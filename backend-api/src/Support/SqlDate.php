<?php

declare(strict_types=1);

namespace App\Support;

/*
 * Metin olarak tutulan tarih kolonlari (sezonlar.tarih1 vb.) bazi sitelerde gg.aa.yyyy, bazilarinda yyyy-aa-gg biciminde.
 * Veriye dokunmadan ikisini de okur; ikisine de uymayan deger NULL olur, sorguyu dusurmez.
 */
final class SqlDate
{
    /**
     * @param string $column Koddan gelen kolon adi (kullanici girdisi verilmemeli), orn. "sz.tarih1"
     */
    public static function any(string $column): string
    {
        return "COALESCE(TRY_CONVERT(date, {$column}, 104), TRY_CONVERT(date, {$column}, 23))";
    }
}
