<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Rezervasyon düzenleme (Natsisa "Detaylar & Finans" > Kaydet).
 *
 *   POST /backend-api/reservation-update  -> sadece gövdede gönderilen (değişen) alanları yazar
 *
 * Panel karşılığı: asp_scripts/scripts.asp islem=rez_kayit + KiralamaTakvimiScripts.asp (cacheIslem=scripts, rez_kayit).
 *
 * Akış (hepsi geçmeden hiçbir şey yazılmaz):
 *   1. Doğrulama (tarih, villa, kişi, tutar, oran, kod uzunluğu).
 *   2. Kiralama Takvimi eşleşmesi varsa villa değiştirilemez (önce açığa alınmalı).
 *   3. Tarih / villa değiştiyse çakışma: aynı villada dolu.durum 1 (opsiyon) / 3 (onaylı) ve dolu_fake.durum 3
 *      (AvailabilityController ile aynı kural). Aynı gün çıkış-giriş çakışma değildir.
 *   4. Villa takvime bağlı ve tarih değiştiyse panel API Reservation/UpdateDates (panel gibi); hata -> kayıt yok.
 *   5. Tek transaction: kayitlar + dolu (+ log). DB hatasında takvim tarihleri eskiye çekilmeye çalışılır.
 *
 * Tutarlar panel biçiminde, tam sayı yazılır: "56000 TL", "$ 1250", "€ 1250", "£ 1250". fnTemizle noktayı sildiği ve
 * money dönüşümü virgülü binlik saydığı için kuruş yazılmaz. Ek ücret 0 ise "Fiyata Dahil", havuz 0 ise "Ücretsiz"
 * (site 2: "Included Price" / "Free"). Para birimi değişirse tüm tutarlar yeni simgeyle yeniden yazılır (kur dönüşümü
 * yapılmaz). tutar (int, ödeme sayfası) = tur 1 ise ön ödeme, değilse toplam.
 *
 * Kolon adları reservationdetail ile aynı ayarlardan gelir (reservation_extras, reservation_discount); sitede olmayan
 * kolon yazılmaz. Her kayıt kullanici_log_kaydi'na değişen alanlarla (eski -> yeni) yazılır.
 */
final class ReservationUpdateController extends Controller
{
    /** doviz kodları: frontend (ISO) ya da panel adı -> panel adı. */
    private const CURRENCIES = [
        'tl' => 'tl', 'try' => 'tl',
        'dolar' => 'dolar', 'usd' => 'dolar',
        'euro' => 'euro', 'eur' => 'euro',
        'pound' => 'pound', 'gbp' => 'pound',
    ];

    /** Para birimi simgesi ve konumu (panel scripts.asp rez_kayit). */
    private const CURRENCY_FORMAT = [
        'tl' => ['', ' TL'],
        'dolar' => ['$ ', ''],
        'euro' => ['€ ', ''],
        'pound' => ['£ ', ''],
    ];

    private const EXTRAS_DEFAULTS = [
        'cleaning' => ['enabled' => 'auto', 'amount_column' => 'temizlik'],
        'electricity' => ['enabled' => 'auto', 'amount_column' => 'elektrik'],
        'pool' => ['enabled' => 'auto', 'flag_column' => 'isitmaliHavuzBool', 'amount_column' => 'isitmaliHavuz'],
    ];

    private const DISCOUNT_DEFAULTS = [
        'old_price_column' => 'eskifiyat',
        'total_column' => 'toplam_tutar',
        'promo_column' => 'promotionCode',
        'owner_column' => 'indirimevsahibi',
        'commission_column' => 'kazancorani',
    ];

    /** Ödeme şekli (kayitlar.odeme): 1 Kart, 2 Havale, 3 Western, 4 Sanal Kart, 5 Sanal Havale, 6 Nakit. */
    private const PAYMENT_METHODS = [1, 2, 3, 4, 5, 6];

    /** @var array<string,array<string,array{name:string,max:int|null,type:string}>> */
    private $columnCache = [];

    /** @var bool|null */
    private $hasLogDetailsColumn = null;

    /**
     * Rezervasyonu düzenler. Gövdede sadece değişen alanlar gönderilir; gönderilmeyen alana dokunulmaz.
     *
     * @Post
     * @body id int required Rezervasyon kimliği (kayitlar.id)
     * @body girisTarihi string yyyy-MM-dd
     * @body cikisTarihi string yyyy-MM-dd
     * @body evid int Villa (homes.id)
     * @body yetiskin int Yetişkin sayısı
     * @body cocuk int Çocuk sayısı
     * @body bebek int Bebek sayısı
     * @body satisKanaliId int Satış kanalı (satis_kanallari.id; boş/0 = yok)
     * @body gorevli string Görevli
     * @body doviz string tl|dolar|euro|pound (ya da TRY|USD|EUR|GBP)
     * @body tur int 1 Ön ödeme, 2 Tamamı
     * @body odeme int Ödeme şekli 1-6
     * @body virtualPosId int Sanal POS (boş/0 = yok)
     * @body listeFiyati number Liste (indirimsiz) fiyat (eskifiyat)
     * @body toplamTutar number Toplam (indirimli) fiyat
     * @body onOdeme number Ön ödeme
     * @body kalan number Kalan ödeme
     * @body kazancOrani int Komisyon oranı 0-100 (tam sayı)
     * @body onOdemeOrani number Ön ödeme oranı 0-100 (kayitlar.onOdemeOrani)
     * @body indirimiKarsilayan string site|ev_sahibi
     * @body promosyonKodu string En fazla 20 karakter
     * @body temizlik number Temizlik ücreti (0 = Fiyata Dahil)
     * @body elektrik number Elektrik ücreti (0 = Fiyata Dahil)
     * @body hasarDepozito number Hasar depozitosu (0 = Fiyata Dahil)
     * @body havuz object {istiyor: bool, tutar: number} (tutar 0 = Ücretsiz)
     * @body ekstraOdemeler array Ek ücretlerin son hali [{dbId?, baslik, tur (0 gecelik, 1 konaklama boyu), gece, tutar}];
     *       dbId'li güncellenir, dbId'siz eklenir, listede olmayan kayıtlı satır silinir (panel gibi)
     */
    public function update(): void
    {
        $pdo = $this->db->pdo();
        $body = $this->request->json();
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            throw new HttpException('Lütfen geçerli bir rezervasyon ID gönderin.', 'VALIDATION', 422);
        }

        $reservation = $this->fetchOne($pdo, 'SELECT * FROM kayitlar WHERE id = :id', [':id' => $id]);
        if (!$reservation) {
            throw new HttpException('Belirtilen ID ile rezervasyon bulunamadı.', 'NOT_FOUND', 404);
        }
        $dolu = $this->fetchOne(
            $pdo,
            'SELECT TOP 1 *, CONVERT(varchar(10), tarih, 23) AS tarihIso, CONVERT(varchar(10), tarih2, 23) AS tarih2Iso
             FROM dolu WHERE kayitid = :id',
            [':id' => $id]
        ) ?: [];

        $kayitlar = $this->columns($pdo, 'dbo.kayitlar');
        $set = [];      // kolon => değer (kayitlar)
        $labels = [];   // kolon => log etiketi
        $doluSet = [];  // kolon => değer (dolu)

        // ------------------------------------------------------------ tarihler
        $oldIn = $this->isoDate($reservation['rez_tarihi'] ?? null);
        $oldOut = $this->isoDate($reservation['gelecek_tarih'] ?? null);
        $newIn = array_key_exists('girisTarihi', $body) ? $this->requireDate($body['girisTarihi'], 'Giriş tarihi') : $oldIn;
        $newOut = array_key_exists('cikisTarihi', $body) ? $this->requireDate($body['cikisTarihi'], 'Çıkış tarihi') : $oldOut;
        $datesChanged = $newIn !== $oldIn || $newOut !== $oldOut;
        if ($datesChanged) {
            if ($newIn === '' || $newOut === '' || $newOut <= $newIn) {
                throw new HttpException('Çıkış tarihi giriş tarihinden sonra olmalı.', 'VALIDATION', 422);
            }
            $set['rez_tarihi'] = $newIn;
            $set['gelecek_tarih'] = $newOut;
            $labels['rez_tarihi'] = 'Giriş tarihi';
            $labels['gelecek_tarih'] = 'Çıkış tarihi';
            $doluSet['tarih'] = $newIn;
            $doluSet['tarih2'] = $newOut;
        }

        // ------------------------------------------------------------ villa
        $oldHome = (int) ($reservation['evid'] ?? 0);
        $newHome = array_key_exists('evid', $body) ? (int) $body['evid'] : $oldHome;
        $homeChanged = $newHome !== $oldHome;
        if ($homeChanged) {
            $home = $newHome > 0 ? $this->fetchOne($pdo, 'SELECT id, baslik FROM homes WHERE id = :id', [':id' => $newHome]) : false;
            if (!$home) {
                throw new HttpException('Seçilen villa bulunamadı.', 'VALIDATION', 422);
            }
            if ($this->isCalendarMapped($pdo, $id, $dolu)) {
                throw new HttpException(
                    'Rezervasyon Kiralama Takvimi\'nde kayıtlı. Villayı değiştirmek için önce rezervasyonu açığa alın.',
                    'KT_MAPPED',
                    409
                );
            }
            $set['evid'] = $newHome;
            $labels['evid'] = 'Villa';
            if (isset($kayitlar['adi'])) {
                $set['adi'] = (string) $home['baslik'];
                $labels['adi'] = 'Villa adı';
            }
            $doluSet['emlak'] = $newHome;
        }

        // ------------------------------------------------------------ çakışma
        if ($datesChanged || $homeChanged) {
            $this->assertNoOverlap($pdo, $id, $newHome, $newIn, $newOut);
        }

        // ------------------------------------------------------------ kişi, kanal, görevli, ödeme
        foreach (['yetiskin' => 'Yetişkin', 'cocuk' => 'Çocuk', 'bebek' => 'Bebek'] as $key => $label) {
            if (array_key_exists($key, $body)) {
                $set[$key] = $this->nonNegativeInt($body[$key], $label);
                $labels[$key] = $label;
            }
        }
        if (array_key_exists('satisKanaliId', $body)) {
            $channel = (int) $body['satisKanaliId'];
            $set['satis_kanallari_id'] = $channel > 0 ? $channel : null;
            $labels['satis_kanallari_id'] = 'Satış kanalı';
        }
        if (array_key_exists('gorevli', $body)) {
            $set['gorevli'] = $this->fit($kayitlar, 'gorevli', $this->text($body['gorevli']), 'Görevli');
            $labels['gorevli'] = 'Görevli';
        }
        if (array_key_exists('tur', $body)) {
            $type = (int) $body['tur'];
            if ($type !== 1 && $type !== 2) {
                throw new HttpException('Ödeme türü 1 (Ön ödeme) ya da 2 (Tamamı) olmalı.', 'VALIDATION', 422);
            }
            $set['tur'] = $type;
            $labels['tur'] = 'Ödeme türü';
        }
        if (array_key_exists('odeme', $body)) {
            $method = (int) $body['odeme'];
            if (!in_array($method, self::PAYMENT_METHODS, true)) {
                throw new HttpException('Geçersiz ödeme şekli.', 'VALIDATION', 422);
            }
            $set['odeme'] = $method;
            $labels['odeme'] = 'Ödeme şekli';
        }
        if (array_key_exists('virtualPosId', $body) && isset($kayitlar['virtualposid'])) {
            $pos = (int) $body['virtualPosId'];
            $set[$kayitlar['virtualposid']['name']] = $pos > 0 ? $pos : null;
            $labels[$kayitlar['virtualposid']['name']] = 'Sanal POS';
        }

        // ------------------------------------------------------------ para birimi + tutarlar
        $oldCurrency = $this->currency((string) ($reservation['doviz'] ?? ''), 'tl');
        $currency = $oldCurrency;
        if (array_key_exists('doviz', $body)) {
            $requested = strtolower(trim((string) $body['doviz']));
            if (!isset(self::CURRENCIES[$requested])) {
                throw new HttpException('Geçersiz para birimi.', 'VALIDATION', 422);
            }
            $currency = self::CURRENCIES[$requested];
        }
        $currencyChanged = $currency !== $oldCurrency;
        if ($currencyChanged) {
            $set['doviz'] = $currency;
            $labels['doviz'] = 'Para birimi';
        }

        $discount = array_replace(self::DISCOUNT_DEFAULTS, is_array($this->app['reservation_discount'] ?? null) ? $this->app['reservation_discount'] : []);
        $extras = array_replace_recursive(self::EXTRAS_DEFAULTS, is_array($this->app['reservation_extras'] ?? null) ? $this->app['reservation_extras'] : []);
        $siteTwo = (int) ($reservation['site'] ?? 0) === 2;
        $included = $siteTwo ? 'Included Price' : 'Fiyata Dahil';
        $free = $siteTwo ? 'Free' : 'Ücretsiz';

        // Ana tutarlar: [gövde anahtarı, kolon, etiket]
        $moneyFields = [
            ['listeFiyati', (string) $discount['old_price_column'], 'Liste fiyatı'],
            ['toplamTutar', (string) $discount['total_column'], 'Toplam tutar'],
            ['onOdeme', 'on_odeme', 'Ön ödeme'],
            ['kalan', 'kalan', 'Kalan'],
        ];
        $finalMoney = []; // kolon => tam sayı (tutar ve doğrulama için)
        foreach ($moneyFields as $field) {
            list($key, $column, $label) = $field;
            $col = $this->column($kayitlar, $column);
            if ($col === '') {
                continue;
            }
            if (array_key_exists($key, $body)) {
                $amount = $this->amount($body[$key], $label);
                $set[$col] = $this->formatMoney($amount, $currency);
                $labels[$col] = $label;
                $finalMoney[$column] = $amount;
            } else {
                $existing = $this->parseMoney($reservation[$col] ?? null);
                $finalMoney[$column] = $existing;
                if ($currencyChanged && $existing !== null) {
                    $set[$col] = $this->formatMoney($existing, $currency);
                    $labels[$col] = $label;
                }
            }
        }

        $totalCol = (string) $discount['total_column'];
        $total = $finalMoney[$totalCol] ?? null;
        $down = $finalMoney['on_odeme'] ?? null;
        if ($total !== null && $down !== null && $down > $total) {
            throw new HttpException('Ön ödeme toplam tutardan büyük olamaz.', 'VALIDATION', 422);
        }

        // Ek ücretler: temizlik, elektrik, hasar depozitosu (0 = Fiyata Dahil), havuz (0 = Ücretsiz).
        $feeFields = [
            ['temizlik', (string) ($extras['cleaning']['amount_column'] ?? ''), 'Temizlik', $included, $extras['cleaning']['enabled'] ?? 'auto'],
            ['elektrik', (string) ($extras['electricity']['amount_column'] ?? ''), 'Elektrik', $included, $extras['electricity']['enabled'] ?? 'auto'],
            ['hasarDepozito', 'hasar_dep', 'Hasar depozitosu', $included, 'auto'],
        ];
        foreach ($feeFields as $field) {
            list($key, $column, $label, $zeroText, $enabled) = $field;
            $col = $this->column($kayitlar, $column);
            if ($col === '' || ($enabled !== 'auto' && $enabled !== null && !$enabled)) {
                continue;
            }
            $this->feeChange($body, $key, $col, $label, $zeroText, $reservation, $currency, $currencyChanged, $set, $labels);
        }

        $poolCfg = is_array($extras['pool'] ?? null) ? $extras['pool'] : [];
        $poolEnabled = $poolCfg['enabled'] ?? 'auto';
        $poolAmountCol = $this->column($kayitlar, (string) ($poolCfg['amount_column'] ?? ''));
        $poolFlagCol = $this->column($kayitlar, (string) ($poolCfg['flag_column'] ?? ''));
        if ($poolAmountCol !== '' && ($poolEnabled === 'auto' || $poolEnabled === null || $poolEnabled)) {
            $pool = is_array($body['havuz'] ?? null) ? $body['havuz'] : null;
            if ($pool !== null && $poolFlagCol !== '' && array_key_exists('istiyor', $pool)) {
                $set[$poolFlagCol] = filter_var($pool['istiyor'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                $labels[$poolFlagCol] = 'Isıtmalı havuz isteniyor';
            }
            $poolBody = $pool !== null && array_key_exists('tutar', $pool) ? ['havuzTutar' => $pool['tutar']] : [];
            // Seçim kolonu yoksa (garantivillam: sadece kayitlar.isitma) "istemiyor" tutarı 0 yazar; yoksa tutar
            // kalır ve mail / sözleşme havuz ücretini göstermeye devam eder.
            if ($pool !== null && $poolFlagCol === '' && array_key_exists('istiyor', $pool)
                && !filter_var($pool['istiyor'], FILTER_VALIDATE_BOOLEAN)) {
                $poolBody = ['havuzTutar' => 0];
            }
            // Tutar sayı olarak yazılır: kolon sayısal tipteyse ya da amount_format = 'number' ise (garantivillam
            // kayitlar.isitma: panel type=number input, sözleşme "<tutar> TL" diye yazar). Aksi halde "1.500 TL".
            $poolNumeric = in_array($kayitlar[strtolower($poolAmountCol)]['type'] ?? '', ['int', 'bigint', 'smallint', 'tinyint', 'decimal', 'numeric', 'float', 'real', 'money', 'smallmoney'], true)
                || strtolower((string) ($poolCfg['amount_format'] ?? 'money')) === 'number';
            $this->feeChange($poolBody, 'havuzTutar', $poolAmountCol, 'Isıtmalı havuz', $free, $reservation, $currency, $currencyChanged, $set, $labels, $poolNumeric);
        }

        // tutar (int): ödeme sayfasının çektiği tutar; tur 1 ise ön ödeme, değilse toplam (panel rez_kayit).
        $type = (int) ($set['tur'] ?? $reservation['tur'] ?? 0);
        if (isset($kayitlar['tutar']) && (isset($set['tur']) || array_key_exists('onOdeme', $body) || array_key_exists('toplamTutar', $body))) {
            $payable = $type === 1 ? $down : $total;
            if ($payable !== null) {
                $set['tutar'] = $payable;
                $labels['tutar'] = 'Ödenecek tutar';
            }
        }

        // ------------------------------------------------------------ oranlar, indirim
        $commissionCol = $this->column($kayitlar, (string) $discount['commission_column']);
        if (array_key_exists('kazancOrani', $body) && $commissionCol !== '') {
            $rate = $body['kazancOrani'];
            if (!is_numeric($rate) || (float) $rate != (int) $rate || (int) $rate < 0 || (int) $rate > 100) {
                throw new HttpException('Komisyon oranı 0-100 arası tam sayı olmalı.', 'VALIDATION', 422);
            }
            $set[$commissionCol] = (int) $rate;
            $labels[$commissionCol] = 'Komisyon oranı';
        }
        if (array_key_exists('onOdemeOrani', $body) && isset($kayitlar['onodemeorani'])) {
            $rate = $body['onOdemeOrani'];
            if ($rate === null || $rate === '') {
                $set[$kayitlar['onodemeorani']['name']] = null;
            } elseif (!is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100) {
                throw new HttpException('Ön ödeme oranı 0-100 arası olmalı.', 'VALIDATION', 422);
            } else {
                // decimal(9,4)
                $set[$kayitlar['onodemeorani']['name']] = number_format(round((float) $rate, 4), 4, '.', '');
            }
            $labels[$kayitlar['onodemeorani']['name']] = 'Ön ödeme oranı';
        }
        $ownerCol = $this->column($kayitlar, (string) $discount['owner_column']);
        if (array_key_exists('indirimiKarsilayan', $body) && $ownerCol !== '') {
            $owner = (string) $body['indirimiKarsilayan'];
            if ($owner !== 'site' && $owner !== 'ev_sahibi') {
                throw new HttpException('İndirimi karşılayan site ya da ev_sahibi olmalı.', 'VALIDATION', 422);
            }
            $set[$ownerCol] = $owner === 'ev_sahibi' ? 1 : 0;
            $labels[$ownerCol] = 'İndirimi karşılayan';
        }
        $promoCol = $this->column($kayitlar, (string) $discount['promo_column']);
        if (array_key_exists('promosyonKodu', $body) && $promoCol !== '') {
            $code = $this->text($body['promosyonKodu']);
            $set[$promoCol] = $code === '' ? null : $this->fit($kayitlar, $promoCol, $code, 'Promosyon kodu');
            $labels[$promoCol] = 'Promosyon kodu';
        }

        // ------------------------------------------------------------ sadece gerçekten değişenler
        $changes = [];
        foreach ($set as $column => $value) {
            if (!isset($kayitlar[strtolower($column)])) {
                unset($set[$column]);
                continue;
            }
            $before = $this->display($column, $reservation[$column] ?? null);
            $after = $this->display($column, $value);
            if ($before === $after) {
                unset($set[$column]);
                continue;
            }
            $changes[] = ['alan' => $labels[$column] ?? $column, 'eski' => $before, 'yeni' => $after];
        }
        // ------------------------------------------------------------ ek ücretler (Reservation.ReservationExtraPayments)
        $extraOps = $this->extraPaymentOps($pdo, $id, $body, $currency, $currencyChanged);
        foreach ($extraOps['changes'] as $change) {
            $changes[] = $change;
        }

        if ($set === [] && $doluSet === [] && !$extraOps['hasWork']) {
            $this->response->success(['id' => $id, 'degisen' => 0]);
            return;
        }

        // ------------------------------------------------------------ Kiralama Takvimi tarih senkronu
        $calendarId = (int) ($dolu['ReservationId'] ?? 0);
        $calendarSynced = false;
        if ($datesChanged && $calendarId > 0 && $this->calendarHomeExists($pdo, $newHome)) {
            $this->updateCalendarDates($calendarId, $newIn, $newOut);
            $calendarSynced = true;
        }

        try {
            $this->transaction($pdo, function () use ($pdo, $id, $set, $doluSet, $dolu, $changes, $extraOps) {
                if ($set !== []) {
                    $assignments = [];
                    $params = [':id' => $id];
                    $i = 0;
                    foreach ($set as $column => $value) {
                        $assignments[] = '[' . $column . '] = :v' . $i;
                        $params[':v' . $i] = $value;
                        $i++;
                    }
                    $stmt = $pdo->prepare('UPDATE kayitlar SET ' . implode(', ', $assignments) . ' WHERE id = :id');
                    $this->execute($stmt, $params);
                }
                if ($doluSet !== [] && $dolu !== []) {
                    $assignments = [];
                    $params = [':id' => $id];
                    foreach ($doluSet as $column => $value) {
                        $assignments[] = '[' . $column . '] = :' . $column;
                        $params[':' . $column] = $value;
                    }
                    $stmt = $pdo->prepare('UPDATE dolu SET ' . implode(', ', $assignments) . ' WHERE kayitid = :id');
                    $this->execute($stmt, $params);
                }
                $this->applyExtraPaymentOps($pdo, $id, $extraOps);
                $this->log($pdo, $id, $id . ' numaralı rezervasyonu düzenledi.', 'rezervasyon-duzenle', $changes);
            });
        } catch (\Throwable $e) {
            // Takvim yeni tarihleri aldı ama DB yazılamadı: takvimi eski tarihlere döndürmeye çalış.
            if ($calendarSynced && $oldIn !== '' && $oldOut !== '') {
                try {
                    $this->updateCalendarDates($calendarId, $oldIn, $oldOut);
                } catch (\Throwable $revertError) {
                    try {
                        $this->log($pdo, $id, 'Kiralama Takvimi tarihleri güncellendi ancak rezervasyon kaydedilemedi; takvim geri alınamadı.', 'rezervasyon-duzenle-hata', $changes);
                    } catch (\Throwable $ignored) {
                        // log da yazılamazsa asıl hata dönsün
                    }
                }
            }
            throw $e;
        }

        $this->response->success([
            'id' => $id,
            'degisen' => count($changes),
            'degisiklikler' => $changes,
            'kiralamatakvimi' => $calendarSynced,
        ]);
    }

    // ---------------------------------------------------------------- kurallar

    /** Rezervasyon Kiralama Takvimi'yle eşleşmiş mi (dolu.ReservationId ya da KiralamaTakvimi.Response). */
    private function isCalendarMapped(PDO $pdo, int $id, array $dolu): bool
    {
        if ((int) ($dolu['ReservationId'] ?? 0) > 0) {
            return true;
        }
        if (!$this->objectExists($pdo, 'KiralamaTakvimi.Response')) {
            return false;
        }

        return (bool) $this->fetchOne($pdo, 'SELECT TOP 1 1 AS x FROM KiralamaTakvimi.Response WHERE kayitlarId = :id', [':id' => $id]);
    }

    private function calendarHomeExists(PDO $pdo, int $homeId): bool
    {
        if ($homeId <= 0 || !$this->objectExists($pdo, 'KiralamaTakvimi.CalendarHomes')) {
            return false;
        }

        return (bool) $this->fetchOne($pdo, 'SELECT TOP 1 1 AS x FROM KiralamaTakvimi.CalendarHomes WHERE homesId = :id', [':id' => $homeId]);
    }

    /**
     * Aynı villada aktif rezervasyon (dolu.durum 1 opsiyon / 3 onaylı) ya da kapatılmış tarih (dolu_fake.durum 3)
     * ile çakışma varsa 409. Aynı gün çıkış-giriş çakışma sayılmaz.
     */
    private function assertNoOverlap(PDO $pdo, int $id, int $homeId, string $in, string $out): void
    {
        $conflict = $this->fetchOne(
            $pdo,
            'SELECT TOP 1 kayitid, durum, CONVERT(varchar(10), tarih, 104) AS t1, CONVERT(varchar(10), tarih2, 104) AS t2
             FROM dolu
             WHERE emlak = :home AND kayitid <> :id AND durum IN (1, 3)
               AND CONVERT(date, tarih) < :out AND CONVERT(date, tarih2) > :in
             ORDER BY tarih',
            [':home' => $homeId, ':id' => $id, ':in' => $in, ':out' => $out]
        );
        if ($conflict) {
            $status = (int) $conflict['durum'] === 1 ? 'opsiyonlu' : 'onaylı';
            throw new HttpException(
                'Villa bu tarihlerde dolu: ' . (int) $conflict['kayitid'] . ' numaralı ' . $status . ' rezervasyon ('
                . $conflict['t1'] . ' - ' . $conflict['t2'] . ').',
                'OVERLAP',
                409
            );
        }

        if ($this->objectExists($pdo, 'dbo.dolu_fake')) {
            $closed = $this->fetchOne(
                $pdo,
                'SELECT TOP 1 CONVERT(varchar(10), tarih, 104) AS t1, CONVERT(varchar(10), tarih2, 104) AS t2
                 FROM dolu_fake
                 WHERE emlak = :home AND durum = 3 AND CONVERT(date, tarih) < :out AND CONVERT(date, tarih2) > :in
                 ORDER BY tarih',
                [':home' => $homeId, ':in' => $in, ':out' => $out]
            );
            if ($closed) {
                throw new HttpException(
                    'Villa bu tarihlerde kapalı (' . $closed['t1'] . ' - ' . $closed['t2'] . ').',
                    'OVERLAP',
                    409
                );
            }
        }
    }

    /** Panel KiralamaTakvimiScripts.asp: POST Reservation/UpdateDates, yanıt "0;/..." başarılı. */
    private function updateCalendarDates(int $calendarId, string $in, string $out): void
    {
        $raw = $this->panelApi('POST', 'Reservation/UpdateDates', [
            'reservationId' => $calendarId,
            'checkInDate' => $in,
            'checkOutDate' => $out,
        ]);
        $parts = explode(';/', $raw);
        if (trim((string) ($parts[0] ?? '')) !== '0') {
            $message = trim((string) ($parts[1] ?? ''));
            throw new HttpException(
                'Kiralama Takvimi tarihleri güncellemedi' . ($message !== '' ? ': ' . $message : '.'),
                'KT_UPDATE_DATES_FAILED',
                409
            );
        }
    }

    /**
     * Ek ücret alanı: gövdede varsa yazar (0 -> metin), yoksa para birimi değiştiyse mevcut sayıyı yeni simgeyle yazar.
     *
     * @param array<string,mixed> $body
     * @param array<string,mixed> $reservation
     * @param array<string,mixed> $set
     * @param array<string,string> $labels
     */
    private function feeChange(
        array $body,
        string $key,
        string $col,
        string $label,
        string $zeroText,
        array $reservation,
        string $currency,
        bool $currencyChanged,
        array &$set,
        array &$labels,
        bool $plainNumber = false
    ): void {
        if (array_key_exists($key, $body)) {
            $amount = $this->amount($body[$key], $label);
            if ($plainNumber) {
                $set[$col] = $amount;
            } else {
                $set[$col] = $amount === 0 ? $zeroText : $this->formatMoney($amount, $currency);
            }
            $labels[$col] = $label;
            return;
        }
        if ($currencyChanged && !$plainNumber) {
            $existing = $this->parseMoney($reservation[$col] ?? null);
            if ($existing !== null && $existing > 0) {
                $set[$col] = $this->formatMoney($existing, $currency);
                $labels[$col] = $label;
            }
        }
    }

    // ---------------------------------------------------------------- ek ücretler

    /**
     * Ek ücret işlemlerini hesaplar (yazmaz). Gövdede ekstraOdemeler listesi rezervasyonun son halidir (panel gibi):
     * dbId'li satır güncellenir, dbId'siz satır eklenir, listede olmayan kayıtlı satır silinir. Liste gönderilmezse
     * satırlara dokunulmaz; sadece para birimi değiştiyse satırların para birimi güncellenir.
     * Amount / TotalAmount decimal(12) (küsuratsız) -> tam sayı. Toplam sunucuda hesaplanır: gecelik (Type 0) =
     * gece × tutar, konaklama boyu (Type 1) = tutar.
     *
     * @param array<string,mixed> $body
     * @return array{hasWork:bool,changes:array<int,array{alan:string,eski:string,yeni:string}>,insert:array,update:array,delete:array,currency:?array,retarget:bool}
     */
    private function extraPaymentOps(PDO $pdo, int $id, array $body, string $currency, bool $currencyChanged): array
    {
        // currency: yeni satırların para birimi; retarget: kayıtlı satırlar da bu para birimine geçsin (doviz değişti).
        $ops = ['hasWork' => false, 'changes' => [], 'insert' => [], 'update' => [], 'delete' => [], 'currency' => null, 'retarget' => false];
        $sent = array_key_exists('ekstraOdemeler', $body);
        if (!$sent && !$currencyChanged) {
            return $ops;
        }
        if (!$this->objectExists($pdo, 'Reservation.ReservationExtraPayments')) {
            if ($sent && is_array($body['ekstraOdemeler']) && $body['ekstraOdemeler'] !== []) {
                throw new HttpException('Bu sitede ek ücret tablosu (Reservation.ReservationExtraPayments) yok.', 'SETUP_REQUIRED', 409);
            }
            return $ops;
        }

        $currencyRow = $this->fetchOne(
            $pdo,
            'SELECT TOP 1 CurrencyId, CurrencyCode FROM Finance.Currency WHERE CurrencyName = :name',
            [':name' => $currency]
        );
        if (!$currencyRow) {
            throw new HttpException('Para birimi Finance.Currency tablosunda bulunamadı: ' . $currency, 'VALIDATION', 422);
        }
        $ops['currency'] = ['id' => (int) $currencyRow['CurrencyId'], 'code' => (string) $currencyRow['CurrencyCode']];

        $existing = [];
        foreach ($this->fetchAll(
            $pdo,
            'SELECT PaymentId, Title, Type, Night, Amount, TotalAmount FROM Reservation.ReservationExtraPayments WHERE ReservationId = :id',
            [':id' => $id]
        ) as $row) {
            $existing[(int) $row['PaymentId']] = $row;
        }

        if ($currencyChanged && $existing !== []) {
            $ops['retarget'] = true;
            $ops['hasWork'] = true;
        }
        if (!$sent) {
            return $ops;
        }
        if (!is_array($body['ekstraOdemeler'])) {
            throw new HttpException('Ek ücretler liste olmalı.', 'VALIDATION', 422);
        }

        $describe = function (string $title, int $type, int $night, int $amount, int $total) use ($currency): string {
            return $title . ' · ' . ($type === 0 ? $night . ' gece × ' . $this->formatMoney($amount, $currency) : 'konaklama boyu')
                . ' = ' . $this->formatMoney($total, $currency);
        };

        $kept = [];
        foreach ($body['ekstraOdemeler'] as $index => $item) {
            if (!is_array($item)) {
                throw new HttpException('Ek ücret satırı geçersiz.', 'VALIDATION', 422);
            }
            $title = $this->text($item['baslik'] ?? '');
            if ($title === '') {
                throw new HttpException('Ek ücret başlığı boş olamaz (' . ($index + 1) . '. satır).', 'VALIDATION', 422);
            }
            if (mb_strlen($title) > 250) {
                throw new HttpException('Ek ücret başlığı en fazla 250 karakter olabilir.', 'VALIDATION', 422);
            }
            $type = (int) ($item['tur'] ?? 1) === 0 ? 0 : 1;
            $night = $type === 0 ? $this->nonNegativeInt($item['gece'] ?? 0, 'Ek ücret gece sayısı') : (int) ($item['gece'] ?? 1);
            $amount = $this->amount($item['tutar'] ?? 0, 'Ek ücret tutarı');
            $total = $type === 0 ? $night * $amount : $amount;
            $row = ['Title' => $title, 'Type' => $type, 'Night' => $night, 'Amount' => $amount, 'TotalAmount' => $total];

            $dbId = (int) ($item['dbId'] ?? 0);
            if ($dbId > 0) {
                if (!isset($existing[$dbId])) {
                    throw new HttpException('Ek ücret bu rezervasyonda bulunamadı (' . $dbId . ').', 'NOT_FOUND', 404);
                }
                $kept[$dbId] = true;
                $old = $existing[$dbId];
                $oldRow = [
                    'Title' => trim((string) $old['Title']),
                    'Type' => (int) $old['Type'],
                    'Night' => (int) $old['Night'],
                    'Amount' => (int) round((float) $old['Amount']),
                    'TotalAmount' => (int) round((float) $old['TotalAmount']),
                ];
                if ($oldRow !== $row) {
                    $ops['update'][$dbId] = $row;
                    $ops['changes'][] = [
                        'alan' => 'Ek ücret',
                        'eski' => $describe($oldRow['Title'], $oldRow['Type'], $oldRow['Night'], $oldRow['Amount'], $oldRow['TotalAmount']),
                        'yeni' => $describe($title, $type, $night, $amount, $total),
                    ];
                }
            } else {
                $ops['insert'][] = $row;
                $ops['changes'][] = ['alan' => 'Ek ücret', 'eski' => '', 'yeni' => $describe($title, $type, $night, $amount, $total)];
            }
        }

        foreach ($existing as $paymentId => $old) {
            if (isset($kept[$paymentId])) {
                continue;
            }
            $ops['delete'][] = $paymentId;
            $ops['changes'][] = [
                'alan' => 'Ek ücret',
                'eski' => $describe(
                    trim((string) $old['Title']),
                    (int) $old['Type'],
                    (int) $old['Night'],
                    (int) round((float) $old['Amount']),
                    (int) round((float) $old['TotalAmount'])
                ),
                'yeni' => '',
            ];
        }

        $ops['hasWork'] = $ops['hasWork'] || $ops['insert'] !== [] || $ops['update'] !== [] || $ops['delete'] !== [];

        return $ops;
    }

    /**
     * extraPaymentOps sonucunu yazar (transaction içinde çağrılır).
     *
     * @param array{hasWork:bool,insert:array,update:array,delete:array,currency:?array,retarget:bool} $ops
     */
    private function applyExtraPaymentOps(PDO $pdo, int $id, array $ops): void
    {
        if (!$ops['hasWork']) {
            return;
        }
        foreach ($ops['delete'] as $paymentId) {
            $stmt = $pdo->prepare('DELETE FROM Reservation.ReservationExtraPayments WHERE PaymentId = :pid AND ReservationId = :id');
            $this->execute($stmt, [':pid' => (int) $paymentId, ':id' => $id]);
        }
        foreach ($ops['update'] as $paymentId => $row) {
            $stmt = $pdo->prepare(
                'UPDATE Reservation.ReservationExtraPayments
                 SET Title = :title, Type = :type, Night = :night, Amount = :amount, TotalAmount = :total
                 WHERE PaymentId = :pid AND ReservationId = :id'
            );
            $this->execute($stmt, [
                ':title' => $row['Title'], ':type' => $row['Type'], ':night' => $row['Night'],
                ':amount' => $row['Amount'], ':total' => $row['TotalAmount'], ':pid' => (int) $paymentId, ':id' => $id,
            ]);
        }
        $currency = $ops['currency'];
        foreach ($ops['insert'] as $row) {
            $stmt = $pdo->prepare(
                'INSERT INTO Reservation.ReservationExtraPayments
                    (ReservationId, Title, Type, Night, Amount, TotalAmount, CurrencyId, CurrencyCode)
                 VALUES (:id, :title, :type, :night, :amount, :total, :cid, :ccode)'
            );
            $this->execute($stmt, [
                ':id' => $id, ':title' => $row['Title'], ':type' => $row['Type'], ':night' => $row['Night'],
                ':amount' => $row['Amount'], ':total' => $row['TotalAmount'],
                ':cid' => (int) $currency['id'], ':ccode' => (string) $currency['code'],
            ]);
        }
        // Para birimi değişince kayıtlı satırlar da yeni para birimine geçer (tutarlar aynen kalır).
        if ($ops['retarget'] && $currency !== null) {
            $stmt = $pdo->prepare(
                'UPDATE Reservation.ReservationExtraPayments SET CurrencyId = :cid, CurrencyCode = :ccode WHERE ReservationId = :id'
            );
            $this->execute($stmt, [':cid' => (int) $currency['id'], ':ccode' => (string) $currency['code'], ':id' => $id]);
        }
    }

    // ---------------------------------------------------------------- biçim

    private function formatMoney(int $amount, string $currency): string
    {
        $format = self::CURRENCY_FORMAT[$currency] ?? self::CURRENCY_FORMAT['tl'];

        return $format[0] . $amount . $format[1];
    }

    /**
     * Kayıtlı tutar metnini tam sayıya çevirir ("56000 TL", "₺40000", "$ 1250"). Sayı yoksa (örn. "Fiyata Dahil") null.
     *
     * @param mixed $value
     */
    private function parseMoney($value): ?int
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '' || preg_match('/\d/', $text) !== 1) {
            return null;
        }
        $number = preg_replace('/[^\d,.-]/', '', $text);
        if (strpos((string) $number, ',') !== false) {
            $number = str_replace(',', '.', str_replace('.', '', (string) $number));
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', (string) $number) === 1) {
            $number = str_replace('.', '', (string) $number);
        }

        return is_numeric($number) ? (int) round((float) $number) : null;
    }

    /**
     * Gövdedeki tutar: 0 ya da pozitif sayı, tam sayıya yuvarlanır.
     *
     * @param mixed $value
     */
    private function amount($value, string $label): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (!is_numeric($value) || (float) $value < 0) {
            throw new HttpException($label . ' geçersiz.', 'VALIDATION', 422);
        }

        return (int) round((float) $value);
    }

    /** @param mixed $value */
    private function nonNegativeInt($value, string $label): int
    {
        if (!is_numeric($value) || (int) $value < 0 || (float) $value != (int) $value) {
            throw new HttpException($label . ' 0 ya da pozitif tam sayı olmalı.', 'VALIDATION', 422);
        }

        return (int) $value;
    }

    /** @param mixed $value */
    private function requireDate($value, string $label): string
    {
        $text = trim((string) $value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new HttpException($label . ' geçersiz (yyyy-MM-dd bekleniyor).', 'VALIDATION', 422);
        }

        return $text;
    }

    /** @param mixed $value DB tarihi (DateTime metni) -> yyyy-MM-dd */
    private function isoDate($value): string
    {
        $text = trim((string) $value);

        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $m) === 1 ? $m[1] . '-' . $m[2] . '-' . $m[3] : '';
    }

    private function currency(string $value, string $default): string
    {
        $key = strtolower(trim($value));

        return self::CURRENCIES[$key] ?? $default;
    }

    /** @param mixed $value */
    private function text($value): string
    {
        if ($value === null || is_array($value)) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    /**
     * Log için karşılaştırılabilir metin (tarih -> yyyy-MM-dd, bit -> 0/1, ondalık sondaki sıfırlar atılır).
     *
     * @param mixed $value
     */
    private function display(string $column, $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        $text = trim((string) $value);
        $lower = strtolower($column);
        if ($lower === 'rez_tarihi' || $lower === 'gelecek_tarih') {
            return $this->isoDate($text);
        }
        if (is_numeric($text) && strpos($text, '.') !== false) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return $text;
    }

    // ---------------------------------------------------------------- DB yardımcıları

    /**
     * Ayardaki kolon adının sitedeki gerçek adı; yoksa ''.
     *
     * @param array<string,array{name:string,max:int|null,type:string}> $columns
     */
    private function column(array $columns, string $name): string
    {
        return $name !== '' && isset($columns[strtolower($name)]) ? $columns[strtolower($name)]['name'] : '';
    }

    /**
     * @return array<string,array{name:string,max:int|null,type:string}>
     */
    private function columns(PDO $pdo, string $table): array
    {
        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }
        $parts = explode('.', $table, 2);
        $rows = $this->fetchAll(
            $pdo,
            'SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table',
            [':schema' => $parts[0], ':table' => $parts[1]]
        );
        $columns = [];
        foreach ($rows as $row) {
            $max = $row['CHARACTER_MAXIMUM_LENGTH'];
            $columns[strtolower((string) $row['COLUMN_NAME'])] = [
                'name' => (string) $row['COLUMN_NAME'],
                'max' => $max === null || (int) $max < 0 ? null : (int) $max,
                'type' => strtolower((string) $row['DATA_TYPE']),
            ];
        }
        $this->columnCache[$table] = $columns;

        return $columns;
    }

    /**
     * Kolon sınırını aşan metin kesilmez, hata döner.
     *
     * @param array<string,array{name:string,max:int|null,type:string}> $columns
     */
    private function fit(array $columns, string $column, string $value, string $label): string
    {
        $max = $columns[strtolower($column)]['max'] ?? null;
        if ($max !== null && mb_strlen($value) > $max) {
            throw new HttpException($label . ' en fazla ' . $max . ' karakter olabilir.', 'VALIDATION', 422);
        }

        return $value;
    }

    private function objectExists(PDO $pdo, string $name): bool
    {
        $stmt = $pdo->prepare('SELECT CASE WHEN OBJECT_ID(:name) IS NULL THEN 0 ELSE 1 END');
        $stmt->bindValue(':name', $name);
        $stmt->execute();

        return (int) $stmt->fetchColumn() === 1;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|false
     */
    private function fetchOne(PDO $pdo, string $sql, array $params = [])
    {
        $stmt = $pdo->prepare($sql);
        $this->execute($stmt, $params);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    private function fetchAll(PDO $pdo, string $sql, array $params = []): array
    {
        $stmt = $pdo->prepare($sql);
        $this->execute($stmt, $params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Parametreleri tipine göre bağlayıp çalıştırır (null -> NULL, int -> INT).
     *
     * @param array<string,mixed> $params
     */
    private function execute(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            if ($value === null) {
                $stmt->bindValue($key, null, PDO::PARAM_NULL);
            } elseif (is_int($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
            }
        }
        $stmt->execute();
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

    // ---------------------------------------------------------------- log (ReservationStatusController ile aynı biçim)

    /**
     * @param array<int,array{alan:string,eski:string,yeni:string}> $changes
     */
    private function log(PDO $pdo, int $id, string $message, string $action, array $changes): void
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
        $details = [
            'kaynak' => $this->natsisaUserId() > 0 || $userName !== '' ? 'natsisa' : 'api',
            'kullaniciAdi' => $userName,
            'natsisaKullaniciId' => $this->natsisaUserId(),
            'islem' => $action,
            'alanlar' => $changes,
        ];
        $actionCode = (string) $this->request->input('islem_kodu', '');
        if (preg_match('/^[A-Za-z0-9-]{1,64}$/', $actionCode) === 1) {
            $details['islemKodu'] = $actionCode;
        }

        if ($this->hasLogDetailsColumn($pdo)) {
            $json = json_encode($details, JSON_UNESCAPED_UNICODE);
            $stmt = $pdo->prepare(
                "INSERT INTO kullanici_log_kaydi (islm, islm_id, aciklama, kullanici_id, degisiklikler)
                 VALUES ('rezervasyon', :islmId, :aciklama, :kullaniciId, :degisiklikler)"
            );
            $params[':degisiklikler'] = $json === false ? null : $json;
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO kullanici_log_kaydi (islm, islm_id, aciklama, kullanici_id)
                 VALUES ('rezervasyon', :islmId, :aciklama, :kullaniciId)"
            );
        }
        $this->execute($stmt, $params);
    }

    private function userName(): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $this->request->input('kullanici_adi', '')));

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

    // ---------------------------------------------------------------- panel API (ReservationStatusController ile aynı)

    /**
     * Panel API'sine istek atar, ham metin yanıtı döner ("0;/..." gibi düz metin).
     *
     * @param array<string,mixed> $data
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
                'Kiralama Takvimi (panel API) adresine ulaşılamadı.' . (!empty($this->app['debug']) ? ' | ' . $url . ' | ' . $error : ''),
                'PANEL_UNREACHABLE',
                502
            );
        }

        return trim((string) $raw);
    }

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
}
