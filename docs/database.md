# Database Schema Reference

All tables use InnoDB engine with utf8mb4 charset. The schema is defined in `schema.sql` at the repo root.

## Entity Relationship

```
users
  │
  ├──< upload_sessions (user_id)
  │       │
  │       ├──< raw_files (upload_session_id)
  │       │
  │       └──< stacking_jobs (upload_session_id)
  │               │
  │               └──< stacked_frames (stacking_job_id)
  │
  ├──< stacking_jobs (user_id)
  │
  └──< stacked_frames (user_id)
```

`<` means "has many". Foreign keys cascade on delete.

---

## `users`

Registered user accounts.

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT UNSIGNED AUTO_INCREMENT | Primary key |
| `username` | VARCHAR(64) UNIQUE | Login name |
| `email` | VARCHAR(255) UNIQUE | Email address |
| `password_hash` | VARCHAR(255) | bcrypt hash from `password_hash()` |
| `tier` | ENUM('free','pro','raw') | Subscription tier. Default: 'free' |
| `storage_used_mb` | DECIMAL(10,2) | How much u:cloud storage this user's stacks consume |
| `storage_limit_mb` | DECIMAL(10,2) | Storage quota. Default: 500 MB |
| `created_at` | DATETIME | Account creation time |
| `updated_at` | DATETIME | Auto-updated on row change |
| `is_active` | TINYINT(1) | Whether account is enabled. Default: 1 |
| `api_token` | VARCHAR(64) UNIQUE NULL | Future: per-user API token for programmatic uploads |

**Tier meanings (planned):**
- `free` — stacks at 15-minute cadence, raws deleted after stacking
- `pro` — stacks at 3-min or 1-min cadence
- `raw` — raw files preserved on u:cloud

---

## `upload_sessions`

Groups a batch of uploaded files together. Created when the user starts an upload, completed when they click "Finalize".

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT UNSIGNED AUTO_INCREMENT | Primary key |
| `user_id` | INT UNSIGNED FK→users | Who uploaded |
| `session_token` | VARCHAR(64) UNIQUE | Random hex token, used in URLs and local paths |
| `object_name` | VARCHAR(255) NULL | Sky object name from first file's FITS OBJECT keyword |
| `ra_deg` | DOUBLE NULL | Right ascension from first file |
| `dec_deg` | DOUBLE NULL | Declination from first file |
| `file_count` | INT UNSIGNED | Number of files uploaded |
| `total_size_mb` | DECIMAL(10,2) | Total size of uploaded files |
| `status` | ENUM | See status values below |
| `created_at` | DATETIME | When upload started |
| `completed_at` | DATETIME NULL | When user clicked Finalize |
| `ucloud_path` | VARCHAR(512) | Local filesystem path to the session's upload directory |

**Status values:**
- `uploading` — user is still adding files
- `complete` — finalized, stacking jobs created
- `failed` — something went wrong
- `expired` — cleaned up by cron after 24h of inactivity

**Indexes:**
- `idx_user_status (user_id, status)` — for listing a user's sessions

> **Note on `ucloud_path`:** Despite the column name (a historical artifact from before the webspace storage refactor), this stores a **local filesystem path**, not a u:cloud path. Example: `/path/to/web/uploads/user_1/sess_abc123`

---

## `raw_files`

Individual uploaded FITS files. One row per file.

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT UNSIGNED AUTO_INCREMENT | Primary key |
| `upload_session_id` | INT UNSIGNED FK→upload_sessions | Which session this file belongs to |
| `filename` | VARCHAR(255) | Original filename (e.g., `Seestar_20250115-193000.fit`) |
| `ucloud_path` | VARCHAR(512) | Local filesystem path to the file |
| `file_size_bytes` | BIGINT UNSIGNED | File size in bytes |
| `fits_date_obs` | DATETIME NULL | DATE-OBS from FITS header, converted to MySQL datetime |
| `fits_object` | VARCHAR(255) NULL | OBJECT keyword from FITS header |
| `fits_exptime` | FLOAT NULL | EXPTIME keyword (exposure time in seconds) |
| `fits_ra` | DOUBLE NULL | RA keyword (right ascension in degrees) |
| `fits_dec` | DOUBLE NULL | DEC keyword (declination in degrees) |
| `chunk_key` | VARCHAR(32) NULL | Computed time+pointing chunk key (see architecture.md) |
| `is_deleted` | TINYINT(1) | Whether the local file has been deleted after stacking |
| `created_at` | DATETIME | When the file was uploaded |

**Indexes:**
- `idx_session (upload_session_id)` — for listing files in a session
- `idx_chunk (upload_session_id, chunk_key)` — for grouping files into stacking jobs

---

## `stacking_jobs`

Work queue entries. One job per chunk_key per upload session. The Python worker polls for these.

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT UNSIGNED AUTO_INCREMENT | Primary key |
| `user_id` | INT UNSIGNED FK→users | Owner |
| `upload_session_id` | INT UNSIGNED FK→upload_sessions | Source session |
| `chunk_key` | VARCHAR(32) | Time+pointing chunk identifier |
| `object_name` | VARCHAR(255) NULL | Sky object name |
| `pointing_key` | VARCHAR(32) NULL | Future: for multi-target grouping |
| `frame_count` | INT UNSIGNED | Number of raw files to stack |
| `status` | ENUM | See status values below |
| `worker_id` | VARCHAR(64) NULL | Which worker claimed this job |
| `started_at` | DATETIME NULL | When processing began |
| `completed_at` | DATETIME NULL | When processing finished |
| `error_message` | TEXT NULL | Error details if failed |
| `retry_count` | TINYINT UNSIGNED | Number of retry attempts (max 3) |
| `created_at` | DATETIME | When the job was created |

**Status values:**
- `pending` — waiting for a worker to claim it
- `processing` — a worker is currently stacking
- `completed` — stacking succeeded, result uploaded to u:cloud
- `failed` — stacking failed after max retries
- `retry` — failed but eligible for another attempt (retry_count < 3)

**Indexes:**
- `idx_status (status, created_at)` — for the worker to find the next pending job

---

## `stacked_frames`

Results of completed stacking jobs. One row per stacked output.

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT UNSIGNED AUTO_INCREMENT | Primary key |
| `stacking_job_id` | INT UNSIGNED FK→stacking_jobs | Which job produced this |
| `user_id` | INT UNSIGNED FK→users | Owner |
| `object_name` | VARCHAR(255) NULL | Sky object name |
| `chunk_key` | VARCHAR(32) | Time+pointing chunk identifier |
| `ucloud_path` | VARCHAR(512) | Path on u:cloud to the stacked FITS file |
| `thumbnail_path` | VARCHAR(512) NULL | Path on u:cloud to the PNG thumbnail |
| `n_frames_input` | INT UNSIGNED | How many raw frames went in |
| `n_frames_aligned` | INT UNSIGNED | How many frames successfully aligned |
| `total_exptime` | FLOAT NULL | Sum of EXPTIME across all input frames |
| `date_obs_start` | DATETIME NULL | Earliest DATE-OBS in the input frames |
| `date_obs_end` | DATETIME NULL | Latest DATE-OBS in the input frames |
| `ra_deg` | DOUBLE NULL | Mean right ascension |
| `dec_deg` | DOUBLE NULL | Mean declination |
| `file_size_bytes` | BIGINT UNSIGNED | Size of the stacked FITS file |
| `n_stars_detected` | INT UNSIGNED NULL | Stars found by source detection |
| `created_at` | DATETIME | When the result was recorded |

**Indexes:**
- `idx_user_object (user_id, object_name)` — for browsing stacks by object
- `idx_user_date (user_id, date_obs_start)` — for browsing stacks by date
