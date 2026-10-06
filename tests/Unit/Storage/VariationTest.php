<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Storage\RubyMarshal;
use App\Storage\Variation;
use App\Tests\Unit\Rails\Vectors;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VariationTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function variations(): iterable
    {
        foreach (StorageVectors::get('variations') as $vector) {
            yield $vector['inspect'] => [$vector];
        }
    }

    /** @param array<string, mixed> $vector */
    #[DataProvider('variations')]
    public function testDigestsAndKeysLikeRails(array $vector): void
    {
        $variation = new Variation(StorageVectors::typed($vector['typed']));
        self::assertSame($vector['marshal_hex'], bin2hex(RubyMarshal::dump($variation->transformations)));
        self::assertSame($vector['digest'], $variation->digest());
        $verifier = StorageVectors::disk()->verifier;
        self::assertSame($vector['key'], $variation->key($verifier));

        $decoded = Variation::decode($verifier, $vector['key'], Vectors::now());
        self::assertNotNull($decoded);
        self::assertEquals(StorageVectors::typed($vector['decoded_typed']), $decoded->transformations);
        self::assertSame($vector['decoded_marshal_hex'], bin2hex(RubyMarshal::dump($decoded->transformations)));
        self::assertSame($vector['decoded_digest'], $decoded->digest());
    }

    public function testDefaultToPutsTheDefaultsFirst(): void
    {
        $variation = Variation::resizeToLimit(512, 512, 'webp')->defaultTo(['format' => 'jpg']);
        self::assertSame(['format', 'resize_to_limit'], array_keys($variation->transformations));
        self::assertSame('6gwfjNKv9eUy9jNUtEZvQFLU0hQ=', $variation->digest());
        self::assertSame('webp', $variation->format());
        self::assertSame('image/webp', $variation->contentType());
    }

    public function testATamperedKeyDoesNotDecode(): void
    {
        self::assertNull(Variation::decode(StorageVectors::disk()->verifier, 'eyJfcmFpbHMiOnt9fQ==--0000', Vectors::now()));
    }
}
