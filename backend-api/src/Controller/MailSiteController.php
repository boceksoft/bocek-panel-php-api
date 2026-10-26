<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\LegacySql;
use PDO;

/*
 * Sitenin e-posta sabitleri (genel{dbtable}), sadece okuma.
 *   GET /backend-api/mail-site?site=1
 * Natsisa e-posta şablonu önizlemesinde {{site_*}} değişkenlerini workspace'in gerçek değerleriyle doldurmak için.
 * Değerler reservationdetail mailBilgileri.site ile aynı kurallarla üretilir (domain: web. -> www., cdn: cdn.<alan adı>/).
 */
final class MailSiteController extends Controller
{
    use LegacySql;

    /**
     * @Get
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

        $this->response->success([
            'site' => [
                'id' => $siteId,
                'adi' => $g('siteadi'),
                'domain' => str_replace('web.', 'www.', $domain),
                'cdn' => $this->cdn($domain),
                'telefon' => $g('telefon'),
                'gsm' => $g('gsm'),
                'whatsapp' => $g('whatsapp'),
                'mail' => $g('sitemail'),
                'instagram' => $g('instagram'),
                'facebook' => $g('facebook'),
                'youtube' => $g('youtube'),
                'twitter' => $g('twitter'),
                'adres' => $g('adres'),
                'unvan' => $g('unvan'),
            ],
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

    /** "https://web.site.com" / "https://www.site.com" / "https://site.com" -> "https://cdn.site.com/" (reservationdetail ile aynı). */
    private function cdn(string $domain): string
    {
        $domain = trim($domain);
        if ($domain === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $domain) !== 1) {
            $domain = 'https://' . $domain;
        }
        $parts = parse_url($domain);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if ($host === '') {
            return '';
        }
        if (strpos($host, 'cdn.') !== 0) {
            $host = 'cdn.' . preg_replace('/^(www|web)\./', '', $host);
        }

        return ($parts['scheme'] ?? 'https') . '://' . $host . '/';
    }
}
