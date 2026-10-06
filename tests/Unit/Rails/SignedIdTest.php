<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rails;

use App\Rails\SignedId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SignedIdTest extends TestCase
{
    /** @return iterable<string, array<mixed>> */
    public static function generateCases(): iterable
    {
        return Vectors::cases('signed_ids.generate');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('generateCases')]
    public function testGeneratesRailsSignedIds(array $case): void
    {
        $signedIds = new SignedId(Vectors::keys());
        self::assertSame($case['signed_id'], $signedIds->generate($case['id'], $case['model'], $case['purpose'], Vectors::time($case['expires_at'])));
        self::assertSame($case['id'], $signedIds->find($case['signed_id'], $case['model'], $case['purpose'], Vectors::now()));
    }

    /** @return iterable<string, array<mixed>> */
    public static function verifyCases(): iterable
    {
        return Vectors::cases('signed_ids.verify');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('verifyCases')]
    public function testFindsLikeRails(array $case): void
    {
        $expected = \is_string($case['expected']) ? (int) $case['expected'] : $case['expected'];
        self::assertSame($expected, new SignedId(Vectors::keys())->find($case['signed_id'], $case['model'], $case['purpose'], Vectors::time($case['now'])));
    }

    public function testCombinesPurposes(): void
    {
        self::assertSame('user/avatar', SignedId::combinePurposes('User', 'avatar'));
        self::assertSame('user', SignedId::combinePurposes('User', null));
        self::assertSame('rooms/open', SignedId::combinePurposes('Rooms::Open', ''));
        self::assertSame('http_request/x', SignedId::combinePurposes('HTTPRequest', 'x'));
        self::assertSame('web_push/subscription', SignedId::combinePurposes('WebPush::Subscription', null));
    }
}
