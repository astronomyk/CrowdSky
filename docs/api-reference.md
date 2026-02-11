# Worker API Reference

These PHP endpoints in `web/api/` are called by the Python worker (or any HTTP client). All endpoints except `cleanup.php` require Bearer token authentication.

## Authentication

Every request must include:
```
Authorization: Bearer <WORKER_API_KEY>
```

The key is defined in `config.php` on the PHP side and `.env` on the worker side. They must match.

---

## GET `/api/next_job.php`

Claim the next pending stacking job. Uses `SELECT ... FOR UPDATE SKIP LOCKED` for safe concurrent access.

### Parameters
| Param | Location | Required | Description |
|-------|----------|----------|-------------|
| `worker_id` | query | No | Identifier for this worker instance. Default: `"default"` |

### Response

**200 OK** — job claimed:
```json
{
    "job_id": 42,
    "user_id": 1,
    "upload_session_id": 7,
    "chunk_key": "20250115.78_83.6_+22.0",
    "object_name": "M42",
    "frame_count": 15,
    "session_ucloud_path": "/path/to/uploads/user_1/sess_abc123"
}
```

**204 No Content** — no jobs available. Empty response body.

### Behavior
1. Checks for `pending` jobs first (oldest first)
2. If none, checks for `retry` jobs with `retry_count < 3`
3. Atomically marks the claimed job as `processing` with `worker_id` and `started_at`

---

## GET `/api/job_files.php`

Get the list of raw files for a stacking job.

### Parameters
| Param | Location | Required | Description |
|-------|----------|----------|-------------|
| `job_id` | query | Yes | The stacking job ID |

### Response

**200 OK:**
```json
{
    "job_id": 42,
    "chunk_key": "20250115.78_83.6_+22.0",
    "files": [
        {
            "id": "101",
            "filename": "Seestar_20250115-193000.fit",
            "ucloud_path": "/path/to/uploads/user_1/sess_abc/Seestar_20250115-193000.fit",
            "file_size_bytes": "4194304",
            "fits_date_obs": "2025-01-15 19:30:00",
            "fits_exptime": "10",
            "fits_ra": "83.633",
            "fits_dec": "22.014"
        }
    ]
}
```

**Note:** The `id` field is what you pass to `download_raw.php` to download the actual file.

---

## GET `/api/download_raw.php`

Download a raw FITS file from local webspace storage. Streams the binary file directly.

### Parameters
| Param | Location | Required | Description |
|-------|----------|----------|-------------|
| `file_id` | query | Yes | The `raw_files.id` value |

### Response

**200 OK:**
- `Content-Type: application/octet-stream`
- `Content-Length: <file size>`
- Body: raw binary FITS data

**404 Not Found:** file doesn't exist or has been deleted.

---

## POST `/api/complete_job.php`

Mark a job as completed and submit the stacked frame metadata.

### Request Body (JSON)
```json
{
    "job_id": 42,
    "ucloud_path": "/crowdsky/stacks/user_1/M42/stack_20250115.78_42.fits",
    "thumbnail_path": "/crowdsky/stacks/user_1/M42/stack_20250115.78_42_thumb.png",
    "n_frames_input": 15,
    "n_frames_aligned": 13,
    "total_exptime": 150.0,
    "date_obs_start": "2025-01-15T19:30:00",
    "date_obs_end": "2025-01-15T19:44:30",
    "ra_deg": 83.633,
    "dec_deg": 22.014,
    "file_size_bytes": 12582912,
    "n_stars_detected": 247
}
```

### Response

**200 OK:**
```json
{
    "ok": true
}
```

### Behavior
1. Verifies the job exists and is in `processing` state
2. Inserts a `stacked_frames` row with all the metadata
3. Marks the job as `completed`
4. **Deletes the local raw files** from webspace disk
5. If all chunks in the upload session are done, removes the empty session directory

---

## POST `/api/fail_job.php`

Report that a job failed.

### Request Body (JSON)
```json
{
    "job_id": 42,
    "error_message": "Alignment failed: not enough stars detected"
}
```

### Response

**200 OK:**
```json
{
    "ok": true,
    "status": "retry"
}
```

### Behavior
- If `retry_count < 3`: marks job as `retry` (will be picked up again by `next_job.php`)
- If `retry_count >= 3`: marks job as `failed` (permanent failure)
- Increments `retry_count` and stores the error message

---

## GET `/api/cleanup.php`

Cron endpoint for cleaning up abandoned uploads. Not strictly part of the worker API but included here for completeness.

### Authentication
Accepts any of:
- `Authorization: Bearer <WORKER_API_KEY>`
- Query parameter: `?key=<WORKER_API_KEY>`
- CLI invocation (no auth needed when run via `php cleanup.php`)

### Response (text/plain)
```
Cleanup done: 3 expired sessions, 47 files deleted.
```

### Behavior
1. Finds upload sessions in `uploading` status older than `UPLOAD_EXPIRY_HOURS` (default 24h)
2. Deletes their local raw files from disk
3. Marks sessions as `expired` and files as `is_deleted = 1`
4. Also cleans up leftover files from `complete` sessions older than 1 hour

### Cron Setup
```
0 * * * * curl -s "https://crowdsky.univie.ac.at/api/cleanup.php?key=YOUR_KEY"
```
