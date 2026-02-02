<?php
include '../database/db_connect.php';

$message = "";
$toastClass = "";

// Helper: map to Bootstrap toast classes
function setToast($type) {
    // type: success, danger, warning, primary
    return "bg-" . $type;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = trim($_POST['password'] ?? '');

    // Basic validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email format";
        $toastClass = setToast("warning");
    } else {
        // Check if email already exists
        $checkEmailStmt = $conn->prepare("SELECT email FROM userdata WHERE email = ?");
        $checkEmailStmt->bind_param("s", $email);
        $checkEmailStmt->execute();
        $checkEmailStmt->store_result();

        if ($checkEmailStmt->num_rows > 0) {
            $message = "Email already exists";
            $toastClass = setToast("primary");
        } else {
            // Hash password + insert
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("INSERT INTO userdata (username, email, password) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $username, $email, $hashed_password);

            if ($stmt->execute()) {
                $message = "Account created successfully";
                $toastClass = setToast("success");
            } else {
                $message = "Error: " . $stmt->error;
                $toastClass = setToast("danger");
            }

            $stmt->close();
        }

        $checkEmailStmt->close();
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create Account</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>

  <style>
    :root {
      /* Light theme */
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
      /* Dark theme */
      --card-bg: rgba(17,24,39,0.72);
      --card-border: rgba(255,255,255,0.10);
      --text: #f9fafb;
      --muted: rgba(249,250,251,0.72);
      --input-bg: rgba(17,24,39,0.55);
      --input-border: rgba(255,255,255,0.14);
      --shadow: 0 20px 60px rgba(0,0,0,0.55);
      --btn: #f9fafb;
      --btn-text: #111827;
      --link: #f9fafb;
      --chip: rgba(255,255,255,0.10);
    }

    /* Full-page animated grey/white background */
    body {
      min-height: 100vh;
      margin: 0;
      color: var(--text);
      overflow-x: hidden;
      display: grid;
      place-items: center;
      padding: 28px 16px;

      background: linear-gradient(120deg,
        rgb(50, 72, 117),
        #324875,
        #123c8f,
        #093969,
        #144577
      );
      background-size: 400% 400%;
      animation: bgmove 10s ease infinite;
      background-size: 400% 400%;
      animation: bgMove 16s ease infinite;
    }

    @keyframes bgMove {
      0% { background-position: 0% 50%; }
      50% { background-position: 100% 50%; }
      100% { background-position: 0% 50%; }
    }

    /* Soft animated blobs (grey/white) */
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

    /* Subtle noise overlay for texture */
    .noise {
      position: fixed;
      inset: 0;
      pointer-events: none;
      z-index: 0;
      opacity: 0.08;
      background-image:
        url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.8' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='120' height='120' filter='url(%23n)' opacity='0.35'/%3E%3C/svg%3E");
    }

    /* Card */
    .wrap {
      width: 100%;
      max-width: 440px;
      position: relative;
      z-index: 1;
    }

    .auth-card {
      border-radius: 16px;
      background: var(--card-bg);
      border: 1px solid var(--card-border);
      box-shadow: var(--shadow);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      padding: 26px;
    }

    .title {
      font-weight: 800;
      letter-spacing: -0.02em;
      margin-bottom: 6px;
      text-align: center;
    }

    .subtitle {
      color: var(--muted);
      font-size: 14px;
      text-align: center;
      margin-bottom: 18px;
    }

    label { font-weight: 700; font-size: 13px; margin-bottom: 6px; }

    .form-control {
      background: var(--input-bg);
      border: 1px solid var(--input-border);
      color: var(--text);
      border-radius: 12px;
      padding: 10px 12px;
    }

    /*  Focus fix: keep same bg in dark mode */
    .form-control:focus {
      background: var(--input-bg) !important;
      color: var(--text) !important;
      box-shadow: none !important;
      border-color: var(--input-border) !important;
    }

    /* ✅ Chrome autofill fix */
    input:-webkit-autofill,
    input:-webkit-autofill:hover,
    input:-webkit-autofill:focus {
      -webkit-text-fill-color: var(--text) !important;
      transition: background-color 9999s ease-out 0s;
      box-shadow: 0 0 0px 1000px var(--input-bg) inset !important;
    }

    .btn-main {
      border-radius: 12px;
      font-weight: 800;
      padding: 10px 12px;
      background: var(--btn);
      color: var(--btn-text);
      border: none;
      width: 100%;
    }
    .btn-main:hover { filter: brightness(0.94); }

    .links {
      text-align: center;
      margin-top: 12px;
      font-weight: 800;
      font-size: 14px;
    }
    .links a {
      color: var(--link);
      text-decoration: none;
      border-bottom: 1px dashed rgba(0,0,0,0.25);
    }
    [data-theme="dark"] .links a {
      border-bottom-color: rgba(255,255,255,0.25);
    }

    /* Dark mode toggle button */
    .theme-toggle {
      position: fixed;
      top: 14px;
      right: 14px;
      z-index: 2;
      border: none;
      border-radius: 999px;
      padding: 10px 12px;
      background: var(--card-bg);
      border: 1px solid var(--card-border);
      box-shadow: 0 10px 25px rgba(0,0,0,0.12);
      font-weight: 900;
      color: var(--text);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
    }

    /* Toast positioning */
    .toast-wrap {
      position: fixed;
      top: 18px;
      left: 18px;
      z-index: 2000;
      width: 420px;
      max-width: calc(100% - 36px);
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

  <button class="theme-toggle" type="button" id="themeBtn" aria-label="Toggle theme">
    App Appearance
  </button>

  <?php if ($message): ?>
    <div class="toast-wrap">
      <div class="toast align-items-center text-white <?php echo $toastClass; ?> border-0" role="alert"
           aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
          <div class="toast-body"><?php echo $message; ?></div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"
                  aria-label="Close"></button>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="wrap">
    <div class="auth-card">
      <h3 class="title">Create Account</h3>
      <div class="subtitle">Create your account to get started.</div>

      <form method="post" class="d-grid" style="gap: 12px;">
        <div>
          <label for="username">Username</label>
          <input type="text" name="username" id="username" class="form-control" required>
        </div>

        <div>
          <label for="email">Email</label>
          <input type="email" name="email" id="email" class="form-control" required>
        </div>

        <div>
          <label for="password">Password</label>
          <input type="password" name="password" id="password" class="form-control" required>
        </div>

        <div class="d-grid mt-1">
          <button type="submit" class="btn-main">Create Account</button>
        </div>

        <div class="links">
          Already have an account? <a href="./login.php">Login</a>
        </div>
      </form>
    </div>
  </div>

  <script>
    // Toast show
    (function () {
      var toastElList = [].slice.call(document.querySelectorAll('.toast'));
      var toastList = toastElList.map(function (toastEl) {
        return new bootstrap.Toast(toastEl, { delay: 3000 });
      });
      toastList.forEach(t => t.show());
    })();

    // Theme: auto-detect + manual toggle + save
    (function () {
      const root = document.documentElement;
      const btn = document.getElementById('themeBtn');

      function applyTheme(t) {
        root.setAttribute('data-theme', t);
        localStorage.setItem('theme', t);
      }

      const saved = localStorage.getItem('theme');
      if (saved) {
        applyTheme(saved);
      } else {
        const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        applyTheme(prefersDark ? 'dark' : 'light');
      }

      btn.addEventListener('click', () => {
        const current = root.getAttribute('data-theme') || 'light';
        applyTheme(current === 'dark' ? 'light' : 'dark');
      });
    })();
  </script>
</body>
</html>
