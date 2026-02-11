<?php
/**
 * Worker API: POST /api/complete_job.php
 *
 * Marks a stacking job as completed and records the stacked frame metadata.
 * Authenticated with Bearer WORKER_API_KEY.
 *
 * POST body (JSON):
 *   job_id, ucloud_path, thumbnail_path, n_frames_input, n_frames_aligned,
 *   total_exptime, date_obs_start, date_obs_end, ra_deg, dec_deg,
 *   file_size_bytes, n_stars_detected
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required.']);
    exit;
}

// Authenticate worker
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m) || !hash_equals(WORKER_API_KEY, $m[1])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body.']);
    exit;
}

$jobId = (int)($input['job_id'] ?? 0);
if ($jobId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing job_id.']);
    exit;
}

$db = getDb();

// Verify job exists and is processing
$stmt = $db->prepare('SELECT * FROM stacking_jobs WHERE id = ? AND status = ?');
$stmt->execute([$jobId, 'processing']);
$job = $stmt->fetch();

if (!$job) {
    http_response_code(404);
    echo json_encode(['error' => 'Job not found or not in processing state.']);
    exit;
}

$db->beginTransaction();

try {
    // Insert stacked_frames row
    $stmt = $db->prepare(
        'INSERT INTO stacked_frames
            (stacking_job_id, user_id, object_name, chunk_key, ucloud_path, thumbnail_path,
             n_frames_input, n_frames_aligned, total_exptime, date_obs_start, date_obs_end,
             ra_deg, dec_deg, file_size_bytes, n_stars_detected)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $jobId,
        $job['user_id'],
        $job['object_name'],
        $job['chunk_key'],
        $input['ucloud_path'] ?? '',
        $input['thumbnail_path'] ?? null,
        (int)($input['n_frames_input'] ?? $job['frame_count']),
        (int)($input['n_frames_aligned'] ?? 0),
        $input['total_exptime'] ?? null,
        $input['date_obs_start'] ?? null,
        $input['date_obs_end'] ?? null,
        $input['ra_deg'] ?? null,
        $input['dec_deg'] ?? null,
        (int)($input['file_size_bytes'] ?? 0),
        isset($input['n_stars_detected']) ? (int)$input['n_stars_detected'] : null,
    ]);

    // Mark job completed
    $db->prepare(
        'UPDATE stacking_jobs SET status = ?, completed_at = NOW() WHERE id = ?'
    )->execute(['completed', $jobId]);

    // Delete local raw files from webspace disk and mark as deleted in DB
    $rawStmt = $db->prepare(
        'SELECT id, ucloud_path FROM raw_files
         WHERE upload_session_id = ? AND chunk_key = ? AND is_deleted = 0'
    );
    $rawStmt->execute([$job['upload_session_id'], $job['chunk_key']]);
    $rawFiles = $rawStmt->fetchAll();

    foreach ($rawFiles as $raw) {
        if (is_file($raw['ucloud_path'])) {
            unlink($raw['ucloud_path']);
        }
    }

    $db->prepare(
        'UPDATE raw_files SET is_deleted = 1
         WHERE upload_session_id = ? AND chunk_key = ?'
    )->execute([$job['upload_session_id'], $job['chunk_key']]);

    // If all chunks for this session are done, remove the session directory
    $remaining = $db->prepare(
        'SELECT COUNT(*) FROM raw_files
         WHERE upload_session_id = ? AND is_deleted = 0'
    );
    $remaining->execute([$job['upload_session_id']]);
    if ((int)$remaining->fetchColumn() === 0) {
        $sessStmt = $db->prepare(
            'SELECT ucloud_path FROM upload_sessions WHERE id = ?'
        );
        $sessStmt->execute([$job['upload_session_id']]);
        $sessDir = $sessStmt->fetchColumn();
        if ($sessDir && is_dir($sessDir)) {
            @rmdir($sessDir); // removes empty dir
        }
    }

    $db->commit();
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Internal error.']);
    error_log('complete_job error: ' . $e->getMessage());
}
