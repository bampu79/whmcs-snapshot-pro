<?php
/**
 * Dev harness: end-to-end backup using a minimal WHMCS fixture and MariaDB.
 *
 * Exercises module activation, database dump, filesystem archive, encryption,
 * checksum generation, and local storage without requiring licensed WHMCS files.
 */

define('WHMCS', true);

$repoRoot = realpath(__DIR__ . '/../..');
$fixtureRoot = realpath(__DIR__ . '/../dev/fixture-whmcs');
if ($repoRoot === false || $fixtureRoot === false) {
    fwrite(STDERR, "Repository paths could not be resolved.\n");
    exit(1);
}

define('ROOTDIR', $fixtureRoot);

require $repoRoot . '/.cursor/dev/vendor/autoload.php';

if (!class_exists('WHMCS\\Database\\Capsule')) {
    class_alias(Illuminate\Database\Capsule\Manager::class, 'WHMCS\\Database\\Capsule');
}

use Illuminate\Database\Capsule\Manager as Capsule;
use SnapshotPro\SnapshotManager;

$capsule = new Capsule();
$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => getenv('WHMCS_DB_HOST') ?: '127.0.0.1',
    'port'      => (int) (getenv('WHMCS_DB_PORT') ?: 3306),
    'database'  => getenv('WHMCS_DB_NAME') ?: 'whmcs',
    'username'  => getenv('WHMCS_DB_USER') ?: 'whmcs',
    'password'  => getenv('WHMCS_DB_PASS') ?: 'whmcs',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix'    => '',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

// Minimal WHMCS schema for backup + restore verification.
$schema = Capsule::schema();
if (!$schema->hasTable('tblclients')) {
    $schema->create('tblclients', function ($table) {
        $table->increments('id');
        $table->string('firstname', 100)->default('Test');
        $table->string('lastname', 100)->default('Client');
        $table->string('email', 191)->default('test@example.com');
        $table->dateTime('datecreated')->nullable();
    });
    Capsule::table('tblclients')->insert([
        'firstname'    => 'Snapshot',
        'lastname'     => 'Pro',
        'email'        => 'snapshot@example.com',
        'datecreated'  => date('Y-m-d H:i:s'),
    ]);
}

require_once ROOTDIR . '/modules/addons/snapshot_pro/autoload.php';
require_once ROOTDIR . '/modules/addons/snapshot_pro/snapshot_pro.php';

$activation = snapshot_pro_activate();
if (($activation['status'] ?? '') !== 'success') {
    fwrite(STDERR, 'Activation failed: ' . ($activation['description'] ?? 'unknown error') . PHP_EOL);
    exit(1);
}

$manager = new SnapshotManager(ROOTDIR);
$snapshot = $manager->create('manual', 'dev-harness', function ($pct, $msg) {
    fwrite(STDOUT, sprintf("[%3d%%] %s\n", $pct, $msg));
});

if (($snapshot['status'] ?? '') !== 'complete') {
    fwrite(STDERR, 'Snapshot did not complete: ' . json_encode($snapshot) . PHP_EOL);
    exit(1);
}

$storageDir = ROOTDIR . '/modules/addons/snapshot_pro/storage';
$files = glob($storageDir . '/*.spx') ?: [];
if (count($files) === 0) {
    fwrite(STDERR, "No .spx archive was written to storage.\n");
    exit(1);
}

$latest = array_reduce($files, function ($carry, $path) {
    if ($carry === null || filemtime($path) > filemtime($carry)) {
        return $path;
    }
    return $carry;
});

if (!is_readable($latest) || filesize($latest) < 100) {
    fwrite(STDERR, "Snapshot archive is missing or too small: {$latest}\n");
    exit(1);
}

echo "End-to-end backup succeeded.\n";
echo "Snapshot ID: {$snapshot['snapshot_id']}\n";
echo "Archive: {$latest} (" . filesize($latest) . " bytes)\n";
echo "Checksum: {$snapshot['checksum']}\n";
