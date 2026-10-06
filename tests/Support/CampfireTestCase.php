<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base class for functional tests: every test runs against its own copy of the parity seed
 * (db/production.sqlite3 and files/), selected through CAMPFIRE_STORAGE_PATH before the kernel
 * boots, and deleted afterwards.
 *
 * Env vars that the container reads at runtime (CAMPFIRE_FROZEN_TIME, …) can be set with
 * setEnv() before the kernel boots (before createClient()/bootKernel()/em()).
 */
abstract class CampfireTestCase extends WebTestCase
{
    protected string $storagePath;

    /** @var array<string, array{string|false, mixed, mixed}> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->storagePath = SeedDatabase::copy();
        $this->setEnv('CAMPFIRE_STORAGE_PATH', $this->storagePath);
    }

    protected function tearDown(): void
    {
        // Shuts the kernel down first, which closes the SQLite connections.
        parent::tearDown();
        foreach ($this->savedEnv as $name => [$getenv, $server, $env]) {
            false === $getenv ? putenv($name) : putenv($name.'='.$getenv);
            self::restore($_SERVER, $name, $server);
            self::restore($_ENV, $name, $env);
        }
        $this->savedEnv = [];
        SeedDatabase::remove($this->storagePath);
    }

    /** Sets an environment variable for this test (getenv, $_SERVER and $_ENV), restored afterwards. */
    protected function setEnv(string $name, string $value): void
    {
        if (static::$booted) {
            throw new \LogicException(\sprintf('Set %s before the kernel boots.', $name));
        }
        $this->savedEnv[$name] ??= [getenv($name), $_SERVER[$name] ?? null, $_ENV[$name] ?? null];
        putenv($name.'='.$value);
        $_SERVER[$name] = $_ENV[$name] = $value;
    }

    /** A value from the seed's labels.json (`rooms.watercooler`, `emails.david`, …). */
    protected static function labels(string $key): int|string
    {
        return SeedDatabase::labels()[$key] ?? throw new \OutOfBoundsException(\sprintf('No "%s" label in labels.json.', $key));
    }

    /** The label as an id. */
    protected static function id(string $key): int
    {
        return (int) self::labels($key);
    }

    protected function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    protected function connection(): Connection
    {
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);

        return $connection;
    }

    /** @param array<string, mixed> $vars */
    private static function restore(array &$vars, string $name, mixed $value): void
    {
        if (null === $value) {
            unset($vars[$name]);
        } else {
            $vars[$name] = $value;
        }
    }
}
