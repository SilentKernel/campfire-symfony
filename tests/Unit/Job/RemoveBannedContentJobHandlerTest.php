<?php

declare(strict_types=1);

namespace App\Tests\Unit\Job;

use App\Cable\Broadcaster;
use App\Cable\RecordingBroadcaster;
use App\Job\RemoveBannedContentJob;
use App\Job\RemoveBannedContentJobHandler;
use App\Tests\Support\CampfireTestCase;

/** User::Bannable#remove_banned_content over the seed. */
final class RemoveBannedContentJobHandlerTest extends CampfireTestCase
{
    public function testDestroysEveryMessageOfTheUserAndRemovesItFromItsRoom(): void
    {
        $jason = self::id('users.jason');
        $messages = $this->connection()->fetchAllKeyValue('SELECT id, client_message_id FROM messages WHERE creator_id = ? ORDER BY id', [$jason]);
        self::assertNotEmpty($messages);
        $others = (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM messages WHERE creator_id != ?', [$jason]);

        $handler = static::getContainer()->get(RemoveBannedContentJobHandler::class);
        \assert($handler instanceof RemoveBannedContentJobHandler);
        $handler(new RemoveBannedContentJob($jason));

        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM messages WHERE creator_id = ?', [$jason]));
        self::assertSame($others, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM messages'));
        $broadcaster = static::getContainer()->get(Broadcaster::class);
        \assert($broadcaster instanceof RecordingBroadcaster);
        $removals = array_values(array_filter(array_column($broadcaster->broadcasts, 'payload'), static fn (mixed $payload): bool => \is_string($payload) && str_contains($payload, 'action="remove"')));
        self::assertCount(\count($messages), $removals);
        self::assertStringContainsString('target="message_'.reset($messages).'"', $removals[0]);
    }
}
