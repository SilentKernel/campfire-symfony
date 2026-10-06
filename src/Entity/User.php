<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\UserRole;
use App\Entity\Enum\UserStatus;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** reference/app/models/user.rb and its concerns (user/role.rb, user/bot.rb, …). */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
final class User implements Timestamped, UserInterface, PasswordAuthenticatedUserInterface
{
    use IdTrait;
    use TimestampsTrait;

    /** User::Mentionable::MENTION_CONTENT_TYPE */
    public const MENTION_CONTENT_TYPE = 'application/vnd.campfire.mention';

    /**
     * Characters Ruby's \b treats as word characters (Onigmo's Unicode word: Alphabetic, Mark,
     * Decimal_Number, Connector_Punctuation, Join_Control, plus Latin-1's ¹²³¼½¾), checked against
     * every code point Ruby 3.4 knows. \w itself stays ASCII.
     */
    private const RUBY_BOUNDARY_WORD = '\p{Alphabetic}\p{M}\p{Nd}\p{Pc}\x{200C}\x{200D}\x{B2}\x{B3}\x{B9}\x{BC}-\x{BE}';

    #[ORM\Column(name: 'email_address', type: Types::STRING, nullable: true)]
    private ?string $emailAddress = null;

    #[ORM\Column(name: 'password_digest', type: Types::STRING, nullable: true)]
    private ?string $passwordDigest = null;

    #[ORM\Column(name: 'role', type: Types::INTEGER, enumType: UserRole::class)]
    private UserRole $role = UserRole::Member;

    #[ORM\Column(name: 'status', type: Types::INTEGER, enumType: UserStatus::class)]
    private UserStatus $status = UserStatus::Active;

    #[ORM\Column(name: 'bio', type: Types::TEXT, nullable: true)]
    private ?string $bio = null;

    #[ORM\Column(name: 'bot_token', type: Types::STRING, nullable: true)]
    private ?string $botToken = null;

    /** @var Collection<int, Membership> */
    #[ORM\OneToMany(targetEntity: Membership::class, mappedBy: 'user', fetch: 'EXTRA_LAZY')]
    private Collection $memberships;

    public function __construct(
        #[ORM\Column(name: 'name', type: Types::STRING)]
        private string $name,
    ) {
        $this->memberships = new ArrayCollection();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getEmailAddress(): ?string
    {
        return $this->emailAddress;
    }

    public function setEmailAddress(?string $emailAddress): static
    {
        $this->emailAddress = $emailAddress;

        return $this;
    }

    /** The bcrypt digest `has_secure_password` stores. */
    public function getPasswordDigest(): ?string
    {
        return $this->passwordDigest;
    }

    public function setPasswordDigest(?string $passwordDigest): static
    {
        $this->passwordDigest = $passwordDigest;

        return $this;
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function setRole(UserRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getStatus(): UserStatus
    {
        return $this->status;
    }

    public function setStatus(UserStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): static
    {
        $this->bio = $bio;

        return $this;
    }

    public function getBotToken(): ?string
    {
        return $this->botToken;
    }

    public function setBotToken(?string $botToken): static
    {
        $this->botToken = $botToken;

        return $this;
    }

    /** @return Collection<int, Membership> */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    public function isMember(): bool
    {
        return UserRole::Member === $this->role;
    }

    public function isAdministrator(): bool
    {
        return UserRole::Administrator === $this->role;
    }

    public function isBot(): bool
    {
        return UserRole::Bot === $this->role;
    }

    public function isActive(): bool
    {
        return UserStatus::Active === $this->status;
    }

    public function isDeactivated(): bool
    {
        return UserStatus::Deactivated === $this->status;
    }

    public function isBanned(): bool
    {
        return UserStatus::Banned === $this->status;
    }

    /**
     * `can_administer?(record = nil)` (user/role.rb): administrators, the record's creator, and
     * anyone for a record that isn't saved yet.
     */
    public function canAdminister(Room|Message|null $record = null): bool
    {
        if ($this->isAdministrator()) {
            return true;
        }
        if (null === $record) {
            return false;
        }

        return $this->isSameRecord($record->getCreator()) || $record->isNewRecord();
    }

    /** `name.scan(/\b\w/).join`: Ruby's \w is ASCII, its \b is Unicode-aware. */
    public function initials(): string
    {
        preg_match_all('/(?<!['.self::RUBY_BOUNDARY_WORD.'])[A-Za-z0-9_]/u', $this->name, $matches);

        return implode('', $matches[0]);
    }

    /** `[ name, bio ].compact_blank.join(" – ")` */
    public function title(): string
    {
        return implode(' – ', array_filter([$this->name, $this->bio], static fn (?string $part): bool => !self::isBlank($part)));
    }

    /** User::Bot#bot_key: `"#{id}-#{bot_token}"`. */
    public function botKey(): string
    {
        return $this->getId().'-'.$this->botToken;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->getId();
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return match ($this->role) {
            UserRole::Member => ['ROLE_USER'],
            UserRole::Administrator => ['ROLE_USER', 'ROLE_ADMINISTRATOR'],
            UserRole::Bot => ['ROLE_USER', 'ROLE_BOT'],
        };
    }

    public function getPassword(): ?string
    {
        return $this->passwordDigest;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }

    /** ActiveSupport's String#blank?: empty or only Unicode whitespace. */
    private static function isBlank(?string $value): bool
    {
        return null === $value || 1 === preg_match('/\A[\s\x{85}\p{Z}]*\z/u', $value);
    }
}
