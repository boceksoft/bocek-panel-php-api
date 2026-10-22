<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Support\LegacySql;
use App\Support\Uploads;
use PDO;

/*
 * CMS sayfa yonetimi (eski panel sayfalar.asp karsiligi).
 * Tablo siteye gore sayfalar{dbtable}; haber, blog ve disabled kayitlar listelenmez.
 *   GET  /backend-api/cms-pages?site=1
 *   GET  /backend-api/cms-pages/detail?site=1&id=5
 *   POST /backend-api/cms-pages | update | delete | move
 * Gorseller uploads/ kokune kaydedilir (sayfa_duzenle.asp ve resimler.asp karsiligi):
 *   resim, kapak, video_kapak -> sayfalar{dbtable} kolonunda dosya adi
 *   ekstra resimler           -> upload tablosu (islm='sayfa', islm_id, site, sira)
 *   GET  /backend-api/cms-pages/images?site=1&id=5
 *   POST /backend-api/cms-pages/images/upload | update | delete | sort | clear
 * BlogPostsController bu sinifi genisletir (blog=1, islm='blog'); sabitler ve scope() alt sinifta degisir.
 */
class CmsPagesController extends Controller
{
    use LegacySql;

    // upload tablosundaki islm degeri (ekstra resimler).
    protected const ISLM = 'sayfa';

    protected const FIELDS = [
        'baslik' => 'string', 'url' => 'string', 'cat' => 'int', 'siralama' => 'int',
        'aktif' => 'bit', 'favori' => 'bit', 'menu' => 'bit', 'menu2' => 'bit', 'sitemap' => 'bit',
        'kisa_baslik' => 'string', 'kisa_icerik' => 'string', 'icerik' => 'string', 'title' => 'string',
        'description' => 'string', 'keywords' => 'string', 'canonical' => 'string', 'yonlendirme' => 'bit',
        'kampanyakodu' => 'string', 'kampanyayazi' => 'string', 'video' => 'string',
    ];

    // Upload endpoint'i ile doldurulan tekil gorsel kolonlari (formdan dogrudan yazilmaz).
    protected const IMAGE_FIELDS = ['resim', 'kapak', 'video_kapak'];

    protected const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    // Liste agacinda icerik metinleri tasinmaz.
    protected const LIST_FIELDS = ['baslik', 'url', 'cat', 'siralama', 'aktif', 'favori', 'menu', 'menu2', 'sitemap', 'static'];

    /**
     * @Get
     * @query site int Site kimligi (varsayilan 1)
     */
    public function index(): void
    {
        $pdo = $this->db->pdo();
        $table = $this->table($pdo);
        $present = $this->presentColumns($pdo, $table);
        $columns = array_values(array_intersect(static::LIST_FIELDS, $present));
        $select = implode(', ', array_map(function ($field) { return '[' . $field . ']'; }, $columns));
        $stmt = $pdo->prepare('SELECT id, ' . $select . ' FROM dbo.[' . $table . '] WHERE '
            . $this->scope($present) . ' ORDER BY siralama, id');
        $stmt->execute();
        $this->response->success([
            'pages' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'kampanyalarId' => $this->kampanyalarId($pdo),
        ]);
    }

    /**
     * @Get("detail")
     * @query site int Site kimligi (varsayilan 1)
     * @query id int Sayfa kimligi
     */
    public function detail(): void
    {
        $id = $this->queryId('id');
        $pdo = $this->db->pdo();
        $table = $this->table($pdo);
        $present = $this->presentColumns($pdo, $table);
        $columns = array_values(array_intersect(array_merge(array_keys(static::FIELDS), ['static'], static::IMAGE_FIELDS), $present));
        $select = implode(', ', array_map(function ($field) { return '[' . $field . ']'; }, $columns));
        $stmt = $pdo->prepare('SELECT id, ' . $select . ' FROM dbo.[' . $table . '] WHERE id = :id AND '
            . $this->scope($present));
        $this->execute($stmt, ['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new HttpException('Sayfa bulunamadi.', 'NOT_FOUND', 404);
        $this->response->success(['page' => $row, 'imageBase' => $this->imageBase()]);
    }

    /** @Post */
    public function store(): void
    {
        $values = $this->values();
        if (!isset($values['baslik']) || $values['baslik'] === '') {
            throw new HttpException('Sayfa basligi gereklidir.', 'VALIDATION', 422);
        }
        $pdo = $this->db->pdo();
        $table = $this->table($pdo);
        $present = $this->presentColumns($pdo, $table);
        // NOT NULL bit kolonlar ve liste filtresi (haber=0, blog=0) icin varsayilanlar.
        $values += ['cat' => 0, 'aktif' => 0, 'favori' => 0, 'menu' => 0, 'menu2' => 0, 'haber' => 0, 'blog' => 0, 'static' => 0];
        $values = array_intersect_key($values, array_flip($present));
        $pdo->beginTransaction();
        try {
            $rows = $this->hierarchy($pdo, $table, $present);
            $this->validateParent($rows, 0, (int) $values['cat']);
            if (!isset($values['siralama'])) {
                $max = 0;
                foreach ($rows as $row) {
                    if ((int) $row['cat'] === (int) $values['cat']) $max = max($max, (int) $row['siralama']);
                }
                $values['siralama'] = $max + 1;
            }
            $newId = $this->insertRow($pdo, $table, $values);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $this->response->success(['message' => 'Sayfa eklendi', 'id' => $newId], 201);
    }

    /** @Post("update") */
    public function update(): void
    {
        $id = $this->bodyId('id');
        $values = $this->values();
        $pdo = $this->db->pdo();
        $table = $this->table($pdo);
        $present = $this->presentColumns($pdo, $table);
        $values = array_intersect_key($values, array_flip($present));
        if ($values === []) throw new HttpException('Guncellenecek alan gereklidir.', 'VALIDATION', 422);
        $pdo->beginTransaction();
        try {
            $rows = $this->hierarchy($pdo, $table, $present);
            $page = $this->requirePage($rows, $id);
            // Ustu listede olmayan (blog/haber/silinmis vb.) sayfa, cat degismeden kaydedilebilsin.
            if (isset($values['cat']) && $values['cat'] !== (int) $page['cat']) {
                $this->validateParent($rows, $id, $values['cat']);
            }
            $sets = array_map(function ($field) { return '[' . $field . '] = :' . $field; }, array_keys($values));
            $stmt = $pdo->prepare('UPDATE dbo.[' . $table . '] SET ' . implode(', ', $sets) . ' WHERE id = :id');
            $this->execute($stmt, $values + ['id' => $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $this->response->success(['message' => 'Sayfa guncellendi']);
    }

    /**
     * Sayfa satirini tablodan siler (eski paneldeki gibi). Sabit sayfa ve alt sayfasi olan sayfa silinmez.
     * @Post("delete")
     */
    public function delete(): void
    {
        $id = $this->bodyId('id');
        $pdo = $this->db->pdo();
        $table = $this->table($pdo);
        $present = $this->presentColumns($pdo, $table);
        $pdo->beginTransaction();
        try {
            $rows = $this->hierarchy($pdo, $table, $present);
            $page = $this->requirePage($rows, $id);
            if (!empty($page['static'])) throw new HttpException('Sabit sayfa silinemez.', 'STATIC_PAGE', 409);
            foreach ($rows as $row) {
                if ((int) $row['cat'] === $id) {
                    throw new HttpException('Once alt sayfalari tasiyin veya silin.', 'CHILD_PAGES', 409);
                }
            }
            $this->execute($pdo->prepare('DELETE FROM dbo.[' . $table . '] WHERE id = :id'), ['id' => $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $this->response->success(['message' => 'Sayfa silindi']);
    }

    /** @Post("move") */
    public function move(): void
    {
        $id = $this->bodyId('id');
        $targetId = $this->bodyId('targetId');
        $position = $this->request->input('position');
        if ($id === $targetId || !in_array($position, ['inside', 'before', 'after'], true)) {
            throw new HttpException('Gecersiz tasima.', 'VALIDATION', 422);
        }
        $pdo = $this->db->pdo();
        $table = $this->table($pdo);
        $present = $this->presentColumns($pdo, $table);
        $pdo->beginTransaction();
        try {
            $rows = $this->hierarchy($pdo, $table, $present);
            $this->requirePage($rows, $id);
            $target = $this->requirePage($rows, $targetId);
            $parentId = $position === 'inside' ? $targetId : (int) $target['cat'];
            $this->validateParent($rows, $id, $parentId);
            $siblings = [];
            foreach ($rows as $row) {
                if ((int) $row['cat'] === $parentId && (int) $row['id'] !== $id) $siblings[] = (int) $row['id'];
            }
            $index = $position === 'inside' ? count($siblings) : array_search($targetId, $siblings, true);
            if ($position === 'after') ++$index;
            array_splice($siblings, $index, 0, [$id]);
            $stmt = $pdo->prepare('UPDATE dbo.[' . $table . '] SET cat = :cat, siralama = :siralama WHERE id = :id');
            foreach ($siblings as $offset => $siblingId) {
                $this->execute($stmt, ['id' => $siblingId, 'cat' => $parentId, 'siralama' => $offset + 1]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $this->response->success(['message' => 'Sayfa tasindi']);
    }

    /**
     * Sayfanin ekstra resimleri (resimler.asp?islm=sayfa).
     * @Get("images")
     * @query site int Site kimligi (varsayilan 1)
     * @query id int Sayfa kimligi
     */
    public function images(): void
    {
        $id = $this->queryId('id');
        $pdo = $this->db->pdo();
        $this->requireListedPage($pdo, $id);
        $columns = $this->uploadColumns($pdo);
        $select = 'uploadID AS id, FileName AS fileName, sira';
        foreach (['aciklama', 'url'] as $column) {
            if (in_array($column, $columns, true)) $select .= ', [' . $column . ']';
        }
        $stmt = $pdo->prepare('SELECT ' . $select . ' FROM dbo.upload WHERE islm = :islm AND islm_id = :id AND site = :site ORDER BY sira, uploadID');
        $this->execute($stmt, ['islm' => static::ISLM, 'id' => $id, 'site' => $this->siteId()]);
        $this->response->success(['images' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'imageBase' => $this->imageBase()]);
    }

    /**
     * Gorseli uploads/ kokune kaydeder. field=gallery ise upload tablosuna ekstra resim olarak,
     * resim/kapak/video_kapak ise sayfanin kolonuna dosya adi olarak yazar. DB yazimi olmazsa dosya geri silinir.
     * @Post("images/upload")
     */
    public function uploadImage(): void
    {
        $this->assertUploadBody();
        $id = $this->bodyId('id');
        $field = (string) $this->request->input('field', 'gallery');
        if ($field !== 'gallery' && !in_array($field, static::IMAGE_FIELDS, true)) {
            throw new HttpException('Gecersiz gorsel alani.', 'VALIDATION', 422);
        }
        $pdo = $this->db->pdo();
        $table = $this->requireListedPage($pdo, $id);
        if ($field !== 'gallery' && !in_array($field, $this->presentColumns($pdo, $table), true)) {
            throw new HttpException('Sayfa tablosunda bu gorsel kolonu yok.', 'VALIDATION', 422);
        }

        $this->assertUploadedFile();
        $filename = Uploads::storeImage('image', static::IMAGE_TYPES);
        $path = Uploads::dir() . DIRECTORY_SEPARATOR . $filename;
        $uploadId = null;
        // SQL'e kayit olmazsa (hata, 0 satir, id yok) dosya uploads/ klasorunden silinir; kayitsiz dosya kalmaz.
        try {
            if ($field === 'gallery') {
                $uploadId = $this->insertUpload($pdo, $id, $filename, (int) @filesize($path));
            } else {
                $stmt = $pdo->prepare('UPDATE dbo.[' . $table . '] SET [' . $field . '] = :filename WHERE id = :id');
                $this->execute($stmt, ['filename' => $filename, 'id' => $id]);
                if ($stmt->rowCount() === 0) throw new HttpException('Sayfa bulunamadi.', 'NOT_FOUND', 404);
            }
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }

        $this->response->success([
            'id' => $uploadId,
            'field' => $field,
            'fileName' => $filename,
            'url' => $this->imageBase() . rawurlencode($filename),
        ], 201);
    }

    /**
     * Ekstra resmin sira, aciklama ve url bilgisini gunceller (resimler.asp "Resim Duzenle").
     * @Post("images/update")
     */
    public function updateImage(): void
    {
        $uploadId = $this->bodyId('uploadId');
        $pdo = $this->db->pdo();
        $this->requireUpload($pdo, $uploadId);
        $columns = $this->uploadColumns($pdo);
        $values = [];
        $sira = $this->request->input('sira');
        if ($sira !== null) {
            $number = filter_var($sira, FILTER_VALIDATE_INT);
            if ($number === false || $number < 0) throw new HttpException('Gecersiz sira.', 'VALIDATION', 422);
            $values['sira'] = $number;
        }
        foreach (['aciklama', 'url'] as $column) {
            $value = $this->request->input($column);
            if ($value === null || !in_array($column, $columns, true)) continue;
            if (!is_string($value)) throw new HttpException('Gecersiz metin alani.', 'VALIDATION', 422);
            $values[$column] = trim($value);
        }
        if ($values === []) throw new HttpException('Guncellenecek alan gereklidir.', 'VALIDATION', 422);
        $sets = array_map(function ($field) { return '[' . $field . '] = :' . $field; }, array_keys($values));
        $stmt = $pdo->prepare('UPDATE dbo.upload SET ' . implode(', ', $sets) . ' WHERE uploadID = :uploadId');
        $this->execute($stmt, $values + ['uploadId' => $uploadId]);
        $this->response->success(['message' => 'Resim guncellendi']);
    }

    /**
     * Ekstra resmi upload tablosundan kaldirir; dosya uploads/ klasorunde birakilir.
     * @Post("images/delete")
     */
    public function deleteImage(): void
    {
        $uploadId = $this->bodyId('uploadId');
        $pdo = $this->db->pdo();
        $this->requireUpload($pdo, $uploadId);
        $this->execute($pdo->prepare('DELETE FROM dbo.upload WHERE uploadID = :uploadId'), ['uploadId' => $uploadId]);
        $this->response->success(['message' => 'Resim silindi']);
    }

    /**
     * Ekstra resimlerin sirasini verilen uploadID listesine gore 1..n yazar (surukle/birak).
     * @Post("images/sort")
     */
    public function sortImages(): void
    {
        $id = $this->bodyId('id');
        $order = $this->request->input('order');
        if (!is_array($order) || $order === []) throw new HttpException('Sira listesi gereklidir.', 'VALIDATION', 422);
        $pdo = $this->db->pdo();
        $this->requireListedPage($pdo, $id);
        $stmt = $pdo->prepare('UPDATE dbo.upload SET sira = :sira WHERE uploadID = :uploadId AND islm = :islm AND islm_id = :id AND site = :site');
        $pdo->beginTransaction();
        try {
            foreach (array_values($order) as $offset => $raw) {
                $this->execute($stmt, ['sira' => $offset + 1, 'uploadId' => $this->validId($raw), 'islm' => static::ISLM, 'id' => $id, 'site' => $this->siteId()]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $this->response->success(['message' => 'Resim sirasi guncellendi']);
    }

    /**
     * resim/kapak/video_kapak kolonunu bosaltir; dosya uploads/ klasorunde birakilir.
     * @Post("images/clear")
     */
    public function clearImage(): void
    {
        $id = $this->bodyId('id');
        $field = (string) $this->request->input('field', '');
        if (!in_array($field, static::IMAGE_FIELDS, true)) throw new HttpException('Gecersiz gorsel alani.', 'VALIDATION', 422);
        $pdo = $this->db->pdo();
        $table = $this->requireListedPage($pdo, $id);
        if (!in_array($field, $this->presentColumns($pdo, $table), true)) {
            throw new HttpException('Sayfa tablosunda bu gorsel kolonu yok.', 'VALIDATION', 422);
        }
        $this->execute($pdo->prepare('UPDATE dbo.[' . $table . '] SET [' . $field . "] = '' WHERE id = :id"), ['id' => $id]);
        $this->response->success(['message' => 'Gorsel kaldirildi']);
    }

    // Site tablosu sonekini sites tablosundan (yoksa global $SITES'ten) okur.
    protected function table(PDO $pdo): string
    {
        return 'sayfalar' . $this->siteSuffix($pdo);
    }

    // DEBUG: multipart govde PHP'ye bos ulastiysa (id yok) PHP'nin yukleme durumunu hata mesajina ekler.
    protected function assertUploadBody(): void
    {
        if (empty($this->app['debug']) || $this->request->input('id') !== null) return;
        throw new HttpException('Istek govdesi PHP\'ye bos ulasti.' . $this->uploadState(), 'UPLOAD_NO_BODY', 400);
    }

    // DEBUG: $_FILES['image'] yok veya hatali ise PHP hata kodunun anlamini ve yukleme durumunu dondurur.
    protected function assertUploadedFile(): void
    {
        if (empty($this->app['debug'])) return;
        if (!isset($_FILES['image'])) {
            throw new HttpException('Dosya PHP\'ye ulasmadi ($_FILES bos).' . $this->uploadState(), 'UPLOAD_NO_FILE', 400);
        }
        $error = (int) $_FILES['image']['error'];
        if ($error === UPLOAD_ERR_OK) return;
        $reasons = [
            UPLOAD_ERR_INI_SIZE => 'upload_max_filesize limiti asildi',
            UPLOAD_ERR_FORM_SIZE => 'MAX_FILE_SIZE limiti asildi',
            UPLOAD_ERR_PARTIAL => 'dosya yarim geldi',
            UPLOAD_ERR_NO_FILE => 'dosya secilmedi',
            UPLOAD_ERR_NO_TMP_DIR => 'gecici klasor yok veya erisilemiyor (upload_tmp_dir / open_basedir)',
            UPLOAD_ERR_CANT_WRITE => 'gecici klasore yazilamadi (izin)',
            UPLOAD_ERR_EXTENSION => 'bir PHP eklentisi yuklemeyi durdurdu',
        ];
        throw new HttpException('Dosya yuklenemedi: PHP hata kodu ' . $error . ' - ' . ($reasons[$error] ?? 'bilinmiyor') . '.'
            . $this->uploadState(), 'UPLOAD_FAILED', 400);
    }

    protected function uploadState(): string
    {
        $tmpDir = (string) ini_get('upload_tmp_dir');
        $file = $_FILES['image'] ?? null;
        $info = [
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
            'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
            'post_keys' => array_keys($_POST),
            'files_keys' => array_keys($_FILES),
            'files_image' => is_array($file) ? [
                'name' => $file['name'] ?? null, 'size' => $file['size'] ?? null,
                'error' => $file['error'] ?? null, 'tmp_name' => $file['tmp_name'] ?? null,
            ] : null,
            'file_uploads' => ini_get('file_uploads'),
            'post_max_size' => ini_get('post_max_size'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'upload_tmp_dir' => $tmpDir,
            'upload_tmp_dir_exists' => $tmpDir !== '' ? @is_dir($tmpDir) : null,
            'upload_tmp_dir_writable' => $tmpDir !== '' ? @is_writable($tmpDir) : null,
            'sys_get_temp_dir' => sys_get_temp_dir(),
            'open_basedir' => ini_get('open_basedir'),
            'last_error' => error_get_last(),
            'php_ini' => php_ini_loaded_file(),
            'php_version' => PHP_VERSION,
        ];
        return ' | DEBUG: ' . json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // Sayfa bu ekranda listeleniyorsa tablo adini dondurur.
    protected function requireListedPage(PDO $pdo, int $id): string
    {
        $table = $this->table($pdo);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM dbo.[' . $table . '] WHERE id = :id AND ' . $this->scope($this->presentColumns($pdo, $table)));
        $this->execute($stmt, ['id' => $id]);
        if ((int) $stmt->fetchColumn() === 0) throw new HttpException('Sayfa bulunamadi.', 'NOT_FOUND', 404);
        return $table;
    }

    // Sadece bu sitedeki, listelenen bir sayfaya ait ekstra resim.
    protected function requireUpload(PDO $pdo, int $uploadId): void
    {
        $stmt = $pdo->prepare('SELECT islm_id FROM dbo.upload WHERE uploadID = :uploadId AND islm = :islm AND site = :site');
        $this->execute($stmt, ['uploadId' => $uploadId, 'islm' => static::ISLM, 'site' => $this->siteId()]);
        $pageId = $stmt->fetchColumn();
        if ($pageId === false) throw new HttpException('Resim bulunamadi.', 'NOT_FOUND', 404);
        $this->requireListedPage($pdo, (int) $pageId);
    }

    // ImgDownloadController ile ayni kayit bicimi; islm=static::ISLM, sira dizinin sonu.
    protected function insertUpload(PDO $pdo, int $pageId, string $filename, int $size): int
    {
        $columns = $this->uploadColumns($pdo);
        $site = $this->siteId();
        $stmt = $pdo->prepare('SELECT ISNULL(MAX(sira), 0) FROM dbo.upload WHERE islm = :islm AND islm_id = :id AND site = :site');
        $this->execute($stmt, ['islm' => static::ISLM, 'id' => $pageId, 'site' => $site]);
        $values = [
            'FileName' => $filename, 'FileSize' => $size, 'islm' => static::ISLM, 'islm_id' => $pageId,
            'sira' => (int) $stmt->fetchColumn() + 1, 'site' => $site,
        ];
        // NOT NULL olabilen metin kolonlari eski kayitlardaki gibi bos string.
        foreach (['aciklama', 'aciklama4', 'url'] as $column) {
            if (in_array($column, $columns, true)) $values[$column] = '';
        }
        $raw = in_array('dateofupload', $columns, true) ? ['DateOfUpload' => 'GETDATE()'] : [];
        return $this->insertRow($pdo, 'upload', $values, $raw);
    }

    protected function uploadColumns(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT LOWER(COLUMN_NAME) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'upload'");
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'c');
    }

    // yeni_sayfa_ekle.asp: kampanya alanlari sadece qsql("kampanyalarID") altindaki sayfalarda gosterilir.
    protected function kampanyalarId(PDO $pdo): int
    {
        $table = $this->siteId() === 2 ? 'genel_s2' : 'genel';
        try {
            $row = $pdo->query("SELECT TOP 1 kampanyalarID FROM {$table}")->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return 0;
        }
        return is_array($row) ? (int) ($row['kampanyalarID'] ?? reset($row)) : 0;
    }

    protected function presentColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare("SELECT LOWER(COLUMN_NAME) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = :t");
        $stmt->execute([':t' => $table]);
        $present = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'c');
        if ($present === []) throw new HttpException('Sayfa tablosu bulunamadi.', 'SITE_NOT_FOUND', 404);
        return $present;
    }

    // sayfalar.asp'deki liste filtresi.
    protected function scope(array $present): string
    {
        $where = ['1 = 1'];
        if (in_array('haber', $present, true)) $where[] = 'ISNULL(haber, 0) = 0';
        if (in_array('blog', $present, true)) $where[] = 'ISNULL(blog, 0) = 0';
        if (in_array('disabled', $present, true)) $where[] = 'disabled IS NULL';
        return implode(' AND ', $where);
    }

    protected function values(): array
    {
        $input = $this->request->json();
        $values = [];
        foreach (static::FIELDS as $field => $type) {
            if (!array_key_exists($field, $input)) continue;
            $value = $input[$field];
            if ($type === 'string') {
                if ($value !== null && !is_string($value)) throw new HttpException('Gecersiz metin alani.', 'VALIDATION', 422);
                $values[$field] = trim((string) $value);
                if ($field === 'baslik' && $values[$field] === '') throw new HttpException('Sayfa basligi gereklidir.', 'VALIDATION', 422);
            } elseif ($type === 'datetime') {
                // "2026-10-21 14:30", "2026-10-21T14:30" veya saniyeli; SQL'e dil ayarindan bagimsiz ISO 8601 (2026-10-21T14:30:00) gider.
                $text = is_string($value) ? str_replace('T', ' ', trim($value)) : '';
                $date = false;
                foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
                    $date = \DateTime::createFromFormat('!' . $format, $text);
                    if ($date !== false) break;
                }
                if ($date === false) throw new HttpException('Gecersiz tarih.', 'VALIDATION', 422);
                $values[$field] = $date->format('Y-m-d\TH:i:s');
            } else {
                if ($type === 'bit' && is_bool($value)) $value = (int) $value;
                $number = filter_var($value, FILTER_VALIDATE_INT);
                if ($number === false || $number < 0 || $number > 2147483647 || ($type === 'bit' && $number > 1)) {
                    throw new HttpException('Gecersiz sayisal alan.', 'VALIDATION', 422);
                }
                $values[$field] = $number;
            }
        }
        return $values;
    }

    protected function hierarchy(PDO $pdo, string $table, array $present): array
    {
        $static = in_array('static', $present, true) ? ', static' : '';
        // Eszamanli tasimalar dongu olusturmasin diye hiyerarsi kilitlenir.
        $stmt = $pdo->prepare('SELECT id, cat, siralama' . $static . ' FROM dbo.[' . $table . '] WITH (UPDLOCK, HOLDLOCK) WHERE '
            . $this->scope($present) . ' ORDER BY siralama, id');
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function requirePage(array $rows, int $id): array
    {
        foreach ($rows as $row) if ((int) $row['id'] === $id) return $row;
        throw new HttpException('Sayfa bulunamadi.', 'NOT_FOUND', 404);
    }

    protected function validateParent(array $rows, int $id, int $parentId): void
    {
        $parents = [];
        foreach ($rows as $row) $parents[(int) $row['id']] = (int) $row['cat'];
        $visited = [];
        while ($parentId !== 0) {
            if ($parentId === $id || isset($visited[$parentId]) || !array_key_exists($parentId, $parents)) {
                throw new HttpException('Gecersiz ust sayfa.', 'INVALID_PARENT', 422);
            }
            $visited[$parentId] = true;
            $parentId = $parents[$parentId];
        }
    }
}
