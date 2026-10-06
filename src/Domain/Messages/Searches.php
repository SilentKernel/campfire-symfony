<?php

declare(strict_types=1);

namespace App\Domain\Messages;

use App\Database\Transactions;
use App\Database\Type\RailsDateTimeType;
use App\Entity\Message;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Search (reference/app/models/search.rb) and Message::Searchable's `search` scope, as
 * SearchesController uses them: the recent searches of a user (at most 10, newest first) and
 * the full-text search of the messages they can reach.
 */
final readonly class Searches
{
    public const int RECENT_LIMIT = 10;
    public const int RESULTS_LIMIT = 100;

    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $em,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * `params[:q]&.gsub(/[^[:word:]]/, " ")`: everything but Unicode word characters becomes a
     * space, so FTS5 only sees bare terms.
     */
    public static function sanitize(?string $q): ?string
    {
        if (null === $q) {
            return null;
        }

        return (string) preg_replace('/[^\p{L}\p{M}\p{Nd}\p{Nl}\p{Pc}\x{200C}\x{200D}]/u', ' ', mb_scrub($q, 'UTF-8'));
    }

    /**
     * `Current.user.reachable_messages.search(query).last_page_of(100)`: the newest 100 matches,
     * oldest first.
     *
     * @return list<Message>
     */
    public function search(User $user, string $query): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT messages.id FROM messages'
            .' INNER JOIN rooms ON messages.room_id = rooms.id'
            .' INNER JOIN memberships ON rooms.id = memberships.room_id'
            .' join message_search_index idx on messages.id = idx.rowid'
            .' WHERE memberships.user_id = ? AND (idx.body match ?)'
            .' ORDER BY messages.created_at DESC LIMIT '.self::RESULTS_LIMIT,
            [$user->getId(), $query],
        );
        if ([] === $ids) {
            return [];
        }
        $ids = array_reverse(array_map(intval(...), $ids));

        /** @var list<Message> $messages */
        $messages = $this->em->getRepository(Message::class)->findBy(['id' => $ids]);
        $byId = [];
        foreach ($messages as $message) {
            $byId[$message->getId()] = $message;
        }

        return array_values(array_filter(array_map(static fn (int $id): ?Message => $byId[$id] ?? null, $ids)));
    }

    /**
     * `Current.user.searches.ordered`: the queries, most recently used first.
     *
     * @return list<string>
     */
    public function recent(User $user): array
    {
        return array_map(strval(...), $this->connection->fetchFirstColumn(
            'SELECT query FROM searches WHERE user_id = ? ORDER BY updated_at DESC',
            [$user->getId()],
        ));
    }

    /**
     * `Current.user.searches.record(query)`: `find_or_create_by(query:).touch`; a new search
     * trims the user's searches to the 10 most recent (after_create :trim_recent_searches).
     */
    public function record(User $user, string $query): void
    {
        $this->transactions->transaction(function () use ($user, $query): void {
            $userId = $user->getId();
            $id = $this->connection->fetchOne('SELECT id FROM searches WHERE user_id = ? AND query = ? LIMIT 1', [$userId, $query]);
            if (false === $id) {
                $now = RailsDateTimeType::format($this->clock->now());
                $this->connection->insert('searches', ['user_id' => $userId, 'query' => $query, 'created_at' => $now, 'updated_at' => $now]);
                $this->connection->executeStatement(
                    'DELETE FROM searches WHERE user_id = ? AND id NOT IN (SELECT id FROM searches WHERE user_id = ? ORDER BY updated_at DESC LIMIT '.self::RECENT_LIMIT.')',
                    [$userId, $userId],
                );
            }
            $this->connection->update('searches', ['updated_at' => RailsDateTimeType::format($this->clock->now())], ['user_id' => $userId, 'query' => $query]);
        });
    }

    /** `Current.user.searches.destroy_all` */
    public function clear(User $user): void
    {
        $this->connection->delete('searches', ['user_id' => $user->getId()]);
    }
}
