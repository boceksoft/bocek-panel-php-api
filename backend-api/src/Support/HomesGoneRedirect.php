<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\HttpException;
use PDO;

/*
 * Villa silinince / pasife alininca villanin URL'si (homes.url, site sonekli url_s2 ...) o sitenin
 * yonlendirme{dbtable} tablosuna "410 Gone" olarak yazilir; ayni eski link icin kayit varsa kodu 410 yapilir.
 * Villa tekrar aktif edilince o URL'deki 410 kaydi tablodan silinir, baska kodlara dokunulmaz.
 * Config: homes_gone_redirect (varsayilan acik). Hatalar asil islemi (pasif / silme) bozmaz; cagiran yakalar.
 *
 * Kullanim: once collect() (silmeden ONCE, URL okunabilsin), islem basarili olunca markGone() / clearGone().
 */
final class HomesGoneRedirect
{
    const CODE = '410';
    const TEXT = '410 Gone';

    // UrlRedirectsController::STATUS_TEXTS ile ayni.
    const STATUS_TEXTS = [
        301 => '301 Moved Permanently',
        302 => '302 Found',
        307 => '307 Temporary Redirect',
        308 => '308 Permanent Redirect',
        404 => '404 File Not Found',
        410 => '410 Gone',
    ];

    public static function enabled(array $app): bool
    {
        return !array_key_exists('homes_gone_redirect', $app) || !empty($app['homes_gone_redirect']);
    }

    /**
     * Villalarin URL'leri, site yonlendirme tablosuna gore gruplu.
     *
     * @param array<int,mixed> $homeIds
     * @param array<int,int>|null $siteIds null: tum siteler
     * @return array<string,string[]> tablo adi => eski linkler ("/villa-adi")
     */
    public static function collect(PDO $pdo, array $homeIds, ?array $siteIds = null): array
    {
        $ids = [];
        foreach ($homeIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === [] || !self::hasColumn($pdo, 'homes', 'url')) {
            return [];
        }

        $result = [];
        foreach (self::sites($pdo) as $siteId => $suffix) {
            if ($siteIds !== null && !in_array($siteId, $siteIds, true)) {
                continue;
            }
            $table = 'yonlendirme' . $suffix;
            if (!self::tableExists($pdo, $table)) {
                continue;
            }
            // Sitenin kendi url kolonu (url_s2) yoksa ana url kullanilir.
            $column = ($suffix !== '' && self::hasColumn($pdo, 'homes', 'url' . $suffix)) ? 'url' . $suffix : 'url';
            $rows = $pdo->query('SELECT [' . $column . '] FROM dbo.homes WHERE id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_COLUMN);
            $links = [];
            foreach ($rows as $url) {
                $link = self::link((string) $url);
                if ($link !== '') {
                    $links[self::key($link)] = $link;
                }
            }
            if ($links !== []) {
                $result[$table] = array_values($links);
            }
        }

        return $result;
    }

    /**
     * Linkleri 410 Gone yapar (kayit yoksa ekler). Dondurur: yazilan / guncellenen kayit sayisi.
     *
     * @param array<string,string[]> $collected
     */
    public static function markGone(PDO $pdo, array $collected): int
    {
        return self::upsert($pdo, $collected, self::CODE, self::TEXT, '');
    }

    /**
     * Linklere verilen yonlendirmeyi yazar: kayit varsa kodu / hedefi guncellenir (aktif yapilir), yoksa eklenir.
     * Hedefi kendisi olan link atlanir. Dondurur: yazilan / guncellenen link sayisi.
     *
     * @param array<string,string[]> $collected
     */
    public static function upsert(PDO $pdo, array $collected, string $code, string $text, string $target): int
    {
        $count = 0;
        foreach ($collected as $table => $links) {
            $hasActive = self::hasColumn($pdo, $table, 'aktif');
            $existing = self::existing($pdo, $table);
            $update = $pdo->prepare('UPDATE dbo.[' . $table . '] SET turu_code = :code, turu_string = :text, yeni_link = :target'
                . ($hasActive ? ', aktif = 1' : '') . ' WHERE id = :id');
            $insert = $pdo->prepare('INSERT INTO dbo.[' . $table . '] (eski_link, yeni_link, turu_code, turu_string' . ($hasActive ? ', aktif' : '')
                . ') VALUES (:link, :target, :code, :text' . ($hasActive ? ', 1' : '') . ')');
            foreach ($links as $link) {
                $key = self::key($link);
                if ($target !== '' && $key === self::key($target)) {
                    continue;
                }
                if (isset($existing[$key])) {
                    foreach ($existing[$key] as $row) {
                        $update->execute([':code' => $code, ':text' => $text, ':target' => $target, ':id' => $row['id']]);
                    }
                } else {
                    $insert->execute([':link' => $link, ':target' => $target, ':code' => $code, ':text' => $text]);
                }
                $count++;
            }
        }

        return $count;
    }

    /**
     * Pasife alirken panelde girilen yonlendirme satirlari: { items: [{ eski_link, yeni_link?, turu_code, turu_string? }] }
     * (her satir kendi eski -> yeni linki ve turuyle). Ayni eski link iki satirda olamaz. Hatada 422.
     *
     * @param array<string,mixed> $input
     * @return array<int,array{code:string,text:string,target:string,links:string[]}>
     */
    public static function parseCustomItems(array $input): array
    {
        $items = $input['items'] ?? null;
        if (!is_array($items) || $items === []) {
            throw new HttpException('Yonlendirme satiri gereklidir.', 'VALIDATION', 422);
        }
        if (count($items) > 50) {
            throw new HttpException('Tek seferde en fazla 50 yonlendirme eklenebilir.', 'VALIDATION', 422);
        }
        $result = [];
        $seen = [];
        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                throw new HttpException('Gecersiz yonlendirme satiri.', 'VALIDATION', 422);
            }
            $old = is_string($item['eski_link'] ?? null) ? trim($item['eski_link']) : '';
            if ($old === '') {
                throw new HttpException(($index + 1) . '. satirda eski link gereklidir.', 'VALIDATION', 422);
            }
            $parsed = self::parseCustom([
                'turu_code' => $item['turu_code'] ?? null,
                'turu_string' => $item['turu_string'] ?? null,
                'yeni_link' => $item['yeni_link'] ?? null,
                'eski_linkler' => [$old],
            ]);
            $key = self::key($parsed['links'][0]);
            if (isset($seen[$key])) {
                throw new HttpException('Ayni eski link iki kez yazilmis: ' . $parsed['links'][0], 'VALIDATION', 422);
            }
            $seen[$key] = true;
            $result[] = $parsed;
        }

        return $result;
    }

    /**
     * Panelde secilen yonlendirmeyi dogrular (UrlRedirectsController ile ayni kurallar). Hatada 422.
     *
     * @param array<string,mixed> $input { turu_code, yeni_link?, turu_string?, eski_linkler? }
     * @return array{code:string,text:string,target:string,links:string[]|null} links null: villanin URL'si kullanilir
     */
    public static function parseCustom(array $input): array
    {
        $number = filter_var(is_string($input['turu_code'] ?? null) ? trim($input['turu_code']) : ($input['turu_code'] ?? null), FILTER_VALIDATE_INT);
        if ($number === false || $number < 300 || $number > 599) {
            throw new HttpException('Gecersiz yonlendirme durum kodu (300-599 arasi olmalidir).', 'VALIDATION', 422);
        }
        if (isset(self::STATUS_TEXTS[$number])) {
            $text = self::STATUS_TEXTS[$number];
        } else {
            $text = is_string($input['turu_string'] ?? null) ? trim($input['turu_string']) : '';
            if ($text === '') {
                $text = (string) $number;
            }
            if (strpos($text, (string) $number) !== 0) {
                $text = $number . ' ' . $text;
            }
            if (mb_strlen($text, 'UTF-8') > 100) {
                throw new HttpException('Durum aciklamasi en fazla 100 karakter olabilir.', 'VALIDATION', 422);
            }
        }

        $target = '';
        if (!in_array($number, [404, 410], true)) {
            $raw = is_string($input['yeni_link'] ?? null) ? trim($input['yeni_link']) : '';
            if ($raw !== '' && preg_match('/\s/u', $raw) === 1) {
                throw new HttpException('Yeni link bosluk iceremez.', 'VALIDATION', 422);
            }
            $target = self::link($raw);
            if ($target === '') {
                throw new HttpException('Bu durum kodu icin yeni link gereklidir.', 'VALIDATION', 422);
            }
        }

        $links = null;
        if (isset($input['eski_linkler']) && is_array($input['eski_linkler'])) {
            $links = [];
            foreach ($input['eski_linkler'] as $raw) {
                if (!is_string($raw) || trim($raw) === '') {
                    continue;
                }
                if (preg_match('/\s/u', trim($raw)) === 1) {
                    throw new HttpException('Eski link bosluk iceremez: ' . trim($raw), 'VALIDATION', 422);
                }
                $link = self::link($raw);
                if ($target !== '' && self::key($link) === self::key($target)) {
                    throw new HttpException('Bir link kendisine yonlendirilemez: ' . $link, 'VALIDATION', 422);
                }
                $links[self::key($link)] = $link;
            }
            $links = array_values($links);
            if ($links === []) {
                throw new HttpException('Eski link gereklidir.', 'VALIDATION', 422);
            }
        }

        return ['code' => (string) $number, 'text' => $text, 'target' => $target, 'links' => $links];
    }

    /**
     * Verilen linkleri sitenin yonlendirme tablosuna gore gruplar (tablo yoksa bos).
     *
     * @param string[] $links
     * @return array<string,string[]>
     */
    public static function collectLinks(PDO $pdo, int $siteId, array $links): array
    {
        $sites = self::sites($pdo);
        $table = 'yonlendirme' . ($sites[$siteId] ?? '');
        if (!isset($sites[$siteId]) || !self::tableExists($pdo, $table) || $links === []) {
            return [];
        }

        return [$table => array_values($links)];
    }

    /**
     * Linklerdeki 410 kayitlarini tablodan siler (villa tekrar aktif). Baska kodlu kayitlara dokunulmaz.
     * Dondurur: silinen kayit sayisi.
     *
     * @param array<string,string[]> $collected
     */
    public static function clearGone(PDO $pdo, array $collected): int
    {
        $count = 0;
        foreach ($collected as $table => $links) {
            $existing = self::existing($pdo, $table);
            $stmt = $pdo->prepare('DELETE FROM dbo.[' . $table . '] WHERE id = :id');
            foreach ($links as $link) {
                foreach ($existing[self::key($link)] ?? [] as $row) {
                    if ($row['code'] === self::CODE) {
                        $stmt->execute([':id' => $row['id']]);
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    /** @return array<string,array<int,array{id:int,code:string,active:bool}>> karsilastirma anahtari => kayitlar */
    private static function existing(PDO $pdo, string $table): array
    {
        $hasActive = self::hasColumn($pdo, $table, 'aktif');
        $rows = $pdo->query('SELECT id, eski_link, turu_code' . ($hasActive ? ', aktif' : '') . ' FROM dbo.[' . $table . ']')->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $row) {
            $link = trim((string) ($row['eski_link'] ?? ''));
            if ($link === '') {
                continue;
            }
            $map[self::key($link)][] = [
                'id' => (int) $row['id'],
                'code' => trim((string) ($row['turu_code'] ?? '')),
                'active' => $hasActive ? !empty($row['aktif']) : true,
            ];
        }

        return $map;
    }

    /** @return array<int,string> site id => tablo soneki ('' / '_s2') */
    private static function sites(PDO $pdo): array
    {
        $sites = [];
        try {
            foreach ($pdo->query('SELECT id, dbtable FROM sites ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $suffix = trim((string) ($row['dbtable'] ?? ''));
                if ($suffix === '' || preg_match('/^_[A-Za-z0-9]+$/', $suffix) === 1) {
                    $sites[(int) $row['id']] = $suffix;
                }
            }
        } catch (\PDOException $e) {
            // sites tablosu yoksa tek site.
        }

        return $sites !== [] ? $sites : [1 => ''];
    }

    // UrlRedirectsController ile ayni: site ici link "/" ile baslar, tam adres oldugu gibi kalir; bosluklu link yazilmaz.
    private static function link(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/\s/u', $url) === 1) {
            return '';
        }

        return preg_match('#^([a-z][a-z0-9+.-]*:|/)#i', $url) === 1 ? $url : '/' . $url;
    }

    // UrlRedirectsController::key ile ayni ("/Sayfa/", "/sayfa", "sayfa" tek kayit).
    private static function key(string $link): string
    {
        $link = mb_strtolower(trim($link), 'UTF-8');
        $trimmed = trim($link, '/');

        return $trimmed === '' ? $link : $trimmed;
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT CASE WHEN OBJECT_ID(N'dbo.' + QUOTENAME(:t), N'U') IS NULL THEN 0 ELSE 1 END");
        $stmt->execute([':t' => $table]);

        return (int) $stmt->fetchColumn() === 1;
    }

    private static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare("SELECT CASE WHEN COL_LENGTH(N'dbo.' + QUOTENAME(:t), :c) IS NULL THEN 0 ELSE 1 END");
        $stmt->execute([':t' => $table, ':c' => $column]);

        return (int) $stmt->fetchColumn() === 1;
    }
}
