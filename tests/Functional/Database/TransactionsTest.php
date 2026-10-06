<?php

declare(strict_types=1);

namespace App\Tests\Functional\Database;

use App\Database\Transactions;
use App\Tests\Support\CampfireTestCase;
use App\Tests\Support\SeedDatabase;

final class TransactionsTest extends CampfireTestCase
{
    public function testTransactionsBeginImmediate(): void
    {
        $transactions = $this->transactions();
        $other = SeedDatabase::connect($this->storagePath);
        $other->executeStatement('PRAGMA busy_timeout = 0');

        try {
            $transactions->transaction(function () use ($other): void {
                // No write yet, but the write lock is already held: a deferred BEGIN wouldn't take it.
                try {
                    $other->executeStatement("UPDATE accounts SET name = 'other'");
                    self::fail('The other connection could write during an IMMEDIATE transaction.');
                } catch (\Doctrine\DBAL\Exception\LockWaitTimeoutException|\Doctrine\DBAL\Exception\DriverException $error) {
                    self::assertStringContainsString('locked', $error->getMessage());
                }
            });
        } finally {
            $other->close();
        }
    }

    public function testAfterCommitRunsOnceTheOutermostTransactionCommits(): void
    {
        $transactions = $this->transactions();
        $log = [];

        $result = $transactions->transaction(function () use ($transactions, &$log): string {
            $this->connection()->executeStatement("UPDATE accounts SET name = 'Renamed'");
            $transactions->afterCommit(function () use (&$log): void {
                $log[] = 'outer:'.$this->connection()->fetchOne('SELECT name FROM accounts');
            });
            $transactions->transaction(function () use ($transactions, &$log): void {
                $transactions->afterCommit(static function () use (&$log): void {
                    $log[] = 'inner';
                });
            });
            self::assertSame([], $log, 'nothing runs before the outermost commit');

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(['outer:Renamed', 'inner'], $log);
        self::assertFalse($transactions->inTransaction());
    }

    public function testAfterCommitOutsideATransactionRunsRightAway(): void
    {
        $ran = false;
        $this->transactions()->afterCommit(static function () use (&$ran): void {
            $ran = true;
        });

        self::assertTrue($ran);
    }

    public function testRollbackDiscardsCallbacksAndWrites(): void
    {
        $transactions = $this->transactions();
        /** @var \ArrayObject<int, string> $log */
        $log = new \ArrayObject();
        $work = function () use ($transactions, $log): void {
            $this->connection()->executeStatement("UPDATE accounts SET name = 'Rolled back'");
            $transactions->afterCommit(static function () use ($log): void {
                $log[] = 'ran';
            });
            throw new \DomainException('boom');
        };

        try {
            $transactions->transaction($work);
        } catch (\DomainException $error) {
            self::assertSame('boom', $error->getMessage());
        }

        self::assertCount(0, $log);
        self::assertFalse($transactions->inTransaction());
        self::assertNotSame('Rolled back', $this->connection()->fetchOne('SELECT name FROM accounts'));

        $transactions->transaction(static fn () => null);
        self::assertCount(0, $log, 'the discarded callback never runs later');
    }

    public function testResetRollsBackALeftoverTransaction(): void
    {
        $transactions = $this->transactions();
        $ran = false;
        $this->connection()->beginTransaction();
        $this->connection()->executeStatement("UPDATE accounts SET name = 'Leftover'");
        $transactions->afterCommit(static function () use (&$ran): void {
            $ran = true;
        });

        $transactions->reset();

        self::assertFalse($transactions->inTransaction());
        self::assertFalse($ran);
        self::assertNotSame('Leftover', $this->connection()->fetchOne('SELECT name FROM accounts'));
    }

    /** Built on the app's connection: nothing uses the service yet, so the container drops it. */
    private function transactions(): Transactions
    {
        return new Transactions($this->connection());
    }
}
