<?php
/**
 * Delete a stacking job and its associated data.
 *
 * POST JSON: { "job_id": int, "csrf_token": string }
 * Auth: session cookie (user must own the job).
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../webdav.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required.']);
    exit;
}

$userId = currentUserId();
if ($userId === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Login required.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body.']);
    exit;
}

$jobId = (int)($input['job_id'] ?? 0);
$csrfToken = $input['csrf_token'] ?? '';

if ($jobId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing job_id.']);
    exit;
}

// Validate CSRF — use the token from JSON body
if (!hash_equals(csrfToken(), $csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token.']);
    exit;
}

$db = getDb();

// Verify user owns this job
$stmt = $db->prepare(
    'SELECT sj.id, sj.upload_session_id, sj.chunk_key
     FROM stacking_jobs sj
     WHERE sj.id = ? AND sj.user_id = ?'
);
$stmt->execute([$jobId, $userId]);
$job = $stmt->fetch();

if (!$job) {
    http_response_code(404);
    echo json_encode(['error' => 'Job not found.']);
    exit;
}

$db->beginTransaction();
try {
    // 1. Find non-deleted raw files for this job's session + chunk_key
    $stmt = $db->prepare(
        'SELECT id, ucloud_path FROM raw_files
         WHERE upload_session_id = ? AND chunk_key = ? AND is_deleted = 0'
    );
    $stmt->execute([$job['upload_session_id'], $job['chunk_key']]);
    $rawFiles = $stmt->fetchAll();

    // 2. Delete physical raw files from webspace disk
    foreach ($rawFiles as $rf) {
        if (!empty($rf['ucloud_path']) && file_exists($rf['ucloud_path'])) {
            @unlink($rf['ucloud_path']);
        }
    }

    // 3. Mark raw_files as deleted
    if (!empty($rawFiles)) {
        $ids = array_column($rawFiles, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare(
            "UPDATE raw_files SET is_deleted = 1 WHERE id IN ($placeholders)"
        )->execute($ids);
    }

    // 4. If job produced stacked_frames, delete from u:cloud
    $stmt = $db->prepare(
        'SELECT id, ucloud_path, thumbnail_path FROM stacked_frames WHERE stacking_job_id = ?'
    );
    $stmt->execute([$jobId]);
    $stacks = $stmt->fetchAll();

    foreach ($stacks as $stack) {
        if (!empty($stack['ucloud_path'])) {
            deleteFromUcloud($stack['ucloud_path']);
        }
        if (!empty($stack['thumbnail_path'])) {
            deleteFromUcloud($stack['thumbnail_path']);
        }
    }

    // 5. Delete stacked_frames rows
    $db->prepare('DELETE FROM stacked_frames WHERE stacking_job_id = ?')->execute([$jobId]);

    // 6. Delete the stacking_job row
    $db->prepare('DELETE FROM stacking_jobs WHERE id = ?')->execute([$jobId]);

    $db->commit();
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    $db->rollBack();
    error_log("delete_job error for job $jobId: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to delete job.']);
}
