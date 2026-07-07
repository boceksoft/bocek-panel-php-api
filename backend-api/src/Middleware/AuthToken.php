<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use PDOException;

/*
 * Bearer token doğrulaması. Token, dbo.AuthToken tablosunda geçerli olmalı.
 */
final class AuthToken
{
    /** @var Database */
    private $db;

    /** @var Request */
    private $request;

    public function __construct(Database $db, Request $request)
    {
        $this->db = $db;
        $this->request = $request;
    }

    public function handle(): void
    {
        $token = $this->request->bearerToken();
        if ($token === null || $token === '') {
            throw new HttpException('Yetkilendirme reddedildi. Headerda Token bulunamadı.', 'AUTH_MISSING', 401);
        }

        try {
            $stmt = $this->db->pdo()->prepare(
                'SELECT TokenId FROM dbo.AuthToken
                 WHERE Token = :token
                   AND UserId = 0
                   AND IsDeleted = 0
                   AND ExpireDate > GETDATE()'
            );
            $stmt->execute([':token' => $token]);
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            throw new HttpException('Token doğrulama sırasında hata oluştu.', 'AUTH_ERROR', 500, $e);
        }

        if ($row === false) {
            throw new HttpException('Geçersiz veya süresi dolmuş token.', 'AUTH_INVALID', 401);
        }
    }
}
