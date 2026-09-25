<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Kullanici migrasyon isaretleme.
 *   POST /backend-api/kullanicilarmigration?id={kayitlarId}
 */
final class KullanicilarmigrationController extends Controller
{
    /**
     * Verilen id ve daha kucuk kayitlari migrated yapar.
     *
     * @Post
     * @query id int kayitlar.id
     */
    public function index(): void
    {
        $pdo = $this->db->pdo();
        $id = (int) $this->request->query('id', $this->request->input('id', 0));
        if ($id <= 0) {
            throw new HttpException('Gecerli bir kayit id gonderilmelidir.', 'INVALID_ID', 422);
        }

        if (!$this->columnExists($pdo, 'dbo', 'kayitlar', 'MigrationStatus')) {
            throw new HttpException('MigrationStatus kolonu bulunamadi. Once setup calistirilmalidir.', 'MIGRATION_COLUMN_NOT_FOUND', 500);
        }

        $target = $this->reservation($pdo, $id);
        if ($target === []) {
            throw new HttpException('Rezervasyon bulunamadi.', 'RESERVATION_NOT_FOUND', 404);
        }

        $updated = $this->markUpdated($pdo, $id);

        $this->response->success([
            'id' => $id,
            'max_id' => $id,
            'migration_status' => true,
            'updated' => $updated,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function reservation(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare(
            'SELECT TOP 1 id
             FROM kayitlar
             WHERE id = :id'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    private function markUpdated(PDO $pdo, int $id): int
    {
        $stmt = $pdo->prepare(
            'UPDATE kayitlar
             SET MigrationStatus = 1
             WHERE id <= :id'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    private function columnExists(PDO $pdo, string $schema, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT CASE WHEN COL_LENGTH(:tableName, :columnName) IS NULL THEN 0 ELSE 1 END'
        );
        $stmt->execute([
            ':tableName' => $schema . '.' . $table,
            ':columnName' => $column,
        ]);

        return (int) $stmt->fetchColumn() === 1;
    }
}
