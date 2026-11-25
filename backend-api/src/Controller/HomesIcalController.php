<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Ilan takvimindeki iCal baglantisi (eski panel: calendar.asp > "Ical Url" formu, calendarajax.asp urlDuzenle / urlDoldur).
 *   GET  /homes-ical?id=   homes.ical, homes.ical_gecelik, disa aktarma linki, Kiralama Takvimi baglantisi
 *   POST /homes-ical       homes.ical ve homes.ical_gecelik'i kaydeder
 *   POST /homes-ical/sync  sitedeki /ical/icalsync.php?id= betigini calistirir (eski panel: "Ice Aktar")
 *
 * iCal'in okunup dolu tablosuna yazilmasi ve disa aktarma (/ical/icalwrite.php) sitede kalir; burada yeniden yazilmaz.
 * Kiralama Takvimi'ne bagli villalarda (KiralamaTakvimi.CalendarHomes) iCal kullanilamaz: kaydetme ve ice aktarma reddedilir.
 */
final class HomesIcalController extends Controller
{
    /**
     * Villanin iCal ayarlari.
     *
     * @Get
     * @query id int required Emlak kimligi
     */
    public function index(): void
    {
        $id = $this->homeId((int) $this->request->query('id', 0));
        $pdo = $this->db->pdo();
        $this->assertHome($pdo, $id);

        $hasIcal = $this->columnExists($pdo, 'homes', 'ical');
        $hasGecelik = $this->columnExists($pdo, 'homes', 'ical_gecelik');
        $url = '';
        $gecelik = false;
        if ($hasIcal) {
            $select = 'SELECT ISNULL(CONVERT(nvarchar(max), ical), \'\') AS ical'
                . ($hasGecelik ? ', CASE WHEN ical_gecelik = 1 THEN 1 ELSE 0 END AS ical_gecelik' : ', 0 AS ical_gecelik')
                . ' FROM homes WHERE id = :id';
            $stmt = $pdo->prepare($select);
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $url = trim((string) ($row['ical'] ?? ''));
            $gecelik = ((int) ($row['ical_gecelik'] ?? 0)) === 1;
        }

        $estateId = $this->calendarEstateId($pdo, $id);

        $this->response->success([
            'id' => $id,
            'supported' => $hasIcal,
            'ical_url' => $url,
            'gecelik' => $gecelik,
            'gecelik_supported' => $hasGecelik,
            // Disa aktarma: eski panel replace(ayarlar("domain"), "web", "www") & "/ical/icalwrite.php?id="
            'export_url' => $this->siteDomain() . '/ical/icalwrite.php?id=' . $id,
            'calendar_estate_id' => $estateId,
            'kiralama_takvimi' => $estateId !== null,
        ]);
    }

    /**
     * iCal linkini ve "Gecelik olarak kapat" ayarini kaydeder (eski panel: urlDuzenle).
     *
     * @Post
     * @body id int required Emlak kimligi
     * @body ical_url string iCal linki (bos: baglanti kaldirilir)
     * @body gecelik bool Gecelik olarak kapat (homes.ical_gecelik)
     */
    public function save(): void
    {
        $id = $this->homeId((int) $this->request->input('id', 0));
        $pdo = $this->db->pdo();
        $this->assertHome($pdo, $id);
        $this->assertNotKiralamaTakvimi($pdo, $id);

        if (!$this->columnExists($pdo, 'homes', 'ical')) {
            throw new HttpException('Bu sitede homes.ical kolonu yok; iCal kullanılamaz.', 'NOT_SUPPORTED', 422);
        }

        $url = trim((string) $this->request->input('ical_url', ''));
        if ($url !== '' && preg_match('#^(https?|webcal)://#i', $url) !== 1) {
            throw new HttpException('iCal linki http://, https:// veya webcal:// ile başlamalı.', 'VALIDATION', 422);
        }
        $gecelik = filter_var($this->request->input('gecelik', false), FILTER_VALIDATE_BOOLEAN);
        $hasGecelik = $this->columnExists($pdo, 'homes', 'ical_gecelik');

        $sql = 'UPDATE homes SET ical = :ical' . ($hasGecelik ? ', ical_gecelik = :gecelik' : '') . ' WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':ical', $url, PDO::PARAM_STR);
        if ($hasGecelik) {
            $stmt->bindValue(':gecelik', $gecelik ? 1 : 0, PDO::PARAM_INT);
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $this->response->success([
            'id' => $id,
            'ical_url' => $url,
            'gecelik' => $hasGecelik ? $gecelik : false,
        ]);
    }

    /**
     * Sitedeki iCal ice aktarma betigini calistirir (eski panel: "Ice Aktar" > genel("domain")/ical/icalsync.php?id=).
     *
     * @Post("sync")
     * @body id int required Emlak kimligi
     */
    public function sync(): void
    {
        $id = $this->homeId((int) $this->request->input('id', 0));
        $pdo = $this->db->pdo();
        $this->assertHome($pdo, $id);
        $this->assertNotKiralamaTakvimi($pdo, $id);

        if ($this->columnExists($pdo, 'homes', 'ical')) {
            $stmt = $pdo->prepare("SELECT ISNULL(CONVERT(nvarchar(max), ical), '') FROM homes WHERE id = :id");
            $stmt->execute([':id' => $id]);
            if (trim((string) $stmt->fetchColumn()) === '') {
                throw new HttpException('Önce iCal linkini kaydedin.', 'VALIDATION', 422);
            }
        }

        $url = $this->syncDomain($pdo) . '/ical/icalsync.php?id=' . $id;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 90,
        ]);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($raw === false || $status === 0) {
            throw new HttpException(
                'Sitedeki iCal içe aktarma betiğine ulaşılamadı.' . (!empty($this->app['debug']) ? ' | ' . $url . ' | ' . $error : ''),
                'ICAL_SYNC_UNREACHABLE',
                502
            );
        }
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $raw), ENT_QUOTES, 'UTF-8')) ?? '');
        $text = function_exists('mb_substr') ? mb_substr($text, 0, 500) : substr($text, 0, 500);
        if ($status >= 400) {
            throw new HttpException(
                'iCal içe aktarma başarısız (HTTP ' . $status . ')' . ($text !== '' ? ': ' . $text : ''),
                'ICAL_SYNC_FAILED',
                502
            );
        }

        $this->response->success([
            'id' => $id,
            'status' => $status,
            'message' => $text,
        ]);
    }

    private function homeId(int $id): int
    {
        if ($id <= 0) {
            throw new HttpException('Lütfen geçerli bir emlak ID gönderin.', 'VALIDATION', 422);
        }

        return $id;
    }

    private function assertHome(PDO $pdo, int $id): void
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM homes WHERE id = :id');
        $stmt->execute([':id' => $id]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new HttpException('Emlak bulunamadı.', 'NOT_FOUND', 404);
        }
    }

    /** Eski panel: iCal formu yalnizca villa KiralamaTakvimi.CalendarHomes'ta yoksa gosterilir. */
    private function assertNotKiralamaTakvimi(PDO $pdo, int $id): void
    {
        if ($this->calendarEstateId($pdo, $id) !== null) {
            throw new HttpException(
                "Bu villa Kiralama Takvimi'ne bağlı; iCal Kiralama Takvimi üzerinden yönetilir.",
                'KIRALAMA_TAKVIMI_CONNECTED',
                409
            );
        }
    }

    private function calendarEstateId(PDO $pdo, int $id): ?int
    {
        try {
            if ($pdo->query("SELECT OBJECT_ID('KiralamaTakvimi.CalendarHomes', 'U')")->fetchColumn() === null) {
                return null;
            }
            $stmt = $pdo->prepare('SELECT TOP 1 EstateId FROM KiralamaTakvimi.CalendarHomes WHERE homesId = :id');
            $stmt->execute([':id' => $id]);
            $estateId = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return null;
        }

        return $estateId !== false && (int) $estateId > 0 ? (int) $estateId : null;
    }

    /** Sitenin yayin adresi: Domain sabiti (ayarlar.domain), web. alt alan adi www. olarak. */
    private function siteDomain(): string
    {
        $domain = defined('Domain') ? rtrim(trim((string) constant('Domain')), '/') : '';

        return str_replace('web.', 'www.', $domain);
    }

    /** Ice aktarma adresi: eski panel genel("domain"); kolon bos / yoksa siteDomain(). */
    private function syncDomain(PDO $pdo): string
    {
        try {
            if ($this->columnExists($pdo, 'genel', 'domain')) {
                $domain = rtrim(trim((string) $pdo->query('SELECT TOP 1 domain FROM genel')->fetchColumn()), '/');
                if ($domain !== '') {
                    return preg_match('#^https?://#i', $domain) === 1 ? $domain : 'https://' . $domain;
                }
            }
        } catch (\Throwable $e) {
            // genel okunamazsa Domain sabitine dusulur
        }
        $site = $this->siteDomain();
        if ($site === '') {
            throw new HttpException('Site adresi bulunamadı (genel.domain / Domain).', 'CONFIG', 500);
        }

        return $site;
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = :t AND COLUMN_NAME = :c"
        );
        $stmt->execute([':t' => $table, ':c' => $column]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
