<?php
// Olası yol/dizin hatalarını sayfada görebilmek için (Canlıda bunları 0 yapabilirsin)
ini_set('display_errors', 0);
error_reporting(0);

// config.php dosyasını çağır (Yolun doğru olduğundan emin ol)
require_once __DIR__ . '/../api/config.php';

$backendApiAppConfig = require __DIR__ . '/config/app.php';
$backendApiLocalConfigPath = __DIR__ . '/config/app.local.php';
if (is_file($backendApiLocalConfigPath)) {
    $backendApiAppConfig = array_merge($backendApiAppConfig, require $backendApiLocalConfigPath);
}

header('Content-Type: application/json; charset=utf-8');

// ─────────────────────────────────────────────────────────────────────────────
// 1. IP KONTROLÜ (BEYAZ LİSTE / WHITELIST)
// ─────────────────────────────────────────────────────────────────────────────
$allowed_ips = [
    '31.210.157.219',//natsisa
    '78.189.74.10'
];

function getClientIp() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    } else {
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
}

$client_ip = getClientIp();

if (!in_array($client_ip, $allowed_ips)) {
    // IIS araya girmesin diye 403 yerine 200 veriyoruz
    http_response_code(200);
    echo json_encode(['success' => false, 'error' => 'Erişim reddedildi. Yetkisiz IP adresi.', 'ip' => $client_ip]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. VERİTABANI BAĞLANTISI (PDO)
// ─────────────────────────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        'sqlsrv:server=' . $config['db']['host'] . ';database=' . $config['db']['name'],
        $config['db']['user'],
        $config['db']['pass']
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    http_response_code(200); // 500 yerine 200
    echo json_encode(['success' => false, 'error' => 'Veritabanı bağlantısı başarısız.', 'detail' => $e->getMessage()]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. AUTH TOKEN KONTROLÜ (Bearer Token)
// ─────────────────────────────────────────────────────────────────────────────
$headers = null;
if (isset($_SERVER['Authorization'])) {
    $headers = trim($_SERVER["Authorization"]);
} else if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
} elseif (function_exists('apache_request_headers')) {
    $requestHeaders = apache_request_headers();
    $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
    if (isset($requestHeaders['Authorization'])) {
        $headers = trim($requestHeaders['Authorization']);
    }
}

$token = null;
if (!empty($headers)) {
    if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
        $token = $matches[1];
    }
}

// Token gönderilmemişse
if (!$token) {
    http_response_code(200); // 401 yerine 200
    echo json_encode(['success' => false, 'error' => 'Yetkilendirme reddedildi. Token bulunamadı.']);
    exit;
}

// Token'ı veritabanında doğrula
try {
    $tokenStmt = $pdo->prepare("
        SELECT TokenId 
        FROM dbo.AuthToken 
        WHERE Token = :token 
          AND UserId = 0 
          AND IsDeleted = 0 
          AND ExpireDate > GETDATE()
    ");
    $tokenStmt->execute([':token' => $token]);
    $validTokenRow = $tokenStmt->fetch();

    if (!$validTokenRow) {
        http_response_code(200); // 401 yerine 200
        echo json_encode(['success' => false, 'error' => 'Geçersiz veya süresi dolmuş token.']);
        exit;
    }

} catch (PDOException $e) {
    http_response_code(200); // 500 yerine 200
    echo json_encode(['success' => false, 'error' => 'Token doğrulama sırasında bir hata oluştu.', 'detail' => $e->getMessage()]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// 4. MEVCUT ESKİ KODLAR (URL ÇEKME)
// ─────────────────────────────────────────────────────────────────────────────
try {
    $searchPageQuery = trim((string) ($backendApiAppConfig['links_search_page_query'] ?? 'SELECT url FROM tip WHERE id = 1'));
    $stmt = $pdo->query($searchPageQuery);
    $row = $stmt->fetch();

    $aramaSayfasiUrl = $row ? $row['url'] : '';

} catch (PDOException $e) {
    http_response_code(200); // 500 yerine 200
    echo json_encode(['success' => false, 'error' => 'URL çekilemedi.', 'detail' => $e->getMessage()]);
    exit;
}

// Bu dosya sonlandığında $pdo ve $aramaSayfasiUrl değişkenleri
// villaAra.php (3. dosyan) tarafından kullanılmaya hazır olacak.
?>
