/*
    Bu sorguyu kolon tiplerinin dogru oldugu KAYNAK veritabaninda calistir.

    Yalnizca bu dosyadaki tablo/kolon listesi icin ALTER TABLE scripti uretir.
    Kaynak veritabanindaki diger kolonlar ciktuya dahil edilmez.

    OlusanSqlScript sonucunu kopyalayip hedef veritabaninda calistir.
    Hedefte tablo yoksa script o tabloyu atlar; mevcut kolonlara ve dataya dokunmaz.
*/

SET NOCOUNT ON;

DECLARE @IstenenKolonlar TABLE
(
    SchemaName SYSNAME NOT NULL,
    TableName  SYSNAME NOT NULL,
    ColumnName SYSNAME NOT NULL,
    PRIMARY KEY (SchemaName, TableName, ColumnName)
);

INSERT INTO @IstenenKolonlar (SchemaName, TableName, ColumnName)
VALUES
    (N'dbo', N'acenta_kayitlar', N'acentaId'),
    (N'dbo', N'acenta_kayitlar', N'kayitId'),
    (N'dbo', N'acenta_users', N'id'),
    (N'dbo', N'havuztanimlamari', N'deger'),
    (N'dbo', N'havuztanimlamari', N'derinlik'),
    (N'dbo', N'havuztanimlamari', N'genislik'),
    (N'dbo', N'havuztanimlamari', N'havuzTipiId'),
    (N'dbo', N'havuztanimlamari', N'homesId'),
    (N'dbo', N'havuztanimlamari', N'id'),
    (N'dbo', N'havuztanimlamari', N'tamKorunakli'),
    (N'dbo', N'havuztanimlamari', N'tipId'),
    (N'dbo', N'havuztanimlamari', N'uzunluk'),
    (N'dbo', N'havuztipitanimlari', N'baslik'),
    (N'dbo', N'havuztipitanimlari', N'id'),
    (N'dbo', N'HomesExtraPaymentPrices', N'EndDate'),
    (N'dbo', N'HomesExtraPaymentPrices', N'ExtraPaymentPriceId'),
    (N'dbo', N'HomesExtraPaymentPrices', N'ExtraPaymentTypeId'),
    (N'dbo', N'HomesExtraPaymentPrices', N'HomesId'),
    (N'dbo', N'HomesExtraPaymentPrices', N'IsDeleted'),
    (N'dbo', N'HomesExtraPaymentPrices', N'StartDate'),
    (N'dbo', N'HomesExtraPaymentTypes', N'Code'),
    (N'dbo', N'HomesExtraPaymentTypes', N'ExtraPaymentTypeId'),
    (N'dbo', N'HomesExtraPaymentTypes', N'HomeColumnBase'),
    (N'dbo', N'HomesExtraPaymentTypes', N'IsDeleted'),
    (N'dbo', N'HomesExtraPaymentTypes', N'Name'),
    (N'dbo', N'redirects', N'teklifId'),
    (N'dbo', N'teklifler', N'createdOn'),
    (N'dbo', N'teklifler', N'email'),
    (N'dbo', N'teklifler', N'id'),
    (N'dbo', N'teklifler', N'isim'),
    (N'dbo', N'teklifler', N'kisi'),
    (N'dbo', N'teklifler', N'link'),
    (N'dbo', N'teklifler', N'parametreler'),
    (N'dbo', N'teklifler', N'site'),
    (N'dbo', N'teklifler', N'telefon'),
    (N'dbo', N'defter', N'id'),
    (N'dbo', N'defter', N'rezid'),
    (N'dbo', N'destinations', N'baslik'),
    (N'dbo', N'destinations', N'cat'),
    (N'dbo', N'destinations', N'id'),
    (N'dbo', N'dolu', N'durum'),
    (N'dbo', N'dolu', N'emlak'),
    (N'dbo', N'dolu', N'id'),
    (N'dbo', N'dolu', N'kayitid'),
    (N'dbo', N'dolu', N'tarih'),
    (N'dbo', N'dolu', N'tarih2'),
    (N'dbo', N'dolu_fake', N'emlak'),
    (N'dbo', N'dolu_fake', N'id'),
    (N'dbo', N'dolu_fake', N'tarih'),
    (N'dbo', N'dolu_fake', N'tarih2'),
    (N'Finance', N'Currency', N'CurrencyId'),
    (N'Finance', N'Currency', N'CurrencyName'),
    (N'Finance', N'Currency', N'Symbol'),
    (N'Finance', N'RateDetail', N'Buy'),
    (N'Finance', N'RateDetail', N'FromCurrencyId'),
    (N'Finance', N'RateDetail', N'RateId'),
    (N'Finance', N'RateDetail', N'ToCurrencyId'),
    (N'dbo', N'homes', N'baslik'),
    (N'dbo', N'homes', N'baslik_s3'),
    (N'dbo', N'homes', N'depozito'),
    (N'dbo', N'homes', N'doviz'),
    (N'dbo', N'homes', N'emlak_bolgesi'),
    (N'dbo', N'homes', N'emlak_tipi'),
    (N'dbo', N'homes', N'evsahibi'),
    (N'dbo', N'homes', N'id'),
    (N'dbo', N'homes', N'kazancorani'),
    (N'dbo', N'homes', N'n_emlak_bolgesi'),
    (N'dbo', N'homes', N'rez_takip_yeri_adi'),
    (N'dbo', N'homes', N'url'),
    (N'dbo', N'indirimler', N'emlak'),
    (N'dbo', N'indirimler', N'oran'),
    (N'dbo', N'indirimler', N'sahte_oran'),
    (N'dbo', N'indirimler', N'showDate1'),
    (N'dbo', N'indirimler', N'showDate2'),
    (N'dbo', N'indirimler', N'tarih1'),
    (N'dbo', N'indirimler', N'tarih2'),
    (N'dbo', N'kanun7464', N'belgeSuresiTipi'),
    (N'dbo', N'kanun7464', N'gavel'),
    (N'dbo', N'kanun7464', N'homeId'),
    (N'dbo', N'kayitlar', N'adi'),
    (N'dbo', N'kayitlar', N'arandi'),
    (N'dbo', N'kayitlar', N'doviz'),
    (N'dbo', N'kayitlar', N'email'),
    (N'dbo', N'kayitlar', N'evid'),
    (N'dbo', N'kayitlar', N'gelecek_tarih'),
    (N'dbo', N'kayitlar', N'gonderildi'),
    (N'dbo', N'kayitlar', N'id'),
    (N'dbo', N'kayitlar', N'islem_tarihi'),
    (N'dbo', N'kayitlar', N'kalan'),
    (N'dbo', N'kayitlar', N'kar'),
    (N'dbo', N'kayitlar', N'kazancorani'),
    (N'dbo', N'kayitlar', N'kur'),
    (N'dbo', N'kayitlar', N'maliyet'),
    (N'dbo', N'kayitlar', N'musteri'),
    (N'dbo', N'kayitlar', N'odeme'),
    (N'dbo', N'kayitlar', N'on_odeme'),
    (N'dbo', N'kayitlar', N'oznot'),
    (N'dbo', N'kayitlar', N'promotionCode'),
    (N'dbo', N'kayitlar', N'rez_tarihi'),
    (N'dbo', N'kayitlar', N'saat'),
    (N'dbo', N'kayitlar', N'satis_kanallari_id'),
    (N'dbo', N'kayitlar', N'site'),
    (N'dbo', N'kayitlar', N'sozlesme'),
    (N'dbo', N'kayitlar', N'telefon'),
    (N'dbo', N'kayitlar', N'temizlik'),
    (N'dbo', N'kayitlar', N'toplam_tutar'),
    (N'dbo', N'kayitlar', N'tur'),
    (N'dbo', N'kayitlar', N'ulkekodu'),
    (N'KiralamaTakvimi', N'CalendarHomes', N'EstateId'),
    (N'KiralamaTakvimi', N'CalendarHomes', N'homesId'),
    (N'KiralamaTakvimi', N'CalendarHomes', N'RoomType'),
    (N'KiralamaTakvimi', N'HotelAvailabilityRooms', N'Date'),
    (N'KiralamaTakvimi', N'HotelAvailabilityRooms', N'EstateId'),
    (N'KiralamaTakvimi', N'HotelAvailabilityRooms', N'IsClosed'),
    (N'KiralamaTakvimi', N'HotelAvailabilityRooms', N'RoomCount'),
    (N'dbo', N'kisi_bilgileri', N'id'),
    (N'dbo', N'kisi_bilgileri', N'siparis_kodu'),
    (N'dbo', N'kullanici', N'id'),
    (N'dbo', N'kullanici', N'tel'),
    (N'dbo', N'kullanici_log_kaydi', N'kullanici_id'),
    (N'dbo', N'mesafeler', N'baslik'),
    (N'dbo', N'mesafeler', N'id'),
    (N'dbo', N'mesafelerValues', N'homesId'),
    (N'dbo', N'mesafelerValues', N'mesafelerId'),
    (N'dbo', N'odalar', N'id'),
    (N'dbo', N'odalarValues', N'homesId'),
    (N'dbo', N'odalarValues', N'odalarId'),
    (N'dbo', N'rate', N'CurrencyName'),
    (N'dbo', N'rules', N'id'),
    (N'dbo', N'rulesruletypes', N'ruletypes'),
    (N'dbo', N'rulesruletypes', N'value'),
    (N'dbo', N'ruletypes', N'id'),
    (N'dbo', N'satis_kanallari', N'baslik'),
    (N'dbo', N'satis_kanallari', N'id'),
    (N'dbo', N'sezonlar', N'fiyat'),
    (N'dbo', N'sezonlar', N'islem'),
    (N'dbo', N'sezonlar', N'islem_id'),
    (N'dbo', N'sezonlar', N'site'),
    (N'dbo', N'sezonlar', N'tarih1'),
    (N'dbo', N'sezonlar', N'tarih2'),
    (N'dbo', N'sites', N'id'),
    (N'dbo', N'sonDakika', N'islem_id'),
    (N'dbo', N'sonDakika', N'site'),
    (N'dbo', N'sonDakika', N'tarih2'),
    (N'dbo', N'tip', N'aktif'),
    (N'dbo', N'tip', N'cat'),
    (N'dbo', N'tip', N'id'),

    -- CalculateController.php tarafindan kullanilan ve mevcut setup-collums.sql'de olmayan kolonlar
    (N'KiralamaTakvimi', N'CalendarHomes', N'BookableDirectly'),
    (N'dbo', N'homes', N'aktif'),
    (N'dbo', N'homes', N'hasar'),
    (N'dbo', N'homes', N'kur'),
    (N'dbo', N'homes', N'resim'),
    (N'dbo', N'homes', N'sitemap'),
    (N'dbo', N'sezonlar', N'gece'),
    (N'dbo', N'sezonlar', N'temizlikgece'),
    (N'dbo', N'sezonlar', N'temizlikFiyat'),
    (N'dbo', N'sezonlar', N'isitmaFiyat'),
    (N'dbo', N'sezonlar', N'isitmaHizmetDisi'),
    (N'dbo', N'HomesExtraPayments', N'id'),
    (N'dbo', N'HomesExtraPayments', N'homesId'),
    (N'dbo', N'HomesExtraPayments', N'title'),
    (N'dbo', N'HomesExtraPayments', N'amount'),
    (N'dbo', N'HomesExtraPayments', N'CurrencyId'),
    (N'dbo', N'HomesExtraPayments', N'start_date'),
    (N'dbo', N'HomesExtraPayments', N'end_date'),
    (N'dbo', N'HomesExtraPayments', N'IsOptional'),
    (N'dbo', N'HomesExtraPayments', N'Type'),
    (N'dbo', N'promotionCodes', N'code'),
    (N'dbo', N'promotionCodes', N'startDate'),
    (N'dbo', N'promotionCodes', N'endDate'),
    (N'dbo', N'promotionCodes', N'stock'),
    (N'dbo', N'promotionCodes', N'isPrice'),
    (N'dbo', N'promotionCodes', N'value'),
    (N'Finance', N'Currency', N'CurrencyCode'),
    (N'Finance', N'Rate', N'RateId');

DECLARE @Script NVARCHAR(MAX);

SELECT @Script =
(
    SELECT
        CHAR(13) + CHAR(10) +
        N'IF OBJECT_ID(N''' +
        REPLACE(QUOTENAME(i.SchemaName) + N'.' + QUOTENAME(i.TableName), N'''', N'''''') +
        N''', N''U'') IS NOT NULL' + CHAR(13) + CHAR(10) +
        N'   AND COL_LENGTH(N''' +
        REPLACE(QUOTENAME(i.SchemaName) + N'.' + QUOTENAME(i.TableName), N'''', N'''''') +
        N''', N''' +
        REPLACE(i.ColumnName, N'''', N'''''') +
        N''') IS NULL' + CHAR(13) + CHAR(10) +
        N'BEGIN' + CHAR(13) + CHAR(10) +
        N'    ALTER TABLE ' +
        QUOTENAME(i.SchemaName) + N'.' + QUOTENAME(i.TableName) +
        N' ADD ' + QUOTENAME(i.ColumnName) + N' ' +

        CASE
            WHEN c.DATA_TYPE IN (N'varchar', N'char', N'varbinary', N'binary')
            THEN
                c.DATA_TYPE + N'(' +
                CASE
                    WHEN c.CHARACTER_MAXIMUM_LENGTH = -1 THEN N'MAX'
                    ELSE CONVERT(NVARCHAR(20), c.CHARACTER_MAXIMUM_LENGTH)
                END + N')'

            WHEN c.DATA_TYPE IN (N'nvarchar', N'nchar')
            THEN
                c.DATA_TYPE + N'(' +
                CASE
                    WHEN c.CHARACTER_MAXIMUM_LENGTH = -1 THEN N'MAX'
                    ELSE CONVERT(NVARCHAR(20), c.CHARACTER_MAXIMUM_LENGTH)
                END + N')'

            WHEN c.DATA_TYPE IN (N'decimal', N'numeric')
            THEN
                c.DATA_TYPE + N'(' +
                CONVERT(NVARCHAR(20), c.NUMERIC_PRECISION) + N',' +
                CONVERT(NVARCHAR(20), c.NUMERIC_SCALE) + N')'

            WHEN c.DATA_TYPE IN (N'datetime2', N'datetimeoffset', N'time')
            THEN
                c.DATA_TYPE + N'(' +
                CONVERT(NVARCHAR(20), c.DATETIME_PRECISION) + N')'

            WHEN c.DATA_TYPE = N'float'
                 AND c.NUMERIC_PRECISION IS NOT NULL
            THEN
                c.DATA_TYPE + N'(' +
                CONVERT(NVARCHAR(20), c.NUMERIC_PRECISION) + N')'

            ELSE c.DATA_TYPE
        END +

        N' NULL;' + CHAR(13) + CHAR(10) +
        N'END;' + CHAR(13) + CHAR(10) +
        N'GO' + CHAR(13) + CHAR(10)

    FROM @IstenenKolonlar AS i
    INNER JOIN INFORMATION_SCHEMA.COLUMNS AS c
        ON c.TABLE_SCHEMA = i.SchemaName
       AND c.TABLE_NAME = i.TableName
       AND c.COLUMN_NAME = i.ColumnName
    ORDER BY
        i.SchemaName,
        i.TableName,
        c.ORDINAL_POSITION
    FOR XML PATH(N''), TYPE
).value(N'.', N'NVARCHAR(MAX)');

SELECT @Script AS OlusanSqlScript;

/*
    Kaynak veritabaninda bulunamayan listedeki kolonlari gosterir.
    Bu sonuc bos olmalidir. Bos degilse kaynak database'de kolon yoktur
    veya tablo/sema/kolon adi farklidir.
*/
SELECT
    i.SchemaName,
    i.TableName,
    i.ColumnName
FROM @IstenenKolonlar AS i
LEFT JOIN INFORMATION_SCHEMA.COLUMNS AS c
    ON c.TABLE_SCHEMA = i.SchemaName
   AND c.TABLE_NAME = i.TableName
   AND c.COLUMN_NAME = i.ColumnName
WHERE c.COLUMN_NAME IS NULL
ORDER BY
    i.SchemaName,
    i.TableName,
    i.ColumnName;
