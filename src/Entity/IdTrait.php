<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** The `id integer PRIMARY KEY AUTOINCREMENT` every Rails table has. */
trait IdTrait
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    private ?int $id = null;

    public function getId(): int
    {
        return $this->id ?? throw new \LogicException(\sprintf('%s has no id until it is saved.', static::class));
    }

    /** Active Record's `new_record?`. */
    public function isNewRecord(): bool
    {
        return null === $this->id;
    }

    /** Active Record's `==`: the same class and the same saved id. */
    public function isSameRecord(?object $other): bool
    {
        return $other === $this
            || ($other instanceof self && $other::class === static::class && null !== $this->id && $other->id === $this->id);
    }
}
