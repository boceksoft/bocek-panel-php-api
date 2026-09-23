<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Emlak mesafe kayitlarini detail update akisi disinda ayri endpoint'ten yonetir.
 *
 * Endpointler:
 *   POST|PUT /backend-api/homes-management-distance?homesId={emlakId}&mesafelerId={mesafeTipiId}&aciklama={aciklama}&deger={deger}
 *   POST|PUT /backend-api/homes-management-distance?id={mesafeSatirId}&homesId={emlakId}&mesafelerId={mesafeTipiId}&aciklama={aciklama}&deger={deger}
 *   POST|PUT /backend-api/homes-management-distance?delete=1&id={mesafeSatirId}
 */
final class HomesManagementDistanceController extends Controller
{
    /**
     * Emlak mesafesini ekler veya gunceller.
     *
     * @Post
     * @Put
     * @query homesId int required Emlak ID
     * @query id int Mesafe satir ID. Gonderilirse update yapilir.
     * @query mesafelerId int required Mesafe tipi ID
     * @query deger string Mesafe degeri
     * @query aciklama string Aciklama
     * @query distanceId int Guncellenecek mesafe satir ID
     * @query delete int 1 ise mesafe satirini siler
     */
    public function distance(): void
    { 
        $payload = $this->payload();
        $homeId = $this->resolveId($payload);
        $delete = $this->boolPayloadValue($payload, ['delete']);

        if ($delete) {
            $this->deleteDistanceFromPayload($payload);

            return;
        }

        if ($homeId <= 0) {
            throw new HttpException('Lutfen gecerli bir emlak ID gonderin.', 'VALIDATION', 422);
        }

        $rows = $this->distancePayload($payload);
        if ($rows === []) {
            throw new HttpException('Eklenecek veya guncellenecek mesafe bulunamadi.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();
        $this->assertHomeExists($pdo, $homeId);
        $this->assertDistanceTablesReady($pdo);

        $pdo->beginTransaction();
        try {
            $result = $this->saveDistances($pdo, $homeId, $rows);
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

            throw new HttpException('Mesafe bilgileri kaydedilemedi.', 'DB_UPDATE_FAILED', 500, $e);
        }

        $this->response->success([
            'id' => $homeId,
            'updated' => $result['updated'] > 0,
            'updated_related_sections' => [
                'mesafeler' => $result['updated'],
            ],
            'mesafeler' => $this->listDistances($pdo, $homeId),
            'skipped_related_rows' => $result['skipped'],
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function deleteDistanceFromPayload(array $payload): void
    {
        $distanceId = $this->numericValue($payload, ['distanceId', 'mesafeId', 'id']);

        if ($distanceId <= 0) {
            throw new HttpException('Lutfen gecerli bir mesafe ID gonderin.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();
        $this->assertDistanceTablesReady($pdo);
        $homeId = $this->resolveId($payload);
        if ($homeId <= 0) {
            $homeId = $this->distanceHomeId($pdo, $distanceId);
        }

        $stmt = $pdo->prepare('DELETE FROM dbo.mesafelerValues WHERE id = :distanceId');
        $stmt->execute([
            ':distanceId' => $distanceId,
        ]);

        if ($stmt->rowCount() <= 0) {
            throw new HttpException('Secilen mesafe bulunamadi.', 'NOT_FOUND', 404);
        }

        $this->response->success([
            'id' => $distanceId,
            'deleted' => true,
            'deleted_related_sections' => [
                'mesafeler' => 1,
            ],
            'mesafeler' => $homeId > 0 ? $this->listDistances($pdo, $homeId) : [],
        ]);
    }

    /**
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
        $id = $payload['homesId'] ?? $payload['homeId'] ?? $this->request->query('homesId', 0);

        return is_numeric($id) ? (int) $id : 0;
    }

    private function assertHomeExists(PDO $pdo, int $id): void
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM dbo.homes WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        if ((int) $stmt->fetchColumn() === 0) {
            throw new HttpException('Belirtilen ID ile emlak bulunamadi.', 'NOT_FOUND', 404);
        }
    }

    private function assertDistanceTablesReady(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'dbo', 'mesafelerValues') || !$this->tableExists($pdo, 'dbo', 'mesafeler')) {
            throw new HttpException('Mesafe tablolari bulunamadi.', 'SETUP_REQUIRED', 500);
        }

        foreach (['id', 'homesId', 'mesafelerId', 'aciklama', 'deger'] as $column) {
            if (!$this->columnExists($pdo, 'dbo', 'mesafelerValues', $column)) {
                throw new HttpException('mesafelerValues tablosunda ' . $column . ' kolonu bulunamadi.', 'SETUP_REQUIRED', 500);
            }
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    private function distancePayload(array $payload): array
    {
        $single = [];
        foreach (['id', 'distanceId', 'mesafeId', 'mesafelerId', 'mesafeler_id', 'meid', 'deger', 'aciklama', 'Mesafe', 'mesafe', 'value', 'Aciklama'] as $key) {
            if (array_key_exists($key, $_GET)) {
                $single[$key] = $_GET[$key];
            }
        }
        if ($single !== []) {
            return [$single];
        }

        $value = null;
        if ($this->hasPath($payload, 'mesafeler')) {
            $value = $this->getPath($payload, 'mesafeler');
        }
        if ($this->hasPath($payload, 'konumBilgileri.mesafeler')) {
            $value = $this->getPath($payload, 'konumBilgileri.mesafeler');
        }

        if (is_array($value)) {
            return $this->isList($value) ? array_values(array_filter($value, 'is_array')) : [$value];
        }

        foreach (['id', 'distanceId', 'mesafeId', 'mesafelerId', 'mesafeler_id', 'meid', 'deger', 'aciklama', 'Mesafe', 'mesafe', 'value', 'Aciklama'] as $key) {
            if (array_key_exists($key, $payload)) {
                $single[$key] = $payload[$key];
            }
        }

        return $single === [] ? [] : [$single];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array{updated:int,skipped:array<int,array<string,string>>}
     */
    private function saveDistances(PDO $pdo, int $homeId, array $rows): array
    {
        $updated = 0;
        $skipped = [];

        foreach ($rows as $index => $row) {
            $rowId = $this->numericValue($row, ['id', 'distanceId', 'mesafeId']);
            $distanceTypeId = $this->numericValue($row, ['mesafelerId', 'mesafeler_id', 'meid']);
            $distance = $this->stringValue($row, ['deger', 'Mesafe', 'mesafe', 'value']);
            $description = $this->stringValue($row, ['aciklama', 'Aciklama']);

            if ($rowId > 0) {
                if ($distanceTypeId <= 0) {
                    $distanceTypeId = $this->existingDistanceTypeId($pdo, $rowId);
                }

                if ($distanceTypeId <= 0) {
                    $skipped[] = [
                        'section' => 'mesafeler',
                        'index' => (string) $index,
                        'reason' => 'Secilen mesafe bulunamadi: ' . $rowId,
                    ];
                    continue;
                }

                if (!$this->distanceTypeExists($pdo, $distanceTypeId)) {
                    $skipped[] = [
                        'section' => 'mesafeler',
                        'index' => (string) $index,
                        'reason' => 'Secilen mesafe tipi bulunamadi: ' . $distanceTypeId,
                    ];
                    continue;
                }

                $this->updateDistance($pdo, $rowId, $homeId, $distanceTypeId, $distance, $description);
                $updated++;
                continue;
            }

            if ($distanceTypeId <= 0) {
                $skipped[] = [
                    'section' => 'mesafeler',
                    'index' => (string) $index,
                    'reason' => 'mesafelerId zorunlu.',
                ];
                continue;
            }

            if ($distanceTypeId > 0 && !$this->distanceTypeExists($pdo, $distanceTypeId)) {
                $skipped[] = [
                    'section' => 'mesafeler',
                    'index' => (string) $index,
                    'reason' => 'Secilen mesafe tipi bulunamadi: ' . $distanceTypeId,
                ];
                continue;
            }

            $this->insertDistance($pdo, $homeId, $distanceTypeId, $distance, $description);
            $updated++;
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    private function updateDistance(
        PDO $pdo,
        int $rowId,
        int $homeId,
        int $distanceTypeId,
        string $distance,
        string $description
    ): void {
        $stmt = $pdo->prepare(
            'UPDATE dbo.mesafelerValues
             SET homesId = :homeId,
                 mesafelerId = :mesafelerId,
                 aciklama = :aciklama,
                 deger = :deger
             WHERE id = :rowId'
        );
        $stmt->execute([
            ':rowId' => $rowId,
            ':homeId' => $homeId,
            ':mesafelerId' => $distanceTypeId,
            ':aciklama' => $description,
            ':deger' => $distance,
        ]);
    }

    private function insertDistance(
        PDO $pdo,
        int $homeId,
        int $distanceTypeId,
        string $distance,
        string $description
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO dbo.mesafelerValues
                (homesId, mesafelerId, aciklama, deger)
             VALUES
                (:homeId, :mesafelerId, :aciklama, :deger)'
        );
        $stmt->execute([
            ':homeId' => $homeId,
            ':mesafelerId' => $distanceTypeId,
            ':aciklama' => $description,
            ':deger' => $distance,
        ]);
    }

    private function existingDistanceTypeId(PDO $pdo, int $rowId): int
    {
        $stmt = $pdo->prepare('SELECT mesafelerId FROM dbo.mesafelerValues WHERE id = :id');
        $stmt->execute([
            ':id' => $rowId,
        ]);

        return (int) $stmt->fetchColumn();
    }

    private function distanceHomeId(PDO $pdo, int $rowId): int
    {
        $stmt = $pdo->prepare('SELECT homesId FROM dbo.mesafelerValues WHERE id = :id');
        $stmt->execute([
            ':id' => $rowId,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function listDistances(PDO $pdo, int $homeId): array
    {
        $stmt = $pdo->prepare(
            "SELECT
                m.id,
                m.mesafelerId,
                me.baslik AS Baslik,
                m.aciklama AS Aciklama,
                m.deger AS Mesafe
             FROM dbo.mesafelerValues m
             INNER JOIN dbo.mesafeler me ON me.id = m.mesafelerId
             WHERE m.homesId = :homeId
             ORDER BY me.siralama ASC"
        );
        $stmt->execute([
            ':homeId' => $homeId,
        ]);

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'mesafeler_id' => (int) ($row['mesafelerId'] ?? 0),
                'Baslik' => $this->firstStringValue($row, ['Baslik']),
                'Aciklama' => $this->firstStringValue($row, ['Aciklama']),
                'Mesafe' => $this->firstStringValue($row, ['Mesafe']),
                'tip' => $this->firstStringValue($row, ['Baslik']),
                'mesafe' => $this->firstStringValue($row, ['Mesafe']),
                'aciklama' => $this->firstStringValue($row, ['Aciklama']),
            ];
        }

        return $items;
    }

    private function distanceTypeExists(PDO $pdo, int $distanceTypeId): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM dbo.mesafeler WHERE id = :id');
        $stmt->execute([':id' => $distanceTypeId]);

        return (int) $stmt->fetchColumn() > 0;
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
    private function stringValue(array $row, array $keys): string
    {
        $value = $this->firstPayloadValue($row, $keys);

        return $value === null || is_array($value) ? '' : trim((string) $value);
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
     */
    private function firstStringValue(array $row, array $keys): string
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
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
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
}
