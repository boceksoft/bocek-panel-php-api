<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Rezervasyon detay kaynaÄŸÄ±.
 * ASP siparis_detaylari.asp ekranÄ±nÄ±n okuduÄŸu verileri JSON olarak dÃ¶ner.
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
        $defterReservationIdColumnSql = $this->qualifiedColumn(
            'defter',
            $this->configString('defter_reservation_id_column', 'islm_id')
        );

        $reservation = $this->fetchReservation($pdo, $id, $defterReservationIdColumnSql);
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

        $personInfoList = $this->fetchAll($pdo, 'SELECT * FROM kisi_bilgileri WHERE siparis_kodu = :id ORDER BY id ASC', [':id' => $id]);
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
            'sonrakiRzKontrol' => $nextReservation,
            'hesaplar_kullanici_0_banka' => $accounts['site'],
            'hesaplar_kullanici_0_siralama' => $accounts['site_ordered'],
            'hesaplar_evsahibi_siralama' => $accounts['owner'],
            'blockList' => $blockInfo,
            'parcaliOdeme' => $partialPayments,
        ]);
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
    private function fetchReservation(PDO $pdo, int $id, string $defterReservationIdColumnSql)
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
                    (SELECT COUNT(defter.id) FROM defter WHERE {$defterReservationIdColumnSql} = kayitlar.id) AS yorumsay,
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
                    'id' => $this->intValue($this->firstValueFrom([$dolu, $reservation], ['durum'])),
                    'text' => $this->reservationStatusText($this->intValue($this->firstValueFrom([$dolu, $reservation], ['durum']))),
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
            'misafirler' => $this->guestInfo($personInfoList),
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
    private function guestInfo(array $personInfoList): array
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

        return $guests;
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
     * @param array<string,mixed> $params
     */
    private function bindParams(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }

    private function qualifiedColumn(string $alias, string $column): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
            throw new HttpException('Gecersiz defter rezervasyon kolon ayari.', 'CONFIG_ERROR', 500);
        }

        return $alias . '.[' . $column . ']';
    }

    private function configString(string $key, string $default = ''): string
    {
        if (!array_key_exists($key, $this->app) || trim((string) $this->app[$key]) === '') {
            if ($default !== '') {
                return $default;
            }

            throw new HttpException('Eksik config ayari: ' . $key, 'CONFIG_ERROR', 500);
        }

        return trim((string) $this->app[$key]);
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
