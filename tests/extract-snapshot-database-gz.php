<?php
/**
 * Extract database.sql.gz from an encrypted snapshot bundle to stdout path (CLI).
 * Usage: SP_ENC_KEY=... php tests/extract-snapshot-database-gz.php <snapshot.spx> <output.sql.gz>
 */
declare(strict_types=1);

define('WHMCS', true);

$key = getenv('SP_ENC_KEY') ?: '';
if ($key === '' || $argc < 3) {
    fwrite(STDERR, "Usage: SP_ENC_KEY=... php tests/extract-snapshot-database-gz.php <snapshot.spx> <output.sql.gz>\n");
    exit(1);
}

require dirname(__DIR__) . '/modules/addons/snapshot_pro/lib/Encryption.php';

$spx = $argv[1];
$outGz = $argv[2];
$work = sys_get_temp_dir() . '/sp_extract_' . bin2hex(random_bytes(3));
@mkdir($work, 0700, true);
$tar = $work . '/bundle.tar';

(new SnapshotPro\Encryption($key))->decryptFile($spx, $tar);

$fp = fopen($tar, 'rb');
$found = false;
while (!feof($fp)) {
    $header = fread($fp, 512);
    if ($header === false || $header === '' || ord($header[0]) === 0) {
        break;
    }
    $name = rtrim(substr($header, 0, 100), "\0");
    $size = octdec(trim(substr($header, 124, 12)) ?: '0');
    if ($name === 'database.sql.gz') {
        $out = fopen($outGz, 'wb');
        $remaining = $size;
        while ($remaining > 0) {
            $read = fread($fp, min(262144, $remaining));
            if ($read === false) {
                break;
            }
            fwrite($out, $read);
            $remaining -= strlen($read);
        }
        fclose($out);
        $found = true;
        break;
    }
    $skip = $size + ((512 - ($size % 512)) % 512);
    fseek($fp, $skip, SEEK_CUR);
}
fclose($fp);
@unlink($tar);
@rmdir($work);

if (!$found) {
    fwrite(STDERR, "database.sql.gz not found in bundle\n");
    exit(1);
}
echo $outGz . "\n";
