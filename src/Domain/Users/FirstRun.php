<?php

declare(strict_types=1);

namespace App\Domain\Users;

use App\Database\Transactions;
use App\Domain\Accounts\Accounts;
use App\Domain\Rooms\Memberships;
use App\Domain\Rooms\Rooms;
use App\Entity\Enum\UserRole;
use App\Entity\Rooms\Open;
use App\Entity\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/** FirstRun (reference/app/models/first_run.rb): the account, its administrator and first room. */
final readonly class FirstRun
{
    public const string ACCOUNT_NAME = 'Campfire';
    public const string FIRST_ROOM_NAME = 'All Talk';

    public function __construct(
        private Transactions $transactions,
        private Accounts $accounts,
        private Users $users,
        private Rooms $rooms,
        private Memberships $memberships,
    ) {
    }

    /**
     * `FirstRun.create!(user_params)`. A concurrent first run (the singleton account, or the
     * email address) raises RecordNotUnique.
     *
     * @param array{name?: mixed, email_address?: mixed, password?: mixed, avatar?: mixed} $userParams
     */
    public function create(array $userParams): User
    {
        try {
            [$administrator, $room] = $this->transactions->transaction(function () use ($userParams): array {
                $this->accounts->create(self::ACCOUNT_NAME);
                $administrator = $this->users->create($userParams, UserRole::Administrator);

                return [$administrator, $this->rooms->createFor(Open::class, self::FIRST_ROOM_NAME, [], $administrator)];
            });
        } catch (UniqueConstraintViolationException $error) {
            throw new RecordNotUnique($error->getMessage(), 0, $error);
        }

        // `room.memberships.grant_to administrator`
        $this->memberships->grantTo($room, [$administrator]);

        return $administrator;
    }
}
