<?php

declare(strict_types=1);

namespace App\RichText\Attachables;

use App\Rails\Base64;
use App\Rails\GlobalId;
use App\Rails\SignedGlobalId;
use App\RichText\RichTextError;
use App\RichText\Ruby;
use Symfony\Component\Clock\ClockInterface;

/**
 * The two record lookups Action Text performs for an attachment node: a signed GlobalID located
 * for the "attachable" purpose, and Campfire's unsigned fallback
 * (reference/lib/rails_ext/action_text_attachables.rb). Campfire's only attachables are users.
 */
final readonly class AttachableLocator
{
    public function __construct(
        private SignedGlobalId $signedGlobalIds,
        private MentionUsers $users,
        private ClockInterface $clock,
    ) {
    }

    /** `GlobalID::Locator.locate_signed(sgid, for: "attachable")` */
    public function locateSigned(string $sgid): SignedLookup
    {
        $located = $this->signedGlobalIds->locate($sgid, SignedGlobalId::ATTACHABLE_PURPOSE, $this->clock->now());
        if (null === $located) {
            return new SignedLookup();
        }
        if ('User' === $located['model'] && 1 === preg_match('/\A[0-9]+\z/', $located['id'])) {
            $user = $this->users->find((int) $located['id']);
            if (null !== $user) {
                return new SignedLookup(user: $user);
            }
        }

        return new SignedLookup(missingModel: $located['model']);
    }

    /**
     * `attachable_from_possibly_expired_sgid`: reads the GlobalID out of an SGID without checking
     * its signature, and only ever returns a User. Raises where Rails raises (bad Base64 or JSON,
     * a payload that isn't an envelope).
     *
     * @throws RichTextError
     */
    public function userFromPossiblyExpiredSgid(?string $sgid): ?MentionUser
    {
        if (null === $sgid) {
            return null;
        }
        // `sgid.split("--").first`: Ruby drops trailing empty fields, so "" and "--" have no first
        $fields = explode('--', $sgid);
        while ([] !== $fields && '' === end($fields)) {
            array_pop($fields);
        }
        if ([] === $fields) {
            return null;
        }
        $json = self::decodeBase64($fields[0]);
        try {
            $envelope = Ruby::jsonParse($json);
        } catch (\JsonException) {
            throw new RichTextError('JSON::ParserError');
        }
        if (!$envelope instanceof \stdClass) {
            throw new RichTextError(\is_array($envelope) ? 'TypeError' : 'NoMethodError: dig');
        }
        $rails = $envelope->_rails ?? null;
        if (null !== $rails && !$rails instanceof \stdClass) {
            throw new RichTextError('TypeError: dig');
        }
        $data = $rails->data ?? null;
        $message = $rails->message ?? null;
        if (null !== $data && false !== $data) {
            // GlobalID.find of anything but a string finds nothing
            $gid = \is_string($data) ? $data : null;
        } elseif (null !== $message && false !== $message) {
            // Rails 7 Marshal-dumped the GID. The signature isn't verified, so the dump can't be
            // safely loaded; the GID is matched out of its bytes instead.
            if (!\is_string($message)) {
                throw new RichTextError('NoMethodError: unpack1');
            }
            $gid = 1 === preg_match('~(gid://campfire/[^/]+/[0-9]+)~', self::decodeBase64($message), $m) ? $m[1] : null;
        } else {
            $gid = null;
        }
        if (null === $gid) {
            return null;
        }

        return $this->findUserByGid($gid);
    }

    /**
     * `GlobalID.find(gid)` kept to users, as the fallback only permits them: another model's
     * record (found or not) is nil either way.
     */
    private function findUserByGid(string $gid): ?MentionUser
    {
        $located = GlobalId::parse($gid);
        if (null === $located || 'User' !== $located['model'] || 1 !== preg_match('/\A[0-9]+\z/', $located['id'])) {
            return null;
        }

        return $this->users->find((int) $located['id']);
    }

    /**
     * `Base64.strict_decode64(message) rescue Base64.urlsafe_decode64(message)`.
     *
     * @throws RichTextError
     */
    private static function decodeBase64(string $message): string
    {
        return Base64::strictDecode($message) ?? Base64::urlsafeDecode($message) ?? throw new RichTextError('ArgumentError: invalid base64');
    }
}
