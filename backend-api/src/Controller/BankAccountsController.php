<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Support\LegacySql;
use App\Support\Uploads;
use PDO;

/*
 * Sitenin banka hesaplari (hesaplar, kullanici = 0; eski panel hesaplar.asp / hesap_duzenle.asp karsiligi).
 * kullanici > 0 satirlar ev sahibi hesaplaridir; bu controller onlara dokunmaz.
 *   GET  /backend-api/bank-accounts                  -> hesaplar (rezervasyon bildirim ayarlari da kullanir)
 *   POST /backend-api/bank-accounts                  { banka, hesap_adi?, sube?, sube_kod?, hesap_no?, swift?, iban_tl?, iban_euro?, iban_dolar?, iban_pound? }
 *   POST /backend-api/bank-accounts/update           { id, ...ayni alanlar }
 *   POST /backend-api/bank-accounts/delete           { id }
 *   POST /backend-api/bank-accounts/sort             { ids[] }  -> verilen sira siralama 1..n
 *   POST /backend-api/bank-accounts/logo?id=         multipart resim (jpg/png/webp/gif)
 * Eski panelden farki: IBAN'lar bosluksuz/buyuk harf kaydedilir ve dogrulanir, siralama tek tek input yerine
 * liste sirasiyla kaydedilir. Eski logo dosyalari silinmez (panel siliyordu; baska yerde kullaniliyor olabilir).
 */
final class BankAccountsController extends Controller
{
    use LegacySql;

    private const LOGO_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    // alan => en fazla uzunluk. Kolonu olmayan alan (eski kurulumlar) okunmaz/yazilmaz.
    private const TEXT_FIELDS = [
        'banka' => 150, 'hesap_adi' => 200, 'sube' => 150, 'sube_kod' => 50, 'hesap_no' => 50, 'swift' => 20,
    ];
    private const IBAN_FIELDS = ['iban_tl', 'iban_euro', 'iban_dolar', 'iban_pound'];

    /**
     * @Get
     */
    public function index(): void
    {
        $pdo = $this->db->pdo();
        $columns = $this->tableColumns($pdo, 'hesaplar');
        $rows = $pdo->query('SELECT * FROM hesaplar WHERE kullanici = 0 ORDER BY siralama ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
        $logoBase = Uploads::cdnDomain() . '/uploads/';

        $accounts = [];
        foreach ($rows as $row) {
            $logo = trim((string) ($row['resim'] ?? ''));
            $accounts[] = [
                'id' => (int) ($row['id'] ?? 0),
                'banka' => trim((string) ($row['banka'] ?? '')),
                'hesapAdi' => trim((string) ($row['hesap_adi'] ?? '')),
                'sube' => trim((string) ($row['sube'] ?? '')),
                'subeKodu' => trim((string) ($row['sube_kod'] ?? '')),
                'hesapNo' => trim((string) ($row['hesap_no'] ?? '')),
                'swift' => trim((string) ($row['swift'] ?? '')),
                'ibanTl' => trim((string) ($row['iban_tl'] ?? '')),
                'ibanEuro' => trim((string) ($row['iban_euro'] ?? '')),
                'ibanDolar' => trim((string) ($row['iban_dolar'] ?? '')),
                'ibanPound' => trim((string) ($row['iban_pound'] ?? '')),
                'siralama' => (int) ($row['siralama'] ?? 0),
                'resim' => $logo,
                'logoUrl' => $logo !== '' ? $logoBase . rawurlencode($logo) : '',
            ];
        }

        // fields: bu kurulumda olan alanlar (form sadece bunlari gosterir).
        $fields = array_values(array_filter(array_merge(array_keys(self::TEXT_FIELDS), self::IBAN_FIELDS), function ($field) use ($columns) {
            return in_array($field, $columns, true);
        }));

        $this->response->success(['hesaplar' => $accounts, 'fields' => $fields]);
    }

    /** @Post */
    public function store(): void
    {
        $pdo = $this->db->pdo();
        $values = $this->values($pdo, true);

        $pdo->beginTransaction();
        try {
            $max = (int) $pdo->query('SELECT ISNULL(MAX(siralama), 0) FROM hesaplar WITH (UPDLOCK, HOLDLOCK) WHERE kullanici = 0')->fetchColumn();
            $id = $this->insertRow($pdo, 'hesaplar', $values + ['kullanici' => 0, 'siralama' => $max + 1]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $this->response->success(['message' => 'Banka hesabi eklendi', 'id' => $id], 201);
    }

    /** @Post("update") */
    public function update(): void
    {
        $id = $this->bodyId('id');
        $pdo = $this->db->pdo();
        $values = $this->values($pdo, false);
        if ($values === []) throw new HttpException('Guncellenecek alan gereklidir.', 'VALIDATION', 422);

        $sets = array_map(function ($field) { return '[' . $field . '] = :' . $field; }, array_keys($values));
        $stmt = $pdo->prepare('UPDATE hesaplar SET ' . implode(', ', $sets) . ' WHERE id = :id AND kullanici = 0');
        $this->execute($stmt, $values + ['id' => $id]);
        if ($stmt->rowCount() === 0) throw new HttpException('Banka hesabi bulunamadi.', 'NOT_FOUND', 404);

        $this->response->success(['message' => 'Banka hesabi guncellendi']);
    }

    /** @Post("delete") */
    public function delete(): void
    {
        $id = $this->bodyId('id');
        $pdo = $this->db->pdo();

        $pdo->beginTransaction();
        try {
            $this->requireAccount($pdo, $id);
            $this->execute($pdo->prepare('DELETE FROM hesaplar WHERE id = :id AND kullanici = 0'), ['id' => $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $this->response->success(['message' => 'Banka hesabi silindi']);
    }

    // Gelen sira sitenin tum hesaplari olmali; baska ekranda degistiyse siralama yapilmaz.
    /** @Post("sort") */
    public function sort(): void
    {
        $raw = $this->request->input('ids');
        if (!is_array($raw) || $raw === []) throw new HttpException('Siralama listesi gereklidir.', 'VALIDATION', 422);
        $ids = array_values(array_unique(array_map(function ($value) { return $this->validId($value); }, $raw)));
        $pdo = $this->db->pdo();

        $pdo->beginTransaction();
        try {
            $current = array_map('intval', $pdo->query('SELECT id FROM hesaplar WITH (UPDLOCK, HOLDLOCK) WHERE kullanici = 0')->fetchAll(PDO::FETCH_COLUMN));
            $given = $ids;
            sort($current);
            sort($given);
            if ($current !== $given) throw new HttpException('Hesap listesi degismis; sayfayi yenileyip tekrar siralayin.', 'LIST_CHANGED', 409);

            $stmt = $pdo->prepare('UPDATE hesaplar SET siralama = :siralama WHERE id = :id AND kullanici = 0');
            foreach ($ids as $index => $id) $this->execute($stmt, ['siralama' => $index + 1, 'id' => $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $this->response->success(['message' => 'Siralama kaydedildi']);
    }

    /**
     * Logo yukler/degistirir. Kayit olmazsa yeni dosya silinir.
     *
     * @Post("logo")
     */
    public function logo(): void
    {
        $id = $this->queryId('id');
        $pdo = $this->db->pdo();
        $this->requireAccount($pdo, $id);

        $filename = Uploads::storeImage('resim', self::LOGO_TYPES);
        try {
            $this->execute($pdo->prepare('UPDATE hesaplar SET resim = :resim WHERE id = :id AND kullanici = 0'), ['resim' => $filename, 'id' => $id]);
        } catch (\Throwable $e) {
            @unlink(Uploads::dir() . DIRECTORY_SEPARATOR . $filename);
            throw $e;
        }
        $this->response->success([
            'message' => 'Logo yuklendi',
            'resim' => $filename,
            'logoUrl' => Uploads::cdnDomain() . '/uploads/' . rawurlencode($filename),
        ], 201);
    }

    /**
     * Govdedeki alanlari dogrular. Eklemede banka zorunlu; guncellemede sadece gonderilen alanlar yazilir.
     *
     * @return array<string,string>
     */
    private function values(PDO $pdo, bool $isNew): array
    {
        $columns = $this->tableColumns($pdo, 'hesaplar');
        $input = $this->request->json();
        $values = [];

        foreach (self::TEXT_FIELDS as $field => $max) {
            if (!in_array($field, $columns, true) || !array_key_exists($field, $input)) continue;
            $value = trim((string) preg_replace('/\s+/u', ' ', (string) ($input[$field] ?? '')));
            if (mb_strlen($value, 'UTF-8') > $max) throw new HttpException($field . ' en fazla ' . $max . ' karakter olabilir.', 'VALIDATION', 422);
            $values[$field] = $field === 'swift' ? strtoupper(str_replace(' ', '', $value)) : $value;
        }
        foreach (self::IBAN_FIELDS as $field) {
            if (!in_array($field, $columns, true) || !array_key_exists($field, $input)) continue;
            $values[$field] = $this->iban((string) ($input[$field] ?? ''), $field);
        }

        if (($isNew || array_key_exists('banka', $values)) && ($values['banka'] ?? '') === '') {
            throw new HttpException('Banka adi gereklidir.', 'VALIDATION', 422);
        }

        return $values;
    }

    // Bosluksuz, buyuk harf; ulke kodu + kontrol basamagi + mod 97 dogrulamasi. Bos birakilabilir.
    private function iban(string $value, string $field): string
    {
        $iban = strtoupper((string) preg_replace('/[\s-]+/', '', $value));
        if ($iban === '') return '';
        $label = 'IBAN ' . (['iban_tl' => 'TL', 'iban_euro' => 'EUR', 'iban_dolar' => 'USD', 'iban_pound' => 'GBP'][$field] ?? $field);
        if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
            throw new HttpException($label . ' geçersiz.', 'VALIDATION', 422);
        }
        // Ilk 4 karakter sona alinir, harfler sayiya (A=10) cevrilir, parca parca mod 97 alinir; sonuc 1 olmali.
        $digits = '';
        foreach (str_split(substr($iban, 4) . substr($iban, 0, 4)) as $char) {
            $digits .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }
        $remainder = 0;
        foreach (str_split($digits, 7) as $chunk) $remainder = (int) (($remainder . $chunk) % 97);
        if ($remainder !== 1) throw new HttpException($label . ' geçersiz.', 'VALIDATION', 422);

        return $iban;
    }

    /** @return string hesabin logo dosya adi */
    private function requireAccount(PDO $pdo, int $id): string
    {
        $stmt = $pdo->prepare('SELECT resim FROM hesaplar WITH (UPDLOCK, HOLDLOCK) WHERE id = :id AND kullanici = 0');
        $this->execute($stmt, ['id' => $id]);
        $logo = $stmt->fetchColumn();
        if ($logo === false) throw new HttpException('Banka hesabi bulunamadi.', 'NOT_FOUND', 404);
        return trim((string) $logo);
    }
}
