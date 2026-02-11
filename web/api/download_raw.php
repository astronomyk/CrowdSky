<?php
/**
 * Worker API: GET /api/download_raw.php?file_id=X
 *
 * Streams a raw FITS file from local webspace storage to the worker.
 * Authenticated with Bearer WORKER_API_KEY.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

// Authenticate worker
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m) || !hash_equals(WORKER_API_KEY, $m[1])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized.']);
    exit;
}

$fileId = (int)($_GET['file_id'] ?? 0);
if ($fileId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing or invalid file_id.']);
    exit;
}

$db = getDb();
$stmt = $db->prepare(
    'SELECT filename, ucloud_path, file_size_bytes FROM raw_files WHERE id = ? AND is_deleted = 0'
);
$stmt->execute([$fileId]);
$file = $stmt->fetch();

if (!$file || !is_file($file['ucloud_path'])) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'File not found.']);
    exit;
}

// Stream the file
header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($file['ucloud_path']));
header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
readfile($file['ucloud_path']);
