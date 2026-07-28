/*
    Creates and backfills pool definitions from dbo.homes.

    Tables:
      dbo.havuztipitanimlari
        id = 1, baslik = Yuzme Havuzu
        id = 2, baslik = Cocuk Havuzu
        id = 3, baslik = Kapali Havuz

      dbo.havuztanimlamari
        homesId = dbo.homes.id
        tipId   = dbo.havuztipitanimlari.id

    The script is safe to run more than once. Existing rows are updated by
    homesId + tipId; missing rows are inserted.
*/

SET XACT_ABORT ON;
BEGIN TRANSACTION;

IF OBJECT_ID('dbo.havuztipitanimlari', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.havuztipitanimlari
    (
        id           int NOT NULL
            CONSTRAINT PK_havuztipitanimlari PRIMARY KEY,
        baslik       nvarchar(100) NOT NULL,
        DateCreated  datetime NOT NULL
            CONSTRAINT DF_havuztipitanimlari_DateCreated DEFAULT GETDATE(),
        DateModified datetime NULL
    );
END;

MERGE dbo.havuztipitanimlari AS target
USING
(
    SELECT 1 AS id, CAST(N'Yüzme Havuzu' AS nvarchar(100)) AS baslik
    UNION ALL SELECT 2, CAST(N'Çocuk Havuzu' AS nvarchar(100))
    UNION ALL SELECT 3, CAST(N'Kapalı Havuz' AS nvarchar(100))
) AS source
    ON target.id = source.id
WHEN MATCHED THEN
    UPDATE SET
        target.baslik = source.baslik,
        target.DateModified = GETDATE()
WHEN NOT MATCHED BY TARGET THEN
    INSERT (id, baslik)
    VALUES (source.id, source.baslik);

IF OBJECT_ID('dbo.havuztanimlamari', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.havuztanimlamari
    (
        id               int IDENTITY(1,1) NOT NULL
            CONSTRAINT PK_havuztanimlamari PRIMARY KEY,
        homesId          int NOT NULL,
        tipId            int NOT NULL,
        deger            nvarchar(255) NULL,
        havuzTipiId      int NULL,
        uzunluk          nvarchar(255) NULL,
        genislik         nvarchar(255) NULL,
        derinlik         nvarchar(255) NULL,
        tamKorunakli     nvarchar(255) NULL,
        DateCreated      datetime NOT NULL
            CONSTRAINT DF_havuztanimlamari_DateCreated DEFAULT GETDATE(),
        DateModified     datetime NULL
    );
END;

IF OBJECT_ID('dbo.FK_havuztanimlamari_havuzozelliktipitanimlari', 'F') IS NOT NULL
BEGIN
    ALTER TABLE dbo.havuztanimlamari
    DROP CONSTRAINT FK_havuztanimlamari_havuzozelliktipitanimlari;
END;

IF OBJECT_ID('dbo.havuzozelliktipitanimlari', 'U') IS NOT NULL
BEGIN
    DROP TABLE dbo.havuzozelliktipitanimlari;
END;

IF COL_LENGTH('dbo.havuztanimlamari', 'tipId') IS NULL
BEGIN
    ALTER TABLE dbo.havuztanimlamari ADD tipId int NULL;
END;

IF COL_LENGTH('dbo.havuztanimlamari', 'tip') IS NOT NULL
BEGIN
    EXEC('UPDATE dbo.havuztanimlamari SET tipId = CONVERT(int, tip) WHERE tipId IS NULL');
END;

IF COL_LENGTH('dbo.havuztanimlamari', 'tipBaslik') IS NOT NULL
BEGIN
    ALTER TABLE dbo.havuztanimlamari DROP COLUMN tipBaslik;
END;

IF COL_LENGTH('dbo.havuztanimlamari', 'tipAdi') IS NOT NULL
BEGIN
    ALTER TABLE dbo.havuztanimlamari DROP COLUMN tipAdi;
END;

IF COL_LENGTH('dbo.havuztanimlamari', 'tip') IS NOT NULL
BEGIN
    ALTER TABLE dbo.havuztanimlamari DROP COLUMN tip;
END;

IF EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = 'UX_havuztanimlamari_homesId_tip'
      AND object_id = OBJECT_ID('dbo.havuztanimlamari')
)
BEGIN
    DROP INDEX UX_havuztanimlamari_homesId_tip ON dbo.havuztanimlamari;
END;

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = 'UX_havuztanimlamari_homesId_tipId'
      AND object_id = OBJECT_ID('dbo.havuztanimlamari')
)
BEGIN
    CREATE UNIQUE INDEX UX_havuztanimlamari_homesId_tipId
        ON dbo.havuztanimlamari (homesId, tipId);
END;

IF OBJECT_ID('dbo.FK_havuztanimlamari_havuztipitanimlari', 'F') IS NULL
BEGIN
    ALTER TABLE dbo.havuztanimlamari
    ADD CONSTRAINT FK_havuztanimlamari_havuztipitanimlari
        FOREIGN KEY (tipId) REFERENCES dbo.havuztipitanimlari(id);
END;

;WITH PoolSource AS
(
    SELECT
        h.id AS homesId,
        1 AS tipId,
        NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), h.yuzme_havuzu))), '') AS deger,
        h.yuzme_havuzu_tipi AS havuzTipiId,
        NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), h.yuzme_havuzu_uzunluk))), '') AS uzunluk,
        NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), h.yuzme_havuzu_genislik))), '') AS genislik,
        NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), h.yuzme_havuzu_derinlik))), '') AS derinlik,
        NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), h.tam_korunakli_havuz))), '') AS tamKorunakli
    FROM dbo.homes h
    WHERE NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), h.yuzme_havuzu))), '') IS NOT NULL
       OR h.yuzme_havuzu_tipi IS NOT NULL
       OR NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), h.yuzme_havuzu_uzunluk))), '') IS NOT NULL
       OR NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), h.yuzme_havuzu_genislik))), '') IS NOT NULL
       OR NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), h.yuzme_havuzu_derinlik))), '') IS NOT NULL
       OR NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), h.tam_korunakli_havuz))), '') IS NOT NULL

    UNION ALL

    SELECT
        h.id AS homesId,
        2 AS tipId,
        NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), h.cocuk_havuzu))), '') AS deger,
        NULL AS havuzTipiId,
        NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), h.cocuk_havuzu_uzunluk))), '') AS uzunluk,
        NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), h.cocuk_havuzu_genislik))), '') AS genislik,
        NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), h.cocuk_havuzu_derinlik))), '') AS derinlik,
        NULL AS tamKorunakli
    FROM dbo.homes h
    WHERE NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), h.cocuk_havuzu))), '') IS NOT NULL
       OR NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), h.cocuk_havuzu_uzunluk))), '') IS NOT NULL
       OR NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), h.cocuk_havuzu_genislik))), '') IS NOT NULL
       OR NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), h.cocuk_havuzu_derinlik))), '') IS NOT NULL

    UNION ALL

    SELECT
        h.id AS homesId,
        3 AS tipId,
        NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), h.kapali_havuz))), '') AS deger,
        NULL AS havuzTipiId,
        NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), h.kapali_havuz_uzunluk))), '') AS uzunluk,
        NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), h.kapali_havuz_genislik))), '') AS genislik,
        NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), h.kapali_havuz_derinlik))), '') AS derinlik,
        NULL AS tamKorunakli
    FROM dbo.homes h
    WHERE NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), h.kapali_havuz))), '') IS NOT NULL
       OR NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), h.kapali_havuz_uzunluk))), '') IS NOT NULL
       OR NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), h.kapali_havuz_genislik))), '') IS NOT NULL
       OR NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255), h.kapali_havuz_derinlik))), '') IS NOT NULL
)
MERGE dbo.havuztanimlamari AS target
USING PoolSource AS source
    ON target.homesId = source.homesId
   AND target.tipId = source.tipId
WHEN MATCHED THEN
    UPDATE SET
        target.deger = source.deger,
        target.havuzTipiId = source.havuzTipiId,
        target.uzunluk = source.uzunluk,
        target.genislik = source.genislik,
        target.derinlik = source.derinlik,
        target.tamKorunakli = source.tamKorunakli,
        target.DateModified = GETDATE()
WHEN NOT MATCHED BY TARGET THEN
    INSERT
    (
        homesId,
        tipId,
        deger,
        havuzTipiId,
        uzunluk,
        genislik,
        derinlik,
        tamKorunakli
    )
    VALUES
    (
        source.homesId,
        source.tipId,
        source.deger,
        source.havuzTipiId,
        source.uzunluk,
        source.genislik,
        source.derinlik,
        source.tamKorunakli
    );

COMMIT TRANSACTION;
