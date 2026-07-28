<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Homes management detay kaynağı.
 * Eski emlak_duzenle.asp ekranının okuduğu recordset'leri JSON olarak listeler.
 */
final class HomesManagementDetailController extends Controller
{
    /**
     * Emlak yönetimi detay verilerini listeler.
     *
     * @Get
     * @query id int required Emlak kimliği
     */ 
    public function index(): void
    {
        $id = (int) $this->request->query('id', 0);
        if ($id <= 0) {
            throw new HttpException('Lütfen geçerli bir emlak ID gönderin.', 'VALIDATION', 422);
        }

        $siteId = max(1, (int) $this->request->query('site', 1));
        $pdo = $this->db->pdo();  

        $rs = $this->fetchOne(
            $pdo,
            "SELECT
                (SELECT ad FROM kullanici WHERE yetki = 2 AND homes.evsahibi = kullanici.id) AS evsadi,
                (SELECT soyad FROM kullanici WHERE yetki = 2 AND homes.evsahibi = kullanici.id) AS evssoyadi,
                (SELECT tel FROM kullanici WHERE yetki = 2 AND homes.evsahibi = kullanici.id) AS evstel,
                (SELECT baslik FROM tip WHERE tip.id = homes.emlak_tipi) AS emlak_tipi_baslik,
                (SELECT baslik FROM destinations WHERE destinations.id = homes.emlak_bolgesi) AS emlak_bolgesi_baslik,
                *,
                CONVERT(varchar, tarih, 104) AS tarih,
                CONVERT(datetime, yayinlama_tarihi) AS t,
                ISNULL(giris_saat, 0) AS giris_saat,
                ISNULL(cikis_saat, 0) AS cikis_saat
             FROM homes
             WHERE id = :id",
            [':id' => $id]
        );

        if (!$rs) { 
            throw new HttpException('Belirtilen ID ile emlak bulunamadı.', 'NOT_FOUND', 404);
        }

        $rawRs = $rs;
        $rs = $this->withoutS2Fields($rs);
        $evSahibi = [];
        if ((int) ($rs['evsahibi'] ?? 0) > 0) {
            $evSahibi = $this->fetchOne($pdo, 'SELECT * FROM kullanici WHERE id = :id', [':id' => (int) $rs['evsahibi']]);
            if (!is_array($evSahibi)) {
                $evSahibi = [];
            }
        }

        $tipCat0Aktif = $this->fetchAll($pdo, 'SELECT * FROM tip WHERE cat = 0 AND aktif = 1 ORDER BY baslik ASC');
        $images = $this->fetchAll(
            $pdo,
            "SELECT UploadId, filename, aciklama
             FROM upload
             WHERE islm = 'emlak' AND islm_id = :id
             ORDER BY sira ASC",
            [':id' => $id]
        );
        $mesafelerValues = $this->fetchAll(
            $pdo,
            "SELECT me.id AS meid, me.baslik, m.*
             FROM mesafelerValues m
             INNER JOIN mesafeler me ON me.id = m.mesafelerId
             WHERE m.homesId = :id",
            [':id' => $id]
        );
        $konum = $this->konumHiyerarsi($pdo, (int) ($rs['emlak_bolgesi'] ?? 0), $this->firstValueFrom($rs, ['emlak_bolgesi_baslik']));
        $this->response->success([
            'genelBilgiler' => [
                'temelBilgiler' => [
                    'villa_adi' => $this->firstValueFrom($rs, ['baslik']),
                    'kisa_aciklama' => $this->firstValueFrom($rs, ['kisa_icerik']),
                    'emlak_tipi_baslik' => $this->firstValueFrom($rs, ['emlak_tipi_baslik']),
                    'arr' => $this->onlyBaslik($tipCat0Aktif),
                ],
                'altKategoriler' => [
                    'kategori' => $this->fetchTipKategori($pdo, $tipCat0Aktif, $rs),
                ],
                'villaDetaylari' => [
                    'kapasite' => $this->firstValueFrom($rs, ['kisi']),
                    'yatak_odasi_sayisi' => $this->firstValueFrom($rs, ['yatak_odasi']),
                    'yatak_sayisi' => $this->firstValueFrom($rs, ['yatak_sayisi']),
                    'banyo_sayisi' => $this->firstValueFrom($rs, ['banyo']),
                ],
                'konaklamaKurallari' => [
                    'giris_saati' => $this->firstValueFrom($rs, ['giris_saat']),
                    'cikis_saati' => $this->firstValueFrom($rs, ['cikis_saat']),
                    'iptal_sarti' => $this->firstValueFrom($rs, ['iptal_politikasi', 'ozel_sartlar']),
                ],
                'iletisimBilgileri' => [
                    'bakimciBilgisi' => [
                        'ad_soyad' => $this->firstValueFrom($rs, ['bakimciad']),
                        'telefon' => $this->firstValueFrom($rs, ['bakimcitel']),
                    ],
                    'evSahibiBilgisi' => [
                        'ad_soyad' => $this->ownerFullName($evSahibi, $rs),
                        'telefon' => $this->firstValueFrom(array_merge($rs, $evSahibi), ['tel', 'evstel']),
                        'eposta' => $this->firstValueFrom($evSahibi, ['email', 'eposta', 'mail']),
                        'adres' => $this->firstValueFrom($evSahibi, ['adres', 'address']),
                    ],
                    'whatsapp_grup_adi' => $this->firstValueFrom($rs, ['whatsapp_grup', 'rez_takip_yeri_adi']),
                    'yetkili_firma_adi' => $this->firstValueFrom($rs, ['yetkilifirma']),
                ],
                'yoneticiBilgileri' => [
                    'ilan_notlari' => $this->firstValueFrom($rs, ['not2', 'not']),
                ],
                'detayliAciklama' => [
                    'icerik' => $this->firstValueFrom($rs, ['icerik']),
                ],
            ],
            'ozelliklerVeOlanaklar' => [
                'seciliOzellikler' => $this->seciliOzellikler($pdo, $rs),
                'oneCikanOzellikler' => $this->oneCikanOzellikler($pdo, $rs),
                'oneCikanEtiketler' => $this->selectedRibbonLabels($rs),
                'evKurallari' => $this->evKurallari($pdo, $rs),
                'dahilHizmetler' => $this->dahilHizmetler($pdo, $rs),
                'havuzlar' => $this->havuzlar($pdo, $id),
                'odalar' => $this->odalar($pdo, $id),
            ],
            'fiyatlandirmaVeKurallar' => [
                'donemselFiyatlandirma' => $this->donemselFiyatlandirma($pdo, $id, $siteId),
                'para_birimi' => $this->currencyCode($rs['doviz'] ?? ''),
                'sabitEkstraUcretler' => $this->sabitEkstraUcretler($rawRs, $siteId),
                'odemeAyarlari' => [
                    'depozito' => [
                        'tip' => 'percentage',
                        'tutar' => 0,
                        'oran' => (float) $this->firstValueFrom($rs, ['depozito']),
                    ],
                    'komisyonOrani' => (float) $this->firstValueFrom($rs, ['kazancorani']),
                    'izinVerilenOnOdemeYontemi' => $this->izinVerilenOnOdemeYontemi($pdo, (int) ($rs['FirstPaymentTypeId'] ?? 0)),
                ],
                'ekstraUcretler' => $this->ekstraUcretler($pdo, $id),
                'indirimler' => $this->indirimler($pdo, $id, $siteId),
            ],
            'konumBilgileri' => [
                'il' => $konum['il'],
                'ilce' => $konum['ilce'],
                'mahalle' => $konum['mahalle'],
                'enlem' => $this->firstValueFrom($rs, ['enlem']),
                'boylam' => $this->firstValueFrom($rs, ['boylam']),
                'konumNotlari' => $this->konumNotlari($pdo, $rawRs),
                'mesafeler' => $this->mesafeler($mesafelerValues),
                'mesafeHesaplamaTipi' => $this->firstValueFrom($rs, ['mesafe_cetvelitipi']),
            ],
            'medyaBilgileri' => [
                'resimler' => $this->resimler($images, $rs),
                'videolar' => $this->filledValues($rs, ['video']),
            ],
            'seoBilgileri' => [
                'metaBaslik' => $this->firstValueFrom($rs, ['title']),
                'metaAnahtarKelimeler' => $this->firstValueFrom($rs, ['keywords']),
                'metaAciklama' => $this->firstValueFrom($rs, ['description']),
                'slug' => $this->firstValueFrom($rs, ['url']),
                'canonical' => $this->firstValueFrom($rs, ['canonical']),
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|false
     */
    private function fetchOne(PDO $pdo, string $sql, array $params = [])
    {
        $stmt = $this->execute($pdo, $sql, $params);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    private function fetchAll(PDO $pdo, string $sql, array $params = []): array
    {
        $stmt = $this->execute($pdo, $sql, $params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string,mixed> $params
     */
    private function execute(PDO $pdo, string $sql, array $params = []): \PDOStatement
    {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt;
    }

    /**
     * @param array<int,array<string,mixed>> $sites
     * @return array<int,array<string,mixed>>
     */
    private function fetchSayfalar(PDO $pdo, array $sites): array
    {
        $rows = [];
        foreach ($sites as $site) {
            $suffix = $this->safeDbTableSuffix((string) ($site['dbtable'] ?? ''));
            $siteId = (int) ($site['id'] ?? 0);
            $rows[$siteId] = $this->fetchAll(
                $pdo,
                "SELECT *
                 FROM sayfalar{$suffix}
                 WHERE aktif = 1 AND id = 24
                 ORDER BY siralama ASC"
            );
        }

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $parents
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function fetchTipKategori(PDO $pdo, array $parents, array $rs): array
    {
        $selectedIds = $this->selectedIdMap((string) ($rs['kategori'] ?? ''), ',');
        $emlakTipi = (int) ($rs['emlak_tipi'] ?? 0);
        if ($emlakTipi > 0) {
            $selectedIds[$emlakTipi] = true;
        }

        $rows = [];
        foreach ($parents as $parent) {
            $cat = (int) ($parent['id'] ?? 0);
            if ($cat <= 0) {
                continue;
            }

            $children = $this->fetchAll(
                $pdo,
                'SELECT id, baslik FROM tip WHERE cat = :cat AND aktif = 1 ORDER BY siralama ASC',
                [':cat' => $cat]
            );
            $rows[$cat] = array_map(function (array $row) use ($selectedIds): array {
                $id = (int) ($row['id'] ?? 0);

                return [
                    'id' => $id,
                    'baslik' => $row['baslik'] ?? '',
                    'selected' => isset($selectedIds[$id]),
                ];
            }, $children);
        }

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array{baslik:mixed}>
     */
    private function onlyBaslik(array $rows): array
    {
        return array_map(
            static fn (array $row): array => ['baslik' => trim((string) ($row['baslik'] ?? '')) !== '' ? $row['baslik'] : ''],
            $rows
        );
    }

    /**
     * @param array<string,mixed> $rs
     * @return array<int,string>
     */
    private function selectedRibbonLabels(array $rs): array
    {
        return $this->filledValues($rs, ['ribbon1', 'ribbon', 'ribbon2']);
    }

    /**
     * @param array<string,mixed> $rs
     * @return array<int,array<string,mixed>>
     */
    private function seciliOzellikler(PDO $pdo, array $rs): array
    {
        $selectedIds = $this->selectedIdMap((string) ($rs['ozellikler'] ?? ''), '#');
        $rows = $this->fetchAll(
            $pdo,
            "SELECT ozellikler.*
             FROM ozellikler
             ORDER BY cat ASC, siralama ASC"
        );

        $features = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $features[] = [
                'id' => $id,
                'cat' => (int) ($row['cat'] ?? 0),
                'baslik' => $row['baslik'] ?? '',
                'selected' => isset($selectedIds[$id]),
            ];
        }

        return $features;
    }

    /**
     * @param array<string,mixed> $rs
     * @return array<int,array<string,mixed>>
     */
    private function oneCikanOzellikler(PDO $pdo, array $rs): array
    {
        $selectedIds = $this->selectedIdMap((string) ($rs['onecikan'] ?? ''), ',');
        $rows = $this->fetchAll(
            $pdo,
            'SELECT id, baslik FROM oneCikanOzellikler ORDER BY baslik ASC'
        );

        $items = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $items[] = [
                'id' => $id,
                'baslik' => $row['baslik'] ?? '',
                'selected' => isset($selectedIds[$id]),
            ];
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $rs
     * @return array<int,array<string,mixed>>
     */
    private function evKurallari(PDO $pdo, array $rs): array
    {
        $selectedIds = $this->selectedIdMap((string) ($rs['kurallar'] ?? ''), ',');
        $rows = $this->fetchAll(
            $pdo,
            'SELECT id, baslik FROM kurallar ORDER BY baslik ASC'
        );

        return $this->checkboxItems($rows, $selectedIds);
    }

    /**
     * @param array<string,mixed> $rs
     * @return array<int,array<string,mixed>>
     */
    private function dahilHizmetler(PDO $pdo, array $rs): array
    {
        $selectedIds = $this->selectedIdMap((string) ($rs['fiyata_dahil'] ?? ''), '#');
        $rows = $this->fetchAll(
            $pdo,
            'SELECT id, baslik FROM dahilOlanlar ORDER BY siralama ASC'
        );

        return $this->checkboxItems($rows, $selectedIds);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,bool> $selectedIds
     * @return array<int,array<string,mixed>>
     */
    private function checkboxItems(array $rows, array $selectedIds): array
    {
        $items = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $items[] = [
                'id' => $id,
                'baslik' => $row['baslik'] ?? '',
                'selected' => isset($selectedIds[$id]),
            ];
        }

        return $items;
    }

    /**
     * @return array<string,mixed>
     */
    private function izinVerilenOnOdemeYontemi(PDO $pdo, int $firstPaymentTypeId): array
    {
        if ($firstPaymentTypeId <= 0) {
            return [];
        }

        $row = $this->fetchOne(
            $pdo,
            'SELECT Id, Title FROM Finance.FirstPaymentTypes WHERE Id = :id',
            [':id' => $firstPaymentTypeId]
        );
        if (!is_array($row)) {
            return [];
        }

        return [
            'id' => (int) ($row['Id'] ?? 0),
            'title' => (string) ($row['Title'] ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function donemselFiyatlandirma(PDO $pdo, int $homeId, int $siteId): array
    {
        $calendarHome = $this->fetchOne(
            $pdo,
            'SELECT * FROM KiralamaTakvimi.CalendarHomes WHERE homesId = :homeId',
            [':homeId' => $homeId]
        );
        if (!is_array($calendarHome)) {
            $calendarHome = [];
        }

        $years = $this->fetchAll(
            $pdo,
            "SELECT YEAR(TRY_CONVERT(date, tarih2, 103)) AS yeardate
             FROM sezonlar
             WHERE LEN(tarih2) >= 10
               AND TRY_CONVERT(date, tarih2, 103) IS NOT NULL
               AND site = :site
               AND islem = 'emlak'
               AND islem_id = :homeId
             GROUP BY YEAR(TRY_CONVERT(date, tarih2, 103))
             ORDER BY YEAR(TRY_CONVERT(date, tarih2, 103)) DESC",
            [
                ':site' => $siteId,
                ':homeId' => $homeId,
            ]
        );
        $selectedYear = isset($years[0]['yeardate']) ? (int) $years[0]['yeardate'] : 0;

        $siteSuffix = $this->siteDbTableSuffix($pdo, $siteId);
        $villa = $this->fetchOne(
            $pdo,
            "SELECT doviz{$siteSuffix} AS doviz, hasar{$siteSuffix} AS hasar
             FROM homes
             WHERE id = :homeId",
            [':homeId' => $homeId]
        );
        if (!is_array($villa)) {
            $villa = [];
        }

        $rows = $this->fetchAll(
            $pdo,
            "SELECT *,
                    YEAR(TRY_CONVERT(date, tarih2, 103)) AS yeardate
             FROM sezonlar
             WHERE site = :site
               AND islem = 'emlak'
               AND islem_id = :homeId
             ORDER BY TRY_CONVERT(date, tarih1, 104) ASC",
            [
                ':site' => $siteId,
                ':homeId' => $homeId,
            ]
        );

        $sezonlar = [];
        foreach ($rows as $row) {
            $fiyat = $this->firstNonEmptyValue($row, ['fiyat']);
            $year = (int) ($row['yeardate'] ?? 0);
            $sezonlar[] = [
                'id' => (int) ($row['id'] ?? 0),
                'year' => $year,
                'visible' => $selectedYear === 0 || $year === $selectedYear,
                'sezon' => $this->firstNonEmptyValue($row, ['sezon']),
                'tarih1' => $this->firstNonEmptyValue($row, ['tarih1']),
                'tarih2' => $this->firstNonEmptyValue($row, ['tarih2']),
                'fiyat' => $fiyat,
                'fiyat_tipi' => 'haftalik',
                'gecelik_fiyat' => is_numeric($fiyat) ? ((float) $fiyat / 7) : '',
                'haftalik_fiyat' => $fiyat,
                'min_gece' => $this->firstNonEmptyValue($row, ['gece']),
                'minKonaklama' => $this->firstNonEmptyValue($row, ['gece']),
                'temizlik_gece' => $this->firstNonEmptyValue($row, ['temizlikGece']),
                'temizlik_fiyat' => $this->firstNonEmptyValue($row, ['temizlikFiyat']),
            ];
        }

        $calendarLinked = $calendarHome !== [];

        return [
            'site_id' => $siteId,
            'calendar_url' => 'calendar.asp?islem_id=' . $homeId . '&site=' . $siteId,
            'calendar_linked' => $calendarLinked,
            'price_transfer_warning' => $calendarLinked,
            'years' => array_map(static function (array $row): int {
                return (int) ($row['yeardate'] ?? 0);
            }, $years),
            'selected_year' => $selectedYear,
            'currency' => $this->currencyCode($villa['doviz'] ?? ''),
            'damage_deposit' => $this->firstNonEmptyValue($villa, ['hasar']),
            'fiyat_tipleri' => [
                ['id' => 'gunluk', 'title' => 'Gunluk'],
                ['id' => 'haftalik', 'title' => 'Haftalik'],
            ],
            'calendar_settings' => $calendarLinked ? $this->calendarSettings($calendarHome) : [],
            'sezonlar' => $sezonlar,
        ];
    }

    /**
     * @param array<string,mixed> $calendarHome
     * @return array<string,mixed>
     */
    private function calendarSettings(array $calendarHome): array
    {
        return [
            'homesId' => (int) ($calendarHome['homesId'] ?? 0),
            'estateId' => (int) ($calendarHome['EstateId'] ?? 0),
            'roomType' => (string) ($calendarHome['RoomType'] ?? ''),
            'connectionType' => (string) ($calendarHome['ConnectionType'] ?? ''),
            'hotelRunnerAddCommission' => (bool) ($calendarHome['HotelRunnerAddCommission'] ?? false),
            'hotelRunnerAutoPriceImport' => (bool) ($calendarHome['HotelRunnerAutoPriceImport'] ?? false),
            'autoPriceImport' => (bool) ($calendarHome['AutoPriceImport'] ?? false),
            'autoPriceImportColumnLastUpdate' => $this->firstNonEmptyValue($calendarHome, ['AutoPriceImportColumnLastUpdate']),
            'autoDiscountImport' => (bool) ($calendarHome['AutoDiscountImport'] ?? false),
            'autoDiscountImportColumnLastUpdate' => $this->firstNonEmptyValue($calendarHome, ['AutoDiscountImportColumnLastUpdate']),
            'lastPriceCallback' => $this->firstNonEmptyValue($calendarHome, ['LastPriceCallback']),
            'lastPriceUpdated' => $this->firstNonEmptyValue($calendarHome, ['LastPriceUpdated']),
        ];
    }

    private function siteDbTableSuffix(PDO $pdo, int $siteId): string
    {
        foreach ($this->fetchSites($pdo) as $site) {
            if ((int) ($site['id'] ?? 0) === $siteId) {
                return $this->safeDbTableSuffix((string) ($site['dbtable'] ?? ''));
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $home
     * @return array<string,string>
     */
    private function sabitEkstraUcretler(array $home, int $siteId): array
    {
        $suffix = $this->configuredSiteSuffix($siteId);

        return [
            'hasarDepozitosu' => $this->firstNonEmptyValue($home, ['hasar' . $suffix, 'hasar']),
            'temizlik' => $this->firstNonEmptyValue($home, ['temizlik' . $suffix, 'temizlik']),
            'elektrikSu' => $this->firstNonEmptyValue($home, ['elektrik' . $suffix, 'elektrik']),
        ];
    }

    private function configuredSiteSuffix(int $siteId): string
    {
        $suffixes = $this->app['site_column_suffixes'] ?? [];
        $suffix = is_array($suffixes) && array_key_exists($siteId, $suffixes)
            ? (string) $suffixes[$siteId]
            : '';

        return $this->safeDbTableSuffix($suffix);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function ekstraUcretler(PDO $pdo, int $homeId): array
    {
        if (!$this->tableExists($pdo, 'dbo', 'HomesExtraPaymentPrices')) {
            return [];
        }

        $rows = $this->fetchAll(
            $pdo,
            "SELECT ep.*,
                    ept.Code AS type_code,
                    ept.Name AS type_name,
                    ept.HomeColumnBase AS home_column_base,
                    CONVERT(varchar(10), ep.StartDate, 103) AS start_date_formatted,
                    CONVERT(varchar(10), ep.EndDate, 103) AS end_date_formatted
             FROM dbo.HomesExtraPaymentPrices ep
             INNER JOIN dbo.HomesExtraPaymentTypes ept ON ept.ExtraPaymentTypeId = ep.ExtraPaymentTypeId
             WHERE ep.HomesId = :homeId
               AND ep.IsDeleted = 0
               AND ept.IsDeleted = 0
             ORDER BY ep.StartDate ASC, ep.ExtraPaymentPriceId ASC",
            [':homeId' => $homeId]
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) ($row['ExtraPaymentPriceId'] ?? 0),
                'season_id' => (int) ($row['SeasonId'] ?? 0),
                'start_date' => $this->firstNonEmptyValue($row, ['start_date_formatted', 'StartDate']),
                'end_date' => $this->firstNonEmptyValue($row, ['end_date_formatted', 'EndDate']),
                'type_id' => (int) ($row['ExtraPaymentTypeId'] ?? 0),
                'type_code' => $this->firstNonEmptyValue($row, ['type_code']),
                'title' => $this->firstNonEmptyValue($row, ['type_name']),
                'home_column_base' => $this->firstNonEmptyValue($row, ['home_column_base']),
                'currency_id' => (int) ($row['CurrencyId'] ?? 0),
                'fiyat_tipi' => $this->firstNonEmptyValue($row, ['PriceType']),
                'amount' => $this->firstNonEmptyValue($row, ['Value']),
                'description' => $this->firstNonEmptyValue($row, ['Description']),
            ];
        }

        return $items;
    }

    private function tableExists(PDO $pdo, string $schema, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table'
        );
        $stmt->execute([
            ':schema' => $schema,
            ':table' => $table,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function indirimler(PDO $pdo, int $homeId, int $siteId): array
    {
        $rows = $this->fetchAll(
            $pdo,
            "SELECT *,
                    REPLACE(CONVERT(varchar, showdate1, 104), '.', '/') AS showdate1_formatted,
                    REPLACE(CONVERT(varchar, showdate2, 104), '.', '/') AS showdate2_formatted,
                    REPLACE(CONVERT(varchar, tarih1, 104), '.', '/') AS tarih1_formatted,
                    REPLACE(CONVERT(varchar, tarih2, 104), '.', '/') AS tarih2_formatted
             FROM indirimler
             WHERE site = :site
               AND emlak = :homeId
             ORDER BY CONVERT(date, tarih1, 104) ASC",
            [
                ':site' => $siteId,
                ':homeId' => $homeId,
            ]
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'tarih1' => $this->firstNonEmptyValue($row, ['tarih1_formatted', 'tarih1']),
                'tarih2' => $this->firstNonEmptyValue($row, ['tarih2_formatted', 'tarih2']),
                'showdate1' => $this->firstNonEmptyValue($row, ['showdate1_formatted', 'showdate1']),
                'showdate2' => $this->firstNonEmptyValue($row, ['showdate2_formatted', 'showdate2']),
                'oran' => $this->firstNonEmptyValue($row, ['oran']),
            ];
        }

        return $items;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function havuzlar(PDO $pdo, int $homeId): array
    {
        $rows = $this->fetchAll(
            $pdo,
            "SELECT
                ht.id,
                ht.homesId,
                ht.tipId,
                htip.baslik AS tipBaslik,
                ht.deger,
                ht.havuzTipiId,
                ht.uzunluk,
                ht.genislik,
                ht.derinlik,
                ht.tamKorunakli
             FROM dbo.havuztanimlamari ht
             INNER JOIN dbo.havuztipitanimlari htip ON htip.id = ht.tipId
             WHERE ht.homesId = :homeId
             ORDER BY ht.tipId ASC",
            [':homeId' => $homeId]
        );

        $pools = [];
        foreach ($rows as $row) {
            $havuzTipiId = isset($row['havuzTipiId']) && $row['havuzTipiId'] !== null
                ? (int) $row['havuzTipiId']
                : null;

            $pools[] = [
                'id' => (int) ($row['id'] ?? 0),
                'homesId' => (int) ($row['homesId'] ?? 0),
                'tipId' => (int) ($row['tipId'] ?? 0),
                'tip' => $this->firstNonEmptyValue($row, ['tipBaslik']),
                'deger' => $this->firstNonEmptyValue($row, ['deger']),
                'havuzTipiId' => $havuzTipiId,
                'havuzTipi' => $havuzTipiId,
                'uzunluk' => $this->firstNonEmptyValue($row, ['uzunluk']),
                'genislik' => $this->firstNonEmptyValue($row, ['genislik']),
                'derinlik' => $this->firstNonEmptyValue($row, ['derinlik']),
                'tamKorunakli' => $this->firstNonEmptyValue($row, ['tamKorunakli']),
            ];
        }

        return $pools;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function odalar(PDO $pdo, int $id): array
    {
        $yatakTipleri = $this->fetchAll(
            $pdo,
            'SELECT id, baslik, degerturu, degerleri, yatakmi FROM yatak_tipleri ORDER BY yatakmi ASC'
        );
        $rows = $this->fetchAll(
            $pdo,
            "SELECT DISTINCT
                ov.i,
                ov.odalarId,
                ISNULL(o.baslik, '') AS tip,
                deg = STUFF((
                    SELECT '=', CONVERT(varchar, yatak_tipleriId) + ';/' + ISNULL(deger, '')
                    FROM odalarValues
                    WHERE odalarId = ov.odalarId AND homesId = :idInner AND i = ov.i
                    FOR XML PATH('')
                ), 1, 1, '')
             FROM odalarValues ov
             LEFT JOIN odalar o ON o.id = ov.odalarId
             WHERE ov.homesId = :idOuter",
            [
                ':idInner' => $id,
                ':idOuter' => $id,
            ]
        );

        $satirlar = [];
        $say = 0;
        foreach ($rows as $row) {
            $say++;
            $values = $this->parseYatakDegerleri((string) ($row['deg'] ?? ''));
            $yataklar = [];
            foreach ($yatakTipleri as $yatakTipi) {
                $yatakId = (int) ($yatakTipi['id'] ?? 0);
                if ($yatakId <= 0) {
                    continue;
                }

                $deger = $values[$yatakId] ?? '';
                $yataklar[] = [
                    'id' => $yatakId,
                    'name' => $yatakTipi['baslik'] ?? '',
                    'value' => $deger,
                ];
            }

            $satirlar[] = [
                'sira' => $say,
                'i' => (int) ($row['i'] ?? 0),
                'odalarId' => (int) ($row['odalarId'] ?? 0),
                'odaAdi' => $row['tip'] ?? '',
                'select_name' => 'odalar_y_' . $say,
                'yataklar' => $yataklar,
            ];
        }

        return $satirlar;
    }

    /**
     * @return array<int,string>
     */
    private function parseYatakDegerleri(string $deg): array
    {
        $values = [];
        foreach (explode('=', $deg) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $pieces = explode(';/', $part, 2);
            if (count($pieces) !== 2 || !is_numeric(trim($pieces[0]))) {
                continue;
            }

            $values[(int) trim($pieces[0])] = (string) $pieces[1];
        }

        return $values;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function mesafeler(array $rows): array
    {
        return array_map(function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'mesafeler_id' => (int) ($row['mesafelerId'] ?? $row['meid'] ?? 0),
                'tip' => $this->firstValueFrom($row, ['baslik']),
                'mesafe' => $this->firstValueFrom($row, ['mesafe', 'deger', 'value']),
                'birim' => $this->firstValueFrom($row, ['birim', 'unit']),
                'aciklama' => $this->firstValueFrom($row, ['aciklama']),
            ];
        }, $rows);
    }

    /**
     * @return array{il:string,ilce:string,mahalle:string}
     */
    private function konumHiyerarsi(PDO $pdo, int $destinationId, string $fallbackMahalle): array
    {
        $konum = [
            'il' => '',
            'ilce' => '',
            'mahalle' => $fallbackMahalle,
        ];

        if ($destinationId <= 0) {
            return $konum;
        }

        $row = $this->fetchOne(
            $pdo,
            "SELECT
                d.baslik AS mahalle,
                p.baslik AS ilce,
                gp.baslik AS il
             FROM destinations d
             LEFT JOIN destinations p ON p.id = d.cat
             LEFT JOIN destinations gp ON gp.id = p.cat
             WHERE d.id = :id",
            [':id' => $destinationId]
        );

        if (!is_array($row)) {
            return $konum;
        }

        return [
            'il' => $this->firstValueFrom($row, ['il']),
            'ilce' => $this->firstValueFrom($row, ['ilce']),
            'mahalle' => $this->firstValueFrom($row, ['mahalle']),
        ];
    }

    /**
     * @param array<string,mixed> $rs
     * @return array<int,array<string,mixed>>
     */
    private function konumNotlari(PDO $pdo, array $rs): array
    {
        $items = [];
        foreach ($this->fetchSites($pdo) as $site) {
            $siteId = (int) ($site['id'] ?? 0);
            if ($siteId <= 0) {
                continue;
            }

            $suffix = $this->safeDbTableSuffix((string) ($site['dbtable'] ?? ''));
            $column = 'konum_not' . $suffix;
            $note = $this->firstNonEmptyValue($rs, [$column]);
            if ($note === '') {
                continue;
            }

            $items[] = [
                'site_id' => $siteId,
                'site' => $site['site'] ?? '',
                'kisa' => $site['kisa'] ?? '',
                'dbtable' => $suffix,
                'name' => $column,
                'not' => $note,
            ];
        }

        return $items;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchSites(PDO $pdo): array
    {
        try {
            $sites = $this->fetchAll(
                $pdo,
                'SELECT id, site, kisa, dbtable FROM sites ORDER BY id ASC'
            );
            if ($sites) {
                return $sites;
            }
        } catch (\PDOException $e) {
        }

        global $SITES;
        if (isset($SITES) && is_array($SITES)) {
            $sites = [];
            foreach ($SITES as $id => $site) {
                if (!is_array($site)) {
                    continue;
                }

                $sites[] = [
                    'id' => (int) $id,
                    'site' => $site['site'] ?? ('Site ' . (int) $id),
                    'kisa' => $site['kisa'] ?? '',
                    'dbtable' => $site['dbtable'] ?? '',
                ];
            }

            if ($sites) {
                return $sites;
            }
        }

        return [[
            'id' => 1,
            'site' => 'Site 1',
            'kisa' => '',
            'dbtable' => '',
        ]];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $home
     * @return array<int,array<string,mixed>>
     */
    private function resimler(array $rows, array $home): array
    {
        $cdnBase = (defined('Cdn') ? (string) constant('Cdn') : '') . '/uploads/small/';
        $kapakMap = $this->selectedStringMap($this->firstNonEmptyValue($home, ['resim']), ',');
        $images = [];

        foreach ($rows as $row) {
            $filename = trim((string) ($row['filename'] ?? ''));
            if ($filename === '') {
                continue;
            }

            $images[] = [
                'id' => (int) ($row['UploadId'] ?? $row['uploadID'] ?? $row['uploadId'] ?? 0),
                'fileName' => $filename,
                'url' => $cdnBase . $filename,
                'aciklama' => $this->firstNonEmptyValue($row, ['aciklama']),
                'kapak' => isset($kapakMap[$filename]),
            ];
        }

        return $images;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $keys
     * @return array<int,string>
     */
    private function filledValues(array $row, array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            $value = isset($row[$key]) ? trim((string) $row[$key]) : '';
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    private function currencyCode($value): string
    {
        $currency = strtolower(trim((string) $value));

        return match ($currency) {
            'tl', 'try' => 'TRY',
            'dolar', 'usd' => 'USD',
            'euro', 'eur' => 'EUR',
            'pound', 'gbp' => 'GBP',
            default => $currency !== '' ? strtoupper($currency) : '',
        };
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function withoutS2Fields(array $row): array
    {
        return array_filter(
            $row,
            static fn (string $key): bool => !str_ends_with($key, '_s2'),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $keys
     */
    private function firstValueFrom(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                return (string) $row[$key];
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $keys
     */
    private function firstNonEmptyValue(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                return (string) $row[$key];
            }
        }

        return '';
    }

    /**
     * @return array<int,bool>
     */
    private function selectedIdMap(string $value, string $delimiter): array
    {
        $map = [];
        foreach (explode($delimiter, $value) as $part) {
            $part = trim($part);
            if ($part === '' || !is_numeric($part)) {
                continue;
            }

            $id = (int) $part;
            if ($id > 0) {
                $map[$id] = true;
            }
        }

        return $map;
    }

    /**
     * @return array<string,bool>
     */
    private function selectedStringMap(string $value, string $delimiter): array
    {
        $map = [];
        foreach (explode($delimiter, $value) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $map[$part] = true;
        }

        return $map;
    }

    /**
     * @param array<string,mixed> $owner
     * @param array<string,mixed> $home
     */
    private function ownerFullName(array $owner, array $home): string
    {
        $name = trim((string) ($owner['ad'] ?? $home['evsadi'] ?? '') . ' ' . (string) ($owner['soyad'] ?? $home['evssoyadi'] ?? ''));

        return $name !== '' ? $name : '';
    }

    /**
     * @param array<int,array<string,mixed>> $parents
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function fetchTipAltKategoriler(PDO $pdo, array $parents): array
    {
        $rows = [];
        foreach ($parents as $parent) {
            $cat = (int) ($parent['id'] ?? 0);
            if ($cat <= 0) {
                continue;
            }

            $rows[$cat] = $this->fetchAll(
                $pdo,
                'SELECT id, baslik FROM tip WHERE cat = :cat AND aktif = 1 ORDER BY baslik ASC',
                [':cat' => $cat]
            );
        }

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $parents
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function fetchDestinationsHierarchy(PDO $pdo, array $parents): array
    {
        $rows = [];
        foreach ($parents as $parent) {
            $cat = (int) ($parent['id'] ?? 0);
            if ($cat <= 0) {
                continue;
            }

            $children = $this->fetchAll(
                $pdo,
                'SELECT id, baslik FROM destinations WHERE cat = :cat AND aktif = 1 ORDER BY baslik ASC',
                [':cat' => $cat]
            );

            $rows[$cat] = [];
            foreach ($children as $child) {
                $childId = (int) ($child['id'] ?? 0);
                if ($childId <= 0) {
                    continue;
                }

                $rows[$cat][] = [
                    'd2' => ['baslik' => $child['baslik'] ?? ''],
                    'd3' => $this->fetchAll(
                        $pdo,
                        'SELECT baslik FROM destinations WHERE cat = :cat AND aktif = 1 ORDER BY baslik ASC',
                        [':cat' => $childId]
                    ),
                ];
            }
        }

        return $rows;
    }

    private function safeDbTableSuffix(string $suffix): string
    {
        if ($suffix === '' || preg_match('/^_[A-Za-z0-9]+$/', $suffix) === 1) {
            return $suffix;
        }

        return '';
    }
}
