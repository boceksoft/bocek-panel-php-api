<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Support\LegacySql;
use PDO;

/*
 * Promosyon kodlari (eski panel promotionCodes.asp karsiligi).
 * Tablo promotionCodes: id, code, startDate, endDate, stock, value, isPrice (1 = TL, 0 = yuzde).
 * Kullanim (usedCount) kayitlar.promotionCode ile, sadece onayli (kayitlar.kayit_durum = 3) rezervasyonlardan
 * sayilir; reservationCount kodla yapilan tum rezervasyonlardir. Sitede gecerlilik CalculateController::promotionCode ile ayni:
 * GETDATE() BETWEEN startDate AND endDate AND stock > 0.
 *   GET  /backend-api/promotion-codes
 *   POST /backend-api/promotion-codes          { code, startDate, endDate, value, isPrice, stock }
 *   POST /backend-api/promotion-codes/update   { id, ...ayni alanlar }  (sadece gonderilenler yazilir)
 *   POST /backend-api/promotion-codes/delete   { id }
 * Eski panelden farki: ayni kod iki kez eklenmez, bitis tarihi o gunun sonuna (23:59:59) yazilir ki son gun de
 * gecerli olsun, yuzde indirim 100'u gecemez ve rezervasyonda kullanilmis kodun adi degistirilemez
 * (kayitlar.promotionCode metin olarak tuttugu icin kullanim sayisi kopardi).
 */
final class PromotionCodesController extends Controller
{
    use LegacySql;

    // Panel formundaki sinir (maxlength=10); eski uzun kodlar duzenlenebilir ama yeni kod 10 karakteri gecemez.
    private const MAX_CODE_LENGTH = 10;

    /** @Get */
    public function index(): void
    {
        $pdo = $this->db->pdo();
        $this->requireTable($pdo);
        $rows = $pdo->query(
            "SELECT p.id, p.code,
                    CONVERT(varchar(10), p.startDate, 120) AS startDate,
                    CONVERT(varchar(10), p.endDate, 120) AS endDate,
                    p.stock, p.value, p.isPrice,
                    CASE WHEN GETDATE() BETWEEN p.startDate AND p.endDate AND p.stock > 0 THEN 1 ELSE 0 END AS isValid,
                    ISNULL(u.usedCount, 0) AS usedCount,
                    ISNULL(u.reservationCount, 0) AS reservationCount
             FROM dbo.promotionCodes p
             OUTER APPLY (
                 -- Kullanim sadece onayli (kayitlar.kayit_durum = 3) rezervasyonlar; reservationCount tum durumlar.
                 SELECT SUM(CASE WHEN k.kayit_durum = 3 THEN 1 ELSE 0 END) AS usedCount, COUNT(*) AS reservationCount
                 FROM dbo.kayitlar k
                 WHERE k.promotionCode = p.code
             ) u
             ORDER BY p.startDate DESC, p.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $codes = array_map(function (array $row) {
            return [
                'id' => (int) $row['id'],
                'code' => trim((string) ($row['code'] ?? '')),
                'startDate' => (string) ($row['startDate'] ?? ''),
                'endDate' => (string) ($row['endDate'] ?? ''),
                'stock' => (int) ($row['stock'] ?? 0),
                'value' => (float) ($row['value'] ?? 0),
                'isPrice' => !empty($row['isPrice']),
                'isValid' => (int) $row['isValid'] === 1,
                'usedCount' => (int) $row['usedCount'],
                'reservationCount' => (int) $row['reservationCount'],
            ];
        }, $rows);

        $this->response->success(['codes' => $codes, 'siteDomain' => $this->siteDomain($pdo)]);
    }

    /** @Post */
    public function store(): void
    {
        $input = $this->request->json();
        foreach (['code', 'startDate', 'endDate', 'value'] as $required) {
            if (!array_key_exists($required, $input)) throw new HttpException('Kod, tarih araligi ve indirim gereklidir.', 'VALIDATION', 422);
        }
        $pdo = $this->db->pdo();
        $this->requireTable($pdo);
        $values = $this->values($pdo, $input, null);
        $values += ['stock' => 0, 'isPrice' => 0];

        $pdo->beginTransaction();
        try {
            $this->assertUniqueCode($pdo, $values['code'], 0);
            $id = $this->insertRow($pdo, 'promotionCodes', $values);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $this->response->success(['message' => 'Promosyon kodu eklendi', 'id' => $id], 201);
    }

    /** @Post("update") */
    public function update(): void
    {
        $id = $this->bodyId('id');
        $input = $this->request->json();
        $pdo = $this->db->pdo();
        $this->requireTable($pdo);

        $pdo->beginTransaction();
        try {
            $current = $this->requireCode($pdo, $id);
            $values = $this->values($pdo, $input, $current);
            if ($values === []) throw new HttpException('Guncellenecek alan gereklidir.', 'VALIDATION', 422);
            if (isset($values['code']) && $values['code'] !== $current['code']) {
                if ($this->usedCount($pdo, $current['code']) > 0) {
                    throw new HttpException('Bu kod rezervasyonlarda kullanildigi icin adi degistirilemez.', 'CODE_IN_USE', 409);
                }
                $this->assertUniqueCode($pdo, $values['code'], $id);
            } else {
                unset($values['code']);
            }
            if ($values !== []) {
                $sets = array_map(function ($field) { return '[' . $field . '] = :' . $field; }, array_keys($values));
                $this->execute($pdo->prepare('UPDATE dbo.promotionCodes SET ' . implode(', ', $sets) . ' WHERE id = :id'), $values + ['id' => $id]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $this->response->success(['message' => 'Promosyon kodu guncellendi']);
    }

    /**
     * Kodu siler (eski paneldeki gibi). Rezervasyonlardaki kod metni korunur; sadece kod artik kullanilamaz.
     *
     * @Post("delete")
     */
    public function delete(): void
    {
        $id = $this->bodyId('id');
        $pdo = $this->db->pdo();
        $this->requireTable($pdo);
        $stmt = $pdo->prepare('DELETE FROM dbo.promotionCodes WHERE id = :id');
        $this->execute($stmt, ['id' => $id]);
        if ($stmt->rowCount() === 0) throw new HttpException('Promosyon kodu bulunamadi.', 'NOT_FOUND', 404);
        $this->response->success(['message' => 'Promosyon kodu silindi']);
    }

    private function requireTable(PDO $pdo): void
    {
        if ($this->tableColumns($pdo, 'promotionCodes') === []) {
            throw new HttpException('Bu kurulumda promosyon kodu tablosu (promotionCodes) yok.', 'TABLE_NOT_FOUND', 404);
        }
    }

    /** @return array{id:int,code:string,startDate:string,endDate:string,isPrice:bool} */
    private function requireCode(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT id, code, CONVERT(varchar(10), startDate, 120) AS startDate, CONVERT(varchar(10), endDate, 120) AS endDate, isPrice
            FROM dbo.promotionCodes WITH (UPDLOCK, HOLDLOCK) WHERE id = :id');
        $this->execute($stmt, ['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new HttpException('Promosyon kodu bulunamadi.', 'NOT_FOUND', 404);
        return [
            'id' => (int) $row['id'],
            'code' => trim((string) ($row['code'] ?? '')),
            'startDate' => (string) ($row['startDate'] ?? ''),
            'endDate' => (string) ($row['endDate'] ?? ''),
            'isPrice' => !empty($row['isPrice']),
        ];
    }

    private function usedCount(PDO $pdo, string $code): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM dbo.kayitlar WHERE promotionCode = :code');
        $this->execute($stmt, ['code' => $code]);
        return (int) $stmt->fetchColumn();
    }

    // Buyuk/kucuk harf farki ayni kod sayilir (sitede kod elle yazilir).
    private function assertUniqueCode(PDO $pdo, string $code, int $exceptId): void
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM dbo.promotionCodes WITH (UPDLOCK, HOLDLOCK) WHERE LOWER(LTRIM(RTRIM(code))) = LOWER(:code) AND id <> :id');
        $this->execute($stmt, ['code' => $code, 'id' => $exceptId]);
        if ((int) $stmt->fetchColumn() > 0) throw new HttpException('Bu promosyon kodu zaten var.', 'DUPLICATE_CODE', 409);
    }

    /**
     * @param array<string,mixed> $input
     * @param array{code:string,startDate:string,endDate:string,isPrice:bool}|null $current
     * @return array<string,mixed>
     */
    private function values(PDO $pdo, array $input, ?array $current): array
    {
        $values = [];
        if (array_key_exists('code', $input)) {
            $code = is_string($input['code']) ? trim($input['code']) : '';
            if ($code === '') throw new HttpException('Kod gereklidir.', 'VALIDATION', 422);
            if (preg_match('/^[A-Za-z0-9._-]+$/', $code) !== 1) throw new HttpException('Kod sadece harf, rakam, nokta, tire ve alt cizgi icerebilir.', 'VALIDATION', 422);
            $changed = $current === null || $code !== $current['code'];
            if ($changed && strlen($code) > self::MAX_CODE_LENGTH) {
                throw new HttpException('Kod en fazla ' . self::MAX_CODE_LENGTH . ' karakter olabilir.', 'VALIDATION', 422);
            }
            $values['code'] = $code;
        }

        $start = array_key_exists('startDate', $input) ? $this->date($input['startDate'], 'Baslangic tarihi') : ($current['startDate'] ?? null);
        $end = array_key_exists('endDate', $input) ? $this->date($input['endDate'], 'Bitis tarihi') : ($current['endDate'] ?? null);
        if ($start !== null && $end !== null && $end < $start) throw new HttpException('Bitis tarihi baslangic tarihinden once olamaz.', 'VALIDATION', 422);
        if (array_key_exists('startDate', $input)) $values['startDate'] = $start . 'T00:00:00';
        if (array_key_exists('endDate', $input)) $values['endDate'] = $end . ($this->endDateHasTime($pdo) ? 'T23:59:59' : 'T00:00:00');

        if (array_key_exists('isPrice', $input)) {
            $isPrice = is_bool($input['isPrice']) ? (int) $input['isPrice'] : $input['isPrice'];
            if (!in_array($isPrice, [0, 1, '0', '1'], true)) throw new HttpException('Gecersiz indirim turu.', 'VALIDATION', 422);
            $values['isPrice'] = (int) $isPrice;
        }
        if (array_key_exists('value', $input)) {
            $value = filter_var($input['value'], FILTER_VALIDATE_FLOAT);
            if ($value === false || $value <= 0) throw new HttpException('Indirim 0\'dan buyuk olmalidir.', 'VALIDATION', 422);
            $isPrice = isset($values['isPrice']) ? $values['isPrice'] === 1 : ($current['isPrice'] ?? false);
            if (!$isPrice && $value > 100) throw new HttpException('Yuzde indirim 100\'u gecemez.', 'VALIDATION', 422);
            $values['value'] = $value;
        }
        if (array_key_exists('stock', $input)) {
            $stock = filter_var($input['stock'], FILTER_VALIDATE_INT);
            if ($stock === false || $stock < 0 || $stock > 2147483647) throw new HttpException('Stok 0 veya daha buyuk bir tam sayi olmalidir.', 'VALIDATION', 422);
            $values['stock'] = $stock;
        }
        return $values;
    }

    /** @param mixed $value */
    private function date($value, string $label): string
    {
        $text = is_string($value) ? trim($value) : '';
        foreach (['Y-m-d', 'd.m.Y'] as $format) {
            $date = \DateTime::createFromFormat('!' . $format, $text);
            if ($date !== false && $date->format($format) === $text) return $date->format('Y-m-d');
        }
        throw new HttpException($label . ' gecersiz.', 'VALIDATION', 422);
    }

    // endDate kolonu sadece tarih (date) ise saat yazilamaz.
    private function endDateHasTime(PDO $pdo): bool
    {
        $stmt = $pdo->query("SELECT LOWER(DATA_TYPE) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'promotionCodes' AND COLUMN_NAME = 'endDate'");
        return in_array((string) $stmt->fetchColumn(), ['datetime', 'datetime2', 'smalldatetime', 'datetimeoffset'], true);
    }

    // Promosyon linki icin site adresi (panelde site_("domain")).
    private function siteDomain(PDO $pdo): string
    {
        try {
            $row = $pdo->query('SELECT TOP 1 domain FROM dbo.genel')->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return '';
        }
        $domain = is_array($row) ? rtrim(trim((string) ($row['domain'] ?? '')), '/') : '';
        if ($domain !== '' && preg_match('#^https?://#i', $domain) !== 1) $domain = 'https://' . $domain;
        return $domain;
    }
}
