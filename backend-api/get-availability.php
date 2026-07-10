<?php
// Gelişmiş CORS (Cross-Origin Resource Sharing) Ayarları
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Tarayıcı "Preflight" (Ön Kontrol) isteği attığında scripti burada sonlandır ve 200 dön
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json; charset=utf-8');

// 1. Merkezi yapılandırma ve Veritabanı bağlantısı (Sadece backend-api-config.php kullanılıyor)
require_once __DIR__ . '/backend-api-config.php';

try {
    // IIS'in araya girmemesi için HTTP kodunu 200 tutuyoruz
    http_response_code(200);

    // GET Parametreleri
    $EntityId = isset($_GET['EntityId']) ? (int)$_GET['EntityId'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

    // Eski $config yerine defined() kontrolü yapıyoruz. (Tanımlı değilse varsayılan 1 olur)
    $DefaultCurrencyId = defined('DEFAULT_CURRENCY_ID') ? DEFAULT_CURRENCY_ID : 1;

    if ($EntityId === 0) {
        throw new Exception("EntityId belirtilmedi.");
    }

    // --- HOME BİLGİSİ ---
    $uzanti = defined('UZANTI') ? UZANTI : '';
    $query = $pdo->prepare("SELECT h.id, h.doviz" . $uzanti . " AS doviz, ToC.Symbol 
                            FROM homes h 
                            INNER JOIN Finance.Currency ToC ON ToC.CurrencyId = :DefaultCurrencyId 
                            WHERE h.id = :id");
    $query->execute(["id" => $EntityId, "DefaultCurrencyId" => $DefaultCurrencyId]);
    $home = $query->fetch();

    if (!$home) {
        throw new Exception("Kayıt bulunamadı. EntityId: " . $EntityId);
    }

    // --- SONDAKİKA VE DİĞER TARİH AYARLARI ---
    $explodeWhere = "";

    // Eski koddaki gibi tarih1/tarih2 convert işlemlerini 103 formatında (dd/mm/yyyy) güncelledik
    $doluTarihlerSql = "SELECT 
                        ISNULL(STRING_AGG(CONCAT(YEAR(tarih) ,'-' ,FORMAT(tarih,'MM'),'-', FORMAT(tarih,'dd')),','),'') AS doluGirisler,
                        ISNULL(STRING_AGG(CONCAT(YEAR(tarih2) ,'-' ,FORMAT(tarih2,'MM'),'-', FORMAT(tarih2,'dd')),','),'') AS doluCikislar,
                        ISNULL(STRING_AGG(dbo.Fn_aratarihler2(tarih,tarih2) ,','),'') AS doluGunler 
                        FROM dolu WHERE CONVERT(date,tarih2,103) > CONVERT(date,GETDATE(),103) AND durum = 3 AND emlak = " . $EntityId;

    // --- ODA TİPİ KONTROLÜ (HOTEL AVAILABILITY) ---
    $doluTarihlerSqlWith = "";
    $query = $pdo->prepare("SELECT * FROM KiralamaTakvimi.CalendarHomes WHERE homesId = :homesId");
    $query->execute(["homesId" => $EntityId]);
    $CalendarHomes = $query->fetch();

    if ($CalendarHomes && isset($CalendarHomes["RoomType"]) && $CalendarHomes["RoomType"] == "1") {
        $doluTarihlerSqlWith = "
            WITH DoluGunler AS ( 
                SELECT Date FROM KiralamaTakvimi.HotelAvailabilityRooms
                WHERE (RoomCount = 0 OR IsClosed = 1) AND EstateId = ".$CalendarHomes["EstateId"]." 
                UNION 
                SELECT DATEADD(DAY, 1, dg1.Date) FROM KiralamaTakvimi.HotelAvailabilityRooms dg1 
                WHERE ((dg1.RoomCount = 0 OR dg1.IsClosed = 1) AND dg1.EstateId = ".$CalendarHomes["EstateId"]." ) 
                AND NOT EXISTS ( 
                    SELECT 1 FROM KiralamaTakvimi.HotelAvailabilityRooms dg2 
                    WHERE dg2.Date = DATEADD(DAY, 1, dg1.Date) 
                    AND ((dg2.RoomCount = 0 OR dg2.IsClosed = 1) AND dg2.EstateId = ".$CalendarHomes["EstateId"].") 
                ) 
            ) ";

        $doluTarihlerSql = " SELECT
            (SELECT STRING_AGG(CAST(FORMAT(Date,'yyyy-MM-dd') AS VARCHAR), ',') FROM DoluGunler) AS doluGunler, 
            (SELECT STRING_AGG(CAST(FORMAT(Date,'yyyy-MM-dd') AS VARCHAR), ',') FROM DoluGunler dg1 
                WHERE NOT EXISTS ( SELECT 1 FROM DoluGunler dg2 WHERE dg2.Date = DATEADD(DAY, -1, dg1.Date) )) AS doluGirisler, 
            (SELECT STRING_AGG(CAST(FORMAT(Date,'yyyy-MM-dd') AS VARCHAR), ',') FROM DoluGunler dg1 
                WHERE NOT EXISTS ( SELECT 1 FROM DoluGunler dg3 WHERE dg3.Date = DATEADD(DAY, 1, dg1.Date) )) AS doluCikislar ";
    }

    // --- DEVASA SQL BİRLEŞTİRME (verisql) ---
    $siteVal = defined('PRICE_SITE') ? PRICE_SITE : 1;
    $doluKayitIdColumn = isset($backendApiAppConfig['dolu_kayit_id_column'])
        ? (string)$backendApiAppConfig['dolu_kayit_id_column']
        : 'kayitid';
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $doluKayitIdColumn)) {
        throw new Exception("Gecersiz dolu kayit id kolon ayari.");
    }
    $doluKayitIdSql = "dolu.[" . $doluKayitIdColumn . "]";
    $ruleshomesRulesIdColumn = isset($backendApiAppConfig['ruleshomes_rules_id_column'])
        ? (string)$backendApiAppConfig['ruleshomes_rules_id_column']
        : 'rulesId';
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $ruleshomesRulesIdColumn)) {
        throw new Exception("Gecersiz ruleshomes rules id kolon ayari.");
    }
    $ruleshomesRulesIdSql = "rh.[" . $ruleshomesRulesIdColumn . "]";
    $ruleshomesHomesIdColumn = isset($backendApiAppConfig['ruleshomes_homes_id_column'])
        ? (string)$backendApiAppConfig['ruleshomes_homes_id_column']
        : 'homesId';
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $ruleshomesHomesIdColumn)) {
        throw new Exception("Gecersiz ruleshomes homes id kolon ayari.");
    }
    $ruleshomesHomesIdSql = "rh.[" . $ruleshomesHomesIdColumn . "]";

    // Fiyat tablosundaki olası Döviz kurundan kaynaklı boş dönmeleri (Rate kaydı yoksa) önlemek için
    // INNER JOIN Finance.RateDetail kısmını LEFT JOIN'e çevirip ISNULL(RD.Buy, 1) ekledik!
    $verisql = $doluTarihlerSqlWith . "
        SELECT * FROM
            (SELECT 
                ISNULL(STRING_AGG(CONVERT(date,tarih1,103),','),'') AS sondakikaGirisler,
                ISNULL(STRING_AGG(CONVERT(date,tarih2,103),','),'') AS sondakikaCikislar,
                ISNULL(STRING_AGG(dbo.Fn_aratarihler2(CONVERT(date,tarih1,103),CONVERT(date,tarih2,103)) ,','),'') AS sondakikaGunler 
                FROM sonDakika WHERE CONVERT(date,tarih2,103) > CONVERT(date,GETDATE(),103) AND site = " . $siteVal . " AND islem_id = " . $EntityId . ") AS sonDakika,                     
            (" . $doluTarihlerSql . ") AS dolu,
            (SELECT 
                ISNULL(STRING_AGG(CONCAT(YEAR(tarih) ,'-' ,FORMAT(tarih,'MM'),'-', FORMAT(tarih,'dd')),','),'') AS dolu_fakeGirisler,
                ISNULL(STRING_AGG(CONCAT(YEAR(tarih2) ,'-' ,FORMAT(tarih2,'MM'),'-', FORMAT(tarih2,'dd')),','),'') AS dolu_fakeCikislar,
                ISNULL(STRING_AGG(dbo.Fn_aratarihler2(tarih,tarih2) ,','),'') AS dolu_fakeGunler 
                FROM dolu_fake WHERE CONVERT(date,tarih2,103) > CONVERT(date,GETDATE(),103) AND durum = 3 AND emlak = " . $EntityId . ") AS fake_dolu,
            (SELECT ISNULL(( SELECT 
                ISNULL(STRING_AGG(
                    CONCAT(
                        i.tarih1, '|', i.oran, '|', ISNULL(i.sahte_oran,0), ',',
                        REPLACE(dbo.Fn_aratarihler2(i.tarih1, i.tarih2), ',',  '|'+CAST(i.oran AS VARCHAR)+'|'+CAST(ISNULL(i.sahte_oran,0) AS VARCHAR)+',' ),
                        '|', i.oran, '|', ISNULL(i.sahte_oran,0), ',',
                        i.tarih2, '|', i.oran, '|', ISNULL(i.sahte_oran,0)
                    ), ','
                ),'')
                FROM indirimler i
                WHERE i.tarih2 > GETDATE() AND GETDATE() BETWEEN i.showDate1 AND i.showDate2 AND i.emlak = " . $EntityId . "
                GROUP BY i.emlak),'') AS indirimler) AS indirimler,     
            (SELECT 
                ISNULL(STRING_AGG(CONCAT(YEAR(dolu.tarih) ,'-' ,FORMAT(dolu.tarih,'MM'),'-', FORMAT(dolu.tarih,'dd')),','),'') AS odemeGirisler,
                ISNULL(STRING_AGG(CONCAT(YEAR(dolu.tarih2) ,'-' ,FORMAT(dolu.tarih2,'MM'),'-', FORMAT(dolu.tarih2,'dd')),','),'') AS odemeCikislar,
                ISNULL(STRING_AGG(dbo.Fn_aratarihler2(dolu.tarih,dolu.tarih2) ,','),'') AS odemeGunler,
                ISNULL(STRING_AGG(CONCAT(REPLICATE(CONCAT(DATEDIFF(HOUR,CONVERT(datetime,GETDATE(),103),CONVERT(datetime,kayitlar.saat,103)),','),DATEDIFF(day,CONVERT(date,dolu.tarih,103),CONVERT(date,dolu.tarih2,103))),DATEDIFF(HOUR,CONVERT(datetime,GETDATE(),103),CONVERT(datetime,kayitlar.saat,103))),','),'') AS odemeSaatler 
                FROM dolu LEFT JOIN kayitlar ON kayitlar.id = " . $doluKayitIdSql . " WHERE CONVERT(date,dolu.tarih2,103) > CONVERT(date,GETDATE(),103) AND dolu.durum = 1 AND dolu.emlak = " . $EntityId . ") AS odeme,
            (SELECT 
                ISNULL((SELECT r.baslik,
                CONVERT(VARCHAR, r.date1, 103) AS date1,
                CONVERT(VARCHAR, r.date2, 103) AS date2,
                (SELECT CONVERT(VARCHAR, r.date1, 103) as date1,
                    CONVERT(VARCHAR, r.date2, 103) as date2,
                    CONVERT(VARCHAR, ruletypes.id) as id,
                    rulesruletypes.[value] as [value] 
                    FROM rulesruletypes INNER JOIN ruletypes ON ruletypes.id = rulesruletypes.ruletypes WHERE rulesid = r.id FOR JSON PATH) AS maddeler 
                    FROM ruleshomes rh INNER JOIN rules r ON r.id = " . $ruleshomesRulesIdSql . " WHERE r.isactive = 1 AND " . $ruleshomesHomesIdSql . " = " . $EntityId . " FOR JSON PATH),'') AS kurallar ) AS kurallar,
            (SELECT  
                ISNULL(STRING_AGG( 
                    CAST(CONCAT(YEAR(CONVERT(date,tarih1,103)) ,'-' ,FORMAT(CONVERT(date,tarih1,103),'MM'),'-', FORMAT(CONVERT(date,tarih1,103),'dd'),',', 
                    dbo.Fn_aratarihler2(CONVERT(date,tarih1,103),CONVERT(date,tarih2,103)),
                    ',',YEAR(CONVERT(date,tarih2,103)) ,'-' ,FORMAT(CONVERT(date,tarih2,103),'MM'),'-', FORMAT(CONVERT(date,tarih2,103),'dd')) AS NVARCHAR(MAX)),','),'') AS fiyatlarTarihler,
                ISNULL(STRING_AGG(CAST(REPLICATE(CONVERT(NVARCHAR,(CAST((ISNULL(CONVERT(float,fiyat*ISNULL(RD.Buy, 1)),0)/7) AS decimal(10,0))))+',',DATEDIFF(day,CONVERT(date,tarih1,103),CONVERT(date,tarih2,103)))+CONVERT(NVARCHAR,(CAST(ISNULL(CONVERT(float,fiyat*ISNULL(RD.Buy, 1)),0)/7 AS decimal(10,0)))) AS NVARCHAR(MAX)),','),'') AS fiyatlar 
                FROM sezonlar                 
                LEFT JOIN kanun7464 ka ON ka.homeId=" . $home["id"] . " 
                INNER JOIN Finance.Currency FromC ON FromC.CurrencyName='" . $home["doviz"] . "'
                INNER JOIN Finance.Currency ToC ON ToC.CurrencyId = :DefaultCurrencyId
                LEFT JOIN Finance.RateDetail RD ON RD.ToCurrencyId = ToC.CurrencyId 
                    AND RD.FromCurrencyId = FromC.CurrencyId AND RD.RateId = :RateId 
            WHERE site = " . $siteVal . " " . $explodeWhere . " AND islem_id = " . $EntityId . " AND islem = 'emlak' AND CONVERT(date,tarih2,103) >= CONVERT(date,GETDATE(),103)) AS fiyatlar";

    $query = $pdo->prepare($verisql);

    // Eski config'e bağlı RateId yerine defined() kontrolü yapılıyor.
    $bindRateId = defined('RATE_ID') ? RATE_ID : (class_exists('Rate') ? Rate::GetLastRate() : 1);

    $query->execute([
        "RateId" => $bindRateId,
        "DefaultCurrencyId" => $DefaultCurrencyId
    ]);

    $rawResult = $query->fetch();

    $processedData = [];
    if ($rawResult) {
        foreach ($rawResult as $key => $val) {
            if ($key === 'kurallar' && !empty($val)) {
                $processedData[$key] = json_decode($val, true);
            } else {
                $processedData[$key] = !empty($val) ? explode(",", $val) : [];
            }
        }
    }

    echo json_encode([
        'status' => 'success',
        'Symbol' => $home["Symbol"],
        'data'   => $processedData
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Veritabanı hatası.',
        'debug'   => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Error $e) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'PHP Ölümcül Hata',
        'debug'   => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
