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
     * Adds columns used by the current API SQL queries when they are missing.
     *
     * @Post("collums")
     */ 
    public function collums(): void
    {
        $sqlPath = dirname(__DIR__, 2) . '/setup-collums.sql';
        if (!is_file($sqlPath)) {
            throw new HttpException('Kolon setup SQL dosyasi bulunamadi.', 'SETUP_SQL_NOT_FOUND', 500);
        }
        $poolSqlPath = dirname(__DIR__, 2) . '/setup-havuztanimlamari.sql';
        if (!is_file($poolSqlPath)) {
            throw new HttpException('Havuz setup SQL dosyasi bulunamadi.', 'SETUP_SQL_NOT_FOUND', 500);
        }

        $sql = file_get_contents($sqlPath);
        if (!is_string($sql) || trim($sql) === '') {
            throw new HttpException('Kolon setup SQL dosyasi bos.', 'SETUP_SQL_EMPTY', 500);
        }
        $poolSql = file_get_contents($poolSqlPath);
        if (!is_string($poolSql) || trim($poolSql) === '') {
            throw new HttpException('Havuz setup SQL dosyasi bos.', 'SETUP_SQL_EMPTY', 500);
        }

        try {
            $this->execSqlBatches($sql);
            $this->execSqlBatches($poolSql);
            $this->execSqlBatches($this->extraPaymentsSql());
            $this->assertExtraPaymentTablesExist();
            $this->execSqlBatches($this->reservationGuestsSql());
            $this->assertReservationGuestTablesExist();
            $this->execSqlBatches($this->reservationNotesSql());
            $this->assertReservationNotesTableExists();
            $this->execSqlBatches($this->reservationDocumentsSql());
            $this->assertReservationDocumentsTableExists();
        } catch (\PDOException $e) {
            throw new HttpException('Kolon setup calistirilamadi.', 'SETUP_FAILED', 500, $e);
        }

        $this->response->success([
            'setup' => 'collums',
            'database' => $this->databaseName(),
            'verified_tables' => array_merge(
                $this->extraPaymentTableStatus(),
                $this->reservationGuestTableStatus(),
                $this->reservationNotesTableStatus(),
                $this->reservationDocumentsTableStatus()
            ),
            'included_setups' => [
                'havuztanimlamari',
                'extra-payments',
                'reservation-guests',
                'reservation-notes',
                'reservation-documents',
            ],
            'status' => 'completed',
        ]);
    }

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
        try {
            $this->execSqlBatches($this->extraPaymentsSql());
            $this->assertExtraPaymentTablesExist();
        } catch (\PDOException $e) {
            throw new HttpException('Ekstra ucret tablolari setup calistirilamadi.', 'SETUP_FAILED', 500, $e);
        }

        $this->response->success([
            'setup' => 'extra-payments',
            'database' => $this->databaseName(),
            'verified_tables' => $this->extraPaymentTableStatus(),
            'tables' => [
                'dbo.HomesExtraPaymentTypes',
                'dbo.HomesExtraPaymentPrices',
            ],
            'status' => 'completed',
        ]);
    }

    /**
     * Rezervasyon misafir (Reservation.passengerInformation) ve fatura (Reservation.InvoiceData) tablolarını kurar.
     * Sitenin ön yüzü ("Misafir Bilgilerini Doldur") ve panel bu tablolara yazar. Var olan şema/tabloya dokunmaz.
     *
     * @Post("reservation-guests")
     */
    public function reservationGuests(): void
    {
        try {
            $this->execSqlBatches($this->reservationGuestsSql());
            $this->assertReservationGuestTablesExist();
        } catch (\PDOException $e) {
            throw new HttpException('Misafir/fatura tablolari setup calistirilamadi.', 'SETUP_FAILED', 500, $e);
        }

        $this->response->success([
            'setup' => 'reservation-guests',
            'database' => $this->databaseName(),
            'verified_tables' => $this->reservationGuestTableStatus(),
            'tables' => [
                'Reservation.passengerInformation',
                'Reservation.InvoiceData',
            ],
            'status' => 'completed',
        ]);
    }

    /**
     * Yapı mevcut sitelerdeki tablolarla birebir aynıdır (kolon tipleri, PK ve kayitindex).
     * Sadece eksik olanı oluşturur; mevcut tablo/veri değişmez.
     */
    private function reservationGuestsSql(): string
    {
        return "
IF SCHEMA_ID('Reservation') IS NULL
    EXEC('CREATE SCHEMA Reservation');
GO

IF OBJECT_ID('Reservation.InvoiceData', 'U') IS NULL
BEGIN
    CREATE TABLE Reservation.InvoiceData
    (
        faturatipi   int,
        isim         nvarchar(150),
        tcno         nvarchar(100),
        email        nvarchar(100),
        telefon      nvarchar(50),
        mesaj        nvarchar(max),
        vergidairesi nvarchar(100),
        id           int identity
            constraint Invoice_primary
                primary key,
        kayitlarId   int
    );
END;
GO

IF OBJECT_ID('Reservation.passengerInformation', 'U') IS NULL
BEGIN
    CREATE TABLE Reservation.passengerInformation
    (
        kayitlarId int,
        birthDate  date,
        gender     smallint,
        isTurkish  bit,
        name       nvarchar(200),
        tc         nvarchar(70),
        birthPlace nvarchar(100),
        id         int identity
            constraint key_name
                primary key
    );
END;
GO

-- Tablo önceden varsa eksik kolonları ekler (nullable; mevcut veri değişmez). id (identity/PK) eklenmez.
IF OBJECT_ID(N'[Reservation].[InvoiceData]', N'U') IS NOT NULL
    AND COL_LENGTH(N'[Reservation].[InvoiceData]', N'faturatipi') IS NULL
BEGIN
ALTER TABLE [Reservation].[InvoiceData] ADD [faturatipi] int NULL;
END;
GO

IF OBJECT_ID(N'[Reservation].[InvoiceData]', N'U') IS NOT NULL
    AND COL_LENGTH(N'[Reservation].[InvoiceData]', N'isim') IS NULL
BEGIN
ALTER TABLE [Reservation].[InvoiceData] ADD [isim] nvarchar(150) NULL;
END;
GO

IF OBJECT_ID(N'[Reservation].[InvoiceData]', N'U') IS NOT NULL
    AND COL_LENGTH(N'[Reservation].[InvoiceData]', N'tcno') IS NULL
BEGIN
ALTER TABLE [Reservation].[InvoiceData] ADD [tcno] nvarchar(100) NULL;
END;
GO

IF OBJECT_ID(N'[Reservation].[InvoiceData]', N'U') IS NOT NULL
    AND COL_LENGTH(N'[Reservation].[InvoiceData]', N'email') IS NULL
BEGIN
ALTER TABLE [Reservation].[InvoiceData] ADD [email] nvarchar(100) NULL;
END;
GO

IF OBJECT_ID(N'[Reservation].[InvoiceData]', N'U') IS NOT NULL
    AND COL_LENGTH(N'[Reservation].[InvoiceData]', N'telefon') IS NULL
BEGIN
ALTER TABLE [Reservation].[InvoiceData] ADD [telefon] nvarchar(50) NULL;
END;
GO

IF OBJECT_ID(N'[Reservation].[InvoiceData]', N'U') IS NOT NULL
    AND COL_LENGTH(N'[Reservation].[InvoiceData]', N'mesaj') IS NULL
BEGIN
ALTER TABLE [Reservation].[InvoiceData] ADD [mesaj] nvarchar(max) NULL;
END;
GO

IF OBJECT_ID(N'[Reservation].[InvoiceData]', N'U') IS NOT NULL
    AND COL_LENGTH(N'[Reservation].[InvoiceData]', N'vergidairesi') IS NULL
BEGIN
ALTER TABLE [Reservation].[InvoiceData] ADD [vergidairesi] nvarchar(100) NULL;
END;
GO

IF OBJECT_ID(N'[Reservation].[InvoiceData]', N'U') IS NOT NULL
    AND COL_LENGTH(N'[Reservation].[InvoiceData]', N'kayitlarId') IS NULL
BEGIN
ALTER TABLE [Reservation].[InvoiceData] ADD [kayitlarId] int NULL;
END;
GO

IF OBJECT_ID(N'[Reservation].[passengerInformation]', N'U') IS NOT NULL
    AND COL_LENGTH(N'[Reservation].[passengerInformation]', N'kayitlarId') IS NULL
BEGIN
ALTER TABLE [Reservation].[passengerInformation] ADD [kayitlarId] int NULL;
END;
GO

IF OBJECT_ID(N'[Reservation].[passengerInformation]', N'U') IS NOT NULL
    AND COL_LENGTH(N'[Reservation].[passengerInformation]', N'birthDate') IS NULL
BEGIN
ALTER TABLE [Reservation].[passengerInformation] ADD [birthDate] date NULL;
END;
GO

IF OBJECT_ID(N'[Reservation].[passengerInformation]', N'U') IS NOT NULL
    AND COL_LENGTH(N'[Reservation].[passengerInformation]', N'gender') IS NULL
BEGIN
ALTER TABLE [Reservation].[passengerInformation] ADD [gender] smallint NULL;
END;
GO

IF OBJECT_ID(N'[Reservation].[passengerInformation]', N'U') IS NOT NULL
    AND COL_LENGTH(N'[Reservation].[passengerInformation]', N'isTurkish') IS NULL
BEGIN
ALTER TABLE [Reservation].[passengerInformation] ADD [isTurkish] bit NULL;
END;
GO

IF OBJECT_ID(N'[Reservation].[passengerInformation]', N'U') IS NOT NULL
    AND COL_LENGTH(N'[Reservation].[passengerInformation]', N'name') IS NULL
BEGIN
ALTER TABLE [Reservation].[passengerInformation] ADD [name] nvarchar(200) NULL;
END;
GO

IF OBJECT_ID(N'[Reservation].[passengerInformation]', N'U') IS NOT NULL
    AND COL_LENGTH(N'[Reservation].[passengerInformation]', N'tc') IS NULL
BEGIN
ALTER TABLE [Reservation].[passengerInformation] ADD [tc] nvarchar(70) NULL;
END;
GO

IF OBJECT_ID(N'[Reservation].[passengerInformation]', N'U') IS NOT NULL
    AND COL_LENGTH(N'[Reservation].[passengerInformation]', N'birthPlace') IS NULL
BEGIN
ALTER TABLE [Reservation].[passengerInformation] ADD [birthPlace] nvarchar(100) NULL;
END;
GO

IF OBJECT_ID('Reservation.passengerInformation', 'U') IS NOT NULL
   AND NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE name = 'kayitindex' AND object_id = OBJECT_ID('Reservation.passengerInformation')
   )
    CREATE INDEX kayitindex ON Reservation.passengerInformation (kayitlarId);
GO
";
    }

    /**
     * Rezervasyon notları tablosu (Reservation.ReservationNotes): Natsisa "Rezervasyon Notları", birden fazla not.
     * Panelin tek notu (kayitlar.OzNot) ayrıca gösterilir; yeni notlar OzNot'a yazılmaz. Var olan şema/tabloya dokunmaz.
     *
     * @Post("reservation-notes")
     */
    public function reservationNotes(): void
    {
        try {
            $this->execSqlBatches($this->reservationNotesSql());
            $this->assertReservationNotesTableExists();
        } catch (\PDOException $e) {
            throw new HttpException('Rezervasyon notlari tablosu setup calistirilamadi.', 'SETUP_FAILED', 500, $e);
        }

        $this->response->success([
            'setup' => 'reservation-notes',
            'database' => $this->databaseName(),
            'verified_tables' => $this->reservationNotesTableStatus(),
            'tables' => ['Reservation.ReservationNotes'],
            'status' => 'completed',
        ]);
    }

    /** Silme yumuşak (IsDeleted). Tablo varsa eksik kolonlar NULL olarak eklenir (NoteId hariç). */
    private function reservationNotesSql(): string
    {
        $sql = "
IF SCHEMA_ID('Reservation') IS NULL
    EXEC('CREATE SCHEMA Reservation');
GO

IF OBJECT_ID('Reservation.ReservationNotes', 'U') IS NULL
BEGIN
    CREATE TABLE Reservation.ReservationNotes
    (
        NoteId          int identity
            constraint ReservationNotes_pk primary key,
        ReservationId   int            NOT NULL,
        Note            nvarchar(max)  NOT NULL,
        CreatedByUserId bigint         NULL,
        CreatedByName   nvarchar(100)  NULL,
        CreatedAt       datetime       NOT NULL constraint DF_ReservationNotes_CreatedAt default getdate(),
        UpdatedByUserId bigint         NULL,
        UpdatedByName   nvarchar(100)  NULL,
        UpdatedAt       datetime       NULL,
        IsDeleted       bit            NOT NULL constraint DF_ReservationNotes_IsDeleted default 0
    );
END;
GO
";
        $columns = [
            'ReservationId' => 'int', 'Note' => 'nvarchar(max)', 'CreatedByUserId' => 'bigint',
            'CreatedByName' => 'nvarchar(100)', 'CreatedAt' => 'datetime', 'UpdatedByUserId' => 'bigint',
            'UpdatedByName' => 'nvarchar(100)', 'UpdatedAt' => 'datetime', 'IsDeleted' => 'bit',
        ];
        foreach ($columns as $column => $type) {
            $sql .= "
IF OBJECT_ID(N'[Reservation].[ReservationNotes]', N'U') IS NOT NULL
    AND COL_LENGTH(N'[Reservation].[ReservationNotes]', N'{$column}') IS NULL
BEGIN
ALTER TABLE [Reservation].[ReservationNotes] ADD [{$column}] {$type} NULL;
END;
GO
";
        }
        $sql .= "
IF OBJECT_ID('Reservation.ReservationNotes', 'U') IS NOT NULL
   AND NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE name = 'IX_ReservationNotes_ReservationId' AND object_id = OBJECT_ID('Reservation.ReservationNotes')
   )
    CREATE INDEX IX_ReservationNotes_ReservationId ON Reservation.ReservationNotes (ReservationId);
GO
";

        return $sql;
    }

    /**
     * Rezervasyon belgeleri tablosu (Reservation.ReservationDocuments): Natsisa'nın ürettiği PDF'ler
     * (dosyalar uploads/natsisa_documents altında). Var olan şema/tabloya dokunmaz.
     *
     * @Post("reservation-documents")
     */
    public function reservationDocuments(): void
    {
        try {
            $this->execSqlBatches($this->reservationDocumentsSql());
            $this->assertReservationDocumentsTableExists();
        } catch (\PDOException $e) {
            throw new HttpException('Rezervasyon belgeleri tablosu setup calistirilamadi.', 'SETUP_FAILED', 500, $e);
        }

        $this->response->success([
            'setup' => 'reservation-documents',
            'database' => $this->databaseName(),
            'verified_tables' => $this->reservationDocumentsTableStatus(),
            'tables' => ['Reservation.ReservationDocuments'],
            'status' => 'completed',
        ]);
    }

    /** Silme yumuşak (IsDeleted) + dosya silinir. Tablo varsa eksik kolonlar NULL olarak eklenir (DocumentId hariç). */
    private function reservationDocumentsSql(): string
    {
        $sql = "
IF SCHEMA_ID('Reservation') IS NULL
    EXEC('CREATE SCHEMA Reservation');
GO

IF OBJECT_ID('Reservation.ReservationDocuments', 'U') IS NULL
BEGIN
    CREATE TABLE Reservation.ReservationDocuments
    (
        DocumentId      int identity
            constraint ReservationDocuments_pk primary key,
        ReservationId   int            NOT NULL,
        TemplateId      bigint         NULL,
        TemplateName    nvarchar(150)  NOT NULL,
        FileName        nvarchar(200)  NOT NULL,
        FilePath        nvarchar(300)  NOT NULL,
        FileSize        int            NOT NULL,
        ForOwner        bit            NOT NULL constraint DF_ReservationDocuments_ForOwner default 0,
        CreatedByUserId bigint         NULL,
        CreatedByName   nvarchar(100)  NULL,
        CreatedAt       datetime       NOT NULL constraint DF_ReservationDocuments_CreatedAt default getdate(),
        IsDeleted       bit            NOT NULL constraint DF_ReservationDocuments_IsDeleted default 0
    );
END;
GO
";
        $columns = [
            'ReservationId' => 'int', 'TemplateId' => 'bigint', 'TemplateName' => 'nvarchar(150)',
            'FileName' => 'nvarchar(200)', 'FilePath' => 'nvarchar(300)', 'FileSize' => 'int', 'ForOwner' => 'bit',
            'CreatedByUserId' => 'bigint', 'CreatedByName' => 'nvarchar(100)', 'CreatedAt' => 'datetime', 'IsDeleted' => 'bit',
        ];
        foreach ($columns as $column => $type) {
            $sql .= "
IF OBJECT_ID(N'[Reservation].[ReservationDocuments]', N'U') IS NOT NULL
    AND COL_LENGTH(N'[Reservation].[ReservationDocuments]', N'{$column}') IS NULL
BEGIN
ALTER TABLE [Reservation].[ReservationDocuments] ADD [{$column}] {$type} NULL;
END;
GO
";
        }
        $sql .= "
IF OBJECT_ID('Reservation.ReservationDocuments', 'U') IS NOT NULL
   AND NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE name = 'IX_ReservationDocuments_ReservationId' AND object_id = OBJECT_ID('Reservation.ReservationDocuments')
   )
    CREATE INDEX IX_ReservationDocuments_ReservationId ON Reservation.ReservationDocuments (ReservationId);
GO
";

        return $sql;
    }

    private function assertReservationDocumentsTableExists(): void
    {
        foreach ($this->reservationDocumentsTableStatus() as $table => $exists) {
            if (!$exists) {
                throw new HttpException('Rezervasyon belgeleri setup tamamlanmadi. Tablo/kolon eksik: Reservation.' . $table, 'SETUP_VERIFY_FAILED', 500);
            }
        }
    }

    /**
     * @return array<string,bool>
     */
    private function reservationDocumentsTableStatus(): array
    {
        return [
            'ReservationDocuments' => $this->tableExists('Reservation', 'ReservationDocuments')
                && $this->missingColumns('Reservation', 'ReservationDocuments', [
                    'DocumentId', 'ReservationId', 'TemplateId', 'TemplateName', 'FileName', 'FilePath', 'FileSize',
                    'ForOwner', 'CreatedByUserId', 'CreatedByName', 'CreatedAt', 'IsDeleted',
                ]) === [],
        ];
    }

    private function assertReservationNotesTableExists(): void
    {
        foreach ($this->reservationNotesTableStatus() as $table => $exists) {
            if (!$exists) {
                throw new HttpException('Rezervasyon notlari setup tamamlanmadi. Tablo/kolon eksik: Reservation.' . $table, 'SETUP_VERIFY_FAILED', 500);
            }
        }
    }

    /**
     * @return array<string,bool>
     */
    private function reservationNotesTableStatus(): array
    {
        return [
            'ReservationNotes' => $this->tableExists('Reservation', 'ReservationNotes')
                && $this->missingColumns('Reservation', 'ReservationNotes', [
                    'NoteId', 'ReservationId', 'Note', 'CreatedByUserId', 'CreatedByName', 'CreatedAt',
                    'UpdatedByUserId', 'UpdatedByName', 'UpdatedAt', 'IsDeleted',
                ]) === [],
        ];
    }

    private function assertReservationGuestTablesExist(): void
    {
        foreach ($this->reservationGuestTableStatus() as $table => $exists) {
            if (!$exists) {
                throw new HttpException('Misafir/fatura setup tamamlanmadi. Tablo/kolon eksik: Reservation.' . $table, 'SETUP_VERIFY_FAILED', 500);
            }
        }
    }

    /**
     * @return array<string,bool>
     */
    private function reservationGuestTableStatus(): array
    {
        return [
            'passengerInformation' => $this->tableExists('Reservation', 'passengerInformation')
                && $this->missingColumns('Reservation', 'passengerInformation', ['id', 'kayitlarId', 'birthDate', 'gender', 'isTurkish', 'name', 'tc', 'birthPlace']) === [],
            'InvoiceData' => $this->tableExists('Reservation', 'InvoiceData')
                && $this->missingColumns('Reservation', 'InvoiceData', ['id', 'kayitlarId', 'faturatipi', 'isim', 'tcno', 'email', 'telefon', 'mesaj', 'vergidairesi']) === [],
        ];
    }

    /**
     * @param array<int,string> $expected
     * @return array<int,string>
     */
    private function missingColumns(string $schema, string $table, array $expected): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT LOWER(COLUMN_NAME) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table'
        );
        $stmt->execute([':schema' => $schema, ':table' => $table]);
        $existing = array_flip($stmt->fetchAll(\PDO::FETCH_COLUMN));

        return array_values(array_filter($expected, function (string $column) use ($existing): bool {
            return !isset($existing[strtolower($column)]);
        }));
    }

    private function extraPaymentsSql(): string
    {
        return "
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

IF OBJECT_ID('dbo.HomesExtraPayments', 'U') IS NOT NULL
BEGIN
    INSERT INTO dbo.HomesExtraPaymentTypes (Code, Name, HomeColumnBase, IsIncluded)
    SELECT source.Code, source.Name, N'', CONVERT(bit, 0)
    FROM (
        SELECT DISTINCT
            N'legacy_' + LOWER(CONVERT(varchar(40), HASHBYTES('SHA1', LOWER(LTRIM(RTRIM(CAST(title AS nvarchar(255)))))), 2)) AS Code,
            LTRIM(RTRIM(CAST(title AS nvarchar(255)))) AS Name
        FROM dbo.HomesExtraPayments
        WHERE title IS NOT NULL
          AND LTRIM(RTRIM(CAST(title AS nvarchar(255)))) <> N''
    ) AS source
    WHERE NOT EXISTS (
        SELECT 1
        FROM dbo.HomesExtraPaymentTypes target
        WHERE target.IsDeleted = 0
          AND (target.Name = source.Name OR target.Code = source.Code)
    );
END;
GO

IF OBJECT_ID('dbo.HomesExtraPayments', 'U') IS NOT NULL
BEGIN
    EXEC(N'
CREATE OR ALTER TRIGGER dbo.trg_HomesExtraPayments_InsertType
ON dbo.HomesExtraPayments
AFTER INSERT
AS
BEGIN
    SET NOCOUNT ON;

    INSERT INTO dbo.HomesExtraPaymentTypes (Code, Name, HomeColumnBase, IsIncluded)
    SELECT source.Code, source.Name, N'''', CONVERT(bit, 0)
    FROM (
        SELECT DISTINCT
            N''legacy_'' + LOWER(CONVERT(varchar(40), HASHBYTES(''SHA1'', LOWER(LTRIM(RTRIM(CAST(title AS nvarchar(255)))))), 2)) AS Code,
            LTRIM(RTRIM(CAST(title AS nvarchar(255)))) AS Name
        FROM inserted
        WHERE title IS NOT NULL
          AND LTRIM(RTRIM(CAST(title AS nvarchar(255)))) <> N''''
    ) AS source
    WHERE NOT EXISTS (
        SELECT 1
        FROM dbo.HomesExtraPaymentTypes target
        WHERE target.IsDeleted = 0
          AND (target.Name = source.Name OR target.Code = source.Code)
    );
END;
');
END;
GO
";
    }

    private function execSqlBatches(string $sql): void
    {
        $batches = preg_split('/^\s*GO\s*;?\s*$/mi', $sql);
        if (!is_array($batches)) {
            $batches = [$sql];
        }

        foreach ($batches as $batch) {
            $batch = trim($batch);
            if ($batch === '') {
                continue;
            }

            $this->db->pdo()->exec($batch);
        }
    }

    private function assertExtraPaymentTablesExist(): void
    {
        foreach ($this->extraPaymentTableStatus() as $table => $exists) {
            if (!$exists) {
                throw new HttpException('Ekstra ucret setup tamamlanmadi. Tablo bulunamadi: dbo.' . $table, 'SETUP_VERIFY_FAILED', 500);
            }
        }
    }

    /**
     * @return array<string,bool>
     */
    private function extraPaymentTableStatus(): array
    {
        return [
            'HomesExtraPaymentTypes' => $this->tableExists('dbo', 'HomesExtraPaymentTypes'),
            'HomesExtraPaymentPrices' => $this->tableExists('dbo', 'HomesExtraPaymentPrices'),
        ];
    }

    private function tableExists(string $schema, string $table): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table'
        );
        $stmt->execute([
            ':schema' => $schema,
            ':table' => $table,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function databaseName(): string
    {
        $stmt = $this->db->pdo()->query('SELECT DB_NAME()');
        if ($stmt === false) {
            return '';
        }

        return (string) $stmt->fetchColumn();
    }
}
