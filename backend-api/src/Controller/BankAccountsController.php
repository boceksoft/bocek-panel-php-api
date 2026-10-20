<?php

declare(strict_types=1);

namespace App\Controller;

use PDO;

/*
 * Sitenin banka hesapları (hesaplar, kullanici = 0), sadece okuma.
 *   GET /backend-api/bank-accounts
 * Natsisa rezervasyon bildirim ayarlarında e-postaya eklenecek hesapların seçimi için kullanılır.
 */
final class BankAccountsController extends Controller
{
    /**
     * @Get
     */
    public function index(): void
    {
        $rows = $this->db->pdo()
            ->query('SELECT * FROM hesaplar WHERE kullanici = 0 ORDER BY siralama ASC')
            ->fetchAll(PDO::FETCH_ASSOC);

        $accounts = [];
        foreach ($rows as $row) {
            $accounts[] = [
                'id' => (int) ($row['id'] ?? 0),
                'banka' => trim((string) ($row['banka'] ?? '')),
                'hesapAdi' => trim((string) ($row['hesap_adi'] ?? '')),
                'subeKodu' => trim((string) ($row['sube_kod'] ?? '')),
                'hesapNo' => trim((string) ($row['hesap_no'] ?? '')),
                'ibanTl' => trim((string) ($row['iban_tl'] ?? '')),
                'ibanEuro' => trim((string) ($row['iban_euro'] ?? '')),
                'ibanDolar' => trim((string) ($row['iban_dolar'] ?? '')),
                'ibanPound' => trim((string) ($row['iban_pound'] ?? '')),
                'resim' => trim((string) ($row['resim'] ?? '')),
            ];
        }

        $this->response->success(['hesaplar' => $accounts]);
    }
}
