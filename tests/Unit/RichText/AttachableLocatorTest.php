<?php

declare(strict_types=1);

namespace App\Tests\Unit\RichText;

use App\Rails\KeyGenerator;
use App\Rails\SignedGlobalId;
use App\RichText\Attachables\AttachableLocator;
use App\RichText\Attachables\MentionUser;
use App\RichText\Attachables\MentionUsers;
use App\RichText\RichTextError;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Campfire's fallback for SGIDs whose signature doesn't verify
 * (reference/lib/rails_ext/action_text_attachables.rb), against tests/vectors/rails_compat.json's
 * `unverified_sgids`, where users 1 and 2 exist.
 */
final class AttachableLocatorTest extends TestCase
{
    public function testFindsUsersThroughPossiblyExpiredSgidsLikeRails(): void
    {
        $vectors = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/vectors/rails_compat.json'), true, 512, \JSON_THROW_ON_ERROR);
        $users = new class implements MentionUsers {
            public function find(int $id): ?MentionUser
            {
                return \in_array($id, [1, 2], true) ? new MentionUser($id, 'User '.$id, '', '') : null;
            }
        };
        $locator = new AttachableLocator(new SignedGlobalId(new KeyGenerator($vectors['secret_key_base'])), $users, new MockClock());
        foreach ($vectors['unverified_sgids'] as $case) {
            try {
                $found = $locator->userFromPossiblyExpiredSgid($case['sgid'])?->id;
            } catch (RichTextError) {
                $this->assertIsArray($case['expected'], $case['case'].' raises only in the port');
                continue;
            }
            $this->assertSame(\is_string($case['expected']) ? (int) substr($case['expected'], strrpos($case['expected'], '/') + 1) : null, $found, $case['case']);
        }
    }
}
