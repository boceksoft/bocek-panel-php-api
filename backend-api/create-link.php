<?php
/*
 * Teklif (Kısa Link) Oluşturma API Endpoint'i
 * Kullanım: POST /create-link.php
 * * Beklenen Body (JSON):
 * {
 * "ids": [5648, 1234, 9876],
 * "start": "2026-06-16",
 * "end": "2026-06-23",
 * "sure": 3,
 * "teklifId": 949715
 * }
 */

header('Content-Type: text/plain; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Veritabanı Bağlantısı
// ─────────────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/backend-api-config.php';

// ─────────────────────────────────────────────────────────────────────────────
// 4 Haneli Rastgele Metin Üretici
// ─────────────────────────────────────────────────────────────────────────────
function generateRandomString($length = 4) {
    $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $randomString;
}

// ─────────────────────────────────────────────────────────────────────────────
// Gelen JSON Veriyi Okuma
// ─────────────────────────────────────────────────────────────────────────────
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

if (!$input) {
    $input = $_POST;
}

$ids      = isset($input['ids']) && is_array($input['ids']) ? $input['ids'] : array();
$start    = isset($input['start']) ? trim($input['start']) : '';
$end      = isset($input['end']) ? trim($input['end']) : '';
$sure     = isset($input['sure']) ? (int)$input['sure'] : 0;

// C# veya Frontend'den gelen teklifId değerini alıyoruz
$teklifId = !empty($input['teklifId']) ? (int)$input['teklifId'] : null;

$validIds = array_filter(array_map('intval', $ids), function($v) { return $v > 0; });

if (empty($validIds)) {
    die("1;/Lütfen listeden en az bir villa seçiniz.");
}

// ─────────────────────────────────────────────────────────────────────────────
// Link İnşa Etme ve Veritabanı Kaydı
// ─────────────────────────────────────────────────────────────────────────────
try {
    $stmtId = $pdo->query("SELECT ISNULL(MAX(id), 0) + 1 AS nextId FROM redirects");
    $rowId = $stmtId->fetch();
    $nextId = $rowId['nextId'];

    $originalLink = generateRandomString(4) . $nextId;

    // Domain config.php dosyasındaki sabitten çekiliyor
    $domain = Domain;

    // Arama sayfasını veritabanından çekme sorgusu
    $aramaSayfasi = $aramaSayfasiUrl;

    $queryParams = array();
    $queryParams['ids'] = implode(',', $validIds);
    if ($start !== '') $queryParams['start'] = $start;
    if ($end !== '')   $queryParams['end'] = $end;
    if ($sure > 0)     $queryParams['sure'] = $sure;

    $queryString = http_build_query($queryParams);

    $redirectTo = $domain . "/" . $aramaSayfasi . "?" . urldecode($queryString);

    $useExpirationColumns = isset($backendApiAppConfig['links_use_expiration_columns'])
        ? (bool)$backendApiAppConfig['links_use_expiration_columns']
        : true;

    $expiredMode = 0;
    $expiredDate = null;

    if ($sure > 0) {
        $expiredMode = 1;
        $date = new DateTime();
        $date->modify("+{$sure} days");
        $expiredDate = $date->format('Y-m-d H:i:s');
    }

    // Geçersiz kolon olan userId sorgudan tamamen kaldırıldı, sadece teklifId yazılıyor
    $insertColumns = "originalLink, teklifId, redirectTo";
    $insertValues = ":originalLink, :teklifId, :redirectTo";
    $insertParams = array(
        ':originalLink' => $originalLink,
        ':teklifId'     => $teklifId,
        ':redirectTo'   => $redirectTo
    );

    if ($useExpirationColumns) {
        $insertColumns .= ", expiredDate, expiredMode";
        $insertValues .= ", :expiredDate, :expiredMode";
        $insertParams[':expiredDate'] = $expiredDate;
        $insertParams[':expiredMode'] = $expiredMode;
    }

    $sqlInsert = "INSERT INTO redirects (" . $insertColumns . ") VALUES (" . $insertValues . ")";

    $stmtInsert = $pdo->prepare($sqlInsert);
    $stmtInsert->execute($insertParams);

    $finalLink = str_replace("www.", "", $domain) . "/" . $originalLink . "?v";

    echo $finalLink;

} catch (Exception $e) {
    echo "Sunucu Hatası: " . $e->getMessage();
}
?>
