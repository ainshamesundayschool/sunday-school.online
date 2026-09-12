<?php
/**
 * Sunday School Taranim - Mobile Remote Control High-Speed Real-Time Sync Engine
 * Fast, stateless, zero-blocking for high concurrency on all web servers.
 */

// Disable output buffering
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Cache-Control: no-cache, no-store, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0777, true);
}
$sessionsFile = $dataDir . '/remote_sessions.json';

function getSessionsData($file) {
    if (!file_exists($file)) return [];
    $content = @file_get_contents($file);
    if (!$content) return [];
    $data = @json_decode($content, true);
    return is_array($data) ? $data : [];
}

function saveSessionsData($file, $data) {
    $now = time();
    foreach ($data as $roomId => $room) {
        if (isset($room['updated_at']) && ($now - $room['updated_at']) > 86400) {
            unset($data[$roomId]);
        }
    }
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function getJsonInput() {
    $raw = @file_get_contents('php://input');
    if (!$raw) return $_REQUEST;
    $parsed = @json_decode($raw, true);
    return is_array($parsed) ? array_merge($_REQUEST, $parsed) : $_REQUEST;
}

$input = getJsonInput();
$action = trim($input['action'] ?? $_GET['action'] ?? '');

// Normalize action name if passed from api.php (e.g. remote_join_room -> join_room)
if (strpos($action, 'remote_') === 0) {
    $action = substr($action, 7);
}

switch ($action) {
    case 'create_room':
    case 'register_room':
        $sessions = getSessionsData($sessionsFile);
        $now = time();

        $reqRoomId = trim($input['roomId'] ?? '');
        $reqPin = trim($input['roomPin'] ?? $input['pin'] ?? '');
        $reqHostKey = trim($input['hostKey'] ?? '');

        if (!empty($reqRoomId) && !empty($reqPin) && !empty($reqHostKey)) {
            $roomId = $reqRoomId;
            $roomPin = $reqPin;
            $hostKey = $reqHostKey;
        } else {
            $roomId = 'rm_' . substr(md5(uniqid(mt_rand(), true)), 0, 10);
            $roomPin = strval(mt_rand(100000, 999999));
            $hostKey = 'hk_' . bin2hex(random_bytes(16));
        }

        $existingState = isset($sessions[$roomId]['state']) ? $sessions[$roomId]['state'] : [
            'activeSong' => null,
            'currentLineIndex' => 0,
            'totalLines' => 0,
            'presentationLines' => [],
            'isBlank' => false,
            'isStandbyMode' => false,
            'theme' => 'default'
        ];

        $sessions[$roomId] = [
            'room_id' => $roomId,
            'pin' => $roomPin,
            'host_key' => $hostKey,
            'created_at' => isset($sessions[$roomId]['created_at']) ? $sessions[$roomId]['created_at'] : $now,
            'updated_at' => $now,
            'state_ver' => isset($sessions[$roomId]['state_ver']) ? $sessions[$roomId]['state_ver'] : 1,
            'state' => $existingState,
            'commands' => isset($sessions[$roomId]['commands']) ? $sessions[$roomId]['commands'] : [],
            'clients' => isset($sessions[$roomId]['clients']) ? $sessions[$roomId]['clients'] : []
        ];

        saveSessionsData($sessionsFile, $sessions);

        echo json_encode([
            'success' => true,
            'roomId' => $roomId,
            'roomPin' => $roomPin,
            'hostKey' => $hostKey
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'join_room':
        $roomId = trim($input['roomId'] ?? '');
        $pin = trim($input['pin'] ?? '');
        $clientName = trim($input['clientName'] ?? 'هاتف ريموت');

        $sessions = getSessionsData($sessionsFile);
        $targetRoomId = null;

        if (!empty($roomId) && isset($sessions[$roomId])) {
            $targetRoomId = $roomId;
        } elseif (!empty($pin)) {
            foreach ($sessions as $rId => $rData) {
                if (strval($rData['pin']) === strval($pin)) {
                    $targetRoomId = $rId;
                    break;
                }
            }
        }

        if (!$targetRoomId || !isset($sessions[$targetRoomId])) {
            echo json_encode(['success' => false, 'message' => 'رمز الغرفة غير صحيح أو لم يتم تفعيلها من اللابتوب بعد'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $room = &$sessions[$targetRoomId];
        $clientToken = 'ct_' . bin2hex(random_bytes(12));
        $now = time();

        $clientIp = trim($input['clientIp'] ?? '');
        if (empty($clientIp)) {
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $clientIp = trim($parts[0]);
            }
        }

        $room['clients'][$clientToken] = [
            'name' => $clientName,
            'ip' => $clientIp,
            'joined_at' => $now,
            'last_seen' => $now
        ];
        $room['updated_at'] = $now;

        saveSessionsData($sessionsFile, $sessions);

        echo json_encode([
            'success' => true,
            'roomId' => $targetRoomId,
            'roomPin' => $room['pin'],
            'clientToken' => $clientToken,
            'clientIp' => $clientIp,
            'state' => $room['state'],
            'stateVer' => $room['state_ver'] ?? 1
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'push_state':
        $roomId = trim($input['roomId'] ?? '');
        $hostKey = trim($input['hostKey'] ?? '');
        $state = $input['state'] ?? [];

        $sessions = getSessionsData($sessionsFile);
        if (!isset($sessions[$roomId]) || $sessions[$roomId]['host_key'] !== $hostKey) {
            echo json_encode(['success' => false, 'message' => 'غير مصرح'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $sessions[$roomId]['state'] = $state;
        $sessions[$roomId]['state_ver'] = ($sessions[$roomId]['state_ver'] ?? 0) + 1;
        $sessions[$roomId]['updated_at'] = time();

        saveSessionsData($sessionsFile, $sessions);
        echo json_encode([
            'success' => true,
            'stateVer' => $sessions[$roomId]['state_ver'],
            'clientCount' => count($sessions[$roomId]['clients'] ?? [])
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'get_state':
        $roomId = trim($input['roomId'] ?? '');
        $clientToken = trim($input['clientToken'] ?? '');

        $sessions = getSessionsData($sessionsFile);
        if (!isset($sessions[$roomId])) {
            echo json_encode(['success' => false, 'message' => 'الغرفة غير موجودة'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $room = &$sessions[$roomId];
        if (!empty($clientToken) && isset($room['clients'][$clientToken])) {
            $room['clients'][$clientToken]['last_seen'] = time();
            saveSessionsData($sessionsFile, $sessions);
        }

        echo json_encode([
            'success' => true,
            'state' => $room['state'],
            'stateVer' => $room['state_ver'] ?? 1,
            'clientCount' => count($room['clients'] ?? [])
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'send_command':
        $roomId = trim($input['roomId'] ?? '');
        $clientToken = trim($input['clientToken'] ?? '');
        $command = $input['command'] ?? null;

        if (!$command || !is_array($command)) {
            echo json_encode(['success' => false, 'message' => 'أمر غير صالح'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $sessions = getSessionsData($sessionsFile);
        if (!isset($sessions[$roomId])) {
            echo json_encode(['success' => false, 'message' => 'الغرفة غير موجودة'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $command['id'] = 'cmd_' . uniqid(mt_rand(), true);
        $command['timestamp'] = microtime(true);
        $command['client_token'] = $clientToken;

        $sessions[$roomId]['commands'][] = $command;
        $sessions[$roomId]['updated_at'] = time();

        saveSessionsData($sessionsFile, $sessions);

        echo json_encode(['success' => true, 'commandId' => $command['id']], JSON_UNESCAPED_UNICODE);
        break;

    case 'poll_commands':
        $roomId = trim($input['roomId'] ?? '');
        $hostKey = trim($input['hostKey'] ?? '');

        $sessions = getSessionsData($sessionsFile);
        if (!isset($sessions[$roomId]) || $sessions[$roomId]['host_key'] !== $hostKey) {
            echo json_encode(['success' => false, 'message' => 'غير مصرح'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $commands = $sessions[$roomId]['commands'] ?? [];
        if (!empty($commands)) {
            $sessions[$roomId]['commands'] = []; // Drain commands
            $sessions[$roomId]['updated_at'] = time();
            saveSessionsData($sessionsFile, $sessions);
        }

        $now = time();
        $activeClients = 0;
        $clientsList = [];
        foreach ($sessions[$roomId]['clients'] as $cToken => $cData) {
            if (($now - ($cData['last_seen'] ?? 0)) < 45) {
                $activeClients++;
                $clientsList[] = [
                    'token' => $cToken,
                    'name' => $cData['name'] ?? 'جهاز تحكم',
                    'ip' => $cData['ip'] ?? '127.0.0.1',
                    'last_seen' => $cData['last_seen'] ?? $now
                ];
            }
        }

        echo json_encode([
            'success' => true,
            'commands' => $commands,
            'clientCount' => $activeClients,
            'clients' => $clientsList
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'get_my_ip':
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($parts[0]);
        }
        echo json_encode(['success' => true, 'ip' => $ip], JSON_UNESCAPED_UNICODE);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'إجراء غير معروف'], JSON_UNESCAPED_UNICODE);
        break;
}
