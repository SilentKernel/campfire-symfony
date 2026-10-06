<?php

declare(strict_types=1);

namespace App\Storage;

use App\Rails\AppVerifiers;
use App\Rails\MessageVerifier;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * ActiveStorage::Service::DiskService for Campfire's "local" service (reference/config/storage.yml):
 * files at `<storage>/files/<key[0..1]>/<key[2..3]>/<key>`, signed disk URLs
 * (`/rails/active_storage/disk/:encoded_key/*filename`) and direct-upload tokens.
 */
#[Autoconfigure(public: true)]
final readonly class DiskService
{
    public const string NAME = 'local';

    public MessageVerifier $verifier;

    public function __construct(
        #[Autowire('%campfire.files_path%')] private string $root,
        AppVerifiers $verifiers,
    ) {
        $this->verifier = $verifiers->verifier('ActiveStorage');
    }

    public function root(): string
    {
        return $this->root;
    }

    /** `path_for(key)` */
    public function pathFor(string $key): string
    {
        return $this->root.'/'.substr($key, 0, 2).'/'.substr($key, 2, 2).'/'.$key;
    }

    public function exists(string $key): bool
    {
        return is_file($this->pathFor($key));
    }

    /**
     * `upload(key, io, checksum:)`: copy, then verify the MD5 and delete on mismatch.
     *
     * @param resource|string $source a stream, or the path of a local file
     *
     * @throws IntegrityError
     */
    public function upload(string $key, mixed $source, ?string $checksum = null): void
    {
        $path = $this->makePathFor($key);
        $in = \is_string($source) ? fopen($source, 'r') : $source;
        if (false === $in) {
            throw new \RuntimeException(\sprintf('Cannot read %s.', $source));
        }
        $out = fopen($path, 'w');
        if (false === $out) {
            throw new \RuntimeException(\sprintf('Cannot write %s.', $path));
        }
        try {
            if (false === stream_copy_to_stream($in, $out)) {
                throw new \RuntimeException(\sprintf('Cannot write %s.', $path));
            }
        } finally {
            fclose($out);
            if (\is_string($source)) {
                fclose($in);
            }
        }
        if (null !== $checksum && self::checksumFile($path) !== $checksum) {
            $this->delete($key);
            throw new IntegrityError();
        }
    }

    /** @throws FileNotFound */
    public function download(string $key): string
    {
        $data = @file_get_contents($this->pathFor($key));

        return false === $data ? throw new FileNotFound($key) : $data;
    }

    /** `download_chunk(key, range)`, an inclusive range. */
    public function downloadChunk(string $key, int $start, int $end): string
    {
        $file = @fopen($this->pathFor($key), 'r');
        if (false === $file) {
            throw new FileNotFound($key);
        }
        try {
            fseek($file, $start);

            return (string) stream_get_contents($file, $end - $start + 1);
        } finally {
            fclose($file);
        }
    }

    public function delete(string $key): void
    {
        $path = $this->pathFor($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** `delete_prefixed(prefix)`: `rm_rf` everything matching `path_for("#{prefix}*")`. */
    public function deletePrefixed(string $prefix): void
    {
        foreach (glob($this->pathFor($prefix).'*') ?: [] as $path) {
            self::removeTree($path);
        }
    }

    /**
     * The path of `service.url(key, expires_in:, filename:, content_type:, disposition:)`; the
     * caller adds the request's scheme and host (`ActiveStorage::Current.url_options`).
     */
    public function urlPath(string $key, ?\DateTimeInterface $expiresAt, Filename $filename, ?string $contentType, ?string $disposition): string
    {
        $sanitized = $filename->sanitized();
        $token = $this->verifier->generate([
            'key' => $key,
            'disposition' => ContentDisposition::with($disposition, $sanitized),
            'content_type' => $contentType,
            'service_name' => self::NAME,
        ], 'blob_key', $expiresAt);

        return '/rails/active_storage/disk/'.Paths::escapeSegment($token).'/'.Paths::escapePath($sanitized);
    }

    /** The path of `url_for_direct_upload(key, expires_in:, content_type:, content_length:, checksum:)`. */
    public function directUploadPath(string $key, \DateTimeInterface $expiresAt, ?string $contentType, int $contentLength, string $checksum): string
    {
        $token = $this->verifier->generate([
            'key' => $key,
            'content_type' => $contentType,
            'content_length' => $contentLength,
            'checksum' => $checksum,
            'service_name' => self::NAME,
        ], 'blob_token', $expiresAt);

        return '/rails/active_storage/disk/'.Paths::escapeSegment($token);
    }

    /**
     * `DiskController#decode_verified_key`.
     *
     * @return array{key: string, disposition: ?string, content_type: ?string, service_name: ?string}|null
     */
    public function decodeKey(string $encodedKey, \DateTimeInterface $now): ?array
    {
        $data = $this->verifier->verified($encodedKey, 'blob_key', $now);
        if (!\is_array($data) || !\is_string($data['key'] ?? null)) {
            return null;
        }

        return [
            'key' => $data['key'],
            'disposition' => \is_string($data['disposition'] ?? null) ? $data['disposition'] : null,
            'content_type' => \is_string($data['content_type'] ?? null) ? $data['content_type'] : null,
            'service_name' => \is_string($data['service_name'] ?? null) ? $data['service_name'] : null,
        ];
    }

    /**
     * `DiskController#decode_verified_token`.
     *
     * @return array<string, mixed>|null
     */
    public function decodeToken(string $encodedToken, \DateTimeInterface $now): ?array
    {
        $data = $this->verifier->verified($encodedToken, 'blob_token', $now);

        return \is_array($data) && \is_string($data['key'] ?? null) ? $data : null;
    }

    /** `OpenSSL::Digest::MD5.file(path).base64digest` */
    public static function checksumFile(string $path): string
    {
        $md5 = md5_file($path, true);

        return false === $md5 ? throw new FileNotFound($path) : base64_encode($md5);
    }

    private function makePathFor(string $key): string
    {
        $path = $this->pathFor($key);
        $dir = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Cannot create %s.', $dir));
        }

        return $path;
    }

    private static function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ('.' !== $entry && '..' !== $entry) {
                    self::removeTree($path.'/'.$entry);
                }
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
}
