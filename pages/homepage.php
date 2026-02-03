<?php
// pages/homepage.php
session_start();

$isLoggedIn = isset($_SESSION['email']);
$email = $isLoggedIn ? $_SESSION['email'] : '';

function clean($s) { return htmlspecialchars($s ?? "", ENT_QUOTES, 'UTF-8'); }

// ✅ CSRF token (still fine to keep, even if not used on homepage now)
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

// ✅ Toast messaging
$message = "";
$toastClass = "";

// ✅ Profile fields
$profileUsername = "";
$profileName = "";
$profilePhoto = "";   // userdata.profile_photo
$initials = "U";

// ✅ Dashboard data (logged-in only)
$dash = [
  'overdue' => 0,
  'due_today' => 0,
  'due_soon' => 0,
  'no_due' => 0,
  'total_open' => 0
];
$upcomingTasks = [];      // next 7 days tasks (not done)
$next7Counts = [];        // yyyy-mm-dd => count (not done)
$today = new DateTime('today');
$todayStr = $today->format('Y-m-d');
$soonLimit = (clone $today)->modify('+7 days');

// ✅ Fetch profile fields for display
if ($isLoggedIn) {
  include __DIR__ . '/../database/db_connect.php';

  $stmt = $conn->prepare("SELECT username, display_name, profile_photo FROM userdata WHERE email=? LIMIT 1");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  $res = $stmt->get_result();

  if ($row = $res->fetch_assoc()) {
    $profileUsername = $row['username'] ?? "";
    $profileName = $row['display_name'] ?? "";
    $profilePhoto = $row['profile_photo'] ?? "";
  }
  $stmt->close();

  // ✅ Ensure tasks table exists (safe for users who land on homepage first)
  $conn->query("
    CREATE TABLE IF NOT EXISTS tasks (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_email VARCHAR(255) NOT NULL,
      title VARCHAR(255) NOT NULL,
      description TEXT NULL,
      due_date DATE NULL,
      status ENUM('Not Started','In Progress','Done') NOT NULL DEFAULT 'Not Started',
      priority ENUM('Low','Medium','High') NOT NULL DEFAULT 'Medium',
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      INDEX(user_email),
      INDEX(due_date),
      INDEX(status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  // ✅ Dashboard stats (overdue / today / soon / no due / total open)
  $statsSql = "
    SELECT
      SUM(status <> 'Done' AND due_date IS NOT NULL AND due_date < CURDATE()) AS overdue,
      SUM(status <> 'Done' AND due_date = CURDATE()) AS due_today,
      SUM(status <> 'Done' AND due_date > CURDATE() AND due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)) AS due_soon,
      SUM(due_date IS NULL OR due_date = '') AS no_due,
      SUM(status <> 'Done') AS total_open
    FROM tasks
    WHERE user_email = ?
  ";
  $stmtS = $conn->prepare($statsSql);
  $stmtS->bind_param("s", $email);
  $stmtS->execute();
  $rS = $stmtS->get_result();
  if ($row = $rS->fetch_assoc()) {
    $dash['overdue'] = (int)($row['overdue'] ?? 0);
    $dash['due_today'] = (int)($row['due_today'] ?? 0);
    $dash['due_soon'] = (int)($row['due_soon'] ?? 0);
    $dash['no_due'] = (int)($row['no_due'] ?? 0);
    $dash['total_open'] = (int)($row['total_open'] ?? 0);
  }
  $stmtS->close();

  // ✅ Upcoming tasks list (next 7 days, not done)
  $stmtU = $conn->prepare("
    SELECT id, title, description, due_date, priority, status
    FROM tasks
    WHERE user_email = ?
      AND status <> 'Done'
      AND due_date IS NOT NULL
      AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY due_date ASC,
      CASE priority WHEN 'High' THEN 1 WHEN 'Medium' THEN 2 ELSE 3 END,
      id DESC
    LIMIT 6
  ");
  $stmtU->bind_param("s", $email);
  $stmtU->execute();
  $rU = $stmtU->get_result();
  while ($row = $rU->fetch_assoc()) $upcomingTasks[] = $row;
  $stmtU->close();

  // ✅ Next 7 days counts (mini calendar preview)
  $startStr = $today->format('Y-m-d');
  $endStr = $soonLimit->format('Y-m-d');
  $stmtC = $conn->prepare("
    SELECT due_date, COUNT(*) AS c
    FROM tasks
    WHERE user_email = ?
      AND status <> 'Done'
      AND due_date IS NOT NULL
      AND due_date BETWEEN ? AND ?
    GROUP BY due_date
  ");
  $stmtC->bind_param("sss", $email, $startStr, $endStr);
  $stmtC->execute();
  $rC = $stmtC->get_result();
  while ($row = $rC->fetch_assoc()) {
    $d = $row['due_date'];
    $next7Counts[$d] = (int)$row['c'];
  }
  $stmtC->close();

  $conn->close();

  // initials from display_name > username > email
  $src = trim($profileName) ?: (trim($profileUsername) ?: $email);
  $src = preg_replace('/[^a-zA-Z0-9\s]/', ' ', $src);
  $parts = array_values(array_filter(preg_split('/\s+/', $src)));

  if (count($parts) >= 2) {
    $initials = strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
  } else {
    $initials = strtoupper(substr($parts[0] ?? $src, 0, 2));
  }
  if ($initials === "") $initials = "U";
}

/**
 * ✅ Build profile image URL robustly
 * DB stores like: "../uploads/profile_photos/xxx.jpg"
 * homepage.php is /pages, so we need to convert that to a working URL.
 */
$profilePicUrl = "";
$profilePicExists = false;

if ($isLoggedIn && !empty($profilePhoto)) {

  $norm = trim($profilePhoto);

  // remove leading ../ (one or many)
  $norm = preg_replace('#^(\.\./)+#', '', $norm);

  // remove leading slash
  $norm = ltrim($norm, '/'); // now: uploads/profile_photos/xxx.jpg

  // absolute disk path
  $abs = __DIR__ . '/../' . $norm;

  if (file_exists($abs)) {
    $profilePicExists = true;

    // web URL from homepage.php
    $profilePicUrl = "../" . $norm . "?v=" . filemtime($abs);
  }
}

// helpers for dashboard labels
function prio_tag($p) {
  if ($p === 'High') return 'High';
  if ($p === 'Low') return 'Low';
  return 'Medium';
}
function due_human($ymd) {
  if (!$ymd) return "No due date";
  $dt = DateTime::createFromFormat('Y-m-d', $ymd);
  return $dt ? $dt->format('D j M') : "No due date";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Student Task Tracker</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>

  <style>
    :root {
      --card-bg: rgba(255,255,255,0.78);
      --card-border: rgba(255,255,255,0.55);
      --text: #111827;
      --muted: #6b7280;
      --input-bg: rgba(255,255,255,0.85);
      --input-border: rgba(17,24,39,0.12);
      --shadow: 0 20px 60px rgba(0,0,0,0.18);
      --btn: #111827;
      --btn-text: #ffffff;
      --link: #111827;
      --chip: rgba(17,24,39,0.08);
    }

    [data-theme="dark"] {
      --card-bg: rgba(17,24,39,0.72);
      --card-border: rgba(255,255,255,0.10);
      --text: #f9fafb;
      --muted: rgba(249,250,251,0.74);
      --input-bg: rgba(17,24,39,0.55);
      --input-border: rgba(255,255,255,0.14);
      --shadow: 0 20px 60px rgba(0,0,0,0.55);
      --btn: #f9fafb;
      --btn-text: #111827;
      --link: #f9fafb;
      --chip: rgba(255,255,255,0.10);
    }

    html { scroll-behavior: smooth; }

    body {
      min-height: 100vh;
      margin: 0;
      color: var(--text);
      overflow-x: hidden;

      background: linear-gradient(120deg,
        rgb(50, 72, 117),
        #324875,
        #123c8f,
        #093969,
        #144577
      );
      background-size: 400% 400%;
      animation: bgMove 16s ease infinite;
    }

    @keyframes bgMove {
      0% { background-position: 0% 50%; }
      50% { background-position: 100% 50%; }
      100% { background-position: 0% 50%; }
    }

    .bg-blobs {
      position: fixed;
      inset: 0;
      pointer-events: none;
      z-index: 0;
      filter: blur(48px);
      opacity: 0.75;
    }

    .blob {
      position: absolute;
      width: 420px;
      height: 420px;
      border-radius: 999px;
      background: radial-gradient(circle at 30% 30%, #324875 10%, #e5e7eb 45%, rgba(229,231,235,0) 70%);
      animation: floaty 18s ease-in-out infinite;
      mix-blend-mode: soft-light;
    }

    .blob.b1 { top: -120px; left: -120px; animation-duration: 20s; }
    .blob.b2 { bottom: -150px; right: -140px; animation-duration: 22s; }
    .blob.b3 { top: 20%; right: -180px; width: 520px; height: 520px; animation-duration: 26s; }
    .blob.b4 { bottom: 18%; left: -180px; width: 520px; height: 520px; animation-duration: 24s; }

    @keyframes floaty {
      0%, 100% { transform: translate(0,0) scale(1); }
      50% { transform: translate(40px, -30px) scale(1.08); }
    }

    .noise {
      position: fixed;
      inset: 0;
      pointer-events: none;
      z-index: 0;
      opacity: 0.08;
      background-image:
        url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.8' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='120' height='120' filter='url(%23n)' opacity='0.35'/%3E%3C/svg%3E");
    }

    .page {
      position: relative;
      z-index: 1;
      padding: 18px 16px 60px;
      max-width: 1120px;
      margin: 0 auto;
    }

    .glass {
      background: var(--card-bg);
      border: 1px solid var(--card-border);
      box-shadow: var(--shadow);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      border-radius: 16px;
    }

    .topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 14px 14px;
      position: sticky;
      top: 14px;
      z-index: 5000;
      overflow: visible !important;
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 12px;
      font-weight: 900;
      letter-spacing: -0.02em;
    }

    .avatar {
      width: 40px;
      height: 40px;
      border-radius: 14px;
      display: grid;
      place-items: center;
      background: var(--chip);
      border: 1px solid var(--card-border);
      font-weight: 950;
      overflow: hidden;
      flex: 0 0 auto;
    }
    .avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .brand small {
      display: block;
      color: var(--muted);
      font-weight: 700;
      font-size: 12px;
    }

    .right-actions {
      display: flex;
      align-items: center;
      gap: 10px;
      overflow: visible !important;
    }

    .icon-btn {
      border: none;
      background: var(--card-bg);
      border: 1px solid var(--card-border);
      border-radius: 999px;
      padding: 10px 12px;
      color: var(--text);
      font-weight: 900;
      box-shadow: 0 10px 25px rgba(0,0,0,0.12);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
    }

    .hamburger-btn{
      font-size: 18px;
      line-height: 1;
      padding: 10px 14px;
    }

    .btn-main {
      border-radius: 12px;
      font-weight: 900;
      padding: 10px 14px;
      background: var(--btn);
      color: var(--btn-text);
      border: none;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      white-space: nowrap;
    }

    .btn-ghost {
      border-radius: 12px;
      font-weight: 900;
      padding: 10px 14px;
      background: transparent;
      color: var(--text);
      border: 1px solid var(--card-border);
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      white-space: nowrap;
    }

    .btn-main:hover, .btn-ghost:hover { filter: brightness(0.95); }

    .dropdown-menu {
      border-radius: 14px;
      border: 1px solid var(--card-border);
      background: var(--card-bg);
      box-shadow: var(--shadow);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      z-index: 9999 !important;
      transform: translateY(6px);
      animation: dropdownFade 0.15s ease-out;
    }

    @keyframes dropdownFade {
      from { opacity: 0; transform: translateY(0); }
      to   { opacity: 1; transform: translateY(6px); }
    }

    .dropdown-item { color: var(--text); font-weight: 700; }
    .dropdown-item:hover { background: var(--chip); }

    /* ✅ Settings dropdown - toggle only + stronger headings */
    .settings-menu{
      min-width: 270px;
      border-radius: 16px;
      border: 1px solid var(--card-border);
      background: var(--card-bg);
      box-shadow: var(--shadow);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      overflow: hidden;
      padding: 0 !important;
    }

    .settings-title{
      padding: 14px 14px 10px;
      font-weight: 1000;
      font-size: 16px;
      letter-spacing: 0.06em;
      color: var(--text);
      text-transform: uppercase;
    }

    .settings-divider{
      height: 1px;
      background: var(--card-border);
      margin: 0;
    }

    .settings-row{
      padding: 12px 14px 14px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 10px;
    }

    .settings-label{
      font-weight: 1000;
      font-size: 14px;
      letter-spacing: 0.05em;
      color: var(--text);
      text-transform: uppercase;
    }

    .toast-wrap{
      position: fixed;
      top: 18px;
      left: 18px;
      z-index: 20000;
      width: 420px;
      max-width: calc(100% - 36px);
    }

    /* keep your original hero/sections css (unchanged) */
    .hero { margin-top: 16px; padding: 26px; position: relative; }
    .hero h1 { font-weight: 950; letter-spacing: -0.03em; line-height: 1.05; margin: 0 0 10px; font-size: clamp(28px, 4.2vw, 44px); }
    .hero p { margin: 0; color: var(--muted); font-weight: 700; font-size: 15px; max-width: 62ch; }
    .hero-cta { margin-top: 18px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
    .hero-note { margin-top: 10px; color: var(--muted); font-weight: 700; font-size: 12px; }
    .scroll-arrow { margin-top: 16px; display: inline-flex; align-items: center; gap: 10px; text-decoration: none; color: var(--text); font-weight: 900; background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 999px; padding: 10px 14px; box-shadow: 0 10px 25px rgba(0,0,0,0.12); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); }
    .scroll-arrow:hover { filter: brightness(0.95); }
    .scroll-bubble { width: 34px; height: 34px; border-radius: 999px; display: grid; place-items: center; background: var(--chip); border: 1px solid var(--card-border); font-size: 16px; animation: bounce 1.4s ease-in-out infinite; }
    @keyframes bounce { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(4px); } }
    .grid { margin-top: 14px; display: grid; gap: 14px; grid-template-columns: 1.1fr 0.9fr; }
    @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }
    .card-pad { padding: 18px; }
    .kicker { display: inline-flex; align-items: center; gap: 8px; background: var(--chip); padding: 8px 10px; border-radius: 999px; font-weight: 900; margin-bottom: 10px; }
    .preview { padding: 18px; }
    .fake-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
    .fake-pill { background: var(--chip); border: 1px solid var(--card-border); padding: 8px 10px; border-radius: 999px; font-weight: 900; font-size: 12px; }
    .fake-task { border: 1px solid var(--card-border); background: rgba(255,255,255,0.12); border-radius: 14px; padding: 12px; margin-bottom: 10px; }
    [data-theme="dark"] .fake-task { background: rgba(17,24,39,0.35); }
    .fake-task h6 { margin: 0 0 6px; font-weight: 950; letter-spacing: -0.01em; }
    .muted { color: var(--muted); }
    .tag { display: inline-flex; align-items: center; gap: 8px; padding: 7px 9px; border-radius: 999px; font-weight: 900; background: var(--chip); border: 1px solid var(--card-border); font-size: 12px; }
    .section { margin-top: 14px; padding: 18px; scroll-margin-top: 96px; }
    .section h3 { font-weight: 950; margin: 0 0 10px; letter-spacing: -0.02em; }
    .features { display: grid; gap: 12px; grid-template-columns: repeat(3, 1fr); }
    @media (max-width: 900px) { .features { grid-template-columns: 1fr; } }
    .feature { padding: 16px; border-radius: 16px; border: 1px solid var(--card-border); background: rgba(255,255,255,0.12); }
    [data-theme="dark"] .feature { background: rgba(17,24,39,0.35); }
    .feature h5 { margin: 0 0 6px; font-weight: 950; }
    .footer { margin-top: 14px; padding: 16px 18px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; color: var(--muted); font-weight: 800; font-size: 13px; }

    /* ✅ NEW: logged-in dashboard widgets */
    .stat-grid{
      display:grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 10px;
      margin-bottom: 12px;
    }
    @media (max-width: 900px){
      .stat-grid{ grid-template-columns: repeat(2, 1fr); }
    }
    .stat-card{
      border: 1px solid var(--card-border);
      border-radius: 16px;
      padding: 12px;
      background: rgba(255,255,255,0.12);
    }
    [data-theme="dark"] .stat-card{ background: rgba(17,24,39,0.35); }
    .stat-top{
      display:flex; align-items:center; justify-content:space-between; gap: 10px;
      font-weight: 950;
      letter-spacing: -0.01em;
    }
    .stat-num{
      font-size: 26px;
      font-weight: 1000;
      line-height: 1;
      margin-top: 6px;
    }
    .stat-sub{
      margin-top: 6px;
      font-weight: 800;
      color: var(--muted);
      font-size: 12px;
    }
    .mini-list{
      display:grid;
      gap: 10px;
    }
    .mini-item{
      border: 1px solid var(--card-border);
      border-radius: 14px;
      padding: 12px;
      background: rgba(255,255,255,0.12);
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap: 10px;
    }
    [data-theme="dark"] .mini-item{ background: rgba(17,24,39,0.35); }
    .mini-title{ font-weight: 950; margin: 0; letter-spacing: -0.01em; }
    .mini-desc{ margin-top: 4px; font-weight: 800; color: var(--muted); font-size: 12px; }
    .mini-right{ display:flex; flex-direction:column; align-items:flex-end; gap: 8px; white-space:nowrap; }
    .pill-link{
      display:inline-flex; align-items:center; gap: 8px;
      padding: 8px 10px;
      border-radius: 999px;
      font-weight: 900;
      background: var(--chip);
      border: 1px solid var(--card-border);
      text-decoration:none;
      color: var(--text);
    }
    .pill-link:hover{ filter: brightness(0.95); }

    .tiny{
      font-weight: 900;
      font-size: 12px;
      padding: 6px 9px;
      border-radius: 999px;
      background: var(--chip);
      border: 1px solid var(--card-border);
    }
  </style>
</head>

<body>
  <div class="bg-blobs" aria-hidden="true">
    <div class="blob b1"></div>
    <div class="blob b2"></div>
    <div class="blob b3"></div>
    <div class="blob b4"></div>
  </div>
  <div class="noise" aria-hidden="true"></div>

  <?php if ($message): ?>
    <div class="toast-wrap">
      <div class="toast align-items-center text-white <?php echo $toastClass; ?> border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
          <div class="toast-body"><?php echo clean($message); ?></div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="page">

    <!-- Topbar -->
    <div class="glass topbar">
      <div class="brand">
        <?php if (!$isLoggedIn): ?>
          <img src="../logo.png" alt="Logo" style="width:36px; height:36px; border-radius:12px; border:1px solid var(--card-border);">
          <div>
            Student Task Tracker
            <small>Plan, track, and finish your school work</small>
          </div>
        <?php else: ?>
          <!-- ✅ TOP LEFT PROFILE (avatar + name + username) -->
          <a href="profile.php" style="text-decoration:none; color:inherit; display:flex; align-items:center; gap:12px;">
            <div class="avatar">
              <?php if ($profilePicExists && !empty($profilePicUrl)): ?>
                <img src="<?php echo clean($profilePicUrl); ?>" alt="Profile picture">
              <?php else: ?>
                <?php echo clean($initials); ?>
              <?php endif; ?>
            </div>
            <div>
              <?php echo clean($profileName ?: "Your profile"); ?>
              <small>
                <?php echo $profileUsername ? ("@" . clean($profileUsername) . " • ") : ""; ?>
                <?php echo clean($email); ?>
              </small>
            </div>
          </a>
        <?php endif; ?>
      </div>

      <div class="right-actions">
        <?php if (!$isLoggedIn): ?>
          <a class="btn-ghost" href="login.php">Login</a>
          <a class="btn-main" href="register.php">Create Account</a>
        <?php else: ?>

          <!-- ✅ Settings dropdown -->
          <div class="dropdown">
            <button class="icon-btn dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Settings">⚙</button>
            <ul class="dropdown-menu dropdown-menu-end settings-menu">
              <li class="settings-title">Settings</li>
              <li><div class="settings-divider"></div></li>
              <li class="settings-row">
                <span class="settings-label">App Appearance</span>
                <button class="btn btn-sm btn-main" type="button" id="themeBtn">Toggle</button>
              </li>
            </ul>
          </div>

          <!-- 3-bar menu -->
          <div class="dropdown">
            <button class="icon-btn dropdown-toggle hamburger-btn" data-bs-toggle="dropdown" aria-expanded="false" title="Menu">
              &#9776;
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="homepage.php">Home</a></li>
              <li><a class="dropdown-item" href="profile.php">Profile</a></li>
              <li><a class="dropdown-item" href="../tasks/calendar.php">Calendar</a></li>
              <li><a class="dropdown-item" href="../tasks/index.php#tasks">Tasks</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="logout.php">Log out</a></li>
            </ul>
          </div>

        <?php endif; ?>
      </div>
    </div>

    <!-- Hero -->
    <div class="glass hero">
      <div class="kicker">📚 Built for students</div>
      <h1>Track assignments, stay organised, and hit deadlines.</h1>
      <p>
        Student Task Tracker helps you plan school work in one place: add tasks, set due dates, update progress,
        and keep your workload under control — without forgetting what’s due next.
      </p>

      <div class="hero-cta">
        <?php if (!$isLoggedIn): ?>
          <a class="btn-main" href="register.php">Create a free account</a>
          <a class="btn-ghost" href="login.php">I already have an account</a>
        <?php else: ?>
          <a class="btn-main" href="../tasks/index.php">Open my task dashboard</a>
          <a class="btn-ghost" href="../tasks/calendar.php">Open my calendar</a>
        <?php endif; ?>
      </div>

      <div class="hero-note">
        ✅ Add • ✅ Edit • ✅ Delete • ✅ Track progress — all in one simple dashboard.
      </div>

      <a class="scroll-arrow" href="#features" id="scrollToFeatures">
        <span class="scroll-bubble">↓</span>
        Scroll to features
      </a>
    </div>

    <!-- Main grid: preview + highlights -->
    <div class="grid" id="preview">

      <?php if (!$isLoggedIn): ?>
        <!-- ✅ NOT LOGGED IN VERSION (UNCHANGED) -->
        <div class="glass preview">
          <div class="fake-top">
            <div style="font-weight:950; letter-spacing:-0.02em;">App Preview</div>
            <div class="fake-pill">Today: 3 tasks • Due soon: 1</div>
          </div>

          <div class="fake-task">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <h6>English Essay — draft intro</h6>
              <span class="tag">High</span>
            </div>
            <div class="muted" style="font-weight:800; font-size:13px;">
              Write a strong opening paragraph and outline the main points.
            </div>
            <div class="d-flex gap-2 mt-2 flex-wrap">
              <span class="tag">Due: Fri</span>
              <span class="tag">Status: In Progress</span>
            </div>
          </div>

          <div class="fake-task">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <h6>Maths Homework — Chapter 7</h6>
              <span class="tag">Medium</span>
            </div>
            <div class="muted" style="font-weight:800; font-size:13px;">
              Complete questions 1–12 and check answers.
            </div>
            <div class="d-flex gap-2 mt-2 flex-wrap">
              <span class="tag">Due: Mon</span>
              <span class="tag">Status: Not Started</span>
            </div>
          </div>

          <div class="fake-task" style="margin-bottom:0;">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <h6>Science Revision — Photosynthesis</h6>
              <span class="tag">Low</span>
            </div>
            <div class="muted" style="font-weight:800; font-size:13px;">
              Review notes and make 10 flashcards.
            </div>
            <div class="d-flex gap-2 mt-2 flex-wrap">
              <span class="tag">Due: —</span>
              <span class="tag">Status: Done</span>
            </div>
          </div>
        </div>

        <div class="glass card-pad">
          <div class="kicker">✨ What you get</div>
          <h3 style="font-weight:950; letter-spacing:-0.02em; margin:0 0 10px;">Everything you need to stay on top.</h3>
          <div class="muted" style="font-weight:800; margin-bottom:12px;">
            Keep your school work organised with simple tools that actually get used.
          </div>

          <div class="d-grid" style="gap:10px;">
            <div class="feature">
              <h5>Task Management</h5>
              <div class="muted" style="font-weight:800;">Add, edit, delete tasks — with due dates and priorities.</div>
            </div>
            <div class="feature">
              <h5>Progress Tracking</h5>
              <div class="muted" style="font-weight:800;">Set status: Not Started, In Progress, Done.</div>
            </div>
            <div class="feature">
              <h5>Clean UI + Dark Mode</h5>
              <div class="muted" style="font-weight:800;">Switch appearance instantly using the settings toggle.</div>
            </div>
          </div>
        </div>

      <?php else: ?>
        <!-- ✅ LOGGED IN VERSION (REAL DATA DASHBOARD) -->

        <div class="glass preview">
          <div class="fake-top">
            <div style="font-weight:950; letter-spacing:-0.02em;">Your week overview</div>
            <div class="fake-pill">Open tasks: <?php echo (int)$dash['total_open']; ?></div>
          </div>

          <!-- Stats -->
          <div class="stat-grid">
            <a class="stat-card" href="../tasks/index.php#tasks" style="text-decoration:none; color:inherit;">
              <div class="stat-top">
                <span>Overdue</span>
                <span class="tiny">⚠</span>
              </div>
              <div class="stat-num"><?php echo (int)$dash['overdue']; ?></div>
              <div class="stat-sub">Tasks past the due date</div>
            </a>

            <a class="stat-card" href="../tasks/index.php#tasks" style="text-decoration:none; color:inherit;">
              <div class="stat-top">
                <span>Due today</span>
                <span class="tiny">📌</span>
              </div>
              <div class="stat-num"><?php echo (int)$dash['due_today']; ?></div>
              <div class="stat-sub"><?php echo clean($today->format('D j M')); ?></div>
            </a>

            <a class="stat-card" href="../tasks/calendar.php" style="text-decoration:none; color:inherit;">
              <div class="stat-top">
                <span>Next 7 days</span>
                <span class="tiny">📅</span>
              </div>
              <div class="stat-num"><?php echo (int)$dash['due_soon']; ?></div>
              <div class="stat-sub">Coming up soon</div>
            </a>

            <a class="stat-card" href="../tasks/index.php#tasks" style="text-decoration:none; color:inherit;">
              <div class="stat-top">
                <span>No due date</span>
                <span class="tiny">—</span>
              </div>
              <div class="stat-num"><?php echo (int)$dash['no_due']; ?></div>
              <div class="stat-sub">Set dates when you can</div>
            </a>
          </div>

          <!-- Upcoming list -->
          <div class="d-flex align-items-center justify-content-between mb-2">
            <div style="font-weight:950; letter-spacing:-0.01em;">Upcoming tasks</div>
            <a class="pill-link" href="../tasks/index.php">Open tasks →</a>
          </div>

          <?php if (count($upcomingTasks) === 0): ?>
            <div class="muted" style="font-weight:800;">
              Nothing due in the next 7 days 🎯
            </div>
          <?php else: ?>
            <div class="mini-list">
              <?php foreach ($upcomingTasks as $t): ?>
                <?php
                  $p = $t['priority'] ?? 'Medium';
                  $d = $t['due_date'] ?? '';
                  $dueText = ($d === $todayStr) ? "Today" : due_human($d);
                ?>
                <div class="mini-item">
                  <div style="min-width:0;">
                    <div class="mini-title" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                      <?php echo clean($t['title']); ?>
                    </div>
                    <?php if (!empty($t['description'])): ?>
                      <div class="mini-desc" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                        <?php echo clean($t['description']); ?>
                      </div>
                    <?php endif; ?>
                  </div>
                  <div class="mini-right">
                    <span class="tiny"><?php echo clean($dueText); ?></span>
                    <span class="tiny"><?php echo clean(prio_tag($p)); ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="glass card-pad">
          <div class="kicker">🚀 Quick access</div>
          <h3 style="font-weight:950; letter-spacing:-0.02em; margin:0 0 10px;">Jump back in.</h3>
          <div class="muted" style="font-weight:800; margin-bottom:12px;">
            Open your tasks and calendar instantly, and see what’s coming up this week.
          </div>

          <div class="d-grid" style="gap:10px; margin-bottom: 12px;">
            <a class="btn-main" href="../tasks/index.php">Open Tasks</a>
            <a class="btn-ghost" href="../tasks/calendar.php">Open Calendar</a>
          </div>

          <div class="feature">
            <h5 style="margin:0 0 6px; font-weight:950;">Mini calendar (next 7 days)</h5>
            <div class="muted" style="font-weight:800; margin-bottom:10px;">
              Counts show open tasks due on each day.
            </div>

            <div class="d-grid" style="gap:10px;">
              <?php
                $cursor = new DateTime('today');
                for ($i = 0; $i < 7; $i++) {
                  $d = $cursor->format('Y-m-d');
                  $count = $next7Counts[$d] ?? 0;
                  $label = ($d === $todayStr) ? "Today" : $cursor->format('D j M');
                  $monthLink = "../tasks/calendar.php?y=" . $cursor->format('Y') . "&m=" . $cursor->format('n');

                  echo '<a class="pill-link" href="' . clean($monthLink) . '" style="justify-content:space-between;">';
                  echo '  <span style="font-weight:950;">' . clean($label) . '</span>';
                  echo '  <span class="tiny">' . (int)$count . '</span>';
                  echo '</a>';

                  $cursor->modify('+1 day');
                }
              ?>
            </div>
          </div>
        </div>

      <?php endif; ?>
    </div>

    <div class="glass section" id="features">
      <h3>Features made for students</h3>
      <div class="features">
        <div class="feature">
          <h5>Due dates & deadlines</h5>
          <div class="muted" style="font-weight:800;">Always know what’s due next and plan your week.</div>
        </div>
        <div class="feature">
          <h5>Priority levels</h5>
          <div class="muted" style="font-weight:800;">Focus on what matters first — high, medium, low.</div>
        </div>
        <div class="feature">
          <h5>Quick edits</h5>
          <div class="muted" style="font-weight:800;">Update tasks fast as your homework changes.</div>
        </div>
      </div>
    </div>

    <div class="glass section" id="how">
      <h3>How it helps</h3>
      <div class="features">
        <div class="feature">
          <h5>Less stress</h5>
          <div class="muted" style="font-weight:800;">Everything is in one place, so nothing is forgotten.</div>
        </div>
        <div class="feature">
          <h5>Better time management</h5>
          <div class="muted" style="font-weight:800;">Break down big assignments and track progress.</div>
        </div>
        <div class="feature">
          <h5>Higher grades</h5>
          <div class="muted" style="font-weight:800;">Consistent planning beats last-minute panic.</div>
        </div>
      </div>
    </div>

    <div class="glass footer">
      <div>© <?php echo date('Y'); ?> Student Task Tracker</div>
      <div class="d-flex gap-3 flex-wrap">
        <?php if (!$isLoggedIn): ?>
          <a href="login.php" style="color:var(--link); text-decoration:none;">Login</a>
          <a href="register.php" style="color:var(--link); text-decoration:none;">Create Account</a>
          <a href="reset_password.php" style="color:var(--link); text-decoration:none;">Reset Password</a>
        <?php else: ?>
          <a href="../tasks/index.php" style="color:var(--link); text-decoration:none;">Open App</a>
          <a href="profile.php" style="color:var(--link); text-decoration:none;">Profile</a>
          <a href="reset_password.php" style="color:var(--link); text-decoration:none;">Change Password</a>
          <a href="logout.php" style="color:var(--link); text-decoration:none;">Log out</a>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <script>
    // Theme: auto-detect + manual toggle + save
    (function () {
      const root = document.documentElement;
      const btn = document.getElementById('themeBtn');

      const saved = localStorage.getItem('theme');
      if (saved) {
        root.setAttribute('data-theme', saved);
      } else {
        const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        root.setAttribute('data-theme', prefersDark ? 'dark' : 'light');
      }

      btn?.addEventListener('click', () => {
        const current = root.getAttribute('data-theme') || 'light';
        const next = current === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        localStorage.setItem('theme', next);
      });
    })();

    // Toast show (Bootstrap)
    (function () {
      const toastElList = [].slice.call(document.querySelectorAll('.toast'));
      const toastList = toastElList.map(t => new bootstrap.Toast(t, { delay: 3000 }));
      toastList.forEach(t => t.show());
    })();

    // Click-outside-to-close dropdowns (extra-safe)
    document.addEventListener('click', (e) => {
      document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
        if (!menu.parentElement.contains(e.target)) {
          const toggle = menu.parentElement.querySelector('[data-bs-toggle="dropdown"]');
          if (toggle) bootstrap.Dropdown.getOrCreateInstance(toggle).hide();
        }
      });
    });

    // Smooth scroll offset for sticky bar
    document.getElementById('scrollToFeatures')?.addEventListener('click', function (e) {
      const target = document.getElementById('features');
      if (!target) return;
      e.preventDefault();
      const y = target.getBoundingClientRect().top + window.pageYOffset - 96;
      window.scrollTo({ top: y, behavior: 'smooth' });
    });
  </script>
</body>
</html>
