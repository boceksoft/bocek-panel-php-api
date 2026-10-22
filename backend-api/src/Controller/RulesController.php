<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Ev kurallari tanimlari (dbo.kurallar). Villalarda homes.kurallar virgullu id listesi olarak tutulur.
 *   GET  /rules            liste (id, baslik, siralama)
 *   POST /rules            ekle    { baslik, siralama? }
 *   POST /rules/update     guncelle { id, baslik?, siralama? }
 *   POST /rules/delete     sil     { id }
 */
final class RulesController extends Controller
{
    /**
     * @Get
     */
    public function index(): void
    {
        $rows = $this->db->pdo()->query(
            'SELECT id, baslik, siralama FROM dbo.kurallar ORDER BY ISNULL(siralama, 0) ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $items = array_map(function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'baslik' => (string) ($row['baslik'] ?? ''),
                'siralama' => $row['siralama'] === null ? 0 : (int) $row['siralama'],
            ];
        }, $rows);

        $this->response->success(['items' => $items]);
    }

    /**
     * @Post
     */
    public function store(): void
    {
        $baslik = $this->baslik(true);
        $pdo = $this->db->pdo();

        $siralama = $this->request->input('siralama', null);
        if ($siralama === null || $siralama === '') {
            $siralama = (int) $pdo->query('SELECT ISNULL(MAX(siralama), 0) + 1 FROM dbo.kurallar')->fetchColumn();
        }

        // Yeni kural aktif eklenir (diger aktif kolonlari eski panel gibi bos kalir).
        $stmt = $pdo->prepare(
            'INSERT INTO dbo.kurallar (baslik, siralama, aktif) OUTPUT INSERTED.id VALUES (:baslik, :siralama, 1)'
        );
        $stmt->execute([':baslik' => $baslik, ':siralama' => (int) $siralama]);

        $this->response->success(['message' => 'Kural eklendi', 'id' => (int) $stmt->fetchColumn()]);
    }

    /**
     * @Post("update")
     */
    public function update(): void
    {
        $id = $this->id();
        $sets = [];
        $params = [':id' => $id];

        if ($this->request->input('baslik', null) !== null) {
            $sets[] = 'baslik = :baslik';
            $params[':baslik'] = $this->baslik(true);
        }
        $siralama = $this->request->input('siralama', null);
        if ($siralama !== null && $siralama !== '') {
            if (!is_numeric($siralama)) {
                throw new HttpException('Siralama sayi olmali.', 'VALIDATION', 422);
            }
            $sets[] = 'siralama = :siralama';
            $params[':siralama'] = (int) $siralama;
        }
        if (!$sets) {
            throw new HttpException('Guncellenecek alan yok.', 'VALIDATION', 422);
        }

        $stmt = $this->db->pdo()->prepare('UPDATE dbo.kurallar SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            throw new HttpException('Kural bulunamadi.', 'NOT_FOUND', 404);
        }

        $this->response->success(['message' => 'Kural guncellendi']);
    }

    /**
     * Sadece kurallar satiri silinir; villalardaki (homes.kurallar) id listesine dokunulmaz.
     *
     * @Post("delete")
     */
    public function delete(): void
    {
        $id = $this->id();
        $stmt = $this->db->pdo()->prepare('DELETE FROM dbo.kurallar WHERE id = :id');
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() === 0) {
            throw new HttpException('Kural bulunamadi.', 'NOT_FOUND', 404);
        }

        $this->response->success(['message' => 'Kural silindi']);
    }

    /**
     * Surukle-birak siralama: items = [{ id, siralama }] tek transaction'da yazilir.
     *
     * @Post("sort")
     */
    public function sort(): void
    {
        $items = $this->request->input('items', []);
        if (!is_array($items) || !$items) {
            throw new HttpException('Siralanacak kayit yok.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('UPDATE dbo.kurallar SET siralama = :siralama WHERE id = :id');
        $pdo->beginTransaction();
        try {
            foreach ($items as $item) {
                $id = (int) ($item['id'] ?? 0);
                if ($id <= 0 || !isset($item['siralama']) || !is_numeric($item['siralama'])) {
                    throw new HttpException('Gecersiz siralama verisi.', 'VALIDATION', 422);
                }
                $stmt->execute([':siralama' => (int) $item['siralama'], ':id' => $id]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->response->success(['message' => 'Kural siralamasi guncellendi']);
    }

    private function id(): int
    {
        $id = (int) $this->request->query('id', $this->request->input('id', 0));
        if ($id <= 0) {
            throw new HttpException('Gecersiz ID', 'VALIDATION', 422);
        }

        return $id;
    }

    private function baslik(bool $required): string
    {
        $baslik = trim((string) $this->request->input('baslik', ''));
        if ($required && $baslik === '') {
            throw new HttpException('Baslik zorunludur.', 'VALIDATION', 422);
        }

        return $baslik;
    }
}
