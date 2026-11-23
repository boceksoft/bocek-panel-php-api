<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\LegacySql;
use PDO;

/*
 * Sitenin alan adi, arama sayfasi ve rezervasyon sayfasi URL bilgileri, sadece okuma.
 *   GET /backend-api/site-url?site=1
 *   GET /backend-api/site-url/reservation-url?site=1
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
        [$siteDomain, $fullDomain] = $this->resolveSiteDomain($pdo, $siteId);

        $searchUrls = $this->app['site_search_url'] ?? [];
        $searchUrl = '';
        $inlineParams = [];
        if (is_array($searchUrls)) {
            $val = $searchUrls[$siteId] ?? ($searchUrls[(string) $siteId] ?? '');
            if (is_array($val)) {
                $searchUrl = (string) ($val['url'] ?? ($val['path'] ?? ''));
                $inlineParams = isset($val['params']) && is_array($val['params']) ? $val['params'] : $val;
            } else {
                $searchUrl = (string) $val;
            }
        } elseif (is_string($searchUrls)) {
            $searchUrl = $searchUrls;
        }
        $searchUrl = trim($searchUrl);
        if ($searchUrl === '') {
            $searchUrl = 'arama-sonuclari';
        }

        $searchPageUrl = '';
        if ($searchUrl !== '') {
            $searchPageUrl = preg_match('#^https?://#i', $searchUrl) === 1
                ? $searchUrl
                : rtrim($fullDomain, '/') . '/' . ltrim($searchUrl, '/');
        }

        $defaultSearchParams = [
            'start' => 'start',
            'end' => 'end',
            'adult' => 'yetiskin',
            'child' => 'cocuk',
            'baby' => 'bebek',
            'min' => 'min',
            'max' => 'max',
            'region' => 'bolge',
            'type' => 'type',
            'use_mutliple_for_array_query' => false,
        ];
        $defaultFormatting = [
            'start' => 'Y-m-d',
            'end' => 'Y-m-d',
        ];

        $searchParamsConfig = $this->app['site_search_params'] ?? ($this->app['site_search_queries'] ?? []);
        $configuredParams = [];
        if (is_array($searchParamsConfig)) {
            $cfg = $searchParamsConfig[$siteId] ?? ($searchParamsConfig[(string) $siteId] ?? []);
            if (is_array($cfg)) {
                $configuredParams = $cfg;
            }
        }

        $sourceParams = array_merge($inlineParams, $configuredParams);
        $searchParams = [];
        foreach ($defaultSearchParams as $k => $defaultVal) {
            if ($k === 'use_mutliple_for_array_query') {
                continue;
            }
            if (array_key_exists($k, $sourceParams)) {
                $searchParams[$k] = trim((string) $sourceParams[$k]);
            } else {
                $searchParams[$k] = $defaultVal;
            }
        }

        $searchParams['use_mutliple_for_array_query'] = (bool) (
            $sourceParams['use_mutliple_for_array_query']
            ?? ($sourceParams['use_multiple_for_array_query'] ?? false)
        );

        $rawFormatting = array_key_exists('formatting', $sourceParams)
            ? (is_array($sourceParams['formatting']) ? $sourceParams['formatting'] : [])
            : $defaultFormatting;

        $formatting = [];
        if (is_array($rawFormatting)) {
            foreach ($rawFormatting as $fKey => $fVal) {
                $fVal = trim((string) $fVal);
                if ($fVal !== '') {
                    $formatting[(string) $fKey] = $fVal;
                }
            }
        }

        $searchParams['formatting'] = $formatting;

        $this->response->success([
            'site' => [
                'id' => $siteId,
                'domain' => $siteDomain,
                'search_url' => $searchUrl,
                'search_page_url' => $searchPageUrl,
                'search_params' => $searchParams,
                'formatting' => $formatting,
            ],
            'domain' => $siteDomain,
            'search_url' => $searchUrl,
            'search_page_url' => $searchPageUrl,
            'search_params' => $searchParams,
            'formatting' => $formatting,
        ]);
    }

    /**
     * Sitenin rezervasyon sayfasi URL ve parametre yapisini dondurur.
     *
     * @Get("reservation-url")
     * @query site int Site kimliği
     */
    public function reservationUrl(): void
    {
        $pdo = $this->db->pdo();
        $siteId = $this->siteId();
        [$siteDomain, $fullDomain] = $this->resolveSiteDomain($pdo, $siteId);

        $config = is_array($this->app['calculate_reservation_url'] ?? null)
            ? $this->app['calculate_reservation_url']
            : [];

        $rawPath = trim((string) ($config['path'] ?? '/rezervasyon'));
        if ($rawPath === '') {
            $path = '';
        } elseif (preg_match('#^https?://#i', $rawPath) === 1) {
            $path = $rawPath;
        } else {
            $path = '/' . ltrim($rawPath, '/');
        }

        if ($path !== '') {
            $reservationPageUrl = preg_match('#^https?://#i', $path) === 1
                ? $path
                : ($fullDomain !== '' ? rtrim($fullDomain, '/') . $path : $path);
        } else {
            $reservationPageUrl = $fullDomain;
        }

        $defaultParams = [
            'entity_id' => 'id',
            'start' => 'reservationdate1',
            'end' => 'reservationdate2',
            'adult' => 'adult',
            'child' => 'child',
            'baby' => 'baby',
            'pool_fee' => 'buyPool',
        ];
        $rawParams = is_array($config['params'] ?? null) ? $config['params'] : [];
        $params = [];
        foreach ($defaultParams as $key => $defaultVal) {
            $params[$key] = array_key_exists($key, $rawParams)
                ? trim((string) $rawParams[$key])
                : $defaultVal;
        }
        foreach ($rawParams as $key => $val) {
            if (!array_key_exists($key, $params)) {
                $params[(string) $key] = trim((string) $val);
            }
        }

        $dateFormat = trim((string) ($config['date_format'] ?? 'd.m.Y'));
        if ($dateFormat === '') {
            $dateFormat = 'd.m.Y';
        }

        $this->response->success([
            'site' => [
                'id' => $siteId,
                'domain' => $siteDomain,
                'reservation_url' => $reservationPageUrl,
                'path' => $path,
                'params' => $params,
                'date_format' => $dateFormat,
            ],
            'domain' => $siteDomain,
            'reservation_url' => $reservationPageUrl,
            'path' => $path,
            'params' => $params,
            'date_format' => $dateFormat,
        ]);
    }

    /**
     * @return array{0:string,1:string} [siteDomain, fullDomain]
     */
    private function resolveSiteDomain(PDO $pdo, int $siteId): array
    {
        $suffix = $this->tableSuffix($pdo, $siteId);

        $genel = [];
        try {
            $row = $pdo->query('SELECT TOP 1 * FROM genel' . $suffix)->fetch(PDO::FETCH_ASSOC);
            $genel = is_array($row) ? array_change_key_case($row, CASE_LOWER) : [];
        } catch (\PDOException $e) {
            $genel = [];
        }
        $domainValue = '';
        if (isset($genel['domain'])) {
            $domainValue = trim((string) $genel['domain']);
        }
        $domain = rtrim($domainValue, '/');
        $siteDomain = str_replace('web.', 'www.', $domain);

        $fullDomain = $siteDomain;
        if ($fullDomain !== '' && preg_match('#^https?://#i', $fullDomain) !== 1) {
            $fullDomain = 'https://' . $fullDomain;
        }

        return [$siteDomain, $fullDomain];
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

