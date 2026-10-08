<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;

/*
 * İlan detaylarında fotoğraf yüklemek için endpoint.
 */
final class ImgDownloadController extends Controller
{
    /**
     * @Post
     */
    public function download(): void
    {
        $villaId = $this->request->input('villaId');
        if (!$villaId) {
            throw new HttpException('villaId eksik.', 'MISSING_VILLA_ID', 400);
        }

        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            throw new HttpException('Geçerli bir dosya yüklenmedi.', 'UPLOAD_FAILED', 400);
        }

        // Kök dizini bul (web.villavillam.com.tr'nin bir üstü olan ana vhosts dizini)
        $rootDir = dirname(__DIR__, 4);
        
        // config.php bootstrap tarafından zaten yükleniyor, doğrudan Cdn sabitini kullanalım
        $cdnFolder = 'cdn'; // fallback
        if (defined('Cdn')) {
            // Cdn sabiti "https://cdn.villavillam.com.tr" şeklinde olduğu için parse_url ile domaini alıyoruz
            $parsedHost = parse_url(Cdn, PHP_URL_HOST);
            if ($parsedHost) {
                $domainHost = defined('Domain') ? parse_url(Domain, PHP_URL_HOST) : '';
                
                // Eğer CDN adresi "www." ile başlıyorsa veya ana Domain ile aynıysa Plesk'te klasör adı "httpdocs" olur
                if (strpos($parsedHost, 'www.') === 0 || ($domainHost && $parsedHost === $domainHost)) {
                    $cdnFolder = 'httpdocs';
                } else {
                    $cdnFolder = $parsedHost; // cdn.villavillam.com.tr
                }
            }
        }
        
        // Windows open_basedir sorunlarını aşmak için slash'leri düzelt
        $configPath = $rootDir . DIRECTORY_SEPARATOR . $cdnFolder . DIRECTORY_SEPARATOR . 'resizerPHP' . DIRECTORY_SEPARATOR . 'config.json';
        
        $uploadsDir = $rootDir . DIRECTORY_SEPARATOR . 'httpdocs' . DIRECTORY_SEPARATOR . 'uploads'; // fallback

        // Hata basmasını engellemek için @ kullanıyoruz
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
            // Klasör henüz yoksa path oluşturmayı deneyelim veya hata dönelim
            throw new HttpException('Uploads klasörü bulunamadı veya geçerli bir dizin değil: ' . $uploadsDir, 'UPLOADS_DIR_INVALID', 500);
        }

        $natsisaDir = $uploadsDir . '/natsisa';
        if (!is_dir($natsisaDir)) {
            if (!@mkdir($natsisaDir, 0755, true)) {
                throw new HttpException('natsisa klasörü oluşturulamadı.', 'MKDIR_FAILED', 500);
            }
        }

        $villaDir = $natsisaDir . '/' . $villaId;
        if (!is_dir($villaDir)) {
            if (!@mkdir($villaDir, 0755, true)) {
                throw new HttpException('villa klasörü oluşturulamadı.', 'MKDIR_FAILED', 500);
            }
        }

        $fileInfo = pathinfo($_FILES['image']['name']);
        $ext = isset($fileInfo['extension']) ? strtolower($fileInfo['extension']) : 'jpg';
        $filename = uniqid() . '.' . $ext;
        $targetFile = $villaDir . '/' . $filename;

        if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
            $this->response->success([
                'path' => 'natsisa/' . $villaId . '/' . $filename,
                'message' => 'Fotoğraf başarıyla yüklendi.'
            ]);
        } else {
            throw new HttpException('Fotoğraf kaydedilemedi.', 'SAVE_FAILED', 500);
        }
    }
}
