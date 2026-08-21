<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;

/*
 * Kısa link (teklif linki) kaynağı.
 *   POST /api/links
 *   Body (JSON):
 *   {
 *     "ids":   [5648],
 *     "start": "2026-06-16",
 *     "end":   "2026-06-23",
 *     "sure":  3,
 *     "teklifId": 949715,
 *     "pool_fee": 1
 *   }
 * (Eski create-link.php'nin yeni iskelete taşınmış hâli — POST örneği.)
 */
final class LinksController extends Controller
{
    /**
     * Seçilen villalar için kısa yönlendirme linki oluşturur.
     *
     * @Post
     * @body ids array required Villa kimlikleri
     * @body start string Başlangıç tarihi (YYYY-MM-DD)
     * @body end string Bitiş tarihi (YYYY-MM-DD)
     * @body sure int Geçerlilik süresi (gün)
     * @body teklifId int Bağlı teklif kimliği
     * @body pool_fee bool Havuz ısıtma ücreti eklensin mi
     */
    public function create(): void
    {
        // 1) Gövdeyi oku (JSON; değilse $_POST'a düşer)
        $ids      = $this->request->input('ids', []);
        if (!is_array($ids)) {
            $ids = [];
        }
        $singleId = (int) $this->request->input('id', $this->request->input('home_id', 0));
        if ($singleId > 0) {
            $ids[] = $singleId;
        }
        $start    = trim((string) $this->request->input('start', ''));
        $end      = trim((string) $this->request->input('end', ''));
        $sure     = (int) $this->request->input('sure', 0);
        $siteId   = $this->siteId();
        $teklifId = $this->request->input('teklifId');
        $teklifId = !empty($teklifId) ? (int) $teklifId : null;
        $poolFee = (string) $this->request->input('pool_fee', $this->request->input('buyPool', ''));

        // 2) Doğrula
        $intIds = array_map('intval', $ids);
        $validIds = array_values(array_filter($intIds, function ($v) {
            return $v > 0;
        }));

        if ($validIds === []) {
            throw new HttpException('Lütfen listeden en az bir villa seçiniz.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();

        // 3) Sıradaki id'den eşsiz link üret
        $nextRow = $pdo->query('SELECT ISNULL(MAX(id), 0) + 1 AS nextId FROM redirects')->fetch();
        $nextId = (int) ($nextRow['nextId'] ?? 1);
        $originalLink = $this->randomString(4) . $nextId;

        // 4) Yönlendirme hedefini homes.id ile sayfa URL'sine kur
        $searchPageQuery = trim((string) ($this->app['links_search_page_query'] ?? 'SELECT url FROM tip WHERE id = 1'));
        $urlRow = $pdo->query($searchPageQuery)->fetch();
        $aramaSayfasi = trim((string) ($urlRow['url'] ?? ''));
        if ($aramaSayfasi === '') {
            throw new HttpException('Link sayfa URL ayarı bulunamadı.', 'CONFIG_ERROR', 500);
        }
        $domain = $this->siteDomain($pdo, $siteId);
        $redirectTo = $this->reservationRedirectUrl($domain, $aramaSayfasi, $validIds, $start, $end, $poolFee);

        // 5) Süre verildiyse son kullanma tarihini hesapla
        $expiredMode = 0;
        $expiredDate = null;
        if ($sure > 0) {
            $date = new \DateTime();
            $date->modify('+' . $sure . ' days');
            $expiredDate = $date->format('Y-m-d H:i:s');
        }

        // 6) Kaydet
        $insertColumns = 'originalLink, teklifId, redirectTo';
        $insertValues = ':originalLink, :teklifId, :redirectTo';
        $insertParams = [
            ':originalLink' => $originalLink,
            ':teklifId'     => $teklifId,
            ':redirectTo'   => $redirectTo,
        ];

        $insertColumns .= ', expiredDate, expiredMode';
        $insertValues .= ', :expiredDate, :expiredMode';
        $insertParams[':expiredDate'] = $expiredDate;
        $insertParams[':expiredMode'] = $expiredMode;

        $stmt = $pdo->prepare(
            'INSERT INTO redirects (' . $insertColumns . ')
             VALUES (' . $insertValues . ')'
        );
        $stmt->execute($insertParams);

        // 7) Standart başarı zarfı
        $finalDomain = $siteId > 1 ? $domain : str_replace('www.', '', $domain);
        $finalLink = $finalDomain . '/' . $originalLink . '?v';

        $this->response->success([
            'link'         => $finalLink,
            'originalLink' => $originalLink,
            'teklifId'     => $teklifId,
        ], 201);
    }

    private function randomString(int $length = 4): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $max = strlen($chars) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, $max)];
        }

        return $out;
    }

    /**
     * @param int[] $entityIds
     */
    private function reservationRedirectUrl(string $domain, string $pageUrl, array $entityIds, string $start, string $end, string $poolFee): string
    {
        $domain = $this->normalizeDomain($domain);

        $config = is_array($this->app['links_reservation_url'] ?? null)
            ? $this->app['links_reservation_url']
            : [];
        $params = is_array($config['params'] ?? null) ? $config['params'] : [];
        $dateFormat = trim((string) ($config['date_format'] ?? 'Y-m-d'));
        $dateFormat = $dateFormat !== '' ? $dateFormat : 'Y-m-d';

        $query = [
            $this->queryParamName($params, 'entity_id', 'ids') => implode(',', $entityIds),
        ];
        if ($start !== '') {
            $query[$this->queryParamName($params, 'start', 'start')] = $this->formatDate($start, $dateFormat);
        }
        if ($end !== '') {
            $query[$this->queryParamName($params, 'end', 'end')] = $this->formatDate($end, $dateFormat);
        }
        if ($poolFee === '1') {
            $query[$this->queryParamName($params, 'pool_fee', 'buyPool')] = 1;
        }

        return $domain . '/' . ltrim($pageUrl, '/') . '?' . urldecode(http_build_query($query, '', '&'));
    }

    private function siteId(): int
    {
        foreach (['site', 'site_id', 'siteId', 'currentSite', 'currentSiteId'] as $key) {
            $value = $this->request->input($key, '');
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return defined('PRICE_SITE') ? max(1, (int) constant('PRICE_SITE')) : 1;
    }

    private function siteDomain(\PDO $pdo, int $siteId): string
    {
        if ($siteId <= 1) {
            return defined('Domain') ? $this->normalizeDomain((string) constant('Domain')) : '';
        }

        $queries = $this->app['links_site_domain_queries'] ?? ($this->app['homes_site_domain_queries'] ?? []);
        $query = is_array($queries)
            ? (string) ($queries[$siteId] ?? ($queries[(string) $siteId] ?? ''))
            : '';
        if (trim($query) === '') {
            throw new HttpException('Site domain sorgusu tanımlı değil. Site: ' . $siteId, 'CONFIG_ERROR', 500);
        }

        try {
            $row = $pdo->query($query)->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new HttpException('Site domain sorgusu çalıştırılamadı. Site: ' . $siteId, 'CONFIG_ERROR', 500, $e);
        }

        if (!is_array($row)) {
            throw new HttpException('Site domain kaydı bulunamadı. Site: ' . $siteId, 'CONFIG_ERROR', 500);
        }

        $domain = trim((string) ($row['domain'] ?? reset($row)));

        if ($domain === '') {
            throw new HttpException('Site domain değeri boş. Site: ' . $siteId, 'CONFIG_ERROR', 500);
        }

        return $this->normalizeDomain($domain);
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = rtrim(trim($domain), '/');
        if ($domain !== '' && preg_match('#^https?://#i', $domain) !== 1) {
            $domain = 'https://' . $domain;
        }

        return $domain;
    }

    /**
     * @param array<string,mixed> $params
     */
    private function queryParamName(array $params, string $name, string $default): string
    {
        $value = trim((string) ($params[$name] ?? $default));

        return $value !== '' ? $value : $default;
    }

    private function formatDate(string $date, string $format): string
    {
        $dateTime = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$dateTime instanceof \DateTime) {
            return $date;
        }

        return $dateTime->format($format);
    }
}
