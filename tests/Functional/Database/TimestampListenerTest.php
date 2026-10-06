<?php

declare(strict_types=1);

namespace App\Tests\Functional\Database;

use App\Entity\ActiveStorage\Blob;
use App\Entity\Rooms\Open;
use App\Entity\Search;
use App\Entity\User;
use App\Tests\Support\CampfireTestCase;

final class TimestampListenerTest extends CampfireTestCase
{
    private const NOW = '2026-03-02 16:00:00.123456';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('CAMPFIRE_FROZEN_TIME', '2026-03-02T16:00:00.123456Z');
    }

    public function testCreateSetsBothTimestamps(): void
    {
        $david = $this->user('users.david');
        $search = new Search($david, 'cats');
        $this->em()->persist($search);
        $this->em()->flush();

        self::assertSame(
            ['created_at' => self::NOW, 'updated_at' => self::NOW],
            $this->connection()->fetchAssociative('SELECT created_at, updated_at FROM searches WHERE id = ?', [$search->getId()]),
        );
    }

    public function testCreateKeepsTimestampsThatWereSet(): void
    {
        $room = new Open($this->user('users.david'), 'Imported');
        $room->setCreatedAt(new \DateTimeImmutable('2020-01-01 00:00:00', new \DateTimeZone('UTC')));
        $this->em()->persist($room);
        $this->em()->flush();

        self::assertSame(
            ['created_at' => '2020-01-01 00:00:00', 'updated_at' => self::NOW, 'type' => 'Rooms::Open'],
            $this->connection()->fetchAssociative('SELECT created_at, updated_at, type FROM rooms WHERE id = ?', [$room->getId()]),
        );
    }

    public function testTablesWithOnlyCreatedAt(): void
    {
        $blob = new Blob('testkey123', 'a.txt', 3);
        $this->em()->persist($blob);
        $this->em()->flush();

        self::assertSame(self::NOW, $this->connection()->fetchOne('SELECT created_at FROM active_storage_blobs WHERE id = ?', [$blob->getId()]));
    }

    public function testUpdateBumpsUpdatedAtOnlyWhenSomethingChanged(): void
    {
        $id = $this->id('users.jason');
        $before = $this->connection()->fetchAssociative('SELECT created_at, updated_at FROM users WHERE id = ?', [$id]);
        $jason = $this->user('users.jason');

        $jason->setName($jason->getName());
        $this->em()->flush();
        self::assertSame($before, $this->connection()->fetchAssociative('SELECT created_at, updated_at FROM users WHERE id = ?', [$id]));

        $jason->setBio('Designer');
        $this->em()->flush();
        self::assertSame(
            ['created_at' => $before['created_at'] ?? null, 'updated_at' => self::NOW],
            $this->connection()->fetchAssociative('SELECT created_at, updated_at FROM users WHERE id = ?', [$id]),
        );
    }

    public function testAnExplicitUpdatedAtWins(): void
    {
        $jason = $this->user('users.jason');
        $jason->setBio('Designer')->setUpdatedAt(new \DateTimeImmutable('2021-05-05 05:05:05.5', new \DateTimeZone('UTC')));
        $this->em()->flush();

        self::assertSame('2021-05-05 05:05:05.500000', $this->connection()->fetchOne('SELECT updated_at FROM users WHERE id = ?', [$jason->getId()]));
    }

    private function user(string $label): User
    {
        $user = $this->em()->find(User::class, self::id($label));
        \assert(null !== $user);

        return $user;
    }
}
