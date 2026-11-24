<?php

declare(strict_types=1);

namespace App\Core;

/*
 * Tüm endpoint'ler için standart JSON yanıt zarfı.
 *   Başarı: { "success": true,  "data": { ... } }
 *   Hata:   { "success": false, "error": { "message": "...", "code": "...", "details"?: { ... } } }
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

    /**
     * @param array<string,mixed> $details Boş değilse error.details olarak eklenir.
     */
    public function error(string $message, string $code = 'ERROR', int $status = 400, array $details = []): void
    {
        $error = ['message' => $message, 'code' => $code];
        if ($details !== []) {
            $error['details'] = $details;
        }

        $this->send($status, [
            'success' => false,
            'error' => $error,
        ]);
    }

    private function send(int $status, array $payload): void
    {
        if ($this->version !== null) {
            $payload['version'] = $this->version;
        }

        // Büyük yanıt (örn. rezervasyon dışa aktarma, per_page=0): JSON metni bellek sınırını aşacaksa PHP
        // "Allowed memory size exhausted" ile çökmesin; boyut ve sınırla birlikte anlaşılır hata dönsün.
        $flags = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
        $tooLarge = $this->tooLargeError($payload, $flags);
        if ($tooLarge !== null) {
            $status = 413;
            $payload = $tooLarge;
            $flags = JSON_UNESCAPED_UNICODE;
        }

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Access-Control-Allow-Origin: ' . ($this->options['cors_origin'] ?? '*'));
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');

            // IIS hata sayfalarını yutmasın diye istenirse her zaman 200 dön.
            http_response_code(!empty($this->options['force_http_200']) ? 200 : $status);
        }

        echo json_encode($payload, $flags);
    }

    /**
     * Yanıtın JSON boyutunu tahmin eder; bellek sınırına sığmayacaksa hata zarfını döndürür, sığıyorsa null.
     * Sığıyorsa ve büyükse (1 MB+) $flags'ten JSON_PRETTY_PRINT çıkarılır (girintiler boyutu ciddi büyütür).
     * json_encode metni büyütürken geçici olarak ~2 katı bellek isteyebilir; hesap buna göre yapılır.
     *
     * @return array<string,mixed>|null
     */
    private function tooLargeError(array $payload, int &$flags): ?array
    {
        $limit = self::memoryLimitBytes();
        $usage = memory_get_usage();
        // Bellek sınırsızsa ya da kullanılan bellek düşükse (küçük yanıt) tahmin yapılmaz.
        if ($limit <= 0 || $usage < $limit * 0.2) {
            return null;
        }

        $size = self::estimateJsonBytes($payload);
        if ($size > 1048576) {
            $flags &= ~JSON_PRETTY_PRINT;
        }
        $reserve = 8 * 1048576; // hata yanıtı ve PHP'nin kendi ihtiyacı için pay
        $maxSendable = max(0, (int) (($limit - $usage - $reserve) / 2));
        if ($size <= $maxSendable) {
            return null;
        }

        $mb = function (int $bytes): float {
            return round($bytes / 1048576, 1);
        };
        $rows = isset($payload['data']['data']) && is_array($payload['data']['data']) ? count($payload['data']['data']) : null;

        return [
            'success' => false,
            'error' => [
                'message' => sprintf(
                    'Çok fazla kayıt seçildi, yanıt oluşturulamadı. Seçilen: %syaklaşık %s MB. En fazla: yaklaşık %s MB. Tarih aralığını küçültüp parça parça deneyin.',
                    $rows !== null ? $rows . ' kayıt, ' : '',
                    $mb($size),
                    $mb($maxSendable)
                ),
                'code' => 'RESPONSE_TOO_LARGE',
                'details' => [
                    'size_mb' => $mb($size),
                    'max_mb' => $mb($maxSendable),
                    'memory_limit_mb' => $mb($limit),
                    'rows' => $rows,
                ],
            ],
        ];
    }

    /**
     * JSON boyut tahmini: üst seviyeler gezilir, 3. seviyedeki değerler (örn. her rezervasyon satırı) tek tek
     * kodlanır; böylece tüm metin bellekte oluşturulmadan ölçülür.
     *
     * @param mixed $value
     */
    private static function estimateJsonBytes($value, int $depth = 0): int
    {
        if (!is_array($value) || $depth >= 3) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE);
            return $json === false ? 0 : strlen($json);
        }

        $total = 2;
        foreach ($value as $key => $item) {
            $total += strlen((string) $key) + 4 + self::estimateJsonBytes($item, $depth + 1);
        }

        return $total;
    }

    /** php.ini memory_limit bayt olarak; sınırsız (-1) ise 0. */
    private static function memoryLimitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return 0;
        }
        $number = (int) $raw;
        switch (strtolower(substr($raw, -1))) {
            case 'g':
                $number *= 1024;
                // no break
            case 'm':
                $number *= 1024;
                // no break
            case 'k':
                $number *= 1024;
        }

        return $number;
    }
}
