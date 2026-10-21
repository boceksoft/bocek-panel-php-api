<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Rezervasyon detay kaynaÄŸÄ±.
 * ASP siparis_detaylari.asp  ekranÄ±nÄ±n okuduÄŸu verileri JSON olarak dÃ¶ner.
 */
final class ReservationdetailController extends Controller
{
    /**
     * Rezervasyon detaylarÄ±nÄ± dÃ¶ner.
     *
     * @Get
     * @query id int required Rezervasyon kimliÄŸi
     */
    public function index(): void
    {
        if ($this->request->query('btrans_ajax') === 'ibansec') {
            $this->ibansec();
            return;
        }

        $id = (int) $this->request->query('id', 0);
        if ($id <= 0) {
            throw new HttpException('LÃ¼tfen geÃ§erli bir rezervasyon ID gÃ¶nderin.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();

        $reservation = $this->fetchReservation($pdo, $id);
        if (!$reservation) {
            throw new HttpException('Belirtilen ID ile rezervasyon bulunamadÄ±.', 'NOT_FOUND', 404);
        }

        $homeId = isset($reservation['evid']) ? (int) $reservation['evid'] : 0;
        $dolu = $this->fetchOne($pdo, 'SELECT * FROM dolu WHERE kayitid = :id', [':id' => $id]);
        $villa = $homeId > 0
            ? $this->fetchOne(
                $pdo,
                "SELECT *,
                        ISNULL(evsahibi, '0') AS evsahibi,
                        ISNULL((SELECT baslik FROM tip WHERE id = homes.emlak_tipi), '') AS emlak_tipi_baslik,
                        ISNULL((SELECT baslik FROM destinations WHERE id = homes.emlak_bolgesi), '') AS emlak_bolgesi_baslik
                 FROM homes
                 WHERE id = :id",
                [':id' => $homeId]
            )
            : null;

        $owner = null;
        if ($villa && isset($villa['evsahibi']) && (int) $villa['evsahibi'] > 0) {
            $owner = $this->fetchOne($pdo, 'SELECT * FROM kullanici WHERE id = :id', [':id' => (int) $villa['evsahibi']]);
        }

        $calendarResponse = $this->fetchOne(
            $pdo,
            'SELECT * FROM KiralamaTakvimi.Response WHERE kayitlarId = :id',
            [':id' => $id]
        );
        $calendarHome = $homeId > 0
            ? $this->fetchOne($pdo, 'SELECT * FROM KiralamaTakvimi.CalendarHomes WHERE homesId = :id', [':id' => $homeId])
            : null;

        $personInfoList = $this->fetchPersonInfoList($pdo, $id);
        $personInfo = $personInfoList !== [] ? $personInfoList[0] : false;
        $partialPayments = $this->fetchAll(
            $pdo,
            "SELECT *,
                    CASE
                        WHEN odendi = 1 THEN '<span class=''btn btn-xs btn-success''>Ödendi</span>'
                        ELSE '<span class=''btn btn-xs btn-danger''>Ödenmedi</span>'
                    END AS durum,
                    CONVERT(varchar, tarih, 104) AS tarih
             FROM parcaliOdeme
             WHERE kayitlarId = :id",
            [':id' => $id]
        );

        $documentLogs = $this->configBool('reservation_detail_use_islem_kaydi', true)
            ? $this->fetchDocumentLogs($pdo, $id)
            : [];
        $documentState = $this->fetchOne($pdo, 'SELECT * FROM belge_kayitlari WHERE musteriid = :id', [':id' => $id]);

        $latestLog = $this->fetchOne(
            $pdo,
            "SELECT TOP 1 *,
                    (SELECT TOP 1 ad + ' ' + soyad FROM kullanici WHERE kullanici.id = kullanici_log_kaydi.kullanici_id) AS u
             FROM kullanici_log_kaydi
             WHERE islm = 'rezervasyon' AND islm_id = :id
             ORDER BY id DESC",
            [':id' => $id]
        );

        $activityLogs = $this->fetchActivityLogs($pdo, $id);

        $nextReservation = $this->fetchOne(
            $pdo,
            'SELECT id, site FROM kayitlar WHERE AcikRezervasyonId = :id',
            [':id' => $id]
        );

        $accounts = [
            'site' => $this->fetchAll($pdo, 'SELECT * FROM hesaplar WHERE kullanici = 0 ORDER BY banka ASC'),
            'site_ordered' => $this->fetchAll($pdo, 'SELECT * FROM hesaplar WHERE kullanici = 0 ORDER BY siralama ASC'),
            'owner' => [],
        ];
        if ($villa && isset($villa['evsahibi'])) {
            $accounts['owner'] = $this->fetchAll(
                $pdo,
                'SELECT * FROM hesaplar WHERE kullanici = :ownerId ORDER BY siralama ASC',
                [':ownerId' => (int) $villa['evsahibi']]
            );
        }

        $blockInfo = [];
        if (!empty($reservation['ip'])) {
            $blockInfo = $this->fetchAll(
                $pdo,
                'SELECT DATEADD(MINUTE, [minute], modifiedDate) AS blockEndDate, *
                 FROM blockList
                 WHERE ip = :ip',
                [':ip' => (string) $reservation['ip']]
            );
        }

        $personCountInfo = $this->personCountInfo($reservation, $personInfo ?: []);
        $detailSections = $this->detailSections(
            $reservation,
            $dolu ?: [],
            $villa ?: [],
            $personCountInfo,
            $documentLogs,
            $documentState ?: [],
            $personInfoList
        );

        $this->response->success([
            'detay' => $detailSections,
            'rs' => $reservation,
            'dolutable' => $dolu,
            'villa' => $villa,
            'evsahibesi' => $owner,
            'KiralamaTakvimiResponse' => $calendarResponse,
            'CalendarHomes' => $calendarHome,
            'kisi_bilgileri' => $personInfo,
            'belge_kayitlari' => $documentState,
            'islem_kaydi' => $documentLogs,
            'logend' => $latestLog,
            // İşlem geçmişi: kullanici_log_kaydi (islm = rezervasyon), yeniden eskiye, en fazla 100 kayıt.
            'islemGecmisi' => $activityLogs,
            'sonrakiRzKontrol' => $nextReservation,
            'hesaplar_kullanici_0_banka' => $accounts['site'],
            'hesaplar_kullanici_0_siralama' => $accounts['site_ordered'],
            'hesaplar_evsahibi_siralama' => $accounts['owner'],
            'blockList' => $blockInfo,
            'parcaliOdeme' => $partialPayments,
            // Bildirim şablonları için ödeme linkleri (kart: parametreli, havale: parametresiz).
            'odemeLinkleri' => $this->paymentLinks($pdo, $reservation),
            // Müşteri e-posta şablonları için (Natsisa): sitenin sabitleri, sitenin dilinde villa bilgileri,
            // ödeme türü, panelin ürettiği ödeme satırları ve banka hesapları HTML'i (aktif_pasif.asp siparis_1).
            'mailBilgileri' => $this->mailInfo($pdo, $reservation),
        ]);
    }

    /**
     * Panel (aktif_pasif.asp siparis_1) ile aynı ödeme linkleri. Sayfa yolları sayfalar{dbtable}'dan okunur.
     *
     * @param array<string,mixed> $reservation
     * @return array<string,string>
     */
    private function paymentLinks(PDO $pdo, array $reservation): array
    {
        $links = ['kartIleOde' => '', 'kartIleOdeParametre' => '', 'havale' => ''];

        $id = (int) ($reservation['id'] ?? 0);
        if ($id <= 0) {
            return $links;
        }

        $siteId = (int) ($reservation['site'] ?? 1);
        $suffix = $this->siteTableSuffix($pdo, $siteId);
        $domain = $this->paymentDomain();
        if ($domain === '') {
            return $links;
        }

        $cardPath = $this->pagePath($pdo, $suffix, $this->pageIdSetting('reservation_payment_card_page_id', $siteId, $suffix));
        if ($cardPath !== '') {
            $email = (string) ($reservation['email'] ?? '');
            $params = '_=' . $this->idHash((string) $id) . '&authKey=' . $this->idHash($id . '_' . $email);
            $links['kartIleOde'] = $domain . '/' . $cardPath . '?' . $params;
            $links['kartIleOdeParametre'] = $params;
        }

        $transferPath = $this->pagePath($pdo, $suffix, $this->pageIdSetting('reservation_payment_transfer_page_id', $siteId, $suffix));
        if ($transferPath !== '') {
            $links['havale'] = $domain . '/' . $transferPath;
        }

        return $links;
    }

    /**
     * E-posta şablonu bilgileri. Panel: inc/Functions.asp mail_sablon_html + aktif_pasif.asp siparis_1.
     * Site sabitleri rezervasyonun sitesinin genel tablosundan (genel, genel_s2...), metinler sitenin dilinden okunur.
     *
     * @param array<string,mixed> $reservation
     * @return array<string,mixed>
     */
    private function mailInfo(PDO $pdo, array $reservation): array
    {
        $siteId = (int) ($reservation['site'] ?? 1);
        $suffix = $this->siteTableSuffix($pdo, $siteId);
        $english = $siteId === 2;
        $doviz = strtolower(trim((string) ($reservation['doviz'] ?? '')));

        // Site sabitleri
        $genel = [];
        try {
            $row = $pdo->query('SELECT TOP 1 * FROM genel' . $suffix)->fetch(PDO::FETCH_ASSOC);
            $genel = is_array($row) ? $row : [];
        } catch (\PDOException $e) {
            $genel = [];
        }
        $g = function (string $key) use ($genel): string {
            return isset($genel[$key]) ? trim((string) $genel[$key]) : '';
        };
        $domain = rtrim($g('domain'), '/');
        $siteName = $g('siteadi');

        // Linkler: "web." -> "www." (panel). CDN: alan adının başı www./web. ise cdn. olur, değilse başına cdn. eklenir
        // (panel sadece www. -> cdn. yapıyordu; domain web. ile kayıtlıysa CDN yerine web. adresine gidiyordu).
        $site = [
            'id' => $siteId,
            'adi' => $siteName,
            'domain' => str_replace('web.', 'www.', $domain),
            'cdn' => $this->cdnFromDomain($domain),
            'telefon' => $g('telefon'),
            'gsm' => $g('gsm'),
            'whatsapp' => $g('whatsapp'),
            'mail' => $g('sitemail'),
            'instagram' => $g('instagram'),
            'facebook' => $g('facebook'),
            'youtube' => $g('youtube'),
            'adres' => $g('adres'),
        ];

        // Mail alt metinleri (panel: ms_fn(id) -> mailsablon.icerik{suffix}): ödeme bekliyor 6, onaylandı 3.
        // {saat} Natsisa'da opsiyon süresiyle doldurulur.
        $paymentPendingText = $this->mailText($pdo, 6, $suffix, $siteName);
        $approvedText = $this->mailText($pdo, 3, $suffix, $siteName);

        // Rezervasyonlarım linki (panel siparis_3: domain/rezervasyonlarimUrl?authKey=idHash(id_email)).
        // Sayfa yolu ödeme linkleri gibi sayfalar{suffix} tablosundan (ayar: reservation_my_reservations_page_id,
        // varsayılan 3 = /rezervasyonlarim); bulunamazsa genel tablosundaki rezervasyonlarimUrl alanı denenir.
        $myReservationsPath = $this->pagePath(
            $pdo,
            $suffix,
            $this->pageIdSetting('reservation_my_reservations_page_id', $siteId, $suffix)
        );
        if ($myReservationsPath === '') {
            $myReservationsPath = trim($g('rezervasyonlarimUrl'), '/');
        }
        $linkDomain = $site['domain'] !== '' ? rtrim($site['domain'], '/') : $this->paymentDomain();
        $myReservationsLink = '';
        if ($linkDomain !== '' && $myReservationsPath !== '') {
            $myReservationsLink = $linkDomain . '/' . $myReservationsPath . '?authKey='
                . $this->idHash((int) ($reservation['id'] ?? 0) . '_' . (string) ($reservation['email'] ?? ''));
        }

        // Villa: sitenin dilinde ad ve bölge ("Üst bölge / Bölge"), ilk resim (panel: /uploads/400x320/<resim>)
        $villa = ['adi' => '', 'bolge' => '', 'resim' => '', 'resimUrl' => ''];
        $homeId = (int) ($reservation['evid'] ?? 0);
        if ($homeId > 0) {
            try {
                $row = $this->fetchOne(
                    $pdo,
                    "SELECT h.baslik{$suffix} AS adi, h.resim,
                            CONCAT(d2.baslik{$suffix}, ' / ', d3.baslik{$suffix}) AS bolge
                     FROM homes h
                     LEFT JOIN destinations d3 ON d3.id = h.emlak_bolgesi
                     LEFT JOIN destinations d2 ON d2.id = d3.cat
                     WHERE h.id = :id",
                    [':id' => $homeId]
                );
            } catch (\PDOException $e) {
                $row = false;
            }
            if (is_array($row)) {
                $images = array_values(array_filter(array_map('trim', explode(',', (string) ($row['resim'] ?? '')))));
                $image = $images !== [] ? '/uploads/400x320/' . $images[0] : '';
                $villa = [
                    'adi' => trim((string) ($row['adi'] ?? '')),
                    'bolge' => trim((string) ($row['bolge'] ?? ''), " /"),
                    'resim' => $image,
                    'resimUrl' => $image !== '' && $site['cdn'] !== '' ? rtrim($site['cdn'], '/') . $image : '',
                ];
            }
        }

        // Ödeme türü (kayitlar.odeme): 2 = havale, diğerleri kart. Ön ödemeli (kayitlar.tur = 1).
        $transfer = (string) ($reservation['odeme'] ?? '') === '2';
        $partial = (string) ($reservation['tur'] ?? '') === '1';

        $amountDueNow = $partial
            ? $this->mailMoney($reservation['on_odeme'] ?? '', $doviz)
            : $this->mailMoney($reservation['toplam_tutar'] ?? '', $doviz);

        return [
            'site' => $site,
            'odemeBekliyorMetni' => $paymentPendingText,
            'onaylandiMetni' => $approvedText,
            'rezervasyonlarimLinki' => $myReservationsLink,
            'villa' => $villa,
            'odemeTuru' => $transfer ? 'havale' : 'kart',
            'onOdemeli' => $partial,
            'simdiOdenecek' => $amountDueNow,
            'hasarDepozitosu' => trim((string) ($reservation['hasar_dep'] ?? '')),
            'odemeSatirlariHtml' => $this->mailPaymentRowsHtml($pdo, $reservation, $siteId, $english, $doviz, $partial),
            // Sitenin tüm banka hesapları, her biri panelin mail kutusu (HTML) ile. Hangilerinin maile gireceği
            // Natsisa bildirim ayarında seçilir (seçim yoksa hepsi).
            'bankaHesaplari' => $this->mailBankAccounts($pdo, $english, $site['cdn']),
        ];
    }

    /**
     * Panel mail_sablon_html ödeme satırları: ön ödeme, villaya girişte ödenecek (ön ödemeli), temizlik, ısıtmalı havuz,
     * elektrik, ekstra ödemeler ve (ekstra varsa) girişte ödenecek toplam. Değerler panelde olduğu gibi ham yazılır.
     *
     * @param array<string,mixed> $reservation
     */
    private function mailPaymentRowsHtml(PDO $pdo, array $reservation, int $siteId, bool $english, string $doviz, bool $partial): string
    {
        $labels = $english
            ? ['on' => 'Advance Payment Amount', 'kalan' => 'Amount to be Paid on Villa Entry', 'temizlik' => 'Cleaning Fee',
               'elektrik' => 'Electricity Fee', 'isitma' => 'Heated Fee', 'toplam' => 'Total Amount to be Paid on Entry']
            : ['on' => 'Ön Ödeme Tutarı', 'kalan' => 'Villaya Girişte Ödenecek Tutar', 'temizlik' => 'Temizlik Ücreti',
               'elektrik' => 'Elektrik Ücreti', 'isitma' => 'Isıtmalı Havuz Ücreti', 'toplam' => 'Girişte Ödenecek Toplam Tutar'];

        $html = '';
        $remaining = 0.0;
        $extra = 0.0;
        $value = function ($v): string {
            return trim((string) $v);
        };

        if ($partial) {
            $remaining = $this->mailNumber($reservation['kalan'] ?? '');
            $html .= $this->mailRow($labels['on'], $value($reservation['on_odeme'] ?? ''));
            $html .= $this->mailRow($labels['kalan'], $value($reservation['kalan'] ?? ''));
        }

        $cleaning = $value($reservation['temizlik'] ?? '');
        if ($cleaning !== '' && $cleaning !== '0') {
            $extra += $this->mailNumber($cleaning);
            $html .= $this->mailRow($labels['temizlik'], $cleaning);
        }

        $heated = $reservation['isitmaliHavuzBool'] ?? null;
        if ($heated === true || $heated === 1 || $heated === '1') {
            $extra += $this->mailNumber($reservation['isitmaliHavuz'] ?? '');
            $html .= $this->mailRow($labels['isitma'], $value($reservation['isitmaliHavuz'] ?? ''));
        }

        try {
            $extras = $this->fetchAll(
                $pdo,
                'SELECT * FROM Reservation.vw_ReservationExtraPayments WHERE ReservationId = :id',
                [':id' => (int) ($reservation['id'] ?? 0)]
            );
        } catch (\PDOException $e) {
            $extras = [];
        }
        foreach ($extras as $row) {
            $extra += $this->mailNumber($row['TotalAmount'] ?? '');
            $html .= $this->mailRow((string) ($row['Title'] ?? ''), ($row['Symbol'] ?? '') . ($row['TotalAmount'] ?? ''));
        }

        $electricity = $value($reservation['elektrik'] ?? '');
        if ($electricity !== '' && $electricity !== '0') {
            $extra += $this->mailNumber($electricity);
            $html .= $this->mailRow($labels['elektrik'], $electricity);
        }

        if ($extra != 0.0) {
            $color = $siteId === 3 ? '#d84d64' : '#ed7823';
            $html .= '<tr>'
                . "<td style='padding: 5px 12px;'><strong style='padding-inline-end: 8px; font-size: 14px; color:" . $color . ";font-weight:700'>"
                . htmlspecialchars($labels['toplam'], ENT_QUOTES, 'UTF-8') . '</strong></td>'
                . "<td style='float: right; padding: 5px 12px; text-align: end; font-size: 14px; font-weight: 600; color: #4A4B54;'><strong>"
                . htmlspecialchars($this->mailMoney((string) ($remaining + $extra), $doviz), ENT_QUOTES, 'UTF-8') . '</strong></td>'
                . '</tr>';
        }

        return $html;
    }

    /** "https://web.site.com" / "https://www.site.com" / "https://site.com" -> "https://cdn.site.com/". */
    private function cdnFromDomain(string $domain): string
    {
        $domain = trim($domain);
        if ($domain === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $domain) !== 1) {
            $domain = 'https://' . $domain;
        }
        $parts = parse_url($domain);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if ($host === '') {
            return '';
        }
        if (strpos($host, 'cdn.') !== 0) {
            $host = 'cdn.' . preg_replace('/^(www|web)\./', '', $host);
        }

        return ($parts['scheme'] ?? 'https') . '://' . $host . '/';
    }

    /** Panel ms_fn: mailsablon.icerik{suffix} ({siteadi} doldurulur). Tablo/kayıt yoksa boş. */
    private function mailText(PDO $pdo, int $id, string $suffix, string $siteName): string
    {
        try {
            $row = $this->fetchOne($pdo, 'SELECT * FROM mailsablon WHERE id = :id', [':id' => $id]);
        } catch (\PDOException $e) {
            return '';
        }
        if (!is_array($row)) {
            return '';
        }

        return str_replace('{siteadi}', $siteName, (string) ($row['icerik' . $suffix] ?? $row['icerik'] ?? ''));
    }

    /** Panel htmlSablonItem: başlık / değer satırı. */
    private function mailRow(string $title, string $value): string
    {
        return '<tr>'
            . "<td style='padding: 5px 12px;'><strong style='padding-inline-end: 8px; font-size: 14px; font-weight: 600; color: #4a4b5499;'>"
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</strong></td>'
            . "<td style='float: right; padding: 5px 12px; text-align: end; font-size: 14px; font-weight: 600; color: #4A4B54;'><strong>"
            . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</strong></td>'
            . '</tr>';
    }

    /**
     * Panel siparis_1 banka hesapları (hesaplar, kullanici = 0: sitenin hesapları, siralama sırasıyla).
     * Her hesap için panelin mail kutusu (HTML) üretilir; seçim Natsisa'da yapılır.
     *
     * @return array<int,array{id:int,banka:string,hesapAdi:string,html:string}>
     */
    private function mailBankAccounts(PDO $pdo, bool $english, string $cdn): array
    {
        try {
            $accounts = $this->fetchAll($pdo, 'SELECT * FROM hesaplar WHERE kullanici = 0 ORDER BY siralama ASC');
        } catch (\PDOException $e) {
            return [];
        }

        $t = $english
            ? ['alici' => 'Recipient', 'sube' => 'Branch code', 'hesap' => 'Account number']
            : ['alici' => 'Alıcı', 'sube' => 'Şube Kodu', 'hesap' => 'Hesap No'];
        $e = function ($v): string {
            return htmlspecialchars(trim((string) $v), ENT_QUOTES, 'UTF-8');
        };
        $line = function (string $label, $value) use ($e): string {
            return "<tr style='display: block; height: 25%; font-size: 14px; color: #4A4B54;'><td>"
                . '<span>' . $e($label) . ' :</span> <span>' . $e($value) . '</span></td></tr>';
        };

        $result = [];
        foreach ($accounts as $a) {
            $image = rtrim($cdn, '/') . '/uploads/' . trim((string) ($a['resim'] ?? '')) . '?l=0';
            $rows = $line($t['alici'], $a['hesap_adi'] ?? '');
            foreach ([
                [$t['sube'], 'sube_kod'],
                [$t['hesap'], 'hesap_no'],
                ['TL IBAN', 'iban_tl'],
                ['GBP IBAN', 'iban_pound'],
                ['EUR IBAN', 'iban_euro'],
                ['USD IBAN', 'iban_dolar'],
            ] as [$label, $column]) {
                if (trim((string) ($a[$column] ?? '')) !== '') {
                    $rows .= $line($label, $a[$column]);
                }
            }

            $html = "<tr style='margin-bottom: 36px; display: block;'><td class='sm-ml-0' style='margin-left: 20px; display: block'>"
                . "<table style='width: 100%;' cellpadding='0' cellspacing='0' role='presentation'><tbody><tr>"
                . "<td class='sm-float-left sm-w-full' style='float: none; width: 154px'>"
                . "<div class='sm-w-full sm-mx-0 sm-mb-4' style='margin-left: auto; margin-right: auto; height: 83px; width: 154px; overflow: hidden; border-radius: 4px; border: 1px solid #d1d5db; text-align: center'>"
                . "<img src='" . $e($image) . "' alt='Resim' width='114' height='78' style='max-width: 100%; vertical-align: middle; line-height: 1; border: 0; object-fit: contain;'>"
                . '</div></td>'
                . "<td class='sm-ml-0' style='margin-left: 36px; display: block'>"
                . "<table style='height: 85px;' cellpadding='0' cellspacing='0' role='presentation'><tbody>" . $rows . '</tbody></table>'
                . '</td></tr></tbody></table></td></tr>';

            $result[] = [
                'id' => (int) ($a['id'] ?? 0),
                'banka' => trim((string) ($a['banka'] ?? '')),
                'hesapAdi' => trim((string) ($a['hesap_adi'] ?? '')),
                'html' => $html,
            ];
        }

        return $result;
    }

    /** Panel DovizTemizle: para birimi yazılarını ve ayırıcıları temizleyip sayı döner. */
    private function mailNumber($value): float
    {
        $number = $this->moneyValue($value);

        return $number === null ? 0.0 : (float) $number;
    }

    /** Panel doviz_cevir_format: binlik ".", kuruş ","; tl -> "x TL", euro/dolar/pound -> "€ x" / "$ x" / "£ x". */
    private function mailMoney($value, string $doviz): string
    {
        $number = $this->moneyValue($value);
        if ($number === null) {
            return trim((string) $value);
        }
        $decimals = abs($number - floor($number)) > 0.0001 ? 2 : 0;
        $text = number_format($number, $decimals, ',', '.');

        switch ($doviz) {
            case 'tl':
            case 'try':
                return $text . ' TL';
            case 'pound':
                return '£ ' . $text;
            case 'dolar':
                return '$ ' . $text;
            case 'euro':
                return '€ ' . $text;
            default:
                return $text;
        }
    }

    /** Panel inc/Functions.asp idHash: Base64("{burasiidkismi_}" + veri). */
    private function idHash(string $data): string
    {
        return base64_encode('{burasiidkismi_}' . $data);
    }

    /** Panel: replace(qsql("domain"), "web.", "www."). Domain sabiti config.php'den gelir. */
    private function paymentDomain(): string
    {
        $domain = defined('Domain') ? trim((string) constant('Domain')) : '';
        if ($domain === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $domain) !== 1) {
            $domain = 'https://' . $domain;
        }

        return rtrim(str_replace('web.', 'www.', $domain), '/');
    }

    /** Ayar tek sayı ya da [siteId|dbtable => id] olabilir (homes_management_cancellation_policy_page_id gibi). */
    private function pageIdSetting(string $key, int $siteId, string $suffix): int
    {
        $config = $this->app[$key] ?? 0;
        if (!is_array($config)) {
            return is_numeric($config) ? (int) $config : 0;
        }
        foreach ([$siteId, (string) $siteId, $suffix] as $k) {
            if (array_key_exists($k, $config) && is_numeric($config[$k])) {
                return (int) $config[$k];
            }
        }

        return 0;
    }

    private function siteTableSuffix(PDO $pdo, int $siteId): string
    {
        if ($siteId <= 0) {
            return '';
        }
        try {
            $row = $this->fetchOne($pdo, 'SELECT dbtable FROM sites WHERE id = :id', [':id' => $siteId]);
        } catch (\PDOException $e) {
            return '';
        }
        $suffix = is_array($row) ? trim((string) ($row['dbtable'] ?? '')) : '';

        return $suffix === '' || preg_match('/^_[A-Za-z0-9]+$/', $suffix) === 1 ? $suffix : '';
    }

    private function pagePath(PDO $pdo, string $suffix, int $pageId): string
    {
        if ($pageId <= 0) {
            return '';
        }
        try {
            $row = $this->fetchOne($pdo, "SELECT url FROM sayfalar{$suffix} WHERE id = :id", [':id' => $pageId]);
        } catch (\PDOException $e) {
            return '';
        }

        return is_array($row) ? trim(trim((string) ($row['url'] ?? '')), '/') : '';
    }

    private function ibansec(): void
    {
        $ibanId = (int) $this->request->query('iban_id', 0);
        if ($ibanId <= 0) {
            throw new HttpException('Lütfen geçerli bir iban_id gönderin.', 'VALIDATION', 422);
        }

        $rows = $this->fetchAll(
            $this->db->pdo(),
            'SELECT * FROM hesaplar WHERE id = :id',
            [':id' => $ibanId]
        );

        $this->response->success([
            'ibansec' => $rows,
        ]);
    }

    /**
     * @return array<string,mixed>|false
     */
    private function fetchReservation(PDO $pdo, int $id)
    {
        $sql = "SELECT *,
                    (SELECT site FROM sites WHERE id = kayitlar.site) AS siteadi,
                    CASE
                        WHEN doviz = 'euro' THEN 'EUR'
                        WHEN doviz = 'dolar' THEN 'USD'
                        WHEN doviz = 'pound' THEN 'GBP'
                        ELSE 'TRY'
                    END AS btrans_para_birimi,
                    ISNULL(btrans_iban, '00') AS btrans_iban,
                    ISNULL(btrans_odeme_bilgisi, 0) AS btrans_odeme_bilgisi,
                    ISNULL(btrans_odeme_yontemi, 4) AS btrans_odeme_yontemi,
                    ISNULL(iyzico_odeme, 1) AS iyzico_odeme,
                    ISNULL(kazancorani, 0) AS kazancorani,
                    ISNULL(
                        onaylanmaTarihi2,
                        (
                            CASE
                                WHEN CHARINDEX('-', onaylanmaTarihi) > 0
                                    THEN CONVERT(date, CONVERT(date, onaylanmaTarihi, 102), 103)
                                ELSE CONVERT(date, onaylanmaTarihi, 103)
                            END
                        )
                    ) AS onaytarihi,
                    ISNULL(eskifiyat, '') AS eskifiyat,
                    ISNULL(promotionCode, '') AS promotionCode,
                    CONVERT(varchar, kayitlar.rez_tarihi, 104) AS rez_tarihix,
                    CONVERT(varchar, kayitlar.gelecek_tarih, 104) AS gelecek_tarihx,
                    ISNULL((SELECT baslik FROM satis_kanallari WHERE id = kayitlar.satis_kanallari_id), '') AS satiskanali,
                    ISNULL(arandi, 0) AS arandi
                FROM kayitlar
                WHERE id = :id";

        return $this->fetchOne($pdo, $sql, [':id' => $id]);
    }

    /**
     * @param array<string,mixed> $reservation
     * @param array<string,mixed> $personInfo
     * @return array{yetiskin:int,cocuk:int,bebek:int,toplam:int}
     */
    private function personCountInfo(array $reservation, array $personInfo): array
    {
        $sources = [$reservation, $personInfo];

        $adult = $this->firstIntFrom($sources, ['yetiskin', 'yetiskin_sayisi', 'adult', 'adults'], -1);
        if ($adult < 0) {
            $adult = $this->firstIntFrom([$reservation], ['kisi'], 0);
        }
        $child = $this->firstIntFrom($sources, ['cocuk', 'cocuk_sayisi', 'child', 'children'], 0);
        $baby = $this->firstIntFrom($sources, ['bebek', 'bebek_sayisi', 'infant', 'baby'], 0);

        return [
            'yetiskin' => $adult,
            'cocuk' => $child,
            'bebek' => $baby,
            'toplam' => $adult + $child + $baby,
        ];
    }

    /**
     * @param array<string,mixed> $reservation
     * @param array<string,mixed> $dolu
     * @param array<string,mixed> $villa
     * @param array{yetiskin:int,cocuk:int,bebek:int,toplam:int} $personCountInfo
     * @param array<string,array<int,array<string,mixed>>> $documentLogs
     * @param array<string,mixed> $documentState
     * @param array<int,array<string,mixed>> $personInfoList
     * @return array<string,array<string,mixed>>
     */
    private function detailSections(
        array $reservation,
        array $dolu,
        array $villa,
        array $personCountInfo,
        array $documentLogs,
        array $documentState,
        array $personInfoList
    ): array
    {
        return [
            'rezervasyonBilgileri' => [
                'rezervasyonNo' => $this->intValue($this->firstValueFrom([$reservation], ['id'])),
                'emlakIsmi' => $this->firstValueFrom([$villa, $reservation], ['baslik', 'villa_adi', 'adi', 'emlak_adi']),
                'emlak_tipi' => $this->firstValueFrom([$villa], ['emlak_tipi_baslik']),
                'emlak_bolgesi' => $this->firstValueFrom([$villa], ['emlak_bolgesi_baslik']),
                'resim_liste' => $this->homeImages($villa),
                'kapak_resmi' => $this->homeCoverImage($villa),
                'satisKanali' => $this->firstValueFrom([$reservation], ['satiskanali', 'satis_kanali', 'satisKanali']) ?: 'site',
                'siteDil' => $this->firstValueFrom([$reservation], ['site_dil', 'siteDil', 'dil', 'lang', 'language']) ?: 'tr',
                'durum' => [
                    // Siteye özel silinme kodu (örn. -1) 5 / Silindi olarak döner.
                    'id' => (int) $this->normalizeStatus($this->intValue($this->firstValueFrom([$dolu, $reservation], ['durum', 'Durum']))),
                    'text' => $this->reservationStatusText((int) $this->normalizeStatus($this->intValue($this->firstValueFrom([$dolu, $reservation], ['durum', 'Durum'])))),
                    // Opsiyon süresi (saat), dolu.saat2. Bitiş kayitlar.saat; başlangıç = bitiş - süre.
                    'opsiyonSuresiSaat' => $this->optionHours($dolu),
                ],
            ],
            'rezervasyonDetaylari' => [
                'girisTarihi' => $this->firstValueFrom([$reservation, $dolu], ['rez_tarihix', 'giris_tarihi', 'rez_tarihi', 'tarih']),
                'cikisTarihi' => $this->firstValueFrom([$reservation, $dolu], ['gelecek_tarihx', 'cikis_tarihi', 'gelecek_tarih', 'tarih2']),
                'geceSayisi' => $this->nightCount($reservation),
                'yetiskin' => $personCountInfo['yetiskin'],
                'cocuk' => $personCountInfo['cocuk'],
                'bebek' => $personCountInfo['bebek'],
                'musteriNotu' => $this->firstValueFrom([$reservation], ['oznot', 'oz_not', 'musteri_notu', 'not']),
            ],
            'misafirler' => $this->guestInfo($personInfoList, $reservation, $personCountInfo),
            'odemeBilgileri' => [
                'odemeTuru' => [
                    'id' => $this->intValue($this->firstValueFrom([$reservation], ['tur', 'odeme_turu'])),
                    'text' => $this->paymentTypeText($this->intValue($this->firstValueFrom([$reservation], ['tur', 'odeme_turu']))),
                ],
                'odemeSekli' => [
                    'id' => $this->intValue($this->firstValueFrom([$reservation], ['odeme', 'odeme_sekli'])),
                    'text' => $this->paymentMethodText($this->intValue($this->firstValueFrom([$reservation], ['odeme', 'odeme_sekli']))),
                ],
                'paraBirimi' => $this->firstValueFrom([$reservation], ['btrans_para_birimi', 'doviz']),
                'toplamUcret' => $this->moneyValue($this->firstValueFrom([$reservation], ['toplam_tutar'])),
                'onOdeme' => $this->moneyValue($this->firstValueFrom([$reservation], ['on_odeme'])),
                'kalanOdeme' => $this->moneyValue($this->firstValueFrom([$reservation], ['kalan'])),
                'kazancOran' => $this->moneyValue($this->firstValueFrom([$reservation], ['kazancorani'])),
                'depozitoOrani' => $this->moneyValue($this->firstValueFrom([$reservation, $villa], ['depozito_orani', 'depozitoOrani', 'depozitoorani'])),
                'temizlikUcreti' => $this->moneyValue($this->firstValueFrom([$reservation, $villa], ['temizlik', 'temizlik_ucreti', 'temizlikUcreti'])),
                'depozitoUcretiGiriste' => $this->moneyValue($this->firstValueFrom([$reservation, $villa], ['depozito_ucreti', 'depozitoUcreti', 'depozito'])),
            ],
            'sozlesmeler' => [
                'belgeKayitlari' => $documentState,
                'sozlesme' => $this->documentLogsOrNotSent($documentLogs, ['Sözleşme', 'SÃ¶zleÅŸme']),
                'odemeBelgesi' => $this->documentLogsOrNotSent($documentLogs, ['Ödeme Belgesi', 'Ã–deme Belgesi']),
                'iptalSartlari' => $this->documentLogsOrNotSent($documentLogs, ['İptal Şartları', 'Ä°ptal ÅžartlarÄ±']),
                'kiralamaSozlesmesi' => $this->documentLogsOrNotSent($documentLogs, ['Kiralama Sözleşmesi', 'Kiralama SÃ¶zleÅŸmesi']),
                'islemKaydi' => $documentLogs,
            ],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $personInfoList
     * @return array<int,array<string,mixed>>
     */
    private function guestInfo(array $personInfoList, array $reservation, array $personCountInfo): array
    {
        $guests = [];

        foreach ($personInfoList as $personInfo) {
            $parsedGuests = $this->guestsFromPeopleColumn($personInfo);
            if ($parsedGuests !== []) {
                foreach ($parsedGuests as $guest) {
                    $guests[] = $this->withoutEmptyValues($guest);
                }
                continue;
            }

            $guests[] = $this->withoutEmptyValues([
                'id' => $this->nullableIntValue($this->firstValueFrom([$personInfo], ['id'])),
                'siparisKodu' => $this->nullableIntValue($this->firstValueFrom([$personInfo], ['siparis_kodu'])),
                'tip' => null,
                'isim' => $this->firstValueFrom([$personInfo], ['isim']),
                'tc' => $this->firstValueFrom([$personInfo], ['tc']),
                'eposta' => $this->firstValueFrom([$personInfo], ['eposta']),
                'adres' => $this->firstValueFrom([$personInfo], ['adres']),
                'onay' => $this->boolValue($this->firstValueFrom([$personInfo], ['onay'])),
                'telefon' => $this->firstValueFrom([$personInfo], ['telefon']),
                'faturatipi' => $this->nullableIntValue($this->firstValueFrom([$personInfo], ['faturatipi'])),
                'vergidairesi' => $this->firstValueFrom([$personInfo], ['vergidairesi']),
            ]);
        }

        if ($guests === []) {
            $fallbackGuest = $this->withoutEmptyValues([
                'siparisKodu' => $this->nullableIntValue($this->firstValueFrom([$reservation], ['id'])),
                'tip' => 'Ana Misafir',
                'isim' => $this->firstValueFrom([$reservation], ['musteri', 'musteri_adi', 'adsoyad', 'ad_soyad']),
                'telefon' => $this->firstValueFrom([$reservation], ['telefon', 'musteri_teli', 'tel', 'gsm']),
                'eposta' => $this->firstValueFrom([$reservation], ['eposta', 'email', 'mail']),
                'yetiskin' => $personCountInfo['yetiskin'],
                'cocuk' => $personCountInfo['cocuk'],
                'bebek' => $personCountInfo['bebek'],
                'toplam' => $personCountInfo['toplam'],
            ]);

            if ($fallbackGuest !== []) {
                $guests[] = $fallbackGuest;
            }
        }

        return $guests;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchPersonInfoList(PDO $pdo, int $id): array
    {
        $candidateColumns = [
            'siparis_kodu',
            'siparisKodu',
            'kayitlarId',
            'kayit_id',
            'rezervasyon_id',
            'rezid',
        ];

        $whereParts = [];
        foreach ($candidateColumns as $column) {
            if ($this->columnExists($pdo, 'dbo.kisi_bilgileri', $column)) {
                $whereParts[] = '[' . $column . '] = :id';
            }
        }

        if ($whereParts === []) {
            return [];
        }

        $orderBy = $this->columnExists($pdo, 'dbo.kisi_bilgileri', 'id') ? ' ORDER BY id ASC' : '';

        return $this->fetchAll(
            $pdo,
            'SELECT * FROM kisi_bilgileri WHERE ' . implode(' OR ', $whereParts) . $orderBy,
            [':id' => $id]
        );
    }

    /**
     * @param array<string,mixed> $personInfo
     * @return array<int,array<string,mixed>>
     */
    private function guestsFromPeopleColumn(array $personInfo): array
    {
        $raw = $this->firstValueFrom([$personInfo], ['kisiler']);
        if ($raw === null || trim((string) $raw) === '') {
            return [];
        }

        $parts = explode('##', (string) $raw);
        if (count($parts) < 2) {
            return [];
        }

        $types = $this->splitPeoplePart($parts[0]);
        $names = $this->splitPeoplePart($parts[1]);
        $identityNumbers = $this->splitPeoplePart($parts[2] ?? '');
        $max = max(count($types), count($names), count($identityNumbers));
        if ($max === 0) {
            return [];
        }

        $guests = [];
        for ($i = 0; $i < $max; $i++) {
            $guests[] = $this->withoutEmptyValues([
                'id' => $this->nullableIntValue($this->firstValueFrom([$personInfo], ['id'])),
                'siparisKodu' => $this->nullableIntValue($this->firstValueFrom([$personInfo], ['siparis_kodu'])),
                'tip' => $types[$i] ?? null,
                'isim' => $names[$i] ?? null,
                'tc' => $identityNumbers[$i] ?? null,
            ]);
        }

        return $guests;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function withoutEmptyValues(array $row): array
    {
        return array_filter($row, function ($value) {
            return $value !== null && $value !== '';
        });
    }

    /**
     * @return string[]
     */
    private function splitPeoplePart(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), function ($item) {
            return $item !== '';
        }));
    }

    /**
     * @param array<string,mixed> $villa
     * @return string[]
     */
    private function homeImages(array $villa): array
    {
        $resimStr = isset($villa['resim']) ? (string) $villa['resim'] : '';
        if ($resimStr === '') {
            return [];
        }

        $cdnBase = (defined('Cdn') ? (string) constant('Cdn') : '') . '/uploads/small/';
        $images = array_values(array_filter(array_map('trim', explode(',', $resimStr))));

        return array_map(function ($image) use ($cdnBase) {
            return $cdnBase . $image;
        }, $images);
    }

    /**
     * @param array<string,mixed> $villa
     */
    private function homeCoverImage(array $villa): ?string
    {
        $images = $this->homeImages($villa);

        return $images !== [] ? $images[0] : null;
    }

    /**
     * @param array<string,array<int,array<string,mixed>>> $logs
     * @param string[] $keys
     * @return array<int,array<string,mixed>>
     */
    private function documentLogs(array $logs, array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($logs[$key])) {
                return $logs[$key];
            }
        }

        return [];
    }

    /**
     * @param array<string,array<int,array<string,mixed>>> $logs
     * @param string[] $keys
     * @return array<int,array<string,mixed>>|string
     */
    private function documentLogsOrNotSent(array $logs, array $keys)
    {
        $items = $this->documentLogs($logs, $keys);

        return $items === [] ? 'Gönderilmedi' : $items;
    }

    /**
     * @param array<int,array<string,mixed>> $sources
     * @param string[] $keys
     */
    private function firstIntFrom(array $sources, array $keys, int $default): int
    {
        foreach ($sources as $source) {
            foreach ($keys as $key) {
                if (isset($source[$key]) && $source[$key] !== '') {
                    return (int) $source[$key];
                }
            }
        }

        return $default;
    }

    /**
     * @param array<int,array<string,mixed>> $sources
     * @param string[] $keys
     * @return mixed|null
     */
    private function firstValueFrom(array $sources, array $keys)
    {
        foreach ($sources as $source) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $source) && $source[$key] !== '') {
                    return $source[$key];
                }
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    private function intValue($value): int
    {
        return $value === null || $value === '' ? 0 : (int) $value;
    }

    /**
     * @param mixed $value
     */
    private function nullableIntValue($value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * @param mixed $value
     */
    private function boolValue($value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (bool) $value;
    }

    /**
     * @param mixed $value
     * @return float|int|null
     */
    private function moneyValue($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = trim((string) $value);
        $normalized = preg_replace('/[^\d,.-]/', '', $normalized);
        if ($normalized === null || $normalized === '' || $normalized === '-' || $normalized === ',') {
            return null;
        }

        if (strpos($normalized, ',') !== false) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        }

        return round((float) $normalized, 2);
    }

    /**
     * @param array<string,mixed> $reservation
     */
    private function nightCount(array $reservation): int
    {
        $gece = $this->firstValueFrom([$reservation], ['gece', 'gece_sayisi']);
        if ($gece !== null && $gece !== '') {
            return (int) $gece;
        }

        if (empty($reservation['rez_tarihi']) || empty($reservation['gelecek_tarih'])) {
            return 0;
        }

        try {
            $start = new \DateTime((string) $reservation['rez_tarihi']);
            $end = new \DateTime((string) $reservation['gelecek_tarih']);
            return max(0, (int) $start->diff($end)->format('%a'));
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * dolu.saat2: opsiyon süresi (saat). Kiralama Takvimi callback'i (OptionReservation) ve
     * reservation-status (yerel opsiyon) yazar. Sayı değilse null.
     *
     * @param array<string,mixed>|false|null $dolu
     * @return float|null
     */
    private function optionHours($dolu)
    {
        if (!is_array($dolu)) {
            return null;
        }
        $raw = $dolu['saat2'] ?? null;
        if ($raw === null || !is_numeric(trim((string) $raw))) {
            return null;
        }
        $hours = (float) trim((string) $raw);

        return $hours > 0 ? $hours : null;
    }

    private function reservationStatusText(int $status): string
    {
        switch ($status) {
            case 0:
                return 'Onay Bekliyor';
            case 1:
                return 'Ödeme Bekliyor';
            case 2:
                return 'Süre Doldu';
            case 3:
                return 'Onaylandı';
            case 4:
                return 'İptal Edildi';
            case 5:
                return 'Silindi';
            case 6:
                return 'Açık Rezervasyon';
            default:
                return '-';
        }
    }

    private function paymentTypeText(int $type): string
    {
        switch ($type) {
            case 1:
                return 'Ön Ödeme';
            case 2:
                return 'Tamamı';
            default:
                return '-';
        }
    }

    private function paymentMethodText(int $method): string
    {
        switch ($method) {
            case 1:
                return 'Kredi Kartı';
            case 2:
                return 'Havale';
            case 3:
                return 'Western Union';
            case 4:
                return 'Sanal Kart';
            case 5:
                return 'Sanal Havale';
            case 6:
                return 'Nakit';
            default:
                return '-';
        }
    }

    /**
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function fetchDocumentLogs(PDO $pdo, int $id): array
    {
        $logs = [];
        foreach (['Sözleşme', 'Ödeme Belgesi', 'İptal Şartları', 'Kiralama Sözleşmesi'] as $name) {
            $logs[$name] = $this->fetchAll(
                $pdo,
                'SELECT userName, description, createdDate
                 FROM islem_kaydi
                 WHERE cat = 1 AND d_id = :id AND name = :name
                 ORDER BY createdDate ASC',
                [
                    ':id' => $id,
                    ':name' => $name,
                ]
            );
        }

        return $logs;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|false
     */
    private function fetchOne(PDO $pdo, string $sql, array $params = [])
    {
        $stmt = $pdo->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    private function fetchAll(PDO $pdo, string $sql, array $params = []): array
    {
        $stmt = $pdo->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Rezervasyonun işlem geçmişi (kullanici_log_kaydi, islm = 'rezervasyon'). Panel ve Natsisa kayıtları birlikte.
     * Natsisa kayıtlarında kullanici_id 0'dır; kişi adı degisiklikler JSON'undan (kullaniciAdi) alınır ve açıklamanın
     * sonuna eklenen "(Ad)" kısmı ayrı gösterildiği için açıklamadan çıkarılır.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchActivityLogs(PDO $pdo, int $id): array
    {
        $hasDetails = $this->columnExists($pdo, 'dbo.kullanici_log_kaydi', 'degisiklikler');

        $rows = $this->fetchAll(
            $pdo,
            "SELECT TOP 100
                    l.id,
                    CONVERT(varchar(19), l.tarih, 120) AS tarih,
                    l.aciklama,
                    l.kullanici_id,
                    (SELECT TOP 1 k.ad + ' ' + k.soyad FROM kullanici k WHERE k.id = l.kullanici_id) AS kullanici_adi"
                . ($hasDetails ? ', l.degisiklikler' : ', NULL AS degisiklikler') . "
             FROM kullanici_log_kaydi l
             WHERE l.islm = 'rezervasyon' AND l.islm_id = :id
             ORDER BY l.id DESC",
            [':id' => $id]
        );

        $result = [];
        foreach ($rows as $row) {
            $details = null;
            $raw = isset($row['degisiklikler']) ? trim((string) $row['degisiklikler']) : '';
            if ($raw !== '' && $raw[0] === '{') {
                $decoded = json_decode($raw, true);
                // Sadece bizim yazdığımız ayrıntılar (kaynak alanı olan) döner; panelin kendi formatı dönmez.
                if (is_array($decoded) && isset($decoded['kaynak'])) {
                    $details = $decoded;
                }
            }

            $message = (string) ($row['aciklama'] ?? '');
            $userName = trim((string) ($row['kullanici_adi'] ?? ''));
            $source = 'panel';

            if ($details !== null) {
                $source = (string) $details['kaynak'];
                $detailUser = trim((string) ($details['kullaniciAdi'] ?? ''));
                if ($detailUser !== '') {
                    $suffix = ' (' . $detailUser . ')';
                    if (mb_substr($message, -mb_strlen($suffix)) === $suffix) {
                        $message = mb_substr($message, 0, mb_strlen($message) - mb_strlen($suffix));
                    }
                    if ($userName === '') {
                        $userName = $detailUser;
                    }
                }
            }

            $result[] = [
                'id' => (int) $row['id'],
                'tarih' => $row['tarih'],
                'aciklama' => $message,
                'kullanici' => $userName !== '' ? $userName : 'Sistem',
                'kaynak' => $source,
                'ayrinti' => $details,
            ];
        }

        return $result;
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('SELECT CASE WHEN COL_LENGTH(:table, :column) IS NULL THEN 0 ELSE 1 END');
        $stmt->bindValue(':table', $table);
        $stmt->bindValue(':column', $column);
        $stmt->execute();

        return (int) $stmt->fetchColumn() === 1;
    }

    /**
     * @param array<string,mixed> $params
     */
    private function bindParams(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }

    private function configBool(string $key, bool $default): bool
    {
        if (!array_key_exists($key, $this->app)) {
            return $default;
        }

        $value = $this->app[$key];
        if (is_bool($value)) {
            return $value;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $filtered === null ? $default : $filtered;
    }
}
