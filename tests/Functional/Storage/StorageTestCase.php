<?php

declare(strict_types=1);

namespace App\Tests\Functional\Storage;

use App\Rails\CsrfToken;
use App\Tests\Functional\Http\HttpTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Seed-backed tests for Active Storage. Message ids, blobs and files come from
 * var/seed/default (labels.json); David's session cookie was signed by the Rails app.
 */
abstract class StorageTestCase extends HttpTestCase
{
    /** messages.image: moon.jpg, blob 5, its :thumb variant is blob 6. */
    protected const string MOON_SIGNED_ID = 'eyJfcmFpbHMiOnsiZGF0YSI6NSwicHVyIjoiYmxvYl9pZCJ9fQ==--4ceb3a7460a929db324ca5fd0dffee9c8527bfad';

    /** The seed's blob keys (files under storage/files). */
    protected const string MOON_KEY = '5j2ce1yctlrqocxcrb749gk74d70';
    protected const string MOON_THUMB_KEY = '1la4o27xxpq9ksfz8queu37rsz24';

    protected function client(bool $signedIn = false): KernelBrowser
    {
        $this->setEnv('CAMPFIRE_FROZEN_TIME', (string) self::labels('clock.now'));
        $client = static::createClient();
        if ($signedIn) {
            self::setRawCookie($client, 'session_token', self::DAVID_COOKIE);
        }

        return $client;
    }

    /** Signs David in with a session holding a known CSRF secret; returns the masked token. */
    protected static function signInWithCsrf(KernelBrowser $client): string
    {
        $secret = CsrfToken::generateSecret();
        self::setRawCookie($client, 'session_token', self::DAVID_COOKIE);
        self::setRawCookie($client, '_campfire_session', self::railsCookies()->writeEncrypted('_campfire_session', ['session_id' => bin2hex(random_bytes(16)), '_csrf_token' => $secret]));

        return CsrfToken::masked($secret);
    }

    protected function file(string $key): string
    {
        return $this->storagePath.'/files/'.substr($key, 0, 2).'/'.substr($key, 2, 2).'/'.$key;
    }
}
