<?php

declare(strict_types=1);

namespace App\RichText\Attachables;

/** `ActionText::Attachables::ContentAttachment` */
final readonly class ContentAttachment
{
    public function __construct(public string $content)
    {
    }
}
