<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Entity\ActiveStorage\Attachment;
use App\Entity\Message;
use App\Entity\PushSubscription;
use App\Entity\RichText;
use App\Entity\Room;
use App\Entity\Rooms;
use App\Entity\User;
use App\Twig\Html\RecordIdentifier;
use PHPUnit\Framework\TestCase;

/** Expected values were printed by the reference app (ActionView::RecordIdentifier, model_name.param_key). */
final class RecordIdentifierTest extends TestCase
{
    public function testStiSubclassesUseTheirOwnName(): void
    {
        $david = Records::saved(new User('David'), 127326141);

        self::assertSame('rooms_open_104393281', RecordIdentifier::domId(Records::saved(new Rooms\Open($david), 104393281)));
        self::assertSame('messages_rooms_open_201306877', RecordIdentifier::domId(Records::saved(new Rooms\Open($david), 201306877), 'messages'));
        self::assertSame('involvement_rooms_direct_7', RecordIdentifier::domId(Records::saved(new Rooms\Direct($david), 7), 'involvement'));
        self::assertSame('rooms_closed_3', RecordIdentifier::domId(Records::saved(new Rooms\Closed($david), 3)));
        self::assertSame('user_127326141', RecordIdentifier::domId($david));
    }

    public function testMessagesAreKeyedByClientMessageId(): void
    {
        $david = Records::saved(new User('David'), 1);
        $message = new Message(Records::saved(new Rooms\Open($david), 2), $david, 'abc');

        self::assertSame('message_abc', RecordIdentifier::domId($message));
        self::assertSame('x_message_abc', RecordIdentifier::domId(Records::saved($message, 13), 'x'));
    }

    public function testNewRecordsAndClasses(): void
    {
        self::assertSame('new_user', RecordIdentifier::domId(new User('New')));
        self::assertSame('edit_user', RecordIdentifier::domId(new User('New'), 'edit'));
        self::assertSame('new_message', RecordIdentifier::domId(Message::class));
        self::assertSame('room', RecordIdentifier::domClass(Room::class));
        self::assertSame('sidebar_rooms_open', RecordIdentifier::domClass(Rooms\Open::class, 'sidebar'));
    }

    public function testModelNames(): void
    {
        self::assertSame('push_subscription', RecordIdentifier::paramKey(PushSubscription::class));
        self::assertSame('rich_text', RecordIdentifier::paramKey(RichText::class));
        self::assertSame('attachment', RecordIdentifier::paramKey(Attachment::class));
        self::assertSame('Rooms::Open', RecordIdentifier::modelName(Rooms\Open::class));
    }
}
