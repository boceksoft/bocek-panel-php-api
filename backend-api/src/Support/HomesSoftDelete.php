<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\HttpException;
use PDO;

/*
 * Villa silme (yumusak): homes satiri ve bagli veriler silinmez.
 *   - homes.isDeleted = 1  -> panel listelerinde (ilanlar, doluluk, villa ara, toplu duzenleme) gosterilmez
 *   - butun sitelerin aktif kolonlari (aktif, aktif_s2 ...) = 0 -> sitede de yayindan kalkar
 * Geri almak icin isDeleted = 0 yapilip villa yeniden aktiflenir.
 * Kolon setup-collums.sql ile eklenir; yoksa SETUP_REQUIRED hatasi verilir.
 */
final class HomesSoftDelete
{
    public static function hasColumn(PDO $pdo): bool
    {
        return (int) $pdo->query(
            "SELECT CASE WHEN COL_LENGTH('dbo.homes', 'isDeleted') IS NULL THEN 0 ELSE 1 END"
        )->fetchColumn() === 1;
    }

    /**
     * @param int[] $ids
     * @return array{deleted:int,requested:int,activeColumns:string[]}
     */
    public static function delete(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function (int $id): bool {
            return $id > 0;
        })));
        if (!$ids) {
            throw new HttpException('Silinecek villa secilmedi.', 'VALIDATION', 422);
        }
        if (count($ids) > 1000) {
            throw new HttpException('Tek seferde en fazla 1000 villa silinebilir.', 'VALIDATION', 422);
        }
        if (!self::hasColumn($pdo)) {
            // VALIDATION kodu: Web.Api toplu duzenleme proxy'si bu mesaji kullaniciya iletir (SETUP_REQUIRED genel hataya donusuyordu).
            throw new HttpException('homes.isDeleted kolonu bulunamadi; once setup calistirilmalidir.', 'VALIDATION', 409);
        }

        // Aktiflik kolonlari: aktif ve site sonekli halleri (aktif_s2 ...). Sadece "aktif" + "_harf/rakam" bicimi alinir.
        $activeColumns = [];
        $stmt = $pdo->query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'homes' AND (COLUMN_NAME = 'aktif' OR COLUMN_NAME LIKE 'aktif[_]%')"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
            $column = (string) $column;
            if (preg_match('/^aktif(_[A-Za-z0-9]+)?$/', $column) === 1) {
                $activeColumns[] = $column;
            }
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $sets = ['[isDeleted] = 1'];
        foreach ($activeColumns as $column) {
            $sets[] = "[{$column}] = 0";
        }

        $pdo->beginTransaction();
        try {
            // homes'ta trigger olabilir (rowCount guvenilmez): silinecek sayi once okunur.
            $count = $pdo->prepare("SELECT COUNT(*) FROM dbo.homes WHERE ISNULL([isDeleted], 0) = 0 AND id IN ({$placeholders})");
            $count->execute($ids);
            $deleted = (int) $count->fetchColumn();

            $update = $pdo->prepare('UPDATE dbo.homes SET ' . implode(', ', $sets) . " WHERE id IN ({$placeholders})");
            $update->execute($ids);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new HttpException('Villa silinemedi, hicbir degisiklik yapilmadi: ' . $e->getMessage(), 'DB_UPDATE_FAILED', 500);
        }

        return ['deleted' => $deleted, 'requested' => count($ids), 'activeColumns' => $activeColumns];
    }
}
