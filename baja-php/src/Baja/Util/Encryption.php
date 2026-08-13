<?php

namespace Baja\Util;

/**
 * Encryption utility using libsodium
 *
 * Uses sodium_crypto_secretbox for symmetric encryption with a master key,
 * read from the STORAGE_ENCRYPTION_KEY environment variable.
 *
 * Generate a key with: php -r "echo base64_encode(random_bytes(32));"
 */
class Encryption
{
    private const ENV_KEY = 'STORAGE_ENCRYPTION_KEY';

    /**
     * Encrypt data using sodium secretbox
     *
     * @param string $data Data to encrypt
     * @return string Base64-encoded encrypted data (nonce + ciphertext)
     * @throws \RuntimeException If encryption fails or key is not configured
     */
    public static function encrypt(string $data): string
    {
        $key = self::getKey();

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($data, $nonce, $key);

        // Prepend nonce to ciphertext and base64 encode
        return base64_encode($nonce . $ciphertext);
    }

    /**
     * Decrypt data encrypted with encrypt()
     *
     * @param string $encrypted Base64-encoded encrypted data
     * @return string Decrypted data
     * @throws \RuntimeException If decryption fails or key is not configured
     */
    public static function decrypt(string $encrypted): string
    {
        $key = self::getKey();

        $decoded = base64_decode($encrypted, true);
        if ($decoded === false) {
            throw new \RuntimeException('Failed to decode encrypted data');
        }

        if (strlen($decoded) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Encrypted data is too short');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed - invalid key or corrupted data');
        }

        return $plaintext;
    }

    /**
     * Encrypt JSON data
     *
     * @param array $data Data to encrypt as JSON
     * @return string Base64-encoded encrypted JSON
     * @throws \RuntimeException If encryption fails
     */
    public static function encryptJson(array $data): string
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        return self::encrypt($json);
    }

    /**
     * Decrypt JSON data
     *
     * @param string $encrypted Base64-encoded encrypted JSON
     * @return array Decrypted data as array
     * @throws \RuntimeException If decryption fails
     */
    public static function decryptJson(string $encrypted): array
    {
        $json = self::decrypt($encrypted);
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Get the encryption key from the environment.
     *
     * The VM also accepted a $_storageEncryptionKey global set in
     * config.prod.php. That file doesn't exist on this branch — secrets come
     * from the compose environment — so STORAGE_ENCRYPTION_KEY is the only
     * source.
     *
     * @return string 32-byte key for sodium secretbox
     * @throws \RuntimeException If key is not configured or invalid
     */
    private static function getKey(): string
    {
        $keyBase64 = Env::get(self::ENV_KEY);

        if ($keyBase64 === null || $keyBase64 === '') {
            throw new \RuntimeException(
                'Encryption key not configured. Set the ' . self::ENV_KEY . ' environment variable.'
            );
        }

        $key = base64_decode($keyBase64, true);
        if ($key === false) {
            throw new \RuntimeException('Invalid encryption key format - must be base64 encoded');
        }

        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException(
                'Invalid encryption key length. Expected ' . SODIUM_CRYPTO_SECRETBOX_KEYBYTES .
                ' bytes, got ' . strlen($key)
            );
        }

        return $key;
    }

    /**
     * Generate a new random encryption key
     *
     * Use this to generate a key for the STORAGE_ENCRYPTION_KEY env var.
     *
     * @return string Base64-encoded 32-byte key
     */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    /**
     * Check if encryption is properly configured
     *
     * @return bool True if encryption key is available
     */
    public static function isConfigured(): bool
    {
        try {
            self::getKey();
            return true;
        } catch (\RuntimeException $e) {
            return false;
        }
    }
}
