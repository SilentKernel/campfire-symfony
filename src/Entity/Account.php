<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** reference/app/models/account.rb: the single account (`singleton_guard` is unique and always 0). */
#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'accounts')]
#[ORM\HasLifecycleCallbacks]
final class Account implements Timestamped
{
    use IdTrait;
    use TimestampsTrait;

    /** `has_json :settings, restrict_room_creation_to_administrators: false` */
    public const SETTINGS_SCHEMA = ['restrict_room_creation_to_administrators' => false];

    #[ORM\Column(name: 'custom_styles', type: Types::TEXT, nullable: true)]
    private ?string $customStyles = null;

    /** @var array<string, bool|int|string|null>|null */
    #[ORM\Column(name: 'settings', type: Types::JSON, nullable: true)]
    private ?array $settings = null;

    #[ORM\Column(name: 'singleton_guard', type: Types::INTEGER)]
    private int $singletonGuard = 0;

    public function __construct(
        #[ORM\Column(name: 'name', type: Types::STRING)]
        private string $name,
        #[ORM\Column(name: 'join_code', type: Types::STRING)]
        private string $joinCode,
    ) {
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

    public function getJoinCode(): string
    {
        return $this->joinCode;
    }

    public function setJoinCode(string $joinCode): static
    {
        $this->joinCode = $joinCode;

        return $this;
    }

    public function getCustomStyles(): ?string
    {
        return $this->customStyles;
    }

    public function setCustomStyles(?string $customStyles): static
    {
        $this->customStyles = $customStyles;

        return $this;
    }

    public function getSingletonGuard(): int
    {
        return $this->singletonGuard;
    }

    /**
     * The settings with the schema defaults filled in, as `account.settings` reads them
     * (ActiveModel::SchematizedJson::DataAccessor).
     *
     * @return array<string, bool|int|string|null>
     */
    public function getSettings(): array
    {
        return ($this->settings ?? []) + self::SETTINGS_SCHEMA;
    }

    /**
     * The raw column, null when Rails never saved the account since `settings` was added.
     *
     * @return array<string, bool|int|string|null>|null
     */
    public function getStoredSettings(): ?array
    {
        return $this->settings;
    }

    /**
     * `account.settings = params`: each schema key is cast with its type (booleans the way
     * ActiveModel::Type::Boolean casts form values); unknown keys are rejected like Rails'
     * method_missing would.
     *
     * @param array<string, mixed> $data
     */
    public function assignSettings(array $data): static
    {
        $settings = $this->getSettings();
        foreach ($data as $key => $value) {
            if (!\array_key_exists($key, self::SETTINGS_SCHEMA)) {
                throw new \InvalidArgumentException(\sprintf('Unknown account setting "%s".', $key));
            }
            $settings[$key] = self::castBoolean($value);
        }
        $this->settings = $settings;

        return $this;
    }

    /** `settings.restrict_room_creation_to_administrators?`: the stored value's `present?`. */
    public function restrictsRoomCreationToAdministrators(): bool
    {
        $value = $this->getSettings()['restrict_room_creation_to_administrators'];

        return match (true) {
            null === $value, false === $value => false,
            \is_string($value) => 1 !== preg_match('/\A[\s\x{85}\p{Z}]*\z/u', $value),
            default => true,
        };
    }

    /** The `before_save` has_json adds: the schema defaults are written along with the record. */
    #[ORM\PrePersist]
    public function writeSettingsDefaults(): void
    {
        $this->settings = $this->getSettings();
    }

    /** ActiveModel::Type::Boolean#cast_value: blank is nil, FALSE_VALUES are false, anything else true. */
    private static function castBoolean(mixed $value): ?bool
    {
        if (\is_bool($value) || null === $value) {
            return $value;
        }
        if ('' === $value) {
            return null;
        }

        return !\in_array($value, [0, '0', 'f', 'F', 'false', 'FALSE', 'off', 'OFF'], true);
    }
}
