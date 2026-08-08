<?php
/**
 * WHMCS Snapshot Pro - Snapshot Manager
 *
 * The core orchestrator. Coordinates database + filesystem backup, bundling,
 * encryption, integrity checksums, storage upload, metadata persistence,
 * retention pruning and listing/deletion of snapshots.
 *
 * A snapshot archive is a single encrypted file whose plaintext (before
 * encryption) is a tar containing:
 *   - database.sql.gz  (the compressed DB dump)
 *   - filesystem.tar.gz (the compressed WHMCS files)
 *   - manifest.json     (metadata about the snapshot)
 *
 * @package    SnapshotPro
 * @author     bampu79
 * @license    MIT
 */

namespace SnapshotPro;

use WHMCS\Database\Capsule;
use PharData;
use Exception;
use RuntimeException;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Class SnapshotManager
 */
class SnapshotManager
{
    /** @var string Snapshots metadata table. */
    const TABLE = 'mod_snapshot_pro_snapshots';

    /** @var array Loaded module settings. */
    private $settings;

    /** @var string Absolute WHMCS root path. */
    private $whmcsRoot;

    /** @var string Working/temp directory for building archives. */
    private $workDir;

    /**
     * SnapshotManager constructor.
     *
     * @param string|null $whmcsRoot Absolute WHMCS root path (auto-detected if null).
     */
    public function __construct($whmcsRoot = null)
    {
        $this->settings  = Settings::all();
        $this->whmcsRoot = $whmcsRoot !== null ? rtrim($whmcsRoot, '/') : $this->detectWhmcsRoot();
        $this->workDir   = sys_get_temp_dir() . '/snapshot_pro_work';
        if (!is_dir($this->workDir)) {
            @mkdir($this->workDir, 0700, true);
        }
    }

    /**
     * Attempt to detect the WHMCS root directory from known constants/paths.
     *
     * @return string
     */
    private function detectWhmcsRoot()
    {
        if (defined('ROOTDIR')) {
            return rtrim(ROOTDIR, '/');
        }
        // modules/addons/snapshot_pro/lib -> up 4 levels to WHMCS root.
        return dirname(__DIR__, 4);
    }

    /**
     * Create a full or partial snapshot.
     *
     * @param string      $trigger   Who/what triggered it ("manual"|"cron"|"pre-restore").
     * @param string|null $adminUser Admin username (for auditing), if applicable.
     * @param callable|null $progress Optional callback(int $percent, string $message).
     *
     * @return array The created snapshot metadata row (as array).
     *
     * @throws RuntimeException On any fatal failure (partial artifacts cleaned up).
     */
    public function create($trigger = 'manual', $adminUser = null, callable $progress = null)
    {
        $report = function ($pct, $msg) use ($progress) {
            if ($progress) {
                call_user_func($progress, $pct, $msg);
            }
        };

        $timestamp  = date('Ymd_His');
        $snapshotId = 'snap_' . $timestamp . '_' . substr(md5(uniqid('', true)), 0, 8);
        $stageDir   = $this->workDir . '/' . $snapshotId;
        @mkdir($stageDir, 0700, true);

        $backupDb    = (string) Settings::get('backup_database', '1') === '1';
        $backupFiles = (string) Settings::get('backup_files', '1') === '1';

        // Register an initial "running" row so the dashboard can reflect progress.
        $rowId = Capsule::table(self::TABLE)->insertGetId([
            'snapshot_id' => $snapshotId,
            'status'      => 'running',
            'trigger'     => $trigger,
            'storage'     => Settings::get('storage_backend', 'local'),
            'scope'       => $this->describeScope($backupDb, $backupFiles),
            'size'        => 0,
            'checksum'    => '',
            'reference'   => '',
            'created_by'  => $adminUser,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        try {
            $report(5, 'Starting snapshot ' . $snapshotId);
            Logger::info('backup.create', 'Snapshot started: ' . $snapshotId, $adminUser, ['trigger' => $trigger]);

            $manifest = [
                'snapshot_id' => $snapshotId,
                'created_at'  => date('c'),
                'whmcs_root'  => $this->whmcsRoot,
                'components'  => [],
                'trigger'     => $trigger,
            ];

            // 1) Database dump.
            if ($backupDb) {
                $report(15, 'Backing up database…');
                $dbPath = $stageDir . '/database.sql.gz';
                $dbMeta = (new DatabaseBackup())->dump($dbPath);
                $manifest['components']['database'] = [
                    'file'     => 'database.sql.gz',
                    'method'   => $dbMeta['method'],
                    'tables'   => $dbMeta['tables'],
                    'size'     => $dbMeta['size'],
                    'checksum' => Integrity::checksum($dbPath),
                ];
                $report(40, 'Database backup complete (' . $dbMeta['tables'] . ' tables).');
            }

            // 2) Filesystem archive.
            if ($backupFiles) {
                $report(45, 'Archiving WHMCS files…');
                $fsPath = $stageDir . '/filesystem.tar.gz';
                $fsMeta = (new FilesystemBackup($this->whmcsRoot))->archive($fsPath);
                // archiveWithZip may have produced a .zip; detect actual file.
                if (!file_exists($fsPath) && file_exists(preg_replace('/\.tar\.gz$/', '.zip', $fsPath))) {
                    $fsPath = preg_replace('/\.tar\.gz$/', '.zip', $fsPath);
                }
                $manifest['components']['filesystem'] = [
                    'file'     => basename($fsPath),
                    'method'   => $fsMeta['method'],
                    'files'    => $fsMeta['files'],
                    'size'     => $fsMeta['size'],
                    'checksum' => Integrity::checksum($fsPath),
                ];
                $report(65, 'Filesystem backup complete (' . $fsMeta['files'] . ' files).');
            }

            // 3) Write manifest.
            file_put_contents($stageDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

            // 4) Bundle the staged files into a single tar.
            $report(70, 'Bundling snapshot archive…');
            $bundleTar = $this->workDir . '/' . $snapshotId . '.tar';
            $this->bundle($stageDir, $bundleTar);

            // 5) Encrypt the bundle.
            $report(80, 'Encrypting snapshot…');
            $encKey = (string) Settings::get('encryption_key', '');
            if ($encKey === '') {
                throw new RuntimeException('No encryption key configured. Set one in Settings before creating snapshots.');
            }
            $encryptedPath = $this->workDir . '/' . $snapshotId . '.spx';
            (new Encryption($encKey))->encryptFile($bundleTar, $encryptedPath);
            @unlink($bundleTar);

            // 6) Compute final checksum of the encrypted artifact.
            $report(88, 'Computing integrity checksum…');
            $checksum = Integrity::checksum($encryptedPath);
            $size     = (int) filesize($encryptedPath);

            // 7) Store to the configured backend.
            $report(92, 'Uploading to storage backend…');
            $storage   = StorageFactory::make(Settings::get('storage_backend', 'local'), $this->settings);
            $reference = $storage->put($encryptedPath, $snapshotId . '.spx');

            // Local storage keeps the file; other backends can drop the temp copy.
            if ($storage->getType() !== 'local') {
                @unlink($encryptedPath);
            }

            // 8) Persist final metadata.
            Capsule::table(self::TABLE)->where('id', $rowId)->update([
                'status'    => 'complete',
                'size'      => $size,
                'checksum'  => $checksum,
                'reference' => $reference,
                'manifest'  => json_encode($manifest),
                'completed_at' => date('Y-m-d H:i:s'),
            ]);

            Settings::set('last_backup_at', date('Y-m-d H:i:s'));

            $report(96, 'Applying retention policy…');
            $this->applyRetention($adminUser);

            $this->cleanupStage($stageDir);

            $report(100, 'Snapshot complete.');
            Logger::success('backup.create', 'Snapshot completed: ' . $snapshotId, $adminUser, [
                'size'     => $size,
                'checksum' => $checksum,
            ]);

            return (array) Capsule::table(self::TABLE)->where('id', $rowId)->first();
        } catch (Exception $e) {
            // Mark failure, clean up, and rethrow for the caller to surface.
            Capsule::table(self::TABLE)->where('id', $rowId)->update([
                'status'     => 'failed',
                'error'      => $e->getMessage(),
                'completed_at' => date('Y-m-d H:i:s'),
            ]);
            $this->cleanupStage($stageDir);
            @unlink($this->workDir . '/' . $snapshotId . '.tar');
            @unlink($this->workDir . '/' . $snapshotId . '.spx');
            Logger::error('backup.create', 'Snapshot failed: ' . $e->getMessage(), $adminUser, ['snapshot' => $snapshotId]);
            throw new RuntimeException('Snapshot failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Bundle a directory of staged files into a single (uncompressed) tar.
     *
     * @param string $stageDir  Directory containing the snapshot components.
     * @param string $outputTar Path to the tar file to create.
     *
     * @return void
     *
     * @throws RuntimeException On failure.
     */
    private function bundle($stageDir, $outputTar)
    {
        @unlink($outputTar);
        try {
            $phar = new PharData($outputTar);
            foreach (glob($stageDir . '/*') as $file) {
                if (is_file($file)) {
                    $phar->addFile($file, basename($file));
                }
            }
            unset($phar);
        } catch (Exception $e) {
            throw new RuntimeException('Failed to bundle snapshot: ' . $e->getMessage());
        }
    }

    /**
     * Download (if needed) and decrypt a snapshot into a staging directory,
     * returning the path to the extracted component directory.
     *
     * @param array  $snapshot Snapshot metadata row (array).
     * @param string $encKey   Encryption key.
     *
     * @return string Path to the directory containing the extracted components.
     *
     * @throws RuntimeException On failure.
     */
    public function materialize(array $snapshot, $encKey)
    {
        $storage    = StorageFactory::make($snapshot['storage'], $this->settings);
        $encryptedPath = $this->workDir . '/' . $snapshot['snapshot_id'] . '_restore.spx';

        // Fetch the encrypted archive locally.
        $storage->get($snapshot['reference'], $encryptedPath);

        // Verify integrity against stored checksum.
        if (!Integrity::verify($encryptedPath, $snapshot['checksum'])) {
            @unlink($encryptedPath);
            throw new RuntimeException('Integrity check failed: the snapshot archive does not match its stored checksum.');
        }

        // Decrypt.
        $bundleTar = $this->workDir . '/' . $snapshot['snapshot_id'] . '_restore.tar';
        (new Encryption($encKey))->decryptFile($encryptedPath, $bundleTar);
        @unlink($encryptedPath);

        // Extract the bundle tar.
        $extractDir = $this->workDir . '/' . $snapshot['snapshot_id'] . '_extracted';
        if (is_dir($extractDir)) {
            $this->cleanupStage($extractDir);
        }
        @mkdir($extractDir, 0700, true);
        $phar = new PharData($bundleTar);
        $phar->extractTo($extractDir, null, true);
        unset($phar);
        @unlink($bundleTar);

        return $extractDir;
    }

    /**
     * List snapshots (most recent first).
     *
     * @param int $limit Max rows.
     *
     * @return array Array of snapshot rows (stdClass).
     */
    public function listSnapshots($limit = 100)
    {
        return Capsule::table(self::TABLE)
            ->orderBy('id', 'desc')
            ->limit(max(1, (int) $limit))
            ->get()
            ->all();
    }

    /**
     * Fetch a single snapshot by its snapshot_id.
     *
     * @param string $snapshotId
     *
     * @return array|null
     */
    public function getSnapshot($snapshotId)
    {
        $row = Capsule::table(self::TABLE)->where('snapshot_id', $snapshotId)->first();
        return $row ? (array) $row : null;
    }

    /**
     * Delete a snapshot from storage and metadata.
     *
     * @param string      $snapshotId
     * @param string|null $adminUser
     *
     * @return bool
     *
     * @throws RuntimeException On failure.
     */
    public function delete($snapshotId, $adminUser = null)
    {
        $snapshot = $this->getSnapshot($snapshotId);
        if (!$snapshot) {
            throw new RuntimeException('Snapshot not found: ' . $snapshotId);
        }
        try {
            if (!empty($snapshot['reference'])) {
                $storage = StorageFactory::make($snapshot['storage'], $this->settings);
                $storage->delete($snapshot['reference']);
            }
        } catch (Exception $e) {
            Logger::warning('backup.delete', 'Storage delete warning for ' . $snapshotId . ': ' . $e->getMessage(), $adminUser);
        }
        Capsule::table(self::TABLE)->where('snapshot_id', $snapshotId)->delete();
        Logger::info('backup.delete', 'Snapshot deleted: ' . $snapshotId, $adminUser);
        return true;
    }

    /**
     * Apply the retention policy: keep only the newest N completed snapshots.
     *
     * @param string|null $adminUser
     *
     * @return int Number of snapshots pruned.
     */
    public function applyRetention($adminUser = null)
    {
        $keep = (int) Settings::get('retention', '7');
        if ($keep <= 0) {
            return 0;
        }
        $completed = Capsule::table(self::TABLE)
            ->where('status', 'complete')
            ->orderBy('id', 'desc')
            ->get()
            ->all();

        $pruned = 0;
        if (count($completed) > $keep) {
            $toPrune = array_slice($completed, $keep);
            foreach ($toPrune as $snap) {
                try {
                    $this->delete($snap->snapshot_id, $adminUser);
                    $pruned++;
                } catch (Exception $e) {
                    Logger::warning('backup.retention', 'Failed to prune ' . $snap->snapshot_id . ': ' . $e->getMessage(), $adminUser);
                }
            }
        }
        if ($pruned > 0) {
            Logger::info('backup.retention', 'Retention pruned ' . $pruned . ' old snapshot(s).', $adminUser);
        }
        return $pruned;
    }

    /**
     * Total storage usage across all recorded snapshots (bytes).
     *
     * @return int
     */
    public function totalUsage()
    {
        return (int) Capsule::table(self::TABLE)->where('status', 'complete')->sum('size');
    }

    /**
     * Describe the backup scope as a short string.
     *
     * @param bool $db
     * @param bool $files
     * @return string
     */
    private function describeScope($db, $files)
    {
        if ($db && $files) {
            return 'full';
        }
        if ($db) {
            return 'database';
        }
        if ($files) {
            return 'files';
        }
        return 'empty';
    }

    /**
     * Recursively remove a staging directory.
     *
     * @param string $dir
     * @return void
     */
    public function cleanupStage($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    /**
     * Expose the WHMCS root path.
     *
     * @return string
     */
    public function getWhmcsRoot()
    {
        return $this->whmcsRoot;
    }

    /**
     * Expose the working directory.
     *
     * @return string
     */
    public function getWorkDir()
    {
        return $this->workDir;
    }
}
