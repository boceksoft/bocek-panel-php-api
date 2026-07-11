# Yazım Standardı (backend-api)

Bu projede tek bir giriş noktası (`public/index.php`) üzerinden çalışan, okunabilirliği
öncelikleyen basit bir API iskeleti kullanılır. Aşağıdaki kurallar zorunludur.

## Genel

- **Hedef sürüm: PHP 7.3.** Bu yüzden şunlar KULLANILMAZ: typed property (`private int $x`),
  constructor property promotion, arrow function (`fn`), `mixed` tipi, native attribute (`#[...]`).
  Bunların yerine: docblock (`/** @var int */`), klasik constructor ataması, `function () {}`
  closure, ve yönlendirmede docblock annotation (`@Get`, `@Post`).
- **PSR-12** taban alınır.
- Her PHP dosyasının ilk satırı: `declare(strict_types=1);`
- 4 boşluk girinti, satır sonu **LF**, dosya sonunda kapanış `?>` **kullanılmaz**.
- Dosya/sınıf başına kısa bir Türkçe amaç bloğu yazılır. Yorumlar Türkçe olabilir.
- Sırlar (DB kullanıcı/şifre) repoya **girmez**; repo dışındaki `../api/config.php` dosyasından gelir.

## İsimlendirme

| Öğe              | Kural          | Örnek                 |
|------------------|----------------|-----------------------|
| Sınıf            | `PascalCase`   | `OffersController`    |
| Metot / değişken | `camelCase`    | `bearerToken()`       |
| Sabit            | `UPPER_SNAKE`  | `DEFAULT_PER_PAGE`    |
| Namespace        | `App\...`      | `App\Controller`      |

- Controller dosyaları `{Resource}Controller.php` adıyla `src/Controller/` altında durur.

## Klasör yapısı

```
index.php       -> tek giriş noktası (front controller)
web.config      -> IIS: rewrite -> index.php, src/ ve config/ erişime kapalı
docs.html       -> Swagger UI
openapi.php     -> otomatik üretilen OpenAPI JSON
config/         -> uygulama ayarları (app.php)
src/Core/       -> Router, Request, Response, Database, Autoloader, HttpException
src/Middleware/ -> IpWhitelist, AuthToken
src/Controller/ -> endpoint sınıfları (her biri bir kaynak)
src/Support/    -> bootstrap.php, OpenApiGenerator.php
```

> Alt yolda yayın: uygulama `https://.../backend-api` altında çalışıyorsa
> `config/app.php` içindeki `base_path` `'/backend-api'` olmalı. Kök dizinde `''` bırak.
> Giriş dosyaları (`index.php`, `web.config`, `docs.html`, `openapi.php`) doğrudan bu
> klasörde durur; ayrı bir `public/` yoktur (alt klasör dağıtımı için).

## Yönlendirme (docblock annotation — .NET Core tarzı, PHP 7.3 uyumlu)

Merkezi rota listesi **yoktur**. Controller, URL'den konvansiyonla bulunur; aksiyon ise
metodun **docblock annotation**'ıyla eşlenir (metot adı serbesttir). PHP 7.3'te native
attribute olmadığından verb, `getDocComment()` ile docblock'tan okunur.

- Controller: `/{resource}` → `App\Controller\{Resource}Controller` (base_path zaten soyulur)
- Aksiyon: HTTP verb + opsiyonel alt yol, annotation'dan gelir:

```php
final class OffersController extends Controller
{
    /** @Get */             // GET  /backend-api/offers
    public function index() {}

    /** @Get("detail") */   // GET  /backend-api/offers/detail
    public function detail() {}

    /** @Post */            // POST /backend-api/offers
    public function create() {}
}
```

> `index` gibi metot adları URL'e YAZILMAZ; `@Get` (boş yol) zaten `GET /offers`'ın karşılığıdır.

Desteklenen annotation'lar: `@Get`, `@Post`, `@Put`, `@Delete` — hepsi opsiyonel alt yol alır:
`@Get`, `@Get("detail")`, `@Post('iptal')`.
Yeni endpoint eklemek = `src/Controller/` altına yeni dosya + metoda annotation. Başka kayıt gerekmez.

> Not: Annotation gerçek docblock (`/** ... */`) içinde olmalı; tek satır `//` yorumunda çalışmaz.

## Dokümantasyon (Swagger / OpenAPI)

Doküman **otomatik**tir: `src/Support/OpenApiGenerator.php`, controller docblock'larını
reflection ile tarayıp OpenAPI 3.0 JSON üretir. Ayrı bir spec dosyası tutulmaz.

- `openapi.php` → üretilen JSON (auth gerektirmez)
- `docs.html` → Swagger UI (CDN'den), şemayı `openapi.php`'den okur

Adres: `https://web.villakilavuzu.com/backend-api/docs.html`. Sağ üstteki **Authorize** ile
Bearer token girip **Try it out** ile canlı istek atılır.

Doc**b**lock'a şu etiketler eklenirse dokümana yansır (routing'i etkilemez):

```php
/**
 * Teklifleri sayfalı listeler.        // özet (etiketsiz ilk satır)
 *
 * @Get
 * @query site int Site kimliği         // @query <ad> <tip> [required] [açıklama]
 * @body  ids array required Villa id'leri  // @body  <ad> <tip> [required] [açıklama]
 */
```

Tipler: `int`, `number`, `bool`, `array`, `string` (varsayılan). `required` yazılırsa zorunlu olur.

## İstek (Request)

- Query parametresi: `$request->query('site', 1)`
- JSON gövde: `$request->json()` veya tek alan için `$request->input('start')`
  (gövde JSON değilse `$_POST`'a düşer)
- Token: `$request->bearerToken()`

## Yanıt (Response) — standart zarf

Her endpoint **aynı** zarfı döndürür:

```json
{ "success": true, "data": { } }
```

```json
{ "success": false, "error": { "message": "...", "code": "AUTH_INVALID" } }
```

- Sayfalama/sayaç gibi meta alanlar `data.meta` altında durur.
- `json_encode(..., JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)` kullanılır.
- Başarı: `$this->response->success([...])`
- Hata: `throw new HttpException('mesaj', 'KOD', 400)` — front controller zarfa çevirir.

## IIS uyumu

IIS, hata HTTP kodlarında kendi sayfasını gösterebildiği için `config/app.php` içindeki
`force_http_200 = true` ayarı, gövdede `success:false` tutarken HTTP'yi **200** döndürür.
Standart davranışa dönmek için bu bayrak tek yerden `false` yapılır.

## Hata yönetimi

- İş kuralı / doğrulama hataları `HttpException` fırlatır.
- Beklenmeyen hatalar front controller'da `Throwable` ile yakalanır.
- Hata detayı yalnızca `config/app.php` içindeki `debug = true` iken yanıta eklenir.

## Sunucuya özel ayarlar (config/app.local.php)

`config/app.php` repoya girer ve her güncellemede üzerine yazılır. Sunucuya özel /
gizli değerler (sırlar, bu sitede farklı olan `base_path`/`allowed_ips`) bunun yerine
**`config/app.local.php`** dosyasına yazılır:

- Git'e girmez (`.gitignore`), otomatik güncelleme bu dosyaya **asla dokunmaz**.
- `bootstrap.php` içinde `config/app.php` ile birleştirilir (`app.local.php` önceliklidir).
- Şablon: `config/app.local.php.example` — sunucuda `app.local.php` adıyla kopyalanıp doldurulur.

## Sürümlendirme (git tag)

Sürüm kaynağı **git tag**'dir, commit değil. Yeni bir sürüm yayınlamak için:

```bash
git tag 1.4.0
git push --tags
```

- `GET /backend-api/version` → kurulu sürümü döner:
  `{"version":"1.4.0","sha":"...","deployed_at":"...","changelog":[{"sha":"a1b2c3d","message":"..."}]}`.
  Herkese açıktır (sır istemez), sadece bilgi verir — güncelleme YAPMAZ.
- Repoda hiç tag yoksa (henüz hiç etiketlenmemiş proje) `version: null` döner, `sha` yine gösterilir.
- Tag adları semver benzeri olmalı (`1.4.0`, `v1.4.0`, `1.4`, `1`): "en son sürüm" bunlar arasından
  `version_compare` ile bulunur. Semver'e uymayan tag'ler (örn. `nightly`) sıralamaya katılmaz.

### Changelog: "bu sürümde ne değişti" — commit mesajlarından otomatik

Tag atarken ayrıca bir şey yazman gerekmez: her güncellemede, önceki kurulu sürümle yeni
sürüm arasındaki **commit mesajları** GitHub'ın "compare" API'sinden otomatik çekilip
kaydedilir (en yeni commit ilk sırada, en fazla 50 commit).

- `GET /backend-api/version` ve `GET /backend-api/update/status` → son güncellemenin
  changelog'unu (`changelog` alanı) gösterir — kalıcıdır, `.deploy-state.json`'da tutulur.
- `GET /backend-api/update/changelog?from=1.3.0&to=1.4.0` → **kurulum yapmadan** iki sürüm
  arasındaki farkı önizler (ikisi de opsiyonel: `to` boşsa en son tag, `from` boşsa şu an
  kurulu sürüm). 400 siteye toplu güncelleme öncesi "bu sürümde ne var" görmek için kullanılır.
  Bu uç da `X-Deploy-Secret` ister.

## Otomatik güncelleme (400 site — git binary'siz)

Sunucularda `git` kurulu olmayabileceği / `shell_exec` kapalı olabileceği için güncelleme
**salt PHP** ile yapılır: GitHub API'den hedef tag/commit bulunur, onun `.zip` arşivi
indirilir (`ZipArchive`), mevcut `backend-api/` üzerine **sadece kopyalanır** (hiçbir şey
silinmez — bu yüzden `config/app.local.php`, `.backups/`, `.update.log`, `.deploy-state.json`
gibi arşivde olmayan dosyalar dokunulmadan kalır).

- `POST /backend-api/update` → **varsayılan: repodaki EN SON tag**'i kurar.
  - Body `{"version": "1.4.0"}` → o BELİRLİ tag'i kurar (geri alma / belirli sürüme sabitleme için).
  - Body `{"force": true}` → hedef zaten kuruluysa bile yeniden indirip kurar.
  - Repoda hiç tag yoksa branch'in (`github_branch`) son commit'ine düşer.
- `GET  /backend-api/update/status` → indirmeden, kurulu sürüm + commit + önceki sürüm bilgisini döner.
- `update` uçları `X-Deploy-Secret` header'ı ister (`config/app.local.php` → `deploy_secret`).
  Bu, müşteri Bearer token'ından (AuthToken) tamamen ayrı bir mekanizmadır. `version` ucu ister istemez.
- Güncellemeden önce mevcut `backend-api/` otomatik olarak `.backups/{tarih-saat}.zip`
  içine yedeklenir (son 3 yedek tutulur) — bir güncelleme bozarsa elle geri yüklenebilir.
- Kod: `src/Core/Updater.php` (tag çözümleme/indirme/kopyalama/yedekleme), `src/Controller/UpdateController.php`
  (deploy/status uçları + sır kontrolü), `src/Controller/VersionController.php` (herkese açık sürüm ucu).
