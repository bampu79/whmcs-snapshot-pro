<?php
/**
 * WHMCS Snapshot Pro - Filesystem Backup
 *
 * Archives the WHMCS root directory. ZipArchive is preferred so creation does
 * not open a Phar stream while walking the live tree (WHMCS trees contain
 * .phar files; PHP then constructs RecursiveDirectoryIterator with phar://
 * and fails with "Unable to find the wrapper phar"). PharData tar.gz remains
 * a fallback. Restore accepts both .zip and .tar.gz via the staged filename.
 *
 * @package    SnapshotPro
 * @author     bampu79
 * @license    MIT
 */

namespace SnapshotPro;

use PharData;
use ZipArchive;
use RuntimeException;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Class FilesystemBackup
 *
 * Creates and extracts compressed archives of the WHMCS installation tree.
 */
class FilesystemBackup
{
    /** @var string Absolute path to the WHMCS root directory. */
    private $rootPath;

    /** @var string[] Relative directory names/paths to exclude from archives. */
    private $excludes;

    /**
     * FilesystemBackup constructor.
     *
     * @param string   $rootPath Absolute path to the WHMCS root directory.
     * @param string[] $excludes Additional relative paths to exclude.
     */
    public function __construct($rootPath, array $excludes = [])
    {
        $this->rootPath = rtrim($rootPath, DIRECTORY_SEPARATOR);
        // Default exclusions: caches, logs, temp files and our own storage dir.
        $this->excludes = array_merge([
            'templates_c',
            'cache',
            'temp',
            'attachments/tmp',
            'modules/addons/snapshot_pro/storage',
            '.git',
        ], $excludes);
    }

    /**
     * Create a compressed archive of the WHMCS filesystem.
     *
     * @param string $outputPath Absolute path for the resulting .tar.gz file
     *                           (adjusted to .zip when ZipArchive is used).
     *
     * @return array Metadata: ['method' => 'phar'|'zip', 'size' => int, 'files' => int]
     *
     * @throws RuntimeException On failure of the available archive strategy.
     */
    public function archive($outputPath)
    {
        // Prefer ZipArchive: it does not register a phar:// stream over the
        // tree being walked. SnapshotManager records basename() in the
        // manifest; RestoreWizard/extract() already accept .zip and .tar.gz.
        if (class_exists('ZipArchive')) {
            $zipPath = preg_replace('/\.tar\.gz$/', '.zip', $outputPath);
            return $this->archiveWithZip($zipPath);
        }
        if (class_exists('PharData')) {
            return $this->archiveWithPhar($outputPath);
        }
        throw new RuntimeException('Neither ZipArchive nor PharData is available for filesystem backup.');
    }

    /**
     * Archive using ZipArchive.
     *
     * @param string $outputPath Path to the resulting .zip file.
     * @return array Metadata.
     * @throws RuntimeException On failure.
     */
    private function archiveWithZip($outputPath)
    {
        @unlink($outputPath);

        $zip = new ZipArchive();
        if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($outputPath);
            throw new RuntimeException('Unable to create ZIP archive at ' . $outputPath);
        }

        $fileCount = 0;
        try {
            $this->forEachBackupFile($outputPath, function ($path, $localName) use ($zip, &$fileCount) {
                if ($zip->addFile($path, $localName) !== true) {
                    throw new RuntimeException('Unable to add file to ZIP: ' . $localName);
                }
                $fileCount++;
            });
            if ($zip->close() !== true) {
                throw new RuntimeException('Unable to finalize ZIP archive.');
            }
        } catch (\Exception $e) {
            @$zip->close();
            @unlink($outputPath);
            throw new RuntimeException('ZIP archive failed: ' . $e->getMessage(), 0, $e);
        }

        if (!file_exists($outputPath)) {
            throw new RuntimeException('ZIP archive was not produced.');
        }

        return [
            'method' => 'zip',
            'size'   => (int) filesize($outputPath),
            'files'  => $fileCount,
        ];
    }

    /**
     * Archive using PharData (tar) then gzip-compress. Fallback only.
     *
     * @param string $outputPath Path to the resulting .tar.gz file.
     * @return array Metadata.
     * @throws RuntimeException On failure.
     */
    private function archiveWithPhar($outputPath)
    {
        $tarPath = preg_replace('/\.gz$/', '', $outputPath);
        if ($tarPath === $outputPath) {
            $tarPath = $outputPath . '.tar';
        }
        @unlink($tarPath);
        @unlink($outputPath);

        try {
            $phar      = new PharData($tarPath);
            $fileCount = 0;

            $this->forEachBackupFile($tarPath, function ($path, $localName) use ($phar, &$fileCount) {
                $phar->addFile($path, $localName);
                $fileCount++;
            });

            $phar->compress(\Phar::GZ);
            unset($phar);

            $generated = $tarPath . '.gz';
            if (file_exists($generated) && $generated !== $outputPath) {
                @rename($generated, $outputPath);
            }
            @unlink($tarPath);

            if (!file_exists($outputPath)) {
                throw new RuntimeException('Phar archive was not produced.');
            }

            return [
                'method' => 'phar',
                'size'   => (int) filesize($outputPath),
                'files'  => $fileCount,
            ];
        } catch (\Exception $e) {
            @unlink($tarPath);
            @unlink($outputPath);
            throw new RuntimeException('PharData archive failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Stream the WHMCS tree and invoke $callback for each included regular file.
     *
     * Uses opendir/readdir so .phar files are not mounted as phar:// directories
     * and Windows junctions that leave the WHMCS root are not followed.
     *
     * @param string   $skipPath Absolute path of the archive being written.
     * @param callable $callback function(string $absolutePath, string $localName): void
     *
     * @return void
     */
    private function forEachBackupFile($skipPath, callable $callback)
    {
        $rootReal = realpath($this->rootPath);
        if ($rootReal === false) {
            throw new RuntimeException('WHMCS root is not accessible: ' . $this->rootPath);
        }
        $rootNorm = $this->normalizePath($rootReal);
        $skipNorm = $skipPath !== '' ? $this->normalizePath($skipPath) : '';
        $skipReal = $skipPath !== '' ? realpath($skipPath) : false;

        $stack = [$this->rootPath];
        while ($stack !== []) {
            $dir = array_pop($stack);
            $handle = @opendir($dir);
            if ($handle === false) {
                continue;
            }
            while (($name = readdir($handle)) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $path = $dir . DIRECTORY_SEPARATOR . $name;
                if ($this->isSkippedArchivePath($path, $skipNorm, $skipReal)) {
                    continue;
                }
                $localName = $this->localName($path);
                if ($localName === '' || $this->isExcluded($localName)) {
                    continue;
                }
                $resolved = realpath($path);
                if ($resolved === false || !$this->isInsideRoot($resolved, $rootNorm)) {
                    continue;
                }
                // Never opendir/is_dir a .phar path: PHP mounts it as phar://.
                // Still require the same inside-root resolution as every other entry.
                if ($this->isPharNamed($name)) {
                    if (is_file($resolved)) {
                        $callback($path, $localName);
                    }
                    continue;
                }
                if (is_link($path)) {
                    if (is_dir($resolved)) {
                        $stack[] = $path;
                        continue;
                    }
                    if (is_file($resolved) || is_file($path)) {
                        $callback($path, $localName);
                    }
                    continue;
                }
                if (is_dir($path)) {
                    $stack[] = $path;
                    continue;
                }
                if (is_file($path)) {
                    $callback($path, $localName);
                }
            }
            closedir($handle);
        }
    }

    /**
     * Relative archive member name with forward slashes.
     *
     * @param string $path Absolute filesystem path.
     * @return string
     */
    private function localName($path)
    {
        $root = $this->normalizePath($this->rootPath);
        $full = $this->normalizePath($path);
        if (strcasecmp($full, $root) === 0) {
            return '';
        }
        $prefix = $root . '/';
        if (stripos($full, $prefix) === 0) {
            return substr($full, strlen($prefix));
        }
        $relative = ltrim(str_replace($this->rootPath, '', $path), DIRECTORY_SEPARATOR);
        return str_replace('\\', '/', $relative);
    }

    /**
     * @param string $relative Forward-slash relative path.
     * @return bool
     */
    private function isExcluded($relative)
    {
        $relative = str_replace('\\', '/', $relative);
        foreach ($this->excludes as $exclude) {
            $exclude = trim(str_replace('\\', '/', (string) $exclude), '/');
            if ($exclude === '') {
                continue;
            }
            if ($relative === $exclude || strpos($relative . '/', $exclude . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $name Basename.
     * @return bool
     */
    private function isPharNamed($name)
    {
        return (bool) preg_match('/\.phar$/i', $name);
    }

    /**
     * True when $path is the archive currently being written.
     *
     * @param string      $path
     * @param string      $skipNorm
     * @param string|false $skipReal
     * @return bool
     */
    private function isSkippedArchivePath($path, $skipNorm, $skipReal)
    {
        if ($skipNorm !== '' && strcasecmp($this->normalizePath($path), $skipNorm) === 0) {
            return true;
        }
        if ($skipReal === false) {
            return false;
        }
        $real = realpath($path);
        return $real !== false && $real === $skipReal;
    }

    /**
     * @param string $path Absolute path.
     * @param string $rootNorm Normalized real WHMCS root (forward slashes).
     * @return bool
     */
    private function isInsideRoot($path, $rootNorm)
    {
        $norm = $this->normalizePath($path);
        if (strcasecmp($norm, $rootNorm) === 0) {
            return true;
        }
        return stripos($norm, $rootNorm . '/') === 0;
    }

    /**
     * @param string $path
     * @return string
     */
    private function normalizePath($path)
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * Extract a filesystem archive into a target directory.
     *
     * Detects tar.gz vs zip by extension and uses the appropriate extractor.
     *
     * @param string $archivePath Path to the .tar.gz or .zip archive.
     * @param string $targetDir   Directory to extract into.
     *
     * @return bool True on success.
     *
     * @throws RuntimeException On failure.
     */
    public function extract($archivePath, $targetDir)
    {
        if (!is_readable($archivePath)) {
            throw new RuntimeException('Filesystem archive not readable: ' . $archivePath);
        }
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
            throw new RuntimeException('Unable to create extraction target: ' . $targetDir);
        }

        if (preg_match('/\.zip$/i', $archivePath)) {
            $zip = new ZipArchive();
            if ($zip->open($archivePath) !== true) {
                throw new RuntimeException('Unable to open ZIP archive for extraction.');
            }
            try {
                $this->extractZipSafely($zip, $targetDir);
            } finally {
                $zip->close();
            }
            return true;
        }

        try {
            $phar = new PharData($archivePath);
            $phar->extractTo($targetDir, null, true);
            return true;
        } catch (\Exception $e) {
            throw new RuntimeException('tar.gz extraction failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Return the configured list of excluded relative paths.
     *
     * @return string[]
     */
    public function getExcludes()
    {
        return $this->excludes;
    }

    /**
     * Runtime directories WHMCS expects but backups intentionally omit.
     *
     * @return string[]
     */
    public static function requiredRuntimeDirectories()
    {
        return [
            'templates_c',
            'cache',
            'temp',
            'attachments/tmp',
        ];
    }

    /**
     * @param string $relative Forward-slash relative path.
     *
     * @return bool
     */
    public static function isExcludedRelativePath($relative)
    {
        $backup = new self(DIRECTORY_SEPARATOR . 'whmcs');
        return $backup->isExcluded(str_replace('\\', '/', $relative));
    }

    /**
     * Create empty runtime directories required for a usable WHMCS install.
     *
     * @param string $targetRoot Absolute WHMCS root.
     *
     * @return array{ok:bool,prepared:string[],details:array<string,array{path:string,writable:bool}>}
     */
    public static function prepareRuntimeDirectories($targetRoot)
    {
        $details = [];
        $prepared = [];
        $allOk = true;
        foreach (self::requiredRuntimeDirectories() as $relative) {
            $path = rtrim($targetRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $writable = RestoreTarget::ensureWritableDirectory($path);
            $details[$relative] = [
                'path'     => $path,
                'writable' => $writable,
            ];
            if ($writable) {
                $prepared[] = $relative;
            } else {
                $allOk = false;
            }
        }
        return [
            'ok'       => $allOk,
            'prepared' => $prepared,
            'details'  => $details,
        ];
    }

    /**
     * List regular-file members in a filesystem archive.
     *
     * @param string $archivePath
     *
     * @return array<int,array{path:string,size:int,crc?:int}>
     */
    public function listArchiveFileMembers($archivePath)
    {
        if (preg_match('/\.zip$/i', $archivePath)) {
            return $this->listZipFileMembers($archivePath);
        }
        return $this->listPharFileMembers($archivePath);
    }

    /**
     * @param string $archivePath
     * @param string $targetDir
     * @param string[] $relativePaths
     *
     * @return void
     */
    public function extractArchiveMembers($archivePath, $targetDir, array $relativePaths)
    {
        $relativePaths = array_values(array_unique(array_filter($relativePaths)));
        if ($relativePaths === []) {
            return;
        }
        if (preg_match('/\.zip$/i', $archivePath)) {
            $this->extractZipMembers($archivePath, $targetDir, $relativePaths);
            return;
        }
        $this->extractPharMembers($archivePath, $targetDir, $relativePaths);
    }

    /**
     * Verify extracted files against archive metadata; retry recoverable misses once.
     *
     * @param string $archivePath
     * @param string $targetDir
     *
     * @return array{
     *   ok:bool,
     *   expected_count:int,
     *   missing:string[],
     *   size_mismatch:string[],
     *   retried:string[],
     *   still_missing:string[],
     *   still_mismatch:string[]
     * }
     */
    public function verifyAndRepairExtractedArchive($archivePath, $targetDir)
    {
        $members = $this->listArchiveFileMembers($archivePath);
        $rootReal = RestoreTarget::canonicalPath($targetDir, true);
        $check = $this->compareMembersToDisk($members, $rootReal);
        $retried = [];
        $toRetry = array_values(array_unique(array_merge($check['missing'], $check['size_mismatch'])));
        if ($toRetry !== []) {
            $this->extractArchiveMembers($archivePath, $targetDir, $toRetry);
            $retried = $toRetry;
            $check = $this->compareMembersToDisk($members, $rootReal);
        }
        $ok = $check['missing'] === [] && $check['size_mismatch'] === [];
        return [
            'ok'              => $ok,
            'expected_count'  => count($members),
            'missing'         => $check['missing'],
            'size_mismatch'   => $check['size_mismatch'],
            'retried'         => $retried,
            'still_missing'   => $check['missing'],
            'still_mismatch'  => $check['size_mismatch'],
        ];
    }

    /**
     * @param array<int,array{path:string,size:int}> $members
     * @param string $rootReal
     *
     * @return array{missing:string[],size_mismatch:string[]}
     */
    private function compareMembersToDisk(array $members, $rootReal)
    {
        $missing = [];
        $sizeMismatch = [];
        foreach ($members as $member) {
            $relative = $member['path'];
            $dest = $rootReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($dest)) {
                $missing[] = $relative;
                continue;
            }
            if ((int) filesize($dest) !== (int) $member['size']) {
                $sizeMismatch[] = $relative;
            }
        }
        return [
            'missing'       => $missing,
            'size_mismatch' => $sizeMismatch,
        ];
    }

    /**
     * @param string $archivePath
     *
     * @return array<int,array{path:string,size:int,crc?:int}>
     */
    private function listZipFileMembers($archivePath)
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Unable to open ZIP archive for verification.');
        }
        $members = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if ($entryName === false || $entryName === '') {
                continue;
            }
            if (substr(str_replace('\\', '/', $entryName), -1) === '/') {
                continue;
            }
            $relative = RestoreTarget::validateZipEntryName($entryName);
            $stat = $zip->statIndex($i);
            $members[] = [
                'path' => $relative,
                'size' => isset($stat['size']) ? (int) $stat['size'] : 0,
                'crc'  => isset($stat['crc']) ? (int) $stat['crc'] : null,
            ];
        }
        $zip->close();
        return $members;
    }

    /**
     * @param string $archivePath
     *
     * @return array<int,array{path:string,size:int}>
     */
    private function listPharFileMembers($archivePath)
    {
        $phar = new PharData($archivePath);
        $members = [];
        foreach (new \RecursiveIteratorIterator($phar, \RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
            if ($file->isDir()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($phar->getPath()) + 1));
            $relative = ltrim($relative, '/');
            if ($relative === '') {
                continue;
            }
            $members[] = [
                'path' => $relative,
                'size' => (int) $file->getSize(),
            ];
        }
        return $members;
    }

    /**
     * @param string $archivePath
     * @param string $targetDir
     * @param string[] $relativePaths
     *
     * @return void
     */
    private function extractZipMembers($archivePath, $targetDir, array $relativePaths)
    {
        $wanted = array_flip($relativePaths);
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Unable to open ZIP archive for member retry.');
        }
        $rootReal = RestoreTarget::canonicalPath($targetDir, true);
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);
                if ($entryName === false || $entryName === '') {
                    continue;
                }
                if (substr(str_replace('\\', '/', $entryName), -1) === '/') {
                    continue;
                }
                $relative = RestoreTarget::validateZipEntryName($entryName);
                if (!isset($wanted[$relative])) {
                    continue;
                }
                $destPath = RestoreTarget::resolveZipEntryPath($entryName, $rootReal);
                $parent = dirname($destPath);
                if (!is_dir($parent) && !@mkdir($parent, 0755, true)) {
                    throw new RuntimeException('Unable to create parent directory for retry: ' . $relative);
                }
                $contents = $zip->getFromIndex($i);
                if ($contents === false) {
                    throw new RuntimeException('Unable to read ZIP entry for retry: ' . $relative);
                }
                if (file_put_contents($destPath, $contents) === false) {
                    throw new RuntimeException('Unable to write ZIP entry for retry: ' . $relative);
                }
                RestoreTarget::assertMaterializedPathInsideRoot($destPath, $rootReal);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @param string $archivePath
     * @param string $targetDir
     * @param string[] $relativePaths
     *
     * @return void
     */
    private function extractPharMembers($archivePath, $targetDir, array $relativePaths)
    {
        $phar = new PharData($archivePath);
        $rootReal = RestoreTarget::canonicalPath($targetDir, true);
        foreach ($relativePaths as $relative) {
            $relative = str_replace('\\', '/', $relative);
            $contents = file_get_contents('phar://' . $archivePath . '/' . $relative);
            if ($contents === false) {
                throw new RuntimeException('Unable to read archive member for retry: ' . $relative);
            }
            $destPath = $rootReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $parent = dirname($destPath);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true)) {
                throw new RuntimeException('Unable to create parent directory for retry: ' . $relative);
            }
            if (file_put_contents($destPath, $contents) === false) {
                throw new RuntimeException('Unable to write archive member for retry: ' . $relative);
            }
        }
    }

    /**
     * Extract ZIP members one-by-one with zip-slip validation.
     *
     * @param ZipArchive $zip
     * @param string     $targetDir
     * @return void
     */
    private function extractZipSafely(ZipArchive $zip, $targetDir)
    {
        $rootReal = RestoreTarget::canonicalPath($targetDir, true);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if ($entryName === false || $entryName === '') {
                continue;
            }
            $isDir = substr(str_replace('\\', '/', $entryName), -1) === '/';
            $relative = RestoreTarget::validateZipEntryName($entryName);
            $destPath = RestoreTarget::resolveZipEntryPath($entryName, $rootReal);
            if ($isDir) {
                if (!is_dir($destPath) && !@mkdir($destPath, 0755, true)) {
                    throw new RuntimeException('Unable to create ZIP directory: ' . $relative);
                }
                RestoreTarget::assertMaterializedPathInsideRoot($destPath, $rootReal);
                continue;
            }
            $parent = dirname($destPath);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true)) {
                throw new RuntimeException('Unable to create ZIP parent directory for: ' . $relative);
            }
            $contents = $zip->getFromIndex($i);
            if ($contents === false) {
                throw new RuntimeException('Unable to read ZIP entry: ' . $relative);
            }
            if (file_put_contents($destPath, $contents) === false) {
                throw new RuntimeException('Unable to write ZIP entry: ' . $relative);
            }
            RestoreTarget::assertMaterializedPathInsideRoot($destPath, $rootReal);
        }
    }
}
