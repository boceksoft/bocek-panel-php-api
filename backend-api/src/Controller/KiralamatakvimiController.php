<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use PDO;
use App\Support\Uploads;

/*
 * Villa <-> Kiralama Takvimi bağlantısı ve villa ev sahipleri. İşi panel yapar: bu controller
 * panelin API'sine ($domain/api/{Sınıf}/{Metot}) istek atar (Estate::Create / Delete / Leave,
 * Owner::*). Kendisi sadece villanın bilgisini ve CalendarHomes bağını okur.
 *
 * Ayarlar (config/app.local.php):
 *   kiralamatakvimi_panel_api_url     Panel API kökü. Boşsa config.php'deki URL sabiti (örn. https://www.site.com/api).
 *   kiralamatakvimi_panel_api_headers Panel API'sine eklenecek header'lar (örn. ['X-Api-Key: ...']).
 */
final class KiralamatakvimiController extends Controller
{
    /**
     * Villanın bilgisini ve mevcut Kiralama Takvimi bağlantısını döner. Bağlıysa connection.estateType
     * Kiralama Takvimi API'sinden (estate/item) okunur: "Standard" | "Special"; okunamazsa null.
     *
     * @Get
     * @query homesId int required Villa id'si
     */
    public function show(): void
    {
        $homesId = $this->homesId((int) $this->request->query('homesId', 0));
        $pdo = $this->db->pdo();
        $villa = $this->villaInfo($this->home($pdo, $homesId));

        $connection = $this->connection($pdo, $homesId);
        if ($connection !== null) {
            $connection['estateType'] = $this->estateType($pdo, $connection['estateId']);
        }

        $this->response->success(['villa' => $villa, 'connection' => $connection]);
    }

    /**
     * Takvimin Kiralama Takvimi'ndeki türü. Tür yalnızca gösterim içindir; ayar eksikse ya da API
     * yanıt vermezse villa bilgisi yine döner, tür null olur.
     *
     * @return string|null
     */
    private function estateType(PDO $pdo, int $estateId)
    {
        try {
            $estate = $this->ktApi($this->ktCredentials($pdo), 'GET', 'estate/item', ['estateId' => $estateId]);
        } catch (HttpException $e) {
            return null;
        }

        // KT enum'ları metin ("Special") döndürür; sayı gelirse 1 Özel'dir.
        $type = $estate['EstateType'] ?? $estate['estateType'] ?? null;
        if ($type === null) {
            return null;
        }

        return ($type === 1 || $type === '1' || strcasecmp((string) $type, 'Special') === 0) ? 'Special' : 'Standard';
    }

    /**
     * Villayı Kiralama Takvimi'ne bağlar: panelin Estate::Create'ine istek atar. calendarCode boşsa
     * yeni takvim oluşturulur (tur=1), doluysa o takvime katılınır (tur=2). ownerId verilirse yeni
     * takvim panelin Owner::SetEstateOwner'ıyla ev sahibine atanır.
     *
     * @Post("connect")
     * @body homesId int required Villa id'si
     * @body calendarCode string Boşsa yeni takvim oluşturulur
     * @body isEditableByOwner bool Yeni takvim: ev sahibi düzenleyebilir mi
     * @body transferToKiralamaTakvimi bool Yeni takvim: sitedeki rezervasyonları aktar (çakışan tarih kontrolü yapılır)
     * @body priceTransferToKiralamaTakvimi bool Yeni takvim: sitedeki fiyatları aktar
     * @body ownerId int Yeni takvim: atanacak Kiralama Takvimi ev sahibi (isteğe bağlı)
     * @body priceTransferFromKiralamaTakvimi bool Katılırken: Kiralama Takvimi fiyatlarını çek
     * @body manuelKapatmalariSil bool Katılırken: manuel kapatmaları sil
     * @body isSpecial bool Yeni takvim: özel ilan (ev sahibi atanmaz; oluşunca türü Kiralama Takvimi API'sinden Özel yapılır)
     */
    public function connect(): void
    {
        $homesId = $this->homesId((int) $this->request->input('homesId', 0));
        $calendarCode = trim((string) $this->request->input('calendarCode', ''));
        $isJoin = $calendarCode !== '';
        $isSpecial = $this->flag('isSpecial');
        // Özel ilanın ev sahibi olmaz
        $ownerId = $isSpecial ? 0 : (int) $this->request->input('ownerId', 0);

        if ($isSpecial && $isJoin) {
            throw new HttpException('Özel ilana takvim koduyla katılınamaz.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();
        $this->home($pdo, $homesId);
        if ($this->connection($pdo, $homesId) !== null) {
            throw new HttpException('Villa zaten Kiralama Takvimi\'ne bağlı.', 'ALREADY_CONNECTED', 409);
        }

        // Ayar eksikse takvim Standart oluşmasın diye anahtar panelden önce okunur.
        $ktCredentials = $isSpecial ? $this->ktCredentials($pdo) : null;

        // Estate::Create gövdeyi JSON okur ve bayrakları "true" / "1" metni olarak karşılaştırır.
        $created = $this->panel('Estate/Create', [
            'homesId' => $homesId,
            'tur' => $isJoin ? '2' : '1',
            'calendarCode' => $calendarCode,
            'isEditableByOwner' => $this->flag('isEditableByOwner') ? 'true' : 'false',
            'TransferToKiralamaTakvimi' => $this->flag('transferToKiralamaTakvimi') ? 'true' : 'false',
            'PriceTransferToKiralamaTakvimi' => $this->flag('priceTransferToKiralamaTakvimi') ? 'true' : 'false',
            'PriceTransferFromKiralamaTakvimi' => $this->flag('priceTransferFromKiralamaTakvimi') ? '1' : '0',
            'ManuelKapatmalariSil' => $this->flag('manuelKapatmalariSil') ? '1' : '0',
        ], true);
        if (empty($created['success']) || !empty($created['error'])) {
            throw new HttpException($this->panelError($created, 'Takvim bağlanamadı.'), 'PANEL_ERROR', 400);
        }

        $connection = $this->connection($pdo, $homesId);
        if ($connection === null) {
            throw new HttpException('Panel başarılı döndü ancak villa takvime bağlanmadı.', 'PANEL_ERROR', 500);
        }

        $ownerError = null;
        if (!$isJoin && $ownerId > 0) {
            $owner = $this->panel('Owner/SetEstateOwner', ['homesId' => $homesId, 'ownerId' => $ownerId]);
            if (empty($owner['success'])) {
                $ownerError = $this->panelError($owner, 'Ev sahibi atanamadı.');
            }
        }

        // Panel takvimi Standart oluşturur; tür ayrı istekle Özel yapılır. Başarısız olsa da takvim bağlanmıştır.
        $specialError = null;
        if ($ktCredentials !== null) {
            try {
                $this->ktApi($ktCredentials, 'PUT', 'estate/updatespecialtype', [
                    'estateId' => $connection['estateId'],
                    'estateType' => 1,
                ]);
            } catch (HttpException $e) {
                $specialError = $e->getMessage();
            }
        }

        $this->response->success([
            'connection' => $connection,
            'ownerError' => $ownerError,
            'specialError' => $specialError,
            // Tür gerçekten Özel yapıldıysa true; çağıran taraf eski sürüm bir sitenin isSpecial'ı yok saydığını buradan anlar.
            'isSpecial' => $ktCredentials !== null && $specialError === null,
        ]);
    }

    /**
     * Villanın Kiralama Takvimi bağını kaldırır: takvim oluşturulduysa panelin Estate::Delete'ine,
     * katılındıysa Estate::Leave'ine istek atar. Takvim sitede başka bir villaya da bağlıysa panele
     * gidilmez (Delete / Leave takvimi ve diğer villanın bağını da kaldırır); sadece bu villanın bağı silinir.
     *
     * @Post("disconnect")
     * @body homesId int required Villa id'si
     */
    public function disconnect(): void
    {
        $homesId = $this->homesId((int) $this->request->input('homesId', 0));
        $pdo = $this->db->pdo();

        $connection = $this->connection($pdo, $homesId);
        if ($connection === null) {
            throw new HttpException('Villa Kiralama Takvimi\'ne bağlı değil.', 'NOT_CONNECTED', 404);
        }

        if ($connection['sharedHomesIds'] !== []) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE dolu SET ReservationId = NULL WHERE emlak = :emlak AND ReservationId > 0')
                    ->execute(['emlak' => $homesId]);
                $pdo->prepare('DELETE FROM KiralamaTakvimi.CalendarHomes WHERE homesId = :homesId')
                    ->execute(['homesId' => $homesId]);
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        } else {
            $removed = $this->panel($connection['type'] === 1 ? 'Estate/Leave' : 'Estate/Delete', ['estateId' => $connection['estateId']]);
            if (empty($removed['success'])) {
                throw new HttpException($this->panelError($removed, 'Takvim bağı kaldırılamadı.'), 'PANEL_ERROR', 400);
            }
        }

        $this->response->success(['connection' => $connection]);
    }

    /**
     * Firmanın Kiralama Takvimi'ndeki tüm ev sahipleri (Owner::GetEstablishmentOwners).
     *
     * @Get("owners")
     */
    public function owners(): void
    {
        $response = $this->panel('Owner/GetEstablishmentOwners', []);
        $owners = $response['data'] ?? [];
        if (is_array($owners) && isset($owners['Data']) && is_array($owners['Data'])) {
            $owners = $owners['Data'];
        }
        if (!is_array($owners) || isset($owners['Messages'])) {
            throw new HttpException($this->panelError(is_array($owners) ? ['error' => $owners] : [], 'Ev sahipleri alınamadı.'), 'PANEL_ERROR', 400);
        }

        $this->response->success(['owners' => array_values($owners)]);
    }

    /**
     * Villanın takvimine bağlı ev sahipleri (Owner::EstateOwners).
     *
     * @Get("owners/villa")
     * @query homesId int required Villa id'si
     */
    public function villaOwners(): void
    {
        $response = $this->panel('Owner/EstateOwners', ['homesId' => $this->homesId((int) $this->request->query('homesId', 0))]);
        if (empty($response['success'])) {
            throw new HttpException($this->panelError($response, 'Ev sahipleri alınamadı.'), 'PANEL_ERROR', 400);
        }

        $this->response->success(['owners' => $response['owners'] ?? []]);
    }

    /**
     * Kiralama Takvimi'nde telefonla ev sahibi arar (Owner::FindByPhone). Kayıt yoksa found=false.
     *
     * @Get("owners/find")
     * @query phone string required Telefon
     */
    public function findOwner(): void
    {
        $response = $this->panel('Owner/FindByPhone', ['phone' => (string) $this->request->query('phone', '')]);
        if (!empty($response['error'])) {
            throw new HttpException($this->panelError($response, 'Ev sahibi aranamadı.'), 'PANEL_ERROR', 400);
        }

        $this->response->success(['found' => !empty($response['found']), 'owner' => $response['owner'] ?? null]);
    }

    /**
     * Telefonla ev sahibinin Kiralama Takvimi'ndeki villaları (Owner::Estates). Panel Kiralama Takvimi
     * yanıtını olduğu gibi döner; liste doğrudan dizi ya da {"Data": [...]} olabilir.
     *
     * @Get("owners/estates")
     * @query tel string required Ev sahibinin telefonu
     */
    public function ownerEstates(): void
    {
        $tel = trim((string) $this->request->query('tel', ''));
        if ($tel === '') {
            throw new HttpException('Telefon numarası gerekli.', 'VALIDATION', 422);
        }

        $response = $this->panel('Owner/Estates', ['tel' => $tel]);
        if (isset($response['Messages']) || isset($response['messages']) || isset($response['error'])) {
            $error = $response['error'] ?? $response;
            throw new HttpException($this->panelError(['error' => $error], 'Ev sahibinin villaları alınamadı.'), 'PANEL_ERROR', 400);
        }
        $estates = isset($response['Data']) && is_array($response['Data']) ? $response['Data'] : $response;

        $this->response->success(['estates' => array_values($estates)]);
    }

    /**
     * Villanın sitede kayıtlı ev sahibi; yeni ev sahibi formunu doldurmak için (Owner::HomeOwnerLocal).
     *
     * @Get("owners/local")
     * @query homesId int required Villa id'si
     */
    public function localOwner(): void
    {
        $response = $this->panel('Owner/HomeOwnerLocal', ['homesId' => $this->homesId((int) $this->request->query('homesId', 0))]);

        $this->response->success([
            'found' => !empty($response['found']),
            'firstName' => $response['firstName'] ?? null,
            'lastName' => $response['lastName'] ?? null,
            'email' => $response['email'] ?? null,
            'phone' => $response['phone'] ?? null,
        ]);
    }

    /**
     * Kiralama Takvimi'nde yeni ev sahibi oluşturur ve villaya atar (Owner::CreateAndAssign).
     * Ev sahibi oluşup atanamazsa assigned=false ve owner döner.
     *
     * @Post("owners/create")
     * @body homesId int required Villa id'si
     * @body firstName string required
     * @body lastName string required
     * @body phone string required
     * @body email string
     * @body isCalendarEditable bool
     */
    public function createOwner(): void
    {
        $response = $this->panel('Owner/CreateAndAssign', [
            'homesId' => $this->homesId((int) $this->request->input('homesId', 0)),
            'firstName' => (string) $this->request->input('firstName', ''),
            'lastName' => (string) $this->request->input('lastName', ''),
            'email' => (string) $this->request->input('email', ''),
            'phone' => (string) $this->request->input('phone', ''),
            'isCalendarEditable' => $this->flag('isCalendarEditable') ? 'true' : 'false',
        ]);
        if (empty($response['success']) && empty($response['created'])) {
            throw new HttpException($this->panelError($response, 'Ev sahibi oluşturulamadı.'), 'PANEL_ERROR', 400);
        }

        $this->response->success([
            'owner' => $response['owner'] ?? null,
            'assigned' => !empty($response['success']),
            'message' => empty($response['success']) ? $this->panelError($response, 'Ev sahibi villaya atanamadı.') : null,
        ]);
    }

    /**
     * Var olan ev sahibini villanın takvimine atar (Owner::SetEstateOwner).
     *
     * @Post("owners/assign")
     * @body homesId int required Villa id'si
     * @body ownerId int required Kiralama Takvimi ev sahibi id'si
     */
    public function assignOwner(): void
    {
        $this->ownerRequest('Owner/SetEstateOwner', 'Ev sahibi atanamadı.');
    }

    /**
     * Ev sahibini villanın takviminden kaldırır (Owner::RemoveEstateOwner).
     *
     * @Post("owners/remove")
     * @body homesId int required Villa id'si
     * @body ownerId int required Kiralama Takvimi ev sahibi id'si
     */
    public function removeOwner(): void
    {
        $this->ownerRequest('Owner/RemoveEstateOwner', 'Ev sahibi kaldırılamadı.');
    }

    private function ownerRequest(string $path, string $fallback): void
    {
        $ownerId = (int) $this->request->input('ownerId', 0);
        if ($ownerId <= 0) {
            throw new HttpException('Geçerli bir ev sahibi seçiniz.', 'VALIDATION', 422);
        }

        $response = $this->panel($path, ['homesId' => $this->homesId((int) $this->request->input('homesId', 0)), 'ownerId' => $ownerId]);
        if (empty($response['success'])) {
            throw new HttpException($this->panelError($response, $fallback), 'PANEL_ERROR', 400);
        }

        $this->response->success([]);
    }

    /**
     * Özel ilanın WhatsApp durumu: bağlı grup (yoksa null) ve grup listesini çekmek için seçilebilecek
     * numaralar (onaylı ve API key'i tanımlı olanlar; isMaster varsayılan seçimdir).
     *
     * @Get("whatsapp")
     * @query homesId int required Villa id'si
     */
    public function whatsapp(): void
    {
        $pdo = $this->db->pdo();
        $estateId = $this->specialEstateId($pdo, (int) $this->request->query('homesId', 0));
        $credentials = $this->ktCredentials($pdo);

        $group = $this->ktApi($credentials, 'GET', 'estate/whatsapp', ['estateId' => $estateId]);
        $groupId = (int) ($group['WhatsappGroupId'] ?? $group['whatsappGroupId'] ?? 0);

        $numbers = [];
        foreach ($this->ktApi($credentials, 'GET', 'establishment/numbers') as $number) {
            if (!is_array($number)) {
                continue;
            }
            $status = $number['Status'] ?? $number['status'] ?? null;
            $approved = $status === 1 || $status === '1' || strcasecmp((string) $status, 'Approved') === 0;
            if (!$approved || empty($number['HasApiKey'] ?? $number['hasApiKey'] ?? false)) {
                continue;
            }
            $numbers[] = [
                'whatsappContactId' => (int) ($number['WhatsappContactId'] ?? $number['whatsappContactId'] ?? 0),
                'phoneNumber' => (string) ($number['PhoneNumber'] ?? $number['phoneNumber'] ?? ''),
                'isMaster' => !empty($number['IsMasterWhatsappContact'] ?? $number['isMasterWhatsappContact'] ?? false),
            ];
        }

        $this->response->success([
            'estateId' => $estateId,
            'group' => $groupId > 0 ? [
                'whatsappGroupId' => $groupId,
                'name' => (string) ($group['Name'] ?? $group['name'] ?? ''),
                'createdOn' => $group['CreatedOn'] ?? $group['createdOn'] ?? null,
            ] : null,
            'numbers' => $numbers,
        ]);
    }

    /**
     * Seçilen numaranın API key'i ile WhatsApp grupları (KT whatsapp/specialgroups). search verilirse
     * gruplar doğrudan WhatsApp'ta isimle aranır.
     *
     * @Get("whatsapp/groups")
     * @query homesId int required Villa id'si
     * @query whatsappContactId int required Grupları çekilecek numara
     * @query search string Grup adı (2+ harf)
     */
    public function whatsappGroups(): void
    {
        $pdo = $this->db->pdo();
        $estateId = $this->specialEstateId($pdo, (int) $this->request->query('homesId', 0));
        $contactId = (int) $this->request->query('whatsappContactId', 0);
        if ($contactId <= 0) {
            throw new HttpException('Whatsapp numarası seçiniz.', 'VALIDATION', 422);
        }

        $query = ['estateId' => $estateId, 'whatsappContactId' => $contactId];
        $search = trim((string) $this->request->query('search', ''));
        if ($search !== '') {
            $query['search'] = $search;
        }

        $groups = [];
        foreach ($this->ktApi($this->ktCredentials($pdo), 'GET', 'whatsapp/specialgroups', $query, null, 60) as $group) {
            if (!is_array($group)) {
                continue;
            }
            $groups[] = [
                'id' => (string) ($group['id'] ?? ''),
                'name' => $group['name'] ?? null,
                'phoneNumber' => $group['phoneNumber'] ?? null,
                'whatsappContactId' => (int) ($group['whatsappContactId'] ?? 0),
            ];
        }

        $this->response->success(['groups' => $groups]);
    }

    /**
     * Özel ilanı seçilen numaranın WhatsApp grubuna bağlar (KT whatsapp/connectestate); doğrulama kodu gerekmez.
     *
     * @Post("whatsapp/connect")
     * @body homesId int required Villa id'si
     * @body whatsappContactId int required Grubun çekildiği numara
     * @body groupId string required WhatsApp grup id'si
     * @body name string Grup adı
     */
    public function whatsappConnect(): void
    {
        $pdo = $this->db->pdo();
        $estateId = $this->specialEstateId($pdo, (int) $this->request->input('homesId', 0));
        $contactId = (int) $this->request->input('whatsappContactId', 0);
        $groupId = trim((string) $this->request->input('groupId', ''));
        if ($contactId <= 0 || $groupId === '') {
            throw new HttpException('Numara ve grup seçiniz.', 'VALIDATION', 422);
        }

        $result = $this->ktApi($this->ktCredentials($pdo), 'POST', 'whatsapp/connectestate', [], [
            'estateId' => $estateId,
            'whatsappContactId' => $contactId,
            'groupId' => $groupId,
            'name' => (string) $this->request->input('name', ''),
        ], 60);

        $this->response->success([
            'whatsappGroupId' => (int) ($result['whatsappGroupId'] ?? $result['WhatsappGroupId'] ?? 0),
        ]);
    }

    /**
     * Özel ilanın WhatsApp grup bağlantısını kaldırır (KT whatsapp/disconnectestate); doğrulama kodu gerekmez.
     *
     * @Post("whatsapp/disconnect")
     * @body homesId int required Villa id'si
     * @body whatsappGroupId int required Bağlı grubun KT id'si
     */
    public function whatsappDisconnect(): void
    {
        $pdo = $this->db->pdo();
        $estateId = $this->specialEstateId($pdo, (int) $this->request->input('homesId', 0));
        $whatsappGroupId = (int) $this->request->input('whatsappGroupId', 0);
        if ($whatsappGroupId <= 0) {
            throw new HttpException('Geçerli bir grup gereklidir.', 'VALIDATION', 422);
        }

        $this->ktApi($this->ktCredentials($pdo), 'DELETE', 'whatsapp/disconnectestate', [
            'whatsappGroupId' => $whatsappGroupId,
            'estateId' => $estateId,
        ]);

        $this->response->success([]);
    }

    /**
     * Villanın bağlı olduğu takvim; yalnızca Özel takvimler için WhatsApp grubu buradan yönetilir.
     */
    private function specialEstateId(PDO $pdo, int $homesId): int
    {
        $connection = $this->connection($pdo, $this->homesId($homesId));
        if ($connection === null) {
            throw new HttpException('Villa Kiralama Takvimi\'ne bağlı değil.', 'NOT_CONNECTED', 404);
        }
        if ($this->estateType($pdo, $connection['estateId']) !== 'Special') {
            throw new HttpException('WhatsApp grubu buradan yalnızca özel ilanlarda bağlanabilir.', 'VALIDATION', 422);
        }

        return $connection['estateId'];
    }

    /**
     * Panel API'sine POST isteği atar. Panel metotları alanları post() ile (form) okur; Estate::Create ise JSON gövde okur.
     * Panel hata durumlarında da JSON döner (400/502); HTTP koduna değil gövdeye bakılır.
     */
    private function panel(string $path, array $data, bool $json = false): array
    {
        $headers = array_merge(
            ['Accept: application/json'],
            $json ? ['Content-Type: application/json'] : ['Content-Type: application/x-www-form-urlencoded'],
            array_values((array) ($this->app['kiralamatakvimi_panel_api_headers'] ?? []))
        );

        $url = $this->panelUrl() . '/' . $path;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json ? (string) json_encode($data) : http_build_query($data),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            // Estate::Create katılırken fiyat ve rezervasyon aktarımı yaptığı için uzun sürebilir.
            CURLOPT_TIMEOUT => 150,
        ]);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($raw === false || $status === 0) {
            throw new HttpException(
                'Panel API\'sine ulaşılamadı.' . $this->debugDetail($url, $error),
                'PANEL_UNREACHABLE',
                502
            );
        }
        $body = json_decode((string) $raw, true);
        if (!is_array($body)) {
            throw new HttpException(
                'Panel API\'si geçerli bir yanıt döndürmedi (HTTP ' . $status . '). Adres veya panel oturumu ayarını kontrol edin.'
                    . $this->debugDetail($url, (string) $raw),
                'PANEL_INVALID_RESPONSE',
                502
            );
        }

        return $body;
    }

    // Debug açıkken (config/app.php 'debug') panel hatasının teşhisi için adres ve yanıtın başı; canlıda boş.
    private function debugDetail(string $url, string $response): string
    {
        if (empty($this->app['debug'])) {
            return '';
        }
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($response)));

        return ' | ' . $url . ' | ' . (function_exists('mb_substr') ? mb_substr($text, 0, 500) : substr($text, 0, 500));
    }

    private function panelUrl(): string
    {
        $url = trim((string) ($this->app['kiralamatakvimi_panel_api_url'] ?? ''));
        if ($url === '') {
            // Panel API'si sitenin config.php'sindeki URL sabitidir (örn. https://www.site.com/api).
            $url = defined('URL') ? trim((string) constant('URL')) : '';
            if ($url === '') {
                throw new HttpException('Panel API adresi tanımlı değil (config URL sabiti yok).', 'PANEL_UNREACHABLE', 500);
            }
            if (preg_match('#^https?://#i', $url) !== 1) {
                $url = 'https://' . $url;
            }
        }

        return rtrim($url, '/');
    }

    /**
     * Panel yanıtındaki hata: metin, Kiralama Takvimi yanıtı ({"Messages": [...]}) ya da error alanı.
     */
    private function panelError(array $response, string $fallback): string
    {
        $error = $response['error'] ?? null;
        if (is_string($error) && $error !== '') {
            return $error;
        }
        if (is_array($error)) {
            foreach (['Messages', 'messages'] as $key) {
                if (!empty($error[$key]) && is_array($error[$key])) {
                    return implode(' ', array_map('strval', $error[$key]));
                }
            }
            foreach (['Message', 'message'] as $key) {
                if (!empty($error[$key]) && is_string($error[$key])) {
                    return $error[$key];
                }
            }
        }

        return $fallback;
    }

    /**
     * Kiralama Takvimi API'si (backend.kiralamatakvimi.com) kimliği; HomesManagementCalendarController::ktApi ile aynı kural:
     * önce app.local.php, sonra genel tablosu (apiKey / EstablishmentId), en son panelin api/config.php'si.
     * Panel takvimi bu kurumla oluşturduğu için türü de aynı kurum değiştirebilir.
     *
     * @return array{apiKey: string, establishmentId: string}
     */
    private function ktCredentials(PDO $pdo): array
    {
        $genel = $pdo->query('SELECT TOP 1 * FROM genel')->fetch(PDO::FETCH_ASSOC) ?: [];
        $genel = array_change_key_case($genel, CASE_LOWER);
        $panel = (array) ($this->app['_panel_config'] ?? []);

        $apiKey = trim((string) ($this->app['kiralamatakvimi_api_key'] ?? ''));
        if ($apiKey === '') {
            $apiKey = trim((string) ($genel['apikey'] ?? ($panel['apiKey'] ?? '')));
        }
        $establishmentId = trim((string) ($this->app['kiralamatakvimi_establishment_id'] ?? ''));
        if ($establishmentId === '') {
            $establishmentId = trim((string) ($genel['establishmentid'] ?? ($panel['EstablishmentId'] ?? '')));
        }
        if ($apiKey === '' || $establishmentId === '') {
            throw new HttpException(
                'Kiralama Takvimi API ayarları eksik: ' . ($apiKey === '' ? 'genel.apiKey boş' : 'EstablishmentId bulunamadı')
                . ' (config/app.local.php: kiralamatakvimi_api_key / kiralamatakvimi_establishment_id).',
                'PANEL_ERROR',
                400
            );
        }

        return ['apiKey' => $apiKey, 'establishmentId' => $establishmentId];
    }

    /**
     * Kiralama Takvimi API'sine istek atar ve JSON yanıtı dizi olarak döner. Hata (200 dışı) KT'nin
     * mesajıyla HttpException olarak fırlatılır.
     *
     * @param array{apiKey: string, establishmentId: string} $credentials
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $body JSON gövde (GET dışı; boşsa {})
     * @param int|null $timeout Saniye; boşsa GET 10, diğerleri 30
     * @return array<string,mixed>
     */
    private function ktApi(array $credentials, string $method, string $path, array $query = [], $body = null, $timeout = null): array
    {
        $base = rtrim(trim((string) ($this->app['kiralamatakvimi_api_url'] ?? '')) ?: 'https://backend.kiralamatakvimi.com/api', '/');
        $url = $base . '/' . $path . ($query ? '?' . http_build_query($query) : '');

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 10,
            // Okuma sayfa açılışında yapılır; uzun beklenmez.
            CURLOPT_TIMEOUT => $timeout ?? ($method === 'GET' ? 10 : 30),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-API-Key: ' . $credentials['apiKey'],
                'EstablishmentId: ' . $credentials['establishmentId'],
            ],
        ];
        if ($method !== 'GET') {
            $options[CURLOPT_POSTFIELDS] = $body ? (string) json_encode($body) : '{}';
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($raw === false || $status === 0) {
            throw new HttpException('Kiralama Takvimi API\'sine ulaşılamadı.', 'PANEL_UNREACHABLE', 502);
        }
        if ($status < 200 || $status >= 300) {
            $decoded = json_decode((string) $raw, true);
            $message = is_array($decoded) ? $this->panelError(['error' => $decoded], '') : '';
            if ($message === '') {
                $message = $status === 401 ? 'Kiralama Takvimi API anahtarı geçersiz.' : 'Kiralama Takvimi isteği başarısız (HTTP ' . $status . ').';
            }
            throw new HttpException($message, 'PANEL_ERROR', 400);
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function flag(string $name): bool
    {
        return filter_var($this->request->input($name, false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array|null
     */
    private function connection(PDO $pdo, int $homesId)
    {
        $stmt = $pdo->prepare('SELECT EstateId, CalendarCode, Type FROM KiralamaTakvimi.CalendarHomes WHERE homesId = :homesId');
        $stmt->execute(['homesId' => $homesId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if ($row === false) {
            return null;
        }

        return [
            'estateId' => (int) $row['EstateId'],
            'calendarCode' => (string) $row['CalendarCode'],
            // Panel yeni oluşturulan takvimde Type yazmaz (NULL); katılınca 1 yazar.
            'type' => (int) ($row['Type'] ?? 0) === 1 ? 1 : 0,
            'sharedHomesIds' => $this->otherHomesOnEstate($pdo, (int) $row['EstateId'], $homesId),
        ];
    }

    /**
     * Aynı takvime (EstateId) bağlı diğer villalar. Bir takvim sitede tek villaya ait olmalı; doluysa veri hatalıdır.
     *
     * @return int[]
     */
    private function otherHomesOnEstate(PDO $pdo, int $estateId, int $homesId): array
    {
        $stmt = $pdo->prepare('SELECT homesId FROM KiralamaTakvimi.CalendarHomes WHERE EstateId = :EstateId AND homesId <> :homesId');
        $stmt->execute(['EstateId' => $estateId, 'homesId' => $homesId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function home(PDO $pdo, int $homesId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM homes WHERE id = :id');
        $stmt->execute(['id' => $homesId]);
        $home = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if ($home === false) {
            throw new HttpException('Villa bulunamadı.', 'NOT_FOUND', 404);
        }

        return $home;
    }

    private function homesId(int $homesId): int
    {
        if ($homesId <= 0) {
            throw new HttpException('Geçerli bir homesId gereklidir.', 'VALIDATION', 422);
        }

        return $homesId;
    } 

    // Estate::Create'te Kiralama Takvimi'ne gönderilen villa bilgisi (sadece gösterim için).
    private function villaInfo(array $home): array
    {
        $domain = defined('Domain') ? rtrim((string) constant('Domain'), '/') : '';
        $whatsappColumn = defined('WhatsappColumnName') ? (string) constant('WhatsappColumnName') : 'whatsapp_grup';

        return [
            'homesId' => (int) $home['id'],
            'estateTitle' => (string) ($home['baslik'] ?? ''),
            'estateDescription' => (string) ($home['kisa_icerik'] ?? ''),
            'estateUrl' => $domain . '/' . ($home['url'] ?? ''),
            // Ilk kapak, yeni CDN yapisinda (/uploads/property/small/{villaId}/)
            'estateImageUrl' => Uploads::homeImageBase($this->imageBase(), (int) ($home['id'] ?? 0), $this->app)
                . (explode(',', (string) ($home['resim'] ?? ''))[0] ?? ''),
            'guestPerson' => (int) ($home['kisi'] ?? 0),
            'bedroomCount' => (int) ($home['yatak_odasi'] ?? 0),
            'bedCount' => (int) ($home['yatak_sayisi'] ?? 0),
            'bathCount' => (int) ($home['banyo'] ?? 0),
            'whatsappGroup' => (string) ($home[$whatsappColumn] ?? ''),
        ];
    }

    // CategoriesController::imageBase ile aynı: Cdn yoksa Domain, görseller /uploads/small/ üzerinden.
    private function imageBase(): string
    {
        $domain = defined('Cdn') ? trim((string) constant('Cdn')) : '';
        if ($domain === '') {
            $domain = defined('Domain') ? trim((string) constant('Domain')) : '';
        }
        $domain = rtrim($domain, '/');
        if ($domain !== '' && preg_match('#^https?://#i', $domain) !== 1) {
            $domain = 'https://' . $domain;
        }

        return $domain !== '' ? $domain . '/uploads/small/' : '/uploads/small/';
    }
}
