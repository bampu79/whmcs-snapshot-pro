<?php
/**
 * WHMCS Snapshot Pro - Snapshot Manager
 *
 * The core orchestrator. Coordinates database + filesystem backup, bundling,
 * encryption, integrity checksums, storage upload, metadata persistence,
 * retention pruning and listing/deletion of snapshots.
 *
 * A snapshot archive is a single encrypted file whose plaintext (before
 * encryption) is an uncompressed tar containing:
 *   - database.sql.gz  (the compressed DB dump)
 *   - filesystem.zip or filesystem.tar.gz (WHMCS files)
 *   - manifest.json     (metadata about the snapshot)
 *
 * @package    SnapshotPro
 * @author     bampu79
 * @license    MIT
 */

namespace SnapshotPro;

use WHMCS\Database\Capsule;
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
     * Bundle staged snapshot components into an uncompressed ustar archive.
     *
     * PharData is not used: adding filesystem.zip via PharData::addFile() makes
     * PHP mount the zip as phar:// and fails with RecursiveDirectoryIterator
     * "Unable to find the wrapper phar".
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
        $files = [];
        $entries = glob($stageDir . DIRECTORY_SEPARATOR . '*') ?: [];
        foreach ($entries as $file) {
            if (is_file($file)) {
                $files[] = $file;
            }
        }
        if ($files === []) {
            throw new RuntimeException('No snapshot components were staged for bundling.');
        }
        try {
            self::writeUstarArchive($outputTar, $files);
        } catch (Exception $e) {
            @unlink($outputTar);
            throw new RuntimeException('Failed to bundle snapshot: ' . $e->getMessage(), 0, $e);
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
        try {
            self::extractUstarArchive($bundleTar, $extractDir);
        } catch (Exception $e) {
            @unlink($bundleTar);
            $this->cleanupStage($extractDir);
            throw new RuntimeException('Failed to extract snapshot bundle: ' . $e->getMessage(), 0, $e);
        }
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

    /**
     * Write an uncompressed ustar archive from a list of regular files.
     *
     * Member names are the basenames only. Used for the outer snapshot bundle
     * (a handful of staged files), not a recursive tree walk.
     *
     * @param string   $outputTar
     * @param string[] $files Absolute paths.
     * @return void
     */
    private static function writeUstarArchive($outputTar, array $files)
    {
        $out = @fopen($outputTar, 'wb');
        if ($out === false) {
            throw new RuntimeException('Unable to create snapshot tar at ' . $outputTar);
        }
        try {
            foreach ($files as $path) {
                $name = basename($path);
                if (!self::isSafeTarMemberName($name)) {
                    throw new RuntimeException('Refusing to bundle snapshot member: ' . $name);
                }
                $size = filesize($path);
                if ($size === false) {
                    throw new RuntimeException('Unable to stat snapshot member: ' . $name);
                }
                $header = self::buildUstarHeader($name, (int) $size, (int) filemtime($path));
                if (fwrite($out, $header) !== 512) {
                    throw new RuntimeException('Failed to write tar header for ' . $name);
                }
                $in = @fopen($path, 'rb');
                if ($in === false) {
                    throw new RuntimeException('Unable to read snapshot member: ' . $name);
                }
                try {
                    while (!feof($in)) {
                        $chunk = fread($in, 8192);
                        if ($chunk === false) {
                            throw new RuntimeException('Read error bundling ' . $name);
                        }
                        if ($chunk !== '' && fwrite($out, $chunk) !== strlen($chunk)) {
                            throw new RuntimeException('Write error bundling ' . $name);
                        }
                    }
                } finally {
                    fclose($in);
                }
                $pad = (512 - ((int) $size % 512)) % 512;
                if ($pad > 0 && fwrite($out, str_repeat("\0", $pad)) !== $pad) {
                    throw new RuntimeException('Failed to pad tar member ' . $name);
                }
            }
            $eof = str_repeat("\0", 1024);
            if (fwrite($out, $eof) !== 1024) {
                throw new RuntimeException('Failed to finalize snapshot tar.');
            }
        } finally {
            fclose($out);
        }
    }

    /**
     * Extract regular files from an uncompressed ustar archive.
     *
     * @param string $tarPath
     * @param string $targetDir
     * @return void
     */
    private static function extractUstarArchive($tarPath, $targetDir)
    {
        $in = @fopen($tarPath, 'rb');
        if ($in === false) {
            throw new RuntimeException('Unable to open snapshot tar for extraction.');
        }
        $extracted = 0;
        try {
            while (!feof($in)) {
                $header = fread($in, 512);
                if ($header === false || strlen($header) === 0) {
                    break;
                }
                if (strlen($header) < 512) {
                    throw new RuntimeException('Truncated snapshot tar header.');
                }
                if ($header === str_repeat("\0", 512)) {
                    $next = fread($in, 512);
                    break;
                }
                $name = rtrim(substr($header, 0, 100), "\0");
                $prefix = rtrim(substr($header, 345, 155), "\0");
                if ($prefix !== '') {
                    $name = $prefix . '/' . $name;
                }
                $sizeOctal = rtrim(substr($header, 124, 12), "\0 ");
                $size = octdec($sizeOctal);
                $typeflag = $header[156];
                if (!self::isSafeTarMemberName($name)) {
                    throw new RuntimeException('Refusing to extract snapshot member: ' . $name);
                }
                $pad = (512 - ($size % 512)) % 512;
                if ($typeflag !== '0' && $typeflag !== "\0") {
                    if ($size + $pad > 0 && fseek($in, $size + $pad, SEEK_CUR) !== 0) {
                        throw new RuntimeException('Unable to skip tar member ' . $name);
                    }
                    continue;
                }
                $dest = $targetDir . DIRECTORY_SEPARATOR . $name;
                $out = @fopen($dest, 'wb');
                if ($out === false) {
                    throw new RuntimeException('Unable to write extracted member: ' . $name);
                }
                try {
                    $remaining = $size;
                    while ($remaining > 0) {
                        $chunk = fread($in, (int) min(8192, $remaining));
                        if ($chunk === false || $chunk === '') {
                            throw new RuntimeException('Truncated snapshot tar member: ' . $name);
                        }
                        fwrite($out, $chunk);
                        $remaining -= strlen($chunk);
                    }
                } finally {
                    fclose($out);
                }
                if ($pad > 0 && fseek($in, $pad, SEEK_CUR) !== 0) {
                    throw new RuntimeException('Unable to skip tar padding for ' . $name);
                }
                $extracted++;
            }
        } finally {
            fclose($in);
        }
        if ($extracted < 1) {
            throw new RuntimeException('Snapshot tar contained no extractable files.');
        }
    }

    /**
     * @param string $name
     * @param int    $size
     * @param int    $mtime
     * @return string 512-byte ustar header
     */
    private static function buildUstarHeader($name, $size, $mtime)
    {
        $header = str_pad($name, 100, "\0");
        $header .= sprintf('%07o', 0644) . "\0";
        $header .= sprintf('%07o', 0) . "\0";
        $header .= sprintf('%07o', 0) . "\0";
        $header .= sprintf('%011o', $size) . "\0";
        $header .= sprintf('%011o', $mtime) . "\0";
        $header .= str_repeat(' ', 8);
        $header .= '0';
        $header .= str_repeat("\0", 100);
        $header .= "ustar\0";
        $header .= '00';
        $header .= str_pad('snapshot', 32, "\0");
        $header .= str_pad('snapshot', 32, "\0");
        $header .= str_repeat("\0", 8);
        $header .= str_repeat("\0", 8);
        $header .= str_repeat("\0", 155);
        $header .= str_repeat("\0", 12);
        if (strlen($header) !== 512) {
            throw new RuntimeException('Internal tar header length error.');
        }
        $sum = 0;
        for ($i = 0; $i < 512; $i++) {
            $sum += ord($header[$i]);
        }
        $checksum = sprintf('%06o', $sum) . "\0 ";
        return substr($header, 0, 148) . $checksum . substr($header, 156);
    }

    /**
     * Allow only a single-path-segment member name (no traversal).
     *
     * @param string $name
     * @return bool
     */
    private static function isSafeTarMemberName($name)
    {
        if ($name === '' || $name === '.' || $name === '..') {
            return false;
        }
        if (strpos($name, '/') !== false || strpos($name, '\\') !== false) {
            return false;
        }
        if (strlen($name) > 100) {
            return false;
        }
        return (bool) preg_match('/^[A-Za-z0-9._-]+$/', $name);
    }
}
