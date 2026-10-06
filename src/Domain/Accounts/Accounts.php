<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Database\Transactions;
use App\Domain\Users\Bots;
use App\Entity\Account;
use App\Storage\Attachments;
use App\Storage\BlobService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Account behaviour (reference/app/models/account.rb, account/joinable.rb). */
final readonly class Accounts
{
    public function __construct(
        private EntityManagerInterface $em,
        private Transactions $transactions,
        private Attachments $attachments,
        private BlobService $blobs,
    ) {
    }

    /** `Account.create!(name:)`, with its join code (`before_create`). */
    public function create(string $name): Account
    {
        return $this->transactions->transaction(function () use ($name): Account {
            $account = new Account($name, self::generateJoinCode());
            $this->em->persist($account);
            $this->em->flush();

            return $account;
        });
    }

    /**
     * `account.update!(params)` for the permitted name, logo, settings and custom_styles.
     *
     * @param array<string, mixed> $attributes
     */
    public function update(Account $account, array $attributes): void
    {
        $this->transactions->transaction(function () use ($account, $attributes): void {
            if (\array_key_exists('name', $attributes)) {
                $account->setName(\is_scalar($attributes['name']) ? (string) $attributes['name'] : '');
            }
            if (\array_key_exists('custom_styles', $attributes)) {
                $account->setCustomStyles(\is_scalar($attributes['custom_styles']) ? (string) $attributes['custom_styles'] : null);
            }
            if (\is_array($attributes['settings'] ?? null)) {
                $account->assignSettings($attributes['settings']);
            }
            $this->em->flush();

            if (($attributes['logo'] ?? null) instanceof UploadedFile) {
                $this->attachments->attach('Account', $account->getId(), 'logo', $this->blobs->createFromUpload($attributes['logo']));
            }
        });
    }

    /** `account.reset_join_code` */
    public function resetJoinCode(Account $account): void
    {
        $this->transactions->transaction(function () use ($account): void {
            $account->setJoinCode(self::generateJoinCode());
            $this->em->flush();
        });
    }

    /** `SecureRandom.alphanumeric(12).scan(/.{4}/).join("-")` */
    public static function generateJoinCode(): string
    {
        return implode('-', str_split(Bots::alphanumeric(12), 4));
    }
}
