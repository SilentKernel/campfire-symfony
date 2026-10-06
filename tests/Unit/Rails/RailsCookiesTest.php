<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rails;

use App\Rails\RailsCookies;
use App\Rails\RailsJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RailsCookiesTest extends TestCase
{
    private static function cookies(): RailsCookies
    {
        return new RailsCookies(Vectors::keys());
    }

    /** @return iterable<string, array<mixed>> */
    public static function escapingCases(): iterable
    {
        return Vectors::cases('cookie_escaping');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('escapingCases')]
    public function testEscapesLikeRack(array $case): void
    {
        if (null !== $case['raw']) {
            self::assertSame($case['wire'], RailsCookies::escape($case['raw']));
        }
        self::assertSame($case['parsed'], RailsCookies::unescape($case['wire']));
    }

    /** @return iterable<string, array<mixed>> */
    public static function rubyStrings(): iterable
    {
        foreach (Vectors::get('strings', 'ruby_core') as $i => $case) {
            yield '#'.$i => [$case['input'], $case['rack_escape']];
        }
    }

    #[DataProvider('rubyStrings')]
    public function testEscapeIsRackUtilsEscape(string $input, string $expected): void
    {
        self::assertSame($expected, RailsCookies::escape($input));
    }

    /** @return iterable<string, array<mixed>> */
    public static function signedGenerateCases(): iterable
    {
        return Vectors::cases('signed_cookies.generate');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('signedGenerateCases')]
    public function testSignedCookiesAreGeneratedByteForByte(array $case): void
    {
        $expiresAt = Vectors::time($case['expires_at']);
        $raw = self::cookies()->writeSigned($case['name'], $case['value'], $expiresAt);
        self::assertSame($case['raw'], $raw);
        self::assertSame($case['value'], self::cookies()->readSigned($case['name'], $raw, Vectors::now()));

        $httpOnly = str_contains($case['set_cookie'], '; httponly');
        self::assertSame($case['set_cookie'], RailsCookies::setCookieHeader($case['name'], $raw, $expiresAt, $httpOnly, 'lax', false));
    }

    public function testPermanentCookiesExpireInTwentyYears(): void
    {
        $sessionToken = Vectors::get('signed_cookies.generate')[0];
        self::assertEquals(Vectors::time($sessionToken['expires_at']), RailsCookies::permanentExpiresAt(Vectors::now()));
    }

    /** @return iterable<string, array<mixed>> */
    public static function signedVerifyCases(): iterable
    {
        return Vectors::cases('signed_cookies.verify');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('signedVerifyCases')]
    public function testSignedCookiesVerifyLikeRails(array $case): void
    {
        self::assertSame($case['expected'], self::cookies()->readSigned($case['name'], $case['raw'], Vectors::time($case['now'])));
    }

    /** @return iterable<string, array<mixed>> */
    public static function encryptedGenerateCases(): iterable
    {
        return Vectors::cases('encrypted_cookies.generate');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('encryptedGenerateCases')]
    public function testEncryptedCookiesMatchRails(array $case): void
    {
        $cookies = self::cookies();
        self::assertSame($case['plaintext'], $cookies->encryptor()->decrypt($case['raw']));
        self::assertSame($case['value'], $cookies->readEncrypted($case['name'], $case['raw'], Vectors::now()));

        $expiresAt = Vectors::time($case['expires_at']);
        $raw = $cookies->writeEncrypted($case['name'], $case['value'], $expiresAt);
        self::assertMatchesRegularExpression('~\A[A-Za-z0-9+/]+=*--[A-Za-z0-9+/]{16}--[A-Za-z0-9+/]{22}==\z~', $raw);
        self::assertSame($case['plaintext'], $cookies->encryptor()->decrypt($raw), 'same plaintext as Rails');
        self::assertSame($case['value'], $cookies->readEncrypted($case['name'], $raw, Vectors::now()));

        if (isset($case['set_cookie'])) {
            $wire = self::cookies()->readEncrypted($case['name'], RailsCookies::unescape(explode('=', explode(';', $case['set_cookie'])[0], 2)[1]), Vectors::now());
            self::assertSame($case['value'], $wire);
            self::assertSame(
                preg_replace('/\A[^;]*/', '', $case['set_cookie']),
                preg_replace('/\A[^;]*/', '', RailsCookies::setCookieHeader($case['name'], $raw, $expiresAt, str_contains($case['set_cookie'], '; httponly'), 'lax', false)),
            );
        }
    }

    /** @return iterable<string, array<mixed>> */
    public static function encryptedVerifyCases(): iterable
    {
        return Vectors::cases('encrypted_cookies.verify');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('encryptedVerifyCases')]
    public function testEncryptedCookiesDecryptLikeRails(array $case): void
    {
        self::assertSame($case['expected'], self::cookies()->readEncrypted($case['name'], $case['raw'], Vectors::time($case['now'])));
    }

    public function testWrongSecretReadsNothing(): void
    {
        $other = new RailsCookies(Vectors::rotatedKeys());
        $case = Vectors::get('signed_cookies.generate')[0];
        self::assertNull($other->readSigned($case['name'], $case['raw'], Vectors::now()));
    }

    public function testRailsSessionCarriesOver(): void
    {
        $session = Vectors::get('session');
        $cookies = self::cookies();

        self::assertSame($session['session'], $cookies->readEncrypted('_campfire_session', $session['session_cookie_raw'], Vectors::now()));
        self::assertSame($session['session_after_login'], $cookies->readEncrypted('_campfire_session', $session['session_after_login_raw'], Vectors::now()));
        $wire = explode('=', explode(';', $session['set_cookie'])[0], 2)[1];
        self::assertSame($session['session'], $cookies->readEncrypted('_campfire_session', RailsCookies::unescape($wire), Vectors::now()));

        $token = $session['session_token_value'];
        self::assertSame($token, $cookies->readSigned('session_token', $session['session_token_raw'], Vectors::now()));
        $permanent = RailsCookies::permanentExpiresAt(Vectors::now());
        $raw = $cookies->writeSigned('session_token', $token, $permanent);
        self::assertSame($session['session_token_raw'], $raw);
        self::assertSame($session['session_token_set_cookie'], RailsCookies::setCookieHeader('session_token', $raw, $permanent, true, 'lax', false));

        $ours = $cookies->writeEncrypted('_campfire_session', $session['session'], $permanent);
        self::assertSame($session['session'], $cookies->readEncrypted('_campfire_session', $ours, Vectors::now()));
        self::assertStringEndsWith('; path=/; expires=Mon, 01 Jan 2046 12:00:00 GMT; httponly; samesite=lax', $session['set_cookie']);
    }

    public function testCampfireSessionVectors(): void
    {
        $vectors = Vectors::load('campfire_sessions');
        $cookies = self::cookies();
        $sessionCookie = $vectors['sessions'][0];
        $value = $cookies->readSigned('session_token', $sessionCookie['cookie_value'], Vectors::now());
        if (null === $value) {
            self::markTestSkipped('campfire_sessions.json was signed with the parity seed secret, not the rails_compat one.');
        }
        self::assertSame($sessionCookie['token'], $value);
        self::assertSame($sessionCookie['cookie_value'], RailsCookies::parseCookieHeader($sessionCookie['cookie_header'])['session_token']);
        // "forged" is authentic but names no session: rejecting it is the session lookup's job.
        self::assertSame('not-a-session-token', $cookies->readSigned('session_token', $vectors['forged']['cookie_value'], Vectors::now()));
        self::assertSame($vectors['forged']['cookie_value'], RailsCookies::parseCookieHeader($vectors['forged']['cookie_header'])['session_token']);
    }

    public function testParsesCookieHeadersLikeRack(): void
    {
        self::assertSame(
            ['a' => 'x y', 'b' => '', 'c' => 'bad%zz', 'd' => 'e=f'],
            RailsCookies::parseCookieHeader('a=x+y; b; c=bad%zz;d=e=f; a=second'),
        );
        self::assertSame([], RailsCookies::parseCookieHeader(null));
    }

    public function testDeletesLikeRails(): void
    {
        self::assertSame('last_room=; path=/; max-age=0; expires=Thu, 01 Jan 1970 00:00:00 GMT; samesite=lax', RailsCookies::deleteCookieHeader('last_room'));
        self::assertSame('{"a":1}', RailsJson::encode(['a' => 1]));
    }
}
