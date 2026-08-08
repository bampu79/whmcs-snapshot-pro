<?php
/**
 * WHMCS Snapshot Pro - Filesystem Backup
 *
 * Archives the WHMCS root directory into a compressed tar.gz (via PharData)
 * with a ZipArchive fallback. Cache, logs, temporary directories and the
 * module's own backup storage are excluded to keep archives lean and to avoid
 * recursively backing up the backups.
 *
 * @package    SnapshotPro
 * @author     bampu79
 * @license    MIT
 */

namespace SnapshotPro;

use PharData;
use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use RecursiveCallbackFilterIterator;
use SplFileInfo;
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
     * @param string $outputPath Absolute path for the resulting .tar.gz file.
     *
     * @return array Metadata: ['method' => 'phar'|'zip', 'size' => int, 'files' => int]
     *
     * @throws RuntimeException On failure of the available archive strategy.
     */
    public function archive($outputPath)
    {
        // NOTE: PharData (tar/zip *data* archives) is NOT affected by the
        // php.ini "phar.readonly" directive - that setting only blocks creating
        // executable .phar archives. So we prefer PharData whenever the class
        // exists, and only fall back to ZipArchive when PharData is missing.
        if (class_exists('PharData')) {
            return $this->archiveWithPhar($outputPath);
        }
        if (class_exists('ZipArchive')) {
            // Zip fallback writes a .zip; adjust the caller-provided extension.
            $zipPath = preg_replace('/\.tar\.gz$/', '.zip', $outputPath);
            return $this->archiveWithZip($zipPath);
        }
        throw new RuntimeException('Neither PharData nor ZipArchive is available for filesystem backup.');
    }

    /**
     * Build the recursive, filtered file iterator honouring exclusions.
     *
     * @return RecursiveIteratorIterator<SplFileInfo>
     */
    private function buildIterator()
    {
        $rootPath = $this->rootPath;
        $excludes = $this->excludes;

        $directoryIterator = new RecursiveDirectoryIterator(
            $rootPath,
            RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS
        );

        $filter = new RecursiveCallbackFilterIterator(
            $directoryIterator,
            function (SplFileInfo $current) use ($rootPath, $excludes) {
                $relative = ltrim(str_replace($rootPath, '', $current->getPathname()), DIRECTORY_SEPARATOR);
                $relative = str_replace('\\', '/', $relative);
                foreach ($excludes as $exclude) {
                    $exclude = trim($exclude, '/');
                    if ($exclude === '') {
                        continue;
                    }
                    if ($relative === $exclude || strpos($relative . '/', $exclude . '/') === 0) {
                        return false;
                    }
                }
                return true;
            }
        );

        return new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY);
    }

    /**
     * Archive using PharData (tar) then gzip-compress.
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
            $phar     = new PharData($tarPath);
            $iterator = $this->buildIterator();
            $fileCount = 0;

            foreach ($iterator as $fileInfo) {
                /** @var SplFileInfo $fileInfo */
                if (!$fileInfo->isFile()) {
                    continue;
                }
                $localName = ltrim(
                    str_replace($this->rootPath, '', $fileInfo->getPathname()),
                    DIRECTORY_SEPARATOR
                );
                $phar->addFile($fileInfo->getPathname(), $localName);
                $fileCount++;
            }

            // Compress the whole tar to gzip. PharData::compress creates .tar.gz.
            $phar->compress(\Phar::GZ);
            unset($phar);

            // PharData::compress writes alongside with .tar.gz; ensure final path.
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
            throw new RuntimeException('PharData archive failed: ' . $e->getMessage());
        }
    }

    /**
     * Archive using ZipArchive as a fallback.
     *
     * @param string $outputPath Path to the resulting .zip file.
     * @return array Metadata.
     * @throws RuntimeException On failure.
     */
    private function archiveWithZip($outputPath)
    {
        $zip = new ZipArchive();
        if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create ZIP archive at ' . $outputPath);
        }

        $iterator  = $this->buildIterator();
        $fileCount = 0;
        foreach ($iterator as $fileInfo) {
            /** @var SplFileInfo $fileInfo */
            if (!$fileInfo->isFile()) {
                continue;
            }
            $localName = ltrim(
                str_replace($this->rootPath, '', $fileInfo->getPathname()),
                DIRECTORY_SEPARATOR
            );
            $zip->addFile($fileInfo->getPathname(), $localName);
            $fileCount++;
        }
        $zip->close();

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

        // Assume tar.gz.
        try {
            $phar = new PharData($archivePath);
            $phar->extractTo($targetDir, null, true);
            return true;
        } catch (\Exception $e) {
            throw new RuntimeException('tar.gz extraction failed: ' . $e->getMessage());
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
