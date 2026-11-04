<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Support\Uploads;
use PDO;

/*
 * Kiralama Takvimi fiyat güncellemeleri (KiralamaTakvimi.SyncHistory) ve fiyat şablonları.
 * Panel API'sindeki (api/app/classes/Price.php) SyncHistoryList / SyncHistoryDetail /
 * ListAllPriceTemplates / TemplateSummary / DeletePriceTemplate buraya taşındı:
 * geçmiş doğrudan veritabanından okunur, şablonlar Kiralama Takvimi API'sinden
 * (genel.apiKey / establishmentId / token ile) alınır.
 */
final class KtPricesController extends Controller
{
    private const DEFAULT_KT_API = 'https://backend.kiralamatakvimi.com/api';

    /**
     * Fiyat / indirim güncelleme geçmişi.
     *
     * @Get("sync-history")
     * @query page int Sayfa (1'den başlar)
     * @query itemCount int Sayfa başına kayıt (en fazla 100)
     * @query search string Villa adı, EstateId veya villa id
     * @query type string Price | Discount
     * @query auto string 1 otomatik aktarım açık, 0 kapalı
     * @query status int 1 aktarıldı, 0 bekliyor, 2 hata, 9 atlandı
     * @query start string Başlangıç tarihi (yyyy-mm-dd veya dd.mm.yyyy)
     * @query end string Bitiş tarihi (yyyy-mm-dd veya dd.mm.yyyy)
     */
    public function syncHistory(): void
    {
        $pdo = $this->db->pdo();

        $page = max(1, (int) ($this->request->query('page', 1) ?: 1));
        $itemCount = min(100, max(1, (int) ($this->request->query('itemCount', 50) ?: 50)));
        $offset = ($page - 1) * $itemCount;

        $where = ['1=1'];
        $params = [];

        $type = (string) $this->request->query('type', '');
        if ($type === 'Price' || $type === 'Discount') {
            $where[] = 's.SyncType = ?';
            $params[] = $type;
        }

        $auto = (string) $this->request->query('auto', '');
        if ($auto === '1' || $auto === '0') {
            $where[] = 's.AutoImportEnabled = ?';
            $params[] = (int) $auto;
        }

        $search = trim((string) $this->request->query('search', ''));
        if ($search !== '') {
            // LIKE joker karakterlerini (%, _, [) düz karakter olarak ara
            $like = '%' . str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $search) . '%';
            if (ctype_digit($search)) {
                $where[] = '(s.EstateId = ? OR s.HomesId = ? OR h.baslik LIKE ? COLLATE Turkish_CI_AS)';
                $params[] = (int) $search;
                $params[] = (int) $search;
                $params[] = $like;
            } else {
                $where[] = 'h.baslik LIKE ? COLLATE Turkish_CI_AS';
                $params[] = $like;
            }
        }

        $start = $this->dateOnly($this->request->query('start'));
        $end = $this->dateOnly($this->request->query('end'));
        // convert(..., 23): yyyy-mm-dd biçimini sunucunun dil ayarından bağımsız okur
        if ($start !== null) {
            $where[] = 's.CreatedOn >= CONVERT(date, ?, 23)';
            $params[] = $start;
        }
        if ($end !== null) {
            $where[] = 's.CreatedOn < DATEADD(day, 1, CONVERT(date, ?, 23))';
            $params[] = $end;
        }

        $baseSql = ' FROM KiralamaTakvimi.SyncHistory s LEFT JOIN homes h ON h.id = s.HomesId WHERE ' . implode(' AND ', $where);

        // Durum sayaçları: durum filtresinden önceki koşullarla
        $stmt = $pdo->prepare('SELECT s.ImportStatus, COUNT(*) AS c' . $baseSql . ' GROUP BY s.ImportStatus');
        $stmt->execute($params);
        $counts = ['1' => 0, '0' => 0, '2' => 0, '9' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(string) (int) $row['ImportStatus']] = (int) $row['c'];
        }

        $status = $this->request->query('status');
        if ($status !== null && $status !== '' && in_array((int) $status, [0, 1, 2, 9], true)) {
            $baseSql .= ' AND s.ImportStatus = ?';
            $params[] = (int) $status;
        }

        $stmt = $pdo->prepare('SELECT COUNT(*)' . $baseSql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $titleSql = $this->homeTitleSql('h', 'h.baslik');
        $stmt = $pdo->prepare(
            "SELECT s.Id, s.SyncType, s.Action, s.EstateId, s.HomesId,
                    s.AutoImportEnabled, s.ManualTrigger, s.ItemCount, s.ImportStatus, s.ImportMessage,
                    s.CalledBy, s.PriceTemplateId,
                    CONVERT(varchar(19), s.CreatedOn, 120) AS CreatedOn,
                    CONVERT(varchar(19), s.ProcessedOn, 120) AS ProcessedOn,
                    {$titleSql} AS VillaName, h.resim AS Image, h.doviz AS Currency"
            . $baseSql
            . " ORDER BY s.CreatedOn DESC, s.Id DESC OFFSET {$offset} ROWS FETCH NEXT {$itemCount} ROWS ONLY"
        );
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'id' => (int) $row['Id'],
                'syncType' => (string) $row['SyncType'],
                'action' => (string) ($row['Action'] ?? ''),
                'estateId' => (int) ($row['EstateId'] ?? 0),
                'homesId' => (int) ($row['HomesId'] ?? 0),
                'autoImportEnabled' => (bool) (int) ($row['AutoImportEnabled'] ?? 0),
                'manualTrigger' => (bool) (int) ($row['ManualTrigger'] ?? 0),
                'itemCount' => $row['ItemCount'] !== null ? (int) $row['ItemCount'] : null,
                'importStatus' => (int) ($row['ImportStatus'] ?? 0),
                'importMessage' => $row['ImportMessage'],
                'calledBy' => $row['CalledBy'],
                'priceTemplateId' => $row['PriceTemplateId'] !== null ? (int) $row['PriceTemplateId'] : null,
                'createdOn' => $row['CreatedOn'],
                'processedOn' => $row['ProcessedOn'],
                'villaName' => $row['VillaName'],
                'currency' => $row['Currency'],
                'imageUrl' => $this->imageUrl((int) ($row['HomesId'] ?? 0), (string) ($row['Image'] ?? '')),
            ];
        }

        $this->response->success([
            'page' => $page,
            'itemCount' => $itemCount,
            'total' => $total,
            'counts' => $counts,
            'items' => $items,
        ]);
    }

    /**
     * Tek güncellemenin ayrıntısı: gelen veri ve sitedeki önceki verilerle gün gün karşılaştırma.
     *
     * @Get("sync-history-detail")
     * @query id int required SyncHistory kaydı
     */
    public function syncHistoryDetail(): void
    {
        $id = (int) $this->request->query('id', 0);
        if ($id <= 0) {
            throw new HttpException('id gerekli.', 'MISSING_ID', 400);
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT Id, SyncType, Snapshot, SiteSnapshot, ImportStatus, ImportMessage, ImportResponse,
                    CONVERT(varchar(10), CreatedOn, 23) AS CreatedDate
             FROM KiralamaTakvimi.SyncHistory WHERE Id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new HttpException('Kayıt bulunamadı.', 'NOT_FOUND', 404);
        }

        $snapshot = $row['Snapshot'] ? json_decode((string) $row['Snapshot'], true) : null;
        $site = $row['SiteSnapshot'] ? json_decode((string) $row['SiteSnapshot'], true) : null;

        $diff = null;
        if ($row['SyncType'] === 'Discount' && is_array($site) && is_array($snapshot)) {
            $diff = $this->buildDiscountDiff($snapshot['discounts'] ?? [], $site['discounts'] ?? [], (string) $row['CreatedDate']);
        }
        if ($row['SyncType'] === 'Price' && is_array($site) && is_array($snapshot)) {
            $diff = $this->buildPriceDiff($snapshot['prices'] ?? [], $site['prices'] ?? [], (string) $row['CreatedDate']);
            $diff['SiteCurrency'] = $site['currency'] ?? null;
            $diff['IncomingCurrency'] = $snapshot['currency'] ?? null;
            $diff['CurrencyChanged'] = $diff['SiteCurrency'] && $diff['IncomingCurrency']
                && $diff['SiteCurrency'] !== $diff['IncomingCurrency'];
        }

        // Ham yanıt sadece hatalı kayıtlarda ve kısaltılmış olarak gönderilir
        $importResponse = null;
        if ((int) $row['ImportStatus'] === 2 && $row['ImportResponse']) {
            $importResponse = mb_substr((string) $row['ImportResponse'], 0, 2000);
        }

        $this->response->success([
            'id' => (int) $row['Id'],
            'syncType' => (string) $row['SyncType'],
            'snapshot' => $snapshot,
            'diff' => $diff,
            'siteDiscounts' => ($row['SyncType'] === 'Discount' && is_array($site)) ? ($site['discounts'] ?? []) : null,
            'importMessage' => $row['ImportMessage'],
            'importResponse' => $importResponse,
        ]);
    }

    /**
     * Firmanın tüm fiyat şablonları (Kiralama Takvimi price/listtemplatesv2) ve sitedeki villa id'si.
     *
     * @Get("templates")
     * @query page int Sayfa
     * @query itemCount int Sayfa başına kayıt
     * @query search string Arama
     * @query status int 0 beklemede, 1 onaylandı, 2 reddedildi
     */
    public function templates(): void
    {
        $page = max(1, (int) ($this->request->query('page', 1) ?: 1));
        $itemCount = min(200, max(1, (int) ($this->request->query('itemCount', 50) ?: 50)));
        $query = ['page' => $page, 'itemCount' => $itemCount];

        $search = trim((string) $this->request->query('search', ''));
        if ($search !== '') {
            $query['search'] = $search;
        }
        $status = $this->request->query('status');
        if ($status !== null && $status !== '' && is_numeric($status)) {
            $query['status'] = (int) $status;
        }

        $response = $this->ktApi('GET', 'price/listtemplatesv2', $query);
        $data = isset($response['Data']) && is_array($response['Data']) ? $response['Data'] : [];

        // EstateId -> homesId eşlemesi (tek sorguda)
        $estateIds = array_values(array_unique(array_filter(array_map(static function ($r) {
            return (int) ($r['EstateId'] ?? 0);
        }, $data))));
        $map = [];
        if ($estateIds !== []) {
            $in = implode(',', array_fill(0, count($estateIds), '?'));
            $stmt = $this->db->pdo()->prepare("SELECT EstateId, homesId FROM KiralamaTakvimi.CalendarHomes WHERE EstateId IN ($in)");
            $stmt->execute($estateIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $map[(int) $row['EstateId']] = (int) $row['homesId'];
            }
        }
        foreach ($data as &$r) {
            $r['HomeId'] = $map[(int) ($r['EstateId'] ?? 0)] ?? 0;
        }
        unset($r);

        $this->response->success([
            'page' => (int) ($response['Page'] ?? $page),
            'itemCount' => $itemCount,
            'total' => (int) ($response['Total'] ?? count($data)),
            'items' => $data,
        ]);
    }

    /**
     * Şablon sayıları (Kiralama Takvimi price/templatesummary).
     *
     * @Get("template-summary")
     */
    public function templateSummary(): void
    {
        $r = $this->ktApi('GET', 'price/templatesummary');

        $this->response->success([
            'count' => (int) ($r['Count'] ?? 0),
            'pendingCount' => (int) ($r['PendingCount'] ?? 0),
            'approveCount' => (int) ($r['ApproveCount'] ?? 0),
            'cancelledCount' => (int) ($r['CancelledCount'] ?? 0),
        ]);
    }

    /**
     * Onaylanmamış bir fiyat şablonunu Kiralama Takvimi'nden siler (price/deletetemplate).
     *
     * @Post("template-delete")
     * @body estateId int required Kiralama Takvimi EstateId
     * @body templateId int required PriceTemplateId
     */
    public function templateDelete(): void
    {
        $estateId = (int) $this->request->input('estateId', 0);
        $templateId = (int) $this->request->input('templateId', 0);
        if ($estateId <= 0 || $templateId <= 0) {
            throw new HttpException('estateId ve templateId gerekli.', 'MISSING_PARAMS', 400);
        }

        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM KiralamaTakvimi.CalendarHomes WHERE EstateId = ?');
        $stmt->execute([$estateId]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new HttpException('Bu takvim sitede bir villaya bağlı değil.', 'NOT_CONNECTED', 404);
        }

        $this->ktApi('DELETE', 'price/deletetemplate', ['estateId' => $estateId, 'priceTemplateId' => $templateId]);

        $this->response->success(['deleted' => true]);
    }

    // ---------------------------------------------------------------------------------------------
    // Kiralama Takvimi API
    // ---------------------------------------------------------------------------------------------

    /**
     * Kiralama Takvimi API'sine istek atar. Kimlik: app.local.php (kiralamatakvimi_api_key /
     * kiralamatakvimi_establishment_id), boşsa genel tablosu (apiKey / establishmentId / token).
     *
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function ktApi(string $method, string $path, array $query = []): array
    {
        $genel = $this->db->pdo()->query('SELECT TOP 1 * FROM genel')->fetch(PDO::FETCH_ASSOC) ?: [];
        $panel = (array) ($this->app['_panel_config'] ?? []);

        $apiKey = trim((string) ($this->app['kiralamatakvimi_api_key'] ?? ''));
        if ($apiKey === '') {
            $apiKey = trim((string) ($this->rowValue($genel, 'apiKey') ?? ''));
        }
        $establishmentId = trim((string) ($this->app['kiralamatakvimi_establishment_id'] ?? ''));
        if ($establishmentId === '') {
            $establishmentId = trim((string) ($this->rowValue($genel, 'establishmentId') ?? ($panel['EstablishmentId'] ?? '')));
        }
        $token = trim((string) ($this->rowValue($genel, 'token') ?? ''));
        if ($apiKey === '' || $establishmentId === '') {
            throw new HttpException(
                'Kiralama Takvimi API ayarları eksik: ' . ($apiKey === '' ? 'genel.apiKey boş' : 'EstablishmentId bulunamadı') . '.',
                'KT_CONFIG_MISSING',
                500
            );
        }

        $base = rtrim(trim((string) ($this->app['kiralamatakvimi_api_url'] ?? '')) ?: self::DEFAULT_KT_API, '/');
        $url = $base . '/' . $path . ($query ? '?' . http_build_query($query) : '');
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-API-Key: ' . $apiKey,
            'EstablishmentId: ' . $establishmentId,
        ];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($raw === false || $status === 0) {
            throw new HttpException('Kiralama Takvimi API\'sine ulaşılamadı.', 'KT_UNREACHABLE', 502);
        }
        $decoded = json_decode((string) $raw, true);
        if ($status < 200 || $status >= 300) {
            $message = '';
            if (is_array($decoded)) {
                $messages = $this->rowValue($decoded, 'messages');
                $message = is_array($messages) && isset($messages[0])
                    ? (string) $messages[0]
                    : (string) ($this->rowValue($decoded, 'message') ?? '');
            }
            if ($message === '') {
                $message = trim(strip_tags((string) $raw)) ?: 'Kiralama Takvimi isteği başarısız.';
            }
            throw new HttpException(mb_substr($message, 0, 500) . ' (' . $path . ', HTTP ' . $status . ')', 'KT_REQUEST_FAILED', 502);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** @return mixed */
    private function rowValue(array $row, string $key)
    {
        foreach ($row as $name => $value) {
            if (strcasecmp((string) $name, $key) === 0) {
                return $value;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------------------------------------
    // Yardımcılar
    // ---------------------------------------------------------------------------------------------

    /** yyyy-mm-dd veya dd.mm.yyyy -> yyyy-mm-dd; geçersizse null. */
    private function dateOnly($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
        }

        return null;
    }

    private function imageUrl(int $homesId, string $images): ?string
    {
        $first = trim(explode(',', $images)[0] ?? '');
        if ($first === '') {
            return null;
        }
        $domain = defined('Cdn') ? trim((string) constant('Cdn')) : '';
        if ($domain === '') {
            $domain = defined('Domain') ? trim((string) constant('Domain')) : '';
        }
        $domain = rtrim($domain, '/');
        if ($domain !== '' && preg_match('#^https?://#i', $domain) !== 1) {
            $domain = 'https://' . $domain;
        }

        return Uploads::homeImageBase($domain . '/uploads/small/', $homesId, $this->app) . $first;
    }

    // ---------------------------------------------------------------------------------------------
    // Karşılaştırma (panel API'sindeki Price::buildPriceDiff / buildDiscountDiff ile aynı)
    // ---------------------------------------------------------------------------------------------

    /**
     * Gelen periyotları (PriceImport çıktısı) sitedeki sezonlarla gün gün karşılaştırır.
     * $fromDate: güncelleme tarihi (Y-m-d); öncesi karşılaştırılmaz.
     */
    private function buildPriceDiff(array $incoming, array $site, string $fromDate): array
    {
        $from = strtotime($fromDate ?: date('Y-m-d'));
        $to = strtotime('+3 years', $from);

        // Sitede ısıtma kolonu yoksa ısıtma karşılaştırılmaz
        $compareIsitma = false;
        foreach ($site as $s) {
            if (is_array($s) && array_key_exists('isitmaFiyat', $s)) {
                $compareIsitma = true;
                break;
            }
        }

        $normIn = static function ($p) use ($compareIsitma) {
            $n = [
                'fiyat' => (int) round((float) ($p['fiyat'] ?? 0)),
                'mingece' => (int) ($p['mingece'] ?? 0),
                'temizlik' => (int) round((float) ($p['temizlik'] ?? 0)),
                'temizlikGece' => (int) ($p['temizlikGece'] ?? 0),
            ];
            if ($compareIsitma) {
                $n['isitma'] = (int) round((float) ($p['isitmaFiyat'] ?? 0));
            }
            return $n;
        };
        $normSite = static function ($p) use ($compareIsitma) {
            $n = [
                'fiyat' => (int) round((float) ($p['fiyat'] ?? 0)),
                'mingece' => (int) ($p['gece'] ?? 0),
                'temizlik' => (int) round((float) ($p['temizlikFiyat'] ?? 0)),
                'temizlikGece' => (int) ($p['temizlikGece'] ?? 0),
            ];
            if ($compareIsitma) {
                $n['isitma'] = (int) round((float) ($p['isitmaFiyat'] ?? 0));
            }
            return $n;
        };

        // PriceImport'un yaptığını taklit eder:
        // 1) Gelenle birebir aynı (tarih + değerler) sezon yerinde kalır.
        // 2) Gelen bir dönemle çakışan diğer sezonlar tamamen silinir.
        // 3) Hiçbir gelen dönemle çakışmayan geçmiş sezonlara dokunulmaz.
        // 4) Birebir karşılığı olmayan gelen dönemler eklenir.
        $d10 = static function ($v) {
            return substr((string) $v, 0, 10);
        };
        $key = static function ($t1, $t2, $n) use ($d10) {
            return $d10($t1) . '|' . $d10($t2) . '|' . json_encode($n);
        };

        $inRows = [];
        foreach ($incoming as $p) {
            $n = $normIn($p);
            $inRows[] = ['t1' => $d10($p['tarih1'] ?? ''), 't2' => $d10($p['tarih2'] ?? ''), 'n' => $n,
                'k' => $key($p['tarih1'] ?? '', $p['tarih2'] ?? '', $n)];
        }
        $siteRows = [];
        foreach ($site as $p) {
            $n = $normSite($p);
            $siteRows[] = ['t1' => $d10($p['tarih1'] ?? ''), 't2' => $d10($p['tarih2'] ?? ''), 'n' => $n,
                'k' => $key($p['tarih1'] ?? '', $p['tarih2'] ?? '', $n)];
        }

        $inKeys = array_flip(array_column($inRows, 'k'));
        $siteKeys = array_flip(array_column($siteRows, 'k'));

        $overlapsIncoming = static function ($r) use ($inRows) {
            $s = strtotime($r['t1']);
            $e = strtotime($r['t2']);
            if (!$s || !$e) {
                return false;
            }
            foreach ($inRows as $i) {
                $is = strtotime($i['t1']);
                $ie = strtotime($i['t2']);
                if ($is && $ie && $s <= $ie && $e >= $is) {
                    return true;
                }
            }
            return false;
        };

        $after = [];
        $kept = [];
        foreach ($siteRows as $r) {
            if (isset($inKeys[$r['k']])) {
                $after[] = $r;
                continue;
            }
            if ($overlapsIncoming($r)) {
                continue;
            }
            $rEnd = strtotime($r['t2']);
            if ($rEnd && $rEnd >= $from) {
                continue;
            }
            $after[] = $r;
            $kept[] = $r;
        }
        foreach ($inRows as $r) {
            if (!isset($siteKeys[$r['k']])) {
                $after[] = $r;
            }
        }

        $ident = static function ($r) {
            return $r['n'];
        };
        $beforeDays = $this->expandDays($siteRows, 't1', 't2', $ident, $from, $to);
        $afterDays = $this->expandDays($after, 't1', 't2', $ident, $from, $to);

        $diff = $this->diffDays($afterDays, $beforeDays, $from);

        $keptDays = $this->expandDays($kept, 't1', 't2', $ident, $from, $to);
        $keptDiff = $this->diffDays([], $keptDays, $from);
        $diff['Kept'] = array_map(static function ($rg) {
            $rg['type'] = 'kept';
            return $rg;
        }, $keptDiff['Ranges']);
        $diff['Summary']['kept'] = count($diff['Kept']);

        return $diff;
    }

    /**
     * Gelen indirimleri sitedeki indirimlerle gün gün karşılaştırır. Sadece "Percent" tipindekiler
     * karşılaştırılır (DiscountImport da sadece bunları aktarıyor).
     */
    private function buildDiscountDiff(array $incoming, array $site, string $fromDate): array
    {
        $from = strtotime($fromDate ?: date('Y-m-d'));
        $to = strtotime('+3 years', $from);

        $ignored = 0;
        $percent = [];
        foreach ($incoming as $d) {
            if (($d['Type'] ?? '') === 'Percent') {
                $percent[] = $d;
            } else {
                $ignored++;
            }
        }

        // "_yayin": indirimin sitede gösterildiği tarihler; "_" ile başlayan alanlar karşılaştırılmaz.
        $fromStr = date('Y-m-d', $from);
        $inDays = $this->expandDays($percent, 'StartDate', 'EndDate', static function ($d) use ($fromStr) {
            return [
                'oran' => round((float) ($d['Value'] ?? 0), 2),
                '_yayin' => [$fromStr, substr((string) ($d['EndDate'] ?? ''), 0, 10)],
            ];
        }, $from, $to);
        $siteDays = $this->expandDays($site, 'tarih1', 'tarih2', static function ($d) {
            return [
                'oran' => round((float) ($d['oran'] ?? 0), 2),
                '_yayin' => [$d['showDate1'] ?? null, $d['showDate2'] ?? null],
            ];
        }, $from, $to);

        $diff = $this->diffDays($inDays, $siteDays, $from);
        $diff['IgnoredCount'] = $ignored;

        return $diff;
    }

    // Dönem listesini gün => değerler dizisine açar (çakışmada ilk kayıt geçerli)
    private function expandDays(array $list, string $startKey, string $endKey, callable $norm, int $from, int $to): array
    {
        $days = [];
        foreach ($list as $p) {
            $s = strtotime(substr((string) ($p[$startKey] ?? ''), 0, 10));
            $e = strtotime(substr((string) ($p[$endKey] ?? ''), 0, 10));
            if (!$s || !$e || $e < $s) {
                continue;
            }
            $s = max($s, $from);
            $e = min($e, $to);
            $n = $norm($p);
            for ($t = $s; $t <= $e; $t = strtotime('+1 day', $t)) {
                $d = date('Y-m-d', $t);
                if (!isset($days[$d])) {
                    $days[$d] = $n;
                }
            }
        }

        return $days;
    }

    // İki gün listesini karşılaştırıp değişen günleri aralıklara toplar
    private function diffDays(array $inDays, array $siteDays, int $from): array
    {
        $allDays = array_unique(array_merge(array_keys($inDays), array_keys($siteDays)));
        sort($allDays);

        $ranges = [];
        $cur = null;
        $prevDay = null;
        $summary = ['changed' => 0, 'new' => 0, 'removed' => 0, 'days' => 0];

        $close = static function () use (&$cur, &$ranges) {
            if ($cur) {
                unset($cur['sig']);
                $ranges[] = $cur;
            }
            $cur = null;
        };

        foreach ($allDays as $d) {
            $in = $inDays[$d] ?? null;
            $st = $siteDays[$d] ?? null;

            if ($in && $st) {
                $changes = [];
                foreach ($in as $k => $v) {
                    if ($k[0] === '_') {
                        continue;
                    }
                    if (($st[$k] ?? null) !== $v) {
                        $changes[$k] = ['old' => $st[$k] ?? null, 'new' => $v];
                    }
                }
                if (!$changes) {
                    $close();
                    $prevDay = $d;
                    continue;
                }
                foreach (array_merge($st, $in) as $k => $v) {
                    if ($k[0] === '_') {
                        $changes[$k] = ['old' => $st[$k] ?? null, 'new' => $in[$k] ?? null];
                    }
                }
                $type = 'changed';
                $data = $changes;
            } elseif ($in) {
                $type = 'new';
                $data = $in;
            } else {
                $type = 'removed';
                $data = $st;
            }

            $sig = json_encode([$type, $data]);
            $contiguous = $prevDay && $d === date('Y-m-d', strtotime('+1 day', strtotime($prevDay)));

            if ($cur && $cur['sig'] === $sig && $contiguous) {
                $cur['end'] = $d;
                $cur['days']++;
            } else {
                $close();
                $cur = ['sig' => $sig, 'type' => $type, 'start' => $d, 'end' => $d, 'days' => 1, 'data' => $data];
            }
            $summary['days']++;
            $prevDay = $d;
        }
        $close();

        foreach ($ranges as $rg) {
            $summary[$rg['type']]++;
        }

        return [
            'From' => date('Y-m-d', $from),
            'Summary' => $summary,
            'Ranges' => array_slice($ranges, 0, 300),
            'Truncated' => count($ranges) > 300,
        ];
    }
}
