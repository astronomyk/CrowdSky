<?php
/**
 * Upload page: drag-and-drop .fit files, save to local webspace disk, parse FITS headers.
 *
 * Raw files are stored locally on the webspace (50 GB buffer). Only stacked
 * results go to u:cloud. The worker downloads raws via /api/download_raw.php.
 *
 * GET           — render upload form
 * GET action=status — return session state as JSON (for session persistence)
 * POST action=start — create upload_session + local directory
 * POST action=file  — receive one .fit file, save to disk, insert raw_files row
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/fits_utils.php';

$userId = requireLogin();

$action = $_GET['action'] ?? $_POST['action'] ?? null;

// --- GET: session status endpoint (for persistence) ---

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'status') {
    header('Content-Type: application/json');

    $sessionToken = $_GET['session_token'] ?? '';
    if (empty($sessionToken)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing session_token.']);
        exit;
    }

    $db = getDb();
    $stmt = $db->prepare(
        'SELECT id, status, file_count FROM upload_sessions WHERE session_token = ? AND user_id = ?'
    );
    $stmt->execute([$sessionToken, $userId]);
    $session = $stmt->fetch();

    if (!$session) {
        echo json_encode(['status' => 'not_found']);
        exit;
    }

    $files = [];
    if ($session['status'] === 'uploading') {
        $stmt = $db->prepare(
            'SELECT filename, chunk_key, file_size_bytes FROM raw_files
             WHERE upload_session_id = ? AND is_deleted = 0
             ORDER BY created_at'
        );
        $stmt->execute([$session['id']]);
        $files = $stmt->fetchAll();
    }

    echo json_encode([
        'status'     => $session['status'],
        'file_count' => (int)$session['file_count'],
        'files'      => $files,
    ]);
    exit;
}

// --- API-style POST handlers (called via fetch from JS) ---

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'start') {
    header('Content-Type: application/json');

    if (!csrfValidate()) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token.']);
        exit;
    }

    $db = getDb();
    $sessionToken = bin2hex(random_bytes(16));
    $localDir = UPLOAD_DIR . "/user_{$userId}/sess_{$sessionToken}";

    // Create local directory on webspace
    if (!mkdir($localDir, 0750, true)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create upload directory.']);
        exit;
    }

    $stmt = $db->prepare(
        'INSERT INTO upload_sessions (user_id, session_token, ucloud_path) VALUES (?, ?, ?)'
    );
    $stmt->execute([$userId, $sessionToken, $localDir]);
    $sessionId = (int)$db->lastInsertId();

    echo json_encode([
        'session_id'    => $sessionId,
        'session_token' => $sessionToken,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'file') {
    header('Content-Type: application/json');

    $sessionToken = $_POST['session_token'] ?? '';
    if (empty($sessionToken)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing session_token.']);
        exit;
    }

    $db = getDb();
    $stmt = $db->prepare(
        'SELECT id, ucloud_path FROM upload_sessions WHERE session_token = ? AND user_id = ? AND status = ?'
    );
    $stmt->execute([$sessionToken, $userId, 'uploading']);
    $session = $stmt->fetch();

    if (!$session) {
        http_response_code(404);
        echo json_encode(['error' => 'Upload session not found or expired.']);
        exit;
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        $code = $_FILES['file']['error'] ?? 'missing';
        echo json_encode(['error' => "File upload error (code: $code)."]);
        exit;
    }

    $file = $_FILES['file'];
    $filename = basename($file['name']);
    $tmpPath = $file['tmp_name'];
    $fileSize = $file['size'];

    // Validate extension
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Only .fit and .fits files are accepted.']);
        exit;
    }

    // Validate file size
    if ($fileSize > MAX_FILE_SIZE) {
        http_response_code(400);
        $maxMb = MAX_FILE_SIZE / 1024 / 1024;
        echo json_encode(['error' => "File exceeds {$maxMb} MB limit."]);
        exit;
    }

    // Validate FITS magic bytes
    $header = parseFitsHeader($tmpPath);
    if ($header === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid FITS file (bad header).']);
        exit;
    }

    // Compute chunk key
    $chunkKey = null;
    if (!empty($header['DATE-OBS'])) {
        $ra = is_numeric($header['RA'] ?? null) ? (float)$header['RA'] : null;
        $dec = is_numeric($header['DEC'] ?? null) ? (float)$header['DEC'] : null;
        $chunkKey = computeChunkKey($header['DATE-OBS'], $ra, $dec);
    }

    // Save to local webspace disk
    $localPath = $session['ucloud_path'] . '/' . $filename;
    if (!move_uploaded_file($tmpPath, $localPath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save file to disk.']);
        exit;
    }

    // Parse DATE-OBS to MySQL DATETIME
    $dateObs = null;
    if (!empty($header['DATE-OBS'])) {
        $ts = strtotime($header['DATE-OBS']);
        if ($ts !== false) {
            $dateObs = gmdate('Y-m-d H:i:s', $ts);
        }
    }

    // Insert raw_files row
    $stmt = $db->prepare(
        'INSERT INTO raw_files
            (upload_session_id, filename, ucloud_path, file_size_bytes,
             fits_date_obs, fits_object, fits_exptime, fits_ra, fits_dec, chunk_key)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $session['id'],
        $filename,
        $localPath,
        $fileSize,
        $dateObs,
        $header['OBJECT'] ?? null,
        $header['EXPTIME'] ?? null,
        is_numeric($header['RA'] ?? null) ? (float)$header['RA'] : null,
        is_numeric($header['DEC'] ?? null) ? (float)$header['DEC'] : null,
        $chunkKey,
    ]);

    // Update session counters
    $db->prepare(
        'UPDATE upload_sessions
         SET file_count = file_count + 1,
             total_size_mb = total_size_mb + ?,
             object_name = COALESCE(object_name, ?),
             ra_deg  = COALESCE(ra_deg, ?),
             dec_deg = COALESCE(dec_deg, ?)
         WHERE id = ?'
    )->execute([
        round($fileSize / 1024 / 1024, 2),
        $header['OBJECT'] ?? null,
        is_numeric($header['RA'] ?? null) ? (float)$header['RA'] : null,
        is_numeric($header['DEC'] ?? null) ? (float)$header['DEC'] : null,
        $session['id'],
    ]);

    echo json_encode([
        'ok'        => true,
        'filename'  => $filename,
        'date_obs'  => $header['DATE-OBS'] ?? null,
        'object'    => $header['OBJECT'] ?? null,
        'chunk_key' => $chunkKey,
    ]);
    exit;
}

// --- GET: render upload form ---

$pageTitle = 'Upload - CrowdSky';
include __DIR__ . '/templates/header.php';
?>

<h1>Upload FITS Files</h1>

<div class="card">
    <div id="upload-zone" class="upload-zone">
        <div class="icon">&#x1F52D;</div>
        <p><strong>Drag & drop .fit files here</strong><br>or click to browse</p>
        <input type="file" id="file-input" multiple accept=".fit,.fits" style="display:none">
    </div>

    <div id="upload-progress" style="display:none">
        <div class="upload-progress-row">
            <button id="btn-finalize" class="btn btn-primary btn-disabled" disabled>
                Finalize &amp; Create Stacking Jobs
            </button>
            <div class="progress-bar"><div class="fill" id="progress-fill" style="width:0%"></div></div>
        </div>
        <p id="progress-text" style="margin-top:0.5rem;font-size:0.875rem;color:var(--text-muted)"></p>
    </div>

    <div class="file-list-container" id="file-list-container" style="display:none">
        <ul class="file-list" id="file-list"></ul>
    </div>
</div>

<script>
(function() {
    const zone = document.getElementById('upload-zone');
    const input = document.getElementById('file-input');
    const list = document.getElementById('file-list');
    const listContainer = document.getElementById('file-list-container');
    const progressWrap = document.getElementById('upload-progress');
    const progressFill = document.getElementById('progress-fill');
    const progressText = document.getElementById('progress-text');
    const btnFinalize = document.getElementById('btn-finalize');
    const csrf = <?= json_encode(csrfToken()) ?>;

    let sessionToken = null;
    let totalFiles = 0;
    let uploadedFiles = 0;
    let allSucceeded = true;
    let uploading = false;

    // --- Session persistence ---
    function saveState() {
        if (sessionToken) {
            sessionStorage.setItem('crowdsky_upload', JSON.stringify({
                sessionToken, totalFiles, uploadedFiles
            }));
        }
    }

    function clearState() {
        sessionStorage.removeItem('crowdsky_upload');
    }

    async function restoreState() {
        const saved = sessionStorage.getItem('crowdsky_upload');
        if (!saved) return;

        let state;
        try { state = JSON.parse(saved); } catch { clearState(); return; }
        if (!state.sessionToken) { clearState(); return; }

        // Verify session is still valid on the server
        try {
            const resp = await fetch('upload.php?action=status&session_token=' + encodeURIComponent(state.sessionToken));
            const data = await resp.json();

            if (data.status === 'uploading') {
                sessionToken = state.sessionToken;
                totalFiles = state.totalFiles || 0;
                uploadedFiles = data.file_count || 0;

                // Restore UI
                zone.style.display = 'none';
                progressWrap.style.display = 'block';
                listContainer.style.display = 'block';

                // Rebuild file list from server data
                list.innerHTML = '';
                if (data.files) {
                    data.files.forEach(f => {
                        const li = document.createElement('li');
                        const sizeMb = (f.file_size_bytes / 1024 / 1024).toFixed(2);
                        const info = f.chunk_key || 'ok';
                        li.innerHTML = '<span>' + escHtml(f.filename) + ' (' + sizeMb + ' MB)</span>' +
                                       '<span class="file-status done">' + escHtml(info) + '</span>';
                        list.appendChild(li);
                    });
                }

                // Update progress
                const pct = totalFiles > 0 ? Math.round((uploadedFiles / totalFiles) * 100) : 0;
                progressFill.style.width = pct + '%';
                progressText.textContent = uploadedFiles + ' / ' + totalFiles + ' files uploaded';

                // Enable finalize if upload was complete
                if (uploadedFiles >= totalFiles && totalFiles > 0) {
                    enableFinalize();
                }
            } else if (data.status === 'complete' || data.status === 'not_found') {
                clearState();
            }
        } catch {
            // Server unreachable, keep the saved state for later
        }
    }

    function enableFinalize() {
        btnFinalize.disabled = false;
        btnFinalize.classList.remove('btn-disabled');
    }

    // Restore on page load
    restoreState();

    // Warn before leaving during upload
    window.addEventListener('beforeunload', e => {
        if (uploading) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    zone.addEventListener('click', () => input.click());
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
    zone.addEventListener('drop', e => {
        e.preventDefault();
        zone.classList.remove('dragover');
        handleFiles(e.dataTransfer.files);
    });
    input.addEventListener('change', () => handleFiles(input.files));

    async function handleFiles(files) {
        const fitFiles = Array.from(files).filter(f => {
            const ext = f.name.split('.').pop().toLowerCase();
            return ext === 'fit' || ext === 'fits';
        });
        if (fitFiles.length === 0) {
            alert('No .fit/.fits files found.');
            return;
        }

        totalFiles = fitFiles.length;
        uploadedFiles = 0;
        allSucceeded = true;
        uploading = true;
        progressWrap.style.display = 'block';
        listContainer.style.display = 'block';
        zone.style.display = 'none';
        list.innerHTML = '';

        // Disable finalize during upload
        btnFinalize.disabled = true;
        btnFinalize.classList.add('btn-disabled');

        // Start session
        if (!sessionToken) {
            const fd = new FormData();
            fd.append('action', 'start');
            fd.append('csrf_token', csrf);
            const resp = await fetch('upload.php?action=start', { method: 'POST', body: fd });
            const data = await resp.json();
            if (data.error) {
                alert('Error: ' + data.error);
                uploading = false;
                return;
            }
            sessionToken = data.session_token;
        }
        saveState();

        // Upload files sequentially
        for (const file of fitFiles) {
            const li = document.createElement('li');
            li.innerHTML = '<span>' + escHtml(file.name) + ' (' + (file.size/1024/1024).toFixed(2) + ' MB)</span>' +
                           '<span class="file-status" id="fs-' + uploadedFiles + '">uploading...</span>';
            list.appendChild(li);

            const fd = new FormData();
            fd.append('action', 'file');
            fd.append('session_token', sessionToken);
            fd.append('file', file);

            const statusEl = document.getElementById('fs-' + uploadedFiles);
            try {
                const resp = await fetch('upload.php?action=file', { method: 'POST', body: fd });
                const data = await resp.json();
                if (data.error) {
                    statusEl.textContent = data.error;
                    statusEl.className = 'file-status error';
                    allSucceeded = false;
                } else {
                    const info = data.object ? data.object : (data.chunk_key || 'ok');
                    statusEl.textContent = info;
                    statusEl.className = 'file-status done';
                }
            } catch (err) {
                statusEl.textContent = 'network error';
                statusEl.className = 'file-status error';
                allSucceeded = false;
            }

            uploadedFiles++;
            const pct = Math.round((uploadedFiles / totalFiles) * 100);
            progressFill.style.width = pct + '%';
            progressText.textContent = uploadedFiles + ' / ' + totalFiles + ' files uploaded';
            saveState();
        }

        uploading = false;

        if (uploadedFiles === totalFiles && allSucceeded) {
            enableFinalize();
        }
    }

    btnFinalize.addEventListener('click', async () => {
        if (btnFinalize.disabled) return;
        btnFinalize.disabled = true;
        btnFinalize.textContent = 'Finalizing...';

        const resp = await fetch('finalize.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'session_token=' + encodeURIComponent(sessionToken) + '&csrf_token=' + encodeURIComponent(csrf)
        });
        const data = await resp.json();
        if (data.error) {
            alert('Error: ' + data.error);
            btnFinalize.disabled = false;
            btnFinalize.textContent = 'Finalize & Create Stacking Jobs';
        } else {
            clearState();
            window.location.href = 'status.php?session=' + encodeURIComponent(sessionToken);
        }
    });

    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }
})();
</script>

<?php include __DIR__ . '/templates/footer.php'; ?>
