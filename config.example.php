<?php
/**
 * Configuration Template for Sunday School Platform
 * 
 * Instructions:
 * 1. Copy this file to `config.php`:
 *    cp config.example.php config.php
 * 2. Fill in your database credentials and optional application keys below.
 * 3. Keep `config.php` private and never commit it to git (it is included in .gitignore).
 */

// Define SCHEMA_MIGRATED if lock file exists to bypass schema checks
if (file_exists(__DIR__ . '/schema_migrated.lock')) {
    define('SCHEMA_MIGRATED', true);
}

// ============================================================
//  ENVIRONMENT DETECTION
// ============================================================
$isLocal = false;
if (defined('PHP_SAPI') && PHP_SAPI === 'cli') {
    // Under CLI, detect local development environment
    $cwd = __DIR__;
    if (strpos($cwd, '/Applications/XAMPP/') === 0 || strpos($cwd, '/Users/') === 0 || strpos($cwd, 'C:\\xampp') === 0) {
        $isLocal = true;
    }
} else {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === 'localhost' || $host === '127.0.0.1' || strpos($host, '192.168.') === 0 || strpos($host, '10.') === 0) {
        $isLocal = true;
    }
}

// ============================================================
//  DATABASE CONFIGURATION
// ============================================================
if ($isLocal) {
    // Local Development (e.g. XAMPP, MAMP, Local MySQL)
    define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
    define('DB_USER', getenv('DB_USER') ?: 'root');
    define('DB_PASS', getenv('DB_PASS') ?: '');
    define('DB_NAME', getenv('DB_NAME') ?: 'sunday_school_db');
} else {
    // Production / Remote Server Configuration
    define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
    define('DB_USER', getenv('DB_USER') ?: 'your_db_username');
    define('DB_PASS', getenv('DB_PASS') ?: 'your_db_password');
    define('DB_NAME', getenv('DB_NAME') ?: 'your_db_database');
}

// ============================================================
//  TIMEZONE — Egypt / Cairo (UTC+2 standard, UTC+3 daylight saving)
// ============================================================
date_default_timezone_set('Africa/Cairo');

// Ensure Cairo Egypt DST (Law 24 of 2023: UTC+3 from last Friday of April to last Thursday of October)
$currentTs = time();
$currentYear = (int)date('Y', $currentTs);

$dstStartTs = strtotime("last friday of april $currentYear 00:00:00 UTC") - 7200;
$dstEndTs   = strtotime("last thursday of october $currentYear 23:59:59 UTC") - 10800;

$isEgyptDst = ($currentTs >= $dstStartTs && $currentTs <= $dstEndTs);
$expectedOffsetHours = $isEgyptDst ? 3 : 2;
$expectedOffsetSeconds = $expectedOffsetHours * 3600;

if ((int)date('Z') !== $expectedOffsetSeconds) {
    $tzName = $isEgyptDst ? 'Etc/GMT-3' : 'Etc/GMT-2';
    date_default_timezone_set($tzName);
}

$mysqlOffset = sprintf('%s%02d:00', ($expectedOffsetHours >= 0 ? '+' : '-'), abs($expectedOffsetHours));

// ============================================================
//  UPLOAD PATH CONFIGURATION
// ============================================================
define('UPLOAD_DIR_KIDS', __DIR__ . '/uploads/');
define('UPLOAD_URL_KIDS', '/uploads/');

function getUploadDir(): string {
    $dir = UPLOAD_DIR_KIDS;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}

function getUploadUrl(): string {
    return UPLOAD_URL_KIDS;
}

// ============================================================
//  CONNECTION CACHE
// ============================================================
$_DB_CONNECTIONS = [];

function getDBConnection(): mysqli {
    global $_DB_CONNECTIONS, $mysqlOffset, $isLocal;
    if (isset($_DB_CONNECTIONS['kids']) && $_DB_CONNECTIONS['kids'] instanceof mysqli && $_DB_CONNECTIONS['kids']->ping()) {
        return $_DB_CONNECTIONS['kids'];
    }
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        throw new Exception('Database connection failed: ' . $conn->connect_error);
    }
    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '$mysqlOffset'");
    
    // Bypass schema checks if already migrated or on production server
    if (defined('SCHEMA_MIGRATED') || !$isLocal) {
        $_DB_CONNECTIONS['kids'] = $conn;
        return $conn;
    }

    // Ensure common optional columns exist
    $check = $conn->query("SHOW COLUMNS FROM students LIKE 'added_by'");
    if ($check && $check->num_rows == 0) {
        $conn->query("ALTER TABLE students ADD COLUMN added_by VARCHAR(100) DEFAULT NULL");
    }

    $checkGuest = $conn->query("SHOW COLUMNS FROM students LIKE 'is_guest'");
    if ($checkGuest && $checkGuest->num_rows == 0) {
        $conn->query("ALTER TABLE students ADD COLUMN is_guest TINYINT(1) DEFAULT 0");
    }

    $tableCheck = $conn->query("SHOW TABLES LIKE 'trip_registrations'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $resStudent = $conn->query("SHOW COLUMNS FROM `trip_registrations` LIKE 'student_id'");
        if ($resStudent && $resStudent->num_rows > 0) {
            $rowStudent = $resStudent->fetch_assoc();
            if (strtoupper($rowStudent['Null']) === 'NO') {
                $conn->query("ALTER TABLE `trip_registrations` MODIFY COLUMN `student_id` INT DEFAULT NULL");
            }
        }

        $resUncle = $conn->query("SHOW COLUMNS FROM `trip_registrations` LIKE 'uncle_id'");
        if ($resUncle && $resUncle->num_rows === 0) {
            $conn->query("ALTER TABLE `trip_registrations` ADD COLUMN `uncle_id` INT DEFAULT NULL AFTER `student_id`");
            $conn->query("CREATE INDEX idx_trip_reg_uncle ON `trip_registrations` (`uncle_id`)");
        }

        $resType = $conn->query("SHOW COLUMNS FROM `trip_registrations` LIKE 'registration_type'");
        if ($resType && $resType->num_rows > 0) {
            $row = $resType->fetch_assoc();
            if (strpos($row['Type'], 'uncle') === false) {
                $conn->query("ALTER TABLE `trip_registrations` MODIFY COLUMN `registration_type` ENUM('student', 'other_church_student', 'guest', 'uncle') DEFAULT 'student'");
            }
        }
    }

    @file_put_contents(__DIR__ . '/schema_migrated.lock', 'migrated');
    if (!defined('SCHEMA_MIGRATED')) {
        define('SCHEMA_MIGRATED', true);
    }

    $_DB_CONNECTIONS['kids'] = $conn;
    return $conn;
}

// Alias kept for API compatibility
function getDBConnectionForType(string $type): mysqli {
    return getDBConnection();
}

// ============================================================
//  SESSION RESTORE & CHURCH LOOKUP
// ============================================================

/**
 * Find a church by church_code or id.
 */
function findChurchInAnyDB($churchCodeOrId, $directId = 0): ?array {
    try {
        $conn = getDBConnection();
        $code = trim(strval($churchCodeOrId ?? ''));
        $id = intval($directId ?? 0);
        if ($id <= 0 && is_numeric($code) && intval($code) > 0) {
            $id = intval($code);
        }

        if ($id > 0) {
            $stmt = $conn->prepare('SELECT * FROM churches WHERE id = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $row['_db_type'] = 'kids';
                    return $row;
                }
            }
        }

        if ($code === '') return null;

        $stmt = $conn->prepare('SELECT * FROM churches WHERE church_code = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $code);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $row['_db_type'] = 'kids';
                return $row;
            }
        }

        $cleanCode = mb_strtolower(trim($code), 'UTF-8');
        $stmt = $conn->prepare('SELECT * FROM churches WHERE LOWER(TRIM(church_code)) = ? OR LOWER(TRIM(church_name)) = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('ss', $cleanCode, $cleanCode);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $row['_db_type'] = 'kids';
                return $row;
            }
        }
    } catch (Exception $e) {
        // DB unreachable
    }
    return null;
}

/**
 * Find an uncle/servant by username.
 */
function findUncleInAnyDB(string $username): ?array {
    try {
        $conn  = getDBConnection();
        $stmt  = $conn->prepare('SELECT * FROM uncles WHERE username = ? LIMIT 1');
        if (!$stmt) return null;
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $row    = $result->fetch_assoc();
        $stmt->close();
        if ($row) {
            $row['_db_type'] = 'kids';
            return $row;
        }
    } catch (Exception $e) {
        // DB unreachable
    }
    return null;
}

// ============================================================
//  COMMON HELPERS
// ============================================================

function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

function getClientIp(): string {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return trim($_SERVER['HTTP_CF_CONNECTING_IP']);
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return trim($_SERVER['HTTP_X_REAL_IP']);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function sendJSON($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
//  EMAIL CONFIGURATION (Hostinger SMTP & Google Apps Script)
// ============================================================
// Option 1: Hostinger SMTP (Recommended for custom domain emails)
define('HOSTINGER_SMTP_HOST', getenv('HOSTINGER_SMTP_HOST') ?: 'smtp.hostinger.com');
define('HOSTINGER_SMTP_PORT', intval(getenv('HOSTINGER_SMTP_PORT') ?: 465));
define('HOSTINGER_SMTP_USER', getenv('HOSTINGER_SMTP_USER') ?: 'your_email@sunday-school.online');
define('HOSTINGER_SMTP_PASS', getenv('HOSTINGER_SMTP_PASS') ?: 'your_email_password');
define('HOSTINGER_SMTP_FROM_NAME', getenv('HOSTINGER_SMTP_FROM_NAME') ?: 'منصة مدارس الأحد والشباب');

// Option 2: Google Apps Script Relay (Gmail delivery via Web App)
define('GOOGLE_APPS_SCRIPT_URL', getenv('GOOGLE_APPS_SCRIPT_URL') ?: 'https://script.google.com/macros/s/AKfycbxsDA0veJTA3C_2Bw47coffOagRigWwaZnyxWuGb_gSVUCWM958V1bUcaZDwfIHVZ7b1g/exec');

// ============================================================
//  WEB PUSH / VAPID CONFIGURATION
// ============================================================
// Generate your own VAPID keys for Web Push Notifications using:
// npx web-push generate-vapid-keys
define('VAPID_PUBLIC_KEY', getenv('VAPID_PUBLIC_KEY') ?: 'YOUR_VAPID_PUBLIC_KEY_HERE');
define('VAPID_PRIVATE_KEY', getenv('VAPID_PRIVATE_KEY') ?: 'YOUR_VAPID_PRIVATE_KEY_HERE');

// ============================================================
//  SESSION & REFRESH TOKEN ROTATION (RTR) CONFIGURATION
// ============================================================
// Leave AUTH_TOKEN_SECRET unset or empty to let the system generate
// a persistent random 256-bit secret stored in `.auth_secret`.
$envTokenSecret = getenv('AUTH_TOKEN_SECRET') 
    ?: ($_ENV['AUTH_TOKEN_SECRET'] 
    ?? ($_SERVER['AUTH_TOKEN_SECRET'] ?? ''));
if (!empty($envTokenSecret) && !defined('AUTH_TOKEN_SECRET')) {
    define('AUTH_TOKEN_SECRET', $envTokenSecret);
}

if (!defined('ACCESS_TOKEN_LIFETIME')) {
    define('ACCESS_TOKEN_LIFETIME', 900); // 15 minutes (900 seconds)
}
if (!defined('REFRESH_TOKEN_GRACE_PERIOD')) {
    define('REFRESH_TOKEN_GRACE_PERIOD', 5); // 5 seconds grace window for concurrent requests
}
if (!defined('SESSION_ABSOLUTE_LIFETIME')) {
    define('SESSION_ABSOLUTE_LIFETIME', 2592000); // 30 days (30 * 24 * 3600 seconds)
}
if (!defined('REFRESH_COOKIE_NAME')) {
    define('REFRESH_COOKIE_NAME', 'ss_refresh_token');
}
