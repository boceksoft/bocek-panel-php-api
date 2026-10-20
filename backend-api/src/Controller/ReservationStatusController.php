<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use DateTime;
use DateTimeZone;
use PDO;
use PDOException;

/*
 * Rezervasyon durumu kaynağı.
 * Durum, dolu.durum alanında tutulur (dolu.kayitid = kayitlar.id).
 *
 *   GET  /backend-api/reservation-status         -> durum listesi
 *   GET  /backend-api/reservation-status/current -> rezervasyonun şu anki durumu (callback takibi)
 *   POST /backend-api/reservation-status         -> rezervasyonun durumunu değiştirir
 *
 * Panel karşılığı: aktif_pasif.asp (islem=siparis_{durum}) + asp_scripts/KiralamaTakvimiScripts.asp.
 *
 * Kaynak merkezi sistemdir (Kiralama Takvimi). Villa bağlıysa işlem panel API'sine
 * ($domain/api/Reservation/...) gönderilir; rezervasyonun durumu (dolu.durum, opsiyon saati,
 * onay tarihi) burada YAZILMAZ — merkezi sistemin birkaç saniye (5-10 sn) sonra attığı callback
 * (callback.php) yazar. Yanıtta kiralamatakvimi.callback_bekleniyor=true döner; ön yüz
 * /current ile durumun değişmesini takip eder. Bize özel işler (log, kur, tarihler/sonDakika)
 * merkezi sistem "success" dedikten sonra burada yapılır. Bağlı olmayan villalarda her şey yerelde yazılır.
 *
 * Desteklenen: 0 (Onay Bekliyor), 1 (Ödeme Bekliyor / Opsiyona Al), 3 (Onaylandı / Tamamla), 4 (İptal Edildi),
 * 5 (Silindi, panelde de fiziksel silme yok), 6 (Açık Rezervasyon).
 * Mail/SMS henüz gönderilmez.
 *
 * Ayarlar (config/app.local.php) — KiralamatakvimiController ile ortak:
 *   kiralamatakvimi_panel_api_url     Panel API kökü. Boşsa config.php'deki URL sabiti.
 *   kiralamatakvimi_panel_api_headers Panel API'sine eklenecek header'lar.
 */
final class ReservationStatusController extends Controller
{
    /** Durum kodları — ReservationsController'daki durum_text ile aynı. */
    private const STATUSES = [
        0 => 'Onay Bekliyor',
        1 => 'Ödeme Bekliyor',
        2 => 'Süre Doldu',
        3 => 'Onaylandı',
        4 => 'İptal Edildi',
        5 => 'Silindi',
        6 => 'Açık Rezervasyon',
    ];

    /** Bu endpoint üzerinden geçilebilen durumlar (adım adım genişleyecek). */
    private const SUPPORTED_STATUSES = [0, 1, 3, 4, 5, 6];

    /**
     * Zaten bu durumdaysa tekrar geçilemeyen durumlar (panel bu durumlarda butonu gizler).
     * 1 tekrar gönderilebilir: "Opsiyon Değiştir".
     */
    private const NO_REPEAT_STATUSES = [0, 3, 4, 5, 6];

    /** Panelde saat girilmezse kullanılan opsiyon süresi. */
    private const DEFAULT_OPTION_HOURS = 24;

    /**
     * Log kaydının degisiklikler kolonuna JSON olarak yazılan ayrıntılar (eski/yeni durum, opsiyon süresi,
     * Kiralama Takvimi...). update() doldurur, durum metotları ekler.
     *
     * @var array<string,mixed>
     */
    private $logDetails = [];

    /** @var bool|null kullanici_log_kaydi.degisiklikler kolonu var mı (istek başına bir kez bakılır) */
    private $hasLogDetailsColumn = null;

    /** kayitlar.doviz -> [TCMB döviz kodu, genel tablosundaki elle girilen kur kolonu] (inc/Doviz.asp). */
    private const CURRENCIES = [
        'dolar' => ['USD', 'dolar1'],
        'euro' => ['EUR', 'euro1'],
        'pound' => ['GBP', 'sterlin1'],
    ];

    /**
     * Kullanılabilir rezervasyon durumlarını listeler.
     *
     * @Get
     */
    public function index(): void
    {
        $statuses = [];
        foreach (self::STATUSES as $code => $label) {
            $statuses[] = [
                'durum' => $code,
                'durum_text' => $label,
                'destekleniyor' => in_array($code, self::SUPPORTED_STATUSES, true),
            ];
        }

        $this->response->success(['statuses' => $statuses]);
    }

    /**
     * Rezervasyonun şu anki durumunu döner. Merkezi sisteme gönderilen işlemden sonra
     * callback'in durumu güncelleyip güncellemediğini takip etmek için kullanılır.
     *
     * @Get("current")
     * @query id int required Rezervasyon kimliği (kayitlar.id)
     */
    public function current(): void
    {
        $id = (int) $this->request->query('id', 0);
        if ($id <= 0) {
            throw new HttpException('Lütfen geçerli bir rezervasyon ID gönderin.', 'VALIDATION', 422);
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT d.durum, d.saat2, k.saat, k.onaylanmaTarihi
             FROM kayitlar k
             INNER JOIN dolu d ON d.kayitid = k.id
             WHERE k.id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new HttpException('Belirtilen ID ile rezervasyon bulunamadı.', 'NOT_FOUND', 404);
        }

        $durum = $this->normalizeStatus($row['durum']);
        $this->response->success([
            'id' => $id,
            'durum' => $durum,
            'durum_text' => $durum !== null ? (self::STATUSES[$durum] ?? '-') : '-',
            'opsiyon_bitis' => $row['saat'],
            'opsiyon_suresi_saat' => is_numeric(trim((string) $row['saat2'])) ? (float) trim((string) $row['saat2']) : null,
            'onaylanma_tarihi' => $row['onaylanmaTarihi'],
        ]);
    }

    /**
     * Rezervasyonun durumunu değiştirir.
     *
     * Kiralama Takvimi hata kodları: KT_WARNING_RULE (skipRules=true ile tekrar gönderilebilir),
     * KT_SPLIT_REQUIRED (villa başka işletmenin, parçalama isteği gerekir), KT_WHATSAPP_OPTION,
     * KT_NOT_AVAILABLE, KT_CREATE_FAILED, KT_OPTION_FAILED, KT_APPROVE_FAILED, KT_CANCEL_FAILED,
     * KT_RELEASE_FAILED (Onay Bekliyor / Silindi / Açık Rezervasyon'a alırken merkezi sistemdeki kayıt bırakılamadı).
     *
     * @Post
     * @body id int required Rezervasyon kimliği (kayitlar.id)
     * @body durum int required Yeni durum kodu: 0 Onay Bekliyor, 1 Ödeme Bekliyor, 3 Onaylandı, 4 İptal Edildi, 5 Silindi, 6 Açık Rezervasyon
     * @body saat int Opsiyon süresi, sadece durum=1 (varsayılan 24)
     * @body isMinute bool true ise saat dakika olarak yorumlanır, sadece durum=1
     * @body skipRules bool Kiralama Takvimi uyarı kurallarını atla (KT_WARNING_RULE sonrası)
     * @body sendWhatsappGroup bool Kiralama Takvimi bildirimi villanın WhatsApp grubuna gitsin mi (durum=1 ve 3, varsayılan true)
     * @body kullanici_id int İşlemi yapan panel kullanıcısı (log kaydı için)
     * @body kullanici_adi string İşlemi yapan kişinin adı (Natsisa'dan); log açıklamasının sonuna "(Ad)" olarak eklenir
     * @body natsisa_kullanici_id int İşlemi yapan Natsisa kullanıcısı (log ayrıntısına yazılır)
     * @body islem_kodu string Natsisa işlem kodu (log ayrıntısına islemKodu olarak yazılır; bildirimlerle eşleştirme)
     */
    public function update(): void
    {
        $id = (int) $this->request->input('id', 0);
        if ($id <= 0) {
            throw new HttpException('Lütfen geçerli bir rezervasyon ID gönderin.', 'VALIDATION', 422);
        }

        $durumRaw = $this->request->input('durum');
        if (!is_numeric($durumRaw) || !array_key_exists((int) $durumRaw, self::STATUSES)) {
            throw new HttpException('Geçersiz durum kodu.', 'VALIDATION', 422);
        }
        $durum = (int) $durumRaw;
        if (!in_array($durum, self::SUPPORTED_STATUSES, true)) {
            throw new HttpException(
                '"' . self::STATUSES[$durum] . '" durumuna geçiş henüz desteklenmiyor.',
                'NOT_SUPPORTED',
                422
            );
        }

        $pdo = $this->db->pdo();
        $reservation = $this->reservation($pdo, $id);
        if (!$reservation) {
            throw new HttpException('Belirtilen ID ile rezervasyon bulunamadı.', 'NOT_FOUND', 404);
        }

        $oldDurum = $reservation['durum'] !== null ? (int) $reservation['durum'] : null;
        if ($oldDurum === $durum && in_array($durum, self::NO_REPEAT_STATUSES, true)) {
            throw new HttpException(
                'Rezervasyon zaten "' . self::STATUSES[$durum] . '" durumunda.',
                'ALREADY_IN_STATUS',
                409
            );
        }

        $this->logDetails = [
            'kaynak' => $this->natsisaUserId() > 0 || $this->userName() !== '' ? 'natsisa' : 'api',
            'kullaniciAdi' => $this->userName(),
            'natsisaKullaniciId' => $this->natsisaUserId(),
            'eskiDurum' => $oldDurum,
            'yeniDurum' => $durum,
        ];
        // Natsisa işlem kodu: bu işlemin müşteri bildirimleri aynı kodla kaydedilir (işlem geçmişinde gruplama).
        $actionCode = (string) $this->request->input('islem_kodu', '');
        if (preg_match('/^[A-Za-z0-9-]{1,64}$/', $actionCode) === 1) {
            $this->logDetails['islemKodu'] = $actionCode;
        }

        if ($durum === 0) {
            $this->toPending($pdo, $reservation);
        } elseif ($durum === 5) {
            $this->toDeleted($pdo, $reservation);
        } elseif ($durum === 6) {
            $this->toOpen($pdo, $reservation);
        } elseif ($durum === 1) {
            $this->toPaymentPending($pdo, $reservation);
        } elseif ($durum === 3) {
            $this->toCompleted($pdo, $reservation);
        } else {
            $this->toCancelled($pdo, $reservation);
        }
    }

    /**
     * Onay Bekliyor. Panel: KiralamaTakvimiScripts.asp siparis_0 + aktif_pasif.asp siparis_0.
     *
     * Paneldeki hata (bilinçli düzeltildi): Bağlı villada merkezi sistemle eşleşme varsa panel
     * Reservation/SetStatus (callbackParam=1) çağırıyor; bu merkezi sistemin "cancel" ucuna gidiyor ve
     * gelen CancelReservation callback'i callbackParam'a bakmadan dolu.durum=4 yazıyordu. Yani
     * "Onay Bekliyor'a al" rezervasyonu yerelde İptal'e çekiyordu.
     *
     * Burada: merkezi sistemde kayıt yine bırakılır (tarihler serbest kalır), ardından eşleşme
     * (KiralamaTakvimi.Response + dolu.ReservationId) siparis_6'daki gibi silinir. Böylece sonradan gelen
     * CancelReservation callback'i bu rezervasyonu bulamaz; durum 0 hemen yerelde yazılır. Rezervasyon
     * tekrar opsiyona alınır/onaylanırsa merkezi sistemde yeni kayıt açılır (createOnCalendar).
     */
    private function toPending(PDO $pdo, array $reservation): void
    {
        $this->toReleased($pdo, $reservation, 0, '1', ' numaralı rezervasyonu onay bekliyor durumuna aldı.');
    }

    /**
     * Silindi (soft delete). Panel: sil.asp islem=siparis + KiralamaTakvimiScripts.asp cacheIslem="sil".
     * Panel de kaydı fiziksel silmez, dolu.durum=5 yapar. Bazı sitelerde silinme kodu -1'dir
     * (ayar: reservation_deleted_status); setStatus doğru kodu yazar.
     *
     * Paneldeki hata (bilinçli düzeltildi, toPending ile aynı): Eşleşme varsa panel SetStatus
     * (callbackParam=0) çağırıp durumu callback'e bırakıyordu; gelen CancelReservation callback'i
     * dolu.durum=4 yazdığı için "Sil" rezervasyonu İptal'e çekiyordu ve eşleşme silinmiyordu.
     * Burada kayıt merkezi sistemde bırakılır, eşleşme silinir ve durum 5 hemen yerelde yazılır.
     */
    private function toDeleted(PDO $pdo, array $reservation): void
    {
        $this->toReleased($pdo, $reservation, 5, '0', ' numaralı rezervasyonu sildi.');
    }

    /**
     * Açık Rezervasyon. Panel: KiralamaTakvimiScripts.asp siparis_6 + aktif_pasif.asp siparis_6.
     * Panel ile aynı: eşleşme varsa merkezi sistemde kayıt bırakılır (SetStatus, callbackParam=7),
     * eşleşme silinir ve durum 6 yerelde yazılır.
     */
    private function toOpen(PDO $pdo, array $reservation): void
    {
        $this->toReleased($pdo, $reservation, 6, '7', ' numaralı rezervasyonu açık rezervasyon durumuna aldı.');
    }

    /**
     * Merkezi sistemdeki kaydı bırakıp rezervasyonu yerelde $durum'a alır (0 ve 6 için ortak).
     *
     * Panelin ReservationStatus alt yordamı gibi, sadece eşleşme (KiralamaTakvimi.Response) varsa merkezi
     * sisteme gider; villa bağlantısına bakmaz. Callback beklenmez, durum hemen yazılır.
     *
     * @param string $callbackParam Panelin SetStatus'a gönderdiği değer (0 için "1", 5 için "0", 6 için "7")
     */
    private function toReleased(PDO $pdo, array $reservation, int $durum, string $callbackParam, string $logText): void
    {
        $id = (int) $reservation['id'];
        $oldDurum = $reservation['durum'] !== null ? (int) $reservation['durum'] : null;

        $calendar = $this->calendarResult();
        if ($this->calendarHome($pdo, (int) $reservation['evid'])) {
            $calendar['bagli'] = true;
        }

        $mapping = $this->calendarResponse($pdo, $id);
        if ($mapping) {
            $reservationId = (int) $mapping['ReservationId'];
            $raw = $this->panelApi('GET', 'Reservation/SetStatus', [
                'reservationId' => $reservationId,
                'estateId' => (int) $mapping['EstateId'],
                'callbackParam' => $callbackParam,
            ]);
            if (trim($raw) !== 'success') {
                throw new HttpException(
                    trim($raw) !== '' ? trim($raw) : 'Kiralama Takvimi kaydı bırakılamadı.',
                    'KT_RELEASE_FAILED',
                    502
                );
            }
            $calendar['islem'] = 'release';
            $calendar['reservation_id'] = $reservationId;
        }

        // Panel (siparis_6): eşleşme silinir, dolu.ReservationId her durumda boşaltılır. 0 ve 5'te sadece kayıt
        // bırakıldıysa boşaltılır (eşleşmesiz, merkezi sistemden gelen kayıtların bağı korunur). Sonradan gelen
        // CancelReservation callback'i ReservationId ile eşleştiği için bırakılan rezervasyonu artık bulamaz.
        $clearLink = $mapping || $durum === 6;

        $this->transaction($pdo, function () use ($pdo, $id, $durum, $logText, $clearLink) {
            if ($clearLink) {
                $stmt = $pdo->prepare('DELETE FROM KiralamaTakvimi.Response WHERE kayitlarId = :id');
                $stmt->execute([':id' => $id]);

                $stmt = $pdo->prepare('UPDATE dolu SET ReservationId = NULL WHERE kayitid = :id');
                $stmt->execute([':id' => $id]);
            }

            $this->setStatus($pdo, $id, $durum);
            $this->log($pdo, $id, $id . $logText);
        });

        $this->respond($id, $oldDurum, $durum, [
            'kiralamatakvimi' => $calendar,
        ]);
    }

    /**
     * Ödeme Bekliyor (opsiyon). Panel: KiralamaTakvimiScripts.asp siparis_1 + aktif_pasif.asp siparis_1.
     */
    private function toPaymentPending(PDO $pdo, array $reservation): void
    {
        $id = (int) $reservation['id'];
        $oldDurum = $reservation['durum'] !== null ? (int) $reservation['durum'] : null;

        $saat = (int) $this->request->input('saat', self::DEFAULT_OPTION_HOURS);
        if ($saat <= 0) {
            $saat = self::DEFAULT_OPTION_HOURS;
        }
        $isMinute = $this->flag('isMinute');
        $skipRules = $this->flag('skipRules');
        $sendGroup = $this->sendWhatsappGroup();

        $calendar = $this->calendarResult();

        $calendarHome = $this->calendarHome($pdo, (int) $reservation['evid']);
        if ($calendarHome) {
            $calendar['bagli'] = true;
            $estateId = (int) $calendarHome['EstateId'];
            $mapping = $this->calendarResponse($pdo, $id);
            $alreadyOption = $mapping && $oldDurum === 1;

            if (!$mapping) {
                // Merkezi sistemde henüz yok: önce oluştur (panelde bu yolda müsaitlik kontrolü yapılmaz).
                $mapping = $this->createOnCalendar($pdo, $reservation, $estateId, 0);
            } elseif (!$skipRules && !$alreadyOption) {
                $this->checkOnCalendar($reservation, $estateId);
            }

            $reservationId = (int) $mapping['ReservationId'];
            $mappedEstateId = (int) $mapping['EstateId'];

            // Panel, yerelde zaten opsiyondaysa ("Opsiyon Değiştir") merkezi sisteme hiç gitmiyordu; bu yüzden
            // yerel ile merkezi sistem ayrışınca bir daha düzelmiyordu. Burada önce opsiyon süresi uzatılır,
            // merkezi sistemde opsiyonda değilse (uzatma reddedilirse) yeniden opsiyona alınır.
            $calendar['islem'] = 'option';
            if ($alreadyOption && !$isMinute && $this->extendOptionOnCalendar($reservationId, $mappedEstateId, $saat, $sendGroup)) {
                $calendar['islem'] = 'option_extend';
            } else {
                $this->optionOnCalendar($reservationId, $mappedEstateId, $saat, $isMinute, $sendGroup);
            }
            $calendar['gonderildi'] = true;
            $calendar['whatsapp_grup_bildirimi'] = $sendGroup;
            $calendar['reservation_id'] = $reservationId;
        }

        // Merkezi sisteme gittiyse durum ve opsiyon bitişini callback (OptionReservation) yazar.
        $optionEndText = null;
        if (!$calendar['gonderildi']) {
            $optionEnd = $this->now();
            $optionEnd->modify('+' . $saat . ($isMinute ? ' minutes' : ' hours'));
            $optionEndText = $optionEnd->format('d.m.Y H:i:s');
        }

        // Opsiyon süresi (saat). Panel yerel opsiyonda dolu.saat2'yi yazmıyordu; bağlı villada callback
        // (OptionReservation) yazar. Kalan süre/oran hesabı (ön yüz, opsiyonlar.asp) bu alanı kullanır.
        $optionHoursText = (string) max(1, $isMinute ? (int) ceil($saat / 60) : $saat);

        $this->logDetails['opsiyonSure'] = $saat;
        $this->logDetails['opsiyonSureBirim'] = $isMinute ? 'dakika' : 'saat';
        $this->logDetails['opsiyonBitis'] = $optionEndText;
        $this->logDetails['kiralamaTakvimi'] = $calendar['gonderildi'] ? (string) $calendar['islem'] : null;

        $this->transaction($pdo, function () use ($pdo, $id, $optionEndText, $optionHoursText) {
            if ($optionEndText !== null) {
                $this->setStatus($pdo, $id, 1);

                $stmt = $pdo->prepare('UPDATE kayitlar SET saat = :saat WHERE id = :id');
                $stmt->execute([':saat' => $optionEndText, ':id' => $id]);

                $stmt = $pdo->prepare('UPDATE dolu SET saat2 = :saat2 WHERE kayitid = :id');
                $stmt->execute([':saat2' => $optionHoursText, ':id' => $id]);
            }

            $this->log($pdo, $id, $id . ' numaralı rezervasyonu ödeme bekliyor durumuna aldı.');
        });

        $this->respond($id, $oldDurum, 1, [
            'opsiyon_bitis' => $optionEndText,
            'kiralamatakvimi' => $calendar,
        ]);
    }

    /**
     * Onaylandı / Tamamla. Panel: KiralamaTakvimiScripts.asp siparis_3 + aktif_pasif.asp siparis_3.
     */
    private function toCompleted(PDO $pdo, array $reservation): void
    {
        $id = (int) $reservation['id'];
        $oldDurum = $reservation['durum'] !== null ? (int) $reservation['durum'] : null;
        $skipRules = $this->flag('skipRules');

        $calendar = $this->calendarResult();

        $calendarHome = $this->calendarHome($pdo, (int) $reservation['evid']);
        if ($calendarHome) {
            $calendar['bagli'] = true;
            $estateId = (int) $calendarHome['EstateId'];
            $mapping = $this->calendarResponse($pdo, $id);

            if (!$mapping) {
                // Merkezi sistemde henüz yok: önce oluştur (panelde bu yolda müsaitlik kontrolü yapılmaz).
                $mapping = $this->createOnCalendar($pdo, $reservation, $estateId, 3);
            } elseif (!$skipRules && $oldDurum !== 1) {
                // Panel: opsiyondaki (1) rezervasyon onaylanırken müsaitlik kontrolü yapılmaz,
                // tarihler zaten bu rezervasyona ayrılmıştır.
                $this->checkOnCalendar($reservation, $estateId);
            }

            $reservationId = (int) $mapping['ReservationId'];
            $sendGroup = $this->sendWhatsappGroup();
            $this->calendarAction(
                'Reservation/Approve',
                $reservationId,
                (int) $mapping['EstateId'],
                'KT_APPROVE_FAILED',
                'Kiralama Takvimi onay işlemi başarısız.',
                ['sendWhatsappGroup' => $sendGroup ? '1' : '0']
            );
            $calendar['islem'] = 'approve';
            $calendar['gonderildi'] = true;
            $calendar['whatsapp_grup_bildirimi'] = $sendGroup;
            $calendar['reservation_id'] = $reservationId;
        }

        $warnings = [];
        $kur = $this->currencyRate($pdo, (string) $reservation['doviz'], (int) $reservation['site']);
        if ($kur === null) {
            $warnings[] = 'Döviz kuru alınamadı, kayitlar.kur güncellenmedi.';
        }

        $now = $this->now();
        // Panel: SaatGuncelle -> now() + 1 gün. Callback bu alanı onayda yazmadığı için her durumda burada.
        $saatText = (clone $now)->modify('+1 day')->format('d.m.Y H:i:s');
        // Panel: sqldate(date()) -> yyyy-mm-dd. Merkezi sisteme gittiyse durum ve onay tarihini callback
        // (ApproveReservation) yazar.
        $approvedOn = $calendar['gonderildi'] ? null : $now->format('Y-m-d');

        $this->transaction($pdo, function () use ($pdo, $id, $reservation, $kur, $saatText, $approvedOn) {
            $stmt = $pdo->prepare('UPDATE kayitlar SET saat = :saat WHERE id = :id');
            $stmt->execute([':saat' => $saatText, ':id' => $id]);

            if ($approvedOn !== null) {
                $this->setStatus($pdo, $id, 3);

                $stmt = $pdo->prepare('UPDATE kayitlar SET onaylanmaTarihi = :onaylanmaTarihi WHERE id = :id');
                $stmt->execute([':onaylanmaTarihi' => $approvedOn, ':id' => $id]);
            }

            if ($kur !== null) {
                $stmt = $pdo->prepare('UPDATE kayitlar SET kur = :kur WHERE id = :id');
                $stmt->execute([':kur' => $kur, ':id' => $id]);
            }

            $this->removeOverlappingOffers($pdo, $reservation);

            $this->log($pdo, $id, $id . ' numaralı rezervasyonu tamamlandı durumuna aldı.');
        });

        $this->respond($id, $oldDurum, 3, [
            'onaylanma_tarihi' => $approvedOn,
            'kur' => $kur,
            'uyarilar' => $warnings,
            'kiralamatakvimi' => $calendar,
        ]);
    }

    /**
     * İptal. Panel: KiralamaTakvimiScripts.asp siparis_4 + aktif_pasif.asp siparis_4.
     * Merkezi sistemle eşleşmiş rezervasyonda durumu callback (CancelReservation) yazar — panel de böyle.
     */
    private function toCancelled(PDO $pdo, array $reservation): void 
    {
        $id = (int) $reservation['id'];
        $oldDurum = $reservation['durum'] !== null ? (int) $reservation['durum'] : null;

        $calendar = $this->calendarResult();

        // Panel: sadece merkezi sistemle eşleşme (KiralamaTakvimi.Response) varsa Cancel gönderilir.
        $mapping = $this->calendarResponse($pdo, $id);
        if ($mapping) {
            $calendar['bagli'] = true;
            $reservationId = (int) $mapping['ReservationId'];
            $this->calendarAction('Reservation/Cancel', $reservationId, (int) $mapping['EstateId'], 'KT_CANCEL_FAILED', 'Kiralama Takvimi iptal işlemi başarısız.');
            $calendar['islem'] = 'cancel';
            $calendar['gonderildi'] = true;
            $calendar['reservation_id'] = $reservationId;
        } elseif ($this->calendarHome($pdo, (int) $reservation['evid'])) {
            $calendar['bagli'] = true;
        }

        $sent = $calendar['gonderildi'];
        $this->transaction($pdo, function () use ($pdo, $id, $sent) {
            if (!$sent) {
                $this->setStatus($pdo, $id, 4);
            }
            $this->log($pdo, $id, $id . ' numaralı rezervasyonu iptal durumuna aldı.');
        });

        $this->respond($id, $oldDurum, 4, [
            'kiralamatakvimi' => $calendar,
        ]);
    }

    /**
     * Panel: /api/Reservation/Create. Başarılıysa eşleşme (KiralamaTakvimi.Response) hemen yazılır;
     * sonraki adım hata verse bile tekrar denemede merkezi sistemde mükerrer kayıt açılmaz.
     *
     * @param int $debug KiralamaTakvimi.Response.debug — panelde kaydın hangi yoldan açıldığını gösterir
     */
    private function createOnCalendar(PDO $pdo, array $reservation, int $estateId, int $debug): array 
    {
        $raw = $this->panelApi('POST', 'Reservation/Create', [
            'estateId' => $estateId,
            'checkInDate' => (string) $reservation['tarih'],
            'checkOutDate' => (string) $reservation['tarih2'],
            'customerEmail' => (string) $reservation['email'],
            'customerFullName' => (string) $reservation['musteri'],
            'customerPhone' => (string) $reservation['telefon'],
            'adultGuestCount' => (string) $reservation['yetiskin'],
            'childGuestCount' => (string) $reservation['cocuk'],
        ]);

        // Başarılı: "0;/{ReservationId};/{EstateId}", hata: "1;/{mesaj}"
        $parts = explode(';/', $raw);
        if ($parts[0] !== '0' || !isset($parts[1], $parts[2]) || (int) $parts[1] <= 0) {
            throw new HttpException(
                $this->calendarMessage($parts, 'Rezervasyon Kiralama Takvimi\'nde oluşturulamadı.'),
                'KT_CREATE_FAILED',
                502
            );
        }

        $mapping = [
            'ReservationId' => (int) $parts[1],
            'EstateId' => (int) $parts[2],
        ];
        $id = (int) $reservation['id'];

        $stmt = $pdo->prepare(
            'INSERT INTO KiralamaTakvimi.Response (kayitlarId, ReservationId, EstateId, debug)
             VALUES (:id, :reservationId, :estateId, :debug)'
        );
        $stmt->execute([
            ':id' => $id,
            ':reservationId' => $mapping['ReservationId'],
            ':estateId' => $mapping['EstateId'],
            ':debug' => $debug,
        ]);

        $stmt = $pdo->prepare('UPDATE dolu SET ReservationId = :reservationId WHERE kayitid = :id');
        $stmt->execute([':reservationId' => $mapping['ReservationId'], ':id' => $id]);

        return $mapping;
    }

    /**
     * Panel: /api/Reservation/CheckFromPanel (ReservationCheck). Sadece "1" ile başlayan yanıt işlemi durdurur.
     */
    private function checkOnCalendar(array $reservation, int $estateId): void
    {
        $raw = $this->panelApi('POST', 'Reservation/CheckFromPanel', [
            'estateId' => $estateId,
            'checkInDate' => (string) $reservation['tarih'],
            'checkOutDate' => (string) $reservation['tarih2'],
            'ReservationId' => (string) $reservation['ReservationId'],
        ]);

        // "{0|1};/{Status};/{StatusDescription};/{bizim işletme mi: 1|''}"
        // WhatsApp: "1;/WhatsappOption;/{rezId};/{waRezId};/{tarih1};/{tarih2};/{numara}"
        $parts = explode(';/', $raw);
        if ($parts[0] !== '1') {
            return;
        }

        $status = $parts[1] ?? '';
        if ($status === 'WhatsappOption') {
            throw new HttpException(
                'Bu tarihlerde WhatsApp üzerinden oluşturulmuş bir opsiyon var ('
                    . ($parts[4] ?? '') . ' - ' . ($parts[5] ?? '') . ', ' . ($parts[6] ?? '') . ').',
                'KT_WHATSAPP_OPTION',
                409
            );
        }
        if ($status === 'AvailableHasWarningRule') {
            $isOwnerEstablishment = ($parts[3] ?? '') === '1';
            throw new HttpException(
                $this->calendarMessage($parts, 'Bu tarihler için Kiralama Takvimi uyarı kuralı var.', 2),
                $isOwnerEstablishment ? 'KT_WARNING_RULE' : 'KT_SPLIT_REQUIRED',
                409
            );
        }

        throw new HttpException(
            $this->calendarMessage($parts, 'Villa bu tarihlerde Kiralama Takvimi\'nde müsait değil.', 2),
            'KT_NOT_AVAILABLE',
            409
        );
    }

    /**
     * Panel: /api/Reservation/Option (ReservationStatus id,"Option"). Başarılı yanıt düz "success" metnidir.
     */
    private function optionOnCalendar(int $reservationId, int $estateId, int $saat, bool $isMinute, bool $sendGroup = true): void
    {
        $raw = $this->panelApi('GET', 'Reservation/Option', [
            'reservationId' => $reservationId,
            'estateId' => $estateId,
            'optionHours' => $saat,
            'callbackParam' => '',
            'isMinute' => $isMinute ? '1' : '',
            'sendWhatsappGroup' => $sendGroup ? '1' : '0',
        ]);

        if (trim($raw) !== 'success') {
            throw new HttpException(
                trim($raw) !== '' ? trim($raw) : 'Kiralama Takvimi opsiyon işlemi başarısız.',
                'KT_OPTION_FAILED',
                502
            );
        }
    }

    /**
     * Panel: /api/Reservation/OptionPeriodExtend (opsiyon_uzat). Sadece saat destekler.
     * Başarılıysa true; reddedilirse false (çağıran Option'a düşer).
     */
    private function extendOptionOnCalendar(int $reservationId, int $estateId, int $saat, bool $sendGroup = true): bool
    {
        $raw = $this->panelApi('GET', 'Reservation/OptionPeriodExtend', [
            'reservationId' => $reservationId,
            'estateId' => $estateId,
            'optionHours' => $saat,
            'sendWhatsappGroup' => $sendGroup ? '1' : '0',
        ]);

        return trim($raw) === 'success';
    }

    /**
     * Panel: /api/Reservation/{Approve|Cancel} (ReservationStatus id,"Approve"|"Cancel").
     * Başarılı yanıt düz "success" metnidir; değilse metin hata mesajıdır.
     */
    /**
     * @param array<string,string> $extra Panel API'sine eklenecek ek parametreler (örn. sendWhatsappGroup)
     */
    private function calendarAction(string $path, int $reservationId, int $estateId, string $errorCode, string $fallback, array $extra = []): void
    {
        $raw = $this->panelApi('GET', $path, array_merge([
            'reservationId' => $reservationId,
            'estateId' => $estateId,
        ], $extra));

        if (trim($raw) !== 'success') {
            throw new HttpException(trim($raw) !== '' ? trim($raw) : $fallback, $errorCode, 502);
        }
    }

    /**
     * Panel (aktif_pasif.asp siparis_3): konaklama günlerine denk gelen villa fiyat/özel tarih
     * (tarihler) ve son dakika (sonDakika) kayıtlarını siler. Panel her günü tek tek dolaşır;
     * burada aynı sonuç tek sorguyla, tarih aralığı kesişimiyle alınır (giriş ve çıkış günü dahil).
     */
    private function removeOverlappingOffers(PDO $pdo, array $reservation): void
    {
        $start = (string) ($reservation['rez_tarihi'] ?? '');
        $end = (string) ($reservation['gelecek_tarih'] ?? '');
        $homeId = (int) $reservation['evid'];
        if ($start === '' || $end === '' || $homeId <= 0) {
            return;
        }

        $stmt = $pdo->prepare(
            "DELETE FROM tarihler
             WHERE islem = 'emlak' AND islem_id = :homeId
               AND TRY_CONVERT(date, tarih1, 104) <= :endDate
               AND TRY_CONVERT(date, tarih2, 104) >= :startDate"
        );
        $stmt->execute([':homeId' => $homeId, ':startDate' => $start, ':endDate' => $end]);

        $stmt = $pdo->prepare(
            'DELETE FROM sonDakika
             WHERE islem_id = :homeId
               AND TRY_CONVERT(date, tarih1, 104) <= :endDate
               AND TRY_CONVERT(date, tarih2, 104) >= :startDate'
        );
        $stmt->execute([':homeId' => $homeId, ':startDate' => $start, ':endDate' => $end]);
    }

    /**
     * Rezervasyonun dövizine göre güncel kur (panel: inc/Doviz.asp).
     * genel.dovizaktif açıksa elle girilen kur (dolar1/euro1/sterlin1), değilse TCMB ForexBuying.
     * Belirlenemezse null (kur güncellenmez, onay işlemi durmaz).
     */
    private function currencyRate(PDO $pdo, string $doviz, int $site)
    {
        $doviz = strtolower(trim($doviz));
        if ($doviz === 'tl') {
            return '1';
        }
        if (!isset(self::CURRENCIES[$doviz])) {
            return null;
        }
        list($code, $column) = self::CURRENCIES[$doviz];

        $settings = $this->siteSettings($pdo, $site);
        if ($settings && !empty($settings['dovizaktif'])) {
            $rate = $settings[$column] ?? null;
        } else {
            $rate = $this->tcmbRate($code);
        }

        $rate = str_replace(',', '.', trim((string) $rate));

        return is_numeric($rate) && (float) $rate > 0 ? $rate : null;
    }

    /**
     * Panelin "ayarlar" kaydı: genel{dbtable} (site 1 -> genel, site 2 -> genel_s2 ...).
     *
     * @return array<string,mixed>|false
     */
    private function siteSettings(PDO $pdo, int $site)
    {
        $suffixes = (array) ($this->app['site_column_suffixes'] ?? []);
        $suffix = (string) ($suffixes[$site] ?? '');
        if (preg_match('/^[A-Za-z0-9_]*$/', $suffix) !== 1) {
            $suffix = '';
        }

        try {
            return $pdo->query('SELECT TOP 1 * FROM genel' . $suffix)->fetch();
        } catch (PDOException $e) {
            if ($suffix === '') {
                return false;
            }

            return $pdo->query('SELECT TOP 1 * FROM genel')->fetch();
        }
    }

    /**
     * TCMB günlük kurlar (https://www.tcmb.gov.tr/kurlar/today.xml) -> ForexBuying.
     *
     * @return string|null
     */
    private function tcmbRate(string $code)
    {
        if (!function_exists('simplexml_load_string')) {
            return null;
        }

        $curl = curl_init('https://www.tcmb.gov.tr/kurlar/today.xml');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($curl);
        curl_close($curl);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($raw);
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            return null;
        }

        foreach ($xml->Currency as $currency) {
            if ((string) $currency['CurrencyCode'] === $code) {
                return (string) $currency->ForexBuying;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|false
     */
    private function reservation(PDO $pdo, int $id)
    {
        $stmt = $pdo->prepare(
            'SELECT k.id, k.evid, k.site, k.doviz, k.email, k.musteri, k.telefon, k.yetiskin, k.cocuk,
                    CONVERT(varchar(10), k.rez_tarihi, 23) AS rez_tarihi,
                    CONVERT(varchar(10), k.gelecek_tarih, 23) AS gelecek_tarih,
                    d.durum, d.ReservationId,
                    CONVERT(varchar(10), d.tarih, 23) AS tarih,
                    CONVERT(varchar(10), d.tarih2, 23) AS tarih2
             FROM kayitlar k
             INNER JOIN dolu d ON d.kayitid = k.id
             WHERE k.id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row) {
            // Siteye özel silinme kodu (örn. -1) burada 5'e çevrilir; akışın geri kalanı 5 ile çalışır.
            $row['durum'] = $this->normalizeStatus($row['durum']);
        }

        return $row;
    }

    /**
     * @return array<string,mixed>|false
     */
    private function calendarHome(PDO $pdo, int $homesId)
    {
        if ($homesId <= 0) {
            return false;
        }
        $stmt = $pdo->prepare('SELECT TOP 1 EstateId FROM KiralamaTakvimi.CalendarHomes WHERE homesId = :id');
        $stmt->execute([':id' => $homesId]);

        return $stmt->fetch();
    }

    /**
     * @return array<string,mixed>|false
     */
    private function calendarResponse(PDO $pdo, int $id)
    {
        $stmt = $pdo->prepare(
            'SELECT TOP 1 ReservationId, EstateId FROM KiralamaTakvimi.Response WHERE kayitlarId = :id'
        );
        $stmt->execute([':id' => $id]);

        return $stmt->fetch();
    }

    // Panel: DurumDegistir(kayitid, durum)
    private function setStatus(PDO $pdo, int $id, int $durum): void
    {
        // Silindi (5) veritabanına siteye özel kodla yazılır (reservation_deleted_status, örn. -1).
        $value = $durum === 5 ? $this->deletedStatus() : $durum;
        $stmt = $pdo->prepare('UPDATE dolu SET durum = :durum WHERE kayitid = :id');
        $stmt->execute([':durum' => $value, ':id' => $id]);
    }

    // Panel: kullaniciLogKaydet("rezervasyon", id, aciklama)
    // Natsisa'dan gelen işlemde kişinin adı açıklamanın sonuna "(Ad)" olarak eklenir (panelde kullanici_id 0
    // olduğundan ad görünmez). Ayrıntılar degisiklikler kolonuna JSON yazılır; kolon yoksa sadece açıklama yazılır.
    private function log(PDO $pdo, int $id, string $message): void
    {
        $userName = $this->userName();
        if ($userName !== '') {
            $message .= ' (' . $userName . ')';
        }

        $params = [
            ':islmId' => (string) $id,
            ':aciklama' => $message,
            ':kullaniciId' => (int) $this->request->input('kullanici_id', 0),
        ];

        $details = null;
        if ($this->logDetails !== [] && $this->hasLogDetailsColumn($pdo)) {
            $json = json_encode($this->logDetails, JSON_UNESCAPED_UNICODE);
            $details = $json === false ? null : $json;
        }

        if ($details !== null) {
            $stmt = $pdo->prepare(
                "INSERT INTO kullanici_log_kaydi (islm, islm_id, aciklama, kullanici_id, degisiklikler)
                 VALUES ('rezervasyon', :islmId, :aciklama, :kullaniciId, :degisiklikler)"
            );
            $params[':degisiklikler'] = $details;
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO kullanici_log_kaydi (islm, islm_id, aciklama, kullanici_id)
                 VALUES ('rezervasyon', :islmId, :aciklama, :kullaniciId)"
            );
        }
        $stmt->execute($params);
    }

    /** Natsisa'dan gelen işlemi yapan kişinin adı (en fazla 100 karakter, tek satır). */
    private function userName(): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $this->request->input('kullanici_adi', '')) ?? '');
        return mb_substr($name, 0, 100);
    }

    private function natsisaUserId(): int
    {
        return max(0, (int) $this->request->input('natsisa_kullanici_id', 0));
    }

    private function hasLogDetailsColumn(PDO $pdo): bool
    {
        if ($this->hasLogDetailsColumn === null) {
            $stmt = $pdo->query("SELECT COL_LENGTH('dbo.kullanici_log_kaydi', 'degisiklikler') AS len");
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            $this->hasLogDetailsColumn = is_array($row) && $row['len'] !== null;
        }

        return $this->hasLogDetailsColumn;
    }

    private function transaction(PDO $pdo, callable $work): void
    {
        $pdo->beginTransaction();
        try {
            $work();
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param int|null $oldDurum
     */
    private function respond(int $id, $oldDurum, int $durum, array $extra): void
    {
        // Merkezi sisteme gittiyse yerel durum henüz değişmedi; callback birkaç saniye içinde yazar.
        $pending = !empty($extra['kiralamatakvimi']['gonderildi']);
        if ($pending) {
            $extra['kiralamatakvimi']['callback_bekleniyor'] = true;
        }
        $currentDurum = $pending ? $oldDurum : $durum;

        $this->response->success(array_merge([
            'id' => $id,
            'eski_durum' => $oldDurum,
            'eski_durum_text' => $oldDurum !== null ? (self::STATUSES[$oldDurum] ?? '-') : '-',
            'durum' => $durum,
            'durum_text' => self::STATUSES[$durum],
            'guncel_durum' => $currentDurum,
            'guncel_durum_text' => $currentDurum !== null ? (self::STATUSES[$currentDurum] ?? '-') : '-',
        ], $extra));
    }

    private function calendarResult(): array
    {
        return [
            'bagli' => false,
            'gonderildi' => false,
            'callback_bekleniyor' => false,
            'islem' => null,
            'reservation_id' => null,
            // Merkezi sisteme iletilen WhatsApp grubu bildirimi tercihi (sadece opsiyon/onayda dolar).
            'whatsapp_grup_bildirimi' => null,
        ];
    }

    private function now(): DateTime
    {
        return new DateTime('now', new DateTimeZone('Europe/Istanbul'));
    }

    /**
     * Panel API'sine (ör. https://www.site.com/api/Reservation/Option) istek atar, ham metin yanıtı döner.
     * Bu uçlar JSON değil "0;/..." veya "success" gibi düz metin döndürür.
     */
    private function panelApi(string $method, string $path, array $data): string
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
                'Kiralama Takvimi (panel API) adresine ulaşılamadı.' . ($this->app['debug'] ? ' | ' . $url . ' | ' . $error : ''),
                'PANEL_UNREACHABLE',
                502
            );
        }

        return trim((string) $raw);
    }

    // KiralamatakvimiController::panelUrl ile aynı kural.
    private function panelUrl(): string
    {
        $url = trim((string) ($this->app['kiralamatakvimi_panel_api_url'] ?? ''));
        if ($url === '') {
            $url = defined('URL') ? trim((string) constant('URL')) : '';
            if ($url === '') {
                throw new HttpException('Panel API adresi tanımlı değil (config URL sabiti yok).', 'PANEL_UNREACHABLE', 500);
            }
            if (preg_match('#^https?://#i', $url) !== 1) {
                $url = 'https://' . $url;
            }
        }

        return rtrim($url, '/');
    }

    /**
     * "1;/mesaj" biçimindeki yanıttan kullanıcıya gösterilecek mesajı seçer.
     *
     * @param string[] $parts
     */
    private function calendarMessage(array $parts, string $fallback, int $index = 1): string
    {
        $message = trim((string) ($parts[$index] ?? ''));
        if ($message === '' && $index !== 1) {
            $message = trim((string) ($parts[1] ?? ''));
        }

        return $message !== '' ? $message : $fallback;
    }

    /**
     * Kiralama Takvimi işlem bildirimi villanın WhatsApp grubuna gönderilsin mi? Gönderimi merkezi sistem yapar;
     * biz sadece sendWhatsappGroup=1/0 iletiriz. Parametre gelmezse true (önceki davranış).
     */
    private function sendWhatsappGroup(): bool
    {
        $raw = $this->request->input('sendWhatsappGroup');
        if ($raw === null || $raw === '') {
            return true;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    private function flag(string $name): bool
    {
        return filter_var($this->request->input($name, false), FILTER_VALIDATE_BOOLEAN);
    }
}
