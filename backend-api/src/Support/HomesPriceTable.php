<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/*
 * dbo.Natsisa_Fn_yenifiyathesapla_tablo fonksiyonu sezonlar / sonDakika tarihlerini CONVERT(date, x, 104) ile cevirir;
 * tarih yyyy-aa-gg bicimindeyse hata verir. DB'ye dokunmadan: tarihleri standart villa fonksiyonu kullanmaya devam eder,
 * farkli bicimli kaydi olan villanin tutarlari burada ayni kurallarla hesaplanir.
 * Kurallar setup-collums.sql icindeki fonksiyonla ayni tutulmali.
 *
 * Hafta sonu fiyatlari (dbo.haftasonu; config homes_weekend_prices acik ve tablo varsa, orn. garantivillam): sitenin kendi
 * dbo.Fn_yenifiyathesapla_tablo fonksiyonundaki kural. Natsisa fonksiyonunda yok (tablo olmayan sitede fonksiyon
 * hata verirdi); tablo varsa ve villanin araliga denk gelen satiri varsa tutar burada hesaplanir:
 *   weekday = DATEPART(weekday, gun) - 1 (sunucunun DATEFIRST ayariyla, site fonksiyonu gibi SQL'den alinir);
 *   weekday 5 / 6 / 0 iken gun tarih1..tarih2 araligindaysa ve cuma / cumartesi / pazar isaretliyse
 *   o gecenin fiyati haftasonu.fiyat (gecelik, 7'ye bolunmez) ve indirimi 0 olur.
 *   Sezon fiyati olmayan gun yine toplami 0 yapar (fonksiyonda @fiyatyok hafta sonu kontrolunden once).
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
     * dbo.haftasonu tablosu var mi ve villanin bu konaklamaya denk gelen hafta sonu fiyati var mi?
     * Tablo yoksa (cogu panel) false; sorgu hata vermez.
     */
    public static function hasWeekendPrices(PDO $pdo, int $homeId, int $site, string $start, string $end): bool
    {
        return self::weekendRows($pdo, $homeId, $site, $start, $end) !== [];
    }

    /**
     * Villanin konaklama araligina denk gelen hafta sonu satirlari (t1, t2 Y-m-d; cuma, cumartesi, pazar 0/1; fiyat).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function weekendRows(PDO $pdo, int $homeId, int $site, string $start, string $end): array
    {
        if (!self::weekendTableExists($pdo)) {
            return [];
        }

        $any1 = SqlDate::any('tarih1');
        $any2 = SqlDate::any('tarih2');
        $stmt = $pdo->prepare(
            "SELECT fiyat,
                    CONVERT(varchar(10), {$any1}, 23) AS t1,
                    CONVERT(varchar(10), {$any2}, 23) AS t2,
                    CASE WHEN ISNULL(cuma, 0) = 1 THEN 1 ELSE 0 END AS cuma,
                    CASE WHEN ISNULL(cumartesi, 0) = 1 THEN 1 ELSE 0 END AS cumartesi,
                    CASE WHEN ISNULL(pazar, 0) = 1 THEN 1 ELSE 0 END AS pazar
             FROM dbo.haftasonu
             WHERE site = :site AND emlak = :home
               AND {$any1} < CONVERT(date, :end, 23)
               AND {$any2} >= CONVERT(date, :start, 23)
             ORDER BY id ASC"
        );
        $stmt->execute([':site' => $site, ':home' => $homeId, ':start' => $start, ':end' => $end]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Hafta sonu fiyatlari bu sitede kullaniliyor mu: config homes_weekend_prices acik VE dbo.haftasonu var.
     * Tablo bazi sitelerin DB'sinde kullanilmadan duruyor (orn. villaciniz); sadece tabloya bakmak yetmez.
     *
     * @param array<string,mixed> $app
     */
    public static function weekendEnabled(PDO $pdo, array $app): bool
    {
        return !empty($app['homes_weekend_prices']) && self::weekendTableExists($pdo);
    }

    /** dbo.haftasonu tablosu bu panelde var mi (eski panel "Haftasonu Fiyatlari"; orn. garantivillam). */
    public static function weekendTableExists(PDO $pdo): bool
    {
        return (int) $pdo->query("SELECT CASE WHEN OBJECT_ID(N'dbo.haftasonu', N'U') IS NULL THEN 0 ELSE 1 END")->fetchColumn() === 1;
    }

    /**
     * Villanin tum hafta sonu fiyatlari (ilan fiyatlandirma ekrani). Tablo yoksa bos.
     *
     * @return array<int,array{id:int,tarih1:string,tarih2:string,fiyat:float,cuma:bool,cumartesi:bool,pazar:bool}>
     */
    public static function weekendRowsForHome(PDO $pdo, int $homeId, int $site): array
    {
        if (!self::weekendTableExists($pdo)) {
            return [];
        }
        $any1 = SqlDate::any('tarih1');
        $any2 = SqlDate::any('tarih2');
        $stmt = $pdo->prepare(
            "SELECT id, fiyat,
                    CONVERT(varchar(10), {$any1}, 23) AS t1,
                    CONVERT(varchar(10), {$any2}, 23) AS t2,
                    CASE WHEN ISNULL(cuma, 0) = 1 THEN 1 ELSE 0 END AS cuma,
                    CASE WHEN ISNULL(cumartesi, 0) = 1 THEN 1 ELSE 0 END AS cumartesi,
                    CASE WHEN ISNULL(pazar, 0) = 1 THEN 1 ELSE 0 END AS pazar
             FROM dbo.haftasonu
             WHERE site = :site AND emlak = :home
             ORDER BY {$any1} ASC, id ASC"
        );
        $stmt->execute([':site' => $site, ':home' => $homeId]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'tarih1' => (string) ($row['t1'] ?? ''),
                'tarih2' => (string) ($row['t2'] ?? ''),
                'fiyat' => self::number($row['fiyat'] ?? 0),
                'cuma' => (int) $row['cuma'] === 1,
                'cumartesi' => (int) $row['cumartesi'] === 1,
                'pazar' => (int) $row['pazar'] === 1,
            ];
        }

        return $rows;
    }

    /**
     * Site fonksiyonundaki "DATEPART(weekday, @tarih1) - 1" degeri (sunucunun DATEFIRST ayarina gore 0-6).
     */
    private static function sqlWeekday(PDO $pdo, string $day): int
    {
        $stmt = $pdo->prepare('SELECT DATEPART(weekday, CONVERT(date, :day, 23)) - 1');
        $stmt->execute([':day' => $day]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Fonksiyonun yerine gecen tek satirlik sorgu parcasi (CROSS APPLY icinde).
     */
    public static function inlineSql(PDO $pdo, string $start, string $end, int $homeId, int $site, bool $weekendPrices = false): string
    {
        $t = self::calculate($pdo, $start, $end, $homeId, $site, $weekendPrices);
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
    public static function calculate(PDO $pdo, string $start, string $end, int $homeId, int $site, bool $weekendPrices = false): array
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

        // Hafta sonu kurali sadece sitede aciksa (weekendEnabled); kapaliyken tablodaki satirlar yok sayilir.
        $weekend = $weekendPrices ? self::weekendRows($pdo, $homeId, $site, $start, $end) : [];
        $startWeekday = $weekend !== [] ? self::sqlWeekday($pdo, $start) : null;

        return self::totals($seasons, $discounts, $start, $end, $shortStayRate, $lastMinute, $weekend, $startWeekday);
    }

    /**
     * Fonksiyonun gun gun hesabi. Birden cok kayit eslesirse fonksiyondaki gibi sonuncu gecerlidir;
     * money bicimi icin ara sonuclar 4 haneye yuvarlanir, indirim oranlari tam sayidir.
     *
     * @param array<int,array<string,mixed>> $seasons fiyat, t1, t2 (Y-m-d)
     * @param array<int,array<string,mixed>> $discounts oran, sahte_oran, t1, t2 (Y-m-d)
     * @param array<int,array<string,mixed>> $weekend haftasonu satirlari (weekendRows); bos ise hafta sonu kurali yok
     * @param int|null $startWeekday ilk gecenin SQL weekday degeri (0-6); sonraki geceler +1 mod 7
     * @return array{ToplamTutar:float,IndirimTutari:float,SahteIndirimTutari:float}
     */
    public static function totals(
        array $seasons,
        array $discounts,
        string $start,
        string $end,
        int $shortStayRate,
        float $lastMinute,
        array $weekend = [],
        ?int $startWeekday = null
    ): array {
        $dayIndex = 0;
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

            // Hafta sonu (site fonksiyonu): weekday 5 cuma, 6 cumartesi, 0 pazar; eslesen son satir gecerli.
            if ($weekend !== [] && $startWeekday !== null) {
                $weekday = ($startWeekday + $dayIndex) % 7;
                $flag = $weekday === 5 ? 'cuma' : ($weekday === 6 ? 'cumartesi' : ($weekday === 0 ? 'pazar' : ''));
                if ($flag !== '') {
                    foreach ($weekend as $row) {
                        if ($day >= (string) ($row['t1'] ?? '') && $day <= (string) ($row['t2'] ?? '')
                            && (int) ($row[$flag] ?? 0) === 1
                        ) {
                            $price = round(self::number($row['fiyat'] ?? 0), 4);
                            $rate = 0.0;
                            $fakeRate = 0.0;
                        }
                    }
                }
            }
            $dayIndex++;

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
