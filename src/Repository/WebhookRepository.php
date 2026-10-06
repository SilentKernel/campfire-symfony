<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\Webhook;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Webhook> */
final class WebhookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Webhook::class);
    }

    /** `user.webhook` (`has_one`). */
    public function findOneForUser(User $user): ?Webhook
    {
        return $this->findOneBy(['user' => $user], ['id' => 'ASC']);
    }
}
