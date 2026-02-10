<?php
/**
 * Worker API: GET /api/job_files.php?job_id=X
 *
 * Returns the list of raw file paths for a stacking job.
 * Authenticated with Bearer WORKER_API_KEY.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

// Authenticate worker
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m) || !hash_equals(WORKER_API_KEY, $m[1])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized.']);
    exit;
}

$jobId = (int)($_GET['job_id'] ?? 0);
if ($jobId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid job_id.']);
    exit;
}

$db = getDb();

// Get job info
$stmt = $db->prepare(
    'SELECT id, upload_session_id, chunk_key, status FROM stacking_jobs WHERE id = ?'
);
$stmt->execute([$jobId]);
$job = $stmt->fetch();

if (!$job) {
    http_response_code(404);
    echo json_encode(['error' => 'Job not found.']);
    exit;
}

// Get raw files for this job's session + chunk_key
$stmt = $db->prepare(
    'SELECT id, filename, ucloud_path, file_size_bytes, fits_date_obs, fits_exptime, fits_ra, fits_dec
     FROM raw_files
     WHERE upload_session_id = ? AND chunk_key = ? AND is_deleted = 0
     ORDER BY fits_date_obs ASC'
);
$stmt->execute([$job['upload_session_id'], $job['chunk_key']]);
$files = $stmt->fetchAll();

echo json_encode([
    'job_id' => (int)$job['id'],
    'chunk_key' => $job['chunk_key'],
    'files' => $files,
]);
