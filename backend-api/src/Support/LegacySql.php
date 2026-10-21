<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\HttpException;
use PDO;

/*
 * Eski panel tablolari (sayfalar{dbtable}, blog_kategorileri, upload) icin ortak SQL yardimcilari.
 * Kullanan controller $this->request uzerinden site/id okur.
 */
trait LegacySql
{
    protected function siteId(): int
    {
        $value = $this->request->query('site', $this->request->input('site', 1));
        $site = filter_var($value, FILTER_VALIDATE_INT);
        if ($site === false || $site <= 0) throw new HttpException('Gecersiz site.', 'VALIDATION', 422);
        return $site;
    }

    /**
     * Sitelerin id, ad, kisa ad ve tablo soneki (dbtable). sites tablosu yoksa global $SITES, o da yoksa tek site.
     * Gecersiz sonekli site atlanir.
     *
     * @return array<int,array{id:int,site:string,kisa:string,dbtable:string}>
     */
    protected function sites(PDO $pdo): array
    {
        $rows = [];
        try {
            $rows = $pdo->query('SELECT id, site, kisa, dbtable FROM sites ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            global $SITES;
            if (isset($SITES) && is_array($SITES)) {
                foreach ($SITES as $id => $site) {
                    if (!is_array($site)) continue;
                    $rows[] = ['id' => $id, 'site' => $site['site'] ?? '', 'kisa' => $site['kisa'] ?? '', 'dbtable' => $site['dbtable'] ?? ''];
                }
            }
        }
        if ($rows === []) $rows = [['id' => 1, 'site' => 'Site 1', 'kisa' => '', 'dbtable' => '']];

        $sites = [];
        foreach ($rows as $row) {
            $suffix = trim((string) ($row['dbtable'] ?? ''));
            if ($suffix !== '' && preg_match('/^_[A-Za-z0-9]+$/', $suffix) !== 1) continue;
            $sites[] = [
                'id' => (int) $row['id'],
                'site' => trim((string) ($row['site'] ?? '')),
                'kisa' => trim((string) ($row['kisa'] ?? '')),
                'dbtable' => $suffix,
            ];
        }
        return $sites;
    }

    // Aktif sitenin tablo soneki ('' / '_s2' ...).
    protected function siteSuffix(PDO $pdo): string
    {
        $siteId = $this->siteId();
        foreach ($this->sites($pdo) as $site) {
            if ($site['id'] === $siteId) return $site['dbtable'];
        }
        throw new HttpException('Site bulunamadi.', 'SITE_NOT_FOUND', 404);
    }

    /** @return array<int,string> kucuk harfli kolon adlari */
    protected function tableColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare("SELECT LOWER(COLUMN_NAME) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = :t");
        $stmt->execute([':t' => $table]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'c');
    }

    /**
     * Satir ekler ve yeni kimligi dondurur. OUTPUT INSERTED trigger'li tablolarda hata verdigi icin
     * ayni batch'te SCOPE_IDENTITY() okunur. Gonderilmeyen NOT NULL + varsayilansiz kolonlar
     * eski panel kayitlari gibi bos deger alir ('' / 0 / GETDATE()).
     *
     * @param array<string,mixed> $values kolon => deger (parametreli)
     * @param array<string,string> $raw kolon => SQL ifadesi (ornek GETDATE())
     */
    protected function insertRow(PDO $pdo, string $table, array $values, array $raw = []): int
    {
        $given = array_map('strtolower', array_merge(array_keys($values), array_keys($raw)));
        foreach ($this->requiredColumns($pdo, $table) as $column) {
            if (in_array(strtolower($column['name']), $given, true)) continue;
            $type = strtolower((string) $column['type']);
            if (in_array($type, ['char', 'varchar', 'nchar', 'nvarchar', 'text', 'ntext'], true)) {
                $values[$column['name']] = '';
            } elseif (in_array($type, ['bit', 'tinyint', 'smallint', 'int', 'bigint', 'decimal', 'numeric', 'float', 'real', 'money', 'smallmoney'], true)) {
                $values[$column['name']] = 0;
            } elseif (in_array($type, ['date', 'datetime', 'datetime2', 'smalldatetime', 'datetimeoffset'], true)) {
                $raw[$column['name']] = 'GETDATE()';
            }
        }

        $names = array_merge(array_keys($values), array_keys($raw));
        $params = [];
        $placeholders = [];
        $index = 0;
        foreach ($values as $value) {
            $key = 'p' . $index++;
            $params[$key] = $value;
            $placeholders[] = ':' . $key;
        }
        $placeholders = array_merge($placeholders, array_values($raw));

        $stmt = $pdo->prepare('SET NOCOUNT ON; INSERT INTO dbo.[' . $table . '] ([' . implode('], [', $names) . ']) VALUES ('
            . implode(', ', $placeholders) . '); SELECT CAST(SCOPE_IDENTITY() AS INT) AS id;');
        $this->execute($stmt, $params);
        // Trigger ek sonuc kumesi dondurse bile son kolonlu sonuc SCOPE_IDENTITY'dir.
        $id = 0;
        do {
            if ($stmt->columnCount() > 0) {
                $value = $stmt->fetchColumn();
                if ($value !== false && $value !== null) $id = (int) $value;
            }
        } while ($stmt->nextRowset());
        if ($id <= 0) throw new HttpException('Kayit eklendi ancak kimligi okunamadi.', 'INSERT_ID_MISSING', 500);
        return $id;
    }

    /** @return array<int,array{name:string,type:string}> */
    protected function requiredColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare("SELECT c.COLUMN_NAME AS name, c.DATA_TYPE AS type
            FROM INFORMATION_SCHEMA.COLUMNS c
            WHERE c.TABLE_SCHEMA = 'dbo' AND c.TABLE_NAME = :t AND c.IS_NULLABLE = 'NO' AND c.COLUMN_DEFAULT IS NULL
              AND COLUMNPROPERTY(OBJECT_ID('dbo.' + QUOTENAME(c.TABLE_NAME)), c.COLUMN_NAME, 'IsIdentity') = 0
              AND COLUMNPROPERTY(OBJECT_ID('dbo.' + QUOTENAME(c.TABLE_NAME)), c.COLUMN_NAME, 'IsComputed') = 0");
        $stmt->execute([':t' => $table]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function queryId(string $key): int
    {
        return $this->validId($this->request->query($key));
    }

    protected function bodyId(string $key): int
    {
        return $this->validId($this->request->input($key));
    }

    /** @param mixed $raw */
    protected function validId($raw): int
    {
        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false || $value <= 0 || $value > 2147483647) throw new HttpException('Gecersiz ID.', 'VALIDATION', 422);
        return $value;
    }

    protected function execute(\PDOStatement $stmt, array $values): void
    {
        foreach ($values as $key => $value) $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $stmt->execute();
    }

    protected function imageBase(): string
    {
        return Uploads::cdnDomain() . '/uploads/small/';
    }
}
