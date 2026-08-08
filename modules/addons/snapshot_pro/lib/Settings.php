<?php
/**
 * WHMCS Snapshot Pro - Settings Store
 *
 * A thin key/value settings store backed by the mod_snapshot_pro_settings
 * table. Sensitive values (encryption key, Google credentials) are kept here
 * rather than in the WHMCS addon "configuration" screen so they can hold large
 * JSON blobs and be updated through the module's own Settings page.
 *
 * @package    SnapshotPro
 * @author     bampu79
 * @license    MIT
 */

namespace SnapshotPro;

use WHMCS\Database\Capsule;
use Exception;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Class Settings
 */
class Settings
{
    /** @var string Settings table name. */
    const TABLE = 'mod_snapshot_pro_settings';

    /** @var array|null In-request cache of all settings. */
    private static $cache = null;

    /** @var array Default values applied when a key is missing. */
    private static $defaults = [
        'storage_backend'      => 'local',
        'local_storage_path'   => '',
        'gdrive_service_account' => '',
        'gdrive_folder_id'     => '',
        'schedule'             => 'daily',      // daily|weekly|monthly|disabled
        'retention'            => '7',          // keep last N snapshots
        'encryption_key'       => '',
        'backup_files'         => '1',
        'backup_database'      => '1',
        'last_backup_at'       => '',
        'next_backup_due'      => '',
    ];

    /**
     * Retrieve a single setting value.
     *
     * @param string $key     Setting key.
     * @param mixed  $default Value if not set (falls back to internal defaults).
     *
     * @return mixed
     */
    public static function get($key, $default = null)
    {
        $all = self::all();
        if (array_key_exists($key, $all)) {
            return $all[$key];
        }
        if ($default !== null) {
            return $default;
        }
        return isset(self::$defaults[$key]) ? self::$defaults[$key] : null;
    }

    /**
     * Persist a single setting value (upsert).
     *
     * @param string $key   Setting key.
     * @param mixed  $value Value to store (scalar or JSON-encodable).
     *
     * @return void
     */
    public static function set($key, $value)
    {
        $stored = is_scalar($value) ? (string) $value : json_encode($value);
        $exists = Capsule::table(self::TABLE)->where('setting_key', $key)->exists();
        if ($exists) {
            Capsule::table(self::TABLE)
                ->where('setting_key', $key)
                ->update(['setting_value' => $stored, 'updated_at' => date('Y-m-d H:i:s')]);
        } else {
            Capsule::table(self::TABLE)->insert([
                'setting_key'   => $key,
                'setting_value' => $stored,
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
        }
        self::$cache = null; // Invalidate cache.
    }

    /**
     * Persist several settings at once.
     *
     * @param array $values Map of key => value.
     * @return void
     */
    public static function setMany(array $values)
    {
        foreach ($values as $key => $value) {
            self::set($key, $value);
        }
    }

    /**
     * Fetch all settings merged over the defaults.
     *
     * @return array
     */
    public static function all()
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $values = self::$defaults;
        try {
            $rows = Capsule::table(self::TABLE)->get();
            foreach ($rows as $row) {
                $values[$row->setting_key] = $row->setting_value;
            }
        } catch (Exception $e) {
            // Table may not exist yet (pre-activation) - return defaults.
            Logger::error('settings.load', 'Failed to load settings: ' . $e->getMessage());
        }
        self::$cache = $values;
        return $values;
    }

    /**
     * Return the internal default settings map.
     *
     * @return array
     */
    public static function defaults()
    {
        return self::$defaults;
    }
}
