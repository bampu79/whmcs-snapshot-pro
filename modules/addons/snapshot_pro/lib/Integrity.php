<?php
/**
 * WHMCS Snapshot Pro - Integrity
 *
 * Generates and verifies SHA-256 checksums for snapshot archives so that data
 * corruption or tampering can be detected before a restore is attempted.
 *
 * @package    SnapshotPro
 * @author     bampu79
 * @license    MIT
 */

namespace SnapshotPro;

use RuntimeException;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Class Integrity
 *
 * Thin wrapper around PHP's streaming hash API to compute SHA-256 digests of
 * potentially very large files without exhausting memory.
 */
class Integrity
{
    /** @var string Hash algorithm used for all integrity checks. */
    const ALGO = 'sha256';

    /**
     * Compute the SHA-256 checksum of a file.
     *
     * @param string $path Absolute path to the file.
     *
     * @return string Lowercase hex digest.
     *
     * @throws RuntimeException If the file cannot be read.
     */
    public static function checksum($path)
    {
        if (!is_readable($path)) {
            throw new RuntimeException('Cannot compute checksum, file not readable: ' . $path);
        }
        $hash = hash_file(self::ALGO, $path);
        if ($hash === false) {
            throw new RuntimeException('Failed to compute checksum for: ' . $path);
        }
        return $hash;
    }

    /**
     * Verify that a file matches an expected SHA-256 checksum.
     *
     * Uses hash_equals() for a timing-safe comparison.
     *
     * @param string $path             Absolute path to the file.
     * @param string $expectedChecksum Expected lowercase hex digest.
     *
     * @return bool True when the checksum matches.
     */
    public static function verify($path, $expectedChecksum)
    {
        if (!is_readable($path) || $expectedChecksum === null || $expectedChecksum === '') {
            return false;
        }
        $actual = hash_file(self::ALGO, $path);
        if ($actual === false) {
            return false;
        }
        return hash_equals(strtolower($expectedChecksum), strtolower($actual));
    }

    /**
     * Produce a short, human-friendly representation of a checksum for display
     * (first 8 and last 8 characters).
     *
     * @param string $checksum Full hex digest.
     * @return string
     */
    public static function shortForm($checksum)
    {
        if (strlen((string) $checksum) <= 20) {
            return (string) $checksum;
        }
        return substr($checksum, 0, 8) . '…' . substr($checksum, -8);
    }
}
