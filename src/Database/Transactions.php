<?php

declare(strict_types=1);

namespace App\Database;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Write transactions with Rails' `after_commit` semantics.
 *
 * Everything that writes runs inside transaction(): it begins IMMEDIATE (see SqliteMiddleware),
 * so the write lock is held for the shortest possible span, and callbacks registered with
 * afterCommit() run only once the outermost transaction has committed. That is where broadcasts,
 * search indexing and job enqueueing go, exactly as Rails runs them from after_*_commit.
 *
 * In FrankenPHP worker mode this service lives across requests: reset() rolls back anything a
 * failed request left open, because one stray transaction would block every other writer.
 */
final class Transactions implements ResetInterface
{
    /** @var list<callable(): void> */
    private array $afterCommit = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        $outermost = !$this->connection->isTransactionActive();
        $this->connection->beginTransaction();
        try {
            $result = $work();
            $this->connection->commit();
        } catch (\Throwable $error) {
            $this->connection->rollBack();
            if ($outermost) {
                $this->afterCommit = [];
            }
            throw $error;
        }

        if ($outermost) {
            $this->runAfterCommit();
        }

        return $result;
    }

    /** Runs $callback after the current transaction commits, or right away outside one. */
    public function afterCommit(callable $callback): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->afterCommit[] = $callback;
        } else {
            $callback();
        }
    }

    public function inTransaction(): bool
    {
        return $this->connection->isTransactionActive();
    }

    public function reset(): void
    {
        $this->afterCommit = [];
        while ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
    }

    private function runAfterCommit(): void
    {
        while ([] !== $this->afterCommit) {
            $callback = array_shift($this->afterCommit);
            $callback();
        }
    }
}
