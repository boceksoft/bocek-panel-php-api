IF OBJECT_ID(N'[dbo].[defter]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[defter]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[defter] ADD [id] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[defter]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[defter]', N'rezid') IS NULL
BEGIN
    ALTER TABLE [dbo].[defter] ADD [rezid] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[destinations]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[destinations]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[destinations] ADD [id] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[destinations]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[destinations]', N'baslik') IS NULL
BEGIN
    ALTER TABLE [dbo].[destinations] ADD [baslik] nvarchar(MAX) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[destinations]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[destinations]', N'cat') IS NULL
BEGIN
    ALTER TABLE [dbo].[destinations] ADD [cat] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[dolu]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[dolu]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[dolu] ADD [id] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[dolu]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[dolu]', N'emlak') IS NULL
BEGIN
    ALTER TABLE [dbo].[dolu] ADD [emlak] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[dolu]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[dolu]', N'tarih') IS NULL
BEGIN
    ALTER TABLE [dbo].[dolu] ADD [tarih] date NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[dolu]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[dolu]', N'tarih2') IS NULL
BEGIN
    ALTER TABLE [dbo].[dolu] ADD [tarih2] date NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[dolu]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[dolu]', N'kayitid') IS NULL
BEGIN
    ALTER TABLE [dbo].[dolu] ADD [kayitid] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[dolu]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[dolu]', N'durum') IS NULL
BEGIN
    ALTER TABLE [dbo].[dolu] ADD [durum] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[dolu_fake]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[dolu_fake]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[dolu_fake] ADD [id] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[dolu_fake]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[dolu_fake]', N'emlak') IS NULL
BEGIN
    ALTER TABLE [dbo].[dolu_fake] ADD [emlak] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[dolu_fake]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[dolu_fake]', N'tarih2') IS NULL
BEGIN
    ALTER TABLE [dbo].[dolu_fake] ADD [tarih2] date NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[dolu_fake]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[dolu_fake]', N'tarih') IS NULL
BEGIN
    ALTER TABLE [dbo].[dolu_fake] ADD [tarih] date NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[homes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[homes]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [id] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[homes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[homes]', N'baslik') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [baslik] nvarchar(MAX) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[homes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[homes]', N'emlak_tipi') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [emlak_tipi] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[homes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[homes]', N'emlak_bolgesi') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [emlak_bolgesi] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[homes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[homes]', N'url') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [url] nvarchar(MAX) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[homes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[homes]', N'doviz') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [doviz] nvarchar(255) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[homes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[homes]', N'depozito') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [depozito] nvarchar(255) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[homes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[homes]', N'evsahibi') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [evsahibi] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[homes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[homes]', N'baslik_s3') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [baslik_s3] nvarchar(MAX) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[homes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[homes]', N'kazancorani') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [kazancorani] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[homes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[homes]', N'rez_takip_yeri_adi') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [rez_takip_yeri_adi] nvarchar(250) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[indirimler]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[indirimler]', N'emlak') IS NULL
BEGIN
    ALTER TABLE [dbo].[indirimler] ADD [emlak] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[indirimler]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[indirimler]', N'tarih1') IS NULL
BEGIN
    ALTER TABLE [dbo].[indirimler] ADD [tarih1] date NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[indirimler]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[indirimler]', N'tarih2') IS NULL
BEGIN
    ALTER TABLE [dbo].[indirimler] ADD [tarih2] date NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[indirimler]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[indirimler]', N'oran') IS NULL
BEGIN
    ALTER TABLE [dbo].[indirimler] ADD [oran] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[indirimler]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[indirimler]', N'site') IS NULL
BEGIN
    ALTER TABLE [dbo].[indirimler] ADD [site] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[indirimler]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[indirimler]', N'showDate1') IS NULL
BEGIN
    ALTER TABLE [dbo].[indirimler] ADD [showDate1] date NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[indirimler]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[indirimler]', N'showDate2') IS NULL
BEGIN
    ALTER TABLE [dbo].[indirimler] ADD [showDate2] date NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[indirimler]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[indirimler]', N'sahte_oran') IS NULL
BEGIN
    ALTER TABLE [dbo].[indirimler] ADD [sahte_oran] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[indirimler]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[indirimler]', N'createdDate') IS NULL
BEGIN
    ALTER TABLE [dbo].[indirimler] ADD [createdDate] datetime NULL CONSTRAINT DF_indirimler_createdDate DEFAULT GETDATE();
END;
GO

IF OBJECT_ID(N'[dbo].[indirimler]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[indirimler]', N'vitrin') IS NULL
BEGIN
    ALTER TABLE [dbo].[indirimler] ADD [vitrin] bit NULL CONSTRAINT DF_indirimler_vitrin DEFAULT 0;
END;
GO

IF OBJECT_ID(N'[dbo].[indirimler]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[indirimler]', N'discountType') IS NULL
BEGIN
    ALTER TABLE [dbo].[indirimler] ADD [discountType] int NULL CONSTRAINT DF_indirimler_discountType DEFAULT 1;
END;
GO

IF OBJECT_ID(N'[dbo].[kanun7464]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kanun7464]', N'homeId') IS NULL
BEGIN
    ALTER TABLE [dbo].[kanun7464] ADD [homeId] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kanun7464]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kanun7464]', N'gavel') IS NULL
BEGIN
    ALTER TABLE [dbo].[kanun7464] ADD [gavel] bit NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kanun7464]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kanun7464]', N'belgeSuresiTipi') IS NULL
BEGIN
    ALTER TABLE [dbo].[kanun7464] ADD [belgeSuresiTipi] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [id] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'adi') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [adi] nvarchar(MAX) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'islem_tarihi') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [islem_tarihi] smalldatetime NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'saat') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [saat] nvarchar(MAX) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'odeme') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [odeme] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'musteri') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [musteri] nvarchar(MAX) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'rez_tarihi') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [rez_tarihi] date NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'gelecek_tarih') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [gelecek_tarih] date NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'toplam_tutar') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [toplam_tutar] nvarchar(255) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'on_odeme') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [on_odeme] nvarchar(255) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'kalan') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [kalan] nvarchar(255) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'temizlik') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [temizlik] nvarchar(MAX) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'email') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [email] nvarchar(MAX) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'telefon') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [telefon] nvarchar(MAX) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'tur') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [tur] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'doviz') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [doviz] nvarchar(50) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'gonderildi') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [gonderildi] bit NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'kur') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [kur] money NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'site') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [site] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'evid') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [evid] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'satis_kanallari_id') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [satis_kanallari_id] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'oznot') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [oznot] nvarchar(MAX) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'arandi') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [arandi] bit NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'sozlesme') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [sozlesme] bit NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'promotionCode') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [promotionCode] nvarchar(20) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'kazancorani') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [kazancorani] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'ulkekodu') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [ulkekodu] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'kar') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [kar] nvarchar(50) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'maliyet') IS NULL
BEGIN
    ALTER TABLE [dbo].[kayitlar] ADD [maliyet] nvarchar(50) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kisi_bilgileri]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kisi_bilgileri]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[kisi_bilgileri] ADD [id] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kisi_bilgileri]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kisi_bilgileri]', N'siparis_kodu') IS NULL
BEGIN
    ALTER TABLE [dbo].[kisi_bilgileri] ADD [siparis_kodu] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kullanici]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kullanici]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[kullanici] ADD [id] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kullanici]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kullanici]', N'tel') IS NULL
BEGIN
    ALTER TABLE [dbo].[kullanici] ADD [tel] nvarchar(255) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kullanici_log_kaydi]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kullanici_log_kaydi]', N'kullanici_id') IS NULL
BEGIN
    ALTER TABLE [dbo].[kullanici_log_kaydi] ADD [kullanici_id] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[mesafeler]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[mesafeler]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[mesafeler] ADD [id] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[mesafeler]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[mesafeler]', N'baslik') IS NULL
BEGIN
    ALTER TABLE [dbo].[mesafeler] ADD [baslik] nvarchar(MAX) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[mesafelerValues]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[mesafelerValues]', N'mesafelerId') IS NULL
BEGIN
    ALTER TABLE [dbo].[mesafelerValues] ADD [mesafelerId] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[mesafelerValues]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[mesafelerValues]', N'homesId') IS NULL
BEGIN
    ALTER TABLE [dbo].[mesafelerValues] ADD [homesId] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[odalar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[odalar]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[odalar] ADD [id] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[odalarValues]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[odalarValues]', N'homesId') IS NULL
BEGIN
    ALTER TABLE [dbo].[odalarValues] ADD [homesId] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[odalarValues]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[odalarValues]', N'odalarId') IS NULL
BEGIN
    ALTER TABLE [dbo].[odalarValues] ADD [odalarId] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[rate]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[rate]', N'CurrencyName') IS NULL
BEGIN
    ALTER TABLE [dbo].[rate] ADD [CurrencyName] nvarchar(255) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[redirects]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[redirects]', N'teklifId') IS NULL
BEGIN
    ALTER TABLE [dbo].[redirects] ADD [teklifId] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[rules]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[rules]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[rules] ADD [id] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[rulesruletypes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[rulesruletypes]', N'ruletypes') IS NULL
BEGIN
    ALTER TABLE [dbo].[rulesruletypes] ADD [ruletypes] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[rulesruletypes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[rulesruletypes]', N'value') IS NULL
BEGIN
    ALTER TABLE [dbo].[rulesruletypes] ADD [value] varchar(500) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[ruletypes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[ruletypes]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[ruletypes] ADD [id] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[satis_kanallari]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[satis_kanallari]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[satis_kanallari] ADD [id] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[satis_kanallari]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[satis_kanallari]', N'baslik') IS NULL
BEGIN
    ALTER TABLE [dbo].[satis_kanallari] ADD [baslik] nvarchar(MAX) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[sezonlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[sezonlar]', N'fiyat') IS NULL
BEGIN
    ALTER TABLE [dbo].[sezonlar] ADD [fiyat] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[sezonlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[sezonlar]', N'tarih1') IS NULL
BEGIN
    ALTER TABLE [dbo].[sezonlar] ADD [tarih1] nvarchar(10) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[sezonlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[sezonlar]', N'tarih2') IS NULL
BEGIN
    ALTER TABLE [dbo].[sezonlar] ADD [tarih2] nvarchar(10) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[sezonlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[sezonlar]', N'islem') IS NULL
BEGIN
    ALTER TABLE [dbo].[sezonlar] ADD [islem] nvarchar(20) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[sezonlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[sezonlar]', N'islem_id') IS NULL
BEGIN
    ALTER TABLE [dbo].[sezonlar] ADD [islem_id] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[sezonlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[sezonlar]', N'site') IS NULL
BEGIN
    ALTER TABLE [dbo].[sezonlar] ADD [site] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[sites]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[sites]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[sites] ADD [id] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[sonDakika]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[sonDakika]', N'tarih2') IS NULL
BEGIN
    ALTER TABLE [dbo].[sonDakika] ADD [tarih2] nvarchar(MAX) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[sonDakika]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[sonDakika]', N'islem_id') IS NULL
BEGIN
    ALTER TABLE [dbo].[sonDakika] ADD [islem_id] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[sonDakika]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[sonDakika]', N'site') IS NULL
BEGIN
    ALTER TABLE [dbo].[sonDakika] ADD [site] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[teklifler]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[teklifler]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[teklifler] ADD [id] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[teklifler]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[teklifler]', N'isim') IS NULL
BEGIN
    ALTER TABLE [dbo].[teklifler] ADD [isim] nvarchar(50) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[teklifler]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[teklifler]', N'email') IS NULL
BEGIN
    ALTER TABLE [dbo].[teklifler] ADD [email] nvarchar(50) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[teklifler]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[teklifler]', N'telefon') IS NULL
BEGIN
    ALTER TABLE [dbo].[teklifler] ADD [telefon] nvarchar(50) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[teklifler]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[teklifler]', N'kisi') IS NULL
BEGIN
    ALTER TABLE [dbo].[teklifler] ADD [kisi] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[teklifler]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[teklifler]', N'parametreler') IS NULL
BEGIN
    ALTER TABLE [dbo].[teklifler] ADD [parametreler] nvarchar(MAX) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[teklifler]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[teklifler]', N'createdOn') IS NULL
BEGIN
    ALTER TABLE [dbo].[teklifler] ADD [createdOn] smalldatetime NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[teklifler]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[teklifler]', N'site') IS NULL
BEGIN
    ALTER TABLE [dbo].[teklifler] ADD [site] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[teklifler]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[teklifler]', N'link') IS NULL
BEGIN
    ALTER TABLE [dbo].[teklifler] ADD [link] nvarchar(MAX) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[tip]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[tip]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[tip] ADD [id] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[tip]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[tip]', N'cat') IS NULL
BEGIN
    ALTER TABLE [dbo].[tip] ADD [cat] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[tip]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[tip]', N'aktif') IS NULL
BEGIN
    ALTER TABLE [dbo].[tip] ADD [aktif] bit NULL;
END;
GO

IF OBJECT_ID(N'[Finance].[Currency]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[Finance].[Currency]', N'CurrencyId') IS NULL
BEGIN
    ALTER TABLE [Finance].[Currency] ADD [CurrencyId] tinyint NULL;
END;
GO

IF OBJECT_ID(N'[Finance].[Currency]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[Finance].[Currency]', N'CurrencyName') IS NULL
BEGIN
    ALTER TABLE [Finance].[Currency] ADD [CurrencyName] nvarchar(20) NULL;
END;
GO

IF OBJECT_ID(N'[Finance].[Currency]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[Finance].[Currency]', N'Symbol') IS NULL
BEGIN
    ALTER TABLE [Finance].[Currency] ADD [Symbol] nvarchar(3) NULL;
END;
GO

IF OBJECT_ID(N'[Finance].[RateDetail]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[Finance].[RateDetail]', N'RateId') IS NULL
BEGIN
    ALTER TABLE [Finance].[RateDetail] ADD [RateId] bigint NULL;
END;
GO

IF OBJECT_ID(N'[Finance].[RateDetail]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[Finance].[RateDetail]', N'FromCurrencyId') IS NULL
BEGIN
    ALTER TABLE [Finance].[RateDetail] ADD [FromCurrencyId] tinyint NULL;
END;
GO

IF OBJECT_ID(N'[Finance].[RateDetail]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[Finance].[RateDetail]', N'ToCurrencyId') IS NULL
BEGIN
    ALTER TABLE [Finance].[RateDetail] ADD [ToCurrencyId] tinyint NULL;
END;
GO

IF OBJECT_ID(N'[Finance].[RateDetail]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[Finance].[RateDetail]', N'Buy') IS NULL
BEGIN
    ALTER TABLE [Finance].[RateDetail] ADD [Buy] decimal(18,6) NULL;
END;
GO

IF OBJECT_ID(N'[KiralamaTakvimi].[CalendarHomes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[KiralamaTakvimi].[CalendarHomes]', N'homesId') IS NULL
BEGIN
    ALTER TABLE [KiralamaTakvimi].[CalendarHomes] ADD [homesId] int NULL;
END;
GO

IF OBJECT_ID(N'[KiralamaTakvimi].[CalendarHomes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[KiralamaTakvimi].[CalendarHomes]', N'EstateId') IS NULL
BEGIN
    ALTER TABLE [KiralamaTakvimi].[CalendarHomes] ADD [EstateId] int NULL;
END;
GO

IF OBJECT_ID(N'[KiralamaTakvimi].[CalendarHomes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[KiralamaTakvimi].[CalendarHomes]', N'RoomType') IS NULL
BEGIN
    ALTER TABLE [KiralamaTakvimi].[CalendarHomes] ADD [RoomType] int NULL;
END;
GO

IF OBJECT_ID(N'[KiralamaTakvimi].[HotelAvailabilityRooms]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[KiralamaTakvimi].[HotelAvailabilityRooms]', N'Date') IS NULL
BEGIN
    ALTER TABLE [KiralamaTakvimi].[HotelAvailabilityRooms] ADD [Date] date NULL;
END;
GO

IF OBJECT_ID(N'[KiralamaTakvimi].[HotelAvailabilityRooms]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[KiralamaTakvimi].[HotelAvailabilityRooms]', N'RoomCount') IS NULL
BEGIN
    ALTER TABLE [KiralamaTakvimi].[HotelAvailabilityRooms] ADD [RoomCount] int NULL;
END;
GO

IF OBJECT_ID(N'[KiralamaTakvimi].[HotelAvailabilityRooms]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[KiralamaTakvimi].[HotelAvailabilityRooms]', N'EstateId') IS NULL
BEGIN
    ALTER TABLE [KiralamaTakvimi].[HotelAvailabilityRooms] ADD [EstateId] int NULL;
END;
GO

IF OBJECT_ID(N'[KiralamaTakvimi].[HotelAvailabilityRooms]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[KiralamaTakvimi].[HotelAvailabilityRooms]', N'IsClosed') IS NULL
BEGIN
    ALTER TABLE [KiralamaTakvimi].[HotelAvailabilityRooms] ADD [IsClosed] bit NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[havuztanimlamari]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[havuztanimlamari]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[havuztanimlamari] ADD [id] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[havuztanimlamari]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[havuztanimlamari]', N'homesId') IS NULL
BEGIN
    ALTER TABLE [dbo].[havuztanimlamari] ADD [homesId] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[havuztanimlamari]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[havuztanimlamari]', N'tipId') IS NULL
BEGIN
    ALTER TABLE [dbo].[havuztanimlamari] ADD [tipId] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[havuztanimlamari]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[havuztanimlamari]', N'deger') IS NULL
BEGIN
    ALTER TABLE [dbo].[havuztanimlamari] ADD [deger] nvarchar(255) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[havuztanimlamari]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[havuztanimlamari]', N'havuzTipiId') IS NULL
BEGIN
    ALTER TABLE [dbo].[havuztanimlamari] ADD [havuzTipiId] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[havuztanimlamari]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[havuztanimlamari]', N'uzunluk') IS NULL
BEGIN
    ALTER TABLE [dbo].[havuztanimlamari] ADD [uzunluk] nvarchar(255) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[havuztanimlamari]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[havuztanimlamari]', N'genislik') IS NULL
BEGIN
    ALTER TABLE [dbo].[havuztanimlamari] ADD [genislik] nvarchar(255) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[havuztanimlamari]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[havuztanimlamari]', N'derinlik') IS NULL
BEGIN
    ALTER TABLE [dbo].[havuztanimlamari] ADD [derinlik] nvarchar(255) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[havuztanimlamari]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[havuztanimlamari]', N'tamKorunakli') IS NULL
BEGIN
    ALTER TABLE [dbo].[havuztanimlamari] ADD [tamKorunakli] nvarchar(255) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[havuztipitanimlari]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[havuztipitanimlari]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[havuztipitanimlari] ADD [id] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[havuztipitanimlari]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[havuztipitanimlari]', N'baslik') IS NULL
BEGIN
    ALTER TABLE [dbo].[havuztipitanimlari] ADD [baslik] nvarchar(100) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[homes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[homes]', N'resim') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [resim] nvarchar(MAX) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[homes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[homes]', N'aktif') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [aktif] bit NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[homes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[homes]', N'hasar') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [hasar] nvarchar(255) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[homes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[homes]', N'kur') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [kur] money NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[homes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[homes]', N'sitemap') IS NULL
BEGIN
    ALTER TABLE [dbo].[homes] ADD [sitemap] bit NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[HomesExtraPayments]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[HomesExtraPayments]', N'id') IS NULL
BEGIN
    ALTER TABLE [dbo].[HomesExtraPayments] ADD [id] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[HomesExtraPayments]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[HomesExtraPayments]', N'amount') IS NULL
BEGIN
    ALTER TABLE [dbo].[HomesExtraPayments] ADD [amount] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[HomesExtraPayments]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[HomesExtraPayments]', N'start_date') IS NULL
BEGIN
    ALTER TABLE [dbo].[HomesExtraPayments] ADD [start_date] date NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[HomesExtraPayments]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[HomesExtraPayments]', N'end_date') IS NULL
BEGIN
    ALTER TABLE [dbo].[HomesExtraPayments] ADD [end_date] date NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[HomesExtraPayments]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[HomesExtraPayments]', N'homesId') IS NULL
BEGIN
    ALTER TABLE [dbo].[HomesExtraPayments] ADD [homesId] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[HomesExtraPayments]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[HomesExtraPayments]', N'title') IS NULL
BEGIN
    ALTER TABLE [dbo].[HomesExtraPayments] ADD [title] nvarchar(150) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[HomesExtraPayments]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[HomesExtraPayments]', N'CurrencyId') IS NULL
BEGIN
    ALTER TABLE [dbo].[HomesExtraPayments] ADD [CurrencyId] tinyint NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[HomesExtraPayments]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[HomesExtraPayments]', N'Type') IS NULL
BEGIN
    ALTER TABLE [dbo].[HomesExtraPayments] ADD [Type] tinyint NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[HomesExtraPayments]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[HomesExtraPayments]', N'IsOptional') IS NULL
BEGIN
    ALTER TABLE [dbo].[HomesExtraPayments] ADD [IsOptional] bit NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[promotionCodes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[promotionCodes]', N'startDate') IS NULL
BEGIN
    ALTER TABLE [dbo].[promotionCodes] ADD [startDate] date NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[promotionCodes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[promotionCodes]', N'endDate') IS NULL
BEGIN
    ALTER TABLE [dbo].[promotionCodes] ADD [endDate] date NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[promotionCodes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[promotionCodes]', N'code') IS NULL
BEGIN
    ALTER TABLE [dbo].[promotionCodes] ADD [code] nvarchar(20) NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[promotionCodes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[promotionCodes]', N'stock') IS NULL
BEGIN
    ALTER TABLE [dbo].[promotionCodes] ADD [stock] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[promotionCodes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[promotionCodes]', N'isPrice') IS NULL
BEGIN
    ALTER TABLE [dbo].[promotionCodes] ADD [isPrice] bit NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[promotionCodes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[promotionCodes]', N'value') IS NULL
BEGIN
    ALTER TABLE [dbo].[promotionCodes] ADD [value] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[sezonlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[sezonlar]', N'gece') IS NULL
BEGIN
    ALTER TABLE [dbo].[sezonlar] ADD [gece] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[sezonlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[sezonlar]', N'temizlikgece') IS NULL
BEGIN
    ALTER TABLE [dbo].[sezonlar] ADD [temizlikgece] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[sezonlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[sezonlar]', N'temizlikFiyat') IS NULL
BEGIN
    ALTER TABLE [dbo].[sezonlar] ADD [temizlikFiyat] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[sezonlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[sezonlar]', N'isitmaFiyat') IS NULL
BEGIN
    ALTER TABLE [dbo].[sezonlar] ADD [isitmaFiyat] int NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[sezonlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[sezonlar]', N'isitmaHizmetDisi') IS NULL
BEGIN
    ALTER TABLE [dbo].[sezonlar] ADD [isitmaHizmetDisi] bit NULL;
END;
GO

IF OBJECT_ID(N'[Finance].[Currency]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[Finance].[Currency]', N'CurrencyCode') IS NULL
BEGIN
    ALTER TABLE [Finance].[Currency] ADD [CurrencyCode] nvarchar(50) NULL;
END;
GO

IF OBJECT_ID(N'[Finance].[Rate]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[Finance].[Rate]', N'RateId') IS NULL
BEGIN
    ALTER TABLE [Finance].[Rate] ADD [RateId] bigint NULL;
END;
GO

IF OBJECT_ID(N'[KiralamaTakvimi].[CalendarHomes]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[KiralamaTakvimi].[CalendarHomes]', N'BookableDirectly') IS NULL
BEGIN
    ALTER TABLE [KiralamaTakvimi].[CalendarHomes] ADD [BookableDirectly] bit NULL;
END;
GO

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[kayitlar]', N'onaylanmaTarihi2') IS NULL
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

IF OBJECT_ID(N'[dbo].[HomesExtraPaymentPrices]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[HomesExtraPaymentPrices]', N'CurrencyId') IS NULL
    ALTER TABLE dbo.HomesExtraPaymentPrices ADD CurrencyId int NULL;
GO

IF OBJECT_ID(N'[dbo].[HomesExtraPaymentPrices]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[HomesExtraPaymentPrices]', N'PriceType') IS NULL
    ALTER TABLE dbo.HomesExtraPaymentPrices ADD PriceType nvarchar(32) NULL;
GO

IF OBJECT_ID(N'[dbo].[HomesExtraPaymentPrices]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[HomesExtraPaymentPrices]', N'Description') IS NULL
    ALTER TABLE dbo.HomesExtraPaymentPrices ADD Description nvarchar(511) NULL;
GO

IF OBJECT_ID(N'[dbo].[HomesExtraPaymentPrices]', N'U') IS NOT NULL
   AND COL_LENGTH(N'[dbo].[HomesExtraPaymentPrices]', N'UpdatedOn') IS NULL
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

IF OBJECT_ID(N'[dbo].[Natsisa_Fn_yenifiyathesapla_tablo]', N'TF') IS NOT NULL
    DROP FUNCTION [dbo].[Natsisa_Fn_yenifiyathesapla_tablo];
GO

CREATE FUNCTION [dbo].[Natsisa_Fn_yenifiyathesapla_tablo](
    @tarih1 DATE,
    @tarih2 DATE,
    @id INT,
    @site INT
)
    RETURNS @result TABLE (
                              ToplamTutar MONEY,
                              IndirimTutari MONEY,
                              SahteIndirimTutari MONEY
                          )
AS
BEGIN
    DECLARE @t1 DATE = @tarih1;
    DECLARE @t2 DATE = @tarih2;
    DECLARE @fyt MONEY = 0;
    DECLARE @sondakika MONEY = 0;
    DECLARE @oran INT = 0;
    DECLARE @fiyatyok INT = 0;
    DECLARE @gece INT = DATEDIFF(DAY, @tarih1, @tarih2);
    DECLARE @indirimToplam MONEY = 0;
    DECLARE @sahteIndirimToplam MONEY = 0;

    WHILE @tarih1 < @tarih2
        BEGIN
            DECLARE @fiyat MONEY = 0;
            DECLARE @indirim DECIMAL = 0;
            DECLARE @IndirimTutari MONEY = 0;
            DECLARE @sahte_oran DECIMAL = 0;

            SELECT @fiyat = fiyat
            FROM sezonlar
            WHERE site = @site
              AND LEN(tarih1) >= 8
              AND islem = 'emlak'
              AND islem_id = @id
              AND (@tarih1 BETWEEN CONVERT(DATE, tarih1, 104) AND CONVERT(DATE, tarih2, 104));

            SELECT @indirim = ISNULL(oran, 0),
                   @sahte_oran = ISNULL(sahte_oran, 0)
            FROM indirimler
            WHERE site = @site
              AND emlak = @id
              AND @tarih1 BETWEEN tarih1 AND tarih2
              AND CONVERT(DATE, GETDATE()) BETWEEN showDate1 AND showDate2;

            SET @fiyat = @fiyat / 7;
            SET @IndirimTutari = (@fiyat / 100) * @indirim;

            SET @indirimToplam += @IndirimTutari;

            IF @sahte_oran > 0
                SET @sahteIndirimToplam += (((@fiyat - @IndirimTutari) * (100 / (100 - @sahte_oran)))) - (@fiyat - @IndirimTutari) + @IndirimTutari;
            ELSE
                SET @sahteIndirimToplam += @IndirimTutari;

            SET @fyt += @fiyat - @IndirimTutari;

            SET @tarih1 = DATEADD(DAY, 1, @tarih1);

            IF (@fiyat = 0)
                SET @fiyatyok = 1;
        END;

    IF @gece = 5
        SELECT @oran = gece_5 FROM genel;
    IF @gece = 4
        SELECT @oran = gece_4 FROM genel;
    IF @gece = 3
        SELECT @oran = gece_3 FROM genel;
    IF @gece = 2
        SELECT @oran = gece_2 FROM genel;
    IF @gece = 1
        SELECT @oran = gece_1 FROM genel;

    SET @fyt += (@fyt / 100) * @oran;

    IF @fiyatyok = 1
        SET @fyt = 0;

    SELECT @sondakika = fiyat
    FROM sonDakika
    WHERE site = @site
      AND islem_id = @id
      AND CONVERT(DATE, tarih1, 104) = @t1
      AND CONVERT(DATE, tarih2, 104) = @t2;

    IF @sondakika != 0
        SET @fyt = @sondakika;

    INSERT INTO @result (ToplamTutar, IndirimTutari, SahteIndirimTutari)
    VALUES (@fyt, @indirimToplam, @sahteIndirimToplam);

    RETURN;
END
GO
