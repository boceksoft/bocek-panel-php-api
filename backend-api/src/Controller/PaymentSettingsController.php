<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Support\LegacySql;
use PDO;

/*
 * Sitenin ödeme ayarları (eski panel genel_ayarlar.asp "Ödeme Ayarları" sekmesi; panel sadece site 1'de, genel tablosuna yazar).
 *   GET  /backend-api/payment-settings          -> { fields, values, posOptions }
 *   POST /backend-api/payment-settings/update   { defaultVirtualPosId?, defaultInstallment?, defaultInstallmentVirtualPosId?,
 *                                                 ziraatPayMaxInstallmentCount? }  (gönderilmeyen alan değişmez)
 * Kolonu olmayan alan okunmaz/yazılmaz (paneli bu sekmeyi içermeyen kurulumlar); fields bu kurulumda olanları listeler.
 * defaultInstallmentVirtualPosId null = "Otomatik" (panel boş seçimde NULL yazar).
 * Yeni rezervasyonlarda varsayılan POS'u ReservationsCreateController / VirtualPosController genel.DefaultVirtualPosId'den okur.
 */
final class PaymentSettingsController extends Controller
{
    use LegacySql;

    // API alanı => genel kolonu
    private const FIELDS = [
        'defaultVirtualPosId' => 'DefaultVirtualPosId',
        'defaultInstallment' => 'DefaultInstallment',
        'defaultInstallmentVirtualPosId' => 'DefaultInstallmentVirtualPosId',
        'ziraatPayMaxInstallmentCount' => 'ZiraatPayMaxInstallmentCount',
    ];

    /**
     * @Get
     */
    public function index(): void
    {
        $pdo = $this->db->pdo();
        $fields = $this->availableFields($pdo);

        $values = [];
        if ($fields !== []) {
            $row = $pdo->query('SELECT TOP 1 * FROM genel')->fetch(PDO::FETCH_ASSOC) ?: [];
            $row = array_change_key_case($row, CASE_LOWER);
            foreach ($fields as $field) {
                $raw = $row[strtolower(self::FIELDS[$field])] ?? null;
                if ($field === 'defaultInstallment') {
                    $values[$field] = $raw !== null && (int) $raw === 1;
                } else {
                    $values[$field] = $raw === null || $raw === '' || (int) $raw <= 0 ? null : (int) $raw;
                }
            }
        }

        $this->response->success([
            'fields' => $fields,
            'values' => (object) $values,
            'posOptions' => $this->posOptions($pdo),
        ]);
    }

    /** @Post("update") */
    public function update(): void
    {
        $pdo = $this->db->pdo();
        $fields = $this->availableFields($pdo);
        if ($fields === []) {
            throw new HttpException('Bu kurulumda ödeme ayarı kolonları yok (genel.DefaultVirtualPosId vb.).', 'NOT_SUPPORTED', 422);
        }

        $posIds = array_column($this->posOptions($pdo), 'id');
        $body = $this->request->json();
        $set = [];
        $params = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $raw = $body[$field];
            $column = self::FIELDS[$field];

            if ($field === 'defaultInstallment') {
                $value = filter_var($raw, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            } elseif ($field === 'ziraatPayMaxInstallmentCount') {
                $value = (int) $raw;
                if ($value < 1 || $value > 12) {
                    throw new HttpException('ZiraatPay taksit sınırı 1 ile 12 arasında olmalıdır.', 'VALIDATION', 422);
                }
            } else {
                $value = $raw === null || $raw === '' ? null : (int) $raw;
                if ($value !== null && $value <= 0) {
                    $value = null;
                }
                if ($field === 'defaultVirtualPosId' && $value === null) {
                    throw new HttpException('Varsayılan POS seçilmelidir.', 'VALIDATION', 422);
                }
                if ($value !== null && !in_array($value, $posIds, true)) {
                    throw new HttpException('Seçilen POS bulunamadı.', 'VALIDATION', 422);
                }
            }

            $set[] = '[' . $column . '] = :' . $field;
            $params[':' . $field] = $value;
        }

        if ($set === []) {
            throw new HttpException('Güncellenecek alan gönderilmedi.', 'VALIDATION', 422);
        }

        // Panel genel tablosunun (tek satır) ilk kaydını günceller.
        $where = in_array('id', $this->tableColumns($pdo, 'genel'), true)
            ? 'UPDATE genel SET ' . implode(', ', $set) . ' WHERE id = (SELECT TOP 1 id FROM genel ORDER BY id)'
            : 'UPDATE TOP (1) genel SET ' . implode(', ', $set);
        $stmt = $pdo->prepare($where);
        $stmt->execute($params);

        $this->response->success(['message' => 'Ödeme ayarları kaydedildi.']);
    }

    /** @return string[] bu kurulumda kolonu olan API alanları */
    private function availableFields(PDO $pdo): array
    {
        $columns = $this->tableColumns($pdo, 'genel');
        $fields = [];
        foreach (self::FIELDS as $field => $column) {
            if (in_array(strtolower($column), $columns, true)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /** @return array<int,array{id:int,name:string}> Finance.VirtualPos (panelde seçenekler buradan) */
    private function posOptions(PDO $pdo): array
    {
        try {
            $rows = $pdo->query('SELECT * FROM Finance.VirtualPos ORDER BY Id')->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }

        $options = [];
        foreach ($rows as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $options[] = ['id' => $id, 'name' => trim((string) ($row['name'] ?? ('POS ' . $id)))];
            }
        }

        return $options;
    }
}
