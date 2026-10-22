<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Support\Uploads;
use PDO;

/*
 * Mesafe tipleri tanimlari (dbo.mesafeler). Villa mesafe degerleri: mesafelerValues.mesafelerId.
 * Siteye ozel kolonlar: baslik / aktif / icon + site soneki (site 1: '', 2: '_s2', ...; config site_column_suffixes).
 * siralama ve favori tum sitelerde ortaktir. mesafeAraligi bu ekrandan duzenlenmez.
 *   GET  /distance-types               liste
 *   POST /distance-types               ekle     { baslik, siralama? }
 *   POST /distance-types/update        guncelle { id, baslik?, siralama?, aktif?, favori? }
 *   POST /distance-types/delete        sil      { id }  (villalarda kullaniliyorsa silinmez)
 *   POST /distance-types/image         ikon     { id, image }  uploads/ kokune, icon kolonuna dosya adi
 *   POST /distance-types/image/delete  { id }
 */
final class DistanceTypesController extends Controller
{
    /** @var array<int,string>|null kucuk harfli kolon adlari */
    private $columns = null;

    /**
     * @Get
     */
    public function index(): void
    {
        $pdo = $this->db->pdo();
        $baslikCol = $this->requireCol($pdo, 'baslik');
        $iconCol = $this->col($pdo, 'icon');
        $activeCol = $this->col($pdo, 'aktif');
        $hasFavorite = in_array('favori', $this->columns($pdo), true);

        $rows = $pdo->query(
            "SELECT id, [{$baslikCol}] AS baslik, " . ($iconCol ? "[{$iconCol}]" : "''") . ' AS icon, siralama'
            . ($activeCol ? ", [{$activeCol}] AS aktif" : '') . ($hasFavorite ? ', favori' : '') . '
             FROM dbo.mesafeler ORDER BY ISNULL(siralama, 0) ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $imageBase = $this->imageBase();
        $items = [];
        foreach ($rows as $row) {
            $icon = $this->imageName((string) ($row['icon'] ?? ''));
            $items[] = [
                'id' => (int) $row['id'],
                'baslik' => (string) ($row['baslik'] ?? ''),
                'resim' => $icon !== '' ? $imageBase . rawurlencode($icon) : '',
                'resimName' => $icon,
                'siralama' => $row['siralama'] === null ? 0 : (int) $row['siralama'],
                'aktif' => $activeCol ? (bool) (int) ($row['aktif'] ?? 0) : null,
                'favori' => $hasFavorite ? (bool) (int) ($row['favori'] ?? 0) : null,
            ];
        }

        $this->response->success(['items' => $items]);
    }

    /**
     * Baslik tum sitelerin baslik kolonlarina yazilir; aktif sadece secili sitede.
     *
     * @Post
     */
    public function store(): void
    {
        $pdo = $this->db->pdo();
        $this->requireCol($pdo, 'baslik');
        $baslik = $this->baslik();

        $siralama = $this->request->input('siralama', null);
        if ($siralama === null || $siralama === '') {
            $siralama = (int) $pdo->query('SELECT ISNULL(MAX(siralama), 0) + 1 FROM dbo.mesafeler')->fetchColumn();
        } elseif (!is_numeric($siralama)) {
            throw new HttpException('Siralama sayi olmali.', 'VALIDATION', 422);
        }

        $columns = ['siralama'];
        $values = [':siralama' => (int) $siralama];
        $i = 0;
        foreach ($this->siteColumns($pdo, 'baslik') as $column) {
            $columns[] = "[{$column}]";
            $values[':b' . $i++] = $baslik;
        }
        $activeCol = $this->col($pdo, 'aktif');
        if ($activeCol !== null) {
            $columns[] = "[{$activeCol}]";
            $values[':aktif'] = 1;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO dbo.mesafeler (' . implode(', ', $columns) . ') OUTPUT INSERTED.id
             VALUES (' . implode(', ', array_keys($values)) . ')'
        );
        $stmt->execute($values);

        $this->response->success(['message' => 'Mesafe tipi eklendi', 'id' => (int) $stmt->fetchColumn()]);
    }

    /**
     * @Post("update")
     */
    public function update(): void
    {
        $pdo = $this->db->pdo();
        $id = $this->id();
        $sets = [];
        $params = [':id' => $id];

        if ($this->request->input('baslik', null) !== null) {
            $sets[] = '[' . $this->requireCol($pdo, 'baslik') . '] = :baslik';
            $params[':baslik'] = $this->baslik();
        }
        $siralama = $this->request->input('siralama', null);
        if ($siralama !== null && $siralama !== '') {
            if (!is_numeric($siralama)) {
                throw new HttpException('Siralama sayi olmali.', 'VALIDATION', 422);
            }
            $sets[] = 'siralama = :siralama';
            $params[':siralama'] = (int) $siralama;
        }
        $aktif = $this->request->input('aktif', null);
        $activeCol = $this->col($pdo, 'aktif');
        if ($aktif !== null && $activeCol !== null) {
            $sets[] = "[{$activeCol}] = :aktif";
            $params[':aktif'] = $this->flag($aktif);
        }
        $favori = $this->request->input('favori', null);
        if ($favori !== null && in_array('favori', $this->columns($pdo), true)) {
            $sets[] = 'favori = :favori';
            $params[':favori'] = $this->flag($favori);
        }
        if (!$sets) {
            throw new HttpException('Guncellenecek alan yok.', 'VALIDATION', 422);
        }

        $stmt = $pdo->prepare('UPDATE dbo.mesafeler SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            throw new HttpException('Mesafe tipi bulunamadi.', 'NOT_FOUND', 404);
        }

        $this->response->success(['message' => 'Mesafe tipi guncellendi']);
    }

    /**
     * Villalarda (mesafelerValues) kullanilan mesafe tipi silinmez; degerler kaybolmasin.
     *
     * @Post("delete")
     */
    public function delete(): void
    {
        $pdo = $this->db->pdo();
        $id = $this->id();

        if ($this->tableExists($pdo, 'mesafelerValues')) {
            $used = $pdo->prepare('SELECT COUNT(DISTINCT homesId) FROM dbo.mesafelerValues WHERE mesafelerId = :id');
            $used->execute([':id' => $id]);
            $count = (int) $used->fetchColumn();
            if ($count > 0) {
                throw new HttpException("Bu mesafe tipi {$count} villada kullaniliyor; silinemez. Pasif yapabilirsiniz.", 'IN_USE', 422);
            }
        }

        $stmt = $pdo->prepare('DELETE FROM dbo.mesafeler WHERE id = :id');
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() === 0) {
            throw new HttpException('Mesafe tipi bulunamadi.', 'NOT_FOUND', 404);
        }

        $this->response->success(['message' => 'Mesafe tipi silindi']);
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
        $stmt = $pdo->prepare('UPDATE dbo.mesafeler SET siralama = :siralama WHERE id = :id');
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

        $this->response->success(['message' => 'Mesafe tipi siralamasi guncellendi']);
    }

    /**
     * Ikonu uploads/ kokune kaydeder ve secili sitenin icon kolonuna dosya adini yazar. Yazilamazsa dosya silinir.
     *
     * @Post("image")
     */
    public function image(): void
    {
        $pdo = $this->db->pdo();
        $id = $this->id();
        $iconCol = $this->requireCol($pdo, 'icon');
        $filename = Uploads::storeImage('image', [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/svg+xml' => 'svg',
        ]);
        $target = Uploads::dir() . DIRECTORY_SEPARATOR . $filename;

        try {
            $stmt = $pdo->prepare("UPDATE dbo.mesafeler SET [{$iconCol}] = :icon WHERE id = :id");
            $stmt->execute([':icon' => $filename, ':id' => $id]);
            if ($stmt->rowCount() === 0) {
                throw new HttpException('Mesafe tipi bulunamadi.', 'NOT_FOUND', 404);
            }
        } catch (\Throwable $e) {
            @unlink($target);
            throw $e;
        }

        $this->response->success(['fileName' => $filename, 'url' => $this->imageBase() . rawurlencode($filename)]);
    }

    /**
     * Ikonu kolondan kaldirir; dosyayi hicbir sitenin icon kolonu kullanmiyorsa uploads/ klasorunden siler.
     *
     * @Post("image/delete")
     */
    public function deleteImage(): void
    {
        $pdo = $this->db->pdo();
        $id = $this->id();
        $iconCol = $this->requireCol($pdo, 'icon');
        $stmt = $pdo->prepare("SELECT [{$iconCol}] FROM dbo.mesafeler WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            throw new HttpException('Mesafe tipi bulunamadi.', 'NOT_FOUND', 404);
        }
        $filename = $this->imageName((string) $current);

        $pdo->prepare("UPDATE dbo.mesafeler SET [{$iconCol}] = '' WHERE id = :id")->execute([':id' => $id]);

        $fileDeleted = false;
        if ($filename !== '') {
            $conditions = [];
            $params = [];
            foreach ($this->siteColumns($pdo, 'icon') as $i => $column) {
                $conditions[] = "CAST([{$column}] AS nvarchar(4000)) = :n{$i}";
                $params[':n' . $i] = $filename;
            }
            $used = $pdo->prepare('SELECT COUNT(*) FROM dbo.mesafeler WHERE ' . implode(' OR ', $conditions));
            $used->execute($params);
            $path = Uploads::dir() . DIRECTORY_SEPARATOR . $filename;
            if ((int) $used->fetchColumn() === 0 && is_file($path)) {
                $fileDeleted = @unlink($path);
            }
        }

        $this->response->success(['message' => 'Ikon silindi', 'fileDeleted' => $fileDeleted]);
    }

    /**
     * @param mixed $value
     */
    private function flag($value): int
    {
        return ($value === true || $value === 1 || $value === '1' || $value === 'true') ? 1 : 0;
    }

    private function imageBase(): string
    {
        return Uploads::cdnDomain() . '/uploads/';
    }

    private function imageName(string $value): string
    {
        $value = trim(str_replace('\\', '/', $value));
        if ($value === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $value) === 1) {
            return rawurldecode(basename((string) parse_url($value, PHP_URL_PATH)));
        }

        return basename($value);
    }

    /**
     * @return array<int,string> kucuk harfli kolon adlari
     */
    private function columns(PDO $pdo): array
    {
        if ($this->columns === null) {
            $this->columns = $pdo->query(
                "SELECT LOWER(COLUMN_NAME) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'mesafeler'"
            )->fetchAll(PDO::FETCH_COLUMN);
        }

        return $this->columns;
    }

    private function col(PDO $pdo, string $base): ?string
    {
        $column = $base . $this->siteSuffix();

        return in_array(strtolower($column), $this->columns($pdo), true) ? $column : null;
    }

    private function requireCol(PDO $pdo, string $base): string
    {
        $column = $this->col($pdo, $base);
        if ($column === null) {
            throw new HttpException('Secili site icin mesafeler tablosunda ' . $base . $this->siteSuffix() . ' kolonu yok.', 'VALIDATION', 422);
        }

        return $column;
    }

    /**
     * @return array<int,string>
     */
    private function siteColumns(PDO $pdo, string $base): array
    {
        $result = [];
        foreach ($this->columns($pdo) as $column) {
            if ($column === $base || preg_match('/^' . preg_quote($base, '/') . '_s[0-9]+$/', $column) === 1) {
                $result[] = $column;
            }
        }

        return $result;
    }

    private function siteSuffix(): string
    {
        $value = $this->request->query('site', $this->request->input('site', 1));
        $siteId = is_numeric($value) && (int) $value > 0 ? (int) $value : 1;
        if ($siteId <= 1) {
            return '';
        }
        $suffixes = $this->app['site_column_suffixes'] ?? [];
        $suffix = is_array($suffixes)
            ? (string) ($suffixes[$siteId] ?? ($suffixes[(string) $siteId] ?? ('_s' . $siteId)))
            : '_s' . $siteId;

        return preg_match('/^_[A-Za-z0-9]+$/', $suffix) === 1 ? $suffix : '';
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
