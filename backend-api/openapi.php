<?php

declare(strict_types=1);

use App\Support\OpenApiGenerator;

/*
 * OpenAPI (Swagger) dokümanını JSON olarak üretir.
 * Not: Bu uç auth GEREKTİRMEZ (yalnızca uç/parametre listesini yayınlar, veri döndürmez).
 * Gerçek dosya olduğu için web.config rewrite'a takılmaz, doğrudan çalışır.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Canlıda display_errors kapalı olduğu için ölümcül hata IIS'in genel 500 sayfasına
// dönüşüyordu. Hata olursa sebebini JSON olarak yaz (yalnızca mesaj + dosya:satır).
ini_set('display_errors', '0');
register_shutdown_function(static function (): void {
    $error = error_get_last();
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if ($error === null || !in_array($error['type'], $fatal, true)) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode([
        'success' => false,
        'error' => [
            'message' => $error['message'],
            'file' => str_replace(__DIR__, '', $error['file']),
            'line' => $error['line'],
            'code' => 'OPENAPI_FATAL',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
});

$context = require __DIR__ . '/src/Support/bootstrap.php';

// base_path OpenAPI "servers" alanına yazılır; Swagger istekleri /backend-api/... olur.
$generator = new OpenApiGenerator(__DIR__ . '/src/Controller', $context['app']['base_path']);

try {
    $json = json_encode($generator->generate(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR);
} catch (\Throwable $e) {
    http_response_code(500);
    $json = json_encode([
        'success' => false,
        'error' => [
            'message' => get_class($e) . ': ' . $e->getMessage(),
            'file' => str_replace(__DIR__, '', $e->getFile()),
            'line' => $e->getLine(),
            'code' => 'OPENAPI_ERROR',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

echo $json;
