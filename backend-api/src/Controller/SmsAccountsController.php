<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\LegacySql;
use PDO;

/*
 * Sitelerin SMS hesapları (panel inc/Functions.asp smsgonder: genel{dbtable}.smsSystem / smska / smspwd / smsorg),
 * sadece okuma.
 *   GET /backend-api/sms-accounts
 * Natsisa "Villa Kiralama sitesinden içe aktar" (NetGSM) için kullanır. ŞİFRE DÖNER: yalnızca Natsisa sunucusu çağırır
 * (Bearer token + allowed_ips); Natsisa şifreyi tarayıcıya iletmez.
 * smsSystem: 1 Netgsm, 2 Mutlucell (panel genel_ayarlar.asp). Kolon yoksa Netgsm sayılır.
 */
final class SmsAccountsController extends Controller
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

            $userName = $value('smska');
            if ($userName === '') {
                continue;
            }
            $system = $value('smssystem');

            $accounts[] = [
                'siteId' => $site['id'],
                'site' => $site['site'],
                'siteAdi' => $value('siteadi'),
                'provider' => $system === '2' ? 'mutlucell' : 'netgsm',
                'userName' => $userName,
                'password' => $value('smspwd'),
                'header' => $value('smsorg'),
                // NetGSM kullanıcı kodu genelde hesabın telefon numarası; değilse sitenin telefonu öneri olarak.
                'telefon' => $value('telefon'),
            ];
        }

        $this->response->success(['hesaplar' => $accounts]);
    }
}
