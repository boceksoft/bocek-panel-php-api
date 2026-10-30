<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/*
 * Ilan detayindaki (yeni ilan / duzenleme) site-ozel ek homes alanlari.
 * Her panelde bulunmayan alanlar burada katalog olarak tanimlidir; hangilerinin
 * gorunecegi config 'homes_extra_fields' listesinden secilir (Villa Kiralama > Config).
 * Frontend alanlari "ek_{anahtar}" adiyla gonderir; sadece secili ve homes'ta kolonu olan alanlar yazilir.
 */
final class HomesExtraFields
{
    public const PAYLOAD_PREFIX = 'ek_';

    /**
     * Alan katalogu. Anahtar = homes kolonu (per_site ise site soneki eklenir).
     * type: text | textarea | number | select | bool | multiselect
     * group: genel | takip | detay | fiyat | konum | kurallar (frontend'de hangi sekmede gosterilecegi)
     * relation: homes kolonu yerine iliski tablosu (deger virgullu id listesi; bkz. relationValues/saveRelations)
     *
     * @return array<string,array<string,mixed>>
     */
    public static function catalog(): array
    {
        $varYok = [
            ['value' => '1', 'label' => 'Var'],
            ['value' => '0', 'label' => 'Yok'],
        ];

        return [
            'takvimadi' => ['label' => 'Takvim Adı', 'type' => 'text', 'group' => 'genel'],
            'orj_ad' => ['label' => 'Villanın Orjinal Adı', 'type' => 'text', 'group' => 'genel', 'hint' => 'Aynıysa boş bırakın'],
            'ekstra' => ['label' => 'Ekstra Bilgiler', 'type' => 'textarea', 'group' => 'genel', 'hint' => 'HTML yazılabilir'],
            'rez_takibi' => ['label' => 'Rezervasyon Takibi', 'type' => 'select', 'group' => 'takip', 'options_source' => 'rez_takip_kullanicilari'],
            'rez_takip_yeri' => [
                'label' => 'Rezervasyon Takibi Yapılan Yer',
                'type' => 'select',
                'group' => 'takip',
                'options' => [
                    ['value' => '1', 'label' => 'Site'],
                    ['value' => '2', 'label' => 'WhatsApp'],
                ],
            ],
            'rez_takip_yeri_adi' => ['label' => 'Takip Edilen Yerin Adı', 'type' => 'text', 'group' => 'takip'],
            'oda_sayisi' => ['label' => 'Oda Sayısı', 'type' => 'number', 'group' => 'detay'],
            'm2' => ['label' => 'Metrekare', 'type' => 'number', 'group' => 'detay'],
            'kat_sayisi' => ['label' => 'Kat Sayısı', 'type' => 'number', 'group' => 'detay'],
            'isitma' => ['label' => 'Isıtma', 'type' => 'select', 'group' => 'detay', 'options_source' => 'isitma'],
            'klima' => ['label' => 'Klima', 'type' => 'select', 'group' => 'detay', 'options' => $varYok],
            'otopark' => ['label' => 'Otopark', 'type' => 'select', 'group' => 'detay', 'options' => $varYok],
            'internet' => ['label' => 'İnternet', 'type' => 'select', 'group' => 'detay', 'options' => $varYok],
            // Panel emlak_duzenle: "Sadece Bizde" Hayir (0) / Evet (1).
            'sadece_bizde' => [
                'label' => 'Sadece Bizde',
                'type' => 'select',
                'group' => 'detay',
                'options' => [
                    ['value' => '0', 'label' => 'Hayır'],
                    ['value' => '1', 'label' => 'Evet'],
                ],
            ],
            'kur' => ['label' => 'Kur', 'type' => 'number', 'group' => 'fiyat'],
            'fiyat_notu' => ['label' => 'Fiyat Notu', 'type' => 'text', 'group' => 'fiyat', 'per_site' => true],
            'takvimNotu' => ['label' => 'Takvim Notu', 'type' => 'text', 'group' => 'fiyat', 'per_site' => true],
            'harita' => ['label' => 'Sitede haritayı kullan', 'type' => 'bool', 'group' => 'konum'],
            // Panel: rules tablosundan onay kutulari, secilenler rulesHomes (rulesId, homesId).
            'takvim_kurallari' => ['label' => 'Takvim Kuralları', 'type' => 'multiselect', 'group' => 'kurallar', 'relation' => 'ruleshomes'],
        ];
    }

    /**
     * Config'de secili ve katalogda olan anahtarlar (katalog sirasiyla).
     *
     * @param array<string,mixed> $app
     * @return array<int,string>
     */
    public static function enabledKeys(array $app): array
    {
        $configured = $app['homes_extra_fields'] ?? [];
        if (!is_array($configured) || $configured === []) {
            return [];
        }

        $wanted = [];
        foreach ($configured as $key) {
            if (is_string($key) && $key !== '') {
                $wanted[strtolower($key)] = true;
            }
        }

        $keys = [];
        foreach (array_keys(self::catalog()) as $key) {
            if (isset($wanted[strtolower($key)])) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Frontend'e gidecek alan tanimlari (secenekleri ile). homes'ta kolonu olmayan alan dondurulmez.
     *
     * @param array<string,mixed> $app
     * @return array<int,array<string,mixed>>
     */
    public static function definitions(PDO $pdo, array $app, string $suffix): array
    {
        $keys = self::enabledKeys($app);
        if ($keys === []) {
            return [];
        }

        $columns = self::homesColumns($pdo);
        $catalog = self::catalog();
        $definitions = [];

        foreach ($keys as $key) {
            $field = $catalog[$key];
            if (isset($field['relation'])) {
                $options = self::relationOptions($pdo, (string) $field['relation']);
                if ($options === null) {
                    continue;
                }
                $definitions[] = [
                    'key' => $key,
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'group' => $field['group'],
                    'options' => $options,
                ];
                continue;
            }

            $column = self::column($key, $field, $suffix);
            if (!isset($columns[strtolower($column)])) {
                continue;
            }

            $options = $field['options'] ?? null;
            if (isset($field['options_source'])) {
                $options = self::sourceOptions($pdo, (string) $field['options_source']);
            }

            $definition = [
                'key' => $key,
                'label' => $field['label'],
                'type' => $field['type'],
                'group' => $field['group'],
                'column' => $column,
            ];
            if (isset($field['hint'])) {
                $definition['hint'] = $field['hint'];
            }
            if (is_array($options)) {
                $definition['options'] = $options;
            }

            $definitions[] = $definition;
        }

        return $definitions;
    }

    /**
     * Secili alanlarin kayittaki degerleri: [anahtar => string].
     *
     * @param array<string,mixed> $app
     * @param array<string,mixed> $home homes satiri (site sonekli kolonlar dahil)
     * @return array<string,string>
     */
    public static function values(array $app, array $home, string $suffix): array
    {
        $keys = self::enabledKeys($app);
        if ($keys === []) {
            return [];
        }

        $lowerHome = array_change_key_case($home, CASE_LOWER);
        $catalog = self::catalog();
        $values = [];

        foreach ($keys as $key) {
            $field = $catalog[$key];
            if (isset($field['relation'])) {
                continue;
            }
            $column = strtolower(self::column($key, $field, $suffix));
            if (!array_key_exists($column, $lowerHome)) {
                continue;
            }

            $raw = $lowerHome[$column];
            if ($field['type'] === 'bool') {
                $values[$key] = self::truthy($raw) ? '1' : '0';
                continue;
            }

            $values[$key] = $raw === null ? '' : trim((string) $raw);
        }

        return $values;
    }

    /**
     * Payload'daki "ek_{anahtar}" degerlerinden homes guncellemeleri: [kolon => deger].
     * Kolon varligi ve tip donusumu cagiran controller'in insert/update akisinda kontrol edilir.
     *
     * @param array<string,mixed> $app
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function updates(array $app, array $payload, string $suffix): array
    {
        $keys = self::enabledKeys($app);
        if ($keys === []) {
            return [];
        }

        $catalog = self::catalog();
        $updates = [];

        foreach ($keys as $key) {
            $payloadKey = self::PAYLOAD_PREFIX . $key;
            if (!array_key_exists($payloadKey, $payload)) {
                continue;
            }

            $field = $catalog[$key];
            if (isset($field['relation'])) {
                continue;
            }
            $value = $payload[$payloadKey];
            if (is_array($value)) {
                continue;
            }

            $value = $value === null ? '' : trim((string) $value);
            if ($field['type'] === 'bool') {
                $value = self::truthy($value) ? 1 : 0;
            } elseif ($field['type'] === 'number') {
                // Bos sayi 0 yazilir (panel de bos kurda 0 yaziyordu); virgullu ondalik noktaya cevrilir.
                $value = str_replace(',', '.', $value);
                if ($value === '' || !is_numeric($value)) {
                    $value = '0';
                }
            }

            $updates[self::column($key, $field, $suffix)] = $value;
        }

        return $updates;
    }

    /**
     * Iliski tablosundaki secili degerler: [anahtar => "3,5"]. Tablo yoksa anahtar dondurulmez.
     *
     * @param array<string,mixed> $app
     * @return array<string,string>
     */
    public static function relationValues(PDO $pdo, array $app, int $homeId): array
    {
        $values = [];
        if ($homeId <= 0) {
            return $values;
        }

        $catalog = self::catalog();
        foreach (self::enabledKeys($app) as $key) {
            $relation = $catalog[$key]['relation'] ?? null;
            if ($relation !== 'ruleshomes') {
                continue;
            }

            $columns = self::rulesHomesColumns($pdo);
            if ($columns === null) {
                continue;
            }

            try {
                $stmt = $pdo->prepare(
                    'SELECT [' . $columns['rules'] . '] AS rulesId FROM rulesHomes WHERE [' . $columns['homes'] . '] = :homeId'
                );
                $stmt->execute([':homeId' => $homeId]);
                $ids = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $id = (int) ($row['rulesId'] ?? 0);
                    if ($id > 0) {
                        $ids[$id] = (string) $id;
                    }
                }
                $values[$key] = implode(',', array_values($ids));
            } catch (\Throwable $e) {
                // Okunamazsa alan bos gelir.
            }
        }

        return $values;
    }

    /**
     * Payload'daki "ek_{anahtar}" iliski alanlarini kaydeder. Panel ile ayni: isaretli olup kayitli olmayan eklenir,
     * isaretli olmayan silinir. Anahtar payload'da yoksa iliskiye dokunulmaz.
     *
     * @param array<string,mixed> $app
     * @param array<string,mixed> $payload
     * @return array{updated:int,skipped:array<int,array<string,string>>}
     */
    public static function saveRelations(PDO $pdo, array $app, int $homeId, array $payload): array
    {
        $result = ['updated' => 0, 'skipped' => []];
        if ($homeId <= 0) {
            return $result;
        }

        $catalog = self::catalog();
        foreach (self::enabledKeys($app) as $key) {
            $relation = $catalog[$key]['relation'] ?? null;
            $payloadKey = self::PAYLOAD_PREFIX . $key;
            if ($relation !== 'ruleshomes' || !array_key_exists($payloadKey, $payload)) {
                continue;
            }

            $columns = self::rulesHomesColumns($pdo);
            if ($columns === null) {
                $result['skipped'][] = ['section' => $key, 'reason' => 'rulesHomes tablosu ya da kolonlari bulunamadi.'];
                continue;
            }

            $wanted = self::idList($payload[$payloadKey]);
            $current = array_map('intval', array_filter(explode(',', self::relationValues($pdo, $app, $homeId)[$key] ?? '')));

            foreach (array_diff($wanted, $current) as $ruleId) {
                $stmt = $pdo->prepare(
                    'INSERT INTO rulesHomes ([' . $columns['rules'] . '], [' . $columns['homes'] . ']) VALUES (:ruleId, :homeId)'
                );
                $stmt->execute([':ruleId' => $ruleId, ':homeId' => $homeId]);
                $result['updated']++;
            }

            foreach (array_diff($current, $wanted) as $ruleId) {
                $stmt = $pdo->prepare(
                    'DELETE FROM rulesHomes WHERE [' . $columns['homes'] . '] = :homeId AND [' . $columns['rules'] . '] = :ruleId'
                );
                $stmt->execute([':ruleId' => $ruleId, ':homeId' => $homeId]);
                $result['updated']++;
            }
        }

        return $result;
    }

    /**
     * Payload'da kaydedilecek iliski alani var mi (update'te "guncellenecek alan yok" kontrolu icin).
     *
     * @param array<string,mixed> $app
     * @param array<string,mixed> $payload
     */
    public static function hasRelationPayload(array $app, array $payload): bool
    {
        $catalog = self::catalog();
        foreach (self::enabledKeys($app) as $key) {
            if (isset($catalog[$key]['relation']) && array_key_exists(self::PAYLOAD_PREFIX . $key, $payload)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Site kolon soneki (config site_column_suffixes; yoksa ya da gecersizse '').
     *
     * @param array<string,mixed> $app
     */
    public static function siteSuffix(array $app, int $siteId): string
    {
        $suffixes = $app['site_column_suffixes'] ?? [];
        $suffix = is_array($suffixes) && array_key_exists($siteId, $suffixes) ? (string) $suffixes[$siteId] : '';

        return ($suffix === '' || preg_match('/^_[A-Za-z0-9]+$/', $suffix) === 1) ? $suffix : '';
    }

    /**
     * @param array<string,mixed> $field
     */
    private static function column(string $key, array $field, string $suffix): string
    {
        return !empty($field['per_site']) ? $key . $suffix : $key;
    }

    /**
     * @param mixed $value
     */
    private static function truthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim((string) $value));

        return in_array($value, ['1', 'true', 'on', 'evet', 'yes'], true);
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private static function sourceOptions(PDO $pdo, string $source): array
    {
        try {
            if ($source === 'rez_takip_kullanicilari') {
                // Panel: Admin (0) + kullanici (aktif=1, yetki=2) ad sirasiyla.
                $options = [['value' => '0', 'label' => 'Admin']];
                $stmt = $pdo->query('SELECT id, ad, soyad FROM kullanici WHERE aktif = 1 AND yetki = 2 ORDER BY ad');
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $options[] = [
                        'value' => (string) (int) ($row['id'] ?? 0),
                        'label' => trim((string) ($row['ad'] ?? '') . ' ' . (string) ($row['soyad'] ?? '')),
                    ];
                }

                return $options;
            }

            if ($source === 'isitma') {
                // Panel: "Yok" + isitma tablosu; deger olarak baslik yazilir.
                $options = [['value' => 'Yok', 'label' => 'Yok']];
                $stmt = $pdo->query('SELECT baslik FROM isitma ORDER BY baslik ASC');
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $title = trim((string) ($row['baslik'] ?? ''));
                    if ($title !== '' && $title !== 'Yok') {
                        $options[] = ['value' => $title, 'label' => $title];
                    }
                }

                return $options;
            }
        } catch (\Throwable $e) {
            // Tablo bu panelde yoksa secenek listesi bos doner; alan yine gosterilir.
        }

        return [];
    }

    /**
     * Iliski alaninin secenekleri; tablo yoksa null (alan gosterilmez).
     *
     * @return array<int,array{value:string,label:string}>|null
     */
    private static function relationOptions(PDO $pdo, string $relation): ?array
    {
        if ($relation !== 'ruleshomes' || self::rulesHomesColumns($pdo) === null) {
            return null;
        }

        try {
            $hasActive = self::columnExists($pdo, 'rules', 'isactive');
            $stmt = $pdo->query(
                'SELECT id, baslik' . ($hasActive ? ', isactive' : '') . ' FROM rules ORDER BY baslik ASC'
            );
            $options = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $label = trim((string) ($row['baslik'] ?? ''));
                if ($hasActive && !self::truthy($row['isactive'] ?? '')) {
                    $label .= ' (pasif)';
                }
                $options[] = ['value' => (string) (int) ($row['id'] ?? 0), 'label' => $label];
            }

            return $options;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * rulesHomes kolon adlari (panellerde rulesId/homesId ya da kucuk harf). Tablo/kolon yoksa null.
     *
     * @return array{rules:string,homes:string}|null
     */
    private static function rulesHomesColumns(PDO $pdo): ?array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache === false ? null : $cache;
        }

        try {
            $stmt = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'rulesHomes'");
            $names = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $name = (string) ($row['COLUMN_NAME'] ?? '');
                $names[strtolower($name)] = $name;
            }
            $hasRules = self::columnExists($pdo, 'rules', 'id');
        } catch (\Throwable $e) {
            $names = [];
            $hasRules = false;
        }

        if (!$hasRules || !isset($names['rulesid'], $names['homesid'])) {
            $cache = false;

            return null;
        }

        $cache = ['rules' => $names['rulesid'], 'homes' => $names['homesid']];

        return $cache;
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('SELECT CASE WHEN COL_LENGTH(:table, :column) IS NULL THEN 0 ELSE 1 END');
        $stmt->execute([':table' => $table, ':column' => $column]);

        return (int) $stmt->fetchColumn() === 1;
    }

    /**
     * "3,5" / [3,5] / "3#5" -> [3,5] (pozitif, tekil).
     *
     * @param mixed $value
     * @return array<int,int>
     */
    private static function idList($value): array
    {
        if (!is_array($value)) {
            $value = preg_split('/[,#]/', trim((string) $value)) ?: [];
        }

        $ids = [];
        foreach ($value as $item) {
            if (is_numeric($item) && (int) $item > 0) {
                $ids[(int) $item] = (int) $item;
            }
        }

        return array_values($ids);
    }

    /**
     * @return array<string,true>
     */
    private static function homesColumns(PDO $pdo): array
    {
        $columns = [];
        $stmt = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'homes'");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = strtolower((string) ($row['COLUMN_NAME'] ?? ''));
            if ($name !== '') {
                $columns[$name] = true;
            }
        }

        return $columns;
    }
}
