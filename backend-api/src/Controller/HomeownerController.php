<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Support\LegacySql;
use App\Support\Uploads;
use PDO;

/*
 * Ev sahibi kaynagi (dbo.kullanici, yetki = 2).
 *   POST /backend-api/homeowner -> yeni ev sahibi ekler (ilan duzenleme > Ev Sahibi Ekle)
 *   POST /backend-api/homeowner/match -> Natsisa kisisine en cok benzeyen ev sahipleri (puanli)
 *   GET  /backend-api/homeowner/villas -> ev sahibine bagli villalar (homes.evsahibi)
 *   GET  /backend-api/homeowner/villa-options -> baglanabilecek villalar (arama)
 *   POST /backend-api/homeowner/assign -> villayi ev sahibine baglar
 *   POST /backend-api/homeowner/unassign -> villayi ev sahibinden ayirir (homes.evsahibi = 0, panel ev_kaldir gibi)
 *   GET  /backend-api/homeowner/bank-accounts -> ev sahibinin banka hesaplari (hesaplar.kullanici = ownerId)
 *   POST /backend-api/homeowner/bank-accounts/save -> hesap ekler / gunceller (silme yok: BTrans kayitlar.btrans_iban = hesaplar.id)
 *
 * Villalar kismi panel profil.asp (ev sahibi > Villalar sekmesi) karsiligi. Natsisa'daki ev sahipleri panelden
 * kopyalanmis kisilerdir ve panel id'si tutulmaz; bu yuzden once match ile paneldeki ev sahibi bulunur
 * (her seferinde yeniden puanlanir, secim saklanmaz).
 *
 * Panel yeni_kullanici_ekle.asp (yetki=2) karsiligi; farklar:
 * - aktif = 1 eklenir (ilan formundaki ev sahibi listesi sadece aktif=1 olanlari gosterir).
 * - Panel girisi verilmez: kullanici_adi bos/NULL, sifre rastgele (bilinmeyen) bir degerdir.
 * - Ayni telefonla kayitli ev sahibi varsa yeni kayit acilmaz, mevcut kayit doner (existing = true).
 */
final class HomeownerController extends Controller
{
    use LegacySql;

    /**
     * Ev sahibi ekler.
     *
     * @Post
     * @body name string Ad soyad (ad/soyad verilmezse buradan bolunur: son kelime soyad)
     * @body ad string Opsiyonel ad
     * @body soyad string Opsiyonel soyad
     * @body tel string Telefon
     * @body email string Opsiyonel e-posta
     * @body adres string Opsiyonel adres
     */
    public function create(): void
    {
        $ad = $this->cleanName((string) $this->inputAny(['ad', 'isim'], ''));
        $soyad = $this->cleanName((string) $this->inputAny(['soyad', 'soyisim'], ''));
        if ($ad === '' && $soyad === '') {
            $split = $this->splitName((string) $this->inputAny(['name', 'ad_soyad', 'adSoyad'], ''));
            $ad = $split['ad'];
            $soyad = $split['soyad'];
        }
        if (!$this->hasLetter($ad . $soyad)) {
            throw new HttpException('Ev sahibi adı harf içermeli.', 'VALIDATION', 422);
        }

        $tel = trim((string) $this->inputAny(['tel', 'phone', 'telefon'], ''));
        $email = trim((string) $this->inputAny(['email', 'eposta'], ''));
        $adres = trim((string) $this->inputAny(['adres', 'address'], ''));

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException('E-posta adresi geçersiz.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();

        // Ayni telefonla kayitli ev sahibi varsa onu don (cift kayit acilmasin).
        $phoneKey = $this->phoneKey($tel);
        if ($phoneKey !== '') {
            $existingId = $this->findOwnerByPhone($pdo, $phoneKey);
            if ($existingId > 0) {
                $this->response->success([
                    'homeowner' => $this->fetchOwner($pdo, $existingId),
                    'existing' => true,
                ]);
                return;
            }
        }

        $columns = $this->kullaniciColumns($pdo);
        $values = [
            'ad' => $ad,
            'soyad' => $soyad,
            'tel' => $tel !== '' ? $tel : null,
            'yetki' => 2,
            'aktif' => 1,
        ];
        if (isset($columns['email'])) {
            $values['email'] = $email !== '' ? $email : null;
        }
        if (isset($columns['adres'])) {
            $values['adres'] = $adres !== '' ? $adres : null;
        }
        if (isset($columns['resim'])) {
            $values['resim'] = 'profile.jpg';
        }
        // Panel girisi yok: kullanici adi NULL (zorunluysa tahmin edilemez bir deger), sifre rastgele.
        if (isset($columns['kullanici_adi']) && !$columns['kullanici_adi']) {
            $values['kullanici_adi'] = 'evsahibi_' . bin2hex(random_bytes(6));
        }
        if (isset($columns['sifre'])) {
            $values['sifre'] = bin2hex(random_bytes(16));
        }

        // NOT NULL kolona NULL yazilmasin: zorunlu metin kolonlarinda bos metin kullanilir.
        foreach ($values as $column => $value) {
            if ($value === null && isset($columns[$column]) && !$columns[$column]) {
                $values[$column] = '';
            }
        }

        // SET NOCOUNT + SCOPE_IDENTITY ile eklenir (OUTPUT INSERTED trigger'li tabloda hata, sqlsrv'de id 0 donebiliyor).
        // Gonderilmeyen NOT NULL + varsayilansiz kolonlar eski panel kayitlari gibi bos deger alir.
        $id = $this->insertRow($pdo, 'kullanici', $values);

        $this->response->success([
            'homeowner' => $this->fetchOwner($pdo, $id),
            'existing' => false,
        ], 201);
    }

    /** Eslesme puanlari: kesin kimlik alanlari yuksek, isim gibi tekrar edebilen alanlar dusuk. */
    private const MATCH_WEIGHTS = [
        'tc' => 40,
        'vkn' => 40,
        'iban' => 40,
        'tel' => 30,
        'email' => 20,
        'adSoyad' => 20,
        'soyad' => 5,
        'unvan' => 15,
    ];

    private const MATCH_LABELS = [
        'tc' => 'TC kimlik no',
        'vkn' => 'VKN',
        'iban' => 'IBAN',
        'tel' => 'Telefon',
        'email' => 'E-posta',
        'adSoyad' => 'Ad soyad',
        'soyad' => 'Soyad',
        'unvan' => 'Unvan',
    ];

    /** Otomatik secim: en az MATCH_MIN puan ve ikinciden en az MATCH_LEAD fark. */
    private const MATCH_MIN = 50;
    private const MATCH_LEAD = 20;

    /** @var array<string,bool> */
    private $homesColumnCache = [];

    /**
     * Natsisa kisisine en cok benzeyen ev sahiplerini puanla doner (ilk 5). Bos alanlar puan almaz, ceza da almaz.
     * Tek ve acik farkla onde olan aday selectedId olarak doner; degilse kullanici adaylardan secer.
     *
     * @Post("match")
     * @body ad string Ad
     * @body soyad string Soyad
     * @body tel string Telefon
     * @body email string E-posta
     * @body tc string TC kimlik no
     * @body vkn string Vergi kimlik no
     * @body unvan string Firma unvani
     * @body iban string IBAN
     */
    public function match(): void
    {
        $pdo = $this->db->pdo();
        $columns = $this->kullaniciColumns($pdo);

        $input = $this->matchKeys([
            'ad' => (string) $this->inputAny(['ad', 'name'], ''),
            'soyad' => (string) $this->inputAny(['soyad', 'surname'], ''),
            'tel' => (string) $this->inputAny(['tel', 'phone'], ''),
            'email' => (string) $this->inputAny(['email'], ''),
            'tc' => (string) $this->inputAny(['tc', 'identityNumber'], ''),
            'vkn' => (string) $this->inputAny(['vkn', 'taxNumber'], ''),
            'unvan' => (string) $this->inputAny(['unvan', 'companyTitle'], ''),
            'iban' => (string) $this->inputAny(['iban'], ''),
        ]);

        // tc / vkUnvan / vkN / email / aktif her sitenin kullanici tablosunda olmayabilir.
        $select = ['k.id', 'k.ad', 'k.soyad', 'k.tel'];
        foreach (['email' => 'email', 'tc' => 'tc', 'vkunvan' => 'vkUnvan', 'vkn' => 'vkN', 'aktif' => 'aktif'] as $lower => $column) {
            $select[] = isset($columns[$lower]) ? "k.{$column}" : "NULL AS {$column}";
        }
        // IBAN: ev sahibinin tum banka hesaplari (hesaplar.kullanici); STRING_AGG yerine FOR XML (eski SQL Server).
        $ibanSql = $this->tableExists($pdo, 'hesaplar')
            ? "STUFF((SELECT ',' + CONVERT(nvarchar(64), h.iban) FROM hesaplar h WHERE h.kullanici = k.id FOR XML PATH('')), 1, 1, '')"
            : 'NULL';
        $stmt = $pdo->query('SELECT ' . implode(', ', $select) . ", {$ibanSql} AS ibanlar FROM dbo.kullanici k WHERE k.yetki = 2");

        $candidates = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $owner = $this->matchKeys([
                'ad' => (string) ($row['ad'] ?? ''),
                'soyad' => (string) ($row['soyad'] ?? ''),
                'tel' => (string) ($row['tel'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'tc' => (string) ($row['tc'] ?? ''),
                'vkn' => (string) ($row['vkN'] ?? ''),
                'unvan' => (string) ($row['vkUnvan'] ?? ''),
                'iban' => '',
            ]);
            $ibans = [];
            foreach (explode(',', (string) ($row['ibanlar'] ?? '')) as $iban) {
                $key = $this->ibanKey($iban);
                if ($key !== '') {
                    $ibans[] = $key;
                }
            }

            $matched = [];
            foreach (['tc', 'vkn', 'tel', 'email', 'unvan'] as $field) {
                if ($input[$field] !== '' && $input[$field] === $owner[$field]) {
                    $matched[] = $field;
                }
            }
            if ($input['iban'] !== '' && in_array($input['iban'], $ibans, true)) {
                $matched[] = 'iban';
            }
            if ($input['adSoyad'] !== '' && $input['adSoyad'] === $owner['adSoyad']) {
                $matched[] = 'adSoyad';
            } elseif ($input['soyad'] !== '' && $input['soyad'] === $owner['soyad']) {
                $matched[] = 'soyad';
            }
            if ($matched === []) {
                continue;
            }

            $score = 0;
            foreach ($matched as $field) {
                $score += self::MATCH_WEIGHTS[$field];
            }
            $candidates[] = [
                'id' => (int) $row['id'],
                'name' => $this->cleanName(trim((string) ($row['ad'] ?? '')) . ' ' . trim((string) ($row['soyad'] ?? ''))),
                'phone' => trim((string) ($row['tel'] ?? '')),
                'email' => trim((string) ($row['email'] ?? '')),
                'aktif' => (int) ($row['aktif'] ?? 0),
                'score' => $score,
                'matched' => array_map(function (string $field): array {
                    return ['field' => $field, 'label' => self::MATCH_LABELS[$field]];
                }, $matched),
            ];
        }

        usort($candidates, function (array $a, array $b): int {
            return ($b['score'] <=> $a['score']) ?: ($b['id'] <=> $a['id']);
        });
        $candidates = array_slice($candidates, 0, 5);

        $selectedId = null;
        if ($candidates !== [] && $candidates[0]['score'] >= self::MATCH_MIN
            && (!isset($candidates[1]) || $candidates[0]['score'] - $candidates[1]['score'] >= self::MATCH_LEAD)) {
            $selectedId = $candidates[0]['id'];
        }

        $this->response->success([
            'candidates' => $candidates,
            'selectedId' => $selectedId,
            'rules' => ['weights' => self::MATCH_WEIGHTS, 'min' => self::MATCH_MIN, 'lead' => self::MATCH_LEAD],
        ]);
    }

    /**
     * Ev sahibi ve ona bagli villalar (homes.evsahibi = ownerId, silinmis villalar haric).
     *
     * @Get("villas")
     * @query ownerId int required Paneldeki ev sahibi id'si (kullanici.id)
     */
    public function villas(): void
    {
        $ownerId = $this->queryId('ownerId');
        $pdo = $this->db->pdo();
        $owner = $this->requireOwner($pdo, $ownerId);

        $stmt = $pdo->prepare($this->homesSelectSql($pdo, 'TRY_CONVERT(int, homes.evsahibi) = :ownerId', 'homes.baslik, homes.id'));
        $stmt->bindValue(':ownerId', $ownerId, PDO::PARAM_INT);
        $stmt->execute();

        $this->response->success([
            'owner' => $owner,
            'villas' => array_map([$this, 'villaRow'], $stmt->fetchAll(PDO::FETCH_ASSOC)),
        ]);
    }

    /**
     * Ev sahibine baglanabilecek villalar: ad veya kodla arama (en fazla 30). Su anki ev sahibi de doner.
     *
     * @Get("villa-options")
     * @query q string Villa adi veya kodu
     */
    public function villaOptions(): void
    {
        $pdo = $this->db->pdo();
        $q = trim((string) $this->request->query('q', ''));
        $hasCode = $this->hasHomesColumn($pdo, 'evkodu');
        $where = '1 = 1';
        if ($q !== '') {
            $where = $hasCode ? '(homes.baslik LIKE :q OR homes.evkodu LIKE :q2)' : 'homes.baslik LIKE :q';
        }
        $stmt = $pdo->prepare($this->homesSelectSql($pdo, $where, 'homes.baslik', 30));
        if ($q !== '') {
            $like = '%' . str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $q) . '%';
            $stmt->bindValue(':q', $like);
            if ($hasCode) {
                $stmt->bindValue(':q2', $like);
            }
        }
        $stmt->execute();

        $this->response->success([
            'villas' => array_map([$this, 'villaRow'], $stmt->fetchAll(PDO::FETCH_ASSOC)),
        ]);
    }

    /**
     * Villayi ev sahibine baglar (panel profil.asp "Yeni Villa Ekle": homes.evsahibi = ownerId).
     * Villa baska bir ev sahibine bagliysa force verilmeden degistirilmez: assigned = false ve conflict doner.
     *
     * @Post("assign")
     * @body ownerId int required Paneldeki ev sahibi id'si
     * @body homesId int required Villa id'si
     * @body force bool Baska ev sahibine bagliysa yine de degistir
     */
    public function assign(): void
    {
        $ownerId = $this->bodyId('ownerId');
        $homesId = $this->bodyId('homesId');
        $force = filter_var($this->request->input('force', false), FILTER_VALIDATE_BOOLEAN);
        $pdo = $this->db->pdo();
        $owner = $this->requireOwner($pdo, $ownerId);
        $home = $this->requireHome($pdo, $homesId);

        $currentId = (int) ($home['evsahibi_id'] ?? 0);
        if ($currentId === $ownerId) {
            $this->response->success(['assigned' => true, 'unchanged' => true, 'villa' => $this->villaRow($home)]);
            return;
        }
        if ($currentId > 0 && !$force) {
            $this->response->success([
                'assigned' => false,
                'conflict' => ['ownerId' => $currentId, 'ownerName' => trim((string) ($home['evsahibi_adi'] ?? ''))],
                'villa' => $this->villaRow($home),
            ]);
            return;
        }

        $stmt = $pdo->prepare('UPDATE homes SET evsahibi = :ownerId WHERE id = :id');
        $stmt->bindValue(':ownerId', (string) $ownerId);
        $stmt->bindValue(':id', $homesId, PDO::PARAM_INT);
        $stmt->execute();

        $previous = $currentId > 0
            ? sprintf(' (önceki ev sahibi: %s #%d)', trim((string) ($home['evsahibi_adi'] ?? '')), $currentId)
            : '';
        $this->writeLog($pdo, 'ev_sahibi_villa_bagla', $homesId, sprintf(
            'Villa ev sahibine bağlandı: %s -> %s #%d%s',
            trim((string) ($home['baslik'] ?? '')),
            (string) $owner['name'],
            $ownerId,
            $previous
        ));

        $this->response->success(['assigned' => true, 'villa' => $this->villaRow($this->requireHome($pdo, $homesId))]);
    }

    /**
     * Villayi ev sahibinden ayirir (panel sil.asp ev_kaldir: homes.evsahibi = 0). Villa silinmez.
     * Sadece villa hala bu ev sahibine bagliysa yapilir.
     *
     * @Post("unassign")
     * @body ownerId int required Paneldeki ev sahibi id'si
     * @body homesId int required Villa id'si
     */
    public function unassign(): void
    {
        $ownerId = $this->bodyId('ownerId');
        $homesId = $this->bodyId('homesId');
        $pdo = $this->db->pdo();
        $owner = $this->requireOwner($pdo, $ownerId);
        $home = $this->requireHome($pdo, $homesId);

        if ((int) ($home['evsahibi_id'] ?? 0) !== $ownerId) {
            throw new HttpException('Bu villa artık bu ev sahibine bağlı değil.', 'VALIDATION', 422);
        }

        $stmt = $pdo->prepare("UPDATE homes SET evsahibi = '0' WHERE id = :id");
        $stmt->bindValue(':id', $homesId, PDO::PARAM_INT);
        $stmt->execute();

        $this->writeLog($pdo, 'ev_sahibi_villa_ayir', $homesId, sprintf(
            'Villa ev sahibinden ayrıldı: %s (%s #%d)',
            trim((string) ($home['baslik'] ?? '')),
            (string) $owner['name'],
            $ownerId
        ));

        $this->response->success(['unassigned' => true]);
    }

    /** Ev sahibi hesap alanlari (panel yeni_hesap_ekle.asp, cat <> 0): body anahtari => [kolon, en fazla uzunluk]. */
    private const OWNER_ACCOUNT_FIELDS = [
        'banka' => ['banka', 150],
        'hesapAdi' => ['hesap_adi', 200],
        'sube' => ['sube', 150],
        'subeKodu' => ['sube_kod', 50],
        'hesapNo' => ['hesap_no', 50],
        'iban' => ['iban', 34],
    ];

    /**
     * Ev sahibinin banka hesaplari (panel profil.asp / hesaplar.asp?id=). usedInBtrans: kac rezervasyonun BTrans
     * "odemenin yapildigi IBAN" alaninda (kayitlar.btrans_iban) bu hesap secili.
     *
     * @Get("bank-accounts")
     * @query ownerId int required Paneldeki ev sahibi id'si
     */
    public function bankAccounts(): void
    {
        $ownerId = $this->queryId('ownerId');
        $pdo = $this->db->pdo();
        $this->requireOwner($pdo, $ownerId);

        $this->response->success([
            'accounts' => $this->ownerAccounts($pdo, $ownerId),
            'fields' => array_keys($this->ownerAccountColumns($pdo)),
        ]);
    }

    /**
     * Ev sahibine banka hesabi ekler (id bos/0) veya gunceller. IBAN bosluksuz buyuk harf kaydedilir;
     * TR IBAN 26 karakter olmali. Silme yok: eski rezervasyonlarin BTrans IBAN'i hesap id'sine bakar.
     *
     * @Post("bank-accounts/save")
     * @body ownerId int required Paneldeki ev sahibi id'si
     * @body id int Guncellenecek hesap (bos = yeni)
     * @body banka string Banka adi
     * @body hesapAdi string Hesap sahibi
     * @body sube string Sube
     * @body subeKodu string Sube kodu
     * @body hesapNo string Hesap no
     * @body iban string IBAN
     */
    public function saveBankAccount(): void
    {
        $ownerId = $this->bodyId('ownerId');
        $rawId = $this->request->input('id', 0);
        $accountId = $rawId === null || $rawId === '' || (int) $rawId === 0 ? 0 : $this->validId($rawId);
        $pdo = $this->db->pdo();
        $owner = $this->requireOwner($pdo, $ownerId);
        $columns = $this->ownerAccountColumns($pdo);

        $values = [];
        foreach ($columns as $key => $column) {
            if (!array_key_exists($key, $this->request->json())) {
                continue;
            }
            $value = trim((string) preg_replace('/\s+/u', ' ', (string) $this->request->input($key, '')));
            if ($key === 'iban') {
                $value = strtoupper((string) preg_replace('/\s+/', '', $value));
                if ($value !== '' && !preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/', $value)) {
                    throw new HttpException('IBAN geçersiz.', 'VALIDATION', 422);
                }
                if (strpos($value, 'TR') === 0 && strlen($value) !== 26) {
                    throw new HttpException('TR IBAN 26 karakter olmalı.', 'VALIDATION', 422);
                }
            }
            $max = self::OWNER_ACCOUNT_FIELDS[$key][1];
            if (mb_strlen($value, 'UTF-8') > $max) {
                throw new HttpException(sprintf('%s en fazla %d karakter olabilir.', $key, $max), 'VALIDATION', 422);
            }
            $values[$column] = $value;
        }
        if (($values['banka'] ?? '') === '' && ($values['iban'] ?? '') === '' && $accountId === 0) {
            throw new HttpException('Banka adı veya IBAN girilmeli.', 'VALIDATION', 422);
        }

        if ($accountId > 0) {
            $stmt = $pdo->prepare('SELECT id FROM hesaplar WHERE id = :id AND kullanici = :ownerId');
            $stmt->execute([':id' => $accountId, ':ownerId' => $ownerId]);
            if ($stmt->fetchColumn() === false) {
                throw new HttpException('Banka hesabı bu ev sahibine ait değil.', 'NOT_FOUND', 404);
            }
            if ($values !== []) {
                $sets = [];
                $params = [':id' => $accountId];
                foreach ($values as $column => $value) {
                    $sets[] = "{$column} = :{$column}";
                    $params[':' . $column] = $value;
                }
                $pdo->prepare('UPDATE hesaplar SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
            }
            $message = 'Ev sahibi banka hesabı güncellendi';
        } else {
            $tableColumns = $this->tableColumns($pdo, 'hesaplar');
            $values['kullanici'] = $ownerId;
            if (in_array('aktif', $tableColumns, true)) {
                $values['aktif'] = 1;
            }
            if (in_array('siralama', $tableColumns, true)) {
                $stmt = $pdo->prepare('SELECT ISNULL(MAX(TRY_CONVERT(int, siralama)), 0) + 1 FROM hesaplar WHERE kullanici = :ownerId');
                $stmt->execute([':ownerId' => $ownerId]);
                $values['siralama'] = (int) $stmt->fetchColumn();
            }
            $accountId = $this->insertRow($pdo, 'hesaplar', $values);
            $message = 'Ev sahibine banka hesabı eklendi';
        }

        $this->writeLog($pdo, 'ev_sahibi_banka_hesabi', $accountId, sprintf(
            '%s: %s #%d, hesap #%d (%s %s)',
            $message,
            (string) $owner['name'],
            $ownerId,
            $accountId,
            (string) ($values['banka'] ?? ''),
            (string) ($values['iban'] ?? '')
        ));

        $this->response->success([
            'id' => $accountId,
            'accounts' => $this->ownerAccounts($pdo, $ownerId),
        ]);
    }

    /**
     * Bu kurulumda olan ev sahibi hesap kolonlari: body anahtari => kolon.
     *
     * @return array<string,string>
     */
    private function ownerAccountColumns(PDO $pdo): array
    {
        $tableColumns = $this->tableColumns($pdo, 'hesaplar');
        $result = [];
        foreach (self::OWNER_ACCOUNT_FIELDS as $key => $def) {
            if (in_array($def[0], $tableColumns, true)) {
                $result[$key] = $def[0];
            }
        }

        return $result;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function ownerAccounts(PDO $pdo, int $ownerId): array
    {
        if (!$this->tableExists($pdo, 'hesaplar')) {
            return [];
        }
        $columns = $this->ownerAccountColumns($pdo);
        $tableColumns = $this->tableColumns($pdo, 'hesaplar');
        $order = in_array('siralama', $tableColumns, true) ? 'TRY_CONVERT(int, h.siralama), h.id' : 'h.id';
        $usedSql = $this->hasKayitlarColumn($pdo, 'btrans_iban')
            ? '(SELECT COUNT(*) FROM kayitlar k WHERE k.btrans_iban = CONVERT(nvarchar(20), h.id))'
            : '0';
        $select = ['h.id', "{$usedSql} AS used_in_btrans"];
        foreach ($columns as $column) {
            $select[] = "h.{$column}";
        }
        $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . " FROM hesaplar h WHERE h.kullanici = :ownerId ORDER BY {$order}");
        $stmt->execute([':ownerId' => $ownerId]);

        $accounts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $account = ['id' => (int) $row['id'], 'usedInBtrans' => (int) ($row['used_in_btrans'] ?? 0)];
            foreach (self::OWNER_ACCOUNT_FIELDS as $key => $def) {
                $account[$key] = trim((string) ($row[$def[0]] ?? ''));
            }
            $accounts[] = $account;
        }

        return $accounts;
    }

    private function hasKayitlarColumn(PDO $pdo, string $column): bool
    {
        return in_array(strtolower($column), $this->tableColumns($pdo, 'kayitlar'), true);
    }

    /**
     * Karsilastirma anahtarlari: telefon son 10 hane, isim/unvan Turkce harfsiz ve bosluksuz,
     * TC 11 / VKN 10 hane, IBAN bosluksuz buyuk harf. Gecersiz/kisa degerler bos sayilir (puan almaz).
     *
     * @param array<string,string> $raw
     * @return array<string,string>
     */
    private function matchKeys(array $raw): array
    {
        $ad = $this->textKey($raw['ad']);
        $soyad = $this->textKey($raw['soyad']);
        $email = mb_strtolower(trim($raw['email']), 'UTF-8');
        $tc = (string) preg_replace('/\D+/', '', $raw['tc']);
        $vkn = (string) preg_replace('/\D+/', '', $raw['vkn']);
        $unvan = $this->textKey($raw['unvan']);

        return [
            'adSoyad' => $ad !== '' ? $ad . $soyad : '',
            'soyad' => strlen($soyad) >= 2 ? $soyad : '',
            'tel' => $this->phoneKey($raw['tel']),
            'email' => strpos($email, '@') !== false ? $email : '',
            'tc' => strlen($tc) === 11 ? $tc : '',
            'vkn' => strlen($vkn) === 10 ? $vkn : '',
            'unvan' => strlen($unvan) >= 3 ? $unvan : '',
            'iban' => $this->ibanKey($raw['iban']),
        ];
    }

    private function textKey(string $value): string
    {
        $value = strtr(trim($value), [
            'İ' => 'i', 'I' => 'i', 'ı' => 'i', 'Ş' => 's', 'ş' => 's', 'Ğ' => 'g', 'ğ' => 'g',
            'Ü' => 'u', 'ü' => 'u', 'Ö' => 'o', 'ö' => 'o', 'Ç' => 'c', 'ç' => 'c',
            'Â' => 'a', 'â' => 'a', 'Î' => 'i', 'î' => 'i', 'Û' => 'u', 'û' => 'u',
        ]);

        return (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower($value, 'UTF-8'));
    }

    private function ibanKey(string $iban): string
    {
        $key = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $iban));

        return strlen($key) >= 15 ? $key : '';
    }

    /**
     * @return array<string,mixed>
     */
    private function requireOwner(PDO $pdo, int $ownerId): array
    {
        $stmt = $pdo->prepare('SELECT id FROM dbo.kullanici WHERE id = :id AND yetki = 2');
        $stmt->bindValue(':id', $ownerId, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->fetchColumn() === false) {
            throw new HttpException('Ev sahibi bulunamadı.', 'NOT_FOUND', 404);
        }

        return $this->fetchOwner($pdo, $ownerId);
    }

    /**
     * @return array<string,mixed>
     */
    private function requireHome(PDO $pdo, int $homesId): array
    {
        $stmt = $pdo->prepare($this->homesSelectSql($pdo, 'homes.id = :id', 'homes.id'));
        $stmt->bindValue(':id', $homesId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new HttpException('Villa bulunamadı.', 'NOT_FOUND', 404);
        }

        return $row;
    }

    /** Villa sorgusu: kod/resim/aktif kolonlari her kurulumda olmayabilir; silinmis villalar (isDeleted) gelmez. */
    private function homesSelectSql(PDO $pdo, string $where, string $orderBy, int $top = 0): string
    {
        $code = $this->hasHomesColumn($pdo, 'evkodu') ? 'homes.evkodu' : "N''";
        $image = $this->hasHomesColumn($pdo, 'resim') ? 'homes.resim' : "N''";
        $active = $this->hasHomesColumn($pdo, 'aktif') ? 'homes.aktif' : 'NULL';
        if ($this->hasHomesColumn($pdo, 'isdeleted')) {
            $where = "({$where}) AND ISNULL(homes.isDeleted, 0) = 0";
        }
        $topSql = $top > 0 ? 'TOP ' . $top . ' ' : '';

        return "SELECT {$topSql}homes.id, homes.baslik, {$code} AS evkodu, {$image} AS resim, {$active} AS aktif,
                       ISNULL(TRY_CONVERT(int, homes.evsahibi), 0) AS evsahibi_id,
                       LTRIM(RTRIM(ISNULL(es.ad, N'') + N' ' + ISNULL(es.soyad, N''))) AS evsahibi_adi
                FROM homes
                LEFT JOIN dbo.kullanici es ON es.id = TRY_CONVERT(int, homes.evsahibi) AND TRY_CONVERT(int, homes.evsahibi) > 0
                WHERE {$where}
                ORDER BY {$orderBy}";
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function villaRow(array $row): array
    {
        $id = (int) ($row['id'] ?? 0);
        $images = array_values(array_filter(array_map('trim', explode(',', (string) ($row['resim'] ?? '')))));
        $ownerId = (int) ($row['evsahibi_id'] ?? 0);

        return [
            'id' => $id,
            'title' => trim((string) ($row['baslik'] ?? '')),
            'code' => trim((string) ($row['evkodu'] ?? '')),
            'image' => $images !== [] ? Uploads::homeImageBase($this->imageBase(), $id, $this->app) . $images[0] : null,
            'active' => ($row['aktif'] ?? null) === null ? null : (int) $row['aktif'] === 1,
            'ownerId' => $ownerId > 0 ? $ownerId : null,
            'ownerName' => $ownerId > 0 ? trim((string) ($row['evsahibi_adi'] ?? '')) : null,
        ];
    }

    private function hasHomesColumn(PDO $pdo, string $column): bool
    {
        if ($this->homesColumnCache === []) {
            foreach ($this->tableColumns($pdo, 'homes') as $name) {
                $this->homesColumnCache[$name] = true;
            }
        }

        return isset($this->homesColumnCache[strtolower($column)]);
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT CASE WHEN OBJECT_ID(:t, 'U') IS NULL THEN 0 ELSE 1 END");
        $stmt->bindValue(':t', 'dbo.' . $table);
        $stmt->execute();

        return (int) $stmt->fetchColumn() === 1;
    }

    /**
     * kullanici_log_kaydi'na yazar (KtPriceSettingsController ile ayni yapi). Log yazilamazsa islem bozulmaz.
     */
    private function writeLog(PDO $pdo, string $islm, int $homesId, string $message): void
    {
        try {
            $userName = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $this->request->input('kullanici_adi', ''))), 0, 100);
            $natsisaUserId = max(0, (int) $this->request->input('natsisa_kullanici_id', 0));
            $params = [$islm, (string) $homesId, $message, (int) $this->request->input('kullanici_id', 0)];

            $check = $pdo->prepare('SELECT COL_LENGTH(?, ?)');
            $check->execute(['dbo.kullanici_log_kaydi', 'degisiklikler']);
            if ($check->fetchColumn() !== null) {
                $details = json_encode([
                    'kaynak' => $natsisaUserId > 0 || $userName !== '' ? 'natsisa' : 'api',
                    'kullaniciAdi' => $userName,
                    'natsisaKullaniciId' => $natsisaUserId,
                    'islem' => $islm,
                ], JSON_UNESCAPED_UNICODE);
                $params[] = $details === false ? null : $details;
                $insert = $pdo->prepare('INSERT INTO kullanici_log_kaydi (islm, islm_id, aciklama, kullanici_id, degisiklikler) VALUES (?, ?, ?, ?, ?)');
            } else {
                $insert = $pdo->prepare('INSERT INTO kullanici_log_kaydi (islm, islm_id, aciklama, kullanici_id) VALUES (?, ?, ?, ?)');
            }
            $insert->execute($params);
        } catch (\Throwable $e) {
            // Log yazilamazsa villa islemi geri alinmaz.
        }
    }

    /**
     * dbo.kullanici kolonlari: kolon adi (kucuk harf) -> NULL olabilir mi.
     *
     * @return array<string,bool>
     */
    private function kullaniciColumns(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT LOWER(COLUMN_NAME) AS name, CASE WHEN IS_NULLABLE = 'YES' THEN 1 ELSE 0 END AS nullable
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'kullanici'"
        );
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[(string) $row['name']] = (int) $row['nullable'] === 1;
        }

        return $columns;
    }

    private function findOwnerByPhone(PDO $pdo, string $phoneKey): int
    {
        $stmt = $pdo->query("SELECT id, tel FROM dbo.kullanici WHERE yetki = 2 AND tel IS NOT NULL AND LTRIM(RTRIM(tel)) <> '' ORDER BY id DESC");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($this->phoneKey((string) ($row['tel'] ?? '')) === $phoneKey) {
                return (int) $row['id'];
            }
        }

        return 0;
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchOwner(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM dbo.kullanici WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['id' => $id];
        }

        $name = $this->cleanName(trim((string) ($row['ad'] ?? '')) . ' ' . trim((string) ($row['soyad'] ?? '')));

        // ilan formundaki ev_sahipleri listesiyle ayni alanlar (id, title, tokens, phone) + iletisim
        return [
            'id' => (int) $row['id'],
            'title' => $name,
            'tokens' => $name,
            'name' => $name,
            'ad' => trim((string) ($row['ad'] ?? '')),
            'soyad' => trim((string) ($row['soyad'] ?? '')),
            'phone' => trim((string) ($row['tel'] ?? '')),
            'email' => trim((string) ($row['email'] ?? '')),
            'address' => trim((string) ($row['adres'] ?? '')),
            'aktif' => (int) ($row['aktif'] ?? 0),
        ];
    }

    /** Telefon karsilastirma anahtari: son 10 hane (0, 90, +90, 0090 farki yok sayilir). */
    private function phoneKey(string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);

        return strlen($digits) >= 10 ? substr($digits, -10) : '';
    }

    /**
     * @return array{ad:string,soyad:string}
     */
    private function splitName(string $fullName): array
    {
        $parts = array_values(array_filter(preg_split('/\s+/', $this->cleanName($fullName)) ?: [], static function (string $p): bool {
            return $p !== '';
        }));
        if (count($parts) <= 1) {
            return ['ad' => $parts[0] ?? '', 'soyad' => ''];
        }
        $soyad = (string) array_pop($parts);

        return ['ad' => implode(' ', $parts), 'soyad' => $soyad];
    }

    private function cleanName(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    private function hasLetter(string $value): bool
    {
        return preg_match('/\p{L}/u', $value) === 1;
    }

    /**
     * @param string[] $keys
     * @return mixed
     */
    private function inputAny(array $keys, $default = '')
    {
        foreach ($keys as $key) {
            $value = $this->request->query($key, null);
            if ($value !== null) {
                return $value;
            }
            $value = $this->request->input($key, null);
            if ($value !== null) {
                return $value;
            }
        }

        return $default;
    }
}
