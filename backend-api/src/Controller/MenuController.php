<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Support\Uploads;
use PDO;

/*
 * Menu yonetimi (eski panel: menu_yonetimi.asp).
 *   Menu      : menu gruplari (MenuId, Baslik). Sadece baslik duzenlenir.
 *   MenuItems : menu ogeleri. Cat = ust oge Id (0 = ana dizin), Site = site id, Sort = siralama.
 * Gorsel (resim) uploads/ kokune kaydedilir, kolona sadece dosya adi yazilir.
 */
final class MenuController extends Controller
{
    /** @var array<string,string> */
    private const IMAGE_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /**
     * Menuler ve secili sitenin menu ogeleri (agac).
     *
     * @Get
     */
    public function index(): void
    {
        $pdo = $this->db->pdo();
        $site = $this->siteId();

        $menus = $pdo->query('SELECT MenuId, Baslik FROM dbo.Menu ORDER BY MenuId ASC')->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare(
            'SELECT Id, Title, MetaTitle, MetaUrl, IsActive, MenuId, Cat, Site, Sort, resim
             FROM dbo.MenuItems
             WHERE Site = :site
             ORDER BY CASE WHEN Sort IS NULL THEN 1 ELSE 0 END, Sort ASC, Id ASC'
        );
        $stmt->execute([':site' => $site]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $imageBase = $this->imageBase();
        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->mapItem($row, $imageBase);
        }

        $result = [];
        foreach ($menus as $menu) {
            $menuId = (int) $menu['MenuId'];
            $menuItems = array_values(array_filter($items, function (array $item) use ($menuId): bool {
                return $item['menuId'] === $menuId;
            }));
            $result[] = [
                'menuId' => $menuId,
                'baslik' => (string) ($menu['Baslik'] ?? ''),
                'items' => $this->buildTree($menuItems),
            ];
        }

        $this->response->success([
            'site' => $site,
            'menus' => $result,
            'image_base' => $imageBase,
        ]);
    }

    /**
     * Menu basligini gunceller (eski: islem=menu_duzenle).
     *
     * @Post("title")
     */
    public function title(): void
    {
        $menuId = (int) $this->request->input('menuId', 0);
        $baslik = trim((string) $this->request->input('baslik', ''));
        if ($menuId <= 0 || $baslik === '') {
            throw new HttpException('Menu ve baslik zorunludur.', 'VALIDATION', 422);
        }

        $stmt = $this->db->pdo()->prepare('UPDATE dbo.Menu SET Baslik = :baslik WHERE MenuId = :menuId');
        $stmt->execute([':baslik' => $baslik, ':menuId' => $menuId]);
        if ($stmt->rowCount() === 0) {
            throw new HttpException('Menu bulunamadi.', 'NOT_FOUND', 404);
        }

        $this->response->success(['message' => 'Menu guncellendi']);
    }

    /**
     * Yeni menu ogesi (eski: islem=menu_ekle_alt). Alt ogede MenuId ust ogeden alinir.
     *
     * @Post("items")
     */
    public function store(): void
    {
        $pdo = $this->db->pdo();
        $site = $this->siteId();
        $title = trim((string) $this->request->input('title', ''));
        $menuId = (int) $this->request->input('menuId', 0);
        $cat = (int) $this->request->input('cat', 0);
        if ($title === '') {
            throw new HttpException('Baslik zorunludur.', 'VALIDATION', 422);
        }

        if ($cat > 0) {
            $parent = $this->findItem($pdo, $cat);
            if (!$parent || (int) $parent['Site'] !== $site) {
                throw new HttpException('Ust menu ogesi bulunamadi.', 'INVALID_PARENT', 422);
            }
            $menuId = (int) $parent['MenuId'];
        } else {
            $this->assertMenuExists($pdo, $menuId);
        }

        $sortStmt = $pdo->prepare('SELECT ISNULL(MAX(Sort), 0) + 1 FROM dbo.MenuItems WHERE MenuId = :menuId AND Site = :site AND Cat = :cat');
        $sortStmt->execute([':menuId' => $menuId, ':site' => $site, ':cat' => $cat]);
        $sort = (int) $sortStmt->fetchColumn();

        $stmt = $pdo->prepare(
            'INSERT INTO dbo.MenuItems (Title, MetaTitle, MetaUrl, IsActive, MenuId, Cat, Site, Sort, resim)
             OUTPUT INSERTED.Id
             VALUES (:title, :metaTitle, :metaUrl, :isActive, :menuId, :cat, :site, :sort, :resim)'
        );
        $stmt->execute([
            ':title' => $title,
            ':metaTitle' => trim((string) $this->request->input('metaTitle', '')),
            ':metaUrl' => trim((string) $this->request->input('metaUrl', '')),
            ':isActive' => $this->boolInput('isActive', true) ? 1 : 0,
            ':menuId' => $menuId,
            ':cat' => $cat,
            ':site' => $site,
            ':sort' => $sort,
            ':resim' => $this->imageName((string) $this->request->input('resim', '')),
        ]);
        $id = (int) $stmt->fetchColumn();

        $this->response->success(['message' => 'Menu ogesi eklendi', 'id' => $id]);
    }

    /**
     * Menu ogesini gunceller (eski: islem=menu_duzenle_alt). Sadece gonderilen alanlar degisir.
     * menuId / cat degisirse alt ogeler de ayni menuye tasinir.
     *
     * @Post("items/update")
     */
    public function update(): void
    {
        $pdo = $this->db->pdo();
        $id = (int) $this->request->input('id', 0);
        $item = $id > 0 ? $this->findItem($pdo, $id) : null;
        if (!$item) {
            throw new HttpException('Menu ogesi bulunamadi.', 'NOT_FOUND', 404);
        }

        $set = [];
        $params = [':id' => $id];
        foreach (['title' => 'Title', 'metaTitle' => 'MetaTitle', 'metaUrl' => 'MetaUrl'] as $key => $column) {
            $value = $this->request->input($key, null);
            if ($value !== null) {
                $set[] = "{$column} = :{$key}";
                $params[":{$key}"] = trim((string) $value);
            }
        }
        if (isset($params[':title']) && $params[':title'] === '') {
            throw new HttpException('Baslik zorunludur.', 'VALIDATION', 422);
        }
        if ($this->request->input('isActive', null) !== null) {
            $set[] = 'IsActive = :isActive';
            $params[':isActive'] = $this->boolInput('isActive', true) ? 1 : 0;
        }
        if ($this->request->input('resim', null) !== null) {
            $set[] = 'resim = :resim';
            $params[':resim'] = $this->imageName((string) $this->request->input('resim', ''));
        }

        $newMenuId = null;
        $catInput = $this->request->input('cat', null);
        $menuInput = $this->request->input('menuId', null);
        if ($catInput !== null || $menuInput !== null) {
            $cat = $catInput !== null ? (int) $catInput : (int) $item['Cat'];
            $newMenuId = $this->resolveParent($pdo, $item, $cat, $menuInput !== null ? (int) $menuInput : (int) $item['MenuId']);
            $set[] = 'Cat = :cat';
            $set[] = 'MenuId = :menuId';
            $params[':cat'] = $cat;
            $params[':menuId'] = $newMenuId;
        }

        if (!$set) {
            $this->response->success(['message' => 'Guncellenecek alan bulunamadi']);
            return;
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE dbo.MenuItems SET ' . implode(', ', $set) . ' WHERE Id = :id')->execute($params);
            if ($newMenuId !== null && $newMenuId !== (int) $item['MenuId']) {
                $this->moveDescendantsToMenu($pdo, $id, $newMenuId);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->response->success(['message' => 'Menu ogesi guncellendi']);
    }

    /**
     * Toplu siralama / tasima (eski: islem=MenuSort). items: [{id, sort, cat?, menuId?}]
     *
     * @Post("items/sort")
     */
    public function sort(): void
    {
        $items = $this->request->input('items', []);
        if (!is_array($items) || !$items) {
            throw new HttpException('Siralanacak oge bulunamadi.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            foreach ($items as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $id = (int) ($entry['id'] ?? 0);
                $item = $id > 0 ? $this->findItem($pdo, $id) : null;
                if (!$item) {
                    throw new HttpException('Menu ogesi bulunamadi: ' . $id, 'NOT_FOUND', 404);
                }

                $set = ['Sort = :sort'];
                $params = [':id' => $id, ':sort' => (int) ($entry['sort'] ?? 0)];
                $newMenuId = null;
                if (array_key_exists('cat', $entry) || array_key_exists('menuId', $entry)) {
                    $cat = array_key_exists('cat', $entry) ? (int) $entry['cat'] : (int) $item['Cat'];
                    $menuId = array_key_exists('menuId', $entry) ? (int) $entry['menuId'] : (int) $item['MenuId'];
                    $newMenuId = $this->resolveParent($pdo, $item, $cat, $menuId);
                    $set[] = 'Cat = :cat';
                    $set[] = 'MenuId = :menuId';
                    $params[':cat'] = $cat;
                    $params[':menuId'] = $newMenuId;
                }

                $pdo->prepare('UPDATE dbo.MenuItems SET ' . implode(', ', $set) . ' WHERE Id = :id')->execute($params);
                if ($newMenuId !== null && $newMenuId !== (int) $item['MenuId']) {
                    $this->moveDescendantsToMenu($pdo, $id, $newMenuId);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->response->success(['message' => 'Siralama guncellendi']);
    }

    /**
     * Aktif / pasif.
     *
     * @Post("items/active")
     */
    public function active(): void
    {
        $id = (int) $this->request->input('id', 0);
        if ($id <= 0) {
            throw new HttpException('Gecersiz ID', 'VALIDATION', 422);
        }

        $stmt = $this->db->pdo()->prepare('UPDATE dbo.MenuItems SET IsActive = :isActive WHERE Id = :id');
        $stmt->execute([':isActive' => $this->boolInput('isActive', true) ? 1 : 0, ':id' => $id]);
        if ($stmt->rowCount() === 0) {
            throw new HttpException('Menu ogesi bulunamadi.', 'NOT_FOUND', 404);
        }

        $this->response->success(['message' => 'Durum guncellendi']);
    }

    /**
     * Menu ogesini siler (eski panel gibi kalici DELETE). Alt ogeleri olan oge silinmez; once alt ogeler
     * silinmeli/tasinmali (yetim kayit kalmasin).
     *
     * @Post("items/delete")
     */
    public function delete(): void
    {
        $pdo = $this->db->pdo();
        $id = (int) $this->request->query('id', $this->request->input('id', 0));
        if ($id <= 0) {
            throw new HttpException('Gecersiz ID', 'VALIDATION', 422);
        }

        $children = $pdo->prepare('SELECT COUNT(*) FROM dbo.MenuItems WHERE Cat = :id');
        $children->execute([':id' => $id]);
        if ((int) $children->fetchColumn() > 0) {
            throw new HttpException('Alt ogeleri olan bir menu ogesi silinemez.', 'CHILD_ITEMS', 422);
        }

        $stmt = $pdo->prepare('DELETE FROM dbo.MenuItems WHERE Id = :id');
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() === 0) {
            throw new HttpException('Menu ogesi bulunamadi.', 'NOT_FOUND', 404);
        }

        $this->response->success(['message' => 'Menu ogesi silindi']);
    }

    /**
     * Gorseli uploads/ kokune kaydeder ve dosya adini MenuItems.resim kolonuna yazar.
     * Kolona yazilamazsa dosya geri silinir.
     *
     * @Post("items/image")
     */
    public function image(): void
    {
        $id = (int) $this->request->input('id', 0);
        if ($id <= 0) {
            throw new HttpException('Gecersiz menu ogesi.', 'VALIDATION', 422);
        }

        $filename = Uploads::storeImage('image', self::IMAGE_TYPES);
        $target = Uploads::dir() . DIRECTORY_SEPARATOR . $filename;

        try {
            $stmt = $this->db->pdo()->prepare('UPDATE dbo.MenuItems SET resim = :filename WHERE Id = :id');
            $stmt->execute([':filename' => $filename, ':id' => $id]);
            if ($stmt->rowCount() === 0) {
                throw new HttpException('Menu ogesi bulunamadi.', 'NOT_FOUND', 404);
            }
        } catch (\Throwable $e) {
            @unlink($target);
            throw $e;
        }

        $this->response->success([
            'fileName' => $filename,
            'url' => $this->imageBase() . rawurlencode($filename),
        ]);
    }

    /**
     * Gorseli kolondan kaldirir; dosyayi baska kayit kullanmiyorsa uploads/ klasorunden siler.
     *
     * @Post("items/image/delete")
     */
    public function deleteImage(): void
    {
        $id = (int) $this->request->input('id', 0);
        $pdo = $this->db->pdo();
        $item = $id > 0 ? $this->findItem($pdo, $id) : null;
        if (!$item) {
            throw new HttpException('Menu ogesi bulunamadi.', 'NOT_FOUND', 404);
        }
        $filename = $this->imageName((string) ($item['resim'] ?? ''));

        $pdo->prepare("UPDATE dbo.MenuItems SET resim = '' WHERE Id = :id")->execute([':id' => $id]);

        $fileDeleted = false;
        if ($filename !== '') {
            $used = $pdo->prepare('SELECT COUNT(*) FROM dbo.MenuItems WHERE CAST(resim AS nvarchar(4000)) = :name');
            $used->execute([':name' => $filename]);
            $path = Uploads::dir() . DIRECTORY_SEPARATOR . $filename;
            if ((int) $used->fetchColumn() === 0 && is_file($path)) {
                $fileDeleted = @unlink($path);
            }
        }

        $this->response->success(['message' => 'Gorsel silindi', 'fileDeleted' => $fileDeleted]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function mapItem(array $row, string $imageBase): array
    {
        $resim = $this->imageName((string) ($row['resim'] ?? ''));

        return [
            'id' => (int) $row['Id'],
            'title' => (string) ($row['Title'] ?? ''),
            'metaTitle' => (string) ($row['MetaTitle'] ?? ''),
            'metaUrl' => (string) ($row['MetaUrl'] ?? ''),
            'isActive' => (bool) $row['IsActive'],
            'menuId' => (int) $row['MenuId'],
            'cat' => (int) $row['Cat'],
            'site' => (int) $row['Site'],
            'sort' => $row['Sort'] === null ? null : (int) $row['Sort'],
            'resim' => $resim !== '' ? $imageBase . rawurlencode($resim) : '',
            'resimName' => $resim,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function buildTree(array $items, int $parentId = 0, array $visited = []): array
    {
        $tree = [];
        foreach ($items as $item) {
            if ($item['cat'] !== $parentId || isset($visited[$item['id']])) {
                continue;
            }
            $item['children'] = $this->buildTree($items, $item['id'], $visited + [$item['id'] => true]);
            $tree[] = $item;
        }

        // Ust ogesi bu menude/sitede olmayan (yetim) kayitlar kaybolmasin: kok seviyede gosterilir.
        if ($parentId === 0) {
            $ids = array_column($items, 'id');
            foreach ($items as $item) {
                if ($item['cat'] > 0 && !in_array($item['cat'], $ids, true)) {
                    $item['children'] = $this->buildTree($items, $item['id'], [$item['id'] => true]);
                    $item['orphan'] = true;
                    $tree[] = $item;
                }
            }
        }

        return $tree;
    }

    /**
     * Yeni ust oge / menu gecerli mi; dongu olusturmasin. Yeni MenuId'yi dondurur.
     *
     * @param array<string,mixed> $item
     */
    private function resolveParent(PDO $pdo, array $item, int $cat, int $menuId): int
    {
        $id = (int) $item['Id'];
        if ($cat <= 0) {
            $this->assertMenuExists($pdo, $menuId);
            return $menuId;
        }
        if ($cat === $id) {
            throw new HttpException('Oge kendi altina tasinamaz.', 'INVALID_PARENT', 422);
        }

        $parent = $this->findItem($pdo, $cat);
        if (!$parent || (int) $parent['Site'] !== (int) $item['Site']) {
            throw new HttpException('Ust menu ogesi bulunamadi.', 'INVALID_PARENT', 422);
        }

        // Yeni ust oge, ogenin kendi alt agacindaysa dongu olur.
        $cycle = $pdo->prepare(
            'WITH tree AS (
                SELECT Id FROM dbo.MenuItems WHERE Cat = :id
                UNION ALL
                SELECT mi.Id FROM dbo.MenuItems mi INNER JOIN tree ON mi.Cat = tree.Id
             )
             SELECT COUNT(*) FROM tree WHERE Id = :cat
             OPTION (MAXRECURSION 50)'
        );
        $cycle->execute([':id' => $id, ':cat' => $cat]);
        if ((int) $cycle->fetchColumn() > 0) {
            throw new HttpException('Oge kendi alt ogesinin altina tasinamaz.', 'INVALID_PARENT', 422);
        }

        return (int) $parent['MenuId'];
    }

    private function moveDescendantsToMenu(PDO $pdo, int $id, int $menuId): void
    {
        $pdo->prepare(
            'WITH tree AS (
                SELECT Id FROM dbo.MenuItems WHERE Cat = :id
                UNION ALL
                SELECT mi.Id FROM dbo.MenuItems mi INNER JOIN tree ON mi.Cat = tree.Id
             )
             UPDATE dbo.MenuItems SET MenuId = :menuId WHERE Id IN (SELECT Id FROM tree)
             OPTION (MAXRECURSION 50)'
        )->execute([':id' => $id, ':menuId' => $menuId]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findItem(PDO $pdo, int $id)
    {
        $stmt = $pdo->prepare('SELECT Id, MenuId, Cat, Site, resim FROM dbo.MenuItems WHERE Id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function assertMenuExists(PDO $pdo, int $menuId): void
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM dbo.Menu WHERE MenuId = :menuId');
        $stmt->execute([':menuId' => $menuId]);
        if ($menuId <= 0 || (int) $stmt->fetchColumn() === 0) {
            throw new HttpException('Menu bulunamadi.', 'INVALID_MENU', 422);
        }
    }

    private function boolInput(string $key, bool $default): bool
    {
        $value = $this->request->input($key, null);
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    private function siteId(): int
    {
        foreach (['site', 'Site', 'siteId'] as $key) {
            $value = $this->request->query($key);
            if ($value === null) {
                $value = $this->request->input($key);
            }
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return defined('PRICE_SITE') ? max(1, (int) constant('PRICE_SITE')) : 1;
    }

    // CategoriesController ile ayni: gorseller Cdn/uploads/small/ uzerinden.
    private function imageBase(): string
    {
        $domain = Uploads::cdnDomain();

        return $domain !== '' ? $domain . '/uploads/small/' : '/uploads/small/';
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
}
