<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/*
 * Tüm controller'ların ortak tabanı. İstek, yanıt, veritabanı ve
 * uygulama ayarlarına (config/app.php + app.local.php birleşimi) erişim sağlar.
 */
abstract class Controller
{
    /** @var Request */
    protected $request;

    /** @var Response */
    protected $response;

    /** @var Database */
    protected $db;

    /** @var array */
    protected $app;

    public function __construct(Request $request, Response $response, Database $db, array $app = [])
    {
        $this->request = $request;
        $this->response = $response;
        $this->db = $db;
        $this->app = $app;
    }

    /**
     * Belge sure tipinin tutuldugu kanun7464 kolonu (ayar: gavel_duration_column, panelde elle yazilir).
     * Bos veya gecersiz kolon adinda varsayilan belgeSuresiTipi kullanilir.
     */
    protected function gavelDurationColumn(): string
    {
        $column = trim((string) ($this->app['gavel_duration_column'] ?? ''));

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) === 1 ? $column : 'belgeSuresiTipi';
    }

    /**
     * GavelBelgeSureli kolonu 0 suresiz / 1 sureli tutar; diger kolonlar 1 suresiz / 2 sureli.
     */
    protected function gavelDurationIsZeroBased(): bool
    {
        return strcasecmp($this->gavelDurationColumn(), 'GavelBelgeSureli') === 0;
    }

    /**
     * SQL ifadesi: ayardaki kolondan sure tipini API degerine cevirir (1 suresiz, 2 sureli).
     * 1/2 tutan kolon bossa NULL doner; 0/1 tutan kolonda (GavelBelgeSureli) bos deger suresiz sayilir.
     */
    /**
     * Villa adi SQL ifadesi: config homes_title_use_code aciksa homes.evkodu (bossa ad), degilse adin kendisi.
     * $alias homes tablosunun takma adi, $titleExpr villa adi ifadesi (orn. "h.baslik", "h.baslik_s2").
     */
    protected function homeTitleSql(string $alias, string $titleExpr): string
    {
        if (empty($this->app['homes_title_use_code'])) {
            return $titleExpr;
        }

        return "ISNULL(NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), {$alias}.evkodu))), ''), {$titleExpr})";
    }

    protected function gavelDurationSql(string $alias): string
    {
        $column = $this->gavelDurationColumn();

        return $this->gavelDurationIsZeroBased()
            ? "(CASE WHEN ISNULL({$alias}.[{$column}], 0) = 1 THEN 2 ELSE 1 END)"
            : "{$alias}.[{$column}]";
    }

    /**
     * API sure tipini (1 suresiz, 2 sureli) ayardaki kolonun degerine cevirir; bos/gecersizse null.
     *
     * @param mixed $value
     */
    protected function gavelDurationDbValue($value): ?int
    {
        $value = trim((string) $value);
        if ($value !== '1' && $value !== '2') {
            return null;
        }

        return $this->gavelDurationIsZeroBased() ? ((int) $value - 1) : (int) $value;
    }

    /**
     * Silinen rezervasyonun veritabanındaki dolu.durum kodu (ayar: reservation_deleted_status, varsayılan 5).
     */
    protected function deletedStatus(): int
    {
        return (int) ($this->app['reservation_deleted_status'] ?? 5);
    }

    /**
     * Veritabanındaki dolu.durum değerini API koduna çevirir: siteye özel silinme kodu her zaman 5 olur.
     *
     * @param mixed $durum
     * @return int|null
     */
    protected function normalizeStatus($durum)
    {
        if ($durum === null || trim((string) $durum) === '' || !is_numeric(trim((string) $durum))) {
            return null;
        }
        $value = (int) $durum;

        return $value === $this->deletedStatus() ? 5 : $value;
    }

    /**
     * SQL ifadesi: dolu.durum'u API koduna çevirir (silinme kodu -> 5). Silinme kodu 5 ise ifade aynen döner.
     */
    protected function normalizedStatusSql(string $column): string
    {
        $deleted = $this->deletedStatus();

        return $deleted === 5 ? $column : "(CASE WHEN {$column} = {$deleted} THEN 5 ELSE {$column} END)";
    }
}
