<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Support\LegacySql;
use PDO;

/*
 * Ev sahibi kaynagi (dbo.kullanici, yetki = 2).
 *   POST /backend-api/homeowner -> yeni ev sahibi ekler (ilan duzenleme > Ev Sahibi Ekle)
 *
 * Panel yeni_kullanici_ekle.asp (yetki=2) karsiligi; farklar:
 * - aktif = 1 eklenir (ilan formundaki ev sahibi listesi sadece aktif=1 olanlari gosterir).
 * - Panel girisi verilmez: kullanici_adi bos/NULL, sifre rastgele (bilinmeyen) bir degerdir.
 * - Ayni telefonla kayitli ev sahibi varsa yeni kayit acilmaz, mevcut kayit doner (existing = true).
 */
final class HomeownerController extends Controller
{
    use LegacySql;

    /**
     * Ev sahibi ekler.
     *
     * @Post
     * @body name string Ad soyad (ad/soyad verilmezse buradan bolunur: son kelime soyad)
     * @body ad string Opsiyonel ad
     * @body soyad string Opsiyonel soyad
     * @body tel string Telefon
     * @body email string Opsiyonel e-posta
     * @body adres string Opsiyonel adres
     */
    public function create(): void
    {
        $ad = $this->cleanName((string) $this->inputAny(['ad', 'isim'], ''));
        $soyad = $this->cleanName((string) $this->inputAny(['soyad', 'soyisim'], ''));
        if ($ad === '' && $soyad === '') {
            $split = $this->splitName((string) $this->inputAny(['name', 'ad_soyad', 'adSoyad'], ''));
            $ad = $split['ad'];
            $soyad = $split['soyad'];
        }
        if (!$this->hasLetter($ad . $soyad)) {
            throw new HttpException('Ev sahibi adı harf içermeli.', 'VALIDATION', 422);
        }

        $tel = trim((string) $this->inputAny(['tel', 'phone', 'telefon'], ''));
        $email = trim((string) $this->inputAny(['email', 'eposta'], ''));
        $adres = trim((string) $this->inputAny(['adres', 'address'], ''));

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException('E-posta adresi geçersiz.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();

        // Ayni telefonla kayitli ev sahibi varsa onu don (cift kayit acilmasin).
        $phoneKey = $this->phoneKey($tel);
        if ($phoneKey !== '') {
            $existingId = $this->findOwnerByPhone($pdo, $phoneKey);
            if ($existingId > 0) {
                $this->response->success([
                    'homeowner' => $this->fetchOwner($pdo, $existingId),
                    'existing' => true,
                ]);
                return;
            }
        }

        $columns = $this->kullaniciColumns($pdo);
        $values = [
            'ad' => $ad,
            'soyad' => $soyad,
            'tel' => $tel !== '' ? $tel : null,
            'yetki' => 2,
            'aktif' => 1,
        ];
        if (isset($columns['email'])) {
            $values['email'] = $email !== '' ? $email : null;
        }
        if (isset($columns['adres'])) {
            $values['adres'] = $adres !== '' ? $adres : null;
        }
        if (isset($columns['resim'])) {
            $values['resim'] = 'profile.jpg';
        }
        // Panel girisi yok: kullanici adi NULL (zorunluysa tahmin edilemez bir deger), sifre rastgele.
        if (isset($columns['kullanici_adi']) && !$columns['kullanici_adi']) {
            $values['kullanici_adi'] = 'evsahibi_' . bin2hex(random_bytes(6));
        }
        if (isset($columns['sifre'])) {
            $values['sifre'] = bin2hex(random_bytes(16));
        }

        // NOT NULL kolona NULL yazilmasin: zorunlu metin kolonlarinda bos metin kullanilir.
        foreach ($values as $column => $value) {
            if ($value === null && isset($columns[$column]) && !$columns[$column]) {
                $values[$column] = '';
            }
        }

        // SET NOCOUNT + SCOPE_IDENTITY ile eklenir (OUTPUT INSERTED trigger'li tabloda hata, sqlsrv'de id 0 donebiliyor).
        // Gonderilmeyen NOT NULL + varsayilansiz kolonlar eski panel kayitlari gibi bos deger alir.
        $id = $this->insertRow($pdo, 'kullanici', $values);

        $this->response->success([
            'homeowner' => $this->fetchOwner($pdo, $id),
            'existing' => false,
        ], 201);
    }

    /**
     * dbo.kullanici kolonlari: kolon adi (kucuk harf) -> NULL olabilir mi.
     *
     * @return array<string,bool>
     */
    private function kullaniciColumns(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT LOWER(COLUMN_NAME) AS name, CASE WHEN IS_NULLABLE = 'YES' THEN 1 ELSE 0 END AS nullable
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'kullanici'"
        );
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[(string) $row['name']] = (int) $row['nullable'] === 1;
        }

        return $columns;
    }

    private function findOwnerByPhone(PDO $pdo, string $phoneKey): int
    {
        $stmt = $pdo->query("SELECT id, tel FROM dbo.kullanici WHERE yetki = 2 AND tel IS NOT NULL AND LTRIM(RTRIM(tel)) <> '' ORDER BY id DESC");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($this->phoneKey((string) ($row['tel'] ?? '')) === $phoneKey) {
                return (int) $row['id'];
            }
        }

        return 0;
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchOwner(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM dbo.kullanici WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['id' => $id];
        }

        $name = $this->cleanName(trim((string) ($row['ad'] ?? '')) . ' ' . trim((string) ($row['soyad'] ?? '')));

        // ilan formundaki ev_sahipleri listesiyle ayni alanlar (id, title, tokens, phone) + iletisim
        return [
            'id' => (int) $row['id'],
            'title' => $name,
            'tokens' => $name,
            'name' => $name,
            'ad' => trim((string) ($row['ad'] ?? '')),
            'soyad' => trim((string) ($row['soyad'] ?? '')),
            'phone' => trim((string) ($row['tel'] ?? '')),
            'email' => trim((string) ($row['email'] ?? '')),
            'address' => trim((string) ($row['adres'] ?? '')),
            'aktif' => (int) ($row['aktif'] ?? 0),
        ];
    }

    /** Telefon karsilastirma anahtari: son 10 hane (0, 90, +90, 0090 farki yok sayilir). */
    private function phoneKey(string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);

        return strlen($digits) >= 10 ? substr($digits, -10) : '';
    }

    /**
     * @return array{ad:string,soyad:string}
     */
    private function splitName(string $fullName): array
    {
        $parts = array_values(array_filter(preg_split('/\s+/', $this->cleanName($fullName)) ?: [], static function (string $p): bool {
            return $p !== '';
        }));
        if (count($parts) <= 1) {
            return ['ad' => $parts[0] ?? '', 'soyad' => ''];
        }
        $soyad = (string) array_pop($parts);

        return ['ad' => implode(' ', $parts), 'soyad' => $soyad];
    }

    private function cleanName(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    private function hasLetter(string $value): bool
    {
        return preg_match('/\p{L}/u', $value) === 1;
    }

    /**
     * @param string[] $keys
     * @return mixed
     */
    private function inputAny(array $keys, $default = '')
    {
        foreach ($keys as $key) {
            $value = $this->request->query($key, null);
            if ($value !== null) {
                return $value;
            }
            $value = $this->request->input($key, null);
            if ($value !== null) {
                return $value;
            }
        }

        return $default;
    }
}
