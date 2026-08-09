<?php
/**
 * WHMCS Snapshot Pro - Hooks
 *
 * Registers DailyCronJob to enqueue a scheduled snapshot when due. Long-running
 * SnapshotManager::create() work is never executed inside WHMCS automation cron;
 * the dedicated CLI worker (cron.php) claims and processes queued jobs.
 *
 * WHMCS automatically includes this file for active addon modules.
 *
 * @package    SnapshotPro
 * @author     bampu79
 * @license    MIT
 */

use WHMCS\Database\Capsule;
use SnapshotPro\JobQueue;
use SnapshotPro\Settings;
use SnapshotPro\Logger;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/autoload.php';

/**
 * DailyCronJob hook: enqueue a scheduled snapshot when due.
 *
 * WHMCS fires DailyCronJob once per day. When a backup is due for the
 * configured schedule, a job is queued for the dedicated Snapshot Pro CLI
 * worker. This hook must remain fast and must never call SnapshotManager.
 *
 * Duplicate scheduled jobs are prevented by JobQueue::enqueueScheduled()
 * (unique schedule_slot), not only by a SELECT-then-INSERT check.
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

        JobQueue::ensureSchema();

        // Fast path: skip INSERT when an active cron job is already visible.
        if (JobQueue::hasActiveJob('cron')) {
            Logger::info(
                'cron.skip',
                'Scheduled backup due but a cron job is already queued or running.',
                'cron'
            );
            return;
        }

        // Atomic uniqueness via schedule_slot; returns null on duplicate-key race.
        $jobId = JobQueue::enqueueScheduled();
        if ($jobId === null) {
            return;
        }

        Logger::info(
            'cron.enqueue',
            'Scheduled snapshot queued for CLI worker (schedule: ' . $schedule . ', job: ' . $jobId . ').',
            'cron'
        );

        // Lightweight housekeeping only — never run SnapshotManager here.
        Logger::prune(90);
    } catch (\Throwable $e) {
        // Never let a backup failure break the WHMCS cron chain.
        try {
            Logger::error(
                'cron.enqueue',
                'Failed to enqueue scheduled snapshot: ' . JobQueue::sanitizeErrorMessage($e->getMessage()),
                'cron'
            );
        } catch (\Throwable $inner) {
            error_log('SnapshotPro cron hook fatal');
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
