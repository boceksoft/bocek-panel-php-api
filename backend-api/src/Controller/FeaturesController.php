<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Support\Uploads;
use PDO;

/*
 * Villa ozellikleri tanimlari (dbo.ozellikler). Iki seviyeli: cat = ust ozellik id (0 = ana).
 * Villalarda homes.ozellikler '#' ile ayrilmis id listesi olarak tutulur.
 * Siteye ozel kolonlar: baslik / resim / aktif / search / iconmu + site soneki (site 1: '', 2: '_s2', ...;
 * config site_column_suffixes). siralama ve cat tum sitelerde ortaktir. ?site= ile secilir (varsayilan 1).
 *   GET  /features            liste (agac)
 *   POST /features            ekle     { baslik, cat?, siralama? }
 *   POST /features/update     guncelle { id, baslik?, siralama?, cat?, aktif?, search? }
 *   POST /features/delete     sil      { id }  (alt ozelligi olan silinmez)
 *   POST /features/image      gorsel   { id, image }  uploads/ kokune, resim kolonuna dosya adi (simge turu: resim)
 *   POST /features/image/delete        { id }
 */
final class FeaturesController extends Controller
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
        $resimCol = $this->col($pdo, 'resim');
        $activeCol = $this->col($pdo, 'aktif');
        $searchCol = $this->col($pdo, 'search');
        $hasActive = $activeCol !== null;
        $hasSearch = $searchCol !== null;
        $rows = $pdo->query(
            "SELECT id, [{$baslikCol}] AS baslik, " . ($resimCol ? "[{$resimCol}]" : "''") . ' AS resim, ISNULL(cat, 0) AS cat, siralama'
            . ($hasActive ? ", [{$activeCol}] AS aktif" : '') . ($hasSearch ? ", [{$searchCol}] AS search" : '') . '
             FROM dbo.ozellikler ORDER BY ISNULL(siralama, 0) ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $imageBase = $this->imageBase();
        $items = [];
        foreach ($rows as $row) {
            $resim = $this->imageName((string) ($row['resim'] ?? ''));
            $items[] = [
                'id' => (int) $row['id'],
                'baslik' => (string) ($row['baslik'] ?? ''),
                'resim' => $resim !== '' ? $imageBase . rawurlencode($resim) : '',
                'resimName' => $resim,
                'cat' => (int) $row['cat'],
                'siralama' => $row['siralama'] === null ? 0 : (int) $row['siralama'],
                'aktif' => $hasActive ? (bool) (int) ($row['aktif'] ?? 0) : null,
                'search' => $hasSearch ? (bool) (int) ($row['search'] ?? 0) : null,
            ];
        }

        $this->response->success(['items' => $this->tree($items), 'has_active' => $hasActive, 'has_search' => $hasSearch]);
    }

    /**
     * @Post
     */
    public function store(): void
    {
        $pdo = $this->db->pdo();
        $baslik = $this->baslik();
        $cat = (int) $this->request->input('cat', 0);
        if ($cat > 0) {
            $parent = $this->find($pdo, $cat);
            if (!$parent) {
                throw new HttpException('Ust ozellik bulunamadi.', 'VALIDATION', 422);
            }
            if ((int) $parent['cat'] > 0) {
                throw new HttpException('Alt ozelligin altina ozellik eklenemez.', 'VALIDATION', 422);
            }
        }

        $siralama = $this->request->input('siralama', null);
        if ($siralama === null || $siralama === '') {
            $stmt = $pdo->prepare('SELECT ISNULL(MAX(siralama), 0) + 1 FROM dbo.ozellikler WHERE ISNULL(cat, 0) = :cat');
            $stmt->execute([':cat' => $cat]);
            $siralama = (int) $stmt->fetchColumn();
        } elseif (!is_numeric($siralama)) {
            throw new HttpException('Siralama sayi olmali.', 'VALIDATION', 422);
        }

        $this->requireCol($pdo, 'baslik');
        // Baslik tum sitelerin baslik kolonlarina yazilir (villa detayi temel baslik'i okur, diger siteler bos kalmasin).
        // Aktif sadece secili sitede; simge turu resim (iconmu = 0), arama motoru kapali.
        $columns = ['cat', 'siralama'];
        $values = [':cat' => $cat, ':siralama' => (int) $siralama];
        $i = 0;
        foreach ($this->siteColumns($pdo, 'baslik') as $column) {
            $columns[] = "[{$column}]";
            $values[':b' . $i++] = $baslik;
        }
        $currentFlags = ['aktif' => 1, 'iconmu' => 0, 'search' => 0];
        foreach ($currentFlags as $base => $value) {
            $column = $this->col($pdo, $base);
            if ($column !== null) {
                $columns[] = "[{$column}]";
                $values[':f_' . $base] = $value;
            }
        }
        $stmt = $pdo->prepare(
            'INSERT INTO dbo.ozellikler (' . implode(', ', $columns) . ') OUTPUT INSERTED.id
             VALUES (' . implode(', ', array_keys($values)) . ')'
        );
        $stmt->execute($values);

        $this->response->success(['message' => 'Ozellik eklendi', 'id' => (int) $stmt->fetchColumn()]);
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
        $catInput = $this->request->input('cat', null);
        if ($catInput !== null && $catInput !== '') {
            $cat = (int) $catInput;
            if (!$this->find($pdo, $id)) {
                throw new HttpException('Ozellik bulunamadi.', 'NOT_FOUND', 404);
            }
            if ($cat > 0) {
                if ($cat === $id) {
                    throw new HttpException('Ozellik kendi altina tasinamaz.', 'VALIDATION', 422);
                }
                $parent = $this->find($pdo, $cat);
                if (!$parent || (int) $parent['cat'] > 0) {
                    throw new HttpException('Ust kategori bir ana ozellik olmali.', 'VALIDATION', 422);
                }
                $children = $pdo->prepare('SELECT COUNT(*) FROM dbo.ozellikler WHERE cat = :id');
                $children->execute([':id' => $id]);
                if ((int) $children->fetchColumn() > 0) {
                    throw new HttpException('Alt ozellikleri olan bir ozellik baska bir ozelligin altina tasinamaz.', 'VALIDATION', 422);
                }
            }
            $sets[] = 'cat = :cat';
            $params[':cat'] = $cat;
        }
        foreach (['aktif', 'search'] as $flag) {
            $value = $this->request->input($flag, null);
            $column = $this->col($pdo, $flag);
            if ($value !== null && $column !== null) {
                $sets[] = "[{$column}] = :{$flag}";
                $params[':' . $flag] = ($value === true || $value === 1 || $value === '1' || $value === 'true') ? 1 : 0;
            }
        }
        if (!$sets) {
            throw new HttpException('Guncellenecek alan yok.', 'VALIDATION', 422);
        }

        $stmt = $pdo->prepare('UPDATE dbo.ozellikler SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            throw new HttpException('Ozellik bulunamadi.', 'NOT_FOUND', 404);
        }

        $this->response->success(['message' => 'Ozellik guncellendi']);
    }

    /**
     * Sadece ozellikler satiri silinir; villalardaki (homes.ozellikler) id listesine dokunulmaz.
     *
     * @Post("delete")
     */
    public function delete(): void
    {
        $pdo = $this->db->pdo();
        $id = $this->id();

        $children = $pdo->prepare('SELECT COUNT(*) FROM dbo.ozellikler WHERE cat = :id');
        $children->execute([':id' => $id]);
        if ((int) $children->fetchColumn() > 0) {
            throw new HttpException('Alt ozellikleri olan bir ozellik silinemez; once alt ozellikleri silin.', 'CHILD_ITEMS', 422);
        }

        $stmt = $pdo->prepare('DELETE FROM dbo.ozellikler WHERE id = :id');
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() === 0) {
            throw new HttpException('Ozellik bulunamadi.', 'NOT_FOUND', 404);
        }

        $this->response->success(['message' => 'Ozellik silindi']);
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
        $stmt = $pdo->prepare('UPDATE dbo.ozellikler SET siralama = :siralama WHERE id = :id');
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

        $this->response->success(['message' => 'Ozellik siralamasi guncellendi']);
    }

    /**
     * Gorseli uploads/ kokune kaydeder, resim kolonuna dosya adini yazar ve simge turunu resim yapar (iconmu = 0).
     * Kolona yazilamazsa dosya geri silinir.
     *
     * @Post("image")
     */
    public function image(): void
    {
        $pdo = $this->db->pdo();
        $id = $this->id();
        $filename = Uploads::storeImage('image', [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/svg+xml' => 'svg',
        ]);
        $target = Uploads::dir() . DIRECTORY_SEPARATOR . $filename;

        try {
            $sets = ['[' . $this->requireCol($pdo, 'resim') . '] = :resim'];
            $iconCol = $this->col($pdo, 'iconmu');
            if ($iconCol !== null) {
                $sets[] = "[{$iconCol}] = 0";
            }
            $stmt = $pdo->prepare('UPDATE dbo.ozellikler SET ' . implode(', ', $sets) . ' WHERE id = :id');
            $stmt->execute([':resim' => $filename, ':id' => $id]);
            if ($stmt->rowCount() === 0) {
                throw new HttpException('Ozellik bulunamadi.', 'NOT_FOUND', 404);
            }
        } catch (\Throwable $e) {
            @unlink($target);
            throw $e;
        }

        $this->response->success(['fileName' => $filename, 'url' => $this->imageBase() . rawurlencode($filename)]);
    }

    /**
     * Gorseli kolondan kaldirir; dosyayi baska ozellik kullanmiyorsa uploads/ klasorunden siler.
     *
     * @Post("image/delete")
     */
    public function deleteImage(): void
    {
        $pdo = $this->db->pdo();
        $id = $this->id();
        $resimCol = $this->requireCol($pdo, 'resim');
        $stmt = $pdo->prepare("SELECT [{$resimCol}] FROM dbo.ozellikler WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            throw new HttpException('Ozellik bulunamadi.', 'NOT_FOUND', 404);
        }
        $filename = $this->imageName((string) $current);

        $pdo->prepare("UPDATE dbo.ozellikler SET [{$resimCol}] = '' WHERE id = :id")->execute([':id' => $id]);

        $fileDeleted = false;
        if ($filename !== '') {
            // Herhangi bir sitenin resim kolonunda kullaniliyorsa dosya silinmez.
            $conditions = [];
            $params = [];
            foreach ($this->siteColumns($pdo, 'resim') as $i => $column) {
                $conditions[] = "CAST([{$column}] AS nvarchar(4000)) = :n{$i}";
                $params[':n' . $i] = $filename;
            }
            $used = $pdo->prepare('SELECT COUNT(*) FROM dbo.ozellikler WHERE ' . implode(' OR ', $conditions));
            $used->execute($params);
            $path = Uploads::dir() . DIRECTORY_SEPARATOR . $filename;
            if ((int) $used->fetchColumn() === 0 && is_file($path)) {
                $fileDeleted = @unlink($path);
            }
        }

        $this->response->success(['message' => 'Gorsel silindi', 'fileDeleted' => $fileDeleted]);
    }

    // Ozellik gorselleri uploads/ kokunden (orijinal boyut) gosterilir.
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
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function tree(array $items): array
    {
        $ids = array_column($items, 'id');
        $roots = [];
        foreach ($items as $item) {
            // Ust ozelligi silinmis (yetim) kayitlar da kok seviyede gosterilir.
            if ($item['cat'] <= 0 || !in_array($item['cat'], $ids, true)) {
                $item['children'] = array_values(array_filter($items, function (array $child) use ($item): bool {
                    return $child['cat'] === $item['id'];
                }));
                foreach ($item['children'] as &$child) {
                    $child['children'] = [];
                }
                unset($child);
                $roots[] = $item;
            }
        }

        return $roots;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function find(PDO $pdo, int $id)
    {
        $stmt = $pdo->prepare('SELECT id, ISNULL(cat, 0) AS cat FROM dbo.ozellikler WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return array<int,string> kucuk harfli kolon adlari
     */
    private function columns(PDO $pdo): array
    {
        if ($this->columns === null) {
            $stmt = $pdo->query(
                "SELECT LOWER(COLUMN_NAME) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'ozellikler'"
            );
            $this->columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        return $this->columns;
    }

    // Secili sitenin kolonu (ornek: baslik_s3); tabloda yoksa null.
    private function col(PDO $pdo, string $base): ?string
    {
        $column = $base . $this->siteSuffix();

        return in_array(strtolower($column), $this->columns($pdo), true) ? $column : null;
    }

    private function requireCol(PDO $pdo, string $base): string
    {
        $column = $this->col($pdo, $base);
        if ($column === null) {
            throw new HttpException('Secili site icin ozellikler tablosunda ' . $base . $this->siteSuffix() . ' kolonu yok.', 'VALIDATION', 422);
        }

        return $column;
    }

    /**
     * Tum sitelerin ayni alan kolonlari (baslik, baslik_s2, baslik_s3 ...).
     *
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

    private function siteId(): int
    {
        $value = $this->request->query('site', $this->request->input('site', 1));

        return is_numeric($value) && (int) $value > 0 ? (int) $value : 1;
    }

    // CategoriesController ile ayni: config site_column_suffixes, yoksa _s{site}.
    private function siteSuffix(): string
    {
        $siteId = $this->siteId();
        if ($siteId <= 1) {
            return '';
        }
        $suffixes = $this->app['site_column_suffixes'] ?? [];
        $suffix = is_array($suffixes)
            ? (string) ($suffixes[$siteId] ?? ($suffixes[(string) $siteId] ?? ('_s' . $siteId)))
            : '_s' . $siteId;

        return preg_match('/^_[A-Za-z0-9]+$/', $suffix) === 1 ? $suffix : '';
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
