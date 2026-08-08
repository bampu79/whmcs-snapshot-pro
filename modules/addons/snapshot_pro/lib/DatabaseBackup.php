<?php
/**
 * WHMCS Snapshot Pro - Database Backup
 *
 * Produces a compressed SQL dump of the WHMCS database. It prefers the native
 * `mysqldump` binary (invoked via exec with a temporary credentials file so
 * the password never appears in the process list) and transparently falls back
 * to a pure-PHP dump built on the WHMCS Capsule query builder when exec() is
 * disabled or mysqldump is unavailable.
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
 * Class DatabaseBackup
 *
 * Creates and restores gzip-compressed SQL dumps of the WHMCS database.
 */
class DatabaseBackup
{
    /** @var array Database connection parameters (host, port, database, username, password). */
    private $dbConfig;

    /**
     * DatabaseBackup constructor.
     *
     * Reads the active connection details from the WHMCS Capsule connection so
     * we always target the same database WHMCS itself uses.
     */
    public function __construct()
    {
        $connection = Capsule::connection();
        $config     = $connection->getConfig();

        $this->dbConfig = [
            'host'     => isset($config['host']) ? $config['host'] : '127.0.0.1',
            'port'     => isset($config['port']) && $config['port'] ? (int) $config['port'] : 3306,
            'database' => isset($config['database']) ? $config['database'] : '',
            'username' => isset($config['username']) ? $config['username'] : '',
            'password' => isset($config['password']) ? $config['password'] : '',
        ];
    }

    /**
     * Create a gzip-compressed SQL dump at the given path.
     *
     * @param string $outputPath Absolute path for the resulting .sql.gz file.
     *
     * @return array Metadata: ['method' => 'mysqldump'|'php', 'size' => int, 'tables' => int]
     *
     * @throws RuntimeException On failure of both dump strategies.
     */
    public function dump($outputPath)
    {
        if ($this->canUseMysqldump()) {
            try {
                return $this->dumpWithMysqldump($outputPath);
            } catch (Exception $e) {
                Logger::warning(
                    'backup.db',
                    'mysqldump failed, falling back to PHP dump: ' . $e->getMessage()
                );
                // Fall through to PHP dump.
            }
        }
        return $this->dumpWithPhp($outputPath);
    }

    /**
     * Determine whether exec() is available and mysqldump can be located.
     *
     * @return bool
     */
    private function canUseMysqldump()
    {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('exec', $disabled, true)) {
            return false;
        }
        // Attempt to locate the binary.
        $binary = $this->locateMysqldump();
        return $binary !== null;
    }

    /**
     * Locate the mysqldump binary on common paths / via `which`.
     *
     * @return string|null Absolute path to mysqldump, or null if not found.
     */
    private function locateMysqldump()
    {
        $candidates = [
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/usr/local/mysql/bin/mysqldump',
            '/opt/cpanel/mysql/current/bin/mysqldump',
        ];
        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }
        // Try `which` as a last resort.
        $which = @exec('command -v mysqldump 2>/dev/null');
        if (is_string($which) && $which !== '' && is_executable($which)) {
            return $which;
        }
        return null;
    }

    /**
     * Dump the database using the mysqldump binary.
     *
     * Credentials are passed through a temporary defaults-extra-file (chmod 600)
     * so the password is never exposed on the command line / process list.
     *
     * @param string $outputPath Path to the resulting .sql.gz file.
     *
     * @return array Dump metadata.
     *
     * @throws RuntimeException On command failure.
     */
    private function dumpWithMysqldump($outputPath)
    {
        $binary = $this->locateMysqldump();
        if ($binary === null) {
            throw new RuntimeException('mysqldump binary not found.');
        }

        // Write a temporary MySQL option file to hold credentials securely.
        $defaultsFile = tempnam(sys_get_temp_dir(), 'sp_my_');
        if ($defaultsFile === false) {
            throw new RuntimeException('Unable to create temporary credentials file.');
        }
        $ini = "[client]\n"
            . 'user=' . $this->dbConfig['username'] . "\n"
            . 'password="' . str_replace('"', '\"', $this->dbConfig['password']) . "\"\n"
            . 'host=' . $this->dbConfig['host'] . "\n"
            . 'port=' . $this->dbConfig['port'] . "\n";
        file_put_contents($defaultsFile, $ini);
        @chmod($defaultsFile, 0600);

        // A raw (uncompressed) intermediate dump path.
        $rawDump = $outputPath . '.raw';

        $cmd = escapeshellarg($binary)
            . ' --defaults-extra-file=' . escapeshellarg($defaultsFile)
            . ' --single-transaction --quick --routines --triggers --events'
            . ' --default-character-set=utf8mb4 --no-tablespaces'
            . ' ' . escapeshellarg($this->dbConfig['database'])
            . ' > ' . escapeshellarg($rawDump)
            . ' 2> ' . escapeshellarg($rawDump . '.err');

        $returnVar = 0;
        $output    = [];
        @exec($cmd, $output, $returnVar);

        $errText = @file_get_contents($rawDump . '.err');
        @unlink($defaultsFile);
        @unlink($rawDump . '.err');

        if ($returnVar !== 0 || !file_exists($rawDump) || filesize($rawDump) === 0) {
            @unlink($rawDump);
            throw new RuntimeException('mysqldump exited with code ' . $returnVar . ': ' . trim((string) $errText));
        }

        // Gzip-compress the raw dump into the final output path.
        $this->gzipFile($rawDump, $outputPath);
        @unlink($rawDump);

        return [
            'method' => 'mysqldump',
            'size'   => (int) filesize($outputPath),
            'tables' => $this->countTables(),
        ];
    }

    /**
     * Pure-PHP database dump fallback using the query builder.
     *
     * Builds standard SQL statements for CREATE TABLE and INSERT rows and writes
     * them straight into a gzip stream. Suitable when exec() is disabled.
     *
     * @param string $outputPath Path to the resulting .sql.gz file.
     *
     * @return array Dump metadata.
     *
     * @throws RuntimeException On write failure.
     */
    private function dumpWithPhp($outputPath)
    {
        $gz = gzopen($outputPath, 'wb9');
        if (!$gz) {
            throw new RuntimeException('Unable to open gzip stream for PHP database dump.');
        }

        $pdo = Capsule::connection()->getPdo();

        $header = "-- WHMCS Snapshot Pro PHP database dump\n"
            . '-- Database: ' . $this->dbConfig['database'] . "\n"
            . '-- Generated: ' . date('Y-m-d H:i:s') . "\n"
            . "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n";
        gzwrite($gz, $header);

        $tables    = $this->listTables();
        $tableCount = 0;

        foreach ($tables as $table) {
            $tableCount++;
            // CREATE TABLE statement.
            $createRow = Capsule::select('SHOW CREATE TABLE `' . str_replace('`', '', $table) . '`');
            if (!empty($createRow)) {
                $createArr = (array) $createRow[0];
                $createSql = isset($createArr['Create Table']) ? $createArr['Create Table'] : end($createArr);
                gzwrite($gz, "\n-- Structure for table `{$table}`\n");
                gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n");
                gzwrite($gz, $createSql . ";\n\n");
            }

            // Data rows, streamed in batches to limit memory usage.
            gzwrite($gz, "-- Data for table `{$table}`\n");
            $offset    = 0;
            $batchSize = 500;
            do {
                $rows = Capsule::table($table)->offset($offset)->limit($batchSize)->get();
                $count = 0;
                foreach ($rows as $row) {
                    $rowArr = (array) $row;
                    $columns = array_map(function ($c) {
                        return '`' . str_replace('`', '', $c) . '`';
                    }, array_keys($rowArr));
                    $values = array_map(function ($v) use ($pdo) {
                        if ($v === null) {
                            return 'NULL';
                        }
                        return $pdo->quote((string) $v);
                    }, array_values($rowArr));
                    gzwrite(
                        $gz,
                        'INSERT INTO `' . $table . '` (' . implode(',', $columns) . ') VALUES ('
                        . implode(',', $values) . ");\n"
                    );
                    $count++;
                }
                $offset += $batchSize;
            } while ($count === $batchSize);
            gzwrite($gz, "\n");
        }

        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);

        return [
            'method' => 'php',
            'size'   => (int) filesize($outputPath),
            'tables' => $tableCount,
        ];
    }

    /**
     * Restore a gzip-compressed SQL dump back into the database.
     *
     * Prefers the mysql CLI client when available, otherwise executes the
     * statements through PDO in a transaction-friendly manner.
     *
     * @param string $dumpPath Path to the .sql.gz file to restore.
     *
     * @return bool True on success.
     *
     * @throws RuntimeException On failure.
     */
    public function restore($dumpPath)
    {
        if (!is_readable($dumpPath)) {
            throw new RuntimeException('Database dump not readable: ' . $dumpPath);
        }

        if ($this->canUseMysqlClient()) {
            return $this->restoreWithMysqlClient($dumpPath);
        }
        return $this->restoreWithPhp($dumpPath);
    }

    /**
     * Check whether the mysql client binary is usable via exec().
     *
     * @return bool
     */
    private function canUseMysqlClient()
    {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('exec', $disabled, true)) {
            return false;
        }
        $which = @exec('command -v mysql 2>/dev/null');
        return is_string($which) && $which !== '' && is_executable($which);
    }

    /**
     * Restore a dump using the mysql CLI client.
     *
     * @param string $dumpPath Path to the .sql.gz file.
     * @return bool
     * @throws RuntimeException On failure.
     */
    private function restoreWithMysqlClient($dumpPath)
    {
        $mysql = @exec('command -v mysql 2>/dev/null');

        // Decompress to a temporary raw SQL file first.
        $rawSql = $dumpPath . '.restore.sql';
        $this->gunzipFile($dumpPath, $rawSql);

        $defaultsFile = tempnam(sys_get_temp_dir(), 'sp_my_');
        $ini = "[client]\n"
            . 'user=' . $this->dbConfig['username'] . "\n"
            . 'password="' . str_replace('"', '\"', $this->dbConfig['password']) . "\"\n"
            . 'host=' . $this->dbConfig['host'] . "\n"
            . 'port=' . $this->dbConfig['port'] . "\n";
        file_put_contents($defaultsFile, $ini);
        @chmod($defaultsFile, 0600);

        $cmd = escapeshellarg($mysql)
            . ' --defaults-extra-file=' . escapeshellarg($defaultsFile)
            . ' ' . escapeshellarg($this->dbConfig['database'])
            . ' < ' . escapeshellarg($rawSql)
            . ' 2> ' . escapeshellarg($rawSql . '.err');

        $returnVar = 0;
        @exec($cmd, $out, $returnVar);
        $errText = @file_get_contents($rawSql . '.err');

        @unlink($defaultsFile);
        @unlink($rawSql);
        @unlink($rawSql . '.err');

        if ($returnVar !== 0) {
            throw new RuntimeException('mysql restore failed: ' . trim((string) $errText));
        }
        return true;
    }

    /**
     * Restore a dump by executing statements through PDO.
     *
     * @param string $dumpPath Path to the .sql.gz file.
     * @return bool
     * @throws RuntimeException On failure.
     */
    private function restoreWithPhp($dumpPath)
    {
        $gz = gzopen($dumpPath, 'rb');
        if (!$gz) {
            throw new RuntimeException('Unable to open gzip dump for restore.');
        }
        $pdo = Capsule::connection()->getPdo();

        $buffer = '';
        try {
            while (!gzeof($gz)) {
                $buffer .= gzread($gz, 262144);
                // Execute complete statements terminated by ";\n".
                while (($pos = strpos($buffer, ";\n")) !== false) {
                    $statement = substr($buffer, 0, $pos + 1);
                    $buffer    = substr($buffer, $pos + 2);
                    $trimmed   = trim($statement);
                    if ($trimmed === '' || strpos($trimmed, '--') === 0) {
                        continue;
                    }
                    $pdo->exec($statement);
                }
            }
            // Any trailing statement without newline terminator.
            $trailing = trim($buffer);
            if ($trailing !== '' && strpos($trailing, '--') !== 0) {
                $pdo->exec($trailing);
            }
        } catch (Exception $e) {
            gzclose($gz);
            throw new RuntimeException('PHP restore failed: ' . $e->getMessage());
        }
        gzclose($gz);
        return true;
    }

    /**
     * List all base tables in the current database.
     *
     * @return string[] Array of table names.
     */
    private function listTables()
    {
        $tables = [];
        $rows   = Capsule::select('SHOW FULL TABLES WHERE Table_type = ?', ['BASE TABLE']);
        foreach ($rows as $row) {
            $vals     = array_values((array) $row);
            $tables[] = $vals[0];
        }
        return $tables;
    }

    /**
     * Count the number of base tables in the database.
     *
     * @return int
     */
    private function countTables()
    {
        try {
            return count($this->listTables());
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Gzip-compress a source file into a destination file (streamed).
     *
     * @param string $source      Path to plaintext file.
     * @param string $destination Path to gzip output.
     * @return void
     * @throws RuntimeException On I/O failure.
     */
    private function gzipFile($source, $destination)
    {
        $in  = @fopen($source, 'rb');
        $out = @gzopen($destination, 'wb9');
        if (!$in || !$out) {
            if ($in) { fclose($in); }
            if ($out) { gzclose($out); }
            throw new RuntimeException('Unable to gzip database dump.');
        }
        while (!feof($in)) {
            gzwrite($out, fread($in, 262144));
        }
        fclose($in);
        gzclose($out);
    }

    /**
     * Decompress a gzip file into a destination file (streamed).
     *
     * @param string $source      Path to gzip file.
     * @param string $destination Path to plaintext output.
     * @return void
     * @throws RuntimeException On I/O failure.
     */
    private function gunzipFile($source, $destination)
    {
        $in  = @gzopen($source, 'rb');
        $out = @fopen($destination, 'wb');
        if (!$in || !$out) {
            if ($in) { gzclose($in); }
            if ($out) { fclose($out); }
            throw new RuntimeException('Unable to gunzip database dump.');
        }
        while (!gzeof($in)) {
            fwrite($out, gzread($in, 262144));
        }
        gzclose($in);
        fclose($out);
    }
}
