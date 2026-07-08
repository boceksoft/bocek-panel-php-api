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

// Endpoint sürümleri (git hook üretir): her satır "kaynak sürüm".
$versions = [];
$versionsFile = $backendRoot . '/versions.txt';
if (is_file($versionsFile)) {
    foreach (file($versionsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $parts = explode(' ', trim($line), 2);
        if ($parts[0] !== '') {
            $versions[$parts[0]] = (int) ($parts[1] ?? 0);
        }
    }
}

return [
    'app'      => $app,
    'db'       => $config['db'] ?? [],
    'versions' => $versions,
];
