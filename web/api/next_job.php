<?php
/**
 * Worker API: GET /api/next_job.php
 *
 * Claims the next pending stacking job atomically.
 * Authenticated with Bearer WORKER_API_KEY.
 *
 * Returns JSON with job details, or 204 if no jobs available.
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

$workerId = $_GET['worker_id'] ?? 'default';

$db = getDb();

// Claim next pending job atomically
$db->beginTransaction();

try {
    $stmt = $db->prepare(
        'SELECT id, user_id, upload_session_id, chunk_key, object_name, frame_count
         FROM stacking_jobs
         WHERE status = ?
         ORDER BY created_at ASC
         LIMIT 1
         FOR UPDATE SKIP LOCKED'
    );
    $stmt->execute(['pending']);
    $job = $stmt->fetch();

    if (!$job) {
        // Also check for retry jobs
        $stmt = $db->prepare(
            'SELECT id, user_id, upload_session_id, chunk_key, object_name, frame_count
             FROM stacking_jobs
             WHERE status = ? AND retry_count < 3
             ORDER BY created_at ASC
             LIMIT 1
             FOR UPDATE SKIP LOCKED'
        );
        $stmt->execute(['retry']);
        $job = $stmt->fetch();
    }

    if (!$job) {
        $db->commit();
        http_response_code(204);
        exit;
    }

    // Mark as processing
    $update = $db->prepare(
        'UPDATE stacking_jobs SET status = ?, worker_id = ?, started_at = NOW() WHERE id = ?'
    );
    $update->execute(['processing', $workerId, $job['id']]);
    $db->commit();

    // Get the upload session's u:cloud path
    $stmt = $db->prepare('SELECT ucloud_path FROM upload_sessions WHERE id = ?');
    $stmt->execute([$job['upload_session_id']]);
    $session = $stmt->fetch();

    echo json_encode([
        'job_id'            => (int)$job['id'],
        'user_id'           => (int)$job['user_id'],
        'upload_session_id' => (int)$job['upload_session_id'],
        'chunk_key'         => $job['chunk_key'],
        'object_name'       => $job['object_name'],
        'frame_count'       => (int)$job['frame_count'],
        'session_ucloud_path' => $session['ucloud_path'] ?? null,
    ]);
} catch (Exception $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Internal error.']);
    error_log('next_job error: ' . $e->getMessage());
}
