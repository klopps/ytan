<?php

declare(strict_types=1);

namespace Ytan\Service;

/**
 * Reversible encryption (AES-256-GCM) of a Garmin watch key at rest, so YTAN
 * can rebuild the watch's settings file (WatchSettingsFile) until a new key
 * is created. The device endpoint still only ever compares the key's hash;
 * this only protects against a database dump on its own - whoever holds the
 * database AND JWT_SECRET can read the keys. What a leaked key gives access
 * to is small: the route currently "sent to the watch" of that one user.
 */
final class WatchKeyVault
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    private readonly string $key;

    public function __construct(string $secret)
    {
        // A separate derived key, so the encryption key isn't the JWT signing key itself.
        $this->key = hash('sha256', 'ytan-watch-key-vault|' . $secret, true);
    }

    /** @return string base64 of iv + tag + ciphertext */
    public function encrypt(string $plain): string
    {
        $iv = random_bytes(self::IV_BYTES);
        $cipher = openssl_encrypt($plain, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES);
        if ($cipher === false) {
            throw new \RuntimeException('Encrypting the watch key failed.');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    /** @return string|null the key, or null if the text isn't ours or was encrypted with another secret */
    public function decrypt(string $encrypted): ?string
    {
        $raw = base64_decode($encrypted, true);
        if ($raw === false || strlen($raw) <= self::IV_BYTES + self::TAG_BYTES) {
            return null;
        }
        $iv = substr($raw, 0, self::IV_BYTES);
        $tag = substr($raw, self::IV_BYTES, self::TAG_BYTES);
        $plain = openssl_decrypt(substr($raw, self::IV_BYTES + self::TAG_BYTES), self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? null : $plain;
    }
}
