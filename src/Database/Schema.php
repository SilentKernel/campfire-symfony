<?php

declare(strict_types=1);

namespace App\Database;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * `bin/rails db:prepare` for the Rails database (db/schema.sql, db/versions.json).
 *
 * An empty database gets the schema exactly as Rails' `db:schema:load` writes it, the migration
 * versions `assume_migrated_upto_version` records, and the `ar_internal_metadata` rows, so the
 * Rails image can take the database back. Migrations aren't ported: an existing database must
 * already have every version (boot the Rails image once to migrate an older one).
 */
final readonly class Schema
{
    /** SHA1 of reference/db/schema.rb, which `db:schema:load` stores as `schema_sha1`. */
    public const SCHEMA_SHA1 = 'f75da8dad38bfb179ffd757bd7a7c2b3f818bc29';

    public const ENVIRONMENT = 'production';

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private string $projectDir,
    ) {
    }

    /** Whether the Rails schema is loaded (Rails checks for its tables; `users` is the first we need). */
    public function isLoaded(): bool
    {
        return $this->tableExists('users');
    }

    /**
     * Loads the schema into an empty database. Returns false when it was already loaded.
     *
     * @throws PendingMigrations when an existing database misses migration versions
     */
    public function prepare(): bool
    {
        if ($this->isLoaded()) {
            $pending = $this->pendingMigrations();
            if ([] !== $pending) {
                throw new PendingMigrations($pending);
            }

            return false;
        }

        $this->connection->transactional(function (Connection $connection): void {
            // Several statements in one call: PDO SQLite's exec() runs them all.
            $native = $connection->getNativeConnection();
            \assert($native instanceof \PDO);
            $native->exec($this->sql());

            // Same order as Rails: the current version first, then the rest, newest first.
            foreach ($this->versions() as $version) {
                $connection->insert('schema_migrations', ['version' => $version]);
            }

            $now = Type\RailsDateTimeType::format($this->clock->now());
            foreach (['environment' => self::ENVIRONMENT, 'schema_sha1' => self::SCHEMA_SHA1] as $key => $value) {
                $connection->insert('ar_internal_metadata', ['key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now]);
            }
        });

        return true;
    }

    /**
     * Versions in db/versions.json missing from schema_migrations, oldest first.
     *
     * @return list<string>
     */
    public function pendingMigrations(): array
    {
        $applied = $this->tableExists('schema_migrations')
            ? $this->connection->fetchFirstColumn('SELECT version FROM schema_migrations')
            : [];
        $pending = array_values(array_diff($this->versions(), $applied));
        sort($pending);

        return $pending;
    }

    /** The DDL of db/schema.sql. */
    public function sql(): string
    {
        return (string) file_get_contents($this->projectDir.'/db/schema.sql');
    }

    /**
     * Every migration version, in the order Rails inserts them into schema_migrations.
     *
     * @return list<string>
     */
    public function versions(): array
    {
        /* @var list<string> */
        return json_decode((string) file_get_contents($this->projectDir.'/db/versions.json'), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function tableExists(string $name): bool
    {
        return false !== $this->connection->fetchOne("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$name]);
    }
}
