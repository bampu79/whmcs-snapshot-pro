<?php
/**
 * WHMCS Snapshot Pro - PSR-4 Autoloader
 *
 * Registers a lightweight autoloader for the SnapshotPro\ namespace so the
 * module's classes in lib/ load on demand without requiring Composer. WHMCS
 * addon modules cannot assume a project-level composer autoloader covers custom
 * module namespaces, so we register our own.
 *
 * @package    SnapshotPro
 * @author     bampu79
 * @license    MIT
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

spl_autoload_register(function ($class) {
    $prefix  = 'SnapshotPro\\';
    $baseDir = __DIR__ . '/lib/';

    // Only handle classes in our namespace.
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file          = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
