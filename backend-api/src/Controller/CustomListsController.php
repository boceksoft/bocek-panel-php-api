<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Support\LegacySql;
use PDO;

/*
 * Ozel listeler (eski panel CustomList.asp / CustomListHomes.asp karsiligi).
 * CustomList: Id, Name, PageId, Site (TypeId, Sort sitede kullanilir; panel yazmaz).
 * CustomListHomes: ListId, HomeId, Sequence (listedeki villa sirasi).
 * Her listenin sitede bir sayfasi vardir (sayfalar{dbtable}, cat = custom_list_page_category).
 *   GET  /backend-api/custom-lists?site=1
 *   POST /backend-api/custom-lists?site=1                { name }  -> sayfa + liste olusturur
 *   POST /backend-api/custom-lists/update?site=1         { id, name, pageId }
 *   POST /backend-api/custom-lists/delete?site=1         { id }    -> liste ve villa baglantilari silinir, sayfa kalir
 *   GET  /backend-api/custom-lists/homes?site=1&listId=  -> listedeki villalar (villa arama: estate gethomes)
 *   POST /backend-api/custom-lists/homes/add?site=1      { listId, homeIds[] }  (en fazla 1000)
 *   POST /backend-api/custom-lists/homes/remove?site=1   { listId, homeIds[] }  (en fazla 1000)
 *   POST /backend-api/custom-lists/homes/sort?site=1     { listId, homeIds[] }  -> verilen sira Sequence 1..n
 */
final class CustomListsController extends Controller
{
    use LegacySql;

    private const MAX_NAME_LENGTH = 250;

    /** @Get */
    public function index(): void
    {
        $pdo = $this->db->pdo();
        $siteId = $this->siteId();
        $pageTable = $this->pageTable($pdo);

        $stmt = $pdo->prepare('SELECT c.Id, c.Name, c.PageId, s.baslik AS PageTitle, s.url AS PageUrl,
                (SELECT COUNT(*) FROM dbo.CustomListHomes h WHERE h.ListId = c.Id) AS HomeCount
            FROM dbo.CustomList c
            LEFT JOIN dbo.[' . $pageTable . '] s ON s.id = c.PageId
            WHERE c.Site = :site
            ORDER BY c.Id DESC');
        $this->execute($stmt, ['site' => $siteId]);

        $lists = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $lists[] = [
                'id' => (int) $row['Id'],
                'name' => trim((string) ($row['Name'] ?? '')),
                'pageId' => (int) ($row['PageId'] ?? 0),
                'pageTitle' => trim((string) ($row['PageTitle'] ?? '')),
                'pageUrl' => trim((string) ($row['PageUrl'] ?? '')),
                'homeCount' => (int) ($row['HomeCount'] ?? 0),
            ];
        }

        $this->response->success(['lists' => $lists, 'pages' => $this->pageOptions($pdo, $pageTable)]);
    }

    /**
     * Panel CustomListAdd: once listenin sayfasi acilir (tum metin alanlari baslik), sonra liste o sayfaya baglanir.
     *
     * @Post
     */
    public function store(): void
    {
        $name = $this->name($this->request->input('name'));
        $pdo = $this->db->pdo();
        $siteId = $this->siteId();
        $pageTable = $this->pageTable($pdo);
        $columns = $this->tableColumns($pdo, $pageTable);

        // Panel kisa_icerik'e de basligi yazardi; icerik alanlari bos birakilir, Sayfalar'dan doldurulur.
        $page = [
            'baslik' => $name, 'kisa_baslik' => $name, 'title' => $name, 'description' => $name, 'keywords' => $name,
            'cat' => $this->pageCategory(), 'siralama' => 0, 'blog' => 0, 'yonlendirme' => 0, 'ShowTopSearchBar' => 1,
        ];
        $page = array_filter($page, function ($column) use ($columns) {
            return in_array(strtolower($column), $columns, true);
        }, ARRAY_FILTER_USE_KEY);
        $raw = in_array('tarih', $columns, true) ? ['tarih' => 'CAST(GETDATE() AS date)'] : [];

        $pdo->beginTransaction();
        try {
            if (in_array('url', $columns, true)) $page['url'] = $this->uniqueUrl($pdo, $pageTable, $this->slug($name));
            $pageId = $this->insertRow($pdo, $pageTable, $page, $raw);
            $id = $this->insertRow($pdo, 'CustomList', ['Name' => $name, 'PageId' => $pageId, 'Site' => $siteId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $this->response->success(['message' => 'Ozel liste eklendi', 'id' => $id, 'pageId' => $pageId], 201);
    }

    /** @Post("update") */
    public function update(): void
    {
        $id = $this->bodyId('id');
        $name = $this->name($this->request->input('name'));
        $pageId = $this->bodyId('pageId');
        $pdo = $this->db->pdo();
        $pageTable = $this->pageTable($pdo);

        $allowed = array_column($this->pageOptions($pdo, $pageTable), 'id');
        if (!in_array($pageId, $allowed, true)) {
            throw new HttpException('Secilen sayfa ozel liste sayfalari arasinda yok.', 'VALIDATION', 422);
        }

        $pdo->beginTransaction();
        try {
            $this->requireList($pdo, $id);
            $this->execute($pdo->prepare('UPDATE dbo.CustomList SET Name = :name, PageId = :pageId WHERE Id = :id'), [
                'name' => $name, 'pageId' => $pageId, 'id' => $id,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $this->response->success(['message' => 'Ozel liste guncellendi']);
    }

    // Listenin sayfasi silinmez (sitede baska yerden baglanmis olabilir); Sayfalar'dan ayrica silinebilir.
    /** @Post("delete") */
    public function delete(): void
    {
        $id = $this->bodyId('id');
        $pdo = $this->db->pdo();

        $pdo->beginTransaction();
        try {
            $this->requireList($pdo, $id);
            $this->execute($pdo->prepare('DELETE FROM dbo.CustomListHomes WHERE ListId = :id'), ['id' => $id]);
            $this->execute($pdo->prepare('DELETE FROM dbo.CustomList WHERE Id = :id'), ['id' => $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $this->response->success(['message' => 'Ozel liste silindi']);
    }

    /** @Get("homes") */
    public function homes(): void
    {
        $listId = $this->queryId('listId');
        $pdo = $this->db->pdo();
        $this->requireList($pdo, $listId);

        $stmt = $pdo->prepare('SELECT l.HomeId, ' . $this->homeTitleSql('h', 'h.baslik') . ' AS baslik, h.evkodu
            FROM dbo.CustomListHomes l
            LEFT JOIN dbo.homes h ON h.id = l.HomeId
            WHERE l.ListId = ?
            ORDER BY ISNULL(l.Sequence, 0), l.HomeId');
        $stmt->execute([$listId]);

        $homes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $homes[] = [
                'homeId' => (int) $row['HomeId'],
                'title' => trim((string) ($row['baslik'] ?? '')),
                'code' => trim((string) ($row['evkodu'] ?? '')),
                // Villa silinmis ama baglantisi kalmis olabilir; listede gosterilip cikarilabilsin.
                'missing' => $row['baslik'] === null && $row['evkodu'] === null,
            ];
        }

        $this->response->success(['homes' => $homes]);
    }

    /**
     * Toplu ekleme tek INSERT ... SELECT ile: olmayan villa ve listede zaten olan villa atlanir,
     * yeniler gelen sirayla listenin sonuna eklenir.
     *
     * @Post("homes/add")
     */
    public function addHomes(): void
    {
        $listId = $this->bodyId('listId');
        $homeIds = $this->homeIds();
        $pdo = $this->db->pdo();

        $pdo->beginTransaction();
        try {
            $this->requireList($pdo, $listId);
            [$valuesSql, $valuesParams] = $this->orderedIds($homeIds);
            $stmt = $pdo->prepare('INSERT INTO dbo.CustomListHomes (ListId, HomeId, Sequence)
                SELECT ?, v.HomeId, ISNULL(m.MaxSequence, 0) + ROW_NUMBER() OVER (ORDER BY v.Ord)
                FROM ' . $valuesSql . '
                INNER JOIN dbo.homes h ON h.id = v.HomeId
                CROSS JOIN (SELECT MAX(Sequence) AS MaxSequence FROM dbo.CustomListHomes WITH (UPDLOCK, HOLDLOCK) WHERE ListId = ?) m
                WHERE NOT EXISTS (SELECT 1 FROM dbo.CustomListHomes x WITH (UPDLOCK, HOLDLOCK) WHERE x.ListId = ? AND x.HomeId = v.HomeId)');
            $stmt->execute(array_merge([$listId], $valuesParams, [$listId, $listId]));
            $added = $stmt->rowCount();
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $skipped = count($homeIds) - $added;
        $this->response->success([
            'message' => $added . ' villa listeye eklendi' . ($skipped > 0 ? ', ' . $skipped . ' villa zaten listedeydi' : ''),
            'added' => $added,
            'skipped' => $skipped,
        ]);
    }

    /** @Post("homes/remove") */
    public function removeHomes(): void
    {
        $listId = $this->bodyId('listId');
        $homeIds = $this->homeIds();
        $pdo = $this->db->pdo();

        $pdo->beginTransaction();
        try {
            $this->requireList($pdo, $listId);
            $placeholders = implode(', ', array_fill(0, count($homeIds), '?'));
            $stmt = $pdo->prepare('DELETE FROM dbo.CustomListHomes WHERE ListId = ? AND HomeId IN (' . $placeholders . ')');
            $stmt->execute(array_merge([$listId], $homeIds));
            $removed = $stmt->rowCount();
            $this->renumber($pdo, $listId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $this->response->success(['message' => $removed . ' villa listeden cikarildi', 'removed' => $removed]);
    }

    // Gelen sira listedeki villalarin tamami olmali; eksik/fazla villa varsa (baska ekranda degismis) siralama yapilmaz.
    /** @Post("homes/sort") */
    public function sortHomes(): void
    {
        $listId = $this->bodyId('listId');
        $homeIds = $this->homeIds();
        $pdo = $this->db->pdo();

        $pdo->beginTransaction();
        try {
            $this->requireList($pdo, $listId);
            $stmt = $pdo->prepare('SELECT DISTINCT HomeId FROM dbo.CustomListHomes WITH (UPDLOCK, HOLDLOCK) WHERE ListId = ?');
            $stmt->execute([$listId]);
            $current = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $given = $homeIds;
            sort($current);
            sort($given);
            if ($current !== $given) {
                throw new HttpException('Liste degismis; sayfayi yenileyip tekrar siralayin.', 'LIST_CHANGED', 409);
            }

            [$valuesSql, $valuesParams] = $this->orderedIds($homeIds);
            $stmt = $pdo->prepare('UPDATE l SET Sequence = v.Ord FROM dbo.CustomListHomes l INNER JOIN ' . $valuesSql . ' ON v.HomeId = l.HomeId WHERE l.ListId = ?');
            $stmt->execute(array_merge($valuesParams, [$listId]));
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $this->response->success(['message' => 'Siralama kaydedildi']);
    }

    /**
     * Villa id'lerini sirasiyla tablo ifadesine cevirir: (VALUES (?, ?), ...) AS v(HomeId, Ord), Ord 1'den baslar.
     *
     * @param array<int,int> $homeIds
     * @return array{0:string,1:array<int,int>}
     */
    private function orderedIds(array $homeIds): array
    {
        $params = [];
        foreach ($homeIds as $index => $homeId) {
            $params[] = $homeId;
            $params[] = $index + 1;
        }
        return ['(VALUES ' . implode(', ', array_fill(0, count($homeIds), '(?, ?)')) . ') AS v(HomeId, Ord)', $params];
    }

    // Cikarmadan sonra sira bosluksuz 1..n olur.
    private function renumber(PDO $pdo, int $listId): void
    {
        $stmt = $pdo->prepare('WITH o AS (
                SELECT Sequence, ROW_NUMBER() OVER (ORDER BY ISNULL(Sequence, 0), HomeId) AS n FROM dbo.CustomListHomes WHERE ListId = ?
            ) UPDATE o SET Sequence = n');
        $stmt->execute([$listId]);
    }

    private function pageTable(PDO $pdo): string
    {
        return 'sayfalar' . $this->siteSuffix($pdo);
    }

    private function pageCategory(): int
    {
        $bySite = $this->app['custom_list_page_categories'] ?? [];
        $siteId = $this->siteId();
        if (is_array($bySite) && isset($bySite[$siteId])) return (int) $bySite[$siteId];
        return (int) ($this->app['custom_list_page_category'] ?? 391);
    }

    /** @return array<int,array{id:int,title:string}> panel duzenle formundaki "Sayfa" secenekleri */
    private function pageOptions(PDO $pdo, string $pageTable): array
    {
        $stmt = $pdo->prepare('SELECT id, baslik FROM dbo.[' . $pageTable . '] WHERE cat = :cat ORDER BY baslik ASC');
        $this->execute($stmt, ['cat' => $this->pageCategory()]);
        return array_map(function ($row) {
            return ['id' => (int) $row['id'], 'title' => trim((string) ($row['baslik'] ?? ''))];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // Liste baska sitenin ise bulunamadi sayilir.
    private function requireList(PDO $pdo, int $id): void
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM dbo.CustomList WITH (UPDLOCK, HOLDLOCK) WHERE Id = :id AND Site = :site');
        $this->execute($stmt, ['id' => $id, 'site' => $this->siteId()]);
        if ((int) $stmt->fetchColumn() === 0) throw new HttpException('Ozel liste bulunamadi.', 'NOT_FOUND', 404);
    }

    /** @return array<int,int> tekrarsiz villa id'leri, gelen sirayla */
    private function homeIds(): array
    {
        $raw = $this->request->input('homeIds');
        if (!is_array($raw) || $raw === []) throw new HttpException('En az bir villa secilmelidir.', 'VALIDATION', 422);
        if (count($raw) > 1000) throw new HttpException('Tek seferde en fazla 1000 villa gonderilebilir.', 'VALIDATION', 422);
        $ids = [];
        foreach ($raw as $value) $ids[] = $this->validId($value);
        return array_values(array_unique($ids));
    }

    /** @param mixed $value */
    private function name($value): string
    {
        if (!is_string($value)) throw new HttpException('Baslik gereklidir.', 'VALIDATION', 422);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($value === '') throw new HttpException('Baslik gereklidir.', 'VALIDATION', 422);
        if (mb_strlen($value, 'UTF-8') > self::MAX_NAME_LENGTH) {
            throw new HttpException('Baslik en fazla ' . self::MAX_NAME_LENGTH . ' karakter olabilir.', 'VALIDATION', 422);
        }
        return $value;
    }

    // Panel ayni url'yi ikinci kez acabiliyordu; doluysa -2, -3 ... eklenir.
    private function uniqueUrl(PDO $pdo, string $pageTable, string $slug): string
    {
        $stmt = $pdo->prepare('SELECT LOWER(url) FROM dbo.[' . $pageTable . '] WITH (UPDLOCK, HOLDLOCK) WHERE url = ? OR url LIKE ?');
        $stmt->execute([$slug, $slug . '-%']);
        $taken = array_flip(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        $url = $slug;
        for ($n = 2; isset($taken[$url]); $n++) $url = $slug . '-' . $n;
        return $url;
    }

    // Panel Lcase(Cevir(baslik)) karsiligi: Turkce harfler ascii, digerleri "-".
    private function slug(string $value): string
    {
        $value = strtr($value, [
            'Ç' => 'c', 'Ğ' => 'g', 'İ' => 'i', 'I' => 'i', 'Ö' => 'o', 'Ş' => 's', 'Ü' => 'u',
            'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u',
        ]);
        $value = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');
        return $value !== '' ? $value : 'ozel-liste';
    }
}
