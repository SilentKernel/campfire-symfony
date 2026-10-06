<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Tests\Support\SeedDatabase;

final class SeedHelper
{
    /**
     * @param list<mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(string $storagePath, string $sql, array $params = []): array
    {
        return SeedDatabase::connect($storagePath)->fetchAllAssociative($sql, $params);
    }
}
