<?php
/**
 * WHMCS Snapshot Pro - Restore target (production vs test/clone).
 *
 * @package    SnapshotPro
 * @author     bampu79
 * @license    MIT
 */

namespace SnapshotPro;

use WHMCS\Database\Capsule;
use RuntimeException;
use Exception;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Validated restore destination for the guided restore wizard.
 */
class RestoreTarget
{
    const MODE_PRODUCTION = 'production';
    const MODE_TEST       = 'test';

    /** @var string */
    private $mode;

    /** @var string Live WHMCS root (canonical when resolvable). */
    private $liveRoot;

    /** @var string|null Test filesystem root. */
    private $filesystemPath;

    /** @var string|null */
    private $testUrl;

    /** @var array|null host, port, database, username, password */
    private $databaseConfig;

    /**
     * @param string $liveRoot
     * @return self
     */
    public static function production($liveRoot)
    {
        $target = new self();
        $target->mode = self::MODE_PRODUCTION;
        $target->liveRoot = $target->canonicalPath($liveRoot, true);
        return $target;
    }

    /**
     * Build and validate a restore target from an HTTP request payload.
     *
     * @param array  $request
     * @param string $liveRoot
     * @return self
     */
    public static function fromRequest(array $request, $liveRoot)
    {
        $mode = isset($request['restore_mode']) ? (string) $request['restore_mode'] : self::MODE_PRODUCTION;
        if ($mode !== self::MODE_PRODUCTION && $mode !== self::MODE_TEST) {
            throw new RuntimeException('Unknown restore mode.');
        }

        if ($mode === self::MODE_PRODUCTION) {
            return self::production($liveRoot);
        }

        $liveDb = self::liveDatabaseConfig();
        $fsPath = trim((string) ($request['test_filesystem_path'] ?? ''));
        $dbName = trim((string) ($request['test_db_name'] ?? ''));
        $dbHost = trim((string) ($request['test_db_host'] ?? ''));
        $dbUser = trim((string) ($request['test_db_user'] ?? ''));
        $dbPass = (string) ($request['test_db_password'] ?? '');
        $testUrl = trim((string) ($request['test_base_url'] ?? ''));

        if ($fsPath === '' || $dbName === '' || $dbHost === '' || $dbUser === '' || $testUrl === '') {
            throw new RuntimeException('Test restore requires filesystem path, database settings, and test base URL.');
        }

        if (!filter_var($testUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Test base URL is not a valid URL.');
        }

        $parsedUrl = parse_url($testUrl);
        if (empty($parsedUrl['scheme']) || empty($parsedUrl['host'])) {
            throw new RuntimeException('Test base URL must include a scheme and host.');
        }

        self::assertTestFilesystemPath($fsPath, $liveRoot);
        self::assertTestDatabaseName($dbName, $liveDb['database']);
        self::assertValidTestDbHost($dbHost);
        self::assertValidTestDbUser($dbUser);

        $port = isset($liveDb['port']) && $liveDb['port'] ? (int) $liveDb['port'] : 3306;
        if (isset($request['test_db_port']) && trim((string) $request['test_db_port']) !== '') {
            if (!preg_match('/^\d+$/', trim((string) $request['test_db_port']))) {
                throw new RuntimeException('Test database port must be a number.');
            }
            $port = (int) trim((string) $request['test_db_port']);
        }
        self::assertValidDbPort($port);

        $target = new self();
        $target->mode = self::MODE_TEST;
        $target->liveRoot = self::production($liveRoot)->getLiveRoot();
        $target->filesystemPath = self::canonicalPath($fsPath, true);
        $target->testUrl = rtrim($testUrl, '/') . '/';
        $target->databaseConfig = [
            'host'     => $dbHost,
            'port'     => $port,
            'database' => $dbName,
            'username' => $dbUser,
            'password' => $dbPass,
        ];

        self::assertTestDatabaseReady($target->databaseConfig);

        return $target;
    }

    /**
     * @return bool
     */
    public function isTest()
    {
        return $this->mode === self::MODE_TEST;
    }

    /**
     * @return bool
     */
    public function isProduction()
    {
        return $this->mode === self::MODE_PRODUCTION;
    }

    /**
     * @return string production|test
     */
    public function getMode()
    {
        return $this->mode;
    }

    /**
     * @return string
     */
    public function getLiveRoot()
    {
        return $this->liveRoot;
    }

    /**
     * Filesystem path to extract into.
     *
     * @return string
     */
    public function getFilesystemPath()
    {
        if ($this->isTest()) {
            return (string) $this->filesystemPath;
        }
        return $this->liveRoot;
    }

    /**
     * @return string|null
     */
    public function getTestUrl()
    {
        return $this->testUrl;
    }

    /**
     * @return array|null Target DB config for test mode; null for production (Capsule).
     */
    public function getDatabaseConfig()
    {
        if ($this->isProduction()) {
            return null;
        }
        return $this->databaseConfig;
    }

    /**
     * @return array{host:string,port:int,database:string,username:string,password:string}
     */
    public static function liveDatabaseConfig()
    {
        $connection = Capsule::connection();
        $config     = $connection->getConfig();
        return [
            'host'     => isset($config['host']) ? (string) $config['host'] : '127.0.0.1',
            'port'     => isset($config['port']) && $config['port'] ? (int) $config['port'] : 3306,
            'database' => isset($config['database']) ? (string) $config['database'] : '',
            'username' => isset($config['username']) ? (string) $config['username'] : '',
            'password' => isset($config['password']) ? (string) $config['password'] : '',
        ];
    }

    /**
     * Validate a ZIP member name and return a safe relative path (forward slashes).
     *
     * @param string $entryName
     * @return string
     */
    public static function validateZipEntryName($entryName)
    {
        $name = rtrim(str_replace('\\', '/', (string) $entryName), '/');
        if ($name === '' || $name === '/' || $name === '.') {
            throw new RuntimeException('Invalid ZIP entry name.');
        }
        if (preg_match('/^[a-zA-Z]:[\/]/', $name) || preg_match('/^\/\//', $name)) {
            throw new RuntimeException('ZIP entry uses a forbidden absolute path: ' . $entryName);
        }
        if ($name[0] === '/') {
            throw new RuntimeException('ZIP entry uses a forbidden absolute path: ' . $entryName);
        }
        $parts = explode('/', $name);
        $safe  = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new RuntimeException('ZIP entry escapes the destination: ' . $entryName);
            }
            $safe[] = $part;
        }
        if ($safe === []) {
            throw new RuntimeException('Invalid ZIP entry name.');
        }
        return implode('/', $safe);
    }

    /**
     * Resolve a ZIP entry to an absolute path that must remain inside $targetRoot.
     *
     * @param string $entryName
     * @param string $targetRoot
     * @return string Absolute filesystem path for extraction.
     */
    public static function resolveZipEntryPath($entryName, $targetRoot)
    {
        $relative = self::validateZipEntryName($entryName);
        $rootReal = self::canonicalPath($targetRoot, true);
        $dest     = $rootReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!self::pathIsInsideRoot($dest, $rootReal)) {
            throw new RuntimeException('ZIP entry escapes the destination: ' . $entryName);
        }
        return $dest;
    }

    /**
     * After materializing a path, confirm its canonical location is still under $targetRoot.
     *
     * @param string $absolutePath File or directory that now exists.
     * @param string $targetRoot   Trusted extraction root (must resolve).
     *
     * @return void
     */
    public static function assertMaterializedPathInsideRoot($absolutePath, $targetRoot)
    {
        $rootReal = self::canonicalPath($targetRoot, true);
        $real     = realpath($absolutePath);
        if ($real === false) {
            throw new RuntimeException('Path does not resolve after extraction: ' . $absolutePath);
        }
        if (!self::pathIsInsideRoot($real, $rootReal)) {
            throw new RuntimeException('ZIP entry escapes the destination after materialization.');
        }
    }

    /**
     * @param string $testPath
     * @param string $liveRoot
     * @return void
     */
    public static function assertTestFilesystemPath($testPath, $liveRoot)
    {
        $liveCanon = self::canonicalPath($liveRoot, true);
        if (!is_dir($testPath)) {
            if (!@mkdir($testPath, 0755, true) && !is_dir($testPath)) {
                throw new RuntimeException('Unable to create test restore directory.');
            }
        }
        $testCanon = self::canonicalPath($testPath, true);
        if ($testCanon === $liveCanon) {
            throw new RuntimeException('Test restore path must not be the live WHMCS root.');
        }
        if (self::pathIsInsideRoot($testCanon, $liveCanon)) {
            throw new RuntimeException('Test restore path must not be inside the live WHMCS root.');
        }
        if (self::pathIsInsideRoot($liveCanon, $testCanon)) {
            throw new RuntimeException('Test restore path must not contain the live WHMCS root.');
        }
        if (!self::isDirectoryEmpty($testCanon)) {
            throw new RuntimeException('Test restore directory must be empty.');
        }
    }

    /**
     * @param string $dbName
     * @param string $liveDbName
     * @return void
     */
    public static function assertTestDatabaseName($dbName, $liveDbName)
    {
        if ($dbName === '' || $liveDbName === '') {
            throw new RuntimeException('Invalid database name.');
        }
        if (strcasecmp($dbName, $liveDbName) === 0) {
            throw new RuntimeException('Test restore database must not be the live WHMCS database.');
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
            throw new RuntimeException('Test database name contains invalid characters.');
        }
    }

    /**
     * @param string $host
     * @return void
     */
    public static function assertValidTestDbHost($host)
    {
        if ($host === '' || $host !== trim($host)) {
            throw new RuntimeException('Test database host is invalid.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $host)) {
            throw new RuntimeException('Test database host contains invalid characters.');
        }
        if (strpos($host, ';') !== false
            || strpos($host, '"') !== false
            || strpos($host, "'") !== false
            || strpos($host, '[') !== false
            || strpos($host, ']') !== false
            || strpos($host, '\\') !== false
            || strpos($host, '=') !== false
        ) {
            throw new RuntimeException('Test database host contains invalid characters.');
        }
        if (strcasecmp($host, 'localhost') === 0) {
            return;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6) !== false) {
            return;
        }
        if (preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/', $host)) {
            return;
        }
        throw new RuntimeException('Test database host is not a valid hostname or IP address.');
    }

    /**
     * @param string $username
     * @return void
     */
    public static function assertValidTestDbUser($username)
    {
        if ($username === '' || $username !== trim($username)) {
            throw new RuntimeException('Test database username is invalid.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $username)) {
            throw new RuntimeException('Test database username contains invalid characters.');
        }
        if (strpos($username, ';') !== false
            || strpos($username, '"') !== false
            || strpos($username, "'") !== false
            || strpos($username, '[') !== false
            || strpos($username, ']') !== false
            || strpos($username, '\\') !== false
            || strpos($username, '=') !== false
        ) {
            throw new RuntimeException('Test database username contains invalid characters.');
        }
        if (!preg_match('/^[a-zA-Z0-9._@-]+$/', $username)) {
            throw new RuntimeException('Test database username contains invalid characters.');
        }
    }

    /**
     * @param int $port
     * @return void
     */
    public static function assertValidDbPort($port)
    {
        $port = (int) $port;
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Test database port is invalid.');
        }
    }

    /**
     * @param array $config
     * @return void
     */
    public static function assertTestDatabaseReady(array $config)
    {
        $pdo = self::connectPdo($config, false);
        try {
            $pdo->exec('USE `' . str_replace('`', '``', $config['database']) . '`');
        } catch (Exception $e) {
            throw new RuntimeException(
                'Test restore database does not exist. Create the empty target database first.'
            );
        }
        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_NUM);
        if ($tables !== [] && count($tables) > 0) {
            throw new RuntimeException('Test restore database must be empty before import.');
        }
    }

    /**
     * @param array  $config
     * @param string $testUrl
     * @return void
     */
    public static function patchWhmcsUrlSettings(array $config, $testUrl)
    {
        $pdo = self::connectPdo($config, true);
        $pdo->exec('USE `' . str_replace('`', '``', $config['database']) . '`');

        $normalized = rtrim($testUrl, '/') . '/';
        $parsed     = parse_url($normalized);
        $domain     = isset($parsed['host']) ? $parsed['host'] : '';

        $stmt = $pdo->prepare('UPDATE tblconfiguration SET value = ? WHERE setting = ?');
        $stmt->execute([$normalized, 'SystemURL']);
        if ($domain !== '') {
            $stmt->execute([$domain, 'Domain']);
        }
        if (isset($parsed['scheme']) && strtolower($parsed['scheme']) === 'https') {
            $stmt->execute([$normalized, 'SystemSSLURL']);
        }
    }

    /**
     * @param string $configPath
     * @param array  $dbConfig
     * @return void
     */
    public static function patchConfigurationPhp($configPath, array $dbConfig)
    {
        if (!is_file($configPath)) {
            throw new RuntimeException('configuration.php not found in test restore directory.');
        }
        $content = file_get_contents($configPath);
        if ($content === false) {
            throw new RuntimeException('Unable to read configuration.php for test restore.');
        }

        $replacements = [
            'db_host'     => $dbConfig['host'],
            'db_username' => $dbConfig['username'],
            'db_password' => $dbConfig['password'],
            'db_name'     => $dbConfig['database'],
        ];
        if (isset($dbConfig['port']) && $dbConfig['port'] && preg_match('/\$db_port\s*=/', $content)) {
            $replacements['db_port'] = (string) $dbConfig['port'];
        }

        foreach ($replacements as $var => $value) {
            $quoted = var_export((string) $value, true);
            $pattern = '/\$' . $var . '\s*=\s*[^;]+;/';
            if (!preg_match($pattern, $content)) {
                throw new RuntimeException('configuration.php is missing $' . $var . '.');
            }
            $content = preg_replace($pattern, '$' . $var . ' = ' . $quoted . ';', $content, 1);
        }

        if (file_put_contents($configPath, $content) === false) {
            throw new RuntimeException('Unable to write patched configuration.php.');
        }
    }

    /**
     * @param string $path
     * @param bool   $mustExist
     * @return string
     */
    public static function canonicalPath($path, $mustExist = true)
    {
        $normalized = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        if ($mustExist) {
            $real = realpath($normalized);
            if ($real === false) {
                throw new RuntimeException('Path does not resolve: ' . $path);
            }
            return rtrim($real, DIRECTORY_SEPARATOR);
        }
        return $normalized;
    }

    /**
     * @param string $inner
     * @param string $root
     * @return bool
     */
    public static function pathIsInsideRoot($inner, $root)
    {
        $innerNorm = self::normalizeForCompare($inner);
        $rootNorm  = self::normalizeForCompare($root);
        if ($innerNorm === $rootNorm) {
            return true;
        }
        return stripos($innerNorm, $rootNorm . DIRECTORY_SEPARATOR) === 0;
    }

    /**
     * @param string $path
     * @return bool
     */
    /**
     * Create a directory if needed and confirm the PHP process can write a probe file.
     *
     * @param string $path Absolute directory path.
     *
     * @return bool
     */
    public static function ensureWritableDirectory($path)
    {
        if ($path === '' || $path === '.' || $path === '..') {
            return false;
        }
        if (!is_dir($path) && !@mkdir($path, 0777, true) && !is_dir($path)) {
            return false;
        }
        $probe = $path . DIRECTORY_SEPARATOR . '.sp_write_probe_' . bin2hex(random_bytes(4));
        if (@file_put_contents($probe, 'ok') === false) {
            return false;
        }
        if (!@unlink($probe)) {
            @unlink($probe);
            return false;
        }
        return true;
    }

    public static function isDirectoryEmpty($path)
    {
        if (!is_dir($path)) {
            return false;
        }
        $items = scandir($path);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array $config
     * @param bool  $selectDatabase
     * @return \PDO
     */
    public static function connectPdo(array $config, $selectDatabase = true)
    {
        $host = $config['host'];
        $port = isset($config['port']) ? (int) $config['port'] : 3306;
        $dsn  = 'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4';
        if ($selectDatabase && !empty($config['database'])) {
            $dsn .= ';dbname=' . $config['database'];
        }
        return new \PDO($dsn, $config['username'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /**
     * @param string $path
     * @return string
     */
    private static function normalizeForCompare($path)
    {
        return strtolower(rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR));
    }
}
