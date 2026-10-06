<?php

declare(strict_types=1);

namespace App\Tests\Functional\Http;

use App\Http\Pipeline;
use App\Rails\KeyGenerator;
use App\Rails\RailsCookies;
use App\Tests\Support\CampfireTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;

abstract class HttpTestCase extends CampfireTestCase
{
    /** tests/vectors/campfire_sessions.json: David's seed session, signed by the Rails app. */
    protected const string DAVID_COOKIE = 'eyJfcmFpbHMiOnsibWVzc2FnZSI6IklrRjRTbk01TkdaMFpWRTFRWFYwZGpKV2NrdHpTRFk0WXlJPSIsImV4cCI6IjIwNDYtMDktMjZUMTM6MTQ6NTIuODc3WiIsInB1ciI6ImNvb2tpZS5zZXNzaW9uX3Rva2VuIn19--958d9639acf62d4748990cd880ea1af466dd10f0';
    protected const string DAVID_TOKEN = 'AxJs94fteQ5Autv2VrKsH68c';
    protected const string FORGED_COOKIE = 'eyJfcmFpbHMiOnsibWVzc2FnZSI6IkltNXZkQzFoTFhObGMzTnBiMjR0ZEc5clpXNGkiLCJleHAiOiIyMDQ2LTA5LTI2VDEzOjE0OjUyLjg4NFoiLCJwdXIiOiJjb29raWUuc2Vzc2lvbl90b2tlbiJ9fQ==--b2ad571ae515f00423f55aea97bea1d6846ed075';

    protected static function railsCookies(): RailsCookies
    {
        return new RailsCookies(new KeyGenerator((string) $_SERVER['SECRET_KEY_BASE']));
    }

    protected static function setRawCookie(KernelBrowser $client, string $name, string $value): void
    {
        $client->getCookieJar()->set(new Cookie($name, $value, null, '/', 'localhost'));
    }

    /**
     * The value of the response's Set-Cookie header for $name (raw, Rack-escaped), or null.
     */
    protected static function setCookie(Response $response, string $name): ?string
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return (string) $cookie;
            }
        }

        return null;
    }

    /** The cookie's value, unescaped like Rack. */
    protected static function cookieValue(Response $response, string $name): ?string
    {
        $header = self::setCookie($response, $name);
        if (null === $header) {
            return null;
        }

        return RailsCookies::unescape(substr(explode(';', $header, 2)[0], \strlen($name) + 1));
    }

    /** @return array<string, mixed> */
    protected static function railsSession(Response $response): array
    {
        $value = self::cookieValue($response, '_campfire_session');
        self::assertNotNull($value, 'No _campfire_session cookie');
        $session = self::railsCookies()->readEncrypted('_campfire_session', $value);
        self::assertIsArray($session);

        return $session;
    }

    /**
     * Once the filters pass, answer with $response instead of the action (the F3 stubs raise 501).
     * Needs a client with reboot disabled, so the listener survives between requests.
     */
    protected static function respondAfterFilters(KernelBrowser $client, callable $response): void
    {
        $dispatcher = $client->getContainer()->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);
        $dispatcher->addListener(KernelEvents::CONTROLLER_ARGUMENTS, static function (ControllerArgumentsEvent $event) use ($response): void {
            if (!Pipeline::isHalted($event->getRequest())) {
                $event->setController(static fn (): Response => $response());
            }
        });
    }
}
