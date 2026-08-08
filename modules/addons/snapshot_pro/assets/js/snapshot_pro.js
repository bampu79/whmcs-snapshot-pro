/*
 * WHMCS Snapshot Pro - Frontend JavaScript
 *
 * Powers the AJAX interactions of the admin UI:
 *   - Manual backup with progress polling
 *   - The multi-step restore wizard (verify / safety / confirm / execute)
 *   - Settings page Google Drive connection test
 *   - Byte formatting helpers
 *
 * Uses vanilla JS + fetch so it has no jQuery dependency (although WHMCS ships
 * jQuery, avoiding it keeps the module resilient across WHMCS versions).
 */
window.SnapshotPro = (function () {
    'use strict';

    var AJAX_URL = 'modules/addons/snapshot_pro/ajax/handler.php';

    /**
     * Convert a byte count into a human-readable string.
     * @param {number} bytes
     * @returns {string}
     */
    function humanBytes(bytes) {
        bytes = parseInt(bytes, 10) || 0;
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var i = 0;
        while (bytes >= 1024 && i < units.length - 1) {
            bytes /= 1024;
            i++;
        }
        return bytes.toFixed(i === 0 ? 0 : 2) + ' ' + units[i];
    }

    /**
     * Format every element with the .sp-bytes class (and the usage card).
     */
    function formatByteCells() {
        document.querySelectorAll('.sp-bytes').forEach(function (el) {
            el.textContent = humanBytes(el.getAttribute('data-bytes'));
        });
        var usage = document.getElementById('sp-usage');
        if (usage) {
            usage.textContent = humanBytes(usage.getAttribute('data-bytes'));
        }
    }

    /**
     * Perform a POST request to the AJAX handler.
     * @param {string} op
     * @param {object} params
     * @param {string} csrf
     * @returns {Promise<object>}
     */
    function post(op, params, csrf) {
        var body = new URLSearchParams();
        body.append('op', op);
        body.append('csrf_token', csrf);
        Object.keys(params || {}).forEach(function (k) {
            body.append(k, params[k]);
        });
        return fetch(AJAX_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrf
            },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); });
    }

    /**
     * Poll a job's progress file until it reaches a terminal state.
     * @param {string} jobId
     * @param {function} onTick  callback(data)
     * @param {function} onDone  callback(data)
     */
    function pollProgress(jobId, onTick, onDone) {
        var url = AJAX_URL + '?op=progress&job=' + encodeURIComponent(jobId);
        var timer = setInterval(function () {
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    onTick(data);
                    if (data.state === 'done' || data.state === 'error') {
                        clearInterval(timer);
                        onDone(data);
                    }
                })
                .catch(function () { /* transient errors are ignored while polling */ });
        }, 1500);
    }

    /**
     * Update a Bootstrap progress bar element.
     * @param {HTMLElement} bar
     * @param {number} pct
     */
    function setBar(bar, pct) {
        if (!bar) { return; }
        pct = Math.max(0, Math.min(100, parseInt(pct, 10) || 0));
        bar.style.width = pct + '%';
        bar.textContent = pct + '%';
    }

    // -----------------------------------------------------------------------
    // Create backup page.
    // -----------------------------------------------------------------------
    function initCreateBackup() {
        var btn = document.getElementById('sp-start-backup');
        if (!btn) { return; }
        var csrf = btn.getAttribute('data-csrf');

        btn.addEventListener('click', function () {
            btn.disabled = true;
            document.getElementById('sp-progress-wrap').style.display = 'block';
            var bar = document.getElementById('sp-progress-bar');
            var msg = document.getElementById('sp-progress-msg');
            var log = document.getElementById('sp-progress-log');
            var result = document.getElementById('sp-result');

            post('create_backup', {}, csrf).then(function (resp) {
                if (!resp.ok || !resp.job) {
                    msg.textContent = resp.error || 'Failed to start backup.';
                    btn.disabled = false;
                    return;
                }
                pollProgress(resp.job, function (d) {
                    setBar(bar, d.percent);
                    msg.textContent = d.message || '';
                    if (d.message) {
                        log.textContent += d.message + '\n';
                        log.scrollTop = log.scrollHeight;
                    }
                }, function (d) {
                    if (d.state === 'done') {
                        result.style.display = 'block';
                        result.className = 'sp-result alert alert-success';
                        result.textContent = 'Backup completed successfully.';
                    } else {
                        result.style.display = 'block';
                        result.className = 'sp-result alert alert-danger';
                        result.textContent = 'Backup failed: ' + (d.message || 'unknown error');
                    }
                    btn.disabled = false;
                });
            }).catch(function (e) {
                msg.textContent = 'Error: ' + e;
                btn.disabled = false;
            });
        });
    }

    // -----------------------------------------------------------------------
    // Restore wizard state machine.
    // -----------------------------------------------------------------------
    function initRestoreWizard() {
        var root = document.getElementById('sp-restore');
        if (!root) { return; }
        var csrf = root.getAttribute('data-csrf');

        var steps = Array.prototype.slice.call(root.querySelectorAll('.sp-step'));
        var panels = Array.prototype.slice.call(root.querySelectorAll('.sp-panel'));
        var order = ['select', 'verify', 'safety', 'scope', 'confirm', 'execute', 'report'];
        var current = 0;
        var state = { snapshot: null, scope: 'full', verified: false, safety: false };

        function show(index) {
            current = index;
            panels.forEach(function (p) {
                p.style.display = (p.getAttribute('data-panel') === order[index]) ? 'block' : 'none';
            });
            steps.forEach(function (s, i) {
                s.classList.remove('sp-step-active', 'sp-step-done');
                if (i < index) { s.classList.add('sp-step-done'); }
                else if (i === index) { s.classList.add('sp-step-active'); }
            });
        }

        // Step 1: snapshot selection enables Next.
        root.querySelectorAll('.sp-select-snapshot').forEach(function (radio) {
            radio.addEventListener('change', function () {
                state.snapshot = this.value;
                var next = panels[0].querySelector('.sp-next');
                if (next) { next.disabled = false; }
            });
        });

        // Delete buttons (Step 1).
        root.querySelectorAll('.sp-delete-snapshot').forEach(function (b) {
            b.addEventListener('click', function () {
                if (!confirm('Delete snapshot ' + this.getAttribute('data-snapshot') + '?')) { return; }
                var id = this.getAttribute('data-snapshot');
                var row = this.closest('tr');
                post('delete_snapshot', { snapshot: id }, csrf).then(function (r) {
                    if (r.ok && row) { row.parentNode.removeChild(row); }
                    else { alert(r.error || 'Delete failed.'); }
                });
            });
        });

        // Generic Back buttons.
        root.querySelectorAll('.sp-back').forEach(function (b) {
            b.addEventListener('click', function () { if (current > 0) { show(current - 1); } });
        });

        // Generic Next buttons (used by select & scope panels).
        root.querySelectorAll('.sp-next').forEach(function (b) {
            b.addEventListener('click', function () {
                // Capture scope when leaving the scope panel.
                if (order[current] === 'scope') {
                    var sel = root.querySelector('input[name="sp_scope"]:checked');
                    state.scope = sel ? sel.value : 'full';
                    loadConfirmSummary();
                }
                if (current < order.length - 1) { show(current + 1); }
            });
        });

        // Step 2: verify.
        var verifyRun = root.querySelector('.sp-verify-run');
        if (verifyRun) {
            verifyRun.addEventListener('click', function () {
                var box = document.getElementById('sp-verify-status');
                box.textContent = 'Verifying…';
                post('restore_verify', { snapshot: state.snapshot }, csrf).then(function (r) {
                    if (r.ok && r.result) {
                        box.innerHTML = (r.result.ok ? '<span class="sp-ok">✔ </span>' : '<span class="sp-fail">✖ </span>')
                            + r.result.message + '<br><small>SHA-256: ' + r.result.checksum + '</small>';
                        var next = panels[1].querySelector('.sp-next');
                        if (next && r.result.ok) { next.disabled = false; }
                        state.verified = !!r.result.ok;
                    } else {
                        box.innerHTML = '<span class="sp-fail">✖ </span>' + (r.error || 'Verification failed.');
                    }
                });
            });
        }

        // Step 3: safety backup.
        var safetyRun = root.querySelector('.sp-safety-run');
        if (safetyRun) {
            safetyRun.addEventListener('click', function () {
                var bar = document.getElementById('sp-safety-bar');
                var msg = document.getElementById('sp-safety-msg');
                safetyRun.disabled = true;
                post('restore_safety', {}, csrf).then(function (resp) {
                    if (!resp.ok || !resp.job) { msg.textContent = resp.error || 'Failed to start.'; safetyRun.disabled = false; return; }
                    pollProgress(resp.job, function (d) {
                        setBar(bar, d.percent);
                        msg.textContent = d.message || '';
                    }, function (d) {
                        if (d.state === 'done') {
                            msg.textContent = 'Safety backup complete.';
                            var next = panels[2].querySelector('.sp-next');
                            if (next) { next.disabled = false; }
                            state.safety = true;
                        } else {
                            msg.textContent = 'Safety backup failed: ' + (d.message || '');
                            safetyRun.disabled = false;
                        }
                    });
                });
            });
        }

        // Step 5: load confirmation summary.
        function loadConfirmSummary() {
            var box = document.getElementById('sp-confirm-summary');
            if (!box) { return; }
            box.textContent = 'Loading summary…';
            post('restore_confirm', { snapshot: state.snapshot, scope: state.scope }, csrf).then(function (r) {
                if (r.ok && r.summary) {
                    var html = '<strong>Snapshot:</strong> ' + r.summary.snapshot_id + '<br>'
                        + '<strong>Created:</strong> ' + r.summary.created_at + '<br>'
                        + '<strong>Scope:</strong> ' + r.summary.scope + '<hr>';
                    (r.summary.targets || []).forEach(function (t) {
                        html += '<div>• ' + t.detail + '</div>';
                    });
                    box.innerHTML = html;
                } else {
                    box.textContent = r.error || 'Failed to build summary.';
                }
            });
        }

        // Confirmation checkbox enables the execute button.
        var confirmCheck = document.getElementById('sp-confirm-check');
        if (confirmCheck) {
            confirmCheck.addEventListener('change', function () {
                var exec = root.querySelector('.sp-execute-run');
                if (exec) { exec.disabled = !this.checked; }
            });
        }

        // Step 6: execute restore.
        var executeRun = root.querySelector('.sp-execute-run');
        if (executeRun) {
            executeRun.addEventListener('click', function () {
                show(order.indexOf('execute'));
                var bar = document.getElementById('sp-restore-bar');
                var msg = document.getElementById('sp-restore-msg');
                var log = document.getElementById('sp-restore-log');
                post('restore_execute', { snapshot: state.snapshot, scope: state.scope }, csrf).then(function (resp) {
                    if (!resp.ok || !resp.job) { msg.textContent = resp.error || 'Failed to start restore.'; return; }
                    pollProgress(resp.job, function (d) {
                        setBar(bar, d.percent);
                        msg.textContent = d.message || '';
                        if (d.log && Array.isArray(d.log)) {
                            log.textContent = d.log.join('\n');
                            log.scrollTop = log.scrollHeight;
                        }
                    }, function (d) {
                        renderReport(d);
                        show(order.indexOf('report'));
                    });
                });
            });
        }

        // Step 7: report.
        function renderReport(d) {
            var box = document.getElementById('sp-report');
            if (!box) { return; }
            var ok = d.state === 'done';
            var html = '<div class="alert alert-' + (ok ? 'success' : 'danger') + '">'
                + (ok ? '✔ Restore completed successfully.' : '✖ ' + (d.message || 'Restore failed.'))
                + '</div>';
            if (d.verification) {
                Object.keys(d.verification).forEach(function (k) {
                    var v = d.verification[k];
                    html += '<div>' + (v.ok ? '<span class="sp-ok">✔</span>' : '<span class="sp-fail">✖</span>')
                        + ' <strong>' + k + ':</strong> ' + v.message + '</div>';
                });
            }
            if (d.log && Array.isArray(d.log)) {
                html += '<div class="sp-progress-log" style="margin-top:12px;">' + d.log.join('\n') + '</div>';
            }
            box.innerHTML = html;
        }

        show(0);
    }

    // -----------------------------------------------------------------------
    // Settings page.
    // -----------------------------------------------------------------------
    function initSettings() {
        var btn = document.getElementById('sp-test-gdrive');
        if (!btn) { return; }
        var root = btn.closest('.snapshot-pro');
        var csrf = root.getAttribute('data-csrf');

        btn.addEventListener('click', function () {
            var result = document.getElementById('sp-gdrive-result');
            result.textContent = 'Testing…';
            result.className = 'sp-inline-result';
            var json = document.getElementById('gdrive_service_account').value;
            var folder = document.getElementById('gdrive_folder_id').value;
            post('test_gdrive', { gdrive_service_account: json, gdrive_folder_id: folder }, csrf).then(function (r) {
                if (r.ok) {
                    result.textContent = '✔ ' + r.message;
                    result.className = 'sp-inline-result sp-ok';
                } else {
                    result.textContent = '✖ ' + (r.error || 'Failed');
                    result.className = 'sp-inline-result sp-fail';
                }
            });
        });
    }

    // Public API.
    return {
        humanBytes: humanBytes,
        formatByteCells: formatByteCells,
        initCreateBackup: initCreateBackup,
        initRestoreWizard: initRestoreWizard,
        initSettings: initSettings
    };
})();
