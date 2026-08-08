{*
    WHMCS Snapshot Pro - Dashboard Template

    Displays summary cards (last backup, storage usage, next scheduled run,
    total snapshots) and a table of recent snapshots. Uses Bootstrap 4 markup
    that WHMCS ships in the admin area.
*}
<link rel="stylesheet" href="{$assetsBase}/css/snapshot_pro.css">

<div class="snapshot-pro">

    {* --- Navigation tabs --- *}
    <ul class="nav nav-tabs sp-nav">
        {foreach from=$navItems key=navKey item=navLabel}
            <li class="nav-item">
                <a class="nav-link {if $action eq $navKey || ($navKey eq 'dashboard' && $action eq 'dashboard')}active{/if}"
                   href="{$modulelink}&spaction={$navKey}">{$navLabel}</a>
            </li>
        {/foreach}
    </ul>

    {if $notice}
        <div class="alert alert-{$notice.type} sp-mt">{$notice.message}</div>
    {/if}

    <h2 class="sp-page-title">Disaster Recovery Dashboard</h2>

    {* --- Summary cards --- *}
    <div class="row sp-cards">
        <div class="col-md-3">
            <div class="sp-card sp-card-blue">
                <div class="sp-card-label">Last Backup</div>
                <div class="sp-card-value">
                    {if $lastBackup}{$lastBackup->completed_at|default:$lastBackup->created_at}{else}Never{/if}
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="sp-card sp-card-green">
                <div class="sp-card-label">Storage Usage</div>
                <div class="sp-card-value" id="sp-usage" data-bytes="{$totalUsage}">
                    {$totalUsage} bytes
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="sp-card sp-card-purple">
                <div class="sp-card-label">Next Scheduled Backup</div>
                <div class="sp-card-value sp-small">{$nextBackup}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="sp-card sp-card-orange">
                <div class="sp-card-label">Total Snapshots</div>
                <div class="sp-card-value">{$snapshots|@count}</div>
            </div>
        </div>
    </div>

    <div class="sp-actions-bar">
        <a href="{$modulelink}&spaction=create" class="btn btn-primary">
            <i class="fas fa-plus"></i> Create Backup
        </a>
        <a href="{$modulelink}&spaction=restore" class="btn btn-warning">
            <i class="fas fa-undo"></i> Restore
        </a>
    </div>

    {* --- Recent snapshots table --- *}
    <h4 class="sp-section-title">Recent Snapshots</h4>
    {if $snapshots|@count eq 0}
        <div class="alert alert-info">No snapshots yet. Create your first backup to get started.</div>
    {else}
        <div class="table-responsive">
            <table class="table table-striped sp-table">
                <thead>
                    <tr>
                        <th>Snapshot ID</th>
                        <th>Created</th>
                        <th>Scope</th>
                        <th>Storage</th>
                        <th>Size</th>
                        <th>Checksum</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach from=$snapshots item=snap}
                        <tr>
                            <td><code>{$snap->snapshot_id}</code></td>
                            <td>{$snap->created_at}</td>
                            <td><span class="badge badge-info">{$snap->scope}</span></td>
                            <td>{$snap->storage}</td>
                            <td class="sp-bytes" data-bytes="{$snap->size}">{$snap->size}</td>
                            <td><code title="{$snap->checksum}">{$snap->checksum|truncate:16:"…":true}</code></td>
                            <td>
                                {if $snap->status eq 'complete'}
                                    <span class="badge badge-success">Complete</span>
                                {elseif $snap->status eq 'running'}
                                    <span class="badge badge-warning">Running</span>
                                {else}
                                    <span class="badge badge-danger" title="{$snap->error}">Failed</span>
                                {/if}
                            </td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
    {/if}
</div>

<script src="{$assetsBase}/js/snapshot_pro.js"></script>
<script>
    // Format byte values into human-readable units on the dashboard.
    SnapshotPro.formatByteCells();
</script>
