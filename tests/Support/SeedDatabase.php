<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Database\SqliteMiddleware;
use App\Database\Type\RailsDateTimeType;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Throwaway copies of the parity seed (var/seed/default, see bin/fetch-seed) laid out as a
 * CAMPFIRE_STORAGE_PATH: `<path>/db/production.sqlite3` and `<path>/files/` (the seed's Active
 * Storage tree). The seed itself is never opened, so it stays byte-for-byte what Rails built.
 */
final class SeedDatabase
{
    public const SEED = 'default';

    public static function projectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    public static function seedDir(string $seed = self::SEED): string
    {
        $dir = self::projectDir().'/var/seed/'.$seed;
        if (!is_file($dir.'/db/production.sqlite3')) {
            throw new \RuntimeException(\sprintf('The "%s" seed is missing: run bin/fetch-seed %1$s.', $seed));
        }

        return $dir;
    }

    /** A fresh copy of the seed under var/test-storage/, returned as a storage path. */
    public static function copy(string $seed = self::SEED): string
    {
        $source = self::seedDir($seed);
        $path = self::emptyStorage();
        $filesystem = new Filesystem();
        $filesystem->copy($source.'/db/production.sqlite3', $path.'/db/production.sqlite3');
        if (is_dir($source.'/storage')) {
            $filesystem->mirror($source.'/storage', $path.'/files');
        }

        return $path;
    }

    /** An empty storage directory (db/ and files/) under var/test-storage/. */
    public static function emptyStorage(): string
    {
        $path = self::projectDir().'/var/test-storage/'.bin2hex(random_bytes(8));
        (new Filesystem())->mkdir([$path.'/db', $path.'/files']);

        return $path;
    }

    public static function remove(string $path): void
    {
        (new Filesystem())->remove($path);
    }

    /** A DBAL connection to `<path>/db/production.sqlite3`, configured like the app's. */
    public static function connect(string $path): Connection
    {
        if (!Type::hasType(RailsDateTimeType::NAME)) {
            Type::addType(RailsDateTimeType::NAME, RailsDateTimeType::class);
        }
        $configuration = (new Configuration())->setMiddlewares([new SqliteMiddleware()]);

        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path.'/db/production.sqlite3'], $configuration);
    }

    /**
     * The seed's labels.json: fixture names to ids and values (`rooms.watercooler`, `emails.david`).
     *
     * @return array<string, int|string>
     */
    public static function labels(string $seed = self::SEED): array
    {
        static $labels = [];

        return $labels[$seed] ??= json_decode((string) file_get_contents(self::seedDir($seed).'/labels.json'), true, flags: \JSON_THROW_ON_ERROR);
    }
}
