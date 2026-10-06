<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rails;

use App\Rails\Base64;
use App\Rails\CsrfToken;
use App\Rails\ForgeryProtection;
use App\Rails\InvalidAuthenticityToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsrfTokenTest extends TestCase
{
    public function testGlobalTokenMatchesRails(): void
    {
        $secret = Vectors::get('csrf.session_token');
        self::assertSame(Vectors::get('csrf.global_token_hex'), bin2hex(CsrfToken::globalToken($secret)));
        self::assertTrue(Vectors::get('csrf.per_form_csrf_tokens'));
        self::assertTrue(Vectors::get('csrf.forgery_protection_origin_check'));

        foreach (Vectors::get('csrf.global_tokens') as $token) {
            $masked = Base64::urlsafeDecode($token);
            self::assertSame(CsrfToken::globalToken($secret), substr($masked, 0, 32) ^ substr($masked, 32));
        }
    }

    public function testGeneratesTokensInRailsFormat(): void
    {
        $example = Vectors::get('csrf.generated_session_token_example');
        $secret = CsrfToken::generateSecret();
        self::assertSame(\strlen($example), \strlen($secret));
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $secret);

        $masked = CsrfToken::masked($secret);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{86}\z/', $masked);
        self::assertNotSame($masked, CsrfToken::masked($secret), 'a fresh pad each time');
        self::assertTrue(CsrfToken::isValid($secret, $masked, '/anything', 'POST'));
        self::assertFalse(CsrfToken::isValid(CsrfToken::generateSecret(), $masked, '/anything', 'POST'));
    }

    /** @return iterable<string, array<mixed>> */
    public static function formTokenCases(): iterable
    {
        return Vectors::cases('csrf.form_tokens');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('formTokenCases')]
    public function testPerFormTokensMatchRails(array $case): void
    {
        $secret = Vectors::get('csrf.session_token');
        $path = CsrfToken::normalizeActionPath($case['action'], $case['page_path']);
        self::assertSame($case['normalized_action_path'], $path);
        self::assertSame($case['unmasked_hex'], bin2hex(CsrfToken::perFormToken($secret, $path, $case['method'])));

        $masked = Base64::urlsafeDecode($case['token']);
        self::assertSame($case['unmasked_hex'], bin2hex(substr($masked, 0, 32) ^ substr($masked, 32)));

        $ours = CsrfToken::perForm($secret, $path, $case['method']);
        self::assertSame(
            CsrfToken::isValid($secret, $case['token'], $path, strtoupper($case['method'])),
            CsrfToken::isValid($secret, $ours, $path, strtoupper($case['method'])),
        );
    }

    /** @return iterable<string, array<mixed>> */
    public static function validityCases(): iterable
    {
        return Vectors::cases('csrf.validity');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('validityCases')]
    public function testValidityMatchesRails(array $case): void
    {
        self::assertSame($case['expected'], CsrfToken::isValid(Vectors::get('csrf.session_token'), $case['token'], $case['path'], $case['method']));
    }

    /** @return iterable<string, array<mixed>> */
    public static function originCases(): iterable
    {
        return Vectors::cases('csrf.origin');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('originCases')]
    public function testOriginCheckMatchesRails(array $case): void
    {
        if ('raises' === $case['expected']) {
            $this->expectException(InvalidAuthenticityToken::class);
        }
        self::assertSame($case['expected'], ForgeryProtection::validRequestOrigin($case['origin'], $case['base_url']));
    }

    public function testSessionPostsGetRailsStatuses(): void
    {
        $session = Vectors::get('session');
        $secret = $session['session']['_csrf_token'];
        $base = 'http://campfire.test';
        $status = static fn (bool $verified): int => $verified ? 302 : 422;

        self::assertSame($session['post_with_form_token_status'], $status(ForgeryProtection::verifiedRequest('POST', '/session', null, $base, 'same-origin', null, $session['session_form_token'], $secret)));
        self::assertSame($session['post_with_meta_token_header_status'], $status(ForgeryProtection::verifiedRequest('POST', '/session', $base, $base, 'same-origin', $session['csrf_meta_token'], null, $secret)));
        self::assertSame($session['post_with_bad_token_status'], $status(ForgeryProtection::verifiedRequest('POST', '/session', null, $base, 'same-origin', null, 'bad', $secret)));
        self::assertSame($session['post_with_cross_origin_status'], $status(ForgeryProtection::verifiedRequest('POST', '/session', 'http://evil.example', $base, 'cross-site', null, $session['session_form_token'], $secret)));

        self::assertTrue(ForgeryProtection::verifiedRequest('GET', '/session', 'http://evil.example', $base, 'cross-site', null, null, null));
        self::assertTrue(ForgeryProtection::verifiedRequest('head', '/session', null, $base, null, null, null, null));
        self::assertFalse(ForgeryProtection::verifiedRequest('POST', '/session', 'null', $base, null, $session['csrf_meta_token'], null, $secret));
        self::assertFalse(ForgeryProtection::verifiedRequest('POST', '/session', null, $base, null, $session['csrf_meta_token'], null, null));
        self::assertFalse(ForgeryProtection::verifiedRequest('POST', '/session', null, $base, null, null, [$session['csrf_meta_token']], $secret));
        self::assertTrue(ForgeryProtection::verifiedRequest('DELETE', '/rooms/1', 'http://evil.example', $base, null, $session['csrf_meta_token'], null, $secret, originCheck: false));
        self::assertFalse(ForgeryProtection::verifiedRequest('PATCH', '/other', null, $base, null, null, $session['session_form_token'], $secret), 'per-form token for another action');
    }
}
