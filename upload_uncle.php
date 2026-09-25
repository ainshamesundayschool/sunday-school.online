<?php
if (!headers_sent()) {
    header_remove('Access-Control-Allow-Origin');
    header_remove('Access-Control-Allow-Methods');
    header_remove('Access-Control-Allow-Headers');
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host = parse_url($origin, PHP_URL_HOST);
if ($origin && ($host === 'localhost' || $host === '127.0.0.1' || preg_match('/(^|\.)(sunday-school\.online|sunday-school\.rf.gd)$/i', (string)$host))) {
    header("Access-Control-Allow-Origin: $origin");
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/upload_errors.log');

$rootPath = dirname(__FILE__);
while ($rootPath && !file_exists($rootPath . '/api.php')) {
    $parent = dirname($rootPath);
    if ($parent === $rootPath)
        break;
    $rootPath = $parent;
}
$sessionPath = $rootPath . '/.sessions';
if (!is_dir($sessionPath)) {
    @mkdir($sessionPath, 0777, true);
    @chmod($sessionPath, 0777);
}
if (is_writable($sessionPath)) {
    session_save_path($sessionPath);
}

$cookieLifetime = 315360000;
ini_set('session.gc_maxlifetime', $cookieLifetime);
ini_set('session.cookie_lifetime', $cookieLifetime);
if (session_status() === PHP_SESSION_NONE) {
    @session_set_cookie_params([
        'lifetime' => $cookieLifetime,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    @session_start();
}

if (file_exists($rootPath . '/config.php')) {
    require_once $rootPath . '/config.php';
}

function sendJson($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Check Servant / Admin Authentication ──────────────────────
$isServantOrAdmin = !empty($_SESSION['uncle_id']) || 
                    !empty($_SESSION['church_id']) || 
                    !empty($_SESSION['uncle_logged_in']) ||
                    !empty($_SESSION['loggedIn']);

$uncleId = intval($_POST['uncle_id'] ?? $_POST['uncleId'] ?? 0);
$username = trim($_POST['username'] ?? '');

// Database fallback check if session cookie was omitted
if (!$isServantOrAdmin && ($uncleId > 0 || !empty($username))) {
    if (function_exists('getDBConnection')) {
        try {
            $conn = getDBConnection();
            $stmt = $conn->prepare("SELECT id FROM uncles WHERE (id = ? AND id > 0) OR (username = ? AND username != '') LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("is", $uncleId, $username);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $isServantOrAdmin = true;
                }
                $stmt->close();
            }
        } catch (Throwable $e) {}
    }
}

if (!$isServantOrAdmin) {
    http_response_code(401);
    sendJson(['success' => false, 'message' => 'غير مصرح لك. يجب تسجيل الدخول كخادم أولاً.']);
}

try {
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        sendJson(['success' => false, 'message' => 'لم يتم رفع أي ملف أو حدث خطأ أثناء الرفع']);
    }

    $file = $_FILES['photo'];
    $studentName = htmlspecialchars(trim($_POST['studentName'] ?? ''), ENT_QUOTES, 'UTF-8');
    $studentPhone = preg_replace('/[^\d]/', '', $_POST['studentPhone'] ?? '');
    
    $maxSize = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $maxSize) {
        sendJson(['success' => false, 'message' => 'حجم الملف كبير جداً (الحد الأقصى 5 ميجابايت)']);
    }

    // STRICT MIME & INTEGRITY CHECK
    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp'
    ];

    $imageInfo = @getimagesize($file['tmp_name']);
    if (!$imageInfo || !isset($allowedMimes[$imageInfo['mime']])) {
        sendJson(['success' => false, 'message' => 'نوع الملف غير مسموح به (الملف ليس صورة صالحة)']);
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!isset($allowedMimes[$realMime])) {
            sendJson(['success' => false, 'message' => 'تم رفض الملف: محتوى الملف لا يطابق نوع الصورة المسموح بها']);
        }
    }

    $extension = $allowedMimes[$imageInfo['mime']];

    // Generate unique random filename
    $timestamp = time();
    $random = bin2hex(random_bytes(8));
    $phoneTag = !empty($studentPhone) ? substr($studentPhone, -8) : 'servant';
    $filename = "profile_{$phoneTag}_{$timestamp}_{$random}.{$extension}";
    
    $uploadDir = __DIR__ . '/uploads/uncle/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
        @chmod($uploadDir, 0777);
    }
    
    $filePath = $uploadDir . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        sendJson(['success' => false, 'message' => 'فشل في حفظ الملف على السيرفر']);
    }
    
    $relativeUrl = '/uploads/uncle/' . $filename;
    $baseUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'sunday-school.online');
    $fullUrl = $baseUrl . $relativeUrl;
    
    sendJson([
        'success' => true,
        'message' => 'تم رفع الصورة بنجاح',
        'imageUrl' => $relativeUrl,
        'fullUrl' => $fullUrl,
        'url' => $relativeUrl,
        'fileName' => $filename,
        'studentName' => $studentName,
        'studentPhone' => $studentPhone
    ]);
    
} catch (Exception $e) {
    error_log("Upload error: " . $e->getMessage());
    sendJson(['success' => false, 'message' => 'خطأ في معالجة الملف']);
}
?>