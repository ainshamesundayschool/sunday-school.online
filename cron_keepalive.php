<?php
/**
 * Cron Clean-Up Script
 * 
 * Purpose:
 * Cleans up old expired OTP entries from phone_verifications table.
 * 
 * Hostinger Cron Job Setup:
 * - Recommended Command: curl -s https://sunday-school.rf.gd/cron_keepalive.php
 *   (or: php /home/uXXXXXX/public_html/cron_keepalive.php)
 * - Recommended Schedule: Once per hour
 */

header('Content-Type: text/plain; charset=utf-8');
date_default_timezone_set('Africa/Cairo');

require_once __DIR__ . '/config.php';

// Clean up expired OTPs older than 24 hours
$cleanedCount = 0;
try {
    if (function_exists('getDBConnection')) {
        $conn = getDBConnection();
        $stmt = $conn->prepare("DELETE FROM phone_verifications WHERE created_at < NOW() - INTERVAL 24 HOUR");
        if ($stmt) {
            $stmt->execute();
            $cleanedCount = $stmt->affected_rows;
        }
    }
} catch (Throwable $e) {
    // Non-blocking cleanup error handling
}

$now = date('Y-m-d H:i:s');
echo "[$now] Expired OTPs Cleaned: $cleanedCount\n";

