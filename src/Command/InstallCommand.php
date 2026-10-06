<?php

declare(strict_types=1);

namespace App\Command;

use App\Database\PendingMigrations;
use App\Database\Schema;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

/**
 * What the Rails image does on boot (bin/rails db:prepare): creates storage/db and storage/files,
 * loads the schema into a new database, refuses one with pending migrations, and leaves an
 * up-to-date one alone. Idempotent; run before the server starts.
 */
#[AsCommand(name: 'campfire:install', description: 'Create the storage directories and prepare the database like Rails db:prepare')]
final readonly class InstallCommand
{
    public function __construct(
        private Connection $connection,
        private Schema $schema,
        private string $storagePath,
        private string $filesPath,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        // Before the first query: the SQLite file is created inside storage/db on connect.
        new Filesystem()->mkdir([$this->storagePath.'/db', $this->filesPath]);

        try {
            $loaded = $this->schema->prepare();
        } catch (PendingMigrations $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }

        $this->connection->executeStatement('PRAGMA journal_mode = WAL');

        $io->success($loaded ? 'Created the database.' : 'The database is up to date.');

        return Command::SUCCESS;
    }
}
