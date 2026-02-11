# Configuration Reference

## PHP Configuration (`web/config.php`)

This file is **gitignored** — it contains live credentials. Created by copying `config.example.php`.

### Database

| Constant | Example | Description |
|----------|---------|-------------|
| `DB_HOST` | `crowdskyo92.mysql.univie.ac.at` | MariaDB hostname. On univie, this is `<dbname>.mysql.univie.ac.at` |
| `DB_NAME` | `crowdskyo92` | Database name (shown after creating at Webdatenbank verwalten) |
| `DB_USER` | `crowdskyo92` | Database user (same as u:account or webspace account) |
| `DB_PASS` | `(secret)` | Database password (set at Webdatenbank verwalten, must differ from u:account password) |

### u:cloud WebDAV

| Constant | Example | Description |
|----------|---------|-------------|
| `UCLOUD_WEBDAV_URL` | `https://ucloud.univie.ac.at/public.php/webdav` | WebDAV endpoint for Nextcloud public shares. This is the same for all u:cloud shares. |
| `UCLOUD_SHARE_TOKEN` | `ELBci3d9eqyRBHp` | The token from the share URL (`/s/TOKEN`). Authenticate as `(TOKEN, "")` — token as username, empty password. |
| `UCLOUD_BASE_PATH` | `/crowdsky` | Base directory within the share. Created manually. |

**How to get a share token:**
1. Go to https://ucloud.univie.ac.at/
2. Right-click a folder → Share → Share link
3. The URL will be like `https://ucloud.univie.ac.at/index.php/s/ELBci3d9eqyRBHp`
4. The token is `ELBci3d9eqyRBHp`

### Worker API

| Constant | Example | Description |
|----------|---------|-------------|
| `WORKER_API_KEY` | `52e4cb09...` | 64-character hex string shared between PHP and the Python worker. Generate with `python -c "import secrets; print(secrets.token_hex(32))"` |

### Local Storage

| Constant | Default | Description |
|----------|---------|-------------|
| `UPLOAD_DIR` | `__DIR__ . '/uploads'` | Absolute path to the directory where raw uploads are temporarily stored. Must be writable by PHP. |
| `UPLOAD_EXPIRY_HOURS` | `24` | Hours before abandoned upload sessions are cleaned up by `cleanup.php` |

### Upload Limits

| Constant | Default | Description |
|----------|---------|-------------|
| `MAX_FILE_SIZE` | `50 * 1024 * 1024` (50 MB) | Maximum size per uploaded file. Seestar raws are typically ~4 MB. Set high to allow other telescope formats. |
| `ALLOWED_EXTENSIONS` | `['fit', 'fits']` | Accepted file extensions. Case-insensitive. |

### Session & Site

| Constant | Default | Description |
|----------|---------|-------------|
| `SESSION_LIFETIME` | `3600` (1 hour) | PHP session lifetime in seconds |
| `SITE_URL` | `https://crowdsky.univie.ac.at` | Base URL of the site. Used for generating links. |
| `SITE_NAME` | `CrowdSky` | Displayed in the UI |

---

## Python Worker Configuration (`worker/.env`)

This file is **gitignored**. Created by copying `.env.example`.

| Variable | Example | Description |
|----------|---------|-------------|
| `API_BASE_URL` | `https://crowdsky.univie.ac.at/api` | URL to the PHP API directory. Must end with `/api` (no trailing slash). |
| `WORKER_API_KEY` | `52e4cb09...` | Must match `WORKER_API_KEY` in PHP `config.php` |
| `UCLOUD_WEBDAV_URL` | `https://ucloud.univie.ac.at/public.php/webdav` | Same as PHP config |
| `UCLOUD_SHARE_TOKEN` | `ELBci3d9eqyRBHp` | Same as PHP config |
| `UCLOUD_BASE_PATH` | `/crowdsky` | Same as PHP config |
| `WORKER_ID` | `worker-01` | Identifier stored in `stacking_jobs.worker_id`. Useful for debugging with multiple workers. |
| `POLL_INTERVAL` | `30` | Seconds to wait between API polls when no jobs are available |
| `WORK_DIR` | `./tmp` | Local directory for temporary files during processing. Created automatically. Cleaned up after each job. |

---

## PHP `php.ini` / Server Settings

You may need to adjust these on the webspace (if configurable via `.user.ini` or the hosting panel):

| Setting | Recommended | Why |
|---------|-------------|-----|
| `upload_max_filesize` | `50M` | Must be >= `MAX_FILE_SIZE` in config |
| `post_max_size` | `55M` | Must be > `upload_max_filesize` |
| `max_execution_time` | `120` | File uploads + FITS parsing can take time |
| `memory_limit` | `128M` | Should be fine for header parsing |

If the univie webspace allows `.user.ini` files, create one in the document root:
```ini
upload_max_filesize = 50M
post_max_size = 55M
max_execution_time = 120
```

---

## Keeping Configs in Sync

The PHP and Python sides share three values that must match:

| Value | PHP (`config.php`) | Python (`worker/.env`) |
|-------|-------|--------|
| Worker API key | `WORKER_API_KEY` | `WORKER_API_KEY` |
| u:cloud token | `UCLOUD_SHARE_TOKEN` | `UCLOUD_SHARE_TOKEN` |
| u:cloud base path | `UCLOUD_BASE_PATH` | `UCLOUD_BASE_PATH` |

If you change any of these, update both files.
