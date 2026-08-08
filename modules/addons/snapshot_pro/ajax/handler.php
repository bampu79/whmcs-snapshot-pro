<?php
/**
 * WHMCS Snapshot Pro - AJAX Handler
 *
 * Single JSON endpoint powering the asynchronous parts of the module:
 *   - Manual backup creation with progress polling
 *   - The multi-step restore wizard (verify / safety / confirm / execute)
 *
 * The handler bootstraps the WHMCS environment (so Capsule, sessions and admin
 * authentication are available), enforces Full Administrator access and CSRF
 * validation, then dispatches on the `op` parameter and returns a JSON payload.
 *
 * Long-running operations write progress into a per-job file so the browser can
 * poll `op=progress` while the work continues.
 *
 * @package    SnapshotPro
 * @author     bampu79
 * @license    MIT
 */

use WHMCS\Database\Capsule;
use SnapshotPro\SnapshotManager;
use SnapshotPro\RestoreWizard;
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
 * Path to a job's progress file within the working directory.
 *
 * @param string $jobId
 * @return string
 */
function sp_progressFile($jobId)
{
    $jobId = preg_replace('/[^a-zA-Z0-9_]/', '', $jobId);
    return sys_get_temp_dir() . '/snapshot_pro_work/progress_' . $jobId . '.json';
}

/**
 * Write a progress record for a job.
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
    $dir = dirname(sp_progressFile($jobId));
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    @file_put_contents(sp_progressFile($jobId), json_encode(array_merge([
        'percent' => (int) $percent,
        'message' => (string) $message,
        'state'   => $state,
        'ts'      => time(),
    ], $extra)));
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
        // Poll progress for a running job.
        // -------------------------------------------------------------------
        case 'progress':
            $jobId = isset($_REQUEST['job']) ? $_REQUEST['job'] : '';
            $file  = sp_progressFile($jobId);
            if (!is_file($file)) {
                sp_json(['ok' => true, 'percent' => 0, 'message' => 'Waiting…', 'state' => 'running']);
            }
            $data = json_decode(file_get_contents($file), true);
            sp_json(array_merge(['ok' => true], is_array($data) ? $data : []));
            break;

        // -------------------------------------------------------------------
        // Create a manual backup. Runs synchronously but streams progress to a
        // job file (flushed as it goes) so the client polls op=progress.
        // -------------------------------------------------------------------
        case 'create_backup':
            $jobId = 'job_' . bin2hex(random_bytes(6));
            // Send the job id immediately, then continue processing.
            sp_writeProgress($jobId, 1, 'Initializing…');

            // Detach output so the browser has the job id and can start polling.
            ignore_user_abort(true);
            @set_time_limit(0);

            // Return the job id first; the actual work continues after flush.
            if (function_exists('fastcgi_finish_request')) {
                echo json_encode(['ok' => true, 'job' => $jobId, 'async' => true]);
                @session_write_close();
                fastcgi_finish_request();
            } else {
                // Without FastCGI we cannot truly background; do the work then
                // return the final state. The client will still poll and get 'done'.
                register_shutdown_function(function () use ($jobId) {
                    // no-op; ensures job file persists.
                });
            }

            try {
                $manager = new SnapshotManager();
                $manager->create('manual', $adminUser, function ($pct, $msg) use ($jobId) {
                    sp_writeProgress($jobId, $pct, $msg);
                });
                sp_writeProgress($jobId, 100, 'Backup completed successfully.', 'done');
            } catch (\Exception $e) {
                sp_writeProgress($jobId, 100, $e->getMessage(), 'error');
            }

            // If we already flushed via FastCGI, we're done. Otherwise return now.
            if (!function_exists('fastcgi_finish_request')) {
                sp_json(['ok' => true, 'job' => $jobId, 'async' => false]);
            }
            exit;

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
        // Restore wizard: pre-restore safety backup.
        // -------------------------------------------------------------------
        case 'restore_safety':
            $jobId = 'job_' . bin2hex(random_bytes(6));
            sp_writeProgress($jobId, 1, 'Starting safety backup…');
            @set_time_limit(0);
            if (function_exists('fastcgi_finish_request')) {
                echo json_encode(['ok' => true, 'job' => $jobId, 'async' => true]);
                @session_write_close();
                fastcgi_finish_request();
            }
            try {
                $manager = new SnapshotManager();
                $manager->create('pre-restore', $adminUser, function ($pct, $msg) use ($jobId) {
                    sp_writeProgress($jobId, $pct, $msg);
                });
                sp_writeProgress($jobId, 100, 'Safety backup completed.', 'done');
            } catch (\Exception $e) {
                sp_writeProgress($jobId, 100, $e->getMessage(), 'error');
            }
            if (!function_exists('fastcgi_finish_request')) {
                sp_json(['ok' => true, 'job' => $jobId, 'async' => false]);
            }
            exit;

        // -------------------------------------------------------------------
        // Restore wizard: confirmation summary.
        // -------------------------------------------------------------------
        case 'restore_confirm':
            $snapshotId = preg_replace('/[^a-zA-Z0-9_]/', '', $_REQUEST['snapshot'] ?? '');
            $scope      = preg_replace('/[^a-z]/', '', $_REQUEST['scope'] ?? 'full');
            $wizard     = new RestoreWizard($adminUser);
            sp_json(['ok' => true, 'summary' => $wizard->confirmationSummary($snapshotId, $scope)]);
            break;

        // -------------------------------------------------------------------
        // Restore wizard: execute the restore with live progress.
        // -------------------------------------------------------------------
        case 'restore_execute':
            $snapshotId = preg_replace('/[^a-zA-Z0-9_]/', '', $_REQUEST['snapshot'] ?? '');
            $scope      = preg_replace('/[^a-z]/', '', $_REQUEST['scope'] ?? 'full');
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
                $result = $wizard->execute($snapshotId, $scope, $encKey, function ($pct, $msg) use ($jobId) {
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
