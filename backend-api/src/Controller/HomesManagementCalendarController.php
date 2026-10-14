<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Ilan takvimi: dolu kayitlari, bagli rezervasyon (kayitlar) ve satis kanali (satis_kanallari).
 *
 * Endpoint:
 *   GET  /backend-api/homes-management-calendar?id={emlakId}
 *   POST /backend-api/homes-management-calendar          {id, startDate, endDate, title, rezTip}  kapatma ekler
 *   POST /backend-api/homes-management-calendar/update   {doluId, startDate, endDate, title, rezTip}
 *   POST /backend-api/homes-management-calendar/delete   {doluId}
 *
 * Kapatma: rezervasyonsuz dolu kaydidir (kayitid -1 veya NULL). Rezervasyon kayitlarina (kayitid > 0) dokunulmaz.
 *
 * rez_tip: dolu.rez_tip doluysa o deger; bossa kayitid varsa "rezervasyon", yoksa "kapali".
 */
final class HomesManagementCalendarController extends Controller
{
    // Ornek kapatma kaydindaki degerler: Durum 0, kayitid -1, kullanici_id -99, IsExternal 0, State 0.
    private const CLOSURE_DURUM = 0;
    private const CLOSURE_KAYIT_ID = -1;
    private const CLOSURE_KULLANICI_ID = -99;
    // Takvimden eklenebilen kayit tipleri; dolu.rez_tip kolonuna yazilir.
    private const CLOSURE_TYPES = ['kapali', 'tadilat'];

    private const DURUM_TEXT = [
        0 => 'Onay Bekliyor',
        1 => 'Ödeme Bekliyor',
        2 => 'Süre Doldu',
        3 => 'Onaylandı',
        4 => 'İptal Edildi',
        5 => 'Silindi',
        6 => 'Açık Rezervasyon',
    ];

    /**
     * Emlagin takvim kayitlarini dondurur. Suresi dolan, iptal ve silinen kayitlar (durum 2, 4, 5) takvimde gosterilmez.
     *
     * @Get
     * @query id int required Emlak ID
     */
    public function index(): void
    {
        $id = filter_var($this->request->query('id'), FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            throw new HttpException('Lutfen gecerli bir emlak ID gonderin.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();
        $rezTipColumn = $this->hasColumn($pdo, 'dbo.dolu', 'rez_tip') ? 'd.rez_tip' : 'NULL';
        $colorColumn = $this->hasColumn($pdo, 'dbo.satis_kanallari', 'color') ? 'sk.color' : 'NULL';

        $stmt = $pdo->prepare("
            SELECT
                d.id,
                d.kayitid,
                CONVERT(varchar(10), d.tarih, 23) AS tarih,
                CONVERT(varchar(10), d.tarih2, 23) AS tarih2,
                d.Durum AS durum,
                d.ical,
                CONVERT(varchar(19), d.createdOn, 120) AS created_on,
                d.musteri AS dolu_musteri,
                {$rezTipColumn} AS rez_tip,
                k.musteri,
                k.adi,
                k.siparis_kodu,
                k.toplam_tutar,
                k.doviz,
                k.yetiskin,
                k.cocuk,
                k.bebek,
                k.[not] AS notlar,
                sk.id AS kanal_id,
                sk.baslik AS kanal_baslik,
                {$colorColumn} AS kanal_renk
            FROM dbo.dolu d
            LEFT JOIN dbo.kayitlar k ON k.id = d.kayitid AND ISNULL(d.kayitid, 0) > 0
            LEFT JOIN dbo.satis_kanallari sk ON sk.id = k.satis_kanallari_id
            WHERE d.emlak = :emlak
              AND ISNULL(d.Durum, -1) NOT IN (2, 4, 5)
            ORDER BY d.tarih DESC, d.id DESC
        ");
        $stmt->execute([':emlak' => $id]);

        $events = [];
        foreach ($stmt->fetchAll() as $row) {
            $kayitId = (int) ($row['kayitid'] ?? 0);
            $durum = $row['durum'] === null ? null : (int) $row['durum'];
            $rezTip = trim((string) ($row['rez_tip'] ?? ''));
            if ($rezTip === '') {
                $rezTip = $kayitId > 0 ? 'rezervasyon' : 'kapali';
            }

            $events[] = [
                'id' => (int) $row['id'],
                'kayitId' => $kayitId > 0 ? $kayitId : null,
                'startDate' => (string) $row['tarih'],
                'endDate' => (string) $row['tarih2'],
                'rezTip' => $rezTip,
                'durum' => $durum,
                'durumText' => $durum === null ? null : (self::DURUM_TEXT[$durum] ?? null),
                'musteri' => $this->firstFilled([$row['musteri'], $row['adi'], $row['dolu_musteri']]),
                'siparisKodu' => $this->text($row['siparis_kodu']),
                'toplamTutar' => $this->text($row['toplam_tutar']),
                'doviz' => $this->text($row['doviz']),
                'yetiskin' => (int) $row['yetiskin'],
                'cocuk' => (int) $row['cocuk'],
                'bebek' => (int) $row['bebek'],
                'notlar' => $this->text($row['notlar']),
                'ical' => !empty($row['ical']),
                'createdAt' => $this->text($row['created_on']),
                'satisKanali' => $row['kanal_id'] === null ? null : [
                    'id' => (int) $row['kanal_id'],
                    'baslik' => $this->text($row['kanal_baslik']),
                    'color' => $this->text($row['kanal_renk']),
                ],
            ];
        }

        $channels = [];
        foreach ($pdo->query("SELECT id, baslik, {$this->channelColor($colorColumn)} AS color FROM dbo.satis_kanallari ORDER BY id")->fetchAll() as $channel) {
            $channels[] = [
                'id' => (int) $channel['id'],
                'baslik' => $this->text($channel['baslik']),
                'color' => $this->text($channel['color']),
            ];
        }

        $this->response->success([
            'events' => $events,
            'satisKanallari' => $channels,
        ]);
    }

    /**
     * Tarih araligini kapatir.
     *
     * @Post
     */
    public function store(): void
    {
        $emlakId = $this->positiveInt('id', 'Lutfen gecerli bir emlak ID gonderin.');
        [$start, $end] = $this->dateRange();
        $title = $this->title();
        $rezTip = $this->closureType();

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->assertNoOverlap($pdo, $emlakId, $start, $end, 0);
            $this->assertRezTipColumn($pdo);
            $columns = ['emlak', 'tarih', 'tarih2', 'musteri', 'Durum', 'kayitid', 'kullanici_id', 'ical', 'rez_tip'];
            $values = [
                ':emlak' => $emlakId,
                ':tarih' => $start,
                ':tarih2' => $end,
                ':musteri' => $title,
                ':Durum' => self::CLOSURE_DURUM,
                ':kayitid' => self::CLOSURE_KAYIT_ID,
                ':kullanici_id' => self::CLOSURE_KULLANICI_ID,
                ':ical' => 0,
                ':rez_tip' => $rezTip,
            ];
            foreach (['IsExternal', 'State'] as $optional) {
                if ($this->hasColumn($pdo, 'dbo.dolu', $optional)) {
                    $columns[] = $optional;
                    $values[':' . $optional] = 0;
                }
            }
            $stmt = $pdo->prepare('INSERT INTO dbo.dolu ([' . implode('], [', $columns) . '], createdOn) VALUES ('
                . implode(', ', array_keys($values)) . ', GETDATE())');
            $stmt->execute($values);
            $newId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->response->success(['message' => 'Tarihler kapatildi', 'id' => $newId], 201);
    }

    /**
     * Kapatmanin tarihlerini ve basligini gunceller.
     *
     * @Post("update")
     */
    public function update(): void
    {
        $doluId = $this->positiveInt('doluId', 'Gecersiz kapatma ID.');
        [$start, $end] = $this->dateRange();
        $title = $this->title();
        $rezTip = $this->closureType();

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $emlakId = $this->requireClosure($pdo, $doluId);
            $this->assertNoOverlap($pdo, $emlakId, $start, $end, $doluId);
            $this->assertRezTipColumn($pdo);
            $pdo->prepare('UPDATE dbo.dolu SET tarih = :tarih, tarih2 = :tarih2, musteri = :musteri, rez_tip = :rez_tip WHERE id = :id')
                ->execute([':tarih' => $start, ':tarih2' => $end, ':musteri' => $title, ':rez_tip' => $rezTip, ':id' => $doluId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->response->success(['message' => 'Kapatma guncellendi']);
    }

    /**
     * Kapatmayi siler.
     *
     * @Post("delete")
     */
    public function delete(): void
    {
        $doluId = $this->positiveInt('doluId', 'Gecersiz kapatma ID.');
        $pdo = $this->db->pdo();
        $this->requireClosure($pdo, $doluId);
        $pdo->prepare('DELETE FROM dbo.dolu WHERE id = :id AND ISNULL(kayitid, -1) <= 0')->execute([':id' => $doluId]);

        $this->response->success(['message' => 'Kapatma silindi']);
    }

    private function requireClosure(PDO $pdo, int $doluId): int
    {
        $stmt = $pdo->prepare('SELECT emlak, kayitid FROM dbo.dolu WITH (UPDLOCK) WHERE id = :id');
        $stmt->execute([':id' => $doluId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new HttpException('Kapatma bulunamadi.', 'NOT_FOUND', 404);
        }
        if ((int) ($row['kayitid'] ?? -1) > 0) {
            throw new HttpException('Rezervasyon kayitlari takvimden degistirilemez.', 'RESERVATION_LOCKED', 409);
        }

        return (int) $row['emlak'];
    }

    // Cikis gunu ayni gun giris olabilir; iptal/silinen/suresi dolan kayitlar cakisma sayilmaz.
    private function assertNoOverlap(PDO $pdo, int $emlakId, string $start, string $end, int $exceptId): void
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM dbo.dolu WITH (UPDLOCK, HOLDLOCK)
            WHERE emlak = :emlak AND id <> :id AND ISNULL(Durum, -1) NOT IN (2, 4, 5)
              AND tarih < :end AND tarih2 > :start');
        $stmt->execute([':emlak' => $emlakId, ':id' => $exceptId, ':start' => $start, ':end' => $end]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new HttpException('Secilen tarihler baska bir kayitla cakisiyor.', 'OVERLAP', 409);
        }
    }

    /**
     * @return array{0:string,1:string}
     */
    private function dateRange(): array
    {
        $start = (string) $this->request->input('startDate', '');
        $end = (string) $this->request->input('endDate', '');
        foreach ([$start, $end] as $date) {
            $parsed = \DateTime::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                throw new HttpException('Tarihler YYYY-MM-DD formatinda olmalidir.', 'VALIDATION', 422);
            }
        }
        if ($end <= $start) {
            throw new HttpException('Cikis tarihi giris tarihinden sonra olmalidir.', 'VALIDATION', 422);
        }

        return [$start, $end];
    }

    private function closureType(): string
    {
        $type = (string) $this->request->input('rezTip', 'kapali');
        if (!in_array($type, self::CLOSURE_TYPES, true)) {
            throw new HttpException('Tip kapali veya tadilat olmalidir.', 'VALIDATION', 422);
        }

        return $type;
    }

    private function assertRezTipColumn(PDO $pdo): void
    {
        if (!$this->hasColumn($pdo, 'dbo.dolu', 'rez_tip')) {
            throw new HttpException('dolu.rez_tip kolonu bulunamadi; once setup calistirilmalidir.', 'SETUP_REQUIRED', 409);
        }
    }

    private function title(): string
    {
        $title = trim((string) $this->request->input('title', ''));
        if ($title === '') {
            throw new HttpException('Baslik gereklidir.', 'VALIDATION', 422);
        }

        return function_exists('mb_substr') ? mb_substr($title, 0, 150) : substr($title, 0, 150);
    }

    private function positiveInt(string $key, string $message): int
    {
        $value = filter_var($this->request->input($key), FILTER_VALIDATE_INT);
        if ($value === false || $value <= 0) {
            throw new HttpException($message, 'VALIDATION', 422);
        }

        return $value;
    }

    private function channelColor(string $colorColumn): string
    {
        return $colorColumn === 'NULL' ? 'NULL' : 'color';
    }

    private function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('SELECT CASE WHEN COL_LENGTH(:table, :column) IS NULL THEN 0 ELSE 1 END');
        $stmt->execute([':table' => $table, ':column' => $column]);

        return (int) $stmt->fetchColumn() === 1;
    }

    /**
     * @param array<int,mixed> $values
     */
    private function firstFilled(array $values): string
    {
        foreach ($values as $value) {
            $value = $this->text($value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param mixed $value
     */
    private function text($value): string
    {
        return $value === null ? '' : trim((string) $value);
    }
}
