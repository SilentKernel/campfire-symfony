<?php

declare(strict_types=1);

namespace App\RichText\Attachables;

/** Lexxy's `ActionText::Attachables::RemoteVideo` */
final readonly class RemoteVideo
{
    public function __construct(
        public string $url,
        public string $contentType,
        public ?string $width,
        public ?string $height,
        public ?string $filename,
    ) {
    }
}
