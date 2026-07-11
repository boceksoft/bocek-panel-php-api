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
    'force_http_200' => true,

    // CORS: izin verilen origin.
    'cors_origin' => '*',

    // Uygulamanın yayınlandığı alt yol. Kök dizinse '' bırak.
    // Örn: https://web.villakilavuzu.com/backend-api  ->  '/backend-api'
    'base_path' => '/backend-api',

    // dolu tablosunda kayitlar.id ile eslesen kolon adi.
    // Bazi SQL Server kurulumlari case-sensitive oldugu icin siteye gore override edilebilir.
    'dolu_kayit_id_column' => 'kayitid',

    // ruleshomes tablosunda rules.id ile eslesen kolon adi.
    'ruleshomes_rules_id_column' => 'rulesId',

    // ruleshomes tablosunda homes.id ile eslesen kolon adi.
    'ruleshomes_homes_id_column' => 'homesId',

    // redirects tablosunda expiredDate/expiredMode kolonlari varsa true, yoksa false.
    'links_use_expiration_columns' => false,

    // Link olustururken redirect hedefinde kullanilan arama sayfasi sorgusu.
    // Sorgu ilk satirdaki "url" alanindan arama sayfasi yolunu okumalidir.
    'links_search_page_query' => 'SELECT url FROM sayfalar WHERE id = 229',

    // Rezervasyon filtrelerinde acenta_users tablosundan alt acentalar cekilsin mi?
    'reservation_filters_use_acenta_users' => false,

    // IP beyaz liste. Boş bırakılırsa IP kontrolü KAPALI olur.
    'allowed_ips' => [
        '31.210.157.219', // natsisa
        '78.189.74.10',
    ],

    // Bearer token doğrulaması açık mı? Test için false yapabilirsin.
    'auth_enabled' => true,

    // Auth GEREKTİRMEYEN kaynaklar (ilk yol segmenti). tokens = token üretimi,
    // update = otomatik güncelleme (kendi "deploy_secret" ile korunur),
    // version = kurulu sürümü gösterir (salt okunur, sır istemez).
    'public_resources' => ['tokens', 'update', 'version'],

    // DB bilgileri ($config['db']) ve Domain sabitinin geldiği,
    // repo dışındaki config dosyasının yolu (backend-api/../api/config.php).
    'external_config_path' => dirname(__DIR__, 2) . '/api/config.php',

    // ── Otomatik güncelleme (backend-api/update) ────────────────────────────
    // Sır değildir, repoda kalabilir. Sırlar (deploy_secret, github_token)
    // config/app.local.php dosyasından gelir (bkz. app.local.php.example).
    'github_owner'  => 'boceksoft',
    'github_repo'   => 'bocek-panel-php-api',
    'github_branch' => 'main',

    // İndirilen zip'in geçici olarak açıldığı, YAZILABİLİR bir klasör.
    // Site yapısı farklıysa (örn. uploads/ başka bir subdomain'in altındaysa)
    // config/app.local.php içinde override et — her sitede değişebilir,
    // bu yüzden burada sadece en yaygın düzen için bir VARSAYILAN var.
    'git_temp_dir' => dirname(__DIR__, 2) . '/uploads/git-temp',
];
