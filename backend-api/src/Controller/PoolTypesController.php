<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Havuz tipleri (dbo.havuztipitanimlari): ilan detayindaki havuz satirinin tipi (havuztanimlamari.tipId).
 * id 1/2/3 (yuzme / cocuk / kapali) homes havuz kolonlarina bagli oldugu icin duzenlenmez ve silinmez.
 *   GET  /pool-types            liste (id, baslik)
 *   POST /pool-types            ekle     { baslik }
 *   POST /pool-types/update     guncelle { id, baslik }
 *   POST /pool-types/delete     sil      { id }  (sabit tipler ve ilanlarda kullanilanlar silinmez)
 */
final class PoolTypesController extends Controller
{
    // HomesManagementPoolsController::homesPoolColumnMap ile ayni: bu tiplerin homes kolonlari var.
    private const SYSTEM_IDS = [1, 2, 3];

    /**
     * @Get
     */
    public function index(): void
    {
        $this->guard(function () {
            $pdo = $this->db->pdo();
            $usage = $this->tableExists($pdo, 'havuztanimlamari')
                ? '(SELECT COUNT(DISTINCT h.homesId) FROM dbo.havuztanimlamari h WHERE h.tipId = t.id)'
                : '0';
            $rows = $pdo->query(
                "SELECT t.id, t.baslik, {$usage} AS HomeCount FROM dbo.havuztipitanimlari t ORDER BY t.id ASC"
            )->fetchAll(PDO::FETCH_ASSOC);

            $items = array_map(function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'baslik' => (string) ($row['baslik'] ?? ''),
                    'homeCount' => (int) ($row['HomeCount'] ?? 0),
                    'isSystem' => in_array((int) $row['id'], self::SYSTEM_IDS, true),
                ];
            }, $rows);

            $this->response->success(['items' => $items]);
        });
    }

    /**
     * @Post
     */
    public function store(): void
    {
        $this->guard(function () {
            $stmt = $this->db->pdo()->prepare('INSERT INTO dbo.havuztipitanimlari (baslik) OUTPUT INSERTED.id VALUES (:baslik)');
            $stmt->execute([':baslik' => $this->baslik()]);

            $this->response->success(['message' => 'Havuz tipi eklendi', 'id' => (int) $stmt->fetchColumn()]);
        });
    }

    /**
     * @Post("update")
     */
    public function update(): void
    {
        $this->guard(function () {
            $id = $this->id();
            if (in_array($id, self::SYSTEM_IDS, true)) {
                throw new HttpException('Bu sabit bir havuz tipi; duzenlenemez.', 'IN_USE', 422);
            }
            $stmt = $this->db->pdo()->prepare('UPDATE dbo.havuztipitanimlari SET baslik = :baslik WHERE id = :id');
            $stmt->execute([':baslik' => $this->baslik(), ':id' => $id]);
            if ($stmt->rowCount() === 0) {
                throw new HttpException('Havuz tipi bulunamadi.', 'NOT_FOUND', 404);
            }

            $this->response->success(['message' => 'Havuz tipi guncellendi']);
        });
    }

    /**
     * @Post("delete")
     */
    public function delete(): void
    {
        $this->guard(function () {
            $pdo = $this->db->pdo();
            $id = $this->id();
            if (in_array($id, self::SYSTEM_IDS, true)) {
                throw new HttpException('Bu sabit bir havuz tipi (ilan kolonlarina bagli); silinemez.', 'IN_USE', 422);
            }

            if ($this->tableExists($pdo, 'havuztanimlamari')) {
                $used = $pdo->prepare('SELECT COUNT(DISTINCT homesId) FROM dbo.havuztanimlamari WHERE tipId = :id');
                $used->execute([':id' => $id]);
                $count = (int) $used->fetchColumn();
                if ($count > 0) {
                    throw new HttpException("Bu havuz tipi {$count} ilanda kullaniliyor; silinemez.", 'IN_USE', 422);
                }
            }

            $stmt = $pdo->prepare('DELETE FROM dbo.havuztipitanimlari WHERE id = :id');
            $stmt->execute([':id' => $id]);
            if ($stmt->rowCount() === 0) {
                throw new HttpException('Havuz tipi bulunamadi.', 'NOT_FOUND', 404);
            }

            $this->response->success(['message' => 'Havuz tipi silindi']);
        });
    }

    // Veritabani hatasi genel "beklenmeyen hata" yerine aciklamasiyla doner (sebep panelde gorunsun).
    private function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (\PDOException $e) {
            throw new HttpException('Veritabani hatasi: ' . $e->getMessage(), 'DB_ERROR', 500);
        }
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = :t");
        $stmt->execute([':t' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function id(): int
    {
        $id = (int) $this->request->query('id', $this->request->input('id', 0));
        if ($id <= 0) {
            throw new HttpException('Gecersiz ID', 'VALIDATION', 422);
        }

        return $id;
    }

    private function baslik(): string
    {
        $baslik = trim((string) $this->request->input('baslik', ''));
        if ($baslik === '') {
            throw new HttpException('Baslik zorunludur.', 'VALIDATION', 422);
        }

        return $baslik;
    }
}
