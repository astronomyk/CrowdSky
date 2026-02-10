-- CrowdSky Database Schema
-- MariaDB / MySQL
-- Run: mysql -u crowdsky -p crowdsky < schema.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(64) NOT NULL UNIQUE,
    email           VARCHAR(255) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    tier            ENUM('free','pro','raw') NOT NULL DEFAULT 'free',
    storage_used_mb DECIMAL(10,2) NOT NULL DEFAULT 0,
    storage_limit_mb DECIMAL(10,2) NOT NULL DEFAULT 500,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    api_token       VARCHAR(64) NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS upload_sessions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    session_token   VARCHAR(64) NOT NULL UNIQUE,
    object_name     VARCHAR(255) NULL,
    ra_deg          DOUBLE NULL,
    dec_deg         DOUBLE NULL,
    file_count      INT UNSIGNED NOT NULL DEFAULT 0,
    total_size_mb   DECIMAL(10,2) NOT NULL DEFAULT 0,
    status          ENUM('uploading','complete','failed','expired') NOT NULL DEFAULT 'uploading',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at    DATETIME NULL,
    ucloud_path     VARCHAR(512) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS raw_files (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    upload_session_id INT UNSIGNED NOT NULL,
    filename          VARCHAR(255) NOT NULL,
    ucloud_path       VARCHAR(512) NOT NULL,
    file_size_bytes   BIGINT UNSIGNED NOT NULL,
    fits_date_obs     DATETIME NULL,
    fits_object       VARCHAR(255) NULL,
    fits_exptime      FLOAT NULL,
    fits_ra           DOUBLE NULL,
    fits_dec          DOUBLE NULL,
    chunk_key         VARCHAR(32) NULL,
    is_deleted        TINYINT(1) NOT NULL DEFAULT 0,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (upload_session_id) REFERENCES upload_sessions(id) ON DELETE CASCADE,
    INDEX idx_session (upload_session_id),
    INDEX idx_chunk (upload_session_id, chunk_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stacking_jobs (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id           INT UNSIGNED NOT NULL,
    upload_session_id INT UNSIGNED NOT NULL,
    chunk_key         VARCHAR(32) NOT NULL,
    object_name       VARCHAR(255) NULL,
    pointing_key      VARCHAR(32) NULL,
    frame_count       INT UNSIGNED NOT NULL DEFAULT 0,
    status            ENUM('pending','processing','completed','failed','retry') NOT NULL DEFAULT 'pending',
    worker_id         VARCHAR(64) NULL,
    started_at        DATETIME NULL,
    completed_at      DATETIME NULL,
    error_message     TEXT NULL,
    retry_count       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (upload_session_id) REFERENCES upload_sessions(id) ON DELETE CASCADE,
    INDEX idx_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stacked_frames (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stacking_job_id   INT UNSIGNED NOT NULL,
    user_id           INT UNSIGNED NOT NULL,
    object_name       VARCHAR(255) NULL,
    chunk_key         VARCHAR(32) NOT NULL,
    ucloud_path       VARCHAR(512) NOT NULL,
    thumbnail_path    VARCHAR(512) NULL,
    n_frames_input    INT UNSIGNED NOT NULL,
    n_frames_aligned  INT UNSIGNED NOT NULL,
    total_exptime     FLOAT NULL,
    date_obs_start    DATETIME NULL,
    date_obs_end      DATETIME NULL,
    ra_deg            DOUBLE NULL,
    dec_deg           DOUBLE NULL,
    file_size_bytes   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    n_stars_detected  INT UNSIGNED NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (stacking_job_id) REFERENCES stacking_jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_object (user_id, object_name),
    INDEX idx_user_date (user_id, date_obs_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
