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
            if (!$zip->extractTo($targetDir)) {
                $zip->close();
                throw new RuntimeException('ZIP extraction failed.');
            }
            $zip->close();
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
}
