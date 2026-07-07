<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

/*
 * SQL Server (sqlsrv) PDO bağlantısını tek yerden yönetir.
 * Bağlantı ilk kullanımda (lazy) kurulur ve tekrar kullanılır.
 */
final class Database
{
    /** @var array */
    private $config;

    /** @var PDO|null */
    private $pdo = null;

    /**
     * @param array $config ['host' => ..., 'name' => ..., 'user' => ..., 'pass' => ...]
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        try {
            $dsn = sprintf(
                'sqlsrv:server=%s;database=%s',
                $this->config['host'] ?? '',
                $this->config['name'] ?? ''
            );

            $pdo = new PDO($dsn, $this->config['user'] ?? '', $this->config['pass'] ?? '');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            $this->pdo = $pdo;

            return $pdo;
        } catch (PDOException $e) {
            throw new HttpException('Veritabanı bağlantısı başarısız.', 'DB_CONNECTION', 500, $e);
        }
    }
}
