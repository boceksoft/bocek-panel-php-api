<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/*
 * dbo.Natsisa_Fn_yenifiyathesapla_tablo fonksiyonu sezonlar / sonDakika tarihlerini CONVERT(date, x, 104) ile cevirir;
 * tarih yyyy-aa-gg bicimindeyse hata verir. DB'ye dokunmadan: tarihleri standart villa fonksiyonu kullanmaya devam eder,
 * farkli bicimli kaydi olan villanin tutarlari burada ayni kurallarla hesaplanir.
 * Kurallar setup-collums.sql icindeki fonksiyonla ayni tutulmali.
 */
final class HomesPriceTable
{
    /**
     * Fonksiyonun cevirirken hata verecegi kayit var mi?
     */
    public static function hasNonStandardDates(PDO $pdo, int $homeId, int $site): bool
    {
        $stmt = $pdo->prepare(
            "SELECT CASE WHEN EXISTS (
                SELECT 1 FROM sezonlar
                WHERE site = :site1 AND islem = 'emlak' AND islem_id = :home1 AND LEN(tarih1) >= 8
                  AND (TRY_CONVERT(date, tarih1, 104) IS NULL OR TRY_CONVERT(date, tarih2, 104) IS NULL)
             ) OR EXISTS (
                SELECT 1 FROM sonDakika
                WHERE site = :site2 AND islem_id = :home2
                  AND (TRY_CONVERT(date, tarih1, 104) IS NULL OR TRY_CONVERT(date, tarih2, 104) IS NULL)
             ) THEN 1 ELSE 0 END"
        );
        $stmt->execute([':site1' => $site, ':home1' => $homeId, ':site2' => $site, ':home2' => $homeId]);

        return (int) $stmt->fetchColumn() === 1;
    }

    /**
     * Fonksiyonun yerine gecen tek satirlik sorgu parcasi (CROSS APPLY icinde).
     */
    public static function inlineSql(PDO $pdo, string $start, string $end, int $homeId, int $site): string
    {
        $t = self::calculate($pdo, $start, $end, $homeId, $site);
        $money = static function (float $value): string {
            return 'CAST(' . number_format($value, 4, '.', '') . ' AS money)';
        };

        return 'SELECT ' . $money($t['ToplamTutar']) . ' AS ToplamTutar, '
            . $money($t['IndirimTutari']) . ' AS IndirimTutari, '
            . $money($t['SahteIndirimTutari']) . ' AS SahteIndirimTutari';
    }

    /**
     * @return array{ToplamTutar:float,IndirimTutari:float,SahteIndirimTutari:float}
     */
    public static function calculate(PDO $pdo, string $start, string $end, int $homeId, int $site): array
    {
        $any1 = SqlDate::any('tarih1');
        $any2 = SqlDate::any('tarih2');

        $stmt = $pdo->prepare(
            "SELECT fiyat, CONVERT(varchar(10), {$any1}, 23) AS t1, CONVERT(varchar(10), {$any2}, 23) AS t2
             FROM sezonlar
             WHERE site = :site AND LEN(tarih1) >= 8 AND islem = 'emlak' AND islem_id = :home
             ORDER BY id ASC"
        );
        $stmt->execute([':site' => $site, ':home' => $homeId]);
        $seasons = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare(
            "SELECT oran, sahte_oran, CONVERT(varchar(10), tarih1, 23) AS t1, CONVERT(varchar(10), tarih2, 23) AS t2
             FROM indirimler
             WHERE site = :site AND emlak = :home AND CONVERT(date, GETDATE()) BETWEEN showDate1 AND showDate2
             ORDER BY id ASC"
        );
        $stmt->execute([':site' => $site, ':home' => $homeId]);
        $discounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $night = (int) date_diff(date_create($end), date_create($start))->days;
        $shortStayRate = 0;
        if ($night >= 1 && $night <= 5) {
            foreach ($pdo->query("SELECT gece_{$night} FROM genel")->fetchAll(PDO::FETCH_COLUMN) as $rate) {
                $shortStayRate = (int) $rate;
            }
        }

        $stmt = $pdo->prepare(
            "SELECT fiyat FROM sonDakika
             WHERE site = :site AND islem_id = :home
               AND {$any1} = CONVERT(date, :t1, 23) AND {$any2} = CONVERT(date, :t2, 23)
             ORDER BY id ASC"
        );
        $stmt->execute([':site' => $site, ':home' => $homeId, ':t1' => $start, ':t2' => $end]);
        $lastMinute = 0.0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $price) {
            $lastMinute = self::number($price);
        }

        return self::totals($seasons, $discounts, $start, $end, $shortStayRate, $lastMinute);
    }

    /**
     * Fonksiyonun gun gun hesabi. Birden cok kayit eslesirse fonksiyondaki gibi sonuncu gecerlidir;
     * money bicimi icin ara sonuclar 4 haneye yuvarlanir, indirim oranlari tam sayidir.
     *
     * @param array<int,array<string,mixed>> $seasons fiyat, t1, t2 (Y-m-d)
     * @param array<int,array<string,mixed>> $discounts oran, sahte_oran, t1, t2 (Y-m-d)
     * @return array{ToplamTutar:float,IndirimTutari:float,SahteIndirimTutari:float}
     */
    public static function totals(array $seasons, array $discounts, string $start, string $end, int $shortStayRate, float $lastMinute): array
    {
        $total = 0.0;
        $discountTotal = 0.0;
        $fakeDiscountTotal = 0.0;
        $missingPrice = false;

        for ($day = $start; $day < $end; $day = date('Y-m-d', strtotime($day . ' +1 day'))) {
            $price = 0.0;
            foreach ($seasons as $season) {
                if (($season['t1'] ?? null) !== null && ($season['t2'] ?? null) !== null
                    && $day >= $season['t1'] && $day <= $season['t2']
                ) {
                    $price = self::number($season['fiyat'] ?? 0);
                }
            }

            $rate = 0.0;
            $fakeRate = 0.0;
            foreach ($discounts as $discount) {
                if ($day >= (string) ($discount['t1'] ?? '') && $day <= (string) ($discount['t2'] ?? '')) {
                    $rate = round(self::number($discount['oran'] ?? 0));
                    $fakeRate = round(self::number($discount['sahte_oran'] ?? 0));
                }
            }

            if ($price == 0.0) {
                $missingPrice = true;
            }
            $price = round($price / 7, 4);
            $discountAmount = round(round($price / 100, 4) * $rate, 4);
            $discountTotal += $discountAmount;

            if ($fakeRate > 0 && $fakeRate < 100) {
                $net = $price - $discountAmount;
                $fakeDiscountTotal += round(($net * (100 / (100 - $fakeRate))) - $net + $discountAmount, 4);
            } else {
                $fakeDiscountTotal += $discountAmount;
            }

            $total += $price - $discountAmount;
        }

        $total += round(round($total / 100, 4) * $shortStayRate, 4);
        if ($missingPrice) {
            $total = 0.0;
        }
        if ($lastMinute != 0.0) {
            $total = $lastMinute;
        }

        return [
            'ToplamTutar' => $total,
            'IndirimTutari' => $discountTotal,
            'SahteIndirimTutari' => $fakeDiscountTotal,
        ];
    }

    /**
     * @param mixed $value
     */
    private static function number($value): float
    {
        return (float) str_replace(',', '.', trim((string) $value));
    }
}
