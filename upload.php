<?php
if (!headers_sent()) {
    header_remove('Access-Control-Allow-Origin');
    header_remove('Access-Control-Allow-Methods');
    header_remove('Access-Control-Allow-Headers');
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedHosts = ['sunday-school.online', 'sunday-school.rf.gd', 'localhost', '127.0.0.1'];
$host = parse_url($origin, PHP_URL_HOST);
if ($host && in_array(strtolower($host), $allowedHosts)) {
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

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

if (session_status() === PHP_SESSION_NONE) {
    $cookieLifetime = 315360000;
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

function sendJson($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Check Authentication ──────────────────────────────────────
$isAuthenticated = !empty($_SESSION['uncle_id']) || 
                   !empty($_SESSION['church_id']) || 
                   !empty($_SESSION['student_id']) || 
                   !empty($_SESSION['loggedIn']) || 
                   !empty($_SESSION['uncle_logged_in']);

// Allow registration temporary upload if registration session flag is set
$isRegistration = !empty($_POST['is_registration']) && !empty($_SESSION['registration_active']);

if (!$isAuthenticated && !$isRegistration) {
    http_response_code(401);
    sendJson(['success' => false, 'message' => 'غير مصرح لك برفع الملفات. يرجى تسجيل الدخول أولاً.']);
}

// ── Image Enhancement Function ──────────────────────────────
function enhanceImage($imagePath, $targetWidth = 400, $targetHeight = 500) {
    $imageInfo = @getimagesize($imagePath);
    if (!$imageInfo) return null;
    
    $mime = $imageInfo['mime'];
    $source = null;
    
    if ($mime === 'image/jpeg') {
        $source = @imagecreatefromjpeg($imagePath);
    } elseif ($mime === 'image/png') {
        $source = @imagecreatefrompng($imagePath);
    } elseif ($mime === 'image/gif') {
        $source = @imagecreatefromgif($imagePath);
    } elseif ($mime === 'image/webp') {
        $source = @imagecreatefromwebp($imagePath);
    }
    
    if (!$source) return null;
    
    $width = imagesx($source);
    $height = imagesy($source);
    
    $ratio = $width / $height;
    $targetRatio = $targetWidth / $targetHeight;
    
    if ($ratio > $targetRatio) {
        $newWidth = $targetHeight * $ratio;
        $newHeight = $targetHeight;
    } else {
        $newWidth = $targetWidth;
        $newHeight = $targetWidth / $ratio;
    }
    
    $newImage = imagecreatetruecolor($targetWidth, $targetHeight);
    
    if ($mime === 'image/png' || $mime === 'image/webp') {
        imagealphablending($newImage, false);
        imagesavealpha($newImage, true);
        $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
        imagefilledrectangle($newImage, 0, 0, $targetWidth, $targetHeight, $transparent);
    } else {
        $white = imagecolorallocate($newImage, 255, 255, 255);
        imagefilledrectangle($newImage, 0, 0, $targetWidth, $targetHeight, $white);
    }
    
    $srcX = ($width - ($targetWidth * ($height / $targetHeight))) / 2;
    $srcY = 0;
    $srcW = $targetWidth * ($height / $targetHeight);
    $srcH = $height;
    
    if ($ratio < $targetRatio) {
        $srcX = 0;
        $srcY = ($height - ($targetHeight * ($width / $targetWidth))) / 2;
        $srcW = $width;
        $srcH = $targetHeight * ($width / $targetWidth);
    }
    
    imagecopyresampled($newImage, $source, 0, 0, (int)$srcX, (int)$srcY, $targetWidth, $targetHeight, (int)$srcW, (int)$srcH);
    imagedestroy($source);
    
    return $newImage;
}

function saveEnhancedImage($image, $outputPath, $quality = 85) {
    $ext = strtolower(pathinfo($outputPath, PATHINFO_EXTENSION));
    if ($ext === 'png') {
        $pngQuality = (int)(9 - ($quality / 100 * 9));
        return imagepng($image, $outputPath, $pngQuality);
    } elseif ($ext === 'webp') {
        return imagewebp($image, $outputPath, $quality);
    }
    return imagejpeg($image, $outputPath, $quality);
}

try {
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        sendJson(['success' => false, 'message' => 'لم يتم رفع أي ملف أو حدث خطأ أثناء الرفع']);
    }

    $file = $_FILES['photo'];
    $studentName = htmlspecialchars(trim($_POST['studentName'] ?? ''), ENT_QUOTES, 'UTF-8');
    $studentPhone = preg_replace('/[^\d]/', '', $_POST['studentPhone'] ?? '');
    $uploadType = $_POST['type'] ?? 'student';
    $isQuestion = in_array($uploadType, ['question', 'task']);
    
    $applyEnhancement = !$isQuestion && isset($_POST['enhanceImage']) && $_POST['enhanceImage'] === 'true';
    
    $maxSize = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $maxSize) {
        sendJson(['success' => false, 'message' => 'حجم الملف كبير جداً (الحد الأقصى 5 ميجابايت)']);
    }

    // STRICT MIME & INTEGRITY CHECK using getimagesize & finfo
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

    // Extension strictly mapped from server-verified MIME, never from user input
    $extension = $allowedMimes[$imageInfo['mime']];

    // Generate unique random filename
    $timestamp = time();
    $random = bin2hex(random_bytes(8));

    if ($isQuestion) {
        $filename = "q_{$timestamp}_{$random}.{$extension}";
        $uploadSubdir = '/uploads/questions/';
    } else {
        $phoneTag = !empty($studentPhone) ? substr($studentPhone, -8) : 'user';
        $filename = "profile_{$phoneTag}_{$timestamp}_{$random}.{$extension}";
        $uploadSubdir = '/uploads/students/';
    }

    $uploadDir = __DIR__ . $uploadSubdir;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $filePath = $uploadDir . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        sendJson(['success' => false, 'message' => 'فشل في حفظ الملف على السيرفر']);
    }
    
    // Always re-encode / enhance profile images to sanitize any polyglot / EXIF payloads
    if (extension_loaded('gd')) {
        $enhanced = @enhanceImage($filePath, 400, 500);
        if ($enhanced) {
            @saveEnhancedImage($enhanced, $filePath, 85);
            imagedestroy($enhanced);
        }
    }
    
    $imageUrl = $uploadSubdir . $filename;
    
    sendJson([
        'success' => true,
        'message' => 'تم رفع الصورة بنجاح',
        'imageUrl' => $imageUrl,
        'fileName' => $filename,
        'studentName' => $studentName,
        'studentPhone' => $studentPhone,
        'enhanced' => extension_loaded('gd')
    ]);
    
} catch (Exception $e) {
    error_log("Upload error: " . $e->getMessage());
    sendJson(['success' => false, 'message' => 'خطأ في معالجة الملف']);
}
?>