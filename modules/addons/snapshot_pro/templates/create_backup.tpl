{*
    WHMCS Snapshot Pro - Create Backup Template

    One-click manual backup with a live progress bar driven by AJAX polling.
*}
<link rel="stylesheet" href="{$assetsBase}/css/snapshot_pro.css">

<div class="snapshot-pro">

    {* --- Navigation tabs --- *}
    <ul class="nav nav-tabs sp-nav">
        {foreach from=$navItems key=navKey item=navLabel}
            <li class="nav-item">
                <a class="nav-link {if $action eq $navKey}active{/if}"
                   href="{$modulelink}&spaction={$navKey}">{$navLabel}</a>
            </li>
        {/foreach}
    </ul>

    {if $notice}
        <div class="alert alert-{$notice.type} sp-mt">{$notice.message}</div>
    {/if}

    <h2 class="sp-page-title">Create a New Snapshot</h2>
    <p class="text-muted">
        Create a complete, encrypted point-in-time snapshot of your WHMCS installation.
        The backup includes:
    </p>
    <ul class="sp-scope-list">
        <li>
            <strong>Database:</strong>
            {if $settings.backup_database eq '1'}<span class="text-success">Included</span>{else}<span class="text-muted">Excluded (enable in Settings)</span>{/if}
        </li>
        <li>
            <strong>Filesystem:</strong>
            {if $settings.backup_files eq '1'}<span class="text-success">Included</span>{else}<span class="text-muted">Excluded (enable in Settings)</span>{/if}
        </li>
        <li><strong>Storage backend:</strong> {$settings.storage_backend}</li>
        <li><strong>Encryption:</strong> AES-256-CBC (key configured in Settings)</li>
    </ul>

    <div class="sp-backup-panel">
        <button id="sp-start-backup" class="btn btn-lg btn-primary"
                data-modulelink="{$modulelink}" data-csrf="{$csrfToken}">
            <i class="fas fa-play"></i> Start Backup Now
        </button>

        <div id="sp-progress-wrap" class="sp-progress-wrap" style="display:none;">
            <div class="progress sp-progress">
                <div id="sp-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated"
                     role="progressbar" style="width:0%;">0%</div>
            </div>
            <div id="sp-progress-msg" class="sp-progress-msg">Initializing…</div>
            <div id="sp-progress-log" class="sp-progress-log"></div>
        </div>

        <div id="sp-result" class="sp-result" style="display:none;"></div>
    </div>
</div>

<script>
    window.SnapshotProAjaxUrl = "{$ajaxUrl|escape:'javascript'}";
</script>
<script src="{$assetsBase}/js/snapshot_pro.js"></script>
<script>
    // Wire up the manual backup button and progress polling.
    SnapshotPro.initCreateBackup();
</script>
