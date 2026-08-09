# WHMCS Snapshot Pro

**Professional disaster-recovery addon for WHMCS 9.x.** Create complete,
encrypted, point-in-time snapshots of your entire WHMCS installation
(database **+** files), store them locally or in Google Drive, schedule
automatic backups with a retention policy, and restore everything through a
guided, integrity-verified wizard.

---

## Table of Contents

1. [Features](#features)
2. [How It Works](#how-it-works)
3. [Prerequisites](#prerequisites)
4. [Installation](#installation)
5. [Configuration](#configuration)
6. [Usage](#usage)
   - [Creating a Backup](#creating-a-backup)
   - [Scheduled Backups](#scheduled-backups)
   - [Restoring a Snapshot](#restoring-a-snapshot)
7. [Storage Backends](#storage-backends)
   - [Local Storage](#local-storage)
   - [Google Drive](#google-drive)
8. [Security](#security)
9. [File Structure](#file-structure)
10. [Troubleshooting](#troubleshooting)
11. [FAQ](#faq)
12. [License](#license)

---

## Features

- **Complete point-in-time snapshots** — full database dump + full filesystem
  archive bundled into a single encrypted file.
- **AES-256-CBC encryption** with an HMAC-SHA256 authentication tag; the key
  never leaves your WHMCS configuration and is never stored inside the archive.
- **SHA-256 integrity verification** for every snapshot, checked automatically
  before any restore begins.
- **Storage backends**: local server storage and Google Drive (via a service
  account). Selectable globally in settings.
- **Scheduled automatic backups** (daily / weekly / monthly) queued by the
  WHMCS daily cron and executed by the dedicated Snapshot Pro CLI worker, with
  a configurable **retention policy** (keep last *N*).
- **Guided restore wizard** — a 7-step, AJAX-driven flow: select → verify →
  safety backup → scope → confirm → execute (live log) → report.
- **Full audit log** of every backup, restore and settings change.
- **Security hardened** — Full Administrator only, CSRF-protected forms,
  escaped output, protected storage directory.
- **Zero external Composer dependencies** — uses only what WHMCS ships plus
  standard PHP extensions (`openssl`, `zlib`, `phar`/`zip`, `curl`).

---

## How It Works

A snapshot is produced in the following pipeline:

```
┌─────────────┐   ┌──────────────┐   ┌─────────┐   ┌────────────┐   ┌──────────┐   ┌──────────┐
│ DB mysqldump│ + │ Filesystem   │ → │ bundle  │ → │ AES-256    │ → │ SHA-256  │ → │ Storage  │
│ (.sql.gz)   │   │ tar.gz       │   │ (.tar)  │   │ encrypt    │   │ checksum │   │ backend  │
└─────────────┘   └──────────────┘   └─────────┘   └────────────┘   └──────────┘   └──────────┘
```

The encrypted archive (`*.spx`) contains `database.sql.gz`,
`filesystem.tar.gz` and a `manifest.json` describing the snapshot. Restores
reverse the pipeline: download → verify checksum → decrypt (with HMAC auth) →
extract → restore DB and/or files.

---

## Prerequisites

- **WHMCS 9.x** (also compatible with recent 8.x releases).
- **PHP 8.0+** with the following extensions enabled:
  - `openssl` (encryption + Google JWT signing)
  - `zlib` (gzip compression)
  - `phar` **or** `zip` (filesystem archiving)
  - `curl` (Google Drive API)
  - `pdo_mysql` (database access — already required by WHMCS)
- Shell access is **optional**. If `exec()` and `mysqldump`/`mysql` are
  available they are used for faster, more reliable database dumps; otherwise
  the module falls back to a pure-PHP dump/restore.
- Sufficient free disk space in the system temp directory and the storage path
  to hold at least one full snapshot.
- For Google Drive: a **Google Cloud service account** with a JSON key and
  access to the destination Drive folder.

---

## Installation

1. **Copy the module files** into your WHMCS installation so the directory
   layout is:

   ```
   <whmcs_root>/modules/addons/snapshot_pro/
   ```

   You can do this by cloning this repository and copying the
   `modules/addons/snapshot_pro` directory, or by uploading the folder via
   FTP/SFTP.

   ```bash
   git clone https://github.com/bampu79/whmcs-snapshot-pro.git
   cp -r whmcs-snapshot-pro/modules/addons/snapshot_pro \
         /path/to/whmcs/modules/addons/
   ```

2. **Set permissions** so the web-server user can write the storage directory
   and read the WHMCS root:

   ```bash
   chown -R www-data:www-data /path/to/whmcs/modules/addons/snapshot_pro
   chmod -R 750 /path/to/whmcs/modules/addons/snapshot_pro/storage
   ```

3. **Activate the module** in the WHMCS admin area:

   `Setup → Addon Modules → WHMCS Snapshot Pro → Activate`

   On activation the module automatically:
   - creates its database tables (`mod_snapshot_pro_snapshots`,
     `mod_snapshot_pro_settings`, `mod_snapshot_pro_logs`),
   - seeds default settings, and
   - generates a strong AES-256 encryption key.

4. **Grant access** to the appropriate admin role. All actions are restricted
   to the **Full Administrator** role by design.

---

## Configuration

Open **Addons → WHMCS Snapshot Pro → Settings** and configure:

| Setting | Description |
|---|---|
| **Storage backend** | `Local Server Storage` or `Google Drive`. |
| **Local storage path** | Absolute path for archives. Blank = module's `storage/` dir. |
| **Google Drive Service Account JSON** | Full JSON key of a service account. |
| **Google Drive Folder ID** | Optional target folder for uploads. |
| **Schedule** | `Daily`, `Weekly` (Mondays), `Monthly` (1st) or `Disabled`. |
| **Retention** | Keep the last *N* snapshots; older ones are pruned. `0` = keep all. |
| **Backup scope** | Toggle database and/or filesystem inclusion. |
| **Encryption key** | AES-256 key. Auto-generated on activation. **Back this up!** |

> ⚠️ **Store your encryption key somewhere safe and separate from the server.**
> Snapshots **cannot** be restored without it.

---

## Usage

### Creating a Backup

1. Go to **Create Backup**.
2. Review the scope and storage summary.
3. Click **Start Backup Now**. The request only **queues** a job and returns
   immediately; a live progress bar polls while the dedicated CLI worker runs
   each stage (database → files → bundle → encrypt → checksum → upload).
4. On completion the snapshot appears on the **Dashboard**.

### Snapshot Pro CLI Worker (required)

Long-running snapshot work must **not** run inside the normal WHMCS automation
cron. Schedule the module worker separately (adjust the PHP binary and WHMCS
path for your server):

```bash
*/5 * * * * /usr/bin/php -q /path/to/whmcs/modules/addons/snapshot_pro/cron.php >/dev/null 2>&1
```

This script is CLI-only. It claims at most one queued job per run and executes
`SnapshotManager::create()` in that isolated process.

### Scheduled Backups

When a backup is due, the WHMCS **DailyCronJob** hook only **enqueues** a job
(it never runs the backup pipeline). The Snapshot Pro CLI worker above then
processes it. Ensure both the WHMCS system cron and the Snapshot Pro worker
cron are configured. The module decides whether a backup is due based on your
schedule and the last successful backup time, so the daily hook will not stack
duplicate scheduled jobs while one is already queued or running.

### Restoring a Snapshot

Open **Restore** and follow the wizard:

1. **Select** a completed snapshot.
2. **Integrity Check** — the archive's SHA-256 checksum is verified. Restore is
   blocked if it fails.
3. **Safety Backup** — a pre-restore snapshot of the current state is created.
4. **Scope** — choose Full, Database only, or Files only.
5. **Confirmation** — review exactly what will be overwritten and acknowledge.
6. **Restore** — watch the live log as the DB and/or files are restored.
7. **Report** — post-restore verification (DB reachable, WHMCS root readable).

---

## Storage Backends

### Local Storage

Archives are written to the configured path (default:
`modules/addons/snapshot_pro/storage`). The directory is automatically
protected with `.htaccess` (`Deny from all`) and an empty `index.html`. For
best safety, point this to a location **outside** the web root.

### Google Drive

The module talks to the **Google Drive REST API v3** directly (no SDK needed),
authenticating with a **service account**:

1. In the [Google Cloud Console](https://console.cloud.google.com/), create a
   project and enable the **Google Drive API**.
2. Create a **Service Account** and generate a **JSON key**.
3. Share the destination Drive folder with the service account's
   `client_email` (grant *Editor*), and copy the folder's ID from its URL.
4. Paste the JSON key and folder ID into the module **Settings** and click
   **Test Google Drive Connection** to confirm authentication.

Uploads use the Drive `multipart` upload endpoint; the returned file ID is
stored as the snapshot's storage reference.

---

## Security

- **Encryption:** AES-256-CBC with per-file random IV and an HMAC-SHA256
  authentication tag. Decryption verifies the HMAC **before** trusting the
  data, detecting wrong keys and tampering.
- **Key handling:** the key lives only in the module settings table and is
  never written into an archive.
- **Access control:** every page and AJAX operation requires an authenticated
  **Full Administrator**.
- **CSRF:** all state-changing requests require a per-session token.
- **Credential safety:** `mysqldump`/`mysql` credentials are passed through a
  `chmod 600` temporary defaults-file, never on the command line.
- **Directory hardening:** the local storage directory denies direct web
  access.

---

## File Structure

```
modules/addons/snapshot_pro/
├── snapshot_pro.php          # Addon entry point (config/activate/deactivate/output)
├── hooks.php                 # DailyCronJob enqueues scheduled backups only
├── cron.php                  # Dedicated CLI worker (claims & runs queued jobs)
├── autoload.php              # PSR-4 autoloader for SnapshotPro\ namespace
├── lang/
│   └── english.php           # Language strings
├── lib/
│   ├── SnapshotManager.php   # Core orchestration: create/list/delete/retention
│   ├── JobQueue.php          # Persistent job queue + atomic claim
│   ├── DatabaseBackup.php    # mysqldump + PHP-fallback DB dump/restore
│   ├── FilesystemBackup.php  # PharData/ZipArchive file archiving
│   ├── Encryption.php        # AES-256-CBC encrypt/decrypt (+ HMAC)
│   ├── Integrity.php         # SHA-256 checksum generation & verification
│   ├── StorageInterface.php  # Storage backend contract
│   ├── StorageLocal.php      # Local filesystem backend
│   ├── StorageGoogleDrive.php# Google Drive REST API v3 backend
│   ├── StorageFactory.php    # Backend factory
│   ├── RestoreWizard.php     # Multi-step restore orchestration
│   ├── Settings.php          # Key/value settings store
│   └── Logger.php            # Audit log writer/reader
├── templates/
│   ├── dashboard.tpl
│   ├── create_backup.tpl
│   ├── restore_wizard.tpl
│   ├── settings.tpl
│   └── logs.tpl
├── assets/
│   ├── css/snapshot_pro.css
│   └── js/snapshot_pro.js
├── ajax/
│   └── handler.php           # JSON endpoint (progress + wizard steps)
└── storage/                  # Default local archive directory (auto-created)
```

---

## Troubleshooting

**"No encryption key configured" when creating a snapshot**
Set a key in **Settings**. One is generated automatically on activation, so
this usually means the key field was cleared.

**Backup fails immediately with a permissions error**
Ensure the web-server user can write to the storage path and the system temp
directory, and can read the WHMCS root.

**`mysqldump` not used / slow PHP dump**
If `exec()` is disabled by your host or `mysqldump` is not installed, the
module transparently falls back to a pure-PHP dump. This is normal on
restricted shared hosting and still produces valid backups.

**Google Drive test fails**
- Confirm the **Drive API** is enabled in your Google Cloud project.
- Confirm the destination folder is **shared with the service account email**.
- Check the server can reach `googleapis.com` (outbound HTTPS / no firewall).

**Restore integrity check fails**
The archive is corrupt, incomplete, or the encryption key does not match the
one used to create it. Restore is intentionally blocked in this case.

**Progress bar never completes for large sites**
Long backups rely on the server not killing the PHP process. On FastCGI
(`php-fpm`) the work continues in the background after the request returns. On
other SAPIs increase `max_execution_time` / `memory_limit` for the admin area.

---

## FAQ

**Does deactivating the module delete my backups?**
No. Deactivation drops only the module's database tables. Stored archives are
left untouched.

**Are backups incremental?**
No. Each snapshot is a complete, self-contained point-in-time image.

**Can I download a snapshot manually?**
Yes — local snapshots are `*.spx` files in your storage directory. They are
encrypted; decrypt them with the module (via a restore) using your key.

**What is excluded from filesystem backups?**
`templates_c`, `cache`, `temp`, `attachments/tmp`, the module's own `storage/`
directory, and `.git` — to keep archives lean and avoid backing up backups.

---

## License

Released under the **MIT License**. See below.

```
MIT License

Copyright (c) 2026 bampu79

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```
