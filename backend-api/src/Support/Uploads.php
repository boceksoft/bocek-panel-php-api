<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\HttpException;

/*
 * Panelden yuklenen gorseller icin ortak uploads/ klasoru ve CDN adresi.
 * Klasor, ImgDownloadController ile ayni sekilde resizerPHP/config.json "base" ayarindan cozulur.
 */
final class Uploads
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    public static function dir(): string
    {
        $rootDir = dirname(__DIR__, 4);
        $cdnFolder = 'cdn';
        if (defined('Cdn')) {
            $parsedHost = parse_url((string) constant('Cdn'), PHP_URL_HOST);
            if ($parsedHost) {
                $domainHost = defined('Domain') ? parse_url((string) constant('Domain'), PHP_URL_HOST) : '';
                $cdnFolder = (strpos($parsedHost, 'www.') === 0 || ($domainHost && $parsedHost === $domainHost))
                    ? 'httpdocs'
                    : $parsedHost;
            }
        }

        $uploadsDir = $rootDir . DIRECTORY_SEPARATOR . 'httpdocs' . DIRECTORY_SEPARATOR . 'uploads';
        $configPath = $rootDir . DIRECTORY_SEPARATOR . $cdnFolder . DIRECTORY_SEPARATOR . 'resizerPHP' . DIRECTORY_SEPARATOR . 'config.json';
        if (@file_exists($configPath)) {
            $cdnConfig = json_decode((string) @file_get_contents($configPath), true);
            if (is_array($cdnConfig) && isset($cdnConfig['base'])) {
                $calculated = realpath(dirname($configPath) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $cdnConfig['base']));
                if ($calculated && is_dir($calculated)) {
                    $uploadsDir = $calculated;
                }
            }
        }

        if (!is_dir($uploadsDir)) {
            throw new HttpException('Uploads klasoru bulunamadi.', 'UPLOADS_DIR_INVALID', 500);
        }

        return $uploadsDir;
    }

    /**
     * Cdn sabiti, yoksa Domain; sonunda "/" olmadan ve https:// ile.
     */
    public static function cdnDomain(): string
    {
        $domain = defined('Cdn') ? trim((string) constant('Cdn')) : '';
        if ($domain === '') {
            $domain = defined('Domain') ? trim((string) constant('Domain')) : '';
        }
        $domain = rtrim($domain, '/');
        if ($domain !== '' && preg_match('#^https?://#i', $domain) !== 1) {
            $domain = 'https://' . $domain;
        }

        return $domain;
    }

    /**
     * $_FILES[$field] gorselini dogrulayip rastgele adla uploads/ (veya uploads/{$subDir}/) altina kaydeder
     * ve dosya adini dondurur.
     *
     * @param array<string,string> $allowed mime => uzanti
     */
    public static function storeImage(string $field, array $allowed, string $subDir = ''): string
    {
        if ($subDir !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $subDir) !== 1) {
            throw new HttpException('Gecersiz klasor adi.', 'VALIDATION', 422);
        }
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            throw new HttpException('Gecerli bir dosya yuklenmedi.', 'UPLOAD_FAILED', 400);
        }
        if ($_FILES[$field]['size'] > self::MAX_BYTES) {
            throw new HttpException('Dosya boyutu 10MB limitini asiyor.', 'FILE_TOO_LARGE', 400);
        }

        $mime = self::imageMime($_FILES[$field]['tmp_name']);
        if (!is_string($mime) || !isset($allowed[$mime])) {
            throw new HttpException('Desteklenmeyen gorsel formati.', 'INVALID_FILE_TYPE', 400);
        }

        try {
            $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        } catch (\Exception $e) {
            $filename = md5(uniqid('', true)) . '.' . $allowed[$mime];
        }

        $targetDir = self::dir() . ($subDir !== '' ? DIRECTORY_SEPARATOR . $subDir : '');
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
            throw new HttpException($subDir . ' klasoru olusturulamadi.', 'MKDIR_FAILED', 500);
        }

        if (!self::saveUploadedFile((string) $_FILES[$field]['tmp_name'], $targetDir . DIRECTORY_SEPARATOR . $filename)) {
            throw new HttpException('Gorsel kaydedilemedi.', 'SAVE_FAILED', 500);
        }

        return $filename;
    }

    /**
     * Dosyanin gercek MIME turu. fileinfo eklentisi kapali sunucularda getimagesize() (PHP cekirdegi)
     * kullanilir; gorsel degilse null doner.
     */
    public static function imageMime(string $path): ?string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_file($finfo, $path) : false;
            if ($finfo) finfo_close($finfo);
            if (is_string($mime) && $mime !== '') return $mime;
        }

        $info = @getimagesize($path);
        return is_array($info) && isset($info['mime']) ? (string) $info['mime'] : null;
    }

    /**
     * Yuklenen dosyayi hedefe kaydeder. move_uploaded_file yerine kopyalar: Windows/IIS'te tasinan dosya gecici klasorun
     * (upload_tmp_dir) izinlerini korur ve CDN gibi baska kullanicilar okuyamaz; kopya hedef klasorun izinlerini alir.
     */
    public static function saveUploadedFile(string $tmpName, string $target): bool
    {
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return false;
        }

        if (!@copy($tmpName, $target)) {
            return false;
        }

        @unlink($tmpName);

        return true;
    }

    /**
     * Villa fotografi adresinin tabani. Yeni CDN yapisi (resizer): {domain}/uploads/property/{boyut}/{villaId}/{dosya};
     * resizer once uploads/product/{villaId}/, yoksa eski duz uploads/ klasorune bakar. Villa no yoksa eski yapi.
     * $base eski taban olabilir ("https://cdn.site.com/uploads/small/"): boyut ve alan adi ondan alinir.
     * Sadece config homes_image_property_urls acikken (sitenin resizer'i yeni yapiyi destekliyorsa); kapaliysa eski taban.
     *
     * @param array<string,mixed> $app
     */
    public static function homeImageBase(string $base, int $homeId, array $app = []): string
    {
        if ($homeId <= 0 || empty($app['homes_image_property_urls'])) {
            return $base;
        }

        if (preg_match('#^(.*)/uploads/([^/]+)/$#', $base, $m) === 1) {
            return $m[1] . '/uploads/property/' . $m[2] . '/' . $homeId . '/';
        }

        return $base;
    }
}
