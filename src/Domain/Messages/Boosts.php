<?php

declare(strict_types=1);

namespace App\Domain\Messages;

use App\Database\Transactions;
use App\Domain\Rooms\RubyInteger;
use App\Entity\Boost;
use App\Entity\Membership;
use App\Entity\Message;
use App\Entity\User;
use App\Storage\RecordToucher;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Boosts (reference/app/models/boost.rb: `belongs_to :message, touch: true`) and the lookups of
 * Messages::BoostsController: `Current.user.reachable_messages.find(id)` and
 * `message.boosts.find_by!(id:, booster: Current.user)`.
 */
final readonly class Boosts
{
    public function __construct(
        private EntityManagerInterface $em,
        private Transactions $transactions,
        private RecordToucher $toucher,
        private MessageBroadcasts $broadcasts,
    ) {
    }

    /** `user.reachable_messages.find_by(id:)`: a message in one of the user's rooms. */
    public function findReachableMessage(User $user, mixed $id): ?Message
    {
        $id = RubyInteger::cast($id);
        if (null === $id) {
            return null;
        }
        $message = $this->em->createQueryBuilder()->select('m')->from(Message::class, 'm')
            ->join(Membership::class, 'ms', 'WITH', 'ms.room = m.room')
            ->where('ms.user = :user')->andWhere('m.id = :id')
            ->setParameter('user', $user->getId())->setParameter('id', $id)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();

        return $message instanceof Message ? $message : null;
    }

    /** `message.boosts.find_by(id:, booster: user)` */
    public function findOwn(Message $message, User $user, mixed $id): ?Boost
    {
        $id = RubyInteger::cast($id);
        if (null === $id) {
            return null;
        }
        $boost = $this->em->getRepository(Boost::class)->findOneBy(['id' => $id, 'message' => $message, 'booster' => $user]);

        return $boost instanceof Boost ? $boost : null;
    }

    /** `message.boosts.create!(content:)` then its broadcast (appended to the message's boosts). */
    public function create(Message $message, User $booster, string $content): Boost
    {
        $boost = $this->transactions->transaction(function () use ($message, $booster, $content): Boost {
            $boost = new Boost($message, $booster, $content);
            $this->em->persist($boost);
            $this->em->flush();
            $this->toucher->touch(Message::RECORD_TYPE, $message->getId());

            return $boost;
        });
        $this->broadcasts->broadcastBoostCreate($boost);

        return $boost;
    }

    /** `boost.destroy!` then its broadcast (removed from the room). */
    public function destroy(Boost $boost): void
    {
        $messageId = $boost->getMessage()->getId();
        $this->transactions->transaction(function () use ($boost, $messageId): void {
            $this->em->getConnection()->delete('boosts', ['id' => $boost->getId()]);
            $this->em->detach($boost);
            $this->toucher->touch(Message::RECORD_TYPE, $messageId);
        });
        $this->broadcasts->broadcastBoostRemove($boost);
    }
}
