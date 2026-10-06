<?php

declare(strict_types=1);

namespace App\View;

use App\Domain\Messages\MessageRichText;
use App\Domain\Messages\Sound;
use App\Domain\Rooms\RoomNames;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\BoostRepository;
use App\Storage\Attachments;
use App\Storage\Filename;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * `Message.with_presentation` for the messages the fragment cache missed (reference commit
 * 659f957 "Preload only uncached messages"): one query per association, whatever the number of
 * messages.
 */
#[AsAlias(MessagePresentationSource::class)]
final readonly class MessagePresentationLoader implements MessagePresentationSource
{
    public function __construct(
        private EntityManagerInterface $em,
        private Connection $connection,
        private BoostRepository $boosts,
        private Attachments $attachments,
        private MessageRichText $richText,
        private RoomNames $roomDisplayNames,
    ) {
    }

    /**
     * @param list<Message> $messages
     *
     * @return array<int, MessagePresentation> keyed by message id
     */
    public function load(array $messages): array
    {
        if ([] === $messages) {
            return [];
        }
        $ids = array_map(static fn (Message $message): int => $message->getId(), $messages);

        $creators = $this->creators($messages);
        $bodies = $this->bodies($ids);
        $attachments = $this->attachments->forRecords(Message::RECORD_TYPE, $ids, 'attachment');
        $boosts = $this->boosts->findOrderedByMessageIds($ids);
        $roomNames = $this->roomNames($messages);

        $presentations = [];
        foreach ($messages as $message) {
            $id = $message->getId();
            $body = $bodies[$id] ?? null;
            $attachment = $attachments[$id] ?? null;
            try {
                $plainText = $this->plainTextBody($body, null !== $attachment ? (new Filename($attachment->getBlob()->getFilename()))->sanitized() : null);
                $failed = false;
            } catch (\Throwable) {
                // message_tag evaluates plain_text_body first and rescues what it raises.
                $plainText = '';
                $failed = true;
            }
            $presentations[$id] = new MessagePresentation(
                $message,
                $creators[$this->creatorId($message)] ?? null,
                $body,
                $attachment,
                $boosts[$id] ?? [],
                $roomNames[$message->getRoom()->getId()] ?? null,
                $plainText,
                Sound::forPlainText($plainText),
                $failed,
            );
        }

        return $presentations;
    }

    /** `plain_text_body`: the body's plain text, else the attachment's filename, else "". */
    public function plainTextBody(?string $body, ?string $filename): string
    {
        $text = null !== $body ? $this->richText->toPlainText($body) : '';
        if (1 !== preg_match('/\A[[:space:]]*\z/u', $text)) {
            return $text;
        }

        return $filename ?? '';
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, ?string>
     */
    private function bodies(array $ids): array
    {
        $rows = $this->connection->fetchAllKeyValue(
            "SELECT record_id, body FROM action_text_rich_texts WHERE record_type = 'Message' AND name = 'body' AND record_id IN (?)",
            [$ids],
            [ArrayParameterType::INTEGER],
        );
        $bodies = [];
        foreach ($rows as $id => $body) {
            $bodies[(int) $id] = null === $body ? null : (string) $body;
        }

        return $bodies;
    }

    /**
     * The creators, initialized in one query (a creator that no longer exists is missing).
     *
     * @param list<Message> $messages
     *
     * @return array<int, User>
     */
    private function creators(array $messages): array
    {
        $ids = array_values(array_unique(array_map($this->creatorId(...), $messages)));
        $users = [];
        foreach ($this->em->getRepository(User::class)->findBy(['id' => $ids]) as $user) {
            $users[$user->getId()] = $user;
        }

        return $users;
    }

    private function creatorId(Message $message): int
    {
        $creator = $message->getCreator();
        $id = $this->em->getUnitOfWork()->getEntityIdentifier($creator)['id'] ?? null;

        return (int) $id;
    }

    /**
     * `room_display_name(message.room, for_user: nil)` for each room (RoomNames): the name, or for
     * a direct room its members' names as a sentence (nil when it has none).
     *
     * @param list<Message> $messages
     *
     * @return array<int, ?string>
     */
    private function roomNames(array $messages): array
    {
        $names = [];
        foreach ($messages as $message) {
            $room = $message->getRoom();
            if (!\array_key_exists($room->getId(), $names)) {
                $name = $this->roomDisplayNames->displayName($room, null);
                $names[$room->getId()] = '' === $name && $room->isDirect() ? null : $name;
            }
        }

        return $names;
    }
}
