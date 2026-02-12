# Python Worker Reference

The worker is a standalone Python application in the `worker/` directory. It runs on any machine with network access to the CrowdSky webspace and u:cloud. Its job is to download raw FITS files, stack them using `seestarpy`, and upload the results.

## Installation

### Using uv (recommended, especially for servers)

The worker has a `pyproject.toml` that handles all dependencies:

```bash
cd CrowdSky/worker
uv sync                    # creates .venv, installs everything including seestarpy from GitHub
cp .env.example .env       # edit with credentials
```

### Using pip (for local development)

```bash
cd D:\Repos\CrowdSky
pip install -e E:\WHOPA\seestarpy    # local seestarpy
pip install requests python-dotenv Pillow
copy worker\.env.example worker\.env
```

### Dependencies
- **seestarpy** — FITS stacking library (astroalign, OpenCV, numpy, astropy, sep). Pulled from [GitHub](https://github.com/astronomyk/seestarpy) by `uv sync`.
- **requests** — HTTP client for the PHP API and WebDAV
- **python-dotenv** — loads `.env` configuration
- **Pillow** — PNG thumbnail generation

### systemd service (for production servers)

A `setup-service.sh` script is included for Fedora/RHEL. It auto-detects paths, writes the systemd unit file, and starts the service:

```bash
sudo bash worker/setup-service.sh
```

See [server-deployment.md](server-deployment.md) for the full zeus setup guide.

## Configuration

All settings are in `worker/.env`:

```ini
# PHP API endpoint
API_BASE_URL=https://crowdsky.univie.ac.at/api
WORKER_API_KEY=same-64-char-hex-as-in-php-config

# u:cloud WebDAV (for uploading stacks)
UCLOUD_WEBDAV_URL=https://ucloud.univie.ac.at/public.php/webdav
UCLOUD_SHARE_TOKEN=ELBci3d9eqyRBHp
UCLOUD_BASE_PATH=/crowdsky

# Worker identification
WORKER_ID=worker-01        # Shown in stacking_jobs.worker_id
POLL_INTERVAL=30           # Seconds between API polls when idle
WORK_DIR=./tmp             # Local temp directory for processing
```

## Running

### Daemon mode (recommended for development)
```bash
python -m worker
```
Polls for jobs continuously. Press Ctrl+C to stop.

### Single-job mode (for cron)
```bash
python -m worker --once
```
Processes one job and exits. Exit code 0 = job found and processed, exit code 1 = no jobs available.

### Cron example (every 2 minutes)
```
*/2 * * * * cd /path/to/CrowdSky && python -m worker --once >> /var/log/crowdsky-worker.log 2>&1
```

## Module Reference

### `config.py`
Loads environment variables from `.env` via `python-dotenv`. All settings are module-level constants.

### `api_client.py`
HTTP client for the PHP worker API. All functions use Bearer token authentication.

- **`get_next_job() -> Optional[dict]`** — claims the next pending job. Returns job dict or `None`.
- **`get_job_files(job_id: int) -> dict`** — gets raw file list for a job.
- **`download_raw_file(file_id: int, local_path: Path) -> None`** — downloads a raw FITS file from the webspace to a local path.
- **`complete_job(job_id: int, metadata: dict) -> dict`** — reports job completion with stack metadata.
- **`fail_job(job_id: int, error_message: str) -> dict`** — reports job failure.

### `webdav.py`
WebDAV operations for u:cloud. Only used for uploading stacks (raws are no longer on u:cloud).

- **`download_file(remote_path, local_path)`** — download from u:cloud (kept for potential future use).
- **`upload_file(local_path, remote_path)`** — upload a file to u:cloud via PUT.
- **`mkcol(remote_path)`** — create directory hierarchy on u:cloud.
- **`delete_files(remote_paths)`** — delete files from u:cloud.

### `stacking_adapter.py`
Wraps `seestarpy.stacking.stacking.FrameCollection` for CrowdSky's needs.

- **`stack_files(fits_paths, output_path, method, sigma_clip) -> StackResult`**

  The main function. Takes a list of FITS file paths, stacks them, and writes the output. Returns a `StackResult` dataclass with:
  - `output_path` — where the stack was written
  - `n_frames_input` — total frames loaded
  - `n_aligned` — frames that successfully aligned
  - `n_stars_detected` — stars found in the final stack (or None)
  - `total_exptime` — sum of EXPTIME across all input frames
  - `date_obs_start` / `date_obs_end` — time range of input frames
  - `ra_deg` / `dec_deg` — mean pointing coordinates

  Internally calls:
  1. `FrameCollection(fits_paths)` — loads all frames
  2. `.process()` — runs the full pipeline: source detection → alignment → transform → stack → star detection
  3. `.save(output_path)` — writes multi-extension FITS (RGB data + footprint + optional star catalog)

### `thumbnail.py`
Generates PNG preview images from stacked FITS files.

- **`generate_thumbnail(fits_path, output_path, max_size=512) -> Path`**

  Reads the primary HDU (RGB or grayscale), applies percentile-based stretch (1st to 99.5th percentile mapped to 0-255), resizes preserving aspect ratio, and saves as PNG.

### `job_processor.py`
Orchestrates the complete job processing pipeline.

- **`process_job(job: dict) -> None`**

  The main function called for each job. Steps:
  1. Fetch file list from PHP API (`get_job_files`)
  2. Download each raw FITS from webspace (`download_raw_file`)
  3. Stack via seestarpy (`stack_files`)
  4. Generate thumbnail (`generate_thumbnail`)
  5. Upload stacked FITS + thumbnail to u:cloud (`upload_file`, `mkcol`)
  6. Report completion to PHP API (`complete_job`) — this triggers PHP to delete local raws
  7. Clean up local temp directory

  On failure: reports error to PHP API (`fail_job`), cleans up temp files.

### `main.py`
Entry point. Parses `--once` flag, runs either `run_daemon()` (continuous poll loop) or `run_once()` (single job).

## Processing Pipeline Detail

For a single stacking job with 15 raw frames:

```
1. API call: GET /api/next_job.php
   → receives: job_id=42, chunk_key="20250115.78_83.6_+22.0", frame_count=15

2. API call: GET /api/job_files.php?job_id=42
   → receives: list of 15 files with IDs and metadata

3. For each file:
   API call: GET /api/download_raw.php?file_id=101
   → saves to: ./tmp/job_42/raws/Seestar_20250115-193000.fit
   (15 HTTP requests, ~60 MB total)

4. seestarpy FrameCollection:
   - Load 15 FITS files into memory
   - Detect sources in each frame (star finding)
   - Compute alignment transforms (astroalign)
   - Apply transforms (reproject all frames to common grid)
   - Stack with sigma-clipped mean (reject outliers)
   - Detect stars in final stack
   → output: ./tmp/job_42/stack_20250115.78_42.fits (~12 MB)

5. Thumbnail generation:
   - Read RGB data from stacked FITS
   - Percentile stretch → 8-bit PNG
   → output: ./tmp/job_42/stack_20250115.78_42_thumb.png (~200 KB)

6. WebDAV uploads to u:cloud:
   - MKCOL /crowdsky/stacks/user_1/M42/
   - PUT stack FITS file
   - PUT thumbnail PNG

7. API call: POST /api/complete_job.php
   → sends: all metadata (frames aligned, exposure time, coordinates, etc.)
   → PHP side: inserts stacked_frames row, deletes 15 local raw files

8. Cleanup: rm -rf ./tmp/job_42/
```

Total time per job: typically 30-120 seconds depending on frame count and network speed.
