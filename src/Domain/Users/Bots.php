<?php

declare(strict_types=1);

namespace App\Domain\Users;

use App\Database\Transactions;
use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Entity\Webhook;
use App\Repository\WebhookRepository;
use Doctrine\ORM\EntityManagerInterface;

/** User::Bot (reference/app/models/user/bot.rb): bot accounts, their keys and webhooks. */
final readonly class Bots
{
    public function __construct(
        private EntityManagerInterface $em,
        private Transactions $transactions,
        private Users $users,
        private WebhookRepository $webhooks,
    ) {
    }

    /**
     * `User.create_bot!(attributes)`: a bot user with a fresh token, and a webhook when a
     * webhook_url was given (even blank: `if webhook_url`).
     *
     * @param array{name?: mixed, avatar?: mixed, webhook_url?: mixed} $attributes
     */
    public function create(array $attributes): User
    {
        return $this->transactions->transaction(function () use ($attributes): User {
            $webhookUrl = $attributes['webhook_url'] ?? null;
            unset($attributes['webhook_url']);

            $bot = $this->users->create($attributes, UserRole::Bot, self::generateBotToken());
            if (null !== $webhookUrl) {
                $this->em->persist(new Webhook($bot, \is_scalar($webhookUrl) ? (string) $webhookUrl : null));
                $this->em->flush();
            }

            return $bot;
        });
    }

    /**
     * `bot.update_bot!(attributes)`: a present webhook_url updates (or creates) the webhook, a
     * missing or blank one deletes it; then the other attributes.
     *
     * @param array{name?: mixed, avatar?: mixed, webhook_url?: mixed} $attributes
     */
    public function update(User $bot, array $attributes): void
    {
        $this->transactions->transaction(function () use ($bot, $attributes): void {
            $url = $attributes['webhook_url'] ?? null;
            unset($attributes['webhook_url']);
            $webhook = $this->webhooks->findOneForUser($bot);

            if (\is_scalar($url) && 1 !== preg_match('/\A[[:space:]]*\z/u', (string) $url)) {
                if (null !== $webhook) {
                    $webhook->setUrl((string) $url);
                } else {
                    $this->em->persist(new Webhook($bot, (string) $url));
                }
            } elseif (null !== $webhook) {
                $this->em->remove($webhook);
            }
            $this->em->flush();

            $this->users->update($bot, $attributes);
        });
    }

    /** `bot.reset_bot_key` */
    public function resetBotKey(User $bot): void
    {
        $this->transactions->transaction(function () use ($bot): void {
            $bot->setBotToken(self::generateBotToken());
            $this->em->flush();
        });
    }

    /** `SecureRandom.alphanumeric(12)` */
    public static function generateBotToken(): string
    {
        return self::alphanumeric(12);
    }

    public static function alphanumeric(int $length): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $token = '';
        for ($i = 0; $i < $length; ++$i) {
            $token .= $alphabet[random_int(0, 61)];
        }

        return $token;
    }
}
