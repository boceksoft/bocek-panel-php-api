<?php

declare(strict_types=1);

namespace App\Controller;

final class CategoriesController extends Controller
{ 
    /**
     * @Get
     */ 
    public function index(): void
    {
        $pdo = $this->db->pdo();
        
        $siteId = $this->siteId();
        $titleColumn = $this->titleColumn($siteId);
        
        // Fetch categories from `tip` table
        $categoriesQuery = "
            SELECT 
                id, 
                {$titleColumn} AS baslik, 
                kisa_baslik, 
                kisa_icerik, 
                icerik, 
                icon, 
                kapak, 
                resim, 
                vitrin, 
                cat, 
                aktif, 
                search, 
                favori, 
                menu, 
                siralama, 
                title, 
                description, 
                keywords, 
                url, 
                canonical, 
                DateCreated, 
                dateModified
            FROM tip 
            ORDER BY siralama ASC
        ";
        
        $categories = $pdo->query($categoriesQuery)->fetchAll();
        $imageBase = $this->imageBase();
        foreach ($categories as &$category) {
            foreach (['resim', 'kapak'] as $field) {
                $category[$field] = $this->imageUrl((string) ($category[$field] ?? ''), $imageBase);
            }
        }
        unset($category);

        $this->response->success([
            'categories' => $this->buildCategoryTree($categories),
            'image_base' => $this->imageBase(),
        ]);
    }

    /**
     * @Get("regions")
     */
    public function regions(): void
    {
        $pdo = $this->db->pdo();
        $titleColumn = $this->titleColumn($this->siteId());

        // Fetch regions from `destinations` table
        $regionsQuery = "
            SELECT 
                id, 
                {$titleColumn} AS baslik, 
                cat, 
                siralama 
            FROM destinations 
            WHERE aktif=1 
            ORDER BY siralama ASC
        ";
        $allDestinations = $pdo->query($regionsQuery)->fetchAll();
        $regions = $this->buildRegionTree($allDestinations);
        
        $this->response->success(['regions' => $regions]);
    }
    

    private function buildCategoryTree(array $categories): array
    {
        $map = [];
        foreach ($categories as $cat) {
            $cat['sub_categories'] = [];
            $map[$cat['id']] = $cat;
        }

        $tree = [];
        foreach ($categories as $cat) {
            if ((int) $cat['cat'] === 0) {
                $tree[] = &$map[$cat['id']];
            } elseif (isset($map[$cat['cat']])) {
                $map[$cat['cat']]['sub_categories'][] = &$map[$cat['id']];
            } else {
                $tree[] = &$map[$cat['id']];
            }
        }

        return $tree;
    }

    private function buildRegionTree(array $destinations): array
    {
        $map = [];
        foreach ($destinations as $dest) {
            $dest['sub_regions'] = [];
            $map[$dest['id']] = $dest;
        }

        $regions = [];
        foreach ($destinations as $dest) {
            if ((int) $dest['cat'] === 0) {
                $regions[] = &$map[$dest['id']];
            } elseif (isset($map[$dest['cat']])) {
                $map[$dest['cat']]['sub_regions'][] = &$map[$dest['id']];
            }
        }

        return $regions;
    }
    
    /**
     * @Post
     */
    public function store(): void
    {
        $pdo = $this->db->pdo();
        
        $kisa_baslik = trim((string) $this->request->input('kisa_baslik', ''));
        $baslik = trim((string) $this->request->input('baslik', ''));
        $url = trim((string) $this->request->input('url', ''));
        $cat = (int) $this->request->input('cat', 0);
        $siralama = (int) $this->request->input('siralama', 0);
        $aktif = (int) $this->request->input('aktif', 1);
        $kisa_icerik = trim((string) $this->request->input('kisa_icerik', ''));
        $icerik = trim((string) $this->request->input('icerik', ''));
        $icon = trim((string) $this->request->input('icon', ''));
        $kapak = $this->imageName((string) $this->request->input('kapak', ''));
        $resim = $this->imageName((string) $this->request->input('resim', ''));
        $vitrin = (int) $this->request->input('vitrin', 0);
        $search = (int) $this->request->input('search', 0);
        $favori = (int) $this->request->input('favori', 0);
        $menu = (int) $this->request->input('menu', 0);
        $title = trim((string) $this->request->input('title', ''));
        $description = trim((string) $this->request->input('description', ''));
        $keywords = trim((string) $this->request->input('keywords', ''));
        $canonical = trim((string) $this->request->input('canonical', ''));
        
        $sql = "INSERT INTO dbo.tip (kisa_baslik, baslik, url, cat, siralama, aktif, kisa_icerik, icerik, icon, kapak, 
                resim, vitrin, search, favori, menu, title, description, keywords, canonical) 
                VALUES (:kisa_baslik, :baslik, :url, :cat, :siralama, :aktif, :kisa_icerik, :icerik, :icon, :kapak, 
                :resim, :vitrin, :search, :favori, :menu, :title, :description, :keywords, :canonical)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':kisa_baslik' => $kisa_baslik,
            ':baslik' => $baslik,
            ':url' => $url,
            ':cat' => $cat,
            ':siralama' => $siralama,
            ':aktif' => $aktif,
            ':kisa_icerik' => $kisa_icerik,
            ':icerik' => $icerik,
            ':icon' => $icon,
            ':kapak' => $kapak,
            ':resim' => $resim,
            ':vitrin' => $vitrin,
            ':search' => $search,
            ':favori' => $favori,
            ':menu' => $menu,
            ':title' => $title,
            ':description' => $description,
            ':keywords' => $keywords,
            ':canonical' => $canonical
        ]);
        
        $this->response->success(['message' => 'Kategori eklendi', 'id' => (int) $pdo->lastInsertId()]);
    }

    /**
     * @Post("update")
     */
    public function update(): void
    {
        $pdo = $this->db->pdo();
        
        $id = (int) $this->request->input('id', 0);
        if ($id <= 0) {
            throw new \App\Core\HttpException('Gecersiz ID', 'VALIDATION', 422);
        }

        $fields = [
            'kisa_baslik' => 'string',
            'baslik' => 'string',
            'url' => 'string',
            'cat' => 'int',
            'siralama' => 'int',
            'aktif' => 'int',
            'kisa_icerik' => 'string',
            'icerik' => 'string',
            'icon' => 'string',
            'kapak' => 'string',
            'resim' => 'string',
            'vitrin' => 'int',
            'search' => 'int',
            'favori' => 'int',
            'menu' => 'int',
            'title' => 'string',
            'description' => 'string',
            'keywords' => 'string',
            'canonical' => 'string'
        ];

        $setParts = [];
        $params = [':id' => $id];

        foreach ($fields as $field => $type) {
            $val = $this->request->input($field, null);
            if ($val !== null) {
                $setParts[] = "{$field} = :{$field}";
                if ($field === 'resim' || $field === 'kapak') {
                    $params[":{$field}"] = $this->imageName((string) $val);
                } else {
                    $params[":{$field}"] = ($type === 'int') ? (int) $val : trim((string) $val);
                }
            }
        }

        if (empty($setParts)) {
            $this->response->success(['message' => 'Guncellenecek alan bulunamadi']);
            return;
        }

        $sql = "UPDATE dbo.tip SET " . implode(', ', $setParts) . " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $this->response->success(['message' => 'Kategori guncellendi']);
    }

    /**
     * @Post("delete")
     */
    public function delete(): void
    {
        $pdo = $this->db->pdo();
        
        $id = (int) $this->request->query('id', $this->request->input('id', 0));
        if ($id <= 0) {
            throw new \App\Core\HttpException('Gecersiz ID', 'VALIDATION', 422);
        }
        
        $sql = "DELETE FROM dbo.tip WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        
        $this->response->success(['message' => 'Kategori silindi']);
    }

    /**
     * Kategori gorselini uploads/ kokune kaydeder (product/ altina degil) ve dosya adini tip.resim / tip.kapak
     * kolonuna yazar. Kolona yazilamazsa dosya geri silinir; kayitsiz dosya birakilmaz.
     *
     * @Post("image")
     */
    public function image(): void
    {
        $id = (int) $this->request->input('id', 0);
        $field = (string) $this->request->input('field', '');
        if ($id <= 0 || !in_array($field, ['resim', 'kapak'], true)) {
            throw new \App\Core\HttpException('Gecersiz kategori veya gorsel alani.', 'VALIDATION', 422);
        }
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            throw new \App\Core\HttpException('Gecerli bir dosya yuklenmedi.', 'UPLOAD_FAILED', 400);
        }
        if ($_FILES['image']['size'] > 10 * 1024 * 1024) {
            throw new \App\Core\HttpException('Dosya boyutu 10MB limitini asiyor.', 'FILE_TOO_LARGE', 400);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['image']['tmp_name']);
        finfo_close($finfo);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime])) {
            throw new \App\Core\HttpException('Sadece JPEG, PNG ve WEBP formatlari desteklenmektedir.', 'INVALID_FILE_TYPE', 400);
        }

        try {
            $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        } catch (\Exception $e) {
            $filename = md5(uniqid('', true)) . '.' . $extensions[$mime];
        }

        $target = $this->uploadsDir() . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
            throw new \App\Core\HttpException('Gorsel kaydedilemedi.', 'SAVE_FAILED', 500);
        }

        try {
            $stmt = $this->db->pdo()->prepare("UPDATE dbo.tip SET {$field} = :filename WHERE id = :id");
            $stmt->execute([':filename' => $filename, ':id' => $id]);
            if ($stmt->rowCount() === 0) {
                throw new \App\Core\HttpException('Kategori bulunamadi.', 'NOT_FOUND', 404);
            }
        } catch (\Throwable $e) {
            @unlink($target);
            throw $e;
        }

        $this->response->success([
            'fileName' => $filename,
            'url' => $this->imageBase() . $filename,
        ]);
    }

    /**
     * Kategori gorselini tip kolonundan kaldirir ve dosyayi uploads/ klasorunden siler.
     * Ayni dosyayi kullanan baska bir tip kaydi varsa dosya silinmez.
     *
     * @Post("image/delete")
     */
    public function deleteImage(): void
    {
        $id = (int) $this->request->input('id', 0);
        $field = (string) $this->request->input('field', '');
        if ($id <= 0 || !in_array($field, ['resim', 'kapak'], true)) {
            throw new \App\Core\HttpException('Gecersiz kategori veya gorsel alani.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare("SELECT {$field} FROM dbo.tip WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            throw new \App\Core\HttpException('Kategori bulunamadi.', 'NOT_FOUND', 404);
        }
        $filename = $this->imageName((string) $current);

        $pdo->prepare("UPDATE dbo.tip SET {$field} = '' WHERE id = :id")->execute([':id' => $id]);

        $fileDeleted = false;
        if ($filename !== '') {
            $used = $pdo->prepare('SELECT COUNT(*) FROM dbo.tip WHERE CAST(resim AS nvarchar(4000)) = :name1 OR CAST(kapak AS nvarchar(4000)) = :name2');
            $used->execute([':name1' => $filename, ':name2' => $filename]);
            $path = $this->uploadsDir() . DIRECTORY_SEPARATOR . $filename;
            if ((int) $used->fetchColumn() === 0 && is_file($path)) {
                $fileDeleted = @unlink($path);
            }
        }

        $this->response->success(['message' => 'Gorsel silindi', 'fileDeleted' => $fileDeleted]);
    }

    // ImgDownloadController ile ayni uploads klasoru cozumlemesi (resizerPHP/config.json "base").
    private function uploadsDir(): string
    {
        $rootDir = dirname(__DIR__, 4);
        $cdnFolder = 'cdn';
        if (defined('Cdn')) {
            $parsedHost = parse_url((string) constant('Cdn'), PHP_URL_HOST);
            if ($parsedHost) {
                $domainHost = defined('Domain') ? parse_url((string) constant('Domain'), PHP_URL_HOST) : '';
                $cdnFolder = (strpos($parsedHost, 'www.') === 0 || ($domainHost && $parsedHost === $domainHost))
                    ? 'httpdocs'
                    : $parsedHost;
            }
        }

        $uploadsDir = $rootDir . DIRECTORY_SEPARATOR . 'httpdocs' . DIRECTORY_SEPARATOR . 'uploads';
        $configPath = $rootDir . DIRECTORY_SEPARATOR . $cdnFolder . DIRECTORY_SEPARATOR . 'resizerPHP' . DIRECTORY_SEPARATOR . 'config.json';
        if (@file_exists($configPath)) {
            $cdnConfig = json_decode((string) @file_get_contents($configPath), true);
            if (is_array($cdnConfig) && isset($cdnConfig['base'])) {
                $calculated = realpath(dirname($configPath) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $cdnConfig['base']));
                if ($calculated && is_dir($calculated)) {
                    $uploadsDir = $calculated;
                }
            }
        }

        if (!is_dir($uploadsDir)) {
            throw new \App\Core\HttpException('Uploads klasoru bulunamadi.', 'UPLOADS_DIR_INVALID', 500);
        }

        return $uploadsDir;
    }

    // HomesController::cdnBase ile ayni: Cdn yoksa Domain, gorseller /uploads/small/ uzerinden.
    private function imageBase(): string
    {
        $domain = defined('Cdn') ? trim((string) constant('Cdn')) : '';
        if ($domain === '') {
            $domain = defined('Domain') ? trim((string) constant('Domain')) : '';
        }
        $domain = rtrim($domain, '/');
        if ($domain !== '' && preg_match('#^https?://#i', $domain) !== 1) {
            $domain = 'https://' . $domain;
        }

        return $domain !== '' ? $domain . '/uploads/small/' : '/uploads/small/';
    }

    private function imageUrl(string $value, string $imageBase): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('#^https?://#i', $value) === 1) {
            return $value;
        }

        return $imageBase . rawurlencode($this->imageName($value));
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

    private function siteId(): int
    {
        foreach (['site', 'Site', 'site_id', 'SiteId', 'siteId', 'currentSite', 'currentSiteId'] as $key) {
            $value = $this->request->query($key);
            if ($value === null) {
                $value = $this->request->input($key);
            }
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        foreach (['X-Site', 'X-Site-Id', 'X-Current-Site', 'X-Current-Site-Id'] as $header) {
            $value = $this->request->header($header);
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            } 
        }

        return defined('PRICE_SITE') ? max(1, (int) constant('PRICE_SITE')) : 1;
    }

    private function titleColumn(int $siteId): string
    {
        return 'baslik' . $this->siteSuffix($siteId);
    }

    private function siteSuffix(int $siteId): string
    {
        if ($siteId <= 1) {
            return '';
        }

        $suffixes = $this->app['site_column_suffixes'] ?? [];
        $suffix = is_array($suffixes)
            ? (string) ($suffixes[$siteId] ?? ($suffixes[(string) $siteId] ?? ('_s' . $siteId)))
            : '_s' . $siteId;

        return preg_match('/^_[A-Za-z0-9]+$/', $suffix) === 1 ? $suffix : '';
    }
}
