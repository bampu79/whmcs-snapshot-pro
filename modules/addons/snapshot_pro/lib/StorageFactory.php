<?php
/**
 * WHMCS Snapshot Pro - Storage Factory
 *
 * Builds the appropriate StorageInterface implementation from the module's
 * stored configuration. Centralising construction keeps the rest of the module
 * decoupled from backend-specific wiring.
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
 * Class StorageFactory
 */
class StorageFactory
{
    /**
     * Create a storage backend instance for the given type.
     *
     * @param string $type     Backend type: "local" or "googledrive".
     * @param array  $settings The module settings array (see Settings::all()).
     *
     * @return StorageInterface
     *
     * @throws RuntimeException On unknown type or invalid configuration.
     */
    public static function make($type, array $settings)
    {
        switch ($type) {
            case 'local':
                $path = !empty($settings['local_storage_path'])
                    ? $settings['local_storage_path']
                    : self::defaultLocalPath();
                return new StorageLocal($path);

            case 'googledrive':
                if (empty($settings['gdrive_service_account'])) {
                    throw new RuntimeException('Google Drive is selected but no service account JSON is configured.');
                }
                $folderId = !empty($settings['gdrive_folder_id']) ? $settings['gdrive_folder_id'] : null;
                return new StorageGoogleDrive($settings['gdrive_service_account'], $folderId);

            default:
                throw new RuntimeException('Unknown storage backend type: ' . $type);
        }
    }

    /**
     * The default on-server storage path used when none is configured.
     *
     * @return string
     */
    public static function defaultLocalPath()
    {
        return __DIR__ . '/../storage';
    }

    /**
     * List the storage backend types available for selection in the UI.
     *
     * @return array<string,string> Map of type => human label.
     */
    public static function availableTypes()
    {
        return [
            'local'       => 'Local Server Storage',
            'googledrive' => 'Google Drive',
        ];
    }
}
