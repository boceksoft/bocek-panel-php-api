<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Rezervasyon belgeleri (Natsisa'nın şablondan ürettiği PDF'ler: sözleşme, ödeme belgesi...).
 *
 *   POST /backend-api/reservation-documents/upload  -> PDF'i {cdn kökü}/natsisa_documents/xx/yy/{32 hex}.pdf'e yazar, satır ekler
 *   GET  /backend-api/reservation-documents/list    -> rezervasyonun belgeleri (yeniden eskiye)
 *   POST /backend-api/reservation-documents/delete  -> satır yumuşak silinir (IsDeleted = 1), dosya diskten silinir
 *   POST /backend-api/reservation-documents/sent    -> "Belge gönderdi" log'u (islem_kodu = gönderim ActionId); sözleşmede
 *                                                      kayitlar.sozlesme / sozlesme_send_date
 *
 * Kurulum: setup/reservation-documents (setup/collums içinde de çalışır). Tablo eksikse upload/delete 409 SETUP_REQUIRED,
 * list boş liste + tabloHazir:false.
 *
 * Dosyalar Cdn sitesinin ana klasöründe: {cdn klasörü}/natsisa_documents/xx/yy/{32 hex}.pdf (uploads altında değil:
 * cdn sitelerinde uploads/* resizerPHP'ye yönlenir). Ad rastgele (random_bytes(16)), URL'de rezervasyon kodu geçmez.
 * URL tabloya yazılmaz: Cdn (yoksa Domain) + / + FilePath ile okurken hesaplanır. PDF'ler statik sunulur.
 *
 * ForOwner kolonu tabloda duruyor (varsayılan 0) ama kullanılmıyor: belge alıcıya göre değişmez, ev sahibine ayrı şablon.
 * Her yükleme / silme kullanici_log_kaydi'na yazılır (degisiklikler JSON: islem, alanlar[{alan, eski, yeni}]).
 * Ortak gövde: id (kayitlar.id), kullanici_id, kullanici_adi, natsisa_kullanici_id, islem_kodu (Natsisa ekler).
 */
final class ReservationDocumentsController extends Controller
{
    private const TABLE = 'Reservation.ReservationDocuments';
    private const FOLDER = 'natsisa_documents';
    private const MAX_BYTES = 10 * 1024 * 1024;

    /** @var bool|null */
    private $hasLogDetailsColumn = null;

    /**
     * PDF yükle.
     *
     * @Post("upload")
     * @body id int required Rezervasyon kimliği (kayitlar.id)
     * @body pdfBase64 string required PDF içeriği (base64, en fazla 10 MB)
     * @body templateName string required Şablon adı (en fazla 150)
     * @body fileName string İndirmede görünen ad (en fazla 200, .pdf eklenir; boşsa şablon adı)
     * @body templateId int Natsisa şablon id
     */
    public function upload(): void
    {
        $pdo = $this->db->pdo();
        $id = $this->reservationId($pdo);
        $this->requireTable($pdo);

        $templateName = $this->line((string) $this->request->input('templateName', ''));
        if ($templateName === '') {
            throw new HttpException('Şablon adı boş olamaz.', 'VALIDATION', 422);
        }
        $templateName = mb_substr($templateName, 0, 150);
        $fileName = $this->downloadName((string) $this->request->input('fileName', ''), $templateName);
        $templateId = (int) $this->request->input('templateId', 0);

        $raw = (string) $this->request->input('pdfBase64', '');
        if ($raw === '') {
            throw new HttpException('PDF içeriği boş.', 'VALIDATION', 422);
        }
        if (strlen($raw) > (int) ceil(self::MAX_BYTES / 3) * 4 + 4) {
            throw new HttpException('PDF en fazla 10 MB olabilir.', 'VALIDATION', 422);
        }
        $pdf = base64_decode($raw, true);
        if ($pdf === false || $pdf === '') {
            throw new HttpException('PDF içeriği geçerli base64 değil.', 'VALIDATION', 422);
        }
        if (strlen($pdf) > self::MAX_BYTES) {
            throw new HttpException('PDF en fazla 10 MB olabilir.', 'VALIDATION', 422);
        }
        if (strncmp($pdf, '%PDF-', 5) !== 0) {
            throw new HttpException('Gönderilen dosya PDF değil.', 'VALIDATION', 422);
        }

        [$relativePath, $fullPath] = $this->writeFile($pdf);
        $size = strlen($pdf);
        $userName = $this->userName();
        $natsisaUserId = $this->natsisaUserId();

        try {
            $documentId = 0;
            $this->transaction($pdo, function () use (
                $pdo, $id, $templateId, $templateName, $fileName, $relativePath, $size, $userName, $natsisaUserId, &$documentId
            ) {
                $stmt = $pdo->prepare(
                    'INSERT INTO ' . self::TABLE . '
                        (ReservationId, TemplateId, TemplateName, FileName, FilePath, FileSize, CreatedByUserId, CreatedByName, CreatedAt, IsDeleted)
                     OUTPUT INSERTED.DocumentId
                     VALUES (:id, :tid, :tname, :fname, :fpath, :fsize, :uid, :uname, GETDATE(), 0)'
                );
                $this->execute($stmt, [
                    ':id' => $id,
                    ':tid' => $templateId > 0 ? $templateId : null,
                    ':tname' => $templateName,
                    ':fname' => $fileName,
                    ':fpath' => $relativePath,
                    ':fsize' => $size,
                    ':uid' => $natsisaUserId > 0 ? $natsisaUserId : null,
                    ':uname' => $userName !== '' ? $userName : null,
                ]);
                $documentId = (int) $stmt->fetchColumn();
                $this->log(
                    $pdo,
                    $id,
                    'Belge oluşturdu: ' . $templateName,
                    'belge-olusturuldu',
                    [['alan' => 'Belge', 'eski' => '', 'yeni' => $fileName]]
                );
            });
        } catch (\Throwable $e) {
            @unlink($fullPath);
            throw $e;
        }

        $row = $this->documentRow($pdo, $id, $documentId);
        $this->response->success($row ?? ['id' => $documentId]);
    }

    /**
     * Rezervasyonun belgeleri (silinmemiş, yeniden eskiye).
     *
     * @Get("list")
     * @query id int required Rezervasyon kimliği (kayitlar.id)
     */
    public function listDocuments(): void
    {
        $pdo = $this->db->pdo();
        $id = (int) $this->request->query('id', 0);
        if ($id <= 0) {
            throw new HttpException('Lütfen geçerli bir rezervasyon ID gönderin.', 'VALIDATION', 422);
        }
        if (!$this->tableReady($pdo)) {
            $this->response->success(['tabloHazir' => false, 'belgeler' => []]);
            return;
        }

        $stmt = $pdo->prepare($this->selectSql() . ' WHERE ReservationId = :id AND ISNULL(IsDeleted, 0) = 0 ORDER BY DocumentId DESC');
        $this->execute($stmt, [':id' => $id]);
        $base = $this->siteUrl();
        $items = array_map(function (array $row) use ($base): array {
            return $this->mapRow($row, $base);
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        $this->response->success(['tabloHazir' => true, 'belgeler' => $items]);
    }

    /**
     * Belge sil: satır IsDeleted = 1, dosya diskten silinir (gönderilmiş link artık açılmaz).
     *
     * @Post("delete")
     * @body id int required Rezervasyon kimliği
     * @body belgeId int required Belge (DocumentId)
     */
    public function deleteDocument(): void
    {
        $pdo = $this->db->pdo();
        $id = $this->reservationId($pdo);
        $this->requireTable($pdo);
        $documentId = (int) $this->request->input('belgeId', 0);
        if ($documentId <= 0) {
            throw new HttpException('Belge seçilmedi.', 'VALIDATION', 422);
        }

        $row = $this->fetchOne(
            $pdo,
            'SELECT DocumentId, TemplateName, FileName, FilePath FROM ' . self::TABLE . '
             WHERE DocumentId = :did AND ReservationId = :id AND ISNULL(IsDeleted, 0) = 0',
            [':did' => $documentId, ':id' => $id]
        );
        if (!$row) {
            throw new HttpException('Belge bu rezervasyonda bulunamadı.', 'NOT_FOUND', 404);
        }

        $this->transaction($pdo, function () use ($pdo, $id, $documentId, $row) {
            $this->execute(
                $pdo->prepare('UPDATE ' . self::TABLE . ' SET IsDeleted = 1 WHERE DocumentId = :did AND ReservationId = :id'),
                [':did' => $documentId, ':id' => $id]
            );
            $this->log(
                $pdo,
                $id,
                'Belge sildi: ' . trim((string) $row['TemplateName']),
                'belge-silindi',
                [['alan' => 'Belge', 'eski' => trim((string) $row['FileName']), 'yeni' => '']]
            );
        });

        // Dosya, satır silindikten sonra silinir; silinemezse (kilit vb.) işlem yine başarılı sayılır.
        $fileDeleted = $this->deleteFile((string) $row['FilePath']);

        $this->response->success(['id' => $id, 'belgeId' => $documentId, 'dosyaSilindi' => $fileDeleted]);
    }

    /**
     * Belge gönderildi (Natsisa gönderim kuyruğuna aldıktan sonra çağırır): işlem geçmişine "Belge gönderdi" yazılır;
     * islem_kodu gönderim kayıtlarının ActionId'sidir (bildirimler bu satırın altında gruplanır). sozlesme true ise
     * panelle uyum için kayitlar.sozlesme = 1, sozlesme_send_date = şimdi (panel aktif_pasif.asp cat=sozlesme ile aynı);
     * kolonlar yoksa bu kısım atlanır.
     *
     * @Post("sent")
     * @body id int required Rezervasyon kimliği
     * @body belgeId int required Belge (DocumentId)
     * @body ozet string Alıcılar ve kanallar ("Müşteri: E-posta, WhatsApp Business; Ev sahibi: SMS"), en fazla 300
     * @body sozlesme bool Sözleşme belgesi (kayitlar.sozlesme işaretlenir)
     */
    public function sent(): void
    {
        $pdo = $this->db->pdo();
        $id = $this->reservationId($pdo);
        $this->requireTable($pdo);
        $documentId = (int) $this->request->input('belgeId', 0);
        $row = $documentId > 0 ? $this->fetchOne(
            $pdo,
            'SELECT DocumentId, TemplateName, FileName FROM ' . self::TABLE . '
             WHERE DocumentId = :did AND ReservationId = :id AND ISNULL(IsDeleted, 0) = 0',
            [':did' => $documentId, ':id' => $id]
        ) : false;
        if (!$row) {
            throw new HttpException('Belge bu rezervasyonda bulunamadı.', 'NOT_FOUND', 404);
        }

        $summary = mb_substr($this->line((string) $this->request->input('ozet', '')), 0, 300);
        $contract = filter_var($this->request->input('sozlesme', false), FILTER_VALIDATE_BOOLEAN);
        $templateName = trim((string) $row['TemplateName']);

        $kayitlarColumns = [];
        // LOWER() SQL'de yapilmaz: Turkce collation'da 'I' -> 'ı' olur; kucuk harfe cevirme PHP'de.
        $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'kayitlar'");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
            $kayitlarColumns[strtolower((string) $column)] = true;
        }
        $markContract = $contract && isset($kayitlarColumns['sozlesme']) && isset($kayitlarColumns['sozlesme_send_date']);

        $this->transaction($pdo, function () use ($pdo, $id, $row, $summary, $templateName, $markContract) {
            $changes = [['alan' => 'Belge', 'eski' => '', 'yeni' => trim((string) $row['FileName']) . ($summary !== '' ? ' (' . $summary . ')' : '')]];
            if ($markContract) {
                $old = $this->fetchOne($pdo, 'SELECT ISNULL(sozlesme, 0) AS s FROM kayitlar WHERE id = :id', [':id' => $id]) ?: [];
                $this->execute(
                    $pdo->prepare('UPDATE kayitlar SET sozlesme = 1, sozlesme_send_date = GETDATE() WHERE id = :id'),
                    [':id' => $id]
                );
                if (empty($old['s'])) {
                    $changes[] = ['alan' => 'Sözleşme', 'eski' => 'Gönderilmedi', 'yeni' => 'Gönderildi'];
                }
            }
            $this->log(
                $pdo,
                $id,
                'Belge gönderdi: ' . $templateName . ($summary !== '' ? ' — ' . $summary : ''),
                'belge-gonderildi',
                $changes
            );
        });

        $this->response->success(['id' => $id, 'belgeId' => $documentId, 'sozlesmeIsaretlendi' => $markContract]);
    }

    // ---------------------------------------------------------------- dosya

    /**
     * @return array{0:string,1:string} [uploads'a göre yol, tam yol]
     */
    private function writeFile(string $pdf): array
    {
        $root = $this->siteRootDir() . DIRECTORY_SEPARATOR . self::FOLDER;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $name = bin2hex(random_bytes(16));
            $sub1 = substr($name, 0, 2);
            $sub2 = substr($name, 2, 2);
            $dir = $root . DIRECTORY_SEPARATOR . $sub1 . DIRECTORY_SEPARATOR . $sub2;
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new HttpException('Belge klasörü oluşturulamadı.', 'UPLOAD_DIR_FAILED', 500);
            }
            $fullPath = $dir . DIRECTORY_SEPARATOR . $name . '.pdf';
            if (file_exists($fullPath)) {
                continue;
            }

            $tempPath = $fullPath . '.tmp';
            if (@file_put_contents($tempPath, $pdf, LOCK_EX) !== strlen($pdf) || !@rename($tempPath, $fullPath)) {
                @unlink($tempPath);
                throw new HttpException('Belge dosyası yazılamadı.', 'UPLOAD_WRITE_FAILED', 500);
            }

            return [self::FOLDER . '/' . $sub1 . '/' . $sub2 . '/' . $name . '.pdf', $fullPath];
        }

        throw new HttpException('Belge dosyası adı üretilemedi.', 'UPLOAD_WRITE_FAILED', 500);
    }

    /** Yalnızca natsisa_documents altındaki .pdf silinir (yol tabloda olsa bile dışarı çıkılmaz). */
    private function deleteFile(string $relativePath): bool
    {
        if (preg_match('#^' . self::FOLDER . '/[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{32}\.pdf$#', $relativePath) !== 1) {
            return false;
        }
        $fullPath = $this->siteRootDir() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($fullPath)) {
            return true;
        }

        return @unlink($fullPath);
    }

    /**
     * Belgelerin kök klasörü: Cdn sitesinin ana klasörü. Klasör tespiti CategoriesController ile aynı: Cdn yoksa,
     * www. ile başlıyorsa ya da Domain ile aynıysa httpdocs; değilse Cdn host adlı klasör. uploads altına yazılmaz:
     * cdn sitelerinde uploads/* istekleri resizerPHP'ye yönlenir ve PDF sunulamaz.
     */
    private function siteRootDir(): string
    {
        $rootDir = dirname(__DIR__, 4);
        $folder = 'httpdocs';
        if (defined('Cdn')) {
            $parsedHost = parse_url((string) constant('Cdn'), PHP_URL_HOST);
            if ($parsedHost) {
                $domainHost = defined('Domain') ? parse_url((string) constant('Domain'), PHP_URL_HOST) : '';
                $folder = (strpos($parsedHost, 'www.') === 0 || ($domainHost && $parsedHost === $domainHost))
                    ? 'httpdocs'
                    : $parsedHost;
            }
        }

        $dir = $rootDir . DIRECTORY_SEPARATOR . $folder;
        if (!is_dir($dir)) {
            throw new HttpException('Site klasoru bulunamadi: ' . $folder, 'SITE_DIR_INVALID', 500);
        }

        return $dir;
    }

    /** Cdn (yoksa Domain) + / : belgeler sitenin ana klasöründe (natsisa_documents/...). */
    private function siteUrl(): string
    {
        $domain = defined('Cdn') ? trim((string) constant('Cdn')) : '';
        if ($domain === '') {
            $domain = defined('Domain') ? trim((string) constant('Domain')) : '';
        }
        $domain = rtrim($domain, '/');
        if ($domain !== '' && preg_match('#^https?://#i', $domain) !== 1) {
            $domain = 'https://' . $domain;
        }

        return $domain . '/';
    }

    /** İndirme adı: yol / yasak karakterler temizlenir, .pdf eklenir, en fazla 200. */
    private function downloadName(string $value, string $fallback): string
    {
        $name = $this->line($value);
        $name = (string) preg_replace('/\.pdf$/i', '', $name);
        $name = trim((string) preg_replace('#[\\\\/:*?"<>|\x00-\x1F]+#u', '-', $name), " .-");
        if ($name === '') {
            $name = trim((string) preg_replace('#[\\\\/:*?"<>|\x00-\x1F]+#u', '-', $fallback), " .-");
        }
        if ($name === '') {
            $name = 'Belge';
        }

        return mb_substr($name, 0, 196) . '.pdf';
    }

    // ---------------------------------------------------------------- satır

    private function selectSql(): string
    {
        return 'SELECT DocumentId, ReservationId, TemplateId, TemplateName, FileName, FilePath, FileSize,
                       CreatedByUserId, CreatedByName, CONVERT(varchar(19), CreatedAt, 120) AS CreatedAt
                FROM ' . self::TABLE;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function documentRow(PDO $pdo, int $id, int $documentId)
    {
        $row = $this->fetchOne($pdo, $this->selectSql() . ' WHERE DocumentId = :did AND ReservationId = :id', [':did' => $documentId, ':id' => $id]);

        return $row ? $this->mapRow($row, $this->siteUrl()) : null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function mapRow(array $row, string $base): array
    {
        return [
            'belgeId' => (int) $row['DocumentId'],
            'sablonId' => $row['TemplateId'] !== null ? (int) $row['TemplateId'] : null,
            'sablonAdi' => trim((string) $row['TemplateName']),
            'dosyaAdi' => trim((string) $row['FileName']),
            'url' => $base . ltrim((string) $row['FilePath'], '/'),
            'boyut' => (int) $row['FileSize'],
            'olusturanId' => $row['CreatedByUserId'] !== null ? (int) $row['CreatedByUserId'] : null,
            'olusturan' => trim((string) ($row['CreatedByName'] ?? '')),
            'olusturmaTarihi' => $row['CreatedAt'],
        ];
    }

    // ---------------------------------------------------------------- doğrulama

    private function reservationId(PDO $pdo): int
    {
        $id = (int) $this->request->input('id', 0);
        if ($id <= 0) {
            throw new HttpException('Lütfen geçerli bir rezervasyon ID gönderin.', 'VALIDATION', 422);
        }
        if (!$this->fetchOne($pdo, 'SELECT TOP 1 1 AS x FROM kayitlar WHERE id = :id', [':id' => $id])) {
            throw new HttpException('Belirtilen ID ile rezervasyon bulunamadı.', 'NOT_FOUND', 404);
        }

        return $id;
    }

    private function tableReady(PDO $pdo): bool
    {
        $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :s AND TABLE_NAME = :t');
        $this->execute($stmt, [':s' => 'Reservation', ':t' => 'ReservationDocuments']);
        $columns = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
        foreach (['documentid', 'reservationid', 'templateid', 'templatename', 'filename', 'filepath', 'filesize',
                     'createdbyuserid', 'createdbyname', 'createdat', 'isdeleted'] as $column) {
            if (!in_array($column, $columns, true)) {
                return false;
            }
        }

        return true;
    }

    private function requireTable(PDO $pdo): void
    {
        if (!$this->tableReady($pdo)) {
            throw new HttpException(
                'Rezervasyon belgeleri tablosu bu sitede eksik kurulu. Önce POST /setup/reservation-documents çalıştırılmalı.',
                'SETUP_REQUIRED',
                409
            );
        }
    }

    // ---------------------------------------------------------------- yardımcılar

    private function line(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|false
     */
    private function fetchOne(PDO $pdo, string $sql, array $params = [])
    {
        $stmt = $pdo->prepare($sql);
        $this->execute($stmt, $params);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string,mixed> $params
     */
    private function execute(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            if ($value === null) {
                $stmt->bindValue($key, null, PDO::PARAM_NULL);
            } elseif (is_int($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
            }
        }
        $stmt->execute();
    }

    private function transaction(PDO $pdo, callable $work): void
    {
        $pdo->beginTransaction();
        try {
            $work();
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ---------------------------------------------------------------- log (ReservationOperationsController ile aynı biçim)

    /**
     * @param array<int,array{alan:string,eski:string,yeni:string}> $changes
     */
    private function log(PDO $pdo, int $id, string $message, string $action, array $changes): void
    {
        $userName = $this->userName();
        if ($userName !== '') {
            $message .= ' (' . $userName . ')';
        }
        $params = [
            ':islmId' => (string) $id,
            ':aciklama' => $message,
            ':kullaniciId' => (int) $this->request->input('kullanici_id', 0),
        ];
        $details = [
            'kaynak' => $this->natsisaUserId() > 0 || $userName !== '' ? 'natsisa' : 'api',
            'kullaniciAdi' => $userName,
            'natsisaKullaniciId' => $this->natsisaUserId(),
            'islem' => $action,
            'alanlar' => $changes,
        ];
        $actionCode = (string) $this->request->input('islem_kodu', '');
        if (preg_match('/^[A-Za-z0-9-]{1,64}$/', $actionCode) === 1) {
            $details['islemKodu'] = $actionCode;
        }

        if ($this->hasLogDetailsColumn($pdo)) {
            $json = json_encode($details, JSON_UNESCAPED_UNICODE);
            $stmt = $pdo->prepare(
                "INSERT INTO kullanici_log_kaydi (islm, islm_id, aciklama, kullanici_id, degisiklikler)
                 VALUES ('rezervasyon', :islmId, :aciklama, :kullaniciId, :degisiklikler)"
            );
            $params[':degisiklikler'] = $json === false ? null : $json;
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO kullanici_log_kaydi (islm, islm_id, aciklama, kullanici_id)
                 VALUES ('rezervasyon', :islmId, :aciklama, :kullaniciId)"
            );
        }
        $this->execute($stmt, $params);
    }

    private function userName(): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $this->request->input('kullanici_adi', '')));

        return mb_substr($name, 0, 100);
    }

    private function natsisaUserId(): int
    {
        return max(0, (int) $this->request->input('natsisa_kullanici_id', 0));
    }

    private function hasLogDetailsColumn(PDO $pdo): bool
    {
        if ($this->hasLogDetailsColumn === null) {
            $stmt = $pdo->query("SELECT COL_LENGTH('dbo.kullanici_log_kaydi', 'degisiklikler') AS len");
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            $this->hasLogDetailsColumn = is_array($row) && $row['len'] !== null;
        }

        return $this->hasLogDetailsColumn;
    }
}
