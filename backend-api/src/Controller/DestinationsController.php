<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

final class DestinationsController extends Controller
{
    private const FIELDS = [
        'baslik' => 'string', 'url' => 'string', 'cat' => 'int', 'siralama' => 'int',
        'aktif' => 'bit', 'vitrin' => 'bit', 'favori' => 'bit', 'icerik' => 'string',
        'canonical' => 'string', 'title' => 'string', 'keywords' => 'string', 'description' => 'string',
    ];

    /** @Get */
    public function index(): void
    {
        $pdo = $this->db->pdo();
        $columns = implode(', ', array_map(function ($field) { return '[' . $field . ']'; }, $this->existingFields($pdo)));
        $rows = $pdo->query('SELECT id, ' . $columns . ' FROM dbo.destinations ORDER BY siralama, id')->fetchAll();
        $this->response->success(['destinations' => $rows]);
    }

    /** @Post */
    public function store(): void
    {
        $values = $this->values();
        if (!isset($values['baslik']) || $values['baslik'] === '') {
            throw new HttpException('Bolge adi gereklidir.', 'VALIDATION', 422);
        }
        $values += ['cat' => 0, 'siralama' => 999, 'aktif' => 0, 'vitrin' => 0, 'favori' => 0];
        $pdo = $this->db->pdo();
        $values = array_intersect_key($values, array_flip($this->existingFields($pdo)));
        $pdo->beginTransaction();
        try {
            $this->validateParent($this->hierarchy($pdo), 0, $values['cat']);
            $columns = array_keys($values);
            $stmt = $pdo->prepare('INSERT INTO dbo.destinations ([' . implode('], [', $columns)
                . ']) VALUES (:' . implode(', :', $columns) . ')');
            $this->execute($stmt, $values);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $this->response->success(['message' => 'Bolge eklendi'], 201);
    }

    /** @Post("update") */
    public function update(): void
    {
        $id = $this->id('id');
        $values = $this->values();
        if ($values === []) throw new HttpException('Guncellenecek alan gereklidir.', 'VALIDATION', 422);
        $pdo = $this->db->pdo();
        $values = array_intersect_key($values, array_flip($this->existingFields($pdo)));
        if ($values === []) throw new HttpException('Guncellenecek alan gereklidir.', 'VALIDATION', 422);
        $pdo->beginTransaction();
        try {
            $rows = $this->hierarchy($pdo);
            $this->requireRegion($rows, $id);
            if (isset($values['cat'])) $this->validateParent($rows, $id, $values['cat']);
            $sets = array_map(function ($field) { return '[' . $field . '] = :' . $field; }, array_keys($values));
            $stmt = $pdo->prepare('UPDATE dbo.destinations SET ' . implode(', ', $sets) . ' WHERE id = :id');
            $this->execute($stmt, $values + ['id' => $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $this->response->success(['message' => 'Bolge guncellendi']);
    }

    /** @Post("delete") */
    public function delete(): void
    {
        $id = $this->id('id');
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $rows = $this->hierarchy($pdo);
            $this->requireRegion($rows, $id);
            foreach ($rows as $row) {
                if ((int) $row['cat'] === $id) {
                    throw new HttpException('Once alt bolgeleri tasiyin veya silin.', 'CHILD_REGIONS', 409);
                }
            }
            $this->execute($pdo->prepare('DELETE FROM dbo.destinations WHERE id = :id'), ['id' => $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $this->response->success(['message' => 'Bolge silindi']);
    }

    /** @Post("move") */
    public function move(): void
    {
        $id = $this->id('id');
        $targetId = $this->id('targetId');
        $position = $this->request->input('position');
        if ($id === $targetId || !in_array($position, ['inside', 'before', 'after'], true)) {
            throw new HttpException('Gecersiz tasima.', 'VALIDATION', 422);
        }
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $rows = $this->hierarchy($pdo);
            $this->requireRegion($rows, $id);
            $target = $this->requireRegion($rows, $targetId);
            $parentId = $position === 'inside' ? $targetId : (int) $target['cat'];
            $this->validateParent($rows, $id, $parentId);
            $siblings = [];
            foreach ($rows as $row) {
                if ((int) $row['cat'] === $parentId && (int) $row['id'] !== $id) $siblings[] = (int) $row['id'];
            }
            $index = $position === 'inside' ? count($siblings) : array_search($targetId, $siblings, true);
            if ($position === 'after') ++$index;
            array_splice($siblings, $index, 0, [$id]);
            $stmt = $pdo->prepare('UPDATE dbo.destinations SET cat = :cat, siralama = :siralama WHERE id = :id');
            foreach ($siblings as $offset => $siblingId) {
                $this->execute($stmt, ['id' => $siblingId, 'cat' => $parentId, 'siralama' => $offset + 1]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $this->response->success(['message' => 'Bolge tasindi']);
    }

    // Eski kurulumlarda destinations tablosunda olmayan kolonlar sorguyu bozmasin.
    private function existingFields(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT LOWER(COLUMN_NAME) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'destinations'");
        $present = array_column($stmt->fetchAll(), 'c');
        return array_values(array_filter(array_keys(self::FIELDS), function ($field) use ($present) {
            return in_array($field, $present, true);
        }));
    }

    private function values(): array
    {
        $input = $this->request->json();
        $values = [];
        foreach (self::FIELDS as $field => $type) {
            if (!array_key_exists($field, $input)) continue;
            $value = $input[$field];
            if ($type === 'string') {
                if ($value !== null && !is_string($value)) throw new HttpException('Gecersiz metin alani.', 'VALIDATION', 422);
                $values[$field] = trim((string) $value);
                if ($field === 'baslik' && $values[$field] === '') throw new HttpException('Bolge adi gereklidir.', 'VALIDATION', 422);
            } else {
                if ($type === 'bit' && is_bool($value)) $value = (int) $value;
                $number = filter_var($value, FILTER_VALIDATE_INT);
                if ($number === false || $number < 0 || $number > 2147483647 || ($type === 'bit' && $number > 1)) {
                    throw new HttpException('Gecersiz sayisal alan.', 'VALIDATION', 422);
                }
                $values[$field] = $number;
            }
        }
        return $values;
    }

    private function id(string $key): int
    {
        $value = filter_var($this->request->input($key), FILTER_VALIDATE_INT);
        if ($value === false || $value <= 0 || $value > 2147483647) throw new HttpException('Gecersiz ID.', 'VALIDATION', 422);
        return $value;
    }

    private function execute(\PDOStatement $stmt, array $values): void
    {
        foreach ($values as $key => $value) $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $stmt->execute();
    }

    private function hierarchy(PDO $pdo): array
    {
        // Serialize hierarchy edits so concurrent moves cannot create a cycle.
        return $pdo->query('SELECT id, cat, siralama FROM dbo.destinations WITH (UPDLOCK, HOLDLOCK) ORDER BY siralama, id')->fetchAll();
    }

    private function requireRegion(array $rows, int $id): array
    {
        foreach ($rows as $row) if ((int) $row['id'] === $id) return $row;
        throw new HttpException('Bolge bulunamadi.', 'NOT_FOUND', 404);
    }

    private function validateParent(array $rows, int $id, int $parentId): void
    {
        $parents = [];
        foreach ($rows as $row) $parents[(int) $row['id']] = (int) $row['cat'];
        $visited = [];
        while ($parentId !== 0) {
            if ($parentId === $id || isset($visited[$parentId]) || !array_key_exists($parentId, $parents)) {
                throw new HttpException('Gecersiz ust bolge.', 'INVALID_PARENT', 422);
            }
            $visited[$parentId] = true;
            $parentId = $parents[$parentId];
        }
    }
}
