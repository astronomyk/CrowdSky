# Architecture

## Overview

CrowdSky has three components that communicate through HTTP APIs and WebDAV:

```
┌──────────┐         ┌─────────────────────────┐        ┌──────────────────────┐
│  Browser  │────────>│  PHP on univie webspace  │        │  u:cloud WebDAV      │
│           │ upload  │  crowdsky.univie.ac.at   │        │  250 GB permanent    │
│           │ .fit    │                          │        │                      │
│           │ files   │  ┌─────────┐ ┌────────┐ │        │  Only stacked FITS   │
│           │         │  │MariaDB  │ │Local   │ │        │  + thumbnails live   │
│           │         │  │(users,  │ │disk    │ │        │  here                │
│           │         │  │jobs)    │ │(50 GB  │ │        │                      │
│           │         │  │         │ │raw     │ │        │                      │
│           │         │  │         │ │buffer) │ │        │                      │
│           │         │  └─────────┘ └────────┘ │        │                      │
│           │         │                          │        │                      │
│           │         │  Worker API endpoints    │        │                      │
│           │         └──────────┬───────────────┘        └──────────┬───────────┘
│           │                    │                                    │
│           │         ┌──────────┴───────────────┐                   │
│           │         │  Python Worker            │                   │
│           │         │  (any machine with cron)  │                   │
│           │         │                           │                   │
│           │         │  1. Poll API for jobs     │                   │
│           │         │  2. Download raws from ───┼── HTTP GET ──────>│
│           │         │     webspace              │                   │
│           │         │  3. Stack via seestarpy   │                   │
│           │         │  4. Upload stack to ──────┼── WebDAV PUT ────>│
│           │         │     u:cloud               │                   │
│           │         │  5. Report completion     │                   │
│           │         └───────────────────────────┘                   │
│           │                                                         │
│           │<── view/download stacks (proxied through PHP) ──────────┘
└──────────┘
```

## Data Flow: Upload to Stack

### 1. User uploads raw .fit files

```
Browser                      PHP (upload.php)              Local Disk
  │                              │                              │
  ├── POST action=start -------->│                              │
  │                              ├── mkdir uploads/sess_xxx --->│
  │                              ├── INSERT upload_sessions     │
  │<── { session_token } --------│                              │
  │                              │                              │
  ├── POST action=file --------->│                              │
  │   (multipart: .fit file)     ├── validate FITS magic bytes  │
  │                              ├── parse FITS header          │
  │                              ├── compute chunk_key          │
  │                              ├── move_uploaded_file() ----->│
  │                              ├── INSERT raw_files           │
  │<── { ok, chunk_key } --------│                              │
  │                              │                              │
  │   ... repeat for each file   │                              │
```

### 2. User finalizes upload

```
Browser                      PHP (finalize.php)
  │                              │
  ├── POST session_token ------->│
  │                              ├── GROUP BY chunk_key
  │                              ├── INSERT stacking_jobs (one per chunk)
  │                              ├── UPDATE upload_sessions → 'complete'
  │<── { jobs: [...] } ----------│
```

### 3. Worker processes jobs

```
Worker                       PHP API                     u:cloud
  │                              │                          │
  ├── GET /api/next_job.php ---->│                          │
  │                              ├── SELECT ... FOR UPDATE  │
  │                              ├── UPDATE → 'processing'  │
  │<── { job_id, chunk_key } ----│                          │
  │                              │                          │
  ├── GET /api/job_files.php --->│                          │
  │<── { files: [...] } ---------│                          │
  │                              │                          │
  ├── GET /api/download_raw.php  │                          │
  │   ?file_id=1 --------------->│                          │
  │<── (binary .fit data) -------│                          │
  │   ... repeat per file        │                          │
  │                              │                          │
  ├── [ stack via seestarpy ]    │                          │
  ├── [ generate thumbnail ]     │                          │
  │                              │                          │
  ├── MKCOL + PUT stacked.fits ──┼────────────────────────->│
  ├── PUT thumbnail.png ─────────┼────────────────────────->│
  │                              │                          │
  ├── POST /api/complete_job --->│                          │
  │   { metadata }               ├── INSERT stacked_frames  │
  │                              ├── DELETE local raw files  │
  │                              ├── UPDATE → 'completed'   │
  │<── { ok } -------------------│                          │
```

## Key Design Decisions

### Database as message bus
The PHP app and Python worker never share a database connection. PHP creates stacking jobs in MariaDB; the worker discovers them by polling the HTTP API. This means the worker can run anywhere with internet access — your laptop, a lab server, or eventually a cloud container.

### Two-tier storage
- **Webspace local disk (50 GB):** Temporary buffer for raw uploads. Files are deleted after stacking or after 24 hours if abandoned. Fast writes (no network round-trip during upload).
- **u:cloud (250 GB):** Permanent storage for stacked FITS files and thumbnails. Only the worker writes here. Users download stacks through a PHP proxy (so the WebDAV token is never exposed to browsers).

### PHP parses FITS headers
FITS headers are simple: 80-byte ASCII cards in 2880-byte blocks. PHP reads the first few blocks to extract DATE-OBS, OBJECT, RA, DEC, EXPTIME. No scientific library needed — just `fread()` and string parsing.

### Stacking stays in Python
The actual stacking (alignment, debayering, sigma-clipped combination) requires numpy, OpenCV, astroalign, and the `seestarpy` package. This can't run in PHP. The worker is the only component that does heavy computation.

### Chunk key format
Files are grouped into 15-minute time windows for stacking. The chunk key encodes the time slot and sky coordinates:

```
YYYYMMDD.CC_RRR.R_sDD.D

  YYYYMMDD  = UTC date
  CC        = 15-minute chunk index (0-95, i.e., floor(seconds_since_midnight / 900))
  RRR.R     = Right Ascension in degrees, 1 decimal place
  sDD.D     = Declination with sign (+/-), 1 decimal place
```

Example: `20250115.78_83.6_+22.0` means January 15, 2025, chunk 78 (19:30-19:45 UTC), pointing at RA=83.6, Dec=+22.0 (near Orion Nebula).

### Atomic job claiming
`next_job.php` uses `SELECT ... FOR UPDATE SKIP LOCKED` to atomically claim a job. This prevents two workers from grabbing the same job, and it works even with multiple concurrent workers.

## Security Model

- **User passwords:** bcrypt via PHP's `password_hash()`
- **CSRF protection:** random token in session, validated on all POST forms
- **FITS validation:** magic bytes check (`SIMPLE  =                    T`)
- **Worker API:** Bearer token authentication (shared secret in config)
- **File downloads:** proxied through PHP so WebDAV credentials never reach the browser
- **SQL injection:** PDO prepared statements everywhere
- **Upload limits:** 50 MB per file, only `.fit`/`.fits` extensions accepted
