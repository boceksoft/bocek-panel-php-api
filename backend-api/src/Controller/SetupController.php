<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;

/*
 * One-off database setup endpoints.
 * These endpoints are protected by the normal Bearer token middleware.
 */
final class SetupController extends Controller
{
    /**
     * Creates/backfills dbo.havuztanimlamari from dbo.homes pool columns.
     *
     * @Post("havuztanimlamari")
     */
    public function havuzTanimlamari(): void
    {
        $sqlPath = dirname(__DIR__, 2) . '/setup-havuztanimlamari.sql';
        if (!is_file($sqlPath)) {
            throw new HttpException('Setup SQL dosyasi bulunamadi.', 'SETUP_SQL_NOT_FOUND', 500);
        }

        $sql = file_get_contents($sqlPath);
        if (!is_string($sql) || trim($sql) === '') {
            throw new HttpException('Setup SQL dosyasi bos.', 'SETUP_SQL_EMPTY', 500);
        }

        try {
            $this->db->pdo()->exec($sql);
        } catch (\PDOException $e) {
            throw new HttpException('Havuz tanimlamalari setup calistirilamadi.', 'SETUP_FAILED', 500, $e);
        }

        $this->response->success([
            'setup' => 'havuztanimlamari',
            'table' => 'dbo.havuztanimlamari',
            'status' => 'completed',
        ]);
    }

    /**
     * Creates homes extra payment type/price tables and seeds default types.
     *
     * @Post("extra-payments")
     */
    public function extraPayments(): void
    {
        $sql = "
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

    CREATE UNIQUE INDEX UX_HomesExtraPaymentTypes_Code
        ON dbo.HomesExtraPaymentTypes (Code)
        WHERE IsDeleted = 0;
END;

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
        CONSTRAINT FK_HomesExtraPaymentPrices_Homes FOREIGN KEY (HomesId)
            REFERENCES dbo.homes (id),
        CONSTRAINT FK_HomesExtraPaymentPrices_Sezonlar FOREIGN KEY (SeasonId)
            REFERENCES dbo.sezonlar (id),
        CONSTRAINT FK_HomesExtraPaymentPrices_Types FOREIGN KEY (ExtraPaymentTypeId)
            REFERENCES dbo.HomesExtraPaymentTypes (ExtraPaymentTypeId)
    );

    CREATE INDEX IX_HomesExtraPaymentPrices_HomesId ON dbo.HomesExtraPaymentPrices (HomesId);
    CREATE INDEX IX_HomesExtraPaymentPrices_SeasonId ON dbo.HomesExtraPaymentPrices (SeasonId);
    CREATE INDEX IX_HomesExtraPaymentPrices_TypeId ON dbo.HomesExtraPaymentPrices (ExtraPaymentTypeId);
    CREATE INDEX IX_HomesExtraPaymentPrices_DateRange ON dbo.HomesExtraPaymentPrices (StartDate, EndDate);
END;

IF COL_LENGTH('dbo.HomesExtraPaymentPrices', 'CurrencyId') IS NULL
    ALTER TABLE dbo.HomesExtraPaymentPrices ADD CurrencyId int NULL;

IF COL_LENGTH('dbo.HomesExtraPaymentPrices', 'PriceType') IS NULL
    ALTER TABLE dbo.HomesExtraPaymentPrices ADD PriceType nvarchar(32) NULL;

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
";

        try {
            $this->db->pdo()->exec($sql);
        } catch (\PDOException $e) {
            throw new HttpException('Ekstra ucret tablolari setup calistirilamadi.', 'SETUP_FAILED', 500, $e);
        }

        $this->response->success([
            'setup' => 'extra-payments',
            'tables' => [
                'dbo.HomesExtraPaymentTypes',
                'dbo.HomesExtraPaymentPrices',
            ],
            'status' => 'completed',
        ]);
    }
}
