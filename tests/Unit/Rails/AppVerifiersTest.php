<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rails;

use App\Rails\AppVerifiers;
use App\Rails\RailsJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AppVerifiersTest extends TestCase
{
    /** @return iterable<string, array<mixed>> */
    public static function generateCases(): iterable
    {
        return Vectors::cases('app_verifiers.generate');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('generateCases')]
    public function testGeneratesLikeRailsApplicationMessageVerifier(array $case): void
    {
        $verifier = new AppVerifiers(Vectors::keys())->verifier($case['name']);
        $data = RailsJson::decode($case['data_json']);
        self::assertSame($case['message'], $verifier->generate($data, $case['purpose'], Vectors::time($case['expires_at'])));
        self::assertSame($data, $verifier->verified($case['message'], $case['purpose'], Vectors::now()));
    }

    /** @return iterable<string, array<mixed>> */
    public static function verifyCases(): iterable
    {
        return Vectors::cases('app_verifiers.verify');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('verifyCases')]
    public function testVerifiesLikeRails(array $case): void
    {
        $data = new AppVerifiers(Vectors::keys())->verifier($case['name'])->verified($case['message'], $case['purpose'], Vectors::time($case['now']));
        self::assertSame($case['expected_json'], null === $data ? null : RailsJson::encode($data));
    }

    public function testBlobSignedIdsFromTheSeed(): void
    {
        $verifier = new AppVerifiers(Vectors::keys())->verifier('ActiveStorage');
        foreach (Vectors::get('blobs', 'campfire_sessions') as $blob) {
            self::assertSame($blob['signed_id'], $verifier->generate($blob['blob_id'], 'blob_id'));
            self::assertSame($blob['blob_id'], $verifier->verified($blob['signed_id'], 'blob_id', Vectors::now()));
        }
    }
}
