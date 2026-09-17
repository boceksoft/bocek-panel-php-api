<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Emlak havuz tanimlarini detail update akisi disinda ayri endpoint'ten yonetir.
 *
 * Endpointler:
 *   POST|PUT /backend-api/homes-management-pools/havuzlar?id={emlakId}&tipId={tipId}&deger={deger}
 *   DELETE   /backend-api/homes-management-pools/havuzlar?havuzId={havuzId}
 *   POST     /backend-api/homes-management-pools/havuzlar/delete?havuzId={havuzId}
 */
final class HomesManagementPoolsController extends Controller
{
    /**
     * Emlak havuzlarini ekler veya gunceller.
     *
     * @Post("havuzlar")
     * @Put("havuzlar")
     * @query id int required Emlak ID
     * @query havuzId int Havuz ID
     * @query tipId int Havuz tipi ID
     * @query deger string Havuz degeri
     * @query havuzTipiId int Havuz ozellik tipi ID
     * @query uzunluk string Havuz uzunlugu
     * @query genislik string Havuz genisligi
     * @query derinlik string Havuz derinligi
     * @query tamKorunakli string Tam korunakli bilgisi
     * @query isitma bool Havuz isitma bilgisi
     */
    public function pools(): void
    {
        $payload = $this->payload();
        $id = $this->resolveId($payload);
        $delete = $this->boolPayloadValue($payload, ['delete']);

        if ($delete) {
            $this->deletePoolFromPayload($payload);

            return;
        }

        if ($id <= 0) {
            throw new HttpException('Lutfen gecerli bir emlak ID gonderin.', 'VALIDATION', 422);
        }

        $rows = $this->havuzlarPayload($payload);
        if ($rows === []) {
            throw new HttpException('Eklenecek veya guncellenecek havuz bulunamadi.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();
        $this->assertHomeExists($pdo, $id);
        $this->assertPoolTableReady($pdo);

        $pdo->beginTransaction();
        try {
            $result = $this->updateHavuzlar($pdo, $id, $rows);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw new HttpException('Havuz bilgileri kaydedilemedi.', 'DB_UPDATE_FAILED', 500, $e);
        }

        $this->response->success([
            'id' => $id,
            'updated' => $result['updated'] > 0,
            'updated_related_sections' => [
                'havuzlar' => $result['updated'],
            ],
            'skipped_related_rows' => $result['skipped'],
        ]);
    }

    /**
     * Emlak havuz satirini siler.
     *
     * @Delete("havuzlar")
     * @Post("havuzlar-sil")
     * @Post("havuzlar/delete")
     * @query havuzId int required Havuz ID
     */
    public function deletePool(): void
    {
        $payload = $this->payload();
        $this->deletePoolFromPayload($payload);
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

    private function assertPoolTableReady(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'dbo', 'havuztanimlamari')) {
            throw new HttpException('Havuz tablosu kurulu degil. Once setup/havuztanimlamari calistirin.', 'SETUP_REQUIRED', 500);
        }
        if (!$this->tableExists($pdo, 'dbo', 'havuztipitanimlari')) {
            throw new HttpException('Havuz tipi tablosu kurulu degil. Once setup/havuztanimlamari calistirin.', 'SETUP_REQUIRED', 500);
        }

        if (!$this->columnExists($pdo, 'dbo', 'havuztanimlamari', 'isitma')) {
            $pdo->exec('ALTER TABLE dbo.havuztanimlamari ADD isitma bit NULL');
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    private function havuzlarPayload(array $payload): array
    {
        $single = [];
        foreach ([
                     'id',
                     'havuzId',
                     'poolId',
                     'tipId',
                     'deger',
                     'value',
                     'havuzTipiId',
                     'poolTypeId',
                     'uzunluk',
                     'genislik',
                     'derinlik',
                     'tamKorunakli',
                     'isitma',
                 ] as $key) {
            if (array_key_exists($key, $_GET)) {
                $single[$key] = $_GET[$key];
            }
        }
        if ($single !== []) {
            return [$single];
        }

        $value = null;
        if ($this->hasPath($payload, 'havuzlar')) {
            $value = $this->getPath($payload, 'havuzlar');
        }
        if ($this->hasPath($payload, 'ozelliklerVeOlanaklar.havuzlar')) {
            $value = $this->getPath($payload, 'ozelliklerVeOlanaklar.havuzlar');
        }

        if (is_array($value)) {
            if ($value === []) {
                return [];
            }

            return $this->isList($value) ? array_values(array_filter($value, 'is_array')) : [$value];
        }
        foreach ([
                     'id',
                     'havuzId',
                     'poolId',
                     'tipId',
                     'deger',
                     'value',
                     'havuzTipiId',
                     'poolTypeId',
                     'uzunluk',
                     'genislik',
                     'derinlik',
                     'tamKorunakli',
                     'isitma',
                 ] as $key) {
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
    private function updateHavuzlar(PDO $pdo, int $homeId, array $rows): array
    {
        $updated = 0;
        $skipped = [];

        foreach ($rows as $index => $row) {
            $poolId = $this->numericValue($row, ['id', 'havuzId', 'poolId']);
            $existingPool = $poolId > 0 ? $this->poolRowById($pdo, $homeId, $poolId) : [];
            if ($poolId > 0 && $existingPool === []) {
                $skipped[] = [
                    'section' => 'havuzlar',
                    'index' => (string) $index,
                    'reason' => 'Secilen havuz bulunamadi: ' . $poolId,
                ];
                continue;
            }

            $tipId = $this->numericValue($row, ['tipId', 'typeId']);
            if ($existingPool !== [] && $tipId <= 0) {
                $tipId = (int) ($existingPool['tipId'] ?? 0);
            }

            if ($tipId <= 0) {
                $skipped[] = [
                    'section' => 'havuzlar',
                    'index' => (string) $index,
                    'reason' => 'tipId zorunlu.',
                ];
                continue;
            }

            if (!$this->poolTypeExists($pdo, $tipId)) {
                $skipped[] = [
                    'section' => 'havuzlar',
                    'index' => (string) $index,
                    'reason' => 'Secilen havuz tipi bulunamadi: ' . $tipId,
                ];
                continue;
            }

            if ($existingPool === []) {
                $existingPool = $this->poolRowByTipId($pdo, $homeId, $tipId);
            }

            $values = $this->poolValues($row, $existingPool);
            if ($values['deger'] === '') {
                $skipped[] = [
                    'section' => 'havuzlar',
                    'index' => (string) $index,
                    'reason' => 'deger zorunlu.',
                ];
                continue;
            }

            if ($existingPool !== []) {
                $stmt = $pdo->prepare(
                    "UPDATE dbo.havuztanimlamari
                     SET tipId = :tipId,
                         deger = :deger,
                         havuzTipiId = :havuzTipiId,
                         uzunluk = :uzunluk,
                         genislik = :genislik,
                         derinlik = :derinlik,
                         tamKorunakli = :tamKorunakli,
                         isitma = :isitma,
                         DateModified = GETDATE()
                     WHERE id = :id AND homesId = :homeId"
                );
                $stmt->execute([
                    ':tipId' => $tipId,
                    ':deger' => $values['deger'],
                    ':havuzTipiId' => $values['havuzTipiId'],
                    ':uzunluk' => $values['uzunluk'],
                    ':genislik' => $values['genislik'],
                    ':derinlik' => $values['derinlik'],
                    ':tamKorunakli' => $values['tamKorunakli'],
                    ':isitma' => $values['isitma'],
                    ':id' => (int) $existingPool['id'],
                    ':homeId' => $homeId,
                ]);
                $updated += max(1, $stmt->rowCount());
                continue;
            }

            $stmt = $pdo->prepare(
                "INSERT INTO dbo.havuztanimlamari
                    (homesId, tipId, deger, havuzTipiId, uzunluk, genislik, derinlik, tamKorunakli, isitma)
                 VALUES
                    (:homeId, :tipId, :deger, :havuzTipiId, :uzunluk, :genislik, :derinlik, :tamKorunakli, :isitma)"
            );
            $stmt->execute([
                ':homeId' => $homeId,
                ':tipId' => $tipId,
                ':deger' => $values['deger'],
                ':havuzTipiId' => $values['havuzTipiId'],
                ':uzunluk' => $values['uzunluk'],
                ':genislik' => $values['genislik'],
                ':derinlik' => $values['derinlik'],
                ':tamKorunakli' => $values['tamKorunakli'],
                ':isitma' => $values['isitma'],
            ]);
            $updated++;
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $existingPool
     * @return array{deger:string,havuzTipiId:int|null,uzunluk:string,genislik:string,derinlik:string,tamKorunakli:string,isitma:int|null}
     */
    private function poolValues(array $row, array $existingPool): array
    {
        return [
            'deger' => $this->stringValue($row, ['deger', 'value'], $existingPool['deger'] ?? ''),
            'havuzTipiId' => $this->nullableNumericValue($row, ['havuzTipiId', 'poolTypeId'], $existingPool['havuzTipiId'] ?? null),
            'uzunluk' => $this->stringValue($row, ['uzunluk', 'length'], $existingPool['uzunluk'] ?? ''),
            'genislik' => $this->stringValue($row, ['genislik', 'width'], $existingPool['genislik'] ?? ''),
            'derinlik' => $this->stringValue($row, ['derinlik', 'depth'], $existingPool['derinlik'] ?? ''),
            'tamKorunakli' => $this->stringValue($row, ['tamKorunakli', 'fullySheltered'], $existingPool['tamKorunakli'] ?? ''),
            'isitma' => $this->nullableBoolValue($row, ['isitma', 'heated', 'heating'], $existingPool['isitma'] ?? null),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function deletePoolFromPayload(array $payload): void
    {
        $poolId = $this->numericValue($payload, ['havuzId', 'poolId', 'id']);

        if ($poolId <= 0) {
            throw new HttpException('Lutfen gecerli bir havuz ID gonderin.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();
        $this->assertPoolTableReady($pdo);

        $stmt = $pdo->prepare('DELETE FROM dbo.havuztanimlamari WHERE id = :poolId');
        $stmt->execute([
            ':poolId' => $poolId,
        ]);

        if ($stmt->rowCount() <= 0) {
            throw new HttpException('Secilen havuz bulunamadi.', 'NOT_FOUND', 404);
        }

        $this->response->success([
            'id' => $poolId,
            'deleted' => true,
            'deleted_related_sections' => [
                'havuzlar' => 1,
            ],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function poolRowById(PDO $pdo, int $homeId, int $poolId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, homesId, tipId, deger, havuzTipiId, uzunluk, genislik, derinlik, tamKorunakli, isitma
             FROM dbo.havuztanimlamari
             WHERE id = :id AND homesId = :homeId'
        );
        $stmt->execute([
            ':id' => $poolId,
            ':homeId' => $homeId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function poolRowByTipId(PDO $pdo, int $homeId, int $tipId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, homesId, tipId, deger, havuzTipiId, uzunluk, genislik, derinlik, tamKorunakli, isitma
             FROM dbo.havuztanimlamari
             WHERE homesId = :homeId AND tipId = :tipId'
        );
        $stmt->execute([
            ':homeId' => $homeId,
            ':tipId' => $tipId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    private function poolTypeExists(PDO $pdo, int $tipId): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM dbo.havuztipitanimlari WHERE id = :id');
        $stmt->execute([':id' => $tipId]);

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
     * @param mixed $fallback
     */
    private function nullableNumericValue(array $row, array $keys, $fallback)
    {
        $missing = '__MISSING__';
        $value = $this->firstPayloadValue($row, $keys, $missing);
        if ($value === $missing) {
            return is_numeric($fallback) ? (int) $fallback : null;
        }
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $keys
     * @param mixed $fallback
     */
    private function nullableBoolValue(array $row, array $keys, $fallback)
    {
        $missing = '__MISSING__';
        $value = $this->firstPayloadValue($row, $keys, $missing);
        if ($value === $missing) {
            if ($fallback === null || $fallback === '') {
                return null;
            }

            return $this->toBool($fallback) ? 1 : 0;
        }
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->toBool($value) ? 1 : 0;
    }

    /**
     * @param mixed $value
     */
    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim((string) $value));

        return in_array($value, ['1', 'true', 'yes', 'on', 'evet'], true);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $keys
     * @param mixed $fallback
     */
    private function stringValue(array $row, array $keys, $fallback): string
    {
        $missing = '__MISSING__';
        $value = $this->firstPayloadValue($row, $keys, $missing);
        if ($value === $missing) {
            return trim((string) $fallback);
        }
        if ($value === null || is_array($value)) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $keys
     * @return mixed
     */
    private function firstPayloadValue(array $row, array $keys, $default = '')
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }
        }

        return $default;
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
