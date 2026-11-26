<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Support\HtmlCacheCleaner;

/*
 * Site on yuz HTML cache'i (HtmlCache) teshis / elle temizleme. Otomatik temizlik icin bkz. config html_cache_clear.
 *
 *   GET  /backend-api/html-cache?id={emlakId}        silmeden rapor: kok klasor, siteler, villa url'leri, silinecekler
 *   POST /backend-api/html-cache?id={emlakId}        config kapali olsa da villanin cache'ini siler, raporu doner
 *   GET  /backend-api/html-cache/log                 son otomatik temizliklerin kaydi (.html-cache.log, son 20 satir)
 */
final class HtmlCacheController extends Controller
{
    /**
     * @Get
     * @query id int required Emlak ID
     */
    public function index(): void
    {
        $this->response->success(
            HtmlCacheCleaner::clearHomes($this->db->pdo(), $this->app, [$this->homeId()], true, true)
        );
    }

    /**
     * @Post
     * @query id int required Emlak ID
     */
    public function clear(): void
    {
        $this->response->success(
            HtmlCacheCleaner::clearHomes($this->db->pdo(), $this->app, [$this->homeId()], true, false)
        );
    }

    /**
     * @Get("log")
     */
    public function log(): void
    {
        $file = dirname(__DIR__, 2) . '/.html-cache.log';
        $lines = is_file($file) ? (array) @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        $entries = [];
        foreach (array_slice($lines, -20) as $line) {
            $decoded = json_decode((string) $line, true);
            $entries[] = is_array($decoded) ? $decoded : $line;
        }

        $this->response->success([
            'enabled' => !empty($this->app['html_cache_clear']),
            'log_exists' => is_file($file),
            'entries' => array_reverse($entries),
        ]);
    }

    private function homeId(): int
    {
        $id = (int) $this->request->query('id', 0);
        if ($id <= 0) {
            throw new HttpException('Lutfen gecerli bir emlak ID gonderin.', 'VALIDATION', 422);
        }

        return $id;
    }
}
