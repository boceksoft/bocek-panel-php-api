/* =====================================================================================
   kayitlar_odeme TOPLU YENIDEN HESAPLAMA  —  SSMS'te ELLE calistirilir (Setup Calistir'a dahil degil)
   -------------------------------------------------------------------------------------
   Mantik tutarAktar trigger'i ile birebir ayni (setup-collums.sql, 02.11.2026):
     - indirim kim karsiladi: ayrik tutarlar (sitesahibi_indirimi / evsahibi_indirimi), toplamlari gercek indirime
       esitse (yuvarlama payi 2); degilse kayitlar.indirimevsahibi bayragi
     - kar (kazanc_tutari): kayitlar.kar > evsahibi_hakedis (firma hakedisi) > satis x komisyon% - site indirimi
     - evsahibi_kazanc_tutari = toplam_tutar - kar   (eskiden satis - kar; indirim kadar fazla cikiyordu)
     - evsahibi_kalan = on_odeme - kar
   Sitede olmayan kolonlar (orn. vv'de sitesahibi_indirimi) NULL sayilir; script her sitede calisir.

   KULLANIM
     1) Once @uygula = 0 ile calistir: hicbir sey yazmaz; kac kaydin degisecegini ve ornek farklari gosterir.
     2) Yedek al (asagidaki satir), sonra @uygula = 1 ile calistir. Tek transaction; hata olursa geri alinir.
        SELECT * INTO dbo.kayitlar_odeme_yedek_20261102 FROM dbo.kayitlar_odeme;
     Istege bagli: @sadeceKayitId ile tek rezervasyon denenebilir.
   ===================================================================================== */
SET NOCOUNT ON;
SET XACT_ABORT ON;

DECLARE @uygula bit = 0;              -- 0: sadece onizleme, 1: yaz
DECLARE @sadeceKayitId int = NULL;    -- NULL: hepsi

IF OBJECT_ID(N'[dbo].[kayitlar]', N'U') IS NULL OR OBJECT_ID(N'[dbo].[kayitlar_odeme]', N'U') IS NULL
BEGIN
    RAISERROR(N'kayitlar ya da kayitlar_odeme tablosu yok.', 16, 1);
    RETURN;
END;
IF OBJECT_ID(N'[dbo].[fnTemizle]', N'FN') IS NULL
BEGIN
    RAISERROR(N'dbo.fnTemizle yok: once Config > Setup Calistir.', 16, 1);
    RETURN;
END;

IF OBJECT_ID(N'tempdb..#src') IS NOT NULL DROP TABLE #src;
CREATE TABLE #src (
    id int NOT NULL PRIMARY KEY,
    satis nvarchar(100) COLLATE DATABASE_DEFAULT NULL,
    flag bit NULL,
    toplam nvarchar(100) COLLATE DATABASE_DEFAULT NULL,
    onodeme nvarchar(100) COLLATE DATABASE_DEFAULT NULL,
    kalan nvarchar(100) COLLATE DATABASE_DEFAULT NULL,
    temizlik nvarchar(100) COLLATE DATABASE_DEFAULT NULL,
    elektrik nvarchar(100) COLLATE DATABASE_DEFAULT NULL,
    tur int NULL,
    doviz nvarchar(10) COLLATE DATABASE_DEFAULT NULL,
    kur money NULL,
    kazancorani int NULL,
    kar nvarchar(100) COLLATE DATABASE_DEFAULT NULL,
    splitSite nvarchar(100) COLLATE DATABASE_DEFAULT NULL,
    splitOwner nvarchar(100) COLLATE DATABASE_DEFAULT NULL,
    splitHakedis nvarchar(100) COLLATE DATABASE_DEFAULT NULL
);

/* 1) Kaynak: trigger'daki select ile ayni; olmayan kolonlar NULL */
DECLARE @sql nvarchar(max) = N'
INSERT INTO #src (id, satis, flag, toplam, onodeme, kalan, temizlik, elektrik, tur, doviz, kur, kazancorani, kar,
                  splitSite, splitOwner, splitHakedis)
SELECT
    k.id,
    dbo.fnTemizle((case when isnull(nullif(ltrim(rtrim(k.eskifiyat)),''''),''0'')=''0'' then k.toplam_tutar else k.eskifiyat end)),
    isnull({{FLAG}},0),
    dbo.fnTemizle(k.toplam_tutar),
    dbo.fnTemizle(k.on_odeme),
    dbo.fnTemizle(k.kalan),
    dbo.fnTemizle(k.temizlik),
    dbo.fnTemizle(k.elektrik),
    k.tur,
    isnull(k.doviz,''tl''),
    (case when isnull(k.doviz,''tl'')=''tl'' then 1 else isnull(TRY_CONVERT(money, k.kur),1) end),
    isnull(TRY_CONVERT(int, k.kazancorani),0),
    dbo.fnTemizle(k.kar),
    {{SPLIT_SITE}},
    {{SPLIT_OWNER}},
    {{SPLIT_HAKEDIS}}
FROM dbo.kayitlar k
WHERE (@sadeceKayitId IS NULL OR k.id = @sadeceKayitId);';

SET @sql = REPLACE(@sql, N'{{FLAG}}', CASE WHEN COL_LENGTH(N'[dbo].[kayitlar]', N'indirimevsahibi') IS NOT NULL
    THEN N'k.indirimevsahibi' ELSE N'NULL' END);
SET @sql = REPLACE(@sql, N'{{SPLIT_SITE}}', CASE WHEN COL_LENGTH(N'[dbo].[kayitlar]', N'sitesahibi_indirimi') IS NOT NULL
    THEN N'dbo.fnTemizle(CONVERT(nvarchar(50), k.sitesahibi_indirimi))' ELSE N'NULL' END);
SET @sql = REPLACE(@sql, N'{{SPLIT_OWNER}}', CASE WHEN COL_LENGTH(N'[dbo].[kayitlar]', N'evsahibi_indirimi') IS NOT NULL
    THEN N'dbo.fnTemizle(CONVERT(nvarchar(50), k.evsahibi_indirimi))' ELSE N'NULL' END);
SET @sql = REPLACE(@sql, N'{{SPLIT_HAKEDIS}}', CASE WHEN COL_LENGTH(N'[dbo].[kayitlar]', N'evsahibi_hakedis') IS NOT NULL
    THEN N'dbo.fnTemizle(CONVERT(nvarchar(50), k.evsahibi_hakedis))' ELSE N'NULL' END);

EXEC sp_executesql @sql, N'@sadeceKayitId int', @sadeceKayitId = @sadeceKayitId;

/* 2) Pesin odeme (tur 2): on odeme = toplam, kalan = 0 (trigger ile ayni) */
UPDATE #src SET onodeme = toplam, kalan = N'0' WHERE tur = 2;

/* 3) Sayi temizleme (trigger ile ayni kural):
      '.' ile bolunen parcalarin en kisasi 2'den uzunsa noktalar binlik ayiracidir -> silinir;
      ',' ile bolunen parcalarin en kisasi 2'den uzun degilse virguller silinir. */
DECLARE @col sysname;
DECLARE cols CURSOR LOCAL FAST_FORWARD FOR
    SELECT v FROM (VALUES (N'satis'), (N'toplam'), (N'onodeme'), (N'kalan'), (N'temizlik'), (N'elektrik'),
                          (N'splitSite'), (N'splitOwner'), (N'splitHakedis')) t(v);
OPEN cols;
FETCH NEXT FROM cols INTO @col;
WHILE @@FETCH_STATUS = 0
BEGIN
    SET @sql = N'
        UPDATE s SET ' + QUOTENAME(@col) + N' = CASE
            WHEN (SELECT MIN(LEN(value)) FROM string_split(s.' + QUOTENAME(@col) + N', ''.'')) > 2
                THEN REPLACE(s.' + QUOTENAME(@col) + N', ''.'', '''')
            ELSE s.' + QUOTENAME(@col) + N' END
        FROM #src s WHERE s.' + QUOTENAME(@col) + N' IS NOT NULL;
        UPDATE s SET ' + QUOTENAME(@col) + N' = CASE
            WHEN (SELECT MIN(LEN(value)) FROM string_split(s.' + QUOTENAME(@col) + N', '','')) > 2
                THEN s.' + QUOTENAME(@col) + N'
            ELSE REPLACE(s.' + QUOTENAME(@col) + N', '','', '''') END
        FROM #src s WHERE s.' + QUOTENAME(@col) + N' IS NOT NULL;';
    EXEC sp_executesql @sql;
    FETCH NEXT FROM cols INTO @col;
END;
CLOSE cols;
DEALLOCATE cols;

/* 4) Hesap */
IF OBJECT_ID(N'tempdb..#calc') IS NOT NULL DROP TABLE #calc;
SELECT
    s.id,
    m.satis_m AS satis_bedeli,
    sh.indirim_site,
    sh.indirim_evsahibi,
    m.toplam_m AS toplam_tutar,
    m.onodeme_m AS on_odeme,
    m.kalan_m AS kalan,
    m.ekstra_m AS ekstra_ucretler,
    kz.kazanc AS kazanc_tutari,
    m.toplam_m - kz.kazanc AS evsahibi_kazanc_tutari,
    m.onodeme_m - kz.kazanc AS evsahibi_kalan,
    s.doviz,
    s.kur
INTO #calc
FROM #src s
CROSS APPLY (SELECT
    satis_m   = isnull(TRY_CONVERT(money, replace(s.satis, ',', '.')), 0),
    toplam_m  = isnull(TRY_CONVERT(money, replace(s.toplam, ',', '.')), 0),
    onodeme_m = isnull(TRY_CONVERT(money, replace(s.onodeme, ',', '.')), 0),
    kalan_m   = isnull(TRY_CONVERT(money, replace(s.kalan, ',', '.')), 0),
    ekstra_m  = (case when isnumeric(isnull(replace(s.temizlik, ',', '.'), '0')) = 1 then isnull(TRY_CONVERT(money, replace(s.temizlik, ',', '.')), 0) else 0 end)
              + (case when isnumeric(isnull(replace(s.elektrik, ',', '.'), '0')) = 1 then isnull(TRY_CONVERT(money, replace(s.elektrik, ',', '.')), 0) else 0 end),
    spSite    = isnull(TRY_CONVERT(money, replace(s.splitSite, ',', '.')), 0),
    spOwner   = isnull(TRY_CONVERT(money, replace(s.splitOwner, ',', '.')), 0),
    spHakedis = isnull(TRY_CONVERT(money, replace(s.splitHakedis, ',', '.')), 0)
) m
CROSS APPLY (SELECT ind0 = m.satis_m - m.toplam_m) i0
CROSS APPLY (SELECT ind = case when i0.ind0 = -1 or i0.ind0 = 1 then 0 else i0.ind0 end) i
CROSS APPLY (SELECT
    indirim_site = case
        when (m.spSite + m.spOwner) > 0 and abs((m.spSite + m.spOwner) - i.ind) <= 2 then m.spSite
        when s.flag = 1 then 0
        else i.ind end,
    indirim_evsahibi = case
        when (m.spSite + m.spOwner) > 0 and abs((m.spSite + m.spOwner) - i.ind) <= 2 then m.spOwner
        when s.flag = 1 then i.ind
        else 0 end
) sh
CROSS APPLY (SELECT kazanc = case
    when not isnull(s.kar, '') = '' then isnull(TRY_CONVERT(money, s.kar), 0)
    when m.spHakedis > 0 then m.spHakedis
    else m.satis_m / 100 * s.kazancorani - sh.indirim_site end
) kz;

/* 5) Onizleme: degisecek kayitlar */
IF OBJECT_ID(N'tempdb..#fark') IS NOT NULL DROP TABLE #fark;
SELECT c.id,
       CASE WHEN ko.kayitid IS NULL THEN 1 ELSE 0 END AS eklenecek,
       ko.indirim_site AS eski_indirim_site, c.indirim_site AS yeni_indirim_site,
       ko.indirim_evsahibi AS eski_indirim_evsahibi, c.indirim_evsahibi AS yeni_indirim_evsahibi,
       ko.kazanc_tutari AS eski_kazanc, c.kazanc_tutari AS yeni_kazanc,
       ko.evsahibi_kazanc_tutari AS eski_evsahibi_kazanc, c.evsahibi_kazanc_tutari AS yeni_evsahibi_kazanc,
       ko.evsahibi_kalan AS eski_evsahibi_kalan, c.evsahibi_kalan AS yeni_evsahibi_kalan
INTO #fark
FROM #calc c
LEFT JOIN dbo.kayitlar_odeme ko ON ko.kayitid = c.id
WHERE ko.kayitid IS NULL
   OR isnull(ko.satis_bedeli, -1) <> c.satis_bedeli
   OR isnull(ko.indirim_site, -1) <> c.indirim_site
   OR isnull(ko.indirim_evsahibi, -1) <> c.indirim_evsahibi
   OR isnull(ko.toplam_tutar, -1) <> c.toplam_tutar
   OR isnull(ko.on_odeme, -1) <> c.on_odeme
   OR isnull(ko.kalan, -1) <> c.kalan
   OR isnull(ko.ekstra_ucretler, -1) <> c.ekstra_ucretler
   OR isnull(ko.kazanc_tutari, -1) <> c.kazanc_tutari
   OR isnull(ko.evsahibi_kazanc_tutari, -1) <> c.evsahibi_kazanc_tutari
   OR isnull(ko.evsahibi_kalan, -1) <> c.evsahibi_kalan
   /* metin karsilastirmasi ikili (varbinary): tempdb / veritabani harf duzeni (collation) farkindan etkilenmez */
   OR CONVERT(varbinary(100), isnull(CONVERT(nvarchar(10), ko.doviz), N'')) <> CONVERT(varbinary(100), isnull(CONVERT(nvarchar(10), c.doviz), N''))
   OR isnull(ko.kur, -1) <> isnull(c.kur, -1);

SELECT (SELECT COUNT(*) FROM #calc) AS toplam_rezervasyon,
       (SELECT COUNT(*) FROM #fark WHERE eklenecek = 1) AS eklenecek,
       (SELECT COUNT(*) FROM #fark WHERE eklenecek = 0) AS guncellenecek,
       (SELECT COUNT(*) FROM #fark WHERE isnull(eski_kazanc, -1) <> yeni_kazanc) AS kari_degisen;

SELECT TOP 200 * FROM #fark ORDER BY id DESC;

/* 6) Uygula */
IF @uygula = 1
BEGIN
    BEGIN TRANSACTION;

    UPDATE ko SET
        satis_bedeli = c.satis_bedeli,
        indirim_site = c.indirim_site,
        indirim_evsahibi = c.indirim_evsahibi,
        toplam_tutar = c.toplam_tutar,
        on_odeme = c.on_odeme,
        kalan = c.kalan,
        ekstra_ucretler = c.ekstra_ucretler,
        kazanc_tutari = c.kazanc_tutari,
        evsahibi_kazanc_tutari = c.evsahibi_kazanc_tutari,
        evsahibi_kalan = c.evsahibi_kalan,
        doviz = c.doviz,
        kur = c.kur,
        modifedDate = getdate()
    FROM dbo.kayitlar_odeme ko
    INNER JOIN #calc c ON c.id = ko.kayitid
    INNER JOIN #fark f ON f.id = c.id;

    DECLARE @guncellenen int = @@ROWCOUNT;

    INSERT INTO dbo.kayitlar_odeme (kayitid, satis_bedeli, indirim_site, indirim_evsahibi, toplam_tutar, on_odeme, kalan,
                                    ekstra_ucretler, kazanc_tutari, evsahibi_kazanc_tutari, evsahibi_kalan, doviz, kur, createdDate)
    SELECT c.id, c.satis_bedeli, c.indirim_site, c.indirim_evsahibi, c.toplam_tutar, c.on_odeme, c.kalan,
           c.ekstra_ucretler, c.kazanc_tutari, c.evsahibi_kazanc_tutari, c.evsahibi_kalan, c.doviz, c.kur, getdate()
    FROM #calc c
    INNER JOIN #fark f ON f.id = c.id AND f.eklenecek = 1;

    DECLARE @eklenen int = @@ROWCOUNT;

    COMMIT TRANSACTION;

    SELECT @guncellenen AS guncellenen, @eklenen AS eklenen;
END
ELSE
BEGIN
    PRINT N'Onizleme: hicbir sey yazilmadi. Uygulamak icin yedek alip @uygula = 1 yap.';
END;
