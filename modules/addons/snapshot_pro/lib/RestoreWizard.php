<?php
/**
 * WHMCS Snapshot Pro - Restore Wizard
 *
 * Orchestrates the multi-step guided restore process:
 *   1. select    - choose a snapshot
 *   2. verify    - SHA-256 integrity check
 *   3. safety    - pre-restore safety backup
 *   4. scope     - choose full / database / files
 *   5. confirm   - summary of what will be overwritten
 *   6. execute   - materialize + restore with a live log
 *   7. report    - post-restore verification
 *
 * Each step is invoked independently by the AJAX handler and returns a
 * structured array, keeping the browser responsive while long operations run.
 *
 * @package    SnapshotPro
 * @author     bampu79
 * @license    MIT
 */

namespace SnapshotPro;

use Exception;
use RuntimeException;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Class RestoreWizard
 */
class RestoreWizard
{
    /** @var SnapshotManager */
    private $manager;

    /** @var string|null Admin username performing the restore. */
    private $adminUser;

    /**
     * RestoreWizard constructor.
     *
     * @param string|null $adminUser Admin username for auditing.
     */
    public function __construct($adminUser = null)
    {
        $this->manager   = new SnapshotManager();
        $this->adminUser = $adminUser;
    }

    /**
     * Step 2: Verify the integrity of a snapshot before restore begins.
     *
     * Downloads (if remote) the encrypted archive and confirms its SHA-256
     * checksum matches the value recorded at creation time.
     *
     * @param string $snapshotId
     *
     * @return array ['ok' => bool, 'checksum' => string, 'message' => string]
     *
     * @throws RuntimeException If the snapshot cannot be found.
     */
    public function verify($snapshotId)
    {
        $snapshot = $this->requireSnapshot($snapshotId);
        Logger::info('restore.verify', 'Verifying snapshot ' . $snapshotId, $this->adminUser);

        $settings = Settings::all();
        $storage  = StorageFactory::make($snapshot['storage'], $settings);

        $tmp = $this->manager->getWorkDir() . '/' . $snapshotId . '_verify.spx';
        try {
            $storage->get($snapshot['reference'], $tmp);
            $ok = Integrity::verify($tmp, $snapshot['checksum']);
        } finally {
            @unlink($tmp);
        }

        Logger::log(
            'restore.verify',
            $ok ? Logger::LEVEL_SUCCESS : Logger::LEVEL_ERROR,
            $ok ? 'Integrity verified for ' . $snapshotId : 'Integrity FAILED for ' . $snapshotId,
            $this->adminUser
        );

        return [
            'ok'       => $ok,
            'checksum' => $snapshot['checksum'],
            'message'  => $ok
                ? 'Integrity verified. The archive matches its recorded SHA-256 checksum.'
                : 'Integrity check FAILED. The archive is corrupt or was tampered with. Restore is blocked.',
        ];
    }

    /**
     * Step 3: Create a quick pre-restore safety backup so the current state can
     * be recovered if the restore goes wrong.
     *
     * @return array The created safety snapshot metadata.
     */
    public function safetyBackup()
    {
        Logger::info('restore.safety', 'Creating pre-restore safety backup', $this->adminUser);
        return $this->manager->create('pre-restore', $this->adminUser);
    }

    /**
     * Step 5: Build a confirmation summary describing what will be overwritten.
     *
     * @param string $snapshotId
     * @param string $scope      full|database|files
     *
     * @return array Summary details for the confirmation screen.
     */
    public function confirmationSummary($snapshotId, $scope)
    {
        $snapshot = $this->requireSnapshot($snapshotId);
        $manifest = $this->decodeManifest($snapshot);

        $summary = [
            'snapshot_id' => $snapshotId,
            'created_at'  => $snapshot['created_at'],
            'scope'       => $scope,
            'targets'     => [],
        ];

        $components = isset($manifest['components']) ? $manifest['components'] : [];

        if (($scope === 'full' || $scope === 'database') && isset($components['database'])) {
            $summary['targets'][] = [
                'type'    => 'database',
                'detail'  => 'The entire WHMCS database will be overwritten ('
                    . (isset($components['database']['tables']) ? $components['database']['tables'] : '?') . ' tables).',
            ];
        }
        if (($scope === 'full' || $scope === 'files') && isset($components['filesystem'])) {
            $summary['targets'][] = [
                'type'    => 'files',
                'detail'  => 'WHMCS files under ' . $this->manager->getWhmcsRoot()
                    . ' will be overwritten (' . (isset($components['filesystem']['files']) ? $components['filesystem']['files'] : '?') . ' files).',
            ];
        }

        return $summary;
    }

    /**
     * Step 6: Execute the restore.
     *
     * @param string        $snapshotId
     * @param string        $scope      full|database|files
     * @param string        $encKey     Encryption key.
     * @param callable|null $progress   Optional callback(int $percent, string $message).
     *
     * @return array ['ok' => bool, 'log' => string[], 'message' => string]
     *
     * @throws RuntimeException On fatal failure.
     */
    public function execute($snapshotId, $scope, $encKey, callable $progress = null)
    {
        $log    = [];
        $append = function ($msg) use (&$log, $progress) {
            $log[] = '[' . date('H:i:s') . '] ' . $msg;
            return $msg;
        };
        $report = function ($pct, $msg) use ($progress, $append) {
            $append($msg);
            if ($progress) {
                call_user_func($progress, $pct, $msg);
            }
        };

        $snapshot = $this->requireSnapshot($snapshotId);
        Logger::info('restore.execute', 'Restore started for ' . $snapshotId . ' (scope: ' . $scope . ')', $this->adminUser);

        $extractDir = null;
        try {
            $report(10, 'Retrieving and decrypting snapshot…');
            $extractDir = $this->manager->materialize($snapshot, $encKey);
            $report(35, 'Snapshot decrypted and verified.');

            $manifest   = $this->readManifestFromDir($extractDir);
            $components = isset($manifest['components']) ? $manifest['components'] : [];

            // Restore database.
            if (($scope === 'full' || $scope === 'database') && isset($components['database'])) {
                $report(45, 'Restoring database…');
                $dbFile = $extractDir . '/' . $components['database']['file'];
                if (!is_file($dbFile)) {
                    throw new RuntimeException('Database component missing from archive.');
                }
                (new DatabaseBackup())->restore($dbFile);
                $report(70, 'Database restore complete.');
            }

            // Restore files.
            if (($scope === 'full' || $scope === 'files') && isset($components['filesystem'])) {
                $report(75, 'Restoring filesystem…');
                $fsFile = $extractDir . '/' . $components['filesystem']['file'];
                if (!is_file($fsFile)) {
                    throw new RuntimeException('Filesystem component missing from archive.');
                }
                (new FilesystemBackup($this->manager->getWhmcsRoot()))
                    ->extract($fsFile, $this->manager->getWhmcsRoot());
                $report(92, 'Filesystem restore complete.');
            }

            $report(97, 'Running post-restore verification…');
            $verification = $this->postRestoreVerify($scope);
            $report(100, 'Restore completed successfully.');

            Logger::success('restore.execute', 'Restore completed for ' . $snapshotId, $this->adminUser, [
                'scope'        => $scope,
                'verification' => $verification,
            ]);

            return [
                'ok'           => true,
                'log'          => $log,
                'verification' => $verification,
                'message'      => 'Restore completed successfully.',
            ];
        } catch (Exception $e) {
            $append('ERROR: ' . $e->getMessage());
            Logger::error('restore.execute', 'Restore failed for ' . $snapshotId . ': ' . $e->getMessage(), $this->adminUser);
            return [
                'ok'      => false,
                'log'     => $log,
                'message' => 'Restore failed: ' . $e->getMessage(),
            ];
        } finally {
            if ($extractDir !== null) {
                $this->manager->cleanupStage($extractDir);
            }
        }
    }

    /**
     * Step 7: Basic post-restore verification.
     *
     * Confirms core WHMCS tables are present/queryable after a database restore
     * and that the WHMCS root remains readable after a file restore.
     *
     * @param string $scope
     *
     * @return array Verification results.
     */
    public function postRestoreVerify($scope)
    {
        $results = [];
        if ($scope === 'full' || $scope === 'database') {
            try {
                $count = \WHMCS\Database\Capsule::table('tblclients')->count();
                $results['database'] = [
                    'ok'      => true,
                    'message' => 'Database reachable. tblclients row count: ' . $count,
                ];
            } catch (Exception $e) {
                $results['database'] = ['ok' => false, 'message' => 'Database verification failed: ' . $e->getMessage()];
            }
        }
        if ($scope === 'full' || $scope === 'files') {
            $root = $this->manager->getWhmcsRoot();
            $results['files'] = [
                'ok'      => is_readable($root . '/configuration.php'),
                'message' => is_readable($root . '/configuration.php')
                    ? 'WHMCS root readable and configuration.php present.'
                    : 'Warning: configuration.php not readable after restore.',
            ];
        }
        return $results;
    }

    /**
     * Load and require a snapshot, throwing if missing.
     *
     * @param string $snapshotId
     * @return array
     * @throws RuntimeException
     */
    private function requireSnapshot($snapshotId)
    {
        $snapshot = $this->manager->getSnapshot($snapshotId);
        if (!$snapshot) {
            throw new RuntimeException('Snapshot not found: ' . $snapshotId);
        }
        if ($snapshot['status'] !== 'complete') {
            throw new RuntimeException('Snapshot is not in a restorable state (status: ' . $snapshot['status'] . ').');
        }
        return $snapshot;
    }

    /**
     * Decode the manifest stored on the snapshot row.
     *
     * @param array $snapshot
     * @return array
     */
    private function decodeManifest(array $snapshot)
    {
        if (!empty($snapshot['manifest'])) {
            $decoded = json_decode($snapshot['manifest'], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    /**
     * Read the manifest.json from an extracted snapshot directory.
     *
     * @param string $dir
     * @return array
     */
    private function readManifestFromDir($dir)
    {
        $path = $dir . '/manifest.json';
        if (is_readable($path)) {
            $decoded = json_decode(file_get_contents($path), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    /**
     * Return the ordered list of wizard steps for the UI.
     *
     * @return array<int,array{key:string,label:string}>
     */
    public static function steps()
    {
        return [
            ['key' => 'select',  'label' => 'Select Snapshot'],
            ['key' => 'verify',  'label' => 'Integrity Check'],
            ['key' => 'safety',  'label' => 'Safety Backup'],
            ['key' => 'scope',   'label' => 'Restore Scope'],
            ['key' => 'confirm', 'label' => 'Confirmation'],
            ['key' => 'execute', 'label' => 'Restore'],
            ['key' => 'report',  'label' => 'Report'],
        ];
    }
}
