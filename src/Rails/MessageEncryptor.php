<?php

declare(strict_types=1);

namespace App\Rails;

/**
 * ActiveSupport::MessageEncryptor with aes-256-gcm (use_authenticated_message_encryption, as the
 * encrypted cookie jar builds it): "<base64 ciphertext>--<base64 12-byte IV>--<base64 16-byte
 * auth tag>", strict Base64, empty auth data, no separate signature
 * (activesupport/lib/active_support/message_encryptor.rb).
 */
final class MessageEncryptor
{
    private const string CIPHER = 'aes-256-gcm';
    private const int IV_LENGTH = 12;
    private const int AUTH_TAG_LENGTH = 16;
    private const int ENCODED_IV_LENGTH = 16;
    private const int ENCODED_AUTH_TAG_LENGTH = 24;

    /** @param string $secret 32 raw bytes, e.g. KeyGenerator::generateKey($salt, 32) */
    public function __construct(
        #[\SensitiveParameter] private readonly string $secret,
        private readonly Serializer $serializer = Serializer::JsonAllowMarshal,
    ) {
        if (32 !== \strlen($secret)) {
            throw new \InvalidArgumentException('aes-256-gcm needs a 32-byte key.');
        }
    }

    public function encryptAndSign(mixed $value, ?string $purpose = null, ?\DateTimeInterface $expiresAt = null): string
    {
        $plaintext = Metadata::serialize($this->serializer, $value, $purpose, $expiresAt);
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->secret, \OPENSSL_RAW_DATA, $iv, $tag, '', self::AUTH_TAG_LENGTH);
        if (false === $ciphertext) {
            throw new \RuntimeException('aes-256-gcm encryption failed.');
        }

        return implode('--', array_map(Base64::strictEncode(...), [$ciphertext, $iv, $tag]));
    }

    /** decrypt_and_verify; null wherever Rails raises InvalidMessage or returns nil. */
    public function decryptAndVerify(?string $message, ?string $purpose = null, ?\DateTimeInterface $now = null): mixed
    {
        if (null === $message) {
            return null;
        }
        $plaintext = $this->decrypt($message);
        if (null === $plaintext) {
            return null;
        }

        try {
            return Metadata::deserialize($this->serializer, $plaintext, $purpose, $now ?? new \DateTimeImmutable(), Base64::strictDecode(...));
        } catch (InvalidMessage) {
            return null;
        }
    }

    /** The decrypted bytes before any envelope handling; null if the message is not authentic. */
    public function decrypt(string $message): ?string
    {
        // extract_parts: fixed-length IV and auth tag at the end, each preceded by "--".
        $tagStart = \strlen($message) - self::ENCODED_AUTH_TAG_LENGTH;
        $ivStart = $tagStart - 2 - self::ENCODED_IV_LENGTH;
        $ciphertextEnd = $ivStart - 2;
        if ($ciphertextEnd < 0 || '--' !== substr($message, $tagStart - 2, 2) || '--' !== substr($message, $ciphertextEnd, 2)) {
            return null;
        }

        $ciphertext = Base64::strictDecode(substr($message, 0, $ciphertextEnd));
        $iv = Base64::strictDecode(substr($message, $ivStart, self::ENCODED_IV_LENGTH));
        $tag = Base64::strictDecode(substr($message, $tagStart));
        if (null === $ciphertext || self::IV_LENGTH !== \strlen($iv ?? '') || self::AUTH_TAG_LENGTH !== \strlen($tag ?? '')) {
            return null;
        }

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->secret, \OPENSSL_RAW_DATA, (string) $iv, $tag, '');

        return false === $plaintext ? null : $plaintext;
    }
}
