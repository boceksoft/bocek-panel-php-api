<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Support\LegacySql;
use PDO;

/*
 * Belge numarasi (7464) yonetimi. Eski panel: gavel-yonetimi.asp
 *   GET  /gavel-management          ozet sayilar + kayitli belge numaralari
 *   GET  /gavel-management/export   belge sorgu servisine (BulkSaveData) gonderilecek ilan verileri
 *   POST /gavel-management          Bulkcheckv2 sonuclarini kanun7464'e yazar (eski: ?islem=kaydet)
 *   GET  /gavel-management/settings  Gavel secenekleri (eski: genel_ayarlar.asp > Gavel sekmesi)
 *   POST /gavel-management/settings  Gavel seceneklerini kaydeder (yalnizca bu kolonlar)
 *   Ayarlar yalnizca site 1'in genel tablosundan okunur/yazilir; diger sitelerin genel{dbtable} tablolari kullanilmaz.
 *
 * Bakanlik sorgusu ve BulkSaveData istegi Web.Api uzerinden yapilir (servis token'i orada durur).
 */
final class GavelManagementController extends Controller
{
    use LegacySql;

    /**
     * Gavel sekmesi alanlari: istek anahtari => genel tablosu kolonu.
     * Secenek alanlarinda izin verilen degerler eski paneldeki select'lerle aynidir.
     */
    private const SETTING_OPTIONS = [
        'explode_option' => ['column' => 'gavelExplodeOption', 'values' => ['0', '1']],
        'owner_option' => ['column' => 'gavelOwnerOption', 'values' => ['0', '1', '2']],
        'detail_option' => ['column' => 'gavelDetailOption', 'values' => ['0', '1']],
        'listing_option' => ['column' => 'gavelListingOption', 'values' => ['0', '1']],
        'search_option' => ['column' => 'gavelSearchOption', 'values' => ['0', '1']],
    ];
    private const SETTINGS_TABLE = 'genel';
    private const SETTING_DATE_COLUMN = 'gavelExplodeDate';
    private const SETTING_MESSAGE_COLUMN = 'stopSellMessage';

    private const NOT_FOUND_STATUS = 'Kayıt bulunamadı';
    private const DB_FALLBACK_MESSAGE = 'Bakanlık sitesinde kayıt bulunamadı, veritabanından sonuçlar getirildi.';

    /**
     * Belgeli/belgesiz ilan sayilari, toplu guncellemede sorgulanacak belge numaralari ve
     * birden fazla ilanda kullanilan belge numaralari.
     *
     * @Get
     */
    public function index(): void
    {
        $pdo = $this->db->pdo();
        $this->assertTable($pdo);

        $stats = $pdo->query(
            "SELECT
                (SELECT COUNT(homeId) FROM kanun7464 WHERE LEN(gavelBelgeNo) > 1 AND ISNULL(GavelBelgeTipi, 1) = 1) AS ToplamBelgeli,
                (SELECT COUNT(id) FROM homes WHERE aktif = 1) AS ToplamVilla,
                (SELECT COUNT(id) FROM homes) - (SELECT COUNT(homeId) FROM kanun7464 WHERE LEN(gavelBelgeNo) > 1) AS ToplamBelgesiz"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $numbers = $pdo->query('SELECT gavelBelgeNo FROM kanun7464 WHERE LEN(gavelBelgeNo) > 1')->fetchAll(PDO::FETCH_COLUMN);

        $this->response->success([
            'duplicate_numbers' => $this->duplicateNumbers($pdo),
            'stats' => [
                'with_document' => (int) ($stats['ToplamBelgeli'] ?? 0),
                'without_document' => (int) ($stats['ToplamBelgesiz'] ?? 0),
                'active_listings' => (int) ($stats['ToplamVilla'] ?? 0),
            ],
            'document_numbers' => array_values(array_map('strval', $numbers)),
        ]);
    }

    /**
     * Birden fazla kanun7464 satirinda (bosluklar yok sayilarak) gecen belge numaralari; en cok kullanilan basta.
     * hatali: numaranin en az bir satirinda belge sureli ve belge sahibi bos (bakanlikta bulunamadi).
     *
     * @return array<int,array<string,mixed>>
     */
    private function duplicateNumbers(PDO $pdo): array
    {
        $durationSql = $this->gavelDurationSql('k');
        $rows = $pdo->query(
            "SELECT
                REPLACE(k.gavelBelgeNo, ' ', '') AS no,
                k.homeId,
                ISNULL(h.baslik, '') AS baslik,
                CASE WHEN ISNULL({$durationSql}, 0) = 2 AND ISNULL(k.gavelBelgeSahip, '') = '' THEN 1 ELSE 0 END AS hatali
             FROM kanun7464 k
             LEFT JOIN homes h ON h.id = k.homeId
             WHERE LEN(k.gavelBelgeNo) > 1
               AND REPLACE(k.gavelBelgeNo, ' ', '') IN (
                   SELECT REPLACE(gavelBelgeNo, ' ', '')
                   FROM kanun7464
                   WHERE LEN(gavelBelgeNo) > 1
                   GROUP BY REPLACE(gavelBelgeNo, ' ', '')
                   HAVING COUNT(*) > 1
               )
             ORDER BY REPLACE(k.gavelBelgeNo, ' ', ''), k.homeId"
        )->fetchAll(PDO::FETCH_ASSOC);

        $groups = [];
        foreach ($rows as $row) {
            $no = (string) $row['no'];
            if (!isset($groups[$no])) {
                $groups[$no] = ['number' => $no, 'count' => 0, 'not_found' => false, 'listings' => []];
            }
            $groups[$no]['count']++;
            $groups[$no]['not_found'] = $groups[$no]['not_found'] || (int) $row['hatali'] === 1;
            $groups[$no]['listings'][] = ['id' => (int) $row['homeId'], 'title' => (string) $row['baslik']];
        }

        $result = array_values($groups);
        usort($result, function (array $a, array $b): int {
            return $b['count'] <=> $a['count'] ?: strcmp($a['number'], $b['number']);
        });

        return $result;
    }

    /**
     * Belge sorgu servisine (BulkSaveData) gonderilecek, unvani belli belgeli ilanlar.
     * Unvan: kanun7464.belgeSahibiUnvani, bossa ev sahibinin adi soyadi.
     *
     * @Get("export")
     */
    public function export(): void
    {
        $pdo = $this->db->pdo();
        $this->assertTable($pdo);

        $durationSql = $this->gavelDurationSql('k');
        $ownerSql = "(CASE WHEN ISNULL(k.belgeSahibiUnvani, '') = '' THEN ISNULL(es.ad + ' ' + es.soyad, '') ELSE ISNULL(k.belgeSahibiUnvani, '') END)";
        $rows = $pdo->query(
            "SELECT
                k.gavelBelgeNo,
                h.id,
                h.baslik,
                h.url,
                dbo.FnRandomSplit(h.resim, ',') AS resim,
                {$ownerSql} AS OwnerName,
                ISNULL({$durationSql}, 0) AS DocumentType
             FROM kanun7464 k
             INNER JOIN homes h ON h.id = k.homeId
             LEFT JOIN kullanici es ON es.id = h.evsahibi AND es.yetki = 2
             WHERE LEN(k.gavelBelgeNo) > 1
               AND ISNULL(k.GavelBelgeTipi, 1) = 1
               AND NOT {$ownerSql} = ''"
        )->fetchAll(PDO::FETCH_ASSOC);

        // Panel: ayarlar("domain"); web. alt alan adi www./cdn. olarak yayinlanir
        $domain = defined('Domain') ? rtrim(trim((string) constant('Domain')), '/') : '';
        $siteDomain = str_replace('web.', 'www.', $domain);
        $domainName = preg_replace('#^https?://#i', '', $siteDomain);
        $cdnDomain = str_replace('www.', 'cdn.', $siteDomain);

        $items = [];
        foreach ($rows as $row) {
            $item = [
                'domainName' => (string) $domainName,
                'documentNumber' => (string) $row['gavelBelgeNo'],
                'productName' => (string) $row['baslik'],
                'productId' => (string) $row['id'],
                'productUrl' => $siteDomain . '/' . (string) $row['url'],
                'productImageUrl' => $cdnDomain . '/uploads/' . (string) $row['resim'],
                'OwnerName' => (string) $row['OwnerName'],
                'ministryData' => '{}',
            ];
            // Eski panel: DocumentType 0 ise gonderilmez
            if ((int) $row['DocumentType'] !== 0) {
                $item['DocumentType'] = (int) $row['DocumentType'];
            }
            $items[] = $item;
        }

        $this->response->success(['items' => $items]);
    }

    /**
     * Bulkcheckv2 sonuclarini kanun7464'e yazar. Yalnizca Konak (veya tipi bos) kayitlar guncellenir.
     *
     * @Post
     * @body rows array required Bulkcheckv2 sonuc satirlari
     * @body import_owner bool Bos belge sahibi unvanina sitelerde en cok secilen unvani yaz
     */
    public function save(): void
    {
        $rows = $this->request->input('rows');
        if (!is_array($rows) || $rows === []) {
            throw new HttpException('Kaydedilecek belge sonucu bulunamadi.', 'VALIDATION', 422);
        }
        if (count($rows) > 500) {
            throw new HttpException('Tek seferde en fazla 500 belge sonucu kaydedilebilir.', 'VALIDATION', 422);
        }
        $importOwner = filter_var($this->request->input('import_owner', false), FILTER_VALIDATE_BOOLEAN);

        $pdo = $this->db->pdo();
        $this->assertTable($pdo);

        // Sure tipi ayardaki kolona yazilir (gavel_duration_column)
        $durationColumn = $this->gavelDurationColumn();
        $stmt = $pdo->prepare(
            "UPDATE dbo.kanun7464 SET
                gavelBelgeSahip = :sahip,
                gavelIl = :il,
                gavelBelgeKonutTip = :konutTip,
                gavelYatakSayi = :yatak,
                gavelOdaSayi = :oda,
                gavelIlce = :ilce,
                gavelAdres = :adres,
                gavelKonutAdet = :konutAdet,
                [{$durationColumn}] = :sureTipi,
                belgeSahibiUnvani = (CASE WHEN :unvan1 = '' OR NOT ISNULL(belgeSahibiUnvani, '') = '' THEN belgeSahibiUnvani ELSE :unvan2 END)
             WHERE ISNULL(GavelBelgeTipi, 1) = 1 AND REPLACE(gavelBelgeNo, ' ', '') = :belgeNo"
        );

        $processed = 0;
        $notFound = 0;
        $affected = 0;

        $pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $belgeNo = str_replace(' ', '', trim((string) ($row['BelgeNo'] ?? '')));
                if ($belgeNo === '') {
                    continue;
                }

                $values = $this->rowValues($row, $importOwner);
                if ($values['not_found']) {
                    $notFound++;
                }

                $stmt->execute([
                    ':sahip' => $values['BelgeSahip'],
                    ':il' => $values['Il'],
                    ':konutTip' => $values['BelgeKonutTip'],
                    ':yatak' => $values['YatakSayi'],
                    ':oda' => $values['OdaSayi'],
                    ':ilce' => $values['Ilce'],
                    ':adres' => $values['Adres'],
                    ':konutAdet' => $values['KonutAdet'],
                    ':sureTipi' => $this->gavelDurationDbValue($values['DocumentType']),
                    ':unvan1' => $values['OwnerName'],
                    ':unvan2' => $values['OwnerName'],
                    ':belgeNo' => $belgeNo,
                ]);
                $affected += $stmt->rowCount();
                $processed++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw new HttpException('Belge bilgileri guncellenemedi.', 'DB_UPDATE_FAILED', 500, $e);
        }

        $this->response->success([
            'processed' => $processed,
            'not_found' => $notFound,
            'affected_rows' => $affected,
        ]);
    }

    /**
     * Eski paneldeki istemci donusumu: bulunamayan belgelerde alanlar bosaltilir ve sureli (2) sayilir,
     * digerlerinde gecici belge ise sureli (2), degilse suresiz (1).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function rowValues(array $row, bool $importOwner): array
    {
        $notFound = (string) ($row['Durum'] ?? '') === self::NOT_FOUND_STATUS
            || (string) ($row['Message'] ?? '') === self::DB_FALLBACK_MESSAGE;

        $fields = ['BelgeSahip', 'Il', 'Ilce', 'BelgeKonutTip', 'Adres', 'KonutAdet', 'OdaSayi', 'YatakSayi'];
        $values = ['not_found' => $notFound];
        foreach ($fields as $field) {
            $value = $notFound ? '' : ($row[$field] ?? '');
            $values[$field] = is_scalar($value) ? trim((string) $value) : '';
        }

        $temporary = $notFound || !empty($row['GeciciBelgeDurumundaMi']);
        $values['DocumentType'] = $temporary ? '2' : '1';
        $values['OwnerName'] = $importOwner && !$notFound ? $this->mostUsedOwner($row['DomainRecords'] ?? []) : '';

        return $values;
    }

    /**
     * Belgenin kayitli oldugu sitelerde en cok secilen unvan; esitlikte ilk gelen.
     *
     * @param mixed $records
     */
    private function mostUsedOwner($records): string
    {
        if (!is_array($records)) {
            return '';
        }

        $counts = [];
        foreach ($records as $record) {
            $name = is_array($record) ? trim((string) ($record['OwnerName'] ?? '')) : '';
            if ($name !== '') {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }
        if ($counts === []) {
            return '';
        }

        $best = '';
        $bestCount = 0;
        foreach ($counts as $name => $count) {
            if ($count > $bestCount) {
                $best = (string) $name;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * Gavel secenekleri (genel tablosu). Tabloda olmayan kolon null doner (available listesinde yer almaz).
     *
     * @Get("settings")
     */
    public function settings(): void
    {
        $pdo = $this->db->pdo();
        $table = self::SETTINGS_TABLE;
        $columns = $this->tableColumns($pdo, $table);
        if ($columns === []) {
            throw new HttpException($table . ' tablosu bulunamadi.', 'NOT_FOUND', 404);
        }

        $row = $pdo->query('SELECT TOP 1 * FROM dbo.[' . $table . '] WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $row = $pdo->query('SELECT TOP 1 * FROM dbo.[' . $table . '] ORDER BY id')->fetch(PDO::FETCH_ASSOC);
        }
        if (!is_array($row)) {
            throw new HttpException('Genel ayar kaydi bulunamadi.', 'NOT_FOUND', 404);
        }
        $row = array_change_key_case($row, CASE_LOWER);

        $data = [];
        $available = [];
        foreach (self::SETTING_OPTIONS as $key => $option) {
            $column = strtolower($option['column']);
            $data[$key] = array_key_exists($column, $row) ? $this->optionValue($row[$column]) : null;
            if (in_array($column, $columns, true)) {
                $available[] = $key;
            }
        }

        $dateColumn = strtolower(self::SETTING_DATE_COLUMN);
        $data['explode_date'] = array_key_exists($dateColumn, $row) ? $this->dateValue($row[$dateColumn]) : null;
        if (in_array($dateColumn, $columns, true)) {
            $available[] = 'explode_date';
        }

        $messageColumn = strtolower(self::SETTING_MESSAGE_COLUMN);
        $data['stop_sell_message'] = array_key_exists($messageColumn, $row) ? (string) ($row[$messageColumn] ?? '') : null;
        if (in_array($messageColumn, $columns, true)) {
            $available[] = 'stop_sell_message';
        }

        $data['available'] = $available;
        $this->response->success($data);
    }

    /**
     * Gavel seceneklerini kaydeder. Yalnizca gonderilen ve tabloda bulunan kolonlar guncellenir;
     * genel tablosunun diger alanlarina dokunulmaz.
     *
     * @Post("settings")
     * @body explode_option string 0 satisa kapali / 1 satisa acik
     * @body explode_date string Y-m-d, bos birakilabilir
     * @body owner_option string 0 kayitli evsahibi / 1 belgeye ait evsahibi / 2 belge sahibi unvani
     * @body detail_option string 0 kisitli goster / 1 rezervasyon sayfasina yonlendir
     * @body listing_option string 0 gosterme / 1 goster
     * @body search_option string 0 gosterme / 1 goster
     * @body stop_sell_message string Satisa durdur mesaji
     */
    public function saveSettings(): void
    {
        $pdo = $this->db->pdo();
        $table = self::SETTINGS_TABLE;
        $columns = $this->tableColumns($pdo, $table);
        if ($columns === []) {
            throw new HttpException($table . ' tablosu bulunamadi.', 'NOT_FOUND', 404);
        }

        $sets = [];
        $params = [];
        foreach (self::SETTING_OPTIONS as $key => $option) {
            $value = $this->request->input($key);
            if ($value === null) {
                continue;
            }
            $value = $this->optionValue($value);
            if (!in_array($value, $option['values'], true)) {
                throw new HttpException('Gecersiz deger: ' . $key, 'VALIDATION', 422);
            }
            if (!in_array(strtolower($option['column']), $columns, true)) {
                continue;
            }
            $sets[] = '[' . $option['column'] . '] = :' . $key;
            $params[':' . $key] = (int) $value;
        }

        $date = $this->request->input('explode_date');
        if ($date !== null && in_array(strtolower(self::SETTING_DATE_COLUMN), $columns, true)) {
            $date = trim((string) $date);
            if ($date !== '' && $this->dateValue($date) !== $date) {
                throw new HttpException('Gecersiz belge bitis tarihi.', 'VALIDATION', 422);
            }
            // Eski panel formdaki degeri (yyyy-mm-dd ya da bos) aynen yazar; tarih kolonunda bos deger NULL olur
            $isText = $this->isTextColumn($pdo, $table, self::SETTING_DATE_COLUMN);
            $sets[] = '[' . self::SETTING_DATE_COLUMN . '] = :explode_date';
            $params[':explode_date'] = $date === '' && !$isText ? null : $date;
        }

        $message = $this->request->input('stop_sell_message');
        if ($message !== null && in_array(strtolower(self::SETTING_MESSAGE_COLUMN), $columns, true)) {
            $message = trim((string) $message);
            if ($message === '') {
                throw new HttpException('Satışa durdur mesajı zorunludur.', 'VALIDATION', 422);
            }
            $sets[] = '[' . self::SETTING_MESSAGE_COLUMN . '] = :stop_sell_message';
            $params[':stop_sell_message'] = $message;
        }

        if ($sets === []) {
            throw new HttpException('Kaydedilecek ayar bulunamadi.', 'VALIDATION', 422);
        }

        // Eski panel genel{dbtable} tablosunun tek satirini (id = 1) kullanir
        $hasIdOne = (int) $pdo->query('SELECT COUNT(*) FROM dbo.[' . $table . '] WHERE id = 1')->fetchColumn() > 0;
        $where = $hasIdOne ? 'id = 1' : 'id = (SELECT MIN(id) FROM dbo.[' . $table . '])';

        try {
            $stmt = $pdo->prepare('UPDATE dbo.[' . $table . '] SET ' . implode(', ', $sets) . ' WHERE ' . $where);
            $stmt->execute($params);
        } catch (\Throwable $e) {
            throw new HttpException('Gavel ayarlari kaydedilemedi.', 'DB_UPDATE_FAILED', 500, $e);
        }

        $this->settings();
    }

    /** bit / int / metin kolonlardaki secenek degerini '0' / '1' / '2' bicimine getirir. */
    private function optionValue($value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        $text = strtolower(trim((string) $value));
        if ($text === 'true') {
            return '1';
        }
        if ($text === 'false') {
            return '0';
        }

        return is_numeric($text) ? (string) (int) $text : $text;
    }

    /** Tarih degerini Y-m-d olarak doner (kolon datetime ya da metin olabilir); gecersizse bos. */
    private function dateValue($value): string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return '';
        }
        foreach (['Y-m-d', 'Y-m-d H:i:s', 'Y-m-d H:i:s.u', 'd.m.Y', 'd/m/Y'] as $format) {
            $date = \DateTime::createFromFormat('!' . $format, $text);
            if ($date !== false && $date->format($format) === $text) {
                return $date->format('Y-m-d');
            }
        }
        $time = strtotime($text);

        return $time === false ? '' : date('Y-m-d', $time);
    }

    private function isTextColumn(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            "SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = :t AND COLUMN_NAME = :c"
        );
        $stmt->execute([':t' => $table, ':c' => $column]);

        return in_array(strtolower((string) $stmt->fetchColumn()), ['varchar', 'nvarchar', 'char', 'nchar', 'text', 'ntext'], true);
    }

    private function assertTable(PDO $pdo): void
    {
        $stmt = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'kanun7464'");
        if ((int) $stmt->fetchColumn() === 0) {
            throw new HttpException('kanun7464 tablosu bulunamadi.', 'NOT_FOUND', 404);
        }
    }
}
