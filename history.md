# CrowdSky Development History

## 2026-02-10 — Initial Implementation (DB schema + PHP skeleton + upload page + worker)

Created the full project skeleton in one session. 28 new files across PHP web app and Python worker.

### Scaffolding
- **`.gitignore`** — ignores `config.php`, `.env`, `vendor/`, `__pycache__/`, IDE files
- **`schema.sql`** — all 5 tables: `users`, `upload_sessions`, `raw_files`, `stacking_jobs`, `stacked_frames`
- **`web/config.example.php`** — template with DB, u:cloud, and worker API key placeholders
- **`web/db.php`** — PDO singleton with `getDb()`

### Core PHP Utilities
- **`web/fits_utils.php`** — `parseFitsHeader()` reads 80-byte cards from FITS blocks, validates magic bytes; `computeChunkKey()` produces `YYYYMMDD.CC_RRR.R_sDD.D` format
- **`web/webdav.php`** — `mkcolUcloud()` (recursive), `uploadToUcloud()`, `uploadDataToUcloud()`, `deleteFromUcloud()` using cURL

### Auth + Templates
- **`web/auth.php`** — `registerUser()`, `loginUser()`, `logoutUser()`, `requireLogin()`, `csrfToken()`, `csrfValidate()` with bcrypt
- **`web/templates/header.php`** — nav bar with conditional login/upload/stacks links
- **`web/templates/footer.php`** — closing HTML
- **`web/assets/css/style.css`** — dark theme with cards, upload zone, progress bar, badges, tables

### Pages
- **`web/index.php`** — login/register forms, redirects to upload if logged in
- **`web/upload.php`** — drag-and-drop UI with JS that POSTs `action=start` then `action=file` for each .fit, saves to local webspace disk, parses FITS headers, inserts DB rows
- **`web/finalize.php`** — groups raw_files by `chunk_key`, creates `stacking_jobs`
- **`web/status.php`** — lists upload sessions and per-session job statuses
- **`web/stacks.php`** — browse completed stacks filtered by object, with download links
- **`web/download.php`** — proxy download from u:cloud (hides WebDAV token)

### Worker API (6 endpoints, Bearer-token auth)
- **`web/api/next_job.php`** — `FOR UPDATE SKIP LOCKED` atomic claim, checks pending then retry
- **`web/api/job_files.php`** — returns raw file paths for a job's chunk_key
- **`web/api/download_raw.php`** — streams a raw FITS file from local webspace to the worker
- **`web/api/complete_job.php`** — inserts `stacked_frames` row, marks job completed, deletes local raws
- **`web/api/fail_job.php`** — marks job failed/retry with error message
- **`web/api/cleanup.php`** — cron endpoint: expires abandoned sessions, deletes leftover local raws after 24h

### Python Worker (runs on any machine, communicates via HTTP API + WebDAV)
- **`worker/config.py`** — loads from `.env`
- **`worker/api_client.py`** — HTTP client for all 4 PHP API endpoints
- **`worker/webdav.py`** — download/upload/mkcol/delete on u:cloud
- **`worker/stacking_adapter.py`** — wraps `seestarpy.FrameCollection.process()` + `.save()`
- **`worker/thumbnail.py`** — FITS to PNG via percentile stretch + Pillow
- **`worker/job_processor.py`** — full pipeline: download raws from webspace → stack → upload to u:cloud → report
- **`worker/main.py`** — daemon mode (poll loop) or `--once` for cron

### Architecture
```
Browser → upload.php → local webspace disk (50 GB buffer for raw .fit files)
                     → MariaDB (sessions, raw_files, stacking_jobs)

Python worker → GET /api/next_job.php (claim job)
             → GET /api/job_files.php (file list)
             → GET /api/download_raw.php (download raws from webspace)
             → stack via seestarpy
             → upload stack + thumbnail to u:cloud (250 GB permanent storage)
             → POST /api/complete_job.php (report metadata, PHP deletes local raws)
```

---

## 2026-02-10 — Use webspace as raw buffer, u:cloud for stacks only

Discovered the uni webspace has 50 GB of local disk. Refactored so raw uploads
go to local disk instead of u:cloud WebDAV — faster uploads (no WebDAV round-trip
per file) and keeps u:cloud clean as purely archival storage for finished stacks.

### Changes
- **`web/upload.php`** — `move_uploaded_file()` to local disk instead of cURL PUT to u:cloud
- **`web/config.example.php`** — added `UPLOAD_DIR` and `UPLOAD_EXPIRY_HOURS` settings
- **`web/api/download_raw.php`** — new endpoint: streams local raw files to the worker
- **`web/api/complete_job.php`** — now deletes local raw files and cleans up empty session dirs
- **`web/api/cleanup.php`** — new cron endpoint: expires abandoned sessions after 24h
- **`worker/api_client.py`** — added `download_raw_file()` to fetch raws via PHP API
- **`worker/job_processor.py`** — downloads from webspace API instead of u:cloud; removed u:cloud raw deletion (PHP handles it)

### Storage split
| Storage | Contents | Lifecycle |
|---------|----------|-----------|
| Webspace (50 GB) | Raw .fit uploads | Temporary — deleted after stacking or after 24h |
| u:cloud (250 GB) | Stacked FITS + thumbnails | Permanent |
