<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;

/*
 * Kısa link (teklif linki) kaynağı.
 *   POST /api/links
 *   Body (JSON):
 *   {
 *     "ids":   [5648, 1234, 9876],
 *     "start": "2026-06-16",
 *     "end":   "2026-06-23",
 *     "sure":  3,
 *     "teklifId": 949715
 *   }
 * (Eski create-link.php'nin yeni iskelete taşınmış hâli — POST örneği.)
 */
final class LinksController extends Controller
{
    /**
     * Seçilen villalar için kısa yönlendirme linki oluşturur.
     *
     * @Post
     * @body ids array required Villa kimlikleri
     * @body start string Başlangıç tarihi (YYYY-MM-DD)
     * @body end string Bitiş tarihi (YYYY-MM-DD)
     * @body sure int Geçerlilik süresi (gün)
     * @body teklifId int Bağlı teklif kimliği
     */
    public function create(): void
    {
        // 1) Gövdeyi oku (JSON; değilse $_POST'a düşer)
        $ids      = $this->request->input('ids', []);
        $start    = trim((string) $this->request->input('start', ''));
        $end      = trim((string) $this->request->input('end', ''));
        $sure     = (int) $this->request->input('sure', 0);
        $teklifId = $this->request->input('teklifId');
        $teklifId = !empty($teklifId) ? (int) $teklifId : null;

        // 2) Doğrula
        $intIds = array_map('intval', is_array($ids) ? $ids : []);
        $validIds = array_values(array_filter($intIds, function ($v) {
            return $v > 0;
        }));

        if ($validIds === []) {
            throw new HttpException('Lütfen listeden en az bir villa seçiniz.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();

        // 3) Sıradaki id'den eşsiz link üret
        $nextRow = $pdo->query('SELECT ISNULL(MAX(id), 0) + 1 AS nextId FROM redirects')->fetch();
        $nextId = (int) ($nextRow['nextId'] ?? 1);
        $originalLink = $this->randomString(4) . $nextId;

        // 4) Yönlendirme hedefini kur (arama sayfası DB'den, Domain dış config'ten)
        $urlRow = $pdo->query('SELECT url FROM tip WHERE id = 1')->fetch();
        $aramaSayfasi = (string) ($urlRow['url'] ?? '');
        $domain = defined('Domain') ? (string) constant('Domain') : '';

        $params = ['ids' => implode(',', $validIds)];
        if ($start !== '') {
            $params['start'] = $start;
        }
        if ($end !== '') {
            $params['end'] = $end;
        }
        if ($sure > 0) {
            $params['sure'] = $sure;
        }
        $redirectTo = $domain . '/' . $aramaSayfasi . '?' . urldecode(http_build_query($params));

        // 5) Süre verildiyse son kullanma tarihini hesapla
        $expiredMode = 0;
        $expiredDate = null;
        if ($sure > 0) {
            $expiredMode = 1;
            $date = new \DateTime();
            $date->modify('+' . $sure . ' days');
            $expiredDate = $date->format('Y-m-d H:i:s');
        }

        // 6) Kaydet
        $stmt = $pdo->prepare(
            'INSERT INTO redirects (originalLink, teklifId, redirectTo, expiredDate, expiredMode)
             VALUES (:originalLink, :teklifId, :redirectTo, :expiredDate, :expiredMode)'
        );
        $stmt->execute([
            ':originalLink' => $originalLink,
            ':teklifId'     => $teklifId,
            ':redirectTo'   => $redirectTo,
            ':expiredDate'  => $expiredDate,
            ':expiredMode'  => $expiredMode,
        ]);

        // 7) Standart başarı zarfı
        $finalLink = str_replace('www.', '', $domain) . '/' . $originalLink . '?v';

        $this->response->success([
            'link'         => $finalLink,
            'originalLink' => $originalLink,
            'teklifId'     => $teklifId,
        ], 201);
    }

    private function randomString(int $length = 4): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $max = strlen($chars) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, $max)];
        }

        return $out;
    }
}
