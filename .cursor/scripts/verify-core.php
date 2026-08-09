<?php
/**
 * Dev harness: verify Encryption and Integrity round-trip without a full WHMCS install.
 */

define('WHMCS', true);

require_once __DIR__ . '/../../modules/addons/snapshot_pro/autoload.php';

use SnapshotPro\Encryption;
use SnapshotPro\Integrity;

$tmpdir = sys_get_temp_dir() . '/snapshot_pro_verify_' . getmypid();
@mkdir($tmpdir, 0700, true);

$plain = $tmpdir . '/sample.dat';
$cipher = $tmpdir . '/sample.spx';
$key = Encryption::generateKey();

file_put_contents($plain, str_repeat('WHMCS Snapshot Pro test payload ', 1000));

$enc = new Encryption($key);
$enc->encryptFile($plain, $cipher);

if (!is_file($cipher)) {
    fwrite(STDERR, "Encryption failed: output file missing\n");
    exit(1);
}

$checksum = Integrity::checksum($cipher);
if (!Integrity::verify($cipher, $checksum)) {
    fwrite(STDERR, "Integrity verification failed\n");
    exit(1);
}

$decrypted = $tmpdir . '/sample.restored';
$enc->decryptFile($cipher, $decrypted);

if (hash_file('sha256', $plain) !== hash_file('sha256', $decrypted)) {
    fwrite(STDERR, "Decrypted payload does not match original\n");
    exit(1);
}

// Wrong key must fail HMAC verification.
try {
    (new Encryption(Encryption::generateKey()))->decryptFile($cipher, $tmpdir . '/bad.out');
    fwrite(STDERR, "Expected decryption with wrong key to fail\n");
    exit(1);
} catch (Throwable $e) {
    // expected
}

array_map('unlink', glob($tmpdir . '/*') ?: []);
@rmdir($tmpdir);

echo "Core encryption + integrity verification passed.\n";
