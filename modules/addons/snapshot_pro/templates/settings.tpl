{*
    WHMCS Snapshot Pro - Settings Template

    Configure storage backend, schedule, retention, encryption key, backup
    scope and Google Drive credentials. All posts are CSRF-protected.
*}
<link rel="stylesheet" href="{$assetsBase}/css/snapshot_pro.css">

<div class="snapshot-pro" data-modulelink="{$modulelink}" data-csrf="{$csrfToken}">

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

    <h2 class="sp-page-title">Module Settings</h2>

    <form method="post" action="{$modulelink}&spaction=settings" class="sp-form">
        <input type="hidden" name="csrf_token" value="{$csrfToken}">

        {* ---- Storage backend ---- *}
        <fieldset class="sp-fieldset">
            <legend>Storage Backend</legend>
            <div class="form-group">
                <label for="storage_backend">Default storage backend</label>
                <select name="storage_backend" id="storage_backend" class="form-control">
                    {foreach from=$storageTypes key=stKey item=stLabel}
                        <option value="{$stKey}" {if $settings.storage_backend eq $stKey}selected{/if}>{$stLabel}</option>
                    {/foreach}
                </select>
            </div>
            <div class="form-group">
                <label for="local_storage_path">Local storage path</label>
                <input type="text" name="local_storage_path" id="local_storage_path"
                       class="form-control" value="{$settings.local_storage_path}"
                       placeholder="Leave blank to use the module's storage/ directory">
                <small class="form-text text-muted">Absolute path on the server. Must be writable by the web user.</small>
            </div>
        </fieldset>

        {* ---- Google Drive ---- *}
        <fieldset class="sp-fieldset">
            <legend>Google Drive</legend>
            <div class="form-group">
                <label for="gdrive_service_account">Service Account JSON</label>
                <textarea name="gdrive_service_account" id="gdrive_service_account"
                          class="form-control sp-json" rows="6"
                          placeholder='{literal}{"type":"service_account", ...}{/literal}'>{$settings.gdrive_service_account}</textarea>
                <small class="form-text text-muted">
                    Paste the full JSON key of a Google service account that has access to the target Drive folder.
                </small>
            </div>
            <div class="form-group">
                <label for="gdrive_folder_id">Drive Folder ID (optional)</label>
                <input type="text" name="gdrive_folder_id" id="gdrive_folder_id"
                       class="form-control" value="{$settings.gdrive_folder_id}"
                       placeholder="e.g. 1AbCdEfGhIjKlMnOpQrStUvWxYz">
            </div>
            <button type="button" id="sp-test-gdrive" class="btn btn-outline-info btn-sm">Test Google Drive Connection</button>
            <span id="sp-gdrive-result" class="sp-inline-result"></span>
        </fieldset>

        {* ---- Schedule & retention ---- *}
        <fieldset class="sp-fieldset">
            <legend>Schedule &amp; Retention</legend>
            <div class="form-group">
                <label for="schedule">Automatic backup schedule</label>
                <select name="schedule" id="schedule" class="form-control">
                    <option value="daily"    {if $settings.schedule eq 'daily'}selected{/if}>Daily</option>
                    <option value="weekly"   {if $settings.schedule eq 'weekly'}selected{/if}>Weekly (Mondays)</option>
                    <option value="monthly"  {if $settings.schedule eq 'monthly'}selected{/if}>Monthly (1st)</option>
                    <option value="disabled" {if $settings.schedule eq 'disabled'}selected{/if}>Disabled</option>
                </select>
                <small class="form-text text-muted">Runs via the WHMCS daily cron.</small>
            </div>
            <div class="form-group">
                <label for="retention">Retention — keep last N snapshots</label>
                <input type="number" min="0" name="retention" id="retention"
                       class="form-control" value="{$settings.retention}">
                <small class="form-text text-muted">Older snapshots beyond this count are pruned automatically. 0 = keep all.</small>
            </div>
        </fieldset>

        {* ---- Scope ---- *}
        <fieldset class="sp-fieldset">
            <legend>Backup Scope</legend>
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="backup_database"
                       name="backup_database" {if $settings.backup_database eq '1'}checked{/if}>
                <label class="form-check-label" for="backup_database">Include database</label>
            </div>
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="backup_files"
                       name="backup_files" {if $settings.backup_files eq '1'}checked{/if}>
                <label class="form-check-label" for="backup_files">Include filesystem</label>
            </div>
        </fieldset>

        {* ---- Encryption ---- *}
        <fieldset class="sp-fieldset">
            <legend>Encryption</legend>
            <div class="form-group">
                <label for="encryption_key">AES-256 Encryption Key</label>
                <input type="text" name="encryption_key" id="encryption_key"
                       class="form-control" value="{$settings.encryption_key}"
                       autocomplete="off">
                <small class="form-text text-muted">
                    <strong>Keep this safe.</strong> Snapshots cannot be restored without it. A strong key was
                    generated automatically on activation. Leave unchanged to keep the current key.
                </small>
            </div>
        </fieldset>

        <div class="sp-form-actions">
            <button type="submit" class="btn btn-primary">Save Settings</button>
        </div>
    </form>
</div>

<script>
    window.SnapshotProAjaxUrl = "{$ajaxUrl|escape:'javascript'}";
</script>
<script src="{$assetsBase}/js/snapshot_pro.js"></script>
<script>
    // Wire up the Google Drive test button.
    SnapshotPro.initSettings();
</script>
