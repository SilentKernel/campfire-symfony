<?php

declare(strict_types=1);

namespace App\Domain\Users;

use App\Cable\Broadcaster;
use App\Database\Transactions;
use App\Domain\Rooms\Memberships;
use App\Entity\Enum\Involvement;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\UserStatus;
use App\Entity\User;
use App\Rails\Password;
use App\Repository\RoomRepository;
use App\Storage\Attachments;
use App\Storage\BlobService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * User model behaviour (reference/app/models/user.rb and user/*.rb) that touches several records:
 * creation (granting the open rooms after commit), profile and role updates, deactivation.
 */
final readonly class Users
{
    public function __construct(
        private EntityManagerInterface $em,
        private Connection $connection,
        private Transactions $transactions,
        private Memberships $memberships,
        private RoomRepository $rooms,
        private Broadcaster $broadcaster,
        private Attachments $attachments,
        private BlobService $blobs,
    ) {
    }

    /**
     * `User.create!(attributes)`, then `after_create_commit :grant_membership_to_open_rooms`.
     * A duplicate email address raises RecordNotUnique (ActiveRecord::RecordNotUnique).
     *
     * @param array{name?: mixed, email_address?: mixed, password?: mixed, avatar?: mixed, bio?: mixed} $attributes
     */
    public function create(array $attributes, UserRole $role = UserRole::Member, ?string $botToken = null): User
    {
        return $this->transactions->transaction(function () use ($attributes, $role, $botToken): User {
            $user = new User(self::string($attributes['name'] ?? null) ?? '');
            $user->setRole($role);
            $user->setBotToken($botToken);
            $this->assign($user, $attributes);

            // The unique index on users.email_address, checked first so the unit of work stays usable.
            $this->ensureUniqueEmailAddress($user);
            try {
                $this->em->persist($user);
                $this->em->flush();
            } catch (UniqueConstraintViolationException $error) {
                throw new RecordNotUnique($error->getMessage(), 0, $error);
            }

            if (($attributes['avatar'] ?? null) instanceof UploadedFile) {
                $this->attachments->attach('User', $user->getId(), 'avatar', $this->blobs->createFromUpload($attributes['avatar']));
            }

            $userId = $user->getId();
            $this->transactions->afterCommit(fn () => $this->grantMembershipToOpenRooms($userId));

            return $user;
        });
    }

    /**
     * `user.update(attributes)` with the profile's permitted keys (name, avatar, email_address,
     * password, bio), nils already dropped (`.compact`).
     *
     * @param array<string, mixed> $attributes
     */
    public function update(User $user, array $attributes): void
    {
        $this->transactions->transaction(function () use ($user, $attributes): void {
            if (\array_key_exists('name', $attributes)) {
                $user->setName(self::string($attributes['name']) ?? '');
            }
            $this->assign($user, $attributes);

            $this->ensureUniqueEmailAddress($user);
            try {
                $this->em->flush();
            } catch (UniqueConstraintViolationException $error) {
                throw new RecordNotUnique($error->getMessage(), 0, $error);
            }

            if (($attributes['avatar'] ?? null) instanceof UploadedFile) {
                $this->attachments->attach('User', $user->getId(), 'avatar', $this->blobs->createFromUpload($attributes['avatar']));
            }
        });
    }

    /** `user.update(role:)` */
    public function updateRole(User $user, UserRole $role): void
    {
        $this->transactions->transaction(function () use ($user, $role): void {
            $user->setRole($role);
            $this->em->flush();
        });
    }

    /**
     * `user.deactivate`: close the user's connections, delete their memberships of non-direct
     * rooms, push subscriptions, searches and sessions, then mark them deactivated with an email
     * address that frees the original one.
     */
    public function deactivate(User $user): void
    {
        $this->transactions->transaction(function () use ($user): void {
            $id = $user->getId();
            $this->closeRemoteConnections($user);

            $this->connection->executeStatement(
                'DELETE FROM "memberships" WHERE "memberships"."id" IN (SELECT "memberships"."id" FROM "memberships" INNER JOIN "rooms" "room" ON "room"."id" = "memberships"."room_id" WHERE "memberships"."user_id" = ? AND "room"."type" != ?)',
                [$id, 'Rooms::Direct'],
            );
            $this->connection->executeStatement('DELETE FROM "push_subscriptions" WHERE "push_subscriptions"."user_id" = ?', [$id]);
            $this->connection->executeStatement('DELETE FROM "searches" WHERE "searches"."user_id" = ?', [$id]);
            $this->connection->executeStatement('DELETE FROM "sessions" WHERE "sessions"."user_id" = ?', [$id]);

            $user->setStatus(UserStatus::Deactivated);
            $user->setEmailAddress(self::deactivatedEmailAddress($user->getEmailAddress()));
            $this->em->flush();
        });
    }

    /** `close_remote_connections(reconnect:)` */
    public function closeRemoteConnections(User $user, bool $reconnect = false): void
    {
        $this->broadcaster->disconnectUser($user->getId(), $reconnect);
    }

    /** `email_address&.gsub(/@/, "-deactivated-#{SecureRandom.uuid}@")` */
    public static function deactivatedEmailAddress(?string $emailAddress): ?string
    {
        return null === $emailAddress ? null : str_replace('@', '-deactivated-'.Uuid::v4()->toRfc4122().'@', $emailAddress);
    }

    /** `grant_membership_to_open_rooms`: `Membership.insert_all` with the column default involvement. */
    private function grantMembershipToOpenRooms(int $userId): void
    {
        foreach ($this->rooms->findOpenIds() as $roomId) {
            $this->memberships->insertAll($roomId, Involvement::Mentions, [$userId]);
        }
    }

    /**
     * has_secure_password (validations: false): nil clears the digest, "" leaves it unchanged,
     * anything else is hashed with bcrypt.
     *
     * @param array<string, mixed> $attributes
     */
    private function assign(User $user, array $attributes): void
    {
        if (\array_key_exists('email_address', $attributes)) {
            $user->setEmailAddress(self::string($attributes['email_address']));
        }
        if (\array_key_exists('bio', $attributes)) {
            $user->setBio(self::string($attributes['bio']));
        }
        if (\array_key_exists('password', $attributes)) {
            $password = self::string($attributes['password']);
            if (null === $password) {
                $user->setPasswordDigest(null);
            } elseif ('' !== $password) {
                $user->setPasswordDigest(Password::hash($password));
            }
        }
    }

    private function ensureUniqueEmailAddress(User $user): void
    {
        $email = $user->getEmailAddress();
        if (null === $email) {
            return;
        }
        $id = $this->connection->fetchOne('SELECT "id" FROM "users" WHERE "email_address" = ? LIMIT 1', [$email]);
        if (false !== $id && ($user->isNewRecord() || (int) $id !== $user->getId())) {
            throw new RecordNotUnique('UNIQUE constraint failed: users.email_address');
        }
    }

    private static function string(mixed $value): ?string
    {
        return \is_scalar($value) ? (string) $value : null;
    }
}
