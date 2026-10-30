<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;

/*
 * Sitenin robots.txt dosyasi (eski panel robottxtupload.asp karsiligi).
 * Panel gibi DB'ye bir sey yazilmaz; var/yok bilgisi dogrudan uploads klasorundeki dosyadan okunur, icerik dondurulmez.
 * Sadece .txt dosyasi yuklenir ve adi ne olursa olsun uploads klasorune robots.txt olarak kaydedilir (varsa uzerine yazilir).
 * Klasor: sadece resizerPHP/config.json "base" ayarinin gosterdigi uploads klasoru; varsayilan yok, bkz. path().
 *   GET  /backend-api/robots-txt          { exists, size, updatedAt }
 *   POST /backend-api/robots-txt          multipart: dosya (.txt)
 */
final class RobotsTxtController extends Controller
{
    private const FILE_NAME = 'robots.txt';

    private const MAX_BYTES = 64 * 1024;

    /** @Get */
    public function index(): void
    {
        $path = $this->path();
        $exists = is_file($path);
        $mtime = $exists ? @filemtime($path) : false;

        $data = [
            'exists' => $exists,
            'size' => $exists ? (int) @filesize($path) : 0,
            'updatedAt' => $mtime ? date('Y-m-d H:i:s', $mtime) : null,
        ];
        // DEBUG: dosyanin yazildigi gercek yol (sitenin robots.txt'yi baska yerden verip vermedigini anlamak icin).
        if (!empty($this->app['debug'])) $data['path'] = $path;
        $this->response->success($data);
    }

    /**
     * Yuklenen .txt dosyasini once gecici dosyaya yazar, sonra robots.txt ile degistirir; yarim dosya yayinlanmaz.
     *
     * @Post
     * @body dosya file Yuklenecek .txt dosyasi (en fazla 64KB, duz metin)
     */
    public function store(): void
    {
        $file = $_FILES['dosya'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new HttpException('Gecerli bir dosya yuklenmedi.', 'UPLOAD_FAILED', 400);
        }
        if (strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'txt') {
            throw new HttpException('Sadece .txt dosyasi yuklenebilir.', 'INVALID_FILE_TYPE', 400);
        }
        if ((int) $file['size'] > self::MAX_BYTES) throw new HttpException('robots.txt en fazla 64KB olabilir.', 'FILE_TOO_LARGE', 400);

        $content = @file_get_contents((string) $file['tmp_name']);
        if ($content === false) throw new HttpException('Dosya okunamadi.', 'UPLOAD_FAILED', 400);
        // Uzantisi .txt olan resim/zip vb. reddedilir: icerik duz metin (UTF-8) olmali.
        if (!mb_check_encoding($content, 'UTF-8') || strpos($content, "\0") !== false) {
            throw new HttpException('Dosya duz metin (.txt) olmalidir.', 'INVALID_FILE_TYPE', 400);
        }
        if (strpos($content, "\xEF\xBB\xBF") === 0) $content = substr($content, 3);

        $path = $this->path();
        $temp = dirname($path) . DIRECTORY_SEPARATOR . '.robots-' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temp, $content, LOCK_EX) !== strlen($content)) {
            @unlink($temp);
            throw new HttpException('robots.txt yazilamadi (klasor yazma izni yok).', 'WRITE_FAILED', 500);
        }
        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new HttpException('robots.txt kaydedilemedi.', 'WRITE_FAILED', 500);
        }

        $data = [
            'message' => 'robots.txt yuklendi',
            'size' => strlen($content),
            'updatedAt' => date('Y-m-d H:i:s'),
        ];
        // DEBUG: dosyanin yazildigi gercek yol.
        if (!empty($this->app['debug'])) $data['path'] = $path;
        $this->response->success($data);
    }

    /**
     * DB kullanilmaz ve varsayilan klasor yoktur: klasor ImgDownloadController'daki gibi bulunan
     * resizerPHP/config.json dosyasinin "base" ayari neyi gosteriyorsa odur (her sitede farkli).
     * Config bulunamazsa veya base gecerli bir klasore cikmazsa hicbir yere yazilmaz, hata doner.
     */
    private function path(): string
    {
        $configPath = $this->resizerConfigPath();
        if (!@file_exists($configPath)) {
            throw new HttpException('resizerPHP/config.json bulunamadi' . $this->debugPath($configPath) . '.', 'ROOT_NOT_FOUND', 500);
        }
        $config = json_decode((string) @file_get_contents($configPath), true);
        $base = is_array($config) ? trim((string) ($config['base'] ?? '')) : '';
        if ($base === '') {
            throw new HttpException('resizerPHP/config.json icinde base ayari yok' . $this->debugPath($configPath) . '.', 'ROOT_NOT_FOUND', 500);
        }
        $dir = realpath(dirname($configPath) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $base));
        if ($dir === false || !is_dir($dir)) {
            throw new HttpException('config.json base klasoru bulunamadi' . $this->debugPath($configPath . ' -> ' . $base) . '.', 'ROOT_NOT_FOUND', 500);
        }
        return $dir . DIRECTORY_SEPARATOR . self::FILE_NAME;
    }

    // ImgDownloadController ile ayni: Cdn sabitinin host'u (www. ile basliyor ya da Domain ile ayniysa httpdocs) klasoru.
    private function resizerConfigPath(): string
    {
        $rootDir = dirname(__DIR__, 4);
        $cdnFolder = 'cdn';
        if (defined('Cdn')) {
            $parsedHost = parse_url((string) constant('Cdn'), PHP_URL_HOST);
            if ($parsedHost) {
                $domainHost = defined('Domain') ? parse_url((string) constant('Domain'), PHP_URL_HOST) : '';
                $cdnFolder = (strpos($parsedHost, 'www.') === 0 || ($domainHost && $parsedHost === $domainHost)) ? 'httpdocs' : $parsedHost;
            }
        }
        return $rootDir . DIRECTORY_SEPARATOR . $cdnFolder . DIRECTORY_SEPARATOR . 'resizerPHP' . DIRECTORY_SEPARATOR . 'config.json';
    }

    // DEBUG: hata mesajina aranan yol eklenir (debug kapaliyken sunucu yolu gosterilmez).
    private function debugPath(string $path): string
    {
        return !empty($this->app['debug']) ? ' (' . $path . ')' : '';
    }
}
