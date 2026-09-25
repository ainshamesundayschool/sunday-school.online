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
ini_set('error_log', __DIR__ . '/photo_delete_errors.log');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $fileName = basename($input['fileName'] ?? '');
    
    if (empty($fileName)) {
        sendJson(['success' => false, 'message' => 'اسم الملف مطلوب']);
    }
    
    // Strict validation: must be a profile image
    if (!preg_match('/^profile_[a-zA-Z0-9_]+\.(jpg|jpeg|png|gif|webp)$/i', $fileName)) {
        sendJson(['success' => false, 'message' => 'اسم الملف غير صالح']);
    }
    
    $filePath = __DIR__ . '/uploads/students/' . $fileName;
    if (!file_exists($filePath)) {
        sendJson(['success' => false, 'message' => 'الملف غير موجود']);
    }
    
    $trashDir = __DIR__ . '/uploads/trash_bin/';
    if (!is_dir($trashDir)) {
        mkdir($trashDir, 0755, true);
    }
    
    $trashPath = $trashDir . time() . '_' . $fileName;
    if (rename($filePath, $trashPath)) {
        sendJson(['success' => true, 'message' => 'تم حذف الصورة بنجاح']);
    } else {
        sendJson(['success' => false, 'message' => 'فشل في حذف الملف']);
    }
} catch (Exception $e) {
    error_log("Delete photo error: " . $e->getMessage());
    sendJson(['success' => false, 'message' => 'خطأ في الخادم']);
}
?>