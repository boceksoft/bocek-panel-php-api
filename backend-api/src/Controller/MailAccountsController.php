<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\LegacySql;
use PDO;

/*
 * Sitelerin mail gönderim hesapları (panel inc/Functions.asp mailgonder: genel{dbtable}.sitemail / sitemailsifre /
 * smtp, port, ssl), sadece okuma.
 *   GET /backend-api/mail-accounts
 * Natsisa "Villa Kiralama sitesinden içe aktar" için kullanır. ŞİFRE DÖNER: yalnızca Natsisa sunucusu çağırır
 * (Bearer token + allowed_ips); Natsisa şifreyi tarayıcıya iletmez. Panelde IMAP bilgisi yoktur.
 */
final class MailAccountsController extends Controller
{
    use LegacySql;

    /**
     * @Get
     */
    public function index(): void
    {
        $pdo = $this->db->pdo();
        $accounts = [];

        foreach ($this->sites($pdo) as $site) {
            try {
                $row = $pdo->query('SELECT TOP 1 * FROM genel' . $site['dbtable'])->fetch(PDO::FETCH_ASSOC);
            } catch (\PDOException $e) {
                continue;
            }
            if (!is_array($row)) {
                continue;
            }
            $row = array_change_key_case($row, CASE_LOWER);
            $value = function (string $key) use ($row): string {
                return isset($row[$key]) ? trim((string) $row[$key]) : '';
            };

            $email = $value('sitemail');
            if ($email === '') {
                continue;
            }
            $port = $value('port');
            $ssl = $value('ssl');

            $accounts[] = [
                'siteId' => $site['id'],
                'site' => $site['site'],
                'siteAdi' => $value('siteadi'),
                'email' => $email,
                'password' => $value('sitemailsifre'),
                'smtp' => $value('smtp'),
                // Panel: port boşsa Persits varsayılanı; ssl "1" / "0" / boş.
                'port' => is_numeric($port) ? (int) $port : null,
                'ssl' => $ssl === '' ? null : ($ssl === '1' || strtolower($ssl) === 'true'),
            ];
        }

        $this->response->success(['hesaplar' => $accounts]);
    }
}
