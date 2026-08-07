<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\AuthToken;
use App\Middleware\IpWhitelist;

ob_start();

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
    $isPublic = in_array($request->resource(), $app['public_resources'], true)
        || backendApiIsCalculateImageRequest($request);
    if ($app['auth_enabled'] && !$isPublic) {
        (new AuthToken($db, $request))->handle();
    }

    (new Router($request, $response, $db, $app))->dispatch();
} catch (HttpException $e) {
    $message = $e->getMessage();
    if ($app['debug'] && $e->getPrevious() !== null) {
        $message .= ' | ' . $e->getPrevious()->getMessage();
    }
    if (backendApiIsCalculateImageRequest($request)) {
        backendApiSendErrorSvg($message);
    }
    $response->error($message, $e->errorCode(), $e->httpStatus());
} catch (\Throwable $e) {
    $message = $app['debug'] ? $e->getMessage() : 'Beklenmeyen bir hata oluştu.';
    if (backendApiIsCalculateImageRequest($request)) {
        backendApiSendErrorSvg($message);
    }
    $response->error($message, 'SERVER_ERROR', 500);
}

function backendApiIsCalculateImageRequest(Request $request): bool
{
    return trim($request->path(), '/') === 'calculate/image';
}

function backendApiSendErrorSvg(string $message): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $message = htmlspecialchars($message, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="760" height="220" viewBox="0 0 760 220">
<style>text{font-family:Manrope,"Segoe UI",Arial,sans-serif}</style>
<rect width="760" height="220" fill="#fff"/>
<rect x="20" y="20" width="720" height="180" rx="2" fill="#fff7f7" stroke="#fecaca"/>
<text x="48" y="80" font-size="18" font-weight="700" fill="#dc2626">Fiyat hesaplanamadı</text>
<text x="48" y="122" font-size="14" font-weight="600" fill="#555">' . $message . '</text>
</svg>';

    if (!headers_sent()) {
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: no-store');
        header('Content-Length: ' . strlen($svg));
        http_response_code(200);
    }

    echo $svg;
    exit;
}
