<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\AuthToken;
use App\Middleware\IpWhitelist;

/*
 * Tek giriş noktası (front controller). Tüm /backend-api/* istekleri buraya düşer.
 * Akış: bootstrap -> CORS -> middleware (IP, Auth) -> router -> yanıt.
 */

$context = require __DIR__ . '/src/Support/bootstrap.php';
$app = $context['app'];

// Hata gösterimi tek yerden. Canlıda (debug=false) kapalı.
ini_set('display_errors', $app['debug'] ? '1' : '0');
error_reporting($app['debug'] ? E_ALL : 0);

// base_path ('/backend-api') istek yolundan soyulur, böylece router '/api/...' görür.
$request  = new Request($app['base_path']);
$response = new Response($app);

// Aktif kaynağın sürümünü yanıta ekle (versions.txt'ten gelir).
$response->setVersion($context['versions'][$request->resource()] ?? null);

// CORS preflight isteği
if ($request->method() === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $db = new Database($context['db']);

    (new IpWhitelist($app['allowed_ips']))->handle();

    // Token doğrulaması: auth açıksa ve kaynak public değilse uygula.
    $isPublic = in_array($request->resource(), $app['public_resources'], true);
    if ($app['auth_enabled'] && !$isPublic) {
        (new AuthToken($db, $request))->handle();
    }

    (new Router($request, $response, $db))->dispatch();
} catch (HttpException $e) {
    $message = $e->getMessage();
    if ($app['debug'] && $e->getPrevious() !== null) {
        $message .= ' | ' . $e->getPrevious()->getMessage();
    }
    $response->error($message, $e->errorCode(), $e->httpStatus());
} catch (\Throwable $e) {
    $message = $app['debug'] ? $e->getMessage() : 'Beklenmeyen bir hata oluştu.';
    $response->error($message, 'SERVER_ERROR', 500);
}
