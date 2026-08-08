<?php
/**
 * WHMCS Snapshot Pro - Storage Interface
 *
 * Common contract implemented by every storage backend (local, Google Drive,
 * future S3/Dropbox). Keeping a stable interface lets the SnapshotManager and
 * RestoreWizard work with any backend interchangeably.
 *
 * @package    SnapshotPro
 * @author     bampu79
 * @license    MIT
 */

namespace SnapshotPro;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Interface StorageInterface
 */
interface StorageInterface
{
    /**
     * Store a local file into the backend.
     *
     * @param string $localPath  Absolute path to the source file on disk.
     * @param string $remoteName Desired name/key for the object in the backend.
     *
     * @return string A backend-specific reference/locator for the stored object.
     */
    public function put($localPath, $remoteName);

    /**
     * Retrieve an object from the backend to a local path.
     *
     * @param string $reference Backend reference returned by put().
     * @param string $localPath Absolute path to write the downloaded file to.
     *
     * @return bool True on success.
     */
    public function get($reference, $localPath);

    /**
     * Delete an object from the backend.
     *
     * @param string $reference Backend reference returned by put().
     *
     * @return bool True on success.
     */
    public function delete($reference);

    /**
     * Determine whether an object still exists in the backend.
     *
     * @param string $reference Backend reference.
     *
     * @return bool
     */
    public function exists($reference);

    /**
     * Human-readable backend identifier (e.g. "local", "googledrive").
     *
     * @return string
     */
    public function getType();
}
