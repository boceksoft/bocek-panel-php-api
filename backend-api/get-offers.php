<?php
// API Headers (JSON çıktısı ve CORS ayarları)
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

// 1. Merkezi yapılandırma ve Veritabanı bağlantısı
require_once __DIR__ . '/backend-api-config.php';

try {
    // IIS'in araya girmemesi için HTTP kodunu 200 tutuyoruz
    http_response_code(200);

    // 2. Parametre Kontrolleri: Site, Sayfa (page) ve Sayfa Başına Kayıt (per_page)
    $siteId = defined('PRICE_SITE') ? max(1, (int)PRICE_SITE) : 1;
    foreach (array('site', 'Site', 'site_id', 'SiteId', 'siteId', 'currentSite', 'currentSiteId') as $siteKey) {
        if (isset($_GET[$siteKey]) && is_numeric($_GET[$siteKey]) && (int)$_GET[$siteKey] > 0) {
            $siteId = (int)$_GET[$siteKey];
            break;
        }
    }
    $page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $perPage = isset($_GET['per_page']) ? max(1, (int)$_GET['per_page']) : 50;

    // Atlanacak kayıt sayısını hesapla
    $offset  = ($page - 1) * $perPage;

    // 3. SQL Sorgusu (COUNT(*) OVER() ile toplam kayıt sayısını da tek sorguda alıyoruz)
    $sql = "SELECT 
                COUNT(*) OVER() AS totalCount,
                t.id, 
                t.isim, 
                t.email, 
                t.telefon, 
                t.kisi, 
                t.parametreler, 
                t.createdOn, 
                t.site, 
                t.link,
                (CASE 
                    WHEN EXISTS (SELECT 1 FROM dbo.redirects r WHERE r.teklifId = t.id) 
                    THEN 'Cevaplandı' 
                    ELSE 'Yeni' 
                END) AS durum
            FROM dbo.teklifler t
            WHERE t.site = :site 
            ORDER BY t.createdOn DESC
            OFFSET :offset ROWS FETCH NEXT :perPage ROWS ONLY";

    $stmt = $pdo->prepare($sql);

    // SQL Server'da OFFSET kullanırken parametrelerin INT olması zorunludur
    $stmt->bindParam(':site', $siteId, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindParam(':perPage', $perPage, PDO::PARAM_INT);

    $stmt->execute();

    // 4. Verileri Çek
    $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Toplam Sayı Mantığı
    $totalCount = 0;
    if (count($offers) > 0) {
        $totalCount = (int)$offers[0]['totalCount'];
    }

    // JSON çıktısı temiz olsun diye her bir satırdaki totalCount bilgisini siliyoruz
    foreach ($offers as &$offer) {
        unset($offer['totalCount']);
    }
    unset($offer); // Referansı kır (Olası bugları önler)

    // 6. Başarılı JSON Yanıtı
    echo json_encode([
        'status'      => 'success',
        'total'       => $totalCount,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => (int)ceil($totalCount / $perPage),
        'count'       => count($offers),
        'data'        => $offers
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    // 7. Hata Durumu
    echo json_encode([
        'status'  => 'error',
        'message' => 'Veritabanı bağlantısı veya sorgu başarısız.',
        'debug'   => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
