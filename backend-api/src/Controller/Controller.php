<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/*
 * Tüm controller'ların ortak tabanı. İstek, yanıt, veritabanı ve
 * uygulama ayarlarına (config/app.php + app.local.php birleşimi) erişim sağlar.
 */
abstract class Controller
{
    /** @var Request */
    protected $request;

    /** @var Response */
    protected $response;

    /** @var Database */
    protected $db;

    /** @var array */
    protected $app;

    public function __construct(Request $request, Response $response, Database $db, array $app = [])
    {
        $this->request = $request;
        $this->response = $response;
        $this->db = $db;
        $this->app = $app;
    }

    /**
     * Silinen rezervasyonun veritabanındaki dolu.durum kodu (ayar: reservation_deleted_status, varsayılan 5).
     */
    protected function deletedStatus(): int
    {
        return (int) ($this->app['reservation_deleted_status'] ?? 5);
    }

    /**
     * Veritabanındaki dolu.durum değerini API koduna çevirir: siteye özel silinme kodu her zaman 5 olur.
     *
     * @param mixed $durum
     * @return int|null
     */
    protected function normalizeStatus($durum)
    {
        if ($durum === null || trim((string) $durum) === '' || !is_numeric(trim((string) $durum))) {
            return null;
        }
        $value = (int) $durum;

        return $value === $this->deletedStatus() ? 5 : $value;
    }

    /**
     * SQL ifadesi: dolu.durum'u API koduna çevirir (silinme kodu -> 5). Silinme kodu 5 ise ifade aynen döner.
     */
    protected function normalizedStatusSql(string $column): string
    {
        $deleted = $this->deletedStatus();

        return $deleted === 5 ? $column : "(CASE WHEN {$column} = {$deleted} THEN 5 ELSE {$column} END)";
    }
}
