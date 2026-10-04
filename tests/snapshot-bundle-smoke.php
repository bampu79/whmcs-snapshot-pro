<?php
/**
 * Portable regression smoke test for SnapshotManager outer ustar bundle I/O.
 *
 * Run from repository root: php tests/snapshot-bundle-smoke.php
 */

declare(strict_types=1);

$repoRoot = dirname(__DIR__);
$managerPath = $repoRoot . DIRECTORY_SEPARATOR . 'modules'
    . DIRECTORY_SEPARATOR . 'addons' . DIRECTORY_SEPARATOR . 'snapshot_pro'
    . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'SnapshotManager.php';

if (!is_file($managerPath)) {
    fwrite(STDERR, "FAIL: SnapshotManager not found at {$managerPath}\n");
    exit(1);
}

if (!defined('WHMCS')) {
    define('WHMCS', true);
}

require $managerPath;

$managerSource = file_get_contents($managerPath);
if (
    $managerSource === false
    || preg_match('/\buse\s+PharData\b/', $managerSource) === 1
    || preg_match('/\bnew\s+PharData\b/', $managerSource) === 1
) {
    fwrite(STDERR, "FAIL: SnapshotManager must not use PharData for outer bundle I/O\n");
    exit(1);
}

/**
 * @return never
 */
function smokeFail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

/**
 * @param string $dir
 */
function wipeDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}

/**
 * @return array{0: list<array{name: string, size: int, pad: int, typeflag: string, magic: string, header_len: int}>, 1: int}
 */
function parseTarMembers(string $tarPath): array
{
    $fh = fopen($tarPath, 'rb');
    if ($fh === false) {
        smokeFail('unable to open tar for parsing');
    }
    $members = [];
    $zeroBlocks = 0;
    try {
        while (!feof($fh)) {
            $header = fread($fh, 512);
            if ($header === false || $header === '') {
                break;
            }
            if (strlen($header) !== 512) {
                smokeFail('truncated tar header while parsing');
            }
            if ($header === str_repeat("\0", 512)) {
                $zeroBlocks++;
                continue;
            }
            $name = rtrim(substr($header, 0, 100), "\0");
            $sizeOctal = rtrim(substr($header, 124, 12), "\0 ");
            $size = (int) octdec($sizeOctal);
            $magic = substr($header, 257, 5);
            $typeflag = $header[156];
            $pad = (512 - ($size % 512)) % 512;
            $members[] = [
                'name' => $name,
                'size' => $size,
                'pad' => $pad,
                'typeflag' => $typeflag,
                'magic' => $magic,
                'header_len' => strlen($header),
                'size_octal' => $sizeOctal,
            ];
            if (fseek($fh, $size + $pad, SEEK_CUR) !== 0) {
                smokeFail('unable to advance tar stream for ' . $name);
            }
        }
    } finally {
        fclose($fh);
    }

    return [$members, $zeroBlocks];
}

/**
 * @param string $header 512-byte ustar header with checksum applied
 */
function verifyUstarChecksum(string $header): void
{
    if (strlen($header) !== 512) {
        smokeFail('buildUstarHeader did not return 512 bytes');
    }
    $forSum = substr($header, 0, 148) . str_repeat(' ', 8) . substr($header, 156);
    $sum = 0;
    for ($i = 0; $i < 512; $i++) {
        $sum += ord($forSum[$i]);
    }
    $expected = sprintf('%06o', $sum) . "\0 ";
    $actual = substr($header, 148, 8);
    if ($actual !== $expected) {
        smokeFail('buildUstarHeader checksum mismatch');
    }
}

/**
 * @param class-string $class
 * @param string       $method
 * @return ReflectionMethod
 */
function reflectStatic(string $class, string $method): ReflectionMethod
{
    $ref = new ReflectionMethod($class, $method);
    $ref->setAccessible(true);

    return $ref;
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'snapshot-bundle-smoke-' . bin2hex(random_bytes(8));
$stage = $base . DIRECTORY_SEPARATOR . 'stage';
$extract = $base . DIRECTORY_SEPARATOR . 'extract';
$tar = $base . DIRECTORY_SEPARATOR . 'bundle.tar';
$emptyStage = $base . DIRECTORY_SEPARATOR . 'empty-stage';
$emptyExtract = $base . DIRECTORY_SEPARATOR . 'empty-extract';
$emptyTar = $base . DIRECTORY_SEPARATOR . 'empty.tar';

try {
    mkdir($stage, 0700, true);
    mkdir($extract, 0700, true);
    mkdir($emptyStage, 0700, true);
    mkdir($emptyExtract, 0700, true);

    $write = reflectStatic(SnapshotPro\SnapshotManager::class, 'writeUstarArchive');
    $extractFn = reflectStatic(SnapshotPro\SnapshotManager::class, 'extractUstarArchive');
    $buildHeader = reflectStatic(SnapshotPro\SnapshotManager::class, 'buildUstarHeader');
    $isSafeName = reflectStatic(SnapshotPro\SnapshotManager::class, 'isSafeTarMemberName');

    $sampleHeader = $buildHeader->invoke(null, 'manifest.json', 55, 1_700_000_000);
    if (strlen($sampleHeader) !== 512) {
        smokeFail('buildUstarHeader length is not 512');
    }
    if (substr($sampleHeader, 257, 5) !== 'ustar') {
        smokeFail('buildUstarHeader missing ustar magic');
    }
    $headerSize = (int) octdec(rtrim(substr($sampleHeader, 124, 12), "\0 "));
    if ($headerSize !== 55) {
        smokeFail('buildUstarHeader size field incorrect');
    }
    verifyUstarChecksum($sampleHeader);

    $zipBytes = "PK\x03\x04" . random_bytes(64) . "\x00\xffbinary";
    file_put_contents($stage . DIRECTORY_SEPARATOR . 'database.sql.gz', gzencode('select 1;'));
    file_put_contents($stage . DIRECTORY_SEPARATOR . 'filesystem.zip', $zipBytes);
    file_put_contents(
        $stage . DIRECTORY_SEPARATOR . 'manifest.json',
        '{"components":{"filesystem":{"file":"filesystem.zip"}}}'
    );

    $inZipHash = hash_file('sha256', $stage . DIRECTORY_SEPARATOR . 'filesystem.zip');
    $inDb = file_get_contents($stage . DIRECTORY_SEPARATOR . 'database.sql.gz');
    $inMan = file_get_contents($stage . DIRECTORY_SEPARATOR . 'manifest.json');
    if ($inZipHash === false || $inDb === false || $inMan === false) {
        smokeFail('unable to read staged fixture inputs');
    }

    $write->invoke(null, $tar, [
        $stage . DIRECTORY_SEPARATOR . 'database.sql.gz',
        $stage . DIRECTORY_SEPARATOR . 'filesystem.zip',
        $stage . DIRECTORY_SEPARATOR . 'manifest.json',
    ]);

    [$members, $zeroBlocks] = parseTarMembers($tar);

    if ($zeroBlocks !== 2) {
        smokeFail("expected exactly two 512-byte zero EOF blocks, got {$zeroBlocks}");
    }

    $tarTail = file_get_contents($tar, false, null, -1024);
    if ($tarTail === false || $tarTail !== str_repeat("\0", 1024)) {
        smokeFail('tar file does not end with two 512-byte zero blocks');
    }

    if (count($members) !== 3) {
        smokeFail('expected three tar members');
    }

    $expectNames = ['database.sql.gz', 'filesystem.zip', 'manifest.json'];
    $memberNames = array_column($members, 'name');
    if ($memberNames !== $expectNames) {
        smokeFail('unexpected tar member names: ' . implode(', ', $memberNames));
    }

    foreach ($members as $m) {
        if ($m['header_len'] !== 512) {
            smokeFail('header length not 512 for ' . $m['name']);
        }
        if ($m['typeflag'] !== '0') {
            smokeFail('typeflag not regular file for ' . $m['name']);
        }
        if ($m['magic'] !== 'ustar') {
            smokeFail('missing ustar magic for ' . $m['name']);
        }
        $srcPath = $stage . DIRECTORY_SEPARATOR . $m['name'];
        $srcSize = filesize($srcPath);
        if ($srcSize === false || $m['size'] !== $srcSize) {
            smokeFail('header size mismatch for ' . $m['name']);
        }
        $expectedPad = (512 - ($srcSize % 512)) % 512;
        if ($m['pad'] !== $expectedPad) {
            smokeFail('padding mismatch for ' . $m['name']);
        }
        if ($m['size_octal'] !== sprintf('%011o', $srcSize)) {
            smokeFail('octal size field mismatch for ' . $m['name']);
        }
    }

    $extractFn->invoke(null, $tar, $extract);
    $names = array_values(array_diff(scandir($extract) ?: [], ['.', '..']));
    sort($names);
    if ($names !== $expectNames) {
        smokeFail('unexpected extracted member names');
    }

    $outZipHash = hash_file('sha256', $extract . DIRECTORY_SEPARATOR . 'filesystem.zip');
    if ($outZipHash !== $inZipHash) {
        smokeFail('filesystem.zip not byte-identical after round trip');
    }
    if (file_get_contents($extract . DIRECTORY_SEPARATOR . 'database.sql.gz') !== $inDb) {
        smokeFail('database.sql.gz contents mismatch');
    }
    if (file_get_contents($extract . DIRECTORY_SEPARATOR . 'manifest.json') !== $inMan) {
        smokeFail('manifest.json contents mismatch');
    }

    file_put_contents($emptyStage . DIRECTORY_SEPARATOR . 'empty.dat', '');
    $write->invoke(null, $emptyTar, [$emptyStage . DIRECTORY_SEPARATOR . 'empty.dat']);
    $extractFn->invoke(null, $emptyTar, $emptyExtract);
    $emptyPath = $emptyExtract . DIRECTORY_SEPARATOR . 'empty.dat';
    if (!is_file($emptyPath) || filesize($emptyPath) !== 0) {
        smokeFail('empty file round trip failed');
    }

    $safeCases = [
        '../x' => false,
        'a/b' => false,
        'a\\b' => false,
        '.' => false,
        '..' => false,
        'filesystem.zip' => true,
        'filesystem.tar.gz' => true,
        'database.sql.gz' => true,
        'manifest.json' => true,
    ];
    foreach ($safeCases as $name => $should) {
        $ok = (bool) $isSafeName->invoke(null, $name);
        if ($ok !== $should) {
            smokeFail('isSafeTarMemberName(' . $name . ') expected ' . ($should ? 'true' : 'false'));
        }
    }

    $longName = str_repeat('a', 101);
    if ($isSafeName->invoke(null, $longName)) {
        smokeFail('isSafeTarMemberName must reject names longer than 100 bytes');
    }

    echo "SMOKE: OK (ustar bundle write/extract, 3 members, EOF blocks, binary zip)\n";
    exit(0);
} catch (Throwable $e) {
    smokeFail($e->getMessage());
} finally {
    wipeDir($base);
}
