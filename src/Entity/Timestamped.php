<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * An entity whose table has Rails timestamp columns: App\Database\TimestampListener fills its
 * mapped `createdAt` (and `updatedAt`, when the table has one) the way ActiveRecord::Timestamp
 * does. Use TimestampsTrait (created_at + updated_at) or CreatedAtTrait (created_at only).
 */
interface Timestamped
{
    public function getCreatedAt(): \DateTimeImmutable;
}
