<?php
/**
 * Focused smoke tests for RestoreTarget validation and clone safeguards.
 *
 * Run from repository root: php tests/restore-target-smoke.php
 */

declare(strict_types=1);

define('WHMCS', true);

$repoRoot = dirname(__DIR__);
require $repoRoot . '/modules/addons/snapshot_pro/lib/RestoreTarget.php';

use SnapshotPro\RestoreTarget;

$failures = 0;

/**
 * @param string $name
 * @param callable $fn
 */
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

/**
 * @param string $name
 * @param callable $fn
 */
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

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sp-restore-target-' . bin2hex(random_bytes(4));
$live = $base . DIRECTORY_SEPARATOR . 'live';
$test = $base . DIRECTORY_SEPARATOR . 'test-empty';
$inside = $base . DIRECTORY_SEPARATOR . 'live' . DIRECTORY_SEPARATOR . 'nested-test';
@mkdir($live, 0777, true);
@mkdir($test, 0777, true);
@mkdir($inside, 0777, true);

$liveCanon = RestoreTarget::canonicalPath($live, true);

check('A production target remains production', function () use ($liveCanon) {
    $target = RestoreTarget::production($liveCanon);
    if (!$target->isProduction() || $target->getFilesystemPath() !== $liveCanon) {
        throw new RuntimeException('production target mismatch');
    }
});

checkThrows('B test target equal to live root rejected', function () use ($liveCanon) {
    RestoreTarget::assertTestFilesystemPath($liveCanon, $liveCanon);
});

checkThrows('C test target inside live root rejected', function () use ($inside, $liveCanon) {
    RestoreTarget::assertTestFilesystemPath($inside, $liveCanon);
});

check('host localhost accepted', function () {
    RestoreTarget::assertValidTestDbHost('localhost');
});

check('host 127.0.0.1 accepted', function () {
    RestoreTarget::assertValidTestDbHost('127.0.0.1');
});

check('host normal hostname accepted', function () {
    RestoreTarget::assertValidTestDbHost('db.example.com');
});

checkThrows('host LF rejected', function () {
    RestoreTarget::assertValidTestDbHost("db.exam\nple.com");
});

checkThrows('host CR rejected', function () {
    RestoreTarget::assertValidTestDbHost("127.0.0.1\r");
});

checkThrows('host semicolon rejected', function () {
    RestoreTarget::assertValidTestDbHost('127.0.0.1;evil');
});

checkThrows('user LF rejected', function () {
    RestoreTarget::assertValidTestDbUser("clone_user\n");
});

checkThrows('user CR rejected', function () {
    RestoreTarget::assertValidTestDbUser("clone_user\r");
});

checkThrows('user semicolon rejected', function () {
    RestoreTarget::assertValidTestDbUser('user;DROP');
});

checkThrows('invalid port rejected', function () {
    RestoreTarget::assertValidDbPort(0);
});

checkThrows('invalid port high rejected', function () {
    RestoreTarget::assertValidDbPort(70000);
});

check('valid port accepted', function () {
    RestoreTarget::assertValidDbPort(3306);
});

checkThrows('E test DB equal to live DB rejected', function () {
    RestoreTarget::assertTestDatabaseName('hostila_bkend', 'hostila_bkend');
});

check('K valid empty test target accepted', function () use ($test, $liveCanon) {
    RestoreTarget::assertTestFilesystemPath($test, $liveCanon);
});

checkThrows('H ZIP ../ traversal rejected', function () {
    RestoreTarget::validateZipEntryName('../configuration.php');
});

checkThrows('I ZIP absolute path rejected', function () {
    RestoreTarget::validateZipEntryName('/etc/passwd');
});

checkThrows('J ZIP drive-letter path rejected', function () {
    RestoreTarget::validateZipEntryName('C:/windows/system32/evil.dll');
});

check('valid ZIP entry accepted', function () use ($liveCanon) {
    $relative = RestoreTarget::validateZipEntryName('configuration.php');
    if ($relative !== 'configuration.php') {
        throw new RuntimeException('unexpected relative path');
    }
    RestoreTarget::resolveZipEntryPath('configuration.php', $liveCanon);
});

$configSample = $live . DIRECTORY_SEPARATOR . 'configuration.php';
file_put_contents($configSample, "<?php\n\$db_host = 'localhost';\n\$db_username = 'old';\n\$db_password = 'old';\n\$db_name = 'old_db';\n");

check('L configuration.php patched to test DB', function () use ($configSample) {
    RestoreTarget::patchConfigurationPhp($configSample, [
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'hostila_restore_test',
        'username' => 'clone_user',
        'password' => 'clone_pass',
    ]);
    $content = file_get_contents($configSample);
    if (strpos($content, "'hostila_restore_test'") === false || strpos($content, "'clone_user'") === false) {
        throw new RuntimeException('configuration.php not patched');
    }
    if (strpos($content, 'clone_pass') === false) {
        throw new RuntimeException('configuration.php password not patched');
    }
});

// Optional DB-backed checks (F/G/M) — skip when MySQL is unavailable.
$dbHost = getenv('SP_TEST_DB_HOST') ?: '127.0.0.1';
$dbUser = getenv('SP_TEST_DB_USER') ?: 'root';
$dbPass = getenv('SP_TEST_DB_PASS') ?: '';
$liveDb = getenv('SP_TEST_DB_LIVE') ?: 'sp_restore_live_missing';
$emptyDb = getenv('SP_TEST_DB_EMPTY') ?: 'sp_restore_empty_test';
$fullDb = getenv('SP_TEST_DB_FULL') ?: 'sp_restore_full_test';

try {
    $pdo = new PDO('mysql:host=' . $dbHost . ';charset=utf8mb4', $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $emptyDb) . '`');
    $pdo->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $fullDb) . '`');
    $pdo->exec('CREATE DATABASE `' . str_replace('`', '``', $fullDb) . '`');
    $pdo->exec('USE `' . str_replace('`', '``', $fullDb) . '`');
    $pdo->exec('CREATE TABLE smoke_demo (id INT PRIMARY KEY)');

    checkThrows('F missing test DB rejected', function () use ($dbHost, $dbUser, $dbPass) {
        RestoreTarget::assertTestDatabaseReady([
            'host' => $dbHost,
            'port' => 3306,
            'database' => 'sp_restore_missing_' . bin2hex(random_bytes(2)),
            'username' => $dbUser,
            'password' => $dbPass,
        ]);
    });

    check('empty test DB accepted', function () use ($dbHost, $dbUser, $dbPass, $emptyDb) {
        RestoreTarget::assertTestDatabaseReady([
            'host' => $dbHost,
            'port' => 3306,
            'database' => $emptyDb,
            'username' => $dbUser,
            'password' => $dbPass,
        ]);
    });

    checkThrows('G non-empty test DB rejected', function () use ($dbHost, $dbUser, $dbPass, $fullDb) {
        RestoreTarget::assertTestDatabaseReady([
            'host' => $dbHost,
            'port' => 3306,
            'database' => $fullDb,
            'username' => $dbUser,
            'password' => $dbPass,
        ]);
    });

    $pdo->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $fullDb) . '`');
} catch (Throwable $e) {
    echo "SKIP DB tests (F/G/M): {$e->getMessage()}\n";
}

// Cleanup
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
