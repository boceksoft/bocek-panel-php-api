<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Yeni rezervasyon (Natsisa /rezervasyonlar/yeni).
 *
 *   GET  /backend-api/reservations-create -> form bilgileri (siteler, bu panelde olan ek ücret / indirim alanları)
 *   POST /backend-api/reservations-create -> rezervasyonu oluşturur (kayitlar + dolu), yeni id'yi döner
 *
 * Panel karşılığı: yeni_kayit_ekle.asp + asp_scripts/scripts.asp islem=kayit_ekle.
 *
 * Rezervasyon "Onay Bekliyor" (dolu.durum 0) olarak açılır; Kiralama Takvimi'ne gönderilmez, müşteriye bildirim
 * gitmez. Opsiyon / onay, rezervasyon sayfasındaki durum butonlarıyla (reservation-status) yapılır; panel de kaydı
 * açtıktan sonra aynı işi aktif_pasif.asp ile yapar.
 *
 * Tutarlar burada sıfır (panel biçiminde) yazılır. Fiyat, ödeme, ek ücret ve indirimler oluşturmadan hemen sonra
 * reservation-update ile yazılır (aynı doğrulama ve log). Natsisa ön yüzü ikisini sırayla çağırır.
 *
 * Çakışma: aynı villada dolu.durum 1 (opsiyon) / 3 (onaylı) ya da dolu_fake.durum 3 (kapalı) varsa kayıt açılmaz
 * (reservation-update ile aynı kural). Aynı gün çıkış-giriş çakışma değildir.
 */
final class ReservationsCreateController extends Controller
{
    /** doviz kodları: frontend (ISO) ya da panel adı -> panel adı. */
    private const CURRENCIES = [
        'tl' => 'tl', 'try' => 'tl',
        'dolar' => 'dolar', 'usd' => 'dolar',
        'euro' => 'euro', 'eur' => 'euro',
        'pound' => 'pound', 'gbp' => 'pound',
    ];

    /** Para birimi simgesi ve konumu (reservation-update ile aynı). */
    private const CURRENCY_FORMAT = [
        'tl' => ['', ' TL'],
        'dolar' => ['$ ', ''],
        'euro' => ['€ ', ''],
        'pound' => ['£ ', ''],
    ];

    /** reservation_extras varsayılanları (reservationdetail ile aynı). */
    private const EXTRAS_DEFAULTS = [
        'cleaning' => ['enabled' => 'auto', 'amount_column' => 'temizlik'],
        'electricity' => ['enabled' => 'auto', 'amount_column' => 'elektrik'],
        'pool' => ['enabled' => 'auto', 'flag_column' => 'isitmaliHavuzBool', 'amount_column' => 'isitmaliHavuz'],
        'extra_payments' => [
            'enabled' => 'auto',
            'sources' => ['Reservation.vw_ReservationExtraPayments', 'Reservation.ReservationExtraPayments'],
        ],
    ];

    /** reservation_discount varsayılanları (reservationdetail ile aynı). */
    private const DISCOUNT_DEFAULTS = [
        'old_price_column' => 'eskifiyat',
        'total_column' => 'toplam_tutar',
        'promo_column' => 'promotionCode',
        'owner_column' => 'indirimevsahibi',
    ];

    /** Müşteri alanı -> kayitlar kolon adayları (reservation-guests/customer ile aynı) ve etiket. */
    private const CUSTOMER_FIELDS = [
        'musteri' => [['musteri'], 'Ad Soyad'],
        'email' => [['email'], 'E-posta'],
        'telefon' => [['telefon'], 'Telefon'],
        'telefon2' => [['phone2', 'telefon2'], 'Telefon 2'],
        'tc' => [['tc'], 'TC No'],
        'adres' => [['adres'], 'Adres'],
    ];

    /** Ödeme şekli (kayitlar.odeme): 1 Kart, 2 Havale, 3 Western, 4 Sanal Kart, 5 Sanal Havale, 6 Nakit. */
    private const PAYMENT_METHODS = [1, 2, 3, 4, 5, 6];

    /** @var array<string,array<string,array{name:string,max:int|null,type:string}>> */
    private $columnCache = [];

    /** @var bool|null */
    private $hasLogDetailsColumn = null;

    /**
     * Yeni rezervasyon formu için bilgiler: siteler ve bu panelde olan ek ücret / indirim alanları
     * (reservationdetail ekUcretler.ozellikler / indirim.ozellikler ile aynı anahtarlar).
     *
     * @Get
     */
    public function index(): void
    {
        $pdo = $this->db->pdo();
        $kayitlar = $this->columns($pdo, 'dbo.kayitlar');
        $has = function (string $name) use ($kayitlar): bool {
            return $name !== '' && isset($kayitlar[strtolower($name)]);
        };

        $local = is_array($this->app['reservation_extras'] ?? null) ? $this->app['reservation_extras'] : [];
        $extras = array_replace_recursive(self::EXTRAS_DEFAULTS, $local);
        if (isset($local['extra_payments']['sources'])) {
            $extras['extra_payments']['sources'] = (array) $local['extra_payments']['sources'];
        }
        $enabled = function ($item, bool $detected): bool {
            $flag = is_array($item) ? ($item['enabled'] ?? 'auto') : 'auto';
            if ($flag === 'auto' || $flag === null) {
                return $detected;
            }

            return (bool) $flag && $detected;
        };

        $extraPayments = false;
        $extraCfg = is_array($extras['extra_payments'] ?? null) ? $extras['extra_payments'] : [];
        if ($enabled($extraCfg, true)) {
            foreach ((array) ($extraCfg['sources'] ?? []) as $candidate) {
                if (is_string($candidate) && $candidate !== '' && $this->objectExists($pdo, $candidate)) {
                    $extraPayments = true;
                    break;
                }
            }
        }

        $discount = array_replace(
            self::DISCOUNT_DEFAULTS,
            is_array($this->app['reservation_discount'] ?? null) ? $this->app['reservation_discount'] : []
        );

        $sites = [];
        foreach ($this->sites($pdo) as $site) {
            $sites[] = ['id' => $site['id'], 'ad' => $site['site'], 'kisa' => $site['kisa']];
        }

        $this->response->success([
            'siteler' => $sites,
            'varsayilanSite' => $sites !== [] ? $sites[0]['id'] : 1,
            'ekUcretler' => [
                'ozellikler' => [
                    'temizlik' => $enabled($extras['cleaning'] ?? [], $has((string) ($extras['cleaning']['amount_column'] ?? ''))),
                    'elektrik' => $enabled($extras['electricity'] ?? [], $has((string) ($extras['electricity']['amount_column'] ?? ''))),
                    'havuz' => $enabled($extras['pool'] ?? [], $has((string) ($extras['pool']['amount_column'] ?? ''))),
                    'ekstraOdemeler' => $extraPayments,
                    'eskiEkUcretler' => false,
                ],
            ],
            'indirim' => [
                'ozellikler' => [
                    'eskiFiyat' => $has((string) $discount['old_price_column']),
                    'promosyon' => $has((string) $discount['promo_column']),
                    'karsilayan' => $has((string) $discount['owner_column']),
                ],
            ],
        ]);
    }

    /**
     * Rezervasyonu "Onay Bekliyor" olarak oluşturur (kayitlar + dolu, tek transaction, log).
     *
     * @Post
     * @body evid int required Villa (homes.id)
     * @body girisTarihi string required yyyy-MM-dd
     * @body cikisTarihi string required yyyy-MM-dd
     * @body musteri string required Ad soyad
     * @body site int Site (sites.id; boşsa ilk site)
     * @body yetiskin int Yetişkin sayısı (varsayılan 1)
     * @body cocuk int Çocuk sayısı
     * @body bebek int Bebek sayısı
     * @body email string E-posta
     * @body telefon string Telefon
     * @body telefon2 string Telefon 2
     * @body tc string TC no
     * @body adres string Adres
     * @body satisKanaliId int Satış kanalı (satis_kanallari.id; boş/0 = yok)
     * @body doviz string tl|dolar|euro|pound (ya da TRY|USD|EUR|GBP; varsayılan tl)
     * @body tur int 1 Ön ödeme (varsayılan), 2 Tamamı
     * @body odeme int Ödeme şekli 1-6
     */
    public function create(): void
    {
        $pdo = $this->db->pdo();
        $body = $this->request->json();
        $kayitlar = $this->columns($pdo, 'dbo.kayitlar');
        $doluColumns = $this->columns($pdo, 'dbo.dolu');

        // ------------------------------------------------------------ doğrulama
        $in = $this->requireDate($body['girisTarihi'] ?? '', 'Giriş tarihi');
        $out = $this->requireDate($body['cikisTarihi'] ?? '', 'Çıkış tarihi');
        if ($out <= $in) {
            throw new HttpException('Çıkış tarihi giriş tarihinden sonra olmalı.', 'VALIDATION', 422);
        }
        $nights = (int) round((strtotime($out . ' 12:00:00') - strtotime($in . ' 12:00:00')) / 86400);

        $homeId = (int) ($body['evid'] ?? 0);
        $home = $homeId > 0 ? $this->fetchOne($pdo, 'SELECT * FROM homes WHERE id = :id', [':id' => $homeId]) : false;
        if (!$home) {
            throw new HttpException('Lütfen bir villa seçin.', 'VALIDATION', 422);
        }

        $siteId = (int) ($body['site'] ?? 0);
        $site = null;
        foreach ($this->sites($pdo) as $row) {
            if ($siteId <= 0 || $row['id'] === $siteId) {
                $site = $row;
                break;
            }
        }
        if ($site === null) {
            throw new HttpException('Seçilen site bulunamadı.', 'VALIDATION', 422);
        }

        $customer = $this->text($body['musteri'] ?? '');
        if ($customer === '') {
            throw new HttpException('Müşteri adı soyadı zorunlu.', 'VALIDATION', 422);
        }

        $currency = 'tl';
        if (array_key_exists('doviz', $body) && trim((string) $body['doviz']) !== '') {
            $requested = strtolower(trim((string) $body['doviz']));
            if (!isset(self::CURRENCIES[$requested])) {
                throw new HttpException('Geçersiz para birimi.', 'VALIDATION', 422);
            }
            $currency = self::CURRENCIES[$requested];
        }

        $type = (int) ($body['tur'] ?? 0) !== 0 ? (int) $body['tur'] : 1;
        if ($type !== 1 && $type !== 2) {
            throw new HttpException('Ödeme türü 1 (Ön ödeme) ya da 2 (Tamamı) olmalı.', 'VALIDATION', 422);
        }
        $method = (int) ($body['odeme'] ?? 0);
        if ($method !== 0 && !in_array($method, self::PAYMENT_METHODS, true)) {
            throw new HttpException('Geçersiz ödeme şekli.', 'VALIDATION', 422);
        }

        $this->assertNoOverlap($pdo, $homeId, $in, $out);

        // ------------------------------------------------------------ kayitlar satırı (sadece sitede olan kolonlar)
        $now = date('Y-m-d H:i:s');
        $suffix = $site['dbtable'];
        $values = [];   // kolon => değer
        $changes = [];  // log alanları

        $put = function (string $column, $value, string $label = '') use (&$values, &$changes, $kayitlar): void {
            $key = strtolower($column);
            if (!isset($kayitlar[$key])) {
                return;
            }
            $values[$kayitlar[$key]['name']] = $value;
            if ($label !== '') {
                $changes[] = ['alan' => $label, 'eski' => '', 'yeni' => $value === null ? '' : (string) $value];
            }
        };

        $put('islem_tarihi', $now);
        $put('saat', $now);
        $put('siparis_kodu', (string) time());
        $put('manuel', 1);
        $put('aktif', 0);
        $put('site', (int) $site['id'], 'Site');
        $put('evid', $homeId, 'Villa');
        $homeTitle = $this->text($home['baslik' . $suffix] ?? '');
        if ($homeTitle === '') {
            $homeTitle = $this->text($home['baslik'] ?? '');
        }
        $put('adi', $this->fit($kayitlar, 'adi', $homeTitle, 'Villa adı'));
        $put('resim', $this->coverImage($home, $suffix));
        $put('rez_tarihi', $in, 'Giriş tarihi');
        $put('gelecek_tarih', $out, 'Çıkış tarihi');
        $put('toplam_gece', $nights);
        $put('yetiskin', array_key_exists('yetiskin', $body) ? $this->nonNegativeInt($body['yetiskin'], 'Yetişkin') : 1, 'Yetişkin');
        $put('cocuk', array_key_exists('cocuk', $body) ? $this->nonNegativeInt($body['cocuk'], 'Çocuk') : 0, 'Çocuk');
        $put('bebek', array_key_exists('bebek', $body) ? $this->nonNegativeInt($body['bebek'], 'Bebek') : 0, 'Bebek');

        foreach (self::CUSTOMER_FIELDS as $field => $definition) {
            $value = $this->text($body[$field] ?? '');
            if ($value === '') {
                continue;
            }
            foreach ($definition[0] as $candidate) {
                if (isset($kayitlar[strtolower($candidate)])) {
                    $put($candidate, $this->fit($kayitlar, $candidate, $value, $definition[1]), $definition[1]);
                    break;
                }
            }
        }

        $channel = (int) ($body['satisKanaliId'] ?? 0);
        $put('satis_kanallari_id', $channel > 0 ? $channel : null, $channel > 0 ? 'Satış kanalı' : '');
        $put('doviz', $currency, 'Para birimi');
        if ($currency === 'tl') {
            $put('kur', '1');
        }
        $put('tur', $type, 'Ödeme türü');
        if ($method > 0) {
            $put('odeme', $method, 'Ödeme şekli');
        }
        $defaultPos = $this->defaultVirtualPos($pdo, $suffix);
        if ($defaultPos !== null) {
            $put('VirtualPosId', $defaultPos);
        }

        // Tutarlar: panel biçiminde sıfır; fiyat hemen ardından reservation-update ile yazılır.
        $zero = $this->formatMoney(0, $currency);
        $included = (int) $site['id'] === 2 ? 'Included Price' : 'Fiyata Dahil';
        $put('toplam_tutar', $zero);
        $put('on_odeme', $zero);
        $put('kalan', $zero);
        $put('tutar', 0);
        $put('hasar_dep', $included);
        $put('temizlik', $included);

        // ------------------------------------------------------------ yaz
        $id = 0;
        $this->transaction($pdo, function () use ($pdo, $values, $doluColumns, $homeId, $in, $out, $customer, $changes, &$id) {
            $columns = array_keys($values);
            $params = [];
            $placeholders = [];
            foreach ($columns as $i => $column) {
                $placeholders[] = ':v' . $i;
                $params[':v' . $i] = $values[$column];
            }
            $stmt = $pdo->prepare(
                'INSERT INTO kayitlar ([' . implode('], [', $columns) . ']) VALUES (' . implode(', ', $placeholders) . ')'
            );
            $this->execute($stmt, $params);
            $id = (int) $pdo->lastInsertId();
            if ($id <= 0) {
                throw new HttpException('Rezervasyon numarası alınamadı.', 'CREATE_FAILED', 500);
            }

            // Takvim satırı (panel: INSERT INTO dolu (emlak, tarih, tarih2, musteri, Durum, Kayitid)).
            $dolu = [
                'emlak' => $homeId, 'tarih' => $in, 'tarih2' => $out, 'musteri' => $customer,
                'durum' => 0, 'kayitid' => $id, 'onay' => 0, 'wait' => 0,
            ];
            $names = [];
            $doluParams = [];
            foreach ($dolu as $column => $value) {
                if (!isset($doluColumns[$column])) {
                    if (in_array($column, ['emlak', 'tarih', 'tarih2', 'durum', 'kayitid'], true)) {
                        throw new HttpException('dolu tablosunda ' . $column . ' kolonu yok.', 'CREATE_FAILED', 500);
                    }
                    continue;
                }
                $names[] = '[' . $doluColumns[$column]['name'] . ']';
                $doluParams[':' . $column] = $value;
            }
            $stmt = $pdo->prepare(
                'INSERT INTO dolu (' . implode(', ', $names) . ') VALUES (' . implode(', ', array_keys($doluParams)) . ')'
            );
            $this->execute($stmt, $doluParams);

            $this->log($pdo, $id, $id . ' numaralı yeni rezervasyon oluşturdu.', 'rezervasyon-olustur', $changes);
        });

        $this->response->success(['id' => $id, 'durum' => 0]);
    }

    // ---------------------------------------------------------------- kurallar

    /**
     * Aynı villada aktif rezervasyon (dolu.durum 1 opsiyon / 3 onaylı) ya da kapatılmış tarih (dolu_fake.durum 3)
     * ile çakışma varsa 409 (reservation-update ile aynı).
     */
    private function assertNoOverlap(PDO $pdo, int $homeId, string $in, string $out): void
    {
        $conflict = $this->fetchOne(
            $pdo,
            'SELECT TOP 1 kayitid, durum, CONVERT(varchar(10), tarih, 104) AS t1, CONVERT(varchar(10), tarih2, 104) AS t2
             FROM dolu
             WHERE emlak = :home AND durum IN (1, 3)
               AND CONVERT(date, tarih) < :out AND CONVERT(date, tarih2) > :in
             ORDER BY tarih',
            [':home' => $homeId, ':in' => $in, ':out' => $out]
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

    /**
     * Rezervasyonun kapak resmi: homes.resim{dbtable} (yoksa resim); virgüllü listede ilki (panel küçük resim adresi).
     *
     * @param array<string,mixed> $home
     */
    private function coverImage(array $home, string $suffix): string
    {
        $raw = trim((string) ($home['resim' . $suffix] ?? ''));
        if ($raw === '') {
            $raw = trim((string) ($home['resim'] ?? ''));
        }
        $first = trim((string) explode(',', $raw)[0]);

        return mb_substr($first, 0, 250);
    }

    /** Panelin varsayılan sanal POS'u (genel{dbtable}.DefaultVirtualPosId, yoksa genel); bulunamazsa null. */
    private function defaultVirtualPos(PDO $pdo, string $suffix): ?int
    {
        foreach (array_unique([$suffix, '']) as $tableSuffix) {
            if (!isset($this->columns($pdo, 'dbo.genel' . $tableSuffix)['defaultvirtualposid'])) {
                continue;
            }
            try {
                $row = $pdo->query('SELECT TOP 1 DefaultVirtualPosId AS pos FROM genel' . $tableSuffix)->fetch(PDO::FETCH_ASSOC);
            } catch (\PDOException $e) {
                continue;
            }
            $pos = (int) ($row['pos'] ?? 0);

            return $pos > 0 ? $pos : null;
        }

        return null;
    }

    /**
     * Siteler (id, ad, kısa ad, tablo soneki). sites tablosu yoksa tek site.
     *
     * @return array<int,array{id:int,site:string,kisa:string,dbtable:string}>
     */
    private function sites(PDO $pdo): array
    {
        try {
            $rows = $pdo->query('SELECT id, site, kisa, dbtable FROM sites ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $rows = [];
        }
        if ($rows === []) {
            $rows = [['id' => 1, 'site' => 'Site', 'kisa' => '', 'dbtable' => '']];
        }

        $sites = [];
        foreach ($rows as $row) {
            $suffix = trim((string) ($row['dbtable'] ?? ''));
            if ($suffix !== '' && preg_match('/^_[A-Za-z0-9]+$/', $suffix) !== 1) {
                continue;
            }
            $sites[] = [
                'id' => (int) $row['id'],
                'site' => trim((string) ($row['site'] ?? '')),
                'kisa' => trim((string) ($row['kisa'] ?? '')),
                'dbtable' => $suffix,
            ];
        }

        return $sites;
    }

    // ---------------------------------------------------------------- biçim

    private function formatMoney(int $amount, string $currency): string
    {
        $format = self::CURRENCY_FORMAT[$currency] ?? self::CURRENCY_FORMAT['tl'];

        return $format[0] . $amount . $format[1];
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
        $text = is_string($value) ? trim($value) : '';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new HttpException($label . ' geçersiz (yyyy-MM-dd bekleniyor).', 'VALIDATION', 422);
        }

        return $text;
    }

    /** @param mixed $value */
    private function text($value): string
    {
        if ($value === null || is_array($value)) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    // ---------------------------------------------------------------- DB yardımcıları (reservation-update ile aynı)

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

    // ---------------------------------------------------------------- log (reservation-update ile aynı biçim)

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
}
