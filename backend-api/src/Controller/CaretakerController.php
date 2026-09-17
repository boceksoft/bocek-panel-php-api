<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Bakimci kaynagi.
 *   GET    /backend-api/caretaker -> dbo.bakimcilar listesini doner
 *   POST   /backend-api/caretaker -> dbo.bakimcilar tablosuna ekler
 *   PUT    /backend-api/caretaker -> dbo.bakimcilar kaydini duzenler
 *   GET    /backend-api/caretaker/update -> query parametreleriyle duzenler
 *   DELETE /backend-api/caretaker -> dbo.bakimcilar tablosundan siler
 */
final class CaretakerController extends Controller
{
    /**
     * Bakimcilari listeler.
     *
     * @Get
     * @query homesId int Opsiyonel ev kimligi
     * @query q string Opsiyonel ad/telefon/e-posta aramasi
     */
    public function index(): void
    {
        $homesId = (int) $this->request->query('homesId', $this->request->query('homes_id', 0));
        $q = trim((string) $this->request->query('q', ''));

        $where = [
            "bakimciAdi IS NOT NULL",
            "LTRIM(RTRIM(bakimciAdi)) <> ''",
        ];
        $params = [];

        if ($homesId > 0) {
            $where[] = 'homesId = :homesId';
            $params[':homesId'] = $homesId;
        }

        if ($q !== '') {
            $where[] = "(bakimciAdi LIKE :q OR bakimcitel LIKE :q OR bakimciEmail LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }

        $sql = "SELECT
                    id,
                    homesId AS homes_id,
                    bakimciAdi AS name,
                    bakimciAdi AS title,
                    bakimcitel AS phone,
                    bakimciAdres AS address,
                    bakimciEmail AS email
                FROM dbo.bakimcilar
                WHERE " . implode(' AND ', $where) . "
                ORDER BY bakimciAdi ASC, id ASC";

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        $caretakers = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $caretakers[] = $this->normalizeCaretaker($row);
        }

        $this->response->success([
            'caretakers' => $caretakers,
            'bakimcilar' => $caretakers,
        ]);
    }

    /**
     * Bakimci ekler.
     *
     * @Post
     * @body homesId int Opsiyonel ev kimligi
     * @body bakimciAdi string required Bakimci adi
     * @body bakimcitel string Opsiyonel telefon
     * @body bakimciAdres string Opsiyonel adres
     * @body bakimciEmail string Opsiyonel e-posta
     */
    public function create(): void
    {
        if ((int) $this->inputAny(['id'], 0) > 0) {
            $this->update();
            return;
        }

        $name = $this->cleanName((string) $this->inputAny(['bakimciAdi', 'bakimciadi', 'name', 'ad_soyad'], ''));
        if ($name === '' || !$this->hasLetter($name)) {
            throw new HttpException('Bakimci adi harf icermeli.', 'VALIDATION', 422);
        }

        $phone = $this->normalizePhone((string) $this->inputAny(['bakimcitel', 'phone', 'telefon'], ''));
        $address = trim((string) $this->inputAny(['bakimciAdres', 'address', 'adres'], ''));
        $email = trim((string) $this->inputAny(['bakimciEmail', 'email', 'eposta'], ''));
        $homesId = (int) $this->inputAny(['homesId', 'homes_id', 'homeId', 'home_id'], 0);
        $homesIdValue = $homesId > 0 ? $homesId : null;

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->clearOtherHomeCaretakers($pdo, $homesIdValue);

            $stmt = $pdo->prepare(
                "INSERT INTO dbo.bakimcilar
                    (homesId, bakimciAdi, bakimcitel, bakimciAdres, bakimciEmail)
                 OUTPUT INSERTED.id
                 VALUES
                    (:homesId, :bakimciAdi, :bakimcitel, :bakimciAdres, :bakimciEmail)"
            );
            $stmt->bindValue(':homesId', $homesIdValue, $homesIdValue === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':bakimciAdi', $name);
            $stmt->bindValue(':bakimcitel', $phone !== '' ? $phone : null, $phone !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':bakimciAdres', $address !== '' ? $address : null, $address !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':bakimciEmail', $email !== '' ? $email : null, $email !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->execute();

            $id = (int) $stmt->fetchColumn();
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        $this->response->success([
            'caretaker' => $this->fetchCaretaker($pdo, $id),
        ], 201);
    }

    /**
     * Bakimci duzenler.
     *
     * @Put
     * @Get("update")
     * @Post("update")
     * @body id int required Bakimci kimligi
     * @body homesId int Opsiyonel ev kimligi
     * @body bakimciAdi string Opsiyonel bakimci adi
     * @body bakimcitel string Opsiyonel telefon
     * @body bakimciAdres string Opsiyonel adres
     * @body bakimciEmail string Opsiyonel e-posta
     */
    public function update(): void
    {
        $id = (int) $this->request->input('id', $this->request->query('id', 0));
        if ($id <= 0) {
            throw new HttpException('Lutfen gecerli bir bakimci ID gonderin.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();
        if ($this->fetchCaretaker($pdo, $id) === []) {
            throw new HttpException('Bakimci bulunamadi.', 'NOT_FOUND', 404);
        }

        $updates = [];
        $params = [':id' => $id];

        if ($this->hasInputAny(['homesId', 'homes_id', 'homeId', 'home_id'])) {
            $homesId = (int) $this->inputAny(['homesId', 'homes_id', 'homeId', 'home_id'], 0);
            $updates[] = 'homesId = :homesId';
            $params[':homesId'] = $homesId > 0 ? $homesId : null;
        }

        if ($this->hasInputAny(['bakimciAdi', 'bakimciadi', 'name', 'ad_soyad'])) {
            $name = $this->cleanName((string) $this->inputAny(['bakimciAdi', 'bakimciadi', 'name', 'ad_soyad'], ''));
            if ($name !== '' && $this->hasLetter($name)) {
                $updates[] = 'bakimciAdi = :bakimciAdi';
                $params[':bakimciAdi'] = $name;
            }
        } 

        if ($this->hasInputAny(['bakimcitel', 'phone', 'telefon'])) {
            $phone = $this->normalizePhone((string) $this->inputAny(['bakimcitel', 'phone', 'telefon'], ''));
            if ($phone !== '') {
                $updates[] = 'bakimcitel = :bakimcitel';
                $params[':bakimcitel'] = $phone;
            }
        }

        if ($this->hasInputAny(['bakimciAdres', 'address', 'adres'])) {
            $address = trim((string) $this->inputAny(['bakimciAdres', 'address', 'adres'], ''));
            $updates[] = 'bakimciAdres = :bakimciAdres';
            $params[':bakimciAdres'] = $address !== '' ? $address : null;
        }

        if ($this->hasInputAny(['bakimciEmail', 'email', 'eposta'])) {
            $email = trim((string) $this->inputAny(['bakimciEmail', 'email', 'eposta'], ''));
            $updates[] = 'bakimciEmail = :bakimciEmail';
            $params[':bakimciEmail'] = $email !== '' ? $email : null;
        }

        if ($updates === []) {
            throw new HttpException('Guncellenecek alan gonderilmedi.', 'VALIDATION', 422);
        }

        $pdo->beginTransaction();
        try {
            if (array_key_exists(':homesId', $params)) {
                $this->clearOtherHomeCaretakers($pdo, $params[':homesId'], $id);
            }

            $stmt = $pdo->prepare('UPDATE dbo.bakimcilar SET ' . implode(', ', $updates) . ' WHERE id = :id');
            foreach ($params as $key => $value) {
                if ($key === ':id') {
                    $stmt->bindValue($key, $value, PDO::PARAM_INT);
                    continue;
                }

                $stmt->bindValue($key, $value, $this->paramType($value));
            }
            $stmt->execute();
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        $this->response->success([
            'caretaker' => $this->fetchCaretaker($pdo, $id),
        ]);
    }

    /**
     * Bakimci siler.
     *
     * @Delete
     * @query id int required Bakimci kimligi
     * @body id int required Bakimci kimligi
     */
    public function delete(): void
    {
        $id = (int) $this->request->query('id', $this->request->input('id', 0));
        if ($id <= 0) {
            throw new HttpException('Lutfen gecerli bir bakimci ID gonderin.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();
        $existing = $this->fetchCaretaker($pdo, $id);
        if ($existing === []) {
            throw new HttpException('Bakimci bulunamadi.', 'NOT_FOUND', 404);
        }

        $stmt = $pdo->prepare('DELETE FROM dbo.bakimcilar WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $this->response->success([
            'deleted' => true,
            'id' => $id,
        ]);
    }

    /**
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

    private function hasInputAny(array $keys): bool
    {
        $body = $this->request->json();
        foreach ($keys as $key) {
            if ($this->request->query($key, null) !== null) {
                return true;
            }

            if (array_key_exists($key, $body)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     */
    private function paramType($value): int
    {
        if ($value === null) {
            return PDO::PARAM_NULL;
        }

        return is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
    }

    private function cleanName(string $name): string
    {
        $name = str_replace([',', ':', ';', '.'], '', $name);

        return trim($name);
    }

    private function hasLetter(string $value): bool
    {
        return preg_match('/[A-Za-zÇĞİÖŞÜçğıöşü]/u', $value) === 1;
    }

    private function normalizePhone(string $phone): string
    {
        $phone = str_replace(['+', ' ', '(', ')', '-', '.', '/', ',', "\t", "\n", "\r"], '', trim($phone));
        if ($phone === '') {
            return '';
        }

        if (strpos($phone, '0090') === 0 && strlen($phone) === 14) {
            return '90' . substr($phone, 4, 10);
        }

        if (strpos($phone, '90') === 0 && strlen($phone) === 12) {
            return substr($phone, 0, 20);
        }

        if (strpos($phone, '0') === 0 && strlen($phone) === 11) {
            return '90' . substr($phone, 1, 10);
        }

        if (strlen($phone) === 10) {
            return '90' . $phone;
        }

        return substr($phone, 0, 20);
    }

    /**
     * @param int|null $homesId
     */
    private function clearOtherHomeCaretakers(PDO $pdo, $homesId, int $exceptId = 0): void
    {
        if (!is_int($homesId) || $homesId <= 0) {
            return;
        }

        $sql = 'UPDATE dbo.bakimcilar SET homesId = NULL WHERE homesId = :homesId';
        $params = [':homesId' => $homesId];

        if ($exceptId > 0) {
            $sql .= ' AND id <> :exceptId';
            $params[':exceptId'] = $exceptId;
        }

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchCaretaker(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare(
            "SELECT
                    id,
                    homesId AS homes_id,
                    bakimciAdi AS name,
                    bakimciAdi AS title,
                    bakimcitel AS phone,
                    bakimciAdres AS address,
                    bakimciEmail AS email
             FROM dbo.bakimcilar
             WHERE id = :id"
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [];
        }

        return $this->normalizeCaretaker($row);
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeCaretaker(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'homes_id' => (int) ($row['homes_id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'address' => (string) ($row['address'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
        ];
    }
}
