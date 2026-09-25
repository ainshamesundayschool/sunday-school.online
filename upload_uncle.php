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

// ── Image Sanitization Function (Preserves Exact Aspect Ratio) ─
function sanitizeAndSaveImage($filePath, $outputPath = null, $quality = 90) {
    if (!$outputPath) $outputPath = $filePath;
    if (!extension_loaded('gd')) return true;
    
    $info = @getimagesize($filePath);
    if (!$info) return false;
    $mime = $info['mime'];
    $source = null;
    if ($mime === 'image/jpeg' || $mime === 'image/pjpeg' || $mime === 'image/jpg') {
        $source = @imagecreatefromjpeg($filePath);
    } elseif ($mime === 'image/png' || $mime === 'image/x-png') {
        $source = @imagecreatefrompng($filePath);
    } elseif ($mime === 'image/gif') {
        $source = @imagecreatefromgif($filePath);
    } elseif ($mime === 'image/webp' || $mime === 'image/x-webp') {
        $source = @imagecreatefromwebp($filePath);
    }
    if (!$source) return false;
    
    $w = imagesx($source);
    $h = imagesy($source);
    
    $maxDim = 1400;
    if ($w > $maxDim || $h > $maxDim) {
        if ($w >= $h) {
            $newW = $maxDim;
            $newH = (int)round($h * ($maxDim / $w));
        } else {
            $newH = $maxDim;
            $newW = (int)round($w * ($maxDim / $h));
        }
    } else {
        $newW = $w;
        $newH = $h;
    }
    
    $dest = imagecreatetruecolor($newW, $newH);
    if ($mime === 'image/png' || $mime === 'image/x-png' || $mime === 'image/webp' || $mime === 'image/x-webp') {
        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        $transparent = imagecolorallocatealpha($dest, 255, 255, 255, 127);
        imagefilledrectangle($dest, 0, 0, $newW, $newH, $transparent);
    } else {
        $white = imagecolorallocate($dest, 255, 255, 255);
        imagefilledrectangle($dest, 0, 0, $newW, $newH, $white);
    }
    
    imagecopyresampled($dest, $source, 0, 0, 0, 0, $newW, $newH, $w, $h);
    imagedestroy($source);
    
    $ext = strtolower(pathinfo($outputPath, PATHINFO_EXTENSION));
    if ($ext === 'png') {
        imagepng($dest, $outputPath, 8);
    } elseif ($ext === 'webp') {
        imagewebp($dest, $outputPath, $quality);
    } else {
        imagejpeg($dest, $outputPath, $quality);
    }
    imagedestroy($dest);
    return true;
}

try {
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        sendJson(['success' => false, 'message' => 'لم يتم رفع أي ملف أو حدث خطأ أثناء الرفع']);
    }

    $file = $_FILES['photo'];
    $studentName = htmlspecialchars(trim($_POST['studentName'] ?? ''), ENT_QUOTES, 'UTF-8');
    $studentPhone = preg_replace('/[^\d]/', '', $_POST['studentPhone'] ?? '');
    
    $maxSize = 10 * 1024 * 1024; // 10MB
    if ($file['size'] > $maxSize) {
        sendJson(['success' => false, 'message' => 'حجم الملف كبير جداً (الحد الأقصى 10 ميجابايت)']);
    }

    // Comprehensive allowed image MIME mapping
    $allowedMimes = [
        'image/jpeg'   => 'jpg',
        'image/jpg'    => 'jpg',
        'image/pjpeg'  => 'jpg',
        'image/png'    => 'png',
        'image/x-png'  => 'png',
        'image/gif'    => 'gif',
        'image/webp'   => 'webp',
        'image/x-webp' => 'webp'
    ];

    $imageInfo = @getimagesize($file['tmp_name']);
    if (!$imageInfo || !isset($allowedMimes[$imageInfo['mime']])) {
        $isWebp = false;
        $fh = @fopen($file['tmp_name'], 'rb');
        if ($fh) {
            $hdr = fread($fh, 12);
            fclose($fh);
            if (substr($hdr, 0, 4) === 'RIFF' && substr($hdr, 8, 4) === 'WEBP') {
                $isWebp = true;
            }
        }
        if (!$isWebp) {
            sendJson(['success' => false, 'message' => 'نوع الملف غير مسموح به (الملف ليس صورة صالحة)']);
        }
        $extension = 'webp';
    } else {
        $extension = $allowedMimes[$imageInfo['mime']];
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!empty($realMime) && preg_match('/(php|html|javascript|executable|x-sh|shell)/i', $realMime)) {
            sendJson(['success' => false, 'message' => 'تم رفض الملف: محتوى الملف غير آمن']);
        }
    }

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
    
    // Sanitize image without altering aspect ratio
    if (extension_loaded('gd')) {
        @sanitizeAndSaveImage($filePath, $filePath, 90);
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