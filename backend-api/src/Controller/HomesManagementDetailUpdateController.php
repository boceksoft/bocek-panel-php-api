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
    /**
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
        $sezonlar = $this->sezonlarPayload($payload);
        $extraPayments = $this->extraPaymentsPayload($payload);
        if ($updates === [] && $mesafeler === [] && $sezonlar === [] && $extraPayments === []) {
            throw new HttpException('Guncellenecek alan bulunamadi.', 'VALIDATION', 422);
        }

        $pdo->beginTransaction();
        try {
            $updateResult = $updates !== []
                ? $this->updateHomes($pdo, $id, $updates)
                : ['updated_columns' => [], 'skipped_columns' => []];
            $mesafelerResult = $this->updateMesafeler($pdo, $id, $mesafeler);
            $sezonlarResult = $this->updateSezonlar($pdo, $id, $sezonlar, $this->siteId($payload));
            $extraPaymentsResult = $this->updateExtraPayments($pdo, $id, $extraPayments);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw new HttpException('Emlak detay bilgileri guncellenemedi.', 'DB_UPDATE_FAILED', 500, $e);
        }

        $this->response->success([
            'id' => $id,
            'updated' => count($updateResult['updated_columns']) > 0 || $mesafelerResult['updated'] > 0 || $sezonlarResult['updated'] > 0 || $extraPaymentsResult['updated'] > 0,
            'updated_columns' => $updateResult['updated_columns'],
            'skipped_columns' => $updateResult['skipped_columns'],
            'updated_related_sections' => [
                'mesafeler' => $mesafelerResult['updated'],
                'sezonlar' => $sezonlarResult['updated'],
                'ekstra_ucretler' => $extraPaymentsResult['updated'],
            ],
            'skipped_related_rows' => array_merge($mesafelerResult['skipped'], $sezonlarResult['skipped'], $extraPaymentsResult['skipped']),
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
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    private function sezonlarPayload(array $payload): array
    {
        $value = null;
        if ($this->hasPath($payload, 'sezonlar')) {
            $value = $this->getPath($payload, 'sezonlar');
        }
        if ($this->hasPath($payload, 'fiyatlandirmaVeKurallar.donemselFiyatlandirma.sezonlar')) {
            $value = $this->getPath($payload, 'fiyatlandirmaVeKurallar.donemselFiyatlandirma.sezonlar');
        }

        if (is_array($value)) {
            if ($value === []) {
                return [];
            }

            return $this->isList($value) ? array_values(array_filter($value, 'is_array')) : [$value];
        }

        $single = [];
        foreach ([
            'sezon',
            'tarih1',
            'tarih2',
            'baslangicTarihi',
            'bitisTarihi',
            'fiyat',
            'gecelik_fiyat',
            'haftalik_fiyat',
            'minKonaklama',
            'mingece',
            'gece',
            'fiyatTipi',
            'fiyat_tipi',
            'sezonId',
            'seasonId',
        ] as $key) {
            if (array_key_exists($key, $payload)) {
                $single[$key] = $payload[$key];
            }
        }

        return $single === [] ? [] : [$single];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    private function extraPaymentsPayload(array $payload): array
    {
        $value = null;
        if ($this->hasPath($payload, 'ekstraucretler')) {
            $value = $this->getPath($payload, 'ekstraucretler');
        }
        if ($this->hasPath($payload, 'ekstraUcretler')) {
            $value = $this->getPath($payload, 'ekstraUcretler');
        }
        if ($this->hasPath($payload, 'fiyatlandirmaVeKurallar.ekstraUcretler')) {
            $value = $this->getPath($payload, 'fiyatlandirmaVeKurallar.ekstraUcretler');
        }

        if (is_array($value)) {
            if ($value === []) {
                return [];
            }

            return $this->isList($value) ? array_values(array_filter($value, 'is_array')) : [$value];
        }

        $single = [];
        foreach ([
            'extraPaymentTypeId',
            'typeId',
            'tipId',
            'typeCode',
            'tip',
            'kod',
            'seasonIds',
            'sezonIds',
            'seasonId',
            'sezonId',
            'tarih1',
            'tarih2',
            'baslangicTarihi',
            'bitisTarihi',
            'fiyat',
            'value',
            'amount',
            'currencyId',
            'paraBirimi',
            'currency_id',
            'fiyatTipi',
            'fiyat_tipi',
            'priceType',
            'description',
            'aciklama',
        ] as $key) {
            if (array_key_exists($key, $payload)) {
                $single[$key] = $payload[$key];
            }
        }

        return $single === [] ? [] : [$single];
    }

    /**
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
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
     * @param array<int,array<string,mixed>> $rows
     * @return array{updated:int,skipped:array<int,array<string,string>>}
     */
    private function updateSezonlar(PDO $pdo, int $homeId, array $rows, int $siteId): array
    {
        if ($rows === []) {
            return ['updated' => 0, 'skipped' => []];
        }

        $updated = 0;
        $skipped = [];

        foreach ($rows as $index => $row) {
            $startDate = $this->normalizeDate($this->firstPayloadValue($row, ['tarih1', 'baslangicTarihi', 'startDate', 'start_date']));
            $endDate = $this->normalizeDate($this->firstPayloadValue($row, ['tarih2', 'bitisTarihi', 'endDate', 'end_date']));
            $price = $this->priceValue($row);
            $minStay = $this->numericValue($row, ['minKonaklama', 'mingece', 'min_gece', 'gece']);

            if ($startDate === '' || $endDate === '' || $price <= 0 || $minStay <= 0) {
                $skipped[] = [
                    'section' => 'sezonlar',
                    'index' => (string) $index,
                    'reason' => 'tarih1, tarih2, fiyat ve minKonaklama zorunlu.',
                ];
                continue;
            }

            $seasonId = $this->numericValue($row, ['id', 'seasonId', 'sezonId']);
            $seasonTitle = $this->normalizeScalar($this->firstPayloadValue($row, ['sezon', 'title', 'baslik']));

            if ($seasonId > 0) {
                $stmt = $pdo->prepare(
                    "UPDATE sezonlar
                     SET tarih1 = :tarih1, tarih2 = :tarih2, fiyat = :fiyat, gece = :gece, sezon = :sezon
                     WHERE id = :id AND islem_id = :homeId AND islem = 'emlak'"
                );
                $stmt->execute([
                    ':tarih1' => $startDate,
                    ':tarih2' => $endDate,
                    ':fiyat' => $price,
                    ':gece' => $minStay,
                    ':sezon' => $seasonTitle,
                    ':id' => $seasonId,
                    ':homeId' => $homeId,
                ]);
                $updated += max(1, $stmt->rowCount());
                continue;
            }

            $stmt = $pdo->prepare(
                "INSERT INTO sezonlar (site, islem, islem_id, tarih1, tarih2, fiyat, gece, sezon)
                 VALUES (:site, 'emlak', :homeId, :tarih1, :tarih2, :fiyat, :gece, :sezon)"
            );
            $stmt->execute([
                ':site' => $siteId,
                ':homeId' => $homeId,
                ':tarih1' => $startDate,
                ':tarih2' => $endDate,
                ':fiyat' => $price,
                ':gece' => $minStay,
                ':sezon' => $seasonTitle,
            ]);
            $updated++;
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array{updated:int,skipped:array<int,array<string,string>>}
     */
    private function updateExtraPayments(PDO $pdo, int $homeId, array $rows): array
    {
        if ($rows === []) {
            return ['updated' => 0, 'skipped' => []];
        }
        if (!$this->tableExists($pdo, 'dbo', 'HomesExtraPaymentPrices')) {
            throw new HttpException('Ekstra ucret tablolari kurulu degil. Once setup/extra-payments calistirin.', 'SETUP_REQUIRED', 500);
        }

        $updated = 0;
        $skipped = [];

        foreach ($rows as $index => $row) {
            $type = $this->extraPaymentType($pdo, $row);
            $value = $this->moneyPayloadValue($row, ['fiyat', 'value', 'amount', 'tutar']);
            $currencyId = $this->currencyId($row);
            $priceType = $this->extraPaymentPriceType($row);
            $seasonIds = $this->seasonIds($row);
            $startDate = $this->normalizeDate($this->firstPayloadValue($row, ['tarih1', 'baslangicTarihi', 'startDate', 'start_date']));
            $endDate = $this->normalizeDate($this->firstPayloadValue($row, ['tarih2', 'bitisTarihi', 'endDate', 'end_date']));
            $description = $this->normalizeScalar($this->firstPayloadValue($row, ['description', 'aciklama']));

            if ($type === [] || $value <= 0) {
                $skipped[] = [
                    'section' => 'ekstra_ucretler',
                    'index' => (string) $index,
                    'reason' => 'tip ve fiyat zorunlu.',
                ];
                continue;
            }

            if ($seasonIds === [] && $startDate !== '' && $endDate !== '') {
                $seasonIds = $this->seasonIdsInRange($pdo, $homeId, $startDate, $endDate);
            }

            if ($seasonIds === []) {
                $skipped[] = [
                    'section' => 'ekstra_ucretler',
                    'index' => (string) $index,
                    'reason' => 'seasonIds veya tarih araligina denk gelen sezon zorunlu.',
                ];
                continue;
            }

            foreach ($seasonIds as $seasonId) {
                $season = $this->seasonRow($pdo, $homeId, $seasonId);
                if ($season === []) {
                    $skipped[] = [
                        'section' => 'ekstra_ucretler',
                        'index' => (string) $index,
                        'reason' => 'Secilen sezon bulunamadi: ' . $seasonId,
                    ];
                    continue;
                }

                $rowStart = $startDate !== '' ? $startDate : $this->normalizeDate($season['tarih1'] ?? '');
                $rowEnd = $endDate !== '' ? $endDate : $this->normalizeDate($season['tarih2'] ?? '');
                $this->upsertExtraPaymentPrice($pdo, $homeId, $seasonId, (int) $type['id'], $rowStart, $rowEnd, $value, $currencyId, $priceType, $description);

                if ($type['code'] === 'temizlik') {
                    $this->updateSeasonCleaningPrice($pdo, $homeId, $seasonId, $value);
                }

                $updated++;
            }
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * @param mixed $value
     */
    private function normalizeDate($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        foreach (['d.m.Y', 'd/m/Y', 'Y-m-d', 'Y.m.d'] as $format) {
            $date = \DateTime::createFromFormat($format, $value);
            if ($date instanceof \DateTime) {
                return $date->format('d.m.Y');
            }
        }

        try {
            return (new \DateTime($value))->format('d.m.Y');
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    private function priceValue(array $row): float
    {
        $weekly = $this->firstPayloadValue($row, ['haftalik_fiyat', 'haftalikFiyat', 'weeklyPrice']);
        if (is_numeric($weekly) && (float) $weekly > 0) {
            return (float) $weekly;
        }

        $daily = $this->firstPayloadValue($row, ['gecelik_fiyat', 'gunluk_fiyat', 'gunlukFiyat', 'dailyPrice']);
        if (is_numeric($daily) && (float) $daily > 0) {
            return (float) $daily * 7;
        }

        $price = $this->firstPayloadValue($row, ['fiyat', 'price']);
        if (!is_numeric($price) || (float) $price <= 0) {
            return 0.0;
        }

        $priceType = strtolower(trim((string) $this->firstPayloadValue($row, ['fiyatTipi', 'fiyat_tipi', 'priceType'])));
        if (in_array($priceType, ['gunluk', 'daily', 'day', '1'], true)) {
            return (float) $price * 7;
        }

        return (float) $price;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,code:string}
     */
    private function extraPaymentType(PDO $pdo, array $row): array
    {
        $typeId = $this->numericValue($row, ['extraPaymentTypeId', 'typeId', 'tipId']);
        if ($typeId > 0) {
            $stmt = $pdo->prepare(
                'SELECT ExtraPaymentTypeId, Code
                 FROM dbo.HomesExtraPaymentTypes
                 WHERE ExtraPaymentTypeId = :id AND IsDeleted = 0'
            );
            $stmt->execute([':id' => $typeId]);
        } else {
            $code = strtolower(trim((string) $this->firstPayloadValue($row, ['typeCode', 'tip', 'kod', 'code'])));
            $codeMap = [
                'hasardepozitosu' => 'hasar',
                'hasar_depozitosu' => 'hasar',
                'elektriksu' => 'elektrik',
                'elektrik-su' => 'elektrik',
                'elektrik_su' => 'elektrik',
            ];
            $code = $codeMap[$code] ?? $code;
            if ($code === '') {
                return [];
            }

            $stmt = $pdo->prepare(
                'SELECT ExtraPaymentTypeId, Code
                 FROM dbo.HomesExtraPaymentTypes
                 WHERE Code = :code AND IsDeleted = 0'
            );
            $stmt->execute([':code' => $code]);
        }

        $type = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($type)) {
            return [];
        }

        return [
            'id' => (int) ($type['ExtraPaymentTypeId'] ?? 0),
            'code' => strtolower((string) ($type['Code'] ?? '')),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<int,int>
     */
    private function seasonIds(array $row): array
    {
        $value = $this->firstPayloadValue($row, ['seasonIds', 'sezonIds', 'season_ids', 'sezon_ids']);
        if ($value === '') {
            $single = $this->numericValue($row, ['seasonId', 'sezonId']);
            return $single > 0 ? [$single] : [];
        }

        $ids = [];
        foreach ($this->normalizeListValue($value) as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<int,int>
     */
    private function seasonIdsInRange(PDO $pdo, int $homeId, string $startDate, string $endDate): array
    {
        $stmt = $pdo->prepare(
            "SELECT id
             FROM sezonlar
             WHERE islem_id = :homeId
               AND islem = 'emlak'
               AND CONVERT(date, tarih1, 104) <= CONVERT(date, :endDate, 104)
               AND CONVERT(date, tarih2, 104) >= CONVERT(date, :startDate, 104)"
        );
        $stmt->execute([
            ':homeId' => $homeId,
            ':startDate' => $startDate,
            ':endDate' => $endDate,
        ]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return array<string,mixed>
     */
    private function seasonRow(PDO $pdo, int $homeId, int $seasonId): array
    {
        $stmt = $pdo->prepare(
            "SELECT id, tarih1, tarih2
             FROM sezonlar
             WHERE id = :id AND islem_id = :homeId AND islem = 'emlak'"
        );
        $stmt->execute([
            ':id' => $seasonId,
            ':homeId' => $homeId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    private function upsertExtraPaymentPrice(
        PDO $pdo,
        int $homeId,
        int $seasonId,
        int $typeId,
        string $startDate,
        string $endDate,
        float $value,
        int $currencyId,
        string $priceType,
        string $description
    ): void {
        $stmt = $pdo->prepare(
            "UPDATE dbo.HomesExtraPaymentPrices
             SET StartDate = CONVERT(date, :startDate, 104),
                 EndDate = CONVERT(date, :endDate, 104),
                 CurrencyId = :currencyId,
                 PriceType = :priceType,
                 Value = :value,
                 Description = :description,
                 UpdatedOn = GETDATE(),
                 IsDeleted = 0
             WHERE HomesId = :homeId
               AND SeasonId = :seasonId
               AND ExtraPaymentTypeId = :typeId"
        );
        $stmt->execute([
            ':homeId' => $homeId,
            ':seasonId' => $seasonId,
            ':typeId' => $typeId,
            ':startDate' => $startDate,
            ':endDate' => $endDate,
            ':currencyId' => $currencyId > 0 ? $currencyId : null,
            ':priceType' => $priceType,
            ':value' => $value,
            ':description' => $description,
        ]);

        if ($stmt->rowCount() > 0) {
            return;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO dbo.HomesExtraPaymentPrices
                (HomesId, SeasonId, StartDate, EndDate, CurrencyId, PriceType, ExtraPaymentTypeId, Value, Description, CreatedOn, IsDeleted)
             VALUES
                (:homeId, :seasonId, CONVERT(date, :startDate, 104), CONVERT(date, :endDate, 104), :currencyId, :priceType, :typeId, :value, :description, GETDATE(), 0)"
        );
        $stmt->execute([
            ':homeId' => $homeId,
            ':seasonId' => $seasonId,
            ':typeId' => $typeId,
            ':startDate' => $startDate,
            ':endDate' => $endDate,
            ':currencyId' => $currencyId > 0 ? $currencyId : null,
            ':priceType' => $priceType,
            ':value' => $value,
            ':description' => $description,
        ]);
    }

    private function updateSeasonCleaningPrice(PDO $pdo, int $homeId, int $seasonId, float $value): void
    {
        $stmt = $pdo->prepare(
            "UPDATE sezonlar
             SET temizlikFiyat = :value
             WHERE id = :seasonId AND islem_id = :homeId AND islem = 'emlak'"
        );
        $stmt->execute([
            ':value' => $value,
            ':seasonId' => $seasonId,
            ':homeId' => $homeId,
        ]);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $keys
     */
    private function moneyPayloadValue(array $row, array $keys): float
    {
        $value = $this->firstPayloadValue($row, $keys);
        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = str_replace(',', '.', str_replace('.', '', trim((string) $value)));

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function currencyId(array $row): int
    {
        $value = $this->firstPayloadValue($row, ['currencyId', 'paraBirimi', 'currency_id']);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function extraPaymentPriceType(array $row): string
    {
        $value = strtolower(trim((string) $this->firstPayloadValue($row, ['fiyatTipi', 'fiyat_tipi', 'priceType'])));
        $map = [
            'gunluk' => 'gunluk',
            'daily' => 'gunluk',
            'day' => 'gunluk',
            'haftalik' => 'haftalik',
            'weekly' => 'haftalik',
            'week' => 'haftalik',
            'aylik' => 'aylik',
            'monthly' => 'aylik',
            'month' => 'aylik',
            'konaklama' => 'konaklama',
            'stay' => 'konaklama',
        ];

        return $map[$value] ?? ($value !== '' ? $value : 'konaklama');
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
            'indirimler',
            'konumnotlari',
            'ozelliklerVeOlanaklar.havuzlar',
            'ozelliklerVeOlanaklar.odalar',
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
