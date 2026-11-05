<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/*
 * Ev kodu (homes.evkodu). Config 'homes_code':
 *   mode   off    : ilan ekranında alan yok, otomatik kod yok (varsayilan; eski davranis).
 *          manual : alan var, kullanici yazar; otomatik kod yok.
 *          auto   : alan var; bos birakilirsa kayitta format'a gore uretilir.
 *   format : {id}, {id+N} (orn. {id+1000}), {bolge_harf} (emlak bolgesi basliginin ilk harfi), {bolge},
 *            {tip_prefix} (tip.prefix). Ornekler: ovillam '{bolge_harf}-{id+1000}', villavillam 'VR-{id}'.
 * Otomatik kod sadece evkodu bossa yazilir; doldurulmus kod ezilmez. Gerekli bilgi (orn. bolge) yoksa kod
 * uretilmez, sonraki kayitta tekrar denenir.
 *
 *   target evkodu : kod homes.evkodu'na yazilir (varsayilan; ovillam, vv).
 *          baslik : kod sitenin basligi olur (garantivillam paneli): her kayitta baslik{sonek} = kod ve
 *                   title / description / icerik icindeki {-evkodu-} koda cevrilir; yeni ilanda url{sonek} = kod
 *                   (panel duzenlemede url'e dokunmaz). Ilan ekranindaki "Villa Basligi" homes.villaadi'ye yazilir
 *                   ve oradan okunur. Bu hedefte mod her zaman auto; evkodu'na yazilmaz (panel her kayitta bosaltir).
 *                   garantivillam: ['mode' => 'auto', 'target' => 'baslik', 'format' => '{tip_prefix}-{id+10000}'].
 *   url    : (sadece target evkodu) true ise yeni ilanda url{sonek} = kod ve title / description / icerik icindeki
 *            {-evkodu-} koda cevrilir; baslik'a dokunulmaz (KVK paneli scripts.asp yeni_emlak_ekle).
 *            KVK: ['mode' => 'auto', 'format' => 'KVK-{id+10000}', 'url' => true].
 */
final class HomesCode
{
    public const DEFAULT_FORMAT = '{bolge_harf}-{id+1000}';

    /**
     * @param array<string,mixed> $app
     * @return array{mode:string,format:string,target:string,url:bool}
     */
    public static function config(array $app): array
    {
        $raw = $app['homes_code'] ?? [];
        $raw = is_array($raw) ? $raw : [];
        $mode = strtolower(trim((string) ($raw['mode'] ?? 'off')));
        if (!in_array($mode, ['off', 'manual', 'auto'], true)) {
            $mode = 'off';
        }
        $target = strtolower(trim((string) ($raw['target'] ?? 'evkodu')));
        if ($target !== 'baslik') {
            $target = 'evkodu';
        }
        // Basliga yazilan kod her zaman otomatik (panel gibi); off secildiyse hic calismaz.
        if ($target === 'baslik' && $mode === 'manual') {
            $mode = 'auto';
        }
        $format = trim((string) ($raw['format'] ?? ''));

        // url: yeni ilanda adres = kod (target baslik zaten yapar).
        $url = $target === 'evkodu' && !empty($raw['url']);

        return ['mode' => $mode, 'format' => $format !== '' ? $format : self::DEFAULT_FORMAT, 'target' => $target, 'url' => $url];
    }

    /**
     * Kod basliga mi yaziliyor (target baslik, mod acik)?
     *
     * @param array<string,mixed> $app
     */
    public static function writesTitle(array $app): bool
    {
        $config = self::config($app);

        return $config['mode'] !== 'off' && $config['target'] === 'baslik';
    }

    /**
     * Frontend icin: mod, format ve (id henuz yokken onizleme icin) tahmini sonraki id.
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     */
    public static function definition(PDO $pdo, array $app): array
    {
        $config = self::config($app);
        $available = $config['target'] === 'baslik' ? self::hasHomesColumn($pdo, 'baslik') : self::hasColumn($pdo);
        if ($config['mode'] === 'off' || !$available) {
            return ['mode' => 'off'];
        }

        return [
            'mode' => $config['mode'],
            'format' => $config['format'],
            'target' => $config['target'],
            'url' => $config['url'],
            'sonrakiId' => self::nextIdEstimate($pdo),
        ];
    }

    /**
     * Payload'daki "evkodu" -> homes.evkodu (mod off degilse).
     *
     * @param array<string,mixed> $app
     * @param array<string,mixed> $payload
     * @return array<string,string>
     */
    public static function updates(array $app, array $payload): array
    {
        $config = self::config($app);
        if ($config['mode'] === 'off' || $config['target'] === 'baslik'
            || !array_key_exists('evkodu', $payload) || is_array($payload['evkodu'])
        ) {
            return [];
        }

        return ['evkodu' => trim((string) $payload['evkodu'])];
    }

    /**
     * Target baslik: ilan ekranindaki villa adi (payload villaadi -> baslik) homes.villaadi'ye de yazilir;
     * baslik kayittan sonra applyAuto ile koda cevrilir (kod uretilemezse villa adi kalir).
     *
     * @param array<string,mixed> $app
     * @param array<string,mixed> $updates homes kolon => deger
     * @return array<string,mixed>
     */
    public static function adjustUpdates(PDO $pdo, array $app, array $updates): array
    {
        if (!self::writesTitle($app) || !array_key_exists('baslik', $updates) || !self::hasHomesColumn($pdo, 'villaadi')) {
            return $updates;
        }
        $updates['villaadi'] = $updates['baslik'];

        return $updates;
    }

    /**
     * Ilan detayinda gosterilecek villa adi kolonlari (oncelik sirasiyla).
     *
     * @param array<string,mixed> $app
     * @return array<int,string>
     */
    public static function nameColumns(array $app): array
    {
        return self::writesTitle($app) ? ['villaadi', 'baslik'] : ['baslik'];
    }

    /**
     * Otomatik kod: mod auto, evkodu bos ve kayitta gonderilmemis/bos ise uretip yazar.
     * Update'te sadece payload'da "evkodu" anahtari varsa (alan formdaysa) calisir; eski villalara kendiliginden dokunmaz.
     *
     * @param array<string,mixed> $app
     * @param array<string,mixed> $payload
     */
    public static function applyAuto(PDO $pdo, array $app, int $homeId, array $payload, bool $isCreate): ?string
    {
        $config = self::config($app);
        if ($homeId > 0 && $config['mode'] === 'auto' && $config['target'] === 'baslik') {
            return self::applyTitle($pdo, $app, $config['format'], $homeId, $isCreate);
        }
        $code = self::applyEvkodu($pdo, $config, $homeId, $payload, $isCreate);

        // url secenegi: yeni ilanda adres = kod, {-evkodu-} = kod (otomatik uretilen ya da elle girilen kod).
        if ($homeId > 0 && $isCreate && $config['url'] && $config['mode'] !== 'off' && self::hasColumn($pdo)) {
            $current = $code;
            if ($current === null) {
                $stmt = $pdo->prepare('SELECT evkodu FROM homes WHERE id = :id');
                $stmt->execute([':id' => $homeId]);
                $current = trim((string) $stmt->fetchColumn());
            }
            if ($current !== '') {
                self::applyCodeColumns($pdo, $app, $current, $homeId, false, true);
            }
        }

        return $code;
    }

    /**
     * Otomatik modda bos evkodu'nu format'a gore doldurur. Uretilen kodu, uretilmediyse null doner.
     *
     * @param array{mode:string,format:string,target:string,url:bool} $config
     * @param array<string,mixed> $payload
     */
    private static function applyEvkodu(PDO $pdo, array $config, int $homeId, array $payload, bool $isCreate): ?string
    {
        if ($homeId <= 0 || $config['mode'] !== 'auto' || !self::hasColumn($pdo)) {
            return null;
        }
        if (!$isCreate && !array_key_exists('evkodu', $payload)) {
            return null;
        }
        if (array_key_exists('evkodu', $payload) && !is_array($payload['evkodu']) && trim((string) $payload['evkodu']) !== '') {
            return null;
        }

        $stmt = $pdo->prepare('SELECT evkodu FROM homes WHERE id = :id');
        $stmt->execute([':id' => $homeId]);
        if (trim((string) $stmt->fetchColumn()) !== '') {
            return null;
        }

        $code = self::generate($pdo, $config['format'], $homeId);
        if ($code === null) {
            return null;
        }

        $stmt = $pdo->prepare("UPDATE homes SET evkodu = :code WHERE id = :id AND ISNULL(LTRIM(RTRIM(evkodu)), '') = ''");
        $stmt->execute([':code' => $code, ':id' => $homeId]);

        return $stmt->rowCount() > 0 ? $code : null;
    }

    /**
     * Target baslik (garantivillam paneli scripts.asp yeni_emlak_ekle / emlak_duzenle):
     *   baslik = kod, title / description / icerik icindeki {-evkodu-} = kod, yeni ilanda url = kod.
     * Her site soneki icin (site_column_suffixes), kolon varsa. Kod uretilemezse (orn. tip.prefix bos) dokunmaz.
     *
     * @param array<string,mixed> $app
     */
    private static function applyTitle(PDO $pdo, array $app, string $format, int $homeId, bool $isCreate): ?string
    {
        $code = self::generate($pdo, $format, $homeId);
        if ($code === null) {
            return null;
        }

        return self::applyCodeColumns($pdo, $app, $code, $homeId, true, $isCreate) ? $code : null;
    }

    /**
     * Kodu homes kolonlarina yazar, her site soneki icin (site_column_suffixes), kolon varsa:
     *   $setTitle: baslik{sonek} = kod;  $setUrl: url{sonek} = kod;  her zaman title / description / icerik {-evkodu-} = kod.
     *
     * @param array<string,mixed> $app
     */
    private static function applyCodeColumns(PDO $pdo, array $app, string $code, int $homeId, bool $setTitle, bool $setUrl): bool
    {
        $suffixes = $app['site_column_suffixes'] ?? [];
        $suffixes = is_array($suffixes) && $suffixes !== [] ? array_values(array_unique(array_map('strval', $suffixes))) : [''];
        if (!in_array('', $suffixes, true)) {
            array_unshift($suffixes, '');
        }

        $sets = [];
        foreach ($suffixes as $suffix) {
            if (!preg_match('/^[A-Za-z0-9_]*$/', $suffix)) {
                continue;
            }
            if ($setTitle && self::hasHomesColumn($pdo, 'baslik' . $suffix)) {
                $sets[] = '[baslik' . $suffix . '] = :code';
            }
            if ($setUrl && self::hasHomesColumn($pdo, 'url' . $suffix)) {
                $sets[] = '[url' . $suffix . '] = :code';
            }
            foreach (['title', 'description', 'icerik'] as $column) {
                if (self::hasHomesColumn($pdo, $column . $suffix)) {
                    // ntext kolonlarda da calissin diye nvarchar(max)'a cevrilir.
                    $sets[] = '[' . $column . $suffix . "] = REPLACE(CAST([" . $column . $suffix . "] AS nvarchar(max)), '{-evkodu-}', :code)";
                }
            }
        }
        if ($sets === []) {
            return false;
        }

        $sql = 'UPDATE homes SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $sql = self::numberPlaceholders($sql, ':code');
        $stmt = $pdo->prepare($sql['sql']);
        foreach ($sql['names'] as $name) {
            $stmt->bindValue($name, $code);
        }
        $stmt->bindValue(':id', $homeId, PDO::PARAM_INT);
        $stmt->execute();

        return true;
    }

    /**
     * PDO sqlsrv ayni isimli parametreyi birden fazla kullanmaya izin vermez: :code -> :code0, :code1 ...
     *
     * @return array{sql:string,names:array<int,string>}
     */
    private static function numberPlaceholders(string $sql, string $name): array
    {
        $names = [];
        $sql = preg_replace_callback('/' . preg_quote($name, '/') . '\b/', static function () use ($name, &$names): string {
            $numbered = $name . count($names);
            $names[] = $numbered;

            return $numbered;
        }, $sql);

        return ['sql' => (string) $sql, 'names' => $names];
    }

    /**
     * Kayittaki bilgilerle kodu uretir; formatin istedigi bilgi bossa null.
     */
    public static function generate(PDO $pdo, string $format, int $homeId): ?string
    {
        $vars = ['id' => $homeId, 'bolge' => '', 'tip_prefix' => ''];

        if (self::uses($format, 'bolge')) {
            try {
                $stmt = $pdo->prepare(
                    'SELECT d.baslik FROM homes h INNER JOIN destinations d ON d.id = h.emlak_bolgesi WHERE h.id = :id'
                );
                $stmt->execute([':id' => $homeId]);
                $vars['bolge'] = trim((string) $stmt->fetchColumn());
            } catch (\Throwable $e) {
                $vars['bolge'] = '';
            }
        }

        if (self::uses($format, 'tip_prefix')) {
            try {
                $stmt = $pdo->prepare('SELECT t.prefix FROM homes h INNER JOIN tip t ON t.id = h.emlak_tipi WHERE h.id = :id');
                $stmt->execute([':id' => $homeId]);
                $vars['tip_prefix'] = trim((string) $stmt->fetchColumn());
            } catch (\Throwable $e) {
                $vars['tip_prefix'] = '';
            }
        }

        return self::render($format, $vars);
    }

    /**
     * @param array{id:int,bolge:string,tip_prefix:string} $vars
     */
    public static function render(string $format, array $vars): ?string
    {
        $missing = false;
        $code = preg_replace_callback(
            '/\{(id(?:\s*\+\s*(\d+))?|bolge_harf|bolge|tip_prefix)\}/',
            static function (array $m) use ($vars, &$missing): string {
                $token = preg_replace('/\s+/', '', $m[1]);
                if (strpos($token, 'id') === 0) {
                    return (string) ($vars['id'] + (isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0));
                }
                if ($token === 'bolge_harf') {
                    $value = self::firstChar($vars['bolge']);
                } else {
                    $value = (string) $vars[$token];
                }
                if ($value === '') {
                    $missing = true;
                }

                return $value;
            },
            $format
        );

        return ($missing || $code === null || trim($code) === '') ? null : trim($code);
    }

    private static function uses(string $format, string $prefix): bool
    {
        return strpos($format, '{' . $prefix) !== false;
    }

    private static function firstChar(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, 1, 'UTF-8') : substr($value, 0, 1);
    }

    private static function nextIdEstimate(PDO $pdo): int
    {
        try {
            $row = $pdo->query(
                "SELECT CAST(ISNULL(IDENT_CURRENT('homes'), 0) + ISNULL(IDENT_INCR('homes'), 1) AS bigint) AS identNext,
                        ISNULL(MAX(id), 0) + 1 AS maxNext,
                        COLUMNPROPERTY(OBJECT_ID('homes'), 'id', 'IsIdentity') AS isIdentity
                 FROM homes"
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return 0;
            }

            return (int) ($row['isIdentity'] ?? 0) === 1 ? (int) $row['identNext'] : (int) $row['maxNext'];
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function hasHomesColumn(PDO $pdo, string $column): bool
    {
        static $columns = null;
        if ($columns === null) {
            $columns = [];
            try {
                $rows = $pdo->query(
                    "SELECT name FROM sys.columns WHERE object_id = OBJECT_ID('homes')"
                )->fetchAll(PDO::FETCH_COLUMN);
                foreach ($rows as $name) {
                    $columns[strtolower((string) $name)] = true;
                }
            } catch (\Throwable $e) {
                $columns = [];
            }
        }

        return isset($columns[strtolower($column)]);
    }

    private static function hasColumn(PDO $pdo): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = (int) $pdo->query("SELECT CASE WHEN COL_LENGTH('homes', 'evkodu') IS NULL THEN 0 ELSE 1 END")->fetchColumn() === 1;
            } catch (\Throwable $e) {
                $has = false;
            }
        }

        return $has;
    }
}
