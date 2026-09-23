<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Emlak fiyatlandirma iliskili kayitlari.
 * Sezonlar, ekstra ucretler ve indirimler detail update akisi disinda ayri endpoint'lerden yonetilir.
 *
 * Endpointler:
 *   POST|PUT /backend-api/homes-management-prices/sezonlar?id={emlakId}&site={siteId}
 *   POST|PUT /backend-api/homes-management-prices/ekstra-ucretler?id={emlakId}
 *   POST|PUT /backend-api/homes-management-prices/indirimler?id={emlakId}&site={siteId}
 *   POST     /backend-api/homes-management-prices/indirimler?delete=1&indirimId={indirimId}
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
        $delete = $this->boolPayloadValue($payload, ['delete']);

        if ($delete) {
            $this->deleteSeasonFromPayload($payload);

            return;
        }

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
     * Emlak sezon satirini siler.
     *
     * @Delete("sezonlar")
     * @Post("sezonlar-sil")
     * @Post("sezonlar/delete")
     * @query sezonId int required Sezon ID
     * @body sezonId int required Sezon ID
     */
    public function deleteSeason(): void
    {
        $payload = $this->payload();
        $this->deleteSeasonFromPayload($payload);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function deleteSeasonFromPayload(array $payload): void
    {
        $seasonId = $this->numericValue($payload, ['sezonId', 'seasonId']);

        if ($seasonId <= 0) {
            throw new HttpException('Lutfen gecerli bir sezon ID gonderin.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare('DELETE FROM sezonlar WHERE id = :seasonId');
        $stmt->execute([
            ':seasonId' => $seasonId,
        ]);

        if ($stmt->rowCount() <= 0) {
            throw new HttpException('Secilen sezon bulunamadi.', 'NOT_FOUND', 404);
        }

        $this->response->success([
            'id' => $seasonId,
            'deleted' => true,
            'deleted_related_sections' => [
                'sezonlar' => 1,
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function deleteExtraPaymentFromPayload(array $payload): void
    {
        $rowId = $this->numericValue($payload, ['extraId']);
        $priceId = $this->numericValue($payload, ['extraPaymentPriceId']);
        $legacyId = $this->numericValue($payload, ['extraPaymentId', 'legacyExtraPaymentId']);

        if ($rowId <= 0 && $priceId <= 0 && $legacyId <= 0) {
            throw new HttpException('Lutfen gecerli bir ekstra ucret ID gonderin.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();
        if (($rowId > 0 || $priceId > 0) && !$this->tableExists($pdo, 'dbo', 'HomesExtraPaymentPrices')) {
            throw new HttpException('Ekstra ucret tablolari kurulu degil.', 'SETUP_REQUIRED', 500);
        }

        $pdo->beginTransaction();
        try {
            $priceDeleted = 0;
            $legacyDeleted = 0;
            $skipped = [];

            if ($rowId > 0 || $priceId > 0) {
                try {
                    $conditions = [];
                    $params = [];
                    if ($rowId > 0) {
                        if ($this->columnExists($pdo, 'dbo', 'HomesExtraPaymentPrices', 'id')) {
                            $conditions[] = '[id] = :rowId';
                            $params[':rowId'] = $rowId;
                        } elseif ($priceId <= 0) {
                            throw new HttpException('HomesExtraPaymentPrices tablosunda id kolonu bulunamadi.', 'VALIDATION', 422);
                        }
                    }
                    if ($priceId > 0) {
                        $conditions[] = 'ExtraPaymentPriceId = :priceId';
                        $params[':priceId'] = $priceId;
                    }

                    $stmt = $pdo->prepare(
                        'DELETE FROM dbo.HomesExtraPaymentPrices
                         WHERE ' . implode(' OR ', $conditions)
                    );
                    $stmt->execute($params);
                    $priceDeleted = $stmt->rowCount();
                } catch (HttpException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    $skipped[] = [
                        'section' => 'ekstra_ucretler',
                        'id' => $rowId > 0 ? (string) $rowId : (string) $priceId,
                        'reason' => $e->getMessage(),
                    ];
                }
            }

            if ($legacyId > 0) {
                try {
                    $legacyDeleted += $this->deleteLegacyExtraPaymentById($pdo, $legacyId);
                } catch (\Throwable $e) {
                    $skipped[] = [
                        'section' => 'legacy_ekstra_ucretler',
                        'id' => (string) $legacyId,
                        'reason' => $e->getMessage(),
                    ];
                }
            }

            if ($priceDeleted <= 0 && $legacyDeleted <= 0 && $skipped === []) {
                throw new HttpException('Secilen ekstra ucret bulunamadi.', 'NOT_FOUND', 404);
            }

            $pdo->commit();
        } catch (HttpException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw new HttpException('Ekstra ucret silinemedi.', 'DB_DELETE_FAILED', 500, $e);
        }

        $this->response->success([
            'extraId' => $rowId,
            'extraPaymentPriceId' => $priceId,
            'extraPaymentId' => $legacyId,
            'legacyExtraPaymentId' => $legacyId,
            'deleted' => true,
            'deleted_related_sections' => [
                'ekstra_ucretler' => $priceDeleted,
                'legacy_ekstra_ucretler' => $legacyDeleted,
            ],
            'skipped_related_rows' => $skipped,
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
        $delete = $this->boolPayloadValue($payload, ['delete']);

        if ($delete) {
            $this->deleteExtraPaymentFromPayload($payload);

            return;
        }

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
                'legacy_ekstra_ucretler' => $result['legacy_updated'],
            ],
            'ekstra_ucretler' => $result['ekstra_ucretler'],
            'legacy_ekstra_ucretler' => $result['legacy_ekstra_ucretler'],
            'skipped_related_rows' => $result['skipped'],
        ]);
    }

    /**
     * Emlak ekstra ucret fiyat satirini siler.
     *
     * @Delete("ekstra-ucretler")
     * @Delete("ekstraucretler")
     * @Post("ekstra-ucretler-sil")
     * @Post("ekstra-ucretler/delete")
     * @Post("ekstraucretler-sil")
     * @Post("ekstraucretler/delete")
     * @query extraPaymentPriceId int required Ekstra ucret fiyat ID
     * @body extraPaymentPriceId int required Ekstra ucret fiyat ID
     */
    public function deleteExtraPayment(): void
    {
        $payload = $this->payload();
        $this->deleteExtraPaymentFromPayload($payload);
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
        $delete = $this->boolPayloadValue($payload, ['delete']);

        if ($delete) {
            $this->deleteDiscountFromPayload($payload);

            return;
        }

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
     * @param array<string,mixed> $payload
     */
    private function deleteDiscountFromPayload(array $payload): void
    {
        $discountId = $this->numericValue($payload, ['indirimId', 'discountId']);

        if ($discountId <= 0) {
            throw new HttpException('Lutfen gecerli bir indirim ID gonderin.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare('DELETE FROM indirimler WHERE id = :discountId');
        $stmt->execute([
            ':discountId' => $discountId,
        ]);

        if ($stmt->rowCount() <= 0) {
            throw new HttpException('Secilen indirim bulunamadi.', 'NOT_FOUND', 404);
        }

        $this->response->success([
            'id' => $discountId,
            'deleted' => true,
            'deleted_related_sections' => [
                'indirimler' => 1,
            ],
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
                     'typeid',
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
                     'startDate',
                     'endDate',
                     'start_date',
                     'end_date',
                     'baslangicTarihi',
                     'bitisTarihi',
                     'fiyat',
                     'value',
                     'amount',
                     'currencyId',
                     'currencyid',
                     'paraBirimi',
                     'currency_id',
                     'fiyatTipi',
                     'fiyattipi',
                     'fiyat_tipi',
                     'priceType',
                     'pricetype',
                     'title',
                     'baslik',
                     'description',
                     'aciklama',
                     'included',
                     'isIncluded',
                     'IsIncluded',
                     'isOptional',
                     'IsOptional',
                     'optional',
                     'durum',
                     'status',
                     'Type',
                     'type',
                     'ChargeNightLimit',
                     'chargeNightLimit',
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
            $seasonId = $this->numericValue($row, ['id', 'seasonId', 'sezonId']);
            $existingSeason = $seasonId > 0 ? $this->seasonRow($pdo, $homeId, $seasonId) : [];
            if ($seasonId > 0 && $existingSeason === []) {
                $skipped[] = [
                    'section' => 'sezonlar',
                    'index' => (string) $index,
                    'reason' => 'Secilen sezon bulunamadi: ' . $seasonId,
                ];
                continue;
            }

            $startDate = $this->normalizeDate($this->firstPayloadValue($row, ['tarih1', 'baslangicTarihi', 'startDate', 'start_date']));
            $endDate = $this->normalizeDate($this->firstPayloadValue($row, ['tarih2', 'bitisTarihi', 'endDate', 'end_date']));
            $price = $this->priceValue($row);
            $minStay = $this->numericValue($row, ['minKonaklama', 'mingece', 'min_gece', 'gece']);

            if ($existingSeason !== []) {
                $startDate = $startDate !== '' ? $startDate : $this->normalizeDate($existingSeason['tarih1'] ?? '');
                $endDate = $endDate !== '' ? $endDate : $this->normalizeDate($existingSeason['tarih2'] ?? '');
                $price = $price > 0 ? $price : (float) ($existingSeason['fiyat'] ?? 0);
                $minStay = $minStay > 0 ? $minStay : (int) ($existingSeason['gece'] ?? 0);
            }

            if ($startDate === '' || $endDate === '' || $price <= 0 || $minStay <= 0) {
                $skipped[] = [
                    'section' => 'sezonlar',
                    'index' => (string) $index,
                    'reason' => 'tarih1, tarih2, fiyat ve minKonaklama zorunlu.',
                ];
                continue;
            }

            $seasonTitle = $this->normalizeScalar($this->firstPayloadValue($row, ['aciklama', 'sezon', 'title', 'baslik']));
            if ($existingSeason !== [] && $seasonTitle === '') {
                $seasonTitle = $this->normalizeScalar($existingSeason['sezon'] ?? '');
            }

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
     * @return array{updated:int,legacy_updated:int,ekstra_ucretler:array<int,array<string,mixed>>,legacy_ekstra_ucretler:array<int,array<string,mixed>>,skipped:array<int,array<string,string>>}
     */
    private function updateExtraPayments(PDO $pdo, int $homeId, array $rows): array
    {
        if (!$this->tableExists($pdo, 'dbo', 'HomesExtraPaymentPrices')) {
            throw new HttpException('Ekstra ucret tablolari kurulu degil. Once setup/collums veya setup/extra-payments calistirin.', 'SETUP_REQUIRED', 500);
        }

        $updated = 0;
        $legacyUpdated = 0;
        $extraPaymentRows = [];
        $legacyExtraPaymentRows = [];
        $skipped = [];
        $hasLegacyTable = $this->tableExists($pdo, 'dbo', 'HomesExtraPayments');

        foreach ($rows as $index => $row) {
            $type = $this->extraPaymentType($pdo, $row);
            $value = $this->moneyPayloadValue($row, ['fiyat', 'value', 'amount', 'tutar']);
            $currencyId = $this->currencyId($row);
            $priceType = $this->extraPaymentPriceType($row);
            $seasonIds = $this->seasonIds($row);
            $startDate = $this->normalizeDate($this->firstPayloadValue($row, ['tarih1', 'baslangicTarihi', 'startDate', 'start_date']));
            $endDate = $this->normalizeDate($this->firstPayloadValue($row, ['tarih2', 'bitisTarihi', 'endDate', 'end_date']));

            if ($type === [] || $value <= 0) {
                $skipped[] = [
                    'section' => 'ekstra_ucretler',
                    'index' => (string) $index,
                    'reason' => 'tip ve fiyat zorunlu.',
                ];
                continue;
            }
            $description = $this->extraPaymentDescription($row, $type);

            if ($startDate !== '' && $endDate !== '') {
                $seasonIds = $this->prepareSeasonsForExtraPaymentRange($pdo, $homeId, $startDate, $endDate);
            }

            if ($seasonIds === []) {
                $skipped[] = [
                    'section' => 'ekstra_ucretler',
                    'index' => (string) $index,
                    'reason' => 'seasonIds veya tarih araligina denk gelen sezon zorunlu.',
                ];
                continue;
            }

            $rowUpdated = 0;
            $legacyStartDate = '';
            $legacyEndDate = '';
            $usesSeasonColumn = $this->extraPaymentUsesSeasonColumn($type);
            $singlePriceRowForRange = $startDate !== '' && $endDate !== '' && count($seasonIds) > 1;

            if ($singlePriceRowForRange) {
                $this->updateExtraPaymentTypeIncluded($pdo, (int) $type['id'], $row);
                $this->deactivateSeasonExtraPaymentPrices($pdo, $homeId, (int) $type['id'], $startDate, $endDate);
                $extraPaymentRows[] = $this->upsertExtraPaymentPrice($pdo, $homeId, 0, (int) $type['id'], $startDate, $endDate, $value, $currencyId, $priceType, $description, $usesSeasonColumn);
                $updated++;
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
                if ($startDate !== '' && $endDate !== '') {
                    $rowStart = $this->maxDate($startDate, $this->normalizeDate($season['tarih1'] ?? ''));
                    $rowEnd = $this->minDate($endDate, $this->normalizeDate($season['tarih2'] ?? ''));
                }
                if (!$singlePriceRowForRange) {
                    $this->updateExtraPaymentTypeIncluded($pdo, (int) $type['id'], $row);
                    $extraPaymentRows[] = $this->upsertExtraPaymentPrice($pdo, $homeId, $seasonId, (int) $type['id'], $rowStart, $rowEnd, $value, $currencyId, $priceType, $description, $usesSeasonColumn);
                    $updated++;
                }

                $this->updateSeasonExtraPaymentColumn($pdo, $homeId, $seasonId, $type, $value);

                $rowUpdated++;
                $legacyStartDate = $legacyStartDate === '' ? $rowStart : $this->minDate($legacyStartDate, $rowStart);
                $legacyEndDate = $legacyEndDate === '' ? $rowEnd : $this->maxDate($legacyEndDate, $rowEnd);
            }

            if (!$usesSeasonColumn && $hasLegacyTable && $rowUpdated > 0 && $legacyStartDate !== '' && $legacyEndDate !== '') {
                $legacyExtraPaymentRows[] = $this->upsertLegacyExtraPayment($pdo, $homeId, $row, $type, $legacyStartDate, $legacyEndDate, $value, $currencyId, $priceType, $description);
                $legacyUpdated++;
            }
        }

        return [
            'updated' => $updated,
            'legacy_updated' => $legacyUpdated,
            'ekstra_ucretler' => array_values(array_filter($extraPaymentRows)),
            'legacy_ekstra_ucretler' => array_values(array_filter($legacyExtraPaymentRows)),
            'skipped' => $skipped,
        ];
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
     * @return array{id:int,code:string,name:string,home_column_base:string,is_included:int}
     */
    private function extraPaymentType(PDO $pdo, array $row): array
    {
        $typeId = $this->numericValue($row, ['extraPaymentTypeId', 'typeId', 'typeid', 'tipId']);
        if ($typeId > 0) {
            $stmt = $pdo->prepare(
                'SELECT ExtraPaymentTypeId, Code, Name, HomeColumnBase, IsIncluded
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
                'SELECT ExtraPaymentTypeId, Code, Name, HomeColumnBase, IsIncluded
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
            'name' => (string) ($type['Name'] ?? ''),
            'home_column_base' => strtolower((string) ($type['HomeColumnBase'] ?? '')),
            'is_included' => (int) ($type['IsIncluded'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function updateExtraPaymentTypeIncluded(PDO $pdo, int $typeId, array $row): void
    {
        foreach (['included', 'isIncluded', 'IsIncluded'] as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }

            $stmt = $pdo->prepare(
                'UPDATE dbo.HomesExtraPaymentTypes
                 SET IsIncluded = :isIncluded
                 WHERE ExtraPaymentTypeId = :typeId AND IsDeleted = 0'
            );
            $stmt->execute([
                ':typeId' => $typeId,
                ':isIncluded' => $this->boolPayloadValue($row, [$key]) ? 1 : 0,
            ]);

            return;
        }
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
    private function prepareSeasonsForExtraPaymentRange(PDO $pdo, int $homeId, string $startDate, string $endDate): array
    {
        $stmt = $pdo->prepare(
            "SELECT id,
                    tarih1,
                    tarih2
             FROM sezonlar
             WHERE islem_id = :homeId
               AND islem = 'emlak'
               AND CONVERT(date, tarih1, 104) <= CONVERT(date, :endDate, 104)
               AND CONVERT(date, tarih2, 104) >= CONVERT(date, :startDate, 104)
             ORDER BY CONVERT(date, tarih1, 104) ASC, id ASC"
        );
        $stmt->execute([
            ':homeId' => $homeId,
            ':startDate' => $startDate,
            ':endDate' => $endDate,
        ]);

        $seasonIds = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $season) {
            $seasonIds = array_merge($seasonIds, $this->splitSeasonForExtraPaymentRange($pdo, $season, $startDate, $endDate));
        }

        return array_values(array_unique(array_map('intval', $seasonIds)));
    }

    /**
     * @param array<string,mixed> $season
     * @return array<int,int>
     */
    private function splitSeasonForExtraPaymentRange(PDO $pdo, array $season, string $startDate, string $endDate): array
    {
        $seasonId = (int) ($season['id'] ?? 0);
        $seasonStart = $this->normalizeDate($season['tarih1'] ?? '');
        $seasonEnd = $this->normalizeDate($season['tarih2'] ?? '');
        if ($seasonId <= 0 || $seasonStart === '' || $seasonEnd === '') {
            return [];
        }

        $overlapStart = $this->maxDate($seasonStart, $startDate);
        $overlapEnd = $this->minDate($seasonEnd, $endDate);
        if ($this->compareDates($overlapStart, $overlapEnd) > 0) {
            return [];
        }

        if ($overlapStart === $seasonStart && $overlapEnd === $seasonEnd) {
            return [$seasonId];
        }

        if ($this->compareDates($seasonStart, $overlapStart) < 0) {
            $this->updateSeasonDateRange($pdo, $seasonId, $seasonStart, $this->dateAdd($overlapStart, -1));
            $targetSeasonId = $this->copySeasonWithDateRange($pdo, $seasonId, $overlapStart, $overlapEnd);
        } else {
            $this->updateSeasonDateRange($pdo, $seasonId, $overlapStart, $overlapEnd);
            $targetSeasonId = $seasonId;
        }

        if ($this->compareDates($overlapEnd, $seasonEnd) < 0) {
            $this->copySeasonWithDateRange($pdo, $seasonId, $this->dateAdd($overlapEnd, 1), $seasonEnd);
        }

        return $targetSeasonId > 0 ? [$targetSeasonId] : [];
    }

    private function updateSeasonDateRange(PDO $pdo, int $seasonId, string $startDate, string $endDate): void
    {
        $columns = $this->sezonlarColumns($pdo);
        $dateSet = '';
        if (isset($columns['tarih1_date'])) {
            $dateSet .= ', tarih1_date = CONVERT(date, :tarih1Date, 104)';
        }
        if (isset($columns['tarih2_date'])) {
            $dateSet .= ', tarih2_date = CONVERT(date, :tarih2Date, 104)';
        }

        $stmt = $pdo->prepare(
            "UPDATE sezonlar
             SET tarih1 = :tarih1,
                 tarih2 = :tarih2
                 {$dateSet}
             WHERE id = :id"
        );
        $params = [
            ':id' => $seasonId,
            ':tarih1' => $startDate,
            ':tarih2' => $endDate,
        ];
        if (isset($columns['tarih1_date'])) {
            $params[':tarih1Date'] = $startDate;
        }
        if (isset($columns['tarih2_date'])) {
            $params[':tarih2Date'] = $endDate;
        }
        $stmt->execute($params);
    }

    private function copySeasonWithDateRange(PDO $pdo, int $sourceSeasonId, string $startDate, string $endDate): int
    {
        $availableColumns = $this->sezonlarColumns($pdo);
        $copyColumns = [];
        foreach ([
                     'site',
                     'islem',
                     'islem_id',
                     'fiyat',
                     'gece',
                     'sezon',
                     'temizlikgece',
                     'temizlikFiyat',
                     'isitmaFiyat',
                     'isitmaHizmetDisi',
                 ] as $column) {
            if (isset($availableColumns[strtolower($column)])) {
                $copyColumns[] = $column;
            }
        }

        $insertColumns = ['tarih1', 'tarih2'];
        $selectColumns = [':tarih1', ':tarih2'];
        if (isset($availableColumns['tarih1_date'])) {
            $insertColumns[] = 'tarih1_date';
            $selectColumns[] = 'CONVERT(date, :tarih1Date, 104)';
        }
        if (isset($availableColumns['tarih2_date'])) {
            $insertColumns[] = 'tarih2_date';
            $selectColumns[] = 'CONVERT(date, :tarih2Date, 104)';
        }
        $insertColumns = array_merge($insertColumns, $copyColumns);
        $selectColumns = array_merge($selectColumns, array_map(function (string $column): string {
            return '[' . $column . ']';
        }, $copyColumns));

        $stmt = $pdo->prepare(
            'INSERT INTO sezonlar ([' . implode('], [', $insertColumns) . '])
             OUTPUT INSERTED.id
             SELECT ' . implode(', ', $selectColumns) . '
             FROM sezonlar
             WHERE id = :id'
        );
        $params = [
            ':id' => $sourceSeasonId,
            ':tarih1' => $startDate,
            ':tarih2' => $endDate,
        ];
        if (isset($availableColumns['tarih1_date'])) {
            $params[':tarih1Date'] = $startDate;
        }
        if (isset($availableColumns['tarih2_date'])) {
            $params[':tarih2Date'] = $endDate;
        }
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string,bool>
     */
    private function sezonlarColumns(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'sezonlar'");
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
            $columns[strtolower((string) $column)] = true;
        }

        return $columns;
    }

    private function maxDate(string $left, string $right): string
    {
        if ($right === '') {
            return $left;
        }
        if ($left === '') {
            return $right;
        }

        return $this->compareDates($left, $right) >= 0 ? $left : $right;
    }

    private function minDate(string $left, string $right): string
    {
        if ($right === '') {
            return $left;
        }
        if ($left === '') {
            return $right;
        }

        return $this->compareDates($left, $right) <= 0 ? $left : $right;
    }

    private function compareDates(string $left, string $right): int
    {
        $leftDate = \DateTime::createFromFormat('d.m.Y', $left);
        $rightDate = \DateTime::createFromFormat('d.m.Y', $right);
        if (!$leftDate instanceof \DateTime || !$rightDate instanceof \DateTime) {
            return strcmp($left, $right);
        }

        return $leftDate <=> $rightDate;
    }

    private function dateAdd(string $date, int $days): string
    {
        $dateTime = \DateTime::createFromFormat('d.m.Y', $date);
        if (!$dateTime instanceof \DateTime) {
            return $date;
        }

        $dateTime->modify(($days >= 0 ? '+' : '') . $days . ' day');

        return $dateTime->format('d.m.Y');
    }

    /**
     * @return array<string,mixed>
     */
    private function seasonRow(PDO $pdo, int $homeId, int $seasonId): array
    {
        $stmt = $pdo->prepare(
            "SELECT id, tarih1, tarih2, fiyat, gece, sezon
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

    private function deleteLegacyExtraPaymentById(PDO $pdo, int $legacyId): int
    {
        if (!$this->tableExists($pdo, 'dbo', 'HomesExtraPayments')) {
            return 0;
        }

        $stmt = $pdo->prepare('DELETE FROM dbo.HomesExtraPayments WHERE id = :legacyId');
        $stmt->execute([':legacyId' => $legacyId]);

        return $stmt->rowCount();
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
        string $description,
        bool $updateExisting
    ): array {
        if ($updateExisting) {
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
                return $this->extraPaymentPriceRow($pdo, $homeId, $seasonId, $typeId, $startDate, $endDate, $priceType);
            }
        } elseif ($this->extraPaymentPriceExists($pdo, $homeId, $seasonId, $typeId, $startDate, $endDate, $priceType)) {
            return $this->extraPaymentPriceRow($pdo, $homeId, $seasonId, $typeId, $startDate, $endDate, $priceType);
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

        return $this->extraPaymentPriceRow($pdo, $homeId, $seasonId, $typeId, $startDate, $endDate, $priceType);
    }

    private function extraPaymentPriceExists(
        PDO $pdo,
        int $homeId,
        int $seasonId,
        int $typeId,
        string $startDate,
        string $endDate,
        string $priceType
    ): bool {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM dbo.HomesExtraPaymentPrices
             WHERE HomesId = :homeId
               AND SeasonId = :seasonId
               AND ExtraPaymentTypeId = :typeId
               AND StartDate = CONVERT(date, :startDate, 104)
               AND EndDate = CONVERT(date, :endDate, 104)
               AND PriceType = :priceType
               AND IsDeleted = 0"
        );
        $stmt->execute([
            ':homeId' => $homeId,
            ':seasonId' => $seasonId,
            ':typeId' => $typeId,
            ':startDate' => $startDate,
            ':endDate' => $endDate,
            ':priceType' => $priceType,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @return array<string,mixed>
     */
    private function extraPaymentPriceRow(
        PDO $pdo,
        int $homeId,
        int $seasonId,
        int $typeId,
        string $startDate,
        string $endDate,
        string $priceType
    ): array {
        $idSelect = $this->columnExists($pdo, 'dbo', 'HomesExtraPaymentPrices', 'id')
            ? '[id] AS id'
            : 'CAST(NULL AS int) AS id';

        $stmt = $pdo->prepare(
            "SELECT TOP 1
                    {$idSelect},
                    ExtraPaymentPriceId AS extraPaymentPriceId,
                    HomesId AS homesId,
                    SeasonId AS seasonId,
                    ExtraPaymentTypeId AS extraPaymentTypeId,
                    StartDate AS startDate,
                    EndDate AS endDate,
                    CurrencyId AS currencyId,
                    PriceType AS priceType,
                    Value AS value,
                    Description AS description
             FROM dbo.HomesExtraPaymentPrices
             WHERE HomesId = :homeId
               AND SeasonId = :seasonId
               AND ExtraPaymentTypeId = :typeId
               AND StartDate = CONVERT(date, :startDate, 104)
               AND EndDate = CONVERT(date, :endDate, 104)
               AND PriceType = :priceType
               AND IsDeleted = 0
             ORDER BY ExtraPaymentPriceId DESC"
        );
        $stmt->execute([
            ':homeId' => $homeId,
            ':seasonId' => $seasonId,
            ':typeId' => $typeId,
            ':startDate' => $startDate,
            ':endDate' => $endDate,
            ':priceType' => $priceType,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    private function deactivateSeasonExtraPaymentPrices(PDO $pdo, int $homeId, int $typeId, string $startDate, string $endDate): void
    {
        $stmt = $pdo->prepare(
            "UPDATE dbo.HomesExtraPaymentPrices
             SET IsDeleted = 1,
                 UpdatedOn = GETDATE()
             WHERE HomesId = :homeId
               AND ExtraPaymentTypeId = :typeId
               AND ISNULL(SeasonId, 0) <> 0
               AND StartDate <= CONVERT(date, :endDate, 104)
               AND EndDate >= CONVERT(date, :startDate, 104)"
        );
        $stmt->execute([
            ':homeId' => $homeId,
            ':typeId' => $typeId,
            ':startDate' => $startDate,
            ':endDate' => $endDate,
        ]);
    }

    /**
     * @param array<string,mixed> $row
     * @param array{id:int,code:string,name:string,home_column_base:string,is_included:int} $type
     */
    private function extraPaymentDescription(array $row, array $type): string
    {
        $description = $this->normalizeScalar($this->firstPayloadValue($row, ['title', 'baslik', 'description', 'aciklama']));
        if ($description !== '') {
            return (string) $description;
        }

        return $type['name'] !== '' ? $type['name'] : $type['code'];
    }

    /**
     * @param array<string,mixed> $row
     * @param array{id:int,code:string,name:string,home_column_base:string,is_included:int} $type
     */
    private function upsertLegacyExtraPayment(
        PDO $pdo,
        int $homeId,
        array $row,
        array $type,
        string $startDate,
        string $endDate,
        float $value,
        int $currencyId,
        string $priceType,
        string $description
    ): array {
        $title = $this->normalizeScalar($this->firstPayloadValue($row, ['title', 'baslik', 'description', 'aciklama']));
        if ($title === '') {
            $title = $type['name'] !== '' ? $type['name'] : $type['code'];
        }

        $legacyType = $this->legacyExtraPaymentType($row, $priceType);
        $isOptional = $this->legacyExtraPaymentIsOptional($row, (int) $type['is_included']);
        $amount = (int) round($value);
        $chargeNightLimit = $this->numericValue($row, ['ChargeNightLimit', 'chargeNightLimit']);

        $stmt = $pdo->prepare(
            "UPDATE dbo.HomesExtraPayments
             SET amount = :amount,
                 price = :price,
                 CurrencyId = :currencyId,
                 IsOptional = :isOptional,
                 ChargeNightLimit = :chargeNightLimit
             WHERE homesId = :homeId
               AND title = :title
               AND Type = :type
               AND start_date = CONVERT(date, :startDate, 104)
               AND end_date = CONVERT(date, :endDate, 104)"
        );
        $stmt->execute([
            ':homeId' => $homeId,
            ':title' => $title,
            ':type' => $legacyType,
            ':startDate' => $startDate,
            ':endDate' => $endDate,
            ':amount' => $amount,
            ':price' => $amount,
            ':currencyId' => $currencyId > 0 ? $currencyId : null,
            ':isOptional' => $isOptional,
            ':chargeNightLimit' => $chargeNightLimit > 0 ? $chargeNightLimit : null,
        ]);

        if ($stmt->rowCount() > 0) {
            return $this->legacyExtraPaymentRow($pdo, $homeId, $title, $legacyType, $startDate, $endDate);
        }

        $stmt = $pdo->prepare(
            "INSERT INTO dbo.HomesExtraPayments
                (amount, start_date, end_date, price, homesId, title, CurrencyId, Type, IsOptional, ChargeNightLimit)
             VALUES
                (:amount, CONVERT(date, :startDate, 104), CONVERT(date, :endDate, 104), :price, :homeId, :title, :currencyId, :type, :isOptional, :chargeNightLimit)"
        );
        $stmt->execute([
            ':homeId' => $homeId,
            ':title' => $title,
            ':type' => $legacyType,
            ':startDate' => $startDate,
            ':endDate' => $endDate,
            ':amount' => $amount,
            ':price' => $amount,
            ':currencyId' => $currencyId > 0 ? $currencyId : null,
            ':isOptional' => $isOptional,
            ':chargeNightLimit' => $chargeNightLimit > 0 ? $chargeNightLimit : null,
        ]);

        return $this->legacyExtraPaymentRow($pdo, $homeId, $title, $legacyType, $startDate, $endDate);
    }

    /**
     * @return array<string,mixed>
     */
    private function legacyExtraPaymentRow(
        PDO $pdo,
        int $homeId,
        string $title,
        int $type,
        string $startDate,
        string $endDate
    ): array {
        $stmt = $pdo->prepare(
            "SELECT TOP 1
                    id AS extraPaymentId,
                    homesId,
                    title,
                    Type AS type,
                    start_date AS startDate,
                    end_date AS endDate,
                    amount,
                    price,
                    CurrencyId AS currencyId,
                    IsOptional AS isOptional,
                    ChargeNightLimit AS chargeNightLimit
             FROM dbo.HomesExtraPayments
             WHERE homesId = :homeId
               AND title = :title
               AND Type = :type
               AND start_date = CONVERT(date, :startDate, 104)
               AND end_date = CONVERT(date, :endDate, 104)
             ORDER BY id DESC"
        );
        $stmt->execute([
            ':homeId' => $homeId,
            ':title' => $title,
            ':type' => $type,
            ':startDate' => $startDate,
            ':endDate' => $endDate,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function legacyExtraPaymentType(array $row, string $priceType): int
    {
        foreach (['Type', 'type'] as $key) {
            if (array_key_exists($key, $row) && is_numeric($row[$key])) {
                return (int) $row[$key] === 1 ? 1 : 0;
            }
        }

        return in_array(strtolower(trim($priceType)), [
            'tekseferlik',
            'tek_seferlik',
            'tek-seferlik',
            'single',
            'one_time',
            'one-time',
            'onetime',
            'konaklama',
            'stay',
        ], true) ? 1 : 0;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function legacyExtraPaymentIsOptional(array $row, int $typeIsIncluded): int
    {
        foreach (['durum', 'status'] as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }

            $value = strtolower(trim((string) $row[$key]));
            if (in_array($value, ['zorunlu', 'required', 'mandatory', '1', 'true'], true)) {
                return 1;
            }
            if (in_array($value, ['opsiyonel', 'optional', '0', 'false'], true)) {
                return 0;
            }
        }

        foreach (['included', 'isIncluded', 'IsIncluded'] as $key) {
            if (array_key_exists($key, $row)) {
                return $this->boolPayloadValue($row, [$key]) ? 0 : 1;
            }
        }

        foreach (['isOptional', 'IsOptional', 'optional'] as $key) {
            if (array_key_exists($key, $row)) {
                return $this->boolPayloadValue($row, [$key]) ? 1 : 0;
            }
        }

        return $typeIsIncluded === 1 ? 0 : 1;
    }

    /**
     * @param array{id:int,code:string,name:string,home_column_base:string,is_included:int} $type
     */
    private function extraPaymentUsesSeasonColumn(array $type): bool
    {
        return $type['home_column_base'] !== '';
    }

    /**
     * @param array{id:int,code:string,name:string,home_column_base:string,is_included:int} $type
     */
    private function updateSeasonExtraPaymentColumn(PDO $pdo, int $homeId, int $seasonId, array $type, float $value): void
    {
        $column = $this->seasonExtraPaymentColumn($pdo, $type);
        if ($column === '') {
            return;
        }

        $stmt = $pdo->prepare(
            "UPDATE sezonlar
             SET [{$column}] = :value
             WHERE id = :seasonId AND islem_id = :homeId AND islem = 'emlak'"
        );
        $stmt->execute([
            ':value' => $value,
            ':seasonId' => $seasonId,
            ':homeId' => $homeId,
        ]);
    }

    /**
     * @param array{id:int,code:string,name:string,home_column_base:string,is_included:int} $type
     */
    private function seasonExtraPaymentColumn(PDO $pdo, array $type): string
    {
        $key = $type['home_column_base'] !== '' ? $type['home_column_base'] : $type['code'];
        $map = [
            'temizlik' => 'temizlikFiyat',
            'elektrik' => 'isitmaFiyat',
            'elektriksu' => 'isitmaFiyat',
            'elektrik-su' => 'isitmaFiyat',
            'elektrik_su' => 'isitmaFiyat',
        ];
        $column = $map[$key] ?? '';
        if ($column === '') {
            return '';
        }

        return isset($this->sezonlarColumns($pdo)[strtolower($column)]) ? $column : '';
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
        $value = $this->firstPayloadValue($row, ['currencyId', 'currencyid', 'paraBirimi', 'currency_id']);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function extraPaymentPriceType(array $row): string
    {
        $value = strtolower(trim((string) $this->firstPayloadValue($row, ['fiyatTipi', 'fiyattipi', 'fiyat_tipi', 'priceType', 'pricetype'])));
        $map = [
            'gunluk' => 'gunluk',
            'daily' => 'gunluk',
            'day' => 'gunluk',
            'gecelik' => 'gecelik',
            'nightly' => 'gecelik',
            'night' => 'gecelik',
            'haftalik' => 'haftalik',
            'weekly' => 'haftalik',
            'week' => 'haftalik',
            'aylik' => 'aylik',
            'monthly' => 'aylik',
            'month' => 'aylik',
            'tekseferlik' => 'tekseferlik',
            'tek_seferlik' => 'tekseferlik',
            'tek-seferlik' => 'tekseferlik',
            'single' => 'tekseferlik',
            'one_time' => 'tekseferlik',
            'one-time' => 'tekseferlik',
            'onetime' => 'tekseferlik',
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

    private function columnExists(PDO $pdo, string $schema, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = :schema
               AND TABLE_NAME = :table
               AND COLUMN_NAME = :column'
        );
        $stmt->execute([
            ':schema' => $schema,
            ':table' => $table,
            ':column' => $column,
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
     */
    private function boolPayloadValue(array $row, array $keys): bool
    {
        $value = $this->firstPayloadValue($row, $keys);
        if (is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim((string) $value));

        return in_array($value, ['1', 'true', 'yes', 'on', 'evet'], true);
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
     * @param array<string,mixed> $row
     * @param array<int,string> $keys
     * @return int|float|string
     */
    private function firstNonEmptyValue(array $row, array $keys)
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }

            $value = $this->normalizeScalar($row[$key]);
            if ($value !== '') {
                return $value;
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
