# PHP Code Reference

Every PHP file in the `web/` directory explained.

---

## Configuration & Helpers

### `config.php` (gitignored)
Live credentials. Created by copying `config.example.php` and filling in real values. See [configuration.md](configuration.md) for all settings.

### `config.example.php`
Template committed to git with placeholder values. Never contains real passwords.

### `db.php`
Database connection helper. Exports one function:

- **`getDb(): PDO`** — returns a singleton PDO connection to MariaDB. Uses settings from `config.php`. Configured with:
  - Exception error mode (throws on SQL errors)
  - Associative fetch mode (rows returned as `['column' => value]`)
  - Real prepared statements (not emulated)

### `auth.php`
Authentication and session management. Starts PHP sessions on include. Exports:

- **`registerUser(string $username, string $email, string $password): array`** — creates a new user with bcrypt password hash. Returns `['ok' => true, 'user_id' => int]` on success or `['ok' => false, 'error' => string]` on failure (duplicate username/email, validation errors).

- **`loginUser(string $username, string $password): array`** — validates credentials against the database. Accepts username or email. Returns `['ok' => true]` or `['ok' => false, 'error' => string]`.

- **`logoutUser(): void`** — destroys the session and clears the session cookie.

- **`currentUserId(): ?int`** — returns the logged-in user's ID or null.

- **`requireLogin(): int`** — redirects to `index.php` if not logged in. Returns user ID if authenticated. Call this at the top of any page that requires login.

- **`csrfToken(): string`** — returns (and generates if needed) a CSRF token stored in the session. Use in forms as a hidden field.

- **`csrfValidate(): bool`** — checks that the submitted `csrf_token` matches the session token. Call on POST handlers.

---

## Core Utilities

### `fits_utils.php`
FITS header parsing without any external library.

- **`parseFitsHeader(string $filePath): array|false`** — reads a FITS file and extracts header keywords. Returns an associative array with keys `DATE-OBS`, `OBJECT`, `EXPTIME`, `RA`, `DEC` (any may be null if not present in the header). Returns `false` if the file isn't a valid FITS file.

  **How it works:**
  1. Reads first 30 bytes, checks for FITS magic: `SIMPLE  =                    T`
  2. Reads 2880-byte blocks (up to 10 blocks)
  3. Each block contains 36 cards of 80 bytes each
  4. Parses `KEY = VALUE / comment` format
  5. String values are enclosed in single quotes
  6. Stops when it hits the `END` keyword

- **`computeChunkKey(string $dateObs, ?float $ra, ?float $dec): ?string`** — computes the time+pointing chunk key from a DATE-OBS string and optional coordinates. Format: `YYYYMMDD.CC_RRR.R_sDD.D`. Returns null if DATE-OBS is unparseable.

### `webdav.php`
u:cloud WebDAV operations using PHP's cURL extension. All functions authenticate with the share token from `config.php`.

- **`mkcolUcloud(string $remotePath): bool`** — creates a directory hierarchy on u:cloud via MKCOL. Creates parent directories one by one. Returns true if all directories exist.

- **`uploadToUcloud(string $localPath, string $remotePath): bool`** — uploads a local file via PUT. Streams the file (doesn't load it all into memory). 5-minute timeout for large files.

- **`uploadDataToUcloud(string $data, string $remotePath): bool`** — uploads raw data (a string) via PUT. Useful for small files.

- **`deleteFromUcloud(string $remotePath): bool`** — deletes a file or directory via DELETE.

> **Note:** Since the webspace storage refactor, `webdav.php` is no longer used during uploads. It's only used by `download.php` to proxy stack downloads to browsers. The worker handles its own WebDAV operations in Python.

---

## Pages

### `index.php`
Landing page. Shows login and register forms if not logged in. Redirects to `upload.php` if already authenticated. Handles:
- `GET ?action=logout` — logs out and redirects
- `POST form_action=login` — authenticates user
- `POST form_action=register` — creates new user

### `upload.php`
The main upload interface. Requires login.

**GET** — renders a drag-and-drop upload zone with JavaScript that handles:
1. File selection (drag-drop or click-to-browse)
2. Filtering for `.fit`/`.fits` extensions
3. Starting an upload session (POST to `?action=start`)
4. Uploading files one by one (POST to `?action=file`)
5. Showing per-file progress and status
6. "Finalize" button that POSTs to `finalize.php`

**POST `action=start`** — creates a new upload session:
1. Validates CSRF token
2. Generates a random 32-char hex session token
3. Creates a local directory: `uploads/user_{id}/sess_{token}/`
4. Inserts a row into `upload_sessions`
5. Returns JSON: `{ session_id, session_token }`

**POST `action=file`** — receives a single file:
1. Validates the session token belongs to the logged-in user
2. Validates file extension and size (50 MB max)
3. Validates FITS magic bytes via `parseFitsHeader()`
4. Computes chunk key from FITS header
5. Saves the file to local disk via `move_uploaded_file()`
6. Inserts a row into `raw_files` with all parsed header values
7. Updates `upload_sessions` counters (file_count, total_size_mb)
8. Returns JSON: `{ ok, filename, date_obs, object, chunk_key }`

### `finalize.php`
Finalizes an upload session and creates stacking jobs. POST only.

1. Validates CSRF token and session ownership
2. Groups `raw_files` by `chunk_key` (`GROUP BY chunk_key`)
3. Creates one `stacking_jobs` row per chunk
4. Marks the upload session as `complete`
5. Returns JSON: `{ ok, jobs: count, job_ids: [...], chunks: [...] }`

### `status.php`
Job status dashboard. Requires login.

- With `?session=TOKEN` — shows details for a specific upload session and its stacking jobs
- Without parameters — lists all upload sessions for the current user (last 50)

### `stacks.php`
Browse completed stacked frames. Requires login.

- Optional `?object=NAME` filter to show only stacks of a specific sky object
- Shows filter buttons for all objects the user has stacked
- Table with: object name, chunk key, frame counts, exposure time, date, star count, file size, download link

### `download.php`
Proxies a stacked FITS file download from u:cloud. Requires login.

- `GET ?id=STACK_ID` — looks up the `stacked_frames` row, verifies ownership, then streams the file from u:cloud using cURL with the WebDAV token. The browser never sees the token.
- Sets `Content-Disposition: attachment` with a descriptive filename.

---

## Templates

### `templates/header.php`
HTML `<head>`, navigation bar, and opening `<main>` tag. Expects `$pageTitle` to be set before including. Shows different nav links depending on whether the user is logged in.

### `templates/footer.php`
Closing `</main>`, footer text, closing `</body></html>`.

---

## Assets

### `assets/css/style.css`
Dark-themed CSS using CSS custom properties. Styles for:
- Layout (header, main, footer)
- Cards, forms, buttons
- Upload zone (drag-and-drop area with hover/dragover states)
- File list with per-file status indicators
- Progress bar
- Tables with sortable columns
- Status badges (colored by job status: pending/processing/completed/failed)
- Alert boxes (error, success, warning)
