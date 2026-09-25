<?php
if (!headers_sent()) {
    header_remove('Access-Control-Allow-Origin');
    header_remove('Access-Control-Allow-Methods');
    header_remove('Access-Control-Allow-Headers');
}

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

function sendJson($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Security: Require active servant or admin session
$isAuthorized = !empty($_SESSION['uncle_id']) || !empty($_SESSION['church_id']) || !empty($_SESSION['uncle_logged_in']);
if (!$isAuthorized) {
    http_response_code(403);
    sendJson(['success' => false, 'message' => 'غير مصرح بالوصول']);
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/photo_list_errors.log');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $studentPhone = $input['studentPhone'] ?? '';
    $cleanPhone = preg_replace('/[^0-9]/', '', $studentPhone);
    
    if (empty($cleanPhone) || strlen($cleanPhone) < 8) {
        sendJson(['success' => false, 'message' => 'رقم هاتف الطالب مطلوب']);
    }
    
    $uploadDir = __DIR__ . '/uploads/students/';
    $realUploadDir = realpath($uploadDir);
    if (!$realUploadDir || !is_dir($realUploadDir)) {
        sendJson(['success' => false, 'message' => 'المجلد غير موجود']);
    }
    
    $files = glob($uploadDir . '*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE);
    $fileNames = array_map('basename', $files);
    
    $last8 = substr($cleanPhone, -8);
    $pattern = '/^profile_.*' . preg_quote($last8, '/') . '.*$/i';
    
    $filteredFiles = array_filter($fileNames, function($fileName) use ($pattern) {
        return preg_match($pattern, $fileName);
    });
    
    sendJson([
        'success' => true,
        'files' => array_values($filteredFiles),
        'total' => count($filteredFiles)
    ]);
} catch (Exception $e) {
    error_log("List photos error: " . $e->getMessage());
    sendJson(['success' => false, 'message' => 'خطأ في الخادم']);
}
?>