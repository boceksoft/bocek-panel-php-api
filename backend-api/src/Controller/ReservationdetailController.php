<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Rezervasyon detay kaynaÄŸÄ±.
 * ASP siparis_detaylari.asp ekranÄ±nÄ±n okuduÄŸu verileri JSON olarak dÃ¶ner.
 */
final class ReservationdetailController extends Controller
{
    /**
     * Rezervasyon detaylarÄ±nÄ± dÃ¶ner.
     *
     * @Get
     * @query id int required Rezervasyon kimliÄŸi
     */
    public function index(): void
    {
        if ($this->request->query('btrans_ajax') === 'ibansec') {
            $this->ibansec();
            return;
        }

        $id = (int) $this->request->query('id', 0);
        if ($id <= 0) {
            throw new HttpException('LÃ¼tfen geÃ§erli bir rezervasyon ID gÃ¶nderin.', 'VALIDATION', 422);
        } 

        $pdo = $this->db->pdo();

        $reservation = $this->fetchReservation($pdo, $id);
        if (!$reservation) {
            throw new HttpException('Belirtilen ID ile rezervasyon bulunamadÄ±.', 'NOT_FOUND', 404);
        }

        $homeId = isset($reservation['evid']) ? (int) $reservation['evid'] : 0;
        $dolu = $this->fetchOne($pdo, 'SELECT * FROM dolu WHERE kayitid = :id', [':id' => $id]);
        $villa = $homeId > 0
            ? $this->fetchOne($pdo, "SELECT *, ISNULL(evsahibi, '0') AS evsahibi FROM homes WHERE id = :id", [':id' => $homeId])
            : null;

        $owner = null;
        if ($villa && isset($villa['evsahibi']) && (int) $villa['evsahibi'] > 0) {
            $owner = $this->fetchOne($pdo, 'SELECT * FROM kullanici WHERE id = :id', [':id' => (int) $villa['evsahibi']]);
        }

        $calendarResponse = $this->fetchOne(
            $pdo,
            'SELECT * FROM KiralamaTakvimi.Response WHERE kayitlarId = :id',
            [':id' => $id]
        );
        $calendarHome = $homeId > 0
            ? $this->fetchOne($pdo, 'SELECT * FROM KiralamaTakvimi.CalendarHomes WHERE homesId = :id', [':id' => $homeId])
            : null;

        $personInfo = $this->fetchOne($pdo, 'SELECT * FROM kisi_bilgileri WHERE siparis_kodu = :id', [':id' => $id]);
        $partialPayments = $this->fetchAll(
            $pdo,
            "SELECT *,
                    CASE
                        WHEN odendi = 1 THEN '<span class=''btn btn-xs btn-success''>Ödendi</span>'
                        ELSE '<span class=''btn btn-xs btn-danger''>Ödenmedi</span>'
                    END AS durum,
                    CONVERT(varchar, tarih, 104) AS tarih
             FROM parcaliOdeme
             WHERE kayitlarId = :id",
            [':id' => $id]
        );

        $documentLogs = $this->fetchDocumentLogs($pdo, $id);
        $documentState = $this->fetchOne($pdo, 'SELECT * FROM belge_kayitlari WHERE musteriid = :id', [':id' => $id]);

        $latestLog = $this->fetchOne(
            $pdo,
            "SELECT TOP 1 *,
                    (SELECT TOP 1 ad + ' ' + soyad FROM kullanici WHERE kullanici.id = kullanici_log_kaydi.kullanici_id) AS u
             FROM kullanici_log_kaydi
             WHERE islm = 'rezervasyon' AND islm_id = :id
             ORDER BY id DESC",
            [':id' => $id]
        );

        $nextReservation = $this->fetchOne(
            $pdo,
            'SELECT id, site FROM kayitlar WHERE AcikRezervasyonId = :id',
            [':id' => $id]
        );

        $accounts = [
            'site' => $this->fetchAll($pdo, 'SELECT * FROM hesaplar WHERE kullanici = 0 ORDER BY banka ASC'),
            'site_ordered' => $this->fetchAll($pdo, 'SELECT * FROM hesaplar WHERE kullanici = 0 ORDER BY siralama ASC'),
            'owner' => [],
        ];
        if ($villa && isset($villa['evsahibi'])) {
            $accounts['owner'] = $this->fetchAll(
                $pdo,
                'SELECT * FROM hesaplar WHERE kullanici = :ownerId ORDER BY siralama ASC',
                [':ownerId' => (int) $villa['evsahibi']]
            );
        }

        $blockInfo = [];
        if (!empty($reservation['ip'])) {
            $blockInfo = $this->fetchAll(
                $pdo,
                'SELECT DATEADD(MINUTE, [minute], modifiedDate) AS blockEndDate, *
                 FROM blockList
                 WHERE ip = :ip',
                [':ip' => (string) $reservation['ip']]
            );
        }

        $this->response->success([
            'rs' => $reservation,
            'dolutable' => $dolu,
            'villa' => $villa,
            'evsahibesi' => $owner,
            'KiralamaTakvimiResponse' => $calendarResponse,
            'CalendarHomes' => $calendarHome,
            'kisi_bilgileri' => $personInfo,
            'belge_kayitlari' => $documentState,
            'islem_kaydi' => $documentLogs,
            'logend' => $latestLog,
            'sonrakiRzKontrol' => $nextReservation,
            'hesaplar_kullanici_0_banka' => $accounts['site'],
            'hesaplar_kullanici_0_siralama' => $accounts['site_ordered'],
            'hesaplar_evsahibi_siralama' => $accounts['owner'],
            'blockList' => $blockInfo,
            'parcaliOdeme' => $partialPayments,
        ]);
    }

    private function ibansec(): void
    {
        $ibanId = (int) $this->request->query('iban_id', 0);
        if ($ibanId <= 0) {
            throw new HttpException('Lütfen geçerli bir iban_id gönderin.', 'VALIDATION', 422);
        }

        $rows = $this->fetchAll(
            $this->db->pdo(),
            'SELECT * FROM hesaplar WHERE id = :id',
            [':id' => $ibanId]
        );

        $this->response->success([
            'ibansec' => $rows,
        ]);
    }

    /**
     * @return array<string,mixed>|false
     */
    private function fetchReservation(PDO $pdo, int $id)
    {
        $sql = "SELECT *,
                    (SELECT site FROM sites WHERE id = kayitlar.site) AS siteadi,
                    CASE
                        WHEN doviz = 'euro' THEN 'EUR'
                        WHEN doviz = 'dolar' THEN 'USD'
                        WHEN doviz = 'pound' THEN 'GBP'
                        ELSE 'TRY'
                    END AS btrans_para_birimi,
                    ISNULL(btrans_iban, '00') AS btrans_iban,
                    ISNULL(btrans_odeme_bilgisi, 0) AS btrans_odeme_bilgisi,
                    ISNULL(btrans_odeme_yontemi, 4) AS btrans_odeme_yontemi,
                    (SELECT COUNT(defter.id) FROM defter WHERE defter.rezid = kayitlar.id) AS yorumsay,
                    ISNULL(iyzico_odeme, 1) AS iyzico_odeme,
                    ISNULL(kazancorani, 0) AS kazancorani,
                    ISNULL(
                        onaylanmaTarihi2,
                        (
                            CASE
                                WHEN CHARINDEX('-', onaylanmaTarihi) > 0
                                    THEN CONVERT(date, CONVERT(date, onaylanmaTarihi, 102), 103)
                                ELSE CONVERT(date, onaylanmaTarihi, 103)
                            END
                        )
                    ) AS onaytarihi,
                    ISNULL(eskifiyat, '') AS eskifiyat,
                    ISNULL(promotionCode, '') AS promotionCode,
                    CONVERT(varchar, kayitlar.rez_tarihi, 104) AS rez_tarihix,
                    CONVERT(varchar, kayitlar.gelecek_tarih, 104) AS gelecek_tarihx,
                    ISNULL(arandi, 0) AS arandi
                FROM kayitlar
                WHERE id = :id";

        return $this->fetchOne($pdo, $sql, [':id' => $id]);
    }

    /**
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function fetchDocumentLogs(PDO $pdo, int $id): array
    {
        $logs = [];
        foreach (['Sözleşme', 'Ödeme Belgesi', 'İptal Şartları', 'Kiralama Sözleşmesi'] as $name) {
            $logs[$name] = $this->fetchAll(
                $pdo,
                'SELECT userName, description, createdDate
                 FROM islem_kaydi
                 WHERE cat = 1 AND d_id = :id AND name = :name
                 ORDER BY createdDate ASC',
                [
                    ':id' => $id,
                    ':name' => $name,
                ]
            );
        }

        return $logs;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|false
     */
    private function fetchOne(PDO $pdo, string $sql, array $params = [])
    {
        $stmt = $pdo->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    private function fetchAll(PDO $pdo, string $sql, array $params = []): array
    {
        $stmt = $pdo->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string,mixed> $params
     */
    private function bindParams(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
}
