<?php

declare(strict_types=1);

namespace App\Tests\Unit\Schema;

use App\Config\Config;
use App\Config\ConfigException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Schema smoke test: applies all six migrations (001-006) against a real,
 * disposable MariaDB test database and asserts the tables, constraints,
 * and the `license_events.webhook_event_id` generated column behave as
 * designed.
 *
 * This intentionally does NOT use SQLite. The schema relies on
 * MariaDB-specific features that SQLite cannot faithfully emulate:
 *   - a JSON-path `GENERATED ALWAYS AS (...) STORED` column
 *     (`license_events.webhook_event_id`), whose extraction semantics
 *     (`JSON_UNQUOTE(payload->'$.webhook_event_id')`) are MariaDB-specific
 *   - table-level `CHECK` constraints (`chk_activation_limit_positive`,
 *     `chk_price_nonneg`), whose enforcement semantics differ from
 *     SQLite's
 *
 * Connection details (host/user/pass) come from `Config::load()`, but the
 * *database name* used is deliberately NOT the real configured `DB_NAME`
 * — it is a dedicated, disposable test database (`TEST_DB_NAME` env var,
 * defaulting to `license_server_test`), so this test can never run
 * destructive schema operations against the production database.
 *
 * If no MariaDB connection can be established (expected in this local
 * environment, per the project plan this is meant to run for real on the
 * target OVH server), the test is skipped rather than failed.
 *
 * _Requirements: 1.1, 1.3, 1.8, 1.10_
 *
 * @covers \App\Config\Config
 */
final class SchemaMigrationTest extends TestCase
{
    /**
     * Tables in FK-safe drop order: children (tables with a FOREIGN KEY
     * referencing `licenses`) before parents, so DROP TABLE never fails
     * on a dangling foreign key.
     *
     * @var list<string>
     */
    private const TABLES_FK_SAFE_DROP_ORDER = [
        'license_activations',
        'license_events',
        'rate_limit_store',
        'admin_login_attempts',
        'admin_users',
        'licenses',
    ];

    private const ALL_SIX_TABLES = [
        'licenses',
        'license_activations',
        'license_events',
        'admin_users',
        'admin_login_attempts',
        'rate_limit_store',
    ];

    private ?PDO $pdo = null;

    private string $testDbName = 'license_server_test';

    protected function setUp(): void
    {
        parent::setUp();

        $host = null;
        $user = null;
        $pass = null;

        try {
            $config = Config::load();
            $host = $config->get('DB_HOST');
            $user = $config->get('DB_USER');
            $pass = $config->get('DB_PASS');
        } catch (ConfigException $e) {
            $this->markTestSkipped(
                "Skipping MariaDB schema smoke test: could not load application configuration "
                . "(Config::load() failed on missing/empty key '{$e->missingKey()}'). This test "
                . "needs DB_HOST/DB_USER/DB_PASS to be configured (see .env.example)."
            );
            return;
        }

        $envTestDbName = getenv('TEST_DB_NAME');
        if ($envTestDbName !== false && $envTestDbName !== '') {
            $this->testDbName = $envTestDbName;
        }

        // Step 1: connect without selecting a database, so we can
        // CREATE DATABASE IF NOT EXISTS for the disposable test database.
        try {
            $serverPdo = new PDO(
                "mysql:host={$host};charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            $this->markTestSkipped(
                "Skipping MariaDB schema smoke test: could not connect to MariaDB at "
                . "host '{$host}' as user '{$user}' ({$e->getMessage()}). This environment likely "
                . "has no local MariaDB available; this test is meant to run for real against a "
                . "MariaDB instance (e.g. on the target OVH server)."
            );
            return;
        }

        try {
            $serverPdo->exec(
                "CREATE DATABASE IF NOT EXISTS `{$this->testDbName}` "
                . "DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            );
        } catch (PDOException $e) {
            $this->markTestSkipped(
                "Skipping MariaDB schema smoke test: connected to MariaDB at host '{$host}' as user "
                . "'{$user}', but could not CREATE DATABASE IF NOT EXISTS `{$this->testDbName}` "
                . "({$e->getMessage()})."
            );
            return;
        }

        // Step 2: connect to the disposable test database itself.
        try {
            $this->pdo = new PDO(
                "mysql:host={$host};dbname={$this->testDbName};charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            $this->markTestSkipped(
                "Skipping MariaDB schema smoke test: could not connect to test database "
                . "'{$this->testDbName}' at host '{$host}' as user '{$user}' ({$e->getMessage()})."
            );
            return;
        }

        $this->dropAllTables();
        $this->runAllMigrations();
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->dropAllTables();
        }

        parent::tearDown();
    }

    /**
     * Drops all six tables (if they exist) in FK-safe order, so repeated
     * runs of this test stay hermetic. Deliberately does NOT DROP
     * DATABASE — the operator may want to inspect the disposable test
     * database afterwards, and dropping the database would require a
     * broader privilege than schema tests should need.
     */
    private function dropAllTables(): void
    {
        foreach (self::TABLES_FK_SAFE_DROP_ORDER as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    /**
     * Applies migrations 001 through 006, in filename order, against the
     * connected disposable test database.
     */
    private function runAllMigrations(): void
    {
        $migrationsDir = dirname(__DIR__, 3) . '/migrations';

        $files = glob($migrationsDir . '/*.sql');
        self::assertNotFalse($files, "Could not scan migrations directory {$migrationsDir}.");
        sort($files, SORT_STRING);

        self::assertNotEmpty($files, "No .sql migration files found in {$migrationsDir}.");

        foreach ($files as $filePath) {
            $sql = file_get_contents($filePath);
            self::assertNotFalse($sql, "Could not read migration file {$filePath}.");
            $this->pdo->exec($sql);
        }
    }

    /**
     * Inserts a valid `licenses` row (with optional field overrides) and
     * returns its generated id. Used as a fixture by the constraint tests
     * below.
     *
     * @param array<string, mixed> $overrides
     */
    private function insertLicense(array $overrides = []): int
    {
        $fields = array_merge(
            [
                'license_key' => 'SERB-' . bin2hex(random_bytes(10)),
                'email' => 'customer@example.com',
                'customer_name' => 'Test Customer',
                'product' => 'test-product',
                'tier' => 'annual',
                'purchased_at' => '2024-01-01 00:00:00',
                'expires_at' => '2025-01-01 00:00:00',
                'activation_limit' => 3,
                'price_amount' => 99.99,
                'currency' => 'INR',
            ],
            $overrides
        );

        $columns = array_keys($fields);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO licenses (%s) VALUES (%s)',
            implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns)),
            implode(', ', $placeholders)
        );

        $statement = $this->pdo->prepare($sql);
        $statement->execute($fields);

        return (int) $this->pdo->lastInsertId();
    }

    public function testAllSixTablesExist(): void
    {
        $query = $this->pdo->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema'
        );
        $query->execute(['schema' => $this->testDbName]);
        $existingTables = $query->fetchAll(PDO::FETCH_COLUMN);

        foreach (self::ALL_SIX_TABLES as $expectedTable) {
            self::assertContains(
                $expectedTable,
                $existingTables,
                "Expected table '{$expectedTable}' to exist after applying all migrations."
            );
        }
    }

    public function testLicenseKeyUniqueConstraintRejectsDuplicateInsert(): void
    {
        $duplicateKey = 'SERB-DUP01-DUP01-DUP01-DUP01';

        $this->insertLicense(['license_key' => $duplicateKey]);

        $this->expectException(PDOException::class);
        $this->insertLicense(['license_key' => $duplicateKey]);
    }

    public function testLicenseActivationsForeignKeyConstraintIsEnforced(): void
    {
        $nonExistentLicenseId = 999999999;

        $statement = $this->pdo->prepare(
            'INSERT INTO license_activations (license_id, site_url, site_hash, activated_at) '
            . 'VALUES (:license_id, :site_url, :site_hash, :activated_at)'
        );

        $this->expectException(PDOException::class);
        $statement->execute([
            'license_id' => $nonExistentLicenseId,
            'site_url' => 'https://example.com',
            'site_hash' => hash('sha256', 'https://example.com'),
            'activated_at' => '2024-01-01 00:00:00',
        ]);
    }

    public function testActivationLimitCheckConstraintRejectsZero(): void
    {
        $this->expectException(PDOException::class);
        $this->insertLicense(['activation_limit' => 0]);
    }

    public function testActivationLimitCheckConstraintRejectsNegative(): void
    {
        $this->expectException(PDOException::class);
        $this->insertLicense(['activation_limit' => -1]);
    }

    public function testPriceCheckConstraintRejectsNegativeAmount(): void
    {
        $this->expectException(PDOException::class);
        $this->insertLicense(['price_amount' => -0.01]);
    }

    public function testWebhookEventIdGeneratedColumnExtractsValueFromPayload(): void
    {
        $expectedEventId = 'evt_test_' . bin2hex(random_bytes(6));
        $payload = json_encode([
            'webhook_event_id' => $expectedEventId,
            'event' => ['type' => 'subscription.charged'],
        ]);
        self::assertNotFalse($payload);

        $insert = $this->pdo->prepare(
            'INSERT INTO license_events (license_id, event_type, payload) '
            . 'VALUES (NULL, :event_type, :payload)'
        );
        $insert->execute([
            'event_type' => 'webhook_charged',
            'payload' => $payload,
        ]);
        $insertedId = (int) $this->pdo->lastInsertId();

        $select = $this->pdo->prepare(
            'SELECT webhook_event_id FROM license_events WHERE id = :id'
        );
        $select->execute(['id' => $insertedId]);
        $row = $select->fetch();

        self::assertNotFalse($row);
        self::assertSame($expectedEventId, $row['webhook_event_id']);
    }
}
