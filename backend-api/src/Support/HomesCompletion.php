<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/*
 * Ilan tamamlanma yuzdesi (homes.tamamlanma_yuzdesi): alti bolumun her biri esit agirlikta.
 * Ayni kurallar frontend'de src/components/listings/listingCompletion.ts icinde; biri degisirse digeri de degismeli.
 *   Genel: baslik, kisa aciklama, gorunen icerik, emlak tipi, kapasite >= 1
 *   Ozellikler: en az 3 ozellik, en az 1 ev kurali
 *   Fiyat: en az 1 sezon (site 1)
 *   Konum: bolge, enlem, boylam
 *   Medya: en az 3 fotograf
 *   SEO: meta baslik >= 10, meta aciklama >= 50, url >= 3 karakter
 *
 * Ayni geciste SEO puani da (homes.seo_yuzdesi) hesaplanir; bkz. HomesSeoScore.
 */
final class HomesCompletion
{
    public const COLUMN = 'tamamlanma_yuzdesi';

    private const SECTION_COUNT = 6;

    private const OPTIONAL_COLUMNS = [
        'baslik', 'kisa_icerik', 'icerik', 'kisi', 'ozellikler', 'kurallar',
        'emlak_bolgesi', 'enlem', 'boylam', 'title', 'description', 'url',
        'keywords', 'villaadi',
    ];

    public static function columnExists(PDO $pdo): bool
    {
        return self::homesColumnExists($pdo, self::COLUMN);
    }

    /**
     * Tek villayi yeniler. Asil kayit tamamlandiktan sonra cagrilir; hata kaydi bozmaz (setup yeniden hesaplar).
     *
     * @param array<string,mixed> $app uygulama ayarlari (villa adi kolonu icin, bkz. HomesCode::nameColumns)
     */
    public static function refresh(PDO $pdo, int $homeId, array $app = []): void
    {
        if ($homeId <= 0) {
            return;
        }

        try {
            self::sync($pdo, $homeId, $app);
        } catch (\Throwable $e) {
            error_log('HomesCompletion: ' . $e->getMessage());
        }
    }

    /**
     * Tum villalari yeniler (setup). Sadece degeri degisen satirlara ve sadece bu iki kolona yazar.
     *
     * @param array<string,mixed> $app
     * @return int degisen villa sayisi
     */
    public static function refreshAll(PDO $pdo, array $app = []): int
    {
        // Setup'in onceki adimlari sureyi tuketmis olabilir; toplu hesap kendi suresiyle baslar.
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        return self::sync($pdo, null, $app);
    }

    /**
     * @param array<string,mixed> $app
     */
    private static function sync(PDO $pdo, ?int $homeId, array $app): int
    {
        $hasCompletion = self::columnExists($pdo);
        $hasSeo = HomesSeoScore::columnExists($pdo);
        if (!$hasCompletion && !$hasSeo) {
            return 0;
        }

        $features = $hasCompletion ? self::countedFeatureTitles($pdo) : [];
        $rules = $hasCompletion ? self::ruleIds($pdo) : [];
        $seasonHomes = $hasCompletion ? self::homesWithSeasons($pdo, $homeId) : [];
        $imageCounts = $hasCompletion ? self::imageCounts($pdo, $homeId) : [];
        $nameColumns = HomesCode::nameColumns($app);

        $select = [];
        foreach (self::existingColumns($pdo) as $column) {
            if ($column === 'icerik') {
                // Icerik uzun olabilir; bas kismi yeterli, bellek sismesin. SEO icin tam uzunluk ayrica
                // (frontend'deki JS string uzunlugu = UTF-16 birim sayisi = nvarchar byte / 2).
                $select[] = 'LEFT(CONVERT(nvarchar(max), h.[icerik]), 20000) AS [icerik]';
                $select[] = 'ISNULL(DATALENGTH(CONVERT(nvarchar(max), h.[icerik])), 0) / 2 AS [icerik_uzunluk]';
            } else {
                $select[] = "h.[{$column}] AS [{$column}]";
            }
        }
        $select[] = $hasCompletion ? 'h.[' . self::COLUMN . '] AS stored_percent' : 'CAST(NULL AS int) AS stored_percent';
        $select[] = $hasSeo ? 'h.[' . HomesSeoScore::COLUMN . '] AS stored_seo' : 'CAST(NULL AS int) AS stored_seo';
        $stmt = $pdo->prepare(
            'SELECT ' . implode(', ', $select) . ", h.id,
                    (SELECT TOP 1 baslik FROM tip WHERE tip.id = h.emlak_tipi) AS tip_baslik
             FROM dbo.homes h" . ($homeId !== null ? ' WHERE h.id = :id' : '')
        );
        if ($homeId !== null) {
            $stmt->bindValue(':id', $homeId, PDO::PARAM_INT);
        }
        $stmt->execute();

        // Tek baglantida okuma bitmeden yazilmaz; degisenler toplanip sonra guncellenir.
        $completionChanges = [];
        $seoChanges = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $id = (int) $row['id'];
            if ($hasCompletion) {
                $percent = self::percent($row, $features, $rules, isset($seasonHomes[$id]), $imageCounts[$id] ?? 0);
                if ($row['stored_percent'] === null || (int) $row['stored_percent'] !== $percent) {
                    $completionChanges[$id] = $percent;
                }
            }
            if ($hasSeo) {
                $seo = HomesSeoScore::score($row, $nameColumns);
                if ($row['stored_seo'] === null || (int) $row['stored_seo'] !== $seo) {
                    $seoChanges[$id] = $seo;
                }
            }
        }
        $stmt->closeCursor();

        self::writeGrouped($pdo, self::COLUMN, $completionChanges);
        self::writeGrouped($pdo, HomesSeoScore::COLUMN, $seoChanges);

        return count($completionChanges + $seoChanges);
    }

    /**
     * Ayni degerdeki villalar parcalar halinde birlikte guncellenir.
     *
     * @param array<int,int> $changes villa id => yeni deger
     */
    private static function writeGrouped(PDO $pdo, string $column, array $changes): void
    {
        $idsByValue = [];
        foreach ($changes as $id => $value) {
            $idsByValue[$value][] = (int) $id;
        }
        foreach ($idsByValue as $value => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                $pdo->exec(
                    'UPDATE dbo.homes SET [' . $column . '] = ' . (int) $value
                    . ' WHERE id IN (' . implode(',', $chunk) . ')'
                );
            }
        }
    }

    public static function homesColumnExists(PDO $pdo, string $column): bool
    {
        return $pdo->query("SELECT COL_LENGTH('dbo.homes', '" . str_replace("'", '', $column) . "')")->fetchColumn() !== null;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $features
     * @param array<int,bool> $rules
     */
    private static function percent(array $row, array $features, array $rules, bool $hasSeason, int $imageCount): int
    {
        $done = 0;

        if (self::text($row, 'baslik') !== ''
            && self::text($row, 'kisa_icerik') !== ''
            && self::hasVisibleText(self::text($row, 'icerik'))
            && self::text($row, 'tip_baslik') !== ''
            && (int) ($row['kisi'] ?? 0) >= 1
        ) {
            $done++;
        }

        $selectedFeatures = [];
        foreach (self::idList(self::text($row, 'ozellikler'), '#') as $id) {
            if (isset($features[$id])) {
                $selectedFeatures[$features[$id]] = true;
            }
        }
        $selectedRules = 0;
        foreach (self::idList(self::text($row, 'kurallar'), ',') as $id) {
            if (isset($rules[$id])) {
                $selectedRules++;
            }
        }
        if (count($selectedFeatures) >= 3 && $selectedRules >= 1) {
            $done++;
        }

        if ($hasSeason) {
            $done++;
        }

        if ((int) ($row['emlak_bolgesi'] ?? 0) > 0
            && self::hasCoordinate(self::text($row, 'enlem'))
            && self::hasCoordinate(self::text($row, 'boylam'))
        ) {
            $done++;
        }

        if ($imageCount >= 3) {
            $done++;
        }

        if (mb_strlen(self::text($row, 'title'), 'UTF-8') >= 10
            && mb_strlen(self::text($row, 'description'), 'UTF-8') >= 50
            && mb_strlen(self::text($row, 'url'), 'UTF-8') >= 3
        ) {
            $done++;
        }

        return (int) round($done * 100 / self::SECTION_COUNT);
    }

    /**
     * Detay ekranindaki gibi sayilan ozellikler: alt ozelligi olan ana ozellik sayilmaz, ayni baslik bir kez sayilir.
     *
     * @return array<int,string> ozellik id => baslik
     */
    private static function countedFeatureTitles(PDO $pdo): array
    {
        $rows = [];
        $parents = [];
        foreach ($pdo->query('SELECT id, cat, baslik FROM ozellikler')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            if ($id > 0) {
                $rows[$id] = ['cat' => (int) $row['cat'], 'title' => trim((string) $row['baslik'])];
            }
        }
        foreach ($rows as $row) {
            if ($row['cat'] > 0 && isset($rows[$row['cat']])) {
                $parents[$row['cat']] = true;
            }
        }

        $counted = [];
        foreach ($rows as $id => $row) {
            $isTop = !($row['cat'] > 0 && isset($rows[$row['cat']]));
            $parent = $isTop ? null : $rows[$row['cat']];
            $parentIsTop = $parent !== null && !($parent['cat'] > 0 && isset($rows[$parent['cat']]));
            if ($row['title'] !== '' && (($isTop && !isset($parents[$id])) || $parentIsTop)) {
                $counted[$id] = $row['title'];
            }
        }

        return $counted;
    }

    /**
     * @return array<int,bool>
     */
    private static function ruleIds(PDO $pdo): array
    {
        $ids = [];
        foreach ($pdo->query('SELECT id, baslik FROM kurallar')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (trim((string) $row['baslik']) !== '') {
                $ids[(int) $row['id']] = true;
            }
        }

        return $ids;
    }

    /**
     * @return array<int,bool>
     */
    private static function homesWithSeasons(PDO $pdo, ?int $homeId): array
    {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT islem_id FROM sezonlar WHERE islem = 'emlak' AND site = 1"
            . ($homeId !== null ? ' AND islem_id = :id' : '')
        );
        $stmt->execute($homeId !== null ? [':id' => $homeId] : []);

        $homes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $homes[(int) $id] = true;
        }

        return $homes;
    }

    /**
     * @return array<int,int>
     */
    private static function imageCounts(PDO $pdo, ?int $homeId): array
    {
        $stmt = $pdo->prepare(
            "SELECT islm_id, COUNT(*) AS total
             FROM upload
             WHERE islm = 'emlak' AND LTRIM(RTRIM(ISNULL(filename, ''))) <> ''"
            . ($homeId !== null ? ' AND islm_id = :id' : '')
            . ' GROUP BY islm_id'
        );
        $stmt->execute($homeId !== null ? [':id' => $homeId] : []);

        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['islm_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Sitede olmayan kolon SELECT'i bozmasin; olmayan alan bos sayilir.
     *
     * @return array<int,string>
     */
    private static function existingColumns(PDO $pdo): array
    {
        $existing = [];
        $rows = $pdo->query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'homes'"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $name) {
            $existing[strtolower((string) $name)] = true;
        }

        return array_values(array_filter(self::OPTIONAL_COLUMNS, function (string $column) use ($existing): bool {
            return isset($existing[$column]);
        }));
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function text(array $row, string $key): string
    {
        return trim((string) ($row[$key] ?? ''));
    }

    /**
     * @return array<int,int>
     */
    private static function idList(string $value, string $delimiter): array
    {
        $ids = [];
        foreach (explode($delimiter, $value) as $part) {
            $part = trim($part);
            if ($part !== '' && is_numeric($part) && (int) $part > 0) {
                $ids[(int) $part] = (int) $part;
            }
        }

        return array_values($ids);
    }

    private static function hasVisibleText(string $html): bool
    {
        $text = str_ireplace('&nbsp;', ' ', preg_replace('/<[^>]*>/', '', $html) ?? '');

        return trim($text) !== '';
    }

    private static function hasCoordinate(string $value): bool
    {
        return (float) str_replace(',', '.', $value) != 0.0;
    }
}
