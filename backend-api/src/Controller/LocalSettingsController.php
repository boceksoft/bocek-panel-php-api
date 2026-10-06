<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;

/*
 * app.local.php yonetimi.
 * Sadece config/app.php icindeki editable_local_settings listesinde olan alanlari
 * okur/yazar. Gizli veya sistemsel alanlar whitelist disinda tutulmalidir.
 */
final class LocalSettingsController extends Controller
{
    /**
     * Panelde gosterilecek duzenlenebilir local ayarlari dondurur.
     *
     * @Get
     * @Get("list")
     */
    public function index(): void
    {
        $schema = $this->editableSettings();
        $local = $this->readLocalConfig();
        $onlyKeys = $this->requestedKeys();

        $values = [];
        foreach ($schema as $key => $type) {
            if ($onlyKeys !== [] && !in_array($key, $onlyKeys, true)) {
                continue;
            }

            $values[$key] = [
                'type' => $type,
                'value' => array_key_exists($key, $local) ? $local[$key] : ($this->app[$key] ?? null),
                'local' => array_key_exists($key, $local),
            ];
        }

        $this->enrichSiteLanguages($values, $local);

        $this->response->success([
            'values' => $values,
        ]);
    }

    /**
     * Secilen ayarlari app.local.php icine yazar.
     *
     * Body:
     * {
     *   "changes": {
     *     "links_search_page_url": "https://www.site.com/arama"
     *   }
     * }
     *
     * @Post
     * @Post("update")
     */
    public function update(): void
    {
        $schema = $this->editableSettings();
        $changes = $this->requestChanges();

        if (!is_array($changes) || $changes === []) {
            throw new HttpException('Guncellenecek ayar gonderilmedi.', 'VALIDATION', 422);
        }

        $local = $this->readLocalConfig();
        $updated = [];

        foreach ($changes as $key => $value) {
            if (!is_string($key) || !array_key_exists($key, $schema)) {
                throw new HttpException('Bu ayar panelden degistirilemez: ' . (string) $key, 'VALIDATION', 422);
            }

            $local[$key] = $this->normalizeValue($key, $schema[$key], $value);
            $updated[$key] = $local[$key];
        }

        $this->writeLocalConfig($local, $updated);

        // site_languages guncellendiyse veritabanindaki genel{suffix}.dil kolonuna kaydet
        if (isset($updated['site_languages']) && is_array($updated['site_languages'])) {
            $suffixes = $local['site_column_suffixes'] ?? ($this->app['site_column_suffixes'] ?? [1 => '', 2 => '_s2']);
            $this->syncSiteLanguagesToDb($updated['site_languages'], is_array($suffixes) ? $suffixes : []);
        }

        $this->response->success([
            'updated' => $updated,
        ]);
    }

    private function enrichSiteLanguages(array &$values, array $local): void
    {
        if (!isset($values['site_languages'])) {
            return;
        }

        $suffixes = $values['site_column_suffixes']['value'] ?? ($this->app['site_column_suffixes'] ?? [1 => '', 2 => '_s2']);
        if (!is_array($suffixes) || $suffixes === []) {
            $suffixes = [1 => ''];
        }

        $existingLocal = is_array($values['site_languages']['value']) ? $values['site_languages']['value'] : [];
        $siteLanguages = [];

        foreach ($suffixes as $siteId => $suffix) {
            $siteId = (int) $siteId;
            $table = 'genel' . (string) $suffix;
            $dbDil = '';

            try {
                $row = $this->db->pdo()->query("SELECT TOP 1 dil FROM {$table}")->fetch(\PDO::FETCH_ASSOC);
                if (is_array($row) && isset($row['dil']) && trim((string) $row['dil']) !== '') {
                    $dbDil = trim((string) $row['dil']);
                }
            } catch (\Throwable $e) {
                // Tablo veya dil kolonu erisilemezse devam et
            }

            if ($dbDil !== '') {
                $siteLanguages[$siteId] = $dbDil;
            } elseif (isset($existingLocal[$siteId]) && trim((string) $existingLocal[$siteId]) !== '') {
                $siteLanguages[$siteId] = trim((string) $existingLocal[$siteId]);
            } else {
                $siteLanguages[$siteId] = $siteId === 1 ? 'tr' : 'en';
            }
        }

        $values['site_languages']['value'] = $siteLanguages;
    }

    private function syncSiteLanguagesToDb(array $languages, array $suffixes): void
    {
        foreach ($languages as $siteId => $dil) {
            $siteId = (int) $siteId;
            $suffix = $suffixes[$siteId] ?? ($siteId === 1 ? '' : '_s' . $siteId);
            $table = 'genel' . (string) $suffix;
            $cleanDil = trim((string) $dil);

            try {
                $stmt = $this->db->pdo()->prepare("UPDATE {$table} SET dil = :dil");
                $stmt->execute([':dil' => $cleanDil]);
            } catch (\Throwable $e) {
                // Hata durumunda devam et
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function requestChanges(): array
    {
        $changes = $this->request->input('changes', null);
        if (is_array($changes)) {
            return $changes;
        }

        $body = $this->request->json();
        $keyValueChanges = $this->keyValueChanges($body);
        if ($keyValueChanges !== []) {
            return $keyValueChanges;
        }
        if (is_array($body) && $body !== []) {
            return $body;
        }

        $keyValueChanges = $this->keyValueChanges($_GET);
        if ($keyValueChanges !== []) {
            return $keyValueChanges;
        }

        return $_GET;
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function keyValueChanges(array $source): array
    {
        $key = $source['key'] ?? '';
        if (!is_string($key) || trim($key) === '' || !array_key_exists('value', $source)) {
            return [];
        }

        return [trim($key) => $source['value']];
    }

    /**
     * @return string[]
     */
    private function requestedKeys(): array
    {
        $key = $this->request->query('key', '');
        if (is_string($key) && trim($key) !== '') {
            return [trim($key)];
        }

        $keys = $this->request->query('keys', []);
        if (is_string($keys)) {
            $keys = explode(',', $keys);
        }
        if (!is_array($keys)) {
            return [];
        }

        $out = [];
        foreach ($keys as $value) {
            if (is_string($value) && trim($value) !== '') {
                $out[] = trim($value);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array<string,string>
     */
    private function editableSettings(): array
    {
        $settings = $this->app['editable_local_settings'] ?? [];
        if (!is_array($settings) || $settings === []) {
            throw new HttpException('editable_local_settings tanimli degil.', 'CONFIG_ERROR', 500);
        }

        $clean = [];
        foreach ($settings as $key => $type) {
            if (is_string($key) && is_string($type)) {
                $clean[$key] = $type;
            }
        }

        return $clean;
    }

    /**
     * @return array<string,mixed>
     */
    private function readLocalConfig(): array
    {
        $path = $this->localConfigPath();
        if (!is_file($path)) {
            return [];
        }

        $data = require $path;
        if (!is_array($data)) {
            throw new HttpException('app.local.php array dondurmuyor.', 'CONFIG_ERROR', 500);
        }

        return $data;
    }

    private function writeLocalConfig(array $config, array $changes): void
    {
        $path = $this->localConfigPath();
        $dir = dirname($path);

        if (!is_dir($dir)) {
            throw new HttpException('Config klasoru bulunamadi: ' . $dir, 'CONFIG_ERROR', 500);
        }

        if ((is_file($path) && !is_writable($path)) || (!is_file($path) && !is_writable($dir))) {
            throw new HttpException('app.local.php yazilabilir degil: ' . $path, 'CONFIG_ERROR', 500);
        }

        $php = is_file($path)
            ? $this->patchLocalConfigPhp((string) file_get_contents($path), $changes)
            : $this->localConfigPhp($config);

        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $php, LOCK_EX) === false) {
            throw new HttpException('Gecici config dosyasi yazilamadi: ' . $tmp, 'CONFIG_ERROR', 500);
        }

        if (!@copy($tmp, $path)) {
            @unlink($tmp);
            throw new HttpException('app.local.php guncellenemedi: ' . $path, 'CONFIG_ERROR', 500);
        }

        @unlink($tmp);
    }

    /**
     * @param array<string,mixed> $changes
     */
    private function patchLocalConfigPhp(string $php, array $changes): string
    {
        $arrayStart = $this->findReturnArrayStart($php);
        $arrayEnd = $this->findMatchingBracket($php, $arrayStart);

        foreach ($changes as $key => $value) {
            $entry = $this->findTopLevelArrayEntry($php, $arrayStart, $arrayEnd, (string) $key);
            $exported = $this->exportPhpValue($value, 1);

            if ($entry !== null) {
                $php = substr($php, 0, $entry['value_start']) . $exported . substr($php, $entry['value_end']);
                $delta = strlen($exported) - ($entry['value_end'] - $entry['value_start']);
                $arrayEnd += $delta;
                continue;
            }

            $insert = "    " . var_export((string) $key, true) . ' => ' . $exported . ",\n";
            $prefix = ($arrayEnd > 0 && $php[$arrayEnd - 1] !== "\n") ? "\n" : '';
            $php = substr($php, 0, $arrayEnd) . $prefix . $insert . substr($php, $arrayEnd);
            $arrayEnd += strlen($prefix . $insert);
        }

        return $php;
    }

    private function findReturnArrayStart(string $php): int
    {
        $returnPos = strpos($php, 'return');
        if ($returnPos === false) {
            throw new HttpException('app.local.php return array icermiyor.', 'CONFIG_ERROR', 500);
        }

        $arrayStart = strpos($php, '[', $returnPos);
        if ($arrayStart === false) {
            throw new HttpException('app.local.php return array icermiyor.', 'CONFIG_ERROR', 500);
        }

        return $arrayStart;
    }

    private function findMatchingBracket(string $php, int $arrayStart): int
    {
        $depth = 0;
        $length = strlen($php);
        for ($i = $arrayStart; $i < $length; $i++) {
            $i = $this->skipPhpNoise($php, $i);
            if ($i >= $length) {
                break;
            }

            if ($php[$i] === '[') {
                $depth++;
            } elseif ($php[$i] === ']') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        throw new HttpException('app.local.php array kapanisi bulunamadi.', 'CONFIG_ERROR', 500);
    }

    /**
     * @return array{value_start:int,value_end:int}|null
     */
    private function findTopLevelArrayEntry(string $php, int $arrayStart, int $arrayEnd, string $wantedKey): ?array
    {
        $depth = 0;
        for ($i = $arrayStart + 1; $i < $arrayEnd; $i++) {
            $i = $this->skipPhpComment($php, $i);
            if ($i >= $arrayEnd) {
                break;
            }

            $char = $php[$i];
            if ($char === '[' || $char === '(' || $char === '{') {
                $depth++;
                continue;
            }
            if ($char === ']' || $char === ')' || $char === '}') {
                $depth--;
                continue;
            }
            if ($depth !== 0 || ($char !== "'" && $char !== '"')) {
                continue;
            }

            $keyEnd = $this->skipPhpString($php, $i);
            $key = stripcslashes(substr($php, $i + 1, $keyEnd - $i - 2));
            $arrowStart = $this->skipWhitespace($php, $keyEnd);
            if (substr($php, $arrowStart, 2) !== '=>') {
                $i = $keyEnd - 1;
                continue;
            }

            if ($key !== $wantedKey) {
                $i = $keyEnd - 1;
                continue;
            }

            $valueStart = $this->skipWhitespace($php, $arrowStart + 2);
            $valueEnd = $this->findTopLevelValueEnd($php, $valueStart, $arrayEnd);

            return [
                'value_start' => $valueStart,
                'value_end' => $valueEnd,
            ];
        }

        return null;
    }

    private function findTopLevelValueEnd(string $php, int $valueStart, int $arrayEnd): int
    {
        $depth = 0;
        for ($i = $valueStart; $i < $arrayEnd; $i++) {
            $i = $this->skipPhpNoise($php, $i);
            if ($i >= $arrayEnd) {
                break;
            }

            $char = $php[$i];
            if ($char === '[' || $char === '(' || $char === '{') {
                $depth++;
                continue;
            }
            if ($char === ']' || $char === ')' || $char === '}') {
                if ($depth === 0) {
                    return $i;
                }
                $depth--;
                continue;
            }
            if ($char === ',' && $depth === 0) {
                return $i;
            }
        }

        return $arrayEnd;
    }

    private function skipPhpNoise(string $php, int $offset): int
    {
        if (!isset($php[$offset])) {
            return $offset;
        }

        $char = $php[$offset];
        if ($char === "'" || $char === '"') {
            return $this->skipPhpString($php, $offset) - 1;
        }
        if (substr($php, $offset, 2) === '//') {
            $end = strpos($php, "\n", $offset + 2);
            return $end === false ? strlen($php) : $end;
        }
        if ($char === '#') {
            $end = strpos($php, "\n", $offset + 1);
            return $end === false ? strlen($php) : $end;
        }
        if (substr($php, $offset, 2) === '/*') {
            $end = strpos($php, '*/', $offset + 2);
            return $end === false ? strlen($php) : $end + 1;
        }

        return $offset;
    }

    private function skipPhpComment(string $php, int $offset): int
    {
        if (substr($php, $offset, 2) === '//') {
            $end = strpos($php, "\n", $offset + 2);
            return $end === false ? strlen($php) : $end;
        }
        if (($php[$offset] ?? '') === '#') {
            $end = strpos($php, "\n", $offset + 1);
            return $end === false ? strlen($php) : $end;
        }
        if (substr($php, $offset, 2) === '/*') {
            $end = strpos($php, '*/', $offset + 2);
            return $end === false ? strlen($php) : $end + 1;
        }

        return $offset;
    }

    private function skipPhpString(string $php, int $offset): int
    {
        $quote = $php[$offset];
        $length = strlen($php);
        for ($i = $offset + 1; $i < $length; $i++) {
            if ($php[$i] === '\\') {
                $i++;
                continue;
            }
            if ($php[$i] === $quote) {
                return $i + 1;
            }
        }

        throw new HttpException('app.local.php string kapanisi bulunamadi.', 'CONFIG_ERROR', 500);
    }

    private function skipWhitespace(string $php, int $offset): int
    {
        $length = strlen($php);
        while ($offset < $length && ctype_space($php[$offset])) {
            $offset++;
        }

        return $offset;
    }

    private function localConfigPhp(array $config): string
    {
        return "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "/*\n"
            . " * Sunucuya ozel ayar dosyasi. Bu dosya GIT'e girmez.\n"
            . " * Otomatik guncelleme bu dosyaya dokunmaz; panel sadece\n"
            . " * editable_local_settings icindeki site-ozel ayarlari gunceller.\n"
            . " * deploy_secret, github_token ve benzeri sirlar panelden degistirilmez.\n"
            . " */\n\n"
            . "return " . $this->exportPhpValue($config, 0) . ";\n";
    }

    /**
     * @param mixed $value
     */
    private function exportPhpValue($value, int $level): string
    {
        if (is_array($value)) {
            return $this->exportPhpArray($value, $level);
        }

        if (is_string($value)) {
            return var_export($value, true);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return var_export($value, true);
    }

    private function exportPhpArray(array $array, int $level): string
    {
        if ($array === []) {
            return '[]';
        }

        $indent = str_repeat('    ', $level);
        $childIndent = str_repeat('    ', $level + 1);
        $isList = array_keys($array) === range(0, count($array) - 1);
        $lines = ["["];

        foreach ($array as $key => $value) {
            $line = $childIndent;
            if (!$isList) {
                $line .= $this->exportPhpValue($key, 0) . ' => ';
            }
            $line .= $this->exportPhpValue($value, $level + 1) . ',';
            $lines[] = $line;
        }

        $lines[] = $indent . ']';

        return implode("\n", $lines);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function normalizeValue(string $key, string $type, $value)
    {
        switch ($type) {
            case 'string':
                return trim((string) $value);

            case 'bool':
                if (is_bool($value)) {
                    return $value;
                }
                if (is_int($value)) {
                    return $value !== 0;
                }
                $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($bool === null) {
                    throw new HttpException('Bool deger gecersiz: ' . $key, 'VALIDATION', 422);
                }

                return $bool;

            case 'int':
                if (!is_numeric($value)) {
                    throw new HttpException('Int deger gecersiz: ' . $key, 'VALIDATION', 422);
                }

                return (int) $value;

            case 'array':
                if (is_string($value)) {
                    $decoded = json_decode($value, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
                if (!is_array($value)) {
                    throw new HttpException('Array deger gecersiz: ' . $key, 'VALIDATION', 422);
                }

                return $value;

            case 'int_or_array':
                if (is_string($value)) {
                    $decoded = json_decode($value, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
                if (is_array($value)) {
                    return $value;
                }
                if (!is_numeric($value)) {
                    throw new HttpException('Int veya array deger gecersiz: ' . $key, 'VALIDATION', 422);
                }

                return (int) $value;
        }

        throw new HttpException('Bilinmeyen ayar tipi: ' . $type, 'CONFIG_ERROR', 500);
    }

    private function localConfigPath(): string
    {
        return (string) ($this->app['_local_config_path'] ?? dirname(__DIR__, 2) . '/config/app.local.php');
    }
}
