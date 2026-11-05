<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Support\HomesCode;
use App\Support\Uploads;
use PDO;

/*
 * Villa kopyalama: secilen villanin verileriyle yeni (pasif) bir villa olusturur.
 *   POST /backend-api/homes-copy  { homeId }  -> { id, copied: {tablo: satir}, skipped: [...] }
 * Kopyalanan: homes satiri (ayni baslik, aktif kolonlari 0, benzersiz url, evkodu bos/otomatik, 7464 belge no bos),
 *   sezonlar (islem='emlak'), HomesExtraPayments, HomesExtraPaymentPrices (SeasonId yeni sezonlara eslenir),
 *   mesafelerValues, havuztanimlamari, odalarValues, rulesHomes, indirimler, upload (fotograflar, dosyalariyla).
 * Kopyalanmayan: dolu/rezervasyonlar, Kiralama Takvimi baglantisi (CalendarHomes), kanun7464 belge kaydi.
 * Hepsi tek transaction'da; bir adim hata verirse DB geri alinir ve kopyalanan foto dosyalari silinir.
 */
final class HomesCopyController extends Controller
{
    /** @var array<int,string> kopyalanan foto dosyalari (hata olursa silinir) */
    private $copiedFiles = [];

    /** @Post */
    public function copy(): void
    {
        $sourceId = filter_var($this->request->input('homeId'), FILTER_VALIDATE_INT);
        if ($sourceId === false || $sourceId <= 0) {
            throw new HttpException('Kopyalanacak villa secilmedi.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();
        $copied = [];
        $skipped = [];

        $pdo->beginTransaction();
        try {
            $newId = $this->copyHome($pdo, $sourceId);

            // Sezonlar tek tek kopyalanir: ekstra odeme fiyatlari SeasonId ile yeni sezonlara baglanir.
            $seasonMap = $this->copyRows($pdo, 'sezonlar', "islem = 'emlak' AND islem_id = :old", ['islem_id' => $newId], $sourceId, $copied, $skipped, true);
            $this->copyRows($pdo, 'HomesExtraPayments', 'homesId = :old', ['homesId' => $newId], $sourceId, $copied, $skipped);
            $this->copyRows($pdo, 'HomesExtraPaymentPrices', 'HomesId = :old AND ISNULL(IsDeleted, 0) = 0', ['HomesId' => $newId], $sourceId, $copied, $skipped, false,
                function (array $row) use ($seasonMap) {
                    $season = isset($row['SeasonId']) ? (int) $row['SeasonId'] : 0;
                    if ($season > 0) $row['SeasonId'] = $seasonMap[$season] ?? null;
                    return $row;
                });
            $this->copyRows($pdo, 'mesafelerValues', 'homesId = :old', ['homesId' => $newId], $sourceId, $copied, $skipped);
            $this->copyRows($pdo, 'havuztanimlamari', 'homesId = :old', ['homesId' => $newId], $sourceId, $copied, $skipped);
            $this->copyRows($pdo, 'odalarValues', 'homesId = :old', ['homesId' => $newId], $sourceId, $copied, $skipped);
            $this->copyRows($pdo, 'rulesHomes', 'homesId = :old', ['homesId' => $newId], $sourceId, $copied, $skipped);
            $this->copyRows($pdo, 'indirimler', 'emlak = :old', ['emlak' => $newId], $sourceId, $copied, $skipped);
            $copied['upload'] = $this->copyPhotos($pdo, $sourceId, $newId);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($this->copiedFiles as $file) @unlink($file);
            throw $e;
        }

        \App\Support\HomesCompletion::refresh($pdo, (int) $newId);

        $this->response->success([
            'message' => 'Villa kopyalandi',
            'id' => $newId,
            'copied' => $copied,
            'skipped' => $skipped,
        ], 201);
    }

    private function copyHome(PDO $pdo, int $sourceId): int
    {
        $stmt = $pdo->prepare('SELECT * FROM homes WHERE id = :id');
        $stmt->execute(['id' => $sourceId]);
        $home = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$home) throw new HttpException('Kopyalanacak villa bulunamadi.', 'NOT_FOUND', 404);

        $columns = $this->columns($pdo, 'homes');
        $values = [];
        foreach ($home as $column => $value) {
            if (!isset($columns[strtolower($column)])) continue;
            $values[$column] = $value;
        }

        $title = trim((string) ($home['baslik'] ?? ''));
        foreach (array_keys($values) as $column) {
            $lower = strtolower($column);
            if ($lower === 'baslik') {
                $values[$column] = $title !== '' ? $title : 'Villa';
            } elseif (preg_match('/^aktif(_[a-z0-9]+)?$/', $lower) === 1) {
                $values[$column] = 0;
            } elseif (preg_match('/^url(_[a-z0-9]+)?$/', $lower) === 1) {
                $base = trim((string) $values[$column]) !== '' ? $values[$column] : $title;
                $values[$column] = $this->uniqueUrl($pdo, $column, $this->slug((string) $base));
            } elseif (in_array($lower, ['evkodu', 'gavelbelgeno', 'resim'], true)) {
                // evkodu otomatik moddaysa asagida yeniden verilir; resim (kapak) foto kopyasindan sonra yazilir.
                $values[$column] = $columns[$lower]['nullable'] ? null : '';
            }
        }

        if (isset($columns['id']) && !$columns['id']['identity']) {
            $values['id'] = (int) $pdo->query('SELECT ISNULL(MAX(id), 0) + 1 FROM homes WITH (UPDLOCK, HOLDLOCK)')->fetchColumn();
        } else {
            unset($values['id'], $values['ID'], $values['Id']);
        }

        $newId = $this->insert($pdo, 'homes', $values, $columns);
        if (isset($values['id'])) $newId = (int) $values['id'];
        if ($newId <= 0) throw new HttpException('Kopya villanin ID degeri alinamadi.', 'DB_CREATE_FAILED', 500);

        HomesCode::applyAuto($pdo, $this->app, $newId, [], true);

        return $newId;
    }

    /**
     * Kaynak villanin satirlarini yeni villaya kopyalar. Tablo yoksa atlanir.
     * $withMap: eski id => yeni id eslemesi doner (kimlik kolonu olan tablolarda).
     *
     * @param array<string,mixed> $overrides
     * @param array<string,int> $copied
     * @param array<int,array<string,string>> $skipped
     * @return array<int,int>
     */
    private function copyRows(PDO $pdo, string $table, string $where, array $overrides, int $sourceId, array &$copied, array &$skipped, bool $withMap = false, ?callable $transform = null): array
    {
        $columns = $this->columns($pdo, $table);
        if ($columns === []) {
            $skipped[] = ['table' => $table, 'reason' => 'Tablo bu kurulumda yok.'];
            return [];
        }
        foreach (array_keys($overrides) as $column) {
            if (!isset($columns[strtolower($column)])) {
                $skipped[] = ['table' => $table, 'reason' => $column . ' kolonu yok.'];
                return [];
            }
        }

        $stmt = $pdo->prepare('SELECT * FROM dbo.[' . $table . '] WHERE ' . $where);
        $stmt->execute(['old' => $sourceId]);
        $identity = $this->identityColumn($columns);
        $map = [];
        $count = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $oldKey = $identity !== null ? (int) ($this->value($row, $identity) ?? 0) : 0;
            foreach ($overrides as $column => $value) $row = $this->setValue($row, $column, $value);
            if ($transform !== null) $row = $transform($row);
            $newKey = $this->insert($pdo, $table, $row, $columns);
            if ($withMap && $oldKey > 0) $map[$oldKey] = $newKey;
            $count++;
        }
        $copied[$table] = $count;

        return $map;
    }

    /**
     * Fotograflari kopyalar: dosya ayni adla uploads/property/{yeniId}/ altina, upload satiri yeni villaya.
     * Dosyasi bulunamayan foto atlanir. Kapaklar (homes.resim) yeni adlarla yazilir.
     */
    private function copyPhotos(PDO $pdo, int $sourceId, int $newId): int
    {
        $columns = $this->columns($pdo, 'upload');
        if ($columns === []) return 0;

        $stmt = $pdo->prepare("SELECT * FROM dbo.upload WHERE islm = 'emlak' AND islm_id = :id ORDER BY sira, uploadID");
        $stmt->execute(['id' => $sourceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) return 0;

        $uploadsDir = Uploads::dir();
        $sourceDir = $uploadsDir . DIRECTORY_SEPARATOR . 'property' . DIRECTORY_SEPARATOR . $sourceId;
        $targetDir = $uploadsDir . DIRECTORY_SEPARATOR . 'property' . DIRECTORY_SEPARATOR . $newId;
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
            throw new HttpException('Kopya villanin foto klasoru olusturulamadi.', 'MKDIR_FAILED', 500);
        }

        $renamed = [];
        $count = 0;
        foreach ($rows as $row) {
            $oldName = basename(str_replace('\\', '/', trim((string) ($row['FileName'] ?? ''))));
            if ($oldName === '') continue;
            $source = is_file($sourceDir . DIRECTORY_SEPARATOR . $oldName)
                ? $sourceDir . DIRECTORY_SEPARATOR . $oldName
                : (is_file($uploadsDir . DIRECTORY_SEPARATOR . $oldName) ? $uploadsDir . DIRECTORY_SEPARATOR . $oldName : null);
            if ($source === null) continue;

            // Dosya adi korunur; yeni villanin kendi klasorune kopyalanir.
            $candidate = $oldName;
            $target = $targetDir . DIRECTORY_SEPARATOR . $candidate;
            if (!@copy($source, $target)) throw new HttpException('Foto kopyalanamadi: ' . $oldName, 'COPY_FAILED', 500);
            $this->copiedFiles[] = $target;

            $row = $this->setValue($row, 'islm_id', $newId);
            $row = $this->setValue($row, 'FileName', $candidate);
            $this->insert($pdo, 'upload', $row, $columns);
            $renamed[$oldName] = $candidate;
            $count++;
        }

        // Kapaklar: kaynak villanin homes.resim adlari yeni dosya adlarina cevrilir.
        $cover = $pdo->prepare('SELECT resim FROM homes WHERE id = :id');
        $cover->execute(['id' => $sourceId]);
        $covers = array_values(array_filter(array_map('trim', explode(',', (string) $cover->fetchColumn())), 'strlen'));
        $newCovers = array_values(array_filter(array_map(function ($name) use ($renamed) { return $renamed[basename($name)] ?? null; }, $covers)));
        if ($newCovers === [] && $renamed !== []) $newCovers = [reset($renamed)];
        if ($newCovers !== []) {
            $pdo->prepare('UPDATE homes SET resim = :resim WHERE id = :id')->execute(['resim' => implode(',', $newCovers), 'id' => $newId]);
        }

        return $count;
    }

    /**
     * Satiri ekler; kimlik/hesaplanan/rowversion kolonlari ve tabloda olmayan alanlar atlanir. Yeni kimligi doner (yoksa 0).
     *
     * @param array<string,mixed> $row
     * @param array<string,array{name:string,identity:bool,nullable:bool}> $columns
     */
    private function insert(PDO $pdo, string $table, array $row, array $columns): int
    {
        $names = [];
        $params = [];
        $i = 0;
        foreach ($row as $column => $value) {
            $info = $columns[strtolower((string) $column)] ?? null;
            if ($info === null || $info['identity']) continue;
            $names[] = '[' . $info['name'] . ']';
            $params['p' . $i++] = $value;
        }
        if ($names === []) throw new HttpException($table . ' icin kopyalanacak kolon yok.', 'COPY_FAILED', 500);

        $stmt = $pdo->prepare('SET NOCOUNT ON; INSERT INTO dbo.[' . $table . '] (' . implode(', ', $names) . ') VALUES (:'
            . implode(', :', array_keys($params)) . '); SELECT CAST(SCOPE_IDENTITY() AS INT) AS id;');
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, $value === null ? PDO::PARAM_NULL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR));
        }
        $stmt->execute();
        $id = 0;
        do {
            if ($stmt->columnCount() > 0) {
                $value = $stmt->fetchColumn();
                if ($value !== false && $value !== null) $id = (int) $value;
            }
        } while ($stmt->nextRowset());

        return $id;
    }

    /**
     * Yazilabilir kolonlar (kucuk harf anahtar). Hesaplanan ve rowversion kolonlari dahil edilmez. Tablo yoksa bos.
     *
     * @return array<string,array{name:string,identity:bool,nullable:bool}>
     */
    private function columns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare("SELECT c.name, c.is_identity, c.is_nullable
            FROM sys.columns c INNER JOIN sys.types t ON t.user_type_id = c.user_type_id
            WHERE c.object_id = OBJECT_ID(:t) AND c.is_computed = 0 AND t.name NOT IN ('timestamp', 'rowversion')");
        $stmt->execute(['t' => 'dbo.' . $table]);
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[strtolower((string) $row['name'])] = [
                'name' => (string) $row['name'],
                'identity' => (bool) $row['is_identity'],
                'nullable' => (bool) $row['is_nullable'],
            ];
        }
        return $columns;
    }

    /** @param array<string,array{name:string,identity:bool,nullable:bool}> $columns */
    private function identityColumn(array $columns): ?string
    {
        foreach ($columns as $info) if ($info['identity']) return $info['name'];
        return null;
    }

    /** @return mixed */
    private function value(array $row, string $column)
    {
        foreach ($row as $key => $value) if (strcasecmp((string) $key, $column) === 0) return $value;
        return null;
    }

    /** @param mixed $value */
    private function setValue(array $row, string $column, $value): array
    {
        foreach (array_keys($row) as $key) {
            if (strcasecmp((string) $key, $column) === 0) {
                $row[$key] = $value;
                return $row;
            }
        }
        $row[$column] = $value;
        return $row;
    }

    private function uniqueUrl(PDO $pdo, string $column, string $slug): string
    {
        $stmt = $pdo->prepare('SELECT LOWER([' . $column . ']) FROM homes WITH (UPDLOCK, HOLDLOCK) WHERE [' . $column . '] = :a OR [' . $column . '] LIKE :b');
        $stmt->execute(['a' => $slug, 'b' => $slug . '-%']);
        $taken = array_flip(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        $url = $slug;
        for ($n = 2; isset($taken[$url]); $n++) $url = $slug . '-' . $n;
        return $url;
    }

    private function slug(string $value): string
    {
        $value = strtr($value, ['Ç' => 'c', 'Ğ' => 'g', 'İ' => 'i', 'I' => 'i', 'Ö' => 'o', 'Ş' => 's', 'Ü' => 'u',
            'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u']);
        $value = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');
        return $value !== '' ? $value : 'villa';
    }
}
