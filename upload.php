<?php
if (!headers_sent()) {
    header_remove('Access-Control-Allow-Origin');
    header_remove('Access-Control-Allow-Methods');
    header_remove('Access-Control-Allow-Headers');
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host = parse_url($origin, PHP_URL_HOST);
if ($origin && ($host === 'localhost' || $host === '127.0.0.1' || preg_match('/(^|\.)(sunday-school\.online|sunday-school\.rf\.gd)$/i', (string)$host))) {
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

// ── Check Authentication ──────────────────────────────────────
$isAuthenticated = !empty($_SESSION['uncle_id']) || 
                   !empty($_SESSION['church_id']) || 
                   !empty($_SESSION['student_id']) || 
                   !empty($_SESSION['loggedIn']) || 
                   !empty($_SESSION['uncle_logged_in']) ||
                   !empty($_SESSION['student_logged_in']);

$studentId = intval($_POST['studentId'] ?? $_POST['student_id'] ?? 0);
$studentPhone = preg_replace('/[^\d]/', '', $_POST['studentPhone'] ?? '');

// If session cookie wasn't available, authenticate student against database
if (!$isAuthenticated && $studentId > 0 && !empty($studentPhone)) {
    if (function_exists('getDBConnection')) {
        try {
            $conn = getDBConnection();
            $chk = $conn->prepare("SELECT id FROM students WHERE id = ? AND (phone = ? OR emergency_phone = ? OR parent_phones LIKE ?) LIMIT 1");
            if ($chk) {
                $likePhone = '%' . $studentPhone . '%';
                $chk->bind_param("isss", $studentId, $studentPhone, $studentPhone, $likePhone);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    $isAuthenticated = true;
                }
                $chk->close();
            }
        } catch (Throwable $e) {}
    }
}

// Allow registration upload
$isRegistration = !empty($_POST['is_registration']);

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
    
    // Scale down if unnecessarily huge (> 1400px) while maintaining exact aspect ratio
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
    
    saveEnhancedImage($dest, $outputPath, $quality);
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
    $uploadType = $_POST['type'] ?? 'student';
    $isQuestion = in_array($uploadType, ['question', 'task']);
    
    $applyEnhancement = !$isQuestion && isset($_POST['enhanceImage']) && $_POST['enhanceImage'] === 'true';
    
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
        // Fallback for WebP binary signature if GD/fileinfo missing WebP mime
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
        // Only reject if finfo reports an explicit executable / script
        if (!empty($realMime) && preg_match('/(php|html|javascript|executable|x-sh|shell)/i', $realMime)) {
            sendJson(['success' => false, 'message' => 'تم رفض الملف: محتوى الملف غير آمن']);
        }
    }

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
        @mkdir($uploadDir, 0777, true);
        @chmod($uploadDir, 0777);
    }
    
    $filePath = $uploadDir . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        sendJson(['success' => false, 'message' => 'فشل في حفظ الملف على السيرفر']);
    }
    
    // Sanitize image re-encoding without distorting aspect ratio
    if (extension_loaded('gd')) {
        if ($applyEnhancement) {
            $enhanced = @enhanceImage($filePath, 400, 500);
            if ($enhanced) {
                @saveEnhancedImage($enhanced, $filePath, 85);
                imagedestroy($enhanced);
            }
        } else {
            // Keep exact aspect ratio so photo is NEVER cut off
            @sanitizeAndSaveImage($filePath, $filePath, 90);
        }
    }
    
    $imageUrl = $uploadSubdir . $filename;
    
    sendJson([
        'success' => true,
        'message' => 'تم رفع الصورة بنجاح',
        'imageUrl' => $imageUrl,
        'url' => $imageUrl,
        'fileName' => $filename,
        'studentName' => $studentName,
        'studentPhone' => $studentPhone,
        'enhanced' => $applyEnhancement && extension_loaded('gd')
    ]);
    
} catch (Exception $e) {
    error_log("Upload error: " . $e->getMessage());
    sendJson(['success' => false, 'message' => 'خطأ في معالجة الملف']);
}
?>