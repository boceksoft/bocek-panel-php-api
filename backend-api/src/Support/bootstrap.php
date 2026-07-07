<?php

declare(strict_types=1);

use App\Core\Autoloader;

/*
 * Uygulamayı ayağa kaldırır: autoloader'ı kaydeder, ayarları ve repo dışındaki
 * DB config'ini yükler. Geriye uygulama bağlamını (app + db) döndürür.
 */

$backendRoot = dirname(__DIR__, 2); // backend-api/

require_once $backendRoot . '/src/Core/Autoloader.php';
Autoloader::register($backendRoot . '/src');

/** @var array $app */
$app = require $backendRoot . '/config/app.php';

// Repo dışındaki config: $config['db'] ve Domain sabitini tanımlar.
$config = [];
if (is_file($app['external_config_path'])) {
    require $app['external_config_path'];
}

return [
    'app' => $app,
    'db'  => $config['db'] ?? [],
];
