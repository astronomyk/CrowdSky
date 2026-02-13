<?php
/**
 * Return stacked frames data as JSON for the sky map visualization.
 *
 * GET — requires session cookie (logged-in user).
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

$userId = currentUserId();
if ($userId === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Login required.']);
    exit;
}

$db = getDb();

$stmt = $db->prepare(
    'SELECT sf.id, sf.ra_deg, sf.dec_deg, sf.object_name, sf.chunk_key,
            sf.date_obs_start, sf.n_frames_input, sf.n_frames_aligned,
            sf.total_exptime, sf.file_size_bytes
     FROM stacked_frames sf
     WHERE sf.user_id = ?
     ORDER BY sf.date_obs_start DESC'
);
$stmt->execute([$userId]);
$stacks = $stmt->fetchAll();

// Cast numeric fields
foreach ($stacks as &$s) {
    $s['id'] = (int)$s['id'];
    $s['ra_deg'] = $s['ra_deg'] !== null ? (float)$s['ra_deg'] : null;
    $s['dec_deg'] = $s['dec_deg'] !== null ? (float)$s['dec_deg'] : null;
    $s['n_frames_input'] = (int)$s['n_frames_input'];
    $s['n_frames_aligned'] = (int)$s['n_frames_aligned'];
    $s['total_exptime'] = $s['total_exptime'] !== null ? (float)$s['total_exptime'] : null;
    $s['file_size_bytes'] = (int)$s['file_size_bytes'];
}
unset($s);

echo json_encode($stacks);
