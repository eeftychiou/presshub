# PressHub backups and restore (#296)

From 2.53.0, PressHub takes a backup every time WordPress is about to replace the plugin: **Plugins → Update**, bulk updates, auto-updates, and **Upload Plugin → Replace current with uploaded**. The 2.53.0 update itself is still run by the older code, so the first update with a backup is the one after 2.53.0.

## What is in a backup

| Part | Where it comes from |
|---|---|
| Settings | every `wp_options` row whose name starts with `presshub_` (values exactly as stored, including API keys and tokens) |
| Database tables | every `{prefix}presshub_*` table: its `CREATE TABLE` and all rows (token and audit logs, generation records, memory, QA reviews, social queue) |
| Plugin files | a zip of `wp-content/plugins/presshub-ai-editor/` as it was before the update |

Posts, pages, media, users and other plugins are **not** included. Back those up with the host.

Backups are stored in `wp-content/presshub-backups/` (or the folder in `PRESSHUB_AI_BACKUP_DIR`, which can be outside the web root). The folder blocks web access with `.htaccess`, `web.config` and `index.php` (no backup is taken if those cannot be written), and each backup's folder name ends in a random suffix. nginx ignores `.htaccess`, so **PressHub → Backups** checks over HTTP whether the folder is served and shows a red warning if it is: then block it in the server config or set `PRESSHUB_AI_BACKUP_DIR` outside the web root. The newest 5 are kept (change it under **PressHub → Settings → Site & system → Logging & retention → Backups to keep**, 1 to 20).

If a backup cannot be taken (disk full, folder not writable, no zlib or ZipArchive), WordPress stops the update and shows why. The site stays on the old version. A host that must update without a backup can add `define( 'PRESSHUB_AI_SKIP_UPDATE_BACKUP', true );` to `wp-config.php`.

## Restore from wp-admin (the normal way)

1. Open **PressHub → Backups**. Restoring needs an administrator (the PressHub capability and `update_plugins`).
2. Find the backup marked **Before the update to X.Y.Z** for the update that went wrong and click **Restore…**.
3. Read the confirm screen. Leave **Also put back the plugin files** ticked to go back to the old version too. Untick it to restore only the settings and tables.
4. Click **Restore backup**. PressHub first reads the whole backup and stops, changing nothing, if any file is missing or damaged. Then it saves the current state as a new backup (**Before restoring …**), so the restore itself can be undone, reinstalls the saved plugin files through WordPress, and puts back the settings and tables.
5. Check **Plugins** shows the old version, and that **PressHub → Usage & logs** has no new errors.

## Restore when wp-admin does not load

If the new version breaks the site so badly that wp-admin does not open:

1. With the host's file manager, SFTP or SSH, open `wp-content/presshub-backups/` and the newest folder whose name contains the **old** version, for example `20261120-091500-v2.53.0-k3j9x2ab`. `manifest.json` in it says the version and `"reason": "update"`.
2. Delete (or rename) `wp-content/plugins/presshub-ai-editor/`, then unzip that backup's `plugin.zip` into `wp-content/plugins/`. It creates `presshub-ai-editor/` with the old files.
3. wp-admin now loads the old version. Open **PressHub → Backups**, pick the same backup and restore it with **Also put back the plugin files** unticked, to bring back the settings and tables as they were before the update.

## Tested

- `presshub-ai-editor/tests/UpdateBackupTest.php`: which updates are backed up, ids, the zip layout, listing, pruning, link detection and the page.
- End to end on a scratch WordPress 6.8.3 with SQLite: a real `Plugin_Upgrader::upgrade()` from 2.53.0 to a 2.53.1 package took the backup (450 audit rows, all options, the plugin zip). After a simulated bad migration (an option changed, an option added, half the rows deleted, a column added, a table dropped), the restore brought back every option and row byte for byte (Greek text included), the old table definitions, the dropped table and the 2.53.0 files, with the plugin still active. Pruning kept the configured number.
- Round 2 on the same site: six 700 KB rows came back through size-capped INSERTs; a backup with a table file cut in half was refused with nothing changed and no extra backup taken; the web check reported the folder as served by `php -S` (which ignores `.htaccess`).
- The local dev env (`dev-env/`): its plugin folder is a junction to the git checkout, so a restore there puts back settings and tables only and refuses to write plugin files into the repository.
