<?php

declare(strict_types=1);

use App\Support\OpenApiGenerator;

/*
 * OpenAPI (Swagger) dokümanını JSON olarak üretir.
 * Not: Bu uç auth GEREKTİRMEZ (yalnızca uç/parametre listesini yayınlar, veri döndürmez).
 * Gerçek dosya olduğu için web.config rewrite'a takılmaz, doğrudan çalışır.
 */

$context = require __DIR__ . '/src/Support/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// base_path OpenAPI "servers" alanına yazılır; Swagger istekleri /backend-api/... olur.
$generator = new OpenApiGenerator(__DIR__ . '/src/Controller', $context['app']['base_path']);

echo json_encode($generator->generate(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
