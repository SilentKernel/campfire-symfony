<?php

declare(strict_types=1);

namespace App\Tests\Functional\Database;

use App\Database\Schema;
use App\Tests\Support\CampfireTestCase;
use App\Tests\Support\SeedDatabase;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class InstallCommandTest extends CampfireTestCase
{
    private const SQLITE_MASTER = 'SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY rowid';

    public function testInstallOnAnEmptyDirectoryCreatesTheRailsSchema(): void
    {
        $path = SeedDatabase::emptyStorage().'/fresh';
        $this->setEnv('CAMPFIRE_STORAGE_PATH', $path);
        $this->setEnv('CAMPFIRE_FROZEN_TIME', '2026-03-02T16:00:00.5Z');

        try {
            $tester = $this->install();
            self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
            self::assertStringContainsString('Created the database', $tester->getDisplay());
            self::assertDirectoryExists($path.'/files');

            $installed = SeedDatabase::connect($path);
            $seed = SeedDatabase::connect($this->storagePath);

            // Same objects, same DDL, same order as the Rails-made seed, FTS5 shadow tables included.
            self::assertSame($seed->fetchAllAssociative(self::SQLITE_MASTER), $installed->fetchAllAssociative(self::SQLITE_MASTER));
            self::assertSame($this->schemaSql(), $this->dump($installed));

            self::assertSame(
                $seed->fetchFirstColumn('SELECT version FROM schema_migrations ORDER BY rowid'),
                $installed->fetchFirstColumn('SELECT version FROM schema_migrations ORDER BY rowid'),
            );
            self::assertSame(
                [
                    ['key' => 'environment', 'value' => 'production', 'created_at' => '2026-03-02 16:00:00.500000', 'updated_at' => '2026-03-02 16:00:00.500000'],
                    ['key' => 'schema_sha1', 'value' => Schema::SCHEMA_SHA1, 'created_at' => '2026-03-02 16:00:00.500000', 'updated_at' => '2026-03-02 16:00:00.500000'],
                ],
                $installed->fetchAllAssociative('SELECT * FROM ar_internal_metadata ORDER BY rowid'),
            );
            self::assertSame('wal', $installed->fetchOne('PRAGMA journal_mode'));

            $installed->executeStatement("INSERT INTO message_search_index(rowid, body) VALUES (1, 'running dogs')");
            self::assertSame(1, (int) $installed->fetchOne("SELECT rowid FROM message_search_index WHERE body MATCH 'run'"), 'porter tokenizer');
            $installed->close();
            $seed->close();

            self::ensureKernelShutdown();
            $again = $this->install();
            self::assertSame(Command::SUCCESS, $again->getStatusCode());
            self::assertStringContainsString('up to date', $again->getDisplay());
        } finally {
            self::ensureKernelShutdown();
            SeedDatabase::remove(\dirname($path));
        }
    }

    public function testInstallOnTheSeedIsANoOp(): void
    {
        $database = $this->storagePath.'/db/production.sqlite3';
        $seed = SeedDatabase::connect($this->storagePath);
        $before = [$seed->fetchAllAssociative(self::SQLITE_MASTER), $seed->fetchAllAssociative('SELECT * FROM schema_migrations'), $seed->fetchAllAssociative('SELECT * FROM ar_internal_metadata')];
        $seed->close();
        $hash = hash_file('sha256', $database);

        $tester = $this->install();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('up to date', $tester->getDisplay());
        self::ensureKernelShutdown();
        $seed = SeedDatabase::connect($this->storagePath);
        self::assertSame($before, [$seed->fetchAllAssociative(self::SQLITE_MASTER), $seed->fetchAllAssociative('SELECT * FROM schema_migrations'), $seed->fetchAllAssociative('SELECT * FROM ar_internal_metadata')]);
        $seed->close();
        self::assertSame($hash, hash_file('sha256', $database));
    }

    public function testInstallRefusesADatabaseWithPendingMigrations(): void
    {
        $seed = SeedDatabase::connect($this->storagePath);
        $seed->executeStatement("DELETE FROM schema_migrations WHERE version IN ('20251212154340', '20240110071740')");
        $seed->close();

        $tester = $this->install();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('20240110071740, 20251212154340', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
    }

    public function testVersionsMatchTheReferenceMigrationsAndSchemaSha1(): void
    {
        $migrations = array_map(static fn (string $file): string => explode('_', basename($file))[0], glob(SeedDatabase::projectDir().'/reference/db/migrate/*.rb') ?: []);
        $schema = static::getContainer()->get(Schema::class);
        self::assertInstanceOf(Schema::class, $schema);
        $versions = $schema->versions();
        sort($migrations);
        $sorted = $versions;
        sort($sorted);

        self::assertNotEmpty($migrations);
        self::assertSame($migrations, $sorted);
        self::assertSame(Schema::SCHEMA_SHA1, sha1_file(SeedDatabase::projectDir().'/reference/db/schema.rb'));
    }

    private function install(): CommandTester
    {
        $application = new Application(self::bootKernel());
        $tester = new CommandTester($application->find('campfire:install'));
        $tester->execute([]);

        return $tester;
    }

    /** sqlite_master as db/schema.sql lists it: no sqlite_sequence, no FTS5 shadow tables, no autoindexes. */
    private function dump(Connection $connection): string
    {
        $rows = $connection->fetchFirstColumn(
            "SELECT sql || ';' FROM sqlite_master WHERE sql IS NOT NULL AND name <> 'sqlite_sequence' AND name NOT LIKE 'message_search_index_%' ORDER BY rowid",
        );

        return implode("\n", $rows)."\n";
    }

    private function schemaSql(): string
    {
        return (string) file_get_contents(SeedDatabase::projectDir().'/db/schema.sql');
    }
}
