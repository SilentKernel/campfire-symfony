<?php

declare(strict_types=1);

namespace App\Tests\Functional\Storage;

use App\Rails\SignedId;
use App\Storage\Job\PurgeBlob;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class AvatarsTest extends StorageTestCase
{
    private const int DAVID = 127326141;
    private const int JASON = 149087659;
    private const int BENDER = 394959859;

    /** What the reference sends for David, who has no avatar (users/avatars/show.svg.erb). */
    private const string DAVID_SVG = <<<'SVG'
        <svg version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"
          viewBox="0 0 512 512" class="avatar" aria-hidden="true">
          <defs>
            <clipPath id="porthole">
              <circle cx="50%" cy="50%" r="50%" />
            </clipPath>
          </defs>

          <g>
            <rect width="100%" height="100%" rx="50" fill="#736356" />

            <text x="50%" y="50%" fill="#FFFFFF"
              text-anchor="middle" dy="0.35em"
              
              font-family="-apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica, Arial, sans-serif"
              font-size="230"
              font-weight="800"
              letter-spacing="-5">
              D
            </text>
          </g>
        </svg>

        SVG;

    private const string BROWSER_ACCEPT = 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8';

    private function avatarPath(int $userId): string
    {
        return '/users/'.static::getContainer()->get(SignedId::class)->generate($userId, 'User', 'avatar').'/avatar';
    }

    public function testTheSquareVariantOfAnUploadedAvatar(): void
    {
        $client = $this->client(signedIn: true);
        // The token the reference renders for Jason.
        $path = '/users/eyJfcmFpbHMiOnsiZGF0YSI6MTQ5MDg3NjU5LCJwdXIiOiJ1c2VyL2F2YXRhciJ9fQ--3717de512c048bb2a011a08236969887ee56ae9f8bd2fb510959b9915f449e17/avatar?v=20260125160000';
        self::assertSame($path, $this->avatarPath(self::JASON).'?v=20260125160000');
        $client->request('GET', $path, server: ['HTTP_ACCEPT' => '*/*']);
        $response = $client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/webp', $response->headers->get('Content-Type'));
        self::assertSame('max-age=1800, public, stale-while-revalidate=604800', $response->headers->get('Cache-Control'));
        self::assertSame('W/"a0eab39abba52aa6f852cb93e1244bd7"', $response->headers->get('ETag'));
        self::assertSame("inline; filename=\"5nlq52kvmcweobxncp0zy1zvu9bg\"; filename*=UTF-8''5nlq52kvmcweobxncp0zy1zvu9bg", $response->headers->get('Content-Disposition'));
        self::assertSame('binary', $response->headers->get('Content-Transfer-Encoding'));
        // Thruster drops Set-Cookie (session_token refresh included) from publicly cacheable responses.
        self::assertSame([], $response->headers->getCookies());
        self::assertSame(md5_file($this->file('5nlq52kvmcweobxncp0zy1zvu9bg')), md5((string) $client->getInternalResponse()->getContent()));

        $client->request('GET', $path, server: ['HTTP_ACCEPT' => '*/*', 'HTTP_IF_NONE_MATCH' => 'W/"a0eab39abba52aa6f852cb93e1244bd7"']);
        self::assertSame(304, $client->getResponse()->getStatusCode());
    }

    public function testInitialsWithoutAnAvatar(): void
    {
        $client = $this->client(signedIn: true);
        $client->request('GET', $this->avatarPath(self::DAVID), server: ['HTTP_ACCEPT' => self::BROWSER_ACCEPT]);
        $response = $client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/svg+xml; charset=utf-8', $response->headers->get('Content-Type'));
        // The reference's ETag: a browser Accept makes the format html, so no template digest.
        self::assertSame('W/"6bd452b9114127dff5c3f46f079f7a4a"', $response->headers->get('ETag'));
        self::assertSame('max-age=1800, public, stale-while-revalidate=604800', $response->headers->get('Cache-Control'));
        self::assertSame(self::DAVID_SVG, $response->getContent());
    }

    public function testTheDefaultBotAvatar(): void
    {
        $client = $this->client(signedIn: true);
        $client->request('GET', $this->avatarPath(self::BENDER));
        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/svg+xml', $response->headers->get('Content-Type'));
        self::assertSame("inline; filename=\"default-bot-avatar.svg\"; filename*=UTF-8''default-bot-avatar.svg", $response->headers->get('Content-Disposition'));
        self::assertSame(file_get_contents(\dirname(__DIR__, 3).'/reference/app/assets/images/default-bot-avatar.svg'), $client->getInternalResponse()->getContent());
    }

    public function testABadTokenIsNotFoundAndAnonymousRequestsSignIn(): void
    {
        $client = $this->client(signedIn: true);
        $client->request('GET', '/users/bogus/avatar');
        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame('', $client->getResponse()->getContent());

        $client->getCookieJar()->expire('session_token');
        $client->request('GET', $this->avatarPath(self::DAVID));
        self::assertSame(302, $client->getResponse()->getStatusCode());
    }

    public function testDestroyRemovesTheAvatarAndPurgesItsBlobLater(): void
    {
        $client = $this->client();
        $token = self::signInWithCsrf($client);
        // David signs in; give him Jason's avatar blob first through the services.
        $attachments = static::getContainer()->get(\App\Storage\Attachments::class);
        $blobs = static::getContainer()->get(\App\Storage\BlobService::class);
        $blob = $blobs->createFromFile(\dirname(__DIR__, 3).'/reference/test/fixtures/files/moon.jpg', 'moon.jpg', 'image/jpeg');
        $attachments->attach('User', self::DAVID, 'avatar', $blob);

        $client->request('DELETE', '/users/me/avatar', server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('http://localhost/users/me/profile', $client->getResponse()->headers->get('Location'));
        self::assertNull($attachments->find('User', self::DAVID, 'avatar'));

        $transport = static::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);
        $purges = array_filter($transport->getSent(), static fn ($envelope): bool => $envelope->getMessage() instanceof PurgeBlob);
        self::assertSame([$blob->getId()], array_values(array_map(static fn ($envelope): int => $envelope->getMessage()->blobId, $purges)));
    }
}
