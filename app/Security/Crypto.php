<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
namespace App\Security;

use App\Logger;
use App\Config\ConfigLoader;

class Crypto
{
    private const CIPHER_ALGO = 'aes-256-cbc';

    /**
     * Get the encryption key.
     * Priority: config.json (app.encryption_key) → env APP_ENCRYPTION_KEY → $_ENV
     * @return string
     * @throws \RuntimeException if the key is not set or invalid
     */
    private static function getKey(): string
    {
        // Prima priorità: config.json
        $key = ConfigLoader::get('app.encryption_key');

        // Fallback: variabile d'ambiente (compatibilità .env legacy)
        if (empty($key)) {
            $key = $_ENV['APP_ENCRYPTION_KEY'] ?? getenv('APP_ENCRYPTION_KEY');
        }

        if (empty($key)) {
            throw new \RuntimeException('APP_ENCRYPTION_KEY is not configured in the environment.');
        }

        return (string)$key;
    }

    /**
     * Encrypt a string using AES-256-CBC.
     * The result is a generic string (base64 of IV + ciphertext) safe for DB storage.
     * 
     * @param string $data The cleartext data
     * @return string The encrypted data encoded in base64
     */
    public static function encrypt(string $data): string
    {
        if (empty($data)) {
            return $data;
        }

        $key = self::getKey();
        $ivLength = openssl_cipher_iv_length(self::CIPHER_ALGO);
        $iv = openssl_random_pseudo_bytes($ivLength);
        
        $ciphertext = openssl_encrypt($data, self::CIPHER_ALGO, $key, OPENSSL_RAW_DATA, $iv);
        
        if ($ciphertext === false) {
            Logger::getInstance()->error('Errore durante la cifratura del dato (openssl_encrypt fallito)', [
                'error' => openssl_error_string()
            ]);
            throw new \RuntimeException('Encryption failed.');
        }

        // Prepend IV to ciphertext and encode in Base64
        return base64_encode($iv . $ciphertext);
    }

    /**
     * Decrypt a string that was encrypted by self::encrypt.
     * If decryption fails (e.g. data is not encrypted, wrong key, etc.),
     * it logs a warning and returns the original string or throws an exception.
     * For backward compatibility during migration, we can return the original string if it's not base64 or decryption fails.
     * 
     * @param string $encryptedData The base64 encrypted data
     * @return string The cleartext data
     */
    public static function decrypt(string $encryptedData): string
    {
        if (empty($encryptedData)) {
            return $encryptedData;
        }

        $decoded = base64_decode($encryptedData, true);
        
        // If it's not valid base64, return original (assume it's cleartext)
        if ($decoded === false) {
            return $encryptedData;
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER_ALGO);
        
        // If the decoded string is too short to even contain the IV, return original
        if (strlen($decoded) <= $ivLength) {
            return $encryptedData;
        }

        $iv = substr($decoded, 0, $ivLength);
        $ciphertext = substr($decoded, $ivLength);
        $key = self::getKey();

        $cleartext = openssl_decrypt($ciphertext, self::CIPHER_ALGO, $key, OPENSSL_RAW_DATA, $iv);

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
