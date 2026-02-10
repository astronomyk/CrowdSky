<?php
/**
 * Worker API: POST /api/fail_job.php
 *
 * Marks a stacking job as failed with an error message.
 * Authenticated with Bearer WORKER_API_KEY.
 *
 * POST body (JSON): { job_id, error_message }
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

$errorMessage = $input['error_message'] ?? 'Unknown error';

$db = getDb();

$stmt = $db->prepare('SELECT id, retry_count FROM stacking_jobs WHERE id = ? AND status = ?');
$stmt->execute([$jobId, 'processing']);
$job = $stmt->fetch();

if (!$job) {
    http_response_code(404);
    echo json_encode(['error' => 'Job not found or not in processing state.']);
    exit;
}

// If under retry limit, mark as retry; otherwise mark as failed
$newStatus = ($job['retry_count'] < 3) ? 'retry' : 'failed';

$db->prepare(
    'UPDATE stacking_jobs
     SET status = ?, error_message = ?, retry_count = retry_count + 1, completed_at = NOW()
     WHERE id = ?'
)->execute([$newStatus, $errorMessage, $jobId]);

echo json_encode([
    'ok'     => true,
    'status' => $newStatus,
]);
