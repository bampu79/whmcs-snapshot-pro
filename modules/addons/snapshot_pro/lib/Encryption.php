<?php
/**
 * WHMCS Snapshot Pro - Encryption
 *
 * Provides AES-256-CBC encryption/decryption for snapshot archives. The
 * encryption key is supplied by the module configuration and is NEVER stored
 * inside the archive itself. A random IV is generated per operation and
 * prepended to the ciphertext so it can be recovered at decryption time.
 *
 * Files are processed in streaming chunks so that very large archives do not
 * need to be loaded entirely into memory.
 *
 * @package    SnapshotPro
 * @author     bampu79
 * @license    MIT
 */

namespace SnapshotPro;

use Exception;
use RuntimeException;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Class Encryption
 *
 * AES-256-CBC file encryption with an HMAC-SHA256 authentication tag to detect
 * tampering or an incorrect key before an archive is trusted for restore.
 */
class Encryption
{
    /** @var string OpenSSL cipher method. */
    const CIPHER = 'aes-256-cbc';

    /** @var int Chunk size (bytes) for streaming. Must be a multiple of the block size. */
    const CHUNK_SIZE = 1048576; // 1 MiB

    /** @var string Magic header written at the start of an encrypted file. */
    const MAGIC = "SNAPPRO1";

    /** @var string Raw 32-byte key derived from the configured passphrase. */
    private $key;

    /** @var string Separate 32-byte key used for the HMAC. */
    private $macKey;

    /**
     * Encryption constructor.
     *
     * @param string $passphrase The encryption passphrase from module settings.
     *
     * @throws RuntimeException If OpenSSL is unavailable or the passphrase is empty.
     */
    public function __construct($passphrase)
    {
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('The OpenSSL PHP extension is required for encryption.');
        }
        if ($passphrase === null || $passphrase === '') {
            throw new RuntimeException('An encryption key must be configured in module settings.');
        }
        // Derive two independent 32-byte keys from the passphrase using HKDF so
        // that the same passphrase never directly serves as both cipher and MAC key.
        $this->key    = hash_hkdf('sha256', (string) $passphrase, 32, 'snapshot-pro-cipher');
        $this->macKey = hash_hkdf('sha256', (string) $passphrase, 32, 'snapshot-pro-mac');
    }

    /**
     * Encrypt a source file to a destination file.
     *
     * File layout: MAGIC(8) | IV(16) | ciphertext... | HMAC(32 at end)
     * The HMAC is computed over MAGIC + IV + ciphertext.
     *
     * @param string $sourcePath      Path to the plaintext file.
     * @param string $destinationPath Path to write the encrypted file to.
     *
     * @return bool True on success.
     *
     * @throws RuntimeException On any I/O or cipher failure.
     */
    public function encryptFile($sourcePath, $destinationPath)
    {
        if (!is_readable($sourcePath)) {
            throw new RuntimeException('Encryption source file is not readable: ' . $sourcePath);
        }

        $iv = openssl_random_pseudo_bytes(16);
        if ($iv === false) {
            throw new RuntimeException('Unable to generate a secure IV.');
        }

        $in  = @fopen($sourcePath, 'rb');
        $out = @fopen($destinationPath, 'wb');
        if (!$in || !$out) {
            if ($in) { fclose($in); }
            if ($out) { fclose($out); }
            throw new RuntimeException('Unable to open files for encryption.');
        }

        // HMAC context accumulates header + all ciphertext.
        $hmacCtx = hash_init('sha256', HASH_HMAC, $this->macKey);

        fwrite($out, self::MAGIC);
        hash_update($hmacCtx, self::MAGIC);
        fwrite($out, $iv);
        hash_update($hmacCtx, $iv);

        $nextIv    = $iv;
        $carry     = '';
        try {
            while (!feof($in)) {
                $chunk = fread($in, self::CHUNK_SIZE);
                if ($chunk === false) {
                    throw new RuntimeException('Read error during encryption.');
                }
                $carry .= $chunk;
                // Only encrypt full blocks in the loop; keep remainder for final call.
                $encryptable = substr($carry, 0, intdiv(strlen($carry), 16) * 16);
                $carry       = substr($carry, strlen($encryptable));

                if ($encryptable !== '') {
                    $cipher = openssl_encrypt(
                        $encryptable,
                        self::CIPHER,
                        $this->key,
                        OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                        $nextIv
                    );
                    if ($cipher === false) {
                        throw new RuntimeException('OpenSSL encryption failed.');
                    }
                    fwrite($out, $cipher);
                    hash_update($hmacCtx, $cipher);
                    // Chain: next IV is the last ciphertext block.
                    $nextIv = substr($cipher, -16);
                }
            }

            // Final block with PKCS7 padding applied to the remaining carry.
            $finalCipher = openssl_encrypt(
                $carry,
                self::CIPHER,
                $this->key,
                OPENSSL_RAW_DATA,
                $nextIv
            );
            if ($finalCipher === false) {
                throw new RuntimeException('OpenSSL final encryption failed.');
            }
            fwrite($out, $finalCipher);
            hash_update($hmacCtx, $finalCipher);

            // Append the authentication tag.
            $hmac = hash_final($hmacCtx, true);
            fwrite($out, $hmac);
        } finally {
            fclose($in);
            fclose($out);
        }

        return true;
    }

    /**
     * Decrypt a previously encrypted file.
     *
     * Verifies the HMAC authentication tag BEFORE returning success, so an
     * incorrect key or a corrupted/tampered archive is detected.
     *
     * @param string $sourcePath      Path to the encrypted file.
     * @param string $destinationPath Path to write the decrypted plaintext to.
     *
     * @return bool True on success.
     *
     * @throws RuntimeException On authentication failure or any I/O/cipher error.
     */
    public function decryptFile($sourcePath, $destinationPath)
    {
        if (!is_readable($sourcePath)) {
            throw new RuntimeException('Decryption source file is not readable: ' . $sourcePath);
        }

        $fileSize = filesize($sourcePath);
        $headerLen = strlen(self::MAGIC) + 16; // MAGIC + IV
        $hmacLen   = 32;
        if ($fileSize < $headerLen + $hmacLen) {
            throw new RuntimeException('Encrypted file is too small or truncated.');
        }

        $in = @fopen($sourcePath, 'rb');
        if (!$in) {
            throw new RuntimeException('Unable to open encrypted file.');
        }

        $magic = fread($in, strlen(self::MAGIC));
        if ($magic !== self::MAGIC) {
            fclose($in);
            throw new RuntimeException('Invalid archive header - not a Snapshot Pro encrypted file.');
        }
        $iv = fread($in, 16);

        // First pass: verify HMAC over MAGIC + IV + ciphertext (excluding trailing HMAC).
        $cipherBytesTotal = $fileSize - $headerLen - $hmacLen;
        $hmacCtx = hash_init('sha256', HASH_HMAC, $this->macKey);
        hash_update($hmacCtx, self::MAGIC);
        hash_update($hmacCtx, $iv);

        $remaining = $cipherBytesTotal;
        while ($remaining > 0) {
            $toRead = (int) min(self::CHUNK_SIZE, $remaining);
            $chunk  = fread($in, $toRead);
            if ($chunk === false) {
                fclose($in);
                throw new RuntimeException('Read error while verifying archive.');
            }
            hash_update($hmacCtx, $chunk);
            $remaining -= strlen($chunk);
        }
        $storedHmac = fread($in, $hmacLen);
        $calcHmac   = hash_final($hmacCtx, true);
        if (!hash_equals($calcHmac, $storedHmac)) {
            fclose($in);
            throw new RuntimeException('Integrity/authentication check failed. Wrong encryption key or corrupted archive.');
        }

        // Second pass: decrypt now that authenticity is proven.
        fseek($in, $headerLen);
        $out = @fopen($destinationPath, 'wb');
        if (!$out) {
            fclose($in);
            throw new RuntimeException('Unable to open destination for decryption.');
        }

        $nextIv    = $iv;
        $remaining = $cipherBytesTotal;
        $carry     = '';
        try {
            while ($remaining > 0) {
                $toRead = (int) min(self::CHUNK_SIZE, $remaining);
                $chunk  = fread($in, $toRead);
                if ($chunk === false) {
                    throw new RuntimeException('Read error during decryption.');
                }
                $remaining -= strlen($chunk);
                $carry     .= $chunk;

                // Decrypt everything except the final block, which needs padding removal.
                $isLast = ($remaining <= 0);
                if (!$isLast) {
                    $decryptable = substr($carry, 0, intdiv(strlen($carry), 16) * 16);
                    // Keep at least one block back so the final decrypt handles padding.
                    if (strlen($decryptable) >= 16) {
                        $decryptable = substr($decryptable, 0, strlen($decryptable) - 16);
                    } else {
                        $decryptable = '';
                    }
                    $carry = substr($carry, strlen($decryptable));

                    if ($decryptable !== '') {
                        $plain = openssl_decrypt(
                            $decryptable,
                            self::CIPHER,
                            $this->key,
                            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                            $nextIv
                        );
                        if ($plain === false) {
                            throw new RuntimeException('OpenSSL decryption failed.');
                        }
                        fwrite($out, $plain);
                        $nextIv = substr($decryptable, -16);
                    }
                }
            }

            // Final block(s) with padding.
            if ($carry !== '') {
                $plain = openssl_decrypt(
                    $carry,
                    self::CIPHER,
                    $this->key,
                    OPENSSL_RAW_DATA,
                    $nextIv
                );
                if ($plain === false) {
                    throw new RuntimeException('OpenSSL final decryption failed.');
                }
                fwrite($out, $plain);
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return true;
    }

    /**
     * Generate a cryptographically strong random key suitable for use as the
     * module encryption passphrase (returned as a 64-char hex string).
     *
     * @return string
     */
    public static function generateKey()
    {
        return bin2hex(openssl_random_pseudo_bytes(32));
    }
}
