<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;
use ZipArchive;

/*
 * GİB BTRANS (Gelir İdaresi Başkanlığı Günbirlik Konaklama Veri Transferi) Controller.
 * Eski btrans.asp ve btrans.php mantığının modern API karşılığıdır.
 *
 * Uç Noktalar:
 *   GET  /backend-api/btrans                -> Kaydedilmiş BTRANS dosyaları listesi (varsayılan: sayfa 1)
 *   GET  /backend-api/btrans/list           -> Kaydedilmiş BTRANS dosyaları listesi (sayfalama destekli)
 *   GET  /backend-api/btrans/detail         -> Tek bir BTRANS kaydının detay ve XML içeriği
 *   POST /backend-api/btrans/delete         -> Kaydedilmiş bir BTRANS dosyasını siler
 *   GET  /backend-api/btrans/years          -> Raporlama için mevcut rezervasyon yılları
 *   GET  /backend-api/btrans/records        -> Belirli dönem (yıl/ay) için BTRANS kayıtlarını önizler/listeler
 *   POST /backend-api/btrans/create         -> Seçilen kayıtlar için resmi GİB BTRANS XML üretir ve kaydeder
 *   GET  /backend-api/btrans/download       -> Dosyayı indirir (format: xml, zip, xls, excel; dosya: 1, 2, 3)
 *   GET  /backend-api/btrans/xml            -> XML dosyasını doğrudan indirir
 *   GET  /backend-api/btrans/zip            -> ZIP arşivini doğrudan indirir 
 *   GET  /backend-api/btrans/excel          -> Excel tablosunu doğrudan indirir (dosya: 1, 2, 3)
 *   POST /backend-api/btrans/setup          -> Btrans tablosu ve ilişkili kolonları kontrol edip eksikleri tamamlar
 */
final class BtransController extends Controller
{
    /** @var bool|null */
    private $hasFnTemizle = null; 

    /**
     * Kaydedilen BTRANS dosyaları listesi.
     *
     * @Get
     * @Get("list")
     * @query page int Sayfa numarası (varsayılan: 1)
     * @query perPage int Sayfa başına kayıt sayısı (varsayılan: 50)
     */
    public function list(): void
    {
        $pdo = $this->db->pdo();
        $this->ensureBtransTable($pdo);

        $page = max(1, (int) ($this->request->query('page', 1)));
        $perPage = max(1, min(500, (int) ($this->request->query('perPage', 50))));
        $offset = ($page - 1) * $perPage;

        $totalStmt = $pdo->query('SELECT COUNT(*) FROM dbo.btrans');
        $total = (int) ($totalStmt ? $totalStmt->fetchColumn() : 0);

        $safeOffset = max(0, $offset);
        $safeLimit = max(1, $perPage);

        $sql = "SELECT id, FileName, sayfa, yil, ay, hashurl, NumberOfProccess, NumberOfOriginalProccess,
                       isEdit, isDownload, CreatedDateTime
                FROM dbo.btrans
                ORDER BY CreatedDateTime DESC
                OFFSET {$safeOffset} ROWS FETCH NEXT {$safeLimit} ROWS ONLY";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        foreach ($rows as $row) {
            $hash = trim((string) ($row['hashurl'] ?? ''));
            $id = (int) ($row['id'] ?? 0);
            $year = trim((string) ($row['yil'] ?? ''));
            $month = str_pad(trim((string) ($row['ay'] ?? '')), 2, '0', STR_PAD_LEFT);
            $isEdit = (bool) ($row['isEdit'] ?? false);
            $isDownload = (bool) ($row['isDownload'] ?? false);

            $items[] = [
                'id' => $id,
                'fileName' => trim((string) ($row['FileName'] ?? '')),
                'year' => $year,
                'month' => $month,
                'period' => ($year !== '' && $month !== '') ? ($year . '/' . $month) : '',
                'page' => (int) ($row['sayfa'] ?? 1),
                'hashUrl' => $hash,
                'numberOfProcess' => (int) ($row['NumberOfProccess'] ?? 0),
                'numberOfOriginalProcess' => (int) ($row['NumberOfOriginalProccess'] ?? 0),
                'isEdit' => $isEdit,
                'status' => $isEdit ? 'Düzenlendi' : 'Orjinal',
                'isDownload' => $isDownload,
                'createdDateTime' => $this->formatDateTime($row['CreatedDateTime'] ?? null),
                'downloadUrls' => [
                    'xml' => '/btrans/xml?hashurl=' . rawurlencode($hash),
                    'zip' => '/btrans/zip?hashurl=' . rawurlencode($hash),
                    'excel' => '/btrans/excel?hashurl=' . rawurlencode($hash) . '&dosya=2',
                    'faturaExcel' => '/btrans/excel?hashurl=' . rawurlencode($hash) . '&dosya=3',
                    'protokolExcel' => '/btrans/excel?hashurl=' . rawurlencode($hash) . '&dosya=1',
                ],
            ];
        }

        $this->response->success([
            'items' => $items,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'perPage' => $perPage,
                'totalPages' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * Tek bir BTRANS kaydının detayını ve XML içeriğini getirir.
     *
     * @Get("detail")
     * @query id int BTRANS kayıt kimliği
     * @query hashurl string BTRANS hash kodu
     */
    public function detail(): void
    {
        $pdo = $this->db->pdo();
        $this->ensureBtransTable($pdo);

        $row = $this->findBtransRecord($pdo);
        if (!$row) {
            throw new HttpException('BTRANS kaydı bulunamadı.', 'RECORD_NOT_FOUND', 404);
        }

        $hash = trim((string) ($row['hashurl'] ?? ''));
        $year = trim((string) ($row['yil'] ?? ''));
        $month = str_pad(trim((string) ($row['ay'] ?? '')), 2, '0', STR_PAD_LEFT);
        $isEdit = (bool) ($row['isEdit'] ?? false);

        $this->response->success([
            'id' => (int) $row['id'],
            'fileName' => trim((string) ($row['FileName'] ?? '')),
            'year' => $year,
            'month' => $month,
            'period' => ($year !== '' && $month !== '') ? ($year . '/' . $month) : '',
            'page' => (int) ($row['sayfa'] ?? 1),
            'hashUrl' => $hash,
            'numberOfProcess' => (int) ($row['NumberOfProccess'] ?? 0),
            'numberOfOriginalProcess' => (int) ($row['NumberOfOriginalProccess'] ?? 0),
            'isEdit' => $isEdit,
            'status' => $isEdit ? 'Düzenlendi' : 'Orjinal',
            'isDownload' => (bool) ($row['isDownload'] ?? false),
            'sqlEkVeri' => trim((string) ($row['SqlEkVeri'] ?? '')),
            'xmlContent' => (string) ($row['veri'] ?? ''),
            'createdDateTime' => $this->formatDateTime($row['CreatedDateTime'] ?? null),
            'downloadUrls' => [
                'xml' => '/btrans/xml?hashurl=' . rawurlencode($hash),
                'zip' => '/btrans/zip?hashurl=' . rawurlencode($hash),
                'excel' => '/btrans/excel?hashurl=' . rawurlencode($hash) . '&dosya=2',
                'faturaExcel' => '/btrans/excel?hashurl=' . rawurlencode($hash) . '&dosya=3',
                'protokolExcel' => '/btrans/excel?hashurl=' . rawurlencode($hash) . '&dosya=1',
            ],
        ]);
    }

    /**
     * Kaydedilen BTRANS dosyasını siler.
     *
     * @Post("delete")
     * @body id int required Silinecek BTRANS kayıt kimliği
     * @body hashurl string Silinecek BTRANS hash kodu
     */
    public function delete(): void
    {
        $pdo = $this->db->pdo();
        $this->ensureBtransTable($pdo);

        $id = $this->request->input('id', $this->request->query('id'));
        $hash = trim((string) ($this->request->input('hashurl', $this->request->query('hashurl')) ?? ''));

        if ((!$id || !is_numeric($id)) && $hash === '') {
            throw new HttpException('Geçerli bir id veya hashurl belirtilmelidir.', 'VALIDATION', 422);
        }

        if ($id && is_numeric($id)) {
            $stmt = $pdo->prepare('DELETE FROM dbo.btrans WHERE id = :id');
            $stmt->execute([':id' => (int) $id]);
        } else {
            $stmt = $pdo->prepare('DELETE FROM dbo.btrans WHERE hashurl = :hash');
            $stmt->execute([':hash' => $hash]);
        }

        $this->response->success([
            'deleted' => true,
            'id' => $id ? (int) $id : null,
            'hashUrl' => $hash ?: null,
        ]);
    }

    /**
     * BTRANS bildirimine konu rezervasyonların bulunduğu yılları listeler.
     *
     * @Get("years")
     */
    public function years(): void
    {
        $pdo = $this->db->pdo();
        $years = [];

        try {
            $stmt = $pdo->query(
                "SELECT DISTINCT YEAR(TRY_CONVERT(date, rez_tarihi, 103)) AS tarih
                 FROM dbo.kayitlar
                 WHERE TRY_CONVERT(date, rez_tarihi, 103) IS NOT NULL
                   AND YEAR(TRY_CONVERT(date, rez_tarihi, 103)) > 2021
                 ORDER BY tarih ASC"
            );
            if ($stmt) {
                while ($val = $stmt->fetchColumn()) {
                    if ($val !== null && is_numeric($val)) {
                        $years[] = (int) $val;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fallback for older SQL Server syntax
            try {
                $stmt = $pdo->query(
                    "SELECT DISTINCT YEAR(CONVERT(date, rez_tarihi, 103)) AS tarih
                     FROM dbo.kayitlar
                     WHERE rez_tarihi IS NOT NULL
                     ORDER BY tarih ASC"
                );
                if ($stmt) {
                    while ($val = $stmt->fetchColumn()) {
                        if ($val !== null && (int) $val > 2021) {
                            $years[] = (int) $val;
                        }
                    }
                }
            } catch (\Throwable $e2) {
                $currentYear = (int) date('Y');
                $years = range(2022, $currentYear);
            }
        }

        if (empty($years)) {
            $currentYear = (int) date('Y');
            $years = range(2022, max(2022, $currentYear));
        }

        $this->response->success([
            'years' => array_values(array_unique($years)),
        ]);
    }

    /**
     * Seçilen dönem için BTRANS kayıtlarını listeler ve önizleme sağlar.
     *
     * @Get("records")
     * @query year int required Raporlama yılı (örn: 2024)
     * @query month int required Raporlama ayı (1-12)
     * @query page int Sayfa numarası (varsayılan: 1)
     * @query perPage int Sayfa başına kayıt sayısı (varsayılan: 300)
     * @query hashurl string Kaydedilmiş bir BTRANS dosyasına ait kayıtları getirmek için hashurl
     */
    public function records(): void
    {
        $pdo = $this->db->pdo();
        $this->ensureBtransTable($pdo);

        $year = (int) $this->request->query('year');
        $month = (int) $this->request->query('month');
        $hashurl = trim((string) ($this->request->query('hashurl') ?? ''));

        // Eğer hashurl verilmişse ve year/month boşsa, btrans tablosundan oku
        if (($year <= 0 || $month <= 0) && $hashurl !== '') {
            $btransStmt = $pdo->prepare('SELECT yil, ay, SqlEkVeri FROM dbo.btrans WHERE hashurl = :hash');
            $btransStmt->execute([':hash' => $hashurl]);
            $btransRow = $btransStmt->fetch(PDO::FETCH_ASSOC);
            if ($btransRow) {
                $year = (int) ($btransRow['yil'] ?? 0);
                $month = (int) ($btransRow['ay'] ?? 0);
            }
        }

        if ($year <= 2000 || $month < 1 || $month > 12) {
            throw new HttpException('Geçerli bir yıl ve ay (1-12) belirtilmelidir.', 'VALIDATION', 422);
        }

        $page = max(1, (int) ($this->request->query('page', 1)));
        $perPage = max(1, min(1000, (int) ($this->request->query('perPage', 300))));

        $monthStr = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
        $periodStart = '01/' . $monthStr . '/' . $year;
        $lastDay = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
        $periodEnd = str_pad((string) $lastDay, 2, '0', STR_PAD_LEFT) . '/' . $monthStr . '/' . $year;

        // Ekstra SQL filtresi (hashurl veya SqlEkVeri)
        $sqlEkVeri = '';
        if ($hashurl !== '') {
            $btransStmt = $pdo->prepare('SELECT SqlEkVeri FROM dbo.btrans WHERE hashurl = :hash');
            $btransStmt->execute([':hash' => $hashurl]);
            $savedSql = (string) $btransStmt->fetchColumn();
            if (trim($savedSql) !== '') {
                $sqlEkVeri = ' ' . trim($savedSql);
            }
        }

        $company = $this->companySettings($pdo);
        $totalCount = $this->countPeriodRecords($pdo, $periodStart, $periodEnd, $sqlEkVeri);

        $offset = ($page - 1) * $perPage;
        $records = $this->fetchPeriodRecords($pdo, $periodStart, $periodEnd, $sqlEkVeri, $offset, $perPage);

        $this->response->success([
            'company' => $company,
            'period' => [
                'year' => $year,
                'month' => $monthStr,
                'startDate' => sprintf('%04d-%02d-01', $year, $month),
                'endDate' => date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $month))),
            ],
            'suggestedFileName' => $year . $monthStr . '-' . $page . '-' . $this->randomHash(24),
            'meta' => [
                'totalRecords' => $totalCount,
                'pageRecords' => count($records),
                'page' => $page,
                'perPage' => $perPage,
                'totalPages' => (int) ceil($totalCount / $perPage),
            ],
            'records' => $records,
        ]);
    }

    /**
     * Dosya oluşturma öncesi önerilen dosya adını döner (btrans.asp prompt varsayılanı).
     *
     * @Get("suggest-filename")
     * @Get("default-filename")
     * @query year int required Rapor yılı (örn: 2026)
     * @query month int required Rapor ayı (1-12)
     * @query page int Sayfa numarası (varsayılan: 1)
     */
    public function suggestFilename(): void
    {
        $year = (int) ($this->request->query('year') ?? $this->request->query('yil') ?? date('Y'));
        $month = (int) ($this->request->query('month') ?? $this->request->query('ay') ?? date('m'));
        $page = max(1, (int) ($this->request->query('page') ?? $this->request->query('s', 1)));

        $monthStr = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
        $hash = $this->randomHash(24);
        $fileName = $year . $monthStr . '-' . $page . '-' . $hash;

        $this->response->success([
            'year' => $year,
            'month' => $monthStr,
            'page' => $page,
            'hashUrl' => $hash,
            'fileName' => $fileName,
        ]);
    }

    /**
     * Seçilen rezervasyonlar için resmi GİB BTRANS XML dosyası üretir ve kaydeder (Dosya Oluştur).
     * btrans.asp sayfasındaki "Dosya Oluştur" butonunun API karşılığıdır.
     *
     * @Post("create")
     * @Post("dosya-olustur")
     * @Get("dosya-olustur")
     * @Post("create-file")
     * @query year int Raporlama yılı (örn: 2026)
     * @query month int Raporlama ayı (1-12)
     * @body year int Raporlama yılı
     * @body month int Raporlama ayı (1-12)
     * @body page int Sayfa numarası (varsayılan: 1)
     * @body fileName string Dosya kayıt adı (boşsa otomatik üretilir)
     * @body selectedIds array Seçilen rezervasyon kimlikleri (boş bırakılırsa tümü dahil edilir)
     * @body originalProcessCount int Orijinal işlem sayısı
     */
    public function create(): void
    {
        $pdo = $this->db->pdo();
        $this->ensureBtransTable($pdo);

        $year = (int) ($this->request->input('year')
            ?? $this->request->input('yil')
            ?? $this->request->query('year')
            ?? $this->request->query('yil'));

        $month = (int) ($this->request->input('month')
            ?? $this->request->input('ay')
            ?? $this->request->query('month')
            ?? $this->request->query('ay'));

        $page = max(1, (int) ($this->request->input('page')
            ?? $this->request->input('s')
            ?? $this->request->query('page')
            ?? $this->request->query('s', 1)));

        $customFileName = trim((string) ($this->request->input('fileName')
            ?? $this->request->input('kaydet')
            ?? $this->request->input('DosyaKayitAdi')
            ?? $this->request->query('fileName')
            ?? $this->request->query('kaydet')
            ?? ''));

        $selectedIds = $this->request->input('selectedIds') ?? $this->request->query('selectedIds');
        $originalProcessInput = $this->request->input('originalProcessCount')
            ?? $this->request->input('OriginalProccess')
            ?? $this->request->query('originalProcessCount')
            ?? $this->request->query('OriginalProccess');

        if ($year <= 2000 || $month < 1 || $month > 12) {
            throw new HttpException('Geçerli bir yıl ve ay (1-12) belirtilmelidir.', 'VALIDATION', 422);
        }

        $monthStr = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
        $periodStart = '01/' . $monthStr . '/' . $year;
        $lastDay = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
        $periodEnd = str_pad((string) $lastDay, 2, '0', STR_PAD_LEFT) . '/' . $monthStr . '/' . $year;

        // Seçili ID filtrelemesi (dizi, virgülle ayrılmış metin veya sec_123=1 formatı)
        $sqlEkVeri = '';
        $idList = [];
        if (is_array($selectedIds) && !empty($selectedIds)) {
            foreach ($selectedIds as $id) {
                $intId = filter_var($id, FILTER_VALIDATE_INT);
                if ($intId !== false && $intId > 0) {
                    $idList[] = $intId;
                }
            }
        } elseif (is_string($selectedIds) && trim($selectedIds) !== '') {
            foreach (explode(',', $selectedIds) as $id) {
                $intId = filter_var(trim($id), FILTER_VALIDATE_INT);
                if ($intId !== false && $intId > 0) {
                    $idList[] = $intId;
                }
            }
        } else {
            // Legacy form alanları kontrolü: sec_123=1
            $allInputs = array_merge($_GET, $_POST, $this->request->json() ?: []);
            foreach ($allInputs as $k => $v) {
                if (strpos((string) $k, 'sec_') === 0 && ($v === '1' || $v === 1 || $v === true || $v === 'on')) {
                    $intId = filter_var(substr((string) $k, 4), FILTER_VALIDATE_INT);
                    if ($intId !== false && $intId > 0) {
                        $idList[] = $intId;
                    }
                }
            }
        }

        if (!empty($idList)) {
            $sqlEkVeri = ' and k.id in(' . implode(',', $idList) . ')';
        }

        // Toplam kayıt sayısı (filtresiz)
        $totalOriginal = $this->countPeriodRecords($pdo, $periodStart, $periodEnd, '');
        $originalProcessCount = ($originalProcessInput !== null && is_numeric($originalProcessInput))
            ? (int) $originalProcessInput
            : $totalOriginal;

        // XML üretilecek kayıtları çek
        $records = $this->fetchPeriodRecords($pdo, $periodStart, $periodEnd, $sqlEkVeri, 0, 5000);
        $processCount = count($records);

        $isEdit = ($processCount === $originalProcessCount && empty($sqlEkVeri)) ? 0 : 1;

        // Son dosya no (dosyano)
        $lastIdStmt = $pdo->query('SELECT ISNULL(MAX(id), 0) FROM dbo.btrans');
        $lastId = (int) ($lastIdStmt ? $lastIdStmt->fetchColumn() : 0);
        $nextFileNo = $lastId + 1;

        $hashUrl = $this->randomHash(24);
        $fileName = $customFileName !== '' ? $customFileName : ($year . $monthStr . '-' . $page . '-' . $hashUrl);

        $company = $this->companySettings($pdo);
        $xmlContent = $this->buildBtransXml($company, $records, $year, $monthStr, $nextFileNo, $processCount);

        $insertSql = "INSERT INTO dbo.btrans 
            (SqlEkVeri, veri, hashurl, sayfa, yil, ay, FileName, NumberOfOriginalProccess, NumberOfProccess, isEdit, isDownload, CreatedDateTime)
            VALUES (:sqlEkVeri, :veri, :hashurl, :sayfa, :yil, :ay, :fileName, :origCount, :procCount, :isEdit, 0, GETDATE())";

        $insertStmt = $pdo->prepare($insertSql);
        $insertStmt->execute([
            ':sqlEkVeri' => $sqlEkVeri,
            ':veri' => $xmlContent,
            ':hashurl' => $hashUrl,
            ':sayfa' => $page,
            ':yil' => (string) $year,
            ':ay' => $monthStr,
            ':fileName' => $fileName,
            ':origCount' => $originalProcessCount,
            ':procCount' => $processCount,
            ':isEdit' => $isEdit,
        ]);

        $createdId = (int) $pdo->lastInsertId();

        $this->response->success([
            'id' => $createdId,
            'fileName' => $fileName,
            'hashUrl' => $hashUrl,
            'year' => (string) $year,
            'month' => $monthStr,
            'page' => $page,
            'numberOfProcess' => $processCount,
            'numberOfOriginalProcess' => $originalProcessCount,
            'isEdit' => (bool) $isEdit,
            'downloadUrls' => [
                'xml' => '/btrans/xml?hashurl=' . rawurlencode($hashUrl),
                'zip' => '/btrans/zip?hashurl=' . rawurlencode($hashUrl),
                'excel' => '/btrans/excel?hashurl=' . rawurlencode($hashUrl) . '&dosya=2',
                'faturaExcel' => '/btrans/excel?hashurl=' . rawurlencode($hashUrl) . '&dosya=3',
                'protokolExcel' => '/btrans/excel?hashurl=' . rawurlencode($hashUrl) . '&dosya=1',
            ],
        ], 201);
    }

    /**
     * BTRANS belgesini XML, ZIP veya Excel formatında indirir.
     *
     * @Get("download")
     * @query hashurl string BTRANS hash kodu
     * @query id int BTRANS ID
     * @query format string required Format: xml, zip, xls, excel
     * @query dosya int Excel tipi: 1 (protokol), 2 (işlemler), 3 (fatura nolu işlemler - varsayılan: 2)
     */
    public function download(): void
    {
        $pdo = $this->db->pdo();
        $this->ensureBtransTable($pdo);

        $record = $this->findBtransRecord($pdo);
        if (!$record) {
            throw new HttpException('BTRANS kaydı bulunamadı.', 'RECORD_NOT_FOUND', 404);
        }

        // İndirme işaretini güncelle
        try {
            $pdo->prepare('UPDATE dbo.btrans SET isDownload = 1 WHERE id = :id')->execute([':id' => $record['id']]);
        } catch (\Throwable $e) {
            // Sessizce devam et
        }

        $format = strtolower(trim((string) ($this->request->query('format') ?? '')));
        $dosya = (int) ($this->request->query('dosya', $this->request->query('fileType', 2)));

        if ($format === 'xml') {
            $this->streamXml($record);
            return;
        }

        if ($format === 'zip') {
            $this->streamZip($record);
            return;
        }

        if ($format === 'xls' || $format === 'excel') {
            $this->streamExcel($pdo, $record, $dosya);
            return;
        }

        throw new HttpException('Desteklenmeyen dosya formatı. (Desteklenenler: xml, zip, xls, excel)', 'INVALID_FORMAT', 422);
    }

    /**
     * BTRANS XML dosyasını doğrudan indirir.
     *
     * @Get("xml")
     * @query hashurl string BTRANS hash kodu
     * @query id int BTRANS ID
     */
    public function xml(): void
    {
        $pdo = $this->db->pdo();
        $this->ensureBtransTable($pdo);

        $record = $this->findBtransRecord($pdo);
        if (!$record) {
            throw new HttpException('BTRANS kaydı bulunamadı.', 'RECORD_NOT_FOUND', 404);
        }

        $this->streamXml($record);
    }

    /**
     * BTRANS ZIP arşivini doğrudan indirir.
     *
     * @Get("zip")
     * @query hashurl string BTRANS hash kodu
     * @query id int BTRANS ID
     */
    public function zip(): void
    {
        $pdo = $this->db->pdo();
        $this->ensureBtransTable($pdo);

        $record = $this->findBtransRecord($pdo);
        if (!$record) {
            throw new HttpException('BTRANS kaydı bulunamadı.', 'RECORD_NOT_FOUND', 404);
        }

        $this->streamZip($record);
    }

    /**
     * BTRANS Excel tablosunu doğrudan indirir.
     *
     * @Get("excel")
     * @query hashurl string BTRANS hash kodu
     * @query id int BTRANS ID
     * @query dosya int Excel tipi: 1 (protokol), 2 (işlemler), 3 (fatura nolu işlemler - varsayılan: 2)
     */
    public function excel(): void
    {
        $pdo = $this->db->pdo();
        $this->ensureBtransTable($pdo);

        $record = $this->findBtransRecord($pdo);
        if (!$record) {
            throw new HttpException('BTRANS kaydı bulunamadı.', 'RECORD_NOT_FOUND', 404);
        }

        $dosya = (int) ($this->request->query('dosya', $this->request->query('fileType', 2)));
        $this->streamExcel($pdo, $record, $dosya);
    }

    /**
     * BTRANS veritabanı tablosunu ve ilişkili kolonları kontrol edip eksikleri tamamlar.
     *
     * @Post("setup")
     */
    public function setup(): void
    {
        $pdo = $this->db->pdo();
        $results = $this->runDatabaseSetup($pdo);

        $this->response->success([
            'setup' => 'btrans',
            'tables' => $results,
        ]);
    }

    // =========================================================================
    // İÇ YARDIMCI VE İŞ MANTIĞI METOTLARI
    // =========================================================================

    /**
     * btrans kaydını id veya hashurl üzerinden bulur.
     *
     * @return array<string,mixed>|null
     */
    private function findBtransRecord(PDO $pdo): ?array
    {
        $id = $this->request->query('id', $this->request->input('id'));
        $hash = trim((string) ($this->request->query('hashurl', $this->request->input('hashurl')) ?? ''));

        if ($id && is_numeric($id)) {
            $stmt = $pdo->prepare('SELECT * FROM dbo.btrans WHERE id = :id');
            $stmt->execute([':id' => (int) $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }

        if ($hash !== '') {
            $stmt = $pdo->prepare('SELECT * FROM dbo.btrans WHERE hashurl = :hash');
            $stmt->execute([':hash' => $hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }

        return null;
    }

    /**
     * XML dosyasını istemciye aktarır.
     */
    private function streamXml(array $record): void
    {
        $xmlContent = (string) ($record['veri'] ?? '');
        $year = trim((string) ($record['yil'] ?? ''));
        $month = str_pad(trim((string) ($record['ay'] ?? '')), 2, '0', STR_PAD_LEFT);
        $hash = trim((string) ($record['hashurl'] ?? ''));

        $fileName = $year . $month . '-' . $hash . '.xml';

        if (!headers_sent()) {
            header('Content-Type: application/xml; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            header('Content-Length: ' . strlen($xmlContent));
        }

        echo $xmlContent;
        exit;
    }

    /**
     * ZIP arşivini istemciye aktarır.
     */
    private function streamZip(array $record): void
    {
        if (!class_exists('ZipArchive')) {
            throw new HttpException('Sunucuda ZipArchive PHP eklentisi bulunmuyor.', 'ZIP_NOT_SUPPORTED', 500);
        }

        $xmlContent = (string) ($record['veri'] ?? '');
        $year = trim((string) ($record['yil'] ?? ''));
        $month = str_pad(trim((string) ($record['ay'] ?? '')), 2, '0', STR_PAD_LEFT);
        $hash = trim((string) ($record['hashurl'] ?? ''));
        $id = (int) ($record['id'] ?? 0);

        $xmlFileName = $year . $month . '-' . $hash . '.xml';
        $zipFileName = $year . $month . ($id > 0 ? (string) $id : $hash) . '.zip';

        $tempFile = tempnam(sys_get_temp_dir(), 'btrans_zip_');
        $zip = new ZipArchive();

        if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new HttpException('ZIP arşivi oluşturulamadı.', 'ZIP_CREATE_FAILED', 500);
        }

        $zip->addFromString($xmlFileName, $xmlContent);
        $zip->close();

        if (!headers_sent()) {
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zipFileName . '"');
            header('Content-Length: ' . (int) filesize($tempFile));
        }

        readfile($tempFile);
        @unlink($tempFile);
        exit;
    }

    /**
     * Excel (HTML-tabanlı .xls) dosyasını istemciye aktarır.
     */
    private function streamExcel(PDO $pdo, array $record, int $dosya): void
    {
        $year = trim((string) ($record['yil'] ?? ''));
        $month = str_pad(trim((string) ($record['ay'] ?? '')), 2, '0', STR_PAD_LEFT);
        $hash = trim((string) ($record['hashurl'] ?? ''));
        $periodStart = '01/' . $month . '/' . $year;
        $lastDay = (int) date('t', mktime(0, 0, 0, (int) $month, 1, (int) $year));
        $periodEnd = str_pad((string) $lastDay, 2, '0', STR_PAD_LEFT) . '/' . $month . '/' . $year;

        $company = $this->companySettings($pdo);
        $sqlEkVeri = trim((string) ($record['SqlEkVeri'] ?? ''));
        if ($sqlEkVeri !== '') {
            $sqlEkVeri = ' ' . $sqlEkVeri;
        }

        if ($dosya === 1) {
            // Protokol Excel
            $fileName = $year . $month . '-' . $hash . '-protokol.xls';
            $processCount = (int) ($record['NumberOfProccess'] ?? 0);
            $fileNo = (int) ($record['id'] ?? 0);

            $html = $this->buildProtokolExcelHtml($company, $year, $month, $fileNo, $processCount);
        } else {
            // İşlemler veya Fatura Nolu İşlemler Excel
            $isFaturaNo = ($dosya === 3);
            $suffix = $isFaturaNo ? '-fatura-islemler.xls' : '-islemler.xls';
            $fileName = $year . $month . '-' . $hash . $suffix;

            $records = $this->fetchPeriodRecords($pdo, $periodStart, $periodEnd, $sqlEkVeri, 0, 10000);
            $html = $this->buildIslemlerExcelHtml($records, $isFaturaNo);
        }

        if (!headers_sent()) {
            header('Content-Type: application/vnd.ms-excel; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
        }

        echo "\xEF\xBB\xBF"; // UTF-8 BOM
        echo $html;
        exit;
    }

    /**
     * genel tablosundan firma/site ayarlarını okur.
     *
     * @return array{title:string,vkn:string,siteName:string,domain:string}
     */
    private function companySettings(PDO $pdo): array
    {
        $title = '';
        $vkn = '';
        $siteName = '';
        $domain = '';

        try {
            $stmt = $pdo->query('SELECT TOP 1 faturaTitle, faturaVkn, siteadi, domain FROM dbo.genel WHERE id = 1');
            if (!$stmt) {
                $stmt = $pdo->query('SELECT TOP 1 faturaTitle, faturaVkn, siteadi, domain FROM dbo.genel');
            }
            if ($stmt) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $title = trim((string) ($row['faturaTitle'] ?? ''));
                    $vkn = trim((string) ($row['faturaVkn'] ?? ''));
                    $siteName = trim((string) ($row['siteadi'] ?? ''));
                    $domain = trim((string) ($row['domain'] ?? ''));
                }
            }
        } catch (\Throwable $e) {
            // Devam et
        }

        return [
            'title' => $title,
            'vkn' => $vkn,
            'siteName' => $siteName,
            'domain' => $domain,
        ];
    }

    /**
     * BTRANS ana sorgusu için SQL ifadesini üretir.
     */
    private function buildQuerySql(PDO $pdo, string $sqlEkVeri = '', bool $forCount = false): string
    {
        $hasFn = $this->hasFnTemizle($pdo);

        $cleanTutar = $hasFn
            ? 'dbo.fnTemizle(k.toplam_tutar)'
            : "REPLACE(REPLACE(ISNULL(k.toplam_tutar, '0'), '.', ''), ',', '.')";

        $cleanOnOdeme = $hasFn
            ? 'dbo.fnTemizle(k.on_odeme)'
            : "REPLACE(REPLACE(ISNULL(k.on_odeme, '0'), '.', ''), ',', '.')";

        if ($forCount) {
            return "SELECT COUNT(*)
                    FROM dbo.kayitlar AS k
                    INNER JOIN dbo.genel g ON g.id = 1
                    INNER JOIN dbo.homes h ON h.id = k.evid
                    INNER JOIN dbo.dolu ON dolu.kayitid = k.id
                    INNER JOIN dbo.kullanici u ON u.id = h.evsahibi
                    LEFT JOIN dbo.destinations d ON d.id = h.emlak_bolgesi
                    LEFT JOIN dbo.destinations d2 ON d2.id = d.cat
                    LEFT JOIN dbo.destinations d3 ON d3.id = d2.cat
                    LEFT JOIN dbo.destinations d4 ON d4.id = d3.cat
                    WHERE dolu.durum = 3
                      AND (CASE 
                            WHEN TRY_CONVERT(date, k.rez_tarihi, 103) IS NOT NULL THEN TRY_CONVERT(date, k.rez_tarihi, 103)
                            WHEN TRY_CONVERT(date, k.rez_tarihi, 104) IS NOT NULL THEN TRY_CONVERT(date, k.rez_tarihi, 104)
                            WHEN TRY_CONVERT(date, k.rez_tarihi, 120) IS NOT NULL THEN TRY_CONVERT(date, k.rez_tarihi, 120)
                            ELSE TRY_CONVERT(date, k.rez_tarihi)
                          END)
                          BETWEEN CONVERT(date, :periodStart, 103) AND CONVERT(date, :periodEnd, 103)
                      AND NOT {$cleanTutar} = '0'
                      AND NOT {$cleanTutar} = ''" . $sqlEkVeri;
        }

        return "SELECT
            CASE WHEN h.btrans_cadde = '' THEN NULL ELSE h.btrans_cadde END AS btrans_cadde,
            CASE WHEN h.btrans_sokak = '' THEN NULL ELSE h.btrans_sokak END AS btrans_sokak,
            CASE WHEN h.btrans_dis_kapi_no = '' THEN NULL ELSE h.btrans_dis_kapi_no END AS btrans_dis_kapi_no,
            CASE WHEN h.btrans_ic_kapi_no = '' THEN NULL ELSE h.btrans_ic_kapi_no END AS btrans_ic_kapi_no,
            CASE WHEN h.btrans_imar_plani_icinde = '' THEN NULL ELSE h.btrans_imar_plani_icinde END AS btrans_imar_plani_icinde,
            CASE WHEN h.btrans_uavt_no = '' THEN '0' ELSE ISNULL(h.btrans_uavt_no, '0') END AS btrans_uavt_no,
            CASE WHEN h.btrans_zemin_sistem_no = '' THEN '0' ELSE ISNULL(h.btrans_zemin_sistem_no, '0') END AS btrans_zemin_sistem_no,
            CASE WHEN h.btrans_yevmiye_no = '' THEN NULL ELSE h.btrans_yevmiye_no END AS btrans_yevmiye_no,
            CASE WHEN h.btrans_cevre_sehircilik_basvuru = '' THEN NULL ELSE h.btrans_cevre_sehircilik_basvuru END AS btrans_cevre_sehircilik_basvuru,
            CASE WHEN h.btrans_yapi_kayit_belgesi_no = '' THEN NULL ELSE h.btrans_yapi_kayit_belgesi_no END AS btrans_yapi_kayit_belgesi_no,
            CASE WHEN h.btrans_yapi_kayit_basvuru_no = '' THEN NULL ELSE h.btrans_yapi_kayit_basvuru_no END AS btrans_yapi_kayit_basvuru_no,
            CASE WHEN LEN(REPLACE(h.btrans_yapi_kayit_duzenleme_tarihi, '-', '')) > 0 
                 THEN REPLACE(h.btrans_yapi_kayit_duzenleme_tarihi, '-', '') 
                 ELSE '10000101' END AS btrans_yapi_kayit_duzenleme_tarihi,
            k.site AS site,
            k.faturaseri AS faturaseri,
            CASE WHEN LEN(k.btrans_odeme_yapan_iban_no) = 26 
                 THEN k.btrans_odeme_yapan_iban_no 
                 ELSE ISNULL((SELECT TOP 1 REPLACE(iban_tl, ' ', '') FROM dbo.hesaplar WHERE kullanici = 0 ORDER BY id ASC), '00000000000000000000000000') 
            END AS btrans_odeme_yapan_iban_no,
            ISNULL(k.btrans_odeme_bilgisi, '2') AS btrans_odeme_bilgisi,
            ISNULL(ISNULL(REPLACE(k.btrans_odeme_tarihi, '-', ''), REPLACE(CONVERT(varchar(10), DATEADD(day, 1, ISNULL(TRY_CONVERT(date, k.rez_tarihi, 103), TRY_CONVERT(date, k.rez_tarihi))), 120), '-', '')), '10000101') AS btrans_odeme_tarihi,
            ISNULL(ISNULL(k.btrans_komisyon_orani, ({$cleanOnOdeme} * 100) / NULLIF({$cleanTutar}, 0)), '0') AS btrans_komisyon_orani,
            REPLACE(ISNULL(ISNULL(k.btrans_odeme_tutari_kdv_dahil, CONVERT(money, {$cleanOnOdeme}) - CONVERT(money, {$cleanTutar}) / 100 * ISNULL(h.kazancorani, 0)), '0'), ',', '.') AS btrans_odeme_tutari_kdv_dahil,
            k.btrans_iban AS btrans_iban_id,
            ISNULL(LEFT(REPLACE(
                CASE 
                    WHEN k.btrans_iban = '01' OR k.btrans_iban = '02' 
                    THEN ISNULL(REPLACE(UPPER(k.btrans_farkli_alici_iban), ' ', ''), '00000000000000000000000000')
                    ELSE 
                        CASE WHEN LEN(ISNULL((SELECT REPLACE(iban, ' ', '') FROM dbo.hesaplar WHERE hesaplar.id = k.btrans_iban), '')) >= 26 
                             THEN (SELECT REPLACE(iban, ' ', '') FROM dbo.hesaplar WHERE hesaplar.id = k.btrans_iban)
                             ELSE '00000000000000000000000000'
                        END
                END, ' ', ''), 26), '00000000000000000000000000') AS btrans_iban_no,
            ISNULL(k.btrans_para_birimi, 'TRY') AS btrans_para_birimi,
            CASE WHEN LEN(REPLACE(k.btrans_baslangic_tarihi, '-', '')) > 0 
                 THEN REPLACE(k.btrans_baslangic_tarihi, '-', '') 
                 ELSE REPLACE(k.rez_tarihi, '-', '') 
            END AS btrans_baslangic_tarihi,
            CASE WHEN LEN(REPLACE(k.btrans_bitis_tarihi, '-', '')) > 0 
                 THEN REPLACE(k.btrans_bitis_tarihi, '-', '') 
                 ELSE REPLACE(k.gelecek_tarih, '-', '') 
            END AS btrans_bitis_tarihi,
            k.btrans_guncelleme_tarihi,
            ISNULL(k.btrans_odeme_yontemi, '4') AS btrans_odeme_yontemi,
            ISNULL(CASE WHEN k.btrans_iban = '01' THEN '1' WHEN k.btrans_iban = '02' THEN '2' END, '1') AS vkturu,
            CASE WHEN k.btrans_iban = '01' OR k.btrans_iban = '02' THEN k.btrans_farkli_alici_unvan ELSE u.vkUnvan END AS vkUnvan,
            LEFT(REPLACE(ISNULL(CASE WHEN LEN(REPLACE(u.tc, ' ', '')) = 11 THEN REPLACE(u.tc, ' ', '') ELSE '00000000000' END, '00000000000'), ' ', ''), 11) AS tc,
            LEFT(REPLACE(ISNULL(CASE WHEN LEN(REPLACE(u.vkn, ' ', '')) = 10 THEN REPLACE(u.vkn, ' ', '') ELSE '0000000000' END, '0000000000'), ' ', ''), 10) AS vkn,
            k.id AS kid,
            ISNULL(CASE WHEN d4.kod IS NULL THEN d3.kod ELSE LEFT(d4.kod, 2) END, '07') AS ilkod,
            ISNULL(CASE WHEN LEN(d2.kod) > 0 THEN d2.kod ELSE '1135' END, '1135') AS ilcekod,
            ISNULL(d2.baslik, '') AS bolgebaslik,
            g.faturaVkn,
            g.faturaTitle,
            g.siteadi,
            g.domain,
            h.baslik AS evadi,
            h.evkodu AS evkodu,
            g.domain + '/' + h.url AS url,
            CASE WHEN LEN(CONVERT(nvarchar(50), h.enlem)) > 0 THEN CONVERT(nvarchar(50), h.enlem) ELSE '12.12345678' END AS enlem,
            CASE WHEN LEN(CONVERT(nvarchar(50), h.boylam)) > 0 THEN CONVERT(nvarchar(50), h.boylam) ELSE '12.12345678' END AS boylam,
            h.kazancorani,
            (CASE WHEN ISNULL(TRY_CONVERT(date, k.rez_tarihi, 103), TRY_CONVERT(date, k.rez_tarihi)) < CONVERT(date, '01/08/2023', 103) THEN '18' ELSE '20' END) AS kdv,
            REPLACE(k.rez_tarihi, '-', '') AS rez_tarihi,
            REPLACE(k.gelecek_tarih, '-', '') AS gelecek_tarih,
            k.rez_tarihi AS rez_tarihi_format,
            k.gelecek_tarih AS gelecek_tarih_format,
            REPLACE({$cleanTutar}, ',', '.') AS toplam_tutar,
            UPPER(REPLACE(REPLACE(REPLACE(REPLACE(k.doviz, 'tl', 'TRY'), 'pound', 'GBP'), 'dolar', 'USD'), 'euro', 'EUR')) AS parabirimi,
            REPLACE(CONVERT(varchar(10), ISNULL(TRY_CONVERT(date, k.islem_tarihi, 103), TRY_CONVERT(date, k.islem_tarihi)), 120), '-', '') AS islem_tarihi,
            CONVERT(varchar(10), ISNULL(TRY_CONVERT(date, k.islem_tarihi, 103), TRY_CONVERT(date, k.islem_tarihi)), 120) AS islem_tarihi_format,
            ISNULL((SELECT TOP 1 REPLACE(iban, ' ', '') FROM dbo.hesaplar WHERE kullanici = u.id), '00000000000000000000000000') AS iban,
            ISNULL((SELECT TOP 1 REPLACE(iban_tl, ' ', '') FROM dbo.hesaplar WHERE kullanici = 0), '00000000000000000000000000') AS siteiban,
            {$cleanTutar} - ({$cleanTutar} * ISNULL(h.kazancorani, 0)) / 100 AS evsahibiodeme,
            ISNULL(CASE WHEN LEN(RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(u.tel, CHAR(160), ''), '(', ''), ')', ''), ' ', ''), '+90', ''), '-', ''), 10)) = 10 
                        THEN RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(u.tel, CHAR(160), ''), '(', ''), ')', ''), ' ', ''), '+90', ''), '-', ''), 10) 
                        ELSE '0000000000' 
                   END, '0000000000') AS usertel,
            CASE WHEN k.btrans_iban = '01' OR k.btrans_iban = '02' THEN k.btrans_farkli_alici_adi ELSE u.ad END AS evsahibiad,
            CASE WHEN k.btrans_iban = '01' OR k.btrans_iban = '02' THEN k.btrans_farkli_alici_soyadi ELSE u.soyad END AS evsahibisoyad,
            '' AS usermail
        FROM dbo.kayitlar AS k
        INNER JOIN dbo.genel g ON g.id = 1
        INNER JOIN dbo.homes h ON h.id = k.evid
        INNER JOIN dbo.dolu ON dolu.kayitid = k.id
        INNER JOIN dbo.kullanici u ON u.id = h.evsahibi
        LEFT JOIN dbo.destinations d ON d.id = h.emlak_bolgesi
        LEFT JOIN dbo.destinations d2 ON d2.id = d.cat
        LEFT JOIN dbo.destinations d3 ON d3.id = d2.cat
        LEFT JOIN dbo.destinations d4 ON d4.id = d3.cat
        WHERE dolu.durum = 3
          AND (CASE 
                WHEN TRY_CONVERT(date, k.rez_tarihi, 103) IS NOT NULL THEN TRY_CONVERT(date, k.rez_tarihi, 103)
                WHEN TRY_CONVERT(date, k.rez_tarihi, 104) IS NOT NULL THEN TRY_CONVERT(date, k.rez_tarihi, 104)
                WHEN TRY_CONVERT(date, k.rez_tarihi, 120) IS NOT NULL THEN TRY_CONVERT(date, k.rez_tarihi, 120)
                ELSE TRY_CONVERT(date, k.rez_tarihi)
              END)
              BETWEEN CONVERT(date, :periodStart, 103) AND CONVERT(date, :periodEnd, 103)
          AND NOT {$cleanTutar} = '0'
          AND NOT {$cleanTutar} = ''" . $sqlEkVeri . "
        ORDER BY k.id ASC";
    }

    /**
     * Dönemdeki toplam kayıt sayısını döner.
     */
    private function countPeriodRecords(PDO $pdo, string $periodStart, string $periodEnd, string $sqlEkVeri): int
    {
        $sql = $this->buildQuerySql($pdo, $sqlEkVeri, true);
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':periodStart' => $periodStart,
            ':periodEnd' => $periodEnd,
        ]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Dönem kayıtlarını çeker.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchPeriodRecords(PDO $pdo, string $periodStart, string $periodEnd, string $sqlEkVeri, int $offset = 0, int $limit = 300): array
    {
        $baseSql = $this->buildQuerySql($pdo, $sqlEkVeri, false);
        $safeOffset = max(0, (int) $offset);
        $safeLimit = max(1, (int) $limit);
        $sql = $baseSql . " OFFSET {$safeOffset} ROWS FETCH NEXT {$safeLimit} ROWS ONLY";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':periodStart' => $periodStart,
            ':periodEnd' => $periodEnd,
        ]);
        $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rawRows as $row) {
            $results[] = $this->formatRecordRow($row);
        }

        return $results;
    }

    /**
     * Veritabanı satırını temiz formatlı diziye çevirir.
     */
    private function formatRecordRow(array $row): array
    {
        $guncellemeTarihi = trim((string) ($row['btrans_guncelleme_tarihi'] ?? ''));
        $isUpdated = ($guncellemeTarihi !== '');

        return [
            'kid' => (int) ($row['kid'] ?? 0),
            'faturaSeri' => trim((string) ($row['faturaseri'] ?? '')),
            'evAdi' => trim((string) ($row['evadi'] ?? '')),
            'evKodu' => trim((string) ($row['evkodu'] ?? '')),
            'url' => trim((string) ($row['url'] ?? '')),
            'ulke' => 'Türkiye',
            'ilKod' => trim((string) ($row['ilkod'] ?? '')),
            'ilceKod' => trim((string) ($row['ilcekod'] ?? '')),
            'mahalle' => trim((string) ($row['bolgebaslik'] ?? '')),
            'cadde' => trim((string) ($row['btrans_cadde'] ?? '')),
            'sokak' => trim((string) ($row['btrans_sokak'] ?? '')),
            'disKapiNo' => trim((string) ($row['btrans_dis_kapi_no'] ?? '')),
            'icKapiNo' => trim((string) ($row['btrans_ic_kapi_no'] ?? '')),
            'imarPlaniIcinde' => trim((string) ($row['btrans_imar_plani_icinde'] ?? '')),
            'uavtNo' => trim((string) ($row['btrans_uavt_no'] ?? '')),
            'zeminSistemNo' => trim((string) ($row['btrans_zemin_sistem_no'] ?? '')),
            'yevmiyeNo' => trim((string) ($row['btrans_yevmiye_no'] ?? '')),
            'cevreSehircilikBasvuru' => trim((string) ($row['btrans_cevre_sehircilik_basvuru'] ?? '')),
            'yapiKayitBelgeNo' => trim((string) ($row['btrans_yapi_kayit_belgesi_no'] ?? '')),
            'yapiKayitBasvuruNo' => trim((string) ($row['btrans_yapi_kayit_basvuru_no'] ?? '')),
            'yapiKayitDuzenlemeTarihi' => $this->formatDate8($row['btrans_yapi_kayit_duzenleme_tarihi'] ?? null),
            'enlem' => substr(trim((string) ($row['enlem'] ?? '')), 0, 11),
            'boylam' => substr(trim((string) ($row['boylam'] ?? '')), 0, 11),
            'rezervasyonBasTar' => $this->formatDate8($row['btrans_baslangic_tarihi'] ?? null),
            'rezervasyonBitTar' => $this->formatDate8($row['btrans_bitis_tarihi'] ?? null),
            'toplamTutar' => trim((string) ($row['toplam_tutar'] ?? '0')),
            'paraBirimi' => trim((string) ($row['parabirimi'] ?? 'TRY')),
            'odemeBilgisi' => trim((string) ($row['btrans_odeme_bilgisi'] ?? '2')),
            'odemeYontemi' => trim((string) ($row['btrans_odeme_yontemi'] ?? '4')),
            'odemeYapanIbanNo' => trim((string) ($row['btrans_odeme_yapan_iban_no'] ?? '')),
            'odemeTarihi' => $this->formatDate8($row['btrans_odeme_tarihi'] ?? null),
            'odemeTutariKdvDahil' => trim((string) ($row['btrans_odeme_tutari_kdv_dahil'] ?? '0')),
            'komisyonOrani' => trim((string) ($row['btrans_komisyon_orani'] ?? '0')),
            'vkTuru' => trim((string) ($row['vkturu'] ?? '1')),
            'vkn' => trim((string) ($row['vkn'] ?? '')),
            'vkUnvan' => trim((string) ($row['vkUnvan'] ?? '')),
            'tc' => trim((string) ($row['tc'] ?? '')),
            'evsahibiAd' => trim((string) ($row['evsahibiad'] ?? '')),
            'evsahibiSoyad' => trim((string) ($row['evsahibisoyad'] ?? '')),
            'evsahibiIbanNo' => trim((string) ($row['btrans_iban_no'] ?? '')),
            'usertel' => trim((string) ($row['usertel'] ?? '')),
            'usermail' => trim((string) ($row['usermail'] ?? '')),
            'isUpdated' => $isUpdated,
            'guncellemeTarihi' => $guncellemeTarihi,
        ];
    }

    /**
     * Resmi GİB BTRANS XML şemasını oluşturur.
     */
    private function buildBtransXml(array $company, array $records, int $year, string $month, int $fileNo, int $count): string
    {
        $domain = $company['domain'];
        $faturaVkn = $company['vkn'];
        $faturaTitle = $this->xmlEscape($company['title']);

        $xml = "<?xml version='1.0' encoding='UTF-8'?>\n";
        $xml .= "<vimveritransferi xsi:noNamespaceSchemaLocation='gunubirlikson_uyumsuz.xsd' xmlns:xsi='http://www.w3.org/2001/XMLSchema-instance'>\n";
        $xml .= "\t<protokol>\n";
        $xml .= "\t\t<genel kuruluskodu='538'>\n";
        $xml .= "\t\t\t <vkn>{$faturaVkn}</vkn>\n";
        $xml .= "\t\t\t <unvan>{$faturaTitle}</unvan>\n";
        $xml .= "\t\t</genel>\n";
        $xml .= "\t\t<ozel>\n";
        $xml .= "\t\t\t<bilgidonem>{$year}{$month}-{$year}{$month}</bilgidonem>\n";
        $xml .= "\t\t\t<dosyano>{$fileNo}</dosyano>\n";
        $xml .= "\t\t\t<bilgiTarihi>{$year}{$month}01</bilgiTarihi>\n";
        $xml .= "\t\t\t<webAdresi>{$this->xmlEscape($domain)}</webAdresi>\n";
        $xml .= "\t\t\t<islemsayisi>{$count}</islemsayisi>\n";
        if ($count <= 0) {
            $xml .= "\t\t\t<islemyok>1</islemyok>\n";
        }
        $xml .= "\t\t</ozel>\n";
        $xml .= "\t</protokol>\n";
        $xml .= "\t<islemler>\n";

        foreach ($records as $r) {
            $xml .= "\t\t<islem>\n";
            $xml .= "\t\t\t<ilanaKonuGayrimenkulBilgisi>\n";
            $xml .= "\t\t\t\t<webAdresAlani>\n";
            $xml .= "\t\t\t\t\t<siteAdi>" . $this->xmlEscape($r['evAdi']) . "</siteAdi>\n";
            $xml .= "\t\t\t\t\t<siteKodu>" . $this->xmlEscape($r['evKodu']) . "</siteKodu>\n";
            $xml .= "\t\t\t\t\t<webAdresi>" . $this->xmlEscape($r['url']) . "</webAdresi>\n";
            $xml .= "\t\t\t\t</webAdresAlani>\n";
            $xml .= "\t\t\t\t<adresAlani>\n";
            $xml .= "\t\t\t\t\t<ulke>\n";
            $xml .= "\t\t\t\t\t\t<ulkeAdi>1</ulkeAdi>\n";
            $xml .= "\t\t\t\t\t\t<digerSecimi>Türkiye</digerSecimi>\n";
            $xml .= "\t\t\t\t\t</ulke>\n";
            $xml .= "\t\t\t\t\t<il>" . $this->xmlEscape($r['ilKod']) . "</il>\n";
            $xml .= "\t\t\t\t\t<ilce>" . $this->xmlEscape($r['ilceKod']) . "</ilce>\n";
            $xml .= "\t\t\t\t\t<mahalle>" . $this->xmlEscape($r['mahalle']) . "</mahalle>\n";
            $xml .= "\t\t\t\t\t<cadde>" . $this->xmlEscape($r['cadde']) . "</cadde>\n";
            $xml .= "\t\t\t\t\t<sokak>" . $this->xmlEscape($r['sokak']) . "</sokak>\n";
            $xml .= "\t\t\t\t\t<diskapiNo>" . $this->xmlEscape($r['disKapiNo']) . "</diskapiNo>\n";
            $xml .= "\t\t\t\t\t<ickapiNo>" . $this->xmlEscape($r['icKapiNo']) . "</ickapiNo>\n";
            $xml .= "\t\t\t\t</adresAlani>\n";
            $xml .= "\t\t\t\t<imarPlani>\n";
            $xml .= "\t\t\t\t\t<imarPlaniIcinde>\n";
            $xml .= "\t\t\t\t\t\t<ulusalAdresVeriTabanNumarasi>" . $this->xmlEscape($r['uavtNo']) . "</ulusalAdresVeriTabanNumarasi>\n";
            $xml .= "\t\t\t\t\t</imarPlaniIcinde>\n";
            $xml .= "\t\t\t\t</imarPlani>\n";
            $xml .= "\t\t\t\t<zeminSistemNumarasi>" . $this->xmlEscape($r['zeminSistemNo']) . "</zeminSistemNumarasi>\n";
            $xml .= "\t\t\t\t<yevmiyeNo>" . $this->xmlEscape($r['yevmiyeNo']) . "</yevmiyeNo>\n";
            $xml .= "\t\t\t\t<basvuruBulunmaBilgisi>\n";
            if ($r['cevreSehircilikBasvuru'] === '1') {
                $xml .= "\t\t\t\t\t<evet>\n";
                $xml .= "\t\t\t\t\t\t<yapiKayitBelgeNo>" . $this->xmlEscape($r['yapiKayitBelgeNo']) . "</yapiKayitBelgeNo>\n";
                $xml .= "\t\t\t\t\t\t<yapiKayitBasvuruNo>" . $this->xmlEscape($r['yapiKayitBasvuruNo']) . "</yapiKayitBasvuruNo>\n";
                $xml .= "\t\t\t\t\t\t<yapiKayitDuzenlenmeTarihi>" . $this->xmlEscape($r['yapiKayitDuzenlemeTarihi']) . "</yapiKayitDuzenlenmeTarihi>\n";
                $xml .= "\t\t\t\t\t</evet>\n";
            } else {
                $xml .= "\t\t\t\t\t<hayir></hayir>\n";
            }
            $xml .= "\t\t\t\t</basvuruBulunmaBilgisi>\n";
            $xml .= "\t\t\t\t<koordinatBilgileri>\n";
            $xml .= "\t\t\t\t\t<enlem>" . $this->xmlEscape($r['enlem']) . "</enlem>\n";
            $xml .= "\t\t\t\t\t<boylam>" . $this->xmlEscape($r['boylam']) . "</boylam>\n";
            $xml .= "\t\t\t\t</koordinatBilgileri>\n";
            $xml .= "\t\t\t</ilanaKonuGayrimenkulBilgisi>\n";
            $xml .= "\t\t\t<rezervasyonBilgileri>\n";
            $xml .= "\t\t\t\t<rezervasyonBasTar>" . $this->xmlEscape($r['rezervasyonBasTar']) . "</rezervasyonBasTar>\n";
            $xml .= "\t\t\t\t<rezervasyonBitTar>" . $this->xmlEscape($r['rezervasyonBitTar']) . "</rezervasyonBitTar>\n";
            $xml .= "\t\t\t\t<rezervasyonTutari>" . $this->xmlEscape($r['toplamTutar']) . "</rezervasyonTutari>\n";
            $xml .= "\t\t\t\t<rezervasyonParaBirimi>" . $this->xmlEscape($r['paraBirimi']) . "</rezervasyonParaBirimi>\n";
            $xml .= "\t\t\t</rezervasyonBilgileri>\n";
            $xml .= "\t\t\t<odemeBilgileri>\n";

            if ($r['odemeBilgisi'] === '1') {
                // Peşin Ödeme
                $xml .= "\t\t\t\t<pesinOdeme>\n";
                $xml .= "\t\t\t\t\t<kiralanmaSuresiBasTar>" . $this->xmlEscape($r['rezervasyonBasTar']) . "</kiralanmaSuresiBasTar>\n";
                $xml .= "\t\t\t\t\t<kiralanmaSuresiBitTar>" . $this->xmlEscape($r['rezervasyonBitTar']) . "</kiralanmaSuresiBitTar>\n";
                $xml .= "\t\t\t\t\t<pesinTahsilSekli>" . $this->xmlEscape($r['odemeBilgisi']) . "</pesinTahsilSekli>\n";
                $xml .= "\t\t\t\t\t<ibanNo>" . $this->xmlEscape($r['odemeYapanIbanNo']) . "</ibanNo>\n";
                $xml .= "\t\t\t\t\t<odemeTarihi>" . $this->xmlEscape($r['odemeTarihi']) . "</odemeTarihi>\n";
                $xml .= "\t\t\t\t\t<toplamOdemeTutari>" . $this->xmlEscape($r['odemeTutariKdvDahil']) . "</toplamOdemeTutari>\n";
                $xml .= "\t\t\t\t\t<paraBirimi>" . $this->xmlEscape($r['paraBirimi']) . "</paraBirimi>\n";
                $xml .= "\t\t\t\t</pesinOdeme>\n";
            } else {
                // Komisyonlu Ödeme
                $xml .= "\t\t\t\t<komisyonOdeme>\n";
                $xml .= "\t\t\t\t\t<komisyonTahsilSekli>" . $this->xmlEscape($r['odemeBilgisi']) . "</komisyonTahsilSekli>\n";
                $xml .= "\t\t\t\t\t<ibanNo>" . $this->xmlEscape($r['odemeYapanIbanNo']) . "</ibanNo>\n";
                $xml .= "\t\t\t\t\t<tahsilTar>" . $this->xmlEscape($r['odemeTarihi']) . "</tahsilTar>\n";
                $xml .= "\t\t\t\t\t<komisyonOrani>" . $this->xmlEscape($r['komisyonOrani']) . "</komisyonOrani>\n";
                $xml .= "\t\t\t\t\t<komisyonTutari>" . $this->xmlEscape($r['odemeTutariKdvDahil']) . "</komisyonTutari>\n";
                $xml .= "\t\t\t\t\t<paraBirimi>" . $this->xmlEscape($r['paraBirimi']) . "</paraBirimi>\n";
                $xml .= "\t\t\t\t</komisyonOdeme>\n";
            }

            $xml .= "\t\t\t</odemeBilgileri>\n";
            $xml .= "\t\t\t<gayrimenkulSahipBilgi>\n";
            $xml .= "\t\t\t\t<kisi>\n";

            if ($r['vkTuru'] === '2') {
                // Tüzel Kişi
                $xml .= "\t\t\t\t\t<tuzelKisi>\n";
                $xml .= "\t\t\t\t\t\t<vkn>" . $this->xmlEscape($r['vkn']) . "</vkn>\n";
                $xml .= "\t\t\t\t\t\t<tuzelKisiUnvan>" . $this->xmlEscape($r['vkUnvan']) . "</tuzelKisiUnvan>\n";
                $xml .= "\t\t\t\t\t</tuzelKisi>\n";
            } else {
                // Gerçek Kişi
                $xml .= "\t\t\t\t\t<gercekKisi>\n";
                $xml .= "\t\t\t\t\t\t<kimlikTipi>1</kimlikTipi>\n";
                $xml .= "\t\t\t\t\t\t<kimlikNumarasi>" . $this->xmlEscape($r['tc']) . "</kimlikNumarasi>\n";
                $xml .= "\t\t\t\t\t\t<ad>" . $this->xmlEscape($r['evsahibiAd']) . "</ad>\n";
                $xml .= "\t\t\t\t\t\t<soyad>" . $this->xmlEscape($r['evsahibiSoyad']) . "</soyad>\n";
                $xml .= "\t\t\t\t\t</gercekKisi>\n";
            }

            $xml .= "\t\t\t\t</kisi>\n";
            $xml .= "\t\t\t\t<ibanNo>" . $this->xmlEscape($r['evsahibiIbanNo']) . "</ibanNo>\n";
            $xml .= "\t\t\t\t<isTelNumarasi>" . $this->xmlEscape($r['usertel']) . "</isTelNumarasi>\n";
            $xml .= "\t\t\t\t<ceptelNumarasi>" . $this->xmlEscape($r['usertel']) . "</ceptelNumarasi>\n";
            $xml .= "\t\t\t\t<eposta>" . $this->xmlEscape($r['usermail']) . "</eposta>\n";
            $xml .= "\t\t\t</gayrimenkulSahipBilgi>\n";
            $xml .= "\t\t</islem>\n";
        }

        $xml .= "\t</islemler>\n";
        $xml .= "</vimveritransferi>\n";

        return $xml;
    }

    /**
     * Protokol Excel HTML içeriğini üretir (btrans.asp dosya=1 uyumlu).
     */
    private function buildProtokolExcelHtml(array $company, string $year, string $month, int $fileNo, int $count): string
    {
        $vkn = htmlspecialchars($company['vkn'], ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars($company['title'], ENT_QUOTES, 'UTF-8');
        $domain = htmlspecialchars($company['domain'], ENT_QUOTES, 'UTF-8');
        $donem = htmlspecialchars($year . $month . '-' . $year . $month, ENT_QUOTES, 'UTF-8');
        $bilgiTarihi = htmlspecialchars($year . $month . '01', ENT_QUOTES, 'UTF-8');

        return '<table border="1" cellpadding="2" cellspacing="0" style="border-collapse:collapse;font-family:Calibri,sans-serif;font-size:10pt;">
            <tr height="20">
                <td colspan="3" align="center" style="font-weight:bold;background:#e9e9e9;">GENEL</td>
                <td colspan="5" align="center" style="font-weight:bold;background:#e9e9e9;">OZEL</td>
            </tr>
            <tr height="40" style="font-weight:bold;text-align:center;">
                <td>Kuruluş Kodu</td>
                <td>Vergi Kimlik Numarası</td>
                <td>Unvan</td>
                <td>Bilginin Ait Olduğu Dönem</td>
                <td>Dosya No</td>
                <td>BİLGİ TARİHİ</td>
                <td>Web Adresi</td>
                <td>İşlem Sayısı</td>
            </tr>
            <tr height="25" align="center">
                <td>538</td>
                <td>' . $vkn . '</td>
                <td>' . $title . '</td>
                <td>' . $donem . '</td>
                <td>' . $fileNo . '</td>
                <td>' . $bilgiTarihi . '</td>
                <td>' . $domain . '</td>
                <td>' . $count . '</td>
            </tr>
        </table>';
    }

    /**
     * İşlemler Excel HTML içeriğini üretir (btrans.asp dosya=2 ve dosya=3 uyumlu).
     */
    private function buildIslemlerExcelHtml(array $records, bool $isFaturaNo): string
    {
        $html = '<table border="1" cellpadding="2" cellspacing="0" style="border-collapse:collapse;font-family:Calibri,sans-serif;font-size:8pt;">';

        // 1. Başlık Satırı
        $html .= '<tr height="21" style="font-weight:bold;text-align:center;background:#e9e9e9;">';
        if ($isFaturaNo) {
            $html .= '<td colspan="2">FATURA / REZERVASYON</td>';
        }
        $html .= '<td colspan="21">İLANA KONU TAŞINMAZA İLİŞKİN BİLGİLER</td>';
        $html .= '<td colspan="4">REZERVASYON BİLGİSİ</td>';
        $html .= '<td colspan="14">ÖDEME BİLGİSİ</td>';
        $html .= '<td colspan="10">İLANA KONU TAŞINMAZIN SAHİBİNE İLİŞKİN BİLGİLER</td>';
        $html .= '</tr>';

        // 2. Alt Grup Başlık Satırı
        $html .= '<tr height="35" style="text-align:center;">';
        if ($isFaturaNo) {
            $html .= '<td></td><td></td>';
        }
        $html .= '<td colspan="3">WEB ADRESİ ALANI</td>';
        $html .= '<td colspan="8">ADRES ALANI</td>';
        $html .= '<td></td>';
        $html .= '<td>İMAR PLANI İÇİNDE İSE (o)</td>';
        $html .= '<td colspan="3"></td>';
        $html .= '<td colspan="3">3194 SAYILI İMAR KANUNU\'NA EKLENEN GEÇİCİ 16’NCI MADDE KAPSAMINDA ÇEVRE VE ŞEHİRCİLİK BAKANLIĞINA BAŞVURU (o)</td>';
        $html .= '<td colspan="2">KOORDİNAT BİLGİLERİ (wgs84) (z)</td>';
        $html .= '<td colspan="4"></td>';
        $html .= '<td></td>';
        $html .= '<td colspan="7">TAŞINMAZ SAHİBİNE PLATFORM TARAFINDAN ÖDEME PEŞİN YAPILIYOR İSE (o)</td>';
        $html .= '<td colspan="6">TAŞINMAZ SAHİBİNE PLATFORM TARAFINDAN ÖDEME KOMİSYON KARŞILIĞINDA YAPILIYOR İSE (o)</td>';
        $html .= '<td colspan="2">TÜZEL KİŞİ (o)</td>';
        $html .= '<td colspan="4">GERÇEK KİŞİ (o)</td>';
        $html .= '<td colspan="4"></td>';
        $html .= '</tr>';

        // 3. Kolon Başlıkları Satırı
        $html .= '<tr height="52" style="font-weight:bold;text-align:center;background:#f5f5f5;">';
        if ($isFaturaNo) {
            $html .= '<td>Fatura No</td>';
            $html .= '<td>Rezervasyon No</td>';
        }
        $html .= '<td>TAŞINMAZIN SİTEDEKİ ADI (z)</td>';
        $html .= '<td>TAŞINMAZIN SİTEDEKİ KODU (z)</td>';
        $html .= '<td>İLANIN YAYIMLANDIĞI WEB ADRESİ (z)</td>';
        $html .= '<td>ÜLKE (z)</td>';
        $html .= '<td>İL (z)</td>';
        $html .= '<td>İLÇE (z)</td>';
        $html .= '<td>MAHALLE/MEVKİ (z)</td>';
        $html .= '<td>CADDE (o)</td>';
        $html .= '<td>SOKAK (o)</td>';
        $html .= '<td>DIŞ KAPI NO (z)</td>';
        $html .= '<td>İÇ KAPI NO (o)</td>';
        $html .= '<td>İMAR PLANI İÇİNDE (z)</td>';
        $html .= '<td>ULUSAL ADRES VERİ TABANI (UAVT) NUMARASI (z)</td>';
        $html .= '<td>ZEMİN SİSTEM NO (z)</td>';
        $html .= '<td>YEVMİYE NO (z)</td>';
        $html .= '<td>3194 S. İMAR KANUNU GEÇİCİ 16. MADDE BAŞVURU BİLGİSİ (z)</td>';
        $html .= '<td>YAPI KAYIT BELGESİ NO (z)</td>';
        $html .= '<td>YAPI KAYIT BAŞVURU NO (z)</td>';
        $html .= '<td>DÜZENLENME TARİHİ (z)</td>';
        $html .= '<td>ENLEM (z)</td>';
        $html .= '<td>BOYLAM (z)</td>';
        $html .= '<td>REZERVASYON BAŞLANGIÇ TARİHİ (z)</td>';
        $html .= '<td>REZERVASYON BİTİŞ TARİHİ (z)</td>';
        $html .= '<td>REZERVASYON TUTARI (KDV DÂHİL) (z)</td>';
        $html .= '<td>PARA BİRİMİ (z)</td>';
        $html .= '<td>ÖDEME BİLGİSİ (z)</td>';
        $html .= '<td>KİRALANMA BAŞLANGIÇ TARİHİ (z)</td>';
        $html .= '<td>KİRALANMA BİTİŞ TARİHİ (z)</td>';
        $html .= '<td>ÖDEME YÖNTEMİ (z)</td>';
        $html .= '<td>PLATFORM IBAN BİLGİSİ (z)</td>';
        $html .= '<td>ÖDEME TARİHİ (z)</td>';
        $html .= '<td>TOPLAM ÖDEME TUTARI (KDV DÂHİL) (z)</td>';
        $html .= '<td>PARA BİRİMİ (z)</td>';
        $html .= '<td>KOMİSYON ÖDEME YÖNTEMİ (z)</td>';
        $html .= '<td>KOMİSYON PLATFORM IBAN (z)</td>';
        $html .= '<td>KOMİSYON ÖDEME TARİHİ (z)</td>';
        $html .= '<td>REZERVASYON KOMİSYON ORANI (z)</td>';
        $html .= '<td>KOMİSYON TUTARI (KDV DÂHİL) (z)</td>';
        $html .= '<td>PARA BİRİMİ (z)</td>';
        $html .= '<td>VKN (z)</td>';
        $html .= '<td>UNVAN (z)</td>';
        $html .= '<td>KİMLİK TÜRÜ (z)</td>';
        $html .= '<td>KİMLİK NUMARASI (z)</td>';
        $html .= '<td>ADI (z)</td>';
        $html .= '<td>SOYADI (z)</td>';
        $html .= '<td>IBAN BİLGİSİ (z)</td>';
        $html .= '<td>İŞ TELEFONU (o)</td>';
        $html .= '<td>CEP TELEFONU (z)</td>';
        $html .= '<td>E-POSTA ADRESİ (o)</td>';
        $html .= '</tr>';

        // Veri Satırları
        foreach ($records as $r) {
            $isPesin = ($r['odemeBilgisi'] === '1');
            $isTuzel = ($r['vkTuru'] === '2');

            $html .= '<tr height="15">';
            if ($isFaturaNo) {
                $html .= '<td>' . htmlspecialchars($r['faturaSeri'], ENT_QUOTES, 'UTF-8') . '</td>';
                $html .= '<td>' . $r['kid'] . '</td>';
            }
            $html .= '<td>' . htmlspecialchars($r['evAdi'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['evKodu'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['url'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>Türkiye</td>';
            $html .= '<td>' . htmlspecialchars($r['ilKod'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['ilceKod'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['mahalle'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['cadde'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['sokak'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['disKapiNo'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['icKapiNo'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['imarPlaniIcinde'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['uavtNo'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['zeminSistemNo'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['yevmiyeNo'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['cevreSehircilikBasvuru'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['yapiKayitBelgeNo'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['yapiKayitBasvuruNo'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['yapiKayitDuzenlemeTarihi'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['enlem'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['boylam'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['rezervasyonBasTar'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['rezervasyonBitTar'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['toplamTutar'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['paraBirimi'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['odemeBilgisi'], ENT_QUOTES, 'UTF-8') . '</td>';

            // Peşin Ödeme Alanları
            $html .= '<td>' . ($isPesin ? htmlspecialchars($r['rezervasyonBasTar'], ENT_QUOTES, 'UTF-8') : '') . '</td>';
            $html .= '<td>' . ($isPesin ? htmlspecialchars($r['rezervasyonBitTar'], ENT_QUOTES, 'UTF-8') : '') . '</td>';
            $html .= '<td>' . ($isPesin ? htmlspecialchars($r['odemeYontemi'], ENT_QUOTES, 'UTF-8') : '') . '</td>';
            $html .= '<td>' . ($isPesin ? htmlspecialchars($r['odemeYapanIbanNo'], ENT_QUOTES, 'UTF-8') : '') . '</td>';
            $html .= '<td>' . ($isPesin ? htmlspecialchars($r['odemeTarihi'], ENT_QUOTES, 'UTF-8') : '') . '</td>';
            $html .= '<td>' . ($isPesin ? htmlspecialchars($r['odemeTutariKdvDahil'], ENT_QUOTES, 'UTF-8') : '') . '</td>';
            $html .= '<td>' . ($isPesin ? htmlspecialchars($r['paraBirimi'], ENT_QUOTES, 'UTF-8') : '') . '</td>';

            // Komisyon Ödeme Alanları
            $html .= '<td>' . (!$isPesin ? htmlspecialchars($r['odemeYontemi'], ENT_QUOTES, 'UTF-8') : '') . '</td>';
            $html .= '<td>' . (!$isPesin ? htmlspecialchars($r['odemeYapanIbanNo'], ENT_QUOTES, 'UTF-8') : '') . '</td>';
            $html .= '<td>' . (!$isPesin ? htmlspecialchars($r['odemeTarihi'], ENT_QUOTES, 'UTF-8') : '') . '</td>';
            $html .= '<td>' . (!$isPesin ? htmlspecialchars($r['komisyonOrani'], ENT_QUOTES, 'UTF-8') : '') . '</td>';
            $html .= '<td>' . (!$isPesin ? htmlspecialchars($r['odemeTutariKdvDahil'], ENT_QUOTES, 'UTF-8') : '') . '</td>';
            $html .= '<td>' . (!$isPesin ? htmlspecialchars($r['paraBirimi'], ENT_QUOTES, 'UTF-8') : '') . '</td>';

            // Sahip Bilgileri
            $html .= '<td>' . ($isTuzel ? htmlspecialchars($r['vkn'], ENT_QUOTES, 'UTF-8') : '') . '</td>';
            $html .= '<td>' . ($isTuzel ? htmlspecialchars($r['vkUnvan'], ENT_QUOTES, 'UTF-8') : '') . '</td>';
            $html .= '<td>1</td>';
            $html .= '<td>' . (!$isTuzel ? htmlspecialchars($r['tc'], ENT_QUOTES, 'UTF-8') : '') . '</td>';
            $html .= '<td>' . (!$isTuzel ? htmlspecialchars($r['evsahibiAd'], ENT_QUOTES, 'UTF-8') : '') . '</td>';
            $html .= '<td>' . (!$isTuzel ? htmlspecialchars($r['evsahibiSoyad'], ENT_QUOTES, 'UTF-8') : '') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['evsahibiIbanNo'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['usertel'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['usertel'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['usermail'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '</tr>';
        }

        $html .= '</table>';
        return $html;
    }

    /**
     * btrans tablosunun varlığını kontrol eder, yoksa oluşturur.
     */
    private function ensureBtransTable(PDO $pdo): void
    {
        try {
            $checkStmt = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'btrans'");
            if ($checkStmt && $checkStmt->fetchColumn()) {
                return;
            }
        } catch (\Throwable $e) {
            // Devam et
        }

        $this->runDatabaseSetup($pdo);
    }

    /**
     * btrans tablosunu ve ilişkili kolonları oluşturur.
     *
     * @return array<string,string>
     */
    private function runDatabaseSetup(PDO $pdo): array
    {
        $status = [];

        // 1. btrans tablosu
        try {
            $pdo->exec(
                "IF OBJECT_ID(N'dbo.btrans', N'U') IS NULL
                BEGIN
                    CREATE TABLE [dbo].[btrans](
                        [id] [int] IDENTITY(1,1) NOT NULL,
                        [veri] [nvarchar](max) NULL,
                        [sayfa] [int] NULL,
                        [SqlEkVeri] [nvarchar](max) NULL,
                        [hashurl] [nvarchar](max) NULL,
                        [yil] [nvarchar](50) NULL,
                        [ay] [nvarchar](50) NULL,
                        [FileName] [nvarchar](max) NULL,
                        [isDownload] [bit] NULL DEFAULT ((0)),
                        [isEdit] [bit] NULL DEFAULT ((0)),
                        [NumberOfProccess] [int] NULL DEFAULT ((0)),
                        [NumberOfOriginalProccess] [int] NULL DEFAULT ((0)),
                        [CreatedDateTime] [datetime] NULL DEFAULT (getdate()),
                        CONSTRAINT [PK_btrans_1] PRIMARY KEY CLUSTERED ([id] ASC)
                    );
                END"
            );
            $status['btrans'] = 'OK';
        } catch (\Throwable $e) {
            $status['btrans'] = 'ERROR: ' . $e->getMessage();
        }

        // 2. destinations kolonları
        $this->addColumnIfNotExists($pdo, 'destinations', 'kod', 'nvarchar(50)', $status);

        // 3. kullanici kolonları
        $this->addColumnIfNotExists($pdo, 'kullanici', 'vkTuru', 'int', $status);
        $this->addColumnIfNotExists($pdo, 'kullanici', 'vkUnvan', 'nvarchar(255)', $status);
        $this->addColumnIfNotExists($pdo, 'kullanici', 'vkN', 'nvarchar(50)', $status);
        $this->addColumnIfNotExists($pdo, 'kullanici', 'tc', 'nvarchar(50)', $status);

        // 4. kayitlar kolonları
        $this->addColumnIfNotExists($pdo, 'kayitlar', 'btrans_odeme_bilgisi', 'nvarchar(50)', $status);
        $this->addColumnIfNotExists($pdo, 'kayitlar', 'btrans_baslangic_tarihi', 'date', $status);
        $this->addColumnIfNotExists($pdo, 'kayitlar', 'btrans_bitis_tarihi', 'date', $status);
        $this->addColumnIfNotExists($pdo, 'kayitlar', 'btrans_komisyon_orani', 'nvarchar(50)', $status);
        $this->addColumnIfNotExists($pdo, 'kayitlar', 'btrans_odeme_yontemi', 'nvarchar(50)', $status);
        $this->addColumnIfNotExists($pdo, 'kayitlar', 'btrans_iban', 'nvarchar(30)', $status);
        $this->addColumnIfNotExists($pdo, 'kayitlar', 'btrans_odeme_tarihi', 'date', $status);
        $this->addColumnIfNotExists($pdo, 'kayitlar', 'btrans_odeme_tutari_kdv_dahil', 'nvarchar(50)', $status);
        $this->addColumnIfNotExists($pdo, 'kayitlar', 'btrans_para_birimi', 'nvarchar(3)', $status);
        $this->addColumnIfNotExists($pdo, 'kayitlar', 'btrans_guncelleme_tarihi', 'date', $status);
        $this->addColumnIfNotExists($pdo, 'kayitlar', 'btrans_odeme_yapan_iban_no', 'nvarchar(26)', $status);
        $this->addColumnIfNotExists($pdo, 'kayitlar', 'btrans_odeme_yapan_iban_id', 'int', $status);
        $this->addColumnIfNotExists($pdo, 'kayitlar', 'btrans_odeme_yapan_iban_type', 'nvarchar(3)', $status);
        $this->addColumnIfNotExists($pdo, 'kayitlar', 'btrans_farkli_alici_adi', 'nvarchar(50)', $status);
        $this->addColumnIfNotExists($pdo, 'kayitlar', 'btrans_farkli_alici_soyadi', 'nvarchar(50)', $status);
        $this->addColumnIfNotExists($pdo, 'kayitlar', 'btrans_farkli_alici_unvan', 'nvarchar(50)', $status);
        $this->addColumnIfNotExists($pdo, 'kayitlar', 'btrans_farkli_alici_iban', 'nvarchar(50)', $status);

        // 5. homes kolonları
        $this->addColumnIfNotExists($pdo, 'homes', 'btrans_cadde', 'nvarchar(50)', $status);
        $this->addColumnIfNotExists($pdo, 'homes', 'btrans_sokak', 'nvarchar(50)', $status);
        $this->addColumnIfNotExists($pdo, 'homes', 'btrans_dis_kapi_no', 'nvarchar(20)', $status);
        $this->addColumnIfNotExists($pdo, 'homes', 'btrans_ic_kapi_no', 'nvarchar(20)', $status);
        $this->addColumnIfNotExists($pdo, 'homes', 'btrans_imar_plani_icinde', 'int', $status);
        $this->addColumnIfNotExists($pdo, 'homes', 'btrans_uavt_no', 'nvarchar(10)', $status);
        $this->addColumnIfNotExists($pdo, 'homes', 'btrans_zemin_sistem_no', 'nvarchar(50)', $status);
        $this->addColumnIfNotExists($pdo, 'homes', 'btrans_yevmiye_no', 'nvarchar(50)', $status);
        $this->addColumnIfNotExists($pdo, 'homes', 'btrans_cevre_sehircilik_basvuru', 'int', $status);
        $this->addColumnIfNotExists($pdo, 'homes', 'btrans_yapi_kayit_belgesi_no', 'nvarchar(50)', $status);
        $this->addColumnIfNotExists($pdo, 'homes', 'btrans_yapi_kayit_basvuru_no', 'nvarchar(50)', $status);
        $this->addColumnIfNotExists($pdo, 'homes', 'btrans_yapi_kayit_duzenleme_tarihi', 'date', $status);

        return $status;
    }

    /**
     * Tabloya eksik kolonu güvenle ekler.
     */
    private function addColumnIfNotExists(PDO $pdo, string $table, string $column, string $type, array &$status): void
    {
        $key = $table . '.' . $column;
        try {
            $check = $pdo->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
                 WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = :table AND COLUMN_NAME = :col"
            );
            $check->execute([':table' => $table, ':col' => $column]);
            if ($check->fetchColumn()) {
                $status[$key] = 'EXISTS';
                return;
            }

            $pdo->exec("ALTER TABLE dbo.[{$table}] ADD [{$column}] {$type} NULL");
            $status[$key] = 'ADDED';
        } catch (\Throwable $e) {
            $status[$key] = 'ERROR: ' . $e->getMessage();
        }
    }

    /**
     * dbo.fnTemizle fonksiyonunun varlığını kontrol eder.
     */
    private function hasFnTemizle(PDO $pdo): bool
    {
        if ($this->hasFnTemizle !== null) {
            return $this->hasFnTemizle;
        }

        try {
            $stmt = $pdo->query(
                "SELECT 1 FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.fnTemizle') AND type IN (N'FN', N'IF', N'TF', N'FS', N'FT')"
            );
            $this->hasFnTemizle = (bool) ($stmt && $stmt->fetchColumn());
        } catch (\Throwable $e) {
            $this->hasFnTemizle = false;
        }

        return $this->hasFnTemizle;
    }

    /**
     * Belirtilen uzunlukta rastgele alfanümerik hash üretir.
     */
    private function randomHash(int $length = 24): string
    {
        try {
            $bytes = random_bytes((int) ceil($length / 2));
            return substr(bin2hex($bytes), 0, $length);
        } catch (\Throwable $e) {
            return substr(md5(uniqid((string) mt_rand(), true)), 0, $length);
        }
    }

    /**
     * Tarih/saat bilgisini ISO formatında (Y-m-d H:i:s) metne çevirir.
     *
     * @param mixed $val
     */
    private function formatDateTime($val): ?string
    {
        if ($val === null || trim((string) $val) === '') {
            return null;
        }
        $str = trim((string) $val);
        $ts = strtotime($str);
        if ($ts !== false && $ts > 0) {
            return date('Y-m-d H:i:s', $ts);
        }
        return $str;
    }

    /**
     * Tarihi GİB BTRANS'ın beklediği YYYYMMDD formatına çevirir.
     *
     * @param mixed $val
     */
    private function formatDate8($val): string
    {
        if ($val === null || trim((string) $val) === '') {
            return '10000101';
        }
        $str = trim((string) $val);
        $clean = str_replace(['-', '.', '/', ' '], '', $str);

        if (strlen($clean) === 8 && ctype_digit($clean)) {
            // YYYYMMDD kontrolü (ilk 4 hane 1900-2100 arası mı)
            $y = (int) substr($clean, 0, 4);
            if ($y >= 1900 && $y <= 2100) {
                return $clean;
            }
            // DDMMYYYY ise ters çevir
            return substr($clean, 4, 4) . substr($clean, 2, 2) . substr($clean, 0, 2);
        }

        $ts = strtotime($str);
        if ($ts !== false && $ts > 0) {
            return date('Ymd', $ts);
        }

        return '10000101';
    }

    /**
     * XML içerisindeki özel karakterleri kaçırır.
     */
    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}

