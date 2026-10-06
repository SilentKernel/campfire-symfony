<?php

declare(strict_types=1);

namespace App\Database;

/** The database predates some Rails migrations; the Rails image must migrate it first. */
final class PendingMigrations extends \RuntimeException
{
    /** @param list<string> $versions */
    public function __construct(public readonly array $versions)
    {
        parent::__construct(\sprintf(
            'The database is missing Rails migrations %s. Run the Rails image once on this storage (bin/rails db:prepare) to migrate it.',
            implode(', ', $versions),
        ));
    }
}
