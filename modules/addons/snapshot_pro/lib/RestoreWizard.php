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
     * Parse create_safety_backup / skip acknowledgment from the HTTP request.
     *
     * Production: missing or invalid create_safety_backup defaults to true.
     * Test/clone: missing or invalid defaults to false.
     *
     * @param array         $request
     * @param RestoreTarget $target
     *
     * @return array{create_safety_backup:bool,skip_safety_backup_ack:bool}
     */
    public static function safetyBackupFromRequest(array $request, RestoreTarget $target)
    {
        $production = $target->isProduction();
        $raw        = isset($request['create_safety_backup']) ? $request['create_safety_backup'] : null;

        if ($raw === null || $raw === '') {
            $create = $production;
        } elseif ($raw === '1' || $raw === 1 || $raw === true || $raw === 'true') {
            $create = true;
        } elseif ($raw === '0' || $raw === 0 || $raw === false || $raw === 'false') {
            $create = false;
        } else {
            $create = $production;
        }

        $ackRaw = isset($request['skip_safety_backup_ack']) ? $request['skip_safety_backup_ack'] : '';
        $ack    = ($ackRaw === '1' || $ackRaw === 1 || $ackRaw === true || $ackRaw === 'true');

        return [
            'create_safety_backup'   => $create,
            'skip_safety_backup_ack' => $ack,
        ];
    }

    /**
     * Enforce safety-backup policy before confirm or execute.
     *
     * @param RestoreTarget $target
     * @param array         $safety From safetyBackupFromRequest()
     *
     * @return void
     */
    public static function assertSafetyBackupPolicy(RestoreTarget $target, array $safety)
    {
        if (!empty($safety['create_safety_backup'])) {
            return;
        }
        if ($target->isTest()) {
            return;
        }
        if (empty($safety['skip_safety_backup_ack'])) {
            throw new RuntimeException(
                'You must acknowledge proceeding without a pre-restore safety backup.'
            );
        }
    }

    /**
     * Step 5: Build a confirmation summary describing what will be overwritten.
     *
     * @param string $snapshotId
     * @param string $scope      full|database|files
     *
     * @return array Summary details for the confirmation screen.
     */
    public function confirmationSummary($snapshotId, $scope, RestoreTarget $target, array $safety)
    {
        $snapshot = $this->requireSnapshot($snapshotId);
        $manifest = $this->decodeManifest($snapshot);
        $liveDb   = RestoreTarget::liveDatabaseConfig();

        $summary = [
            'snapshot_id' => $snapshotId,
            'created_at'  => $snapshot['created_at'],
            'scope'       => $scope,
            'restore_mode'=> $target->getMode(),
            'targets'     => [],
            'warnings'    => [],
        ];

        if ($target->isProduction()) {
            $summary['headline'] = 'Current WHMCS';
            $summary['filesystem_target'] = $this->manager->getWhmcsRoot();
            $summary['database_target'] = $liveDb['database'];
            $summary['warnings'][] = 'This will overwrite the current WHMCS installation.';
        } else {
            $summary['headline'] = 'Test / Clone Restore';
            $summary['filesystem_target'] = $target->getFilesystemPath();
            $summary['database_target'] = $target->getDatabaseConfig()['database'];
            $summary['test_url'] = $target->getTestUrl();
            $summary['warnings'][] = 'Production WHMCS and production database will not be modified.';
        }

        $components = isset($manifest['components']) ? $manifest['components'] : [];

        if (($scope === 'full' || $scope === 'database') && isset($components['database'])) {
            if ($target->isProduction()) {
                $summary['targets'][] = [
                    'type'   => 'database',
                    'detail' => 'Database: all '
                        . (isset($components['database']['tables']) ? $components['database']['tables'] : '?')
                        . ' tables will be overwritten.',
                ];
            } else {
                $summary['targets'][] = [
                    'type'   => 'database',
                    'detail' => 'Database: restore into ' . $summary['database_target'] . '.',
                ];
            }
        }
        if (($scope === 'full' || $scope === 'files') && isset($components['filesystem'])) {
            if ($target->isProduction()) {
                $summary['targets'][] = [
                    'type'   => 'files',
                    'detail' => 'Files: ' . $summary['filesystem_target'] . ' ('
                        . (isset($components['filesystem']['files']) ? $components['filesystem']['files'] : '?')
                        . ' files) will be overwritten.',
                ];
            } else {
                $summary['targets'][] = [
                    'type'   => 'files',
                    'detail' => 'Filesystem: ' . $summary['filesystem_target'] . '.',
                ];
            }
        }

        self::assertSafetyBackupPolicy($target, $safety);

        $summary['create_safety_backup'] = $safety['create_safety_backup'];
        if ($safety['create_safety_backup']) {
            $summary['safety_backup'] = [
                'status' => 'enabled',
                'label'  => 'Pre-restore safety backup: Will be created before restore',
            ];
        } elseif ($target->isTest()) {
            $summary['safety_backup'] = [
                'status' => 'skipped_test',
                'label'  => 'Pre-restore safety backup: Not required for isolated test restore',
            ];
        } else {
            $summary['safety_backup'] = [
                'status' => 'skipped',
                'label'  => 'Pre-restore safety backup: SKIPPED',
            ];
            $summary['warnings'][] = 'You are proceeding without a pre-restore safety backup. '
                . 'If the restore fails or produces unexpected results, Snapshot Pro will not have '
                . 'an automatically created rollback point.';
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
    public function execute($snapshotId, $scope, $encKey, RestoreTarget $target, callable $progress = null)
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
        Logger::info(
            'restore.execute',
            'Restore started for ' . $snapshotId . ' (scope: ' . $scope . ', mode: ' . $target->getMode() . ')',
            $this->adminUser
        );

        $extractDir = null;
        try {
            $report(10, 'Retrieving and decrypting snapshot…');
            $extractDir = $this->manager->materialize($snapshot, $encKey);
            $report(35, 'Snapshot decrypted and verified.');

            $manifest   = $this->readManifestFromDir($extractDir);
            $components = isset($manifest['components']) ? $manifest['components'] : [];
            $fsRoot     = $target->getFilesystemPath();
            $dbConfig   = $target->getDatabaseConfig();
            $runtimePrep = null;
            $fsIntegrity = null;

            if ($target->isProduction()) {
                if (($scope === 'full' || $scope === 'database') && isset($components['database'])) {
                    $report(45, 'Restoring database…');
                    $dbFile = $extractDir . '/' . $components['database']['file'];
                    if (!is_file($dbFile)) {
                        throw new RuntimeException('Database component missing from archive.');
                    }
                    (new DatabaseBackup())->restore($dbFile, $dbConfig);
                    $report(70, 'Database restore complete.');
                }

                if (($scope === 'full' || $scope === 'files') && isset($components['filesystem'])) {
                    $report(75, 'Restoring filesystem…');
                    $fsFile = $extractDir . '/' . $components['filesystem']['file'];
                    if (!is_file($fsFile)) {
                        throw new RuntimeException('Filesystem component missing from archive.');
                    }
                    $prodRoot = $this->manager->getWhmcsRoot();
                    $fsBackup = new FilesystemBackup($prodRoot);
                    $fsBackup->extract($fsFile, $prodRoot);
                    $runtimePrep = FilesystemBackup::prepareRuntimeDirectories($prodRoot);
                    $fsIntegrity = $fsBackup->verifyAndRepairExtractedArchive($fsFile, $prodRoot);
                    $report(92, 'Filesystem restore complete.');
                }
            } else {
                if (($scope === 'full' || $scope === 'files') && isset($components['filesystem'])) {
                    $report(55, 'Restoring filesystem…');
                    $fsFile = $extractDir . '/' . $components['filesystem']['file'];
                    if (!is_file($fsFile)) {
                        throw new RuntimeException('Filesystem component missing from archive.');
                    }
                    $fsBackup = new FilesystemBackup($fsRoot);
                    $fsBackup->extract($fsFile, $fsRoot);
                    $report(62, 'Preparing runtime directories…');
                    $runtimePrep = FilesystemBackup::prepareRuntimeDirectories($fsRoot);
                    $report(65, 'Verifying filesystem restore…');
                    $fsIntegrity = $fsBackup->verifyAndRepairExtractedArchive($fsFile, $fsRoot);
                    $report(68, 'Patching test configuration.php…');
                    RestoreTarget::patchConfigurationPhp($fsRoot . '/configuration.php', $dbConfig);
                    $report(72, 'Filesystem restore complete.');
                }

                if (($scope === 'full' || $scope === 'database') && isset($components['database'])) {
                    $report(78, 'Restoring database…');
                    $dbFile = $extractDir . '/' . $components['database']['file'];
                    if (!is_file($dbFile)) {
                        throw new RuntimeException('Database component missing from archive.');
                    }
                    (new DatabaseBackup())->restore($dbFile, $dbConfig);
                    $report(88, 'Updating test WHMCS URL settings…');
                    RestoreTarget::patchWhmcsUrlSettings($dbConfig, $target->getTestUrl());
                    $report(92, 'Database restore complete.');
                }
            }

            $report(97, 'Running post-restore verification…');
            $verification = $this->postRestoreVerify($scope, $target, $runtimePrep, $fsIntegrity);
            if (!$this->restoreVerificationPassed($scope, $target, $verification, $runtimePrep, $fsIntegrity)) {
                $failure = $this->formatRestoreVerificationFailure($target, $verification, $runtimePrep, $fsIntegrity);
                throw new RuntimeException($failure);
            }

            $successMessage = $target->isTest()
                ? 'Test restore complete.'
                : 'Restore completed successfully.';
            $report(100, $successMessage);

            Logger::success('restore.execute', $successMessage . ' Snapshot ' . $snapshotId, $this->adminUser, [
                'scope'        => $scope,
                'mode'         => $target->getMode(),
                'verification' => $verification,
            ]);

            return [
                'ok'           => true,
                'log'          => $log,
                'verification' => $verification,
                'message'      => $successMessage,
                'restore_mode' => $target->getMode(),
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
    public function postRestoreVerify($scope, RestoreTarget $target, array $runtimePrep = null, array $fsIntegrity = null)
    {
        $results = [];
        if ($target->isProduction()) {
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
                $results = array_merge($results, $this->buildFilesystemVerificationResults($runtimePrep, $fsIntegrity));
            }
            return $results;
        }

        $testRoot = $target->getFilesystemPath();
        $dbConfig = $target->getDatabaseConfig();
        $liveRoot = $target->getLiveRoot();
        $liveDb   = RestoreTarget::liveDatabaseConfig();

        $results['production'] = [
            'ok'      => is_dir($liveRoot) && RestoreTarget::canonicalPath($liveRoot, true) === $liveRoot,
            'message' => 'Production installation: UNCHANGED',
        ];

        if ($scope === 'full' || $scope === 'files') {
            $configPath = $testRoot . '/configuration.php';
            $configOk   = is_readable($configPath);
            $configMsg  = $configOk
                ? 'Test filesystem and configuration.php present.'
                : 'Test configuration.php not readable.';
            if ($configOk) {
                $configOk = $this->configurationUsesDatabase($configPath, $dbConfig['database']);
                $configMsg = $configOk
                    ? 'configuration.php points to the test database.'
                    : 'configuration.php does not point to the test database.';
            }
            $results['files'] = ['ok' => $configOk, 'message' => $configMsg];
            $results['filesystem_target'] = [
                'ok'      => RestoreTarget::canonicalPath($testRoot, true) !== $liveRoot,
                'message' => 'Filesystem: ' . $testRoot,
            ];
            $results = array_merge($results, $this->buildFilesystemVerificationResults($runtimePrep, $fsIntegrity));
        }

        if ($scope === 'full' || $scope === 'database') {
            try {
                $pdo = RestoreTarget::connectPdo($dbConfig, true);
                $count = (int) $pdo->query('SELECT COUNT(*) FROM tblclients')->fetchColumn();
                $urlRow = $pdo->prepare('SELECT value FROM tblconfiguration WHERE setting = ?');
                $urlRow->execute(['SystemURL']);
                $systemUrl = (string) $urlRow->fetchColumn();
                $dbOk = strcasecmp($dbConfig['database'], $liveDb['database']) !== 0;
                $results['database'] = [
                    'ok'      => $dbOk && $count >= 0,
                    'message' => 'Database: ' . $dbConfig['database'] . ' (tblclients rows: ' . $count . ')',
                ];
                $results['database_target'] = [
                    'ok'      => $dbOk,
                    'message' => 'Test database is not the live WHMCS database.',
                ];
                $results['test_url'] = [
                    'ok'      => $systemUrl === $target->getTestUrl(),
                    'message' => 'URL: ' . $target->getTestUrl() . ($systemUrl === $target->getTestUrl() ? '' : ' (stored: ' . $systemUrl . ')'),
                ];
            } catch (Exception $e) {
                $results['database'] = ['ok' => false, 'message' => 'Test database verification failed: ' . $e->getMessage()];
            }
        }

        $results['isolation'] = [
            'ok'      => true,
            'message' => 'Isolate the clone before use: disable cron, mail, and payment gateways until verified.',
        ];

        return $results;
    }

    /**
     * @param array|null $runtimePrep
     * @param array|null $fsIntegrity
     *
     * @return array<string,array{ok:bool,message:string}>
     */
    private function buildFilesystemVerificationResults(array $runtimePrep = null, array $fsIntegrity = null)
    {
        $results = [];
        if ($runtimePrep !== null) {
            $prepared = isset($runtimePrep['prepared']) ? $runtimePrep['prepared'] : [];
            $templatesWritable = isset($runtimePrep['details']['templates_c']['writable'])
                ? (bool) $runtimePrep['details']['templates_c']['writable']
                : false;
            $results['runtime_directories'] = [
                'ok'      => !empty($runtimePrep['ok']),
                'message' => 'Runtime directories prepared: ' . implode(', ', $prepared)
                    . '. templates_c writable: ' . ($templatesWritable ? 'YES' : 'NO'),
            ];
        }
        if ($fsIntegrity !== null) {
            $msg = 'Filesystem archive verification: expected ' . (int) $fsIntegrity['expected_count'] . ' files.';
            if (!empty($fsIntegrity['retried'])) {
                $msg .= ' Retried ' . count($fsIntegrity['retried']) . ' file(s).';
            }
            if (!$fsIntegrity['ok']) {
                $parts = [];
                if (!empty($fsIntegrity['still_missing'])) {
                    $parts[] = 'missing: ' . implode(', ', array_slice($fsIntegrity['still_missing'], 0, 10));
                }
                if (!empty($fsIntegrity['still_mismatch'])) {
                    $parts[] = 'size mismatch: ' . implode(', ', array_slice($fsIntegrity['still_mismatch'], 0, 10));
                }
                $msg .= ' ' . implode('; ', $parts);
            } else {
                $msg .= ' All expected archive members present.';
            }
            $results['filesystem_integrity'] = [
                'ok'      => !empty($fsIntegrity['ok']),
                'message' => $msg,
            ];
        }
        return $results;
    }

    /**
     * @param string $scope
     * @param RestoreTarget $target
     * @param array $verification
     * @param array|null $runtimePrep
     * @param array|null $fsIntegrity
     *
     * @return bool
     */
    private function restoreVerificationPassed($scope, RestoreTarget $target, array $verification, array $runtimePrep = null, array $fsIntegrity = null)
    {
        foreach ($verification as $entry) {
            if (is_array($entry) && array_key_exists('ok', $entry) && !$entry['ok']) {
                return false;
            }
        }
        if (($scope === 'full' || $scope === 'files')) {
            if ($runtimePrep !== null && empty($runtimePrep['ok'])) {
                return false;
            }
            if ($fsIntegrity !== null && empty($fsIntegrity['ok'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param RestoreTarget $target
     * @param array $verification
     * @param array|null $runtimePrep
     * @param array|null $fsIntegrity
     *
     * @return string
     */
    private function formatRestoreVerificationFailure(RestoreTarget $target, array $verification, array $runtimePrep = null, array $fsIntegrity = null)
    {
        if ($fsIntegrity !== null && empty($fsIntegrity['ok'])) {
            $details = [];
            if (!empty($fsIntegrity['still_missing'])) {
                $details[] = 'missing files: ' . implode(', ', $fsIntegrity['still_missing']);
            }
            if (!empty($fsIntegrity['still_mismatch'])) {
                $details[] = 'size mismatches: ' . implode(', ', $fsIntegrity['still_mismatch']);
            }
            return 'Test restore failed: filesystem verification failed. ' . implode('; ', $details);
        }
        if ($runtimePrep !== null && empty($runtimePrep['ok'])) {
            $bad = [];
            foreach ($runtimePrep['details'] as $name => $info) {
                if (empty($info['writable'])) {
                    $bad[] = $name;
                }
            }
            return 'Test restore failed: required runtime directories are not writable: ' . implode(', ', $bad);
        }
        if ($target->isTest()) {
            return 'Test restore failed: post-restore verification did not pass.';
        }
        return 'Restore failed: post-restore verification did not pass.';
    }

    /**
     * @param string $configPath
     * @param string $expectedDatabase
     * @return bool
     */
    private function configurationUsesDatabase($configPath, $expectedDatabase)
    {
        $content = file_get_contents($configPath);
        if ($content === false) {
            return false;
        }
        if (preg_match('/\$db_name\s*=\s*\'([^\']*)\'/', $content, $m)) {
            return $m[1] === $expectedDatabase;
        }
        if (preg_match('/\$db_name\s*=\s*"([^"]*)"/', $content, $m)) {
            return $m[1] === $expectedDatabase;
        }
        return false;
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
            ['key' => 'mode',    'label' => 'Restore Mode'],
            ['key' => 'confirm', 'label' => 'Confirmation'],
            ['key' => 'execute', 'label' => 'Restore'],
            ['key' => 'report',  'label' => 'Report'],
        ];
    }
}
