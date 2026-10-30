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
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['image']['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowedMimes)) {
            throw new HttpException('Sadece JPEG, PNG ve WEBP formatlari desteklenmektedir.', 'INVALID_FILE_TYPE', 400);
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

        $productDir = $uploadsDir . DIRECTORY_SEPARATOR . 'product';
        $oldNatsisaDir = $uploadsDir . DIRECTORY_SEPARATOR . 'natsisa';

        if (is_dir($oldNatsisaDir) && !is_dir($productDir)) {
            @rename($oldNatsisaDir, $productDir);
        }

        if (!is_dir($productDir)) {
            if (!@mkdir($productDir, 0755, true)) {
                throw new HttpException('product klasÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¶rÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¼ olusturulamadi.', 'MKDIR_FAILED', 500);
            }
        }

        $villaDir = $productDir . DIRECTORY_SEPARATOR . $villaId;
        if (!is_dir($villaDir)) {
            if (!@mkdir($villaDir, 0755, true)) {
                throw new HttpException('villa klasÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¶rÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¼ olusturulamadi.', 'MKDIR_FAILED', 500);
            }
        }  

        $fileInfo = pathinfo($_FILES['image']['name']);
        $ext = isset($fileInfo['extension']) ? strtolower($fileInfo['extension']) : 'jpg';
        
        try {
            $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        } catch (\Exception $e) {
            $filename = md5(uniqid('', true)) . '.' . $ext;
        }

        $targetFile = $villaDir . DIRECTORY_SEPARATOR . $filename;

        if (Uploads::saveUploadedFile((string) $_FILES['image']['tmp_name'], $targetFile)) {
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
                    'filesize' => $_FILES['image']['size'],
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

    private function imageName(string $value): string
    {
        $value = trim(str_replace('\\', '/', $value));
        if ($value === '') {
            return '';
        }

        return basename($value);
    }
}
