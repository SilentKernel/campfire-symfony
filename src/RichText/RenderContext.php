<?php

declare(strict_types=1);

namespace App\RichText;

use App\RichText\Attachables\AttachableLocator;

/**
 * Everything loading and rendering rich text needs from the app and the request: the record
 * lookups and `Current.request_host` (reference/app/models/current.rb), which the opengraph
 * embed checks compare against ("" without a request, as `nil.to_s`).
 */
final readonly class RenderContext
{
    public function __construct(
        public AttachableLocator $locator,
        public string $requestHost = '',
    ) {
    }
}
