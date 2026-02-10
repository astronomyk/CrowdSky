<?php
/**
 * Proxy download of a stacked FITS file from u:cloud.
 * Prevents exposing the WebDAV token to the browser.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

$userId = requireLogin();

$stackId = (int)($_GET['id'] ?? 0);
if ($stackId <= 0) {
    http_response_code(400);
    echo 'Missing stack ID.';
    exit;
}

$db = getDb();
$stmt = $db->prepare(
    'SELECT ucloud_path, chunk_key, object_name FROM stacked_frames WHERE id = ? AND user_id = ?'
);
$stmt->execute([$stackId, $userId]);
$stack = $stmt->fetch();

if (!$stack) {
    http_response_code(404);
    echo 'Stack not found.';
    exit;
}

$url = UCLOUD_WEBDAV_URL . '/' . ltrim($stack['ucloud_path'], '/');

// Stream from u:cloud to the browser
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_USERPWD        => UCLOUD_SHARE_TOKEN . ':',
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_TIMEOUT        => 300,
    CURLOPT_HEADERFUNCTION => function ($ch, $headerLine) {
        $lower = strtolower($headerLine);
        // Forward content-length
        if (strpos($lower, 'content-length:') === 0) {
            header($headerLine);
        }
        return strlen($headerLine);
    },
]);

$filename = ($stack['object_name'] ?? 'stack') . '_' . $stack['chunk_key'] . '.fits';
$filename = preg_replace('/[^a-zA-Z0-9._\-+]/', '_', $filename);

header('Content-Type: application/fits');
header('Content-Disposition: attachment; filename="' . $filename . '"');

curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    // Headers already sent, not much we can do — log it
    error_log("Download proxy failed for stack $stackId: HTTP $httpCode");
}
