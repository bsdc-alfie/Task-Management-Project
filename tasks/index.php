<?php
// tasks/index.php
session_start();

/* ✅ Prevent cached dashboard after logout (back button issue) */
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

// --- CSRF token ---
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

// --- Create tasks table if it doesn't exist ---
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

// --- Toast messaging ---
$message = "";
$toastClass = "";

// --- Helpers ---
function clean($s) { return htmlspecialchars($s ?? "", ENT_QUOTES, 'UTF-8'); }
function require_csrf() {
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        http_response_code(403);
        die("Invalid CSRF token");
    }
}

// --- Handle actions: add/edit/delete ---
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $due_date = $_POST['due_date'] ?? null;

        // ✅ NEW: if user leaves date blank, store NULL
        if ($due_date === '' || $due_date === null) $due_date = null;

        $status = $_POST['status'] ?? 'Not Started';
        $priority = $_POST['priority'] ?? 'Medium';

        if ($title === '') {
            $message = "Title is required.";
            $toastClass = "bg-warning";
        } else {
            $stmt = $conn->prepare("INSERT INTO tasks (user_email, title, description, due_date, status, priority) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $userEmail, $title, $description, $due_date, $status, $priority);

            if ($stmt->execute()) {
                $message = "Task added successfully.";
                $toastClass = "bg-success";
            } else {
                $message = "Error adding task.";
                $toastClass = "bg-danger";
            }
            $stmt->close();
        }
    }

    if ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $due_date = $_POST['due_date'] ?? null;

        // ✅ NEW: if user clears date, store NULL
        if ($due_date === '' || $due_date === null) $due_date = null;

        $status = $_POST['status'] ?? 'Not Started';
        $priority = $_POST['priority'] ?? 'Medium';

        if ($id <= 0 || $title === '') {
            $message = "Invalid task update.";
            $toastClass = "bg-warning";
        } else {
            $stmt = $conn->prepare("UPDATE tasks SET title=?, description=?, due_date=?, status=?, priority=? WHERE id=? AND user_email=?");
            $stmt->bind_param("sssssis", $title, $description, $due_date, $status, $priority, $id, $userEmail);

            if ($stmt->execute()) {
                $message = "Task updated successfully.";
                $toastClass = "bg-success";
            } else {
                $message = "Error updating task.";
                $toastClass = "bg-danger";
            }
            $stmt->close();
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $message = "Invalid task delete.";
            $toastClass = "bg-warning";
        } else {
            $stmt = $conn->prepare("DELETE FROM tasks WHERE id=? AND user_email=?");
            $stmt->bind_param("is", $id, $userEmail);

            if ($stmt->execute()) {
                $message = "Task deleted.";
                $toastClass = "bg-success";
            } else {
                $message = "Error deleting task.";
                $toastClass = "bg-danger";
            }
            $stmt->close();
        }
    }
}

// --- Fetch tasks for this user ---
$tasks = [];
$stmt = $conn->prepare("
    SELECT id, title, description, due_date, status, priority, created_at
    FROM tasks
    WHERE user_email=?
    ORDER BY
      CASE status WHEN 'Not Started' THEN 1 WHEN 'In Progress' THEN 2 ELSE 3 END,
      (due_date IS NULL), due_date ASC, created_at DESC
");
$stmt->bind_param("s", $userEmail);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $tasks[] = $row;
$stmt->close();

// --- Stats + date flags ---
$total = count($tasks);
$done = 0; $inprog = 0; $notstart = 0;
$overdueCount = 0; $dueTodayCount = 0; $dueSoonCount = 0;

$today = new DateTime('today');
$todayStr = $today->format('Y-m-d');
$soonLimit = (clone $today)->modify('+3 days');

foreach ($tasks as $t) {
    if ($t['status'] === 'Done') $done++;
    elseif ($t['status'] === 'In Progress') $inprog++;
    else $notstart++;

    if (!empty($t['due_date'])) {
        $due = DateTime::createFromFormat('Y-m-d', $t['due_date']);
        if ($due && $t['status'] !== 'Done') {
            if ($due < $today) $overdueCount++;
            if ($t['due_date'] === $todayStr) $dueTodayCount++;
            if ($due > $today && $due <= $soonLimit) $dueSoonCount++;
        }
    }
}

$progressPct = ($total > 0) ? (int)round(($done / $total) * 100) : 0;

$conn->close();
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
  /* ✅ Homepage-style settings dropdown */
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

  :root{
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

    --pri-low-bg: rgba(34,197,94,0.14);
    --pri-low-bd: rgba(34,197,94,0.35);
    --pri-low-tx: #0f5132;

    --pri-med-bg: rgba(245,158,11,0.16);
    --pri-med-bd: rgba(245,158,11,0.45);
    --pri-med-tx: #7a4a00;

    --pri-high-bg: rgba(239,68,68,0.16);
    --pri-high-bd: rgba(239,68,68,0.45);
    --pri-high-tx: #7f1d1d;

    --overdue-bg: rgba(239,68,68,0.10);
    --overdue-bd: rgba(239,68,68,0.40);

    --today-bg: rgba(59,130,246,0.12);
    --today-bd: rgba(59,130,246,0.45);
    --today-tx: #0b3b8a;

    --soon-bg: rgba(168,85,247,0.12);
    --soon-bd: rgba(168,85,247,0.45);
    --soon-tx: #5b21b6;
  }

  [data-theme="dark"]{
    --card-bg: rgba(17,24,39,0.72);
    --card-border: rgba(255,255,255,0.10);
    --text: #f9fafb;
    --table-text: #f9fafb;
    --table-muted: rgba(249,250,251,0.78);
    --muted: rgba(249,250,251,0.72);
    --input-bg: rgba(17,24,39,0.55);
    --input-border: rgba(255,255,255,0.14);
    --shadow: 0 20px 60px rgba(0,0,0,0.55);
    --btn: #f9fafb;
    --btn-text: #111827;
    --link: #f9fafb;
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

    --overdue-bg: rgba(239,68,68,0.12);
    --overdue-bd: rgba(239,68,68,0.55);

    --today-bg: rgba(59,130,246,0.16);
    --today-bd: rgba(59,130,246,0.55);
    --today-tx: #bfdbfe;

    --soon-bg: rgba(168,85,247,0.18);
    --soon-bd: rgba(168,85,247,0.60);
    --soon-tx: #e9d5ff;
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

  .bg-blobs{ position:fixed; inset:0; pointer-events:none; z-index:0; filter:blur(48px); opacity:.75; }
  .blob{
    position:absolute; width:420px; height:420px; border-radius:999px;
    background: radial-gradient(circle at 30% 30%, #324875 10%, #e5e7eb 45%, rgba(229,231,235,0) 70%);
    animation: floaty 18s ease-in-out infinite; mix-blend-mode: soft-light;
  }
  .blob.b1 { top: -120px; left: -120px; animation-duration: 20s; }
  .blob.b2 { bottom: -150px; right: -140px; animation-duration: 22s; }
  .blob.b3 { top: 20%; right: -180px; width: 520px; height: 520px; animation-duration: 26s; }
  .blob.b4 { bottom: 18%; left: -180px; width: 520px; height: 520px; animation-duration: 24s; }
  @keyframes floaty { 0%, 100% { transform: translate(0,0) scale(1); } 50% { transform: translate(40px, -30px) scale(1.08); } }

  .noise{
    position:fixed; inset:0; pointer-events:none; z-index:0;
    opacity:.08;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.8' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='120' height='120' filter='url(%23n)' opacity='0.35'/%3E%3C/svg%3E");
  }

  .page{ position:relative; z-index:1; padding: 18px 16px 40px; max-width: 1100px; margin: 0 auto; }
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
  .right-actions{ display:flex; align-items:center; gap:10px; }
  .icon-btn{
    border:none; background: var(--card-bg); border:1px solid var(--card-border);
    border-radius: 999px; padding: 10px 12px; color: var(--text); font-weight: 900;
    box-shadow: 0 10px 25px rgba(0,0,0,0.12);
    backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
  }
  .hamburger-btn{ font-size: 18px; line-height: 1; padding: 10px 14px; }

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
  @keyframes dropdownFade { from { opacity: 0; transform: translateY(0); } to { opacity: 1; transform: translateY(6px); } }
  .glass, .topbar, .page { overflow: visible !important; }
  .dropdown-item{ color: var(--text); font-weight: 700; }
  .dropdown-item:hover{ background: var(--chip); }

  .card-pad{ padding: 18px; }
  .muted{ color: var(--muted); }

  .form-control{
    background: var(--input-bg);
    border: 1px solid var(--input-border);
    color: var(--text);
    border-radius: 12px;
    padding: 10px 12px;
  }
  .form-control:focus{
    background: var(--input-bg) !important;
    color: var(--text) !important;
    box-shadow:none !important;
    border-color: var(--input-border) !important;
  }

  .btn-main{ border-radius: 12px; font-weight: 900; padding: 10px 12px; background: var(--btn); color: var(--btn-text); border:none; }
  .pill{ display:inline-flex; align-items:center; gap:8px; background: var(--chip); padding: 8px 10px; border-radius: 999px; font-weight: 900; }

  .table-wrap{ overflow:auto; }
  table { color: var(--text); }
  .table > :not(caption) > * > * { border-color: rgba(255,255,255,0.14); }

  .badge-soft{
    background: var(--chip);
    color: var(--text);
    border: 1px solid var(--card-border);
    font-weight: 900;
  }

  .prio-low  { background: var(--pri-low-bg) !important;  border-color: var(--pri-low-bd) !important;  color: var(--pri-low-tx) !important; }
  .prio-med  { background: var(--pri-med-bg) !important;  border-color: var(--pri-med-bd) !important;  color: var(--pri-med-tx) !important; }
  .prio-high { background: var(--pri-high-bg) !important; border-color: var(--pri-high-bd) !important; color: var(--pri-high-tx) !important; }

  tr.is-overdue td { background: var(--overdue-bg) !important; }
  tr.is-overdue td:first-child { border-left: 3px solid var(--overdue-bd); }

  tr.is-today td { background: var(--today-bg) !important; }
  tr.is-today td:first-child { border-left: 3px solid var(--today-bd); }

  tr.is-soon td { background: var(--soon-bg) !important; }
  tr.is-soon td:first-child { border-left: 3px solid var(--soon-bd); }

  [data-theme="dark"] table,
  [data-theme="dark"] table td,
  [data-theme="dark"] table th { color: var(--table-text) !important; }
  [data-theme="dark"] table .muted { color: var(--table-muted) !important; }
  [data-theme="dark"] table thead th { opacity: 0.95; }

  .toast-wrap{
    position: fixed; top: 18px; left: 18px; z-index: 20000;
    width: 420px; max-width: calc(100% - 36px);
  }

  .filterbar{
    display:flex; flex-wrap:wrap; gap:10px;
    align-items:center; justify-content:space-between;
    margin-bottom: 10px;
  }
  .filter-left{ display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
  .filter-right{ display:flex; flex-wrap:wrap; gap:10px; align-items:center; }

  .mini{
    padding: 8px 10px;
    border-radius: 12px;
    font-weight: 900;
    border: 1px solid var(--input-border);
    background: var(--input-bg);
    color: var(--text);
  }
  .mini:focus{
    outline:none; box-shadow:none;
    border-color: var(--input-border);
    background: var(--input-bg); color: var(--text);
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
    <div class="glass topbar mb-3">
      <div class="brand">
        <div class="brand-badge">ST</div>
        <div>
          Student Task Tracker<br>
          <span class="muted" style="font-weight:700; font-size:12px;">Signed in as <?php echo clean($userEmail); ?></span>
        </div>
      </div>

      <div class="right-actions">
    <div class="dropdown">
  <button class="icon-btn dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Settings">⚙</button>

  <ul class="dropdown-menu dropdown-menu-end p-0 settings-menu">
    <li class="settings-title">Settings</li>
    <li><div class="settings-divider"></div></li>

    <li class="settings-row">
      <span class="settings-label">App Appearance</span>
      <button class="btn btn-sm btn-main" type="button" id="themeBtn">Toggle</button>
    </li>
  </ul>
</div>


        <div class="dropdown">
          <button class="icon-btn dropdown-toggle hamburger-btn" data-bs-toggle="dropdown" aria-expanded="false" title="Menu">&#9776;</button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="../pages/homepage.php">Home</a></li>
             <li><a class="dropdown-item" href="../pages/profile.php">Profile</a></li>
            <li><a class="dropdown-item" href="calendar.php">Calendar</a></li>
            <li><a class="dropdown-item" href="#tasks">Tasks</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="../pages/logout.php">Log out</a></li>
          </ul>
        </div>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-12 col-lg-5">
        <div class="glass card-pad">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <h5 class="m-0" style="font-weight:900;">Add Task</h5>
            <span class="pill"><span class="muted" style="font-weight:900;">Total</span><?php echo $total; ?></span>
          </div>

          <div class="muted mb-3" style="font-weight:700;">
            Add assignments, homework, revision tasks, deadlines.
          </div>

          <div class="mb-3">
            <div class="d-flex align-items-center justify-content-between">
              <div style="font-weight:900;">Progress</div>
              <div class="muted" style="font-weight:900;"><?php echo (int)$progressPct; ?>%</div>
            </div>
            <div class="progress" style="height:10px; border-radius:999px; background: var(--chip);">
              <div class="progress-bar" role="progressbar"
                   style="width: <?php echo (int)$progressPct; ?>%; background: var(--btn); border-radius:999px;"></div>
            </div>
          </div>

          <form method="post" class="d-grid" style="gap:12px;">
            <input type="hidden" name="csrf" value="<?php echo clean($csrf); ?>">
            <input type="hidden" name="action" value="add">

            <div>
              <label style="font-weight:900; font-size:13px;">Title</label>
              <input class="form-control" name="title" placeholder="e.g., Maths homework (Chapter 7)" required>
            </div>

            <div>
              <label style="font-weight:900; font-size:13px;">Description</label>
              <textarea class="form-control" name="description" rows="3" placeholder="What do you need to do?"></textarea>
            </div>

            <div class="row g-2">
              <div class="col-6">
                <label style="font-weight:900; font-size:13px;">Due date</label>
                <input class="form-control" type="date" name="due_date">
              </div>
              <div class="col-6">
                <label style="font-weight:900; font-size:13px;">Priority</label>
                <select class="form-control" name="priority">
                  <option>Low</option>
                  <option selected>Medium</option>
                  <option>High</option>
                </select>
              </div>
            </div>

            <div>
              <label style="font-weight:900; font-size:13px;">Status</label>
              <select class="form-control" name="status">
                <option selected>Not Started</option>
                <option>In Progress</option>
                <option>Done</option>
              </select>
            </div>

            <button class="btn-main" type="submit">Add Task</button>
          </form>

          <hr style="opacity:.2;">
          <div class="d-flex flex-wrap gap-2">
            <span class="pill">Not Started: <?php echo $notstart; ?></span>
            <span class="pill">In Progress: <?php echo $inprog; ?></span>
            <span class="pill">Done: <?php echo $done; ?></span>

            <span class="pill" style="<?php echo $overdueCount > 0 ? 'border:1px solid var(--overdue-bd); background: var(--overdue-bg);' : ''; ?>">
              Overdue: <?php echo (int)$overdueCount; ?>
            </span>

            <span class="pill" style="<?php echo $dueTodayCount > 0 ? 'border:1px solid var(--today-bd); background: var(--today-bg);' : ''; ?>">
              Due today: <?php echo (int)$dueTodayCount; ?>
            </span>

            <span class="pill" style="<?php echo $dueSoonCount > 0 ? 'border:1px solid var(--soon-bd); background: var(--soon-bg);' : ''; ?>">
              Due soon: <?php echo (int)$dueSoonCount; ?>
            </span>
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-7" id="tasks">
        <div class="glass card-pad">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <h5 class="m-0" style="font-weight:900;">Your Tasks</h5>
            <span class="muted" style="font-weight:800;">Edit / Delete from the list</span>
          </div>

          <div class="filterbar">
            <div class="filter-left">
              <input class="mini" id="q" placeholder="Search tasks..." style="min-width:220px;">
              <select class="mini" id="fStatus">
                <option value="">All status</option>
                <option>Not Started</option>
                <option>In Progress</option>
                <option>Done</option>
              </select>
              <select class="mini" id="fPriority">
                <option value="">All priority</option>
                <option>Low</option>
                <option>Medium</option>
                <option>High</option>
              </select>

              <label class="mini" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                <input type="checkbox" id="fOverdue" style="transform: translateY(1px);">
                Overdue only
              </label>

              <label class="mini" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                <input type="checkbox" id="fToday" style="transform: translateY(1px);">
                Due today only
              </label>

              <label class="mini" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                <input type="checkbox" id="fSoon" style="transform: translateY(1px);">
                Due soon only
              </label>
            </div>

            <div class="filter-right">
              <!-- ✅ SORT DROPDOWN -->
              <select class="mini" id="sortBy" title="Sort">
                <option value="due_asc">Sort: Due date (soonest)</option>
                <option value="due_desc">Sort: Due date (latest)</option>
                <option value="priority_desc">Sort: Priority (High → Low)</option>
                <option value="priority_asc">Sort: Priority (Low → High)</option>
                <option value="status_asc">Sort: Status (Not Started → Done)</option>
                <option value="status_desc">Sort: Status (Done → Not Started)</option>
                <option value="title_asc">Sort: Title (A → Z)</option>
                <option value="created_desc">Sort: Created (newest)</option>
                <option value="created_asc">Sort: Created (oldest)</option>
              </select>

              <div class="muted" id="visibleCount" style="font-weight:900;"></div>
            </div>
          </div>

          <div class="table-wrap">
            <table class="table table-hover align-middle mb-0">
              <thead>
                <tr style="font-weight:900;">
                  <th>Task</th>
                  <th>Due</th>
                  <th>Status</th>
                  <th>Priority</th>
                  <th style="width:160px;">Actions</th>
                </tr>
              </thead>

              <tbody id="taskBody">
                <?php if (count($tasks) === 0): ?>
                  <tr>
                    <td colspan="5" class="muted" style="font-weight:800;">
                      No tasks yet — add one on the left.
                    </td>
                  </tr>
                <?php endif; ?>

                <?php foreach ($tasks as $t): ?>
                  <?php
                    // ✅ NEW: show "No due date" when due_date is empty
                    $dueLabel = "No due date";
                    $isOverdue = false;
                    $isToday = false;
                    $isSoon = false;
                    $dueRaw = $t['due_date'] ?? '';

                    if (!empty($dueRaw)) {
                        $dueDT = DateTime::createFromFormat('Y-m-d', $dueRaw);
                        if ($dueDT) {
                            $dueLabel = $dueDT->format("d M Y");
                            $isOverdue = ($dueDT < $today && $t['status'] !== 'Done');
                            $isToday = ($dueRaw === $todayStr && $t['status'] !== 'Done');
                            $isSoon = ($t['status'] !== 'Done' && $dueDT > $today && $dueDT <= $soonLimit);
                        }
                    }

                    $p = $t['priority'] ?? 'Medium';
                    $pClass = ($p === 'High') ? 'prio-high' : (($p === 'Low') ? 'prio-low' : 'prio-med');

                    $rowClass = $isOverdue ? 'is-overdue' : ($isToday ? 'is-today' : ($isSoon ? 'is-soon' : ''));
                    $createdRaw = $t['created_at'] ?? '';
                  ?>

                  <tr
                    class="<?php echo $rowClass; ?>"
                    data-title="<?php echo clean(mb_strtolower($t['title'] ?? '')); ?>"
                    data-desc="<?php echo clean(mb_strtolower($t['description'] ?? '')); ?>"
                    data-status="<?php echo clean($t['status']); ?>"
                    data-priority="<?php echo clean($t['priority']); ?>"
                    data-overdue="<?php echo $isOverdue ? '1' : '0'; ?>"
                    data-today="<?php echo $isToday ? '1' : '0'; ?>"
                    data-soon="<?php echo $isSoon ? '1' : '0'; ?>"
                    data-due="<?php echo clean($dueRaw); ?>"
                    data-created="<?php echo clean($createdRaw); ?>"
                  >
                    <td>
                      <div style="font-weight:900; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                        <span><?php echo clean($t['title']); ?></span>

                        <?php if ($isOverdue): ?>
                          <span class="badge badge-soft" style="background:var(--overdue-bg); border-color:var(--overdue-bd);">
                            Overdue
                          </span>
                        <?php endif; ?>

                        <?php if ($isToday): ?>
                          <span class="badge badge-soft" style="background:var(--today-bg); border-color:var(--today-bd); color:var(--today-tx);">
                            Due today
                          </span>
                        <?php endif; ?>

                        <?php if ($isSoon): ?>
                          <span class="badge badge-soft" style="background:var(--soon-bg); border-color:var(--soon-bd); color:var(--soon-tx);">
                            Due soon
                          </span>
                        <?php endif; ?>
                      </div>

                      <?php if (!empty($t['description'])): ?>
                        <div class="muted" style="font-weight:700; font-size:12px;">
                          <?php echo clean($t['description']); ?>
                        </div>
                      <?php endif; ?>
                    </td>

                    <td style="font-weight:800;">
                      <?php if ($isToday): ?>
                        <span style="font-weight:900;">Today</span>
                        <span class="muted" style="font-weight:800;">(<?php echo clean($dueLabel); ?>)</span>
                      <?php else: ?>
                        <?php echo clean($dueLabel); ?>
                      <?php endif; ?>
                    </td>

                    <td><span class="badge badge-soft"><?php echo clean($t['status']); ?></span></td>

                    <td>
                      <span class="badge badge-soft <?php echo $pClass; ?>">
                        <?php echo clean($t['priority']); ?>
                      </span>
                    </td>

                    <td>
                      <div class="d-flex gap-2">
                        <button
                          type="button"
                          class="btn btn-sm btn-main"
                          data-bs-toggle="modal"
                          data-bs-target="#editModal"
                          data-id="<?php echo (int)$t['id']; ?>"
                          data-title="<?php echo clean($t['title']); ?>"
                          data-description="<?php echo clean($t['description']); ?>"
                          data-due="<?php echo clean($t['due_date']); ?>"
                          data-status="<?php echo clean($t['status']); ?>"
                          data-priority="<?php echo clean($t['priority']); ?>"
                        >Edit</button>

                        <form method="post" onsubmit="return confirm('Delete this task?');">
                          <input type="hidden" name="csrf" value="<?php echo clean($csrf); ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                          <button type="submit" class="btn btn-sm btn-danger" style="border-radius:12px; font-weight:900;">Delete</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>

            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Edit Modal -->
  <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content glass" style="border-radius:16px;">
        <div class="modal-header" style="border-bottom:1px solid var(--card-border);">
          <h5 class="modal-title" style="font-weight:900;">Edit Task</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <form method="post">
          <div class="modal-body d-grid" style="gap:12px;">
            <input type="hidden" name="csrf" value="<?php echo clean($csrf); ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_id">

            <div>
              <label style="font-weight:900; font-size:13px;">Title</label>
              <input class="form-control" name="title" id="edit_title" required>
            </div>

            <div>
              <label style="font-weight:900; font-size:13px;">Description</label>
              <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
            </div>

            <div class="row g-2">
              <div class="col-6">
                <label style="font-weight:900; font-size:13px;">Due date</label>
                <input class="form-control" type="date" name="due_date" id="edit_due_date">
              </div>
              <div class="col-6">
                <label style="font-weight:900; font-size:13px;">Priority</label>
                <select class="form-control" name="priority" id="edit_priority">
                  <option>Low</option>
                  <option>Medium</option>
                  <option>High</option>
                </select>
              </div>
            </div>

            <div>
              <label style="font-weight:900; font-size:13px;">Status</label>
              <select class="form-control" name="status" id="edit_status">
                <option>Not Started</option>
                <option>In Progress</option>
                <option>Done</option>
              </select>
            </div>
          </div>

          <div class="modal-footer" style="border-top:1px solid var(--card-border);">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius:12px; font-weight:900;">Cancel</button>
            <button type="submit" class="btn-main">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>

<script>
  // Theme
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

  // Populate Edit Modal
  (function () {
    const editModal = document.getElementById('editModal');
    if (!editModal) return;

    editModal.addEventListener('show.bs.modal', function (event) {
      const btn = event.relatedTarget;
      if (!btn) return;

      document.getElementById('edit_id').value = btn.getAttribute('data-id') || '';
      document.getElementById('edit_title').value = btn.getAttribute('data-title') || '';
      document.getElementById('edit_description').value = btn.getAttribute('data-description') || '';
      document.getElementById('edit_due_date').value = btn.getAttribute('data-due') || '';
      document.getElementById('edit_status').value = btn.getAttribute('data-status') || 'Not Started';
      document.getElementById('edit_priority').value = btn.getAttribute('data-priority') || 'Medium';
    });
  })();

  // Toast show
  (function () {
    const toastElList = [].slice.call(document.querySelectorAll('.toast'));
    const toastList = toastElList.map(t => new bootstrap.Toast(t, { delay: 3000 }));
    toastList.forEach(t => t.show());
  })();

  // Click-outside-to-close dropdowns
  document.addEventListener('click', (e) => {
    document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
      if (!menu.parentElement.contains(e.target)) {
        const toggle = menu.parentElement.querySelector('[data-bs-toggle="dropdown"]');
        if (toggle) bootstrap.Dropdown.getOrCreateInstance(toggle).hide();
      }
    });
  });

  // Filters + search + SORT (client-side)
  (function () {
    const q = document.getElementById('q');
    const fStatus = document.getElementById('fStatus');
    const fPriority = document.getElementById('fPriority');
    const fOverdue = document.getElementById('fOverdue');
    const fToday = document.getElementById('fToday');
    const fSoon = document.getElementById('fSoon');
    const sortBy = document.getElementById('sortBy');

    const body = document.getElementById('taskBody');
    const visibleCount = document.getElementById('visibleCount');
    if (!body) return;

    const PRIORITY_MAP = { "Low": 1, "Medium": 2, "High": 3 };
    const STATUS_MAP = { "Not Started": 1, "In Progress": 2, "Done": 3 };

    function parseDateYMD(s) {
      if (!s) return null;
      const parts = s.split("-");
      if (parts.length !== 3) return null;
      const y = Number(parts[0]), m = Number(parts[1]) - 1, d = Number(parts[2]);
      const dt = new Date(Date.UTC(y, m, d));
      return isNaN(dt.getTime()) ? null : dt.getTime();
    }

    function parseDateTime(s) {
      if (!s) return null;
      const iso = s.replace(" ", "T") + "Z";
      const dt = new Date(iso);
      return isNaN(dt.getTime()) ? null : dt.getTime();
    }

    function sortRows() {
      const mode = sortBy?.value || "due_asc";
      const rows = Array.from(body.querySelectorAll('tr'))
        .filter(r => !(r.querySelector('td')?.getAttribute('colspan')));

      const getDue = (r) => parseDateYMD(r.getAttribute('data-due')) ?? null;
      const getCreated = (r) => parseDateTime(r.getAttribute('data-created')) ?? null;
      const getPriority = (r) => PRIORITY_MAP[r.getAttribute('data-priority')] ?? 2;
      const getStatus = (r) => STATUS_MAP[r.getAttribute('data-status')] ?? 99;
      const getTitle = (r) => (r.getAttribute('data-title') || '').trim();

      const idxMap = new Map(rows.map((r, i) => [r, i]));

      rows.sort((a, b) => {
        const ia = idxMap.get(a) ?? 0;
        const ib = idxMap.get(b) ?? 0;

        if (mode === "due_asc" || mode === "due_desc") {
          const da = getDue(a);
          const db = getDue(b);

          if (da === null && db === null) return ia - ib;
          if (da === null) return mode === "due_asc" ? 1 : -1;
          if (db === null) return mode === "due_asc" ? -1 : 1;

          if (da !== db) return mode === "due_asc" ? (da - db) : (db - da);
          return ia - ib;
        }

        if (mode === "priority_desc" || mode === "priority_asc") {
          const pa = getPriority(a), pb = getPriority(b);
          if (pa !== pb) return mode === "priority_desc" ? (pb - pa) : (pa - pb);
          return ia - ib;
        }

        if (mode === "status_asc" || mode === "status_desc") {
          const sa = getStatus(a), sb = getStatus(b);
          if (sa !== sb) return mode === "status_asc" ? (sa - sb) : (sb - sa);
          return ia - ib;
        }

        if (mode === "title_asc") {
          const ta = getTitle(a), tb = getTitle(b);
          if (ta < tb) return -1;
          if (ta > tb) return 1;
          return ia - ib;
        }

        if (mode === "created_desc" || mode === "created_asc") {
          const ca = getCreated(a);
          const cb = getCreated(b);

          if (ca === null && cb === null) return ia - ib;
          if (ca === null) return 1;
          if (cb === null) return -1;

          if (ca !== cb) return mode === "created_desc" ? (cb - ca) : (ca - cb);
          return ia - ib;
        }

        return ia - ib;
      });

      rows.forEach(r => body.appendChild(r));
    }

    function applyFilters() {
      const query = (q?.value || '').trim().toLowerCase();
      const st = (fStatus?.value || '').trim();
      const pr = (fPriority?.value || '').trim();

      const odOnly = !!fOverdue?.checked;
      const todayOnly = !!fToday?.checked;
      const soonOnly = !!fSoon?.checked;

      if (todayOnly) { if (fOverdue) fOverdue.checked = false; if (fSoon) fSoon.checked = false; }
      if (odOnly)    { if (fToday) fToday.checked = false; if (fSoon) fSoon.checked = false; }
      if (soonOnly)  { if (fToday) fToday.checked = false; if (fOverdue) fOverdue.checked = false; }

      const rows = Array.from(body.querySelectorAll('tr'));
      let shown = 0;

      rows.forEach(row => {
        if (row.querySelector('td')?.getAttribute('colspan')) return;

        const title = row.getAttribute('data-title') || '';
        const desc = row.getAttribute('data-desc') || '';
        const status = row.getAttribute('data-status') || '';
        const priority = row.getAttribute('data-priority') || '';

        const overdue = row.getAttribute('data-overdue') === '1';
        const today = row.getAttribute('data-today') === '1';
        const soon = row.getAttribute('data-soon') === '1';

        const matchesQuery = !query || title.includes(query) || desc.includes(query);
        const matchesStatus = !st || status === st;
        const matchesPriority = !pr || priority === pr;

        const matchesOverdue = !odOnly || overdue;
        const matchesToday = !todayOnly || today;
        const matchesSoon = !soonOnly || soon;

        const ok = matchesQuery && matchesStatus && matchesPriority && matchesOverdue && matchesToday && matchesSoon;
        row.style.display = ok ? '' : 'none';
        if (ok) shown++;
      });

      if (visibleCount) visibleCount.textContent = shown ? `${shown} showing` : `0 showing`;

      sortRows();
    }

    [q, fStatus, fPriority, fOverdue, fToday, fSoon, sortBy].forEach(el => {
      if (!el) return;
      el.addEventListener('input', applyFilters);
      el.addEventListener('change', applyFilters);
    });

    applyFilters();
  })();
</script>
</body>
</html>
