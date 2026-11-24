<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Ilan listesindeki "Belge Ekle" penceresi (eski panel: emlak_yonetimi.asp > gavelModal, ?ajax=gavelBelge).
 *   GET  /homes-document?id=   ilanin kanun7464 kaydi, ilan bilgileri, ev sahibi belge loglari, Kiralama Takvimi baglantisi
 *   POST /homes-document       kanun7464 kaydini gunceller / olusturur, istege bagli ev sahibi logu yazar
 *
 * Belge dosyasi yukleme (gavelFileName) eski panelde kalir; bu uc nokta dosyaya dokunmaz.
 * Bakanlik sorgusu, Kiralama Takvimi'ne belge no gonderme ve belge sorgu servisine (SaveData) kayit
 * istemci / Web.Api tarafinda yapilir; Kiralama Takvimi sonucu buraya kiralama_takvimi olarak gelir.
 */
final class HomesDocumentController extends Controller
{
    private const LOG_TABLE = 'homesOwnerDocumentLog';

    /** Ev sahibi log durumlari (eski panel: Log Ekle secenekleri) */
    private const LOG_STATUSES = [
        '0' => 'Beklemede Durumuna Alındı',
        '1' => 'Onay Bekliyor Durumuna Alındı',
        '3' => 'Onaylandı Durumuna Alındı',
    ];

    /**
     * Belge Ekle penceresinin verileri.
     *
     * @Get
     * @query id int required Emlak kimligi
     */
    public function index(): void
    {
        $id = (int) $this->request->query('id', 0);
        if ($id <= 0) {
            throw new HttpException('Lütfen geçerli bir emlak ID gönderin.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();
        $this->assertKanunTable($pdo);
        $home = $this->fetchHome($pdo, $id);

        $stmt = $pdo->prepare('SELECT TOP 1 * FROM kanun7464 WHERE homeId = :id');
        $stmt->execute([':id' => $id]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);
        $row = is_array($found) ? array_change_key_case($found, CASE_LOWER) : [];
        $columns = $this->kanunColumns($pdo);

        $belgeNo = $this->str($row, 'gavelbelgeno');
        // Config homes_belge_no_homes: kanun7464'te belge no yoksa eski panelin yazdigi homes.gavelBelgeNo
        if ($belgeNo === '' && !empty($this->app['homes_belge_no_homes']) && $this->columnExists($pdo, 'homes', 'gavelBelgeNo')) {
            $h = $pdo->prepare('SELECT gavelBelgeNo FROM homes WHERE id = :id');
            $h->execute([':id' => $id]);
            $belgeNo = trim((string) $h->fetchColumn());
        }

        $durationColumn = strtolower($this->gavelDurationColumn());
        $durationRaw = $this->str($row, $durationColumn);
        $duration = '';
        if ($durationRaw !== '') {
            $duration = $this->gavelDurationIsZeroBased() ? ($durationRaw === '1' ? '2' : '1') : $durationRaw;
        }

        $hasLogTable = $this->tableExists($pdo, self::LOG_TABLE);

        $this->response->success([
            'id' => $id,
            'exists' => $row !== [],
            'home' => $home,
            'document' => [
                'belge_no' => $belgeNo,
                'basvuru_no' => $this->str($row, 'gavelbasvuruno'),
                'basvuru_ad_soyad' => $this->str($row, 'gavelbasvuruadsoyad'),
                'belge_tipi' => $this->str($row, 'gavelbelgetipi') !== '' ? $this->str($row, 'gavelbelgetipi') : '1',
                'belge_suresi_tipi' => $duration,
                'belge_sureli' => $this->str($row, 'gavelbelgesureli'),
                'belge_sahibi_unvani' => $this->str($row, 'belgesahibiunvani'),
                'belge_json' => $this->str($row, 'gavelbelgedata'),
                // gavel: 0 aktif, 1 pasif (kayit yoksa aktif sayilir)
                'aktif' => $row === [] ? true : ((int) ($row['gavel'] ?? 0)) === 0,
                'kiralama_takvimi' => $row === [] || $this->str($row, 'gavelkiralamatakvimi') === '' ? null : ((int) $row['gavelkiralamatakvimi']) === 1,
                'dosya' => $this->str($row, 'gavelfilename'),
            ],
            // Pencerede yalnizca sitede olan alanlar gosterilir
            'fields' => [
                'belge_sureli' => isset($columns['gavelbelgesureli']),
                'basvuru' => isset($columns['gavelbasvuruno']),
                'owner_log' => $hasLogTable,
            ],
            'logs' => $hasLogTable ? $this->fetchLogs($pdo, $id) : [],
            'calendar_estate_id' => $this->calendarEstateId($pdo, $id),
            'save_data' => $this->saveDataBase($home),
        ]);
    }

    /**
     * Belge Ekle penceresini kaydeder (eski panel: ?ajax=gavelBelge). Gelen alanlar yazilir, gelmeyenlere dokunulmaz.
     *
     * @Post
     * @body id int required Emlak kimligi
     * @body aktif bool Kaydet (true) / Pasif Et (false); kanun7464.gavel 0/1
     * @body belge_no string Belge numarasi
     * @body basvuru_no string Basvuru numarasi
     * @body basvuru_ad_soyad string Basvuru sahibi
     * @body belge_tipi int 1 Konak, 2 Basit Konak
     * @body belge_suresi_tipi int 1 Suresiz, 2 Sureli
     * @body belge_sureli int 0 Yok, 1 Var (GavelBelgeSureli kolonu varsa)
     * @body belge_sahibi_unvani string Belge sahibi unvani
     * @body belge_json string Bakanlik sorgu sonucu (JSON); alanlari gavelBelgeSahip, gavelIl... kolonlarina da yazilir
     * @body kiralama_takvimi bool Belge no Kiralama Takvimi'ne aktarildi mi (istemci gonderir)
     * @body log_ekle string Ev sahibi log durumu: 0 Beklemede, 1 Onay Bekliyor, 3 Onaylandi
     * @body kullanici_adi string Islemi yapan (Web.Api ekler)
     */
    public function save(): void
    {
        $id = (int) $this->request->input('id', 0);
        if ($id <= 0) {
            throw new HttpException('Lütfen geçerli bir emlak ID gönderin.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();
        $this->assertKanunTable($pdo);
        $this->fetchHome($pdo, $id);
        $columns = $this->kanunColumns($pdo);

        $values = [];
        $text = [
            'belge_no' => 'gavelBelgeNo',
            'basvuru_no' => 'gavelBasvuruNo',
            'basvuru_ad_soyad' => 'gavelBasvuruAdSoyad',
            'belge_sahibi_unvani' => 'belgeSahibiUnvani',
        ];
        foreach ($text as $key => $column) {
            if ($this->has($key)) {
                $values[$column] = trim((string) $this->request->input($key, ''));
            }
        }

        if ($this->has('belge_tipi')) {
            $values['GavelBelgeTipi'] = $this->intOrNull($this->request->input('belge_tipi'));
        }

        // Sure tipi: eski panel belgeSuresiTipi (1/2) ve GavelBelgeSureli (0/1) kolonlarini ayri ayri yazar.
        // Ayardaki sure kolonu (gavel_duration_column) bunlardan biri degilse ona da kendi degeriyle yazilir.
        if ($this->has('belge_suresi_tipi')) {
            $tip = trim((string) $this->request->input('belge_suresi_tipi', ''));
            $values['belgeSuresiTipi'] = ($tip === '1' || $tip === '2') ? (int) $tip : null;
            $durationColumn = $this->gavelDurationColumn();
            if (strcasecmp($durationColumn, 'belgeSuresiTipi') !== 0 && strcasecmp($durationColumn, 'GavelBelgeSureli') !== 0) {
                $values[$durationColumn] = $this->gavelDurationDbValue($tip);
            }
            if (strcasecmp($durationColumn, 'GavelBelgeSureli') === 0 && !$this->has('belge_sureli')) {
                $values['GavelBelgeSureli'] = $this->gavelDurationDbValue($tip);
            }
        }
        if ($this->has('belge_sureli')) {
            $values['GavelBelgeSureli'] = $this->intOrNull($this->request->input('belge_sureli'));
        }

        // Sorgu sonucu: JSON ve alanlari (eski panel: belgejson, belgejsonmin ve gizli inputlar)
        if ($this->has('belge_json')) {
            $json = trim((string) $this->request->input('belge_json', ''));
            $values['gavelBelgeData'] = $json;
            $values['gavelBelgeDataMin'] = $json === '' ? '' : $this->encodeUriComponent($json);
            $data = $json !== '' ? json_decode($json, true) : null;
            $fields = [
                'BelgeSahip' => 'gavelBelgeSahip',
                'Il' => 'gavelIl',
                'Ilce' => 'gavelIlce',
                'BelgeKonutTip' => 'gavelBelgeKonutTip',
                'Adres' => 'gavelAdres',
                'OdaSayi' => 'gavelOdaSayi',
                'YatakSayi' => 'gavelYatakSayi',
                'KonutAdet' => 'gavelKonutAdet',
            ];
            foreach ($fields as $key => $column) {
                $value = is_array($data) ? ($data[$key] ?? '') : '';
                $values[$column] = is_scalar($value) ? trim((string) $value) : '';
            }
        }

        $aktif = filter_var($this->request->input('aktif', true), FILTER_VALIDATE_BOOLEAN);
        $values['gavel'] = $aktif ? 0 : 1;

        // Kiralama Takvimi aktarimi yalnizca belge no varken yazilir (eski panel gibi)
        $belgeNo = (string) ($values['gavelBelgeNo'] ?? '');
        if ($belgeNo !== '' && $this->has('kiralama_takvimi')) {
            $values['gavelKiralamaTakvimi'] = filter_var($this->request->input('kiralama_takvimi'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        // Tabloda olmayan kolonlar atlanir
        $set = [];
        $skipped = [];
        foreach ($values as $column => $value) {
            $info = $columns[strtolower($column)] ?? null;
            if ($info === null) {
                $skipped[] = $column;
                continue;
            }
            $set[$info] = $value;
        }

        $logStatus = trim((string) $this->request->input('log_ekle', ''));
        $userName = trim((string) $this->request->input('kullanici_adi', ''));

        $pdo->beginTransaction();
        try {
            $exists = $pdo->prepare('SELECT COUNT(*) FROM kanun7464 WHERE homeId = :id');
            $exists->execute([':id' => $id]);
            $hasRow = (int) $exists->fetchColumn() > 0;

            $params = [];
            $i = 0;
            foreach (array_keys($set) as $column) {
                $params[$column] = ':v' . $i++;
            }
            if ($hasRow) {
                $assign = [];
                foreach ($params as $column => $param) {
                    $assign[] = '[' . $column . '] = ' . $param;
                }
                $sql = 'UPDATE kanun7464 SET ' . implode(', ', $assign) . ' WHERE homeId = :id';
            } else {
                $sql = 'INSERT INTO kanun7464 (homeId' . ($params ? ', [' . implode('], [', array_keys($params)) . ']' : '')
                    . ') VALUES (:id' . ($params ? ', ' . implode(', ', $params) : '') . ')';
            }
            $write = $pdo->prepare($sql);
            $write->bindValue(':id', $id, PDO::PARAM_INT);
            foreach ($params as $column => $param) {
                $value = $set[$column];
                if ($value === null) {
                    $write->bindValue($param, null, PDO::PARAM_NULL);
                } elseif (is_int($value)) {
                    $write->bindValue($param, $value, PDO::PARAM_INT);
                } else {
                    $write->bindValue($param, (string) $value, PDO::PARAM_STR);
                }
            }
            $write->execute();

            // Config homes_belge_no_homes: belge no homes.gavelBelgeNo'ya da yazilir
            if (array_key_exists('gavelBelgeNo', $values) && !empty($this->app['homes_belge_no_homes'])
                && $this->columnExists($pdo, 'homes', 'gavelBelgeNo')
            ) {
                $h = $pdo->prepare('UPDATE homes SET gavelBelgeNo = :no WHERE id = :id');
                $h->execute([':no' => $belgeNo, ':id' => $id]);
            }

            // Ev sahibi belge logu (eski panel: GavelModuleEvSahibi); tablo yoksa atlanir
            $logged = 0;
            if ($this->tableExists($pdo, self::LOG_TABLE)) {
                $who = $userName !== '' ? $userName : 'Natsisa';
                $this->insertLog($pdo, $id, $who . ', Veri Güncellemesi Yaptı', 3);
                $logged++;
                if (isset(self::LOG_STATUSES[$logStatus])) {
                    $this->insertLog($pdo, $id, $who . ', ' . self::LOG_STATUSES[$logStatus], (int) $logStatus);
                    $logged++;
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new HttpException('Belge kaydedilemedi: ' . $e->getMessage(), 'DB_UPDATE_FAILED', 500);
        }

        $this->response->success([
            'id' => $id,
            'created' => !$hasRow,
            'aktif' => $aktif,
            'updated_columns' => array_keys($set),
            'skipped_columns' => $skipped,
            'logs_added' => $logged,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchHome(PDO $pdo, int $id): array
    {
        $resim = $this->columnExists($pdo, 'homes', 'resim') ? 'h.resim' : "CAST('' AS nvarchar(10))";
        $stmt = $pdo->prepare(
            "SELECT TOP 1
                h.id,
                ISNULL(h.baslik, '') AS baslik,
                ISNULL(h.url, '') AS url,
                ISNULL({$resim}, '') AS resim,
                ISNULL(CONVERT(nvarchar(50), h.yatak_sayisi), '') AS yatak_sayisi,
                ISNULL(CONVERT(nvarchar(50), h.yatak_odasi), '') AS oda_sayisi,
                ISNULL(es.ad + ' ' + es.soyad, '') AS ev_sahibi,
                ISNULL(d.baslik, '') AS bolge,
                ISNULL(p.baslik, '') AS ust_bolge
             FROM homes h
             LEFT JOIN kullanici es ON es.id = h.evsahibi
             LEFT JOIN destinations d ON d.id = h.emlak_bolgesi
             LEFT JOIN destinations p ON p.id = d.cat
             WHERE h.id = :id"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new HttpException('Emlak bulunamadı.', 'NOT_FOUND', 404);
        }

        $images = array_values(array_filter(array_map('trim', explode(',', (string) $row['resim']))));
        $konum = trim(implode(' / ', array_filter([trim((string) $row['ust_bolge']), trim((string) $row['bolge'])])));

        return [
            'id' => (int) $row['id'],
            'baslik' => (string) $row['baslik'],
            'url' => (string) $row['url'],
            'resim' => $images[0] ?? '',
            'ev_sahibi' => trim((string) $row['ev_sahibi']),
            'konum' => $konum,
            'yatak_sayisi' => (string) $row['yatak_sayisi'],
            'oda_sayisi' => (string) $row['oda_sayisi'],
        ];
    }

    /**
     * Belge sorgu servisine (SaveData / BulkSaveData) gidecek ilan bilgileri; istemci unvan, belge no ve sure tipini ekler.
     * Panel: ayarlar("domain"); web. alt alan adi www./cdn. olarak yayinlanir (gavel-management/export ile ayni).
     *
     * @param array<string,mixed> $home
     * @return array<string,string>
     */
    private function saveDataBase(array $home): array
    {
        $domain = defined('Domain') ? rtrim(trim((string) constant('Domain')), '/') : '';
        $siteDomain = str_replace('web.', 'www.', $domain);
        $domainName = (string) preg_replace('#^https?://#i', '', $siteDomain);
        $cdnDomain = str_replace('www.', 'cdn.', $siteDomain);

        return [
            'domainName' => $domainName,
            'productName' => (string) $home['baslik'],
            'productId' => (string) $home['id'],
            'productUrl' => $siteDomain . '/' . (string) $home['url'],
            'productImageUrl' => $cdnDomain . '/uploads/' . (string) $home['resim'],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchLogs(PDO $pdo, int $id): array
    {
        try {
            $stmt = $pdo->prepare('SELECT TOP 100 * FROM ' . self::LOG_TABLE . ' WHERE homeId = :id');
            $stmt->execute([':id' => $id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        $logs = [];
        foreach ($rows as $row) {
            $lower = array_change_key_case($row, CASE_LOWER);
            $date = '';
            foreach (['tarih', 'createdat', 'created_at', 'date', 'kayittarihi'] as $key) {
                if (!empty($lower[$key])) {
                    $date = (string) $lower[$key];
                    break;
                }
            }
            $logs[] = [
                'id' => isset($lower['id']) ? (int) $lower['id'] : null,
                'message' => (string) ($lower['message'] ?? ''),
                'value' => (string) ($lower['value'] ?? ''),
                'status' => isset($lower['status']) ? (int) $lower['status'] : null,
                'bg_color' => (string) ($lower['bgcolor'] ?? ''),
                'date' => $date,
            ];
        }
        // En yeni ustte (id varsa id'ye gore)
        usort($logs, static function (array $a, array $b): int {
            return ($b['id'] ?? 0) <=> ($a['id'] ?? 0);
        });

        return $logs;
    }

    private function insertLog(PDO $pdo, int $id, string $message, int $status): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO ' . self::LOG_TABLE . " (homeId, message, value, bgColor, status) VALUES (:id, :message, '', 'info', :status)"
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':message', $message, PDO::PARAM_STR);
        $stmt->bindValue(':status', $status, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function calendarEstateId(PDO $pdo, int $id): ?int
    {
        try {
            if ($pdo->query("SELECT OBJECT_ID('KiralamaTakvimi.CalendarHomes', 'U')")->fetchColumn() === null) {
                return null;
            }
            $stmt = $pdo->prepare('SELECT TOP 1 EstateId FROM KiralamaTakvimi.CalendarHomes WHERE homesId = :id');
            $stmt->execute([':id' => $id]);
            $estateId = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return null;
        }

        return $estateId !== false && (int) $estateId > 0 ? (int) $estateId : null;
    }

    /**
     * kanun7464 kolonlari: kucuk harf => gercek ad
     *
     * @return array<string,string>
     */
    private function kanunColumns(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'kanun7464'");
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $name = (string) $name;
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1) {
                $columns[strtolower($name)] = $name;
            }
        }

        return $columns;
    }

    private function assertKanunTable(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'kanun7464')) {
            throw new HttpException('kanun7464 tablosu bulunamadı.', 'NOT_FOUND', 404);
        }
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = :t");
        $stmt->execute([':t' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = :t AND COLUMN_NAME = :c"
        );
        $stmt->execute([':t' => $table, ':c' => $column]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function has(string $key): bool
    {
        return $this->request->input($key, null) !== null;
    }

    /**
     * @param mixed $value
     */
    private function intOrNull($value): ?int
    {
        $text = trim((string) $value);

        return $text !== '' && is_numeric($text) ? (int) $text : null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function str(array $row, string $key): string
    {
        return trim((string) ($row[$key] ?? ''));
    }

    /** JavaScript encodeURIComponent karsiligi (eski panel gavelBelgeDataMin) */
    private function encodeUriComponent(string $value): string
    {
        return strtr(rawurlencode($value), ['%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')']);
    }
}
