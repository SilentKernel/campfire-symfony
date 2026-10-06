<?php

declare(strict_types=1);

namespace App\Rails;

/**
 * SignedGlobalID (globalid 1.3 lib/global_id/signed_global_id.rb) as Campfire uses it for
 * Action Text attachables (@mentions).
 *
 * SGIDs are signed by GlobalID::Verifier: key generate_key("signed_global_ids"), HMAC-SHA1,
 * URL-safe Base64 *with* padding, the app's :json_allow_marshal serializer and the
 * {"_rails":{"data","exp","pur"}} envelope. Rails 7.0 SGIDs used the legacy envelope with a
 * Marshal-dumped string and globalid < 1.0 a self-validated {"gid","purpose","expires_at"} hash;
 * both are still read.
 */
final class SignedGlobalId
{
    public const string SALT = 'signed_global_ids';
    /** ActionText::Attachable::LOCATOR_NAME. */
    public const string ATTACHABLE_PURPOSE = 'attachable';
    /** SignedGlobalID::DEFAULT_PURPOSE. */
    public const string DEFAULT_PURPOSE = 'default';
    /** ATTACHABLES_PERMITTED_WITH_INVALID_SIGNATURES (reference/lib/rails_ext/action_text_attachables.rb). */
    public const array PERMITTED_WITH_INVALID_SIGNATURES = ['User'];

    private ?MessageVerifier $verifier = null;

    public function __construct(private readonly KeyGenerator $keys)
    {
    }

    /**
     * Without $expiresAt this is record.attachable_sgid, i.e. to_sgid(expires_in: nil, for:):
     * GlobalID turns the leftover expires_in option into a query param, so the signed data is
     * "gid://campfire/User/1?expires_in" with no expiry. With $expiresAt it is
     * SignedGlobalID.new(gid, for: $purpose, expires_at: $expiresAt) over the bare GID.
     */
    public function generate(string $modelName, int|string $id, string $purpose = self::ATTACHABLE_PURPOSE, ?\DateTimeInterface $expiresAt = null): string
    {
        $gid = GlobalId::gid($modelName, $id);

        return null === $expiresAt
            ? $this->sign($gid.'?expires_in', $purpose, null)
            : $this->sign($gid, $purpose, $expiresAt);
    }

    /** SignedGlobalID.new(<gid uri string>, for:, expires_at:).to_s. */
    public function sign(string $gidUri, string $purpose = self::DEFAULT_PURPOSE, ?\DateTimeInterface $expiresAt = null): string
    {
        return $this->verifier()->generate($gidUri, $purpose, $expiresAt);
    }

    /**
     * SignedGlobalID.parse(sgid, for: purpose): the GID if signature, purpose and expiry check out.
     * Finding the record is the caller's job.
     *
     * @return array{app: string, model: string, id: string}|null
     */
    public function locate(?string $sgid, string $purpose = self::ATTACHABLE_PURPOSE, ?\DateTimeInterface $now = null): ?array
    {
        if (null === $sgid) {
            return null;
        }
        $now ??= new \DateTimeImmutable();
        $data = $this->verifier()->verified($sgid, $purpose, $now) ?? $this->verifyLegacySelfValidated($sgid, $purpose, $now);

        return \is_string($data) ? GlobalId::parse($data) : null;
    }

    /**
     * ActionText::Attachment.attachable_from_possibly_expired_sgid
     * (reference/lib/rails_ext/action_text_attachables.rb): reads the GID out of an SGID *without*
     * checking the signature, so mentions survive a SECRET_KEY_BASE rotation, and only for User.
     * The caller must still check that the user exists (GlobalID.find returns nil otherwise).
     * Where Rails raises on malformed input (bad JSON or Base64, a non-object payload) this
     * returns null.
     *
     * @return array{app: string, model: string, id: string}|null
     */
    public static function unverifiedLocate(?string $sgid): ?array
    {
        if (null === $sgid || '' === $sgid) {
            return null; // "".split("--").first is nil
        }
        $message = explode('--', $sgid)[0];

        $json = self::decodeBase64($message);
        if (null === $json) {
            return null;
        }
        try {
            $envelope = RailsJson::decode($json);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($envelope) || array_is_list($envelope) && [] !== $envelope) {
            return null;
        }

        $rails = \is_array($envelope['_rails'] ?? null) ? $envelope['_rails'] : [];
        $data = $rails['data'] ?? null;
        $legacy = $rails['message'] ?? null;
        if (null !== $data && false !== $data) {
            $gid = $data;
        } elseif (null !== $legacy && false !== $legacy) {
            // Rails 7 marshaled the GID; pull it out of the bytes rather than unmarshal unverified data.
            $dumped = \is_string($legacy) ? self::decodeBase64($legacy) : null;
            $gid = null !== $dumped && preg_match('~(gid://campfire/[^/]+/\d+)~', $dumped, $m) ? $m[1] : null;
        } else {
            return null;
        }

        $located = \is_string($gid) ? GlobalId::parse($gid) : null;

        return null !== $located && \in_array($located['model'], self::PERMITTED_WITH_INVALID_SIGNATURES, true) ? $located : null;
    }

    public function verifier(): MessageVerifier
    {
        return $this->verifier ??= new MessageVerifier($this->keys->generateKey(self::SALT), 'sha1', true, Serializer::JsonAllowMarshal, paddedUrlSafe: true);
    }

    /** globalid < 1.0 signed {"gid","purpose","expires_at"} without a Rails envelope. */
    private function verifyLegacySelfValidated(string $sgid, string $purpose, \DateTimeInterface $now): mixed
    {
        $metadata = $this->verifier()->verified($sgid, null, $now);
        if (!\is_array($metadata)) {
            return null;
        }
        $expiresAt = $metadata['expires_at'] ?? null;
        if (null !== $expiresAt && false !== $expiresAt) {
            $expiry = Metadata::parseIso8601($expiresAt);
            if (null === $expiry || $now > $expiry) {
                return null;
            }
        }

        return Metadata::rubyToS($metadata['purpose'] ?? null) === $purpose ? ($metadata['gid'] ?? null) : null;
    }

    /** decode_base64: strict_decode64 rescue urlsafe_decode64. */
    private static function decodeBase64(string $message): ?string
    {
        return Base64::strictDecode($message) ?? Base64::urlsafeDecode($message);
    }
}
