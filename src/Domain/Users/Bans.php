<?php

declare(strict_types=1);

namespace App\Domain\Users;

use App\Database\Transactions;
use App\Entity\Ban;
use App\Entity\Enum\UserStatus;
use App\Entity\User;
use App\Job\RemoveBannedContentJob;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * User::Bannable (reference/app/models/user/bannable.rb) and Ban's validation
 * (reference/app/models/ban.rb).
 */
final readonly class Bans
{
    public function __construct(
        private EntityManagerInterface $em,
        private Connection $connection,
        private Transactions $transactions,
        private Users $users,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * `user.ban`: a ban for every distinct IP address of the user's sessions (each must be a
     * public address, or the whole ban rolls back with RecordInvalid, 422), then close their
     * connections, delete their sessions, remove their messages later, and mark them banned.
     */
    public function ban(User $user): void
    {
        $this->transactions->transaction(function () use ($user): void {
            $ips = $this->connection->fetchFirstColumn('SELECT "sessions"."ip_address" FROM "sessions" WHERE "sessions"."user_id" = ?', [$user->getId()]);
            // `compact_blank.uniq`
            $ips = array_values(array_unique(array_filter(array_map(static fn (mixed $ip): string => (string) $ip, $ips), static fn (string $ip): bool => 1 !== preg_match('/\A[[:space:]]*\z/u', $ip))));
            foreach ($ips as $ip) {
                $error = self::ipAddressError($ip);
                if (null !== $error) {
                    throw new RecordInvalid('Validation failed: Ip address '.$error);
                }
                $this->em->persist(new Ban($user, $ip));
                $this->em->flush();
            }

            $this->users->closeRemoteConnections($user);
            $this->connection->executeStatement('DELETE FROM "sessions" WHERE "sessions"."user_id" = ?', [$user->getId()]);
            $this->removeBannedContentLater($user);

            $user->setStatus(UserStatus::Banned);
            $this->em->flush();
        });
    }

    /** `user.unban`: delete the user's bans and mark them active. */
    public function unban(User $user): void
    {
        $this->transactions->transaction(function () use ($user): void {
            $this->connection->executeStatement('DELETE FROM "bans" WHERE "bans"."user_id" = ?', [$user->getId()]);
            $user->setStatus(UserStatus::Active);
            $this->em->flush();
        });
    }

    /** `RemoveBannedContentJob.perform_later(self)`, enqueued once the transaction commits. */
    public function removeBannedContentLater(User $user): void
    {
        $userId = $user->getId();
        $this->transactions->afterCommit(fn () => $this->bus->dispatch(new RemoveBannedContentJob($userId)));
    }

    /**
     * Ban#ip_address_is_public: the validation error for $ip, or null when it is a public
     * address. Mirrors Ruby's IPAddr (zone ids and /prefix accepted; IPv4-mapped IPv6 addresses
     * judged by their IPv4 part).
     */
    public static function ipAddressError(string $ip): ?string
    {
        $binary = self::parse($ip);
        if (null === $binary) {
            return 'is not a valid IP address';
        }

        return self::loopback($binary) || self::private($binary) || self::linkLocal($binary) ? 'cannot be a private or internal IP address' : null;
    }

    private static function parse(string $ip): ?string
    {
        $prefix = null;
        if (str_contains($ip, '/')) {
            [$ip, $prefix] = explode('/', $ip, 2);
        }
        if (str_contains($ip, '%') && str_contains($ip, ':')) {
            $ip = explode('%', $ip, 2)[0];
        }
        if (str_starts_with($ip, '[') && str_ends_with($ip, ']')) {
            $ip = substr($ip, 1, -1);
        }
        if (false === filter_var($ip, \FILTER_VALIDATE_IP)) {
            return null;
        }
        $binary = (string) inet_pton($ip);
        if (null !== $prefix) {
            $bits = \strlen($binary) * 8;
            if (1 !== preg_match('/\A\d+\z/', $prefix) || (int) $prefix > $bits) {
                return null;
            }
            $mask = str_repeat("\xff", intdiv((int) $prefix, 8));
            if (0 !== (int) $prefix % 8) {
                $mask .= \chr((0xFF << (8 - (int) $prefix % 8)) & 0xFF);
            }
            $binary &= str_pad($mask, \strlen($binary), "\0");
        }

        return $binary;
    }

    /** The IPv4 bytes of an IPv4 address, or of an IPv6 one with bits 32-47 all set (::ffff:a.b.c.d). */
    private static function v4(string $binary): ?string
    {
        if (4 === \strlen($binary)) {
            return $binary;
        }

        return "\xff\xff" === substr($binary, 10, 2) ? substr($binary, 12, 4) : null;
    }

    private static function loopback(string $binary): bool
    {
        if (16 === \strlen($binary) && str_repeat("\0", 15)."\x01" === $binary) {
            return true;
        }
        $v4 = self::v4($binary);

        return null !== $v4 && "\x7f" === $v4[0];
    }

    private static function private(string $binary): bool
    {
        if (16 === \strlen($binary) && 0xFC === (\ord($binary[0]) & 0xFE)) {
            return true;
        }
        $v4 = self::v4($binary);

        return null !== $v4 && (10 === \ord($v4[0]) || (172 === \ord($v4[0]) && 16 === (\ord($v4[1]) & 0xF0)) || (192 === \ord($v4[0]) && 168 === \ord($v4[1])));
    }

    private static function linkLocal(string $binary): bool
    {
        if (16 === \strlen($binary) && 0xFE === \ord($binary[0]) && 0x80 === (\ord($binary[1]) & 0xC0)) {
            return true;
        }
        $v4 = self::v4($binary);

        return null !== $v4 && 169 === \ord($v4[0]) && 254 === \ord($v4[1]);
    }
}
