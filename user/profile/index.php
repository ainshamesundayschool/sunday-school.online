<?php
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);
ini_set('session.gc_maxlifetime', 60 * 60 * 24 * 365 * 10);

// Robust local session directory to prevent aggressive shared hosting garbage collection
$rootPath = dirname(__FILE__);
while ($rootPath && !file_exists($rootPath . '/api.php')) {
  $parent = dirname($rootPath);
  if ($parent === $rootPath)
    break;
  $rootPath = $parent;
}
$sessionPath = $rootPath . '/.sessions';
if (!is_dir($sessionPath)) {
  @mkdir($sessionPath, 0755, true);
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

// Include config.php for VAPID keys
if (file_exists($rootPath . '/config.php')) {
  require_once $rootPath . '/config.php';
}
$studentIdFromUrl = isset($_GET['id']) ? intval($_GET['id']) : null;
if ($studentIdFromUrl) {
    $_SESSION['student_id'] = $studentIdFromUrl;
}
$tempIdFromUrl = isset($_GET['tempid']) ? $_GET['tempid'] : null;

if ($tempIdFromUrl && !$studentIdFromUrl) {
  try {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT student_id AS id FROM student_temp_ids WHERE temp_id = ? LIMIT 1");
    $stmt->bind_param("s", $tempIdFromUrl);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $row = $res->fetch_assoc()) {
      header("Location: " . $_SERVER['PHP_SELF'] . "?id=" . $row['id']);
      exit;
    }
  } catch (Exception $e) {
    // Database connection or query failed
  }
}
$isUncleLoggedIn = isset($_SESSION['uncle_id']) || isset($_SESSION['church_id']);
if ($isUncleLoggedIn && $studentIdFromUrl && !(isset($_GET['noredirect']) && ($_GET['noredirect'] === 'true' || $_GET['noredirect'] === '1'))) {
    $isTestingEnv = (strpos($_SERVER['REQUEST_URI'], '/testing/') !== false || strpos($_SERVER['SCRIPT_NAME'], '/testing/') !== false);
    $pathPrefix = $isTestingEnv ? '/testing' : '';

    $targetTripId = isset($_GET['trip_id']) ? intval($_GET['trip_id']) : null;
    $tripToRedirect = null;

    if (function_exists('getDBConnection')) {
        try {
            $conn = getDBConnection();

            if ($targetTripId > 0) {
                $tstmt = $conn->prepare("SELECT id, points_config FROM trips WHERE id = ? LIMIT 1");
                $tstmt->bind_param("i", $targetTripId);
                $tstmt->execute();
                $tres = $tstmt->get_result();
                if ($tres && $trow = $tres->fetch_assoc()) {
                    $pcfg = json_decode($trow['points_config'] ?? '', true) ?? [];
                    if (!empty($pcfg['direct_kid_profile_to_trip'])) {
                        $tripToRedirect = intval($trow['id']);
                    }
                }
            }

            if (!$tripToRedirect) {
                $rstmt = $conn->prepare("SELECT t.id, t.points_config FROM trip_registrations tr JOIN trips t ON tr.trip_id = t.id WHERE tr.student_id = ? AND tr.cancelled = 0 ORDER BY t.id DESC");
                $rstmt->bind_param("i", $studentIdFromUrl);
                $rstmt->execute();
                $rres = $rstmt->get_result();
                while ($rres && $rrow = $rres->fetch_assoc()) {
                    $pcfg = json_decode($rrow['points_config'] ?? '', true) ?? [];
                    if (!empty($pcfg['direct_kid_profile_to_trip'])) {
                        $tripToRedirect = intval($rrow['id']);
                        break;
                    }
                }
            }

            if (!$tripToRedirect) {
                $churchId = $_SESSION['church_id'] ?? null;
                if (!$churchId) {
                    $sstmt = $conn->prepare("SELECT church_id FROM students WHERE id = ? LIMIT 1");
                    $sstmt->bind_param("i", $studentIdFromUrl);
                    $sstmt->execute();
                    $sres = $sstmt->get_result();
                    if ($sres && $srow = $sres->fetch_assoc()) {
                        $churchId = intval($srow['church_id']);
                    }
                }
                if ($churchId) {
                    $cstmt = $conn->prepare("SELECT id, points_config FROM trips WHERE church_id = ? OR FIND_IN_SET(?, collaborating_churches) ORDER BY id DESC");
                    $cstmt->bind_param("ii", $churchId, $churchId);
                    $cstmt->execute();
                    $cres = $cstmt->get_result();
                    while ($cres && $crow = $cres->fetch_assoc()) {
                        $pcfg = json_decode($crow['points_config'] ?? '', true) ?? [];
                        if (!empty($pcfg['direct_kid_profile_to_trip'])) {
                            $tripToRedirect = intval($crow['id']);
                            break;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // DB lookup failed, fall back to default
        }
    }

    if ($tripToRedirect) {
        header("Location: " . $pathPrefix . "/uncle/trip/index.html?trip_id=" . $tripToRedirect . "&student_id=" . $studentIdFromUrl);
    } else {
        header("Location: " . $pathPrefix . "/uncle/dashboard/index.php?kid_id=" . $studentIdFromUrl);
    }
    exit;
}
$targetTripId = isset($_GET['trip_id']) ? intval($_GET['trip_id']) : null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
  session_destroy();
  echo '<script>["savedUsername","savedPassword","rememberMe","userPhone","loginType"].forEach(k=>localStorage.removeItem(k));window.location.href="/user/login";</script>';
  exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
  <script>
    // ── SESSION GUARD FOR LOGGED-IN UNCLE / CHURCH ON KID PROFILE PAGE ──
    (function () {
      if (!navigator.onLine) return;
      var search = window.location.search;
      var noredirect = search.indexOf('noredirect=true') !== -1 || search.indexOf('noredirect=1') !== -1;
      if (noredirect) return;

      var cl = localStorage.getItem('loggedIn') === 'true';
      var ul = localStorage.getItem('uncleLoggedIn') === 'true';
      if (!cl && !ul) return;

      var KEY = '_ss_restoring_prof';
      if (sessionStorage.getItem(KEY)) {
        return;
      }

      var cc = localStorage.getItem('churchCode');
      var un = localStorage.getItem('uncleUsername');
      var fd = new FormData();
      fd.append('action', 'restore_session');
      if (cl && cc) fd.append('church_code', cc);
      else if (ul && un) fd.append('username', un);
      else return;

      sessionStorage.setItem(KEY, '1');
      var isTesting = window.location.pathname.indexOf('/testing/') !== -1;
      var apiPath = isTesting ? '/testing/api.php' : '/api.php';
      fetch(apiPath, { method: 'POST', body: fd, credentials: 'include' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.success) {
            window.location.reload();
          }
        })
        .catch(function () { });
    })();
  </script>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
  <!-- ═══ Social Preview Defaults ═══ -->
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Sunday School">
  <meta property="og:title" content="الملف الشخصي - مدارس الأحد">
  <meta property="og:description"
    content="منصة متكاملة لإدارة مدارس الأحد — الحضور، الكوبونات، الرحلات / المؤتمرات والمزيد">
  <meta property="og:url" content="https://sunday-school.online/user/profile/">
  <meta property="og:image" content="https://sunday-school.online/imgs/Sunday-School-Og.png">
  <meta property="og:image:width" content="1000">
  <meta property="og:image:height" content="1000">
  <meta property="og:image:type" content="image/png">
  <meta property="og:image:alt" content="Sunday School">
  <meta property="og:locale" content="ar_AR">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="الملف الشخصي - مدارس الأحد">
  <meta name="twitter:description"
    content="منصة متكاملة لإدارة مدارس الأحد — الحضور، الكوبونات، الرحلات / المؤتمرات والمزيد">
  <meta name="twitter:image" content="https://sunday-school.online/imgs/Sunday%20School%20App.png">

  <link rel="manifest" href="/manifest.json">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="mobile-web-app-capable" content="yes">
  <title id="pageTitle">الملف الشخصي - مدارس الأحد</title>
  <meta name="theme-color" content="#4f46e5">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="/fonts/cairo.css">
  <link href="https://fonts.googleapis.com/css2?family=Baloo+Bhaijaan+2:wght@400;500;600;700;800&family=Cairo:wght@300;400;600;700;800&display=swap"
    rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
  <link rel="icon" href="/favicon.ico">
  <style>
    /* Hide all tabs and non-info/non-coupon sections in kid profile view */
    .view-other-mode .bottom-nav {
      display: none !important;
    }

    .view-other-mode #scTrips,
    .view-other-mode #scAtt,
    .view-other-mode #scAnn,
    .view-other-mode #scSiblings,
    .view-other-mode #scUncles,
    .view-other-mode #scClassFriends,
    .view-other-mode #scTasks,
    .view-other-mode #scPaperExams,
    .view-other-mode .stats-bar {
      display: none !important;
    }

    .view-other-mode .page {
      padding-bottom: 24px !important;
    }

    /* ══ TOKENS ══════════════════════════════════════════════════ */
    :root {
      --brand: #4f46e5;
      --brand-d: #3730a3;
      --brand-l: #818cf8;
      --brand-bg: #eef2ff;
      --brand-glow: rgba(79, 70, 229, .18);
      --ok: #059669;
      --ok-l: #10b981;
      --ok-bg: #d1fae5;
      --err: #dc2626;
      --err-l: #ef4444;
      --err-bg: #fee2e2;
      --warn: #d97706;
      --warn-l: #f59e0b;
      --warn-bg: #fef3c7;
      --cou: #7c3aed;
      --cou-l: #8b5cf6;
      --cou-bg: #ede9fe;
      --trip: #0369a1;
      --trip-l: #0ea5e9;
      --trip-bg: #e0f2fe;
      --gold: #b45309;
      --gold-l: #f59e0b;
      --gold-bg: #fef3c7;
      --t1: #0f172a;
      --t2: #334155;
      --t3: #64748b;
      --t4: #94a3b8;
      --t5: #cbd5e1;
      --bg: #f1f5f9;
      --surf: #fff;
      --s2: #f8fafc;
      --bdr: #e2e8f0;
      --bdr2: #f1f5f9;
      --r-xs: 6px;
      --r-sm: 10px;
      --r-md: 16px;
      --r-lg: 22px;
      --r-xl: 30px;
      --r-2xl: 42px;
      --r-full: 9999px;
      --sh-sm: 0 1px 6px rgba(0, 0, 0, .05);
      --sh-md: 0 6px 20px rgba(0, 0, 0, .08);
      --sh-lg: 0 16px 40px rgba(0, 0, 0, .11);
      --sh-xl: 0 30px 60px rgba(0, 0, 0, .14);
      --sh-brand: 0 8px 24px rgba(79, 70, 229, .3);
      --ease: cubic-bezier(.4, 0, .2, 1);
      --spring: cubic-bezier(.16, 1, .3, 1);
      --fast: .15s var(--ease);
      --norm: .26s var(--ease);
      --slow: .48s var(--spring);
    }

    *,
    *::before,
    *::after {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      -webkit-tap-highlight-color: transparent;
      -webkit-user-select: none;
      -moz-user-select: none;
      user-select: none;
      -webkit-touch-callout: none;
    }

    html {
      scroll-behavior: smooth;
    }

    html.ov-open {
      overflow: visible;
    }

    html {
      scrollbar-gutter: stable;
    }

    body {
      font-family: 'Baloo Bhaijaan 2', 'Cairo', system-ui, -apple-system, sans-serif;
      background: var(--bg);
      color: var(--t1);
      min-height: 100vh;
      overflow-x: hidden;
      -webkit-font-smoothing: antialiased;
    }

    body::before {
      content: '';
      position: fixed;
      inset: 0;
      pointer-events: none;
      z-index: 0;
      background:
        radial-gradient(ellipse 70% 45% at 10% 5%, rgba(79, 70, 229, .08) 0%, transparent 65%),
        radial-gradient(ellipse 55% 40% at 90% 90%, rgba(124, 58, 237, .06) 0%, transparent 60%);
    }

    /* ══ HERO ════════════════════════════════════════════════════ */
    .hero {
      position: relative;
      overflow: hidden;
      background: linear-gradient(145deg, #312e81 0%, #4f46e5 35%, #7c3aed 70%, #5b21b6 100%);
      padding: 0 0 0;
      display: flex;
      flex-direction: column;
      min-height: 340px;
    }

    /* animated mesh */
    .hero::before {
      content: '';
      position: absolute;
      inset: 0;
      background:
        radial-gradient(circle at 20% 30%, rgba(255, 255, 255, .07) 0%, transparent 40%),
        radial-gradient(circle at 80% 70%, rgba(255, 255, 255, .05) 0%, transparent 35%);
      animation: hero-pulse 6s ease-in-out infinite;
    }

    @keyframes hero-pulse {

      0%,
      100% {
        opacity: 1;
      }

      50% {
        opacity: .6;
      }
    }

    /* star/dot texture */
    .hero::after {
      content: '';
      position: absolute;
      inset: 0;
      pointer-events: none;
      background-image: radial-gradient(circle, rgba(255, 255, 255, .08) 1px, transparent 1px);
      background-size: 28px 28px;
    }

    .hero-top {
      position: relative;
      z-index: 2;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 14px 18px 0;
    }

    .hero-church-chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: rgba(255, 255, 255, .14);
      backdrop-filter: blur(10px);
      color: rgba(255, 255, 255, .92);
      font-size: .78rem;
      font-weight: 600;
      padding: 5px 13px;
      border-radius: var(--r-full);
      border: none;
      max-width: 180px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .hero-actions-top {
      display: flex;
      gap: 8px;
    }

    .hero-ico-btn {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background: rgba(255, 255, 255, .15);
      backdrop-filter: blur(8px);
      border: none;
      color: #fff;
      font-size: .9rem;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: var(--fast);
    }

    .hero-ico-btn:hover {
      background: rgba(255, 255, 255, .26);
    }

    /* center content */
    .hero-body {
      position: relative;
      z-index: 2;
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 22px 20px 0;
      flex: 1;
    }

    .avatar-ring {
      position: relative;
      width: 116px;
      height: 116px;
      /* glowing ring */
      background: conic-gradient(from 0deg, #818cf8, #c4b5fd, #e0e7ff, #818cf8);
      border-radius: 50%;
      padding: 3px;
      box-shadow: 0 0 0 4px rgba(255, 255, 255, .15), 0 8px 32px rgba(0, 0, 0, .25);
      cursor: pointer;
      transition: var(--norm);
      animation: ring-spin 8s linear infinite;
    }

    @keyframes ring-spin {
      from {
        background: conic-gradient(from 0deg, #818cf8, #c4b5fd, #e0e7ff, #818cf8);
      }

      to {
        background: conic-gradient(from 360deg, #818cf8, #c4b5fd, #e0e7ff, #818cf8);
      }
    }

    .avatar-ring:hover {
      transform: scale(1.04);
    }

    .avatar-inner {
      width: 100%;
      height: 100%;
      border-radius: 50%;
      background: linear-gradient(135deg, #e0e7ff, #c7d2fe);
      overflow: hidden;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 2.6rem;
      color: #818cf8;
      border: 3px solid rgba(255, 255, 255, .9);
    }

    .avatar-inner img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .avatar-edit-fab {
      position: absolute;
      bottom: 2px;
      left: 2px;
      width: 26px;
      height: 26px;
      border-radius: 50%;
      background: var(--brand);
      border: 2px solid #fff;
      display: none;
      align-items: center;
      justify-content: center;
      font-size: .58rem;
      color: #fff;
      cursor: pointer;
      box-shadow: 0 2px 8px rgba(0, 0, 0, .2);
      transition: var(--fast);
    }

    .avatar-edit-fab.show {
      display: flex;
    }

    .avatar-edit-fab:hover {
      background: var(--brand-d);
      transform: scale(1.12);
    }

    .hero-name {
      margin-top: 14px;
      font-size: 1.85rem;
      font-weight: 800;
      color: #fff;
      text-align: center;
      text-shadow: 0 2px 12px rgba(0, 0, 0, .2);
      line-height: 1.15;
    }

    @media(max-width:400px) {
      .hero-name {
        font-size: 1.5rem;
      }
    }

    .hero-tags {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 7px;
      flex-wrap: wrap;
      margin-top: 10px;
    }

    .htag {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 4px 13px;
      border-radius: var(--r-full);
      font-size: .78rem;
      font-weight: 700;
      background: rgba(255, 255, 255, .16);
      backdrop-filter: blur(8px);
      border: 1px solid rgba(255, 255, 255, .28);
      color: #fff;
      transition: var(--fast);
    }

    .htag.class-tag {
      background: rgba(255, 255, 255, .95);
      color: var(--brand-d);
      border-color: transparent;
    }

    .htag.switch-tag {
      background: rgba(255, 255, 255, .18);
      border: 1px solid rgba(255, 255, 255, .32);
      color: #fff;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 12px;
      border-radius: var(--r-full);
      font-size: .78rem;
      font-weight: 700;
      font-family: inherit;
      transition: all var(--fast);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
    }

    .htag.switch-tag:hover {
      background: rgba(255, 255, 255, .28);
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.18);
      border-color: rgba(255, 255, 255, .55);
    }

    .htag.switch-tag:active {
      transform: translateY(0);
    }

    /* ── COUPON HERO CARD ──────────────────────────────── */
    .coupon-hero {
      position: relative;
      z-index: 2;
      margin: 20px 16px 0;
      background: rgba(255, 255, 255, .1);
      backdrop-filter: blur(14px);
      border: 1px solid rgba(255, 255, 255, .22);
      border-radius: var(--r-xl);
      padding: 18px 20px 16px;
      display: grid;
      grid-template-columns: 1fr auto;
      align-items: center;
      gap: 16px;
      overflow: hidden;
    }

    .coupon-hero::before {
      content: '';
      position: absolute;
      top: -30px;
      left: -30px;
      width: 140px;
      height: 140px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(196, 181, 253, .25), transparent 70%);
      pointer-events: none;
    }

    .ch-total-label {
      font-size: .74rem;
      font-weight: 600;
      color: rgba(255, 255, 255, .75);
      margin-bottom: 3px;
    }

    .ch-total-val {
      font-size: 2.9rem;
      font-weight: 800;
      color: #fff;
      line-height: 1;
      text-shadow: 0 2px 16px rgba(0, 0, 0, .2);
    }

    .ch-total-unit {
      font-size: .88rem;
      color: rgba(255, 255, 255, .8);
      margin-top: 1px;
      font-weight: 600;
    }

    .ch-breakdown {
      display: flex;
      flex-direction: column;
      gap: 5px;
    }

    .ch-row {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: .74rem;
      color: rgba(255, 255, 255, .85);
      font-weight: 600;
      background: rgba(255, 255, 255, .1);
      padding: 4px 10px;
      border-radius: var(--r-full);
    }

    .ch-row i {
      font-size: .72rem;
    }

    /* ── HERO WAVE BOTTOM ──────────────────────────────── */
    .hero-wave {
      position: relative;
      z-index: 2;
      margin-top: 20px;
      height: 38px;
      background: var(--bg);
      clip-path: ellipse(56% 100% at 50% 100%);
      flex-shrink: 0;
    }

    /* ══ STATS BAR ═══════════════════════════════════════ */
    .stats-bar {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      margin: 0 14px 18px;
      background: var(--surf);
      border-radius: var(--r-lg);
      border: 1px solid var(--bdr);
      box-shadow: var(--sh-md);
      overflow: hidden;
    }

    .sb-cell {
      padding: 12px 8px;
      text-align: center;
      border-left: 1px solid var(--bdr);
      transition: var(--fast);
      position: relative;
    }

    .sb-cell:last-child {
      border-left: none;
    }

    .sb-cell:hover {
      background: var(--s2);
    }

    .sb-val {
      font-size: 1.3rem;
      font-weight: 800;
      line-height: 1;
      color: var(--t1);
    }

    .sb-lbl {
      font-size: .63rem;
      color: var(--t4);
      margin-top: 2px;
      font-weight: 600;
    }

    .sb-cell.ok .sb-val {
      color: var(--ok-l);
    }

    .sb-cell.err .sb-val {
      color: var(--err-l);
    }

    .sb-cell.cou .sb-val {
      color: var(--cou-l);
    }

    .sb-cell.neu .sb-val {
      color: var(--brand-l);
    }

    /* ══ PAGE ════════════════════════════════════════════ */
    .page {
      max-width: 860px;
      margin: 0 auto;
      padding: 0 14px 90px;
      position: relative;
      z-index: 1;
    }

    /* ══ SECTION CARD ════════════════════════════════════ */
    .sc {
      position: relative;
      background:
        linear-gradient(180deg, rgba(255, 255, 255, .98), rgba(248, 250, 252, .98));
      border-radius: 26px;
      border: 1px solid rgba(226, 232, 240, .9);
      box-shadow:
        0 1px 0 rgba(255, 255, 255, .85) inset,
        0 14px 34px rgba(15, 23, 42, .06);
      overflow: hidden;
      margin-bottom: 16px;
      transition: box-shadow var(--norm), transform var(--norm), border-color var(--norm);
    }

    .sc::before {
      content: '';
      position: absolute;
      inset: 0 0 auto 0;
      height: 1px;
      background: linear-gradient(90deg, rgba(255, 255, 255, 0), rgba(255, 255, 255, .9), rgba(255, 255, 255, 0));
      pointer-events: none;
    }

    .sc:hover {
      transform: translateY(-3px);
      border-color: rgba(129, 140, 248, .45);
      box-shadow:
        0 1px 0 rgba(255, 255, 255, .92) inset,
        0 20px 46px rgba(15, 23, 42, .09);
    }

    .sc-head {
      position: relative;
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 18px 20px 14px;
      border-bottom: 1px solid rgba(241, 245, 249, .95);
      background:
        radial-gradient(circle at top right, rgba(99, 102, 241, .07), transparent 42%),
        linear-gradient(180deg, rgba(255, 255, 255, .96), rgba(248, 250, 252, .86));
    }

    .sc-ico {
      width: 42px;
      height: 42px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1rem;
      flex-shrink: 0;
      box-shadow:
        0 1px 0 rgba(255, 255, 255, .8) inset,
        0 10px 22px rgba(15, 23, 42, .08);
    }

    .sc-label {
      flex: 1;
    }

    .sc-title {
      font-size: 1rem;
      font-weight: 800;
      color: var(--t1);
    }

    .sc-sub {
      font-size: .74rem;
      color: var(--t4);
      margin-top: 2px;
      font-weight: 600;
    }

    .sc-badge {
      font-size: .72rem;
      font-weight: 800;
      padding: 5px 11px;
      border-radius: var(--r-full);
      background: linear-gradient(135deg, rgba(238, 242, 255, .96), rgba(224, 231, 255, .96));
      color: var(--brand);
      border: 1px solid rgba(129, 140, 248, .2);
    }

    .sc-body {
      padding: 18px;
    }

    /* ══ INFO PILLS ══════════════════════════════════════ */
    .info-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(168px, 1fr));
      gap: 10px;
    }

    .ip {
      display: flex;
      align-items: flex-start;
      gap: 9px;
      padding: 11px 13px;
      border-radius: var(--r-md);
      background: var(--s2);
      border: 1px solid var(--bdr);
      transition: var(--fast);
    }

    .ip:hover {
      border-color: var(--brand-l);
      background: var(--brand-bg);
      transform: translateY(-1px);
    }

    .ip-ico {
      width: 30px;
      height: 30px;
      border-radius: var(--r-xs);
      flex-shrink: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: .78rem;
      margin-top: 1px;
    }

    .ip-lbl {
      font-size: .66rem;
      color: var(--t4);
      font-weight: 600;
      margin-bottom: 2px;
    }

    .ip-val {
      font-size: .84rem;
      font-weight: 700;
      color: var(--t1);
      word-break: break-word;
      line-height: 1.3;
    }

    /* ══ ATTENDANCE ══════════════════════════════════════ */
    .att-stats {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 10px;
      margin-bottom: 14px;
    }

    .as {
      text-align: center;
      padding: 11px 6px;
      border-radius: var(--r-md);
      background: var(--s2);
      border: 1px solid var(--bdr);
    }

    .as-val {
      font-size: 1.35rem;
      font-weight: 800;
      line-height: 1;
    }

    .as-lbl {
      font-size: .65rem;
      color: var(--t4);
      margin-top: 2px;
      font-weight: 600;
    }

    .as.ok {
      background: var(--ok-bg);
      border-color: #6ee7b7;
    }

    .as.ok .as-val {
      color: var(--ok);
    }

    .as.err {
      background: var(--err-bg);
      border-color: #fca5a5;
    }

    .as.err .as-val {
      color: var(--err);
    }

    .as.neu .as-val {
      color: var(--brand);
    }

    .cal-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(82px, 1fr));
      gap: 8px;
    }

    .cal-day {
      padding: 10px 7px;
      border-radius: var(--r-md);
      text-align: center;
      border: 2px solid var(--bdr);
      background: var(--s2);
      transition: var(--fast);
    }

    .cal-day:hover {
      transform: translateY(-2px);
      box-shadow: var(--sh-sm);
    }

    .cal-day.present {
      background: var(--ok-bg);
      border-color: #6ee7b7;
    }

    .cal-day.absent {
      background: var(--err-bg);
      border-color: #fca5a5;
    }

    .cd-num {
      font-size: 1.1rem;
      font-weight: 800;
      color: var(--t1);
    }

    .cal-day.present .cd-num {
      color: var(--ok);
    }

    .cal-day.absent .cd-num {
      color: var(--err);
    }

    .cd-mo {
      font-size: .6rem;
      color: var(--t4);
      font-weight: 600;
    }

    .cd-st {
      font-size: .62rem;
      font-weight: 700;
      margin-top: 3px;
      color: var(--t5);
    }

    .cal-day.present .cd-st {
      color: var(--ok);
    }

    .cal-day.absent .cd-st {
      color: var(--err);
    }

    .cd-days {
      font-size: .58rem;
      color: var(--t4);
      margin-top: 1px;
      font-weight: 500;
    }

    /* ══ ATTENDANCE HISTORY MODAL ════════════════════════ */
    .att-view-all {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      font-size: .72rem;
      font-weight: 700;
      color: var(--brand);
      cursor: pointer;
      padding: 4px 10px;
      border-radius: var(--r-full);
      background: var(--brand-bg);
      border: 1px solid rgba(79, 70, 229, .15);
      transition: var(--fast);
      margin-top: 10px;
      -webkit-user-select: none;
      user-select: none;
    }

    .att-view-all:hover {
      background: var(--brand);
      color: #fff;
      transform: translateY(-1px);
    }

    #attHistOv .modal {
      max-width: 700px;
    }

    .att-hist-filters {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      padding: 12px 18px;
      border-bottom: 1px solid var(--bdr);
      background: var(--s2);
    }

    .att-hist-search {
      flex: 1;
      min-width: 140px;
      padding: 9px 14px;
      border: 1.5px solid var(--bdr);
      border-radius: var(--r-sm);
      font-family: 'Baloo Bhaijaan 2', sans-serif;
      font-size: .88rem;
      background: var(--surf);
      color: var(--t1);
      outline: none;
      transition: var(--fast);
    }

    .att-hist-search:focus {
      border-color: var(--brand);
      box-shadow: 0 0 0 3px var(--brand-bg);
    }

    .att-filter-chips {
      display: flex;
      gap: 6px;
      flex-wrap: wrap;
    }

    .fchip {
      padding: 5px 12px;
      border-radius: var(--r-full);
      font-size: .72rem;
      font-weight: 700;
      border: 1.5px solid var(--bdr);
      background: var(--surf);
      color: var(--t3);
      cursor: pointer;
      transition: var(--fast);
      white-space: nowrap;
    }

    .fchip:hover {
      border-color: var(--brand);
      color: var(--brand);
    }

    .fchip.active {
      background: var(--brand);
      border-color: var(--brand);
      color: #fff;
    }

    .fchip.ok.active {
      background: var(--ok);
      border-color: var(--ok);
      color: #fff;
    }

    .fchip.err.active {
      background: var(--err);
      border-color: var(--err);
      color: #fff;
    }

    .att-hist-sort {
      padding: 7px 12px;
      border: 1.5px solid var(--bdr);
      border-radius: var(--r-sm);
      font-family: 'Baloo Bhaijaan 2', sans-serif;
      font-size: .8rem;
      background: var(--surf);
      color: var(--t2);
      outline: none;
      cursor: pointer;
      transition: var(--fast);
    }

    .att-hist-sort:focus {
      border-color: var(--brand);
    }

    .att-hist-summary {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 8px;
      padding: 12px 18px;
      border-bottom: 1px solid var(--bdr);
    }

    .ahs-card {
      text-align: center;
      padding: 10px 6px;
      border-radius: var(--r-md);
      background: var(--s2);
      border: 1px solid var(--bdr);
    }

    .ahs-val {
      font-size: 1.2rem;
      font-weight: 800;
      line-height: 1;
    }

    .ahs-lbl {
      font-size: .6rem;
      color: var(--t4);
      margin-top: 2px;
      font-weight: 600;
    }

    .ahs-card.ok {
      background: var(--ok-bg);
      border-color: #6ee7b7;
    }

    .ahs-card.ok .ahs-val {
      color: var(--ok);
    }

    .ahs-card.err {
      background: var(--err-bg);
      border-color: #fca5a5;
    }

    .ahs-card.err .ahs-val {
      color: var(--err);
    }

    .ahs-card.neu .ahs-val {
      color: var(--brand);
    }

    .att-hist-list {
      padding: 10px 18px 18px;
      max-height: calc(100vh - 340px);
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .att-hist-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 10px 14px;
      border-radius: var(--r-md);
      border: 1.5px solid var(--bdr);
      background: var(--surf);
      transition: var(--fast);
    }

    .att-hist-item:hover {
      transform: translateX(-2px);
      box-shadow: var(--sh-sm);
    }

    .att-hist-item.present {
      border-color: #6ee7b7;
      background: var(--ok-bg);
    }

    .att-hist-item.absent {
      border-color: #fca5a5;
      background: var(--err-bg);
    }

    .att-hist-item.unrecorded {
      border-color: var(--bdr2);
      background: var(--s2);
      opacity: .75;
    }

    .ahi-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      flex-shrink: 0;
    }

    .present .ahi-dot {
      background: var(--ok);
    }

    .absent .ahi-dot {
      background: var(--err);
    }

    .unrecorded .ahi-dot {
      background: var(--t5);
    }

    .ahi-info {
      flex: 1;
      min-width: 0;
    }

    .ahi-date {
      font-size: .9rem;
      font-weight: 800;
      color: var(--t1);
    }

    .ahi-meta {
      font-size: .67rem;
      color: var(--t4);
      font-weight: 500;
      margin-top: 2px;
    }

    .ahi-status {
      padding: 3px 10px;
      border-radius: var(--r-full);
      font-size: .7rem;
      font-weight: 700;
      flex-shrink: 0;
    }

    .present .ahi-status {
      background: rgba(5, 150, 105, .12);
      color: var(--ok);
    }

    .absent .ahi-status {
      background: rgba(220, 38, 38, .12);
      color: var(--err);
    }

    .unrecorded .ahi-status {
      background: var(--bdr2);
      color: var(--t4);
    }

    .att-hist-empty {
      text-align: center;
      padding: 32px 16px;
      color: var(--t4);
      font-size: .88rem;
      font-weight: 600;
    }

    .att-hist-empty i {
      display: block;
      font-size: 1.8rem;
      margin-bottom: 10px;
      opacity: .4;
    }

    .att-hist-count {
      font-size: .72rem;
      color: var(--t4);
      font-weight: 600;
      padding: 6px 18px 2px;
    }

    /* ══ CLEAN SCROLLBAR ════════════════════════════════ */
    .att-hist-scroll {
      scrollbar-width: thin;
      scrollbar-color: var(--bdr) transparent;
    }

    .att-hist-scroll::-webkit-scrollbar {
      width: 4px;
    }

    .att-hist-scroll::-webkit-scrollbar-track {
      background: transparent;
    }

    .att-hist-scroll::-webkit-scrollbar-thumb {
      background: var(--bdr);
      border-radius: var(--r-full);
    }

    .att-hist-scroll::-webkit-scrollbar-thumb:hover {
      background: var(--t5);
    }

    .settings-sheet {
      scrollbar-width: thin;
      scrollbar-color: var(--bdr) transparent;
    }

    .settings-sheet::-webkit-scrollbar {
      width: 4px;
    }

    .settings-sheet::-webkit-scrollbar-track {
      background: transparent;
    }

    .settings-sheet::-webkit-scrollbar-thumb {
      background: var(--bdr);
      border-radius: var(--r-full);
    }

    .settings-sheet::-webkit-scrollbar-thumb:hover {
      background: var(--t5);
    }

    /* ══ TRIPS ═══════════════════════════════════════════ */
    #tripList {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 12px;
    }

    .trip-card {
      border-radius: var(--r-md);
      overflow: hidden;
      border: 1px solid var(--bdr);
      background: var(--surf);
      transition: var(--norm);
      cursor: pointer;
      display: flex;
      flex-direction: column;
      height: 100%;
    }

    .trip-card:hover {
      transform: translateY(-3px);
      box-shadow: var(--sh-md);
      border-color: var(--trip-l);
    }

    .trip-thumb {
      width: 100%;
      height: 120px;
      object-fit: cover;
      display: block;
      background: linear-gradient(135deg, #0c4a6e, #0369a1);
    }

    .trip-thumb-placeholder {
      width: 100%;
      height: 120px;
      background: linear-gradient(135deg, #1e3a5f 0%, #0369a1 50%, #0ea5e9 100%);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      color: rgba(255, 255, 255, .5);
      font-size: 2rem;
      gap: 6px;
    }

    .trip-thumb-placeholder span {
      font-size: .75rem;
      font-weight: 600;
    }

    /* price overlay on thumb */
    .trip-thumb-wrap {
      position: relative;
    }

    .trip-price-overlay {
      position: absolute;
      bottom: 8px;
      left: 8px;
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .trip-price-pill {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 3px 8px;
      border-radius: var(--r-full);
      font-size: .68rem;
      font-weight: 800;
      backdrop-filter: blur(12px);
    }

    .trip-price-pill.main {
      background: rgba(16, 185, 129, .9);
      color: #fff;
      box-shadow: 0 2px 8px rgba(0, 0, 0, .2);
    }

    .trip-price-pill.remaining {
      background: rgba(220, 38, 38, .85);
      color: #fff;
      font-size: .62rem;
    }

    .trip-status-overlay {
      position: absolute;
      top: 8px;
      right: 8px;
      padding: 3px 8px;
      border-radius: var(--r-full);
      font-size: .62rem;
      font-weight: 700;
      backdrop-filter: blur(10px);
    }

    .ts-planned {
      background: rgba(217, 119, 6, .88);
      color: #fff;
    }

    .ts-active {
      background: rgba(5, 150, 105, .88);
      color: #fff;
    }

    .ts-completed {
      background: rgba(100, 116, 139, .8);
      color: #fff;
    }

    .ts-cancelled {
      background: rgba(220, 38, 38, .8);
      color: #fff;
    }

    .trip-body {
      padding: 10px;
      display: flex;
      flex-direction: column;
      flex: 1;
    }

    .trip-title {
      font-size: .84rem;
      font-weight: 800;
      color: var(--t1);
      line-height: 1.3;
      margin-bottom: 4px;
    }

    .trip-desc {
      font-size: .74rem;
      color: var(--t3);
      line-height: 1.4;
      margin-bottom: 8px;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .trip-meta-row {
      margin-top: auto;
      display: flex;
      flex-wrap: wrap;
      gap: 5px;
      align-items: center;
      padding-top: 6px;
    }

    .trip-meta-chip {
      display: inline-flex;
      align-items: center;
      gap: 3px;
      font-size: .64rem;
      color: var(--t3);
      padding: 2px 7px;
      border-radius: var(--r-full);
      background: var(--s2);
      border: 1px solid var(--bdr);
    }

    @media (max-width: 480px) {
      .hero-top {
        padding: 10px 10px 0;
        gap: 8px;
      }

      .hero-church-chip {
        max-width: 110px;
        font-size: .7rem;
        padding: 4px 8px;
      }

      .hero-ico-btn {
        width: 30px;
        height: 30px;
        font-size: .8rem;
      }

      .hero-actions-top {
        gap: 6px;
      }

      #tripList {
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
      }

      .trip-body {
        padding: 8px;
      }

      .trip-contact-bar {
        font-size: .64rem;
        padding: 6px 8px;
        gap: 4px;
      }

      .trip-contact-action {
        width: 100%;
        margin-top: 4px;
        justify-content: center;
        padding: 3px 6px;
        font-size: .62rem;
      }

      .kids-strip .ka {
        width: 22px;
        height: 22px;
        font-size: .55rem;
      }
    }

    /* not-registered contact bar on trip card */
    .trip-contact-bar {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      margin-top: 10px;
      padding: 9px 12px;
      background: #fef3c7;
      border: 1.5px solid #fde68a;
      border-radius: var(--r-md);
      font-size: .76rem;
      font-weight: 600;
      color: #92400e;
      cursor: pointer;
      transition: var(--fast);
    }

    .trip-contact-bar:hover {
      background: #fde68a;
    }

    .trip-contact-bar.unreach {
      cursor: default;
    }

    .trip-contact-bar.unreach:hover {
      background: #fef3c7;
    }

    .trip-contact-action {
      margin-right: auto;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 4px 11px;
      border-radius: var(--r-full);
      background: #d97706;
      color: #fff;
      font-size: .72rem;
      font-weight: 700;
      transition: var(--fast);
    }

    .trip-contact-bar:hover .trip-contact-action {
      background: #92400e;
    }

    /* avatars strip */
    .kids-strip {
      display: flex;
      align-items: center;
      margin-top: 10px;
    }

    .kids-strip .ka {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      border: 2px solid var(--surf);
      background: linear-gradient(135deg, var(--brand-bg), #c7d2fe);
      color: var(--brand);
      font-size: .65rem;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-left: -8px;
      overflow: hidden;
      flex-shrink: 0;
    }

    .kids-strip .ka:first-child {
      margin-left: 0;
    }

    .kids-strip .ka img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .kids-strip .ka-more {
      background: var(--brand);
      color: #fff;
      font-size: .6rem;
    }

    /* ══ TASKS ════════════════════════════════════════════ */
    .task-card {
      border-radius: var(--r-md);
      overflow: hidden;
      border: 1px solid var(--bdr);
      margin-bottom: 10px;
      background: var(--surf);
      transition: var(--fast);
      cursor: pointer;
      display: flex;
    }

    .task-card:last-child {
      margin-bottom: 0;
    }

    .task-card:hover {
      transform: translateX(-2px);
      box-shadow: var(--sh-sm);
      border-color: var(--brand-l);
    }

    .task-bar {
      width: 5px;
      flex-shrink: 0;
      background: linear-gradient(180deg, var(--brand), var(--cou-l));
    }

    .task-bar.done-bar {
      background: linear-gradient(180deg, var(--ok), #6ee7b7);
    }

    .task-bar.exp-bar {
      background: var(--t5);
    }

    .task-bar.up-bar {
      background: linear-gradient(180deg, var(--warn-l), var(--gold-l));
    }

    .task-body {
      padding: 11px 13px;
      flex: 1;
    }

    .task-top {
      display: flex;
      align-items: flex-start;
      gap: 8px;
      margin-bottom: 6px;
    }

    .task-title {
      font-size: .88rem;
      font-weight: 700;
      color: var(--t1);
      flex: 1;
      line-height: 1.35;
    }

    .task-badge {
      font-size: .66rem;
      font-weight: 700;
      padding: 2px 8px;
      border-radius: var(--r-full);
      flex-shrink: 0;
      white-space: nowrap;
    }

    .tb-open {
      background: var(--ok-bg);
      color: var(--ok);
    }

    .tb-done {
      background: var(--brand-bg);
      color: var(--brand);
    }

    .tb-up {
      background: var(--warn-bg);
      color: var(--warn);
    }

    .tb-exp {
      background: var(--s2);
      color: var(--t4);
      border: 1px solid var(--bdr);
    }

    .task-metas {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
    }

    .task-meta-chip {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: .69rem;
      color: var(--t4);
    }

    .task-result {
      display: flex;
      align-items: center;
      gap: 7px;
      margin-top: 7px;
      padding: 7px 10px;
      border-radius: var(--r-sm);
      background: var(--brand-bg);
      border: 1px solid var(--brand-bg);
      font-size: .77rem;
      color: var(--brand);
      font-weight: 700;
    }

    /* ══ ANNOUNCEMENTS ═══════════════════════════════════ */
    .ann-shell {
      display: grid;
      gap: 14px;
    }

    .ann-summary {
      display: grid;
      grid-template-columns: minmax(0, 1.7fr) minmax(140px, .9fr);
      gap: 12px;
    }

    .ann-summary-card,
    .ann-highlight {
      position: relative;
      overflow: hidden;
      border-radius: 22px;
      border: 1px solid rgba(226, 232, 240, .95);
      background: linear-gradient(180deg, rgba(255, 255, 255, .98), rgba(248, 250, 252, .96));
      box-shadow: 0 10px 26px rgba(15, 23, 42, .05);
    }

    .ann-highlight {
      padding: 18px;
      background:
        radial-gradient(circle at top right, rgba(245, 158, 11, .18), transparent 42%),
        linear-gradient(135deg, rgba(255, 251, 235, .98), rgba(255, 255, 255, .98));
    }

    .ann-highlight-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 11px;
      border-radius: var(--r-full);
      background: rgba(255, 255, 255, .72);
      color: var(--warn);
      font-size: .72rem;
      font-weight: 800;
      margin-bottom: 12px;
      border: 1px solid rgba(245, 158, 11, .16);
    }

    .ann-highlight-title {
      font-size: 1rem;
      font-weight: 800;
      color: var(--t1);
      margin-bottom: 6px;
    }

    .ann-highlight-text {
      font-size: .83rem;
      line-height: 1.75;
      color: var(--t2);
      margin-bottom: 12px;
    }

    .ann-highlight-meta {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 7px;
    }

    .ann-stat-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 10px;
      padding: 14px;
    }

    .ann-stat {
      padding: 13px 12px;
      border-radius: 16px;
      background: var(--s2);
      border: 1px solid var(--bdr);
      text-align: center;
    }

    .ann-stat-val {
      display: block;
      font-size: 1.18rem;
      font-weight: 800;
      color: var(--t1);
      line-height: 1;
    }

    .ann-stat-lbl {
      display: block;
      margin-top: 5px;
      font-size: .68rem;
      color: var(--t4);
      font-weight: 700;
    }

    .ann-list {
      display: grid;
      gap: 11px;
    }

    .ann-item {
      position: relative;
      display: grid;
      gap: 10px;
      padding: 15px 16px;
      border-radius: 20px;
      background: linear-gradient(180deg, rgba(248, 250, 252, .95), rgba(255, 255, 255, .98));
      border: 1px solid rgba(226, 232, 240, .95);
      box-shadow: 0 8px 22px rgba(15, 23, 42, .04);
      transition: transform var(--fast), box-shadow var(--fast), border-color var(--fast);
    }

    .ann-item::before {
      content: '';
      position: absolute;
      inset: 12px auto 12px 0;
      width: 4px;
      border-radius: 99px;
      background: linear-gradient(180deg, var(--warn-l), var(--brand));
      opacity: .9;
    }

    .ann-item:hover {
      transform: translateY(-2px);
      border-color: rgba(129, 140, 248, .4);
      box-shadow: 0 14px 28px rgba(15, 23, 42, .07);
    }

    .ann-top {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 10px;
    }

    .ann-type {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      font-size: .7rem;
      font-weight: 800;
      padding: 5px 10px;
      border-radius: var(--r-full);
      background: linear-gradient(135deg, rgba(238, 242, 255, .96), rgba(224, 231, 255, .96));
      color: var(--brand);
      border: 1px solid rgba(129, 140, 248, .16);
    }

    .ann-type.link {
      background: linear-gradient(135deg, rgba(237, 233, 254, .96), rgba(243, 232, 255, .96));
      color: var(--cou);
      border-color: rgba(139, 92, 246, .18);
    }

    .ann-date {
      font-size: .68rem;
      color: var(--t4);
      font-weight: 700;
      white-space: nowrap;
      padding-top: 2px;
    }

    .ann-text {
      font-size: .84rem;
      color: var(--t1);
      line-height: 1.75;
      padding-right: 4px;
    }

    .ann-footer {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      flex-wrap: wrap;
    }

    .ann-meta-pill {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 4px 10px;
      border-radius: var(--r-full);
      background: var(--s2);
      border: 1px solid var(--bdr);
      color: var(--t3);
      font-size: .68rem;
      font-weight: 700;
    }

    .ann-link-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 7px 14px;
      border-radius: var(--r-full);
      background: linear-gradient(135deg, var(--cou-l), var(--cou));
      color: #fff;
      font-size: .76rem;
      font-weight: 800;
      text-decoration: none;
      border: none;
      cursor: pointer;
      font-family: 'Baloo Bhaijaan 2', sans-serif;
      transition: var(--fast);
      box-shadow:
        0 1px 0 rgba(255, 255, 255, .22) inset,
        0 10px 20px rgba(124, 58, 237, .18);
    }

    .ann-link-btn:hover {
      transform: translateY(-2px);
      box-shadow:
        0 1px 0 rgba(255, 255, 255, .24) inset,
        0 14px 24px rgba(124, 58, 237, .22);
    }

    .ann-empty {
      text-align: center;
      padding: 28px 18px;
      border-radius: 20px;
      background: linear-gradient(180deg, rgba(248, 250, 252, .96), rgba(255, 255, 255, .98));
      border: 1px dashed rgba(203, 213, 225, .9);
      color: var(--t3);
    }

    .ann-empty i {
      display: block;
      font-size: 1.8rem;
      margin-bottom: 10px;
      color: var(--warn);
      opacity: .75;
    }

    .ann-empty strong {
      display: block;
      font-size: .9rem;
      color: var(--t1);
      margin-bottom: 4px;
    }

    /* ══ OVERLAY / MODAL ═════════════════════════════════ */
    .overlay {
      position: fixed;
      inset: 0;
      background: rgba(10, 16, 40, .65);
      z-index: 500;
      display: flex;
      align-items: flex-start;
      justify-content: center;
      padding: 16px;
      overflow-y: auto;
      -webkit-overflow-scrolling: touch;
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
      transition: var(--norm);
    }

    .overlay:not(.open),
    .overlay:not(.open) * {
      display: none !important;
      visibility: hidden !important;
      opacity: 0 !important;
      pointer-events: none !important;
    }

    .overlay.open {
      display: flex !important;
      opacity: 1 !important;
      visibility: visible !important;
      pointer-events: auto !important;
    }

    .modal {
      background: var(--surf);
      border-radius: var(--r-xl);
      width: 100%;
      max-width: 660px;
      margin: auto;
      max-height: calc(100vh - 32px);
      max-height: calc(100dvh - 32px);
      display: flex;
      flex-direction: column;
      overflow: hidden;
      box-shadow: var(--sh-xl);
      border: 1px solid var(--bdr);
      transform: translateY(20px) scale(.97);
      transition: var(--slow);
    }

    .overlay.open .modal {
      transform: translateY(0) scale(1);
    }

    .modal.narrow {
      max-width: 400px;
    }

    .mhdr {
      display: flex;
      align-items: center;
      gap: 11px;
      padding: 16px 18px;
      border-bottom: 1px solid var(--bdr);
      background: linear-gradient(135deg, var(--brand), var(--cou));
      border-radius: var(--r-xl) var(--r-xl) 0 0;
    }

    .mhdr-title {
      font-size: .97rem;
      font-weight: 800;
      color: #fff;
      flex: 1;
    }

    .mhdr-sub {
      font-size: .7rem;
      color: rgba(255, 255, 255, .75);
      margin-top: 1px;
    }

    .mclose {
      width: 28px;
      height: 28px;
      border-radius: var(--r-sm);
      background: rgba(255, 255, 255, .15);
      border: none;
      color: #fff;
      font-size: .85rem;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: var(--fast);
    }

    .mclose:hover {
      background: rgba(255, 255, 255, .28);
    }

    .mbody {
      padding: 18px;
      overflow-y: auto;
      -webkit-overflow-scrolling: touch;
      flex: 1 1 auto;
      min-height: 0;
    }

    .mfooter {
      padding: 12px 18px;
      border-top: 1px solid var(--bdr);
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 8px;
      background: var(--s2);
      border-radius: 0 0 var(--r-xl) var(--r-xl);
      flex-shrink: 0;
    }

    /* ══ TRIP DETAIL MODAL ════════════════════════════════ */
    .trip-detail-thumb {
      width: 100%;
      max-height: 200px;
      object-fit: cover;
      border-radius: var(--r-md);
      margin-bottom: 14px;
      display: block;
    }

    .trip-detail-ph {
      width: 100%;
      height: 120px;
      border-radius: var(--r-md);
      margin-bottom: 14px;
      background: linear-gradient(135deg, #1e3a5f, #0ea5e9);
      display: flex;
      align-items: center;
      justify-content: center;
      color: rgba(255, 255, 255, .4);
      font-size: 2.5rem;
    }

    .my-trip-box {
      padding: 13px 15px;
      border-radius: var(--r-md);
      background: var(--ok-bg);
      border: 1px solid #6ee7b7;
      margin-bottom: 14px;
    }

    .my-trip-title {
      font-size: .8rem;
      font-weight: 700;
      color: var(--ok);
      margin-bottom: 8px;
      display: flex;
      align-items: center;
      gap: 5px;
    }

    .my-trip-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
    }

    .mtr-cell {
      text-align: center;
      padding: 8px;
      background: rgba(255, 255, 255, .6);
      border-radius: var(--r-sm);
    }

    .mtr-val {
      font-size: 1.1rem;
      font-weight: 800;
      color: var(--ok);
    }

    .mtr-lbl {
      font-size: .65rem;
      color: var(--t3);
      font-weight: 600;
    }

    .mtr-cell.warn {
      background: var(--warn-bg);
    }

    .mtr-cell.warn .mtr-val {
      color: var(--warn);
    }

    .mtr-cell.ok .mtr-val {
      color: var(--ok);
    }

    .kids-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(72px, 1fr));
      gap: 10px;
      margin-top: 4px;
    }

    .kid-tile {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 4px;
      text-align: center;
    }

    .kid-tile-av {
      width: 52px;
      height: 52px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--brand-bg), #c7d2fe);
      color: var(--brand);
      font-size: 1.3rem;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      border: 2px solid var(--bdr);
      transition: var(--fast);
    }

    .kid-tile-av:hover {
      border-color: var(--brand-l);
      transform: scale(1.06);
    }

    .kid-tile-av img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .kid-tile-name {
      font-size: .64rem;
      font-weight: 700;
      color: var(--t2);
      line-height: 1.2;
      max-width: 68px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .kid-tile-cls {
      font-size: .58rem;
      color: var(--t4);
    }

    .user-tile {
      /* alias for .kid-tile so both classes share styles */
    }

    .user-role {
      font-size: .58rem;
      color: var(--t4);
    }

    /* ══ EXAM / QUESTIONS ════════════════════════════════ */
    .qcard,
    .qcard-inner {
      background: var(--s2);
      border: 1.5px solid var(--bdr);
      border-radius: var(--r-md);
      margin-bottom: 12px;
      overflow: hidden;
      transition: border-color var(--fast), box-shadow var(--fast);
    }

    .qcard.qcard-unanswered {
      border: 2px solid var(--err) !important;
      box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.18) !important;
      animation: qcardPulse 1.6s ease-in-out infinite;
    }

    @keyframes qcardPulse {
      0%, 100% { box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.18); }
      50% { box-shadow: 0 0 0 6px rgba(239, 68, 68, 0.35); }
    }

    .open-ans-textarea {
      width: 100%;
      min-height: 110px;
      padding: 11px 13px;
      border: 1.5px solid var(--bdr);
      border-radius: var(--r-md);
      font-family: 'Baloo Bhaijaan 2', sans-serif;
      font-size: .9rem;
      color: var(--t1);
      background: var(--surf);
      resize: vertical;
      outline: none;
      display: block;
      line-height: 1.6;
      transition: border-color var(--fast), box-shadow var(--fast);
    }

    .open-ans-textarea:focus {
      border-color: var(--brand);
      box-shadow: 0 0 0 3px var(--brand-glow);
    }

    .qhdr {
      display: flex;
      align-items: center;
      gap: 7px;
      padding: 10px 13px;
      background: var(--surf);
      border-bottom: 1px solid var(--bdr);
    }

    .qnum {
      width: 22px;
      height: 22px;
      border-radius: 5px;
      background: linear-gradient(135deg, var(--brand), var(--cou-l));
      color: #fff;
      font-size: .68rem;
      font-weight: 800;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .qtext {
      font-size: .85rem;
      font-weight: 700;
      color: var(--t1);
      flex: 1;
    }

    .qdeg {
      font-size: .68rem;
      font-weight: 700;
      color: var(--brand);
      background: var(--brand-bg);
      border: 1px solid var(--brand-l);
      padding: 2px 7px;
      border-radius: var(--r-full);
    }

    .qopts {
      padding: 9px 13px;
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .qopt {
      display: flex;
      align-items: center;
      gap: 7px;
      padding: 8px 11px;
      border-radius: var(--r-sm);
      border: 1.5px solid var(--bdr);
      background: var(--surf);
      cursor: pointer;
      transition: var(--fast);
      font-size: .81rem;
      color: var(--t1);
    }

    .qopt:hover {
      border-color: var(--brand-l);
      background: var(--brand-bg);
    }

    .qopt.selected {
      border-color: var(--brand);
      background: var(--brand-bg);
      color: var(--brand);
      font-weight: 700;
    }

    .qopt.correct {
      border-color: var(--ok);
      background: var(--ok-bg);
      color: var(--ok);
      font-weight: 700;
    }

    .qopt.wrong {
      border-color: var(--err);
      background: var(--err-bg);
      color: var(--err);
    }

    .oradio {
      width: 15px;
      height: 15px;
      border-radius: 50%;
      border: 2px solid var(--bdr);
      flex-shrink: 0;
      transition: var(--fast);
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .qopt.selected .oradio {
      border-color: var(--brand);
      background: var(--brand);
    }

    .qopt.selected .oradio::after {
      content: '';
      width: 5px;
      height: 5px;
      border-radius: 50%;
      background: #fff;
    }

    .qopt.correct .oradio {
      border-color: var(--ok);
      background: var(--ok);
    }

    .qopt.correct .oradio::after {
      content: '✓';
      font-size: .5rem;
      color: #fff;
      font-weight: 900;
    }

    .qopt.wrong .oradio {
      border-color: var(--err);
      background: var(--err);
    }

    .qopt.wrong .oradio::after {
      content: '✗';
      font-size: .5rem;
      color: #fff;
      font-weight: 900;
    }

    .olet {
      width: 18px;
      height: 18px;
      border-radius: 4px;
      background: var(--bdr);
      font-size: .64rem;
      font-weight: 700;
      color: var(--t3);
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .qopt.selected .olet,
    .qopt.correct .olet,
    .qopt.wrong .olet {
      background: transparent;
    }

    .result-card {
      text-align: center;
      padding: 28px 20px;
      background: linear-gradient(135deg, var(--brand-bg), #e0e7ff);
      border-radius: var(--r-lg);
      border: 1px solid var(--brand-l);
    }

    .result-icon {
      font-size: 3rem;
      margin-bottom: 10px;
    }

    .result-score {
      font-size: 2.6rem;
      font-weight: 800;
      color: var(--brand);
    }

    .result-pct {
      font-size: .95rem;
      color: var(--t3);
      margin-top: 3px;
      font-weight: 600;
    }

    .result-coupons {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      margin-top: 14px;
      padding: 8px 20px;
      border-radius: var(--r-full);
      background: linear-gradient(135deg, var(--cou-l), var(--cou));
      color: #fff;
      font-weight: 800;
      font-size: .9rem;
    }

    /* ══ TIMER ════════════════════════════════════════════ */
    .timer-wrap {
      margin-bottom: 12px;
    }

    .timer-bar {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 9px 13px;
      background: var(--warn-bg);
      border: 1px solid #fde68a;
      border-radius: var(--r-md);
    }

    .timer-bar.urgent {
      background: var(--err-bg);
      border-color: #fca5a5;
    }

    .timer-val {
      font-size: 1.05rem;
      font-weight: 800;
      color: var(--warn);
    }

    .timer-bar.urgent .timer-val {
      color: var(--err);
    }

    .prog-wrap {
      margin-bottom: 12px;
    }

    .prog-bar {
      height: 5px;
      background: var(--bdr);
      border-radius: var(--r-full);
      overflow: hidden;
    }

    .prog-fill {
      height: 100%;
      background: linear-gradient(90deg, var(--brand), var(--cou-l));
      border-radius: var(--r-full);
      transition: width .4s var(--ease);
    }

    .prog-lbl {
      display: flex;
      justify-content: space-between;
      font-size: .66rem;
      color: var(--t4);
      margin-top: 3px;
    }

    /* ══ SETTINGS / FAB ══════════════════════════════════ */
    /* fab removed */
    .settings-list {
      display: flex;
      flex-direction: column;
      gap: 7px;
    }

    .s-btn {
      display: flex;
      align-items: center;
      gap: 11px;
      padding: 12px 15px;
      border-radius: var(--r-md);
      border: 1.5px solid var(--bdr);
      background: var(--surf);
      font-family: 'Baloo Bhaijaan 2', sans-serif;
      font-weight: 700;
      font-size: .88rem;
      color: var(--t1);
      cursor: pointer;
      transition: var(--fast);
      width: 100%;
      text-align: right;
    }

    .s-btn:hover {
      background: var(--brand-bg);
      border-color: var(--brand-l);
      transform: translateX(-2px);
    }

    .s-btn i {
      width: 20px;
      color: var(--brand);
      font-size: 1rem;
    }

    .s-btn.danger {
      color: var(--err);
      border-color: #fca5a5;
    }

    .s-btn.danger:hover {
      background: var(--err-bg);
    }

    .s-btn.danger i {
      color: var(--err);
    }

    /* ══ FORM ════════════════════════════════════════════ */
    .fg {
      display: flex;
      flex-direction: column;
      gap: 4px;
      margin-bottom: 12px;
    }

    .flbl {
      font-size: .74rem;
      font-weight: 700;
      color: var(--t2);
    }

    .fi {
      padding: 10px 12px;
      border: 1.5px solid var(--bdr);
      border-radius: var(--r-md);
      font-family: 'Baloo Bhaijaan 2', sans-serif;
      font-size: .87rem;
      color: var(--t1);
      background: var(--surf);
      outline: none;
      width: 100%;
      transition: var(--fast);
    }

    .fi:focus {
      border-color: var(--brand);
      box-shadow: 0 0 0 3px var(--brand-glow);
    }

    .pass-wrap {
      position: relative;
    }

    .pass-wrap .fi {
      padding-left: 42px;
    }

    .pass-eye {
      position: absolute;
      left: 11px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      color: var(--t4);
      font-size: .88rem;
      cursor: pointer;
    }

    /* ══ BUTTONS ═════════════════════════════════════════ */
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 8px 17px;
      border-radius: var(--r-full);
      font-family: 'Baloo Bhaijaan 2', sans-serif;
      font-size: .84rem;
      font-weight: 700;
      border: 1.5px solid transparent;
      cursor: pointer;
      transition: var(--fast);
    }

    .btn-p {
      background: linear-gradient(135deg, var(--brand), var(--cou));
      color: #fff;
      box-shadow: var(--sh-brand);
    }

    .btn-p:hover {
      transform: translateY(-1px);
      box-shadow: 0 12px 26px -6px rgba(79, 70, 229, .42);
    }

    .btn-g {
      background: var(--s2);
      color: var(--t2);
      border-color: var(--bdr);
    }

    .btn-g:hover {
      background: var(--brand-bg);
      color: var(--brand);
      border-color: var(--brand-l);
    }

    .btn:disabled {
      opacity: .5;
      cursor: not-allowed;
      transform: none !important;
    }

    /* ══ PHOTO UPLOAD ════════════════════════════════════ */
    .upload-drop {
      border: 2.5px dashed var(--bdr);
      border-radius: var(--r-lg);
      padding: 28px 20px;
      text-align: center;
      cursor: pointer;
      transition: var(--fast);
      margin-bottom: 12px;
    }

    .upload-drop:hover,
    .upload-drop.over {
      border-color: var(--brand);
      background: var(--brand-bg);
    }

    .upload-drop i {
      font-size: 2.4rem;
      color: var(--brand);
      display: block;
      margin-bottom: 8px;
    }

    .upload-drop p {
      font-size: .8rem;
      color: var(--t3);
    }

    .crop-area {
      width: 100%;
      height: 280px;
      background: var(--s2);
      border-radius: var(--r-md);
      overflow: hidden;
      margin-bottom: 12px;
    }

    /* ══ ACCOUNT SWITCHER ════════════════════════════════ */
    .acc-item {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 13px;
      border-radius: var(--r-md);
      border: 1.5px solid var(--bdr);
      background: var(--surf);
      cursor: pointer;
      transition: var(--fast);
      margin-bottom: 7px;
    }

    .acc-item:last-child {
      margin-bottom: 0;
    }

    .acc-item:hover,
    .acc-item.active {
      background: var(--brand-bg);
      border-color: var(--brand-l);
    }

    .acc-av {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      background: var(--brand-bg);
      color: var(--brand);
      font-size: 1rem;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      overflow: hidden;
      border: 2px solid var(--bdr);
    }

    .acc-av img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .acc-name {
      font-weight: 800;
      font-size: .84rem;
      color: var(--t1);
    }

    .acc-cls {
      font-size: .69rem;
      color: var(--t4);
    }

    /* ══ MAIN PAGE ACCOUNT SWITCHER ═════════════════════════ */
    .account-switcher-box {
      background: var(--surf);
      border: 1.5px solid var(--bdr);
      border-radius: var(--r-lg);
      padding: 18px 18px 16px;
      margin-bottom: 20px;
      box-shadow: var(--sh-md);
      position: relative;
      overflow: hidden;
      transition: all var(--norm);
      direction: rtl;
      text-align: right;
    }

    .account-switcher-box.highlight-pulse {
      animation: boxGlowPulse 1.8s ease-in-out 3;
    }

    @keyframes boxGlowPulse {
      0%, 100% {
        box-shadow: var(--sh-md);
        border-color: var(--bdr);
      }
      50% {
        box-shadow: 0 0 0 4px var(--brand-glow), 0 10px 30px rgba(79, 70, 229, 0.25);
        border-color: var(--brand);
      }
    }

    .as-head-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      margin-bottom: 12px;
      flex-wrap: nowrap;
    }

    .as-head-info-wrap {
      display: flex;
      align-items: center;
      gap: 9px;
      min-width: 0;
      flex: 1;
      overflow: hidden;
    }

    .as-head-icon {
      width: 38px;
      height: 38px;
      border-radius: var(--r-md);
      background: var(--brand-bg);
      color: var(--brand);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.1rem;
      flex-shrink: 0;
    }

    .as-head-text-col {
      min-width: 0;
      flex: 1;
      overflow: hidden;
    }

    .as-head-title-row {
      font-size: 1rem;
      font-weight: 800;
      color: var(--t1);
      display: flex;
      align-items: center;
      gap: 8px;
      white-space: nowrap;
      flex-wrap: nowrap;
      overflow: hidden;
    }

    .as-head-title-text {
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .as-title-full {
      display: inline;
    }

    .as-title-mobile {
      display: none;
    }

    .as-head-sub-text {
      font-size: .73rem;
      color: var(--t3);
      font-weight: 600;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .as-count-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 1px 8px;
      border-radius: var(--r-full);
      background: var(--brand-bg);
      color: var(--brand);
      font-size: .72rem;
      font-weight: 800;
      border: 1px solid var(--brand-l);
      flex-shrink: 0;
      white-space: nowrap;
    }

    .acc-tag-long {
      display: inline;
    }

    .acc-tag-short {
      display: none;
    }

    .as-open-all-btn {
      background: none;
      border: 1px solid var(--bdr);
      border-radius: var(--r-full);
      padding: 6px 14px;
      color: var(--t3);
      font-family: inherit;
      font-size: .76rem;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      transition: all var(--fast);
    }

    .as-open-all-btn:hover {
      background: var(--bdr2);
      color: var(--brand);
      border-color: var(--brand-l);
    }

    /* First-time notice inside switcher */
    .first-time-switch-notice {
      background: linear-gradient(135deg, #eef2ff 0%, #ede9fe 50%, #fdf4ff 100%);
      border: 1.5px solid #a5b4fc;
      border-radius: var(--r-md);
      padding: 14px 16px;
      margin-bottom: 16px;
      display: flex;
      align-items: flex-start;
      gap: 12px;
      position: relative;
      box-shadow: 0 4px 15px rgba(99, 102, 241, 0.1);
      animation: fadeIn 0.4s ease both;
      direction: rtl;
      text-align: right;
    }

    .notice-icon-box {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: var(--brand);
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.1rem;
      flex-shrink: 0;
      box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
    }

    .notice-text-content {
      flex: 1;
      min-width: 0;
    }

    .notice-title {
      font-size: .92rem;
      font-weight: 800;
      color: #312e81;
      margin-bottom: 3px;
    }

    .notice-desc {
      font-size: .78rem;
      color: #4338ca;
      line-height: 1.45;
      font-weight: 600;
    }

    .notice-arrow-hint {
      font-size: .72rem;
      color: #6366f1;
      font-weight: 800;
      margin-top: 6px;
      display: flex;
      align-items: center;
      gap: 5px;
    }

    .notice-dismiss-btn {
      background: #4f46e5;
      color: #fff;
      border: none;
      border-radius: var(--r-sm);
      padding: 6px 14px;
      font-family: inherit;
      font-size: .76rem;
      font-weight: 800;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      flex-shrink: 0;
      align-self: center;
      box-shadow: 0 2px 8px rgba(79, 70, 229, 0.35);
      transition: all var(--fast);
    }

    .notice-dismiss-btn:hover {
      background: #4338ca;
      transform: translateY(-1px);
    }

    /* Account cards in main page */
    .acc-cards-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
      gap: 12px;
    }

    .acc-card-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 14px;
      border-radius: var(--r-md);
      border: 1.5px solid var(--bdr);
      background: var(--s2);
      cursor: pointer;
      position: relative;
      transition: all var(--fast);
    }

    .acc-card-item:hover {
      background: var(--surf);
      border-color: var(--brand-l);
      transform: translateY(-2px);
      box-shadow: var(--sh-sm);
    }

    .acc-card-item.active {
      background: linear-gradient(135deg, rgba(238, 242, 255, 0.95), rgba(245, 243, 255, 0.8));
      border: 2px solid var(--brand);
      box-shadow: 0 4px 14px rgba(79, 70, 229, 0.12);
      cursor: default;
    }

    .acc-card-av {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      background: var(--brand-bg);
      color: var(--brand);
      font-size: 1.15rem;
      font-weight: 800;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      overflow: hidden;
      border: 2px solid #fff;
      box-shadow: 0 2px 6px rgba(0,0,0,0.08);
    }

    .acc-card-av img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .acc-card-info {
      flex: 1;
      min-width: 0;
    }

    .acc-card-name {
      font-weight: 800;
      font-size: .88rem;
      color: var(--t1);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .acc-card-class {
      font-size: .72rem;
      color: var(--t3);
      margin-top: 1px;
      display: flex;
      align-items: center;
      gap: 4px;
    }

    .acc-card-tag {
      font-size: .7rem;
      font-weight: 800;
      padding: 3px 9px;
      border-radius: var(--r-full);
      display: inline-flex;
      align-items: center;
      gap: 4px;
      flex-shrink: 0;
    }

    .acc-card-tag.current {
      background: var(--brand);
      color: #fff;
      box-shadow: 0 2px 6px rgba(79, 70, 229, 0.3);
    }

    .acc-card-tag.switch-btn {
      background: var(--surf);
      color: var(--brand);
      border: 1px solid var(--brand-l);
      transition: all var(--fast);
    }

    .acc-card-item:hover .acc-card-tag.switch-btn {
      background: var(--brand);
      color: #fff;
    }

    /* ══ MOBILE COMPACT STYLING FOR ACCOUNT SWITCHER ═════════ */
    @media (max-width: 600px) {
      .account-switcher-box {
        padding: 10px 12px 9px;
        margin-bottom: 12px;
        border-radius: var(--r-md, 12px);
      }

      .account-switcher-box.highlight-pulse {
        animation: boxGlowPulseMobile 1.6s ease-in-out 3;
      }

      .as-head-bar {
        margin-bottom: 8px;
        gap: 8px;
        flex-wrap: nowrap;
      }

      .as-head-info-wrap {
        gap: 7px;
        min-width: 0;
        flex: 1;
        overflow: hidden;
      }

      .as-head-icon {
        width: 26px;
        height: 26px;
        font-size: 0.8rem;
        border-radius: 6px;
        flex-shrink: 0;
      }

      .as-head-text-col {
        min-width: 0;
        flex: 1;
        overflow: hidden;
      }

      .as-head-title-row {
        font-size: 0.82rem;
        gap: 5px;
        white-space: nowrap;
        flex-wrap: nowrap;
        overflow: hidden;
      }

      .as-title-full {
        display: none;
      }

      .as-title-mobile {
        display: inline;
        white-space: nowrap;
      }

      .as-head-title-text {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      .as-count-badge {
        font-size: 0.65rem;
        padding: 0 5px;
        min-width: 18px;
        height: 18px;
        flex-shrink: 0;
      }

      .as-head-sub-text {
        font-size: 0.65rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      .as-open-all-btn {
        padding: 3px 8px;
        font-size: 0.68rem;
        gap: 3px;
        flex-shrink: 0;
        white-space: nowrap;
      }

      .first-time-switch-notice {
        padding: 8px 10px;
        margin-bottom: 8px;
        gap: 8px;
        border-radius: 8px;
      }

      .notice-icon-box {
        width: 26px;
        height: 26px;
        font-size: 0.8rem;
      }

      .notice-title {
        font-size: 0.78rem;
        margin-bottom: 1px;
      }

      .notice-desc {
        font-size: 0.69rem;
        line-height: 1.35;
      }

      .notice-arrow-hint {
        display: none;
      }

      .notice-dismiss-btn {
        padding: 3px 8px;
        font-size: 0.68rem;
        border-radius: 5px;
      }

      .acc-cards-grid {
        grid-template-columns: 1fr;
        gap: 6px;
      }

      .acc-card-item {
        padding: 6px 10px;
        gap: 8px;
        border-radius: 8px;
      }

      .acc-card-av {
        width: 32px;
        height: 32px;
        font-size: 0.88rem;
        border-width: 1.5px;
      }

      .acc-card-name {
        font-size: 0.82rem;
      }

      .acc-card-class {
        font-size: 0.66rem;
        gap: 3px;
      }

      .acc-card-tag {
        font-size: 0.65rem;
        padding: 2px 7px;
        border-radius: 6px;
      }

      .acc-tag-long {
        display: none;
      }

      .acc-tag-short {
        display: inline;
      }
    }

    @keyframes boxGlowPulseMobile {
      0%, 100% {
        box-shadow: var(--sh-sm);
        border-color: var(--bdr);
      }
      50% {
        box-shadow: 0 0 0 2.5px var(--brand-glow), 0 4px 14px rgba(79, 70, 229, 0.28);
        border-color: var(--brand);
      }
    }

    @media (max-width: 360px) {
      .as-head-sub-text {
        display: none;
      }
      .as-head-title-text {
        font-size: 0.78rem;
      }
    }

    /* First-time multi-account modal */
    .ft-avatars-wrap {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      direction: ltr; /* Keeps overlap visually uniform */
      margin: 0 auto 16px;
      padding: 4px;
    }

    .ft-avatar-circle {
      width: 66px;
      height: 66px;
      border-radius: 50%;
      border: 3.5px solid var(--surf);
      background: var(--s3);
      overflow: hidden;
      position: relative;
      margin-left: -18px;
      box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12);
      display: flex;
      align-items: center;
      justify-content: center;
      transition: transform var(--fast), z-index var(--fast);
      flex-shrink: 0;
      animation: avatarPop 0.5s var(--spring) both;
    }

    .ft-avatars-wrap .ft-avatar-circle:first-child {
      margin-left: 0;
    }

    .ft-avatar-circle:hover {
      transform: translateY(-4px) scale(1.08);
      z-index: 10 !important;
    }

    .ft-avatar-circle img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .ft-avatar-circle.ft-avatar-fallback {
      background: linear-gradient(135deg, var(--brand), #8b5cf6);
      color: #fff;
      font-weight: 800;
      font-size: 1.4rem;
      user-select: none;
    }

    .ft-avatar-circle.ft-avatar-extra {
      background: linear-gradient(135deg, #8b5cf6, var(--brand-d));
      color: #fff;
      font-weight: 900;
      font-size: 1.12rem;
      letter-spacing: -0.5px;
      user-select: none;
    }

    .ft-icon-ring {
      width: 64px;
      height: 64px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--brand), #8b5cf6);
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.8rem;
      margin: 0 auto 16px;
      box-shadow: 0 8px 24px rgba(79, 70, 229, 0.35);
      animation: avatarPop 0.5s var(--spring) both;
    }

    .ft-title {
      font-size: 1.18rem;
      font-weight: 900;
      color: var(--t1);
      margin-bottom: 8px;
    }

    .ft-desc {
      font-size: .84rem;
      color: var(--t3);
      line-height: 1.6;
      margin-bottom: 18px;
    }

    .ft-kids-preview {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      flex-wrap: wrap;
      margin-bottom: 22px;
    }

    .ft-kid-chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 12px;
      border-radius: var(--r-full);
      background: var(--brand-bg);
      border: 1px solid var(--brand-l);
      color: var(--brand);
      font-size: .8rem;
      font-weight: 800;
    }

    .ft-actions {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .ft-btn-primary {
      width: 100%;
      padding: 12px;
      border-radius: var(--r-md);
      background: linear-gradient(135deg, var(--brand), #7c3aed);
      color: #fff;
      border: none;
      font-family: inherit;
      font-size: .92rem;
      font-weight: 800;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      box-shadow: var(--sh-brand);
      transition: all var(--fast);
    }

    .ft-btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 25px rgba(79, 70, 229, 0.4);
    }

    .ft-btn-secondary {
      width: 100%;
      padding: 10px;
      border-radius: var(--r-md);
      background: none;
      color: var(--t3);
      border: 1px solid var(--bdr);
      font-family: inherit;
      font-size: .85rem;
      font-weight: 700;
      cursor: pointer;
      transition: all var(--fast);
    }

    .ft-btn-secondary:hover {
      background: var(--s2);
      color: var(--t1);
    }

    /* Separate accounts modal styling */
    .sep-acc-card {
      background: var(--s2);
      border: 1px solid var(--bdr);
      border-radius: var(--r-lg);
      padding: 14px 16px;
      transition: border-color var(--fast), box-shadow var(--fast);
    }
    .sep-acc-card:focus-within {
      border-color: var(--brand);
      box-shadow: 0 0 0 3px var(--brand-glow);
    }
    .sep-card-head {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 12px;
      padding-bottom: 10px;
      border-bottom: 1px dashed var(--bdr2);
    }
    .sep-card-av {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      overflow: hidden;
      background: var(--brand-bg);
      color: var(--brand);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 800;
      font-size: 1.05rem;
      flex-shrink: 0;
      border: 2px solid var(--surf);
      box-shadow: var(--sh-sm);
    }
    .sep-card-av img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }
    .sep-card-info {
      flex: 1;
      min-width: 0;
    }
    .sep-card-name {
      font-size: .96rem;
      font-weight: 800;
      color: var(--t1);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .sep-card-meta {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-top: 3px;
      flex-wrap: wrap;
    }
    .sep-class-tag {
      font-size: .74rem;
      color: var(--t3);
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }
    .sep-status-badge {
      font-size: .72rem;
      font-weight: 800;
      padding: 2px 8px;
      border-radius: var(--r-full);
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }
    .sep-status-badge.has-pass {
      background: rgba(16, 185, 129, .12);
      color: #10b981;
    }
    .sep-status-badge.no-pass {
      background: rgba(239, 68, 68, .12);
      color: #ef4444;
      animation: pulse 2s infinite;
    }
    .sep-field-group {
      margin-top: 10px;
    }
    .sep-field-label {
      display: block;
      font-size: .78rem;
      font-weight: 700;
      color: var(--t2);
      margin-bottom: 5px;
    }
    .sep-field-input {
      width: 100%;
      padding: 9px 12px;
      border-radius: var(--r-md);
      border: 1.5px solid var(--bdr);
      background: var(--surf);
      color: var(--t1);
      font-family: inherit;
      font-size: .88rem;
      outline: none;
      transition: all var(--fast);
      box-sizing: border-box;
    }
    .sep-field-input:focus {
      border-color: var(--brand);
      box-shadow: 0 0 0 3px var(--brand-glow);
    }
    .sep-field-input.input-error {
      border-color: var(--danger) !important;
      background: var(--danger-bg) !important;
    }
    .sep-pass-wrap {
      position: relative;
    }
    .sep-pass-wrap input {
      padding-left: 40px;
    }
    .sep-pass-toggle {
      position: absolute;
      left: 10px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      color: var(--t3);
      cursor: pointer;
      padding: 4px;
      font-size: .88rem;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: color var(--fast);
    }
    .sep-pass-toggle:hover {
      color: var(--brand);
    }
    .sep-field-hint {
      font-size: .72rem;
      color: var(--t3);
      margin-top: 4px;
      line-height: 1.4;
    }
    .sep-field-hint.required {
      color: #ef4444;
      font-weight: 700;
    }

    /* ══ LOADING / TOAST / EMPTY ═════════════════════════ */
    .loading-screen {
      position: fixed;
      inset: 0;
      background: rgba(255, 255, 255, .96);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      z-index: 10000;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 14px;
      font-weight: 700;
      color: var(--brand);
      font-size: .95rem;
      transition: opacity 0.2s ease, visibility 0.2s ease;
    }

    .loading-screen.hidden {
      display: none !important;
    }

    .spin {
      display: inline-block;
      width: 38px;
      height: 38px;
      border: 3px solid var(--brand-bg);
      border-top-color: var(--brand);
      border-radius: 50%;
      animation: _spin .7s linear infinite;
    }

    .spin-sm {
      width: 14px;
      height: 14px;
      border-width: 2px;
      border-top-color: #fff;
    }

    @keyframes _spin {
      to {
        transform: rotate(360deg)
      }
    }

    .tc {
      position: fixed;
      bottom: 18px;
      right: 14px;
      z-index: 9999;
      display: flex;
      flex-direction: column;
      gap: 5px;
      pointer-events: none;
    }

    .toast {
      display: flex;
      align-items: center;
      gap: 7px;
      padding: 9px 15px;
      border-radius: var(--r-full);
      background: var(--t1);
      color: #fff;
      font-size: .81rem;
      font-weight: 700;
      box-shadow: var(--sh-lg);
      opacity: 0;
      transform: translateX(16px);
      transition: var(--norm);
      pointer-events: auto;
      white-space: nowrap;
      max-width: 270px;
    }

    .toast.show {
      opacity: 1;
      transform: translateX(0);
    }

    .toast.ok {
      background: var(--ok);
    }

    .toast.err {
      background: var(--err);
    }

    .toast.info {
      background: var(--brand);
    }

    .empty-st {
      text-align: center;
      padding: 28px 20px;
      color: var(--t4);
    }

    .empty-st i {
      font-size: 2rem;
      display: block;
      margin-bottom: 8px;
      opacity: .5;
    }

    .empty-st p {
      font-size: .8rem;
      font-weight: 600;
    }

    .no-profile {
      text-align: center;
      padding: 40px 24px;
      background: rgba(255, 255, 255, 0.75);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border-radius: 20px;
      box-shadow: var(--sh-md);
      border: 1px solid rgba(255, 255, 255, 0.6);
      max-width: 400px;
      margin: 60px auto;
      display: none;
      flex-direction: column;
      align-items: center;
      gap: 12px;
    }

    .no-profile i {
      font-size: 3rem;
      color: var(--brand);
      background: rgba(79, 70, 229, 0.12);
      width: 76px;
      height: 76px;
      border-radius: 50%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 8px;
      border: 1px solid rgba(79, 70, 229, 0.25);
    }

    .no-profile h2 {
      font-size: 1.25rem;
      font-weight: 800;
      color: var(--t1);
      margin: 0;
    }

    .no-profile p {
      font-size: 0.9rem;
      color: var(--t3);
      margin: 0 0 8px 0;
      line-height: 1.6;
    }

    .no-profile a.btn {
      text-decoration: none !important;
      width: 100%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      padding: 12px 24px;
      border-radius: 14px;
      font-weight: 700;
      border: none;
      box-shadow: var(--sh-brand);
      transition: all var(--fast);
      background: var(--brand) !important;
      color: #fff !important;
    }

    .no-profile a.btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 20px rgba(79, 70, 229, 0.4);
    }

    .public-banner {
      background: var(--brand-bg);
      border: 1px solid var(--brand-l);
      border-radius: var(--r-md);
      padding: 10px 14px;
      margin-bottom: 14px;
      display: flex;
      align-items: center;
      gap: 9px;
      font-size: .8rem;
      color: var(--brand);
      font-weight: 700;
    }

    .friend-banner {
      background: #fdf2f8;
      border: 1.5px solid #e879f9;
      border-radius: var(--r-md);
      padding: 10px 14px;
      margin-bottom: 14px;
      display: flex;
      align-items: center;
      gap: 9px;
      font-size: .8rem;
      color: #9333ea;
      font-weight: 700;
    }

    /* Search friends */
    .friend-search-bar {
      display: flex;
      align-items: center;
      gap: 8px;
      background: var(--surf);
      border: 1.5px solid var(--bdr);
      border-radius: var(--r-full);
      padding: 8px 14px;
      transition: var(--fast);
    }

    .friend-search-bar:focus-within {
      border-color: var(--cou-l);
      box-shadow: 0 0 0 3px rgba(167, 139, 250, .15);
    }

    .friend-search-bar input {
      flex: 1;
      border: none;
      outline: none;
      background: transparent;
      font-family: inherit;
      font-size: .88rem;
      color: var(--t1);
      -webkit-user-select: text;
      user-select: text;
    }

    .friend-search-bar input::placeholder {
      color: var(--t4);
    }

    .friend-result-card {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 14px;
      border-radius: var(--r-md);
      border: 1.5px solid var(--bdr);
      background: var(--surf);
      cursor: pointer;
      transition: var(--fast);
      margin-bottom: 8px;
    }

    .friend-result-card:hover {
      border-color: var(--cou-l);
      background: var(--cou-bg);
    }

    .friend-result-av {
      width: 46px;
      height: 46px;
      border-radius: 50%;
      flex-shrink: 0;
      background: linear-gradient(135deg, var(--cou-bg), #ede9fe);
      color: var(--cou);
      font-size: 1.1rem;
      font-weight: 800;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      border: 2px solid var(--bdr);
    }

    .friend-result-av img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .friend-result-info {
      flex: 1;
      min-width: 0;
    }

    .friend-result-name {
      font-size: .88rem;
      font-weight: 700;
      color: var(--t1);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .friend-result-meta {
      font-size: .72rem;
      color: var(--t3);
      margin-top: 2px;
    }

    .friend-result-cou {
      font-size: .78rem;
      font-weight: 700;
      color: var(--cou);
      white-space: nowrap;
    }

    /* ══ SETTINGS SHEET ═════════════════════════════════ */
    .settings-overlay {
      align-items: flex-end;
      padding: 0;
    }

    .settings-sheet {
      background: var(--surf);
      border-radius: 28px 28px 0 0;
      width: 100%;
      max-width: 520px;
      padding: 0 0 max(24px, env(safe-area-inset-bottom));
      box-shadow: 0 -8px 40px rgba(0, 0, 0, .14);
      transform: translateY(100%);
      transition: transform var(--slow);
      max-height: 90vh;
      overflow: hidden;
      overflow-x: hidden;
      will-change: transform;
      overscroll-behavior: contain;
    }

    .settings-sheet.ss-scrollable {
      overflow-y: auto;
      overflow-x: hidden;
    }

    .overlay.open .settings-sheet {
      transform: translateY(0);
    }

    .ss-handle {
      width: 38px;
      height: 4px;
      background: var(--t5);
      border-radius: var(--r-full);
      margin: 12px auto 0;
    }

    .ss-profile {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 18px 22px 16px;
      border-bottom: 1px solid var(--bdr2);
    }

    .ss-avatar {
      width: 52px;
      height: 52px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--brand-bg), #c7d2fe);
      color: var(--brand);
      font-size: 1.3rem;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      border: 2px solid var(--bdr);
      flex-shrink: 0;
    }

    .ss-avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .ss-name {
      font-size: 1rem;
      font-weight: 800;
      color: var(--t1);
    }

    .ss-class {
      font-size: .75rem;
      color: var(--t4);
      font-weight: 600;
      margin-top: 2px;
    }

    .ss-items {
      padding: 8px 14px;
    }

    .ss-item {
      display: flex;
      align-items: center;
      gap: 13px;
      padding: 13px 10px;
      border-radius: var(--r-md);
      cursor: pointer;
      transition: var(--fast);
    }

    .ss-item:hover {
      background: var(--s2);
    }

    .ss-item.danger .ss-item-label {
      color: var(--err);
    }

    .ss-item-ico {
      width: 38px;
      height: 38px;
      border-radius: var(--r-sm);
      flex-shrink: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: .92rem;
    }

    .ss-item-label {
      font-size: .92rem;
      font-weight: 700;
      color: var(--t1);
      flex: 1;
    }

    .ss-item-arr {
      font-size: .72rem;
      color: var(--t5);
    }

    .ss-divider {
      height: 8px;
      background: var(--s2);
      margin: 0;
    }

    .ss-close-btn {
      display: block;
      width: calc(100% - 32px);
      margin: 16px 16px 0;
      padding: 13px;
      border-radius: var(--r-md);
      background: var(--s2);
      border: 1.5px solid var(--bdr);
      font-family: 'Baloo Bhaijaan 2', sans-serif;
      font-size: .9rem;
      font-weight: 700;
      color: var(--t2);
      cursor: pointer;
      transition: var(--fast);
    }

    .ss-close-btn:hover {
      background: var(--bdr);
    }

    /* ══ UNCLE STRIP ══════════════════════════════════════ */
    .uncle-strip {
      display: inline-flex !important;
      align-items: center;
      gap: 7px;
      background: #fff;
      border: 1.5px solid rgba(99, 102, 241, .2);
      border-radius: var(--r-full);
      padding: 5px 12px 5px 7px;
      cursor: pointer;
      box-shadow: 0 2px 8px rgba(0, 0, 0, .10);
      transition: var(--fast);
    }

    .uncle-strip:hover {
      background: #f5f3ff;
      border-color: var(--cou-l);
    }

    .uncle-strip-label {
      font-size: .68rem;
      font-weight: 700;
      color: var(--cou);
      white-space: nowrap;
      letter-spacing: .01em;
    }

    .uncle-avatars {
      display: flex;
      align-items: center;
    }

    .ua {
      width: 24px;
      height: 24px;
      border-radius: 50%;
      border: 2px solid #fff;
      background: linear-gradient(135deg, #6366f1, #a78bfa);
      color: #fff;
      font-size: .58rem;
      font-weight: 800;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      margin-left: -6px;
      flex-shrink: 0;
      box-shadow: 0 1px 4px rgba(0, 0, 0, .18);
      transition: var(--fast);
    }

    .ua:first-child {
      margin-left: 0;
    }

    .ua img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .ua-more {
      font-size: .58rem;
      color: var(--cou);
      font-weight: 800;
      padding: 0 6px;
      border-radius: var(--r-full);
      height: 24px;
      display: flex;
      align-items: center;
      margin-left: 5px;
      border: 1.5px solid var(--cou-l);
      background: var(--cou-bg);
      white-space: nowrap;
      flex-shrink: 0;
    }

    /* ══ ATTENDANCE NARROWER ══════════════════════════════ */
    @media(max-width:480px) {
      .cal-day {
        padding: 7px 5px !important;
      }

      .cd-num {
        font-size: 1rem !important;
      }
    }

    /* ══ RESPONSIVE ══════════════════════════════════════ */
    @media(max-width:580px) {
      .hero #scInfo {
        margin: 12px auto 0 !important;
        padding: 10px 12px !important;
        border-radius: 16px !important;
      }
      .hero #scInfo .sc-body {
        padding: 0 !important;
      }
      .hero #scInfo .info-grid {
        gap: 6px !important;
      }
      .hero #scInfo .ip {
        padding: 6px 10px !important;
        gap: 8px !important;
        border-radius: 10px !important;
      }
      .hero #scInfo .ip-ico {
        width: 24px !important;
        height: 24px !important;
        font-size: 0.7rem !important;
      }
      .hero #scInfo .ip-lbl {
        font-size: 0.6rem !important;
        margin-bottom: 0 !important;
      }
      .hero #scInfo .ip-val {
        font-size: 0.78rem !important;
      }

      .coupon-hero {
        grid-template-columns: 1fr auto;
        padding: 12px 14px;
        gap: 12px;
      }

      .sc {
        border-radius: 22px;
      }

      .sc-head {
        padding: 16px 16px 12px;
      }

      .sc-body {
        padding: 15px;
      }

      .cal-grid {
        grid-template-columns: repeat(auto-fill, minmax(70px, 1fr));
      }

      .info-grid {
        grid-template-columns: 1fr 1fr;
      }

      .stats-bar {
        grid-template-columns: repeat(2, 1fr);
      }

      .sb-cell {
        border-left: none;
      }

      .sb-cell:nth-child(odd) {
        border-left: 1px solid var(--bdr);
      }

      .ann-summary {
        grid-template-columns: 1fr;
      }

      .ann-stat-grid {
        grid-template-columns: repeat(2, 1fr);
      }

      .sb-cell:nth-child(3) {
        border-top: 1px solid var(--bdr);
      }

      .sb-cell:nth-child(4) {
        border-top: 1px solid var(--bdr);
      }
    }

    @media(max-width:360px) {
      .info-grid {
        grid-template-columns: 1fr;
      }

      .hero-name {
        font-size: 1.4rem;
      }

      .ann-stat-grid {
        grid-template-columns: 1fr;
      }

      .ann-top,
      .ann-footer {
        align-items: flex-start;
        flex-direction: column;
      }
    }

    /* ══ UNCLE CARDS ═════════════════════════════════════ */
    .uncle-card {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
      cursor: pointer;
      padding: 12px 8px;
      border-radius: var(--r-lg);
      border: 1.5px solid var(--bdr);
      background: var(--surf);
      transition: var(--fast);
      text-align: center;
    }

    .uncle-card:hover {
      border-color: var(--cou-l);
      background: var(--cou-bg);
      transform: translateY(-2px);
      box-shadow: var(--sh-sm);
    }

    .uncle-card-av {
      width: 64px;
      height: 64px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--cou-bg), #ede9fe);
      color: var(--cou);
      font-size: 1.5rem;
      font-weight: 800;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      border: 2px solid var(--bdr);
      flex-shrink: 0;
      transition: var(--fast);
    }

    .uncle-card:hover .uncle-card-av {
      border-color: var(--cou-l);
    }

    .uncle-card-av img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .uncle-card-name {
      font-size: .75rem;
      font-weight: 700;
      color: var(--t1);
      line-height: 1.3;
      word-break: break-word;
    }

    .uncle-card-role {
      font-size: .65rem;
      color: var(--t4);
      font-weight: 600;
    }

    /* uncle drawer profile */
    .uncle-drawer-hero {
      background: linear-gradient(145deg, #4c1d95, #7c3aed);
      padding: 24px 22px 22px;
      display: flex;
      align-items: center;
      gap: 16px;
      border-radius: 28px 28px 0 0;
    }

    .uncle-drawer-av {
      width: 70px;
      height: 70px;
      border-radius: 50%;
      flex-shrink: 0;
      background: rgba(255, 255, 255, .18);
      border: 3px solid rgba(255, 255, 255, .4);
      overflow: hidden;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.8rem;
      color: #fff;
      font-weight: 800;
    }

    .uncle-drawer-av img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .uncle-drawer-name {
      font-size: 1.15rem;
      font-weight: 800;
      color: #fff;
      line-height: 1.25;
    }

    .uncle-drawer-role {
      font-size: .75rem;
      color: rgba(255, 255, 255, .75);
      margin-top: 3px;
      font-weight: 600;
    }

    .uncle-action-btn {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 14px 18px;
      border-radius: var(--r-md);
      border: 1.5px solid var(--bdr);
      background: var(--surf);
      cursor: pointer;
      transition: var(--fast);
      width: 100%;
      font-family: 'Baloo Bhaijaan 2', sans-serif;
      font-size: .93rem;
      font-weight: 700;
      color: var(--t1);
    }

    .uncle-action-btn:hover {
      background: var(--s2);
    }

    .uncle-action-btn.call {
      border-color: #6ee7b7;
    }

    .uncle-action-btn.call:hover {
      background: var(--ok-bg);
    }

    .uncle-action-btn.wa {
      border-color: #86efac;
    }

    .uncle-action-btn.wa:hover {
      background: #f0fdf4;
    }

    .uncle-action-ico {
      width: 40px;
      height: 40px;
      border-radius: var(--r-sm);
      flex-shrink: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.1rem;
    }

    /* ══ EXAM SCREEN ════════════════════════════════════════ */
    #examScreen {
      font-family: 'Baloo Bhaijaan 2', sans-serif;
    }

    /* ── Start card ── */
    .exam-start-card {
      background: var(--surf, #ffffff);
      border-radius: 20px;
      border: 1px solid var(--bdr);
      box-shadow: var(--sh-xl);
      overflow: hidden;
    }

    .exam-start-hero {
      background: var(--surf, #ffffff);
      padding: 32px 24px 20px;
      border-bottom: 1px solid var(--bdr);
      text-align: center;
      position: relative;
    }

    .exam-start-icon {
      position: relative;
      z-index: 1;
      width: 64px;
      height: 64px;
      border-radius: 50%;
      background: var(--brand-bg, rgba(79,70,229,0.1));
      color: var(--brand, #4f46e5);
      border: none;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 16px;
      font-size: 1.7rem;
    }

    .exam-start-title {
      position: relative;
      z-index: 1;
      font-size: 1.3rem;
      font-weight: 800;
      color: var(--t1);
      margin-bottom: 5px;
      line-height: 1.25;
    }

    .exam-start-sub {
      position: relative;
      z-index: 1;
      font-size: .88rem;
      color: var(--t3);
      font-weight: 600;
    }

    .exam-meta-row {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 13px 16px;
      border-radius: var(--r-md);
      font-weight: 700;
      font-size: .88rem;
    }

    .exam-meta-row i {
      font-size: 1.05rem;
      flex-shrink: 0;
    }

    .exam-meta-row .em-text {}

    .exam-meta-row .em-sub {
      font-size: .7rem;
      font-weight: 500;
      color: var(--t4);
      margin-top: 2px;
    }

    /* ── Active exam header ── */
    .exam-hdr {
      position: sticky;
      top: 0;
      z-index: 10;
      background: var(--surf);
      border-bottom: 1px solid var(--bdr);
      box-shadow: 0 2px 12px rgba(0, 0, 0, .06);
    }

    .exam-hdr-inner {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 16px;
    }

    .exam-back-btn {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      border: 1.5px solid var(--bdr);
      background: var(--s2);
      color: var(--t2);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      transition: var(--fast);
    }

    .exam-back-btn:hover {
      background: var(--brand-bg);
      border-color: var(--brand-l);
      color: var(--brand);
    }

    .exam-hdr-title {
      font-size: .92rem;
      font-weight: 800;
      color: var(--t1);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      flex: 1;
    }

    .exam-hdr-sub {
      font-size: .67rem;
      color: var(--t4);
      margin-top: 1px;
    }

    .exam-timer {
      display: none;
      padding: 5px 13px;
      border-radius: var(--r-full);
      font-size: .88rem;
      font-weight: 800;
      white-space: nowrap;
      flex-shrink: 0;
      background: var(--warn-bg);
      border: 1.5px solid #fde68a;
      color: var(--warn);
      font-family: 'Baloo Bhaijaan 2', sans-serif;
      transition: background var(--fast), color var(--fast), border-color var(--fast);
    }

    .exam-timer.urgent {
      background: var(--err-bg);
      border-color: #fca5a5;
      color: var(--err);
    }

    .exam-prog-track {
      height: 3px;
      background: var(--bdr);
    }

    .exam-prog-fill {
      height: 100%;
      background: linear-gradient(90deg, var(--brand), var(--cou-l));
      transition: width .35s var(--ease);
      width: 0%;
    }

    /* ── Questions area ── */
    .exam-questions {
      padding: 18px 16px 130px;
      max-width: 720px;
      margin: 0 auto;
      width: 100%;
    }

    /* ── Sticky submit footer ── */
    .exam-footer {
      position: sticky;
      bottom: 0;
      z-index: 10;
      background: rgba(255, 255, 255, .95);
      backdrop-filter: blur(14px);
      border-top: 1px solid var(--bdr);
      padding: 10px 16px max(10px, env(safe-area-inset-bottom));
      box-shadow: 0 -4px 20px rgba(0, 0, 0, .07);
      margin-top: auto;
    }

    .exam-footer-inner {
      max-width: 720px;
      margin: 0 auto;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .exam-ans-count {
      flex: 1;
      font-size: .8rem;
      color: var(--t4);
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 5px;
    }

    .exam-ans-count strong {
      color: var(--t1);
      font-size: .95rem;
    }

    /* ── Result card ── */
    .exam-result-wrap {
      width: 100%;
      max-width: 440px;
    }

    .exam-result-card {
      border-radius: var(--r-xl);
      overflow: hidden;
      box-shadow: var(--sh-lg);
      border: 1px solid var(--bdr);
      margin-bottom: 14px;
    }

    .exam-result-hero {
      padding: 36px 24px 28px;
      text-align: center;
      position: relative;
      overflow: hidden;
    }

    .exam-result-icon-ring {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 16px;
      font-size: 2rem;
      border: 3px solid rgba(255, 255, 255, .4);
      background: rgba(255, 255, 255, .2);
      backdrop-filter: blur(8px);
    }

    .exam-result-score-big {
      font-size: 3.4rem;
      font-weight: 800;
      color: #fff;
      line-height: 1;
      text-shadow: 0 2px 16px rgba(0, 0, 0, .2);
    }

    .exam-result-pct {
      font-size: 1rem;
      color: rgba(255, 255, 255, .85);
      margin-top: 4px;
      font-weight: 700;
    }

    .exam-result-body {
      padding: 20px 22px;
    }

    .exam-result-msg {
      font-size: 1rem;
      font-weight: 700;
      color: var(--t1);
      text-align: center;
      margin-bottom: 14px;
      line-height: 1.5;
    }

    .exam-result-coupons {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 11px 20px;
      border-radius: var(--r-full);
      background: linear-gradient(135deg, var(--cou-l), var(--cou));
      color: #fff;
      font-weight: 800;
      font-size: .95rem;
      box-shadow: 0 4px 14px rgba(124, 58, 237, .35);
    }

    .birthday-greeting-btn {
      display: none;
      align-items: center;
      justify-content: center;
      gap: 8px;
      margin-top: 12px;
      padding: 8px 16px;
      border-radius: var(--r-full);
      border: none;
      background: rgba(255, 255, 255, .18);
      color: #fff;
      font-family: inherit;
      font-size: .82rem;
      font-weight: 800;
      cursor: pointer;
      box-shadow: 0 10px 28px rgba(15, 23, 42, .18);
      backdrop-filter: blur(10px);
      transition: var(--fast);
    }

    .birthday-greeting-btn.show {
      display: inline-flex;
    }

    .birthday-greeting-btn:hover {
      background: rgba(255, 255, 255, .28);
      transform: translateY(-1px);
    }

    .birthday-card-preview {
      width: min(100%, 360px);
      aspect-ratio: 4 / 5;
      border-radius: var(--r-lg);
      overflow: hidden;
      background: var(--s2);
      border: 1px solid var(--bdr);
      box-shadow: var(--sh-lg);
      margin: 0 auto;
    }

    .birthday-card-preview canvas {
      display: block;
      width: 100%;
      height: 100%;
    }

    .birthday-actions {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
      padding: 14px 22px 0;
    }

    /* ══ BOTTOM NAVIGATION ══════════════════════════════════════ */
    .bottom-nav {
      position: fixed;
      bottom: 0;
      left: 0;
      right: 0;
      height: 64px;
      background: rgba(255, 255, 255, 0.96);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border-top: 1.5px solid var(--bdr2);
      display: flex;
      justify-content: space-around;
      align-items: center;
      z-index: 490;
      padding-bottom: env(safe-area-inset-bottom);
      box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.04);
    }

    .bottom-nav-item {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      color: var(--t3);
      font-size: 0.68rem;
      font-weight: 800;
      text-decoration: none;
      cursor: pointer;
      transition: all var(--fast);
      flex: 1;
      height: 100%;
      gap: 3px;
    }

    .bottom-nav-item i {
      font-size: 1.2rem;
      transition: transform var(--fast);
    }

    .bottom-nav-item.active {
      color: var(--brand);
    }

    .bottom-nav-item.active i {
      transform: translateY(-2px);
    }

    .bottom-nav-item.center-fab {
      position: relative;
      height: fit-content;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: flex-end;
      padding-bottom: 24px;
    }

    .fab-btn {
      width: 63px;
      height: 58px;
      border-radius: var(--r-full);
      background: linear-gradient(135deg, var(--brand) 0%, var(--cou) 100%);
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 6px 16px rgba(79, 70, 229, 0.3);
      transform: translateY(-3px);
      transition: all var(--fast);
      border: none;
    }

    .fab-btn i {
      font-size: 1.55rem;
      color: #fff;
    }

    .bottom-nav-item.center-fab:hover .fab-btn {
      transform: translateY(-12px) scale(1.05);
      box-shadow: 0 8px 20px rgba(79, 70, 229, 0.4);
    }

    /* ══ SIBLINGS & TABS CUSTOM STYLES ══════════════════════════ */
    .sibling-card:hover {
      border-color: var(--brand) !important;
      transform: translateY(-2px);
      box-shadow: var(--sh-md) !important;
    }

    .send-cat-btn {
      background: var(--s2);
      border: 1.5px solid var(--bdr);
      border-radius: var(--r-sm);
      padding: 8px 4px;
      font-size: .75rem;
      font-weight: 800;
      color: var(--t2);
      cursor: pointer;
      font-family: inherit;
      transition: all var(--fast);
      outline: none;
    }

    .send-cat-btn.active {
      background: var(--brand);
      color: #fff;
      border-color: var(--brand);
    }

    .send-recipient-chip {
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 8px 12px;
      background: var(--s2);
      border: 1.5px solid var(--bdr);
      border-radius: var(--r-md);
      font-size: .75rem;
      font-weight: 800;
      cursor: pointer;
      min-width: 80px;
      flex-shrink: 0;
      gap: 4px;
      transition: all var(--fast);
    }

    .send-recipient-chip.active {
      border-color: var(--brand);
      background: var(--brand-bg);
      color: var(--brand);
    }

    /* ══ V3 TABS AND SEARCH STYLE ══════════════════════════════ */
    .send-coupons-page {
      background: var(--surf);
      position: relative;
      overflow: hidden;
      color: var(--t1);
      padding: 24px 20px 80px !important;
    }

    .send-coupons-page::before {
      content: '';
      position: absolute;
      inset: 0;
      background:
        radial-gradient(circle at 20% 30%, rgba(99, 102, 241, .03) 0%, transparent 40%),
        radial-gradient(circle at 80% 70%, rgba(99, 102, 241, .02) 0%, transparent 35%);
      animation: hero-pulse 6s ease-in-out infinite;
      pointer-events: none;
      z-index: 0;
    }

    .send-coupons-page>* {
      position: relative;
      z-index: 1;
    }



    .wizard-step-container {
      background: var(--bg);
      border: 1.5px solid var(--bdr2);
      border-radius: var(--radius-lg);
      padding: 20px 16px;
      margin-top: 14px;
      box-shadow: var(--sh-sm);
    }

    .wizard-step-title {
      font-size: 1.05rem;
      font-weight: 800;
      color: var(--t1);
      margin-bottom: 12px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .wizard-btn-row {
      display: flex;
      gap: 10px;
      margin-top: 18px;
    }

    .send-wizard-input {
      width: 100%;
      padding: 12px;
      border: 1.5px solid var(--bdr);
      background: var(--surf);
      border-radius: var(--r-sm);
      color: var(--t1);
      font-family: inherit;
      font-size: 1rem;
      text-align: center;
      outline: none;
      transition: all var(--fast);
    }

    .send-wizard-input::placeholder {
      color: var(--t4);
    }

    .send-wizard-input:focus {
      border-color: var(--brand);
      background: var(--surf);
      box-shadow: 0 0 0 3px var(--brand-glow);
    }

    /* Sleek Home Search Bar */
    .home-search-bar input {
      border: 2px solid var(--bdr) !important;
      box-shadow: var(--sh-sm) !important;
    }

    .home-search-bar input:focus {
      border-color: var(--brand) !important;
      box-shadow: 0 0 0 4px var(--brand-glow) !important;
    }

    /* Step progress dots */
    .wizard-dots {
      display: flex;
      justify-content: center;
      gap: 6px;
      margin-bottom: 12px;
    }

    .wizard-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: var(--bdr);
      transition: all var(--fast);
    }

    .wizard-dot.active {
      background: var(--brand);
      transform: scale(1.2);
    }

    /* Toggle Switch Styling */
    .switch-toggle {
      position: relative;
      display: inline-block;
      width: 46px;
      height: 24px;
    }

    .switch-toggle input {
      opacity: 0;
      width: 0;
      height: 0;
    }

    .slider-toggle {
      position: absolute;
      cursor: pointer;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background-color: var(--bdr);
      transition: .3s;
      border-radius: 24px;
    }

    .slider-toggle:before {
      position: absolute;
      content: "";
      height: 18px;
      width: 18px;
      right: 3px;
      bottom: 3px;
      background-color: white;
      transition: .3s;
      border-radius: 50%;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
    }

    .switch-toggle input:checked+.slider-toggle {
      background-color: var(--brand);
    }

    .switch-toggle input:checked+.slider-toggle:before {
      transform: translateX(-22px);
      /* RTL slide direction */
    }

    /* ── PWA install modal ── */
    #pwaInstallModal {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .5);
      backdrop-filter: blur(8px);
      z-index: 9999997;
      display: none;
      align-items: flex-end;
      justify-content: center;
      font-family: inherit;
    }

    #pwaInstallModal.show {
      display: flex;
    }

    .pwa-install-sheet {
      background: var(--surface, #fff);
      border-radius: var(--r-2xl, 24px) var(--r-2xl, 24px) 0 0;
      padding: 24px 20px 36px;
      width: 100%;
      max-width: 480px;
      box-shadow: 0 -8px 40px rgba(0, 0, 0, .25);
      animation: sheetUp .35s ease-out;
      text-align: center;
      box-sizing: border-box;
    }

    .pwa-icon-big {
      width: 84px;
      height: 84px;
      border-radius: 22px;
      overflow: hidden;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 16px;
      background: linear-gradient(135deg, #5b6cf5, #8b5cf6);
      box-shadow:
        0 0 0 6px rgba(181, 190, 248, .22),
        0 0 0 12px rgba(181, 190, 248, .08),
        0 8px 28px rgba(181, 190, 248, .50);
      animation: pwaIconPulse 3s ease-in-out infinite;
    }

    @keyframes pwaIconPulse {

      0%,
      100% {
        box-shadow: 0 0 0 6px rgba(181, 190, 248, .25), 0 0 0 12px rgba(181, 190, 248, .10), 0 8px 28px rgba(181, 190, 248, .45);
      }

      50% {
        box-shadow: 0 0 0 9px rgba(181, 190, 248, .30), 0 0 0 18px rgba(181, 190, 248, .08), 0 8px 36px rgba(181, 190, 248, .60);
      }
    }

    .pwa-steps {
      background: var(--surface-3, #f3f4f6);
      border-radius: var(--r-lg, 16px);
      padding: 14px;
      margin: 16px 0;
      text-align: right;
    }

    .pwa-step {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      padding: 6px 0;
      font-size: .84rem;
      color: var(--text-2, #4b5563);
    }

    .pwa-step-num {
      width: 22px;
      height: 22px;
      border-radius: 50%;
      background: var(--brand, #5b6cf5);
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: .7rem;
      font-weight: 800;
      flex-shrink: 0;
      margin-top: 1px;
    }

    .pwa-btn {
      width: 100%;
      height: 44px;
      border-radius: var(--r-full, 9999px);
      font-weight: 800;
      font-size: 0.9rem;
      border: none;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: all 0.2s ease;
    }

    .pwa-btn-primary {
      background: var(--brand, #5b6cf5);
      color: #fff;
    }

    .pwa-btn-primary:hover {
      opacity: 0.9;
      transform: scale(1.02);
    }

    .pwa-btn-ghost {
      background: transparent;
      color: var(--text-3, #888);
    }

    .pwa-btn-ghost:hover {
      background: var(--surface-3, #f3f4f6);
    }

    @keyframes sheetUp {
      from {
        transform: translateY(100%);
      }

      to {
        transform: translateY(0);
      }
    }

    /* Glassmorphic styling for info inside hero (mobile & desktop) */
    .hero #scInfo {
      background: rgba(255, 255, 255, 0.1) !important;
      backdrop-filter: blur(14px) !important;
      border: 1px solid rgba(255, 255, 255, 0.22) !important;
      border-radius: var(--r-xl) !important;
      box-shadow: none !important;
      margin: 20px auto 0 !important;
      width: calc(100% - 32px) !important;
      box-sizing: border-box !important;
      padding: 16px 20px !important;
    }
    
    .hero #scInfo .sc-head {
      margin-bottom: 12px !important;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important;
      padding-bottom: 8px !important;
      display: flex !important;
      align-items: center !important;
      gap: 10px !important;
    }
    
    .hero #scInfo .sc-ico {
      background: rgba(255, 255, 255, 0.15) !important;
      color: #fff !important;
      width: 30px !important;
      height: 30px !important;
      border-radius: var(--r-xs) !important;
      display: flex !important;
      align-items: center !important;
      justify-content: center !important;
      font-size: .85rem !important;
    }
    
    .hero #scInfo .sc-title {
      color: #fff !important;
      font-weight: 800 !important;
      font-size: 0.88rem !important;
    }
    
    .hero #scInfo .ip {
      background: rgba(255, 255, 255, 0.08) !important;
      border: 1px solid rgba(255, 255, 255, 0.12) !important;
      transition: all var(--fast) !important;
    }
    
    .hero #scInfo .ip:hover {
      background: rgba(255, 255, 255, 0.16) !important;
      border-color: rgba(255, 255, 255, 0.25) !important;
      transform: translateY(-1px) !important;
    }
    
    .hero #scInfo .ip-ico {
      background: rgba(255, 255, 255, 0.15) !important;
      color: #fff !important;
    }
    
    .hero #scInfo .ip-lbl {
      color: rgba(255, 255, 255, 0.75) !important;
    }
    
    .hero #scInfo .ip-val {
      color: #fff !important;
    }

    .sidebar-desktop,
    .main-content-desktop {
      display: contents;
    }

    @media (min-width: 900px) {
      .sidebar-desktop {
        grid-column: 1;
        grid-row: 1 / 3;
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        background: linear-gradient(145deg, #312e81 0%, #4f46e5 35%, #7c3aed 70%, #5b21b6 100%) !important;
        border: 1px solid rgba(255, 255, 255, 0.15) !important;
        border-radius: var(--r-xl) !important;
        position: sticky !important;
        top: 24px !important;
        height: fit-content !important;
        margin: 24px 24px 24px 12px !important;
        overflow-y: auto;
        z-index: 500;
        box-sizing: border-box !important;
        box-shadow: 0 12px 32px rgba(49, 46, 129, 0.25) !important;
        animation: sidebarSlideIn 0.5s var(--spring) both;
      }

      /* ── Desktop entrance animations ── */
      @keyframes sidebarSlideIn {
        from { transform: translateX(40px); opacity: 0; }
        to   { transform: translateX(0);   opacity: 1; }
      }
      @keyframes fadeUp {
        from { transform: translateY(18px); opacity: 0; }
        to   { transform: translateY(0);    opacity: 1; }
      }
      @keyframes fadeIn {
        from { opacity: 0; }
        to   { opacity: 1; }
      }

      /* Main side-by-side grid layout */
      body {
        display: grid;
        grid-template-columns: 380px minmax(auto, 1000px);
        grid-template-rows: auto 1fr;
        justify-content: center;
        min-height: 100vh;
        background: var(--bg);
        direction: rtl;
        margin: 0;
      }

      /* Hero panel inside sticky desktop sidebar */
      .hero {
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: center !important;
        text-align: center !important;
        background: transparent !important;
        border: none !important;
        padding: 40px 28px 30px !important;
        height: auto !important;
        min-height: auto !important;
        box-shadow: none !important;
        border-radius: 0 !important;
        position: relative !important;
        width: 100% !important;
        box-sizing: border-box !important;
        animation: fadeIn 0.6s var(--ease) 0.15s both;
      }

      /* Stagger sidebar children for polish */
      .avatar-ring    { animation: fadeUp 0.5s var(--spring) 0.2s  both !important; }
      .hero-name      { animation: fadeUp 0.5s var(--spring) 0.3s  both !important; }
      .hero-tags      { animation: fadeUp 0.5s var(--spring) 0.35s both !important; }
      #scInfo         { animation: fadeUp 0.5s var(--spring) 0.4s  both !important; }
      .coupon-hero    { animation: fadeUp 0.5s var(--spring) 0.45s both !important; }

      /* Always show personal info in sidebar on desktop view */
      .hero #scInfo {
        display: block !important;
      }

      /* Adjust text colors in sidebar profile */
      .hero-name {
        color: #fff !important;
        font-size: 1.15rem !important;
        margin-top: 12px !important;
        text-shadow: 0 2px 10px rgba(0, 0, 0, .2) !important;
      }

      /* Converted Bottom Navigation Sidebar inside desktop main-content-desktop wrapper */
      .bottom-nav {
        position: relative !important;
        height: auto !important;
        width: auto !important;
        box-sizing: border-box !important;
        background: var(--surf) !important;
        border: 1px solid var(--bdr2) !important;
        box-shadow: var(--sh-sm) !important;
        display: flex !important;
        flex-direction: row !important;
        justify-content: center !important;
        align-items: center !important;
        padding: 6px !important;
        border-radius: var(--r-full) !important;
        margin: 0 auto 30px auto !important;
        gap: 6px !important;
        z-index: 10 !important;
        animation: fadeUp 0.5s var(--spring) 0.1s both;
      }

      .bottom-nav-item {
        flex-direction: row !important;
        justify-content: center !important;
        align-items: center !important;
        gap: 8px !important;
        padding: 10px 20px !important;
        border-radius: var(--r-full) !important;
        height: auto !important;
        flex: 0 0 auto !important;
        margin-bottom: 0 !important;
        font-size: 0.95rem !important;
        color: var(--t3) !important;
        transition: all var(--fast) !important;
        background: transparent !important;
        cursor: pointer !important;
      }

      .bottom-nav-item i {
        font-size: 1.15rem !important;
        transform: none !important;
      }

      .bottom-nav-item:hover:not(.active) {
        background: var(--s2) !important;
        color: var(--brand) !important;
      }

      .bottom-nav-item.active {
        background: var(--brand) !important;
        color: #fff !important;
        box-shadow: var(--sh-brand) !important;
      }

      .bottom-nav-item.active i {
        transform: none !important;
        color: #fff !important;
      }

      /* Send tab: hide the fab-btn wrapper on desktop — use normal pill styling */
      .bottom-nav-item.center-fab {
        padding: 10px 20px !important;
        padding-bottom: 10px !important;
        order: 0 !important;
        flex-direction: row !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 8px !important;
        position: static !important;
      }

      /* Hide the circular fab-btn wrapper, show its icon inline */
      .fab-btn {
        display: contents !important;
        width: auto !important;
        height: auto !important;
        background: none !important;
        box-shadow: none !important;
        border-radius: 0 !important;
        transform: none !important;
        color: inherit !important;
      }

      .fab-btn i {
        font-size: 1.15rem !important;
        color: inherit !important;
      }

      /* Show the إرسال label next to the icon */
      .bottom-nav-item.center-fab > span {
        display: inline !important;
        font-size: 0.95rem !important;
        font-weight: 700 !important;
      }

      .bottom-nav-item.center-fab:hover .fab-btn {
        transform: none !important;
      }

      /* Main Content Desktop Wrapper */
      .main-content-desktop {
        grid-column: 2;
        grid-row: 1 / 3;
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        width: 100% !important;
        box-sizing: border-box !important;
        padding: 40px 12px 80px 24px !important;
        max-width: 1000px !important;
        margin: 0 !important;
        min-height: 100vh !important;
        animation: fadeIn 0.5s var(--ease) 0.05s both;
      }

      /* Main Page Content Container */
      .page {
        width: 100% !important;
        max-width: 960px !important;
        padding: 0 !important;
        margin: 0 !important;
        box-sizing: border-box !important;
        flex: 1 !important;
        display: flex !important;
        flex-direction: column !important;
      }

      /* Stats bar placed horizontally at the top of page content */
      .stats-bar {
        width: 100% !important;
        max-width: 960px !important;
        margin: 0 0 24px 0 !important;
        box-shadow: var(--sh-sm) !important;
        border-radius: var(--r-lg) !important;
        background: var(--surf) !important;
        border: 1px solid var(--bdr2) !important;
        align-self: stretch !important;
        grid-template-columns: repeat(4, 1fr) !important;
        position: relative !important;
      }

      /* Desktop hero-top elements stacked vertically to prevent clipping */
      .hero-top {
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        gap: 12px !important;
        width: 100% !important;
        box-sizing: border-box !important;
        padding: 0 16px !important;
      }

      .hero-church-chip {
        background: var(--brand-bg) !important;
        color: var(--brand) !important;
        border: 1px solid rgba(79, 70, 229, 0.15) !important;
        max-width: none !important;
        width: fit-content !important;
      }

      /* Hide app download floating / top buttons and place in profile */
      .hero-actions-top {
        margin-top: 0 !important;
        gap: 12px !important;
      }
       .hero-ico-btn {
        background: rgba(255, 255, 255, .15) !important;
        color: #fff !important;
        border: none !important;
      }
      .hero-ico-btn:hover {
        background: rgba(255, 255, 255, .26) !important;
        color: #fff !important;
      }

      /* Desktop coupon-hero: use horizontal grid layout same as mobile */
      .coupon-hero {
        background: rgba(255, 255, 255, .1) !important;
        backdrop-filter: blur(14px) !important;
        border: 1px solid rgba(255, 255, 255, .22) !important;
        box-shadow: none !important;
        width: calc(100% - 32px) !important;
        margin: 20px auto 0 !important;
        border-radius: var(--r-lg) !important;
        display: grid !important;
        grid-template-columns: 1fr auto !important;
        align-items: center !important;
        text-align: right !important;
        gap: 16px !important;
        padding: 18px 20px 16px !important;
      }
      .ch-breakdown {
        display: flex !important;
        flex-direction: column !important;
        gap: 5px !important;
        align-items: flex-start !important;
      }
      .ch-total-label {
        color: rgba(255, 255, 255, 0.85) !important;
      }
      .ch-total-val {
        color: #fff !important;
      }
      .ch-total-unit {
        color: rgba(255, 255, 255, 0.9) !important;
      }
      .ch-row {
        background: rgba(255, 255, 255, 0.15) !important;
        color: #fff !important;
      }
    }
  </style>
  <script src="/js/og-meta.js"></script>
</head>

<body>

  <div class="loading-screen" id="ls">
    <div class="spin"></div><span id="lt">جارٍ التحميل…</span>
  </div>

  <div id="tempIdAssignContainer" style="display:none; padding:20px; max-width:600px; margin:0 auto; direction:rtl; text-align:right; font-family:'Baloo Bhaijaan 2', sans-serif;"></div>

  <!-- ══ SIDEBAR (DESKTOP) ══ -->
  <aside class="sidebar-desktop" id="sidebarDesktop">
    <!-- ══ HERO ══ -->
    <div class="hero" id="hero" style="display:none">
      <div class="hero-top">
        <div class="hero-church-chip" id="churchChip" style="display:none">
          <i class="fas fa-church"></i><span id="churchName"></span>
        </div>
        <div class="hero-actions-top">
          <div class="hero-ico-btn" id="topbarDownloadBtn" style="display:none" onclick="triggerPwaInstall()"
            title="تنزيل التطبيق"><i class="fas fa-download"></i></div>
          <div class="hero-ico-btn" id="notifBtnTop" style="display:none; position:relative;" onclick="openOv('notifOv')"
            title="الإشعارات">
            <i class="fas fa-bell"></i>
            <span id="notifBadgeTop"
              style="display:none; position:absolute; top:-4px; right:-4px; min-width:16px; height:16px; padding:0 4px; background:var(--err); color:#fff; font-size:.62rem; font-weight:800; border-radius:10px; display:flex; align-items:center; justify-content:center; border:1.5px solid #fff; box-sizing:border-box;">0</span>
          </div>
          <div class="hero-ico-btn" id="switchBtnTop" style="display:none" onclick="openOv('switchOv')"
            title="تبديل الحساب"><i class="fas fa-exchange-alt"></i></div>
          <div class="hero-ico-btn" id="settingsTop" style="display:none" onclick="openOv('settingsOv')"
            title="الإعدادات"><i class="fas fa-cog"></i></div>
        </div>
      </div>
      <div class="hero-body">
        <div style="position:relative; display:inline-block;">
          <div class="avatar-ring" id="avatarRing">
            <div class="avatar-inner" id="avatarInner"><i class="fas fa-user"></i></div>
            <div class="avatar-edit-fab" id="avatarEdit" onclick="openOv('photoOv')"><i class="fas fa-camera"></i></div>
          </div>
          <button type="button" id="deleteStudentPhotoBtn" onclick="deleteStudentPhoto(event)" style="display:none; position:absolute; top:-4px; right:-4px; background:var(--err); color:white; border:none; border-radius:50%; width:28px; height:28px; cursor:pointer; align-items:center; justify-content:center; box-shadow:0 2px 5px rgba(0,0,0,0.2); z-index:10;"><i class="fas fa-trash-alt" style="font-size:0.8rem;"></i></button>
        </div>
        <div class="hero-name" id="heroName">—</div>
        <div class="hero-tags" id="heroTags">
          <span class="htag class-tag" id="heroClass"><i class="fas fa-graduation-cap"></i><span
              id="heroClassTxt">—</span></span>
          <button type="button" class="htag switch-tag" id="heroSwitchTag" style="display:none" onclick="openOv('switchOv')" title="تبديل الحساب">
            <i class="fas fa-exchange-alt"></i><span>تبديل الحساب (<span id="heroSwitchCount">0</span>)</span>
          </button>
          <div class="uncle-strip" id="uncleStrip" style="display:none"></div>
        </div>
        <button class="birthday-greeting-btn" id="birthdayGreetingBtn" type="button" onclick="openBirthdayGreeting()">
          <i class="fas fa-cake-candles"></i>
          <span>صورة عيد الميلاد</span>
        </button>
      </div>
      <!-- Info -->
      <div class="sc" id="scInfo">
        <div class="sc-body">
          <div class="info-grid" id="infoGrid"></div>
        </div>
      </div>

      <!-- Coupon hero card (private only) -->
      <div class="coupon-hero" id="couponHero" style="display:none">
        <div>
          <div class="ch-total-label"><i class="fas fa-star"></i> <span>إجمالي كوبوناتك</span></div>
          <div class="ch-total-val" id="chTotal">0</div>
          <div class="ch-total-unit">كوبون</div>
        </div>
        <div class="ch-breakdown" id="chBreakdown"></div>
      </div>
      <div class="hero-wave"></div>
    </div>

  </aside>

  <div class="main-content-desktop" id="mainContentDesktop">
    <!-- Converted bottom-nav placed inside main-content-desktop -->
    <nav class="bottom-nav" id="bottomNavBar" style="display:none;">
      <div class="bottom-nav-item active" data-tab="home" onclick="switchTab('home')">
        <i class="fas fa-home"></i>
        <span>الرئيسية</span>
      </div>
      <div class="bottom-nav-item" data-tab="attendance" onclick="switchTab('attendance')">
        <i class="fas fa-calendar-check"></i>
        <span>الحضور</span>
      </div>
      <div class="bottom-nav-item center-fab" data-tab="send" onclick="switchTab('send')">
        <div class="fab-btn">
          <i class="fas fa-star"></i>
        </div>
        <span>إرسال</span>
      </div>
      <div class="bottom-nav-item" data-tab="tasks" onclick="switchTab('tasks')" style="position: relative;">
        <i class="fas fa-tasks"></i>
        <span>التاسكات</span>
        <span id="tasksBadge"
          style="display:none; position:absolute; top:6px; right:calc(50% - 18px); width:8px; height:8px; background:var(--err); border-radius:50%; border:1px solid #fff;"></span>
      </div>
      <div class="bottom-nav-item" data-tab="family" onclick="switchTab('family')">
        <i class="fas fa-comments"></i>
        <span>التواصل</span>
      </div>
    </nav>

    <!-- stats bar -->
    <div class="stats-bar" id="statsBar" style="display:none">
    <div class="sb-cell ok">
      <div style="display:flex; align-items:center; justify-content:center; gap:6px;">
        <i class="fas fa-check-circle" style="font-size:1.05rem; color:var(--ok-l);"></i>
        <div class="sb-val" id="sbP">0</div>
      </div>
      <div class="sb-lbl">حضر</div>
    </div>
    <div class="sb-cell err">
      <div style="display:flex; align-items:center; justify-content:center; gap:6px;">
        <i class="fas fa-times-circle" style="font-size:1.05rem; color:var(--err-l);"></i>
        <div class="sb-val" id="sbA">0</div>
      </div>
      <div class="sb-lbl">غاب</div>
    </div>
    <div class="sb-cell neu">
      <div style="display:flex; align-items:center; justify-content:center; gap:6px;">
        <i class="fas fa-chart-line" style="font-size:1.05rem; color:var(--brand-l);"></i>
        <div class="sb-val" id="sbR">0%</div>
      </div>
      <div class="sb-lbl">نسبة الحضور</div>
    </div>
    <div class="sb-cell cou">
      <div style="display:flex; align-items:center; justify-content:center; gap:6px;">
        <i class="fas fa-star" style="font-size:1.05rem; color:var(--cou-l);"></i>
        <div class="sb-val" id="sbC">0</div>
      </div>
      <div class="sb-lbl">كوبونات</div>
    </div>
  </div>

  <!-- Page -->
  <div class="page" id="mainPage" style="display:none">

    <!-- Guest Login Prompt -->
    <div id="guestLoginPrompt" style="display:none; text-align:center; padding:40px 20px; background:rgba(255,255,255,0.85); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); border:2px dashed var(--brand-l); border-radius:var(--r-md); margin:20px 0; box-shadow:var(--sh-md); font-family:'Cairo','Baloo Bhaijaan 2',sans-serif;">
        <div style="background:var(--brand-bg); color:var(--brand); width:70px; height:70px; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 18px; font-size:2rem; box-shadow:var(--brand-glow);">
            <i class="fas fa-lock"></i>
        </div>
        <h3 style="margin-bottom:10px; font-weight:800; font-size:1.15rem; color:var(--t1);">تفاصيل هذا الملف الشخصي محمية</h3>
        <p style="margin-bottom:24px; font-size:0.85rem; color:var(--t3); line-height:1.6; max-width:400px; margin-left:auto; margin-right:auto;">
            عذراً، هذه البيانات تظهر فقط للأعضاء المسجلين في مدارس الأحد. يرجى تسجيل الدخول للتحقق من تفاصيل الطفل.
        </p>
        <a id="guestLoginBtn" href="/user/login" class="btn" style="display:inline-flex; align-items:center; gap:8px; justify-content:center; padding:12px 32px; background:var(--brand); color:#fff; border-radius:var(--r-sm); text-decoration:none; font-weight:700; box-shadow:var(--sh-brand); font-family:inherit; transition:transform var(--fast);">
            <i class="fas fa-sign-in-alt"></i> تسجيل الدخول للمتابعة
        </a>
    </div>

    <!-- Profile picture suggestion banner -->
    <div id="profilePicSuggestionBanner" style="display:none; background: linear-gradient(135deg, var(--brand-bg), rgba(79, 70, 229, 0.15)); border: 1px solid var(--brand); padding: 12px 16px; border-radius: 12px; margin-bottom: 12px; align-items: center; justify-content: space-between; gap: 12px; direction: rtl; text-align: right; position: relative;">
        <div style="display:flex; align-items:center; gap:10px; min-width:0; flex:1;">
            <div style="background:var(--brand); color:#fff; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <i class="fas fa-camera"></i>
            </div>
            <div style="min-width:0; flex:1;">
                <h4 style="margin:0 0 2px 0; font-size:0.9rem; font-weight:800; color:var(--t1); font-family: 'Baloo Bhaijaan 2', sans-serif;">أضف صورتك الشخصية</h4>
                <p style="margin:0; font-size:0.78rem; color:var(--t3); line-height:1.3; font-family: 'Baloo Bhaijaan 2', sans-serif; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">اضغط هنا لتحديث صورتك</p>
            </div>
        </div>
        <div style="display:flex; align-items:center; gap:8px; flex-shrink:0;">
            <button onclick="openOv('photoOv')" style="font-size:0.75rem; font-weight:800; padding:6px 12px; background:var(--brand); color:#fff; border:none; border-radius:8px; cursor:pointer; font-family: 'Baloo Bhaijaan 2', sans-serif;">إضافة الآن</button>
            <button onclick="dismissProfilePicSuggestion()" style="background:none; border:none; color:var(--t3); cursor:pointer; padding:4px 6px; font-size:0.9rem;" title="إغلاق"><i class="fas fa-times"></i></button>
        </div>
    </div>

    <!-- Announcement notification banner -->
    <div class="ann-banner-wrap" id="scAnnBanner" style="display:none;">
      <div
        style="padding: 12px 16px; background:var(--warn-bg); border: 1.5px solid var(--warn-l); border-radius: var(--r-md); display: flex; align-items: flex-start; gap: 10px; color: var(--warn); direction: rtl; box-shadow: var(--sh-sm);">
        <div style="font-size: 1.25rem; color: var(--warn-l); flex-shrink: 0; margin-top: 2px;"><i
            class="fas fa-bell"></i></div>
        <div style="flex: 1; min-width: 0;">
          <div style="font-weight: 800; font-size: 0.82rem; margin-bottom: 2px; color:var(--warn);">إشعار هام:</div>
          <div id="annBannerList" style="font-size: 0.85rem; line-height: 1.4; color: var(--t1);"></div>
        </div>
        <button onclick="dismissAnnBanner()"
          style="background:none; border:none; color:var(--warn); opacity: 0.7; cursor:pointer; font-size:1rem; display:flex; align-items:center; justify-content:center; padding: 2px; margin-top: 2px; transition: opacity var(--fast);"
          onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=0.7" title="إغلاق"><i
            class="fas fa-times"></i></button>
      </div>
    </div>

    <!-- ══ ACCOUNT SWITCHER (MAIN PAGE) ══ -->
    <div class="sc account-switcher-box" id="scAccountSwitcher" style="display:none;">
      <!-- First-time Notice Banner -->
      <div class="first-time-switch-notice" id="firstTimeSwitchNotice" style="display:none;">
        <div class="notice-icon-box">
          <i class="fas fa-users-cog"></i>
        </div>
        <div class="notice-text-content">
          <div class="notice-title" id="firstTimeNoticeTitle">مرحباً بك! يوجد حسابان مرتبطان بهذا الرقم</div>
          <div class="notice-desc">تم ربط حسابات أولادك بهذا الرقم، ويمكنك التبديل بين الحسابات بسهولة في أي وقت من هذه المنطقة مباشرة أو من زر التبديل بالأعلى.</div>
          <div class="notice-arrow-hint">
            <i class="fas fa-hand-point-down"></i> اضغط على أي حساب أدناه للتبديل الفوري إليه
          </div>
        </div>
        <button type="button" class="notice-dismiss-btn" onclick="dismissFirstTimeSwitchNotice(event)" title="إغلاق التنبيه">
          <i class="fas fa-check"></i> فهمت
        </button>
      </div>

      <div class="sc-head as-head-bar">
        <div class="as-head-info-wrap">
          <div class="as-head-icon">
            <i class="fas fa-users"></i>
          </div>
          <div class="as-head-text-col">
            <div class="as-head-title-row">
              <span class="as-head-title-text as-title-full">الحسابات المرتبطة بهذا الرقم</span>
              <span class="as-head-title-text as-title-mobile">الحسابات المرتبطة</span>
              <span class="as-count-badge" id="asCountBadge">2</span>
            </div>
            <div class="as-head-sub-text">اضغط على أي حساب للتبديل السريع إليه</div>
          </div>
        </div>
        <div style="display:flex; align-items:center; gap:6px;">
          <button type="button" class="as-open-all-btn" style="background:var(--s2); color:var(--brand); border:1px solid var(--bdr);" onclick="openSeparateAccountsModal()" title="فصل الحسابات">
            <i class="fas fa-user-slash"></i>
            <span>فصل الحسابات</span>
          </button>
          <button type="button" class="as-open-all-btn" onclick="openOv('switchOv')">
            <span>عرض الكل</span>
            <i class="fas fa-chevron-left" style="font-size:.7rem;"></i>
          </button>
        </div>
      </div>

      <div class="acc-cards-grid" id="accCardsGrid">
        <!-- Rendered via JS -->
      </div>
    </div>

    <!-- Top Search Bar (Home page search) -->
    <div id="homeSearchBar" style="display:none; padding: 14px 16px 8px; position:relative; z-index:99;">
      <div style="position:relative;">
        <i class="fas fa-search"
          style="position:absolute; right:16px; top:50%; transform:translateY(-50%); color:var(--t4); font-size:.95rem; pointer-events:none;"></i>
        <input type="text" id="homeFriendSearch" placeholder="ابحث عن أصحابك في الكنيسة..."
          style="width:100%; padding:12px 42px 12px 42px; border:2px solid var(--bdr); border-radius:var(--r-xl); font-family:inherit; font-size:.88rem; outline:none; background:var(--surf); color:var(--t1); box-shadow:var(--sh-sm); transition:all var(--fast);"
          oninput="onHomeSearch(this.value)" autocomplete="off">
        <button onclick="clearHomeSearch()"
          style="position:absolute; left:16px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--t4); cursor:pointer; font-size:.95rem; display:flex; align-items:center; justify-content:center;"><i
            class="fas fa-times"></i></button>
      </div>
      <div id="homeSearchResults" class="inline-search-dropdown"></div>
    </div>

    <!-- ══ FULL PAGE SEND COUPONS WIZARD ══ -->
    <div class="sc send-coupons-page" id="scSendCoupons" style="display:none">
      <!-- Tab Header -->
      <div style="display:flex; align-items:center; gap:12px; margin-bottom:20px;">
        <div
          style="width:50px; height:50px; border-radius:50%; background:var(--brand-bg); color:var(--brand); display:flex; align-items:center; justify-content:center; font-size:1.35rem; flex-shrink:0; box-shadow: var(--sh-sm);">
          <i class="fas fa-star"></i>
        </div>
        <div>
          <div style="font-size:1.15rem; font-weight:800; color:var(--t1);">إرسال كوبونات</div>
          <div style="font-size:.78rem; color:var(--t3);">شارك كوبوناتك مع إخوتك وصديقك المقرب</div>
        </div>
      </div>

      <!-- Step Progress Dots -->
      <div class="wizard-dots">
        <div class="wizard-dot active" id="dot1"></div>
        <div class="wizard-dot" id="dot2"></div>
        <div class="wizard-dot" id="dot3"></div>
      </div>

      <!-- STEP 1: Select Recipient -->
      <div class="wizard-step-container" id="sendStep1">
        <div class="wizard-step-title"><i class="fas fa-user-plus"></i> اختر المستلم</div>

        <div id="siblingChipsContainer" style="display:none; margin-bottom:12px;">
          <div style="font-size:.72rem; color:var(--t3); margin-bottom:6px; font-weight:700;">إخوتك:</div>
          <div id="sendSiblingChips"
            style="display:flex; gap:8px; overflow-x:auto; padding-bottom:6px; scrollbar-width:none; -webkit-overflow-scrolling:touch;">
          </div>
        </div>

        <div style="font-size:.72rem; color:var(--t3); margin-bottom:6px; font-weight:700;">ابحث عن صديق بالاسم:</div>
        <div style="position:relative;">
          <i class="fas fa-search"
            style="position:absolute; right:12px; top:50%; transform:translateY(-50%); font-size:.82rem; color:var(--t4); pointer-events:none;"></i>
          <input type="text" id="sendFriendSearch" placeholder="اكتب اسم صديقك..." class="send-wizard-input"
            style="padding-right:36px; text-align:right;" oninput="onSendFriendSearch(this.value)" autocomplete="off">
        </div>
        <div id="sendFriendSearchResults"
          style="max-height:160px; overflow-y:auto; background:var(--surf); border:1px solid var(--bdr); border-radius:var(--r-sm); margin-top:6px; display:none; color:var(--t1); z-index: 10; position: relative;">
        </div>

        <!-- Selected Tag -->
        <div id="selectedRecipientTag"
          style="display:none; align-items:center; gap:8px; background:var(--brand-bg); color:var(--brand); padding:10px 14px; border-radius:var(--r-sm); margin-top:14px; font-size:.85rem; font-weight:800; border:1px solid var(--brand-l); box-shadow:var(--sh-md);">
          <i class="fas fa-user-check"></i>
          <span id="selectedRecipientName">صديق محدد</span>
          <button onclick="clearSelectedRecipient()"
            style="margin-right:auto; background:none; border:none; color:var(--err); cursor:pointer; font-size:1.1rem; display:flex; align-items:center; padding:0;"><i
              class="fas fa-times-circle"></i></button>
        </div>

        <div class="wizard-btn-row">
          <button class="btn btn-p" id="toStep2Btn"
            style="width:100%; padding:12px; font-weight:800;"
            onclick="goToStep(2)" disabled>متابعة <i class="fas fa-chevron-left"
              style="margin-right:4px; font-size:.75rem;"></i></button>
        </div>
      </div>

      <!-- STEP 2: Amount & Category -->
      <div class="wizard-step-container" id="sendStep2" style="display:none;">
        <div class="wizard-step-title"><i class="fas fa-star"></i> رصيد الإرسال والقيمة</div>

        <!-- Available breakdown -->
        <div
          style="background:var(--s2); border:1px solid var(--bdr); border-radius:var(--r-md); padding:12px; margin-bottom:14px;">
          <div
            style="font-size:.74rem; color:var(--t3); margin-bottom:8px; text-align:center; font-weight:700;">
            <i class="fas fa-wallet"></i> رصيدك المتاح
          </div>
          <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:6px; text-align:center; color:var(--t1);">
            <div style="background:var(--surf); padding:6px; border-radius:var(--r-sm); border:1px solid var(--bdr);">
              <div style="font-size:.62rem; color:var(--t4);">حضور</div>
              <div style="font-size:.9rem; font-weight:900;" id="sendAvailAtt">0</div>
            </div>
            <div style="background:var(--surf); padding:6px; border-radius:var(--r-sm); border:1px solid var(--bdr);">
              <div style="font-size:.62rem; color:var(--t4);">التزام</div>
              <div style="font-size:.9rem; font-weight:900;" id="sendAvailCom">0</div>
            </div>
            <div style="background:var(--surf); padding:6px; border-radius:var(--r-sm); border:1px solid var(--bdr);">
              <div style="font-size:.62rem; color:var(--t4);">تاسكات</div>
              <div style="font-size:.9rem; font-weight:900;" id="sendAvailTsk">0</div>
            </div>
          </div>
          <div style="text-align:center; font-size:.78rem; font-weight:800; margin-top:8px; color:var(--t1);">إجمالي رصيدك الكلي: <span
              id="sendAvailTotal" style="font-weight:900;">0</span></div>
        </div>

        <!-- Choose Category -->
        <div style="margin-bottom:14px;">
          <div style="font-size:.72rem; color:var(--t3); margin-bottom:6px; font-weight:700;">أرسل من تصنيف:</div>
          <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:6px;">
            <button class="send-cat-btn active" data-cat="all" onclick="selectSendCat(this)">الكل</button>
            <button class="send-cat-btn" data-cat="att" onclick="selectSendCat(this)">حضور</button>
            <button class="send-cat-btn" data-cat="com" onclick="selectSendCat(this)">التزام</button>
            <button class="send-cat-btn" data-cat="task" onclick="selectSendCat(this)">تاسكات</button>
          </div>
        </div>

        <!-- Enter Amount -->
        <div style="margin-bottom:14px;">
          <div style="font-size:.72rem; color:var(--t3); margin-bottom:6px; font-weight:700;">عدد الكوبونات:</div>
          <input type="number" id="sendAmount" placeholder="أدخل العدد..." class="send-wizard-input" min="1"
            oninput="checkStep2Valid()">
        </div>

        <div class="wizard-btn-row">
          <button class="btn btn-g" style="flex:1; padding:12px;" onclick="goToStep(1)"><i class="fas fa-chevron-right"></i> رجوع</button>
          <button class="btn btn-p" id="toStep3Btn" style="flex:2; padding:12px; font-weight:800;" onclick="goToStep(3)" disabled>التالي <i class="fas fa-chevron-left"></i></button>
        </div>
      </div>

      <!-- STEP 3: Password & Confirm -->
      <div class="wizard-step-container" id="sendStep3" style="display:none;">
        <div class="wizard-step-title"><i class="fas fa-shield-alt"></i> تأكيد الهوية والإرسال</div>

        <div
          style="background:var(--brand-bg); border:1px solid var(--brand-l); color:var(--brand); border-radius:var(--r-md); padding:14px; text-align:center; font-size:.85rem; line-height:1.5; margin-bottom:14px; font-weight:800;"
          id="sendSummaryMsg">
          سوف تقوم بإرسال 0 كوبون إلى صديقك.
        </div>

        <div style="margin-bottom:14px;">
          <div style="font-size:.72rem; color:var(--t3); margin-bottom:6px; font-weight:700;">اكتب كلمة مرور حسابك لتأكيد العملية:</div>
          <input type="password" id="sendPassword" placeholder="كلمة المرور الخاصة بك..." class="send-wizard-input"
            oninput="checkStep3Valid()">
        </div>

        <div class="wizard-btn-row" style="align-items: center; justify-content: center; gap: 16px;">
          <button class="btn btn-g"
            style="padding: 12px 20px; height: 48px; display: flex; align-items: center; justify-content: center; font-weight: 800;"
            onclick="goToStep(2)"><i class="fas fa-chevron-right" style="margin-left: 6px;"></i> رجوع</button>
          <button class="btn btn-p" id="sendWizardSubmitBtn"
            style="width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0; padding: 0; margin: 0 auto;"
            onclick="trySendCoupons()" disabled><i class="fas fa-star"></i></button>
        </div>
      </div>
    </div>


    <!-- public banner -->
    <div class="public-banner" id="pubBanner" style="display:none">
      <i class="fas fa-eye"></i> عرض عام
      <a href="/user/login"
        style="margin-right:auto;color:var(--brand);text-decoration:none;font-size:.74rem;font-weight:800;"><i
          class="fas fa-sign-in-alt"></i> دخول</a>
    </div>

    <!-- Friend mode banner -->
    <div class="friend-banner" id="friendBanner" style="display:none">
      <i class="fas fa-user-friends"></i>
      <span id="friendBannerName">ملف صديق</span>
      <button onclick="returnToMyProfile()"
        style="margin-right:auto;background:none;border:none;color:#9333ea;font-size:.8rem;font-weight:800;cursor:pointer;font-family:inherit;padding:0;display:flex;align-items:center;gap:5px;"><i
          class="fas fa-arrow-right"></i> <span>رجوع</span></button>
    </div>




    <!-- Trips -->
    <div class="sc" id="scTrips" style="display:none">
      <div class="sc-head">
        <div class="sc-ico" style="background:var(--trip-bg);color:var(--trip-l);"><i class="fas fa-bus"></i></div>
        <div class="sc-label">
          <div class="sc-title">الرحلات / المؤتمرات</div>
          <div class="sc-sub" id="tripSub"></div>
        </div>
      </div>
      <div class="sc-body">
        <div id="tripList"></div>
      </div>
    </div>


    <!-- Attendance -->
    <div class="sc" id="scAtt" style="display:none">
      <div class="sc-head">
        <div class="sc-ico" style="background:var(--ok-bg);color:var(--ok);"><i class="fas fa-calendar-check"></i></div>
        <div class="sc-label">
          <div class="sc-title">سجل الحضور</div>
          <div class="sc-sub" id="attSub">آخر 12 أسبوع</div>
        </div>
        <div class="sc-badge" id="attBadge"></div>
      </div>
      <div class="sc-body">
        <div class="att-stats">
          <div class="as ok">
            <div class="as-val" id="ap">0</div>
            <div class="as-lbl">حضر</div>
          </div>
          <div class="as err">
            <div class="as-val" id="aa">0</div>
            <div class="as-lbl">غاب</div>
          </div>
          <div class="as neu">
            <div class="as-val" id="ar">0%</div>
            <div class="as-lbl">نسبة</div>
          </div>
        </div>
        <div class="cal-grid" id="calGrid"></div>
        <div style="text-align:center;margin-top:4px;">
          <span class="att-view-all" id="attViewAllBtn" onclick="openAttHistory()">
            <i class="fas fa-list-ul"></i> <span>عرض السجل بالكامل</span>
          </span>
        </div>
      </div>
    </div>

    <!-- Tasks -->
    <div class="sc" id="scTasks" style="display:none">
      <div class="sc-head">
        <div class="sc-ico" style="background:var(--brand-bg);color:var(--brand);"><i class="fas fa-tasks"></i></div>
        <div class="sc-label">
          <div class="sc-title">الاختبارات والتاسكات</div>
          <div class="sc-sub" id="taskSub">0 تاسك</div>
        </div>
      </div>
      <div class="sc-body">
        <div id="taskList"></div>
      </div>
    </div>

    <!-- Paper Exams -->
    <div class="sc" id="scPaperExams" style="display:none">
      <div class="sc-head">
        <div class="sc-ico" style="background:#fef3c7;color:#d97706;"><i class="fas fa-file-invoice"></i></div>
        <div class="sc-label">
          <div class="sc-title">الامتحانات الورقية</div>
          <div class="sc-sub" id="paperExamsSub">درجات الامتحانات التحريرية وأوراق الإجابات</div>
        </div>
      </div>
      <div class="sc-body">
        <div id="paperExamsList" style="display:flex; flex-direction:column; gap:8px;"></div>
      </div>
    </div>



    <!-- Announcements -->
    <div class="sc" id="scAnn" style="display:none">
      <div class="sc-head">
        <div class="sc-ico" style="background:var(--warn-bg);color:var(--warn-l);"><i class="fas fa-bullhorn"></i></div>
        <div class="sc-label">
          <div class="sc-title">الإعلانات</div>
          <div class="sc-sub" id="annSub">كل جديد يخصك هيظهر هنا أولاً</div>
        </div>
        <div class="sc-badge" id="annBadge">0</div>
      </div>
      <div class="sc-body">
        <div id="annList"></div>
      </div>
    </div>


    <!-- Siblings Section -->
    <div class="sc" id="scSiblings" style="display:none">
      <div class="sc-head">
        <div class="sc-ico" style="background:#e0f2fe;color:#0369a1;"><i class="fas fa-user-friends"></i></div>
        <div class="sc-label">
          <div class="sc-title">إخوتك</div>
          <div class="sc-sub">اضغط على أي من إخوتك لزيارة حسابه مباشرة</div>
        </div>
      </div>
      <div class="sc-body">
        <div id="siblingsList" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:12px;">
        </div>
      </div>
    </div>




    <!-- Uncles section -->
    <div class="sc" id="scUncles" style="display:none;">
      <div class="sc-head">
        <div class="sc-ico" style="background:#ede9fe;color:#7c3aed;"><i class="fas fa-chalkboard-teacher"></i></div>
        <div class="sc-label">
          <div class="sc-title">انكل وطنط اللي معاك في الفصل</div>
          <div class="sc-sub" id="unclesSub"></div>
        </div>
      </div>
      <div class="sc-body">
        <div id="uncleCardGrid"
          style="display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:14px;"></div>
      </div>
    </div>

    <!-- Class Friends section -->
    <div class="sc" id="scClassFriends" style="display:none;">
      <div class="sc-head">
        <div class="sc-ico" style="background:var(--ok-bg);color:var(--ok);"><i class="fas fa-user-friends"></i></div>
        <div class="sc-label">
          <div class="sc-title">أصدقائي في الفصل</div>
          <div class="sc-sub" id="friendsSub">زملاء فصلك بمدارس الأحد</div>
        </div>
      </div>
      <div class="sc-body">
        <div id="friendsCardGrid"
          style="display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:14px;"></div>
      </div>
    </div>



    <div style="text-align:center;padding:18px 0 0;font-size:.72rem;color:var(--t4);margin-top:auto !important;">
      <span style="font-weight:700;">Sunday School 2026</span><br>
      <!-- مُكْثِرِينَ فِي عَمَلِ الرَّبِّ كُلَّ حِينٍ-->
    </div>
  </div>
</div>

  <div class="no-profile" id="noProfile" style="display:none">
    <i class="fas fa-user-slash"></i>
    <h2>لم يُعثر على ملف شخصي</h2>
    <p id="noMsg">يرجى تسجيل الدخول أو استخدام رابط المعرّف</p>
    <a href="/user/login" class="btn btn-p"><i class="fas fa-sign-in-alt"></i> تسجيل الدخول</a>
  </div>


  <!-- ══ ATTENDANCE HISTORY SHEET ══ -->
  <div class="overlay settings-overlay" id="attHistOv">
    <div class="settings-sheet" style="max-width:600px;max-height:92vh;">
      <div class="ss-handle"></div>

      <!-- Header -->
      <div style="padding:14px 20px 12px;border-bottom:1px solid var(--bdr2);display:flex;align-items:center;gap:12px;">
        <div
          style="width:38px;height:38px;border-radius:var(--r-sm);background:var(--ok-bg);color:var(--ok);display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0;">
          <i class="fas fa-calendar-check"></i>
        </div>
        <div style="flex:1;">
          <div style="font-size:1rem;font-weight:800;color:var(--t1);">سجل الحضور الكامل</div>
          <div style="font-size:.72rem;color:var(--t4);font-weight:600;" id="attHistSubtitle">جارٍ التحميل…</div>
        </div>
        <button onclick="closeOv('attHistOv')"
          style="width:30px;height:30px;border-radius:var(--r-sm);border:1.5px solid var(--bdr);background:var(--s2);color:var(--t3);font-size:.82rem;cursor:pointer;display:flex;align-items:center;justify-content:center;">
          <i class="fas fa-times"></i>
        </button>
      </div>

      <!-- Summary -->
      <div
        style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:12px 16px;border-bottom:1px solid var(--bdr2);">
        <div
          style="text-align:center;padding:10px 6px;border-radius:var(--r-md);background:var(--ok-bg);border:1px solid #6ee7b7;">
          <div style="font-size:1.2rem;font-weight:800;color:var(--ok);" id="ahsPresent">0</div>
          <div style="font-size:.6rem;color:var(--t4);margin-top:2px;font-weight:600;">حضر</div>
        </div>
        <div
          style="text-align:center;padding:10px 6px;border-radius:var(--r-md);background:var(--err-bg);border:1px solid #fca5a5;">
          <div style="font-size:1.2rem;font-weight:800;color:var(--err);" id="ahsAbsent">0</div>
          <div style="font-size:.6rem;color:var(--t4);margin-top:2px;font-weight:600;">غاب</div>
        </div>
        <div
          style="text-align:center;padding:10px 6px;border-radius:var(--r-md);background:var(--s2);border:1px solid var(--bdr);">
          <div style="font-size:1.2rem;font-weight:800;color:var(--brand);" id="ahsRate">0%</div>
          <div style="font-size:.6rem;color:var(--t4);margin-top:2px;font-weight:600;">نسبة الحضور</div>
        </div>
      </div>

      <!-- Filters -->
      <div
        style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:10px 16px;border-bottom:1px solid var(--bdr2);background:var(--s2);">
        <input id="attHistSearch" type="text" placeholder="ابحث بالتاريخ…"
          style="flex:1;min-width:120px;padding:8px 12px;border:1.5px solid var(--bdr);border-radius:var(--r-sm);font-family:'Baloo Bhaijaan 2',sans-serif;font-size:.86rem;background:var(--surf);color:var(--t1);outline:none;"
          oninput="renderAttHist()" />
        <select id="attHistSort" onchange="renderAttHist()"
          style="padding:7px 10px;border:1.5px solid var(--bdr);border-radius:var(--r-sm);font-family:'Baloo Bhaijaan 2',sans-serif;font-size:.8rem;background:var(--surf);color:var(--t2);outline:none;">
          <option value="newest">الأحدث أولاً</option>
          <option value="oldest">الأقدم أولاً</option>
        </select>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
          <span class="fchip active" data-filter="all" onclick="setAttFilter(this,'all')">الكل</span>
          <span class="fchip ok" data-filter="present" onclick="setAttFilter(this,'present')">✓ حضر</span>
          <span class="fchip err" data-filter="absent" onclick="setAttFilter(this,'absent')">✗ غاب</span>
          <span class="fchip" data-filter="unrecorded" onclick="setAttFilter(this,'unrecorded')">— غير مسجّل</span>
        </div>
      </div>

      <!-- Count -->
      <div id="attHistCount" style="font-size:.7rem;color:var(--t4);font-weight:600;padding:6px 16px 2px;"></div>

      <!-- List (scrollable) -->
      <div id="attHistList" class="att-hist-scroll"
        style="padding:6px 14px 12px;overflow-y:auto;max-height:calc(92vh - 290px);display:flex;flex-direction:column;gap:5px;">
        <div style="text-align:center;padding:28px;color:var(--t4);font-size:.88rem;">
          <i class="fas fa-spinner fa-spin"
            style="display:block;font-size:1.6rem;margin-bottom:8px;opacity:.4;"></i>جارٍ التحميل…
        </div>
      </div>

    </div>
  </div>

  <!-- ══ REPORT ATTENDANCE ERROR (WhatsApp) SHEET ══ -->
  <div class="overlay settings-overlay" id="attReportOv">
    <div class="settings-sheet" style="max-width:480px;">
      <div class="ss-handle"></div>
      <div style="padding:14px 20px 12px;border-bottom:1px solid var(--bdr2);display:flex;align-items:center;gap:12px;">
        <div
          style="width:36px;height:36px;border-radius:var(--r-sm);background:#fef3c7;color:#d97706;display:flex;align-items:center;justify-content:center;font-size:.92rem;flex-shrink:0;">
          <i class="fas fa-flag"></i>
        </div>
        <div style="flex:1;">
          <div style="font-size:.96rem;font-weight:800;color:var(--t1);">بلّغ عن خطأ</div>
          <div style="font-size:.72rem;color:var(--t4);font-weight:600;" id="reportDateLabel"></div>
        </div>
        <button onclick="closeOv('attReportOv')"
          style="width:28px;height:28px;border-radius:var(--r-sm);border:1.5px solid var(--bdr);background:var(--s2);color:var(--t3);font-size:.8rem;cursor:pointer;display:flex;align-items:center;justify-content:center;">
          <i class="fas fa-times"></i>
        </button>
      </div>

      <!-- Only picker: what SHOULD it be -->
      <div style="padding:16px 16px 12px;border-bottom:1px solid var(--bdr2);">
        <div style="font-size:.74rem;font-weight:700;color:var(--t3);margin-bottom:10px;">المفروض أنا كنت</div>
        <div style="display:flex;gap:10px;">
          <button id="reportShouldPresent" onclick="setReportShould('present')"
            style="flex:1;padding:14px 8px;border-radius:var(--r-md);border:2px solid var(--bdr);background:var(--surf);color:var(--t2);font-family:'Baloo Bhaijaan 2',sans-serif;font-size:.95rem;font-weight:800;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;transition:var(--fast);">
            <i class="fas fa-check-circle"></i> <span>حضر</span>
          </button>
          <button id="reportShouldAbsent" onclick="setReportShould('absent')"
            style="flex:1;padding:14px 8px;border-radius:var(--r-md);border:2px solid var(--bdr);background:var(--surf);color:var(--t2);font-family:'Baloo Bhaijaan 2',sans-serif;font-size:.95rem;font-weight:800;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;transition:var(--fast);">
            <i class="fas fa-times-circle"></i> <span>غاب</span>
          </button>
        </div>
      </div>

      <!-- Uncle list -->
      <div style="padding:12px 16px 4px;">
        <div style="font-size:.72rem;font-weight:700;color:var(--t3);margin-bottom:8px;">ابعت للمدرّس</div>
        <div id="reportUncleList" style="display:flex;flex-direction:column;gap:6px;"></div>
      </div>
      <button class="ss-close-btn" onclick="closeOv('attReportOv')">إغلاق</button>
    </div>
  </div>

  <!-- ══ OVERLAYS ══ -->

  <!-- Settings — full-screen modern sheet -->
  <div class="overlay settings-overlay" id="settingsOv">
    <div class="settings-sheet">
      <div class="ss-handle"></div>
      <div class="ss-profile" id="ssProfile">
        <div class="ss-avatar" id="ssAvatar"><i class="fas fa-user"></i></div>
        <div>
          <div class="ss-name" id="ssName">—</div>
          <div class="ss-class" id="ssClass">—</div>
        </div>
      </div>
      <div class="ss-items">
        <div class="ss-item" onclick="closeOv('settingsOv');setTimeout(()=>openOv('editOv'),180)">
          <div class="ss-item-ico" style="background:#e0e7ff;color:#4338ca;"><i class="fas fa-user-edit"></i></div>
          <div class="ss-item-label">تعديل معلوماتي</div>
          <i class="fas fa-chevron-left ss-item-arr"></i>
        </div>
        <div class="ss-item" id="passMenuItem" onclick="closeOv('settingsOv');setTimeout(()=>openOv('passOv'),180)">
          <div class="ss-item-ico" style="background:#fef3c7;color:#92400e;"><i class="fas fa-lock"></i></div>
          <div class="ss-item-label" id="passMenuLabel">تغيير كلمة المرور</div>
          <i class="fas fa-chevron-left ss-item-arr"></i>
        </div>
        <div class="ss-item" onclick="closeOv('settingsOv');setTimeout(()=>openOv('photoOv'),180)">
          <div class="ss-item-ico" style="background:#d1fae5;color:#065f46;"><i class="fas fa-camera"></i></div>
          <div class="ss-item-label">تغيير الصورة الشخصية</div>
          <i class="fas fa-chevron-left ss-item-arr"></i>
        </div>
        <div class="ss-item" id="notifToggleItem" style="display:none">
          <div class="ss-item-ico" style="background:#ffe4e6;color:#e11d48;"><i class="fas fa-bell"></i></div>
          <div class="ss-item-label">إشعارات الهاتف</div>
          <label class="switch-toggle" style="margin-left: auto; display: inline-block;">
            <input type="checkbox" id="phoneNotifToggle">
            <span class="slider-toggle"></span>
          </label>
        </div>
        <div class="ss-item" id="settingsPwaBtn" style="display:none"
          onclick="closeOv('settingsOv');setTimeout(()=>triggerPwaInstall(),180)">
          <div class="ss-item-ico" style="background:#e0f2fe;color:#0369a1;"><i class="fas fa-download"></i></div>
          <div class="ss-item-label">تنزيل التطبيق كـ App</div>
          <i class="fas fa-chevron-left ss-item-arr"></i>
        </div>
      </div>
      <div class="ss-divider"></div>
      <div class="ss-items">
        <div class="ss-item danger" onclick="doLogout()">
          <div class="ss-item-ico" style="background:#fee2e2;color:#b91c1c;"><i class="fas fa-sign-out-alt"></i></div>
          <div class="ss-item-label">تسجيل الخروج</div>
          <i class="fas fa-chevron-left ss-item-arr"></i>
        </div>
      </div>
      <button class="ss-close-btn" onclick="closeOv('settingsOv')">إغلاق</button>
    </div>
  </div>

  <!-- Edit Info -->
  <div class="overlay settings-overlay" id="editOv">
    <div class="settings-sheet">
      <div class="ss-handle"></div>
      <div style="padding:18px 22px 8px;border-bottom:1px solid var(--bdr2);">
        <div style="font-size:1.05rem;font-weight:800;color:var(--t1);display:flex;align-items:center;gap:10px;">
          <div
            style="width:36px;height:36px;border-radius:var(--r-sm);background:#e0e7ff;color:#4338ca;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas fa-user-edit"></i>
          </div>
          <span>تعديل المعلومات</span>
        </div>
      </div>
      <div style="padding:18px 22px;">
        <div class="fg"><label class="flbl">الاسم</label><input class="fi" id="eN" type="text"></div>
        <div class="fg"><label class="flbl">العنوان</label><input class="fi" id="eA" type="text"></div>
        <div class="fg"><label class="flbl">التليفون</label><input class="fi" id="eP" type="tel"></div>
        <div class="fg" style="margin-bottom:0;"><label class="flbl">تاريخ الميلاد</label><input class="fi" id="eB"
            type="text" placeholder="DD/MM/YYYY"></div>
      </div>
      <div style="padding:8px 22px 0;">
        <button class="btn btn-p" style="width:100%;padding:12px;" onclick="saveProfile()"><i class="fas fa-save"></i>
          <span>حفظ المعلومات</span></button>
      </div>
      <button class="ss-close-btn" onclick="closeOv('editOv')">إغلاق</button>
    </div>
  </div>

  <!-- Add / Change Password -->
  <div class="overlay settings-overlay" id="passOv">
    <div class="settings-sheet">
      <div class="ss-handle"></div>
      <div style="padding:18px 22px 8px;border-bottom:1px solid var(--bdr2);">
        <div style="font-size:1.05rem;font-weight:800;color:var(--t1);display:flex;align-items:center;gap:10px;">
          <div
            style="width:36px;height:36px;border-radius:var(--r-sm);background:#fef3c7;color:#92400e;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas fa-lock"></i>
          </div>
          <span id="passOvTitle">تغيير كلمة المرور</span>
        </div>
      </div>
      <div style="padding:18px 22px;">
        <!-- Note for "add" mode -->
        <div id="passAddNote"
          style="display:none;background:var(--brand-bg);color:var(--brand);border-radius:var(--r-sm);padding:10px 14px;font-size:.82rem;font-weight:700;margin-bottom:14px;line-height:1.5;">
          <i class="fas fa-info-circle" style="margin-left:6px;"></i>
          لا توجد كلمة مرور لحسابك بعد. أضف كلمة مرور الآن لتتمكن من إرسال الكوبونات والمزيد.
        </div>
        <!-- Old password (shown only in change mode) -->
        <div class="fg" id="passOldWrap">
          <label class="flbl">الحالية</label>
          <div class="pass-wrap"><input class="fi" id="po" type="password"><button type="button" class="pass-eye"
              onclick="tPass('po',this)"><i class="fas fa-eye"></i></button></div>
        </div>
        <div class="fg"><label class="flbl">الجديدة (٦ أحرف+)</label>
          <div class="pass-wrap"><input class="fi" id="pn" type="password"><button type="button" class="pass-eye"
              onclick="tPass('pn',this)"><i class="fas fa-eye"></i></button></div>
        </div>
        <div class="fg" style="margin-bottom:0;"><label class="flbl">تأكيد الجديدة</label>
          <div class="pass-wrap"><input class="fi" id="pc" type="password"><button type="button" class="pass-eye"
              onclick="tPass('pc',this)"><i class="fas fa-eye"></i></button></div>
        </div>
      </div>
      <div style="padding:8px 22px 0;">
        <button class="btn btn-p" style="width:100%;padding:12px;" onclick="changePass()"><i class="fas fa-lock"></i>
          <span id="passOvBtn">تغيير كلمة المرور</span></button>
      </div>
      <button class="ss-close-btn" onclick="closeOv('passOv')">إغلاق</button>
    </div>
  </div>

  <!-- Photo Upload -->
  <div class="overlay settings-overlay" id="photoOv">
    <div class="settings-sheet">
      <div class="ss-handle"></div>
      <div style="padding:18px 22px 8px;border-bottom:1px solid var(--bdr2);">
        <div style="font-size:1.05rem;font-weight:800;color:var(--t1);display:flex;align-items:center;gap:10px;">
          <div
            style="width:36px;height:36px;border-radius:var(--r-sm);background:#d1fae5;color:#065f46;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas fa-camera"></i>
          </div>
          <span>تغيير الصورة الشخصية</span>
        </div>
      </div>
      <div style="padding:18px 22px;">
        <div class="upload-drop" id="dropZone" onclick="document.getElementById('photoIn').click()">
          <i class="fas fa-cloud-upload-alt"></i>
          <p>اضغط أو اسحب صورة هنا</p>
          <input type="file" id="photoIn" accept="image/*" style="display:none" onchange="onPhoto(event)">
        </div>
        <div id="cropWrap" style="display:none">
          <div class="crop-area"><img id="cropImg" src="" alt=""></div>
        </div>
        <img id="photoPrev" src=""
          style="display:none;width:100px;height:100px;border-radius:50%;object-fit:cover;margin:0 auto 12px;border:3px solid var(--brand);">
      </div>
      <div style="padding:8px 22px 0;display:flex;gap:8px;">
        <button class="btn btn-p" id="cropBtn" style="display:none;width:100%;padding:12px;" onclick="doCrop()"><i
            class="fas fa-crop-alt"></i> <span>قص الصورة</span></button>
        <button class="btn btn-p" id="uploadBtn" style="display:none;width:100%;padding:12px;"
          onclick="uploadPhoto()"><i class="fas fa-upload"></i> <span>رفع الصورة</span></button>
      </div>
      <button class="ss-close-btn" onclick="closeOv('photoOv');resetPhoto()">إغلاق</button>
    </div>
  </div>

  <!-- Birthday Greeting -->
  <div class="overlay settings-overlay" id="bdayGreetingOv">
    <div class="settings-sheet" style="max-width:430px;">
      <div class="ss-handle"></div>
      <div style="padding:18px 22px 10px;border-bottom:1px solid var(--bdr2);display:flex;align-items:center;gap:10px;">
        <div
          style="width:36px;height:36px;border-radius:var(--r-sm);background:#fef3c7;color:#b45309;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <i class="fas fa-cake-candles"></i>
        </div>
        <div style="flex:1;">
          <div style="font-size:1.05rem;font-weight:800;color:var(--t1);">صورة عيد الميلاد</div>
          <div style="font-size:.72rem;color:var(--t4);font-weight:600;">احفظها أو شاركها مع أصحابك</div>
        </div>
        <button onclick="closeOv('bdayGreetingOv')"
          style="width:30px;height:30px;border-radius:var(--r-sm);border:1.5px solid var(--bdr);background:var(--s2);color:var(--t3);font-size:.82rem;cursor:pointer;display:flex;align-items:center;justify-content:center;">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <div style="padding:18px 22px 0;">
        <div class="birthday-card-preview">
          <canvas id="birthdayGreetingCanvas" width="1080" height="1350"></canvas>
        </div>
      </div>
      <div class="birthday-actions">
        <button class="btn btn-p" onclick="shareBirthdayGreeting()"><i class="fas fa-share-alt"></i>
          <span>مشاركة</span></button>
        <button class="btn btn-p" onclick="saveBirthdayGreeting()"><i class="fas fa-download"></i>
          <span>حفظ</span></button>
      </div>
      <button class="ss-close-btn" onclick="closeOv('bdayGreetingOv')">إغلاق</button>
    </div>
  </div>




  <!-- Internal Confirmation modal for sharing -->
  <div class="overlay" id="shareConfirmModal" style="z-index:1200;">
    <div class="modal narrow" style="max-width:360px;">
      <div class="mhdr" style="background:linear-gradient(135deg,var(--brand),var(--cou));">
        <div
          style="width:36px;height:36px;border-radius:var(--r-sm);background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.2rem;color:#fff;">
          <i class="fas fa-question-circle"></i>
        </div>
        <div class="mhdr-title" style="flex:1;">تأكيد الإرسال</div>
        <button class="mclose" onclick="closeOv('shareConfirmModal')"><i class="fas fa-times"></i></button>
      </div>
      <div class="mbody" style="text-align:center;padding:22px 18px 14px;">
        <div id="shareConfirmMsg"
          style="font-size:.93rem;font-weight:700;color:var(--t1);line-height:1.6;margin-bottom:18px;"></div>
        <div style="display:flex;gap:10px;">
          <button class="btn btn-g" style="flex:1;" onclick="closeOv('shareConfirmModal')">تراجع</button>
          <button class="btn btn-p" style="flex:1;background:linear-gradient(135deg,var(--brand),var(--cou));"
            onclick="confirmSendCoupons()"><i class="fas fa-check"></i> نعم، أرسل</button>
        </div>
      </div>
    </div>
  </div>


  <!-- Bottom Mobile Navigation Bar was moved inside sidebar wrapper -->

  <!-- Notifications Drawer -->
  <div class="overlay settings-overlay" id="notifOv">
    <div class="settings-sheet" style="max-height: 92vh; display:flex; flex-direction:column;">
      <div class="ss-handle"></div>
      <div
        style="padding:14px 22px 10px;border-bottom:1px solid var(--bdr2);display:flex;align-items:center;gap:10px;flex-shrink:0;">
        <div
          style="width:36px;height:36px;border-radius:var(--r-sm);background:var(--warn-bg);color:var(--warn-l);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <i class="fas fa-bell"></i>
        </div>
        <div style="flex:1;min-width:0;text-align:right;direction:rtl;">
          <div style="font-size:1rem;font-weight:800;color:var(--t1);">الإشعارات والإعلانات</div>
          <div style="font-size:.72rem;color:var(--t4);margin-top:1px;">كل جديد يخصك هيظهر هنا أولاً</div>
        </div>
      </div>
      <div id="notifListModal"
        style="padding:16px 18px; overflow-y:auto; flex:1; max-height:calc(92vh - 120px); text-align:right; direction:rtl;">
      </div>
      <button class="ss-close-btn" onclick="closeOv('notifOv')" style="flex-shrink:0;">إغلاق</button>
    </div>
  </div>

  <!-- Account Switch -->
  <div class="overlay settings-overlay" id="switchOv">
    <div class="settings-sheet">
      <div class="ss-handle"></div>
      <div style="padding:18px 22px 8px;border-bottom:1px solid var(--bdr2);">
        <div style="font-size:1.05rem;font-weight:800;color:var(--t1);display:flex;align-items:center;gap:10px;">
          <div
            style="width:36px;height:36px;border-radius:var(--r-sm);background:var(--brand-bg);color:var(--brand);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas fa-exchange-alt"></i>
          </div>
          <span>تبديل الحساب</span>
        </div>
      </div>
      <div id="switchList" style="padding:10px 16px;"></div>
      <div style="padding:4px 16px 10px;">
        <button type="button" class="btn" style="width:100%; display:flex; align-items:center; justify-content:center; gap:8px; background:var(--s2); color:var(--brand); border:1px solid var(--bdr); font-weight:700; border-radius:var(--r-md); padding:10px;" onclick="closeOv('switchOv'); openSeparateAccountsModal();">
          <i class="fas fa-user-slash"></i>
          <span>فصل الحسابات وتغيير الأرقام</span>
        </button>
      </div>
      <button class="ss-close-btn" onclick="closeOv('switchOv')">إغلاق</button>
    </div>
  </div>

  <!-- ══ FIRST TIME MULTI-ACCOUNT MODAL ══ -->
  <div class="overlay settings-overlay" id="firstTimeMultiAccModal" style="z-index:99999;">
    <div class="settings-sheet ft-sheet" style="max-width:440px; text-align:center; padding:24px 20px 20px; margin:auto; border-radius:var(--r-xl); direction:rtl;">
      <div class="ft-avatars-wrap" id="ftAvatarsWrap">
        <!-- Dynamic overlapping avatars -->
      </div>
      <h3 class="ft-title" id="ftModalTitle">يوجد حسابان مرتبطان بهذا الرقم</h3>
      <p class="ft-desc" id="ftModalDesc">
        مرحباً بك! لاحظنا وجود أكثر من حساب مرتبط برقم هاتفك. يمكنك التبديل بين حسابات أولادك في أي وقت بسهولة من منطقة <strong>"الحسابات المرتبطة"</strong> في الصفحة الرئيسية.
      </p>
      <div class="ft-kids-preview" id="ftKidsPreview">
        <!-- Chips with kid names -->
      </div>
      <div class="ft-actions">
        <button type="button" class="ft-btn-primary" onclick="openSeparateAccountsModal()">
          <i class="fas fa-user-slash"></i> فصل الحسابات
        </button>
        <button type="button" class="ft-btn-secondary" onclick="dismissFirstTimeModalOnly()">
          حسناً، فهمت
        </button>
      </div>
    </div>
  </div>

  <!-- ══ SEPARATE ACCOUNTS MODAL ══ -->
  <div class="overlay settings-overlay" id="separateAccountsModal" style="z-index:999999;">
    <div class="settings-sheet sep-sheet" style="max-width:520px; width:100%; max-height:88vh; margin:auto; border-radius:var(--r-xl); direction:rtl; display:flex; flex-direction:column; overflow:hidden; background:var(--surf);">
      <div class="ss-handle"></div>
      
      <!-- Modal Header -->
      <div style="padding:16px 20px 14px; border-bottom:1px solid var(--bdr2); display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">
        <div style="display:flex; align-items:center; gap:12px;">
          <div style="width:40px; height:40px; border-radius:var(--r-md); background:var(--brand-bg); color:var(--brand); display:flex; align-items:center; justify-content:center; font-size:1.15rem; flex-shrink:0;">
            <i class="fas fa-user-slash"></i>
          </div>
          <div>
            <div style="font-size:1.06rem; font-weight:800; color:var(--t1);">فصل الحسابات وتغيير الأرقام</div>
            <div style="font-size:.76rem; color:var(--t3); margin-top:2px;">تعيين رقم هاتف وكلمة مرور مستقلة لكل حساب</div>
          </div>
        </div>
        <button type="button" class="close-btn" onclick="closeOv('separateAccountsModal')" aria-label="إغلاق" style="width:34px; height:34px; border-radius:50%; background:var(--s2); border:none; color:var(--t2); cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:1rem; transition:all var(--fast);">
          <i class="fas fa-times"></i>
        </button>
      </div>

      <!-- Modal Body -->
      <div id="separateAccountsBody" style="padding:16px 20px; overflow-y:auto; flex:1;">
        <div style="background:var(--s2); border:1px solid var(--bdr); border-radius:var(--r-md); padding:12px 14px; margin-bottom:16px; display:flex; align-items:flex-start; gap:10px; font-size:.82rem; color:var(--t2); line-height:1.55;">
          <i class="fas fa-info-circle" style="color:var(--brand); font-size:1.05rem; margin-top:2px; flex-shrink:0;"></i>
          <span>يمكنك تخصيص رقم هاتف مستقل لكل طفل ليصبح حسابه منفصلاً تماماً. <strong>الحسابات التي ليس لها كلمة مرور تتطلب تعيين كلمة مرور جديدة</strong> لتتمكن من تسجيل الدخول إليها مستقبلاً.</span>
        </div>

        <div id="sepAccountsList" style="display:flex; flex-direction:column; gap:14px;">
          <!-- Dynamically populated via JS -->
        </div>
      </div>

      <!-- Modal Footer -->
      <div style="padding:14px 20px; border-top:1px solid var(--bdr2); background:var(--surf); display:flex; gap:10px; flex-shrink:0;">
        <button type="button" class="ft-btn-primary" id="btnSaveSeparateAccounts" style="flex:1;" onclick="saveSeparateAccounts()">
          <i class="fas fa-save"></i>
          <span>حفظ جميع الحسابات</span>
        </button>
        <button type="button" class="ft-btn-secondary" style="width:auto; min-width:85px;" onclick="closeOv('separateAccountsModal')">
          إلغاء
        </button>
      </div>
    </div>
  </div>

  <!-- Trip Detail -->
  <div class="overlay settings-overlay" id="tripOv">
    <div class="settings-sheet" style="max-height:92vh;">
      <div class="ss-handle"></div>
      <div style="padding:14px 22px 10px;border-bottom:1px solid var(--bdr2);display:flex;align-items:center;gap:10px;">
        <div
          style="width:36px;height:36px;border-radius:var(--r-sm);background:var(--trip-bg);color:var(--trip-l);display:flex;align-items:center;justify-content:center;flex-shrink:0;flex-shrink:0;">
          <i class="fas fa-bus"></i>
        </div>
        <div style="flex:1;min-width:0;">
          <div id="tripOvTitle"
            style="font-size:1rem;font-weight:800;color:var(--t1);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
          </div>
          <div id="tripOvSub" style="font-size:.72rem;color:var(--t4);margin-top:1px;"></div>
        </div>
      </div>
      <div id="tripOvBody" style="padding:16px 18px;"></div>
      <button class="ss-close-btn" onclick="closeOv('tripOv')">إغلاق</button>
    </div>
  </div>

  <!-- Uncle profile drawer -->
  <div class="overlay settings-overlay" id="uncleOv">
    <div class="settings-sheet" style="padding-bottom:max(20px,env(safe-area-inset-bottom));">
      <div id="uncleOvContent"></div>
      <button class="ss-close-btn" onclick="closeOv('uncleOv')" style="margin:12px 16px 0;">إغلاق</button>
    </div>
  </div>

  <!-- ══ FULL-SCREEN EXAM ══ -->
  <div id="examScreen"
    style="display:none;position:fixed;inset:0;z-index:800;background:var(--bg);overflow-y:auto;-webkit-overflow-scrolling:touch;">

    <!-- ① Start / confirmation view -->
    <div id="examStartView"
      style="display:none;flex-direction:column;align-items:center;justify-content:center;padding:20px 16px;min-height:100vh;">
      <div style="width:100%;max-width:460px;">
        <div class="exam-start-card">
          <div class="exam-start-hero">
            <div class="exam-start-icon"><i class="fas fa-pen-nib"></i></div>
            <div class="exam-start-title" id="startTitle"></div>
            <div class="exam-start-sub" id="startSub"></div>
          </div>
          <div id="startMeta" style="padding:16px 18px;display:flex;flex-direction:column;gap:8px;"></div>
          <div style="padding:0 18px 20px;display:flex;gap:10px;">
            <button class="btn btn-g" style="flex:1;" onclick="exitExamScreen()"><i class="fas fa-chevron-right"></i>
              رجوع</button>
            <button class="btn btn-p" id="examStartBtn" style="flex:2;padding:12px;font-size:.97rem;"
              onclick="beginExam()"><i class="fas fa-play-circle"></i> ابدأ الاختبار</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ② Active exam view -->
    <div id="examActiveView" style="display:none;flex-direction:column;min-height:100vh;position:relative;">
      <div class="exam-hdr">
        <div class="exam-hdr-inner">
          <button class="exam-back-btn" onclick="confirmExitExam()"><i class="fas fa-chevron-right"
              style="font-size:.78rem;"></i></button>
          <div style="flex:1;min-width:0;">
            <div class="exam-hdr-title" id="examHeaderTitle"></div>
            <div class="exam-hdr-sub" id="examHeaderSub"></div>
          </div>
          <div class="exam-timer" id="examTimerBadge"></div>
        </div>
        <div class="exam-prog-track">
          <div class="exam-prog-fill" id="examProgBar"></div>
        </div>
      </div>
      <div class="exam-questions">
        <div id="examQList"></div>
      </div>
      <div class="exam-footer">
        <div class="exam-footer-inner">
          <div class="exam-ans-count">
            <i class="fas fa-check-circle" style="color:var(--ok);"></i>
            <strong id="examAnsDone">0</strong> / <span id="examTotalQ">0</span> سؤال
          </div>
          <div id="examQNav" style="display:flex;gap:4px;flex-wrap:wrap;flex:1;justify-content:center;padding:0 8px;">
          </div>
          <button class="btn btn-p" style="padding:11px 26px;font-size:.93rem;" onclick="submitExam()">
            <i class="fas fa-paper-plane"></i> تسليم
          </button>
        </div>
      </div>
    </div>

    <!-- ③ Result view -->
    <div id="examResultView"
      style="display:none;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;min-height:100dvh;width:100%;padding:20px 16px;box-sizing:border-box;">
      <div class="exam-result-wrap" style="width:100%;max-width:480px;margin:auto;">
        <div id="examResultCard"></div>
      </div>
    </div>

  </div>

  <!-- ══ INTERNAL CONFIRM MODALS (no browser dialogs) ══ -->
  <!-- Submit with unanswered questions -->
  <div class="overlay" id="submitConfirmModal" style="z-index:1200;">
    <div class="modal narrow" style="max-width:360px;">
      <div class="mhdr" style="background:linear-gradient(135deg,#d97706,#b45309);">
        <div
          style="width:36px;height:36px;border-radius:var(--r-sm);background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.2rem;color:#fff;">
          <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="mhdr-title" style="flex:1;">تأكيد التسليم</div>
        <button class="mclose" onclick="_closeSubmitConfirm()"><i class="fas fa-times"></i></button>
      </div>
      <div class="mbody" style="text-align:center;padding:22px 18px 14px;">
        <div id="scModalMsg"
          style="font-size:.93rem;font-weight:700;color:var(--t1);line-height:1.6;margin-bottom:18px;"></div>
        <div style="display:flex;flex-direction:column;gap:10px;">
          <button class="btn btn-p" style="width:100%;padding:12px 16px;font-size:.92rem;display:flex;align-items:center;justify-content:center;gap:8px;" onclick="_reviewUnansweredQuestions()">
            <i class="fas fa-pencil-alt"></i> إكمال الأسئلة المتبقية
          </button>
          <button class="btn btn-g" style="width:100%;padding:10px 16px;font-size:.84rem;color:var(--t3);display:flex;align-items:center;justify-content:center;gap:8px;"
            onclick="_confirmSubmitExam()">
            <i class="fas fa-paper-plane"></i> تسليم على أي حال بدون إكمالها
          </button>
        </div>
      </div>
    </div>
  </div>
  <!-- Exit exam with saved answers -->
  <div class="overlay" id="exitConfirmModal" style="z-index:1200;">
    <div class="modal narrow" style="max-width:360px;">
      <div class="mhdr" style="background:linear-gradient(135deg,var(--brand),var(--cou));">
        <div
          style="width:36px;height:36px;border-radius:var(--r-sm);background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.2rem;color:#fff;">
          <i class="fas fa-question-circle"></i>
        </div>
        <div class="mhdr-title" style="flex:1;">الخروج من الاختبار</div>
        <button class="mclose" onclick="_closeExitConfirm()"><i class="fas fa-times"></i></button>
      </div>
      <div class="mbody" style="text-align:center;padding:22px 18px 14px;">
        <div style="font-size:.93rem;font-weight:700;color:var(--t1);line-height:1.6;margin-bottom:18px;">إجاباتك محفوظة
          تلقائياً. هل تريد الخروج؟</div>
        <div style="display:flex;gap:10px;">
          <button class="btn btn-g" style="flex:1;" onclick="_closeExitConfirm()"><i class="fas fa-times"></i>
            بقاء</button>
          <button class="btn btn-p" style="flex:1;" onclick="_confirmExit()"><i class="fas fa-sign-out-alt"></i>
            خروج</button>
        </div>
      </div>
    </div>
  </div>

  <div class="tc" id="tc"></div>

  <script>
    'use strict';
    // ── Config ────────────────────────────────────────────────────────
    const URL_ID = (() => { const m = location.search.match(/[?&]id=(\d+)/); return m ? parseInt(m[1]) : null; })();
    const URL_TEMPID = (() => { const m = location.search.match(/[?&]tempid=([^&]+)/); return m ? decodeURIComponent(m[1]) : null; })();
    const IS_UNCLE_LOGGED_IN = <?php echo json_encode($isUncleLoggedIn); ?>;
    const TARGET_TRIP_ID = <?php echo json_encode($targetTripId); ?>;
    const _creds = localStorage.getItem('rememberMe') === 'true' && !!localStorage.getItem('savedUsername') && !!localStorage.getItem('savedPassword');
    const IS_PUBLIC = !!(URL_ID && !_creds);
    const IS_GUEST = !!(URL_ID && !_creds && !IS_UNCLE_LOGGED_IN);
    const API_URL = (() => {
      const segs = location.pathname.replace(/\/[^/]*$/, '').split('/').filter(Boolean);
      return segs.map(() => '../').join('') + 'api.php';
    })();
    const LETTERS = ['أ', 'ب', 'ج', 'د', 'هـ'];
    // attendance_day: DB 1=Mon…7=Sun → JS getDay() 0=Sun 1=Mon…6=Sat
    const DB_TO_JSDAY = { 1: 1, 2: 2, 3: 3, 4: 4, 5: 5, 6: 6, 7: 0 };
    const DAY_NAMES = { 0: 'الأحد', 1: 'الاثنين', 2: 'الثلاثاء', 3: 'الأربعاء', 4: 'الخميس', 5: 'الجمعة', 6: 'السبت' };

    // ── State ─────────────────────────────────────────────────────────
    let student = null, allAccounts = [], selAccId = null;
    let churchDay = 5;
    let customFields = null;
    let cropper = null, croppedBlob = null;
    let allTrips = [], allTasks = [];
    let curTask = null, taskAnswers = {}, examDone = false;
    let birthdayGreetingStudent = null;
    let maxFetchedTaskAnnId = 0;


    function onDOMReady(fn) {
      if (document.readyState === 'interactive' || document.readyState === 'complete') {
        setTimeout(fn, 0);
      } else {
        document.addEventListener('DOMContentLoaded', fn);
      }
    }

    // ── Boot ──────────────────────────────────────────────────────────
    onDOMReady(async () => {
      if (URL_TEMPID) {
        initTempIdAssignment(URL_TEMPID);
        return;
      }
      if (!IS_PUBLIC) {
        localStorage.setItem('lastVisitedPortal', 'kid');
      }
      if (IS_PUBLIC) {
        // Hide private tabs in public mode
        ['send', 'tasks', 'family'].forEach(tab => {
          const el = document.querySelector(`.bottom-nav-item[data-tab="${tab}"]`);
          if (el) el.style.display = 'none';
        });
      }
      if (_creds && URL_ID) {
        await initPrivate();
        const matchedAccount = allAccounts.find(a => Number(a.id) === Number(URL_ID));
        if (matchedAccount) {
          student = matchedAccount;
          localStorage.setItem('activeKidAccountId', String(matchedAccount.id));
          renderPrivate(student);
          switchTab(getInitialTab());
          loadSiblings();
          document.getElementById('bottomNavBar').style.display = 'flex';
          syncPassOverlay();
          if (allAccounts.length > 1) {
            document.getElementById('switchBtnTop').style.display = 'flex';
            renderAccountSwitcher();
          }
        } else {
          await openFriendProfile(URL_ID, false);
        }
      }
      else if (IS_PUBLIC && URL_ID) await initPublic(URL_ID);
      else if (_creds) await initPrivate();
      else if (URL_ID) await initPublic(URL_ID);
      else noProfile('يرجى تسجيل الدخول أو استخدام رابط المعرّف');
      setupOvClose();
      if (!IS_PUBLIC && _creds) {
        _initPushNotifications();
      }
    });

    async function api(p) {
      const fd = new FormData();
      for (const [k, v] of Object.entries(p)) if (v !== null && v !== undefined) fd.append(k, v);
      const r = await fetch(API_URL, { method: 'POST', body: fd, credentials: 'include' });
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    }

    // NOTE: Standardized intelligent search helper functions.
    // Centralized library can be found at /js/search_intelligent.js
    function normalizeArabic(text) {
      if (!text) return "";
      return String(text)
        .replace(/[أإآٱ]/g, "ا")
        .replace(/[ىئ]/g, "ي")
        .replace(/ة/g, "ه")
        .replace(/ؤ/g, "و")
        .replace(/[\u064B-\u0652]/g, "") // Remove Harakat
        .toLowerCase()
        .trim();
    }

    function francoToArabic(text) {
      if (!text) return "";
      let s = text.toLowerCase().trim();
      if (!/[a-z0-9]/.test(s)) return "";
      const multiMap = [
        ['sh', 'ش'], ['ch', 'تش'], ['kh', 'خ'], ['gh', 'غ'],
        ['th', 'ث'], ['dh', 'ذ'], ['zh', 'ج'],
        ['ph', 'ف'],
        ['ou', 'و'], ['oo', 'و'], ['ee', 'ي'], ['ei', 'اي'],
        ['aa', 'ا'], ['ii', 'ي'],
      ];
      for (const [from, to] of multiMap) {
        s = s.split(from).join(to);
      }
      const singleMap = {
        'a': 'ا', 'b': 'ب', 't': 'ت', 'g': 'ج', 'j': 'ج',
        'h': 'ح', 'd': 'د', 'r': 'ر', 'z': 'ز', 's': 'س',
        'c': 'ك',
        'f': 'ف', 'q': 'ق', 'k': 'ك', 'l': 'ل', 'm': 'م',
        'n': 'ن', 'w': 'و', 'u': 'و', 'o': 'و',
        'y': 'ي', 'i': 'ي', 'e': 'ي',
        'x': 'اكس', 'v': 'ف', 'p': 'ب',
        '2': 'ء', '3': 'ع', '4': 'ش', '5': 'خ',
        '6': 'ط', '7': 'ح', '8': 'غ', '9': 'ق',
      };
      let result = '';
      for (let i = 0; i < s.length; i++) {
        const ch = s[i];
        if (singleMap[ch]) {
          result += singleMap[ch];
        } else if (ch === ' ' || ch === '-' || ch === '_') {
          result += ' ';
        } else {
          result += ch;
        }
      }
      return normalizeArabic(result);
    }

    function arabicToLatin(text) {
      if (!text) return "";
      let s = text.toLowerCase().trim();
      if (!/[\u0600-\u06FF]/.test(s)) return "";
      s = normalizeArabic(s);
      const multiMap = [
        ['ش', 'sh'], ['خ', 'kh'], ['غ', 'gh'], ['ث', 'th'],
        ['ذ', 'dh'], ['ج', 'g'], ['ف', 'f'], ['ع', '3'],
        ['ط', '6'], ['ح', '7']
      ];
      for (const [from, to] of multiMap) {
        s = s.split(from).join(to);
      }
      const singleMap = {
        'ا': 'a', 'ب': 'b', 'ت': 't', 'ة': 'a',
        'د': 'd', 'ر': 'r', 'ز': 'z', 'س': 's', 'ص': 's', 'ض': 'd',
        'ق': 'q', 'ك': 'k', 'ل': 'l', 'م': 'm', 'ن': 'n',
        'ه': 'h', 'و': 'w', 'ي': 'y', 'ى': 'y', 'ئ': 'e', 'ء': '2', 'ؤ': 'o'
      };
      let result = '';
      for (let i = 0; i < s.length; i++) {
        const ch = s[i];
        if (singleMap[ch]) {
          result += singleMap[ch];
        } else if (ch === ' ' || ch === '-' || ch === '_') {
          result += ' ';
        } else {
          result += ch;
        }
      }
      return result;
    }

    function phoneticClean(str) {
      if (!str) return "";
      let s = str.toLowerCase().trim();
      s = s.replace(/p/g, 'b');
      s = s.replace(/v/g, 'f');
      s = s.replace(/c/g, 'k');
      s = s.replace(/q/g, 'k');
      s = s.replace(/j/g, 'g');
      s = s.replace(/z/g, 's');
      s = s.replace(/x/g, 'ks');
      s = s.replace(/[aeiouywh]/g, '');
      return s;
    }

    function getMatchScore(student, query) {
      const dbId = String(student.id || student._studentId || '');
      const queryClean = query.trim();
      if (dbId && queryClean && dbId === queryClean) {
        return 10000;
      }

      const qNormalized = normalizeArabic(query);
      const qRaw = query.trim().toLowerCase();
      const qFranco = francoToArabic(query);
      const qLatin = arabicToLatin(query);
      const qPhonetic = phoneticClean(query.includes(' ') ? query : (qLatin || qRaw));

      let maxScore = 0;
      const fields = [
        { val: student.name, weight: 1.0 },
        { val: student.class, weight: 0.7 },
        { val: student.id, weight: 1.1 },
        { val: student.phone, weight: 1.1 }
      ];

      fields.forEach(field => {
        if (!field.val) return;
        const target = String(field.val);
        const tNormalized = normalizeArabic(target);
        const tRaw = target.toLowerCase();
        const tLatin = arabicToLatin(target);
        const tPhonetic = phoneticClean(tLatin || tRaw);
        let currentScore = 0;

        if (tRaw === qRaw || tNormalized === qNormalized) currentScore = 100;
        else if (tRaw.startsWith(qRaw) || tNormalized.startsWith(qNormalized)) currentScore = 80;
        else if (tRaw.includes(qRaw) || tNormalized.includes(qNormalized)) currentScore = 60;
        else if (qFranco && tNormalized === qFranco) currentScore = 92;
        else if (qFranco && tNormalized.startsWith(qFranco)) currentScore = 72;
        else if (qFranco && tNormalized.includes(qFranco)) currentScore = 52;
        else if (qLatin && tRaw === qLatin) currentScore = 92;
        else if (qLatin && tRaw.startsWith(qLatin)) currentScore = 72;
        else if (qLatin && tRaw.includes(qLatin)) currentScore = 52;
        else if (tLatin && tLatin === qRaw) currentScore = 90;
        else if (tLatin && tLatin.startsWith(qRaw)) currentScore = 70;
        else if (tLatin && tLatin.includes(qRaw)) currentScore = 50;
        else if (qPhonetic && tPhonetic && tPhonetic === qPhonetic) currentScore = 88;
        else if (qPhonetic && tPhonetic && tPhonetic.startsWith(qPhonetic)) currentScore = 68;
        else if (qPhonetic && tPhonetic && tPhonetic.includes(qPhonetic)) currentScore = 48;
        else {
          let score = 0, queryIdx = 0;
          for (let i = 0; i < tNormalized.length && queryIdx < qNormalized.length; i++) {
            if (tNormalized[i] === qNormalized[queryIdx]) { queryIdx++; score++; }
          }
          if (queryIdx === qNormalized.length) currentScore = (score / tNormalized.length) * 40;

          if (qFranco) {
            let fScore = 0, fIdx = 0;
            for (let i = 0; i < tNormalized.length && fIdx < qFranco.length; i++) {
              if (tNormalized[i] === qFranco[fIdx]) { fIdx++; fScore++; }
            }
            if (fIdx === qFranco.length) {
              currentScore = Math.max(currentScore, (fScore / tNormalized.length) * 38);
            }
          }
        }

        const weighted = currentScore * field.weight;
        if (weighted > maxScore) maxScore = weighted;
      });

      return maxScore;
    }

    async function initTempIdAssignment(tempid) {
      hideLoad(); // Hide loading screen
      const container = document.getElementById('tempIdAssignContainer');
      container.style.display = 'block';

      if (!IS_UNCLE_LOGGED_IN) {
        // Try restoring session
        const cl = localStorage.getItem('loggedIn') === 'true';
        const ul = localStorage.getItem('uncleLoggedIn') === 'true';
        if (cl || ul) {
          showLoad('استعادة الجلسة...');
          try {
            const fd = new FormData();
            fd.append('action', 'restore_session');
            if (cl) fd.append('church_code', localStorage.getItem('churchCode'));
            else fd.append('username', localStorage.getItem('uncleUsername'));
            
            const r = await fetch(API_URL, { method: 'POST', body: fd, credentials: 'include' }).then(res => res.json());
            if (r.success) {
              location.reload();
              return;
            }
          } catch(e) {}
          hideLoad();
        }

        container.innerHTML = `
          <div style="background:#fff; padding:24px; border-radius:16px; box-shadow:var(--sh-md); text-align:center; max-width:450px; margin:40px auto; border: 1.5px solid var(--bdr);">
            <div style="font-size:3.5rem; color:var(--brand); margin-bottom:16px;"><i class="fas fa-qrcode"></i></div>
            <h2 style="font-size:1.4rem; font-weight:800; color:var(--t1); margin-bottom:12px; font-family:'Baloo Bhaijaan 2', sans-serif;">كارت غير مسجل</h2>
            <p style="font-size:0.95rem; color:var(--t3); line-height:1.6; margin-bottom:24px;">هذا الكود (ID: <strong>${esc(tempid)}</strong>) غير مرتبط بأي طفل حالياً. يرجى تسجيل الدخول كخادم للتمكن من ربطه بطفل.</p>
            <a href="/login/?redirect=${encodeURIComponent(location.href)}" class="btn" style="display:inline-flex; align-items:center; gap:8px; width:100%; justify-content:center; padding:12px; background:var(--brand); color:#fff; border-radius:10px; text-decoration:none; font-weight:700; box-shadow:var(--sh-brand); font-family:inherit;">
              <i class="fas fa-sign-in-alt"></i> تسجيل دخول الخادم
            </a>
          </div>
        `;
        return;
      }

      showLoad('تحميل البيانات...');
      let classes = [];
      let churchSettings = null;
      let allChurches = [];
      try {
        const [dClasses, dSettings, dChurches] = await Promise.all([
          api({ action: 'getChurchClasses' }),
          api({ action: 'getChurchSettings' }),
          api({ action: 'getAllChurches' })
        ]);
        if (dClasses.success) classes = dClasses.data || dClasses.classes || [];
        if (dSettings.success) churchSettings = dSettings.settings || {};
        if (dChurches.success) allChurches = dChurches.churches || [];
      } catch (e) {
        console.error(e);
      }
      hideLoad();

      window.allChurches = allChurches;

      let churchCustomFields = [];
      if (churchSettings && (churchSettings.custom_fields || churchSettings.custom_field)) {
        const cf = churchSettings.custom_fields || churchSettings.custom_field;
        churchCustomFields = Array.isArray(cf) ? cf : [cf];
      }

      renderTempIdAssignmentUI(tempid, classes, churchCustomFields, churchSettings);
    }

    function renderTempIdAssignmentUI(tempid, classes, churchCustomFields, churchSettings) {
      const container = document.getElementById('tempIdAssignContainer');

      container.innerHTML = `
        <div style="background:#fff; padding:24px; border-radius:20px; box-shadow:var(--sh-md); border:1.5px solid var(--bdr); margin:20px auto;">
          <div style="display:flex; align-items:center; gap:12px; border-bottom:2px dashed var(--bdr); padding-bottom:16px; margin-bottom:20px;">
            <div style="font-size:2rem; color:var(--brand);"><i class="fas fa-qrcode"></i></div>
            <div>
              <h2 style="margin:0; font-size:1.25rem; font-weight:800; color:var(--t1);">ربط الكود بالكارت الذكي</h2>
              <div style="font-size:0.8rem; color:var(--t3); margin-top:2px;">كود الكارت: <strong>${esc(tempid)}</strong></div>
            </div>
          </div>

          <!-- Tab Switcher -->
          <div style="display:flex; border-bottom:1px solid var(--bdr2); margin-bottom:20px;">
            <button id="tabBtnExisting" onclick="switchAssignTab('existing')" style="flex:1; padding:10px; background:none; border:none; border-bottom:2px solid var(--brand); color:var(--brand); font-weight:800; font-family:inherit; cursor:pointer; font-size:0.95rem;">ربط بطفل مسجل</button>
            <button id="tabBtnNew" onclick="switchAssignTab('new')" style="flex:1; padding:10px; background:none; border:none; border-bottom:2px solid transparent; color:var(--t3); font-weight:800; font-family:inherit; cursor:pointer; font-size:0.95rem;">إضافة طفل جديد</button>
          </div>

          <!-- Tab 1: Link Existing -->
          <div id="assignTabExisting" style="display:block;">
            <div style="margin-bottom:16px; position:relative;">
              <label style="display:block; font-size:0.85rem; font-weight:800; color:var(--t2); margin-bottom:6px;">ابحث عن اسم الطفل أو رقم الهاتف:</label>
              <div style="position:relative;">
                <input type="text" id="assignSearchInput" placeholder="اكتب اسم الطفل للبحث..." style="width:100%; padding:12px 14px 12px 38px; border:1.5px solid var(--bdr); border-radius:10px; font-family:inherit; font-size:0.9rem; outline:none;" oninput="onAssignSearchChange(this.value)">
                <i class="fas fa-search" style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--t4);"></i>
              </div>
              <div id="assignSearchResults" style="display:none; position:absolute; right:0; left:0; background:#fff; border:1.5px solid var(--bdr); border-radius:10px; margin-top:6px; max-height:220px; overflow-y:auto; box-shadow:var(--sh-md); z-index:99;"></div>
            </div>
            
            <div id="selectedStudentInfo" style="display:none; background:var(--brand-bg); border:1px solid var(--brand-l); padding:14px; border-radius:12px; margin-bottom:20px; align-items:center; gap:12px;">
              <div style="font-size:1.8rem; color:var(--brand);"><i class="fas fa-user"></i></div>
              <div style="flex:1;">
                <div id="selStudentName" style="font-weight:800; color:var(--t1); font-size:0.95rem;"></div>
                <div id="selStudentDetails" style="font-size:0.8rem; color:var(--t3); margin-top:2px;"></div>
              </div>
              <button onclick="clearSelectedAssignStudent()" style="background:none; border:none; color:var(--err); cursor:pointer; font-size:1rem;"><i class="fas fa-times-circle"></i></button>
            </div>

            <button onclick="submitAssignExisting('${esc(tempid)}')" class="btn" style="width:100%; padding:12px; background:var(--brand); color:#fff; border:none; border-radius:10px; font-weight:800; font-family:inherit; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; box-shadow:var(--sh-brand);">
              <i class="fas fa-link"></i> ربط الكارت بالطفل المختار
            </button>
          </div>

          <!-- Tab 2: Add New Kid -->
          <div id="assignTabNew" style="display:none;">
            <!-- Church Search Input -->
            <div style="margin-bottom:12px; position:relative;">
              <label style="display:block; font-size:0.85rem; font-weight:800; color:var(--t2); margin-bottom:6px;">الكنيسة التابع لها الطفل *</label>
              <div style="position:relative;">
                <input type="text" id="newKidChurchSearchInput" placeholder="اكتب اسم الكنيسة للبحث..." style="width:100%; padding:10px 12px; border:1.5px solid var(--bdr); border-radius:10px; font-family:inherit; font-size:0.9rem; outline:none;" oninput="onNewKidChurchSearchChange(this.value)">
                <i class="fas fa-church" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--t4);"></i>
              </div>
              <div id="newKidChurchSearchResults" style="display:none; position:absolute; right:0; left:0; background:#fff; border:1.5px solid var(--bdr); border-radius:10px; margin-top:6px; max-height:200px; overflow-y:auto; box-shadow:var(--sh-md); z-index:999;"></div>
              <input type="hidden" id="newKidChurchId">
            </div>

            <!-- Form fields wrapper (initially hidden until church is selected) -->
            <div id="newKidFormFields" style="display:none; flex-direction:column; gap:12px; border-top:1px dashed var(--bdr); padding-top:16px; margin-top:12px;">
              <!-- Photo Upload Area -->
              <div style="display:flex; flex-direction:column; align-items:center; gap:8px;">
                <label style="align-self:flex-start; font-size:0.85rem; font-weight:800; color:var(--t2);">الصورة الشخصية</label>
                <div onclick="document.getElementById('newKidPhotoInput').click()" style="width:80px; height:80px; border-radius:50%; border:2px dashed var(--brand); display:flex; align-items:center; justify-content:center; cursor:pointer; overflow:hidden; background:#f9fafb; position:relative;">
                  <img id="newKidPhotoPreview" style="display:none; width:100%; height:100%; object-fit:cover;">
                  <div id="newKidPhotoPlaceholder" style="color:var(--brand); font-size:1.5rem;"><i class="fas fa-user-plus"></i></div>
                </div>
                <input type="file" id="newKidPhotoInput" accept="image/*" style="display:none;" onchange="handleNewKidPhotoChange(event)">
              </div>

              <!-- Name & Gender -->
              <div style="display:flex; gap:12px;">
                <div style="flex:1;">
                  <label style="display:block; font-size:0.85rem; font-weight:800; color:var(--t2); margin-bottom:4px;">الاسم *</label>
                  <input type="text" id="newKidName" placeholder="الاسم رباعي" style="width:100%; padding:10px 12px; border:1.5px solid var(--bdr); border-radius:10px; font-family:inherit; font-size:0.9rem; outline:none;">
                </div>
                <div style="width:100px;">
                  <label style="display:block; font-size:0.85rem; font-weight:800; color:var(--t2); margin-bottom:4px;">النوع *</label>
                  <select id="newKidGender" style="width:100%; padding:10px; border:1.5px solid var(--bdr); border-radius:10px; font-family:inherit; font-size:0.9rem; outline:none; background:#fff;">
                    <option value="male">ولد</option>
                    <option value="female">بنت</option>
                  </select>
                </div>
              </div>

              <!-- Class Selection -->
              <div>
                <label style="display:block; font-size:0.85rem; font-weight:800; color:var(--t2); margin-bottom:4px;">الفصل *</label>
                <select id="newKidClassId" style="width:100%; padding:10px; border:1.5px solid var(--bdr); border-radius:10px; font-family:inherit; font-size:0.9rem; outline:none; background:#fff;">
                  <option value="">اختر الفصل</option>
                </select>
              </div>

              <!-- Address & Phone & Emergency Phone -->
              <div>
                <label style="display:block; font-size:0.85rem; font-weight:800; color:var(--t2); margin-bottom:4px;">العنوان</label>
                <input type="text" id="newKidAddress" placeholder="العنوان بالتفصيل" style="width:100%; padding:10px 12px; border:1.5px solid var(--bdr); border-radius:10px; font-family:inherit; font-size:0.9rem; outline:none;">
              </div>

              <div style="display:flex; flex-direction:column; gap:12px;">
                <div>
                  <label style="display:block; font-size:0.85rem; font-weight:800; color:var(--t2); margin-bottom:4px;">رقم التليفون</label>
                  <input type="tel" id="newKidPhone" placeholder="01........." style="width:100%; padding:10px 12px; border:1.5px solid var(--bdr); border-radius:10px; font-family:inherit; font-size:0.9rem; outline:none;">
                </div>

                <div>
                  <label style="display:block; font-size:0.85rem; font-weight:800; color:var(--t2); margin-bottom:4px;">تليفون الطوارئ</label>
                  <input type="tel" id="newKidEmergencyPhone" placeholder="01........." style="width:100%; padding:10px 12px; border:1.5px solid var(--bdr); border-radius:10px; font-family:inherit; font-size:0.9rem; outline:none;">
                </div>
              </div>

              <!-- Birthday & Coupons -->
              <div style="display:flex; gap:12px;">
                <div style="flex:1;">
                  <label style="display:block; font-size:0.85rem; font-weight:800; color:var(--t2); margin-bottom:4px;">تاريخ الميلاد</label>
                  <input type="text" id="newKidBirthday" placeholder="DD/MM/YYYY" style="width:100%; padding:10px 12px; border:1.5px solid var(--bdr); border-radius:10px; font-family:inherit; font-size:0.9rem; outline:none;">
                </div>
                <div style="flex:1;">
                  <label style="display:block; font-size:0.85rem; font-weight:800; color:var(--t2); margin-bottom:4px;">كوبونات ابتدائية</label>
                  <input type="number" id="newKidCoupons" value="0" min="0" style="width:100%; padding:10px 12px; border:1.5px solid var(--bdr); border-radius:10px; font-family:inherit; font-size:0.9rem; outline:none;">
                </div>
              </div>

              <!-- Medical Notes -->
              <div>
                <label style="display:block; font-size:0.85rem; font-weight:800; color:var(--t2); margin-bottom:4px;">ملاحظات طبية</label>
                <textarea id="newKidMedicalNotes" rows="2" placeholder="أي مشاكل صحية أو ملاحظات طبية..." style="width:100%; padding:10px 12px; border:1.5px solid var(--bdr); border-radius:10px; font-family:inherit; font-size:0.9rem; outline:none; resize:vertical;"></textarea>
              </div>

              <!-- Custom Info Fields Container according to selected church -->
              <div id="newKidCustomFieldsContainer" style="display:none; flex-direction:column; gap:12px; background:var(--brand-bg); padding:12px; border-radius:12px; border:1px solid var(--brand-l);">
                <div style="font-size:0.85rem; font-weight:800; color:var(--brand); border-bottom:1px solid var(--brand-l); padding-bottom:6px; margin-bottom:6px;"><i class="fas fa-list-ul"></i> بيانات إضافية خاصة بالكنيسة</div>
                <div id="newKidCustomFieldsList" style="display:flex; flex-direction:column; gap:8px;"></div>
              </div>

              <button onclick="submitCreateAndAssign('${esc(tempid)}')" class="btn" style="width:100%; padding:12px; background:var(--brand); color:#fff; border:none; border-radius:10px; font-weight:800; font-family:inherit; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; box-shadow:var(--sh-brand); margin-top:8px;">
                <i class="fas fa-plus"></i> إضافة الطفل وربط الكارت
              </button>
            </div>
          </div>
        </div>
      `;
    }



    let selectedAssignStudentId = null;

    window.onAssignSearchChange = async function(q) {
      const resultsDiv = document.getElementById('assignSearchResults');
      if (!q.trim()) {
        resultsDiv.style.display = 'none';
        return;
      }
      try {
        const payload = { action: 'searchAllStudents', query: q };
        const queryAr = francoToArabic(q);
        if (queryAr) {
          payload.query_ar = queryAr;
        }

        const d = await api(payload);
        if (d.success && d.students && d.students.length > 0) {
          let students = d.students || [];
          students = students.map(s => ({ ...s, _score: getMatchScore(s, q) }))
                             .sort((a, b) => b._score - a._score);

          resultsDiv.innerHTML = students.map(s => {
            const isExactIdMatch = String(s.id).trim() === q.trim();
            return `
            <div onclick="selectAssignStudent(${s.id}, '${esc(s.name)}', '${esc(s.class || '')}')" style="padding:10px 12px; border-bottom:1px solid var(--bdr2); cursor:pointer; transition:background 0.2s;" onmouseover="this.style.background='var(--brand-bg)'" onmouseout="this.style.background='none'">
              <div style="font-weight:700; font-size:0.88rem; color:var(--t1); display:flex; align-items:center; gap:6px;">
                <span>${esc(s.name)}</span>
                ${isExactIdMatch ? `<span style="background:var(--brand); color:#fff; font-size:0.6rem; padding:2px 6px; border-radius:4px; font-weight:bold; white-space:nowrap;">ID: ${s.id}</span>` : ''}
              </div>
              <div style="font-size:0.75rem; color:var(--t3); margin-top:2px;">الكنيسة: ${esc(s.church_name || '')} - الفصل: ${esc(s.class || '')}</div>
            </div>`;
          }).join('');
          resultsDiv.style.display = 'block';
        } else {
          resultsDiv.innerHTML = `<div style="padding:12px; color:var(--t4); text-align:center; font-size:0.85rem;">لم يتم العثور على أطفال</div>`;
          resultsDiv.style.display = 'block';
        }
      } catch (e) {
        console.error(e);
      }
    };

    window.selectAssignStudent = function(id, name, className) {
      selectedAssignStudentId = id;
      document.getElementById('assignSearchResults').style.display = 'none';
      document.getElementById('assignSearchInput').value = '';
      
      const infoBox = document.getElementById('selectedStudentInfo');
      document.getElementById('selStudentName').textContent = name;
      document.getElementById('selStudentDetails').textContent = className ? `الفصل: ${className}` : '';
      infoBox.style.display = 'flex';
    };

    window.clearSelectedAssignStudent = function() {
      selectedAssignStudentId = null;
      document.getElementById('selectedStudentInfo').style.display = 'none';
    };

    window.submitAssignExisting = async function(tempid) {
      if (!selectedAssignStudentId) {
        alert('يرجى اختيار طفل أولاً');
        return;
      }
      showLoad('جاري ربط الكارت بالطفل...');
      try {
        const d = await api({
          action: 'linkTempIdToStudent',
          student_id: selectedAssignStudentId,
          tempid: tempid,
          trip_id: TARGET_TRIP_ID
        });
        hideLoad();
        if (d.success) {
          alert('تم ربط الكارت بالطفل بنجاح!');
          window.location.href = `/user/profile/?id=${selectedAssignStudentId}`;
        } else {
          alert(d.message || 'فشل في ربط الكارت');
        }
      } catch (e) {
        hideLoad();
        alert('حدث خطأ في الاتصال');
      }
    };

    window.switchAssignTab = function(tab) {
      const tabBtnExisting = document.getElementById('tabBtnExisting');
      const tabBtnNew = document.getElementById('tabBtnNew');
      const assignTabExisting = document.getElementById('assignTabExisting');
      const assignTabNew = document.getElementById('assignTabNew');
      if (tab === 'existing') {
        tabBtnExisting.style.borderBottomColor = 'var(--brand)';
        tabBtnExisting.style.color = 'var(--brand)';
        tabBtnNew.style.borderBottomColor = 'transparent';
        tabBtnNew.style.color = 'var(--t3)';
        assignTabExisting.style.display = 'block';
        assignTabNew.style.display = 'none';
      } else {
        tabBtnNew.style.borderBottomColor = 'var(--brand)';
        tabBtnNew.style.color = 'var(--brand)';
        tabBtnExisting.style.borderBottomColor = 'transparent';
        tabBtnExisting.style.color = 'var(--t3)';
        assignTabExisting.style.display = 'none';
        assignTabNew.style.display = 'block';
      }
    };

    let currentSelectedChurchCustomFields = [];

    window.onNewKidChurchSearchChange = function(q) {
      const resultsDiv = document.getElementById('newKidChurchSearchResults');
      if (!q.trim()) {
        resultsDiv.style.display = 'none';
        return;
      }
      if (!window.allChurches || window.allChurches.length === 0) {
        resultsDiv.innerHTML = `<div style="padding:10px; color:var(--t4); text-align:center; font-size:0.85rem;">لا توجد كنائس متاحة</div>`;
        resultsDiv.style.display = 'block';
        return;
      }

      let churches = window.allChurches.map(c => ({
        ...c,
        _score: getMatchScore(c, q, [
          { val: c.name || c.church_name || '', weight: 1.0 },
          { val: c.code || c.church_code || '', weight: 1.1 }
        ])
      })).filter(c => c._score > 0).sort((a, b) => b._score - a._score);

      if (churches.length > 0) {
        resultsDiv.innerHTML = churches.map(c => `
          <div onclick="selectNewKidChurch(${c.id}, '${esc(c.name)}')" style="padding:10px 12px; border-bottom:1px solid var(--bdr2); cursor:pointer; transition:background 0.2s;" onmouseover="this.style.background='var(--brand-bg)'" onmouseout="this.style.background='none'">
            <div style="font-weight:700; font-size:0.88rem; color:var(--t1);">${esc(c.name)}</div>
          </div>
        `).join('');
        resultsDiv.style.display = 'block';
      } else {
        resultsDiv.innerHTML = `<div style="padding:10px; color:var(--t4); text-align:center; font-size:0.85rem;">لا توجد كنيسة مطابقة</div>`;
        resultsDiv.style.display = 'block';
      }
    };

    window.selectNewKidChurch = async function(churchId, churchName) {
      document.getElementById('newKidChurchId').value = churchId;
      document.getElementById('newKidChurchSearchInput').value = churchName;
      document.getElementById('newKidChurchSearchResults').style.display = 'none';

      showLoad('تحميل بيانات الكنيسة...');
      try {
        const [dClasses, dSettings] = await Promise.all([
          api({ action: 'getChurchClasses', church_id: churchId }),
          api({ action: 'getChurchSettings', church_id: churchId })
        ]);

        const classSelect = document.getElementById('newKidClassId');
        classSelect.innerHTML = '<option value="">اختر الفصل</option>';
        if (dClasses.success) {
          const classesList = dClasses.data || dClasses.classes || [];
          classesList.forEach(cls => {
            const opt = document.createElement('option');
            opt.value = cls.id;
            opt.textContent = cls.arabic_name;
            classSelect.appendChild(opt);
          });
        }

        const customContainer = document.getElementById('newKidCustomFieldsContainer');
        const customList = document.getElementById('newKidCustomFieldsList');
        customList.innerHTML = '';
        currentSelectedChurchCustomFields = [];

        if (dSettings.success && dSettings.settings) {
          const settings = dSettings.settings || {};
          let cf = settings.custom_fields || settings.custom_field || [];
          if (cf && !Array.isArray(cf)) cf = [cf];
          
          currentSelectedChurchCustomFields = cf;

          if (cf.length > 0) {
            cf.forEach((field, index) => {
              const div = document.createElement('div');
              div.style.display = 'flex';
              div.style.flexDirection = 'column';
              div.style.gap = '4px';

              let iconHtml = '';
              if (field.icon) {
                const iconClass = field.icon.startsWith('fa-') ? 'fas ' + field.icon : field.icon;
                iconHtml = `<i class="${iconClass}" style="opacity:0.7;"></i> `;
              }

              div.innerHTML = `
                <label style="font-size:0.8rem; font-weight:700; color:var(--t2);">${iconHtml}${esc(field.label)}</label>
                <input type="text" id="newKidCustom_${index}" placeholder="${esc(field.placeholder || '')}" style="width:100%; padding:8px 10px; border:1.5px solid var(--bdr); border-radius:8px; font-family:inherit; font-size:0.85rem; outline:none; background:#fff;">
              `;
              customList.appendChild(div);
            });
            customContainer.style.display = 'flex';
          } else {
            customContainer.style.display = 'none';
          }
        } else {
          customContainer.style.display = 'none';
        }

        document.getElementById('newKidFormFields').style.display = 'flex';
      } catch (e) {
        console.error(e);
      }
      hideLoad();
    };

    window.handleNewKidPhotoChange = function(event) {
      const file = event.target.files[0];
      const preview = document.getElementById('newKidPhotoPreview');
      const placeholder = document.getElementById('newKidPhotoPlaceholder');
      if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
          preview.src = e.target.result;
          preview.style.display = 'block';
          placeholder.style.display = 'none';
        };
        reader.readAsDataURL(file);
      } else {
        preview.style.display = 'none';
        placeholder.style.display = 'flex';
      }
    };

    window.submitCreateAndAssign = async function(tempid) {
      const churchId = document.getElementById('newKidChurchId').value;
      const name = document.getElementById('newKidName').value.trim();
      const gender = document.getElementById('newKidGender').value;
      const classId = document.getElementById('newKidClassId').value;
      const address = document.getElementById('newKidAddress').value.trim();
      const phone = document.getElementById('newKidPhone').value.trim();
      const emergencyPhone = document.getElementById('newKidEmergencyPhone').value.trim();
      const birthday = document.getElementById('newKidBirthday').value.trim();
      const coupons = document.getElementById('newKidCoupons').value;
      const medicalNotes = document.getElementById('newKidMedicalNotes').value.trim();
      const photoFile = document.getElementById('newKidPhotoInput').files[0];

      if (!churchId) {
        alert('يرجى اختيار الكنيسة أولاً');
        return;
      }
      if (!name) {
        alert('الاسم مطلوب');
        return;
      }
      if (!classId) {
        alert('الفصل مطلوب');
        return;
      }

      const customInfo = {};
      currentSelectedChurchCustomFields.forEach((field, index) => {
        const inputVal = document.getElementById(`newKidCustom_${index}`).value.trim();
        customInfo[field.key || `church_custom_${index}`] = inputVal;
      });

      showLoad('جاري إضافة الطفل وربط الكارت...');
      try {
        const fd = new FormData();
        fd.append('action', 'addStudent');
        fd.append('church_id', churchId);
        fd.append('name', name);
        fd.append('gender', gender);
        fd.append('classId', classId);
        fd.append('address', address);
        fd.append('phone', phone);
        fd.append('emergency_phone', emergencyPhone);
        fd.append('birthday', birthday);
        fd.append('coupons', coupons);
        fd.append('medical_notes', medicalNotes);
        fd.append('tempid', tempid);
        fd.append('custom_info', JSON.stringify(customInfo));
        if (TARGET_TRIP_ID) {
          fd.append('trip_id', TARGET_TRIP_ID);
        }
        if (photoFile) {
          fd.append('photo', photoFile);
        }

        const res = await fetch(API_URL, {
          method: 'POST',
          body: fd,
          credentials: 'include'
        }).then(r => r.json());

        hideLoad();
        if (res.success) {
          alert('تم إضافة الطفل وربط الكارت بنجاح!');
          window.location.href = `/user/profile/?id=${res.studentId}`;
        } else {
          alert(res.message || 'فشل في إضافة الطفل');
        }
      } catch (e) {
        hideLoad();
        console.error(e);
        alert('حدث خطأ أثناء الاتصال بالخادم');
      }
    };



    // ── Public init ───────────────────────────────────────────────────
    async function initPublic(id) {
      showLoad('جارٍ التحميل…');
      try {
        const d = await api({ action: 'getStudentProfile', studentId: id });
        hideLoad();
        if (d.success && (d.student || d.user)) {
          student = norm(d.student || d.user);
          await loadChurchSettings();
          renderPublic(student); switchTab(getInitialTab()); loadSiblings(); document.getElementById('bottomNavBar').style.display = 'flex';
        } else noProfile('لم يُعثر على الملف الشخصي');
      } catch (e) { hideLoad(); noProfile('خطأ في الاتصال'); }
    }

    // ── Private init ──────────────────────────────────────────────────
    async function initPrivate() {
      showLoad('جارٍ تحميل ملفك…');
      try {
        const d = await api({ action: 'kidLogin', username: localStorage.getItem('savedUsername'), password: localStorage.getItem('savedPassword') });
        hideLoad();
        if (d.success && d.data && d.data.length > 0) {
          const rawAccounts = d.data.map(norm);
          // Restore last active account from localStorage
          const savedId = parseInt(localStorage.getItem('activeKidAccountId') || '0');
          let saved = savedId ? rawAccounts.find(a => a.id === savedId) : null;
          let initialStudent = saved || rawAccounts[0];

          // Filter accounts to only those that share a common phone with initialStudent
          allAccounts = rawAccounts.filter(a => doAccountsShareCommonPhone(initialStudent, a));
          student = initialStudent;
          localStorage.setItem('activeKidAccountId', String(student.id));

          await loadChurchSettings();
          renderPrivate(student);
          switchTab(getInitialTab());
          loadSiblings();
          document.getElementById('bottomNavBar').style.display = 'flex';
          syncPassOverlay();
          if (allAccounts.length > 1) {
            document.getElementById('switchBtnTop').style.display = 'flex';
            renderAccountSwitcher();
          } else {
            const swTop = document.getElementById('switchBtnTop');
            if (swTop) swTop.style.display = 'none';
            const swHero = document.getElementById('heroSwitchTag');
            if (swHero) swHero.style.display = 'none';
            const swBox = document.getElementById('scAccountSwitcher');
            if (swBox) swBox.style.display = 'none';
          }
        } else noProfile('فشل في تحميل الملف الشخصي');
      } catch (e) { hideLoad(); noProfile('خطأ في الاتصال'); }
    }

    async function loadChurchSettings() {
      if (!student?.church_id) return;
      try {
        const d = await api({ action: 'getPublicChurchSettings', church_id: student.church_id });
        if (d.success) {
          churchDay = d.attendance_day || 5;
          customFields = d.custom_fields || null;
        }
      } catch (e) { }
    }

    // ── Normalise ─────────────────────────────────────────────────────
    function norm(s) {
      // The API returns `class` as the resolved class name (COALESCE'd in SQL).
      // '---' is the PHP fallback when all sources are null — treat it as empty.
      const rawCls = s.class || s['الفصل'] || '';
      const cls = (rawCls === '---' || rawCls === '--') ? '' : rawCls;
      return {
        id: s.id || 0,
        name: s.name || s['الاسم'] || '',
        class: cls,
        class_id: s.class_id || s._classId || 0,
        address: s.address || '',
        phone: s.phone || '',
        emergency_phone: s.emergency_phone || s['تليفون الطوارئ'] || '',
        parent_phones: (function () {
          let pp = s.parent_phones || (s.custom_info && typeof s.custom_info === 'object' ? s.custom_info.parent_phones : null);
          if (typeof pp === 'string' && pp.trim()) {
            try { return JSON.parse(pp); } catch (e) { return []; }
          }
          return Array.isArray(pp) ? pp : [];
        })(),
        birthday: s.birthday || '',
        email: s.email || '',
        coupons: parseInt(s.coupons || 0),
        att_coupons: parseInt(s.attendance_coupons || 0),
        com_coupons: parseInt(s.commitment_coupons || 0),
        task_coupons: parseInt(s.task_coupons || 0),
        image_url: s.image_url || '',
        church_name: s.church_name || '',
        church_id: s.church_id || 0,
        church_type: s.church_type || localStorage.getItem('churchType') || 'kids',
        gender: s.gender || '',
        has_password: s.has_password === true || s.has_password === 1 || s.has_password === '1',
        custom_info: s.custom_info ? (typeof s.custom_info === 'string' ? JSON.parse(s.custom_info) : s.custom_info) : null,
        trip_points: (function () { try { if (!s.trip_points) return {}; return (typeof s.trip_points === 'string' ? JSON.parse(s.trip_points) : s.trip_points) || {} } catch (e) { return {} } })(),
        paper_exams: s.paper_exams || [],
      };
    }

    // ── Render public ─────────────────────────────────────────────────
    function renderPublic(s) {
      renderHero(s, false);
      if (IS_GUEST) {
        if (document.getElementById('scInfo')) document.getElementById('scInfo').style.display = 'none';
        if (document.getElementById('pubBanner')) document.getElementById('pubBanner').style.display = 'none';
        if (document.getElementById('statsBar')) document.getElementById('statsBar').style.setProperty('display', 'none', 'important');
        const bNav = document.getElementById('bottomNavBar');
        if (bNav) bNav.style.setProperty('display', 'none', 'important');
        if (document.getElementById('scTrips')) document.getElementById('scTrips').style.display = 'none';
        if (document.getElementById('scAtt')) document.getElementById('scAtt').style.display = 'none';
        if (document.getElementById('scAnn')) document.getElementById('scAnn').style.display = 'none';
        if (document.getElementById('scPaperExams')) document.getElementById('scPaperExams').style.display = 'none';
        const prompt = document.getElementById('guestLoginPrompt');
        if (prompt) {
          prompt.style.display = 'block';
          const btn = document.getElementById('guestLoginBtn');
          if (btn) btn.href = '/user/login?redirect=' + encodeURIComponent(window.location.href);
        }
        showMain();
        syncViewMode();
        return;
      }
      renderInfo(s, true);
      document.getElementById('pubBanner').style.display = 'flex';
      document.getElementById('sbC').textContent = s.coupons;
      document.getElementById('statsBar').style.display = 'grid';
      // hide private stats cells
      ['sbP', 'sbA', 'sbR'].forEach(id => {
        document.getElementById(id).closest('.sb-cell').style.display = 'none';
      });
      loadTrips(false);
      loadAnn();
      loadAtt();
      document.getElementById('scPaperExams').style.display = 'block';
      renderPaperExams(s);
      showMain();
      syncViewMode();
    }

    // ── Render private ────────────────────────────────────────────────
    function renderPrivate(s) {
      renderHero(s, true);
      renderInfo(s, false);
      renderCouponHero(s);
      document.getElementById('couponHero').style.display = 'grid';
      document.getElementById('scAtt').style.display = 'block';
      document.getElementById('scTasks').style.display = 'block';
      document.getElementById('scTrips').style.display = 'block';
      document.getElementById('scPaperExams').style.display = 'block';
      document.getElementById('settingsTop').style.display = 'flex';
      document.getElementById('notifBtnTop').style.display = 'flex';
      document.getElementById('avatarEdit').classList.add('show');

      // edit form prefill & settings sheet sync
      syncSettingsSheet(s);

      document.getElementById('statsBar').style.display = 'grid';
      document.getElementById('sbC').textContent = s.coupons;
      loadAtt();
      loadTasks();
      loadTrips(true);
      loadAnn();
      renderPaperExams(s);
      showMain();
      syncViewMode();
    }

    // ── Sync Settings Sheet & Edit Form ───────────────────────────────
    function syncSettingsSheet(s = student) {
      if (!s) return;
      const ssN = document.getElementById('ssName');
      const ssCls = document.getElementById('ssClass');
      const ssAv = document.getElementById('ssAvatar');
      if (ssN) ssN.textContent = s.name || '—';
      if (ssCls) ssCls.textContent = s.class || '—';
      if (ssAv) {
        if (s.image_url) {
          ssAv.innerHTML = `<img src="${esc(s.image_url)}" alt="${esc(s.name || '')}" onerror="this.parentElement.innerHTML='<i class=\\'fas fa-user\\'></i>'">`;
        } else {
          ssAv.innerHTML = `<i class="fas fa-user"></i>`;
        }
      }

      // Prefill edit form inputs
      const eN = document.getElementById('eN');
      const eA = document.getElementById('eA');
      const eP = document.getElementById('eP');
      const eB = document.getElementById('eB');
      if (eN) eN.value = s.name || '';
      if (eA) eA.value = s.address || '';
      if (eP) eP.value = s.phone || '';
      if (eB) eB.value = s.birthday || '';

      // Reset photo upload overlay
      if (typeof resetPhoto === 'function') {
        resetPhoto();
      }

      // Sync password overlay
      syncPassOverlay();
    }

    // ── Hero ──────────────────────────────────────────────────────────
    function renderHero(s, isPrivate) {
      document.getElementById('hero').style.display = 'flex';
      document.getElementById('heroName').textContent = s.name;
      updateBirthdayGreetingButton(s);
      document.getElementById('heroClassTxt').textContent = s.class || '—';
      if (s.church_name) {
        document.getElementById('churchName').textContent = s.church_name;
        document.getElementById('churchChip').style.display = 'inline-flex';
      }
      if (s.image_url) {
        document.getElementById('avatarInner').innerHTML = `<img src="${esc(s.image_url)}" alt="${esc(s.name)}" onerror="this.parentElement.innerHTML='<i class=\\'fas fa-user\\'></i>'">`;
      } else {
        document.getElementById('avatarInner').innerHTML = `<i class="fas fa-user"></i>`;
      }
      if (!isPrivate) {
        document.getElementById('avatarEdit').style.display = 'none';
      } else {
        document.getElementById('avatarEdit').style.display = 'flex';
      }
      const deleteBtn = document.getElementById('deleteStudentPhotoBtn');
      if (deleteBtn) {
        deleteBtn.style.display = (s.image_url && isPrivate) ? 'flex' : 'none';
      }
      const banner = document.getElementById('profilePicSuggestionBanner');
      if (banner) {
        if (!s.image_url && isPrivate && localStorage.getItem('dismissProfilePicSuggestion') !== 'true') {
          banner.style.display = 'flex';
        } else {
          banner.style.display = 'none';
        }
      }
      // Populate settings sheet
      syncSettingsSheet(s);
      // Load class uncles — always try, API resolves class_id→name server-side
      if (s.church_id && (s.class || s.class_id)) {
        loadClassUncles(s.church_id, s.class || '', s.class_id || 0);
      }
    }

    // ── Coupon Hero ───────────────────────────────────────────────────
    function renderCouponHero(s) {
      // Get all coupon types
      const attCoupons = s.att_coupons || 0;
      const comCoupons = s.com_coupons || 0;
      const taskCoupons = s.task_coupons || 0;
      const totalCoupons = s.coupons || 0;

      document.getElementById('chTotal').textContent = totalCoupons;
      document.getElementById('sbC').textContent = totalCoupons;

      const rows = [
        { icon: 'fa-calendar-check', color: '#6ee7b7', label: 'حضور', val: attCoupons },
        { icon: 'fa-star', color: '#c4b5fd', label: 'التزام', val: comCoupons },
        { icon: 'fa-tasks', color: '#fde68a', label: 'تاسكات', val: taskCoupons }
      ];

      document.getElementById('chBreakdown').innerHTML = rows.map(r => `
    <div class="ch-row"><i class="fas ${r.icon}" style="color:${r.color};"></i>${r.val} ${r.label}</div>
  `).join('');
    }

    // ── Class uncles strip ────────────────────────────────────────────
    async function loadClassUncles(churchId, className, classId) {
      if (!churchId) return;
      try {
        const params = { action: 'getPublicClassUncles', church_id: churchId };
        if (className) params.class_name = className;
        if (classId) params.class_id = classId;
        if (!className && !classId) return;
        const d = await api(params);
        // If server resolved a class name from class_id and we had none, update display
        if (d.resolved_class_name && !className) {
          const heroTxt = document.getElementById('heroClassTxt');
          if (heroTxt && heroTxt.textContent === '—') heroTxt.textContent = d.resolved_class_name;
          const ssCls = document.getElementById('ssClass');
          if (ssCls && ssCls.textContent === '—') ssCls.textContent = d.resolved_class_name;
          if (student) student.class = d.resolved_class_name;
        }
        if (d.success && d.uncles && d.uncles.length) renderUncleStrip(d.uncles);
      } catch (e) { }
    }
    // Global uncles list (filled after load)
    let classUncles = [];

    function normalizeBirthdayDigits(value) {
      const ar = '٠١٢٣٤٥٦٧٨٩';
      const fa = '۰۱۲۳۴۵۶۷۸۹';
      return String(value || '')
        .replace(/[٠-٩]/g, d => ar.indexOf(d))
        .replace(/[۰-۹]/g, d => fa.indexOf(d));
    }

    function parseBirthdayDate(value) {
      const raw = normalizeBirthdayDigits(value).trim();
      if (!raw) return null;
      const short = raw.split(/[ T]/)[0];
      let m = short.match(/^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$/);
      if (m) return { year: +m[1], month: +m[2], day: +m[3] };
      m = short.match(/^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})$/);
      if (m) return { year: +m[3], month: +m[2], day: +m[1] };
      m = short.match(/^(\d{1,2})[-/.](\d{1,2})$/);
      if (m) return { year: null, month: +m[2], day: +m[1] };
      const d = new Date(raw);
      if (!Number.isNaN(d.getTime())) return { year: d.getFullYear(), month: d.getMonth() + 1, day: d.getDate() };
      return null;
    }

    function getBirthdayAge(s) {
      const b = parseBirthdayDate(s && s.birthday);
      if (!b || !b.year) return null;
      const now = new Date();
      let age = now.getFullYear() - b.year;
      if (now.getMonth() + 1 < b.month || (now.getMonth() + 1 === b.month && now.getDate() < b.day)) age--;
      return age >= 0 ? age : null;
    }

    function isBirthdayToday(s) {
      const b = parseBirthdayDate(s && s.birthday);
      if (!b) return false;
      const now = new Date();
      return b.month === now.getMonth() + 1 && b.day === now.getDate();
    }

    function updateBirthdayGreetingButton(s) {
      const btn = document.getElementById('birthdayGreetingBtn');
      if (!btn) return;
      const show = !!(s && isBirthdayToday(s));
      birthdayGreetingStudent = show ? s : null;
      btn.classList.toggle('show', show);
    }

    function fillRoundRect(ctx, x, y, w, h, r) {
      ctx.beginPath();
      ctx.moveTo(x + r, y);
      ctx.arcTo(x + w, y, x + w, y + h, r);
      ctx.arcTo(x + w, y + h, x, y + h, r);
      ctx.arcTo(x, y + h, x, y, r);
      ctx.arcTo(x, y, x + w, y, r);
      ctx.closePath();
      ctx.fill();
    }

    function loadCanvasImage(src) {
      return new Promise((resolve, reject) => {
        if (!src) { reject(new Error('missing image')); return; }
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = () => resolve(img);
        img.onerror = reject;
        img.src = src;
      });
    }

    function drawRoundedImage(ctx, img, x, y, w, h, r) {
      ctx.save();
      ctx.beginPath();
      ctx.moveTo(x + r, y);
      ctx.arcTo(x + w, y, x + w, y + h, r);
      ctx.arcTo(x + w, y + h, x, y + h, r);
      ctx.arcTo(x, y + h, x, y, r);
      ctx.arcTo(x, y, x + w, y, r);
      ctx.closePath();
      ctx.clip();
      const scale = Math.max(w / img.width, h / img.height);
      const sw = w / scale, sh = h / scale;
      ctx.drawImage(img, (img.width - sw) / 2, (img.height - sh) / 2, sw, sh, x, y, w, h);
      ctx.restore();
    }

    function drawBirthdayConfetti(ctx, w, h) {
      const colors = ['#ef4444', '#f59e0b', '#10b981', '#2563eb', '#ec4899', '#7c3aed', '#14b8a6'];
      for (let i = 0; i < 95; i++) {
        const x = (Math.sin(i * 17.31) * .5 + .5) * w;
        const y = 70 + (Math.cos(i * 9.71) * .5 + .5) * (h - 180);
        const size = 8 + (i % 6) * 4;
        ctx.save();
        ctx.translate(x, y);
        ctx.rotate((i * 29 * Math.PI) / 180);
        ctx.globalAlpha = .72;
        ctx.fillStyle = colors[i % colors.length];
        if (i % 3 === 0) {
          ctx.beginPath(); ctx.arc(0, 0, size / 2, 0, Math.PI * 2); ctx.fill();
        } else if (i % 3 === 1) {
          ctx.fillRect(-size / 2, -size / 4, size, size / 2);
        } else {
          ctx.beginPath(); ctx.moveTo(0, -size / 2); ctx.lineTo(size / 2, size / 2); ctx.lineTo(-size / 2, size / 2); ctx.closePath(); ctx.fill();
        }
        ctx.restore();
      }
      for (let i = 0; i < 8; i++) {
        ctx.save();
        ctx.strokeStyle = colors[(i + 2) % colors.length];
        ctx.globalAlpha = .45;
        ctx.lineWidth = 8;
        ctx.beginPath();
        const startX = 70 + i * 135;
        ctx.moveTo(startX, 120);
        for (let t = 0; t < 6; t++) ctx.quadraticCurveTo(startX + 30, 170 + t * 35, startX, 195 + t * 35);
        ctx.stroke();
        ctx.restore();
      }
    }

    async function drawBirthdayGreeting() {
      const s = birthdayGreetingStudent || student;
      const canvas = document.getElementById('birthdayGreetingCanvas');
      if (!s || !canvas) return;
      const ctx = canvas.getContext('2d');
      const w = canvas.width, h = canvas.height;
      ctx.clearRect(0, 0, w, h);

      const bg = ctx.createLinearGradient(0, 0, w, h);
      bg.addColorStop(0, '#fff7ad');
      bg.addColorStop(.35, '#ffd6e7');
      bg.addColorStop(.7, '#bde7ff');
      bg.addColorStop(1, '#d8ffd6');
      ctx.fillStyle = bg;
      ctx.fillRect(0, 0, w, h);
      drawBirthdayConfetti(ctx, w, h);

      ctx.save();
      ctx.fillStyle = 'rgba(255,255,255,.86)';
      ctx.shadowColor = 'rgba(30,41,59,.18)';
      ctx.shadowBlur = 35;
      fillRoundRect(ctx, 78, 150, w - 156, h - 235, 54);
      ctx.restore();

      try {
        const logo = await loadCanvasImage('/logo.png');
        drawRoundedImage(ctx, logo, w / 2 - 58, 185, 116, 116, 28);
      } catch (e) {
        ctx.fillStyle = '#ffffff';
        ctx.beginPath(); ctx.arc(w / 2, 243, 58, 0, Math.PI * 2); ctx.fill();
      }

      ctx.textAlign = 'center';
      ctx.direction = 'rtl';
      ctx.fillStyle = '#0f172a';
      ctx.font = '800 36px Cairo, Tahoma, Arial';
      ctx.fillText(s.church_name || 'Sunday School', w / 2, 340);
      ctx.fillStyle = '#db2777';
      ctx.font = '900 78px Cairo, Tahoma, Arial';
      ctx.fillText('عيد ميلاد سعيد', w / 2, 455);
      ctx.fillStyle = '#7c3aed';
      ctx.font = '800 34px Cairo, Tahoma, Arial';
      ctx.fillText('ربنا يفرح قلبك ويبارك سنينك', w / 2, 515);

      const px = w / 2 - 185, py = 575, ps = 370;
      ctx.save();
      ctx.shadowColor = 'rgba(15,23,42,.25)';
      ctx.shadowBlur = 35;
      ctx.fillStyle = '#fff';
      ctx.beginPath(); ctx.arc(w / 2, py + ps / 2, ps / 2 + 18, 0, Math.PI * 2); ctx.fill();
      ctx.restore();

      try {
        const photo = await loadCanvasImage(s.image_url);
        ctx.save();
        ctx.beginPath(); ctx.arc(w / 2, py + ps / 2, ps / 2, 0, Math.PI * 2); ctx.clip();
        const scale = Math.max(ps / photo.width, ps / photo.height);
        const sw = ps / scale, sh = ps / scale;
        ctx.drawImage(photo, (photo.width - sw) / 2, (photo.height - sh) / 2, sw, sh, px, py, ps, ps);
        ctx.restore();
      } catch (e) {
        const av = ctx.createLinearGradient(px, py, px + ps, py + ps);
        av.addColorStop(0, '#2563eb');
        av.addColorStop(1, '#ec4899');
        ctx.fillStyle = av;
        ctx.beginPath(); ctx.arc(w / 2, py + ps / 2, ps / 2, 0, Math.PI * 2); ctx.fill();
        ctx.fillStyle = '#fff';
        ctx.font = '900 135px Cairo, Tahoma, Arial';
        ctx.fillText((s.name || '?').trim().charAt(0), w / 2, py + ps / 2 + 48);
      }

      ctx.fillStyle = '#111827';
      ctx.font = '900 70px Cairo, Tahoma, Arial';
      ctx.fillText(s.name || '', w / 2, 1030);

      const age = getBirthdayAge(s);
      if (age !== null) {
        ctx.fillStyle = '#f97316';
        fillRoundRect(ctx, w / 2 - 210, 1075, 420, 86, 43);
        ctx.fillStyle = '#fff';
        ctx.font = '900 40px Cairo, Tahoma, Arial';
        ctx.fillText(`تم ${age} سنة`, w / 2, 1132);
      }

      ctx.fillStyle = '#475569';
      ctx.font = '700 31px Cairo, Tahoma, Arial';
      ctx.fillText('كل سنة وأنت طيب', w / 2, 1218);
    }

    function openBirthdayGreeting() {
      const s = birthdayGreetingStudent || student;
      if (!s || !isBirthdayToday(s)) {
        toast('الصورة تظهر في يوم عيد الميلاد فقط', 'info');
        return;
      }
      birthdayGreetingStudent = s;
      openOv('bdayGreetingOv');
      setTimeout(drawBirthdayGreeting, 80);
    }

    function birthdayCanvasToBlob() {
      const canvas = document.getElementById('birthdayGreetingCanvas');
      return new Promise((resolve, reject) => {
        try {
          canvas.toBlob(blob => blob ? resolve(blob) : reject(new Error('تعذر تجهيز الصورة')), 'image/png', 1);
        } catch (e) { reject(e); }
      });
    }

    async function saveBirthdayGreeting() {
      try {
        await drawBirthdayGreeting();
        const blob = await birthdayCanvasToBlob();
        const s = birthdayGreetingStudent || student || {};
        const safeName = String(s.name || 'birthday').replace(/[^\p{L}\p{N}_-]+/gu, '_');
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `birthday_${safeName}.png`;
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
      } catch (e) {
        toast('تعذر حفظ الصورة: ' + e.message, 'err');
      }
    }

    async function shareBirthdayGreeting() {
      try {
        await drawBirthdayGreeting();
        const blob = await birthdayCanvasToBlob();
        const s = birthdayGreetingStudent || student || {};
        const file = new File([blob], 'birthday-greeting.png', { type: 'image/png' });
        if (navigator.canShare && navigator.canShare({ files: [file] })) {
          await navigator.share({ files: [file], title: 'صورة عيد الميلاد', text: `كل سنة و${s.name || ''} طيب` });
        } else {
          await saveBirthdayGreeting();
        }
      } catch (e) {
        toast('تعذر مشاركة الصورة: ' + e.message, 'err');
      }
    }

    function renderUncleStrip(uncles) {
      classUncles = uncles;
      const strip = document.getElementById('uncleStrip');
      if (!strip || !uncles.length) return;
      const show = uncles.slice(0, 4);
      const extra = uncles.length - show.length;
      strip.innerHTML = `
    <div class="uncle-avatars">
      ${show.map(u => `<div class="ua" title="${esc(u.name)}">${u.image_url ? `<img src="${esc(u.image_url)}" alt="${esc(u.name)}">` : u.name.charAt(0)}</div>`).join('')}
      ${extra > 0 ? `<div class="ua-more">+${extra}</div>` : ''}
    </div>
    <span class="uncle-strip-label">${uncles.length === 1 ? esc(uncles[0].name) : ''}</span>`;
      strip.style.display = 'inline-flex';
      strip.onclick = () => { switchTab('family'); };
      renderUncleCards(uncles);
    }

    function renderUncleCards(uncles) {
      const sec = document.getElementById('scUncles');
      const grid = document.getElementById('uncleCardGrid');
      const sub = document.getElementById('unclesSub');
      if (!sec || !grid || !uncles.length) return;
      sub.textContent = uncles.length + ' مدرس';
      const roleLbl = { admin: 'مدرس', developer: 'مطوّر', uncle: 'مدرس' };
      grid.innerHTML = uncles.map(u => `
    <div class="uncle-card" onclick="openUncleDrawer(${u.id})">
      <div class="uncle-card-av">
        ${u.image_url ? `<img src="${esc(u.image_url)}" alt="${esc(u.name)}">` : u.name.charAt(0)}
      </div>
      <div class="uncle-card-name">${esc(u.name)}</div>
      <div class="uncle-card-role">${roleLbl[u.role] || u.role}</div>
    </div>`).join('');
      const currentTab = document.querySelector('.bottom-nav-item.active')?.getAttribute('data-tab') || 'home';
      if (currentTab === 'family') {
        sec.style.display = 'block';
      } else {
        sec.style.display = 'none';
      }
    }

    function openUncleDrawer(uid) {
      const u = classUncles.find(x => x.id === uid);
      if (!u) return;
      const roleLbl = { admin: 'مدرس', developer: 'مطوّر', uncle: 'مدرس' };
      const hasPhone = u.phone && u.phone.trim();
      // Format phone for WhatsApp (strip leading 0, add country code)
      const waPhone = hasPhone ? '20' + u.phone.trim().replace(/^0/, '') : '';
      const waMsg = encodeURIComponent('مرحباً أنا ' + ((student && student.name) || '') + '، انا في فصلك بمدارس الأحد');
      const actionBtns = hasPhone ? `
    <a href="tel:${esc(u.phone.trim())}" class="uncle-action-btn call" style="text-decoration:none;">
      <div class="uncle-action-ico" style="background:#d1fae5;color:#059669;"><i class="fas fa-phone-alt"></i></div>
      <div><div>${esc(u.phone.trim())}</div><div style="font-size:.7rem;color:var(--t4);font-weight:500;">اضغط للاتصال</div></div>
    </a>
    <a href="https://wa.me/${waPhone}?text=${waMsg}" target="_blank" class="uncle-action-btn wa" style="text-decoration:none;">
      <div class="uncle-action-ico" style="background:#dcfce7;color:#16a34a;"><i class="fab fa-whatsapp"></i></div>
      <div><div>واتساب</div><div style="font-size:.7rem;color:var(--t4);font-weight:500;">إرسال رسالة</div></div>
    </a>`
        : `<div style="padding:14px 18px;background:var(--s2);border-radius:var(--r-md);text-align:center;color:var(--t4);font-size:.82rem;font-weight:600;"><i class="fas fa-phone-slash"></i> لم يُضَف رقم الهاتف بعد</div>`;
      document.getElementById('uncleOvContent').innerHTML = `
    <div class="uncle-drawer-hero">
      <div class="uncle-drawer-av">
        ${u.image_url ? `<img src="${esc(u.image_url)}" alt="${esc(u.name)}">` : u.name.charAt(0)}
      </div>
      <div>
        <div class="uncle-drawer-name">${esc(u.name)}</div>
        <div class="uncle-drawer-role">${roleLbl[u.role] || u.role}</div>
      </div>
    </div>
    <div style="padding:14px 18px;display:flex;flex-direction:column;gap:10px;">
      ${actionBtns}
    </div>`;
      openOv('uncleOv');
    }

    // ── Info grid ─────────────────────────────────────────────────────
    // ── Friend profile ────────────────────────────────────────────────
    let _myStudent = null; // snapshot of logged-in student while viewing a friend

    function isViewingOther() {
      return !!(IS_PUBLIC || _myStudent);
    }

    function syncViewMode() {
      if (isViewingOther()) {
        document.body.classList.add('view-other-mode');
      } else {
        document.body.classList.remove('view-other-mode');
      }
    }

    async function openFriendProfile(friendId, pushState = true) {
      showLoad('جارٍ تحميل الملف…');
      try {
        const [profD, attD] = await Promise.all([
          api({ action: 'getStudentProfile', studentId: friendId }),
          api({ action: 'getStudentAttendance', studentId: friendId })
        ]);
        hideLoad();
        if (!profD.success || !(profD.student || profD.user)) return; // friend not found — stay on own profile

        if (pushState) {
          const newUrl = location.pathname + '?id=' + friendId + location.hash;
          window.history.pushState({ friendId: friendId }, '', newUrl);
        }

        const f = norm(profD.student || profD.user);
        f._friendAtt = attD.success ? (attD.attendance || []) : [];
        renderFriend(f);
      } catch (e) { hideLoad(); }
    }

    function renderFriend(f) {
      _myStudent = student; // save own profile

      // ── Avatar — friend's own image, no edit button ──────────────
      const avInner = document.getElementById('avatarInner');
      avInner.innerHTML = f.image_url
        ? `<img src="${esc(f.image_url)}" alt="${esc(f.name)}" onerror="this.parentElement.innerHTML='<i class=\\'fas fa-user\\'></i>'">`
        : '<i class="fas fa-user"></i>';
      document.getElementById('avatarEdit').style.display = 'none';
      document.getElementById('avatarEdit').classList.remove('show');

      // ── Name, class ───────────────────────────────────────────────
      document.getElementById('heroName').textContent = f.name;
      updateBirthdayGreetingButton(f);
      document.getElementById('heroClassTxt').textContent = f.class || '—';
      const chip = document.getElementById('churchChip');
      if (chip && f.church_name) {
        document.getElementById('churchName').textContent = f.church_name;
        chip.style.display = 'inline-flex';
      }
      document.getElementById('hero').style.display = 'flex';

      // ── Uncle strip for friend's class ───────────────────────────
      const strip = document.getElementById('uncleStrip');
      strip.style.display = 'none';
      strip.innerHTML = '';
      classUncles = [];
      if (f.church_id && (f.class || f.class_id)) {
        loadClassUncles(f.church_id, f.class || '', f.class_id || 0);
      }

      // ── Info (public mode — class + church only) ──────────────────
      renderInfo(f, true);

      // ── Coupon hero ────────────────────────────────────────────────
      renderCouponHero(f);
      document.getElementById('couponHero').style.display = 'grid';

      // ── Stats bar — coupons only ──────────────────────────────────
      document.getElementById('statsBar').style.display = 'grid';
      document.getElementById('sbC').textContent = f.coupons;
      ['sbP', 'sbA', 'sbR'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.closest('.sb-cell').style.display = 'none';
      });

      // ── Attendance calendar ───────────────────────────────────────
      renderCal(f._friendAtt || []);
      document.getElementById('scAtt').style.display = 'block';
      document.getElementById('attViewAllBtn').style.display = 'none';


      // ── Announcements for friend's church/class ───────────────────
      const savedStudent = student;
      student = f;
      loadAnn();
      student = savedStudent;

      // ── Hide private sections ─────────────────────────────────────
      document.getElementById('scTasks').style.display = 'none';
      document.getElementById('scTrips').style.display = 'none';
      document.getElementById('pubBanner').style.display = 'none';
      document.getElementById('settingsTop').style.display = 'none';
      document.getElementById('notifBtnTop').style.display = 'none';
      const scSendCoupons = document.getElementById('scSendCoupons');
      if (scSendCoupons) scSendCoupons.style.display = 'none';
      const homeSearchBar = document.getElementById('homeSearchBar');
      if (homeSearchBar) homeSearchBar.style.display = 'none';
      // Hide bottom nav while viewing friend — only the banner "return" button is available
      document.getElementById('bottomNavBar').style.display = 'none';

      // ── Friend banner ─────────────────────────────────────────────
      document.getElementById('friendBannerName').textContent = 'ملف ' + f.name;
      document.getElementById('friendBanner').style.display = 'flex';

      showMain();
      window.scrollTo({ top: 0, behavior: 'smooth' });
      syncViewMode();
    }

    function returnToMyProfile(pushState = true) {
      if (!_myStudent) return;
      const s = _myStudent;
      _myStudent = null;

      // Clean the ?id= from the URL
      const clean = location.pathname + location.hash;
      if (pushState) {
        window.history.pushState({}, '', clean);
      } else {
        window.history.replaceState({}, '', clean);
      }

      // Restore student global first
      student = s;

      // Restore own avatar
      const avInner = document.getElementById('avatarInner');
      if (s.image_url) {
        avInner.innerHTML = `<img src="${esc(s.image_url)}" alt="${esc(s.name)}" onerror="this.parentElement.innerHTML='<i class=\\'fas fa-user\\'></i>'">`;
      } else {
        avInner.innerHTML = '<i class="fas fa-user"></i>';
      }
      document.getElementById('avatarEdit').style.display = '';
      document.getElementById('avatarEdit').classList.add('show');

      // Restore hero text
      document.getElementById('heroName').textContent = s.name;
      updateBirthdayGreetingButton(s);
      document.getElementById('heroClassTxt').textContent = s.class || '—';
      const chip = document.getElementById('churchChip');
      if (chip) { document.getElementById('churchName').textContent = s.church_name || ''; chip.style.display = s.church_name ? 'inline-flex' : 'none'; }

      // Restore uncle strip for own class
      const strip = document.getElementById('uncleStrip');
      strip.style.display = 'none'; strip.innerHTML = ''; classUncles = [];
      if (s.church_id && (s.class || s.class_id)) loadClassUncles(s.church_id, s.class || '', s.class_id || 0);

      // Restore info
      renderInfo(s, false);

      // Restore coupons
      renderCouponHero(s);
      document.getElementById('couponHero').style.display = 'grid';

      // Restore stats bar — show all cells
      document.getElementById('statsBar').style.display = 'grid';
      document.getElementById('sbC').textContent = s.coupons;
      ['sbP', 'sbA', 'sbR'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.closest('.sb-cell').style.display = '';
      });

      // Hide friend banner
      document.getElementById('friendBanner').style.display = 'none';
      document.getElementById('pubBanner').style.display = 'none';
      document.getElementById('settingsTop').style.display = 'flex';
      document.getElementById('notifBtnTop').style.display = 'flex';

      // Restore nav bar and switch to home tab cleanly
      document.getElementById('bottomNavBar').style.display = 'flex';

      // Reload own data
      loadAtt();
      loadTasks();
      loadTrips(true);
      loadAnn();
      loadSiblings();
      syncPassOverlay();

      // Switch to home/initial tab — this handles all section show/hide
      switchTab(getInitialTab());
      renderAccountSwitcher();
      window.scrollTo({ top: 0, behavior: 'smooth' });
      syncViewMode();
    }



    function renderInfo(s, isPublic) {
      const el = document.getElementById('infoGrid');
      const pills = [];
      if (s.class) pills.push({ bg: '#e0e7ff', c: '#4338ca', icon: 'fas fa-graduation-cap', lbl: 'الفصل', val: s.class });
      if (!isPublic) {
        const displayedPhones = new Set();
        const relLabels = {
          'father': 'الأب', 'mother': 'الأم', 'brother': 'الأخ', 'sister': 'الأخت',
          'grandfather': 'الجد', 'grandmother': 'الجدة', 'uncle': 'عم / خال',
          'aunt': 'عمة / خالة', 'guardian': 'ولي أمر', 'self': 'شخصي', 'personal': 'شخصي', 'other': 'أخرى'
        };
        if (s.parent_phones && s.parent_phones.length > 0) {
          s.parent_phones.forEach(p => {
            if (!p.phone) return;
            const cleanP = String(p.phone).replace(/\D/g, '');
            displayedPhones.add(cleanP);
            const rel = (p.relation === 'other' && p.custom_relation) ? p.custom_relation : (relLabels[p.relation] || p.relation || 'ولي أمر');
            const nameStr = p.name ? ` (${p.name})` : '';
            pills.push({ bg: '#ecfdf5', c: '#047857', icon: 'fas fa-phone-alt', lbl: `هاتف ${rel}${nameStr}`, val: p.phone });
          });
        }
        if (s.phone) {
          const cleanMain = String(s.phone).replace(/\D/g, '');
          if (!displayedPhones.has(cleanMain)) {
            const rawLbl = (s.custom_info && (s.custom_info.phone_custom_label || s.custom_info.phone_label)) || 'father';
            const ownerRel = relLabels[rawLbl] || rawLbl || 'الأب (ولي الأمر)';
            const lblStr = ownerRel.startsWith('هاتف') ? ownerRel : `هاتف ${ownerRel}`;
            pills.push({ bg: '#d1fae5', c: '#065f46', icon: 'fas fa-phone', lbl: lblStr, val: s.phone });
            displayedPhones.add(cleanMain);
          }
        }
        if (s.emergency_phone) {
          const cleanEmerg = String(s.emergency_phone).replace(/\D/g, '');
          if (!displayedPhones.has(cleanEmerg)) {
            pills.push({ bg: '#ecfdf5', c: '#047857', icon: 'fas fa-phone-alt', lbl: 'هاتف طوارئ', val: s.emergency_phone });
            displayedPhones.add(cleanEmerg);
          }
        }
        if (s.address) pills.push({ bg: '#ffedd5', c: '#9a3412', icon: 'fas fa-map-marker-alt', lbl: 'العنوان', val: s.address });
        if (s.birthday) pills.push({ bg: '#fce7f3', c: '#9d174d', icon: 'fas fa-birthday-cake', lbl: 'عيد الميلاد', val: s.birthday });
        if (s.email) pills.push({ bg: '#dbeafe', c: '#1e40af', icon: 'fas fa-envelope', lbl: 'البريد', val: s.email });
        // Custom info from student record
        if (s.custom_info && typeof s.custom_info === 'object') {
          // Match keys against church custom_field definitions for labels/icons
          const defs = customFields || [];
          for (const [key, val] of Object.entries(s.custom_info)) {
            if (!val || key === 'sibling_group' || key === 'parent_phones') continue;
            const def = defs.find(d => d.key === key);
            const label = def?.name || key;
            const icon = def?.icon || 'fas fa-tag';
            pills.push({ bg: '#f0fdf4', c: '#166534', icon: `fas ${icon.replace('fas ', '').replace('fa-', '') ? 'fa-' + icon.replace('fas ', '').replace('fa-', '') : icon}`, lbl: label, val: String(val) });
          }
        }
      }
      if (s.church_name && isPublic) pills.push({ bg: '#ede9fe', c: '#4c1d95', icon: 'fas fa-church', lbl: 'الكنيسة', val: s.church_name });

      if (!pills.length) {
        document.getElementById('scInfo').style.display = 'none';
        return;
      }
      el.innerHTML = pills.map(p => `
    <div class="ip">
      <div class="ip-ico" style="background:${p.bg};color:${p.c};"><i class="${p.icon}"></i></div>
      <div><div class="ip-lbl">${esc(p.lbl)}</div><div class="ip-val">${esc(p.val)}</div></div>
    </div>`).join('');
    }

    // ── Attendance ────────────────────────────────────────────────────
    async function loadAtt() {
      try {
        const d = await api({ action: 'getStudentAttendance', studentId: student.id });
        renderCal(d.attendance || []);
      } catch (e) { renderCal([]); }
    }

    function renderCal(records) {
      const jsDay = DB_TO_JSDAY[churchDay] ?? 5;
      const dayName = DAY_NAMES[jsDay] || 'الجمعة';
      document.getElementById('attSub').textContent = `آخر 12 ${dayName}`;

      const todayLocal = new Date(); todayLocal.setHours(12, 0, 0, 0);
      const days = [];
      for (let i = 0; days.length < 12; i++) {
        const d = new Date(todayLocal); d.setDate(d.getDate() - i);
        if (d.getDay() === jsDay) {
          const y = d.getFullYear(), m = String(d.getMonth() + 1).padStart(2, '0'), dd = String(d.getDate()).padStart(2, '0');
          const str = `${y}-${m}-${dd}`;
          days.push({ date: d, str, num: d.getDate(), mo: d.toLocaleDateString('ar-EG', { month: 'short' }) });
        }
      }
      let pr = 0, ab = 0;
      document.getElementById('calGrid').innerHTML = days.map(day => {
        const rec = records.find(r => r.attendance_date === day.str);
        const diff = Math.round((todayLocal - day.date) / (86400000));
        const daysAgo = diff === 0 ? 'اليوم' : diff === 7 ? 'أسبوع' : diff < 14 ? `${diff} يوم` : Math.round(diff / 7) + ' أسبوع';
        let cls = '', st = '—';
        if (rec) {
          if (rec.status === 'present') { cls = 'present'; st = 'حضر'; pr++; }
          else if (rec.status === 'absent') { cls = 'absent'; st = 'غاب'; ab++; }
        }
        return `<div class="cal-day ${cls}">
      <div class="cd-num">${day.num}</div>
      <div class="cd-mo">${day.mo}</div>
      <div class="cd-st">${st}</div>
      <div class="cd-days">${daysAgo}</div>
    </div>`;
      }).join('');
      const total = pr + ab; const rate = total > 0 ? Math.round(pr / total * 100) : 0;
      document.getElementById('ap').textContent = pr;
      document.getElementById('aa').textContent = ab;
      document.getElementById('ar').textContent = rate + '%';
      document.getElementById('sbP').textContent = pr;
      document.getElementById('sbA').textContent = ab;
      document.getElementById('sbR').textContent = rate + '%';
      document.getElementById('attBadge').textContent = rate + '%';
      if (!pr && !ab) document.getElementById('scAtt').style.display = 'none';
    }

    // ── Attendance History Sheet ──────────────────────────────────────
    let _attAllRecords = [];
    let _attFilter = 'all';

    function timeAgoAr(dateStr) {
      const [yr, mo, dy] = dateStr.split('-').map(Number);
      const diff = Math.floor((Date.now() - new Date(yr, mo - 1, dy, 12).getTime()) / 86400000);
      if (diff <= 0) return 'اليوم';
      if (diff === 1) return 'أمس';
      if (diff < 7) return `منذ ${diff} أيام`;
      const w = Math.floor(diff / 7);
      if (w === 1) return 'منذ أسبوع';
      if (w < 5) return `منذ ${w} أسابيع`;
      const m = Math.floor(diff / 30);
      if (m === 1) return 'منذ شهر';
      if (m < 12) return `منذ ${m} شهور`;
      const y = Math.floor(diff / 365);
      return y === 1 ? 'منذ سنة' : `منذ ${y} سنوات`;
    }

    async function openAttHistory() {
      openOv('attHistOv');
      _attFilter = 'all';
      document.querySelectorAll('#attHistOv .fchip').forEach(c => c.classList.remove('active'));
      document.querySelector('#attHistOv .fchip[data-filter="all"]').classList.add('active');
      document.getElementById('attHistSearch').value = '';
      document.getElementById('attHistSort').value = 'newest';
      document.getElementById('attHistList').innerHTML =
        '<div style="text-align:center;padding:28px;color:var(--t4);font-size:.88rem;"><i class="fas fa-spinner fa-spin" style="display:block;font-size:1.6rem;margin-bottom:8px;opacity:.4;"></i>جارٍ التحميل…</div>';

      try {
        const d = await api({ action: 'getStudentAttendance', studentId: student.id });
        const dbRecords = (d.attendance || []);

        // Build ALL church-day slots for the past 24 weeks
        // Use LOCAL date arithmetic to avoid UTC timezone shift bugs
        const jsDay = DB_TO_JSDAY[churchDay] ?? 5;
        const todayLocal = new Date();
        todayLocal.setHours(12, 0, 0, 0); // noon — safe against DST/UTC shifts
        const allSlots = [];
        let cur = new Date(todayLocal);
        // Walk back to most recent church day (inclusive of today)
        while (cur.getDay() !== jsDay) cur.setDate(cur.getDate() - 1);
        for (let i = 0; i < 24; i++) {
          // Format as YYYY-MM-DD using local parts — never toISOString (UTC)
          const y = cur.getFullYear();
          const m = String(cur.getMonth() + 1).padStart(2, '0');
          const dd = String(cur.getDate()).padStart(2, '0');
          const str = `${y}-${m}-${dd}`;
          const rec = dbRecords.find(r => r.attendance_date === str);
          allSlots.push({ str, status: rec ? rec.status : 'unrecorded' });
          cur.setDate(cur.getDate() - 7);
        }
        _attAllRecords = allSlots;

        const pr = allSlots.filter(r => r.status === 'present').length;
        const ab = allSlots.filter(r => r.status === 'absent').length;
        const ur = allSlots.filter(r => r.status === 'unrecorded').length;
        const total = pr + ab;
        document.getElementById('ahsPresent').textContent = pr;
        document.getElementById('ahsAbsent').textContent = ab;
        document.getElementById('ahsRate').textContent = total > 0 ? Math.round(pr / total * 100) + '%' : '—';
        document.getElementById('attHistSubtitle').textContent =
          `${allSlots.length} أسبوع · ${pr} حضور · ${ab} غياب · ${ur} غير مسجّل`;

        renderAttHist();
      } catch (e) {
        document.getElementById('attHistList').innerHTML =
          '<div style="text-align:center;padding:28px;color:var(--err);font-size:.88rem;"><i class="fas fa-exclamation-circle" style="display:block;font-size:1.6rem;margin-bottom:8px;"></i>فشل التحميل</div>';
      }
    }

    function setAttFilter(el, filter) {
      _attFilter = filter;
      document.querySelectorAll('#attHistOv .fchip').forEach(c => c.classList.remove('active'));
      el.classList.add('active');
      renderAttHist();
    }

    function renderAttHist() {
      const search = (document.getElementById('attHistSearch').value || '').trim().toLowerCase();
      const sort = document.getElementById('attHistSort').value;
      const list = document.getElementById('attHistList');
      const WDAYS = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

      let items = _attAllRecords.slice();
      if (sort === 'oldest') items = items.slice().reverse();
      if (_attFilter !== 'all') items = items.filter(r => r.status === _attFilter);
      if (search) {
        items = items.filter(r => {
          const dateAr = new Date(r.str || r.attendance_date).toLocaleDateString('ar-EG', { day: 'numeric', month: 'long', year: 'numeric' });
          return (r.str || r.attendance_date).includes(search) || dateAr.includes(search);
        });
      }

      document.getElementById('attHistCount').textContent = items.length ? `${items.length} نتيجة` : '';

      if (!items.length) {
        list.innerHTML = '<div style="text-align:center;padding:28px;color:var(--t4);font-size:.88rem;font-weight:600;"><i class="fas fa-search" style="display:block;font-size:1.5rem;margin-bottom:8px;opacity:.35;"></i>لا توجد نتائج</div>';
        return;
      }

      list.innerHTML = items.map(r => {
        const dateStr = r.str || r.attendance_date;
        // Parse as local noon to avoid UTC-shift day-off-by-one
        const [yr, mo, dy] = dateStr.split('-').map(Number);
        const d = new Date(yr, mo - 1, dy, 12);
        const dateAr = d.toLocaleDateString('ar-EG', { day: 'numeric', month: 'long', year: 'numeric' });
        const wday = WDAYS[d.getDay()];
        const ago = timeAgoAr(dateStr);
        const isP = r.status === 'present';
        const isA = r.status === 'absent';
        const isU = r.status === 'unrecorded';

        const rowBg = isP ? 'background:var(--ok-bg);border-color:#6ee7b7;'
          : isA ? 'background:var(--err-bg);border-color:#fca5a5;'
            : 'background:var(--s2);border-color:var(--bdr2);opacity:.82;';
        const dotClr = isP ? 'var(--ok)' : isA ? 'var(--err)' : 'var(--t5)';
        const badgeBg = isP ? 'background:rgba(5,150,105,.12);color:var(--ok);'
          : isA ? 'background:rgba(220,38,38,.12);color:var(--err);'
            : 'background:var(--s2);color:var(--t4);border:1px solid var(--bdr);';
        const label = isP ? 'حضر ✓' : isA ? 'غاب ✗' : '— غير مسجّل';

        return `<div style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:var(--r-md);border:1.5px solid;${rowBg}transition:var(--fast);">
      <div style="width:8px;height:8px;border-radius:50%;flex-shrink:0;background:${dotClr};margin-top:1px;"></div>
      <div style="flex:1;min-width:0;">
        <div style="font-size:.88rem;font-weight:800;color:var(--t1);">${dateAr}</div>
        <div style="font-size:.64rem;color:var(--t4);font-weight:500;margin-top:1px;">${wday} · ${ago}</div>
      </div>
      <span style="padding:3px 9px;border-radius:var(--r-full);font-size:.68rem;font-weight:700;flex-shrink:0;white-space:nowrap;${badgeBg}">${label}</span>
      <button onclick="openRowReport('${dateStr}','${r.status}')"
        title="بلّغ عن خطأ"
        style="width:28px;height:28px;border-radius:var(--r-sm);border:1.5px solid #fde68a;background:#fef3c7;color:#d97706;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.72rem;flex-shrink:0;transition:var(--fast);"
        onmouseover="this.style.background='#fde68a'" onmouseout="this.style.background='#fef3c7'">
        <i class="fas fa-flag"></i>
      </button>
    </div>`;
      }).join('');
    }

    // ── Report Attendance Error ───────────────────────────────────────
    let _reportDateStr = '';
    let _reportShould = '';

    function openRowReport(dateStr, currentStatus) {
      _reportDateStr = dateStr;
      _reportShould = '';

      // Show the date in Arabic in the sheet header
      let dateAr = dateStr;
      try {
        const [yr, mo, dy] = dateStr.split('-').map(Number);
        dateAr = new Date(yr, mo - 1, dy, 12).toLocaleDateString('ar-EG', { weekday: 'long', day: 'numeric', month: 'long' });
      } catch (e) { }
      document.getElementById('reportDateLabel').textContent = dateAr;

      // Reset should buttons
      ['reportShouldPresent', 'reportShouldAbsent'].forEach(id => {
        const b = document.getElementById(id);
        if (b) { b.style.background = 'var(--surf)'; b.style.borderColor = 'var(--bdr)'; b.style.color = 'var(--t2)'; }
      });

      // Build uncle list
      const unclesWithPhone = classUncles.filter(u => u.phone && u.phone.trim());
      const ul = document.getElementById('reportUncleList');
      if (!unclesWithPhone.length) {
        ul.innerHTML = `<div style="text-align:center;padding:16px;color:var(--t4);font-size:.82rem;font-weight:600;">
      <i class="fas fa-phone-slash" style="display:block;font-size:1.2rem;margin-bottom:5px;opacity:.4;"></i>
      لم يُضَف رقم هاتف لأي مدرّس بعد</div>`;
      } else {
        const roleLbl = { admin: 'مشرف', developer: 'مطوّر', uncle: 'مدرّس' };
        ul.innerHTML = unclesWithPhone.map(u => `
      <button onclick="sendRowReport(${u.id})"
        style="width:100%;display:flex;align-items:center;gap:11px;padding:10px 12px;border-radius:var(--r-md);border:1.5px solid var(--bdr);background:var(--surf);cursor:pointer;font-family:'Baloo Bhaijaan 2',sans-serif;transition:var(--fast);"
        onmouseover="this.style.borderColor='#d97706';this.style.background='#fef3c7'"
        onmouseout="this.style.borderColor='var(--bdr)';this.style.background='var(--surf)'">
        <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--brand-bg),#c7d2fe);color:var(--brand);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.88rem;flex-shrink:0;overflow:hidden;">
          ${u.image_url ? `<img src="${esc(u.image_url)}" style="width:100%;height:100%;object-fit:cover;">` : u.name.charAt(0)}
        </div>
        <div style="flex:1;text-align:right;">
          <div style="font-size:.88rem;font-weight:800;color:var(--t1);">${esc(u.name)}</div>
          <div style="font-size:.68rem;color:var(--t4);font-weight:500;">${roleLbl[u.role] || u.role}</div>
        </div>
        <div style="width:30px;height:30px;border-radius:var(--r-sm);background:#fef3c7;color:#d97706;display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0;">
          <i class="fab fa-whatsapp"></i>
        </div>
      </button>`).join('');
      }

      openOv('attReportOv');
    }

    function setReportShould(val) {
      _reportShould = val;
      const map = {
        present: { id: 'reportShouldPresent', bg: 'var(--ok-bg)', border: '#6ee7b7', color: 'var(--ok)' },
        absent: { id: 'reportShouldAbsent', bg: 'var(--err-bg)', border: '#fca5a5', color: 'var(--err)' },
      };
      ['reportShouldPresent', 'reportShouldAbsent'].forEach(id => {
        const b = document.getElementById(id);
        if (b) { b.style.background = 'var(--surf)'; b.style.borderColor = 'var(--bdr)'; b.style.color = 'var(--t2)'; }
      });
      const cfg = map[val]; if (!cfg) return;
      const btn = document.getElementById(cfg.id);
      if (btn) { btn.style.background = cfg.bg; btn.style.borderColor = cfg.border; btn.style.color = cfg.color; }
    }

    function sendRowReport(uid) {
      if (!_reportShould) { toast('اختر حضر أو غاب أولاً', 'err'); return; }
      const u = classUncles.find(x => x.id === uid);
      if (!u || !u.phone) return;
      const name = (student && student.name) || '';
      const cls = (student && student.class) || '';
      const shouldAr = _reportShould === 'present' ? 'حضر' : 'غاب';
      let dateAr = _reportDateStr;
      try {
        const [yr, mo, dy] = _reportDateStr.split('-').map(Number);
        dateAr = new Date(yr, mo - 1, dy, 12).toLocaleDateString('ar-EG', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
      } catch (e) { }
      const msg = `${name}${cls ? ' (' + cls + ')' : ''} — ${dateAr}\nالمفروض أكون: ${shouldAr}`;
      const wa = '20' + u.phone.trim().replace(/^0/, '');
      window.open(`https://wa.me/${wa}?text=${encodeURIComponent(msg)}`, '_blank');
    }

    // ── Tasks ─────────────────────────────────────────────────────────
    async function loadTasks() {
      try {
        const d = await api({ action: 'getStudentTasks', student_id: student.id, church_id: student.church_id, class_id: student.class_id || 0 });
        if (d.success) renderTasks(d.tasks || []);
      } catch (e) { }
    }
    function tSt(t) {
      if (t.my_submission) return 'done';
      const n = Date.now(), s = new Date(t.start_date).getTime();
      const hasDeadline = !parseInt(t.no_deadline || 0) && !!t.end_date;
      const e = hasDeadline ? new Date(t.end_date).getTime() : null;
      if (n < s) return 'upcoming'; if (hasDeadline && n > e) return 'expired'; return 'open';
    }
    function renderTasks(tasks) {
      allTasks = tasks;
      const el = document.getElementById('taskList');
      document.getElementById('taskSub').textContent = tasks.length + ' تاسك';
      if (!tasks.length) {
        el.innerHTML = `
          <div style="text-align:center; padding:50px 20px; color:rgba(255,255,255,0.7);">
            <div style="font-size:3rem; margin-bottom:15px; opacity:0.4;"><i class="fas fa-tasks"></i></div>
            <div style="font-size:1.05rem; font-weight:800;">لا توجد تاسكات أو اختبارات حالياً</div>
            <div style="font-size:0.8rem; margin-top:6px; opacity:0.8;">تابع مع مدرسك لمعرفة كل جديد</div>
          </div>
        `;
        return;
      }
      const stLbl = { done: 'مكتمل', open: 'مفتوح', upcoming: 'قادم', expired: 'منتهي' };
      const stBar = { done: 'done-bar', open: '', upcoming: 'up-bar', expired: 'exp-bar' };
      const stBadge = { done: 'tb-done', open: 'tb-open', upcoming: 'tb-up', expired: 'tb-exp' };
      el.innerHTML = tasks.map(t => {
        const st = tSt(t);
        const sub = t.my_submission;

        // Max coupon from matrix
        const matrix = t.coupon_matrix ? JSON.parse(t.coupon_matrix || '[]') : [];
        const maxCoupon = matrix.length ? Math.max(...matrix.map(m => parseInt(m.val) || 0)) : 0;

        // Coupon row — always visible
        let couponRow = '';
        if (sub) {
          const hasOpenQs = (t.questions || []).some(q => q.question_type === 'open' && (parseInt(q.degree) || 0) > 0);
          const isGraded = (parseInt(sub.is_graded || 0) === 1) || !hasOpenQs;
          const pct = t.total_degree > 0 ? Math.round(sub.score / t.total_degree * 100) : 0;
          if (isGraded) {
            couponRow = `<div class="task-result">
        <i class="fas fa-check-circle"></i>
        <span>${sub.score}/${t.total_degree} (${pct}%)</span>
        <span style="margin-right:auto;display:flex;align-items:center;gap:4px;">
          <i class="fas fa-star" style="color:var(--cou-l);font-size:.75rem;"></i>
          <strong style="color:var(--cou);font-size:.82rem;">${sub.coupons_awarded}</strong>
          <span style="font-size:.7rem;color:var(--t3);margin-left:5px;">كوبون</span>
          ${t.show_answers ? `<button onclick="event.stopPropagation();viewMyAnswers(${t.id})" style="margin-right:5px;background:var(--s2);border:1px solid var(--brand-l);color:var(--brand);border-radius:5px;padding:3px 8px;font-size:.7rem;font-family:'Baloo Bhaijaan 2',sans-serif;font-weight:700;cursor:pointer;"><i class="fas fa-eye"></i> الإجابات</button>` : ''}
        </span>
      </div>`;
          } else {
            couponRow = `<div class="task-result" style="background:#fffbeb;border:1px solid #fde68a;color:#b45309;">
        <i class="fas fa-clock" style="color:#d97706;"></i>
        <span style="font-weight:700;">بانتظار التقييم (لم يتم التقييم بعد)</span>
        <span style="margin-right:auto;display:flex;align-items:center;">
          ${t.show_answers ? `<button onclick="event.stopPropagation();viewMyAnswers(${t.id})" style="background:var(--s2);border:1px solid var(--brand-l);color:var(--brand);border-radius:5px;padding:3px 8px;font-size:.7rem;font-family:'Baloo Bhaijaan 2',sans-serif;font-weight:700;cursor:pointer;"><i class="fas fa-eye"></i> الإجابات</button>` : ''}
        </span>
      </div>`;
          }
        } else if (maxCoupon > 0) {
          // Not submitted: show max possible coupons
          couponRow = `<div class="task-coupon-row" style="display:flex;align-items:center;gap:5px;margin-top:7px;padding:5px 9px;border-radius:var(--r-sm);background:var(--cou-bg);border:1px solid #c4b5fd;font-size:.74rem;">
        <i class="fas fa-star" style="color:var(--cou-l);"></i>
        <span style="color:var(--cou);font-weight:700;">حتى ${maxCoupon} كوبون</span>
        <span style="color:var(--t4);font-size:.68rem;">عند الإجابة</span>
      </div>`;
        }

        return `<div class="task-card" onclick="openTask(${t.id})">
      <div class="task-bar ${stBar[st]}"></div>
      <div class="task-body">
        <div class="task-top">
          <div class="task-title">${esc(t.title)}</div>
          <span class="task-badge ${stBadge[st]}">${stLbl[st]}</span>
        </div>
        <div class="task-metas">
          <span class="task-meta-chip"><i class="far fa-clock" style="color:var(--brand);"></i>${parseInt(t.no_deadline || 0) ? 'بدون آخر موعد' : fmtDate(t.end_date)}</span>
          <span class="task-meta-chip"><i class="fas fa-star" style="color:var(--cou-l);"></i>${maxCoupon} كوبون</span>
          ${t.time_limit ? `<span class="task-meta-chip"><i class="fas fa-stopwatch"></i>${t.time_limit} دقيقة</span>` : ''}
        </div>
        ${couponRow}
      </div>
    </div>`;
      }).join('');
    }

    // ── Task exam (full-screen, DB-anchored timer, auto-save) ─────────
    let examStartedAt = null;   // Date set from server
    let examTimerIv = null;   // countdown interval
    let examTimeLimitSec = 0;      // seconds total

    async function openTask(id) {
      if (isViewingOther()) {
        toast('غير مسموح في وضع المعاينة', 'err');
        return;
      }
      const t = allTasks.find(x => x.id == id); if (!t) return;

      // Automatically dismiss task announcements when the task is opened
      const dismissedIds = JSON.parse(localStorage.getItem('dismissedAnns_' + student.id) || '[]');
      let changed = false;
      allAnnouncements.forEach(ann => {
        const isTaskAnn = ann.type === 'task' || (ann.text && (ann.text.includes(t.title) || ann.text.includes('تاسك')));
        if (isTaskAnn && !dismissedIds.includes(parseInt(ann.id))) {
          dismissedIds.push(parseInt(ann.id));
          changed = true;
        }
      });
      if (changed) {
        localStorage.setItem('dismissedAnns_' + student.id, JSON.stringify(dismissedIds));
        loadAnn();
      }
      const st = tSt(t);
      if (st === 'done' && t.my_submission) { showExamResult(t, t.my_submission); return; }
      if (st === 'upcoming') { toast('هذا التاسك لم تُفتح بعد', 'info'); return; }
      if (st === 'expired' && !t.my_submission) { toast('انتهت فترة التسليم', 'err'); return; }
      curTask = t; examDone = false;

      if (t.time_limit) {
        examTimeLimitSec = t.time_limit * 60;
        taskAnswers = JSON.parse(localStorage.getItem(`ta_${t.id}_${student.id}`) || '{}');

        // ── 1. Check localStorage first (instant, no network) ──────
        const lsKey = `examStart_${t.id}_${student.id}`;
        const lsStart = localStorage.getItem(lsKey);
        if (lsStart) {
          const parsed = new Date(lsStart);
          const elapsed = Math.floor((Date.now() - parsed.getTime()) / 1000);
          const remaining = examTimeLimitSec - elapsed;
          if (remaining > 0) {
            // Active session in localStorage — show continue
            examStartedAt = parsed;
            showExamStartScreen(t, true, remaining);
            // Also verify/sync with server silently
            _syncExamStart(t, lsStart);
            return;
          } else {
            // Expired in localStorage — clear it and let them start fresh
            localStorage.removeItem(lsKey);
            examStartedAt = null;
          }
        }

        // ── 2. No localStorage record — check server ────────────────
        try {
          const d = await api({ action: 'getExamStart', student_id: student.id, church_id: student.church_id, task_id: t.id });
          if (d.success && d.started_at) {
            const parsed = new Date(d.started_at.replace(' ', 'T'));
            const elapsed = Math.floor((Date.now() - parsed.getTime()) / 1000);
            const remaining = examTimeLimitSec - elapsed;
            if (remaining > 0) {
              // Server has active session — restore it and save to localStorage
              examStartedAt = parsed;
              localStorage.setItem(lsKey, d.started_at.replace(' ', 'T'));
              showExamStartScreen(t, true, remaining);
              return;
            }
            // Server record expired — clear it
            try { await api({ action: 'clearExamStart', student_id: student.id, church_id: student.church_id, task_id: t.id }); } catch (e) { }
            examStartedAt = null;
          }
        } catch (e) { }
      } else {
        taskAnswers = JSON.parse(localStorage.getItem(`ta_${t.id}_${student.id}`) || '{}');
      }

      showExamStartScreen(t, false, 0);
    }

    // Silently sync localStorage start time to server (fire-and-forget)
    async function _syncExamStart(t, startedAtStr) {
      try {
        await api({ action: 'startExam', student_id: student.id, church_id: student.church_id, task_id: t.id });
      } catch (e) { }
    }

    function examMetaRow(bg, bdr, icoColor, icon, label, sub) {
      return `<div class="exam-meta-row" style="background:${bg};border:1px solid ${bdr};">
    <i class="${icon}" style="color:${icoColor};"></i>
    <div class="em-text">
      <div>${label}</div>
      ${sub ? `<div class="em-sub">${sub}</div>` : ''}
    </div>
  </div>`;
    }

    // isResume=true → student already started, show "استكمل" with remaining time
    function showExamStartScreen(t, isResume, remainingSec) {
      const qs = (t.questions || []).length;
      const matrix = t.coupon_matrix ? (typeof t.coupon_matrix === 'string' ? JSON.parse(t.coupon_matrix || '[]') : t.coupon_matrix) : [];
      const maxCoupon = t.max_coupons ? parseInt(t.max_coupons) : (matrix.length ? Math.max(...matrix.map(m => parseInt(m.val) || 0)) : 0);
      document.getElementById('startTitle').textContent = t.title;
      document.getElementById('startSub').textContent = `${qs} سؤال · ${maxCoupon} كوبون`;
      const lsKey = `examStart_${t.id}_${student.id}`;
      const hasSaved = Object.keys(JSON.parse(localStorage.getItem(`ta_${t.id}_${student.id}`) || '{}')).length > 0;
      const hasStart = !!localStorage.getItem(lsKey);
      const rows = [];

      if (t.time_limit) {
        if ((isResume || hasStart) && remainingSec > 0) {
          const remM = Math.floor(remainingSec / 60);
          const remS = remainingSec % 60;
          const remStr = `${remM}:${String(remS).padStart(2, '0')}`;
          rows.push(examMetaRow('#fef3c7', '#fde68a', '#d97706', 'fas fa-stopwatch',
            `الوقت المتبقي: <strong style="font-size:1.1em;color:#b45309;">${remStr}</strong>`,
            'الوقت يعمل من السيرفر — يستكمل من حيث توقف'));
        } else {
          rows.push(examMetaRow('var(--warn-bg)', '#fde68a', 'var(--warn)', 'fas fa-stopwatch',
            `مدة الاختبار: <strong>${t.time_limit} دقيقة</strong>`,
            'يبدأ العد فور الضغط على ابدأ ويُسجَّل في السيرفر'));
        }
      } else {
        rows.push(examMetaRow('var(--ok-bg)', '#6ee7b7', 'var(--ok)', 'fas fa-infinity',
          'لا يوجد وقت محدد — أجب بتأنٍّ', null));
      }
      rows.push(examMetaRow('var(--s2)', 'var(--bdr)', 'var(--brand)', 'fas fa-list-ol', `${qs} سؤال`, null));
      rows.push(examMetaRow('var(--s2)', 'var(--bdr)', 'var(--gold-l)', 'fas fa-star', `حتى ${maxCoupon} كوبون`, null));
      if (hasSaved || isResume || hasStart) {
        rows.push(examMetaRow('var(--brand-bg)', 'var(--brand-l)', 'var(--brand)', 'fas fa-layer-group',
          'إجاباتك السابقة محفوظة وستُستكمل', null));
      }
      document.getElementById('startMeta').innerHTML = rows.join('');

      const startBtn = document.getElementById('examStartBtn');
      if (startBtn) {
        startBtn.innerHTML = (isResume || hasSaved || hasStart)
          ? '<i class="fas fa-play-circle"></i> استكمل الاختبار'
          : '<i class="fas fa-play-circle"></i> ابدأ الاختبار';
      }

      examShowView('start');
      examScreenOpen();
    }

    async function beginExam() {
      if (!curTask) return;
      const t = curTask;
      taskAnswers = JSON.parse(localStorage.getItem(`ta_${t.id}_${student.id}`) || '{}');

      if (t.time_limit) {
        examTimeLimitSec = t.time_limit * 60;
        const lsKey = `examStart_${t.id}_${student.id}`;

        if (!examStartedAt) {
          // Fresh start — save to localStorage immediately before any network call
          const now = new Date();
          examStartedAt = now;
          localStorage.setItem(lsKey, now.toISOString());
          // Record on server too (INSERT IGNORE keeps original if already exists)
          try {
            const d = await api({ action: 'startExam', student_id: student.id, church_id: student.church_id, task_id: t.id });
            if (d.started_at) {
              const serverTime = new Date(d.started_at.replace(' ', 'T'));
              const diff = Math.abs(serverTime.getTime() - now.getTime());
              if (diff < 10000) { // within 10s — prefer server time
                examStartedAt = serverTime;
                localStorage.setItem(lsKey, d.started_at.replace(' ', 'T'));
              }
            }
          } catch (e) { } // localStorage fallback already saved — safe to ignore
        }
        // If examStartedAt already set (resume path) — use as-is, timer continues
      }

      const matrix = t.coupon_matrix ? (typeof t.coupon_matrix === 'string' ? JSON.parse(t.coupon_matrix || '[]') : t.coupon_matrix) : [];
      const maxCoupon = t.max_coupons ? parseInt(t.max_coupons) : (matrix.length ? Math.max(...matrix.map(m => parseInt(m.val) || 0)) : 0);

      document.getElementById('examHeaderTitle').textContent = t.title;
      document.getElementById('examHeaderSub').textContent = `${(t.questions || []).length} سؤال — ${maxCoupon} كوبون`;
      document.getElementById('examTotalQ').textContent = (t.questions || []).length;
      renderExamQuestions(t);
      examShowView('active');
      if (t.time_limit) startExamCountdown(t);
    }

    function seededShuffle(arr, seedStr) {
      let hash = 0;
      for (let i = 0; i < seedStr.length; i++) {
        hash = ((hash << 5) - hash) + seedStr.charCodeAt(i);
        hash |= 0;
      }
      let copy = arr.slice();
      for (let i = copy.length - 1; i > 0; i--) {
        hash = ((hash * 9301 + 49297) % 233280);
        let j = Math.floor((Math.abs(hash) / 233280) * (i + 1));
        let temp = copy[i];
        copy[i] = copy[j];
        copy[j] = temp;
      }
      return copy;
    }

    function renderExamQuestions(t) {
      let qs = t.questions || [];
      if (parseInt(t.shuffle || 0) === 1 && qs.length > 1) {
        if (!t._shuffledQuestions) {
          t._shuffledQuestions = seededShuffle(qs, `${student.id}_task_${t.id}_qs`);
        }
        qs = t._shuffledQuestions;
        t.questions = qs;
      }
      document.getElementById('examQList').innerHTML = qs.map((q, i) => {
        const qtype = q.question_type || 'mcq';
        const imgHtml = q.image_url
          ? `<div style="margin:0 0 2px;"><img src="${esc(q.image_url)}" alt="" style="width:100%;max-height:200px;object-fit:contain;display:block;background:var(--s2);"></div>`
          : '';

        const getSaved = (qid, idx) => {
          if (taskAnswers[String(qid)] !== undefined) return taskAnswers[String(qid)];
          if (taskAnswers[qid] !== undefined) return taskAnswers[qid];
          if (taskAnswers[idx] !== undefined) return taskAnswers[idx];
          if (taskAnswers['q_' + idx] !== undefined) return taskAnswers['q_' + idx];
          return undefined;
        };

        // ── Open / essay ──────────────────────────────────────────
        if (qtype === 'open') {
          const savedAns = getSaved(q.id, i) || '';
          const deg = parseInt(q.degree) || 0;
          return `<div class="qcard" id="qc_${q.id}">
        ${imgHtml}
        <div class="qhdr">
          <div class="qnum" style="background:linear-gradient(135deg,#f59e0b,#d97706);">${i + 1}</div>
          <div class="qtext">${esc(q.question_text)}</div>
          <span style="background:#fef3c7;color:#92400e;border-radius:var(--r-full);padding:2px 8px;font-size:.65rem;font-weight:700;flex-shrink:0;"><i class="fas fa-pen-nib"></i> مفتوح</span>
          <span class="qdeg" style="background:#fef3c7;color:#d97706;">${deg} درجة</span>
        </div>
        <div class="qopts" style="padding:10px 12px;display:block;">
          <textarea class="open-ans-textarea" id="openans_${q.id}"
            placeholder="اكتب إجابتك هنا…"
            oninput="pickOpenAns(${q.id},this)">${esc(savedAns)}</textarea>
          ${deg > 0 ? `<div style="font-size:.69rem;color:var(--t4);margin-top:5px;display:flex;align-items:center;gap:4px;">
            <i class="fas fa-info-circle"></i> يُصحَّح من قِبَل الانكل أو الطنط — الدرجة النهائية ستظهر بعد التصحيح
          </div>` : ''}
        </div>
      </div>`;
        }

        // ── True / False ──────────────────────────────────────────
        if (qtype === 'tf') {
          const saved = getSaved(q.id, i);
          const trueOn = saved === 0; const falseOn = saved === 1;
          const deg = parseInt(q.degree) || 0;
          return `<div class="qcard" id="qc_${q.id}">
        ${imgHtml}
        <div class="qhdr">
          <div class="qnum">${i + 1}</div>
          <div class="qtext">${esc(q.question_text)}</div>
          <span class="qdeg">${deg} درجة</span>
        </div>
        <div class="qopts" style="display:flex;gap:10px;padding:10px 12px;">
          <button id="tfbtn_${q.id}_0" onclick="pickOpt(${q.id},0,null)"
            style="flex:1;padding:13px 8px;border-radius:var(--r-sm);border:2px solid ${trueOn ? 'var(--ok)' : 'var(--bdr)'};background:${trueOn ? 'var(--ok-bg)' : 'var(--surf)'};color:${trueOn ? 'var(--ok)' : 'var(--t2)'};font-family:inherit;font-size:.88rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;transition:var(--fast);">
            <i class="fas fa-check-circle"></i> صحيح
          </button>
          <button id="tfbtn_${q.id}_1" onclick="pickOpt(${q.id},1,null)"
            style="flex:1;padding:13px 8px;border-radius:var(--r-sm);border:2px solid ${falseOn ? 'var(--err)' : 'var(--bdr)'};background:${falseOn ? 'var(--err-bg)' : 'var(--surf)'};color:${falseOn ? 'var(--err)' : 'var(--t2)'};font-family:inherit;font-size:.88rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;transition:var(--fast);">
            <i class="fas fa-times-circle"></i> خطأ
          </button>
        </div>
      </div>`;
        }

        // ── MCQ (default) ─────────────────────────────────────────
        const rawOpts = typeof q.options === 'string' ? JSON.parse(q.options) : (q.options || []);
        let optsWithIndices = rawOpts.map((o, j) => ({ text: o, origIdx: j }));
        if (parseInt(t.shuffle_answers || 0) === 1 && optsWithIndices.length > 1) {
          optsWithIndices = seededShuffle(optsWithIndices, `${student.id}_task_${t.id}_q_${q.id}_opts`);
        }
        const savedVal = getSaved(q.id, i);
        const sel = savedVal !== undefined ? savedVal : null;
        const deg = parseInt(q.degree) || 0;
        return `<div class="qcard" id="qc_${q.id}">
      ${imgHtml}
      <div class="qhdr">
        <div class="qnum">${i + 1}</div>
        <div class="qtext">${esc(q.question_text)}</div>
        ${sel !== null ? `<span style="background:var(--ok-bg);color:var(--ok);border-radius:var(--r-full);padding:2px 7px;font-size:.62rem;font-weight:700;flex-shrink:0;"><i class="fas fa-check"></i></span>` : ''}
        <span class="qdeg">${deg} درجة</span>
      </div>
      <div class="qopts">${optsWithIndices.map((item, displayIdx) => {
        const isSelected = sel !== null && parseInt(sel) === item.origIdx;
        return `<div class="qopt${isSelected ? ' selected' : ''}" onclick="pickOpt(${q.id},${item.origIdx},this)"><div class="oradio"></div><div class="olet">${LETTERS[displayIdx]}</div>${esc(item.text)}</div>`;
      }).join('')}</div>
    </div>`;
      }).join('');
      updExamProgress(t);
    }

    function pickOpt(qid, idx, el) {
      if (examDone) return;
      document.getElementById(`qc_${qid}`)?.classList.remove('qcard-unanswered');
      taskAnswers[String(qid)] = idx;
      localStorage.setItem(`ta_${curTask.id}_${student.id}`, JSON.stringify(taskAnswers));
      // MCQ: toggle selected class
      if (el && el.closest && el.closest('.qopts')) {
        el.closest('.qopts').querySelectorAll('.qopt').forEach(o => o.classList.remove('selected'));
        el.classList.add('selected');
      }
      // TF: re-render TF buttons with updated state
      const tfT = document.getElementById(`tfbtn_${qid}_0`);
      const tfF = document.getElementById(`tfbtn_${qid}_1`);
      if (tfT && tfF) {
        const isTrueSelected = (idx === 0);
        tfT.style.border = `2px solid ${isTrueSelected ? '#10b981' : 'var(--bdr)'}`;
        tfT.style.background = isTrueSelected ? '#d1fae5' : 'var(--s2)';
        tfT.style.color = isTrueSelected ? '#065f46' : 'var(--t2)';
        tfT.querySelector('i').style.color = isTrueSelected ? '#10b981' : 'var(--t4)';
        tfF.style.border = `2px solid ${!isTrueSelected ? '#ef4444' : 'var(--bdr)'}`;
        tfF.style.background = !isTrueSelected ? '#fee2e2' : 'var(--s2)';
        tfF.style.color = !isTrueSelected ? '#991b1b' : 'var(--t2)';
        tfF.querySelector('i').style.color = !isTrueSelected ? '#ef4444' : 'var(--t4)';
      }
      updExamProgress(curTask);
    }

    function pickOpenAns(qid, textarea) {
      if (examDone) { textarea.value = taskAnswers[qid] || ''; return; }
      if (textarea.value.trim()) {
        document.getElementById(`qc_${qid}`)?.classList.remove('qcard-unanswered');
      }
      taskAnswers[String(qid)] = textarea.value;
      localStorage.setItem(`ta_${curTask.id}_${student.id}`, JSON.stringify(taskAnswers));
      updExamProgress(curTask);
    }

    function buildResultCard(score, total, pct, coupons, hasOpenQs, taskId, showAnswers, isGraded = true) {
      let iconCls, msg, badgeBg, badgeColor;
      if (!isGraded) {
        iconCls = 'fas fa-hourglass-half';
        msg = 'تم تسليم إجاباتك بنجاح! التكليف في انتظار تصحيح المدرس.';
        badgeBg = 'var(--warn-bg, rgba(217,119,6,0.1))';
        badgeColor = 'var(--warn, #d97706)';
      } else if (pct >= 90) {
        iconCls = 'fas fa-trophy';
        msg = 'ممتاز! إجاباتك رائعة، أنت نجم الفصل!';
        badgeBg = 'var(--ok-bg, rgba(5,150,105,0.1))';
        badgeColor = 'var(--ok, #059669)';
      } else if (pct >= 70) {
        iconCls = 'fas fa-medal';
        msg = 'أحسنت جداً! نتيجة جميلة وأنت تستاهل أكثر من كده.';
        badgeBg = 'rgba(37,99,235,0.1)';
        badgeColor = '#2563eb';
      } else if (pct >= 50) {
        iconCls = 'fas fa-star';
        msg = 'برافو! في تحسّن واضح وأنت على الطريق الصح.';
        badgeBg = 'var(--warn-bg, rgba(217,119,6,0.1))';
        badgeColor = 'var(--warn, #d97706)';
      } else {
        iconCls = 'fas fa-heart';
        msg = 'شكراً على مشاركتك! كل خطوة بتخليك أقوى وأحسن.';
        badgeBg = 'rgba(79,70,229,0.1)';
        badgeColor = '#4f46e5';
      }

      const couponHtml = (isGraded && coupons > 0) ? `
    <div style="display:inline-flex;align-items:center;gap:8px;padding:8px 18px;background:var(--cou-bg, rgba(234,179,8,0.15));color:var(--cou, #d97706);border-radius:20px;font-weight:800;font-size:1.05rem;margin-top:14px;">
      <i class="fas fa-star"></i> حصلت على ${coupons} كوبون!
    </div>` : '';

      const pendingNote = !isGraded ? `
    <div style="display:flex;align-items:center;gap:10px;margin-top:16px;padding:12px 16px;background:var(--warn-bg, rgba(217,119,6,0.08));border:1px solid rgba(217,119,6,0.2);border-radius:12px;font-size:.85rem;color:var(--t1);font-weight:700;line-height:1.5;">
      <i class="fas fa-clock" style="font-size:1.1rem;flex-shrink:0;color:var(--warn, #d97706);"></i>
      <span>لم يتم تحديد الدرجة بعد. ستظهر النتيجة والكوبونات فور قيام الخادم بتصحيح التكليف.</span>
    </div>` : '';

      const scoreBannerHtml = isGraded
        ? `<div style="font-size:3.2rem;font-weight:900;color:var(--t1);line-height:1.1;">${score} <span style="font-size:1.3rem;color:var(--t3);font-weight:700;">/ ${total}</span></div>
           <div style="color:${badgeColor};font-weight:800;font-size:1.2rem;margin-top:4px;">نسبة النجاح: ${pct}%</div>`
        : `<div style="font-size:1.5rem;font-weight:900;color:var(--t1);line-height:1.3;"><i class="fas fa-clock" style="color:var(--warn);margin-left:4px;"></i> في انتظار تصحيح الخادم</div>
           <div style="color:var(--t3);font-weight:700;font-size:.95rem;margin-top:4px;">لم يتم حساب الدرجة أو الكوبونات بعد</div>`;

      return `
    <div style="background:var(--surf, var(--bg, #ffffff));border-radius:20px;padding:28px 22px 22px;border:1px solid var(--bdr);box-shadow:var(--sh-md);text-align:center;position:relative;">
      <button onclick="exitExamScreen()" title="إغلاق" style="position:absolute;top:16px;left:16px;background:var(--s2);border:1px solid var(--bdr);color:var(--t1);width:32px;height:32px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:0.2s;z-index:2;">
        <i class="fas fa-times"></i>
      </button>
      <div style="width:72px;height:72px;background:${badgeBg};color:${badgeColor};border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:2.2rem;">
        <i class="${iconCls}"></i>
      </div>
      ${scoreBannerHtml}
      <div style="font-size:1.15rem;font-weight:800;color:var(--t1);line-height:1.4;margin-top:16px;">${msg}</div>
      ${couponHtml}
      ${pendingNote}
      ${showAnswers && taskId ? `
      <div style="margin-top:24px;">
        <button onclick="viewMyAnswers(${taskId})" style="width:100%;padding:13px;border-radius:12px;background:var(--brand, #4f46e5);border:none;color:#fff;font-family:inherit;font-weight:800;font-size:.92rem;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:0.2s;"><i class="fas fa-eye"></i> راجع إجاباتك وتعلم من أخطائك</button>
      </div>` : ''}
    </div>
  `;
    }
    function showExamResult(t, sub) {
      curTask = t; examDone = true;
      const hasOpenQs = (t.questions || []).some(q => q.question_type === 'open' && (parseInt(q.degree) || 0) > 0);
      const isGraded = (parseInt(sub?.is_graded || 0) === 1) || !hasOpenQs;
      const pct = t.total_degree > 0 ? Math.round((sub?.score || 0) / t.total_degree * 100) : 0;
      document.getElementById('examResultCard').innerHTML = buildResultCard(sub?.score || 0, t.total_degree, pct, sub?.coupons_awarded || 0, hasOpenQs, t.id, !!parseInt(t.show_answers || 0), isGraded);
      examShowView('result');
      examScreenOpen();
    }

    function updExamProgress(t) {
      const qs = t ? t.questions || [] : curTask ? curTask.questions || [] : [];
      const total = qs.length;
      let done = 0;
      // Build nav dots and update card borders
      const navEl = document.getElementById('examQNav');
      const dots = qs.map((q, i) => {
        const k = String(q.id);
        const isOpen = q.question_type === 'open';
        let answered = false;
        if (isOpen) answered = !!(taskAnswers[k] && String(taskAnswers[k]).trim());
        else answered = taskAnswers[k] !== undefined;
        if (answered) done++;
        // Update card border to show answered/unanswered visually
        const card = document.getElementById(`qc_${q.id}`);
        if (card) {
          card.style.borderColor = answered ? 'var(--ok)' : 'var(--bdr)';
          card.style.borderWidth = answered ? '2px' : '1.5px';
        }
        return `<div onclick="document.getElementById('qc_${q.id}')?.scrollIntoView({behavior:'smooth',block:'center'})"
      title="سؤال ${i + 1}" style="width:22px;height:22px;border-radius:50%;cursor:pointer;
        display:flex;align-items:center;justify-content:center;font-size:.6rem;font-weight:800;
        flex-shrink:0;transition:all .2s;
        background:${answered ? 'var(--ok)' : 'var(--bdr)'};
        color:${answered ? '#fff' : 'var(--t3)'};
        box-shadow:${answered ? '0 2px 6px rgba(5,150,105,.3)' : 'none'};"
    >${i + 1}</div>`;
      });
      if (navEl) navEl.innerHTML = dots.join('');
      const pct = total > 0 ? Math.round(done / total * 100) : 0;
      const el = document.getElementById('examAnsDone'); if (el) el.textContent = done;
      const pb = document.getElementById('examProgBar'); if (pb) pb.style.width = pct + '%';
    }

    function startExamCountdown(t) {
      clearInterval(examTimerIv);
      const beh = t.timer_behavior || 'submit';
      const tick = () => {
        if (!examStartedAt || !examTimeLimitSec) return;
        const elapsed = Math.floor((Date.now() - examStartedAt.getTime()) / 1000);
        const rem = examTimeLimitSec - elapsed;
        const badge = document.getElementById('examTimerBadge');
        // Guard: only fire expire logic if exam is actually active on screen
        if (rem <= 0) {
          clearInterval(examTimerIv);
          if (badge) { badge.textContent = '00:00'; badge.classList.add('urgent'); }
          // Only auto-submit if exam screen is open and not already done
          if (!examDone) {
            if (beh === 'submit') _doSubmitExam();
            else { examDone = true; toast('انتهى الوقت', 'err'); }
          }
          return;
        }
        const m = Math.floor(rem / 60).toString().padStart(2, '0');
        const s = (rem % 60).toString().padStart(2, '0');
        const urg = rem <= 60;
        if (badge) {
          badge.style.display = 'block';
          badge.innerHTML = `<i class="fas fa-stopwatch" style="font-size:.75rem;margin-left:4px;"></i>${m}:${s}`;
          badge.classList.toggle('urgent', urg);
        }
      };
      // Delay first tick by 1s so the UI finishes rendering before any expire check
      examTimerIv = setInterval(tick, 1000);
      setTimeout(tick, 500);
    }

    function examScreenOpen() {
      const scr = document.getElementById('examScreen');
      const resultView = document.getElementById('examResultView');
      const isResult = resultView && resultView.style.display !== 'none';
      if (isResult) {
        scr.style.display = 'flex';
        scr.style.alignItems = 'center';
        scr.style.justifyContent = 'center';
      } else {
        scr.style.display = 'block';
      }
      scr.scrollTop = 0;
      document.documentElement.classList.add('ov-open');
    }

    function examScreenClose() {
      clearInterval(examTimerIv);
      const scr = document.getElementById('examScreen');
      if (scr) {
        scr.style.display = 'none';
        scr.style.background = 'var(--bg)';
        scr.style.backdropFilter = 'none';
        scr.style.webkitBackdropFilter = 'none';
      }
      ['examStartView', 'examActiveView', 'examResultView'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
      });
      if (!document.querySelector('.overlay.open')) document.documentElement.classList.remove('ov-open');
      curTask = null; examDone = false; examStartedAt = null; taskAnswers = {};
    }

    function confirmExitExam() {
      if (examDone) { examScreenClose(); return; }
      if (Object.keys(taskAnswers).length > 0) {
        document.getElementById('scModalMsg').textContent = 'إجاباتك محفوظة تلقائياً. هل تريد الخروج؟';
        document.getElementById('exitConfirmModal').classList.add('open');
        document.documentElement.classList.add('ov-open');
        return;
      }
      examScreenClose();
    }
    function _closeExitConfirm() {
      document.getElementById('exitConfirmModal').classList.remove('open');
      if (!document.querySelector('.overlay.open')) document.documentElement.classList.remove('ov-open');
    }
    function _confirmExit() {
      _closeExitConfirm();
      examScreenClose();
    }

    // alias used by result "back" button
    function exitExamScreen() { examScreenClose(); }

    function examShowView(which) {
      ['examStartView', 'examActiveView', 'examResultView'].forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.style.display = 'none'; }
      });
      const map = { start: 'examStartView', active: 'examActiveView', result: 'examResultView' };
      const el = document.getElementById(map[which]);
      const scr = document.getElementById('examScreen');
      if (which === 'result') {
        scr.style.background = 'rgba(15, 23, 42, 0.65)';
        scr.style.backdropFilter = 'none';
        scr.style.webkitBackdropFilter = 'none';
        scr.style.display = 'flex';
        scr.style.alignItems = 'center';
        scr.style.justifyContent = 'center';
      } else {
        scr.style.background = 'var(--bg)';
        scr.style.backdropFilter = 'none';
        scr.style.webkitBackdropFilter = 'none';
        scr.style.display = 'block';
      }
      if (el) { el.style.display = 'flex'; }
    }


    function _legacyBuildResultCard(score, total, pct, coupons, hasOpenQs, taskId, showAnswers) {
      let grad, iconCls, msg, color;
      if (pct >= 90) {
        grad = 'linear-gradient(135deg, #059669 0%, #10b981 100%)';
        iconCls = 'fas fa-trophy';
        msg = 'ممتاز! إجاباتك رائعة، أنت نجم الفصل!';
        color = '#059669';
      } else if (pct >= 70) {
        grad = 'linear-gradient(135deg, #1d4ed8 0%, #3b82f6 100%)';
        iconCls = 'fas fa-medal';
        msg = 'أحسنت جداً! نتيجة جميلة وأنت تستاهل أكثر من كده.';
        color = '#1d4ed8';
      } else if (pct >= 50) {
        grad = 'linear-gradient(135deg, #b45309 0%, #f59e0b 100%)';
        iconCls = 'fas fa-star';
        msg = 'برافو! في تحسّن واضح وأنت على الطريق الصح.';
        color = '#b45309';
      } else {
        grad = 'linear-gradient(135deg, #4f46e5 0%, #818cf8 100%)';
        iconCls = 'fas fa-heart';
        msg = 'شكراً على مشاركتك! كل خطوة بتخليك أقوى وأحسن.';
        color = '#4f46e5';
      }

      const couponHtml = coupons > 0 ? `
    <div style="display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:var(--cou-bg);color:var(--cou);border-radius:var(--r-full);font-weight:800;font-size:1.1rem;box-shadow:0 4px 15px rgba(124,58,237,.2);margin-top:15px;animation:bounce 2s infinite;">
      <i class="fas fa-star"></i> حصلت على ${coupons} كوبون!
    </div>` : '';

      const pendingNote = hasOpenQs ? `
    <div style="display:flex;align-items:center;gap:10px;margin-top:20px;padding:12px 18px;background:var(--warn-bg);border:1.5px solid #fde68a;border-radius:var(--r-md);font-size:.85rem;color:#92400e;font-weight:700;line-height:1.5;">
      <i class="fas fa-clock" style="font-size:1.2rem;flex-shrink:0;"></i>
      <span>هذه درجة مؤقتة — الأسئلة المفتوحة يتم تصحيحها يدوياً. الدرجة النهائية ستظهر قريباً!</span>
    </div>` : '';

      return `
    <div style="background:#fff;border-radius:var(--r-2xl);overflow:hidden;box-shadow:var(--sh-xl);animation:pop-in .5s var(--norm);">
      <div style="background:${grad};padding:40px 20px 60px;text-align:center;position:relative;">
        <div style="width:100px;height:100px;background:rgba(255,255,255,.2);backdrop-filter:blur(10px);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;border:3px solid #fff;box-shadow:0 8px 30px rgba(0,0,0,.2);">
          <i class="${iconCls}" style="color:#fff;font-size:3.2rem;"></i>
        </div>
        <div style="font-size:4rem;font-weight:900;color:#fff;line-height:1;text-shadow:0 4px 15px rgba(0,0,0,.2);">${score} <span style="font-size:1.5rem;opacity:.7;">/ ${total}</span></div>
        <div style="color:rgba(255,255,255,.9);font-weight:800;font-size:1.3rem;margin-top:5px;">${hasOpenQs ? 'درجة مؤقتة' : pct + '%'}</div>
        <div style="position:absolute;bottom:-30px;left:0;right:0;height:40px;background:#fff;clip-path:ellipse(55% 100% at 50% 100%);"></div>
      </div>
      <div style="padding:40px 30px 30px;text-align:center;">
        <div style="font-size:1.35rem;font-weight:800;color:var(--t1);line-height:1.4;margin-bottom:10px;">${msg}</div>
        ${couponHtml}
        ${pendingNote}
        <div style="margin-top:35px;display:flex;flex-direction:column;gap:12px;">
          ${showAnswers && taskId ? `<button onclick="viewMyAnswers(${taskId})" style="width:100%;padding:14px;border-radius:var(--r-lg);background:var(--s2);border:2.5px solid ${color};color:${color};font-family:inherit;font-weight:800;font-size:.95rem;cursor:pointer;transition:var(--fast);display:flex;align-items:center;justify-content:center;gap:10px;"><i class="fas fa-eye"></i> راجع إجاباتك وتعلم من أخطائك</button>` : ''}
          <button onclick="exitExamScreen()" style="width:100%;padding:14px;border-radius:var(--r-lg);background:${color};border:none;color:#fff;font-family:inherit;font-weight:800;font-size:.95rem;cursor:pointer;box-shadow:0 6px 20px ${color}44;transition:var(--fast);">
             العودة للملف الشخصي
          </button>
        </div>
      </div>
    </div>
    <style>
      @keyframes pop-in { from { transform: scale(.8); opacity: 0; } to { transform: scale(1); opacity: 1; } }
      @keyframes bounce { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-5px); } }
  


  </style>
  `;
    }
    function _legacyShowExamResult(t, sub) {
      curTask = t; examDone = true;
      const pct = t.total_degree > 0 ? Math.round(sub.score / t.total_degree * 100) : 0;
      const hasOpenQs = (t.questions || []).some(q => q.question_type === 'open' && (parseInt(q.degree) || 0) > 0);
      document.getElementById('examResultCard').innerHTML = buildResultCard(sub.score, t.total_degree, pct, sub.coupons_awarded, hasOpenQs, t.id, !!parseInt(t.show_answers || 0));
      examShowView('result');
      examScreenOpen();
    }

    // legacy stubs
    function openExamResult(t) { showExamResult(t, t.my_submission); }
    function renderExam(t) { renderExamQuestions(t); }
    function updProg(t) { updExamProgress(t); }
    function updAnsCnt(t) { updExamProgress(t); }
    function saveCloseExam() { examScreenClose(); }
    function closeExam() { examScreenClose(); }

    let isSubmittingExam = false;
    let _pendingUnansweredQs = [];

    async function submitExam() {
      if (isViewingOther()) {
        toast('غير مسموح في وضع المعاينة', 'err');
        return;
      }
      if (!curTask || examDone || isSubmittingExam) return;
      const qs = curTask.questions || [];
      // Count unanswered questions of all types (excluding 0-degree survey/optional questions)
      const unansweredQs = qs.filter(q => {
        if ((parseInt(q.degree) || 0) <= 0) return false;
        const k = String(q.id);
        if (q.question_type === 'open') return !taskAnswers[k] || !String(taskAnswers[k]).trim();
        return taskAnswers[k] === undefined && taskAnswers[q.id] === undefined;
      });
      if (unansweredQs.length > 0) {
        _showSubmitConfirm(unansweredQs);
        return;
      }
      await _doSubmitExam();
    }

    function _showSubmitConfirm(unansweredList) {
      _pendingUnansweredQs = Array.isArray(unansweredList) ? unansweredList : [];
      const count = _pendingUnansweredQs.length;
      const msgEl = document.getElementById('scModalMsg');
      if (msgEl) {
        msgEl.innerHTML = `<div style="font-size:1.02rem;font-weight:800;color:var(--err);margin-bottom:6px;">
          <i class="fas fa-exclamation-circle"></i> لم تُجب على ${count} سؤال!
        </div>
        <div style="font-size:.85rem;color:var(--t2);font-weight:500;line-height:1.5;">
          يُفضَّل إكمال جميع الأسئلة قبل التسليم لتحصل على كامل درجاتك وكوبوناتك.
        </div>`;
      }
      document.getElementById('submitConfirmModal').classList.add('open');
      document.documentElement.classList.add('ov-open');
    }

    function _closeSubmitConfirm() {
      document.getElementById('submitConfirmModal').classList.remove('open');
      if (!document.querySelector('.overlay.open')) document.documentElement.classList.remove('ov-open');
    }

    function _reviewUnansweredQuestions() {
      _closeSubmitConfirm();
      if (!_pendingUnansweredQs || !_pendingUnansweredQs.length) return;
      _pendingUnansweredQs.forEach(q => {
        const qc = document.getElementById(`qc_${q.id}`);
        if (qc) qc.classList.add('qcard-unanswered');
      });
      const firstQ = _pendingUnansweredQs[0];
      const firstEl = document.getElementById(`qc_${firstQ.id}`);
      if (firstEl) {
        firstEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        if (firstQ.question_type === 'open') {
          setTimeout(() => {
            const ta = document.getElementById(`openans_${firstQ.id}`);
            if (ta) ta.focus();
          }, 350);
        }
      }
      const qIndex = (curTask?.questions || []).findIndex(x => x.id === firstQ.id) + 1;
      toast(`انتقلنا إلى سؤال ${qIndex} لإكماله`, 'warn');
    }

    function _confirmSubmitExam() {
      _closeSubmitConfirm();
      _doSubmitExam();
    }

    async function _doSubmitExam() {
      if (!curTask || examDone || isSubmittingExam) return;
      isSubmittingExam = true;
      const submitBtn = document.querySelector('.exam-footer .btn-p');
      const origBtnHtml = submitBtn ? submitBtn.innerHTML : '';
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري التسليم…';
      }
      clearInterval(examTimerIv);
      const tt = examStartedAt ? Math.floor((Date.now() - examStartedAt.getTime()) / 1000) : null;
      try {
        const d = await api({ action: 'submitTaskAnswers', student_id: student.id, church_id: student.church_id, task_id: curTask.id, answers: JSON.stringify(taskAnswers), time_taken_sec: tt });
        localStorage.removeItem(`ta_${curTask.id}_${student.id}`);
        localStorage.removeItem(`examStart_${curTask.id}_${student.id}`);
        if (d.success) {
          examDone = true;
          // Build a complete my_submission object so viewMyAnswers works immediately
          // without waiting for loadTasks() to re-fetch
          const mySubmission = {
            id: d.submission_id || null,
            score: d.score || 0,
            coupons_awarded: d.coupons_awarded || 0,
            submitted_at: new Date().toISOString(),
            answers: taskAnswers,                          // the answers we just sent
            correct_answers: d.questions_with_answers      // full questions with correct_index from API
              ? Object.fromEntries((d.questions_with_answers || []).filter(q => q.question_type !== 'open').map(q => [q.id, parseInt(q.correct_index)]))
              : (curTask.my_submission?.correct_answers || {}),
          };
          // Patch the task in allTasks so viewMyAnswers can find it
          curTask.my_submission = mySubmission;
          const idx = allTasks.findIndex(x => x.id === curTask.id);
          if (idx >= 0) allTasks[idx].my_submission = mySubmission;

          // Always refresh student data from server after submission
          // so coupons (task + total) reflect the real DB values
          try {
            const sd = await api({ action: 'getStudentProfile', studentId: student.id });
            if (sd.success && (sd.student || sd.user)) {
              const fresh = norm(sd.student || sd.user);
              // preserve fields not returned by getStudentProfile
              student.coupons = fresh.coupons;
              student.task_coupons = fresh.task_coupons;
              student.att_coupons = fresh.att_coupons;
              student.com_coupons = fresh.com_coupons;
            }
          } catch (_) { }
          // Re-render coupon hero with fresh values
          renderCouponHero(student);
          document.getElementById('couponHero').style.display = 'grid';
          if (d.show_result) {
            const hasOpenQs = (curTask.questions || []).some(q => q.question_type === 'open' && (parseInt(q.degree) || 0) > 0);
            const isGraded = (parseInt(d.is_graded ?? 0) === 1) || !hasOpenQs;
            document.getElementById('examResultCard').innerHTML = buildResultCard(d.score, curTask.total_degree, d.percentage ?? 0, d.coupons_awarded ?? 0, hasOpenQs, curTask.id, !!d.show_answers, isGraded);
            examShowView('result');
          } else { toast('تم التسليم ✓', 'ok'); examScreenClose(); }
          loadTasks();
        } else {
          // If already submitted previously, don't trap student in the exam form
          if (d.message && d.message.includes('بالفعل')) {
            examDone = true;
            toast(d.message, 'warn');
            examScreenClose();
            loadTasks();
          } else {
            examDone = false;
            toast(d.message || 'فشل التسليم', 'err');
          }
        }
      } catch (e) {
        examDone = false;
        toast('خطأ في الاتصال', 'err');
      } finally {
        isSubmittingExam = false;
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = origBtnHtml;
        }
      }
    }

    // ── Trips ─────────────────────────────────────────────────────────
    async function loadTrips(isPrivate) {
      if (!student?.church_id) return;
      try {
        const params = { action: 'getStudentTrips', church_id: student.church_id };
        if (isPrivate && student.id) params.student_id = student.id;
        const d = await api(params);
        if (d.success && d.trips && d.trips.length) {
          allTrips = d.trips;
          renderTrips(d.trips);
          const currentTab = document.querySelector('.bottom-nav-item.active')?.getAttribute('data-tab') || 'home';
          if (currentTab === 'home') {
            document.getElementById('scTrips').style.display = 'block';
          }
        } else {
          // No trips — keep section hidden
          document.getElementById('scTrips').style.display = 'none';
        }
      } catch (e) {
        document.getElementById('scTrips').style.display = 'none';
      }
    }
    function renderTrips(trips) {
      const el = document.getElementById('tripList');
      document.getElementById('tripSub').textContent = trips.length + ' رحلة';
      const stLbl = { planned: 'مخطط', active: 'نشط', completed: 'مكتمل', cancelled: 'ملغي' };
      el.innerHTML = trips.map(t => {
        const hasTripImg = t.image_url && !['0', 'null', 'undefined', 'false', 'none', ''].includes(String(t.image_url).trim().toLowerCase());
        const thumb = hasTripImg
          ? `<img class="trip-thumb" src="${esc(t.image_url)}" alt="${esc(t.title)}" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">`
          : '';
        const ph = `<div class="trip-thumb-placeholder" ${hasTripImg ? 'style="display:none"' : ''}><i class="fas fa-bus"></i><span>${esc(t.title)}</span></div>`;
        const priceOverlay = parseFloat(t.final_price) > 0
          ? `<span class="trip-price-pill main"><i class="fas fa-tag"></i>${parseFloat(t.final_price).toFixed(0)} ج.م</span>`
          : `<span class="trip-price-pill main"><i class="fas fa-gift"></i> مجانية</span>`;
        const myReg = t.my_registration;
        const remOverlay = myReg && parseFloat(myReg.remaining) > 0
          ? `<span class="trip-price-pill remaining"><i class="fas fa-exclamation-circle"></i>متبقي ${parseFloat(myReg.remaining).toFixed(0)} ج.م</span>`
          : '';
        // Kids avatars strip
        const canSeeKids = String(t.show_registered_kids ?? 1) === '1';
        const kids = canSeeKids ? (t.registered_kids || []).slice(0, 6) : [];
        const extra = (t.registered_count || 0) - kids.length;
        const kidsHtml = kids.length ? `<div class="kids-strip">
      ${kids.map(k => `<div class="ka">${k.image_url ? `<img src="${esc(k.image_url)}" alt="${esc(k.name)}">` : k.name.charAt(0)}</div>`).join('')}
      ${extra > 0 ? `<div class="ka ka-more">+${extra}</div>` : ''}
    </div>`: '';
        // "Contact uncle" button if this student is NOT registered
        const notRegistered = !myReg;
        const contactBtn = notRegistered && classUncles.length
          ? `<div class="trip-contact-bar" onclick="event.stopPropagation();openTripContactUncle(${t.id})">
          <i class="fas fa-exclamation-circle"></i>
          <span>اسمك غير موجود في قائمة الرحلة</span>
          <span class="trip-contact-action"><i class="fas fa-comments"></i> تواصل مع المدرّس</span>
         </div>`
          : notRegistered
            ? `<div class="trip-contact-bar unreach">
            <i class="fas fa-info-circle"></i>
            <span>اسمك غير موجود في قائمة الرحلة</span>
           </div>`
            : '';
        return `<div class="trip-card" onclick="openTrip(${t.id})">
      <div class="trip-thumb-wrap">
        ${thumb}${ph}
        <div class="trip-status-overlay ts-${t.status}">${stLbl[t.status] || t.status}</div>
        <div class="trip-price-overlay">${priceOverlay}${remOverlay}</div>
      </div>
      <div class="trip-body">
        <div class="trip-title">${esc(t.title)}</div>
        ${t.description ? `<div class="trip-desc">${esc(t.description)}</div>` : ''}
        <div class="trip-meta-row">
          ${t.start_date_formatted ? `<span class="trip-meta-chip"><i class="fas fa-calendar"></i>${t.start_date_formatted}</span>` : ''}
          <span class="trip-meta-chip"><i class="fas fa-users"></i>${t.registered_count || 0} مسجّل</span>
          ${typeof t.my_points !== 'undefined' ? `<span class="trip-meta-chip"><i class="fas fa-star"></i> ${esc(t.my_points)} نقاط</span>` : ''}
          ${t.max_participants ? `<span class="trip-meta-chip"><i class="fas fa-user-check"></i>أقصى ${t.max_participants}</span>` : ''}
        </div>
        ${canSeeKids ? kidsHtml : ''}
        ${contactBtn}
      </div>
    </div>`;
      }).join('');
    }
    function openTrip(id) {
      const t = allTrips.find(x => x.id == id); if (!t) return;
      document.getElementById('tripOvTitle').textContent = t.title;
      document.getElementById('tripOvSub').textContent = `${t.registered_count || 0} مسجّل`;
      const stLbl = { planned: 'مخطط', active: 'نشط', completed: 'مكتمل', cancelled: 'ملغي' };
      const myReg = t.my_registration;
      const myRegHtml = myReg ? `<div class="my-trip-box">
    <div class="my-trip-title"><i class="fas fa-check-circle"></i> أنت مسجّل في هذه الرحلة</div>
    <div class="my-trip-row">
      <div class="mtr-cell ok"><div class="mtr-val">${parseFloat(myReg.total_paid).toFixed(0)} ج.م</div><div class="mtr-lbl">المدفوع</div></div>
      <div class="mtr-cell${parseFloat(myReg.remaining) > 0 ? ' warn' : 'ok'}"><div class="mtr-val">${parseFloat(myReg.remaining).toFixed(0)} ج.م</div><div class="mtr-lbl">المتبقي</div></div>
    </div>
  </div>`: '';
      // Not-registered contact block
      const notRegistered = !myReg;
      const contactHtml = notRegistered ? buildTripContactHtml(t) : '';
      const canSeeKids = String(t.show_registered_kids ?? 1) === '1';
      const kids = canSeeKids ? (t.registered_kids || []) : [];
      const kidsHtml = !canSeeKids ? '' : (kids.length ? `
    <div style="margin-bottom:5px;font-size:.78rem;font-weight:700;color:var(--t2);">${kids.length} مستخدم مسجّل</div>
    <div class="kids-grid">
      ${kids.map(k => `<div class="kid-tile user-tile">
        <div class="kid-tile-av">${k.image_url ? `<img src="${esc(k.image_url)}" alt="${esc(k.name)}">` : k.name.charAt(0)}</div>
        <div class="kid-tile-name">${esc(k.name)}</div>
        <div class="kid-tile-cls">${esc(k.class || '')}</div>
        <div class="user-role">${esc(((k.church_type || t.church_type || localStorage.getItem('churchType') || 'kids') === 'youth') ? ((k.gender || '').toString().toLowerCase() === 'female' ? 'شابة' : 'شاب') : 'طفل')}</div>
      </div>`).join('')}
    </div>`: `<div class="empty-st"><i class="fas fa-user-slash"></i><p>لا يوجد مستخدمون مسجّلون بعد</p></div>`);
      document.getElementById('tripOvBody').innerHTML = `
    ${(t.image_url && !['0', 'null', 'undefined', 'false', 'none', ''].includes(String(t.image_url).trim().toLowerCase())) ? `<img class="trip-detail-thumb" src="${esc(t.image_url)}" alt="">` :
          `<div class="trip-detail-ph"><i class="fas fa-bus"></i></div>`}
    ${contactHtml}
    ${myRegHtml}
    <div style="display:flex;flex-wrap:wrap;gap:7px;margin-bottom:14px;">
      <span style="padding:4px 12px;border-radius:var(--r-full);font-size:.74rem;font-weight:700;background:var(--ok-bg);color:var(--ok);">
        <i class="fas fa-tag"></i> ${parseFloat(t.final_price) > 0 ? parseFloat(t.final_price).toFixed(0) + ' ج.م' : 'مجانية'}
      </span>
      ${t.start_date_formatted ? `<span style="padding:4px 12px;border-radius:var(--r-full);font-size:.74rem;font-weight:600;background:var(--s2);border:1px solid var(--bdr);"><i class="fas fa-calendar"></i> ${t.start_date_formatted}</span>` : ''}
    </div>
    ${t.description ? `<p style="font-size:.83rem;color:var(--t3);line-height:1.6;margin-bottom:14px;">${esc(t.description)}</p>` : ''}
    ${kidsHtml}
  `;
      openOv('tripOv');
    }

    // Build the "not registered" contact block for inside the trip detail modal
    function buildTripContactHtml(t) {
      if (!classUncles.length) {
        return `<div style="margin-bottom:14px;padding:12px 16px;background:#fef3c7;border:1.5px solid #fde68a;border-radius:var(--r-md);display:flex;align-items:center;gap:10px;font-size:.82rem;font-weight:600;color:#92400e;">
      <i class="fas fa-exclamation-triangle"></i>
      <span>اسمك غير موجود في قائمة هذه الرحلة — تواصل مع المسؤول</span>
    </div>`;
      }
      const uncleButtons = classUncles.map(u => {
        const hasPhone = u.phone && u.phone.trim();
        const waPhone = hasPhone ? '20' + u.phone.trim().replace(/^0/, '') : '';
        const waMsg = encodeURIComponent('مرحباً، أنا ' + ((student && student.name) || '') + ' — اسمي غير موجود في قائمة رحلة "' + t.title + '"، هل يمكن تسجيلي؟');
        return `<div style="display:flex;gap:8px;align-items:center;padding:10px 14px;background:var(--surf);border:1.5px solid var(--bdr);border-radius:var(--r-md);">
      <div style="width:34px;height:34px;border-radius:50%;background:var(--brand-bg);color:var(--brand);display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:800;flex-shrink:0;overflow:hidden;">
        ${u.image_url ? `<img src="${esc(u.image_url)}" style="width:100%;height:100%;object-fit:cover;" alt="${esc(u.name)}">` : u.name.charAt(0)}
      </div>
      <div style="flex:1;min-width:0;">
        <div style="font-size:.84rem;font-weight:700;color:var(--t1);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(u.name)}</div>
        <div style="font-size:.68rem;color:var(--t4);">مدرّس الفصل</div>
      </div>
      <div style="display:flex;gap:6px;flex-shrink:0;">
        ${hasPhone ? `<a href="tel:${esc(u.phone.trim())}" style="width:34px;height:34px;border-radius:var(--r-sm);background:#d1fae5;color:#059669;display:flex;align-items:center;justify-content:center;text-decoration:none;font-size:.85rem;" title="اتصال"><i class="fas fa-phone-alt"></i></a>
        <a href="https://wa.me/${waPhone}?text=${waMsg}" target="_blank" style="width:34px;height:34px;border-radius:var(--r-sm);background:#dcfce7;color:#16a34a;display:flex;align-items:center;justify-content:center;text-decoration:none;font-size:.9rem;" title="واتساب"><i class="fab fa-whatsapp"></i></a>`
            : `<span style="font-size:.7rem;color:var(--t4);padding:4px 8px;background:var(--s2);border-radius:var(--r-sm);white-space:nowrap;">لا يوجد رقم</span>`}
      </div>
    </div>`;
      }).join('');
      return `<div style="margin-bottom:14px;padding:12px 14px;background:#fef3c7;border:1.5px solid #fde68a;border-radius:var(--r-md) var(--r-md) 0 0;display:flex;align-items:center;gap:9px;font-size:.82rem;font-weight:700;color:#92400e;">
    <i class="fas fa-exclamation-triangle"></i>
    <span>اسمك غير موجود في القائمة — تواصل مع مدرّسك</span>
  </div>
  <div style="margin-bottom:14px;display:flex;flex-direction:column;gap:7px;padding:0 2px;">
    ${uncleButtons}
  </div>`;
    }

    // Open uncle contact from trip card "تواصل" button (card-level, not modal)
    function openTripContactUncle(tripId) {
      const t = allTrips.find(x => x.id == tripId);
      if (!t) return;
      // Build a lightweight bottom sheet using the existing uncleOv
      if (classUncles.length === 1) {
        openUncleDrawer(classUncles[0].id);
      } else {
        // Multiple uncles — show the uncle section or open the first uncle drawer
        openUncleDrawer(classUncles[0].id);
      }
    }

    // ── Announcements ─────────────────────────────────────────────────
    let allAnnouncements = [];
    let latestBannerAnnId = 0;

    async function loadAnn() {
      if (!student) return;
      try {
        const d = await api({ action: 'getAnnouncementsForStudent', churchId: student.church_id || 1, studentClass: student.class, studentName: student.name });
        const anns = (d.success && Array.isArray(d.announcements)) ? d.announcements : [];
        allAnnouncements = anns;

        // 1. Handle navigation tab badge for tasks
        const badge = document.getElementById('tasksBadge');
        if (badge && !isViewingOther() && document.querySelector('.bottom-nav-item.active')?.getAttribute('data-tab') !== 'tasks') {
          const taskAnns = anns.filter(ann => ann.text && (ann.text.includes('تاسك') || ann.text.includes('تصحيح') || ann.text.includes('الكوبونات')));
          maxFetchedTaskAnnId = taskAnns.length ? Math.max(...taskAnns.map(ann => parseInt(ann.id) || 0)) : 0;
          const lastViewedId = parseInt(localStorage.getItem('tasksLastViewedAnnId_' + student.id) || '0');
          const hasNewTaskAnn = taskAnns.some(ann => (parseInt(ann.id) || 0) > lastViewedId);
          badge.style.display = hasNewTaskAnn ? 'block' : 'none';
        }

        const banner = document.getElementById('scAnnBanner');
        const card = document.getElementById('scAnn');
        if (banner) banner.style.display = 'none';
        if (card) card.style.display = 'none';

        // 2. Filter active announcements
        const dismissedIds = JSON.parse(localStorage.getItem('dismissedAnns_' + student.id) || '[]');
        const activeAnns = anns.filter(ann => {
          const idKey = isNaN(ann.id) ? String(ann.id) : parseInt(ann.id);
          return !dismissedIds.includes(idKey);
        });

        // 3. Update the Top Action Header Notification Badge
        const notifBadgeTop = document.getElementById('notifBadgeTop');
        if (notifBadgeTop) {
          if (activeAnns.length > 0) {
            notifBadgeTop.textContent = String(activeAnns.length);
            notifBadgeTop.style.display = 'flex';
          } else {
            notifBadgeTop.style.display = 'none';
          }
        }

        // 4. Render to the Overlay modal list
        const notifListModal = document.getElementById('notifListModal');
        if (notifListModal) {
          if (activeAnns.length > 0) {
            notifListModal.innerHTML = `
              <div class="ann-list" style="display:flex; flex-direction:column; gap:12px;">
                ${activeAnns.map(a => {
              const imgHtml = a.image_url ? `<div style="margin: 8px 0;"><img src="${esc(a.image_url)}" style="max-width:100%; border-radius:12px; border:1px solid var(--bdr); max-height:180px; object-fit:cover; display:block;"/></div>` : '';
              const descHtml = a.description ? `<div style="font-size:0.8rem; color:var(--t2); line-height:1.5; margin-bottom:8px; white-space:pre-wrap;">${esc(a.description)}</div>` : '';
              const linkHtml = a.link ? `<a href="${esc(a.link)}" target="_blank" class="ann-link-btn"><i class="fas fa-external-link-alt"></i> ${esc(a.button_text || 'فتح الرابط')}</a>` : '';

              return `
                  <div class="ann-item" style="padding-left: 20px;">
                    <div class="ann-top">
                      <span class="ann-type ${a.type === 'button' ? 'link' : (a.type === 'developer' ? 'dev' : '')}">
                        <i class="fas fa-${a.type === 'button' ? 'link' : (a.type === 'developer' ? 'code' : 'comment-dots')}"></i>
                        ${a.type === 'button' ? 'رابط سريع' : (a.type === 'developer' ? 'رسالة المطور' : 'إعلان')}
                      </span>
                      <span class="ann-date">${fmtDate(a.created_at)}</span>
                    </div>
                    <div class="ann-text" style="font-weight:700;">${esc(a.text)}</div>
                    ${imgHtml}
                    ${descHtml}
                    <div class="ann-footer">
                      <span class="ann-meta-pill"><i class="fas fa-bullhorn"></i> من الكنيسة أو خدام الفصل</span>
                      <div style="display:flex; gap:8px; align-items:center; margin-right:auto;">
                        ${linkHtml}
                        <button onclick="dismissSingleAnn('${a.id}')" style="background:none; border:none; color:var(--danger, #ef4444); cursor:pointer; font-size:.78rem; font-weight:800; display:flex; align-items:center; gap:4px; padding:4px 8px; border-radius:4px;"><i class="fas fa-eye-slash"></i> إخفاء</button>
                      </div>
                    </div>
                  </div>`;
            }).join('')}
              </div>`;
          } else {
            notifListModal.innerHTML = `
              <div style="text-align:center; padding:40px 20px; color:var(--t4);">
                <i class="fas fa-bell-slash" style="font-size:2.5rem; margin-bottom:12px; color:var(--bdr); display:block; margin-inline:auto;"></i>
                <div style="font-weight:800; font-size:1rem; color:var(--t2); margin-bottom:4px;">لا توجد إشعارات جديدة</div>
                <div style="font-size:.76rem;">أول ما ينزل إعلان جديد هتلاقيه هنا.</div>
              </div>`;
          }
        }
      } catch (e) {
        console.error(e);
      }
    }

    function dismissSingleAnn(annId) {
      if (!student) return;
      const dismissedIds = JSON.parse(localStorage.getItem('dismissedAnns_' + student.id) || '[]');
      const idKey = isNaN(annId) ? String(annId) : parseInt(annId);
      if (!dismissedIds.includes(idKey)) {
        dismissedIds.push(idKey);
        localStorage.setItem('dismissedAnns_' + student.id, JSON.stringify(dismissedIds));
      }
      loadAnn();
    }

    function dismissAnnBanner() {
      if (!student) return;
      const dismissedIds = JSON.parse(localStorage.getItem('dismissedAnns_' + student.id) || '[]');
      const idKey = isNaN(latestBannerAnnId) ? String(latestBannerAnnId) : parseInt(latestBannerAnnId);
      if (latestBannerAnnId && !dismissedIds.includes(idKey)) {
        dismissedIds.push(idKey);
        localStorage.setItem('dismissedAnns_' + student.id, JSON.stringify(dismissedIds));
      }
      loadAnn();
    }

    // ── Edit / Password / Photo ───────────────────────────────────────
    async function saveProfile() {
      if (isViewingOther()) {
        toast('غير مسموح في وضع المعاينة', 'err');
        return;
      }
      const n = document.getElementById('eN').value.trim();
      if (!n) { toast('أدخل الاسم', 'err'); return; }
      try {
        const d = await api({ action: 'updateStudentInfo', studentId: student.id, name: n, address: document.getElementById('eA').value.trim(), phone: document.getElementById('eP').value.trim(), birthday: document.getElementById('eB').value.trim() });
        if (d.success) {
          const newPhoneClean = document.getElementById('eP').value.trim();
          student.name = n; student.address = document.getElementById('eA').value.trim();
          student.phone = newPhoneClean; student.birthday = document.getElementById('eB').value.trim();
          document.getElementById('heroName').textContent = n;
          updateBirthdayGreetingButton(student);
          renderInfo(student, false); closeOv('editOv'); toast('تم الحفظ ✓', 'ok');

          // If phone was changed, update savedUsername in localStorage
          if (newPhoneClean) {
            const rawDigits = newPhoneClean.replace(/[^\d]/g, '');
            if (rawDigits) localStorage.setItem('savedUsername', rawDigits);
          }

          // Update current account in allAccounts
          const selfRef = allAccounts.find(a => a.id === student.id);
          if (selfRef) {
            selfRef.name = n;
            selfRef.phone = newPhoneClean;
          }

          // Exclude any accounts that no longer share a common phone with this account
          allAccounts = allAccounts.filter(a => doAccountsShareCommonPhone(student, a));
          renderAccountSwitcher();
          populateSwitchModal();
        } else toast(d.message || 'فشل', 'err');
      } catch (e) { toast('خطأ في الاتصال', 'err'); }
    }
    // ── Sync passOv between "add" and "change" modes ─────────────────────
    function syncPassOverlay() {
      if (!student) return;
      const isAdd = !student.has_password;
      const title = document.getElementById('passOvTitle');
      const btnLbl = document.getElementById('passOvBtn');
      const menuLbl = document.getElementById('passMenuLabel');
      const note = document.getElementById('passAddNote');
      const oldWrap = document.getElementById('passOldWrap');
      if (title) title.textContent = isAdd ? 'إضافة كلمة مرور' : 'تغيير كلمة المرور';
      if (btnLbl) btnLbl.textContent = isAdd ? 'إضافة كلمة المرور' : 'تغيير كلمة المرور';
      if (menuLbl) menuLbl.textContent = isAdd ? 'إضافة كلمة مرور' : 'تغيير كلمة المرور';
      if (note) note.style.display = isAdd ? 'block' : 'none';
      if (oldWrap) oldWrap.style.display = isAdd ? 'none' : 'block';
    }

    async function changePass() {
      if (isViewingOther()) {
        toast('غير مسموح في وضع المعاينة', 'err');
        return;
      }
      const isAdd = !student.has_password;
      const o = document.getElementById('po').value.trim();
      const n = document.getElementById('pn').value.trim();
      const c = document.getElementById('pc').value.trim();
      if (!n || !c) { toast('أكمل الحقول المطلوبة', 'err'); return; }
      if (!isAdd && !o) { toast('أدخل كلمة المرور الحالية', 'err'); return; }
      if (n !== c) { toast('كلمة المرور غير متطابقة', 'err'); return; }
      if (n.length < 6) { toast('٦ أحرف على الأقل', 'err'); return; }
      try {
        const d = await api({
          action: 'changeStudentPassword',
          phone: localStorage.getItem('savedUsername') || student.phone,
          studentId: student.id,
          oldPassword: o,
          newPassword: n,
          isAdd: isAdd ? 'true' : 'false'
        });
        if (d.success) {
          // Update localStorage password so next auto-login works
          localStorage.setItem('savedPassword', n);
          // Mark student as having a password now
          student.has_password = true;
          // Update all account copies
          const accRef = allAccounts.find(a => a.id === student.id);
          if (accRef) accRef.has_password = true;
          closeOv('passOv');
          syncPassOverlay();
          toast(d.message || 'تم ✓', 'ok');
          ['po', 'pn', 'pc'].forEach(id => document.getElementById(id).value = '');
        } else toast(d.message || 'فشل', 'err');
      } catch (e) { toast('خطأ في الاتصال', 'err'); }
    }

    function onPhoto(e) {
      const file = e.target.files[0]; if (!file) return;
      const reader = new FileReader();
      reader.onload = ev => {
        document.getElementById('dropZone').style.display = 'none';
        document.getElementById('cropWrap').style.display = 'block';
        document.getElementById('cropBtn').style.display = 'inline-flex';
        const img = document.getElementById('cropImg'); img.src = ev.target.result;
        if (cropper) cropper.destroy();
        setTimeout(() => { cropper = new Cropper(img, { aspectRatio: 1, viewMode: 2, dragMode: 'move', autoCropArea: .85, guides: false }); }, 100);
      };
      reader.readAsDataURL(file);
    }
    function doCrop() {
      if (!cropper) return;
      cropper.getCroppedCanvas({ width: 400, height: 400, imageSmoothingQuality: 'high' }).toBlob(blob => {
        croppedBlob = blob;
        const prev = document.getElementById('photoPrev');
        prev.src = URL.createObjectURL(blob); prev.style.display = 'block';
        document.getElementById('dropZone').style.display = 'none';
        document.getElementById('cropWrap').style.display = 'none';
        document.getElementById('cropBtn').style.display = 'none';
        document.getElementById('uploadBtn').style.display = 'inline-flex';
        cropper.destroy(); cropper = null; toast('تم القص ✓', 'ok');
      }, 'image/jpeg', .9);
    }
    async function uploadPhoto() {
      if (isViewingOther()) {
        toast('غير مسموح في وضع المعاينة', 'err');
        return;
      }
      if (!croppedBlob) { toast('اختر صورة أولاً', 'err'); return; }
      showLoad('جارٍ رفع الصورة…');
      const fd = new FormData();
      fd.append('photo', new File([croppedBlob], `profile_${student.phone}_${Date.now()}.jpg`, { type: 'image/jpeg' }));
      fd.append('studentId', student.id); fd.append('studentName', student.name);
      fd.append('studentPhone', student.phone); fd.append('studentClass', student.class); fd.append('churchId', student.church_id || 1);
      try {
        const r = await fetch('/upload.php', { method: 'POST', body: fd, headers: { Accept: 'application/json' } });
        const up = await r.json();
        if (!up.success) throw new Error(up.message || 'فشل رفع الملف');
        const d = await api({ action: 'updateStudentImageAfterCreation', studentId: student.id, imageUrl: up.imageUrl });
        if (!d.success) throw new Error(d.message || 'فشل حفظ الرابط');
        const savedUrl = d.imageUrl || up.imageUrl;
        // Refresh student data from server
        const fresh = await api({ action: 'getStudentProfile', studentId: student.id });
        if (fresh.success && (fresh.student || fresh.user)) student = norm(fresh.student || fresh.user);
        hideLoad();
        document.getElementById('avatarInner').innerHTML = `<img src="${savedUrl}?t=${Date.now()}" alt="">`;
        const deleteBtn = document.getElementById('deleteStudentPhotoBtn');
        if (deleteBtn) deleteBtn.style.display = 'flex';
        const banner = document.getElementById('profilePicSuggestionBanner');
        if (banner) banner.style.display = 'none';
        closeOv('photoOv'); resetPhoto(); toast('تم رفع الصورة ✓', 'ok');
      } catch (e) { hideLoad(); toast('خطأ: ' + e.message, 'err'); }
    }
    function resetPhoto() {
      document.getElementById('dropZone').style.display = 'block';
      document.getElementById('cropWrap').style.display = 'none';
      document.getElementById('cropBtn').style.display = 'none';
      document.getElementById('uploadBtn').style.display = 'none';
      document.getElementById('photoPrev').style.display = 'none';
      document.getElementById('photoIn').value = '';
      if (cropper) { cropper.destroy(); cropper = null; } croppedBlob = null;
    }

    async function deleteStudentPhoto(event) {
      if (event) event.stopPropagation();
      if (!confirm('هل أنت متأكد من حذف الصورة الشخصية؟')) return;
      if (isViewingOther()) {
        toast('غير مسموح في وضع المعاينة', 'err');
        return;
      }
      showLoad('جارٍ حذف الصورة…');
      try {
        const d = await api({ action: 'updateStudentImageAfterCreation', studentId: student.id, imageUrl: '' });
        if (!d.success) throw new Error(d.message || 'فشل حذف الصورة');
        student.image_url = '';
        hideLoad();
        document.getElementById('avatarInner').innerHTML = `<i class="fas fa-user"></i>`;
        const deleteBtn = document.getElementById('deleteStudentPhotoBtn');
        if (deleteBtn) deleteBtn.style.display = 'none';
        const banner = document.getElementById('profilePicSuggestionBanner');
        if (banner && localStorage.getItem('dismissProfilePicSuggestion') !== 'true') {
          banner.style.display = 'flex';
        }
        toast('تم حذف الصورة الشخصية ✓', 'ok');
      } catch (e) {
        hideLoad();
        toast('خطأ: ' + e.message, 'err');
      }
    }

    function dismissProfilePicSuggestion() {
      localStorage.setItem('dismissProfilePicSuggestion', 'true');
      const banner = document.getElementById('profilePicSuggestionBanner');
      if (banner) banner.style.display = 'none';
    }

    // ── Phone matching helper for account switcher ────────────────────
    function getAllStudentPhones(s) {
      if (!s) return [];
      const list = [];
      const add = (p) => {
        if (!p) return;
        if (typeof p === 'string' || typeof p === 'number') {
          const clean = String(p).replace(/[^\d]/g, '');
          if (clean.length >= 8) {
            const last9 = clean.slice(-9);
            if (!list.includes(last9)) list.push(last9);
          }
        } else if (Array.isArray(p)) {
          p.forEach(add);
        } else if (typeof p === 'object') {
          Object.values(p).forEach(add);
        }
      };
      add(s.phone);
      add(s.emergency_phone);
      add(s.parent_phones);
      if (s.custom_info) {
        add(s.custom_info.parent_phones);
        add(s.custom_info.phone);
        add(s.custom_info.emergency_phone);
      }
      return list;
    }

    function doAccountsShareCommonPhone(a, b) {
      if (!a || !b) return false;
      if (Number(a.id) === Number(b.id)) return true;
      const phonesA = getAllStudentPhones(a);
      const phonesB = getAllStudentPhones(b);
      if (!phonesA.length || !phonesB.length) return false;
      return phonesA.some(pA => phonesB.includes(pA));
    }

    // ── Account switch ────────────────────────────────────────────────
    function populateSwitchModal() {
      if (!allAccounts || !allAccounts.length || !student) return;
      allAccounts = allAccounts.filter(a => doAccountsShareCommonPhone(student, a));
      if (allAccounts.length <= 1) {
        closeOv('switchOv');
        renderAccountSwitcher();
        return;
      }
      selAccId = student ? student.id : null;
      const list = document.getElementById('switchList');
      if (!list) return;
      list.innerHTML = allAccounts.map(a => `
        <div class="acc-item${(student && a.id === student.id) ? ' active' : ''}" data-id="${a.id}" onclick="pickAcc(${a.id})">
          <div class="acc-av">${a.image_url ? `<img src="${esc(a.image_url)}" alt="">` : esc(a.name.charAt(0))}</div>
          <div><div class="acc-name">${esc(a.name)}</div><div class="acc-cls"><i class="fas fa-graduation-cap"></i> ${esc(a.class || '—')}</div></div>
          ${(student && a.id === student.id) ? '<i class="fas fa-check-circle" style="color:var(--brand);margin-right:auto;"></i>' : ''}
        </div>`).join('');
    }

    const switchBtnTopEl = document.getElementById('switchBtnTop');
    if (switchBtnTopEl) {
      switchBtnTopEl.addEventListener('click', () => {
        populateSwitchModal();
        openOv('switchOv');
      });
    }

    const heroSwitchTagEl = document.getElementById('heroSwitchTag');
    if (heroSwitchTagEl) {
      heroSwitchTagEl.addEventListener('click', () => {
        populateSwitchModal();
        openOv('switchOv');
      });
    }

    function renderAccountSwitcher() {
      const container = document.getElementById('scAccountSwitcher');
      const heroTag = document.getElementById('heroSwitchTag');
      const heroCount = document.getElementById('heroSwitchCount');
      const switchBtnTop = document.getElementById('switchBtnTop');
      const grid = document.getElementById('accCardsGrid');
      const countBadge = document.getElementById('asCountBadge');
      const notice = document.getElementById('firstTimeSwitchNotice');
      const noticeTitle = document.getElementById('firstTimeNoticeTitle');

      if (allAccounts && student) {
        allAccounts = allAccounts.filter(a => doAccountsShareCommonPhone(student, a));
      }

      if (!allAccounts || allAccounts.length <= 1 || isViewingOther() || IS_PUBLIC) {
        if (container) container.style.display = 'none';
        if (heroTag) heroTag.style.display = 'none';
        if (switchBtnTop) switchBtnTop.style.display = 'none';
        closeOv('switchOv');
        return;
      }

      // Always show switch button in hero & tags
      if (switchBtnTop) switchBtnTop.style.display = 'flex';
      if (heroTag) {
        heroTag.style.display = 'inline-flex';
        if (heroCount) heroCount.textContent = allAccounts.length;
      }

      // Main page container visibility depends on active tab
      const currentTab = document.querySelector('.bottom-nav-item.active')?.getAttribute('data-tab') || 'home';
      if (container) {
        container.style.display = (currentTab === 'home') ? 'block' : 'none';
      }

      if (countBadge) {
        countBadge.textContent = allAccounts.length;
      }

      // First time notice check
      const phoneOrUser = student?.phone || localStorage.getItem('savedUsername') || (student ? String(student.id) : '');
      const noticeKey = 'multiAccNoticeSeen_' + phoneOrUser;
      const isNoticeDismissed = localStorage.getItem(noticeKey) === 'true';

      if (notice) {
        if (!isNoticeDismissed) {
          notice.style.display = 'flex';
          if (noticeTitle) {
            noticeTitle.textContent = allAccounts.length === 2
              ? 'مرحباً بك! يوجد حسابان مرتبطان بهذا الرقم'
              : `مرحباً بك! يوجد ${allAccounts.length} حسابات مرتبطة بهذا الرقم`;
          }
        } else {
          notice.style.display = 'none';
        }
      }

      // Render cards
      if (grid) {
        grid.innerHTML = allAccounts.map(a => {
          const isCurr = student && (Number(a.id) === Number(student.id));
          const av = a.image_url
            ? `<img src="${esc(a.image_url)}" alt="${esc(a.name)}" onerror="this.parentElement.textContent='${esc(a.name.charAt(0))}'">`
            : esc(a.name.charAt(0));
          return `
            <div class="acc-card-item${isCurr ? ' active' : ''}" onclick="${isCurr ? '' : `pickAcc(${a.id})`}">
              <div class="acc-card-av">${av}</div>
              <div class="acc-card-info">
                <div class="acc-card-name" title="${esc(a.name)}">${esc(a.name)}</div>
                <div class="acc-card-class"><i class="fas fa-graduation-cap"></i> ${esc(a.class || '—')}</div>
              </div>
              <div class="acc-card-tag ${isCurr ? 'current' : 'switch-btn'}">
                ${isCurr ? '<i class="fas fa-check-circle"></i> <span class="acc-tag-long">الحساب الحالي</span><span class="acc-tag-short">الحالي</span>' : '<i class="fas fa-exchange-alt"></i> <span>تبديل</span>'}
              </div>
            </div>
          `;
        }).join('');
      }

      // First time welcome modal trigger
      checkAndShowFirstTimeModal();
    }

    function checkAndShowFirstTimeModal() {
      if (!allAccounts || allAccounts.length <= 1 || isViewingOther() || IS_PUBLIC) return;
      const phoneOrUser = student?.phone || localStorage.getItem('savedUsername') || (student ? String(student.id) : '');
      const modalKey = 'multiAccModalSeen_' + phoneOrUser;
      if (localStorage.getItem(modalKey) === 'true') return;

      const modal = document.getElementById('firstTimeMultiAccModal');
      const title = document.getElementById('ftModalTitle');
      const desc = document.getElementById('ftModalDesc');
      const preview = document.getElementById('ftKidsPreview');
      const avatarsWrap = document.getElementById('ftAvatarsWrap');

      if (!modal) return;

      const count = allAccounts.length;
      if (title) {
        title.textContent = count === 2
          ? 'يوجد حسابان مرتبطان بهذا الرقم'
          : `يوجد ${count} حسابات مرتبطة بهذا الرقم`;
      }
      if (desc) {
        desc.innerHTML = count === 2
          ? `مرحباً بك! لاحظنا وجود <strong>حسابين مسجلين</strong> برقم هاتفك. يمكنك التبديل بين حسابات أولادك بكل سهولة في أي وقت أو فصل الحسابات وتخصيص رقم هاتف لكل حساب.`
          : `مرحباً بك! لاحظنا وجود <strong>${count} حسابات مسجلة</strong> برقم هاتفك. يمكنك التبديل بين حسابات أبنائك بكل سهولة في أي وقت أو فصل الحسابات وتخصيص رقم هاتف لكل حساب.`;
      }

      // Overlapping avatars with white border cutout
      if (avatarsWrap) {
        const maxShown = 3;
        const shownAccounts = allAccounts.slice(0, maxShown);
        const extraCount = allAccounts.length - maxShown;

        let avatarsHtml = shownAccounts.map((a, idx) => {
          const avImg = a.image_url
            ? `<img src="${esc(a.image_url)}" alt="${esc(a.name)}" onerror="this.onerror=null; this.parentElement.classList.add('ft-avatar-fallback'); this.parentElement.innerHTML='<span>${esc(a.name ? a.name.charAt(0) : '👤')}</span>';">`
            : `<span>${esc(a.name ? a.name.charAt(0) : '👤')}</span>`;
          const isFallback = !a.image_url ? ' ft-avatar-fallback' : '';
          return `
            <div class="ft-avatar-circle${isFallback}" style="z-index:${maxShown - idx};" title="${esc(a.name)}">
              ${avImg}
            </div>
          `;
        }).join('');

        if (extraCount > 0) {
          avatarsHtml += `
            <div class="ft-avatar-circle ft-avatar-extra" style="z-index:0;" title="${extraCount} حسابات إضافية">
              +${extraCount}
            </div>
          `;
        }

        avatarsWrap.innerHTML = avatarsHtml;
      }

      if (preview) {
        preview.innerHTML = allAccounts.map(a => `
          <div class="ft-kid-chip">
            <i class="fas fa-user-circle"></i>
            <span>${esc(a.name)}</span>
          </div>
        `).join('');
      }

      openOv('firstTimeMultiAccModal');
      localStorage.removeItem('justLoggedInMultiAccounts');
    }

    function dismissFirstTimeSwitchNotice(e) {
      if (e) e.stopPropagation();
      const phoneOrUser = student?.phone || localStorage.getItem('savedUsername') || (student ? String(student.id) : '');
      localStorage.setItem('multiAccNoticeSeen_' + phoneOrUser, 'true');
      const notice = document.getElementById('firstTimeSwitchNotice');
      if (notice) {
        notice.style.transition = 'all 0.3s ease';
        notice.style.opacity = '0';
        notice.style.transform = 'translateY(-10px)';
        setTimeout(() => { notice.style.display = 'none'; }, 300);
      }
    }

    function dismissFirstTimeModalOnly() {
      const phoneOrUser = student?.phone || localStorage.getItem('savedUsername') || (student ? String(student.id) : '');
      localStorage.setItem('multiAccModalSeen_' + phoneOrUser, 'true');
      closeOv('firstTimeMultiAccModal');
    }

    async function openSeparateAccountsModal() {
      closeOv('firstTimeMultiAccModal');
      const listEl = document.getElementById('sepAccountsList');
      if (!listEl) return;

      listEl.innerHTML = `
        <div style="text-align:center; padding:30px 10px; color:var(--t3);">
          <i class="fas fa-spinner fa-spin" style="font-size:1.8rem; color:var(--brand); margin-bottom:12px;"></i>
          <div style="font-weight:700;">جاري تحميل بيانات الحسابات...</div>
        </div>
      `;
      openOv('separateAccountsModal');

      let targetAccounts = allAccounts || [];

      try {
        if (student?.id) {
          const resp = await api({
            action: 'getAccountsSeparationInfo',
            callerStudentId: student.id,
            accountIds: JSON.stringify(targetAccounts.map(a => a.id))
          });
          if (resp && resp.success && Array.isArray(resp.accounts) && resp.accounts.length > 0) {
            targetAccounts = resp.accounts.map(serverAcc => {
              const localAcc = allAccounts.find(a => Number(a.id) === Number(serverAcc.id)) || {};
              return { ...localAcc, ...serverAcc };
            });
          }
        }
      } catch (err) {
        console.warn('Could not fetch server separation info, using local accounts', err);
      }

      renderSeparateAccountsList(targetAccounts);
    }

    function renderSeparateAccountsList(accountsList) {
      const listEl = document.getElementById('sepAccountsList');
      if (!listEl) return;

      if (!accountsList || accountsList.length === 0) {
        listEl.innerHTML = `<div style="text-align:center; padding:20px; color:var(--t3);">لا توجد حسابات مرتبطة لعرضها</div>`;
        return;
      }

      listEl.innerHTML = accountsList.map(a => {
        const hasPass = Boolean(a.has_password);
        const av = a.image_url
          ? `<img src="${esc(a.image_url)}" alt="${esc(a.name)}" onerror="this.onerror=null; this.parentElement.textContent='${esc(a.name ? a.name.charAt(0) : '👤')}'">`
          : esc(a.name ? a.name.charAt(0) : '👤');

        return `
          <div class="sep-acc-card" data-id="${a.id}">
            <div class="sep-card-head">
              <div class="sep-card-av">${av}</div>
              <div class="sep-card-info">
                <div class="sep-card-name" title="${esc(a.name)}">${esc(a.name)}</div>
                <div class="sep-card-meta">
                  <span class="sep-class-tag"><i class="fas fa-graduation-cap"></i> ${esc(a.class || '—')}</span>
                  ${hasPass
                    ? '<span class="sep-status-badge has-pass"><i class="fas fa-check-circle"></i> محمي بكلمة مرور</span>'
                    : '<span class="sep-status-badge no-pass"><i class="fas fa-exclamation-triangle"></i> يحتاج كلمة مرور</span>'
                  }
                </div>
              </div>
            </div>

            <div class="sep-field-group">
              <label class="sep-field-label" for="sepPhone_${a.id}">
                رقم الهاتف الخاص بهذا الحساب <span style="color:var(--danger);">*</span>
              </label>
              <input type="tel" id="sepPhone_${a.id}" class="sep-field-input" value="${esc(a.phone || '')}" placeholder="أدخل رقم الهاتف (مثال: 010...)" dir="ltr" inputmode="tel" data-initial="${esc(a.phone || '')}">
            </div>

            <div class="sep-field-group">
              <label class="sep-field-label" for="sepPass_${a.id}">
                ${hasPass
                  ? 'تغيير كلمة المرور (اختياري)'
                  : 'تعيين كلمة مرور خاصة بهذا الحساب <span style="color:var(--danger);">*</span>'
                }
              </label>
              <div class="sep-pass-wrap">
                <input type="password" id="sepPass_${a.id}" class="sep-field-input" placeholder="${hasPass ? 'اتركه فارغاً للاحتفاظ بكلمة المرور الحالية' : 'أدخل كلمة مرور جديدة (4 خانات على الأقل)'}" data-has-pass="${hasPass ? '1' : '0'}" autocomplete="new-password">
                <button type="button" class="sep-pass-toggle" onclick="toggleSepPassVisibility('sepPass_${a.id}', this)" title="إظهار/إخفاء">
                  <i class="fas fa-eye"></i>
                </button>
              </div>
              <div class="sep-field-hint${hasPass ? '' : ' required'}">
                ${hasPass
                  ? 'الحساب يمتلك كلمة مرور حالياً. اتركه فارغاً إذا كنت لا ترغب بتغييرها.'
                  : 'يجب تعيين كلمة مرور لهذا الحساب حتى يتمكن من تسجيل الدخول بعد تغيير رقمه.'
                }
              </div>
            </div>
          </div>
        `;
      }).join('');
    }

    function toggleSepPassVisibility(inputId, btn) {
      const input = document.getElementById(inputId);
      if (!input) return;
      const isPass = input.type === 'password';
      input.type = isPass ? 'text' : 'password';
      const icon = btn.querySelector('i');
      if (icon) {
        icon.className = isPass ? 'fas fa-eye-slash' : 'fas fa-eye';
      }
    }

    async function saveSeparateAccounts() {
      const cards = document.querySelectorAll('#sepAccountsList .sep-acc-card');
      if (!cards || cards.length === 0) return;

      const payloadAccounts = [];
      let hasError = false;

      document.querySelectorAll('#sepAccountsList .sep-field-input').forEach(inp => inp.classList.remove('input-error'));

      for (const card of cards) {
        const id = parseInt(card.dataset.id);
        const name = card.querySelector('.sep-card-name')?.textContent || 'الطفل';
        const phoneInput = card.querySelector(`#sepPhone_${id}`);
        const passInput = card.querySelector(`#sepPass_${id}`);

        const rawPhone = phoneInput ? phoneInput.value.trim() : '';
        const cleanPhone = rawPhone.replace(/[^\d]/g, '');
        const hasExistingPass = passInput ? (passInput.dataset.hasPass === '1') : false;
        const enteredPass = passInput ? passInput.value.trim() : '';

        if (!cleanPhone || cleanPhone.length < 8) {
          toast(`يرجى إدخال رقم هاتف صحيح لـ ${name}`, 'err');
          if (phoneInput) {
            phoneInput.classList.add('input-error');
            phoneInput.focus();
          }
          hasError = true;
          break;
        }

        if (!hasExistingPass && !enteredPass) {
          toast(`يجب تعيين كلمة مرور لـ ${name} لأنه لا يمتلك كلمة مرور`, 'err');
          if (passInput) {
            passInput.classList.add('input-error');
            passInput.focus();
          }
          hasError = true;
          break;
        }

        if (enteredPass && enteredPass.length < 4) {
          toast(`كلمة المرور لـ ${name} يجب ألا تقل عن 4 خانات`, 'err');
          if (passInput) {
            passInput.classList.add('input-error');
            passInput.focus();
          }
          hasError = true;
          break;
        }

        payloadAccounts.push({
          id: id,
          phone: cleanPhone,
          password: enteredPass
        });
      }

      if (hasError) return;

      const btnSave = document.getElementById('btnSaveSeparateAccounts');
      const origText = btnSave ? btnSave.innerHTML : '';
      if (btnSave) {
        btnSave.disabled = true;
        btnSave.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري حفظ التغييرات...';
      }

      try {
        const res = await api({
          action: 'separateKidAccounts',
          callerStudentId: student ? student.id : 0,
          accounts: JSON.stringify(payloadAccounts)
        });

        if (res && res.success) {
          toast(res.message || 'تم حفظ جميع الحسابات بنجاح', 'ok');

          if (student) {
            const activeUpdate = payloadAccounts.find(a => a.id === student.id);
            if (activeUpdate && activeUpdate.phone) {
              student.phone = activeUpdate.phone;
              localStorage.setItem('savedUsername', activeUpdate.phone);
              if (activeUpdate.password) {
                localStorage.setItem('savedPassword', activeUpdate.password);
              }
            }
          }

          const phoneOrUser = student?.phone || localStorage.getItem('savedUsername') || (student ? String(student.id) : '');
          localStorage.setItem('multiAccModalSeen_' + phoneOrUser, 'true');

          closeOv('separateAccountsModal');

          setTimeout(() => {
            window.location.reload();
          }, 800);
        } else {
          toast(res?.message || 'فشل في حفظ التغييرات', 'err');
        }
      } catch (err) {
        toast('خطأ في الاتصال بالسيرفر: ' + err.message, 'err');
      } finally {
        if (btnSave) {
          btnSave.disabled = false;
          btnSave.innerHTML = origText;
        }
      }
    }

    async function pickAcc(id) {
      if (!id || (student && id === student.id)) { closeOv('switchOv'); return; }
      const acc = allAccounts.find(a => a.id === id); 
      if (!acc) return;
      if (!doAccountsShareCommonPhone(student, acc)) {
        allAccounts = allAccounts.filter(a => doAccountsShareCommonPhone(student, a));
        renderAccountSwitcher();
        populateSwitchModal();
        closeOv('switchOv');
        toast('هذا الحساب لم يعد مرتبطاً بهذا الرقم', 'err');
        return;
      }

      closeOv('switchOv');
      showLoad(`جاري التبديل إلى ${acc.name}…`);

      // Allow browser to render loading screen
      await new Promise(r => setTimeout(r, 100));

      try {
        student = acc;
        localStorage.setItem('activeKidAccountId', String(acc.id));
        if (acc.phone) {
          const cleanP = String(acc.phone).replace(/[^\d]/g, '');
          if (cleanP) localStorage.setItem('savedUsername', cleanP);
        }
        allAccounts = allAccounts.filter(a => doAccountsShareCommonPhone(student, a));
        await loadChurchSettings();
        renderPrivate(student);
        syncSettingsSheet(student);
        switchTab(getInitialTab());
        renderAccountSwitcher();
        loadSiblings();
        syncPassOverlay();
        document.getElementById('bottomNavBar').style.display = 'flex';
        toast(`تم التبديل إلى ${acc.name} ✓`, 'ok');
        if (!IS_PUBLIC && _creds) {
          _initPushNotifications();
        }
      } catch (err) {
        console.error('Account switch error:', err);
        toast('حدث خطأ أثناء التبديل', 'err');
      } finally {
        setTimeout(() => {
          hideLoad();
        }, 150);
      }
    }

    // ── Logout ────────────────────────────────────────────────────────
    function doLogout() {
      closeOv('settingsOv');
      ['savedUsername', 'savedPassword', 'rememberMe', 'userPhone', 'loginType', 'lastVisitedPortal', 'authToken', 'auth_token', 'ss_access_token', 'ss_token_expires_at', 'activeKidAccountId'].forEach(k => localStorage.removeItem(k));
      try { sessionStorage.clear(); } catch(e) {}
      const fd = new FormData(); fd.append('action', 'logout');
      fetch(location.href, { method: 'POST', body: fd, credentials: 'include' }).finally(() => location.href = '/user/login');
    }

    // ── UI helpers ────────────────────────────────────────────────────
    function openOv(id) {
      if (['settingsOv', 'editOv', 'passOv', 'photoOv'].includes(id) && isViewingOther()) {
        toast('غير مسموح في وضع المعاينة', 'err');
        return;
      }
      if (id === 'settingsOv' || id === 'editOv') {
        syncSettingsSheet(student);
      }
      if (id === 'switchOv') {
        populateSwitchModal();
      }
      if (id === 'passOv') {
        syncPassOverlay();
        const po = document.getElementById('po'); if (po) po.value = '';
        const pn = document.getElementById('pn'); if (pn) pn.value = '';
        const pc = document.getElementById('pc'); if (pc) pc.value = '';
      }
      if (id === 'photoOv') {
        if (typeof resetPhoto === 'function') resetPhoto();
      }
      const ov = document.getElementById(id);
      if (!ov) return;
      ov.classList.add('open');
      document.documentElement.classList.add('ov-open');
      const sheet = ov.querySelector('.settings-sheet');
    }
    function closeOv(id) {
      if (id === 'firstTimeMultiAccModal') {
        const phoneOrUser = student?.phone || localStorage.getItem('savedUsername') || (student ? String(student.id) : '');
        localStorage.setItem('multiAccModalSeen_' + phoneOrUser, 'true');
      }
      const ov = document.getElementById(id);
      if (!ov) return;
      ov.classList.remove('open');
      // Only remove ov-open if no other overlay is still open
      if (!document.querySelector('.overlay.open')) document.documentElement.classList.remove('ov-open');
      const sheet = ov.querySelector('.settings-sheet');
      if (sheet) { clearTimeout(sheet._sst); sheet.classList.remove('ss-scrollable'); }
    }
    function setupOvClose() {
      document.querySelectorAll('.overlay').forEach(ov => {
        ov.addEventListener('click', e => { if (e.target === ov) closeOv(ov.id); });
      });
      document.addEventListener('keydown', e => {
        if (e.key === 'Escape') document.querySelectorAll('.overlay.open').forEach(ov => closeOv(ov.id));
      });
    }
    function showMain() {
      document.getElementById('mainPage').style.display = 'block';
      document.getElementById('noProfile').style.setProperty('display', 'none', 'important');
    }
    function showLoad(m = 'جارٍ التحميل…') {
      const el = document.getElementById('ls');
      if (!el) return;
      const txt = document.getElementById('lt');
      if (txt) txt.textContent = m;
      el.style.display = 'flex';
      el.classList.remove('hidden');
    }
    function hideLoad() {
      const el = document.getElementById('ls');
      if (!el) return;
      el.style.display = 'none';
      el.classList.add('hidden');
    }
    function noProfile(m) { hideLoad(); document.getElementById('noMsg').textContent = m; document.getElementById('noProfile').style.setProperty('display', 'flex', 'important'); }
    function toast(m, t = 'info') {
      const tc = document.getElementById('tc');
      const el = document.createElement('div'); el.className = `toast ${t}`;
      const ic = t === 'ok' ? 'fa-check-circle' : t === 'err' ? 'fa-exclamation-circle' : 'fa-info-circle';
      el.innerHTML = `<i class="fas ${ic}"></i>${m}`;
      tc.appendChild(el);
      requestAnimationFrame(() => requestAnimationFrame(() => el.classList.add('show')));
      setTimeout(() => { el.classList.remove('show'); setTimeout(() => el.remove(), 350); }, 3200);
    }
    function tPass(id, btn) {
      const inp = document.getElementById(id); const show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      btn.querySelector('i').className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
    }
    function esc(s) { return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function fmtDate(iso) {
      if (!iso) return '—';
      try {
        let s = String(iso).trim();
        if (s.indexOf('T') === -1 && s.indexOf(' ') !== -1) s = s.replace(' ', 'T');
        const d = new Date(s);
        return isNaN(d.getTime()) ? iso : d.toLocaleDateString('ar-EG', { day: 'numeric', month: 'short', year: 'numeric' });
      } catch (e) { return iso; }
    }
    function openModal(html) {
      let ov = document.getElementById('genericModalOv');
      if (!ov) {
        ov = document.createElement('div');
        ov.id = 'genericModalOv';
        ov.className = 'overlay';
        ov.style.zIndex = '3000';
        ov.innerHTML = `
      <div class="modal" style="max-width:600px;margin:auto;border:none;border-radius:20px;overflow:hidden;box-shadow:var(--sh-xl);background:var(--surf, #ffffff);">
        <div class="mhdr" style="background:var(--surf, #ffffff);padding:16px 20px;border-bottom:1px solid var(--bdr);display:flex;align-items:center;justify-content:space-between;">
          <div id="genericModalTitle" style="color:var(--t1);font-weight:800;font-size:1.05rem;">مراجعة إجاباتي</div>
          <button onclick="closeModal()" style="background:var(--s2);border:1px solid var(--bdr);color:var(--t1);width:32px;height:32px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:0.2s;"><i class="fas fa-times"></i></button>
        </div>
        <div class="mbody" id="genericModalBody" style="padding:0;"></div>
      </div>
    `;
        document.body.appendChild(ov);
        ov.onclick = (e) => { if (e.target === ov) closeModal(); };
      }
      ov.classList.add('open');
      document.getElementById('genericModalBody').innerHTML = html;
      document.documentElement.classList.add('ov-open');
    }

    function closeModal() {
      const ov = document.getElementById('genericModalOv');
      if (ov) ov.classList.remove('open');
      if (!document.querySelector('.overlay.open')) document.documentElement.classList.remove('ov-open');
    }

    function viewMyAnswers(taskId) {
      const t = allTasks.find(x => x.id == taskId);
      if (!t || !t.my_submission) return;
      const sub = t.my_submission;

      // answers: the student's choices (keyed by question id)
      const ans = typeof sub.answers === 'string' ? JSON.parse(sub.answers) : (sub.answers || {});
      // correct_answers: map of {qId: correctIndex} — provided by API when show_answers=1
      const correctMap = sub.correct_answers || {};
      // open_scores: map of {qId: score} for open questions graded by uncle
      const openScores = typeof sub.open_scores === 'string' ? JSON.parse(sub.open_scores || '{}') : (sub.open_scores || {});
      // correction_notes: map of {qId: noteText} for questions graded by uncle
      const corrNotes = typeof sub.correction_notes === 'string' ? JSON.parse(sub.correction_notes || '{}') : (sub.correction_notes || {});

      let html = `<div style="padding:20px;max-height:75vh;overflow-y:auto;background:var(--bg);">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;padding:15px;background:#fff;border-radius:var(--r-md);box-shadow:var(--sh-sm);">
      <div style="width:50px;height:50px;border-radius:50%;background:var(--brand-bg);color:var(--brand);display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0;"><i class="fas fa-clipboard-check"></i></div>
      <div style="flex:1;">
        <div style="font-weight:800;color:var(--t1);font-size:1.1rem;line-height:1.2;">${esc(t.title)}</div>
        <div style="font-size:.78rem;color:var(--t3);margin-top:2px;">لقد حصلت على ${sub.score} من ${t.total_degree} درجة</div>
      </div>
    </div>`;

      if (!t.questions || t.questions.length === 0) {
        html += `<div style="text-align:center;padding:40px;color:var(--t4);">لا توجد أسئلة لهذا التاسك.</div>`;
      } else {
        t.questions.forEach((q, i) => {
          const qType = q.question_type || 'mcq';
          const qId = String(q.id);
          let given = ans[qId] !== undefined ? ans[qId] : (ans[q.id] !== undefined ? ans[q.id] : undefined);
          if (given === undefined && ans[i] !== undefined) given = ans[i];
          if (given === undefined && ans['qc_' + q.id] !== undefined) given = ans['qc_' + q.id];
          if (given === undefined) {
            const ansVals = Object.values(ans);
            if (ansVals[i] !== undefined) given = ansVals[i];
          }

          // Use correct_answers map first (returned by API), fall back to q.correct_index
          const correctIdx = (correctMap[q.id] !== undefined)
            ? parseInt(correctMap[q.id])
            : (q.correct_index !== null && q.correct_index !== undefined ? parseInt(q.correct_index) : null);
          const isCorrect = given !== undefined && correctIdx !== null && parseInt(given) === correctIdx;

          html += `<div style="margin-bottom:15px;padding:15px;border:1.5px solid var(--bdr);border-radius:var(--r-md);background:#fff;box-shadow:var(--sh-sm);">`;
          html += `<div style="display:flex;gap:10px;margin-bottom:12px;">
        <div style="width:26px;height:26px;border-radius:8px;background:var(--s2);color:var(--t1);display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:800;flex-shrink:0;">${i + 1}</div>
        <div style="font-weight:700;color:var(--t1);line-height:1.4;flex:1;">${esc(q.question_text)}</div>
      </div>`;

          if (qType === 'open') {
            const deg = parseInt(q.degree) || 0;
            let openScore = openScores[qId] !== undefined ? openScores[qId] : (openScores[q.id] !== undefined ? openScores[q.id] : undefined);
            if (openScore === undefined && openScores[i] !== undefined) openScore = openScores[i];
            if (openScore === undefined) {
              const scoreVals = Object.values(openScores);
              if (scoreVals[i] !== undefined) openScore = scoreVals[i];
            }
            if (openScore === undefined) openScore = null;

            let openScoreHtml;
            if (deg === 0) {
              openScoreHtml = `<div style="margin-top:8px;font-size:.75rem;color:var(--t3);font-weight:700;"><i class="fas fa-check-circle"></i> تم تسجيل إجابتك (0 درجة)</div>`;
            } else if (openScore !== null) {
              const pct = deg > 0 ? Math.round((openScore / deg) * 100) : 100;
              let colorVar = 'var(--ok)';
              // Color bands: high -> green, mid -> orange, low -> yellow
              if (pct >= 80) colorVar = 'var(--ok)';
              else if (pct >= 50) colorVar = 'var(--warn-l)';
              else colorVar = 'var(--gold-l)';
              openScoreHtml = `<div style="margin-top:8px;font-size:.75rem;color:${colorVar};font-weight:700;"><i class="fas fa-check-circle"></i> درجتك: ${openScore} من ${deg}</div>`;
            } else {
              openScoreHtml = `<div style="margin-top:8px;font-size:.75rem;color:var(--warn);font-weight:700;"><i class="fas fa-clock"></i> في انتظار التصحيح</div>`;
            }
            html += `<div style="background:var(--s2);padding:15px;border-radius:var(--r-sm);border:1.5px solid var(--bdr);">
          <div style="font-size:.7rem;color:var(--t3);margin-bottom:6px;font-weight:700;">إجابتك المسجلة:</div>
          <div style="color:var(--t2);font-size:.9rem;white-space:pre-wrap;line-height:1.6;">${esc(given !== undefined ? String(given) : '— لم تُجب على هذا السؤال —')}</div>
          ${openScoreHtml}
        </div>`;
          } else {
            const rawOpts = typeof q.options === 'string' ? JSON.parse(q.options) : (q.options || []);
            if (qType === 'tf') { rawOpts[0] = 'صحيح'; rawOpts[1] = 'خطأ'; }
            let optsWithIndices = rawOpts.map((o, j) => ({ text: o, origIdx: j }));
            if (qType === 'mcq' && parseInt(t.shuffle_answers || 0) === 1 && optsWithIndices.length > 1) {
              optsWithIndices = seededShuffle(optsWithIndices, `${student.id}_task_${t.id}_q_${q.id}_opts`);
            }

            html += `<div style="display:flex;flex-direction:column;gap:8px;">`;
            optsWithIndices.forEach((item, displayIdx) => {
              const isCorr = item.origIdx === correctIdx;
              const isSel = given !== undefined && parseInt(given) === item.origIdx;

              let borderColor = 'var(--bdr)';
              let bgColor = 'var(--surf)';
              let textColor = 'var(--t2)';
              let icon = '';

              if (isCorr && isSel) {
                borderColor = 'var(--ok)'; bgColor = 'var(--ok-bg)'; textColor = 'var(--ok)';
                icon = '<i class="fas fa-check-circle" style="margin-right:auto;"></i>';
              } else if (isCorr) {
                borderColor = 'var(--ok)'; bgColor = 'var(--ok-bg)'; textColor = 'var(--ok)';
                icon = '<i class="fas fa-check" style="margin-right:auto;opacity:.5;"></i>';
              } else if (isSel) {
                borderColor = 'var(--err)'; bgColor = 'var(--err-bg)'; textColor = 'var(--err)';
                icon = '<i class="fas fa-times-circle" style="margin-right:auto;"></i>';
              }

              html += `<div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:var(--r-sm);border:2px solid ${borderColor};background:${bgColor};color:${textColor};font-size:.88rem;${isSel ? 'font-weight:700;' : ''}">
            <span style="width:20px;font-weight:800;opacity:.5;">${LETTERS[displayIdx]}</span>
            <span>${esc(item.text)}</span>
            ${icon}
          </div>`;
            });
            html += `</div>`;

            // Show "didn't answer" notice if student skipped
            if (given === undefined) {
              html += `<div style="margin-top:8px;font-size:.75rem;color:var(--t4);font-weight:700;"><i class="fas fa-minus-circle"></i> لم تُجب على هذا السؤال</div>`;
            }
          }

          // Show correction note if present
          const note = corrNotes[qId] !== undefined ? corrNotes[qId] : (corrNotes[q.id] !== undefined ? corrNotes[q.id] : null);
          if (note && note.trim()) {
            html += `<div style="margin-top:12px;display:flex;gap:10px;padding:12px;background:#fffbeb;border:1.5px solid #fef3c7;border-radius:var(--r-sm);color:#92400e;align-items:flex-start;text-align:right;direction:rtl;">
              <div style="width:30px;height:30px;border-radius:50%;background:#fef3c7;display:flex;align-items:center;justify-content:center;color:#b45309;font-size:1rem;flex-shrink:0;"><i class="fas fa-comment-dots"></i></div>
              <div style="flex:1;">
                <div style="font-weight:700;font-size:.76rem;margin-bottom:2px;color:#b45309;">توضيح الأنكل / الإجابة الصحيحة:</div>
                <div style="font-size:.84rem;line-height:1.5;white-space:pre-wrap;">${esc(note)}</div>
              </div>
            </div>`;
          }

          html += `</div>`;
        });
      }
      html += `</div>`;

      openModal(html);
    }

    // ── V3 MOBILE APP LOGIC & WIZARD STEPS & GENIUS SEARCH ──────────────
    let selectedRecipientId = null;
    let selectedRecipientNameStr = "";
    let selectedSendCategory = "all";
    let wizardCurrentStep = 1;
    let hasSiblingsLoaded = false;

    function getInitialTab() {
      const initialTab = location.hash.replace('#', '') || 'home';
      const allowedTabs = IS_PUBLIC ? ['home', 'attendance'] : ['home', 'attendance', 'send', 'tasks', 'family'];
      return allowedTabs.includes(initialTab) ? initialTab : 'home';
    }

    window.addEventListener('popstate', async (event) => {
      if (!student) return;

      // 1. Handle tab switching based on hash
      const targetTab = getInitialTab();
      const activeItem = document.querySelector('.bottom-nav-item.active');
      const currentTab = activeItem ? activeItem.getAttribute('data-tab') : 'home';
      if (targetTab !== currentTab) {
        switchTab(targetTab);
      }

      // 2. Handle profile changes based on query param id
      const m = location.search.match(/[?&]id=(\d+)/);
      const targetId = m ? parseInt(m[1]) : null;

      if (targetId) {
        if (student && student.id === targetId) {
          // Already on this student's profile (either own or friend)
          // If we were viewing friend but the ID matches own account, switch back to own
          const isOwn = allAccounts.some(a => Number(a.id) === targetId);
          if (isOwn && isViewingOther()) {
            returnToMyProfile(false);
          }
        } else {
          const matched = allAccounts.find(a => Number(a.id) === targetId);
          if (matched) {
            if (isViewingOther()) {
              returnToMyProfile(false);
            }
            student = matched;
            localStorage.setItem('activeKidAccountId', String(matched.id));
            renderPrivate(student);
            loadSiblings();
            document.getElementById('bottomNavBar').style.display = 'flex';
          } else {
            await openFriendProfile(targetId, false);
          }
        }
      } else {
        // No ID in URL, return to my profile
        if (isViewingOther() && _myStudent) {
          returnToMyProfile(false);
        }
      }
    });

    function switchTab(tabName) {
      const allowedTabs = IS_PUBLIC ? ['home', 'attendance'] : ['home', 'attendance', 'send', 'tasks', 'family'];
      if (!allowedTabs.includes(tabName)) {
        tabName = 'home';
      }

      document.querySelectorAll('.bottom-nav-item').forEach(item => {
        item.classList.remove('active');
      });
      const activeItem = document.querySelector(`.bottom-nav-item[data-tab="${tabName}"]`);
      if (activeItem) activeItem.classList.add('active');

      // Update hash in URL
      if (location.hash.replace('#', '') !== tabName) {
        if (!location.hash && tabName === 'home') {
          history.replaceState(null, '', '#home');
        } else {
          location.hash = tabName;
        }
      }

      const hero = document.querySelector('.hero');
      const statsBar = document.getElementById('statsBar');
      const scInfo = document.getElementById('scInfo');
      const scAnnBanner = document.getElementById('scAnnBanner');
      const scAnn = document.getElementById('scAnn');
      const homeSearchBar = document.getElementById('homeSearchBar');
      const scAtt = document.getElementById('scAtt');
      const scTasks = document.getElementById('scTasks');
      const scTrips = document.getElementById('scTrips');
      const scUncles = document.getElementById('scUncles');
      const scSiblings = document.getElementById('scSiblings');
      const scSendCoupons = document.getElementById('scSendCoupons');
      const scPaperExams = document.getElementById('scPaperExams');
      const mainPage = document.getElementById('mainPage');

      // Adjust mainPage padding/width for fullscreen tabs (send coupons)
      if (mainPage) {
        if (tabName === 'send') {
          mainPage.style.padding = '0';
          mainPage.style.maxWidth = 'none';
        } else {
          mainPage.style.padding = '0 14px 90px';
          mainPage.style.maxWidth = '860px';
        }
      }

      if (hero) hero.style.display = 'none';
      if (statsBar) {
        statsBar.style.setProperty('display', 'none', 'important');
        statsBar.style.marginTop = '0';
      }
      if (scInfo) scInfo.style.display = 'none';
      if (scAnnBanner) scAnnBanner.style.display = 'none';
      if (scAnn) scAnn.style.display = 'none';
      if (homeSearchBar) homeSearchBar.style.display = 'none';
      if (scAtt) scAtt.style.display = 'none';
      if (scTasks) scTasks.style.display = 'none';
      if (scTrips) scTrips.style.display = 'none';
      if (scUncles) scUncles.style.display = 'none';
      if (scSiblings) scSiblings.style.display = 'none';
      const scClassFriends = document.getElementById('scClassFriends');
      if (scClassFriends) scClassFriends.style.display = 'none';
      if (scSendCoupons) scSendCoupons.style.display = 'none';
      if (scPaperExams) scPaperExams.style.display = 'none';
      const scAccSwitcher = document.getElementById('scAccountSwitcher');
      if (scAccSwitcher) scAccSwitcher.style.display = 'none';

      const pPicBanner = document.getElementById('profilePicSuggestionBanner');
      if (pPicBanner) pPicBanner.style.display = 'none';

      // Load correct tab contents
      if (tabName === 'home') {
        if (hero) hero.style.display = 'flex';
        if (scInfo) scInfo.style.display = 'block';
        if (allTrips && allTrips.length && scTrips) scTrips.style.display = 'block';
        if (scAccSwitcher && allAccounts && allAccounts.length > 1 && !isViewingOther() && !IS_PUBLIC) {
          scAccSwitcher.style.display = 'block';
        }

        if (!IS_PUBLIC) {
          if (statsBar) statsBar.style.setProperty('display', 'grid', 'important');
        }
        const activeStudent = student || _myStudent;
        if (pPicBanner && activeStudent && !activeStudent.image_url && !isViewingOther() && localStorage.getItem('dismissProfilePicSuggestion') !== 'true') {
          pPicBanner.style.display = 'flex';
        }
      } else if (tabName === 'attendance') {
        if (scAtt) scAtt.style.display = 'block';
        if (statsBar) {
          statsBar.style.setProperty('display', 'grid', 'important');
          statsBar.style.marginTop = '18px';
        }
      } else if (tabName === 'send') {
        if (scSendCoupons) {
          scSendCoupons.style.display = 'block';
          initSendWizard();
        }
      } else if (tabName === 'tasks') {
        if (scTasks) {
          scTasks.style.display = 'block';
        }
        const activeStudent = student || _myStudent;
        if (activeStudent && activeStudent.paper_exams && activeStudent.paper_exams.length && scPaperExams) {
          scPaperExams.style.display = 'block';
        }
        const badge = document.getElementById('tasksBadge');
        if (badge) badge.style.display = 'none';
        if (maxFetchedTaskAnnId > 0 && student) {
          localStorage.setItem('tasksLastViewedAnnId_' + student.id, String(maxFetchedTaskAnnId));
        }
      } else if (tabName === 'family') {
        // Display siblings only if they have siblings
        if (hasSiblingsLoaded && scSiblings) {
          scSiblings.style.display = 'block';
        }
        if (classUncles && classUncles.length && scUncles) {
          scUncles.style.display = 'block';
        }
        loadClassFriends();
        if (!IS_PUBLIC && homeSearchBar) {
          homeSearchBar.style.display = 'block';
        }
      }
    }

    // ── GENIUS SEARCH LOGIC (LIKE UNCLE DASHBOARD) ───────────────────────
    function normalizeArabic(text) {
      if (!text) return "";
      return text
        .replace(/[أإآٱ]/g, "ا")
        .replace(/[ىئ]/g, "ي")
        .replace(/ة/g, "ه")
        .replace(/ؤ/g, "و")
        .replace(/[\u064B-\u0652]/g, "") // Remove Harakat
        .toLowerCase()
        .trim();
    }

    function francoToArabic(text) {
      if (!text) return "";
      let s = text.toLowerCase().trim();
      if (!/[a-z0-9]/.test(s)) return "";
      const multiMap = [
        ['sh', 'ش'], ['ch', 'تش'], ['kh', 'خ'], ['gh', 'غ'],
        ['th', 'ث'], ['dh', 'ذ'], ['zh', 'ج'],
        ['ph', 'ف'],
        ['ou', 'و'], ['oo', 'و'], ['ee', 'ي'], ['ei', 'اي'],
        ['aa', 'ا'], ['ii', 'ي'],
      ];
      for (const [from, to] of multiMap) {
        s = s.split(from).join(to);
      }
      const singleMap = {
        'a': 'ا', 'b': 'ب', 't': 'ت', 'g': 'ج', 'j': 'ج',
        'h': 'ح', 'd': 'د', 'r': 'ر', 'z': 'ز', 's': 'س',
        'c': 'ك',
        'f': 'ف', 'q': 'ق', 'k': 'ك', 'l': 'ل', 'm': 'م',
        'n': 'ن', 'w': 'و', 'u': 'و', 'o': 'و',
        'y': 'ي', 'i': 'ي', 'e': 'ي',
        'x': 'اكس', 'v': 'ف', 'p': 'ب',
        '2': 'ء', '3': 'ع', '4': 'ش', '5': 'خ',
        '6': 'ط', '7': 'ح', '8': 'غ', '9': 'ق',
      };
      let result = '';
      for (let i = 0; i < s.length; i++) {
        const ch = s[i];
        if (singleMap[ch]) {
          result += singleMap[ch];
        } else if (ch === ' ' || ch === '-' || ch === '_') {
          result += ' ';
        } else {
          result += ch;
        }
      }
      return normalizeArabic(result);
    }

    function arabicToLatin(text) {
      if (!text) return "";
      let s = text.toLowerCase().trim();
      if (!/[\u0600-\u06FF]/.test(s)) return "";
      s = normalizeArabic(s);
      const multiMap = [
        ['ش', 'sh'], ['خ', 'kh'], ['غ', 'gh'], ['ث', 'th'],
        ['ذ', 'dh'], ['ج', 'g'], ['ف', 'f'], ['ع', '3'],
        ['ط', '6'], ['ح', '7']
      ];
      for (const [from, to] of multiMap) {
        s = s.split(from).join(to);
      }
      const singleMap = {
        'ا': 'a', 'ب': 'b', 'ت': 't', 'ة': 'a',
        'د': 'd', 'ر': 'r', 'ز': 'z', 'س': 's', 'ص': 's', 'ض': 'd',
        'ق': 'q', 'ك': 'k', 'ل': 'l', 'م': 'm', 'ن': 'n',
        'ه': 'h', 'و': 'w', 'ي': 'y', 'ى': 'y', 'ئ': 'e', 'ء': '2', 'ؤ': 'o'
      };
      let result = '';
      for (let i = 0; i < s.length; i++) {
        const ch = s[i];
        if (singleMap[ch]) {
          result += singleMap[ch];
        } else if (ch === ' ' || ch === '-' || ch === '_') {
          result += ' ';
        } else {
          result += ch;
        }
      }
      return result;
    }

    function phoneticClean(str) {
      if (!str) return "";
      let s = str.toLowerCase().trim();
      s = s.replace(/p/g, 'b');
      s = s.replace(/v/g, 'f');
      s = s.replace(/c/g, 'k');
      s = s.replace(/q/g, 'k');
      s = s.replace(/j/g, 'g');
      s = s.replace(/z/g, 's');
      s = s.replace(/x/g, 'ks');
      s = s.replace(/[aeiouywh]/g, '');
      return s;
    }

    function getMatchScore(friend, query) {
      const dbId = String(friend.id || friend._studentId || friend['معرف'] || '');
      const queryClean = query.trim();
      if (dbId && queryClean && dbId === queryClean) {
        return 10000;
      }

      const qNormalized = normalizeArabic(query);
      const qRaw = query.trim().toLowerCase();
      const qFranco = francoToArabic(query);
      const qLatin = arabicToLatin(query);
      const qPhonetic = phoneticClean(query.includes(' ') ? query : (qLatin || qRaw));

      let maxScore = 0;
      const fields = [
        { val: friend.name || friend['الاسم'], weight: 1.0 },
        { val: friend.class || friend['الفصل'], weight: 0.7 },
        { val: (friend.id || friend['_studentId'] || friend['معرف'])?.toString(), weight: 1.1 }
      ];

      fields.forEach(field => {
        if (!field.val) return;
        const target = field.val.toString();
        const tNormalized = normalizeArabic(target);
        const tRaw = target.toLowerCase();
        const tLatin = arabicToLatin(target);
        const tPhonetic = phoneticClean(tLatin || tRaw);

        let currentScore = 0;
        if (tRaw === qRaw || tNormalized === qNormalized) {
          currentScore = 100;
        } else if (tRaw.startsWith(qRaw) || tNormalized.startsWith(qNormalized)) {
          currentScore = 80;
        } else if (tRaw.includes(qRaw) || tNormalized.includes(qNormalized)) {
          currentScore = 60;
        } else if (qFranco && tNormalized === qFranco) {
          currentScore = 92;
        } else if (qFranco && tNormalized.startsWith(qFranco)) {
          currentScore = 72;
        } else if (qFranco && tNormalized.includes(qFranco)) {
          currentScore = 52;
        } else if (qLatin && tRaw === qLatin) {
          currentScore = 92;
        } else if (qLatin && tRaw.startsWith(qLatin)) {
          currentScore = 72;
        } else if (qLatin && tRaw.includes(qLatin)) {
          currentScore = 52;
        } else if (tLatin && tLatin === qRaw) {
          currentScore = 90;
        } else if (tLatin && tLatin.startsWith(qRaw)) {
          currentScore = 70;
        } else if (tLatin && tLatin.includes(qRaw)) {
          currentScore = 50;
        } else if (qPhonetic && tPhonetic && tPhonetic === qPhonetic) {
          currentScore = 88;
        } else if (qPhonetic && tPhonetic && tPhonetic.startsWith(qPhonetic)) {
          currentScore = 68;
        } else if (qPhonetic && tPhonetic && tPhonetic.includes(qPhonetic)) {
          currentScore = 48;
        } else {
          let score = 0;
          let queryIdx = 0;
          for (let i = 0; i < tNormalized.length && queryIdx < qNormalized.length; i++) {
            if (tNormalized[i] === qNormalized[queryIdx]) {
              queryIdx++;
              score++;
            }
          }
          if (queryIdx === qNormalized.length) {
            currentScore = (score / tNormalized.length) * 40;
          }
          if (qFranco) {
            let fScore = 0, fIdx = 0;
            for (let i = 0; i < tNormalized.length && fIdx < qFranco.length; i++) {
              if (tNormalized[i] === qFranco[fIdx]) { fIdx++; fScore++; }
            }
            if (fIdx === qFranco.length) {
              currentScore = Math.max(currentScore, (fScore / tNormalized.length) * 38);
            }
            let rScore = 0, rIdx = 0;
            for (let i = 0; i < qFranco.length && rIdx < tNormalized.length; i++) {
              if (qFranco[i] === tNormalized[rIdx]) { rIdx++; rScore++; }
            }
            if (rIdx === tNormalized.length) {
              const ratio = tNormalized.length / qFranco.length;
              currentScore = Math.max(currentScore, ratio * 70);
            }
          }
        }
        maxScore = Math.max(maxScore, currentScore * field.weight);
      });
      return maxScore;
    }

    // ── Home Genius Search ──
    let _homeSearchTimer = null;
    function onHomeSearch(val) {
      clearTimeout(_homeSearchTimer);
      const res = document.getElementById('homeSearchResults');
      if (!val || val.trim().length < 2) {
        res.innerHTML = '';
        res.style.display = 'none';
        return;
      }
      res.style.display = 'flex';
      res.innerHTML = '<div style="padding:12px;text-align:center;color:var(--t4);font-size:.82rem;"><i class="fas fa-spinner fa-spin"></i></div>';
      _homeSearchTimer = setTimeout(() => doHomeSearch(val.trim()), 300);
    }

    async function doHomeSearch(q) {
      const res = document.getElementById('homeSearchResults');
      try {
        const d = await api({ action: 'searchKidsByName', query: q, church_id: student?.church_id || 0 });
        const list = d.users || d.kids || [];
        if (!d.success || !list.length) {
          res.innerHTML = '<div style="padding:12px;text-align:center;color:var(--t3);font-size:.82rem;">لم يُعثر على نتائج</div>';
          res.style.display = 'flex';
          return;
        }

        // Score and sort search results locally (Genius style)
        let scored = list.map(k => {
          return { ...k, _score: getMatchScore(k, q) };
        }).filter(k => k._score > 0);

        scored.sort((a, b) => b._score - a._score);

        res.innerHTML = scored.map(k => {
          const av = k.image_url
            ? `<img src="${esc(k.image_url)}" alt="${esc(k.name)}">`
            : `<span>${k.name.charAt(0)}</span>`;
          const isSelf = student && k.id === student.id;
          const isExactIdMatch = String(k.id).trim() === q.trim();
          return `
            <div class="friend-result-card" onclick="${isSelf ? 'window.scrollTo({top:0,behavior:\'smooth\'})' : 'openFriendProfile(' + k.id + ')'}" style="margin-bottom:0; width:100%;">
              <div class="friend-result-av">${av}</div>
              <div class="friend-result-info">
                <div class="friend-result-name" style="display:flex; align-items:center; gap:6px;">
                  <span>${esc(k.name)}</span>
                  ${isSelf ? '<span style="font-size:.68rem;color:var(--cou);font-weight:700;">(أنت)</span>' : ''}
                  ${isExactIdMatch ? `<span style="background:var(--brand); color:#fff; font-size:0.6rem; padding:1px 5px; border-radius:3px; font-weight:bold; white-space:nowrap;">ID: ${k.id}</span>` : ''}
                </div>
                <div class="friend-result-meta">${esc(k.class || '—')}${k.church_name ? ' · ' + esc(k.church_name) : ''}</div>
              </div>
              <div class="friend-result-cou"><i class="fas fa-star" style="font-size:.72rem;margin-left:3px;"></i>${k.coupons}</div>
            </div>`;
        }).join('');
        res.style.display = 'flex';
      } catch (e) {
        res.innerHTML = '<div style="padding:12px;text-align:center;color:var(--err);font-size:.82rem;">خطأ في البحث</div>';
        res.style.display = 'flex';
      }
    }

    function clearHomeSearch() {
      document.getElementById('homeFriendSearch').value = '';
      const res = document.getElementById('homeSearchResults');
      res.innerHTML = '';
      res.style.display = 'none';
    }

    // Close genius search dropdown on click outside
    document.addEventListener('click', function (e) {
      const wrap = document.getElementById('homeSearchBar');
      if (wrap && !wrap.contains(e.target)) {
        const dropdown = document.getElementById('homeSearchResults');
        if (dropdown) dropdown.style.display = 'none';
      }
    });

    // Show dropdown again on focus if input has value
    onDOMReady(() => {
      const input = document.getElementById('homeFriendSearch');
      if (input) {
        input.addEventListener('focus', function () {
          if (this.value.trim().length >= 2) {
            const dropdown = document.getElementById('homeSearchResults');
            if (dropdown) dropdown.style.display = 'flex';
          }
        });
      }
    });

    // ── Sibling loading overrides ──
    async function loadSiblings() {
      const container = document.getElementById('siblingsList');
      const scSiblings = document.getElementById('scSiblings');
      if (!student || !student.custom_info || !student.custom_info.sibling_group) {
        hasSiblingsLoaded = false;
        if (scSiblings) scSiblings.style.display = 'none';
        return;
      }
      try {
        const d = await api({ action: 'getSiblingGroupMembers', studentId: student.id });
        if (d.success && d.siblings && d.siblings.length > 0) {
          const others = d.siblings.filter(s => s.id != student.id);
          if (others.length === 0) {
            hasSiblingsLoaded = false;
            if (scSiblings) scSiblings.style.display = 'none';
            return;
          }
          hasSiblingsLoaded = true;
          container.innerHTML = others.map(s => {
            const photo = s.image_url
              ? `<img src="${esc(s.image_url)}" style="width:100%;height:100%;object-fit:cover;border-radius:var(--r-md);" alt="${esc(s.name)}">`
              : `<i class="fas fa-user" style="font-size:1.8rem;color:var(--t4);"></i>`;
            return `
              <div class="sibling-card" onclick="openFriendProfile(${s.id})" style="background:var(--surf);border:1.5px solid var(--bdr);border-radius:var(--r-md);padding:14px 10px;text-align:center;cursor:pointer;transition:all var(--fast);display:flex;flex-direction:column;align-items:center;gap:8px;box-shadow:var(--sh-sm);">
                <div style="width:56px;height:56px;border-radius:var(--r-md);background:var(--bg);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;">
                  ${photo}
                </div>
                <div style="font-size:.82rem;font-weight:800;color:var(--t1);line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;width:100%;">${esc(s.name)}</div>
                <div style="font-size:.68rem;color:var(--t4);font-weight:700;">${esc(s.class || '—')}</div>
                <div style="display:inline-flex;align-items:center;gap:4px;background:var(--brand-bg);color:var(--brand);padding:2px 8px;border-radius:99px;font-size:.7rem;font-weight:800;">
                  <i class="fas fa-star" style="font-size:.65rem;"></i> ${s.coupons}
                </div>
              </div>
            `;
          }).join('');

          // Set up chips in Wizard Step 1
          const chipsContainer = document.getElementById('siblingChipsContainer');
          const chipsList = document.getElementById('sendSiblingChips');
          if (chipsContainer && chipsList) {
            chipsContainer.style.display = 'block';
            chipsList.innerHTML = others.map(s => `
              <div class="send-recipient-chip" data-id="${s.id}" data-name="${esc(s.name)}" onclick="selectRecipient(${s.id}, '${esc(s.name)}')">
                <div style="font-size:.8rem;font-weight:800;">${esc(s.name.split(' ')[0])}</div>
                <div style="font-size:.62rem;color:rgba(255,255,255,0.7);">أخ / أخت</div>
              </div>
            `).join('');
          }
        } else {
          hasSiblingsLoaded = false;
          if (scSiblings) scSiblings.style.display = 'none';
        }
      } catch (e) {
        hasSiblingsLoaded = false;
        if (scSiblings) scSiblings.style.display = 'none';
      }
    }

    async function loadClassFriends() {
      const container = document.getElementById('friendsCardGrid');
      const scClassFriends = document.getElementById('scClassFriends');
      if (!container || !scClassFriends) return;
      if (!student || !student.church_id || (!student.class && !student.class_id)) {
        scClassFriends.style.display = 'none';
        return;
      }
      try {
        const d = await api({
          action: 'getPublicClassMembers',
          church_id: student.church_id,
          class_name: student.class || '',
          class_id: student.class_id || 0
        });
        if (d.success && d.members && d.members.length > 0) {
          const others = d.members.filter(s => s.id != student.id);
          if (others.length === 0) {
            scClassFriends.style.display = 'none';
            return;
          }
          container.innerHTML = others.map(s => {
            const photo = s.image_url
              ? `<img src="${esc(s.image_url)}" style="width:100%;height:100%;object-fit:cover;border-radius:var(--r-md);" alt="${esc(s.name)}">`
              : `<i class="fas fa-user" style="font-size:1.8rem;color:var(--t4);"></i>`;
            return `
              <div class="sibling-card" onclick="openFriendProfile(${s.id})" style="background:var(--surf);border:1.5px solid var(--bdr);border-radius:var(--r-md);padding:14px 10px;text-align:center;cursor:pointer;transition:all var(--fast);display:flex;flex-direction:column;align-items:center;gap:8px;box-shadow:var(--sh-sm);">
                <div style="width:56px;height:56px;border-radius:var(--r-md);background:var(--bg);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;">
                  ${photo}
                </div>
                <div style="font-size:.82rem;font-weight:800;color:var(--t1);line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;width:100%;" title="${esc(s.name)}">${esc(s.name)}</div>
                <div style="font-size:.68rem;color:var(--t4);font-weight:700;">${esc(s.class || '—')}</div>
                <div style="display:inline-flex;align-items:center;gap:4px;background:var(--brand-bg);color:var(--brand);padding:2px 8px;border-radius:99px;font-size:.7rem;font-weight:800;">
                  <i class="fas fa-star" style="font-size:.65rem;"></i> ${s.coupons || 0}
                </div>
              </div>
            `;
          }).join('');
          scClassFriends.style.display = 'block';
        } else {
          scClassFriends.style.display = 'none';
        }
      } catch (e) {
        scClassFriends.style.display = 'none';
      }
    }

    // ── SEND COUPONS STEP WIZARD ──
    function initSendWizard() {
      if (isViewingOther()) {
        toast('يرجى تسجيل الدخول لتتمكن من إرسال الكوبونات', 'err');
        switchTab('home');
        return;
      }

      // Update available values
      document.getElementById('sendAvailAtt').innerText = student.att_coupons || 0;
      document.getElementById('sendAvailCom').innerText = student.com_coupons || 0;
      document.getElementById('sendAvailTsk').innerText = student.task_coupons || 0;
      document.getElementById('sendAvailTotal').innerText = student.coupons || 0;

      goToStep(1);
      clearSelectedRecipient();
      document.getElementById('sendAmount').value = '';
      document.getElementById('sendPassword').value = '';
    }

    function goToStep(stepNum) {
      wizardCurrentStep = stepNum;

      // Toggle views
      document.getElementById('sendStep1').style.display = stepNum === 1 ? 'block' : 'none';
      document.getElementById('sendStep2').style.display = stepNum === 2 ? 'block' : 'none';
      document.getElementById('sendStep3').style.display = stepNum === 3 ? 'block' : 'none';

      // Update dots
      document.querySelectorAll('.wizard-dot').forEach((dot, idx) => {
        dot.classList.remove('active');
        if (idx + 1 === stepNum) dot.classList.add('active');
      });

      if (stepNum === 2) {
        checkStep2Valid();
      } else if (stepNum === 3) {
        // Show summary message
        let label = "الكل مدمج";
        if (selectedSendCategory === 'att') label = "الحضور";
        else if (selectedSendCategory === 'com') label = "الالتزام";
        else if (selectedSendCategory === 'task') label = "التاسكات";

        const amount = parseInt(document.getElementById('sendAmount').value);
        document.getElementById('sendSummaryMsg').innerHTML = `سوف تقوم بإرسال <strong style="font-size:1.15rem; color:#f59e0b;">${amount}</strong> كوبون من رصيد [${label}] إلى صديقك <strong style="color:#60a5fa;">(${selectedRecipientNameStr})</strong>.`;
        checkStep3Valid();
      }
    }

    function selectRecipient(id, name) {
      selectedRecipientId = id;
      selectedRecipientNameStr = name;

      document.querySelectorAll('.send-recipient-chip').forEach(chip => {
        chip.classList.remove('active');
        if (chip.getAttribute('data-id') == id) chip.classList.add('active');
      });

      document.getElementById('selectedRecipientName').innerText = name;
      document.getElementById('selectedRecipientTag').style.display = 'flex';

      // Clear searches
      document.getElementById('sendFriendSearch').value = '';
      document.getElementById('sendFriendSearchResults').style.display = 'none';

      // Enable next step button
      document.getElementById('toStep2Btn').disabled = false;
    }

    function clearSelectedRecipient() {
      selectedRecipientId = null;
      selectedRecipientNameStr = "";
      document.querySelectorAll('.send-recipient-chip').forEach(chip => chip.classList.remove('active'));
      document.getElementById('selectedRecipientTag').style.display = 'none';
      document.getElementById('toStep2Btn').disabled = true;
    }

    let _sendFriendSearchTimer = null;
    function onSendFriendSearch(val) {
      clearTimeout(_sendFriendSearchTimer);
      const res = document.getElementById('sendFriendSearchResults');
      if (!val || val.trim().length < 2) { res.innerHTML = ''; res.style.display = 'none'; return; }
      res.style.display = 'block';
      res.innerHTML = '<div style="padding:10px;text-align:center;color:var(--t4);font-size:.78rem;"><i class="fas fa-spinner fa-spin"></i></div>';
      _sendFriendSearchTimer = setTimeout(() => doSendFriendSearch(val.trim()), 300);
    }

    async function doSendFriendSearch(q) {
      const res = document.getElementById('sendFriendSearchResults');
      try {
        const d = await api({ action: 'searchKidsByName', query: q, church_id: student?.church_id || 0 });
        const list = d.users || d.kids || [];
        if (!d.success || !list.length) {
          res.innerHTML = '<div style="padding:10px;text-align:center;color:var(--t3);font-size:.78rem;">لم يُعثر على نتائج</div>';
          return;
        }

        // Score results
        let scored = list.filter(k => k.id != student.id).map(k => {
          return { ...k, _score: getMatchScore(k, q) };
        }).filter(k => k._score > 0);
        scored.sort((a, b) => b._score - a._score);

        res.innerHTML = scored.map(k => {
          const isExactIdMatch = String(k.id).trim() === q.trim();
          return `
          <div style="display:flex;align-items:center;gap:10px;padding:10px;border-bottom:1px solid var(--bdr2);cursor:pointer; color:var(--t1);" onclick="selectRecipient(${k.id}, '${esc(k.name)}')">
            <div style="width:30px;height:30px;border-radius:50%;background:var(--brand-bg);color:var(--brand);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.75rem;overflow:hidden;">
              ${k.image_url ? `<img src="${esc(k.image_url)}" style="width:100%;height:100%;object-fit:cover;">` : k.name.charAt(0)}
            </div>
            <div style="flex:1;min-width:0;text-align:right;">
              <div style="font-size:.8rem;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:flex;align-items:center;gap:6px;">
                <span>${esc(k.name)}</span>
                ${isExactIdMatch ? `<span style="background:var(--brand); color:#fff; font-size:0.6rem; padding:1px 5px; border-radius:3px; font-weight:bold; white-space:nowrap;">ID: ${k.id}</span>` : ''}
              </div>
              <div style="font-size:.64rem;color:var(--t4);">${esc(k.class || '—')}</div>
            </div>
          </div>`;
        }).join('');
      } catch (e) {
        res.innerHTML = '<div style="padding:10px;text-align:center;color:var(--err);font-size:.78rem;">خطأ في البحث</div>';
      }
    }

    function selectSendCat(btn) {
      document.querySelectorAll('.send-cat-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      selectedSendCategory = btn.getAttribute('data-cat');
      checkStep2Valid();
    }

    function checkStep2Valid() {
      const amount = parseInt(document.getElementById('sendAmount').value);
      let limit = student.coupons || 0;
      if (selectedSendCategory === 'att') limit = student.att_coupons || 0;
      else if (selectedSendCategory === 'com') limit = student.com_coupons || 0;
      else if (selectedSendCategory === 'task') limit = student.task_coupons || 0;

      const isValid = (!isNaN(amount) && amount > 0 && amount <= limit);
      document.getElementById('toStep3Btn').disabled = !isValid;
    }

    function checkStep3Valid() {
      const password = document.getElementById('sendPassword').value;
      document.getElementById('sendWizardSubmitBtn').disabled = !password;
    }

    function trySendCoupons() {
      const amount = parseInt(document.getElementById('sendAmount').value);
      const msg = `تأكيد نهائي: هل أنت متأكد من إرسال ${amount} كوبون إلى (${selectedRecipientNameStr})؟`;
      document.getElementById('shareConfirmMsg').innerText = msg;
      openOv('shareConfirmModal');
    }

    async function confirmSendCoupons() {
      if (isViewingOther()) {
        toast('غير مسموح في وضع المعاينة', 'err');
        return;
      }
      closeOv('shareConfirmModal');
      showLoad('جاري إرسال الكوبونات…');
      try {
        const password = document.getElementById('sendPassword').value;
        const amount = parseInt(document.getElementById('sendAmount').value);

        const d = await api({
          action: 'shareCoupons',
          senderId: student.id,
          password: password,
          recipientId: selectedRecipientId,
          amount: amount,
          category: selectedSendCategory
        });

        hideLoad();
        if (d.success) {
          toast(d.message || 'تم إرسال الكوبونات بنجاح!', 'ok');

          // Refresh and redirect to home after 1 second
          setTimeout(() => { location.reload(); }, 1200);
        } else {
          toast(d.message || 'فشل إرسال الكوبونات', 'err');
        }
      } catch (e) {
        hideLoad();
        toast('خطأ في الاتصال بالسيرفر', 'err');
      }
    }

    function renderPaperExams(s) {
      const list = s.paper_exams || [];
      const el = document.getElementById('paperExamsList');
      const container = document.getElementById('scPaperExams');
      if (!el || !container) return;

      if (!list.length) {
        container.style.display = 'none';
        return;
      }

      const currentTab = document.querySelector('.bottom-nav-item.active')?.getAttribute('data-tab') || 'home';
      if (currentTab === 'tasks') {
        container.style.display = 'block';
      } else {
        container.style.display = 'none';
      }

      el.innerHTML = list.map(exam => {
        const degreeText = exam.degree !== null ? `${exam.degree} / ${exam.total_degree}` : 'غير مرصود بعد';
        const degreeColor = exam.degree !== null
          ? (exam.degree >= exam.total_degree * 0.5 ? 'var(--ok)' : 'var(--danger)')
          : 'var(--text-3)';

        let refLinkHtml = '';
        if (exam.reference_url) {
          refLinkHtml = `
            <a href="${exam.reference_url}" target="_blank" class="badge" style="background:var(--brand-bg); color:var(--brand); display:inline-flex; align-items:center; gap:4px; font-weight:700; text-decoration:none; padding:4px 8px; border-radius:12px; font-size:0.72rem; cursor:pointer;">
              <i class="fas fa-external-link-alt"></i> ورقة الامتحان
            </a>
          `;
        }

        let answersPicHtml = '';
        if (exam.answers_picture) {
          answersPicHtml = `
            <a href="${exam.answers_picture}" target="_blank" class="badge" style="background:#dcfce7; color:#15803d; display:inline-flex; align-items:center; gap:4px; font-weight:700; text-decoration:none; padding:4px 8px; border-radius:12px; font-size:0.72rem; cursor:pointer;">
              <i class="fas fa-image"></i> ورقة إجابتك
            </a>
          `;
        }

        return `
          <div class="glass-card" style="padding:12px; border:1px solid var(--bdr); border-radius:12px; display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:4px; box-sizing:border-box;">
            <div style="display:flex; flex-direction:column; gap:4px; text-align:right;">
              <strong style="font-size:0.88rem; color:var(--txt);">${esc(exam.name)}</strong>
              <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                ${refLinkHtml}
                ${answersPicHtml}
              </div>
            </div>
            <div style="font-weight:900; font-size:0.95rem; color:${degreeColor}; white-space:nowrap;">
              ${degreeText}
            </div>
          </div>
        `;
      }).join('');
    }

    const VAPID_PUBLIC_KEY = '<?= defined("VAPID_PUBLIC_KEY") ? VAPID_PUBLIC_KEY : "" ?>';

    function _urlBase64ToUint8Array(base64String) {
      const padding = '='.repeat((4 - base64String.length % 4) % 4);
      const base64 = (base64String + padding).replace(/\-/g, '+').replace(/_/g, '/');
      const rawData = window.atob(base64);
      const outputArray = new Uint8Array(rawData.length);
      for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
      }
      return outputArray;
    }

    async function _initPushNotifications() {
      const toggleRow = document.getElementById('notifToggleItem');
      const checkbox = document.getElementById('phoneNotifToggle');
      if (!toggleRow || !checkbox) return;

      if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        console.warn("Push notifications not supported on this browser.");
        checkbox.checked = false;
        checkbox.disabled = true;
        toggleRow.style.display = 'flex';
        toggleRow.style.cursor = 'pointer';
        toggleRow.onclick = () => {
          toast('يرجى إضافة التطبيق للشاشة الرئيسية (تثبيت كـ Web App) لتفعيل الإشعارات 📲', 'err', 6000);
        };
        return;
      }

      // Check current permission
      if (Notification.permission === 'denied') {
        checkbox.checked = false;
        checkbox.disabled = true;
        toggleRow.style.display = 'flex';
        toggleRow.style.cursor = 'pointer';
        toggleRow.onclick = () => {
          toast('الإشعارات محظورة. يرجى السماح بها من إعدادات المتصفح أو افتح التطبيق بعد إضافته للشاشة الرئيسية 📲', 'err', 6000);
        };
        return;
      }

      try {
        const reg = await navigator.serviceWorker.ready;
        let sub = await reg.pushManager.getSubscription();
        let needsResubscribe = false;
        if (sub) {
          if (sub.options && sub.options.applicationServerKey) {
            const currentKeyUint8 = _urlBase64ToUint8Array(VAPID_PUBLIC_KEY);
            const subKey = new Uint8Array(sub.options.applicationServerKey);
            let mismatch = subKey.length !== currentKeyUint8.length;
            if (!mismatch) {
              for (let i = 0; i < subKey.length; i++) {
                if (subKey[i] !== currentKeyUint8[i]) {
                  mismatch = true;
                  break;
                }
              }
            }
            if (mismatch) {
              try {
                await sub.unsubscribe();
              } catch (unsubErr) { }
              sub = null;
              needsResubscribe = true;
            }
          } else {
            needsResubscribe = true;
          }
        }

        if (Notification.permission === 'default') {
          checkAndShowNotificationPrompt('kid');
        }

        if (Notification.permission === 'granted' && (!sub || needsResubscribe)) {
          if (VAPID_PUBLIC_KEY) {
            try {
              sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: _urlBase64ToUint8Array(VAPID_PUBLIC_KEY)
              });
              needsResubscribe = true;
            } catch (err) {
              console.error("Auto push subscription on load failed:", err);
            }
          }
        }

        checkbox.checked = !!sub;
        toggleRow.style.display = 'flex';

        if (sub) {
          const savedEndpoint = localStorage.getItem('push_sub_saved_endpoint');
          if (needsResubscribe || !savedEndpoint || savedEndpoint !== sub.endpoint) {
            try {
              const res = await api({
                action: 'savePushSubscription',
                endpoint: sub.endpoint,
                p256dh: sub.toJSON().keys?.p256dh || '',
                auth: sub.toJSON().keys?.auth || '',
                student_id: student.id
              });
              if (res.success) {
                localStorage.setItem('push_sub_saved_endpoint', sub.endpoint);
              }
            } catch (saveErr) {
              console.error("Failed to save auto subscription:", saveErr);
            }
          }
        }

        // Remove old event listener and bind new one
        checkbox.onchange = async () => {
          if (checkbox.checked) {
            // Subscribe
            try {
              let permission = Notification.permission;
              if (permission !== 'granted') {
                permission = await Notification.requestPermission();
              }
              if (permission !== 'granted') {
                toast('تم رفض إذن الإشعارات ✕', 'err');
                checkbox.checked = false;
                return;
              }

              if (!VAPID_PUBLIC_KEY) {
                console.error("VAPID Key not found");
                return;
              }

              const newSub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: _urlBase64ToUint8Array(VAPID_PUBLIC_KEY)
              });

              // Save on backend
              const res = await api({
                action: 'savePushSubscription',
                endpoint: newSub.endpoint,
                p256dh: newSub.toJSON().keys?.p256dh || '',
                auth: newSub.toJSON().keys?.auth || '',
                student_id: student.id
              });

              if (res.success) {
                toast('تم تفعيل الإشعارات بنجاح ✓', 'ok');
                localStorage.setItem('push_sub_saved_endpoint', newSub.endpoint);
              } else {
                toast(res.message || 'فشل في حفظ الاشتراك', 'err');
                checkbox.checked = false;
                try {
                  await newSub.unsubscribe();
                } catch (e) { }
              }
            } catch (err) {
              console.error("Subscription failed:", err);
              toast('فشل في تفعيل الإشعارات ✕', 'err');
              checkbox.checked = false;
            }
          } else {
            // Unsubscribe & Full Reset of Notification State
            try {
              const currentSub = await reg.pushManager.getSubscription();
              const savedEndpoint = localStorage.getItem('push_sub_saved_endpoint');
              const endpointToDelete = currentSub ? currentSub.endpoint : (savedEndpoint || '');

              if (currentSub) {
                try {
                  await currentSub.unsubscribe();
                } catch (unsubErr) {
                  console.warn("Unsubscribe browser error:", unsubErr);
                }
              }

              // Always attempt backend delete by endpoint + student_id to clear any orphan subscriptions
              try {
                await api({
                  action: 'deletePushSubscription',
                  endpoint: endpointToDelete,
                  student_id: student.id
                });
              } catch (apiErr) {
                console.warn("Delete push subscription API error:", apiErr);
              }

              localStorage.removeItem('push_sub_saved_endpoint');
              checkbox.checked = false;
              toast('تم إيقاف الإشعارات وإعادة ضبطها بنجاح ✓', 'ok');
            } catch (err) {
              console.error("Unsubscription reset error:", err);
              localStorage.removeItem('push_sub_saved_endpoint');
              checkbox.checked = false;
              toast('تم إعادة ضبط حالة الإشعارات ✓', 'ok');
            }
          }
        };
      } catch (e) {
        console.error("Error setting up push notifications:", e);
      }
    }

    // loadAnn was combined and moved to the primary section above.

    if ('serviceWorker' in navigator) {
      window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(err => console.error('SW Registration failed:', err));
      });
    }

    let _pwaPrompt = null;

    window.addEventListener('beforeinstallprompt', e => {
      e.preventDefault();
      _pwaPrompt = e;
      const btn = document.getElementById('settingsPwaBtn');
      if (btn) btn.style.display = 'flex';
      const topBtn = document.getElementById('topbarDownloadBtn');
      if (topBtn) topBtn.style.display = 'flex';
    });

    window.addEventListener('appinstalled', () => {
      _pwaPrompt = null;
      const btn = document.getElementById('settingsPwaBtn');
      if (btn) btn.style.display = 'none';
      const topBtn = document.getElementById('topbarDownloadBtn');
      if (topBtn) topBtn.style.display = 'none';
      closePwaModal();
      toast('✅ تم تثبيت التطبيق بنجاح!', 'ok');
    });

    function triggerPwaInstall() {
      const ua = navigator.userAgent;
      const isIOS = /iPad|iPhone|iPod/.test(ua);
      const stepsEl = document.getElementById('pwaSteps');
      const installBtn = document.getElementById('pwaInstallNowBtn');

      if (_pwaPrompt) {
        if (stepsEl) stepsEl.innerHTML = `
          <div class="pwa-step"><div class="pwa-step-num">1</div><div>اضغط "تثبيت الآن" أدناه</div></div>
          <div class="pwa-step"><div class="pwa-step-num">2</div><div>وافق على طلب التثبيت</div></div>
          <div class="pwa-step"><div class="pwa-step-num">3</div><div>افتح التطبيق من الشاشة الرئيسية</div></div>`;
        if (installBtn) installBtn.style.display = 'flex';
      } else if (isIOS) {
        if (stepsEl) stepsEl.innerHTML = `
          <div style="font-weight:700;color:var(--brand, #5b6cf5);margin-bottom:8px;font-size:.84rem">على iPhone / iPad:</div>
          <div class="pwa-step"><div class="pwa-step-num">1</div><div>اضغط زر المشاركة <i class="fas fa-share-square" style="color:var(--brand, #5b6cf5)"></i> في أسفل المتصفح</div></div>
          <div class="pwa-step"><div class="pwa-step-num">2</div><div>اختر "إضافة إلى الشاشة الرئيسية"</div></div>
          <div class="pwa-step"><div class="pwa-step-num">3</div><div>اضغط "إضافة" — سيظهر أيقونة التطبيق</div></div>`;
        if (installBtn) installBtn.style.display = 'none';
      } else {
        if (stepsEl) stepsEl.innerHTML = `
          <div class="pwa-step"><div class="pwa-step-num">1</div><div>اضغط قائمة المتصفح ⋮ أو ⋯</div></div>
          <div class="pwa-step"><div class="pwa-step-num">2</div><div>اختر "تثبيت التطبيق" أو "إضافة إلى الشاشة الرئيسية"</div></div>
          <div class="pwa-step"><div class="pwa-step-num">3</div><div>وافق على التثبيت</div></div>`;
        if (installBtn) installBtn.style.display = 'none';
      }
      const modal = document.getElementById('pwaInstallModal');
      if (modal) modal.classList.add('show');
    }

    async function doPwaInstall() {
      if (!_pwaPrompt) return;
      _pwaPrompt.prompt();
      const { outcome } = await _pwaPrompt.userChoice;
      if (outcome === 'accepted') {
        _pwaPrompt = null;
        closePwaModal();
      }
    }

    function closePwaModal() {
      const modal = document.getElementById('pwaInstallModal');
      if (modal) modal.classList.remove('show');
    }

    function checkPwaSupport() {
      const ua = navigator.userAgent;
      const isIOS = /iPad|iPhone|iPod/.test(ua);
      if (isIOS) {
        const btn = document.getElementById('settingsPwaBtn');
        if (btn) btn.style.display = 'flex';
        const topBtn = document.getElementById('topbarDownloadBtn');
        if (topBtn) topBtn.style.display = 'flex';
      }
    }

    // Call on load
    window.addEventListener('load', checkPwaSupport);
  </script>

  <!-- PWA INSTALL MODAL -->
  <div id="pwaInstallModal" onclick="if(event.target===this)closePwaModal()">
    <div class="pwa-install-sheet">
      <div class="pwa-icon-big">
        <img src="/logo.png" alt="مدارس الأحد" style="width:84px;height:84px;object-fit:cover;display:block"
          onerror="this.outerHTML='<i class=\'fas fa-cross\' style=\'font-size:2rem;color:#fff\'></i>'">
      </div>
      <h3 style="font-size:1.1rem;font-weight:800;color:var(--text);margin-bottom:6px">تثبيت التطبيق</h3>
      <p style="color:var(--text-3);font-size:.84rem;margin-bottom:4px">ثبّت Sunday School على شاشتك الرئيسية للوصول
        السريع والعمل بدون إنترنت</p>
      <div class="pwa-steps" id="pwaSteps">
        <!-- filled by JS based on OS -->
      </div>
      <div style="display:flex;flex-direction:column;gap:8px;margin-top:4px">
        <button class="pwa-btn pwa-btn-primary" id="pwaInstallNowBtn" onclick="doPwaInstall()"><i
            class="fas fa-download"></i> تثبيت الآن</button>
        <button class="pwa-btn pwa-btn-ghost" onclick="closePwaModal()">ليس الآن</button>
      </div>
    </div>
  <!-- NOTIFICATION PERMISSION PROMPT MODAL -->
  <div id="notifPromptModal" style="display:none; position:fixed; inset:0; z-index:100000; background:rgba(15,23,42,0.6); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); align-items:center; justify-content:center; padding:16px;">
    <div style="background:var(--bg,#ffffff); max-width:440px; width:100%; border-radius:20px; padding:24px; text-align:center; box-shadow:0 20px 40px rgba(0,0,0,0.25); border:1px solid var(--bdr,rgba(0,0,0,0.08));">
      <div style="width:60px; height:60px; margin:0 auto 14px; border-radius:18px; background:linear-gradient(135deg, #6366f1, #8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-size:1.6rem; box-shadow:0 8px 20px rgba(99,102,241,0.35);">
        <i class="fas fa-bell"></i>
      </div>
      <h3 style="font-size:1.2rem; font-weight:800; color:var(--text,#1e293b); margin-bottom:4px;">تفعيل إشعارات الكنيسة</h3>
      <p style="font-size:0.83rem; color:var(--text-3,#64748b); margin-bottom:18px;">ليصلك التنبيه فور تصحيح التاسكات والتحديثات الجديدة على جهازك</p>

      <div id="notifPromptList" style="text-align:right; background:var(--bg2,#f8fafc); border:1px solid var(--bdr,rgba(0,0,0,0.08)); border-radius:14px; padding:14px; margin-bottom:20px; display:flex; flex-direction:column; gap:12px;"></div>

      <div style="display:flex; flex-direction:column; gap:10px;">
        <button type="button" onclick="allowNotificationsFromModal()" style="width:100%; padding:12px 18px; border-radius:12px; border:none; background:linear-gradient(135deg, #6366f1, #4f46e5); color:#fff; font-size:0.92rem; font-weight:800; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; box-shadow:0 4px 12px rgba(99,102,241,0.3);">
          <i class="fas fa-bell"></i> تفعيل الإشعارات الآن
        </button>
        <button type="button" onclick="dismissNotificationPromptModal()" style="width:100%; padding:10px 18px; border-radius:12px; border:1px solid var(--bdr,rgba(0,0,0,0.12)); background:transparent; color:var(--text-2,#475569); font-size:0.84rem; font-weight:700; cursor:pointer;">
          ربما لاحقاً
        </button>
      </div>
    </div>
  </div>

  <script>
    let _currentNotifRole = 'kid';

    function checkAndShowNotificationPrompt(role = 'kid') {
      if (!('Notification' in window)) return;
      if (Notification.permission !== 'default') return;
      if (sessionStorage.getItem('notif_prompt_dismissed')) return;

      _currentNotifRole = role;
      const listEl = document.getElementById('notifPromptList');
      if (!listEl) return;

      if (role === 'kid') {
        listEl.innerHTML = `
          <div style="display:flex; align-items:flex-start; gap:10px;">
            <div style="width:34px; height:34px; border-radius:10px; background:rgba(99, 102, 241, 0.12); color:#6366f1; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:0.95rem;"><i class="fas fa-tasks"></i></div>
            <div style="font-size:0.8rem; color:var(--text,#1e293b); line-height:1.4;">
              <strong style="display:block; color:#6366f1; font-weight:800; margin-bottom:2px;">تصحيح الإجابات والتاسكات</strong>
              إشعار فور تصحيح إجاباتك، تقييم التاسك، أو تنبيهات المراجعة.
            </div>
          </div>
          <div style="display:flex; align-items:flex-start; gap:10px;">
            <div style="width:34px; height:34px; border-radius:10px; background:rgba(234, 179, 8, 0.12); color:#ca8a04; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:0.95rem;"><i class="fas fa-star"></i></div>
            <div style="font-size:0.8rem; color:var(--text,#1e293b); line-height:1.4;">
              <strong style="display:block; color:#ca8a04; font-weight:800; margin-bottom:2px;">الكوبونات والمكافآت</strong>
              تنبيه فوري فور حصولك على كوبونات ونقاط جديدة في حسابك.
            </div>
          </div>
          <div style="display:flex; align-items:flex-start; gap:10px;">
            <div style="width:34px; height:34px; border-radius:10px; background:rgba(34, 197, 94, 0.12); color:#16a34a; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:0.95rem;"><i class="fas fa-bullhorn"></i></div>
            <div style="font-size:0.8rem; color:var(--text,#1e293b); line-height:1.4;">
              <strong style="display:block; color:#16a34a; font-weight:800; margin-bottom:2px;">الإعلانات والأنشطة</strong>
              متابعة إعلانات الكنيسة والأنشطة والرحلات المتاحة لك أولاً بأول.
            </div>
          </div>
        `;
      } else {
        listEl.innerHTML = `
          <div style="display:flex; align-items:flex-start; gap:10px;">
            <div style="width:34px; height:34px; border-radius:10px; background:rgba(99, 102, 241, 0.12); color:#6366f1; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:0.95rem;"><i class="fas fa-file-signature"></i></div>
            <div style="font-size:0.8rem; color:var(--text,#1e293b); line-height:1.4;">
              <strong style="display:block; color:#6366f1; font-weight:800; margin-bottom:2px;">تسليمات الأطفال الجدد</strong>
              تنبيه فوري عند تسليم أي طفل لتاسك جديد يحتاج للتصحيح.
            </div>
          </div>
          <div style="display:flex; align-items:flex-start; gap:10px;">
            <div style="width:34px; height:34px; border-radius:10px; background:rgba(59, 130, 246, 0.12); color:#2563eb; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:0.95rem;"><i class="fas fa-calendar-check"></i></div>
            <div style="font-size:0.8rem; color:var(--text,#1e293b); line-height:1.4;">
              <strong style="display:block; color:#2563eb; font-weight:800; margin-bottom:2px;">حضور وتنبيهات الخدمة</strong>
              إشعارات الحضور والغياب والتنبيهات الإدارية الهامة للكنيسة.
            </div>
          </div>
          <div style="display:flex; align-items:flex-start; gap:10px;">
            <div style="width:34px; height:34px; border-radius:10px; background:rgba(236, 72, 153, 0.12); color:#db2777; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:0.95rem;"><i class="fas fa-bus"></i></div>
            <div style="font-size:0.8rem; color:var(--text,#1e293b); line-height:1.4;">
              <strong style="display:block; color:#db2777; font-weight:800; margin-bottom:2px;">الرحلات والاشتراكات</strong>
              تنبيهات طلبات الانضمام واشتراكات الرحلات الجديدة.
            </div>
          </div>
        `;
      }

      const modal = document.getElementById('notifPromptModal');
      if (modal) {
        modal.style.display = 'flex';
      }
    }

    async function allowNotificationsFromModal() {
      dismissNotificationPromptModal();
      try {
        const perm = await Notification.requestPermission();
        if (perm === 'granted') {
          if (typeof toast === 'function') toast('تم تفعيل الإشعارات بنجاح', 'ok');
          else if (typeof showToast === 'function') showToast('تم تفعيل الإشعارات بنجاح', 'ok');
          if (typeof setupPushSubscription === 'function') setupPushSubscription();
        } else if (perm === 'denied') {
          if (typeof toast === 'function') toast('تم رفض الإشعارات', 'err');
          else if (typeof showToast === 'function') showToast('تم رفض الإشعارات', 'err');
        }
      } catch (err) {
        console.error("Error requesting notification permission:", err);
      }
    }

    function dismissNotificationPromptModal() {
      sessionStorage.setItem('notif_prompt_dismissed', '1');
      const modal = document.getElementById('notifPromptModal');
      if (modal) {
        modal.style.display = 'none';
      }
    }
  </script>
</body>

</html>