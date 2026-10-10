<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;

/*
 * Müsaitlik / takvim kaynağı.
 *   GET /backend-api/availability?EntityId=123  (veya ?id=123)
 * Dolu günler, son dakika, indirim, ödeme, fiyat ve kural bilgilerini döner.
 * (Eski get-availability.php taşınmış hâli.)
 */
final class AvailabilityController extends Controller
{
    /**
     * @Get
     * @query EntityId int required Emlak/home kimliği (alternatif: id)
     */
    public function index(): void
    {
        $entityIdsStr = $this->request->query('EntityIds', $this->request->query('EntityId', $this->request->query('id', '')));
        $entityIds = [];
        $isBulk = false;
        if (strpos((string)$entityIdsStr, ',') !== false) {
            $isBulk = true;
            $entityIds = array_filter(array_map('intval', explode(',', (string)$entityIdsStr)));
        } else {
            $eId = (int) $entityIdsStr;
            if ($eId > 0) {
                $entityIds[] = $eId;
            }
        }
        if (empty($entityIds)) {
            throw new HttpException('EntityId(s) belirtilmedi.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();

        $defaultCurrencyId = defined('DEFAULT_CURRENCY_ID') ? (int) constant('DEFAULT_CURRENCY_ID') : 1;
        $uzanti  = defined('UZANTI') ? (string) constant('UZANTI') : '';
        $siteVal = defined('PRICE_SITE') ? (int) constant('PRICE_SITE') : 1;
        $doluKayitIdSql = $this->qualifiedExistingColumn($pdo, 'dbo.dolu', 'dolu', ['kayitid', 'kayitId'], 'Dolu kayit id kolonu bulunamadi.');
        $ruleshomesRulesIdSql = $this->qualifiedExistingColumn($pdo, 'dbo.ruleshomes', 'rh', ['rulesid', 'rulesId'], 'Ruleshomes rules id kolonu bulunamadi.');
        $ruleshomesHomesIdSql = $this->qualifiedExistingColumn($pdo, 'dbo.ruleshomes', 'rh', ['homesid', 'homesId'], 'Ruleshomes homes id kolonu bulunamadi.');
        $rulesruletypesRulesIdSql = $this->qualifiedExistingColumn($pdo, 'dbo.rulesruletypes', 'rulesruletypes', ['rulesid', 'rulesId'], 'Rulesruletypes rules id kolonu bulunamadi.');

        $results = [];
        foreach ($entityIds as $entityId) {
            // Home bilgisi (döviz + sembol)
            $homeStmt = $pdo->prepare(
            "SELECT h.id, h.doviz{$uzanti} AS doviz, ISNULL(ToC.Symbol, N'TL') AS Symbol
             FROM homes h
             LEFT JOIN Finance.Currency ToC ON ToC.CurrencyId = :DefaultCurrencyId
             WHERE h.id = :id"
        );
        $homeStmt->execute(['id' => $entityId, 'DefaultCurrencyId' => $defaultCurrencyId]);
        $home = $homeStmt->fetch();

        if (!$home) {
            throw new HttpException('Kayıt bulunamadı. EntityId: ' . $entityId, 'NOT_FOUND', 404);
        }
        // Varsayılan dolu tarihler sorgusu
        $doluTarihlerSql = "SELECT
            ISNULL(STRING_AGG(CONCAT(YEAR(tarih),'-',FORMAT(tarih,'MM'),'-',FORMAT(tarih,'dd')),','),'') AS doluGirisler,
            ISNULL(STRING_AGG(CONCAT(YEAR(tarih2),'-',FORMAT(tarih2,'MM'),'-',FORMAT(tarih2,'dd')),','),'') AS doluCikislar,
            ISNULL(STRING_AGG(dbo.Natsisa_Fn_aratarihler2(tarih,tarih2),','),'') AS doluGunler
            FROM dolu WHERE CONVERT(date,tarih2,103) > CONVERT(date,GETDATE(),103) AND durum = 3 AND emlak = " . $entityId;

        // Oda tipi (hotel availability) kontrolü
        $doluTarihlerSqlWith = '';
        $calStmt = $pdo->prepare("SELECT * FROM KiralamaTakvimi.CalendarHomes WHERE homesId = :homesId");
        $calStmt->execute(['homesId' => $entityId]);
        $calendarHomes = $calStmt->fetch();

        if ($calendarHomes && isset($calendarHomes['RoomType']) && (string) $calendarHomes['RoomType'] === '1') {
            $estateId = (int) $calendarHomes['EstateId'];
            $doluTarihlerSqlWith = "
                WITH DoluGunler AS (
                    SELECT Date FROM KiralamaTakvimi.HotelAvailabilityRooms
                    WHERE (RoomCount = 0 OR IsClosed = 1) AND EstateId = {$estateId}
                    UNION
                    SELECT DATEADD(DAY, 1, dg1.Date) FROM KiralamaTakvimi.HotelAvailabilityRooms dg1
                    WHERE ((dg1.RoomCount = 0 OR dg1.IsClosed = 1) AND dg1.EstateId = {$estateId})
                    AND NOT EXISTS (
                        SELECT 1 FROM KiralamaTakvimi.HotelAvailabilityRooms dg2
                        WHERE dg2.Date = DATEADD(DAY, 1, dg1.Date)
                        AND ((dg2.RoomCount = 0 OR dg2.IsClosed = 1) AND dg2.EstateId = {$estateId})
                    )
                ) ";

            $doluTarihlerSql = " SELECT
                (SELECT STRING_AGG(CAST(FORMAT(Date,'yyyy-MM-dd') AS VARCHAR), ',') FROM DoluGunler) AS doluGunler,
                (SELECT STRING_AGG(CAST(FORMAT(Date,'yyyy-MM-dd') AS VARCHAR), ',') FROM DoluGunler dg1
                    WHERE NOT EXISTS (SELECT 1 FROM DoluGunler dg2 WHERE dg2.Date = DATEADD(DAY, -1, dg1.Date))) AS doluGirisler,
                (SELECT STRING_AGG(CAST(FORMAT(Date,'yyyy-MM-dd') AS VARCHAR), ',') FROM DoluGunler dg1
                    WHERE NOT EXISTS (SELECT 1 FROM DoluGunler dg3 WHERE dg3.Date = DATEADD(DAY, 1, dg1.Date))) AS doluCikislar ";
        }

        $verisql = $doluTarihlerSqlWith . "
            SELECT * FROM
                (SELECT
                    ISNULL(STRING_AGG(CONVERT(date,tarih1,103),','),'') AS sondakikaGirisler,
                    ISNULL(STRING_AGG(CONVERT(date,tarih2,103),','),'') AS sondakikaCikislar,
                    ISNULL(STRING_AGG(dbo.Natsisa_Fn_aratarihler2(CONVERT(date,tarih1,103),CONVERT(date,tarih2,103)),','),'') AS sondakikaGunler
                    FROM sonDakika WHERE CONVERT(date,tarih2,103) > CONVERT(date,GETDATE(),103) AND site = " . $siteVal . " AND islem_id = " . $entityId . ") AS sonDakika,
                (" . $doluTarihlerSql . ") AS dolu,
                (SELECT
                    ISNULL(STRING_AGG(CONCAT(YEAR(tarih),'-',FORMAT(tarih,'MM'),'-',FORMAT(tarih,'dd')),','),'') AS dolu_fakeGirisler,
                    ISNULL(STRING_AGG(CONCAT(YEAR(tarih2),'-',FORMAT(tarih2,'MM'),'-',FORMAT(tarih2,'dd')),','),'') AS dolu_fakeCikislar,
                    ISNULL(STRING_AGG(dbo.Natsisa_Fn_aratarihler2(tarih,tarih2),','),'') AS dolu_fakeGunler
                    FROM dolu_fake WHERE CONVERT(date,tarih2,103) > CONVERT(date,GETDATE(),103) AND durum = 3 AND emlak = " . $entityId . ") AS fake_dolu,
                (SELECT ISNULL((SELECT
                    ISNULL(STRING_AGG(
                        CONCAT(
                            i.tarih1, '|', i.oran, '|', ISNULL(i.sahte_oran,0), ',',
                            REPLACE(dbo.Natsisa_Fn_aratarihler2(i.tarih1, i.tarih2), ',',  '|'+CAST(i.oran AS VARCHAR)+'|'+CAST(ISNULL(i.sahte_oran,0) AS VARCHAR)+',' ),
                            '|', i.oran, '|', ISNULL(i.sahte_oran,0), ',',
                            i.tarih2, '|', i.oran, '|', ISNULL(i.sahte_oran,0)
                        ), ','
                    ),'') 
                    FROM indirimler i
                    WHERE i.tarih2 > GETDATE() AND GETDATE() BETWEEN i.showDate1 AND i.showDate2 AND i.emlak = " . $entityId . "
                    GROUP BY i.emlak),'') AS indirimler) AS indirimler,
                (SELECT
                    ISNULL(STRING_AGG(CONCAT(YEAR(dolu.tarih),'-',FORMAT(dolu.tarih,'MM'),'-',FORMAT(dolu.tarih,'dd')),','),'') AS odemeGirisler,
                    ISNULL(STRING_AGG(CONCAT(YEAR(dolu.tarih2),'-',FORMAT(dolu.tarih2,'MM'),'-',FORMAT(dolu.tarih2,'dd')),','),'') AS odemeCikislar,
                    ISNULL(STRING_AGG(dbo.Natsisa_Fn_aratarihler2(dolu.tarih,dolu.tarih2),','),'') AS odemeGunler,
                    ISNULL(STRING_AGG(CONCAT(REPLICATE(CONCAT(DATEDIFF(HOUR,CONVERT(datetime,GETDATE(),103),CONVERT(datetime,kayitlar.saat,103)),','),DATEDIFF(day,CONVERT(date,dolu.tarih,103),CONVERT(date,dolu.tarih2,103))),DATEDIFF(HOUR,CONVERT(datetime,GETDATE(),103),CONVERT(datetime,kayitlar.saat,103))),','),'') AS odemeSaatler
                    FROM dolu LEFT JOIN kayitlar ON kayitlar.id = " . $doluKayitIdSql . " WHERE CONVERT(date,dolu.tarih2,103) > CONVERT(date,GETDATE(),103) AND dolu.durum = 1 AND dolu.emlak = " . $entityId . ") AS odeme,
                (SELECT
                    ISNULL((SELECT r.baslik,
                    CONVERT(VARCHAR, r.date1, 103) AS date1,
                    CONVERT(VARCHAR, r.date2, 103) AS date2,
                    (SELECT CONVERT(VARCHAR, r.date1, 103) as date1,
                        CONVERT(VARCHAR, r.date2, 103) as date2,
                        CONVERT(VARCHAR, ruletypes.id) as id,
                        rulesruletypes.[value] as [value]
                        FROM rulesruletypes INNER JOIN ruletypes ON ruletypes.id = rulesruletypes.ruletypes WHERE " . $rulesruletypesRulesIdSql . " = r.id FOR JSON PATH) AS maddeler
                        FROM ruleshomes rh INNER JOIN rules r ON r.id = " . $ruleshomesRulesIdSql . " WHERE r.isactive = 1 AND " . $ruleshomesHomesIdSql . " = " . $entityId . " FOR JSON PATH),'') AS kurallar ) AS kurallar,
                (SELECT 
                    ISNULL(STRING_AGG(
                        CAST(CONCAT(YEAR(CONVERT(date,tarih1,103)),'-',FORMAT(CONVERT(date,tarih1,103),'MM'),'-',FORMAT(CONVERT(date,tarih1,103),'dd'),',',
                        dbo.Natsisa_Fn_aratarihler2(CONVERT(date,tarih1,103),CONVERT(date,tarih2,103)),
                        ',',YEAR(CONVERT(date,tarih2,103)),'-',FORMAT(CONVERT(date,tarih2,103),'MM'),'-',FORMAT(CONVERT(date,tarih2,103),'dd')) AS NVARCHAR(MAX)),','),'') AS fiyatlarTarihler,
                    ISNULL(STRING_AGG(CAST(REPLICATE(CONVERT(NVARCHAR,(CAST((ISNULL(CONVERT(float,fiyat*ISNULL(RD.Buy, 1)),0)/7) AS decimal(10,0))))+',',DATEDIFF(day,CONVERT(date,tarih1,103),CONVERT(date,tarih2,103)))+CONVERT(NVARCHAR,(CAST(ISNULL(CONVERT(float,fiyat*ISNULL(RD.Buy, 1)),0)/7 AS decimal(10,0)))) AS NVARCHAR(MAX)),','),'') AS fiyatlar
                    FROM sezonlar
                    LEFT JOIN kanun7464 ka ON ka.homeId=" . (int) $home['id'] . "
                    LEFT JOIN Finance.Currency FromC ON FromC.CurrencyName='" . $home['doviz'] . "'
                    LEFT JOIN Finance.Currency ToC ON ToC.CurrencyId = :DefaultCurrencyId
                    LEFT JOIN Finance.RateDetail RD ON RD.ToCurrencyId = ToC.CurrencyId
                        AND RD.FromCurrencyId = FromC.CurrencyId AND RD.RateId = :RateId
                WHERE site = " . $siteVal . " AND islem_id = " . $entityId . " AND islem = 'emlak' AND CONVERT(date,tarih2,103) >= CONVERT(date,GETDATE(),103)) AS fiyatlar";

        $bindRateId = defined('RATE_ID')
            ? constant('RATE_ID')
            : (class_exists('Rate') ? \Rate::GetLastRate() : 1);

        $stmt = $pdo->prepare($verisql);
        $stmt->execute(['RateId' => $bindRateId, 'DefaultCurrencyId' => $defaultCurrencyId]);
        $raw = $stmt->fetch();

        // Virgülle birleşik alanları diziye çevir, kurallar JSON'unu decode et
        $data = [];
        if ($raw) {
            foreach ($raw as $key => $val) {
                if ($key === 'kurallar' && !empty($val)) {
                    $data[$key] = json_decode($val, true);
                } else {
                    $data[$key] = !empty($val) ? explode(',', $val) : [];
                }
            }
        }

            $results[$entityId] = [
                'symbol' => $home['Symbol'],
                'data'   => $data,
            ];
        }

        if ($isBulk) {
            $this->response->success($results);
        } else {
            $eId = $entityIds[0] ?? 0;
            if (!isset($results[$eId])) {
                throw new HttpException('Kayit bulunamadi.', 'NOT_FOUND', 404);
            }
            $this->response->success($results[$eId]);
        }
    }

    private function qualifiedExistingColumn(\PDO $pdo, string $table, string $alias, array $columns, string $errorMessage): string
    {
        foreach ($columns as $column) {
            if ($this->columnExists($pdo, $table, $column)) {
                return $alias . '.[' . $column . ']';
            }
        }

        throw new HttpException($errorMessage, 'CONFIG_ERROR', 500);
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('SELECT CASE WHEN COL_LENGTH(:table, :column) IS NULL THEN 0 ELSE 1 END');
        $stmt->bindValue(':table', $table);
        $stmt->bindValue(':column', $column);
        $stmt->execute();

        return (int) $stmt->fetchColumn() === 1;
    }
}
