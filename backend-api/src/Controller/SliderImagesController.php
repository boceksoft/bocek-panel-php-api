<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Support\LegacySql;
use App\Support\Uploads;
use PDO;

/*
 * Anasayfa slider resimleri (eski panel resimler.asp?islm=slider karsiligi).
 * Kayitlar upload tablosunda: islm='slider', islm_id=0, site=?site. Dosya uploads/ kokune yazilir.
 *   GET  /backend-api/slider-images?site=1
 *   POST /backend-api/slider-images/upload?site=1   multipart: image
 *   POST /backend-api/slider-images/update?site=1   { uploadId, sira?, aciklama?, aciklama2?, aciklama3?, aciklama4?, url? }
 *   POST /backend-api/slider-images/delete?site=1   { uploadId }   (dosya uploads/ klasorunde birakilir)
 *   POST /backend-api/slider-images/sort?site=1     { order: [uploadId...] }
 * Metin kolonlari kuruluma gore degisebildigi icin sadece tabloda olanlar okunur/yazilir.
 */
final class SliderImagesController extends Controller
{
    use LegacySql;

    private const ISLM = 'slider';

    // Sitedeki slider sadece JPEG gosterebildigi icin diger formatlar kabul edilmez.
    private const IMAGE_TYPES = ['image/jpeg' => 'jpg'];

    // Formdaki alanlar: Resim Aciklamasi, Aciklama 2/3/4 [Varsa], Url [Varsa].
    private const TEXT_FIELDS = ['aciklama', 'aciklama2', 'aciklama3', 'aciklama4', 'url'];

    private const MAX_TEXT_LENGTH = 4000;

    /**
     * @Get
     * @query site int Site kimligi (varsayilan 1)
     */
    public function index(): void
    {
        $pdo = $this->db->pdo();
        $fields = $this->textFields($pdo);
        $select = 'uploadID AS id, FileName AS fileName, sira';
        foreach ($fields as $field) $select .= ', [' . $field . ']';
        $stmt = $pdo->prepare('SELECT ' . $select . ' FROM dbo.upload WHERE islm = :islm AND islm_id = 0 AND site = :site ORDER BY sira, uploadID');
        $this->execute($stmt, ['islm' => self::ISLM, 'site' => $this->siteId()]);

        $images = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $image = ['id' => (int) $row['id'], 'fileName' => (string) ($row['fileName'] ?? ''), 'sira' => (int) ($row['sira'] ?? 0)];
            foreach ($fields as $field) $image[$field] = trim((string) ($row[$field] ?? ''));
            $images[] = $image;
        }

        $this->response->success(['images' => $images, 'fields' => $fields, 'imageBase' => $this->imageBase()]);
    }

    /**
     * Gorseli uploads/ kokune kaydeder ve listenin sonuna ekler. DB'ye yazilamazsa dosya geri silinir.
     *
     * @Post("upload")
     * @query site int Site kimligi (varsayilan 1)
     */
    public function upload(): void
    {
        $pdo = $this->db->pdo();
        $site = $this->siteId();
        $columns = $this->tableColumns($pdo, 'upload');
        // Dosya kaydedilmeden once gercek icerik kontrol edilir (uzantisi .jpg olan webp/png de reddedilir).
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK
            && !isset(self::IMAGE_TYPES[(string) Uploads::imageMime($_FILES['image']['tmp_name'])])) {
            throw new HttpException('Slider icin sadece JPEG (.jpg / .jpeg) resim yuklenebilir.', 'INVALID_FILE_TYPE', 400);
        }
        $filename = Uploads::storeImage('image', self::IMAGE_TYPES);
        $path = Uploads::dir() . DIRECTORY_SEPARATOR . $filename;
        try {
            $stmt = $pdo->prepare('SELECT ISNULL(MAX(sira), 0) FROM dbo.upload WHERE islm = :islm AND islm_id = 0 AND site = :site');
            $this->execute($stmt, ['islm' => self::ISLM, 'site' => $site]);
            $values = [
                'FileName' => $filename, 'FileSize' => (int) @filesize($path), 'islm' => self::ISLM, 'islm_id' => 0,
                'sira' => (int) $stmt->fetchColumn() + 1, 'site' => $site,
            ];
            // Eski kayitlardaki gibi bos metin (NOT NULL olabilir).
            foreach (self::TEXT_FIELDS as $field) {
                if (in_array($field, $columns, true)) $values[$field] = '';
            }
            $raw = in_array('dateofupload', $columns, true) ? ['DateOfUpload' => 'GETDATE()'] : [];
            $id = $this->insertRow($pdo, 'upload', $values, $raw);
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }

        $this->response->success([
            'id' => $id,
            'fileName' => $filename,
            'url' => $this->imageBase() . rawurlencode($filename),
        ], 201);
    }

    /**
     * Sira ve metin alanlarini gunceller; sadece gonderilen alanlar yazilir.
     *
     * @Post("update")
     * @query site int Site kimligi (varsayilan 1)
     */
    public function update(): void
    {
        $uploadId = $this->bodyId('uploadId');
        $input = $this->request->json();
        $pdo = $this->db->pdo();
        $this->requireSlider($pdo, $uploadId);

        $values = [];
        if (array_key_exists('sira', $input)) {
            $number = filter_var($input['sira'], FILTER_VALIDATE_INT);
            if ($number === false || $number < 0 || $number > 2147483647) throw new HttpException('Gecersiz resim sirasi.', 'VALIDATION', 422);
            $values['sira'] = $number;
        }
        foreach ($this->textFields($pdo) as $field) {
            if (!array_key_exists($field, $input)) continue;
            $value = $input[$field];
            if ($value !== null && !is_string($value)) throw new HttpException('Gecersiz metin alani.', 'VALIDATION', 422);
            $value = trim((string) $value);
            if (mb_strlen($value, 'UTF-8') > self::MAX_TEXT_LENGTH) throw new HttpException('Metin alani cok uzun.', 'VALIDATION', 422);
            $values[$field] = $value;
        }
        if ($values === []) throw new HttpException('Guncellenecek alan gereklidir.', 'VALIDATION', 422);

        $sets = array_map(function ($field) { return '[' . $field . '] = :' . $field; }, array_keys($values));
        $this->execute($pdo->prepare('UPDATE dbo.upload SET ' . implode(', ', $sets) . ' WHERE uploadID = :uploadId'), $values + ['uploadId' => $uploadId]);
        $this->response->success(['message' => 'Slider resmi guncellendi']);
    }

    /**
     * Kaydi upload tablosundan siler; dosya uploads/ klasorunde birakilir.
     *
     * @Post("delete")
     * @query site int Site kimligi (varsayilan 1)
     */
    public function delete(): void
    {
        $uploadId = $this->bodyId('uploadId');
        $pdo = $this->db->pdo();
        $this->requireSlider($pdo, $uploadId);
        $this->execute($pdo->prepare('DELETE FROM dbo.upload WHERE uploadID = :uploadId'), ['uploadId' => $uploadId]);
        $this->response->success(['message' => 'Slider resmi silindi']);
    }

    /**
     * Verilen uploadID sirasina gore 1..n sira yazar (surukle/birak).
     *
     * @Post("sort")
     * @query site int Site kimligi (varsayilan 1)
     */
    public function sort(): void
    {
        $order = $this->request->input('order');
        if (!is_array($order) || $order === []) throw new HttpException('Sira listesi gereklidir.', 'VALIDATION', 422);
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('UPDATE dbo.upload SET sira = :sira WHERE uploadID = :uploadId AND islm = :islm AND islm_id = 0 AND site = :site');
        $site = $this->siteId();
        $pdo->beginTransaction();
        try {
            foreach (array_values($order) as $offset => $raw) {
                $this->execute($stmt, ['sira' => $offset + 1, 'uploadId' => $this->validId($raw), 'islm' => self::ISLM, 'site' => $site]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $this->response->success(['message' => 'Slider sirasi guncellendi']);
    }

    /** @return array<int,string> upload tablosunda bulunan metin alanlari (form sirasiyla) */
    private function textFields(PDO $pdo): array
    {
        $columns = $this->tableColumns($pdo, 'upload');
        return array_values(array_filter(self::TEXT_FIELDS, function ($field) use ($columns) {
            return in_array($field, $columns, true);
        }));
    }

    // Sadece bu sitenin slider kaydi duzenlenir/silinir.
    private function requireSlider(PDO $pdo, int $uploadId): void
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM dbo.upload WHERE uploadID = :uploadId AND islm = :islm AND islm_id = 0 AND site = :site');
        $this->execute($stmt, ['uploadId' => $uploadId, 'islm' => self::ISLM, 'site' => $this->siteId()]);
        if ((int) $stmt->fetchColumn() === 0) throw new HttpException('Slider resmi bulunamadi.', 'NOT_FOUND', 404);
    }
}
