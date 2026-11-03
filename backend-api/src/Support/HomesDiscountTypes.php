<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/*
 * Villa indirim turleri (indirimler.discountType). Degerlerin anlami siteye gore degisir, config 'homes_discount_types':
 *   enabled : false -> ilan ekraninda tur secimi gizli, her indirim 'default' yazilir (eski 'homes_discount_type' => false
 *             da ayni anlamdadir; ovillam).
 *   default : tur gonderilmezse / bos (NULL) kayitlarda kullanilan deger.
 *   options : [{value, label, payer, base}]
 *             payer: rezervasyonda indirimi kim karsilar (baslangic bolusumu): site | ev_sahibi | genel (komisyon oraninda)
 *             base : price (varsayilan; indirim = fiyat x oran) | commission (indirim = fiyat x komisyon% x oran;
 *                    garantivillam "Acenta Indirimi": oran komisyondan verilir, musteriye fiyat x KO x oran yansir)
 * Varsayilan (villavillam paneli): 1 Acenta (site), 2 Ev Sahibi, 3 Genel; default 3.
 * garantivillam (paneli calendar.asp / doldur.asp, site Product.php): 1 Genel (site), 2 Acenta (site, commission); default 1.
 */
final class HomesDiscountTypes
{
    private const DEFAULT_OPTIONS = [
        ['value' => 1, 'label' => 'Acenta İndirimi', 'payer' => 'site', 'base' => 'price'],
        ['value' => 2, 'label' => 'Ev Sahibi İndirimi', 'payer' => 'ev_sahibi', 'base' => 'price'],
        ['value' => 3, 'label' => 'Genel İndirim', 'payer' => 'genel', 'base' => 'price'],
    ];

    /**
     * @param array<string,mixed> $app
     * @return array{enabled:bool,default:int,options:array<int,array{value:int,label:string,payer:string,base:string}>}
     */
    public static function config(array $app): array
    {
        $cfg = is_array($app['homes_discount_types'] ?? null) ? $app['homes_discount_types'] : [];
        $options = [];
        foreach ((array) ($cfg['options'] ?? self::DEFAULT_OPTIONS) as $option) {
            if (!is_array($option) || !isset($option['value']) || !is_numeric($option['value'])) {
                continue;
            }
            $payer = (string) ($option['payer'] ?? 'site');
            $options[] = [
                'value' => (int) $option['value'],
                'label' => trim((string) ($option['label'] ?? '')) !== '' ? (string) $option['label'] : (string) $option['value'],
                'payer' => in_array($payer, ['site', 'ev_sahibi', 'genel'], true) ? $payer : 'site',
                'base' => ($option['base'] ?? 'price') === 'commission' ? 'commission' : 'price',
            ];
        }
        if ($options === []) {
            $options = self::DEFAULT_OPTIONS;
        }
        $values = array_column($options, 'value');
        $default = isset($cfg['default']) && in_array((int) $cfg['default'], $values, true)
            ? (int) $cfg['default']
            : (in_array(3, $values, true) && !isset($cfg['options']) ? 3 : (int) $values[0]);
        $enabled = (bool) ($cfg['enabled'] ?? true) && (bool) ($app['homes_discount_type'] ?? true);

        return ['enabled' => $enabled, 'default' => $default, 'options' => $options];
    }

    /** Ilan ekrani icin (HomesManagementDetailData 'indirim_turleri'). */
    public static function definition(array $app): array
    {
        return self::config($app);
    }

    /** Listede olmayan / bos deger -> varsayilan. */
    public static function normalize($value, array $app): int
    {
        $cfg = self::config($app);
        $int = is_numeric($value) ? (int) $value : 0;

        return in_array($int, array_column($cfg['options'], 'value'), true) ? $int : $cfg['default'];
    }

    /** Kaydedilecek deger: secim kapaliysa hep varsayilan; gecersizse null (cagiran karar verir). */
    public static function forSave($value, array $app): ?int
    {
        $cfg = self::config($app);
        if (!$cfg['enabled']) {
            return $cfg['default'];
        }
        $int = is_numeric($value) ? (int) $value : 0;

        return in_array($int, array_column($cfg['options'], 'value'), true) ? $int : null;
    }

    /** @return array{value:int,label:string,payer:string,base:string} */
    public static function option($value, array $app): array
    {
        $cfg = self::config($app);
        $int = self::normalize($value, $app);
        foreach ($cfg['options'] as $option) {
            if ($option['value'] === $int) {
                return $option;
            }
        }

        return $cfg['options'][0];
    }

    /**
     * Konaklama gecelerine denk gelen aktif villa indirimi (Natsisa_Fn_yenifiyathesapla_tablo ile ayni filtre); yoksa null.
     *
     * @return array{type:int,rate:float}|null
     */
    public static function appliedDiscount(PDO $pdo, int $homeId, int $site, string $start, string $end, array $app): ?array
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT TOP 1 discountType, oran
                 FROM indirimler
                 WHERE site = :site AND emlak = :emlak AND ISNULL(oran, 0) > 0
                   AND tarih1 < :bitis AND tarih2 >= :baslangic
                   AND CONVERT(date, GETDATE()) BETWEEN showDate1 AND showDate2
                 ORDER BY tarih1"
            );
            $stmt->execute([':site' => $site, ':emlak' => $homeId, ':baslangic' => $start, ':bitis' => $end]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return null;
        }
        if (!$row) {
            return null;
        }

        return [
            'type' => self::normalize($row['discountType'] ?? null, $app),
            'rate' => (float) str_replace(',', '.', (string) ($row['oran'] ?? 0)),
        ];
    }
}
