<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Villa detayı > Fiyatlandırma: Kiralama Takvimi'ne bağlı villanın fiyat aktarım ayarları ve işlemleri.
 * ASP panel karşılığı: doldur.asp (islem=emlak_sezon) altındaki "KiralamaTakvimi Ayarları" kutusu,
 * ajax.asp (islm=sezon_duzenle) içindeki CalendarHomes güncellemesi ve js/KiralamaTakvimi.js
 * (PriceImport, PriceTransfer, PriceHistory, HotelRunnerPriceImport, fiyat şablonu modalı).
 *
 * Ayarlar ve fiyat geçmişi doğrudan veritabanından okunur/yazılır. Kiralama Takvimi ile ilgili işlemler
 * (fiyat çek/gönder, indirim, HotelRunner / Elektra, şablonlar) ASP panelin yaptığı gibi sitenin /api'sine
 * (api/app/classes/Price.php, Reservation.php) iletilir; sezon / indirim yazma mantığı orada kalır.
 */
final class KtPriceSettingsController extends Controller
{
    /** @var array<string,bool> */
    private $columnCache = [];

    /**
     * Villanın Kiralama Takvimi bağlantısı ve fiyat aktarım ayarları. Bağlı değilse connected=false.
     *
     * @Get("info")
     * @query homesId int required Villa id
     */
    public function info(): void
    {
        $homesId = $this->homesIdFromQuery();
        $row = $this->calendarHome($this->db->pdo(), $homesId);

        $this->response->success($row === null ? ['connected' => false] : $this->settings($row));
    }

    /**
     * Ayarları kaydeder (sadece gönderilen alanlar). Otomatik içeri aktarma değişince
     * AutoPriceImportColumnLastUpdate güncellenir ve kullanici_log_kaydi'na yazılır (panel gibi).
     * Fiyat otomatik aktarma kapatılırsa indirim otomatik aktarma da kapanır.
     *
     * @Post("save")
     * @body homesId int required Villa id
     * @body autoPriceImport bool Otomatik içeri aktar (fiyat)
     * @body autoDiscountImport bool İndirim otomatik içeri aktar
     * @body hotelRunnerAddCommission bool HotelRunner / Elektra fiyatına komisyon ekle
     * @body hotelRunnerAutoPriceImport bool HotelRunner / Elektra fiyatını otomatik çek
     */
    public function save(): void
    {
        $homesId = $this->homesIdFromBody();
        $pdo = $this->db->pdo();
        $row = $this->requireCalendarHome($pdo, $homesId);

        $sets = [];
        $params = [];
        foreach (['hotelRunnerAddCommission' => 'HotelRunnerAddCommission', 'hotelRunnerAutoPriceImport' => 'HotelRunnerAutoPriceImport'] as $key => $column) {
            $value = $this->boolInput($key);
            if ($value !== null && $this->hasColumn($pdo, 'KiralamaTakvimi.CalendarHomes', $column)) {
                $sets[] = "$column = ?";
                $params[] = $value ? 1 : 0;
            }
        }

        $autoPrice = $this->boolInput('autoPriceImport');
        $autoDiscount = $this->boolInput('autoDiscountImport');
        if ($autoPrice === false) {
            $autoDiscount = false;
        }

        $logMessage = null;
        $oldPrice = $row['AutoPriceImport'] ?? null;
        if ($autoPrice !== null && ($oldPrice === null || (bool) $oldPrice !== $autoPrice)) {
            $sets[] = 'AutoPriceImport = ?';
            $params[] = $autoPrice ? 1 : 0;
            if ($this->hasColumn($pdo, 'KiralamaTakvimi.CalendarHomes', 'AutoPriceImportColumnLastUpdate')) {
                $sets[] = 'AutoPriceImportColumnLastUpdate = GETDATE()';
            }
            $logMessage = 'Otomatik fiyat aktarma ' . ((bool) $oldPrice ? 'Açık' : 'Kapalı') . ' değerinden '
                . ($autoPrice ? 'Açık' : 'Kapalı') . ' değerine güncellendi.';
        }

        $oldDiscount = $row['AutoDiscountImport'] ?? null;
        if ($autoDiscount !== null && $this->hasColumn($pdo, 'KiralamaTakvimi.CalendarHomes', 'AutoDiscountImport')
            && ($oldDiscount === null || (bool) $oldDiscount !== $autoDiscount)) {
            $sets[] = 'AutoDiscountImport = ?';
            $params[] = $autoDiscount ? 1 : 0;
            if ($this->hasColumn($pdo, 'KiralamaTakvimi.CalendarHomes', 'AutoDiscountImportColumnLastUpdate')) {
                $sets[] = 'AutoDiscountImportColumnLastUpdate = GETDATE()';
            }
        }

        if ($sets !== []) {
            $params[] = $homesId;
            $stmt = $pdo->prepare('UPDATE KiralamaTakvimi.CalendarHomes SET ' . implode(', ', $sets) . ' WHERE homesId = ?');
            $stmt->execute($params);
        }
        if ($logMessage !== null) {
            $this->writeLog($pdo, 'emlak-otomatik-fiyat-aktarma', $homesId, $logMessage);
        }

        $this->response->success($this->settings($this->requireCalendarHome($pdo, $homesId)));
    }

    /**
     * Sitedeki güncel sezonları Kiralama Takvimi'ne gönderir (/api Price::BulkCreate): takvimdeki fiyatlar ve
     * min. gece kuralları silinip sezonlardan yeniden yazılır.
     *
     * @Post("price-send")
     * @body homesId int required Villa id
     */
    public function priceSend(): void
    {
        $homesId = $this->homesIdFromBody();
        $pdo = $this->db->pdo();
        $this->requireCalendarHome($pdo, $homesId);

        // /api boş sezon listesiyle de KT'deki fiyatları siler; gönderilecek sezon yoksa hiç gidilmez.
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM sezonlar WHERE islem_id = ? AND islem = 'emlak' AND site = 1
               AND CONVERT(date, GETDATE(), 104) <= TRY_CONVERT(date, tarih2, 104)"
        );
        $stmt->execute([$homesId]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new HttpException('Gönderilecek güncel sezon yok (bugünden sonraki sezon fiyatı bulunamadı).', 'NO_SEASONS', 400);
        }

        $result = $this->apiJson('POST', 'Price/BulkCreate', [], ['homesId' => $homesId, 'transferDiscounts' => false]);
        $messages = $result['Messages'] ?? $result['messages'] ?? null;
        if (is_array($messages) && $messages !== []) {
            throw new HttpException((string) reset($messages), 'KT_REJECTED', 400);
        }

        $this->writeLog($pdo, 'emlak-fiyat-gonder', $homesId, 'Fiyatlar Kiralama Takvimi\'ne gönderildi.');
        $this->response->success(['sent' => true]);
    }

    /**
     * Kiralama Takvimi'ndeki fiyat ve indirimlerin önizlemesi (yazma yapmaz).
     * prices: haftalık fiyatlı dönemler; discounts.incoming: eklenecek, discounts.toDelete: silinecek indirimler.
     *
     * @Get("price-import-preview")
     * @query homesId int required Villa id
     */
    public function priceImportPreview(): void
    {
        $homesId = $this->homesIdFromQuery();
        $row = $this->requireCalendarHome($this->db->pdo(), $homesId);
        $estateId = (int) $row['EstateId'];

        $price = $this->apiJson('GET', 'Price/PriceImport', ['estateId' => $estateId, 'overView' => 1]);
        if (($price['status'] ?? '') !== 'success') {
            throw new HttpException((string) ($price['message'] ?? 'Fiyat bilgisi alınamadı.'), 'KT_NO_PRICE', 400);
        }

        // İndirim çekilemese de fiyat akışı bozulmasın (panel de böyle).
        try {
            $discount = $this->apiJson('GET', 'Price/DiscountImport', ['estateId' => $estateId, 'overView' => 1]);
        } catch (HttpException $e) {
            $discount = [];
        }
        $incoming = $discount['incoming'] ?? ($this->isList($discount) ? $discount : []);

        $this->response->success([
            'prices' => array_values((array) ($price['prices'] ?? [])),
            'currency' => (string) ($price['doviz']['currency'] ?? ''),
            'extraCurrency' => (string) ($price['doviz2']['currency'] ?? ($price['doviz']['currency'] ?? '')),
            'discounts' => [
                'incoming' => array_values((array) $incoming),
                'toDelete' => array_values((array) ($discount['toDelete'] ?? [])),
                'today' => (string) ($discount['today'] ?? date('Y-m-d')),
            ],
        ]);
    }

    /**
     * Kiralama Takvimi fiyatlarını siteye aktarır (/api Price::PriceImport): gelen dönemlerle çakışan sezonlar
     * silinip yenileri yazılır. importDiscounts true ise ardından indirimler de aktarılır (Price::DiscountImport).
     *
     * @Post("price-import")
     * @body homesId int required Villa id
     * @body importDiscounts bool İndirimleri de aktar
     */
    public function priceImport(): void
    {
        $homesId = $this->homesIdFromBody();
        $pdo = $this->db->pdo();
        $row = $this->requireCalendarHome($pdo, $homesId);
        $estateId = (int) $row['EstateId'];
        $importDiscounts = $this->boolInput('importDiscounts') === true;

        $price = $this->apiJson('POST', 'Price/PriceImport', ['estateId' => $estateId]);
        if (($price['status'] ?? '') !== 'success') {
            throw new HttpException((string) ($price['message'] ?? 'Fiyatlar içe aktarılamadı.'), 'KT_IMPORT_FAILED', 400);
        }

        $discountImported = false;
        $discountError = null;
        if ($importDiscounts) {
            try {
                $this->apiJson('POST', 'Price/DiscountImport', ['estateId' => $estateId]);
                $discountImported = true;
            } catch (HttpException $e) {
                $discountError = $e->getMessage();
            }
        }

        $this->writeLog($pdo, 'emlak-fiyat-ice-aktar', $homesId, $discountImported
            ? 'Kiralama Takvimi fiyatları ve indirimleri içe aktarıldı.'
            : 'Kiralama Takvimi fiyatları içe aktarıldı.');

        $this->response->success([
            'imported' => true,
            'discountsImported' => $discountImported,
            'discountError' => $discountError,
        ]);
    }

    /**
     * Fiyat geçmişindeki yıllar (dbo.sezonlar_history). Tablo yoksa boş liste.
     *
     * @Get("price-history-years")
     * @query homesId int required Villa id
     */
    public function priceHistoryYears(): void
    {
        $homesId = $this->homesIdFromQuery();
        $pdo = $this->db->pdo();
        if (!$this->hasTable($pdo, 'dbo.sezonlar_history')) {
            $this->response->success(['years' => [], 'available' => false]);
            return;
        }

        $stmt = $pdo->prepare(
            "SELECT DISTINCT YEAR(TRY_CONVERT(date, tarih1, 104)) AS y FROM dbo.sezonlar_history
             WHERE islem = 'emlak' AND islem_id = ? AND TRY_CONVERT(date, tarih1, 104) IS NOT NULL
             ORDER BY y DESC"
        );
        $stmt->execute([$homesId]);
        $years = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $this->response->success(['years' => $years, 'available' => true]);
    }

    /**
     * Seçilen yıla dokunan fiyat geçmişi kayıtları (yeniden eskiye).
     * action: DELETE (silindi), UPDATE-NEW (yeni hali), diğerleri önceki hali.
     *
     * @Get("price-history")
     * @query homesId int required Villa id
     * @query year int required Yıl
     */
    public function priceHistory(): void
    {
        $homesId = $this->homesIdFromQuery();
        $year = (int) $this->request->query('year', 0);
        if ($year < 2000 || $year > 2100) {
            throw new HttpException('Geçerli bir yıl girilmelidir.', 'VALIDATION', 400);
        }
        $pdo = $this->db->pdo();
        if (!$this->hasTable($pdo, 'dbo.sezonlar_history')) {
            $this->response->success(['items' => []]);
            return;
        }

        $stmt = $pdo->prepare(
            "SELECT * FROM dbo.sezonlar_history
             WHERE islem = 'emlak' AND islem_id = ?
               AND ? IN (YEAR(TRY_CONVERT(date, tarih1, 104)), YEAR(TRY_CONVERT(date, tarih2, 104)))
             ORDER BY history_id DESC"
        );
        $stmt->execute([$homesId, $year]);

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[] = [
                'id' => (int) ($r['history_id'] ?? 0),
                'tarih1' => (string) ($r['tarih1'] ?? ''),
                'tarih2' => (string) ($r['tarih2'] ?? ''),
                'gece' => $r['gece'] ?? null,
                'fiyat' => $r['fiyat'] ?? null,
                'isitmaFiyat' => $r['isitmaFiyat'] ?? null,
                'temizlikFiyat' => $r['temizlikFiyat'] ?? null,
                'temizlikGece' => $r['temizlikGece'] ?? null,
                'action' => (string) ($r['action'] ?? ''),
                'actionDate' => isset($r['actionDate']) ? (string) $r['actionDate'] : null,
            ];
        }

        $this->response->success(['items' => $items]);
    }

    /**
     * HotelRunner / Elektra: fiyat çekme isteği başlatır (/api Reservation::CreatePriceCallback).
     * Sonuç hazır olana kadar hotelrunner-status ile sorgulanır.
     *
     * @Post("hotelrunner-request")
     * @body homesId int required Villa id
     */
    public function hotelrunnerRequest(): void
    {
        $homesId = $this->homesIdFromBody();
        $this->requireCalendarHome($this->db->pdo(), $homesId);

        $transactionId = random_int(1, 1000000);
        $result = $this->apiJson('POST', 'Reservation/CreatePriceCallback', ['homesId' => $homesId], ['TransactionId' => $transactionId]);
        if (($result['status'] ?? '') !== 'success') {
            throw new HttpException((string) ($result['error'] ?? 'Fiyat çekme başlatılamadı.'), 'KT_REQUEST_FAILED', 400);
        }

        $this->response->success(['started' => true]);
    }

    /**
     * HotelRunner / Elektra: çekilen fiyatlar hazır mı? Hazırsa fiyat listesi döner (/api Reservation::CheckPriceStatus).
     *
     * @Get("hotelrunner-status")
     * @query homesId int required Villa id
     */
    public function hotelrunnerStatus(): void
    {
        $homesId = $this->homesIdFromQuery();
        $this->requireCalendarHome($this->db->pdo(), $homesId);

        $status = $this->hotelrunnerPriceStatus($homesId);
        $this->response->success($status);
    }

    /**
     * HotelRunner / Elektra: geçici fiyat listesini onaylayıp siteye aktarır (/api Reservation::ApproveTempPriceList).
     * /api villanın tüm sezonlarını silip listeyi yazdığı için liste boşsa istek atılmaz.
     *
     * @Post("hotelrunner-approve")
     * @body homesId int required Villa id
     */
    public function hotelrunnerApprove(): void
    {
        $homesId = $this->homesIdFromBody();
        $pdo = $this->db->pdo();
        $this->requireCalendarHome($pdo, $homesId);

        $status = $this->hotelrunnerPriceStatus($homesId);
        if (!$status['ready'] || $status['prices'] === []) {
            throw new HttpException('Aktarılacak fiyat bulunamadı; mevcut sezonlara dokunulmadı.', 'KT_NO_PRICE', 400);
        }

        $result = $this->apiJson('POST', 'Reservation/ApproveTempPriceList', ['homesId' => $homesId]);
        if (($result['status'] ?? '') !== 'success') {
            throw new HttpException('Fiyatlar içe aktarılamadı.', 'KT_IMPORT_FAILED', 400);
        }

        $this->writeLog($pdo, 'emlak-fiyat-ice-aktar', $homesId, 'HotelRunner / Elektra fiyatları içe aktarıldı.');
        $this->response->success(['imported' => true]);
    }

    /**
     * @return array{ready: bool, prices: array<int,mixed>, currency: string, note: string}
     */
    private function hotelrunnerPriceStatus(int $homesId): array
    {
        $result = $this->apiJson('POST', 'Reservation/CheckPriceStatus', ['homesId' => $homesId]);
        $ready = ($result['HotelRunnerPriceImportBeginHash'] ?? null) == ($result['HotelRunnerPriceImportEndHash'] ?? null)
            && isset($result['PriceFormatted']);
        $formatted = (array) ($result['PriceFormatted'] ?? []);

        return [
            'ready' => $ready,
            'prices' => $ready ? array_values((array) ($formatted['PriceListFormatted'] ?? [])) : [],
            'currency' => (string) ($formatted['doviz'] ?? ''),
            'note' => (string) ($formatted['note'] ?? ''),
        ];
    }

    /**
     * Villanın Kiralama Takvimi fiyat şablonları (/api Price::ListPriceTemplate).
     *
     * @Get("templates")
     * @query homesId int required Villa id
     */
    public function templates(): void
    {
        $homesId = $this->homesIdFromQuery();
        $this->requireCalendarHome($this->db->pdo(), $homesId);

        $result = $this->apiJson('GET', 'Price/ListPriceTemplate', ['homeId' => $homesId]);
        $this->response->success(['items' => $this->isList($result) ? $result : []]);
    }

    /**
     * Şablon detayı: fiyatlar (PriceTypeId 1 konaklama, 2 depozito, 4 havuz ısıtma, 5 temizlik) ve takvim kuralları.
     *
     * @Get("template")
     * @query homesId int required Villa id
     * @query templateId int required Şablon id
     */
    public function template(): void
    {
        $homesId = $this->homesIdFromQuery();
        $templateId = (int) $this->request->query('templateId', 0);
        if ($templateId <= 0) {
            throw new HttpException('Geçerli bir şablon id girilmelidir.', 'VALIDATION', 400);
        }
        $this->requireCalendarHome($this->db->pdo(), $homesId);

        $result = $this->apiJson('GET', 'Price/PriceTemplateDetail', ['homeId' => $homesId, 'templateId' => $templateId]);
        if ($result === []) {
            throw new HttpException('Şablon bulunamadı.', 'NOT_FOUND', 404);
        }
        $this->response->success($result);
    }

    /**
     * Şablon oluşturur (templateId yoksa) ya da günceller (/api Price::CreatePriceTemplate / UpdatePriceTemplate).
     * Para birimi /api'de villanın dövizinden belirlenir.
     *
     * @Post("template-save")
     * @body homesId int required Villa id
     * @body templateId int Güncellenecek şablon (yoksa yeni)
     * @body name string Şablon adı
     * @body description string Açıklama
     * @body items array Fiyatlar [{priceId, startDate, endDate, priceRange, priceTypeId, rate, value, description, isDeleted}]
     * @body bookingRules array Kurallar [{priceTemplateBookingRuleId, startDate, endDate, minStay, isMandatory, isDeleted}]
     */
    public function templateSave(): void
    {
        $homesId = $this->homesIdFromBody();
        $row = $this->requireCalendarHome($this->db->pdo(), $homesId);
        $templateId = (int) $this->request->input('templateId', 0);

        $items = $this->request->input('items', []);
        $rules = $this->request->input('bookingRules', []);
        if (!is_array($items) || !is_array($rules)) {
            throw new HttpException('Fiyat ve kural listesi geçersiz.', 'VALIDATION', 400);
        }
        $estateId = (int) $row['EstateId'];
        foreach ($items as &$item) {
            $item = (array) $item;
            $item['estateId'] = $estateId;
        }
        unset($item);

        $body = [
            'homeId' => $homesId,
            'name' => $this->nullableText($this->request->input('name')),
            'description' => $this->nullableText($this->request->input('description')),
            'items' => array_values($items),
            'bookingRules' => array_values($rules),
        ];
        if ($templateId > 0) {
            $body['templateId'] = $templateId;
        }

        $result = $this->apiJson('POST', $templateId > 0 ? 'Price/UpdatePriceTemplate' : 'Price/CreatePriceTemplate', [], $body, [200, 400, 502]);
        if (($result['success'] ?? false) !== true) {
            throw new HttpException((string) ($result['message'] ?? 'Şablon kaydedilemedi.'), 'KT_REQUEST_FAILED', 400);
        }

        $this->response->success(['saved' => true]);
    }

    /**
     * Şablonu siler (/api Price::DeletePriceTemplate).
     *
     * @Post("template-delete")
     * @body homesId int required Villa id
     * @body templateId int required Şablon id
     */
    public function templateDelete(): void
    {
        $homesId = $this->homesIdFromBody();
        $templateId = (int) $this->request->input('templateId', 0);
        if ($templateId <= 0) {
            throw new HttpException('Geçerli bir şablon id girilmelidir.', 'VALIDATION', 400);
        }
        $this->requireCalendarHome($this->db->pdo(), $homesId);

        $raw = $this->api('POST', 'Price/DeletePriceTemplate', [], ['homeId' => $homesId, 'templateId' => $templateId]);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || ($decoded['success'] ?? false) !== true) {
            throw new HttpException('Şablon silinemedi (onaylanmış şablonlar silinemez).', 'KT_REQUEST_FAILED', 400);
        }

        $this->response->success(['deleted' => true]);
    }

    // ---------------------------------------------------------------- yardımcılar

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function settings(array $row): array
    {
        $pdo = $this->db->pdo();

        return [
            'connected' => true,
            'homesId' => (int) ($row['homesId'] ?? 0),
            'estateId' => (int) ($row['EstateId'] ?? 0),
            // RoomType 1: HotelRunner / Elektra (ConnectionType 2 HotelRunner, diğerleri Elektra)
            'roomType' => (string) ($row['RoomType'] ?? ''),
            'connectionType' => (string) ($row['ConnectionType'] ?? ''),
            'hotelRunnerAddCommission' => (bool) ($row['HotelRunnerAddCommission'] ?? false),
            'hotelRunnerAutoPriceImport' => (bool) ($row['HotelRunnerAutoPriceImport'] ?? false),
            'autoPriceImport' => (bool) ($row['AutoPriceImport'] ?? false),
            'autoPriceImportUpdatedAt' => $this->dateText($row['AutoPriceImportColumnLastUpdate'] ?? null),
            'autoDiscountImport' => (bool) ($row['AutoDiscountImport'] ?? false),
            'autoDiscountImportUpdatedAt' => $this->dateText($row['AutoDiscountImportColumnLastUpdate'] ?? null),
            'discountImportAvailable' => $this->hasColumn($pdo, 'KiralamaTakvimi.CalendarHomes', 'AutoDiscountImport'),
            'lastPriceCallback' => $this->dateText($row['LastPriceCallback'] ?? null),
            'lastPriceUpdated' => $this->dateText($row['LastPriceUpdated'] ?? null),
            'historyAvailable' => $this->hasTable($pdo, 'dbo.sezonlar_history'),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function calendarHome(PDO $pdo, int $homesId): ?array
    {
        // Kiralama Takvimi kurulmamış sitede tablo yok: villa bağlı değil sayılır.
        if (!$this->hasTable($pdo, 'KiralamaTakvimi.CalendarHomes')) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT TOP 1 * FROM KiralamaTakvimi.CalendarHomes WHERE homesId = ?');
        $stmt->execute([$homesId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function requireCalendarHome(PDO $pdo, int $homesId): array
    {
        $row = $this->calendarHome($pdo, $homesId);
        if ($row === null || (int) ($row['EstateId'] ?? 0) <= 0) {
            throw new HttpException('Bu villa Kiralama Takvimi\'ne bağlı değil.', 'NOT_CONNECTED', 404);
        }

        return $row;
    }

    private function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (!isset($this->columnCache[$key])) {
            $stmt = $pdo->prepare('SELECT COL_LENGTH(?, ?) AS len');
            $stmt->execute([$table, $column]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->columnCache[$key] = is_array($r) && $r['len'] !== null;
        }

        return $this->columnCache[$key];
    }

    private function hasTable(PDO $pdo, string $table): bool
    {
        $key = '#' . $table;
        if (!isset($this->columnCache[$key])) {
            $stmt = $pdo->prepare("SELECT OBJECT_ID(?, N'U') AS id");
            $stmt->execute([$table]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->columnCache[$key] = is_array($r) && $r['id'] !== null;
        }

        return $this->columnCache[$key];
    }

    private function homesIdFromQuery(): int
    {
        $id = (int) $this->request->query('homesId', 0);
        if ($id <= 0) {
            throw new HttpException('Geçerli bir villa id girilmelidir.', 'VALIDATION', 400);
        }

        return $id;
    }

    private function homesIdFromBody(): int
    {
        $id = (int) $this->request->input('homesId', 0);
        if ($id <= 0) {
            throw new HttpException('Geçerli bir villa id girilmelidir.', 'VALIDATION', 400);
        }

        return $id;
    }

    private function boolInput(string $key): ?bool
    {
        $value = $this->request->input($key);
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param mixed $value
     */
    private function nullableText($value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, 500);
    }

    /**
     * @param mixed $value
     */
    private function dateText($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = strtotime((string) $value);

        return $ts ? date('Y-m-d H:i:s', $ts) : (string) $value;
    }

    /**
     * @param mixed $value
     */
    private function isList($value): bool
    {
        return is_array($value) && ($value === [] || array_keys($value) === range(0, count($value) - 1));
    }

    /**
     * kullanici_log_kaydi'na yazar (ASP panel kullaniciLogKaydet ile aynı islm adları). Log yazılamazsa işlem bozulmaz.
     */
    private function writeLog(PDO $pdo, string $islm, int $homesId, string $message): void
    {
        try {
            $userName = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $this->request->input('kullanici_adi', ''))), 0, 100);
            $natsisaUserId = max(0, (int) $this->request->input('natsisa_kullanici_id', 0));
            $params = [$islm, (string) $homesId, $message, (int) $this->request->input('kullanici_id', 0)];

            if ($this->hasColumn($pdo, 'dbo.kullanici_log_kaydi', 'degisiklikler')) {
                $details = json_encode([
                    'kaynak' => $natsisaUserId > 0 || $userName !== '' ? 'natsisa' : 'api',
                    'kullaniciAdi' => $userName,
                    'natsisaKullaniciId' => $natsisaUserId,
                    'islem' => $islm,
                ], JSON_UNESCAPED_UNICODE);
                $params[] = $details === false ? null : $details;
                $stmt = $pdo->prepare('INSERT INTO kullanici_log_kaydi (islm, islm_id, aciklama, kullanici_id, degisiklikler) VALUES (?, ?, ?, ?, ?)');
            } else {
                $stmt = $pdo->prepare('INSERT INTO kullanici_log_kaydi (islm, islm_id, aciklama, kullanici_id) VALUES (?, ?, ?, ?)');
            }
            $stmt->execute($params);
        } catch (\Throwable $e) {
            // log yazılamaması işlemi geri almaz
        }
    }

    // ---------------------------------------------------------------- sitenin /api'si (Kiralama Takvimi istemcisi)

    /**
     * Sitenin /api'sine istek atar ve JSON yanıtı dizi olarak döner.
     *
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $body JSON gövde (POST)
     * @param int[] $okStatuses Gövdesi okunacak HTTP durumları
     * @return array<mixed>
     */
    private function apiJson(string $method, string $path, array $query, ?array $body = null, array $okStatuses = [200]): array
    {
        $raw = $this->api($method, $path, $query, $body, $okStatuses);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new HttpException(
                'Site /api\'si beklenmeyen bir yanıt döndü.' . (!empty($this->app['debug']) ? ' | ' . $path . ' | ' . mb_substr($raw, 0, 300) : ''),
                'PANEL_BAD_RESPONSE',
                502
            );
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $body
     * @param int[] $okStatuses
     */
    private function api(string $method, string $path, array $query, ?array $body = null, array $okStatuses = [200]): string
    {
        $url = $this->apiUrl() . '/' . $path . ($query !== [] ? '?' . http_build_query($query) : '');
        $headers = array_merge(['Accept: application/json'], array_values((array) ($this->app['kiralamatakvimi_panel_api_headers'] ?? [])));

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            // Fiyat içe aktarma / gönderme KT'ye çok sayıda istek attığı için uzun sürebilir.
            CURLOPT_TIMEOUT => 150,
        ];
        if ($method !== 'GET') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body === null ? '' : (string) json_encode($body);
            $headers[] = 'Content-Type: application/json';
        }
        $options[CURLOPT_HTTPHEADER] = $headers;

        $curl = curl_init($url);
        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($raw === false || $status === 0) {
            throw new HttpException(
                'Site /api\'sine ulaşılamadı.' . (!empty($this->app['debug']) ? ' | ' . $url . ' | ' . $error : ''),
                'PANEL_UNREACHABLE',
                502
            );
        }
        if (!in_array($status, $okStatuses, true)) {
            throw new HttpException(
                'Site /api\'si hata döndü (' . $status . ').' . (!empty($this->app['debug']) ? ' | ' . $path . ' | ' . mb_substr((string) $raw, 0, 300) : ''),
                'PANEL_ERROR',
                502
            );
        }

        return trim((string) $raw);
    }

    // KiralamatakvimiController::panelUrl ile aynı kural (config kiralamatakvimi_panel_api_url, yoksa URL sabiti).
    private function apiUrl(): string
    {
        $url = trim((string) ($this->app['kiralamatakvimi_panel_api_url'] ?? ''));
        if ($url === '') {
            $url = defined('URL') ? trim((string) constant('URL')) : '';
            if ($url === '') {
                throw new HttpException('Site /api adresi tanımlı değil (config URL sabiti yok).', 'PANEL_UNREACHABLE', 500);
            }
            if (preg_match('#^https?://#i', $url) !== 1) {
                $url = 'https://' . $url;
            }
        }

        return rtrim($url, '/');
    }
}
