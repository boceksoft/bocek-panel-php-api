<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use DateTime;
use PDO;

/*
 * Gunluk misafir hareketleri.
 *   GET|POST /backend-api/guest-movements?startDate=2026-08-17&endDate=2026-08-18&type=all
 */
final class GuestMovementsController extends Controller
{
    /**
     * Secilen tarih araligina gore giris, cikis ve icerdeki misafirleri listeler.
     *
     * @Get
     * @Post
     * @query startDate string required Baslangic tarihi (YYYY-MM-DD, DD.MM.YYYY veya DD/MM/YYYY)
     * @query endDate string required Bitis tarihi, haric tutulur (YYYY-MM-DD, DD.MM.YYYY veya DD/MM/YYYY)
     * @query type string all|giris|cikis|icerde
     * @query mode string all|giris|cikis|icerde
     * @query kelime string Villa, musteri veya telefon aramasi
     * @query search string Villa, musteri veya telefon aramasi
     */
    public function index(): void
    {
        $startDate = $this->parseDate($this->firstRequestValue(['startDate', 'start_date', 'date'], ''));
        if ($startDate === '') {
            $startDate = (new DateTime('today'))->format('Y-m-d');
        }

        $endDate = $this->parseDate($this->firstRequestValue(['endDate', 'end_date'], ''));
        if ($endDate === '') {
            $endDate = $this->nextDate($startDate);
        }
        if ($endDate <= $startDate) {
            throw new HttpException('endDate startDate tarihinden sonra olmali.', 'VALIDATION_ERROR', 422);
        }

        $type = strtolower(trim((string) $this->firstRequestValue(['type', 'mode', 'tur'], 'all')));
        if ($type === '') {
            $type = 'all';
        }
        if (!in_array($type, ['all', 'giris', 'cikis', 'icerde'], true)) {
            throw new HttpException('type all, giris, cikis veya icerde olmali.', 'VALIDATION_ERROR', 422);
        }

        $page = max(1, (int) $this->firstRequestValue(['page'], '1'));
        $perPage = max(1, (int) $this->firstRequestValue(['per_page', 'limit'], '50'));
        $search = $this->firstRequestValue(['kelime', 'search', 'q'], '');

        $girisRows = $this->fetchRows('giris', $startDate, $endDate);
        $cikisRows = $this->fetchRows('cikis', $startDate, $endDate);
        $icerdeRows = $this->fetchRows('icerde', $startDate, $endDate);

        if ($search !== '') {
            $girisRows = $this->filterRowsBySearch($girisRows, $search);
            $cikisRows = $this->filterRowsBySearch($cikisRows, $search);
            $icerdeRows = $this->filterRowsBySearch($icerdeRows, $search);
        }

        $totals = [
            'total' => count($girisRows) + count($cikisRows) + count($icerdeRows),
            'giris' => count($girisRows),
            'cikis' => count($cikisRows),
            'icerde' => count($icerdeRows),
        ];

        if ($type === 'giris') {
            $items = $girisRows;
        } elseif ($type === 'cikis') {
            $items = $cikisRows;
        } elseif ($type === 'icerde') {
            $items = $icerdeRows;
        } else {
            $items = array_merge($girisRows, $icerdeRows, $cikisRows);
            usort($items, function (array $a, array $b): int {
                return [$a['hareket_sira'], $a['hareket_tarihi'], $a['villa_ismi'], $a['musteri_adi']]
                    <=> [$b['hareket_sira'], $b['hareket_tarihi'], $b['villa_ismi'], $b['musteri_adi']];
            });
        }

        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $pagedItems = array_slice($items, $offset, $perPage);
        foreach ($pagedItems as &$row) {
            unset($row['hareket_sira']);
        }
        unset($row);

        $payload = [
            'date' => $startDate,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'type' => $type,
            'total' => $totals['total'],
            'giris_count' => $totals['giris'],
            'cikis_count' => $totals['cikis'],
            'icerde_count' => $totals['icerde'],
            'totals' => $totals,
            'items' => $pagedItems,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
                'count' => count($pagedItems),
            ],
        ];

        $this->response->success($payload);
    }

    /**
     * @param string[] $keys
     */
    private function firstRequestValue(array $keys, string $default): string
    {
        foreach ($keys as $key) {
            $value = $this->request->query($key, null);
            if ($value === null) {
                $value = $this->request->input($key, null);
            }
            if ($value !== null && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return $default;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function filterRowsBySearch(array $rows, string $search): array
    {
        $tokens = preg_split('/\s+/', $this->normalizeSearchText($search)) ?: [];
        $tokens = array_values(array_filter($tokens, function (string $token): bool {
            return $token !== '';
        }));

        if (!$tokens) {
            return $rows;
        }

        return array_values(array_filter($rows, function (array $row) use ($tokens): bool {
            $haystack = $this->normalizeSearchText(implode(' ', [
                (string) ($row['villa_ismi'] ?? ''),
                (string) ($row['musteri_adi'] ?? ''),
                (string) ($row['musteri_teli'] ?? ''),
                (string) ($row['villa_sahibi_ismi'] ?? ''),
                (string) ($row['villa_sahibi_teli'] ?? ''),
            ]));

            foreach ($tokens as $token) {
                if (strpos($haystack, $token) === false) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function normalizeSearchText(string $value): string
    {
        $value = trim($value);
        $value = function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);

        return preg_replace('/\s+/', ' ', $value) ?? '';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchRows(string $type, string $startDate, string $endDate): array
    {
        $reservationIdSql = $this->doluReservationIdSql();
        $whereSql = $this->dateWhere($type);
        $externalFilterSql = $this->columnExists('dbo.dolu', 'IsExternal')
            ? 'AND ISNULL(d.IsExternal, 0) <> 1'
            : '';

        $orderDateSql = $type === 'cikis'
            ? 'CONVERT(date, d.tarih2, 103)'
            : 'CONVERT(date, d.tarih, 103)';
        $movementStatusLabel = $this->movementStatusLabel($type);
        $movementSortValue = $this->movementSortValue($type);

        $sql = "  
SELECT
    d.id AS dolu_id,
    {$reservationIdSql} AS rezid,
    N'{$movementStatusLabel}' AS durum,
    N'{$movementStatusLabel}' AS hareket_durumu,
    '{$type}' AS hareket_tipi,
    {$movementSortValue} AS hareket_sira,
    CONVERT(varchar(10), {$orderDateSql}, 23) AS hareket_tarihi,
    h.id AS villa_id,
    ISNULL(h.baslik, k.adi) AS villa_ismi,
    h.url AS villa_url,
    LTRIM(RTRIM(CONCAT(ISNULL(es.ad, ''), ' ', ISNULL(es.soyad, '')))) AS villa_sahibi_ismi,
    REPLACE(ISNULL(es.tel, ''), ' ', '') AS villa_sahibi_teli,
    k.musteri AS musteri_adi,
    LTRIM(RTRIM(CONCAT(ISNULL('+' + CONVERT(nvarchar(20), k.ulkekodu), ''), ' ', ISNULL(k.telefon, '')))) AS musteri_teli,
    CONVERT(varchar(10), d.tarih, 104) AS giris_tarihi,
    CONVERT(varchar(10), d.tarih2, 104) AS cikis_tarihi,
    d.durum AS rezervasyon_durum,
    CASE d.durum
        WHEN 0 THEN N'Onay Bekliyor'
        WHEN 1 THEN N'Odeme Bekliyor'
        WHEN 2 THEN N'Sure Doldu'
        WHEN 3 THEN N'Onaylandi'
        WHEN 4 THEN N'Iptal Edildi'
        WHEN 5 THEN N'Silindi'
        WHEN 6 THEN N'Acik Rezervasyon'
        ELSE N'-'
    END AS rezervasyon_durum_text
FROM dolu d
INNER JOIN kayitlar k ON k.id = {$reservationIdSql}
LEFT JOIN homes h ON h.id = d.emlak
LEFT JOIN kullanici es ON es.id = h.evsahibi
WHERE {$whereSql}
  AND d.durum = 3
  AND ISNULL(k.musteri, '') <> ''
  AND {$reservationIdSql} IS NOT NULL
  {$externalFilterSql}
ORDER BY
    {$orderDateSql} ASC,
    h.baslik ASC,
    k.musteri ASC";

        $stmt = $this->db->pdo()->prepare($sql);
        $this->bindDateWhereValues($stmt, $startDate, $endDate);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            foreach (['dolu_id', 'rezid', 'villa_id', 'rezervasyon_durum'] as $field) {
                if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') {
                    $row[$field] = (int) $row[$field];
                }
            }
            if (array_key_exists('hareket_sira', $row)) {
                $row['hareket_sira'] = (int) $row['hareket_sira'];
            }
            if (array_key_exists('villa_url', $row)) {
                $row['villa_url'] = $this->fullHomeUrl((string) $row['villa_url']);
            }
        }
        unset($row);

        return $rows;
    }

    private function dateWhere(string $type): string
    {
        if ($type === 'giris') {
            return 'CONVERT(date, d.tarih, 103) >= CONVERT(date, :startDate, 23)
                AND CONVERT(date, d.tarih, 103) < CONVERT(date, :endDate, 23)';
        }
        if ($type === 'cikis') {
            return 'CONVERT(date, d.tarih2, 103) >= CONVERT(date, :startDate, 23)
                AND CONVERT(date, d.tarih2, 103) < CONVERT(date, :endDate, 23)';
        }

        return 'CONVERT(date, d.tarih, 103) < CONVERT(date, :startDate, 23)
            AND CONVERT(date, d.tarih2, 103) >= CONVERT(date, :endDate, 23)';
    }

    private function bindDateWhereValues(\PDOStatement $stmt, string $startDate, string $endDate): void
    {
        $stmt->bindValue(':startDate', $startDate);
        $stmt->bindValue(':endDate', $endDate);
    }

    private function nextDate(string $date): string
    {
        return (new DateTime($date))->modify('+1 day')->format('Y-m-d');
    }

    private function movementStatusLabel(string $type): string
    {
        if ($type === 'giris') {
            return 'Giriş Yapacaklar';
        }
        if ($type === 'cikis') {
            return 'Çıkış Yapacaklar';
        }

        return 'İçerideki Misafirler';
    }

    private function movementSortValue(string $type): int
    {
        if ($type === 'giris') {
            return 1;
        }
        if ($type === 'icerde') {
            return 2;
        }

        return 3;
    }

    private function doluReservationIdSql(): string
    {
        $column = (string) ($this->app['dolu_kayit_id_column'] ?? 'kayitid');
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            throw new HttpException('dolu kayit id kolonu gecersiz.', 'CONFIG_ERROR', 500);
        }

        return 'd.[' . $column . ']';
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->pdo()->prepare("SELECT CASE WHEN OBJECT_ID(:table, 'U') IS NULL THEN 0 ELSE 1 END");
        $stmt->bindValue(':table', $table);
        $stmt->execute();

        return (int) $stmt->fetchColumn() === 1;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT CASE WHEN COL_LENGTH(:table, :column) IS NULL THEN 0 ELSE 1 END');
        $stmt->bindValue(':table', $table);
        $stmt->bindValue(':column', $column);
        $stmt->execute();

        return (int) $stmt->fetchColumn() === 1;
    }

    private function fullHomeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $domain = defined('Domain') ? $this->normalizeDomain((string) constant('Domain')) : '';

        return $domain !== '' ? $domain . '/' . ltrim($url, '/') : $url;
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = rtrim(trim($domain), '/');
        if ($domain !== '' && preg_match('#^https?://#i', $domain) !== 1) {
            $domain = 'https://' . $domain;
        }

        return $domain;
    }

    private function parseDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $formats = ['Y-m-d', 'd.m.Y', 'd/m/Y'];
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat('!' . $format, $value);
            if ($date instanceof DateTime && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        throw new HttpException('tarih formati gecersiz. Ornek: 2026-08-17', 'VALIDATION_ERROR', 422);
    }
}
