<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Ilan takvimi: dolu kayitlari, bagli rezervasyon (kayitlar) ve satis kanali (satis_kanallari).
 *
 * Endpoint:
 *   GET  /backend-api/homes-management-calendar?id={emlakId}
 *   POST /backend-api/homes-management-calendar          {id, startDate, endDate, title, rezTip, optionHours}  kapatma / opsiyon ekler
 *   POST /backend-api/homes-management-calendar/update   {doluId, startDate, endDate, title, rezTip}
 *   POST /backend-api/homes-management-calendar/delete   {doluId}
 *
 * Kapatma: rezervasyonsuz dolu kaydidir (kayitid -1 veya NULL). Rezervasyon kayitlarina (kayitid > 0) dokunulmaz.
 *
 * rez_tip: dolu.rez_tip doluysa o deger; bossa kayitid varsa "rezervasyon", yoksa "kapali".
 * Kiralama Takvimi'nden ice aktarilan satir (kayitid -1, ReservationId > 0) rezervasyon gibidir: Durum 1 ise "opsiyon",
 * degilse "rezervasyon" listelenir; takvimden duzenlenemez / silinemez, cakisma kontrolunde engeller.
 *
 * Opsiyon (rezTip "opsiyon", doluluk takviminden, Kiralama Takvimi'ne bagli olmayan villa): rezervasyonsuz dolu kaydi,
 * Durum 1, saat2 = opsiyon suresi (saat). Suresi dolunca kendiliginden silinmez.
 *
 * Rezervasyon id kolonu sitelere gore kayitid ya da kayitId olabilir; hangisi varsa o kullanilir.
 *
 * Kiralama Takvimi (POST'ta kiralamaTakvimi=1, villa KiralamaTakvimi.CalendarHomes'ta bagli): dolu tablosuna yazilmaz.
 * Panel API'si (sitenin /api'si) uzerinden Reservation/Create, ardindan Option (opsiyon) ya da Approve (kapatma).
 * Ikinci adim basarisiz olursa merkezi sistemde acilan rezervasyon Reservation/Cancel ile geri alinir.
 * Ayarlar (config/app.local.php): kiralamatakvimi_panel_api_url, kiralamatakvimi_panel_api_headers.
 */
final class HomesManagementCalendarController extends Controller
{
    // Ornek kapatma kaydindaki degerler: Durum 0, kayitid -1, kullanici_id -99, IsExternal 0, State 0.
    private const CLOSURE_DURUM = 0;
    private const CLOSURE_KAYIT_ID = -1;
    private const CLOSURE_KULLANICI_ID = -99;
    // Takvimden eklenebilen kayit tipleri; dolu.rez_tip kolonuna yazilir.
    private const CLOSURE_TYPES = ['kapali', 'tadilat'];
    // Opsiyon sadece eklenebilir (guncellemede tip degistirilemez): Durum 1 (Odeme Bekliyor), dolu.saat2 = sure (saat).
    private const OPTION_TYPE = 'opsiyon';
    private const OPTION_DURUM = 1;
    private const OPTION_MAX_HOURS = 720;
    // Kiralama Takvimi'ndeki mevcut kaydi iptal (Reservation/Cancel).
    private const CANCEL_TYPE = 'iptal';
    // Kiralama Takvimi'ndeki mevcut kaydin basligini guncelle (KT reservation/update, dogrudan) / oku (Reservation/Item).
    private const EDIT_TYPE = 'duzenle';
    private const DETAIL_TYPE = 'detay';

    /** @var string|null dolu tablosundaki rezervasyon id kolonu (kayitid / kayitId) */
    private $kayitIdColumn = null;

    private const DURUM_TEXT = [
        0 => 'Onay Bekliyor',
        1 => 'Ödeme Bekliyor',
        2 => 'Süre Doldu',
        3 => 'Onaylandı',
        4 => 'İptal Edildi',
        5 => 'Silindi',
        6 => 'Açık Rezervasyon',
    ];

    /**
     * Emlagin takvim kayitlarini dondurur. Suresi dolan, iptal ve silinen kayitlar (durum 2, 4, 5) takvimde gosterilmez.
     *
     * @Get
     * @query id int required Emlak ID
     */
    public function index(): void
    {
        $id = filter_var($this->request->query('id'), FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            throw new HttpException('Lutfen gecerli bir emlak ID gonderin.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();
        $rezTipColumn = $this->hasColumn($pdo, 'dbo.dolu', 'rez_tip') ? 'd.rez_tip' : 'NULL';
        $colorColumn = $this->hasColumn($pdo, 'dbo.satis_kanallari', 'color') ? 'sk.color' : 'NULL';
        $kayitIdColumn = $this->kayitIdColumn($pdo);
        $ktReservationColumn = $this->hasColumn($pdo, 'dbo.dolu', 'ReservationId') ? 'd.ReservationId' : 'NULL';

        // NULL durum -99 ile ayrılır; siteye özel silinme kodu (örn. -1) da gizlenir.
        $deletedStatus = $this->deletedStatus();
        $stmt = $pdo->prepare("
            SELECT
                d.id,
                d.[{$kayitIdColumn}] AS kayitid,
                {$ktReservationColumn} AS kt_reservation_id,
                CONVERT(varchar(10), d.tarih, 23) AS tarih,
                CONVERT(varchar(10), d.tarih2, 23) AS tarih2,
                d.Durum AS durum,
                d.ical,
                CONVERT(varchar(19), d.createdOn, 120) AS created_on,
                d.musteri AS dolu_musteri,
                {$rezTipColumn} AS rez_tip,
                k.musteri,
                k.adi,
                k.siparis_kodu,
                k.toplam_tutar,
                k.doviz,
                k.yetiskin,
                k.cocuk,
                k.bebek,
                k.[not] AS notlar,
                sk.id AS kanal_id,
                sk.baslik AS kanal_baslik,
                {$colorColumn} AS kanal_renk
            FROM dbo.dolu d
            LEFT JOIN dbo.kayitlar k ON k.id = d.[{$kayitIdColumn}] AND ISNULL(d.[{$kayitIdColumn}], 0) > 0
            LEFT JOIN dbo.satis_kanallari sk ON sk.id = k.satis_kanallari_id
            WHERE d.emlak = :emlak
              AND ISNULL(d.Durum, -99) NOT IN (2, 4, 5, {$deletedStatus})
            ORDER BY d.tarih DESC, d.id DESC
        ");
        $stmt->execute([':emlak' => $id]);

        $events = [];
        foreach ($stmt->fetchAll() as $row) {
            $kayitId = (int) ($row['kayitid'] ?? 0);
            $ktReservationId = (int) ($row['kt_reservation_id'] ?? 0);
            $durum = $this->normalizeStatus($row['durum']);
            $rezTip = trim((string) ($row['rez_tip'] ?? ''));
            if ($rezTip === '') {
                if ($kayitId <= 0 && $ktReservationId > 0) {
                    $rezTip = $durum === self::OPTION_DURUM ? self::OPTION_TYPE : 'rezervasyon';
                } else {
                    $rezTip = $kayitId > 0 ? 'rezervasyon' : 'kapali';
                }
            }

            $events[] = [
                'id' => (int) $row['id'],
                'kayitId' => $kayitId > 0 ? $kayitId : null,
                // Kiralama Takvimi rezervasyon id'si; doluysa kayit KT'den gelir ve takvimden degistirilemez.
                'ktReservationId' => $ktReservationId > 0 ? $ktReservationId : null,
                'startDate' => (string) $row['tarih'],
                'endDate' => (string) $row['tarih2'],
                'rezTip' => $rezTip,
                'durum' => $durum,
                'durumText' => $durum === null ? null : (self::DURUM_TEXT[$durum] ?? null),
                'musteri' => $this->firstFilled([$row['musteri'], $row['adi'], $row['dolu_musteri']]),
                'siparisKodu' => $this->text($row['siparis_kodu']),
                'toplamTutar' => $this->text($row['toplam_tutar']),
                'doviz' => $this->text($row['doviz']),
                'yetiskin' => (int) $row['yetiskin'],
                'cocuk' => (int) $row['cocuk'],
                'bebek' => (int) $row['bebek'],
                'notlar' => $this->text($row['notlar']),
                'ical' => !empty($row['ical']),
                'createdAt' => $this->text($row['created_on']),
                'satisKanali' => $row['kanal_id'] === null ? null : [
                    'id' => (int) $row['kanal_id'],
                    'baslik' => $this->text($row['kanal_baslik']),
                    'color' => $this->text($row['kanal_renk']),
                ],
            ];
        }

        $channels = [];
        foreach ($pdo->query("SELECT id, baslik, {$this->channelColor($colorColumn)} AS color FROM dbo.satis_kanallari ORDER BY id")->fetchAll() as $channel) {
            $channels[] = [
                'id' => (int) $channel['id'],
                'baslik' => $this->text($channel['baslik']),
                'color' => $this->text($channel['color']),
            ];
        }

        $this->response->success([
            'events' => $events,
            'satisKanallari' => $channels,
        ]);
    }

    /**
     * Tarih araligini kapatir.
     *
     * @Post
     */
    public function store(): void
    {
        $emlakId = $this->positiveInt('id', 'Lutfen gecerli bir emlak ID gonderin.');
        [$start, $end] = $this->dateRange();

        // Kiralama Takvimi'ndeki mevcut kayit (ReservationId secili tarihlerden bulunur): dolu'ya yazilmaz, sadece KT'ye istek gider.
        // iptal: Reservation/Cancel, duzenle: baslik (KT reservation/update, dogrudan), detay: mevcut bilgiler (Reservation/Item).
        $existingAction = (string) $this->request->input('rezTip', '');
        if (in_array($existingAction, [self::CANCEL_TYPE, self::EDIT_TYPE, self::DETAIL_TYPE], true)) {
            if (!filter_var($this->request->input('kiralamaTakvimi', false), FILTER_VALIDATE_BOOLEAN)) {
                throw new HttpException('Bu islem sadece Kiralama Takvimi\'ne bagli villada yapilabilir.', 'VALIDATION', 422);
            }
            $pdo = $this->db->pdo();
            if ($existingAction === self::CANCEL_TYPE) {
                $this->cancelOnKiralamaTakvimi($pdo, $emlakId, $start, $end);
            } elseif ($existingAction === self::EDIT_TYPE) {
                $this->updateOnKiralamaTakvimi($pdo, $emlakId, $start, $end);
            } else {
                $this->detailOnKiralamaTakvimi($pdo, $emlakId, $start, $end);
            }
            return;
        }

        $title = $this->title();
        $rezTip = $this->closureType(true);
        $isOption = $rezTip === self::OPTION_TYPE;
        $optionHours = $isOption ? $this->optionHours() : 0;

        $pdo = $this->db->pdo();
        if (filter_var($this->request->input('kiralamaTakvimi', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->storeOnKiralamaTakvimi($pdo, $emlakId, $start, $end, $title, $rezTip, $optionHours);
            return;
        }

        $pdo->beginTransaction();
        try {
            $this->assertNoOverlap($pdo, $emlakId, $start, $end, 0);
            $this->assertRezTipColumn($pdo);
            $columns = ['emlak', 'tarih', 'tarih2', 'musteri', 'Durum', $this->kayitIdColumn($pdo), 'kullanici_id', 'ical', 'rez_tip'];
            $values = [
                ':emlak' => $emlakId,
                ':tarih' => $start,
                ':tarih2' => $end,
                ':musteri' => $title,
                ':Durum' => $isOption ? self::OPTION_DURUM : self::CLOSURE_DURUM,
                ':kayitid' => self::CLOSURE_KAYIT_ID,
                ':kullanici_id' => self::CLOSURE_KULLANICI_ID,
                ':ical' => 0,
                ':rez_tip' => $rezTip,
            ];
            if ($isOption) {
                if (!$this->hasColumn($pdo, 'dbo.dolu', 'saat2')) {
                    throw new HttpException('dolu.saat2 kolonu bulunamadi.', 'SETUP_REQUIRED', 409);
                }
                $columns[] = 'saat2';
                $values[':saat2'] = (string) $optionHours;
            }
            foreach (['IsExternal', 'State'] as $optional) {
                if ($this->hasColumn($pdo, 'dbo.dolu', $optional)) {
                    $columns[] = $optional;
                    $values[':' . $optional] = 0;
                }
            }
            $stmt = $pdo->prepare('INSERT INTO dbo.dolu ([' . implode('], [', $columns) . '], createdOn) VALUES ('
                . implode(', ', array_keys($values)) . ', GETDATE())');
            $stmt->execute($values);
            $newId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->response->success(['message' => $isOption ? 'Opsiyon eklendi' : 'Tarihler kapatildi', 'id' => $newId], 201);
    }

    /**
     * Kiralama Takvimi'ne bagli villada opsiyon / tarih kapatma: dolu tablosuna yazilmaz, istek panel API'sine gider.
     */
    private function storeOnKiralamaTakvimi(PDO $pdo, int $emlakId, string $start, string $end, string $title, string $rezTip, int $optionHours): void
    {
        if ($rezTip === 'tadilat') {
            throw new HttpException('Kiralama Takvimi\'ne bagli villada tadilat eklenemez; opsiyon ya da tarih kapatma secin.', 'VALIDATION', 422);
        }

        $stmt = $pdo->prepare('SELECT TOP 1 EstateId FROM KiralamaTakvimi.CalendarHomes WHERE homesId = :id');
        $stmt->execute([':id' => $emlakId]);
        $estateId = (int) ($stmt->fetchColumn() ?: 0);
        if ($estateId <= 0) {
            throw new HttpException('Villa Kiralama Takvimi\'ne bagli degil.', 'KT_NOT_CONNECTED', 422);
        }

        $sendGroup = $this->kiralamaTakvimiSendGroup();

        // Secilen tarihlerde Kiralama Takvimi'nden gelmis bir opsiyon varsa yeni rezervasyon acilmaz;
        // islem o opsiyonun ReservationId'si ile yapilir.
        $reservationId = $this->existingKtOptionReservationId($pdo, $emlakId, $start, $end);
        $isExisting = $reservationId > 0;

        if (!$isExisting) {
            $adult = max(1, (int) $this->request->input('adultGuestCount', 1));
            $child = max(0, (int) $this->request->input('childGuestCount', 0));

            // Basarili: "0;/{ReservationId};/{EstateId}", hata: "1;/{mesaj}"
            $raw = $this->panelApi('POST', 'Reservation/Create', [
                'estateId' => $estateId,
                'checkInDate' => $start,
                'checkOutDate' => $end,
                'customerEmail' => trim((string) $this->request->input('customerEmail', '')),
                'customerFullName' => $title,
                'customerPhone' => trim((string) $this->request->input('customerPhone', '')),
                'adultGuestCount' => (string) $adult,
                'childGuestCount' => (string) $child,
            ]);
            $parts = explode(';/', $raw);
            if ($parts[0] !== '0' || !isset($parts[1]) || (int) $parts[1] <= 0) {
                throw new HttpException($this->panelMessage($parts, 'Kiralama Takvimi\'nde rezervasyon olusturulamadi.'), 'KT_CREATE_FAILED', 502);
            }
            $reservationId = (int) $parts[1];
        }

        $isOption = $rezTip === self::OPTION_TYPE;
        // Mevcut KT kaydi (ReservationId tarihten bulundu) POST ile, yeni acilan rezervasyon eskisi gibi GET ile.
        // Panel API (Reservation::Option / Approve) parametreleri get() ile okudugu icin POST'ta da query'de gider.
        $method = $isExisting ? 'POST' : 'GET';
        $result = $isOption
            ? $this->panelApi($method, 'Reservation/Option', [
                'reservationId' => $reservationId,
                'estateId' => $estateId,
                'optionHours' => $optionHours,
                'callbackParam' => '',
                'isMinute' => '',
                'sendWhatsappGroup' => $sendGroup ? '1' : '0',
            ], true)
            : $this->panelApi($method, 'Reservation/Approve', [
                'reservationId' => $reservationId,
                'estateId' => $estateId,
                'sendWhatsappGroup' => $sendGroup ? '1' : '0',
            ], true);

        if (trim($result) !== 'success') {
            // Yarim kalan (bu istekte acilan) rezervasyon merkezi sistemde birakilmaz; mevcut opsiyona dokunulmaz.
            if (!$isExisting) {
                try {
                    $this->panelApi('GET', 'Reservation/Cancel', [
                        'reservationId' => $reservationId,
                        'estateId' => $estateId,
                        'sendWhatsappGroup' => '0',
                    ]);
                } catch (\Throwable $e) {
                    // Iptal de basarisizsa asil hata dondurulur.
                }
            }
            throw new HttpException(
                trim($result) !== '' ? trim($result) : ($isOption ? 'Kiralama Takvimi opsiyon islemi basarisiz.' : 'Kiralama Takvimi tarih kapatma islemi basarisiz.'),
                $isOption ? 'KT_OPTION_FAILED' : 'KT_APPROVE_FAILED',
                502
            );
        }

        $this->response->success([
            'message' => $isOption ? 'Kiralama Takvimi\'nde opsiyona alindi' : 'Kiralama Takvimi\'nde tarih kapatildi',
            'kiralamaTakvimi' => ['reservationId' => $reservationId, 'estateId' => $estateId, 'existing' => $isExisting],
        ], 201);
    }

    /**
     * Secilen tarihlerdeki Kiralama Takvimi kaydini (ReservationId tarihten bulunur) iptal eder: POST Reservation/Cancel.
     */
    private function cancelOnKiralamaTakvimi(PDO $pdo, int $emlakId, string $start, string $end): void
    {
        [$reservationId, $estateId] = $this->requireExistingKtReservation($pdo, $emlakId, $start, $end);

        $sendGroup = $this->kiralamaTakvimiSendGroup();
        // Panel API (Reservation::Cancel) parametreleri get() ile okur; POST'ta da query'de gider.
        $result = $this->panelApi('POST', 'Reservation/Cancel', [
            'reservationId' => $reservationId,
            'sendWhatsappGroup' => $sendGroup ? '1' : '0',
        ], true);

        if (trim($result) !== 'success') {
            throw new HttpException(trim($result) !== '' ? trim($result) : 'Kiralama Takvimi iptal islemi basarisiz.', 'KT_CANCEL_FAILED', 502);
        }

        $this->response->success([
            'message' => 'Kiralama Takvimi\'nde rezervasyon iptal edildi',
            'kiralamaTakvimi' => ['reservationId' => $reservationId, 'estateId' => $estateId, 'existing' => true],
        ]);
    }

    /**
     * Secilen tarihlerdeki Kiralama Takvimi kaydinin sadece basligini (KT customerFullName) gunceller; tarih degismez:
     * Panelde karsiligi olmadigi icin istek dogrudan Kiralama Takvimi API'sine gider (reservation/item + reservation/update).
     */
    private function updateOnKiralamaTakvimi(PDO $pdo, int $emlakId, string $start, string $end): void
    {
        [$reservationId, $estateId] = $this->requireExistingKtReservation($pdo, $emlakId, $start, $end);

        $title = $this->title();

        // reservation/update gonderilmeyen alanlari bosaltabilir: mevcut kayit okunup sadece musteri adi degistirilir.
        $item = $this->ktApi('GET', 'reservation/item', null, ['reservationId' => $reservationId]);
        $this->ktApi('POST', 'reservation/update', [
            'reservationId' => $reservationId,
            'customerFullName' => $title,
            'customerPhone' => (string) ($this->rowValue($item, 'customerPhone') ?? ''),
            'customerEmail' => (string) ($this->rowValue($item, 'customerEmail') ?? ''),
            'adultGuestCount' => (int) ($this->rowValue($item, 'adultGuestCount') ?? 0),
            'childGuestCount' => (int) ($this->rowValue($item, 'childGuestCount') ?? 0),
            'callbackParam' => (int) ($this->rowValue($item, 'callbackParam') ?? 0),
            'description' => (string) ($this->rowValue($item, 'description') ?? ''),
            'sendWhatsappGroup' => $this->kiralamaTakvimiSendGroup(),
        ]);

        $this->response->success([
            'message' => 'Kiralama Takvimi\'nde rezervasyon guncellendi',
            'kiralamaTakvimi' => ['reservationId' => $reservationId, 'estateId' => $estateId, 'existing' => true],
        ]);
    }

    /**
     * Secilen tarihlerdeki Kiralama Takvimi kaydinin musteri bilgileri (duzenle penceresini doldurmak icin): Reservation/Item.
     */
    private function detailOnKiralamaTakvimi(PDO $pdo, int $emlakId, string $start, string $end): void
    {
        [$reservationId, $estateId] = $this->requireExistingKtReservation($pdo, $emlakId, $start, $end);

        // Panel Reservation::Item reservationId'yi post() ile okur ve KT cevabini oldugu gibi basar.
        $item = json_decode($this->panelApi('POST', 'Reservation/Item', ['reservationId' => $reservationId]), true);
        if (!is_array($item)) {
            throw new HttpException('Kiralama Takvimi kaydi okunamadi.', 'KT_ITEM_FAILED', 502);
        }

        $this->response->success([
            'reservationId' => $reservationId,
            'estateId' => $estateId,
            'customerFullName' => (string) ($this->rowValue($item, 'customerFullName') ?? ''),
            'customerPhone' => (string) ($this->rowValue($item, 'customerPhone') ?? ''),
            'customerEmail' => (string) ($this->rowValue($item, 'customerEmail') ?? ''),
            'state' => $this->rowValue($item, 'state'),
        ]);
    }

    /**
     * Villanin KT EstateId'si ve secili tarihlerle cakisan KT kaydinin ReservationId'si; yoksa hata.
     *
     * @return int[] [reservationId, estateId]
     */
    private function requireExistingKtReservation(PDO $pdo, int $emlakId, string $start, string $end): array
    {
        $stmt = $pdo->prepare('SELECT TOP 1 EstateId FROM KiralamaTakvimi.CalendarHomes WHERE homesId = :id');
        $stmt->execute([':id' => $emlakId]);
        $estateId = (int) ($stmt->fetchColumn() ?: 0);
        if ($estateId <= 0) {
            throw new HttpException('Villa Kiralama Takvimi\'ne bagli degil.', 'KT_NOT_CONNECTED', 422);
        }

        // Frontend ReservationId'yi (detay cevabindan) gonderirse o kullanilir; villanin dolu kaydinda oldugu dogrulanir.
        $requested = (int) $this->request->input('reservationId', 0);
        if ($requested > 0) {
            $check = $pdo->prepare('SELECT TOP 1 ReservationId FROM dbo.dolu WHERE emlak = :emlak AND ReservationId = :rid');
            $check->execute([':emlak' => $emlakId, ':rid' => $requested]);
            if ((int) ($check->fetchColumn() ?: 0) !== $requested) {
                throw new HttpException('Kiralama Takvimi kaydi bu villaya ait degil.', 'KT_NOT_FOUND', 404);
            }

            return [$requested, $estateId];
        }

        $reservationId = $this->existingKtOptionReservationId($pdo, $emlakId, $start, $end);
        if ($reservationId <= 0) {
            throw new HttpException('Secilen tarihlerde Kiralama Takvimi kaydi bulunamadi.', 'KT_NOT_FOUND', 404);
        }

        return [$reservationId, $estateId];
    }

    // KT cevabinda alan adi buyuk/kucuk harf farkli gelebilir (customerFullName / CustomerFullName).
    private function rowValue(array $row, string $key)
    {
        foreach ($row as $name => $value) {
            if (strcasecmp((string) $name, $key) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Secilen aralikla cakisan, Kiralama Takvimi'nden gelmis kaydin (opsiyon, kapali ya da dolu; ReservationId > 0) ReservationId'si; yoksa 0.
     */
    private function existingKtOptionReservationId(PDO $pdo, int $emlakId, string $start, string $end): int
    {
        if (!$this->hasColumn($pdo, 'dbo.dolu', 'ReservationId')) {
            return 0;
        }

        $stmt = $pdo->prepare(
            'SELECT TOP 1 ReservationId FROM dbo.dolu
             WHERE emlak = :emlak AND ReservationId > 0
               AND tarih < :end AND tarih2 > :start
             ORDER BY tarih'
        );
        $stmt->execute([':emlak' => $emlakId, ':start' => $start, ':end' => $end]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    // Islem bildirimi villanin WhatsApp grubuna gitsin mi (gonderimi merkezi sistem yapar). Gelmezse true.
    private function kiralamaTakvimiSendGroup(): bool
    {
        $raw = $this->request->input('sendWhatsappGroup');
        if ($raw === null || $raw === '') {
            return true;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Panel API'sine (orn. https://www.site.com/api/Reservation/Option) istek atar, ham metin yanit doner.
     * ReservationStatusController::panelApi ile ayni.
     */
    private function panelApi(string $method, string $path, array $data, bool $queryOnly = false): string
    {
        $url = $this->panelUrl() . '/' . $path;
        $headers = array_values((array) ($this->app['kiralamatakvimi_panel_api_headers'] ?? []));

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
        ];
        if ($method === 'GET') {
            $url .= '?' . http_build_query($data);
        } elseif ($queryOnly) {
            // POST, parametreler query'de (panel get() ile okur); body bos.
            $url .= '?' . http_build_query($data);
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = '';
        } else {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($data);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $options[CURLOPT_HTTPHEADER] = $headers;

        $curl = curl_init($url);
        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($raw === false || $status === 0) {
            throw new HttpException(
                'Kiralama Takvimi (panel API) adresine ulasilamadi.' . (!empty($this->app['debug']) ? ' | ' . $url . ' | ' . $error : ''),
                'PANEL_UNREACHABLE',
                502
            );
        }
        // Panel / Kiralama Takvimi hatasi: donen metin oldugu gibi (HTML sayfasiysa etiketleri ayiklanarak) iletilir.
        if ($status >= 400) {
            $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $raw), ENT_QUOTES, 'UTF-8')) ?? '');
            $decoded = json_decode((string) $raw, true);
            if (is_array($decoded)) {
                $messages = $this->rowValue($decoded, 'messages');
                $text = is_array($messages) && isset($messages[0]) ? (string) $messages[0] : (string) ($this->rowValue($decoded, 'message') ?? $text);
            }
            throw new HttpException(
                ($text !== '' ? (function_exists('mb_substr') ? mb_substr($text, 0, 500) : substr($text, 0, 500)) : 'Bos yanit') . ' (' . $path . ', HTTP ' . $status . ')',
                'KT_PANEL_ERROR',
                502
            );
        }

        return trim((string) $raw);
    }

    /**
     * Kiralama Takvimi API'sine (https://backend.kiralamatakvimi.com/api) dogrudan JSON istek atar, cevabi dizi olarak doner.
     * Kimlik: X-API-Key ve EstablishmentId header'lari. Ayarlar (config/app.local.php):
     *   kiralamatakvimi_api_url (bos: https://backend.kiralamatakvimi.com/api),
     *   kiralamatakvimi_api_key, kiralamatakvimi_establishment_id (bos: panel api/config.php'deki $config degerleri).
     * Hata (200 disi): KT'nin mesaji (messages[0]) oldugu gibi iletilir.
     *
     * @param array<string,mixed>|null $body
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function ktApi(string $method, string $path, ?array $body, array $query = []): array
    {
        // Anahtar ve EstablishmentId: once app.local.php, sonra sitenin genel tablosu (apiKey / EstablishmentId kolonu),
        // en son panelin api/config.php'si ($config['EstablishmentId']).
        $panel = (array) ($this->app['_panel_config'] ?? []);
        $genel = $this->db->pdo()->query('SELECT TOP 1 * FROM genel')->fetch(PDO::FETCH_ASSOC) ?: [];
        $apiKey = trim((string) ($this->app['kiralamatakvimi_api_key'] ?? ''));
        if ($apiKey === '') {
            $apiKey = trim((string) ($this->rowValue($genel, 'apiKey') ?? ''));
        }
        $establishmentId = trim((string) ($this->app['kiralamatakvimi_establishment_id'] ?? ''));
        if ($establishmentId === '') {
            $establishmentId = trim((string) ($this->rowValue($genel, 'EstablishmentId') ?? ($panel['EstablishmentId'] ?? '')));
        }
        if ($apiKey === '' || $establishmentId === '') {
            throw new HttpException(
                'Kiralama Takvimi API ayarlari eksik: ' . ($apiKey === '' ? 'genel.apiKey bos' : 'EstablishmentId bulunamadi')
                . ' (config/app.local.php: kiralamatakvimi_api_key / kiralamatakvimi_establishment_id).',
                'KT_CONFIG_MISSING',
                500
            );
        }

        $base = rtrim(trim((string) ($this->app['kiralamatakvimi_api_url'] ?? '')) ?: 'https://backend.kiralamatakvimi.com/api', '/');
        $url = $base . '/' . $path . ($query ? '?' . http_build_query($query) : '');
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-API-Key: ' . $apiKey,
                'EstablishmentId: ' . $establishmentId,
            ],
        ];
        if ($method !== 'GET') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($body ?? new \stdClass(), JSON_UNESCAPED_UNICODE);
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($raw === false || $status === 0) {
            throw new HttpException('Kiralama Takvimi API\'sine ulasilamadi.', 'KT_UNREACHABLE', 502);
        }
        $decoded = json_decode((string) $raw, true);
        if ($status < 200 || $status >= 300) {
            $message = '';
            if (is_array($decoded)) {
                $messages = $this->rowValue($decoded, 'messages');
                $message = is_array($messages) && isset($messages[0]) ? (string) $messages[0] : (string) ($this->rowValue($decoded, 'message') ?? '');
            }
            if ($message === '') {
                $message = trim(strip_tags((string) $raw)) ?: 'Kiralama Takvimi istegi basarisiz.';
            }
            throw new HttpException($message . ' (' . $path . ', HTTP ' . $status . ')', 'KT_REQUEST_FAILED', 502);
        }

        return is_array($decoded) ? $decoded : [];
    }

    // KiralamatakvimiController::panelUrl ile ayni kural.
    private function panelUrl(): string
    {
        $url = trim((string) ($this->app['kiralamatakvimi_panel_api_url'] ?? ''));
        if ($url === '') {
            $url = defined('URL') ? trim((string) constant('URL')) : '';
            if ($url === '') {
                throw new HttpException('Panel API adresi tanimli degil (config URL sabiti yok).', 'PANEL_UNREACHABLE', 500);
            }
            if (preg_match('#^https?://#i', $url) !== 1) {
                $url = 'https://' . $url;
            }
        }

        return rtrim($url, '/');
    }

    /**
     * "1;/mesaj" bicimindeki yanittan gosterilecek mesaj.
     *
     * @param string[] $parts
     */
    private function panelMessage(array $parts, string $fallback): string
    {
        $message = trim((string) ($parts[1] ?? ''));

        return $message !== '' ? $message : $fallback;
    }

    /**
     * Kapatmanin tarihlerini ve basligini gunceller.
     *
     * @Post("update")
     */
    public function update(): void
    {
        $doluId = $this->positiveInt('doluId', 'Gecersiz kapatma ID.');
        [$start, $end] = $this->dateRange();
        $title = $this->title();
        $rezTip = $this->closureType();

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $emlakId = $this->requireClosure($pdo, $doluId);
            $this->assertNoOverlap($pdo, $emlakId, $start, $end, $doluId);
            $this->assertRezTipColumn($pdo);
            $pdo->prepare('UPDATE dbo.dolu SET tarih = :tarih, tarih2 = :tarih2, musteri = :musteri, rez_tip = :rez_tip WHERE id = :id')
                ->execute([':tarih' => $start, ':tarih2' => $end, ':musteri' => $title, ':rez_tip' => $rezTip, ':id' => $doluId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->response->success(['message' => 'Kapatma guncellendi']);
    }

    /**
     * Kapatmayi siler.
     *
     * @Post("delete")
     */
    public function delete(): void
    {
        $doluId = $this->positiveInt('doluId', 'Gecersiz kapatma ID.');
        $pdo = $this->db->pdo();
        $this->requireClosure($pdo, $doluId);
        $kayitIdColumn = $this->kayitIdColumn($pdo);
        $ktGuard = $this->hasColumn($pdo, 'dbo.dolu', 'ReservationId') ? ' AND ISNULL(ReservationId, 0) <= 0' : '';
        $pdo->prepare("DELETE FROM dbo.dolu WHERE id = :id AND ISNULL([{$kayitIdColumn}], -1) <= 0{$ktGuard}")->execute([':id' => $doluId]);

        $this->response->success(['message' => 'Kapatma silindi']);
    }

    private function requireClosure(PDO $pdo, int $doluId): int
    {
        $kayitIdColumn = $this->kayitIdColumn($pdo);
        $ktColumn = $this->hasColumn($pdo, 'dbo.dolu', 'ReservationId') ? 'ReservationId' : 'NULL';
        $stmt = $pdo->prepare("SELECT emlak, [{$kayitIdColumn}] AS kayitid, {$ktColumn} AS kt_reservation_id FROM dbo.dolu WITH (UPDLOCK) WHERE id = :id");
        $stmt->execute([':id' => $doluId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new HttpException('Kapatma bulunamadi.', 'NOT_FOUND', 404);
        }
        if ((int) ($row['kayitid'] ?? -1) > 0) {
            throw new HttpException('Rezervasyon kayitlari takvimden degistirilemez.', 'RESERVATION_LOCKED', 409);
        }
        if ((int) ($row['kt_reservation_id'] ?? 0) > 0) {
            throw new HttpException('Kiralama Takvimi kayitlari Kiralama Takvimi\'nden degistirilir.', 'RESERVATION_LOCKED', 409);
        }

        return (int) $row['emlak'];
    }

    // Cikis gunu ayni gun giris olabilir; iptal/silinen/suresi dolan kayitlar cakisma sayilmaz.
    // Sadece rezervasyonlar (kayitid > 0) engeller; manuel kapatmalarin uzerine kapatma yapilabilir.
    private function assertNoOverlap(PDO $pdo, int $emlakId, string $start, string $end, int $exceptId): void
    {
        // NULL durum -99 ile ayrılır; siteye özel silinme kodu (örn. -1) da çakışma sayılmaz.
        $deletedStatus = $this->deletedStatus();
        $kayitIdColumn = $this->kayitIdColumn($pdo);
        // Kiralama Takvimi'nden gelen satir (ReservationId > 0) da rezervasyon gibi engeller.
        $ktOr = $this->hasColumn($pdo, 'dbo.dolu', 'ReservationId') ? ' OR ISNULL(ReservationId, 0) > 0' : '';
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM dbo.dolu WITH (UPDLOCK, HOLDLOCK)
            WHERE emlak = :emlak AND id <> :id AND ISNULL(Durum, -99) NOT IN (2, 4, 5, {$deletedStatus})
              AND (ISNULL([{$kayitIdColumn}], -1) > 0{$ktOr})
              AND tarih < :end AND tarih2 > :start");
        $stmt->execute([':emlak' => $emlakId, ':id' => $exceptId, ':start' => $start, ':end' => $end]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new HttpException('Secilen tarihler bir rezervasyonla cakisiyor.', 'OVERLAP', 409);
        }
    }

    /**
     * @return array{0:string,1:string}
     */
    private function dateRange(): array
    {
        $start = (string) $this->request->input('startDate', '');
        $end = (string) $this->request->input('endDate', '');
        foreach ([$start, $end] as $date) {
            $parsed = \DateTime::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                throw new HttpException('Tarihler YYYY-MM-DD formatinda olmalidir.', 'VALIDATION', 422);
            }
        }
        if ($end <= $start) {
            throw new HttpException('Cikis tarihi giris tarihinden sonra olmalidir.', 'VALIDATION', 422);
        }

        return [$start, $end];
    }

    private function closureType(bool $allowOption = false): string
    {
        $type = (string) $this->request->input('rezTip', 'kapali');
        if ($allowOption && $type === self::OPTION_TYPE) {
            return $type;
        }
        if (!in_array($type, self::CLOSURE_TYPES, true)) {
            throw new HttpException($allowOption ? 'Tip kapali, tadilat veya opsiyon olmalidir.' : 'Tip kapali veya tadilat olmalidir.', 'VALIDATION', 422);
        }

        return $type;
    }

    // Opsiyon suresi (saat), dolu.saat2'ye yazilir.
    private function optionHours(): int
    {
        $hours = filter_var($this->request->input('optionHours', null), FILTER_VALIDATE_INT);
        if ($hours === false || $hours < 1 || $hours > self::OPTION_MAX_HOURS) {
            throw new HttpException('Opsiyon suresi 1 ile ' . self::OPTION_MAX_HOURS . ' saat arasinda olmalidir.', 'VALIDATION', 422);
        }

        return (int) $hours;
    }

    // dolu tablosundaki rezervasyon id kolonu: kayitid, yoksa kayitId.
    private function kayitIdColumn(PDO $pdo): string
    {
        if ($this->kayitIdColumn === null) {
            foreach (['kayitid', 'kayitId'] as $column) {
                if ($this->hasColumn($pdo, 'dbo.dolu', $column)) {
                    $this->kayitIdColumn = $column;
                    break;
                }
            }
            if ($this->kayitIdColumn === null) {
                throw new HttpException('Dolu kayit id kolonu bulunamadi.', 'CONFIG_ERROR', 500);
            }
        }

        return $this->kayitIdColumn;
    }

    private function assertRezTipColumn(PDO $pdo): void
    {
        if (!$this->hasColumn($pdo, 'dbo.dolu', 'rez_tip')) {
            throw new HttpException('dolu.rez_tip kolonu bulunamadi; once setup calistirilmalidir.', 'SETUP_REQUIRED', 409);
        }
    }

    private function title(): string
    {
        $title = trim((string) $this->request->input('title', ''));
        if ($title === '') {
            throw new HttpException('Baslik gereklidir.', 'VALIDATION', 422);
        }

        return function_exists('mb_substr') ? mb_substr($title, 0, 150) : substr($title, 0, 150);
    }

    private function positiveInt(string $key, string $message): int
    {
        $value = filter_var($this->request->input($key), FILTER_VALIDATE_INT);
        if ($value === false || $value <= 0) {
            throw new HttpException($message, 'VALIDATION', 422);
        }

        return $value;
    }

    private function channelColor(string $colorColumn): string
    {
        return $colorColumn === 'NULL' ? 'NULL' : 'color';
    }

    private function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('SELECT CASE WHEN COL_LENGTH(:table, :column) IS NULL THEN 0 ELSE 1 END');
        $stmt->execute([':table' => $table, ':column' => $column]);

        return (int) $stmt->fetchColumn() === 1;
    }

    /**
     * @param array<int,mixed> $values
     */
    private function firstFilled(array $values): string
    {
        foreach ($values as $value) {
            $value = $this->text($value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param mixed $value
     */
    private function text($value): string
    {
        return $value === null ? '' : trim((string) $value);
    }
}
