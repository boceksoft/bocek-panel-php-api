<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Rezervasyon operasyonları (Natsisa sağ sütun: Operasyon & Görevli, Rezervasyon Notları). Her işlem anında yazılır.
 *
 *   POST /backend-api/reservation-operations/arandi       -> kayitlar.arandi (panel aktif_pasif.asp cat=rezarama)
 *   POST /backend-api/reservation-operations/assignee     -> kayitlar.gorevliKullaniciId / gorevliKullaniciAdi (Natsisa kullanıcısı)
 *   POST /backend-api/reservation-operations/oznot        -> kayitlar.OzNot (panelin tek notu)
 *   POST /backend-api/reservation-operations/note-add     -> Reservation.ReservationNotes ekle
 *   POST /backend-api/reservation-operations/note-update  -> Reservation.ReservationNotes düzenle
 *   POST /backend-api/reservation-operations/note-delete  -> Reservation.ReservationNotes yumuşak sil (IsDeleted = 1)
 *
 * Kurulum: kolonlar setup/collums, not tablosu setup/reservation-notes. Eksikse 409 SETUP_REQUIRED.
 * Her işlem kullanici_log_kaydi'na yazılır (degisiklikler JSON: islem, alanlar[{alan, eski, yeni}]); not metni log'da
 * en fazla 1000 karakter. Not sorguları hem NoteId hem ReservationId ile süzülür (başka rezervasyonun notu değişmez).
 *
 * Ortak gövde: id (kayitlar.id), kullanici_id, kullanici_adi, natsisa_kullanici_id, islem_kodu (Natsisa ekler).
 */
final class ReservationOperationsController extends Controller
{
    private const NOTES_TABLE = 'Reservation.ReservationNotes';
    private const LOG_TEXT_LIMIT = 1000;

    /** @var bool|null */
    private $hasLogDetailsColumn = null;

    /**
     * Arandı / aranmadı.
     *
     * @Post("arandi")
     * @body id int required Rezervasyon kimliği
     * @body arandi bool required true: arandı, false: aranmadı
     */
    public function called(): void
    {
        $pdo = $this->db->pdo();
        $id = $this->reservationId($pdo);
        $this->requireKayitlarColumns($pdo, ['arandi']);
        $value = filter_var($this->request->input('arandi', false), FILTER_VALIDATE_BOOLEAN);

        $old = $this->fetchOne($pdo, 'SELECT ISNULL(arandi, 0) AS arandi FROM kayitlar WHERE id = :id', [':id' => $id]);
        $before = $old && (bool) $old['arandi'];
        if ($before === $value) {
            $this->response->success(['id' => $id, 'arandi' => $value, 'degisti' => false]);
            return;
        }

        $this->transaction($pdo, function () use ($pdo, $id, $value, $before) {
            $this->execute($pdo->prepare('UPDATE kayitlar SET arandi = :v WHERE id = :id'), [':v' => $value ? 1 : 0, ':id' => $id]);
            $this->log(
                $pdo,
                $id,
                $value ? 'Rezervasyonu arandı olarak işaretledi.' : 'Rezervasyonu aranmadı olarak işaretledi.',
                'arandi',
                [['alan' => 'Arandı', 'eski' => $before ? 'Arandı' : 'Aranmadı', 'yeni' => $value ? 'Arandı' : 'Aranmadı']]
            );
        });

        $this->response->success(['id' => $id, 'arandi' => $value, 'degisti' => true]);
    }

    /**
     * Görevli (Natsisa kullanıcısı). Adı Natsisa workspace kullanıcısından gelir.
     *
     * @Post("assignee")
     * @body id int required Rezervasyon kimliği
     * @body kullaniciId int Natsisa kullanıcı id (boş/0: görevli kaldırılır)
     * @body kullaniciAdi string Natsisa kullanıcı adı (en fazla 100)
     */
    public function assignee(): void
    {
        $pdo = $this->db->pdo();
        $id = $this->reservationId($pdo);
        $this->requireKayitlarColumns($pdo, ['gorevliKullaniciId', 'gorevliKullaniciAdi']);

        $userId = (int) $this->request->input('kullaniciId', 0);
        $name = $this->line((string) $this->request->input('kullaniciAdi', ''));
        if ($userId > 0 && $name === '') {
            throw new HttpException('Görevlinin adı boş olamaz.', 'VALIDATION', 422);
        }
        if (mb_strlen($name) > 100) {
            throw new HttpException('Görevli adı en fazla 100 karakter olabilir.', 'VALIDATION', 422);
        }
        $newId = $userId > 0 ? $userId : null;
        $newName = $userId > 0 ? $name : null;

        $old = $this->fetchOne(
            $pdo,
            'SELECT gorevliKullaniciId, gorevliKullaniciAdi FROM kayitlar WHERE id = :id',
            [':id' => $id]
        ) ?: [];
        $oldId = $old['gorevliKullaniciId'] ?? null;
        $oldName = trim((string) ($old['gorevliKullaniciAdi'] ?? ''));
        if ((int) $oldId === (int) $newId && $oldName === (string) $newName) {
            $this->response->success(['id' => $id, 'gorevliKullaniciId' => $newId, 'gorevliKullaniciAdi' => $newName, 'degisti' => false]);
            return;
        }

        $this->transaction($pdo, function () use ($pdo, $id, $newId, $newName, $oldName) {
            $this->execute(
                $pdo->prepare('UPDATE kayitlar SET gorevliKullaniciId = :uid, gorevliKullaniciAdi = :name WHERE id = :id'),
                [':uid' => $newId, ':name' => $newName, ':id' => $id]
            );
            $this->log(
                $pdo,
                $id,
                $newName !== null ? 'Görevli atadı: ' . $newName : 'Görevliyi kaldırdı.',
                'gorevli',
                [['alan' => 'Görevli', 'eski' => $oldName, 'yeni' => (string) $newName]]
            );
        });

        $this->response->success(['id' => $id, 'gorevliKullaniciId' => $newId, 'gorevliKullaniciAdi' => $newName, 'degisti' => true]);
    }

    /**
     * Panelin tek notu (kayitlar.OzNot). Boş gönderilirse temizlenir.
     *
     * @Post("oznot")
     * @body id int required Rezervasyon kimliği
     * @body not string Not metni
     */
    public function panelNote(): void
    {
        $pdo = $this->db->pdo();
        $id = $this->reservationId($pdo);
        $columns = $this->requireKayitlarColumns($pdo, ['OzNot']);
        $text = $this->noteText((string) $this->request->input('not', ''));
        $column = $columns['oznot'];

        $old = $this->fetchOne($pdo, 'SELECT [' . $column . '] AS n FROM kayitlar WHERE id = :id', [':id' => $id]) ?: [];
        $before = trim((string) ($old['n'] ?? ''));
        if ($before === $text) {
            $this->response->success(['id' => $id, 'ozNot' => $text, 'degisti' => false]);
            return;
        }

        $this->transaction($pdo, function () use ($pdo, $id, $column, $text, $before) {
            $this->execute(
                $pdo->prepare('UPDATE kayitlar SET [' . $column . '] = :n WHERE id = :id'),
                [':n' => $text === '' ? null : $text, ':id' => $id]
            );
            $this->log(
                $pdo,
                $id,
                $text === '' ? 'Panel notunu temizledi.' : 'Panel notunu düzenledi.',
                'panel-notu',
                [['alan' => 'Panel notu', 'eski' => $this->clip($before), 'yeni' => $this->clip($text)]]
            );
        });

        $this->response->success(['id' => $id, 'ozNot' => $text, 'degisti' => true]);
    }

    /**
     * Rezervasyon notu ekler.
     *
     * @Post("note-add")
     * @body id int required Rezervasyon kimliği
     * @body not string required Not metni
     */
    public function addNote(): void
    {
        $pdo = $this->db->pdo();
        $id = $this->reservationId($pdo);
        $this->requireNotesTable($pdo);
        $text = $this->requiredNote();
        $userId = $this->natsisaUserId();
        $userName = $this->userName();

        $noteId = 0;
        $this->transaction($pdo, function () use ($pdo, $id, $text, $userId, $userName, &$noteId) {
            $stmt = $pdo->prepare(
                'INSERT INTO ' . self::NOTES_TABLE . ' (ReservationId, Note, CreatedByUserId, CreatedByName)
                 OUTPUT INSERTED.NoteId
                 VALUES (:id, :note, :uid, :name)'
            );
            $this->execute($stmt, [
                ':id' => $id,
                ':note' => $text,
                ':uid' => $userId > 0 ? $userId : null,
                ':name' => $userName !== '' ? $userName : null,
            ]);
            $noteId = (int) $stmt->fetchColumn();
            $this->log($pdo, $id, 'Rezervasyon notu ekledi.', 'not-ekle', [['alan' => 'Not', 'eski' => '', 'yeni' => $this->clip($text)]]);
        });

        $this->response->success(['id' => $id, 'not' => $this->noteRow($pdo, $id, $noteId)]);
    }

    /**
     * Rezervasyon notunu düzenler (herkes düzenleyebilir; son düzenleyen yazılır).
     *
     * @Post("note-update")
     * @body id int required Rezervasyon kimliği
     * @body notId int required Not (ReservationNotes.NoteId)
     * @body not string required Not metni
     */
    public function updateNote(): void
    {
        $pdo = $this->db->pdo();
        $id = $this->reservationId($pdo);
        $this->requireNotesTable($pdo);
        $noteId = $this->noteId();
        $text = $this->requiredNote();
        $old = $this->existingNote($pdo, $id, $noteId);
        $before = (string) $old['Note'];
        if (trim($before) === $text) {
            $this->response->success(['id' => $id, 'not' => $this->noteRow($pdo, $id, $noteId), 'degisti' => false]);
            return;
        }
        $userId = $this->natsisaUserId();
        $userName = $this->userName();

        $this->transaction($pdo, function () use ($pdo, $id, $noteId, $text, $before, $userId, $userName) {
            $this->execute(
                $pdo->prepare(
                    'UPDATE ' . self::NOTES_TABLE . '
                     SET Note = :note, UpdatedByUserId = :uid, UpdatedByName = :name, UpdatedAt = GETDATE()
                     WHERE NoteId = :nid AND ReservationId = :id AND ISNULL(IsDeleted, 0) = 0'
                ),
                [
                    ':note' => $text,
                    ':uid' => $userId > 0 ? $userId : null,
                    ':name' => $userName !== '' ? $userName : null,
                    ':nid' => $noteId,
                    ':id' => $id,
                ]
            );
            $this->log(
                $pdo,
                $id,
                'Rezervasyon notunu düzenledi.',
                'not-duzenle',
                [['alan' => 'Not', 'eski' => $this->clip($before), 'yeni' => $this->clip($text)]]
            );
        });

        $this->response->success(['id' => $id, 'not' => $this->noteRow($pdo, $id, $noteId), 'degisti' => true]);
    }

    /**
     * Rezervasyon notunu siler (yumuşak: IsDeleted = 1, satır kalır).
     *
     * @Post("note-delete")
     * @body id int required Rezervasyon kimliği
     * @body notId int required Not (ReservationNotes.NoteId)
     */
    public function deleteNote(): void
    {
        $pdo = $this->db->pdo();
        $id = $this->reservationId($pdo);
        $this->requireNotesTable($pdo);
        $noteId = $this->noteId();
        $old = $this->existingNote($pdo, $id, $noteId);
        $userId = $this->natsisaUserId();
        $userName = $this->userName();

        $this->transaction($pdo, function () use ($pdo, $id, $noteId, $old, $userId, $userName) {
            $this->execute(
                $pdo->prepare(
                    'UPDATE ' . self::NOTES_TABLE . '
                     SET IsDeleted = 1, UpdatedByUserId = :uid, UpdatedByName = :name, UpdatedAt = GETDATE()
                     WHERE NoteId = :nid AND ReservationId = :id AND ISNULL(IsDeleted, 0) = 0'
                ),
                [
                    ':uid' => $userId > 0 ? $userId : null,
                    ':name' => $userName !== '' ? $userName : null,
                    ':nid' => $noteId,
                    ':id' => $id,
                ]
            );
            $this->log(
                $pdo,
                $id,
                'Rezervasyon notunu sildi.',
                'not-sil',
                [['alan' => 'Not', 'eski' => $this->clip((string) $old['Note']), 'yeni' => '']]
            );
        });

        $this->response->success(['id' => $id, 'notId' => $noteId]);
    }

    // ---------------------------------------------------------------- yardımcılar

    private function reservationId(PDO $pdo): int
    {
        $id = (int) $this->request->input('id', 0);
        if ($id <= 0) {
            throw new HttpException('Lütfen geçerli bir rezervasyon ID gönderin.', 'VALIDATION', 422);
        }
        if (!$this->fetchOne($pdo, 'SELECT TOP 1 1 AS x FROM kayitlar WHERE id = :id', [':id' => $id])) {
            throw new HttpException('Belirtilen ID ile rezervasyon bulunamadı.', 'NOT_FOUND', 404);
        }

        return $id;
    }

    /**
     * kayitlar'da gereken kolonlar; eksikse 409. Dönüş: küçük harf ad => gerçek ad.
     *
     * @param array<int,string> $required
     * @return array<string,string>
     */
    private function requireKayitlarColumns(PDO $pdo, array $required): array
    {
        $columns = $this->columns($pdo, 'dbo', 'kayitlar');
        $missing = array_values(array_filter($required, function (string $c) use ($columns): bool {
            return !isset($columns[strtolower($c)]);
        }));
        if ($missing !== []) {
            throw new HttpException(
                'Bu sitede kayitlar tablosunda eksik kolon var (' . implode(', ', $missing) . '). Önce POST /setup/collums çalıştırılmalı.',
                'SETUP_REQUIRED',
                409
            );
        }

        return $columns;
    }

    private function requireNotesTable(PDO $pdo): void
    {
        $columns = $this->columns($pdo, 'Reservation', 'ReservationNotes');
        foreach (['noteid', 'reservationid', 'note', 'createdbyuserid', 'createdbyname', 'createdat', 'updatedbyuserid', 'updatedbyname', 'updatedat', 'isdeleted'] as $column) {
            if (!isset($columns[$column])) {
                throw new HttpException(
                    'Rezervasyon notları tablosu bu sitede eksik kurulu. Önce POST /setup/reservation-notes çalıştırılmalı.',
                    'SETUP_REQUIRED',
                    409
                );
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function existingNote(PDO $pdo, int $id, int $noteId): array
    {
        $row = $this->fetchOne(
            $pdo,
            'SELECT NoteId, Note FROM ' . self::NOTES_TABLE . ' WHERE NoteId = :nid AND ReservationId = :id AND ISNULL(IsDeleted, 0) = 0',
            [':nid' => $noteId, ':id' => $id]
        );
        if (!$row) {
            throw new HttpException('Not bu rezervasyonda bulunamadı.', 'NOT_FOUND', 404);
        }

        return $row;
    }

    /**
     * Not satırı (reservationdetail detay.notlar.liste ile aynı biçim).
     *
     * @return array<string,mixed>|null
     */
    private function noteRow(PDO $pdo, int $id, int $noteId)
    {
        $row = $this->fetchOne(
            $pdo,
            'SELECT NoteId, Note, CreatedByName, CONVERT(varchar(19), CreatedAt, 120) AS CreatedAt,
                    UpdatedByName, CONVERT(varchar(19), UpdatedAt, 120) AS UpdatedAt
             FROM ' . self::NOTES_TABLE . ' WHERE NoteId = :nid AND ReservationId = :id',
            [':nid' => $noteId, ':id' => $id]
        );
        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['NoteId'],
            'not' => (string) ($row['Note'] ?? ''),
            'olusturan' => trim((string) ($row['CreatedByName'] ?? '')),
            'olusturmaTarihi' => $row['CreatedAt'],
            'guncelleyen' => trim((string) ($row['UpdatedByName'] ?? '')),
            'guncellemeTarihi' => $row['UpdatedAt'],
        ];
    }

    private function noteId(): int
    {
        $noteId = (int) $this->request->input('notId', 0);
        if ($noteId <= 0) {
            throw new HttpException('Not seçilmedi.', 'VALIDATION', 422);
        }

        return $noteId;
    }

    private function requiredNote(): string
    {
        $text = $this->noteText((string) $this->request->input('not', ''));
        if ($text === '') {
            throw new HttpException('Not boş olamaz.', 'VALIDATION', 422);
        }

        return $text;
    }

    /** Not metni: satır sonları korunur, baş/son boşluk atılır, satır sonları \n'e çevrilir. */
    private function noteText(string $value): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $value));
    }

    /** Tek satır metin. */
    private function line(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** Log için en fazla 1000 karakter. */
    private function clip(string $text): string
    {
        return mb_strlen($text) > self::LOG_TEXT_LIMIT ? mb_substr($text, 0, self::LOG_TEXT_LIMIT) . '…' : $text;
    }

    /**
     * Kolonlar: küçük harf ad => gerçek ad. Tablo yoksa boş.
     *
     * @return array<string,string>
     */
    private function columns(PDO $pdo, string $schema, string $table): array
    {
        $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table');
        $this->execute($stmt, [':schema' => $schema, ':table' => $table]);
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $columns[strtolower((string) $name)] = (string) $name;
        }

        return $columns;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|false
     */
    private function fetchOne(PDO $pdo, string $sql, array $params = [])
    {
        $stmt = $pdo->prepare($sql);
        $this->execute($stmt, $params);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string,mixed> $params
     */
    private function execute(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            if ($value === null) {
                $stmt->bindValue($key, null, PDO::PARAM_NULL);
            } elseif (is_int($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
            }
        }
        $stmt->execute();
    }

    private function transaction(PDO $pdo, callable $work): void
    {
        $pdo->beginTransaction();
        try {
            $work();
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ---------------------------------------------------------------- log (ReservationStatusController ile aynı biçim)

    /**
     * @param array<int,array{alan:string,eski:string,yeni:string}> $changes
     */
    private function log(PDO $pdo, int $id, string $message, string $action, array $changes): void
    {
        $userName = $this->userName();
        if ($userName !== '') {
            $message .= ' (' . $userName . ')';
        }
        $params = [
            ':islmId' => (string) $id,
            ':aciklama' => $message,
            ':kullaniciId' => (int) $this->request->input('kullanici_id', 0),
        ];
        $details = [
            'kaynak' => $this->natsisaUserId() > 0 || $userName !== '' ? 'natsisa' : 'api',
            'kullaniciAdi' => $userName,
            'natsisaKullaniciId' => $this->natsisaUserId(),
            'islem' => $action,
            'alanlar' => $changes,
        ];
        $actionCode = (string) $this->request->input('islem_kodu', '');
        if (preg_match('/^[A-Za-z0-9-]{1,64}$/', $actionCode) === 1) {
            $details['islemKodu'] = $actionCode;
        }

        if ($this->hasLogDetailsColumn($pdo)) {
            $json = json_encode($details, JSON_UNESCAPED_UNICODE);
            $stmt = $pdo->prepare(
                "INSERT INTO kullanici_log_kaydi (islm, islm_id, aciklama, kullanici_id, degisiklikler)
                 VALUES ('rezervasyon', :islmId, :aciklama, :kullaniciId, :degisiklikler)"
            );
            $params[':degisiklikler'] = $json === false ? null : $json;
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO kullanici_log_kaydi (islm, islm_id, aciklama, kullanici_id)
                 VALUES ('rezervasyon', :islmId, :aciklama, :kullaniciId)"
            );
        }
        $this->execute($stmt, $params);
    }

    private function userName(): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $this->request->input('kullanici_adi', '')));

        return mb_substr($name, 0, 100);
    }

    private function natsisaUserId(): int
    {
        return max(0, (int) $this->request->input('natsisa_kullanici_id', 0));
    }

    private function hasLogDetailsColumn(PDO $pdo): bool
    {
        if ($this->hasLogDetailsColumn === null) {
            $stmt = $pdo->query("SELECT COL_LENGTH('dbo.kullanici_log_kaydi', 'degisiklikler') AS len");
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            $this->hasLogDetailsColumn = is_array($row) && $row['len'] !== null;
        }

        return $this->hasLogDetailsColumn;
    }
}
