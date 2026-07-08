<?php

declare(strict_types=1);

namespace App\Core;

/*
 * Tüm endpoint'ler için standart JSON yanıt zarfı.
 *   Başarı: { "success": true,  "data": { ... } }
 *   Hata:   { "success": false, "error": { "message": "...", "code": "..." } }
 */
final class Response
{
    /** @var array */
    private $options;

    /** @var int|null Aktif endpoint'in sürümü (varsa yanıta eklenir) */
    private $version = null;

    /**
     * @param array $options config/app.php (force_http_200, cors_origin) ayarları
     */
    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    /**
     * @param int|null $version
     */
    public function setVersion($version): void
    {
        $this->version = $version;
    }

    public function success(array $data = [], int $status = 200): void
    {
        $this->send($status, ['success' => true, 'data' => $data]);
    }

    public function error(string $message, string $code = 'ERROR', int $status = 400): void
    {
        $this->send($status, [
            'success' => false,
            'error' => ['message' => $message, 'code' => $code],
        ]);
    }

    private function send(int $status, array $payload): void
    {
        if ($this->version !== null) {
            $payload['version'] = $this->version;
        }

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Access-Control-Allow-Origin: ' . ($this->options['cors_origin'] ?? '*'));
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');

            // IIS hata sayfalarını yutmasın diye istenirse her zaman 200 dön.
            http_response_code(!empty($this->options['force_http_200']) ? 200 : $status);
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
