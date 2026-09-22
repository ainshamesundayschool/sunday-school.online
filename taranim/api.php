<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// MYSQL CONFIGURATION (INFINITYFREE / ONLINE SERVER)
$mysqlHost = 'sql311.infinityfree.com';
$mysqlPort = 3306;
$mysqlDb   = 'if0_42112851_taranim';
$mysqlUser = 'if0_42112851';
$mysqlPass = 'MwfgtlTqep1';

$sqlitePath = __DIR__ . '/database.sqlite';
$liveFile   = __DIR__ . '/live.json';

$requestUri = $_SERVER['REQUEST_URI'];
$parsedUrl  = parse_url($requestUri, PHP_URL_PATH);

// MOBILE REMOTE CONTROL & HOTSPOT REAL-TIME SYNC
if ((isset($_GET['action']) && (strpos($_GET['action'], 'remote_') === 0 || strpos($_GET['action'], 'hotspot_') === 0)) || (isset($_REQUEST['action']) && (strpos($_REQUEST['action'], 'remote_') === 0 || strpos($_REQUEST['action'], 'hotspot_') === 0)) || (isset($_GET['action']) && in_array($_GET['action'], ['create_room', 'join_room', 'push_state', 'get_state', 'send_command', 'poll_commands', 'get_my_ip', 'host_ip', 'hotspot_info', 'hotspot_ping', 'hotspot_devices']))) {
    require __DIR__ . '/remote_sync.php';
    exit;
}

// LIVE PRESENTATION STATE SYNC ENDPOINT (INSTANT 0.001s RESPONSE WITHOUT WAITING FOR DATABASE)
if (strpos($parsedUrl, '/api/live') !== false || (isset($_GET['action']) && $_GET['action'] === 'live') || isset($_GET['live'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $inputRaw = file_get_contents('php://input');
        if (!empty($inputRaw)) {
            file_put_contents($liveFile, $inputRaw, LOCK_EX);
            // Also register client IP in hotspot devices
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $clientIp = trim($parts[0]);
            }
            if ($clientIp && !str_starts_with($clientIp, '127.')) {
                $devFile = __DIR__ . '/data/hotspot_devices.json';
                $devs = file_exists($devFile) ? (@json_decode(file_get_contents($devFile), true) ?: []) : [];
                $devs[$clientIp] = [
                    'ip' => $clientIp,
                    'name' => 'لوحة تحكم',
                    'type' => 'CONTROL_PANEL',
                    'last_seen' => time()
                ];
                @file_put_contents($devFile, json_encode($devs, JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
        }
        echo json_encode(['status' => 'success']);
        exit;
    } else {
        if (file_exists($liveFile) && filesize($liveFile) > 0) {
            echo file_get_contents($liveFile);
        } else {
            echo json_encode(['type' => 'PRESENT_LINE', 'text' => '', 'isBlank' => true]);
        }
        exit;
    }
}

// =========================================================================
// SEPARATE DATABASE FOR APPROVED USER/COMMUNITY TARANIM (custom_songs.sqlite)
// =========================================================================
function getCustomSongsPdo() {
    static $customPdo = null;
    if ($customPdo !== null) return $customPdo;
    $customDbPath = __DIR__ . '/custom_songs.sqlite';
    try {
        $customPdo = new PDO('sqlite:' . $customDbPath);
        $customPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $customPdo->exec("
            CREATE TABLE IF NOT EXISTS custom_songs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                item_id INTEGER UNIQUE,
                title TEXT NOT NULL,
                media_url TEXT,
                notes TEXT,
                scale_id INTEGER,
                author_name TEXT,
                church_name TEXT,
                submitter_email TEXT,
                submitter_phone TEXT,
                approved_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS custom_verses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                song_id INTEGER,
                item_id INTEGER,
                type INTEGER DEFAULT 0,
                stanza_num INTEGER,
                title TEXT
            );
            CREATE TABLE IF NOT EXISTS custom_slides (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                verse_id INTEGER,
                heading TEXT
            );
            CREATE TABLE IF NOT EXISTS custom_segments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slide_id INTEGER,
                content TEXT
            );
            CREATE TABLE IF NOT EXISTS submissions (
                id TEXT PRIMARY KEY,
                status TEXT DEFAULT 'pending',
                token TEXT NOT NULL,
                submitter_name TEXT,
                submitter_email TEXT,
                submitter_phone TEXT,
                church_name TEXT,
                notes TEXT,
                song_json TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                reviewed_at DATETIME
            );
        ");
        return $customPdo;
    } catch (Exception $e) {
        return null;
    }
}

function rebuildCustomCatalogJson($customPdo) {
    if (!$customPdo) return [];
    try {
        $stmt = $customPdo->query("SELECT * FROM custom_songs ORDER BY id ASC");
        $songs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $fullList = [];
        foreach ($songs as $s) {
            $itemId = (int)$s['item_id'];
            $vStmt = $customPdo->prepare("SELECT * FROM custom_verses WHERE item_id = :itemId ORDER BY id ASC");
            $vStmt->execute([':itemId' => $itemId]);
            $verses = $vStmt->fetchAll(PDO::FETCH_ASSOC);
            $versesData = [];
            foreach ($verses as $v) {
                $slStmt = $customPdo->prepare("SELECT * FROM custom_slides WHERE verse_id = :vid ORDER BY id ASC");
                $slStmt->execute([':vid' => $v['id']]);
                $slides = $slStmt->fetchAll(PDO::FETCH_ASSOC);
                $slidesData = [];
                foreach ($slides as $sl) {
                    $segStmt = $customPdo->prepare("SELECT content FROM custom_segments WHERE slide_id = :sid ORDER BY id ASC");
                    $segStmt->execute([':sid' => $sl['id']]);
                    $lines = $segStmt->fetchAll(PDO::FETCH_COLUMN);
                    $slidesData[] = [
                        'id' => (int)$sl['id'],
                        'heading' => $sl['heading'],
                        'lines' => $lines,
                        'text' => implode("\n", $lines)
                    ];
                }
                $versesData[] = [
                    'id' => (int)$v['id'],
                    'type' => (int)$v['type'],
                    'isChorus' => ((int)$v['type'] === 1),
                    'stanzaNum' => $v['stanza_num'] ? (int)$v['stanza_num'] : null,
                    'slides' => $slidesData
                ];
            }
            $fullList[] = [
                'id' => (int)$s['id'],
                'item_id' => $itemId,
                'title' => $s['title'],
                'scale_id' => $s['scale_id'] ? (int)$s['scale_id'] : null,
                'media_url' => $s['media_url'] ?? '',
                'notes' => $s['notes'] ?? '',
                'author_name' => $s['author_name'] ?? '',
                'church_name' => $s['church_name'] ?? '',
                'is_custom' => true,
                'is_community' => true,
                'verses' => $versesData
            ];
        }

        $jsonStr = json_encode($fullList, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        @file_put_contents(__DIR__ . '/custom_catalog.json', $jsonStr);
        if (is_dir(__DIR__ . '/public')) {
            @file_put_contents(__DIR__ . '/public/custom_catalog.json', $jsonStr);
        }
        return $fullList;
    } catch (Exception $e) {
        return [];
    }
}

function sendGoogleScriptNotificationEmail($toEmail, $subject, $bodyHtml) {
    // Active Apps Script Web App URL
    $appsScriptUrl = 'https://script.google.com/macros/s/AKfycbwuidcfDZtPxuFTGV5-GxNY395DhD6MeP4tl10LVLMr0ptNvNMI0sOS9OBeZzD9RUg/exec';
    
    $payloadData = [
        'action' => 'sendEmail',
        'to' => $toEmail,
        'subject' => $subject,
        'htmlBody' => $bodyHtml
    ];
    $postFields = http_build_query($payloadData);

    // Prefer cURL because Google Apps Script responds with a 302 redirect
    if (function_exists('curl_init')) {
        $ch = curl_init($appsScriptUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $postFields,
            'timeout' => 12,
            'ignore_errors' => true,
            'follow_location' => 1
        ]
    ];
    $context = stream_context_create($opts);
    return @file_get_contents($appsScriptUrl, false, $context);
}

// 1. SUBMIT LOCAL TARNIMA FOR REVIEW & APP ACCEPTANCE
if (isset($_GET['action']) && $_GET['action'] === 'submit_custom_song') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!$data || empty($data['title']) || empty($data['verses'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'بيانات الترنيمة غير مكتملة']);
        exit;
    }

    $customPdo = getCustomSongsPdo();
    if (!$customPdo) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'فشل الاتصال بقاعدة بيانات الترانيم المخصصة']);
        exit;
    }

    $submissionId = 'sub_' . time() . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
    $token = bin2hex(random_bytes(16));
    $submitterName = trim($data['submitter_name'] ?? 'مستخدم التطبيق');
    $submitterEmail = trim($data['submitter_email'] ?? '');
    $submitterPhone = trim($data['submitter_phone'] ?? '');
    $churchName = trim($data['church_name'] ?? '');
    $notes = trim($data['notes'] ?? '');
    $songTitle = trim($data['title']);

    $stmt = $customPdo->prepare("
        INSERT INTO submissions (id, status, token, submitter_name, submitter_email, submitter_phone, church_name, notes, song_json, created_at)
        VALUES (:id, 'pending', :token, :sName, :sEmail, :sPhone, :cName, :notes, :sJson, CURRENT_TIMESTAMP)
    ");
    $stmt->execute([
        ':id' => $submissionId,
        ':token' => $token,
        ':sName' => $submitterName,
        ':sEmail' => $submitterEmail,
        ':sPhone' => $submitterPhone,
        ':cName' => $churchName,
        ':notes' => $notes,
        ':sJson' => json_encode($data, JSON_UNESCAPED_UNICODE)
    ]);

    // Build base URL
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $baseUrl = $protocol . $host;
    $approveUrl = $baseUrl . '/taranim/api.php?action=approve_submission&id=' . urlencode($submissionId) . '&token=' . urlencode($token);
    $reviewUrl = $baseUrl . '/taranim/?review_sub=' . urlencode($submissionId) . '&token=' . urlencode($token);
    $rejectUrl = $baseUrl . '/taranim/api.php?action=reject_submission&id=' . urlencode($submissionId) . '&token=' . urlencode($token);

    // Format song lyrics for email
    $versesHtml = '';
    $stanzaCount = 0;
    $chorusCount = 0;
    foreach ($data['verses'] as $v) {
        $isCh = ($v['type'] == 1 || (!empty($v['isChorus']) && $v['isChorus'] === true));
        if ($isCh) {
            $chorusCount++;
            $badge = '🌟 القرار' . ($chorusCount > 1 ? " $chorusCount" : '');
            $bg = '#eff6ff';
            $border = '#3b82f6';
            $color = '#1d4ed8';
        } else {
            $stanzaCount++;
            $badge = "📖 عدد $stanzaCount";
            $bg = '#f8fafc';
            $border = '#94a3b8';
            $color = '#334155';
        }
        $lines = [];
        foreach ($v['slides'] ?? [] as $sl) {
            foreach ($sl['lines'] ?? [] as $l) {
                if (trim($l)) $lines[] = htmlspecialchars($l);
            }
        }
        $linesHtml = implode('<br>', $lines);
        $versesHtml .= "
        <div style='margin-bottom:14px; background:{$bg}; border-right:4px solid {$border}; padding:12px 16px; border-radius:8px;'>
            <div style='font-weight:bold; font-size:13px; color:{$color}; margin-bottom:6px;'>{$badge}</div>
            <div style='font-size:14px; line-height:1.7; color:#1e293b;'>{$linesHtml}</div>
        </div>";
    }

    $emailSubject = "🎵 طلب اعتماد ترنيمة جديدة: {$songTitle} — {$submitterName}";
    $emailBodyHtml = "
    <!DOCTYPE html>
    <html dir='rtl' lang='ar'>
    <head><meta charset='utf-8'></head>
    <body style='font-family:Tahoma, Arial, sans-serif; background-color:#f1f5f9; padding:20px; margin:0; direction:rtl;'>
        <div style='max-width:620px; margin:0 auto; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 4px 15px rgba(0,0,0,0.08); border:1px solid #e2e8f0;'>
            <div style='background:linear-gradient(135deg, #2563eb, #1d4ed8); color:#ffffff; padding:24px 20px; text-align:center;'>
                <div style='font-size:28px; margin-bottom:6px;'>🎵</div>
                <h2 style='margin:0; font-size:20px;'>طلب اعتماد وإضافة ترنيمة جديدة</h2>
                <div style='font-size:13px; opacity:0.9; margin-top:4px;'>منظومة ترانيم مدارس الأحد والعرض المباشر</div>
            </div>
            
            <div style='padding:24px;'>
                <div style='background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px 16px; margin-bottom:20px;'>
                    <table style='width:100%; border-collapse:collapse; font-size:13.5px; color:#334155;'>
                        <tr><td style='padding:4px 0; font-weight:bold; width:110px;'>📌 عنوان الترنيمة:</td><td style='color:#0f172a; font-weight:800;'>{$songTitle}</td></tr>
                        <tr><td style='padding:4px 0; font-weight:bold;'>👤 اسم المُرسل:</td><td>{$submitterName}</td></tr>
                        " . ($churchName ? "<tr><td style='padding:4px 0; font-weight:bold;'>⛪ الكنيسة / الخدمة:</td><td>{$churchName}</td></tr>" : "") . "
                        " . ($submitterEmail ? "<tr><td style='padding:4px 0; font-weight:bold;'>📧 البريد:</td><td>{$submitterEmail}</td></tr>" : "") . "
                        " . ($submitterPhone ? "<tr><td style='padding:4px 0; font-weight:bold;'>📱 الهاتف / واتساب:</td><td>{$submitterPhone}</td></tr>" : "") . "
                        " . ($notes ? "<tr><td style='padding:4px 0; font-weight:bold;'>📝 ملاحظات:</td><td style='color:#64748b;'>{$notes}</td></tr>" : "") . "
                    </table>
                </div>

                <div style='margin-bottom:20px;'>
                    <h3 style='font-size:15px; color:#0f172a; margin:0 0 12px 0; border-bottom:2px solid #e2e8f0; padding-bottom:6px;'>📜 كلمات وفقرات الترنيمة:</h3>
                    {$versesHtml}
                </div>

                <!-- ACTION BUTTONS -->
                <div style='background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:18px; text-align:center;'>
                    <div style='font-weight:bold; font-size:14px; margin-bottom:12px; color:#0f172a;'>اختر الإجراء المناسب:</div>
                    <div style='display:block; margin-bottom:10px;'>
                        <a href='{$approveUrl}' style='display:inline-block; background:#10b981; color:#ffffff; text-decoration:none; padding:11px 24px; border-radius:8px; font-weight:bold; font-size:14px; margin:4px;'>
                            🟢 اعتماد وإضافة مباشرة للموقع
                        </a>
                    </div>
                    <div style='display:block; margin-bottom:10px;'>
                        <a href='{$reviewUrl}' style='display:inline-block; background:#2563eb; color:#ffffff; text-decoration:none; padding:11px 24px; border-radius:8px; font-weight:bold; font-size:14px; margin:4px;'>
                            ✏️ مراجعة وتعديل الترنيمة في الموقع
                        </a>
                    </div>
                    <div style='display:block;'>
                        <a href='{$rejectUrl}' style='display:inline-block; background:#ef4444; color:#ffffff; text-decoration:none; padding:8px 18px; border-radius:8px; font-size:12.5px; margin:4px;'>
                            🔴 رفض الطلب
                        </a>
                    </div>
                </div>
            </div>

            <div style='background:#f1f5f9; padding:12px; text-align:center; font-size:12px; color:#64748b; border-top:1px solid #e2e8f0;'>
                sunday-school.rf.gd/taranim — منظومة الترانيم الذكية
            </div>
        </div>
    </body>
    </html>
    ";

    // Send notification email to admin
    $adminTargetEmail = 'peterfayez107@gmail.com';
    sendGoogleScriptNotificationEmail($adminTargetEmail, $emailSubject, $emailBodyHtml);

    // If submitter provided email, send them a confirmation receipt
    if ($submitterEmail) {
        $confirmBody = "
        <div style='font-family:Tahoma, Arial, sans-serif; direction:rtl; padding:16px;'>
            <h3 style='color:#2563eb;'>سلام ونعمة يا {$submitterName}،</h3>
            <p>تم استلام طلب إضافة ترنيمة <strong>«{$songTitle}»</strong> بنجاح وجاري مراجعتها واعتمادها في المنظومة.</p>
            <p>نشكرك على مشاركتك وخدمتك المباركة! 🙏</p>
        </div>";
        sendGoogleScriptNotificationEmail($submitterEmail, "تم استلام ترنيمة: {$songTitle} بنجاح", $confirmBody);
    }

    echo json_encode([
        'status' => 'success',
        'submission_id' => $submissionId,
        'message' => 'تم إرسال الترنيمة بنجاح وسيصلك إشعار فور مراجعتها واعتمادها!'
    ]);
    exit;
}

// 2. APPROVE SUBMISSION DIRECTLY VIA LINK OR API
if (isset($_GET['action']) && $_GET['action'] === 'approve_submission') {
    $subId = trim($_GET['id'] ?? '');
    $token = trim($_GET['token'] ?? '');
    $customPdo = getCustomSongsPdo();

    if (!$customPdo || !$subId || !$token) {
        die("<h3>طلب غير صالح</h3>");
    }

    $stmt = $customPdo->prepare("SELECT * FROM submissions WHERE id = :id AND token = :token LIMIT 1");
    $stmt->execute([':id' => $subId, ':token' => $token]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sub) {
        die("<h3>الطلب غير موجود أو رمز التحقق غير صحيح</h3>");
    }

    $songData = json_decode($sub['song_json'], true);
    if (!$songData) {
        die("<h3>بيانات الترنيمة تالفة</h3>");
    }

    // Allocate safe unique item_id (starting from 500001+)
    $maxStmt = $customPdo->query("SELECT MAX(item_id) as m FROM custom_songs");
    $maxRow = $maxStmt->fetch(PDO::FETCH_ASSOC);
    $nextItemId = max(500001, ((int)($maxRow['m'] ?? 0)) + 1);

    // Insert into custom_songs
    $insSong = $customPdo->prepare("
        INSERT INTO custom_songs (item_id, title, media_url, notes, scale_id, author_name, church_name, submitter_email, submitter_phone, approved_at)
        VALUES (:itemId, :title, :mediaUrl, :notes, :scaleId, :author, :church, :email, :phone, CURRENT_TIMESTAMP)
    ");
    $insSong->execute([
        ':itemId' => $nextItemId,
        ':title' => $songData['title'] ?? 'ترنيمة مخصصة',
        ':mediaUrl' => $songData['media_url'] ?? '',
        ':notes' => $sub['notes'] ?? '',
        ':scaleId' => !empty($songData['scale_id']) ? (int)$songData['scale_id'] : null,
        ':author' => $sub['submitter_name'] ?? '',
        ':church' => $sub['church_name'] ?? '',
        ':email' => $sub['submitter_email'] ?? '',
        ':phone' => $sub['submitter_phone'] ?? ''
    ]);
    $customSongDbId = $customPdo->lastInsertId();

    // Insert verses, slides, and segments
    $stanzaNum = 0;
    foreach ($songData['verses'] ?? [] as $v) {
        $isCh = ($v['type'] == 1 || (!empty($v['isChorus']) && $v['isChorus'] === true));
        if (!$isCh) $stanzaNum++;
        $vType = $isCh ? 1 : 0;

        $vStmt = $customPdo->prepare("INSERT INTO custom_verses (song_id, item_id, type, stanza_num) VALUES (:sId, :itemId, :type, :sNum)");
        $vStmt->execute([
            ':sId' => $customSongDbId,
            ':itemId' => $nextItemId,
            ':type' => $vType,
            ':sNum' => $isCh ? null : $stanzaNum
        ]);
        $verseDbId = $customPdo->lastInsertId();

        foreach ($v['slides'] ?? [] as $sl) {
            $slStmt = $customPdo->prepare("INSERT INTO custom_slides (verse_id, heading) VALUES (:vId, :heading)");
            $slStmt->execute([
                ':vId' => $verseDbId,
                ':heading' => $sl['heading'] ?? null
            ]);
            $slideDbId = $customPdo->lastInsertId();

            $lines = $sl['lines'] ?? (isset($sl['text']) ? explode("\n", $sl['text']) : []);
            foreach ($lines as $line) {
                if (trim($line) !== '') {
                    $segStmt = $customPdo->prepare("INSERT INTO custom_segments (slide_id, content) VALUES (:slId, :content)");
                    $segStmt->execute([
                        ':slId' => $slideDbId,
                        ':content' => trim($line)
                    ]);
                }
            }
        }
    }

    // Mark submission as approved
    $updStmt = $customPdo->prepare("UPDATE submissions SET status = 'approved', reviewed_at = CURRENT_TIMESTAMP WHERE id = :id");
    $updStmt->execute([':id' => $subId]);

    // Rebuild custom catalog JSON
    rebuildCustomCatalogJson($customPdo);

    // Notify submitter if email exists
    if (!empty($sub['submitter_email'])) {
        $approvedBody = "
        <div style='font-family:Tahoma, Arial, sans-serif; direction:rtl; padding:16px;'>
            <h3 style='color:#10b981;'>مبروك يا {$sub['submitter_name']}! 🎉</h3>
            <p>تم اعتماد ونشر ترنيمة <strong>«{$songData['title']}»</strong> بنجاح في قاعدة بيانات التطبيق العامة.</p>
            <p>أصبحت الترنيمة الآن متاحة لجميع الخدام والمستخدمين على الموقع مباشرة.</p>
            <p><a href='https://sunday-school.rf.gd/taranim/' style='color:#2563eb; font-weight:bold;'>فتح موقع الترانيم</a></p>
        </div>";
        sendGoogleScriptNotificationEmail($sub['submitter_email'], "✅ تم اعتماد ونشر ترنيمتك: {$songData['title']}", $approvedBody);
    }

    // Render beautiful success landing page
    header('Content-Type: text/html; charset=utf-8');
    echo "
    <!DOCTYPE html>
    <html dir='rtl' lang='ar'>
    <head>
        <meta charset='utf-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>تم اعتماد الترنيمة بنجاح</title>
        <style>
            body { font-family: 'Baloo Bhaijaan 2', Tahoma, sans-serif; background:#f0fdf4; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; }
            .card { background:#ffffff; border-radius:16px; padding:32px; max-width:480px; text-align:center; box-shadow:0 10px 25px rgba(0,0,0,0.08); border:1px solid #bbf7d0; }
            .icon { font-size:52px; margin-bottom:12px; }
            h2 { color:#15803d; margin:0 0 8px 0; }
            p { color:#374151; font-size:15px; line-height:1.6; }
            .btn { display:inline-block; background:#16a34a; color:#ffffff; text-decoration:none; padding:10px 24px; border-radius:8px; font-weight:bold; margin-top:16px; }
        </style>
    </head>
    <body>
        <div class='card'>
            <div class='icon'>✅</div>
            <h2>تم اعتماد ونشر الترنيمة بنجاح!</h2>
            <p>تمت إضافة ترنيمة <strong>«" . htmlspecialchars($songData['title']) . "»</strong> إلى قاعدة بيانات الترانيم العامة والموقع بنجاح.</p>
            <p style='font-size:13px; color:#6b7280;'>الترنيمة محفوظة بشكل مستقل في <code>custom_songs.sqlite</code> ولن تتأثر بأي تحديثات مستقبلية لقاعدة بيانات تسبيحنا.</p>
            <a href='/taranim/' class='btn'>فتح منظومة الترانيم</a>
        </div>
    </body>
    </html>";
    exit;
}

// 3. REJECT SUBMISSION
if (isset($_GET['action']) && $_GET['action'] === 'reject_submission') {
    $subId = trim($_GET['id'] ?? '');
    $token = trim($_GET['token'] ?? '');
    $customPdo = getCustomSongsPdo();

    if ($customPdo && $subId && $token) {
        $updStmt = $customPdo->prepare("UPDATE submissions SET status = 'rejected', reviewed_at = CURRENT_TIMESTAMP WHERE id = :id AND token = :token");
        $updStmt->execute([':id' => $subId, ':token' => $token]);
    }

    header('Content-Type: text/html; charset=utf-8');
    echo "
    <!DOCTYPE html>
    <html dir='rtl' lang='ar'>
    <head><meta charset='utf-8'><title>تم رفض الطلب</title></head>
    <body style='font-family:Tahoma, sans-serif; background:#fef2f2; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; text-align:center;'>
        <div style='background:#fff; padding:30px; border-radius:12px; box-shadow:0 4px 15px rgba(0,0,0,0.08); border:1px solid #fecaca; max-width:400px;'>
            <div style='font-size:42px; margin-bottom:10px;'>🛑</div>
            <h3 style='color:#dc2626; margin:0 0 10px 0;'>تم رفض طلب إضافة الترنيمة</h3>
            <a href='/taranim/' style='color:#2563eb; text-decoration:none; font-weight:bold;'>العودة للترانيم</a>
        </div>
    </body>
    </html>";
    exit;
}

// 4. GET SUBMISSION DATA (FOR IN-APP REVIEW & EDIT MODE)
if (isset($_GET['action']) && $_GET['action'] === 'get_submission') {
    $subId = trim($_GET['id'] ?? '');
    $token = trim($_GET['token'] ?? '');
    $customPdo = getCustomSongsPdo();

    if (!$customPdo || !$subId || !$token) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'بيانات غير صالحة']);
        exit;
    }

    $stmt = $customPdo->prepare("SELECT * FROM submissions WHERE id = :id AND token = :token LIMIT 1");
    $stmt->execute([':id' => $subId, ':token' => $token]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sub) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'الطلب غير موجود']);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'submission' => [
            'id' => $sub['id'],
            'status' => $sub['status'],
            'submitter_name' => $sub['submitter_name'],
            'submitter_email' => $sub['submitter_email'],
            'submitter_phone' => $sub['submitter_phone'],
            'church_name' => $sub['church_name'],
            'notes' => $sub['notes'],
            'created_at' => $sub['created_at']
        ],
        'song' => json_decode($sub['song_json'], true)
    ]);
    exit;
}

// 5. SAVE REVIEWED SUBMISSION (DIRECTLY FROM IN-APP SONG EDITOR)
if (isset($_GET['action']) && $_GET['action'] === 'save_reviewed_custom_song') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    $subId = trim($data['submission_id'] ?? '');
    $token = trim($data['token'] ?? '');
    $songData = $data['song'] ?? null;

    $customPdo = getCustomSongsPdo();
    if (!$customPdo || !$subId || !$token || !$songData) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'بيانات الحفظ غير مكتملة']);
        exit;
    }

    $stmt = $customPdo->prepare("SELECT * FROM submissions WHERE id = :id AND token = :token LIMIT 1");
    $stmt->execute([':id' => $subId, ':token' => $token]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sub) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'رمز التحقق غير صالح']);
        exit;
    }

    // Allocate safe unique item_id (starting from 500001+)
    $maxStmt = $customPdo->query("SELECT MAX(item_id) as m FROM custom_songs");
    $maxRow = $maxStmt->fetch(PDO::FETCH_ASSOC);
    $nextItemId = max(500001, ((int)($maxRow['m'] ?? 0)) + 1);

    $insSong = $customPdo->prepare("
        INSERT INTO custom_songs (item_id, title, media_url, notes, scale_id, author_name, church_name, submitter_email, submitter_phone, approved_at)
        VALUES (:itemId, :title, :mediaUrl, :notes, :scaleId, :author, :church, :email, :phone, CURRENT_TIMESTAMP)
    ");
    $insSong->execute([
        ':itemId' => $nextItemId,
        ':title' => $songData['title'] ?? 'ترنيمة مخصصة',
        ':mediaUrl' => $songData['media_url'] ?? '',
        ':notes' => $sub['notes'] ?? '',
        ':scaleId' => !empty($songData['scale_id']) ? (int)$songData['scale_id'] : null,
        ':author' => $sub['submitter_name'] ?? '',
        ':church' => $sub['church_name'] ?? '',
        ':email' => $sub['submitter_email'] ?? '',
        ':phone' => $sub['submitter_phone'] ?? ''
    ]);
    $customSongDbId = $customPdo->lastInsertId();

    $stanzaNum = 0;
    foreach ($songData['verses'] ?? [] as $v) {
        $isCh = ($v['type'] == 1 || (!empty($v['isChorus']) && $v['isChorus'] === true));
        if (!$isCh) $stanzaNum++;
        $vType = $isCh ? 1 : 0;

        $vStmt = $customPdo->prepare("INSERT INTO custom_verses (song_id, item_id, type, stanza_num) VALUES (:sId, :itemId, :type, :sNum)");
        $vStmt->execute([
            ':sId' => $customSongDbId,
            ':itemId' => $nextItemId,
            ':type' => $vType,
            ':sNum' => $isCh ? null : $stanzaNum
        ]);
        $verseDbId = $customPdo->lastInsertId();

        foreach ($v['slides'] ?? [] as $sl) {
            $slStmt = $customPdo->prepare("INSERT INTO custom_slides (verse_id, heading) VALUES (:vId, :heading)");
            $slStmt->execute([
                ':vId' => $verseDbId,
                ':heading' => $sl['heading'] ?? null
            ]);
            $slideDbId = $customPdo->lastInsertId();

            $lines = $sl['lines'] ?? (isset($sl['text']) ? explode("\n", $sl['text']) : []);
            foreach ($lines as $line) {
                if (trim($line) !== '') {
                    $segStmt = $customPdo->prepare("INSERT INTO custom_segments (slide_id, content) VALUES (:slId, :content)");
                    $segStmt->execute([
                        ':slId' => $slideDbId,
                        ':content' => trim($line)
                    ]);
                }
            }
        }
    }

    $updStmt = $customPdo->prepare("UPDATE submissions SET status = 'approved', reviewed_at = CURRENT_TIMESTAMP WHERE id = :id");
    $updStmt->execute([':id' => $subId]);

    rebuildCustomCatalogJson($customPdo);

    echo json_encode([
        'status' => 'success',
        'message' => 'تم حفظ واعتماد الترنيمة بنجاح في قاعدة البيانات الحية!'
    ]);
    exit;
}

// 6. SERVE CUSTOM COMMUNITY CATALOG JSON
if (isset($_GET['action']) && $_GET['action'] === 'custom_catalog') {
    $customPdo = getCustomSongsPdo();
    $list = rebuildCustomCatalogJson($customPdo);
    echo json_encode($list, JSON_UNESCAPED_UNICODE);
    exit;
}

// CATALOG STATS ENDPOINT FOR OFFLINE / UPDATE CHECKS
if (isset($_GET['action']) && $_GET['action'] === 'catalog_stats') {
    $songCount = 11697;
    $catalogFile = __DIR__ . '/songs_catalog.json';
    $catalogSize = file_exists($catalogFile) ? filesize($catalogFile) : 0;
    $catalogMtime = file_exists($catalogFile) ? filemtime($catalogFile) : 0;

    $customCount = 0;
    try {
        $customPdo = getCustomSongsPdo();
        if ($customPdo) {
            $stmt = $customPdo->query("SELECT COUNT(*) FROM approved_custom_songs");
            if ($stmt) $customCount = (int)$stmt->fetchColumn();
        }
    } catch (Exception $e) {}

    try {
        if ($pdo) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM songs");
            if ($stmt) {
                $c = (int)$stmt->fetchColumn();
                if ($c > 0) $songCount = $c;
            }
        }
    } catch (Exception $e) {}

    echo json_encode([
        'total_songs' => $songCount,
        'custom_songs' => $customCount,
        'grand_total' => $songCount + $customCount,
        'catalog_size' => $catalogSize,
        'catalog_mtime' => $catalogMtime
    ]);
    exit;
}

// TEMPLATES DISCOVERY ENDPOINT
if (isset($_GET['action']) && $_GET['action'] === 'templates') {
    $jsonFile = file_exists(__DIR__ . '/templates.json') ? __DIR__ . '/templates.json' : (file_exists(__DIR__ . '/../templates.json') ? __DIR__ . '/../templates.json' : null);
    if ($jsonFile) {
        header('Content-Type: application/json; charset=utf-8');
        readfile($jsonFile);
        exit;
    }
    $baseDir = __DIR__ . '/Templates';
    $prefix = 'Templates/';
    if (!is_dir($baseDir) && is_dir(__DIR__ . '/../Templates')) {
        $baseDir = __DIR__ . '/../Templates';
        $prefix = '../Templates/';
    }
    $templates = [];
    $templates[] = [
        'id' => 'tmpl-shabahak-akon-2026',
        'name' => 'Shabahak Akon 2026',
        'category' => 'المؤتمرات',
        'categoryKey' => 'conferences',
        'desc' => 'حزمة مؤتمر شبابك أكون 2026 الكاملة: فيديو انتظار لوب + خلفية شرائح متمركزة + انتقال ستنجر بقناة ألفا.',
        'standby' => $prefix . 'Standby/Shabahak Akon 2026/Shabahak Akoon Loop.mp4',
        'slidesBg' => $prefix . 'SlidesBg/Shabahak Akon 2026/Shabahak Akoon Loop Empty Centered.mp4',
        'stringer' => $prefix . 'Stringer/Shabahak Akon 2026/Stringer 1.webm',
        'thumbnailType' => 'video',
        'thumbnailUrl' => $prefix . 'Standby/Shabahak Akon 2026/Shabahak Akoon Loop.mp4'
    ];

    $groupedFolders = [];
    if (is_dir($baseDir)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $ext = strtolower($file->getExtension());
                $filename = $file->getFilename();
                if (substr($filename, 0, 1) === '.' || $ext === 'xmp') continue;

                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen(__DIR__ . '/')));
                    $nameNoExt = pathinfo($filename, PATHINFO_FILENAME);
                    $parentDir = basename(dirname($file->getPathname()));
                    $cleanVarName = trim(preg_replace('/^Paint\\s+Sweeps\\s+/i', '', $nameNoExt));
                    if (empty($cleanVarName)) $cleanVarName = $nameNoExt;

                    if (!isset($groupedFolders[$parentDir])) {
                        $groupedFolders[$parentDir] = [];
                    }
                    $groupedFolders[$parentDir][] = [
                        'name' => $cleanVarName,
                        'url' => $rel
                    ];
                }
            }
        }
    }

    foreach ($groupedFolders as $folderName => $vars) {
        $firstUrl = !empty($vars) ? $vars[0]['url'] : '';
        $templates[] = [
            'id' => 'tmpl-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower($folderName)),
            'name' => $folderName . ' (Paint Splash)',
            'category' => 'خلفيات وسلايدات',
            'categoryKey' => 'backgrounds',
            'desc' => 'مجموعة خلفيات وسلايدات فنية مميزة (' . $folderName . ') بألوان وتدرجات متنوعة فائقة الدقة لعرض الترانيم.',
            'thumbnailType' => 'image',
            'thumbnailUrl' => $firstUrl,
            'standby' => $firstUrl,
            'slidesBg' => $firstUrl,
            'varieties' => $vars
        ];
    }
    echo json_encode(['status' => 'success', 'templates' => $templates], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = null;
$isMysql = false;

// 1. TRY SQLITE FIRST (LOCAL MASTER SOURCE OF TRUTH: database.sqlite)
if (file_exists($sqlitePath) && filesize($sqlitePath) > 0) {
    try {
        $pdo = new PDO('sqlite:' . $sqlitePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $isMysql = false;
    } catch (PDOException $ex) {
        $pdo = null;
    }
}

// 2. FALLBACK TO MYSQL IF SQLITE NOT AVAILABLE
if (!$pdo) {
    try {
        $pdo = new PDO("mysql:host=$mysqlHost;port=$mysqlPort;dbname=$mysqlDb;charset=utf8mb4", $mysqlUser, $mysqlPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ]);
        $isMysql = true;
    } catch (PDOException $e) {}
}

function fetchExternalUrl($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'SundaySchoolTaranim/2.0');
        $output = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        @curl_close($ch);
        if ($httpCode === 200 && !empty($output)) {
            return $output;
        }
    }
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: SundaySchoolTaranim/2.0\r\n",
            'timeout' => 8
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ];
    $context = stream_context_create($opts);
    return @file_get_contents($url, false, $context);
}

// SILENT BACKGROUND SYNC WITH ONLINE TASBE7NA REPOSITORY & LOCAL CATALOG
function syncOnlineTasbe7naDatabase($pdo, $force = false) {
    if (!$pdo) return;
    $syncLockFile = __DIR__ . '/.tasbe7na_sync.lock';
    if (!$force && file_exists($syncLockFile) && (time() - filemtime($syncLockFile) < 14400)) {
        return;
    }

    $datasets = [];

    // 1. Local catalog on server disk (11,611+ taranim)
    $localFile = __DIR__ . '/songs_catalog.json';
    if (file_exists($localFile)) {
        $localContent = @file_get_contents($localFile);
        if ($localContent) {
            $parsedLocal = json_decode($localContent, true);
            if (is_array($parsedLocal) && count($parsedLocal) > 0) {
                $datasets[] = $parsedLocal;
            }
        }
    }

    // 2. Online Tasbe7na repositories
    $sourceUrls = [
        'https://raw.githubusercontent.com/josephwasily/TasbehnaToOpenLyrics/main/tasbe7naDB.json',
        'https://raw.githubusercontent.com/josephwasily/TasbehnaToOpenLyrics/master/tasbe7naDB.json',
        'https://raw.githubusercontent.com/ainshamesundayschool/sunday-school.rf.gd/main/taranim/songs_catalog.json'
    ];

    foreach ($sourceUrls as $url) {
        $res = fetchExternalUrl($url);
        if ($res) {
            $testData = json_decode($res, true);
            if (is_array($testData) && count($testData) > 0) {
                $datasets[] = $testData;
            }
        }
    }

    if (!empty($datasets)) {
        try {
            $checkStmt  = $pdo->prepare("SELECT id FROM songs WHERE title = :title");
            $insertSong = $pdo->prepare("INSERT INTO songs (item_id, title, language, notes) VALUES (:itemId, :title, 1, :notes)");
            $updateNotes = $pdo->prepare("UPDATE songs SET notes = :notes WHERE id = :id");
            $insertItem = null;
            try {
                $insertItem = $pdo->prepare("INSERT INTO items (item_id, type) VALUES (:itemId, 0)");
            } catch (Exception $e) {}

            $addedCount = 0;
            $updatedCount = 0;

            foreach ($datasets as $data) {
                foreach ($data as $song) {
                    $rawTitle = isset($song['title']) ? trim($song['title']) : (isset($song['name']) ? trim($song['name']) : '');
                    if (empty($rawTitle)) continue;

                    $songNotes = '';
                    if (isset($song['notes']) && !empty($song['notes'])) {
                        $songNotes = is_string($song['notes']) ? $song['notes'] : json_encode($song['notes'], JSON_UNESCAPED_UNICODE);
                    } else if (isset($song['text']) && !empty($song['text'])) {
                        $songNotes = $song['text'];
                    } else if (isset($song['verses']) && is_array($song['verses'])) {
                        $verseTexts = [];
                        foreach ($song['verses'] as $v) {
                            if (isset($v['slides']) && is_array($v['slides'])) {
                                foreach ($v['slides'] as $sl) {
                                    if (isset($sl['lines']) && is_array($sl['lines'])) {
                                        $verseTexts[] = implode("\n", $sl['lines']);
                                    } else if (isset($sl['text'])) {
                                        $verseTexts[] = $sl['text'];
                                    }
                                }
                            }
                        }
                        $songNotes = implode("\n\n", $verseTexts);
                    }

                    $checkStmt->execute([':title' => $rawTitle]);
                    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$existing) {
                        $newItemId = isset($song['id']) ? intval($song['id']) : (isset($song['item_id']) ? intval($song['item_id']) : rand(900000, 999999));
                        $insertSong->execute([
                            ':itemId' => $newItemId,
                            ':title'  => $rawTitle,
                            ':notes'  => $songNotes
                        ]);
                        if ($insertItem) {
                            try { $insertItem->execute([':itemId' => $newItemId]); } catch (Exception $ex) {}
                        }
                        $addedCount++;
                    } else if (empty($existing['notes']) && !empty($songNotes)) {
                        $updateNotes->execute([':notes' => $songNotes, ':id' => $existing['id']]);
                        $updatedCount++;
                    }
                }
            }
            @touch($syncLockFile);
            return ['added' => $addedCount, 'updated' => $updatedCount];
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    return ['added' => 0, 'updated' => 0];
}

function normalizeArabic($text) {
    if (empty($text)) return '';
    $text = preg_replace('/[أإآٱ]/u', 'ا', $text);
    $text = preg_replace('/[ىئ]/u', 'ي', $text);
    $text = preg_replace('/ة/u', 'ه', $text);
    $text = preg_replace('/ؤ/u', 'و', $text);
    $text = preg_replace('/[\x{064B}-\x{0652}]/u', '', $text);
    return trim(mb_strtolower($text, 'UTF-8'));
}

if (isset($_GET['action']) && ($_GET['action'] === 'sync' || $_GET['action'] === 'force_sync')) {
    // Clean duplicate rows if any exist in remote database
    if ($pdo) {
        try {
            $pdo->exec("DELETE s1 FROM songs s1 INNER JOIN songs s2 WHERE s1.id > s2.id AND s1.title = s2.title");
        } catch (Exception $e) {}
    }

    $res = syncOnlineTasbe7naDatabase($pdo, true);
    $totalCount = 11697;
    if ($pdo) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM songs");
            $c = (int)$stmt->fetchColumn();
            if ($c > 0) $totalCount = $c;
        } catch (Exception $e) {}
    }
    echo json_encode([
        'status' => 'success',
        'message' => 'تمت مزامنة الترانيم بنجاح!',
        'syncResult' => $res,
        'total_songs' => $totalCount
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($pdo && rand(1, 3) === 1) {
    syncOnlineTasbe7naDatabase($pdo);
}

// SEARCH SONGS ENDPOINT
if (strpos($parsedUrl, '/api/songs') !== false || (isset($_GET['action']) && $_GET['action'] === 'songs') || isset($_GET['q'])) {
    if (!$pdo) {
        echo json_encode(['songs' => [], 'total_songs' => 11697]);
        exit;
    }

    $q      = isset($_GET['q']) ? trim($_GET['q']) : '';
    $limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

    $songs = [];
    $bibleChapters = [];

    if (!empty($q)) {
        $qNorm = normalizeArabic($q);
        
        $sql = "
            SELECT s.id, s.item_id, s.title, s.media_url, 
                   COALESCE(GROUP_CONCAT(DISTINCT sg.content), s.notes) as notes,
                   sc.scale as scale_id
            FROM songs s
            LEFT JOIN song_scales sc ON sc.song = s.id
            LEFT JOIN verses v ON v.item_id = s.item_id
            LEFT JOIN slides sl ON sl.verse = v.id
            LEFT JOIN segments sg ON sg.slide = sl.id
            WHERE s.title LIKE :q OR sg.content LIKE :q OR s.notes LIKE :q OR s.title LIKE :qNorm OR sg.content LIKE :qNorm OR s.notes LIKE :qNorm
            GROUP BY s.id
            LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':q', '%' . $q . '%', PDO::PARAM_STR);
        $stmt->bindValue(':qNorm', '%' . $qNorm . '%', PDO::PARAM_STR);
        $stmt->execute();
        $songs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // SEARCH BIBLE CHAPTERS TABLE AS WELL
        try {
            $qClean = preg_replace('/[٠]/u', '0', $q);
            $qClean = preg_replace('/[١]/u', '1', $qClean);
            $qClean = preg_replace('/[٢]/u', '2', $qClean);
            $qClean = preg_replace('/[٣]/u', '3', $qClean);
            $qClean = preg_replace('/[٤]/u', '4', $qClean);
            $qClean = preg_replace('/[٥]/u', '5', $qClean);
            $qClean = preg_replace('/[٦]/u', '6', $qClean);
            $qClean = preg_replace('/[٧]/u', '7', $qClean);
            $qClean = preg_replace('/[٨]/u', '8', $qClean);
            $qClean = preg_replace('/[٩]/u', '9', $qClean);

            $bookNum = '';
            $bookText = '';
            $chNum = null;

            if (preg_match('/^([123])?\s*([^\d]+?)\s*(\d+)$/u', trim($qClean), $m)) {
                $bookNum = $m[1];
                $bookText = trim($m[2]);
                $chNum = (int)$m[3];
            } else {
                $bookText = trim(preg_replace('/\d+/', '', $qClean));
                if (preg_match('/(\d+)/', $qClean, $nm)) {
                    $chNum = (int)$nm[1];
                }
            }

            $cleanText = trim(preg_replace('/^(سفر|إنجيل|انجيل|رسالة|رساله)\s+/iu', '', $bookText));
            $textWithAl = 'ال' . preg_replace('/^ال/iu', '', $cleanText);
            $textNoAl = preg_replace('/^ال/iu', '', $cleanText);

            $q1 = '%' . $cleanText . '%';
            $q2 = '%' . $textWithAl . '%';
            $q3 = '%' . $textNoAl . '%';

            if ($chNum !== null) {
                $bStmt = $pdo->prepare("
                    SELECT c.id, c.item_id, 
                           (b.title || ' - الأصحاح ' || bc.number) as title,
                           b.title as book_title,
                           bc.number as chapter_number,
                           1 as is_bible
                    FROM chapters c
                    JOIN bible_chapters bc ON c.bible_chapter = bc.id
                    JOIN books b ON bc.book = b.id
                    WHERE (
                      b.title LIKE :q1 OR b.abbr LIKE :q1
                      OR b.title LIKE :q2 OR b.abbr LIKE :q2
                      OR b.title LIKE :q3 OR b.abbr LIKE :q3
                    )
                    AND bc.number = :chNum
                    LIMIT 15
                ");
                $bStmt->bindValue(':q1', $q1, PDO::PARAM_STR);
                $bStmt->bindValue(':q2', $q2, PDO::PARAM_STR);
                $bStmt->bindValue(':q3', $q3, PDO::PARAM_STR);
                $bStmt->bindValue(':chNum', $chNum, PDO::PARAM_INT);
                $bStmt->execute();
                $bibleChapters = $bStmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $bStmt = $pdo->prepare("
                    SELECT c.id, c.item_id, 
                           (b.title || ' - الأصحاح ' || bc.number) as title,
                           b.title as book_title,
                           bc.number as chapter_number,
                           1 as is_bible
                    FROM chapters c
                    JOIN bible_chapters bc ON c.bible_chapter = bc.id
                    JOIN books b ON bc.book = b.id
                    WHERE (
                      b.title LIKE :q1 OR b.abbr LIKE :q1
                      OR b.title LIKE :q2 OR b.abbr LIKE :q2
                      OR b.title LIKE :q3 OR b.abbr LIKE :q3
                    )
                    LIMIT 15
                ");
                $bStmt->bindValue(':q1', $q1, PDO::PARAM_STR);
                $bStmt->bindValue(':q2', $q2, PDO::PARAM_STR);
                $bStmt->bindValue(':q3', $q3, PDO::PARAM_STR);
                $bStmt->execute();
                $bibleChapters = $bStmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $ex) {}

    } else {
        $sql = "
            SELECT s.id, s.item_id, s.title, s.media_url, s.notes, sc.scale as scale_id
            FROM songs s
            LEFT JOIN song_scales sc ON sc.song = s.id
            GROUP BY s.id
            ORDER BY s.id ASC
            LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $songs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $allResults = array_merge($bibleChapters, $songs);

    $totalCount = 11697;
    try {
        $cStmt = $pdo->query("SELECT COUNT(*) FROM songs");
        $c = (int)$cStmt->fetchColumn();
        if ($c > 0) $totalCount = $c;
    } catch (Exception $e) {}

    echo json_encode(['songs' => $allResults, 'total' => count($allResults), 'total_songs' => $totalCount, 'db_type' => $isMysql ? 'mysql' : 'sqlite']);
    exit;
}
// GET BIBLE CHAPTER ID BY BOOK ID AND CHAPTER NUMBER
if (isset($_GET['action']) && $_GET['action'] === 'bible_chapter') {
    if (!$pdo) {
        echo json_encode(['error' => 'No database']);
        exit;
    }
    $bookId = isset($_GET['book_id']) ? (int)$_GET['book_id'] : 0;
    $chNum = isset($_GET['chapter']) ? (int)$_GET['chapter'] : 0;

    if ($bookId > 0 && $chNum > 0) {
        try {
            $titleConcatSql = $isMysql ? "CONCAT(b.title, ' - الأصحاح ', bc.number)" : "(b.title || ' - الأصحاح ' || bc.number)";
            $stmt = $pdo->prepare("
                SELECT c.id, c.item_id, {$titleConcatSql} as title, 1 as is_bible
                FROM chapters c
                JOIN bible_chapters bc ON c.bible_chapter = bc.id
                JOIN books b ON bc.book = b.id
                WHERE (bc.book = :bookId OR b.id = :bookId) AND bc.number = :chNum
                LIMIT 1
            ");
            $stmt->bindValue(':bookId', $bookId, PDO::PARAM_INT);
            $stmt->bindValue(':chNum', $chNum, PDO::PARAM_INT);
            $stmt->execute();
            $chapter = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($chapter) {
                $vStmt = $pdo->prepare("SELECT id, type FROM verses WHERE item_id = :itemId OR item_id = :cId ORDER BY id ASC");
                $vStmt->bindValue(':itemId', $chapter['item_id'], PDO::PARAM_INT);
                $vStmt->bindValue(':cId', $chapter['id'], PDO::PARAM_INT);
                $vStmt->execute();
                $verses = $vStmt->fetchAll(PDO::FETCH_ASSOC);

                $versesData = [];
                foreach ($verses as $verse) {
                    $sStmt = $pdo->prepare("SELECT id, heading FROM slides WHERE verse = :vid ORDER BY id ASC");
                    $sStmt->bindValue(':vid', $verse['id'], PDO::PARAM_INT);
                    $sStmt->execute();
                    $slides = $sStmt->fetchAll(PDO::FETCH_ASSOC);

                    $slidesData = [];
                    foreach ($slides as $slide) {
                        $lineStmt = $pdo->prepare("SELECT id, content FROM segments WHERE slide = :sid ORDER BY id ASC");
                        $lineStmt->bindValue(':sid', $slide['id'], PDO::PARAM_INT);
                        $lineStmt->execute();
                        $segRows = $lineStmt->fetchAll(PDO::FETCH_ASSOC);

                        $lines = [];
                        foreach ($segRows as $seg) {
                            $lines[] = trim($seg['content']);
                        }

                        $slidesData[] = [
                            'id' => $slide['id'],
                            'heading' => $slide['heading'],
                            'lines' => $lines,
                            'text' => implode("\n", array_filter($lines))
                        ];
                    }

                    $versesData[] = [
                        'id' => $verse['id'],
                        'type' => $verse['type'],
                        'slides' => $slidesData
                    ];
                }

                $chapter['verses'] = $versesData;
                echo json_encode($chapter);
                exit;
            }
        } catch (Exception $ex) {}
    }
    http_response_code(404);
    echo json_encode(['error' => 'Chapter not found']);
    exit;
}

// GET SINGLE SONG OR BIBLE CHAPTER BY ID ENDPOINT
if (preg_match('#/api/song/(\d+)#', $parsedUrl, $matches) || (isset($_GET['action']) && $_GET['action'] === 'song')) {
    if (!$pdo) {
        echo json_encode(['error' => 'No database']);
        exit;
    }
    $songId = isset($matches[1]) ? (int)$matches[1] : (int)$_GET['id'];
    $isBibleReq = (isset($_GET['type']) && $_GET['type'] === 'bible') || (isset($_GET['is_bible']) && ($_GET['is_bible'] == '1' || $_GET['is_bible'] === 'true'));

    $song = null;
    $titleConcatSql = $isMysql ? "CONCAT(b.title, ' - الأصحاح ', bc.number)" : "(b.title || ' - الأصحاح ' || bc.number)";

    if ($isBibleReq) {
        try {
            $cStmt = $pdo->prepare("
                SELECT c.id, c.item_id, {$titleConcatSql} as title, b.abbr, bc.number as chapter_number, 1 as is_bible
                FROM chapters c
                JOIN bible_chapters bc ON c.bible_chapter = bc.id
                JOIN books b ON bc.book = b.id
                WHERE c.id = :id OR c.item_id = :id
            ");
            $cStmt->bindValue(':id', $songId, PDO::PARAM_INT);
            $cStmt->execute();
            $song = $cStmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $ex) {}
    }

    if (!$song) {
        $stmt = $pdo->prepare("SELECT s.*, sc.scale as scale_id FROM songs s LEFT JOIN song_scales sc ON sc.song = s.id WHERE s.id = :id OR s.item_id = :id");
        $stmt->bindValue(':id', $songId, PDO::PARAM_INT);
        $stmt->execute();
        $song = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$song) {
        try {
            $cStmt = $pdo->prepare("
                SELECT c.id, c.item_id, {$titleConcatSql} as title, b.abbr, bc.number as chapter_number, 1 as is_bible
                FROM chapters c
                JOIN bible_chapters bc ON c.bible_chapter = bc.id
                JOIN books b ON bc.book = b.id
                WHERE c.id = :id OR c.item_id = :id
            ");
            $cStmt->bindValue(':id', $songId, PDO::PARAM_INT);
            $cStmt->execute();
            $song = $cStmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $ex) {}
    }

    // Check in custom_songs.sqlite for accepted user songs
    if (!$song) {
        $customPdo = getCustomSongsPdo();
        if ($customPdo) {
            $cStmt = $customPdo->prepare("SELECT * FROM custom_songs WHERE id = :id OR item_id = :id LIMIT 1");
            $cStmt->execute([':id' => $songId]);
            $cSong = $cStmt->fetch(PDO::FETCH_ASSOC);
            if ($cSong) {
                $itemId = (int)$cSong['item_id'];
                $vStmt = $customPdo->prepare("SELECT * FROM custom_verses WHERE item_id = :itemId ORDER BY id ASC");
                $vStmt->execute([':itemId' => $itemId]);
                $verses = $vStmt->fetchAll(PDO::FETCH_ASSOC);
                $versesData = [];
                foreach ($verses as $v) {
                    $slStmt = $customPdo->prepare("SELECT * FROM custom_slides WHERE verse_id = :vid ORDER BY id ASC");
                    $slStmt->execute([':vid' => $v['id']]);
                    $slides = $slStmt->fetchAll(PDO::FETCH_ASSOC);
                    $slidesData = [];
                    foreach ($slides as $sl) {
                        $segStmt = $customPdo->prepare("SELECT content FROM custom_segments WHERE slide_id = :sid ORDER BY id ASC");
                        $segStmt->execute([':sid' => $sl['id']]);
                        $lines = $segStmt->fetchAll(PDO::FETCH_COLUMN);
                        $slidesData[] = [
                            'id' => (int)$sl['id'],
                            'heading' => $sl['heading'],
                            'lines' => $lines,
                            'text' => implode("\n", $lines)
                        ];
                    }
                    $versesData[] = [
                        'id' => (int)$v['id'],
                        'type' => (int)$v['type'],
                        'isChorus' => ((int)$v['type'] === 1),
                        'slides' => $slidesData
                    ];
                }
                $cSong['verses'] = $versesData;
                $cSong['is_custom'] = true;
                $cSong['is_community'] = true;
                echo json_encode($cSong);
                exit;
            }
        }
    }

    if (!$song) {
        http_response_code(404);
        echo json_encode(['error' => 'Song not found']);
        exit;
    }

    // Fetch all repetitions for this item from database
    $repMap = [];
    try {
        $repStmt = $pdo->prepare("
            SELECT r.start_segment, r.end_segment, r.opening_position, r.closing_position, r.repetitions
            FROM repetitions r
            JOIN segments sg ON sg.id = r.start_segment
            JOIN slides sl ON sl.id = sg.slide
            JOIN verses v ON v.id = sl.verse
            WHERE v.item_id = :itemId
        ");
        $repStmt->bindValue(':itemId', $song['item_id'], PDO::PARAM_INT);
        $repStmt->execute();
        $repRows = $repStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($repRows as $rRow) {
            $sId = (int)$rRow['start_segment'];
            $eId = (int)$rRow['end_segment'];
            $op  = (int)$rRow['opening_position'];
            $cp  = (int)$rRow['closing_position'];
            $cnt = (int)$rRow['repetitions'];

            if (!isset($repMap[$sId])) $repMap[$sId] = ['starts' => [], 'ends' => []];
            if (!isset($repMap[$eId])) $repMap[$eId] = ['starts' => [], 'ends' => []];

            $repMap[$sId]['starts'][] = ['pos' => $op, 'cnt' => $cnt];
            $repMap[$eId]['ends'][] = ['pos' => $cp, 'cnt' => $cnt];
        }
    } catch (Exception $ex) {}

    $vStmt = $pdo->prepare("SELECT id, type FROM verses WHERE item_id = :itemId ORDER BY id ASC");
    $vStmt->bindValue(':itemId', $song['item_id'], PDO::PARAM_INT);
    $vStmt->execute();
    $verses = $vStmt->fetchAll(PDO::FETCH_ASSOC);

    $versesData = [];
    foreach ($verses as $verse) {
        $sStmt = $pdo->prepare("SELECT id, heading FROM slides WHERE verse = :vid ORDER BY id ASC");
        $sStmt->bindValue(':vid', $verse['id'], PDO::PARAM_INT);
        $sStmt->execute();
        $slides = $sStmt->fetchAll(PDO::FETCH_ASSOC);

        $slidesData = [];
        foreach ($slides as $slide) {
            $lineStmt = $pdo->prepare("SELECT id, content FROM segments WHERE slide = :sid ORDER BY id ASC");
            $lineStmt->bindValue(':sid', $slide['id'], PDO::PARAM_INT);
            $lineStmt->execute();
            $segRows = $lineStmt->fetchAll(PDO::FETCH_ASSOC);

            $lines = [];
            foreach ($segRows as $seg) {
                $segId = (int)$seg['id'];
                $origTxt = trim($seg['content']);
                $txt = $origTxt;
                $prefix = '';
                $suffix = '';
                if (isset($repMap[$segId])) {
                    if (!empty($repMap[$segId]['starts'])) {
                        foreach ($repMap[$segId]['starts'] as $st) {
                            $pos = $st['pos'];
                            if ($pos > 0 && $pos < mb_strlen($origTxt)) {
                                $txt = mb_substr($origTxt, 0, $pos) . '(' . mb_substr($origTxt, $pos);
                            } else {
                                $prefix .= '(';
                            }
                        }
                    }
                    if (!empty($repMap[$segId]['ends'])) {
                        foreach ($repMap[$segId]['ends'] as $en) {
                            $pos = $en['pos'];
                            $cnt = $en['cnt'];
                            if ($pos > 0 && $pos < mb_strlen($origTxt)) {
                                $txt = mb_substr($origTxt, 0, $pos) . ')' . $cnt . mb_substr($origTxt, $pos);
                            } else {
                                $suffix .= ')' . $cnt;
                            }
                        }
                    }
                }
                $lines[] = $prefix . $txt . $suffix;
            }

            $slidesData[] = [
                'id' => $slide['id'],
                'heading' => $slide['heading'],
                'lines' => $lines,
                'text' => implode("\n", array_filter($lines))
            ];
        }

        $versesData[] = [
            'id' => $verse['id'],
            'type' => $verse['type'],
            'slides' => $slidesData
        ];
    }

    $song['verses'] = $versesData;
    echo json_encode($song);
    exit;
}

echo json_encode(['status' => 'online', 'server' => 'PHP Sunday School Taranim API', 'db_type' => $isMysql ? 'mysql' : 'sqlite']);
