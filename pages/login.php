<?php
include '../database/db_connect.php';

$message ="";
$toastClass ="";

// Remember-me: email prefill
$remembered_email = "";
if (!empty($_COOKIE['remember_email'])) {
    $remembered_email = htmlspecialchars($_COOKIE['remember_email']);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'] ?? '';
    $password = trim($_POST['password'] ?? '');
    $remember = isset($_POST['remember']);

    // Remember-me cookie (email only)
    if ($remember) {
        // 30 days
        setcookie("remember_email", $email, time() + (60 * 60 * 24 * 30), "/");
    } else {
        // Clear cookie
        setcookie("remember_email", "", time() - 3600, "/");
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email format";
        $toastClass = "bg-danger";
    } else {
        $stmt = $conn->prepare("SELECT password FROM userdata WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $stmt->bind_result($db_password);
            $stmt->fetch();

            $is_hashed = strpos($db_password, '$') === 0;
            $password_match = $is_hashed
                ? password_verify($password, $db_password)
                : $password === trim($db_password);

            if ($password_match) {
                session_start();
                $_SESSION['email'] = $email;

                header("Location: ../pages/homepage.php");
                exit();
            } else {
                $message = "Incorrect password";
                $toastClass = "bg-danger";
            }
        } else {
            $message = "Email not found";
            $toastClass = "bg-warning";
        }

        $stmt->close();
    }
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login</title>

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
      --text: #ffffff;
      --muted: rgba(249,250,251,0.72);
      --input-bg: rgba(17,24,39,0.55);
      --input-border: rgba(255,255,255,0.14);
      --shadow: 0 20px 60px rgba(0,0,0,0.55);
      --btn: #ffffff;
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
      padding: 110px 16px;

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

    @keyframes bgMove {
      0% { background-position: 0% 50%; }
      50% { background-position: 100% 50%; }
      100% { background-position: 0% 50%; }
    }

    @keyframes floaty {
      0%, 100% { transform: translate(0,0) scale(1); }
      50% { transform: translate(40px, -30px) scale(1.08); }
    }

    /* Card */
    .wrap {
      width: 100%;
      max-width: 440px;
      position: relative;
      z-index: 1;
    }

    .login-card {
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
    
.form-control:focus {
  background: var(--input-bg) !important;
  color: var(--text) !important;
  box-shadow: none !important;
  border-color: var(--input-border) !important;
}
 



    .btn-main {
      border-radius: 12px;
      font-weight: 800;
      padding: 10px 12px;
      background: var(--btn);
      color: var(--btn-text);
      border: none;
    }
    .btn-main:hover { filter: brightness(0.94); }

    .row-gap { display: grid; gap: 12px; }

    /* Password toggle button inside input */
    .pw-wrap { position: relative; }
    .pw-toggle {
      position: absolute;
      right: 10px;
      top: 50%;
      transform: translateY(-50%);
      border: none;
      background: var(--chip);
      color: var(--text);
      padding: 6px 10px;
      border-radius: 10px;
      font-weight: 800;
      font-size: 12px;
    }

    .or {
      text-align: center;
      color: var(--muted);
      font-size: 12px;
      margin: 6px 0;
      letter-spacing: 0.12em;
    }

    .ms-btn {
      width: 100%;
      border: 1px solid var(--input-border);
      background: var(--input-bg);
      padding: 10px 12px;
      border-radius: 12px;
      font-weight: 800;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      text-decoration: none;
      color: var(--text);
    }
    .ms-btn:hover { filter: brightness(0.98); }

    .ms-square {
      width: 16px;
      height: 16px;
      display: grid;
      grid-template-columns: repeat(2,1fr);
      grid-template-rows: repeat(2,1fr);
      gap: 2px;
    }
    .ms-square span:nth-child(1){background:#f25022;}
    .ms-square span:nth-child(2){background:#7fba00;}
    .ms-square span:nth-child(3){background:#00a4ef;}
    .ms-square span:nth-child(4){background:#ffb900;}

    .links {
      text-align: center;
      margin-top: 10px;
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

    /* Toast */
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
    <div class="login-card">
      <h3 class="title">Login</h3>
      <div class="subtitle">Welcome back — enter your details to continue.</div>

      <form method="post" class="row-gap">
        <div>
          <label for="email">Email</label>
          <input
            type="email"
            name="email"
            id="email"
            class="form-control"
            value="<?php echo $remembered_email; ?>"
            required
          >
        </div>

        <div class="pw-wrap">
          <label for="password">Password</label>
          <input type="password" name="password" id="password" class="form-control" required>
          <button class="pw-toggle" type="button" id="pwBtn">Show</button>
        </div>

        <div class="d-flex align-items-center justify-content-between">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" value="1" id="remember" name="remember"
              <?php echo !empty($remembered_email) ? 'checked' : ''; ?>>
            <label class="form-check-label" for="remember" style="font-weight: 800; color: var(--muted);">
              Remember me
            </label>
          </div>

          <a href="/pages/reset_password.php" style="color: var(--link); text-decoration:none; font-weight: 900;">
            Forgot?
          </a>
        </div>

        <div class="d-grid">
          <button type="submit" class="btn-main">Login</button>
        </div>

        <div class="or">OR</div>

        <!-- Microsoft wired to a real redirect file -->
        <a class="ms-btn" href="microsoft_login.php">
          <span class="ms-square" aria-hidden="true">
            <span></span><span></span><span></span><span></span>
          </span>
          Continue with Microsoft
        </a>

        <div class="links">
          <a href="/pages/register.php">Create Account</a>
        </div>
      </form>
    </div>
  </div>

  <script>
    // Bootstrap toast show
    (function () {
      var toastElList = [].slice.call(document.querySelectorAll('.toast'));
      var toastList = toastElList.map(function (toastEl) {
        return new bootstrap.Toast(toastEl, { delay: 3000 });
      });
      toastList.forEach(t => t.show());
    })();

    // Password show/hide
    (function () {
      const pw = document.getElementById('password');
      const btn = document.getElementById('pwBtn');
      btn.addEventListener('click', () => {
        const isHidden = pw.getAttribute('type') === 'password';
        pw.setAttribute('type', isHidden ? 'text' : 'password');
        btn.textContent = isHidden ? 'Hide' : 'Show';
      });
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
