<?php

declare(strict_types=1);

namespace App\Controller;

/*
 * API token kaynağı.
 *   POST /backend-api/tokens  -> yeni token üretir ve kaydeder (UserId = 0)
 * DİKKAT: Bu uç auth GEREKTİRMEZ (config/app.php -> public_resources: ['tokens']).
 * (Eski create-token.php taşınmış hâli.)
 */
final class TokensController extends Controller
{
    /**
     * Token üretir. GET ve POST ile, hem /tokens hem /tokens/create yollarından çalışır.
     *
     * @Get
     * @Post
     * @Get("create")
     * @Post("create")
     */
    public function create(): void
    {
        $domain = defined('Domain') ? (string) constant('Domain') : '';
        $token = $this->generateJwt($domain);

        $pdo = $this->db->pdo();

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $expireDate = date('Y-m-d H:i:s', strtotime('+1 year'));

        // UserId=0 kaydı varsa güncelle, yoksa ekle. SQL Server PDO'da aynı
        // parametreyi iki kez kullanamadığımız için 1/2 ekleriyle çoğalttık.
        $sql = "
            IF EXISTS (SELECT 1 FROM AuthToken WHERE UserId = 0)
            BEGIN
                UPDATE AuthToken
                SET Token = :Token1, ExpireDate = :ExpireDate1,
                    IpAdress = :IpAdress1, UserAgent = :UserAgent1, CreatedOn = GETDATE()
                WHERE UserId = 0
            END
            ELSE
            BEGIN
                INSERT INTO AuthToken
                    (Token, UserId, ExpireDate, IpAdress, UserAgent, CurrentUsername, CurrentPassword, CurrentEmail)
                VALUES
                    (:Token2, 0, :ExpireDate2, :IpAdress2, :UserAgent2, NULL, '', NULL)
            END
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':Token1'      => $token,
            ':ExpireDate1' => $expireDate,
            ':IpAdress1'   => substr($ip, 0, 50),
            ':UserAgent1'  => substr($userAgent, 0, 300),
            ':Token2'      => $token,
            ':ExpireDate2' => $expireDate,
            ':IpAdress2'   => substr($ip, 0, 50),
            ':UserAgent2'  => substr($userAgent, 0, 300),
        ]);

        $this->response->success(['token' => $token]);
    }

    /**
     * Rastgele, imzalı bir JWT üretir (HS256).
     */
    private function generateJwt(string $siteDomain): string
    {
        $secretKey = '9f2d8a7c4b6e1d0f3a5c8e7b2d9f6a1c4e8b0d3f7a9c2e5d6b1a4f8c0e3d7a9b';

        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode([
            'SiteDomain' => $siteDomain,
            'jti'        => bin2hex(random_bytes(16)),
            'iat'        => time(),
        ]);

        $b64Header  = $this->base64Url($header);
        $b64Payload = $this->base64Url($payload);
        $signature  = hash_hmac('sha256', $b64Header . '.' . $b64Payload, $secretKey, true);

        return $b64Header . '.' . $b64Payload . '.' . $this->base64Url($signature);
    }

    private function base64Url(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }
}
