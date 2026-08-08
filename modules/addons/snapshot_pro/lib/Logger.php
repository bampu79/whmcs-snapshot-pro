<?php
/**
 * WHMCS Snapshot Pro - Audit Logger
 *
 * Persists an audit trail of every backup/restore/settings operation to the
 * mod_snapshot_pro_logs table using the WHMCS Capsule (Illuminate) query
 * builder. No raw SQL strings are used anywhere in this class.
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
 * Class Logger
 *
 * Lightweight audit-log writer/reader for the module. All public methods are
 * defensive: logging must never throw an exception that would break the
 * surrounding backup/restore operation, so write failures are swallowed after
 * being attempted (the caller should not depend on the log write succeeding).
 */
class Logger
{
    /** Log level constants. */
    const LEVEL_INFO    = 'info';
    const LEVEL_SUCCESS = 'success';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR   = 'error';

    /** @var string Database table name for audit logs. */
    const TABLE = 'mod_snapshot_pro_logs';

    /**
     * Write an audit log entry.
     *
     * @param string      $action    Short action key (e.g. "backup.create").
     * @param string      $level     One of the LEVEL_* constants.
     * @param string      $message   Human readable message.
     * @param string|null $adminUser The WHMCS admin username performing the action.
     * @param array       $context   Optional structured context (stored as JSON).
     *
     * @return void
     */
    public static function log($action, $level, $message, $adminUser = null, array $context = [])
    {
        try {
            Capsule::table(self::TABLE)->insert([
                'action'     => substr((string) $action, 0, 100),
                'level'      => in_array($level, [
                    self::LEVEL_INFO,
                    self::LEVEL_SUCCESS,
                    self::LEVEL_WARNING,
                    self::LEVEL_ERROR,
                ], true) ? $level : self::LEVEL_INFO,
                'message'    => (string) $message,
                'admin_user' => $adminUser !== null ? substr((string) $adminUser, 0, 191) : null,
                'context'    => !empty($context) ? json_encode($context) : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Exception $e) {
            // Logging must never break the caller. As a last resort, emit to
            // the PHP error log so the failure is not entirely silent.
            error_log('SnapshotPro Logger failed: ' . $e->getMessage());
        }
    }

    /**
     * Convenience wrapper for informational messages.
     *
     * @param string      $action
     * @param string      $message
     * @param string|null $adminUser
     * @param array       $context
     * @return void
     */
    public static function info($action, $message, $adminUser = null, array $context = [])
    {
        self::log($action, self::LEVEL_INFO, $message, $adminUser, $context);
    }

    /**
     * Convenience wrapper for success messages.
     *
     * @param string      $action
     * @param string      $message
     * @param string|null $adminUser
     * @param array       $context
     * @return void
     */
    public static function success($action, $message, $adminUser = null, array $context = [])
    {
        self::log($action, self::LEVEL_SUCCESS, $message, $adminUser, $context);
    }

    /**
     * Convenience wrapper for warning messages.
     *
     * @param string      $action
     * @param string      $message
     * @param string|null $adminUser
     * @param array       $context
     * @return void
     */
    public static function warning($action, $message, $adminUser = null, array $context = [])
    {
        self::log($action, self::LEVEL_WARNING, $message, $adminUser, $context);
    }

    /**
     * Convenience wrapper for error messages.
     *
     * @param string      $action
     * @param string      $message
     * @param string|null $adminUser
     * @param array       $context
     * @return void
     */
    public static function error($action, $message, $adminUser = null, array $context = [])
    {
        self::log($action, self::LEVEL_ERROR, $message, $adminUser, $context);
    }

    /**
     * Retrieve a paginated list of log entries, most recent first.
     *
     * @param int $limit  Maximum number of rows to return.
     * @param int $offset Number of rows to skip (for pagination).
     *
     * @return array Array of stdClass log rows.
     */
    public static function getLogs($limit = 100, $offset = 0)
    {
        try {
            return Capsule::table(self::TABLE)
                ->orderBy('id', 'desc')
                ->offset(max(0, (int) $offset))
                ->limit(max(1, min(1000, (int) $limit)))
                ->get()
                ->all();
        } catch (Exception $e) {
            error_log('SnapshotPro Logger getLogs failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Count total log entries (for pagination controls).
     *
     * @return int
     */
    public static function countLogs()
    {
        try {
            return (int) Capsule::table(self::TABLE)->count();
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Delete log entries older than the supplied number of days.
     *
     * @param int $days Retention period in days.
     * @return int Number of rows deleted.
     */
    public static function prune($days = 90)
    {
        try {
            $cutoff = date('Y-m-d H:i:s', strtotime('-' . max(1, (int) $days) . ' days'));
            return (int) Capsule::table(self::TABLE)
                ->where('created_at', '<', $cutoff)
                ->delete();
        } catch (Exception $e) {
            error_log('SnapshotPro Logger prune failed: ' . $e->getMessage());
            return 0;
        }
    }
}
