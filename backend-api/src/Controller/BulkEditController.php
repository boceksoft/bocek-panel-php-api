<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Support\LegacySql;
use PDO;

/*
 * Toplu veri duzenleme (villalar, sayfalar, blog).
 *   GET  /bulk-edit/fields?type=villas|pages|blog      alan listesi (+ secenekler, kolon var mi)
 *   GET  /bulk-edit?type=..&fields=a,b&page=1&per_page=50&search=&active=1|0&sort=   kayitlar
 *        sort: order (siralama, varsayilan) | id_desc | id_asc | title_asc | title_desc
 *   POST /bulk-edit/save { type, changes: [{ id, values: { alan: deger } }] }
 *
 * Villa alanlari detay kaydetmenin yazdigi homes kolonlarina, havuz alanlari havuztanimlamari tablosuna
 * (homes havuz kolonlari senkron) yazilir. Sayfa/blog: sayfalar{dbtable}. Her alanin kolonu INFORMATION_SCHEMA'dan
 * dogrulanir; bulunamayan alan "available=false" doner ve yazilmaz.
 */
final class BulkEditController extends Controller
{
    use LegacySql;

    private const TYPES = ['villas', 'pages', 'blog'];

    // tipId: havuztanimlamari.tipId (1 = yuzme havuzu, 2 = cocuk havuzu)
    private const POOL_TYPES = [1 => 'yuzme', 2 => 'cocuk'];

    // havuztanimlamari kolonu => homes kolonu (HomesManagementPoolsController ile ayni)
    private const POOL_HOMES_COLUMNS = [
        1 => ['deger' => 'yuzme_havuzu', 'havuzTipiId' => 'yuzme_havuzu_tipi', 'uzunluk' => 'yuzme_havuzu_uzunluk',
              'genislik' => 'yuzme_havuzu_genislik', 'derinlik' => 'yuzme_havuzu_derinlik'],
        2 => ['deger' => 'cocuk_havuzu', 'uzunluk' => 'cocuk_havuzu_uzunluk', 'genislik' => 'cocuk_havuzu_genislik',
              'derinlik' => 'cocuk_havuzu_derinlik'],
    ];

    /**
     * @Get("fields")
     */
    public function fields(): void
    {
        $pdo = $this->db->pdo();
        $type = $this->type();
        $columns = $this->tableColumns($pdo, $this->table($pdo, $type));
        $poolReady = $type === 'villas' && $this->tableExists($pdo, 'havuztanimlamari');

        $fields = [];
        foreach ($this->definitions($type) as $key => $def) {
            // Kolonu veritabaninda bulunamayan alan hic donmez.
            $available = isset($def['pool']) ? $poolReady : $this->resolveColumn($def, $columns) !== null;
            if (!$available) {
                continue;
            }
            $field = ['key' => $key, 'label' => $def['label'], 'type' => $def['type'], 'available' => true];
            if (isset($def['options'])) {
                $field['options'] = $this->options($pdo, (string) $def['options']);
            }
            $fields[] = $field;
        }

        $this->response->success(['type' => $type, 'fields' => $fields]);
    }

    /**
     * @Get
     */
    public function index(): void
    {
        $pdo = $this->db->pdo();
        $type = $this->type();
        $table = $this->table($pdo, $type);
        $columns = $this->tableColumns($pdo, $table);
        $definitions = $this->definitions($type);

        $requested = array_values(array_filter(array_map('trim', explode(',', (string) $this->request->query('fields', '')))));
        $select = [];   // alan => kolon
        $poolFields = [];
        foreach ($requested as $key) {
            if (!isset($definitions[$key])) {
                continue;
            }
            if (isset($definitions[$key]['pool'])) {
                $poolFields[$key] = $definitions[$key]['pool'];
                continue;
            }
            $column = $this->resolveColumn($definitions[$key], $columns);
            if ($column !== null) {
                $select[$key] = $column;
            }
        }

        $page = max(1, (int) $this->request->query('page', 1));
        $perPage = min(500, max(1, (int) $this->request->query('per_page', 50)));
        $search = trim((string) $this->request->query('search', ''));

        $where = [$this->scope($type, $columns)];
        $params = [];
        if ($search !== '') {
            $where[] = '(baslik LIKE :search OR CONVERT(nvarchar(20), id) = :searchId)';
            $params[':search'] = '%' . $search . '%';
            $params[':searchId'] = $search;
        }
        // Aktiflik filtresi: 1 = aktifler, 0 = pasifler; bos = hepsi.
        $active = (string) $this->request->query('active', '');
        if (($active === '1' || $active === '0') && in_array('aktif', $columns, true)) {
            $where[] = $active === '1' ? 'ISNULL(aktif, 0) = 1' : 'ISNULL(aktif, 0) = 0';
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM dbo.[{$table}] WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $selectSql = ['id', 'baslik AS __label'];
        foreach ($select as $key => $column) {
            $selectSql[] = "[{$column}] AS [{$key}]";
        }
        $order = $this->orderSql((string) $this->request->query('sort', 'order'), $columns);
        $stmt = $pdo->prepare(
            'SELECT ' . implode(', ', $selectSql) . " FROM dbo.[{$table}] WHERE {$whereSql}
             ORDER BY {$order} OFFSET :offset ROWS FETCH NEXT :perPage ROWS ONLY"
        );
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':perPage', $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pools = $poolFields && $rawRows ? $this->poolsFor($pdo, array_map('intval', array_column($rawRows, 'id'))) : [];

        $rows = [];
        foreach ($rawRows as $raw) {
            $id = (int) $raw['id'];
            $values = [];
            foreach ($select as $key => $column) {
                $values[$key] = $this->outputValue($definitions[$key]['type'], $raw[$key] ?? null);
            }
            foreach ($poolFields as $key => [$tipId, $poolColumn]) {
                $value = $pools[$id][$tipId][$poolColumn] ?? null;
                $values[$key] = $this->outputValue($definitions[$key]['type'], $value);
            }
            $rows[] = ['id' => $id, 'label' => (string) ($raw['__label'] ?? ''), 'values' => $values];
        }

        $this->response->success([
            'type' => $type,
            'rows' => $rows,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    /**
     * Degisen hucreleri tek transaction'da yazar.
     *
     * @Post("save")
     */
    public function save(): void
    {
        $pdo = $this->db->pdo();
        $type = $this->type((string) $this->request->input('type', ''));
        $table = $this->table($pdo, $type);
        $columns = $this->tableColumns($pdo, $table);
        $definitions = $this->definitions($type);
        $scope = $this->scope($type, $columns);

        $changes = $this->request->input('changes', []);
        if (!is_array($changes) || !$changes) {
            throw new HttpException('Kaydedilecek degisiklik yok.', 'VALIDATION', 422);
        }

        $updated = 0;
        $skipped = [];
        $pdo->beginTransaction();
        try {
            foreach ($changes as $change) {
                $id = (int) ($change['id'] ?? 0);
                $values = $change['values'] ?? null;
                if ($id <= 0 || !is_array($values) || !$values) {
                    continue;
                }

                $exists = $pdo->prepare("SELECT COUNT(*) FROM dbo.[{$table}] WHERE id = :id AND {$scope}");
                $exists->execute([':id' => $id]);
                if ((int) $exists->fetchColumn() === 0) {
                    $skipped[] = ['id' => $id, 'reason' => 'Kayit bulunamadi.'];
                    continue;
                }

                $sets = [];
                $params = [':id' => $id];
                $poolValues = [];
                $i = 0;
                foreach ($values as $key => $value) {
                    $def = $definitions[$key] ?? null;
                    if ($def === null) {
                        continue;
                    }
                    if (isset($def['pool'])) {
                        [$tipId, $poolColumn] = $def['pool'];
                        $poolValues[$tipId][$poolColumn] = $this->inputValue($def['type'], $value, true);
                        continue;
                    }
                    $column = $this->resolveColumn($def, $columns);
                    if ($column === null) {
                        $skipped[] = ['id' => $id, 'field' => $key, 'reason' => 'Kolon bulunamadi.'];
                        continue;
                    }
                    $param = ':v' . $i++;
                    $sets[] = "[{$column}] = {$param}";
                    $params[$param] = $this->inputValue($def['type'], $value);
                }

                if ($sets) {
                    $stmt = $pdo->prepare("UPDATE dbo.[{$table}] SET " . implode(', ', $sets) . ' WHERE id = :id');
                    foreach ($params as $name => $paramValue) {
                        $stmt->bindValue($name, $paramValue, $paramValue === null ? PDO::PARAM_NULL : (is_int($paramValue) ? PDO::PARAM_INT : PDO::PARAM_STR));
                    }
                    $stmt->execute();
                    $updated++;
                }

                foreach ($poolValues as $tipId => $poolRow) {
                    $result = $this->savePool($pdo, $id, (int) $tipId, $poolRow);
                    if ($result !== null) {
                        $skipped[] = ['id' => $id, 'field' => self::POOL_TYPES[$tipId] . '_havuzu', 'reason' => $result];
                    } elseif (!$sets) {
                        $updated++;
                    }
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof HttpException) {
                throw $e;
            }
            throw new HttpException('Toplu kayit yapilamadi, hicbir degisiklik kaydedilmedi: ' . $e->getMessage(), 'DB_UPDATE_FAILED', 500);
        }

        $this->response->success(['updated' => $updated, 'skipped' => $skipped]);
    }

    /**
     * Alan tanimlari. columns: aday kolon adlari (ilk var olan kullanilir). pool: [tipId, havuztanimlamari kolonu].
     *
     * @return array<string,array<string,mixed>>
     */
    private function definitions(string $type): array
    {
        $common = [
            'siralama' => ['label' => 'Sıralama', 'type' => 'number', 'columns' => ['siralama']],
            'baslik' => ['label' => 'Başlık', 'type' => 'text', 'columns' => ['baslik']],
            'kisa_icerik' => ['label' => 'Kısa İçerik', 'type' => 'textarea', 'columns' => ['kisa_icerik']],
            'title' => ['label' => 'title [META]', 'type' => 'text', 'columns' => ['title']],
            'keywords' => ['label' => 'Anahtar Kelimeler [META]', 'type' => 'text', 'columns' => ['keywords']],
            'description' => ['label' => 'Açıklama [META]', 'type' => 'textarea', 'columns' => ['description']],
            'url' => ['label' => 'Url [META]', 'type' => 'text', 'columns' => ['url']],
        ];

        if ($type !== 'villas') {
            return $common + [
                'aktif' => ['label' => 'Aktif', 'type' => 'checkbox', 'columns' => ['aktif']],
                'favori' => ['label' => 'Favori', 'type' => 'checkbox', 'columns' => ['favori']],
            ];
        }

        return $common + [
            'evsahibi' => ['label' => 'Evsahibi', 'type' => 'select', 'columns' => ['evsahibi'], 'options' => 'owners'],
            'aktif' => ['label' => 'Aktif', 'type' => 'checkbox', 'columns' => ['aktif']],
            'not' => ['label' => 'Not', 'type' => 'textarea', 'columns' => ['not2', 'not']],
            'bakimciad' => ['label' => 'Bakımcı Adı', 'type' => 'text', 'columns' => ['bakimciad']],
            'bakimcitel' => ['label' => 'Bakımcı Tel', 'type' => 'text', 'columns' => ['bakimcitel']],
            'cikis_saat' => ['label' => 'Çıkış Saati', 'type' => 'text', 'columns' => ['cikis_saat']],
            'giris_saat' => ['label' => 'Giriş Saati', 'type' => 'text', 'columns' => ['giris_saat']],
            'kat_sayisi' => ['label' => 'Kat Sayısı', 'type' => 'number', 'columns' => ['kat_sayisi', 'katsayisi', 'kat']],
            'kiralama_kurallari' => ['label' => 'Kiralama Kuralları', 'type' => 'textarea', 'columns' => ['kiralama_kurallari', 'kiralamakurallari']],
            'kurallar' => ['label' => 'Ev Kuralları', 'type' => 'multiselect', 'columns' => ['kurallar'], 'options' => 'rules'],
            'kazancorani' => ['label' => 'Kazanç Oranı', 'type' => 'number', 'columns' => ['kazancorani']],
            'depozito' => ['label' => 'Depozito', 'type' => 'number', 'columns' => ['depozito']],
            'hasar' => ['label' => 'Hasar Depozitosu', 'type' => 'number', 'columns' => ['hasar']],
            'temizlik' => ['label' => 'Temizlik Ücreti', 'type' => 'number', 'columns' => ['temizlik']],
            'elektrik' => ['label' => 'Elektrik Ücreti', 'type' => 'number', 'columns' => ['elektrik']],
            'ribbon' => ['label' => 'Ribbon', 'type' => 'text', 'columns' => ['ribbon']],
            'ribbon2' => ['label' => 'Ribbon 2', 'type' => 'text', 'columns' => ['ribbon2']],
            'yuzme_derinlik' => ['label' => 'Yüzme Havuzu Derinlik', 'type' => 'text', 'pool' => [1, 'derinlik']],
            'yuzme_genislik' => ['label' => 'Yüzme Havuzu Genişlik', 'type' => 'text', 'pool' => [1, 'genislik']],
            'yuzme_uzunluk' => ['label' => 'Yüzme Havuzu Uzunluk', 'type' => 'text', 'pool' => [1, 'uzunluk']],
            'cocuk_derinlik' => ['label' => 'Çocuk Havuzu Derinlik', 'type' => 'text', 'pool' => [2, 'derinlik']],
            'cocuk_genislik' => ['label' => 'Çocuk Havuzu Genişlik', 'type' => 'text', 'pool' => [2, 'genislik']],
            'cocuk_uzunluk' => ['label' => 'Çocuk Havuzu Uzunluk', 'type' => 'text', 'pool' => [2, 'uzunluk']],
            'yuzme_havuzu' => ['label' => 'Yüzme Havuzu', 'type' => 'text', 'pool' => [1, 'deger']],
            'yuzme_havuzu_tipi' => ['label' => 'Yüzme Havuzu Tipi', 'type' => 'select', 'pool' => [1, 'havuzTipiId'], 'options' => 'poolTypes'],
            'cocuk_havuzu' => ['label' => 'Çocuk Havuzu', 'type' => 'text', 'pool' => [2, 'deger']],
            'konum' => ['label' => 'Konumu', 'type' => 'textarea', 'columns' => ['konum', 'konumu', 'konum_not']],
        ];
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private function options(PDO $pdo, string $name): array
    {
        if ($name === 'owners') {
            $rows = $pdo->query(
                "SELECT id, LTRIM(RTRIM(ISNULL(ad, '') + ' ' + ISNULL(soyad, ''))) AS label
                 FROM kullanici WHERE aktif = 1 AND yetki = 2 AND id <> 1 ORDER BY ad ASC, soyad ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
            array_unshift($rows, ['id' => 1, 'label' => 'Admin']);
        } elseif ($name === 'rules') {
            $rows = $pdo->query('SELECT id, baslik AS label FROM kurallar ORDER BY baslik ASC')->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($name === 'poolTypes' && $this->tableExists($pdo, 'havuz_tipi')) {
            $rows = $pdo->query('SELECT id, baslik AS label FROM dbo.havuz_tipi ORDER BY baslik ASC')->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $rows = [];
        }

        return array_map(function (array $row): array {
            return ['value' => (string) $row['id'], 'label' => trim((string) $row['label']) ?: ('#' . $row['id'])];
        }, $rows);
    }

    /**
     * @param int[] $ids
     * @return array<int,array<int,array<string,mixed>>> homesId => tipId => satir
     */
    private function poolsFor(PDO $pdo, array $ids): array
    {
        if (!$ids || !$this->tableExists($pdo, 'havuztanimlamari')) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT homesId, tipId, deger, havuzTipiId, uzunluk, genislik, derinlik
             FROM dbo.havuztanimlamari WHERE tipId IN (1, 2) AND homesId IN ({$placeholders})
             ORDER BY id ASC"
        );
        $stmt->execute($ids);

        $pools = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $homeId = (int) $row['homesId'];
            $tipId = (int) $row['tipId'];
            if (!isset($pools[$homeId][$tipId])) {
                $pools[$homeId][$tipId] = $row;
            }
        }

        return $pools;
    }

    /**
     * Havuz satirini gunceller / ekler ve homes havuz kolonlarini senkronlar. Hata metni ya da null doner.
     *
     * @param array<string,mixed> $values havuztanimlamari kolonu => deger
     */
    private function savePool(PDO $pdo, int $homeId, int $tipId, array $values): ?string
    {
        if (!$this->tableExists($pdo, 'havuztanimlamari')) {
            return 'havuztanimlamari tablosu yok.';
        }

        $stmt = $pdo->prepare('SELECT TOP 1 id FROM dbo.havuztanimlamari WHERE homesId = :homeId AND tipId = :tipId ORDER BY id ASC');
        $stmt->execute([':homeId' => $homeId, ':tipId' => $tipId]);
        $poolId = $stmt->fetchColumn();

        if ($poolId === false) {
            if (trim((string) ($values['deger'] ?? '')) === '') {
                return 'Havuz kaydi yok; yeni havuz icin "' . (self::POOL_TYPES[$tipId] === 'yuzme' ? 'Yüzme Havuzu' : 'Çocuk Havuzu') . '" degeri girilmeli.';
            }
            // HomesManagementPoolsController gibi: gonderilmeyen olculer bos, tamKorunakli bos.
            $values += ['uzunluk' => '', 'genislik' => '', 'derinlik' => '', 'tamKorunakli' => ''];
            $cols = array_keys($values);
            $insert = $pdo->prepare(
                'INSERT INTO dbo.havuztanimlamari (homesId, tipId, ' . implode(', ', $cols) . ')
                 VALUES (:homeId, :tipId, ' . implode(', ', array_map(function ($c) { return ':' . $c; }, $cols)) . ')'
            );
            $params = [':homeId' => $homeId, ':tipId' => $tipId];
            foreach ($values as $col => $value) {
                $params[':' . $col] = $value;
            }
            $insert->execute($params);
        } else {
            $sets = [];
            $params = [':id' => (int) $poolId];
            foreach ($values as $col => $value) {
                $sets[] = "{$col} = :{$col}";
                $params[':' . $col] = $value;
            }
            $sets[] = 'DateModified = GETDATE()';
            $pdo->prepare('UPDATE dbo.havuztanimlamari SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
        }

        // homes havuz kolonlari (varsa) ayni degerlerle.
        $homesColumns = $this->tableColumns($pdo, 'homes');
        $sets = [];
        $params = [':homeId' => $homeId];
        foreach ($values as $col => $value) {
            $homesColumn = self::POOL_HOMES_COLUMNS[$tipId][$col] ?? null;
            if ($homesColumn !== null && in_array(strtolower($homesColumn), $homesColumns, true)) {
                $sets[] = "[{$homesColumn}] = :h_{$col}";
                $params[':h_' . $col] = $value;
            }
        }
        if ($sets) {
            $pdo->prepare('UPDATE dbo.homes SET ' . implode(', ', $sets) . ' WHERE id = :homeId')->execute($params);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $def
     * @param string[] $columns kucuk harfli tablo kolonlari
     */
    private function resolveColumn(array $def, array $columns): ?string
    {
        foreach ($def['columns'] ?? [] as $candidate) {
            if (in_array(strtolower($candidate), $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function outputValue(string $type, $value)
    {
        if ($type === 'checkbox') {
            return (bool) (int) $value;
        }
        if ($type === 'multiselect') {
            $items = preg_split('/[,#]/', (string) $value) ?: [];
            return array_values(array_filter(array_map('trim', $items), function ($v) { return $v !== '' && $v !== '0'; }));
        }
        if ($value === null) {
            return '';
        }
        if ($type === 'number' && is_numeric($value)) {
            return $value + 0;
        }

        return is_string($value) ? $value : (string) $value;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function inputValue(string $type, $value, bool $pool = false)
    {
        if ($type === 'checkbox') {
            return ($value === true || $value === 1 || $value === '1' || $value === 'true') ? 1 : 0;
        }
        if ($type === 'multiselect') {
            $items = is_array($value) ? $value : explode(',', (string) $value);
            $ids = [];
            foreach ($items as $item) {
                if (is_numeric($item) && (int) $item > 0) {
                    $ids[] = (string) (int) $item;
                }
            }
            return implode(',', array_values(array_unique($ids)));
        }
        if ($type === 'select' && $pool) {
            return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
        }
        if ($type === 'number' || $type === 'select') {
            $value = str_replace(',', '.', trim((string) $value));
            if ($value === '') {
                return null;
            }
            if (!is_numeric($value)) {
                throw new HttpException('Sayisal alana gecersiz deger: ' . $value, 'VALIDATION', 422);
            }
            return strpos($value, '.') !== false ? $value : (int) $value;
        }

        return trim((string) $value);
    }

    private function type(?string $value = null): string
    {
        $type = $value ?? (string) $this->request->query('type', '');
        if (!in_array($type, self::TYPES, true)) {
            throw new HttpException('Gecersiz veri tipi.', 'VALIDATION', 422);
        }

        return $type;
    }

    private function table(PDO $pdo, string $type): string
    {
        return $type === 'villas' ? 'homes' : 'sayfalar' . $this->siteSuffix($pdo);
    }

    /**
     * Sayfa: blog ve haber haric; blog: sadece blog=1 (CmsPagesController / BlogPostsController ile ayni).
     *
     * @param string[] $columns
     */
    private function scope(string $type, array $columns): string
    {
        if ($type === 'villas') {
            return '1 = 1';
        }
        $where = [];
        if ($type === 'blog') {
            if (!in_array('blog', $columns, true)) {
                return '1 = 0';
            }
            $where[] = 'ISNULL(blog, 0) = 1';
        } elseif (in_array('blog', $columns, true)) {
            $where[] = 'ISNULL(blog, 0) = 0';
        }
        if (in_array('haber', $columns, true)) {
            $where[] = 'ISNULL(haber, 0) = 0';
        }
        if (in_array('disabled', $columns, true)) {
            $where[] = 'disabled IS NULL';
        }

        return $where ? implode(' AND ', $where) : '1 = 1';
    }

    /**
     * @param string[] $columns
     */
    private function orderSql(string $sort, array $columns): string
    {
        switch ($sort) {
            case 'id_asc':
                return 'id ASC';
            case 'id_desc':
                return 'id DESC';
            case 'title_asc':
                return 'baslik ASC, id DESC';
            case 'title_desc':
                return 'baslik DESC, id DESC';
            default:
                return in_array('siralama', $columns, true) ? 'ISNULL(siralama, 0) ASC, id DESC' : 'id DESC';
        }
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = :t");
        $stmt->execute([':t' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
