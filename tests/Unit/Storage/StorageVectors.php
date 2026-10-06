<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Entity\ActiveStorage\Blob;
use App\Rails\AppVerifiers;
use App\Storage\DiskService;
use App\Storage\StorageUrls;
use App\Storage\Symbol;
use App\Tests\Unit\Rails\Vectors;

/**
 * tests/vectors/storage.json and tests/vectors/storage/* (reference-tools/storage/generate.rb in
 * once-campfire-rust, run against the reference image).
 */
final class StorageVectors
{
    public static function get(string $path): mixed
    {
        return Vectors::get($path, 'storage');
    }

    public static function file(string $name): string
    {
        return \dirname(__DIR__, 2).'/vectors/storage/'.$name;
    }

    public static function fixture(string $name): string
    {
        return \dirname(__DIR__, 3).'/reference/test/fixtures/files/'.$name;
    }

    public static function disk(string $root = '/nonexistent'): DiskService
    {
        return new DiskService($root, new AppVerifiers(Vectors::keys()));
    }

    public static function urls(): StorageUrls
    {
        return new StorageUrls(self::disk());
    }

    /** A Blob entity with the row's values, id included. */
    public static function blob(array $row): Blob
    {
        $blob = new Blob($row['key'], $row['filename'], $row['byte_size'], $row['service_name']);
        $blob->setContentType($row['content_type']);
        $blob->setChecksum($row['checksum']);
        $blob->setMetadataJson($row['metadata']);
        new \ReflectionProperty(Blob::class, 'id')->setValue($blob, $row['id']);

        return $blob;
    }

    /** The "typed" Ruby value of a vector as PHP (`{"sym": "webp"}` → Symbol). */
    public static function typed(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (isset($value['sym']) && 1 === \count($value)) {
            return new Symbol($value['sym']);
        }
        if (\array_key_exists('str', $value) && 1 === \count($value)) {
            return $value['str'];
        }
        if (isset($value['hash']) && 1 === \count($value)) {
            $hash = [];
            foreach ($value['hash'] as [$key, $item]) {
                $hash[$key] = self::typed($item);
            }

            return $hash;
        }

        return array_map(self::typed(...), $value);
    }
}
