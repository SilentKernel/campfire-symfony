<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rails;

use App\Rails\GlobalId;
use App\Rails\MessageVerifier;
use App\Rails\Serializer;
use App\Rails\SignedGlobalId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GlobalIdTest extends TestCase
{
    /** @return iterable<string, array<mixed>> */
    public static function globalIdCases(): iterable
    {
        return Vectors::cases('global_ids');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('globalIdCases')]
    public function testGlobalIds(array $case): void
    {
        self::assertSame($case['gid'], GlobalId::gid($case['model_name'], $case['id']));
        self::assertSame($case['param'], GlobalId::param($case['gid']));
        $expected = ['app' => 'campfire', 'model' => $case['model_name'], 'id' => $case['id']];
        self::assertSame($expected, GlobalId::parse($case['gid']));
        self::assertSame($expected, GlobalId::parse($case['param']));
        self::assertSame($expected, GlobalId::fromParam($case['param']));
    }

    public function testParsesLikeUriGid(): void
    {
        self::assertSame(['app' => 'campfire', 'model' => 'Rooms::Open', 'id' => '1'], GlobalId::parse('gid://campfire/Rooms::Open/1?expires_in'));
        self::assertNull(GlobalId::parse('gid://campfire/User'));
        self::assertNull(GlobalId::parse('gid://campfire/User/'));
        self::assertNull(GlobalId::parse('http://campfire/User/1'));
        self::assertNull(GlobalId::parse('hello'));
        self::assertSame(Vectors::get('sgids.app'), GlobalId::APP);
    }

    /** @return iterable<string, array<mixed>> */
    public static function sgidGenerateCases(): iterable
    {
        return Vectors::cases('sgids.generate');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('sgidGenerateCases')]
    public function testGeneratesRailsSgids(array $case): void
    {
        $sgids = new SignedGlobalId(Vectors::keys());
        $gid = GlobalId::parse($case['gid']);
        $expiresAt = Vectors::time($case['expires_at']);
        if (str_ends_with($case['data'], '?expires_in')) {
            self::assertNull($expiresAt);
            self::assertSame($case['sgid'], $sgids->generate($gid['model'], $gid['id'], $case['purpose']));
        } else {
            self::assertSame($case['sgid'], $sgids->generate($gid['model'], $gid['id'], $case['purpose'], $expiresAt));
        }
        self::assertSame($case['sgid'], $sgids->sign($case['data'], $case['purpose'], $expiresAt));
        self::assertSame($gid, $sgids->locate($case['sgid'], $case['purpose'], Vectors::now()));
    }

    /** @return iterable<string, array<mixed>> */
    public static function sgidVerifyCases(): iterable
    {
        return Vectors::cases('sgids.verify');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('sgidVerifyCases')]
    public function testLocatesLikeRails(array $case): void
    {
        $expected = null === $case['expected'] ? null : GlobalId::parse($case['expected']);
        self::assertSame($expected, new SignedGlobalId(Vectors::keys())->locate($case['sgid'], $case['purpose'], Vectors::time($case['now'])));
    }

    public function testForgedSgidsAreRejected(): void
    {
        $sgids = new SignedGlobalId(Vectors::keys());
        $attacker = new MessageVerifier(hash_pbkdf2('sha256', 'attacker', SignedGlobalId::SALT, 1000, 64, true), 'sha1', true, Serializer::JsonAllowMarshal, true);
        foreach (['User', 'Rooms::Open', 'Account', 'Message', 'Rooms::Direct', 'Session'] as $model) {
            self::assertNull($sgids->locate($attacker->generate(GlobalId::gid($model, 1), 'attachable'), 'attachable', Vectors::now()));
        }
    }

    /** @return iterable<string, array<mixed>> */
    public static function unverifiedCases(): iterable
    {
        return Vectors::cases('unverified_sgids');
    }

    /**
     * The vectors ran against a database with users 1 and 2.
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('unverifiedCases')]
    public function testReadsPossiblyExpiredSgidsLikeCampfire(array $case): void
    {
        $located = SignedGlobalId::unverifiedLocate($case['sgid']);
        if (null !== $located && !\in_array($located['id'], ['1', '2'], true)) {
            $located = null; // GlobalID.find: no such user
        }
        $expected = \is_string($case['expected']) ? GlobalId::parse($case['expected']) : null; // "raises" → null here
        self::assertSame($expected['model'] ?? null, $located['model'] ?? null);
        self::assertSame($expected['id'] ?? null, $located['id'] ?? null);
    }
}
