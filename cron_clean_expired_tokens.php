<?php
// cron_clean_expired_tokens.php
// Production Garbage Collection Cron Job for Auth Refresh Tokens
// Recommended schedule: Daily at 03:00 AM (0 3 * * *)

header('Content-Type: text/plain; charset=utf-8');

try {
    $configRoot = __DIR__;
    while ($configRoot && !file_exists($configRoot . '/api.php')) {
        $configParent = dirname($configRoot);
        if ($configParent === $configRoot) {
            break;
        }
        $configRoot = $configParent;
    }
    $isTesting = (strpos($configRoot, '/testing') !== false);
    $configName = $isTesting ? 'config-testing.php' : 'config.php';

    $configFile = $configRoot . '/' . $configName;
    if (!is_file($configFile)) {
        $configFile = dirname($configRoot) . '/' . $configName;
        if (!is_file($configFile)) {
            throw new Exception("Configuration file ($configName) not found.");
        }
    }
    require_once $configFile;

    if (!function_exists('getDBConnection')) {
        throw new Exception("getDBConnection function is not defined.");
    }

    $conn = getDBConnection();

    // 1. Purge refresh tokens where absolute expiration passed > 7 days ago
    // or revoked tokens older than 14 days
    $purgeSql = "
        DELETE FROM auth_refresh_tokens 
        WHERE absolute_expires_at < NOW() - INTERVAL 7 DAY
           OR (status = 'revoked' AND revoked_at IS NOT NULL AND revoked_at < NOW() - INTERVAL 14 DAY)
           OR expires_at < NOW() - INTERVAL 30 DAY
    ";
    $stmtRtr = $conn->prepare($purgeSql);
    $deletedRtr = 0;
    if ($stmtRtr) {
        $stmtRtr->execute();
        $deletedRtr = $stmtRtr->affected_rows;
        $stmtRtr->close();
    }

    // 2. Also clean legacy user_auth_tokens expired > 7 days ago
    $deletedLegacy = 0;
    $stmtLegacy = $conn->prepare("DELETE FROM user_auth_tokens WHERE expires_at < NOW() - INTERVAL 7 DAY");
    if ($stmtLegacy) {
        $stmtLegacy->execute();
        $deletedLegacy = $stmtLegacy->affected_rows;
        $stmtLegacy->close();
    }

    // 3. Optimize tables if rows were deleted
    if ($deletedRtr > 0) {
        @$conn->query("OPTIMIZE TABLE auth_refresh_tokens");
    }
    if ($deletedLegacy > 0) {
        @$conn->query("OPTIMIZE TABLE user_auth_tokens");
    }

    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] Success: Purged $deletedRtr expired/revoked refresh tokens and $deletedLegacy legacy auth tokens.\n";

} catch (Exception $e) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] Error: " . $e->getMessage() . "\n";
    error_log("cron_clean_expired_tokens.php error: " . $e->getMessage());
}
