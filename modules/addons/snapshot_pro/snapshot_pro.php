<?php
/**
 * WHMCS Snapshot Pro - Addon Module Entry Point
 *
 * A disaster-recovery addon for WHMCS 9.x that creates complete, encrypted,
 * point-in-time snapshots (database + files), stores them locally or on Google
 * Drive, and restores them through a guided wizard.
 *
 * This file implements the WHMCS addon module contract:
 *   - snapshot_pro_config()      Module metadata & configuration fields
 *   - snapshot_pro_activate()    Creates the module's database tables
 *   - snapshot_pro_deactivate()  Drops the module's database tables
 *   - snapshot_pro_output()      Renders the admin area UI
 *
 * @see https://developers.whmcs.com/addon-modules/
 *
 * @package    SnapshotPro
 * @author     bampu79
 * @license    MIT
 */

use WHMCS\Database\Capsule;
use SnapshotPro\SnapshotManager;
use SnapshotPro\JobQueue;
use SnapshotPro\RestoreWizard;
use SnapshotPro\Settings;
use SnapshotPro\Logger;
use SnapshotPro\Encryption;
use SnapshotPro\StorageFactory;
use SnapshotPro\Integrity;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

// ---------------------------------------------------------------------------
// Autoloader for the module's namespaced lib/ classes (PSR-4 style).
// ---------------------------------------------------------------------------
require_once __DIR__ . '/autoload.php';

/**
 * Define the module configuration and metadata.
 *
 * WHMCS calls this to render the "Configure" screen and to display the module
 * in Setup > Addon Modules. The fields declared here are stored by WHMCS and
 * exposed to the other module functions via $vars['...'].
 *
 * @return array Module configuration array.
 */
function snapshot_pro_config()
{
    return [
        'name'        => 'WHMCS Snapshot Pro',
        'description' => 'Professional disaster-recovery: complete encrypted point-in-time snapshots '
            . '(database + files), local or Google Drive storage, scheduled backups, retention, and a '
            . 'guided restore wizard with integrity verification.',
        'author'  => 'bampu79',
        'language' => 'english',
        'version' => '1.1.0',
        'fields'  => [
            // A minimal WHMCS-level toggle. The bulk of configuration lives on
            // the module's own Settings page so it can hold large JSON blobs.
            'access_note' => [
                'FriendlyName' => 'Access',
                'Type'         => 'text',
                'Size'         => '25',
                'Default'      => 'Full Administrators only',
                'Description'  => 'This module restricts all actions to Full Administrator role. '
                    . 'Configure storage, schedule, retention and encryption on the module\'s Settings tab.',
                'Disabled'     => true,
            ],
        ],
    ];
}

/**
 * Activate the module: create all required database tables.
 *
 * Called by WHMCS when an administrator activates the addon. Uses the Capsule
 * schema builder so no raw DDL strings are executed.
 *
 * @return array ['status' => 'success'|'error', 'description' => string]
 */
function snapshot_pro_activate()
{
    try {
        $schema = Capsule::schema();

        // Snapshots metadata table.
        if (!$schema->hasTable('mod_snapshot_pro_snapshots')) {
            $schema->create('mod_snapshot_pro_snapshots', function ($table) {
                $table->increments('id');
                $table->string('snapshot_id', 64)->unique();
                $table->string('status', 20)->default('running'); // running|complete|failed
                $table->string('trigger', 20)->default('manual');  // manual|cron|pre-restore
                $table->string('storage', 30)->default('local');
                $table->string('scope', 20)->default('full');
                $table->bigInteger('size')->default(0);
                $table->string('checksum', 128)->default('');
                $table->text('reference')->nullable();
                $table->longText('manifest')->nullable();
                $table->text('error')->nullable();
                $table->string('created_by', 191)->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->index('status');
                $table->index('created_at');
            });
        }

        // Settings key/value table.
        if (!$schema->hasTable('mod_snapshot_pro_settings')) {
            $schema->create('mod_snapshot_pro_settings', function ($table) {
                $table->increments('id');
                $table->string('setting_key', 100)->unique();
                $table->longText('setting_value')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        // Audit log table.
        if (!$schema->hasTable('mod_snapshot_pro_logs')) {
            $schema->create('mod_snapshot_pro_logs', function ($table) {
                $table->increments('id');
                $table->string('action', 100);
                $table->string('level', 20)->default('info');
                $table->text('message');
                $table->string('admin_user', 191)->nullable();
                $table->text('context')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->index('level');
                $table->index('created_at');
            });
        }

        // Background job queue (claimed by the dedicated CLI worker cron.php).
        JobQueue::ensureSchema();

        // Seed default settings (only fills gaps; never overwrites existing).
        foreach (Settings::defaults() as $key => $value) {
            if (!Capsule::table('mod_snapshot_pro_settings')->where('setting_key', $key)->exists()) {
                Capsule::table('mod_snapshot_pro_settings')->insert([
                    'setting_key'   => $key,
                    'setting_value' => $value,
                    'updated_at'    => date('Y-m-d H:i:s'),
                ]);
            }
        }

        // Generate an encryption key on first activation if none exists.
        $existingKey = Capsule::table('mod_snapshot_pro_settings')
            ->where('setting_key', 'encryption_key')->value('setting_value');
        if (empty($existingKey)) {
            Capsule::table('mod_snapshot_pro_settings')
                ->where('setting_key', 'encryption_key')
                ->update(['setting_value' => Encryption::generateKey(), 'updated_at' => date('Y-m-d H:i:s')]);
        }

        // Ensure the default local storage directory exists.
        $storageDir = __DIR__ . '/storage';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0750, true);
        }

        Logger::success('module.activate', 'Snapshot Pro activated and tables created.');

        return [
            'status'      => 'success',
            'description' => 'WHMCS Snapshot Pro activated. Database tables created and a secure encryption '
                . 'key was generated automatically. Review the Settings tab before your first backup.',
        ];
    } catch (\Exception $e) {
        return [
            'status'      => 'error',
            'description' => 'Activation failed: ' . $e->getMessage(),
        ];
    }
}

/**
 * Deactivate the module: drop the module's database tables.
 *
 * NOTE: Snapshot archives stored on disk/Drive are intentionally NOT deleted so
 * an accidental deactivation does not destroy backups.
 *
 * @return array ['status' => 'success'|'error', 'description' => string]
 */
function snapshot_pro_deactivate()
{
    try {
        $schema = Capsule::schema();
        $schema->dropIfExists('mod_snapshot_pro_jobs');
        $schema->dropIfExists('mod_snapshot_pro_logs');
        $schema->dropIfExists('mod_snapshot_pro_settings');
        $schema->dropIfExists('mod_snapshot_pro_snapshots');

        return [
            'status'      => 'success',
            'description' => 'WHMCS Snapshot Pro deactivated and its tables were removed. '
                . 'Your stored snapshot archives were left untouched.',
        ];
    } catch (\Exception $e) {
        return [
            'status'      => 'error',
            'description' => 'Deactivation failed: ' . $e->getMessage(),
        ];
    }
}

/**
 * Upgrade the module schema when the configured version changes.
 *
 * WHMCS invokes this when snapshot_pro_config() reports a newer version than
 * the one stored for the addon. Creates the jobs table for the CLI worker queue.
 *
 * @param array $vars WHMCS module variables (includes 'version').
 * @return array ['status' => 'success'|'error', 'description' => string]
 */
function snapshot_pro_upgrade($vars)
{
    try {
        JobQueue::ensureSchema();
        return [
            'status'      => 'success',
            'description' => 'WHMCS Snapshot Pro upgraded to ' . ($vars['version'] ?? '1.1.0')
                . '. Background job queue table is ready.',
        ];
    } catch (\Exception $e) {
        return [
            'status'      => 'error',
            'description' => 'Upgrade failed: ' . $e->getMessage(),
        ];
    }
}

/**
 * Render the admin area output.
 *
 * Dispatches to the requested page (dashboard, create, restore, settings, logs)
 * and renders the corresponding Smarty template. Enforces the Full Administrator
 * requirement and CSRF protection on all state-changing form posts.
 *
 * @param array $vars WHMCS-provided module variables (modulelink, version, etc.).
 *
 * @return void Output is echoed directly.
 */
function snapshot_pro_output($vars)
{
    // Ensure schema migrations apply even if WHMCS has not yet run upgrade().
    try {
        JobQueue::ensureSchema();
    } catch (\Exception $e) {
        // Non-fatal; activation/upgrade remain the primary path.
    }

    $modulelink = $vars['modulelink'];
    $version    = $vars['version'];

    // Enforce Full Administrator access.
    if (!snapshot_pro_isFullAdmin()) {
        echo '<div class="alert alert-danger">Access denied. WHMCS Snapshot Pro is restricted to '
            . 'Full Administrators only.</div>';
        return;
    }

    $adminUser = snapshot_pro_currentAdminUsername();
    $action    = isset($_GET['spaction']) ? preg_replace('/[^a-z_]/', '', $_GET['spaction']) : 'dashboard';

    // Handle non-AJAX form posts (settings save, delete, manual create trigger).
    $notice = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $notice = snapshot_pro_handlePost($action, $adminUser);
    }

    // Base asset URLs.
    $assetsBase = 'modules/addons/snapshot_pro/assets';

    // Build a Smarty instance using WHMCS' bundled Smarty.
    $smarty = new \Smarty();
    $smarty->setTemplateDir(__DIR__ . '/templates');
    $smarty->setCompileDir(rtrim(sys_get_temp_dir(), '/') . '/snapshot_pro_smarty');
    if (!is_dir($smarty->getCompileDir())) {
        @mkdir($smarty->getCompileDir(), 0700, true);
    }

    $manager  = new SnapshotManager();
    $settings = Settings::all();

    // Common template variables.
    $smarty->assign('modulelink', $modulelink);
    $smarty->assign('assetsBase', $assetsBase);
    $smarty->assign('version', $version);
    $smarty->assign('action', $action);
    $smarty->assign('csrfToken', snapshot_pro_csrfToken());
    $smarty->assign('notice', $notice);
    $smarty->assign('navItems', [
        'dashboard' => 'Dashboard',
        'create'    => 'Create Backup',
        'restore'   => 'Restore',
        'settings'  => 'Settings',
        'logs'      => 'Logs',
    ]);

    // Render the requested page.
    switch ($action) {
        case 'create':
            $smarty->assign('settings', $settings);
            $smarty->display('create_backup.tpl');
            break;

        case 'restore':
            $smarty->assign('snapshots', $manager->listSnapshots(200));
            $smarty->assign('steps', RestoreWizard::steps());
            $smarty->display('restore_wizard.tpl');
            break;

        case 'settings':
            $smarty->assign('settings', $settings);
            $smarty->assign('storageTypes', StorageFactory::availableTypes());
            $smarty->display('settings.tpl');
            break;

        case 'logs':
            $page    = isset($_GET['p']) ? max(1, (int) $_GET['p']) : 1;
            $perPage = 50;
            $smarty->assign('logs', Logger::getLogs($perPage, ($page - 1) * $perPage));
            $smarty->assign('totalLogs', Logger::countLogs());
            $smarty->assign('page', $page);
            $smarty->assign('perPage', $perPage);
            $smarty->display('logs.tpl');
            break;

        case 'dashboard':
        default:
            $snapshots  = $manager->listSnapshots(50);
            $lastBackup = null;
            foreach ($snapshots as $s) {
                if ($s->status === 'complete') {
                    $lastBackup = $s;
                    break;
                }
            }
            $smarty->assign('snapshots', $snapshots);
            $smarty->assign('lastBackup', $lastBackup);
            $smarty->assign('totalUsage', $manager->totalUsage());
            $smarty->assign('settings', $settings);
            $smarty->assign('nextBackup', snapshot_pro_nextScheduledRun($settings));
            $smarty->display('dashboard.tpl');
            break;
    }
}

/**
 * Handle non-AJAX POST actions (settings save, snapshot delete).
 *
 * @param string $action    Current page action.
 * @param string $adminUser Current admin username.
 *
 * @return array|null A notice ['type' => 'success'|'danger', 'message' => string] or null.
 */
function snapshot_pro_handlePost($action, $adminUser)
{
    // CSRF validation for every state-changing POST.
    $token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!snapshot_pro_validateCsrf($token)) {
        return ['type' => 'danger', 'message' => 'Security token validation failed. Please try again.'];
    }

    try {
        if ($action === 'settings') {
            $map = [
                'storage_backend', 'local_storage_path', 'gdrive_service_account',
                'gdrive_folder_id', 'schedule', 'retention', 'encryption_key',
                'backup_files', 'backup_database',
            ];
            $values = [];
            foreach ($map as $key) {
                if ($key === 'backup_files' || $key === 'backup_database') {
                    $values[$key] = isset($_POST[$key]) ? '1' : '0';
                } elseif (isset($_POST[$key])) {
                    $values[$key] = trim((string) $_POST[$key]);
                }
            }
            // Never allow an empty encryption key to be saved.
            if (isset($values['encryption_key']) && $values['encryption_key'] === '') {
                unset($values['encryption_key']);
            }
            Settings::setMany($values);
            Logger::success('settings.save', 'Settings updated.', $adminUser);
            return ['type' => 'success', 'message' => 'Settings saved successfully.'];
        }

        if ($action === 'restore' && isset($_POST['delete_snapshot'])) {
            $id = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['delete_snapshot']);
            (new SnapshotManager())->delete($id, $adminUser);
            return ['type' => 'success', 'message' => 'Snapshot ' . $id . ' deleted.'];
        }
    } catch (\Exception $e) {
        return ['type' => 'danger', 'message' => 'Error: ' . $e->getMessage()];
    }

    return null;
}

/**
 * Determine whether the currently logged-in admin has the Full Administrator role.
 *
 * @return bool
 */
function snapshot_pro_isFullAdmin()
{
    try {
        $adminId = snapshot_pro_currentAdminId();
        if (!$adminId) {
            return false;
        }
        $admin = Capsule::table('tbladmins')->where('id', $adminId)->first();
        if (!$admin) {
            return false;
        }
        $role = Capsule::table('tbladminroles')->where('id', $admin->roleid)->first();
        // WHMCS names the top role "Full Administrator" by default.
        return $role && stripos($role->name, 'Full Admin') !== false;
    } catch (\Exception $e) {
        return false;
    }
}

/**
 * Get the current admin's numeric ID from the WHMCS session.
 *
 * @return int|null
 */
function snapshot_pro_currentAdminId()
{
    if (isset($_SESSION['adminid'])) {
        return (int) $_SESSION['adminid'];
    }
    return null;
}

/**
 * Get the current admin's username.
 *
 * @return string
 */
function snapshot_pro_currentAdminUsername()
{
    try {
        $adminId = snapshot_pro_currentAdminId();
        if ($adminId) {
            $admin = Capsule::table('tbladmins')->where('id', $adminId)->first();
            if ($admin) {
                return $admin->username;
            }
        }
    } catch (\Exception $e) {
        // ignore
    }
    return 'unknown';
}

/**
 * Generate (and cache in session) a CSRF token for this admin session.
 *
 * @return string
 */
function snapshot_pro_csrfToken()
{
    if (empty($_SESSION['snapshot_pro_csrf'])) {
        $_SESSION['snapshot_pro_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['snapshot_pro_csrf'];
}

/**
 * Validate a submitted CSRF token in a timing-safe manner.
 *
 * @param string $token
 * @return bool
 */
function snapshot_pro_validateCsrf($token)
{
    return !empty($_SESSION['snapshot_pro_csrf'])
        && is_string($token)
        && hash_equals($_SESSION['snapshot_pro_csrf'], $token);
}

/**
 * Compute a human-readable description of the next scheduled backup time.
 *
 * @param array $settings
 * @return string
 */
function snapshot_pro_nextScheduledRun(array $settings)
{
    $schedule = isset($settings['schedule']) ? $settings['schedule'] : 'daily';
    if ($schedule === 'disabled') {
        return 'Disabled';
    }
    $labels = [
        'daily'   => 'Daily (next WHMCS daily cron run)',
        'weekly'  => 'Weekly (Mondays, via daily cron)',
        'monthly' => 'Monthly (1st of month, via daily cron)',
    ];
    return isset($labels[$schedule]) ? $labels[$schedule] : ucfirst($schedule);
}
