<?php

declare(strict_types=1);

namespace App\RichText;

/**
 * Something Ruby would have raised while loading or rendering a rich text body. Callers mirror
 * what the Rails code does with the exception: `message_presentation` rescues everything and
 * renders "", while `Message#plain_text_body` and the editor let it propagate.
 */
class RichTextError extends \RuntimeException
{
}
