<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use DateTime;
use PDO;

/*
 * Villa musaitlik liste kaynagi.
 *   GET /backend-api/availability-homes?start=2026-07-01&end=2026-07-14&page=1
 */
final class AvailabilityHomesController extends Controller
{
    /**
     * @Get
     * @query start string Baslangic tarihi (Y-m-d / d.m.Y)
     * @query end string Bitis/cikis tarihi (Y-m-d / d.m.Y)
     * @query id int Villa kimligi
     * @query villaadi string Villa adi aramasi
     * @query bolge string Bolge kimlikleri
     * @query kisi int Minimum kisi
     * @query page int Sayfa
     * @query per_page int Sayfa basi kayit
     */
    public function index(): void
    {
        $pdo = $this->db->pdo();

        $startRaw = $this->p('start') !== '' ? $this->p('start') : $this->p('searchdate1');
        $endRaw = $this->p('end') !== '' ? $this->p('end') : $this->p('searchdate2');
        $dateFrom = $this->parseDate($startRaw);
        $dateTo = $this->parseDate($endRaw);
        $hasDate = ($dateFrom !== null && $dateTo !== null);

        if (($startRaw !== '' || $endRaw !== '') && !$hasDate) {
            throw new HttpException('Tarih formati gecersiz. Y-m-d veya d.m.Y kullanin.', 'VALIDATION', 422);
        }
        if ($hasDate && $dateTo <= $dateFrom) {
            throw new HttpException('end tarihi start tarihinden buyuk olmali.', 'VALIDATION', 422);
        }

        $siteId = $this->pInt('site', defined('PRICE_SITE') ? (int) constant('PRICE_SITE') : 1);
        $id = $this->pInt('id', $this->pInt('EntityId', 0));
        $villaAdi = $this->p('baslik') !== '' ? $this->p('baslik') : $this->p('villaadi');
        if ($villaAdi === '') {
            $villaAdi = $this->p('name');
        }
        if ($villaAdi === '') {
            $villaAdi = $this->p('q');
        }
        $bolge = $this->p('bolge');
        $kisi = $this->pInt('kisi', 0);

        $page = max(1, $this->pInt('page', 1));
        $perPage = max(1, $this->pInt('per_page', 50));
        $offset = ($page - 1) * $perPage;

        $defaultCurrencyId = defined('DEFAULT_CURRENCY_ID') ? (int) constant('DEFAULT_CURRENCY_ID') : 1;
        $uzanti = defined('UZANTI') ? (string) constant('UZANTI') : '';
        $bindRateId = defined('RATE_ID')
            ? constant('RATE_ID')
            : (class_exists('Rate') ? \Rate::GetLastRate() : 1);

        $where = [
            "h.aktif{$uzanti} = 1",
            'd2.aktif = 1',
            'd1.aktif = 1',
            't.aktif = 1',
        ];
        $params = [
            ':DefaultCurrencyId' => $defaultCurrencyId,
            ':RateId' => $bindRateId,
            ':site' => $siteId,
        ];

        if ($id > 0) {
            $where[] = 'h.id = :id';
            $params[':id'] = $id;
        }
        if ($villaAdi !== '') {
            $where[] = "(h.baslik{$uzanti} LIKE :villaadi OR h.evkodu LIKE :villaadi)";
            $params[':villaadi'] = '%' . $villaAdi . '%';
        }
        if ($kisi > 0) {
            $where[] = 'h.kisi >= :kisi';
            $params[':kisi'] = $kisi;
        }
        if ($bolge !== '' && $bolge !== '0') {
            $bolgeIds = $this->safeIntList($bolge);
            if ($bolgeIds !== '') {
                $where[] = "(d2.id IN ({$bolgeIds}) OR d2.cat IN ({$bolgeIds}))";
            }
        }

        $totalDays = 0;
        $availabilityApply = 'OUTER APPLY (SELECT CAST(NULL AS int) AS dolu_gun, CAST(NULL AS int) AS bos_gun) AS availability';
        $availabilitySelect = '
    CAST(NULL AS bit) AS available,
    CAST(NULL AS nvarchar(20)) AS musaitlik_durumu,
    CAST(NULL AS int) AS dolu_gun,
    CAST(NULL AS int) AS bos_gun,
    CAST(NULL AS decimal(10, 2)) AS dolu_bos_orani,';

        if ($hasDate) {
            $totalDays = (int) $dateFrom->diff($dateTo)->days;
            $params[':dateFrom'] = $dateFrom->format('Y-m-d');
            $params[':dateTo'] = $dateTo->format('Y-m-d');

            $availabilityApply = "
OUTER APPLY (
    SELECT COUNT(1) AS dolu_gun, {$totalDays} - COUNT(1) AS bos_gun
    FROM (
        SELECT DATEADD(day, seq.n, CONVERT(date, :dateFrom, 23)) AS gun
        FROM (
            SELECT TOP ({$totalDays}) ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) - 1 AS n
            FROM sys.all_objects
        ) AS seq
    ) AS gunler
    WHERE EXISTS (
        SELECT 1
        FROM dolu
        WHERE dolu.emlak = h.id
          AND dolu.durum = 3
          AND gunler.gun >= CONVERT(date, dolu.tarih, 103)
          AND gunler.gun < CONVERT(date, dolu.tarih2, 103)
    )
    OR EXISTS (
        SELECT 1
        FROM KiralamaTakvimi.CalendarHomes ch
        INNER JOIN KiralamaTakvimi.HotelAvailabilityRooms har ON har.EstateId = ch.EstateId
        WHERE ch.homesId = h.id
          AND CONVERT(varchar(10), ch.RoomType) = '1'
          AND (har.RoomCount = 0 OR har.IsClosed = 1)
          AND CONVERT(date, har.Date) = gunler.gun
    )
) AS availability";

            $availabilitySelect = "
    CAST(CASE WHEN availability.dolu_gun = 0 THEN 1 ELSE 0 END AS bit) AS available,
    CASE
        WHEN availability.dolu_gun = 0 THEN 'musait'
        WHEN availability.dolu_gun >= {$totalDays} THEN 'dolu'
        ELSE 'kismi_dolu'
    END AS musaitlik_durumu,
    availability.dolu_gun,
    availability.bos_gun,
    CAST(CASE
        WHEN availability.bos_gun = 0 AND availability.dolu_gun > 0 THEN 100
        WHEN availability.bos_gun = 0 THEN 0
        ELSE availability.dolu_gun * 100.0 / availability.bos_gun
    END AS decimal(10, 2)) AS dolu_bos_orani,";
        }

        $seasonDateFilter = $hasDate
            ? 'AND CONVERT(date, sezonlar.tarih1, 103) < CONVERT(date, :dateTo, 23)
               AND CONVERT(date, sezonlar.tarih2, 103) > CONVERT(date, :dateFrom, 23)'
            : 'AND CONVERT(date, sezonlar.tarih2, 103) >= CONVERT(date, GETDATE(), 103)';

        $whereSql = implode(' AND ', $where);
        $sql = "
SELECT
    COUNT(*) OVER() AS totalCount,
    h.id,
    h.baslik{$uzanti} AS name,
    d2.id AS region_id,
    d1.baslik{$uzanti} + ' / ' + d2.baslik{$uzanti} AS region,
    {$availabilitySelect}
    CAST(ISNULL(price_calc.price, 0) AS int) AS price
FROM homes h
INNER JOIN tip t ON t.id = h.emlak_tipi
INNER JOIN destinations d2 ON d2.id = h.emlak_bolgesi
INNER JOIN destinations d1 ON d1.id = d2.cat
INNER JOIN destinations d0 ON d0.id = d1.cat
{$availabilityApply}
OUTER APPLY (
    SELECT MIN(CAST(ISNULL(CONVERT(float, sezonlar.fiyat * ISNULL(RD.Buy, 1)), 0) / 7 AS decimal(18, 2))) AS price
    FROM sezonlar
    INNER JOIN Finance.Currency FromC ON FromC.CurrencyName = h.doviz{$uzanti}
    INNER JOIN Finance.Currency ToC ON ToC.CurrencyId = :DefaultCurrencyId
    LEFT JOIN Finance.RateDetail RD ON RD.ToCurrencyId = ToC.CurrencyId
        AND RD.FromCurrencyId = FromC.CurrencyId
        AND RD.RateId = :RateId
    WHERE sezonlar.site = :site
      AND sezonlar.islem_id = h.id
      AND sezonlar.islem = 'emlak'
      {$seasonDateFilter}
) AS price_calc
WHERE {$whereSql}
ORDER BY h.baslik{$uzanti} ASC, h.id ASC
OFFSET :offset ROWS FETCH NEXT :perPage ROWS ONLY";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':perPage', $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = $rows ? (int) $rows[0]['totalCount'] : 0;
        foreach ($rows as &$row) {
            unset($row['totalCount']);
            $row['id'] = (int) $row['id'];
            $row['region_id'] = (int) $row['region_id'];
            $row['price'] = (int) $row['price'];
            if ($hasDate) {
                $row['available'] = (bool) $row['available'];
                $row['dolu_gun'] = (int) $row['dolu_gun'];
                $row['bos_gun'] = (int) $row['bos_gun'];
                $row['dolu_bos_orani'] = (float) $row['dolu_bos_orani'];
            } else {
                $row['available'] = null;
                $row['dolu_gun'] = null;
                $row['bos_gun'] = null;
                $row['dolu_bos_orani'] = null;
            }
        }
        unset($row);

        $this->response->success([
            'items' => $rows,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
                'date_from' => $hasDate ? $dateFrom->format('Y-m-d') : null,
                'date_to' => $hasDate ? $dateTo->format('Y-m-d') : null,
                'total_days' => $totalDays,
                'count' => count($rows),
            ],
        ]);
    }

    private function p(string $key, string $default = ''): string
    {
        $val = $_GET[$key] ?? ($_POST[$key] ?? $default);

        return trim((string) $val);
    }

    private function pInt(string $key, int $default = 0): int
    {
        $v = $this->p($key);

        return is_numeric($v) ? (int) $v : $default;
    }

    /**
     * @return DateTime|null
     */
    private function parseDate(string $s)
    {
        if ($s === '') {
            return null;
        }
        foreach (['Y-m-d', 'd.m.Y', 'm/d/Y'] as $fmt) {
            $d = DateTime::createFromFormat($fmt, $s);
            if ($d !== false) {
                $d->setTime(0, 0, 0);

                return $d;
            }
        }

        return null;
    } 

    private function safeIntList(string $s): string
    {
        $parts = array_map('intval', explode(',', $s));
        $valid = array_filter($parts, function ($v) {
            return $v > 0;
        });

        return $valid ? implode(',', $valid) : '';
    }
}
