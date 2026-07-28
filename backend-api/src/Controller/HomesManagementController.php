<?php

declare(strict_types=1);

namespace App\Controller;

use PDO;

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
     * @query keyword string Search by title, code or id
     * @query title string Search by title or code
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
     * @query sort int 1/2 order, 3/4 id
     * @query site int Site id
     */
    public function index(): void
    {
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

        $pageRaw = $this->p('page') !== '' ? $this->p('page') : $this->p('sayfa', '1');
        $allRows = strtolower($pageRaw) === 'tumu' || strtolower($this->p('per_page')) === 'tumu' || $this->p('per_page') === '0';
        $page = $allRows ? 1 : max(1, (int) $pageRaw);
        $perPage = $allRows ? 0 : max(1, $this->pInt('per_page', 50));
        $offset = $allRows ? 0 : (($page - 1) * $perPage);

        $where = ['1 = 1'];
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
        if ($title !== '') {
            $this->addTextSearchFilter($where, $params, $titleColumn, $title, 'title', false);
        }
        if ($search !== '') {
            $this->addTextSearchFilter($where, $params, $titleColumn, $search, 'keyword', true);
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

        $document = $this->p('document') !== '' ? $this->p('document') : $this->p('gavelBelge');
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
                    $where[] = 'ISNULL(gavel.GavelBelgeSureli, 0) = 1';
                    break;
                case 'permanent':
                case 'sureli-no':
                    $where[] = 'ISNULL(gavel.GavelBelgeSureli, 0) = 0';
                    break;
            }
        }

        $whereSql = implode(' AND ', $where);
        $orderSql = $this->orderSql($this->pInt('sort', $this->pInt('sira', 0)), $hasTypeFilter);
        $pagingSql = $allRows ? '' : 'OFFSET :offset ROWS FETCH NEXT :perPage ROWS ONLY';

        $sql = "
SELECT
    COUNT(*) OVER() AS totalCount,
    h.id,
    {$siteId} AS site_id,
    h.evkodu AS code,
    {$titleColumn} AS title,
    {$titleColumn} AS gavel_title,
    h.siralama AS sort_order,
    {$imageColumn} AS image,
    h.yatak_odasi AS bedroom_count,
    h.kisi AS capacity,
    h.banyo AS bathroom_count,
    h.kazancorani AS earning_rate,
    {$depositColumn} AS deposit_percentage,
    ISNULL(h.bakimciad, '') AS caretaker_name,
    ISNULL(h.bakimcitel, '') AS caretaker_phone,
    " . $this->selectValue($activeColumn) . " AS active,
    " . $this->selectValue($showcaseColumn) . " AS showcase,
    " . $this->selectValue($favoriteColumn) . " AS favorite,
    " . $this->selectValue($opportunityColumn) . " AS opportunity,
    d.id AS region_id,
    {$regionTitleColumn} AS region_title,
    ISNULL(gavel.gavel, 0) AS document_passive,
    CASE WHEN ISNULL(gavel.gavel, 0) = 0 THEN 'default' ELSE '' END AS document_button_class,
    calendar.exists_value AS rental_calendar,
    calendar.auto_price_update AS rental_calendar_price_sync,
    season.exists_value AS has_season
FROM homes h
INNER JOIN destinations d ON d.id = h.emlak_bolgesi
LEFT JOIN kanun7464 gavel ON gavel.homeId = h.id
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
          AND CONVERT(date, sd.tarih2, 103) >= CONVERT(date, GETDATE(), 103)
    ) THEN 1 ELSE 0 END AS exists_value
) AS lastMinute
OUTER APPLY (
    SELECT CASE WHEN " . ($year > 0 ? "EXISTS (
        SELECT 1 FROM sezonlar sz
        WHERE sz.islem = 'emlak'
          AND sz.islem_id = h.id
          AND sz.site = {$siteId}
          AND CONVERT(date, sz.tarih1, 103) <= CONVERT(date, :yearEnd, 23)
          AND CONVERT(date, sz.tarih2, 103) >= CONVERT(date, :yearStart, 23)
    )" : '0 = 1') . " THEN 1 ELSE 0 END AS exists_value
) AS season
WHERE {$whereSql}
{$orderSql}
{$pagingSql}";

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
        $cdnBase = (defined('Cdn') ? (string) constant('Cdn') : '') . '/uploads/small/';
        foreach ($rows as &$row) {
            unset($row['totalCount']);
            $imageList = $this->imageList((string) $row['image'], $cdnBase);
            $row = [
                'id' => (int) $row['id'],
                'site_id' => (int) $row['site_id'],
                'code' => (string) $row['code'],
                'title' => (string) $row['title'],
                'gavel_title' => (string) $row['gavel_title'],
                'sort_order' => (int) $row['sort_order'],
                'image' => $imageList ? $imageList[0] : '',
                'image_list' => $imageList,
                'bedroom_count' => (int) $row['bedroom_count'],
                'capacity' => (int) $row['capacity'],
                'bathroom_count' => (int) $row['bathroom_count'],
                'earning_rate' => (float) $row['earning_rate'],
                'deposit_percentage' => (float) $row['deposit_percentage'],
                'caretaker_name' => (string) $row['caretaker_name'],
                'caretaker_phone' => (string) $row['caretaker_phone'],
                'active' => (bool) $row['active'],
                'showcase' => (bool) $row['showcase'],
                'favorite' => (bool) $row['favorite'],
                'opportunity' => (bool) $row['opportunity'],
                'region_id' => (int) $row['region_id'],
                'region_title' => (string) $row['region_title'],
                'document_passive' => (int) $row['document_passive'],
                'document_button_class' => (string) $row['document_button_class'],
                'rental_calendar' => (int) $row['rental_calendar'],
                'rental_calendar_price_sync' => (int) $row['rental_calendar_price_sync'],
                'has_season' => (int) $row['has_season'],
            ];
        }
        unset($row);

        $this->response->success([
            'items' => $rows,
            'meta' => [
                'total' => $total,
                'site' => $currentSite,
                'page' => $page,
                'per_page' => $allRows ? $total : $perPage,
                'total_pages' => $allRows ? 1 : (int) ceil($total / max(1, $perPage)),
                'count' => count($rows),
            ],
        ]);
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
     * @param array<int,string> $where
     */
    private function addTextSearchFilter(
        array &$where,
        array &$params,
        string $titleColumn,
        string $value,
        string $prefix,
        bool $includeExactId
    ): void {
        $tokens = preg_split('/\s+/', $value) ?: [];
        $tokens = array_values(array_filter(array_map('trim', $tokens), function ($token) {
            return $token !== '';
        }));

        if (!$tokens) {
            return;
        }

        $parts = [];
        $titleNoSpace = "REPLACE(REPLACE(REPLACE(REPLACE({$titleColumn}, CHAR(9), ''), CHAR(10), ''), CHAR(13), ''), ' ', '')";
        $codeNoSpace = "REPLACE(REPLACE(REPLACE(REPLACE(h.evkodu, CHAR(9), ''), CHAR(10), ''), CHAR(13), ''), ' ', '')";
        $valueNoSpace = preg_replace('/\s+/', '', $value) ?? '';
        foreach ($tokens as $index => $token) {
            $titleParam = ':' . $prefix . 'Name' . $index;
            $codeParam = ':' . $prefix . 'Code' . $index;
            $parts[] = "({$titleColumn} LIKE {$titleParam} OR h.evkodu LIKE {$codeParam})";
            $params[$titleParam] = '%' . $token . '%';
            $params[$codeParam] = '%' . $token . '%';
        }

        $textWhere = '(' . implode(' AND ', $parts) . ')';
        if ($valueNoSpace !== '') {
            $titleNoSpaceParam = ':' . $prefix . 'TitleNoSpace';
            $codeNoSpaceParam = ':' . $prefix . 'CodeNoSpace';
            $textWhere = "({$textWhere} OR {$titleNoSpace} LIKE {$titleNoSpaceParam} OR {$codeNoSpace} LIKE {$codeNoSpaceParam})";
            $params[$titleNoSpaceParam] = '%' . $valueNoSpace . '%';
            $params[$codeNoSpaceParam] = '%' . $valueNoSpace . '%';
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
