<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PushSubscription> */
final class PushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushSubscription::class);
    }

    /** @return list<PushSubscription> */
    public function findForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['id' => 'ASC']);
    }

    /** `user.push_subscriptions.find_by(endpoint:, p256dh_key:, auth_key:)` */
    public function findOneForUserByKeys(User $user, ?string $endpoint, ?string $p256dhKey, ?string $authKey): ?PushSubscription
    {
        return $this->findOneBy(['user' => $user, 'endpoint' => $endpoint, 'p256dhKey' => $p256dhKey, 'authKey' => $authKey]);
    }
}
