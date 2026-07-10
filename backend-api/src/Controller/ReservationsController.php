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
        $useAcentaUsers = !empty($this->app['reservation_filters_use_acenta_users']);

        // Sayfalama
        $page       = max(1, $this->pInt('page', $this->pInt('s', 1)));
        $perPageRaw = $this->p('per_page', '20');
        $allRows    = ($perPageRaw === '0' || strtolower($perPageRaw) === 'tumu' || $this->p('tumu') === 'tumu');
        $perPage    = $allRows ? 0 : max(1, (int) $perPageRaw);
        $offset     = $allRows ? 0 : (($page - 1) * $perPage);

        // Filtreler
        $promosyon  = $this->p('promosyon');
        $musteriAra = $this->p('musteri') !== '' ? $this->p('musteri') : $this->p('kelime');

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

        // 'emlak' = ASP form adı, 'evkodu' = alternatif
        $evkodu        = $this->safeIntList($this->p('emlak') !== '' ? $this->p('emlak') : $this->p('evkodu'));
        $odemesekli    = $this->safeIntList($this->p('odemesekli'));
        $odemeturu     = $this->safeIntList($this->p('odemeturu'));
        $acentaRaw     = $this->p('acenta');
        $acentaFilter  = $this->parseAcentaFilter($acentaRaw);
        $acenta        = $acentaFilter[0];
        $acentaSites   = $acentaFilter[1];
        $altacenta     = $useAcentaUsers ? $this->p('altacenta') : ''; // acenta_users.id
        $altacentaInt  = $useAcentaUsers && preg_match('/^\d+$/', trim($altacenta)) ? (int) trim($altacenta) : 0;
        $acentaid      = $useAcentaUsers ? $this->safeIntList($this->p('acentaid')) : '';
        $rqsite        = $this->safeIntList($this->p('rqsite'));
        $site          = $this->safeIntList($this->p('site'));

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
        } elseif ($acentaSites !== '') {
            $where[] = "kayitlar.site IN ($acentaSites)";
        } elseif ($site !== '') {
            $where[] = "kayitlar.site IN ($site)";
        }

        if ($evkodu !== '') {
            $where[] = "kayitlar.evid IN ($evkodu)";
        }

        if ($rezTarih1) {
            $where[] = 'CONVERT(date, kayitlar.islem_tarihi, 103) >= CONVERT(date, :rezTarih1, 104)';
            $params[':rezTarih1'] = $rezTarih1;
        }
        if ($rezTarih2) {
            $where[] = 'CONVERT(date, kayitlar.islem_tarihi, 103) <= CONVERT(date, :rezTarih2, 104)';
            $params[':rezTarih2'] = $rezTarih2;
        }

        if ($girisTarih1) {
            $where[] = 'CONVERT(date, dolu.tarih, 103) >= CONVERT(date, :girisTarih1, 104)';
            $params[':girisTarih1'] = $girisTarih1;
        }
        if ($girisTarih2) {
            $where[] = 'CONVERT(date, dolu.tarih, 103) <= CONVERT(date, :girisTarih2, 104)';
            $params[':girisTarih2'] = $girisTarih2;
        }
        if ($cikisTarih1) {
            $where[] = 'CONVERT(date, dolu.tarih2, 103) >= CONVERT(date, :cikisTarih1, 104)';
            $params[':cikisTarih1'] = $cikisTarih1;
        }
        if ($cikisTarih2) {
            $where[] = 'CONVERT(date, dolu.tarih2, 103) <= CONVERT(date, :cikisTarih2, 104)';
            $params[':cikisTarih2'] = $cikisTarih2;
        }

        if ($musteriAra !== '') {
            $where[] = '(kayitlar.musteri LIKE :musteriAdi OR CONVERT(nvarchar, kayitlar.id) = :musteriExact OR homes.baslik LIKE :musteriVilla OR kayitlar.ip = :musteriIp)';
            $params[':musteriAdi']   = '%' . $musteriAra . '%';
            $params[':musteriExact'] = $musteriAra;
            $params[':musteriVilla'] = '%' . $musteriAra . '%';
            $params[':musteriIp']    = $musteriAra;
        }


        if ($acenta !== '') {
            $where[] = "kayitlar.satis_kanallari_id IN ($acenta)";
        }
        if ($useAcentaUsers && $acentaid !== '') {
            $where[] = "au.id IN ($acentaid)";
        }
        // altacenta — acenta_users tablosundaki alt acenta kullanıcısı
        if ($useAcentaUsers && $altacentaInt > 0) {
            $where[] = 'au.id = :altacenta';
            $params[':altacenta'] = $altacentaInt;
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

        $orderSql = 'ORDER BY kayitlar.id DESC';

        $sql = $this->buildSql($whereSql, $orderSql, $allRows, $useAcentaUsers);

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
        $totalCount     = 0;
        $toplamTutar    = 0.0;
        $toplamOnOdeme  = 0.0;
        $toplamKalan    = 0.0;
        $toplamKar      = 0.0;
        $toplamMaliyet  = 0.0;
        $toplamTemizlik = 0.0;

        if (count($rows) > 0) {
            $totalCount     = (int)   $rows[0]['totalCount'];
            $toplamTutar    = (float) $rows[0]['sumTutar'];
            $toplamOnOdeme  = (float) $rows[0]['sumOnOdeme'];
            $toplamKalan    = (float) $rows[0]['sumKalan'];
            $toplamKar      = (float) $rows[0]['sumKar'];
            $toplamMaliyet  = (float) $rows[0]['sumMaliyet'];
            $toplamTemizlik = (float) $rows[0]['sumTemizlik'];
        }

        $rows = $this->normalizeRows($rows);

        $this->response->success([
            'status'      => 'success',
            'total'       => $totalCount,
            'page'        => $allRows ? 1 : $page,
            'per_page'    => $allRows ? $totalCount : $perPage,
            'total_pages' => $allRows ? 1 : (int) ceil($totalCount / max(1, $perPage)),
            'count'       => count($rows),

            'ciro_bilgileri' => [
                'toplamTutar'    => round($toplamTutar,    2),
                'toplamOnOdeme'  => round($toplamOnOdeme,  2),
                'toplamKalan'    => round($toplamKalan,    2),
                'toplamKar'      => round($toplamKar,      2),
                'toplamMaliyet'  => round($toplamMaliyet,  2),
                'toplamTemizlik' => round($toplamTemizlik, 2),
            ],

            'ciro_bilgileri_formatted' => [
                'toplamTutar'    => $this->trMoney($toplamTutar),
                'toplamOnOdeme'  => $this->trMoney($toplamOnOdeme),
                'toplamKalan'    => $this->trMoney($toplamKalan),
                'toplamKar'      => $this->trMoney($toplamKar),
                'toplamMaliyet'  => $this->trMoney($toplamMaliyet),
                'toplamTemizlik' => $this->trMoney($toplamTemizlik),
            ],

            'data' => $rows,
        ]);
    }

    private function buildSql(string $whereSql, string $orderSql, bool $allRows, bool $useAcentaUsers): string
    {
        $acentaSelectSql = $useAcentaUsers
            ? "
    a.kayitId AS acenta_rez_no,
    kayitlar.adi AS acenta_villa_adi,
    CONVERT(varchar(10), a.islem_tarihi, 104) AS acenta_rez_tarihi,
    tutar.toplam_tutar AS acenta_rez_toplam_tutar,
    tutar.on_odeme AS acenta_rez_komisyon,
    dolu.durum AS acenta_rez_durum,
    au.id AS acenta_user_id,
    au.agencyName AS acenta_user_name,"
            : "
    CAST(NULL AS int) AS acenta_rez_no,
    CAST(NULL AS nvarchar(255)) AS acenta_villa_adi,
    CAST(NULL AS varchar(10)) AS acenta_rez_tarihi,
    CAST(NULL AS float) AS acenta_rez_toplam_tutar,
    CAST(NULL AS float) AS acenta_rez_komisyon,
    CAST(NULL AS int) AS acenta_rez_durum,
    CAST(NULL AS int) AS acenta_user_id,
    CAST(NULL AS nvarchar(255)) AS acenta_user_name,";

        $acentaJoinSql = $useAcentaUsers
            ? "
LEFT  JOIN acenta_kayitlar a ON kayitlar.id = a.kayitId
LEFT  JOIN acenta_users au ON au.id = a.acentaId"
            : '';

        $sql = "
SELECT
    COUNT(*) OVER() AS totalCount,
    SUM(tutar.toplam_tutar * tutar.kur_carpan) OVER() AS sumTutar,
    SUM((CASE WHEN kayitlar.tur = 2 THEN tutar.toplam_tutar ELSE tutar.on_odeme END) * tutar.kur_carpan) OVER() AS sumOnOdeme,
    SUM((CASE WHEN kayitlar.tur = 2 THEN 0 ELSE tutar.kalan END) * tutar.kur_carpan) OVER() AS sumKalan,
    SUM(
        CASE
            WHEN tutar.kar = 0 THEN
                (tutar.toplam_tutar * tutar.kur_carpan) / 100 * ISNULL(TRY_CONVERT(float, kayitlar.kazancorani), 0)
            ELSE
                tutar.kar * tutar.kur_carpan
        END
    ) OVER() AS sumKar,
    SUM(
        CASE
            WHEN tutar.maliyet = 0 THEN
                (tutar.toplam_tutar * tutar.kur_carpan)
                -
                (
                    CASE
                        WHEN tutar.kar = 0 THEN
                            (tutar.toplam_tutar * tutar.kur_carpan) / 100 * ISNULL(TRY_CONVERT(float, kayitlar.kazancorani), 0)
                        ELSE
                            tutar.kar * tutar.kur_carpan
                    END
                )
            ELSE
                tutar.maliyet * tutar.kur_carpan
        END
    ) OVER() AS sumMaliyet,
    SUM(tutar.temizlik * tutar.kur_carpan) OVER() AS sumTemizlik,
    kayitlar.id, kayitlar.site, kayitlar.evid,
    homes.id AS home_id, homes.baslik AS villa_adi, homes.baslik_s3 AS villa_adi_s3, homes.url AS villa_url,
    homes.kazancorani AS kazancorani_homes, homes.depozito AS ev_depozito,
    kayitlar.musteri AS musteri_adi,
    kayitlar.email AS email,
    ISNULL(kayitlar.ulkekodu, '') AS ulke_kodu,
    REPLACE(ISNULL(kayitlar.telefon, ''), ' ', '') AS telefon,
    LTRIM(RTRIM(CONCAT(ISNULL('+' + kayitlar.ulkekodu, ''), ' ', ISNULL(kayitlar.telefon, '')))) AS telefon_full,
    kayitlar.adi AS silinen_emlak_adi,
    ISNULL(gorevli, '') AS gorevli,
    CONVERT(varchar(10), kayitlar.rez_tarihi, 104) AS giris_tarihi,
    CONVERT(varchar(10), kayitlar.gelecek_tarih, 104) AS cikis_tarihi,
    CONVERT(varchar(10), dolu.tarih, 104) AS dolu_giris_tarihi,
    CONVERT(varchar(10), dolu.tarih2, 104) AS dolu_cikis_tarihi,
    CONVERT(varchar(10), kayitlar.islem_tarihi, 104) AS islem_tarihi,
    DATEDIFF(day, kayitlar.rez_tarihi, kayitlar.gelecek_tarih) AS gece,
    tutar.toplam_tutar AS toplam_tutar,
    tutar.on_odeme AS on_odeme,
    tutar.kalan AS kalan,
    tutar.maliyet AS maliyet,
    tutar.temizlik AS temizlik,
    CASE WHEN kayitlar.tur = 1 THEN tutar.on_odeme WHEN kayitlar.tur = 2 THEN tutar.toplam_tutar ELSE 0 END AS odeme_tutari,
    CASE WHEN kayitlar.tur = 1 THEN tutar.kalan WHEN kayitlar.tur = 2 THEN 0 ELSE 0 END AS kalan_tutar,
    tutar.toplam_tutar * tutar.kur_carpan AS toplam_tutar_tl,
    (CASE WHEN kayitlar.tur = 2 THEN tutar.toplam_tutar ELSE tutar.on_odeme END) * tutar.kur_carpan AS odeme_tutari_tl,
    (CASE WHEN kayitlar.tur = 2 THEN 0 ELSE tutar.kalan END) * tutar.kur_carpan AS kalan_tutar_tl,
    CASE
        WHEN tutar.kar = 0 THEN
            (tutar.toplam_tutar * tutar.kur_carpan) / 100 * ISNULL(TRY_CONVERT(float, kayitlar.kazancorani), 0)
        ELSE
            tutar.kar * tutar.kur_carpan
    END AS kar_tl,
    CASE
        WHEN tutar.maliyet = 0 THEN
            (tutar.toplam_tutar * tutar.kur_carpan)
            -
            (
                CASE
                    WHEN tutar.kar = 0 THEN
                        (tutar.toplam_tutar * tutar.kur_carpan) / 100 * ISNULL(TRY_CONVERT(float, kayitlar.kazancorani), 0)
                    ELSE
                        tutar.kar * tutar.kur_carpan
                END
            )
        ELSE
            tutar.maliyet * tutar.kur_carpan
    END AS maliyet_tl,
    tutar.temizlik * tutar.kur_carpan AS temizlik_tl,
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
$acentaSelectSql
    REPLACE(ISNULL(es.tel, ''), ' ', '') AS evsahibitel,
    ISNULL(homes.whatsapp_grup, '') AS whatsapp,
    kayitlar.oznot AS oz_not,
    kisi.total AS kisi_bilgileri_count,
    yorumsay.total AS yorum_count,
    opsiyonvarmi.total AS opsiyon_count,
    CASE WHEN opsiyonvarmi.total > 0 THEN 1 ELSE 0 END AS has_opsiyon,
    dolu_fake.bizdekitarih AS dolu_fake_count,
    CASE WHEN dolu_fake.bizdekitarih > 0 THEN 1 ELSE 0 END AS has_bizdeki_tarih,
    ISNULL(kayitlar.arandi, 0) AS arandi,
    ISNULL(kayitlar.sozlesme, 0) AS sozlesme,
    ISNULL(kayitlar.gonderildi, 0) AS gonderildi,
    ISNULL(kanun7464.belgeSuresiTipi, 0) AS belgeSuresiTipi,
    ISNULL(kanun7464.gavel, 0) AS gavel,
    CASE WHEN ISNULL(kanun7464.belgeSuresiTipi, 1) = 2 AND ISNULL(kanun7464.gavel, 0) = 0 THEN 1 ELSE 0 END AS ozelden_al
FROM kayitlar
CROSS APPLY (
    SELECT
        ISNULL(TRY_CONVERT(float, dbo.fnTemizle(kayitlar.toplam_tutar)), 0) AS toplam_tutar,
        ISNULL(TRY_CONVERT(float, dbo.fnTemizle(kayitlar.on_odeme)), 0) AS on_odeme,
        ISNULL(TRY_CONVERT(float, dbo.fnTemizle(kayitlar.kalan)), 0) AS kalan,
        ISNULL(TRY_CONVERT(float, dbo.fnTemizle(kayitlar.maliyet)), 0) AS maliyet,
        ISNULL(TRY_CONVERT(float, dbo.fnTemizle(kayitlar.temizlik)), 0) AS temizlik,
        ISNULL(TRY_CONVERT(float, dbo.fnTemizle(kayitlar.kar)), 0) AS kar,
        CASE WHEN kayitlar.doviz = 'tl' THEN 1 ELSE ISNULL(TRY_CONVERT(float, kayitlar.kur), 1) END AS kur_carpan
) AS tutar
CROSS APPLY (
    SELECT COUNT(defter.id) AS total FROM defter WHERE defter.rezid = kayitlar.id
) AS yorumsay
CROSS APPLY (  
    SELECT COUNT(kb.id) AS total FROM kisi_bilgileri kb WHERE kb.siparis_kodu = kayitlar.id
) AS kisi
CROSS APPLY (
    SELECT COUNT(ddop.id) AS total FROM dolu ddop
    WHERE ddop.durum IN (0, 1) AND ddop.emlak = kayitlar.evid AND ISNULL(ddop.kayitid, 0) <> kayitlar.id
      AND ((kayitlar.rez_tarihi BETWEEN ddop.tarih AND ddop.tarih2)
        OR (kayitlar.gelecek_tarih BETWEEN ddop.tarih AND ddop.tarih2)
        OR (ddop.tarih BETWEEN kayitlar.rez_tarihi AND kayitlar.gelecek_tarih)
        OR (ddop.tarih2 BETWEEN kayitlar.rez_tarihi AND kayitlar.gelecek_tarih))
) AS opsiyonvarmi 
OUTER APPLY (
    SELECT COUNT(df.id) AS bizdekitarih  
    FROM dolu_fake df
    WHERE df.emlak = kayitlar.evid
      AND (
            (CONVERT(date, df.tarih, 103) >= CONVERT(date, kayitlar.rez_tarihi, 103)
             AND CONVERT(date, kayitlar.gelecek_tarih, 103) >= CONVERT(date, df.tarih2, 103))
         OR (CONVERT(date, df.tarih, 103) BETWEEN CONVERT(date, kayitlar.gelecek_tarih, 103) AND CONVERT(date, kayitlar.rez_tarihi, 103)
             OR CONVERT(date, df.tarih2, 103) BETWEEN CONVERT(date, kayitlar.gelecek_tarih, 103) AND CONVERT(date, kayitlar.rez_tarihi, 103)
             OR CONVERT(date, kayitlar.rez_tarihi, 103) BETWEEN CONVERT(date, df.tarih, 103) AND CONVERT(date, df.tarih2, 103)
             OR CONVERT(date, kayitlar.gelecek_tarih, 103) BETWEEN CONVERT(date, df.tarih, 103) AND CONVERT(date, df.tarih2, 103))
      )
      AND df.tarih <> kayitlar.gelecek_tarih
      AND df.tarih2 <> kayitlar.rez_tarihi
) AS dolu_fake
INNER JOIN rate ON rate.CurrencyName = ISNULL(kayitlar.doviz, 'tl')
LEFT  JOIN homes ON kayitlar.evid = homes.id
INNER JOIN sites ON sites.id = ISNULL(kayitlar.site, 1)
LEFT  JOIN kullanici es ON es.id = homes.evsahibi
LEFT  JOIN kanun7464 ON kanun7464.homeId = homes.id
INNER JOIN dolu ON dolu.kayitid = kayitlar.id
LEFT  JOIN satis_kanallari sk ON sk.id = kayitlar.satis_kanallari_id
$acentaJoinSql
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
            'maliyet', 'temizlik',
            'toplam_tutar_tl', 'odeme_tutari_tl', 'kalan_tutar_tl', 'kar_tl',
            'maliyet_tl', 'temizlik_tl', 'ev_depozito',
            'acenta_rez_toplam_tutar', 'acenta_rez_komisyon',
        ];
        $intFields = [
            'id', 'site', 'evid', 'home_id', 'gece', 'odeme_sekli', 'odeme_turu', 'durum',
            'acenta', 'kisi_bilgileri_count', 'yorum_count', 'opsiyon_count', 'has_opsiyon',
            'dolu_fake_count', 'has_bizdeki_tarih', 'arandi', 'sozlesme', 'gonderildi',
            'belgeSuresiTipi', 'gavel', 'ozelden_al',
            'acenta_rez_no', 'acenta_rez_durum', 'acenta_user_id',
        ];

        foreach ($rows as &$row) {
            unset(
                $row['totalCount'], $row['sumTutar'], $row['sumOnOdeme'],
                $row['sumKalan'],   $row['sumKar'],
                $row['sumMaliyet'], $row['sumTemizlik']
            );

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

            $this->addTableColumnAliases($row);
        }
        unset($row);

        return $rows;
    }

    /**
     * Filtre endpointindeki table_colons id'leriyle satır alanlarını eşler.
     *
     * @param array<string,mixed> $row
     */
    private function addTableColumnAliases(array &$row): void
    {
        $aliases = [
            'rezNo' => 'id',
            'evadi' => 'villa_adi',
            'musteri' => 'musteri_adi',
            'islemTarihi' => 'islem_tarihi',
            'girisTarihi' => 'giris_tarihi',
            'cikisTarihi' => 'cikis_tarihi',
            'toplamTutar' => 'toplam_tutar',
            'onOdeme' => 'on_odeme',
            'odemeSekli' => 'odeme_sekli_text',
            'acentaRezNo' => 'acenta_rez_no',
            'acentaVillaAdi' => 'acenta_villa_adi',
            'acentaRezTarihi' => 'acenta_rez_tarihi',
            'acentaRezToplamTutar' => 'acenta_rez_toplam_tutar',
            'acentaRezKomisyon' => 'acenta_rez_komisyon',
            'acentaRezDurum' => 'acenta_rez_durum',
            'satis' => 'toplam_tutar_tl',
            'alis' => 'maliyet_tl',
            'kar' => 'kar_tl',
            'odenen' => 'odeme_tutari_tl',
            'doviz' => 'doviz',
            'kur' => 'kur',
        ];

        foreach ($aliases as $alias => $source) {
            if (array_key_exists($source, $row) && !array_key_exists($alias, $row)) {
                $row[$alias] = $row[$source];
            }
        }

        if (!array_key_exists('odenenOran', $row)) {
            $toplam = isset($row['toplam_tutar_tl']) ? (float) $row['toplam_tutar_tl'] : 0.0;
            $odenen = isset($row['odeme_tutari_tl']) ? (float) $row['odeme_tutari_tl'] : 0.0;
            $row['odenenOran'] = $toplam > 0 ? round(($odenen / $toplam) * 100, 2) : 0.0;
        }

        if (!array_key_exists('odemeTarihi', $row)) {
            $row['odemeTarihi'] = null;
        }
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
     * ASP formunda acenta hem satis kanali id'si hem de site_N seklinde gelebiliyor.
     *
     * @return array{0:string,1:string}
     */
    private function parseAcentaFilter(string $s): array
    {
        if (trim($s) === '') {
            return ['', ''];
        }

        $channels = [];
        $sites = [];
        foreach (explode(',', $s) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^site_(\d+)$/', $part, $m)) {
                $sites[] = (int) $m[1];
            } elseif (preg_match('/^\d+$/', $part)) {
                $channels[] = (int) $part;
            }
        }

        $channels = array_values(array_unique($channels));
        $sites = array_values(array_unique($sites));

        return [
            $channels ? implode(',', $channels) : '',
            $sites ? implode(',', $sites) : '',
        ];
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
        foreach (['d.m.Y', 'j.n.Y', 'Y-m-d', 'Y.m.d'] as $fmt) {
            $d = \DateTime::createFromFormat($fmt, $s);
            if ($d instanceof \DateTime) {
                return $d->format('d.m.Y');
            }
        }

        return null;
    }

    private function trMoney(float $n): string
    {
        return number_format($n, 2, ',', '.');
    }
}
