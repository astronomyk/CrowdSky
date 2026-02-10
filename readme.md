# CrowdSky - Cloud Stacking Service for Seestar Telescopes

## Context

Seestar S50 users accumulate large volumes of raw FITS files they rarely need after stacking. CrowdSky offers a service: upload your raws, get 15-minute stacked frames stored for free. Finer granularity (3-min, 1-min, raw preservation) is a paid tier. The operator (an astronomer) benefits from time-domain astronomy data at 15-minute cadence.

**Proof of concept goal:** PHP frontend on University of Vienna webspace + MariaDB for users/jobs + u:cloud (250GB Nextcloud/WebDAV) for file storage + Python worker for stacking.

**Tonight's goal:** Database schema + PHP project skeleton + basic upload page.

---

## Architecture

```
[Browser]                    [PHP on univie.ac.at]           [u:cloud WebDAV 250GB]
   |                              |                                |
   +-- upload .fit files -------->|                                |
   |                              +-- stream PUT to u:cloud ------>|
   |                              +-- INSERT job in MariaDB        |
   |                              |                                |
   |                              |    [Python Worker]             |
   |                              |    (any machine w/ cron)       |
   |                              |         |                      |
   |                              |<-- GET /api/worker/next-job    |
   |                              |         |                      |
   |                              |         +-- GET raws --------->|
   |                              |         +-- stack (seestarpy)  |
   |                              |         +-- PUT stack -------->|
   |                              |         +-- DELETE raws ------>|
   |                              |<-- POST /api/worker/complete   |
   |                              |                                |
   +-- view stacks <--------------|-- query MariaDB + proxy u:cloud|
```

**Key design decisions:**
- **Database as message bus.** PHP creates stacking jobs; Python worker polls for them via HTTP API on the PHP side. No direct DB access from the worker needed.
- **PHP parses FITS headers.** FITS headers are simple 80-byte ASCII cards. PHP reads DATE-OBS, OBJECT, RA, DEC, EXPTIME from the first header block — no scientific library needed.
- **Stacking stays in Python.** The existing `seestarpy` package handles alignment (astroalign), debayering (OpenCV), sigma-clipped stacking, and FITS output. PHP cannot replicate this.
- **Worker is decoupled.** It communicates only through HTTP API + WebDAV. Can run on any machine: dev laptop, lab server, or later OpenShift/cloud.

---

## Existing Code to Reuse

| File | What it provides |
|------|-----------------|
| `D:\Repos\CrowdSky\upload.py` | WebDAV upload pattern: URL `https://ucloud.univie.ac.at/public.php/webdav`, token auth `(TOKEN, "")`, MKCOL for dirs, PUT for files |
| `E:\WHOPA\seestarpy\src\seestarpy\stacking\stacking.py` | `FrameCollection.process()` (line 524) — full pipeline: load, detect stars, align, debayer, stack, detect sources. `FrameCollection.save()` (line 480) writes multi-extension FITS |
| `E:\WHOPA\seestarpy\src\seestarpy\stacking\file_selection_utils.py` | `group_filenames_by_15min_chunk_ymd()` (line 23) — chunk key format `YYYYMMDD-CC`. `group_files_by_pointing_coords()` (line 90) for multi-target support |
| `E:\WHOPA\seestarpy\src\seestarpy\stacking\fits_header_utils.py` | `create_stacked_header()` (line 12), `make_stacked_rgb_fits()` (line 114) — proper FITS output format |
| `E:\WHOPA\seestarpy\pyproject.toml` | Installable package via `pip install -e .` — pulls numpy, opencv-python, astropy, astroalign, sep |

---

## Database Schema (MariaDB)

### `users`
```sql
CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(64) NOT NULL UNIQUE,
    email           VARCHAR(255) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    tier            ENUM('free','pro','raw') NOT NULL DEFAULT 'free',
    storage_used_mb DECIMAL(10,2) NOT NULL DEFAULT 0,
    storage_limit_mb DECIMAL(10,2) NOT NULL DEFAULT 500,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    api_token       VARCHAR(64) NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### `upload_sessions`
```sql
CREATE TABLE upload_sessions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    session_token   VARCHAR(64) NOT NULL UNIQUE,
    object_name     VARCHAR(255) NULL,
    ra_deg          DOUBLE NULL,
    dec_deg         DOUBLE NULL,
    file_count      INT UNSIGNED NOT NULL DEFAULT 0,
    total_size_mb   DECIMAL(10,2) NOT NULL DEFAULT 0,
    status          ENUM('uploading','complete','failed','expired') NOT NULL DEFAULT 'uploading',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at    DATETIME NULL,
    ucloud_path     VARCHAR(512) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### `raw_files`
```sql
CREATE TABLE raw_files (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    upload_session_id INT UNSIGNED NOT NULL,
    filename          VARCHAR(255) NOT NULL,
    ucloud_path       VARCHAR(512) NOT NULL,
    file_size_bytes   BIGINT UNSIGNED NOT NULL,
    fits_date_obs     DATETIME NULL,
    fits_object       VARCHAR(255) NULL,
    fits_exptime      FLOAT NULL,
    fits_ra           DOUBLE NULL,
    fits_dec          DOUBLE NULL,
    chunk_key         VARCHAR(32) NULL,
    is_deleted        TINYINT(1) NOT NULL DEFAULT 0,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (upload_session_id) REFERENCES upload_sessions(id) ON DELETE CASCADE,
    INDEX idx_session (upload_session_id),
    INDEX idx_chunk (upload_session_id, chunk_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### `stacking_jobs`
```sql
CREATE TABLE stacking_jobs (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id           INT UNSIGNED NOT NULL,
    upload_session_id INT UNSIGNED NOT NULL,
    chunk_key         VARCHAR(32) NOT NULL,
    object_name       VARCHAR(255) NULL,
    pointing_key      VARCHAR(32) NULL,
    frame_count       INT UNSIGNED NOT NULL DEFAULT 0,
    status            ENUM('pending','processing','completed','failed','retry') NOT NULL DEFAULT 'pending',
    worker_id         VARCHAR(64) NULL,
    started_at        DATETIME NULL,
    completed_at      DATETIME NULL,
    error_message     TEXT NULL,
    retry_count       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (upload_session_id) REFERENCES upload_sessions(id) ON DELETE CASCADE,
    INDEX idx_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### `stacked_frames`
```sql
CREATE TABLE stacked_frames (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stacking_job_id   INT UNSIGNED NOT NULL,
    user_id           INT UNSIGNED NOT NULL,
    object_name       VARCHAR(255) NULL,
    chunk_key         VARCHAR(32) NOT NULL,
    ucloud_path       VARCHAR(512) NOT NULL,
    thumbnail_path    VARCHAR(512) NULL,
    n_frames_input    INT UNSIGNED NOT NULL,
    n_frames_aligned  INT UNSIGNED NOT NULL,
    total_exptime     FLOAT NULL,
    date_obs_start    DATETIME NULL,
    date_obs_end      DATETIME NULL,
    ra_deg            DOUBLE NULL,
    dec_deg           DOUBLE NULL,
    file_size_bytes   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    n_stars_detected  INT UNSIGNED NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (stacking_job_id) REFERENCES stacking_jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_object (user_id, object_name),
    INDEX idx_user_date (user_id, date_obs_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## u:cloud File Organization

```
/crowdsky/
    raws/user_{id}/sess_{token}/         # temporary, deleted after stacking
        Seestar_20250115-193000.fit
        ...
    stacks/user_{id}/{object_name}/      # permanent
        stack_{chunk_key}_{job_id}.fits
        stack_{chunk_key}_{job_id}_thumb.png
```

---

## PHP Project Structure

Plain PHP for the MVP (no framework). Migrate to Slim later if needed.

```
D:\Repos\CrowdSky\
    schema.sql                   # All CREATE TABLE statements
    web/                         # Deploy to univie webspace document root
        index.php                # Landing page
        config.example.php       # Template (committed to git)
        config.php               # Real credentials (gitignored)
        db.php                   # PDO connection helper
        fits_utils.php           # parseFitsHeader(), computeChunkKey()
        webdav.php               # uploadToUcloud(), mkcolUcloud(), deleteFromUcloud()
        auth.php                 # Login/register handlers
        upload.php               # Upload form + POST handler
        finalize.php             # Finalize session, create stacking jobs
        status.php               # Job status page
        stacks.php               # Browse completed stacks
        download.php             # Proxy download from u:cloud
        api/                     # Worker API endpoints
            next_job.php         # GET - claim next pending job
            complete_job.php     # POST - mark job done, submit metadata
            fail_job.php         # POST - mark job failed
            job_files.php        # GET - list raw file paths for a job
        templates/
            header.php
            footer.php
        assets/
            css/style.css
    worker/                      # Python stacking worker
        config.py                # Env vars: DB host, WebDAV token, etc.
        api_client.py            # HTTP client for PHP worker API
        webdav.py                # WebDAV download/upload/delete
        job_processor.py         # Main processing logic
        stacking_adapter.py      # Wraps seestarpy FrameCollection
        thumbnail.py             # Generate PNG preview
        main.py                  # Entry point (cron or daemon mode)
        requirements.txt         # seestarpy, requests, pymysql, python-dotenv, Pillow
    upload.py                    # Existing WebDAV reference (keep for now)
    readme.md
    .gitignore
```

---

## Key Implementation Details

### PHP FITS Header Parser
FITS headers are 80-byte ASCII cards in 2880-byte blocks. PHP reads the first block(s) to extract DATE-OBS, OBJECT, RA, DEC, EXPTIME. No external library needed — just `fread()` and string parsing.

### Chunk Key Computation (must be identical in PHP and Python)
```
chunk_key = YYYYMMDD.CC_RRR.R_sDD.D
where:
 CC = floor(seconds_since_UTC_midnight / 900)   // 0..95
 RRR.R = Right Ascension of the chunk, rounded to 1 deciaml place
 sDD.D = Declication of the chunk, rounded to 1 decimal place, with the s = +/- for the sign of the declination coordinate 
```
PHP uses DATE-OBS, RA, DEC keywords from the FITS header (more reliable than filename parsing).

### Upload Flow
1. `POST /upload.php?action=start` → creates `upload_sessions` row + u:cloud MKCOL
2. `POST /upload.php?action=file` → streams each .fit to u:cloud via PUT, reads FITS header, inserts `raw_files` row
3. `POST /finalize.php` → groups files by chunk_key, creates `stacking_jobs`

### Worker API (PHP endpoints called by Python worker)
All authenticated with a shared `WORKER_API_KEY` Bearer token.
- `GET /api/next_job.php` — returns next pending job (claims it atomically via `FOR UPDATE SKIP LOCKED`)
- `GET /api/job_files.php?job_id=X` — returns raw file paths for a job
- `POST /api/complete_job.php` — marks job done, accepts stack metadata
- `POST /api/fail_job.php` — marks job failed with error message

### Security
- `password_hash()` with BCRYPT for user passwords
- FITS file validation: check magic bytes (`SIMPLE  =                    T`)
- Max file size: 50MB per file (Seestar raws are ~4MB)
- WebDAV token stored in `config.php` (gitignored), never exposed to browser
- Prepared statements (PDO) everywhere
- CSRF tokens on forms

---

## Tonight's Implementation Steps

### Step 1: Project scaffolding
- Create `.gitignore` (ignore `config.php`, `vendor/`, `__pycache__/`, `.env`)
- Create `schema.sql` with all table definitions
- Create `web/config.example.php` with placeholder values
- Create `web/db.php` (PDO connection helper)

### Step 2: Core PHP utilities
- Create `web/fits_utils.php` — `parseFitsHeader()` and `computeChunkKey()`
- Create `web/webdav.php` — `mkcolUcloud()`, `uploadToUcloud()`, `deleteFromUcloud()` using cURL (no Composer/Guzzle needed for MVP)

### Step 3: Upload page
- Create `web/templates/header.php` and `footer.php` (minimal HTML shell)
- Create `web/upload.php`:
  - GET: renders drag-and-drop upload form (HTML + JS)
  - POST `action=start`: creates upload session
  - POST `action=file`: receives file, reads FITS header, streams to u:cloud, inserts DB row
- Create `web/finalize.php`: groups by chunk_key, creates stacking jobs

### Step 4: Status page
- Create `web/status.php` — shows upload sessions and job statuses for the logged-in user

---

## Verification

1. **Schema**: Run `schema.sql` against MariaDB on univie webspace, verify tables created
2. **FITS parsing**: Upload a real Seestar .fit file, verify `parseFitsHeader()` extracts correct DATE-OBS, OBJECT, RA, DEC
3. **Chunk key**: Verify `computeChunkKey('2025-01-15T19:30:00')` returns `20250115-78` (19:30 UTC = 70200s / 900 = 78)
4. **Upload flow**: Upload 5+ .fit files via browser, verify they appear in u:cloud under correct path and DB rows are populated
5. **Job creation**: Finalize an upload session, verify stacking_jobs are created with correct chunk_keys
6. **Worker API** (later): Verify `next_job.php` returns a pending job and marks it processing

---

## Roadmap (Post-MVP)

1. **Python worker** — poll API, download raws, stack via seestarpy, upload results, clean up
2. **Results page** — browse stacks with thumbnails, download FITS
3. **User registration** — sign-up, email verification, login
4. **Tier system** — free (15-min), pro (3-min/1-min), raw preservation
5. **Pointing grouping** — auto-detect multiple targets per upload using `group_files_by_pointing_coords()`
6. **Desktop/mobile clients** — Kivy app (already started in seestarpy), browser PWA
7. **Infrastructure scaling** — OpenShift containers, S3 storage, multiple workers
8. **Community features** — public stacks, cross-user mosaics, sky map browser
