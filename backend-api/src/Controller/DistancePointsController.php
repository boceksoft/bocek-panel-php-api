<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Support\Uploads;
use PDO;

/*
 * Mesafe cetveli noktalari (dbo.mesafe_cetveli): plaj, market, havalimani gibi yerler ve konumlari.
 *   yertipi -> mesafeler.id (Tanimlamalar > Mesafe Tipleri), bolge -> destinations.id (Bolge yonetimi).
 *   tip: anlami belirsiz eski alan; guncellemede degistirilmez, yeni kayitta tablodaki en yaygin deger yazilir.
 *   GET  /distance-points               liste (+ mesafe tipi ve bolge adlari, secenekler)
 *   POST /distance-points               ekle     { yer, yertipi, bolge, telefon?, enlem?, boylam? }
 *   POST /distance-points/update        guncelle { id, ... }
 *   POST /distance-points/delete        sil      { id }
 *   POST /distance-points/image         resim    { id, image }  uploads/ kokune, resim kolonuna dosya adi
 *   POST /distance-points/image/delete  { id }
 */
final class DistancePointsController extends Controller
{
    /**
     * @Get
     */
    public function index(): void
    {
        $pdo = $this->db->pdo();
        $rows = $pdo->query(
            "SELECT c.id, c.yer, c.yertipi, c.bolge, c.telefon, c.enlem, c.boylam, c.resim,
                    m.baslik AS yertipi_baslik, d.baslik AS bolge_baslik
             FROM dbo.mesafe_cetveli c
             LEFT JOIN dbo.mesafeler m ON m.id = c.yertipi
             LEFT JOIN dbo.destinations d ON d.id = c.bolge
             ORDER BY c.yer ASC, c.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $imageBase = $this->imageBase();
        $items = [];
        foreach ($rows as $row) {
            $resim = $this->imageName((string) ($row['resim'] ?? ''));
            $items[] = [
                'id' => (int) $row['id'],
                'yer' => (string) ($row['yer'] ?? ''),
                'yertipi' => $row['yertipi'] === null ? null : (int) $row['yertipi'],
                'yertipiBaslik' => (string) ($row['yertipi_baslik'] ?? ''),
                'bolge' => $row['bolge'] === null ? null : (int) $row['bolge'],
                'bolgeBaslik' => (string) ($row['bolge_baslik'] ?? ''),
                'telefon' => (string) ($row['telefon'] ?? ''),
                'enlem' => (string) ($row['enlem'] ?? ''),
                'boylam' => (string) ($row['boylam'] ?? ''),
                'resim' => $resim !== '' ? $imageBase . rawurlencode($resim) : '',
                'resimName' => $resim,
            ];
        }

        $types = $pdo->query('SELECT id, baslik FROM dbo.mesafeler ORDER BY ISNULL(siralama, 0) ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
        $regions = $pdo->query('SELECT id, baslik, ISNULL(cat, 0) AS cat FROM dbo.destinations ORDER BY ISNULL(siralama, 0) ASC, baslik ASC')->fetchAll(PDO::FETCH_ASSOC);

        $this->response->success([
            'items' => $items,
            'distanceTypes' => array_map(function (array $r): array {
                return ['id' => (int) $r['id'], 'baslik' => (string) ($r['baslik'] ?? '')];
            }, $types),
            'regions' => array_map(function (array $r): array {
                return ['id' => (int) $r['id'], 'baslik' => (string) ($r['baslik'] ?? ''), 'cat' => (int) $r['cat']];
            }, $regions),
        ]);
    }

    /**
     * @Post
     */
    public function store(): void
    {
        $pdo = $this->db->pdo();
        $values = $this->values($pdo, true);

        // tip: tablodaki en yaygin deger (yoksa NULL).
        $tip = $pdo->query('SELECT TOP 1 tip FROM dbo.mesafe_cetveli WHERE tip IS NOT NULL GROUP BY tip ORDER BY COUNT(*) DESC')->fetchColumn();
        $values['tip'] = $tip === false ? null : (int) $tip;

        $columns = array_keys($values);
        $stmt = $pdo->prepare(
            'INSERT INTO dbo.mesafe_cetveli (' . implode(', ', $columns) . ') OUTPUT INSERTED.id
             VALUES (' . implode(', ', array_map(function ($c) { return ':' . $c; }, $columns)) . ')'
        );
        $params = [];
        foreach ($values as $column => $value) {
            $params[':' . $column] = $value;
        }
        $stmt->execute($params);

        $this->response->success(['message' => 'Mesafe noktasi eklendi', 'id' => (int) $stmt->fetchColumn()]);
    }

    /**
     * @Post("update")
     */
    public function update(): void
    {
        $pdo = $this->db->pdo();
        $id = $this->id();
        $values = $this->values($pdo, false);
        if (!$values) {
            throw new HttpException('Guncellenecek alan yok.', 'VALIDATION', 422);
        }

        $sets = [];
        $params = [':id' => $id];
        foreach ($values as $column => $value) {
            $sets[] = "{$column} = :{$column}";
            $params[':' . $column] = $value;
        }
        $stmt = $pdo->prepare('UPDATE dbo.mesafe_cetveli SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            throw new HttpException('Mesafe noktasi bulunamadi.', 'NOT_FOUND', 404);
        }

        $this->response->success(['message' => 'Mesafe noktasi guncellendi']);
    }

    /**
     * @Post("delete")
     */
    public function delete(): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM dbo.mesafe_cetveli WHERE id = :id');
        $stmt->execute([':id' => $this->id()]);
        if ($stmt->rowCount() === 0) {
            throw new HttpException('Mesafe noktasi bulunamadi.', 'NOT_FOUND', 404);
        }

        $this->response->success(['message' => 'Mesafe noktasi silindi']);
    }

    /**
     * Resmi uploads/ kokune kaydeder, resim kolonuna dosya adini yazar. Yazilamazsa dosya silinir.
     *
     * @Post("image")
     */
    public function image(): void
    {
        $id = $this->id();
        $filename = Uploads::storeImage('image', ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif']);
        $target = Uploads::dir() . DIRECTORY_SEPARATOR . $filename;

        try {
            $stmt = $this->db->pdo()->prepare('UPDATE dbo.mesafe_cetveli SET resim = :resim WHERE id = :id');
            $stmt->execute([':resim' => $filename, ':id' => $id]);
            if ($stmt->rowCount() === 0) {
                throw new HttpException('Mesafe noktasi bulunamadi.', 'NOT_FOUND', 404);
            }
        } catch (\Throwable $e) {
            @unlink($target);
            throw $e;
        }

        $this->response->success(['fileName' => $filename, 'url' => $this->imageBase() . rawurlencode($filename)]);
    }

    /**
     * Resmi kolondan kaldirir; dosyayi baska nokta kullanmiyorsa uploads/ klasorunden siler.
     *
     * @Post("image/delete")
     */
    public function deleteImage(): void
    {
        $pdo = $this->db->pdo();
        $id = $this->id();
        $stmt = $pdo->prepare('SELECT resim FROM dbo.mesafe_cetveli WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            throw new HttpException('Mesafe noktasi bulunamadi.', 'NOT_FOUND', 404);
        }
        $filename = $this->imageName((string) $current);

        $pdo->prepare("UPDATE dbo.mesafe_cetveli SET resim = '' WHERE id = :id")->execute([':id' => $id]);

        $fileDeleted = false;
        if ($filename !== '') {
            $used = $pdo->prepare('SELECT COUNT(*) FROM dbo.mesafe_cetveli WHERE resim = :name');
            $used->execute([':name' => $filename]);
            $path = Uploads::dir() . DIRECTORY_SEPARATOR . $filename;
            if ((int) $used->fetchColumn() === 0 && is_file($path)) {
                $fileDeleted = @unlink($path);
            }
        }

        $this->response->success(['message' => 'Resim silindi', 'fileDeleted' => $fileDeleted]);
    }

    /**
     * Gonderilen alanlari dogrular. $create: yer, yertipi, bolge zorunlu.
     *
     * @return array<string,mixed>
     */
    private function values(PDO $pdo, bool $create): array
    {
        $values = [];
        $input = function (string $key) {
            return $this->request->input($key, null);
        };

        if ($create || $input('yer') !== null) {
            $yer = trim((string) $input('yer'));
            if ($yer === '') {
                throw new HttpException('Yer adi zorunludur.', 'VALIDATION', 422);
            }
            $values['yer'] = $yer;
        }
        if ($create || $input('yertipi') !== null) {
            $yertipi = (int) $input('yertipi');
            if (!$this->exists($pdo, 'mesafeler', $yertipi)) {
                throw new HttpException('Gecerli bir mesafe tipi secin.', 'VALIDATION', 422);
            }
            $values['yertipi'] = $yertipi;
        }
        if ($create || $input('bolge') !== null) {
            $bolge = (int) $input('bolge');
            if (!$this->exists($pdo, 'destinations', $bolge)) {
                throw new HttpException('Gecerli bir bolge secin.', 'VALIDATION', 422);
            }
            $values['bolge'] = $bolge;
        }
        foreach (['telefon', 'enlem', 'boylam'] as $key) {
            if ($create || $input($key) !== null) {
                $values[$key] = trim((string) $input($key));
            }
        }
        foreach (['enlem', 'boylam'] as $key) {
            if (isset($values[$key]) && $values[$key] !== '' && !is_numeric(str_replace(',', '.', $values[$key]))) {
                throw new HttpException(($key === 'enlem' ? 'Enlem' : 'Boylam') . ' sayi olmali.', 'VALIDATION', 422);
            }
            if (isset($values[$key])) {
                $values[$key] = str_replace(',', '.', $values[$key]);
            }
        }

        return $values;
    }

    private function exists(PDO $pdo, string $table, int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM dbo.{$table} WHERE id = :id");
        $stmt->execute([':id' => $id]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function imageBase(): string
    {
        return Uploads::cdnDomain() . '/uploads/';
    }

    private function imageName(string $value): string
    {
        $value = trim(str_replace('\\', '/', $value));
        if ($value === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $value) === 1) {
            return rawurldecode(basename((string) parse_url($value, PHP_URL_PATH)));
        }

        return basename($value);
    }

    private function id(): int
    {
        $id = (int) $this->request->query('id', $this->request->input('id', 0));
        if ($id <= 0) {
            throw new HttpException('Gecersiz ID', 'VALIDATION', 422);
        }

        return $id;
    }
}
