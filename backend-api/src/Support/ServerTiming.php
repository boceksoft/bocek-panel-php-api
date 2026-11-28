<?php

declare(strict_types=1);

namespace App\Support;

/*
 * "Server-Timing" başlığı için ölçüm kaydı. Natsisa Web.Api bu başlığı kendi cevabına aktarır;
 * böylece tarayıcıda yavaş isteğin PHP içinde nerede beklediği (ana sorgu, toplam süre) görünür.
 * Kullanım: $t = microtime(true); ...; ServerTiming::add('sql', $t);
 */
final class ServerTiming
{
    /** @var array<int, string> */
    private static $entries = [];

    /** @param float $startedAt microtime(true) ile alınan başlangıç */
    public static function add(string $name, float $startedAt): void
    {
        $name = preg_replace('/[^A-Za-z0-9_-]/', '', $name);
        self::$entries[] = $name . ';dur=' . number_format((microtime(true) - $startedAt) * 1000, 1, '.', '');
    }

    /** Toplam PHP süresi (istek başlangıcından) dahil başlık değeri */
    public static function header(): string
    {
        $start = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);
        $entries = self::$entries;
        $entries[] = 'phptotal;dur=' . number_format((microtime(true) - $start) * 1000, 1, '.', '');

        return implode(', ', $entries);
    }
}
