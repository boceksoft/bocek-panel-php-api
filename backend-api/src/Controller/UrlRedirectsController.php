<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Support\LegacySql;
use PDO;

/*
 * URL yonlendirmeleri (eski panel yonlendirme.asp karsiligi).
 * Tablo siteye gore yonlendirme{dbtable}: eski_link, yeni_link, turu_code, turu_string, aktif.
 *   GET  /backend-api/url-redirects?site=1
 *   POST /backend-api/url-redirects?site=1          { eski_linkler[], yeni_link, turu_code, turu_string? }
 *   POST /backend-api/url-redirects/update?site=1   { id, eski_link?, yeni_link?, turu_code?, turu_string?, aktif? }
 *   POST /backend-api/url-redirects/delete?site=1   { ids[] }
 * Eski panelden farki: ayni eski link ikinci kez eklenmez, yeni link gereken turlerde bos birakilamaz,
 * bir link kendine yonlendirilemez; toplu ekleme ve toplu silme tek istekte yapilir.
 */
final class UrlRedirectsController extends Controller
{
    use LegacySql;

    // Hazir durum kodlari; panelin turu_string metinleriyle ayni (404 "File Not Found").
    private const STATUS_TEXTS = [
        301 => '301 Moved Permanently',
        302 => '302 Found',
        307 => '307 Temporary Redirect',
        308 => '308 Permanent Redirect',
        404 => '404 File Not Found',
        410 => '410 Gone',
    ];

    // Bu kodlarda yeni link kullanilmaz (eski panelde 404 secilince yeni link alani gizlenir).
    private const NO_TARGET_CODES = [404, 410];

    private const MAX_LINK_LENGTH = 2000;

    /**
     * @Get
     * @query site int Site kimligi (varsayilan 1)
     */
    public function index(): void
    {
        $pdo = $this->db->pdo();
        $table = $this->table($pdo);
        $present = $this->presentColumns($pdo, $table);
        $select = ['id', 'eski_link', 'yeni_link', 'turu_code', 'turu_string'];
        if (in_array('aktif', $present, true)) $select[] = 'aktif';
        $stmt = $pdo->query('SELECT [' . implode('], [', $select) . '] FROM dbo.[' . $table . '] ORDER BY id DESC');

        $redirects = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $redirects[] = [
                'id' => (int) $row['id'],
                'eski_link' => trim((string) ($row['eski_link'] ?? '')),
                'yeni_link' => trim((string) ($row['yeni_link'] ?? '')),
                'turu_code' => trim((string) ($row['turu_code'] ?? '')),
                'turu_string' => trim((string) ($row['turu_string'] ?? '')),
                // aktif kolonu olmayan kurulumda tum kayitlar aktif sayilir.
                'aktif' => array_key_exists('aktif', $row) ? !empty($row['aktif']) : true,
            ];
        }

        $sites = array_map(function (array $site) {
            return ['id' => $site['id'], 'site' => $site['site'], 'kisa' => $site['kisa']];
        }, $this->sites($pdo));

        $this->response->success([
            'redirects' => $redirects,
            'sites' => $sites,
            'statusCodes' => $this->statusCodeOptions(),
            'hasActiveColumn' => in_array('aktif', $present, true),
        ]);
    }

    /**
     * Bir veya birden cok eski linki ayni hedefe yonlendirir; her eski link ayri satir olur.
     *
     * @Post
     * @query site int Site kimligi (varsayilan 1)
     * @body eski_linkler array Eski linkler (tek link icin eski_link de olur)
     * @body yeni_link string Hedef link (404/410'da bos)
     * @body turu_code int Durum kodu (301, 302, 307, 308, 404, 410 veya 3xx/4xx diger)
     * @body turu_string string Sadece listede olmayan kodlarda aciklama
     */
    public function store(): void
    {
        $input = $this->request->json();
        $sources = $input['eski_linkler'] ?? ($input['eski_link'] ?? null);
        if (is_string($sources)) $sources = preg_split('/\r\n|\r|\n/', $sources) ?: [];
        if (!is_array($sources)) throw new HttpException('Eski link gereklidir.', 'VALIDATION', 422);

        $links = [];
        foreach ($sources as $source) {
            if (!is_string($source)) throw new HttpException('Gecersiz eski link.', 'VALIDATION', 422);
            $link = $this->link($source, 'Eski link');
            if ($link !== '') $links[$this->key($link)] = $link;
        }
        if ($links === []) throw new HttpException('Eski link gereklidir.', 'VALIDATION', 422);
        if (count($links) > 500) throw new HttpException('Tek seferde en fazla 500 link eklenebilir.', 'VALIDATION', 422);

        [$code, $text] = $this->status($input['turu_code'] ?? null, $input['turu_string'] ?? null);
        $target = $this->target($input['yeni_link'] ?? null, $code);
        foreach ($links as $link) $this->assertNotSelf($link, $target);

        $pdo = $this->db->pdo();
        $table = $this->table($pdo);
        $present = $this->presentColumns($pdo, $table);
        $created = [];
        $skipped = [];
        $pdo->beginTransaction();
        try {
            $existing = $this->existingLinks($pdo, $table, true);
            foreach ($links as $key => $link) {
                if (isset($existing[$key])) {
                    $skipped[] = $link;
                    continue;
                }
                $values = ['eski_link' => $link, 'yeni_link' => $target, 'turu_code' => (string) $code, 'turu_string' => $text];
                if (in_array('aktif', $present, true)) $values['aktif'] = 1;
                $created[] = $this->insertRow($pdo, $table, $values);
                $existing[$key] = true;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        if ($created === []) {
            throw new HttpException('Bu linkler icin zaten yonlendirme var: ' . implode(', ', array_slice($skipped, 0, 5)), 'DUPLICATE_LINK', 409);
        }
        $this->response->success([
            'message' => count($created) . ' yonlendirme eklendi',
            'ids' => $created,
            'skipped' => $skipped,
        ], 201);
    }

    /**
     * Sadece gonderilen alanlari gunceller; aktif tek basina gonderilerek aktif/pasif yapilir.
     *
     * @Post("update")
     * @query site int Site kimligi (varsayilan 1)
     */
    public function update(): void
    {
        $id = $this->bodyId('id');
        $input = $this->request->json();
        $pdo = $this->db->pdo();
        $table = $this->table($pdo);
        $present = $this->presentColumns($pdo, $table);

        $pdo->beginTransaction();
        try {
            $current = $this->requireRedirect($pdo, $table, $id);
            $values = [];

            if (array_key_exists('aktif', $input)) {
                if (!in_array('aktif', $present, true)) throw new HttpException('Bu sitede aktif/pasif desteklenmiyor.', 'VALIDATION', 422);
                $active = $input['aktif'];
                if (is_bool($active)) $active = (int) $active;
                if (!in_array($active, [0, 1, '0', '1'], true)) throw new HttpException('Gecersiz aktif degeri.', 'VALIDATION', 422);
                $values['aktif'] = (int) $active;
            }

            $redirectFields = ['eski_link', 'yeni_link', 'turu_code', 'turu_string'];
            if (array_intersect($redirectFields, array_keys($input)) !== []) {
                $source = array_key_exists('eski_link', $input) ? $input['eski_link'] : $current['eski_link'];
                if (!is_string($source)) throw new HttpException('Gecersiz eski link.', 'VALIDATION', 422);
                $source = $this->link($source, 'Eski link');
                if ($source === '') throw new HttpException('Eski link gereklidir.', 'VALIDATION', 422);

                [$code, $text] = $this->status(
                    $input['turu_code'] ?? $current['turu_code'],
                    array_key_exists('turu_string', $input) ? $input['turu_string'] : $current['turu_string']
                );
                $target = $this->target(array_key_exists('yeni_link', $input) ? $input['yeni_link'] : $current['yeni_link'], $code);
                $this->assertNotSelf($source, $target);

                $existing = $this->existingLinks($pdo, $table, true, $id);
                if (isset($existing[$this->key($source)])) {
                    throw new HttpException('Bu eski link icin zaten yonlendirme var.', 'DUPLICATE_LINK', 409);
                }
                $values += ['eski_link' => $source, 'yeni_link' => $target, 'turu_code' => (string) $code, 'turu_string' => $text];
            }

            if ($values === []) throw new HttpException('Guncellenecek alan gereklidir.', 'VALIDATION', 422);
            $sets = array_map(function ($field) { return '[' . $field . '] = :' . $field; }, array_keys($values));
            $this->execute($pdo->prepare('UPDATE dbo.[' . $table . '] SET ' . implode(', ', $sets) . ' WHERE id = :id'), $values + ['id' => $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $this->response->success(['message' => 'Yonlendirme guncellendi']);
    }

    /**
     * Satirlari tablodan siler (eski paneldeki gibi). Tek kayit icin id, toplu silme icin ids.
     *
     * @Post("delete")
     * @query site int Site kimligi (varsayilan 1)
     */
    public function delete(): void
    {
        $input = $this->request->json();
        $raw = $input['ids'] ?? (isset($input['id']) ? [$input['id']] : null);
        if (!is_array($raw) || $raw === []) throw new HttpException('Silinecek yonlendirme secilmedi.', 'VALIDATION', 422);
        if (count($raw) > 1000) throw new HttpException('Tek seferde en fazla 1000 kayit silinebilir.', 'VALIDATION', 422);
        $ids = array_values(array_unique(array_map(function ($value) { return $this->validId($value); }, $raw)));

        $pdo = $this->db->pdo();
        $table = $this->table($pdo);
        $stmt = $pdo->prepare('DELETE FROM dbo.[' . $table . '] WHERE id = :id');
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
        if ($deleted === 0) throw new HttpException('Yonlendirme bulunamadi.', 'NOT_FOUND', 404);
        $this->response->success(['message' => $deleted . ' yonlendirme silindi', 'deleted' => $deleted]);
    }

    private function table(PDO $pdo): string
    {
        return 'yonlendirme' . $this->siteSuffix($pdo);
    }

    /** @return array<int,string> */
    private function presentColumns(PDO $pdo, string $table): array
    {
        $present = $this->tableColumns($pdo, $table);
        if ($present === []) throw new HttpException('Bu sitede yonlendirme tablosu bulunamadi.', 'SITE_NOT_FOUND', 404);
        return $present;
    }

    /** @return array{id:int,eski_link:string,yeni_link:string,turu_code:string,turu_string:string} */
    private function requireRedirect(PDO $pdo, string $table, int $id): array
    {
        $stmt = $pdo->prepare('SELECT id, eski_link, yeni_link, turu_code, turu_string FROM dbo.[' . $table . '] WITH (UPDLOCK, HOLDLOCK) WHERE id = :id');
        $this->execute($stmt, ['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new HttpException('Yonlendirme bulunamadi.', 'NOT_FOUND', 404);
        return [
            'id' => (int) $row['id'],
            'eski_link' => trim((string) ($row['eski_link'] ?? '')),
            'yeni_link' => trim((string) ($row['yeni_link'] ?? '')),
            'turu_code' => trim((string) ($row['turu_code'] ?? '')),
            'turu_string' => trim((string) ($row['turu_string'] ?? '')),
        ];
    }

    /**
     * Tablodaki eski linkler (karsilastirma anahtari => true). Eszamanli eklemede ayni link iki kez
     * yazilmasin diye islem icinde kilitli okunur.
     *
     * @return array<string,bool>
     */
    private function existingLinks(PDO $pdo, string $table, bool $lock, int $exceptId = 0): array
    {
        $stmt = $pdo->prepare('SELECT id, eski_link FROM dbo.[' . $table . ']' . ($lock ? ' WITH (UPDLOCK, HOLDLOCK)' : ''));
        $stmt->execute();
        $links = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int) $row['id'] === $exceptId) continue;
            $link = trim((string) ($row['eski_link'] ?? ''));
            if ($link !== '') $links[$this->key($link)] = true;
        }
        return $links;
    }

    // Ayni linkin "/Sayfa/", "/sayfa" ve eski kayitlardaki "sayfa" yazimlari tek kayit sayilir.
    private function key(string $link): string
    {
        $link = mb_strtolower(trim($link), 'UTF-8');
        $trimmed = trim($link, '/');
        return $trimmed === '' ? $link : $trimmed;
    }

    private function link(string $value, string $label): string
    {
        $value = trim($value);
        if (mb_strlen($value, 'UTF-8') > self::MAX_LINK_LENGTH) {
            throw new HttpException($label . ' en fazla ' . self::MAX_LINK_LENGTH . ' karakter olabilir.', 'VALIDATION', 422);
        }
        if (preg_match('/\s/u', $value) === 1) throw new HttpException($label . ' bosluk iceremez: ' . $value, 'VALIDATION', 422);
        // Site ici linkler DB'ye "/" ile baslayarak yazilir ("sayfa" -> "/sayfa"); tam adresler (http://, https://, //) oldugu gibi kalir.
        if ($value !== '' && preg_match('#^([a-z][a-z0-9+.-]*:|/)#i', $value) !== 1) $value = '/' . $value;
        return $value;
    }

    /**
     * @param mixed $code
     * @param mixed $text
     * @return array{0:int,1:string}
     */
    private function status($code, $text): array
    {
        $number = filter_var(is_string($code) ? trim($code) : $code, FILTER_VALIDATE_INT);
        if ($number === false || $number < 300 || $number > 599) {
            throw new HttpException('Gecersiz durum kodu (300-599 arasi olmalidir).', 'VALIDATION', 422);
        }
        if (isset(self::STATUS_TEXTS[$number])) return [$number, self::STATUS_TEXTS[$number]];

        // Listede olmayan kod: eski paneldeki "Diger" secenegi gibi aciklama elle yazilir.
        $text = is_string($text) ? trim($text) : '';
        if ($text === '') $text = (string) $number;
        if (strpos($text, (string) $number) !== 0) $text = $number . ' ' . $text;
        if (mb_strlen($text, 'UTF-8') > 100) throw new HttpException('Durum aciklamasi en fazla 100 karakter olabilir.', 'VALIDATION', 422);
        return [$number, $text];
    }

    /** @param mixed $value */
    private function target($value, int $code): string
    {
        if (in_array($code, self::NO_TARGET_CODES, true)) return '';
        if ($value !== null && !is_string($value)) throw new HttpException('Gecersiz yeni link.', 'VALIDATION', 422);
        $target = $this->link((string) $value, 'Yeni link');
        if ($target === '') throw new HttpException('Bu durum kodu icin yeni link gereklidir.', 'VALIDATION', 422);
        return $target;
    }

    private function assertNotSelf(string $source, string $target): void
    {
        if ($target !== '' && $this->key($source) === $this->key($target)) {
            throw new HttpException('Bir link kendisine yonlendirilemez: ' . $source, 'VALIDATION', 422);
        }
    }

    /** @return array<int,array{code:int,text:string,needsTarget:bool}> */
    private function statusCodeOptions(): array
    {
        $options = [];
        foreach (self::STATUS_TEXTS as $code => $text) {
            $options[] = ['code' => $code, 'text' => $text, 'needsTarget' => !in_array($code, self::NO_TARGET_CODES, true)];
        }
        return $options;
    }
}
