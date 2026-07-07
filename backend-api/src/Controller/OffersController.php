<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Teklifler kaynağı.
 *   GET /backend-api/offers?site=1&page=1&per_page=50   -> liste
 *   GET /backend-api/offers/detail?id=123               -> tek teklif detayı
 * (Eski get-offers.php + get-offers-detail.php taşınmış hâli.)
 */
final class OffersController extends Controller
{
    const DEFAULT_PER_PAGE = 50;

    /**
     * Teklifleri sayfalı listeler.
     *
     * @Get
     * @query site int Site kimliği (varsayılan 1)
     * @query page int Sayfa numarası (varsayılan 1)
     * @query per_page int Sayfa başına kayıt (varsayılan 50)
     */
    public function index(): void
    {
        $siteId  = (int) $this->request->query('site', 1);
        $page    = max(1, (int) $this->request->query('page', 1));
        $perPage = max(1, (int) $this->request->query('per_page', self::DEFAULT_PER_PAGE));
        $offset  = ($page - 1) * $perPage;

        $sql = "SELECT
                    COUNT(*) OVER() AS totalCount,
                    t.id, t.isim, t.email, t.telefon, t.kisi,
                    t.parametreler, t.createdOn, t.site, t.link,
                    (CASE
                        WHEN EXISTS (SELECT 1 FROM dbo.redirects r WHERE r.teklifId = t.id)
                        THEN 'Cevaplandı'
                        ELSE 'Yeni'
                    END) AS durum
                FROM dbo.teklifler t
                WHERE t.site = :site
                ORDER BY t.createdOn DESC
                OFFSET :offset ROWS FETCH NEXT :perPage ROWS ONLY";

        $stmt = $this->db->pdo()->prepare($sql);
        // SQL Server'da OFFSET/FETCH parametreleri INT olmak zorunda.
        $stmt->bindValue(':site', $siteId, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':perPage', $perPage, PDO::PARAM_INT);
        $stmt->execute();

        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // totalCount tüm satırlarda tekrar eder; tek sefer alıp satırlardan temizliyoruz.
        $total = $items !== [] ? (int) $items[0]['totalCount'] : 0;
        foreach ($items as &$item) {
            unset($item['totalCount']);
        }
        unset($item);

        $this->response->success([
            'items' => $items,
            'meta' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
                'count'       => count($items),
            ],
        ]);
    }

    /**
     * Tek bir teklifin detayını, parametreleri çözümlenmiş şekilde döner.
     *
     * @Get("detail")
     * @query id int required Teklif kimliği
     */
    public function detail(): void
    {
        $offerId = (int) $this->request->query('id', 0);
        if ($offerId <= 0) {
            throw new HttpException('Lütfen geçerli bir teklif ID gönderin.', 'VALIDATION', 422);
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT id, isim, email, telefon, kisi, parametreler, createdOn, site, link
             FROM dbo.teklifler WHERE id = :id'
        );
        $stmt->bindValue(':id', $offerId, PDO::PARAM_INT);
        $stmt->execute();
        $offer = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$offer) {
            throw new HttpException('Belirtilen ID ile teklif bulunamadı.', 'NOT_FOUND', 404);
        }

        // Ham "parametreler" query-string'ini çözümle
        $parsed = [];
        if (!empty($offer['parametreler'])) {
            parse_str($offer['parametreler'], $parsed);
        }

        // Yetişkin / çocuk / bebek
        $yetiskin = $this->firstInt($parsed, ['adult', 'yetiskin'], !empty($offer['kisi']) ? (int) $offer['kisi'] : 0);
        $cocuk    = $this->firstInt($parsed, ['child', 'cocuk'], 0);
        $bebek    = isset($parsed['infant']) && $parsed['infant'] !== '' ? (int) $parsed['infant'] : 0;

        // Tarihler
        $baslangic = $this->firstStr($parsed, ['start', 'searchdate1']);
        $bitis     = $this->firstStr($parsed, ['end', 'searchdate2']);

        // Bütçe
        $priceType = isset($parsed['priceType']) ? (int) $parsed['priceType']
            : (isset($parsed['pricetype']) ? (int) $parsed['pricetype'] : 0);
        $min = isset($parsed['min']) ? (float) $parsed['min'] : 0;
        $max = isset($parsed['max']) ? (float) $parsed['max'] : 0;

        if ($priceType === 1) {
            $minTL = 1;
            $maxTL = 20000;
        } elseif ($priceType === 2) {
            $minTL = 20000;
            $maxTL = 50000;
        } elseif ($priceType === 3) {
            $minTL = 50000;
            $maxTL = 100000;
        } else {
            $minTL = (int) round($min);
            $maxTL = (int) round($max);
        }
        $butce = $maxTL > 0 ? ($minTL > 0 ? "$minTL - $maxTL" : (string) $maxTL) : null;

        // Kategoriler ve seçilen tipler
        $kategoriler = [];
        if (isset($parsed['categories']) && is_array($parsed['categories'])) {
            $kategoriler = array_map('intval', $parsed['categories']);
        }

        $secilenTipler = [];
        if (!empty($parsed['tip'])) {
            $tipIds = array_values(array_filter(array_map('intval', explode(',', (string) $parsed['tip']))));
            if ($tipIds !== []) {
                $in = implode(',', array_fill(0, count($tipIds), '?'));
                $tipStmt = $this->db->pdo()->prepare("SELECT id, baslik FROM dbo.tip WHERE id IN ($in)");
                $tipStmt->execute($tipIds);
                $secilenTipler = $tipStmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        $this->response->success([
            'id'            => $offer['id'],
            'isim'          => $offer['isim'],
            'email'         => $offer['email'],
            'telefon'       => $offer['telefon'],
            'tarihler'      => ['baslangic' => $baslangic, 'bitis' => $bitis],
            'kisi'          => $yetiskin + $cocuk,
            'kisiBilgileri' => ['yetiskin' => $yetiskin, 'cocuk' => $cocuk, 'bebek' => $bebek],
            'butce'         => $butce,
            'kategoriler'   => $kategoriler,
            'secilenTipler' => $secilenTipler,
            'createdOn'     => $offer['createdOn'],
            'site'          => $offer['site'],
            'link'          => $offer['link'],
            'parametreler'  => $offer['parametreler'],
        ]);
    }

    /**
     * Verilen anahtarlardan ilk dolu olanı int döndürür, yoksa varsayılan.
     *
     * @param string[] $keys
     */
    private function firstInt(array $params, array $keys, int $default): int
    {
        foreach ($keys as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                return (int) $params[$key];
            }
        }

        return $default;
    }

    /**
     * @param string[] $keys
     * @return string|null
     */
    private function firstStr(array $params, array $keys)
    {
        foreach ($keys as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                return (string) $params[$key];
            }
        }

        return null;
    }
}
