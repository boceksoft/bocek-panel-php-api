<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Support\LegacySql;
use PDO;

/*
 * Kullanici yorumlari (eski panel yorumlar.asp karsiligi). Tablo defter.
 * Yorum bir villaya (islm='emlak', islm_id=homes.id) veya blog yazisina (islm='sayfa', islm_id=sayfalar.id) baglidir.
 *   GET  /backend-api/reviews                 yorumlar + siteler
 *   GET  /backend-api/reviews/targets         yorum baglanabilecek villalar ve blog yazilari
 *   POST /backend-api/reviews                 { isim, eposta?, tarih, puan, site, islm, islm_id, mesaj, cevap?, onay? }
 *   POST /backend-api/reviews/update          { id, ...ayni alanlar, siralama? }  (sadece gonderilenler yazilir)
 *   POST /backend-api/reviews/delete          { ids[] }
 * defter kolonlari kuruluma gore degisebildigi icin sadece tabloda olanlar okunur/yazilir. tarih kolonu tarih
 * tipindeyse ISO, metin ise panelin yazdigi gibi dd.mm.yyyy olarak saklanir; API her durumda yyyy-mm-dd dondurur.
 */
final class ReviewsController extends Controller
{
    use LegacySql;

    private const TARGET_TYPES = ['emlak', 'sayfa'];

    private const MAX_TEXT_LENGTH = 8000;

    /** @Get */
    public function index(): void
    {
        $pdo = $this->db->pdo();
        $present = $this->presentColumns($pdo);
        $has = function (string $column) use ($present): bool { return in_array($column, $present, true); };

        $select = ['d.id'];
        foreach (['isim', 'eposta', 'puan', 'site', 'islm', 'islm_id', 'mesaj', 'cevap', 'onay', 'siralama', 'rezid'] as $column) {
            $select[] = $has($column) ? 'd.[' . $column . ']' : 'NULL AS [' . $column . ']';
        }
        $select[] = $has('tarih') ? ($this->dateIsTyped($pdo) ? 'CONVERT(varchar(10), d.tarih, 120) AS tarih' : 'd.tarih') : 'NULL AS tarih';

        $joins = '';
        $titleSql = 'NULL';
        if ($has('islm') && $has('islm_id')) {
            $joins = " LEFT JOIN dbo.homes h ON d.islm = 'emlak' AND h.id = TRY_CONVERT(int, d.islm_id)"
                . " LEFT JOIN dbo.sayfalar s ON d.islm = 'sayfa' AND s.id = TRY_CONVERT(int, d.islm_id)";
            $titleSql = 'COALESCE(' . $this->homeTitleSql('h', 'h.baslik') . ', s.baslik)';
        }
        $order = $has('siralama') ? 'ISNULL(d.siralama, 0) DESC, d.id DESC' : 'd.id DESC';
        $rows = $pdo->query('SELECT ' . implode(', ', $select) . ', ' . $titleSql . ' AS hedef_adi FROM dbo.defter d' . $joins . ' ORDER BY ' . $order)
            ->fetchAll(PDO::FETCH_ASSOC);

        $reviews = [];
        foreach ($rows as $row) {
            $reviews[] = [
                'id' => (int) $row['id'],
                'isim' => trim((string) ($row['isim'] ?? '')),
                'eposta' => trim((string) ($row['eposta'] ?? '')),
                'tarih' => $this->outputDate($row['tarih'] ?? null),
                'puan' => (int) ($row['puan'] ?? 0),
                'site' => (int) ($row['site'] ?? 0),
                'islm' => trim((string) ($row['islm'] ?? '')),
                'islm_id' => (int) ($row['islm_id'] ?? 0),
                'hedef_adi' => trim((string) ($row['hedef_adi'] ?? '')),
                'mesaj' => (string) ($row['mesaj'] ?? ''),
                'cevap' => (string) ($row['cevap'] ?? ''),
                'onay' => !empty($row['onay']),
                'siralama' => (int) ($row['siralama'] ?? 0),
                'rezid' => (int) ($row['rezid'] ?? 0),
            ];
        }

        $sites = array_map(function (array $site) {
            return ['id' => $site['id'], 'site' => $site['site']];
        }, $this->sites($pdo));

        $this->response->success([
            'reviews' => $reviews,
            'sites' => $sites,
            'columns' => array_values(array_intersect(['eposta', 'tarih', 'puan', 'site', 'cevap', 'onay', 'siralama', 'rezid'], $present)),
        ]);
    }

    /**
     * Yorum baglanabilecek villalar ve blog yazilari (panelde villa listesi evkodu, blog basliga gore).
     *
     * @Get("targets")
     */
    public function targets(): void
    {
        $pdo = $this->db->pdo();
        $villas = $pdo->query('SELECT h.id, ' . $this->homeTitleSql('h', 'h.baslik') . ' AS baslik FROM dbo.homes h ORDER BY h.evkodu ASC')
            ->fetchAll(PDO::FETCH_ASSOC);

        // Panel blog yazilarini cat=4 ile listeler; yeni panelde blog=1 olanlar da eklenir.
        $pageColumns = $this->tableColumns($pdo, 'sayfalar');
        $blogWhere = in_array('blog', $pageColumns, true) ? '(ISNULL(blog, 0) = 1 OR cat = 4)' : 'cat = 4';
        $blogs = $pdo->query('SELECT id, baslik FROM dbo.sayfalar WHERE ' . $blogWhere . ' ORDER BY baslik ASC')->fetchAll(PDO::FETCH_ASSOC);

        $map = function (array $rows, string $type): array {
            return array_map(function ($row) use ($type) {
                return ['islm' => $type, 'id' => (int) $row['id'], 'baslik' => trim((string) ($row['baslik'] ?? ''))];
            }, $rows);
        };

        $this->response->success(['villas' => $map($villas, 'emlak'), 'blogs' => $map($blogs, 'sayfa')]);
    }

    /** @Post */
    public function store(): void
    {
        $pdo = $this->db->pdo();
        $present = $this->presentColumns($pdo);
        $input = $this->request->json();
        foreach (['isim', 'mesaj', 'islm', 'islm_id'] as $required) {
            if (!array_key_exists($required, $input)) throw new HttpException('Isim, mesaj ve villa/blog secimi gereklidir.', 'VALIDATION', 422);
        }
        $values = $this->values($pdo, $input, $present);
        // Panelde yeni yorum pasif eklenir; onay gonderilmezse ayni davranis.
        if (in_array('onay', $present, true) && !isset($values['onay'])) $values['onay'] = 0;
        if (in_array('tarih', $present, true) && !isset($values['tarih'])) $values['tarih'] = $this->storeDate($pdo, date('Y-m-d'));
        if (in_array('site', $present, true) && !isset($values['site'])) $values['site'] = 1;
        if (in_array('puan', $present, true) && !isset($values['puan'])) $values['puan'] = 5;

        $id = $this->insertRow($pdo, 'defter', $values);
        $this->response->success(['message' => 'Yorum eklendi', 'id' => $id], 201);
    }

    /** @Post("update") */
    public function update(): void
    {
        $id = $this->bodyId('id');
        $pdo = $this->db->pdo();
        $present = $this->presentColumns($pdo);
        $values = $this->values($pdo, $this->request->json(), $present);
        if ($values === []) throw new HttpException('Guncellenecek alan gereklidir.', 'VALIDATION', 422);

        $sets = array_map(function ($field) { return '[' . $field . '] = :' . $field; }, array_keys($values));
        $stmt = $pdo->prepare('UPDATE dbo.defter SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $this->execute($stmt, $values + ['id' => $id]);
        if ($stmt->rowCount() === 0) throw new HttpException('Yorum bulunamadi.', 'NOT_FOUND', 404);
        $this->response->success(['message' => 'Yorum guncellendi']);
    }

    /**
     * Yorumlari siler (eski paneldeki gibi). Tek kayit icin id, toplu silme icin ids.
     *
     * @Post("delete")
     */
    public function delete(): void
    {
        $input = $this->request->json();
        $raw = $input['ids'] ?? (isset($input['id']) ? [$input['id']] : null);
        if (!is_array($raw) || $raw === []) throw new HttpException('Silinecek yorum secilmedi.', 'VALIDATION', 422);
        if (count($raw) > 1000) throw new HttpException('Tek seferde en fazla 1000 yorum silinebilir.', 'VALIDATION', 422);
        $ids = array_values(array_unique(array_map(function ($value) { return $this->validId($value); }, $raw)));

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('DELETE FROM dbo.defter WHERE id = :id');
        $deleted = 0;
        $pdo->beginTransaction();
        try {
            foreach ($ids as $id) {
                $this->execute($stmt, ['id' => $id]);
                $deleted += $stmt->rowCount();
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        if ($deleted === 0) throw new HttpException('Yorum bulunamadi.', 'NOT_FOUND', 404);
        $this->response->success(['message' => $deleted . ' yorum silindi', 'deleted' => $deleted]);
    }

    /** @return array<int,string> */
    private function presentColumns(PDO $pdo): array
    {
        $present = $this->tableColumns($pdo, 'defter');
        if ($present === []) throw new HttpException('Yorum tablosu (defter) bulunamadi.', 'TABLE_NOT_FOUND', 404);
        return $present;
    }

    /**
     * Gonderilen alanlari dogrular; tabloda olmayan kolonlar atlanir.
     *
     * @param array<string,mixed> $input
     * @param array<int,string> $present
     * @return array<string,mixed>
     */
    private function values(PDO $pdo, array $input, array $present): array
    {
        $values = [];
        $has = function (string $column) use ($present): bool { return in_array($column, $present, true); };

        if (array_key_exists('isim', $input) && $has('isim')) {
            $values['isim'] = $this->text($input['isim'], 'Isim', 200);
            if ($values['isim'] === '') throw new HttpException('Isim gereklidir.', 'VALIDATION', 422);
        }
        if (array_key_exists('eposta', $input) && $has('eposta')) {
            $email = $this->text($input['eposta'], 'E-posta', 200);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new HttpException('Gecersiz e-posta adresi.', 'VALIDATION', 422);
            $values['eposta'] = $email;
        }
        if (array_key_exists('mesaj', $input) && $has('mesaj')) {
            $values['mesaj'] = $this->text($input['mesaj'], 'Mesaj', self::MAX_TEXT_LENGTH);
            if ($values['mesaj'] === '') throw new HttpException('Mesaj gereklidir.', 'VALIDATION', 422);
        }
        if (array_key_exists('cevap', $input) && $has('cevap')) {
            $values['cevap'] = $this->text($input['cevap'], 'Cevap', self::MAX_TEXT_LENGTH);
        }
        if (array_key_exists('puan', $input) && $has('puan')) {
            $score = filter_var($input['puan'], FILTER_VALIDATE_INT);
            if ($score === false || $score < 1 || $score > 5) throw new HttpException('Puan 1 ile 5 arasinda olmalidir.', 'VALIDATION', 422);
            $values['puan'] = $score;
        }
        if (array_key_exists('site', $input) && $has('site')) {
            $site = filter_var($input['site'], FILTER_VALIDATE_INT);
            $siteIds = array_column($this->sites($pdo), 'id');
            if ($site === false || !in_array($site, $siteIds, true)) throw new HttpException('Gecersiz site.', 'VALIDATION', 422);
            $values['site'] = $site;
        }
        if (array_key_exists('tarih', $input) && $has('tarih')) {
            $values['tarih'] = $this->storeDate($pdo, $input['tarih']);
        }
        if (array_key_exists('onay', $input) && $has('onay')) {
            $approved = is_bool($input['onay']) ? (int) $input['onay'] : $input['onay'];
            if (!in_array($approved, [0, 1, '0', '1'], true)) throw new HttpException('Gecersiz onay degeri.', 'VALIDATION', 422);
            $values['onay'] = (int) $approved;
        }
        if (array_key_exists('siralama', $input) && $has('siralama')) {
            $order = filter_var($input['siralama'], FILTER_VALIDATE_INT);
            if ($order === false || $order < 0 || $order > 2147483647) throw new HttpException('Gecersiz siralama.', 'VALIDATION', 422);
            $values['siralama'] = $order;
        }
        if ((array_key_exists('islm', $input) || array_key_exists('islm_id', $input)) && $has('islm') && $has('islm_id')) {
            $type = is_string($input['islm'] ?? null) ? trim($input['islm']) : '';
            $targetId = filter_var($input['islm_id'] ?? null, FILTER_VALIDATE_INT);
            if (!in_array($type, self::TARGET_TYPES, true) || $targetId === false || $targetId <= 0) {
                throw new HttpException('Villa veya blog yazisi secilmelidir.', 'VALIDATION', 422);
            }
            $this->requireTarget($pdo, $type, $targetId);
            $values['islm'] = $type;
            $values['islm_id'] = $targetId;
        }
        return $values;
    }

    private function requireTarget(PDO $pdo, string $type, int $id): void
    {
        $table = $type === 'emlak' ? 'homes' : 'sayfalar';
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM dbo.' . $table . ' WHERE id = :id');
        $this->execute($stmt, ['id' => $id]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new HttpException($type === 'emlak' ? 'Secilen villa bulunamadi.' : 'Secilen blog yazisi bulunamadi.', 'VALIDATION', 422);
        }
    }

    /** @param mixed $value */
    private function text($value, string $label, int $max): string
    {
        if ($value !== null && !is_string($value)) throw new HttpException('Gecersiz ' . $label . ' alani.', 'VALIDATION', 422);
        $value = trim((string) $value);
        if (mb_strlen($value, 'UTF-8') > $max) throw new HttpException($label . ' en fazla ' . $max . ' karakter olabilir.', 'VALIDATION', 422);
        return $value;
    }

    private function dateIsTyped(PDO $pdo): bool
    {
        $stmt = $pdo->query("SELECT LOWER(DATA_TYPE) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'defter' AND COLUMN_NAME = 'tarih'");
        return in_array((string) $stmt->fetchColumn(), ['date', 'datetime', 'datetime2', 'smalldatetime', 'datetimeoffset'], true);
    }

    /**
     * yyyy-mm-dd (veya dd.mm.yyyy) girdiyi kolon tipine gore saklanacak bicime cevirir.
     *
     * @param mixed $value
     */
    private function storeDate(PDO $pdo, $value): string
    {
        $text = is_string($value) ? trim($value) : '';
        $date = false;
        foreach (['Y-m-d', 'd.m.Y'] as $format) {
            $date = \DateTime::createFromFormat('!' . $format, $text);
            if ($date !== false && $date->format($format) === $text) break;
            $date = false;
        }
        if ($date === false) throw new HttpException('Gecersiz tarih.', 'VALIDATION', 422);
        return $this->dateIsTyped($pdo) ? $date->format('Y-m-d\TH:i:s') : $date->format('d.m.Y');
    }

    // Metin kolonundaki "dd.mm.yyyy" ya da tarih kolonundan gelen "yyyy-mm-dd" -> yyyy-mm-dd; cozulemezse oldugu gibi.
    /** @param mixed $value */
    private function outputDate($value): string
    {
        $text = trim((string) $value);
        if ($text === '') return '';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $m) === 1) return $m[1] . '-' . $m[2] . '-' . $m[3];
        if (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})/', $text, $m) === 1) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        return $text;
    }
}
