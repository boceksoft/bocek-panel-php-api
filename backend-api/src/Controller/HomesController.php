<?php

declare(strict_types=1);

namespace App\Controller;

use DateTime;
use PDO;

/*
 * Villa arama kaynağı (villaAra.asp dönüşümü).
 *   GET /backend-api/homes?site=1&start=2026-07-01&end=2026-07-14&kisi=6&...
 * (Eski get-homes.php taşınmış hâli.)
 *
 * NOT: Eski koddaki çok-dilli $SITES tablo son eki ($dbt) burada '' (varsayılan site)
 * kabul edilir. Çok siteli/çok dilli sürüm gerekiyorsa $SITES dış config'ten bağlanmalı.
 */
final class HomesController extends Controller
{
    /**
     * @Get
     * @query site int Site kimliği
     * @query start string Giriş tarihi (Y-m-d / d.m.Y)
     * @query end string Çıkış tarihi
     * @query kisi int Minimum kişi
     * @query tip string Tip kimlik(ler)i (virgüllü ya da tekrarlı)
     * @query bolge string Bölge kimlik(ler)i
     * @query ozellik string Özellik kimlik(ler)i
     * @query villaadi string Villa adı araması
     * @query order_by int Sıralama (0-6)
     * @query page int Sayfa
     * @query per_page int Sayfa başına kayıt
     */
    public function index(): void
    {
        $pdo = $this->db->pdo();
        $now = new DateTime();

        $siteId = $this->pInt('site', 1);
        $dbt = ''; // Çok-dilli tablo son eki (varsayılan site için boş)

        $startRaw = $this->p('start') !== '' ? $this->p('start') : $this->p('searchdate1');
        $endRaw   = $this->p('end') !== '' ? $this->p('end') : $this->p('searchdate2');
        $hasDate  = ($startRaw !== '' || $endRaw !== '');

        $tarih1 = $this->parseDate($startRaw);
        $tarih2 = $this->parseDate($endRaw);
        if ($hasDate) {
            if ($tarih1 === null) {
                $tarih1 = clone $now;
            }
            if ($tarih2 === null) {
                $tarih2 = clone $tarih1;
                $tarih2->modify('+7 days');
            }
        }

        $netTarih = $this->p('netTarih', '1');
        $season   = $this->pInt('season', (int) $now->format('n'));

        if ($hasDate && $netTarih === '0') {
            $year   = (int) $now->format('Y');
            $tarih1 = new DateTime("{$year}-{$season}-01");
            $tarih2 = clone $tarih1;
            $tarih2->modify('last day of this month');
        }

        $t1  = $this->d104($tarih1);
        $t2  = $this->d104($tarih2);
        $gun = ($tarih1 && $tarih2) ? (int) $tarih1->diff($tarih2)->days : 0;

        $nd1 = $nd2 = '';
        if ($tarih1 && $tarih2) {
            $nd1Clone = clone $tarih1;
            $nd1Clone->modify('+1 day');
            $nd2Clone = clone $tarih2;
            $nd2Clone->modify('-1 day');
            $nd1 = $this->dISO($nd1Clone);
            $nd2 = $this->dISO($nd2Clone);
        }

        $min       = $this->pInt('min', 0);
        $max       = $this->pInt('max', 0);
        $priceType = $this->pInt('priceType', 0);

        // Döviz + kur
        $dovizRaw = $this->p('doviz');
        $eskiKur  = $this->p('kur');
        if ($eskiKur !== '') {
            if ($eskiKur === '1' || $eskiKur === '0') {
                $dovizRaw = 'tl';
            } elseif ($eskiKur === '2') {
                $dovizRaw = 'dolar';
            } elseif ($eskiKur === '3') {
                $dovizRaw = 'euro';
            } elseif ($eskiKur === '4' || $eskiKur === '5') {
                $dovizRaw = 'pound';
            }
        }
        $doviz = in_array($dovizRaw, ['tl', 'dolar', 'euro', 'pound'], true) ? $dovizRaw : 'tl';

        $hedefKur = 1.0;
        if ($doviz !== 'tl') {
            try {
                $rateStmt = $pdo->prepare('SELECT rate FROM rate WHERE CurrencyName = ?');
                $rateStmt->execute([$doviz]);
                $rateRow = $rateStmt->fetch();
                if ($rateRow && (float) $rateRow['rate'] > 0) {
                    $hedefKur = (float) $rateRow['rate'];
                }
            } catch (\PDOException $e) {
                // kur bulunamazsa 1.0 kalır
            }
        }

        // Fiyat aralığı (ASP birebir)
        if ($priceType === 1) {
            $minTL = 1;
            $maxTL = 20000;
        } elseif ($priceType === 2) {
            $minTL = 20000;
            $maxTL = 50000;
        } elseif ($priceType === 3) {
            $minTL = 50000;
            $maxTL = 100000;
        } else {
            $minTL = (int) round($min * $hedefKur);
            $maxTL = (int) round($max * $hedefKur);
        }

        $kisi = $this->pInt('kisi', 0);
        $tipx = $this->multiQuery('tip');
        if ($tipx === '') {
            $tipx = $this->p('tip');
        }

        $tip2     = $this->p('tip2', '1');
        $bolge    = $this->p('bolge');
        $ozellik  = $this->p('ozellik');
        $villaadi = $this->p('villaadi');
        $orderBy  = $this->pInt('order_by', 0);

        $gKuraliParam = $this->p('gavelkurali');
        if ($gKuraliParam === '') {
            $gKuraliParam = '1';
        }

        $takvimKuraliReq = $this->p('takvimkurali');
        $geceRaw    = $this->p('gece', '');
        $kisasureli = ($this->p('kisasureli') === '1') || (is_numeric($geceRaw) && (int) $geceRaw > 0);
        $geceSql    = $geceRaw !== '' ? ($this->safeIntList($geceRaw) ?: '2,3,4,5,6') : '2,3,4,5,6';

        $page    = max(1, $this->pInt('page', 1));
        $perPage = max(1, $this->pInt('per_page', 50));
        $offset  = ($page - 1) * $perPage;

        // Dinamik SQL parçaları
        [$takvimSelect, $takvimCross, $takvimWhere] = $this->takvimParts($hasDate, $takvimKuraliReq, $gun, $t1, $t2);
        $dsql2       = $this->fiyatParcasi($hasDate, $netTarih, $kisasureli, $siteId, $t1, $t2);
        [$ksqlx, $ksql] = $this->kisasureliParts($hasDate, $kisasureli, $geceSql);
        $nettarihSql = $this->nettarihParcasi($hasDate, $netTarih, $minTL, $maxTL, $season, (int) $now->format('Y'), $siteId);

        $sql = $this->buildSql(compact(
            'dbt', 'doviz', 'takvimSelect', 'hedefKur', 'ksqlx', 'ksql', 'nettarihSql',
            'takvimCross', 'hasDate', 't1', 't2', 'dsql2', 'takvimWhere'
        ));

        // Dinamik WHERE
        $params = [];
        if ($gKuraliParam === '1') {
            $sql .= ' AND ISNULL(kanun.gavel, 0) = 0 ';
        }

        if ($hasDate && $netTarih !== '0') {
            if (!$kisasureli) {
                $sql .= " AND (SELECT TOP 1 COUNT(dolu.id) FROM dolu
                    WHERE dolu.emlak = h.id AND dolu.durum = 3
                      AND (('{$nd2}' BETWEEN dolu.tarih AND dolu.tarih2)
                        OR ('{$nd1}' BETWEEN dolu.tarih AND dolu.tarih2)
                        OR (dolu.tarih BETWEEN '{$nd1}' AND '{$nd2}')
                        OR (dolu.tarih2 BETWEEN '{$nd1}' AND '{$nd2}'))) = 0";
            } else {
                $sql .= " AND (('{$nd2}' BETWEEN kisasureli.tarih AND kisasureli.tarih2)
                    OR ('{$nd1}' BETWEEN kisasureli.tarih AND kisasureli.tarih2)
                    OR (kisasureli.tarih BETWEEN '{$nd1}' AND '{$nd2}')
                    OR (kisasureli.tarih2 BETWEEN '{$nd1}' AND '{$nd2}'))";
            }
        }

        if ($maxTL > 0) {
            $sql .= " AND fiyatlar_num.sqfiyat * rate_num.rate BETWEEN {$minTL} AND {$maxTL}";
        }

        $orderByEk = '';
        if ($kisi > 0) {
            $orderByEk = ' h.kisi ASC, ';
            $sql .= " AND h.kisi >= {$kisi}";
        }

        if ($tipx !== '' && $tipx !== '0') {
            $tipIds = array_filter(array_map('intval', explode(',', $tipx)), function ($v) {
                return $v > 0;
            });
            if ($tipIds) {
                if ($tip2 === '2') {
                    $parts = array_map(function ($t) {
                        return "(','+REPLACE(h.kategori,' ','')+',' LIKE '%,{$t},%' OR h.emlak_tipi = {$t})";
                    }, $tipIds);
                    $sql .= ' AND (1=2 OR ' . implode(' OR ', $parts) . ')';
                } else {
                    foreach ($tipIds as $t) {
                        $sql .= " AND (','+REPLACE(h.kategori,' ','')+',' LIKE '%,{$t},%' OR h.emlak_tipi = {$t})";
                    }
                }
            }
        }

        if ($bolge !== '' && $bolge !== '0') {
            $bolgeIds = array_filter(array_map('intval', explode(',', $bolge)), function ($v) {
                return $v > 0;
            });
            if ($bolgeIds) {
                $parts = array_map(function ($b) {
                    return "({$b} IN (d2.id, d1.id, d0.id))";
                }, $bolgeIds);
                $sql .= ' AND (' . implode(' OR ', $parts) . ')';
            }
        }

        if ($ozellik !== '') {
            $ozellikIds = array_filter(array_map('intval', explode(',', $ozellik)), function ($v) {
                return $v > 0;
            });
            foreach ($ozellikIds as $oid) {
                $sql .= " AND '#'+REPLACE(h.ozellikler,' ','')+'#' LIKE '%#{$oid}#%'";
            }
        }

        if ($villaadi !== '') {
            $params[':villaadi'] = "%{$villaadi}%";
            $sql .= ' AND h.baslik LIKE :villaadi';
        }

        // ORDER BY + sayfalama
        $isFilterSelected = (
            $hasDate || $this->pInt('kisi') > 0 || $this->p('tip') !== '' ||
            $this->p('bolge') !== '' || $this->p('ozellik') !== '' || $this->p('villaadi') !== ''
        );
        $sql .= "\nORDER BY " . $this->orderClause($orderBy, $orderByEk, $isFilterSelected);
        $sql .= "\nOFFSET {$offset} ROWS FETCH NEXT {$perPage} ROWS ONLY";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = $rows ? (int) $rows[0]['totalCount'] : 0;
        $cdnBase = (defined('Cdn') ? (string) constant('Cdn') : '') . '/uploads/small/';

        foreach ($rows as &$row) {
            unset($row['totalCount']);
            if (isset($row['fiyat'])) {
                $row['fiyat'] = (int) round((float) $row['fiyat']);
            }
            if (isset($row['girisbosluk']) && (int) $row['girisbosluk'] === 999) {
                $row['girisbosluk'] = null;
            }
            if (isset($row['cikisbosluk']) && (int) $row['cikisbosluk'] === 999) {
                $row['cikisbosluk'] = null;
            }

            $resimStr = isset($row['resim']) ? (string) $row['resim'] : '';
            if ($resimStr !== '') {
                $liste = array_values(array_filter(array_map('trim', explode(',', $resimStr))));
                $row['resim_liste'] = array_map(function ($r) use ($cdnBase) {
                    return $cdnBase . $r;
                }, $liste);
                $row['kapak_resmi'] = $row['resim_liste'] ? $row['resim_liste'][0] : null;
            } else {
                $row['resim_liste'] = [];
                $row['kapak_resmi'] = null;
            }
            unset($row['resim']);
        }
        unset($row);

        $this->response->success([
            'items' => $rows,
            'meta' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
                'date_from'   => $tarih1 ? $this->dISO($tarih1) : null,
                'date_to'     => $tarih2 ? $this->dISO($tarih2) : null,
                'total_days'  => $gun,
                'count'       => count($rows),
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $c
     */
    private function buildSql(array $c): string
    {
        $homeRate = "TRY_CONVERT(decimal(18, 6), NULLIF(CONVERT(varchar(64), h.kur{$c['dbt']}), ''))";
        $currencyRate = 'rate_num.rate';
        $price = 'fiyatlar_num.sqfiyat';

        return "
SELECT
    COUNT(*) OVER() AS totalCount,
    h.yatak_odasi, h.id,
    h.url{$c['dbt']} AS url,
    h.title{$c['dbt']} AS title,
    h.kisa_icerik{$c['dbt']} AS kisa_icerik,
    CASE WHEN {$homeRate} > 0 THEN {$homeRate} ELSE 0 END AS kur,
    h.baslik{$c['dbt']} AS baslik,
    h.baslik AS basliko,
    h.icerik{$c['dbt']},
    h.enlem, h.boylam, h.resim,
    h.ribbon{$c['dbt']} AS ribbon,
    h.ribbon2{$c['dbt']} AS ribbon2,
    h.yuzme_havuzu, h.kisi, h.oda_sayisi,
    '{$c['doviz']}' AS doviz,
    d2.baslik{$c['dbt']} AS d2baslik,
    {$c['takvimSelect']}
    CAST(ROUND(
        (CASE
            WHEN h.doviz{$c['dbt']} = '{$c['doviz']}' THEN {$price}
            WHEN h.doviz{$c['dbt']} = 'tl' THEN ({$price} / NULLIF({$c['hedefKur']}, 0))
            WHEN '{$c['doviz']}' = 'tl' THEN ({$price} * (CASE WHEN {$homeRate} > 0 THEN {$homeRate} ELSE {$currencyRate} END))
            ELSE ({$price} * (CASE WHEN {$homeRate} > 0 THEN {$homeRate} ELSE {$currencyRate} END) / NULLIF({$c['hedefKur']}, 0))
        END), 0
    ) AS INT) AS fiyat,
    {$c['ksqlx']}
    h.banyo,
    d1.baslik{$c['dbt']} + ' / ' + d2.baslik{$c['dbt']} AS bolgebaslik,
    mm.val AS mm,
    bosluklar.girisbosluk, bosluklar.cikisbosluk
FROM homes AS h
INNER JOIN rate ON rate.CurrencyName = h.doviz{$c['dbt']}
{$c['ksql']}
{$c['nettarihSql']}
{$c['takvimCross']}
CROSS APPLY (
    SELECT ISNULL(TRY_CONVERT(decimal(18, 6), NULLIF(CONVERT(varchar(64), (
        SELECT TOP 1 gece FROM sezonlar
        WHERE site = 1 AND islem = 'emlak' AND islem_id = h.id
          " . ($c['hasDate'] ? "AND LEN(CONVERT(date, '{$c['t1']}', 104)) >= 8 AND CONVERT(date, '{$c['t1']}', 104) BETWEEN CONVERT(date, tarih1, 104) AND CONVERT(date, tarih2, 104)" : '') . "
    )), '')), 0) AS val
) AS mm
CROSS APPLY (
    SELECT
        ISNULL((SELECT TOP 1 DATEDIFF(day, CONVERT(date, od.tarih2, 103), " . ($c['hasDate'] ? "CONVERT(date, '{$c['t1']}', 103)" : 'GETDATE()') . ")
            FROM dolu od WHERE od.emlak = h.id AND od.Durum = 3
              " . ($c['hasDate'] ? "AND CONVERT(date, od.tarih2, 103) <= CONVERT(date, '{$c['t1']}', 104)" : '') . "
              AND CONVERT(date, od.tarih2, 103) >= CONVERT(date, GETDATE(), 103)
            ORDER BY od.tarih2 DESC), 999) AS girisbosluk,
        ISNULL((SELECT TOP 1 DATEDIFF(day, " . ($c['hasDate'] ? "CONVERT(date, '{$c['t2']}', 103)" : 'GETDATE()') . ", CONVERT(date, od.tarih, 103))
            FROM dolu od WHERE od.emlak = h.id AND od.Durum = 3
              " . ($c['hasDate'] ? "AND CONVERT(date, od.tarih, 103) >= CONVERT(date, '{$c['t2']}', 104)" : '') . "
            ORDER BY od.tarih ASC), 999) AS cikisbosluk
) AS bosluklar
CROSS APPLY ({$c['dsql2']}) AS fiyatlar
CROSS APPLY (
    SELECT TRY_CONVERT(decimal(18, 6), NULLIF(CONVERT(varchar(64), fiyatlar.sqfiyat), '')) AS sqfiyat
) AS fiyatlar_num
CROSS APPLY (
    SELECT TRY_CONVERT(decimal(18, 6), NULLIF(CONVERT(varchar(64), rate.rate), '')) AS rate
) AS rate_num
INNER JOIN tip t ON t.id = h.emlak_tipi
INNER JOIN destinations d2 ON d2.id = h.emlak_bolgesi
INNER JOIN destinations d1 ON d1.id = d2.cat
INNER JOIN destinations d0 ON d0.id = d1.cat
LEFT  JOIN destinations d ON d.id = d0.cat
LEFT  JOIN kanun7464 kanun ON kanun.homeId = h.id
WHERE h.aktif{$c['dbt']} = 1
  AND d2.aktif = 1 AND d1.aktif = 1 AND t.aktif = 1
  AND fiyatlar_num.sqfiyat > 0
  AND rate_num.rate > 0
  {$c['takvimWhere']}
";
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function takvimParts(bool $hasDate, string $takvimKuraliReq, int $gun, string $t1, string $t2): array
    {
        $select = '0 AS gecemax, 0 AS sezongece,';
        $cross = '';
        $where = '';

        if (!$hasDate) {
            return [$select, $cross, $where];
        }

        if ($takvimKuraliReq === '1') {
            $select = '0 AS gecemax, sezon.gece AS sezongece,';
            $cross = "CROSS APPLY (
                SELECT TOP 1 ISNULL(TRY_CONVERT(decimal(18, 6), NULLIF(CONVERT(varchar(64), gece), '')), 0) AS gece,
                ISNULL((SELECT DATEDIFF(day, MAX(CONVERT(date, dd.tarih2, 104)), CONVERT(date, '{$t1}', 104)) FROM dolu dd WHERE dd.emlak = h.id AND dd.durum = 3 AND CONVERT(date, dd.tarih2, 104) <= CONVERT(date, '{$t1}', 104)), ISNULL(TRY_CONVERT(decimal(18, 6), NULLIF(CONVERT(varchar(64), gece), '')), 0)) AS girisara,
                ISNULL((SELECT DATEDIFF(day, CONVERT(date, '{$t2}', 104), MIN(CONVERT(date, dd.tarih, 104))) FROM dolu dd WHERE dd.emlak = h.id AND dd.durum = 3 AND CONVERT(date, dd.tarih, 104) >= CONVERT(date, '{$t2}', 104)), ISNULL(TRY_CONVERT(decimal(18, 6), NULLIF(CONVERT(varchar(64), gece), '')), 0)) AS cikisara
                FROM sezonlar
                WHERE site = 1 AND islem = 'emlak' AND islem_id = h.id AND LEN(tarih2) = 10
                  AND CONVERT(date, '{$t1}', 104) >= CONVERT(date, sezonlar.tarih1, 104)
                  AND CONVERT(date, '{$t1}', 104) <= CONVERT(date, sezonlar.tarih2, 104)
            ) AS sezon ";
            $where = " AND (sezon.girisara >= sezon.gece OR sezon.girisara = 0)
                       AND (sezon.cikisara >= sezon.gece OR sezon.cikisara = 0)
                       AND DATEDIFF(day, CONVERT(date, '{$t1}', 104), CONVERT(date, '{$t2}', 104)) >= sezon.gece ";
        } elseif ($gun !== 0) {
            $cross = "CROSS APPLY (
                SELECT TOP 1 ISNULL(TRY_CONVERT(decimal(18, 6), NULLIF(CONVERT(varchar(64), gece), '')), 0) AS gece
                FROM sezonlar
                WHERE site = 1 AND islem = 'emlak' AND islem_id = h.id AND LEN(tarih2) = 10
                  AND CONVERT(date, '{$t1}', 104) >= CONVERT(date, sezonlar.tarih1, 104)
                  AND CONVERT(date, '{$t1}', 104) <= CONVERT(date, sezonlar.tarih2, 104)
            ) AS sezon ";
            $select = " sezon.gece, (CASE WHEN DATEDIFF(day, CONVERT(date, '{$t1}', 104), CONVERT(date, '{$t2}', 104)) >= sezon.gece THEN 0 ELSE 1 END) AS gecemax, sezon.gece AS sezongece, ";
        }

        return [$select, $cross, $where];
    }

    private function fiyatParcasi(bool $hasDate, string $netTarih, bool $kisasureli, int $siteId, string $t1, string $t2): string
    {
        if (!$hasDate) {
            return 'SELECT ISNULL((SELECT TOP 1 fiyat FROM sezonlar WHERE islem_id = h.id AND site = 1 ORDER BY fiyat ASC), 1) AS sqfiyat';
        }
        if ($netTarih === '0') {
            return 'SELECT nettarih.fiyat AS sqfiyat';
        }
        if ($kisasureli) {
            return "SELECT dbo.Fn_yenifiyathesapla(kisasureli.tarih, kisasureli.tarih2, h.id, {$siteId}) AS sqfiyat";
        }

        return "SELECT dbo.Fn_yenifiyathesapla(CONVERT(date, '{$t1}', 104), CONVERT(date, '{$t2}', 104), h.id, 1) AS sqfiyat";
    }

    /**
     * @return array{0:string,1:string}
     */
    private function kisasureliParts(bool $hasDate, bool $kisasureli, string $geceSql): array
    {
        if (!($hasDate && $kisasureli)) {
            return ['', ''];
        }

        $ksqlx = 'CONVERT(varchar, kisasureli.tarih, 104) AS tarih1, CONVERT(varchar, kisasureli.tarih2, 104) AS tarih2,';
        $ksql = "CROSS APPLY (
            SELECT TOP 1
                tarih2 AS tarih,
                DATEADD(DAY, DATEDIFF(DAY, tarih2,
                    (SELECT MIN(d2.tarih) FROM dolu d2 WHERE d2.emlak = dolu.emlak AND d2.durum = 3 AND d2.tarih >= dolu.tarih2)
                ), tarih2) AS tarih2
            FROM dolu
            WHERE emlak = h.id AND durum = 3
              AND DATEDIFF(DAY, tarih2,
                    (SELECT MIN(d2.tarih) FROM dolu d2 WHERE d2.emlak = dolu.emlak AND d2.durum = 3 AND d2.tarih >= dolu.tarih2)
                  ) IN ({$geceSql})
              AND CONVERT(date, tarih, 103) >= CONVERT(date, GETDATE(), 103)
              AND CONVERT(date, tarih2, 103) >= CONVERT(date, GETDATE(), 103)
        ) AS kisasureli";

        return [$ksqlx, $ksql];
    }

    private function nettarihParcasi(bool $hasDate, string $netTarih, int $minTL, int $maxTL, int $season, int $currentYear, int $siteId): string
    {
        if (!($hasDate && $netTarih === '0')) {
            return '';
        }
        $maxForNet = $maxTL > 0 ? $maxTL : 99999999;

        return "CROSS APPLY (
            SELECT TOP 1 * FROM dbo.musaitlik({$season}, {$season}, {$currentYear}, h.id, {$siteId})
            WHERE TRY_CONVERT(decimal(18, 6), NULLIF(CONVERT(varchar(64), fiyat), '')) * TRY_CONVERT(decimal(18, 6), NULLIF(CONVERT(varchar(64), rate.rate), '')) BETWEEN {$minTL} AND {$maxForNet}
              AND (SELECT TOP 1 COUNT(dolu.id) FROM dolu
                  WHERE dolu.emlak = h.id AND dolu.durum = 3
                    AND ((DATEADD(day, -1, tarih2) BETWEEN dolu.tarih AND dolu.tarih2)
                      OR (DATEADD(day, +1, tarih1) BETWEEN dolu.tarih AND dolu.tarih2)
                      OR (dolu.tarih BETWEEN DATEADD(day, 1, tarih1) AND DATEADD(day, -1, tarih2))
                      OR (dolu.tarih2 BETWEEN DATEADD(day, 1, tarih1) AND DATEADD(day, -1, tarih2)))
              ) = 0
        ) AS nettarih";
    }

    private function orderClause(int $orderBy, string $orderByEk, bool $isFilterSelected): string
    {
        if ($isFilterSelected) {
            $gelismis = "(CASE WHEN h.sadece_bizde = 1 AND bosluklar.girisbosluk = 0 AND bosluklar.cikisbosluk = 0 THEN 1 ELSE 0 END) DESC, "
                . "(CASE WHEN h.sadece_bizde = 1 AND ((bosluklar.girisbosluk = 0 AND mm.val <= bosluklar.cikisbosluk) OR (bosluklar.cikisbosluk = 0 AND mm.val <= bosluklar.girisbosluk)) THEN 1 ELSE 0 END) DESC, ";
        } else {
            $gelismis = '';
        }

        switch ($orderBy) {
            case 1:
                $clause = $orderByEk . $gelismis . ' h.id ASC';
                break;
            case 2:
                $clause = $orderByEk . $gelismis . ' h.id DESC';
                break;
            case 3:
                $clause = $orderByEk . $gelismis . ' fiyatlar_num.sqfiyat * rate_num.rate ASC';
                break;
            case 4:
                $clause = $orderByEk . $gelismis . ' fiyatlar_num.sqfiyat * rate_num.rate DESC';
                break;
            case 5:
                $clause = $gelismis . ' h.kisi ASC, h.siralama ASC';
                break;
            case 6:
                $clause = $gelismis . ' h.kisi DESC, h.siralama ASC';
                break;
            default:
                $clause = ' h.kisi ASC, ' . $gelismis . ' h.siralama ASC, h.id ASC';
                break;
        }

        if (!preg_match('/h\.id (ASC|DESC)\s*$/i', $clause)) {
            $clause .= ', h.id ASC';
        }

        return $clause;
    }

    // ── İstek yardımcıları ───────────────────────────────────────────────────────

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
     * tip=7&tip=12 gibi tekrarlı parametreleri virgülle birleştirir.
     */
    private function multiQuery(string $key): string
    {
        $values = [];
        $raw = $_SERVER['QUERY_STRING'] ?? '';
        if ($raw !== '') {
            foreach (explode('&', $raw) as $pair) {
                $kv = explode('=', $pair);
                if (count($kv) >= 2 && urldecode($kv[0]) === $key) {
                    $val = trim(urldecode($kv[1]));
                    if ($val !== '') {
                        $values[] = $val;
                    }
                }
            }
        }

        return $values ? implode(',', $values) : '';
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

    private function d104(?DateTime $d): string
    {
        return $d ? $d->format('d.m.Y') : '';
    }

    private function dISO(?DateTime $d): string
    {
        return $d ? $d->format('Y-m-d') : '';
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
