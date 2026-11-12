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

    /** @var bool|null homes.isDeleted kolonu var mi (istek boyunca bir kez bakilir) */
    private $homesHasDeletedColumn = null;

    /**
     * Toplu silmede isDeleted = 1 yapilan villalar listelenmez. Kolon yoksa (setup calismamis) filtre uygulanmaz.
     * $alias bos verilirse kolon adi tek basina yazilir.
     */
    protected function homesNotDeletedSql(\PDO $pdo, string $alias = 'h'): string
    {
        if ($this->homesHasDeletedColumn === null) {
            $this->homesHasDeletedColumn = (int) $pdo->query(
                "SELECT CASE WHEN COL_LENGTH('dbo.homes', 'isDeleted') IS NULL THEN 0 ELSE 1 END"
            )->fetchColumn() === 1;
        }
        if (!$this->homesHasDeletedColumn) {
            return '1 = 1';
        }

        return 'ISNULL(' . ($alias !== '' ? $alias . '.' : '') . '[isDeleted], 0) = 0';
    }

    /**
     * Belge sure tipinin tutuldugu kanun7464 kolonu (ayar: gavel_duration_column, panelde elle yazilir).
     * Bos veya gecersiz kolon adinda varsayilan belgeSuresiTipi kullanilir.
     */
    /**
     * Gercek villa adi (kod degil) SQL ifadesi: ilan detayindaki gibi config homes_code'a gore
     * (HomesCode::nameColumns: kod basliga yaziliyorsa once homes.villaadi, sonra baslik; degilse baslik).
     * $titleExpr: villaadi bossa / yoksa kullanilacak baslik ifadesi (site sonekli olabilir).
     */
    protected function homeRealNameSql(\PDO $pdo, string $alias, string $titleExpr): string
    {
        $parts = [];
        foreach (\App\Support\HomesCode::nameColumns($this->app) as $column) {
            if ($column === 'baslik') {
                $parts[] = "NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(500), {$titleExpr}))), '')";
                continue;
            }
            $exists = (int) $pdo->query(
                "SELECT CASE WHEN COL_LENGTH('dbo.homes', '{$column}') IS NULL THEN 0 ELSE 1 END"
            )->fetchColumn() === 1;
            if ($exists) {
                $parts[] = "NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(500), {$alias}.[{$column}]))), '')";
            }
        }

        return count($parts) > 1 ? 'COALESCE(' . implode(', ', $parts) . ')' : ($parts[0] ?? $titleExpr);
    }

    /**
     * WhatsApp grup adinin tutuldugu homes kolonu: sitenin config'indeki WhatsappColumnName sabiti
     * (or. '[rez_takip_yeri_adi]', koseli parantezsiz dondurulur); tanimli degil / gecersizse null.
     */
    protected function whatsappColumn(): ?string
    {
        if (!defined('WhatsappColumnName')) {
            return null;
        }
        $column = trim((string) constant('WhatsappColumnName'), " \t[]");

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) === 1 ? $column : null;
    }

    /**
     * WhatsApp grup adi icin okunacak kolonlar: config'de kolon tanimliysa sadece o; degilse eski sira
     * (whatsapp_grup, yoksa rez_takip_yeri_adi).
     *
     * @return string[]
     */
    protected function whatsappColumnCandidates(): array
    {
        $column = $this->whatsappColumn();

        return $column !== null ? [$column] : ['whatsapp_grup', 'rez_takip_yeri_adi'];
    }

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
