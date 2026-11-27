<?php

declare(strict_types=1);

namespace App\Controller;

use PDO;
use App\Support\Uploads;

/*
 * Homes management list resource.
 *   GET /backend-api/homes-management?keyword=sea&region=5&page=1
 *
 * This is the API version of the old emlak_yonetimi.asp AJAX list.
 */
final class HomesManagementController extends Controller
{
    /**
     * @Get
     * @query page int Page number
     * @query per_page int Rows per page
     * @query keyword string Search by title, code, original name, document number or id
     * @query title string Search by title, code, original name or document number
     * @query region string Region id
     * @query type int Home type id
     * @query active int Active status
     * @query showcase int Showcase status
     * @query favorite int Favorite status
     * @query opportunity int Opportunity status
     * @query last_minute int Last minute status
     * @query year int Season year
     * @query missing_year int If 1, returns homes without season in year
     * @query document string Comma separated document filters
     * @query tamamlanma int 1: %100 tamamlanmis, 0: %100 olmayan villalar
     * @query seo int 1: SEO puani %100 olan, 0: %100 olmayan villalar
     * @query sort int 1/2 order, 3/4 id
     * @query site int Site id
     */
    public function index(): void
    {
        // Ölçüm: ana sorgudan önceki hazırlık (kolon kontrolleri, site bilgisi) ve ana sorgu süresi
        $requestStartedAt = microtime(true);
        $pdo = $this->db->pdo();
        $siteId = $this->siteId();
        $currentSite = $this->siteInfo($pdo, $siteId);
        $activeColumn = 'h.' . $this->siteColumn('aktif', $siteId);
        $showcaseColumn = 'h.' . $this->siteColumn('vitrin', $siteId);
        $favoriteColumn = 'h.' . $this->siteColumn('favori', $siteId);
        $opportunityColumn = 'h.' . $this->siteColumn('firsat', $siteId);
        $titleColumn = 'h.' . $this->siteColumn('baslik', $siteId);
        $imageColumn = 'h.' . $this->siteColumn('resim', $siteId);
        $depositColumn = 'h.' . $this->siteColumn('depozito', $siteId);
        $regionTitleColumn = 'd.' . $this->siteColumn('baslik', $siteId);
        // WhatsApp grup adi: sitenin config'indeki WhatsappColumnName; tanimli degilse whatsapp_grup, yoksa rez_takip_yeri_adi.
        $whatsappColumn = "N''";
        foreach ($this->whatsappColumnCandidates() as $candidate) {
            if (\App\Support\SchemaColumns::exists($pdo, 'dbo.homes', $candidate)) {
                $whatsappColumn = "ISNULL(h.[{$candidate}], N'')";
                break;
            }
        }

        $pageRaw = $this->p('page') !== '' ? $this->p('page') : $this->p('sayfa', '1');
        $allRows = strtolower($pageRaw) === 'tumu' || strtolower($this->p('per_page')) === 'tumu' || $this->p('per_page') === '0';
        $page = $allRows ? 1 : max(1, (int) $pageRaw);
        $perPage = $allRows ? 0 : max(1, $this->pInt('per_page', 50));
        $offset = $allRows ? 0 : (($page - 1) * $perPage);

        // Toplu silinen (isDeleted = 1) villalar listelenmez.
        $where = [$this->homesNotDeletedSql($this->db->pdo(), 'h')];
        $params = [];
        $hasTypeFilter = false;

        if ($siteId > 1) {
            $where[] = "EXISTS (
                SELECT 1
                FROM tip siteTip
                WHERE siteTip.cat = {$siteId}
                  AND ISNULL(siteTip.aktif, 0) = 1
                  AND (h.emlak_tipi = siteTip.id OR ',' + REPLACE(h.kategori, ' ', '') + ',' LIKE '%,' + CONVERT(varchar(32), siteTip.id) + ',%')
            )";
        }

        $search = $this->p('keyword') !== '' ? $this->p('keyword') : $this->p('kelime');
        $title = $this->p('title') !== '' ? $this->p('title') : $this->p('baslik');
        $extraNameColumns = [];
        if ($title !== '' || $search !== '') {
            foreach (['orj_ad', 'villaadi'] as $extraNameColumn) {
                // Config homes_orj_ad_reference_link aciksa orj_ad referans linkidir, orda aranmaz.
                if ($extraNameColumn === 'orj_ad' && !$this->searchOrjAdEnabled()) {
                    continue;
                }
                if ($this->homesColumnExists($pdo, $extraNameColumn)) {
                    $extraNameColumns[] = "ISNULL(CONVERT(nvarchar(500), h.[{$extraNameColumn}]), N'')";
                }
            }
        }
        if ($title !== '') {
            $this->addTextSearchFilter($where, $params, $titleColumn, $title, 'title', false, '', $extraNameColumns);
        }
        if ($search !== '') {
            // Genel arama WhatsApp grup adinda da yapilir.
            $this->addTextSearchFilter($where, $params, $titleColumn, $search, 'keyword', true, $whatsappColumn, $extraNameColumns);
        }

        $typeRaw = $this->p('type') !== '' ? $this->p('type') : $this->p('tip');
        if ($typeRaw !== '' && $typeRaw !== '0') {
            $typeIds = array_values(array_filter(array_map('intval', explode(',', $typeRaw)), function ($value) {
                return $value > 0;
            }));
            if ($typeIds) {
                $hasTypeFilter = true;
                $typeWhere = [];
                foreach ($typeIds as $index => $typeId) {
                    $typeParam = ':type' . $index;
                    $typeLikeParam = ':typeLike' . $index;
                    $typeWhere[] = "(h.emlak_tipi = {$typeParam} OR ',' + REPLACE(h.kategori, ' ', '') + ',' LIKE {$typeLikeParam})";
                    $params[$typeParam] = $typeId;
                    $params[$typeLikeParam] = '%,' . $typeId . ',%';
                }
                $where[] = '(' . implode(' OR ', $typeWhere) . ')';
            }
        }

        $region = $this->p('region') !== '' ? $this->p('region') : $this->p('bolge');
        if ($region !== '' && $region !== '0') {
            $regionIds = $this->safeIntList($region);
            if ($regionIds !== '') {
                $where[] = "(d.id IN ({$regionIds}) OR d.cat IN ({$regionIds}))";
            }
        }

        // Kisi sayisi araligi ve ozellikler: villa arama (/homes) ile ayni mantik (doluluk takvimi filtreleri).
        $guest = $this->pInt('kisi', 0);
        if ($guest === 1) {
            $where[] = '(h.kisi = 1 OR h.kisi = 2)';
        } elseif ($guest > 1 && $guest % 2 === 0) {
            $where[] = 'h.kisi BETWEEN ' . $guest . ' AND ' . ($guest + 2);
        } elseif ($guest > 1) {
            $where[] = 'h.kisi BETWEEN ' . ($guest - 1) . ' AND ' . ($guest + 1);
        }
        $featureIds = $this->safeIntList($this->p('ozellik'));
        if ($featureIds !== '') {
            foreach (explode(',', $featureIds) as $featureId) {
                $where[] = "'#' + REPLACE(ISNULL(h.ozellikler, ''), ' ', '') + '#' LIKE '%#" . (int) $featureId . "#%'";
            }
        }

        $this->addBinaryFilter($where, $activeColumn, 'active', 'aktif');
        $this->addBinaryFilter($where, $showcaseColumn, 'showcase', 'vitrin');
        $this->addBinaryFilter($where, $favoriteColumn, 'favorite', 'favori');
        $this->addBinaryFilter($where, $opportunityColumn, 'opportunity', 'firsat');

        $lastMinute = $this->p('last_minute') !== '' ? $this->p('last_minute') : $this->p('sondakika');
        if ($lastMinute === '1') {
            $where[] = 'lastMinute.exists_value = 1';
        } elseif ($lastMinute === '0') {
            $where[] = 'lastMinute.exists_value = 0';
        }

        $active = $this->p('active') !== '' ? $this->p('active') : $this->p('aktif');
        if ($activeColumn !== null) {
            if ($active === '2') {
                $where[] = "{$activeColumn} = 1 AND calendar.exists_value = 1";
            } elseif ($active === '3') {
                $where[] = "{$activeColumn} = 0 AND calendar.exists_value = 1";
            } elseif ($active === '4') {
                $where[] = "{$activeColumn} = 1 AND calendar.exists_value = 0";
            } elseif ($active === '5') {
                $where[] = "{$activeColumn} = 0 AND calendar.exists_value = 0";
            }
        }

        $year = $this->pInt('year', $this->pInt('yil', 0));
        $missingYear = $this->p('missing_year') !== '' ? $this->p('missing_year') : $this->p('yilrw');
        if ($year > 0) {
            $where[] = $missingYear === '1' ? 'season.exists_value = 0' : 'season.exists_value = 1';
            $params[':yearStart'] = $year . '-01-01';
            $params[':yearEnd'] = $year . '-12-31';
        }

        // Tamamlanma: 1 = %100 olanlar, 0 = %100 olmayanlar (henuz hesaplanmamis villa da tamamlanmamis sayilir).
        $completion = $this->p('tamamlanma');
        $hasCompletionColumn = \App\Support\HomesCompletion::columnExists($pdo);
        $completionColumn = 'h.[' . \App\Support\HomesCompletion::COLUMN . ']';
        if ($completion === '1' || $completion === '0') {
            if (!$hasCompletionColumn) {
                throw new \App\Core\HttpException('Tamamlanma filtresi icin once setup calistirilmali.', 'SETUP_REQUIRED', 409);
            }
            $where[] = $completion === '1' ? "{$completionColumn} = 100" : "ISNULL({$completionColumn}, 0) < 100";
        }

        // SEO puani (homes.seo_yuzdesi, ilan SEO sekmesindeki genel puan): 1 = %100, 0 = %100 olmayan (hesaplanmamis dahil).
        $seo = $this->p('seo');
        $hasSeoColumn = \App\Support\HomesSeoScore::columnExists($pdo);
        $seoColumn = 'h.[' . \App\Support\HomesSeoScore::COLUMN . ']';
        // Sadece Bizde (homes.sadece_bizde); kolon yoksa 0
        $hasOnlyUsColumn = \App\Support\SchemaColumns::exists($pdo, 'dbo.homes', 'sadece_bizde');
        if ($seo === '1' || $seo === '0') {
            if (!$hasSeoColumn) {
                throw new \App\Core\HttpException('SEO filtresi icin once setup calistirilmali.', 'SETUP_REQUIRED', 409);
            }
            $where[] = $seo === '1' ? "{$seoColumn} = 100" : "ISNULL({$seoColumn}, 0) < 100";
        }

        $document = $this->p('document') !== '' ? $this->p('document') : $this->p('gavelBelge');
        // Sure tipi ayardaki kolondan okunur (gavel_duration_column); 1 suresiz, 2 sureli
        $durationSql = $this->gavelDurationSql('gavel');
        foreach ($this->stringList($document) as $documentFilter) {
            switch ($documentFilter) {
                case 'active':
                case 'aktif':
                    $where[] = 'ISNULL(gavel.gavel, 0) = 0';
                    break;
                case 'passive':
                case 'pasif':
                    $where[] = 'ISNULL(gavel.gavel, 0) = 1';
                    break;
                case 'document':
                case 'belge':
                    $where[] = "ISNULL(gavel.gavelBelgeNo, '') <> ''";
                    break;
                case 'document-no':
                case 'belge-no':
                    $where[] = "ISNULL(gavel.gavelBelgeNo, '') = ''";
                    break;
                // Belge sahibi bakanlik sorgusundan dolar; toplu guncellemede bulunamayan belgelerde bosaltilir
                case 'owner':
                case 'ok':
                    $where[] = "ISNULL(gavel.gavelBelgeSahip, '') <> ''";
                    break;
                case 'owner-no':
                case 'no':
                    $where[] = "ISNULL(gavel.gavelBelgeSahip, '') = ''";
                    break;
                // Belge Yonetimi sonuc listeleri. Basarili = belge no var + suresiz.
                case 'verified':
                    $where[] = "ISNULL(gavel.gavelBelgeNo, '') <> '' AND ISNULL({$durationSql}, 0) = 1";
                    break;
                // Hatali (bakanlikta bulunamadi) = belge no var + sureli + belge sahibi bos. Toplu guncelleme bu
                // belgelerde bakanlik alanlarini bosaltip sureli yapar; gecici belgelerde bakanlik verisi dolu kalir.
                case 'unverified':
                case 'not-found':
                    $where[] = "ISNULL(gavel.gavelBelgeNo, '') <> '' AND ISNULL({$durationSql}, 0) = 2"
                        . " AND ISNULL(gavel.gavelBelgeSahip, '') = ''";
                    break;
                case 'application':
                case 'basvuru':
                    $where[] = "ISNULL(gavel.gavelBasvuruNo, '') <> ''";
                    break;
                case 'application-no':
                case 'basvuru-no':
                    $where[] = "ISNULL(gavel.gavelBasvuruNo, '') = ''";
                    break;
                case 'temporary':
                case 'sureli':
                    $where[] = "ISNULL({$durationSql}, 0) = 2";
                    break;
                case 'permanent':
                case 'sureli-no':
                case 'suresiz':
                    $where[] = "ISNULL({$durationSql}, 0) = 1";
                    break;
                case 'unspecified':
                case 'sure-belirtilmemis':
                    $where[] = "ISNULL({$durationSql}, 0) NOT IN (1, 2)";
                    break;
            }
        }

        $whereSql = implode(' AND ', $where);
        $orderSql = $this->orderSql($this->pInt('sort', $this->pInt('sira', 0)), $hasTypeFilter);
        // Aranan deger sadece rakamsa id'si birebir tutan villa basa gelir (belge no / kod icinde gecenler sonra).
        if ($search !== '' && preg_match('/^\d{1,10}$/', trim($search)) === 1) {
            $orderSql = preg_replace('/^ORDER BY /', 'ORDER BY CASE WHEN h.id = :keywordExactOrder THEN 0 ELSE 1 END, ', $orderSql, 1);
            $params[':keywordExactOrder'] = (int) trim($search);
        }
        // Diger sitelerin villa adlari: sites tablosundaki her site (1 haric) icin baslik kolonu (baslik_s2 / config soneki)
        // homes'ta varsa doner (site_titles). Kolonu olmayan site listelenmez; frontend her site icin bir kolon acar.
        $extraTitleSites = $this->extraTitleSites($pdo);
        $extraTitleSql = '';
        foreach ($extraTitleSites as $extraSite) {
            $extraTitleSql .= "    ISNULL(CONVERT(nvarchar(500), h.[{$extraSite['column']}]), N'') AS site_title_{$extraSite['id']}, ";
        }

        $pagingSql = $allRows ? '' : 'OFFSET :offset ROWS FETCH NEXT :perPage ROWS ONLY';

        // Villa URL'si (homes.url / site sonekli url_s2): pasif yaparken yonlendirme modalinda eski link olarak gelir.
        $urlColumn = $this->siteColumn('url', $siteId);
        if ($urlColumn !== 'url' && !$this->homesColumnExists($pdo, $urlColumn)) {
            $urlColumn = 'url';
        }
        $urlSql = $this->homesColumnExists($pdo, $urlColumn) ? "ISNULL(CONVERT(nvarchar(1000), h.[{$urlColumn}]), N'')" : "N''";
        // Taslak: yeni ilan ekraninda olusturulup "Kaydet" ile tamamlanmamis ilan (homes.template = 1).
        $draftSql = $this->homesColumnExists($pdo, 'template') ? 'CAST(ISNULL(h.template, 0) AS int)' : 'CAST(0 AS int)';
        // Basvuru numarasi (kanun7464.gavelBasvuruNo); kolon olmayan sitelerde bos
        $basvuruSql = \App\Support\SchemaColumns::exists($pdo, 'dbo.kanun7464', 'gavelBasvuruNo') ? "ISNULL(CONVERT(nvarchar(200), gavel.gavelBasvuruNo), N'')" : "N''";

        $sql = " 
SELECT
    COUNT(*) OVER() AS totalCount,
    h.id,
    {$siteId} AS site_id,
    h.evkodu AS code,
    {$urlSql} AS url,
    {$draftSql} AS is_draft,
    " . $this->homeTitleSql('h', $titleColumn) . " AS title,
    {$titleColumn} AS gavel_title,
    " . $this->homeRealNameSql($pdo, 'h', $titleColumn) . " AS real_title,
    " . ($this->searchOrjAdEnabled() && $this->homesColumnExists($pdo, 'orj_ad') ? "ISNULL(CONVERT(nvarchar(500), h.orj_ad), N'')" : "N''") . " AS original_name,
{$extraTitleSql}    h.siralama AS sort_order, 
    {$imageColumn} AS image,
    h.yatak_odasi AS bedroom_count,
    h.kisi AS capacity,
    h.banyo AS bathroom_count,
    h.kazancorani AS earning_rate,
    {$depositColumn} AS deposit_percentage,
    ISNULL(own.owner_name, '') AS owner_name,
    ISNULL(own.owner_phone, '') AS owner_phone,
    ISNULL(h.bakimciad, '') AS caretaker_name,
    ISNULL(h.bakimcitel, '') AS caretaker_phone,
    {$whatsappColumn} AS whatsapp_group,
    " . $this->selectValue($activeColumn) . " AS active,
    " . $this->selectValue($showcaseColumn) . " AS showcase,
    " . $this->selectValue($favoriteColumn) . " AS favorite,
    " . $this->selectValue($opportunityColumn) . " AS opportunity,
    d.id AS region_id,
    {$regionTitleColumn} AS region_title,
    ISNULL(gavel.gavel, 0) AS document_passive,
    ISNULL(gavel.gavelBelgeNo, '') AS GavelBelgeNo,
    {$basvuruSql} AS GavelBasvuruNo,
    " . $this->gavelDurationSelectSql('gavel') . " AS document_duration,
    CASE WHEN ISNULL(gavel.gavel, 0) = 0 THEN 'default' ELSE '' END AS document_button_class,
    calendar.exists_value AS rental_calendar,
    calendar.auto_price_update AS rental_calendar_price_sync,
    season.exists_value AS has_season,
    price.min_price AS min_price,
    ISNULL(h.doviz, '') AS currency,
    " . ($hasCompletionColumn ? $completionColumn : 'CAST(NULL AS int)') . " AS completion_percent,
    " . ($hasSeoColumn ? $seoColumn : 'CAST(NULL AS int)') . " AS seo_percent,
    " . ($hasOnlyUsColumn ? 'CASE WHEN ISNULL(TRY_CONVERT(int, h.sadece_bizde), 0) = 1 THEN 1 ELSE 0 END' : '0') . " AS only_us
FROM homes h
INNER JOIN destinations d ON d.id = h.emlak_bolgesi
LEFT JOIN kanun7464 gavel ON gavel.homeId = h.id
OUTER APPLY (
    -- Ev sahibi: ilan detayindaki gibi homes.evsahibi -> kullanici
    SELECT TOP 1
        LTRIM(RTRIM(CONCAT(ISNULL(k.ad, ''), ' ', ISNULL(k.soyad, '')))) AS owner_name,
        LTRIM(RTRIM(ISNULL(CONVERT(nvarchar(255), k.tel), ''))) AS owner_phone
    FROM kullanici k
    WHERE k.id = h.evsahibi
) AS own
OUTER APPLY (
    SELECT MIN(fiyat_num.v) AS min_price
    FROM sezonlar sz
    CROSS APPLY (SELECT TRY_CONVERT(float, REPLACE(CONVERT(varchar(64), sz.fiyat), ',', '.')) AS v) AS fiyat_num
    WHERE sz.islem_id = h.id AND sz.islem = 'emlak' AND sz.site = 1 AND fiyat_num.v > 0
) AS price
OUTER APPLY (
    SELECT
        CASE WHEN EXISTS (SELECT 1 FROM KiralamaTakvimi.CalendarHomes ch WHERE ch.homesId = h.id) THEN 1 ELSE 0 END AS exists_value,
        0 AS auto_price_update
) AS calendar
OUTER APPLY (
    SELECT CASE WHEN EXISTS (
        SELECT 1 FROM sonDakika sd
        WHERE sd.islem_id = h.id
          AND sd.site = {$siteId}
          AND COALESCE(TRY_CONVERT(date, sd.tarih2, 103), TRY_CONVERT(date, sd.tarih2, 23)) >= CONVERT(date, GETDATE(), 103)
    ) THEN 1 ELSE 0 END AS exists_value
) AS lastMinute
OUTER APPLY (
    SELECT CASE WHEN " . ($year > 0 ? "EXISTS (
        SELECT 1 FROM sezonlar sz
        WHERE sz.islem = 'emlak'
          AND sz.islem_id = h.id
          AND sz.site = {$siteId}
          AND COALESCE(TRY_CONVERT(date, sz.tarih1, 103), TRY_CONVERT(date, sz.tarih1, 23)) <= CONVERT(date, :yearEnd, 23)
          AND COALESCE(TRY_CONVERT(date, sz.tarih2, 103), TRY_CONVERT(date, sz.tarih2, 23)) >= CONVERT(date, :yearStart, 23)
    )" : '0 = 1') . " THEN 1 ELSE 0 END AS exists_value
) AS season
WHERE {$whereSql}
{$orderSql}
{$pagingSql}";

        \App\Support\ServerTiming::add('prep', $requestStartedAt);
        $sqlStartedAt = microtime(true);
        $fromPos = strpos($sql, "\nFROM homes h\n");
        $wherePos = strpos($sql, "\nWHERE {$whereSql}\n");
        if (!$allRows && $fromPos !== false && $wherePos !== false) {
            // Iki asama: once filtre + siralama + sayfalama ile sadece sayfadaki id'ler ve toplam sayi alinir;
            // fiyat, ev sahibi gibi sadece listede gosterilen alt sorgular yalnizca bu id'ler icin hesaplanir.
            // Tek sorguda COUNT(*) OVER() yuzunden bu alt sorgular filtreye uyan tum villalar icin calisiyordu.
            $fromSql = substr($sql, $fromPos, $wherePos - $fromPos);
            $idSql = "SELECT COUNT(*) OVER() AS totalCount, h.id{$fromSql}\nWHERE {$whereSql}\n{$orderSql}\n{$pagingSql}";
            $stmt = $pdo->prepare($idSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->bindValue(':perPage', $perPage, PDO::PARAM_INT);
            $stmt->execute();
            $idRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            \App\Support\ServerTiming::add('sqlids', $sqlStartedAt);
            $total = $idRows ? (int) $idRows[0]['totalCount'] : 0;
            $rows = [];
            if ($idRows) {
                $detailStartedAt = microtime(true);
                $ids = implode(',', array_map(function (array $r): int {
                    return (int) $r['id'];
                }, $idRows));
                $detailSql = substr($sql, 0, $wherePos) . "\nWHERE h.id IN ({$ids})\n{$orderSql}";
                $stmt = $pdo->prepare($detailSql);
                foreach ($params as $key => $value) {
                    // Detay sorgusunda sadece geceren parametreler (yil, tam eslesme siralamasi) baglanir
                    if (strpos($detailSql, $key) !== false) {
                        $stmt->bindValue($key, $value);
                    }
                }
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                \App\Support\ServerTiming::add('sqldetail', $detailStartedAt);
            }
        } else {
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            if (!$allRows) {
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->bindValue(':perPage', $perPage, PDO::PARAM_INT);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = $rows ? (int) $rows[0]['totalCount'] : 0;
        }
        \App\Support\ServerTiming::add('sql', $sqlStartedAt);
        $postStartedAt = microtime(true);
        $cdnBase = (defined('Cdn') ? (string) constant('Cdn') : '') . '/uploads/small/';
        // Sitenin adresi (villa linki icin), HomesController ile ayni kaynak
        $siteDomain = $this->siteDomain($pdo, $siteId);
        foreach ($rows as &$row) {
            unset($row['totalCount']);
            // Villa fotograflari yeni CDN yapisinda (/property/small/{villaId}/)
            $imageList = $this->imageList((string) $row['image'], Uploads::homeImageBase($cdnBase, (int) ($row['id'] ?? 0), $this->app));
            $row = [
                'id' => (int) $row['id'],
                'site_id' => (int) $row['site_id'],
                'code' => (string) $row['code'],
                'url' => (string) ($row['url'] ?? ''),
                // Villanin sitedeki tam linki (ilan listesindeki link butonu)
                'link' => $this->homeLink((string) ($row['url'] ?? ''), $siteDomain),
                'is_draft' => (int) ($row['is_draft'] ?? 0) === 1,
                'title' => (string) $row['title'],
                'gavel_title' => (string) $row['gavel_title'],
                // Gercek villa adi (config homes_code: kod basliga yaziliyorsa homes.villaadi)
                'real_title' => (string) ($row['real_title'] ?? ''),
                // "Villanin Orjinal Adi" (homes.orj_ad); kolon yoksa veya config homes_orj_ad_reference_link aciksa (alan referans linki) bos
                'original_name' => trim((string) ($row['original_name'] ?? '')),
                // Diger sitelerdeki villa adlari [{id, name, title}]; baslik kolonu olmayan site yok
                'site_titles' => array_map(function (array $extraSite) use ($row): array {
                    return [
                        'id' => $extraSite['id'],
                        'name' => $extraSite['name'],
                        'short' => $extraSite['short'],
                        'title' => (string) ($row['site_title_' . $extraSite['id']] ?? ''),
                    ];
                }, $extraTitleSites),
                'sort_order' => (int) $row['sort_order'],
                'image' => $imageList ? $imageList[0] : '',
                'image_list' => $imageList,
                'bedroom_count' => (int) $row['bedroom_count'],
                'capacity' => (int) $row['capacity'],
                'bathroom_count' => (int) $row['bathroom_count'],
                'earning_rate' => (float) $row['earning_rate'],
                'deposit_percentage' => (float) $row['deposit_percentage'],
                // Ev sahibi (homes.evsahibi -> kullanici; ilan detayindaki ev sahibi bilgisi)
                'owner_name' => (string) ($row['owner_name'] ?? ''),
                'owner_phone' => (string) ($row['owner_phone'] ?? ''),
                'caretaker_name' => (string) $row['caretaker_name'],
                'caretaker_phone' => (string) $row['caretaker_phone'],
                'whatsapp_group' => (string) $row['whatsapp_group'],
                'active' => (bool) $row['active'],
                'showcase' => (bool) $row['showcase'],
                'favorite' => (bool) $row['favorite'],
                'opportunity' => (bool) $row['opportunity'],
                'region_id' => (int) $row['region_id'],
                'region_title' => (string) $row['region_title'],
                'document_passive' => (int) $row['document_passive'],
                'GavelBelgeNo' => (string) $row['GavelBelgeNo'],
                // Basvuru numarasi (belge no yokken listede gosterilir)
                'GavelBasvuruNo' => trim((string) ($row['GavelBasvuruNo'] ?? '')),
                // Belge sure tipi: 1 suresiz, 2 sureli, null bilinmiyor
                'document_duration' => $row['document_duration'] !== null ? (int) $row['document_duration'] : null,
                'document_button_class' => (string) $row['document_button_class'],
                'rental_calendar' => (int) $row['rental_calendar'],
                'rental_calendar_price_sync' => (int) $row['rental_calendar_price_sync'],
                'has_season' => (int) $row['has_season'],
                // En dusuk sezon fiyati (villanin kendi para biriminde); sezon yoksa null.
                'min_price' => $row['min_price'] !== null ? (int) round((float) $row['min_price']) : null,
                'currency' => (string) $row['currency'],
                // Tamamlanma yuzdesi (homes.tamamlanma_yuzdesi); setup calismadiysa veya hesaplanmadiysa null.
                'completion_percent' => $row['completion_percent'] !== null ? (int) $row['completion_percent'] : null,
                // SEO puani (homes.seo_yuzdesi); setup calismadiysa veya hesaplanmadiysa null.
                'seo_percent' => $row['seo_percent'] !== null ? (int) $row['seo_percent'] : null,
                // Sadece Bizde (homes.sadece_bizde = 1)
                'only_us' => (int) $row['only_us'],
            ];
        }
        unset($row);

        \App\Support\ServerTiming::add('post', $postStartedAt);
        $this->response->success([
            'items' => $rows,
            'meta' => [
                'total' => $total,
                'site' => $currentSite,
                // Listelenen sitenin kisa adi (sites.kisa; "VV"): Ad kolonu basligi "VV Villa Adı"
                'site_short' => $this->siteShort($pdo, $siteId),
                'page' => $page,
                'per_page' => $allRows ? $total : $perPage,
                'total_pages' => $allRows ? 1 : (int) ceil($total / max(1, $perPage)),
                'count' => count($rows),
            ],
        ]);
    }

    /**
     * Villayi siler: sadece homes tablosundaki ilgili satir (bagli tablolara dokunulmaz).
     *   POST /backend-api/homes-management/delete  { "id": 123 }
     *
     * @Post("delete")
     */
    public function delete(): void
    {
        $id = (int) $this->request->query('id', $this->request->input('id', 0));
        if ($id <= 0) {
            throw new \App\Core\HttpException('Gecersiz villa ID', 'VALIDATION', 422);
        }

        // isDeleted kolonu varsa (setup) satir silinmez: isDeleted = 1 + butun sitelerde aktif = 0, listelerde gorunmez.
        $pdo = $this->db->pdo();
        // Silinen villanin URL'si butun sitelerde 410 Gone olur; URL silmeden once okunur.
        $goneLinks = $this->goneRedirect(function () use ($pdo, $id) {
            return \App\Support\HomesGoneRedirect::collect($pdo, [$id]);
        });
        $markGone = function () use ($pdo, $goneLinks) {
            // null: config kapali; ['error' => ...]: URL okunamadi (sonuc oldugu gibi doner).
            return is_array($goneLinks) && !isset($goneLinks['error'])
                ? $this->goneRedirect(function () use ($pdo, $goneLinks) {
                    return \App\Support\HomesGoneRedirect::markGone($pdo, $goneLinks);
                })
                : $goneLinks;
        };

        if (\App\Support\HomesSoftDelete::hasColumn($pdo)) {
            $exists = $pdo->prepare('SELECT COUNT(1) FROM dbo.homes WHERE id = :id AND ISNULL([isDeleted], 0) = 0');
            $exists->execute([':id' => $id]);
            if ((int) $exists->fetchColumn() === 0) {
                throw new \App\Core\HttpException('Villa bulunamadi.', 'NOT_FOUND', 404);
            }
            \App\Support\HomesSoftDelete::delete($pdo, [$id]);
            $this->response->success(['message' => 'Villa silindi', 'id' => $id, 'soft' => true, 'redirect' => $markGone()]);
            return;
        }

        $stmt = $pdo->prepare('DELETE FROM dbo.homes WHERE id = :id');
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() === 0) {
            throw new \App\Core\HttpException('Villa bulunamadi.', 'NOT_FOUND', 404);
        }

        $this->response->success(['message' => 'Villa silindi', 'id' => $id, 'redirect' => $markGone()]);
    }

    /**
     * Villayi aktif/pasif yapar: listedeki ile ayni site kolonu (aktif, aktif_s2...) guncellenir.
     *   POST /backend-api/homes-management/active  { "id": 123, "active": 1, "site": 2 }
     *
     * @Post("active")
     */
    public function active(): void
    {
        $id = (int) $this->request->query('id', $this->request->input('id', 0));
        if ($id <= 0) {
            throw new \App\Core\HttpException('Gecersiz villa ID', 'VALIDATION', 422);
        }

        $raw = $this->request->query('active', $this->request->input('active', null));
        if ($raw === null || $raw === '') {
            throw new \App\Core\HttpException('active alani zorunludur.', 'VALIDATION', 422);
        }
        $active = in_array(strtolower(trim((string) $raw)), ['1', 'true', 'on', 'yes'], true) ? 1 : 0;

        $siteId = (int) $this->request->query('site', $this->request->input('site', 0));
        if ($siteId <= 0) {
            $siteId = $this->siteId();
        }
        $column = $this->siteColumn('aktif', $siteId);

        $pdo = $this->db->pdo();

        // Varlik kontrolu ayri yapilir: homes'ta trigger varsa UPDATE'in rowCount() degeri guvenilmez.
        $exists = $pdo->prepare('SELECT COUNT(1) FROM dbo.homes WHERE id = :id');
        $exists->execute([':id' => $id]);
        if ((int) $exists->fetchColumn() === 0) {
            throw new \App\Core\HttpException('Villa bulunamadi.', 'NOT_FOUND', 404);
        }

        // Panelde secilen yonlendirme once dogrulanir: hataliysa villa pasife alinmaz.
        $redirectInput = $this->request->input('redirect', null);
        // { items: [...] } satir satir (eski -> yeni, her biri kendi turu); tek nesne (eski bicim) de kabul edilir.
        $customRedirect = null;
        if ($active === 0 && is_array($redirectInput)) {
            $customRedirect = isset($redirectInput['items'])
                ? \App\Support\HomesGoneRedirect::parseCustomItems($redirectInput)
                : [\App\Support\HomesGoneRedirect::parseCustom($redirectInput)];
        }

        $stmt = $pdo->prepare("UPDATE dbo.homes SET [{$column}] = :active WHERE id = :id");
        $stmt->execute([':active' => $active, ':id' => $id]);

        $check = $pdo->prepare("SELECT ISNULL([{$column}], 0) FROM dbo.homes WHERE id = :id");
        $check->execute([':id' => $id]);
        $current = (int) $check->fetchColumn();

        // Pasif: o sitede villanin URL'si 410 Gone; tekrar aktif: o URL'deki 410 kaldirilir.
        // redirect (sadece pasifte): gonderilmezse otomatik 410; "none" -> yonlendirme yazilmaz;
        // { turu_code, yeni_link?, turu_string?, eski_linkler? } -> panelde secilen yonlendirme (ayni link varsa guncellenir).
        if ($current === 0 && ($redirectInput === 'none' || $redirectInput === false || $redirectInput === 0 || $redirectInput === '0')) {
            $redirect = null;
        } elseif ($current === 0 && $customRedirect !== null) {
            // Kullanicinin sectigi yonlendirme config kapali olsa da yazilir (hata villa durumunu bozmaz).
            try {
                $redirect = 0;
                foreach ($customRedirect as $item) {
                    $collected = $item['links'] !== null
                        ? \App\Support\HomesGoneRedirect::collectLinks($pdo, $siteId, $item['links'])
                        : \App\Support\HomesGoneRedirect::collect($pdo, [$id], [$siteId]);
                    $redirect += \App\Support\HomesGoneRedirect::upsert($pdo, $collected, $item['code'], $item['text'], $item['target']);
                }
            } catch (\Throwable $e) {
                error_log('HomesGoneRedirect: ' . $e->getMessage());
                $redirect = ['error' => $e->getMessage()];
            }
        } else {
            $redirect = $this->goneRedirect(function () use ($pdo, $id, $siteId, $current) {
                $collected = \App\Support\HomesGoneRedirect::collect($pdo, [$id], [$siteId]);
                return $current === 1
                    ? \App\Support\HomesGoneRedirect::clearGone($pdo, $collected)
                    : \App\Support\HomesGoneRedirect::markGone($pdo, $collected);
            });
        }

        $this->response->success(['id' => $id, 'active' => $current === 1, 'column' => $column, 'redirect' => $redirect]);
    }

    private function homesColumnExists(PDO $pdo, string $column): bool
    {
        return \App\Support\SchemaColumns::exists($pdo, 'dbo.homes', $column);
    }

    /**
     * 410 Gone yonlendirme adimi (config homes_gone_redirect kapaliysa calismaz). Hata asil islemi bozmaz:
     * sonuc dondurulur, hata olursa ['error' => mesaj], kapaliysa null.
     *
     * @return mixed
     */
    private function goneRedirect(callable $fn)
    {
        if (!\App\Support\HomesGoneRedirect::enabled($this->app)) {
            return null;
        }
        try {
            return $fn();
        } catch (\Throwable $e) {
            error_log('HomesGoneRedirect: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    private function p(string $key, string $default = ''): string
    {
        $val = $_GET[$key] ?? ($_POST[$key] ?? $default);

        return trim((string) $val);
    }

    private function pInt(string $key, int $default = 0): int
    {
        $v = $this->p($key);

        return is_numeric($v) ? (int) $v : $default;
    }

    private function siteId(): int
    {
        if ($this->p('site') !== '') {
            return max(1, (int) $this->p('site'));
        }

        return defined('PRICE_SITE') ? max(1, (int) constant('PRICE_SITE')) : 1;
    }

    // Sitenin adresi (HomesController ile ayni): site 1 Domain sabiti, diger siteler config homes_site_domain_queries
    // (orn. 2 => 'SELECT TOP 1 domain FROM genel_s2'); bos/hatali ise Domain.
    private function siteDomain(PDO $pdo, int $siteId): string
    {
        $fallback = defined('Domain') ? $this->normalizeDomain((string) constant('Domain')) : '';
        if ($siteId <= 1) {
            return $fallback;
        }

        $queries = $this->app['homes_site_domain_queries'] ?? [];
        $query = is_array($queries)
            ? (string) ($queries[$siteId] ?? ($queries[(string) $siteId] ?? ''))
            : '';
        if (trim($query) === '') {
            return $fallback;
        }

        try {
            $row = $pdo->query($query)->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return $fallback;
        }
        if (!is_array($row)) {
            return $fallback;
        }

        $domain = trim((string) ($row['domain'] ?? reset($row)));

        return $domain !== '' ? $this->normalizeDomain($domain) : $fallback;
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = rtrim(trim($domain), '/');
        if ($domain !== '' && preg_match('#^https?://#i', $domain) !== 1) {
            $domain = 'https://' . $domain;
        }

        return $domain;
    }

    // Villa url'si (homes.url) -> sitedeki tam link; zaten tam adresse aynen doner.
    private function homeLink(string $url, string $domain): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        return $domain !== '' ? $domain . '/' . ltrim($url, '/') : '';
    }

    private function siteSuffix(int $siteId): string
    {
        if ($siteId === 1) {
            return '';
        }

        $suffixes = $this->app['site_column_suffixes'] ?? [];
        if (is_array($suffixes)) {
            if (array_key_exists($siteId, $suffixes)) {
                return $this->safeSuffix((string) $suffixes[$siteId]);
            }
            if (array_key_exists((string) $siteId, $suffixes)) {
                return $this->safeSuffix((string) $suffixes[(string) $siteId]);
            }
        }

        global $SITES;
        if (isset($SITES[$siteId]) && is_array($SITES[$siteId]) && isset($SITES[$siteId]['dbtable'])) {
            return $this->safeSuffix((string) $SITES[$siteId]['dbtable']);
        }

        return $siteId > 1 ? '_s' . $siteId : '';
    }

    private function siteColumn(string $baseColumn, int $siteId): string
    {
        return $baseColumn . $this->fieldSuffix($baseColumn, $siteId);
    }

    private function fieldSuffix(string $baseColumn, int $siteId): string
    {
        $fieldSuffixes = $this->app['site_field_suffixes'] ?? [];
        if (is_array($fieldSuffixes)) {
            $siteFields = $fieldSuffixes[$siteId] ?? ($fieldSuffixes[(string) $siteId] ?? null);
            if (is_array($siteFields) && array_key_exists($baseColumn, $siteFields)) {
                return $this->safeSuffix((string) $siteFields[$baseColumn]);
            }
        }

        return $this->siteSuffix($siteId);
    }

    private function safeSuffix(string $suffix): string
    {
        return $suffix === '' || preg_match('/^_[A-Za-z0-9]+$/', $suffix) === 1 ? $suffix : '';
    }

    /**
     * @return array{id:int,isim:string}
     */
    /**
     * Site 1 disindaki siteler ve homes'taki baslik kolonlari; kolonu olmayan site atlanir.
     *
     * @return array<int,array{id:int,name:string,short:string,column:string}>
     */
    private function extraTitleSites(PDO $pdo): array
    {
        try {
            $rows = $pdo->query('SELECT id, site, kisa FROM sites WHERE id > 1 ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 1) {
                continue;
            }
            $column = $this->siteColumn('baslik', $id);
            if ($column === 'baslik' || preg_match('/^[A-Za-z0-9_]+$/', $column) !== 1) {
                continue;
            }
            $exists = \App\Support\SchemaColumns::exists($pdo, 'dbo.homes', $column) ? 1 : 0;
            if ($exists !== 1) {
                continue;
            }
            $name = $this->siteDisplayName((string) ($row['site'] ?? ''));
            // short: sites.kisa (kolon basliginda "co.uk Villa Adı" gibi kullanilir)
            $result[] = ['id' => $id, 'name' => $name !== '' ? $name : 'Site ' . $id, 'short' => trim((string) ($row['kisa'] ?? '')), 'column' => $column];
        }

        return $result;
    }

    private function siteShort(PDO $pdo, int $siteId): string
    {
        try {
            $stmt = $pdo->prepare('SELECT TOP 1 kisa FROM sites WHERE id = :id');
            $stmt->execute([':id' => $siteId]);

            return trim((string) $stmt->fetchColumn());
        } catch (\PDOException $e) {
            return '';
        }
    }

    private function siteInfo(PDO $pdo, int $siteId): array
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT TOP 1 id, site
                 FROM tip
                 WHERE id = :site"
            );
            $stmt->bindValue(':site', $siteId, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();
        } catch (\PDOException $e) {
            $row = false;
        }

        $name = is_array($row) ? $this->siteDisplayName((string) $row['site']) : '';
        if ($name === '') {
            $name = 'Site ' . $siteId;
        }

        return [
            'id' => $siteId,
            'isim' => $name,
        ];
    }

    private function siteDisplayName(string $site): string
    {
        $parts = explode(',', $site, 2);

        return trim($parts[0]);
    }

    /**
     * Ilan aramasinda ve listede "Villanin Orjinal Adi" (homes.orj_ad) kullanilir mi.
     * Config homes_orj_ad_reference_link aciksa alan referans linkidir: kullanilmaz.
     */
    private function searchOrjAdEnabled(): bool
    {
        return empty($this->app['homes_orj_ad_reference_link']);
    }

    /**
     * @param array<int,string> $where
     * @param array<int,string> $extraColumns
     */
    private function addTextSearchFilter(
        array &$where,
        array &$params,
        string $titleColumn,
        string $value,
        string $prefix,
        bool $includeExactId,
        string $whatsappColumn = '',
        array $extraColumns = []
    ): void {
        $tokens = preg_split('/\s+/', $value) ?: [];
        $tokens = array_values(array_filter(array_map('trim', $tokens), function ($token) {
            return $token !== '';
        }));

        if (!$tokens) {
            return;
        }

        // Turkce harmanlamada (Turkish_CI) "i" ile "I", "ı" ile "i" farkli sayilir: "sinem" araninca "VILLA SINEM" gelmez.
        // Kolon ve aranan metin ı/İ -> i/I cevrilip Latin1_General_CI_AI ile karsilastirilir (buyuk/kucuk ve s/ş, c/ç... farki yok).
        $ci = function (string $expr): string {
            return "REPLACE(REPLACE(CONVERT(nvarchar(max), {$expr}) COLLATE Latin1_General_CI_AI, N'ı', N'i'), N'İ', N'I')";
        };
        $value = strtr($value, ['ı' => 'i', 'İ' => 'I']);
        $tokens = array_map(function (string $token): string {
            return strtr($token, ['ı' => 'i', 'İ' => 'I']);
        }, $tokens);

        $parts = [];
        $titleNoSpace = "REPLACE(REPLACE(REPLACE(REPLACE({$titleColumn}, CHAR(9), ''), CHAR(10), ''), CHAR(13), ''), ' ', '')";
        $codeNoSpace = "REPLACE(REPLACE(REPLACE(REPLACE(h.evkodu, CHAR(9), ''), CHAR(10), ''), CHAR(13), ''), ' ', '')";
        $documentNo = "ISNULL(gavel.gavelBelgeNo, '')";
        $documentNoSpace = "REPLACE(REPLACE(REPLACE(REPLACE({$documentNo}, CHAR(9), ''), CHAR(10), ''), CHAR(13), ''), ' ', '')";
        $valueNoSpace = preg_replace('/\s+/', '', $value) ?? '';
        foreach ($tokens as $index => $token) {
            $titleParam = ':' . $prefix . 'Name' . $index;
            $codeParam = ':' . $prefix . 'Code' . $index;
            $documentParam = ':' . $prefix . 'DocumentNo' . $index;
            $tokenClauses = [
                $ci($titleColumn) . " LIKE {$titleParam}",
                $ci('h.evkodu') . " LIKE {$codeParam}",
                $ci($documentNo) . " LIKE {$documentParam}",
            ];
            $params[$titleParam] = '%' . $token . '%';
            $params[$codeParam] = '%' . $token . '%';
            $params[$documentParam] = '%' . $token . '%';
            foreach ($extraColumns as $colIndex => $extraColumn) {
                $extraParam = ':' . $prefix . 'Extra' . $colIndex . '_' . $index;
                $tokenClauses[] = $ci($extraColumn) . " LIKE {$extraParam}";
                $params[$extraParam] = '%' . $token . '%';
            }
            if ($whatsappColumn !== '') {
                $whatsappParam = ':' . $prefix . 'Whatsapp' . $index;
                $tokenClauses[] = $ci($whatsappColumn) . " LIKE {$whatsappParam}";
                $params[$whatsappParam] = '%' . $token . '%';
            }
            $parts[] = '(' . implode(' OR ', $tokenClauses) . ')';
        }

        $textWhere = '(' . implode(' AND ', $parts) . ')';
        if ($valueNoSpace !== '') {
            $titleNoSpaceParam = ':' . $prefix . 'TitleNoSpace';
            $codeNoSpaceParam = ':' . $prefix . 'CodeNoSpace';
            $documentNoSpaceParam = ':' . $prefix . 'DocumentNoSpace';
            $noSpaceClauses = [
                $ci($titleNoSpace) . " LIKE {$titleNoSpaceParam}",
                $ci($codeNoSpace) . " LIKE {$codeNoSpaceParam}",
                $ci($documentNoSpace) . " LIKE {$documentNoSpaceParam}",
            ];
            $params[$titleNoSpaceParam] = '%' . $valueNoSpace . '%';
            $params[$codeNoSpaceParam] = '%' . $valueNoSpace . '%';
            $params[$documentNoSpaceParam] = '%' . $valueNoSpace . '%';
            foreach ($extraColumns as $colIndex => $extraColumn) {
                $extraNoSpace = "REPLACE(REPLACE(REPLACE(REPLACE({$extraColumn}, CHAR(9), ''), CHAR(10), ''), CHAR(13), ''), ' ', '')";
                $extraNoSpaceParam = ':' . $prefix . 'ExtraNoSpace' . $colIndex;
                $noSpaceClauses[] = $ci($extraNoSpace) . " LIKE {$extraNoSpaceParam}";
                $params[$extraNoSpaceParam] = '%' . $valueNoSpace . '%';
            }
            $textWhere = "({$textWhere} OR " . implode(' OR ', $noSpaceClauses) . ')';
        }
        if ($includeExactId) {
            $exactIdParam = ':' . $prefix . 'Exact';
            $where[] = "({$textWhere} OR CONVERT(varchar(32), h.id) = {$exactIdParam})";
            $params[$exactIdParam] = $value;
            return;
        }

        $where[] = $textWhere;
    }

    /**
     * @param array<int,string> $where
     */
    private function addBinaryFilter(array &$where, ?string $column, string $englishKey, string $legacyKey): void
    {
        if ($column === null) {
            return;
        }

        $value = $this->p($englishKey) !== '' ? $this->p($englishKey) : $this->p($legacyKey);
        if ($value === '1' || $value === '0') {
            $where[] = "{$column} = " . (int) $value;
        }
    }

    private function selectValue(?string $column): string
    {
        return $column !== null ? 'ISNULL(' . $column . ', 0)' : '0';
    }

    /**
     * @return array<int,string>
     */
    private function imageList(string $images, string $cdnBase): array
    {
        if ($images === '') {
            return [];
        }

        $list = array_values(array_filter(array_map('trim', explode(',', $images))));

        return array_map(function (string $image) use ($cdnBase): string {
            return $cdnBase . $image;
        }, $list);
    }

    private function safeIntList(string $s): string
    {
        $parts = array_map('intval', explode(',', $s));
        $valid = array_filter($parts, function ($v) {
            return $v > 0;
        });

        return $valid ? implode(',', $valid) : '';
    }

    /**
     * @return array<int,string>
     */
    private function stringList(string $s): array
    {
        if ($s === '') {
            return [];
        }

        return array_values(array_filter(array_map(function ($item) {
            return strtolower(trim($item));
        }, explode(',', $s))));
    }

    private function orderSql(int $sort, bool $hasTypeFilter): string
    {
        switch ($sort) {
            case 1:
                return "ORDER BY h.siralama ASC, h.id ASC";
            case 2:
                return "ORDER BY h.siralama DESC, h.id ASC";
            case 3:
                return "ORDER BY h.id ASC";
            case 4:
                return "ORDER BY h.id DESC";
            default:
                return $hasTypeFilter ? "ORDER BY h.siralama ASC, h.id ASC" : "ORDER BY h.id ASC";
        }
    }
}
