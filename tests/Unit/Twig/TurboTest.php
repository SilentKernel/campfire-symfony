<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Entity\Rooms;
use App\Entity\User;
use App\Rails\KeyGenerator;
use App\Rails\TurboStreamName;
use App\Twig\Html\TurboStream;
use App\Twig\TurboExtension;
use PHPUnit\Framework\TestCase;
use Twig\Markup;

/**
 * Expected markup is the reference app's, rendered with SECRET_KEY_BASE=x on the parity seed
 * (rooms/show and users/sidebars/show; see tests/fixtures/rails/layout/).
 */
final class TurboTest extends TestCase
{
    private const ROOM_MESSAGES = '<turbo-cable-stream-source channel="RoomMessagesChannel" signed-stream-name="IloybGtPaTh2WTJGdGNHWnBjbVV2VW05dmJYTTZPazl3Wlc0dk1qQXhNekEyT0RjMzptZXNzYWdlcyI=--9d73d58eb6d0aecba5edb04822a8e647769bbb2f675118b3bf58ed617c14da51"></turbo-cable-stream-source>';
    private const ROOMS = '<turbo-cable-stream-source channel="Turbo::StreamsChannel" signed-stream-name="InJvb21zIg==--715f7fd871801fa6af8faf65933375566fce691ac28247400e4f6048715db41c"></turbo-cable-stream-source>';
    private const USER_ROOMS = '<turbo-cable-stream-source channel="Turbo::StreamsChannel" signed-stream-name="IloybGtPaTh2WTJGdGNHWnBjbVV2VlhObGNpOHhNamN6TWpZeE5ERTpyb29tcyI=--1887041bd63d55fd3f31752223f6720d442e0bf451ea08a9015e8024184a717b"></turbo-cable-stream-source>';

    public function testTurboStreamFromMatchesRails(): void
    {
        $david = Records::saved(new User('David'), 127326141);
        $hq = Records::saved(new Rooms\Open($david), 201306877);
        $turbo = new TurboExtension(new FakeViewContext(streamNames: new TurboStreamName(new KeyGenerator('x'))));

        self::assertSame(self::ROOM_MESSAGES, $turbo->turboStreamFrom([$hq, 'messages', ['channel' => 'RoomMessagesChannel']]));
        self::assertSame(self::ROOMS, $turbo->turboStreamFrom(['rooms']));
        self::assertSame(self::USER_ROOMS, $turbo->turboStreamFrom([$david, 'rooms']));
    }

    public function testStreamNamesUseGlobalIdParams(): void
    {
        $hq = Records::saved(new Rooms\Open(Records::saved(new User('David'), 1)), 201306877);

        self::assertSame(['Z2lkOi8vY2FtcGZpcmUvUm9vbXM6Ok9wZW4vMjAxMzA2ODc3', 'messages'], TurboStream::streamNameParts([$hq, 'messages']));
    }

    public function testBlankStreamablesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TurboExtension(new FakeViewContext()))->turboStreamFrom(['', null]);
    }

    public function testFrameTag(): void
    {
        $room = Records::saved(new Rooms\Open(Records::saved(new User('David'), 1)), 201306877);

        self::assertSame(
            '<turbo-frame data-controller="turbo-frame" data-turbo-frame-url-param="/rooms/201306877/involvement" id="involvement_rooms_open_201306877"><b></b></turbo-frame>',
            TurboStream::frameTag([$room, 'involvement'], ['data' => ['controller' => 'turbo-frame', 'turbo_frame_url_param' => '/rooms/201306877/involvement']], new Markup('<b></b>', 'UTF-8')),
        );
        self::assertSame('<turbo-frame id="composer-frame"></turbo-frame>', TurboStream::frameTag('composer-frame'));
        self::assertSame('<turbo-frame id="a_b" src="/x" target="_top"></turbo-frame>', TurboStream::frameTag(['a', 'b'], ['target' => '_top', 'src' => '/x']));
    }

    public function testStreamActions(): void
    {
        $room = Records::saved(new Rooms\Open(Records::saved(new User('David'), 1)), 5);

        self::assertSame('<turbo-stream action="append" target="messages"><template><p>hi</p></template></turbo-stream>', TurboStream::action('append', 'messages', '<p>hi</p>'));
        self::assertSame('<turbo-stream method="morph" action="replace" target="rooms_open_5"><template></template></turbo-stream>', TurboStream::action('replace', $room, '', 'morph'));
        self::assertSame('<turbo-stream action="remove" target="sidebar_rooms_open_5"></turbo-stream>', TurboStream::actionTag('remove', [$room, 'sidebar']));
        self::assertSame('<turbo-stream action="update" targets="#rooms_open_5"><template>x</template></turbo-stream>', TurboStream::actionAll('update', $room, 'x'));
        self::assertSame('<turbo-stream request-id="abc" action="refresh"></turbo-stream>', TurboStream::refresh('abc'));
    }

    public function testDriveHelpers(): void
    {
        self::assertSame('<meta name="turbo-cache-control" content="no-preview">', TurboExtension::previewControl());
        self::assertSame('<meta name="turbo-visit-control" content="reload">', TurboExtension::requiresReload());
    }
}
