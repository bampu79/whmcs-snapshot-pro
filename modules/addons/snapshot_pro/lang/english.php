<?php
/**
 * WHMCS Snapshot Pro - English Language File
 *
 * Provides translatable strings for the module UI. WHMCS loads this file based
 * on the "language" declared in snapshot_pro_config().
 *
 * @package    SnapshotPro
 * @author     bampu79
 * @license    MIT
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$_ADDONLANG = [
    // General / navigation.
    'module_name'      => 'WHMCS Snapshot Pro',
    'nav_dashboard'    => 'Dashboard',
    'nav_create'       => 'Create Backup',
    'nav_restore'      => 'Restore',
    'nav_settings'     => 'Settings',
    'nav_logs'         => 'Logs',

    // Dashboard.
    'dash_title'           => 'Disaster Recovery Dashboard',
    'dash_last_backup'     => 'Last Backup',
    'dash_storage_usage'   => 'Storage Usage',
    'dash_next_scheduled'  => 'Next Scheduled Backup',
    'dash_total_snapshots' => 'Total Snapshots',
    'dash_recent'          => 'Recent Snapshots',
    'dash_no_snapshots'    => 'No snapshots yet. Create your first backup to get started.',

    // Snapshot table columns.
    'col_id'         => 'Snapshot ID',
    'col_created'    => 'Created',
    'col_scope'      => 'Scope',
    'col_storage'    => 'Storage',
    'col_size'       => 'Size',
    'col_checksum'   => 'Checksum (SHA-256)',
    'col_status'     => 'Status',
    'col_actions'    => 'Actions',

    // Create backup.
    'create_title'       => 'Create a New Snapshot',
    'create_intro'       => 'Create a complete, encrypted point-in-time snapshot of your WHMCS installation.',
    'create_start'       => 'Start Backup Now',
    'create_running'     => 'Backup in progress…',
    'create_done'        => 'Backup completed successfully.',
    'create_failed'      => 'Backup failed. See logs for details.',

    // Restore wizard.
    'restore_title'      => 'Guided Restore Wizard',
    'restore_select'     => 'Select a snapshot to restore',
    'restore_verify'     => 'Integrity Verification',
    'restore_safety'     => 'Pre-restore Safety Backup',
    'restore_scope'      => 'Choose Restore Scope',
    'restore_confirm'    => 'Confirm Restore',
    'restore_execute'    => 'Restoring…',
    'restore_report'     => 'Restore Report',
    'restore_scope_full' => 'Full (Database + Files)',
    'restore_scope_db'   => 'Database only',
    'restore_scope_files'=> 'Files only',

    // Settings.
    'settings_title'     => 'Module Settings',
    'settings_storage'   => 'Storage Backend',
    'settings_schedule'  => 'Backup Schedule',
    'settings_retention' => 'Retention (keep last N)',
    'settings_encryption'=> 'Encryption Key',
    'settings_gdrive'    => 'Google Drive Service Account JSON',
    'settings_save'      => 'Save Settings',
    'settings_saved'     => 'Settings saved successfully.',

    // Logs.
    'logs_title'   => 'Audit Log',
    'logs_empty'   => 'No log entries yet.',
    'log_time'     => 'Time',
    'log_level'    => 'Level',
    'log_action'   => 'Action',
    'log_user'     => 'Admin',
    'log_message'  => 'Message',

    // Common.
    'btn_delete'   => 'Delete',
    'btn_restore'  => 'Restore',
    'btn_verify'   => 'Verify',
    'btn_next'     => 'Next',
    'btn_back'     => 'Back',
    'btn_cancel'   => 'Cancel',
    'btn_confirm'  => 'Confirm & Restore',
    'access_denied'=> 'Access denied. This module is restricted to Full Administrators only.',
];
