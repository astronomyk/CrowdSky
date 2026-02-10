<?php
/**
 * u:cloud WebDAV helpers using cURL.
 *
 * All paths are relative to UCLOUD_WEBDAV_URL defined in config.php.
 */

require_once __DIR__ . '/config.php';

/**
 * Create a directory (collection) on u:cloud via MKCOL.
 * Creates parent directories recursively.
 *
 * @param string $remotePath  Path relative to WebDAV root, e.g. "crowdsky/raws/user_1"
 * @return bool True on success or if directory already exists.
 */
function mkcolUcloud(string $remotePath): bool
{
    // Create each path segment incrementally
    $parts = array_filter(explode('/', $remotePath));
    $current = '';

    foreach ($parts as $part) {
        $current .= '/' . $part;
        $url = UCLOUD_WEBDAV_URL . $current;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'MKCOL',
            CURLOPT_USERPWD        => UCLOUD_SHARE_TOKEN . ':',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 201 = created, 405 = already exists — both OK
        if ($httpCode !== 201 && $httpCode !== 405) {
            error_log("MKCOL failed for $current: HTTP $httpCode");
            return false;
        }
    }

    return true;
}

/**
 * Upload a file to u:cloud via PUT.
 *
 * @param string   $localPath   Local file path.
 * @param string   $remotePath  Remote path relative to WebDAV root.
 * @return bool True on success.
 */
function uploadToUcloud(string $localPath, string $remotePath): bool
{
    $url = UCLOUD_WEBDAV_URL . '/' . ltrim($remotePath, '/');
    $fileSize = filesize($localPath);
    $fh = fopen($localPath, 'rb');

    if ($fh === false) {
        return false;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_PUT            => true,
        CURLOPT_USERPWD        => UCLOUD_SHARE_TOKEN . ':',
        CURLOPT_INFILE         => $fh,
        CURLOPT_INFILESIZE     => $fileSize,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 300, // 5 min for large files
    ]);

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);

    return in_array($httpCode, [200, 201, 204], true);
}

/**
 * Upload raw data (from php://input or a string) to u:cloud via PUT.
 *
 * @param string $data       Raw file contents.
 * @param string $remotePath Remote path relative to WebDAV root.
 * @return bool True on success.
 */
function uploadDataToUcloud(string $data, string $remotePath): bool
{
    $url = UCLOUD_WEBDAV_URL . '/' . ltrim($remotePath, '/');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_USERPWD        => UCLOUD_SHARE_TOKEN . ':',
        CURLOPT_POSTFIELDS     => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/octet-stream',
            'Content-Length: ' . strlen($data),
        ],
    ]);

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return in_array($httpCode, [200, 201, 204], true);
}

/**
 * Delete a file or directory from u:cloud via DELETE.
 *
 * @param string $remotePath Remote path relative to WebDAV root.
 * @return bool True on success.
 */
function deleteFromUcloud(string $remotePath): bool
{
    $url = UCLOUD_WEBDAV_URL . '/' . ltrim($remotePath, '/');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_USERPWD        => UCLOUD_SHARE_TOKEN . ':',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return in_array($httpCode, [200, 204], true);
}
