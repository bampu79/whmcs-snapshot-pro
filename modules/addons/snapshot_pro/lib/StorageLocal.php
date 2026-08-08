<?php
/**
 * WHMCS Snapshot Pro - Local Storage Backend
 *
 * Stores snapshot archives on the local server filesystem in a configurable
 * directory. This is the default backend and always available.
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
 * Class StorageLocal
 *
 * Implements StorageInterface for a directory on the local filesystem. The
 * "reference" for this backend is simply the stored file's absolute path.
 */
class StorageLocal implements StorageInterface
{
    /** @var string Absolute base directory where archives are kept. */
    private $basePath;

    /**
     * StorageLocal constructor.
     *
     * @param string $basePath Absolute directory for storing archives.
     *
     * @throws RuntimeException If the directory cannot be created or written.
     */
    public function __construct($basePath)
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        if (!is_dir($this->basePath)) {
            if (!@mkdir($this->basePath, 0750, true) && !is_dir($this->basePath)) {
                throw new RuntimeException('Unable to create local storage directory: ' . $this->basePath);
            }
        }
        if (!is_writable($this->basePath)) {
            throw new RuntimeException('Local storage directory is not writable: ' . $this->basePath);
        }
        // Harden the directory against direct web access.
        $this->ensureProtection();
    }

    /**
     * Store a file into the local storage directory.
     *
     * @param string $localPath  Source file path.
     * @param string $remoteName Destination filename (basename only).
     *
     * @return string Absolute path of the stored file (the reference).
     *
     * @throws RuntimeException On copy failure.
     */
    public function put($localPath, $remoteName)
    {
        if (!is_readable($localPath)) {
            throw new RuntimeException('Source file not readable: ' . $localPath);
        }
        $destination = $this->basePath . DIRECTORY_SEPARATOR . basename($remoteName);
        // If the source is already at the destination, nothing to copy.
        if (realpath($localPath) !== realpath($destination)) {
            if (!@copy($localPath, $destination)) {
                throw new RuntimeException('Failed to copy archive into local storage.');
            }
        }
        @chmod($destination, 0640);
        return $destination;
    }

    /**
     * Retrieve a stored file to a target path.
     *
     * @param string $reference Absolute path of the stored file.
     * @param string $localPath Destination path.
     *
     * @return bool
     *
     * @throws RuntimeException On failure.
     */
    public function get($reference, $localPath)
    {
        if (!is_readable($reference)) {
            throw new RuntimeException('Stored archive not readable: ' . $reference);
        }
        if (realpath($reference) === realpath($localPath)) {
            return true; // Already in place.
        }
        if (!@copy($reference, $localPath)) {
            throw new RuntimeException('Failed to retrieve archive from local storage.');
        }
        return true;
    }

    /**
     * Delete a stored file.
     *
     * @param string $reference Absolute path of the stored file.
     *
     * @return bool
     */
    public function delete($reference)
    {
        if (file_exists($reference)) {
            return @unlink($reference);
        }
        return true;
    }

    /**
     * Whether the stored file still exists.
     *
     * @param string $reference Absolute path.
     *
     * @return bool
     */
    public function exists($reference)
    {
        return is_file($reference);
    }

    /**
     * {@inheritDoc}
     */
    public function getType()
    {
        return 'local';
    }

    /**
     * Return the configured base storage path.
     *
     * @return string
     */
    public function getBasePath()
    {
        return $this->basePath;
    }

    /**
     * Compute the total bytes used by all files in the storage directory.
     *
     * @return int
     */
    public function usage()
    {
        $total = 0;
        foreach (glob($this->basePath . DIRECTORY_SEPARATOR . '*') as $file) {
            if (is_file($file)) {
                $total += filesize($file);
            }
        }
        return $total;
    }

    /**
     * Drop protective .htaccess / index.html files into the directory so it
     * cannot be browsed even if it sits inside the web root.
     *
     * @return void
     */
    private function ensureProtection()
    {
        $htaccess = $this->basePath . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents(
                $htaccess,
                "Order deny,allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
            );
        }
        $index = $this->basePath . '/index.html';
        if (!file_exists($index)) {
            @file_put_contents($index, '');
        }
    }
}
