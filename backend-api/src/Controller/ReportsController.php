<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use DateTime;
use PDO;

/*
 * Raporlar kaynağı.
 * Ön yüzdeki rapor sayfalarına (BocekSoftV26 /reports/...) Natsisa Web.Api üzerinden veri sağlar.
 *
 *   GET /backend-api/reports/summary         -> bugünkü satışlar + genel ciro/komisyon/rezervasyon kartları
 *   GET /backend-api/reports/period          -> tarih aralığı raporu (giriş veya işlem tarihine göre)
 *   GET /backend-api/reports/today-completed -> bugün tamamlanan (onaylanan) rezervasyonlar
 *   GET /backend-api/reports/today-cancelled -> bugün iptal edilen / süresi dolan / silinen rezervasyonlar
 *   GET /backend-api/reports/potential       -> günlük talep ve fırsat dağılımı (potansiyel rapor)
 *   GET /backend-api/reports/potential-options -> potansiyel raporu villa/bölge filtreleri
 *   GET /backend-api/reports/demand-periods  -> en çok talep alan dönemler, lead time ve gece dağılımı
 *
 * Panel karşılığı: rapor-yonetimi.asp. Sadece okuma yapar.
 * Silinme kodu siteye özeldir (ayar: reservation_deleted_status); yanıtta her zaman 5 döner.
 */
final class ReportsController extends Controller
{
    /** Tarih aralığı raporunda kullanılan tarih tipleri (panel: dateType). */
    private const DATE_TYPE_CHECK_IN = 0;
    private const DATE_TYPE_TRANSACTION = 1;

    /** kayitlar.odeme değerleri. */
    private const PAYMENT_CREDIT_CARD = 1;
    private const PAYMENT_TRANSFER = 2;

    /** Potansiyel raporunda izin verilen en uzun aralık (gün). */
    private const MAX_POTENTIAL_DAYS = 366;

    /** Lead time dağılımı aralıkları (gün): 0-3, 4-14, 15-45, 46+. Sırası SQL'deki CASE ile aynı. */
    private const LEAD_TIME_BUCKETS = [
        '0 - 3 Gün Kala (Son Dakika / Last-Minute)',
        '4 - 14 Gün Kala (Kısa Vade)',
        '15 - 45 Gün Kala (Standart Sezon Planı)',
        '46+ Gün Kala (Erken Rezervasyon)',
    ];

    /** DateTime::format('w') -> gün adı. */
    private const DAY_NAMES = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];

    /** Durum etiketleri — ReservationStatusController ile aynı. */
    private const STATUS_LABELS = [
        2 => 'Süresi Doldu',
        3 => 'Onaylandı',
        4 => 'İptal Edildi',
        5 => 'Silindi',
    ];

    /**
     * Bugünkü satışlar ile genel ciro, komisyon ve rezervasyon sayılarını döner.
     *
     * @Get("summary")
     */
    public function summary(): void
    {
        $deleted = $this->deletedStatus();
        $sql = "
SELECT
    (SELECT COUNT(k.id)
       FROM kayitlar k
       INNER JOIN dolu d ON d.kayitid = k.id
      WHERE k.islem_tarihi >= t.bugun AND k.islem_tarihi < t.yarin
        AND d.durum IN (0, 1, 3)) AS bugun_rez_adet,
    (SELECT ISNULL(SUM(k.tutar * ISNULL(k.kur, 1)), 0)
       FROM kayitlar k
      WHERE k.son_durum_guncelleme >= t.bugun AND k.son_durum_guncelleme < t.yarin
        AND k.kayit_durum = 3) AS bugun_on_odeme,
    (SELECT ISNULL(SUM(ISNULL(TRY_CONVERT(float, dbo.fnTemizle(k.toplam_tutar)), 0) * ISNULL(k.kur, 1)), 0)
       FROM kayitlar k
       INNER JOIN dolu d ON d.kayitid = k.id
      WHERE d.durum = 3) AS toplam_ciro,
    (SELECT ISNULL(SUM((ISNULL(TRY_CONVERT(float, dbo.fnTemizle(k.toplam_tutar)), 0) / 100 * k.kazancOrani) * ISNULL(k.kur, 1)), 0)
       FROM kayitlar k
       INNER JOIN dolu d ON d.kayitid = k.id
      WHERE d.durum = 3) AS net_komisyon,
    (SELECT COUNT(k.id)
       FROM kayitlar k
       INNER JOIN dolu d ON d.kayitid = k.id
      WHERE d.durum = 3) AS toplam_kesinlesen,
    (SELECT COUNT(k.id)
       FROM kayitlar k
       INNER JOIN dolu d ON d.kayitid = k.id
      WHERE d.durum IN (2, 4, {$deleted})) AS toplam_iptal
FROM (SELECT CAST(GETDATE() AS date) AS bugun, DATEADD(day, 1, CAST(GETDATE() AS date)) AS yarin) AS t";

        $row = $this->fetchOne($sql, []);

        $this->response->success([
            'today' => [
                'reservation_count' => (int) $row['bugun_rez_adet'],
                'prepayment_total' => $this->money($row['bugun_on_odeme']),
            ],
            'overall' => [
                'revenue_total' => $this->money($row['toplam_ciro']),
                'commission_total' => $this->money($row['net_komisyon']),
                'confirmed_count' => (int) $row['toplam_kesinlesen'],
                'cancelled_count' => (int) $row['toplam_iptal'],
            ],
        ]);
    }

    /**
     * Seçilen tarih aralığının satış raporunu döner. Tutarlar TL'ye çevrilmiştir.
     *
     * @Get("period")
     * @query startDate string Başlangıç tarihi (YYYY-MM-DD veya DD.MM.YYYY), varsayılan bugün
     * @query endDate string Bitiş tarihi, dahil (YYYY-MM-DD veya DD.MM.YYYY), varsayılan bugün
     * @query dateType int 0 giriş tarihi, 1 işlem tarihi (varsayılan 1)
     */
    public function period(): void
    {
        $today = (new DateTime('today'))->format('Y-m-d');
        $startDate = $this->parseDate((string) $this->request->query('startDate', ''), $today);
        $endDate = $this->parseDate((string) $this->request->query('endDate', ''), $today);
        if ($endDate < $startDate) {
            throw new HttpException('endDate startDate tarihinden önce olamaz.', 'VALIDATION_ERROR', 422);
        }

        $dateType = (int) $this->request->query('dateType', (string) self::DATE_TYPE_TRANSACTION);
        if (!in_array($dateType, [self::DATE_TYPE_CHECK_IN, self::DATE_TYPE_TRANSACTION], true)) {
            throw new HttpException('dateType 0 (giriş) veya 1 (işlem) olmalı.', 'VALIDATION_ERROR', 422);
        }

        // İşlem tarihine göre: kayıtlar son durum güncellemesine göre süzülür, gelen rezervasyon
        // sayısı ise işlem tarihine göre sayılır. Giriş tarihine göre: ikisi de giriş tarihidir.
        if ($dateType === self::DATE_TYPE_TRANSACTION) {
            $filterColumn = 'k.son_durum_guncelleme';
            $countColumn = 'k.islem_tarihi';
        } else {
            $filterColumn = 'k.rez_tarihi';
            $countColumn = 'k.rez_tarihi';
        }

        $deleted = $this->deletedStatus();
        $tl = "(CASE WHEN k.doviz = 'tl' THEN 1 ELSE k.kur END)";
        $toplamTutar = 'ISNULL(TRY_CONVERT(float, dbo.fnTemizle(k.toplam_tutar)), 0)';
        // Tarihler parseDate'ten yalnızca Y-m-d olarak gelir; panel gibi sabit yazılır. Parametreli halde
        // SQL Server değeri bilmeden plan kurup zaman aşımına düşüyordu.
        $range = $this->rangeLiterals($startDate, $endDate);
        $inRange = "{$countColumn} >= {$range['start']} AND {$countColumn} < {$range['end']}";

        $sql = "
SELECT
    ISNULL(SUM(CASE WHEN k.kayit_durum IN (0, 1, 3) AND {$inRange} THEN 1 ELSE 0 END), 0) AS rez_adet,
    ISNULL(SUM(CASE WHEN {$inRange} THEN 1 ELSE 0 END), 0) AS gelen_rez,
    ISNULL(SUM(CASE WHEN k.kayit_durum = 3 THEN {$toplamTutar} * {$tl} ELSE 0 END), 0) AS toplam_ciro,
    ISNULL(SUM(CASE WHEN k.kayit_durum = 3 THEN k.tutar * {$tl} ELSE 0 END), 0) AS toplam_on_odeme,
    ISNULL(SUM(CASE WHEN k.kayit_durum = {$deleted} THEN 1 ELSE 0 END), 0) AS silinen_rez,
    ISNULL(SUM(CASE WHEN k.kayit_durum = 3 THEN 1 ELSE 0 END), 0) AS kesinlesen_rez,
    ISNULL(SUM(CASE WHEN k.kayit_durum = 4 THEN 1 ELSE 0 END), 0) AS iptal_rez,
    ISNULL(SUM(CASE WHEN k.kayit_durum = 3 THEN ({$toplamTutar} / 100 * k.kazancorani) * {$tl} ELSE 0 END), 0) AS toplam_komisyon,
    ISNULL(SUM(CASE WHEN k.kayit_durum = 1 THEN 1 ELSE 0 END), 0) AS odeme_bekleyen_adet,
    ISNULL(SUM(CASE WHEN k.kayit_durum = 1 THEN k.tutar * {$tl} ELSE 0 END), 0) AS odeme_bekleyen_toplam
FROM kayitlar k
WHERE {$filterColumn} >= {$range['start']} AND {$filterColumn} < {$range['end']}";

        $row = $this->fetchOne($sql, []);

        $confirmed = (int) $row['kesinlesen_rez'];
        $incoming = (int) $row['gelen_rez'];

        $this->response->success([
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dateType' => $dateType,
            'reservation_count' => (int) $row['rez_adet'],
            'revenue_total' => $this->money($row['toplam_ciro']),
            'prepayment_total' => $this->money($row['toplam_on_odeme']),
            'deleted_count' => (int) $row['silinen_rez'],
            'confirmed_count' => $confirmed,
            'conversion_rate' => $incoming > 0 ? (int) round($confirmed * 100 / $incoming) : 0,
            'credit_card_total' => $this->paymentTotal(self::PAYMENT_CREDIT_CARD, $range),
            'transfer_total' => $this->paymentTotal(self::PAYMENT_TRANSFER, $range),
            'cancelled_count' => (int) $row['iptal_rez'],
            'pending_payment_count' => (int) $row['odeme_bekleyen_adet'],
            'pending_payment_total' => $this->money($row['odeme_bekleyen_toplam']),
            'commission_total' => $this->money($row['toplam_komisyon']),
        ]);
    }

    /**
     * Günlük talep ve fırsat dağılımı (potansiyel rapor). Tutarlar rezervasyon kuru ile TL'dir.
     *   Talep/potansiyel: o gün açılan her rezervasyon kaydı (kayitlar.islem_tarihi).
     *   Gerçekleşen/kaçan: o gün durumu değişen kayıtlar (kayitlar.son_durum_guncelleme), panel raporu gibi;
     *   gerçekleşen dolu.durum 3, kaçan 2 (süresi doldu) + 4 (iptal) + silinen. Önceki günlerin talepleri de gelir.
     *
     * @Get("potential")
     * @query startDate string Başlangıç tarihi (YYYY-MM-DD veya DD.MM.YYYY), varsayılan 6 gün önce
     * @query endDate string Bitiş tarihi, dahil (YYYY-MM-DD veya DD.MM.YYYY), varsayılan bugün
     * @query villaIds string Virgülle ayrılmış villa (homes.id) kimlikleri
     * @query regionIds string Virgülle ayrılmış bölge (destinations.id) kimlikleri
     */
    public function potential(): void
    {
        $startDate = $this->parseDate(
            (string) $this->request->query('startDate', ''),
            (new DateTime('today'))->modify('-6 day')->format('Y-m-d')
        );
        $endDate = $this->parseDate((string) $this->request->query('endDate', ''), (new DateTime('today'))->format('Y-m-d'));
        if ($endDate < $startDate) {
            throw new HttpException('endDate startDate tarihinden önce olamaz.', 'VALIDATION_ERROR', 422);
        }
        if ((new DateTime($startDate))->diff(new DateTime($endDate))->days > self::MAX_POTENTIAL_DAYS) {
            throw new HttpException('Tarih aralığı en fazla ' . self::MAX_POTENTIAL_DAYS . ' gün olabilir.', 'VALIDATION_ERROR', 422);
        }

        $villaIds = $this->idList((string) $this->request->query('villaIds', ''));
        $regionIds = $this->idList((string) $this->request->query('regionIds', ''));

        $range = $this->rangeLiterals($startDate, $endDate);
        $deleted = $this->deletedStatus();
        $durum = 'd.durum';
        $lost = "{$durum} IN (2, 4, {$deleted})";
        $tutar = "ISNULL(TRY_CONVERT(float, dbo.fnTemizle(k.toplam_tutar)), 0) * (CASE WHEN k.doviz = 'tl' THEN 1 ELSE ISNULL(k.kur, 1) END)";
        $gece = 'ISNULL(DATEDIFF(day, k.rez_tarihi, k.gelecek_tarih), 0)';

        $where = [];
        $joins = '';
        if ($villaIds) {
            $where[] = 'k.evid IN (' . implode(',', $villaIds) . ')';
        }
        if ($regionIds) {
            // HomesController'daki bölge filtresi: seçilen bölge villanın 3 seviyeli bölge ağacının herhangi biri.
            $joins = '
INNER JOIN homes h ON h.id = k.evid
INNER JOIN destinations d2 ON d2.id = h.emlak_bolgesi
LEFT  JOIN destinations d1 ON d1.id = d2.cat
LEFT  JOIN destinations d0 ON d0.id = d1.cat';
            $where[] = '(d2.id IN (' . implode(',', $regionIds) . ') OR d1.id IN (' . implode(',', $regionIds)
                . ') OR d0.id IN (' . implode(',', $regionIds) . '))';
        }

        $from = "FROM kayitlar k\nINNER JOIN dolu d ON d.kayitid = k.id{$joins}";
        $filter = $where ? "\n  AND " . implode("\n  AND ", $where) : '';

        // Talepler: geldiği güne göre.
        $requestSql = "
SELECT
    CONVERT(varchar(10), CAST(k.islem_tarihi AS date), 23) AS gun,
    COUNT(*) AS talep,
    SUM({$gece}) AS talep_gece,
    SUM({$tutar}) AS potansiyel
{$from}
WHERE k.islem_tarihi >= {$range['start']} AND k.islem_tarihi < {$range['end']}{$filter}
GROUP BY CAST(k.islem_tarihi AS date)";

        // Sonuçlar: onaylandığı / kaçtığı (son durum değişikliği) güne göre.
        $resultSql = "
SELECT
    CONVERT(varchar(10), CAST(k.son_durum_guncelleme AS date), 23) AS gun,
    SUM(CASE WHEN {$durum} = 3 THEN 1 ELSE 0 END) AS gerceklesen,
    SUM(CASE WHEN {$durum} = 3 THEN {$gece} ELSE 0 END) AS gerceklesen_gece,
    SUM(CASE WHEN {$durum} = 3 THEN {$tutar} ELSE 0 END) AS gerceklesen_ciro,
    SUM(CASE WHEN {$lost} THEN 1 ELSE 0 END) AS kacan,
    SUM(CASE WHEN {$lost} THEN {$tutar} ELSE 0 END) AS kacan_ciro,
    SUM(CASE WHEN {$durum} = 2 THEN 1 ELSE 0 END) AS suresi_dolan
{$from}
WHERE k.son_durum_guncelleme >= {$range['start']} AND k.son_durum_guncelleme < {$range['end']}
  AND ({$durum} = 3 OR {$lost}){$filter}
GROUP BY CAST(k.son_durum_guncelleme AS date)";

        $byDay = [];
        foreach ([$requestSql, $resultSql] as $sql) {
            foreach ($this->db->pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = (string) $row['gun'];
                $byDay[$key] = array_merge($byDay[$key] ?? [], $row);
            }
        }

        $ruleViolations = $this->lostShortStayCounts($from, $filter, $range, $lost);

        // Talep gelmeyen günler de tabloda 0 ile görünsün.
        $days = [];
        $cursor = new DateTime($startDate);
        $last = new DateTime($endDate);
        while ($cursor <= $last) {
            $key = $cursor->format('Y-m-d');
            $row = $byDay[$key] ?? [];
            $days[] = [
                'date' => $key,
                'dayName' => self::DAY_NAMES[(int) $cursor->format('w')],
                'totalInquiries' => (int) ($row['talep'] ?? 0),
                'totalNightsRequested' => (int) ($row['talep_gece'] ?? 0),
                'potentialRevenue' => $this->money($row['potansiyel'] ?? 0),
                'confirmedBookings' => (int) ($row['gerceklesen'] ?? 0),
                'confirmedNights' => (int) ($row['gerceklesen_gece'] ?? 0),
                'confirmedRevenue' => $this->money($row['gerceklesen_ciro'] ?? 0),
                'lostInquiries' => (int) ($row['kacan'] ?? 0),
                'lostRevenue' => $this->money($row['kacan_ciro'] ?? 0),
                // Süresi dolan dolu.durum'dan; kurala uymayan CalculateController'ın kısa konaklama kuralından.
                // Diğer nedenler için veri yok.
                'lostReasons' => [
                    'deadDaysGap' => 0,
                    'minStayNotMet' => $ruleViolations[$key] ?? 0,
                    'optionExpired' => (int) ($row['suresi_dolan'] ?? 0),
                    'ruleMismatch' => 0,
                    'priceNegotiation' => 0,
                ],
            ];
            $cursor->modify('+1 day');
        }

        $this->response->success([
            'startDate' => $startDate,
            'endDate' => $endDate,
            'days' => $days,
            'meta' => ['count' => count($days)],
        ]);
    }

    /**
     * Kaçan taleplerden villanın minimum konaklama kuralına uymayanları, kaçtığı güne göre sayar.
     * Kural CalculateController::isShortStay ile aynıdır: sezonun minimum gecesi (sezonlar.gece) 1'den büyük
     * ve talep daha kısaysa kurala uymaz; günlük kiralık villa (CalendarHomes.RoomType = 1), son dakika
     * dönemi ve kisasureli3'ün izin verdiği tam ara boşluk hariç. kisasureli3 takvimin bugünkü haline bakar.
     *
     * @param array{start:string,end:string} $range rangeLiterals() çıktısı
     * @return array<string,int> Y-m-d => adet
     */
    private function lostShortStayCounts(string $from, string $filter, array $range, string $lost): array
    {
        $pdo = $this->db->pdo();
        $sameAcrossSites = !empty($this->app['calculate_prices_same_across_sites']);
        $priceSite = $sameAcrossSites ? '1' : 'k.site';
        $roomTypeSql = $this->objectExists('KiralamaTakvimi.CalendarHomes', 'U')
            ? '(SELECT TOP 1 CONVERT(varchar(10), ch.RoomType) FROM KiralamaTakvimi.CalendarHomes ch WHERE ch.homesId = k.evid)'
            : 'CAST(NULL AS varchar(10))';

        $sql = "
SELECT
    CONVERT(varchar(10), CAST(k.son_durum_guncelleme AS date), 23) AS gun,
    k.evid,
    CONVERT(varchar(10), CAST(k.rez_tarihi AS date), 23) AS giris,
    CONVERT(varchar(10), CAST(k.gelecek_tarih AS date), 23) AS cikis,
    DATEDIFF(day, k.rez_tarihi, k.gelecek_tarih) AS gece,
    ISNULL(sz.gece, 0) AS min_gece,
    {$roomTypeSql} AS room_type,
    CASE WHEN EXISTS (
        SELECT 1
          FROM sonDakika sd
         WHERE sd.site = {$priceSite}
           AND sd.islem_id = k.evid
           AND TRY_CONVERT(date, sd.tarih1, 104) <= CAST(k.rez_tarihi AS date)
           AND TRY_CONVERT(date, sd.tarih2, 104) >= CAST(k.gelecek_tarih AS date)
    ) THEN 1 ELSE 0 END AS son_dakika
{$from}
OUTER APPLY (
    SELECT TOP 1 ISNULL(s.gece, 0) AS gece
      FROM sezonlar s
     WHERE s.site = {$priceSite}
       AND s.islem_id = k.evid
       AND s.islem = 'emlak'
       AND CAST(k.rez_tarihi AS date) BETWEEN TRY_CONVERT(date, s.tarih1, 104) AND TRY_CONVERT(date, s.tarih2, 104)
     ORDER BY s.id DESC
) AS sz
WHERE k.son_durum_guncelleme >= {$range['start']} AND k.son_durum_guncelleme < {$range['end']}
  AND {$lost}{$filter}";

        $hasShortStayFunction = $this->objectExists('dbo.kisasureli3', 'IF') || $this->objectExists('dbo.kisasureli3', 'TF');
        $counts = [];
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $minNight = (int) $row['min_gece'];
            if ((string) $row['room_type'] === '1' || $minNight <= 1 || (int) $row['son_dakika'] === 1
                || (int) $row['gece'] >= $minNight
            ) {
                continue;
            }
            if ($hasShortStayFunction && $this->isAllowedShortStay((int) $row['evid'], (string) $row['giris'], (string) $row['cikis'])) {
                continue;
            }
            $day = (string) $row['gun'];
            $counts[$day] = ($counts[$day] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * kisasureli3: villanın bu tarihlerde kısa konaklamaya izin verdiği boşluk (CalculateController ile aynı çağrı).
     */
    private function isAllowedShortStay(int $homeId, string $start, string $end): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT *
             FROM kisasureli3(:startDate, :endDate, {$homeId}, '1,2,3,4,5,6') AS kisasureli
             WHERE tarih = :startDate2 AND tarih2 = :endDate2"
        );
        $stmt->execute([
            'startDate' => $start,
            'endDate' => $end,
            'startDate2' => $start,
            'endDate2' => $end,
        ]);

        return (bool) $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function objectExists(string $name, string $type): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT OBJECT_ID(:objectName, :objectType) AS object_id');
        $stmt->execute(['objectName' => $name, 'objectType' => $type]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row && $row['object_id'] !== null;
    }

    /**
     * En çok talep alan konaklama dönemleri ve talep zamanlaması (potansiyel rapor 2. sekme).
     * Talep = seçilen aralıkta oluşturulan (kayitlar.islem_tarihi) her rezervasyon kaydı; dönem = talep edilen giriş-çıkış.
     * Lead time = giriş tarihi - talebin geldiği gün; girişten sonra açılan kayıtlar lead time'a katılmaz.
     *
     * @Get("demand-periods")
     * @query createdStart string Talep oluşturma başlangıcı (YYYY-MM-DD veya DD.MM.YYYY), varsayılan bu yılın başı
     * @query createdEnd string Talep oluşturma bitişi, dahil (YYYY-MM-DD veya DD.MM.YYYY), varsayılan bu yılın sonu
     * @query limit int Dönem listesi uzunluğu (varsayılan 20, en fazla 50)
     */
    public function demandPeriods(): void
    {
        $year = (new DateTime('today'))->format('Y');
        $createdStart = $this->parseDate((string) $this->request->query('createdStart', ''), $year . '-01-01');
        $createdEnd = $this->parseDate((string) $this->request->query('createdEnd', ''), $year . '-12-31');
        if ($createdEnd < $createdStart) {
            throw new HttpException('createdEnd createdStart tarihinden önce olamaz.', 'VALIDATION_ERROR', 422);
        }
        $limit = min(50, max(1, (int) $this->request->query('limit', '20')));

        $pdo = $this->db->pdo();
        $range = $this->rangeLiterals($createdStart, $createdEnd);
        $giris = 'CAST(k.rez_tarihi AS date)';
        $cikis = 'CAST(k.gelecek_tarih AS date)';
        $gece = 'DATEDIFF(day, k.rez_tarihi, k.gelecek_tarih)';
        $lead = "DATEDIFF(day, CAST(k.islem_tarihi AS date), {$giris})";
        $base = "FROM kayitlar k
INNER JOIN dolu d ON d.kayitid = k.id
WHERE k.islem_tarihi >= {$range['start']} AND k.islem_tarihi < {$range['end']}
  AND k.gelecek_tarih > k.rez_tarihi";

        // Lead time dağılımı (sabit aralıklar) ve genel ortalama
        $leadRows = $pdo->query("
SELECT
    CASE WHEN {$lead} <= 3 THEN 0 WHEN {$lead} <= 14 THEN 1 WHEN {$lead} <= 45 THEN 2 ELSE 3 END AS bucket,
    COUNT(*) AS talep,
    SUM({$lead}) AS lead_toplam,
    AVG(CAST({$gece} AS float)) AS ort_gece
{$base}
  AND {$lead} >= 0
GROUP BY CASE WHEN {$lead} <= 3 THEN 0 WHEN {$lead} <= 14 THEN 1 WHEN {$lead} <= 45 THEN 2 ELSE 3 END")->fetchAll(PDO::FETCH_ASSOC);

        $buckets = [];
        foreach (self::LEAD_TIME_BUCKETS as $index => $label) {
            $buckets[$index] = ['key' => $index, 'label' => $label, 'inquiries' => 0, 'avgStayNights' => 0.0];
        }
        $leadCount = 0;
        $leadSum = 0;
        foreach ($leadRows as $row) {
            $index = (int) $row['bucket'];
            $buckets[$index]['inquiries'] = (int) $row['talep'];
            $buckets[$index]['avgStayNights'] = round((float) $row['ort_gece'], 1);
            $leadCount += (int) $row['talep'];
            $leadSum += (int) $row['lead_toplam'];
        }

        // En çok talep edilen gece sayısı
        $nightRows = $pdo->query("
SELECT TOP 1 {$gece} AS gece, COUNT(*) AS talep, SUM(COUNT(*)) OVER () AS toplam
{$base}
GROUP BY {$gece}
ORDER BY COUNT(*) DESC, {$gece} DESC")->fetchAll(PDO::FETCH_ASSOC);
        $topNights = $nightRows ? $nightRows[0] : null;

        // En sık giriş günü (haftanın günü PHP'de hesaplanır; SQL DATEFIRST/dil ayarına bağlı kalmasın)
        $weekdayCounts = array_fill(0, 7, 0);
        $checkInRows = $pdo->query("
SELECT CONVERT(varchar(10), {$giris}, 23) AS giris, COUNT(*) AS talep
{$base}
GROUP BY {$giris}")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($checkInRows as $row) {
            $weekdayCounts[(int) (new DateTime((string) $row['giris']))->format('w')] += (int) $row['talep'];
        }
        $topWeekday = array_sum($weekdayCounts) > 0 ? array_search(max($weekdayCounts), $weekdayCounts, true) : null;

        // En çok talep alan dönemler (giriş-çıkış)
        $periodRows = $pdo->query("
SELECT TOP {$limit}
    CONVERT(varchar(10), {$giris}, 23) AS giris,
    CONVERT(varchar(10), {$cikis}, 23) AS cikis,
    COUNT(*) AS talep,
    SUM(CASE WHEN d.durum = 3 THEN 1 ELSE 0 END) AS onay
{$base}
GROUP BY {$giris}, {$cikis}
ORDER BY COUNT(*) DESC, {$giris}")->fetchAll(PDO::FETCH_ASSOC);

        $periods = [];
        foreach ($periodRows as $row) {
            $periods[] = [
                'startDate' => (string) $row['giris'],
                'endDate' => (string) $row['cikis'],
                'nights' => (int) (new DateTime((string) $row['giris']))->diff(new DateTime((string) $row['cikis']))->days,
                'inquiries' => (int) $row['talep'],
                'confirmed' => (int) $row['onay'],
                'villas' => [],
                'occupancyRate' => null,
            ];
        }
        $this->fillPeriodVillasAndOccupancy($periods, $base, $giris, $cikis);

        $this->response->success([
            'createdStart' => $createdStart,
            'createdEnd' => $createdEnd,
            'totalInquiries' => array_sum($weekdayCounts),
            'avgLeadDays' => $leadCount > 0 ? (int) round($leadSum / $leadCount) : null,
            'topNights' => $topNights ? [
                'nights' => (int) $topNights['gece'],
                'share' => (int) $topNights['toplam'] > 0 ? (int) round((int) $topNights['talep'] * 100 / (int) $topNights['toplam']) : 0,
            ] : null,
            'topCheckInDay' => $topWeekday === null ? null : self::DAY_NAMES[$topWeekday],
            'leadTimes' => array_values($buckets),
            'periods' => $periods,
        ]);
    }

    /**
     * Dönemlere en çok talep alan 3 villayı ve portföy doluluğunu ekler.
     * Doluluk = dönemle çakışan onaylı (dolu.durum 3) rezervasyonu olan aktif villa / aktif villa sayısı.
     *
     * @param array<int,array<string,mixed>> $periods
     */
    private function fillPeriodVillasAndOccupancy(array &$periods, string $base, string $giris, string $cikis): void
    {
        if (!$periods) {
            return;
        }

        // Tarihler veritabanından Y-m-d olarak geldi; yine de SQL'e yazmadan önce doğrulanır.
        $values = [];
        $pairs = [];
        foreach ($periods as $index => $period) {
            $start = (new DateTime($period['startDate']))->format('Y-m-d');
            $end = (new DateTime($period['endDate']))->format('Y-m-d');
            $values[] = "({$index}, CONVERT(date, '{$start}', 23), CONVERT(date, '{$end}', 23))";
            $pairs[] = "({$giris} = CONVERT(date, '{$start}', 23) AND {$cikis} = CONVERT(date, '{$end}', 23))";
        }
        $valuesSql = implode(', ', $values);
        $pdo = $this->db->pdo();

        $villaRows = $pdo->query("
SELECT CONVERT(varchar(10), {$giris}, 23) AS giris, CONVERT(varchar(10), {$cikis}, 23) AS cikis,
       ISNULL(h.baslik, '') AS villa, COUNT(*) AS talep
" . str_replace('INNER JOIN dolu d ON d.kayitid = k.id', "INNER JOIN dolu d ON d.kayitid = k.id\nLEFT JOIN homes h ON h.id = k.evid", $base) . "
  AND (" . implode(' OR ', $pairs) . ")
GROUP BY {$giris}, {$cikis}, h.baslik
ORDER BY COUNT(*) DESC")->fetchAll(PDO::FETCH_ASSOC);
        $villasByPeriod = [];
        foreach ($villaRows as $row) {
            $key = $row['giris'] . '|' . $row['cikis'];
            if ($row['villa'] !== '' && count($villasByPeriod[$key] ?? []) < 3) {
                $villasByPeriod[$key][] = (string) $row['villa'];
            }
        }

        $activeVillas = (int) $pdo->query('SELECT COUNT(*) FROM homes WHERE aktif = 1')->fetchColumn();
        $bookedRows = $activeVillas > 0 ? $pdo->query("
SELECT r.i, COUNT(DISTINCT d.emlak) AS dolu_villa
  FROM (VALUES {$valuesSql}) AS r(i, giris, cikis)
 INNER JOIN dolu d ON d.durum = 3
       AND TRY_CONVERT(date, d.tarih, 104) < r.cikis
       AND TRY_CONVERT(date, d.tarih2, 104) > r.giris
 INNER JOIN homes h ON h.id = d.emlak AND h.aktif = 1
 GROUP BY r.i")->fetchAll(PDO::FETCH_ASSOC) : [];
        $bookedByIndex = [];
        foreach ($bookedRows as $row) {
            $bookedByIndex[(int) $row['i']] = (int) $row['dolu_villa'];
        }

        foreach ($periods as $index => &$period) {
            $period['villas'] = $villasByPeriod[$period['startDate'] . '|' . $period['endDate']] ?? [];
            $period['occupancyRate'] = $activeVillas > 0
                ? (int) round(($bookedByIndex[$index] ?? 0) * 100 / $activeVillas)
                : null;
        }
        unset($period);
    }

    /**
     * Potansiyel raporu filtreleri: villalar ve bölgeler.
     *
     * @Get("potential-options")
     */
    public function potentialOptions(): void
    {
        $pdo = $this->db->pdo();

        $villas = $pdo->query(
            "SELECT h.id, h.baslik, ISNULL(d.baslik, '') AS bolge
               FROM homes h
               LEFT JOIN destinations d ON d.id = h.emlak_bolgesi
              ORDER BY h.baslik ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $destinations = $pdo->query(
            'SELECT id, baslik AS title, cat AS parent_id FROM destinations ORDER BY baslik ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        $titles = [];
        foreach ($destinations as $destination) {
            $titles[(int) $destination['id']] = (string) $destination['title'];
        }

        $regions = [];
        foreach ($destinations as $destination) {
            $parentId = (int) $destination['parent_id'];
            $regions[] = [
                'id' => (int) $destination['id'],
                'title' => $parentId === 0
                    ? $destination['title'] . ' / Tümü'
                    : ($titles[$parentId] ?? '') . ' / ' . $destination['title'],
                'parent_id' => $parentId,
            ];
        }

        $this->response->success([
            'villas' => array_map(function (array $row): array {
                return ['id' => (int) $row['id'], 'title' => (string) $row['baslik'], 'region' => (string) $row['bolge']];
            }, $villas),
            'regions' => $regions,
        ]);
    }

    /**
     * Virgülle ayrılmış pozitif tam sayı listesi (SQL'e doğrudan yazılabilir).
     *
     * @return int[]
     */
    private function idList(string $value): array
    {
        $ids = array_filter(array_map('intval', explode(',', $value)), function (int $id): bool {
            return $id > 0;
        });

        return array_values(array_unique($ids));
    }

    /**
     * Bugün onaylanan rezervasyonları listeler.
     *
     * @Get("today-completed")
     */
    public function todayCompleted(): void
    {
        $items = $this->todayReservations('d.durum = 3');
        foreach ($items as &$item) {
            $item['payment_type'] = (int) $item['odeme'] === self::PAYMENT_CREDIT_CARD ? 'credit_card' : 'transfer';
            $item['payment_label'] = (int) $item['odeme'] === self::PAYMENT_CREDIT_CARD ? 'Kredi Kartı' : 'Havale';
        }
        unset($item);

        $this->response->success(['items' => $items, 'meta' => ['count' => count($items)]]);
    }

    /**
     * Bugün iptal edilen, süresi dolan veya silinen rezervasyonları listeler.
     *
     * @Get("today-cancelled")
     */
    public function todayCancelled(): void
    {
        $items = $this->todayReservations('d.durum IN (2, 4, ' . $this->deletedStatus() . ')');
        foreach ($items as &$item) {
            $status = $this->normalizeStatus($item['durum']);
            $item['durum'] = $status;
            $item['durum_text'] = self::STATUS_LABELS[$status] ?? '';
        }
        unset($item);

        $this->response->success(['items' => $items, 'meta' => ['count' => count($items)]]);
    }

    /**
     * Şu anki durumuna bugün geçmiş rezervasyonlar (reservations_status_log'daki son kayda göre).
     *
     * @return array<int,array<string,mixed>>
     */
    private function todayReservations(string $statusWhere): array
    {
        $sql = "
SELECT
    k.adi,
    k.id,
    k.musteri,
    CONVERT(varchar(10), d.tarih, 104) AS tarih,
    CONVERT(varchar(10), d.tarih2, 104) AS tarih2,
    ISNULL(TRY_CONVERT(float, dbo.fnTemizle(k.on_odeme)), 0) AS on_odeme,
    k.odeme,
    d.durum
FROM kayitlar k
INNER JOIN dolu d ON d.kayitid = k.id
CROSS APPLY (
    SELECT TOP 1 rsl.id
      FROM reservations_status_log rsl
     WHERE rsl.current_status = d.durum
       AND rsl.kayitlarId = k.id
       AND rsl.createdOn >= CAST(GETDATE() AS date)
       AND rsl.createdOn < DATEADD(day, 1, CAST(GETDATE() AS date))
     ORDER BY rsl.id DESC
) AS rsl
WHERE {$statusWhere}
ORDER BY k.id DESC";

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'villa' => (string) $row['adi'],
                'musteri' => (string) $row['musteri'],
                'tarih' => (string) $row['tarih'],
                'tarih2' => (string) $row['tarih2'],
                'on_odeme' => $this->money($row['on_odeme']),
                'odeme' => $row['odeme'] === null ? null : (int) $row['odeme'],
                'durum' => $row['durum'] === null ? null : (int) $row['durum'],
            ];
        }

        return $items;
    }

    /**
     * Aralıkta onaylanan rezervasyonların ödeme tipine göre ön ödeme toplamı (TL).
     * Panel gibi tarih tipinden bağımsızdır: onay logu aralık içinde olanlar sayılır.
     *
     * @param array{start:string,end:string} $range rangeLiterals() çıktısı
     */
    private function paymentTotal(int $paymentType, array $range): float
    {
        $sql = "
SELECT ISNULL(SUM(k.tutar * (CASE WHEN k.doviz = 'tl' THEN 1 ELSE k.kur END)), 0) AS fiyat
FROM kayitlar k
INNER JOIN dolu d ON d.kayitid = k.id
CROSS APPLY (
    SELECT TOP 1 rsl.id
      FROM reservations_status_log rsl
     WHERE rsl.current_status = d.durum
       AND rsl.kayitlarId = k.id
       AND rsl.createdOn >= {$range['start']}
       AND rsl.createdOn < {$range['end']}
     ORDER BY rsl.id DESC
) AS rsl
WHERE k.odeme = {$paymentType}
  AND d.durum = 3";

        $row = $this->fetchOne($sql, []);

        return $this->money($row['fiyat']);
    }

    /**
     * Bitiş günü dahil olacak şekilde [başlangıç 00:00, bitiş+1 gün 00:00) aralığının SQL sabitleri.
     * Girdiler parseDate() ile doğrulanmış Y-m-d olmalı; tekrar DateTime'dan geçirilerek yazılır.
     *
     * @return array{start:string,end:string}
     */
    private function rangeLiterals(string $startDate, string $endDate): array
    {
        $start = (new DateTime($startDate))->format('Y-m-d');
        $end = (new DateTime($endDate))->modify('+1 day')->format('Y-m-d');

        return [
            'start' => "CONVERT(datetime, '{$start} 00:00:00', 120)",
            'end' => "CONVERT(datetime, '{$end} 00:00:00', 120)",
        ];
    }

    /**
     * @param array<string,string> $params
     * @return array<string,mixed>
     */
    private function fetchOne(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    /**
     * @param mixed $value
     */
    private function money($value): float
    {
        return round((float) $value, 2);
    }

    private function parseDate(string $value, string $default): string
    {
        $value = trim($value);
        if ($value === '') {
            return $default;
        }

        foreach (['Y-m-d', 'd.m.Y', 'd/m/Y'] as $format) {
            $date = DateTime::createFromFormat('!' . $format, $value);
            if ($date instanceof DateTime && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        throw new HttpException('tarih formatı geçersiz. Örnek: 2026-10-26', 'VALIDATION_ERROR', 422);
    }
}
