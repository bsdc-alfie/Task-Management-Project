<?php
// pages/canvas_import.php
session_start();

/* ✅ Prevent cached pages after logout (back button issue) */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

// --- Require login ---
if (!isset($_SESSION['email'])) {
  header("Location: login.php");
  exit();
}

$userEmail = $_SESSION['email'];

// --- CSRF token ---
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

include __DIR__ . '/../database/db_connect.php';

// --- Ensure tasks table exists (same schema as your app) ---
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

function clean($s) { return htmlspecialchars($s ?? "", ENT_QUOTES, 'UTF-8'); }

function require_csrf() {
  if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    http_response_code(403);
    die("Invalid CSRF token");
  }
}

$CANVAS_BASE = "https://bsdc.instructure.com";

function canvas_request($base, $token, $path) {
  $url = $base . $path;

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,              // so we can read Link: header for pagination
    CURLOPT_HTTPHEADER => [
      "Authorization: Bearer " . $token,
      "Accept: application/json"
    ],
  ]);

  $raw = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  curl_close($ch);

  if ($raw === false) return [null, null, 0];

  $headerStr = substr($raw, 0, $headerSize);
  $bodyStr   = substr($raw, $headerSize);

  $data = json_decode($bodyStr, true);
  return [$data, $headerStr, $code];
}

// Parse Canvas pagination Link header for rel="next"
function parse_next_link($headerStr) {
  // Canvas uses RFC5988 Link header: <url>; rel="next", <url>; rel="current", ...
  if (!preg_match('/^Link:\s*(.+)$/mi', $headerStr, $m)) return null;
  $links = $m[1];

  // find: <...>; rel="next"
  if (preg_match('/<([^>]+)>\s*;\s*rel="next"/', $links, $n)) {
    return $n[1];
  }
  return null;
}

// Fetch all pages from an endpoint path (relative URL)
function canvas_get_all($base, $token, $path) {
  $all = [];
  $nextUrl = $base . $path;

  // safety limit so we never loop forever
  $guard = 0;

  while ($nextUrl && $guard < 50) {
    $guard++;

    // Convert full URL back to path for our request func (so base stays consistent)
    $parsed = parse_url($nextUrl);
    $pathWithQuery = ($parsed['path'] ?? '') . (isset($parsed['query']) ? ('?' . $parsed['query']) : '');

    [$data, $headers, $code] = canvas_request($base, $token, $pathWithQuery);
    if ($code >= 400 || !is_array($data)) {
      return [null, $code];
    }

    // Canvas returns arrays for list endpoints
    foreach ($data as $item) $all[] = $item;

    $next = parse_next_link($headers);
    $nextUrl = $next ? $next : null;
  }

  return [$all, 200];
}

// Convert Canvas due_at ISO string -> MySQL DATE (Y-m-d) in Europe/London
function due_at_to_date($dueAtIso) {
  if (!$dueAtIso) return null;
  try {
    $dt = new DateTime($dueAtIso); // Canvas includes timezone (usually Z)
    $dt->setTimezone(new DateTimeZone("Europe/London"));
    return $dt->format("Y-m-d");
  } catch (Exception $e) {
    return null;
  }
}

$message = "";
$toastClass = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  require_csrf();

  $token = trim($_POST['canvas_token'] ?? "");

  if ($token === "") {
    $message = "Paste your Canvas access token first.";
    $toastClass = "bg-warning";
  } else {
    // 1) Get active courses
    [$courses, $code] = canvas_get_all($CANVAS_BASE, $token, "/api/v1/courses?enrollment_state=active&per_page=100");

    if (!$courses) {
      $message = "Canvas API error (HTTP $code). Double-check your token.";
      $toastClass = "bg-danger";
    } else {
      // Prepare insert
      $insert = $conn->prepare("
        INSERT INTO tasks (user_email, title, description, due_date, status, priority)
        VALUES (?, ?, ?, ?, 'Not Started', 'Medium')
      ");

      $added = 0;
      $skippedNoDue = 0;
      $skippedNoName = 0;

      foreach ($courses as $c) {
        $courseId = $c['id'] ?? null;
        $courseName = $c['name'] ?? ($c['course_code'] ?? 'Course');

        if (!$courseId) continue;

        // 2) Get assignments for the course
        [$assignments, $aCode] = canvas_get_all(
          $CANVAS_BASE,
          $token,
          "/api/v1/courses/" . urlencode($courseId) . "/assignments?per_page=100"
        );

        // If one course fails, continue (don’t kill whole import)
        if (!$assignments) continue;

        foreach ($assignments as $a) {
          $title = trim($a['name'] ?? "");
          if ($title === "") { $skippedNoName++; continue; }

          $dueAt = $a['due_at'] ?? null;
          $dueDate = due_at_to_date($dueAt);

          if (!$dueDate) { $skippedNoDue++; continue; }

          $htmlUrl = $a['html_url'] ?? '';
          $descParts = [];
          $descParts[] = "Course: " . $courseName;
          if ($htmlUrl) $descParts[] = "Canvas link: " . $htmlUrl;
          $description = implode(" | ", $descParts);

          $insert->bind_param("ssss", $userEmail, $title, $description, $dueDate);
          if ($insert->execute()) $added++;
        }
      }

      $insert->close();

      $message = "Imported $added assignments into your tasks. Skipped $skippedNoDue with no due date.";
      $toastClass = "bg-success";
    }
  }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Import from Canvas</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>

  <style>
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
      --chip: rgba(17,24,39,0.08);
    }

    [data-theme="dark"]{
      --card-bg: rgba(17,24,39,0.72);
      --card-border: rgba(255,255,255,0.10);
      --text: #f9fafb;
      --muted: rgba(249,250,251,0.72);
      --input-bg: rgba(17,24,39,0.55);
      --input-border: rgba(255,255,255,0.14);
      --shadow: 0 20px 60px rgba(0,0,0,0.55);
      --btn: #f9fafb;
      --btn-text: #111827;
      --chip: rgba(255,255,255,0.10);
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

    .page{ position:relative; z-index:1; padding: 18px 16px 40px; max-width: 900px; margin: 0 auto; }
    .glass{
      background: var(--card-bg);
      border:1px solid var(--card-border);
      box-shadow: var(--shadow);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      border-radius: 16px;
    }
    .card-pad{ padding: 18px; }

    .btn-main{
      border-radius: 12px; font-weight: 900; padding: 10px 12px;
      background: var(--btn); color: var(--btn-text); border:none;
    }

    /* ===== ✅ Canvas Import Button Styles (added) ===== */
    .btn-canvas {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 10px;

      padding: 12px 16px;
      border-radius: 14px;
      font-weight: 900;
      font-size: 14px;
      letter-spacing: 0.02em;

      background: linear-gradient(135deg, #e11d48, #be123c);
      color: #ffffff;
      border: none;

      box-shadow:
        0 12px 30px rgba(225, 29, 72, 0.35),
        inset 0 1px 0 rgba(255,255,255,0.2);

      transition: transform 0.15s ease,
                  box-shadow 0.15s ease,
                  filter 0.15s ease;
    }

    .btn-canvas:hover {
      transform: translateY(-1px);
      filter: brightness(1.05);
      box-shadow:
        0 16px 38px rgba(225, 29, 72, 0.45),
        inset 0 1px 0 rgba(255,255,255,0.25);
    }

    .btn-canvas:active {
      transform: translateY(0);
      box-shadow: 0 8px 18px rgba(225, 29, 72, 0.35);
    }

    .btn-canvas:disabled {
      opacity: 0.75;
      cursor: not-allowed;
      transform: none;
      filter: none;
    }

    .btn-canvas-outline {
      display: inline-flex;
      align-items: center;
      gap: 8px;

      padding: 10px 14px;
      border-radius: 14px;
      font-weight: 900;

      background: transparent;
      color: var(--text);
      border: 1px solid var(--card-border);

      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      text-decoration: none;
    }

    .btn-canvas-outline:hover {
      background: var(--chip);
      color: var(--text);
      text-decoration: none;
    }

    .canvas-icon {
      width: 28px;
      height: 28px;
      border-radius: 999px;
      display: grid;
      place-items: center;

      background: rgba(255,255,255,0.18);
      font-size: 14px;
      font-weight: 900;
    }

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
    .muted{ color: var(--muted); }

    .toast-wrap{
      position: fixed;
      top: 18px;
      left: 18px;
      z-index: 20000;
      width: 420px;
      max-width: calc(100% - 36px);
    }
  </style>
</head>
<body>

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
  <div class="glass card-pad">
    <h4 style="font-weight:950; margin:0 0 6px;">Import from Canvas</h4>
    <div class="muted" style="font-weight:800; margin-bottom:14px;">
      Paste your Canvas access token and we’ll import assignments that have due dates into your task list.
      <br><span style="font-weight:900;">Note:</span> token is not saved — you can paste it when needed.
    </div>

    <form method="post" class="d-grid" style="gap:12px;">
      <input type="hidden" name="csrf" value="<?php echo clean($csrf); ?>">

      <div>
        <label style="font-weight:900; font-size:13px;">Canvas Access Token</label>
        <input class="form-control" name="canvas_token" placeholder="Paste token here">
      </div>

      <!-- ✅ Replaced with styled Canvas button -->
      <button class="btn-canvas" type="submit" id="importBtn">
        <span class="canvas-icon">📥</span>
        Import assignments from Canvas
      </button>

      <!-- ✅ Optional: nicer back buttons (replaces the grey bootstrap ones) -->
      <div class="d-flex gap-2 flex-wrap">
        <a class="btn-canvas-outline" href="../tasks/index.php">← Back to Tasks</a>
        <a class="btn-canvas-outline" href="homepage.php">🏠 Back to Home</a>
      </div>
    </form>
  </div>
</div>

<script>
  // Theme (same as your other pages)
  (function () {
    const root = document.documentElement;

    const saved = localStorage.getItem('theme');
    if (saved) root.setAttribute('data-theme', saved);
    else {
      const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
      root.setAttribute('data-theme', prefersDark ? 'dark' : 'light');
    }
  })();

  // Toast show
  (function () {
    const toastElList = [].slice.call(document.querySelectorAll('.toast'));
    const toastList = toastElList.map(t => new bootstrap.Toast(t, { delay: 3500 }));
    toastList.forEach(t => t.show());
  })();

  // Optional: tiny UX touch — disable button on submit to prevent double import
  (function () {
    const form = document.querySelector('form');
    const btn = document.getElementById('importBtn');
    if (!form || !btn) return;

    form.addEventListener('submit', () => {
      btn.disabled = true;
      btn.innerHTML = '<span class="canvas-icon">⏳</span> Importing...';
    });
  })();
</script>

</body>
</html>
