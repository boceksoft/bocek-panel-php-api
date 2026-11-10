<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\LegacySql;
use PDO;

/*
 * Sitenin alan adi ve arama sayfasi URL'si, sadece okuma.
 *   GET /backend-api/site-url?site=1
 */
class SiteUrlController extends Controller
{
    use LegacySql;

    /**
     * Sitenin domain ve arama sayfasi URL bilgisini dondurur.
     *
     * @Get
     * @query site int Site kimliği
     */
    public function index(): void
    {
        $pdo = $this->db->pdo();
        $siteId = $this->siteId();
        $suffix = $this->tableSuffix($pdo, $siteId);

        $genel = [];
        try {
            $row = $pdo->query('SELECT TOP 1 * FROM genel' . $suffix)->fetch(PDO::FETCH_ASSOC);
            $genel = is_array($row) ? array_change_key_case($row, CASE_LOWER) : [];
        } catch (\PDOException $e) {
            $genel = [];
        }
        $g = function (string $key) use ($genel): string {
            $key = strtolower($key);
            return isset($genel[$key]) ? trim((string) $genel[$key]) : '';
        };
        $domain = rtrim($g('domain'), '/');
        $siteDomain = str_replace('web.', 'www.', $domain);

        $searchUrls = $this->app['site_search_url'] ?? [];
        $searchUrl = '';
        if (is_array($searchUrls)) {
            $searchUrl = (string) ($searchUrls[$siteId] ?? ($searchUrls[(string) $siteId] ?? ''));
        } elseif (is_string($searchUrls)) {
            $searchUrl = $searchUrls;
        }
        $searchUrl = trim($searchUrl);
        if ($searchUrl === '') {
            $searchUrl = 'arama-sonuclari';
        }

        $fullDomain = $siteDomain;
        if ($fullDomain !== '' && preg_match('#^https?://#i', $fullDomain) !== 1) {
            $fullDomain = 'https://' . $fullDomain;
        }

        $searchPageUrl = '';
        if ($searchUrl !== '') {
            $searchPageUrl = preg_match('#^https?://#i', $searchUrl) === 1
                ? $searchUrl
                : rtrim($fullDomain, '/') . '/' . ltrim($searchUrl, '/');
        }

        $this->response->success([
            'site' => [
                'id' => $siteId,
                'domain' => $siteDomain,
                'search_url' => $searchUrl,
                'search_page_url' => $searchPageUrl,
            ],
            'domain' => $siteDomain,
            'search_url' => $searchUrl,
            'search_page_url' => $searchPageUrl,
        ]);
    }

    /** sites.dbtable ("", "_s2" ...). Geçersiz sonek ya da tablo yoksa "". */
    private function tableSuffix(PDO $pdo, int $siteId): string
    {
        try {
            $stmt = $pdo->prepare('SELECT dbtable FROM sites WHERE id = :id');
            $stmt->execute([':id' => $siteId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return '';
        }
        $suffix = is_array($row) ? trim((string) ($row['dbtable'] ?? '')) : '';

        return $suffix === '' || preg_match('/^_[A-Za-z0-9]+$/', $suffix) === 1 ? $suffix : '';
    }
}

