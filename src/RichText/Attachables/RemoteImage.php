<?php

declare(strict_types=1);

namespace App\RichText\Attachables;

/** `ActionText::Attachables::RemoteImage` */
final readonly class RemoteImage
{
    public function __construct(public string $url, public ?string $width, public ?string $height)
    {
    }
}
