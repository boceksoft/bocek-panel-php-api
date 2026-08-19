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

    // Guest movements listesinde homes.id ile eslesen kolon.
    // Varsayilan dolu.emlak'tir. Bazi sitelerde kayitlar.evid kullanilirsa
    // app.local.php icinde 'k.evid' olarak override edilebilir.
    'guest_movements_home_id_column' => 'd.emlak',

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

    // Rezervasyon detayinda islem_kaydi tablosundan belge gecmisi cekilsin mi?
    'reservation_detail_use_islem_kaydi' => true,

    // Homes management ekraninda opsiyonel homes kolonlari varsa true, yoksa false.
    'homes_management_use_showcase_column' => false,
    'homes_management_use_favorite_column' => false,
    'homes_management_use_opportunity_column' => false,

    // Homes management iptal sarti select'i icin sayfalar{dbtable}.id degeri.
    // Siteye gore degisirse app.local.php icinde [1 => 4, 2 => 12] gibi override edilebilir.
    'homes_management_cancellation_policy_page_id' => 4,

    // Calculate endpoint parametre adlari ve kabul edilen tarih formatlari.
    // Eski sitelerde app.local.php icinde sadece bu alanlari override ederek
    // ProductId/checkin/checkout veya 17.09.2026, 2026-09-17 gibi varyasyonlar desteklenir.
    'calculate_param_names' => [
        'entity_id' => ['EntityId', 'id', 'ProductId'],
        'start' => ['start', 'searchdate1', 'checkin', 'date1'],
        'end' => ['end', 'searchdate2', 'checkout', 'date2'],
    ],
    'calculate_date_formats' => [
        'Y-m-d',
        'Y.m.d',
        'd.m.Y',
        'd-m-Y',
        'm/d/Y',
    ],
    'calculate_reservation_url' => [
        'path' => '/rezervasyon',
        'params' => [
            'entity_id' => 'id',
            'start' => 'reservationdate1',
            'end' => 'reservationdate2',
            'pool_fee' => 'buyPool',
        ],
        // Ornekler: Y-m-d => 2026-09-17, Y.m.d => 2026.09.17,
        // d-m-Y => 17-09-2026, d.m.Y => 17.09.2026
        'date_format' => 'd.m.Y',
    ],

    // HTML'i PNG/JPG image'a cevirmek icin wkhtmltoimage binary yolu.
    // Sunucuya gore app.local.php icinde override edilmelidir.
    // Proje ici Windows: backend-api/bin/wkhtmltoimage.exe
    // Proje ici Linux: backend-api/bin/wkhtmltoimage
    // Ornek Windows: C:/Program Files/wkhtmltopdf/bin/wkhtmltoimage.exe
    // Ornek Linux: /usr/bin/wkhtmltoimage
    'html_to_image_binary' => '',

    // Siteye gore kolon suffix'i. app.local.php icinde projeye gore override edilebilir.
    'site_column_suffixes' => [
        1 => '',
        2 => '_s2',
        3 => '_s3',
    ],

    // Tekil kolonlar genel suffix'ten farkliysa burada override edilebilir.
    // Ornek: [2 => ['doviz' => '', 'kur' => '_s2']]
    'site_field_suffixes' => [
        3 => [
            'depozito' => '',
        ],
    ],

    // IP beyaz liste. Boş bırakılırsa IP kontrolü KAPALI olur.
    'allowed_ips' => [
        '31.210.157.219', // natsisa
        '78.189.74.10',
        '37.247.103.149'// natsisa
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
