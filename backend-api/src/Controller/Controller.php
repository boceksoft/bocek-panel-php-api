<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/*
 * Tüm controller'ların ortak tabanı. İstek, yanıt ve veritabanına erişim sağlar.
 */
abstract class Controller
{
    /** @var Request */
    protected $request;

    /** @var Response */
    protected $response;

    /** @var Database */
    protected $db;

    public function __construct(Request $request, Response $response, Database $db)
    {
        $this->request = $request;
        $this->response = $response;
        $this->db = $db;
    }
}
