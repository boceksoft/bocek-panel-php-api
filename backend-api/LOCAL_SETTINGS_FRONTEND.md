# Local Settings Frontend Kullanim Talimati

Bu endpointler panelden `config/app.local.php` ayarlarini listelemek ve
guncellemek icindir.

Sadece `editable_local_settings` icindeki ayarlar degistirilebilir. `deploy_secret`,
`github_token` ve DB bilgileri panelden degistirilmez.

`local-settings` public degildir; mevcut API davranisina gore Bearer token gerekir.

```http
Authorization: Bearer TOKEN
Content-Type: application/json
```

## Tum Ayarlari Listele

```http
GET /backend-api/local-settings/list
```

Response yapisi:

```json
{
  "success": true,
  "data": {
    "values": {
      "links_search_page_url": {
        "type": "string",
        "value": "https://www.site.com/arama",
        "local": true
      }
    }
  }
}
```

Response alanlari:

- `type`: Frontend input tipidir. `string`, `bool` veya `array` gelir.
- `value`: Ekranda gosterilecek mevcut degerdir.
- `local`: `true` ise deger `app.local.php` icinde vardir. `false` ise deger `app.php` default degerinden geliyordur.

Tek ayar listelemek gerekirse:

```http
GET /backend-api/local-settings/list?key=links_search_page_url
```

Birden fazla ayar listelemek gerekirse:

```http
GET /backend-api/local-settings/list?keys=links_search_page_url,reservation_filters_use_acenta_users
```

## Ayar Guncelle

Tavsiye edilen yontem JSON body ile gondermektir.

```http
POST /backend-api/local-settings/update
```

```json
{
  "changes": {
    "links_search_page_url": "https://www.site.com/arama"
  }
}
```

Response:

```json
{
  "success": true,
  "data": {
    "updated": {
      "links_search_page_url": "https://www.site.com/arama"
    }
  }
}
```

Query parametre ile tek ayar guncellemek gerekirse:

```http
POST /backend-api/local-settings/update?key=links_search_page_url&value=https://www.site.com/arama
```

Direkt parametre olarak da gonderilebilir:

```http
POST /backend-api/local-settings/update?links_search_page_url=https://www.site.com/arama
```

## Duzenlenebilir Ayarlar

Frontend sadece bu ayarlari gostermeli ve update icin sadece bu keyleri
gondermelidir.

```php
'editable_local_settings' => [
    'guest_movements_home_id_column' => 'bool',
    'guest_movements_use_caretaker_owner_fallback' => 'bool',
    'links_search_page_query' => 'string',
    'links_search_page_url' => 'string',
    'reservation_filters_use_acenta_users' => 'bool',
    'reservation_detail_use_islem_kaydi' => 'bool',
    'reservations_country_code_column' => 'string',
    'calculate_param_names' => 'array',
    'calculate_date_formats' => 'array',
    'calculate_reservation_url' => 'array',
    'links_reservation_url' => 'array',
    'site_column_suffixes' => 'array',
    'calculate_prices_same_across_sites' => 'bool',
],
``` 
 
## Ayar Aciklamalari

`guest_movements_home_id_column`  
Guest movements listesinde ev id eslestirmesi icin kullanilan kaynaktir. `false` ise `d.emlak`, `true` ise `k.evid` kullanilir.

`guest_movements_use_caretaker_owner_fallback`  
Guest movements listesinde ev sahibi adi veya telefonu bos gelirse `homes.BakimciAd` ve `homes.BakimciTel` fallback olarak kullanilsin mi?

`links_search_page_query`  
Link olustururken redirect hedefindeki arama sayfasi yolunu DB'den okumak icin kullanilan SQL sorgusudur. Sorgu ilk satirda `url` alanini dondurmelidir.

`links_search_page_url`  
Doluysa `links_search_page_query` calismaz. Redirect hedefi direkt bu URL olur. Tam URL veya path verilebilir. Ornek: `/arama`, `https://www.site.com/arama`.

`reservation_filters_use_acenta_users`  
Rezervasyon filtrelerinde `acenta_users` tablosundan alt acentalar cekilsin mi?

`reservation_detail_use_islem_kaydi`  
Rezervasyon detayinda `islem_kaydi` tablosundan belge gecmisi cekilsin mi?

`reservations_country_code_column`  
`kayitlar` tablosunda ulke/kod bilgisinin tutuldugu kolon adidir. Ornek: `ulke`, `ulkeKodu`, `countryCode`.

`calculate_param_names`  
Calculate endpoint'ine gelen parametre adlarini siteye gore eslemek icin kullanilir. Ornek olarak `entity_id`, `start`, `end` icin kabul edilecek alternatif parametre adlari tanimlanir.

`calculate_date_formats`  
Calculate endpoint'inin kabul edecegi tarih formatlari listesidir. Ornek: `Y-m-d`, `d.m.Y`, `m/d/Y`.

`calculate_reservation_url`  
Calculate sonucunda donen `reservation_url` icin path, query parametre adlari ve tarih formati ayarlaridir.

`links_reservation_url`  
Link olusturma endpoint'inin `redirectTo` query parametre adlarini ve tarih formatini belirler.

`site_column_suffixes`  
Siteye gore kolon suffix yapisini belirler. Ornek: site 2 icin `_s2`, site 3 icin `_s3`.

`calculate_prices_same_across_sites`  
`true` ise fiyat/sezon/indirim hesaplari her site icin site 1 uzerinden yapilir. `false` ise hesaplama istekte gelen site id ile yapilir.

## Frontend Notlari

- Liste ekraninda `GET /local-settings/list` ile gelen tum `values` alanlari gosterilebilir.
- `bool` alanlar checkbox/switch, `string` alanlar text input, `array` alanlar JSON editor/textarea olarak gosterilebilir.
- Update ederken sadece degisen alanlari `changes` icinde gondermek yeterlidir.
- Gonderilmeyen alanlar degismez.
- Whitelist disinda key gonderilirse API hata verir.
- Kaydedilen degerler sadece `config/app.local.php` dosyasina yazilir.
