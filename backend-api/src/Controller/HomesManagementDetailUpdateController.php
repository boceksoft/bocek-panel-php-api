<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Homes management detail update resource.
 * Detail sayfasindan query string veya JSON body ile gelen alanlari gunceller.
 */
final class HomesManagementDetailUpdateController extends Controller
{
    /** test
     * Detay sayfasindan gelen emlak bilgilerini gunceller.
     *
     * @Post
     * @Put
     * @query id int required Emlak ID
     * @query villaadi string Villa adi
     * @query emlaktipi int Emlak tipi ID
     * @query kategori string Virgulle ayrilmis kategori ID listesi
     * @query ozellikler string Virgulle ayrilmis ozellik ID listesi
     * @body id int required Emlak ID
     */
    public function index(): void
    {
        $payload = $this->payload();
        $id = $this->resolveId($payload);

        if ($id <= 0) {
            throw new HttpException('Lutfen gecerli bir emlak ID gonderin.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();
        $this->assertHomeExists($pdo, $id);

        $updates = $this->homesUpdates($payload);
        $mesafeler = $this->mesafelerPayload($payload);
        $hasBakimciContactPayload = $this->hasBakimciContactPayload($payload);
        if ($updates === [] && $mesafeler === [] && !$hasBakimciContactPayload) {
            throw new HttpException('Guncellenecek alan bulunamadi.', 'VALIDATION', 422);
        }

        $pdo->beginTransaction();
        try {
            $updateResult = $updates !== []
                ? $this->updateHomes($pdo, $id, $updates)
                : ['updated_columns' => [], 'skipped_columns' => []];
            $mesafelerResult = $this->updateMesafeler($pdo, $id, $mesafeler);
            $bakimciAssignmentResult = $this->syncBakimciAssignment($pdo, $id, $updates);
            $bakimciResult = $this->updateBakimciContact($pdo, $id, $payload);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw new HttpException('Emlak detay bilgileri guncellenemedi.', 'DB_UPDATE_FAILED', 500, $e);
        }

        $this->response->success([
            'id' => $id,
            'updated' => count($updateResult['updated_columns']) > 0
                || $mesafelerResult['updated'] > 0
                || $bakimciAssignmentResult['updated'] > 0
                || $bakimciResult['updated'] > 0,
            'updated_columns' => $updateResult['updated_columns'],
            'skipped_columns' => $updateResult['skipped_columns'],
            'updated_related_sections' => [
                'mesafeler' => $mesafelerResult['updated'],
                'bakimci_assignment' => $bakimciAssignmentResult['updated'],
                'bakimci' => $bakimciResult['updated'],
            ],
            'skipped_related_rows' => array_merge(
                $mesafelerResult['skipped'],
                $bakimciAssignmentResult['skipped'],
                $bakimciResult['skipped']
            ),
            'skipped_related_sections' => $this->skippedRelatedSections($payload),
        ]);
    }

    /**
     * Query parametreleri JSON body'nin uzerine yazar.
     *
     * @return array<string,mixed>
     */
    private function payload(): array
    {
        return array_merge($this->request->json(), $_GET);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function resolveId(array $payload): int
    {
        $id = $payload['id'] ?? $this->request->query('id', 0);

        return is_numeric($id) ? (int) $id : 0;
    }

    private function assertHomeExists(PDO $pdo, int $id): void
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM homes WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        if ((int) $stmt->fetchColumn() === 0) {
            throw new HttpException('Belirtilen ID ile emlak bulunamadi.', 'NOT_FOUND', 404);
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function homesUpdates(array $payload): array
    {
        $updates = [];
        $map = [
            'villaadi' => 'baslik',
            'kisaaciklama' => 'kisa_icerik',
            'emlaktipi' => 'emlak_tipi',
            'kapasite' => 'kisi',
            'yatakodasi' => 'yatak_odasi',
            'yataksayisi' => 'yatak_sayisi',
            'banyo' => 'banyo',
            'girissaati' => 'giris_saat',
            'cikissaati' => 'cikis_saat',
            'iptalsarti' => 'ozel_sartlar',
            'iptalpolitikasi' => 'iptal_politikasi',
            'bakimciadi' => 'bakimciad',
            'bakimcitel' => 'bakimcitel',
            'evsahibi' => 'evsahibi',
            'whatsappgrupadi' => 'whatsapp_grup',
            'yetkilifirma' => 'yetkilifirma',
            'ilannotlari' => 'not2',
            'icerik' => 'icerik',
            'parabirimi' => 'doviz',
            'depozitoorani' => 'depozito',
            'komisyonorani' => 'kazancorani',
            'onodemeyontemi' => 'FirstPaymentTypeId',
            'bolge' => 'emlak_bolgesi',
            'n_bolge' => 'n_emlak_bolgesi',
            'n_emlak_bolgesi' => 'n_emlak_bolgesi',
            'enlem' => 'enlem',
            'boylam' => 'boylam',
            'mesafetipi' => 'mesafe_cetvelitipi',
            'metabaslik' => 'title',
            'metakeywords' => 'keywords',
            'metaaciklama' => 'description',
            'slug' => 'url',
            'canonical' => 'canonical',
            'genelBilgiler.temelBilgiler.villa_adi' => 'baslik',
            'genelBilgiler.temelBilgiler.kisa_aciklama' => 'kisa_icerik',
            'genelBilgiler.temelBilgiler.emlak_tipi_id' => 'emlak_tipi',
            'genelBilgiler.villaDetaylari.kapasite' => 'kisi',
            'genelBilgiler.villaDetaylari.yatak_odasi_sayisi' => 'yatak_odasi',
            'genelBilgiler.villaDetaylari.yatak_sayisi' => 'yatak_sayisi',
            'genelBilgiler.villaDetaylari.banyo_sayisi' => 'banyo',
            'genelBilgiler.konaklamaKurallari.giris_saati' => 'giris_saat',
            'genelBilgiler.konaklamaKurallari.cikis_saati' => 'cikis_saat',
            'genelBilgiler.konaklamaKurallari.iptal_sarti' => 'ozel_sartlar',
            'genelBilgiler.iletisimBilgileri.bakimciBilgisi.ad_soyad' => 'bakimciad',
            'genelBilgiler.iletisimBilgileri.bakimciBilgisi.telefon' => 'bakimcitel',
            'genelBilgiler.iletisimBilgileri.evSahibiBilgisi.id' => 'evsahibi',
            'genelBilgiler.iletisimBilgileri.whatsapp_grup_adi' => 'whatsapp_grup',
            'genelBilgiler.iletisimBilgileri.yetkili_firma_adi' => 'yetkilifirma',
            'genelBilgiler.yoneticiBilgileri.ilan_notlari' => 'not2',
            'genelBilgiler.detayliAciklama.icerik' => 'icerik',
            'fiyatlandirmaVeKurallar.para_birimi' => 'doviz',
            'fiyatlandirmaVeKurallar.odemeAyarlari.depozito.oran' => 'depozito',
            'fiyatlandirmaVeKurallar.odemeAyarlari.komisyonOrani' => 'kazancorani',
            'fiyatlandirmaVeKurallar.odemeAyarlari.onOdemeYontemiId' => 'FirstPaymentTypeId',
            'konumBilgileri.mahalle_id' => 'emlak_bolgesi',
            'konumBilgileri.n_mahalle_id' => 'n_emlak_bolgesi',
            'konumBilgileri.n_emlak_bolgesi' => 'n_emlak_bolgesi',
            'konumBilgileri.enlem' => 'enlem',
            'konumBilgileri.boylam' => 'boylam',
            'konumBilgileri.mesafeHesaplamaTipi' => 'mesafe_cetvelitipi',
            'seoBilgileri.metaBaslik' => 'title',
            'seoBilgileri.metaAnahtarKelimeler' => 'keywords',
            'seoBilgileri.metaAciklama' => 'description',
            'seoBilgileri.slug' => 'url',
            'seoBilgileri.canonical' => 'canonical',
        ];

        foreach ($map as $path => $column) {
            if (!$this->hasPath($payload, $path)) {
                continue;
            }

            $value = $this->normalizeScalar($this->getPath($payload, $path));
            $updates[$column] = $column === 'doviz' ? $this->currencyDbValue($value) : $value;
        }

        foreach ([
                     'bolge' => 'emlak_bolgesi',
                     'n_bolge' => 'n_emlak_bolgesi',
                     'n_emlak_bolgesi' => 'n_emlak_bolgesi',
                 ] as $path => $column) {
            if (!$this->hasPath($payload, $path)) {
                continue;
            }

            $updates[$column] = $this->normalizeScalar($this->getPath($payload, $path));
        }

        if (array_key_exists('emlak_bolgesi', $updates) && !array_key_exists('n_emlak_bolgesi', $updates)) {
            $updates['n_emlak_bolgesi'] = $updates['emlak_bolgesi'];
        }

        foreach ($payload as $key => $value) {
            if (
                !is_string($key)
                || (
                    preg_match('/^iptal_politikasi(?:_[A-Za-z0-9]+)?$/', $key) !== 1
                    && preg_match('/^(?:hasar|temizlik|elektrik)_[A-Za-z0-9]+$/', $key) !== 1
                )
            ) {
                continue;
            }

            $updates[$key] = $this->normalizeScalar($value);
        }

        $this->addSiteExtraFeeUpdates($updates, $payload);

        $this->addListUpdate($updates, $payload, 'kategori', 'kategori', ',');
        $this->addListUpdate($updates, $payload, 'ozellikler', 'ozellikler', '#');
        $this->addListUpdate($updates, $payload, 'onecikan', 'onecikan', ',');
        $this->addListUpdate($updates, $payload, 'kurallar', 'kurallar', ',');
        $this->addListUpdate($updates, $payload, 'dahilhizmetler', 'fiyata_dahil', '#');
        $this->addListUpdate($updates, $payload, 'genelBilgiler.temelBilgiler.kategori_ids', 'kategori', ',');
        $this->addListUpdate($updates, $payload, 'ozelliklerVeOlanaklar.seciliOzellikIds', 'ozellikler', '#');
        $this->addListUpdate($updates, $payload, 'ozelliklerVeOlanaklar.oneCikanOzellikIds', 'onecikan', ',');
        $this->addListUpdate($updates, $payload, 'ozelliklerVeOlanaklar.evKurallariIds', 'kurallar', ',');
        $this->addListUpdate($updates, $payload, 'ozelliklerVeOlanaklar.dahilHizmetIds', 'fiyata_dahil', '#');

        if ($this->hasPath($payload, 'etiketler')) {
            $labels = $this->normalizeStringList($this->getPath($payload, 'etiketler'));
            $updates['ribbon'] = isset($labels[0]) ? $labels[0] : '';
            $updates['ribbon2'] = isset($labels[1]) ? $labels[1] : '';
        }

        if ($this->hasPath($payload, 'ozelliklerVeOlanaklar.oneCikanEtiketler')) {
            $labels = $this->getPath($payload, 'ozelliklerVeOlanaklar.oneCikanEtiketler');
            if (is_array($labels)) {
                $updates['ribbon'] = isset($labels[0]) ? $this->normalizeScalar($labels[0]) : '';
                $updates['ribbon2'] = isset($labels[1]) ? $this->normalizeScalar($labels[1]) : '';
            }
        }

        foreach (['ribbon', 'ribbon2'] as $column) {
            if ($this->hasPath($payload, $column)) {
                $updates[$column] = $this->normalizeScalar($this->getPath($payload, $column));
            }
        }

        if ($this->hasPath($payload, 'videolar')) {
            $updates['video'] = implode(',', $this->normalizeStringList($this->getPath($payload, 'videolar')));
        }

        if ($this->hasPath($payload, 'medyaBilgileri.videolar')) {
            $videos = $this->getPath($payload, 'medyaBilgileri.videolar');
            if (is_array($videos)) {
                $updates['video'] = implode(',', array_values(array_filter(array_map([$this, 'normalizeScalar'], $videos))));
            }
        }

        if ($this->hasPath($payload, 'kapakresimler')) {
            $updates['resim'] = implode(',', $this->normalizeImageNames($this->getPath($payload, 'kapakresimler')));
        }

        if ($this->hasPath($payload, 'medyaBilgileri.resimler')) {
            $coverImages = $this->coverImageNames($this->getPath($payload, 'medyaBilgileri.resimler'));
            if ($coverImages !== null) {
                $updates['resim'] = implode(',', $coverImages);
            }
        }

        return $updates;
    }

    /**
     * @param array<string,mixed> $updates
     * @param array<string,mixed> $payload
     */
    private function addSiteExtraFeeUpdates(array &$updates, array $payload): void
    {
        $suffix = $this->configuredSiteSuffix($this->siteId($payload));
        $fields = [
            'hasar' => [
                'hasar',
                'hasarDepozitosu',
                'fiyatlandirmaVeKurallar.sabitEkstraUcretler.hasarDepozitosu',
                'fiyatlandirmaVeKurallar.ekstraUcretler.hasarDepozitosu',
            ],
            'temizlik' => [
                'temizlik',
                'fiyatlandirmaVeKurallar.sabitEkstraUcretler.temizlik',
                'fiyatlandirmaVeKurallar.ekstraUcretler.temizlik',
            ],
            'elektrik' => [
                'elektrik',
                'elektrikSu',
                'fiyatlandirmaVeKurallar.sabitEkstraUcretler.elektrikSu',
                'fiyatlandirmaVeKurallar.ekstraUcretler.elektrikSu',
            ],
        ];

        foreach ($fields as $columnBase => $paths) {
            foreach ($paths as $path) {
                if (!$this->hasPath($payload, $path)) {
                    continue;
                }

                $updates[$columnBase . $suffix] = $this->normalizeScalar($this->getPath($payload, $path));
                break;
            }
        }
    }

    /**
     * @param array<string,mixed> $updates
     * @param array<string,mixed> $payload
     */
    private function addListUpdate(array &$updates, array $payload, string $path, string $column, string $delimiter): void
    {
        if (!$this->hasPath($payload, $path)) {
            return;
        }

        $value = $this->getPath($payload, $path);
        $value = $this->normalizeListValue($value);

        $ids = [];
        foreach ($value as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[] = (string) (int) $id;
            }
        }

        $updates[$column] = implode($delimiter, array_values(array_unique($ids)));
    }

    /**
     * @param mixed $value
     * @return array<int,mixed>
     */
    private function normalizeListValue($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }

        if (strpos($value, '#') !== false) {
            return explode('#', $value);
        }

        return explode(',', $value);
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private function normalizeStringList($value): array
    {
        $items = $this->normalizeListValue($value);
        $strings = [];

        foreach ($items as $item) {
            $item = $this->normalizeScalar($item);
            if ($item !== '') {
                $strings[] = (string) $item;
            }
        }

        return $strings;
    }

    /**
     * @param mixed $images
     * @return array<int,string>|null
     */
    private function coverImageNames($images)
    {
        if (!is_array($images)) {
            return null;
        }

        $covers = [];
        foreach ($images as $image) {
            if (!is_array($image) || empty($image['kapak'])) {
                continue;
            }

            $filename = $this->imageName($image['fileName'] ?? $image['filename'] ?? $image['url'] ?? '');
            if ($filename !== '') {
                $covers[] = $filename;
            }
        }

        return $covers;
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private function normalizeImageNames($value): array
    {
        $names = [];
        foreach ($this->normalizeStringList($value) as $item) {
            $name = $this->imageName($item);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param mixed $value
     */
    private function imageName($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $path = parse_url($value, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $value = $path;
        }

        $value = str_replace('\\', '/', $value);

        return basename($value);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{updated:int,skipped:array<int,array<string,string>>}
     */
    private function updateBakimciContact(PDO $pdo, int $homeId, array $payload): array
    {
        $addressValue = $this->firstExistingPath($payload, [
            'bakimciAdres',
            'bakimciadres',
            'address',
            'adres',
            'genelBilgiler.iletisimBilgileri.bakimciBilgisi.adres',
            'genelBilgiler.iletisimBilgileri.bakimciBilgisi.address',
        ]);
        $emailValue = $this->firstExistingPath($payload, [
            'bakimciEmail',
            'bakimciemail',
            'email',
            'eposta',
            'genelBilgiler.iletisimBilgileri.bakimciBilgisi.eposta',
            'genelBilgiler.iletisimBilgileri.bakimciBilgisi.email',
        ]);

        if (!$addressValue['exists'] && !$emailValue['exists']) {
            return ['updated' => 0, 'skipped' => []];
        }

        $skipped = [];
        if (!$this->tableExists($pdo, 'dbo', 'bakimcilar')) {
            return [
                'updated' => 0,
                'skipped' => [[
                    'section' => 'bakimci',
                    'reason' => 'bakimcilar tablosu bulunamadi.',
                ]],
            ];
        }

        $home = $this->fetchHomeCaretaker($pdo, $homeId);
        $phone = $this->normalizePhone($this->firstNonEmptyPayloadValue($payload, [
            'bakimcitel',
            'genelBilgiler.iletisimBilgileri.bakimciBilgisi.telefon',
        ], $home['bakimcitel'] ?? ''));

        if ($phone === '') {
            return [
                'updated' => 0,
                'skipped' => [[
                    'section' => 'bakimci',
                    'reason' => 'Bakimci telefonu bulunamadigi icin adres/e-posta eslestirilemedi.',
                ]],
            ];
        }

        $set = [];
        $params = [
            ':homeId' => $homeId,
            ':phone' => $phone,
        ];

        if ($addressValue['exists']) {
            $set[] = 'bakimciAdres = :bakimciAdres';
            $params[':bakimciAdres'] = $this->normalizeScalar($addressValue['value']);
        }

        if ($emailValue['exists']) {
            $set[] = 'bakimciEmail = :bakimciEmail';
            $params[':bakimciEmail'] = $this->normalizeScalar($emailValue['value']);
        }

        if ($set === []) {
            return ['updated' => 0, 'skipped' => $skipped];
        }

        $stmt = $pdo->prepare(
            ";WITH MatchedBakimci AS
             (
                SELECT TOP 1 id
                FROM dbo.bakimcilar
                CROSS APPLY
                (
                    SELECT NULLIF(
                        REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                            LTRIM(RTRIM(CONVERT(varchar(50), bakimcitel))),
                            '+', ''), ' ', ''), '(', ''), ')', ''), '-', ''), '.', ''), '/', ''), ',', ''), CHAR(9), ''), CHAR(10), ''), CHAR(13), ''),
                        ''
                    ) AS cleanTel
                ) p
                CROSS APPLY
                (
                    SELECT NULLIF(
                        CASE
                            WHEN LEFT(p.cleanTel, 4) = '0090' AND LEN(p.cleanTel) = 14 THEN '90' + SUBSTRING(p.cleanTel, 5, 10)
                            WHEN LEFT(p.cleanTel, 2) = '90' AND LEN(p.cleanTel) = 12 THEN p.cleanTel
                            WHEN LEFT(p.cleanTel, 1) = '0' AND LEN(p.cleanTel) = 11 THEN '90' + SUBSTRING(p.cleanTel, 2, 10)
                            WHEN LEN(p.cleanTel) = 10 THEN '90' + p.cleanTel
                            ELSE p.cleanTel
                        END,
                        ''
                    ) AS normalizedTel
                ) n
                WHERE homesId = :homeId
                  AND n.normalizedTel = :phone
                ORDER BY id ASC
             )
             UPDATE b
             SET " . implode(', ', $set) . "
             FROM dbo.bakimcilar b
             INNER JOIN MatchedBakimci m ON m.id = b.id"
        );

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            $skipped[] = [
                'section' => 'bakimci',
                'reason' => 'homesId ve telefon ile eslesen bakimci bulunamadi.',
            ];
        }

        return ['updated' => $stmt->rowCount(), 'skipped' => $skipped];
    }

    /**
     * @return array<string,string>
     */
    private function fetchHomeCaretaker(PDO $pdo, int $homeId): array
    {
        $stmt = $pdo->prepare('SELECT bakimciad, bakimcitel FROM homes WHERE id = :id');
        $stmt->bindValue(':id', $homeId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [];
        }

        return [
            'bakimciad' => (string) ($row['bakimciad'] ?? ''),
            'bakimcitel' => (string) ($row['bakimcitel'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $updates
     * @return array{updated:int,skipped:array<int,array<string,string>>}
     */
    private function syncBakimciAssignment(PDO $pdo, int $homeId, array $updates): array
    {
        if (!array_key_exists('bakimciad', $updates) && !array_key_exists('bakimcitel', $updates)) {
            return ['updated' => 0, 'skipped' => []];
        }

        if (!$this->tableExists($pdo, 'dbo', 'bakimcilar')) {
            return [
                'updated' => 0,
                'skipped' => [[
                    'section' => 'bakimci_assignment',
                    'reason' => 'bakimcilar tablosu bulunamadi.',
                ]],
            ];
        }

        $home = $this->fetchHomeCaretaker($pdo, $homeId);
        $name = $this->cleanBakimciName((string) ($home['bakimciad'] ?? ''));
        $phone = $this->normalizePhone((string) ($home['bakimcitel'] ?? ''));

        if ($name === '' || !$this->hasLetter($name) || $phone === '') {
            return [
                'updated' => 0,
                'skipped' => [[
                    'section' => 'bakimci_assignment',
                    'reason' => 'Bakimci adi veya telefonu uygun olmadigi icin bakimcilar eslestirilemedi.',
                ]],
            ];
        }

        $caretakerId = $this->findBakimciIdByPhone($pdo, $phone);
        if ($caretakerId > 0) {
            $this->clearOtherHomeCaretakers($pdo, $homeId, $caretakerId);

            $stmt = $pdo->prepare(
                'UPDATE dbo.bakimcilar
                 SET homesId = :homesId,
                     bakimciAdi = :bakimciAdi,
                     bakimcitel = :bakimcitel
                 WHERE id = :id'
            );
            $stmt->execute([
                ':homesId' => $homeId,
                ':bakimciAdi' => $name,
                ':bakimcitel' => $phone,
                ':id' => $caretakerId,
            ]);

            return ['updated' => 1, 'skipped' => []];
        }

        $this->clearOtherHomeCaretakers($pdo, $homeId);

        $stmt = $pdo->prepare(
            'INSERT INTO dbo.bakimcilar (homesId, bakimciAdi, bakimcitel)
             VALUES (:homesId, :bakimciAdi, :bakimcitel)'
        );
        $stmt->execute([
            ':homesId' => $homeId,
            ':bakimciAdi' => $name,
            ':bakimcitel' => $phone,
        ]);

        return ['updated' => 1, 'skipped' => []];
    }

    private function findBakimciIdByPhone(PDO $pdo, string $phone): int
    {
        $stmt = $pdo->prepare(
            "SELECT TOP 1 id
             FROM dbo.bakimcilar
             CROSS APPLY
             (
                SELECT NULLIF(
                    REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                        LTRIM(RTRIM(CONVERT(varchar(50), bakimcitel))),
                        '+', ''), ' ', ''), '(', ''), ')', ''), '-', ''), '.', ''), '/', ''), ',', ''), CHAR(9), ''), CHAR(10), ''), CHAR(13), ''),
                    ''
                ) AS cleanTel
             ) p
             CROSS APPLY
             (
                SELECT NULLIF(
                    CASE
                        WHEN LEFT(p.cleanTel, 4) = '0090' AND LEN(p.cleanTel) = 14 THEN '90' + SUBSTRING(p.cleanTel, 5, 10)
                        WHEN LEFT(p.cleanTel, 2) = '90' AND LEN(p.cleanTel) = 12 THEN p.cleanTel
                        WHEN LEFT(p.cleanTel, 1) = '0' AND LEN(p.cleanTel) = 11 THEN '90' + SUBSTRING(p.cleanTel, 2, 10)
                        WHEN LEN(p.cleanTel) = 10 THEN '90' + p.cleanTel
                        ELSE p.cleanTel
                    END,
                    ''
                ) AS normalizedTel
             ) n
             WHERE n.normalizedTel = :phone
             ORDER BY CASE WHEN homesId IS NULL THEN 1 ELSE 0 END ASC, id ASC"
        );
        $stmt->bindValue(':phone', $phone);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    private function clearOtherHomeCaretakers(PDO $pdo, int $homeId, int $exceptId = 0): void
    {
        if ($homeId <= 0) {
            return;
        }

        $sql = 'UPDATE dbo.bakimcilar SET homesId = NULL WHERE homesId = :homesId';
        $params = [':homesId' => $homeId];

        if ($exceptId > 0) {
            $sql .= ' AND id <> :exceptId';
            $params[':exceptId'] = $exceptId;
        }

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();
    }

    private function cleanBakimciName(string $name): string
    {
        $name = str_replace([',', ':', ';', '.'], '', $name);

        return trim($name);
    }

    private function hasLetter(string $value): bool
    {
        return preg_match('/[A-Za-zÇĞİÖŞÜçğıöşü]/u', $value) === 1;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    private function mesafelerPayload(array $payload): array
    {
        $value = null;
        if ($this->hasPath($payload, 'mesafeler')) {
            $value = $this->getPath($payload, 'mesafeler');
        }
        if ($this->hasPath($payload, 'konumBilgileri.mesafeler')) {
            $value = $this->getPath($payload, 'konumBilgileri.mesafeler');
        }

        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array{updated:int,skipped:array<int,array<string,string>>}
     */
    private function updateMesafeler(PDO $pdo, int $homeId, array $rows): array
    {
        if ($rows === []) {
            return ['updated' => 0, 'skipped' => []];
        }

        $updated = 0;
        $skipped = [];

        foreach ($rows as $index => $row) {
            $id = $this->numericValue($row, ['id']);
            $mesafelerId = $this->numericValue($row, ['mesafelerId', 'mesafeler_id', 'meid']);
            $mesafe = $this->normalizeScalar($this->firstPayloadValue($row, ['mesafe', 'deger', 'value']));
            $birim = $this->normalizeScalar($this->firstPayloadValue($row, ['birim', 'unit']));
            $aciklama = $this->normalizeScalar($this->firstPayloadValue($row, ['aciklama']));

            if ($id <= 0 && $mesafelerId <= 0) {
                $skipped[] = [
                    'section' => 'mesafeler',
                    'index' => (string) $index,
                    'reason' => 'id veya mesafeler_id zorunlu.',
                ];
                continue;
            }

            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE mesafelerValues
                     SET mesafe = :mesafe, birim = :birim, aciklama = :aciklama
                     WHERE id = :id AND homesId = :homeId'
                );
                $stmt->execute([
                    ':mesafe' => $mesafe,
                    ':birim' => $birim,
                    ':aciklama' => $aciklama,
                    ':id' => $id,
                    ':homeId' => $homeId,
                ]);

                $updated += $stmt->rowCount();
                continue;
            }

            $stmt = $pdo->prepare(
                'UPDATE mesafelerValues
                 SET mesafe = :mesafe, birim = :birim, aciklama = :aciklama
                 WHERE homesId = :homeId AND mesafelerId = :mesafelerId'
            );
            $stmt->execute([
                ':mesafe' => $mesafe,
                ':birim' => $birim,
                ':aciklama' => $aciklama,
                ':homeId' => $homeId,
                ':mesafelerId' => $mesafelerId,
            ]);

            if ($stmt->rowCount() > 0) {
                $updated += $stmt->rowCount();
                continue;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO mesafelerValues (homesId, mesafelerId, mesafe, birim, aciklama)
                 VALUES (:homeId, :mesafelerId, :mesafe, :birim, :aciklama)'
            );
            $stmt->execute([
                ':homeId' => $homeId,
                ':mesafelerId' => $mesafelerId,
                ':mesafe' => $mesafe,
                ':birim' => $birim,
                ':aciklama' => $aciklama,
            ]);
            $updated++;
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $keys
     */
    private function numericValue(array $row, array $keys): int
    {
        $value = $this->firstPayloadValue($row, $keys);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $keys
     * @return mixed
     */
    private function firstPayloadValue(array $row, array $keys)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $updates
     * @return array{updated_columns:array<int,string>,skipped_columns:array<int,array<string,string>>}
     */
    private function updateHomes(PDO $pdo, int $id, array $updates): array
    {
        $set = [];
        $params = [':id' => $id];
        $columns = [];
        $skippedColumns = [];
        $index = 0;
        $existingColumns = $this->homesColumns($pdo);

        foreach ($updates as $column => $value) {
            if ($this->safeColumn($column) === false) {
                continue;
            }
            if (!isset($existingColumns[strtolower($column)])) {
                continue;
            }
            $columnInfo = $existingColumns[strtolower($column)];
            $normalized = $this->normalizeForColumn($column, $value, $columnInfo);
            if (!$normalized['ok']) {
                $skippedColumns[] = [
                    'column' => $column,
                    'reason' => $normalized['reason'],
                ];
                continue;
            }

            $param = ':p' . $index++;
            $set[] = '[' . $column . '] = ' . $param;
            $params[$param] = $normalized['value'];
            $columns[] = $column;
        }

        if ($set === []) {
            if ($skippedColumns !== []) {
                return [
                    'updated_columns' => [],
                    'skipped_columns' => $skippedColumns,
                ];
            }

            throw new HttpException('Gecerli homes alani bulunamadi.', 'VALIDATION', 422);
        }

        $stmt = $pdo->prepare('UPDATE homes SET ' . implode(', ', $set) . ' WHERE id = :id');
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return [
            'updated_columns' => $columns,
            'skipped_columns' => $skippedColumns,
        ];
    }

    /**
     * @return array<string,array{data_type:string}>
     */
    private function homesColumns(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'homes'");
        $columns = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = strtolower((string) ($row['COLUMN_NAME'] ?? ''));
            if ($name !== '') {
                $columns[$name] = [
                    'data_type' => strtolower((string) ($row['DATA_TYPE'] ?? '')),
                ];
            }
        }

        return $columns;
    }

    /**
     * @param mixed $value
     * @param array{data_type:string} $columnInfo
     * @return array{ok:bool,value:mixed,reason:string}
     */
    private function normalizeForColumn(string $column, $value, array $columnInfo): array
    {
        $type = $columnInfo['data_type'];
        $intTypes = ['bigint', 'int', 'smallint', 'tinyint', 'bit'];

        if (in_array($type, $intTypes, true)) {
            $value = trim((string) $value);
            if ($value === '') {
                return ['ok' => true, 'value' => 0, 'reason' => ''];
            }
            if (strpos($value, ',') !== false || strpos($value, '#') !== false) {
                return [
                    'ok' => false,
                    'value' => null,
                    'reason' => 'Virgullu/coklu deger int kolona yazilamaz.',
                ];
            }
            if (!is_numeric($value)) {
                return [
                    'ok' => false,
                    'value' => null,
                    'reason' => 'Sayisal olmayan deger int kolona yazilamaz.',
                ];
            }

            return ['ok' => true, 'value' => (int) $value, 'reason' => ''];
        }

        return ['ok' => true, 'value' => $value, 'reason' => ''];
    }

    private function safeColumn(string $column): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) === 1;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function hasBakimciContactPayload(array $payload): bool
    {
        foreach ([
                     'bakimciAdres',
                     'bakimciadres',
                     'address',
                     'adres',
                     'bakimciEmail',
                     'bakimciemail',
                     'email',
                     'eposta',
                     'genelBilgiler.iletisimBilgileri.bakimciBilgisi.adres',
                     'genelBilgiler.iletisimBilgileri.bakimciBilgisi.address',
                     'genelBilgiler.iletisimBilgileri.bakimciBilgisi.eposta',
                     'genelBilgiler.iletisimBilgileri.bakimciBilgisi.email',
                 ] as $path) {
            if ($this->hasPath($payload, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function hasPath(array $payload, string $path): bool
    {
        $current = $payload;
        foreach (explode('.', $path) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return false;
            }

            $current = $current[$part];
        }

        return true;
    }

    /**
     * @param array<string,mixed> $payload
     * @return mixed
     */
    private function getPath(array $payload, string $path)
    {
        $current = $payload;
        foreach (explode('.', $path) as $part) {
            $current = $current[$part];
        }

        return $current;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<int,string> $paths
     * @return array{exists:bool,value:mixed}
     */
    private function firstExistingPath(array $payload, array $paths): array
    {
        foreach ($paths as $path) {
            if ($this->hasPath($payload, $path)) {
                return [
                    'exists' => true,
                    'value' => $this->getPath($payload, $path),
                ];
            }
        }

        return [
            'exists' => false,
            'value' => '',
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<int,string> $paths
     * @param mixed $fallback
     */
    private function firstNonEmptyPayloadValue(array $payload, array $paths, $fallback): string
    {
        foreach ($paths as $path) {
            if (!$this->hasPath($payload, $path)) {
                continue;
            }

            $value = $this->normalizeScalar($this->getPath($payload, $path));
            if ($value !== '') {
                return (string) $value;
            }
        }

        return (string) $fallback;
    }

    private function normalizePhone(string $phone): string
    {
        $phone = str_replace(['+', ' ', '(', ')', '-', '.', '/', ',', "\t", "\n", "\r"], '', trim($phone));
        if ($phone === '') {
            return '';
        }

        if (strpos($phone, '0090') === 0 && strlen($phone) === 14) {
            return '90' . substr($phone, 4, 10);
        }

        if (strpos($phone, '90') === 0 && strlen($phone) === 12) {
            return substr($phone, 0, 20);
        }

        if (strpos($phone, '0') === 0 && strlen($phone) === 11) {
            return '90' . substr($phone, 1, 10);
        }

        if (strlen($phone) === 10) {
            return '90' . $phone;
        }

        return substr($phone, 0, 20);
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
     * @param mixed $value
     * @return int|float|string
     */
    private function normalizeScalar($value)
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if ($value === null || is_array($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function currencyDbValue(string $value): string
    {
        $currency = strtolower(trim($value));

        switch ($currency) {
            case 'try':
            case 'tl':
                return 'tl';
            case 'usd':
            case 'dolar':
                return 'dolar';
            case 'eur':
            case 'euro':
                return 'euro';
            case 'gbp':
            case 'pound':
                return 'pound';
            default:
                return $currency;
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function siteId(array $payload): int
    {
        $site = $payload['site'] ?? $this->request->query('site', 1);

        return is_numeric($site) && (int) $site > 0 ? (int) $site : 1;
    }

    private function configuredSiteSuffix(int $siteId): string
    {
        $suffixes = $this->app['site_column_suffixes'] ?? [];
        $suffix = is_array($suffixes) && array_key_exists($siteId, $suffixes)
            ? (string) $suffixes[$siteId]
            : '';

        return $this->safeDbTableSuffix($suffix);
    }

    private function safeDbTableSuffix(string $suffix): string
    {
        if ($suffix === '' || preg_match('/^_[A-Za-z0-9]+$/', $suffix) === 1) {
            return $suffix;
        }

        return '';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
    private function skippedRelatedSections(array $payload): array
    {
        $sections = [];
        foreach ([
                     'havuzlar',
                     'odalar',
                     'sezonlar',
                     'ekstraucretler',
                     'ekstraUcretler',
                     'indirimler',
                     'konumnotlari',
                     'ozelliklerVeOlanaklar.havuzlar',
                     'ozelliklerVeOlanaklar.odalar',
                     'fiyatlandirmaVeKurallar.donemselFiyatlandirma.sezonlar',
                     'fiyatlandirmaVeKurallar.ekstraUcretler',
                     'fiyatlandirmaVeKurallar.indirimler',
                     'konumBilgileri.konumNotlari',
                 ] as $path) {
            if ($this->hasPath($payload, $path)) {
                $sections[] = $path;
            }
        }

        return $sections;
    }
}
