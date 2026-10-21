<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
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
 */
final class CmsPagesController extends Controller
{
    private const FIELDS = [
        'baslik' => 'string', 'url' => 'string', 'cat' => 'int', 'siralama' => 'int',
        'aktif' => 'bit', 'favori' => 'bit', 'menu' => 'bit', 'menu2' => 'bit', 'sitemap' => 'bit',
        'kisa_baslik' => 'string', 'kisa_icerik' => 'string', 'icerik' => 'string', 'title' => 'string',
        'description' => 'string', 'keywords' => 'string', 'canonical' => 'string', 'yonlendirme' => 'bit',
        'kampanyakodu' => 'string', 'kampanyayazi' => 'string', 'video' => 'string',
    ];

    // Upload endpoint'i ile doldurulan tekil gorsel kolonlari (formdan dogrudan yazilmaz).
    private const IMAGE_FIELDS = ['resim', 'kapak', 'video_kapak'];

    private const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    // Liste agacinda icerik metinleri tasinmaz.
    private const LIST_FIELDS = ['baslik', 'url', 'cat', 'siralama', 'aktif', 'favori', 'menu', 'menu2', 'sitemap', 'static'];

    /**
     * @Get
     * @query site int Site kimligi (varsayilan 1)
     */
    public function index(): void
    {
        $pdo = $this->db->pdo();
        $table = $this->table($pdo);
        $present = $this->presentColumns($pdo, $table);
        $columns = array_values(array_intersect(self::LIST_FIELDS, $present));
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
        $columns = array_values(array_intersect(array_merge(array_keys(self::FIELDS), ['static'], self::IMAGE_FIELDS), $present));
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
            $columns = array_keys($values);
            $stmt = $pdo->prepare('INSERT INTO dbo.[' . $table . '] ([' . implode('], [', $columns)
                . ']) OUTPUT INSERTED.id VALUES (:' . implode(', :', $columns) . ')');
            $this->execute($stmt, $values);
            $newId = (int) $stmt->fetchColumn();
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
     * Kayit silinmez; disabled = 1 ile listeden kaldirilir.
     * @Post("delete")
     */
    public function delete(): void
    {
        $id = $this->bodyId('id');
        $pdo = $this->db->pdo();
        $table = $this->table($pdo);
        $present = $this->presentColumns($pdo, $table);
        if (!in_array('disabled', $present, true)) {
            throw new HttpException('Sayfa tablosunda disabled kolonu yok.', 'DELETE_UNSUPPORTED', 409);
        }
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
            $this->execute($pdo->prepare('UPDATE dbo.[' . $table . '] SET disabled = 1 WHERE id = :id'), ['id' => $id]);
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
        $stmt = $pdo->prepare('SELECT ' . $select . " FROM dbo.upload WHERE islm = 'sayfa' AND islm_id = :id AND site = :site ORDER BY sira, uploadID");
        $this->execute($stmt, ['id' => $id, 'site' => $this->siteId()]);
        $this->response->success(['images' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'imageBase' => $this->imageBase()]);
    }

    /**
     * Gorseli uploads/ kokune kaydeder. field=gallery ise upload tablosuna ekstra resim olarak,
     * resim/kapak/video_kapak ise sayfanin kolonuna dosya adi olarak yazar. DB yazimi olmazsa dosya geri silinir.
     * @Post("images/upload")
     */
    public function uploadImage(): void
    {
        $id = $this->bodyId('id');
        $field = (string) $this->request->input('field', 'gallery');
        if ($field !== 'gallery' && !in_array($field, self::IMAGE_FIELDS, true)) {
            throw new HttpException('Gecersiz gorsel alani.', 'VALIDATION', 422);
        }
        $pdo = $this->db->pdo();
        $table = $this->requireListedPage($pdo, $id);
        if ($field !== 'gallery' && !in_array($field, $this->presentColumns($pdo, $table), true)) {
            throw new HttpException('Sayfa tablosunda bu gorsel kolonu yok.', 'VALIDATION', 422);
        }

        $filename = Uploads::storeImage('image', self::IMAGE_TYPES);
        $path = Uploads::dir() . DIRECTORY_SEPARATOR . $filename;
        $uploadId = null;
        try {
            if ($field === 'gallery') {
                $uploadId = $this->insertUpload($pdo, $id, $filename, (int) @filesize($path));
            } else {
                $stmt = $pdo->prepare('UPDATE dbo.[' . $table . '] SET [' . $field . '] = :filename WHERE id = :id');
                $this->execute($stmt, ['filename' => $filename, 'id' => $id]);
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
        $stmt = $pdo->prepare("UPDATE dbo.upload SET sira = :sira WHERE uploadID = :uploadId AND islm = 'sayfa' AND islm_id = :id AND site = :site");
        $pdo->beginTransaction();
        try {
            foreach (array_values($order) as $offset => $raw) {
                $this->execute($stmt, ['sira' => $offset + 1, 'uploadId' => $this->validId($raw), 'id' => $id, 'site' => $this->siteId()]);
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
        if (!in_array($field, self::IMAGE_FIELDS, true)) throw new HttpException('Gecersiz gorsel alani.', 'VALIDATION', 422);
        $pdo = $this->db->pdo();
        $table = $this->requireListedPage($pdo, $id);
        if (!in_array($field, $this->presentColumns($pdo, $table), true)) {
            throw new HttpException('Sayfa tablosunda bu gorsel kolonu yok.', 'VALIDATION', 422);
        }
        $this->execute($pdo->prepare('UPDATE dbo.[' . $table . '] SET [' . $field . "] = '' WHERE id = :id"), ['id' => $id]);
        $this->response->success(['message' => 'Gorsel kaldirildi']);
    }

    private function siteId(): int
    {
        $value = $this->request->query('site', $this->request->input('site', 1));
        $site = filter_var($value, FILTER_VALIDATE_INT);
        if ($site === false || $site <= 0) throw new HttpException('Gecersiz site.', 'VALIDATION', 422);
        return $site;
    }

    // Site tablosu sonekini sites tablosundan (yoksa global $SITES'ten) okur.
    private function table(PDO $pdo): string
    {
        $siteId = $this->siteId();
        $sites = [];
        try {
            $sites = $pdo->query('SELECT id, dbtable FROM sites')->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            global $SITES;
            if (isset($SITES) && is_array($SITES)) {
                foreach ($SITES as $id => $site) {
                    if (is_array($site)) $sites[] = ['id' => $id, 'dbtable' => $site['dbtable'] ?? ''];
                }
            }
        }
        if ($sites === [] && $siteId === 1) return 'sayfalar';
        foreach ($sites as $site) {
            if ((int) $site['id'] !== $siteId) continue;
            $suffix = trim((string) ($site['dbtable'] ?? ''));
            if ($suffix !== '' && preg_match('/^_[A-Za-z0-9]+$/', $suffix) !== 1) break;
            return 'sayfalar' . $suffix;
        }
        throw new HttpException('Site bulunamadi.', 'SITE_NOT_FOUND', 404);
    }

    // Sayfa bu ekranda listeleniyorsa tablo adini dondurur.
    private function requireListedPage(PDO $pdo, int $id): string
    {
        $table = $this->table($pdo);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM dbo.[' . $table . '] WHERE id = :id AND ' . $this->scope($this->presentColumns($pdo, $table)));
        $this->execute($stmt, ['id' => $id]);
        if ((int) $stmt->fetchColumn() === 0) throw new HttpException('Sayfa bulunamadi.', 'NOT_FOUND', 404);
        return $table;
    }

    // Sadece bu sitedeki, listelenen bir sayfaya ait ekstra resim.
    private function requireUpload(PDO $pdo, int $uploadId): void
    {
        $stmt = $pdo->prepare("SELECT islm_id FROM dbo.upload WHERE uploadID = :uploadId AND islm = 'sayfa' AND site = :site");
        $this->execute($stmt, ['uploadId' => $uploadId, 'site' => $this->siteId()]);
        $pageId = $stmt->fetchColumn();
        if ($pageId === false) throw new HttpException('Resim bulunamadi.', 'NOT_FOUND', 404);
        $this->requireListedPage($pdo, (int) $pageId);
    }

    // ImgDownloadController ile ayni kayit bicimi; islm='sayfa', sira dizinin sonu.
    private function insertUpload(PDO $pdo, int $pageId, string $filename, int $size): int
    {
        $columns = $this->uploadColumns($pdo);
        $site = $this->siteId();
        $stmt = $pdo->prepare("SELECT ISNULL(MAX(sira), 0) FROM dbo.upload WHERE islm = 'sayfa' AND islm_id = :id AND site = :site");
        $this->execute($stmt, ['id' => $pageId, 'site' => $site]);
        $values = [
            'FileName' => $filename, 'FileSize' => $size, 'islm' => 'sayfa', 'islm_id' => $pageId,
            'sira' => (int) $stmt->fetchColumn() + 1, 'site' => $site,
        ];
        // NOT NULL olabilen metin kolonlari eski kayitlardaki gibi bos string.
        foreach (['aciklama', 'aciklama4', 'url'] as $column) {
            if (in_array($column, $columns, true)) $values[$column] = '';
        }
        $names = array_keys($values);
        $date = in_array('dateofupload', $columns, true) ? ', DateOfUpload' : '';
        $stmt = $pdo->prepare('INSERT INTO dbo.upload ([' . implode('], [', $names) . ']' . $date . ') OUTPUT INSERTED.uploadID VALUES (:'
            . implode(', :', $names) . ($date !== '' ? ', GETDATE()' : '') . ')');
        $this->execute($stmt, $values);
        return (int) $stmt->fetchColumn();
    }

    private function uploadColumns(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT LOWER(COLUMN_NAME) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'upload'");
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'c');
    }

    private function imageBase(): string
    {
        return Uploads::cdnDomain() . '/uploads/small/';
    }

    // yeni_sayfa_ekle.asp: kampanya alanlari sadece qsql("kampanyalarID") altindaki sayfalarda gosterilir.
    private function kampanyalarId(PDO $pdo): int
    {
        $table = $this->siteId() === 2 ? 'genel_s2' : 'genel';
        try {
            $row = $pdo->query("SELECT TOP 1 kampanyalarID FROM {$table}")->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return 0;
        }
        return is_array($row) ? (int) ($row['kampanyalarID'] ?? reset($row)) : 0;
    }

    private function presentColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare("SELECT LOWER(COLUMN_NAME) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = :t");
        $stmt->execute([':t' => $table]);
        $present = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'c');
        if ($present === []) throw new HttpException('Sayfa tablosu bulunamadi.', 'SITE_NOT_FOUND', 404);
        return $present;
    }

    // sayfalar.asp'deki liste filtresi.
    private function scope(array $present): string
    {
        $where = ['1 = 1'];
        if (in_array('haber', $present, true)) $where[] = 'ISNULL(haber, 0) = 0';
        if (in_array('blog', $present, true)) $where[] = 'ISNULL(blog, 0) = 0';
        if (in_array('disabled', $present, true)) $where[] = 'disabled IS NULL';
        return implode(' AND ', $where);
    }

    private function values(): array
    {
        $input = $this->request->json();
        $values = [];
        foreach (self::FIELDS as $field => $type) {
            if (!array_key_exists($field, $input)) continue;
            $value = $input[$field];
            if ($type === 'string') {
                if ($value !== null && !is_string($value)) throw new HttpException('Gecersiz metin alani.', 'VALIDATION', 422);
                $values[$field] = trim((string) $value);
                if ($field === 'baslik' && $values[$field] === '') throw new HttpException('Sayfa basligi gereklidir.', 'VALIDATION', 422);
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

    private function queryId(string $key): int
    {
        return $this->validId($this->request->query($key));
    }

    private function bodyId(string $key): int
    {
        return $this->validId($this->request->input($key));
    }

    private function validId($raw): int
    {
        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false || $value <= 0 || $value > 2147483647) throw new HttpException('Gecersiz ID.', 'VALIDATION', 422);
        return $value;
    }

    private function execute(\PDOStatement $stmt, array $values): void
    {
        foreach ($values as $key => $value) $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $stmt->execute();
    }

    private function hierarchy(PDO $pdo, string $table, array $present): array
    {
        $static = in_array('static', $present, true) ? ', static' : '';
        // Eszamanli tasimalar dongu olusturmasin diye hiyerarsi kilitlenir.
        $stmt = $pdo->prepare('SELECT id, cat, siralama' . $static . ' FROM dbo.[' . $table . '] WITH (UPDLOCK, HOLDLOCK) WHERE '
            . $this->scope($present) . ' ORDER BY siralama, id');
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function requirePage(array $rows, int $id): array
    {
        foreach ($rows as $row) if ((int) $row['id'] === $id) return $row;
        throw new HttpException('Sayfa bulunamadi.', 'NOT_FOUND', 404);
    }

    private function validateParent(array $rows, int $id, int $parentId): void
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
