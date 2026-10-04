<?php
/**
 * WHMCS Snapshot Pro - Dedicated CLI Worker
 *
 * Processes at most one queued snapshot job per invocation by calling
 * JobQueue::processNext(), which acquires an exclusive flock, recovers any
 * abandoned "running" jobs, claims a queued job, and runs
 * SnapshotManager::create() in this CLI process.
 *
 * Intended to be scheduled independently of the WHMCS automation cron, e.g.:
 *
 *   every 5 minutes: php -q /path/to/whmcs/modules/addons/snapshot_pro/cron.php
 *
 * Do not invoke this script over HTTP.
 *
 * Exit codes:
 *   0 — idle, busy (another worker holds the lock), or job completed
 *   1 — job failed, worker error, or invalid invocation
 *
 * @package    SnapshotPro
 * @author     bampu79
 * @license    MIT
 */

// ---------------------------------------------------------------------------
// CLI-only guard (reject browser / web-server execution).
// ---------------------------------------------------------------------------
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'This script may only be run from the command line.';
    exit(1);
}

@set_time_limit(0);
@ignore_user_abort(true);

// modules/addons/snapshot_pro -> WHMCS root is three levels up.
$whmcsRoot = dirname(__DIR__, 3);
$bootstrapFile = $whmcsRoot . '/crons/bootstrap.php';

if (!is_readable($bootstrapFile)) {
    fwrite(STDERR, "Snapshot Pro worker: unable to locate WHMCS crons/bootstrap.php at expected path.\n");
    exit(1);
}

chdir($whmcsRoot);
require_once $bootstrapFile;
require_once __DIR__ . '/autoload.php';

use SnapshotPro\JobQueue;
use SnapshotPro\Logger;

try {
    JobQueue::ensureSchema();
    $result = JobQueue::processNext();

    // Another worker already holds the exclusive lock — not an error.
    if ($result === 'busy' || $result === 'idle' || $result === 'completed') {
        exit(0);
    }

    // 'failed' or 'error' — details are in the module audit log / job row.
    fwrite(STDERR, "Snapshot Pro worker: job did not complete successfully (see module logs).\n");
    exit(1);
} catch (Throwable $e) {
    try {
        Logger::error(
            'job.worker',
            'CLI worker fatal: ' . JobQueue::sanitizeErrorMessage($e->getMessage()),
            'cron'
        );
    } catch (Throwable $inner) {
        // Avoid leaking exception details that might include paths/credentials.
        error_log('SnapshotPro CLI worker fatal');
    }
    fwrite(STDERR, "Snapshot Pro worker: unexpected failure (see module logs).\n");
    exit(1);
}
