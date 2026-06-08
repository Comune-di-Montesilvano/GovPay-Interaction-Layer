<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
namespace App\Security;

use App\Logger;

class Crypto
{
    private const CIPHER_ALGO_GCM = 'aes-256-gcm';
    private const CIPHER_ALGO_CBC = 'aes-256-cbc';

    /**
     * Get the encryption key.
     * Priority: config.json (app.encryption_key) → env APP_ENCRYPTION_KEY → $_ENV
     * @return string
     * @throws \RuntimeException if the key is not set or invalid
     */
    private static function getKey(): string
    {
        $key = $_ENV['APP_ENCRYPTION_KEY'] ?? getenv('APP_ENCRYPTION_KEY');

        if (empty($key)) {
            throw new \RuntimeException('APP_ENCRYPTION_KEY is not configured in the environment.');
        }

        if (strlen((string)$key) < 32) {
            throw new \RuntimeException('APP_ENCRYPTION_KEY must be at least 32 characters for AES-256.');
        }

        return (string)$key;
    }

    /**
     * Encrypt a string using AES-256-GCM.
     * The result is prefixed with "v2:" and followed by a base64 encoded string
     * containing the IV, GCM tag, and ciphertext.
     * 
     * @param string $data The cleartext data
     * @return string The encrypted data prefixed with v2:
     */
    public static function encrypt(string $data): string
    {
        if (empty($data)) {
            return $data;
        }

        $key = self::getKey();
        $ivLength = openssl_cipher_iv_length(self::CIPHER_ALGO_GCM);
        $iv = random_bytes($ivLength);
        $tag = '';
        
        $ciphertext = openssl_encrypt($data, self::CIPHER_ALGO_GCM, $key, OPENSSL_RAW_DATA, $iv, $tag);
        
        if ($ciphertext === false) {
            Logger::getInstance()->error('Errore durante la cifratura del dato (openssl_encrypt fallito)', [
                'error' => openssl_error_string()
            ]);
            throw new \RuntimeException('Encryption failed.');
        }

        // Prepend v2: and Base64 of IV + tag + ciphertext
        return 'v2:' . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a string that was encrypted by self::encrypt.
     * If decryption fails (e.g. data is not encrypted, wrong key, etc.),
     * it logs a warning and returns the original string or throws an exception.
     * For backward compatibility during migration, we return the original string if it's not base64 or decryption fails.
     * 
     * @param string $encryptedData The base64 encrypted data (or prefixed v2: version)
     * @return string The cleartext data
     */
    public static function decrypt(string $encryptedData): string
    {
        if (empty($encryptedData)) {
            return $encryptedData;
        }

        // Check for v2 (AES-GCM) prefix
        if (str_starts_with($encryptedData, 'v2:')) {
            $payload = substr($encryptedData, 3);
            $decoded = base64_decode($payload, true);
            if ($decoded === false) {
                Logger::getInstance()->warning('Decifratura fallita. Payload v2 non è base64 valido.');
                return $encryptedData;
            }

            $ivLength = openssl_cipher_iv_length(self::CIPHER_ALGO_GCM);
            $tagLength = 16; // AES-GCM standard tag length is 16 bytes

            if (strlen($decoded) <= ($ivLength + $tagLength)) {
                Logger::getInstance()->warning('Decifratura fallita. Payload v2 troppo corto.');
                return $encryptedData;
            }

            $iv = substr($decoded, 0, $ivLength);
            $tag = substr($decoded, $ivLength, $tagLength);
            $ciphertext = substr($decoded, $ivLength + $tagLength);
            $key = self::getKey();

            $cleartext = openssl_decrypt($ciphertext, self::CIPHER_ALGO_GCM, $key, OPENSSL_RAW_DATA, $iv, $tag);

            if ($cleartext === false) {
                Logger::getInstance()->warning('Decifratura GCM fallita.', [
                    'error' => openssl_error_string()
                ]);
                return $encryptedData;
            }

            return $cleartext;
        }

        // Fallback to legacy CBC decryption
        $decoded = base64_decode($encryptedData, true);
        
        // If it's not valid base64, return original (assume it's cleartext)
        if ($decoded === false) {
            return $encryptedData;
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER_ALGO_CBC);
        
        // If the decoded string is too short to even contain the IV, return original
        if (strlen($decoded) <= $ivLength) {
            return $encryptedData;
        }

        $iv = substr($decoded, 0, $ivLength);
        $ciphertext = substr($decoded, $ivLength);
        $key = self::getKey();

        $cleartext = openssl_decrypt($ciphertext, self::CIPHER_ALGO_CBC, $key, OPENSSL_RAW_DATA, $iv);

        if ($cleartext === false) {
            // Decryption failed. This might happen if the key changed, 
            // or if the original string was coincidentally valid base64 but not encrypted with this method.
            Logger::getInstance()->warning('Decifratura fallita. Potrebbe essere un dato in chiaro o la chiave è errata.', [
                'error' => openssl_error_string()
            ]);
            return $encryptedData; // Fallback to original
        }

        return $cleartext;
    }
}
