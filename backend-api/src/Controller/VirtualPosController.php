<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Rezervasyon detayındaki "POS" seçimi için seçenekler ve rezervasyonun seçili POS'u. Sadece okuma.
 *   GET /backend-api/virtual-pos?id=99187
 *
 * Yapı rezervasyona göre seçilir (panel siparis_detaylari.asp "Kiralama Ödeme Bilgileri"):
 *   - kayitlar.isNewSite = 1 (React site) -> config reservation_pos.new_site (varsayılan Finance.VirtualPos)
 *   - diğerleri                         -> config reservation_pos.legacy (her panelde farklı, app.local.php ile ayarlanır)
 * POS sadece reservation_pos.editable_statuses durumlarında değiştirilebilir; onaylı (3) ve kartlı
 * rezervasyonda kilitli gösterilir; diğer durumlarda gösterilmez (gorunur = false).
 */
final class VirtualPosController extends Controller
{
    /**
     * @Get
     * @query id int required Rezervasyon kimliği
     */
    public function index(): void
    {
        $id = (int) $this->request->query('id', 0);
        if ($id <= 0) {
            throw new HttpException('Lütfen geçerli bir rezervasyon ID gönderin.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT * FROM kayitlar WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$reservation) {
            throw new HttpException('Belirtilen ID ile rezervasyon bulunamadı.', 'NOT_FOUND', 404);
        }

        $stmt = $pdo->prepare('SELECT TOP 1 durum FROM dolu WHERE kayitid = :id');
        $stmt->execute([':id' => $id]);
        $doluRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $status = $this->normalizeStatus($doluRow ? $doluRow['durum'] : null);

        $settings = $this->settings();
        $isNewSite = $this->truthy($reservation['isNewSite'] ?? null);
        $structure = $isNewSite ? 'new_site' : 'legacy';
        $config = $settings[$structure];

        $siteId = (int) ($reservation['site'] ?? 1);
        $selectedColumn = $this->column($config['selected_column'] ?? '');
        $selectedId = null;
        if ($selectedColumn !== '' && isset($reservation[$selectedColumn]) && is_numeric($reservation[$selectedColumn])) {
            $selectedId = (int) $reservation[$selectedColumn];
        }

        // Görünürlük/kilit (panel): 0,1,2,6 -> değiştirilebilir; 3 + kart (odeme = 1) -> kilitli; diğerleri gizli.
        $editableStatuses = array_map('intval', (array) $settings['editable_statuses']);
        $cardPayment = (int) ($reservation['odeme'] ?? 0) === 1;
        $editable = $status !== null && in_array($status, $editableStatuses, true);
        $locked = !$editable && $status === 3 && $cardPayment;

        $options = $this->filterHidden(
            $this->options($pdo, $config),
            $settings['hidden_ids_by_site'][$siteId] ?? ($settings['hidden_ids_by_site'][(string) $siteId] ?? []),
            $selectedId
        );

        // İyzico Pos (Aktif/Pasif): panelde sadece değiştirilebilir durumlarda gösterilir.
        $iyzico = null;
        $iyzicoColumn = $this->column($config['iyzico_column'] ?? '');
        if ($iyzicoColumn !== '' && array_key_exists($iyzicoColumn, $reservation) && $editable) {
            $iyzico = ['aktif' => $this->truthy($reservation[$iyzicoColumn])];
        }

        $this->response->success([
            'yapi' => $structure,
            'isNewSite' => $isNewSite,
            'durum' => $status,
            'gorunur' => $editable || $locked,
            'kilitli' => $locked,
            'seciliId' => $selectedId,
            'secenekler' => $options,
            'iyzico' => $iyzico,
        ]);
    }

    /** config reservation_pos: app.php varsayılanı + app.local.php (alt anahtarlar tek tek ezilir). */
    private function settings(): array
    {
        $defaults = [
            'new_site' => [
                'options' => [],
                'options_query' => 'SELECT Id AS id, Name AS name FROM Finance.VirtualPos ORDER BY Id',
                'selected_column' => 'VirtualPosId',
                'iyzico_column' => '',
            ],
            'legacy' => [
                'options' => [],
                'options_query' => 'SELECT Id AS id, Name AS name FROM Finance.VirtualPos ORDER BY Id',
                'selected_column' => 'VirtualPosId',
                'iyzico_column' => 'iyzico_odeme',
            ],
            // app.local.php reservation_pos'u bütün olarak ezer; yazılmayan alt anahtarlar app.php ile aynı kalsın.
            'hidden_ids_by_site' => [3 => [1, 2, 3]],
            'editable_statuses' => [0, 1, 2, 6],
        ];
        $custom = $this->app['reservation_pos'] ?? [];
        if (!is_array($custom)) {
            return $defaults;
        }
        foreach (['new_site', 'legacy'] as $key) {
            if (isset($custom[$key]) && is_array($custom[$key])) {
                $defaults[$key] = array_merge($defaults[$key], $custom[$key]);
            }
        }
        foreach (['hidden_ids_by_site', 'editable_statuses'] as $key) {
            if (isset($custom[$key]) && is_array($custom[$key])) {
                $defaults[$key] = $custom[$key];
            }
        }

        return $defaults;
    }

    /**
     * Seçenekler: config 'options' doluysa sabit liste ([id => ad]), değilse 'options_query'
     * (ilk iki kolon ya da id/name alias'ları). Tablo yoksa ya da sorgu hatalıysa boş liste.
     *
     * @return array<int, array{id:int, ad:string}>
     */
    private function options(PDO $pdo, array $config): array
    {
        $list = [];
        if (!empty($config['options']) && is_array($config['options'])) {
            foreach ($config['options'] as $key => $value) {
                if (is_array($value)) {
                    $list[] = ['id' => (int) ($value['id'] ?? 0), 'ad' => trim((string) ($value['ad'] ?? $value['name'] ?? ''))];
                } else {
                    $list[] = ['id' => (int) $key, 'ad' => trim((string) $value)];
                }
            }

            return $list;
        }

        $query = trim((string) ($config['options_query'] ?? ''));
        if ($query === '') {
            return [];
        }
        try {
            $rows = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($rows as $row) {
            $lower = array_change_key_case($row, CASE_LOWER);
            $values = array_values($row);
            $rowId = $lower['id'] ?? ($values[0] ?? 0);
            $name = $lower['name'] ?? ($lower['ad'] ?? ($values[1] ?? ''));
            $list[] = ['id' => (int) $rowId, 'ad' => trim((string) $name)];
        }

        return $list;
    }

    /** Panel: bazı sitelerde belirli POS'lar, rezervasyonda seçili değilse listelenmez (site 3: 1, 2, 3). */
    private function filterHidden(array $options, $hiddenIds, $selectedId): array
    {
        $hidden = array_map('intval', is_array($hiddenIds) ? $hiddenIds : []);
        if (!$hidden) {
            return $options;
        }

        return array_values(array_filter($options, function ($option) use ($hidden, $selectedId) {
            return !in_array($option['id'], $hidden, true) || $option['id'] === $selectedId;
        }));
    }

    /** Config'ten gelen kolon adı: sadece harf/rakam/alt çizgi. */
    private function column($name): string
    {
        $name = trim((string) $name);

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) ? $name : '';
    }

    /** @param mixed $value */
    private function truthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $value = strtolower(trim((string) $value));

        return $value === '1' || $value === 'true';
    }
}
