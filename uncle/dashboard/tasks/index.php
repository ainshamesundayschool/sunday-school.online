<?php
// Prevent caching of tasks dashboard to see instant updates
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past



// ══════════════════════════════════════════════════════════════════



// tasks.php  —  Uncle Dashboard → Tasks & Quizzes



// URL pattern:  /uncle/tasks/?class=الفصل+الأول



// Links back to dashboard class view automatically.



// ══════════════════════════════════════════════════════════════════



ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);
ini_set('session.gc_maxlifetime', 60 * 60 * 24 * 365 * 10);

$isTestingEnv = (strpos($_SERVER['REQUEST_URI'], '/testing/') !== false || strpos($_SERVER['SCRIPT_NAME'], '/testing/') !== false);
$pathPrefix = $isTestingEnv ? '/testing' : '';

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

if (file_exists($rootPath . '/config.php')) {
  require_once $rootPath . '/config.php';
}

$hasUncle = isset($_SESSION['uncle_id']);
$hasChurch = isset($_SESSION['church_id']);

if (!$hasUncle && !$hasChurch) { ?>
  <script>
    (function () {
      var KEY = '_ss_restoring';
      var prefix = window.location.pathname.indexOf('/testing/') !== -1 ? '/testing' : '';
      var loginUrl = prefix + '/login/?redirect=' + encodeURIComponent(window.location.href);

      if (!navigator.onLine) return;
      if (sessionStorage.getItem(KEY)) return;

      var ul = localStorage.getItem('uncleLoggedIn') === 'true';
      var cl = localStorage.getItem('loggedIn') === 'true';
      var un = localStorage.getItem('uncleUsername');
      var cc = localStorage.getItem('churchCode');

      if (!ul && !cl) { 
        if (navigator.onLine) window.location.href = loginUrl; 
        return; 
      }

      var fd = new FormData();
      fd.append('action', 'restore_session');
      if (ul && un) fd.append('username', un);
      else if (cl && cc) fd.append('church_code', cc);
      else { 
        if (navigator.onLine) window.location.href = loginUrl; 
        return; 
      }

      sessionStorage.setItem(KEY, '1');

      var dynamicApiUrl = prefix ? prefix + '/api.php' : '/api.php';
      fetch(dynamicApiUrl, { method: 'POST', body: fd, credentials: 'include' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.success) {
            var prevUncleId = localStorage.getItem('uncleId') || localStorage.getItem('uncle_id');
            var prevChurchId = localStorage.getItem('churchId') || localStorage.getItem('church_id');
            if (d.church_type) { try { localStorage.setItem('churchType', d.church_type); } catch (e) { } }
            if (d.church_name) { try { localStorage.setItem('churchName', d.church_name); } catch (e) { } }
            if (d.uncle_name) { try { localStorage.setItem('uncleName', d.uncle_name); } catch (e) { } }
            if (d.uncle_id) { try { localStorage.setItem('uncleId', d.uncle_id); localStorage.setItem('uncle_id', d.uncle_id); } catch (e) { } }
            if (d.church_id) { try { localStorage.setItem('churchId', d.church_id); localStorage.setItem('church_id', d.church_id); } catch (e) { } }

            var isMismatch = (d.uncle_id && prevUncleId && String(d.uncle_id) !== String(prevUncleId)) ||
                             (d.church_id && prevChurchId && String(d.church_id) !== String(prevChurchId));
            if (isMismatch) {
              window.location.reload();
            }
          } else if (navigator.onLine && d.message && (d.message.indexOf('not found') !== -1 || d.message.indexOf('No credentials') !== -1 || d.message.indexOf('معلقة') !== -1 || d.message.indexOf('انتظار موافقة') !== -1)) {
            ['loggedIn', 'uncleLoggedIn', 'churchCode', 'uncleUsername', 'churchName', 'uncleName']
              .forEach(function (k) { localStorage.removeItem(k); });
            window.location.href = loginUrl;
          }
        }).catch(function () {});
    })();
  </script>
<?php }







$uncleName = $_SESSION['uncle_name'] ?? '';
$uncleId = (int) ($_SESSION['uncle_id'] ?? 0);
$uncleRole = $_SESSION['uncle_role'] ?? '';

if (isset($_SESSION['uncle_id']) && intval($_SESSION['uncle_id']) > 0 && function_exists('getDBConnection')) {
    try {
        $conn = getDBConnection();
        $uStmt = $conn->prepare("SELECT role, name, church_id FROM uncles WHERE id = ? AND (deleted IS NULL OR deleted = 0) LIMIT 1");
        $uId = intval($_SESSION['uncle_id']);
        $uStmt->bind_param("i", $uId);
        $uStmt->execute();
        if ($uRow = $uStmt->get_result()->fetch_assoc()) {
            if (!empty($uRow['name'])) $_SESSION['uncle_name'] = $uRow['name'];
            if (!empty($uRow['church_id']) && empty($_SESSION['church_id'])) {
                $_SESSION['church_id'] = intval($uRow['church_id']);
            }
        }
    } catch (Exception $e) {}
}

if ((empty($_SESSION['church_name']) || $_SESSION['church_name'] === 'الكنيسة') && !empty($_SESSION['church_id']) && function_exists('getDBConnection')) {
    try {
        $conn = getDBConnection();
        $cStmt = $conn->prepare("SELECT church_name, church_type FROM churches WHERE id = ? LIMIT 1");
        $cId = intval($_SESSION['church_id']);
        $cStmt->bind_param("i", $cId);
        $cStmt->execute();
        if ($cRow = $cStmt->get_result()->fetch_assoc()) {
            $_SESSION['church_name'] = $cRow['church_name'];
            if (empty($_SESSION['church_type'])) $_SESSION['church_type'] = $cRow['church_type'];
        }
    } catch (Exception $e) {}
}

$churchName = $_SESSION['church_name'] ?? '';
if (empty($churchName) || $churchName === 'الكنيسة') {
    $churchName = 'مدارس الأحد';
}

$churchType = $_SESSION['church_type'] ?? 'kids';



$isYouth = ($churchType === 'youth');







// ?class= routing



$activeClass = trim(urldecode($_GET['class'] ?? ''));



$dashBack = $pathPrefix . '/uncle/dashboard/' . ($activeClass ? '?class=' . urlencode($activeClass) : '');
?>



<!DOCTYPE html>



<html lang="ar" dir="rtl" data-theme="light">



<head>



  <meta charset="UTF-8">



  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <!-- ═══ Social Preview Defaults ═══ -->
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Sunday School">
  <meta property="og:title" content="التاسكات">
  <meta property="og:description"
    content="منصة متكاملة لإدارة مدارس الأحد — الحضور، الكوبونات، الرحلات / المؤتمرات والمزيد">
  <meta property="og:url" content="https://sunday-school.online/uncle/dashboard/tasks/">
  <meta property="og:image" content="https://sunday-school.online/imgs/Sunday-School-Og.png">
  <meta property="og:image:width" content="1000">
  <meta property="og:image:height" content="1000">
  <meta property="og:image:type" content="image/png">
  <meta property="og:image:alt" content="Sunday School">
  <meta property="og:locale" content="ar_AR">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="التاسكات">
  <meta name="twitter:description"
    content="منصة متكاملة لإدارة مدارس الأحد — الحضور، الكوبونات، الرحلات / المؤتمرات والمزيد">
  <meta name="twitter:image" content="https://sunday-school.online/imgs/Sunday%20School%20App.png">




  <title>التاسكات — <?php echo htmlspecialchars($activeClass ?: $churchName); ?></title>



  <meta name="theme-color" content="#f8fafc">



  <link rel="preconnect" href="https://fonts.googleapis.com">



  <link
    href="https://fonts.googleapis.com/css2?family=Baloo+Bhaijaan+2:wght@400;500;600;700;800&family=Cairo:wght@300;400;500;600;700;800;900&family=Tajawal:wght@400;500;700;800&display=swap"
    rel="stylesheet">



  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">



  <link rel="icon" href="/favicon.ico">



  <style>
    :root {



      /* Brand - Modern Indigo-leaning Blue to match dashboard's #5b6cf5 */



      --brand: #5b6cf5;



      --brand-d: #4354e8;



      --brand-l: #a5b0ff;



      --brand-bg: #eef0ff;



      --brand-glow: rgba(91, 108, 245, .18);







      /* Semantic Colors */



      --ok: #10b981;



      --ok-bg: #d1fae5;



      --err: #ef4444;



      --err-bg: #fee2e2;



      --warn: #f59e0b;



      --warn-bg: #fef3c7;



      --info: #2563eb;



      --info-bg: #dbeafe;



      --cou: #8b5cf6;



      --cou-bg: #ede9fe;



      --coupon-grad: linear-gradient(135deg, #8b5cf6, #7c3aed);







      /* Surfaces & Text (Light Theme) */



      --t1: #1a1d2e;



      --t2: #4b5068;



      --t3: #8b90a8;



      --t4: #cdd1e2;



      --bg: #ffffff;



      --bg2: #f3f4f9;
      /* Matches dashboard background */



      --bg3: #f7f8fc;
      /* Matches dashboard surface-2 */



      --bdr: rgba(91, 108, 245, .12);



      --bdr2: #e4e6f0;







      /* Premium Smoother Border Radii */



      --r-sm: 10px;



      --r-md: 14px;



      --r-lg: 18px;



      --r-xl: 24px;



      --r-full: 9999px;







      /* Advanced Soft Shadows */



      --sh-sm: 0 2px 8px -2px rgba(0, 0, 0, .07);



      --sh-md: 0 8px 24px -4px rgba(0, 0, 0, .10);



      --sh-lg: 0 20px 48px -8px rgba(0, 0, 0, .14);



      --sh-brand: 0 4px 14px rgba(91, 108, 245, .25);







      /* Motion & Timings */



      --ease: cubic-bezier(.4, 0, .2, 1);



      --fast: .15s var(--ease);



      --norm: .22s var(--ease);



      --slow: .35s var(--ease);



    }







    [data-theme="dark"] {



      --bg: #181b26;



      --bg2: #0f1117;



      --bg3: #1e2132;



      --bdr: rgba(91, 108, 245, .18);



      --bdr2: #2a2d42;



      --t1: #e8eaf6;



      --t2: #9299be;



      --t3: #565c7a;



      --t4: #333852;



      --brand-bg: rgba(91, 108, 245, .15);



      --ok-bg: rgba(16, 185, 129, .15);



      --err-bg: rgba(239, 68, 68, .15);



      --warn-bg: rgba(245, 158, 11, .15);



      --cou-bg: rgba(139, 92, 246, .15);



    }







    *,
    *::before,
    *::after {



      margin: 0;



      padding: 0;



      box-sizing: border-box;



    }







    html {



      scroll-behavior: smooth;



    }







    /* Custom Scrollbars */



    ::-webkit-scrollbar {



      width: 6px;



      height: 6px;



    }



    ::-webkit-scrollbar-track {



      background: transparent;



    }



    ::-webkit-scrollbar-thumb {



      background: var(--bdr2);



      border-radius: var(--r-full);



    }



    ::-webkit-scrollbar-thumb:hover {



      background: var(--t3);



    }







    body {



      font-family: 'Cairo', 'Segoe UI', Tahoma, sans-serif;



      background: var(--bg2);



      color: var(--t1);



      min-height: 100vh;



      overflow-x: hidden;



      position: relative;



      transition: background var(--norm), color var(--norm);



    }







    /* Ambient Mesh Background */



    body::before {



      content: '';



      position: fixed;



      top: 0;



      left: 0;



      width: 100%;



      height: 100%;



      background:



        radial-gradient(ellipse 80% 50% at 10% -10%, rgba(91, 108, 245, .07) 0%, transparent 60%),



        radial-gradient(ellipse 60% 40% at 90% 110%, rgba(139, 92, 246, .05) 0%, transparent 60%);



      pointer-events: none;



      z-index: 0;



    }







    [data-theme="dark"] body::before {



      background:



        radial-gradient(ellipse 80% 50% at 10% -10%, rgba(91, 108, 245, .12) 0%, transparent 60%),



        radial-gradient(ellipse 60% 40% at 90% 110%, rgba(139, 92, 246, .08) 0%, transparent 60%);



    }







    button,
    input,
    select,
    textarea,
    a {



      font-family: inherit;



    }







    /* ── Topbar (Glassmorphic & Sleek) ── */



    .topbar {



      position: sticky;



      top: 0;



      z-index: 300;



      display: flex;



      align-items: center;



      gap: 12px;



      padding: 0 20px;



      height: 58px;



      background: var(--bg);



      border-bottom: 1px solid var(--bdr);



      box-shadow: var(--sh-sm);



      transition: background var(--fast), border-color var(--fast);



    }







    [data-theme="light"] .topbar {



      background: rgba(255, 255, 255, 0.88);



      backdrop-filter: blur(20px);



      -webkit-backdrop-filter: blur(20px);



    }







    [data-theme="dark"] .topbar {



      background: rgba(24, 27, 38, 0.88);



      backdrop-filter: blur(20px);



      -webkit-backdrop-filter: blur(20px);



    }







    .tb-back {



      display: flex;



      align-items: center;



      gap: 6px;



      padding: 6px 14px;



      border-radius: var(--r-md);



      background: var(--bg3);



      border: 1px solid var(--bdr);



      color: var(--t2);



      font-size: .82rem;



      font-weight: 700;



      text-decoration: none;



      transition: all var(--fast);



      white-space: nowrap;



      min-height: 38px;



    }







    .tb-back:hover {



      background: var(--brand-bg);



      color: var(--brand);



      border-color: var(--brand);



      transform: translateY(-1px);



    }







    .tb-title {



      flex: 1;



      display: flex;



      align-items: center;



      gap: 10px;



      font-weight: 800;



      font-size: 1rem;



      color: var(--t1);



      min-width: 0;



    }







    .tb-icon {



      width: 32px;



      height: 32px;



      border-radius: var(--r-sm);



      background: var(--brand);



      display: flex;



      align-items: center;



      justify-content: center;



      color: #fff;



      font-size: .85rem;



      flex-shrink: 0;



      box-shadow: var(--sh-brand);



    }







    .tb-cls {



      padding: 4px 12px;



      border-radius: var(--r-full);



      background: var(--brand-bg);



      border: 1px solid var(--brand-l);



      font-size: .72rem;



      font-weight: 700;



      color: var(--brand);



      white-space: nowrap;



    }







    .btn-create {



      display: inline-flex;



      align-items: center;



      gap: 6px;



      padding: 8px 18px;



      border-radius: var(--r-md);



      background: var(--brand);



      color: #fff;



      font-weight: 700;



      font-size: .83rem;



      border: none;



      cursor: pointer;



      box-shadow: var(--sh-brand);



      transition: all var(--fast);



      white-space: nowrap;



      min-height: 38px;



    }







    .btn-create:hover {



      background: var(--brand-d);



      transform: translateY(-1px);



      box-shadow: 0 6px 20px var(--brand-glow);



    }







    /* ── Page Wrapper ── */



    .page {



      max-width: 1140px;



      margin: 0 auto;



      padding: 24px 16px calc(70px + env(safe-area-inset-bottom));



      position: relative;



      z-index: 1;



    }







    /* ── Stats Dashboard ── */



    .stats {



      display: grid;



      grid-template-columns: repeat(4, 1fr);



      gap: 12px;



      margin-bottom: 20px;



    }







    .scard {



      background: var(--bg);



      border: 1px solid var(--bdr);



      border-radius: var(--r-lg);



      padding: 16px 18px;



      display: flex;



      align-items: center;



      gap: 14px;



      box-shadow: var(--sh-sm);



      transition: all var(--norm);



      position: relative;



      overflow: hidden;



    }







    .scard:hover {



      box-shadow: var(--sh-md);



      transform: translateY(-2px);



      border-color: var(--brand-l);



    }







    .scard-ico {



      width: 40px;



      height: 40px;



      border-radius: var(--r-md);



      display: flex;



      align-items: center;



      justify-content: center;



      font-size: .95rem;



      flex-shrink: 0;



    }







    .scard-val {



      font-size: 1.5rem;



      font-weight: 900;



      line-height: 1;



      color: var(--t1);



    }







    .scard-lbl {



      font-size: .72rem;



      color: var(--t3);



      margin-top: 2px;



      font-weight: 600;



    }







    /* ── Section Header & Filter Tabs ── */



    .sec-hdr {



      display: flex;



      align-items: center;



      justify-content: space-between;



      gap: 12px;



      margin-bottom: 16px;



      flex-wrap: wrap;



    }







    .sec-title {



      font-weight: 800;



      font-size: .95rem;



      color: var(--t1);



      display: flex;



      align-items: center;



      gap: 8px;



    }







    .sec-dot {



      width: 6px;



      height: 6px;



      border-radius: 50%;



      background: var(--brand);



      box-shadow: 0 0 8px var(--brand);



    }







    .ftabs {



      display: flex;



      gap: 6px;



      flex-wrap: wrap;



    }







    .ftab {



      padding: 6px 14px;



      border-radius: var(--r-full);



      font-size: .76rem;



      font-weight: 700;



      cursor: pointer;



      border: 1px solid var(--bdr);



      background: var(--bg);



      color: var(--t3);



      transition: all var(--fast);



    }







    .ftab:hover,
    .ftab.active {



      background: var(--brand-bg);



      color: var(--brand);



      border-color: var(--brand);



    }







    /* ── Hero / Welcome Section ── */



    .hero {



      display: grid;



      grid-template-columns: minmax(0, 1.5fr) minmax(260px, .85fr);



      gap: 16px;



      margin-bottom: 20px;



    }







    .hero-card {



      background: var(--bg);



      border: 1px solid var(--bdr);



      border-radius: var(--r-xl);



      padding: 24px;



      box-shadow: var(--sh-sm);



      position: relative;



      overflow: hidden;



    }







    .hero-card::before {



      content: '';



      position: absolute;



      top: -60px;



      left: -60px;



      width: 200px;



      height: 200px;



      border-radius: 50%;



      background: radial-gradient(circle, var(--brand-glow), transparent 70%);



      pointer-events: none;



    }







    .hero-main,
    .hero-side {



      position: relative;



      z-index: 1;



    }







    .hero-badge {



      display: inline-flex;



      align-items: center;



      gap: 6px;



      padding: 5px 12px;



      border-radius: var(--r-full);



      background: var(--brand-bg);



      border: 1px solid var(--brand-l);



      font-size: .74rem;



      font-weight: 700;



      color: var(--brand);



      margin-bottom: 14px;



    }







    .hero-title {



      font-size: 1.6rem;



      font-weight: 900;



      color: var(--t1);



      margin-bottom: 8px;



      line-height: 1.3;



    }







    .hero-sub {



      font-size: .88rem;



      line-height: 1.85;



      color: var(--t2);



      max-width: 580px;



    }







    .hero-actions {



      display: flex;



      flex-wrap: wrap;



      gap: 9px;



      margin-top: 18px;



    }







    .hero-link {



      display: inline-flex;



      align-items: center;



      gap: 6px;



      padding: 8px 16px;



      border-radius: var(--r-md);



      border: 1px solid var(--bdr);



      background: var(--bg);



      color: var(--t2);



      text-decoration: none;



      font-size: .79rem;



      font-weight: 700;



      transition: all var(--fast);



    }







    .hero-link:hover {



      border-color: var(--brand);



      color: var(--brand);



      background: var(--brand-bg);



      transform: translateY(-1px);



    }







    .hero-side {



      display: grid;



      grid-template-columns: 1fr 1fr;



      gap: 10px;



    }







    .hero-mini {



      background: var(--bg);



      border: 1px solid var(--bdr);



      border-radius: var(--r-lg);



      padding: 14px 16px;



      box-shadow: var(--sh-sm);



      display: flex;



      flex-direction: column;



      justify-content: center;



    }







    .hero-mini-label {



      font-size: .71rem;



      color: var(--t3);



      margin-bottom: 6px;



      font-weight: 700;



    }







    .hero-mini-value {



      font-size: 1.4rem;



      font-weight: 900;



      color: var(--t1);



      line-height: 1;



    }







    .hero-mini-note {



      font-size: .7rem;



      color: var(--t3);



      margin-top: 5px;



    }







    .list-shell {



      background: var(--bg);



      border: 1px solid var(--bdr);



      border-radius: var(--r-xl);



      padding: 20px;



      box-shadow: var(--sh-sm);



    }







    /* ── Tasks Grid & Cards ── */



    .tgrid {



      display: grid;



      grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));



      gap: 16px;



    }







    @keyframes cardIn {



      from {
        opacity: 0;
        transform: translateY(12px);
      }



      to {
        opacity: 1;
        transform: translateY(0);
      }



    }







    .tcard {



      background: var(--bg);



      border: 1px solid var(--bdr);



      border-radius: var(--r-lg);



      overflow: hidden;



      box-shadow: var(--sh-sm);



      transition: all var(--norm);



      cursor: pointer;



      animation: cardIn .35s var(--ease) both;



      position: relative;



    }







    .tcard:hover {



      box-shadow: var(--sh-md);



      border-color: var(--brand);



      transform: translateY(-3px);



    }







    .tcard-acc {



      height: 4px;



      background: var(--brand);



    }



    .tcard-acc.ok {
      background: var(--ok);
    }



    .tcard-acc.warn {
      background: var(--warn);
    }



    .tcard-acc.err {
      background: var(--t4);
    }







    .tcard-body {



      padding: 18px 18px 14px;



    }







    .tcard-top {



      display: flex;



      align-items: flex-start;



      justify-content: space-between;



      gap: 10px;



      margin-bottom: 12px;



    }







    .tcard-title {



      font-weight: 800;



      font-size: .97rem;



      color: var(--t1);



      line-height: 1.5;



      flex: 1;



      min-width: 0;



      word-break: break-word;



    }







    .tstatus {



      display: inline-flex;



      align-items: center;



      gap: 4px;



      padding: 4px 10px;



      border-radius: var(--r-full);



      font-size: .68rem;



      font-weight: 800;



      white-space: nowrap;



      flex-shrink: 0;



    }



    .s-active {
      background: var(--ok-bg);
      color: var(--ok);
    }



    .s-upcoming {
      background: var(--info-bg);
      color: var(--info);
    }



    .s-ended {
      background: var(--bg3);
      color: var(--t3);
      border: 1px solid var(--bdr);
    }



    .s-draft {
      background: var(--warn-bg);
      color: var(--warn);
    }







    .tclass-inline {



      display: inline-flex;



      align-items: center;



      gap: 5px;



      padding: 4px 10px;



      border-radius: var(--r-full);



      background: var(--brand-bg);



      border: 1px solid var(--brand-l);



      font-size: .7rem;



      font-weight: 700;



      color: var(--brand);



      margin-bottom: 11px;



    }







    .tmeta {



      display: grid;



      grid-template-columns: 1fr 1fr;



      gap: 8px;



      margin-bottom: 11px;



    }







    .tmeta-i {



      display: flex;



      align-items: center;



      gap: 6px;



      font-size: .71rem;



      color: var(--t2);



      background: var(--bg3);



      border: 1px solid var(--bdr);



      border-radius: var(--r-md);



      padding: 8px 10px;



      min-height: 38px;



    }



    .tmeta-i i {



      color: var(--brand);



      font-size: .75rem;



      flex-shrink: 0;



    }







    .tinfo-grid {



      display: grid;



      grid-template-columns: 1fr 1fr;



      gap: 8px;



      margin-bottom: 11px;



    }







    .tinfo-pill {



      display: flex;



      align-items: center;



      gap: 8px;



      background: var(--bg3);



      border: 1px solid var(--bdr);



      border-radius: var(--r-md);



      padding: 9px 11px;



    }



    .tinfo-pill i {



      font-size: .78rem;



      color: var(--brand);



    }







    .tip-val {



      font-size: .9rem;



      font-weight: 800;



      color: var(--t1);



    }



    .tip-lbl {



      font-size: .67rem;



      color: var(--t3);



      font-weight: 600;



    }







    .tprogress {



      padding: 10px 12px;



      border-radius: var(--r-md);



      background: var(--brand-bg);



      border: 1px solid rgba(165, 180, 252, .35);



    }







    .prog-bar {



      height: 5px;



      background: var(--bdr2);



      border-radius: var(--r-full);



      overflow: hidden;



      margin-bottom: 6px;



    }







    .prog-fill {



      height: 100%;



      border-radius: var(--r-full);



      background: var(--brand);



      transition: width .6s var(--ease);



    }







    .prog-lbl {



      display: flex;



      justify-content: space-between;



      font-size: .68rem;



      color: var(--t2);



      font-weight: 700;



    }







    .tcard-foot {



      padding: 12px 18px;



      border-top: 1px solid var(--bdr);



      display: flex;



      align-items: center;



      justify-content: space-between;



      gap: 8px;



      background: var(--bg3);



    }







    .tclass-badge {



      display: inline-flex;



      align-items: center;



      gap: 5px;



      padding: 5px 12px;



      border-radius: var(--r-full);



      font-size: .7rem;



      font-weight: 700;



      background: var(--bg);



      color: var(--t2);



      border: 1px solid var(--bdr);



      min-width: 0;



      overflow: hidden;



      text-overflow: ellipsis;



      white-space: nowrap;



      max-width: 140px;



    }







    .tact {



      display: flex;



      gap: 6px;



    }







    .tbtn {



      height: 36px;



      padding: 0 14px;



      border-radius: var(--r-md);



      border: 1px solid var(--bdr);



      background: var(--bg);



      display: flex;



      align-items: center;



      justify-content: center;



      cursor: pointer;



      color: var(--t2);



      font-size: .75rem;



      transition: all var(--fast);



      gap: 6px;



      font-weight: 700;



      white-space: nowrap;



    }







    .tbtn:hover {



      background: var(--brand-bg);



      color: var(--brand);



      border-color: var(--brand);



      transform: translateY(-1px);



    }



    .tbtn.d:hover {



      background: var(--err-bg);



      color: var(--err);



      border-color: var(--err);



    }



    .tbtn.view-btn {



      background: var(--brand-bg);



      color: var(--brand);



      border-color: var(--brand-l);



    }



    .tbtn.view-btn:hover {



      background: var(--brand);



      color: #fff;



      border-color: var(--brand);



    }







    .tbtn-lbl {



      font-size: .74rem;



      font-weight: 700;



    }







    .empty {



      grid-column: 1/-1;



      text-align: center;



      padding: 60px 20px;



    }







    .empty-ico {



      width: 70px;



      height: 70px;



      border-radius: 50%;



      background: var(--brand-bg);



      display: flex;



      align-items: center;



      justify-content: center;



      margin: 0 auto 16px;



      font-size: 1.8rem;



      color: var(--brand);



      box-shadow: 0 8px 20px var(--brand-glow);



    }







    .empty-t {



      font-weight: 800;



      font-size: .97rem;



      color: var(--t1);



      margin-bottom: 5px;



    }







    .empty-s {



      font-size: .82rem;



      color: var(--t3);



      margin-bottom: 18px;



    }







    /* ── Overlay / Glass Modal ── */



    .overlay {



      position: fixed;



      inset: 0;



      background: rgba(15, 17, 23, 0.45);



      backdrop-filter: blur(8px);



      -webkit-backdrop-filter: blur(8px);



      z-index: 500;



      display: flex;



      align-items: flex-start;



      justify-content: center;



      padding: 24px;



      overflow-y: auto;



      opacity: 0;



      visibility: hidden;




    }



    .overlay.fullscreen {
      position: fixed !important;
      inset: 0 !important;
      width: 100vw !important;
      height: 100vh !important;
      height: 100dvh !important;
      max-width: 100vw !important;
      max-height: 100vh !important;
      max-height: 100dvh !important;
      padding: 0 !important;
      margin: 0 !important;
      align-items: stretch !important;
      justify-content: stretch !important;
      z-index: 2000 !important;
      background: var(--bg, #ffffff) !important;
      display: flex !important;
      flex-direction: column !important;
      overflow: hidden !important;
    }

    .overlay.fullscreen .modal,
    .overlay.fullscreen .modal.wide,
    .modal.wide {
      width: 100% !important;
      max-width: 100% !important;
      height: 100% !important;
      height: 100vh !important;
      height: 100dvh !important;
      max-height: 100vh !important;
      max-height: 100dvh !important;
      min-height: 0 !important;
      margin: 0 !important;
      border-radius: 0 !important;
      border: none !important;
      box-shadow: none !important;
      display: flex !important;
      flex-direction: column !important;
      overflow: hidden !important;
      transform: none !important;
      background: var(--bg, #ffffff) !important;
    }

    .overlay.fullscreen .mhdr,
    .overlay.fullscreen .mfoot,
    .overlay.fullscreen .modal>div:nth-child(2) {
      flex-shrink: 0 !important;
    }

    .overlay.fullscreen .mbody,
    .modal.wide .mbody,
    #dBody {
      flex: 1 1 auto !important;
      min-height: 0 !important;
      height: 0 !important;
      overflow-y: auto !important;
      overflow-x: hidden !important;
      -webkit-overflow-scrolling: touch !important;
    }

    .overlay.open {
      display: flex !important;
      opacity: 1 !important;
      visibility: visible !important;
      pointer-events: auto !important;
    }

    .overlay:not(.open),
    .overlay:not(.open) * {
      display: none !important;
      opacity: 0 !important;
      visibility: hidden !important;
      pointer-events: none !important;
    }

    .modal {
      background: var(--bg);
      border-radius: var(--r-xl);
      width: 100%;
      max-width: 740px;
      margin: auto;
      max-height: calc(100vh - 32px);
      max-height: calc(100dvh - 32px);
      display: flex;
      flex-direction: column;
      overflow: hidden;
      box-shadow: var(--sh-lg);
      transform: translateY(20px) scale(.97);
      transition: all var(--slow);
      border: 1px solid var(--bdr);
    }

    .mhdr,
    .mfoot {
      flex-shrink: 0 !important;
    }

    .mbody {
      flex: 1 1 auto !important;
      min-height: 0 !important;
      overflow-y: auto !important;
      -webkit-overflow-scrolling: touch !important;
    }



    .overlay.open .modal {



      transform: translateY(0) scale(1);



    }



    .modal.wide {
      max-width: 860px;
    }



    .modal.narrow {
      max-width: 400px;
    }







    .mhdr {



      display: flex;



      align-items: center;



      gap: 12px;



      padding: 18px 24px;



      border-bottom: 1px solid var(--bdr);



      background: var(--bg3);



    }







    .mhdr-ico {



      width: 36px;



      height: 36px;



      border-radius: var(--r-md);



      background: var(--brand);



      display: flex;



      align-items: center;



      justify-content: center;



      color: #fff;



      font-size: .9rem;



      flex-shrink: 0;



      box-shadow: var(--sh-brand);



    }







    .mhdr-title {



      font-weight: 800;



      font-size: 1rem;



      color: var(--t1);



    }



    .mhdr-sub {



      font-size: .72rem;



      color: var(--t3);



      margin-top: 2px;



      font-weight: 600;



    }







    .mclose {



      width: 34px;



      height: 34px;



      border-radius: var(--r-md);



      border: 1px solid var(--bdr);



      background: var(--bg);



      display: flex;



      align-items: center;



      justify-content: center;



      cursor: pointer;



      color: var(--t3);



      font-size: .82rem;



      transition: all var(--fast);



      margin-right: auto;



      flex-shrink: 0;



    }



    .mclose:hover {



      background: var(--err-bg);



      color: var(--err);



      border-color: var(--err);



      transform: scale(1.05);



    }







    .mbody {



      padding: 22px 24px;



    }







    .mfoot {
      padding: 12px 20px;
      padding-bottom: max(14px, env(safe-area-inset-bottom, 14px));
      border-top: 1px solid var(--bdr);
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 8px;
      flex-wrap: wrap;
      background: var(--surface-1, var(--bg2, #ffffff));
      flex-shrink: 0 !important;
      position: relative;
      z-index: 100;
      box-shadow: 0 -4px 15px rgba(0, 0, 0, 0.06);
    }







    /* ── Wizard Steps (Interactive Progression) ── */



    .steps {



      display: flex;



      align-items: center;



      margin-bottom: 24px;



    }







    .step {



      flex: 1;



      display: flex;



      flex-direction: column;



      align-items: center;



      gap: 6px;



      position: relative;



    }







    .step:not(:last-child)::after {



      content: '';



      position: absolute;



      top: 12px;



      left: calc(-50% + 12px);



      right: calc(50% + 12px);



      height: 2px;



      background: var(--bdr);



      z-index: 0;



      transition: background var(--norm);



    }



    .step.done:not(:last-child)::after {



      background: var(--brand);



    }







    .step-c {



      width: 24px;



      height: 24px;



      border-radius: 50%;



      border: 2px solid var(--bdr);



      background: var(--bg);



      display: flex;



      align-items: center;



      justify-content: center;



      font-size: .68rem;



      font-weight: 800;



      color: var(--t3);



      z-index: 1;



      transition: all var(--norm);



    }



    .step.active .step-c {



      border-color: var(--brand);



      background: var(--brand);



      color: #fff;



      box-shadow: 0 0 10px var(--brand-glow);



    }



    .step.done .step-c {



      border-color: var(--brand);



      background: var(--brand);



      color: #fff;



    }







    .step-l {



      font-size: .72rem;



      color: var(--t3);



      font-weight: 700;



      text-align: center;



      transition: color var(--norm);



    }



    .step.active .step-l,
    .step.done .step-l {



      color: var(--brand);



    }







    /* ── Clean & Sleek Forms ── */



    .fsec {



      margin-bottom: 20px;



    }







    .fsec-title {



      font-weight: 800;



      font-size: .78rem;



      color: var(--brand);



      letter-spacing: .05em;



      text-transform: uppercase;



      margin-bottom: 12px;



      display: flex;



      align-items: center;



      gap: 8px;



    }







    .fsec-title::after {



      content: '';



      flex: 1;



      height: 1px;



      background: linear-gradient(90deg, var(--brand), transparent);



    }







    .frow {



      display: grid;



      grid-template-columns: 1fr 1fr;



      gap: 12px;



      margin-bottom: 12px;



    }



    .frow.full {



      grid-template-columns: 1fr;



    }







    .fg {



      display: flex;



      flex-direction: column;



      gap: 5px;



    }







    .flbl {



      font-size: .76rem;



      font-weight: 700;



      color: var(--t2);



      display: flex;



      align-items: center;



      gap: 4px;



    }



    .flbl .req {
      color: var(--err);
    }



    .flbl .tip {
      color: var(--t3);
      font-weight: 500;
      font-size: .68rem;
    }







    .fi,
    .fs,
    .fta {



      padding: 9px 13px;



      border: 1.5px solid var(--bdr);



      border-radius: var(--r-md);



      font-family: inherit;



      font-size: .85rem;



      color: var(--t1);



      background: var(--bg);



      outline: none;



      transition: all var(--fast);



      width: 100%;



    }



    .fi:focus,
    .fs:focus,
    .fta:focus {



      border-color: var(--brand);



      box-shadow: 0 0 0 3px var(--brand-glow);



      background: var(--bg);



    }



    .fta {



      resize: vertical;



      min-height: 70px;



    }







    .tgl-row {



      display: flex;



      align-items: center;



      justify-content: space-between;



      padding: 12px 14px;



      background: var(--bg3);



      border: 1px solid var(--bdr);



      border-radius: var(--r-md);



      margin-bottom: 8px;



    }







    .tgl-lbl {



      font-size: .82rem;



      font-weight: 700;



      color: var(--t1);



    }



    .tgl-desc {



      font-size: .68rem;



      color: var(--t3);



      margin-top: 2px;



      font-weight: 500;



    }







    .tgl {



      position: relative;



      width: 40px;



      height: 22px;



      flex-shrink: 0;



    }



    .tgl input {



      opacity: 0;



      width: 0;



      height: 0;



      position: absolute;



    }



    .tgl-s {



      position: absolute;



      inset: 0;



      border-radius: var(--r-full);



      background: var(--t4);



      cursor: pointer;



      transition: all var(--norm);



    }



    .tgl-s::after {



      content: '';



      position: absolute;



      width: 16px;



      height: 16px;



      border-radius: 50%;



      background: #fff;



      top: 3px;



      right: 3px;



      transition: all var(--norm);



      box-shadow: 0 1px 3px rgba(0, 0, 0, .15);



    }



    .tgl input:checked+.tgl-s {



      background: var(--brand);



    }



    .tgl input:checked+.tgl-s::after {



      transform: translateX(-18px);



    }







    /* ── Interactive Questions Workflow ── */



    .qlist {



      display: flex;



      flex-direction: column;



      gap: 12px;



      margin-bottom: 12px;



    }







    .qcard {



      background: var(--bg);



      border: 1.5px solid var(--bdr);



      border-radius: var(--r-lg);



      overflow: hidden;



      transition: all var(--fast);



    }



    .qcard:focus-within {



      border-color: var(--brand);



      box-shadow: 0 0 0 3px var(--brand-glow);



    }







    .qhdr {



      display: flex;



      align-items: center;



      gap: 10px;



      padding: 12px 14px;



      border-bottom: 1px solid var(--bdr);



      background: var(--bg3);



    }

    /* Taranim & Bible Verses Integration Styles */
    .q-bible-btn {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(168, 85, 247, 0.15));
      color: #6366f1;
      border: 1px solid rgba(99, 102, 241, 0.25);
      border-radius: var(--r-md, 10px);
      padding: 6px 10px;
      font-size: 0.78rem;
      font-weight: 700;
      cursor: pointer;
      white-space: nowrap;
      transition: all 0.2s ease;
    }
    .q-bible-btn:hover {
      background: linear-gradient(135deg, #6366f1, #8b5cf6);
      color: #fff;
      border-color: transparent;
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
    }
    .q-bible-btn i {
      font-size: 0.85rem;
    }

    /* Onboarding Connection Banner Modal */
    .taranim-onboard-overlay {
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(15, 23, 42, 0.78);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      z-index: 11000 !important;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
      animation: fadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .taranim-onboard-card {
      background: var(--bg1, #ffffff);
      border: 1px solid var(--bdr, rgba(226, 232, 240, 0.9));
      border-radius: 28px;
      width: 100%;
      max-width: 580px;
      padding: 42px 36px;
      text-align: center;
      box-shadow: 0 30px 60px -12px rgba(0, 0, 0, 0.3);
      position: relative;
      overflow: hidden;
    }
    .taranim-onboard-card::before {
      content: '';
      position: absolute;
      top: -60px; left: 50%;
      transform: translateX(-50%);
      width: 320px; height: 130px;
      background: linear-gradient(135deg, rgba(99, 102, 241, 0.25), rgba(236, 72, 153, 0.25));
      filter: blur(50px);
      border-radius: 50%;
      pointer-events: none;
    }
    .logo-connection-container {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 28px;
      margin: 12px 0 28px 0;
      position: relative;
    }
    .brand-node-logo {
      width: 92px;
      height: 92px;
      border-radius: 24px;
      object-fit: contain;
      padding: 10px;
      box-shadow: 0 12px 28px -6px rgba(0, 0, 0, 0.15), 0 0 0 4px rgba(255, 255, 255, 0.9);
      background: #ffffff;
      transition: transform 0.3s ease;
    }
    .brand-node-logo:hover {
      transform: scale(1.06);
    }
    .connection-node {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      position: relative;
      padding: 0 8px;
    }
    .connection-standalone-icon {
      font-size: 2.6rem;
      color: #6366f1;
      z-index: 2;
      position: relative;
      filter: drop-shadow(0 0 14px rgba(99, 102, 241, 0.8));
      animation: pulseStandaloneIcon 2s infinite ease-in-out;
    }
    @keyframes pulseStandaloneIcon {
      0%, 100% { transform: scale(1); filter: drop-shadow(0 0 14px rgba(99, 102, 241, 0.8)); }
      50% { transform: scale(1.22); filter: drop-shadow(0 0 24px rgba(236, 72, 153, 0.95)); }
    }
    .taranim-onboard-title {
      font-family: 'Baloo Bhaijaan 2', 'Cairo', system-ui, -apple-system, sans-serif !important;
      font-size: 1.65rem;
      font-weight: 800;
      color: var(--t1, #0f172a);
      margin-bottom: 12px;
      line-height: 1.3;
    }
    .taranim-onboard-desc {
      font-family: 'Cairo', 'Baloo Bhaijaan 2', system-ui, sans-serif;
      font-size: 1.02rem;
      color: var(--t2, #475569);
      line-height: 1.7;
      margin-bottom: 28px;
    }
    .taranim-onboard-btn {
      width: 100%;
      padding: 14px 28px;
      background: linear-gradient(135deg, #6366f1, #4f46e5);
      color: #ffffff;
      border: none;
      border-radius: 16px;
      font-family: 'Baloo Bhaijaan 2', 'Cairo', system-ui, sans-serif !important;
      font-size: 1.1rem;
      font-weight: 800;
      cursor: pointer;
      box-shadow: 0 10px 24px -4px rgba(99, 102, 241, 0.45);
      transition: all 0.2s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
    }
    .taranim-onboard-btn:hover {
      background: linear-gradient(135deg, #4f46e5, #4338ca);
      transform: translateY(-2px);
      box-shadow: 0 14px 28px -4px rgba(99, 102, 241, 0.55);
    }

    /* Bible Verse Search Picker Modal */
    .bible-modal-card {
      max-width: 620px;
      width: 100%;
      max-height: 85vh;
      display: flex;
      flex-direction: column;
      padding: 24px !important;
      text-align: right !important;
    }
    .bible-modal-body {
      overflow-y: auto;
      padding: 12px 4px;
      flex: 1;
    }
    .bible-verse-item {
      background: var(--bg2, #f8fafc);
      border: 1px solid var(--bdr, #e2e8f0);
      border-radius: 14px;
      padding: 14px;
      margin-bottom: 10px;
      transition: all 0.2s ease;
      text-align: right;
    }
    .bible-verse-item:hover {
      border-color: #6366f1;
      box-shadow: 0 4px 12px rgba(99, 102, 241, 0.08);
    }
    .bible-verse-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 6px;
    }
    .bible-verse-ref {
      font-weight: 700;
      color: #6366f1;
      font-size: 0.95rem;
    }
    .bible-verse-text {
      font-size: 0.92rem;
      color: var(--t1, #1e293b);
      line-height: 1.6;
      margin-bottom: 10px;
    }
    .bible-verse-actions {
      display: flex;
      gap: 8px;
      justify-content: flex-end;
      flex-wrap: wrap;
    }
    .btn-insert-verse {
      padding: 6px 12px;
      font-size: 0.78rem;
      font-weight: 700;
      border-radius: 8px;
      border: none;
      cursor: pointer;
      transition: all 0.15s ease;
    }
    .btn-insert-full {
      background: #6366f1;
      color: #fff;
    }
    .btn-insert-full:hover {
      background: #4f46e5;
    }
    .btn-insert-ref {
      background: var(--bg3, #f1f5f9);
      color: var(--t2, #475569);
      border: 1px solid var(--bdr, #cbd5e1);
    }
    .btn-insert-ref:hover {
      background: var(--bdr, #cbd5e1);
      color: var(--t1, #0f172a);
    }







    .qnum {



      width: 24px;



      height: 24px;



      border-radius: var(--r-sm);



      background: var(--brand);



      display: flex;



      align-items: center;



      justify-content: center;



      font-size: .68rem;



      font-weight: 800;



      color: #fff;



      flex-shrink: 0;



      box-shadow: var(--sh-brand);



    }







    .qi {



      flex: 1;



      border: none;



      background: transparent;



      outline: none;



      font-family: inherit;



      font-size: .85rem;



      font-weight: 700;



      color: var(--t1);



    }



    .qi::placeholder {



      color: var(--t3);



    }







    .qdeg {



      display: flex;



      align-items: center;



      gap: 5px;



      background: var(--brand-bg);



      border: 1px solid var(--brand-l);



      border-radius: var(--r-sm);



      padding: 4px 10px;



    }



    .qdeg-l {



      font-size: .65rem;



      color: var(--brand);



      font-weight: 800;



      white-space: nowrap;



    }



    .qdeg-i {



      width: 32px;



      border: none;



      background: transparent;



      outline: none;



      font-family: inherit;



      font-size: .78rem;



      font-weight: 800;



      color: var(--brand);



      text-align: center;



    }







    .qrm {



      width: 24px;



      height: 24px;



      border-radius: var(--r-sm);



      border: 1px solid var(--bdr);



      background: var(--bg);



      display: flex;



      align-items: center;



      justify-content: center;



      cursor: pointer;



      color: var(--t3);



      font-size: .68rem;



      transition: all var(--fast);



    }



    .qrm:hover {



      background: var(--err-bg);



      color: var(--err);



      border-color: var(--err);



    }







    .qbody {



      padding: 14px;



    }







    .opts {



      display: flex;



      flex-direction: column;



      gap: 8px;



      margin-bottom: 10px;



    }







    .orow {



      display: flex;



      align-items: center;



      gap: 8px;



    }







    .oradio {



      width: 18px;



      height: 18px;



      border-radius: 50%;



      border: 2px solid var(--bdr);



      flex-shrink: 0;



      cursor: pointer;



      transition: all var(--fast);



      display: flex;



      align-items: center;



      justify-content: center;



      font-size: .55rem;



      font-weight: 800;



    }



    .oradio.ok {



      border-color: var(--ok);



      background: var(--ok);



      color: #fff;



      box-shadow: 0 0 6px var(--ok);



    }



    .oradio:not(.ok):hover {



      border-color: var(--ok);



    }







    .olet {



      width: 20px;



      height: 20px;



      border-radius: var(--r-sm);



      background: var(--t4);



      display: flex;



      align-items: center;



      justify-content: center;



      font-size: .63rem;



      font-weight: 800;



      color: var(--t2);



      flex-shrink: 0;



    }







    .oinp {



      flex: 1;



      padding: 7px 11px;



      border: 1.5px solid var(--bdr);



      border-radius: var(--r-sm);



      font-family: inherit;



      font-size: .78rem;



      color: var(--t1);



      background: var(--bg);



      outline: none;



      transition: all var(--fast);



    }



    .oinp:focus {



      border-color: var(--brand);



      box-shadow: 0 0 0 2px var(--brand-glow);



    }







    .odel {



      width: 22px;



      height: 22px;



      border-radius: var(--r-sm);



      border: 1px solid var(--bdr);



      background: transparent;



      display: flex;



      align-items: center;



      justify-content: center;



      cursor: pointer;



      color: var(--t3);



      font-size: .64rem;



      transition: all var(--fast);



      flex-shrink: 0;



    }



    .odel:hover {



      color: var(--err);



    }







    .add-opt {



      display: inline-flex;



      align-items: center;



      gap: 5px;



      padding: 6px 12px;



      border-radius: var(--r-full);



      border: 1.5px dashed var(--bdr);



      background: transparent;



      font-size: .72rem;



      font-weight: 700;



      color: var(--t3);



      cursor: pointer;



      transition: all var(--fast);



    }



    .add-opt:hover {



      border-color: var(--brand);



      color: var(--brand);



      background: var(--brand-bg);



    }







    .add-q {



      display: flex;



      align-items: center;



      justify-content: center;



      gap: 7px;



      width: 100%;



      padding: 12px;



      border-radius: var(--r-lg);



      border: 2px dashed var(--brand-l);



      background: var(--brand-bg);



      font-size: .83rem;



      font-weight: 800;



      color: var(--brand);



      cursor: pointer;



      transition: all var(--fast);



    }



    .add-q:hover {



      background: var(--brand);



      color: #fff;



      border-color: var(--brand);



      box-shadow: var(--sh-brand);



    }







    .deg-sum {



      display: flex;



      align-items: center;



      justify-content: space-between;



      padding: 10px 14px;



      background: var(--brand-bg);



      border: 1px solid var(--brand-l);



      border-radius: var(--r-md);



      margin-top: 10px;



    }



    .deg-sum-l {



      font-size: .77rem;



      color: var(--brand);



      font-weight: 700;



    }



    .deg-sum-v {



      font-size: 1rem;



      font-weight: 900;



      color: var(--brand);



    }







    /* ── Coupon Tiers & Matrices ── */



    .ctiers {



      display: flex;



      flex-direction: column;



      gap: 8px;



    }







    .ctier {



      display: flex;



      align-items: center;



      gap: 8px;



      flex-wrap: wrap;



      background: var(--bg3);



      border: 1px solid var(--bdr);



      border-radius: var(--r-md);



      padding: 8px 12px;



    }







    .ctier-range {



      display: flex;



      align-items: center;



      gap: 4px;



      font-size: .77rem;



      color: var(--t2);



      font-weight: 700;



    }







    .ctier input[type=number] {



      width: 54px;



      padding: 5px 6px;



      border: 1.5px solid var(--bdr);



      border-radius: var(--r-sm);



      font-family: inherit;



      font-size: .78rem;



      color: var(--t1);



      background: var(--bg);



      outline: none;



      text-align: center;



      transition: all var(--fast);



    }



    .ctier input[type=number]:focus {



      border-color: var(--brand);



      box-shadow: 0 0 0 2px var(--brand-glow);



    }







    .ctier-arr {



      color: var(--t3);



      font-size: .76rem;



    }







    .crew {



      display: flex;



      align-items: center;



      gap: 5px;



      background: var(--cou-bg);



      border: 1px solid rgba(139, 92, 246, .35);



      border-radius: var(--r-sm);



      padding: 5px 10px;



    }



    .crew i {



      color: var(--cou);



    }



    .crew input[type=number] {



      width: 42px;



      background: transparent;



      border: none;



      outline: none;



      font-size: .8rem;



      font-weight: 800;



      color: var(--cou);



      text-align: center;



    }



    .crew-l {



      font-size: .68rem;



      color: var(--cou);



      font-weight: 700;



    }







    .ctier-del {



      width: 24px;



      height: 24px;



      border-radius: var(--r-sm);



      border: 1px solid var(--bdr);



      background: transparent;



      display: flex;



      align-items: center;



      justify-content: center;



      cursor: pointer;



      color: var(--t3);



      font-size: .66rem;



      transition: all var(--fast);



      margin-right: auto;



    }



    .ctier-del:hover {



      color: var(--err);



      border-color: var(--err);



      background: var(--err-bg);



    }







    /* ── Standard Buttons ── */



    .btn {



      display: inline-flex;



      align-items: center;



      gap: 6px;



      padding: 8px 18px;



      border-radius: var(--r-md);



      font-size: .83rem;



      font-weight: 700;



      border: 1.5px solid transparent;



      cursor: pointer;



      transition: all var(--fast);



    }







    .btn-p {



      background: var(--brand);



      color: #fff;



      box-shadow: var(--sh-brand);



    }



    .btn-p:hover {



      background: var(--brand-d);



      transform: translateY(-1px);



      box-shadow: 0 6px 20px var(--brand-glow);



    }







    .btn-g {



      background: var(--bg);



      color: var(--t2);



      border-color: var(--bdr);



    }



    .btn-g:hover {



      background: var(--brand-bg);



      color: var(--brand);



      border-color: var(--brand);



      transform: translateY(-1px);



    }







    .btn-dg {



      background: transparent;



      color: var(--err);



      border-color: var(--err-bg);



    }



    .btn-dg:hover {



      background: var(--err-bg);



      border-color: var(--err);



    }







    .btn:disabled {



      opacity: .45;



      cursor: not-allowed;



      transform: none !important;



      box-shadow: none !important;



    }







    /* ── Detailed Submissions List ── */



    .dq {



      background: var(--bg3);



      border: 1px solid var(--bdr);



      border-radius: var(--r-md);



      margin-bottom: 10px;



      overflow: hidden;



    }







    .dq-hdr {



      display: flex;



      align-items: center;



      gap: 8px;



      padding: 11px 14px;



      border-bottom: 1px solid var(--bdr);



      background: var(--bg);



    }







    .dq-opt {



      display: flex;



      align-items: center;



      gap: 8px;



      padding: 6px 12px;



      border-radius: var(--r-sm);



      border: 1px solid var(--bdr);



      font-size: .77rem;



      color: var(--t2);



      margin-bottom: 5px;



    }



    .dq-opt.ok {



      background: var(--ok-bg);



      border-color: #6ee7b7;



      color: #065f46;



      font-weight: 700;



    }



    .dq-opt:last-child {



      margin-bottom: 0;



    }







    .sub-tbl {



      width: 100%;



      border-collapse: collapse;



    }



    .sub-tbl th {



      padding: 10px 14px;



      text-align: right;



      font-size: .71rem;



      font-weight: 800;



      color: var(--t3);



      background: var(--bg3);



      border-bottom: 1px solid var(--bdr);



    }



    .sub-tbl td {



      padding: 10px 14px;



      font-size: .78rem;



      color: var(--t1);



      border-bottom: 1px solid var(--bdr);



    }



    .sub-tbl tr:last-child td {



      border-bottom: none;



    }



    .sub-tbl tr:hover td {



      background: var(--bg3);



    }







    /* ── Spinner ── */



    .spinner {



      display: inline-block;



      width: 14px;



      height: 14px;



      border: 2px solid rgba(255, 255, 255, .35);



      border-top-color: #fff;



      border-radius: 50%;



      animation: spin .6s linear infinite;



    }



    @keyframes spin {
      to {
        transform: rotate(360deg)
      }
    }







    /* ── Confirm Dialogue ── */



    .conf-body {



      padding: 24px 20px;



      text-align: center;



    }







    .conf-ico {



      width: 54px;



      height: 54px;



      border-radius: 50%;



      background: var(--err-bg);



      display: flex;



      align-items: center;



      justify-content: center;



      margin: 0 auto 14px;



      font-size: 1.4rem;



      color: var(--err);



      box-shadow: 0 4px 10px rgba(239, 68, 68, .15);



    }







    .conf-t {



      font-weight: 800;



      font-size: .95rem;



      color: var(--t1);



      margin-bottom: 5px;



    }



    .conf-s {



      font-size: .8rem;



      color: var(--t3);



      font-weight: 600;



    }







    /* ── Beautiful Live Toasts ── */



    .tc {



      position: fixed;



      bottom: 24px;



      left: 50%;



      transform: translateX(-50%);



      z-index: 9999;



      display: flex;



      flex-direction: column;



      gap: 8px;



      pointer-events: none;



    }







    .toast {



      display: flex;



      align-items: center;



      gap: 8px;



      padding: 12px 20px;



      border-radius: var(--r-full);



      background: var(--t1);



      color: #fff;



      font-size: .81rem;



      font-weight: 700;



      box-shadow: var(--sh-lg);



      opacity: 0;



      transform: translateY(8px);



      transition: all var(--norm);



      pointer-events: auto;



      white-space: nowrap;



    }



    .toast.show {



      opacity: 1;



      transform: translateY(0);



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







    /* ── Responsive Styling ── */



    @media(max-width:860px) {



      .hero {
        grid-template-columns: 1fr;
      }



      .hero-side {
        grid-template-columns: 1fr 1fr;
      }



      .step1-grid {
        grid-template-columns: 1fr;
      }



      .detail-columns {
        grid-template-columns: 1fr;
      }



      .detail-overview {
        grid-template-columns: repeat(2, 1fr);
      }



    }







    @media(max-width:680px) {



      .hero,
      .step1-grid,
      .detail-columns,
      .detail-overview {
        grid-template-columns: 1fr;
      }

      .page {
        margin-top: 15px !important;
        padding-top: 20px !important;
      }



      .hero-side,
      .field-grid,
      .timer-grid,
      .preset-grid,
      .tmeta,
      .tinfo-grid {
        grid-template-columns: 1fr 1fr;
      }



      .stats {
        grid-template-columns: 1fr 1fr;
      }



      .frow {
        grid-template-columns: 1fr;
      }



      .tgrid {
        grid-template-columns: 1fr;
      }



      .topbar {
        padding-left: 12px;
        padding-right: 12px;
        gap: 8px;
      }



      .mbody,
      .mhdr,
      .mfoot {
        padding-left: 16px;
        padding-right: 16px;
      }



      .hero-card {
        padding: 18px;
      }



      .list-shell {
        padding: 14px;
      }



      .steps {
        gap: 6px;
      }



      .step:not(:last-child)::after {
        display: none;
      }



      .scard2-body,
      .panel-body {
        padding: 12px;
      }



      .ans-shell {
        padding: 12px;
      }



      .ans-head {
        padding: 12px;
        align-items: flex-start;
        gap: 10px;
      }



      .ans-avatar {
        width: 42px;
        height: 42px;
        font-size: 1rem;
      }



      .ans-name {
        font-size: .9rem;
      }



      .ans-question {
        padding: 12px;
      }



      .ans-choice {
        font-size: .8rem;
        padding: 8px 10px;
      }







      .sub-tbl thead {
        display: none;
      }

      .sub-tbl,
      .sub-tbl tbody,
      .sub-tbl tr,
      .sub-tbl td {
        display: block;
        width: 100%;
        box-sizing: border-box;
      }

      .sub-tbl tr {
        background: var(--bg-card);
        border: 1px solid var(--bdr);
        border-radius: 12px;
        padding: 12px;
        margin-bottom: 10px;
        display: flex;
        flex-direction: column;
        gap: 10px;
      }

      .sub-tbl td {
        padding: 0 !important;
        border: none !important;
        text-align: right;
        font-size: 0.8rem;
      }

      .sub-tbl td:first-child {
        display: flex;
        align-items: center;
        gap: 8px;
        justify-content: flex-start;
        direction: rtl;
        border-bottom: 1px dashed var(--bdr) !important;
        padding-bottom: 8px !important;
      }

      .sub-tbl td:first-child::before {
        display: none !important;
      }

      .sub-tbl td:not(:first-child):not(:last-child) {
        display: flex;
        justify-content: space-between;
        align-items: center;
        direction: rtl;
      }

      .sub-tbl td:not(:first-child):not(:last-child)[data-label]::before {
        content: attr(data-label) ': ';
        font-weight: 700;
        color: var(--t3);
        font-size: 0.72rem;
      }

      .sub-tbl td:last-child {
        display: flex;
        justify-content: flex-end;
        padding-top: 8px !important;
        border-top: 1px solid var(--bdr) !important;
        margin-top: 4px;
      }







      .hero-title {
        font-size: 1.3rem;
      }



      .hero-sub {
        font-size: .82rem;
      }



      .hero-actions {
        flex-direction: column;
        gap: 7px;
      }



      .hero-actions .btn-create,
      .hero-actions .hero-link {
        width: 100%;
        justify-content: center;
      }



      .tb-title {
        font-size: .88rem;
      }



      .btn-create .btn-text {
        display: none;
      }



      .detail-overview {
        grid-template-columns: 1fr 1fr;
      }



      .grade-sheet-body {
        padding: 12px 14px;
      }



      .grade-sub-card {
        padding: 12px;
      }



      .mfoot {
        flex-wrap: wrap;
      }



      .mfoot .btn {
        flex: 1;
        min-width: 80px;
        justify-content: center;
        min-height: 40px;
      }



    }







    @media(max-width:480px) {



      .hero-side,
      .tmeta,
      .tinfo-grid {
        grid-template-columns: 1fr 1fr;
      }



      .field-grid,
      .timer-grid,
      .preset-grid {
        grid-template-columns: 1fr;
      }



      .stats {
        grid-template-columns: 1fr 1fr;
      }



      .scard-val {
        font-size: 1.3rem;
      }



      .modal {
        margin: 0;
      }



      .overlay {
        padding: 8px;
      }



      .overlay.fullscreen {
        padding: 0;
      }



      .ans-shell {
        padding: 10px;
      }



    }







    @media(max-width:380px) {



      .scard {
        padding: 11px 12px;
      }



      .scard-val {
        font-size: 1.1rem;
      }



      .hero-side,
      .tmeta,
      .tinfo-grid {
        grid-template-columns: 1fr 1fr;
      }



      .field-grid,
      .timer-grid,
      .preset-grid {
        grid-template-columns: 1fr;
      }



      .topbar {
        padding: 8px 10px;
        height: auto;
        flex-wrap: wrap;
      }



      .tb-title {
        order: -1;
        width: 100%;
      }



      .btn-create,
      .tb-back {
        justify-content: center;
      }



      .detail-overview {
        grid-template-columns: 1fr;
      }



    }







    /* ── Question Type Selector ── */



    .q-type-selector {



      display: flex;



      gap: 6px;



      padding: 10px 12px 8px;



      background: var(--bg);



      border-bottom: 1px solid var(--bdr);



    }







    .q-type-btn {



      flex: 1;



      display: flex;



      align-items: center;



      justify-content: center;



      gap: 5px;



      padding: 8px 6px;



      border-radius: var(--r-md);



      border: 1.5px solid var(--bdr);



      background: var(--bg3);



      color: var(--t2);



      font-weight: 700;



      cursor: pointer;



      transition: all var(--fast);



      white-space: nowrap;



    }



    .q-type-btn:hover {



      border-color: var(--brand);



      color: var(--brand);



      background: var(--brand-bg);



    }



    .q-type-btn.active {



      background: var(--brand-bg);



      border-color: var(--brand);



      color: var(--brand);



      box-shadow: 0 0 0 2px var(--brand-glow);



    }



    .q-type-btn.active-tf {



      background: #fef3c7;



      border-color: #f59e0b;



      color: #92400e;



      box-shadow: 0 0 0 2px rgba(245, 158, 11, .15);



    }



    .q-type-btn.active-open {



      background: #f0fdf4;



      border-color: #10b981;



      color: #065f46;



      box-shadow: 0 0 0 2px rgba(16, 185, 129, .12);



    }



    .q-type-btn i {



      font-size: .76rem;



      flex-shrink: 0;



    }







    /* ── True / False Options ── */



    .tf-opts {



      display: flex;



      gap: 10px;



      padding: 10px 12px;



    }







    .tf-btn {



      flex: 1;



      display: flex;



      align-items: center;



      justify-content: center;



      gap: 8px;



      padding: 12px;



      border-radius: var(--r-md);



      border: 2px solid var(--bdr);



      background: var(--bg3);



      font-size: .87rem;



      font-weight: 700;



      cursor: pointer;



      transition: all var(--fast);



      color: var(--t2);



    }



    .tf-btn:hover {



      border-color: var(--brand);



    }



    .tf-btn.tf-true.selected {



      background: #d1fae5;



      border-color: #10b981;



      color: #065f46;



      box-shadow: 0 0 10px rgba(16, 185, 129, .2);



    }



    .tf-btn.tf-false.selected {



      background: #fee2e2;



      border-color: #ef4444;



      color: #991b1b;



      box-shadow: 0 0 10px rgba(239, 68, 68, .18);



    }



    .tf-btn.tf-true i {
      color: #10b981;
    }



    .tf-btn.tf-false i {
      color: #ef4444;
    }







    /* ── Question Media Section ── */



    .q-img-section {



      padding: 10px 14px;



      border-top: 1px solid var(--bdr);



      background: var(--bg);



    }







    .q-img-toggle {



      display: flex;



      align-items: center;



      gap: 7px;



      font-size: .72rem;



      font-weight: 700;



      color: var(--t3);



      cursor: pointer;



      padding: 4px 0;



      transition: color var(--fast);



      background: none;



      border: none;



      width: 100%;



    }



    .q-img-toggle:hover {



      color: var(--brand);



    }







    .q-img-input-wrap {



      display: none;



      margin-top: 8px;



      flex-direction: column;



      gap: 6px;



    }



    .q-img-input-wrap.open {



      display: flex;



    }







    .q-img-url-row {



      display: flex;



      gap: 7px;



      align-items: center;



    }







    .q-img-url-inp {



      flex: 1;



      padding: 7px 11px;



      border: 1.5px solid var(--bdr);



      border-radius: var(--r-sm);



      font-family: inherit;



      font-size: .78rem;



      color: var(--t1);



      background: var(--bg3);



      outline: none;



      transition: border-color var(--fast);



    }



    .q-img-url-inp:focus {



      border-color: var(--brand);



    }







    .q-img-fetch-btn {



      padding: 7px 14px;



      border-radius: var(--r-sm);



      background: var(--brand);



      color: #fff;



      border: none;



      font-size: .73rem;



      font-weight: 700;



      cursor: pointer;



      white-space: nowrap;



      transition: all var(--fast);



    }



    .q-img-fetch-btn:hover {



      background: var(--brand-d);



    }







    .q-img-preview {



      display: none;



      position: relative;



      border-radius: var(--r-md);



      overflow: hidden;



      background: var(--bg3);



      border: 1.5px solid var(--bdr);



      max-height: 220px;



    }



    .q-img-preview img {



      width: 100%;



      max-height: 220px;



      object-fit: contain;



      display: block;



    }



    .q-img-remove {



      position: absolute;



      top: 6px;



      left: 6px;



      width: 26px;



      height: 26px;



      border-radius: 50%;



      background: rgba(239, 68, 68, .9);



      border: none;



      color: #fff;



      font-size: .72rem;



      cursor: pointer;



      display: flex;



      align-items: center;



      justify-content: center;



      transition: background var(--fast);



    }



    .q-img-remove:hover {



      background: var(--err);



    }







    .q-img-status {



      font-size: .7rem;



      color: var(--t3);



      font-weight: 600;



    }



    .q-img-status.ok {
      color: var(--ok);
    }



    .q-img-status.err {
      color: var(--err);
    }







    /* ── Open Question Notification ── */



    .open-q-note {



      display: flex;



      align-items: center;



      gap: 8px;



      margin: 8px 12px 10px;



      padding: 9px 12px;



      background: #f0fdf4;



      border: 1px solid #6ee7b7;



      border-radius: var(--r-sm);



      font-size: .73rem;



      color: #065f46;



      font-weight: 700;



    }







    /* ── Interactive Grading Sheets ── */



    .grade-panel {



      position: fixed;



      inset: 0;



      z-index: 900;



      background: rgba(15, 17, 23, 0.45);



      backdrop-filter: blur(8px);



      -webkit-backdrop-filter: blur(8px);



      display: none;



      align-items: flex-end;



      justify-content: center;



      transition: all var(--norm);



    }



    .grade-panel.open {



      display: flex;



    }







    .grade-sheet {



      background: var(--bg);



      border-radius: 20px 20px 0 0;



      width: 100%;



      max-width: 720px;



      max-height: 90vh;



      overflow-y: auto;



      box-shadow: 0 -8px 32px rgba(0, 0, 0, .16);



      display: flex;



      flex-direction: column;



      border: 1px solid var(--bdr);



    }







    .grade-sheet-hdr {

      padding: 10px 14px;

      border-bottom: 1px solid var(--bdr);

      display: flex;

      align-items: center;

      gap: 8px;

      position: sticky;

      top: 0;

      background: var(--bg);

      z-index: 2;

    }







    .grade-sheet-body {

      padding: 12px 14px;

      flex: 1;

    }







    .grade-sub-card {



      background: var(--bg3);



      border: 1.5px solid var(--bdr);



      border-radius: var(--r-md);



      padding: 16px;



      margin-bottom: 14px;



    }







    .grade-sub-name {



      font-size: .9rem;



      font-weight: 800;



      color: var(--t1);



      margin-bottom: 12px;



      display: flex;



      align-items: center;



      gap: 8px;



      flex-wrap: wrap;



    }







    .grade-q-row {



      margin-bottom: 14px;



    }







    .grade-q-text {



      font-size: .81rem;



      font-weight: 800;



      color: var(--t2);



      margin-bottom: 6px;



    }







    .grade-ans-text {



      background: var(--bg);



      border: 1px solid var(--bdr);



      border-radius: var(--r-sm);



      padding: 10px 14px;



      font-size: .81rem;



      color: var(--t1);



      margin-bottom: 8px;



      white-space: pre-wrap;



      word-break: break-word;



      line-height: 1.6;



    }







    .grade-score-row {



      display: flex;



      align-items: center;



      gap: 8px;



    }







    .grade-score-inp {



      width: 80px;



      padding: 6px 10px;



      border: 1.5px solid var(--bdr);



      border-radius: var(--r-sm);



      font-family: inherit;



      font-size: .87rem;



      font-weight: 800;



      text-align: center;



      transition: all var(--fast);



      background: var(--bg);



      color: var(--t1);



    }



    .grade-score-inp:focus {



      border-color: var(--brand);



      box-shadow: 0 0 0 2px var(--brand-glow);



      outline: none;



    }







    .grade-max-lbl {



      font-size: .74rem;



      color: var(--t3);



      font-weight: 700;



    }







    .grade-save-btn {



      display: block;



      width: 100%;



      margin-top: 14px;



      padding: 12px;



      background: var(--brand);



      color: #fff;



      border: none;



      border-radius: var(--r-full);



      font-size: .9rem;



      font-weight: 800;



      cursor: pointer;



      box-shadow: var(--sh-brand);



      transition: all var(--fast);



    }



    .grade-save-btn:hover {



      background: var(--brand-d);



      transform: translateY(-1px);



      box-shadow: 0 6px 18px var(--brand-glow);



    }







    .pending-badge {



      display: inline-flex;



      align-items: center;



      gap: 4px;



      background: var(--err-bg);



      color: var(--err);



      border-radius: var(--r-full);



      padding: 3px 10px;



      font-size: .7rem;



      font-weight: 800;



    }







    /* ── Grading Panel Notes ── */



    .grade-note-row {



      display: flex;



      align-items: flex-start;



      gap: 8px;



      margin-top: 10px;



      padding: 10px 12px;



      background: #fffbeb;



      border: 1px solid #fde68a;



      border-radius: var(--r-sm);



    }



    .grade-note-row i {



      color: #d97706;



      font-size: .82rem;



      margin-top: 5px;



      flex-shrink: 0;



    }



    .grade-note-row .gn-wrap {



      flex: 1;



      display: flex;



      flex-direction: column;



      gap: 3px;



    }



    .grade-note-row .gn-lbl {



      font-size: .68rem;



      font-weight: 800;



      color: #92400e;



    }







    .grade-note-inp {



      width: 100%;



      padding: 7px 11px;



      border: 1.5px solid #fde68a;



      border-radius: var(--r-sm);



      font-family: inherit;



      font-size: .78rem;



      color: var(--t1);



      background: #fff;



      outline: none;



      resize: vertical;



      min-height: 40px;



      max-height: 120px;



      transition: all var(--fast);



    }



    .grade-note-inp:focus {



      border-color: #f59e0b;



      box-shadow: 0 0 0 2px rgba(245, 158, 11, .15);



    }



    .grade-note-inp::placeholder {



      color: #d4a373;



      font-size: .74rem;



    }







    /* ── Drag & Drop Media Uploads ── */



    .q-img-tabs {



      display: flex;



      gap: 0;



      border-radius: var(--r-sm);



      overflow: hidden;



      border: 1.5px solid var(--bdr);



      background: var(--bg3);



      margin-bottom: 8px;



    }







    .q-img-tab {



      flex: 1;



      padding: 7px 10px;



      border: none;



      background: transparent;



      font-size: .73rem;



      font-weight: 800;



      color: var(--t3);



      cursor: pointer;



      transition: all var(--fast);



      display: flex;



      align-items: center;



      justify-content: center;



      gap: 5px;



    }



    .q-img-tab:first-child {



      border-left: 1px solid var(--bdr);



    }



    .q-img-tab.active {



      background: var(--brand);



      color: #fff;



    }



    .q-img-tab:hover:not(.active) {



      background: var(--brand-bg);



      color: var(--brand);



    }







    .q-img-tab-panel {



      display: none;



    }



    .q-img-tab-panel.active {



      display: flex;



      flex-direction: column;



      gap: 6px;



    }







    .q-img-drop {



      border: 2px dashed var(--bdr);



      border-radius: var(--r-md);



      padding: 20px 14px;



      text-align: center;



      cursor: pointer;



      transition: all var(--fast);



      background: var(--bg3);



    }



    .q-img-drop:hover,
    .q-img-drop.dragover {



      border-color: var(--brand);



      background: var(--brand-bg);



    }



    .q-img-drop i {



      font-size: 1.5rem;



      color: var(--brand);



      display: block;



      margin-bottom: 6px;



    }



    .q-img-drop p {



      font-size: .73rem;



      color: var(--t3);



      font-weight: 700;



      margin: 0;



    }



    .q-img-drop small {



      font-size: .65rem;



      color: var(--t4);



    }







    .q-img-uploading {



      display: none;



      align-items: center;



      gap: 8px;



      font-size: .74rem;



      color: var(--brand);



      font-weight: 800;



    }







    /* ── Cleaner Modern Settings Cards ── */



    .scard2 {



      background: var(--bg);



      border: 1px solid var(--bdr);



      border-radius: var(--r-lg);



      overflow: hidden;



      margin-bottom: 12px;



    }







    .scard2-hdr {



      display: flex;



      align-items: center;



      gap: 8px;



      padding: 12px 18px;



      background: var(--bg3);



      border-bottom: 1px solid var(--bdr);



      font-size: .8rem;



      font-weight: 800;



      color: var(--brand);



      letter-spacing: .03em;



      text-transform: uppercase;



    }



    .scard2-hdr i {



      font-size: .85rem;



    }







    .scard2-body {



      padding: 14px 16px;



    }



    .scard2-body>.fg,
    .scard2-body>.frow,
    .scard2-body>#specRow {



      padding: 12px;



      border-radius: var(--r-lg);



      background: var(--bg3);



      border: 1px solid var(--bdr);



      margin-bottom: 10px;



    }



    .scard2-body>.fg:last-child {



      margin-bottom: 0;



    }







    .sopt-row {



      display: flex;



      align-items: center;



      gap: 12px;



      padding: 12px 14px;



      border-radius: var(--r-md);



      border: 1px solid var(--bdr);



      background: var(--bg3);



      cursor: pointer;



      transition: all var(--fast);



      margin-bottom: 9px;



    }



    .sopt-row:hover {



      border-color: var(--brand);



      background: var(--brand-bg);



      transform: translateY(-1px);



    }







    .sopt-ico {



      width: 36px;



      height: 36px;



      min-width: 36px;



      border-radius: var(--r-sm);



      display: flex;



      align-items: center;



      justify-content: center;



      font-size: .92rem;



      flex-shrink: 0;



    }







    .sopt-txt {



      flex: 1;



      min-width: 0;



    }



    .sopt-lbl {



      font-size: .84rem;



      font-weight: 800;



      color: var(--t1);



    }



    .sopt-desc {



      font-size: .7rem;



      color: var(--t3);



      margin-top: 2px;



      line-height: 1.5;



      font-weight: 500;



    }







    @media(max-width:500px) {



      .sopt-desc {
        display: none;
      }



      .sopt-lbl {
        font-size: .8rem;
      }



      .scard2-body {
        padding: 10px 12px;
      }



      .sopt-row {
        padding: 9px 10px;
        gap: 8px;
      }



    }







    .hint-box {



      display: flex;



      align-items: flex-start;



      gap: 9px;



      padding: 10px 12px;



      border-radius: var(--r-md);



      background: var(--info-bg);



      border: 1px solid #bfdbfe;



      margin-bottom: 11px;



    }



    .hint-box i {



      color: var(--info);



      font-size: .92rem;



      margin-top: 2px;



    }



    .hint-box strong {



      display: block;



      font-size: .78rem;



      color: var(--t1);



      margin-bottom: 2px;



    }



    .hint-box span {



      font-size: .71rem;



      color: var(--t2);



      line-height: 1.7;



    }







    .quick-presets {



      display: flex;



      flex-wrap: wrap;



      gap: 6px;



      margin-top: 9px;



    }







    .quick-btn {



      padding: 6px 12px;



      border-radius: var(--r-full);



      border: 1px solid var(--bdr);



      background: var(--bg);



      color: var(--t2);



      font-size: .73rem;



      font-weight: 700;



      cursor: pointer;



      transition: all var(--fast);



    }



    .quick-btn:hover {



      background: var(--brand-bg);



      color: var(--brand);



      border-color: var(--brand);



    }







    .mini-note {



      font-size: .69rem;



      color: var(--t3);



      margin-top: 5px;



      line-height: 1.7;



      font-weight: 600;



    }







    .date-switch {



      display: grid;



      grid-template-columns: 1fr;



      gap: 10px;



    }







    .time-block.is-disabled {



      opacity: .5;



      pointer-events: none;



    }







    .step1-grid {



      display: grid;



      grid-template-columns: minmax(0, 1.25fr) minmax(280px, .85fr);



      gap: 14px;



      align-items: start;



    }



    @media(max-width:760px) {
      .step1-grid {
        display: flex !important;
        flex-direction: column !important;
        gap: 14px !important;
      }
    }







    .step-stack {



      display: flex;



      flex-direction: column;



      gap: 12px;



    }







    .panel-card {



      background: var(--bg);



      border: 1px solid var(--bdr);



      border-radius: var(--r-xl);



      box-shadow: var(--sh-sm);



      overflow: hidden;



    }







    .panel-head {



      display: flex;



      align-items: flex-start;



      justify-content: space-between;



      gap: 14px;



      padding: 20px 20px 0;



    }



    .panel-title {



      font-size: 1rem;



      font-weight: 900;



      color: var(--t1);



    }



    .panel-sub {



      font-size: .75rem;



      line-height: 1.8;



      color: var(--t3);



      margin-top: 4px;



      font-weight: 600;



    }







    .panel-body {



      padding: 16px 20px 20px;



    }







    .panel-mark {



      width: 42px;



      height: 42px;



      border-radius: 14px;



      background: var(--brand);



      display: flex;



      align-items: center;



      justify-content: center;



      color: #fff;



      font-size: .95rem;



      box-shadow: var(--sh-brand);



      flex-shrink: 0;



    }







    .field-grid {



      display: grid;



      grid-template-columns: 1fr 1fr;



      gap: 11px;



    }



    .field-grid.single {



      grid-template-columns: 1fr;



    }







    .field-card {



      padding: 12px;



      border-radius: var(--r-lg);



      border: 1px solid var(--bdr);



      background: var(--bg3);



    }



    .field-card.full {



      grid-column: 1/-1;



    }



    .field-card .flbl {



      margin-bottom: 6px;



    }







    .field-note {



      font-size: .7rem;



      color: var(--t3);



      line-height: 1.7;



      margin-top: 6px;



    }







    .spec-list-card {



      max-height: 180px;



      overflow-y: auto;



      border: 1.5px solid var(--bdr);



      border-radius: var(--r-lg);



      padding: 11px;



      display: flex;



      flex-direction: column;



      gap: 7px;



      background: var(--bg);



      font-size: .81rem;



      color: var(--t3);



      font-weight: 700;



    }







    .setting-group {



      display: flex;



      flex-direction: column;



      gap: 9px;



    }







    .setting-title {



      font-size: .78rem;



      font-weight: 800;



      color: var(--brand);



      letter-spacing: .03em;



      text-transform: uppercase;



      margin-bottom: 2px;



    }







    .setting-item {



      display: flex;



      align-items: flex-start;



      gap: 12px;



      padding: 14px;



      border-radius: var(--r-lg);



      border: 1px solid var(--bdr);



      background: var(--bg3);



      transition: all var(--fast);



      cursor: pointer;



    }



    .setting-item:hover {



      border-color: var(--brand);



      background: var(--brand-bg);



      transform: translateY(-1px);



    }



    .setting-item.primary {



      background: var(--brand-bg);



      border-color: var(--brand);



    }







    .setting-icon {



      width: 38px;



      height: 38px;



      border-radius: 12px;



      display: flex;



      align-items: center;



      justify-content: center;



      font-size: .92rem;



      flex-shrink: 0;



    }







    .setting-copy {



      flex: 1;



    }



    .setting-copy strong {



      display: block;



      font-size: .84rem;



      color: var(--t1);



      margin-bottom: 3px;



    }



    .setting-copy span {



      display: block;



      font-size: .72rem;



      color: var(--t3);



      line-height: 1.7;



    }



    .setting-copy small {



      display: inline-flex;



      align-items: center;



      gap: 5px;



      margin-top: 6px;



      padding: 4px 10px;



      border-radius: var(--r-full);



      background: var(--bg);



      border: 1px solid var(--bdr);



      font-size: .65rem;



      font-weight: 700;



      color: var(--t2);



    }







    .preset-grid {



      display: grid;



      grid-template-columns: repeat(5, minmax(0, 1fr));



      gap: 8px;



      margin-top: 9px;



    }







    .preset-btn {



      padding: 8px;



      border-radius: var(--r-md);



      border: 1px solid var(--bdr);



      background: var(--bg);



      font-size: .74rem;



      font-weight: 800;



      color: var(--t2);



      cursor: pointer;



      transition: all var(--fast);



    }



    .preset-btn:hover {



      background: var(--brand-bg);



      border-color: var(--brand);



      color: var(--brand);



    }







    .timer-grid {



      display: grid;



      grid-template-columns: 1fr 1fr;



      gap: 10px;



      margin-top: 9px;



    }







    .coupon-summary {



      display: flex;



      align-items: center;



      gap: 12px;



      padding: 13px 15px;



      border-radius: var(--r-lg);



      background: var(--cou-bg);



      border: 1px solid rgba(139, 92, 246, .3);



      margin-bottom: 13px;



    }



    .coupon-summary i {



      width: 36px;



      height: 36px;



      border-radius: 12px;



      display: flex;



      align-items: center;



      justify-content: center;



      background: #fff;



      color: var(--cou);



      box-shadow: 0 4px 10px rgba(139, 92, 246, .15);



    }



    .coupon-summary strong {



      display: block;



      font-size: .87rem;



      color: var(--t1);



    }



    .coupon-summary span {



      display: block;



      font-size: .71rem;



      color: var(--t3);



      margin-top: 2px;



      font-weight: 600;



    }







    .detail-shell {



      display: flex;



      flex-direction: column;



      gap: 15px;



    }







    .detail-overview {



      display: grid;



      grid-template-columns: repeat(4, minmax(0, 1fr));



      gap: 10px;



    }







    .detail-stat {



      padding: 14px;



      border-radius: var(--r-lg);



      background: var(--bg3);



      border: 1px solid var(--bdr);



    }



    .detail-stat-label {



      font-size: .71rem;



      color: var(--t3);



      margin-bottom: 6px;



      font-weight: 600;



    }



    .detail-stat-value {



      font-size: 1.05rem;



      font-weight: 900;



      color: var(--t1);



    }







    .detail-banner {



      display: flex;



      flex-wrap: wrap;



      align-items: center;



      gap: 8px;



      padding: 13px 15px;



      border-radius: var(--r-lg);



      background: var(--brand-bg);



      border: 1px solid var(--brand-l);



    }







    .coupon-chips {



      display: flex;



      flex-wrap: wrap;



      gap: 8px;



    }







    .detail-columns {



      display: grid;



      grid-template-columns: 1fr 1fr;



      gap: 14px;



    }







    .detail-card {



      background: var(--bg);



      border: 1px solid var(--bdr);



      border-radius: var(--r-lg);



      box-shadow: var(--sh-sm);



      overflow: hidden;



    }







    .detail-card-head {



      display: flex;



      align-items: center;



      justify-content: space-between;



      gap: 10px;



      padding: 14px 16px;



      border-bottom: 1px solid var(--bdr);



      background: var(--bg3);



    }



    .detail-card-title {



      font-size: .83rem;



      font-weight: 800;



      color: var(--t1);



    }







    .detail-list {



      padding: 8px 16px 14px;



    }







    .detail-person {



      display: flex;



      align-items: center;



      gap: 10px;



      padding: 10px 0;



      border-bottom: 1px solid var(--bdr);



    }



    .detail-person:last-child {



      border-bottom: none;



      padding-bottom: 0;



    }







    .detail-person-badge {



      display: inline-flex;



      align-items: center;



      gap: 5px;



      padding: 3px 10px;



      border-radius: var(--r-full);



      background: var(--brand-bg);



      color: var(--brand);



      font-size: .66rem;



      font-weight: 800;



    }







    .detail-empty {



      padding: 16px 0;



      font-size: .77rem;



      color: var(--t3);



      font-weight: 600;



    }







    /* ── Premium Student Submissions Review Sheet ── */



    .ans-shell {

      padding: 20px;

      max-height: min(78vh, 760px);

      overflow-y: auto;

      background: var(--bg);

    }







    .ans-head {

      display: flex;

      align-items: center;

      gap: 14px;

      padding: 16px 18px;

      border-radius: var(--r-lg);

      background: var(--bg2);

      border: 1px solid var(--bdr);

      margin-bottom: 14px;

    }







    .ans-avatar {



      width: 50px;



      height: 50px;



      border-radius: 16px;



      background: var(--brand-bg);



      color: var(--brand);



      display: flex;



      align-items: center;



      justify-content: center;



      font-size: 1.25rem;



      flex-shrink: 0;



      border: 1px solid var(--brand-l);



      box-shadow: 0 4px 10px var(--brand-glow);



    }







    .ans-name {



      font-size: 1rem;



      font-weight: 900;



      color: var(--t1);



      line-height: 1.2;



    }



    .ans-sub {



      font-size: .75rem;



      color: var(--t3);



      margin-top: 4px;



      font-weight: 600;



    }







    .ans-question {

      margin-bottom: 14px;

      padding: 16px;

      border: 1px solid var(--bdr);

      border-radius: var(--r-lg);

      background: var(--bg2);

    }







    .ans-qhead {



      display: flex;



      gap: 10px;



      align-items: flex-start;



      margin-bottom: 12px;



    }







    .ans-qnum {



      width: 28px;



      height: 28px;



      min-width: 28px;



      border-radius: 9px;



      background: var(--brand-bg);



      color: var(--brand);



      display: flex;



      align-items: center;



      justify-content: center;



      font-size: .78rem;



      font-weight: 900;



      flex-shrink: 0;



      border: 1px solid var(--brand-l);



    }







    .ans-qtext {



      font-weight: 800;



      color: var(--t1);



      line-height: 1.6;



      flex: 1;



      word-break: break-word;



    }







    .ans-open {



      padding: 12px 14px;



      border-radius: var(--r-md);



      background: var(--bg3);



      border: 1px solid var(--bdr);



    }



    .ans-open-label {



      font-size: .69rem;



      color: var(--t3);



      margin-bottom: 5px;



      font-weight: 800;



    }



    .ans-open-text {



      color: var(--t2);



      font-size: .88rem;



      white-space: pre-wrap;



      line-height: 1.7;



      word-break: break-word;



    }







    .ans-choice {



      display: flex;



      align-items: center;



      gap: 10px;



      padding: 10px 14px;



      border-radius: var(--r-md);



      border: 1.5px solid var(--bdr);



      background: var(--bg);



      color: var(--t2);



      font-size: .84rem;



      transition: all var(--fast);



    }



    .ans-choice+.ans-choice {



      margin-top: 8px;



    }



    .ans-choice.correct {



      border-color: #86efac;



      background: var(--ok-bg);



      color: #047857;



      font-weight: 700;



    }



    .ans-choice.wrong {



      border-color: #fca5a5;



      background: var(--err-bg);



      color: #dc2626;



      font-weight: 700;



    }







    .ans-choice-letter {



      width: 22px;



      height: 22px;



      min-width: 22px;



      border-radius: 7px;



      background: rgba(148, 163, 184, .14);



      display: flex;



      align-items: center;



      justify-content: center;



      font-weight: 900;



      font-size: .7rem;



      flex-shrink: 0;



    }



    .ans-choice-icon {



      margin-right: auto;



      font-size: .92rem;



      flex-shrink: 0;



    }

    /* Subpage Header layout styling */
    .tasks-subpage-header {
      display: flex;
    }

    body {
      background: var(--bg2) !important;
    }

    .page {
      padding-top: 20px !important;
      margin-top: 0 !important;
      max-width: 1200px !important;
    }

    @media (max-width: 992px) {
      .tasks-subpage-header {
        flex-direction: column;
        align-items: stretch !important;
        gap: 12px !important;
      }

      .header-stats-actions-wrap {
        width: 100% !important;
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        direction: rtl !important;
      }

      .iframe-stats-row {
        justify-content: flex-start !important;
      }

      .header-actions-box {
        margin-right: auto !important;
      }
    }

    /* Wide Modal Fullscreen rules */
    .overlay.fullscreen {
      height: 100vh !important;
      height: 100dvh !important;
      max-height: 100vh !important;
      max-height: 100dvh !important;
    }

    .overlay.fullscreen .modal.wide {
      min-height: 0 !important;
    }

    /* Fullscreen Grading Panel Overrides */
    .grade-panel {
      position: fixed;
      inset: 0;
      height: 100vh !important;
      height: 100dvh !important;
      z-index: 3000 !important;
      background: var(--bg3) !important;
      align-items: stretch !important;
      justify-content: stretch !important;
    }

    .grade-sheet {
      width: 100% !important;
      height: 100vh !important;
      height: 100dvh !important;
      max-width: 100% !important;
      max-height: 100vh !important;
      max-height: 100dvh !important;
      border-radius: 0 !important;
      margin: 0 !important;
      display: flex !important;
      flex-direction: column !important;
      background: var(--bg3) !important;
      box-shadow: none !important;
      border: none !important;
      min-height: 100vh !important;
      min-height: 100dvh !important;
    }

    .grade-sheet-body {
      flex: 1 !important;
      overflow-y: auto !important;
      padding: 0 !important;
      display: flex !important;
      flex-direction: column !important;
    }

    .grade-sub-card {
      flex: 1 !important;
      display: flex !important;
      flex-direction: column !important;
      height: 100% !important;
    }

    /* Responsive Unified Details Info Board & Milestones CSS */
    .detail-meta-pills {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      font-size: 0.75rem;
      color: var(--t3);
      border-bottom: 1px dashed var(--bdr);
      padding-bottom: 12px;
      justify-content: flex-start;
      align-items: center;
      direction: rtl;
      text-align: right;
    }

    .detail-metrics-row {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 16px;
      direction: rtl;
    }

    .detail-metric-col {
      text-align: right;
    }

    .detail-metric-col:not(:first-child) {
      border-right: 1px solid var(--bdr);
      padding-right: 16px;
    }

    .detail-milestones-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
      gap: 10px;
    }

    .detail-milestone-card {
      background: var(--bg-card);
      border: 1.5px solid var(--brand-l);
      border-radius: 10px;
      padding: 10px;
      text-align: center;
      display: flex;
      flex-direction: column;
      gap: 4px;
      transition: transform 0.2s;
    }

    @media (max-width: 600px) {
      .detail-meta-pills {
        gap: 6px;
        font-size: 0.68rem;
        padding-bottom: 8px;
      }

      .detail-metrics-row {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
      }

      .detail-metric-col {
        border-right: none !important;
        padding-right: 0 !important;
        background: var(--bg-hover);
        padding: 8px 10px !important;
        border-radius: 8px;
        border: 1px solid var(--bdr);
      }

      .detail-milestones-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
      }

      .detail-milestone-card {
        padding: 8px;
        gap: 2px;
        border-radius: 8px;
      }
    }

    /* Skeleton Loading Screens styles */
    .skeleton-card {
      background: var(--bg-card, #fff);
      border: 1px solid var(--bdr, rgba(91, 108, 245, .12));
      border-radius: var(--r-lg, 14px);
      padding: 20px;
      height: 160px;
      display: flex;
      flex-direction: column;
      gap: 12px;
      position: relative;
      overflow: hidden;
      box-shadow: var(--sh-sm);
    }

    .skeleton-card::after {
      content: "";
      position: absolute;
      top: 0;
      right: 0;
      bottom: 0;
      left: 0;
      background: linear-gradient(90deg, transparent, rgba(91, 108, 245, 0.08), transparent);
      transform: translateX(-100%);
      animation: shimmer 1.6s infinite;
    }

    @keyframes shimmer {
      100% {
        transform: translateX(100%);
      }
    }

    .skeleton-line {
      background: var(--bg2, #f3f4f6);
      border-radius: 6px;
    }

    .skeleton-title {
      height: 20px;
      width: 60%;
    }

    .skeleton-text {
      height: 12px;
      width: 85%;
    }

    .skeleton-footer {
      margin-top: auto;
      display: flex;
      gap: 10px;
    }

    .skeleton-foot-item {
      height: 22px;
      width: 25%;
      border-radius: 6px;
    }

    /* Skeleton Rows for Tables / Panels */
    .skeleton-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 12px 16px;
      border-bottom: 1px solid var(--bdr);
      background: var(--bg-card);
      position: relative;
      overflow: hidden;
    }

    .skeleton-row::after {
      content: "";
      position: absolute;
      top: 0;
      right: 0;
      bottom: 0;
      left: 0;
      background: linear-gradient(90deg, transparent, rgba(91, 108, 245, 0.08), transparent);
      transform: translateX(-100%);
      animation: shimmer 1.6s infinite;
    }

    /* Ensure all overview overlay contents inherit the premium fonts */
    #overviewOv,
    #overviewOv * {
      font-family: 'Baloo Bhaijaan 2', 'Cairo', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important;
    }

    /* Overlapping student avatar groups styling */
    .uncle-avatar-wrap {
      position: relative;
      margin-left: -10px;
      transition: all 0.2s ease;
      z-index: 1;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      vertical-align: middle;
    }

    .uncle-avatar-wrap:hover {
      z-index: 100 !important;
      transform: scale(1.18) translateY(-3px);
    }

    .uncle-tooltip {
      position: absolute;
      bottom: calc(100% + 4px);
      left: 50%;
      transform: translateX(-50%) translateY(4px);
      background: var(--t1);
      color: var(--bg);
      padding: 3px 8px;
      border-radius: var(--r-full);
      font-size: .68rem;
      font-weight: 700;
      white-space: nowrap;
      pointer-events: none;
      opacity: 0;
      transition: all 0.2s ease;
      z-index: 999;
      box-shadow: var(--shadow-sm);
    }

    .uncle-avatar-wrap:hover .uncle-tooltip {
      opacity: 1;
      transform: translateX(-50%) translateY(0);
    }
  </style>



  <script src="/js/og-meta.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
  <script src="/js/search_intelligent.js"></script>
</head>



<body>















  <!-- ══ PAGE ══════════════════════════════════════════════════════ -->



  <main class="page">

    <header class="tasks-subpage-header"
      style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; gap: 16px; border-bottom: 1px solid var(--bdr); padding-bottom: 16px; direction: rtl; flex-wrap: wrap;">
      <div style="display: flex; align-items: center; gap: 12px;">
        <a href="<?php echo htmlspecialchars($dashBack); ?>"
          style="text-decoration: none !important; background: var(--bg-card); border: 1px solid var(--bdr); border-radius: var(--r-md); width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--t1); transition: all 0.2s;"
          onmouseover="this.style.background='var(--brand-bg)'; this.style.color='var(--brand)';"
          onmouseout="this.style.background='var(--bg-card)'; this.style.color='var(--t1)';"><i
            class="fas fa-arrow-right"></i></a>
        <div>
          <div
            style="font-size: 1.25rem; font-weight: 800; color: var(--t1); font-family: 'Cairo', 'Baloo Bhaijaan 2', sans-serif;">
            التاسكات والاختبارات</div>
          <?php if ($activeClass): ?>
            <div
              style="font-size: 0.75rem; font-weight: 700; color: var(--brand); background: var(--brand-bg); padding: 2px 8px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px; margin-top: 4px; border: 1px solid var(--brand-l);">
              <i class="fas fa-users" style="font-size: 0.65rem;"></i> <?php echo htmlspecialchars($activeClass); ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Stats + Actions Container (Stats Right, Actions Left) -->
      <div class="header-stats-actions-wrap"
        style="display: flex; align-items: center; justify-content: space-between; gap: 12px; direction: rtl;">
        <!-- Stats Row (Right) -->
        <div class="iframe-stats-row" style="display: flex; gap: 8px; align-items: center;">
          <div
            style="background: var(--bg-card); border: 1px solid var(--bdr); border-radius: var(--r-md); padding: 5px 12px; text-align: center; min-width: 60px;">
            <div style="font-size: 0.65rem; color: var(--t3); font-weight: bold; margin-bottom: 2px;">إجمالي التاسكات
            </div>
            <div style="font-size: 1.05rem; font-weight: 800; color: var(--t1);" id="stTotalIframe">—</div>
          </div>

          <div
            style="background: var(--bg-card); border: 1px solid var(--bdr); border-radius: var(--r-md); padding: 5px 12px; text-align: center; min-width: 55px;">
            <div style="font-size: 0.65rem; color: var(--t3); font-weight: bold; margin-bottom: 2px;">Draft</div>
            <div style="font-size: 1.05rem; font-weight: 800; color: var(--warn);" id="stDraftIframe">—</div>
          </div>
        </div>

        <!-- Actions (Left) -->
        <div class="header-actions-box" style="display: flex; gap: 8px; align-items: center;">
          <button onclick="openTasksOverviewModal()" title="تصدير نظرة عامة"
            style="background:var(--brand-bg); color:var(--brand); border:1.5px solid var(--brand-l); font-weight:bold; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; width: 38px; height: 38px; border-radius: var(--r-md); transition: all 0.2s; flex-shrink: 0;"
            onmouseover="this.style.background='var(--brand)'; this.style.color='#fff';"
            onmouseout="this.style.background='var(--brand-bg)'; this.style.color='var(--brand)';"><i
              class="fas fa-file-export" style="font-size:1rem;"></i></button>
          <button class="btn-create" onclick="openCreate()"
            style="padding: 0 16px; font-size: 0.85rem; font-weight: 800; height: 38px; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; flex-shrink: 0;"><i
              class="fas fa-plus"></i> تاسك جديد</button>
        </div>
      </div>
    </header>











    <section class="list-shell">



      <div class="sec-hdr">



        <div class="sec-title">
          <div class="sec-dot"></div>التاسكات
        </div>



        <div class="ftabs" id="filterTabsContainer"></div>



      </div>







      <div class="tgrid" id="tGrid">



        <div class="skeleton-card">
          <div class="skeleton-line skeleton-title"></div>
          <div class="skeleton-line skeleton-text" style="width: 40%;"></div>
          <div class="skeleton-line skeleton-text" style="width: 80%;"></div>
          <div class="skeleton-footer">
            <div class="skeleton-line skeleton-foot-item"></div>
            <div class="skeleton-line skeleton-foot-item"></div>
          </div>
        </div>
        <div class="skeleton-card">
          <div class="skeleton-line skeleton-title"></div>
          <div class="skeleton-line skeleton-text" style="width: 30%;"></div>
          <div class="skeleton-line skeleton-text" style="width: 70%;"></div>
          <div class="skeleton-footer">
            <div class="skeleton-line skeleton-foot-item"></div>
            <div class="skeleton-line skeleton-foot-item"></div>
          </div>
        </div>
        <div class="skeleton-card">
          <div class="skeleton-line skeleton-title"></div>
          <div class="skeleton-line skeleton-text" style="width: 50%;"></div>
          <div class="skeleton-line skeleton-text" style="width: 90%;"></div>
          <div class="skeleton-footer">
            <div class="skeleton-line skeleton-foot-item"></div>
            <div class="skeleton-line skeleton-foot-item"></div>
          </div>
        </div>



      </div>



    </section>



  </main>







  <!-- ══ CREATE / EDIT MODAL ══════════════════════════════════════ -->

  <div class="overlay fullscreen" id="createOv">
    <div class="modal">
      <div class="mhdr">
        <div class="mhdr-ico"><i class="fas fa-pen-nib"></i></div>
        <div>
          <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <div class="mhdr-title" id="createTitle">إنشاء تاسك جديد</div>
            <span id="draftSaveBadge" style="font-size:0.75rem; font-weight:700; color:#059669; background:rgba(5,150,105,0.1); border:1px solid rgba(5,150,105,0.2); padding:3px 10px; border-radius:12px; display:none; align-items:center; gap:5px;"><i class="fas fa-cloud-upload-alt"></i> <span id="draftSaveBadgeText">حفظ تلقائي</span></span>
          </div>
          <div class="mhdr-sub">اختبار MCQ مع مكافآت كوبونات</div>
        </div>
        <div style="margin-right:auto; display:flex; align-items:center; gap:8px;">
          <button type="button" id="clearDraftBtn" onclick="confirmClearDraft()" style="display:none; align-items:center; justify-content:center; gap:6px; background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.2); color:#ef4444; font-size:0.78rem; font-weight:700; padding:5px 11px; border-radius:8px; cursor:pointer;"><i class="fas fa-trash-alt" style="font-size:0.75rem; line-height:1;"></i><span>مسح</span></button>
          <div class="mclose" onclick="closeCreate()"><i class="fas fa-times"></i></div>
        </div>
      </div>

      <div style="padding:16px 22px 0;">
        <div class="steps" id="stepBar">
          <div class="step active" id="sd1">
            <div class="step-c">١</div>
            <div class="step-l">الإعدادات</div>
          </div>
          <div class="step" id="sd2">
            <div class="step-c">٢</div>
            <div class="step-l">الأسئلة</div>
          </div>
          <div class="step" id="sd3">
            <div class="step-c">٣</div>
            <div class="step-l">الكوبونات</div>
          </div>
        </div>
      </div>

      <div class="mbody">
        <!-- Step 1 — Settings -->
        <div id="sp1">
          <div class="step1-grid">
            <!-- Column 1: Info Card + Timing Card -->
            <div class="step-stack">

              <!-- Card 1: Info -->
              <div class="scard2">
                <div class="scard2-hdr"><i class="fas fa-pen"></i> معلومات التاسك</div>
                <div class="scard2-body">
                  <div class="fg" style="margin-bottom:12px;">
                    <label class="flbl">العنوان <span class="req">*</span></label>
                    <input id="fTitle" class="fi" type="text" placeholder="مثال: اختبار سفر التكوين"
                      style="font-size:.95rem;">
                  </div>

                  <div class="fg" style="margin-bottom:12px;">
                    <label class="flbl">تصنيف التاسك</label>
                    <div id="activeClassificationsContainer"
                      style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:8px;"></div>
                    <div style="display:flex; gap:8px;">
                      <select id="classificationSelector" onchange="handleClassificationSelect(this)"
                        style="flex:1; padding:8px 12px; border:1px solid var(--bdr); border-radius:8px; background:var(--bg); color:var(--t1); font-size:0.87rem; outline:none; cursor:pointer;">
                        <option value="" disabled selected>— اختر تصنيفاً لتحديده —</option>
                      </select>
                      <button type="button" class="btn btn-p" onclick="addNewClassificationPrompt()"
                        style="white-space: nowrap; flex-shrink: 0; padding: 0 12px; height: 38px; font-size: 0.85rem; font-weight: bold; display: inline-flex; align-items: center; gap: 6px; border-radius: var(--r-md);"><i
                          class="fas fa-plus"></i> تصنيف جديد</button>
                    </div>
                  </div>

                  <div class="fg" style="margin-bottom:12px;">
                    <label class="flbl">الفصل / المراحل المستهدفة <span class="req">*</span></label>
                    <div id="fClassContainer"
                      style="max-height:160px; overflow-y:auto; border:1.5px solid var(--bdr); border-radius:var(--r-md); padding:10px 12px; background:var(--bg3); display:flex; flex-direction:column; gap:6px;">
                      <!-- JavaScript populates checkboxes -->
                    </div>
                  </div>

                  <div class="fg" style="margin-bottom:12px;">
                    <label class="flbl">تعيين لـ</label>
                    <select id="fAssign" class="fs" onchange="onAssignChange()">
                      <option value="all">جميع أطفال الفصل</option>
                      <option value="specific">أطفال محددون</option>
                    </select>
                  </div>

                  <div id="specRow" style="display:none;" class="fg" style="margin-bottom:12px;">
                    <label class="flbl">اختر الأطفال</label>
                    <div id="specList"
                      style="max-height:140px;overflow-y:auto;border:1.5px solid var(--bdr);border-radius:var(--r-md);padding:10px 12px;display:flex;flex-direction:column;gap:7px;background:var(--bg);font-size:.82rem;color:var(--t3);">
                      اختر الفصل أولاً</div>
                  </div>

                  <div class="fg">
                    <label class="flbl">وصف / تعليمات <span
                        style="color:var(--t3);font-weight:400;">(اختياري)</span></label>
                    <textarea id="fDesc" class="fta" placeholder="تعليمات للطفل…"></textarea>
                  </div>
                </div>
              </div>

              <!-- Card 2: Timing -->
              <div class="scard2"
                style="border-radius:16px; border:1px solid var(--border); overflow:hidden; background:var(--surface-1);">
                <div class="scard2-hdr"
                  style="padding:14px 18px; font-weight:800; display:flex; align-items:center; gap:8px; border-bottom:1px solid var(--border); background:var(--surface-2);">
                  <i class="fas fa-clock" style="color:var(--brand);"></i> التوقيت ومواعيد التاسك
                </div>

                <div class="scard2-body" style="padding:18px;">
                  <input type="checkbox" id="fNoDeadline" style="display:none;" onchange="updateTimingSummaryBadge()">
                  <input type="checkbox" id="fEndDateMode" style="display:none;">
                  <input type="date" id="fEndDateOnly" style="display:none;">

                  <div
                    style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-bottom:16px;">
                    <div class="fg">
                      <label class="flbl"
                        style="font-weight:700; margin-bottom:6px; display:flex; align-items:center; gap:6px;">
                        <i class="far fa-calendar-alt" style="color:var(--brand);"></i> تاريخ ووقت البداية <span
                          class="req">*</span>
                      </label>
                      <input id="fStart" type="datetime-local" class="fi" onchange="updateTimingSummaryBadge()"
                        style="font-family:inherit; border-radius:10px;">
                    </div>

                    <div class="fg">
                      <label class="flbl"
                        style="font-weight:700; margin-bottom:6px; display:flex; align-items:center; justify-content:space-between;">
                        <span style="display:flex; align-items:center; gap:6px;">
                          <i class="far fa-calendar-check" style="color:#ef4444;"></i> آخر موعد للإجابة <span
                            style="color:var(--t3); font-weight:400;">(اختياري)</span>
                        </span>
                      </label>
                      <input id="fEnd" type="datetime-local" class="fi" onchange="onEndDateTimeChange()"
                        style="font-family:inherit; border-radius:10px;">
                    </div>
                  </div>

                  <div style="margin-bottom:16px;">
                    <div
                      style="font-size:0.76rem; font-weight:700; color:var(--t3); margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                      <i class="fas fa-bolt" style="color:#f59e0b;"></i> اختصارات سريعة لموعد الإغلاق:
                    </div>
                    <div style="display:flex; flex-wrap:wrap; gap:8px;">
                      <button type="button" class="quick-preset-chip" onclick="applySimplePreset(0)"
                        style="padding:6px 12px; border-radius:20px; border:1px solid var(--border); background:var(--surface-2); font-size:0.78rem; font-weight:700; cursor:pointer; color:var(--t1); transition:all 0.2s;">اليوم
                        (11:59 م)</button>
                      <button type="button" class="quick-preset-chip" onclick="applySimplePreset(1)"
                        style="padding:6px 12px; border-radius:20px; border:1px solid var(--border); background:var(--surface-2); font-size:0.78rem; font-weight:700; cursor:pointer; color:var(--t1); transition:all 0.2s;">غداً</button>
                      <button type="button" class="quick-preset-chip" onclick="applySimplePreset(3)"
                        style="padding:6px 12px; border-radius:20px; border:1px solid var(--border); background:var(--surface-2); font-size:0.78rem; font-weight:700; cursor:pointer; color:var(--t1); transition:all 0.2s;">بعد
                        3 أيام</button>
                      <button type="button" class="quick-preset-chip" onclick="applySimplePreset(7)"
                        style="padding:6px 12px; border-radius:20px; border:1px solid var(--border); background:var(--surface-2); font-size:0.78rem; font-weight:700; cursor:pointer; color:var(--t1); transition:all 0.2s;">بعد
                        أسبوع</button>
                      <button type="button" class="quick-preset-chip" onclick="applySimplePreset(14)"
                        style="padding:6px 12px; border-radius:20px; border:1px solid var(--border); background:var(--surface-2); font-size:0.78rem; font-weight:700; cursor:pointer; color:var(--t1); transition:all 0.2s;">بعد
                        أسبوعين</button>
                      <button type="button" class="quick-preset-chip" onclick="setNoDeadlineMode()"
                        style="padding:6px 12px; border-radius:20px; border:1px solid rgba(8,145,178,0.3); background:rgba(8,145,178,0.08); font-size:0.78rem; font-weight:800; cursor:pointer; color:#0891b2; transition:all 0.2s;">♾️
                        بدون آخر موعد</button>
                    </div>
                  </div>

                  <div id="timingSummaryPill"
                    style="padding:12px 14px; border-radius:12px; background:rgba(99,102,241,0.08); border:1px solid rgba(99,102,241,0.2); font-size:0.83rem; font-weight:700; color:#4f46e5; display:flex; align-items:center; gap:10px; margin-bottom:16px;">
                    <i class="fas fa-calendar-check" style="font-size:1.1rem; flex-shrink:0;"></i>
                    <span id="timingSummaryText" style="line-height:1.4;">جاري تحديث التوقيت...</span>
                  </div>

                  <div
                    style="background:var(--surface-2); border:1px solid var(--border); border-radius:12px; padding:14px;">
                    <div style="display:flex; align-items:center; justify-content:space-between; cursor:pointer;"
                      onclick="document.getElementById('fTimerOn').click();">
                      <div style="display:flex; align-items:center; gap:10px;">
                        <div
                          style="width:36px; height:36px; border-radius:10px; background:rgba(217,119,6,0.12); color:#d97706; display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0;">
                          <i class="fas fa-stopwatch"></i>
                        </div>
                        <div>
                          <div style="font-weight:700; font-size:0.88rem; color:var(--t1);">عداد تنازلي للإجابة
                            (اختياري)</div>
                          <div style="font-size:0.75rem; color:var(--t3);">تحديد زمن محدد بالدقائق يبدأ عند فتح الطفل
                            للتاسك</div>
                        </div>
                      </div>
                      <label class="tgl" onclick="event.stopPropagation();">
                        <input type="checkbox" id="fTimerOn" onchange="toggleTimerRowDisplay(this.checked)">
                        <span class="tgl-s"></span>
                      </label>
                    </div>

                    <div id="timerRow"
                      style="display:none; margin-top:12px; padding-top:12px; border-top:1px dashed var(--border);">
                      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                        <div class="fg" style="margin:0;">
                          <label class="flbl" style="font-weight:700; font-size:0.78rem;">زمن حل التاسك (بالدقائق) <span
                              class="req">*</span></label>
                          <input id="fTimerMin" type="number" class="fi" min="1" max="180" placeholder="مثال: 30"
                            style="border-radius:10px;">
                        </div>
                        <div class="fg" style="margin:0;">
                          <label class="flbl" style="font-weight:700; font-size:0.78rem;">عند انتهاء الوقت</label>
                          <select id="fTimerBeh" class="fs" style="border-radius:10px; height:38px;">
                            <option value="submit">إرسال تلقائي</option>
                            <option value="lock">تأمين بدون إرسال</option>
                          </select>
                        </div>
                      </div>
                    </div>
                  </div>

                </div>
              </div>

            </div>

            <!-- Column 2: Options Card -->
            <div class="step-stack">
              <div class="scard2">
                <div class="scard2-hdr"><i class="fas fa-sliders-h"></i> خيارات</div>
                <div class="scard2-body" style="padding-bottom:4px;">
                  <div class="hint-box" style="margin-bottom:14px;">
                    <i class="fas fa-hand-pointer"></i>
                    <div>
                      <strong>ما يراه الطفل</strong>
                      <span>اختر الخيارات التي تجعل التاسك أوضح: نتيجة فورية، مراجعة الإجابات، أو إظهار الحلول بعد
                        الإنهاء.</span>
                    </div>
                  </div>

                  <div class="sopt-row" onclick="document.getElementById('fShowAns').click()">
                    <div class="sopt-ico" style="background:#cffafe;color:#0891b2;"><i class="fas fa-eye"></i></div>
                    <div class="sopt-txt">
                      <div class="sopt-lbl">إظهار الإجابات المفصلة</div>
                      <div class="sopt-desc">السماح للطفل بمعرفة إجاباته الصحيحة والخاطئة</div>
                    </div>
                    <label class="tgl" onclick="event.stopPropagation()"><input type="checkbox" id="fShowAns" checked><span
                        class="tgl-s"></span></label>
                  </div>

                  <div class="sopt-row" onclick="document.getElementById('fShowRes').click()">
                    <div class="sopt-ico" style="background:#d1fae5;color:#059669;"><i class="fas fa-check-circle"></i>
                    </div>
                    <div class="sopt-txt">
                      <div class="sopt-lbl">إظهار النتيجة فور الانتهاء</div>
                      <div class="sopt-desc">يرى الطفل درجته مباشرةً بعد التسليم</div>
                    </div>
                    <label class="tgl" onclick="event.stopPropagation()"><input type="checkbox" id="fShowRes"
                        checked><span class="tgl-s"></span></label>
                  </div>

                  <div class="sopt-row" onclick="document.getElementById('fShuffle').click()">
                    <div class="sopt-ico" style="background:var(--brand-bg);color:var(--brand);"><i
                        class="fas fa-random"></i></div>
                    <div class="sopt-txt">
                      <div class="sopt-lbl">خلط ترتيب الأسئلة</div>
                      <div class="sopt-desc">ترتيب عشوائي مختلف لكل طفل</div>
                    </div>
                    <label class="tgl" onclick="event.stopPropagation()"><input type="checkbox" id="fShuffle"><span
                        class="tgl-s"></span></label>
                  </div>

                  <div class="sopt-row" onclick="document.getElementById('fShuffleAnswers').click()">
                    <div class="sopt-ico" style="background:rgba(124,58,237,0.12);color:#7c3aed;"><i
                        class="fas fa-random"></i></div>
                    <div class="sopt-txt">
                      <div class="sopt-lbl">خلط ترتيب الإجابات (الخيارات)</div>
                      <div class="sopt-desc">ترتيب عشوائي للخيارات في أسئلة الاختيار من متعدد</div>
                    </div>
                    <label class="tgl" onclick="event.stopPropagation()"><input type="checkbox" id="fShuffleAnswers"><span
                        class="tgl-s"></span></label>
                  </div>

                  <div class="sopt-row" style="margin-bottom:0;" onclick="document.getElementById('fReview').click()">
                    <div class="sopt-ico" style="background:#fef3c7;color:#d97706;"><i class="fas fa-eye"></i></div>
                    <div class="sopt-txt">
                      <div class="sopt-lbl">مراجعة الإجابات قبل الإرسال</div>
                      <div class="sopt-desc">يستطيع الطفل تغيير إجاباته قبل التسليم النهائي</div>
                    </div>
                    <label class="tgl" onclick="event.stopPropagation()"><input type="checkbox" id="fReview"
                        checked><span class="tgl-s"></span></label>
                  </div>
                </div>
              </div>
            </div>

          </div>
        </div>

        <!-- Step 2 -->



        <div id="sp2" style="display:none;">



          <div class="fsec" style="margin-bottom:0;">



            <div class="fsec-title" style="display:flex;align-items:center;justify-content:space-between;">



              <span><i class="fas fa-question-circle"></i>الأسئلة</span>



              <span style="font-size:.72rem;color:var(--t3);font-weight:500;">اضغط على النوع لكل سؤال لتغييره</span>



            </div>



            <div class="qlist" id="qList"></div>



            <div style="display:flex; gap:10px; align-items:center; margin-bottom:12px; flex-wrap:wrap;">
              <button type="button" class="add-q" onclick="addQ()" style="flex:1; min-width:130px; margin-bottom:0;"><i class="fas fa-plus-circle"></i>إضافة سؤال</button>
              <button type="button" class="add-q" onclick="openBulkQModal()" style="flex:1; min-width:160px; margin-bottom:0; background:linear-gradient(135deg, #10b981 0%, #059669 100%); color:#fff; border:none; box-shadow:0 4px 14px rgba(16,185,129,0.3);"><i class="fas fa-file-excel"></i> استيراد شيت أسئلة</button>
              <button type="button" class="add-q" onclick="exportCurrentEditorQuestions()" style="flex:1; min-width:150px; margin-bottom:0; background:linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); color:#fff; border:none; box-shadow:0 4px 14px rgba(99,102,241,0.3);"><i class="fas fa-file-export"></i> تصدير الأسئلة</button>
            </div>



            <div class="deg-sum"><span class="deg-sum-l"><i class="fas fa-star" style="margin-left:4px;"></i>إجمالي
                الدرجات</span><span class="deg-sum-v" id="degTotal">0 <small
                  style="font-size:.7rem;font-weight:500;">درجة</small></span></div>



          </div>



        </div>







        <!-- Step 3 -->



        <div id="sp3" style="display:none;">



          <div class="fsec" style="margin-bottom:0;">



            <div class="fsec-title"><i class="fas fa-star"></i>مستويات الكوبونات</div>



            <p style="font-size:.77rem;color:var(--t3);margin-bottom:13px;">حدد كم كوبون يحصل عليه الطفل بناءً على نسبة
              إجاباته الصحيحة.<br>الدرجة الكلية: <strong id="s3deg">0</strong> درجة.</p>



            <div class="ctiers" id="ctierList"></div>



            <button class="add-opt" style="margin-top:9px;width:100%;justify-content:center;"
              onclick="addMilestone()"><i class="fas fa-plus"></i>إضافة مستوى</button>



          </div>



        </div>







      </div>



      <div class="mfoot">



        <div style="margin-left:auto;font-size:.73rem;color:var(--t3);">الخطوة <strong id="stepNum">1</strong> من 3
        </div>



        <button class="btn btn-g" id="prevBtn" onclick="prevStep()" style="display:none;"><i
            class="fas fa-chevron-right"></i> السابق</button>



        <button class="btn btn-g" id="draftBtn" onclick="saveDraft()"><i class="fas fa-save"></i> Draft</button>

        <button class="btn btn-p" id="quickSaveBtn" onclick="publishTask()" style="display:none;"><i class="fas fa-save"></i> حفظ التعديلات</button>

        <button class="btn btn-p" id="nextBtn" onclick="nextStep()">التالي <i class="fas fa-chevron-left"></i></button>

        <button class="btn btn-p" id="pubBtn" onclick="publishTask()" style="display:none;"><i
            class="fas fa-paper-plane"></i> نشر</button>



      </div>



    </div>



  </div>







  <!-- ══ DETAIL MODAL ══════════════════════════════════════════════ -->



  <div class="overlay fullscreen" id="detailOv">



    <div class="modal wide">



      <div class="mhdr"
        style="position: relative; display: flex; align-items: center; justify-content: space-between; gap: 10px;">
        <div style="display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0;">
          <div class="mhdr-ico" style="flex-shrink: 0;"><i class="fas fa-eye"></i></div>
          <div style="min-width: 0;">
            <div class="mhdr-title" id="dTitle" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
              تفاصيل التاسك</div>
            <div class="mhdr-sub" id="dSub" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
            </div>
          </div>
        </div>
        <div class="mclose" onclick="closeDetail()" style="flex-shrink: 0;"><i class="fas fa-times"></i></div>
      </div>



      <div class="mbody" id="dBody" style="flex:1; overflow-y:auto; overflow-x:hidden;"></div>



      <div class="mfoot" id="dFoot"></div>



    </div>



  </div>







  <!-- ══ CONFIRM DELETE ════════════════════════════════════════════ -->



  <div class="overlay" id="confOv" style="z-index: 10000 !important;">



    <div class="modal" style="max-width:440px;">



      <div class="conf-body" style="padding-bottom:16px;">



        <div class="conf-ico"><i class="fas fa-trash-alt"></i></div>



        <div class="conf-t">حذف التاسك؟</div>



        <div class="conf-s" id="confSub" style="margin-bottom:14px;">لا يمكن التراجع.</div>



        <div id="confCouponNote"
          style="display:none;background:var(--cou-bg);border:1px solid #d8b4fe;border-radius:10px;padding:11px 14px;font-size:.8rem;color:var(--t2);text-align:right;line-height:1.7;">



          <strong style="color:var(--cou);display:block;margin-bottom:4px;"><i class="fas fa-star"></i> كوبونات
            الأطفال</strong>



          <span id="confCouponDetail"></span>



        </div>



      </div>



      <div class="mfoot" style="flex-direction:column;gap:8px;padding:14px 20px;">



        <button class="btn btn-dg" style="width:100%;justify-content:center;background:var(--err-bg);"
          onclick="doDelete(1)">



          <i class="fas fa-trash-alt"></i> حذف وسحب الكوبونات من الأطفال



        </button>



        <button class="btn"
          style="width:100%;justify-content:center;background:var(--warn-bg);color:var(--warn);border-color:#fcd34d;"
          onclick="doDelete(0)">



          <i class="fas fa-star"></i> حذف والاحتفاظ بالكوبونات



        </button>



        <button class="btn btn-g" style="width:100%;justify-content:center;" onclick="closeConf()">إلغاء</button>



      </div>



    </div>



  </div>







  <!-- ══ DELETE SUBMISSION CONFIRM ═════════════════════════════════ -->



  <div class="overlay" id="delSubConfOv" style="z-index: 10000 !important;">



    <div class="modal narrow">



      <div class="conf-body">



        <div class="conf-ico"><i class="fas fa-trash-alt"></i></div>



        <div class="conf-t">حذف الإجابة؟</div>



        <div class="conf-s" id="delSubMsg">لا يمكن التراجع.</div>



      </div>



      <div class="mfoot" style="justify-content:center;gap:10px;">



        <button class="btn btn-g" onclick="closeOv('delSubConfOv')">إلغاء</button>



        <button class="btn btn-dg" onclick="doDeleteSubConfirmed()"><i class="fas fa-trash-alt"></i> حذف</button>



      </div>



    </div>



  </div>







  <!-- ══ TASKS OVERVIEW & EXPORT MODAL ══════════════════════════════ -->
  <div class="overlay fullscreen" id="overviewOv">
    <style>
      #overviewOv .modal {
        font-family: 'Baloo Bhaijaan 2', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      }

      #overviewOv i.fas,
      #overviewOv i.far,
      #overviewOv i.fal,
      #overviewOv i.fab,
      #overviewOv i.fa,
      #overviewOv .fas,
      #overviewOv .far,
      #overviewOv .fal,
      #overviewOv .fab,
      #overviewOv .fa {
        font-family: "Font Awesome 6 Free", "Font Awesome 6 Brands", FontAwesome !important;
        font-style: normal !important;
      }

      .ov-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        direction: rtl;
        font-size: .87rem;
        margin-bottom: 25px;
      }

      .ov-table th {
        background: var(--brand-bg) !important;
        color: var(--brand) !important;
        font-weight: 700;
        padding: 12px 10px;
        border-bottom: 2px solid var(--brand-l);
        text-align: right;
        white-space: nowrap;
        position: sticky;
        top: 0;
        z-index: 10;
      }

      .ov-table td {
        padding: 10px;
        border-bottom: 1px solid var(--bdr);
        color: var(--t1);
        vertical-align: middle;
        text-align: right;
      }

      .ov-table tr:hover td {
        background: var(--bg-hover) !important;
      }

      .ov-table tr:nth-child(even) td {
        background: var(--bg);
      }

      .ov-cell-answered {
        color: #10b981;
        font-weight: bold;
        display: inline-flex;
        align-items: center;
        gap: 4px;
      }

      .ov-cell-unanswered {
        color: var(--t3);
        font-style: italic;
        display: inline-flex;
        align-items: center;
        gap: 4px;
      }

      .ov-grade-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 6px;
        border-radius: 4px;
        font-size: .75rem;
        font-weight: bold;
      }

      .ov-grade-pass {
        background: #e6fcf5;
        color: #0ca678;
      }

      .ov-grade-fail {
        background: #fff5f5;
        color: #fa5252;
      }

      .ov-time-text {
        font-size: .7rem;
        color: var(--t3);
        margin-top: 3px;
        display: block;
      }

      .ov-class-badge {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 4px;
        font-size: .75rem;
        background: var(--bg-card);
        border: 1px solid var(--bdr);
        color: var(--t2);
      }

      .ov-progress-pill {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.76rem;
        font-weight: 700;
        background: #f1f3f5;
        color: #495057;
      }
      .ov-progress-pill.completed {
        background: #d3f9d8;
        color: #2b8a3e;
      }
      .ov-progress-pill.empty {
        background: #ffe3e3;
        color: #c92a2a;
      }

      /* Premium unified export buttons */
      .btn-export {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 8px 16px;
        border-radius: var(--r-md);
        font-size: .83rem;
        font-weight: 700;
        cursor: pointer;
        transition: all var(--fast);
        border: 1.5px solid transparent;
        text-decoration: none;
        min-height: 38px;
      }

      .btn-export i {
        font-size: 0.95rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        line-height: 1;
      }

      .ov-cell-answered i,
      .ov-cell-unanswered i {
        font-size: 0.85rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        line-height: 1;
      }

      .btn-export.btn-msg {
        background: rgba(16, 185, 129, 0.08);
        color: #10b981;
        border-color: rgba(16, 185, 129, 0.2);
      }
      .btn-export.btn-msg:hover {
        background: #10b981;
        color: #fff;
        border-color: #10b981;
        transform: translateY(-1px);
      }

      .btn-export.btn-csv {
        background: rgba(59, 130, 246, 0.08);
        color: #3b82f6;
        border-color: rgba(59, 130, 246, 0.2);
      }
      .btn-export.btn-csv:hover {
        background: #3b82f6;
        color: #fff;
        border-color: #3b82f6;
        transform: translateY(-1px);
      }

      .btn-export.btn-pdf {
        background: rgba(236, 72, 153, 0.08);
        color: #ec4899;
        border-color: rgba(236, 72, 153, 0.2);
      }
      .btn-export.btn-pdf:hover {
        background: #ec4899;
        color: #fff;
        border-color: #ec4899;
        transform: translateY(-1px);
      }

      .btn-export.btn-img {
        background: rgba(139, 92, 246, 0.08);
        color: #8b5cf6;
        border-color: rgba(139, 92, 246, 0.2);
      }
      .btn-export.btn-img:hover {
        background: #8b5cf6;
        color: #fff;
        border-color: #8b5cf6;
        transform: translateY(-1px);
      }

      /* Control cards styling */
      .ov-controls-panel {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
        background: var(--bg);
        border: 1px solid var(--bdr);
        padding: 12px;
        border-radius: 12px;
        transition: all 0.25s ease;
      }
      .ov-card {
        background: #fff;
        border: 1px solid var(--bdr);
        border-radius: 10px;
        padding: 12px;
        display: flex;
        flex-direction: column;
        gap: 8px;
        box-shadow: 0 1px 2px rgba(0,0,0,0.02);
      }
      .ov-card-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-bottom: 1px solid var(--bdr);
        padding-bottom: 6px;
        font-weight: 700;
        font-size: 0.85rem;
        color: var(--t1);
      }
      .ov-badge {
        font-size: 0.7rem;
        padding: 1px 6px;
        background: var(--brand-bg);
        color: var(--brand);
        border-radius: 10px;
        font-weight: 700;
      }
      .ov-btn-mini {
        background: var(--bg);
        border: 1px solid var(--bdr);
        color: var(--t2);
        padding: 2px 7px;
        font-size: 0.72rem;
        border-radius: 5px;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.15s;
        font-family: inherit;
      }
      .ov-btn-mini:hover {
        background: var(--brand);
        color: #fff;
        border-color: var(--brand);
      }
      .ov-list-scroll {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        max-height: 95px;
        overflow-y: auto;
        padding: 6px;
        border: 1px solid var(--bdr);
        border-radius: 8px;
        background: var(--bg);
      }
      .ov-chip {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        cursor: pointer;
        font-size: 0.76rem;
        background: #fff;
        padding: 3px 7px;
        border-radius: 6px;
        border: 1px solid var(--bdr);
        transition: all 0.15s;
        user-select: none;
      }
      .ov-chip:hover {
        border-color: var(--brand-l);
        background: var(--brand-bg);
      }
      .ov-chip input[type="checkbox"] {
        accent-color: var(--brand);
        width: 14px;
        height: 14px;
        cursor: pointer;
        margin: 0;
      }
      .ov-label {
        font-weight: 700;
        font-size: 0.8rem;
        color: var(--t1);
        display: flex;
        align-items: center;
        gap: 5px;
        margin-top: 2px;
      }
      .ov-select, .ov-input {
        padding: 6px 10px;
        border: 1px solid var(--bdr);
        border-radius: 7px;
        background: #fff;
        font-size: 0.82rem;
        outline: none;
        color: var(--t1);
        font-family: inherit;
        transition: border-color 0.2s;
        width: 100%;
        box-sizing: border-box;
      }
      .ov-select:focus, .ov-input:focus {
        border-color: var(--brand);
        box-shadow: 0 0 0 2px var(--brand-bg);
      }
      .ov-toggle-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 6px;
      }
      .ov-toggle-item {
        display: flex;
        align-items: center;
        gap: 6px;
        cursor: pointer;
        font-size: 0.76rem;
        color: var(--t1);
        padding: 4px 6px;
        border-radius: 6px;
        border: 1px solid var(--bdr);
        background: var(--bg);
        transition: all 0.15s;
      }
      .ov-toggle-item:hover {
        background: var(--brand-bg);
        border-color: var(--brand-l);
      }
      .ov-toggle-item input[type="checkbox"] {
        accent-color: var(--brand);
        width: 14px;
        height: 14px;
        cursor: pointer;
        margin: 0;
      }
      .ov-summary-strip {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        flex-wrap: wrap;
        padding: 8px 12px;
        background: var(--bg);
        border: 1px solid var(--bdr);
        border-radius: 8px;
        font-size: 0.8rem;
        color: var(--t1);
      }
      .ov-summary-pills-wrap {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
      }
      .ov-summary-pill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-weight: 700;
        background: #fff;
        border: 1px solid var(--bdr);
        padding: 3px 8px;
        border-radius: 8px;
        font-size: 0.76rem;
      }
      .ov-summary-pill i {
        color: var(--brand);
      }
      .ov-task-sub {
        font-size: 0.68rem;
        font-weight: normal;
        opacity: 0.75;
      }

      /* Responsive overrides for tasks overview */
      @media (max-width: 960px) {
        .ov-controls-panel {
          grid-template-columns: 1fr !important;
          gap: 10px !important;
          padding: 10px !important;
        }
        .ov-toggle-grid {
          grid-template-columns: 1fr 1fr !important;
        }
      }

      @media (max-width: 768px) {
        #overviewOv .mhdr {
          padding: 12px 16px !important;
        }

        #overviewOv .mhdr-title {
          font-size: 1.1rem !important;
        }

        #overviewOv .mhdr-sub {
          font-size: 0.75rem !important;
          line-height: 1.3;
        }

        #overviewOv .mbody {
          padding: 12px !important;
          gap: 12px !important;
        }

        .ov-table {
          font-size: 0.75rem !important;
        }

        .ov-table th {
          padding: 8px 6px !important;
        }

        .ov-table td {
          padding: 6px 6px !important;
        }

        .ov-grade-badge {
          padding: 1px 4px !important;
          font-size: 0.68rem !important;
        }

        .ov-class-badge {
          padding: 1px 4px !important;
          font-size: 0.68rem !important;
        }

        .ov-time-text {
          font-size: 0.65rem !important;
        }

        #overviewOv .mfoot {
          flex-direction: column !important;
          align-items: stretch !important;
          gap: 10px !important;
          padding: 12px !important;
        }

        .ov-mfoot-btns {
          display: grid !important;
          grid-template-columns: repeat(2, 1fr) !important;
          gap: 8px !important;
          width: 100% !important;
        }

        .ov-mfoot-btns .btn,
        .ov-mfoot-btns .btn-export {
          width: 100% !important;
          justify-content: center !important;
          padding: 8px 10px !important;
          font-size: 0.78rem !important;
          min-height: 36px !important;
        }

        .ov-mfoot-btns .btn-g {
          grid-column: span 2 !important;
          order: 99 !important;
        }
      }
    </style>
    <div class="modal wide" style="display:flex; flex-direction:column;">
      <div class="mhdr">
        <div class="mhdr-ico" style="background:var(--brand-bg); color:var(--brand);"><i class="fas fa-file-export"></i>
        </div>
        <div>
          <div class="mhdr-title">تصدير نظرة عامة للتاسكات</div>
          <div class="mhdr-sub">تخصيص كامل للفلاتر والترتيب وإظهار وإخفاء الأعمدة والبيانات قبل التصدير والطباعة</div>
        </div>
        <div class="mclose" onclick="closeOv('overviewOv')"><i class="fas fa-times"></i></div>
      </div>

      <div class="mbody"
        style="flex:1 1 auto; min-height:0; overflow-y:auto; padding:16px; display:flex; flex-direction:column; gap:14px;">
        
        <!-- Top Toolbar -->
        <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
          <!-- Smart Search -->
          <div style="flex:1; min-width:240px; position:relative;">
            <i class="fas fa-search" style="position:absolute; right:12px; top:50%; transform:translateY(-50%); color:var(--t3); font-size:0.85rem; pointer-events:none;"></i>
            <input type="text" id="ovStudentSearch" oninput="renderOverviewTable()"
              placeholder="بحث ذكي بالاسم أو الفصل..."
              class="ov-input" style="padding-right:34px; padding-left:30px; height:38px; border-radius:8px;">
            <button type="button" onclick="clearOverviewSearch()" title="مسح البحث"
              style="position:absolute; left:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--t3); cursor:pointer; font-size:0.85rem; display:flex; align-items:center;">
              <i class="fas fa-times"></i>
            </button>
          </div>

          <!-- Quick Action Buttons -->
          <div style="display:flex; align-items:center; gap:8px;">
            <button type="button" class="btn btn-sm" id="ovTogglePanelBtn" onclick="toggleOverviewControlsPanel()"
              style="display:inline-flex; align-items:center; gap:6px; background:var(--bg-card); border:1px solid var(--bdr); font-weight:700; height:38px; padding:0 12px; border-radius:8px; cursor:pointer;">
              <i class="fas fa-sliders-h" style="color:var(--brand);"></i>
              <span id="ovTogglePanelText">إخفاء خيارات التخصيص</span>
            </button>
            <button type="button" class="btn btn-sm" onclick="resetOverviewAllSettings()"
              style="display:inline-flex; align-items:center; gap:6px; background:var(--bg-card); border:1px solid var(--bdr); font-weight:700; height:38px; padding:0 12px; border-radius:8px; cursor:pointer;"
              title="استعادة الإعدادات الافتراضية">
              <i class="fas fa-redo" style="color:var(--t2);"></i>
              <span>استعادة الافتراضي</span>
            </button>
          </div>
        </div>

        <!-- Customizable Controls Panel -->
        <div class="ov-controls-panel" id="ovControlsPanel">
          <!-- Card 1: الفلاتر واستهداف البيانات -->
          <div class="ov-card">
            <!-- Classes Selection -->
            <div class="ov-card-head">
              <span><i class="fas fa-users" style="color:var(--brand);"></i> الفصول المستهدفة <span class="ov-badge" id="ovClassesCountBadge">0</span></span>
              <div style="display:inline-flex; gap:4px;">
                <button type="button" class="ov-btn-mini" onclick="selectOverviewAllClasses(true)">الكل</button>
                <button type="button" class="ov-btn-mini" onclick="selectOverviewAllClasses(false)">إلغاء</button>
              </div>
            </div>
            <div id="ovClassesList" class="ov-list-scroll">
              <!-- Loaded dynamically in JS -->
            </div>

            <!-- Tasks Selection -->
            <div class="ov-card-head" style="margin-top:2px;">
              <span><i class="fas fa-tasks" style="color:var(--brand);"></i> التاسكات المحددة <span class="ov-badge" id="ovTasksCountBadge">0</span></span>
              <div style="display:inline-flex; gap:4px;">
                <button type="button" class="ov-btn-mini" onclick="selectOverviewAllTasks(true)">الكل</button>
                <button type="button" class="ov-btn-mini" onclick="selectOverviewRecentTasks(5)">أحدث 5</button>
                <button type="button" class="ov-btn-mini" onclick="selectOverviewAllTasks(false)">إلغاء</button>
              </div>
            </div>
            <input type="text" id="ovTaskFilterInput" oninput="filterOverviewTasksList()" placeholder="فلترة قائمة التاسكات..." class="ov-input" style="padding:4px 8px; font-size:0.75rem; height:28px;">
            <div id="ovTasksList" class="ov-list-scroll">
              <!-- Loaded dynamically in JS -->
            </div>

            <!-- Solution Status Filter -->
            <div style="display:flex; flex-direction:column; gap:4px; margin-top:2px;">
              <label class="ov-label"><i class="fas fa-check-double"></i> حالة حل التاسكات:</label>
              <select id="ovAnswerStatus" class="ov-select" onchange="renderOverviewTable()">
                <option value="both">الكل (الذين أجابوا والذين لم يجيبوا)</option>
                <option value="completed_all">أتموا جميع التاسكات المحددة (100% مكتمل)</option>
                <option value="partial">حل جزئي (أنجزوا بعض التاسكات ولديهم متبقي)</option>
                <option value="at_least_one">حلوا تاسك واحد على الأقل</option>
                <option value="missing">لديهم تاسكات لم تُحل بعد</option>
                <option value="unanswered">لم يحلوا أي تاسك نهائياً (0%)</option>
              </select>
            </div>

            <!-- Grade / Performance Filter -->
            <div style="display:flex; flex-direction:column; gap:4px;">
              <label class="ov-label"><i class="fas fa-award"></i> مستوى التقييم والدرجات:</label>
              <select id="ovGradeFilter" class="ov-select" onchange="renderOverviewTable()">
                <option value="all">كل المستويات والدرجات</option>
                <option value="excellent">ممتاز / متفوق (85% فأعلى)</option>
                <option value="very_good">جيد جداً فأعلى (75% فأعلى)</option>
                <option value="pass">ناجح (50% فأعلى)</option>
                <option value="fail">دون المستوى / راسب (أقل من 50%)</option>
              </select>
            </div>
          </div>

          <!-- Card 2: الترتيب والفرز -->
          <div class="ov-card">
            <div class="ov-card-head">
              <span><i class="fas fa-sort-amount-down" style="color:var(--brand);"></i> خيارات الترتيب والفرز</span>
            </div>

            <!-- Student Sort By -->
            <div style="display:flex; flex-direction:column; gap:4px;">
              <label class="ov-label"><i class="fas fa-user-graduate"></i> ترتيب الطلاب حسب:</label>
              <select id="ovSortBy" class="ov-select" onchange="renderOverviewTable()">
                <option value="name" selected>اسم الطالب (أبجدياً)</option>
                <option value="class">الفصل ثم اسم الطالب</option>
                <option value="solved_count">عدد التاسكات المكتملة</option>
                <option value="total_score">إجمالي الدرجات المحصلة</option>
                <option value="score_pct">النسبة المئوية للدرجات (%)</option>
                <option value="last_submitted">وقت وتاريخ آخر تسليم</option>
              </select>
            </div>

            <!-- Student Sort Direction -->
            <div style="display:flex; flex-direction:column; gap:4px;">
              <label class="ov-label"><i class="fas fa-exchange-alt"></i> اتجاه ترتيب الطلاب:</label>
              <select id="ovSortDir" class="ov-select" onchange="renderOverviewTable()">
                <option value="asc" selected>تصاعدي (أ - ي / من الأقل للأعلى)</option>
                <option value="desc">تنازلي (ي - أ / من الأعلى للأقل)</option>
              </select>
            </div>

            <!-- Task Sort Order -->
            <div style="display:flex; flex-direction:column; gap:4px; margin-top:4px;">
              <label class="ov-label"><i class="fas fa-calendar-alt"></i> ترتيب أعمدة التاسكات:</label>
              <select id="ovTaskSort" class="ov-select" onchange="handleOverviewTaskSortChange()">
                <option value="created_asc" selected>تاريخ إضافة التاسك (الأقدم أولاً - زمني)</option>
                <option value="created_desc">تاريخ إضافة التاسك (الأحدث أولاً)</option>
                <option value="title_asc">أبجدياً حسب عنوان التاسك</option>
              </select>
            </div>

            <!-- View Mode -->
            <div style="display:flex; flex-direction:column; gap:4px; margin-top:4px;">
              <label class="ov-label"><i class="fas fa-table"></i> طريقة العرض والتصدير:</label>
              <select id="ovViewMode" class="ov-select" onchange="renderOverviewTable()">
                <option value="matrix" selected>جدول الطلاب والتاسكات الشامل (Matrix)</option>
                <option value="by_task">قائمة المجيبين المفصلة لكل تاسك</option>
              </select>
            </div>

            <!-- Page Orientation -->
            <div style="display:flex; flex-direction:column; gap:4px; margin-top:4px;">
              <label class="ov-label"><i class="fas fa-file-pdf"></i> اتجاه الصفحة للطباعة والتصدير:</label>
              <select id="ovOrientation" class="ov-select">
                <option value="landscape" selected>عرضي (Landscape) - موصى به</option>
                <option value="portrait">طولي (Portrait)</option>
              </select>
            </div>
          </div>

          <!-- Card 3: إظهار وإخفاء الأعمدة والبيانات -->
          <div class="ov-card">
            <div class="ov-card-head">
              <span><i class="fas fa-eye" style="color:var(--brand);"></i> إظهار وإخفاء الأعمدة والبيانات</span>
              <button type="button" class="ov-btn-mini" onclick="resetOverviewDisplayToggles()">افتراضي</button>
            </div>

            <div class="ov-toggle-grid">
              <label class="ov-toggle-item" title="إظهار أو إخفاء رقم الصف التسلسلي">
                <input type="checkbox" id="ovShowRowIndex" onchange="renderOverviewTable()" checked>
                <span># المسلسل</span>
              </label>

              <label class="ov-toggle-item" title="إظهار أو إخفاء صورة الطالب الشخصية">
                <input type="checkbox" id="ovShowAvatar" onchange="renderOverviewTable()" checked>
                <span>صورة الطالب</span>
              </label>

              <label class="ov-toggle-item" title="إظهار أو إخفاء عمود الفصل">
                <input type="checkbox" id="ovShowClassName" onchange="renderOverviewTable()" checked>
                <span>عمود الفصل</span>
              </label>

              <label class="ov-toggle-item" title="إظهار أو إخفاء عمود عدد التاسكات المنجزة">
                <input type="checkbox" id="ovShowSolvedCount" onchange="renderOverviewTable()" checked>
                <span>عمود الإنجاز</span>
              </label>

              <label class="ov-toggle-item" title="إظهار أو إخفاء عمود المجموع والنسبة المئوية">
                <input type="checkbox" id="ovShowTotalScore" onchange="renderOverviewTable()" checked>
                <span>المجموع والنسبة %</span>
              </label>

              <label class="ov-toggle-item" title="إظهار الدرجات والتقييم العددي داخل كل تاسك">
                <input type="checkbox" id="ovShowGrades" onchange="renderOverviewTable()" checked>
                <span>تفاصيل الدرجات</span>
              </label>

              <label class="ov-toggle-item" title="إظهار وقت وتاريخ تسليم كل تاسك">
                <input type="checkbox" id="ovShowTime" onchange="renderOverviewTable()" checked>
                <span>وقت التسليم</span>
              </label>

              <label class="ov-toggle-item" title="إظهار شريط الملخص والإحصائيات في رأس التقرير">
                <input type="checkbox" id="ovShowStatsHeader" onchange="renderOverviewTable()" checked>
                <span>إحصائيات الرأس</span>
              </label>
            </div>

            <!-- Quick Hint / Instructions -->
            <div style="font-size:0.74rem; color:var(--t3); line-height:1.4; margin-top:auto; background:var(--bg); border:1px dashed var(--bdr); padding:8px 10px; border-radius:8px;">
              💡 <strong>نصيحة:</strong> يمكنك إلغاء تحديد بعض الأعمدة أو التاسكات للتركيز على نتائج محددة فقط وتسهيل طباعتها كـ PDF أو صورة.
            </div>
          </div>
        </div>

        <!-- Live Summary Strip -->
        <div class="ov-summary-strip" id="ovSummaryStrip">
          <div class="ov-summary-pills-wrap">
            <div class="ov-summary-pill"><i class="fas fa-user-friends"></i> الطلاب: <span id="ovStatStudents">0</span></div>
            <div class="ov-summary-pill"><i class="fas fa-file-alt"></i> التاسكات: <span id="ovStatTasks">0</span></div>
            <div class="ov-summary-pill"><i class="fas fa-check-circle" style="color:#10b981;"></i> نسبة الحل: <span id="ovStatSolveRate">0%</span></div>
            <div class="ov-summary-pill"><i class="fas fa-chart-line" style="color:#8b5cf6;"></i> متوسط الدرجات: <span id="ovStatAvgScore">0%</span></div>
          </div>
          <div style="font-size:0.75rem; color:var(--t2);" id="ovActiveFiltersSummary"></div>
        </div>

        <!-- Table Wrapper -->
        <div style="flex:1; border:1px solid var(--bdr); border-radius:12px; background:#fff; padding:0; overflow:auto; position:relative; min-height:280px;">
          <div id="ovTableContainer">
            <!-- Dynamically Rendered Table -->
          </div>
        </div>
      </div>

      <div class="mfoot" style="justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div style="font-size:.8rem; color:var(--t2);" id="ovStatsText">جاري معالجة البيانات...</div>
        <div class="ov-mfoot-btns" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
          <button class="btn btn-g" onclick="closeOv('overviewOv')" style="order:99;">إلغاء</button>
          <button class="btn-export btn-msg" onclick="copyOverviewMessage()"><i class="fas fa-copy"></i> نسخ كرسالة</button>
          <button class="btn-export btn-csv" onclick="exportOverviewCSV()"><i class="fas fa-file-csv"></i> CSV</button>
          <button class="btn-export btn-pdf" onclick="exportOverviewPDF()"><i class="fas fa-file-pdf"></i> PDF</button>
          <button class="btn-export btn-img" onclick="exportOverviewImage()"><i class="fas fa-file-image"></i> صورة</button>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ TASK QUESTIONS EXPORT MODAL ══════════════════════════════ -->
  <div class="overlay fullscreen" id="taskQuestionsExportOv" style="z-index: 10005;">
    <style>
      .tq-export-box {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 14px;
        background: var(--bg);
        border: 1px solid var(--bdr);
        padding: 16px;
        border-radius: 14px;
      }
      .tq-mode-toggle {
        display: flex;
        background: var(--bg2, #f1f5f9);
        border-radius: 12px;
        padding: 4px;
        gap: 4px;
        border: 1px solid var(--bdr);
      }
      .tq-mode-btn {
        flex: 1;
        padding: 9px 12px;
        border-radius: 9px;
        border: none;
        background: transparent;
        color: var(--t2);
        font-family: 'Baloo Bhaijaan 2', 'Cairo', sans-serif;
        font-size: 0.85rem;
        font-weight: 700;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: all 0.2s;
        white-space: nowrap;
      }
      .tq-mode-btn.active {
        background: #ffffff;
        color: var(--brand);
        box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
      }
      .tq-preview-viewport {
        flex: 1;
        border: 1.5px solid var(--bdr);
        border-radius: 14px;
        background: #f1f5f9;
        overflow-y: auto;
        position: relative;
        min-height: 320px;
        padding: 24px 16px;
        display: flex;
        flex-direction: column;
        align-items: center;
      }
      .tq-paper-sheet,
      .tq-page-sheet {
        background: #ffffff;
        color: #1e293b;
        width: 100%;
        max-width: 800px;
        min-height: 1131px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 30px 36px 24px 36px;
        box-shadow: 0 4px 25px rgba(0, 0, 0, 0.08);
        font-family: 'Baloo Bhaijaan 2', 'Cairo', sans-serif;
        direction: rtl;
        box-sizing: border-box;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        position: relative;
        flex-shrink: 0;
      }
      .tq-paper-sheet.landscape-view,
      .tq-page-sheet.landscape-view {
        max-width: 1131px;
        min-height: 800px;
      }
      .tq-page-divider {
        display: flex;
        align-items: center;
        gap: 12px;
        width: 100%;
        max-width: 800px;
        color: #64748b;
        font-size: 0.8rem;
        font-weight: 700;
        margin: 4px 0;
      }
      .tq-page-divider.landscape-view {
        max-width: 1131px;
      }
      .tq-page-divider::before,
      .tq-page-divider::after {
        content: '';
        flex: 1;
        border-bottom: 1.5px dashed #cbd5e1;
      }
      .tq-page-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-top: 1.5px dashed #cbd5e1;
        padding-top: 12px;
        margin-top: 14px;
        font-size: 0.78rem;
        font-weight: 700;
        color: #64748b;
        flex-shrink: 0;
      }
      .tq-page-num-badge {
        background: #f1f5f9;
        color: #334155;
        padding: 2px 10px;
        border-radius: 99px;
        border: 1px solid #e2e8f0;
        font-weight: 800;
      }
      .tq-q-item {
        background: #ffffff;
        border: 1.5px solid #e2e8f0;
        border-radius: 12px;
        padding: 14px 18px;
        margin-bottom: 12px;
        page-break-inside: avoid;
        break-inside: avoid;
      }
      .tq-q-header {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 12px;
      }
      .tq-q-num {
        background: var(--brand-bg, #eff2fe);
        color: var(--brand, #5b6cf5);
        font-weight: 900;
        font-size: 0.95rem;
        width: 32px;
        height: 32px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        border: 1px solid var(--brand-l, #c7d2fe);
      }
      .tq-q-title {
        flex: 1;
        font-size: 1.02rem;
        font-weight: 700;
        line-height: 1.5;
        color: #0f172a;
      }
      .tq-q-badge {
        font-size: 0.75rem;
        font-weight: 700;
        padding: 4px 10px;
        border-radius: 6px;
        white-space: nowrap;
      }
      .tq-opt-row {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 9px 14px;
        border: 1.5px solid #e2e8f0;
        border-radius: 9px;
        margin-top: 8px;
        font-size: 0.92rem;
        background: #ffffff;
        transition: 0.15s;
      }
      .tq-opt-row.is-correct {
        background: #ecfdf5 !important;
        border-color: #10b981 !important;
        color: #065f46 !important;
        font-weight: 800;
      }
      @media (max-width: 768px) {
        .tq-preview-viewport {
          padding: 10px;
        }
        .tq-page-sheet {
          padding: 20px 16px;
        }
        .tq-mode-toggle {
          flex-direction: column;
        }
      }
      @media print {
        html, body {
          overflow: visible !important;
          height: auto !important;
          background: #fff !important;
        }
        body * {
          visibility: hidden;
        }
        #taskQuestionsExportOv, #taskQuestionsExportOv * {
          visibility: visible;
        }
        #taskQuestionsExportOv {
          position: absolute !important;
          top: 0 !important;
          left: 0 !important;
          width: 100% !important;
          height: auto !important;
          min-height: auto !important;
          max-height: none !important;
          background: #fff !important;
          padding: 0 !important;
          margin: 0 !important;
          z-index: 9999999 !important;
          overflow: visible !important;
          display: block !important;
        }
        #taskQuestionsExportOv .modal {
          box-shadow: none !important;
          border: none !important;
          max-width: 100% !important;
          width: 100% !important;
          height: auto !important;
          padding: 0 !important;
          margin: 0 !important;
          border-radius: 0 !important;
          overflow: visible !important;
          display: block !important;
        }
        #taskQuestionsExportOv .mbody {
          overflow: visible !important;
          height: auto !important;
          max-height: none !important;
          padding: 0 !important;
          display: block !important;
        }
        #taskQuestionsExportOv .mhdr,
        #taskQuestionsExportOv .tq-export-box,
        #taskQuestionsExportOv .mfoot,
        #taskQuestionsExportOv .tq-page-divider {
          display: none !important;
        }
        .tq-preview-viewport {
          background: #fff !important;
          padding: 0 !important;
          border: none !important;
          overflow: visible !important;
          height: auto !important;
          display: block !important;
        }
        #tqPaperExport {
          display: block !important;
          gap: 0 !important;
          width: 100% !important;
        }
        .tq-page-sheet {
          box-shadow: none !important;
          border: none !important;
          border-radius: 0 !important;
          margin: 0 !important;
          padding: 10mm 12mm 10mm 12mm !important;
          width: 100% !important;
          max-width: 100% !important;
          min-height: 270mm !important;
          max-height: none !important;
          page-break-after: always !important;
          break-after: page !important;
          display: flex !important;
          flex-direction: column !important;
          justify-content: space-between !important;
          -webkit-print-color-adjust: exact !important;
          print-color-adjust: exact !important;
        }
        .tq-page-sheet:last-child {
          page-break-after: auto !important;
          break-after: auto !important;
        }
        .tq-q-item {
          page-break-inside: avoid !important;
          break-inside: avoid !important;
          -webkit-print-color-adjust: exact !important;
          print-color-adjust: exact !important;
        }
      }
    </style>
    <div class="modal wide" style="display:flex; flex-direction:column; max-width:980px;">
      <div class="mhdr">
        <div class="mhdr-ico" style="background:var(--brand-bg); color:var(--brand);">
          <i class="fas fa-file-export"></i>
        </div>
        <div>
          <div class="mhdr-title" id="tqExportModalTitle">تصدير أسئلة التاسك وإجاباتها</div>
          <div class="mhdr-sub" id="tqExportModalSub">تصدير أسئلة الاختبار بصيغ متعددة (PDF، صورة، Excel) مع التحكم في إظهار أو إخفاء الإجابات</div>
        </div>
        <div class="mclose" onclick="closeOv('taskQuestionsExportOv')"><i class="fas fa-times"></i></div>
      </div>

      <div class="mbody" style="flex:1 1 auto; min-height:0; overflow-y:auto; padding:20px; display:flex; flex-direction:column; gap:16px;">
        <!-- Control Options Box -->
        <div class="tq-export-box">
          <!-- Answer Visibility Toggle -->
          <div style="display:flex; flex-direction:column; gap:6px;">
            <label style="font-weight:800; font-size:.88rem; color:var(--t1);">
              <i class="fas fa-eye-slash" style="color:var(--brand); margin-left:4px;"></i> حالة الإجابات:
            </label>
            <div class="tq-mode-toggle">
              <button type="button" class="tq-mode-btn active" id="btnTqShowAns" onclick="setQuestionsAnswerVisibility(true)">
                <i class="fas fa-check-circle" style="color:#10b981;"></i> إظهار الإجابات (نموذج إجابة)
              </button>
              <button type="button" class="tq-mode-btn" id="btnTqHideAns" onclick="setQuestionsAnswerVisibility(false)">
                <i class="fas fa-eye-slash" style="color:#ef4444;"></i> إخفاء الإجابات (ورقة اختبار)
              </button>
            </div>
          </div>

          <!-- Page Orientation -->
          <div style="display:flex; flex-direction:column; gap:6px;">
            <label style="font-weight:800; font-size:.88rem; color:var(--t1);">
              <i class="fas fa-file-pdf" style="color:var(--brand); margin-left:4px;"></i> اتجاه الصفحة للتصدير:
            </label>
            <select id="tqOrientation" onchange="handleTqOrientationChange()"
              style="padding:9px 12px; border:1px solid var(--bdr); border-radius:10px; background:#fff; font-size:.87rem; font-weight:700; outline:none; cursor:pointer;">
              <option value="portrait" selected>طولي (Portrait) - ورقة A4 قياسية</option>
              <option value="landscape">عرضي (Landscape)</option>
            </select>
          </div>

          <!-- Exam Header & Degrees Checkboxes -->
          <div style="display:flex; flex-direction:column; justify-content:center; gap:8px;">
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:.87rem; font-weight:700; color:var(--t1);">
              <input type="checkbox" id="tqShowExamHeader" onchange="renderQuestionsExportPreview()" checked
                style="accent-color:var(--brand); width:18px; height:18px; cursor:pointer;">
              <span>ترويسة الاختبار (اسم الطالب، الفصل، التاريخ)</span>
            </label>
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:.87rem; font-weight:700; color:var(--t1);">
              <input type="checkbox" id="tqShowDegrees" onchange="renderQuestionsExportPreview()" checked
                style="accent-color:var(--brand); width:18px; height:18px; cursor:pointer;">
              <span>عرض درجات الأسئلة</span>
            </label>
          </div>
        </div>

        <!-- Live Preview Container -->
        <div class="tq-preview-viewport" id="tqPreviewViewport">
          <div id="tqPaperExport" style="display:flex; flex-direction:column; align-items:center; gap:20px; width:100%;">
            <!-- Rendered dynamically -->
          </div>
        </div>
      </div>

      <div class="mfoot" style="justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div style="font-size:.82rem; color:var(--t2); font-weight:700;" id="tqStatsText">جاهز للتصدير</div>
        <div class="ov-mfoot-btns" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
          <button class="btn btn-g" onclick="closeOv('taskQuestionsExportOv')" style="order:99;">إغلاق</button>
          <button class="btn-export btn-msg" onclick="copyQuestionsText()"><i class="fas fa-copy"></i> نسخ كنص</button>
          <button class="btn-export btn-excel" onclick="exportQuestionsExcel()"><i class="fas fa-file-excel"></i> Excel (.xlsx)</button>
          <button class="btn-export btn-pdf" onclick="exportQuestionsPDF()"><i class="fas fa-file-pdf"></i> PDF</button>
          <button class="btn-export btn-print" onclick="printQuestionsExam()" style="background:#0284c7; color:#fff;"><i class="fas fa-print"></i> طباعة</button>
          <button class="btn-export btn-img" onclick="exportQuestionsImage()"><i class="fas fa-file-image"></i> صورة</button>
        </div>
      </div>
    </div>
  </div>

  <div class="tc" id="tc"></div>







  <!-- ══ SCRIPT ════════════════════════════════════════════════════ -->



  <script>
    // ─── Top-level Timing & Date Helpers ──────────────────────────────
    window.parseDateSafe = function(iso) {
      if (!iso) return null;
      let s = String(iso).trim();
      if (!s) return null;
      if (s.indexOf('T') === -1 && s.indexOf(' ') !== -1) s = s.replace(' ', 'T');
      const d = new Date(s);
      return isNaN(d.getTime()) ? null : d;
    };

    window.fmtDate = function(iso) {
      const d = window.parseDateSafe(iso);
      if (!d) return '—';
      return d.toLocaleDateString('ar-EG', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    };

    window.toLocalDT = function(iso) {
      const d = window.parseDateSafe(iso);
      if (!d) return '';
      const p = n => String(n).padStart(2, '0');
      return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
    };

    window.toLocalDateOnly = function(iso) {
      const d = window.parseDateSafe(iso);
      if (!d) return '';
      const p = n => String(n).padStart(2, '0');
      return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
    };

    window.isEndDateOnly = function(iso) {
      const d = window.parseDateSafe(iso);
      if (!d) return false;
      return d.getHours() === 23 && d.getMinutes() === 59;
    };

    window.syncDateOnlyFromDateTime = function() {
      const el = document.getElementById('fEnd');
      const target = document.getElementById('fEndDateOnly');
      if (el && target && el.value) {
        target.value = el.value.split('T')[0];
      }
    };

    window.syncDateTimeFromDateOnly = function() {
      const el = document.getElementById('fEndDateOnly');
      const target = document.getElementById('fEnd');
      if (el && target && el.value) {
        target.value = `${el.value}T23:59`;
      }
    };

    window.toggleEndDateMode = function(syncFromCurrent = true) {
      if (document.getElementById('fNoDeadline')?.checked) return;
      const dateMode = document.getElementById('fEndDateMode')?.checked;
      const endInput = document.getElementById('fEnd');
      const endDateOnly = document.getElementById('fEndDateOnly');
      const note = document.getElementById('endModeNote');
      if (syncFromCurrent) {
        if (dateMode) window.syncDateOnlyFromDateTime();
        else window.syncDateTimeFromDateOnly();
      }
      if (endInput) endInput.style.display = dateMode ? 'none' : '';
      if (endDateOnly) endDateOnly.style.display = dateMode ? '' : 'none';
      if (note) {
        note.textContent = dateMode
          ? 'سيُغلق الامتحان تلقائياً في نهاية هذا اليوم.'
          : 'سيُغلق الامتحان في الساعة التي تحددها هنا.';
      }
      window.updateTimingSummaryBadge();
    };

    window.toggleNoDeadline = function(sync = false) {
      const noDeadline = document.getElementById('fNoDeadline')?.checked;
      const block = document.getElementById('deadlineBlock');
      const endDateMode = document.getElementById('fEndDateMode');
      const note = document.getElementById('endModeNote');

      if (sync && !noDeadline) {
        window.toggleEndDateMode(false);
      }
      if (block) block.classList.toggle('is-disabled', !!noDeadline);
      if (endDateMode) endDateMode.disabled = !!noDeadline;

      if (note && noDeadline) {
        note.textContent = 'لن يكون هناك آخر موعد، وستظل التاسك متاحة بعد البداية.';
      } else if (note) {
        note.textContent = document.getElementById('fEndDateMode')?.checked
          ? 'سيُغلق الامتحان تلقائياً في نهاية هذا اليوم.'
          : 'سيُغلق الامتحان في الساعة التي تحددها هنا.';
      }
      window.updateTimingSummaryBadge();
    };

    window.getNormalizedEndDateValue = function() {
      if (document.getElementById('fNoDeadline')?.checked) return '';
      const dateMode = document.getElementById('fEndDateMode')?.checked;
      if (dateMode) {
        const dateOnly = document.getElementById('fEndDateOnly')?.value;
        return dateOnly ? `${dateOnly}T23:59` : '';
      }
      return document.getElementById('fEnd')?.value || '';
    };

    window.applyDuePreset = function(days) {
      if (document.getElementById('fNoDeadline')?.checked) return;
      const startVal = document.getElementById('fStart')?.value;
      const base = startVal ? new Date(startVal) : new Date();
      if (Number.isNaN(base.getTime())) return;

      const target = new Date(base.getTime());
      target.setDate(target.getDate() + days);
      const p = n => String(n).padStart(2, '0');
      const dateOnly = `${target.getFullYear()}-${p(target.getMonth() + 1)}-${p(target.getDate())}`;

      const fEco = document.getElementById('fEndDateOnly');
      const fE = document.getElementById('fEnd');
      if (fEco) fEco.value = dateOnly;
      if (fE) fE.value = `${dateOnly}T23:59`;

      const note = document.getElementById('endModeNote');
      if (document.getElementById('fEndDateMode')?.checked && note) {
        note.textContent = 'سيُغلق الامتحان تلقائياً في نهاية هذا اليوم.';
      }
      window.updateTimingSummaryBadge();
    };

    window.setDefaultDates = function() {
      const now = new Date();
      const p = n => String(n).padStart(2, '0');
      const f = d => `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
      const fd = d => `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
      const weekLater = new Date(now.getTime() + 7 * 24 * 60 * 60 * 1000);

      const fS = document.getElementById('fStart');
      const fE = document.getElementById('fEnd');
      const fEco = document.getElementById('fEndDateOnly');
      if (fS) fS.value = f(now);
      if (fE) fE.value = f(weekLater);
      if (fEco) fEco.value = fd(weekLater);

      window.updateTimingSummaryBadge();
    };

    window.updateTimingSummaryBadge = function() {
      const pill = document.getElementById('timingSummaryPill');
      const text = document.getElementById('timingSummaryText');
      if (!text) return;

      const noDeadline = document.getElementById('fNoDeadline')?.checked;
      if (noDeadline) {
        text.textContent = 'بدون تاريخ إنهاء (التاسك مفتوحة بدون حد أقصى)';
        return;
      }

      const startVal = document.getElementById('fStart')?.value;
      const endVal = window.getNormalizedEndDateValue();

      let str = '';
      if (startVal) {
        const sDate = window.parseDateSafe(startVal);
        if (sDate && !isNaN(sDate.getTime())) {
          str += 'تبدأ في: ' + sDate.toLocaleDateString('ar-EG', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
        }
      } else {
        str += 'تبدأ فوراً';
      }

      if (endVal) {
        const eDate = window.parseDateSafe(endVal);
        if (eDate && !isNaN(eDate.getTime())) {
          str += ' — تنتهي في: ' + eDate.toLocaleDateString('ar-EG', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
        }
      } else {
        str += ' — بدون موعد إغلاق محدد';
      }

      text.textContent = str;
    };

    window.toggleTimerRowDisplay = function(checked) {
      const r = document.getElementById('timerRow');
      if (r) r.style.display = checked ? 'block' : 'none';
    };

    window.applySimplePreset = function(days) {
      window.applyDuePreset(days);
      window.updateTimingSummaryBadge();
    };

    window.setNoDeadlineMode = function() {
      const nd = document.getElementById('fNoDeadline');
      if (nd) {
        nd.checked = true;
        window.toggleNoDeadline(true);
      }
      window.updateTimingSummaryBadge();
    };

    window.onEndDateTimeChange = function() {
      window.syncDateOnlyFromDateTime();
      window.updateTimingSummaryBadge();
    };

    // ─── PHP config ────────────────────────────────────────────────



    const CFG = {



      uncleId: <?php echo (int) $uncleId; ?>,



      uncleName: <?php echo json_encode($uncleName); ?>,



      role: <?php echo json_encode($uncleRole); ?>,



      churchType: <?php echo json_encode($churchType); ?>,



      isYouth: <?php echo $isYouth ? 'true' : 'false'; ?>,

      activeClass: <?php echo json_encode($activeClass); ?>,

      churchName: <?php echo json_encode($churchName); ?>

    };



    const PEOPLE = CFG.isYouth ? 'الشباب' : 'الأطفال';



    const LETTERS = ['أ', 'ب', 'ج', 'د', 'هـ'];







    // Resolve api.php path using absolute root path (or /testing/api.php in testing environment)

    const API = (() => {

      return window.location.pathname.indexOf('/testing/') !== -1 ? '/testing/api.php' : '/api.php';

    })();







    // ─── State ─────────────────────────────────────────────────────



    let tasks = [];



    let allClasses = [];



    let classStuCache = {};



    let curFilter = 'all';



    let editId = null;



    let delId = null;



    let curStep = 1;



    let qCnt = 0;







    // ─── Boot ──────────────────────────────────────────────────────



    document.addEventListener('DOMContentLoaded', async () => {



      setDefaultDates();



      await loadClasses();



      await loadTasks();

      // If inside an iframe, configure postMessage integration
      if (window.self !== window.top) {
        document.body.classList.add('body-iframe-view');
        const backBtn = document.querySelector('.tb-back');
        if (backBtn) {
          backBtn.removeAttribute('href');
          backBtn.style.cursor = 'pointer';
          backBtn.onclick = (e) => {
            e.preventDefault();
            window.parent.postMessage({ action: 'closeTasksModal' }, '*');
          };
        }
        const backToDashLink = document.querySelector('a.hero-link');
        if (backToDashLink) {
          backToDashLink.style.display = 'none';
        }
      }

      // Auto-open specific task details if passed in query param
      const urlParams = new URLSearchParams(window.location.search);
      const taskId = urlParams.get('taskId');
      if (taskId) {
        setTimeout(() => {
          openDetail(parseInt(taskId, 10));
        }, 300);
      }
      const action = urlParams.get('action');
      if (action === 'create' || action === 'new') {
        setTimeout(() => {
          openCreate();
        }, 300);
      }

      overlayOnBg();



      document.addEventListener('keydown', e => {



        if (e.key === 'Escape') ['confOv', 'detailOv', 'createOv'].forEach(id => {



          if (document.getElementById(id).classList.contains('open')) closeOv(id);



        });



      });



    });







    // ─── API helper ────────────────────────────────────────────────



    async function api(action, extra = {}, isRetry = false) {
      const fd = new FormData();
      fd.append('action', action);

      const uncleUn = localStorage.getItem('uncleUsername');
      const uncleId = localStorage.getItem('uncleId');
      const churchCc = localStorage.getItem('churchCode');
      const churchId = localStorage.getItem('churchId');
      const devId = localStorage.getItem('devViewChurchId');

      if (uncleUn && !fd.has('username')) fd.append('username', uncleUn);
      if (uncleId && !fd.has('uncle_id')) fd.append('uncle_id', uncleId);
      if (churchCc && !fd.has('church_code')) fd.append('church_code', churchCc);
      if (churchId && !fd.has('church_id')) fd.append('church_id', churchId);
      if (devId && devId > 0 && !fd.has('dev_override_church_id')) fd.append('dev_override_church_id', devId);

      Object.entries(extra).forEach(([k, v]) => { 
        if (v !== null && v !== undefined) {
          fd.append(k, typeof v === 'object' && k !== 'action' ? JSON.stringify(v) : v);
        }
      });

      const r = await fetch(API, { method: 'POST', body: fd, credentials: 'include' });
      if (!r.ok) throw new Error('HTTP ' + r.status);
      const d = await r.json();

      if (!isRetry && d && d.success === false && d.message && (d.message.includes('غير مصرح') || d.message.includes('تسجيل الدخول') || d.message.includes('No credentials'))) {
        if (uncleUn || churchCc) {
          try {
            const restoreFd = new FormData();
            restoreFd.append('action', 'restore_session');
            if (uncleUn) restoreFd.append('username', uncleUn);
            else if (churchCc) restoreFd.append('church_code', churchCc);
            const restoreRes = await fetch(API, { method: 'POST', body: restoreFd, credentials: 'include' });
            const restoreData = await restoreRes.json();
            if (restoreData && restoreData.success) {
              return await api(action, extra, true);
            }
          } catch (e) {}
        }
      }

      return d;
    }







    // ─── Load classes ───────────────────────────────────────────────



    async function loadClasses() {



      try {



        const d = await api('getChurchClasses');



        if (d.success && d.classes) allClasses = d.classes.filter(c => c.is_active != 0);



      } catch (e) { console.warn('loadClasses', e); }



      buildClassSel();



    }



    function buildClassSel() {



      const c = document.getElementById('fClassContainer');



      if (!c) return;



      c.innerHTML = '';







      // Create "All Classes" checkbox



      const allWrap = document.createElement('label');



      allWrap.style = "display:flex; align-items:center; gap:8px; cursor:pointer; font-size:.87rem; color:var(--t1); margin-bottom: 4px; padding: 4px 6px; border-radius: 4px; transition: background 0.2s;";



      allWrap.onmouseover = function () { this.style.background = 'var(--bg)'; };



      allWrap.onmouseout = function () { this.style.background = 'transparent'; };







      const allChk = document.createElement('input');



      allChk.type = 'checkbox';



      allChk.id = 'class_all';



      allChk.value = 'كل الفصول';



      allChk.dataset.id = '0';



      allChk.style.accentColor = 'var(--brand)';



      allChk.style.width = '16px';



      allChk.style.height = '16px';



      allChk.style.cursor = 'pointer';



      allChk.onchange = onClassCheckboxChange;







      if (CFG.activeClass === 'كل الفصول') {



        allChk.checked = true;



      }







      allWrap.appendChild(allChk);



      allWrap.appendChild(document.createTextNode(' كل الفصول'));



      c.appendChild(allWrap);







      // Create individual class checkboxes



      allClasses.forEach(cl => {



        const wrap = document.createElement('label');



        wrap.style = "display:flex; align-items:center; gap:8px; cursor:pointer; font-size:.87rem; color:var(--t1); margin-bottom: 4px; padding: 4px 6px; border-radius: 4px; transition: background 0.2s;";



        wrap.onmouseover = function () { this.style.background = 'var(--bg)'; };



        wrap.onmouseout = function () { this.style.background = 'transparent'; };







        const chk = document.createElement('input');



        chk.type = 'checkbox';



        chk.className = 'class-checkbox';



        chk.value = cl.arabic_name;



        chk.dataset.id = cl.id;



        chk.style.accentColor = 'var(--brand)';



        chk.style.width = '16px';



        chk.style.height = '16px';



        chk.style.cursor = 'pointer';



        chk.onchange = onClassCheckboxChange;







        if (CFG.activeClass && cl.arabic_name === CFG.activeClass) {



          chk.checked = true;



          allChk.checked = false; // ensure all classes deselects



        }







        wrap.appendChild(chk);



        wrap.appendChild(document.createTextNode(' ' + cl.arabic_name));



        c.appendChild(wrap);



      });







      // Default fallback check



      const checkedAny = Array.from(c.querySelectorAll('input[type="checkbox"]:checked')).length > 0;



      if (!checkedAny) {



        allChk.checked = true;



      }



    }







    async function onClassCheckboxChange(e) {



      const target = e.target;



      if (target.id === 'class_all' && target.checked) {



        // Deselect all other checkboxes



        document.querySelectorAll('.class-checkbox').forEach(cb => cb.checked = false);



      } else if (target.className === 'class-checkbox' && target.checked) {



        // Deselect "All Classes"



        const all = document.getElementById('class_all');



        if (all) all.checked = false;



      }







      // Ensure at least one is checked if possible



      const checked = document.querySelectorAll('#fClassContainer input[type="checkbox"]:checked');



      if (checked.length === 0) {



        const all = document.getElementById('class_all');



        if (all) all.checked = true;



      }







      await onClassChange();



    }

    function getTaskClassNames(t) {
      if (!t) return ['كل الفصول'];
      const classIds = (t.class_ids || '').split(',').map(s => s.trim()).filter(Boolean);
      if (classIds.includes('0') || (!classIds.length && (!t.class_id || parseInt(t.class_id) === 0))) {
        return ['كل الفصول'];
      }
      if (t.class_names && Array.isArray(t.class_names) && t.class_names.length) {
        return t.class_names;
      }
      const names = [];
      if (classIds.length && typeof allClasses !== 'undefined' && allClasses.length) {
        classIds.forEach(id => {
          const found = allClasses.find(c => String(c.id) === String(id));
          if (found && !names.includes(found.arabic_name)) {
            names.push(found.arabic_name);
          }
        });
      }
      if (!names.length && t.class_name) {
        t.class_name.split('،').map(s => s.trim().replace(/^,\s*/, '')).filter(Boolean).forEach(cn => {
          if (!names.includes(cn)) names.push(cn);
        });
      }
      return names.length ? names : ['كل الفصول'];
    }

    function getTaskStudents(t) {
      if (!t) return [];
      const classNames = getTaskClassNames(t);
      if (classNames.includes('كل الفصول')) {
        return classStuCache['كل الفصول'] || [];
      }
      let combined = [];
      const seen = new Set();
      classNames.forEach(cn => {
        const list = classStuCache[cn] || [];
        list.forEach(s => {
          if (!seen.has(s.id)) {
            seen.add(s.id);
            combined.push(s);
          }
        });
      });
      return combined;
    }

    // ─── Load tasks ─────────────────────────────────────────────────

    async function loadTasks() {
      try {
        const extra = {};
        if (CFG.activeClass && CFG.activeClass !== 'كل الفصول') extra.class_name = CFG.activeClass;
        const d = await api('getTasks', extra);
        if (d.success) tasks = d.tasks || [];
        else showToast(d.message || 'فشل تحميل التاسكات', 'err');
      } catch (e) { showToast('خطأ في الاتصال', 'err'); }
      try {
        await loadStudents('كل الفصول');
      } catch (e) {
        console.error('Failed to load students:', e);
      }

      renderGrid();

      updateStats();

    }







    // ─── Load students ──────────────────────────────────────────────



    async function loadStudents(cls) {



      if (classStuCache[cls]) return classStuCache[cls];



      try {



        const d = await api('getData');



        if (d.success) {



          const all = d.data || d.allStudents || [];



          classStuCache['كل الفصول'] = [];



          all.forEach(s => {



            const c = s['الفصل'] || s.class || '';



            const sId = parseInt(s['_studentId'] || s.id || s.student_id || s._studentId || s['معرف'] || s['id_student'] || 0);
            const student = { id: sId, name: s['الاسم'] || s.name || '', photo: s['صورة'] || s.image_url || s.photo || '' };



            if (!classStuCache[c]) classStuCache[c] = [];



            classStuCache[c].push(student);



            classStuCache['كل الفصول'].push(student);



          });



        }



      } catch (e) { }



      return classStuCache[cls] || [];



    }

    function getStudentAvatarHtml(photo, name, size = '28px') {
      if (photo) {
        return `<img src="${esc(photo)}" style="width:${size};height:${size};border-radius:50%;object-fit:cover;flex-shrink:0;border:1px solid var(--bdr);" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';" />` +
          `<span style="display:none;width:${size};height:${size};border-radius:50%;background:var(--brand-bg);color:var(--brand);align-items:center;justify-content:center;font-size:0.75rem;font-weight:700;flex-shrink:0;border:1px solid var(--bdr);"><i class="fas fa-user"></i></span>`;
      }
      return `<span style="display:inline-flex;width:${size};height:${size};border-radius:50%;background:var(--brand-bg);color:var(--brand);align-items:center;justify-content:center;font-size:0.75rem;font-weight:700;flex-shrink:0;border:1px solid var(--bdr);"><i class="fas fa-user"></i></span>`;
    }







    // ─── Render tasks grid ──────────────────────────────────────────



    function statusOf(t) {
      if (t.status === 'draft') return { key: 'draft', cls: 's-draft', label: 'Draft', acc: 'warn' };
      return { key: 'published', cls: 's-published', label: 'منشور', acc: 'ok' };
    }

    function getCustomFilters() {
      const customFilters = new Set();
      tasks.forEach(t => {
        if (t.group_name) {
          t.group_name.split(',').forEach(g => {
            const trimmed = g.trim();
            if (trimmed) customFilters.add(trimmed);
          });
        }
      });
      return Array.from(customFilters);
    }

    function renderFilterTabs() {
      const container = document.getElementById('filterTabsContainer');
      if (!container) return;

      let html = `<div class="ftab ${curFilter === 'all' ? 'active' : ''}" onclick="setFilter('all',this)">الكل</div>`;
      html += `<div class="ftab ${curFilter === 'draft' ? 'active' : ''}" onclick="setFilter('draft',this)">Draft</div>`;

      const customFilters = getCustomFilters();
      customFilters.forEach(f => {
        html += `<div class="ftab ${curFilter === 'custom_' + f ? 'active' : ''}" onclick="setFilter('custom_${f}',this)">${f}</div>`;
      });

      container.innerHTML = html;
    }

    window.selectedClassifications = [];

    function renderActiveTags() {
      const container = document.getElementById('activeClassificationsContainer');
      if (!container) return;
      if (window.selectedClassifications.length === 0) {
        container.innerHTML = '<span style="font-size:0.8rem; color:var(--t3); font-style:italic;">لا توجد تصنيفات محددة</span>';
        return;
      }
      let html = '';
      window.selectedClassifications.forEach(tag => {
        html += `
      <span class="badge" style="display:inline-flex; align-items:center; gap:6px; background:var(--brand-bg); border:1px solid var(--brand-l); padding:4px 10px; border-radius:14px; font-size:0.8rem; color:var(--brand); font-weight:700;">
        ${esc(tag)}
        <i class="fas fa-times" onclick="removeClassification('${esc(tag)}')" style="cursor:pointer; font-size:0.75rem; opacity:0.7;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.7'"></i>
      </span>
    `;
      });
      container.innerHTML = html;
    }

    function populateClassificationSelector() {
      const selector = document.getElementById('classificationSelector');
      if (!selector) return;

      selector.innerHTML = '<option value="" disabled selected>— اختر تصنيفاً لتحديده —</option>';

      const allFilters = getCustomFilters();
      allFilters.forEach(f => {
        if (!window.selectedClassifications.includes(f)) {
          selector.innerHTML += `<option value="${esc(f)}">${esc(f)}</option>`;
        }
      });
    }

    function handleClassificationSelect(el) {
      const val = el.value.trim();
      if (!val) return;
      if (!window.selectedClassifications.includes(val)) {
        window.selectedClassifications.push(val);
        renderActiveTags();
        populateClassificationSelector();
      }
      el.value = '';
    }

    function addNewClassificationPrompt() {
      const name = prompt("أدخل اسم التصنيف الجديد:");
      if (!name) return;
      const trimmed = name.trim();
      if (!trimmed) return;
      if (!window.selectedClassifications.includes(trimmed)) {
        window.selectedClassifications.push(trimmed);
        renderActiveTags();
        populateClassificationSelector();
      }
    }

    function removeClassification(tag) {
      window.selectedClassifications = window.selectedClassifications.filter(x => x !== tag);
      renderActiveTags();
      populateClassificationSelector();
    }

    function setFilter(f, el) {



      curFilter = f;



      document.querySelectorAll('.ftab').forEach(t => t.classList.remove('active'));



      el.classList.add('active');



      renderGrid();



    }



    function renderOverlappingAvatars(students, size = '26px', bgType = 'bg3', maxShow = 3) {
      if (!students || !students.length) {
        return `<span style="font-size:0.7rem; color:var(--t4); font-style:italic;">لا يوجد</span>`;
      }
      const visible = students.slice(0, maxShow);
      const extraCount = students.length - maxShow;

      let html = `<div style="display:flex; align-items:center; flex-wrap:nowrap; direction:rtl; padding-right:4px;">`;
      visible.forEach((s, idx) => {
        let borderStyle = 'border: 2px solid var(--bg3) !important;';
        if (bgType === 'ok') borderStyle = 'border: 2px solid var(--ok-bg) !important;';
        else if (bgType === 'err') borderStyle = 'border: 2px solid var(--err-bg) !important;';
        else if (bgType === 'bg') borderStyle = 'border: 2px solid var(--bg) !important;';

        const avatarHtml = getStudentAvatarHtml(s.photo, s.name, size);
        const styledAvatarHtml = avatarHtml
          .replace(/border:[^;"]+/g, borderStyle)
          .replace(/border-radius:50%/g, 'border-radius:50%; box-shadow:none');

        html += `
      <div class="uncle-avatar-wrap" style="margin-left:-10px; z-index:${maxShow - idx}; position:relative;">
        ${styledAvatarHtml}
        <div class="uncle-tooltip">${esc(s.name)}</div>
      </div>
    `;
      });

      if (extraCount > 0) {
        let extraBg = 'var(--bg3)';
        let extraBorder = 'var(--bdr)';
        if (bgType === 'ok') { extraBg = '#a7f3d0'; extraBorder = '#6ee7b7'; }
        else if (bgType === 'err') { extraBg = '#fecaca'; extraBorder = '#fca5a5'; }

        html += `
      <div style="margin-left:-10px; z-index:0; width:${size}; height:${size}; border-radius:50%; background:${extraBg}; border:2px solid ${extraBorder}; color:var(--t2); display:flex; align-items:center; justify-content:center; font-size:0.65rem; font-weight:800; font-family:'Cairo'; flex-shrink:0;">
        +${extraCount}
      </div>
    `;
      }

      html += `</div>`;
      return html;
    }

    function toggleDetailMenu(e) {
      if (e) e.stopPropagation();
      const menu = document.getElementById('detailMenuDropdown');
      if (menu) {
        menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
      }
    }

    function toggleTaskCardMenu(e, taskId) {
      if (e) e.stopPropagation();
      document.querySelectorAll('.task-card-menu').forEach(m => {
        if (m.id !== `taskCardMenu_${taskId}`) m.style.display = 'none';
      });
      const menu = document.getElementById(`taskCardMenu_${taskId}`);
      if (menu) {
        menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
      }
    }

    document.addEventListener('click', () => {
      document.querySelectorAll('.task-card-menu').forEach(m => m.style.display = 'none');
      const dMenu = document.getElementById('detailMenuDropdown');
      if (dMenu) dMenu.style.display = 'none';
    });

    function renderGrid() {
      const g = document.getElementById('tGrid');

      // Update dynamic filter tabs view
      renderFilterTabs();

      let list = [];
      if (curFilter === 'all') {
        list = tasks.filter(t => t.status !== 'draft');
      } else if (curFilter === 'draft') {
        list = tasks.filter(t => t.status === 'draft');
      } else if (curFilter.startsWith('custom_')) {
        const fName = curFilter.substring(7);
        list = tasks.filter(t => {
          if (!t.group_name) return false;
          return t.group_name.split(',').map(x => x.trim()).includes(fName);
        });
      } else {
        list = tasks.filter(t => statusOf(t).key === curFilter);
      }

      if (!list.length) {
        g.innerHTML = `<div class="empty"><div class="empty-ico"><i class="fas fa-clipboard-list"></i></div><div class="empty-t">لا توجد تاسكات</div><div class="empty-s">اضغط "تاسك جديد" لإنشاء أول اختبار</div><button class="btn btn-p" onclick="openCreate()"><i class="fas fa-plus"></i> إنشاء تاسك</button></div>`;
        return;
      }

      g.innerHTML = list.map((t, idx) => {
        const si = statusOf(t);
        const qs = (t.questions || []).length;
        const subs = (t.submissions || []).length;
        const tc = (t.submissions || []).reduce((a, s) => a + (parseInt(s.coupons_awarded) || 0), 0);
        const pendingOpen = (t.submissions || []).filter(s => {
          const hasPending = s.pending_open_grading ?? s.has_open_pending;
          return hasPending;
        }).length;

        const taskClasses = getTaskClassNames(t);
        const taskClassesLabel = taskClasses.join('، ');
        const studentsInClass = getTaskStudents(t);
        const answeredIds = (t.submissions || []).map(s => parseInt(s.student_id));
        const answeredStudents = studentsInClass.filter(s => answeredIds.includes(parseInt(s.id)));
        const notAnsweredStudents = studentsInClass.filter(s => !answeredIds.includes(parseInt(s.id)));

        const answeredAvatarsHtml = renderOverlappingAvatars(answeredStudents, '26px', 'bg3', 3);
        const notAnsweredAvatarsHtml = renderOverlappingAvatars(notAnsweredStudents, '26px', 'bg3', 3);

        const totalToAnswer = studentsInClass.length;
        const progressPercent = totalToAnswer > 0 ? Math.round((answeredStudents.length / totalToAnswer) * 100) : 0;

        return `<div class="tcard" onclick="openDetail(${t.id})" style="animation-delay:${idx * 40}ms; border-radius: var(--r-lg); overflow: hidden; background: var(--bg-card); border: 1.5px solid var(--bdr); transition: all 0.2s; box-shadow: var(--shadow-sm); cursor: pointer;" onmouseover="this.style.transform='translateY(-2px)'; this.style.borderColor='var(--brand-l)';" onmouseout="this.style.transform='none'; this.style.borderColor='var(--bdr)';">
      
      <div style="padding: 16px; display: flex; flex-direction: column; gap: 12px;">
        
        <!-- Header row -->
        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
          <div style="font-size: 0.95rem; font-weight: 800; color: var(--t1); line-height: 1.4; flex: 1; text-align: right;">${esc(t.title)}</div>
          <div style="display: flex; align-items: center; gap: 6px; flex-shrink: 0;">
            ${si.key === 'draft' ? `<span style="background:var(--warn-bg); color:var(--warn); font-size:0.7rem; font-weight:700; padding:2px 8px; border-radius:4px; border:1px solid rgba(245,158,11,0.25);">Draft</span>` : ''}
            ${pendingOpen ? `<span style="background:var(--brand-bg); color:var(--brand); font-size:0.7rem; font-weight:700; padding:2px 8px; border-radius:4px; border:1px solid rgba(124,58,237,0.2);"><i class="fas fa-pen-nib"></i> ${pendingOpen} تصحيح</span>` : ''}
            
            <!-- 3 Dots Action Menu -->
            <div style="position: relative;" onclick="event.stopPropagation()">
              <button type="button" onclick="toggleTaskCardMenu(event, ${t.id})" style="background: transparent; border: none; color: var(--t3); width: 28px; height: 28px; border-radius: 6px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; transition: 0.2s;" onmouseover="this.style.background='var(--bg3)'; this.style.color='var(--t1)';" onmouseout="this.style.background='transparent'; this.style.color='var(--t3)';">
                <i class="fas fa-ellipsis-v"></i>
              </button>
              <div id="taskCardMenu_${t.id}" class="task-card-menu" style="display: none; position: absolute; left: 0; top: 100%; margin-top: 4px; z-index: 100; min-width: 140px; background: var(--surface-1, var(--bg2, #ffffff)); border: 1px solid var(--border, var(--bdr, #e2e8f0)); border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,0.15); overflow: hidden; font-family: 'Cairo', sans-serif;">
                <button onclick="toggleTaskCardMenu(event, ${t.id}); openExportTaskQuestions(${t.id})" style="width: 100%; padding: 8px 12px; border: none; background: transparent; color: var(--t1); font-size: 0.8rem; font-weight: 700; text-align: right; cursor: pointer; display: flex; align-items: center; gap: 8px;" onmouseover="this.style.background='var(--bg3)';" onmouseout="this.style.background='transparent';">
                  <i class="fas fa-file-export" style="color: var(--brand);"></i> تصدير الأسئلة
                </button>
                <button onclick="toggleTaskCardMenu(event, ${t.id}); openEdit(${t.id})" style="width: 100%; padding: 8px 12px; border: none; background: transparent; color: var(--t1); font-size: 0.8rem; font-weight: 700; text-align: right; cursor: pointer; display: flex; align-items: center; gap: 8px;" onmouseover="this.style.background='var(--bg3)';" onmouseout="this.style.background='transparent';">
                  <i class="fas fa-pen" style="color: var(--brand);"></i> تعديل التاسك
                </button>
                <button onclick="toggleTaskCardMenu(event, ${t.id}); openConf(${t.id})" style="width: 100%; padding: 8px 12px; border: none; background: transparent; color: var(--err); font-size: 0.8rem; font-weight: 700; text-align: right; cursor: pointer; display: flex; align-items: center; gap: 8px;" onmouseover="this.style.background='var(--err-bg)';" onmouseout="this.style.background='transparent';">
                  <i class="fas fa-trash"></i> حذف التاسك
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Sub/Meta details -->
        <div style="display: flex; flex-wrap: wrap; gap: 10px; font-size: 0.72rem; color: var(--t3); border-bottom: 1px dashed var(--bdr); padding-bottom: 10px; direction: rtl;">
          <span style="display: inline-flex; align-items: center; gap: 4px;"><i class="fas fa-users" style="color:var(--brand);"></i> ${esc(taskClassesLabel)}</span>
          <span style="display: inline-flex; align-items: center; gap: 4px;"><i class="far fa-clock" style="color:var(--brand);"></i> ${parseInt(t.no_deadline || 0) ? 'بدون موعد' : fmtDate(t.end_date)}</span>
          <span style="display: inline-flex; align-items: center; gap: 4px;"><i class="fas fa-question-circle"></i> ${qs} سؤال (${t.total_degree || 0} درجة)</span>
        </div>

        <!-- Overlapping Kids Avatars row -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; align-items: center; background: var(--bg3); padding: 8px 12px; border-radius: 8px; border: 1px solid var(--bdr); direction: rtl;">
          <div>
            <div style="font-size: 0.68rem; font-weight: 700; color: var(--t2); margin-bottom: 4px; display: flex; justify-content: space-between; align-items: center; direction: rtl;">
              <span>أجابوا (${answeredStudents.length})</span>
              <span style="color: var(--brand); font-size: 0.65rem;">${progressPercent}%</span>
            </div>
            ${answeredAvatarsHtml}
          </div>
          <div style="border-right: 1px solid var(--bdr); padding-right: 12px;">
            <div style="font-size: 0.68rem; font-weight: 700; color: var(--t2); margin-bottom: 4px; text-align: right;">لم يجيبوا (${notAnsweredStudents.length})</div>
            ${notAnsweredAvatarsHtml}
          </div>
        </div>

      </div>

      <!-- Footer Category Tags & View Prompt -->
      <div style="display: flex; align-items: center; justify-content: space-between; background: var(--bg-hover); padding: 8px 14px; border-top: 1px solid var(--bdr); direction: rtl;">
        <div style="display: flex; gap: 4px; overflow: hidden; flex: 1; justify-content: flex-start; direction: rtl;">
          ${t.group_name ? t.group_name.split(',').map(g => `<span style="font-size:0.65rem; color:var(--brand); background:var(--brand-bg); border:1.5px solid var(--brand-l); padding:2px 8px; border-radius:10px; font-weight:700; white-space:nowrap;">${esc(g.trim())}</span>`).join('') : '<span style="font-size:0.65rem; color:var(--t4); font-style:italic;">بدون تصنيف</span>'}
        </div>
        <span style="font-size:0.72rem; color:var(--brand); font-weight:700; display:inline-flex; align-items:center; gap:4px;">
          عرض التفاصيل <i class="fas fa-chevron-left" style="font-size:0.65rem;"></i>
        </span>
      </div>

    </div>`;
      }).join('');



    }



    function updateStats() {
      const total = tasks.length;
      const active = tasks.filter(t => statusOf(t).key === 'active').length;
      const upcoming = tasks.filter(t => statusOf(t).key === 'upcoming').length;
      const drafts = tasks.filter(t => statusOf(t).key === 'draft').length;
      const tc = tasks.reduce((a, t) => a + (t.submissions || []).reduce((b, s) => b + (parseInt(s.coupons_awarded) || 0), 0), 0);

      const setVal = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.textContent = val;
      };

      setVal('stTotal', total);
      setVal('stActive', active);
      setVal('stUpcoming', upcoming);
      setVal('stCoupons', tc);

      setVal('stTotalIframe', total);
      setVal('stDraftIframe', drafts);


    }







    // ─── Create / Edit ──────────────────────────────────────────────



    function openCreate(taskId = null) {
      editId = taskId; qCnt = 0;
      resetForm();

      if (taskId) {
        const t = tasks.find(x => x.id == taskId);
        if (t) fillForm(t);
        document.getElementById('createTitle').textContent = 'تعديل التاسك';
        hideDraftSaveBadge();
      } else {
        document.getElementById('createTitle').textContent = 'إنشاء تاسك جديد';
        const restored = restoreLocalDraft();
        if (!restored) {
          initTiers();
          hideDraftSaveBadge();
          updDeg();
        }
      }

      goStep(1);
      openOv('createOv');
    }



    async function openEdit(id) {



      // Fetch fresh detail so question_type, image_url etc. are included



      try {



        const d = await api('getTaskDetail', { task_id: id });



        if (d.success && d.task) {



          // Merge full question data into tasks cache



          const idx = tasks.findIndex(t => t.id == id);



          if (idx > -1) tasks[idx] = { ...tasks[idx], ...d.task, questions: d.task.questions };



        }



      } catch (e) { }



      openCreate(id);



    }







    function resetForm() {

      ['fTitle', 'fDesc'].forEach(id => document.getElementById(id).value = '');
      window.selectedClassifications = [];
      window._editingSpecificIds = [];
      renderActiveTags();
      populateClassificationSelector();

      document.getElementById('fAssign').value = 'all';

      document.getElementById('specRow').style.display = 'none';

      document.getElementById('fTimerOn').checked = false;

      document.getElementById('timerRow').style.display = 'none';

      document.getElementById('fNoDeadline').checked = false;

      document.getElementById('fEndDateMode').checked = false;

      document.getElementById('fShowRes').checked = true;

      document.getElementById('fShowAns').checked = true;

      document.getElementById('fShuffle').checked = false;

      if (document.getElementById('fShuffleAnswers')) document.getElementById('fShuffleAnswers').checked = false;

      document.getElementById('fReview').checked = true;

      document.getElementById('qList').innerHTML = '';

      document.getElementById('ctierList').innerHTML = '';

      setDefaultDates();

      toggleEndDateMode(false);

      toggleNoDeadline(false);

      const allChk = document.getElementById('class_all');

      if (allChk) {

        if (CFG.activeClass === 'كل الفصول') {

          allChk.checked = true;

        } else {

          allChk.checked = !CFG.activeClass;

        }

      }

      document.querySelectorAll('.class-checkbox').forEach(cb => {

        if (CFG.activeClass && cb.value === CFG.activeClass) {

          cb.checked = true;

        } else {

          cb.checked = false;

        }

      });

    }

    function fillForm(t) {

      document.getElementById('fTitle').value = t.title || '';
      window.selectedClassifications = t.group_name ? t.group_name.split(',').map(x => x.trim()).filter(Boolean) : [];
      renderActiveTags();
      populateClassificationSelector();

      document.getElementById('fDesc').value = t.description || '';

      document.getElementById('fStart').value = toLocalDT(t.start_date);

      document.getElementById('fEnd').value = toLocalDT(t.end_date);
      if (document.getElementById('fEndDateOnly')) {
        document.getElementById('fEndDateOnly').value = toLocalDateOnly(t.end_date);
      }

      document.getElementById('fNoDeadline').checked = !!parseInt(t.no_deadline || 0);

      document.getElementById('fAssign').value = t.assign_to || 'all';

      document.getElementById('fShowRes').checked = !!parseInt(t.show_result);

      document.getElementById('fShowAns').checked = !!parseInt(t.show_answers || 0);

      document.getElementById('fShuffle').checked = !!parseInt(t.shuffle);

      if (document.getElementById('fShuffleAnswers')) {
        document.getElementById('fShuffleAnswers').checked = !!parseInt(t.shuffle_answers || 0);
      }

      document.getElementById('fReview').checked = !!parseInt(t.allow_review);

      document.getElementById('fEndDateMode').checked = isEndDateOnly(t.end_date);
      toggleEndDateMode(false);
      toggleNoDeadline(false);
      if (typeof updateTimingSummaryBadge === 'function') updateTimingSummaryBadge();

      let classIds = (t.class_ids || '').split(',').map(s => s.trim()).filter(Boolean);
      if (!classIds.length && t.class_id) {
        classIds = [String(t.class_id)];
      }

      const isAllClasses = classIds.includes('0') || (classIds.length === 0 && (!t.class_id || parseInt(t.class_id) === 0));
      const allChk = document.getElementById('class_all');
      if (allChk) allChk.checked = isAllClasses;

      const classNamesList = (t.class_name || '').split('،').map(s => s.trim().replace(/^,\s*/, '')).filter(Boolean);

      document.querySelectorAll('.class-checkbox').forEach(cb => {
        const isMatch = !isAllClasses && (
          classIds.includes(String(cb.dataset.id)) ||
          classIds.includes(String(cb.value)) ||
          classNamesList.includes(cb.value)
        );
        cb.checked = !!isMatch;
      });

      if (parseInt(t.time_limit)) {

        document.getElementById('fTimerOn').checked = true;

        document.getElementById('timerRow').style.display = '';

        document.getElementById('fTimerMin').value = t.time_limit;

        document.getElementById('fTimerBeh').value = t.timer_behavior || 'submit';

      } else {

        document.getElementById('fTimerOn').checked = false;

        document.getElementById('timerRow').style.display = 'none';

        document.getElementById('fTimerMin').value = '';

        document.getElementById('fTimerBeh').value = t.timer_behavior || 'submit';

      }

      window._editingSpecificIds = (t.assign_to === 'specific' && t.specific_ids)
        ? (typeof t.specific_ids === 'string' ? JSON.parse(t.specific_ids || '[]') : t.specific_ids)
        : [];
      if (t.assign_to === 'specific') onAssignChange();

      document.getElementById('qList').innerHTML = '';

      (t.questions || []).forEach(q => addQ(q));

      document.getElementById('ctierList').innerHTML = '';

      const mx = t.coupon_matrix ? (typeof t.coupon_matrix === 'string' ? JSON.parse(t.coupon_matrix) : t.coupon_matrix) : null;

      if (mx && mx.length) {
        const milestones = convertTiersToMilestones(mx);
        milestones.forEach(m => addMilestone(m.pct, m.coupons));
      } else {
        initTiers();
      }

      updDeg();

    }







    // ─── Steps ──────────────────────────────────────────────────────



    function goStep(n) {
      [1, 2, 3].forEach(i => {
        const sp = document.getElementById(`sp${i}`);
        if (sp) sp.style.display = i === n ? '' : 'none';
        const d = document.getElementById(`sd${i}`);
        if (d) d.className = 'step' + (i < n ? ' done' : i === n ? ' active' : '');
      });

      const isEditing = !!editId;
      const prevBtn = document.getElementById('prevBtn');
      const nextBtn = document.getElementById('nextBtn');
      const pubBtn = document.getElementById('pubBtn');
      const quickSaveBtn = document.getElementById('quickSaveBtn');

      if (prevBtn) prevBtn.style.display = n > 1 ? '' : 'none';
      if (nextBtn) nextBtn.style.display = n < 3 ? '' : 'none';

      if (isEditing) {
        if (pubBtn) {
          pubBtn.innerHTML = '<i class="fas fa-save"></i> حفظ التعديلات';
          pubBtn.style.display = n === 3 ? '' : 'none';
        }
        if (quickSaveBtn) {
          quickSaveBtn.innerHTML = '<i class="fas fa-save"></i> حفظ التعديلات';
          quickSaveBtn.style.display = n < 3 ? '' : 'none';
        }
      } else {
        if (pubBtn) {
          pubBtn.innerHTML = '<i class="fas fa-paper-plane"></i> نشر';
          pubBtn.style.display = n === 3 ? '' : 'none';
        }
        if (quickSaveBtn) {
          quickSaveBtn.style.display = 'none';
        }
      }

      const stepNum = document.getElementById('stepNum');
      if (stepNum) stepNum.textContent = n;
      curStep = n;
    }
    function nextStep() {



      if (curStep === 1 && !v1()) return;



      if (curStep === 2 && !v2()) return;



      if (curStep === 2) document.getElementById('s3deg').textContent = calcDeg();



      goStep(curStep + 1);



    }



    function prevStep() { goStep(curStep - 1); }



    function v1() {



      if (!document.getElementById('fTitle').value.trim()) { showToast('أدخل العنوان', 'err'); return false; }



      const anyClassChecked = Array.from(document.querySelectorAll('#fClassContainer input[type="checkbox"]:checked')).length > 0;

      if (!anyClassChecked) { showToast('اختر الفصل / المراحل المستهدفة', 'err'); return false; }



      const s = new Date(document.getElementById('fStart').value);



      if (Number.isNaN(s.getTime())) { showToast('أدخل تاريخ البداية', 'err'); return false; }



      if (!document.getElementById('fNoDeadline').checked) {



        const e = new Date(getNormalizedEndDateValue());



        if (Number.isNaN(e.getTime()) || e <= s) { showToast('الموعد النهائي يجب أن يكون بعد البداية', 'err'); return false; }



      }



      if (document.getElementById('fTimerOn').checked && !document.getElementById('fTimerMin').value) { showToast('أدخل مدة المؤقت', 'err'); return false; }



      return true;



    }



    function v2() {



      const cards = document.querySelectorAll('.qcard');



      if (!cards.length) { showToast('أضف سؤالاً واحداً على الأقل', 'err'); return false; }



      for (const card of cards) {



        if (!card.querySelector('.qi').value.trim()) { showToast('أكمل نص الأسئلة', 'err'); return false; }



        const qt2v = card.dataset.qtype || 'mcq';



        if (qt2v === 'tf') {



          if (!card.dataset.tfAnswer && card.dataset.tfAnswer !== '0') { showToast('حدد الإجابة الصحيحة (صح أو خطأ)', 'err'); return false; }



        } else if (qt2v === 'mcq') {



          if (!card.querySelector('.oradio.ok')) { showToast('حدد الإجابة الصحيحة لكل سؤال', 'err'); return false; }



          const os = card.querySelectorAll('.oinp');



          if (os.length < 2) { showToast('كل سؤال يحتاج خيارين على الأقل', 'err'); return false; }



          let empty = false; os.forEach(o => { if (!o.value.trim()) empty = true; });



          if (empty) { showToast('أكمل نص الخيارات', 'err'); return false; }



        }



      }



      return true;



    }







    // ─── Question builder ───────────────────────────────────────────



    function addQ(data) {



      data = data || null;



      qCnt++;



      var id = 'q' + qCnt;



      var qtype = data && data.question_type ? data.question_type : 'mcq';
      var deg = (data && data.degree !== undefined && data.degree !== null) ? parseInt(data.degree) : 25;
      if (isNaN(deg) || deg < 0) deg = 0;
      var isUngraded = (deg === 0);
      var qtxt = data && data.question_text ? data.question_text : '';
      var div = document.createElement('div');
      div.className = 'qcard'; div.dataset.qid = id; div.dataset.qtype = qtype;
      if (data && data.id) div.dataset.dbId = data.id;
      var n = document.querySelectorAll('.qcard').length + 1;
      div.innerHTML =
        '<div class="qhdr">' +
        '<div class="qnum" id="qn_' + id + '">' + n + '</div>' +
        '<input class="qi" type="text" placeholder="نص السؤال\u2026" value="' + esc(qtxt) + '">' +
        '<button type="button" class="q-bible-btn" onclick="openBibleVerseModalForQ(\'' + id + '\')" title="إضافة آية من الكتاب المقدس"><i class="fas fa-bible"></i> آية</button>' +
        '<div class="qdeg"><span class="qdeg-l">الدرجة</span><input class="qdeg-i" type="number" min="0" max="100" value="' + deg + '" oninput="onQDegChange(\'' + id + '\', this)"></div>' +
        '<div class="qrm" onclick="rmQ(\'' + id + '\')"><i class="fas fa-trash"></i></div>' +
        '</div>' +
        '<div class="q-type-selector">' +
        '<button class="q-type-btn ' + (qtype === 'mcq' ? 'active' : '') + '" onclick="setQType(\'' + id + '\',\'mcq\',this)" title="اختيار من متعدد">' +
        '<i class="fas fa-list-ul"></i> متعدد</button>' +
        '<button class="q-type-btn ' + (qtype === 'tf' ? 'active-tf' : '') + '" onclick="setQType(\'' + id + '\',\'tf\',this)" title="صح أو خطأ">' +
        '<i class="fas fa-check-circle"></i> صح/خطأ</button>' +
        '<button class="q-type-btn ' + (qtype === 'open' ? 'active-open' : '') + '" onclick="setQType(\'' + id + '\',\'open\',this)" title="إجابة مفتوحة">' +
        '<i class="fas fa-pen-nib"></i> مفتوح</button>' +
        '</div>' +
        '<div class="qbody" id="qbody_' + id + '">' +
        '<div class="opts" id="opts_' + id + '"></div>' +
        '<button class="add-opt" id="addopt_' + id + '" onclick="addOpt(\'' + id + '\')"><i class="fas fa-plus"></i>إضافة خيار</button>' +
        '<div class="tf-opts" id="tfopts_' + id + '" style="display:none">' +
        '<button class="tf-btn tf-true" id="tftrue_' + id + '" onclick="setTF(\'' + id + '\',true)"><i class="fas fa-check-circle"></i> صحيح</button>' +
        '<button class="tf-btn tf-false" id="tffalse_' + id + '" onclick="setTF(\'' + id + '\',false)"><i class="fas fa-times-circle"></i> خطأ</button>' +
        '</div>' +
        '<div class="open-q-note" id="opennote_' + id + '" style="' + (qtype === 'open' ? 'display:flex;' : 'display:none;') + (isUngraded ? 'background:rgba(16,185,129,0.1);border-color:#10b981;color:#065f46;' : '') + '">' +
        (isUngraded
          ? '<i class="fas fa-check-circle" style="color:#10b981;"></i> سؤال بدون درجات (استطلاع / رأي) — لا يتطلب تصحيحاً وتظهر نتيجة التكليف فورياً للطفل'
          : '<i class="fas fa-pen-nib"></i> الطفل يكتب إجابة نصية \u2014 تُصحَّح يدوياً بعد التسليم') +
        '</div>' +
        '<div class="open-q-opts" id="openopts_' + id + '" style="margin-top:8px; display:' + (qtype === 'open' ? 'block' : 'none') + ';">' +
        '<label style="display:inline-flex;align-items:center;gap:7px;font-size:.82rem;font-weight:700;color:var(--t2);cursor:pointer;background:var(--s2);border:1px solid var(--bdr);border-radius:var(--r-sm);padding:6px 12px;user-select:none;">' +
        '<input type="checkbox" id="ungraded_' + id + '" onchange="toggleUngradedQ(\'' + id + '\', this.checked)" ' + (isUngraded ? 'checked' : '') + '> ' +
        '<i class="fas fa-comment-dots" style="color:var(--brand);"></i> سؤال استطلاعي / بدون درجات (0 درجة — لا يتطلب تصحيحاً)' +
        '</label>' +
        '</div>' +
        '</div>' +



        '<div class="q-img-section">' +



        '<button class="q-img-toggle" onclick="toggleImgSection(\'' + id + '\')">' +



        '<i class="fas fa-image"></i> إضافة صورة للسؤال (اختياري)' +



        '<i class="fas fa-chevron-down" id="imgchev_' + id + '" style="font-size:.62rem;margin-right:auto;transition:.2s;"></i>' +



        '</button>' +



        '<div class="q-img-input-wrap" id="imgwrap_' + id + '">' +



        '<div class="q-img-tabs">' +



        '<button class="q-img-tab active" onclick="switchImgTab(\'' + id + '\',\'url\',this)">' +



        '<i class="fas fa-link"></i> رابط</button>' +



        '<button class="q-img-tab" onclick="switchImgTab(\'' + id + '\',\'upload\',this)">' +



        '<i class="fas fa-upload"></i> رفع صورة</button>' +



        '</div>' +



        '<div class="q-img-tab-panel active" id="imgtab_url_' + id + '">' +



        '<div class="q-img-url-row">' +



        '<input class="q-img-url-inp" id="imgurl_' + id + '" type="text" placeholder="الصق رابط الصورة أو أي رابط يحتوي صورة\u2026">' +



        '<button class="q-img-fetch-btn" onclick="fetchImgFromUrl(\'' + id + '\')" ><i class="fas fa-magic"></i> جلب</button>' +



        '</div>' +



        '</div>' +



        '<div class="q-img-tab-panel" id="imgtab_upload_' + id + '">' +



        '<div class="q-img-drop" id="imgdrop_' + id + '" onclick="document.getElementById(\'imgfile_' + id + '\').click()" ' +



        'ondragover="event.preventDefault();this.classList.add(\'dragover\')" ' +



        'ondragleave="this.classList.remove(\'dragover\')" ' +



        'ondrop="event.preventDefault();this.classList.remove(\'dragover\');handleImgDrop(\'' + id + '\',event)">' +



        '<i class="fas fa-cloud-upload-alt"></i>' +



        '<p>اضغط أو اسحب صورة هنا</p>' +



        '<small>JPG, PNG, WebP — يتم ضغط وتحسين الصورة تلقائياً ⚡</small>' +



        '</div>' +



        '<input type="file" id="imgfile_' + id + '" accept="image/*" style="display:none" onchange="uploadQImg(\'' + id + '\',this.files[0])">' +



        '<div class="q-img-uploading" id="imgloading_' + id + '"><div class="spin spin-sm"></div> جارٍ رفع الصورة\u2026</div>' +



        '</div>' +



        '<div class="q-img-status" id="imgstatus_' + id + '"></div>' +

        '<div class="q-img-preview" id="imgpreview_' + id + '" style="display:none; position:relative; margin-top:10px;">' +

        '<img id="imgel_' + id + '" src="" style="max-width:100%; max-height:220px; border-radius:12px; border:1px solid var(--bdr); object-fit:contain; display:block; margin:0 auto;" alt="Question Image">' +

        '<button type="button" class="q-img-remove-btn" onclick="removeQImg(\'' + id + '\')" style="position:absolute; top:8px; left:8px; background:rgba(239,68,68,0.88); color:#fff; border:none; border-radius:50%; width:28px; height:28px; cursor:pointer; display:flex; align-items:center; justify-content:center; box-shadow:0 2px 8px rgba(0,0,0,0.2);" title="حذف الصورة"><i class="fas fa-times"></i></button>' +

        '</div>' +



        '</div>' +



        '</div>' +



        '</div>';



      document.getElementById('qList').appendChild(div);



      if (data && data.image_url) { setQImg(id, data.image_url); toggleImgSection(id); }



      if (qtype === 'tf') {



        _showTFLayout(id, data);



      } else if (qtype === 'open') {



        _showOpenQLayout(id);



      } else if (data && data.options) {



        var os = typeof data.options === 'string' ? JSON.parse(data.options) : (data.options || []);



        var ci = parseInt(data.correct_index != null ? data.correct_index : 0);



        os.forEach(function (o, i) { addOpt(id, o, i === ci); });



      } else { for (var i = 0; i < 4; i++) addOpt(id, '', i === 0); }



      renumQ(); updDeg();



    }



    function setQType(qid, type, btn) {



      var div = document.querySelector('.qcard[data-qid="' + qid + '"]');



      if (!div) return;



      div.dataset.qtype = type;



      div.querySelectorAll('.q-type-btn').forEach(function (b) { b.classList.remove('active', 'active-tf', 'active-open'); });



      if (type === 'mcq') { btn.classList.add('active'); _showMcqLayout(qid); }



      else if (type === 'tf') { btn.classList.add('active-tf'); _showTFLayout(qid, null); }



      else { btn.classList.add('active-open'); _showOpenQLayout(qid); }



    }



    function _showMcqLayout(qid) {



      var opts = document.getElementById('opts_' + qid);



      var addBtn = document.getElementById('addopt_' + qid);



      var tfopts = document.getElementById('tfopts_' + qid);



      var note = document.getElementById('opennote_' + qid);
      var openopts = document.getElementById('openopts_' + qid);
      if (opts) opts.style.display = '';
      if (addBtn) addBtn.style.display = '';
      if (tfopts) tfopts.style.display = 'none';
      if (note) note.style.display = 'none';
      if (openopts) openopts.style.display = 'none';
      if (opts) { opts.innerHTML = ''; for (var i = 0; i < 4; i++)addOpt(qid, '', i === 0); }
    }

    function _showTFLayout(qid, data) {
      var opts = document.getElementById('opts_' + qid);
      var addBtn = document.getElementById('addopt_' + qid);
      var tfopts = document.getElementById('tfopts_' + qid);
      var note = document.getElementById('opennote_' + qid);
      var openopts = document.getElementById('openopts_' + qid);
      if (opts) opts.innerHTML = '';
      if (addBtn) addBtn.style.display = 'none';
      if (tfopts) tfopts.style.display = 'flex';
      if (note) note.style.display = 'none';
      if (openopts) openopts.style.display = 'none';
      if (data && data.correct_index != null) {
        var isTrue = parseInt(data.correct_index) === 0;
        setTF(qid, isTrue, true);
      }
    }

    function _showOpenQLayout(qid) {
      var opts = document.getElementById('opts_' + qid);
      var addBtn = document.getElementById('addopt_' + qid);
      var tfopts = document.getElementById('tfopts_' + qid);
      var note = document.getElementById('opennote_' + qid);
      var openopts = document.getElementById('openopts_' + qid);
      if (opts) opts.innerHTML = '';
      if (addBtn) addBtn.style.display = 'none';
      if (tfopts) tfopts.style.display = 'none';
      if (note) note.style.display = 'flex';
      if (openopts) openopts.style.display = 'block';
    }



    function setTF(qid, isTrue, silent) {



      var trueBtn = document.getElementById('tftrue_' + qid);



      var falseBtn = document.getElementById('tffalse_' + qid);



      if (!trueBtn || !falseBtn) return;



      trueBtn.classList.toggle('selected', isTrue);



      falseBtn.classList.toggle('selected', !isTrue);



      var div = document.querySelector('.qcard[data-qid="' + qid + '"]');



      if (div) div.dataset.tfAnswer = isTrue ? '0' : '1';



    }



    function toggleImgSection(qid) {



      var wrap = document.getElementById('imgwrap_' + qid);



      var chev = document.getElementById('imgchev_' + qid);



      if (!wrap) return;



      var open = wrap.classList.toggle('open');



      if (chev) chev.style.transform = open ? 'rotate(180deg)' : '';



    }



    async function fetchImgFromUrl(qid) {



      var inp = document.getElementById('imgurl_' + qid);



      var status = document.getElementById('imgstatus_' + qid);



      var url = (inp && inp.value || '').trim();



      if (!url) { showToast('أدخل رابطاً أولاً', 'err'); return; }



      if (status) { status.className = 'q-img-status'; status.textContent = 'جارٍ جلب الصورة\u2026'; }



      var directImg = /\.(jpe?g|png|gif|webp|svg|bmp|avif)(\?.*)?$/i.test(url);



      if (directImg) { setQImg(qid, url); return; }



      var transformed = transformToDirectImg(url);



      if (transformed) { setQImg(qid, transformed); return; }



      try {



        if (status) status.textContent = 'جارٍ استخراج الصورة من الرابط\u2026';



        var d = await api('fetchOgImage', { url: url });



        if (d.success && d.image_url) { setQImg(qid, d.image_url); }



        else { if (status) { status.className = 'q-img-status err'; status.textContent = 'تعذّر استخراج الصورة \u2014 جرّب رابطاً مباشراً'; } }



      } catch (e) { if (status) { status.className = 'q-img-status err'; status.textContent = 'خطأ في الاتصال'; } }



    }



    function transformToDirectImg(url) {



      var m = url.match(/drive\.google\.com\/file\/d\/([^\/]+)/);



      if (m) return 'https://drive.google.com/uc?export=view&id=' + m[1];



      m = url.match(/drive\.google\.com\/open\?id=([^&]+)/);



      if (m) return 'https://drive.google.com/uc?export=view&id=' + m[1];



      if (url.indexOf('dropbox.com') > -1 && url.indexOf('dl=0') > -1) return url.replace('dl=0', 'dl=1');



      m = url.match(/imgur\.com\/(?!a\/|gallery\/)([a-zA-Z0-9]+)$/);



      if (m) return 'https://i.imgur.com/' + m[1] + '.jpg';



      return null;



    }



    function setQImg(qid, src) {



      var preview = document.getElementById('imgpreview_' + qid);



      var img = document.getElementById('imgel_' + qid);



      var status = document.getElementById('imgstatus_' + qid);



      var inp = document.getElementById('imgurl_' + qid);



      if (!preview || !img) return;



      img.onerror = function () { preview.style.display = 'none'; if (status) { status.className = 'q-img-status err'; status.textContent = 'تعذّر تحميل الصورة \u2014 تأكد أن الرابط عام'; }; };



      img.onload = function () { preview.style.display = 'block'; if (status) { status.className = 'q-img-status ok'; status.textContent = 'تم تحميل الصورة \u2713'; }; };



      img.src = src;



      if (inp) inp.value = src;



      var wrap = document.getElementById('imgwrap_' + qid);



      if (wrap && !wrap.classList.contains('open')) toggleImgSection(qid);



    }



    function removeQImg(qid) {



      var preview = document.getElementById('imgpreview_' + qid);



      var img = document.getElementById('imgel_' + qid);



      var status = document.getElementById('imgstatus_' + qid);



      var inp = document.getElementById('imgurl_' + qid);



      var fileInp = document.getElementById('imgfile_' + qid);



      if (preview) preview.style.display = 'none';



      if (img) img.src = '';



      if (status) status.textContent = '';



      if (inp) inp.value = '';



      if (fileInp) fileInp.value = '';



    }



    function getQImg(qid) {



      var inp = document.getElementById('imgurl_' + qid);
      if (inp && inp.value && inp.value.trim()) return inp.value.trim();
      var img = document.getElementById('imgel_' + qid);



      return (img && img.src && img.src.indexOf('data:') < 0 && img.src !== window.location.href) ? img.src : '';



    }







    function switchImgTab(qid, tab, btn) {



      var wrap = document.getElementById('imgwrap_' + qid);



      if (!wrap) return;



      wrap.querySelectorAll('.q-img-tab').forEach(function (b) { b.classList.remove('active'); });



      wrap.querySelectorAll('.q-img-tab-panel').forEach(function (p) { p.classList.remove('active'); });



      btn.classList.add('active');



      var panel = document.getElementById('imgtab_' + tab + '_' + qid);



      if (panel) panel.classList.add('active');



    }







    function optimizeImage(file, maxDim = 1200, quality = 0.82) {
      return new Promise(function(resolve) {
        if (!file || !file.type || !file.type.startsWith('image/')) {
          resolve(file);
          return;
        }
        var img = new Image();
        var reader = new FileReader();
        reader.onload = function(e) {
          img.onload = function() {
            var width = img.width;
            var height = img.height;
            if (width > maxDim || height > maxDim) {
              if (width > height) {
                height = Math.round((height * maxDim) / width);
                width = maxDim;
              } else {
                width = Math.round((width * maxDim) / height);
                height = maxDim;
              }
            }
            var canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            var ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0, width, height);
            canvas.toBlob(function(blob) {
              if (blob && blob.size < file.size) {
                var fileName = file.name.replace(/\.[^/.]+$/, "") + ".webp";
                var optimizedFile = new File([blob], fileName, { type: 'image/webp', lastModified: Date.now() });
                resolve(optimizedFile);
              } else {
                resolve(file);
              }
            }, 'image/webp', quality);
          };
          img.onerror = function() { resolve(file); };
          img.src = e.target.result;
        };
        reader.onerror = function() { resolve(file); };
        reader.readAsDataURL(file);
      });
    }

    async function uploadQImg(qid, file) {



      if (!file) return;



      var loading = document.getElementById('imgloading_' + qid);



      var status = document.getElementById('imgstatus_' + qid);



      if (loading) loading.style.display = 'flex';



      if (status) { status.className = 'q-img-status'; status.textContent = 'جارٍ تحسين ورفع الصورة…'; }



      try {

        file = await optimizeImage(file);

        var fd = new FormData();



        fd.append('photo', file, 'question_img_' + Date.now() + '.' + (file.name.split('.').pop() || 'webp'));



        fd.append('type', 'question');



        var r = await fetch('/upload.php', {



          method: 'POST', body: fd, headers: { Accept: 'application/json' }



        });



        var d = await r.json();



        if (d.success && d.imageUrl) {



          setQImg(qid, d.imageUrl);



          var inp = document.getElementById('imgurl_' + qid);



          if (inp) inp.value = d.imageUrl;



        } else {



          if (status) { status.className = 'q-img-status err'; status.textContent = d.message || 'فشل الرفع'; }



        }



      } catch (e) {



        if (status) { status.className = 'q-img-status err'; status.textContent = 'خطأ في الاتصال'; }



      }



      if (loading) loading.style.display = 'none';



    }







    function handleImgDrop(qid, event) {



      var files = event.dataTransfer.files;



      if (files && files[0]) uploadQImg(qid, files[0]);



    }







    function addOpt(qid, text = '', correct = false) {



      const list = document.getElementById(`opts_${qid}`);



      const idx = list.children.length;



      if (idx >= 5) { showToast('الحد الأقصى ٥ خيارات', 'info'); return; }



      const row = document.createElement('div');



      row.className = 'orow';



      row.innerHTML = `<div class="oradio${correct ? ' ok' : ''}" onclick="setCorrect(this)">${correct ? '✓' : ''}</div>



    <div class="olet">${LETTERS[idx]}</div>



    <input class="oinp" type="text" placeholder="الخيار ${LETTERS[idx]}" value="${esc(text)}">



    <div class="odel" onclick="this.closest('.orow').remove();relabel('${qid}')"><i class="fas fa-times"></i></div>`;



      list.appendChild(row);



    }



    function setCorrect(el) {



      el.closest('.opts').querySelectorAll('.oradio').forEach(r => { r.classList.remove('ok'); r.textContent = ''; });



      el.classList.add('ok'); el.textContent = '✓';



    }



    function relabel(qid) {



      document.getElementById(`opts_${qid}`).querySelectorAll('.orow').forEach((r, i) => {



        r.querySelector('.olet').textContent = LETTERS[i];



        r.querySelector('.oinp').placeholder = `الخيار ${LETTERS[i]}`;



      });



    }



    function rmQ(id) { document.querySelector(`.qcard[data-qid="${id}"]`)?.remove(); renumQ(); updDeg(); }



    function renumQ() { document.querySelectorAll('.qcard').forEach((c, i) => c.querySelector('.qnum').textContent = i + 1); }



    function calcDeg() { let t = 0; document.querySelectorAll('.qdeg-i').forEach(i => t += parseInt(i.value) || 0); return t; }



    function updDeg() { document.getElementById('degTotal').innerHTML = `${calcDeg()} <small style="font-size:.7rem;font-weight:500;">درجة</small>`; }







    // ─── Coupon tiers ────────────────────────────────────────────────



    function convertMilestonesToTiers(milestones) {
      const sorted = [...milestones].sort((a, b) => a.pct - b.pct);
      if (sorted.length === 0) {
        return [];
      }
      // The lowest milestone applies to that percentage and anything under it (down to 0)
      const lowestCoupons = sorted[0].coupons;
      if (sorted[0].pct > 0) {
        sorted.unshift({ pct: 0, coupons: lowestCoupons });
      }

      const tiers = [];
      for (let i = 0; i < sorted.length; i++) {
        const from = sorted[i].pct;
        const val = sorted[i].coupons;
        const to = (i < sorted.length - 1) ? sorted[i + 1].pct : 100;
        tiers.push({ from, to, val });
      }
      return tiers;
    }

    function convertTiersToMilestones(tiers) {
      return (tiers || [])
        .filter(t => parseInt(t.from) > 0 || parseInt(t.val) > 0)
        .map(t => ({ pct: parseInt(t.from), coupons: parseInt(t.val) }))
        .sort((a, b) => a.pct - b.pct);
    }

    function addMilestone(pct = 50, coupons = 10) {
      const div = document.createElement('div');
      div.className = 'ctier';
      div.style = "display:flex; align-items:center; gap:8px; margin-bottom:8px; background:var(--bg2); border:1px solid var(--bdr); padding:8px 12px; border-radius:8px;";
      div.innerHTML = `
    <div style="display:flex; align-items:center; gap:6px;">
      <span style="font-size:0.85rem; color:var(--t2); font-weight:700;">حصول الطالب على</span>
      <input type="number" class="milestone-pct" min="1" max="100" value="${pct}" style="width:65px; text-align:center; font-weight:bold; border-radius:6px; border:1px solid var(--bdr); padding:5px; background:var(--bg);">
      <span style="font-size:0.85rem; color:var(--t2); font-weight:700;">% أو أكثر</span>
    </div>
    <div style="display:flex; align-items:center; gap:6px; margin-right:15px;">
      <span style="font-size:0.85rem; color:var(--t2); font-weight:700;">يمنحه</span>
      <input type="number" class="milestone-coupons" min="0" max="999" value="${coupons}" style="width:65px; text-align:center; font-weight:bold; border-radius:6px; border:1px solid var(--bdr); padding:5px; background:var(--bg);">
      <i class="fas fa-star" style="color:#eab308; font-size: 0.9rem;"></i>
      <span style="font-size:0.85rem; color:var(--t2); font-weight:700;">كوبون</span>
    </div>
    <div class="ctier-del" onclick="this.closest('.ctier').remove()" style="margin-right:auto; margin-left:5px; cursor:pointer; color:var(--t3); transition:0.2s;" onmouseover="this.style.color='var(--err)'" onmouseout="this.style.color='var(--t3)'"><i class="fas fa-times"></i></div>
  `;
      document.getElementById('ctierList').appendChild(div);
    }

    function initTiers() {
      document.getElementById('ctierList').innerHTML = '';
      addMilestone(50, 10);
      addMilestone(70, 30);
      addMilestone(85, 50);
      addMilestone(95, 100);
    }

    function resetForm() {

      ['fTitle', 'fDesc'].forEach(id => document.getElementById(id).value = '');
      window.selectedClassifications = [];
      window._editingSpecificIds = [];
      renderActiveTags();
      populateClassificationSelector();

      document.getElementById('fAssign').value = 'all';

      document.getElementById('specRow').style.display = 'none';

      document.getElementById('fTimerOn').checked = false;

      document.getElementById('timerRow').style.display = 'none';

      document.getElementById('fNoDeadline').checked = false;

      document.getElementById('fEndDateMode').checked = false;

      document.getElementById('fShowRes').checked = true;

      document.getElementById('fShowAns').checked = true;

      document.getElementById('fShuffle').checked = false;

      if (document.getElementById('fShuffleAnswers')) document.getElementById('fShuffleAnswers').checked = false;

      document.getElementById('fReview').checked = true;

      document.getElementById('qList').innerHTML = '';

      document.getElementById('ctierList').innerHTML = '';

      setDefaultDates();

      toggleEndDateMode(false);

      toggleNoDeadline(false);

      const allChk = document.getElementById('class_all');

      if (allChk) {

        if (CFG.activeClass === 'كل الفصول') {

          allChk.checked = true;

        } else {

          allChk.checked = !CFG.activeClass;

        }

      }

      document.querySelectorAll('.class-checkbox').forEach(cb => {

        if (CFG.activeClass && cb.value === CFG.activeClass) {

          cb.checked = true;

        } else {

          cb.checked = false;

        }

      });

    }

    function fillForm(t) {

      document.getElementById('fTitle').value = t.title || '';
      window.selectedClassifications = t.group_name ? t.group_name.split(',').map(x => x.trim()).filter(Boolean) : [];
      renderActiveTags();
      populateClassificationSelector();

      document.getElementById('fDesc').value = t.description || '';

      document.getElementById('fStart').value = toLocalDT(t.start_date);

      document.getElementById('fEnd').value = toLocalDT(t.end_date);
      if (document.getElementById('fEndDateOnly')) {
        document.getElementById('fEndDateOnly').value = toLocalDateOnly(t.end_date);
      }

      document.getElementById('fNoDeadline').checked = !!parseInt(t.no_deadline || 0);

      document.getElementById('fAssign').value = t.assign_to || 'all';

      document.getElementById('fShowRes').checked = !!parseInt(t.show_result);

      document.getElementById('fShowAns').checked = !!parseInt(t.show_answers || 0);

      document.getElementById('fShuffle').checked = !!parseInt(t.shuffle);

      if (document.getElementById('fShuffleAnswers')) {
        document.getElementById('fShuffleAnswers').checked = !!parseInt(t.shuffle_answers || 0);
      }

      document.getElementById('fReview').checked = !!parseInt(t.allow_review);

      document.getElementById('fEndDateMode').checked = isEndDateOnly(t.end_date);
      toggleEndDateMode(false);
      toggleNoDeadline(false);
      if (typeof updateTimingSummaryBadge === 'function') updateTimingSummaryBadge();

      let classIds = (t.class_ids || '').split(',').map(s => s.trim()).filter(Boolean);
      if (!classIds.length && t.class_id) {
        classIds = [String(t.class_id)];
      }

      const isAllClasses = classIds.includes('0') || (classIds.length === 0 && (!t.class_id || parseInt(t.class_id) === 0));
      const allChk = document.getElementById('class_all');
      if (allChk) allChk.checked = isAllClasses;

      const classNamesList = (t.class_name || '').split('،').map(s => s.trim().replace(/^,\s*/, '')).filter(Boolean);

      document.querySelectorAll('.class-checkbox').forEach(cb => {
        const isMatch = !isAllClasses && (
          classIds.includes(String(cb.dataset.id)) ||
          classIds.includes(String(cb.value)) ||
          classNamesList.includes(cb.value)
        );
        cb.checked = !!isMatch;
      });

      if (parseInt(t.time_limit)) {

        document.getElementById('fTimerOn').checked = true;

        document.getElementById('timerRow').style.display = '';

        document.getElementById('fTimerMin').value = t.time_limit;

        document.getElementById('fTimerBeh').value = t.timer_behavior || 'submit';

      } else {

        document.getElementById('fTimerOn').checked = false;

        document.getElementById('timerRow').style.display = 'none';

        document.getElementById('fTimerMin').value = '';

        document.getElementById('fTimerBeh').value = t.timer_behavior || 'submit';

      }

      window._editingSpecificIds = (t.assign_to === 'specific' && t.specific_ids)
        ? (typeof t.specific_ids === 'string' ? JSON.parse(t.specific_ids || '[]') : t.specific_ids)
        : [];
      if (t.assign_to === 'specific') onAssignChange();

      document.getElementById('qList').innerHTML = '';

      (t.questions || []).forEach(q => addQ(q));

      document.getElementById('ctierList').innerHTML = '';

      const mx = t.coupon_matrix ? (typeof t.coupon_matrix === 'string' ? JSON.parse(t.coupon_matrix) : t.coupon_matrix) : null;

      if (mx && mx.length) {
        const milestones = convertTiersToMilestones(mx);
        milestones.forEach(m => addMilestone(m.pct, m.coupons));
      } else {
        initTiers();
      }

      updDeg();

    }







    // ─── Steps ──────────────────────────────────────────────────────



    function goStep(n) {
      [1, 2, 3].forEach(i => {
        const sp = document.getElementById(`sp${i}`);
        if (sp) sp.style.display = i === n ? '' : 'none';
        const d = document.getElementById(`sd${i}`);
        if (d) d.className = 'step' + (i < n ? ' done' : i === n ? ' active' : '');
      });

      const isEditing = !!editId;
      const prevBtn = document.getElementById('prevBtn');
      const nextBtn = document.getElementById('nextBtn');
      const pubBtn = document.getElementById('pubBtn');
      const quickSaveBtn = document.getElementById('quickSaveBtn');

      if (prevBtn) prevBtn.style.display = n > 1 ? '' : 'none';
      if (nextBtn) nextBtn.style.display = n < 3 ? '' : 'none';

      if (isEditing) {
        if (pubBtn) {
          pubBtn.innerHTML = '<i class="fas fa-save"></i> حفظ التعديلات';
          pubBtn.style.display = n === 3 ? '' : 'none';
        }
        if (quickSaveBtn) {
          quickSaveBtn.innerHTML = '<i class="fas fa-save"></i> حفظ التعديلات';
          quickSaveBtn.style.display = n < 3 ? '' : 'none';
        }
      } else {
        if (pubBtn) {
          pubBtn.innerHTML = '<i class="fas fa-paper-plane"></i> نشر';
          pubBtn.style.display = n === 3 ? '' : 'none';
        }
        if (quickSaveBtn) {
          quickSaveBtn.style.display = 'none';
        }
      }

      const stepNum = document.getElementById('stepNum');
      if (stepNum) stepNum.textContent = n;
      curStep = n;
    }
    function nextStep() {



      if (curStep === 1 && !v1()) return;



      if (curStep === 2 && !v2()) return;



      if (curStep === 2) document.getElementById('s3deg').textContent = calcDeg();



      goStep(curStep + 1);



    }



    function prevStep() { goStep(curStep - 1); }



    function v1() {



      if (!document.getElementById('fTitle').value.trim()) { showToast('أدخل العنوان', 'err'); return false; }



      const anyClassChecked = Array.from(document.querySelectorAll('#fClassContainer input[type="checkbox"]:checked')).length > 0;

      if (!anyClassChecked) { showToast('اختر الفصل / المراحل المستهدفة', 'err'); return false; }



      const s = new Date(document.getElementById('fStart').value);



      if (Number.isNaN(s.getTime())) { showToast('أدخل تاريخ البداية', 'err'); return false; }



      if (!document.getElementById('fNoDeadline').checked) {



        const e = new Date(getNormalizedEndDateValue());



        if (Number.isNaN(e.getTime()) || e <= s) { showToast('الموعد النهائي يجب أن يكون بعد البداية', 'err'); return false; }



      }



      if (document.getElementById('fTimerOn').checked && !document.getElementById('fTimerMin').value) { showToast('أدخل مدة المؤقت', 'err'); return false; }



      return true;



    }



    function v2() {



      const cards = document.querySelectorAll('.qcard');



      if (!cards.length) { showToast('أضف سؤالاً واحداً على الأقل', 'err'); return false; }



      for (const card of cards) {



        if (!card.querySelector('.qi').value.trim()) { showToast('أكمل نص الأسئلة', 'err'); return false; }



        const qt2v = card.dataset.qtype || 'mcq';



        if (qt2v === 'tf') {



          if (!card.dataset.tfAnswer && card.dataset.tfAnswer !== '0') { showToast('حدد الإجابة الصحيحة (صح أو خطأ)', 'err'); return false; }



        } else if (qt2v === 'mcq') {



          if (!card.querySelector('.oradio.ok')) { showToast('حدد الإجابة الصحيحة لكل سؤال', 'err'); return false; }



          const os = card.querySelectorAll('.oinp');



          if (os.length < 2) { showToast('كل سؤال يحتاج خيارين على الأقل', 'err'); return false; }



          let empty = false; os.forEach(o => { if (!o.value.trim()) empty = true; });



          if (empty) { showToast('أكمل نص الخيارات', 'err'); return false; }



        }



      }



      return true;



    }







    // ─── Question builder ───────────────────────────────────────────



    function addQ(data) {



      data = data || null;



      qCnt++;



      var id = 'q' + qCnt;



      var qtype = data && data.question_type ? data.question_type : 'mcq';



      var deg = (data && data.degree !== undefined && data.degree !== null) ? parseInt(data.degree) : 25;
      if (isNaN(deg) || deg < 0) deg = 0;
      var isUngraded = (deg === 0);



      var qtxt = data && data.question_text ? data.question_text : '';



      var div = document.createElement('div');



      div.className = 'qcard'; div.dataset.qid = id; div.dataset.qtype = qtype;
      if (data && data.id) div.dataset.dbId = data.id;



      var n = document.querySelectorAll('.qcard').length + 1;



      div.innerHTML =



        '<div class="qhdr">' +



        '<div class="qnum" id="qn_' + id + '">' + n + '</div>' +



        '<input class="qi" type="text" placeholder="نص السؤال\u2026" value="' + esc(qtxt) + '">' +

        '<button type="button" class="q-bible-btn" onclick="openBibleVerseModalForQ(\'' + id + '\')" title="إضافة آية من الكتاب المقدس"><i class="fas fa-bible"></i> آية</button>' +



        '<div class="qdeg"><span class="qdeg-l">الدرجة</span><input class="qdeg-i" type="number" min="0" max="100" value="' + deg + '" oninput="onQDegChange(\'' + id + '\', this)"></div>' +



        '<div class="qrm" onclick="rmQ(\'' + id + '\')"><i class="fas fa-trash"></i></div>' +



        '</div>' +



        '<div class="q-type-selector">' +



        '<button class="q-type-btn ' + (qtype === 'mcq' ? 'active' : '') + '" onclick="setQType(\'' + id + '\',\'mcq\',this)" title="اختيار من متعدد">' +



        '<i class="fas fa-list-ul"></i> متعدد</button>' +



        '<button class="q-type-btn ' + (qtype === 'tf' ? 'active-tf' : '') + '" onclick="setQType(\'' + id + '\',\'tf\',this)" title="صح أو خطأ">' +



        '<i class="fas fa-check-circle"></i> صح/خطأ</button>' +



        '<button class="q-type-btn ' + (qtype === 'open' ? 'active-open' : '') + '" onclick="setQType(\'' + id + '\',\'open\',this)" title="إجابة مفتوحة">' +



        '<i class="fas fa-pen-nib"></i> مفتوح</button>' +



        '</div>' +



        '<div class="qbody" id="qbody_' + id + '">' +



        '<div class="opts" id="opts_' + id + '"></div>' +



        '<button class="add-opt" id="addopt_' + id + '" onclick="addOpt(\'' + id + '\')"><i class="fas fa-plus"></i>إضافة خيار</button>' +



        '<div class="tf-opts" id="tfopts_' + id + '" style="display:none">' +



        '<button class="tf-btn tf-true" id="tftrue_' + id + '" onclick="setTF(\'' + id + '\',true)"><i class="fas fa-check-circle"></i> صحيح</button>' +



        '<button class="tf-btn tf-false" id="tffalse_' + id + '" onclick="setTF(\'' + id + '\',false)"><i class="fas fa-times-circle"></i> خطأ</button>' +



        '</div>' +



        '<div class="open-q-note" id="opennote_' + id + '" style="' + (qtype === 'open' ? 'display:flex;' : 'display:none;') + (isUngraded ? 'background:rgba(16,185,129,0.1);border-color:#10b981;color:#065f46;' : '') + '">' +
        (isUngraded
          ? '<i class="fas fa-check-circle" style="color:#10b981;"></i> سؤال بدون درجات (استطلاع / رأي) — لا يتطلب تصحيحاً وتظهر نتيجة التكليف فورياً للطفل'
          : '<i class="fas fa-pen-nib"></i> الطفل يكتب إجابة نصية \u2014 تُصحَّح يدوياً بعد التسليم') +
        '</div>' +
        '<div class="open-q-opts" id="openopts_' + id + '" style="margin-top:8px; display:' + (qtype === 'open' ? 'block' : 'none') + ';">' +
        '<label style="display:inline-flex;align-items:center;gap:7px;font-size:.82rem;font-weight:700;color:var(--t2);cursor:pointer;background:var(--s2);border:1px solid var(--bdr);border-radius:var(--r-sm);padding:6px 12px;user-select:none;">' +
        '<input type="checkbox" id="ungraded_' + id + '" onchange="toggleUngradedQ(\'' + id + '\', this.checked)" ' + (isUngraded ? 'checked' : '') + '> ' +
        '<i class="fas fa-comment-dots" style="color:var(--brand);"></i> سؤال استطلاعي / بدون درجات (0 درجة — لا يتطلب تصحيحاً)' +
        '</label>' +
        '</div>' +
        '</div>' +



        '<div class="q-img-section">' +



        '<button class="q-img-toggle" onclick="toggleImgSection(\'' + id + '\')">' +



        '<i class="fas fa-image"></i> إضافة صورة للسؤال (اختياري)' +



        '<i class="fas fa-chevron-down" id="imgchev_' + id + '" style="font-size:.62rem;margin-right:auto;transition:.2s;"></i>' +



        '</button>' +



        '<div class="q-img-input-wrap" id="imgwrap_' + id + '">' +



        '<div class="q-img-tabs">' +



        '<button class="q-img-tab active" onclick="switchImgTab(\'' + id + '\',\'url\',this)">' +



        '<i class="fas fa-link"></i> رابط</button>' +



        '<button class="q-img-tab" onclick="switchImgTab(\'' + id + '\',\'upload\',this)">' +



        '<i class="fas fa-upload"></i> رفع صورة</button>' +



        '</div>' +



        '<div class="q-img-tab-panel active" id="imgtab_url_' + id + '">' +



        '<div class="q-img-url-row">' +



        '<input class="q-img-url-inp" id="imgurl_' + id + '" type="text" placeholder="الصق رابط الصورة أو أي رابط يحتوي صورة\u2026">' +



        '<button class="q-img-fetch-btn" onclick="fetchImgFromUrl(\'' + id + '\')" ><i class="fas fa-magic"></i> جلب</button>' +



        '</div>' +



        '</div>' +



        '<div class="q-img-tab-panel" id="imgtab_upload_' + id + '">' +



        '<div class="q-img-drop" id="imgdrop_' + id + '" onclick="document.getElementById(\'imgfile_' + id + '\').click()" ' +



        'ondragover="event.preventDefault();this.classList.add(\'dragover\')" ' +



        'ondragleave="this.classList.remove(\'dragover\')" ' +



        'ondrop="event.preventDefault();this.classList.remove(\'dragover\');handleImgDrop(\'' + id + '\',event)">' +



        '<i class="fas fa-cloud-upload-alt"></i>' +



        '<p>اضغط أو اسحب صورة هنا</p>' +



        '<small>JPG, PNG, WebP — يتم ضغط وتحسين الصورة تلقائياً ⚡</small>' +



        '</div>' +



        '<input type="file" id="imgfile_' + id + '" accept="image/*" style="display:none" onchange="uploadQImg(\'' + id + '\',this.files[0])">' +



        '<div class="q-img-uploading" id="imgloading_' + id + '"><div class="spin spin-sm"></div> جارٍ رفع الصورة\u2026</div>' +



        '</div>' +



        '<div class="q-img-status" id="imgstatus_' + id + '"></div>' +

        '<div class="q-img-preview" id="imgpreview_' + id + '" style="display:none; position:relative; margin-top:10px;">' +

        '<img id="imgel_' + id + '" src="" style="max-width:100%; max-height:220px; border-radius:12px; border:1px solid var(--bdr); object-fit:contain; display:block; margin:0 auto;" alt="Question Image">' +

        '<button type="button" class="q-img-remove-btn" onclick="removeQImg(\'' + id + '\')" style="position:absolute; top:8px; left:8px; background:rgba(239,68,68,0.88); color:#fff; border:none; border-radius:50%; width:28px; height:28px; cursor:pointer; display:flex; align-items:center; justify-content:center; box-shadow:0 2px 8px rgba(0,0,0,0.2);" title="حذف الصورة"><i class="fas fa-times"></i></button>' +

        '</div>' +



        '</div>' +



        '</div>' +



        '</div>';



      document.getElementById('qList').appendChild(div);



      if (data && data.image_url) { setQImg(id, data.image_url); toggleImgSection(id); }



      if (qtype === 'tf') {



        _showTFLayout(id, data);



      } else if (qtype === 'open') {



        _showOpenQLayout(id);



      } else if (data && data.options) {



        var os = typeof data.options === 'string' ? JSON.parse(data.options) : (data.options || []);



        var ci = parseInt(data.correct_index != null ? data.correct_index : 0);



        os.forEach(function (o, i) { addOpt(id, o, i === ci); });



      } else { for (var i = 0; i < 4; i++) addOpt(id, '', i === 0); }



      renumQ(); updDeg();



    }



    function setQType(qid, type, btn) {



      var div = document.querySelector('.qcard[data-qid="' + qid + '"]');



      if (!div) return;



      div.dataset.qtype = type;



      div.querySelectorAll('.q-type-btn').forEach(function (b) { b.classList.remove('active', 'active-tf', 'active-open'); });



      if (type === 'mcq') { btn.classList.add('active'); _showMcqLayout(qid); }



      else if (type === 'tf') { btn.classList.add('active-tf'); _showTFLayout(qid, null); }



      else { btn.classList.add('active-open'); _showOpenQLayout(qid); }



    }



    function _showMcqLayout(qid) {



      var opts = document.getElementById('opts_' + qid);



      var addBtn = document.getElementById('addopt_' + qid);



      var tfopts = document.getElementById('tfopts_' + qid);



      var note = document.getElementById('opennote_' + qid);
      var openopts = document.getElementById('openopts_' + qid);
      if (opts) opts.style.display = '';
      if (addBtn) addBtn.style.display = '';
      if (tfopts) tfopts.style.display = 'none';
      if (note) note.style.display = 'none';
      if (openopts) openopts.style.display = 'none';
      if (opts) { opts.innerHTML = ''; for (var i = 0; i < 4; i++)addOpt(qid, '', i === 0); }
    }

    function _showTFLayout(qid, data) {
      var opts = document.getElementById('opts_' + qid);
      var addBtn = document.getElementById('addopt_' + qid);
      var tfopts = document.getElementById('tfopts_' + qid);
      var note = document.getElementById('opennote_' + qid);
      var openopts = document.getElementById('openopts_' + qid);
      if (opts) opts.innerHTML = '';
      if (addBtn) addBtn.style.display = 'none';
      if (tfopts) tfopts.style.display = 'flex';
      if (note) note.style.display = 'none';
      if (openopts) openopts.style.display = 'none';
      if (data && data.correct_index != null) {
        var isTrue = parseInt(data.correct_index) === 0;
        setTF(qid, isTrue, true);
      }
    }

    function _showOpenQLayout(qid) {
      var opts = document.getElementById('opts_' + qid);
      var addBtn = document.getElementById('addopt_' + qid);
      var tfopts = document.getElementById('tfopts_' + qid);
      var note = document.getElementById('opennote_' + qid);
      var openopts = document.getElementById('openopts_' + qid);
      if (opts) opts.innerHTML = '';
      if (addBtn) addBtn.style.display = 'none';
      if (tfopts) tfopts.style.display = 'none';
      if (note) note.style.display = 'flex';
      if (openopts) openopts.style.display = 'block';
    }



    function setTF(qid, isTrue, silent) {



      var trueBtn = document.getElementById('tftrue_' + qid);



      var falseBtn = document.getElementById('tffalse_' + qid);



      if (!trueBtn || !falseBtn) return;



      trueBtn.classList.toggle('selected', isTrue);



      falseBtn.classList.toggle('selected', !isTrue);



      var div = document.querySelector('.qcard[data-qid="' + qid + '"]');



      if (div) div.dataset.tfAnswer = isTrue ? '0' : '1';



    }



    function toggleImgSection(qid) {



      var wrap = document.getElementById('imgwrap_' + qid);



      var chev = document.getElementById('imgchev_' + qid);



      if (!wrap) return;



      var open = wrap.classList.toggle('open');



      if (chev) chev.style.transform = open ? 'rotate(180deg)' : '';



    }



    async function fetchImgFromUrl(qid) {



      var inp = document.getElementById('imgurl_' + qid);



      var status = document.getElementById('imgstatus_' + qid);



      var url = (inp && inp.value || '').trim();



      if (!url) { showToast('أدخل رابطاً أولاً', 'err'); return; }



      if (status) { status.className = 'q-img-status'; status.textContent = 'جارٍ جلب الصورة\u2026'; }



      var directImg = /\.(jpe?g|png|gif|webp|svg|bmp|avif)(\?.*)?$/i.test(url);



      if (directImg) { setQImg(qid, url); return; }



      var transformed = transformToDirectImg(url);



      if (transformed) { setQImg(qid, transformed); return; }



      try {



        if (status) status.textContent = 'جارٍ استخراج الصورة من الرابط\u2026';



        var d = await api('fetchOgImage', { url: url });



        if (d.success && d.image_url) { setQImg(qid, d.image_url); }



        else { if (status) { status.className = 'q-img-status err'; status.textContent = 'تعذّر استخراج الصورة \u2014 جرّب رابطاً مباشراً'; } }



      } catch (e) { if (status) { status.className = 'q-img-status err'; status.textContent = 'خطأ في الاتصال'; } }



    }



    function transformToDirectImg(url) {



      var m = url.match(/drive\.google\.com\/file\/d\/([^\/]+)/);



      if (m) return 'https://drive.google.com/uc?export=view&id=' + m[1];



      m = url.match(/drive\.google\.com\/open\?id=([^&]+)/);



      if (m) return 'https://drive.google.com/uc?export=view&id=' + m[1];



      if (url.indexOf('dropbox.com') > -1 && url.indexOf('dl=0') > -1) return url.replace('dl=0', 'dl=1');



      m = url.match(/imgur\.com\/(?!a\/|gallery\/)([a-zA-Z0-9]+)$/);



      if (m) return 'https://i.imgur.com/' + m[1] + '.jpg';



      return null;



    }



    function setQImg(qid, src) {



      var preview = document.getElementById('imgpreview_' + qid);



      var img = document.getElementById('imgel_' + qid);



      var status = document.getElementById('imgstatus_' + qid);



      var inp = document.getElementById('imgurl_' + qid);



      if (!preview || !img) return;



      img.onerror = function () { preview.style.display = 'none'; if (status) { status.className = 'q-img-status err'; status.textContent = 'تعذّر تحميل الصورة \u2014 تأكد أن الرابط عام'; }; };



      img.onload = function () { preview.style.display = 'block'; if (status) { status.className = 'q-img-status ok'; status.textContent = 'تم تحميل الصورة \u2713'; }; };



      img.src = src;



      if (inp && !inp.value) inp.value = src;



      var wrap = document.getElementById('imgwrap_' + qid);



      if (wrap && !wrap.classList.contains('open')) toggleImgSection(qid);



    }



    function removeQImg(qid) {



      var preview = document.getElementById('imgpreview_' + qid);



      var img = document.getElementById('imgel_' + qid);



      var status = document.getElementById('imgstatus_' + qid);



      var inp = document.getElementById('imgurl_' + qid);



      var fileInp = document.getElementById('imgfile_' + qid);



      if (preview) preview.style.display = 'none';



      if (img) img.src = '';



      if (status) status.textContent = '';



      if (inp) inp.value = '';



      if (fileInp) fileInp.value = '';



    }



    function getQImg(qid) {



      var inp = document.getElementById('imgurl_' + qid);
      if (inp && inp.value && inp.value.trim()) return inp.value.trim();
      var img = document.getElementById('imgel_' + qid);



      return (img && img.src && img.src.indexOf('data:') < 0 && img.src !== window.location.href) ? img.src : '';



    }







    function switchImgTab(qid, tab, btn) {



      var wrap = document.getElementById('imgwrap_' + qid);



      if (!wrap) return;



      wrap.querySelectorAll('.q-img-tab').forEach(function (b) { b.classList.remove('active'); });



      wrap.querySelectorAll('.q-img-tab-panel').forEach(function (p) { p.classList.remove('active'); });



      btn.classList.add('active');



      var panel = document.getElementById('imgtab_' + tab + '_' + qid);



      if (panel) panel.classList.add('active');



    }







    async function uploadQImg(qid, file) {



      if (!file) return;



      if (file.size > 5 * 1024 * 1024) { showToast('الحجم الأقصى 5 MB', 'err'); return; }



      var loading = document.getElementById('imgloading_' + qid);



      var status = document.getElementById('imgstatus_' + qid);



      if (loading) loading.style.display = 'flex';



      if (status) { status.className = 'q-img-status'; status.textContent = 'جارٍ رفع الصورة…'; }



      try {



        var fd = new FormData();



        fd.append('photo', file, 'question_img_' + Date.now() + '.' + file.name.split('.').pop());



        fd.append('type', 'question');



        var r = await fetch('/upload.php', {



          method: 'POST', body: fd, headers: { Accept: 'application/json' }



        });



        var d = await r.json();



        if (d.success && d.imageUrl) {



          setQImg(qid, d.imageUrl);



          var inp = document.getElementById('imgurl_' + qid);



          if (inp) inp.value = d.imageUrl;



        } else {



          if (status) { status.className = 'q-img-status err'; status.textContent = d.message || 'فشل الرفع'; }



        }



      } catch (e) {



        if (status) { status.className = 'q-img-status err'; status.textContent = 'خطأ في الاتصال'; }



      }



      if (loading) loading.style.display = 'none';



    }







    function handleImgDrop(qid, event) {



      var files = event.dataTransfer.files;



      if (files && files[0]) uploadQImg(qid, files[0]);



    }







    function addOpt(qid, text = '', correct = false) {



      const list = document.getElementById(`opts_${qid}`);



      const idx = list.children.length;



      if (idx >= 5) { showToast('الحد الأقصى ٥ خيارات', 'info'); return; }



      const row = document.createElement('div');



      row.className = 'orow';



      row.innerHTML = `<div class="oradio${correct ? ' ok' : ''}" onclick="setCorrect(this)">${correct ? '✓' : ''}</div>



    <div class="olet">${LETTERS[idx]}</div>



    <input class="oinp" type="text" placeholder="الخيار ${LETTERS[idx]}" value="${esc(text)}">



    <div class="odel" onclick="this.closest('.orow').remove();relabel('${qid}')"><i class="fas fa-times"></i></div>`;



      list.appendChild(row);



    }



    function setCorrect(el) {



      el.closest('.opts').querySelectorAll('.oradio').forEach(r => { r.classList.remove('ok'); r.textContent = ''; });



      el.classList.add('ok'); el.textContent = '✓';



    }



    function relabel(qid) {



      document.getElementById(`opts_${qid}`).querySelectorAll('.orow').forEach((r, i) => {



        r.querySelector('.olet').textContent = LETTERS[i];



        r.querySelector('.oinp').placeholder = `الخيار ${LETTERS[i]}`;



      });



    }



    function rmQ(id) { document.querySelector(`.qcard[data-qid="${id}"]`)?.remove(); renumQ(); updDeg(); }



    function renumQ() { document.querySelectorAll('.qcard').forEach((c, i) => c.querySelector('.qnum').textContent = i + 1); }



    function calcDeg() { let t = 0; document.querySelectorAll('.qdeg-i').forEach(i => t += parseInt(i.value) || 0); return t; }



    function updDeg() { document.getElementById('degTotal').innerHTML = `${calcDeg()} <small style="font-size:.7rem;font-weight:500;">درجة</small>`; }

    function toggleUngradedQ(qid, isUngraded) {
      var card = document.querySelector('.qcard[data-qid="' + qid + '"]');
      if (!card) return;
      var degInput = card.querySelector('.qdeg-i');
      var note = document.getElementById('opennote_' + qid);
      if (isUngraded) {
        if (degInput) {
          var curVal = parseInt(degInput.value) || 0;
          if (curVal > 0) degInput.dataset.prevDeg = curVal;
          degInput.value = '0';
        }
        if (note) {
          note.innerHTML = '<i class="fas fa-check-circle" style="color:#10b981;"></i> سؤال بدون درجات (استطلاع / رأي) — لا يتطلب تصحيحاً وتظهر نتيجة التكليف فورياً للطفل';
          note.style.background = 'rgba(16,185,129,0.1)';
          note.style.borderColor = '#10b981';
          note.style.color = '#065f46';
        }
      } else {
        if (degInput) {
          degInput.value = degInput.dataset.prevDeg || '25';
        }
        if (note) {
          note.innerHTML = '<i class="fas fa-pen-nib"></i> الطفل يكتب إجابة نصية \u2014 تُصحَّح يدوياً بعد التسليم';
          note.style.background = '';
          note.style.borderColor = '';
          note.style.color = '';
        }
      }
      updDeg();
    }

    function onQDegChange(qid, input) {
      var val = parseInt(input.value);
      if (isNaN(val) || val < 0) { input.value = 0; val = 0; }
      var card = document.querySelector('.qcard[data-qid="' + qid + '"]');
      var qtype = card ? card.dataset.qtype : '';
      var chk = document.getElementById('ungraded_' + qid);
      var note = document.getElementById('opennote_' + qid);
      if (qtype === 'open') {
        if (val === 0) {
          if (chk) chk.checked = true;
          if (note) {
            note.innerHTML = '<i class="fas fa-check-circle" style="color:#10b981;"></i> سؤال بدون درجات (استطلاع / رأي) — لا يتطلب تصحيحاً وتظهر نتيجة التكليف فورياً للطفل';
            note.style.background = 'rgba(16,185,129,0.1)';
            note.style.borderColor = '#10b981';
            note.style.color = '#065f46';
          }
        } else {
          if (chk) chk.checked = false;
          if (note) {
            note.innerHTML = '<i class="fas fa-pen-nib"></i> الطفل يكتب إجابة نصية \u2014 تُصحَّح يدوياً بعد التسليم';
            note.style.background = '';
            note.style.borderColor = '';
            note.style.color = '';
          }
        }
      }
      updDeg();
    }







    // ─── Coupon tiers ────────────────────────────────────────────────



    function addTier(from = 50, to = 100, coupons = 3) {
      if (typeof addMilestone === 'function') {
        addMilestone(from, coupons);
      }
    }







    // ─── Assign-to ───────────────────────────────────────────────────



    function onAssignChange() {



      const spec = document.getElementById('fAssign').value === 'specific';



      document.getElementById('specRow').style.display = spec ? '' : 'none';



      if (spec) populateSpec();



    }



    async function onClassChange() { if (document.getElementById('fAssign').value === 'specific') await populateSpec(); }



    async function populateSpec() {



      const allChecked = document.getElementById('class_all')?.checked;



      let classes = [];



      if (allChecked) {



        classes = ['كل الفصول'];



      } else {



        classes = Array.from(document.querySelectorAll('.class-checkbox:checked')).map(b => b.value);



      }



      const c = document.getElementById('specList');



      if (!classes.length) { c.innerHTML = '<span style="color:var(--t3);">اختر الفصل أولاً</span>'; return; }



      c.innerHTML = '<span style="color:var(--t3);"><i class="fas fa-spinner fa-spin"></i> جارٍ التحميل…</span>';







      let st = [];



      for (const cls of classes) {



        const studentsOfClass = await loadStudents(cls);



        st = st.concat(studentsOfClass);



      }







      // Deduplicate students by ID



      const seenIds = new Set();



      st = st.filter(s => {



        if (seenIds.has(s.id)) return false;



        seenIds.add(s.id);



        return true;



      });







      if (!st.length) { c.innerHTML = '<span style="color:var(--t3);">لا يوجد أطفال</span>'; return; }

      const preSelected = Array.isArray(window._editingSpecificIds) ? window._editingSpecificIds.map(Number) : [];
      c.innerHTML = st.map(s => {
        const avatar = getStudentAvatarHtml(s.photo, s.name, '24px');
        const isChk = preSelected.includes(Number(s.id)) ? 'checked' : '';
        return `<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.78rem;color:var(--t1);padding:4px 0;">
      <input type="checkbox" name="spec_ids" value="${s.id}" ${isChk} style="accent-color:var(--brand);">${avatar}<span style="font-weight:600;">${esc(s.name)}</span></label>`;
      }).join('');



    }







    // ─── Collect & Save ──────────────────────────────────────────────



    function collectForm(status) {



      const questions = [];



      document.querySelectorAll('.qcard').forEach((card, qi) => {
        const dbId = card.dataset.dbId ? parseInt(card.dataset.dbId) : null;
        const qtype2 = card.dataset.qtype || 'mcq';
        const qtext2 = (card.querySelector('.qi') || { value: '' }).value.trim();
        const degInp2 = card.querySelector('.qdeg-i');
        const degree2 = degInp2 && degInp2.value !== '' ? Math.max(0, parseInt(degInp2.value) || 0) : 0;
        const img2 = getQImg(card.dataset.qid) || '';

        if (qtype2 === 'open') {
          questions.push({ id: dbId, sort_order: qi, question_type: 'open', question_text: qtext2, options: '[]', correct_index: null, degree: degree2, image_url: img2 });
        } else if (qtype2 === 'tf') {
          const ci2 = parseInt(card.dataset.tfAnswer || '0');
          questions.push({ id: dbId, sort_order: qi, question_type: 'tf', question_text: qtext2, options: JSON.stringify(['\u0635\u062d\u064a\u062d', '\u062e\u0637\u0623']), correct_index: ci2, degree: degree2, image_url: img2 });
        } else {
          let ci2 = 0; const opts2 = [];
          card.querySelectorAll('.orow').forEach((r, oi) => {
            opts2.push((r.querySelector('.oinp') || { value: '' }).value.trim());
            if (r.querySelector('.oradio.ok')) ci2 = oi;
          });
          questions.push({ id: dbId, sort_order: qi, question_type: 'mcq', question_text: qtext2, options: JSON.stringify(opts2), correct_index: ci2, degree: degree2, image_url: img2 });
        }
      });



      const milestones = [];
      document.querySelectorAll('.ctier').forEach(t => {
        const pctEl = t.querySelector('.milestone-pct');
        const couponsEl = t.querySelector('.milestone-coupons');
        if (pctEl && couponsEl) {
          const pct = pctEl.value !== '' ? (parseInt(pctEl.value) || 0) : 50;
          const coupons = parseInt(couponsEl.value) || 0;
          milestones.push({ pct, coupons });
        } else {
          const inps = t.querySelectorAll('input[type="number"]');
          if (inps.length >= 3) {
            const pct = inps[0].value !== '' ? (parseInt(inps[0].value) || 0) : 50;
            const coupons = parseInt(inps[2].value) || 0;
            milestones.push({ pct, coupons });
          } else if (inps.length >= 1) {
            const pct = inps[0].value !== '' ? (parseInt(inps[0].value) || 0) : 50;
            const coupons = inps.length > 1 ? (parseInt(inps[1].value) || 0) : 0;
            milestones.push({ pct, coupons });
          }
        }
      });
      const tiers = convertMilestonesToTiers(milestones);





      const specIds = [];



      document.querySelectorAll('[name="spec_ids"]:checked').forEach(c => specIds.push(parseInt(c.value)));







      let classId = 0;



      let className = 'كل الفصول';



      let classIdsList = [];







      const allChecked = document.getElementById('class_all')?.checked;



      if (allChecked) {



        classId = 0;



        className = 'كل الفصول';



        classIdsList = ['0'];



      } else {



        const checkedBoxes = Array.from(document.querySelectorAll('.class-checkbox:checked'));



        if (checkedBoxes.length > 0) {
          classIdsList = checkedBoxes.map(b => b.dataset.id);
          classId = parseInt(checkedBoxes[0].dataset.id);
          className = checkedBoxes.map(b => b.value).join('، ');
        }



      }



      const classIds = classIdsList.join(',');







      return {



        status, title: document.getElementById('fTitle').value.trim(), description: document.getElementById('fDesc').value.trim(),
        group_name: window.selectedClassifications.join(','),



        class_name: className, class_id: classId, class_ids: classIds,



        assign_to: document.getElementById('fAssign').value, specific_ids: JSON.stringify(specIds),



        start_date: document.getElementById('fStart').value.replace('T', ' '),



        end_date: (document.getElementById('fNoDeadline').checked ? '' : getNormalizedEndDateValue()).replace('T', ' '),



        no_deadline: document.getElementById('fNoDeadline').checked ? 1 : 0,



        time_limit: document.getElementById('fTimerOn').checked ? (parseInt(document.getElementById('fTimerMin').value) || null) : null,



        timer_behavior: document.getElementById('fTimerBeh').value,



        show_result: document.getElementById('fShowRes').checked ? 1 : 0,



        show_answers: document.getElementById('fShowAns').checked ? 1 : 0,



        shuffle: document.getElementById('fShuffle').checked ? 1 : 0,

        shuffle_answers: (document.getElementById('fShuffleAnswers') && document.getElementById('fShuffleAnswers').checked) ? 1 : 0,

        allow_review: document.getElementById('fReview').checked ? 1 : 0,



        total_degree: calcDeg(),



        max_coupons: Math.max(...tiers.map(t => t.val), 0),



        coupon_matrix: JSON.stringify(tiers),



        questions: JSON.stringify(questions)



      };



    }



    async function saveTask(status) {



      const payload = collectForm(status);



      const btnId = status === 'draft' ? 'draftBtn' : 'pubBtn';



      const btn = document.getElementById(btnId);



      const orig = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<span class="spinner"></span>';



      try {



        const extra = { ...payload }; if (editId) extra.task_id = editId;



        const d = await api(editId ? 'updateTask' : 'createTask', extra);



        if (d.success) { clearLocalDraft(); showToast(status === 'draft' ? 'تم حفظ الـ Draft ✓' : 'تم نشر التاسك 🎉', status === 'draft' ? 'info' : 'ok'); closeCreate(); await loadTasks(); }



        else showToast(d.message || 'فشل الحفظ', 'err');



      } catch (e) { showToast('خطأ في الاتصال', 'err'); }



      btn.disabled = false; btn.innerHTML = orig;



    }



    function saveDraft() { if (!document.getElementById('fTitle').value.trim()) { showToast('أدخل العنوان أولاً', 'err'); return; } saveTask('draft'); }

    function publishTask() {
      if (!document.querySelectorAll('.ctier').length) { showToast('أضف مستوى كوبون', 'err'); return; }
      let targetStatus = 'published';
      if (editId) {
        const cur = tasks.find(x => x.id == editId);
        if (cur && cur.status) targetStatus = cur.status;
      }
      saveTask(targetStatus);
    }

    // ─── LocalStorage Auto-Save Draft System ───────────────────────
    const TASK_DRAFT_KEY = '_task_exam_draft_v1';
    let _draftSaveTimeout = null;

    function scheduleAutoSaveDraft() {
      const ov = document.getElementById('createOv');
      if (!ov || !ov.classList.contains('open')) return;
      if (editId) return;

      if (_draftSaveTimeout) clearTimeout(_draftSaveTimeout);
      _draftSaveTimeout = setTimeout(() => {
        saveLocalDraft();
      }, 400);
    }

    function saveLocalDraft() {
      const title = (document.getElementById('fTitle')?.value || '').trim();
      const desc = (document.getElementById('fDesc')?.value || '').trim();
      const questionsCount = document.querySelectorAll('.qcard').length;

      if (!title && !desc && questionsCount === 0) return;

      try {
        const payload = collectForm('draft');
        const now = new Date();
        const displayTime = now.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
        const draftData = {
          editId: editId,
          payload: payload,
          savedAt: now.toISOString(),
          displayTime: displayTime
        };
        localStorage.setItem(TASK_DRAFT_KEY, JSON.stringify(draftData));
        updateDraftSaveBadge(displayTime);
      } catch (e) {
        console.warn('Auto-save local draft error:', e);
      }
    }

    function updateDraftSaveBadge(timeStr) {
      const badge = document.getElementById('draftSaveBadge');
      const badgeText = document.getElementById('draftSaveBadgeText');
      const clearBtn = document.getElementById('clearDraftBtn');
      if (badge) {
        badge.style.display = 'inline-flex';
        if (badgeText) badgeText.textContent = `محفوظة محلياً (${timeStr})`;
      }
      if (clearBtn) clearBtn.style.display = 'inline-flex';
    }

    function hideDraftSaveBadge() {
      const badge = document.getElementById('draftSaveBadge');
      const clearBtn = document.getElementById('clearDraftBtn');
      if (badge) badge.style.display = 'none';
      if (clearBtn) clearBtn.style.display = 'none';
    }

    function restoreLocalDraft() {
      try {
        const raw = localStorage.getItem(TASK_DRAFT_KEY);
        if (!raw) return false;
        const draftData = JSON.parse(raw);
        if (!draftData || !draftData.payload) return false;

        const p = draftData.payload;

        if (p.title) document.getElementById('fTitle').value = p.title;
        if (p.description) document.getElementById('fDesc').value = p.description;

        if (p.no_deadline !== undefined) {
          const nd = document.getElementById('fNoDeadline');
          if (nd) { nd.checked = !!p.no_deadline; if (typeof toggleNoDeadline === 'function') toggleNoDeadline(true); }
        }
        if (p.start_date) document.getElementById('fStart').value = p.start_date.replace(' ', 'T');
        if (p.end_date && !p.no_deadline) document.getElementById('fEnd').value = p.end_date.replace(' ', 'T');

        if (p.time_limit !== undefined && p.time_limit !== null) {
          const tOn = document.getElementById('fTimerOn');
          if (tOn) { tOn.checked = true; if (typeof toggleTimerRowDisplay === 'function') toggleTimerRowDisplay(true); }
          const tMin = document.getElementById('fTimerMin');
          if (tMin) tMin.value = p.time_limit;
        }
        if (p.timer_behavior) {
          const tBeh = document.getElementById('fTimerBeh');
          if (tBeh) tBeh.value = p.timer_behavior;
        }

        if (p.show_result !== undefined) document.getElementById('fShowRes').checked = !!p.show_result;
        if (p.show_answers !== undefined) document.getElementById('fShowAns').checked = !!p.show_answers;
        if (p.shuffle !== undefined) document.getElementById('fShuffle').checked = !!p.shuffle;
        if (p.shuffle_answers !== undefined && document.getElementById('fShuffleAnswers')) {
          document.getElementById('fShuffleAnswers').checked = !!p.shuffle_answers;
        }
        if (p.allow_review !== undefined) document.getElementById('fReview').checked = !!p.allow_review;

        document.getElementById('qList').innerHTML = '';
        qCnt = 0;
        let questions = [];
        if (typeof p.questions === 'string') {
          try { questions = JSON.parse(p.questions); } catch (e) {}
        } else if (Array.isArray(p.questions)) {
          questions = p.questions;
        }

        if (questions.length > 0) {
          questions.forEach(q => {
            const qDeg = (q.degree !== undefined && q.degree !== null) ? parseInt(q.degree) : 25;
            addQ({ question_type: q.question_type || 'mcq', degree: qDeg, question_text: q.question_text });
            const qcards = document.querySelectorAll('.qcard');
            const card = qcards[qcards.length - 1];
            if (!card) return;

            const qiInp = card.querySelector('.qi');
            if (qiInp) qiInp.value = q.question_text || '';
            const qdegInp = card.querySelector('.qdeg-i');
            if (qdegInp) {
              qdegInp.value = qDeg;
              if (typeof onQDegChange === 'function') onQDegChange(card.dataset.qid, qdegInp);
            }

            if (q.image_url) setQImg(card.dataset.qid, q.image_url);

            if (q.question_type === 'tf') {
              if (typeof setTFAns === 'function') setTFAns(card.dataset.qid, q.correct_index || 0);
            } else if (q.question_type === 'mcq') {
              const opts = typeof q.options === 'string' ? JSON.parse(q.options) : (q.options || []);
              const orows = card.querySelectorAll('.orow');
              opts.forEach((optText, oIdx) => {
                if (oIdx < orows.length) {
                  const oInp = orows[oIdx].querySelector('.oinp');
                  if (oInp) oInp.value = optText;
                } else {
                  if (typeof addOpt === 'function') addOpt(card.dataset.qid);
                  const newOrows = card.querySelectorAll('.orow');
                  const lastOrow = newOrows[newOrows.length - 1];
                  if (lastOrow) {
                    const oInp = lastOrow.querySelector('.oinp');
                    if (oInp) oInp.value = optText;
                  }
                }
              });
              const finalOrows = card.querySelectorAll('.orow');
              if (q.correct_index !== undefined && finalOrows[q.correct_index]) {
                const rad = finalOrows[q.correct_index].querySelector('.oradio');
                if (rad && typeof setMCQOk === 'function') setMCQOk(rad);
              }
            }
          });
        }

        document.getElementById('ctierList').innerHTML = '';
        let tiers = [];
        if (typeof p.coupon_matrix === 'string') {
          try { tiers = JSON.parse(p.coupon_matrix); } catch (e) {}
        } else if (Array.isArray(p.coupon_matrix)) {
          tiers = p.coupon_matrix;
        }
        const milestones = typeof convertTiersToMilestones === 'function' ? convertTiersToMilestones(tiers) : [];
        if (milestones.length > 0) {
          milestones.forEach(m => addMilestone(m.pct, m.coupons));
        } else {
          initTiers();
        }

        if (typeof updateTimingSummaryBadge === 'function') updateTimingSummaryBadge();
        updateDraftSaveBadge(draftData.displayTime || '');
        showToast('تم استعادة المسودة التلقائية بنجاح 📝', 'ok');
        return true;
      } catch (e) {
        console.error('Error restoring local draft:', e);
        return false;
      }
    }

    function clearLocalDraft() {
      localStorage.removeItem(TASK_DRAFT_KEY);
      hideDraftSaveBadge();
    }

    function confirmClearDraft() {
      if (confirm('هل أنت تأكد من مسح المسودة المحفوظة تلقائياً؟')) {
        clearLocalDraft();
        resetForm();
        initTiers();
        updDeg();
        showToast('تم مسح المسودة المحفوطة', 'info');
      }
    }

    document.addEventListener('DOMContentLoaded', () => {
      const createOv = document.getElementById('createOv');
      if (createOv) {
        createOv.addEventListener('input', scheduleAutoSaveDraft);
        createOv.addEventListener('change', scheduleAutoSaveDraft);
      }
    });







    // ─── Detail modal ────────────────────────────────────────────────



    let detailTask = null;







    async function openDetail(id) {



      try {



        const d = await api('getTaskDetail', { task_id: id });



        if (!d.success) { showToast(d.message || 'فشل', 'err'); return; }



        detailTask = d.task;



        const t = d.task; const si = statusOf(t);



        const taskClasses = getTaskClassNames(t);
        const taskClassesLabel = taskClasses.join('، ');
        document.getElementById('dTitle').textContent = t.title;
        document.getElementById('dSub').textContent = `${taskClassesLabel} — ${(t.questions || []).length} سؤال — ${t.total_degree} درجة`;



        const matrix = t.coupon_matrix ? JSON.parse(t.coupon_matrix) : [];



        const subs = t.submissions || [];



        const tc = subs.reduce((a, s) => a + (parseInt(s.coupons_awarded) || 0), 0);



        const hasOpenQs = (t.questions || []).some(q => q.question_type === 'open' && (parseInt(q.degree) || 0) > 0);



        const pendingSubs = subs.filter(s => !parseInt(s.is_graded || 0) && hasOpenQs).length;



        document.getElementById('dFoot').innerHTML = `
      <div style="display:flex; align-items:center; justify-content:space-between; width:100%; gap:8px;">
        <div style="display:flex; align-items:center; gap:8px;">
          <!-- 3-Dots Action Dropdown Menu -->
          <div style="position:relative;" onclick="event.stopPropagation();">
            <button type="button" id="dMenuBtn" onclick="toggleDetailMenu(event)" style="background:var(--surface-2, var(--bg3, #f1f5f9)); border:1px solid var(--bdr); color:var(--t1); width:36px; height:36px; border-radius:10px; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:0.9rem; transition:0.2s;" onmouseover="this.style.background='var(--bdr)';" onmouseout="this.style.background='var(--surface-2)';">
              <i class="fas fa-ellipsis-v"></i>
            </button>
            <div id="detailMenuDropdown" style="display:none; position:absolute; bottom:100%; right:0; margin-bottom:8px; z-index:100; min-width:150px; background:var(--surface-1, var(--bg2, #ffffff)); border:1px solid var(--bdr); border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,0.15); overflow:hidden; font-family:'Cairo',sans-serif;">
              <button onclick="toggleDetailMenu(event); openExportTaskQuestions(${t.id});" style="width:100%; padding:9px 14px; border:none; background:transparent; color:var(--t1); font-size:0.82rem; font-weight:700; text-align:right; cursor:pointer; display:flex; align-items:center; gap:8px;" onmouseover="this.style.background='var(--bg3)';" onmouseout="this.style.background='transparent';">
                <i class="fas fa-file-export" style="color:var(--brand);"></i> تصدير الأسئلة
              </button>
              <button onclick="toggleDetailMenu(event); copyTaskShareLink(${t.id});" style="width:100%; padding:9px 14px; border:none; background:transparent; color:var(--t1); font-size:0.82rem; font-weight:700; text-align:right; cursor:pointer; display:flex; align-items:center; gap:8px;" onmouseover="this.style.background='var(--bg3)';" onmouseout="this.style.background='transparent';">
                <i class="fas fa-link" style="color:var(--brand);"></i> نسخ رابط التاسك
              </button>
              <button onclick="toggleDetailMenu(event); closeDetail(); openConf(${t.id});" style="width:100%; padding:9px 14px; border:none; background:transparent; color:var(--err); font-size:0.82rem; font-weight:700; text-align:right; cursor:pointer; display:flex; align-items:center; gap:8px;" onmouseover="this.style.background='var(--err-bg)';" onmouseout="this.style.background='transparent';">
                <i class="fas fa-trash"></i> حذف التاسك
              </button>
            </div>
          </div>

          <!-- Edit Button in Main Foot -->
          <button class="btn btn-g" onclick="closeDetail(); openEdit(${t.id});" style="height:36px; padding:0 14px; font-size:0.82rem; font-weight:700; display:inline-flex; align-items:center; gap:6px;">
            <i class="fas fa-pen" style="color:var(--brand);"></i> تعديل
          </button>
        </div>

        <!-- Grading Button -->
        <button class="btn" onclick="closeDetail(); openGradePanel(${t.id});" style="background:linear-gradient(135deg,#f59e0b,#d97706); color:#fff; height:36px; padding:0 16px; font-size:0.83rem; font-weight:700; border-radius:10px; display:inline-flex; align-items:center; gap:6px;">
          <i class="fas fa-pen-nib"></i> تصحيح الإجابات ${pendingSubs > 0 ? `<span class="pending-badge" style="background:#ef4444; color:#fff; border-radius:99px; padding:2px 7px; font-size:0.7rem;">${pendingSubs}</span>` : ''}
        </button>
      </div>
    `;







        // ── Build who answered / who didn't list ──────────────────────



        // Always ensure class students are loaded



        const answeredIds = subs.map(s => parseInt(s.student_id));
        for (const cn of taskClasses) {
          await loadStudents(cn);
        }
        const classStudents = getTaskStudents(t);
        const notAnswered = classStudents.filter(s => !answeredIds.includes(s.id));







        function buildCollapsibleList(items, renderFn, emptyMsg, maxShow = 10) {



          if (!items.length) return `<div style="font-size:.78rem;color:var(--t3);padding:6px 0;">${emptyMsg}</div>`;



          const id = 'clist_' + Math.random().toString(36).slice(2);



          const visible = items.slice(0, maxShow);



          const hidden = items.slice(maxShow);



          let html = visible.map(renderFn).join('');



          if (hidden.length) {



            html += `<div id="${id}_more" style="display:none;">${hidden.map(renderFn).join('')}</div>



        <button onclick="



          var m=document.getElementById('${id}_more');



          var b=document.getElementById('${id}_btn');



          var open=m.style.display!=='none';



          m.style.display=open?'none':'block';



          b.innerHTML=open?'<i class=\\'fas fa-chevron-down\\'></i> عرض ${hidden.length} أكثر':'<i class=\\'fas fa-chevron-up\\'></i> عرض أقل';



        " id="${id}_btn"



          style="width:100%;margin-top:8px;padding:6px;background:transparent;border:1.5px dashed rgba(0,0,0,.12);border-radius:var(--r-md);cursor:pointer;font-family:'Cairo',sans-serif;font-size:.73rem;font-weight:700;color:inherit;opacity:.7;display:flex;align-items:center;justify-content:center;gap:5px;">



          <i class="fas fa-chevron-down"></i> عرض ${hidden.length} أكثر



        </button>`;



          }



          return html;



        }







        document.getElementById('dBody').innerHTML = `



      <div class="detail-shell">



      <!-- Unified Compact Details Info Board -->
      <div style="background:var(--bg-card); border:1.5px solid var(--bdr); border-radius:12px; padding:16px; margin-bottom:20px; display:flex; flex-direction:column; gap:12px; direction:rtl;">
        
        <!-- Metadata pills -->
        <div style="display:flex; flex-wrap:wrap; gap:12px; font-size:0.75rem; color:var(--t3); border-bottom:1px dashed var(--bdr); padding-bottom:12px; justify-content:flex-start; align-items:center; direction:rtl; text-align:right;">
          <span style="display:inline-flex; align-items:center; gap:4px; background:var(--brand-bg); color:var(--brand); padding:2px 8px; border-radius:4px; font-weight:700;"><i class="fas fa-users"></i> ${esc(taskClassesLabel)}</span>
          <span style="display:inline-flex; align-items:center; gap:4px;"><i class="fas fa-calendar-check" style="color:var(--brand);"></i> البدء: ${fmtDate(t.start_date)}</span>
          <span style="display:inline-flex; align-items:center; gap:4px;"><i class="far fa-clock" style="color:var(--brand);"></i> النهاية: ${parseInt(t.no_deadline || 0) ? 'بدون موعد' : fmtDate(t.end_date)}</span>
          ${t.time_limit ? `<span style="display:inline-flex; align-items:center; gap:4px;"><i class="fas fa-stopwatch" style="color:var(--brand);"></i> ${t.time_limit} دقيقة</span>` : ''}
          ${parseInt(t.shuffle) ? `<span style="display:inline-flex; align-items:center; gap:4px; color:var(--warn);"><i class="fas fa-random"></i> ترتيب عشوائي للأسئلة</span>` : ''}
          ${parseInt(t.shuffle_answers || 0) ? `<span style="display:inline-flex; align-items:center; gap:4px; color:#8b5cf6;"><i class="fas fa-random"></i> خلط الإجابات</span>` : ''}
          ${si.key === 'draft' ? `<span style="background:var(--warn-bg); color:var(--warn); font-size:0.7rem; font-weight:700; padding:2px 8px; border-radius:4px; border:1px solid rgba(245,158,11,0.25);">Draft</span>` : ''}
        </div>

        <!-- Metrics row -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(100px, 1fr)); gap:16px; direction:rtl;">
          <div style="text-align:right;">
            <div style="font-size:0.7rem; color:var(--t3); font-weight:700; margin-bottom:2px;">الأسئلة</div>
            <div style="font-size:1.15rem; font-weight:900; color:var(--t1);">${(t.questions || []).length} <span style="font-size:0.75rem; font-weight:700; color:var(--t3);">سؤال</span></div>
          </div>
          <div style="text-align:right; border-right:1px solid var(--bdr); padding-right:16px;">
            <div style="font-size:0.7rem; color:var(--t3); font-weight:700; margin-bottom:2px;">الدرجة الكلية</div>
            <div style="font-size:1.15rem; font-weight:900; color:var(--t1);">${t.total_degree} <span style="font-size:0.75rem; font-weight:700; color:var(--t3);">درجة</span></div>
          </div>
          <div style="text-align:right; border-right:1px solid var(--bdr); padding-right:16px;">
            <div style="font-size:0.7rem; color:var(--t3); font-weight:700; margin-bottom:2px;">تسليمات الطلاب</div>
            <div style="font-size:1.15rem; font-weight:900; color:var(--t1);">${subs.length} <span style="font-size:0.75rem; font-weight:700; color:var(--t3);">إجابة</span></div>
          </div>
          <div style="text-align:right; border-right:1px solid var(--bdr); padding-right:16px;">
            <div style="font-size:0.7rem; color:var(--t3); font-weight:700; margin-bottom:2px;">الكوبونات الموزعة</div>
            <div style="font-size:1.15rem; font-weight:900; color:var(--cou);">${tc} <i class="fas fa-star" style="color:#eab308; font-size:0.75rem; margin-right:2px;"></i></div>
          </div>
        </div>

      </div>











      <!-- Who answered / who didn't -->



      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px;margin-bottom:20px;">



        <div style="background:var(--ok-bg);border:1px solid #6ee7b7;border-radius:var(--r-md);padding:11px 14px;">



          <div style="font-size:.78rem;font-weight:800;color:var(--ok);margin-bottom:10px;display:flex;align-items:center;gap:6px;">



            <i class="fas fa-check-circle"></i> أجابوا



            <span style="background:var(--ok);color:#fff;border-radius:var(--r-full);padding:1px 8px;font-size:.7rem;font-weight:700;">${subs.length}</span>



          </div>



          ${buildCollapsibleList(subs,
          s => {
            const stud = (classStuCache['كل الفصول'] || []).find(x => x.id == s.student_id);
            const photo = stud ? stud.photo : '';
            const avatar = getStudentAvatarHtml(photo, s.student_name, '28px');
            const isGraded = (parseInt(s.is_graded || 0) === 1) || !hasOpenQs;
            const scoreVal = parseInt(s.score || 0);
            const couponsAwarded = parseInt(s.coupons_awarded || 0);

            const scoreBadge = isGraded
              ? `<span style="font-size:.68rem;background:var(--ok-bg);color:var(--ok);border-radius:var(--r-full);padding:2px 8px;font-weight:800;flex-shrink:0;">${scoreVal}/${t.total_degree}</span>`
              : `<span style="font-size:.68rem;background:#fef3c7;color:#92400e;border-radius:var(--r-full);padding:2px 8px;font-weight:800;flex-shrink:0;"><i class="fas fa-clock"></i> لم يتم التقييم</span>`;

            const couponBadge = isGraded
              ? `<span style="font-size:.68rem;background:var(--cou-bg);color:var(--cou);border:1px solid #c4b5fd;border-radius:var(--r-full);padding:2px 7px;font-weight:800;flex-shrink:0;display:inline-flex;align-items:center;gap:3px;"><i class="fas fa-star" style="color:var(--cou-l);"></i> ${couponsAwarded} كوبون</span>`
              : '';

            return `<div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid rgba(0,0,0,.07);">
                ${avatar}
                <span style="font-size:.8rem;font-weight:700;color:var(--t1);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(s.student_name || '—')}</span>
                ${scoreBadge}
                ${couponBadge}
                ${hasOpenQs ? `<button onclick="event.stopPropagation();closeDetail();openGradePanel(${t.id},${s.id},true)"
                  style="background:var(--warn-bg);border:1px solid #fde68a;color:#b45309;border-radius:6px;padding:4px 8px;cursor:pointer;font-size:.65rem;font-weight:700;font-family:'Cairo',sans-serif;flex-shrink:0;white-space:nowrap;"><i class="fas fa-pen-nib"></i> ${isGraded ? 'تعديل الدرجة' : 'تصحيح'}</button>` : ''}
                <button onclick="event.stopPropagation();viewAnswers(${t.id},${s.student_id})"
                  style="background:var(--info-bg);border:1px solid #bfdbfe;color:var(--info);border-radius:6px;padding:4px 8px;cursor:pointer;font-size:.65rem;font-weight:700;font-family:'Cairo',sans-serif;flex-shrink:0;white-space:nowrap;"><i class="fas fa-eye"></i></button>
                <button onclick="event.stopPropagation();showDeleteSubConfirm(${s.id},'${esc(s.student_name || '')}',${s.coupons_awarded || 0},${t.id})"
                  style="background:none;border:1px solid #fca5a5;color:var(--err);border-radius:5px;padding:4px 6px;cursor:pointer;font-size:.63rem;flex-shrink:0;"><i class="fas fa-trash"></i></button>
              </div>`;
          },
          'لا أحد بعد'
        )}



        </div>



        <div style="background:var(--err-bg);border:1px solid #fca5a5;border-radius:var(--r-md);padding:11px 14px;">



          <div style="font-size:.78rem;font-weight:800;color:var(--err);margin-bottom:10px;display:flex;align-items:center;gap:6px;">



            <i class="fas fa-clock"></i> لم يجيبوا



            <span style="background:var(--err);color:#fff;border-radius:var(--r-full);padding:1px 8px;font-size:.7rem;font-weight:700;">${notAnswered.length}</span>



          </div>



          ${buildCollapsibleList(notAnswered,
          s => {
            const avatar = getStudentAvatarHtml(s.photo, s.name, '28px');
            return `<div style="display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid rgba(0,0,0,.07);">
                ${avatar}
                <span style="font-size:.8rem;font-weight:700;color:var(--t1);">${esc(s.name)}</span>
              </div>`;
          },
          classStudents.length ? 'الجميع أجاب 🎉' : 'بيانات الفصل غير محملة'
        )}



        </div>



      </div>







      <div style="margin-bottom:20px;">



        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
          <div class="fsec-title" style="margin-bottom:0;"><i class="fas fa-question-circle"></i>الأسئلة</div>
          <button type="button" class="btn btn-g" onclick="openExportTaskQuestions(${t.id})" style="height:32px; padding:0 12px; font-size:0.75rem; font-weight:700; display:inline-flex; align-items:center; gap:6px; border-radius:8px;">
            <i class="fas fa-file-export" style="color:var(--brand);"></i> تصدير الأسئلة
          </button>
        </div>



        ${(t.questions || []).map((q, i) => {



          const qt3 = q.question_type || 'mcq';



          const imgH = q.image_url ? `<div style="margin:4px 13px 8px;border-radius:var(--r-sm);overflow:hidden;border:1px solid var(--bdr);"><img src="${q.image_url}" alt="" style="width:100%;max-height:180px;object-fit:contain;display:block;background:#f8fafc;"></div>` : '';



          if (qt3 === 'tf') {



            const ci3 = parseInt(q.correct_index || 0);



            return `<div class="dq">



              <div class="dq-hdr"><div class="qnum">${i + 1}</div><div style="flex:1;font-weight:600;font-size:.86rem;">${esc(q.question_text)}</div>



              <span style="background:#fef3c7;color:#92400e;border-radius:var(--r-full);padding:3px 9px;font-size:.7rem;font-weight:700;white-space:nowrap;margin-left:6px;"><i class="fas fa-check-circle"></i> صح/خطأ</span>



              <div class="qdeg" style="background:var(--brand-bg);"><span class="qdeg-l">الدرجة</span><span style="font-size:.8rem;font-weight:700;color:var(--brand);">${q.degree}</span></div></div>



              ${imgH}



              <div style="padding:10px 13px;display:flex;gap:10px;">



                <div class="dq-opt${ci3 === 0 ? ' ok' : ''}" style="flex:1;justify-content:center;"><i class="fas fa-check-circle" style="color:#10b981;"></i> صحيح ${ci3 === 0 ? '<i class="fas fa-check-circle" style="color:var(--ok);margin-right:auto;"></i>' : ''}</div>



                <div class="dq-opt${ci3 === 1 ? ' ok' : ''}" style="flex:1;justify-content:center;"><i class="fas fa-times-circle" style="color:#ef4444;"></i> خطأ ${ci3 === 1 ? '<i class="fas fa-check-circle" style="color:var(--ok);margin-right:auto;"></i>' : ''}</div>



              </div>



            </div>`;



          }



          const os = typeof q.options === 'string' ? JSON.parse(q.options) : (q.options || []);



          const ci = parseInt(q.correct_index || 0);



          return `<div class="dq">



            <div class="dq-hdr"><div class="qnum">${i + 1}</div><div style="flex:1;font-weight:600;font-size:.86rem;">${esc(q.question_text)}</div><div class="qdeg" style="background:var(--brand-bg);"><span class="qdeg-l">الدرجة</span><span style="font-size:.8rem;font-weight:700;color:var(--brand);">${q.degree}</span></div></div>



            ${imgH}



            <div style="padding:10px 13px;">



              ${os.map((o, j) => `<div class="dq-opt${j === ci ? ' ok' : ''}"><strong style="margin-left:4px;">${LETTERS[j]}</strong>${esc(o)}${j === ci ? '<i class="fas fa-check-circle" style="margin-right:auto;color:var(--ok);"></i>' : ''}</div>`).join('')}



            </div>



          </div>`;



        }).join('')}



      </div>







      <div>



        <div class="fsec-title"><i class="fas fa-users"></i>${PEOPLE} الذين أجابوا (${subs.length}) — ${tc} كوبون ممنوح</div>

        ${subs.length ? `<div style="overflow-x:auto;border:1px solid var(--bdr);border-radius:var(--r-md);">

          <table class="sub-tbl"><thead><tr><th>${PEOPLE}</th><th>الدرجة</th><th>النسبة</th><th>الكوبونات</th><th>وقت الإرسال</th><th style="width:80px;"></th></tr></thead>
          <tbody>${subs.map(s => {
          const stud = (classStuCache['كل الفصول'] || []).find(x => x.id == s.student_id);
          const photo = stud ? stud.photo : '';
          const avatar = getStudentAvatarHtml(photo, s.student_name, '24px');
          const isGraded = (parseInt(s.is_graded || 0) === 1) || !hasOpenQs;
          const scoreVal = parseInt(s.score || 0);
          const couponsAwarded = parseInt(s.coupons_awarded || 0);

          return `<tr>
              <td data-label="${PEOPLE}">
                <div style="display:flex;align-items:center;gap:8px;">
                  ${avatar}
                  <span style="font-weight:700;color:var(--t1);">${esc(s.student_name || '—')}</span>
                </div>
              </td>
              <td data-label="الدرجة"><span style="font-weight:700;">${scoreVal}/${t.total_degree}</span></td>
              <td data-label="النسبة">${t.total_degree ? Math.round(scoreVal / t.total_degree * 100) : 0}%</td>
              <td data-label="الكوبونات"><span style="color:var(--cou);font-weight:700;">${couponsAwarded} <i class="fas fa-star"></i></span></td>
              <td data-label="التوقيت" style="color:var(--t3);font-size:.7rem;">${fmtDate(s.submitted_at)}</td>
              <td>
                <div style="display:flex;gap:4px;">
                  <button onclick="event.stopPropagation();viewAnswers(${t.id}, ${s.student_id})" style="background:var(--info-bg);border:1px solid #bfdbfe;color:var(--info);border-radius:6px;padding:5px 10px;cursor:pointer;font-size:.72rem;font-weight:700;font-family:'Cairo',sans-serif;min-height:32px;"><i class="fas fa-eye"></i> إجابات</button>
                  <button onclick="event.stopPropagation();showDeleteSubConfirm(${s.id},'${esc(s.student_name || '')}',${s.coupons_awarded || 0},${t.id})" style="background:var(--err-bg);border:1px solid #fca5a5;color:var(--err);border-radius:6px;padding:5px 8px;cursor:pointer;font-size:.72rem;min-height:32px;"><i class="fas fa-trash"></i></button>
                </div>
              </td>
            </tr>`;
        }).join('')}</tbody>



          </table></div>`:



            `<div style="text-align:center;padding:24px;color:var(--t3);font-size:.82rem;"><i class="fas fa-inbox" style="font-size:1.7rem;display:block;margin-bottom:6px;"></i>لا توجد إجابات بعد</div>`}



      </div>

      <!-- Premium Milestones Card (قواعد توزيع الكوبونات) -->
      <div style="background:var(--bg3); border:1px solid var(--bdr); border-radius:12px; padding:10px 12px; margin-top:14px; margin-bottom:10px; direction:rtl;">
        <div style="font-size:0.75rem; font-weight:800; color:var(--t2); margin-bottom:8px; display:flex; align-items:center; gap:6px; text-align:right; justify-content:flex-start;">
          <i class="fas fa-star" style="color:var(--brand); font-size:0.8rem;"></i>
          <span>قواعد توزيع الكوبونات</span>
        </div>
        <div class="detail-milestones-grid" style="grid-template-columns:repeat(auto-fit, minmax(110px, 1fr)); gap:8px;">
          ${convertTiersToMilestones(matrix).map((m, idx) => `
            <div class="detail-milestone-card" style="padding:6px 8px; border-radius:8px; gap:2px; border:1.5px solid var(--brand-l); background:var(--bg-card); text-align:center; display:flex; flex-direction:column; transition:transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='none'">
              <div style="font-size:0.65rem; font-weight:700; color:var(--t3);">عند الحصول على</div>
              <div style="font-size:0.9rem; font-weight:900; color:var(--brand);">${idx === 0 ? `${m.pct}% أو أقل` : `${m.pct}% أو أكثر`}</div>
              <div style="font-size:0.65rem; font-weight:800; color:var(--brand); display:inline-flex; align-items:center; justify-content:center; gap:4px; margin-top:2px; background:var(--brand-bg); padding:2px 6px; border-radius:12px; border:1px solid var(--brand-l);">
                <i class="fas fa-star" style="color:var(--brand); font-size:0.6rem;"></i>
                <span>${m.coupons} كوبون</span>
              </div>
            </div>
          `).join('')}
        </div>
      </div>`;



        openOv('detailOv');



      } catch (e) { showToast('خطأ في تحميل التفاصيل', 'err'); }



    }







    // ─── Delete submission (internal confirm) ─────────────────────────



    let _pendingDelSub = null;



    function showDeleteSubConfirm(subId, studentName, coupons, taskId) {



      _pendingDelSub = { subId, taskId };



      const msg = document.getElementById('delSubMsg');



      if (msg) {



        msg.innerHTML = `حذف إجابة <strong>${esc(studentName || 'هذا الطفل')}</strong>؟`



          + (coupons > 0 ? `<br><span style="color:var(--err);font-size:.8rem;">سيتم خصم ${coupons} كوبون من كوبونات التاسكات فقط.</span>` : '');



      }



      openOv('delSubConfOv');



    }



    async function doDeleteSubConfirmed() {



      if (!_pendingDelSub) return;



      const { subId, taskId } = _pendingDelSub;



      _pendingDelSub = null;



      closeOv('delSubConfOv');



      try {



        const d = await api('deleteSubmission', { submission_id: subId });



        if (d.success) {



          let msg = 'تم حذف الإجابة ✓';



          if (d.coupons_reversed > 0) msg += ` — تم خصم ${d.coupons_reversed} كوبون من كوبونات التاسكات`;



          showToast(msg, 'ok', {
            text: 'تراجع',
            onClick: async () => {
              try {
                const rd = await api('restoreSubmission', { submission_id: subId });
                if (rd.success) {
                  showToast(rd.message || 'تم التراجع وإعادة الإجابة بنجاح 🎉', 'ok');
                  await openDetail(taskId);
                  loadTasks();
                } else {
                  showToast(rd.message || 'فشل التراجع', 'err');
                }
              } catch (e) {
                showToast('خطأ في الاتصال', 'err');
              }
            }
          });

          await openDetail(taskId);

          loadTasks();



        } else {



          showToast(d.message || 'فشل الحذف', 'err');



        }



      } catch (e) {



        showToast('خطأ في الاتصال', 'err');



      }



    }







    // ─── Delete task ──────────────────────────────────────────────────



    function openConf(id) {



      delId = id;



      const t = tasks.find(x => x.id == id);



      if (!t) { openOv('confOv'); return; }







      document.getElementById('confSub').textContent = `سيتم حذف "​${t.title}​" بشكل نهائي.`;







      // Count coupons awarded across all submissions for this task



      const subs = t.submissions || [];



      const totalCoupons = subs.reduce((a, s) => a + (parseInt(s.coupons_awarded) || 0), 0);



      const kidCount = subs.filter(s => (parseInt(s.coupons_awarded) || 0) > 0).length;







      const noteEl = document.getElementById('confCouponNote');



      const detailEl = document.getElementById('confCouponDetail');



      if (totalCoupons > 0) {



        detailEl.textContent = `حصل ${kidCount} طفل على إجمالي ${totalCoupons} كوبون من هذا التاسك. اختر إذا كنت تريد سحبها أو الاحتفاظ بها.`;



        noteEl.style.display = '';



      } else {



        noteEl.style.display = 'none';



      }







      openOv('confOv');



    }



    async function doDelete(reverseCoupons) {



      try {



        const d = await api('deleteTask', { task_id: delId, reverse_coupons: reverseCoupons ? '1' : '0' });



        if (d.success) {



          let msg = 'تم حذف التاسك';



          if (reverseCoupons && d.coupons_reversed > 0)



            msg += ` وسحب ${d.coupons_reversed} كوبون من الأطفال`;



          else if (!reverseCoupons)



            msg += ' واحتُظ بالكوبونات للأطفال';



          showToast(msg, reverseCoupons ? 'info' : 'ok');



          closeConf();



          await loadTasks();



        } else {



          showToast(d.message || 'فشل الحذف', 'err');



        }



      } catch (e) {



        showToast('خطأ في الاتصال', 'err');



      }



    }







    // ─── Open question grading — FULL EXAM VIEW ───────────────────────



    let gradeTaskId = null;



    let gradeSubs = [];



    let gradeTaskData = null;







    async function openGradePanel(taskId, targetSubId = null, includeGraded = true) {
      gradeTaskId = taskId;
      window.activeGradeIndex = 0;

      document.getElementById('gradePanel').classList.add('open');
      document.body.style.overflow = 'hidden';

      document.getElementById('gradePanelBody').innerHTML = `
    <div class="skeleton-row"><div class="skeleton-line" style="height:14px; width:45%;"></div><div class="skeleton-line" style="height:28px; width:60px; border-radius:6px;"></div></div>
    <div class="skeleton-row"><div class="skeleton-line" style="height:14px; width:55%;"></div><div class="skeleton-line" style="height:28px; width:60px; border-radius:6px;"></div></div>
    <div class="skeleton-row"><div class="skeleton-line" style="height:14px; width:35%;"></div><div class="skeleton-line" style="height:28px; width:60px; border-radius:6px;"></div></div>
  `;

      try {
        const td = await api('getTaskDetail', { task_id: taskId });
        gradeTaskData = td.success ? td.task : null;

        const d = await api('getPendingOpenSubmissions', { task_id: taskId, include_graded: includeGraded ? 1 : 0 });
        if (!d.success) { showToast(d.message || 'فشل', 'err'); return; }

        gradeSubs = d.submissions || [];
        if (gradeTaskData) {
          const gClasses = getTaskClassNames(gradeTaskData);
          if (gClasses.length) {
            await Promise.all(gClasses.map(c => loadStudents(c)));
          } else {
            await loadStudents(gradeTaskData.class_name || 'كل الفصول');
          }
        }

        if (targetSubId) {
          const foundIdx = gradeSubs.findIndex(s => s.id == targetSubId || s.student_id == targetSubId);
          if (foundIdx >= 0) window.activeGradeIndex = foundIdx;
        }

        // Update URL query parameters for deep linking
        try {
          const url = new URL(window.location.href);
          url.searchParams.set('taskId', taskId);
          url.searchParams.set('action', 'grade');
          if (targetSubId) url.searchParams.set('subId', targetSubId);
          window.history.replaceState({}, '', url.toString());
        } catch (err) { }

        renderGradePanel();
      } catch (e) { showToast('خطأ في الاتصال', 'err'); }
    }

    window.activeGradeIndex = 0;

    function navigateGrading(dir) {
      if (!gradeSubs || gradeSubs.length === 0) return;
      let newIdx = window.activeGradeIndex + dir;
      if (newIdx < 0 || newIdx >= gradeSubs.length) return;
      window.activeGradeIndex = newIdx;
      renderGradePanel();
    }

    function updateGradeIndicator() {
      const indicator = document.getElementById('gradeIndexIndicator');
      const prevBtn = document.getElementById('prevGradeBtn');
      const nextBtn = document.getElementById('nextGradeBtn');

      if (indicator) {
        indicator.textContent = `${window.activeGradeIndex + 1} / ${gradeSubs.length}`;
      }
      if (prevBtn) {
        prevBtn.disabled = window.activeGradeIndex === 0;
        prevBtn.style.opacity = window.activeGradeIndex === 0 ? '0.4' : '1';
        prevBtn.style.cursor = window.activeGradeIndex === 0 ? 'not-allowed' : 'pointer';
      }
      if (nextBtn) {
        nextBtn.disabled = window.activeGradeIndex === gradeSubs.length - 1;
        nextBtn.style.opacity = window.activeGradeIndex === gradeSubs.length - 1 ? '0.4' : '1';
        nextBtn.style.cursor = window.activeGradeIndex === gradeSubs.length - 1 ? 'not-allowed' : 'pointer';
      }
    }

    function renderGradePanel() {
      const el = document.getElementById('gradePanelBody');

      if (!gradeSubs || !gradeSubs.length) {
        document.getElementById('gradePanelSub').textContent = `0 طفل في قائمة التصحيح`;
        el.innerHTML = '<div style="text-align:center;padding:40px;color:var(--t3);"><i class="fas fa-check-circle" style="font-size:2rem;color:var(--ok);display:block;margin-bottom:10px;"></i>تم تصحيح جميع الإجابات!</div>';
        const indicator = document.getElementById('gradeIndexIndicator');
        if (indicator) indicator.textContent = '0 / 0';
        return;
      }

      if (window.activeGradeIndex >= gradeSubs.length) {
        window.activeGradeIndex = Math.max(0, gradeSubs.length - 1);
      }

      const pendingCount = gradeSubs.filter(s => !parseInt(s.is_graded || 0)).length;
      document.getElementById('gradePanelSub').textContent = `${gradeSubs.length} إجابة (${pendingCount} تنتظر التصحيح)`;
      updateGradeIndicator();

      const sub = gradeSubs[window.activeGradeIndex];
      const si = window.activeGradeIndex;

      const allQuestions = gradeTaskData ? (gradeTaskData.questions || []) : [];
      const totalDeg = gradeTaskData ? (gradeTaskData.total_degree || 0) : 0;
      const answers = typeof sub.answers === 'string' ? JSON.parse(sub.answers || '{}') : (sub.answers || {});
      const openScores = typeof sub.open_scores === 'string' ? JSON.parse(sub.open_scores || '{}') : (sub.open_scores || {});
      const corrNotes = typeof sub.correction_notes === 'string' ? JSON.parse(sub.correction_notes || '{}') : (sub.correction_notes || {});
      const isAlreadyGraded = parseInt(sub.is_graded || 0) === 1;

      const qRows = allQuestions.map((q, qi) => {
        const qtype = q.question_type || 'mcq';
        const qId = String(q.id);
        const imgH = q.image_url ? `<div style="margin:4px 0 8px;border-radius:8px;overflow:hidden;border:1px solid var(--bdr);"><img src="${q.image_url}" alt="" style="width:100%;max-height:160px;object-fit:contain;display:block;background:#f8fafc;"></div>` : '';
        const noteId = `gn_${sub.id}_${q.id}`;
        const existingNote = corrNotes[q.id] !== undefined ? corrNotes[q.id] : (corrNotes[qId] !== undefined ? corrNotes[qId] : '');

        const buildNoteHtml = (placeholder, defaultVal = '') => `<div class="grade-note-row">
      <i class="fas fa-comment-dots"></i>
      <div class="gn-wrap">
        <div class="gn-lbl">ملاحظة / الإجابة الصحيحة للطفل:</div>
        <textarea class="grade-note-inp" id="${noteId}" data-sub="${sub.id}" data-qid="${q.id}" placeholder="${placeholder}">${esc(existingNote || defaultVal)}</textarea>
      </div>
    </div>`;

        if (qtype === 'open') {
          const ans = answers[qId] !== undefined ? String(answers[qId]) : (answers[q.id] !== undefined ? String(answers[q.id]) : '');
          const qdeg = parseInt(q.degree) || 0;
          const isUngraded = qdeg === 0;
          const existingScore = openScores[q.id] !== undefined ? openScores[q.id] : (openScores[qId] !== undefined ? openScores[qId] : 0);
          return `<div class="grade-q-row" style="background:var(--bg2);border:1.5px solid ${isUngraded ? 'var(--bdr)' : '#fde68a'};border-radius:var(--r-sm);padding:11px 13px;margin-bottom:10px;">
        ${imgH}
        <div class="grade-q-text" style="color:${isUngraded ? 'var(--t1)' : '#b45309'};margin-bottom:4px;">
          <span style="background:${isUngraded ? 'var(--bg3);color:var(--t3)' : '#fef3c7;color:#92400e'};border-radius:var(--r-full);padding:1px 8px;font-size:.66rem;font-weight:700;margin-left:5px;"><i class="fas fa-${isUngraded ? 'comments' : 'pen-nib'}"></i> ${isUngraded ? 'استطلاعي / بدون درجات' : 'مفتوح'}</span>
          <strong>${qi + 1}.</strong> ${esc(q.question_text)} <span style="color:var(--t3);font-size:.72rem;">(${isUngraded ? 'بدون درجات' : `${qdeg} درجة`})</span>
        </div>
        <div class="grade-ans-text" style="background:var(--bg);border:1px solid ${isUngraded ? 'var(--bdr)' : '#fde68a'};">${ans ? esc(ans) : '<em style="color:var(--t3);">— لم يُجب —</em>'}</div>
        ${!isUngraded ? `
        <div class="grade-score-row">
          <span style="font-size:.78rem;color:var(--t2);font-weight:600;">الدرجة:</span>
          <input class="grade-score-inp" type="number" min="0" max="${qdeg}" value="${parseInt(existingScore) || 0}"
            id="gs_${sub.id}_${q.id}" data-sub="${sub.id}" data-qid="${q.id}" data-max="${qdeg}"
            oninput="clampGradeInput(this);updateSubScore(${sub.id})">
          <span class="grade-max-lbl">/ ${qdeg}</span>
        </div>` : ''}
        ${buildNoteHtml('اكتب ملاحظة أو تعليق للطفل…')}
      </div>`;
        }

        const given = answers[qId] !== undefined ? parseInt(answers[qId]) : (answers[q.id] !== undefined ? parseInt(answers[q.id]) : null);
        const correct = q.correct_index !== null ? parseInt(q.correct_index) : null;
        const isCorrect = given !== null && correct !== null && given === correct;
        const isWrong = given !== null && correct !== null && given !== correct;

        const statusDot = given === null
          ? `<span style="color:var(--t3);font-size:.68rem;">لم يجب</span>`
          : isCorrect
            ? `<span style="background:var(--ok-bg);color:var(--ok);border-radius:var(--r-full);padding:1px 8px;font-size:.68rem;font-weight:700;"><i class="fas fa-check"></i> صح</span>`
            : `<span style="background:var(--err-bg);color:var(--err);border-radius:var(--r-full);padding:1px 8px;font-size:.68rem;font-weight:700;"><i class="fas fa-times"></i> خطأ</span>`;

        if (qtype === 'tf') {
          const opts = ['صحيح', 'خطأ'];
          const tfNotePlaceholder = isWrong ? `الإجابة الصحيحة: ${opts[correct]}` : 'اكتب ملاحظة للطفل (اختياري)…';
          const tfNoteDefault = isWrong ? `الإجابة الصحيحة: ${opts[correct]}` : '';
          const qdeg = parseInt(q.degree) || 0;

          return `<div style="background:${isCorrect ? 'var(--ok-bg)' : isWrong ? 'var(--err-bg)' : 'var(--bg2)'};border:1.5px solid ${isCorrect ? '#6ee7b7' : isWrong ? '#fca5a5' : 'var(--bdr)'};border-radius:var(--r-sm);padding:9px 13px;margin-bottom:8px;">
        <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;">
          <span style="font-size:.75rem;font-weight:600;color:var(--t2);"><strong>${qi + 1}.</strong> ${esc(q.question_text)}</span>
          <span style="margin-right:auto;">${statusDot}</span>
          <span style="font-size:.68rem;color:var(--t3);">${qdeg === 0 ? 'بدون درجات' : `${qdeg} درجة`}</span>
        </div>
        ${imgH}
        <div style="display:flex;gap:8px;">
          ${opts.map((o, j) => {
            const isSel = given === j;
            const isCorr = correct === j;
            let bg = 'var(--bg)'; let border = 'var(--bdr)'; let clr = 'var(--t2)';
            if (isSel && isCorr) { bg = 'var(--ok-bg)'; border = '#6ee7b7'; clr = 'var(--ok)'; }
            else if (isSel && !isCorr) { bg = 'var(--err-bg)'; border = '#fca5a5'; clr = 'var(--err)'; }
            else if (isCorr && !isSel) { bg = 'var(--ok-bg)'; border = '#6ee7b7'; clr = 'var(--ok)'; }
            return `<div style="flex:1;padding:7px 10px;border-radius:7px;border:1.5px solid ${border};background:${bg};color:${clr};font-size:.8rem;font-weight:700;text-align:center;">
              ${o}${isSel ? ' <i class="fas fa-hand-pointer"></i>' : ''}${isCorr && !isSel ? ' ✓' : ''}
            </div>`;
          }).join('')}
        </div>
        ${buildNoteHtml(tfNotePlaceholder, tfNoteDefault)}
      </div>`;
        }

        const opts = typeof q.options === 'string' ? JSON.parse(q.options) : (q.options || []);
        const mcqNotePlaceholder = isWrong && correct !== null && opts[correct] ? `الإجابة الصحيحة: ${opts[correct]}` : 'اكتب ملاحظة للطفل (اختياري)…';
        const mcqNoteDefault = isWrong && correct !== null && opts[correct] ? `الإجابة الصحيحة: ${opts[correct]}` : '';
        const qdeg = parseInt(q.degree) || 0;

        return `<div style="background:${isCorrect ? 'var(--ok-bg)' : isWrong ? 'var(--err-bg)' : 'var(--bg2)'};border:1.5px solid ${isCorrect ? '#6ee7b7' : isWrong ? '#fca5a5' : 'var(--bdr)'};border-radius:var(--r-sm);padding:9px 13px;margin-bottom:8px;">
      <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;">
        <span style="font-size:.75rem;font-weight:600;color:var(--t2);"><strong>${qi + 1}.</strong> ${esc(q.question_text)}</span>
        <span style="margin-right:auto;">${statusDot}</span>
        <span style="font-size:.68rem;color:var(--t3);">${qdeg === 0 ? 'بدون درجات' : `${qdeg} درجة`}</span>
      </div>
      ${imgH}
      ${opts.map((o, j) => {
          const isSel = given === j;
          const isCorr = correct === j;
          let bg = 'var(--bg)'; let border = 'var(--bdr)'; let clr = 'var(--t2)';
          if (isSel && isCorr) { bg = 'var(--ok-bg)'; border = '#6ee7b7'; clr = 'var(--ok)'; }
          else if (isSel && !isCorr) { bg = 'var(--err-bg)'; border = '#fca5a5'; clr = 'var(--err)'; }
          else if (isCorr && !isSel) { bg = 'var(--ok-bg)'; border = '#6ee7b7'; clr = 'var(--ok)'; }
          return `<div style="display:flex;align-items:center;gap:7px;padding:6px 10px;border-radius:6px;border:1.5px solid ${border};background:${bg};color:${clr};font-size:.78rem;margin-bottom:4px;">
          <strong style="min-width:18px;">${LETTERS[j]}</strong>${esc(o)}
          ${isSel ? '<i class="fas fa-hand-pointer" style="margin-right:auto;font-size:.7rem;"></i>' : ''}
          ${isCorr && !isSel ? '<i class="fas fa-check" style="margin-right:auto;color:var(--ok);"></i>' : ''}
        </div>`;
        }).join('')}
      ${buildNoteHtml(mcqNotePlaceholder, mcqNoteDefault)}
    </div>`;
      }).join('');

      const stud = (classStuCache['كل الفصول'] || []).find(x => x.id == sub.student_id);
      const photo = stud ? stud.photo : '';
      const avatar = getStudentAvatarHtml(photo, sub.student_name, '28px');

      const gradedBadge = isAlreadyGraded
        ? `<span style="background:var(--ok-bg);color:var(--ok);border-radius:var(--r-full);padding:2px 8px;font-size:.68rem;font-weight:800;border:1px solid #6ee7b7;white-space:nowrap;"><i class="fas fa-check-circle"></i> تم التقييم</span>`
        : `<span style="background:#fef3c7;color:#92400e;border-radius:var(--r-full);padding:2px 8px;font-size:.68rem;font-weight:800;border:1px solid #fde68a;white-space:nowrap;"><i class="fas fa-clock"></i> بانتظار التقييم</span>`;

      el.innerHTML = `<div class="grade-sub-card" id="gradecard_${sub.id}" style="margin: 0; border: none; box-shadow: none;">
    <div class="grade-sub-name" style="padding: 8px 14px; background: var(--bg3); border-bottom: 1px solid var(--bdr); display:flex; align-items:center; gap:8px; flex-wrap:nowrap;">
      ${avatar}
      <div style="display:flex; flex-direction:column; min-width:0;">
        <strong style="font-size:.85rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${esc(sub.student_name || '—')}</strong>
        <span style="font-size:.68rem;color:var(--t3);font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${esc(sub.task_title || '')}</span>
      </div>
      <div style="margin-right:auto; display:flex; align-items:center; gap:6px; flex-shrink:0;">
        ${gradedBadge}
        <span style="font-size:.72rem; font-weight:700;" id="scoreDisp_${sub.id}"></span>
      </div>
    </div>
    <div style="padding: 12px 14px; overflow-y: auto; flex: 1;">
      ${qRows}
    </div>
    <div style="padding: 8px 14px; background: var(--bg3); border-top: 1px solid var(--bdr); display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: nowrap;">
      ${isAlreadyGraded ? `<button type="button" class="grade-clear-btn" onclick="clearGrade(${sub.id}, ${si})" style="background:var(--err-bg);border:1px solid #fca5a5;color:var(--err);padding:6px 12px;border-radius:8px;font-family:inherit;font-weight:700;font-size:.76rem;cursor:pointer;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;height:32px;line-height:1;"><i class="fas fa-undo"></i> مسح التقييم</button>` : '<div></div>'}
      <button class="grade-save-btn" onclick="submitGrade(${sub.id}, ${si})" style="margin:0; width:auto; padding:6px 14px; height:32px; line-height:1; border-radius:8px; font-weight:700; font-size:.76rem; white-space:nowrap; display:inline-flex; align-items:center; gap:5px;">
        <i class="fas fa-check"></i> ${isAlreadyGraded ? 'حفظ التعديل' : 'حفظ التصحيح'}
      </button>
    </div>
  </div>`;

      updateSubScore(sub.id);
    }

    async function submitGrade(subId, subIdx) {
      const scores = {};
      document.querySelectorAll(`.grade-score-inp[data-sub="${subId}"]`).forEach(inp => {
        scores[inp.dataset.qid] = parseInt(inp.value) || 0;
      });

      const notes = {};
      document.querySelectorAll(`.grade-note-inp[data-sub="${subId}"]`).forEach(ta => {
        if (ta.value.trim()) notes[ta.dataset.qid] = ta.value.trim();
      });

      const btn = document.querySelector(`#gradecard_${subId} .grade-save-btn`);
      const orig = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جارٍ الحفظ…';

      try {
        const d = await api('gradeOpenAnswer', {
          submission_id: subId,
          scores: JSON.stringify(scores),
          notes: JSON.stringify(notes)
        });

        if (d.success) {
          let couponMsg = '';
          if (d.coupon_diff > 0) couponMsg = ` — تمت إضافة ${d.coupon_diff} كوبون لكوبونات التاسكات`;
          else if (d.coupon_diff < 0) couponMsg = ` — تم خصم ${Math.abs(d.coupon_diff)} كوبون من كوبونات التاسكات`;
          else couponMsg = ' — لا تغيير في الكوبونات';

          showToast(`تم الحفظ: ${d.score}/${gradeTaskData?.total_degree || 0} درجة${couponMsg}`, 'ok');

          gradeSubs.splice(subIdx, 1);
          renderGradePanel();

          await loadTasks();
          updatePendingBadge(gradeTaskId);
        }
      } catch (e) {
        showToast('خطأ في الاتصال', 'err');
        btn.disabled = false;
        btn.innerHTML = orig;
      }
    }
    function clampGradeInput(inp) {



      const max = parseInt(inp.dataset.max) || 0;



      let v = parseInt(inp.value) || 0;



      if (v < 0) v = 0; if (v > max) v = max;



      inp.value = v;



    }







    function updateSubScore(subId) {



      // Live preview of open question scores



      let openTotal = 0;



      document.querySelectorAll(`.grade-score-inp[data-sub="${subId}"]`).forEach(inp => {



        openTotal += parseInt(inp.value) || 0;



      });



      const disp = document.getElementById(`scoreDisp_${subId}`);



      if (disp && gradeTaskData) {



        // Also add MCQ/TF score from submitted answers



        const sub = gradeSubs.find(s => s.id == subId);



        if (sub) {



          const answers = JSON.parse(sub.answers || '{}');



          let mcqScore = 0;



          (gradeTaskData.questions || []).forEach(q => {



            if (q.question_type === 'open') return;



            if (q.correct_index === null) return;



            const given = answers[String(q.id)];



            if (given !== undefined && parseInt(given) === parseInt(q.correct_index)) mcqScore += parseInt(q.degree || 0);



          });



          const total = mcqScore + openTotal;



          const pct = gradeTaskData.total_degree > 0 ? Math.round(total / gradeTaskData.total_degree * 100) : 0;



          disp.innerHTML = `<span style="background:var(--brand-bg);color:var(--brand);border-radius:var(--r-full);padding:2px 9px;font-weight:800;">${total}/${gradeTaskData.total_degree} — ${pct}%</span>`;



        }



      }



    }







    let _customConfirmResolver = null;

    function showConfirm(opts) {
      return new Promise((resolve) => {
        _customConfirmResolver = resolve;
        const modal = document.getElementById('websiteConfirmModal');
        const msgEl = document.getElementById('wConfirmMessage');
        const titleEl = document.getElementById('wConfirmTitle');
        const iconBox = document.getElementById('wConfirmIconBox');
        const icon = document.getElementById('wConfirmIcon');
        const okBtn = document.getElementById('wConfirmOkBtn');

        let rawMsg = typeof opts === 'string' ? opts : (opts.message || '');
        let cleanMsg = rawMsg.replace(/[\u{1F300}-\u{1F9FF}]|[\u{2600}-\u{26FF}]|[\u{2700}-\u{27BF}]/gu, '').replace(/\*\*/g, '').trim();

        if (msgEl) msgEl.textContent = cleanMsg;
        if (titleEl) titleEl.textContent = opts.title || (opts.danger ? 'تأكيد إجراء هام' : 'تأكيد التعديل');

        if (opts.danger) {
          if (iconBox) { iconBox.style.background = 'rgba(239,68,68,0.12)'; iconBox.style.color = 'var(--err, #ef4444)'; }
          if (icon) icon.className = 'fas fa-exclamation-triangle';
          if (okBtn) { okBtn.style.background = 'var(--err, #ef4444)'; okBtn.style.boxShadow = '0 4px 12px rgba(239,68,68,0.3)'; }
        } else {
          if (iconBox) { iconBox.style.background = 'rgba(99,102,241,0.12)'; iconBox.style.color = 'var(--brand, #6366f1)'; }
          if (icon) icon.className = 'fas fa-info-circle';
          if (okBtn) { okBtn.style.background = 'var(--brand, #6366f1)'; okBtn.style.boxShadow = '0 4px 12px rgba(99,102,241,0.3)'; }
        }

        if (modal) modal.style.display = 'flex';
      });
    }

    function resolveCustomConfirm(result) {
      const modal = document.getElementById('websiteConfirmModal');
      if (modal) modal.style.display = 'none';
      if (_customConfirmResolver) {
        _customConfirmResolver(result);
        _customConfirmResolver = null;
      }
    }
    window.showConfirm = showConfirm;

    async function clearGrade(subId, subIdx) {
      const sub = gradeSubs ? gradeSubs[subIdx] : null;
      if (!sub) return;

      const prevCoupons = parseInt(sub.coupons_awarded || 0);
      const studentName = sub.student_name || 'الطفل';

      let confirmMsg = `هل أنت متأكد من مسح تقييم هذا التكليف للطفل [${studentName}]؟\nسيتم إعادة التكليف لحالة "بانتظار التقييم" وتظهر في حساب الطفل كغير مصحح.`;
      if (prevCoupons > 0) {
        confirmMsg += `\n\nتحذير الكوبونات: سيتم خصم وسحب (${prevCoupons}) كوبون سبق منحها للطفل لهذا التكليف!`;
      }

      if (!(await showConfirm({ message: confirmMsg, danger: true }))) return;

      const btn = document.querySelector(`#gradecard_${subId} .grade-clear-btn`);
      if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جارٍ مسح التقييم…'; }

      try {
        const d = await api('resetSubmissionGrade', { submission_id: subId });
        if (d.success) {
          let couponMsg = d.prev_coupons > 0 ? ` وتم خصم ${d.prev_coupons} كوبون` : '';
          showToast(`تم مسح التقييم وإعادة التكليف لحالة "بانتظار التقييم"${couponMsg}`, 'ok');

          if (window.detailTask) {
            await loadTaskDetail(gradeTaskId);
          }
          openGradePanel(gradeTaskId, null, true);
          if (typeof loadTasks === 'function') await loadTasks();
        } else {
          showToast(d.message || 'فشل مسح التقييم', 'err');
          if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-undo"></i> مسح التقييم (إعادة كغير مصحح)'; }
        }
      } catch (e) {
        showToast('خطأ في الاتصال', 'err');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-undo"></i> مسح التقييم (إعادة كغير مصحح)'; }
      }
    }

    async function submitGrade(subId, subIdx) {
      const sub = gradeSubs ? gradeSubs[subIdx] : null;

      const scores = {};
      document.querySelectorAll(`.grade-score-inp[data-sub="${subId}"]`).forEach(inp => {
        scores[inp.dataset.qid] = parseInt(inp.value) || 0;
      });

      const notes = {};
      document.querySelectorAll(`.grade-note-inp[data-sub="${subId}"]`).forEach(ta => {
        if (ta.value.trim()) notes[ta.dataset.qid] = ta.value.trim();
      });

      // Calculate projected new score & projected new coupons
      const allQuestions = gradeTaskData ? (gradeTaskData.questions || []) : [];
      const answers = typeof sub?.answers === 'string' ? JSON.parse(sub.answers || '{}') : (sub?.answers || {});
      let mcqScore = 0;
      let openScore = 0;
      allQuestions.forEach(q => {
        if ((q.question_type || 'mcq') === 'open') {
          openScore += parseInt(scores[q.id] ?? scores[String(q.id)] ?? 0);
        } else if (q.correct_index !== null && q.correct_index !== undefined) {
          const given = answers[q.id] !== undefined ? answers[q.id] : answers[String(q.id)];
          if (given !== null && given !== undefined && parseInt(given) === parseInt(q.correct_index)) {
            mcqScore += parseInt(q.degree || 0);
          }
        }
      });
      const totalScore = mcqScore + openScore;
      const totalDeg = gradeTaskData?.total_degree || 0;
      const pct = totalDeg > 0 ? (totalScore / totalDeg * 100) : 0;
      const matrix = typeof gradeTaskData?.coupon_matrix === 'string' ? JSON.parse(gradeTaskData.coupon_matrix || '[]') : (gradeTaskData?.coupon_matrix || []);
      let projectedCoupons = 0;
      for (const tier of matrix) {
        if (pct >= parseFloat(tier.from) && pct <= parseFloat(tier.to)) {
          projectedCoupons = parseInt(tier.val) || 0;
          break;
        }
      }

      const prevCoupons = parseInt(sub?.coupons_awarded || 0);
      const isAlreadyGraded = parseInt(sub?.is_graded || 0) === 1;
      const couponDiff = projectedCoupons - prevCoupons;
      const studentName = sub?.student_name || 'الطفل';

      // Always warn if coupon changes!
      if (couponDiff !== 0) {
        let warnMsg = ``;
        if (isAlreadyGraded) {
          if (couponDiff > 0) {
            warnMsg = `تأكيد تعديل التقييم والكوبونات:\nسيتم تعديل درجات الطفل [${studentName}] وتعديل الكوبونات بإضافة (+${couponDiff}) كوبون إضافي.\n\nهل تريد الاستمرار والحفظ؟`;
          } else {
            warnMsg = `تأكيد تعديل التقييم والكوبونات:\nسيتم تعديل درجات الطفل [${studentName}] وتعديل الكوبونات بخصم (-${Math.abs(couponDiff)}) كوبون.\n\nهل تريد الاستمرار والحفظ؟`;
          }
        } else {
          warnMsg = `تأكيد اعتماد التقييم:\nسيتم اعتماد درجات الطفل [${studentName}] وحصوله على (${projectedCoupons}) كوبون.\n\nهل تريد حفظ التصحيح؟`;
        }

        if (!(await showConfirm({ message: warnMsg, danger: couponDiff < 0 }))) {
          return;
        }
      }

      const btn = document.querySelector(`#gradecard_${subId} .grade-save-btn`);
      const orig = btn ? btn.innerHTML : '';
      if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جارٍ الحفظ…'; }

      try {
        const d = await api('gradeOpenAnswer', {
          submission_id: subId,
          scores: JSON.stringify(scores),
          notes: JSON.stringify(notes)
        });

        if (d.success) {
          let couponMsg = '';
          if (d.coupon_diff > 0) couponMsg = ` — تمت إضافة ${d.coupon_diff} كوبون لكوبونات التاسكات`;
          else if (d.coupon_diff < 0) couponMsg = ` — تم خصم ${Math.abs(d.coupon_diff)} كوبون من كوبونات التاسكات`;
          else couponMsg = ' — لا تغيير في الكوبونات';

          showToast(`تم الحفظ: ${d.score}/${gradeTaskData?.total_degree || 0} درجة${couponMsg}`, 'ok');

          if (window.detailTask) {
            await loadTaskDetail(gradeTaskId);
          }
          openGradePanel(gradeTaskId, null, true);
          if (typeof loadTasks === 'function') await loadTasks();
        } else showToast(d.message || 'فشل', 'err');

      } catch (e) { showToast('خطأ', 'err'); }

      if (btn) { btn.disabled = false; btn.innerHTML = orig; }
    }







    function closeGradePanel() {
      document.getElementById('gradePanel').classList.remove('open');
      document.body.style.overflow = '';
      try {
        const url = new URL(window.location.href);
        url.searchParams.delete('action');
        url.searchParams.delete('subId');
        url.searchParams.delete('sub_id');
        window.history.replaceState({}, '', url.toString());
      } catch (e) { }
    }







    function updatePendingBadge(taskId) {



      loadTasks();



    }







    function openModal(html) {
      const ov = document.createElement('div');
      ov.className = 'overlay open';
      ov.style.zIndex = '3000';
      ov.style.padding = '16px';
      ov.style.display = 'flex';
      ov.style.alignItems = 'center';
      ov.style.justifyContent = 'center';

      ov.innerHTML = `
    <div class="modal" style="width:92%; max-width:840px; max-height:88vh; max-height:88dvh; margin:auto; border-radius:20px; display:flex; flex-direction:column; background:var(--bg); border:1px solid var(--bdr); box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); overflow:hidden;">
      <div class="mhdr" style="background:var(--bg); padding:16px 20px; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid var(--bdr);">
        <div style="display:flex; align-items:center; gap:10px;">
          <div style="width:34px;height:34px;border-radius:10px;background:var(--bg2);color:var(--t1);border:1px solid var(--bdr);display:flex;align-items:center;justify-content:center;"><i class="fas fa-eye" style="font-size:.85rem;"></i></div>
          <div style="color:var(--t1);font-weight:800;font-size:1rem;font-family:'Cairo',sans-serif;">مراجعة الإجابات</div>
        </div>
        
        <!-- Navigation Arrows -->
        <div class="modal-nav-arrows" style="display:flex; align-items:center; gap:10px; margin-right:auto; margin-left:20px; direction:rtl;">
          <button onclick="navigateSubmission(-1)" id="prevSubBtn" style="background:var(--bg2); border:1px solid var(--bdr); color:var(--t1); width:34px; height:34px; border-radius:8px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.2s;" onmouseover="this.style.background='var(--brand-bg)'; this.style.color='var(--brand)';" onmouseout="this.style.background='var(--bg2)'; this.style.color='var(--t1)';"><i class="fas fa-chevron-right"></i></button>
          <span id="subIndexIndicator" style="font-size:0.8rem; font-weight:800; color:var(--t2); min-width:45px; text-align:center;">—</span>
          <button onclick="navigateSubmission(1)" id="nextSubBtn" style="background:var(--bg2); border:1px solid var(--bdr); color:var(--t1); width:34px; height:34px; border-radius:8px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.2s;" onmouseover="this.style.background='var(--brand-bg)'; this.style.color='var(--brand)';" onmouseout="this.style.background='var(--bg2)'; this.style.color='var(--t1)';"><i class="fas fa-chevron-left"></i></button>
        </div>

        <button onclick="this.closest('.overlay').remove(); document.documentElement.classList.remove('ov-open');" style="background:var(--bg2); border:1px solid var(--bdr); color:var(--t2); width:32px; height:32px; border-radius:10px; cursor:pointer; font-size:.85rem; display:flex; align-items:center; justify-content:center; transition:0.2s;" onmouseover="this.style.background='var(--err-bg)'; this.style.color='var(--err)';" onmouseout="this.style.background='var(--bg2)'; this.style.color='var(--t2)';"><i class="fas fa-times"></i></button>
      </div>
      <div class="mbody" id="modalSubmissionsBody" style="padding:0; flex:1; overflow-y:auto; background:var(--bg);">${html}</div>
    </div>
  `;

      document.body.appendChild(ov);
      ov.onclick = (e) => { if (e.target === ov) { ov.remove(); document.documentElement.classList.remove('ov-open'); } };
      document.documentElement.classList.add('ov-open');
    }

    window.activeSubmissionList = [];
    window.activeSubmissionIndex = -1;
    window.activeSubmissionTaskId = null;

    function navigateSubmission(dir) {
      if (!window.activeSubmissionList || window.activeSubmissionList.length === 0) return;

      let newIdx = window.activeSubmissionIndex + dir;
      if (newIdx < 0 || newIdx >= window.activeSubmissionList.length) return;

      window.activeSubmissionIndex = newIdx;
      const sub = window.activeSubmissionList[newIdx];

      updateSubmissionIndicator();

      const html = getSubmissionAnswersHtml(window.activeSubmissionTaskId, sub.student_id);
      document.getElementById('modalSubmissionsBody').innerHTML = html;
    }

    function updateSubmissionIndicator() {
      const indicator = document.getElementById('subIndexIndicator');
      const prevBtn = document.getElementById('prevSubBtn');
      const nextBtn = document.getElementById('nextSubBtn');

      if (indicator) {
        indicator.textContent = `${window.activeSubmissionIndex + 1} / ${window.activeSubmissionList.length}`;
      }
      if (prevBtn) {
        prevBtn.disabled = window.activeSubmissionIndex === 0;
        prevBtn.style.opacity = window.activeSubmissionIndex === 0 ? '0.4' : '1';
        prevBtn.style.cursor = window.activeSubmissionIndex === 0 ? 'not-allowed' : 'pointer';
      }
      if (nextBtn) {
        nextBtn.disabled = window.activeSubmissionIndex === window.activeSubmissionList.length - 1;
        nextBtn.style.opacity = window.activeSubmissionIndex === window.activeSubmissionList.length - 1 ? '0.4' : '1';
        nextBtn.style.cursor = window.activeSubmissionIndex === window.activeSubmissionList.length - 1 ? 'not-allowed' : 'pointer';
      }
    }

    function getSubmissionAnswersHtml(taskId, studentId) {
      const t = tasks.find(x => x.id == taskId);
      if (!t) return '';

      const subs = (typeof detailTask !== 'undefined' && detailTask && detailTask.id == taskId) ? (detailTask.submissions || []) : (t.submissions || []);
      const sub = subs.find(x => x.student_id == studentId);
      if (!sub) return '';

      const ans = typeof sub.answers === 'string' ? JSON.parse(sub.answers) : (sub.answers || {});
      const openScores = typeof sub.open_scores === 'string' ? JSON.parse(sub.open_scores) : (sub.open_scores || {});
      const correctionNotes = typeof sub.correction_notes === 'string' ? JSON.parse(sub.correction_notes) : (sub.correction_notes || {});

      const score = sub.score ?? sub.total_score ?? 0;
      const totalDeg = t.total_degree || 0;
      const pct = totalDeg > 0 ? Math.round(score / totalDeg * 100) : 0;
      const scoreColor = pct >= 80 ? 'var(--ok)' : pct >= 50 ? 'var(--warn)' : 'var(--err)';
      const scoreBg = pct >= 80 ? 'var(--ok-bg)' : pct >= 50 ? 'var(--warn-bg)' : 'var(--err-bg)';

      const stud = (classStuCache['كل الفصول'] || []).find(x => x.id == studentId);
      const photo = stud ? stud.photo : '';
      const avatar = getStudentAvatarHtml(photo, sub.student_name, '40px');

      const isSubGraded = parseInt(sub.is_graded || 0) === 1;
      const hasOpenQuestions = (t.questions || []).some(q => (q.question_type || 'mcq') === 'open' && (parseInt(q.degree) || 0) > 0);

      const subCoupons = parseInt(sub.coupons_awarded || 0);
      const couponsHeaderHtml = isSubGraded
        ? `<div style="text-align:center;flex-shrink:0;background:var(--cou-bg);border:1.5px solid #c4b5fd;border-radius:10px;padding:6px 12px;">
             <div style="font-size:1.15rem;font-weight:900;color:var(--cou);line-height:1.1;"><i class="fas fa-star" style="color:var(--cou-l);"></i> ${subCoupons} <span style="font-size:.75rem;font-weight:700;">كوبون</span></div>
           </div>`
        : '';

      const scoreHeaderHtml = isSubGraded
        ? `<div style="text-align:center;flex-shrink:0;">
        <div style="font-size:1.4rem;font-weight:900;color:${scoreColor};line-height:1;">${score}<span style="font-size:.85rem;font-weight:600;color:var(--t3);">/${totalDeg}</span></div>
        <div style="display:inline-flex;align-items:center;gap:4px;background:${scoreBg};color:${scoreColor};border-radius:var(--r-full);padding:2px 9px;font-size:.7rem;font-weight:700;margin-top:4px;">${pct}%</div>
      </div>`
        : `<div style="text-align:center;flex-shrink:0;">
        <div style="display:inline-flex;align-items:center;gap:5px;background:#fef3c7;color:#92400e;border-radius:var(--r-full);padding:4px 11px;font-size:.78rem;font-weight:800;border:1px solid #fde68a;"><i class="fas fa-clock"></i> لم يتم التقييم بعد</div>
      </div>`;

      let html = `<div class="ans-shell">
    <div class="ans-head" style="align-items:center;gap:12px;">
      <div class="ans-avatar" style="background:none;border:none;display:flex;align-items:center;justify-content:center;padding:0;">${avatar}</div>
      <div style="flex:1;min-width:0;">
        <div class="ans-name">${esc(sub.student_name)}</div>
        <div class="ans-sub">أجاب على التاسك: <strong>${esc(t.title || '')}</strong></div>
      </div>
      ${scoreHeaderHtml}
      ${couponsHeaderHtml}
      ${hasOpenQuestions ? `<button onclick="document.querySelector('.overlay.open')?.remove();document.documentElement.classList.remove('ov-open');openGradePanel(${t.id},${sub.id},true)" style="background:var(--warn-bg);border:1.5px solid #fde68a;color:#b45309;padding:6px 12px;border-radius:8px;font-family:'Cairo',sans-serif;font-weight:800;font-size:.78rem;cursor:pointer;flex-shrink:0;"><i class="fas fa-pen-nib"></i> ${isSubGraded ? 'تعديل الدرجة' : 'تصحيح'}</button>` : ''}
    </div>`;

      if (!t.questions || t.questions.length === 0) {
        html += `<div style="text-align:center;padding:40px;color:var(--t4);font-size:.88rem;"><i class="fas fa-question-circle" style="font-size:2rem;display:block;margin-bottom:10px;color:var(--t4);"></i>لا توجد أسئلة لهذا التاسك.</div>`;
      } else {
        t.questions.forEach((q, i) => {
          const qType = q.question_type || 'mcq';
          const given = ans[q.id] !== undefined ? ans[q.id] : ans[String(q.id)];
          const correctIdx = q.correct_index !== null ? parseInt(q.correct_index) : null;
          const qdeg = parseInt(q.degree) || 0;
          const imgH = q.image_url ? `<div style="margin:0 0 10px;border-radius:var(--r-md);overflow:hidden;border:1px solid var(--bdr);"><img src="${esc(q.image_url)}" alt="" style="width:100%;max-height:200px;object-fit:contain;display:block;background:var(--bg2);"></div>` : '';

          html += `<div class="ans-question">`;
          html += imgH;
          html += `<div class="ans-qhead">
        <div class="ans-qnum">${i + 1}</div>
        <div class="ans-qtext">${esc(q.question_text)}</div>
        <div style="flex-shrink:0;font-size:.7rem;color:var(--t3);font-weight:600;padding:2px 7px;background:var(--bg2);border:1px solid var(--bdr);border-radius:var(--r-full);">${qdeg === 0 ? 'بدون درجات' : `${qdeg} درجة`}</div>
      </div>`;

          if (qType === 'open') {
            const hasAns = given && String(given).trim().length > 0;
            html += `<div class="ans-open">
          <div class="ans-open-label">إجابة الطفل (إجابة مفتوحة):</div>
          <div class="ans-open-text" style="${!hasAns ? 'color:var(--t4);font-style:italic;' : ''}">${hasAns ? esc(given) : '— لم يُجب على هذا السؤال —'}</div>
        </div>`;

            if (qdeg === 0) {
              html += `
          <div style="margin-top: 10px; padding: 10px; border-radius: var(--r-md); background: var(--bg2); border: 1px solid var(--bdr);">
            <div style="font-weight: 700; font-size: 0.82rem; color: var(--t3);">
              <i class="fas fa-info-circle"></i> سؤال استطلاعي / رأي — بدون درجات (لا يتطلب تقييماً)
            </div>
          </div>`;
            } else if (sub.is_graded == 1 || openScores[q.id] !== undefined || openScores[String(q.id)] !== undefined) {
              const openScoreVal = openScores[q.id] !== undefined ? openScores[q.id] : openScores[String(q.id)];
              const corrNoteVal = correctionNotes[q.id] !== undefined ? correctionNotes[q.id] : correctionNotes[String(q.id)];
              const scoreDisplay = openScoreVal !== undefined ? openScoreVal : 0;
              html += `
          <div style="margin-top: 10px; padding: 10px; border-radius: var(--r-md); background: var(--bg2); border: 1px solid var(--bdr);">
            <div style="font-weight: 700; font-size: 0.85rem; color: var(--ok); margin-bottom: 5px;">
              <i class="fas fa-check-double"></i> درجة تصحيح السؤال: 
              <span style="font-size: 1rem; color: var(--t1); font-weight: 900;">${scoreDisplay}</span> من <span>${qdeg}</span>
            </div>`;
              if (corrNoteVal && String(corrNoteVal).trim().length > 0) {
                html += `
            <div style="font-size: 0.8rem; color: var(--t2); margin-top: 5px;">
              <strong style="color: var(--t3);"><i class="fas fa-comment-dots"></i> ملاحظات التصحيح:</strong> 
              <span>${esc(corrNoteVal)}</span>
            </div>`;
              }
              html += `</div>`;
            }
          } else {
            const opts = typeof q.options === 'string' ? JSON.parse(q.options) : (q.options || []);
            if (qType === 'tf') { opts[0] = 'صواب'; opts[1] = 'خطأ'; }

            html += `<div style="display:flex;flex-direction:column;gap:7px;">`;
            opts.forEach((o, j) => {
              const isCorr = j === correctIdx;
              const isSel = given !== undefined && parseInt(given) === j;
              let cls = '';
              let icon = '';

              if (isCorr && isSel) { cls = 'correct'; icon = `<i class="fas fa-check-circle ans-choice-icon" style="color:var(--ok);"></i>`; }
              else if (isCorr) { cls = 'correct'; icon = `<i class="fas fa-check ans-choice-icon" style="color:var(--ok);opacity:.55;"></i>`; }
              else if (isSel) { cls = 'wrong'; icon = `<i class="fas fa-times-circle ans-choice-icon" style="color:var(--err);"></i>`; }

              html += `<div class="ans-choice ${cls}" style="${isSel ? 'font-weight:700;' : ''}" >
            <span class="ans-choice-letter">${LETTERS[j] || j + 1}</span>
            <span>${esc(o)}</span>
            ${icon}
            ${isSel ? `<span style="font-size:.65rem;font-weight:700;padding:1px 7px;border-radius:var(--r-full);background:${isCorr ? 'var(--ok)' : 'var(--err)'};color:#fff;flex-shrink:0;">${isCorr ? 'إجابتك ✓' : 'إجابتك ✗'}</span>` : ''}
          </div>`;
            });
            html += `</div>`;
          }
          html += `</div>`;
        });
      }
      html += `</div>`;
      return html;
    }

    function viewAnswers(taskId, studentId) {
      const t = tasks.find(x => x.id == taskId);
      if (!t) return;

      const subs = (typeof detailTask !== 'undefined' && detailTask && detailTask.id == taskId) ? (detailTask.submissions || []) : (t.submissions || []);

      window.activeSubmissionList = subs;
      window.activeSubmissionIndex = subs.findIndex(x => x.student_id == studentId);
      window.activeSubmissionTaskId = taskId;

      const html = getSubmissionAnswersHtml(taskId, studentId);
      openModal(html);
      updateSubmissionIndicator();
    }
    // ─── Overlay helpers ─────────────────────────────────────────────



    function openOv(id) { document.getElementById(id).classList.add('open'); document.body.style.overflow = 'hidden'; }



    function closeOv(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow = ''; }



    function closeCreate() { closeOv('createOv'); }



    function closeDetail() { closeOv('detailOv'); }



    function closeConf() { closeOv('confOv'); }



    function overlayOnBg() {



      ['createOv', 'detailOv', 'confOv', 'taskQuestionsExportOv'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('click', function (e) { if (e.target === this) closeOv(id); });
      });



    }







    // ─── Utils ───────────────────────────────────────────────────────



    function esc(s) { return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }



    function parseDateSafe(iso) {
      if (!iso) return null;
      let s = String(iso).trim();
      if (!s) return null;
      if (s.indexOf('T') === -1 && s.indexOf(' ') !== -1) s = s.replace(' ', 'T');
      const d = new Date(s);
      return isNaN(d.getTime()) ? null : d;
    }

    function fmtDate(iso) {
      const d = parseDateSafe(iso);
      if (!d) return '—';
      return d.toLocaleDateString('ar-EG', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    }

    function toLocalDT(iso) {
      const d = parseDateSafe(iso);
      if (!d) return '';
      const p = n => String(n).padStart(2, '0');
      return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
    }

    function toLocalDateOnly(iso) {
      const d = parseDateSafe(iso);
      if (!d) return '';
      const p = n => String(n).padStart(2, '0');
      return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
    }

    function isEndDateOnly(iso) {
      const d = parseDateSafe(iso);
      if (!d) return false;
      return d.getHours() === 23 && d.getMinutes() === 59;
    }



    function toggleNoDeadline(sync = false) {



      const noDeadline = document.getElementById('fNoDeadline').checked;



      const block = document.getElementById('deadlineBlock');



      const endDateMode = document.getElementById('fEndDateMode');



      const note = document.getElementById('endModeNote');



      if (sync && !noDeadline) {



        toggleEndDateMode(false);



      }



      if (block) block.classList.toggle('is-disabled', noDeadline);



      if (endDateMode) endDateMode.disabled = noDeadline;



      if (note && noDeadline) {
        note.textContent = 'لن يكون هناك آخر موعد، وستظل التاسك متاحة بعد البداية.';
      } else if (note) {
        note.textContent = document.getElementById('fEndDateMode')?.checked
          ? 'سيُغلق الامتحان تلقائياً في نهاية هذا اليوم.'
          : 'سيُغلق الامتحان في الساعة التي تحددها هنا.';
      }
      if (typeof updateTimingSummaryBadge === 'function') updateTimingSummaryBadge();



    }



    function syncDateOnlyFromDateTime() {



      const dt = document.getElementById('fEnd').value;



      if (!dt) return;



      document.getElementById('fEndDateOnly').value = dt.split('T')[0];



    }



    function syncDateTimeFromDateOnly() {



      const dateOnly = document.getElementById('fEndDateOnly').value;



      if (!dateOnly) return;



      document.getElementById('fEnd').value = `${dateOnly}T23:59`;



    }



    function toggleEndDateMode(syncFromCurrent = true) {
      if (document.getElementById('fNoDeadline')?.checked) return;
      const dateMode = document.getElementById('fEndDateMode')?.checked;
      const endInput = document.getElementById('fEnd');
      const endDateOnly = document.getElementById('fEndDateOnly');
      const note = document.getElementById('endModeNote');
      if (syncFromCurrent) {
        if (dateMode) syncDateOnlyFromDateTime();
        else syncDateTimeFromDateOnly();
      }
      if (endInput) endInput.style.display = dateMode ? 'none' : '';
      if (endDateOnly) endDateOnly.style.display = dateMode ? '' : 'none';
      if (note) {
        note.textContent = dateMode
          ? 'سيُغلق الامتحان تلقائياً في نهاية هذا اليوم.'
          : 'سيُغلق الامتحان في الساعة التي تحددها هنا.';
      }
      if (typeof updateTimingSummaryBadge === 'function') updateTimingSummaryBadge();
    }



    function getNormalizedEndDateValue() {



      if (document.getElementById('fNoDeadline').checked) return '';



      const dateMode = document.getElementById('fEndDateMode').checked;



      if (dateMode) {



        const dateOnly = document.getElementById('fEndDateOnly').value;



        return dateOnly ? `${dateOnly}T23:59` : '';



      }



      return document.getElementById('fEnd').value;



    }



    function applyDuePreset(days) {



      if (document.getElementById('fNoDeadline').checked) return;



      const startVal = document.getElementById('fStart').value;



      const base = startVal ? new Date(startVal) : new Date();



      if (Number.isNaN(base.getTime())) return;



      const target = new Date(base.getTime());



      target.setDate(target.getDate() + days);



      const p = n => String(n).padStart(2, '0');



      const dateOnly = `${target.getFullYear()}-${p(target.getMonth() + 1)}-${p(target.getDate())}`;



      document.getElementById('fEndDateOnly').value = dateOnly;



      document.getElementById('fEnd').value = `${dateOnly}T23:59`;



      if (document.getElementById('fEndDateMode').checked) {



        document.getElementById('endModeNote').textContent = 'سيُغلق الامتحان تلقائياً في نهاية هذا اليوم.';



      }



    }



    function setDefaultDates() {

      const now = new Date(); const p = n => String(n).padStart(2, '0');

      const f = d => `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;

      const fd = d => `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;

      const weekLater = new Date(now.getTime() + 7 * 24 * 60 * 60 * 1000);

      document.getElementById('fStart').value = f(now);

      document.getElementById('fEnd').value = f(weekLater);

      document.getElementById('fEndDateOnly').value = fd(weekLater);

      if (typeof updateTimingSummaryBadge === 'function') updateTimingSummaryBadge();

    }

    function updateTimingSummaryBadge() {
      const pill = document.getElementById('timingSummaryPill');
      const text = document.getElementById('timingSummaryText');
      if (!text) return;

      const noDeadline = document.getElementById('fNoDeadline')?.checked;
      if (noDeadline) {
        text.textContent = 'بدون تاريخ إنهاء (التاسك مفتوحة بدون حد أقصى)';
        return;
      }

      const startVal = document.getElementById('fStart')?.value;
      const endVal = typeof getNormalizedEndDateValue === 'function' ? getNormalizedEndDateValue() : (document.getElementById('fEnd')?.value || '');

      let str = '';
      if (startVal) {
        const sDate = typeof parseDateSafe === 'function' ? parseDateSafe(startVal) : new Date(startVal);
        if (sDate && !isNaN(sDate.getTime())) {
          str += 'تبدأ في: ' + (typeof formatDateArabic === 'function' ? formatDateArabic(sDate) : sDate.toLocaleString('ar-EG'));
        }
      } else {
        str += 'تبدأ فوراً';
      }

      if (endVal) {
        const eDate = typeof parseDateSafe === 'function' ? parseDateSafe(endVal) : new Date(endVal);
        if (eDate && !isNaN(eDate.getTime())) {
          str += ' — تنتهي في: ' + (typeof formatDateArabic === 'function' ? formatDateArabic(eDate) : eDate.toLocaleString('ar-EG'));
        }
      } else {
        str += ' — بدون موعد إغلاق محدد';
      }

      text.textContent = str;
    }
    window.updateTimingSummaryBadge = updateTimingSummaryBadge;

    function toggleTimerRowDisplay(checked) {
      const r = document.getElementById('timerRow');
      if (r) r.style.display = checked ? 'block' : 'none';
    }
    window.toggleTimerRowDisplay = toggleTimerRowDisplay;

    function applySimplePreset(days) {
      if (typeof applyDuePreset === 'function') applyDuePreset(days);
      updateTimingSummaryBadge();
    }
    window.applySimplePreset = applySimplePreset;

    function setNoDeadlineMode() {
      const nd = document.getElementById('fNoDeadline');
      if (nd) {
        nd.checked = true;
        if (typeof toggleNoDeadline === 'function') toggleNoDeadline(true);
      }
      updateTimingSummaryBadge();
    }
    window.setNoDeadlineMode = setNoDeadlineMode;

    function onEndDateTimeChange() {
      if (typeof syncDateOnlyFromDateTime === 'function') syncDateOnlyFromDateTime();
      updateTimingSummaryBadge();
    }
    window.onEndDateTimeChange = onEndDateTimeChange;



    function showToast(msg, type = 'info', action = null) {
      const tc = document.getElementById('tc');
      const t = document.createElement('div');
      t.className = `toast ${type}`;
      t.style.display = 'flex';
      t.style.alignItems = 'center';
      t.style.justifyContent = 'space-between';
      t.style.gap = '12px';

      const ic = type === 'ok' ? 'fa-check-circle' : type === 'err' ? 'fa-exclamation-circle' : 'fa-info-circle';
      let actionBtnHtml = '';
      if (action && action.text && typeof action.onClick === 'function') {
        const actionId = 'toast_act_' + Math.random().toString(36).substr(2, 9);
        window[actionId] = action.onClick;
        actionBtnHtml = `<button onclick="window['${actionId}'] && window['${actionId}'](); this.disabled=true; this.textContent='جارٍ...';" style="margin-right:auto; flex-shrink:0; background:rgba(255,255,255,0.22); border:1px solid rgba(255,255,255,0.4); color:inherit; font-weight:800; font-size:0.8rem; padding:4px 12px; border-radius:8px; cursor:pointer; font-family:inherit; transition:0.2s;"><i class="fas fa-undo"></i> ${action.text}</button>`;
      }

      t.innerHTML = `<div style="display:flex;align-items:center;gap:8px;"><i class="fas ${ic}"></i><span>${msg}</span></div>${actionBtnHtml}`;
      tc.appendChild(t);
      requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
      const dur = action ? 6500 : 3200;
      setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 350); }, dur);
    }



    // ─── Tasks Overview and Export Logic ──────────────────────────────────────────

    let overviewSelectedTaskIds = new Set();
    let overviewAvailableTasks = [];

    async function openTasksOverviewModal() {
      showToast('جاري جلب البيانات...', 'info');
      try {
        const d = await api('getTasks', {});
        if (d && d.success && Array.isArray(d.tasks)) {
          tasks = d.tasks;
        }
        await loadStudents('كل الفصول');
      } catch (e) {
        console.error('Error loading data for overview modal:', e);
      }

      openOv('overviewOv');
      buildOverviewClassesList();
      buildOverviewTasksList();
      renderOverviewTable();
    }

    function buildOverviewClassesList() {
      const container = document.getElementById('ovClassesList');
      if (!container) return;
      container.innerHTML = '';

      allClasses.forEach(cl => {
        const wrap = document.createElement('label');
        wrap.className = 'ov-chip';

        const chk = document.createElement('input');
        chk.type = 'checkbox';
        chk.value = cl.arabic_name;
        chk.className = 'ov-class-checkbox';

        if (!CFG.activeClass || CFG.activeClass === 'كل الفصول' || cl.arabic_name === CFG.activeClass) {
          chk.checked = true;
        }

        chk.onchange = () => {
          updateOverviewClassesCountBadge();
          buildOverviewTasksList();
          renderOverviewTable();
        };

        wrap.appendChild(chk);
        wrap.appendChild(document.createTextNode(' ' + cl.arabic_name));
        container.appendChild(wrap);
      });

      updateOverviewClassesCountBadge();
    }

    function updateOverviewClassesCountBadge() {
      const checked = document.querySelectorAll('.ov-class-checkbox:checked').length;
      const total = document.querySelectorAll('.ov-class-checkbox').length;
      const badge = document.getElementById('ovClassesCountBadge');
      if (badge) badge.textContent = `${checked}/${total}`;
    }

    function selectOverviewAllClasses(checked) {
      document.querySelectorAll('.ov-class-checkbox').forEach(cb => { cb.checked = checked; });
      updateOverviewClassesCountBadge();
      buildOverviewTasksList();
      renderOverviewTable();
    }

    function buildOverviewTasksList() {
      const container = document.getElementById('ovTasksList');
      if (!container) return;

      const checkedClassNames = Array.from(document.querySelectorAll('.ov-class-checkbox:checked')).map(cb => cb.value);
      const checkedClassIds = checkedClassNames.map(cn => {
        const found = allClasses.find(c => c.arabic_name === cn);
        return found ? String(found.id) : null;
      }).filter(Boolean);

      // Filter tasks matching checked classes
      overviewAvailableTasks = tasks.filter(t => {
        if (t.status === 'draft') return false;
        const tClasses = getTaskClassNames(t);
        if (t.class_id === 0 || tClasses.includes('كل الفصول')) return true;
        if (tClasses.some(cn => checkedClassNames.includes(cn))) return true;
        if (t.class_ids) {
          const ids = String(t.class_ids).split(',').map(s => s.trim()).filter(Boolean);
          if (ids.some(id => checkedClassIds.includes(id))) return true;
        }
        return false;
      });

      // Sort available tasks according to ovTaskSort
      sortOverviewTasksArray(overviewAvailableTasks);

      container.innerHTML = '';
      if (overviewAvailableTasks.length === 0) {
        container.innerHTML = '<div style="font-size:0.75rem; color:var(--t3); padding:8px 4px;">لا توجد تاسكات لهذه الفصول.</div>';
        updateOverviewTasksCountBadge();
        return;
      }

      // If overviewSelectedTaskIds is empty, default to checking all available tasks
      const isFirstInit = overviewSelectedTaskIds.size === 0;

      overviewAvailableTasks.forEach(t => {
        const wrap = document.createElement('label');
        wrap.className = 'ov-chip ov-task-chip';
        wrap.setAttribute('data-task-title', (t.title || '').toLowerCase());

        const chk = document.createElement('input');
        chk.type = 'checkbox';
        chk.value = String(t.id);
        chk.className = 'ov-task-checkbox';

        if (isFirstInit || overviewSelectedTaskIds.has(String(t.id))) {
          chk.checked = true;
          overviewSelectedTaskIds.add(String(t.id));
        } else {
          chk.checked = false;
        }

        chk.onchange = () => {
          if (chk.checked) {
            overviewSelectedTaskIds.add(String(t.id));
          } else {
            overviewSelectedTaskIds.delete(String(t.id));
          }
          updateOverviewTasksCountBadge();
          renderOverviewTable();
        };

        wrap.appendChild(chk);
        const titleSpan = document.createElement('span');
        titleSpan.textContent = t.title + (t.total_degree ? ` (${t.total_degree}د)` : '');
        wrap.appendChild(titleSpan);
        container.appendChild(wrap);
      });

      updateOverviewTasksCountBadge();
    }

    function sortOverviewTasksArray(arr) {
      const sortMode = document.getElementById('ovTaskSort') ? document.getElementById('ovTaskSort').value : 'created_asc';
      if (sortMode === 'created_desc') {
        arr.sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0));
      } else if (sortMode === 'title_asc') {
        arr.sort((a, b) => (a.title || '').localeCompare(b.title || '', 'ar'));
      } else { // created_asc
        arr.sort((a, b) => new Date(a.created_at || 0) - new Date(b.created_at || 0));
      }
    }

    function handleOverviewTaskSortChange() {
      buildOverviewTasksList();
      renderOverviewTable();
    }

    function filterOverviewTasksList() {
      const q = (document.getElementById('ovTaskFilterInput')?.value || '').trim().toLowerCase();
      const chips = document.querySelectorAll('.ov-task-chip');
      chips.forEach(chip => {
        const title = chip.getAttribute('data-task-title') || '';
        if (!q || title.includes(q)) {
          chip.style.display = 'inline-flex';
        } else {
          chip.style.display = 'none';
        }
      });
    }

    function updateOverviewTasksCountBadge() {
      const checked = document.querySelectorAll('.ov-task-checkbox:checked').length;
      const total = document.querySelectorAll('.ov-task-checkbox').length;
      const badge = document.getElementById('ovTasksCountBadge');
      if (badge) badge.textContent = `${checked}/${total}`;
    }

    function selectOverviewAllTasks(checked) {
      document.querySelectorAll('.ov-task-checkbox').forEach(cb => {
        cb.checked = checked;
        if (checked) overviewSelectedTaskIds.add(cb.value);
        else overviewSelectedTaskIds.delete(cb.value);
      });
      updateOverviewTasksCountBadge();
      renderOverviewTable();
    }

    function selectOverviewRecentTasks(count) {
      const sorted = [...overviewAvailableTasks].sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0));
      const recentIds = new Set(sorted.slice(0, count).map(t => String(t.id)));

      overviewSelectedTaskIds.clear();
      document.querySelectorAll('.ov-task-checkbox').forEach(cb => {
        if (recentIds.has(cb.value)) {
          cb.checked = true;
          overviewSelectedTaskIds.add(cb.value);
        } else {
          cb.checked = false;
        }
      });

      updateOverviewTasksCountBadge();
      renderOverviewTable();
    }

    function toggleOverviewControlsPanel() {
      const panel = document.getElementById('ovControlsPanel');
      const text = document.getElementById('ovTogglePanelText');
      if (!panel) return;
      if (panel.style.display === 'none') {
        panel.style.display = 'grid';
        if (text) text.textContent = 'إخفاء خيارات التخصيص';
      } else {
        panel.style.display = 'none';
        if (text) text.textContent = 'إظهار خيارات التخصيص';
      }
    }

    function clearOverviewSearch() {
      const input = document.getElementById('ovStudentSearch');
      if (input) {
        input.value = '';
        renderOverviewTable();
      }
    }

    function resetOverviewDisplayToggles() {
      const ids = ['ovShowRowIndex', 'ovShowAvatar', 'ovShowClassName', 'ovShowSolvedCount', 'ovShowTotalScore', 'ovShowGrades', 'ovShowTime', 'ovShowStatsHeader'];
      ids.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.checked = true;
      });
      const ori = document.getElementById('ovOrientation');
      if (ori) ori.value = 'landscape';
      renderOverviewTable();
    }

    function resetOverviewAllSettings() {
      if (document.getElementById('ovStudentSearch')) document.getElementById('ovStudentSearch').value = '';
      if (document.getElementById('ovTaskFilterInput')) document.getElementById('ovTaskFilterInput').value = '';
      if (document.getElementById('ovAnswerStatus')) document.getElementById('ovAnswerStatus').value = 'both';
      if (document.getElementById('ovGradeFilter')) document.getElementById('ovGradeFilter').value = 'all';
      if (document.getElementById('ovSortBy')) document.getElementById('ovSortBy').value = 'name';
      if (document.getElementById('ovSortDir')) document.getElementById('ovSortDir').value = 'asc';
      if (document.getElementById('ovTaskSort')) document.getElementById('ovTaskSort').value = 'created_asc';
      if (document.getElementById('ovViewMode')) document.getElementById('ovViewMode').value = 'matrix';
      resetOverviewDisplayToggles();
      selectOverviewAllClasses(true);
      selectOverviewAllTasks(true);
    }

    function renderOverviewTable() {
      const tableContainer = document.getElementById('ovTableContainer');
      const statsText = document.getElementById('ovStatsText');
      if (!tableContainer) return;

      // Visibility toggles
      const showRowIndex = document.getElementById('ovShowRowIndex')?.checked ?? true;
      const showAvatar = document.getElementById('ovShowAvatar')?.checked ?? true;
      const showClassName = document.getElementById('ovShowClassName')?.checked ?? true;
      const showSolvedCount = document.getElementById('ovShowSolvedCount')?.checked ?? true;
      const showTotalScore = document.getElementById('ovShowTotalScore')?.checked ?? true;
      const showGrades = document.getElementById('ovShowGrades')?.checked ?? true;
      const showTime = document.getElementById('ovShowTime')?.checked ?? true;
      const viewMode = document.getElementById('ovViewMode')?.value || 'matrix';

      // Filters & Sorts
      const answerStatus = document.getElementById('ovAnswerStatus')?.value || 'both';
      const gradeFilter = document.getElementById('ovGradeFilter')?.value || 'all';
      const sortBy = document.getElementById('ovSortBy')?.value || 'name';
      const sortDir = document.getElementById('ovSortDir')?.value || 'asc';
      const searchVal = (document.getElementById('ovStudentSearch')?.value || '').trim();

      // Selected classes
      const checkedClassNames = Array.from(document.querySelectorAll('.ov-class-checkbox:checked')).map(cb => cb.value);
      if (checkedClassNames.length === 0) {
        tableContainer.innerHTML = '<div style="text-align:center; padding:40px; color:var(--t3);"><i class="fas fa-exclamation-triangle" style="font-size:2rem; margin-bottom:10px; display:block; color:var(--warn);"></i>برجاء تحديد فصل واحد على الأقل للعرض.</div>';
        updateOverviewStats(0, 0, 0, 0, []);
        return;
      }

      // Collect target students
      let targetStudents = [];
      checkedClassNames.forEach(cn => {
        const list = classStuCache[cn] || [];
        list.forEach(s => {
          if (!targetStudents.some(item => item.id === s.id)) {
            targetStudents.push({ ...s, className: cn });
          }
        });
      });

      // Target tasks
      const checkedTaskIds = new Set(Array.from(document.querySelectorAll('.ov-task-checkbox:checked')).map(cb => cb.value));
      let targetTasks = overviewAvailableTasks.filter(t => checkedTaskIds.has(String(t.id)));
      sortOverviewTasksArray(targetTasks);

      if (targetTasks.length === 0) {
        tableContainer.innerHTML = '<div style="text-align:center; padding:40px; color:var(--t3);"><i class="fas fa-tasks" style="font-size:2rem; margin-bottom:10px; display:block; color:var(--warn);"></i>برجاء تحديد تاسك واحد على الأقل للعرض.</div>';
        updateOverviewStats(targetStudents.length, 0, 0, 0, []);
        return;
      }

      // Build submission lookup map
      const subMap = {};
      targetTasks.forEach(t => {
        const subs = t.submissions || [];
        subs.forEach(sub => {
          subMap[sub.student_id + '_' + t.id] = sub;
        });
      });

      // Calculate per-student metrics
      const totalPossibleMax = targetTasks.reduce((acc, t) => acc + (parseInt(t.total_degree) || 0), 0);

      targetStudents.forEach(s => {
        let solved = 0;
        let totalScore = 0;
        let latestTime = null;

        targetTasks.forEach(t => {
          const sub = subMap[s.id + '_' + t.id];
          if (sub) {
            solved++;
            totalScore += (parseFloat(sub.score) || 0);
            if (sub.submitted_at) {
              const d = new Date(sub.submitted_at);
              if (!latestTime || d > latestTime) latestTime = d;
            }
          }
        });

        s.solvedCount = solved;
        s.totalScore = totalScore;
        s.maxPossibleScore = totalPossibleMax;
        s.scorePct = totalPossibleMax > 0 ? (totalScore / totalPossibleMax) * 100 : (targetTasks.length > 0 ? (solved / targetTasks.length) * 100 : 0);
        s.latestSubmittedAt = latestTime;
      });

      // Track active filter labels for summary
      const activeFilterLabels = [];

      // Filter by answer status
      if (answerStatus !== 'both') {
        if (answerStatus === 'completed_all') activeFilterLabels.push('أتموا جميع التاسكات (100%)');
        else if (answerStatus === 'partial') activeFilterLabels.push('حل جزئي');
        else if (answerStatus === 'at_least_one') activeFilterLabels.push('حلوا تاسك واحد على الأقل');
        else if (answerStatus === 'missing') activeFilterLabels.push('لديهم تاسكات متبقية');
        else if (answerStatus === 'unanswered') activeFilterLabels.push('لم يحلوا أي تاسك (0%)');

        targetStudents = targetStudents.filter(s => {
          if (answerStatus === 'completed_all') return s.solvedCount === targetTasks.length && targetTasks.length > 0;
          if (answerStatus === 'partial') return s.solvedCount > 0 && s.solvedCount < targetTasks.length;
          if (answerStatus === 'at_least_one') return s.solvedCount > 0;
          if (answerStatus === 'missing') return s.solvedCount < targetTasks.length;
          if (answerStatus === 'unanswered') return s.solvedCount === 0;
          return true;
        });
      }

      // Filter by grade / performance
      if (gradeFilter !== 'all') {
        if (gradeFilter === 'excellent') activeFilterLabels.push('ممتاز (≥85%)');
        else if (gradeFilter === 'very_good') activeFilterLabels.push('جيد جداً (≥75%)');
        else if (gradeFilter === 'pass') activeFilterLabels.push('ناجح (≥50%)');
        else if (gradeFilter === 'fail') activeFilterLabels.push('راسب (<50%)');

        targetStudents = targetStudents.filter(s => {
          if (gradeFilter === 'excellent') return s.scorePct >= 85;
          if (gradeFilter === 'very_good') return s.scorePct >= 75;
          if (gradeFilter === 'pass') return s.scorePct >= 50;
          if (gradeFilter === 'fail') return s.scorePct < 50;
          return true;
        });
      }

      // Intelligent Search (per Rule #1)
      if (searchVal && targetStudents.length > 0 && typeof getMatchScore === 'function') {
        activeFilterLabels.push(`بحث: "${searchVal}"`);
        targetStudents = targetStudents.map(s => {
          const score = getMatchScore(s, searchVal, [
            { val: s.name, weight: 1.0 },
            { val: s.className, weight: 0.5 }
          ]);
          return { ...s, _score: score };
        }).filter(s => s._score > 0);
      }

      // Sort Students
      targetStudents.sort((a, b) => {
        if (searchVal && a._score !== undefined && b._score !== undefined && sortBy === 'name') {
          return b._score - a._score;
        }

        let res = 0;
        if (sortBy === 'name') {
          res = a.name.localeCompare(b.name, 'ar');
        } else if (sortBy === 'class') {
          res = a.className.localeCompare(b.className, 'ar') || a.name.localeCompare(b.name, 'ar');
        } else if (sortBy === 'solved_count') {
          res = a.solvedCount - b.solvedCount;
        } else if (sortBy === 'total_score') {
          res = a.totalScore - b.totalScore;
        } else if (sortBy === 'score_pct') {
          res = a.scorePct - b.scorePct;
        } else if (sortBy === 'last_submitted') {
          const tA = a.latestSubmittedAt ? a.latestSubmittedAt.getTime() : 0;
          const tB = b.latestSubmittedAt ? b.latestSubmittedAt.getTime() : 0;
          res = tA - tB;
        }

        return sortDir === 'desc' ? -res : res;
      });

      // Calculate global summary stats
      const totalPossibleSubmissions = targetStudents.length * targetTasks.length;
      const actualSubmissions = targetStudents.reduce((acc, s) => acc + s.solvedCount, 0);
      const overallSolveRate = totalPossibleSubmissions > 0 ? Math.round((actualSubmissions / totalPossibleSubmissions) * 100) : 0;
      const avgScore = targetStudents.length > 0 ? Math.round(targetStudents.reduce((acc, s) => acc + s.scorePct, 0) / targetStudents.length) : 0;

      updateOverviewStats(targetStudents.length, targetTasks.length, overallSolveRate, avgScore, activeFilterLabels);

      // Render Table based on View Mode
      if (viewMode === 'by_task') {
        renderPerTaskView(tableContainer, targetTasks, targetStudents, subMap, {
          showRowIndex,
          showClassName,
          showGrades,
          showTime
        });
      } else {
        renderMatrixView(tableContainer, targetTasks, targetStudents, subMap, {
          showRowIndex,
          showAvatar,
          showClassName,
          showSolvedCount,
          showTotalScore,
          showGrades,
          showTime
        });
      }
    }

    function renderMatrixView(container, targetTasks, targetStudents, subMap, options) {
      let html = `<table class="ov-table" id="ovExportTable">`;
      html += `<thead><tr>`;
      if (options.showRowIndex) html += `<th style="width:45px; text-align:center;">#</th>`;
      html += `<th style="min-width:180px;">اسم الطالب</th>`;
      if (options.showClassName) html += `<th style="min-width:100px; text-align:center;">الفصل</th>`;
      if (options.showSolvedCount) html += `<th style="min-width:90px; text-align:center;">المنجز</th>`;
      if (options.showTotalScore) html += `<th style="min-width:120px; text-align:center;">المجموع والنسبة</th>`;

      targetTasks.forEach(t => {
        const degText = t.total_degree ? `<div class="ov-task-sub">(${t.total_degree} درجات)</div>` : '';
        html += `<th style="min-width:120px; text-align:center;" title="${esc(t.title)}">
          <div style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:160px; margin:0 auto;">${esc(t.title)}</div>
          ${degText}
        </th>`;
      });
      html += `</tr></thead><tbody>`;

      if (targetStudents.length === 0) {
        const colCount = (options.showRowIndex ? 1 : 0) + 1 + (options.showClassName ? 1 : 0) + (options.showSolvedCount ? 1 : 0) + (options.showTotalScore ? 1 : 0) + targetTasks.length;
        html += `<tr><td colspan="${colCount}" style="text-align:center; padding:35px; color:var(--t3);">لا توجد نتائج تطابق خيارات البحث والترشيح المحددة.</td></tr>`;
      } else {
        targetStudents.forEach((s, idx) => {
          html += `<tr>`;
          if (options.showRowIndex) {
            html += `<td style="text-align:center; font-weight:700; color:var(--t3); font-size:0.75rem;">${idx + 1}</td>`;
          }

          const avatar = options.showAvatar ? getStudentAvatarHtml(s.photo, s.name, '24px') : '';
          html += `<td><div style="display:flex; align-items:center; gap:8px;">${avatar} <strong>${esc(s.name)}</strong></div></td>`;

          if (options.showClassName) {
            html += `<td style="text-align:center;"><span class="ov-class-badge">${esc(s.className)}</span></td>`;
          }

          if (options.showSolvedCount) {
            const isFull = s.solvedCount === targetTasks.length;
            const isEmpty = s.solvedCount === 0;
            html += `<td style="text-align:center;"><span class="ov-progress-pill ${isFull ? 'completed' : (isEmpty ? 'empty' : '')}">${s.solvedCount}/${targetTasks.length}</span></td>`;
          }

          if (options.showTotalScore) {
            const isPass = s.scorePct >= 50;
            html += `<td style="text-align:center;"><span class="ov-grade-badge ${isPass ? 'ov-grade-pass' : 'ov-grade-fail'}">${s.totalScore}/${s.maxPossibleScore} (${Math.round(s.scorePct)}%)</span></td>`;
          }

          targetTasks.forEach(t => {
            const sub = subMap[s.id + '_' + t.id];
            html += `<td style="text-align:center;">`;
            if (sub) {
              if (options.showGrades) {
                const scoreVal = parseInt(sub.score);
                const totalVal = parseInt(t.total_degree || sub.total_degree || 0);
                const pct = totalVal > 0 ? (scoreVal / totalVal) : 0;
                const isPass = pct >= 0.5;
                html += `<span class="ov-grade-badge ${isPass ? 'ov-grade-pass' : 'ov-grade-fail'}">${scoreVal}/${totalVal}</span>`;
              } else {
                html += `<span class="ov-cell-answered"><i class="fas fa-check-circle"></i> أجاب</span>`;
              }
              if (options.showTime && sub.submitted_at) {
                const dateObj = new Date(sub.submitted_at);
                const dateStr = `${dateObj.getDate()}/${dateObj.getMonth() + 1} ${String(dateObj.getHours()).padStart(2, '0')}:${String(dateObj.getMinutes()).padStart(2, '0')}`;
                html += `<span class="ov-time-text">${dateStr}</span>`;
              }
            } else {
              html += `<span class="ov-cell-unanswered"><i class="fas fa-times-circle" style="color:var(--err);"></i> لم يجب</span>`;
            }
            html += `</td>`;
          });

          html += `</tr>`;
        });
      }

      html += `</tbody></table>`;
      container.innerHTML = html;
    }

    function renderPerTaskView(container, targetTasks, targetStudents, subMap, options) {
      let html = `<table class="ov-table" id="ovExportTable">`;
      html += `<thead><tr>`;
      if (options.showRowIndex) html += `<th style="width:45px; text-align:center;">#</th>`;
      html += `<th style="width:25%;">اسم التاسك</th>`;
      if (options.showClassName) html += `<th style="width:12%; text-align:center;">الفصل</th>`;
      html += `<th style="width:15%; text-align:center;">عدد المجيبين</th>`;
      if (options.showGrades) html += `<th style="width:12%; text-align:center;">متوسط الدرجات</th>`;
      html += `<th style="width:36%;">قائمة المجيبين</th>`;
      html += `</tr></thead><tbody>`;

      targetTasks.forEach((t, idx) => {
        const respondents = targetStudents.filter(s => subMap[s.id + '_' + t.id]);
        const totalDeg = parseInt(t.total_degree || 0);

        let sumScores = 0;
        respondents.forEach(s => {
          const sub = subMap[s.id + '_' + t.id];
          sumScores += (parseFloat(sub.score) || 0);
        });
        const avgScore = respondents.length > 0 ? (sumScores / respondents.length).toFixed(1) : '-';

        let namesText = respondents.map((s, rIdx) => {
          const avatar = getStudentAvatarHtml(s.photo, s.name, '20px');
          const sub = subMap[s.id + '_' + t.id];
          let subDetails = '';
          if (options.showGrades) {
            subDetails = ` (${parseInt(sub.score)}/${totalDeg})`;
          }
          let timeText = '';
          if (options.showTime && sub.submitted_at) {
            const d = new Date(sub.submitted_at);
            timeText = ` <span style="font-size:0.65rem; opacity:0.75;">${d.getDate()}/${d.getMonth()+1}</span>`;
          }
          return `<span style="display:inline-flex; align-items:center; gap:5px; margin:2px 3px; padding:3px 7px; background:var(--brand-bg); color:var(--brand); border-radius:6px; font-size:0.75rem;">${rIdx + 1}. ${avatar} ${esc(s.name)}${subDetails}${timeText}</span>`;
        }).join(' ');

        if (respondents.length === 0) {
          namesText = `<span style="color:var(--t3); font-style:italic;">لا يوجد مجيبين بعد</span>`;
        }

        const solvePct = targetStudents.length > 0 ? Math.round((respondents.length / targetStudents.length) * 100) : 0;

        html += `<tr>`;
        if (options.showRowIndex) {
          html += `<td style="text-align:center; font-weight:700; color:var(--t3); font-size:0.75rem;">${idx + 1}</td>`;
        }
        html += `<td><strong>${esc(t.title)}</strong> ${totalDeg ? `<span class="ov-task-sub">(${totalDeg} درجات)</span>` : ''}</td>`;
        if (options.showClassName) {
          html += `<td style="text-align:center;"><span class="ov-class-badge">${esc(getTaskClassNames(t).join('، ') || 'كل الفصول')}</span></td>`;
        }
        html += `<td style="text-align:center;"><strong style="color:var(--brand);">${respondents.length}</strong> / ${targetStudents.length} <span class="ov-task-sub">(${solvePct}%)</span></td>`;
        if (options.showGrades) {
          html += `<td style="text-align:center;"><strong style="color:#0ca678;">${avgScore}</strong> ${totalDeg ? `/ ${totalDeg}` : ''}</td>`;
        }
        html += `<td>${namesText}</td>`;
        html += `</tr>`;
      });

      html += `</tbody></table>`;
      container.innerHTML = html;
    }

    function updateOverviewStats(studentCount, taskCount, solveRate, avgScore, filterLabels = []) {
      if (document.getElementById('ovStatStudents')) document.getElementById('ovStatStudents').textContent = studentCount;
      if (document.getElementById('ovStatTasks')) document.getElementById('ovStatTasks').textContent = taskCount;
      if (document.getElementById('ovStatSolveRate')) document.getElementById('ovStatSolveRate').textContent = `${solveRate}%`;
      if (document.getElementById('ovStatAvgScore')) document.getElementById('ovStatAvgScore').textContent = `${avgScore}%`;

      const filterSummary = document.getElementById('ovActiveFiltersSummary');
      if (filterSummary) {
        if (filterLabels.length > 0) {
          filterSummary.innerHTML = `<span style="font-size:0.72rem; color:var(--brand); background:var(--brand-bg); padding:2px 8px; border-radius:10px;"><i class="fas fa-filter"></i> ${filterLabels.join(' | ')}</span>`;
        } else {
          filterSummary.innerHTML = '';
        }
      }

      const statsText = document.getElementById('ovStatsText');
      if (statsText) {
        statsText.textContent = `الطلاب المعروضون: ${studentCount} | التاسكات: ${taskCount} | نسبة التسليم: ${solveRate}% | متوسط الدرجات: ${avgScore}%`;
      }
    }

    function exportOverviewCSV() {
      const table = document.getElementById('ovExportTable');
      if (!table) { showToast('لا توجد بيانات لتصديرها', 'err'); return; }

      let csv = [];
      const rows = table.querySelectorAll('tr');

      rows.forEach(tr => {
        let row = [];
        const cols = tr.querySelectorAll('th, td');
        cols.forEach(col => {
          let text = col.innerText.trim().replace(/"/g, '""');
          text = text.replace(/[\n\r]+/g, ' ');
          row.push('"' + text + '"');
        });
        csv.push(row.join(','));
      });

      const csvContent = "\ufeff" + csv.join("\n");
      const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.setAttribute('href', url);
      link.setAttribute('download', `overview_tasks_${new Date().toISOString().slice(0, 10)}.csv`);
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      showToast('تم تصدير ملف CSV بنجاح', 'ok');
    }

    function copyOverviewMessage() {
      const table = document.getElementById('ovExportTable');
      if (!table) { showToast('لا توجد بيانات لنسخها', 'err'); return; }

      const viewMode = document.getElementById('ovViewMode')?.value || 'matrix';
      const statsText = document.getElementById('ovStatsText')?.innerText || '';

      let msg = `📊 *تقرير متابعة التاسكات والاختبارات*\n`;
      msg += `📅 التاريخ: ${new Date().toLocaleDateString('ar-EG')}\n`;
      msg += `📌 ${statsText}\n\n`;

      if (viewMode === 'by_task') {
        const rows = Array.from(table.querySelectorAll('tbody tr'));
        rows.forEach((tr, i) => {
          const cells = tr.querySelectorAll('td');
          if (cells.length < 3) return;
          const title = tr.querySelector('td:nth-child(2)')?.innerText.trim() || '';
          msg += `📝 *${title}*\n`;
          const details = Array.from(cells).map(c => c.innerText.trim()).filter(Boolean);
          msg += `   ${details.slice(2).join(' | ')}\n\n`;
        });
      } else {
        const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.innerText.trim().replace(/\n+/g, ' '));
        const rows = Array.from(table.querySelectorAll('tbody tr'));

        msg += `📋 *التاسكات المضمنة:*\n`;
        let taskStartIndex = 1;
        if (document.getElementById('ovShowRowIndex')?.checked) taskStartIndex++;
        if (document.getElementById('ovShowClassName')?.checked) taskStartIndex++;
        if (document.getElementById('ovShowSolvedCount')?.checked) taskStartIndex++;
        if (document.getElementById('ovShowTotalScore')?.checked) taskStartIndex++;

        for (let i = taskStartIndex; i < headers.length; i++) {
          msg += `  ${i - taskStartIndex + 1}. ${headers[i]}\n`;
        }
        msg += `\n👤 *النتائج والدرجات:*\n`;

        rows.forEach((tr, idx) => {
          const cols = tr.querySelectorAll('td');
          if (cols.length < 2) return;

          let cIdx = 0;
          let rowNum = '';
          if (document.getElementById('ovShowRowIndex')?.checked) {
            rowNum = cols[cIdx++].innerText.trim() + '. ';
          } else {
            rowNum = (idx + 1) + '. ';
          }

          const studentName = cols[cIdx++].innerText.trim();
          let clsText = '';
          if (document.getElementById('ovShowClassName')?.checked) {
            clsText = ` (${cols[cIdx++].innerText.trim()})`;
          }

          let solvedText = '';
          if (document.getElementById('ovShowSolvedCount')?.checked) {
            solvedText = ` [المنجز: ${cols[cIdx++].innerText.trim()}]`;
          }

          let scoreText = '';
          if (document.getElementById('ovShowTotalScore')?.checked) {
            scoreText = ` [المجموع: ${cols[cIdx++].innerText.trim()}]`;
          }

          msg += `${rowNum}*${studentName}*${clsText}${solvedText}${scoreText}\n`;

          for (let k = cIdx; k < cols.length; k++) {
            const tTitle = headers[k] || 'تاسك';
            const cellVal = cols[k].innerText.trim().replace(/\s+/g, ' ');
            msg += `    ▫️ ${tTitle}: ${cellVal}\n`;
          }
        });
      }

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(msg).then(() => {
          showToast('تم نسخ التقرير كرسالة بنجاح 📋', 'ok');
        }).catch(() => fallbackCopyOverviewText(msg));
      } else {
        fallbackCopyOverviewText(msg);
      }
    }

    function fallbackCopyOverviewText(text) {
      const ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      try {
        document.execCommand('copy');
        showToast('تم نسخ التقرير كرسالة بنجاح 📋', 'ok');
      } catch (e) {
        showToast('فشل نسخ التقرير', 'err');
      }
      document.body.removeChild(ta);
    }

    function copyTaskShareLink(taskId) {
      const t = (typeof tasks !== 'undefined' && tasks) ? tasks.find(x => x.id == taskId) : null;
      const targetTask = t || (typeof detailTask !== 'undefined' ? detailTask : null);
      const title = targetTask ? targetTask.title : 'تاسك';
      const baseUrl = window.location.origin;
      const taskUrl = `${baseUrl}/user/?taskId=${taskId}`;
      const shareText = `📝 *${title}*\nاضغط على الرابط التالي للبدء في حل التاسك:\n${taskUrl}`;

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(shareText).then(() => {
          showToast('تم نسخ رابط التاسك بنجاح 📋', 'ok');
        }).catch(() => fallbackCopyTaskText(shareText));
      } else {
        fallbackCopyTaskText(shareText);
      }
    }

    function fallbackCopyTaskText(text) {
      const ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      try {
        document.execCommand('copy');
        showToast('تم نسخ رابط التاسك بنجاح 📋', 'ok');
      } catch (e) {
        showToast('تعذر نسخ الرابط', 'err');
      }
      document.body.removeChild(ta);
    }

    async function getExportCanvas() {
      const container = document.getElementById('ovTableContainer');
      if (!container) return null;

      const clone = container.cloneNode(true);

      const orientationVal = document.getElementById('ovOrientation') ? document.getElementById('ovOrientation').value : 'landscape';
      const ths = clone.querySelectorAll('thead th');
      let exportWidth = 1200;

      if (orientationVal === 'portrait') {
        exportWidth = 880;
      } else {
        if (ths.length > 4) {
          exportWidth = Math.max(1200, ths.length * 140);
        }
      }

      clone.style.position = 'absolute';
      clone.style.top = '0';
      clone.style.left = '-9999px';
      clone.style.width = exportWidth + 'px';
      clone.style.height = 'auto';
      clone.style.overflow = 'visible';
      clone.style.background = '#ffffff';
      clone.style.padding = '25px 25px 50px 25px';

      const tables = clone.querySelectorAll('table');
      tables.forEach(t => {
        t.style.width = '100%';
        t.style.maxWidth = '100%';
        t.style.overflow = 'visible';
        t.style.margin = '0';
        t.style.borderCollapse = 'collapse';
      });

      const headers = clone.querySelectorAll('th');
      headers.forEach(th => {
        th.style.position = 'static';
        th.style.background = 'var(--surface-2, #f1f5f9)';
        th.style.color = 'var(--text, #1e293b)';
      });

      // Normalize icons inside clone for clean html2canvas rendering
      clone.querySelectorAll('.ov-cell-answered').forEach(el => {
        el.style.background = '#d1fae5';
        el.style.color = '#065f46';
        el.style.border = '1px solid #6ee7b7';
        el.style.padding = '3px 8px';
        el.style.borderRadius = '6px';
        el.style.fontWeight = '800';
        el.style.fontSize = '0.78rem';
        el.style.display = 'inline-flex';
        el.style.alignItems = 'center';
        el.style.gap = '4px';
        el.innerHTML = '✔ أجاب';
      });
      clone.querySelectorAll('.ov-cell-unanswered').forEach(el => {
        el.style.background = '#fee2e2';
        el.style.color = '#991b1b';
        el.style.border = '1px solid #fca5a5';
        el.style.padding = '3px 8px';
        el.style.borderRadius = '6px';
        el.style.fontWeight = '800';
        el.style.fontSize = '0.78rem';
        el.style.display = 'inline-flex';
        el.style.alignItems = 'center';
        el.style.gap = '4px';
        el.innerHTML = '✖ لم يجب';
      });
      clone.querySelectorAll('i.fa-check-circle').forEach(el => {
        const span = document.createElement('span');
        span.style.color = '#10b981';
        span.style.fontWeight = 'bold';
        span.textContent = '✔ ';
        if (el.parentNode) el.parentNode.replaceChild(span, el);
      });
      clone.querySelectorAll('i.fa-times-circle').forEach(el => {
        const span = document.createElement('span');
        span.style.color = '#ef4444';
        span.style.fontWeight = 'bold';
        span.textContent = '✖ ';
        if (el.parentNode) el.parentNode.replaceChild(span, el);
      });

      // Add a header if ovShowStatsHeader is checked
      const showHeader = document.getElementById('ovShowStatsHeader')?.checked ?? true;
      if (showHeader) {
        const statsText = document.getElementById('ovStatsText') ? document.getElementById('ovStatsText').innerText : '';
        const headerDiv = document.createElement('div');
        headerDiv.style.direction = 'rtl';
        headerDiv.style.fontFamily = "'Baloo Bhaijaan 2', 'Segoe UI', Tahoma, sans-serif";
        headerDiv.style.marginBottom = '25px';
        headerDiv.style.borderBottom = '3px solid var(--brand, #5b6cf5)';
        headerDiv.style.paddingBottom = '15px';
        headerDiv.style.display = 'flex';
        headerDiv.style.justifyContent = 'space-between';
        headerDiv.style.alignItems = 'center';

        headerDiv.innerHTML = `
          <div>
            <h2 style="margin:0 0 5px 0; color:#1a1d2e; font-size:1.6rem; font-weight:800;">📋 تقرير متابعة التاسكات والاختبارات</h2>
            <p style="margin:0; color:#4b5068; font-size:0.95rem;">التاريخ: ${new Date().toLocaleDateString('ar-EG')} | ${statsText}</p>
          </div>
          <div style="text-align:left; direction: ltr;">
            <span style="font-size:1.3rem; font-weight:800; color:var(--brand, #5b6cf5); font-family: 'Baloo Bhaijaan 2', sans-serif;">Sunday School</span>
          </div>
        `;
        clone.insertBefore(headerDiv, clone.firstChild);
      }

      const spacer = document.createElement('div');
      spacer.style.height = '30px';
      clone.appendChild(spacer);

      document.body.appendChild(clone);
      await new Promise(r => setTimeout(r, 150));

      try {
        const canvas = await html2canvas(clone, {
          scale: 2,
          useCORS: true,
          backgroundColor: '#ffffff',
          logging: false,
          allowTaint: false,
          width: clone.scrollWidth,
          height: clone.scrollHeight,
          windowWidth: clone.scrollWidth + 100,
          windowHeight: clone.scrollHeight + 100
        });
        return canvas;
      } finally {
        document.body.removeChild(clone);
      }
    }

    async function exportOverviewImage() {
      const container = document.getElementById('ovTableContainer');
      if (!container) { showToast('لا توجد بيانات لتصديرها', 'err'); return; }

      showToast('جاري إعداد الصورة...', 'info');

      try {
        const canvas = await getExportCanvas();
        if (!canvas) { showToast('فشل تصدير الصورة', 'err'); return; }

        const link = document.createElement('a');
        link.download = `overview_tasks_${new Date().toISOString().slice(0, 10)}.png`;
        link.href = canvas.toDataURL('image/png');
        link.click();
        showToast('تم حفظ الصورة بنجاح', 'ok');
      } catch (e) {
        console.error(e);
        showToast('فشل تصدير الصورة', 'err');
      }
    }

    async function exportOverviewPDF() {
      const container = document.getElementById('ovTableContainer');
      if (!container) { showToast('لا توجد بيانات لتصديرها', 'err'); return; }

      showToast('جاري إنشاء PDF...', 'info');

      try {
        const { jsPDF } = window.jspdf;
        if (!jsPDF) { showToast('مكتبة PDF غير محملة', 'err'); return; }

        const canvas = await getExportCanvas();
        if (!canvas) { showToast('فشل إنشاء PDF', 'err'); return; }

        const orientationVal = document.getElementById('ovOrientation') ? document.getElementById('ovOrientation').value : 'landscape';
        const pdf = new jsPDF({ orientation: orientationVal, unit: 'mm', format: 'a4' });
        const pageW = pdf.internal.pageSize.getWidth();
        const pageH = pdf.internal.pageSize.getHeight();
        const imgW = pageW - 12;
        const imgH = canvas.height * imgW / canvas.width;
        const img = canvas.toDataURL('image/png');

        let y = 6;
        let remaining = imgH;

        pdf.addImage(img, 'PNG', 6, y, imgW, imgH);
        while (remaining > pageH - 12) {
          remaining -= pageH - 12;
          pdf.addPage();
          pdf.addImage(img, 'PNG', 6, 6 - (imgH - remaining), imgW, imgH);
        }

        pdf.save(`overview_tasks_${new Date().toISOString().slice(0, 10)}.pdf`);
        showToast('تم حفظ PDF بنجاح', 'ok');
      } catch (e) {
        console.error(e);
        showToast('فشل إنشاء PDF: ' + e.message, 'err');
      }
    }

    // ─── Task Questions & Answers Export Logic ─────────────────────────────────
    window.currentExportTask = null;
    window.currentExportQuestions = [];
    window.currentExportShowAnswers = true;

    function sanitizeFileName(str) {
      return String(str || 'تاسك').replace(/[/\\?%*:|"<>]/g, '-').replace(/\s+/g, '_').trim();
    }

    async function openExportTaskQuestions(taskId) {
      try {
        let t = (typeof tasks !== 'undefined' && tasks) ? tasks.find(x => x.id == taskId) : null;
        if (typeof detailTask !== 'undefined' && detailTask && detailTask.id == taskId) {
          t = detailTask;
        }

        // If not loaded or questions empty, fetch task detail from API
        if (!t || !t.questions || !t.questions.length) {
          showToast('جاري تحميل أسئلة التاسك...', 'info');
          const res = await api('getTaskDetail', { task_id: taskId });
          if (res && res.success && res.task) {
            t = res.task;
            if (typeof detailTask !== 'undefined' && detailTask && detailTask.id == taskId) {
              detailTask = res.task;
            }
          }
        }

        if (!t) {
          showToast('تعذر العثور على بيانات التاسك', 'err');
          return;
        }

        let qs = [];
        if (Array.isArray(t.questions)) {
          qs = t.questions;
        } else if (typeof t.questions === 'string') {
          try { qs = JSON.parse(t.questions); } catch (e) { qs = []; }
        }

        if (!qs || qs.length === 0) {
          showToast('هذا التاسك لا يحتوي على أي أسئلة لتصديرها', 'err');
          return;
        }

        window.currentExportTask = t;
        window.currentExportQuestions = qs;
        setupAndOpenQuestionsExportModal();
      } catch (err) {
        console.error(err);
        showToast('حدث خطأ أثناء تحميل أسئلة التاسك', 'err');
      }
    }

    function exportCurrentEditorQuestions() {
      if (typeof collectForm !== 'function') {
        showToast('تعذر قراءة أسئلة المحرر', 'err');
        return;
      }
      const formData = collectForm('draft');
      let qs = [];
      if (formData && formData.questions) {
        if (typeof formData.questions === 'string') {
          try { qs = JSON.parse(formData.questions); } catch (e) { qs = []; }
        } else if (Array.isArray(formData.questions)) {
          qs = formData.questions;
        }
      }

      if (!qs || qs.length === 0) {
        showToast('لا توجد أسئلة في المحرر لتصديرها! أضف سؤالاً أولاً.', 'err');
        return;
      }

      const titleInput = document.getElementById('qTitle');
      const titleVal = titleInput && titleInput.value ? titleInput.value.trim() : 'تاسك بدون عنوان';
      const degTotalEl = document.getElementById('degTotal');
      const degVal = degTotalEl ? (parseInt(degTotalEl.textContent) || 0) : 0;
      const classCheckboxes = document.querySelectorAll('.class-cb:checked');
      const selClasses = Array.from(classCheckboxes).map(cb => cb.value);

      const fakeTask = {
        id: editId || 0,
        title: titleVal,
        total_degree: degVal,
        questions: qs,
        class_name: selClasses.join('، ') || 'عام'
      };

      window.currentExportTask = fakeTask;
      window.currentExportQuestions = qs;
      setupAndOpenQuestionsExportModal();
    }

    function setupAndOpenQuestionsExportModal() {
      const t = window.currentExportTask || {};
      const qs = window.currentExportQuestions || [];
      const titleEl = document.getElementById('tqExportModalTitle');
      const subEl = document.getElementById('tqExportModalSub');
      const classesLabel = typeof getTaskClassNames === 'function' ? getTaskClassNames(t).join('، ') : (t.class_name || '');

      if (titleEl) titleEl.textContent = `تصدير: ${t.title || 'أسئلة التاسك'}`;
      if (subEl) subEl.textContent = `${classesLabel ? classesLabel + ' — ' : ''}${qs.length} سؤال — ${t.total_degree || 0} درجة`;

      const oriSelect = document.getElementById('tqOrientation');
      if (oriSelect) oriSelect.value = 'portrait';
      const sheet = document.getElementById('tqPaperExport');
      if (sheet) sheet.classList.remove('landscape-view');

      setQuestionsAnswerVisibility(window.currentExportShowAnswers !== undefined ? window.currentExportShowAnswers : true);
      openOv('taskQuestionsExportOv');
    }

    function setQuestionsAnswerVisibility(show) {
      window.currentExportShowAnswers = !!show;
      const btnShow = document.getElementById('btnTqShowAns');
      const btnHide = document.getElementById('btnTqHideAns');
      if (btnShow) btnShow.classList.toggle('active', !!show);
      if (btnHide) btnHide.classList.toggle('active', !show);

      renderQuestionsExportPreview();
    }

    function handleTqOrientationChange() {
      const ori = document.getElementById('tqOrientation')?.value || 'portrait';
      const sheet = document.getElementById('tqPaperExport');
      if (sheet) {
        sheet.classList.toggle('landscape-view', ori === 'landscape');
      }
      renderQuestionsExportPreview();
    }

    function getTaskExportChurchName() {
      const cn = (typeof CFG !== 'undefined' && CFG.churchName && CFG.churchName !== 'الكنيسة' && CFG.churchName !== 'مدارس الأحد')
        ? CFG.churchName
        : (localStorage.getItem('churchName') || '');
      if (cn && cn !== 'الكنيسة' && cn !== 'مدارس الأحد') {
        return cn;
      }
      return '';
    }

    function getTaskExportServiceTitle() {
      return (typeof CFG !== 'undefined' && CFG.isYouth) ? 'خدمة اجتماع الشباب' : 'خدمة مدارس الأحد';
    }

    function buildExportFullHeaderHtml(t, classesLabel, showAns, showExamHdr) {
      const churchName = getTaskExportChurchName();
      const serviceTitle = getTaskExportServiceTitle();
      const churchHeaderLine = churchName
        ? `<div style="font-size: 0.95rem; font-weight: 800; color: var(--brand, #5b6cf5); margin-bottom: 2px;">${esc(churchName)}</div>`
        : '';
      const serviceHeaderLine = churchName
        ? `${serviceTitle} — ${classesLabel ? esc(classesLabel) : 'عام'}`
        : `${serviceTitle}${classesLabel ? ' — ' + esc(classesLabel) : ''}`;

      return `
        <div class="tq-paper-header" style="border-bottom: 2.5px solid var(--brand, #5b6cf5); padding-bottom: 14px; margin-bottom: 16px;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; gap: 12px; flex-wrap: wrap;">
            <div>
              ${churchHeaderLine}
              <div style="font-size: 0.82rem; color: #64748b; font-weight: 700;">
                ${serviceHeaderLine}
              </div>
            </div>
            <div style="text-align: left;">
              <span style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 99px; font-size: 0.78rem; font-weight: 800; ${showAns ? 'background:#ecfdf5; color:#065f46; border:1.5px solid #10b981;' : 'background:#fef2f2; color:#991b1b; border:1.5px solid #f87171;'}">
                ${showAns ? '✔ نموذج إجابة معتمد' : '📝 ورقة اختبار الطالب'}
              </span>
            </div>
          </div>

          <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 10px; border-top: 1px dashed #e2e8f0; padding-top: 10px;">
            <h2 style="margin: 0; font-size: 1.35rem; font-weight: 900; color: #0f172a; line-height: 1.3;">
              ${esc(t.title || 'أسئلة التاسك')}
            </h2>
            <div style="font-size: 0.85rem; font-weight: 700; color: #475569; white-space: nowrap;">
              التاريخ: ${new Date().toLocaleDateString('ar-EG')}
            </div>
          </div>
          ${showExamHdr ? `
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; padding: 8px 14px; background: #f8fafc; border: 1.5px dashed #cbd5e1; border-radius: 10px; margin-top: 12px; font-size: 0.88rem; font-weight: 700; color: #334155;">
              <div>اسم الطالب: ...........................................</div>
              <div>الفصل: ${classesLabel ? esc(classesLabel) : '.....................'}</div>
              <div>الدرجة: ........... / ${t.total_degree || 0}</div>
            </div>
          ` : ''}
        </div>
      `;
    }

    function buildExportCompactHeaderHtml(t, classesLabel, showAns) {
      const churchName = getTaskExportChurchName();
      const serviceTitle = getTaskExportServiceTitle();
      const brandLabel = churchName ? esc(churchName) : serviceTitle;

      return `
        <div class="tq-paper-header-compact" style="border-bottom: 2px solid var(--brand, #5b6cf5); padding-bottom: 10px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;">
          <div style="display:flex; align-items:center; gap:8px;">
            <span style="font-size: 0.88rem; font-weight: 800; color: var(--brand, #5b6cf5);">
              ${brandLabel}
            </span>
            <span style="color: #cbd5e1;">|</span>
            <span style="font-size: 0.88rem; font-weight: 700; color: #1e293b;">
              ${esc(t.title || 'أسئلة التاسك')}
            </span>
          </div>
          <div style="display: flex; align-items: center; gap: 8px;">
            <span style="font-size: 0.75rem; font-weight: 800; padding: 2px 8px; border-radius: 99px; ${showAns ? 'background:#ecfdf5; color:#065f46; border:1px solid #10b981;' : 'background:#fef2f2; color:#991b1b; border:1px solid #f87171;'}">
              ${showAns ? 'نموذج إجابة' : 'ورقة اختبار'}
            </span>
            <span style="font-size: 0.78rem; color: #64748b; font-weight: 700;">
              ${new Date().toLocaleDateString('ar-EG')}
            </span>
          </div>
        </div>
      `;
    }

    function buildExportQuestionHtml(q, i, showAns, showDegrees) {
      const qType = q.question_type || 'mcq';
      const deg = parseInt(q.degree) || 0;
      const ci = q.correct_index !== null && q.correct_index !== undefined ? parseInt(q.correct_index) : null;
      const imgH = q.image_url ? `<div style="margin: 8px 0 12px; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; background: #f8fafc; height: 180px; max-height: 180px; display: flex; justify-content: center; align-items: center;"><img src="${esc(q.image_url)}" alt="" crossorigin="anonymous" style="max-height: 100%; max-width: 100%; object-fit: contain;"></div>` : '';

      let typeLabel = 'اختيار من متعدد';
      let typeColor = 'background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;';
      if (qType === 'tf') {
        typeLabel = 'صح أو خطأ';
        typeColor = 'background:#fffbeb; color:#b45309; border:1px solid #fde68a;';
      } else if (qType === 'open') {
        typeLabel = 'سؤال مقالي';
        typeColor = 'background:#f5f3ff; color:#6d28d9; border:1px solid #ddd6fe;';
      }

      let html = `
        <div class="tq-q-item">
          <div class="tq-q-header">
            <div class="tq-q-num">${i + 1}</div>
            <div class="tq-q-title">${esc(q.question_text || '')}</div>
            <div style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
              <span class="tq-q-badge" style="${typeColor}">${typeLabel}</span>
              ${showDegrees ? `<span class="tq-q-badge" style="background:var(--brand-bg, #eff2fe); color:var(--brand, #5b6cf5); border:1px solid var(--brand-l, #c7d2fe);">${deg} ${deg === 1 ? 'درجة' : 'درجات'}</span>` : ''}
            </div>
          </div>
          ${imgH}
      `;

      if (qType === 'tf') {
        const isTCorrect = showAns && ci === 0;
        const isFCorrect = showAns && ci === 1;
        html += `
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px;">
            <div class="tq-opt-row ${isTCorrect ? 'is-correct' : ''}">
              <span style="font-weight: bold; width: 22px; height: 22px; border-radius: 50%; border: 1.5px solid ${isTCorrect ? '#10b981' : '#94a3b8'}; display: inline-flex; align-items: center; justify-content: center; font-size: 0.75rem; background: ${isTCorrect ? '#10b981' : '#fff'}; color: ${isTCorrect ? '#fff' : '#475569'}; flex-shrink:0;">
                ${isTCorrect ? '✔' : ''}
              </span>
              <span>صحيح</span>
              ${isTCorrect ? '<span style="margin-right:auto; font-size:0.75rem; color:#059669; font-weight:800;">✔ الإجابة الصحيحة</span>' : ''}
            </div>
            <div class="tq-opt-row ${isFCorrect ? 'is-correct' : ''}">
              <span style="font-weight: bold; width: 22px; height: 22px; border-radius: 50%; border: 1.5px solid ${isFCorrect ? '#10b981' : '#94a3b8'}; display: inline-flex; align-items: center; justify-content: center; font-size: 0.75rem; background: ${isFCorrect ? '#10b981' : '#fff'}; color: ${isFCorrect ? '#fff' : '#475569'}; flex-shrink:0;">
                ${isFCorrect ? '✔' : ''}
              </span>
              <span>خطأ</span>
              ${isFCorrect ? '<span style="margin-right:auto; font-size:0.75rem; color:#059669; font-weight:800;">✔ الإجابة الصحيحة</span>' : ''}
            </div>
          </div>
        `;
      } else if (qType === 'open') {
        if (showAns) {
          html += `
            <div style="margin-top: 10px; padding: 12px 16px; border-radius: 8px; background: #f8fafc; border: 1px solid #e2e8f0; font-size: 0.88rem; color: #475569;">
              <strong style="color: var(--brand, #5b6cf5);"><i class="fas fa-pen-nib"></i> سؤال مقالي:</strong>
              إجابة كتابية حرة من الطالب يتم تقييمها وتصحيحها يدوياً (${deg} ${deg === 1 ? 'درجة' : 'درجات'}).
            </div>
          `;
        } else {
          html += `
            <div style="margin-top: 12px; padding: 12px 14px; border: 1px dashed #cbd5e1; border-radius: 8px; background: #fafafa;">
              <div style="font-size: 0.78rem; color: #94a3b8; font-weight: 700; margin-bottom: 6px;">مساحة إجابة الطالب:</div>
              <div style="border-bottom: 1px dotted #cbd5e1; height: 26px;"></div>
              <div style="border-bottom: 1px dotted #cbd5e1; height: 26px;"></div>
            </div>
          `;
        }
      } else {
        const opts = typeof q.options === 'string' ? JSON.parse(q.options || '[]') : (q.options || []);
        html += `<div style="display: flex; flex-direction: column; gap: 6px; margin-top: 8px;">`;
        opts.forEach((o, oi) => {
          const isCorrect = showAns && oi === ci;
          const letter = LETTERS[oi] || (oi + 1);
          html += `
            <div class="tq-opt-row ${isCorrect ? 'is-correct' : ''}">
              <span style="font-weight: 800; width: 22px; height: 22px; border-radius: 50%; border: 1.5px solid ${isCorrect ? '#10b981' : '#cbd5e1'}; display: inline-flex; align-items: center; justify-content: center; font-size: 0.78rem; background: ${isCorrect ? '#10b981' : '#f8fafc'}; color: ${isCorrect ? '#fff' : '#334155'}; flex-shrink:0;">
                ${isCorrect ? '✔' : letter}
              </span>
              <span style="flex:1;">${esc(o)}</span>
              ${isCorrect ? '<span style="margin-right:auto; font-size:0.75rem; color:#059669; font-weight:800; display:inline-flex; align-items:center; gap:4px;"><i class="fas fa-check-circle"></i> الإجابة الصحيحة</span>' : ''}
            </div>
          `;
        });
        html += `</div>`;
      }

      html += `</div>`;
      return html;
    }

    function measureQuestionHeights(t, qs, showAns, showExamHdr, showDegrees, isLandscape, classesLabel) {
      const targetW = isLandscape ? 1131 : 800;
      const tester = document.createElement('div');
      tester.style.position = 'absolute';
      tester.style.top = '0';
      tester.style.left = '-9999px';
      tester.style.width = targetW + 'px';
      tester.style.padding = '30px 36px 24px 36px';
      tester.style.boxSizing = 'border-box';
      tester.style.fontFamily = "'Baloo Bhaijaan 2', 'Cairo', sans-serif";
      tester.style.direction = 'rtl';
      tester.style.visibility = 'hidden';
      document.body.appendChild(tester);

      tester.innerHTML = buildExportFullHeaderHtml(t, classesLabel, showAns, showExamHdr);
      const fullHeaderH = tester.firstElementChild ? (tester.firstElementChild.offsetHeight + 16) : 180;

      tester.innerHTML = buildExportCompactHeaderHtml(t, classesLabel, showAns);
      const compactHeaderH = tester.firstElementChild ? (tester.firstElementChild.offsetHeight + 16) : 55;

      const qHeights = [];
      for (let i = 0; i < qs.length; i++) {
        tester.innerHTML = buildExportQuestionHtml(qs[i], i, showAns, showDegrees);
        const itemEl = tester.firstElementChild;
        const h = itemEl ? (itemEl.offsetHeight + 14) : 110;
        qHeights.push(h);
      }

      document.body.removeChild(tester);
      return { fullHeaderH, compactHeaderH, qHeights };
    }

    function renderQuestionsExportPreview() {
      const container = document.getElementById('tqPaperExport');
      if (!container) return;

      const t = window.currentExportTask || {};
      const qs = window.currentExportQuestions || [];
      const showAns = !!window.currentExportShowAnswers;
      const showExamHdr = document.getElementById('tqShowExamHeader') ? document.getElementById('tqShowExamHeader').checked : true;
      const showDegrees = document.getElementById('tqShowDegrees') ? document.getElementById('tqShowDegrees').checked : true;
      const classesLabel = typeof getTaskClassNames === 'function' ? getTaskClassNames(t).join('، ') : (t.class_name || 'عام');
      const orientation = document.getElementById('tqOrientation')?.value || 'portrait';
      const isLandscape = (orientation === 'landscape');

      if (!qs.length) {
        container.innerHTML = `<div style="text-align:center; padding:50px; color:#94a3b8; width:100%;">لا توجد أسئلة لتصديرها</div>`;
        const statsEl = document.getElementById('tqStatsText');
        if (statsEl) statsEl.innerHTML = 'لا توجد أسئلة';
        return;
      }

      const { fullHeaderH, compactHeaderH, qHeights } = measureQuestionHeights(t, qs, showAns, showExamHdr, showDegrees, isLandscape, classesLabel);

      // Usable content area (excluding header/footer/paddings)
      const usablePageH = isLandscape ? 670 : 990;

      // Group questions into discrete pages without cutting mid-question
      const pages = [];
      let curPageQs = [];
      let curPageContentH = 0;

      for (let i = 0; i < qs.length; i++) {
        const qH = qHeights[i];
        const isFirst = (pages.length === 0);
        const headerH = isFirst ? fullHeaderH : compactHeaderH;
        const maxAllowed = usablePageH - headerH;

        if (curPageQs.length === 0 || (curPageContentH + qH <= maxAllowed)) {
          curPageQs.push(i);
          curPageContentH += qH;
        } else {
          pages.push(curPageQs);
          curPageQs = [i];
          curPageContentH = qH;
        }
      }
      if (curPageQs.length > 0) {
        pages.push(curPageQs);
      }

      const totalPages = pages.length;

      const statsEl = document.getElementById('tqStatsText');
      if (statsEl) {
        statsEl.innerHTML = `عدد الأسئلة: <strong>${qs.length}</strong> | الدرجة: <strong>${t.total_degree || 0}</strong> | الصفحات: <strong>${totalPages} ${totalPages === 1 ? 'صفحة' : 'صفحات'} A4</strong> | الحالة: <span style="color:${showAns ? '#059669' : '#dc2626'}; font-weight:800;">${showAns ? 'نموذج إجابة' : 'ورقة اختبار'}</span>`;
      }

      let pagesHtml = '';

      pages.forEach((pageQIndices, pageIdx) => {
        const isFirst = (pageIdx === 0);
        const headerHtml = isFirst 
          ? buildExportFullHeaderHtml(t, classesLabel, showAns, showExamHdr)
          : buildExportCompactHeaderHtml(t, classesLabel, showAns);

        let qItemsHtml = '';
        pageQIndices.forEach(qIdx => {
          qItemsHtml += buildExportQuestionHtml(qs[qIdx], qIdx, showAns, showDegrees);
        });

        if (pageIdx > 0) {
          pagesHtml += `
            <div class="tq-page-divider ${isLandscape ? 'landscape-view' : ''}">
              <span>الصفحة ${pageIdx + 1} من ${totalPages} (A4)</span>
            </div>
          `;
        }

        pagesHtml += `
          <div class="tq-page-sheet ${isLandscape ? 'landscape-view' : ''}" data-page="${pageIdx + 1}">
            <div class="tq-page-body">
              ${headerHtml}
              <div class="tq-page-questions" style="display:flex; flex-direction:column; gap:12px;">
                ${qItemsHtml}
              </div>
            </div>
            <div class="tq-page-footer">
              <div style="display:flex; align-items:center; gap:6px;">
                <i class="fas fa-church" style="color:var(--brand, #5b6cf5);"></i>
                <span>${getTaskExportChurchName() ? esc(getTaskExportChurchName()) + ' — ' + getTaskExportServiceTitle() : getTaskExportServiceTitle()}</span>
              </div>
              <div class="tq-page-num-badge">صفحة ${pageIdx + 1} من ${totalPages}</div>
            </div>
          </div>
        `;
      });

      container.innerHTML = pagesHtml;
    }

    async function renderPageToCanvas(pageEl, isLandscape = false) {
      const targetW = isLandscape ? 1131 : 800;
      const targetH = isLandscape ? 800 : 1131;

      const clone = pageEl.cloneNode(true);
      clone.style.position = 'absolute';
      clone.style.top = '0';
      clone.style.left = '-9999px';
      clone.style.width = targetW + 'px';
      clone.style.height = targetH + 'px';
      clone.style.maxHeight = targetH + 'px';
      clone.style.boxShadow = 'none';
      clone.style.border = 'none';
      clone.style.borderRadius = '0';
      clone.style.background = '#ffffff';

      clone.querySelectorAll('i.fa-check-circle, i.fa-check').forEach(el => {
        const span = document.createElement('span');
        span.style.color = '#10b981';
        span.style.fontWeight = 'bold';
        span.textContent = '✔ ';
        if (el.parentNode) el.parentNode.replaceChild(span, el);
      });
      clone.querySelectorAll('i.fa-times-circle').forEach(el => {
        const span = document.createElement('span');
        span.style.color = '#ef4444';
        span.style.fontWeight = 'bold';
        span.textContent = '✖ ';
        if (el.parentNode) el.parentNode.replaceChild(span, el);
      });
      clone.querySelectorAll('i.fa-pen-nib').forEach(el => {
        const span = document.createElement('span');
        span.textContent = '✍ ';
        if (el.parentNode) el.parentNode.replaceChild(span, el);
      });
      clone.querySelectorAll('i.fa-church').forEach(el => {
        const span = document.createElement('span');
        span.textContent = '⛪ ';
        if (el.parentNode) el.parentNode.replaceChild(span, el);
      });

      document.body.appendChild(clone);
      await new Promise(r => setTimeout(r, 60));

      try {
        const canvas = await html2canvas(clone, {
          scale: 2,
          useCORS: true,
          backgroundColor: '#ffffff',
          logging: false,
          allowTaint: false,
          width: targetW,
          height: targetH
        });
        return canvas;
      } finally {
        document.body.removeChild(clone);
      }
    }

    async function exportQuestionsPDF() {
      const pageElements = document.querySelectorAll('#tqPaperExport .tq-page-sheet');
      if (!pageElements || !pageElements.length) {
        showToast('لا توجد بيانات لتصديرها', 'err');
        return;
      }

      const { jsPDF } = window.jspdf || {};
      if (!jsPDF) {
        showToast('مكتبة PDF غير محملة', 'err');
        return;
      }

      const orientation = document.getElementById('tqOrientation')?.value || 'portrait';
      const isLandscape = (orientation === 'landscape');
      const pageW_mm = isLandscape ? 297 : 210;
      const pageH_mm = isLandscape ? 210 : 297;
      const totalPages = pageElements.length;

      showToast(`جاري إنشاء PDF (${totalPages} صفحات)...`, 'info');

      try {
        const pdf = new jsPDF({
          orientation: orientation,
          unit: 'mm',
          format: 'a4',
          compress: true
        });

        for (let i = 0; i < totalPages; i++) {
          const pageEl = pageElements[i];
          const canvas = await renderPageToCanvas(pageEl, isLandscape);
          if (!canvas) continue;

          // Highly optimized JPEG encoding: 95% smaller file size (~180KB-250KB per page)
          const imgData = canvas.toDataURL('image/jpeg', 0.88);

          if (i > 0) {
            pdf.addPage([pageW_mm, pageH_mm], orientation);
          }

          pdf.addImage(imgData, 'JPEG', 0, 0, pageW_mm, pageH_mm, undefined, 'FAST');
        }

        const t = window.currentExportTask || {};
        const safeTitle = sanitizeFileName(t.title || 'تاسك');
        const modeLabel = window.currentExportShowAnswers ? 'نموذج_إجابة' : 'ورقة_اختبار';
        pdf.save(`${safeTitle}_أسئلة_${modeLabel}.pdf`);
        showToast(`تم حفظ ملف PDF بنجاح (${totalPages} صفحات) 📄`, 'ok');
      } catch (e) {
        console.error(e);
        showToast('حدث خطأ أثناء إنشاء PDF: ' + (e.message || ''), 'err');
      }
    }

    async function exportQuestionsImage() {
      const pageElements = document.querySelectorAll('#tqPaperExport .tq-page-sheet');
      if (!pageElements || !pageElements.length) {
        showToast('لا توجد بيانات لتصديرها', 'err');
        return;
      }

      showToast('جاري تجهيز الصورة...', 'info');
      try {
        const orientation = document.getElementById('tqOrientation')?.value || 'portrait';
        const isLandscape = (orientation === 'landscape');
        const t = window.currentExportTask || {};
        const safeTitle = sanitizeFileName(t.title || 'تاسك');
        const modeLabel = window.currentExportShowAnswers ? 'نموذج_إجابة' : 'ورقة_اختبار';

        if (pageElements.length === 1) {
          const canvas = await renderPageToCanvas(pageElements[0], isLandscape);
          if (!canvas) throw new Error('فشل إنشاء Canvas');
          const link = document.createElement('a');
          link.download = `${safeTitle}_أسئلة_${modeLabel}.png`;
          link.href = canvas.toDataURL('image/png');
          link.click();
          showToast('تم حفظ الصورة بنجاح 🖼️', 'ok');
        } else {
          const canvases = [];
          for (let i = 0; i < pageElements.length; i++) {
            const c = await renderPageToCanvas(pageElements[i], isLandscape);
            if (c) canvases.push(c);
          }
          if (!canvases.length) throw new Error('فشل إنشاء Canvas');

          const totalW = canvases[0].width;
          const gap = 36;
          let totalH = (canvases.length - 1) * gap;
          canvases.forEach(c => totalH += c.height);

          const combined = document.createElement('canvas');
          combined.width = totalW;
          combined.height = totalH;
          const ctx = combined.getContext('2d');
          ctx.fillStyle = '#f1f5f9';
          ctx.fillRect(0, 0, totalW, totalH);

          let currentY = 0;
          for (let i = 0; i < canvases.length; i++) {
            ctx.drawImage(canvases[i], 0, currentY);
            currentY += canvases[i].height + gap;
          }

          const link = document.createElement('a');
          link.download = `${safeTitle}_أسئلة_${modeLabel}_كامل.png`;
          link.href = combined.toDataURL('image/png');
          link.click();
          showToast(`تم حفظ الصورة المجمعة (${pageElements.length} صفحات) بنجاح 🖼️`, 'ok');
        }
      } catch (e) {
        console.error(e);
        showToast('فشل تصدير الصورة: ' + (e.message || ''), 'err');
      }
    }

    function printQuestionsExam() {
      const pageElements = document.querySelectorAll('#tqPaperExport .tq-page-sheet');
      if (!pageElements || !pageElements.length) {
        showToast('لا توجد بيانات للطباعة', 'err');
        return;
      }

      showToast(`جاري تجهيز ${pageElements.length} صفحات للطباعة...`, 'info');

      const orientation = document.getElementById('tqOrientation')?.value || 'portrait';
      const isLandscape = (orientation === 'landscape');
      const taskTitle = window.currentExportTask?.title || 'تاسك';

      let printFrame = document.getElementById('tqPrintIframe');
      if (printFrame) printFrame.remove();

      printFrame = document.createElement('iframe');
      printFrame.id = 'tqPrintIframe';
      printFrame.style.position = 'fixed';
      printFrame.style.top = '-9999px';
      printFrame.style.left = '-9999px';
      printFrame.style.width = '1000px';
      printFrame.style.height = '1400px';
      printFrame.style.border = 'none';
      printFrame.style.visibility = 'hidden';
      document.body.appendChild(printFrame);

      const frameDoc = printFrame.contentWindow.document;
      frameDoc.open();
      frameDoc.write(`<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
  <meta charset="utf-8">
  <title>${esc(taskTitle)}</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Baloo+Bhaijaan+2:wght@400;600;700;800;900&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    @page {
      size: A4 ${orientation};
      margin: 10mm 12mm 10mm 12mm;
    }
    * {
      box-sizing: border-box;
      -webkit-print-color-adjust: exact !important;
      print-color-adjust: exact !important;
    }
    html, body {
      margin: 0;
      padding: 0;
      background: #ffffff;
      color: #1e293b;
      font-family: 'Baloo Bhaijaan 2', 'Cairo', sans-serif;
      direction: rtl;
    }
    .tq-print-sheet {
      width: 100%;
      min-height: ${isLandscape ? '190mm' : '270mm'};
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      page-break-after: always;
      break-after: page;
      padding: 0;
      box-sizing: border-box;
      background: #ffffff;
    }
    .tq-print-sheet:last-child {
      page-break-after: auto;
      break-after: auto;
    }
    .tq-q-item {
      background: #ffffff;
      border: 1.5px solid #cbd5e1;
      border-radius: 10px;
      padding: 12px 16px;
      margin-bottom: 12px;
      page-break-inside: avoid;
      break-inside: avoid;
      -webkit-print-color-adjust: exact !important;
      print-color-adjust: exact !important;
    }
    .tq-q-header {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      margin-bottom: 10px;
    }
    .tq-q-num {
      background: #eff2fe;
      color: #4338ca;
      font-weight: 900;
      font-size: 0.95rem;
      width: 30px;
      height: 30px;
      border-radius: 7px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      border: 1px solid #c7d2fe;
      -webkit-print-color-adjust: exact !important;
      print-color-adjust: exact !important;
    }
    .tq-q-title {
      flex: 1;
      font-size: 1.02rem;
      font-weight: 700;
      line-height: 1.45;
      color: #0f172a;
    }
    .tq-q-badge {
      font-size: 0.74rem;
      font-weight: 700;
      padding: 3px 9px;
      border-radius: 6px;
      white-space: nowrap;
      -webkit-print-color-adjust: exact !important;
      print-color-adjust: exact !important;
    }
    .tq-opt-row {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 8px 12px;
      border: 1.5px solid #e2e8f0;
      border-radius: 8px;
      margin-top: 7px;
      font-size: 0.9rem;
      background: #ffffff;
    }
    .tq-opt-row.is-correct {
      background: #ecfdf5 !important;
      border-color: #10b981 !important;
      color: #065f46 !important;
      font-weight: 800;
      -webkit-print-color-adjust: exact !important;
      print-color-adjust: exact !important;
    }
    .tq-page-footer {
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-top: 1.5px dashed #cbd5e1;
      padding-top: 10px;
      margin-top: 14px;
      font-size: 0.76rem;
      font-weight: 700;
      color: #64748b;
      flex-shrink: 0;
    }
    .tq-page-num-badge {
      background: #f1f5f9;
      color: #334155;
      padding: 2px 9px;
      border-radius: 99px;
      border: 1px solid #e2e8f0;
      font-weight: 800;
      font-size: 0.72rem;
      -webkit-print-color-adjust: exact !important;
      print-color-adjust: exact !important;
    }
  </style>
</head>
<body>`);

      pageElements.forEach((pageEl) => {
        frameDoc.write(`<div class="tq-print-sheet">${pageEl.innerHTML}</div>`);
      });

      frameDoc.write(`</body></html>`);
      frameDoc.close();

      setTimeout(() => {
        try {
          printFrame.contentWindow.focus();
          printFrame.contentWindow.print();
        } catch (err) {
          console.error('Print iframe error:', err);
          window.print();
        }
      }, 350);
    }

    function exportQuestionsExcel() {
      if (typeof XLSX === 'undefined') {
        showToast('مكتبة Excel غير متوفرة', 'err');
        return;
      }

      const t = window.currentExportTask || {};
      const qs = window.currentExportQuestions || [];
      const showAns = !!window.currentExportShowAnswers;
      if (!qs.length) {
        showToast('لا توجد أسئلة لتصديرها', 'err');
        return;
      }

      try {
        const classesLabel = typeof getTaskClassNames === 'function' ? getTaskClassNames(t).join('، ') : (t.class_name || 'عام');
        const churchName = getTaskExportChurchName();
        const serviceTitle = getTaskExportServiceTitle();
        const excelHeader = churchName ? `${churchName} — ${serviceTitle}` : serviceTitle;
        const wsData = [
          [excelHeader],
          ['عنوان التاسك:', t.title || 'تاسك', 'نوع الملف:', showAns ? 'نموذج إجابة معتمد' : 'ورقة اختبار بدون إجابات'],
          ['الفصل:', classesLabel, 'عدد الأسئلة:', qs.length, 'إجمالي الدرجات:', t.total_degree || 0, 'تاريخ التصدير:', new Date().toLocaleDateString('ar-EG')],
          [] // empty separator row
        ];

        if (showAns) {
          wsData.push(['م', 'نوع السؤال', 'نص السؤال', 'الدرجة', 'الخيارات المتاحة', 'الإجابة الصحيحة', 'رابط الصورة المرفقة']);
        } else {
          wsData.push(['م', 'نوع السؤال', 'نص السؤال', 'الدرجة', 'الخيارات المتاحة', 'إجابة الطالب', 'رابط الصورة المرفقة']);
        }

        qs.forEach((q, i) => {
          const qType = q.question_type || 'mcq';
          let typeStr = 'اختيار من متعدد';
          let optsStr = '';
          let ansStr = '';

          if (qType === 'tf') {
            typeStr = 'صح أو خطأ';
            optsStr = '1) صحيح  |  2) خطأ';
            const ci = parseInt(q.correct_index || 0);
            ansStr = ci === 0 ? 'صحيح' : 'خطأ';
          } else if (qType === 'open') {
            typeStr = 'سؤال مقالي';
            optsStr = '— إجابة حرة من الطالب —';
            ansStr = 'سؤال مقالي (تقييم يدوي)';
          } else {
            const opts = typeof q.options === 'string' ? JSON.parse(q.options || '[]') : (q.options || []);
            const ci = parseInt(q.correct_index || 0);
            optsStr = opts.map((o, oi) => `${LETTERS[oi] || (oi + 1)}) ${o}`).join('  |  ');
            ansStr = opts[ci] ? `${LETTERS[ci] || (ci + 1)}) ${opts[ci]}` : '';
          }

          wsData.push([
            i + 1,
            typeStr,
            q.question_text || '',
            parseInt(q.degree) || 0,
            optsStr,
            showAns ? ansStr : '',
            q.image_url || ''
          ]);
        });

        const ws = XLSX.utils.aoa_to_sheet(wsData);
        ws['!views'] = [{ RTL: true }];
        ws['!cols'] = [
          { wch: 6 },
          { wch: 16 },
          { wch: 45 },
          { wch: 8 },
          { wch: 45 },
          { wch: 26 },
          { wch: 25 }
        ];

        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'الأسئلة');
        const safeTitle = sanitizeFileName(t.title || 'تاسك');
        const modeLabel = showAns ? 'نموذج_إجابة' : 'ورقة_اختبار';
        XLSX.writeFile(wb, `${safeTitle}_أسئلة_${modeLabel}.xlsx`);
        showToast('تم تنزيل ملف Excel بنجاح 📊', 'ok');
      } catch (e) {
        console.error(e);
        showToast('فشل تصدير Excel: ' + (e.message || ''), 'err');
      }
    }

    function copyQuestionsText() {
      const t = window.currentExportTask || {};
      const qs = window.currentExportQuestions || [];
      const showAns = !!window.currentExportShowAnswers;
      if (!qs.length) { showToast('لا توجد أسئلة لنسخها', 'err'); return; }

      const classesLabel = typeof getTaskClassNames === 'function' ? getTaskClassNames(t).join('، ') : (t.class_name || 'عام');
      let msg = `📝 *${t.title || 'أسئلة التاسك'}*\n`;
      msg += `👥 الفصل: ${classesLabel}\n`;
      msg += `📊 إجمالي الدرجات: ${t.total_degree || 0} درجة | عدد الأسئلة: ${qs.length}\n`;
      msg += `📌 النوع: ${showAns ? 'نموذج إجابة' : 'ورقة اختبار'}\n\n`;
      msg += `═══════════════════════════\n\n`;

      qs.forEach((q, i) => {
        const qType = q.question_type || 'mcq';
        const deg = parseInt(q.degree) || 0;
        msg += `*س${i + 1}:* ${q.question_text || ''} ${deg > 0 ? `(${deg} درجات)` : ''}\n`;

        if (qType === 'tf') {
          const ci = parseInt(q.correct_index || 0);
          if (showAns) {
            msg += `• الإجابة الصحيحة: ${ci === 0 ? '✔ صحيح' : '✖ خطأ'}\n`;
          } else {
            msg += `  ( ) صحيح      ( ) خطأ\n`;
          }
        } else if (qType === 'open') {
          if (showAns) {
            msg += `• النوع: سؤال مقالي (إجابة حرة)\n`;
          } else {
            msg += `  الإجابة: ....................................................\n`;
          }
        } else {
          const opts = typeof q.options === 'string' ? JSON.parse(q.options || '[]') : (q.options || []);
          const ci = parseInt(q.correct_index || 0);
          opts.forEach((o, oi) => {
            const letter = LETTERS[oi] || (oi + 1);
            if (showAns && oi === ci) {
              msg += `  [✔] ${letter}) ${o} (الإجابة الصحيحة)\n`;
            } else {
              msg += `  ( ) ${letter}) ${o}\n`;
            }
          });
        }
        msg += `\n`;
      });

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(msg).then(() => {
          showToast('تم نسخ الأسئلة كنص بنجاح 📋', 'ok');
        }).catch(() => fallbackCopyTaskText(msg));
      } else {
        fallbackCopyTaskText(msg);
      }
    }
  </script>







  <!-- ══ GRADING PANEL ══ -->



  <div class="grade-panel" id="gradePanel">



    <div class="grade-sheet">



      <div class="grade-sheet-hdr"
        style="background:var(--bg3); padding:10px 14px; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid var(--bdr); gap:8px; flex-wrap:nowrap;">
        <div style="display:flex; align-items:center; gap:8px; min-width:0;">
          <div
            style="width:30px;height:30px;border-radius:8px;background:var(--warn-bg);color:var(--warn);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.8rem;">
            <i class="fas fa-pen-nib"></i></div>
          <div style="display:flex; flex-direction:column; min-width:0;">
            <div
              style="font-size:.88rem;font-weight:800;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
              تصحيح الإجابات</div>
            <div style="font-size:.66rem;color:var(--t3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
              id="gradePanelSub">أدخل الدرجة لكل إجابة</div>
          </div>
        </div>

        <!-- Navigation Arrows -->
        <div class="grade-nav-arrows"
          style="display:flex; align-items:center; gap:6px; margin-right:auto; margin-left:8px; direction:rtl; flex-shrink:0;">
          <button onclick="navigateGrading(-1)" id="prevGradeBtn"
            style="background:var(--bg2); border:1px solid var(--bdr); color:var(--t1); width:28px; height:28px; border-radius:6px; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:.75rem; transition:0.2s;"
            onmouseover="this.style.background='var(--brand-bg)'; this.style.color='var(--brand)';"
            onmouseout="this.style.background='var(--bg2)'; this.style.color='var(--t1)';"><i
              class="fas fa-chevron-right"></i></button>
          <span id="gradeIndexIndicator"
            style="font-size:0.75rem; font-weight:800; color:var(--t2); min-width:35px; text-align:center; white-space:nowrap;">—</span>
          <button onclick="navigateGrading(1)" id="nextGradeBtn"
            style="background:var(--bg2); border:1px solid var(--bdr); color:var(--t1); width:28px; height:28px; border-radius:6px; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:.75rem; transition:0.2s;"
            onmouseover="this.style.background='var(--brand-bg)'; this.style.color='var(--brand)';"
            onmouseout="this.style.background='var(--bg2)'; this.style.color='var(--t1)';"><i
              class="fas fa-chevron-left"></i></button>
        </div>

        <button onclick="closeGradePanel()"
          style="background:var(--bg2); border:1px solid var(--bdr); color:var(--t2); width:28px; height:28px; border-radius:8px; cursor:pointer; font-size:.75rem; display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:0.2s;"
          onmouseover="this.style.background='var(--err-bg)'; this.style.color='var(--err)';"
          onmouseout="this.style.background='var(--bg2)'; this.style.color='var(--t2)';"><i
            class="fas fa-times"></i></button>
      </div>



      <div class="grade-sheet-body" id="gradePanelBody">



        <div class="skeleton-row">
          <div class="skeleton-line" style="height:14px; width:45%;"></div>
          <div class="skeleton-line" style="height:28px; width:60px; border-radius:6px;"></div>
        </div>
        <div class="skeleton-row">
          <div class="skeleton-line" style="height:14px; width:55%;"></div>
          <div class="skeleton-line" style="height:28px; width:60px; border-radius:6px;"></div>
        </div>
        <div class="skeleton-row">
          <div class="skeleton-line" style="height:14px; width:35%;"></div>
          <div class="skeleton-line" style="height:28px; width:60px; border-radius:6px;"></div>
        </div>



      </div>



    </div>



  </div>

  <!-- WEBSITE CUSTOM CONFIRMATION MODAL -->
  <div id="websiteConfirmModal"
    style="display:none; position:fixed; inset:0; z-index:100000; background:rgba(15,23,42,0.6); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); align-items:center; justify-content:center; padding:16px;">
    <div
      style="background:var(--bg,#ffffff); max-width:440px; width:100%; border-radius:20px; padding:24px; text-align:center; box-shadow:0 20px 40px rgba(0,0,0,0.25); border:1px solid var(--bdr,rgba(0,0,0,0.08));">
      <div id="wConfirmIconBox"
        style="width:60px; height:60px; margin:0 auto 14px; border-radius:18px; background:rgba(239,68,68,0.12); color:var(--err,#ef4444); display:flex; align-items:center; justify-content:center; font-size:1.6rem; box-shadow:0 8px 20px rgba(239,68,68,0.2);">
        <i id="wConfirmIcon" class="fas fa-exclamation-triangle"></i>
      </div>
      <h3 id="wConfirmTitle" style="font-size:1.15rem; font-weight:800; color:var(--t1,#1e293b); margin-bottom:8px;">
        تأكيد الإجراء</h3>
      <div id="wConfirmMessage"
        style="font-size:0.85rem; color:var(--t2,#475569); margin-bottom:20px; line-height:1.5; text-align:center; white-space:pre-line;">
      </div>

      <div style="display:flex; gap:10px; justify-content:center;">
        <button type="button" id="wConfirmCancelBtn" onclick="resolveCustomConfirm(false)"
          style="flex:1; padding:10px 16px; border-radius:10px; border:1px solid var(--bdr,rgba(0,0,0,0.12)); background:var(--bg2,#f8fafc); color:var(--t2,#475569); font-size:0.85rem; font-weight:700; cursor:pointer;">
          إلغاء
        </button>
        <button type="button" id="wConfirmOkBtn" onclick="resolveCustomConfirm(true)"
          style="flex:1; padding:10px 16px; border-radius:10px; border:none; background:var(--err,#ef4444); color:#fff; font-size:0.85rem; font-weight:800; cursor:pointer; box-shadow:0 4px 12px rgba(239,68,68,0.3);">
          موافق
        </button>
      </div>
    </div>
  </div>

  <!-- TARANIM & BIBLE CONNECTION ONBOARDING MODAL -->
  <div id="taranimOnboardingModal" class="taranim-onboard-overlay" style="display:none;">
    <div class="taranim-onboard-card">
      <button type="button" onclick="closeTaranimOnboardingModal()" style="position:absolute; top:16px; left:16px; background:var(--bg2, #f1f5f9); border:none; width:32px; height:32px; border-radius:50%; color:var(--t2); cursor:pointer; display:flex; align-items:center; justify-content:center;">
        <i class="fas fa-times"></i>
      </button>

      <div class="logo-connection-container">
        <!-- Sunday School Logo -->
        <img src="/logo.png" class="brand-node-logo" alt="Sunday School Logo">
        
        <!-- Connection Node Art -->
        <div class="connection-node">
          <i class="fas fa-link connection-standalone-icon"></i>
        </div>

        <!-- Taranim Logo -->
        <img src="/taranim/logo_t.png" class="brand-node-logo" alt="Taranim Logo">
      </div>

      <h3 class="taranim-onboard-title">ربط قاعدة الترانيم والكتاب المقدس</h3>
      <p class="taranim-onboard-desc">
        تم ربط نظام مدارس الأحد بنجاح مع قاعدة بيانات الكتاب المقدس والترانيم! يمكنك الآن البحث عن الآيات والتطبيقات مباشرة وتضمينها في نص أسئلة التاسكات بضغطة زر.
      </p>

      <button type="button" class="taranim-onboard-btn" onclick="confirmTaranimOnboarding()">
        <span>متابعة</span>
        <i class="fas fa-arrow-left"></i>
      </button>
    </div>
  </div>

  <!-- BIBLE VERSE SEARCH & PICKER MODAL -->
  <div id="bibleVerseModal" class="taranim-onboard-overlay" style="display:none;">
    <div class="taranim-onboard-card bible-modal-card">
      <div style="display:flex; align-items:center; justify-content:space-between; padding-bottom:12px; border-bottom:1px solid var(--bdr, #e2e8f0);">
        <a href="/taranim/" target="_blank" title="منصة الترانيم والكتاب المقدس" style="display:inline-flex; align-items:center; gap:6px; text-decoration:none; background:var(--bg2, #f8fafc); padding:4px 10px; border-radius:10px; border:1px solid var(--bdr, #e2e8f0); transition:all 0.2s ease;">
          <img src="/taranim/logo_t.png" style="width:24px; height:24px; object-fit:contain; border-radius:6px;" alt="Taranim Logo">
          <span style="font-size:0.75rem; font-weight:700; color:var(--t2);">منصة الترانيم</span>
          <i class="fas fa-external-link-alt" style="font-size:0.65rem; color:var(--t3);"></i>
        </a>
        <button type="button" onclick="closeBibleVerseModal()" style="background:var(--bg2, #f1f5f9); border:none; width:32px; height:32px; border-radius:50%; color:var(--t2); cursor:pointer; display:flex; align-items:center; justify-content:center;">
          <i class="fas fa-times"></i>
        </button>
      </div>

      <!-- Search Controls -->
      <div style="padding:14px 0 10px 0; display:flex; flex-direction:column; gap:10px;">
        <div style="position:relative;">
          <input type="text" id="bibleSearchInput" oninput="onBibleSearchInput()" placeholder="ابحث باسم السفر، الأصحاح، أو كلمة في الآية (مثال: يوحنا 3، تكوين 1، محبة)..." style="width:100%; padding:10px 38px 10px 14px; border-radius:12px; border:1px solid var(--bdr); background:var(--bg2); color:var(--t1); font-size:0.88rem; outline:none;" />
          <i class="fas fa-search" style="position:absolute; right:12px; top:50%; transform:translateY(-50%); color:var(--t3); font-size:0.9rem;"></i>
        </div>

        <div style="display:flex; gap:8px;">
          <select id="bibleBookSelect" onchange="onBibleBookSelectChange()" style="flex:2; padding:8px 10px; border-radius:10px; border:1px solid var(--bdr); background:var(--bg2); color:var(--t1); font-size:0.82rem; outline:none;">
            <option value="">جميع أسفار الكتاب المقدس...</option>
          </select>
          <select id="bibleChapterSelect" onchange="onBibleChapterSelectChange()" style="flex:1; padding:8px 10px; border-radius:10px; border:1px solid var(--bdr); background:var(--bg2); color:var(--t1); font-size:0.82rem; outline:none;">
            <option value="">الأصحاح...</option>
          </select>
        </div>
      </div>

      <!-- Results Body -->
      <div class="bible-modal-body" id="bibleResultsList">
        <div style="text-align:center; padding:30px 10px; color:var(--t3); font-size:0.88rem;">
          <i class="fas fa-search" style="font-size:2rem; margin-bottom:10px; opacity:0.5; display:block;"></i>
          اكتب كلمة البحث أو اختر السفر والأصحاح لعرض الآيات
        </div>
      </div>
    </div>
  </div>

  <script>
    // ══════════════════════════════════════════════════════════════════
    // TARANIM & BIBLE VERSES INTEGRATION JARS
    // ══════════════════════════════════════════════════════════════════
    window.currentBibleTargetQId = null;
    window.bibleBooksData = [];

    function openBibleVerseModalForQ(qId) {
      window.currentBibleTargetQId = qId;
      var onboarded = localStorage.getItem('taranim_bible_tool_onboarded');
      if (!onboarded) {
        var obm = document.getElementById('taranimOnboardingModal');
        if (obm) obm.style.display = 'flex';
      } else {
        showBibleVerseModal();
      }
    }

    function closeTaranimOnboardingModal() {
      var obm = document.getElementById('taranimOnboardingModal');
      if (obm) obm.style.display = 'none';
    }

    function confirmTaranimOnboarding() {
      localStorage.setItem('taranim_bible_tool_onboarded', 'true');
      closeTaranimOnboardingModal();
      showBibleVerseModal();
    }

    function showBibleVerseModal() {
      var bvm = document.getElementById('bibleVerseModal');
      if (bvm) bvm.style.display = 'flex';
      if (!window.bibleBooksData || window.bibleBooksData.length === 0) {
        loadBibleBooksData();
      }
    }

    function closeBibleVerseModal() {
      var bvm = document.getElementById('bibleVerseModal');
      if (bvm) bvm.style.display = 'none';
    }

    async function loadBibleBooksData() {
      try {
        const res = await fetch('/taranim/bible_books_data.json');
        if (res.ok) {
          window.bibleBooksData = await res.json();
          populateBibleBookSelect();
        }
      } catch (e) {
        console.error('Failed loading bible books data:', e);
      }
    }

    function populateBibleBookSelect() {
      var sel = document.getElementById('bibleBookSelect');
      if (!sel) return;
      sel.innerHTML = '<option value="">جميع أسفار الكتاب المقدس...</option>';
      if (Array.isArray(window.bibleBooksData)) {
        window.bibleBooksData.forEach(function(b) {
          var opt = document.createElement('option');
          opt.value = b.id;
          opt.textContent = b.title + ' (' + b.totalChapters + ' أصحاح)';
          opt.dataset.chapters = b.totalChapters;
          opt.dataset.title = b.title;
          sel.appendChild(opt);
        });
      }
    }

    function onBibleBookSelectChange() {
      var bSel = document.getElementById('bibleBookSelect');
      var cSel = document.getElementById('bibleChapterSelect');
      if (!bSel || !cSel) return;
      
      var selectedBookId = bSel.value;
      cSel.innerHTML = '<option value="">الأصحاح...</option>';
      
      if (selectedBookId) {
        var bookObj = window.bibleBooksData.find(function(b){ return b.id == selectedBookId; });
        if (bookObj && bookObj.totalChapters) {
          for (var i = 1; i <= bookObj.totalChapters; i++) {
            var opt = document.createElement('option');
            opt.value = i;
            opt.textContent = 'الأصحاح ' + i;
            cSel.appendChild(opt);
          }
        }
      }
      executeBibleSearch();
    }

    function onBibleChapterSelectChange() {
      executeBibleSearch();
    }

    var bibleSearchTimeout = null;
    function onBibleSearchInput() {
      clearTimeout(bibleSearchTimeout);
      bibleSearchTimeout = setTimeout(executeBibleSearch, 300);
    }

    async function executeBibleSearch() {
      var qInp = document.getElementById('bibleSearchInput');
      var bSel = document.getElementById('bibleBookSelect');
      var cSel = document.getElementById('bibleChapterSelect');
      var container = document.getElementById('bibleResultsList');
      if (!container) return;

      var q = qInp ? qInp.value.trim() : '';
      var bookId = bSel ? bSel.value : '';
      var chNum = cSel ? cSel.value : '';

      if (!q && !bookId) {
        container.innerHTML = '<div style="text-align:center; padding:30px 10px; color:var(--t3); font-size:0.88rem;"><i class="fas fa-search" style="font-size:2rem; margin-bottom:10px; opacity:0.5; display:block;"></i>اكتب كلمة البحث أو اختر السفر والأصحاح لعرض الآيات</div>';
        return;
      }

      container.innerHTML = '<div style="text-align:center; padding:30px 10px; color:var(--t2);"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem; margin-bottom:8px; display:block; color:#6366f1;"></i>جاري البحث في الكتاب المقدس...</div>';

      try {
        let url = '/taranim/api.php?q=' + encodeURIComponent(q);
        if (bookId && chNum) {
          url = '/taranim/api.php?action=bible_chapter&book_id=' + bookId + '&chapter=' + chNum;
        }

        const res = await fetch(url);
        const data = await res.json();

        let items = [];
        if (data.songs && Array.isArray(data.songs)) {
          items = data.songs.filter(function(s){ return s.is_bible == 1 || (s.title && s.title.includes('الأصحاح')); });
        } else if (data.title || data.id) {
          items = [data];
        }

        if (!items || items.length === 0) {
          container.innerHTML = '<div style="text-align:center; padding:30px 10px; color:var(--t3);">لا توجد نتائج مطابقة لبحثك</div>';
          return;
        }

        if (typeof getMatchScore === 'function' && q) {
          items.forEach(function(it) {
            it._score = getMatchScore(it, q, ['title', 'notes']);
          });
          items.sort(function(a,b){ return (b._score || 0) - (a._score || 0); });
        }

        container.innerHTML = '';
        
        for (let i = 0; i < Math.min(items.length, 10); i++) {
          let item = items[i];
          let detailsRes = await fetch('/taranim/api.php?action=song&id=' + item.id + '&is_bible=1');
          if (!detailsRes.ok) continue;
          let detailedData = await detailsRes.json();
          
          let itemTitle = detailedData.title || item.title || 'آية';
          
          if (detailedData.verses && Array.isArray(detailedData.verses)) {
            detailedData.verses.forEach(function(v, vIdx) {
              if (v.slides && Array.isArray(v.slides)) {
                v.slides.forEach(function(sl, sIdx) {
                  if (sl.lines && Array.isArray(sl.lines) && sl.lines.length > 0) {
                    let verseTxt = sl.lines.join(' ').trim();
                    if (!verseTxt) return;
                    
                    let fullRef = itemTitle + (sl.heading ? ' : ' + sl.heading : '');
                    let escapedVerseTxt = verseTxt.replace(/'/g, "\\'").replace(/"/g, "&quot;");
                    let escapedRef = fullRef.replace(/'/g, "\\'").replace(/"/g, "&quot;");
                    
                    let vCard = document.createElement('div');
                    vCard.className = 'bible-verse-item';
                    vCard.innerHTML = `
                      <div class="bible-verse-header">
                        <span class="bible-verse-ref"><i class="fas fa-bookmark" style="margin-left:4px;"></i> ${esc(fullRef)}</span>
                      </div>
                      <div class="bible-verse-text">«${esc(verseTxt)}»</div>
                      <div class="bible-verse-actions">
                        <button type="button" class="btn-insert-verse btn-insert-ref" onclick="doInsertVerseText('${escapedRef}')">تضمين الشاهد فقط</button>
                        <button type="button" class="btn-insert-verse btn-insert-full" onclick="doInsertVerseText('«${escapedVerseTxt}» (${escapedRef})')">تضمين الآية والشاهد</button>
                      </div>
                    `;
                    container.appendChild(vCard);
                  }
                });
              }
            });
          }
        }

        if (container.children.length === 0) {
          container.innerHTML = '<div style="text-align:center; padding:30px 10px; color:var(--t3);">لم يتم العثور على نصوص للآيات في النتائج</div>';
        }

      } catch (e) {
        console.error('Error fetching Bible search:', e);
        container.innerHTML = '<div style="text-align:center; padding:30px 10px; color:var(--err);">حدث خطأ أثناء تحميل الآيات، يرجى المحاولة مرة أخرى.</div>';
      }
    }

    function doInsertVerseText(textToInsert) {
      if (!window.currentBibleTargetQId) {
        showToast('لم يتم تحديد السؤال الهدف', 'error');
        return;
      }
      
      var targetCard = document.querySelector('.qcard[data-qid="' + window.currentBibleTargetQId + '"]');
      if (targetCard) {
        var qiInp = targetCard.querySelector('input.qi');
        if (qiInp) {
          var currentVal = qiInp.value ? qiInp.value.trim() : '';
          if (currentVal) {
            qiInp.value = currentVal + ' ' + textToInsert;
          } else {
            qiInp.value = textToInsert;
          }
          qiInp.dispatchEvent(new Event('input', { bubbles: true }));
          showToast('تمت إضافة الآية بنجاح ✓', 'success');
          closeBibleVerseModal();
        }
      } else {
        showToast('تعذر العثور على حقل السؤال', 'error');
      }
    }
  </script>

  <!-- BULK QUESTIONS IMPORT MODAL -->
  <div id="bulkQModal" class="taranim-onboard-overlay" style="display:none;">
    <div class="taranim-onboard-card" style="max-width:760px; width:100%; max-height:88vh; max-height:88dvh; display:flex; flex-direction:column; padding:24px !important; text-align:right !important; border-radius:24px;">
      <!-- Modal Header -->
      <div style="display:flex; align-items:center; justify-content:space-between; padding-bottom:14px; border-bottom:1px solid var(--bdr, #e2e8f0);">
        <h3 style="font-family:'Baloo Bhaijaan 2', sans-serif; font-size:1.2rem; font-weight:700; color:var(--t1); margin:0; display:flex; align-items:center; gap:8px;">
          <i class="fas fa-file-excel" style="color:#10b981;"></i> استيراد شيت أسئلة
        </h3>
        <button type="button" onclick="closeBulkQModal()" style="background:var(--bg2, #f1f5f9); border:none; width:32px; height:32px; border-radius:50%; color:var(--t2); cursor:pointer; display:flex; align-items:center; justify-content:center;">
          <i class="fas fa-times"></i>
        </button>
      </div>

      <!-- Modal Content Body -->
      <div style="overflow-y:auto; flex:1; padding:16px 4px; display:flex; flex-direction:column; gap:16px; text-align:right;">
        
        <!-- Step 1 / Action Header: Download Template & Instructions -->
        <div style="background:var(--bg2, #f8fafc); border:1px dashed var(--bdr, #cbd5e1); border-radius:16px; padding:16px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
          <div>
            <div style="font-weight:700; font-size:0.95rem; color:var(--t1); margin-bottom:4px;">
              <i class="fas fa-info-circle" style="color:var(--brand, #6366f1); margin-left:4px;"></i> تعليمات الاستيراد
            </div>
            <div style="font-size:0.8rem; color:var(--t2); line-height:1.6;">
              حمل نموذج الشيت الفارغ لتعبئة الأسئلة والخيارات والدرجات، ثم ارفعه أو انسخ محتواه مباشرة.
            </div>
          </div>
          <button type="button" onclick="downloadEmptyTaskTemplate()" class="btn" style="background:linear-gradient(135deg, #10b981 0%, #059669 100%); color:#fff; border:none; border-radius:12px; padding:8px 16px; font-weight:700; font-size:0.82rem; cursor:pointer; display:inline-flex; align-items:center; gap:8px; box-shadow:0 4px 12px rgba(16,185,129,0.25);">
            <i class="fas fa-download"></i> تحميل نموذج شيت فارغ (CSV)
          </button>
        </div>

        <!-- Inputs Grid: Upload File OR Copy-Paste -->
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:14px;">
          <!-- File Drop Zone -->
          <div style="border:2px dashed var(--bdr); border-radius:16px; padding:16px; text-align:center; background:var(--bg2); transition:all 0.2s ease; cursor:pointer;" onclick="document.getElementById('bulkQFileInput').click()">
            <i class="fas fa-cloud-upload-alt" style="font-size:2rem; color:var(--brand); margin-bottom:8px; display:block;"></i>
            <div style="font-weight:700; font-size:0.88rem; color:var(--t1); margin-bottom:4px;">رفع ملف شيت (Excel / CSV)</div>
            <div style="font-size:0.75rem; color:var(--t3);">اضغط هنا لاختيار ملف .csv أو .xlsx أو .xls</div>
            <input type="file" id="bulkQFileInput" accept=".csv, .xlsx, .xls, .txt" style="display:none;" onchange="handleBulkQFileUpload(event)" />
            <div id="bulkQFileName" style="margin-top:8px; font-size:0.8rem; font-weight:700; color:#10b981;"></div>
          </div>

          <!-- Direct Copy-Paste Area -->
          <div style="display:flex; flex-direction:column; gap:6px;">
            <label style="font-size:0.82rem; font-weight:700; color:var(--t1); display:flex; align-items:center; gap:6px;">
              <i class="fas fa-paste" style="color:var(--brand);"></i> أو لصق جدول البيانات مباشرة:
            </label>
            <textarea id="bulkQPasteArea" placeholder="قم بنسخ الجدول من Excel ولصقه هنا مباشرة..." oninput="parseBulkQPasteText()" style="flex:1; min-height:90px; width:100%; border-radius:12px; border:1px solid var(--bdr); background:var(--bg2); color:var(--t1); padding:10px; font-size:0.8rem; font-family:monospace; resize:vertical; outline:none;"></textarea>
          </div>
        </div>

        <!-- Preview Section -->
        <div>
          <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
            <div style="font-weight:700; font-size:0.9rem; color:var(--t1); display:flex; align-items:center; gap:6px;">
              <i class="fas fa-list-check" style="color:var(--brand);"></i> معاينة الأسئلة المستخرجة
            </div>
            <div id="bulkQCountBadge" style="font-size:0.78rem; font-weight:700; color:var(--t3); background:var(--bg2); padding:2px 10px; border-radius:20px; border:1px solid var(--bdr);">
              0 أسئلة
            </div>
          </div>

          <div style="max-height:220px; overflow-y:auto; border:1px solid var(--bdr); border-radius:14px; background:var(--bg);">
            <table style="width:100%; border-collapse:collapse; text-align:right; font-size:0.8rem;">
              <thead style="background:var(--bg2); position:sticky; top:0; font-weight:700; color:var(--t1); border-bottom:1px solid var(--bdr);">
                <tr>
                  <th style="padding:8px 10px; width:35px; text-align:center;">#</th>
                  <th style="padding:8px 10px; width:85px;">النوع</th>
                  <th style="padding:8px 10px;">السؤال</th>
                  <th style="padding:8px 10px;">الخيارات</th>
                  <th style="padding:8px 10px; width:65px; text-align:center;">الصورة</th>
                  <th style="padding:8px 10px; width:55px; text-align:center;">الدرجة</th>
                  <th style="padding:8px 10px; width:65px; text-align:center;">الحالة</th>
                </tr>
              </thead>
              <tbody id="bulkQPreviewTbody">
                <tr>
                  <td colspan="7" style="text-align:center; padding:24px; color:var(--t3);">
                    قم برفع ملف أو لصق بيانات المعاينة لعرض الأسئلة هنا
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

      </div>

      <!-- Modal Footer -->
      <div style="display:flex; align-items:center; justify-content:flex-end; gap:10px; padding-top:14px; border-top:1px solid var(--bdr, #e2e8f0);">
        <button type="button" onclick="closeBulkQModal()" style="padding:10px 18px; border-radius:12px; border:1px solid var(--bdr); background:var(--bg2); color:var(--t2); font-weight:700; font-size:0.85rem; cursor:pointer;">
          إلغاء
        </button>
        <button type="button" id="btnConfirmBulkQImport" onclick="confirmImportTaskQuestions()" disabled style="padding:10px 22px; border-radius:12px; border:none; background:linear-gradient(135deg, #10b981 0%, #059669 100%); color:#fff; font-weight:700; font-size:0.85rem; cursor:pointer; opacity:0.5; display:inline-flex; align-items:center; gap:8px; box-shadow:0 4px 14px rgba(16,185,129,0.3);">
          <i class="fas fa-plus-circle"></i> إضافة الأسئلة المقبولة للتاسك
        </button>
      </div>

    </div>
  </div>

  <script>
    // ══════════════════════════════════════════════════════════════════
    // BULK QUESTION IMPORT & TEMPLATE PROVIDER
    // ══════════════════════════════════════════════════════════════════
    window.parsedBulkQuestions = [];

    function downloadEmptyTaskTemplate() {
      var csvContent = "\uFEFF"; // UTF-8 BOM for Excel Arabic support
      csvContent += "نوع السؤال,نص السؤال,الدرجة,الخيار الأول,الخيار الثاني,الخيار الثالث,الخيار الرابع,رقم الإجابة الصحيحة (1-4),رابط الصورة (اختياري)\n";
      csvContent += "اختيار من متعدد,ما هي أول أسفار العهد القديم؟,25,التكوين,الخروج,اللاويين,العدد,1,https://example.com/genesis.jpg\n";
      csvContent += "صح وخطأ,ولد السيد المسيح في مدينة بيت لحم,25,صحيح,خطأ,,,1,\n";
      csvContent += "مقالي,اذكر آية تدل على المحبة والرحمة في الإنجيل,25,,,,,\n";

      var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
      var link = document.createElement("a");
      var url = URL.createObjectURL(blob);
      link.setAttribute("href", url);
      link.setAttribute("download", "نموذج_أسئلة_التاسك.csv");
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      if (typeof showToast === 'function') showToast('تم تحميل نموذج الشيت بنجاح!', 'ok');
    }

    function openBulkQModal() {
      var modal = document.getElementById('bulkQModal');
      if (modal) {
        modal.style.display = 'flex';
        window.parsedBulkQuestions = [];
        var fileInp = document.getElementById('bulkQFileInput');
        var pasteArea = document.getElementById('bulkQPasteArea');
        var fileName = document.getElementById('bulkQFileName');
        if (fileInp) fileInp.value = '';
        if (pasteArea) pasteArea.value = '';
        if (fileName) fileName.textContent = '';
        renderBulkQPreview();
      }
    }

    function closeBulkQModal() {
      var modal = document.getElementById('bulkQModal');
      if (modal) modal.style.display = 'none';
    }

    function handleBulkQFileUpload(event) {
      var file = event.target.files[0];
      if (!file) return;

      var nameEl = document.getElementById('bulkQFileName');
      if (nameEl) nameEl.textContent = '📄 ' + file.name;

      var ext = file.name.split('.').pop().toLowerCase();

      if (ext === 'xlsx' || ext === 'xls') {
        if (typeof XLSX === 'undefined') {
          if (typeof showToast === 'function') showToast('مكتبة قراءة Excel غير محملة. يرجى استخدام ملف CSV', 'err');
          return;
        }
        var reader = new FileReader();
        reader.onload = function(e) {
          try {
            var data = new Uint8Array(e.target.result);
            var workbook = XLSX.read(data, { type: 'array' });
            var firstSheetName = workbook.SheetNames[0];
            var worksheet = workbook.Sheets[firstSheetName];
            var jsonRows = XLSX.utils.sheet_to_json(worksheet, { header: 1 });
            processRowsToQuestions(jsonRows);
          } catch (ex) {
            if (typeof showToast === 'function') showToast('خطأ في قراءة ملف Excel: ' + ex.message, 'err');
          }
        };
        reader.readAsArrayBuffer(file);
      } else {
        var reader = new FileReader();
        reader.onload = function(e) {
          var text = e.target.result;
          var lines = text.split(/\r?\n/);
          var rows = lines.map(function(line) {
            return line.split(',').map(function(c) { return c.trim().replace(/^"|"$/g, ''); });
          });
          processRowsToQuestions(rows);
        };
        reader.readAsText(file);
      }
    }

    function parseBulkQPasteText() {
      var pasteArea = document.getElementById('bulkQPasteArea');
      if (!pasteArea) return;
      var text = pasteArea.value.trim();
      if (!text) {
        window.parsedBulkQuestions = [];
        renderBulkQPreview();
        return;
      }
      var lines = text.split(/\r?\n/);
      var rows = lines.map(function(line) {
        var sep = line.indexOf('\t') !== -1 ? '\t' : ',';
        return line.split(sep).map(function(c) { return c.trim().replace(/^"|"$/g, ''); });
      });
      processRowsToQuestions(rows);
    }

    function processRowsToQuestions(rows) {
      window.parsedBulkQuestions = [];
      if (!rows || rows.length === 0) {
        renderBulkQPreview();
        return;
      }

      var startIndex = 0;
      var firstRowStr = (rows[0] || []).join(' ').toLowerCase();
      if (firstRowStr.indexOf('نوع') !== -1 || firstRowStr.indexOf('سؤال') !== -1 || firstRowStr.indexOf('type') !== -1 || firstRowStr.indexOf('question') !== -1) {
        startIndex = 1;
      }

      for (var i = startIndex; i < rows.length; i++) {
        var row = rows[i];
        if (!row || row.length === 0 || (row.length === 1 && !row[0])) continue;

        var rawType = (row[0] || '').toString().trim().toLowerCase();
        var rawText = (row[1] || '').toString().trim();
        var rawDeg = parseInt(row[2]) || 25;
        var opt1 = (row[3] || '').toString().trim();
        var opt2 = (row[4] || '').toString().trim();
        var opt3 = (row[5] || '').toString().trim();
        var opt4 = (row[6] || '').toString().trim();
        var rawAns = (row[7] || '').toString().trim();
        var rawImg = (row[8] || '').toString().trim();

        if (!rawImg) {
          for (var colIdx = 7; colIdx < row.length; colIdx++) {
            var val = (row[colIdx] || '').toString().trim();
            if (val.indexOf('http://') === 0 || val.indexOf('https://') === 0 || val.indexOf('data:image/') === 0) {
              rawImg = val;
              break;
            }
          }
        }

        if (!rawText) continue;

        var qType = 'mcq';
        if (rawType.indexOf('صح') !== -1 || rawType.indexOf('tf') !== -1 || rawType.indexOf('خطأ') !== -1 || rawType.indexOf('true') !== -1) {
          qType = 'tf';
        } else if (rawType.indexOf('مقال') !== -1 || rawType.indexOf('open') !== -1 || rawType.indexOf('مفتوح') !== -1 || rawType.indexOf('essay') !== -1) {
          qType = 'open';
        }

        var isValid = true;
        var errorMsg = '';
        var options = [];
        var correctIndex = 0;

        if (qType === 'mcq') {
          if (opt1) options.push(opt1);
          if (opt2) options.push(opt2);
          if (opt3) options.push(opt3);
          if (opt4) options.push(opt4);

          if (options.length < 2) {
            isValid = false;
            errorMsg = 'يجب توفير خيارين على الأقل';
          } else {
            var ansNum = parseInt(rawAns);
            if (!isNaN(ansNum) && ansNum >= 1 && ansNum <= options.length) {
              correctIndex = ansNum - 1;
            } else if (!isNaN(ansNum) && ansNum >= 0 && ansNum < options.length) {
              correctIndex = ansNum;
            } else if (rawAns) {
              var matchedIdx = options.findIndex(function(o) { return o.toLowerCase() === rawAns.toLowerCase(); });
              if (matchedIdx !== -1) {
                correctIndex = matchedIdx;
              } else {
                correctIndex = 0;
              }
            } else {
              correctIndex = 0;
            }
          }
        } else if (qType === 'tf') {
          options = ['صحيح', 'خطأ'];
          var ansLower = rawAns.toLowerCase();
          if (ansLower === '2' || ansLower.indexOf('خطأ') !== -1 || ansLower === 'false' || ansLower === 'f') {
            correctIndex = 1;
          } else {
            correctIndex = 0;
          }
        } else if (qType === 'open') {
          options = [];
          correctIndex = null;
        }

        window.parsedBulkQuestions.push({
          question_type: qType,
          question_text: rawText,
          degree: rawDeg,
          options: options,
          correct_index: correctIndex,
          image_url: rawImg,
          valid: isValid,
          errorMsg: errorMsg
        });
      }

      renderBulkQPreview();
    }

    function renderBulkQPreview() {
      var tbody = document.getElementById('bulkQPreviewTbody');
      var badge = document.getElementById('bulkQCountBadge');
      var btn = document.getElementById('btnConfirmBulkQImport');
      if (!tbody) return;

      var qList = window.parsedBulkQuestions || [];
      var validCount = qList.filter(function(q) { return q.valid; }).length;

      if (badge) badge.textContent = validCount + ' أسئلة صالحة من أصل ' + qList.length;

      if (qList.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:24px; color:var(--t3);">قم برفع ملف أو لصق بيانات المعاينة لعرض الأسئلة هنا</td></tr>';
        if (btn) { btn.disabled = true; btn.style.opacity = '0.5'; }
        return;
      }

      var html = '';
      qList.forEach(function(q, i) {
        var typeBadge = '';
        if (q.question_type === 'mcq') typeBadge = '<span style="background:#e0e7ff; color:#4338ca; padding:2px 8px; border-radius:6px; font-weight:700; font-size:0.75rem;">اختيارات</span>';
        else if (q.question_type === 'tf') typeBadge = '<span style="background:#dcfce7; color:#15803d; padding:2px 8px; border-radius:6px; font-weight:700; font-size:0.75rem;">صح/خطأ</span>';
        else typeBadge = '<span style="background:#fef3c7; color:#b45309; padding:2px 8px; border-radius:6px; font-weight:700; font-size:0.75rem;">مقال</span>';

        var optsText = q.question_type === 'open' ? '—' : q.options.map(function(o, idx) {
          return (idx === q.correct_index ? '<b>✓ ' + esc(o) + '</b>' : esc(o));
        }).join(' | ');

        var imgCell = q.image_url
          ? '<a href="' + esc(q.image_url) + '" target="_blank" title="' + esc(q.image_url) + '" style="color:var(--brand); text-decoration:none; display:inline-flex; align-items:center; gap:4px; font-weight:700;"><i class="fas fa-image"></i> صورة</a>'
          : '<span style="color:var(--t3);">—</span>';

        var statusBadge = q.valid
          ? '<span style="color:#10b981; font-weight:700;"><i class="fas fa-check-circle"></i> صالح</span>'
          : '<span style="color:#ef4444; font-weight:700;" title="' + esc(q.errorMsg) + '"><i class="fas fa-exclamation-circle"></i> خطأ</span>';

        html += '<tr style="border-bottom:1px solid var(--bdr); ' + (!q.valid ? 'background:rgba(239,68,68,0.05);' : '') + '">' +
          '<td style="padding:8px 10px; text-align:center; font-weight:700;">' + (i + 1) + '</td>' +
          '<td style="padding:8px 10px;">' + typeBadge + '</td>' +
          '<td style="padding:8px 10px; font-weight:600; color:var(--t1);">' + esc(q.question_text) + '</td>' +
          '<td style="padding:8px 10px; color:var(--t2); font-size:0.75rem;">' + optsText + '</td>' +
          '<td style="padding:8px 10px; text-align:center;">' + imgCell + '</td>' +
          '<td style="padding:8px 10px; text-align:center; font-weight:700; color:var(--brand);">' + q.degree + '</td>' +
          '<td style="padding:8px 10px; text-align:center;">' + statusBadge + '</td>' +
          '</tr>';
      });

      tbody.innerHTML = html;

      if (btn) {
        if (validCount > 0) {
          btn.disabled = false;
          btn.style.opacity = '1';
        } else {
          btn.disabled = true;
          btn.style.opacity = '0.5';
        }
      }
    }

    function confirmImportTaskQuestions() {
      var validQuestions = (window.parsedBulkQuestions || []).filter(function(q) { return q.valid; });
      if (validQuestions.length === 0) {
        if (typeof showToast === 'function') showToast('لا توجد أسئلة صالحة للإضافة', 'err');
        return;
      }

      validQuestions.forEach(function(q) {
        if (typeof addQ === 'function') addQ(q);
      });

      if (typeof updDeg === 'function') updDeg();
      if (typeof showToast === 'function') showToast('تم إضافة ' + validQuestions.length + ' سؤال بنجاح!', 'ok');
      closeBulkQModal();
    }
  </script>
</body>

</html>