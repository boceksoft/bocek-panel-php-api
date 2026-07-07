<?php

declare(strict_types=1);

namespace App\Controller;

use PDO;

/*
 * Rezervasyon (kayıtlar) kaynağı + ciro özeti.
 *   GET|POST /backend-api/reservations?page=1&per_page=20&durum=3&...
 * ASP json-ajax/ajax.asp?islem=kayitlar mantığının taşınmış hâlidir.
 * (Eski get-reservations.php taşınmış hâli.)
 */
final class ReservationsController extends Controller
{
    /**
     * @Get
     * @Post
     * @query page int Sayfa numarası
     * @query per_page int Sayfa başına kayıt (0 veya "tumu" => tümü)
     * @query durum int Rezervasyon durumu (çoklu olabilir)
     * @query musteri string Müşteri adı/kelime araması
     */
    public function index(): void
    {
        $pdo = $this->db->pdo();

        // Sayfalama
        $page       = max(1, $this->pInt('page', $this->pInt('s', 1)));
        $perPageRaw = $this->p('per_page', '20');
        $allRows    = ($perPageRaw === '0' || strtolower($perPageRaw) === 'tumu' || $this->p('tumu') === 'tumu');
        $perPage    = $allRows ? 0 : max(1, (int) $perPageRaw);
        $offset     = $allRows ? 0 : (($page - 1) * $perPage);

        // Filtreler
        $promosyon   = $this->p('promosyon');
        $rezTarih1   = $this->parseTrDate($this->p('rezTarih1'));
        $rezTarih2   = $this->parseTrDate($this->p('rezTarih2'));
        $cikisTarih1 = $this->parseTrDate($this->p('cikisTarih1'));
        $cikisTarih2 = $this->parseTrDate($this->p('cikisTarih2'));

        $rezDurumuRaw     = $this->p('rezDurumu');
        $durumRaw         = $this->p('durum');
        $rezDurumu        = $this->safeIntList($rezDurumuRaw);
        $durum            = $this->safeIntList($durumRaw);
        $combinedDurumRaw = trim($durumRaw . ',' . $rezDurumuRaw, ',');
        $hasDurum6        = strpos(',' . str_replace(' ', '', $combinedDurumRaw) . ',', ',6,') !== false;

        $girisTarih1P = $this->p('girisTarih1');
        if (!$hasDurum6 && $girisTarih1P === '') {
            $girisTarih1P = date('d.m.Y');
        }
        $girisTarih1 = $this->parseTrDate($girisTarih1P);
        $girisTarih2 = $this->parseTrDate($this->p('girisTarih2'));

        $musteriAra = $this->p('musteri') !== '' ? $this->p('musteri') : $this->p('kelime');
        $evkodu     = $this->safeIntList($this->p('evkodu'));
        $odemesekli = $this->safeIntList($this->p('odemesekli'));
        $odemeturu  = $this->safeIntList($this->p('odemeturu'));
        $acenta     = $this->safeIntList($this->p('acenta'));
        $rqsite     = $this->safeIntList($this->p('rqsite'));
        $site       = $this->safeIntList($this->p('site'));

        $kisibilgileri = $this->p('kisibilgileri');
        if ($kisibilgileri === '1') {
            $kisibilgileri = '>';
        }
        if ($kisibilgileri === '2') {
            $kisibilgileri = '=';
        }

        $gavelkurali = $this->p('gavelkurali');

        // WHERE koşulları
        $where  = [];
        $params = [];

        $where[] = 'kayitlar.id NOT IN (45285,32729,29108)';

        if ($rqsite !== '') {
            $where[] = "kayitlar.site IN ($rqsite)";
        } elseif ($site !== '') {
            $where[] = "kayitlar.site IN ($site)";
        }

        if ($evkodu !== '') {
            $where[] = "kayitlar.evid IN ($evkodu)";
        }

        if ($rezTarih1) {
            $where[] = 'CONVERT(date, kayitlar.islem_tarihi) >= CONVERT(date, :rezTarih1)';
            $params[':rezTarih1'] = $rezTarih1;
        }
        if ($rezTarih2) {
            $where[] = 'CONVERT(date, kayitlar.islem_tarihi) <= CONVERT(date, :rezTarih2)';
            $params[':rezTarih2'] = $rezTarih2;
        }

        if ($girisTarih1) {
            $where[] = 'CONVERT(date, dolu.tarih) >= CONVERT(date, :girisTarih1)';
            $params[':girisTarih1'] = $girisTarih1;
        }
        if ($girisTarih2) {
            $where[] = 'CONVERT(date, dolu.tarih) <= CONVERT(date, :girisTarih2)';
            $params[':girisTarih2'] = $girisTarih2;
        }
        if ($cikisTarih1) {
            $where[] = 'CONVERT(date, dolu.tarih2) >= CONVERT(date, :cikisTarih1)';
            $params[':cikisTarih1'] = $cikisTarih1;
        }
        if ($cikisTarih2) {
            $where[] = 'CONVERT(date, dolu.tarih2) <= CONVERT(date, :cikisTarih2)';
            $params[':cikisTarih2'] = $cikisTarih2;
        }

        if ($musteriAra !== '') {
            $where[] = '(kayitlar.musteri LIKE :musteri OR CONVERT(nvarchar, kayitlar.id) = :musteriExact)';
            $params[':musteri']      = '%' . $musteriAra . '%';
            $params[':musteriExact'] = $musteriAra;
        }

        if ($acenta !== '') {
            $where[] = "kayitlar.satis_kanallari_id IN ($acenta)";
        }
        if ($rezDurumu !== '') {
            $where[] = "dolu.durum IN ($rezDurumu)";
        }
        if ($durum !== '') {
            $where[] = "dolu.durum IN ($durum)";
        }
        if ($promosyon !== '') {
            $where[] = 'kayitlar.promotionCode = :promosyon';
            $params[':promosyon'] = $promosyon;
        }
        if ($odemesekli !== '') {
            $where[] = "kayitlar.odeme IN ($odemesekli)";
        }
        if ($odemeturu !== '') {
            $where[] = "kayitlar.tur IN ($odemeturu)";
        }
        if ($kisibilgileri === '>' || $kisibilgileri === '=') {
            $where[] = "kisi.total {$kisibilgileri} 0";
        }
        if ($gavelkurali === '1') {
            $where[] = 'kanun7464.belgeSuresitipi = 2';
        } elseif ($gavelkurali === '2') {
            $where[] = 'kanun7464.belgeSuresitipi = 1';
        } elseif ($gavelkurali === '3') {
            $where[] = 'ISNULL(kanun7464.gavel, 0) = 1';
        }

        $whereSql = $where ? ('WHERE 1=1 AND ' . implode(' AND ', $where)) : 'WHERE 1=1';

        // ASP birebir: sadece durum=3 (Onaylı) seçiliyse giriş tarihine göre ASC,
        // aksi halde en yeni kayıt üstte (id DESC).
        $durumValues = array_filter(
            array_map('trim', explode(',', $durumRaw . ',' . $rezDurumuRaw)),
            function ($v) {
                return $v !== '';
            }
        );
        $onlyDurum3 = count($durumValues) > 0 && count(array_diff($durumValues, ['3'])) === 0;
        $orderSql = $onlyDurum3 ? 'ORDER BY dolu.tarih ASC' : 'ORDER BY kayitlar.id DESC';

        $sql = $this->buildSql($whereSql, $orderSql, $allRows);

        // Çalıştır
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        if (!$allRows) {
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->bindValue(':perPage', $perPage, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Ciro toplamları (window fonksiyonlarından ilk satırda gelir)
        $totalCount = $toplamTutar = $toplamOnOdeme = $toplamKalan = $toplamKar = 0;
        if (count($rows) > 0) {
            $totalCount    = (int) $rows[0]['totalCount'];
            $toplamTutar   = (float) $rows[0]['sumTutar'];
            $toplamOnOdeme = (float) $rows[0]['sumOnOdeme'];
            $toplamKalan   = (float) $rows[0]['sumKalan'];
            $toplamKar     = (float) $rows[0]['sumKar'];
        }

        $rows = $this->normalizeRows($rows);

        $this->response->success([
            'items' => $rows,
            'meta' => [
                'total'       => $totalCount,
                'page'        => $allRows ? 1 : $page,
                'per_page'    => $allRows ? $totalCount : $perPage,
                'total_pages' => $allRows ? 1 : (int) ceil($totalCount / max(1, $perPage)),
                'count'       => count($rows),
            ],
            'ciro' => [
                'toplamTutar'   => round($toplamTutar, 2),
                'toplamOnOdeme' => round($toplamOnOdeme, 2),
                'toplamKalan'   => round($toplamKalan, 2),
                'toplamKar'     => round($toplamKar, 2),
            ],
            'ciro_formatted' => [
                'toplamTutar'   => $this->trMoney($toplamTutar),
                'toplamOnOdeme' => $this->trMoney($toplamOnOdeme),
                'toplamKalan'   => $this->trMoney($toplamKalan),
                'toplamKar'     => $this->trMoney($toplamKar),
            ],
        ]);
    }

    private function buildSql(string $whereSql, string $orderSql, bool $allRows): string
    {
        $sql = "
SELECT
    COUNT(*) OVER() AS totalCount,
    SUM(ISNULL(TRY_CONVERT(float, kayitlar.toplam_tutar), 0) * (CASE WHEN kayitlar.doviz = 'tl' THEN 1 ELSE ISNULL(kayitlar.kur, 1) END)) OVER() AS sumTutar,
    SUM(ISNULL(TRY_CONVERT(float, CASE WHEN kayitlar.tur = 2 THEN kayitlar.toplam_tutar ELSE kayitlar.on_odeme END), 0) * (CASE WHEN kayitlar.doviz = 'tl' THEN 1 ELSE ISNULL(kayitlar.kur, 1) END)) OVER() AS sumOnOdeme,
    SUM(ISNULL(TRY_CONVERT(float, CASE WHEN kayitlar.tur = 2 THEN '0' ELSE kayitlar.kalan END), 0) * (CASE WHEN kayitlar.doviz = 'tl' THEN 1 ELSE ISNULL(kayitlar.kur, 1) END)) OVER() AS sumKalan,
    SUM((ISNULL(TRY_CONVERT(float, kayitlar.toplam_tutar), 0) * (CASE WHEN kayitlar.doviz = 'tl' THEN 1 ELSE ISNULL(kayitlar.kur, 1) END)) / 100 * ISNULL(kayitlar.kazancorani, 0)) OVER() AS sumKar,
    kayitlar.id, kayitlar.site, kayitlar.evid,
    homes.id AS home_id, homes.baslik AS villa_adi, homes.url AS villa_url, homes.kazancorani AS kazancorani_homes,
    kayitlar.musteri AS musteri_adi,
    REPLACE(ISNULL(kayitlar.telefon, ''), ' ', '') AS telefon,
    kayitlar.adi AS silinen_emlak_adi,
    CONVERT(varchar(10), kayitlar.rez_tarihi, 104) AS giris_tarihi,
    CONVERT(varchar(10), kayitlar.gelecek_tarih, 104) AS cikis_tarihi,
    CONVERT(varchar(10), dolu.tarih, 104) AS dolu_giris_tarihi,
    CONVERT(varchar(10), dolu.tarih2, 104) AS dolu_cikis_tarihi,
    CONVERT(varchar(10), kayitlar.islem_tarihi, 104) AS islem_tarihi,
    DATEDIFF(day, kayitlar.rez_tarihi, kayitlar.gelecek_tarih) AS gece,
    ISNULL(TRY_CONVERT(float, kayitlar.toplam_tutar), 0) AS toplam_tutar,
    ISNULL(TRY_CONVERT(float, kayitlar.on_odeme), 0) AS on_odeme,
    ISNULL(TRY_CONVERT(float, kayitlar.kalan), 0) AS kalan,
    CASE WHEN kayitlar.tur = 1 THEN ISNULL(TRY_CONVERT(float, kayitlar.on_odeme), 0) WHEN kayitlar.tur = 2 THEN ISNULL(TRY_CONVERT(float, kayitlar.toplam_tutar), 0) ELSE 0 END AS odeme_tutari,
    CASE WHEN kayitlar.tur = 1 THEN ISNULL(TRY_CONVERT(float, kayitlar.kalan), 0) WHEN kayitlar.tur = 2 THEN 0 ELSE 0 END AS kalan_tutar,
    ISNULL(TRY_CONVERT(float, kayitlar.toplam_tutar), 0) * (CASE WHEN kayitlar.doviz = 'tl' THEN 1 ELSE ISNULL(kayitlar.kur, 1) END) AS toplam_tutar_tl,
    ISNULL(TRY_CONVERT(float, CASE WHEN kayitlar.tur = 2 THEN kayitlar.toplam_tutar ELSE kayitlar.on_odeme END), 0) * (CASE WHEN kayitlar.doviz = 'tl' THEN 1 ELSE ISNULL(kayitlar.kur, 1) END) AS odeme_tutari_tl,
    ISNULL(TRY_CONVERT(float, CASE WHEN kayitlar.tur = 2 THEN '0' ELSE kayitlar.kalan END), 0) * (CASE WHEN kayitlar.doviz = 'tl' THEN 1 ELSE ISNULL(kayitlar.kur, 1) END) AS kalan_tutar_tl,
    (ISNULL(TRY_CONVERT(float, kayitlar.toplam_tutar), 0) * (CASE WHEN kayitlar.doviz = 'tl' THEN 1 ELSE ISNULL(kayitlar.kur, 1) END)) / 100 * ISNULL(kayitlar.kazancorani, 0) AS kar_tl,
    kayitlar.odeme AS odeme_sekli,
    CASE kayitlar.odeme WHEN 1 THEN N'Kredi Kartı' WHEN 2 THEN N'Havale' WHEN 3 THEN N'Western Union' WHEN 4 THEN N'Sanal Kart' WHEN 5 THEN N'Sanal Havale' WHEN 6 THEN N'Nakit' ELSE N'-' END AS odeme_sekli_text,
    kayitlar.tur AS odeme_turu,
    CASE kayitlar.tur WHEN 1 THEN N'Ön Ödeme' WHEN 2 THEN N'Tamamı' ELSE N'-' END AS odeme_turu_text,
    dolu.durum,
    CASE dolu.durum WHEN 0 THEN N'Onay Bekliyor' WHEN 1 THEN N'Ödeme Bekliyor' WHEN 2 THEN N'Süre Doldu' WHEN 3 THEN N'Onaylandı' WHEN 4 THEN N'İptal Edildi' WHEN 5 THEN N'Silindi' WHEN 6 THEN N'Açık Rezervasyon' ELSE N'-' END AS durum_text,
    kayitlar.doviz, kayitlar.kur, kayitlar.kazancorani,
    kayitlar.promotionCode AS promosyon,
    kayitlar.satis_kanallari_id AS acenta,
    ISNULL(sk.baslik, N'') AS satiskanali,
    REPLACE(ISNULL(es.tel, ''), ' ', '') AS evsahibitel,
    ISNULL(homes.whatsapp_grup, '') AS whatsapp,
    kayitlar.oznot AS oz_not,
    kisi.total AS kisi_bilgileri_count,
    opsiyonvarmi.total AS opsiyon_count,
    CASE WHEN opsiyonvarmi.total > 0 THEN 1 ELSE 0 END AS has_opsiyon,
    ISNULL(kanun7464.belgeSuresiTipi, 0) AS belgeSuresiTipi,
    ISNULL(kanun7464.gavel, 0) AS gavel,
    CASE WHEN ISNULL(kanun7464.belgeSuresiTipi, 1) = 2 AND ISNULL(kanun7464.gavel, 0) = 0 THEN 1 ELSE 0 END AS ozelden_al
FROM kayitlar
CROSS APPLY (
    SELECT COUNT(kb.id) AS total FROM kisi_bilgileri kb WHERE kb.siparis_kodu = kayitlar.id
) AS kisi
CROSS APPLY (
    SELECT COUNT(ddop.id) AS total FROM dolu ddop
    WHERE ddop.durum IN (1) AND ddop.emlak = kayitlar.evid AND ISNULL(ddop.kayitid, 0) <> kayitlar.id
      AND ((kayitlar.rez_tarihi BETWEEN ddop.tarih AND ddop.tarih2)
        OR (kayitlar.gelecek_tarih BETWEEN ddop.tarih AND ddop.tarih2)
        OR (ddop.tarih BETWEEN kayitlar.rez_tarihi AND kayitlar.gelecek_tarih)
        OR (ddop.tarih2 BETWEEN kayitlar.rez_tarihi AND kayitlar.gelecek_tarih))
) AS opsiyonvarmi
LEFT  JOIN homes ON kayitlar.evid = homes.id
LEFT  JOIN kullanici es ON es.id = homes.evsahibi
LEFT  JOIN kanun7464 ON kanun7464.homeId = homes.id
INNER JOIN dolu ON dolu.kayitid = kayitlar.id
LEFT  JOIN satis_kanallari sk ON sk.id = kayitlar.satis_kanallari_id
$whereSql
$orderSql
";

        if (!$allRows) {
            $sql .= ' OFFSET :offset ROWS FETCH NEXT :perPage ROWS ONLY ';
        }

        return $sql;
    }

    /**
     * Ciro/toplam kolonlarını temizler, para ve int alanlarını normalize eder.
     *
     * @param array<int,array> $rows
     * @return array<int,array>
     */
    private function normalizeRows(array $rows): array
    {
        $moneyFields = [
            'toplam_tutar', 'on_odeme', 'kalan', 'odeme_tutari', 'kalan_tutar',
            'toplam_tutar_tl', 'odeme_tutari_tl', 'kalan_tutar_tl', 'kar_tl',
        ];
        $intFields = [
            'id', 'site', 'evid', 'home_id', 'gece', 'odeme_sekli', 'odeme_turu', 'durum',
            'acenta', 'kisi_bilgileri_count', 'opsiyon_count', 'has_opsiyon',
            'belgeSuresiTipi', 'gavel', 'ozelden_al',
        ];

        foreach ($rows as &$row) {
            unset($row['totalCount'], $row['sumTutar'], $row['sumOnOdeme'], $row['sumKalan'], $row['sumKar']);

            foreach ($moneyFields as $f) {
                if (array_key_exists($f, $row) && $row[$f] !== null && $row[$f] !== '') {
                    $row[$f] = round((float) $row[$f], 2);
                }
            }
            foreach ($intFields as $f) {
                if (array_key_exists($f, $row) && $row[$f] !== null && $row[$f] !== '') {
                    $row[$f] = (int) $row[$f];
                }
            }
        }
        unset($row);

        return $rows;
    }

    // ── İstek yardımcıları (jQuery serialize / çoklu değer desteği) ──────────────

    /**
     * durum=1&durum=3 gibi çoklu parametreleri okur (PHP $_GET son değeri alır).
     *
     * @return string[]
     */
    private function requestValues(string $key): array
    {
        $values = [];

        $scan = function ($query) use ($key, &$values) {
            if (!$query) {
                return;
            }
            foreach (explode('&', $query) as $part) {
                if ($part === '') {
                    continue;
                }
                $pair = explode('=', $part, 2);
                $name = rawurldecode($pair[0] ?? '');
                $val  = rawurldecode($pair[1] ?? '');
                if ($name === $key || $name === $key . '[]') {
                    $values[] = $val;
                }
            }
        };

        $scan($_SERVER['QUERY_STRING'] ?? '');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $ct = $_SERVER['CONTENT_TYPE'] ?? '';
            if (stripos($ct, 'application/x-www-form-urlencoded') !== false) {
                $scan(file_get_contents('php://input'));
            }
        }

        if (!$values) {
            foreach ([$_GET, $_POST] as $src) {
                if (isset($src[$key])) {
                    $values = is_array($src[$key]) ? array_map('strval', $src[$key]) : [(string) $src[$key]];
                    break;
                }
            }
        }

        return $values;
    }

    private function p(string $key, string $default = ''): string
    {
        $values = $this->requestValues($key);
        if (!$values) {
            return $default;
        }
        $values = array_filter(array_map('trim', $values), function ($v) {
            return $v !== '';
        });

        return $values ? implode(',', $values) : $default;
    }

    private function pInt(string $key, int $default = 0): int
    {
        $v = $this->p($key);

        return preg_match('/^-?\d+$/', $v) ? (int) $v : $default;
    }

    private function safeIntList(string $s): string
    {
        if (trim($s) === '') {
            return '';
        }
        $valid = [];
        foreach (explode(',', $s) as $part) {
            $part = trim($part);
            if ($part !== '' && preg_match('/^\d+$/', $part)) {
                $valid[] = (int) $part;
            }
        }

        return $valid ? implode(',', array_values(array_unique($valid))) : '';
    }

    /**
     * @return string|null
     */
    private function parseTrDate(string $s)
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        foreach (['d.m.Y', 'j.n.Y', 'Y-m-d'] as $fmt) {
            $d = \DateTime::createFromFormat($fmt, $s);
            if ($d instanceof \DateTime) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    private function trMoney(float $n): string
    {
        return number_format($n, 2, ',', '.');
    }
}
