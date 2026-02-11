<?php
/**
 * Cleanup cron endpoint: delete expired upload sessions and their local raw files.
 *
 * Call via cron:  curl -s https://site/api/cleanup.php?key=WORKER_API_KEY
 * Or:            php /path/to/web/api/cleanup.php
 *
 * Deletes local raws for sessions older than UPLOAD_EXPIRY_HOURS that are
 * still in 'uploading' state (abandoned) or already 'complete' with leftover files.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

// Authenticate: Bearer token or query param (for simple cron curl)
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$queryKey = $_GET['key'] ?? '';
$authenticated = false;

if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m) && hash_equals(WORKER_API_KEY, $m[1])) {
    $authenticated = true;
} elseif (!empty($queryKey) && hash_equals(WORKER_API_KEY, $queryKey)) {
    $authenticated = true;
} elseif (php_sapi_name() === 'cli') {
    $authenticated = true; // running from CLI cron
}

if (!$authenticated) {
    http_response_code(401);
    echo "Unauthorized\n";
    exit;
}

header('Content-Type: text/plain');

$db = getDb();
$expiryHours = defined('UPLOAD_EXPIRY_HOURS') ? UPLOAD_EXPIRY_HOURS : 24;

// Find expired sessions: uploading status and older than expiry threshold
$stmt = $db->prepare(
    'SELECT id, session_token, ucloud_path FROM upload_sessions
     WHERE status = ? AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)'
);
$stmt->execute(['uploading', $expiryHours]);
$expired = $stmt->fetchAll();

$cleanedSessions = 0;
$cleanedFiles = 0;

foreach ($expired as $session) {
    // Delete local raw files
    $files = $db->prepare(
        'SELECT id, ucloud_path FROM raw_files WHERE upload_session_id = ? AND is_deleted = 0'
    );
    $files->execute([$session['id']]);

    foreach ($files->fetchAll() as $file) {
        if (is_file($file['ucloud_path'])) {
            unlink($file['ucloud_path']);
            $cleanedFiles++;
        }
    }

    // Mark files as deleted
    $db->prepare(
        'UPDATE raw_files SET is_deleted = 1 WHERE upload_session_id = ?'
    )->execute([$session['id']]);

    // Mark session as expired
    $db->prepare(
        'UPDATE upload_sessions SET status = ? WHERE id = ?'
    )->execute(['expired', $session['id']]);

    // Remove empty session directory
    if (is_dir($session['ucloud_path'])) {
        @rmdir($session['ucloud_path']);
    }

    $cleanedSessions++;
}

// Also clean up any complete sessions that still have leftover files on disk
$stmt = $db->prepare(
    'SELECT rf.id, rf.ucloud_path
     FROM raw_files rf
     JOIN upload_sessions us ON us.id = rf.upload_session_id
     WHERE rf.is_deleted = 0
       AND us.status = ?
       AND us.completed_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)'
);
$stmt->execute(['complete']);
$leftovers = $stmt->fetchAll();

foreach ($leftovers as $file) {
    if (is_file($file['ucloud_path'])) {
        unlink($file['ucloud_path']);
        $cleanedFiles++;
    }
}

if (!empty($leftovers)) {
    $ids = array_column($leftovers, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("UPDATE raw_files SET is_deleted = 1 WHERE id IN ($placeholders)")->execute($ids);
}

echo "Cleanup done: {$cleanedSessions} expired sessions, {$cleanedFiles} files deleted.\n";
