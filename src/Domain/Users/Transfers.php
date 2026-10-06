<?php

declare(strict_types=1);

namespace App\Domain\Users;

use App\Entity\User;
use App\Rails\SignedId;
use App\Repository\UserRepository;
use Symfony\Component\Clock\ClockInterface;

/** User::Transferable (reference/app/models/user/transferable.rb). */
final readonly class Transfers
{
    public const int TRANSFER_LINK_EXPIRY_DURATION = 4 * 3600;

    public function __construct(
        private SignedId $signedId,
        private UserRepository $users,
        private ClockInterface $clock,
    ) {
    }

    /** `user.transfer_id`: `signed_id(purpose: :transfer, expires_in: 4.hours)`. */
    public function transferId(User $user): string
    {
        $now = $this->clock->now();

        return $this->signedId->generate($user->getId(), 'User', 'transfer', $now->modify(\sprintf('+%d seconds', self::TRANSFER_LINK_EXPIRY_DURATION)));
    }

    /** `User.active.find_by_transfer_id(id)` */
    public function findActiveUser(string $transferId): ?User
    {
        $id = $this->signedId->find($transferId, 'User', 'transfer', $this->clock->now());

        return null === $id ? null : $this->users->findActive($id);
    }
}
