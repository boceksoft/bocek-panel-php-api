<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/*
 * Tablo kolon varlığı kontrolü (COL_LENGTH yerine).
 * Bir tablonun tüm kolonları istek başına TEK sorguyla (sys.columns) okunur ve aynı istek içinde tekrar kullanılır.
 * Önceden her "bu kolon var mı" kontrolü ayrı bir veritabanı turuydu; liste uç noktalarında istek başına 10+ tur ediyordu.
 * Önbellek sadece istek süresince tutulur: kolon ekleyen kurulum (setup) istekleri bunu kullanmaz, sonraki istek yeni kolonu görür.
 */
final class SchemaColumns
{
    /** @var array<string, array<string, bool>> 'dbo.homes' => ['aktif' => true, ...] */
    private static $cache = [];

    /**
     * @param string $table 'homes', 'dbo.homes', '[dbo].[homes]' veya 'KiralamaTakvimi.CalendarHomes'
     */
    public static function exists(PDO $pdo, string $table, string $column): bool
    {
        $columns = self::columns($pdo, $table);

        return isset($columns[strtolower(trim($column, '[] '))]);
    }

    /**
     * Tablonun kolon adları (küçük harf => true). Tablo yoksa boş dizi.
     *
     * @return array<string, bool>
     */
    public static function columns(PDO $pdo, string $table): array
    {
        $name = self::normalizeTable($table);
        $key = strtolower($name);
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $stmt = $pdo->prepare('SELECT c.name FROM sys.columns c WHERE c.object_id = OBJECT_ID(:t)');
        $stmt->execute([':t' => $name]);
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $col) {
            $columns[strtolower((string) $col)] = true;
        }

        self::$cache[$key] = $columns;

        return $columns;
    }

    private static function normalizeTable(string $table): string
    {
        $clean = str_replace(['[', ']'], '', trim($table));
        if (strpos($clean, '.') === false) {
            $clean = 'dbo.' . $clean;
        }

        return $clean;
    }
}
