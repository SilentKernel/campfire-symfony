<?php

declare(strict_types=1);

namespace App\Database;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * Opens SQLite connections the way Rails' sqlite3 adapter does (reference/config/database.yml):
 * its default PRAGMAs, a 5 second busy timeout, and `default_transaction_mode: immediate`.
 *
 * PDO's beginTransaction() issues a deferred BEGIN, which takes the write lock only at the first
 * write and can then fail with SQLITE_BUSY instead of waiting. BEGIN IMMEDIATE takes it up front,
 * so concurrent writers queue on the busy timeout as they do under Rails.
 */
#[AsMiddleware(connections: ['default', 'jobs'])]
final class SqliteMiddleware implements Middleware
{
    /** ActiveRecord::ConnectionAdapters::SQLite3Adapter::DEFAULT_PRAGMAS, plus database.yml's timeout. */
    public const PRAGMAS = [
        'foreign_keys' => 'ON',
        'journal_mode' => 'WAL',
        'synchronous' => 'NORMAL',
        'mmap_size' => '134217728',
        'journal_size_limit' => '67108864',
        'cache_size' => '2000',
        'busy_timeout' => '5000',
    ];

    public function wrap(Driver $driver): Driver
    {
        return new SqliteDriver($driver);
    }
}
