<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SearchRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** reference/app/models/search.rb: a user's recent search query. */
#[ORM\Entity(repositoryClass: SearchRepository::class)]
#[ORM\Table(name: 'searches')]
final class Search implements Timestamped
{
    use IdTrait;
    use TimestampsTrait;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
        private User $user,
        #[ORM\Column(name: 'query', type: Types::STRING)]
        private string $query,
    ) {
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getQuery(): string
    {
        return $this->query;
    }
}
