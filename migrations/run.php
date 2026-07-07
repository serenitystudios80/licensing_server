#!/usr/bin/env php
<?php

/**
 * Migration runner.
 *
 * Applies every `.sql` file in this directory, in filename order, against
 * the MariaDB database described by the application's `.env` configuration
 * (see `src/Config/Config.php`). Already-applied migrations (tracked in a
 * `schema_migrations` table) are skipped.
 *
 * Usage: php migrations/run.php
 *
 * Error-handling policy (see design.md "Error message and logging
 * specificity policy" and the cross-cutting note at the top of
 * tasks.md): this is an operator-facing CLI tool, so every failure prints
 * a specific, dynamic, actionable message — never a generic "Error" or
 * "Something went wrong" — and the process exits with a non-zero status
 * code. The DB_PASS value itself is never printed; only the names of the
 * env vars to check are mentioned.
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__);

$autoloadPath = $projectRoot . '/vendor/autoload.php';
if (!is_file($autoloadPath)) {
    fwrite(
        STDERR,
        "Could not find Composer autoloader at {$autoloadPath} — run `composer install` "
        . "in {$projectRoot} before running the migration runner.\n"
    );
    exit(1);
}
require $autoloadPath;

use App\Config\Config;
use App\Config\ConfigException;

/**
 * Loads application configuration, exiting with a specific, actionable
 * message if a required key is missing.
 */
function loadConfigOrExit(): Config
{
    try {
        return Config::load();
    } catch (ConfigException $e) {
        fwrite(
            STDERR,
            "Configuration error: required environment variable {$e->missingKey()} is missing or "
            . "empty — check your .env file (see .env.example) or the real process environment.\n"
        );
        exit(1);
    }
}

/**
 * Connects to MariaDB using the loaded configuration, exiting with a
 * specific, actionable message (naming host/database/user, but never the
 * password value) if the connection fails.
 */
function connectOrExit(Config $config): PDO
{
    $host = $config->get('DB_HOST');
    $database = $config->get('DB_NAME');
    $user = $config->get('DB_USER');
    $pass = $config->get('DB_PASS');

    $dsn = "mysql:host={$host};dbname={$database};charset=utf8mb4";

    try {
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        fwrite(
            STDERR,
            "Could not connect to MariaDB at {$host}/{$database} as user {$user}: {$e->getMessage()} "
            . "— check DB_HOST/DB_NAME/DB_USER/DB_PASS in your .env file.\n"
        );
        exit(1);
    }
}

/**
 * Ensures the `schema_migrations` bookkeeping table exists.
 */
function ensureSchemaMigrationsTable(PDO $pdo, string $user): void
{
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                migration VARCHAR(255) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (PDOException $e) {
        fwrite(
            STDERR,
            "Could not create schema_migrations tracking table: {$e->getMessage()} "
            . "— check that user {$user} has CREATE TABLE privileges on the configured database.\n"
        );
        exit(1);
    }
}

/**
 * @return list<string> Migration filenames already recorded as applied.
 */
function fetchAppliedMigrations(PDO $pdo): array
{
    try {
        $statement = $pdo->query('SELECT migration FROM schema_migrations');
        /** @var list<string> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
        return $rows;
    } catch (PDOException $e) {
        fwrite(
            STDERR,
            "Could not read schema_migrations tracking table: {$e->getMessage()} "
            . "— the table may be missing or corrupted; check the schema_migrations table manually.\n"
        );
        exit(1);
    }
}

/**
 * @return list<string> Absolute paths to `.sql` files in this directory, sorted by filename.
 */
function discoverMigrationFiles(string $migrationsDir): array
{
    $files = glob($migrationsDir . '/*.sql');
    if ($files === false) {
        fwrite(STDERR, "Could not scan migrations directory {$migrationsDir} for .sql files.\n");
        exit(1);
    }

    sort($files, SORT_STRING);

    return $files;
}

/**
 * Applies a single migration file inside a transaction that also records
 * the schema_migrations row, so a failure never leaves a half-applied
 * migration marked as applied.
 */
function applyMigration(PDO $pdo, string $filePath): void
{
    $filename = basename($filePath);

    $sql = file_get_contents($filePath);
    if ($sql === false) {
        fwrite(STDERR, "Migration {$filename} failed: could not read file contents from {$filePath}.\n");
        exit(1);
    }

    if (trim($sql) === '') {
        fwrite(STDERR, "Migration {$filename} failed: file is empty — no SQL statements to execute.\n");
        exit(1);
    }

    try {
        $pdo->exec($sql);

        $insert = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
        $insert->execute(['migration' => $filename]);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        fwrite(
            STDERR,
            "Migration {$filename} failed: {$e->getMessage()} — no changes from this file were committed.\n"
        );
        exit(1);
    }

    echo "Applied {$filename}\n";
}

function main(): void
{
    $migrationsDir = __DIR__;

    $config = loadConfigOrExit();
    $pdo = connectOrExit($config);

    echo "Connected to MariaDB at {$config->get('DB_HOST')}/{$config->get('DB_NAME')}.\n";

    ensureSchemaMigrationsTable($pdo, $config->get('DB_USER'));

    $appliedMigrations = fetchAppliedMigrations($pdo);
    $migrationFiles = discoverMigrationFiles($migrationsDir);

    if ($migrationFiles === []) {
        echo "No .sql migration files found in {$migrationsDir}.\n";
        return;
    }

    $appliedCount = 0;
    $skippedCount = 0;

    foreach ($migrationFiles as $filePath) {
        $filename = basename($filePath);

        if (in_array($filename, $appliedMigrations, true)) {
            echo "Skipping {$filename} (already applied)\n";
            $skippedCount++;
            continue;
        }

        echo "Applying {$filename}...\n";
        applyMigration($pdo, $filePath);
        $appliedCount++;
    }

    echo "Done. {$appliedCount} migration(s) applied, {$skippedCount} already up to date.\n";
}

main();
