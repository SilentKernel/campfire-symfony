<?php

declare(strict_types=1);

namespace App\Controller\ActiveStorage;

use App\Http\Attribute\NotApplicationController;
use App\Http\Mime;
use App\Http\Params;
use App\Rails\RailsJson;
use App\Rails\SignedGlobalId;
use App\Security\Authentication;
use App\Storage\BlobService;
use App\Storage\DiskService;
use App\Storage\Http\Responses;
use App\Storage\ServiceUrls;
use App\Storage\StorageUrls;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ActiveStorage::DirectUploadsController: creates the blob row before a direct upload and answers
 * with its JSON and the signed disk URL to PUT the bytes to. Campfire requires a session
 * (reference/config/initializers/active_storage_authentication.rb); the framework-wide CSRF check
 * runs first.
 */
#[NotApplicationController]
#[Route(defaults: ['_format' => null])]
final class DirectUploadsController extends AbstractController
{
    public function __construct(
        private readonly Authentication $authentication,
        private readonly BlobService $blobs,
        private readonly DiskService $disk,
        private readonly StorageUrls $urls,
        private readonly SignedGlobalId $signedGlobalId,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/rails/active_storage/direct_uploads.{_format}', name: 'rails_direct_uploads', methods: ['POST'], priority: 1)]
    public function create(Request $request): Response
    {
        if (null === $this->authentication->findSessionByCookie()) {
            return Responses::head($request, 401, fromBeforeAction: true);
        }

        // params.expect(blob: [:filename, :byte_size, :checksum, :content_type, metadata: {}])
        $params = Params::fromRequest($request);
        $args = $params->expect('blob', ['filename', 'byte_size', 'checksum', 'content_type']);
        // `metadata: {}` permits any hash
        $rawMetadata = $params->all()['blob']['metadata'] ?? null;
        $filename = $args['filename'] ?? null;
        $byteSize = $args['byte_size'] ?? null;
        if (!\is_scalar($filename) || !is_numeric($byteSize)) {
            throw new \InvalidArgumentException('NOT NULL constraint failed'); // ActiveRecord::NotNullViolation
        }
        $checksum = $args['checksum'] ?? null;
        if (Params::isBlank($checksum) || !\is_scalar($checksum)) {
            throw new HttpException(422, "Validation failed: Checksum can't be blank"); // ActiveRecord::RecordInvalid
        }
        $contentType = isset($args['content_type']) && \is_scalar($args['content_type']) ? (string) $args['content_type'] : null;
        $metadata = \is_array($rawMetadata) && (!array_is_list($rawMetadata) || [] === $rawMetadata) ? $rawMetadata : null;

        $blob = $this->blobs->createBeforeDirectUpload((string) $filename, (int) $byteSize, (string) $checksum, $contentType, $metadata);

        $expiresAt = $this->clock->now()->modify('+'.ServiceUrls::EXPIRES_IN.' seconds');
        $json = [
            'id' => $blob->getId(),
            'byte_size' => $blob->getByteSize(),
            'checksum' => $blob->getChecksum(),
            'content_type' => $blob->getContentType(),
            'created_at' => RailsJson::time($blob->getCreatedAt()),
            'filename' => $blob->getFilename(),
            'key' => $blob->getKey(),
            'metadata' => [] === $blob->getMetadata() ? new \stdClass() : $blob->getMetadata(),
            'service_name' => $blob->getServiceName(),
            'attachable_sgid' => $this->signedGlobalId->generate('ActiveStorage::Blob', $blob->getId()),
            'signed_id' => $this->urls->signedId($blob),
            'direct_upload' => [
                'url' => $request->getSchemeAndHttpHost().$this->disk->directUploadPath($blob->getKey(), $expiresAt, $blob->getContentType(), $blob->getByteSize(), (string) $blob->getChecksum()),
                'headers' => ['Content-Type' => $blob->getContentType()],
            ],
        ];

        $response = new Response(RailsJson::encode($json), 200, ['Content-Type' => 'application/json; charset=utf-8']);
        if (Mime::shouldApplyVaryHeader($request)) {
            $response->headers->set('Vary', 'Accept');
        }

        return $response;
    }
}
