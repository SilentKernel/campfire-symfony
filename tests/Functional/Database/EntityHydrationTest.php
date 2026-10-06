<?php

declare(strict_types=1);

namespace App\Tests\Functional\Database;

use App\Entity\Account;
use App\Entity\ActiveStorage\Attachment;
use App\Entity\ActiveStorage\Blob;
use App\Entity\ActiveStorage\VariantRecord;
use App\Entity\Ban;
use App\Entity\Boost;
use App\Entity\Enum\Involvement;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\UserStatus;
use App\Entity\Membership;
use App\Entity\Message;
use App\Entity\PushSubscription;
use App\Entity\RichText;
use App\Entity\Room;
use App\Entity\Rooms\Closed;
use App\Entity\Rooms\Direct;
use App\Entity\Rooms\Open;
use App\Entity\Search;
use App\Entity\Session;
use App\Entity\User;
use App\Entity\Webhook;
use App\Tests\Support\CampfireTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class EntityHydrationTest extends CampfireTestCase
{
    /** @return iterable<string, array{class-string, string}> */
    public static function tables(): iterable
    {
        yield 'accounts' => [Account::class, 'accounts'];
        yield 'users' => [User::class, 'users'];
        yield 'sessions' => [Session::class, 'sessions'];
        yield 'rooms' => [Room::class, 'rooms'];
        yield 'memberships' => [Membership::class, 'memberships'];
        yield 'messages' => [Message::class, 'messages'];
        yield 'action_text_rich_texts' => [RichText::class, 'action_text_rich_texts'];
        yield 'boosts' => [Boost::class, 'boosts'];
        yield 'searches' => [Search::class, 'searches'];
        yield 'bans' => [Ban::class, 'bans'];
        yield 'webhooks' => [Webhook::class, 'webhooks'];
        yield 'push_subscriptions' => [PushSubscription::class, 'push_subscriptions'];
        yield 'active_storage_blobs' => [Blob::class, 'active_storage_blobs'];
        yield 'active_storage_attachments' => [Attachment::class, 'active_storage_attachments'];
        yield 'active_storage_variant_records' => [VariantRecord::class, 'active_storage_variant_records'];
    }

    /** @param class-string $class */
    #[DataProvider('tables')]
    public function testEveryRowHydrates(string $class, string $table): void
    {
        $count = (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM {$table}");
        self::assertGreaterThan(0, $count, "the seed has {$table}");

        $entities = $this->em()->getRepository($class)->findAll();
        self::assertCount($count, $entities);
        foreach ($entities as $entity) {
            self::assertInstanceOf($class, $entity);
        }
    }

    public function testRoomsResolveTheirStiClass(): void
    {
        $types = $this->connection()->fetchAllKeyValue('SELECT id, type FROM rooms');
        $classes = [Open::TYPE => Open::class, Closed::TYPE => Closed::class, Direct::TYPE => Direct::class];

        foreach ($this->em()->getRepository(Room::class)->findAll() as $room) {
            self::assertInstanceOf($classes[$types[$room->getId()]], $room);
            self::assertSame($types[$room->getId()], $room->getType());
        }
        self::assertInstanceOf(Closed::class, $this->em()->find(Room::class, self::id('rooms.watercooler')));
        self::assertTrue($this->em()->find(Room::class, self::id('rooms.david_and_jason'))?->isDirect());
        self::assertCount(3, $this->em()->getRepository(Open::class)->findAll());
    }

    public function testColumnsMapToTheirValues(): void
    {
        $david = $this->em()->find(User::class, self::id('users.david'));
        self::assertNotNull($david);
        self::assertSame('David', $david->getName());
        self::assertSame(self::labels('emails.david'), $david->getEmailAddress());
        self::assertSame(UserRole::Administrator, $david->getRole());
        self::assertSame(['ROLE_USER', 'ROLE_ADMINISTRATOR'], $david->getRoles());
        self::assertSame((string) self::id('users.david'), $david->getUserIdentifier());
        self::assertSame('2', substr((string) $david->getPasswordDigest(), 1, 1), 'a bcrypt digest');

        $rita = $this->em()->find(User::class, self::id('users.rita'));
        self::assertSame(UserStatus::Deactivated, $rita?->getStatus());

        $bender = $this->em()->find(User::class, self::id('users.bender'));
        self::assertTrue($bender?->isBot());
        self::assertSame(self::labels('bot_keys.bender'), $bender->botKey());

        $account = $this->em()->find(Account::class, self::id('accounts.signal'));
        self::assertSame(self::labels('join_codes.signal'), $account?->getJoinCode());
        self::assertSame(['restrict_room_creation_to_administrators' => false], $account->getSettings());

        $membership = $this->em()->find(Membership::class, self::id('memberships.david_watercooler'));
        self::assertInstanceOf(Involvement::class, $membership?->getInvolvement());
        self::assertSame(self::id('rooms.watercooler'), $membership->getRoom()->getId());

        $blob = $this->em()->getRepository(Blob::class)->findOneBy([], ['id' => 'ASC']);
        self::assertSame('local', $blob?->getServiceName());
        self::assertTrue($blob->getMetadata()['identified'] ?? false);
        self::assertFileExists($this->storagePath.'/files/'.$blob->getRelativePath());
    }

    public function testDatetimesAreUtc(): void
    {
        $raw = (string) $this->connection()->fetchOne('SELECT created_at FROM messages ORDER BY id LIMIT 1');
        $message = $this->em()->getRepository(Message::class)->findOneBy([], ['id' => 'ASC']);

        self::assertSame('UTC', $message?->getCreatedAt()->getTimezone()->getName());
        self::assertSame($raw, $message->getCreatedAt()->format('Y-m-d H:i:s'));
    }
}
