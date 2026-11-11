<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/*
 * Ilan SEO puani (homes.seo_yuzdesi, 0-100). Ilan duzenleme ekranindaki SEO sekmesinin "genel" puaniyla birebir ayni:
 * frontend src/components/listings/tabs/SeoTab.tsx (analyzeSEO) ve alan eslemesi src/lib/mapApiToFormData.ts.
 * Biri degisirse digeri de degismeli. Hesap/yazma HomesCompletion::sync icinde yapilir.
 *
 * Alanlar (ekrandaki gibi; kayitli deger bossa ekranin urettigi deger kullanilir):
 *   meta baslik  = title, bossa "{villa adi} - Kiralık Villa"
 *   meta aciklama = description, bossa kisa_icerik
 *   slug          = url, bossa kucuk harf villa adi, bosluklar "-"
 *   anahtar kelimeler = keywords (virgulle ayrilmis)
 *   villa basligi = villa adi (HomesCode::nameColumns), icerik = icerik (HTML dahil ham uzunluk)
 * Puanlar: baslik 10-60 => 70 (kisa 20, uzun 30); aciklama 50-160; villa basligi 10-100; icerik 200-2000;
 *   anahtar kelime gecerse +30. Slug a-z0-9- ve 3-50 => 60, sadece gecerli 40, dolu 20; anahtar kelime +40.
 *   Genel = bes puanin ortalamasi (yuvarlanmis).
 * Uzunluklar JS string uzunlugu gibi UTF-16 birimi olarak sayilir.
 */
final class HomesSeoScore
{
    public const COLUMN = 'seo_yuzdesi';

    // JS \s ile ayni bosluk karakterleri (PCRE /u ile \s sadece ASCII bosluklari kapsar).
    private const JS_WHITESPACE = '[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

    public static function columnExists(PDO $pdo): bool
    {
        return HomesCompletion::homesColumnExists($pdo, self::COLUMN);
    }

    /**
     * @param array<string,mixed> $row homes satiri (title, description, url, keywords, kisa_icerik, icerik,
     *                                 icerik_uzunluk, villa adi kolonlari)
     * @param array<int,string> $nameColumns villa adi icin bakilacak kolonlar (ilk dolu olan)
     */
    public static function score(array $row, array $nameColumns): int
    {
        $name = self::firstFilled($row, $nameColumns);

        $title = self::firstFilled($row, ['title']);
        if ($title === '' && $name !== '') {
            $title = $name . ' - Kiralık Villa';
        }
        $description = self::firstFilled($row, ['description']);
        if ($description === '') {
            $description = self::firstFilled($row, ['kisa_icerik']);
        }
        $slug = self::firstFilled($row, ['url']);
        if ($slug === '') {
            $slug = (string) preg_replace('/' . self::JS_WHITESPACE . '+/u', '-', mb_strtolower($name, 'UTF-8'));
        }
        $content = (string) ($row['icerik'] ?? '');
        $contentLength = isset($row['icerik_uzunluk']) ? (int) $row['icerik_uzunluk'] : self::jsLength($content);

        $keywords = [];
        $rawKeywords = self::firstFilled($row, ['keywords']);
        if ($rawKeywords !== '') {
            foreach (explode(',', $rawKeywords) as $keyword) {
                $keyword = self::jsTrim($keyword);
                if ($keyword !== '') {
                    $keywords[] = mb_strtolower($keyword, 'UTF-8');
                }
            }
        }

        $scores = [
            self::textScore(self::jsLength($title), 10, 60, self::hasKeyword($title, $keywords)),
            self::textScore(self::jsLength($description), 50, 160, self::hasKeyword($description, $keywords)),
            self::slugScore($slug, self::hasKeyword($slug, $keywords)),
            self::textScore(self::jsLength($name), 10, 100, self::hasKeyword($name, $keywords)),
            self::textScore($contentLength, 200, 2000, self::hasKeyword($content, $keywords)),
        ];

        return (int) round(array_sum($scores) / 5);
    }

    private static function textScore(int $length, int $min, int $max, bool $hasKeyword): int
    {
        if ($length >= $min && $length <= $max) {
            $score = 70;
        } elseif ($length < $min) {
            $score = 20;
        } else {
            $score = 30;
        }

        return $score + ($hasKeyword ? 30 : 0);
    }

    private static function slugScore(string $slug, bool $hasKeyword): int
    {
        $length = self::jsLength($slug);
        $isValid = preg_match('/^[a-z0-9-]+\z/', $slug) === 1;
        if ($isValid && $length >= 3 && $length <= 50) {
            $score = 60;
        } elseif ($isValid) {
            $score = 40;
        } elseif ($length > 0) {
            $score = 20;
        } else {
            $score = 0;
        }

        return $score + ($hasKeyword ? 40 : 0);
    }

    /**
     * @param array<int,string> $keywords kucuk harfe cevrilmis, bos olmayan
     */
    private static function hasKeyword(string $text, array $keywords): bool
    {
        if ($keywords === [] || $text === '') {
            return false;
        }
        $text = mb_strtolower($text, 'UTF-8');
        foreach ($keywords as $keyword) {
            if (mb_strpos($text, $keyword, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * mapApiToFormData'daki "kayitli || uretilen" mantigi: detay API'si bosluktan ibaret degeri bos sayar,
     * dolu degeri ise kirpmadan dondurur.
     *
     * @param array<string,mixed> $row
     * @param array<int,string> $keys
     */
    private static function firstFilled(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                return (string) $row[$key];
            }
        }

        return '';
    }

    /**
     * JS String.length: UTF-16 kod birimi sayisi.
     */
    private static function jsLength(string $value): int
    {
        if ($value === '') {
            return 0;
        }

        return intdiv(strlen((string) mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }

    private static function jsTrim(string $value): string
    {
        return (string) preg_replace('/^' . self::JS_WHITESPACE . '+|' . self::JS_WHITESPACE . '+\z/u', '', $value);
    }
}
