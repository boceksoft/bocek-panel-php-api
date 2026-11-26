<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/*
 * Sitenin on yuz HTML cache'ini (cache.php -> {siteRoot}/HtmlCache) villa fiyat / musaitlik degisikliginden sonra temizler.
 * Eski panel karsiligi: asp_scripts/cacheScripts.asp (cacheIslem = calendar / ajax). Her site (sites tablosu) icin:
 *   mod 1: HtmlCache/l/ + tip / destinations url klasorleri + HtmlCacheFile/listingLinks.html icindeki linkler (liste sayfalari)
 *   mod 2: HtmlCache/d/{villaUrl}
 *   mod 3: HtmlCache/{villaUrl}
 * Site klasoru: sites.CacheRealPath (yoksa / bossa realpath) icinden '/uploads' atilir (ASP: replace(realpath, "/uploads", "") & "/HtmlCache").
 * Goreli yol (orn. '../uploads', '../../../balayicim.com/httpdocs/uploads') ASP'deki Server.MapPath gibi panel klasorune,
 * yani site kokunun bir alt klasorune gore cozulur: '../uploads' -> {kok}/HtmlCache. '/' ile baslayan yol site kokune gore.
 *
 * Config: html_cache_clear (bool, varsayilan false) acik degilse hicbir sey yapmaz.
 *         html_cache_root (string) bos ise site koku backend-api'nin bir ust klasorudur.
 * Hata kaydi bozmaz: tum hatalar yutulur (ASP'deki on error resume next gibi); sonuc rapor olarak doner ve
 * backend-api/.html-cache.log dosyasina yazilir (son temizlikler). Teshis: GET/POST /backend-api/html-cache?id={emlakId}.
 */
final class HtmlCacheCleaner
{
    /** @var array<string,mixed> Calisan temizligin raporu */
    private static $report = [];

    /** @var bool true: hicbir sey silinmez, sadece rapor (teshis) */
    private static $dryRun = false;

    /**
     * @param int[] $homeIds
     * @param bool $force config kapali olsa da calisir (teshis endpoint'i)
     * @param bool $dryRun true: silmez, sadece neyin silinecegini raporlar
     * @return array<string,mixed> rapor
     */
    public static function clearHomes(PDO $pdo, array $app, array $homeIds, bool $force = false, bool $dryRun = false): array
    {
        $homeIds = array_values(array_unique(array_filter(array_map('intval', $homeIds), function ($id) {
            return $id > 0;
        })));

        self::$dryRun = $dryRun;
        self::$report = [
            'time' => date('Y-m-d H:i:s'),
            'enabled' => !empty($app['html_cache_clear']),
            'dry_run' => $dryRun,
            'home_ids' => $homeIds,
            'root' => '',
            'sites' => [],
            'error' => null,
        ];

        if (!$force && empty($app['html_cache_clear'])) {
            self::$report['error'] = 'html_cache_clear kapali (Villa Kiralama > Config); cache silinmedi.';

            return self::$report;
        }
        if ($homeIds === []) {
            self::$report['error'] = 'Emlak ID yok.';

            return self::$report;
        }

        try {
            $root = self::siteRoot($app);
            self::$report['root'] = $root;
            if ($root === '') {
                self::$report['error'] = 'Site kok klasoru bulunamadi (html_cache_root).';

                return self::$report;
            }

            $sites = self::sites($pdo);

            $homeColumns = self::columns($pdo, 'homes');
            $homeUrls = self::homeUrls($pdo, $homeIds, $sites, $homeColumns);

            foreach ($sites as $i => $site) {
                $cacheDir = self::resolveDir($root, $site['path']) . '/HtmlCache';
                self::$report['sites'][$i] = [
                    'realpath' => $site['realpath'],
                    'url_column' => $site['column'],
                    'cache_dir' => $cacheDir,
                    'cache_dir_exists' => is_dir($cacheDir),
                    'cache_dir_writable' => is_dir($cacheDir) && is_writable($cacheDir),
                    'home_urls' => $homeUrls[$site['column']] ?? [],
                    'deleted' => [],
                    'not_found' => [],
                    'failed' => [],
                ];
                if (!is_dir($cacheDir)) {
                    continue;
                }

                // mod 1: liste sayfalari (villa kartlarinda fiyat gorunur)
                self::deleteChild($i, $cacheDir, 'l');
                foreach (self::listingUrls($pdo, $root, $sites) as $url) {
                    self::deleteChild($i, $cacheDir, $url);
                }

                // mod 2 / 3: villa detay sayfalari
                foreach ($homeUrls[$site['column']] ?? [] as $url) {
                    self::deleteChild($i, $cacheDir . '/d', $url);
                    self::deleteChild($i, $cacheDir, $url);
                }
            }
        } catch (\Throwable $e) {
            // Cache temizlenemezse kayit yine basarili sayilir.
            self::$report['error'] = $e->getMessage();
        }

        if (!$dryRun) {
            self::writeLog(self::$report);
        }

        return self::$report;
    }

    /**
     * backend-api/.html-cache.log: her temizlikte tek satir JSON (dosya 200 KB'i gecince bastan yazilir).
     *
     * @param array<string,mixed> $report
     */
    private static function writeLog(array $report): void
    {
        $file = dirname(__DIR__, 2) . '/.html-cache.log';
        $summary = $report;
        foreach ($summary['sites'] as &$site) {
            $site['deleted'] = count($site['deleted']);
            $site['not_found'] = count($site['not_found']);
        }
        unset($site);
        $line = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $append = is_file($file) && filesize($file) < 200 * 1024;
        @file_put_contents($file, $line, $append ? FILE_APPEND : 0);
    }

    /**
     * Site yolunu (sites.realpath'ten '/uploads' atilmis) mutlak klasore cevirir. Klasorun var olmasi gerekmez.
     * '' ya da '/x' -> {kok}/x; goreli 'x' / '../x' -> {kok}/{panel}/x (panel site kokunun bir alt klasoru, ASP MapPath gibi).
     */
    private static function resolveDir(string $root, string $path): string
    {
        $full = ($path === '' || $path[0] === '/') ? $root . $path : $root . '/_panel_/' . $path;
        $prefix = '';
        if (preg_match('#^([A-Za-z]:)(/.*)?$#', $full, $m)) {
            $prefix = $m[1];
            $full = $m[2] ?? '';
        }
        $parts = [];
        foreach (explode('/', $full) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return $prefix . '/' . implode('/', $parts);
    }

    private static function siteRoot(array $app): string
    {
        $root = trim((string) ($app['html_cache_root'] ?? ''));
        if ($root === '') {
            $root = dirname(__DIR__, 3);
        }
        $real = realpath($root);

        return $real === false ? '' : rtrim(str_replace('\\', '/', $real), '/');
    }

    /**
     * @return array<int,array{realpath:string,path:string,column:string}> en az bir eleman
     */
    private static function sites(PDO $pdo): array
    {
        $rows = [];
        try {
            $rows = $pdo->query('SELECT * FROM sites')->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            self::$report['sites_error'] = $e->getMessage();
        }
        self::$report['sites_row_count'] = count($rows);

        $result = [];
        foreach ($rows as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $realPath = trim((string) ($row['cacherealpath'] ?? ''));
            if ($realPath === '') {
                $realPath = trim((string) ($row['realpath'] ?? ''));
            }
            // Tam adres yazilmissa (https://site.com/uploads) sadece yol kismi kullanilir.
            $path = strpos($realPath, '://') !== false ? (string) parse_url($realPath, PHP_URL_PATH) : $realPath;
            $path = rtrim(str_replace('/uploads', '', str_replace('\\', '/', $path)), '/');
            $result[] = [
                'realpath' => $realPath,
                'path' => $path,
                'column' => 'url' . trim((string) ($row['dbtable'] ?? '')),
            ];
        }

        // sites tablosu bos / okunamiyorsa tek site varsayilir: kokteki HtmlCache, homes.url.
        if ($result === []) {
            $result[] = ['realpath' => '(sites bos, varsayilan)', 'path' => '', 'column' => 'url'];
        }

        return $result;
    }

    /** @var array<string,array<string,bool>> */
    private static $columnCache = [];

    /**
     * @return array<string,bool> kucuk harf kolon adi => true
     */
    private static function columns(PDO $pdo, string $table): array
    {
        if (!isset(self::$columnCache[$table])) {
            $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = :t");
            $stmt->execute([':t' => $table]);
            $cols = [];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
                $cols[strtolower((string) $name)] = true;
            }
            self::$columnCache[$table] = $cols;
        }

        return self::$columnCache[$table];
    }

    /**
     * @param int[] $homeIds
     * @param array<int,array{path:string,column:string}> $sites
     * @param array<string,bool> $homeColumns
     * @return array<string,string[]> url kolonu => villa url'leri
     */
    private static function homeUrls(PDO $pdo, array $homeIds, array $sites, array $homeColumns): array
    {
        $select = [];
        foreach ($sites as $site) {
            if (isset($homeColumns[strtolower($site['column'])])) {
                $select[$site['column']] = '[' . $site['column'] . ']';
            }
        }
        if ($select === []) {
            return [];
        }

        $ids = implode(',', $homeIds);
        $rows = $pdo->query('SELECT ' . implode(', ', $select) . " FROM homes WHERE id IN ({$ids})")->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            foreach (array_keys($select) as $column) {
                $url = trim((string) ($row[$column] ?? ''));
                if ($url !== '') {
                    $result[$column][] = $url;
                }
            }
        }

        return $result;
    }

    /** @var string[]|null */
    private static $listingUrlCache = null;

    /**
     * Liste sayfasi klasorleri: tip + destinations url'leri (tum siteler) ve listingLinks.html linkleri.
     *
     * @param array<int,array{path:string,column:string}> $sites
     * @return string[]
     */
    private static function listingUrls(PDO $pdo, string $root, array $sites): array
    {
        if (self::$listingUrlCache !== null) {
            return self::$listingUrlCache;
        }

        $urls = [];
        foreach (['tip', 'destinations'] as $table) {
            $columns = self::columns($pdo, $table);
            $select = [];
            foreach ($sites as $site) {
                if (isset($columns[strtolower($site['column'])])) {
                    $select[] = '[' . $site['column'] . ']';
                }
            }
            if ($select === []) {
                continue;
            }
            try {
                foreach ($pdo->query('SELECT ' . implode(', ', $select) . " FROM [{$table}]")->fetchAll(PDO::FETCH_NUM) as $row) {
                    foreach ($row as $value) {
                        $value = trim((string) $value);
                        if ($value !== '') {
                            $urls[$value] = true;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // tablo yoksa / okunamazsa atlanir
            }
        }

        // HtmlCacheFile/listingLinks.html: "baslik##url;/;/baslik##url" (panel html_cache.asp ayar ekrani)
        $linksFile = $root . '/HtmlCacheFile/listingLinks.html';
        if (is_file($linksFile)) {
            $content = str_replace(["\r", "\n"], '', (string) @file_get_contents($linksFile));
            foreach (explode(';/;/', $content) as $item) {
                if (strpos($item, '##') === false) {
                    continue;
                }
                $parts = explode('##', $item);
                $value = trim((string) ($parts[1] ?? ''));
                if ($value !== '') {
                    $urls[$value] = true;
                }
            }
        }

        return self::$listingUrlCache = array_keys($urls);
    }

    /**
     * $parent altindaki $name klasorunu / dosyasini siler. cache.php klasor adini URL'nin son parcasindan
     * aldigi icin (end(explode('/', l))) url '/' iceriyorsa son parca kullanilir; '..' olan ad atlanir.
     */
    private static function deleteChild(int $siteIndex, string $parent, string $name): void
    {
        $name = trim(str_replace('\\', '/', $name), '/');
        if (strpos($name, '/') !== false) {
            $parts = explode('/', $name);
            $name = (string) end($parts);
        }
        if ($name === '' || $name === '.' || strpos($name, '..') !== false) {
            return;
        }
        $target = $parent . '/' . $name;
        $rel = $target;
        if (!file_exists($target)) {
            self::$report['sites'][$siteIndex]['not_found'][] = $rel;

            return;
        }
        if (self::$dryRun) {
            self::$report['sites'][$siteIndex]['deleted'][] = $rel . ' (silinecek)';

            return;
        }
        if (is_dir($target) && !is_link($target)) {
            self::deleteTree($target);
        } else {
            @unlink($target);
        }
        if (file_exists($target)) {
            $error = error_get_last();
            self::$report['sites'][$siteIndex]['failed'][] = $rel . ': ' . (string) ($error['message'] ?? 'silinemedi (yetki?)');
        } else {
            self::$report['sites'][$siteIndex]['deleted'][] = $rel;
        }
    }

    private static function deleteTree(string $dir): void
    {
        $items = @scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                self::deleteTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
