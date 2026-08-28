<?php
// ─────────────────────────────────────────────────────────────────────────────
// Başlıklar ve Güvenlik
// ─────────────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/backend-api-config.php';

// IIS'in araya girmemesi için HTTP kodunu 200 tutuyoruz
http_response_code(200);

// ─────────────────────────────────────────────────────────────────────────────
// 1. Gelen ID Kontrolü
// ─────────────────────────────────────────────────────────────────────────────
if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Lütfen geçerli bir teklif ID numarası gönderin.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$offerId = (int)$_GET['id'];
$siteId = null;
foreach (array('site', 'Site', 'site_id', 'SiteId', 'siteId', 'currentSite', 'currentSiteId') as $siteKey) {
    if (isset($_GET[$siteKey]) && is_numeric($_GET[$siteKey]) && (int)$_GET[$siteKey] > 0) {
        $siteId = (int)$_GET[$siteKey];
        break;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. Veritabanı İşlemleri
// ─────────────────────────────────────────────────────────────────────────────
try {
    $sql = "SELECT 
                id, 
                isim, 
                email, 
                telefon, 
                kisi, 
                parametreler, 
                createdOn, 
                site, 
                link 
            FROM dbo.teklifler 
            WHERE id = :id";
    if ($siteId !== null) {
        $sql .= " AND site = :site";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':id', $offerId, PDO::PARAM_INT);
    if ($siteId !== null) {
        $stmt->bindParam(':site', $siteId, PDO::PARAM_INT);
    }
    $stmt->execute();

    $offer = $stmt->fetch();

    if (!$offer) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Belirtilen ID ile eşleşen bir teklif bulunamadı.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // 3. Parametreleri Arka Planda Parçalama (Hesaplamalar İçin)
    // ─────────────────────────────────────────────────────────────────────────────
    $parsedParams = [];

    if (!empty($offer['parametreler'])) {
        parse_str($offer['parametreler'], $parsedParams);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // 4. Yetişkin / Çocuk Kişi Sayısı Mantığı
    // ─────────────────────────────────────────────────────────────────────────────
    $yetiskin = (isset($parsedParams['adult']) && $parsedParams['adult'] !== "") ? (int)$parsedParams['adult'] :
        ((isset($parsedParams['yetiskin']) && $parsedParams['yetiskin'] !== "") ? (int)$parsedParams['yetiskin'] :
            (!empty($offer['kisi']) ? (int)$offer['kisi'] : 0));

    $cocuk = (isset($parsedParams['child']) && $parsedParams['child'] !== "") ? (int)$parsedParams['child'] :
        ((isset($parsedParams['cocuk']) && $parsedParams['cocuk'] !== "") ? (int)$parsedParams['cocuk'] : 0);

    $bebek = (isset($parsedParams['infant']) && $parsedParams['infant'] !== "") ? (int)$parsedParams['infant'] : 0;

    $toplamKisi = $yetiskin + $cocuk;

    // ─────────────────────────────────────────────────────────────────────────────
    // 5. Tarih Mantığı
    // ─────────────────────────────────────────────────────────────────────────────
    $baslangic = isset($parsedParams['start']) && $parsedParams['start'] !== "" ? $parsedParams['start'] :
        (isset($parsedParams['searchdate1']) && $parsedParams['searchdate1'] !== "" ? $parsedParams['searchdate1'] : null);

    $bitis = isset($parsedParams['end']) && $parsedParams['end'] !== "" ? $parsedParams['end'] :
        (isset($parsedParams['searchdate2']) && $parsedParams['searchdate2'] !== "" ? $parsedParams['searchdate2'] : null);

    // ─────────────────────────────────────────────────────────────────────────────
    // 6. Bütçe (Fiyat Tipi) Hesaplama Mantığı
    // ─────────────────────────────────────────────────────────────────────────────
    $priceType = isset($parsedParams['priceType']) ? (int)$parsedParams['priceType'] : (isset($parsedParams['pricetype']) ? (int)$parsedParams['pricetype'] : 0);
    $min       = isset($parsedParams['min']) ? (float)$parsedParams['min'] : 0;
    $max       = isset($parsedParams['max']) ? (float)$parsedParams['max'] : 0;

    $hedefKur = 1;

    if ($priceType === 1) {
        $minTL = 1;
        $maxTL = 20000;
    } elseif ($priceType === 2) {
        $minTL = 20000;
        $maxTL = 50000;
    } elseif ($priceType === 3) {
        $minTL = 50000;
        $maxTL = 100000;
    } else {
        $minTL = (int)round($min * $hedefKur);
        $maxTL = (int)round($max * $hedefKur);
    }

    if ($maxTL > 0) {
        $butce = $minTL > 0 ? "$minTL - $maxTL" : (string)$maxTL;
    } else {
        $butce = null;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // 7. Kategori ve Tip Mantığı
    // ─────────────────────────────────────────────────────────────────────────────
    $tipBasliklari = [];
    $kategorilerDizisi = [];

    if (isset($parsedParams['categories']) && is_array($parsedParams['categories'])) {
        $kategorilerDizisi = array_map('intval', $parsedParams['categories']);
    }

    if (!empty($parsedParams['tip'])) {
        $tipIds = array_filter(array_map('intval', explode(',', $parsedParams['tip'])));

        if (!empty($tipIds)) {
            $inQuery = implode(',', array_fill(0, count($tipIds), '?'));
            $stmtTip = $pdo->prepare("SELECT id, baslik FROM dbo.tip WHERE id IN ($inQuery)");
            $stmtTip->execute(array_values($tipIds));

            $tipRows = $stmtTip->fetchAll();
            foreach ($tipRows as $row) {
                $tipBasliklari[] = [
                    'id'     => $row['id'],
                    'baslik' => $row['baslik']
                ];
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // 8. Yanıt Formatını Hazırlama
    // ─────────────────────────────────────────────────────────────────────────────
    $responseData = [
        'id'             => $offer['id'],
        'isim'           => $offer['isim'],
        'email'          => $offer['email'],
        'telefon'        => $offer['telefon'],
        'tarihler'       => [
            'baslangic' => $baslangic,
            'bitis'     => $bitis
        ],
        'kisi'           => $toplamKisi,
        'kisiBilgileri'  => [
            'yetiskin' => $yetiskin,
            'cocuk'    => $cocuk,
            'bebek'    => $bebek
        ],
        'butce'          => $butce,
        'kategoriler'    => $kategorilerDizisi,
        'secilenTipler'  => $tipBasliklari,
        'createdOn'      => $offer['createdOn'],
        'site'           => $offer['site'],
        'link'           => $offer['link'],
        // BURASI DEĞİŞTİ: Artık parçalanmış diziyi değil, doğrudan veritabanındaki ham stringi basacak
        'parametreler'   => $offer['parametreler']
    ];

    echo json_encode([
        'status' => 'success',
        'data'   => $responseData
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Veritabanı bağlantısı veya sorgu başarısız.',
        'debug'   => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
