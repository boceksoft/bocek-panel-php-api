<?php

declare(strict_types=1);

/*
 * Uygulama ayarları.
 * Sırlar (DB kullanıcı/şifre) burada DEĞİL; repo dışındaki ../api/config.php
 * dosyasından gelir. Bu dosya repoya girebilir. test4
 */

return [
    // true: ilan detayinda birden fazla kapak fotografi secilebilir; homes.resim'e virgulle yazilir ("a.jpg,b.jpg,c.jpg").
    // false (varsayilan): tek kapak. Villa Kiralama > Config ekranindan degistirilir.
    'homes_two_cover_images' => false,

    // true: villa adi donen yerlerde (ilan listesi, katalog, rezervasyonlar, raporlar...) ad yerine homes.evkodu gosterilir;
    // evkodu bossa ad kullanilir. false (varsayilan): villa adi. Villa Kiralama > Config ekranindan degistirilir.
    'homes_title_use_code' => false,

    // Ilan detayinda (yeni ilan / duzenleme) gosterilecek site-ozel ek homes alanlari. Her panelde olmayan alanlar
    // oldugu icin varsayilan bos; Villa Kiralama > Config ekranindan secilir. Katalog: src/Support/HomesExtraFields.php
    // takvimadi, orj_ad, ekstra, rez_takibi, rez_takip_yeri, rez_takip_yeri_adi, oda_sayisi, m2, kat_sayisi,
    // isitma, klima, otopark, internet, sadece_bizde, kur, fiyat_notu (site sonekli), takvimNotu (site sonekli), harita,
    // takvim_kurallari (rules -> rulesHomes).
    // homes'ta kolonu olmayan alan secilse de gosterilmez/yazilmaz.
    'homes_extra_fields' => [],

    // Ev kodu (homes.evkodu). mode: off (ilan ekraninda alan yok, otomatik yok; varsayilan) | manual (alan var, elle)
    // | auto (alan var; bos birakilirsa kayitta format'a gore verilir, dolu kod ezilmez). format belirtecleri:
    // {id}, {id+N}, {bolge_harf} (emlak bolgesi basliginin ilk harfi), {bolge}, {tip_prefix} (tip.prefix).
    // ovillam: ['mode' => 'auto', 'format' => '{bolge_harf}-{id+1000}'], villavillam: 'VR-{id}'. Bkz. src/Support/HomesCode.php
    'homes_code' => ['mode' => 'off', 'format' => '{bolge_harf}-{id+1000}'],

    // Yeni ilanda bos (0 fiyatli) aylik sezon satirlari (eski panel yeni_emlak_ekle gibi). enabled: ilan ekleme
    // ekraninda "Bos sezon olustur" secenegi (yil + baslangic ayi) gorunur; nights: satirlarin min. gecesi (panel 7).
    // Her site icin acilir, ayni ayla cakisan sezon varsa o ay atlanir. Bkz. src/Support/HomesEmptySeasons.php
    'homes_empty_seasons' => ['enabled' => false, 'nights' => 7],

    // true: villa fotograflari yeni CDN yapisiyla doner: {Cdn}/uploads/property/{boyut}/{villaId}/{dosya}
    // (ilan detayi/listesi, yukleme cevabi, rezervasyonlar, mail resmi, Kiralama Takvimi). Sadece sitenin CDN resizer'i
    // bu yapiyi destekliyorsa ac (bkz. natsisa/docs/devir/resizer). false (varsayilan): /uploads/small/{dosya}.
    'homes_image_property_urls' => false,

    // true: hata detayları yanıta eklenir. Canlıda false olmalı.
    'debug' => true,

    // Silinen rezervasyonun dolu.durum kodu. Çoğu sitede 5; bazı sitelerde -1.
    // -1 kullanan sitede app.local.php içinde 'reservation_deleted_status' => -1 yapılır.
    // API dışarıya her zaman 5 (Silindi) döner ve 5 kabul eder; veritabanına bu değer yazılır/okunur.
    'reservation_deleted_status' => 5,

    // Belge sure tipinin tutuldugu kanun7464 kolonu (ilan listesi filtresi, ilan detayi, belge yonetimi).
    // 'belgeSuresiTipi': 1 suresiz, 2 sureli (varsayilan).
    // 'GavelBelgeSureli': 0 suresiz, 1 sureli. Villa Kiralama > Config ekranindan degistirilir.
    'gavel_duration_column' => 'belgeSuresiTipi',

    // IIS hata sayfalarını yutmasın diye her yanıtı HTTP 200 döndür.
    // Standart HTTP kodlarına dönmek için false yap.
    'force_http_200' => true,

    // CORS: izin verilen origin.
    'cors_origin' => '*',

    // Uygulamanın yayınlandığı alt yol. Kök dizinse '' bırak.
    // Örn: https://web.villakilavuzu.com/backend-api  ->  '/backend-api'
    'base_path' => '/backend-api',

    // Guest movements listesinde homes.id eslesmesi.
    // false: dolu.emlak, true: kayitlar.evid kullanilir.
    'guest_movements_home_id_column' => false,

    // true ise Guest movements listesinde ev sahibi adi/teli bos geldiginde
    // homes.BakimciAd ve homes.BakimciTel fallback olarak kullanilir.
    'guest_movements_use_caretaker_owner_fallback' => false,

    // Link olustururken redirect hedefinde kullanilan arama sayfasi sorgusu.
    // Sorgu ilk satirdaki "url" alanindan arama sayfasi yolunu okumalidir.
    'links_search_page_query' => 'SELECT url FROM sayfalar WHERE id = 229',

    // Doluysa link olustururken DB'den sayfa URL'si okumak yerine direkt bu URL kullanilir.
    // Bos birakilirsa links_search_page_query ile mevcut davranis devam eder.
    // Ornekler: '/arama', 'https://www.site.com/arama'
    'links_search_page_url' => '',

    // Rezervasyon filtrelerinde acenta_users tablosundan alt acentalar cekilsin mi?
    'reservation_filters_use_acenta_users' => false,

    // Logda 3 -> 4 gecisi olan iptal rezervasyonlar icin durum=7 filtresi.
    'reservation_filters_use_status_log' => false,

    // Rezervasyon detayinda islem_kaydi tablosundan belge gecmisi cekilsin mi?
    'reservation_detail_use_islem_kaydi' => true,

    // Homes management ekraninda opsiyonel homes kolonlari varsa true, yoksa false.
    'homes_management_use_showcase_column' => false,
    'homes_management_use_favorite_column' => false,
    'homes_management_use_opportunity_column' => false,

    // Homes management iptal sarti select'i icin sayfalar{dbtable}.id degeri.
    // Siteye gore degisirse app.local.php icinde [1 => 4, 2 => 12] gibi override edilebilir.
    'homes_management_cancellation_policy_page_id' => 4,

    // Rezervasyon bildirimlerindeki ödeme linkleri için sayfalar{dbtable}.id değerleri (iptal şartlarıyla aynı yapı).
    // Siteye göre değişirse app.local.php içinde [1 => 22, 2 => 30] gibi verilebilir. 0 ya da sayfa bulunamazsa
    // genel{dbtable} tablosundaki kartileodeUrl / havaleUrl alanı denenir (ovillam paneli linkleri oradan kurar).
    // Kart: {domain}/{sayfa url}?_={idHash(id)}&authKey={idHash(id_email)} (panel aktif_pasif.asp siparis_1 ile aynı).
    // Havale: {domain}/{sayfa url} (parametresiz) ve aynı parametrelerle havaleParametreli (ovillam: Havale Bildirim Formu).
    'reservation_payment_card_page_id' => 22,
    'reservation_payment_transfer_page_id' => 3,
    // E-postalardaki "İptal Şartlarını okumak için buraya tıklayın" linkinin sayfası (sayfalar{dbtable}.id).
    // Panel bağlantı sorgusu: (Select url from sayfalar{dbtable} where id=24) as iptalurl. 0 ya da bulunamazsa
    // genel{dbtable}.iptalUrl kolonu denenir. Siteye göre app.local.php içinde [1 => 24, 2 => 30] gibi verilebilir.
    'reservation_cancellation_page_id' => 24,
    // Onay e-postasındaki "Rezervasyonlarım" / "Misafir Bilgilerini Doldur" linkinin sayfası (sayfalar.id).
    'reservation_my_reservations_page_id' => 3,

    // Rezervasyon e-postalarındaki panel farkları (reservationdetail mailBilgileri). app.local.php içinde verilir;
    // verilmeyen alt anahtar varsayılanda kalır.
    //   bank_html_style:           'modern' (vv: odeme_bekliyor.txt kutuları) | 'classic' (ovillam:
    //                              odemeBekliyorMailSablonGetir tablo satırları; dış tablo ve başlık şablonda).
    //   active_bank_accounts_only: true ise sadece hesaplar.aktif = 1 olanlar (ovillam paneli böyle).
    //   relative_url_base:         mailsablon metnindeki "../../../../../" yerine yazılan adres (panel UrlDuzenle,
    //                              örn. 'https://web.ovillam.com/'). Boşsa sitenin adresi (genel.domain) kullanılır.
    //   signature_url:             belgelerdeki imza resmi ({{site_imza}}). Tam adres ya da CDN'e göre yol
    //                              ('uploads/imza.jpg' -> https://cdn.site.com/uploads/imza.jpg). Boşsa değişken boş.
    //   payment_row_badges:        ödeme satırlarında "Kredi Kartı - Havale" / "Nakit - Havale" etiketleri (ovillam: true).
    //   cleaning_label:            temizlik satırının adı (ovillam: 'Kısa Süreli K. Ödemesi', garantivillam: 'Temizlik Ücreti').
    //   heated_pool:               ısıtmalı havuz satırının adı ve biçimi (format = true: panel doviz_cevir_format,
    //                              "1.500 TL"). Kolonlar reservation_extras.pool'dan okunur (flag_column yoksa tutar
    //                              boş/0 değilse yazılır). garantivillam: ['label' => 'Havuz Isıtma Ücreti', 'format' => true]
    'reservation_mail' => [
        'bank_html_style' => 'modern',
        'active_bank_accounts_only' => false,
        'relative_url_base' => '',
        'signature_url' => '',
        'payment_row_badges' => true,
        'cleaning_label' => 'Kısa Süreli K. Ödemesi',
        'heated_pool' => ['label' => 'Isıtmalı Havuz Ücreti', 'format' => false],
    ],

    // Rezervasyon detayindaki POS secimi (GET /virtual-pos). Yapi rezervasyona gore secilir:
    // kayitlar.isNewSite = 1 (React site) -> new_site, digerleri -> legacy. Her panel farkli oldugu icin
    // legacy kismi app.local.php icinde siteye gore ayarlanir.
    //   options:         sabit liste [id => 'Ad']; doluysa options_query calismaz.
    //   options_query:   ilk iki kolon (ya da id/name alias'lari) id ve ad olarak okunur.
    //   selected_column: kayitlar'da secili POS'un kolonu ('' = secili yok).
    //   iyzico_column:   kayitlar'daki Iyzico Aktif/Pasif kolonu ('' = gosterilmez).
    // hidden_ids_by_site: rezervasyonda secili degilse listelenmeyen POS id'leri (panel: site 3 -> 1, 2, 3).
    // editable_statuses:  POS'un degistirilebildigi dolu.durum kodlari; 3 + kart odemesi kilitli gosterilir.
    'reservation_pos' => [
        'new_site' => [
            'options' => [],
            'options_query' => 'SELECT Id AS id, Name AS name FROM Finance.VirtualPos ORDER BY Id',
            'selected_column' => 'VirtualPosId',
            'iyzico_column' => '',
        ],
        'legacy' => [
            'options' => [],
            'options_query' => 'SELECT Id AS id, Name AS name FROM Finance.VirtualPos ORDER BY Id',
            'selected_column' => 'VirtualPosId',
            'iyzico_column' => 'iyzico_odeme',
        ],
        'hidden_ids_by_site' => [
            3 => [1, 2, 3],
        ],
        'editable_statuses' => [0, 1, 2, 6],
    ],

    // Rezervasyon ek ücretleri (reservationdetail -> ekUcretler). Kolon/tablo adları panelden panele farklı;
    // farklı panelde app.local.php içinde sadece değişen anahtarlar yazılır (controller varsayılanlarla derin birleştirir).
    //   enabled: 'auto' (kolon/tablo varsa açık) | true | false (panelde yok, gösterilmez)
    //   *_column: kayitlar tablosundaki kolon adı
    //   pool.flag_column: '' ise ayrı "isteniyor" kolonu yok; tutar 0'dan büyükse isteniyor sayılır, istenmezse 0 yazılır.
    //   pool.amount_format: 'money' ("1.500 TL", varsayılan) | 'number' (düz sayı). Kolon sayısal tipteyse her zaman sayı.
    //                     garantivillam: 'pool' => ['flag_column' => '', 'amount_column' => 'isitma', 'amount_format' => 'number']
    //   extra_payments.sources: sırayla denenen tablo/view'lar; ilk bulunan kullanılır.
    //   extra_payments.columns: kaynak kolon adları (id, title, type 0 gecelik/1 konaklama boyu, night, amount, total, symbol)
    'reservation_extras' => [
        'cleaning' => ['enabled' => 'auto', 'amount_column' => 'temizlik'],
        'electricity' => ['enabled' => 'auto', 'amount_column' => 'elektrik'],
        'pool' => ['enabled' => 'auto', 'flag_column' => 'isitmaliHavuzBool', 'amount_column' => 'isitmaliHavuz'],
        'extra_payments' => [
            'enabled' => 'auto',
            'sources' => ['Reservation.vw_ReservationExtraPayments', 'Reservation.ReservationExtraPayments'],
            'reservation_column' => 'ReservationId',
            'columns' => [
                'id' => 'PaymentId',
                'title' => 'Title',
                'type' => 'Type',
                'night' => 'Night',
                'amount' => 'Amount',
                'total' => 'TotalAmount',
                'symbol' => 'Symbol',
                'currency' => 'CurrencyCode',
            ],
        ],
        // Eski sistem: kayitlar.ekucretler = "başlık;/tutar&&başlık;/tutar" (salt okunur).
        'legacy' => ['enabled' => 'auto', 'column' => 'ekucretler', 'item_separator' => '&&', 'field_separator' => ';/'],
    ],

    // Rezervasyon indirimi (reservationdetail -> indirim). Kolon adları panelden panele farklıysa app.local.php'de
    // sadece değişen anahtar yazılır (controller varsayılanlarla derin birleştirir).
    'reservation_discount' => [
        'old_price_column' => 'eskifiyat',          // indirimsiz fiyat
        'total_column' => 'toplam_tutar',           // indirimli fiyat
        'promo_column' => 'promotionCode',
        'owner_column' => 'indirimevsahibi',        // 1: indirimi ev sahibi karşılar, 0: site sahibi
        'commission_column' => 'kazancorani',
        'villa_commission_column' => 'kazancorani', // rezervasyonda oran yoksa villanın (homes, site sonekli) oranı
        'villa_down_payment_column' => 'depozito',  // villanın ön ödeme oranı (homes; site_field_suffixes.depozito)
        'promo_table' => 'promotionCodes',
        'promo_columns' => [
            'code' => 'code', 'value' => 'value', 'is_price' => 'isPrice',
            'stock' => 'stock', 'start' => 'startDate', 'end' => 'endDate',
        ],
    ],

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

    // Link olusturma endpoint'inin redirectTo query parametreleri.
    // Sayfa yolu links_search_page_query ile DB'den gelir; burada path yoktur.
    'links_reservation_url' => [
        'params' => [
            'entity_id' => 'ids',
            'start' => 'start',
            'end' => 'end',
        ],
        'date_format' => 'd-m-y',
    ],
    'links_site_domain_queries' => [
        2 => 'SELECT TOP 1 domain FROM genel_s2',
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

    // true ise fiyat/sezon/indirim/son dakika hesaplari her site icin site=1 uzerinden yapilir.
    // false ise hesaplama istekte gelen site id ile yapilir.
    'calculate_prices_same_across_sites' => true,

    // true ise sezonlar.isitmaFiyat haftalik kabul edilir ve gunluk hesap icin 7'ye bolunur.
    // false ise sezonlar.isitmaFiyat gunluk fiyat kabul edilir.
    'calculate_heating_price_is_weekly' => false,

    // Homes endpoint'inde siteye gore URL/foto domain'i.
    // Sorgu ilk satirda "domain" alias'i dondurmelidir.
    'homes_site_domain_queries' => [
        2 => 'SELECT TOP 1 domain FROM genel_s2',
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

    // Panelden app.local.php icine yazilabilecek site-ozel ayarlar.
    // deploy_secret, github_token ve DB bilgileri bilerek burada yok.
    'editable_local_settings' => [
        'homes_two_cover_images' => 'bool',
        'homes_title_use_code' => 'bool',
        'homes_extra_fields' => 'array',
        'homes_code' => 'array',
        'homes_empty_seasons' => 'array',
        'homes_image_property_urls' => 'bool',
        'guest_movements_home_id_column' => 'bool',
        'guest_movements_use_caretaker_owner_fallback' => 'bool',
        'links_search_page_url' => 'string',
        'reservation_filters_use_acenta_users' => 'bool',
        'reservation_filters_use_status_log' => 'bool',
        'reservation_detail_use_islem_kaydi' => 'bool',
        'calculate_param_names' => 'array',
        'calculate_date_formats' => 'array',
        'calculate_reservation_url' => 'array',
        'links_reservation_url' => 'array',
        'site_column_suffixes' => 'array',
        'site_languages' => 'array',
        'calculate_prices_same_across_sites' => 'bool',
        'calculate_heating_price_is_weekly' => 'bool',
        'reservation_pos' => 'array',
        'reservation_extras' => 'array',
        'reservation_discount' => 'array',
        'reservation_mail' => 'array',
        'gavel_duration_column' => 'string',
    ],

    // DB bilgileri ($config['db']) ve Domain sabitinin geldiaği,
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
