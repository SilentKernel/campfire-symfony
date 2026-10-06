<?php

declare(strict_types=1);

namespace App\RichText;

use App\Http\Current;
use App\RichText\Attachables\AttachableLocator;

/**
 * `Message::Mentionee#mentioned_users` (reference/app/models/message/mentionee.rb):
 * `body.body.attachables.grep(User).uniq`. Content#attachables uses ActionText::Attachable.from_node,
 * so only mentions whose SGID verifies (and whose user exists) count: Campfire's fallback for
 * possibly expired SGIDs applies to rendering, not to who gets notified. The caller narrows the
 * ids to the room's users (`room.users.where(id: …)`).
 */
final readonly class MentionExtractor
{
    public function __construct(
        private AttachableLocator $locator,
        private Current $current,
    ) {
    }

    /**
     * @return list<int> in order of first mention
     *
     * @throws RichTextError where Rails raises
     */
    public function userIds(?string $storedBodyHtml): array
    {
        if (null === $storedBodyHtml) {
            return [];
        }
        $context = new RenderContext($this->locator, $this->current->request()?->getHost() ?? '');

        return array_map(static fn ($user): int => $user->id, Content::load($storedBodyHtml, $context)->mentionedUsers($context));
    }
}
