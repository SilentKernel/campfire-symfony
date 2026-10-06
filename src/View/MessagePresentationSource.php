<?php

declare(strict_types=1);

namespace App\View;

use App\Entity\Message;

/** Where MessageRenderer gets the presentation data of the messages the cache missed. */
interface MessagePresentationSource
{
    /**
     * @param list<Message> $messages
     *
     * @return array<int, MessagePresentation> keyed by message id
     */
    public function load(array $messages): array;
}
