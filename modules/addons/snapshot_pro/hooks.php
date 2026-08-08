<?php
/**
 * WHMCS Snapshot Pro - Hooks
 *
 * Registers the DailyCronJob hook so scheduled snapshots run automatically as
 * part of the WHMCS daily cron. The hook honours the configured schedule
 * (daily/weekly/monthly) and applies the retention policy after each run.
 *
 * WHMCS automatically includes this file for active addon modules.
 *
 * @package    SnapshotPro
 * @author     bampu79
 * @license    MIT
 */

use WHMCS\Database\Capsule;
use SnapshotPro\SnapshotManager;
use SnapshotPro\Settings;
use SnapshotPro\Logger;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/autoload.php';

/**
 * DailyCronJob hook: run a scheduled snapshot when due.
 *
 * WHMCS fires DailyCronJob once per day. We decide whether a backup is due for
 * the configured schedule, and if so, create a snapshot and prune old ones.
 *
 * @return void
 */
add_hook('DailyCronJob', 1, function ($vars) {
    try {
        // The settings table only exists when the module is active.
        if (!Capsule::schema()->hasTable('mod_snapshot_pro_settings')) {
            return;
        }

        $schedule = (string) Settings::get('schedule', 'daily');
        if ($schedule === 'disabled') {
            return;
        }

        if (!snapshot_pro_isBackupDue($schedule)) {
            Logger::info('cron.skip', 'Scheduled backup not due today (schedule: ' . $schedule . ').', 'cron');
            return;
        }

        Logger::info('cron.run', 'Daily cron triggered a scheduled snapshot (' . $schedule . ').', 'cron');

        $manager = new SnapshotManager();
        $manager->create('cron', 'cron');
        // Retention is applied inside create(), but we call it defensively too.
        $manager->applyRetention('cron');

        // Prune very old audit logs to keep the table lean.
        Logger::prune(90);
    } catch (\Throwable $e) {
        // Never let a backup failure break the WHMCS cron chain.
        try {
            Logger::error('cron.run', 'Scheduled snapshot failed: ' . $e->getMessage(), 'cron');
        } catch (\Throwable $inner) {
            error_log('SnapshotPro cron hook fatal: ' . $e->getMessage());
        }
    }
});

/**
 * Decide whether a scheduled backup is due for the given schedule.
 *
 * Uses the last completed snapshot timestamp to avoid running more than once
 * per period even if the cron runs multiple times.
 *
 * @param string $schedule daily|weekly|monthly
 *
 * @return bool
 */
function snapshot_pro_isBackupDue($schedule)
{
    $last = (string) Settings::get('last_backup_at', '');
    $now  = time();

    if ($last === '') {
        return true; // Never backed up before.
    }
    $lastTs = strtotime($last);
    if ($lastTs === false) {
        return true;
    }

    switch ($schedule) {
        case 'weekly':
            return ($now - $lastTs) >= (7 * 86400 - 3600);
        case 'monthly':
            // Due if we're in a later calendar month than the last backup.
            return date('Y-m', $lastTs) !== date('Y-m', $now);
        case 'daily':
        default:
            // Due if the last backup was on an earlier calendar day.
            return date('Y-m-d', $lastTs) !== date('Y-m-d', $now);
    }
}
