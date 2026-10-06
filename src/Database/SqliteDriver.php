<?php

declare(strict_types=1);

namespace App\Database;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Applies SqliteMiddleware::PRAGMAS on connect. Named classes rather than anonymous ones so
 * opcache preloading can link them.
 */
final class SqliteDriver extends AbstractDriverMiddleware
{
    public function connect(array $params): Connection
    {
        $connection = parent::connect($params);
        $busyTimeout = $params['driverOptions']['busy_timeout'] ?? null;
        foreach (SqliteMiddleware::PRAGMAS as $name => $value) {
            if ('busy_timeout' === $name && null !== $busyTimeout) {
                $value = (string) $busyTimeout;
            }
            $connection->exec("PRAGMA {$name} = {$value}");
        }

        return new SqliteConnection($connection);
    }
}
