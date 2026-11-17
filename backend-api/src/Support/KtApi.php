<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\HttpException;
use PDO;

/*
 * Kiralama Takvimi API'si (backend.kiralamatakvimi.com) istemcisi. Panelin api/app/classes/Request.php'si ile aynı
 * kimliği kullanır: X-API-Key + EstablishmentId (+ varsa Bearer token).
 *
 * Kimlik sırası: config/app.local.php (kiralamatakvimi_api_key / kiralamatakvimi_establishment_id /
 * kiralamatakvimi_api_url), boşsa genel tablosu (apiKey / establishmentId / token), en son panelin api/config.php'si.
 * Not: KtPricesController ve KiralamatakvimiController'da aynı işi yapan özel kopyalar var; yeni kod bunu kullanır.
 */
final class KtApi
{
    private const DEFAULT_URL = 'https://backend.kiralamatakvimi.com/api';

    /** @var array<string,mixed> */
    private $app;

    /** @var string */
    private $apiKey;

    /** @var string */
    private $establishmentId;

    /** @var string */
    private $token;

    /**
     * @param array<string,mixed> $app
     */
    public function __construct(PDO $pdo, array $app)
    {
        $this->app = $app;

        $genel = $pdo->query('SELECT TOP 1 * FROM genel')->fetch(PDO::FETCH_ASSOC) ?: [];
        $genel = array_change_key_case($genel, CASE_LOWER);
        $panel = array_change_key_case((array) ($app['_panel_config'] ?? []), CASE_LOWER);

        $apiKey = trim((string) ($app['kiralamatakvimi_api_key'] ?? ''));
        if ($apiKey === '') {
            $apiKey = trim((string) ($genel['apikey'] ?? ($panel['apikey'] ?? '')));
        }
        $establishmentId = trim((string) ($app['kiralamatakvimi_establishment_id'] ?? ''));
        if ($establishmentId === '') {
            $establishmentId = trim((string) ($genel['establishmentid'] ?? ($panel['establishmentid'] ?? '')));
        }
        if ($apiKey === '' || $establishmentId === '') {
            throw new HttpException(
                'Kiralama Takvimi API ayarları eksik: ' . ($apiKey === '' ? 'genel.apiKey boş' : 'EstablishmentId bulunamadı')
                . ' (config/app.local.php: kiralamatakvimi_api_key / kiralamatakvimi_establishment_id).',
                'KT_CONFIG_MISSING',
                500
            );
        }

        $this->apiKey = $apiKey;
        $this->establishmentId = $establishmentId;
        $this->token = trim((string) ($genel['token'] ?? ($panel['token'] ?? '')));
    }

    /** Sitenin Kiralama Takvimi'ndeki kurum (firma) id'si. */
    public function establishmentId(): string
    {
        return $this->establishmentId;
    }

    /**
     * İstek atar, JSON yanıtı dizi olarak döner. 2xx dışı yanıt KT'nin mesajıyla HttpException (KT_REQUEST_FAILED) olur.
     *
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $body GET dışı isteklerde JSON gövde (boşsa {})
     * @return array<string,mixed>
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, ?int $timeout = null): array
    {
        $method = strtoupper($method);
        $base = rtrim(trim((string) ($this->app['kiralamatakvimi_api_url'] ?? '')) ?: self::DEFAULT_URL, '/');
        $url = $base . '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-API-Key: ' . $this->apiKey,
            'EstablishmentId: ' . $this->establishmentId,
        ];
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout ?? ($method === 'GET' ? 20 : 30),
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($method !== 'GET') {
            $options[CURLOPT_POSTFIELDS] = $body ? (string) json_encode($body) : '{}';
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($raw === false || $status === 0) {
            throw new HttpException('Kiralama Takvimi API\'sine ulaşılamadı.', 'KT_UNREACHABLE', 502);
        }

        $decoded = json_decode((string) $raw, true);
        if ($status < 200 || $status >= 300) {
            throw new HttpException(
                mb_substr(self::errorMessage($decoded, (string) $raw, $status), 0, 500),
                'KT_REQUEST_FAILED',
                502
            );
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * KT hata yanıtından okunur mesaj: messages[0] | message | Message | düz metin.
     *
     * @param mixed $decoded
     */
    private static function errorMessage($decoded, string $raw, int $status): string
    {
        if (is_array($decoded)) {
            $lower = array_change_key_case($decoded, CASE_LOWER);
            if (isset($lower['messages']) && is_array($lower['messages']) && isset($lower['messages'][0])) {
                return (string) $lower['messages'][0];
            }
            if (!empty($lower['message']) && is_string($lower['message'])) {
                return $lower['message'];
            }
            if (!empty($lower['title']) && is_string($lower['title'])) {
                return $lower['title'];
            }
        }
        if ($status === 401) {
            return 'Kiralama Takvimi API anahtarı geçersiz.';
        }
        $text = trim(strip_tags($raw));

        return ($text !== '' ? $text : 'Kiralama Takvimi isteği başarısız') . ' (HTTP ' . $status . ')';
    }
}
