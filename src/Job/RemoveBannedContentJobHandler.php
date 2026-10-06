<?php

declare(strict_types=1);

namespace App\Job;

use App\Domain\Messages\MessageDestroyer;
use App\Entity\Message;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * User::Bannable#remove_banned_content (reference/app/models/user/bannable.rb): every message the
 * user wrote is destroyed and removed from its room's page.
 */
#[AsMessageHandler]
final readonly class RemoveBannedContentJobHandler
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $em,
        private MessageDestroyer $destroyer,
    ) {
    }

    public function __invoke(RemoveBannedContentJob $job): void
    {
        // `user.messages.each`
        $ids = $this->connection->fetchFirstColumn('SELECT id FROM messages WHERE creator_id = ? ORDER BY id', [$job->userId]);
        foreach ($ids as $id) {
            $message = $this->em->find(Message::class, (int) $id);
            if (null === $message) {
                continue;
            }
            $this->destroyer->destroy($message);
            $this->destroyer->broadcastRemove($message);
        }
        $this->em->clear();
    }
}
