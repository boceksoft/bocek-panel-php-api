<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;

/*
 * IP beyaz liste kontrolü. Liste boşsa kontrol kapalıdır.
 */
final class IpWhitelist
{
    /** @var string[] */
    private $allowedIps;

    /**
     * @param string[] $allowedIps
     */
    public function __construct(array $allowedIps)
    {
        $this->allowedIps = $allowedIps;
    }

    public function handle(): void
    {
        if ($this->allowedIps === []) {
            return;
        }

        if (!in_array($this->clientIp(), $this->allowedIps, true)) {
            throw new HttpException('Erişim reddedildi. Yetkisiz IP adresi.', 'IP_FORBIDDEN', 403);
        }
    }

    private function clientIp(): string
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);

            return trim($ips[0]);
        }

        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
}
