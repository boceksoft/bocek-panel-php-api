<?php

declare(strict_types=1);

namespace App\Core;

/*
 * Gelen HTTP isteğini tek yerden okur: metot, yol, query, JSON gövde, bearer token.
 */
final class Request
{
    /** @var array|null */
    private $bodyCache = null;

    /** @var string */
    private $basePath;

    /**
     * @param string $basePath Uygulamanın alt yolu (örn. '/backend-api'). Kökse ''.
     */
    public function __construct(string $basePath = '')
    {
        $basePath = '/' . trim($basePath, '/');
        $this->basePath = $basePath === '/' ? '' : $basePath;
    }

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * İlk yol segmenti (kaynak adı). Örn. /offers/detail -> "offers".
     */
    public function resource(): string
    {
        $segments = array_values(array_filter(explode('/', $this->path())));

        return $segments[0] ?? '';
    }

    /**
     * Sorgu dizesi ve base_path olmadan, "/" ile başlayan yol döner. Örn: "/api/offers".
     * (/backend-api/api/offers -> /api/offers)
     */
    public function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = '/' . trim($path, '/');

        // base_path'i başından soy (tam eşleşme veya "base/..." önekinde).
        if ($this->basePath !== ''
            && ($path === $this->basePath || strpos($path, $this->basePath . '/') === 0)
        ) {
            $path = '/' . trim(substr($path, strlen($this->basePath)), '/');
        }

        return $path;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function query(string $key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }

    /**
     * JSON gövdeyi diziye çevirir. Gövde JSON değilse $_POST'a düşer.
     */
    public function json(): array
    {
        if ($this->bodyCache !== null) {
            return $this->bodyCache;
        }

        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            $decoded = $_POST;
        }

        $this->bodyCache = $decoded;

        return $decoded;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, $default = null)
    {
        $body = $this->json();

        return $body[$key] ?? $default;
    }

    /**
     * @return string|null
     */
    public function bearerToken()
    {
        $header = $this->authorizationHeader();

        if ($header !== null && preg_match('/Bearer\s+(\S+)/i', $header, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @return string|null
     */
    private function authorizationHeader()
    {
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            return trim($_SERVER['HTTP_AUTHORIZATION']);
        }

        if (!empty($_SERVER['Authorization'])) {
            return trim($_SERVER['Authorization']);
        }

        if (function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    return trim($value);
                }
            }
        }

        return null;
    }
}
