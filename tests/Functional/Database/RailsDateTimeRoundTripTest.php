<?php

declare(strict_types=1);

namespace App\Tests\Functional\Database;

use App\Database\Type\RailsDateTimeType;
use App\Entity\Message;
use App\Entity\Session;
use App\Tests\Support\CampfireTestCase;

final class RailsDateTimeRoundTripTest extends CampfireTestCase
{
    public function testMicrosecondsAndUtcSurviveARoundTrip(): void
    {
        $session = $this->em()->find(Session::class, self::id('sessions.david_safari'));
        \assert(null !== $session);
        $paris = new \DateTimeImmutable('2026-03-02 17:00:00.000042', new \DateTimeZone('Europe/Paris'));
        $session->setLastActiveAt($paris);
        $this->em()->flush();

        self::assertSame('2026-03-02 16:00:00.000042', $this->connection()->fetchOne('SELECT last_active_at FROM sessions WHERE id = ?', [$session->getId()]));

        $this->em()->clear();
        $reloaded = $this->em()->find(Session::class, self::id('sessions.david_safari'));
        self::assertSame('2026-03-02 16:00:00.000042 UTC', $reloaded?->getLastActiveAt()->format('Y-m-d H:i:s.u e'));
        self::assertEquals($paris, $reloaded->getLastActiveAt());
    }

    public function testFractionsSqliteWritesAreRead(): void
    {
        $id = self::id('sessions.david_safari');
        // What `STRFTIME('%Y-%m-%d %H:%M:%f', 'NOW')` stores (milliseconds).
        $this->connection()->executeStatement("UPDATE sessions SET last_active_at = '2026-03-02 16:00:00.123' WHERE id = ?", [$id]);

        self::assertSame('2026-03-02 16:00:00.123000', $this->em()->find(Session::class, $id)?->getLastActiveAt()->format('Y-m-d H:i:s.u'));
    }

    public function testQueryParametersCompareAsRailsText(): void
    {
        $time = new \DateTimeImmutable('2026-01-20 00:00:00', new \DateTimeZone('UTC'));
        $expected = (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM messages WHERE created_at < '2026-01-20 00:00:00'");

        $count = $this->em()->createQueryBuilder()->select('COUNT(m.id)')->from(Message::class, 'm')
            ->where('m.createdAt < :time')->setParameter('time', $time, RailsDateTimeType::NAME)
            ->getQuery()->getSingleScalarResult();

        self::assertSame($expected, (int) $count);
    }
}
