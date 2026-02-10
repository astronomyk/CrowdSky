<?php
/**
 * CrowdSky Configuration Template
 * Copy to config.php and fill in real values.
 */

// Database (MariaDB on univie webspace)
define('DB_HOST', 'localhost');
define('DB_NAME', 'crowdsky');
define('DB_USER', 'crowdsky');
define('DB_PASS', 'CHANGE_ME');

// u:cloud WebDAV
define('UCLOUD_WEBDAV_URL', 'https://ucloud.univie.ac.at/public.php/webdav');
define('UCLOUD_SHARE_TOKEN', 'CHANGE_ME');
define('UCLOUD_BASE_PATH', '/crowdsky');

// Worker API
define('WORKER_API_KEY', 'CHANGE_ME_GENERATE_A_RANDOM_64_CHAR_STRING');

// Upload limits
define('MAX_FILE_SIZE', 50 * 1024 * 1024); // 50 MB
define('ALLOWED_EXTENSIONS', ['fit', 'fits']);

// Session
define('SESSION_LIFETIME', 3600); // 1 hour

// Site
define('SITE_URL', 'https://yoursite.univie.ac.at/crowdsky');
define('SITE_NAME', 'CrowdSky');
