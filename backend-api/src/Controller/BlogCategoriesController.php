<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Support\LegacySql;
use App\Support\Uploads;
use PDO;

/*
 * Blog kategorileri (eski panel blog_kategorileri.asp karsiligi), tablo blog_kategorileri.
 * Siteye gore kolonlar {alan}{dbtable}: ilk site duz ad (baslik), digerleri sonekli (baslik_s2).
 *   GET  /backend-api/blog-categories
 *   POST /backend-api/blog-categories | update | delete | sort
 *   POST /backend-api/blog-categories/images/upload | clear   (field=icon{dbtable})
 * Gonderim duz anahtarlarla: {"baslik": "...", "baslik_s2": "...", "color": "#ff0000", "aktif": 1}
 */
final class BlogCategoriesController extends Controller
{
    use LegacySql;

    private const TABLE = 'blog_kategorileri';

    private const SITE_FIELDS = ['baslik', 'title', 'keywords', 'description', 'url'];

    private const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    /** @Get */
    public function index(): void
    {
        $pdo = $this->db->pdo();
        $sites = $this->sites($pdo);
        $present = $this->presentColumns($pdo);
        $columns = array_values(array_intersect(array_merge(array_keys($this->editableColumns($sites)), $this->iconColumns($sites)), $present));
        $select = implode(', ', array_map(function ($column) { return '[' . $column . ']'; }, $columns));
        $order = in_array('siralama', $present, true) ? 'siralama, id' : 'id';
        $rows = $pdo->query('SELECT id' . ($select !== '' ? ', ' . $select : '') . ' FROM dbo.[' . self::TABLE . '] ORDER BY ' . $order)
            ->fetchAll(PDO::FETCH_ASSOC);
        // Ikon kolonu her sitede olmayabilir (villaciniz: sadece icon); istemci sadece bunlarda ikon alani gosterir.
        $iconColumns = array_values(array_intersect($this->iconColumns($sites), $present));
        $this->response->success([
            'categories' => $rows, 'sites' => $sites, 'iconColumns' => $iconColumns, 'imageBase' => $this->imageBase(),
        ]);
    }

    /** @Post */
    public function store(): void
    {
        $pdo = $this->db->pdo();
        $sites = $this->sites($pdo);
        $present = $this->presentColumns($pdo);
        $values = $this->values($sites, $present);
        if (trim((string) ($values['baslik'] ?? '')) === '') {
            throw new HttpException('Kategori basligi gereklidir.', 'VALIDATION', 422);
        }
        $values += ['aktif' => 1];
        if (!isset($values['siralama']) && in_array('siralama', $present, true)) {
            $values['siralama'] = (int) $pdo->query('SELECT ISNULL(MAX(siralama), 0) FROM dbo.[' . self::TABLE . ']')->fetchColumn() + 1;
        }
        $values = array_intersect_key($values, array_flip($present));
        $newId = $this->insertRow($pdo, self::TABLE, $values);
        $this->response->success(['message' => 'Kategori eklendi', 'id' => $newId], 201);
    }

    /** @Post("update") */
    public function update(): void
    {
        $id = $this->bodyId('id');
        $pdo = $this->db->pdo();
        $sites = $this->sites($pdo);
        $values = $this->values($sites, $this->presentColumns($pdo));
        if ($values === []) throw new HttpException('Guncellenecek alan gereklidir.', 'VALIDATION', 422);
        if (array_key_exists('baslik', $values) && trim((string) $values['baslik']) === '') {
            throw new HttpException('Kategori basligi gereklidir.', 'VALIDATION', 422);
        }
        $this->requireCategory($pdo, $id);
        $sets = array_map(function ($column) { return '[' . $column . '] = :' . $column; }, array_keys($values));
        $stmt = $pdo->prepare('UPDATE dbo.[' . self::TABLE . '] SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $this->execute($stmt, $values + ['id' => $id]);
        $this->response->success(['message' => 'Kategori guncellendi']);
    }

    /**
     * Kategoriyi siler. Herhangi bir sitede bu kategoride blog yazisi varsa silinmez.
     * @Post("delete")
     */
    public function delete(): void
    {
        $id = $this->bodyId('id');
        $pdo = $this->db->pdo();
        $this->requireCategory($pdo, $id);
        foreach ($this->sites($pdo) as $site) {
            $table = 'sayfalar' . $site['dbtable'];
            $columns = $this->tableColumns($pdo, $table);
            if (!in_array('kategori', $columns, true)) continue;
            $blog = in_array('blog', $columns, true) ? ' AND ISNULL(blog, 0) = 1' : '';
            // sayfalar.kategori nvarchar: sayiya cevirmeden metin olarak karsilastirilir.
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM dbo.[' . $table . '] WHERE LTRIM(RTRIM(CAST(kategori AS nvarchar(50)))) = :id' . $blog);
            $this->execute($stmt, ['id' => (string) $id]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new HttpException('Bu kategoride blog yazisi var; once yazilari baska kategoriye tasiyin.', 'CATEGORY_IN_USE', 409);
            }
        }
        $this->execute($pdo->prepare('DELETE FROM dbo.[' . self::TABLE . '] WHERE id = :id'), ['id' => $id]);
        $this->response->success(['message' => 'Kategori silindi']);
    }

    /**
     * Siralamayi verilen id listesine gore 1..n yazar (blog_kategorileri.asp "Sirayi Guncelle").
     * @Post("sort")
     */
    public function sort(): void
    {
        $order = $this->request->input('order');
        if (!is_array($order) || $order === []) throw new HttpException('Sira listesi gereklidir.', 'VALIDATION', 422);
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('UPDATE dbo.[' . self::TABLE . '] SET siralama = :siralama WHERE id = :id');
        $pdo->beginTransaction();
        try {
            foreach (array_values($order) as $offset => $raw) {
                $this->execute($stmt, ['siralama' => $offset + 1, 'id' => $this->validId($raw)]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $this->response->success(['message' => 'Siralama guncellendi']);
    }

    /**
     * Site ikonunu uploads/ kokune kaydeder ve dosya adini icon{dbtable} kolonuna yazar; SQL'e yazilamazsa dosya silinir.
     * @Post("images/upload")
     */
    public function uploadIcon(): void
    {
        $id = $this->bodyId('id');
        $pdo = $this->db->pdo();
        $field = $this->iconField($pdo);
        $this->requireCategory($pdo, $id);

        // Mevcut ikonlar SVG; SVG ayri ve guvenlik kontroluyle kaydedilir, digerleri ortak gorsel yolundan.
        $filename = $this->isSvgUpload() ? $this->storeSvg() : Uploads::storeImage('image', self::IMAGE_TYPES);
        $path = Uploads::dir() . DIRECTORY_SEPARATOR . $filename;
        try {
            $stmt = $pdo->prepare('UPDATE dbo.[' . self::TABLE . '] SET [' . $field . '] = :filename WHERE id = :id');
            $this->execute($stmt, ['filename' => $filename, 'id' => $id]);
            if ($stmt->rowCount() === 0) throw new HttpException('Kategori bulunamadi.', 'NOT_FOUND', 404);
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }
        $this->response->success([
            'field' => $field,
            'fileName' => $filename,
            'url' => $this->imageBase() . rawurlencode($filename),
        ], 201);
    }

    /**
     * icon{dbtable} kolonunu bosaltir; dosya uploads/ klasorunde birakilir.
     * @Post("images/clear")
     */
    public function clearIcon(): void
    {
        $id = $this->bodyId('id');
        $pdo = $this->db->pdo();
        $field = $this->iconField($pdo);
        $this->requireCategory($pdo, $id);
        $this->execute($pdo->prepare('UPDATE dbo.[' . self::TABLE . '] SET [' . $field . "] = '' WHERE id = :id"), ['id' => $id]);
        $this->response->success(['message' => 'Ikon kaldirildi']);
    }

    /**
     * Duzenlenebilir kolon => tip. Site alanlari her site icin sonekli uretilir.
     *
     * @param array<int,array{id:int,site:string,kisa:string,dbtable:string}> $sites
     * @return array<string,string>
     */
    private function editableColumns(array $sites): array
    {
        $columns = [];
        foreach ($sites as $site) {
            foreach (self::SITE_FIELDS as $field) $columns[$field . $site['dbtable']] = 'string';
        }
        return $columns + ['color' => 'string', 'aktif' => 'bit', 'siralama' => 'int'];
    }

    private function isSvgUpload(): bool
    {
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) return false;
        if (strtolower((string) pathinfo((string) $_FILES['image']['name'], PATHINFO_EXTENSION)) === 'svg') return true;
        return Uploads::imageMime($_FILES['image']['tmp_name']) === 'image/svg+xml';
    }

    /**
     * SVG ikonunu uploads/ kokune rastgele adla kaydeder. fileinfo/getimagesize SVG'yi tanimadigi icin icerik
     * dogrudan kontrol edilir; script, olay oznitelikleri (onload=...), javascript: ve gomulu icerik iceren dosya reddedilir.
     */
    private function storeSvg(): string
    {
        if ($_FILES['image']['size'] > 1024 * 1024) {
            throw new HttpException('SVG dosyasi 1MB limitini asiyor.', 'FILE_TOO_LARGE', 400);
        }
        $content = (string) file_get_contents($_FILES['image']['tmp_name']);
        if (preg_match('/<svg[\s>]/i', $content) !== 1) {
            throw new HttpException('Gecerli bir SVG dosyasi degil.', 'INVALID_FILE_TYPE', 400);
        }
        $unsafe = '/<\s*(script|foreignobject|iframe|embed|object)\b|<!entity|\son[a-z]+\s*=|javascript\s*:|data\s*:\s*text\/html/i';
        if (preg_match($unsafe, $content) === 1) {
            throw new HttpException('SVG guvenli degil (script, olay ozniteligi veya gomulu icerik iceriyor).', 'INVALID_FILE_TYPE', 400);
        }

        try {
            $filename = bin2hex(random_bytes(16)) . '.svg';
        } catch (\Exception $e) {
            $filename = md5(uniqid('', true)) . '.svg';
        }
        if (!Uploads::saveUploadedFile((string) $_FILES['image']['tmp_name'], Uploads::dir() . DIRECTORY_SEPARATOR . $filename)) {
            throw new HttpException('Gorsel kaydedilemedi.', 'SAVE_FAILED', 500);
        }
        return $filename;
    }

    /** @return array<int,string> */
    private function iconColumns(array $sites): array
    {
        return array_map(function ($site) { return 'icon' . $site['dbtable']; }, $sites);
    }

    private function iconField(PDO $pdo): string
    {
        $field = strtolower((string) $this->request->input('field', ''));
        if (!in_array($field, $this->iconColumns($this->sites($pdo)), true) || !in_array($field, $this->presentColumns($pdo), true)) {
            throw new HttpException('Gecersiz ikon alani.', 'VALIDATION', 422);
        }
        return $field;
    }

    /** @return array<string,mixed> */
    private function values(array $sites, array $present): array
    {
        $input = $this->request->json();
        $values = [];
        foreach ($this->editableColumns($sites) as $column => $type) {
            if (!array_key_exists($column, $input) || !in_array($column, $present, true)) continue;
            $value = $input[$column];
            if ($type === 'string') {
                if ($value !== null && !is_string($value)) throw new HttpException('Gecersiz metin alani.', 'VALIDATION', 422);
                $values[$column] = trim((string) $value);
            } else {
                if ($type === 'bit' && is_bool($value)) $value = (int) $value;
                $number = filter_var($value, FILTER_VALIDATE_INT);
                if ($number === false || $number < 0 || $number > 2147483647 || ($type === 'bit' && $number > 1)) {
                    throw new HttpException('Gecersiz sayisal alan.', 'VALIDATION', 422);
                }
                $values[$column] = $number;
            }
        }
        return $values;
    }

    private function requireCategory(PDO $pdo, int $id): void
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM dbo.[' . self::TABLE . '] WHERE id = :id');
        $this->execute($stmt, ['id' => $id]);
        if ((int) $stmt->fetchColumn() === 0) throw new HttpException('Kategori bulunamadi.', 'NOT_FOUND', 404);
    }

    /** @return array<int,string> */
    private function presentColumns(PDO $pdo): array
    {
        $present = $this->tableColumns($pdo, self::TABLE);
        if ($present === []) throw new HttpException('blog_kategorileri tablosu bulunamadi.', 'NOT_FOUND', 404);
        return $present;
    }
}
