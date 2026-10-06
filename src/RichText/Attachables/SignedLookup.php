<?php

declare(strict_types=1);

namespace App\RichText\Attachables;

/**
 * What `GlobalID::Locator.locate_signed(sgid, for: "attachable")` found: a user, or a signature
 * that verified for a record that isn't there (or isn't a user), or nothing.
 */
final readonly class SignedLookup
{
    public function __construct(public ?MentionUser $user = null, public ?string $missingModel = null)
    {
    }
}
