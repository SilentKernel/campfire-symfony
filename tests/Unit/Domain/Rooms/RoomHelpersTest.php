<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Rooms;

use App\Domain\Rooms\Epoch;
use App\Domain\Rooms\RoomNames;
use App\Domain\Rooms\SidebarRoom;
use App\Entity\Rooms\Closed;
use App\Entity\Rooms\Direct;
use App\Entity\Rooms\Open;
use App\Entity\User;
use App\Twig\RoomsExtension;
use PHPUnit\Framework\TestCase;

final class RoomHelpersTest extends TestCase
{
    public function testToSentence(): void
    {
        self::assertSame('', RoomNames::toSentence([]));
        self::assertSame('Jason', RoomNames::toSentence(['Jason']));
        self::assertSame('Jason and Kevin', RoomNames::toSentence(['Jason', 'Kevin']));
        self::assertSame('Jason, Kevin, and JZ', RoomNames::toSentence(['Jason', 'Kevin', 'JZ']));
        self::assertSame('J+K', RoomNames::toSentence(['J', 'K'], '+'));
    }

    public function testDirectRoomNames(): void
    {
        self::assertSame('Jean', RoomsExtension::firstName('  Jean Paul  Sartre'));
        self::assertSame('', RoomsExtension::firstName(' '));
        $members = [new User('jean paul sartre extra'), new User('Ébène'), new User('Kevin')];
        self::assertSame('JPS, É, and K', RoomsExtension::directMembersInitials($members));
        self::assertSame('JPS+É', RoomsExtension::directMembersInitials(\array_slice($members, 0, 2)));
    }

    public function testEpoch(): void
    {
        self::assertSame(1772460000000, Epoch::milliseconds(new \DateTimeImmutable('2026-03-02 14:00:00 UTC')));
        self::assertSame(1772460000123, Epoch::milliseconds(new \DateTimeImmutable('2026-03-02 14:00:00.123456 UTC')));
    }

    public function testSidebarRoomDomIds(): void
    {
        $at = new \DateTimeImmutable('2026-03-02 14:00:00 UTC');
        self::assertSame('list_rooms_open_1', (new SidebarRoom(1, 'HQ', Open::TYPE, $at))->listDomId());
        self::assertSame('list_rooms_closed_2', (new SidebarRoom(2, 'X', Closed::TYPE, $at))->listDomId());
        self::assertSame('list_rooms_direct_3', (new SidebarRoom(3, null, Direct::TYPE, $at))->listDomId());
        self::assertTrue((new SidebarRoom(3, null, Direct::TYPE, $at))->isDirect());
    }
}
