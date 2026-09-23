-- ============================================================
-- Migration: Create auth_refresh_tokens table for Session Management & Refresh Token Rotation (RTR)
-- Date: 2026-09-23
-- ============================================================

CREATE TABLE IF NOT EXISTS `auth_refresh_tokens` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `family_id` VARCHAR(64) NOT NULL COMMENT 'Unique identifier for the session family (continuous rotation chain)',
    `user_type` VARCHAR(20) NOT NULL COMMENT 'Type of user: uncle or church',
    `user_id` INT NOT NULL COMMENT 'ID of the user or church record',
    `token_hash` VARCHAR(64) NOT NULL UNIQUE COMMENT 'SHA-256 hash of the plain refresh token',
    `parent_token_id` BIGINT NULL DEFAULT NULL COMMENT 'Reference to previous token in rotation chain',
    `status` ENUM('active', 'revoked') NOT NULL DEFAULT 'active' COMMENT 'Current token state',
    `revoked_at` DATETIME NULL DEFAULT NULL COMMENT 'Timestamp when token was rotated or revoked',
    `revocation_reason` VARCHAR(50) NULL DEFAULT NULL COMMENT 'rotated, logout, theft_detected, expired',
    `grace_until` DATETIME NULL DEFAULT NULL COMMENT 'Grace window (5 seconds) allowing concurrent requests before considering reuse an attack',
    `ip_address` VARCHAR(45) NULL DEFAULT NULL COMMENT 'Client IP address',
    `user_agent` VARCHAR(500) NULL DEFAULT NULL COMMENT 'Client User-Agent string',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Creation timestamp',
    `expires_at` DATETIME NOT NULL COMMENT 'Sliding/token expiration timestamp',
    `absolute_expires_at` DATETIME NOT NULL COMMENT 'Strict 30-day session maximum expiration timestamp',
    INDEX `idx_family_id` (`family_id`),
    INDEX `idx_user_type_id` (`user_type`, `user_id`),
    INDEX `idx_status_expires` (`status`, `expires_at`),
    INDEX `idx_absolute_expires` (`absolute_expires_at`),
    INDEX `idx_grace_until` (`grace_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
