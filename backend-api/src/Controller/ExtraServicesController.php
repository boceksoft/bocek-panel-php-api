<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Ekstra servis tipleri (dbo.HomesExtraPaymentTypes). Ilan detayindaki ekstra ucretler bu tiplere baglidir
 * (HomesExtraPaymentPrices.ExtraPaymentTypeId). Silme yumusaktir: IsDeleted = 1.
 *   GET  /extra-services            liste (silinmemisler)
 *   POST /extra-services            ekle     { name, code?, isIncluded? }   code bos ise addan uretilir
 *   POST /extra-services/update     guncelle { id, name?, isIncluded? }
 *   POST /extra-services/delete     sil      { id }  (sabit tipler ve fiyati olan tipler silinmez)
 */
final class ExtraServicesController extends Controller
{
    /**
     * @Get
     */
    public function index(): void
    {
        $pdo = $this->db->pdo();
        $this->assertTable($pdo);

        $rows = $pdo->query(
            "SELECT t.ExtraPaymentTypeId, t.Code, t.Name, t.HomeColumnBase, CAST(t.IsIncluded AS int) AS IsIncluded,
                    (SELECT COUNT(DISTINCT p.HomesId) FROM dbo.HomesExtraPaymentPrices p
                      WHERE p.ExtraPaymentTypeId = t.ExtraPaymentTypeId AND p.IsDeleted = 0) AS HomeCount
             FROM dbo.HomesExtraPaymentTypes t
             WHERE t.IsDeleted = 0
             ORDER BY t.ExtraPaymentTypeId ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $items = array_map(function (array $row): array {
            return [
                'id' => (int) $row['ExtraPaymentTypeId'],
                'code' => (string) ($row['Code'] ?? ''),
                'name' => (string) ($row['Name'] ?? ''),
                'homeColumnBase' => (string) ($row['HomeColumnBase'] ?? ''),
                'isIncluded' => (bool) (int) ($row['IsIncluded'] ?? 0),
                'homeCount' => (int) ($row['HomeCount'] ?? 0),
                // homes kolonuna bagli sabit tipler (hasar / temizlik / elektrik) silinemez.
                'isSystem' => trim((string) ($row['HomeColumnBase'] ?? '')) !== '',
            ];
        }, $rows);

        $this->response->success(['items' => $items]);
    }

    /**
     * @Post
     */
    public function store(): void
    {
        $pdo = $this->db->pdo();
        $this->assertTable($pdo);
        $name = $this->name();
        $code = $this->code((string) $this->request->input('code', ''), $name);

        $exists = $pdo->prepare('SELECT COUNT(*) FROM dbo.HomesExtraPaymentTypes WHERE Code = :code');
        $exists->execute([':code' => $code]);
        if ((int) $exists->fetchColumn() > 0) {
            throw new HttpException("Bu kod zaten kullaniliyor: {$code}", 'VALIDATION', 422);
        }

        // Yeni tipler homes kolonuna bagli degildir (HomeColumnBase bos).
        $stmt = $pdo->prepare(
            "INSERT INTO dbo.HomesExtraPaymentTypes (Code, Name, HomeColumnBase, IsDeleted, IsIncluded)
             OUTPUT INSERTED.ExtraPaymentTypeId
             VALUES (:code, :name, '', 0, :included)"
        );
        $stmt->execute([':code' => $code, ':name' => $name, ':included' => $this->flag($this->request->input('isIncluded', false))]);

        $this->response->success(['message' => 'Ekstra servis eklendi', 'id' => (int) $stmt->fetchColumn(), 'code' => $code]);
    }

    /**
     * Kod degistirilmez (ucret kayitlari ve eski entegrasyonlar koda bakabilir).
     *
     * @Post("update")
     */
    public function update(): void
    {
        $pdo = $this->db->pdo();
        $this->assertTable($pdo);
        $id = $this->id();
        $sets = [];
        $params = [':id' => $id];

        if ($this->request->input('name', null) !== null) {
            $sets[] = 'Name = :name';
            $params[':name'] = $this->name();
        }
        $included = $this->request->input('isIncluded', null);
        if ($included !== null) {
            $sets[] = 'IsIncluded = :included';
            $params[':included'] = $this->flag($included);
        }
        if (!$sets) {
            throw new HttpException('Guncellenecek alan yok.', 'VALIDATION', 422);
        }

        $stmt = $pdo->prepare('UPDATE dbo.HomesExtraPaymentTypes SET ' . implode(', ', $sets) . ' WHERE ExtraPaymentTypeId = :id AND IsDeleted = 0');
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            throw new HttpException('Ekstra servis bulunamadi.', 'NOT_FOUND', 404);
        }

        $this->response->success(['message' => 'Ekstra servis guncellendi']);
    }

    /**
     * Yumusak silme (IsDeleted = 1). Ilanlarda fiyati olan ya da homes kolonuna bagli sabit tipler silinmez.
     *
     * @Post("delete")
     */
    public function delete(): void
    {
        $pdo = $this->db->pdo();
        $this->assertTable($pdo);
        $id = $this->id();

        $stmt = $pdo->prepare('SELECT HomeColumnBase FROM dbo.HomesExtraPaymentTypes WHERE ExtraPaymentTypeId = :id AND IsDeleted = 0');
        $stmt->execute([':id' => $id]);
        $base = $stmt->fetchColumn();
        if ($base === false) {
            throw new HttpException('Ekstra servis bulunamadi.', 'NOT_FOUND', 404);
        }
        if (trim((string) $base) !== '') {
            throw new HttpException('Bu sabit bir ucret tipi (ilan kolonuna bagli); silinemez.', 'IN_USE', 422);
        }

        $used = $pdo->prepare('SELECT COUNT(DISTINCT HomesId) FROM dbo.HomesExtraPaymentPrices WHERE ExtraPaymentTypeId = :id AND IsDeleted = 0');
        $used->execute([':id' => $id]);
        $count = (int) $used->fetchColumn();
        if ($count > 0) {
            throw new HttpException("Bu ekstra servisin {$count} ilanda fiyati var; once ilanlardan kaldirin.", 'IN_USE', 422);
        }

        $pdo->prepare('UPDATE dbo.HomesExtraPaymentTypes SET IsDeleted = 1 WHERE ExtraPaymentTypeId = :id')->execute([':id' => $id]);

        $this->response->success(['message' => 'Ekstra servis silindi']);
    }

    private function assertTable(PDO $pdo): void
    {
        $stmt = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'HomesExtraPaymentTypes'");
        if ((int) $stmt->fetchColumn() === 0) {
            throw new HttpException('HomesExtraPaymentTypes tablosu yok (setup calistirilmamis).', 'SETUP_REQUIRED', 409);
        }
    }

    // Kod: kucuk harf, ascii, alt cizgi; bos ise addan uretilir. En fazla 64 karakter.
    private function code(string $code, string $name): string
    {
        $source = trim($code) !== '' ? $code : $name;
        $map = ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', 'Ç' => 'c', 'Ğ' => 'g', 'İ' => 'i', 'Ö' => 'o', 'Ş' => 's', 'Ü' => 'u'];
        $value = strtolower(strtr($source, $map));
        $value = trim((string) preg_replace('/[^a-z0-9]+/', '_', $value), '_');
        if ($value === '') {
            throw new HttpException('Gecerli bir kod uretilemedi.', 'VALIDATION', 422);
        }

        return substr($value, 0, 64);
    }

    /**
     * @param mixed $value
     */
    private function flag($value): int
    {
        return ($value === true || $value === 1 || $value === '1' || $value === 'true') ? 1 : 0;
    }

    private function id(): int
    {
        $id = (int) $this->request->query('id', $this->request->input('id', 0));
        if ($id <= 0) {
            throw new HttpException('Gecersiz ID', 'VALIDATION', 422);
        }

        return $id;
    }

    private function name(): string
    {
        $name = trim((string) $this->request->input('name', ''));
        if ($name === '') {
            throw new HttpException('Ad zorunludur.', 'VALIDATION', 422);
        }
        if (mb_strlen($name) > 255) {
            throw new HttpException('Ad en fazla 255 karakter olabilir.', 'VALIDATION', 422);
        }

        return $name;
    }
}
