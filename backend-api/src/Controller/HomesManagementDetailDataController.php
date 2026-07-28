<?php

declare(strict_types=1);

namespace App\Controller;

use PDO;

/*
 * Homes management detail form contract.
 * Detail sayfasinda yeni kayit eklemek veya mevcut kaydi guncellemek icin
 * frontend'in gonderecegi alan adlarini ve beklenen veri tiplerini doner.
 */
final class HomesManagementDetailDataController extends Controller
{
    /**
     * Detay sayfasi create/update payload sozlesmesini listeler.
     *
     * @Get
     * @query mode string create veya update
     * @query id int Opsiyonel emlak ID. Gonderilirse secili degerler de doner.
     */
    public function index(): void
    {
        $mode = strtolower(trim((string) $this->request->query('mode', 'update')));
        if ($mode !== 'create' && $mode !== 'update') {
            $mode = 'update';
        }

        $pdo = $this->db->pdo();
        $sites = $this->fetchSites($pdo);
        $home = $this->home($pdo, (int) $this->request->query('id', 0));
        $selectableData = $this->selectableData($pdo, $home, $sites);
        $bolgelerTree = $this->buildTree($selectableData['bolgeler']);
        $selectableData['bolgeler_tree'] = $bolgelerTree;
        $selectedValues = $this->selectedValues($home, $sites);

        $this->response->success([
            'fields' => $this->fields(),
            'selectable_data' => $selectableData,
            'selected_values' => $selectedValues,
            'emlak_tipi_baslik' => $this->firstValueFrom($home, ['emlak_tipi_baslik']),
            'arr' => $selectableData['arr'],
            'alt_kategoriler' => $selectableData['alt_kategoriler'],
            'tipler' => $selectableData['emlak_tipleri'],
            'kategoriler' => $selectableData['kategoriler'],
            'bolgeler' => $selectableData['bolgeler'],
            'bolgeler_tree' => $bolgelerTree,
            'ozellikler' => $selectableData['ozellikler'],
            'onecikan_ozellikler' => $selectableData['onecikan_ozellikler'],
            'kurallar' => $selectableData['kurallar'],
            'dahil_hizmetler' => $selectableData['dahil_hizmetler'],
            'ev_sahipleri' => $selectableData['ev_sahipleri'],
            'on_odeme_yontemleri' => $selectableData['on_odeme_yontemleri'],
            'havuz_tipleri' => $selectableData['havuz_tipleri'],
            'oda_yatak_tipleri' => $selectableData['oda_yatak_tipleri'],
            'para_birimleri' => $selectableData['para_birimleri'],
            'mesafe_tipleri' => $selectableData['mesafe_tipleri'],
            'fiyat_tipleri' => $selectableData['fiyat_tipleri'],
            'ekstra_ucret_tipleri' => $selectableData['ekstra_ucret_tipleri'],
            'iptal_sartlari' => $selectableData['iptal_sartlari'],
        ]);
    }




    /**
     * @return array<int,string>
     */
    private function requiredFields(string $mode): array
    {
        if ($mode === 'create') {
            return [
                'villaadi',
                'emlaktipi',
                'bolge',
            ];
        }

        return ['id'];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fields(): array
    {
        return [
            $this->field('id', 'integer'),
            $this->field('villaadi', 'string'),
            $this->field('kisaaciklama', 'string'),
            $this->field('emlaktipi', 'integer', 'emlak_tipleri'),
            $this->field('kategori', 'integer', 'kategoriler'),
            $this->field('kapasite', 'integer'),
            $this->field('yatakodasi', 'integer'),
            $this->field('yataksayisi', 'integer'),
            $this->field('banyo', 'integer'),
            $this->field('girissaati', 'string'),
            $this->field('cikissaati', 'string'),
            $this->field('iptalpolitikasi', 'integer', 'iptal_sartlari'),
            $this->field('iptalsarti', 'string'),
            $this->field('bakimciadi', 'string'),
            $this->field('bakimcitel', 'string'),
            $this->field('evsahibi', 'integer', 'ev_sahipleri'),
            $this->field('whatsappgrupadi', 'string'),
            $this->field('yetkilifirma', 'string'),
            $this->field('ilannotlari', 'string'),
            $this->field('icerik', 'string'),
            $this->field('ozellikler', 'integer[]', 'ozellikler'),
            $this->field('onecikan', 'integer[]', 'onecikan_ozellikler'),
            $this->field('etiketler', 'string[]'),
            $this->field('kurallar', 'integer[]', 'kurallar'),
            $this->field('dahilhizmetler', 'integer[]', 'dahil_hizmetler'),
            $this->field('parabirimi', 'string', 'para_birimleri'),
            $this->field('depozitoorani', 'number'),
            $this->field('komisyonorani', 'number'),
            $this->field('hasar', 'number'),
            $this->field('temizlik', 'number'),
            $this->field('elektrik', 'number'),
            $this->field('onodemeyontemi', 'integer', 'on_odeme_yontemleri'),
            $this->field('bolge', 'integer', 'bolgeler'),
            $this->field('enlem', 'string'),
            $this->field('boylam', 'string'),
            $this->field('mesafetipi', 'string', 'mesafe_tipleri'),
            $this->field('kapakresimler', 'string[]'),
            $this->field('videolar', 'string[]'),
            $this->field('metabaslik', 'string'),
            $this->field('metakeywords', 'string'),
            $this->field('metaaciklama', 'string'),
            $this->field('slug', 'string'),
            $this->field('canonical', 'string'),
            $this->field('havuzlar', 'object[]', 'havuz_tipleri'),
            $this->field('odalar', 'object[]', 'oda_yatak_tipleri'),
            $this->field('sezonlar', 'object[]'),
            $this->field('ekstraucretler', 'object[]'),
            $this->field('indirimler', 'object[]'),
            $this->field('konumnotlari', 'object[]'),
            $this->field('mesafeler', 'object[]'),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function field(string $name, string $type, string $optionsKey = ''): array
    {
        $field = [
            'name' => $name,
            'type' => $type,
        ];

        if ($optionsKey !== '') {
            $field['options_key'] = $optionsKey;
        }

        return $field;
    }

    /**
     * @return array<string,mixed>
     */
    private function selectableData(PDO $pdo, array $home, array $sites): array
    {
        $anaTipler = $this->fetchOptions(
            $pdo,
            "SELECT id, baslik AS title
             FROM tip
             WHERE cat = 0 AND aktif = 1
             ORDER BY baslik ASC"
        );

        return [
            'arr' => array_map(static function (array $row): array {
                return ['baslik' => $row['title'] ?? ''];
            }, $anaTipler),
            'emlak_tipleri' => $this->fetchOptions(
                $pdo,
                "SELECT id, baslik AS title, cat AS parent_id
                 FROM tip
                 WHERE aktif = 1 AND cat <> 0
                 ORDER BY cat ASC, siralama ASC, baslik ASC"
            ),
            'ana_tipler' => $anaTipler,
            'alt_kategoriler' => $this->tipKategorileri($pdo, $anaTipler, $home),
            'kategoriler' => $this->fetchOptions(
                $pdo,
                "SELECT id, baslik AS title, cat AS parent_id
                 FROM tip
                 WHERE aktif = 1
                 ORDER BY cat ASC, siralama ASC, baslik ASC"
            ),
            'bolgeler' => $this->fetchOptions(
                $pdo,
                "SELECT id, baslik AS title, cat AS parent_id
                 FROM destinations
                 WHERE aktif = 1
                 ORDER BY cat ASC, siralama ASC, baslik ASC"
            ),
            'ozellikler' => $this->fetchOptions(
                $pdo,
                "SELECT id, baslik AS title, cat AS parent_id
                 FROM ozellikler
                 ORDER BY cat ASC, siralama ASC, baslik ASC"
            ),
            'onecikan_ozellikler' => $this->fetchOptions(
                $pdo,
                "SELECT id, baslik AS title
                 FROM oneCikanOzellikler
                 ORDER BY baslik ASC"
            ),
            'kurallar' => $this->fetchOptions(
                $pdo,
                "SELECT id, baslik AS title
                 FROM kurallar
                 ORDER BY baslik ASC"
            ),
            'dahil_hizmetler' => $this->fetchOptions(
                $pdo,
                "SELECT id, baslik AS title
                 FROM dahilOlanlar
                 ORDER BY siralama ASC, baslik ASC"
            ),
            'ev_sahipleri' => $this->evSahipleri($pdo),
            'on_odeme_yontemleri' => $this->fetchOptions(
                $pdo,
                "SELECT Id AS id, Title AS title
                 FROM Finance.FirstPaymentTypes
                 ORDER BY Title ASC"
            ),
            'mesafe_secenekleri' => $this->fetchOptions(
                $pdo,
                "SELECT id, baslik AS title
                 FROM mesafeler
                 ORDER BY baslik ASC"
            ),
            'iptal_sartlari' => $this->iptalSartlari($pdo, $sites),
            'havuz_tipleri' => $this->fetchOptions(
                $pdo,
                "SELECT id, baslik AS title
                 FROM dbo.havuztipitanimlari
                 ORDER BY id ASC"
            ),
            'oda_yatak_tipleri' => $this->fetchOptions(
                $pdo,
                "SELECT id, baslik AS title, degerturu, degerleri, yatakmi
                 FROM yatak_tipleri
                 ORDER BY yatakmi ASC, baslik ASC"
            ),
            'para_birimleri' => [
                ['id' => 'tl', 'title' => 'TL'],
                ['id' => 'pound', 'title' => 'Pound'],
                ['id' => 'dolar', 'title' => 'Dolar'],
                ['id' => 'euro', 'title' => 'Euro'],
            ],
            'mesafe_tipleri' => [
                ['id' => 'arac', 'title' => 'Arac'],
                ['id' => 'yurume', 'title' => 'Yurume'],
            ],
            'fiyat_tipleri' => [
                ['id' => 'gunluk', 'title' => 'Gunluk'],
                ['id' => 'haftalik', 'title' => 'Haftalik'],
            ],
            'ekstra_ucret_tipleri' => $this->extraPaymentTypes($pdo),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extraPaymentTypes(PDO $pdo): array
    {
        try {
            $rows = $this->fetchOptions(
                $pdo,
                "SELECT ExtraPaymentTypeId AS id, Name AS title, Code AS code, HomeColumnBase AS home_column_base
                 FROM dbo.HomesExtraPaymentTypes
                 WHERE IsDeleted = 0
                 ORDER BY ExtraPaymentTypeId ASC"
            );
            if ($rows !== []) {
                return $rows;
            }
        } catch (\PDOException $e) {
        }

        return [
            ['id' => 'hasar', 'title' => 'Hasar Depozitosu', 'code' => 'hasar', 'home_column_base' => 'hasar'],
            ['id' => 'temizlik', 'title' => 'Temizlik', 'code' => 'temizlik', 'home_column_base' => 'temizlik'],
            ['id' => 'elektrik', 'title' => 'Elektrik-Su', 'code' => 'elektrik', 'home_column_base' => 'elektrik'],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function iptalSartlari(PDO $pdo, array $sites): array
    {
        $items = [];
        foreach ($sites as $site) {
            $suffix = $this->safeDbTableSuffix((string) ($site['dbtable'] ?? ''));
            $siteId = (int) ($site['id'] ?? 0);
            $pageId = $this->cancellationPolicyPageId($siteId, $suffix);
            if ($pageId <= 0) {
                continue;
            }

            foreach ($this->fetchOptions(
                $pdo,
                "SELECT id, baslik AS title
                 FROM sayfalar{$suffix}
                 WHERE aktif = 1 AND id = {$pageId}
                 ORDER BY siralama ASC"
            ) as $row) {
                $row['site_id'] = $siteId;
                $row['dbtable'] = $suffix;
                $row['name'] = 'iptal_politikasi' . $suffix;
                $items[] = $row;
            }
        }

        return $items;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function evSahipleri(PDO $pdo): array
    {
        $owners = [[
            'id' => 1,
            'title' => 'Admin',
            'tokens' => 'Admin',
            'phone' => '',
            'profile_url' => 'profil.asp?id=1',
        ]];

        foreach ($this->fetchOptions(
            $pdo,
            "SELECT id,
                    LTRIM(RTRIM(ISNULL(ad, '') + ' ' + ISNULL(soyad, ''))) AS title,
                    LTRIM(RTRIM(ISNULL(ad, '') + ' ' + ISNULL(soyad, ''))) AS tokens,
                    tel AS phone
             FROM kullanici
             WHERE aktif = 1 AND yetki = 2 AND id <> 1
             ORDER BY ad ASC, soyad ASC"
        ) as $owner) {
            $owner['profile_url'] = 'profil.asp?id=' . (int) ($owner['id'] ?? 0);
            $owners[] = $owner;
        }

        return $owners;
    }

    private function cancellationPolicyPageId(int $siteId, string $suffix): int
    {
        $config = $this->app['homes_management_cancellation_policy_page_id'] ?? 4;
        if (!is_array($config)) {
            return is_numeric($config) ? (int) $config : 4;
        }

        $keys = [$siteId, (string) $siteId, $suffix];
        foreach ($keys as $key) {
            if (array_key_exists($key, $config) && is_numeric($config[$key])) {
                return (int) $config[$key];
            }
        }

        return 4;
    }

    /**
     * @return array<string,mixed>
     */
    private function home(PDO $pdo, int $id): array
    {
        if ($id <= 0) {
            return [];
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT
                    homes.*,
                    (SELECT baslik FROM tip WHERE tip.id = homes.emlak_tipi) AS emlak_tipi_baslik
                 FROM homes
                 WHERE id = :id"
            );
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }

        return is_array($row) ? $row : [];
    }

    /**
     * @param array<int,array<string,mixed>> $parents
     * @param array<string,mixed> $home
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function tipKategorileri(PDO $pdo, array $parents, array $home): array
    {
        $selectedIds = [];
        foreach ($this->intList((string) ($home['kategori'] ?? ''), ',') as $id) {
            $selectedIds[$id] = true;
        }

        $emlakTipi = (int) ($home['emlak_tipi'] ?? 0);
        if ($emlakTipi > 0) {
            $selectedIds[$emlakTipi] = true;
        }

        $rows = [];
        foreach ($parents as $parent) {
            $cat = (int) ($parent['id'] ?? 0);
            if ($cat <= 0) {
                continue;
            }

            $children = $this->fetchOptions(
                $pdo,
                "SELECT id, baslik AS title
                 FROM tip
                 WHERE cat = {$cat} AND aktif = 1
                 ORDER BY siralama ASC"
            );

            $rows[$cat] = array_map(function (array $row) use ($selectedIds): array {
                $id = (int) ($row['id'] ?? 0);

                return [
                    'id' => $id,
                    'baslik' => $row['title'] ?? '',
                    'selected' => isset($selectedIds[$id]),
                ];
            }, $children);
        }

        return $rows;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchSites(PDO $pdo): array
    {
        try {
            $sites = $this->fetchOptions(
                $pdo,
                'SELECT id, site AS title, kisa, dbtable FROM sites ORDER BY id ASC'
            );
            if ($sites) {
                return $sites;
            }
        } catch (\PDOException $e) {
        }

        global $SITES;
        if (isset($SITES) && is_array($SITES)) {
            $sites = [];
            foreach ($SITES as $id => $site) {
                if (!is_array($site)) {
                    continue;
                }

                $sites[] = [
                    'id' => (int) $id,
                    'title' => $site['site'] ?? ('Site ' . (int) $id),
                    'kisa' => $site['kisa'] ?? '',
                    'dbtable' => $site['dbtable'] ?? '',
                ];
            }

            if ($sites) {
                return $sites;
            }
        }

        return [[
            'id' => 1,
            'title' => 'Site 1',
            'kisa' => '',
            'dbtable' => '',
        ]];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchOptions(PDO $pdo, string $sql): array
    {
        try {
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }

        return array_map(function (array $row): array {
            $item = [];
            foreach ($row as $key => $value) {
                if (($key === 'id' || $key === 'parent_id') && is_numeric($value) && preg_match('/^-?\d+$/', (string) $value) === 1) {
                    $item[$key] = (int) $value;
                    continue;
                }

                $item[$key] = $value === null ? '' : $value;
            }

            return $item;
        }, $rows);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function buildTree(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $row['children'] = [];
            $items[$id] = $row;
        }

        $tree = [];
        foreach ($items as $id => &$item) {
            $parentId = (int) ($item['parent_id'] ?? 0);
            if ($parentId > 0 && isset($items[$parentId])) {
                $items[$parentId]['children'][] = &$item;
                continue;
            }

            $tree[] = &$item;
        }
        unset($item);

        return $tree;
    }

    /**
     * @return array<string,mixed>
     */
    private function selectedValues(array $row, array $sites): array
    {
        if ($row === []) {
            return [];
        }

        $iptalPolitikalari = [];
        foreach ($sites as $site) {
            $siteId = (int) ($site['id'] ?? 0);
            if ($siteId <= 0) {
                continue;
            }

            $suffix = $this->safeDbTableSuffix((string) ($site['dbtable'] ?? ''));
            $column = 'iptal_politikasi' . $suffix;
            $value = $row[$column] ?? '';

            $iptalPolitikalari[] = [
                'site_id' => $siteId,
                'site' => $site['title'] ?? '',
                'kisa' => $site['kisa'] ?? '',
                'dbtable' => $suffix,
                'name' => $column,
                'value' => is_numeric($value) ? (int) $value : 0,
            ];
        }

        return [
            'emlaktipi' => (int) ($row['emlak_tipi'] ?? 0),
            'kategori' => $this->intList((string) ($row['kategori'] ?? ''), ','),
            'bolge' => (int) ($row['emlak_bolgesi'] ?? 0),
            'ozellikler' => $this->intList((string) ($row['ozellikler'] ?? ''), '#'),
            'onecikan' => $this->intList((string) ($row['onecikan'] ?? ''), ','),
            'etiketler' => $this->filledValues($row, ['ribbon', 'ribbon2']),
            'kurallar' => $this->intList((string) ($row['kurallar'] ?? ''), ','),
            'dahilhizmetler' => $this->intList((string) ($row['fiyata_dahil'] ?? ''), '#'),
            'parabirimi' => $this->currencyCode($row['doviz'] ?? ''),
            'hasar' => $this->numericValue($this->firstValueFrom($row, ['hasar'])),
            'temizlik' => $this->numericValue($this->firstValueFrom($row, ['temizlik'])),
            'elektrik' => $this->numericValue($this->firstValueFrom($row, ['elektrik'])),
            'sabitEkstraUcretler' => [
                'hasarDepozitosu' => $this->numericValue($this->firstValueFrom($row, ['hasar'])),
                'temizlik' => $this->numericValue($this->firstValueFrom($row, ['temizlik'])),
                'elektrikSu' => $this->numericValue($this->firstValueFrom($row, ['elektrik'])),
            ],
            'onodemeyontemi' => (int) ($row['FirstPaymentTypeId'] ?? 0),
            'evsahibi' => (int) ($row['evsahibi'] ?? 0),
            'iptalpolitikasi' => (int) ($row['iptal_politikasi'] ?? 0),
            'iptal_politikalari' => $iptalPolitikalari,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $keys
     * @return array<int,string>
     */
    private function filledValues(array $row, array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $keys
     */
    private function firstValueFrom(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                return (string) $row[$key];
            }
        }

        return '';
    }

    /**
     * @param mixed $value
     * @return int|float|string
     */
    private function numericValue($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        return is_numeric($value) ? 0 + $value : $value;
    }

    private function safeDbTableSuffix(string $suffix): string
    {
        if ($suffix === '' || preg_match('/^_[A-Za-z0-9]+$/', $suffix) === 1) {
            return $suffix;
        }

        return '';
    }

    /**
     * @return array<int,int>
     */
    private function intList(string $value, string $delimiter): array
    {
        $items = [];
        foreach (explode($delimiter, $value) as $part) {
            $part = trim($part);
            if (is_numeric($part) && (int) $part > 0) {
                $items[] = (int) $part;
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * @param mixed $value
     */
    private function currencyCode($value): string
    {
        $currency = strtolower(trim((string) $value));

        switch ($currency) {
            case 'tl':
            case 'try':
                return 'tl';
            case 'dolar':
            case 'usd':
                return 'dolar';
            case 'euro':
            case 'eur':
                return 'euro';
            case 'pound':
            case 'gbp':
                return 'pound';
            default:
                return $currency;
        }
    }

}
