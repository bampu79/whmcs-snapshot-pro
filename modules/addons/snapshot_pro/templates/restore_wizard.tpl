{*
    WHMCS Snapshot Pro - Restore Wizard Template

    Multi-step, AJAX-driven guided restore:
      1. Select snapshot
      2. Integrity verification (SHA-256)
      3. Pre-restore safety backup
      4. Choose scope (full / database / files)
      5. Confirmation summary
      6. Restore execution with live log
      7. Post-restore report
*}
<link rel="stylesheet" href="{$assetsBase}/css/snapshot_pro.css">

<div class="snapshot-pro" id="sp-restore"
     data-modulelink="{$modulelink}" data-csrf="{$csrfToken}">

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

    <h2 class="sp-page-title">Guided Restore Wizard</h2>

    <div class="alert alert-danger sp-restore-warning">
        <strong>Warning:</strong> Restoring overwrites your current WHMCS data. A pre-restore safety
        backup is created automatically at Step 3 so you can roll back if needed.
    </div>

    {* --- Step progress indicator --- *}
    <ol class="sp-steps" id="sp-steps">
        {foreach from=$steps item=step name=stepsLoop}
            <li class="sp-step {if $smarty.foreach.stepsLoop.first}sp-step-active{/if}"
                data-step="{$step.key}" data-index="{$smarty.foreach.stepsLoop.index}">
                <span class="sp-step-num">{$smarty.foreach.stepsLoop.iteration}</span>
                <span class="sp-step-label">{$step.label}</span>
            </li>
        {/foreach}
    </ol>

    <div class="sp-wizard-body">

        {* ---- Step 1: Select ---- *}
        <div class="sp-panel" data-panel="select">
            <h4>Step 1 — Select a Snapshot</h4>
            {if $snapshots|@count eq 0}
                <div class="alert alert-info">No snapshots available. Create a backup first.</div>
            {else}
                <div class="table-responsive">
                    <table class="table table-hover sp-table">
                        <thead>
                            <tr>
                                <th></th>
                                <th>Snapshot ID</th>
                                <th>Created</th>
                                <th>Scope</th>
                                <th>Storage</th>
                                <th>Size</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {foreach from=$snapshots item=snap}
                                {if $snap->status eq 'complete'}
                                <tr>
                                    <td>
                                        <input type="radio" name="sp_snapshot" value="{$snap->snapshot_id}"
                                               class="sp-select-snapshot">
                                    </td>
                                    <td><code>{$snap->snapshot_id}</code></td>
                                    <td>{$snap->created_at}</td>
                                    <td><span class="badge badge-info">{$snap->scope}</span></td>
                                    <td>{$snap->storage}</td>
                                    <td class="sp-bytes" data-bytes="{$snap->size}">{$snap->size}</td>
                                    <td><span class="badge badge-success">Complete</span></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-danger sp-delete-snapshot"
                                                data-snapshot="{$snap->snapshot_id}">Delete</button>
                                    </td>
                                </tr>
                                {/if}
                            {/foreach}
                        </tbody>
                    </table>
                </div>
            {/if}
            <div class="sp-nav-buttons">
                <button class="btn btn-primary sp-next" disabled>Next: Verify Integrity</button>
            </div>
        </div>

        {* ---- Step 2: Verify ---- *}
        <div class="sp-panel" data-panel="verify" style="display:none;">
            <h4>Step 2 — Integrity Verification</h4>
            <p>We will download (if needed) and confirm the snapshot's SHA-256 checksum before continuing.</p>
            <div id="sp-verify-status" class="sp-status-box">Ready to verify…</div>
            <div class="sp-nav-buttons">
                <button class="btn btn-secondary sp-back">Back</button>
                <button class="btn btn-info sp-verify-run">Run Verification</button>
                <button class="btn btn-primary sp-next" disabled>Next: Safety Backup</button>
            </div>
        </div>

        {* ---- Step 3: Safety backup ---- *}
        <div class="sp-panel" data-panel="safety" style="display:none;">
            <h4>Step 3 — Pre-restore Safety Backup</h4>
            <label class="sp-check">
                <input type="checkbox" id="sp-create-safety-backup" checked>
                Create a safety backup before restoring
            </label>
            <p class="text-muted small" id="sp-safety-help">
                A quick snapshot of the current state is taken so you can roll back if the restore fails.
            </p>
            <div id="sp-safety-run-block">
                <div class="progress sp-progress">
                    <div id="sp-safety-bar" class="progress-bar progress-bar-striped progress-bar-animated"
                         style="width:0%;">0%</div>
                </div>
                <div id="sp-safety-msg" class="sp-progress-msg">Not started.</div>
                <button type="button" class="btn btn-info sp-safety-run">Create Safety Backup</button>
            </div>
            <div id="sp-safety-skip-production" class="alert alert-danger" style="display:none; margin-top:12px;">
                <strong>Warning:</strong> You are proceeding without a pre-restore safety backup. If the restore fails
                or produces unexpected results, Snapshot Pro will not have an automatically created rollback point.
                <label class="sp-check" style="display:block; margin-top:10px;">
                    <input type="checkbox" id="sp-skip-safety-ack">
                    I understand that I am proceeding without a safety backup.
                </label>
            </div>
            <div id="sp-safety-skip-test-info" class="alert alert-info" style="display:none; margin-top:12px;">
                Safety backup is not required for Test/Clone Restore because the restore target is isolated from the
                live WHMCS installation.
            </div>
            <div class="sp-nav-buttons">
                <button class="btn btn-secondary sp-back">Back</button>
                <button class="btn btn-primary sp-next" id="sp-safety-next" disabled>Next: Choose Scope</button>
            </div>
        </div>

        {* ---- Step 4: Scope ---- *}
        <div class="sp-panel" data-panel="scope" style="display:none;">
            <h4>Step 4 — Choose Restore Scope</h4>
            <div class="sp-scope-options">
                <label class="sp-radio">
                    <input type="radio" name="sp_scope" value="full" checked> Full (Database + Files)
                </label>
                <label class="sp-radio">
                    <input type="radio" name="sp_scope" value="database"> Database only
                </label>
                <label class="sp-radio">
                    <input type="radio" name="sp_scope" value="files"> Files only
                </label>
            </div>
            <div class="sp-nav-buttons">
                <button class="btn btn-secondary sp-back">Back</button>
                <button class="btn btn-primary sp-next">Next: Restore Mode</button>
            </div>
        </div>

        {* ---- Step 5: Restore mode ---- *}
        <div class="sp-panel" data-panel="mode" style="display:none;">
            <h4>Step 5 — Restore Mode</h4>
            <div class="sp-scope-options">
                <label class="sp-radio">
                    <input type="radio" name="sp_restore_mode" value="production" checked>
                    Restore to Current WHMCS
                    <div class="text-danger small">WARNING: This will overwrite the current database and files.</div>
                </label>
                <label class="sp-radio">
                    <input type="radio" name="sp_restore_mode" value="test">
                    Restore to Test Location
                    <div class="text-muted small">Creates an isolated copy for verification. Production is not modified.</div>
                </label>
            </div>
            <div id="sp-test-restore-fields" class="sp-test-restore-fields" style="display:none; margin-top:16px;">
                <div class="form-group">
                    <label for="sp-test-filesystem">Filesystem destination</label>
                    <input type="text" class="form-control" id="sp-test-filesystem" placeholder="D:\path\to\hostila-restore-test">
                </div>
                <div class="form-group">
                    <label for="sp-test-db-name">Database name</label>
                    <input type="text" class="form-control" id="sp-test-db-name" placeholder="hostila_restore_test">
                </div>
                <div class="form-group">
                    <label for="sp-test-db-host">Database host</label>
                    <input type="text" class="form-control" id="sp-test-db-host" placeholder="localhost">
                </div>
                <div class="form-group">
                    <label for="sp-test-db-user">Database username</label>
                    <input type="text" class="form-control" id="sp-test-db-user">
                </div>
                <div class="form-group">
                    <label for="sp-test-db-password">Database password</label>
                    <input type="password" class="form-control" id="sp-test-db-password" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label for="sp-test-base-url">Test base URL</label>
                    <input type="url" class="form-control" id="sp-test-base-url" placeholder="http://hostila-test.local/">
                </div>
            </div>
            <div class="sp-nav-buttons">
                <button class="btn btn-secondary sp-back">Back</button>
                <button class="btn btn-primary sp-next" id="sp-mode-next">Next: Confirmation</button>
            </div>
        </div>

        {* ---- Step 6: Confirm ---- *}
        <div class="sp-panel" data-panel="confirm" style="display:none;">
            <h4>Step 6 — Confirmation</h4>
            <div id="sp-confirm-summary" class="sp-status-box">Loading summary…</div>
            <label class="sp-check">
                <input type="checkbox" id="sp-confirm-check">
                <span id="sp-confirm-check-label">I understand this will overwrite current data.</span>
            </label>
            <div class="sp-nav-buttons">
                <button class="btn btn-secondary sp-back">Back</button>
                <button class="btn btn-danger sp-execute-run" disabled>Confirm &amp; Restore</button>
            </div>
        </div>

        {* ---- Step 7: Execute ---- *}
        <div class="sp-panel" data-panel="execute" style="display:none;">
            <h4>Step 7 — Restoring</h4>
            <div class="progress sp-progress">
                <div id="sp-restore-bar" class="progress-bar progress-bar-striped progress-bar-animated"
                     style="width:0%;">0%</div>
            </div>
            <div id="sp-restore-msg" class="sp-progress-msg">Waiting…</div>
            <div id="sp-restore-log" class="sp-progress-log"></div>
        </div>

        {* ---- Step 8: Report ---- *}
        <div class="sp-panel" data-panel="report" style="display:none;">
            <h4>Step 8 — Restore Report</h4>
            <div id="sp-report" class="sp-status-box"></div>
            <div class="sp-nav-buttons">
                <a href="{$modulelink}&spaction=dashboard" class="btn btn-primary">Back to Dashboard</a>
            </div>
        </div>

    </div>
</div>

<script>
    window.SnapshotProAjaxUrl = "{$ajaxUrl|escape:'javascript'}";
</script>
<script src="{$assetsBase}/js/snapshot_pro.js"></script>
<script>
    // Boot the restore wizard state machine.
    SnapshotPro.initRestoreWizard();
</script>
