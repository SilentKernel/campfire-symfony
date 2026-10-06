<?php

declare(strict_types=1);

namespace App\RichText\Attachables;

/**
 * `ActionText::Attachables::MissingAttachable`, remembering the model a still-valid SGID named.
 */
final readonly class MissingAttachable
{
    public function __construct(public ?string $signedModel = null)
    {
    }
}
