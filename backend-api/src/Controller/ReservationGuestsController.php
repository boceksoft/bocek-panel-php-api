<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;

/*
 * Rezervasyon müşteri / misafir / fatura bilgisi yazma kaynağı.
 *
 *   POST /backend-api/reservation-guests/passenger         -> misafir ekle (yolcuId yoksa) / düzenle (yolcuId varsa)
 *   POST /backend-api/reservation-guests/passenger-delete  -> misafir sil (panel gibi fiziksel silme)
 *   POST /backend-api/reservation-guests/invoice           -> fatura bilgisi kaydet (son kaydı günceller, yoksa ekler)
 *   POST /backend-api/reservation-guests/customer          -> rezervasyon sahibi bilgileri (kayitlar), sadece gönderilen alanlar
 *
 * Panel karşılığı: json-ajax/kisi-bilgileri.asp (addPersonalInformation, editPersonalInformation, delete,
 * addInvoiceData, editInvoiceData) ve asp_scripts/scripts.asp rezervasyon düzenleme (kayitlar + dolu.musteri).
 * Tablolar: Reservation.passengerInformation, Reservation.InvoiceData (kurulum: POST /setup/reservation-guests).
 *
 * Her işlem kullanici_log_kaydi'na (islm = rezervasyon) yazılır; değişen alanlar degisiklikler kolonuna JSON olarak
 * (kaynak, kullaniciAdi, islem, alanlar[{alan, eski, yeni}]) eklenir. Rezervasyonun işlem geçmişinde görünür.
 *
 * Ortak gövde alanları: id (kayitlar.id), kullanici_id, kullanici_adi, natsisa_kullanici_id, islem_kodu.
 */
final class ReservationGuestsController extends Controller
{
    private const PASSENGER_TABLE = 'Reservation.passengerInformation';
    private const INVOICE_TABLE = 'Reservation.InvoiceData';

    /** Müşteri formu alanı -> kayitlar kolon adayları (ilk bulunan kullanılır) ve log etiketi. */
    private const CUSTOMER_FIELDS = [
        'musteri' => [['musteri'], 'Ad Soyad'],
        'email' => [['email'], 'E-posta'],
        'telefon' => [['telefon'], 'Telefon'],
        'telefon2' => [['phone2', 'telefon2'], 'Telefon 2'],
        'tc' => [['tc'], 'TC No'],
        'adres' => [['adres'], 'Adres'],
    ];

    /** @var bool|null */
    private $hasLogDetailsColumn = null;

    /** @var array<string,array<string,array{name:string,max:int|null}>> tablo => kolonlar (istek başına önbellek) */
    private $columnCache = [];

    /**
     * Misafir ekler veya düzenler (Reservation.passengerInformation).
     *
     * @Post("passenger")
     * @body id int required Rezervasyon kimliği (kayitlar.id)
     * @body yolcuId int Düzenlenecek misafir (passengerInformation.id). Yoksa yeni kayıt eklenir.
     * @body adSoyad string required Ad soyad
     * @body tc string TC kimlik no (yabancıda pasaport no)
     * @body cinsiyet int 1 Erkek, 0 Kadın, null bilinmiyor
     * @body dogumTarihi string yyyy-MM-dd
     * @body dogumYeri string Doğum yeri
     * @body turkVatandasi bool Türk vatandaşı mı (varsayılan true)
     */
    public function savePassenger(): void
    {
        $pdo = $this->db->pdo();
        $reservationId = $this->reservationId($pdo);
        $columns = $this->requireColumns(
            $pdo,
            self::PASSENGER_TABLE,
            ['id', 'kayitlarId', 'name', 'tc', 'gender', 'isTurkish', 'birthDate', 'birthPlace']
        );

        $name = $this->text('adSoyad');
        if ($name === '') {
            throw new HttpException('Misafirin adı soyadı boş olamaz.', 'VALIDATION', 422);
        }

        $values = [
            'name' => $this->fit($columns, 'name', $name, 'Ad Soyad'),
            'tc' => $this->fit($columns, 'tc', $this->text('tc'), 'TC / Pasaport No'),
            'gender' => $this->gender(),
            'isTurkish' => $this->request->input('turkVatandasi', true) === false ? 0 : 1,
            'birthDate' => $this->date('dogumTarihi'),
            'birthPlace' => $this->fit($columns, 'birthPlace', $this->text('dogumYeri'), 'Doğum Yeri'),
        ];

        $quoted = [];
        $params = [];
        foreach ($values as $column => $value) {
            $quoted[$column] = '[' . $columns[strtolower($column)]['name'] . ']';
            $params[':' . $column] = $value;
        }
        $idColumn = '[' . $columns['id']['name'] . ']';
        $reservationColumn = '[' . $columns['kayitlarid']['name'] . ']';

        $passengerId = (int) $this->request->input('yolcuId', 0);
        if ($passengerId > 0) {
            $old = $this->passengerRow($pdo, $columns, $passengerId, $reservationId);
            $assignments = [];
            foreach ($quoted as $column => $columnName) {
                $assignments[] = $columnName . ' = :' . $column;
            }

            $this->transaction($pdo, function () use ($pdo, $idColumn, $reservationColumn, $assignments, $params, $passengerId, $reservationId, $old, $values, $name) {
                $stmt = $pdo->prepare(
                    'UPDATE ' . self::PASSENGER_TABLE . ' SET ' . implode(', ', $assignments)
                    . ' WHERE ' . $idColumn . ' = :pid AND ' . $reservationColumn . ' = :rid'
                );
                $this->execute($stmt, $params + [':pid' => $passengerId, ':rid' => $reservationId]);

                $changes = $this->diff($this->passengerLabels(), $this->passengerView($old), $this->passengerView($values));
                if ($changes !== []) {
                    $this->log($pdo, $reservationId, 'Misafir bilgilerini düzenledi: ' . $name, 'misafir-duzenle', $changes);
                }
            });
        } else {
            $this->transaction($pdo, function () use ($pdo, $idColumn, $reservationColumn, $quoted, $params, $reservationId, $values, $name, &$passengerId) {
                $stmt = $pdo->prepare(
                    'INSERT INTO ' . self::PASSENGER_TABLE . ' (' . $reservationColumn . ', ' . implode(', ', $quoted) . ')'
                    . ' OUTPUT INSERTED.' . $idColumn
                    . ' VALUES (:rid, :' . implode(', :', array_keys($quoted)) . ')'
                );
                $this->execute($stmt, $params + [':rid' => $reservationId]);
                $passengerId = (int) $stmt->fetchColumn();

                $changes = $this->diff($this->passengerLabels(), [], $this->passengerView($values));
                $this->log($pdo, $reservationId, 'Misafir ekledi: ' . $name, 'misafir-ekle', $changes);
            });
        }

        $this->response->success(['id' => $reservationId, 'yolcuId' => $passengerId]);
    }

    /**
     * Misafiri siler (panel gibi fiziksel silme). Silinen kaydın tüm bilgisi log'a yazılır.
     *
     * @Post("passenger-delete")
     * @body id int required Rezervasyon kimliği (kayitlar.id)
     * @body yolcuId int required Silinecek misafir (passengerInformation.id)
     */
    public function deletePassenger(): void
    {
        $pdo = $this->db->pdo();
        $reservationId = $this->reservationId($pdo);
        $columns = $this->requireColumns($pdo, self::PASSENGER_TABLE, ['id', 'kayitlarId']);

        $passengerId = (int) $this->request->input('yolcuId', 0);
        if ($passengerId <= 0) {
            throw new HttpException('Silinecek misafir seçilmedi.', 'VALIDATION', 422);
        }
        $old = $this->passengerRow($pdo, $columns, $passengerId, $reservationId);

        $this->transaction($pdo, function () use ($pdo, $columns, $passengerId, $reservationId, $old) {
            $stmt = $pdo->prepare(
                'DELETE FROM ' . self::PASSENGER_TABLE
                . ' WHERE [' . $columns['id']['name'] . '] = :pid AND [' . $columns['kayitlarid']['name'] . '] = :rid'
            );
            $this->execute($stmt, [':pid' => $passengerId, ':rid' => $reservationId]);

            $changes = $this->diff($this->passengerLabels(), $this->passengerView($old), []);
            $this->log($pdo, $reservationId, 'Misafiri sildi: ' . (string) ($old['name'] ?? ''), 'misafir-sil', $changes);
        });

        $this->response->success(['id' => $reservationId, 'yolcuId' => $passengerId]);
    }

    /**
     * Fatura bilgisini kaydeder. Rezervasyonun kaydı varsa son kaydı (id en büyük) günceller, yoksa ekler.
     *
     * @Post("invoice")
     * @body id int required Rezervasyon kimliği (kayitlar.id)
     * @body faturaTipi int 1 Bireysel, 2 Kurumsal
     * @body isim string Ad soyad / ünvan
     * @body tcNo string TC no / vergi no
     * @body email string E-posta
     * @body telefon string Telefon
     * @body vergiDairesi string Vergi dairesi (kurumsal)
     * @body mesaj string Adres / mesaj
     */
    public function saveInvoice(): void
    {
        $pdo = $this->db->pdo();
        $reservationId = $this->reservationId($pdo);
        $columns = $this->requireColumns(
            $pdo,
            self::INVOICE_TABLE,
            ['id', 'kayitlarId', 'faturatipi', 'isim', 'tcno', 'email', 'telefon', 'vergidairesi', 'mesaj']
        );

        $values = [
            'faturatipi' => (int) $this->request->input('faturaTipi', 1) === 2 ? 2 : 1,
            'isim' => $this->fit($columns, 'isim', $this->text('isim'), 'Ad Soyad / Ünvan'),
            'tcno' => $this->fit($columns, 'tcno', $this->text('tcNo'), 'TC / Vergi No'),
            'email' => $this->fit($columns, 'email', $this->text('email'), 'E-posta'),
            'telefon' => $this->fit($columns, 'telefon', $this->text('telefon'), 'Telefon'),
            'vergidairesi' => $this->fit($columns, 'vergidairesi', $this->text('vergiDairesi'), 'Vergi Dairesi'),
            'mesaj' => $this->fit($columns, 'mesaj', $this->text('mesaj', true), 'Adres / Mesaj'),
        ];

        $idColumn = '[' . $columns['id']['name'] . ']';
        $reservationColumn = '[' . $columns['kayitlarid']['name'] . ']';
        $quoted = [];
        $params = [];
        $select = [];
        foreach ($values as $column => $value) {
            $quoted[$column] = '[' . $columns[$column]['name'] . ']';
            $params[':' . $column] = $value;
            $select[] = $quoted[$column] . ' AS [' . $column . ']';
        }

        $stmt = $pdo->prepare(
            'SELECT TOP 1 ' . $idColumn . ' AS [id], ' . implode(', ', $select)
            . ' FROM ' . self::INVOICE_TABLE
            . ' WHERE ' . $reservationColumn . ' = :rid ORDER BY ' . $idColumn . ' DESC'
        );
        $this->execute($stmt, [':rid' => $reservationId]);
        $old = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $invoiceId = $old !== [] ? (int) $old['id'] : 0;
        $this->transaction($pdo, function () use ($pdo, $idColumn, $reservationColumn, $quoted, $params, $reservationId, $old, $values, &$invoiceId) {
            if ($old !== []) {
                $assignments = [];
                foreach ($quoted as $column => $columnName) {
                    $assignments[] = $columnName . ' = :' . $column;
                }
                $stmt = $pdo->prepare(
                    'UPDATE ' . self::INVOICE_TABLE . ' SET ' . implode(', ', $assignments)
                    . ' WHERE ' . $idColumn . ' = :iid AND ' . $reservationColumn . ' = :rid'
                );
                $this->execute($stmt, $params + [':iid' => $invoiceId, ':rid' => $reservationId]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO ' . self::INVOICE_TABLE . ' (' . $reservationColumn . ', ' . implode(', ', $quoted) . ')'
                    . ' OUTPUT INSERTED.' . $idColumn
                    . ' VALUES (:rid, :' . implode(', :', array_keys($quoted)) . ')'
                );
                $this->execute($stmt, $params + [':rid' => $reservationId]);
                $invoiceId = (int) $stmt->fetchColumn();
            }

            $changes = $this->diff($this->invoiceLabels(), $this->invoiceView($old), $this->invoiceView($values));
            if ($changes !== []) {
                $this->log(
                    $pdo,
                    $reservationId,
                    $old !== [] ? 'Fatura bilgilerini düzenledi.' : 'Fatura bilgisi ekledi.',
                    $old !== [] ? 'fatura-duzenle' : 'fatura-ekle',
                    $changes
                );
            }
        });

        $this->response->success(['id' => $reservationId, 'faturaId' => $invoiceId]);
    }

    /**
     * Rezervasyon sahibi bilgilerini (kayitlar) günceller. Sadece gövdede gönderilen ve değişen alanlar yazılır;
     * ad değişirse panel gibi dolu.musteri de güncellenir. Sitede olmayan kolon atlanır.
     *
     * @Post("customer")
     * @body id int required Rezervasyon kimliği (kayitlar.id)
     * @body musteri string Ad soyad
     * @body email string E-posta
     * @body telefon string Telefon
     * @body telefon2 string Telefon 2 (kayitlar.phone2)
     * @body tc string TC no
     * @body adres string Adres
     */
    public function saveCustomer(): void
    {
        $pdo = $this->db->pdo();
        $reservationId = $this->reservationId($pdo);
        $columns = $this->columns($pdo, 'dbo.kayitlar');
        $body = $this->request->json();

        $targets = [];
        foreach (self::CUSTOMER_FIELDS as $field => $definition) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            foreach ($definition[0] as $candidate) {
                if (isset($columns[strtolower($candidate)])) {
                    $targets[$field] = $columns[strtolower($candidate)]['name'];
                    break;
                }
            }
        }
        if ($targets === []) {
            throw new HttpException('Güncellenecek müşteri bilgisi gönderilmedi.', 'VALIDATION', 422);
        }
        if (array_key_exists('musteri', $targets) && $this->text('musteri') === '') {
            throw new HttpException('Müşteri adı boş olamaz.', 'VALIDATION', 422);
        }

        $select = [];
        foreach ($targets as $field => $column) {
            $select[] = '[' . $column . '] AS [' . $field . ']';
        }
        $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM kayitlar WHERE id = :rid');
        $this->execute($stmt, [':rid' => $reservationId]);
        $old = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $new = [];
        $labels = [];
        foreach ($targets as $field => $column) {
            $labels[$field] = self::CUSTOMER_FIELDS[$field][1];
            $new[$field] = $this->fit($columns, $column, $this->text($field, $field === 'adres'), $labels[$field]);
        }

        $changes = $this->diff($labels, $old, $new);
        if ($changes === []) {
            $this->response->success(['id' => $reservationId, 'degisen' => 0]);
            return;
        }

        $this->transaction($pdo, function () use ($pdo, $targets, $new, $old, $reservationId, $changes) {
            $assignments = [];
            $params = [':rid' => $reservationId];
            foreach ($targets as $field => $column) {
                if (trim((string) ($old[$field] ?? '')) === trim((string) $new[$field])) {
                    continue;
                }
                $assignments[] = '[' . $column . '] = :' . $field;
                $params[':' . $field] = $new[$field];
            }
            $stmt = $pdo->prepare('UPDATE kayitlar SET ' . implode(', ', $assignments) . ' WHERE id = :rid');
            $this->execute($stmt, $params);

            // Panel (scripts.asp): rezervasyon adı değişince dolu.musteri de yazılır.
            if (isset($params[':musteri']) && isset($this->columns($pdo, 'dbo.dolu')['musteri'])) {
                $stmt = $pdo->prepare('UPDATE dolu SET musteri = :musteri WHERE kayitid = :rid');
                $this->execute($stmt, [':musteri' => $params[':musteri'], ':rid' => $reservationId]);
            }

            $this->log(
                $pdo,
                $reservationId,
                $reservationId . ' numaralı rezervasyonun müşteri bilgilerini düzenledi.',
                'musteri-duzenle',
                $changes
            );
        });

        $this->response->success(['id' => $reservationId, 'degisen' => count($changes)]);
    }

    // ---------------------------------------------------------------- yardımcılar

    private function reservationId(PDO $pdo): int
    {
        $id = (int) $this->request->input('id', 0);
        if ($id <= 0) {
            throw new HttpException('Lütfen geçerli bir rezervasyon ID gönderin.', 'VALIDATION', 422);
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM kayitlar WHERE id = :id');
        $this->execute($stmt, [':id' => $id]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new HttpException('Belirtilen ID ile rezervasyon bulunamadı.', 'NOT_FOUND', 404);
        }

        return $id;
    }

    /**
     * Misafir satırı; bu rezervasyona ait değilse 404.
     *
     * @param array<string,array{name:string,max:int|null}> $columns
     * @return array<string,mixed>
     */
    private function passengerRow(PDO $pdo, array $columns, int $passengerId, int $reservationId): array
    {
        $select = [];
        foreach (['name', 'tc', 'gender', 'isTurkish', 'birthDate', 'birthPlace'] as $column) {
            $key = strtolower($column);
            if (!isset($columns[$key])) {
                continue;
            }
            $select[] = $column === 'birthDate'
                ? 'CONVERT(varchar(10), [' . $columns[$key]['name'] . '], 23) AS [birthDate]'
                : '[' . $columns[$key]['name'] . '] AS [' . $column . ']';
        }
        $stmt = $pdo->prepare(
            'SELECT [' . $columns['id']['name'] . '] AS [id]' . ($select !== [] ? ', ' . implode(', ', $select) : '')
            . ' FROM ' . self::PASSENGER_TABLE
            . ' WHERE [' . $columns['id']['name'] . '] = :pid AND [' . $columns['kayitlarid']['name'] . '] = :rid'
        );
        $this->execute($stmt, [':pid' => $passengerId, ':rid' => $reservationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new HttpException('Misafir bu rezervasyonda bulunamadı.', 'NOT_FOUND', 404);
        }

        return $row;
    }

    /** @return array<string,string> */
    private function passengerLabels(): array
    {
        return [
            'name' => 'Ad Soyad',
            'tc' => 'TC / Pasaport No',
            'gender' => 'Cinsiyet',
            'isTurkish' => 'Uyruk',
            'birthDate' => 'Doğum Tarihi',
            'birthPlace' => 'Doğum Yeri',
        ];
    }

    /**
     * Misafir değerlerini log için okunur metne çevirir.
     *
     * @param array<string,mixed> $row
     * @return array<string,string>
     */
    private function passengerView(array $row): array
    {
        if ($row === []) {
            return [];
        }
        $gender = $row['gender'] ?? null;
        $turkish = $row['isTurkish'] ?? null;
        $date = (string) ($row['birthDate'] ?? '');

        return [
            'name' => (string) ($row['name'] ?? ''),
            'tc' => (string) ($row['tc'] ?? ''),
            'gender' => $gender === null || $gender === '' ? '' : ((int) $gender === 1 ? 'Erkek' : 'Kadın'),
            'isTurkish' => $turkish === null || $turkish === '' ? '' : ((bool) $turkish ? 'T.C.' : 'Yabancı'),
            'birthDate' => preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m) === 1 ? $m[3] . '.' . $m[2] . '.' . $m[1] : '',
            'birthPlace' => (string) ($row['birthPlace'] ?? ''),
        ];
    }

    /** @return array<string,string> */
    private function invoiceLabels(): array
    {
        return [
            'faturatipi' => 'Fatura Tipi',
            'isim' => 'Ad Soyad / Ünvan',
            'tcno' => 'TC / Vergi No',
            'vergidairesi' => 'Vergi Dairesi',
            'email' => 'E-posta',
            'telefon' => 'Telefon',
            'mesaj' => 'Adres / Mesaj',
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,string>
     */
    private function invoiceView(array $row): array
    {
        if ($row === []) {
            return [];
        }
        $view = [];
        foreach (array_keys($this->invoiceLabels()) as $key) {
            $view[$key] = (string) ($row[$key] ?? '');
        }
        $view['faturatipi'] = $view['faturatipi'] === '' ? '' : ((int) $view['faturatipi'] === 2 ? 'Kurumsal' : 'Bireysel');

        return $view;
    }

    /**
     * Değişen alanlar: [{alan, eski, yeni}]. Eski boşsa ekleme, yeni boşsa silme/temizleme.
     *
     * @param array<string,string> $labels
     * @param array<string,mixed> $old
     * @param array<string,mixed> $new
     * @return array<int,array{alan:string,eski:string,yeni:string}>
     */
    private function diff(array $labels, array $old, array $new): array
    {
        $changes = [];
        foreach ($labels as $key => $label) {
            $before = trim((string) ($old[$key] ?? ''));
            $after = trim((string) ($new[$key] ?? ''));
            if ($before !== $after) {
                $changes[] = ['alan' => $label, 'eski' => $before, 'yeni' => $after];
            }
        }

        return $changes;
    }

    /**
     * Tablonun kolonları; tablo yoksa veya kolon eksikse kurulum hatası (409 SETUP_REQUIRED).
     *
     * @param array<int,string> $required
     * @return array<string,array{name:string,max:int|null}>
     */
    private function requireColumns(PDO $pdo, string $table, array $required): array
    {
        $columns = $this->columns($pdo, $table);
        $missing = [];
        foreach ($required as $column) {
            if (!isset($columns[strtolower($column)])) {
                $missing[] = $column;
            }
        }
        if ($columns === [] || $missing !== []) {
            throw new HttpException(
                $table . ' tablosu bu sitede eksik kurulu'
                . ($columns === [] ? '' : ' (eksik kolon: ' . implode(', ', $missing) . ')')
                . '. Önce POST /setup/reservation-guests çalıştırılmalı.',
                'SETUP_REQUIRED',
                409
            );
        }

        return $columns;
    }

    /**
     * Tablonun kolonları: küçük harf ad => [gerçek ad, karakter sınırı]. Tablo yoksa boş dizi.
     *
     * @return array<string,array{name:string,max:int|null}>
     */
    private function columns(PDO $pdo, string $table): array
    {
        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }
        $parts = explode('.', $table, 2);
        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table'
        );
        $this->execute($stmt, [':schema' => $parts[0], ':table' => $parts[1]]);

        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $max = $row['CHARACTER_MAXIMUM_LENGTH'];
            $columns[strtolower((string) $row['COLUMN_NAME'])] = [
                'name' => (string) $row['COLUMN_NAME'],
                // -1: nvarchar(max), sınır yok.
                'max' => $max === null || (int) $max < 0 ? null : (int) $max,
            ];
        }
        $this->columnCache[$table] = $columns;

        return $columns;
    }

    /**
     * Değer kolonun karakter sınırını aşıyorsa kesmek yerine hata döner (veri kaybı olmasın).
     *
     * @param array<string,array{name:string,max:int|null}> $columns
     */
    private function fit(array $columns, string $column, string $value, string $label): string
    {
        $max = $columns[strtolower($column)]['max'] ?? null;
        if ($max !== null && mb_strlen($value) > $max) {
            throw new HttpException($label . ' en fazla ' . $max . ' karakter olabilir.', 'VALIDATION', 422);
        }

        return $value;
    }

    /** Gövdeden metin; tek satırlıda ardışık boşluklar teke indirilir. */
    private function text(string $key, bool $multiline = false): string
    {
        $value = $this->request->input($key, '');
        if ($value === null || is_array($value)) {
            return '';
        }
        $value = trim((string) $value);

        return $multiline ? $value : (string) preg_replace('/\s+/u', ' ', $value);
    }

    /** @return int|null 1 Erkek, 0 Kadın */
    private function gender()
    {
        $value = $this->request->input('cinsiyet');
        if ($value === null || $value === '') {
            return null;
        }
        if (!in_array((string) $value, ['0', '1'], true)) {
            throw new HttpException('Cinsiyet 1 (Erkek) ya da 0 (Kadın) olmalı.', 'VALIDATION', 422);
        }

        return (int) $value;
    }

    /** @return string|null yyyy-MM-dd */
    private function date(string $key)
    {
        $value = $this->text($key);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new HttpException('Doğum tarihi geçersiz (yyyy-MM-dd bekleniyor).', 'VALIDATION', 422);
        }

        return $value;
    }

    /**
     * kullanici_log_kaydi'na yazar (ReservationStatusController ile aynı biçim). Natsisa'dan gelen işlemde kişinin
     * adı açıklamanın sonuna "(Ad)" olarak eklenir; ayrıntılar degisiklikler kolonuna JSON yazılır.
     *
     * @param array<int,array{alan:string,eski:string,yeni:string}> $changes
     */
    private function log(PDO $pdo, int $reservationId, string $message, string $action, array $changes): void
    {
        $userName = $this->userName();
        if ($userName !== '') {
            $message .= ' (' . $userName . ')';
        }

        $params = [
            ':islmId' => (string) $reservationId,
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

    /** Natsisa'dan gelen işlemi yapan kişinin adı (en fazla 100 karakter, tek satır). */
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

    /**
     * Parametreleri tipine göre bağlayıp çalıştırır (null -> NULL, int -> INT).
     *
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
}
