<?php
/**
 * WHMCS Snapshot Pro - AJAX Handler
 *
 * Single JSON endpoint powering the asynchronous parts of the module:
 *   - Manual backup creation (enqueue only; processed by the CLI worker)
 *   - Progress polling for queued/running jobs
 *   - The multi-step restore wizard (verify / safety / confirm / execute)
 *
 * The handler bootstraps the WHMCS environment (so Capsule, sessions and admin
 * authentication are available), enforces Full Administrator access and CSRF
 * validation, then dispatches on the `op` parameter and returns a JSON payload.
 *
 * Manual snapshot creation persists a queued job and returns immediately.
 * Progress is written to a per-job file (and the jobs table) so the browser can
 * poll `op=progress` while modules/addons/snapshot_pro/cron.php runs SnapshotManager.
 *
 * @package    SnapshotPro
 * @author     bampu79
 * @license    MIT
 */

use WHMCS\Database\Capsule;
use SnapshotPro\SnapshotManager;
use SnapshotPro\JobQueue;
use SnapshotPro\RestoreWizard;
use SnapshotPro\RestoreTarget;
use SnapshotPro\Settings;
use SnapshotPro\Logger;

// ---------------------------------------------------------------------------
// Bootstrap WHMCS. The handler lives at modules/addons/snapshot_pro/ajax/ so
// the WHMCS init.php is five levels up.
// ---------------------------------------------------------------------------
$whmcsInit = dirname(__DIR__, 4) . '/init.php';
if (!is_file($whmcsInit)) {
    // Fallback: some installs keep init.php in the root differently.
    $whmcsInit = dirname(__DIR__, 4) . '/admin/../init.php';
}
require_once $whmcsInit;
require_once __DIR__ . '/../autoload.php';

// Always return JSON.
header('Content-Type: application/json; charset=utf-8');

/**
 * Emit a JSON response and terminate.
 *
 * @param array $data
 * @param int   $httpCode
 * @return void
 */
function sp_json($data, $httpCode = 200)
{
    http_response_code($httpCode);
    echo json_encode($data);
    exit;
}

/**
 * Path to a job's progress file (delegates to JobQueue).
 *
 * @param string $jobId
 * @return string
 */
function sp_progressFile($jobId)
{
    return JobQueue::progressFile($jobId);
}

/**
 * Write a progress record for a job (delegates to JobQueue).
 *
 * @param string $jobId
 * @param int    $percent
 * @param string $message
 * @param string $state   running|done|error
 * @param array  $extra
 * @return void
 */
function sp_writeProgress($jobId, $percent, $message, $state = 'running', array $extra = [])
{
    JobQueue::writeProgress($jobId, $percent, $message, $state, $extra);
}

/**
 * Build a validated restore target from the current request.
 *
 * @return RestoreTarget
 */
function sp_restoreTargetFromRequest()
{
    $manager = new SnapshotManager();
    return RestoreTarget::fromRequest($_REQUEST, $manager->getWhmcsRoot());
}

// ---------------------------------------------------------------------------
// Authentication & authorization.
// ---------------------------------------------------------------------------
$adminId = isset($_SESSION['adminid']) ? (int) $_SESSION['adminid'] : 0;
if (!$adminId) {
    sp_json(['ok' => false, 'error' => 'Not authenticated. Please log in to the WHMCS admin area.'], 401);
}

// Verify Full Administrator role.
try {
    $admin = Capsule::table('tbladmins')->where('id', $adminId)->first();
    $role  = $admin ? Capsule::table('tbladminroles')->where('id', $admin->roleid)->first() : null;
    $isFullAdmin = $role && stripos($role->name, 'Full Admin') !== false;
} catch (\Exception $e) {
    $isFullAdmin = false;
}
if (!$isFullAdmin) {
    sp_json(['ok' => false, 'error' => 'Access denied. Full Administrator role required.'], 403);
}
$adminUser = $admin ? $admin->username : 'unknown';

// ---------------------------------------------------------------------------
// CSRF validation (accept token via header or POST field).
// ---------------------------------------------------------------------------
$op    = isset($_REQUEST['op']) ? preg_replace('/[^a-z_]/', '', $_REQUEST['op']) : '';
$token = isset($_SERVER['HTTP_X_CSRF_TOKEN'])
    ? $_SERVER['HTTP_X_CSRF_TOKEN']
    : (isset($_REQUEST['csrf_token']) ? $_REQUEST['csrf_token'] : '');

// The `progress` poll is read-only and safe to allow without CSRF; everything
// else must present a valid token.
if ($op !== 'progress') {
    if (empty($_SESSION['snapshot_pro_csrf']) || !hash_equals($_SESSION['snapshot_pro_csrf'], (string) $token)) {
        sp_json(['ok' => false, 'error' => 'Invalid security token.'], 419);
    }
}

// ---------------------------------------------------------------------------
// Dispatch.
// ---------------------------------------------------------------------------
try {
    switch ($op) {
        // -------------------------------------------------------------------
        // Poll progress for a queued/running job (read-only; never runs backup).
        // -------------------------------------------------------------------
        case 'progress':
            $jobId = isset($_REQUEST['job']) ? $_REQUEST['job'] : '';
            $fromDb = JobQueue::progressFromDb($jobId);
            if ($fromDb) {
                sp_json(array_merge(['ok' => true], $fromDb));
            }
            $file = sp_progressFile($jobId);
            if (is_file($file)) {
                $data = json_decode(file_get_contents($file), true);
                sp_json(array_merge(['ok' => true], is_array($data) ? $data : []));
            }
            sp_json(['ok' => true, 'percent' => 0, 'message' => 'Waiting…', 'state' => 'running']);
            break;

        // -------------------------------------------------------------------
        // Queue a manual backup. Returns immediately; SnapshotManager::create()
        // runs only in the dedicated CLI worker (cron.php) — never in this request.
        // -------------------------------------------------------------------
        case 'create_backup':
            $jobId = JobQueue::enqueue('manual', $adminUser);
            sp_json(['ok' => true, 'job' => $jobId, 'async' => true]);
            break;

        // -------------------------------------------------------------------
        // Restore wizard: integrity verification.
        // -------------------------------------------------------------------
        case 'restore_verify':
            $snapshotId = preg_replace('/[^a-zA-Z0-9_]/', '', $_REQUEST['snapshot'] ?? '');
            $wizard     = new RestoreWizard($adminUser);
            $result     = $wizard->verify($snapshotId);
            sp_json(['ok' => true, 'result' => $result]);
            break;

        // -------------------------------------------------------------------
        // Restore wizard: pre-restore safety backup (queued for CLI worker).
        // -------------------------------------------------------------------
        case 'restore_safety':
            $target = sp_restoreTargetFromRequest();
            $safety = RestoreWizard::safetyBackupFromRequest($_REQUEST, $target);
            if (!$safety['create_safety_backup']) {
                sp_json(['ok' => false, 'error' => 'Safety backup was not requested for this restore.'], 400);
            }
            $jobId = JobQueue::enqueue('pre-restore', $adminUser);
            sp_json(['ok' => true, 'job' => $jobId, 'async' => true]);
            break;

        // -------------------------------------------------------------------
        // Restore wizard: confirmation summary.
        // -------------------------------------------------------------------
        case 'restore_confirm':
            $snapshotId = preg_replace('/[^a-zA-Z0-9_]/', '', $_REQUEST['snapshot'] ?? '');
            $scope      = preg_replace('/[^a-z]/', '', $_REQUEST['scope'] ?? 'full');
            $target     = sp_restoreTargetFromRequest();
            $safety     = RestoreWizard::safetyBackupFromRequest($_REQUEST, $target);
            $wizard     = new RestoreWizard($adminUser);
            sp_json(['ok' => true, 'summary' => $wizard->confirmationSummary($snapshotId, $scope, $target, $safety)]);
            break;

        // -------------------------------------------------------------------
        // Restore wizard: execute the restore with live progress.
        // -------------------------------------------------------------------
        case 'restore_execute':
            $snapshotId = preg_replace('/[^a-zA-Z0-9_]/', '', $_REQUEST['snapshot'] ?? '');
            $scope      = preg_replace('/[^a-z]/', '', $_REQUEST['scope'] ?? 'full');
            $target     = sp_restoreTargetFromRequest();
            $safety     = RestoreWizard::safetyBackupFromRequest($_REQUEST, $target);
            RestoreWizard::assertSafetyBackupPolicy($target, $safety);
            $jobId      = 'job_' . bin2hex(random_bytes(6));
            sp_writeProgress($jobId, 1, 'Starting restore…');
            @set_time_limit(0);
            if (function_exists('fastcgi_finish_request')) {
                echo json_encode(['ok' => true, 'job' => $jobId, 'async' => true]);
                @session_write_close();
                fastcgi_finish_request();
            }
            try {
                $encKey = (string) Settings::get('encryption_key', '');
                $wizard = new RestoreWizard($adminUser);
                $result = $wizard->execute($snapshotId, $scope, $encKey, $target, function ($pct, $msg) use ($jobId) {
                    sp_writeProgress($jobId, $pct, $msg);
                });
                sp_writeProgress(
                    $jobId,
                    100,
                    $result['message'],
                    $result['ok'] ? 'done' : 'error',
                    ['log' => $result['log'], 'verification' => $result['verification'] ?? []]
                );
            } catch (\Exception $e) {
                sp_writeProgress($jobId, 100, $e->getMessage(), 'error');
            }
            if (!function_exists('fastcgi_finish_request')) {
                sp_json(['ok' => true, 'job' => $jobId, 'async' => false]);
            }
            exit;

        // -------------------------------------------------------------------
        // Delete a snapshot.
        // -------------------------------------------------------------------
        case 'delete_snapshot':
            $snapshotId = preg_replace('/[^a-zA-Z0-9_]/', '', $_REQUEST['snapshot'] ?? '');
            (new SnapshotManager())->delete($snapshotId, $adminUser);
            sp_json(['ok' => true, 'message' => 'Snapshot deleted.']);
            break;

        // -------------------------------------------------------------------
        // Test the Google Drive connection with current settings.
        // -------------------------------------------------------------------
        case 'test_gdrive':
            $json     = $_REQUEST['gdrive_service_account'] ?? Settings::get('gdrive_service_account', '');
            $folderId = $_REQUEST['gdrive_folder_id'] ?? Settings::get('gdrive_folder_id', '');
            $drive    = new \SnapshotPro\StorageGoogleDrive($json, $folderId);
            $drive->testConnection();
            Logger::success('settings.test_gdrive', 'Google Drive connection test succeeded.', $adminUser);
            sp_json(['ok' => true, 'message' => 'Google Drive authentication succeeded.']);
            break;

        default:
            sp_json(['ok' => false, 'error' => 'Unknown operation.'], 400);
    }
} catch (\Throwable $e) {
    Logger::error('ajax', 'AJAX error (' . $op . '): ' . $e->getMessage(), $adminUser ?? null);
    sp_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
