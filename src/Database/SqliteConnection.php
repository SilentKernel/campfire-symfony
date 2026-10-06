<?php

declare(strict_types=1);

namespace App\Database;

use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;

/**
 * `default_transaction_mode: immediate` (reference/config/database.yml); see SqliteMiddleware.
 */
final class SqliteConnection extends AbstractConnectionMiddleware
{
    public function beginTransaction(): void
    {
        $this->exec('BEGIN IMMEDIATE');
    }

    public function commit(): void
    {
        $this->exec('COMMIT');
    }

    public function rollBack(): void
    {
        $this->exec('ROLLBACK');
    }
}
