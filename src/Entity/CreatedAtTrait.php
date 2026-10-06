<?php

declare(strict_types=1);

namespace App\Entity;

use App\Database\Type\RailsDateTimeType;
use Doctrine\ORM\Mapping as ORM;

/**
 * A table with only `created_at` (Active Storage blobs and attachments), set by
 * App\Database\TimestampListener. It is null only until the entity is first flushed.
 */
trait CreatedAtTrait
{
    #[ORM\Column(name: 'created_at', type: RailsDateTimeType::NAME)]
    private ?\DateTimeImmutable $createdAt = null;

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt ?? throw new \LogicException(\sprintf('%s has no created_at until it is saved.', static::class));
    }

    /** Setting it before the first flush keeps the listener from overwriting it, as in Rails. */
    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        if ($this->createdAt != $createdAt) {
            $this->createdAt = $createdAt;
        }

        return $this;
    }
}
