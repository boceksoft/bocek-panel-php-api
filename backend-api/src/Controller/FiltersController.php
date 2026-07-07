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
     * Düz destinasyon listesini üst/alt bölge ağacına çevirir (cat = üst id).
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
