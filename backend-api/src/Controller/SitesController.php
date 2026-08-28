<?php

declare(strict_types=1);

namespace App\Controller;

/*
 * Site secenekleri kaynagi.
 *   GET /backend-api/sites
 *   GET /backend-api/sites?site=2
 */
final class SitesController extends Controller
{
    /**
     * @Get
     * @query site int Aktif site kimligi
     */
    public function index(): void
    {
        $siteId = $this->siteId();
        $activeSite = [
            'id' => $siteId,
            'siteadi' => $this->siteName($siteId),
        ];

        $this->response->success([
            'site' => $activeSite,
            'active_site' => $activeSite,
            'sites' => $this->sites(),
            'siteadi' => $activeSite['siteadi'],
        ]);
    }

    private function siteId(): int
    {
        foreach (['site', 'Site', 'site_id', 'SiteId', 'siteId', 'currentSite', 'currentSiteId'] as $key) {
            $value = $this->request->query($key);
            if ($value === null) {
                $value = $this->request->input($key);
            }
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        foreach (['X-Site', 'X-Site-Id', 'X-Current-Site', 'X-Current-Site-Id'] as $header) {
            $value = $this->request->header($header);
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        $detectedSite = $this->siteIdFromRequestHost();
        if ($detectedSite > 0) {
            return $detectedSite;
        }

        return defined('PRICE_SITE') ? max(1, (int) constant('PRICE_SITE')) : 1;
    }

    private function siteName(int $siteId): string
    {
        $table = $siteId === 2 ? 'genel_s2' : 'genel';

        try {
            $row = $this->db->pdo()->query("SELECT TOP 1 siteadi FROM {$table}")->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return '';
        }

        return is_array($row) ? trim((string) ($row['siteadi'] ?? '')) : '';
    }

    /**
     * @return array<int,array{id:int,siteadi:string}>
     */
    private function sites(): array
    {
        return [
            [
                'id' => 1,
                'siteadi' => $this->siteName(1),
            ],
            [
                'id' => 2,
                'siteadi' => $this->siteName(2),
            ],
        ];
    }

    private function siteIdFromRequestHost(): int
    {
        $requestHosts = array_filter([
            $this->hostFromUrl($_SERVER['HTTP_ORIGIN'] ?? ''),
            $this->hostFromUrl($_SERVER['HTTP_REFERER'] ?? ''),
            $this->hostFromUrl($_SERVER['HTTP_HOST'] ?? ''),
        ]);
        if ($requestHosts === []) {
            return 0;
        }

        $site2Host = $this->siteDomainHost(2);
        if ($site2Host === '') {
            return 0;
        }

        foreach ($requestHosts as $host) {
            if ($host === $site2Host) {
                return 2;
            }
        }

        return 0;
    }

    private function siteDomainHost(int $siteId): string
    {
        $queries = $this->app['homes_site_domain_queries'] ?? [];
        $query = is_array($queries)
            ? (string) ($queries[$siteId] ?? ($queries[(string) $siteId] ?? ''))
            : '';
        if (trim($query) === '' && $siteId === 2) {
            $query = 'SELECT TOP 1 domain FROM genel_s2';
        }

        try {
            $row = $this->db->pdo()->query($query)->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return '';
        }

        return is_array($row) ? $this->hostFromUrl((string) ($row['domain'] ?? reset($row))) : '';
    }

    private function hostFromUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $value) !== 1) {
            $value = 'https://' . $value;
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }

        return preg_replace('/^www\./i', '', strtolower($host)) ?: '';
    }
}
