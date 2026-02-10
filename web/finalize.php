<?php
/**
 * Finalize an upload session: group raw files by chunk_key, create stacking_jobs.
 *
 * POST with session_token + csrf_token.
 * Returns JSON.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required.']);
    exit;
}

$userId = requireLogin();

if (!csrfValidate()) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token.']);
    exit;
}

$sessionToken = $_POST['session_token'] ?? '';
if (empty($sessionToken)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing session_token.']);
    exit;
}

$db = getDb();

// Fetch session
$stmt = $db->prepare(
    'SELECT id, user_id, file_count FROM upload_sessions
     WHERE session_token = ? AND user_id = ? AND status = ?'
);
$stmt->execute([$sessionToken, $userId, 'uploading']);
$session = $stmt->fetch();

if (!$session) {
    http_response_code(404);
    echo json_encode(['error' => 'Session not found or already finalized.']);
    exit;
}

if ($session['file_count'] == 0) {
    http_response_code(400);
    echo json_encode(['error' => 'No files in this session.']);
    exit;
}

$sessionId = (int)$session['id'];

// Group files by chunk_key
$stmt = $db->prepare(
    'SELECT chunk_key, COUNT(*) as cnt, MIN(fits_object) as object_name
     FROM raw_files
     WHERE upload_session_id = ? AND chunk_key IS NOT NULL
     GROUP BY chunk_key'
);
$stmt->execute([$sessionId]);
$chunks = $stmt->fetchAll();

if (empty($chunks)) {
    // Files had no parseable DATE-OBS — create one job for the whole session
    $chunks = [['chunk_key' => 'unknown', 'cnt' => $session['file_count'], 'object_name' => null]];
}

// Create stacking jobs
$insertJob = $db->prepare(
    'INSERT INTO stacking_jobs (user_id, upload_session_id, chunk_key, object_name, frame_count)
     VALUES (?, ?, ?, ?, ?)'
);

$jobIds = [];
foreach ($chunks as $chunk) {
    $insertJob->execute([
        $userId,
        $sessionId,
        $chunk['chunk_key'],
        $chunk['object_name'],
        $chunk['cnt'],
    ]);
    $jobIds[] = (int)$db->lastInsertId();
}

// Mark session complete
$db->prepare(
    'UPDATE upload_sessions SET status = ?, completed_at = NOW() WHERE id = ?'
)->execute(['complete', $sessionId]);

echo json_encode([
    'ok'       => true,
    'jobs'     => count($jobIds),
    'job_ids'  => $jobIds,
    'chunks'   => array_column($chunks, 'chunk_key'),
]);
