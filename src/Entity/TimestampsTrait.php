<?php

declare(strict_types=1);

namespace App\Entity;

use App\Database\Type\RailsDateTimeType;
use Doctrine\ORM\Mapping as ORM;

/**
 * `t.timestamps`: created_at and updated_at, set by App\Database\TimestampListener.
 * They are null only until the entity is first flushed.
 */
trait TimestampsTrait
{
    use CreatedAtTrait;

    #[ORM\Column(name: 'updated_at', type: RailsDateTimeType::NAME)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt ?? throw new \LogicException(\sprintf('%s has no updated_at until it is saved.', static::class));
    }

    /** Setting it explicitly keeps the listener from overwriting it, as in Rails. */
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        if ($this->updatedAt != $updatedAt) {
            $this->updatedAt = $updatedAt;
        }

        return $this;
    }

    /**
     * `touch` without saving: sets updated_at so the next flush writes it even when nothing else
     * changed. (Rails' `touch` also saves; callers flush, or use DBAL for `touch: true` chains.).
     */
    public function touch(\DateTimeImmutable $now): static
    {
        return $this->setUpdatedAt($now);
    }
}
