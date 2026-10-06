<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rails;

use App\Rails\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PasswordTest extends TestCase
{
    /** @return iterable<string, array<mixed>> */
    public static function checkCases(): iterable
    {
        return Vectors::cases('passwords.checks');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('checkCases')]
    public function testChecksBcryptRubyDigests(array $case): void
    {
        self::assertSame($case['expected'], Password::verify($case['password'], $case['digest']));
    }

    public function testDigestsLookLikeBcryptRuby(): void
    {
        self::assertSame(Password::COST, Vectors::get('passwords.cost'));
        foreach (Vectors::get('passwords.digests') as $case) {
            self::assertStringStartsWith('$2a$12$', $case['digest']);
        }
        self::assertTrue(Password::verify('secret123456', Vectors::get('passwords.seeded_user_digest')));

        $ours = Password::hash('pässwörd ☃', Password::MIN_COST);
        self::assertMatchesRegularExpression('~\A\$2a\$04\$[A-Za-z0-9./]{53}\z~', $ours);
        self::assertTrue(Password::verify('pässwörd ☃', $ours));
        self::assertFalse(Password::verify('passwörd ☃', $ours));

        $long = Password::hash(str_repeat('a', 72), Password::MIN_COST);
        self::assertTrue(Password::verify(str_repeat('a', 72).'ignored', $long));
        self::assertStringStartsWith('$2a$12$', Password::hash('x'));
        self::assertFalse(Password::verify('anything', 'not a digest'));
        self::assertFalse(Password::verify('anything', null));
    }
}
