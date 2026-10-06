<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Cookies;
use App\Http\Flash;
use App\Http\RailsSession;
use App\Http\Ssl;
use App\Rails\KeyGenerator;
use App\Rails\RailsCookies;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class FlashTest extends TestCase
{
    private RailsCookies $railsCookies;

    protected function setUp(): void
    {
        $this->railsCookies = new RailsCookies(new KeyGenerator(str_repeat('k', 64)));
    }

    public function testSweepsLikeRails(): void
    {
        [$session, $flash] = $this->request(['session_id' => 'x', 'flash' => ['discard' => ['alert'], 'flashes' => ['notice' => 'hi', 'alert' => 'gone']]]);

        self::assertSame('hi', $flash->get('notice'));
        self::assertNull($flash->get('alert'));
        $flash->commit();
        self::assertNull($session->get('flash'));
        self::assertFalse($session->has('flash'));
    }

    public function testKeepCarriesOver(): void
    {
        [$session, $flash] = $this->request(['session_id' => 'x', 'flash' => ['discard' => [], 'flashes' => ['notice' => 'hi']]]);
        $flash->keep('notice');
        $flash->commit();

        self::assertSame(['discard' => [], 'flashes' => ['notice' => 'hi']], $session->get('flash'));
    }

    public function testNowIsNotStored(): void
    {
        [$session, $flash] = $this->request(null);
        $flash->now('alert', 'Too many requests or unauthorized.');
        self::assertSame('Too many requests or unauthorized.', $flash->alert());
        $flash->commit();

        self::assertFalse($session->has('flash'));
    }

    public function testResetSessionDropsTheFlash(): void
    {
        [$session, $flash] = $this->request(['session_id' => 'x', 'flash' => ['discard' => [], 'flashes' => ['notice' => 'hi']]]);
        self::assertSame('hi', $flash->notice());
        $session->resetSession();

        self::assertNull($flash->notice());
        self::assertNotSame('x', $session->id());
    }

    public function testUntouchedEmptySessionIsNotWritten(): void
    {
        [$session, , $cookies] = $this->request(null);
        self::assertNull($session->get('anything'));
        self::assertFalse($session->isLoaded());
        $session->commit();

        self::assertSame([], $cookies->responseCookies());
    }

    /**
     * @param array<string, mixed>|null $stored
     *
     * @return array{RailsSession, Flash, Cookies}
     */
    private function request(?array $stored): array
    {
        $request = Request::create('/');
        if (null !== $stored) {
            $request->headers->set('Cookie', '_campfire_session='.RailsCookies::escape($this->railsCookies->writeEncrypted('_campfire_session', $stored)));
        }
        $stack = new RequestStack();
        $stack->push($request);
        $clock = new MockClock('2026-01-01 00:00:00');
        $cookies = new Cookies($stack, $this->railsCookies, $clock);
        $session = new RailsSession($cookies, $clock, new Ssl('true'));

        return [$session, new Flash($session), $cookies];
    }
}
