<?php

declare(strict_types=1);

namespace App\Support;

/*
 * Rezervasyon indirimi: "indirimi kim karsiladi" ortak modeli (natsisa docs/devir/ortak-model.md).
 *
 * Ortak dil: iki tutar — kayitlar.sitesahibi_indirimi (firmanin karsiladigi) ve kayitlar.evsahibi_indirimi (villa
 * sahibinin karsiladigi). Kolonlar ovillam'da zaten var; diger sitelere setup-collums.sql ekler.
 * Ekrandaki secim bu tutarlari dolduran kisayoldur:
 *   'site'      (1) indirimin tamami firmadan
 *   'ev_sahibi' (2) indirimin tamami villa sahibinden
 *   'genel'     (3) iki tarafin payi ayni oranda duser: firma payi = indirim x komisyon%
 * Okurken tutarlar ancak toplamlari gercek indirime (eski fiyat - toplam, yuvarlama payi 2) esitse kullanilir; degilse
 * (panelden fiyat degismis, tutarlar eskide kalmis) eski bayrak kolonu (indirimevsahibi) okunur. tutarAktar trigger'i ayni
 * kuralla calisir. Panel uyumu icin bayrak da yazilir (sadece villa sahibi karsiliyorsa 1).
 * Firma hakedisi / ev sahibine odenecek kolonlari (ovillam) varsa onlar da yeniden hesaplanir.
 */
final class ReservationDiscountModel
{
    public const SITE = 1;
    public const OWNER = 2;
    public const GENERAL = 3;

    /** Tutar tutarliligi icin yuvarlama payi (para birimi). */
    private const TOLERANCE = 2.0;

    /** reservation_discount'a eklenen ortak model anahtarlari (diger anahtarlar controller'larda). */
    public const DEFAULTS = [
        'split_columns' => [
            'site' => 'sitesahibi_indirimi',        // firmanin karsiladigi indirim
            'owner' => 'evsahibi_indirimi',         // villa sahibinin karsiladigi indirim
            'firm_payout' => 'evsahibi_hakedis',    // (ovillam) firma hakedisi = liste x KO% - firma indirimi
            'owner_payable' => 'evsahibi_odenecek', // (ovillam) ev sahibine odenecek = on odeme - firma hakedisi
        ],
    ];

    private const KEYS = [self::SITE => 'site', self::OWNER => 'ev_sahibi', self::GENERAL => 'genel'];

    /**
     * @param array<string,mixed> $cfg reservation_discount (controller varsayilanlariyla birlesmis)
     * @return array<string,mixed>
     */
    public static function config(array $cfg): array
    {
        return array_replace_recursive(self::DEFAULTS, $cfg);
    }

    public static function key(int $type): string
    {
        return self::KEYS[$type] ?? 'site';
    }

    /** 'site' | 'ev_sahibi' | 'genel' (ya da 1/2/3) -> 1/2/3; gecersizse null. */
    public static function typeFromKey($value): ?int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $int = (int) $value;

            return isset(self::KEYS[$int]) ? $int : null;
        }
        $found = array_search((string) $value, self::KEYS, true);

        return $found === false ? null : (int) $found;
    }

    /** Tutarlardan secim: sadece firma -> site, sadece villa sahibi -> ev_sahibi, ikisi -> genel. */
    public static function typeFromAmounts(float $site, float $owner): int
    {
        if ($owner <= 0) {
            return self::SITE;
        }

        return $site <= 0 ? self::OWNER : self::GENERAL;
    }

    /**
     * Kayitli satirdan secim: tutarlar (tutarliysa) > indirimevsahibi bayragi.
     *
     * @param array<string,mixed> $row kayitlar satiri
     * @param callable(mixed):float $number tutar metnini sayiya ceviren fonksiyon
     */
    public static function typeFromRow(array $row, array $cfg, callable $number): int
    {
        $amounts = self::splitAmounts($row, $cfg, $number);
        if ($amounts !== null) {
            return self::typeFromAmounts($amounts['site'], $amounts['owner']);
        }
        $ownerCol = self::existing($row, (string) ($cfg['owner_column'] ?? ''));
        if ($ownerCol !== '') {
            $owner = $row[$ownerCol];
            if ($owner === true || $owner === 1 || $owner === '1') {
                return self::OWNER;
            }
        }

        return self::SITE;
    }

    /**
     * Kayitli tutarlar; kolon yoksa, ikisi de 0 ise ya da toplamlari gercek indirime esit degilse null.
     *
     * @return array{site:float,owner:float}|null
     */
    public static function splitAmounts(array $row, array $cfg, callable $number): ?array
    {
        $cols = is_array($cfg['split_columns'] ?? null) ? $cfg['split_columns'] : [];
        $siteCol = self::existing($row, (string) ($cols['site'] ?? ''));
        $ownerCol = self::existing($row, (string) ($cols['owner'] ?? ''));
        if ($siteCol === '' && $ownerCol === '') {
            return null;
        }
        $site = $siteCol !== '' ? max(0.0, (float) $number($row[$siteCol])) : 0.0;
        $owner = $ownerCol !== '' ? max(0.0, (float) $number($row[$ownerCol])) : 0.0;
        if ($site + $owner <= 0) {
            return null;
        }
        $discount = self::discountFromRow($row, $cfg, $number);
        if (abs(($site + $owner) - $discount) > self::TOLERANCE) {
            return null;
        }

        return ['site' => $site, 'owner' => $owner];
    }

    /**
     * Secime gore dagilim.
     *
     * @return array{site:float,owner:float}
     */
    public static function shares(float $discount, float $commissionRate, int $type): array
    {
        $discount = max(0.0, $discount);
        if ($type === self::OWNER) {
            return ['site' => 0.0, 'owner' => $discount];
        }
        if ($type === self::GENERAL) {
            $site = round($discount * max(0.0, $commissionRate) / 100, 2);

            return ['site' => $site, 'owner' => round($discount - $site, 2)];
        }

        return ['site' => $discount, 'owner' => 0.0];
    }

    /**
     * Kaydedilecek kolonlar (kolon adi => deger). $columns: tablodaki kolonlar (kucuk harf anahtar => ['name'=>gercek ad]).
     * $siteAmount verilirse (elle girilen firma payi) secim yerine o kullanilir; villa sahibi payi = indirim - firma payi.
     *
     * @param array<string,array{name:string}> $columns
     * @return array<string,int>
     */
    public static function writes(array $cfg, array $columns, int $type, int $listPrice, int $total, int $down, float $commissionRate, ?float $siteAmount = null): array
    {
        $out = [];
        $col = function (string $name) use ($columns): string {
            return $name !== '' && isset($columns[strtolower($name)]) ? $columns[strtolower($name)]['name'] : '';
        };
        $discount = max(0, $listPrice - $total);
        if ($siteAmount !== null) {
            $site = (int) round(min(max(0.0, $siteAmount), (float) $discount));
        } else {
            $site = (int) round(self::shares((float) $discount, $commissionRate, $type)['site']);
        }
        $owner = $discount - $site;

        $ownerCol = $col((string) ($cfg['owner_column'] ?? ''));
        if ($ownerCol !== '') {
            // Panel uyumu (rezListesi (E)/(S)): panel sadece site / ev sahibi bilir; villa sahibi tek basina karsiliyorsa 1.
            $out[$ownerCol] = ($owner > 0 && $site <= 0) ? 1 : 0;
        }
        $cols = is_array($cfg['split_columns'] ?? null) ? $cfg['split_columns'] : [];
        $firm = (int) round($listPrice * $commissionRate / 100) - $site;
        $map = [
            'site' => $site,
            'owner' => $owner,
            'firm_payout' => $firm,
            'owner_payable' => $down - $firm,
        ];
        foreach ($map as $key => $value) {
            $name = $col((string) ($cols[$key] ?? ''));
            if ($name !== '') {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /**
     * Tutar kolonlari tabloda var mi (Genel secenegi ve elle tutar ancak o zaman kaydedilebilir).
     *
     * @param callable(string):bool $has kolon var mi
     */
    public static function supportsAmounts(array $cfg, callable $has): bool
    {
        $cols = is_array($cfg['split_columns'] ?? null) ? $cfg['split_columns'] : [];

        return $has((string) ($cols['site'] ?? '')) && $has((string) ($cols['owner'] ?? ''));
    }

    /** Eski fiyat - toplam (eski fiyat bos/0 ise 0). */
    private static function discountFromRow(array $row, array $cfg, callable $number): float
    {
        $oldCol = self::existing($row, (string) ($cfg['old_price_column'] ?? 'eskifiyat'));
        $totalCol = self::existing($row, (string) ($cfg['total_column'] ?? 'toplam_tutar'));
        $old = $oldCol !== '' ? (float) $number($row[$oldCol]) : 0.0;
        $total = $totalCol !== '' ? (float) $number($row[$totalCol]) : 0.0;

        return $old > $total ? $old - $total : 0.0;
    }

    /** Satirda (buyuk/kucuk harf duyarsiz) kolonun gercek adi; yoksa ''. */
    private static function existing(array $row, string $name): string
    {
        if ($name === '') {
            return '';
        }
        if (array_key_exists($name, $row)) {
            return $name;
        }
        foreach (array_keys($row) as $key) {
            if (strcasecmp((string) $key, $name) === 0) {
                return (string) $key;
            }
        }

        return '';
    }
}
