IF COL_LENGTH(N'[dbo].[defter]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[defter] ADD [id] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[defter]', N'rezid') IS NULL
BEGIN
    ALTER TABLE [dbo].[defter] ADD [rezid] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[destinations]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[destinations] ADD [id] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[destinations]', N'baslik') IS NULL
BEGIN
    ALTER TABLE [dbo].[destinations] ADD [baslik] nvarchar(MAX) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[destinations]', N'cat') IS NULL
BEGIN
    ALTER TABLE [dbo].[destinations] ADD [cat] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[dolu]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[dolu] ADD [id] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[dolu]', N'emlak') IS NULL
BEGIN
    ALTER TABLE [dbo].[dolu] ADD [emlak] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[dolu]', N'tarih') IS NULL
BEGIN
    ALTER TABLE [dbo].[dolu] ADD [tarih] date NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[dolu]', N'tarih2') IS NULL
BEGIN
    ALTER TABLE [dbo].[dolu] ADD [tarih2] date NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[dolu]', N'kayitid') IS NULL
BEGIN
    ALTER TABLE [dbo].[dolu] ADD [kayitid] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[dolu]', N'durum') IS NULL
BEGIN
    ALTER TABLE [dbo].[dolu] ADD [durum] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[dolu_fake]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[dolu_fake] ADD [id] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[dolu_fake]', N'emlak') IS NULL
BEGIN
    ALTER TABLE [dbo].[dolu_fake] ADD [emlak] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[dolu_fake]', N'tarih2') IS NULL
BEGIN
    ALTER TABLE [dbo].[dolu_fake] ADD [tarih2] date NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[dolu_fake]', N'tarih') IS NULL
BEGIN
    ALTER TABLE [dbo].[dolu_fake] ADD [tarih] date NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[homes]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [id] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[homes]', N'baslik') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [baslik] nvarchar(MAX) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[homes]', N'emlak_tipi') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [emlak_tipi] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[homes]', N'emlak_bolgesi') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [emlak_bolgesi] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[homes]', N'url') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [url] nvarchar(MAX) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[homes]', N'doviz') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [doviz] nvarchar(255) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[homes]', N'depozito') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [depozito] nvarchar(255) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[homes]', N'evsahibi') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [evsahibi] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[homes]', N'baslik_s3') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [baslik_s3] nvarchar(MAX) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[homes]', N'kazancorani') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [kazancorani] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[homes]', N'rez_takip_yeri_adi') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [rez_takip_yeri_adi] nvarchar(250) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[indirimler]', N'emlak') IS NULL
BEGIN
    ALTER TABLE [dbo].[indirimler] ADD [emlak] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[indirimler]', N'tarih1') IS NULL
BEGIN
    ALTER TABLE [dbo].[indirimler] ADD [tarih1] date NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[indirimler]', N'tarih2') IS NULL
BEGIN
    ALTER TABLE [dbo].[indirimler] ADD [tarih2] date NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[indirimler]', N'oran') IS NULL
BEGIN
    ALTER TABLE [dbo].[indirimler] ADD [oran] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[indirimler]', N'site') IS NULL
BEGIN
    ALTER TABLE [dbo].[indirimler] ADD [site] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[indirimler]', N'showDate1') IS NULL
BEGIN
    ALTER TABLE [dbo].[indirimler] ADD [showDate1] date NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[indirimler]', N'showDate2') IS NULL
BEGIN
    ALTER TABLE [dbo].[indirimler] ADD [showDate2] date NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[indirimler]', N'sahte_oran') IS NULL
BEGIN
    ALTER TABLE [dbo].[indirimler] ADD [sahte_oran] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[indirimler]', N'createdDate') IS NULL
BEGIN
    ALTER TABLE [dbo].[indirimler] ADD [createdDate] datetime NULL CONSTRAINT DF_indirimler_createdDate DEFAULT GETDATE();
END;
GO

IF COL_LENGTH(N'[dbo].[indirimler]', N'vitrin') IS NULL
BEGIN
    ALTER TABLE [dbo].[indirimler] ADD [vitrin] bit NULL CONSTRAINT DF_indirimler_vitrin DEFAULT 0;
END;
GO

IF COL_LENGTH(N'[dbo].[indirimler]', N'discountType') IS NULL
BEGIN
    ALTER TABLE [dbo].[indirimler] ADD [discountType] int NULL CONSTRAINT DF_indirimler_discountType DEFAULT 1;
END;
GO

IF COL_LENGTH(N'[dbo].[kanun7464]', N'homeId') IS NULL
BEGIN
    ALTER TABLE [dbo].[kanun7464] ADD [homeId] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kanun7464]', N'gavel') IS NULL
BEGIN
    ALTER TABLE [dbo].[kanun7464] ADD [gavel] bit NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kanun7464]', N'belgeSuresiTipi') IS NULL
BEGIN
    ALTER TABLE [dbo].[kanun7464] ADD [belgeSuresiTipi] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [id] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'adi') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [adi] nvarchar(MAX) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'islem_tarihi') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [islem_tarihi] smalldatetime NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'saat') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [saat] nvarchar(MAX) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'odeme') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [odeme] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'musteri') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [musteri] nvarchar(MAX) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'rez_tarihi') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [rez_tarihi] date NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'gelecek_tarih') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [gelecek_tarih] date NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'toplam_tutar') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [toplam_tutar] nvarchar(255) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'on_odeme') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [on_odeme] nvarchar(255) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'kalan') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [kalan] nvarchar(255) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'temizlik') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [temizlik] nvarchar(MAX) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'email') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [email] nvarchar(MAX) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'telefon') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [telefon] nvarchar(MAX) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'tur') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [tur] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'doviz') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [doviz] nvarchar(50) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'gonderildi') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [gonderildi] bit NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'kur') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [kur] money NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'site') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [site] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'evid') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [evid] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'satis_kanallari_id') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [satis_kanallari_id] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'oznot') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [oznot] nvarchar(MAX) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'arandi') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [arandi] bit NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'sozlesme') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [sozlesme] bit NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'promotionCode') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [promotionCode] nvarchar(20) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'kazancorani') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [kazancorani] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'ulkekodu') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [ulkekodu] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'kar') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [kar] nvarchar(50) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'maliyet') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [maliyet] nvarchar(50) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kisi_bilgileri]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[kisi_bilgileri] ADD [id] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kisi_bilgileri]', N'siparis_kodu') IS NULL
BEGIN
    ALTER TABLE [dbo].[kisi_bilgileri] ADD [siparis_kodu] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kullanici]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[kullanici] ADD [id] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kullanici]', N'tel') IS NULL
BEGIN
    ALTER TABLE [dbo].[kullanici] ADD [tel] nvarchar(255) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kullanici_log_kaydi]', N'kullanici_id') IS NULL
BEGIN
    ALTER TABLE [dbo].[kullanici_log_kaydi] ADD [kullanici_id] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[mesafeler]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[mesafeler] ADD [id] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[mesafeler]', N'baslik') IS NULL
BEGIN
    ALTER TABLE [dbo].[mesafeler] ADD [baslik] nvarchar(MAX) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[mesafelerValues]', N'mesafelerId') IS NULL
BEGIN
    ALTER TABLE [dbo].[mesafelerValues] ADD [mesafelerId] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[mesafelerValues]', N'homesId') IS NULL
BEGIN
    ALTER TABLE [dbo].[mesafelerValues] ADD [homesId] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[odalar]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[odalar] ADD [id] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[odalarValues]', N'homesId') IS NULL
BEGIN
    ALTER TABLE [dbo].[odalarValues] ADD [homesId] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[odalarValues]', N'odalarId') IS NULL
BEGIN
    ALTER TABLE [dbo].[odalarValues] ADD [odalarId] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[rate]', N'CurrencyName') IS NULL
BEGIN
    ALTER TABLE [dbo].[rate] ADD [CurrencyName] nvarchar(255) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[redirects]', N'teklifId') IS NULL
BEGIN
    ALTER TABLE [dbo].[redirects] ADD [teklifId] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[rules]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[rules] ADD [id] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[rulesruletypes]', N'ruletypes') IS NULL
BEGIN
    ALTER TABLE [dbo].[rulesruletypes] ADD [ruletypes] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[rulesruletypes]', N'value') IS NULL
BEGIN
    ALTER TABLE [dbo].[rulesruletypes] ADD [value] varchar(500) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[ruletypes]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[ruletypes] ADD [id] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[satis_kanallari]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[satis_kanallari] ADD [id] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[satis_kanallari]', N'baslik') IS NULL
BEGIN
    ALTER TABLE [dbo].[satis_kanallari] ADD [baslik] nvarchar(MAX) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[sezonlar]', N'fiyat') IS NULL
BEGIN
    ALTER TABLE [dbo].[sezonlar] ADD [fiyat] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[sezonlar]', N'tarih1') IS NULL
BEGIN
    ALTER TABLE [dbo].[sezonlar] ADD [tarih1] nvarchar(10) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[sezonlar]', N'tarih2') IS NULL
BEGIN
    ALTER TABLE [dbo].[sezonlar] ADD [tarih2] nvarchar(10) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[sezonlar]', N'islem') IS NULL
BEGIN
    ALTER TABLE [dbo].[sezonlar] ADD [islem] nvarchar(20) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[sezonlar]', N'islem_id') IS NULL
BEGIN
    ALTER TABLE [dbo].[sezonlar] ADD [islem_id] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[sezonlar]', N'site') IS NULL
BEGIN
    ALTER TABLE [dbo].[sezonlar] ADD [site] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[sites]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[sites] ADD [id] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[sonDakika]', N'tarih2') IS NULL
BEGIN
    ALTER TABLE [dbo].[sonDakika] ADD [tarih2] nvarchar(MAX) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[sonDakika]', N'islem_id') IS NULL
BEGIN
    ALTER TABLE [dbo].[sonDakika] ADD [islem_id] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[sonDakika]', N'site') IS NULL
BEGIN
    ALTER TABLE [dbo].[sonDakika] ADD [site] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[teklifler]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[teklifler] ADD [id] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[teklifler]', N'isim') IS NULL
BEGIN
    ALTER TABLE [dbo].[teklifler] ADD [isim] nvarchar(50) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[teklifler]', N'email') IS NULL
BEGIN
    ALTER TABLE [dbo].[teklifler] ADD [email] nvarchar(50) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[teklifler]', N'telefon') IS NULL
BEGIN
    ALTER TABLE [dbo].[teklifler] ADD [telefon] nvarchar(50) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[teklifler]', N'kisi') IS NULL
BEGIN
    ALTER TABLE [dbo].[teklifler] ADD [kisi] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[teklifler]', N'parametreler') IS NULL
BEGIN
    ALTER TABLE [dbo].[teklifler] ADD [parametreler] nvarchar(MAX) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[teklifler]', N'createdOn') IS NULL
BEGIN
    ALTER TABLE [dbo].[teklifler] ADD [createdOn] smalldatetime NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[teklifler]', N'site') IS NULL
BEGIN
    ALTER TABLE [dbo].[teklifler] ADD [site] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[teklifler]', N'link') IS NULL
BEGIN
    ALTER TABLE [dbo].[teklifler] ADD [link] nvarchar(MAX) NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[tip]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[tip] ADD [id] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[tip]', N'cat') IS NULL
BEGIN
    ALTER TABLE [dbo].[tip] ADD [cat] int NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[tip]', N'aktif') IS NULL
BEGIN
    ALTER TABLE [dbo].[tip] ADD [aktif] bit NULL;
END;
GO

IF COL_LENGTH(N'[Finance].[Currency]', N'CurrencyId') IS NULL
BEGIN
    ALTER TABLE [Finance].[Currency] ADD [CurrencyId] tinyint NULL;
END;
GO

IF COL_LENGTH(N'[Finance].[Currency]', N'CurrencyName') IS NULL
BEGIN
    ALTER TABLE [Finance].[Currency] ADD [CurrencyName] nvarchar(20) NULL;
END;
GO

IF COL_LENGTH(N'[Finance].[Currency]', N'Symbol') IS NULL
BEGIN
    ALTER TABLE [Finance].[Currency] ADD [Symbol] nvarchar(3) NULL;
END;
GO

IF COL_LENGTH(N'[Finance].[RateDetail]', N'RateId') IS NULL
BEGIN
    ALTER TABLE [Finance].[RateDetail] ADD [RateId] bigint NULL;
END;
GO

IF COL_LENGTH(N'[Finance].[RateDetail]', N'FromCurrencyId') IS NULL
BEGIN
    ALTER TABLE [Finance].[RateDetail] ADD [FromCurrencyId] tinyint NULL;
END;
GO

IF COL_LENGTH(N'[Finance].[RateDetail]', N'ToCurrencyId') IS NULL
BEGIN
    ALTER TABLE [Finance].[RateDetail] ADD [ToCurrencyId] tinyint NULL;
END;
GO

IF COL_LENGTH(N'[Finance].[RateDetail]', N'Buy') IS NULL
BEGIN
    ALTER TABLE [Finance].[RateDetail] ADD [Buy] decimal(18,6) NULL;
END;
GO

IF COL_LENGTH(N'[KiralamaTakvimi].[CalendarHomes]', N'homesId') IS NULL
BEGIN
    ALTER TABLE [KiralamaTakvimi].[CalendarHomes] ADD [homesId] int NULL;
END;
GO

IF COL_LENGTH(N'[KiralamaTakvimi].[CalendarHomes]', N'EstateId') IS NULL
BEGIN
    ALTER TABLE [KiralamaTakvimi].[CalendarHomes] ADD [EstateId] int NULL;
END;
GO

IF COL_LENGTH(N'[KiralamaTakvimi].[CalendarHomes]', N'RoomType') IS NULL
BEGIN
    ALTER TABLE [KiralamaTakvimi].[CalendarHomes] ADD [RoomType] int NULL;
END;
GO

IF COL_LENGTH(N'[KiralamaTakvimi].[HotelAvailabilityRooms]', N'Date') IS NULL
BEGIN
    ALTER TABLE [KiralamaTakvimi].[HotelAvailabilityRooms] ADD [Date] date NULL;
END;
GO

IF COL_LENGTH(N'[KiralamaTakvimi].[HotelAvailabilityRooms]', N'RoomCount') IS NULL
BEGIN
    ALTER TABLE [KiralamaTakvimi].[HotelAvailabilityRooms] ADD [RoomCount] int NULL;
END;
GO

IF COL_LENGTH(N'[KiralamaTakvimi].[HotelAvailabilityRooms]', N'EstateId') IS NULL
BEGIN
    ALTER TABLE [KiralamaTakvimi].[HotelAvailabilityRooms] ADD [EstateId] int NULL;
END;
GO

IF COL_LENGTH(N'[KiralamaTakvimi].[HotelAvailabilityRooms]', N'IsClosed') IS NULL
BEGIN
    ALTER TABLE [KiralamaTakvimi].[HotelAvailabilityRooms] ADD [IsClosed] bit NULL;
END;
GO

IF COL_LENGTH(N'[dbo].[kayitlar]', N'onaylanmaTarihi2') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [onaylanmaTarihi2] date NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[blockList]', N'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[blockList]
    (
        [id]           int IDENTITY(1,1) NOT NULL
            CONSTRAINT [PK_blockList] PRIMARY KEY,
        [ip]           nvarchar(200) NULL,
        [minute]       int NULL,
        [createdDate]  datetime NULL
            CONSTRAINT [DF_blockList_createdDate] DEFAULT GETDATE(),
        [modifiedDate] datetime NULL
            CONSTRAINT [DF_blockList_modifiedDate] DEFAULT GETDATE()
    );
END;
GO

IF OBJECT_ID('dbo.HomesExtraPaymentTypes', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.HomesExtraPaymentTypes
    (
        ExtraPaymentTypeId smallint IDENTITY(1,1) NOT NULL CONSTRAINT PK_HomesExtraPaymentTypes PRIMARY KEY,
        Code nvarchar(64) NOT NULL,
        Name nvarchar(255) NOT NULL,
        HomeColumnBase nvarchar(64) NOT NULL,
        IsDeleted bit NOT NULL CONSTRAINT DF_HomesExtraPaymentTypes_IsDeleted DEFAULT 0,
        IsIncluded bit NOT NULL CONSTRAINT DF_HomesExtraPaymentTypes_IsIncluded DEFAULT 0
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = 'UX_HomesExtraPaymentTypes_Code'
      AND object_id = OBJECT_ID('dbo.HomesExtraPaymentTypes')
)
BEGIN
    CREATE UNIQUE INDEX UX_HomesExtraPaymentTypes_Code
        ON dbo.HomesExtraPaymentTypes (Code)
        WHERE IsDeleted = 0;
END;
GO

IF OBJECT_ID('dbo.HomesExtraPaymentPrices', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.HomesExtraPaymentPrices
    (
        ExtraPaymentPriceId int IDENTITY(1,1) NOT NULL CONSTRAINT PK_HomesExtraPaymentPrices PRIMARY KEY,
        HomesId int NOT NULL,
        SeasonId int NULL,
        StartDate date NOT NULL,
        EndDate date NOT NULL,
        CurrencyId int NULL,
        PriceType nvarchar(32) NULL,
        ExtraPaymentTypeId smallint NOT NULL,
        Value money NOT NULL CONSTRAINT DF_HomesExtraPaymentPrices_Value DEFAULT 0.0,
        Description nvarchar(511) NULL,
        CreatedOn smalldatetime NULL CONSTRAINT DF_HomesExtraPaymentPrices_CreatedOn DEFAULT GETDATE(),
        UpdatedOn smalldatetime NULL,
        IsDeleted bit NOT NULL CONSTRAINT DF_HomesExtraPaymentPrices_IsDeleted DEFAULT 0,
        CONSTRAINT FK_HomesExtraPaymentPrices_Types FOREIGN KEY (ExtraPaymentTypeId)
            REFERENCES dbo.HomesExtraPaymentTypes (ExtraPaymentTypeId)
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_HomesExtraPaymentPrices_HomesId' AND object_id = OBJECT_ID('dbo.HomesExtraPaymentPrices'))
    CREATE INDEX IX_HomesExtraPaymentPrices_HomesId ON dbo.HomesExtraPaymentPrices (HomesId);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_HomesExtraPaymentPrices_SeasonId' AND object_id = OBJECT_ID('dbo.HomesExtraPaymentPrices'))
    CREATE INDEX IX_HomesExtraPaymentPrices_SeasonId ON dbo.HomesExtraPaymentPrices (SeasonId);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_HomesExtraPaymentPrices_TypeId' AND object_id = OBJECT_ID('dbo.HomesExtraPaymentPrices'))
    CREATE INDEX IX_HomesExtraPaymentPrices_TypeId ON dbo.HomesExtraPaymentPrices (ExtraPaymentTypeId);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_HomesExtraPaymentPrices_DateRange' AND object_id = OBJECT_ID('dbo.HomesExtraPaymentPrices'))
    CREATE INDEX IX_HomesExtraPaymentPrices_DateRange ON dbo.HomesExtraPaymentPrices (StartDate, EndDate);
GO

IF COL_LENGTH('dbo.HomesExtraPaymentPrices', 'CurrencyId') IS NULL
    ALTER TABLE dbo.HomesExtraPaymentPrices ADD CurrencyId int NULL;
GO

IF COL_LENGTH('dbo.HomesExtraPaymentPrices', 'PriceType') IS NULL
    ALTER TABLE dbo.HomesExtraPaymentPrices ADD PriceType nvarchar(32) NULL;
GO

IF COL_LENGTH('dbo.HomesExtraPaymentPrices', 'Description') IS NULL
    ALTER TABLE dbo.HomesExtraPaymentPrices ADD Description nvarchar(511) NULL;
GO

IF COL_LENGTH('dbo.HomesExtraPaymentPrices', 'UpdatedOn') IS NULL
    ALTER TABLE dbo.HomesExtraPaymentPrices ADD UpdatedOn smalldatetime NULL;
GO

MERGE dbo.HomesExtraPaymentTypes AS target
USING (VALUES
    (N'hasar', N'Hasar Depozitosu', N'hasar', CONVERT(bit, 0)),
    (N'temizlik', N'Temizlik', N'temizlik', CONVERT(bit, 0)),
    (N'elektrik', N'Elektrik-Su', N'elektrik', CONVERT(bit, 0))
) AS source (Code, Name, HomeColumnBase, IsIncluded)
ON target.Code = source.Code
WHEN MATCHED THEN
    UPDATE SET Name = source.Name, HomeColumnBase = source.HomeColumnBase, IsIncluded = source.IsIncluded, IsDeleted = 0
WHEN NOT MATCHED THEN
    INSERT (Code, Name, HomeColumnBase, IsIncluded)
    VALUES (source.Code, source.Name, source.HomeColumnBase, source.IsIncluded);
GO
