<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Rezervasyonun BTRANS (GİB konaklama veri transferi) ödeme verileri.
 *
 *   GET  /backend-api/reservation-btrans?id=     -> kayıtlı değerler (yoksa panel varsayılanları) + hesap listeleri
 *   POST /backend-api/reservation-btrans/save    -> değerleri kaydeder (sadece değişen kolonlar yazılır)
 *
 * Panel karşılığı: siparis_detaylari.asp "Btrans Verileri" sekmesi (portlet_tab5, btrans_ajax=ibansec) ve
 * asp_scripts/scripts.asp islem=btrans_kayit_duzenle. Tüm alanlar kayitlar.btrans_* kolonlarındadır
 * (kurulum: POST /btrans/setup). BtransController::buildQuerySql XML üretirken bu kolonları okur.
 *
 * Panelden farkı: farklı alıcı alanları (ad, soyad, unvan, IBAN) boş gönderilirse temizlenir (panel boşu yazmıyordu).
 * Her kayıt kullanici_log_kaydi'na (islm = rezervasyon) değişen alanlarla birlikte yazılır.
 *
 * Ortak gövde alanları: id (kayitlar.id), kullanici_id, kullanici_adi, natsisa_kullanici_id, islem_kodu.
 */
final class ReservationBtransController extends Controller
{
    /** Gövde alanı => [kayitlar kolonu, log etiketi]. */
    private const FIELDS = [
        'odemeBilgisi' => ['btrans_odeme_bilgisi', 'Yapılan Ödeme Bilgisi'],
        'odemeYapanIbanId' => ['btrans_odeme_yapan_iban_id', 'Ödemenin Yapıldığı Banka'],
        'odemeYapanIbanNo' => ['btrans_odeme_yapan_iban_no', 'Ödemenin Yapıldığı Hesap'],
        'odemeYapanIbanType' => ['btrans_odeme_yapan_iban_type', 'Ödemenin Yapıldığı Hesap Para Birimi'],
        'baslangicTarihi' => ['btrans_baslangic_tarihi', 'Kiralama Başlangıç Tarihi'],
        'bitisTarihi' => ['btrans_bitis_tarihi', 'Kiralama Bitiş Tarihi'],
        'komisyonOrani' => ['btrans_komisyon_orani', 'Komisyon Oranı'],
        'odemeYontemi' => ['btrans_odeme_yontemi', 'Ödeme Yöntemi'],
        'iban' => ['btrans_iban', 'Alıcı IBAN Bilgisi'],
        'farkliAliciAdi' => ['btrans_farkli_alici_adi', 'Farklı Alıcı Adı'],
        'farkliAliciSoyadi' => ['btrans_farkli_alici_soyadi', 'Farklı Alıcı Soyadı'],
        'farkliAliciUnvan' => ['btrans_farkli_alici_unvan', 'Farklı Alıcı Unvan'],
        'farkliAliciIban' => ['btrans_farkli_alici_iban', 'Farklı Alıcı IBAN'],
        'odemeTarihi' => ['btrans_odeme_tarihi', 'Ödeme Tarihi'],
        'odemeTutariKdvDahil' => ['btrans_odeme_tutari_kdv_dahil', 'Toplam Ödeme Tutarı (KDV Dahil)'],
        'paraBirimi' => ['btrans_para_birimi', 'Para Birimi'],
    ];

    private const DATE_FIELDS = ['baslangicTarihi', 'bitisTarihi', 'odemeTarihi'];

    private const PAYMENT_INFO = ['1' => 'Peşin', '2' => 'Komisyon'];

    private const PAYMENT_METHODS = ['1' => 'Kredi Kartı', '2' => 'Nakit', '3' => 'Havale', '4' => 'EFT', '5' => 'Diğer'];

    private const CURRENCIES = ['TRY', 'USD', 'EUR', 'GBP'];

    /** btrans_iban özel değerleri (panel): 00 ev sahibi hesabı yok, 01/02 farklı alıcı. */
    private const SPECIAL_IBANS = [
        '00' => 'Kişi Bilgileri Girilmemiş',
        '01' => 'Farklı alıcı bilgileri [Gerçek Kişi]',
        '02' => 'Farklı alıcı bilgileri [Tüzel Kişi]',
    ];

    /** Site hesabının IBAN kolonları => para birimi (panel btrans_ajax=ibansec sırası). */
    private const SITE_IBAN_COLUMNS = ['iban_tl' => 'TRY', 'iban_euro' => 'EUR', 'iban_pound' => 'GBP'];

    /** @var bool|null */
    private $hasLogDetailsColumn = null;

    /** @var array<string,array<string,array{name:string,max:int|null}>> */
    private $columnCache = [];

    /**
     * Rezervasyonun BTRANS verileri. Hiç kaydedilmemiş alanlar panelin varsayılanlarıyla dolu gelir
     * (tarihler rezervasyondan, komisyon oranı ve tutar evsahibi_odenecek'ten, ödeme tarihi giriş + 1 gün).
     *
     * @Get
     * @query id int required Rezervasyon kimliği (kayitlar.id)
     */
    public function detail(): void
    {
        $pdo = $this->db->pdo();
        $reservationId = $this->reservationId($pdo);
        $this->requireBtransColumns($pdo);
        $row = $this->reservationRow($pdo, $reservationId);
        $ownerId = (int) ($row['evsahibi'] ?? 0);

        $siteAccounts = $this->siteAccounts($pdo);
        $ownerAccounts = $this->ownerAccounts($pdo, $ownerId);
        $stored = $this->storedValues($row);

        $total = $this->money($row['toplam_tutar'] ?? null);
        $ownerPay = $this->money($row['evsahibi_odenecek'] ?? null);
        $commission = $total !== null && $total > 0 && $ownerPay !== null ? (string) round($ownerPay * 100 / $total) : '';

        $values = $stored;
        $fill = static function (string $key, string $default) use (&$values): void {
            if (($values[$key] ?? '') === '') {
                $values[$key] = $default;
            }
        };
        // Panel: kayıtlı değer "1" değilse Komisyon seçili gelir.
        $values['odemeBilgisi'] = $stored['odemeBilgisi'] === '1' ? '1' : '2';
        $fill('baslangicTarihi', (string) ($row['giris'] ?? ''));
        $fill('bitisTarihi', (string) ($row['cikis'] ?? ''));
        $fill('komisyonOrani', $commission);
        $fill('odemeYontemi', '4');
        $fill('odemeTarihi', $this->addDay((string) ($row['giris'] ?? '')));
        $fill('odemeTutariKdvDahil', $ownerPay === null ? '' : $this->numberText($ownerPay));
        $fill('paraBirimi', $this->currencyOf((string) ($row['doviz'] ?? '')));

        // Alıcı IBAN: kayıtlı değer geçerliyse o, değilse ev sahibinin ilk hesabı, hesap yoksa 00.
        $ownerIds = array_map(static function (array $a): string {
            return (string) $a['id'];
        }, $ownerAccounts);
        if (!isset(self::SPECIAL_IBANS[$values['iban']]) && !in_array($values['iban'], $ownerIds, true)) {
            $values['iban'] = $ownerIds[0] ?? '00';
        }
        if ($values['iban'] === '00' && $ownerIds !== []) {
            $values['iban'] = $ownerIds[0];
        }

        // Ödemenin yapıldığı site hesabı: kayıtlı yoksa ilk hesap, TRY IBAN'ı.
        $siteIds = array_map(static function (array $a): string {
            return (string) $a['id'];
        }, $siteAccounts);
        if (!in_array($values['odemeYapanIbanId'], $siteIds, true)) {
            $values['odemeYapanIbanId'] = $siteIds[0] ?? '';
        }
        if (!in_array($values['odemeYapanIbanType'], array_values(self::SITE_IBAN_COLUMNS), true)) {
            $values['odemeYapanIbanType'] = 'TRY';
        }
        $values['odemeYapanIbanNo'] = $this->siteIban($siteAccounts, $values['odemeYapanIbanId'], $values['odemeYapanIbanType']);

        $this->response->success([
            'id' => $reservationId,
            'values' => $values,
            'guncellemeTarihi' => $row['guncelleme'] ?? null,
            'kayitli' => ($row['guncelleme'] ?? null) !== null,
            'siteHesaplari' => $siteAccounts,
            'evSahibiHesaplari' => $ownerAccounts,
            'secenekler' => [
                'odemeBilgisi' => $this->options(self::PAYMENT_INFO),
                'odemeYontemi' => $this->options(self::PAYMENT_METHODS),
                'paraBirimi' => self::CURRENCIES,
                'ozelIbanlar' => $this->options(self::SPECIAL_IBANS),
            ],
        ]);
    }

    /**
     * BTRANS verilerini kaydeder. Gönderilmeyen alan eski değerini korur; değişen kolonlar yazılır,
     * btrans_guncelleme_tarihi bugüne çekilir (panel gibi). Ödemenin yapıldığı hesabın IBAN'ı seçilen site hesabı ve
     * para biriminden sunucuda bulunur.
     *
     * @Post("save")
     * @body id int required Rezervasyon kimliği (kayitlar.id)
     * @body odemeBilgisi string 1 Peşin, 2 Komisyon
     * @body odemeYapanIbanId int Site hesabı (hesaplar.id, kullanici = 0)
     * @body odemeYapanIbanType string TRY | EUR | GBP
     * @body baslangicTarihi string yyyy-MM-dd (peşin)
     * @body bitisTarihi string yyyy-MM-dd (peşin)
     * @body komisyonOrani number 0-100 (komisyon)
     * @body odemeYontemi string 1 Kredi Kartı, 2 Nakit, 3 Havale, 4 EFT, 5 Diğer
     * @body iban string Ev sahibi hesabı (hesaplar.id) | 00 | 01 gerçek kişi | 02 tüzel kişi
     * @body farkliAliciAdi string
     * @body farkliAliciSoyadi string
     * @body farkliAliciUnvan string
     * @body farkliAliciIban string
     * @body odemeTarihi string yyyy-MM-dd
     * @body odemeTutariKdvDahil number
     * @body paraBirimi string TRY | USD | EUR | GBP
     */
    public function save(): void
    {
        $pdo = $this->db->pdo();
        $reservationId = $this->reservationId($pdo);
        $columns = $this->requireBtransColumns($pdo);
        $row = $this->reservationRow($pdo, $reservationId);
        $body = $this->request->json();
        $old = $this->storedValues($row);

        $new = $old;
        foreach (array_keys(self::FIELDS) as $field) {
            if ($field === 'odemeYapanIbanNo' || !array_key_exists($field, $body)) {
                continue;
            }
            $new[$field] = $this->text($field);
        }

        $this->validate($pdo, $new, (int) ($row['evsahibi'] ?? 0));
        // Aynı sayının farklı yazımı (eski panelden "1.250,00") değişiklik sayılmaz.
        foreach (['komisyonOrani', 'odemeTutariKdvDahil'] as $field) {
            if ($old[$field] !== '' && $new[$field] !== '' && $this->money($old[$field]) === $this->money($new[$field])) {
                $new[$field] = $old[$field];
            }
        }

        if (array_key_exists('odemeYapanIbanId', $body) || array_key_exists('odemeYapanIbanType', $body)) {
            $new['odemeYapanIbanNo'] = $new['odemeYapanIbanId'] === ''
                ? ''
                : $this->siteIban($this->siteAccounts($pdo), $new['odemeYapanIbanId'], $new['odemeYapanIbanType']);
        }

        $labels = [];
        foreach (self::FIELDS as $field => $definition) {
            $labels[$field] = $definition[1];
            $new[$field] = $this->fit($columns, $definition[0], $new[$field], $definition[1]);
        }

        $changes = $this->diff($labels, $this->view($pdo, $old), $this->view($pdo, $new));
        $changed = [];
        foreach (array_keys(self::FIELDS) as $field) {
            if ($old[$field] !== $new[$field]) {
                $changed[] = $field;
            }
        }
        if ($changed === []) {
            $this->response->success(['id' => $reservationId, 'degisen' => 0, 'guncellemeTarihi' => $row['guncelleme'] ?? null]);
            return;
        }

        $today = date('Y-m-d');
        $this->transaction($pdo, function () use ($pdo, $changed, $new, $reservationId, $changes) {
            $assignments = ['[btrans_guncelleme_tarihi] = CONVERT(date, GETDATE())'];
            $params = [':rid' => $reservationId];
            foreach ($changed as $field) {
                $column = self::FIELDS[$field][0];
                $assignments[] = '[' . $column . '] = :' . $field;
                $value = $new[$field];
                if ($value === '') {
                    $params[':' . $field] = null;
                } elseif ($field === 'odemeYapanIbanId') {
                    $params[':' . $field] = (int) $value;
                } else {
                    $params[':' . $field] = $value;
                }
            }
            $stmt = $pdo->prepare('UPDATE kayitlar SET ' . implode(', ', $assignments) . ' WHERE id = :rid');
            $this->execute($stmt, $params);

            $this->log(
                $pdo,
                $reservationId,
                $reservationId . ' numaralı rezervasyonun btrans verilerini güncelledi.',
                'btrans-duzenle',
                $changes
            );
        });

        $this->response->success(['id' => $reservationId, 'degisen' => count($changed), 'guncellemeTarihi' => $today]);
    }

    // ---------------------------------------------------------------- okuma

    /** @return array<string,mixed> */
    private function reservationRow(PDO $pdo, int $reservationId): array
    {
        $kayitlar = $this->columns($pdo, 'dbo.kayitlar');
        $select = [
            'k.id',
            'h.evsahibi',
            $this->dateSql('k.rez_tarihi') . ' AS giris',
            $this->dateSql('k.gelecek_tarih') . ' AS cikis',
            'k.doviz',
            'k.toplam_tutar',
            isset($kayitlar['evsahibi_odenecek']) ? 'k.evsahibi_odenecek' : 'NULL AS evsahibi_odenecek',
            'CONVERT(varchar(10), k.btrans_guncelleme_tarihi, 23) AS guncelleme',
        ];
        foreach (self::FIELDS as $field => $definition) {
            $column = $definition[0];
            $select[] = in_array($field, self::DATE_FIELDS, true)
                ? $this->dateSql('k.[' . $column . ']') . ' AS [' . $field . ']'
                : 'k.[' . $column . '] AS [' . $field . ']';
        }
        $stmt = $pdo->prepare(
            'SELECT ' . implode(', ', $select) . ' FROM kayitlar k LEFT JOIN homes h ON h.id = k.evid WHERE k.id = :id'
        );
        $this->execute($stmt, [':id' => $reservationId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * kayitlar.btrans_* değerleri, boş/NULL => ''.
     *
     * @param array<string,mixed> $row
     * @return array<string,string>
     */
    private function storedValues(array $row): array
    {
        $values = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $value = $row[$field] ?? null;
            $values[$field] = $value === null ? '' : trim((string) $value);
        }

        return $values;
    }

    /**
     * Sitenin hesapları (hesaplar.kullanici = 0): banka ve para birimlerine göre IBAN'lar.
     *
     * @return array<int,array{id:int,banka:string,ibanlar:array<int,array{iban:string,tip:string}>}>
     */
    private function siteAccounts(PDO $pdo): array
    {
        $columns = $this->columns($pdo, 'dbo.hesaplar');
        if ($columns === []) {
            return [];
        }
        $ibanColumns = array_filter(array_keys(self::SITE_IBAN_COLUMNS), static function (string $c) use ($columns): bool {
            return isset($columns[$c]);
        });
        $select = ['id', isset($columns['banka']) ? 'banka' : "'' AS banka"];
        foreach ($ibanColumns as $column) {
            $select[] = $column;
        }
        $order = isset($columns['siralama']) ? 'siralama ASC, id ASC' : 'id ASC';
        $rows = $pdo->query('SELECT ' . implode(', ', $select) . ' FROM hesaplar WHERE kullanici = 0 ORDER BY ' . $order);

        $accounts = [];
        foreach ($rows ? $rows->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $ibans = [];
            foreach ($ibanColumns as $column) {
                $ibans[] = [
                    'iban' => str_replace(' ', '', trim((string) ($row[$column] ?? ''))),
                    'tip' => self::SITE_IBAN_COLUMNS[$column],
                ];
            }
            $accounts[] = ['id' => (int) $row['id'], 'banka' => trim((string) $row['banka']), 'ibanlar' => $ibans];
        }

        return $accounts;
    }

    /**
     * Ev sahibinin hesapları (hesaplar.kullanici = homes.evsahibi).
     *
     * @return array<int,array{id:int,banka:string,hesapAdi:string,iban:string}>
     */
    private function ownerAccounts(PDO $pdo, int $ownerId): array
    {
        $columns = $this->columns($pdo, 'dbo.hesaplar');
        if ($ownerId <= 0 || $columns === []) {
            return [];
        }
        $select = ['id'];
        foreach (['banka', 'hesap_adi', 'iban'] as $column) {
            $select[] = isset($columns[$column]) ? $column : "'' AS " . $column;
        }
        $order = isset($columns['siralama']) ? 'siralama ASC, id ASC' : 'id ASC';
        $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM hesaplar WHERE kullanici = :owner ORDER BY ' . $order);
        $this->execute($stmt, [':owner' => $ownerId]);

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'banka' => trim((string) $row['banka']),
                'hesapAdi' => trim((string) $row['hesap_adi']),
                'iban' => trim((string) $row['iban']),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<int,array{id:int,banka:string,ibanlar:array<int,array{iban:string,tip:string}>}> $accounts */
    private function siteIban(array $accounts, string $accountId, string $type): string
    {
        foreach ($accounts as $account) {
            if ((string) $account['id'] !== $accountId) {
                continue;
            }
            foreach ($account['ibanlar'] as $iban) {
                if ($iban['tip'] === $type) {
                    return $iban['iban'];
                }
            }
        }

        return '';
    }

    // ---------------------------------------------------------------- doğrulama

    /** @param array<string,string> $values */
    private function validate(PDO $pdo, array &$values, int $ownerId): void
    {
        if (!isset(self::PAYMENT_INFO[$values['odemeBilgisi']]) && $values['odemeBilgisi'] !== '') {
            throw new HttpException('Ödeme bilgisi 1 (Peşin) ya da 2 (Komisyon) olmalı.', 'VALIDATION', 422);
        }
        if (!isset(self::PAYMENT_METHODS[$values['odemeYontemi']]) && $values['odemeYontemi'] !== '') {
            throw new HttpException('Ödeme yöntemi geçersiz.', 'VALIDATION', 422);
        }
        $values['paraBirimi'] = strtoupper($values['paraBirimi']);
        if ($values['paraBirimi'] !== '' && !in_array($values['paraBirimi'], self::CURRENCIES, true)) {
            throw new HttpException('Para birimi TRY, USD, EUR ya da GBP olmalı.', 'VALIDATION', 422);
        }
        $values['odemeYapanIbanType'] = strtoupper($values['odemeYapanIbanType']);
        if ($values['odemeYapanIbanType'] !== ''
            && !in_array($values['odemeYapanIbanType'], array_values(self::SITE_IBAN_COLUMNS), true)) {
            throw new HttpException('Ödemenin yapıldığı hesabın para birimi TRY, EUR ya da GBP olmalı.', 'VALIDATION', 422);
        }

        foreach (self::DATE_FIELDS as $field) {
            $value = $values[$field];
            if ($value !== '' && (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1
                    || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]))) {
                throw new HttpException(self::FIELDS[$field][1] . ' geçersiz (yyyy-MM-dd bekleniyor).', 'VALIDATION', 422);
            }
        }

        if ($values['komisyonOrani'] !== '') {
            $rate = $this->money($values['komisyonOrani']);
            if ($rate === null || $rate < 0 || $rate > 100) {
                throw new HttpException('Komisyon oranı 0 ile 100 arasında olmalı.', 'VALIDATION', 422);
            }
            $values['komisyonOrani'] = $this->numberText($rate);
        }
        if ($values['odemeTutariKdvDahil'] !== '') {
            $amount = $this->money($values['odemeTutariKdvDahil']);
            if ($amount === null || $amount < 0) {
                throw new HttpException('Toplam ödeme tutarı geçersiz.', 'VALIDATION', 422);
            }
            // BtransController XML'de ',' => '.' çeviriyor; nokta ondalıkla saklanır.
            $values['odemeTutariKdvDahil'] = $this->numberText($amount);
        }

        if ($values['odemeYapanIbanId'] !== '') {
            $ids = array_map(static function (array $a): string {
                return (string) $a['id'];
            }, $this->siteAccounts($pdo));
            if (!in_array($values['odemeYapanIbanId'], $ids, true)) {
                throw new HttpException('Ödemenin yapıldığı banka sitenin hesaplarında bulunamadı.', 'VALIDATION', 422);
            }
        }

        if ($values['iban'] !== '' && !isset(self::SPECIAL_IBANS[$values['iban']])) {
            $ids = array_map(static function (array $a): string {
                return (string) $a['id'];
            }, $this->ownerAccounts($pdo, $ownerId));
            if (!in_array($values['iban'], $ids, true)) {
                throw new HttpException('Seçilen alıcı IBAN ev sahibinin hesaplarında bulunamadı.', 'VALIDATION', 422);
            }
        }

        $values['farkliAliciIban'] = strtoupper(str_replace(' ', '', $values['farkliAliciIban']));
    }

    /**
     * Log için okunur değerler.
     *
     * @param array<string,string> $values
     * @return array<string,string>
     */
    private function view(PDO $pdo, array $values): array
    {
        $view = $values;
        $view['odemeBilgisi'] = self::PAYMENT_INFO[$values['odemeBilgisi']] ?? $values['odemeBilgisi'];
        $view['odemeYontemi'] = self::PAYMENT_METHODS[$values['odemeYontemi']] ?? $values['odemeYontemi'];
        if (isset(self::SPECIAL_IBANS[$values['iban']])) {
            $view['iban'] = self::SPECIAL_IBANS[$values['iban']];
        }
        if ($values['odemeYapanIbanId'] !== '') {
            foreach ($this->siteAccounts($pdo) as $account) {
                if ((string) $account['id'] === $values['odemeYapanIbanId']) {
                    $view['odemeYapanIbanId'] = $account['banka'];
                }
            }
        }
        foreach (self::DATE_FIELDS as $field) {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $values[$field], $m) === 1) {
                $view[$field] = $m[3] . '.' . $m[2] . '.' . $m[1];
            }
        }

        return $view;
    }

    // ---------------------------------------------------------------- yardımcılar

    private function reservationId(PDO $pdo): int
    {
        $id = (int) $this->request->query('id', $this->request->input('id', 0));
        if ($id <= 0) {
            throw new HttpException('Lütfen geçerli bir rezervasyon ID gönderin.', 'VALIDATION', 422);
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM kayitlar WHERE id = :id');
        $this->execute($stmt, [':id' => $id]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new HttpException('Belirtilen ID ile rezervasyon bulunamadı.', 'NOT_FOUND', 404);
        }

        return $id;
    }

    /** @return array<string,array{name:string,max:int|null}> */
    private function requireBtransColumns(PDO $pdo): array
    {
        $columns = $this->columns($pdo, 'dbo.kayitlar');
        $missing = [];
        foreach (array_merge(array_column(self::FIELDS, 0), ['btrans_guncelleme_tarihi']) as $column) {
            if (!isset($columns[$column])) {
                $missing[] = $column;
            }
        }
        if ($missing !== []) {
            throw new HttpException(
                'BTRANS kolonları bu sitede eksik (' . implode(', ', $missing) . '). Önce POST /btrans/setup çalıştırılmalı.',
                'SETUP_REQUIRED',
                409
            );
        }

        return $columns;
    }

    /** Metin/tarih kolonunu yyyy-MM-dd'ye çevirir (BtransController ile aynı biçim sırası). */
    private function dateSql(string $column): string
    {
        return "CONVERT(varchar(10), CASE
                WHEN TRY_CONVERT(date, {$column}, 120) IS NOT NULL THEN TRY_CONVERT(date, {$column}, 120)
                WHEN TRY_CONVERT(date, {$column}, 104) IS NOT NULL THEN TRY_CONVERT(date, {$column}, 104)
                WHEN TRY_CONVERT(date, {$column}, 103) IS NOT NULL THEN TRY_CONVERT(date, {$column}, 103)
                ELSE TRY_CONVERT(date, {$column})
            END, 23)";
    }

    private function addDay(string $date): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return '';
        }
        $value = \DateTime::createFromFormat('!Y-m-d', $date);

        return $value ? $value->modify('+1 day')->format('Y-m-d') : '';
    }

    private function currencyOf(string $doviz): string
    {
        $map = ['euro' => 'EUR', 'dolar' => 'USD', 'pound' => 'GBP'];

        return $map[strtolower(trim($doviz))] ?? 'TRY';
    }

    /**
     * Tutar metnini sayıya çevirir ("56.000", "56000 TL", "1.250,50", "1250.5"). Sayı yoksa null.
     *
     * @param mixed $value
     */
    private function money($value): ?float
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '' || preg_match('/\d/', $text) !== 1) {
            return null;
        }
        $number = (string) preg_replace('/[^\d,.-]/', '', $text);
        if (strpos($number, ',') !== false) {
            $number = str_replace(',', '.', str_replace('.', '', $number));
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $number) === 1) {
            $number = str_replace('.', '', $number);
        }

        return is_numeric($number) ? round((float) $number, 2) : null;
    }

    private function numberText(float $value): string
    {
        $text = number_format($value, 2, '.', '');

        return rtrim(rtrim($text, '0'), '.');
    }

    /**
     * @param array<string,string> $map
     * @return array<int,array{value:string,label:string}>
     */
    private function options(array $map): array
    {
        $options = [];
        foreach ($map as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => $label];
        }

        return $options;
    }

    /**
     * @param array<string,string> $labels
     * @param array<string,mixed> $old
     * @param array<string,mixed> $new
     * @return array<int,array{alan:string,eski:string,yeni:string}>
     */
    private function diff(array $labels, array $old, array $new): array
    {
        $changes = [];
        foreach ($labels as $key => $label) {
            $before = trim((string) ($old[$key] ?? ''));
            $after = trim((string) ($new[$key] ?? ''));
            if ($before !== $after) {
                $changes[] = ['alan' => $label, 'eski' => $before, 'yeni' => $after];
            }
        }

        return $changes;
    }

    /** @return array<string,array{name:string,max:int|null}> */
    private function columns(PDO $pdo, string $table): array
    {
        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }
        $parts = explode('.', $table, 2);
        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table'
        );
        $this->execute($stmt, [':schema' => $parts[0], ':table' => $parts[1]]);

        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $max = $row['CHARACTER_MAXIMUM_LENGTH'];
            $columns[strtolower((string) $row['COLUMN_NAME'])] = [
                'name' => (string) $row['COLUMN_NAME'],
                'max' => $max === null || (int) $max < 0 ? null : (int) $max,
            ];
        }
        $this->columnCache[$table] = $columns;

        return $columns;
    }

    /**
     * Değer kolonun karakter sınırını aşıyorsa kesmek yerine hata döner (veri kaybı olmasın).
     *
     * @param array<string,array{name:string,max:int|null}> $columns
     */
    private function fit(array $columns, string $column, string $value, string $label): string
    {
        $max = $columns[strtolower($column)]['max'] ?? null;
        if ($max !== null && mb_strlen($value) > $max) {
            throw new HttpException($label . ' en fazla ' . $max . ' karakter olabilir.', 'VALIDATION', 422);
        }

        return $value;
    }

    /** Gövdeden tek satırlık metin. */
    private function text(string $key): string
    {
        $value = $this->request->input($key, '');
        if ($value === null || is_array($value) || is_bool($value)) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    /**
     * kullanici_log_kaydi'na yazar (ReservationGuestsController ile aynı biçim).
     *
     * @param array<int,array{alan:string,eski:string,yeni:string}> $changes
     */
    private function log(PDO $pdo, int $reservationId, string $message, string $action, array $changes): void
    {
        $userName = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $this->request->input('kullanici_adi', ''))), 0, 100);
        $natsisaUserId = max(0, (int) $this->request->input('natsisa_kullanici_id', 0));
        if ($userName !== '') {
            $message .= ' (' . $userName . ')';
        }

        $params = [
            ':islmId' => (string) $reservationId,
            ':aciklama' => $message,
            ':kullaniciId' => (int) $this->request->input('kullanici_id', 0),
        ];

        $details = [
            'kaynak' => $natsisaUserId > 0 || $userName !== '' ? 'natsisa' : 'api',
            'kullaniciAdi' => $userName,
            'natsisaKullaniciId' => $natsisaUserId,
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

    private function hasLogDetailsColumn(PDO $pdo): bool
    {
        if ($this->hasLogDetailsColumn === null) {
            $stmt = $pdo->query("SELECT COL_LENGTH('dbo.kullanici_log_kaydi', 'degisiklikler') AS len");
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            $this->hasLogDetailsColumn = is_array($row) && $row['len'] !== null;
        }

        return $this->hasLogDetailsColumn;
    }

    /** @param array<string,mixed> $params */
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
}
