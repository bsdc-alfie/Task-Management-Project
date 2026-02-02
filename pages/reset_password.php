<?php
include '../database/db_connect.php';

$message = "";
$toastClass = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'] ?? '';
    $password = trim($_POST['password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');

    if ($password !== $confirmPassword) {
        $message = "Passwords do not match";
        $toastClass = "bg-warning";
    } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("UPDATE userdata SET password = ? WHERE email = ?");
        $stmt->bind_param("ss", $hashed_password, $email);

        if ($stmt->execute()) {
            $message = "Password updated successfully";
            $toastClass = "bg-success";
        } else {
            $message = "Error updating password";
            $toastClass = "bg-danger";
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
<title>Reset Password</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

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
}

[data-theme="dark"] {
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
}

/* ✅ Centering + NEW animated blue background */
body {
  min-height: 100vh;
  margin: 0;
  display: grid;
  place-items: center;
  padding: 28px 16px;
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

/* ✅ Floating blobs */
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
  background: radial-gradient(
    circle at 30% 30%,
    #324875 10%,
    #e5e7eb 45%,
    rgba(229,231,235,0) 70%
  );
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

/* Card wrapper */
.wrap {
  width: 100%;
  max-width: 440px;
  position: relative;
  z-index: 1; /* makes sure card is above blobs */
}

.auth-card {
  background: var(--card-bg);
  border: 1px solid var(--card-border);
  box-shadow: var(--shadow);
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  border-radius: 16px;
  padding: 26px;
}

.title {
  font-weight: 800;
  text-align: center;
  margin-bottom: 6px;
}

.subtitle {
  text-align: center;
  color: var(--muted);
  font-size: 14px;
  margin-bottom: 18px;
}

label {
  font-weight: 700;
  font-size: 13px;
  margin-bottom: 6px;
}

.form-control {
  background: var(--input-bg);
  border: 1px solid var(--input-border);
  color: var(--text);
  border-radius: 12px;
  padding: 10px 12px;
}

/* ✅ Focus fix (no white flash in dark mode) */
.form-control:focus {
  background: var(--input-bg) !important;
  color: var(--text) !important;
  box-shadow: none !important;
  border-color: var(--input-border) !important;
}

/* ✅ Autofill fix */
input:-webkit-autofill,
input:-webkit-autofill:hover,
input:-webkit-autofill:focus {
  -webkit-text-fill-color: var(--text) !important;
  transition: background-color 9999s ease-out 0s;
  box-shadow: 0 0 0px 1000px var(--input-bg) inset !important;
}

.btn-main {
  width: 100%;
  border-radius: 12px;
  font-weight: 800;
  padding: 10px 12px;
  background: var(--btn);
  color: var(--btn-text);
  border: none;
}

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

<!-- ✅ Blobs added -->
<div class="bg-blobs" aria-hidden="true">
  <div class="blob b1"></div>
  <div class="blob b2"></div>
  <div class="blob b3"></div>
  <div class="blob b4"></div>
</div>

<button class="theme-toggle" id="themeBtn">App Appearance</button>

<?php if ($message): ?>
<div class="toast-wrap">
  <div class="toast align-items-center text-white <?php echo $toastClass; ?> border-0 show">
    <div class="d-flex">
      <div class="toast-body"><?php echo $message; ?></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto"
              data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="wrap">
  <div class="auth-card">
    <h3 class="title">Reset Password</h3>
    <div class="subtitle">Enter your email and choose a new password.</div>

    <form method="post" class="d-grid" style="gap:12px;">
      <div class="position-relative">
        <label>Email</label>
        <input type="email" name="email" id="email" class="form-control" required>
        <span id="email-check" style="position:absolute; right:12px; top:38px;"></span>
      </div>

      <div>
        <label>New Password</label>
        <input type="password" name="password" class="form-control" required>
      </div>

      <div>
        <label>Confirm Password</label>
        <input type="password" name="confirm_password" class="form-control" required>
      </div>

      <button class="btn-main">Reset Password</button>

      <div class="links">
        <a href="./login.php">Login</a> ·
        <a href="./register.php">Create Account</a>
      </div>
    </form>
  </div>
</div>

<script>
/* Theme toggle */
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

  btn.onclick = () => {
    const t = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', t);
    localStorage.setItem('theme', t);
  };
})();

/* Email exists check (your original logic) */
$(document).ready(function () {
  $('#email').on('blur', function () {
    var email = $(this).val();
    if (email) {
      $.ajax({
        url: 'check_email.php',
        type: 'POST',
        data: { email: email },
        success: function (response) {
          if (response == 'exists') {
            $('#email-check').html('<span style="color: #22c55e; font-weight: 900;">✔</span>');
          } else {
            $('#email-check').html('<span style="color: #ef4444; font-weight: 900;">✖</span>');
          }
        }
      });
    } else {
      $('#email-check').html('');
    }
  });

  // Toast auto show (in case you remove "show" class later)
  var toastElList = [].slice.call(document.querySelectorAll('.toast'));
  var toastList = toastElList.map(function (toastEl) {
    return new bootstrap.Toast(toastEl, { delay: 3000 });
  });
  toastList.forEach(t => t.show());
});
</script>

</body>
</html>
