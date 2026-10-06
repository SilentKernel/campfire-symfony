<?php

declare(strict_types=1);

namespace App\Tests\Functional\ActiveStorage;

use App\Rails\RailsJson;
use App\Tests\Functional\Storage\StorageTestCase;

final class DirectUploadsTest extends StorageTestCase
{
    private const string BODY = 'hello';

    public function testTheDirectUploadFlow(): void
    {
        $client = $this->client();
        $token = self::signInWithCsrf($client);
        $checksum = base64_encode(md5(self::BODY, true));

        $client->request('POST', '/rails/active_storage/direct_uploads', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], content: RailsJson::encode(['blob' => ['filename' => 'hello.txt', 'content_type' => 'text/plain', 'byte_size' => 5, 'checksum' => $checksum]]));
        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/json; charset=utf-8', $response->headers->get('Content-Type'));

        $json = RailsJson::decode((string) $response->getContent());
        self::assertSame(['id', 'byte_size', 'checksum', 'content_type', 'created_at', 'filename', 'key', 'metadata', 'service_name', 'attachable_sgid', 'signed_id', 'direct_upload'], array_keys($json));
        self::assertSame('2026-03-02T16:00:00.000Z', $json['created_at']);
        self::assertSame([], $json['metadata']);
        self::assertStringContainsString('"metadata":{}', (string) $response->getContent());
        self::assertSame(['Content-Type' => 'text/plain'], $json['direct_upload']['headers']);
        self::assertNull($this->connection()->fetchOne('SELECT metadata FROM active_storage_blobs WHERE id = ?', [$json['id']]));
        self::assertFileDoesNotExist($this->file($json['key']));

        // The bytes go to the signed disk URL, with the declared type and length.
        $url = $json['direct_upload']['url'];
        self::assertStringStartsWith('http://localhost/rails/active_storage/disk/', $url);
        $client->request('PUT', $url, server: ['CONTENT_TYPE' => 'text/plain', 'CONTENT_LENGTH' => '5'], content: self::BODY);
        self::assertSame(204, $client->getResponse()->getStatusCode());
        self::assertSame(self::BODY, file_get_contents($this->file($json['key'])));
    }

    public function testUploadsNeedASessionAndACsrfToken(): void
    {
        $client = $this->client();
        $client->request('POST', '/rails/active_storage/direct_uploads', server: ['CONTENT_TYPE' => 'application/json'], content: '{"blob":{"filename":"a"}}');
        self::assertSame(422, $client->getResponse()->getStatusCode(), 'CSRF first');

        $token = self::signInWithCsrf($client);
        $client->getCookieJar()->expire('session_token');
        $client->request('POST', '/rails/active_storage/direct_uploads', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: '{"blob":{"filename":"a"}}');
        self::assertSame(401, $client->getResponse()->getStatusCode());
        self::assertSame(14, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM active_storage_blobs'));
    }

    public function testMissingParametersAndChecksum(): void
    {
        $client = $this->client();
        $token = self::signInWithCsrf($client);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token];

        $client->request('POST', '/rails/active_storage/direct_uploads', server: $headers, content: '{}');
        self::assertSame(400, $client->getResponse()->getStatusCode());
        $client->request('POST', '/rails/active_storage/direct_uploads', server: $headers, content: '{"blob":{"filename":"a.txt","byte_size":1}}');
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $client->request('POST', '/rails/active_storage/direct_uploads', server: $headers, content: '{"blob":{"filename":"a.txt","byte_size":1,"checksum":"x","metadata":{"a":1}}}');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('{"a":1}', $this->connection()->fetchOne('SELECT metadata FROM active_storage_blobs ORDER BY id DESC LIMIT 1'));
    }

    public function testDiskUpdateChecksTheTokenTheContentAndTheChecksum(): void
    {
        $client = $this->client();
        $disk = static::getContainer()->get(\App\Storage\DiskService::class);
        $expiresAt = new \DateTimeImmutable('2026-03-02T16:05:00Z');
        $path = $disk->directUploadPath('abcdefghijklmnopqrstuvwxyz12', $expiresAt, 'text/plain', 5, base64_encode(md5(self::BODY, true)));

        $client->request('PUT', $path, server: ['CONTENT_TYPE' => 'text/plain', 'CONTENT_LENGTH' => '5'], content: self::BODY);
        self::assertSame(401, $client->getResponse()->getStatusCode(), 'require_active_storage_authentication');

        self::setRawCookie($client, 'session_token', self::DAVID_COOKIE);
        $client->request('PUT', $path, server: ['CONTENT_TYPE' => 'image/png', 'CONTENT_LENGTH' => '5'], content: self::BODY);
        self::assertSame(422, $client->getResponse()->getStatusCode(), 'content type');
        $client->request('PUT', $path, server: ['CONTENT_TYPE' => 'text/plain', 'CONTENT_LENGTH' => '5'], content: 'HELLO');
        self::assertSame(422, $client->getResponse()->getStatusCode(), 'checksum');
        self::assertFileDoesNotExist($this->file('abcdefghijklmnopqrstuvwxyz12'));
        $client->request('PUT', '/rails/active_storage/disk/bogus', server: ['CONTENT_TYPE' => 'text/plain', 'CONTENT_LENGTH' => '5'], content: self::BODY);
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $client->request('PUT', $path, server: ['CONTENT_TYPE' => 'text/plain; charset=utf-8', 'CONTENT_LENGTH' => '5'], content: self::BODY);
        self::assertSame(204, $client->getResponse()->getStatusCode());
        self::assertFileExists($this->file('abcdefghijklmnopqrstuvwxyz12'));
    }
}
