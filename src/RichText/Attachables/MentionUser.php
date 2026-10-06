<?php

declare(strict_types=1);

namespace App\RichText\Attachables;

/**
 * What rendering a mention needs from a User (User::Mentionable): its id and name for plain text
 * and mentions, and for reference/app/views/users/_mention.html.erb its fresh attachable SGID
 * and its `avatar_tag` markup.
 */
final readonly class MentionUser
{
    public function __construct(
        public int $id,
        public string $name,
        /** `user.attachable_sgid` */
        public string $attachableSgid,
        /** `avatar_tag(user)` (reference/app/helpers/users/avatars_helper.rb), already HTML */
        public string $avatarTag,
    ) {
    }
}
