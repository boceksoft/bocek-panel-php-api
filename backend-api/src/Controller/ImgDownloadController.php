<?php
namespace App\Controller;

use App\Support\UploadLimits;

use App\Core\HttpException;
use App\Support\Uploads;

/*
 * Ilan detaylarinda fotograf yÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¼klemek iÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â§in endpoint.
 */
final class ImgDownloadController extends Controller
{
    /**
     * @Post
     */
    public function download(): void
    {
        $villaId = $this->request->input('villaId');
        $isDelete = $this->request->input('isDelete');
        $uploadId = $this->request->input('uploadId');
        
        if ($isDelete == 'true' || $isDelete === true || $isDelete === '1' || $isDelete === 1) {
            if (!$uploadId) {
                throw new HttpException('uploadId eksik.', 'MISSING_UPLOAD_ID', 400);
            }
            
            $stmt = $this->db->pdo()->prepare("SELECT FileName FROM dbo.upload WHERE uploadID = :id");
            $stmt->execute(['id' => $uploadId]);
            $fileName = $stmt->fetchColumn();
            
            if ($fileName) {
                // Delete file from disk
                $rootDir = dirname(__DIR__, 4);
                $cdnFolder = 'cdn';
                if (defined('Cdn')) {
                    $parsedHost = parse_url(Cdn, PHP_URL_HOST);
                    if ($parsedHost) {
                        $domainHost = defined('Domain') ? parse_url(Domain, PHP_URL_HOST) : '';
                        if (strpos($parsedHost, 'www.') === 0 || ($domainHost && $parsedHost === $domainHost)) {
                            $cdnFolder = 'httpdocs';
                        } else {
                            $cdnFolder = $parsedHost;
                        }
                    }
                }
                
                $configPath = $rootDir . DIRECTORY_SEPARATOR . $cdnFolder . DIRECTORY_SEPARATOR . 'resizerPHP' . DIRECTORY_SEPARATOR . 'config.json';
                $uploadsDir = $rootDir . DIRECTORY_SEPARATOR . 'httpdocs' . DIRECTORY_SEPARATOR . 'uploads'; 
                
                if (@file_exists($configPath)) {
                    $cdnConfigStr = @file_get_contents($configPath);
                    if ($cdnConfigStr) {
                        $cdnConfig = json_decode($cdnConfigStr, true);
                        if ($cdnConfig && isset($cdnConfig['base'])) {
                            $baseRelative = str_replace('/', DIRECTORY_SEPARATOR, $cdnConfig['base']);
                            $resizerDir = dirname($configPath);
                            $calculatedUploads = realpath($resizerDir . DIRECTORY_SEPARATOR . $baseRelative);
                            if ($calculatedUploads && is_dir($calculatedUploads)) {
                                $uploadsDir = $calculatedUploads;
                            }
                        }
                    }
                }
                
                $targetFile = $uploadsDir . DIRECTORY_SEPARATOR . 'property' . DIRECTORY_SEPARATOR . $villaId . DIRECTORY_SEPARATOR . $fileName;
                if (@file_exists($targetFile)) {
                    @unlink($targetFile);
                }
                
                // Delete from DB
                $stmtDel = $this->db->pdo()->prepare("DELETE FROM dbo.upload WHERE uploadID = :id");
                $stmtDel->execute(['id' => $uploadId]);

                // Kapaksa (homes.resim kapaklari virgulle tutar) listeden cikarilir.
                $stmtCheck = $this->db->pdo()->prepare("SELECT resim FROM homes WHERE id = :vid");
                $stmtCheck->execute(['vid' => $villaId]);
                $covers = array_values(array_filter(array_map('trim', explode(',', (string) $stmtCheck->fetchColumn())), 'strlen'));
                if (in_array($fileName, $covers, true)) {
                    $remaining = array_values(array_diff($covers, [$fileName]));
                    $this->db->pdo()->prepare("UPDATE homes SET resim = :resim WHERE id = :vid")
                        ->execute(['resim' => $remaining ? implode(',', $remaining) : null, 'vid' => $villaId]);
                }
            }
            $this->response->success(['message' => 'Fotograf silindi.', 'id' => $uploadId]);
            return;
        }

        if (!$villaId || !is_numeric($villaId)) {
            throw new HttpException('villaId eksik veya geÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â§ersiz.', 'INVALID_VILLA_ID', 400);
        }

        // Villa var mi?
        $stmtVilla = $this->db->pdo()->prepare('SELECT COUNT(*) FROM homes WHERE id = :id');
        $stmtVilla->execute(['id' => $villaId]);
        if ($stmtVilla->fetchColumn() == 0) {
            throw new HttpException('Belirtilen villa bulunamadi.', 'VILLA_NOT_FOUND', 404);
        }

        $sira = $this->request->input('sira');
        $kapak = $this->request->input('kapak');
        $uploadId = $this->request->input('uploadId');

        // Kapaklar: kapakIds = "uploadId1,uploadId2,..." -> homes.resim = "dosya1,dosya2,..." (secim sirasiyla).
        // Config homes_two_cover_images kapaliysa (varsayilan) sadece ilk kapak yazilir.
        $kapakIds = trim((string) $this->request->input('kapakIds', ''));
        if ($kapakIds !== '' && (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK)) {
            $coverLimit = !empty($this->app['homes_two_cover_images']) ? PHP_INT_MAX : 1;
            $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', $kapakIds))))), 0, $coverLimit);
            if (!$ids) {
                throw new HttpException('Gecerli kapak fotografi secilmedi.', 'VALIDATION', 422);
            }
            $stmtName = $this->db->pdo()->prepare("SELECT FileName FROM dbo.upload WHERE uploadID = :id AND islm = 'emlak' AND islm_id = :villaId");
            $names = [];
            foreach ($ids as $id) {
                $stmtName->execute(['id' => $id, 'villaId' => $villaId]);
                $name = $this->imageName((string) $stmtName->fetchColumn());
                if ($name !== '') {
                    $names[] = $name;
                }
            }
            if (!$names) {
                throw new HttpException('Kapak fotografi bulunamadi.', 'NOT_FOUND', 404);
            }
            $this->db->pdo()->prepare("UPDATE homes SET resim = :resim WHERE id = :villaId")
                ->execute(['resim' => implode(',', $names), 'villaId' => $villaId]);
            $this->response->success(['message' => 'Kapak guncellendi.', 'resim' => implode(',', $names)]);
            return;
        }

        // Sadece resim olmadan kapak/sira gÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¼ncellemesi
        if ($uploadId && (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK)) {
            if ($sira !== null && is_numeric($sira)) {
                $stmtUpdate = $this->db->pdo()->prepare("UPDATE dbo.upload SET sira = :sira WHERE uploadID = :id AND islm = 'emlak' AND islm_id = :villaId");
                $stmtUpdate->execute(['sira' => $sira, 'id' => $uploadId, 'villaId' => $villaId]);
            }
            if ($kapak == 'true' || $kapak === true || $kapak === '1' || $kapak === 1) {
                $stmtGetPath = $this->db->pdo()->prepare("SELECT FileName FROM dbo.upload WHERE uploadID = :id");
                $stmtGetPath->execute(['id' => $uploadId]);
                $fileName = $this->imageName((string) $stmtGetPath->fetchColumn());
                if ($fileName) {
                    $stmtCover = $this->db->pdo()->prepare("UPDATE homes SET resim = :filename WHERE id = :villaId");
                    $stmtCover->execute(['filename' => $fileName, 'villaId' => $villaId]);
                }
            }
            $this->response->success(['message' => 'Bilgiler gÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¼ncellendi.']);
            return;
        } 

        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            // PHP dosyayi sessizce dusurdugunde sebebi acik soyle (en sik: upload_max_filesize asildi).
            $code = isset($_FILES['image']['error']) ? (int) $_FILES['image']['error'] : -1;
            $limit = UploadLimits::maxBytes();
            $limitText = $limit > 0 ? round($limit / 1048576, 1) . ' MB' : 'bilinmiyor';
            $reasons = [
                UPLOAD_ERR_INI_SIZE => 'Dosya sunucunun yukleme sinirini (upload_max_filesize: ' . $limitText . ') asiyor.',
                UPLOAD_ERR_FORM_SIZE => 'Dosya form boyut sinirini asiyor.',
                UPLOAD_ERR_PARTIAL => 'Dosya yarim yuklendi.',
                UPLOAD_ERR_NO_FILE => 'Dosya gelmedi.',
                UPLOAD_ERR_NO_TMP_DIR => 'Sunucuda gecici klasor (upload_tmp_dir) yok ya da yazilamiyor. upload_tmp_dir: "'
                    . ((string) ini_get('upload_tmp_dir') !== '' ? (string) ini_get('upload_tmp_dir') : '(tanimsiz)')
                    . '", sistem gecici klasoru: "' . sys_get_temp_dir() . '".',
                UPLOAD_ERR_CANT_WRITE => 'Sunucu dosyayi diske yazamadi.',
                UPLOAD_ERR_EXTENSION => 'Bir PHP eklentisi yuklemeyi durdurdu.',
            ];
            $reason = $reasons[$code] ?? ('Dosya gelmedi (post_max_size: ' . $limitText . ' olabilir).');
            throw new HttpException('Gecerli bir dosya yuklenmedi: ' . $reason, 'UPLOAD_FAILED', 400);
        }

        // MIME ve Dosya Tipi KontrolÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¼
        // Villa fotograflari sadece JPEG (sitede PNG/WEBP kullanilmiyor).
        $allowedMimes = ['image/jpeg'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['image']['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowedMimes)) {
            throw new HttpException('Sadece JPG / JPEG fotograf yuklenebilir.', 'INVALID_FILE_TYPE', 400);
        }

        // Maksimum boyut (ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“rn: 10MB)
        if ($_FILES['image']['size'] > 10 * 1024 * 1024) {
            throw new HttpException('Dosya boyutu 10MB limitini asiyor.', 'FILE_TOO_LARGE', 400);
        }

        $rootDir = dirname(__DIR__, 4);
        
        $cdnFolder = 'cdn';
        if (defined('Cdn')) {
            $parsedHost = parse_url(Cdn, PHP_URL_HOST);
            if ($parsedHost) {
                $domainHost = defined('Domain') ? parse_url(Domain, PHP_URL_HOST) : '';
                if (strpos($parsedHost, 'www.') === 0 || ($domainHost && $parsedHost === $domainHost)) {
                    $cdnFolder = 'httpdocs';
                } else {
                    $cdnFolder = $parsedHost;
                }
            }
        }
        
        $configPath = $rootDir . DIRECTORY_SEPARATOR . $cdnFolder . DIRECTORY_SEPARATOR . 'resizerPHP' . DIRECTORY_SEPARATOR . 'config.json';
        $uploadsDir = $rootDir . DIRECTORY_SEPARATOR . 'httpdocs' . DIRECTORY_SEPARATOR . 'uploads'; 

        if (@file_exists($configPath)) {
            $cdnConfigStr = @file_get_contents($configPath);
            if ($cdnConfigStr) {
                $cdnConfig = json_decode($cdnConfigStr, true);
                if ($cdnConfig && isset($cdnConfig['base'])) {
                    $baseRelative = str_replace('/', DIRECTORY_SEPARATOR, $cdnConfig['base']);
                    $resizerDir = dirname($configPath);
                    $calculatedUploads = realpath($resizerDir . DIRECTORY_SEPARATOR . $baseRelative);
                    if ($calculatedUploads && is_dir($calculatedUploads)) {
                        $uploadsDir = $calculatedUploads;
                    }
                }
            }
        }  

        if (!is_dir($uploadsDir)) {
            throw new HttpException('Uploads klasÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¶rÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¼ bulunamadi veya geÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â§erli bir dizin degil: ' . $uploadsDir, 'UPLOADS_DIR_INVALID', 500);
        }

        // Villa fotograflari uploads/property/{villaId}/ altina yazilir (silme de ayni klasorden yapar).
        $productDir = $uploadsDir . DIRECTORY_SEPARATOR . 'property';
        $oldNatsisaDir = $uploadsDir . DIRECTORY_SEPARATOR . 'natsisa';

        if (is_dir($oldNatsisaDir) && !is_dir($productDir)) {
            @rename($oldNatsisaDir, $productDir);
        }

        if (!is_dir($productDir)) {
            if (!@mkdir($productDir, 0755, true)) {
                throw new HttpException('property klasoru olusturulamadi.', 'MKDIR_FAILED', 500);
            }
        }

        $villaDir = $productDir . DIRECTORY_SEPARATOR . $villaId;
        if (!is_dir($villaDir)) {
            if (!@mkdir($villaDir, 0755, true)) {
                throw new HttpException('villa klasÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¶rÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¼ olusturulamadi.', 'MKDIR_FAILED', 500);
            }
        } 

        // Sadece JPEG kabul edildigi icin uzanti her zaman .jpg (istemcinin dosya adindan alinmaz).
        $ext = 'jpg';

        // fileName: istenen dosya adi (uzantisiz); bos ise eskisi gibi rastgele ad.
        // width / height: hedef boyut (piksel, 0/bos = verilmedi); ikisi de bossa orijinal boyut, bkz. saveResized().
        $width = $this->dimensionInput('width', 'Genislik');
        $height = $this->dimensionInput('height', 'Yukseklik');
        $filename = $this->uniqueFileName($villaDir, $this->slugFileName((string) $this->request->input('fileName', '')), $ext);

        $targetFile = $villaDir . DIRECTORY_SEPARATOR . $filename;

        $saved = $width > 0 || $height > 0
            ? $this->saveResized((string) $_FILES['image']['tmp_name'], $targetFile, $width, $height)
            : Uploads::saveUploadedFile((string) $_FILES['image']['tmp_name'], $targetFile);

        if ($saved) {
            try {
                if ($sira === null || !is_numeric($sira)) {
                    $sira = 99;
                }
                $dbFileName = $this->imageName($filename);

                $stmt = $this->db->pdo()->prepare("
                    INSERT INTO dbo.upload (
                        FileName, DateOfUpload, FileSize, islm, islm_id, 
                        sira, site, aciklama4
                    ) VALUES (
                        :filename, GETDATE(), :filesize, 'emlak', :villaId, 
                        :sira, 1, ''
                    )
                ");
                
                $stmt->execute([
                    'filename' => $dbFileName,
                    // Boyut secildiyse kucultulmus dosyanin gercek boyutu yazilir.
                    'filesize' => (int) (@filesize($targetFile) ?: $_FILES['image']['size']),
                    'villaId' => $villaId,
                    'sira' => $sira
                ]);

                if ($kapak == 'true' || $kapak === true || $kapak === '1' || $kapak === 1) {
                    $stmtCover = $this->db->pdo()->prepare("UPDATE homes SET resim = :filename WHERE id = :villaId");
                    $stmtCover->execute(['filename' => $dbFileName, 'villaId' => $villaId]);
                }

            } catch (\Exception $e) {
                @unlink($targetFile);
                throw new HttpException('Veritabanina kaydedilemedigi iÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â§in dosya geri silindi: ' . $e->getMessage(), 'DB_INSERT_FAILED', 500);
            }

            $uploadId = $this->db->pdo()->lastInsertId();
            // Config homes_image_property_urls aciksa yeni CDN yapisi: /uploads/property/small/{villaId}/{dosya}
            $cdnBase = Uploads::homeImageBase((defined('Cdn') ? (string) constant('Cdn') : '') . '/uploads/small/', (int) $villaId, $this->app);
            $this->response->success([
                'path' => $cdnBase . $filename,
                'fileName' => $dbFileName,
                'id' => (int)$uploadId,
                'sira' => (int)$sira,
                'message' => 'Fotograf basariyla yuklendi.'
            ]);
        } else {
            throw new HttpException('Fotograf kaydedilemedi.', 'SAVE_FAILED', 500);
        }
    }

    /**
     * Metin editoru (CKEditor) gorseli: uploads/images/ altina kaydeder, icerige yazilacak tam adresi dondurur.
     *
     * @Post("editor")
     */
    public function editor(): void
    {
        $filename = Uploads::storeImage('image', [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ], 'images');

        $this->response->success([
            'fileName' => $filename,
            'url' => Uploads::cdnDomain() . '/uploads/images/' . $filename,
        ], 201);
    }

    /**
     * Istenen dosya adini URL'e uygun hale getirir: Turkce karakterler sadelesir, kucuk harf, sadece a-z 0-9 ve tire.
     * "Havuz Manzarası (1).JPG" -> "havuz-manzarasi-1". Bos kalirsa rastgele ad uretilir.
     */
    private function slugFileName(string $name): string
    {
        $name = trim(preg_replace('/\.jpe?g$/i', '', trim($name)) ?? '');
        $name = strtr($name, ['ç' => 'c', 'Ç' => 'c', 'ğ' => 'g', 'Ğ' => 'g', 'ı' => 'i', 'İ' => 'i', 'ö' => 'o', 'Ö' => 'o',
            'ş' => 's', 'Ş' => 's', 'ü' => 'u', 'Ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u']);
        $name = strtolower($name);
        $name = trim((string) preg_replace('/[^a-z0-9]+/', '-', $name), '-');
        $name = substr($name, 0, 80);
        $name = rtrim($name, '-');
        if ($name !== '') {
            return $name;
        }
        try {
            return bin2hex(random_bytes(16));
        } catch (\Exception $e) {
            return md5(uniqid('', true));
        }
    }

    /**
     * Ayni ad hem klasorde hem upload tablosunda (tum villalar; eski resizer dosyayi sadece adla bulabilir) yoksa kullanilir,
     * varsa sonuna -2, -3 ... eklenir.
     */
    private function uniqueFileName(string $dir, string $base, string $ext): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM dbo.upload WHERE FileName = :name');
        for ($i = 1; $i < 1000; $i++) {
            $candidate = ($i === 1 ? $base : $base . '-' . $i) . '.' . $ext;
            if (@file_exists($dir . DIRECTORY_SEPARATOR . $candidate)) {
                continue;
            }
            $stmt->execute(['name' => $candidate]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $candidate;
            }
        }
        throw new HttpException('Bu isimde cok fazla dosya var; farkli bir isim girin.', 'VALIDATION', 422);
    }

    // Genislik/yukseklik: bos veya 0 = verilmedi; aksi halde 50-6000 piksel.
    private function dimensionInput(string $key, string $label): int
    {
        $raw = trim((string) $this->request->input($key, ''));
        if ($raw === '' || $raw === '0') {
            return 0;
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false || $value < 50 || $value > 6000) {
            throw new HttpException($label . ' 50-6000 piksel arasi olmalidir.', 'VALIDATION', 422);
        }
        return $value;
    }

    /**
     * Gorseli istenen boyuta getirip $target'a yazar (GD, JPEG kalite 85). JPEG'de EXIF yonu uygulanir.
     *   - Genislik ve yukseklik ikisi de verildiyse tam o olcu: oran bozulmasin diye olcuyu dolduracak kadar
     *     olceklenir, tasan kisim ortadan kirpilir.
     *   - Sadece biri verildiyse o kenar tam verilen deger olur (gerekirse buyutulur, gerekirse kucultulur),
     *     digeri orana gore. Sonuc zaten ayni boyuttaysa yeniden sikistirilmadan oldugu gibi kaydedilir.
     */
    private function saveResized(string $tmpName, string $target, int $targetWidth, int $targetHeight): bool
    {
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return false;
        }
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagecreatefromjpeg')) {
            throw new HttpException('Sunucuda resim boyutlandirma (PHP GD) yok; boyut vermeden (orijinal) yukleyin.', 'RESIZE_UNSUPPORTED', 500);
        }

        $info = @getimagesize($tmpName);
        if (!is_array($info) || $info[0] <= 0 || $info[1] <= 0) {
            throw new HttpException('Gorsel okunamadi.', 'INVALID_FILE_TYPE', 400);
        }
        $orientation = 1;
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($tmpName);
            $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        }
        // Yan donuk (EXIF 6/8) fotografta genislik ve yukseklik yer degistirir.
        $sourceWidth = in_array($orientation, [5, 6, 7, 8], true) ? $info[1] : $info[0];
        $sourceHeight = in_array($orientation, [5, 6, 7, 8], true) ? $info[0] : $info[1];
        $exact = $targetWidth > 0 && $targetHeight > 0;
        if ($exact) {
            [$outWidth, $outHeight] = [$targetWidth, $targetHeight];
        } else {
            // Tek kenar verildi: o kenar tam deger, digeri orana gore (buyutme de olabilir).
            $scale = $targetWidth > 0 ? $targetWidth / $sourceWidth : $targetHeight / $sourceHeight;
            $outWidth = max(1, (int) round($sourceWidth * $scale));
            $outHeight = max(1, (int) round($sourceHeight * $scale));
        }
        if ($outWidth > 10000 || $outHeight > 10000) {
            throw new HttpException('Bu boyutla fotografin diger kenari ' . max($outWidth, $outHeight) . ' piksel oluyor; daha kucuk bir deger girin.', 'VALIDATION', 422);
        }
        if ($outWidth === $sourceWidth && $outHeight === $sourceHeight && $orientation === 1) {
            return Uploads::saveUploadedFile($tmpName, $target);
        }
        $this->ensureImageMemory($sourceWidth, $sourceHeight, $outWidth, $outHeight);

        $source = @imagecreatefromjpeg($tmpName);
        if (!$source) {
            throw new HttpException('Gorsel okunamadi.', 'INVALID_FILE_TYPE', 400);
        }
        $rotations = [3 => 180, 6 => -90, 8 => 90];
        if (isset($rotations[$orientation])) {
            $rotated = imagerotate($source, $rotations[$orientation], 0);
            if ($rotated) {
                imagedestroy($source);
                $source = $rotated;
            }
        }

        $width = imagesx($source);
        $height = imagesy($source);
        if ($exact) {
            // Tam olcu: kaynaktan hedef oraninda, ortalanmis bir bolge alinir (cover + merkezden kirpma).
            $scale = max($targetWidth / $width, $targetHeight / $height);
            $cropWidth = min($width, (int) round($targetWidth / $scale));
            $cropHeight = min($height, (int) round($targetHeight / $scale));
            $cropX = (int) floor(($width - $cropWidth) / 2);
            $cropY = (int) floor(($height - $cropHeight) / 2);
            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
            imagecopyresampled($canvas, $source, 0, 0, $cropX, $cropY, $targetWidth, $targetHeight, $cropWidth, $cropHeight);
        } else {
            $canvas = imagecreatetruecolor($outWidth, $outHeight);
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $outWidth, $outHeight, $width, $height);
        }
        imagedestroy($source);

        $ok = imagejpeg($canvas, $target, 85);
        imagedestroy($canvas);
        @unlink($tmpName);
        if (!$ok) {
            @unlink($target);
        }
        return (bool) $ok;
    }

    /**
     * GD her piksel icin ~5 bayt tutar (kaynak + hedef; yon cevirmede kaynak bir kez daha). Gerekirse memory_limit
     * 512MB'a kadar yukseltilir; yine yetmezse PHP fatal error vermesin diye anlasilir hata doner.
     */
    private function ensureImageMemory(int $sourceWidth, int $sourceHeight, int $outWidth, int $outHeight): void
    {
        $needed = (int) (($sourceWidth * $sourceHeight * 2 + $outWidth * $outHeight) * 5) + 16 * 1048576;
        $limit = $this->memoryLimitBytes();
        if ($limit < 0 || memory_get_usage() + $needed <= $limit) {
            return;
        }
        $wanted = min(512 * 1048576, memory_get_usage() + $needed);
        if ($wanted > $limit && @ini_set('memory_limit', (string) $wanted) !== false && memory_get_usage() + $needed <= $this->memoryLimitBytes()) {
            return;
        }
        throw new HttpException('Fotograf bu boyutta islenemeyecek kadar buyuk; daha kucuk bir boyut girin.', 'RESIZE_TOO_LARGE', 400);
    }

    private function memoryLimitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return -1;
        }
        $value = (int) $raw;
        $unit = strtolower(substr($raw, -1));
        return $unit === 'g' ? $value * 1073741824 : ($unit === 'm' ? $value * 1048576 : ($unit === 'k' ? $value * 1024 : $value));
    }

    private function imageName(string $value): string
    {
        $value = trim(str_replace('\\', '/', $value));
        if ($value === '') {
            return '';
        }

        return basename($value);
    }
}
