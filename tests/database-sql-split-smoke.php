<?php
/**
 * Regression tests for DatabaseBackup SQL statement splitting (restore importer).
 *
 * Run: php tests/database-sql-split-smoke.php
 */
declare(strict_types=1);

define('WHMCS', true);

require dirname(__DIR__) . '/modules/addons/snapshot_pro/lib/DatabaseBackup.php';

use SnapshotPro\DatabaseBackup;

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

check('commented table block keeps DROP executable', function () {
    $sql = "-- Structure for table `mod_invoicedata`\n"
        . "DROP TABLE IF EXISTS `mod_invoicedata`;\n"
        . "CREATE TABLE `mod_invoicedata` (`id` int NOT NULL);\n";
    $parts = DatabaseBackup::parseSqlStatements($sql);
    if (count($parts) !== 2) {
        throw new RuntimeException('expected 2 statements, got ' . count($parts));
    }
    if (!DatabaseBackup::sqlStatementHasExecutableSql($parts[0])) {
        throw new RuntimeException('DROP statement was treated as empty');
    }
    if (stripos($parts[0], 'DROP TABLE') === false) {
        throw new RuntimeException('first statement is not DROP');
    }
    if (stripos($parts[1], 'CREATE TABLE') === false) {
        throw new RuntimeException('second statement is not CREATE');
    }
});

check('quoted semicolon stays inside INSERT', function () {
    $sql = "INSERT INTO test_table VALUES ('hello;world');\n"
        . "INSERT INTO test_table VALUES ('ok');\n";
    $parts = DatabaseBackup::parseSqlStatements($sql);
    if (count($parts) !== 2) {
        throw new RuntimeException('expected 2 statements, got ' . count($parts));
    }
    if (strpos($parts[0], 'hello;world') === false) {
        throw new RuntimeException('semicolon was split out of quoted string');
    }
});

check('block comment does not terminate on inner semicolon', function () {
    $sql = "SELECT 1; /* comment ; inside */ SELECT 2;\n";
    $parts = DatabaseBackup::parseSqlStatements($sql);
    if (count($parts) !== 2) {
        throw new RuntimeException('expected 2 statements, got ' . count($parts));
    }
});

$gzPath = getenv('SP_TEST_SQL_GZ') ?: '';
if ($gzPath === '' || !is_file($gzPath)) {
    $candidates = [
        'D:/wamp64/www/hostila/modules/addons/snapshot_pro/storage/snap_20261004_222101_ce56843c.spx',
    ];
    foreach ($candidates as $spx) {
        if (!is_file($spx)) {
            continue;
        }
        echo "SKIP snapshot SQL integration (set SP_TEST_SQL_GZ to extracted database.sql.gz)\n";
        break;
    }
} else {
    check('snapshot dump parses DROP before mod_invoicedata CREATE', function () use ($gzPath) {
        $gz = gzopen($gzPath, 'rb');
        if (!$gz) {
            throw new RuntimeException('cannot open gzip');
        }
        $buffer = '';
        $dropSeen = false;
        $createSeen = false;
        while (!gzeof($gz)) {
            $buffer .= gzread($gz, 262144);
            foreach (DatabaseBackup::extractCompleteSqlStatements($buffer) as $statement) {
                if (!DatabaseBackup::sqlStatementHasExecutableSql($statement)) {
                    continue;
                }
                if (stripos($statement, 'DROP TABLE') !== false && stripos($statement, 'mod_invoicedata') !== false) {
                    $dropSeen = true;
                }
                if (stripos($statement, 'CREATE TABLE') !== false && stripos($statement, 'mod_invoicedata') !== false) {
                    if (!$dropSeen) {
                        throw new RuntimeException('CREATE mod_invoicedata before DROP');
                    }
                    $createSeen = true;
                    break 2;
                }
            }
        }
        gzclose($gz);
        if (!$dropSeen || !$createSeen) {
            throw new RuntimeException('DROP/CREATE mod_invoicedata not found in expected order');
        }
    });
}

if ($failures > 0) {
    echo "SMOKE: FAIL ({$failures})\n";
    exit(1);
}
echo "SMOKE: OK\n";
exit(0);
