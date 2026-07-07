<?php

declare(strict_types=1);

/*
 * Uygulama ayarları.
 * Sırlar (DB kullanıcı/şifre) burada DEĞİL; repo dışındaki ../api/config.php
 * dosyasından gelir. Bu dosya repoya girebilir.
 */

return [
    // true: hata detayları yanıta eklenir. Canlıda false olmalı.
    'debug' => true,

    // IIS hata sayfalarını yutmasın diye her yanıtı HTTP 200 döndür.
    // Standart HTTP kodlarına dönmek için false yap.
    'force_http_200' => false,

    // CORS: izin verilen origin.
    'cors_origin' => '*',

    // Uygulamanın yayınlandığı alt yol. Kök dizinse '' bırak.
    // Örn: https://web.villakilavuzu.com/backend-api  ->  '/backend-api'
    'base_path' => '/backend-api',

    // IP beyaz liste. Boş bırakılırsa IP kontrolü KAPALI olur.
    'allowed_ips' => [
        '31.210.157.219', // natsisa
        '78.189.74.10',
    ],

    // Bearer token doğrulaması açık mı? Test için false yapabilirsin.
    'auth_enabled' => true,

    // Auth GEREKTİRMEYEN kaynaklar (ilk yol segmenti). tokens = token üretimi.
    'public_resources' => ['tokens'],

    // DB bilgileri ($config['db']) ve Domain sabitinin geldiği,
    // repo dışındaki config dosyasının yolu (backend-api/../api/config.php).
    'external_config_path' => dirname(__DIR__, 2) . '/api/config.php',
];
