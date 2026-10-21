<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Blog yazilari (eski panel bloglar.asp / yeni_blog_ekle.asp karsiligi).
 * Sayfalarla ayni sayfalar{dbtable} tablosu, blog=1 kayitlar. kategori -> blog_kategorileri.id,
 * tarih -> yayinlama tarihi, resim -> uploads/ kokundeki dosya adi.
 *   GET  /backend-api/blog-posts?site=1
 *   GET  /backend-api/blog-posts/detail?site=1&id=5
 *   POST /backend-api/blog-posts | update | delete
 *   POST /backend-api/blog-posts/images/upload | clear   (field=resim)
 * detail, update, delete ve gorsel endpoint'leri CmsPagesController'dan gelir; sabitler ve scope() burada degisir.
 */
final class BlogPostsController extends CmsPagesController
{
    protected const ISLM = 'blog';

    protected const FIELDS = [
        'baslik' => 'string', 'kisa_baslik' => 'string', 'kisa_icerik' => 'string', 'icerik' => 'string',
        'kategori' => 'int', 'tarih' => 'datetime', 'siralama' => 'int', 'aktif' => 'bit', 'favori' => 'bit',
        'title' => 'string', 'keywords' => 'string', 'description' => 'string', 'url' => 'string', 'canonical' => 'string',
    ];

    protected const IMAGE_FIELDS = ['resim'];

    protected const LIST_FIELDS = ['baslik', 'url', 'kategori', 'tarih', 'aktif', 'favori', 'resim', 'siralama'];

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
        $order = in_array('tarih', $present, true) ? 'tarih DESC, id DESC' : 'id DESC';
        $stmt = $pdo->prepare('SELECT id' . ($select !== '' ? ', ' . $select : '') . ' FROM dbo.[' . $table . '] WHERE '
            . $this->scope($present) . ' ORDER BY ' . $order);
        $stmt->execute();
        $this->response->success(['posts' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'imageBase' => $this->imageBase()]);
    }

    /** @Post */
    public function store(): void
    {
        $values = $this->values();
        if (!isset($values['baslik']) || $values['baslik'] === '') {
            throw new HttpException('Blog basligi gereklidir.', 'VALIDATION', 422);
        }
        $pdo = $this->db->pdo();
        $table = $this->table($pdo);
        $present = $this->presentColumns($pdo, $table);
        // yeni_blog_ekle.asp blog yazisini blog kok sayfasinin (cat) altina ekler; kok, mevcut yazilardan okunur.
        $values += [
            'tarih' => date('Y-m-d\TH:i:s'), 'aktif' => 0, 'favori' => 0, 'menu' => 0, 'menu2' => 0,
            'static' => 0, 'haber' => 0, 'blog' => 1, 'cat' => $this->blogRootCat($pdo, $table),
        ];
        if (!isset($values['siralama']) && in_array('siralama', $present, true)) {
            $stmt = $pdo->prepare('SELECT ISNULL(MAX(siralama), 0) FROM dbo.[' . $table . '] WHERE ' . $this->scope($present));
            $stmt->execute();
            $values['siralama'] = (int) $stmt->fetchColumn() + 1;
        }
        $values = array_intersect_key($values, array_flip($present));
        $newId = $this->insertRow($pdo, $table, $values);
        $this->response->success(['message' => 'Blog eklendi', 'id' => $newId], 201);
    }

    /**
     * Surukle-birak siralama: yaziyi hedefin onune/arkasina koyar, sitedeki tum blog yazilarinin siralama
     * degerini (siralama, id sirasiyla) 1..n yeniden yazar. Sadece degeri degisen satirlar guncellenir.
     * Blogda agac yok; "inside" kabul edilmez.
     * @Post("move")
     */
    public function move(): void
    {
        $id = $this->bodyId('id');
        $targetId = $this->bodyId('targetId');
        $position = $this->request->input('position');
        if ($id === $targetId || !in_array($position, ['before', 'after'], true)) {
            throw new HttpException('Gecersiz tasima.', 'VALIDATION', 422);
        }
        $pdo = $this->db->pdo();
        $table = $this->table($pdo);
        $present = $this->presentColumns($pdo, $table);
        $pdo->beginTransaction();
        try {
            // Eszamanli siralama degisiklikleri karismasin diye satirlar kilitlenir.
            $stmt = $pdo->prepare('SELECT id, siralama FROM dbo.[' . $table . '] WITH (UPDLOCK, HOLDLOCK) WHERE '
                . $this->scope($present) . ' ORDER BY siralama, id');
            $stmt->execute();
            $current = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $current[(int) $row['id']] = $row['siralama'] === null ? null : (int) $row['siralama'];
            }
            if (!array_key_exists($id, $current) || !array_key_exists($targetId, $current)) {
                throw new HttpException('Blog yazisi bulunamadi.', 'NOT_FOUND', 404);
            }

            $order = array_values(array_diff(array_keys($current), [$id]));
            $index = array_search($targetId, $order, true);
            if ($position === 'after') ++$index;
            array_splice($order, $index, 0, [$id]);

            $update = $pdo->prepare('UPDATE dbo.[' . $table . '] SET siralama = :siralama WHERE id = :id');
            foreach ($order as $offset => $postId) {
                if ($current[$postId] === $offset + 1) continue;
                $this->execute($update, ['siralama' => $offset + 1, 'id' => $postId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $this->response->success(['message' => 'Blog sirasi guncellendi']);
    }

    // bloglar.asp: blog=1, haber ve silinmis kayitlar haric.
    protected function scope(array $present): string
    {
        if (!in_array('blog', $present, true)) return '1 = 0';
        $where = ['ISNULL(blog, 0) = 1'];
        if (in_array('haber', $present, true)) $where[] = 'ISNULL(haber, 0) = 0';
        if (in_array('disabled', $present, true)) $where[] = 'disabled IS NULL';
        return implode(' AND ', $where);
    }

    private function blogRootCat(PDO $pdo, string $table): int
    {
        $stmt = $pdo->prepare('SELECT TOP 1 cat FROM dbo.[' . $table . '] WHERE ISNULL(blog, 0) = 1 ORDER BY id DESC');
        $stmt->execute();
        $cat = $stmt->fetchColumn();
        return $cat === false || $cat === null ? 0 : (int) $cat;
    }
}
