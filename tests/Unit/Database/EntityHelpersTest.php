<?php

declare(strict_types=1);

namespace App\Tests\Unit\Database;

use App\Entity\Account;
use App\Entity\Enum\Involvement;
use App\Entity\Enum\UserRole;
use App\Entity\Membership;
use App\Entity\Message;
use App\Entity\Rooms\Closed;
use App\Entity\Rooms\Direct;
use App\Entity\Rooms\Open;
use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EntityHelpersTest extends TestCase
{
    /** @return iterable<array{string, string}> */
    public static function initials(): iterable
    {
        // `name.scan(/\b\w/).join`, outputs from Ruby 3.4 in the reference image.
        yield ['DHH', 'David Heinemeier Hansson'];
        yield ['J', 'José Ñandú'];
        yield ['jlon', "jean-luc o'neil"];
        yield ['Z', 'Émile Zola'];
        yield ['', '李 小龙'];
        yield ['u3', 'under_score 3d'];
        yield ['xz', 'x²y z'];
        yield ['', '  '];
        yield ['B', 'Ünal Bob'];
    }

    #[DataProvider('initials')]
    public function testInitialsMatchRuby(string $expected, string $name): void
    {
        self::assertSame($expected, (new User($name))->initials());
    }

    public function testTitle(): void
    {
        $user = new User('David');
        self::assertSame('David', $user->title());
        self::assertSame('David', $user->setBio(" \u{3000}")->title());
        self::assertSame('David – Founder', $user->setBio('Founder')->title());
    }

    public function testRolesAndCanAdminister(): void
    {
        $member = $this->saved(new User('Kevin'), 1);
        $admin = $this->saved((new User('David'))->setRole(UserRole::Administrator), 2);
        $bot = $this->saved((new User('Bender'))->setRole(UserRole::Bot), 3);

        self::assertSame(['ROLE_USER'], $member->getRoles());
        self::assertSame(['ROLE_USER', 'ROLE_BOT'], $bot->getRoles());
        self::assertTrue($bot->isBot());

        $room = $this->saved(new Open($admin, 'HQ'), 10);
        self::assertTrue($admin->canAdminister());
        self::assertFalse($member->canAdminister());
        self::assertFalse($member->canAdminister($room));
        self::assertTrue($admin->canAdminister($room));
        self::assertTrue($member->canAdminister(new Closed($admin, 'new')), 'a new record');
        self::assertTrue($member->canAdminister($this->saved(new Message($room, $member, 'abc'), 5)), 'its creator');
    }

    public function testRoomTypes(): void
    {
        $user = new User('David');
        self::assertTrue((new Open($user))->isOpen());
        self::assertTrue((new Closed($user))->isClosed());
        $direct = new Direct($user);
        self::assertTrue($direct->isDirect());
        self::assertFalse($direct->isOpen());
        self::assertSame('Rooms::Direct', $direct->getType());
        self::assertSame(Involvement::Everything, $direct->getDefaultInvolvement());
        self::assertSame(Involvement::Mentions, (new Open($user))->getDefaultInvolvement());
    }

    public function testMembershipUnreadAndConnected(): void
    {
        $user = new User('David');
        $membership = new Membership(new Open($user), $user);
        $now = new \DateTimeImmutable('2026-03-02 16:00:00', new \DateTimeZone('UTC'));

        self::assertFalse($membership->isUnread());
        self::assertTrue($membership->setUnreadAt($now)->isUnread());
        self::assertSame(Involvement::Mentions, $membership->getInvolvement());

        self::assertFalse($membership->isConnected($now));
        self::assertTrue($membership->setConnectedAt($now->modify('-60 seconds'))->isConnected($now));
        self::assertFalse($membership->setConnectedAt($now->modify('-61 seconds'))->isConnected($now));
    }

    public function testMessageToKey(): void
    {
        $user = new User('David');
        self::assertSame(['3fa85f64'], (new Message(new Open($user), $user, '3fa85f64'))->toKey());
    }

    public function testAccountSettings(): void
    {
        $account = new Account('37signals', 'abcd-efgh-ijkl');
        self::assertSame(['restrict_room_creation_to_administrators' => false], $account->getSettings());
        self::assertFalse($account->restrictsRoomCreationToAdministrators());

        $account->assignSettings(['restrict_room_creation_to_administrators' => '1']);
        self::assertTrue($account->restrictsRoomCreationToAdministrators());
        $account->assignSettings(['restrict_room_creation_to_administrators' => '0']);
        self::assertFalse($account->restrictsRoomCreationToAdministrators());

        $this->expectException(\InvalidArgumentException::class);
        $account->assignSettings(['unknown' => true]);
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function saved(object $entity, int $id): object
    {
        $class = new \ReflectionClass($entity);
        while (!$class->hasProperty('id') || $class->getProperty('id')->getDeclaringClass()->getName() !== $class->getName()) {
            $class = $class->getParentClass() ?: throw new \LogicException('No id property.');
        }
        $class->getProperty('id')->setValue($entity, $id);

        return $entity;
    }
}
