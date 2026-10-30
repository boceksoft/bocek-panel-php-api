<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/*
 * Yeni ilanda bos (0 fiyatli) aylik sezon satirlari. Eski panel yeni_emlak_ekle: her site icin icinde bulunulan
 * yilin 12 ayi, fiyat 0, gece 7, Kasim haric favori; ay adi sezon basligi (Ingilizce sitede Ingilizce).
 * Burada yil ve baslangic ayi ekrandan secilir (payload bos_sezon_yili, bos_sezon_baslangic_ay).
 * Config 'homes_empty_seasons' => ['enabled' => bool, 'nights' => int]. Ayni villada o ayla cakisan sezon varsa
 * (ayni site) o ay atlanir; tekrar kayitta cift satir olusmaz.
 */
final class HomesEmptySeasons
{
    private const MONTHS_TR = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    private const MONTHS_EN = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    /**
     * @param array<string,mixed> $app
     * @return array{enabled:bool,nights:int}
     */
    public static function config(array $app): array
    {
        $raw = $app['homes_empty_seasons'] ?? [];
        $raw = is_array($raw) ? $raw : [];
        $nights = (int) ($raw['nights'] ?? 7);

        return ['enabled' => !empty($raw['enabled']), 'nights' => $nights > 0 ? $nights : 7];
    }

    /**
     * Frontend icin ayar.
     *
     * @param array<string,mixed> $app
     * @return array{enabled:bool,nights:int}
     */
    public static function definition(array $app): array
    {
        return self::config($app);
    }

    /**
     * @param array<string,mixed> $app
     * @param array<string,mixed> $payload
     * @return array{created:int,skipped:array<int,array<string,string>>}
     */
    public static function apply(PDO $pdo, array $app, int $homeId, array $payload): array
    {
        $result = ['created' => 0, 'skipped' => []];
        $config = self::config($app);
        if (!$config['enabled'] || $homeId <= 0 || !array_key_exists('bos_sezon_yili', $payload)) {
            return $result;
        }

        $year = (int) $payload['bos_sezon_yili'];
        $startMonth = (int) ($payload['bos_sezon_baslangic_ay'] ?? 1);
        if ($year < 2000 || $year > 2100) {
            $result['skipped'][] = ['section' => 'bos_sezonlar', 'reason' => 'Gecersiz yil: ' . $year];

            return $result;
        }
        $startMonth = max(1, min(12, $startMonth));

        $columns = self::columns($pdo);
        if (!isset($columns['islem_id'], $columns['tarih1'], $columns['tarih2'])) {
            $result['skipped'][] = ['section' => 'bos_sezonlar', 'reason' => 'sezonlar tablosu bulunamadi.'];

            return $result;
        }

        foreach (self::sites($pdo, $app) as $site) {
            for ($month = $startMonth; $month <= 12; $month++) {
                $first = new \DateTime(sprintf('%04d-%02d-01', $year, $month));
                $last = (clone $first)->modify('last day of this month');
                $start = $first->format('d.m.Y');
                $end = $last->format('d.m.Y');

                if (self::overlaps($pdo, $homeId, $site['id'], $start, $end, isset($columns['site']))) {
                    continue;
                }

                $values = [
                    'site' => $site['id'],
                    'islem' => 'emlak',
                    'islem_id' => $homeId,
                    'tarih1' => $start,
                    'tarih2' => $end,
                    'fiyat' => 0,
                    'gece' => $config['nights'],
                    'sezon' => $site['english'] ? self::MONTHS_EN[$month - 1] : self::MONTHS_TR[$month - 1],
                    // Panel: Kasim disindaki aylar favori.
                    'favori' => $month === 11 ? 0 : 1,
                ];

                $insert = [];
                $params = [];
                foreach ($values as $column => $value) {
                    if (!isset($columns[strtolower($column)])) {
                        continue;
                    }
                    $insert[] = '[' . $columns[strtolower($column)] . ']';
                    $params[':' . $column] = $value;
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO sezonlar (' . implode(', ', $insert) . ') VALUES (' . implode(', ', array_keys($params)) . ')'
                );
                $stmt->execute($params);
                $result['created']++;
            }
        }

        return $result;
    }

    private static function overlaps(PDO $pdo, int $homeId, int $siteId, string $start, string $end, bool $hasSite): bool
    {
        $sql = "SELECT COUNT(*) FROM sezonlar
                WHERE islem_id = :homeId AND islem = 'emlak'
                  AND TRY_CONVERT(date, tarih1, 104) <= CONVERT(date, :endDate, 104)
                  AND TRY_CONVERT(date, tarih2, 104) >= CONVERT(date, :startDate, 104)";
        $params = [':homeId' => $homeId, ':startDate' => $start, ':endDate' => $end];
        if ($hasSite) {
            $sql .= ' AND ISNULL(site, 1) = :site';
            $params[':site'] = $siteId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Panel gibi tum siteler. Ay adi: config site_languages'ta 'tr' ise Turkce, baska dil ise Ingilizce;
     * dil tanimli degilse panel kurali (site 2 Ingilizce).
     *
     * @param array<string,mixed> $app
     * @return array<int,array{id:int,english:bool}>
     */
    private static function sites(PDO $pdo, array $app): array
    {
        $languages = is_array($app['site_languages'] ?? null) ? $app['site_languages'] : [];
        $ids = [];
        try {
            foreach ($pdo->query('SELECT id FROM sites ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        } catch (\Throwable $e) {
            $ids = [];
        }
        if ($ids === []) {
            $ids = [1];
        }

        $sites = [];
        foreach ($ids as $id) {
            $language = strtolower(trim((string) ($languages[$id] ?? $languages[(string) $id] ?? '')));
            $english = $language !== '' ? strpos($language, 'tr') !== 0 : $id === 2;
            $sites[] = ['id' => $id, 'english' => $english];
        }

        return $sites;
    }

    /**
     * @return array<string,string> kucuk harf => gercek ad
     */
    private static function columns(PDO $pdo): array
    {
        $columns = [];
        try {
            $stmt = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'sezonlar'");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $name = (string) ($row['COLUMN_NAME'] ?? '');
                if ($name !== '') {
                    $columns[strtolower($name)] = $name;
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $columns;
    }
}
