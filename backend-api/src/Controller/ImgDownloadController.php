<?php
namespace App\Controller;

use App\Core\HttpException;

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
                
                $targetFile = $uploadsDir . DIRECTORY_SEPARATOR . 'product' . DIRECTORY_SEPARATOR . $villaId . DIRECTORY_SEPARATOR . $fileName;
                if (@file_exists($targetFile)) {
                    @unlink($targetFile);
                }
                
                // Delete from DB
                $stmtDel = $this->db->pdo()->prepare("DELETE FROM dbo.upload WHERE uploadID = :id");
                $stmtDel->execute(['id' => $uploadId]);
                
                // Check if it was cover
                $stmtCheck = $this->db->pdo()->prepare("SELECT resim FROM homes WHERE id = :vid");
                $stmtCheck->execute(['vid' => $villaId]);
                $cover = $stmtCheck->fetchColumn();
                if ($cover == $fileName) {
                    $this->db->pdo()->prepare("UPDATE homes SET resim = NULL WHERE id = :vid")->execute(['vid' => $villaId]);
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
            throw new HttpException('GeÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â§erli bir dosya yÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¼klenmedi.', 'UPLOAD_FAILED', 400);
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

        if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
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
            $cdnBase = (defined('Cdn') ? (string) constant('Cdn') : '') . '/uploads/small/';
            $this->response->success([
                'path' => $cdnBase . $filename,
                'id' => (int)$uploadId,
                'sira' => (int)$sira,
                'message' => 'Fotograf basariyla yuklendi.'
            ]);
        } else {
            throw new HttpException('Fotograf kaydedilemedi.', 'SAVE_FAILED', 500);
        }
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
