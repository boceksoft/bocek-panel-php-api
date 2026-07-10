<?php

declare(strict_types=1);

namespace App\Controller;

/*
 * Arama filtreleri kaynağı.
 *   GET /backend-api/filters
 * Villa tipleri, özellikler, bölge ağacı ve statik filtreleri döner.
 * (Eski get-filters.php taşınmış hâli.)
 */
final class FiltersController extends Controller
{
    /**
     * @Get
     */
    public function index(): void
    {
        $pdo = $this->db->pdo();

        // Villa tipleri
        $types = $pdo->query(
            "SELECT id, baslik FROM tip WHERE aktif=1 AND search=1 AND cat != 0 ORDER BY siralama ASC"
        )->fetchAll();

        // Özellikler
        $features = $pdo->query(
            "SELECT id, baslik FROM ozellikler WHERE aktif=1 ORDER BY siralama ASC"
        )->fetchAll();

        // Bölgeler (hiyerarşik ağaç)
        $allDestinations = $pdo->query(
            "SELECT id, baslik, cat FROM destinations WHERE aktif=1 ORDER BY siralama ASC"
        )->fetchAll();

        $regions = $this->buildRegionTree($allDestinations);

        $this->response->success([
            'types'    => $types,
            'features' => $features,
            'regions'  => $regions,
            'static_filters' => [
                'currencies' => [
                    ['id' => 'tl', 'label' => 'TL'],
                    ['id' => 'dolar', 'label' => 'USD'],
                    ['id' => 'euro', 'label' => 'EUR'],
                    ['id' => 'pound', 'label' => 'GBP'],
                ],
                'capacities' => range(1, 15),
                'order_by' => [
                    ['id' => 0, 'label' => 'Gelişmiş Sıralama'],
                    ['id' => 1, 'label' => 'Tarihe Göre (Önce En Eski)'],
                    ['id' => 2, 'label' => 'Tarihe Göre (Önce En Yeni)'],
                    ['id' => 3, 'label' => 'Fiyata Göre (Önce En Düşük)'],
                    ['id' => 4, 'label' => 'Fiyata Göre (Önce En Yüksek)'],
                    ['id' => 5, 'label' => 'Kişiye Göre (Önce En Az)'],
                    ['id' => 6, 'label' => 'Kişiye Göre (Önce En Çok)'],
                ],
                'gavel_rules' => [
                    ['id' => 1, 'label' => '7464 Satışa Açık Süreli Belgeli Emlaklar'],
                    ['id' => 2, 'label' => '7464 Satışa Açık Süresiz Belgeli Emlaklar'],
                    ['id' => 3, 'label' => '7464 Belgesiz Emlaklar'],
                    ['id' => 0, 'label' => 'Tümü'],
                ],
                'calendar_rules' => [
                    ['id' => 0, 'label' => 'Tümü'],
                    ['id' => 1, 'label' => 'Takvim Kuralına Göre'],
                ],
            ],
        ]);
    }

    /**
     * Rezervasyon listeleme ekranindaki dropdown filtrelerini dondurur.
     *
     * @Get("reservations")
     */
    public function reservations(): void
    {
        $pdo = $this->db->pdo();

        $homes = $pdo->query(
            "SELECT id, baslik
             FROM homes
             ORDER BY baslik ASC"
        )->fetchAll();

        $salesChannels = $pdo->query(
            "SELECT id, baslik
             FROM satis_kanallari
             ORDER BY baslik ASC"
        )->fetchAll();

        $sites = [];
        try {
            $sitesRaw = $pdo->query(
                "SELECT id, site
                 FROM tip
                 WHERE aktif = 1
                   AND cat = 0
                 ORDER BY id ASC"
            )->fetchAll();

            $sites = array_map(function (array $row): array {
                return [
                    'id'    => 'site_' . $row['id'],
                    'label' => 'Site [' . $row['site'] . ']',
                ];
            }, $sitesRaw);
        } catch (\PDOException $e) {
            $sites = [];
        }

        $agencyOptions = array_merge(
            array_map(function (array $row): array {
                return [
                    'id' => $row['id'],
                    'label' => $row['label'],
                    'type' => 'site',
                ];
            }, $sites),
            array_map(function (array $row): array {
                return [
                    'id' => (string) $row['id'],
                    'label' => $row['baslik'],
                    'type' => 'satis_kanali',
                ];
            }, $salesChannels)
        );

        $subAgencies = [];
        if (!empty($this->app['reservation_filters_use_acenta_users'])) {
            try {
                $subAgencies = $pdo->query(
                    "SELECT id, agencyName
                     FROM acenta_users
                     ORDER BY agencyName ASC"
                )->fetchAll();
            } catch (\PDOException $e) {
                $subAgencies = [];
            }
        }

        $this->response->success([
            'homes' => $homes,
            'satis_kanallari' => $salesChannels,
            'sites' => $sites,
            'acentalar' => $agencyOptions,
            'alt_acentalar' => $subAgencies,
            'acenta_users' => $subAgencies,
            'table_colons' => $this->reservationTableColumns(),
            'static_filters' => [
                'durum' => [
                    ['id' => 0, 'label' => 'Onay Bekliyor'],
                    ['id' => 1, 'label' => 'Ödeme Bekliyor'],
                    ['id' => 2, 'label' => 'Süresi Doldu'],
                    ['id' => 3, 'label' => 'Onaylı'],
                    ['id' => 4, 'label' => 'İptal'],
                    ['id' => 5, 'label' => 'Silinenler'],
                    ['id' => 6, 'label' => 'Açık Rezervasyon'],
                ],
                'odemesekli' => [
                    ['id' => 1, 'label' => 'Kredi Kartı'],
                    ['id' => 2, 'label' => 'Havale'],
                    ['id' => 3, 'label' => 'Western Union'],
                    ['id' => 4, 'label' => 'Sanal Kart'],
                    ['id' => 5, 'label' => 'Sanal Havale'],
                    ['id' => 6, 'label' => 'Nakit'],
                ],
                'odemeturu' => [
                    ['id' => 1, 'label' => 'Ön Ödeme'],
                    ['id' => 2, 'label' => 'Tamamı'],
                ],
                'kisibilgileri' => [
                    ['id' => '1', 'label' => 'Girilenler'],
                    ['id' => '2', 'label' => 'Girilmeyenler'],
                ],
                'gavelkurali' => [
                    ['id' => 1, 'label' => '7464 Satışa Açık Süreli Belgeli Emlaklar'],
                    ['id' => 2, 'label' => '7464 Satışa Açık Süresiz Belgeli Emlaklar'],
                    ['id' => 3, 'label' => '7464 Belgesiz Emlaklar'],
                ],
            ],
        ]);
    }

    /**
     * ASP siparis_yonetimi.asp table_colons secenekleri.
     *
     * @return array<int,array{id:string,baslik:string}>
     */
    private function reservationTableColumns(): array
    {
        return [
            ['id' => 'rezNo', 'baslik' => 'Rez. No'],
            ['id' => 'evadi', 'baslik' => 'Villa Adı'],
            ['id' => 'musteri', 'baslik' => 'Müşteri Adı'],
            ['id' => 'telefon', 'baslik' => 'Telefon'],
            ['id' => 'islemTarihi', 'baslik' => 'Rez. Tarihi'],
            ['id' => 'girisTarihi', 'baslik' => 'Giriş Tarihi'],
            ['id' => 'cikisTarihi', 'baslik' => 'Çıkış Tarihi'],
            ['id' => 'toplamTutar', 'baslik' => 'Toplam Tutar'],
            ['id' => 'onOdeme', 'baslik' => 'Ön Ödeme'],
            ['id' => 'temizlik', 'baslik' => 'Temizlik'],
            ['id' => 'odemeSekli', 'baslik' => 'Ödeme Şekli'],
            ['id' => 'kalan', 'baslik' => 'Kalan'],
            ['id' => 'durum', 'baslik' => 'Durum'],
            ['id' => 'acentaRezNo', 'baslik' => 'Acenta Rez. No'],
            ['id' => 'acentaVillaAdi', 'baslik' => 'Acenta Villa Adı'],
            ['id' => 'acentaRezTarihi', 'baslik' => 'Acenta Rez. Tarihi'],
            ['id' => 'acentaRezToplamTutar', 'baslik' => 'Acenta Rez. Toplam Tutar'],
            ['id' => 'acentaRezKomisyon', 'baslik' => 'Acenta Rez. Komisyon'],
            ['id' => 'acentaRezDurum', 'baslik' => 'Acenta Rez. Durum'],
            ['id' => 'gece', 'baslik' => 'Gece'],
            ['id' => 'satis', 'baslik' => 'Satış'],
            ['id' => 'alis', 'baslik' => 'Alış'],
            ['id' => 'kar', 'baslik' => 'Kar'],
            ['id' => 'odenen', 'baslik' => 'Ödenen'],
            ['id' => 'odenenOran', 'baslik' => 'Ödenen Oran'],
            ['id' => 'odemeTarihi', 'baslik' => 'Ödeme Tarihi'],
            ['id' => 'doviz', 'baslik' => 'Döviz'],
            ['id' => 'kur', 'baslik' => 'Kur'],
        ];

        return [
            ['id' => 'rezNo', 'label' => 'Rez. No', 'default' => true, 'orderable' => true, 'class' => 'width-51px'],
            ['id' => 'evadi', 'label' => 'Villa Adı', 'default' => true, 'orderable' => true, 'class' => 'default'],
            ['id' => 'musteri', 'label' => 'Müşteri Adı', 'default' => true, 'orderable' => true, 'class' => 'default'],
            ['id' => 'telefon', 'label' => 'Telefon', 'default' => true, 'orderable' => true, 'class' => 'default'],
            ['id' => 'islemTarihi', 'label' => 'Rez. Tarihi', 'default' => true, 'orderable' => true, 'class' => 'default'],
            ['id' => 'girisTarihi', 'label' => 'Giriş Tarihi', 'default' => true, 'orderable' => true, 'class' => 'default'],
            ['id' => 'cikisTarihi', 'label' => 'Çıkış Tarihi', 'default' => true, 'orderable' => true, 'class' => 'default'],
            ['id' => 'toplamTutar', 'label' => 'Toplam Tutar', 'default' => true, 'orderable' => true, 'class' => 'default'],
            ['id' => 'onOdeme', 'label' => 'Ön Ödeme', 'default' => true, 'orderable' => true, 'class' => 'default'],
            ['id' => 'temizlik', 'label' => 'Temizlik', 'default' => false, 'orderable' => true, 'class' => 'default'],
            ['id' => 'odemeSekli', 'label' => 'Ödeme Şekli', 'default' => true, 'orderable' => true, 'class' => 'default'],
            ['id' => 'kalan', 'label' => 'Kalan', 'default' => true, 'orderable' => true, 'class' => 'default'],
            ['id' => 'durum', 'label' => 'Durum', 'default' => true, 'orderable' => true, 'class' => 'default'],
            ['id' => 'acentaRezNo', 'label' => 'Acenta Rez. No', 'default' => false, 'orderable' => true, 'class' => 'default'],
            ['id' => 'acentaVillaAdi', 'label' => 'Acenta Villa Adı', 'default' => false, 'orderable' => true, 'class' => 'default'],
            ['id' => 'acentaRezTarihi', 'label' => 'Acenta Rez. Tarihi', 'default' => false, 'orderable' => true, 'class' => 'default'],
            ['id' => 'acentaRezToplamTutar', 'label' => 'Acenta Rez. Toplam Tutar', 'default' => false, 'orderable' => true, 'class' => 'default'],
            ['id' => 'acentaRezKomisyon', 'label' => 'Acenta Rez. Komisyon', 'default' => false, 'orderable' => true, 'class' => 'default'],
            ['id' => 'acentaRezDurum', 'label' => 'Acenta Rez. Durum', 'default' => false, 'orderable' => true, 'class' => 'default'],
            ['id' => 'gece', 'label' => 'Gece', 'default' => false, 'orderable' => true, 'class' => 'default'],
            ['id' => 'satis', 'label' => 'Satış', 'default' => false, 'orderable' => true, 'class' => 'default'],
            ['id' => 'alis', 'label' => 'Alış', 'default' => false, 'orderable' => true, 'class' => 'default'],
            ['id' => 'kar', 'label' => 'Kar', 'default' => false, 'orderable' => true, 'class' => 'default'],
            ['id' => 'odenen', 'label' => 'Ödenen', 'default' => false, 'orderable' => true, 'class' => 'default'],
            ['id' => 'odenenOran', 'label' => 'Ödenen Oran', 'default' => false, 'orderable' => true, 'class' => 'default'],
            ['id' => 'odemeTarihi', 'label' => 'Ödeme Tarihi', 'default' => false, 'orderable' => true, 'class' => 'default'],
            ['id' => 'doviz', 'label' => 'Döviz', 'default' => false, 'orderable' => true, 'class' => 'default'],
            ['id' => 'kur', 'label' => 'Kur', 'default' => false, 'orderable' => true, 'class' => 'default'],
        ]; 
    }

    /**
     * Duz destinasyon listesini ust/alt bolge agacina cevirir (cat = ust id).
     *
     * @param array<int,array> $destinations
     * @return array<int,array>
     */
    private function buildRegionTree(array $destinations): array
    {
        $map = [];
        foreach ($destinations as $dest) {
            $dest['sub_regions'] = [];
            $map[$dest['id']] = $dest;
        }

        $regions = [];
        foreach ($destinations as $dest) {
            if ((int) $dest['cat'] === 0) {
                $regions[] = &$map[$dest['id']];
            } elseif (isset($map[$dest['cat']])) {
                $map[$dest['cat']]['sub_regions'][] = &$map[$dest['id']];
            }
        }
        unset($dest);

        return $regions;
    }
}
