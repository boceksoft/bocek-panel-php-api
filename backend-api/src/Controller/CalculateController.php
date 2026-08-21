<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use DateTime;
use PDO;

/*
 * Secili tarih araligi icin villa fiyat hesaplama kaynagi.
 *   GET /backend-api/calculate?EntityId=123&start=2026-08-10&end=2026-08-15
 */
final class CalculateController extends Controller
{
    /**
     * Villa fiyatini hesaplar.
     *
     * @Get
     * @query EntityId int required Villa kimligi
     * @query start string required Giris tarihi (Y-m-d / d.m.Y)
     * @query end string required Cikis tarihi (Y-m-d / d.m.Y)
     * @query PromotionCode string Promosyon kodu
     * @query pool_fee bool Havuz isitma ucreti eklensin mi
     * @query selectedServices string Secili ekstra servis id listesi
     * @query site int Site kimligi
     */
    public function index(): void
    {
        $entityId = (int) $this->configuredParam('entity_id', ['EntityId', 'id'], 0);
        $start = $this->normalizeDate((string) $this->configuredParam('start', ['start'], ''));
        $end = $this->normalizeDate((string) $this->configuredParam('end', ['end'], ''));

        if ($entityId <= 0) {
            throw new HttpException('EntityId belirtilmedi.', 'VALIDATION', 422);
        }
        if ($start === null || $end === null) {
            throw new HttpException('Tarih formati gecersiz. Y-m-d veya d.m.Y kullanin.', 'VALIDATION', 422);
        }
        if ($end <= $start) {
            throw new HttpException('end tarihi start tarihinden buyuk olmali.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();
        $site = $this->siteId();
        $calendarHome = $this->calendarHome($pdo, $entityId);
        $availability = $this->localAvailability($pdo, $entityId, $start, $end, $calendarHome);

        $bookableDirectly = false;
        if ($calendarHome
            && $availability['Status'] === 'Available'
            && !empty($calendarHome['BookableDirectly'])
        ) {
            $bookableDirectly = true;
        }

        $json = $this->calculatePrice(
            $pdo,
            $start,
            $end,
            $entityId,
            (string) $this->param('PromotionCode', ''),
            $calendarHome,
            $site
        );

        if (!empty($json['success'])) {
            if (($start === date('Y-m-d') || $start === date('Y-m-d', strtotime('+1 day'))) && $bookableDirectly) {
                $bookableDirectly = false;
            }

            if ($availability['Status'] === 'Available') {
                if ($bookableDirectly) {
                    $json['BookableDirectly'] = true;
                }
            } else {
                unset($json['success']);
                $json['error'] = $availability['StatusDescription'];
            }
        }

        $json['apiResponse'] = $availability;
        $this->response->success($json);
    }

    /**
     * Hesaplanan fiyat ozetini SVG image olarak dondurur.
     *
     * @Get("image")
     * @query EntityId int required Villa kimligi
     * @query start string required Giris tarihi (Y-m-d / d.m.Y)
     * @query end string required Cikis tarihi (Y-m-d / d.m.Y)
     * @query PromotionCode string Promosyon kodu
     * @query pool_fee bool Havuz isitma ucreti eklensin mi
     * @query selectedServices string Secili ekstra servis id listesi
     * @query format string png veya jpg
     * @query site int Site kimligi
     */
    public function image(): void
    {
        $entityId = (int) $this->configuredParam('entity_id', ['EntityId', 'id'], 0);
        $start = $this->normalizeDate((string) $this->configuredParam('start', ['start'], ''));
        $end = $this->normalizeDate((string) $this->configuredParam('end', ['end'], ''));

        if ($entityId <= 0 || $start === null || $end === null || $end <= $start) {
            $this->sendSvg($this->renderErrorSvg('Gecersiz parametre.'));
            return;
        }

        $pdo = $this->db->pdo();
        $site = $this->siteId();
        $calendarHome = $this->calendarHome($pdo, $entityId);
        $availability = $this->localAvailability($pdo, $entityId, $start, $end, $calendarHome);
        $json = $this->calculatePrice(
            $pdo,
            $start,
            $end,
            $entityId,
            (string) $this->param('PromotionCode', ''),
            $calendarHome,
            $site
        );

        if (empty($json['success']) || empty($json['result'])) {
            $this->sendSvg($this->renderErrorSvg((string) ($json['error'] ?? 'Fiyat hesaplanamadı.')));
            return;
        }

        if ($availability['Status'] !== 'Available') {
            $this->sendSvg($this->renderErrorSvg((string) $availability['StatusDescription']));
            return;
        }

        $format = $this->normalizeImageFormat((string) $this->param('format', 'png'));
        $image = $this->htmlToRasterImage($this->renderSummaryHtml($start, $end, $json), $format);
        if ($image === null) {
            $this->sendSvg($this->renderSummarySvg($start, $end, $json));
            return;
        }

        $this->sendRasterImage($image, $format);
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    private function param(string $key, $default = null)
    {
        $value = $this->request->query($key);
        if ($value !== null) {
            return $value;
        }

        return $this->request->input($key, $default);
    }

    private function siteId(): int
    {
        foreach (['site', 'site_id', 'siteId', 'currentSite', 'currentSiteId'] as $key) {
            $value = $this->param($key);
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return defined('PRICE_SITE') ? max(1, (int) constant('PRICE_SITE')) : 1;
    }

    /**
     * @param string[] $defaultKeys
     * @param mixed $default
     * @return mixed
     */
    private function configuredParam(string $name, array $defaultKeys, $default = null)
    {
        $configured = $this->app['calculate_param_names'][$name] ?? $defaultKeys;
        $keys = is_array($configured) ? $configured : [$configured];

        foreach ($keys as $key) {
            if (!is_string($key) || trim($key) === '') {
                continue;
            }

            $value = $this->param($key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $default;
    }

    /**
     * @return string|null
     */
    private function normalizeDate(string $value)
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $formats = $this->app['calculate_date_formats'] ?? ['Y-m-d', 'd.m.Y', 'm/d/Y'];
        if (!is_array($formats)) {
            $formats = ['Y-m-d', 'd.m.Y', 'm/d/Y'];
        }

        foreach ($formats as $format) {
            if (!is_string($format) || trim($format) === '') {
                continue;
            }

            $date = DateTime::createFromFormat($format, $value);
            $errors = DateTime::getLastErrors();
            if ($date instanceof DateTime && ($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function calendarHome(PDO $pdo, int $entityId)
    {
        $stmt = $pdo->prepare('SELECT * FROM KiralamaTakvimi.CalendarHomes WHERE homesId = :homesId');
        $stmt->execute(['homesId' => $entityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Dista bagli Kiralama Takvimi client bu repoda olmadigi icin yerel tablolardan temel kontrol yapar.
     *
     * @param array<string,mixed>|null $calendarHome
     * @return array<string,mixed>
     */
    private function localAvailability(PDO $pdo, int $entityId, string $start, string $end, $calendarHome): array
    {
        if ($calendarHome && isset($calendarHome['RoomType']) && (string) $calendarHome['RoomType'] === '1') {
            $stmt = $pdo->prepare(
                'SELECT TOP 1 Date
                 FROM KiralamaTakvimi.HotelAvailabilityRooms
                 WHERE EstateId = :estateId
                   AND (RoomCount = 0 OR IsClosed = 1)
                   AND CONVERT(date, Date) >= CONVERT(date, :startDate, 23)
                   AND CONVERT(date, Date) < CONVERT(date, :endDate, 23)'
            );
            $stmt->execute([
                'estateId' => (int) $calendarHome['EstateId'],
                'startDate' => $start,
                'endDate' => $end,
            ]);

            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                return [
                    'Status' => 'UnavailableHasApproved',
                    'StatusDescription' => 'Secilen tarihler musait degil.',
                    'IsRequestAllowed' => false,
                    'Source' => 'local',
                ];
            }
        } else {
            $stmt = $pdo->prepare(
                'SELECT TOP 1 id
                 FROM dolu
                 WHERE emlak = :entityId
                   AND durum = 3
                   AND CONVERT(date, tarih, 104) < CONVERT(date, :endDate, 23)
                   AND CONVERT(date, tarih2, 104) > CONVERT(date, :startDate, 23)'
            );
            $stmt->execute([
                'entityId' => $entityId,
                'startDate' => $start,
                'endDate' => $end,
            ]);

            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                return [
                    'Status' => 'UnavailableHasApproved',
                    'StatusDescription' => 'Secilen tarihler musait degil.',
                    'IsRequestAllowed' => false,
                    'Source' => 'local',
                ];
            }
        }

        return [
            'Status' => 'Available',
            'StatusDescription' => '',
            'IsRequestAllowed' => true,
            'Source' => 'local',
        ];
    }

    /**
     * @param array<string,mixed>|null $calendarHome
     * @return array<string,mixed>
     */
    private function calculatePrice(
        PDO $pdo,
        string $start,
        string $end,
        int $entityId,
        string $promotionCode,
        $calendarHome,
        int $site
    ): array {
        $json = ['AvailableExtraServices' => []];
        $night = (int) date_diff(date_create($end), date_create($start))->days;

        if ($night <= 0) {
            return ['error' => 'Gecersiz tarih araligi.'];
        }

        $defaultCurrencyId = defined('DEFAULT_CURRENCY_ID') ? (int) constant('DEFAULT_CURRENCY_ID') : 1;
        $rateId = $this->lastRateId($pdo);
        $uzanti = $this->fieldSuffix($site);
        $depozitoSuffix = $this->fieldSuffix($site, 'depozito');

        $sql = " 
SELECT
    FiyatTablosu.ToplamTutar * COALESCE(RD.Buy, NULLIF(h.kur{$uzanti}, 0), 1) AS fyt,
    FiyatTablosu.IndirimTutari * COALESCE(RD.Buy, NULLIF(h.kur{$uzanti}, 0), 1) AS indirimTutari,
    COALESCE(RD.Buy, NULLIF(h.kur{$uzanti}, 0), 1) AS Buy,
    FiyatTablosu.SahteIndirimTutari * COALESCE(RD.Buy, NULLIF(h.kur{$uzanti}, 0), 1) AS SahteIndirimTutari,
    (
        SELECT TOP 1
            CASE
                WHEN ISNULL(temizlikgece, 0) = 0 THEN gece
                WHEN gece > temizlikgece THEN temizlikgece
                ELSE gece
            END
        FROM sezonlar
        WHERE site = {$site}
          AND islem_id = {$entityId}
          AND islem = 'emlak'
          AND '{$start}' BETWEEN CONVERT(date, tarih1, 104) AND CONVERT(date, tarih2, 104)
        ORDER BY id DESC
    ) AS mingece,
    ISNULL((
        SELECT TOP 1 temizlikgece
        FROM sezonlar
        WHERE site = {$site}
          AND islem_id = {$entityId}
          AND islem = 'emlak'
          AND '{$start}' BETWEEN CONVERT(date, tarih1, 104) AND CONVERT(date, tarih2, 104)
        ORDER BY id DESC
    ), 0) AS temizlikGece,
    ISNULL((
        SELECT TOP 1 temizlikFiyat
        FROM sezonlar
        WHERE site = {$site}
          AND islem_id = {$entityId}
          AND islem = 'emlak'
          AND '{$start}' BETWEEN CONVERT(date, tarih1, 104) AND CONVERT(date, tarih2, 104)
        ORDER BY id DESC
    ), 0) * COALESCE(RD2.Buy, NULLIF(h.kur{$uzanti}, 0), 1) AS temizlikFiyat,
    CASE WHEN h.kur{$uzanti} > 0 THEN h.kur{$uzanti} ELSE 0 END AS kur,
    h.depozito{$depozitoSuffix} AS depozito,
    dbo.FnRandomSplit(h.resim{$uzanti}, ',') AS resim,
    h.baslik{$uzanti} AS baslik,
    h.hasar{$uzanti} AS hasar,
    h.kazancorani{$uzanti} AS kazancorani,
    ISNULL(ToC.CurrencyName, h.doviz{$uzanti}) AS CurrencyName,
    ISNULL(ToC.CurrencyCode, h.doviz{$uzanti}) AS CurrencyCode,
    h.id,
    h.sitemap,
    h.aktif{$uzanti} AS aktif,
    ISNULL(ToC.CurrencyId, {$defaultCurrencyId}) AS CurrencyId,
    ISNULL(ToC.Symbol, N'TL') AS Symbol
FROM homes h
LEFT JOIN Finance.Currency FromC ON FromC.CurrencyName = h.doviz{$uzanti}
CROSS APPLY (
    SELECT * FROM dbo.Natsisa_Fn_yenifiyathesapla_tablo('{$start}', '{$end}', {$entityId}, {$site})
) AS FiyatTablosu
LEFT JOIN Finance.Currency ToC ON ToC.CurrencyId = :DefaultCurrencyId
LEFT JOIN Finance.Currency BaseCurrency ON BaseCurrency.CurrencyId = 1
LEFT JOIN Finance.Rate FR ON FR.RateId = :RateId
LEFT JOIN Finance.RateDetail RD ON RD.ToCurrencyId = ToC.CurrencyId
    AND RD.FromCurrencyId = FromC.CurrencyId
    AND RD.RateId = FR.RateId
LEFT JOIN Finance.RateDetail RD2 ON RD2.ToCurrencyId = ToC.CurrencyId
    AND RD2.FromCurrencyId = BaseCurrency.CurrencyId
    AND RD2.RateId = FR.RateId
WHERE h.id = {$entityId}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'RateId' => $rateId,
            'DefaultCurrencyId' => $defaultCurrencyId,
        ]);
        $home = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$home) {
            return ['error' => 'Kayit veya fiyat bilgisi bulunamadi.'];
        }
        if ((string) $this->param('currentRoutingTypeId', '') === 'Reservation' && (string) $home['sitemap'] === '0') {
            $home['aktif'] = 0;
        }
        if ((string) $home['aktif'] !== '1') {
            return ['error' => 'Villa aktif degil.'];
        }

        $state = $this->collectDailyState($pdo, $start, $end, $entityId, $home, $calendarHome, $rateId, $defaultCurrencyId, $site);
        $shortStay = $this->isShortStay($pdo, $start, $end, $entityId, $night, (int) $home['mingece'], $calendarHome);
        $price = (int) $home['fyt'];
        $oldPrice = 0;
        $promotionDiscountPrice = 0;
        $depozitodanDus = 0;

        if ($promotionCode !== '') {
            $code = $this->promotionCode($pdo, $promotionCode);
            if ($code) {
                $oldPrice = $price;
                $promotionDiscountPrice = !empty($code['isPrice'])
                    ? (int) $code['value']
                    : (int) ($price / 100 * (float) $code['value']);
                $price -= $promotionDiscountPrice;
                if ($promotionCode === 'MobilApp.1000') {
                    $depozitodanDus = $promotionDiscountPrice;
                    $json['MobileOzel'] = true;
                }
            }
        }

        if ((float) $home['SahteIndirimTutari'] > 0) {
            $json['result']['old_price2'] = $price + (int) $home['SahteIndirimTutari'] + $promotionDiscountPrice;
            $json['result']['IndirimTutari'] = (int) $home['SahteIndirimTutari'];
        }

        $json['result']['PromotionDiscountPrice'] = $promotionDiscountPrice;
        if ((float) $home['indirimTutari'] > 0) {
            $oldPrice += (int) $home['indirimTutari'];
        }

        $deposit = !empty($home['depozito'])
            ? (($price + $depozitodanDus) / 100 * (float) $home['depozito']) - $depozitodanDus
            : -$depozitodanDus;
        $remainingWithoutFees = $price - $deposit;
        $remaining = $remainingWithoutFees;

        if ($state['occupied']) {
            return ['error' => 'Secilen tarihler musait degil.'];
        }
        if ($shortStay) {
            return ['error' => 'Minimum konaklama suresi saglanmiyor.'];
        }
        if ($price <= 0) {
            return ['error' => 'Fiyat bulunamadi.'];
        }

        if ($state['paymentWaiting']) {
            $json['error'] = 'Secilen tarihlerde odeme bekleyen opsiyon var.';
        }

        $json['success'] = 200;
        if ($oldPrice > 0) {
            $json['result']['old_price'] = $oldPrice;
        }

        $json['AvailableExtraServices'] = $state['extraServices'];
        $json['result']['accommodation_fee'] = $price;
        $json['result']['total_price'] = $price;

        if ($night < (int) $home['temizlikGece'] && (string) $home['temizlikFiyat'] !== '0') {
            $json['result']['cleaning_fee'] = (int) $home['temizlikFiyat'];
            $json['result']['total_price'] += (int) $home['temizlikFiyat'];
            $remaining += (int) $home['temizlikFiyat'];
        }

        $pool = $this->poolFees($state['heatingFees']);
        if ((string) $this->param('pool_fee', '') === '1' && $pool['outOfService'] !== 1) {
            $json['result']['pool_fee'] = $pool['total'];
            $json['result']['total_price'] += $pool['total'];
            $remaining += $pool['total'];
        }

        $json['result']['remaining_price'] = (int) $remaining;
        $json['result']['remaining_price2'] = (int) $remainingWithoutFees;
        $json['result']['deposit_price'] = (int) $deposit;
        $json['result']['isitmaHizmetDisi'] = $pool['outOfService'];
        $json['result']['isitmaUcretleri'] = $state['heatingFees'];
        $json['result']['symbol'] = $home['Symbol'];
        $json['result']['daily_price'] = (int) ($price / $night);
        $json['result']['night'] = $night;
        $json['result']['home_title'] = (string) $home['baslik'];
        $json['result']['reservation_url'] = $this->reservationUrl($entityId, $start, $end);

        $this->applyExtraServicePrices($json);

        return $json;
    }

    /**
     * @param array<string,mixed> $home
     * @param array<string,mixed>|null $calendarHome
     * @return array<string,mixed>
     */
    private function collectDailyState(
        PDO $pdo,
        string $start,
        string $end,
        int $entityId,
        array $home,
        $calendarHome,
        int $rateId,
        int $defaultCurrencyId,
        int $site
    ): array {
        $date = date_create($start);
        $last = date_create($end);
        $roomType = $calendarHome && isset($calendarHome['RoomType']) ? (string) $calendarHome['RoomType'] : '';
        $occupied = false;
        $paymentWaiting = false;
        $heatingFees = [];
        $extraServices = [];

        while ($date <= $last) {
            $day = $date->format('Y-m-d');
            $control = null;
            if ($roomType !== '1') {
                $stmt = $pdo->prepare(
                    "SELECT TOP 1 *
                     FROM dolu
                     WHERE emlak = :entityId
                       AND durum IN (1, 3, 5)
                       AND :day BETWEEN DATEADD(day, 1, tarih) AND DATEADD(day, -1, tarih2)"
                );
                $stmt->execute(['entityId' => $entityId, 'day' => $day]);
                $control = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            if ($day !== $end) {
                $stmt = $pdo->prepare(
                    "SELECT :day AS tarih,
                            CAST(isitmaFiyat * :buy AS int) AS isitmaFiyat,
                            isitmaHizmetDisi
                     FROM sezonlar
                     WHERE site = {$site}
                       AND islem_id = {$entityId}
                       AND islem = 'emlak'
                       AND :day2 BETWEEN CONVERT(date, tarih1, 104) AND CONVERT(date, tarih2, 104)"
                );
                $stmt->execute([
                    'day' => $day,
                    'day2' => $day,
                    'buy' => (float) $home['Buy'],
                ]);
                $heatingFee = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($heatingFee) {
                    $heatingFees[] = $heatingFee;
                }

                $extras = $this->extraPayments($pdo, $entityId, $day, $rateId, $defaultCurrencyId, (float) $home['Buy']);
                if ($extras) {
                    $extraServices[] = [
                        'date' => $day,
                        'extraServices' => $extras,
                    ];
                }
            }

            if ($control && ((string) $control['Durum'] === '3' || (string) $control['Durum'] === '5')) {
                $occupied = true;
            } elseif ($control && (string) $control['Durum'] === '1') {
                $paymentWaiting = true;
            }

            $date->modify('+1 day');
        }

        return [
            'occupied' => $occupied,
            'paymentWaiting' => $paymentWaiting,
            'heatingFees' => $heatingFees,
            'extraServices' => $extraServices,
        ];
    }

    /**
     * @param array<string,mixed>|null $calendarHome
     */
    private function isShortStay(
        PDO $pdo,
        string $start,
        string $end,
        int $entityId,
        int $night,
        int $minNight,
        $calendarHome
    ): bool {
        $roomType = $calendarHome && isset($calendarHome['RoomType']) ? (string) $calendarHome['RoomType'] : '';
        if ($roomType === '1' || $minNight <= 1) {
            return false;
        }

        if ($night < $minNight) {
            if (!$this->objectExists($pdo, 'dbo', 'kisasureli3', 'IF')
                && !$this->objectExists($pdo, 'dbo', 'kisasureli3', 'TF')
            ) {
                return true;
            }

            $stmt = $pdo->prepare(
                "SELECT *
                 FROM kisasureli3(:startDate, :endDate, {$entityId}, '1,2,3,4,5,6') AS kisasureli
                 WHERE tarih = :startDate2 AND tarih2 = :endDate2"
            );
            $stmt->execute([
                'startDate' => $start,
                'endDate' => $end,
                'startDate2' => $start,
                'endDate2' => $end,
            ]);

            if ($stmt->fetchAll(PDO::FETCH_ASSOC)) {
                return false;
            }

            return true;
        }

        return $this->createsShortReservationGap($pdo, $entityId, $start, $end, $minNight);
    }

    private function createsShortReservationGap(PDO $pdo, int $entityId, string $start, string $end, int $minNight): bool
    {
        $previous = $this->adjacentReservationDate($pdo, $entityId, $start, 'previous');
        $previousGap = $previous !== null ? $this->gapNights($previous, $start) : 0;
        if ($previousGap > 0 && $previousGap < $minNight) {
            return true;
        }

        $next = $this->adjacentReservationDate($pdo, $entityId, $end, 'next');
        $nextGap = $next !== null ? $this->gapNights($end, $next) : 0;
        if ($nextGap > 0 && $nextGap < $minNight) {
            return true;
        }

        return false;
    }

    /**
     * @return string|null Y-m-d
     */
    private function adjacentReservationDate(PDO $pdo, int $entityId, string $date, string $direction)
    {
        if ($direction === 'previous') {
            $sql = 'SELECT TOP 1 CONVERT(char(10), CONVERT(date, tarih2, 104), 23) AS reservation_date
                    FROM dolu
                    WHERE emlak = :entityId
                      AND Durum IN (1, 3)
                      AND CONVERT(date, tarih2, 104) <= CONVERT(date, :date, 23)
                    ORDER BY CONVERT(date, tarih2, 104) DESC';
        } else {
            $sql = 'SELECT TOP 1 CONVERT(char(10), CONVERT(date, tarih, 104), 23) AS reservation_date
                    FROM dolu
                    WHERE emlak = :entityId
                      AND Durum IN (1, 3)
                      AND CONVERT(date, tarih, 104) >= CONVERT(date, :date, 23)
                    ORDER BY CONVERT(date, tarih, 104)';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'entityId' => $entityId,
            'date' => $date,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row && !empty($row['reservation_date']) ? (string) $row['reservation_date'] : null;
    }

    private function gapNights(string $from, string $to): int
    {
        $fromDate = date_create($from);
        $toDate = date_create($to);
        if (!$fromDate || !$toDate || $toDate <= $fromDate) {
            return 0;
        }

        return (int) $fromDate->diff($toDate)->days;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extraPayments(PDO $pdo, int $entityId, string $day, int $rateId, int $defaultCurrencyId, float $fallbackBuy): array
    {
        if (!$this->objectExists($pdo, 'dbo', 'HomesExtraPayments', 'U')) {
            return [];
        }

        $fallbackBuySql = $fallbackBuy > 0 ? (string) $fallbackBuy : '1';

        $stmt = $pdo->prepare(
            "SELECT EP.*,
                    EP.title AS title,
                    CAST(EP.amount * COALESCE(RD.Buy, {$fallbackBuySql}, 1) AS decimal(18, 0)) AS amount
             FROM dbo.HomesExtraPayments EP
             LEFT JOIN Finance.Currency FromC ON FromC.CurrencyId = EP.CurrencyId
             LEFT JOIN Finance.Currency ToC ON ToC.CurrencyId = :DefaultCurrencyId
             LEFT JOIN Finance.Rate FR ON FR.RateId = :RateId
             LEFT JOIN Finance.RateDetail RD ON RD.ToCurrencyId = ToC.CurrencyId
                AND RD.FromCurrencyId = FromC.CurrencyId
                AND RD.RateId = FR.RateId
             WHERE EP.homesId = :homesId
               AND :day BETWEEN EP.start_date AND EP.end_date"
        );
        $stmt->execute([
            'homesId' => $entityId,
            'day' => $day,
            'DefaultCurrencyId' => $defaultCurrencyId,
            'RateId' => $rateId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function objectExists(PDO $pdo, string $schema, string $object, string $type): bool
    {
        $stmt = $pdo->prepare(
            'SELECT OBJECT_ID(:objectName, :objectType) AS object_id'
        );
        $stmt->execute([
            'objectName' => $schema . '.' . $object,
            'objectType' => $type,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row && $row['object_id'] !== null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function promotionCode(PDO $pdo, string $promotionCode)
    {
        $stmt = $pdo->prepare(
            'SELECT *
             FROM promotionCodes
             WHERE code = :code
               AND GETDATE() BETWEEN startDate AND endDate
               AND stock > 0'
        );
        $stmt->execute(['code' => $promotionCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<int,array<string,mixed>> $heatingFees
     * @return array{total:int,outOfService:int}
     */
    private function poolFees(array $heatingFees): array
    {
        $total = 0;
        $outOfService = 1;

        foreach ($heatingFees as $fee) {
            if ((string) $fee['isitmaHizmetDisi'] !== '1') {
                $total += (int) $fee['isitmaFiyat'];
            }
            if ($outOfService === 1 && (string) $fee['isitmaHizmetDisi'] === '0') {
                $outOfService = 0;
            }
        }

        return ['total' => $total, 'outOfService' => $outOfService];
    }

    /**
     * @param array<string,mixed> $json
     */
    private function applyExtraServicePrices(array &$json): void
    {
        $selected = array_filter(array_map('intval', explode(',', (string) $this->param('selectedServices', ''))));
        $stayTotalExtras = [];
        $json['result']['extra_price'] = 0;

        foreach ($json['AvailableExtraServices'] as $availableExtraService) {
            foreach ($availableExtraService['extraServices'] as $extraService) {
                $isSelected = in_array((int) $extraService['id'], $selected, true);
                if ((string) $extraService['IsOptional'] !== '0' && !$isSelected) {
                    continue;
                }

                if ((string) $extraService['Type'] === '0') {
                    $amount = (int) $extraService['amount'];
                    $json['result']['total_price'] += $amount;
                    $json['result']['extra_price'] += $amount;
                    $json['result']['remaining_price'] += $amount;
                } elseif ((string) $extraService['Type'] === '1') {
                    $stayTotalExtras[(int) $extraService['id']] = (int) $extraService['amount'];
                }
            }
        }

        $stayTotal = (int) array_sum(array_values($stayTotalExtras));
        $json['result']['extra_price'] += $stayTotal;
        $json['result']['total_price'] += $stayTotal;
        $json['result']['remaining_price'] += $stayTotal;
    }

    private function lastRateId(PDO $pdo): int
    {
        if (defined('RATE_ID')) {
            return (int) constant('RATE_ID');
        }

        $stmt = $pdo->query('SELECT TOP 1 RateId FROM Finance.Rate ORDER BY RateId DESC');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (int) $row['RateId'] : 1;
    }

    private function fieldSuffix(int $site, string $field = ''): string
    {
        $suffixes = isset($this->app['site_column_suffixes']) && is_array($this->app['site_column_suffixes'])
            ? $this->app['site_column_suffixes']
            : [1 => '', 2 => '_s2', 3 => '_s3'];

        $suffix = isset($suffixes[$site]) ? (string) $suffixes[$site] : '';
        if ($field !== ''
            && isset($this->app['site_field_suffixes'][$site][$field])
        ) {
            $suffix = (string) $this->app['site_field_suffixes'][$site][$field];
        }

        if (!preg_match('/^_[A-Za-z0-9]+$/', $suffix) && $suffix !== '') {
            throw new HttpException('Gecersiz kolon suffix ayari.', 'CONFIG_ERROR', 500);
        }

        return $suffix;
    }

    private function sendSvg(string $svg): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            header('Content-Type: image/svg+xml; charset=utf-8');
            header('Cache-Control: no-store');
            header('Content-Length: ' . strlen($svg));
            http_response_code(200);
        }

        echo $svg;
        exit;
    }

    private function sendRasterImage(string $image, string $format): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            header('Content-Type: ' . ($format === 'jpg' ? 'image/jpeg' : 'image/png'));
            header('Cache-Control: no-store');
            header('Content-Length: ' . strlen($image));
            http_response_code(200);
        }

        echo $image;
        exit;
    }

    /**
     * @return string|null PNG/JPG binary
     */
    private function htmlToRasterImage(string $html, string $format)
    {
        $binary = $this->htmlToImageBinary();
        if ($binary === null || !is_callable('proc_open')) {
            return null;
        }

        $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bocek-api-images';
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            return null;
        }

        $base = $dir . DIRECTORY_SEPARATOR . uniqid('reservation-summary-', true);
        $htmlPath = $base . '.html';
        $imagePath = $base . '.' . $format;

        if (file_put_contents($htmlPath, $html) === false) {
            return null;
        }

        $command = $this->escapeCommand($binary)
            . ' --format ' . $format . ' --width 760 --quality 95 --enable-local-file-access '
            . escapeshellarg($htmlPath)
            . ' '
            . escapeshellarg($imagePath);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            @unlink($htmlPath);
            return null;
        }

        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $image = null;
        if ($exitCode === 0 && is_file($imagePath)) {
            $image = file_get_contents($imagePath);
        }

        @unlink($htmlPath);
        @unlink($imagePath);

        return is_string($image) && $image !== '' ? $image : null;
    }

    private function normalizeImageFormat(string $format): string
    {
        $format = strtolower(trim($format));
        if ($format === 'jpg' || $format === 'jpeg') {
            return 'jpg';
        }

        return 'png';
    }

    /**
     * @return string|null
     */
    private function htmlToImageBinary()
    {
        $configured = trim((string) ($this->app['html_to_image_binary'] ?? ''));
        if ($configured !== '') {
            if (is_file($configured) || strpos($configured, DIRECTORY_SEPARATOR) === false) {
                return $configured;
            }

            return null;
        }

        $backendRoot = dirname(__DIR__, 2);
        $candidates = [
            $backendRoot . '/bin/wkhtmltoimage.exe',
            $backendRoot . '/bin/wkhtmltoimage',
            'C:/Program Files/wkhtmltopdf/bin/wkhtmltoimage.exe',
            'C:/Program Files (x86)/wkhtmltopdf/bin/wkhtmltoimage.exe',
            '/usr/bin/wkhtmltoimage',
            '/usr/local/bin/wkhtmltoimage',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function escapeCommand(string $binary): string
    {
        if (strpos($binary, DIRECTORY_SEPARATOR) === false && strpos($binary, '/') === false) {
            return $binary;
        }

        return escapeshellarg($binary);
    }

    private function reservationUrl(int $entityId, string $start, string $end): string
    {
        $domain = defined('Domain') ? (string) constant('Domain') : '';
        $domain = rtrim(trim($domain), '/');
        if ($domain === '') {
            return '';
        }

        $config = is_array($this->app['calculate_reservation_url'] ?? null)
            ? $this->app['calculate_reservation_url']
            : [];
        $path = trim((string) ($config['path'] ?? '/rezervasyon'));
        $path = $path === '' ? '' : '/' . ltrim($path, '/');
        $params = is_array($config['params'] ?? null) ? $config['params'] : [];
        $dateFormat = trim((string) ($config['date_format'] ?? 'Y-m-d'));
        $dateFormat = $dateFormat !== '' ? $dateFormat : 'Y-m-d';

        $query = [
            $this->queryParamName($params, 'entity_id', 'ProductId') => $entityId,
            $this->queryParamName($params, 'start', 'start') => $this->formatDate($start, $dateFormat),
            $this->queryParamName($params, 'end', 'end') => $this->formatDate($end, $dateFormat),
        ];

        if ((string) $this->param('pool_fee', '') === '1') {
            $query[$this->queryParamName($params, 'pool_fee', 'buyPool')] = 1;
        }

        return $domain . $path . '?' . http_build_query($query, '', '&');
    }

    /**
     * @param array<string,mixed> $params
     */
    private function queryParamName(array $params, string $name, string $default): string
    {
        $value = trim((string) ($params[$name] ?? $default));

        return $value !== '' ? $value : $default;
    }

    private function formatDate(string $date, string $format): string
    {
        $dateTime = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dateTime instanceof DateTime) {
            return $date;
        }

        return $dateTime->format($format);
    }

    /**
     * @param array<string,mixed> $json
     */
    private function renderSummaryHtml(string $start, string $end, array $json): string
    {
        $result = $json['result'];
        $symbol = (string) ($result['symbol'] ?? 'TL');
        $night = (int) ($result['night'] ?? 0);
        $homeTitle = trim((string) ($result['home_title'] ?? ''));
        $reservationUrl = trim((string) ($result['reservation_url'] ?? ''));
        $oldPrice = (int) ($result['old_price'] ?? ($result['old_price2'] ?? 0));
        $rows = [];

        if ($oldPrice > 0) {
            $rows[] = ['Konaklama Tutarı (' . $night . ' Gece)', $this->money($oldPrice, $symbol), '#dc2626', true];
        }
        if (!empty($result['IndirimTutari'])) {
            $rows[] = ['İndirim', $this->money((int) $result['IndirimTutari'], $symbol), '#16a34a', false];
        }
        if (!empty($result['PromotionDiscountPrice'])) {
            $rows[] = ['Mobil Uygulamaya Özel İndirim', $this->money((int) $result['PromotionDiscountPrice'], $symbol), '#16a34a', false];
        }

        $rows[] = ['İndirimli Konaklama Tutarı', $this->money((int) $result['accommodation_fee'], $symbol), '#000000', false];

        if (!empty($result['cleaning_fee'])) {
            $rows[] = ['Temizlik Ücreti', $this->money((int) $result['cleaning_fee'], $symbol), '#000000', false];
        }
        if (!empty($result['pool_fee'])) {
            $rows[] = ['Isıtmalı Havuz', $this->money((int) $result['pool_fee'], $symbol), '#000000', false];
        }
        if (!empty($result['extra_price'])) {
            $rows[] = ['Ekstra Servisler', $this->money((int) $result['extra_price'], $symbol), '#000000', false];
        }

        $rows[] = ['Toplam Tutar', $this->money((int) $result['total_price'], $symbol), '#000000', false];

        $rowHtml = '';
        foreach ($rows as $row) {
            $valueStyle = 'font-weight: 700; color: ' . $row[2] . ';';
            if ($row[3]) {
                $valueStyle .= ' text-decoration: line-through;';
            }
            $rowHtml .= '<div style="font-size: 13px; display: flex; justify-content: space-between; margin-top: 12px; align-items: center;">'
                . '<span style="font-weight: 500; opacity: 0.6; color: #000000;">' . $this->e($row[0]) . '</span>'
                . '<span style="' . $valueStyle . '">' . $this->e($row[1]) . '</span>'
                . '</div>';
        }

        $titleHtml = $homeTitle !== ''
            ? '<div style="width: 760px; padding: 0 28px 12px 28px; font-family: Manrope, \'Segoe UI\', Arial, sans-serif; box-sizing: border-box;">
  <div style="font-size: 22px; line-height: 1.25; font-weight: 800; color: #111111;">' . $this->e($homeTitle) . '</div>
  ' . ($reservationUrl !== '' ? '<a href="' . $this->e($reservationUrl) . '" style="display: block; margin-top: 6px; font-size: 12px; line-height: 1.35; font-weight: 600; color: #2563eb; text-decoration: none; overflow-wrap: anywhere;">' . $this->e($reservationUrl) . '</a>' : '') . '
</div>'
            : '';

        return '<!doctype html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
html,body{margin:0;padding:0;background:#fff;width:760px;font-family:Manrope,"Segoe UI",Arial,sans-serif;box-sizing:border-box}
*{box-sizing:border-box}
</style>
</head>
<body>
' . $titleHtml . '
<div style="background-color: #ffffff; padding-top: 16px; padding-bottom: 4px; padding-left: 28px; padding-right: 20px; display: flex; align-items: center; position: relative; border: 1px solid #e4e9eb; border-radius: 2px; font-family: Manrope, \'Segoe UI\', Arial, sans-serif; box-sizing: border-box; width: 760px;">
  <div style="display: flex; justify-content: space-between; flex: 1; margin-right: 40px; align-items: center;">
    <div style="display: flex; flex-direction: column;">
      <span style="font-weight: 700; color: #9b9b9c; font-size: 13px; line-height: 1.2;">Giriş Tarihi</span>
      <span style="font-weight: 700; font-size: 20px; color: #000000; line-height: 1.2; margin-top: 2px;">' . $this->dateDay($start) . ' <span style="font-size: 12px; top: -2px; position: relative; display: inline-block; font-weight: 700;">' . $this->dateMonth($start) . '</span></span>
    </div>
    <svg width="27" height="21" viewBox="0 0 27 21" fill="none" xmlns="http://www.w3.org/2000/svg" style="display: block;">
      <g fill-rule="evenodd">
        <g transform="translate(0, 9.1)" fill="#22B645"><polygon points="15.1725 0.90125 15.1725 4.47125 3.3915 4.47125 6.6045 1.25825 5.355 0.00875 0 5.36375 5.355 10.71875 6.6045 9.46925 3.3915 6.25625 16.9575 6.25625 16.9575 0.90125"></polygon></g>
        <g transform="translate(18.1, 5.7) scale(-1, -1) translate(-18.1, -5.7) translate(9.6, 0.2)" fill="#FF4300"><polygon points="15.1725 0.90125 15.1725 4.47125 3.3915 4.47125 6.6045 1.25825 5.355 0.00875 0 5.36375 5.355 10.71875 6.6045 9.46925 3.3915 6.25625 16.9575 6.25625 16.9575 0.90125"></polygon></g>
      </g>
    </svg>
    <div style="display: flex; flex-direction: column;">
      <span style="font-weight: 700; color: #9b9b9c; font-size: 13px; line-height: 1.2;">Çıkış Tarihi</span>
      <span style="font-weight: 700; font-size: 20px; color: #000000; line-height: 1.2; margin-top: 2px;">' . $this->dateDay($end) . ' <span style="font-size: 12px; top: -2px; position: relative; display: inline-block; font-weight: 700;">' . $this->dateMonth($end) . '</span></span>
    </div>
  </div>
  <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" style="display: block;">
    <rect width="19.8" height="19.8" rx="2" fill="#939495"/>
    <path d="M1.8 7.2h16.2v10.8H1.8z" fill="#ffffff"/>
    <path d="M1.8 3.6h16.2v1.8H1.8z" fill="#ffffff"/>
  </svg>
</div>
<br>
<div style="display: flex; flex-direction: column; font-family: Manrope, \'Segoe UI\', Arial, sans-serif; box-sizing: border-box; width: 760px; border-radius: 2px; overflow: hidden;">
  <div style="background-color: #f9f9f9; padding-left: 28px; padding-right: 28px; padding-top: 16px; padding-bottom: 8px;">
    <span style="margin-bottom: 12px; font-weight: 600; font-size: 16px; letter-spacing: 0.11px; color: #fc8919; display: block;">Rezervasyon Özeti</span>
    ' . $rowHtml . '
  </div>
  <div style="background-color: #e5e7eb; padding-left: 28px; padding-right: 28px; padding-top: 12px; padding-bottom: 12px;">
    <div style="font-size: 13px; display: flex; justify-content: space-between; align-items: center;">
      <span style="font-weight: 500; opacity: 0.6; color: #000000;">Ön Ödeme Tutarı</span>
      <span style="font-weight: 700; color: #000000;">' . $this->e($this->money((int) $result['deposit_price'], $symbol)) . '</span>
    </div>
    <div style="font-size: 13px; display: flex; justify-content: space-between; margin-top: 12px; align-items: center;">
      <span style="font-weight: 500; opacity: 0.6; color: #000000;">Kalan (Villaya girişte ödenecek)</span>
      <span style="font-weight: 700; color: #000000;">' . $this->e($this->money((int) $result['remaining_price'], $symbol)) . '</span>
    </div>
  </div>
  <div style="background-color: #f9f9f9; padding-left: 28px; padding-right: 28px; padding-top: 4px; padding-bottom: 4px;"></div>
</div>
</body>
</html>';
    }

    /**
     * @param array<string,mixed> $json
     */
    private function renderSummarySvg(string $start, string $end, array $json): string
    {
        $result = $json['result'];
        $symbol = (string) ($result['symbol'] ?? 'TL');
        $night = (int) ($result['night'] ?? 0);
        $homeTitle = trim((string) ($result['home_title'] ?? ''));
        $reservationUrl = trim((string) ($result['reservation_url'] ?? ''));
        $oldPrice = (int) ($result['old_price'] ?? ($result['old_price2'] ?? 0));
        $rows = [];
        $paymentRows = [];

        if ($oldPrice > 0) {
            $rows[] = ['Konaklama Tutarı (' . $night . ' Gece)', $this->money($oldPrice, $symbol), '#dc2626', true];
        }
        if (!empty($result['IndirimTutari'])) {
            $rows[] = ['İndirim', $this->money((int) $result['IndirimTutari'], $symbol), '#16a34a', false];
        }
        if (!empty($result['PromotionDiscountPrice'])) {
            $rows[] = ['Promosyon İndirimi', $this->money((int) $result['PromotionDiscountPrice'], $symbol), '#16a34a', false];
        }

        $rows[] = ['İndirimli Konaklama Tutarı', $this->money((int) $result['accommodation_fee'], $symbol), '#111111', false];

        if (!empty($result['cleaning_fee'])) {
            $rows[] = ['Temizlik Ücreti', $this->money((int) $result['cleaning_fee'], $symbol), '#111111', false];
        }
        if (!empty($result['pool_fee'])) {
            $rows[] = ['Isıtmalı Havuz', $this->money((int) $result['pool_fee'], $symbol), '#111111', false];
        }
        if (!empty($result['extra_price'])) {
            $rows[] = ['Ekstra Servisler', $this->money((int) $result['extra_price'], $symbol), '#111111', false];
        }

        $rows[] = ['Toplam Tutar', $this->money((int) $result['total_price'], $symbol), '#111111', false];
        $paymentRows[] = ['Ön Ödeme Tutarı', $this->money((int) $result['deposit_price'], $symbol), '#111111', false];
        $paymentRows[] = ['Kalan (Villaya girişte ödenecek)', $this->money((int) $result['remaining_price'], $symbol), '#111111', false];

        $width = 760;
        $titleHeight = $homeTitle !== '' ? ($reservationUrl !== '' ? 66 : 48) : 0;
        $dateHeight = 65;
        $gap = 18;
        $dateTop = $titleHeight;
        $summaryTop = $titleHeight + $dateHeight + $gap;
        $topBlockHeight = 52 + (count($rows) * 25) + 10;
        $paymentTop = $summaryTop + $topBlockHeight;
        $paymentHeight = 24 + (count($paymentRows) * 25) + 8;
        $bottomStripHeight = 8;
        $height = $paymentTop + $paymentHeight + $bottomStripHeight;
        $svgRows = '';
        $y = $summaryTop + 74;
        foreach ($rows as $row) {
            $decoration = $row[3] ? ' text-decoration="line-through"' : '';
            $svgRows .= '<text x="28" y="' . $y . '" class="row-label">' . $this->e($row[0]) . '</text>';
            $svgRows .= '<text x="732" y="' . $y . '" class="row-value" fill="' . $row[2] . '"' . $decoration . '>' . $this->e($row[1]) . '</text>';
            $y += 25;
        }

        $svgPaymentRows = '';
        $y = $paymentTop + 29;
        foreach ($paymentRows as $row) {
            $svgPaymentRows .= '<text x="28" y="' . $y . '" class="row-label">' . $this->e($row[0]) . '</text>';
            $svgPaymentRows .= '<text x="732" y="' . $y . '" class="row-value" fill="' . $row[2] . '">' . $this->e($row[1]) . '</text>';
            $y += 25;
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">
<style>
text{font-family:Manrope,"Segoe UI",Arial,sans-serif}
.date-label{font-size:13px;font-weight:700;fill:#9b9b9c}
.date-value{font-size:20px;font-weight:700;fill:#000}
.date-month{font-size:12px;font-weight:700;fill:#000}
.home-title{font-size:22px;font-weight:800;fill:#111}
.reservation-link{font-size:12px;font-weight:600;fill:#2563eb}
.title{font-size:16px;font-weight:600;letter-spacing:.11px;fill:#fc8919}
.row-label{font-size:13px;font-weight:500;fill:#666}
.row-value{font-size:13px;font-weight:700;text-anchor:end}
</style>
<rect width="' . $width . '" height="' . $height . '" fill="#fff"/>
' . ($homeTitle !== '' ? '<text x="28" y="30" class="home-title">' . $this->e($homeTitle) . '</text>' : '') . '
' . ($reservationUrl !== '' ? '<text x="28" y="50" class="reservation-link">' . $this->e($reservationUrl) . '</text>' : '') . '
<rect x=".5" y="' . ($dateTop + 0.5) . '" width="759" height="64" rx="2" fill="#fff" stroke="#e4e9eb"/>
<text x="28" y="' . ($dateTop + 29) . '" class="date-label">Giriş Tarihi</text>
<text x="28" y="' . ($dateTop + 55) . '" class="date-value">' . $this->dateDay($start) . ' <tspan class="date-month" dy="-3">' . $this->dateMonth($start) . '</tspan></text>
<g transform="translate(366 ' . ($dateTop + 24) . ') scale(1.16)">
<path d="M15.1725 9.10125V12.67125H3.3915L6.6045 9.45825L5.355 8.20875L0 13.56375L5.355 18.91875L6.6045 17.66925L3.3915 14.45625H16.9575V9.10125H15.1725Z" fill="#22B645"/>
<path d="M11.0275 10.71875V7.14875H22.8085L19.5955 10.36175L20.845 11.61125L26.2 6.25625L20.845 .90125L19.5955 2.15075L22.8085 5.36375H9.2425V10.71875H11.0275Z" fill="#FF4300"/>
</g>
<text x="548" y="' . ($dateTop + 29) . '" class="date-label">Çıkış Tarihi</text>
<text x="548" y="' . ($dateTop + 55) . '" class="date-value">' . $this->dateDay($end) . ' <tspan class="date-month" dy="-3">' . $this->dateMonth($end) . '</tspan></text>
<g transform="translate(718 ' . ($dateTop + 22) . ')">
<rect width="19.8" height="19.8" rx="2" fill="#939495"/>
<path d="M1.8 7.2h16.2v10.8H1.8z" fill="#fff"/>
<path d="M1.8 3.6h16.2v1.8H1.8z" fill="#fff"/>
</g>
<rect x="0" y="' . $summaryTop . '" width="760" height="' . $topBlockHeight . '" fill="#f9f9f9"/>
<text x="28" y="' . ($summaryTop + 34) . '" class="title">Rezervasyon Özeti</text>
' . $svgRows . '
<rect x="0" y="' . $paymentTop . '" width="760" height="' . $paymentHeight . '" fill="#e5e7eb"/>
' . $svgPaymentRows . '
<rect x="0" y="' . ($paymentTop + $paymentHeight) . '" width="760" height="' . $bottomStripHeight . '" fill="#f9f9f9"/>
</svg>';
    }

    private function renderErrorSvg(string $message): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="760" height="220" viewBox="0 0 760 220">
<style>text{font-family:Manrope,"Segoe UI",Arial,sans-serif}</style>
<rect width="760" height="220" fill="#fff"/>
<rect x="20" y="20" width="720" height="180" rx="2" fill="#fff7f7" stroke="#fecaca"/>
<text x="48" y="80" font-size="18" font-weight="700" fill="#dc2626">Fiyat hesaplanamadı</text>
<text x="48" y="122" font-size="14" font-weight="600" fill="#555">' . $this->e($message) . '</text>
</svg>';
    }

    private function money(int $amount, string $symbol): string
    {
        return $symbol . number_format($amount, 0, ',', '.');
    }

    private function dateDay(string $date): string
    {
        return date('d', strtotime($date));
    }

    private function dateMonth(string $date): string
    {
        $months = [
            1 => 'Oca',
            2 => 'Şub',
            3 => 'Mar',
            4 => 'Nis',
            5 => 'May',
            6 => 'Haz',
            7 => 'Tem',
            8 => 'Ağu',
            9 => 'Eyl',
            10 => 'Eki',
            11 => 'Kas',
            12 => 'Ara',
        ];

        return $months[(int) date('n', strtotime($date))];
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
