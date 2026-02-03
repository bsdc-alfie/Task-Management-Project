<?php
// tasks/calendar.php
session_start();

/* ✅ Prevent cached pages after logout (back button issue) */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

include __DIR__ . '/../database/db_connect.php';

// --- Require login ---
if (!isset($_SESSION['email'])) {
    header("Location: ../pages/login.php");
    exit();
}

$userEmail = $_SESSION['email'];

// --- Ensure tasks table exists (in case user lands on calendar first) ---
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

// --- Helpers ---
function clean($s) { return htmlspecialchars($s ?? "", ENT_QUOTES, 'UTF-8'); }

// --- Determine month to show (default: current month) ---
$now = new DateTime('now');

$y = isset($_GET['y']) ? (int)$_GET['y'] : (int)$now->format('Y');
$m = isset($_GET['m']) ? (int)$_GET['m'] : (int)$now->format('n');
if ($y < 1970 || $y > 2100) $y = (int)$now->format('Y');
if ($m < 1 || $m > 12) $m = (int)$now->format('n');

$monthStart = new DateTime(sprintf('%04d-%02d-01', $y, $m));
$monthEnd = (clone $monthStart)->modify('last day of this month');

// Week starts Monday (UK-friendly)
$firstDow = (int)$monthStart->format('N'); // 1=Mon..7=Sun
$gridStart = (clone $monthStart)->modify('-' . ($firstDow - 1) . ' days');
$gridEnd = (clone $gridStart)->modify('+41 days'); // 6 weeks grid

$today = new DateTime('today');
$todayStr = $today->format('Y-m-d');

// --- Fetch tasks for this user for the grid range ---
$tasksByDate = [];
$undatedTasks = [];

$stmt = $conn->prepare("
    SELECT id, title, description, due_date, status, priority
    FROM tasks
    WHERE user_email=? AND due_date IS NOT NULL AND due_date BETWEEN ? AND ?
    ORDER BY due_date ASC,
      CASE status WHEN 'Not Started' THEN 1 WHEN 'In Progress' THEN 2 ELSE 3 END,
      CASE priority WHEN 'High' THEN 1 WHEN 'Medium' THEN 2 ELSE 3 END,
      id DESC
");
$startStr = $gridStart->format('Y-m-d');
$endStr   = $gridEnd->format('Y-m-d');
$stmt->bind_param('sss', $userEmail, $startStr, $endStr);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $d = $row['due_date'];
    if (!isset($tasksByDate[$d])) $tasksByDate[$d] = [];
    $tasksByDate[$d][] = $row;
}
$stmt->close();

// Undated tasks (sidebar)
$stmt2 = $conn->prepare("
    SELECT id, title, status, priority
    FROM tasks
    WHERE user_email=? AND (due_date IS NULL OR due_date = '')
    ORDER BY
      CASE status WHEN 'Not Started' THEN 1 WHEN 'In Progress' THEN 2 ELSE 3 END,
      CASE priority WHEN 'High' THEN 1 WHEN 'Medium' THEN 2 ELSE 3 END,
      id DESC
");
$stmt2->bind_param('s', $userEmail);
$stmt2->execute();
$res2 = $stmt2->get_result();
while ($row = $res2->fetch_assoc()) $undatedTasks[] = $row;
$stmt2->close();

$conn->close();

// --- Navigation links ---
$prev = (clone $monthStart)->modify('-1 month');
$next = (clone $monthStart)->modify('+1 month');
$prevLink = 'calendar.php?y=' . $prev->format('Y') . '&m=' . $prev->format('n');
$nextLink = 'calendar.php?y=' . $next->format('Y') . '&m=' . $next->format('n');

$monthTitle = $monthStart->format('F Y');

function prio_class($p) {
    if ($p === 'High') return 'prio-high';
    if ($p === 'Low') return 'prio-low';
    return 'prio-med';
}

function status_badge($s) {
    if ($s === 'Done') return 'badge bg-success';
    if ($s === 'In Progress') return 'badge bg-primary';
    return 'badge bg-secondary';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Task Calendar</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>

  <style>
    :root{
      --card-bg: rgba(255,255,255,0.78);
      --card-border: rgba(255,255,255,0.55);
      --text: #111827;
      --muted: #6b7280;
      --shadow: 0 20px 60px rgba(0,0,0,0.18);
      --btn: #111827;
      --btn-text: #ffffff;
      --chip: rgba(17,24,39,0.08);

      --pri-low-bg: rgba(34,197,94,0.14);
      --pri-low-bd: rgba(34,197,94,0.35);
      --pri-low-tx: #0f5132;

      --pri-med-bg: rgba(245,158,11,0.16);
      --pri-med-bd: rgba(245,158,11,0.45);
      --pri-med-tx: #7a4a00;

      --pri-high-bg: rgba(239,68,68,0.16);
      --pri-high-bd: rgba(239,68,68,0.45);
      --pri-high-tx: #7f1d1d;

      --today-bg: rgba(59,130,246,0.12);
      --today-bd: rgba(59,130,246,0.45);
    }

    [data-theme="dark"]{
      --card-bg: rgba(17,24,39,0.72);
      --card-border: rgba(255,255,255,0.10);
      --text: #f9fafb;
      --muted: rgba(249,250,251,0.72);
      --shadow: 0 20px 60px rgba(0,0,0,0.55);
      --btn: #f9fafb;
      --btn-text: #111827;
      --chip: rgba(255,255,255,0.10);

      --pri-low-bg: rgba(34,197,94,0.18);
      --pri-low-bd: rgba(34,197,94,0.45);
      --pri-low-tx: #bbf7d0;

      --pri-med-bg: rgba(245,158,11,0.20);
      --pri-med-bd: rgba(245,158,11,0.55);
      --pri-med-tx: #fde68a;

      --pri-high-bg: rgba(239,68,68,0.20);
      --pri-high-bd: rgba(239,68,68,0.60);
      --pri-high-tx: #fecaca;

      --today-bg: rgba(59,130,246,0.16);
      --today-bd: rgba(59,130,246,0.55);
    }

    body{
      min-height:100vh;
      margin:0;
      color:var(--text);
      overflow-x:hidden;
      background: linear-gradient(120deg, rgb(50, 72, 117), #324875, #123c8f, #093969, #144577);
      background-size: 400% 400%;
      animation: bgMove 16s ease infinite;
    }
    @keyframes bgMove{ 0%{background-position:0% 50%;} 50%{background-position:100% 50%;} 100%{background-position:0% 50%;} }

    .page{ position:relative; z-index:1; padding: 18px 16px 40px; max-width: 1200px; margin: 0 auto; }
    .glass{
      background: var(--card-bg);
      border:1px solid var(--card-border);
      box-shadow: var(--shadow);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      border-radius: 16px;
    }

    .topbar{
      display:flex; align-items:center; justify-content:space-between;
      gap:12px; padding: 14px 14px;
      position: sticky; top: 14px; z-index: 5000;
    }

    .brand{ display:flex; align-items:center; gap:10px; font-weight:900; letter-spacing:-0.02em; }
    .brand-badge{
      width:36px; height:36px; border-radius:12px; display:grid; place-items:center;
      background: var(--chip); font-weight:900;
    }

    .btn-main{ border-radius: 12px; font-weight: 900; padding: 10px 12px; background: var(--btn); color: var(--btn-text); border:none; }
    .icon-btn{
      border:none; background: var(--card-bg); border:1px solid var(--card-border);
      border-radius: 999px; padding: 10px 12px; color: var(--text); font-weight: 900;
      box-shadow: 0 10px 25px rgba(0,0,0,0.12);
      backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
    }

    /* ✅ Homepage-style dropdown menus */
    .dropdown-menu{
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

    .dropdown-item{
      color: var(--text);
      font-weight: 700;
    }

    .dropdown-item:hover{
      background: var(--chip);
    }

    .hamburger-btn{
      font-size: 18px;
      line-height: 1;
      padding: 10px 14px;
    }

    .cal-head{
      display:flex; align-items:center; justify-content:space-between;
      padding: 14px 14px;
      border-bottom: 1px solid var(--card-border);
    }

    .chip{ display:inline-flex; align-items:center; gap:8px; background: var(--chip); padding: 8px 10px; border-radius: 999px; font-weight: 900; }

    .cal-grid{
      display:grid;
      grid-template-columns: repeat(7, 1fr);
      gap: 10px;
      padding: 14px;
    }

    .dow{
      text-align:center;
      font-weight: 1000;
      opacity: 0.9;
      padding: 8px 0;
      border-radius: 12px;
      border: 1px solid var(--card-border);
      background: var(--card-bg);
    }

    .day{
      min-height: 130px;
      border-radius: 16px;
      border: 1px solid var(--card-border);
      background: var(--card-bg);
      padding: 10px;
      display:flex;
      flex-direction:column;
      gap: 8px;
    }

    .day.outside{ opacity: 0.55; }
    .day.today{
      outline: 2px solid var(--today-bd);
      background: var(--today-bg);
    }

    .day-num{
      display:flex;
      align-items:center;
      justify-content:space-between;
      font-weight: 1000;
    }

    .task-pill{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:8px;
      border-radius: 12px;
      padding: 8px 10px;
      font-weight: 900;
      border: 1px solid var(--card-border);
      margin-bottom: 8px;
      cursor: default;
    }

    .prio-low  { background: var(--pri-low-bg) !important;  border-color: var(--pri-low-bd) !important;  color: var(--pri-low-tx) !important; }
    .prio-med  { background: var(--pri-med-bg) !important;  border-color: var(--pri-med-bd) !important;  color: var(--pri-med-tx) !important; }
    .prio-high { background: var(--pri-high-bg) !important; border-color: var(--pri-high-bd) !important; color: var(--pri-high-tx) !important; }

    .side-list{ padding: 14px; }
    .side-item{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding: 10px 12px; border-radius: 14px; border: 1px solid var(--card-border); background: var(--card-bg); margin-bottom: 10px; }

    a{ color: inherit; }

    /* ✅ Settings menu content (homepage-style; dropdown-menu handles glass look) */
    .settings-menu{
      min-width: 270px;
      padding: 0 !important;
      overflow: hidden;
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
  </style>
</head>
<body>
  <div class="page">
    <div class="glass topbar mb-3">
      <div class="brand">
        <div class="brand-badge">ST</div>
        <div>
          Task Calendar<br>
          <span class="muted" style="font-weight:700; font-size:12px;">Signed in as <?php echo clean($userEmail); ?></span>
        </div>
      </div>

      <div class="d-flex align-items-center gap-2">

        <!-- ✅ Settings dropdown: toggle only -->
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

        <!-- Menu dropdown -->
        <div class="dropdown">
          <button class="icon-btn dropdown-toggle hamburger-btn" data-bs-toggle="dropdown" aria-expanded="false" title="Menu">&#9776;</button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="../pages/homepage.php">Home</a></li>
            <li><a class="dropdown-item" href="../pages/profile.php">Profile</a></li>
            <li><a class="dropdown-item" href="../tasks/calendar.php">Calendar</a></li>
            <li><a class="dropdown-item" href="../tasks/index.php">Tasks</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="../pages/logout.php">Log out</a></li>
          </ul>
        </div>

      </div>
    </div>

    <div class="row g-3">
      <div class="col-12 col-lg-9">
        <div class="glass">
          <div class="cal-head">
            <div class="d-flex align-items-center gap-2">
              <a class="btn btn-outline-light" href="<?php echo clean($prevLink); ?>" style="border-radius:12px; font-weight:900; border:1px solid var(--card-border); background: var(--card-bg); color: var(--text);">←</a>
              <div style="font-weight: 1000; font-size: 20px; letter-spacing:-0.02em;">
                <?php echo clean($monthTitle); ?>
              </div>
              <a class="btn btn-outline-light" href="<?php echo clean($nextLink); ?>" style="border-radius:12px; font-weight:900; border:1px solid var(--card-border); background: var(--card-bg); color: var(--text);">→</a>
            </div>

            <div class="chip">
              <span class="muted" style="font-weight:900;">Today</span>
              <?php echo clean($today->format('D j M')); ?>
            </div>
          </div>

          <div class="cal-grid" style="padding-top: 0;">
            <?php
              $dows = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
              foreach ($dows as $d) {
                echo '<div class="dow">' . clean($d) . '</div>';
              }

              $cursor = clone $gridStart;
              for ($i = 0; $i < 42; $i++) {
                $dateStr = $cursor->format('Y-m-d');
                $dayNum = $cursor->format('j');
                $isOutside = ((int)$cursor->format('n') !== $m);
                $isToday = ($dateStr === $todayStr);

                $classes = 'day';
                if ($isOutside) $classes .= ' outside';
                if ($isToday) $classes .= ' today';

                echo '<div class="' . clean($classes) . '">';
                echo '  <div class="day-num">';
                echo '    <span>' . clean($dayNum) . '</span>';
                echo '    <span class="muted" style="font-weight:900; font-size:12px;">' . clean($cursor->format('D')) . '</span>';
                echo '  </div>';

                $dayTasks = $tasksByDate[$dateStr] ?? [];
                $maxShow = 3;
                $shown = 0;

                foreach ($dayTasks as $t) {
                  $shown++;
                  if ($shown > $maxShow) break;

                  $prio = $t['priority'] ?? 'Medium';
                  $status = $t['status'] ?? 'Not Started';

                  echo '<div class="task-pill ' . clean(prio_class($prio)) . '">';
                  echo '  <div style="min-width:0; flex:1;">';
                  echo '    <div style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">' . clean($t['title']) . '</div>';
                  if (!empty($t['description'])) {
                    echo '    <div class="muted" style="font-weight:800; font-size:12px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">' . clean($t['description']) . '</div>';
                  }
                  echo '  </div>';
                  echo '  <span class="' . clean(status_badge($status)) . '" style="border-radius:999px;">' . clean($status) . '</span>';
                  echo '</div>';
                }

                $extra = count($dayTasks) - $maxShow;
                if ($extra > 0) {
                  echo '<div class="muted" style="font-weight:900; font-size:12px;">+' . (int)$extra . ' more</div>';
                }

                echo '</div>';

                $cursor->modify('+1 day');
              }
            ?>
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-3">
        <div class="glass side-list">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <div style="font-weight:1000;">No due date</div>
            <div class="chip"><span class="muted" style="font-weight:900;">Count</span><?php echo (int)count($undatedTasks); ?></div>
          </div>

          <?php if (count($undatedTasks) === 0): ?>
            <div class="muted" style="font-weight:800;">All tasks have due dates 🎯</div>
          <?php else: ?>
            <?php foreach ($undatedTasks as $t): ?>
              <div class="side-item <?php echo clean(prio_class($t['priority'] ?? 'Medium')); ?>">
                <div style="min-width:0; flex:1;">
                  <div style="font-weight:1000; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo clean($t['title']); ?></div>
                  <div class="muted" style="font-weight:900; font-size:12px;"><?php echo clean($t['priority'] ?? 'Medium'); ?></div>
                </div>
                <span class="<?php echo clean(status_badge($t['status'] ?? 'Not Started')); ?>" style="border-radius:999px;"><?php echo clean($t['status'] ?? 'Not Started'); ?></span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>

          <hr style="border-color: var(--card-border);">

          <div style="font-weight:1000;" class="mb-2">Legend</div>
          <div class="d-grid" style="gap:10px;">
            <div class="task-pill prio-high" style="cursor: default; margin:0;">High priority</div>
            <div class="task-pill prio-med" style="cursor: default; margin:0;">Medium priority</div>
            <div class="task-pill prio-low" style="cursor: default; margin:0;">Low priority</div>
          </div>

        </div>
      </div>
    </div>

  </div>

<script>
  // Theme (shared with dashboard)
  (function () {
    const root = document.documentElement;
    const btn = document.getElementById('themeBtn');

    const saved = localStorage.getItem('theme');
    if (saved) root.setAttribute('data-theme', saved);
    else {
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
</script>
</body>
</html>
