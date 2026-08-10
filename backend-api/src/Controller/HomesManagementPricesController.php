<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Emlak fiyatlandirma iliskili kayitlari.
 * Sezonlar, ekstra ucretler ve indirimler detail update akisi disinda ayri endpoint'lerden yonetilir.
 */
final class HomesManagementPricesController extends Controller
{ 
    /**
     * Emlak sezonlarini ekler veya gunceller.
     *
     * @Post("sezonlar")
     * @Put("sezonlar")
     * @query id int required Emlak ID
     * @query site int Site ID
     * @body id int required Emlak ID
     * @body sezonlar array Sezon satirlari
     */
    public function seasons(): void
    {
        $payload = $this->payload();
        $id = $this->resolveId($payload);

        if ($id <= 0) {
            throw new HttpException('Lutfen gecerli bir emlak ID gonderin.', 'VALIDATION', 422);
        }

        $rows = $this->sezonlarPayload($payload);
        if ($rows === []) {
            throw new HttpException('Eklenecek veya guncellenecek sezon bulunamadi.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();
        $this->assertHomeExists($pdo, $id);

        $pdo->beginTransaction();
        try {
            $result = $this->updateSezonlar($pdo, $id, $rows, $this->siteId($payload));
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw new HttpException('Sezon bilgileri kaydedilemedi.', 'DB_UPDATE_FAILED', 500, $e);
        }

        $this->response->success([
            'id' => $id,
            'updated' => $result['updated'] > 0,
            'updated_related_sections' => [
                'sezonlar' => $result['updated'],
            ],
            'skipped_related_rows' => $result['skipped'],
        ]);
    }

    /**
     * Emlak ekstra ucretlerini sezon veya tarih araligina gore ekler/gunceller.
     *
     * @Post("ekstra-ucretler")
     * @Put("ekstra-ucretler")
     * @Post("ekstraucretler")
     * @Put("ekstraucretler")
     * @query id int required Emlak ID
     * @body id int required Emlak ID
     * @body ekstraucretler array Ekstra ucret satirlari
     */
    public function extraPayments(): void
    {
        $payload = $this->payload();
        $id = $this->resolveId($payload);

        if ($id <= 0) {
            throw new HttpException('Lutfen gecerli bir emlak ID gonderin.', 'VALIDATION', 422);
        }

        $rows = $this->extraPaymentsPayload($payload);
        if ($rows === []) {
            throw new HttpException('Eklenecek veya guncellenecek ekstra ucret bulunamadi.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();
        $this->assertHomeExists($pdo, $id);

        $pdo->beginTransaction();
        try {
            $result = $this->updateExtraPayments($pdo, $id, $rows);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw new HttpException('Ekstra ucret bilgileri kaydedilemedi.', 'DB_UPDATE_FAILED', 500, $e);
        }

        $this->response->success([
            'id' => $id,
            'updated' => $result['updated'] > 0,
            'updated_related_sections' => [
                'ekstra_ucretler' => $result['updated'],
            ],
            'skipped_related_rows' => $result['skipped'],
        ]);
    }

    /**
     * Emlak indirimlerini ekler veya gunceller.
     *
     * @Post("indirimler")
     * @Put("indirimler")
     * @Post("discounts")
     * @Put("discounts")
     * @query id int required Emlak ID
     * @query site int Site ID
     * @body id int required Emlak ID
     * @body indirimler array Indirim satirlari
     */
    public function discounts(): void
    {
        $payload = $this->payload();
        $id = $this->resolveId($payload);

        if ($id <= 0) {
            throw new HttpException('Lutfen gecerli bir emlak ID gonderin.', 'VALIDATION', 422);
        }

        $rows = $this->discountsPayload($payload);
        if ($rows === []) {
            throw new HttpException('Eklenecek veya guncellenecek indirim bulunamadi.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();
        $this->assertHomeExists($pdo, $id);

        $pdo->beginTransaction();
        try {
            $result = $this->updateDiscounts($pdo, $id, $rows, $this->siteId($payload));
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw new HttpException('Indirim bilgileri kaydedilemedi.', 'DB_UPDATE_FAILED', 500, $e);
        }

        $this->response->success([
            'id' => $id,
            'updated' => $result['updated'] > 0,
            'updated_related_sections' => [
                'indirimler' => $result['updated'],
            ],
            'skipped_related_rows' => $result['skipped'],
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
                     'aciklama',
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
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    private function discountsPayload(array $payload): array
    {
        $value = null;
        if ($this->hasPath($payload, 'indirimler')) {
            $value = $this->getPath($payload, 'indirimler');
        }
        if ($this->hasPath($payload, 'discounts')) {
            $value = $this->getPath($payload, 'discounts');
        }
        if ($this->hasPath($payload, 'fiyatlandirmaVeKurallar.indirimler')) {
            $value = $this->getPath($payload, 'fiyatlandirmaVeKurallar.indirimler');
        }

        if (is_array($value)) {
            if ($value === []) {
                return [];
            }

            return $this->isList($value) ? array_values(array_filter($value, 'is_array')) : [$value];
        }

        $single = [];
        foreach ([
                     'tarih1',
                     'tarih2',
                     'baslangicTarihi',
                     'bitisTarihi',
                     'startDate',
                     'endDate',
                     'showDate1',
                     'showDate2',
                     'showdate1',
                     'showdate2',
                     'etkinBaslangicTarihi',
                     'etkinBitisTarihi',
                     'discountId',
                     'indirimId',
                     'oran',
                     'sahte_oran',
                     'sahteOran',
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
    private function updateSezonlar(PDO $pdo, int $homeId, array $rows, int $siteId): array
    {
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
            $seasonTitle = $this->normalizeScalar($this->firstPayloadValue($row, ['aciklama', 'sezon', 'title', 'baslik']));

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
        if (!$this->tableExists($pdo, 'dbo', 'HomesExtraPaymentPrices')) {
            throw new HttpException('Ekstra ucret tablolari kurulu degil. Once setup/collums veya setup/extra-payments calistirin.', 'SETUP_REQUIRED', 500);
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
     * @param array<int,array<string,mixed>> $rows
     * @return array{updated:int,skipped:array<int,array<string,string>>}
     */
    private function updateDiscounts(PDO $pdo, int $homeId, array $rows, int $siteId): array
    {
        $updated = 0;
        $skipped = [];

        foreach ($rows as $index => $row) {
            $startDate = $this->normalizeDate($this->firstPayloadValue($row, ['tarih1', 'baslangicTarihi', 'startDate', 'start_date']));
            $endDate = $this->normalizeDate($this->firstPayloadValue($row, ['tarih2', 'bitisTarihi', 'endDate', 'end_date']));
            $showStartDate = $this->normalizeDate($this->firstPayloadValue($row, ['showDate1', 'showdate1', 'etkinBaslangicTarihi', 'show_start_date']));
            $showEndDate = $this->normalizeDate($this->firstPayloadValue($row, ['showDate2', 'showdate2', 'etkinBitisTarihi', 'show_end_date']));
            $rate = $this->numericValue($row, ['oran', 'rate', 'discountRate']);
            $fakeRate = $this->numericValue($row, ['sahte_oran', 'sahteOran', 'fakeRate']);

            if ($startDate === '' || $endDate === '' || $showStartDate === '' || $showEndDate === '') {
                $skipped[] = [
                    'section' => 'indirimler',
                    'index' => (string) $index,
                    'reason' => 'tarih1, tarih2, showDate1 ve showDate2 zorunlu.',
                ];
                continue;
            }

            $discountId = $this->numericValue($row, ['id', 'discountId', 'indirimId']);

            if ($discountId > 0) {
                $stmt = $pdo->prepare(
                    "UPDATE indirimler
                     SET tarih1 = CONVERT(date, :tarih1, 104),
                         tarih2 = CONVERT(date, :tarih2, 104),
                         showDate1 = CONVERT(date, :showDate1, 104),
                         showDate2 = CONVERT(date, :showDate2, 104),
                         oran = :oran,
                         sahte_oran = :sahte_oran,
                         site = :site,
                         discountType = 3
                     WHERE id = :id AND emlak = :homeId"
                );
                $stmt->execute([
                    ':tarih1' => $startDate,
                    ':tarih2' => $endDate,
                    ':showDate1' => $showStartDate,
                    ':showDate2' => $showEndDate,
                    ':oran' => $rate,
                    ':sahte_oran' => $fakeRate,
                    ':site' => $siteId,
                    ':id' => $discountId,
                    ':homeId' => $homeId,
                ]);
                if ($stmt->rowCount() > 0 || $this->discountExists($pdo, $homeId, $discountId)) {
                    $updated++;
                    continue;
                }

                $skipped[] = [
                    'section' => 'indirimler',
                    'index' => (string) $index,
                    'reason' => 'Secilen indirim bulunamadi: ' . $discountId,
                ];
                continue;
            }

            $stmt = $pdo->prepare(
                "INSERT INTO indirimler (emlak, tarih1, tarih2, site, showDate1, showDate2, oran, sahte_oran, discountType)
                 VALUES (:homeId, CONVERT(date, :tarih1, 104), CONVERT(date, :tarih2, 104), :site, CONVERT(date, :showDate1, 104), CONVERT(date, :showDate2, 104), :oran, :sahte_oran, 3)"
            );
            $stmt->execute([
                ':homeId' => $homeId,
                ':tarih1' => $startDate,
                ':tarih2' => $endDate,
                ':site' => $siteId,
                ':showDate1' => $showStartDate,
                ':showDate2' => $showEndDate,
                ':oran' => $rate,
                ':sahte_oran' => $fakeRate,
            ]);
            $updated++;
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    private function discountExists(PDO $pdo, int $homeId, int $discountId): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM indirimler WHERE id = :id AND emlak = :homeId');
        $stmt->execute([
            ':id' => $discountId,
            ':homeId' => $homeId,
        ]);

        return (int) $stmt->fetchColumn() > 0;
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

    /**
     * @param array<string,mixed> $payload
     */
    private function siteId(array $payload): int
    {
        $site = $payload['site'] ?? $this->request->query('site', 1);

        return is_numeric($site) && (int) $site > 0 ? (int) $site : 1;
    }
}
