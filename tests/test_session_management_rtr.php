<?php
// tests/test_session_management_rtr.php
// Full Lifecycle Integration & Unit Test for Refresh Token Rotation & Session Management

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api.php';

function assertEq($actual, $expected, $message = '') {
    if ($actual !== $expected) {
        $actualExport = var_export($actual, true);
        $expectedExport = var_export($expected, true);
        echo "FAILED: $message\n   Expected: $expectedExport\n   Actual:   $actualExport\n";
        exit(1);
    } else {
        echo "PASS: $message\n";
    }
}

function assertTrue($condition, $message = '') {
    assertEq((bool)$condition, true, $message);
}

echo "=========================================================\n";
echo "  TEST SUITE: SESSION MANAGEMENT & REFRESH TOKEN ROTATION\n";
echo "=========================================================\n\n";

$conn = getDBConnection();
ensureAuthRefreshTokensTable($conn);

// 1. Table Verification
$checkTable = $conn->query("SHOW TABLES LIKE 'auth_refresh_tokens'");
assertTrue($checkTable->num_rows === 1, "auth_refresh_tokens table exists in DB");

// 2. Initial Token Issuance Test
echo "\n--- Test 1: Initial Session & Token Issuance ---\n";
$testUserId = 99991;
$testUserType = 'uncle';
$_SERVER['REMOTE_ADDR'] = '192.168.1.100';
$_SERVER['HTTP_USER_AGENT'] = 'TestRunner/1.0 (Mozilla 5.0)';

$tokens = issueSessionTokens($testUserType, $testUserId, null, null, null, [
    'name' => 'أستاذ فادي تجريبي',
    'username' => 'fady_test',
    'role' => 'uncle',
    'church_id' => 1
]);

assertTrue(!empty($tokens['access_token']), "Access token generated");
assertTrue(!empty($tokens['refresh_token']), "Refresh token generated");
assertTrue(!empty($tokens['family_id']), "family_id created");
assertEq($tokens['expires_in'], 900, "Access token lifetime is 15 minutes (900 seconds)");

$familyId1 = $tokens['family_id'];
$plainRefresh1 = $tokens['refresh_token'];
$accessToken1 = $tokens['access_token'];

// Verify DB Record
$tokenHash1 = hash('sha256', $plainRefresh1);
$stmt = $conn->prepare("SELECT * FROM auth_refresh_tokens WHERE token_hash = ?");
$stmt->bind_param("s", $tokenHash1);
$stmt->execute();
$row1 = $stmt->get_result()->fetch_assoc();
$stmt->close();

assertTrue(!empty($row1), "Token recorded in DB with SHA-256 hash");
assertEq($row1['status'], 'active', "Initial token status is 'active'");
assertEq($row1['ip_address'], '192.168.1.100', "Client IP captured correctly");
assertEq($row1['user_agent'], 'TestRunner/1.0 (Mozilla 5.0)', "User-Agent captured correctly");
$nowTs = time();
$absTs = strtotime($row1['absolute_expires_at']);
$diffDays = round(($absTs - $nowTs) / 86400);
assertTrue($diffDays >= 29 && $diffDays <= 30, "Absolute expiry is 30 days (+/- 1 day precision: $diffDays days)");

// 3. Access Token Verification Test
echo "\n--- Test 2: Access Token JWT Verification & Tamper Protection ---\n";
$payload = verifyAccessToken($accessToken1);
assertTrue(!empty($payload), "Access token verified successfully");
assertEq(intval($payload['sub']), $testUserId, "Token sub matches user_id");
assertEq($payload['family_id'], $familyId1, "Token family_id matches");
assertEq($payload['role'], 'uncle', "Token role claim preserved");
assertTrue($payload['exp'] > time() && $payload['exp'] <= time() + 900, "exp claim within 15 minutes");

// Tamper test
$tamperedToken = substr($accessToken1, 0, -5) . 'XXXXX';
$tamperedPayload = verifyAccessToken($tamperedToken);
assertTrue($tamperedPayload === null, "Tampered token rejected by verifyAccessToken");

// 4. Normal Token Rotation Test
echo "\n--- Test 3: Normal Token Rotation ---\n";
$rotateResult = rotateRefreshToken($plainRefresh1);
assertTrue($rotateResult['success'], "Rotation succeeded");
assertTrue(!empty($rotateResult['access_token']), "New Access Token issued");
assertTrue(!empty($rotateResult['refresh_token']), "New Refresh Token issued");
assertEq($rotateResult['family_id'], $familyId1, "family_id preserved across rotation");

$plainRefresh2 = $rotateResult['refresh_token'];
$accessToken2 = $rotateResult['access_token'];

// Check old token state
$stmt = $conn->prepare("SELECT * FROM auth_refresh_tokens WHERE token_hash = ?");
$stmt->bind_param("s", $tokenHash1);
$stmt->execute();
$oldRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

assertEq($oldRow['status'], 'revoked', "Old token status marked as 'revoked'");
assertEq($oldRow['revocation_reason'], 'rotated', "Old token revocation_reason set to 'rotated'");
assertTrue(!empty($oldRow['grace_until']), "Grace window (grace_until) set on rotated token");
$graceSecondsLeft = strtotime($oldRow['grace_until']) - time();
assertTrue($graceSecondsLeft >= 1 && $graceSecondsLeft <= 5, "Grace window is active for 5 seconds (left: $graceSecondsLeft s)");

// 5. Race Condition / Concurrent Requests within Grace Period (<= 5s)
echo "\n--- Test 4: Race Condition Tolerance (5s Grace Period) ---\n";
// Re-using $plainRefresh1 while still within 5 seconds grace period
$raceResult = rotateRefreshToken($plainRefresh1);
assertTrue($raceResult['success'], "Old token during grace window accepted for concurrent request");
assertTrue(!empty($raceResult['grace_period']) && $raceResult['grace_period'] === true, "Identified as grace period concurrent request");
assertTrue(!empty($raceResult['access_token']), "Valid Access Token returned during race condition");

// Verify that family is still active, NOT revoked
$stmt = $conn->prepare("SELECT COUNT(*) as active_cnt FROM auth_refresh_tokens WHERE family_id = ? AND status = 'active'");
$stmt->bind_param("s", $familyId1);
$stmt->execute();
$cntRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
assertTrue($cntRow['active_cnt'] >= 1, "Session family remains ACTIVE during grace window");

// 6. Token Theft Detection (> 5s Grace Period)
echo "\n--- Test 5: Token Theft Detection & Full Family Revocation ---\n";
// Manually expire grace window on old token to simulate request arriving after 5 seconds
$conn->query("UPDATE auth_refresh_tokens SET grace_until = NOW() - INTERVAL 1 SECOND WHERE token_hash = '$tokenHash1'");

// Presenting the revoked token after grace window expired
$theftResult = rotateRefreshToken($plainRefresh1);
assertEq($theftResult['success'], false, "Revoked token after grace window rejected");
assertEq($theftResult['error'], 'TOKEN_THEFT_DETECTED', "Theft detected error code triggered");

// Check that ENTIRE family was revoked!
$stmt = $conn->prepare("SELECT COUNT(*) as active_cnt FROM auth_refresh_tokens WHERE family_id = ? AND status = 'active'");
$stmt->bind_param("s", $familyId1);
$stmt->execute();
$activeCount = $stmt->get_result()->fetch_assoc()['active_cnt'];
$stmt->close();
assertEq(intval($activeCount), 0, "ENTIRE token family revoked upon reuse attack");

// Verify theft_detected revocation reason on tokens
$stmt = $conn->prepare("SELECT revocation_reason FROM auth_refresh_tokens WHERE token_hash = ?");
$tokenHash2 = hash('sha256', $plainRefresh2);
$stmt->bind_param("s", $tokenHash2);
$stmt->execute();
$childRevokeReason = $stmt->get_result()->fetch_assoc()['revocation_reason'];
$stmt->close();
assertEq($childRevokeReason, 'theft_detected', "Active child token marked with revocation_reason = 'theft_detected'");

// Verify audit log entry
$stmt = $conn->prepare("SELECT * FROM audit_logs WHERE action = 'security_token_theft_detected' AND entity_id = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param("i", $testUserId);
$stmt->execute();
$auditRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
assertTrue(!empty($auditRow), "Security incident recorded in audit_logs");

// 7. Logout Test
echo "\n--- Test 6: Full Session Family Logout ---\n";
// Create a new fresh session
$tokensLogout = issueSessionTokens('church', 88881);
$familyLogout = $tokensLogout['family_id'];
$refreshLogout = $tokensLogout['refresh_token'];

revokeSessionFamily($familyLogout, null, 'logout');

$stmt = $conn->prepare("SELECT COUNT(*) as active_cnt FROM auth_refresh_tokens WHERE family_id = ? AND status = 'active'");
$stmt->bind_param("s", $familyLogout);
$stmt->execute();
$activeAfterLogout = $stmt->get_result()->fetch_assoc()['active_cnt'];
$stmt->close();
assertEq(intval($activeAfterLogout), 0, "All tokens in family revoked on logout");

// 8. Absolute Expiry (30 Days) Test
echo "\n--- Test 7: Absolute Expiry Enforcement (30 Days) ---\n";
// Create a session and set absolute_expires_at in the past
$tokensExp = issueSessionTokens('uncle', 77771);
$familyExp = $tokensExp['family_id'];
$refreshExp = $tokensExp['refresh_token'];
$hashExp = hash('sha256', $refreshExp);

$conn->query("UPDATE auth_refresh_tokens SET absolute_expires_at = NOW() - INTERVAL 1 MINUTE WHERE family_id = '$familyExp'");

$expResult = rotateRefreshToken($refreshExp);
assertEq($expResult['success'], false, "Rotation blocked after absolute expiry");
assertEq($expResult['error'], 'SESSION_EXPIRED', "Error code is SESSION_EXPIRED");

// 9. Cron Garbage Collection Test
echo "\n--- Test 8: Cron Garbage Collection ---\n";
// Insert mock expired token older than 60 days
$conn->query("INSERT INTO auth_refresh_tokens (family_id, user_type, user_id, token_hash, status, revoked_at, revocation_reason, created_at, expires_at, absolute_expires_at) VALUES ('mock_purge', 'uncle', 111, 'mock_hash_purge_1', 'revoked', NOW() - INTERVAL 20 DAY, 'rotated', NOW() - INTERVAL 20 DAY, NOW() - INTERVAL 20 DAY, NOW() - INTERVAL 10 DAY)");

ob_start();
include __DIR__ . '/../cron_clean_expired_tokens.php';
$cronOutput = ob_get_clean();

assertTrue(strpos($cronOutput, 'Success: Purged') !== false, "Cron job executed and outputted purge confirmation");

// Clean up test records
$conn->query("DELETE FROM auth_refresh_tokens WHERE user_id IN ($testUserId, 88881, 77771, 111)");
$conn->query("DELETE FROM audit_logs WHERE action = 'security_token_theft_detected' AND entity_id = $testUserId");

echo "\n=========================================================\n";
echo "  ALL 8 TEST SUITES PASSED!\n";
echo "=========================================================\n";
