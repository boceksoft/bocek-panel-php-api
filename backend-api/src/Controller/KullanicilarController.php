<?php

declare(strict_types=1);

namespace App\Controller;

use PDO;

/*
 * Kullanici listeleri.
 *   GET /backend-api/kullanicilar -> emlak sahipleri ve musterileri listeler.
 */
final class KullanicilarController extends Controller
{
    /**
     * Emlak sahipleri ve musterileri telefonlari normalize ederek listeler.
     *
     * @Get
     * @query veri int 1 emlak sahipleri, 2 musteriler, bos tumu
     */
    public function index(): void
    {
        $pdo = $this->db->pdo();
        $veri = trim((string) $this->request->query('veri', $this->request->input('veri', '')));

        if ($veri === '1') {
            $owners = $this->fetchOwners($pdo);
            $this->response->success([
                'emlak_sahipleri' => $owners,
                'emlak_sahipleri_sayisi' => count($owners),
                'toplam_sayi' => count($owners),
                'meta' => [
                    'veri' => 1,
                    'emlak_sahipleri_count' => count($owners),
                    'total' => count($owners),
                ],
            ]);
            return;
        }

        if ($veri === '2') {
            $customers = $this->fetchCustomers($pdo);
            $this->response->success([
                'musteriler' => $customers,
                'musteriler_sayisi' => count($customers),
                'toplam_sayi' => count($customers),
                'meta' => [
                    'veri' => 2,
                    'musteriler_count' => count($customers),
                    'total' => count($customers),
                ],
            ]);
            return;
        }

        $owners = $this->fetchOwners($pdo);
        $customers = $this->fetchCustomers($pdo);

        $this->response->success([
            'emlak_sahipleri' => $owners,
            'musteriler' => $customers,
            'emlak_sahipleri_sayisi' => count($owners),
            'musteriler_sayisi' => count($customers),
            'toplam_sayi' => count($owners) + count($customers),
            'meta' => [
                'emlak_sahipleri_count' => count($owners),
                'musteriler_count' => count($customers),
                'total' => count($owners) + count($customers),
            ],
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchOwners(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT id, ad, soyad, tel, email
             FROM kullanici
             WHERE yetki = 2
             ORDER BY id DESC"
        );

        $owners = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $phone = $this->normalizePhone((string) ($row['tel'] ?? ''));
            if ($phone === '') {
                continue;
            }

            $owners[] = [
                'id' => (int) ($row['id'] ?? 0),
                'ad' => $this->cleanName((string) ($row['ad'] ?? '')),
                'soyad' => $this->cleanName((string) ($row['soyad'] ?? '')),
                'tel' => $phone,
                'email' => $this->cleanEmail((string) ($row['email'] ?? '')),
            ];
        }

        return $owners;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchCustomers(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT id, musteri, telefon, email
             FROM kayitlar
             WHERE telefon IS NOT NULL
               AND LTRIM(RTRIM(CONVERT(nvarchar(100), telefon))) <> ''
             ORDER BY id DESC"
        );

        $customers = [];
        $seenPhones = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $phone = $this->normalizePhone((string) ($row['telefon'] ?? ''));
            if ($phone === '' || isset($seenPhones[$phone])) {
                continue;
            }

            $nameParts = $this->splitName((string) ($row['musteri'] ?? ''));
            $customers[] = [
                'ad' => $nameParts['ad'],
                'soyad' => $nameParts['soyad'],
                'tel' => $phone,
                'email' => $this->cleanEmail((string) ($row['email'] ?? '')),
            ];
            $seenPhones[$phone] = true;
        }

        return $customers;
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', trim($phone));
        if (!is_string($digits) || $digits === '') {
            return '';
        }

        if (strpos($digits, '00') === 0) {
            $digits = substr($digits, 2);
        }

        if (strpos($digits, '90') !== 0) {
            if (strpos($digits, '0') === 0) {
                $digits = substr($digits, 1);
            }

            $digits = '90' . $digits;
        }

        return strlen($digits) <= 15 ? $digits : '';
    }

    /**
     * @return array{ad:string,soyad:string}
     */
    private function splitName(string $fullName): array
    {
        $fullName = $this->cleanName($fullName);
        if ($fullName === '') {
            return ['ad' => '', 'soyad' => ''];
        }

        $parts = preg_split('/\s+/', $fullName) ?: [];
        $parts = array_values(array_filter($parts, function (string $part): bool {
            return $part !== '';
        }));

        if (count($parts) <= 1) {
            return ['ad' => $parts[0] ?? '', 'soyad' => ''];
        }

        $surname = (string) array_pop($parts);

        return [
            'ad' => implode(' ', $parts),
            'soyad' => $surname,
        ];
    }

    private function cleanName(string $name): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $name));
    }

    private function cleanEmail(string $email): string
    {
        return trim($email);
    }
}
