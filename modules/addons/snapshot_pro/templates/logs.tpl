{*
    WHMCS Snapshot Pro - Logs Template

    Paginated audit log of all backup/restore/settings operations.
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

    <h2 class="sp-page-title">Audit Log</h2>

    {if $logs|@count eq 0}
        <div class="alert alert-info">No log entries yet.</div>
    {else}
        <div class="table-responsive">
            <table class="table table-sm table-striped sp-table sp-logs">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Level</th>
                        <th>Action</th>
                        <th>Admin</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach from=$logs item=log}
                        <tr class="sp-log-{$log->level}">
                            <td class="sp-nowrap">{$log->created_at}</td>
                            <td>
                                {if $log->level eq 'success'}<span class="badge badge-success">success</span>
                                {elseif $log->level eq 'error'}<span class="badge badge-danger">error</span>
                                {elseif $log->level eq 'warning'}<span class="badge badge-warning">warning</span>
                                {else}<span class="badge badge-secondary">info</span>{/if}
                            </td>
                            <td><code>{$log->action}</code></td>
                            <td>{$log->admin_user|default:'—'}</td>
                            <td>{$log->message|escape}</td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>

        {* --- Pagination --- *}
        {assign var=totalPages value=($totalLogs / $perPage)|ceil}
        {if $totalPages > 1}
            <nav>
                <ul class="pagination">
                    {section name=pg start=1 loop=$totalPages+1}
                        <li class="page-item {if $smarty.section.pg.index eq $page}active{/if}">
                            <a class="page-link"
                               href="{$modulelink}&spaction=logs&p={$smarty.section.pg.index}">{$smarty.section.pg.index}</a>
                        </li>
                    {/section}
                </ul>
            </nav>
        {/if}
    {/if}
</div>
