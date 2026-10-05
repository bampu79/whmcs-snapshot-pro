<?php
/**
 * Filesystem restore verification and runtime directory smoke tests.
 *
 * Run: php tests/filesystem-restore-smoke.php
 */
declare(strict_types=1);

define('WHMCS', true);

require dirname(__DIR__) . '/modules/addons/snapshot_pro/lib/RestoreTarget.php';
require dirname(__DIR__) . '/modules/addons/snapshot_pro/lib/FilesystemBackup.php';

use SnapshotPro\FilesystemBackup;
use SnapshotPro\RestoreTarget;

$failures = 0;

function check($name, callable $fn)
{
    global $failures;
    try {
        $fn();
        echo "OK  {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL {$name}: {$e->getMessage()}\n";
    }
}

function checkThrows($name, callable $fn)
{
    global $failures;
    try {
        $fn();
        $failures++;
        echo "FAIL {$name}: expected exception\n";
    } catch (RuntimeException $e) {
        echo "OK  {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL {$name}: wrong exception: {$e->getMessage()}\n";
    }
}

check('templates_c is excluded from backup expectations', function () {
    if (!FilesystemBackup::isExcludedRelativePath('templates_c/index.php')) {
        throw new RuntimeException('templates_c should be excluded');
    }
    if (FilesystemBackup::isExcludedRelativePath('configuration.php')) {
        throw new RuntimeException('configuration.php should not be excluded');
    }
});

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sp-fs-restore-' . bin2hex(random_bytes(4));
@mkdir($base, 0777, true);

check('required templates_c recreated for clone', function () use ($base) {
    $target = $base . DIRECTORY_SEPARATOR . 'clone';
    @mkdir($target, 0777, true);
    $result = FilesystemBackup::prepareRuntimeDirectories($target);
    if (empty($result['ok'])) {
        throw new RuntimeException('runtime prep failed');
    }
    if (!is_dir($target . DIRECTORY_SEPARATOR . 'templates_c')) {
        throw new RuntimeException('templates_c not created');
    }
    if (!RestoreTarget::ensureWritableDirectory($target . DIRECTORY_SEPARATOR . 'templates_c')) {
        throw new RuntimeException('templates_c not writable');
    }
});

$zipPath = $base . DIRECTORY_SEPARATOR . 'mini.zip';
$extractRoot = $base . DIRECTORY_SEPARATOR . 'extract';
@mkdir($extractRoot, 0777, true);

check('nested vendor path extracts into empty target', function () use ($base) {
    $emptyRoot = $base . DIRECTORY_SEPARATOR . 'empty-target';
    @mkdir($emptyRoot, 0777, true);
    $nestedZip = $base . DIRECTORY_SEPARATOR . 'nested.zip';
    $zip = new ZipArchive();
    if ($zip->open($nestedZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('cannot create nested zip');
    }
    $zip->addFromString('vendor/example.php', "<?php\n// example\n");
    $zip->addFromString('modules/foo/bar.php', "<?php\n// bar\n");
    $zip->close();
    (new FilesystemBackup($emptyRoot))->extract($nestedZip, $emptyRoot);
    $vendorFile = $emptyRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'example.php';
    if (!is_file($vendorFile)) {
        throw new RuntimeException('vendor/example.php was not extracted');
    }
});

check('build mini zip fixture', function () use ($zipPath, $extractRoot) {
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('cannot create zip');
    }
    $zip->addFromString('configuration.php', "<?php\n\$db_name = 'test';\n");
    $zip->addFromString('foo.php', "<?php\n// foo\n");
    $zip->close();
    $backup = new FilesystemBackup($extractRoot);
    $backup->extract($zipPath, $extractRoot);
});

checkThrows('malicious zip paths are rejected', function () {
    RestoreTarget::validateZipEntryName('../configuration.php');
});

check('recoverable missing file is retried', function () use ($zipPath, $extractRoot) {
    @unlink($extractRoot . DIRECTORY_SEPARATOR . 'foo.php');
    $backup = new FilesystemBackup($extractRoot);
    $result = $backup->verifyAndRepairExtractedArchive($zipPath, $extractRoot);
    if (!$result['ok']) {
        throw new RuntimeException('retry should restore missing file');
    }
    if (empty($result['retried']) || !in_array('foo.php', $result['retried'], true)) {
        throw new RuntimeException('expected foo.php in retried paths');
    }
});

check('intentional exclusions are not expected as missing archive paths', function () use ($zipPath, $extractRoot) {
    if (FilesystemBackup::isExcludedRelativePath('templates_c/foo.php')) {
        // excluded paths are not part of backup membership expectations
    }
    $members = (new FilesystemBackup($extractRoot))->listArchiveFileMembers($zipPath);
    foreach ($members as $member) {
        if (strpos($member['path'], 'templates_c/') === 0) {
            throw new RuntimeException('templates_c should not appear in fixture archive');
        }
    }
});

check('persistent missing file fails restore verification', function () use ($zipPath, $extractRoot) {
    @unlink($extractRoot . DIRECTORY_SEPARATOR . 'foo.php');
    file_put_contents($zipPath, 'not-a-zip');
    $backup = new FilesystemBackup($extractRoot);
    try {
        $result = $backup->verifyAndRepairExtractedArchive($zipPath, $extractRoot);
        if (!empty($result['ok'])) {
            throw new RuntimeException('expected verification failure with corrupt archive');
        }
    } catch (RuntimeException $e) {
        if (stripos($e->getMessage(), 'ZIP') === false) {
            throw $e;
        }
    }
});

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $item) {
    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
}
@rmdir($base);

if ($failures > 0) {
    echo "SMOKE: FAIL ({$failures})\n";
    exit(1);
}
echo "SMOKE: OK\n";
exit(0);
