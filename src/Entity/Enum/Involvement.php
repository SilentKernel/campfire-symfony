<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * `enum :involvement, %w[ invisible nothing mentions everything ].index_by(&:itself)`
 * (reference/app/models/membership.rb), stored as the string itself.
 */
enum Involvement: string
{
    case Invisible = 'invisible';
    case Nothing = 'nothing';
    case Mentions = 'mentions';
    case Everything = 'everything';
}
