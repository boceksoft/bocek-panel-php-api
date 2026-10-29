<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Support\LegacySql;
use PDO;

/*
 * Rezervasyon satis kanallari (eski panel satis_kanallari.asp karsiligi).
 * Tablo satis_kanallari: id, baslik, color (color kolonu her kurulumda yok). Rezervasyon baglantisi kayitlar.satis_kanallari_id.
 *   GET  /backend-api/sales-channels
 *   POST /backend-api/sales-channels          { baslik, color? }
 *   POST /backend-api/sales-channels/update   { id, baslik?, color? }
 *   POST /backend-api/sales-channels/delete   { id }
 * Eski panelden farki: ayni baslik iki kez eklenmez, renk #RRGGBB olarak dogrulanir ve rezervasyonda
 * kullanilan kanal silinmez (silinirse o rezervasyonlarin kanali bos kalirdi).
 */
final class SalesChannelsController extends Controller
{
    use LegacySql;

    private const MAX_TITLE_LENGTH = 200;

    /** @Get */
    public function index(): void
    {
        $pdo = $this->db->pdo();
        $hasColor = $this->hasColor($pdo);
        $rows = $pdo->query('SELECT id, baslik' . ($hasColor ? ', color' : '') . ' FROM dbo.satis_kanallari ORDER BY baslik ASC')->fetchAll(PDO::FETCH_ASSOC);
        $usage = $this->usage($pdo);

        $channels = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $channels[] = [
                'id' => $id,
                'baslik' => trim((string) ($row['baslik'] ?? '')),
                'color' => $hasColor ? trim((string) ($row['color'] ?? '')) : '',
                'rezervasyonSayisi' => $usage[$id] ?? 0,
            ];
        }

        $this->response->success(['channels' => $channels, 'hasColor' => $hasColor]);
    }

    /** @Post */
    public function store(): void
    {
        $input = $this->request->json();
        $title = $this->title($input['baslik'] ?? null);
        $pdo = $this->db->pdo();
        $values = ['baslik' => $title];
        if ($this->hasColor($pdo)) $values['color'] = $this->color($input['color'] ?? '');

        $pdo->beginTransaction();
        try {
            $this->assertUniqueTitle($pdo, $title, 0);
            $id = $this->insertRow($pdo, 'satis_kanallari', $values);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $this->response->success(['message' => 'Satis kanali eklendi', 'id' => $id], 201);
    }

    /** @Post("update") */
    public function update(): void
    {
        $id = $this->bodyId('id');
        $input = $this->request->json();
        $pdo = $this->db->pdo();
        $values = [];
        if (array_key_exists('baslik', $input)) $values['baslik'] = $this->title($input['baslik']);
        if (array_key_exists('color', $input) && $this->hasColor($pdo)) $values['color'] = $this->color($input['color']);
        if ($values === []) throw new HttpException('Guncellenecek alan gereklidir.', 'VALIDATION', 422);

        $pdo->beginTransaction();
        try {
            $this->requireChannel($pdo, $id);
            if (isset($values['baslik'])) $this->assertUniqueTitle($pdo, $values['baslik'], $id);
            $sets = array_map(function ($field) { return '[' . $field . '] = :' . $field; }, array_keys($values));
            $this->execute($pdo->prepare('UPDATE dbo.satis_kanallari SET ' . implode(', ', $sets) . ' WHERE id = :id'), $values + ['id' => $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $this->response->success(['message' => 'Satis kanali guncellendi']);
    }

    /** @Post("delete") */
    public function delete(): void
    {
        $id = $this->bodyId('id');
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->requireChannel($pdo, $id);
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM dbo.kayitlar WITH (UPDLOCK, HOLDLOCK) WHERE satis_kanallari_id = :id');
            $this->execute($stmt, ['id' => $id]);
            $count = (int) $stmt->fetchColumn();
            if ($count > 0) {
                throw new HttpException('Bu satis kanali ' . $count . ' rezervasyonda kullaniliyor; silinemez.', 'CHANNEL_IN_USE', 409);
            }
            $this->execute($pdo->prepare('DELETE FROM dbo.satis_kanallari WHERE id = :id'), ['id' => $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $this->response->success(['message' => 'Satis kanali silindi']);
    }

    private function hasColor(PDO $pdo): bool
    {
        return in_array('color', $this->tableColumns($pdo, 'satis_kanallari'), true);
    }

    /** @return array<int,int> kanal id => rezervasyon sayisi */
    private function usage(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT satis_kanallari_id AS id, COUNT(*) AS c FROM dbo.kayitlar WHERE satis_kanallari_id IS NOT NULL GROUP BY satis_kanallari_id')->fetchAll(PDO::FETCH_ASSOC);
        $usage = [];
        foreach ($rows as $row) $usage[(int) $row['id']] = (int) $row['c'];
        return $usage;
    }

    private function requireChannel(PDO $pdo, int $id): void
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM dbo.satis_kanallari WITH (UPDLOCK, HOLDLOCK) WHERE id = :id');
        $this->execute($stmt, ['id' => $id]);
        if ((int) $stmt->fetchColumn() === 0) throw new HttpException('Satis kanali bulunamadi.', 'NOT_FOUND', 404);
    }

    // Buyuk/kucuk harf ve bosluk farki ayni baslik sayilir.
    private function assertUniqueTitle(PDO $pdo, string $title, int $exceptId): void
    {
        $key = mb_strtolower($title, 'UTF-8');
        $rows = $pdo->query('SELECT id, baslik FROM dbo.satis_kanallari WITH (UPDLOCK, HOLDLOCK)')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if ((int) $row['id'] === $exceptId) continue;
            if (mb_strtolower(trim((string) ($row['baslik'] ?? '')), 'UTF-8') === $key) {
                throw new HttpException('Bu isimde bir satis kanali zaten var.', 'DUPLICATE_TITLE', 409);
            }
        }
    }

    /** @param mixed $value */
    private function title($value): string
    {
        if (!is_string($value)) throw new HttpException('Baslik gereklidir.', 'VALIDATION', 422);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($value === '') throw new HttpException('Baslik gereklidir.', 'VALIDATION', 422);
        if (mb_strlen($value, 'UTF-8') > self::MAX_TITLE_LENGTH) {
            throw new HttpException('Baslik en fazla ' . self::MAX_TITLE_LENGTH . ' karakter olabilir.', 'VALIDATION', 422);
        }
        return $value;
    }

    // Bos ya da #RRGGBB; "#abc" ve "ff0000" yazimlari #RRGGBB'ye cevrilir.
    /** @param mixed $value */
    private function color($value): string
    {
        if ($value === null) return '';
        if (!is_string($value)) throw new HttpException('Gecersiz renk.', 'VALIDATION', 422);
        $value = ltrim(trim($value), '#');
        if ($value === '') return '';
        if (preg_match('/^[0-9a-f]{3}$/i', $value) === 1) {
            $value = $value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2];
        }
        if (preg_match('/^[0-9a-f]{6}$/i', $value) !== 1) throw new HttpException('Renk #RRGGBB biciminde olmalidir.', 'VALIDATION', 422);
        return '#' . strtolower($value);
    }
}
