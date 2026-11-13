<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * One cikan ozellikler (dbo.oneCikanOzellikler): ilan detayindaki "One Cikan Ozellikler" secenekleri.
 * Ilanin secimi homes.onecikan kolonunda virgullu id listesi olarak tutulur ("32,33").
 *   GET  /featured-features            liste (id, baslik, siralama, aktif, homeCount); siralama, sonra baslik
 *   POST /featured-features            ekle     { baslik }  (aktif = 1, siralama = en sona)
 *   POST /featured-features/update     guncelle { id, baslik?, aktif? }  (gelen alanlar yazilir)
 *   POST /featured-features/sort       sirala   { items: [{ id, siralama }] }
 *   POST /featured-features/delete     sil      { id }  (ilanlarda kullaniliyorsa silinmez)
 * Tablo ve siralama / aktif kolonlari setup-collums.sql ile olusturulur. aktif NULL olan eski kayitlar aktif sayilir.
 */
final class FeaturedFeaturesController extends Controller
{
    /**
     * @Get
     */
    public function index(): void
    {
        $this->guard(function () {
            $pdo = $this->db->pdo();
            $usage = $this->homesHasOnecikan($pdo)
                ? "(SELECT COUNT(*) FROM dbo.homes h WHERE ',' + REPLACE(ISNULL(CONVERT(nvarchar(max), h.onecikan), ''), ' ', '') + ',' LIKE '%,' + CONVERT(nvarchar(20), t.id) + ',%')"
                : '0';
            if (!$this->tableExists($pdo)) {
                // Setup calismamis: bos liste (panel "setup calistirin" uyarisi gosterir).
                $this->response->success(['items' => [], 'tableExists' => false, 'supportsSort' => false, 'supportsActive' => false]);
                return;
            }
            $hasSort = $this->hasColumn($pdo, 'siralama');
            $hasActive = $this->hasColumn($pdo, 'aktif');
            $sortSql = $hasSort ? 't.siralama' : 'CAST(NULL AS int)';
            $activeSql = $hasActive ? 'CAST(ISNULL(t.aktif, 1) AS int)' : 'CAST(1 AS int)';
            $order = $hasSort ? 'ISNULL(t.siralama, 2147483647) ASC, t.baslik ASC' : 't.baslik ASC';
            $rows = $pdo->query(
                "SELECT t.id, t.baslik, {$sortSql} AS siralama, {$activeSql} AS aktif, {$usage} AS HomeCount
                 FROM dbo.oneCikanOzellikler t ORDER BY {$order}"
            )->fetchAll(PDO::FETCH_ASSOC);

            $items = array_map(function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'baslik' => (string) ($row['baslik'] ?? ''),
                    'siralama' => $row['siralama'] !== null ? (int) $row['siralama'] : null,
                    'aktif' => (int) $row['aktif'] === 1,
                    'homeCount' => (int) ($row['HomeCount'] ?? 0),
                ];
            }, $rows);

            $this->response->success(['items' => $items, 'tableExists' => true, 'supportsSort' => $hasSort, 'supportsActive' => $hasActive]);
        });
    }

    /**
     * @Post
     */
    public function store(): void
    {
        $this->guard(function () {
            $pdo = $this->db->pdo();
            $this->assertTable($pdo);
            $columns = ['baslik'];
            $values = [':baslik'];
            if ($this->hasColumn($pdo, 'aktif')) {
                $columns[] = 'aktif';
                $values[] = '1';
            }
            if ($this->hasColumn($pdo, 'siralama')) {
                $columns[] = 'siralama';
                $values[] = '(SELECT ISNULL(MAX(siralama), 0) + 1 FROM dbo.oneCikanOzellikler)';
            }
            $stmt = $pdo->prepare(
                'INSERT INTO dbo.oneCikanOzellikler (' . implode(', ', $columns) . ') OUTPUT INSERTED.id VALUES (' . implode(', ', $values) . ')'
            );
            $stmt->execute([':baslik' => $this->baslik()]);

            $this->response->success(['message' => 'One cikan ozellik eklendi', 'id' => (int) $stmt->fetchColumn()]);
        });
    }

    /**
     * @Post("update")
     */
    public function update(): void
    {
        $this->guard(function () {
            $pdo = $this->db->pdo();
            $id = $this->id();
            $this->assertExists($pdo, $id);

            $sets = [];
            $params = [':id' => $id];
            if ($this->request->input('baslik', null) !== null) {
                $sets[] = 'baslik = :baslik';
                $params[':baslik'] = $this->baslik();
            }
            $active = $this->request->input('aktif', null);
            if ($active !== null) {
                if (!$this->hasColumn($pdo, 'aktif')) {
                    throw new HttpException('oneCikanOzellikler.aktif kolonu yok; once setup calistirilmalidir.', 'VALIDATION', 422);
                }
                $sets[] = 'aktif = :aktif';
                // JSON true / 1 / "1" / "true" -> 1
                $params[':aktif'] = filter_var($active, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
            if (!$sets) {
                throw new HttpException('Guncellenecek alan yok.', 'VALIDATION', 422);
            }

            $stmt = $pdo->prepare('UPDATE dbo.oneCikanOzellikler SET ' . implode(', ', $sets) . ' WHERE id = :id');
            $stmt->execute($params);

            $this->response->success(['message' => 'One cikan ozellik guncellendi']);
        });
    }

    /**
     * @Post("sort")
     */
    public function sort(): void
    {
        $this->guard(function () {
            $pdo = $this->db->pdo();
            $this->assertTable($pdo);
            if (!$this->hasColumn($pdo, 'siralama')) {
                throw new HttpException('oneCikanOzellikler.siralama kolonu yok; once setup calistirilmalidir.', 'VALIDATION', 422);
            }
            $items = $this->request->input('items', []);
            if (!is_array($items) || !$items) {
                throw new HttpException('Siralanacak kayit yok.', 'VALIDATION', 422);
            }

            $stmt = $pdo->prepare('UPDATE dbo.oneCikanOzellikler SET siralama = :siralama WHERE id = :id');
            $pdo->beginTransaction();
            try {
                foreach ($items as $item) {
                    $id = (int) ($item['id'] ?? 0);
                    if ($id > 0) {
                        $stmt->execute([':siralama' => (int) ($item['siralama'] ?? 0), ':id' => $id]);
                    }
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            $this->response->success(['message' => 'Siralama kaydedildi']);
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
            $this->assertExists($pdo, $id);

            // Ilanda secili olan silinmez (homes.onecikan'da kopuk id kalmasin).
            if ($this->homesHasOnecikan($pdo)) {
                $used = $pdo->prepare(
                    "SELECT COUNT(*) FROM dbo.homes WHERE ',' + REPLACE(ISNULL(CONVERT(nvarchar(max), onecikan), ''), ' ', '') + ',' LIKE :pattern"
                );
                $used->execute([':pattern' => '%,' . $id . ',%']);
                $count = (int) $used->fetchColumn();
                if ($count > 0) {
                    throw new HttpException("Bu ozellik {$count} ilanda secili; silinemez.", 'IN_USE', 422);
                }
            }

            $stmt = $pdo->prepare('DELETE FROM dbo.oneCikanOzellikler WHERE id = :id');
            $stmt->execute([':id' => $id]);

            $this->response->success(['message' => 'One cikan ozellik silindi']);
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

    // homes'ta trigger olabilir (rowCount guvenilmez): varlik ayri kontrol edilir.
    private function assertExists(PDO $pdo, int $id): void
    {
        $this->assertTable($pdo);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM dbo.oneCikanOzellikler WHERE id = :id');
        $stmt->execute([':id' => $id]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new HttpException('One cikan ozellik bulunamadi.', 'NOT_FOUND', 404);
        }
    }

    private function tableExists(PDO $pdo): bool
    {
        return (int) $pdo->query("SELECT CASE WHEN OBJECT_ID(N'dbo.oneCikanOzellikler', N'U') IS NULL THEN 0 ELSE 1 END")->fetchColumn() === 1;
    }

    private function assertTable(PDO $pdo): void
    {
        if (!$this->tableExists($pdo)) {
            throw new HttpException('oneCikanOzellikler tablosu yok; once setup calistirilmalidir.', 'VALIDATION', 409);
        }
    }

    private function hasColumn(PDO $pdo, string $column): bool
    {
        $stmt = $pdo->prepare("SELECT CASE WHEN COL_LENGTH('dbo.oneCikanOzellikler', :c) IS NULL THEN 0 ELSE 1 END");
        $stmt->execute([':c' => $column]);

        return (int) $stmt->fetchColumn() === 1;
    }

    private function homesHasOnecikan(PDO $pdo): bool
    {
        return (int) $pdo->query("SELECT CASE WHEN COL_LENGTH('dbo.homes', 'onecikan') IS NULL THEN 0 ELSE 1 END")->fetchColumn() === 1;
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
