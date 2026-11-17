<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Support\KtApi;
use PDO;

/*
 * Hemen Kirala (hızlı kiralama): Kiralama Takvimi'nde "doğrudan kiralanabilir" villalar.
 * Panel karşılığı: BookableDirectly.asp + api/app/classes/BookableDirectly.php.
 *
 * Kaynak Kiralama Takvimi'dir (estate/getbookabledirectlyestates, getnotbookabledirectlyestates,
 * addbookabledirectly, deletebookabledirectly). Ekleme / kaldırma başarılıysa, panel gibi sitedeki
 * KiralamaTakvimi.CalendarHomes.BookableDirectly de 1 / 0 yapılır: site ön yüzü "Hemen Kirala"yı bu
 * bayrağa bakarak gösterir (CalculateController; giriş bugün / yarın değilse ve tarih müsaitse).
 * Ekleme yalnızca takvim kuralları aktif (IsRulesEnabled) villalarda yapılır; kaldırma her zaman serbesttir.
 */
final class KtBookableController extends Controller
{
    /**
     * Villa listesi (Kiralama Takvimi'nden, sayfalı). Her kayda sitedeki villa (homesId) ve sitedeki bayrak eklenir.
     *
     * @Get("list")
     * @query bookable int 1 hemen kiralanabilir villalar, 0 diğerleri
     * @query page int Sayfa (1'den başlar)
     * @query itemCount int Sayfa başına kayıt (en fazla 50)
     * @query search string Villa adı
     * @query joinType int 0 tümü, 1 oluşturduklarım, 2 katıldıklarım
     * @query ruleEnabled string "" tümü, "true" kural aktif, "false" kural pasif
     */
    public function list(): void
    {
        $bookable = (string) $this->request->query('bookable', '1') !== '0';
        $page = max(1, (int) ($this->request->query('page', 1) ?: 1));
        $itemCount = min(50, max(1, (int) ($this->request->query('itemCount', 20) ?: 20)));
        $joinType = (int) $this->request->query('joinType', 0);
        if (!in_array($joinType, [0, 1, 2], true)) {
            $joinType = 0;
        }
        $ruleEnabled = (string) $this->request->query('ruleEnabled', '');
        if ($ruleEnabled !== 'true' && $ruleEnabled !== 'false') {
            $ruleEnabled = '';
        }

        $pdo = $this->db->pdo();
        $kt = new KtApi($pdo, $this->app);
        $response = $kt->request(
            'GET',
            $bookable ? 'estate/getbookabledirectlyestates' : 'estate/getnotbookabledirectlyestates',
            [
                'page' => $page,
                'itemCount' => $itemCount,
                'estateJoinType' => $joinType,
                'estateTitle' => trim((string) $this->request->query('search', '')),
                'isRuleEnabled' => $ruleEnabled,
            ]
        );

        $data = $this->value($response, 'Data');
        $data = is_array($data) ? array_values($data) : [];

        $estateIds = array_values(array_unique(array_filter(array_map(function ($r) {
            return (int) $this->value((array) $r, 'EstateId');
        }, $data))));
        $siteHomes = $this->siteHomes($pdo, $estateIds);
        $ownId = $kt->establishmentId();

        $items = [];
        foreach ($data as $row) {
            $row = (array) $row;
            $estateId = (int) $this->value($row, 'EstateId');
            $images = array_values(array_filter(array_map('trim', explode(',', (string) ($this->value($row, 'EstateImageUrl') ?? '')))));
            $site = $siteHomes[$estateId] ?? null;
            $items[] = [
                'estateId' => $estateId,
                'title' => (string) ($this->value($row, 'EstateTitle') ?? ''),
                'imageUrl' => $images[0] ?? null,
                'establishmentId' => (int) $this->value($row, 'EstablishmentId'),
                // Takvimi bu firma mı oluşturdu (panelde taç simgesi)
                'isOwner' => (string) $this->value($row, 'EstablishmentId') === $ownId,
                'isRulesEnabled' => (bool) $this->value($row, 'IsRulesEnabled'),
                'bookable' => $bookable,
                'homesId' => $site['homesId'] ?? 0,
                'villaName' => $site['villaName'] ?? null,
                // Sitedeki bayrak (CalendarHomes.BookableDirectly); kolon yoksa ya da villa bağlı değilse null
                'siteBookable' => $site['siteBookable'] ?? null,
            ];
        }

        $total = $this->value($response, 'Total');

        $this->response->success([
            'page' => $page,
            'itemCount' => $itemCount,
            'total' => $total !== null ? (int) $total : null,
            'hasMore' => count($data) >= $itemCount,
            'items' => $items,
        ]);
    }

    /**
     * Sitedeki sayılar (CalendarHomes): takvime bağlı villa ve sitede Hemen Kirala açık olanlar.
     *
     * @Get("summary")
     */
    public function summary(): void
    {
        $pdo = $this->db->pdo();
        if (!$this->hasCalendarHomes($pdo)) {
            $this->response->success(['connected' => 0, 'bookable' => 0, 'columnExists' => false]);
            return;
        }
        $column = $this->hasBookableColumn($pdo);
        $row = $pdo->query(
            'SELECT COUNT(DISTINCT EstateId) AS connected'
            . ($column ? ', COUNT(DISTINCT CASE WHEN BookableDirectly = 1 THEN EstateId END) AS bookable' : ', 0 AS bookable')
            . ' FROM KiralamaTakvimi.CalendarHomes WHERE EstateId IS NOT NULL'
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $this->response->success([
            'connected' => (int) ($row['connected'] ?? 0),
            'bookable' => (int) ($row['bookable'] ?? 0),
            'columnExists' => $column,
        ]);
    }

    /**
     * Villayı Hemen Kirala'ya ekler (KT estate/addbookabledirectly) ve sitedeki bayrağı açar.
     *
     * @Post("add")
     * @body estateId int required Kiralama Takvimi EstateId
     */
    public function add(): void
    {
        $this->change(true);
    }

    /**
     * Villayı Hemen Kirala'dan kaldırır (KT estate/deletebookabledirectly) ve sitedeki bayrağı kapatır.
     *
     * @Post("remove")
     * @body estateId int required Kiralama Takvimi EstateId
     */
    public function remove(): void
    {
        $this->change(false);
    }

    /**
     * Sadece sitedeki bayrağı Kiralama Takvimi'ndeki duruma eşitler (KT'ye istek atmaz).
     * Panel KT isteği başarısız olunca ya da elle değişince bayrak geride kalabiliyor.
     *
     * @Post("sync")
     * @body estateId int required Kiralama Takvimi EstateId
     * @body bookable bool required Kiralama Takvimi'ndeki durum
     */
    public function sync(): void
    {
        $estateId = $this->estateId();
        $bookable = filter_var($this->request->input('bookable', false), FILTER_VALIDATE_BOOLEAN);
        $updated = $this->updateSiteFlag($this->db->pdo(), $estateId, $bookable);
        if ($updated === null) {
            throw new HttpException('Sitede BookableDirectly kolonu yok (setup-collums.sql çalıştırılmalı).', 'COLUMN_MISSING', 400);
        }
        if ($updated === 0) {
            throw new HttpException('Bu takvim sitede bir villaya bağlı değil.', 'NOT_CONNECTED', 404);
        }

        $this->response->success(['estateId' => $estateId, 'siteBookable' => $bookable]);
    }

    private function change(bool $bookable): void
    {
        $estateId = $this->estateId();
        $pdo = $this->db->pdo();
        $kt = new KtApi($pdo, $this->app);

        if ($bookable) {
            // Hemen Kirala onaysız rezervasyon demek: takvim kuralları (min. konaklama vb.) kapalı villa eklenmez.
            $estate = $kt->request('GET', 'estate/item', ['estateId' => $estateId]);
            $rulesEnabled = $this->value($estate, 'IsRulesEnabled');
            if ($rulesEnabled !== null && !filter_var($rulesEnabled, FILTER_VALIDATE_BOOLEAN)) {
                throw new HttpException(
                    'Takvim kuralları pasif olan villa Hemen Kirala\'ya eklenemez. Önce takvim kurallarını açın.',
                    'RULES_DISABLED',
                    400
                );
            }
            $kt->request('POST', 'estate/addbookabledirectly', ['estateId' => $estateId]);
        } else {
            $kt->request('DELETE', 'estate/deletebookabledirectly', ['estateId' => $estateId]);
        }

        // KT tarafı başarılı; site bayrağı (panelin yaptığı gibi). Villa sitede bağlı değilse 0 satır güncellenir.
        $updated = $this->updateSiteFlag($pdo, $estateId, $bookable);

        $this->response->success([
            'estateId' => $estateId,
            'bookable' => $bookable,
            // null: kolon yok, 0: villa sitede bağlı değil
            'siteUpdated' => $updated !== null && $updated > 0,
            'siteColumnExists' => $updated !== null,
        ]);
    }

    /**
     * @return int|null Güncellenen satır sayısı; kolon / tablo yoksa null
     */
    private function updateSiteFlag(PDO $pdo, int $estateId, bool $bookable): ?int
    {
        if (!$this->hasCalendarHomes($pdo) || !$this->hasBookableColumn($pdo)) {
            return null;
        }
        $stmt = $pdo->prepare('UPDATE KiralamaTakvimi.CalendarHomes SET BookableDirectly = ? WHERE EstateId = ?');
        $stmt->execute([$bookable ? 1 : 0, $estateId]);

        return $stmt->rowCount();
    }

    /**
     * EstateId => sitedeki villa (ilk bağlı villa), adı ve BookableDirectly bayrağı.
     *
     * @param int[] $estateIds
     * @return array<int,array{homesId:int,villaName:?string,siteBookable:?bool}>
     */
    private function siteHomes(PDO $pdo, array $estateIds): array
    {
        if ($estateIds === [] || !$this->hasCalendarHomes($pdo)) {
            return [];
        }
        $column = $this->hasBookableColumn($pdo);
        $in = implode(',', array_fill(0, count($estateIds), '?'));
        $title = $this->homeTitleSql('h', 'h.baslik');
        $stmt = $pdo->prepare(
            "SELECT ch.EstateId, ch.homesId, {$title} AS VillaName"
            . ($column ? ', ch.BookableDirectly' : ', NULL AS BookableDirectly')
            . " FROM KiralamaTakvimi.CalendarHomes ch LEFT JOIN homes h ON h.id = ch.homesId
               WHERE ch.EstateId IN ($in) ORDER BY ch.homesId"
        );
        $stmt->execute($estateIds);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $estateId = (int) $row['EstateId'];
            if (isset($map[$estateId])) {
                continue;
            }
            $map[$estateId] = [
                'homesId' => (int) ($row['homesId'] ?? 0),
                'villaName' => $row['VillaName'] !== null ? (string) $row['VillaName'] : null,
                'siteBookable' => $column ? (int) ($row['BookableDirectly'] ?? 0) === 1 : null,
            ];
        }

        return $map;
    }

    private function estateId(): int
    {
        $estateId = (int) $this->request->input('estateId', 0);
        if ($estateId <= 0) {
            throw new HttpException('estateId gerekli.', 'MISSING_PARAMS', 400);
        }

        return $estateId;
    }

    private function hasCalendarHomes(PDO $pdo): bool
    {
        return $pdo->query("SELECT OBJECT_ID('KiralamaTakvimi.CalendarHomes', 'U')")->fetchColumn() !== null;
    }

    private function hasBookableColumn(PDO $pdo): bool
    {
        return $pdo->query("SELECT COL_LENGTH('KiralamaTakvimi.CalendarHomes', 'BookableDirectly')")->fetchColumn() !== null;
    }

    /**
     * Büyük / küçük harf duyarsız anahtar okuma (KT PascalCase döner).
     *
     * @return mixed
     */
    private function value(array $row, string $key)
    {
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }
        foreach ($row as $name => $value) {
            if (strcasecmp((string) $name, $key) === 0) {
                return $value;
            }
        }

        return null;
    }
}
