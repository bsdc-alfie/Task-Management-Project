<?php
session_start();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

include __DIR__ . '/../database/db_connect.php';

if (!isset($_SESSION['email'])) {
  header("Location: login.php");
  exit();
}

$email = $_SESSION['email'];

function clean($s) { return htmlspecialchars($s ?? "", ENT_QUOTES, 'UTF-8'); }

// ✅ CSRF
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

$msg = "";
$toast = "";

// ✅ Fetch current user
$username = "";
$displayName = "";
$photo = "";

$stmt = $conn->prepare("SELECT username, display_name, profile_photo FROM userdata WHERE email=? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
  $username = $row['username'] ?? "";
  $displayName = $row['display_name'] ?? "";
  $photo = $row['profile_photo'] ?? "";
}
$stmt->close();

function initials_from($displayName, $username, $email) {
  $base = trim($displayName) !== '' ? $displayName : (trim($username) !== '' ? $username : $email);
  $base = preg_replace('/[^a-zA-Z0-9\s]/', ' ', $base);
  $parts = array_values(array_filter(preg_split('/\s+/', trim($base))));

  if (count($parts) >= 2) {
    $out = strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
  } else {
    $out = strtoupper(substr($parts[0] ?? $base, 0, 2));
  }
  return $out ?: "U";
}

$initials = initials_from($displayName, $username, $email);

// ✅ Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // CSRF check
  if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    http_response_code(403);
    die("Invalid CSRF token");
  }

  $action = $_POST['action'] ?? '';

  // ✅ Update profile (display name + username with confirmation)
  if ($action === 'update_profile') {
    $newDisplay = trim($_POST['display_name'] ?? '');
    $newUser = trim($_POST['username'] ?? '');
    $confirm = trim($_POST['confirm_username'] ?? '');

    if ($newUser === '') {
      $msg = "Username cannot be empty.";
      $toast = "bg-warning";
    } elseif (!preg_match('/^[a-zA-Z0-9._-]{3,20}$/', $newUser)) {
      $msg = "Username must be 3–20 chars (letters, numbers, dot, underscore, dash).";
      $toast = "bg-warning";
    } elseif ($newUser !== $confirm) {
      $msg = "Username confirmation does not match.";
      $toast = "bg-warning";
    } else {
      // Check taken
      $check = $conn->prepare("SELECT COUNT(*) AS c FROM userdata WHERE username=? AND email<>?");
      $check->bind_param("ss", $newUser, $email);
      $check->execute();
      $count = (int)($check->get_result()->fetch_assoc()['c'] ?? 0);
      $check->close();

      if ($count > 0) {
        $msg = "That username is already taken.";
        $toast = "bg-warning";
      } else {
        $upd = $conn->prepare("UPDATE userdata SET username=?, display_name=? WHERE email=?");
        $upd->bind_param("sss", $newUser, $newDisplay, $email);

        if ($upd->execute()) {
          $msg = "Profile updated.";
          $toast = "bg-success";
          $username = $newUser;
          $displayName = $newDisplay;
          $initials = initials_from($displayName, $username, $email);
        } else {
          $msg = "Error updating profile.";
          $toast = "bg-danger";
        }
        $upd->close();
        
      }
    }
  }

  // ✅ Upload profile photo
  if ($action === 'upload_photo') {
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
      $msg = "Please choose a valid image file.";
      $toast = "bg-warning";
    } else {
      $allowed = ['image/jpeg','image/png','image/webp'];
      $type = mime_content_type($_FILES['photo']['tmp_name']);

      if (!in_array($type, $allowed, true)) {
        $msg = "Only JPG, PNG, or WEBP images allowed.";
        $toast = "bg-warning";
      } else {
        $ext = ($type === 'image/png') ? 'png' : (($type === 'image/webp') ? 'webp' : 'jpg');
        $dir = __DIR__ . '/../uploads/profile_photos';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $safeEmail = preg_replace('/[^a-zA-Z0-9]/', '_', $email);
        $filename = $safeEmail . '_' . time() . '.' . $ext;
        $path = $dir . '/' . $filename;
        $dbPath = '../uploads/profile_photos/' . $filename;

        if (move_uploaded_file($_FILES['photo']['tmp_name'], $path)) {
          $upd = $conn->prepare("UPDATE userdata SET profile_photo=? WHERE email=?");
          $upd->bind_param("ss", $dbPath, $email);

          if ($upd->execute()) {
            $msg = "Profile photo updated.";
            $toast = "bg-success";
            $photo = $dbPath;
          } else {
            $msg = "Error saving photo in database.";
            $toast = "bg-danger";
          }
          $upd->close();
        } else {
          $msg = "Could not upload photo.";
          $toast = "bg-danger";
        }
      }
    }
  }


  // ✅ Delete account (requires password)
  if ($action === 'delete_account') {
    $password = trim($_POST['password'] ?? '');

    if ($password === '') {
      $msg = "Enter your password to delete your account.";
      $toast = "bg-warning";
    } else {
      // Fetch password hash
      $st = $conn->prepare("SELECT password FROM userdata WHERE email=? LIMIT 1");
      $st->bind_param("s", $email);
      $st->execute();
      $hash = $st->get_result()->fetch_assoc()['password'] ?? '';
      $st->close();

      if (!$hash || !password_verify($password, $hash)) {
        $msg = "Password incorrect. Account NOT deleted.";
        $toast = "bg-danger";
      } else {
        // Delete user tasks first (if you want)
        $delTasks = $conn->prepare("DELETE FROM tasks WHERE user_email=?");
        $delTasks->bind_param("s", $email);
        $delTasks->execute();
        $delTasks->close();

        // Delete user
        $del = $conn->prepare("DELETE FROM userdata WHERE email=?");
        $del->bind_param("s", $email);

        if ($del->execute()) {
          session_destroy();
          header("Location: homepage.php");
          exit();
        } else {
          $msg = "Error deleting account.";
          $toast = "bg-danger";
        }
        $del->close();
      }
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
<title>Your Profile</title>

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
    --muted: rgba(249,250,251,0.74);
    --input-bg: rgba(17,24,39,0.55);
    --input-border: rgba(255,255,255,0.14);
    --shadow: 0 20px 60px rgba(0,0,0,0.55);
    --btn: #f9fafb;
    --btn-text: #111827;
    --chip: rgba(255,255,255,0.10);
  }
  body{
    min-height:100vh; margin:0; color:var(--text); overflow-x:hidden;
    background: linear-gradient(120deg, rgb(50,72,117), #324875, #123c8f, #093969, #144577);
    background-size: 400% 400%;
    animation: bgMove 16s ease infinite;
  }
  @keyframes bgMove{0%{background-position:0% 50%;}50%{background-position:100% 50%;}100%{background-position:0% 50%;}}
  .page{position:relative; z-index:1; padding:18px 16px 60px; max-width:900px; margin:0 auto;}
  .glass{background:var(--card-bg); border:1px solid var(--card-border); box-shadow:var(--shadow);
    backdrop-filter:blur(14px); -webkit-backdrop-filter:blur(14px); border-radius:16px;}
  .card-pad{padding:18px;}
  .muted{color:var(--muted);}
  .btn-main{border-radius:12px; font-weight:900; padding:10px 12px; background:var(--btn); color:var(--btn-text); border:none;}
  .form-control{background:var(--input-bg); border:1px solid var(--input-border); color:var(--text); border-radius:12px; padding:10px 12px;}
  .form-control:focus{background:var(--input-bg)!important;color:var(--text)!important;box-shadow:none!important;border-color:var(--input-border)!important;}
  .avatar{
    width:72px; height:72px; border-radius:18px;
    display:grid; place-items:center; background:var(--chip);
    border:1px solid var(--card-border); font-weight:950; font-size:22px;
    overflow:hidden;
  }
  .avatar img{width:100%; height:100%; object-fit:cover;}
  .toast-wrap{position:fixed; top:18px; left:18px; z-index:20000; width:420px; max-width:calc(100% - 36px);}
</style>
</head>
<body>

<?php if ($msg): ?>
  <div class="toast-wrap">
    <div class="toast align-items-center text-white <?php echo $toast; ?> border-0" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="d-flex">
        <div class="toast-body"><?php echo clean($msg); ?></div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="page">
  <div class="glass card-pad">
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <div class="avatar">
        <?php if ($photo): ?>
          <img src="<?php echo clean($photo); ?>" alt="Profile photo">
        <?php else: ?>
          <?php echo clean($initials); ?>
        <?php endif; ?>
      </div>

      <div>
        <div style="font-weight:950; font-size:22px;"><?php echo clean($displayName ?: $username); ?></div>
        <div class="muted" style="font-weight:900;">@<?php echo clean($username); ?> • <?php echo clean($email); ?></div>
        <div class="mt-2">
          <a class="btn btn-sm btn-main" href="homepage.php" style="border-radius:12px; font-weight:900;">Back to Home</a>
          <a class="btn btn-sm btn-main" href="../tasks/index.php" style="border-radius:12px; font-weight:900;">Open Tasks</a>
          <a class="btn btn-sm btn-main" href="../tasks/calendar.php" style="border-radius:12px; font-weight:900;">Open Calendar</a>
        </div>
      </div>
    </div>

    <hr style="opacity:.2;">

    <!-- Update Profile -->
    <h5 style="font-weight:950;">Edit profile</h5>
    <a class="btn btn-dark" href="reset_password.php" style="border-radius:12px; font-weight:900;">
  Change Password
</a>

    <form method="post" class="d-grid" style="gap:12px; max-width:520px;">
      <input type="hidden" name="csrf" value="<?php echo clean($csrf); ?>">
      <input type="hidden" name="action" value="update_profile">

      <div>
        <label style="font-weight:900; font-size:13px;">Display name (optional)</label>
        <input class="form-control" name="display_name" value="<?php echo clean($displayName); ?>" placeholder="e.g. Joseph Rolfe">
      </div>

      <div>
        <label style="font-weight:900; font-size:13px;">New username</label>
        <input class="form-control" name="username" value="<?php echo clean($username); ?>" required>
      </div>

      <div>
        <label style="font-weight:900; font-size:13px;">Confirm new username</label>
        <input class="form-control" name="confirm_username" value="<?php echo clean($username); ?>" required>
        <div class="muted" style="font-weight:800; font-size:12px; margin-top:6px;">
          This prevents accidental username changes.
        </div>
      </div>

      <button class="btn-main" type="submit">Save profile</button>
    </form>

    <hr style="opacity:.2;">

    <!-- Upload Photo -->
    <h5 style="font-weight:950;">Profile photo</h5>
    <form method="post" enctype="multipart/form-data" class="d-grid" style="gap:12px; max-width:520px;">
      <input type="hidden" name="csrf" value="<?php echo clean($csrf); ?>">
      <input type="hidden" name="action" value="upload_photo">

      <input class="form-control" type="file" name="photo" accept="image/png,image/jpeg,image/webp" required>
      <button class="btn-main" type="submit">Upload photo</button>

      <div class="muted" style="font-weight:800; font-size:12px;">
        Allowed: JPG, PNG, WEBP. Keep it small (under ~2MB is ideal).
      </div>
    </form>

    <hr style="opacity:.2;">

    <!-- Delete Account -->
    <h5 style="font-weight:950; color:#b91c1c;">Delete account</h5>
    <div class="muted" style="font-weight:800; max-width:60ch;">
      This permanently deletes your account and your tasks. This cannot be undone.
    </div>

    <form method="post" onsubmit="return confirm('This will permanently delete your account and tasks. Continue?');"
          class="d-grid mt-2" style="gap:12px; max-width:520px;">
      <input type="hidden" name="csrf" value="<?php echo clean($csrf); ?>">
      <input type="hidden" name="action" value="delete_account">

      <div>
        <label style="font-weight:900; font-size:13px;">Confirm password</label>
        <input class="form-control" type="password" name="password" required>
      </div>

      <button class="btn btn-danger" type="submit" style="border-radius:12px; font-weight:900;">
        Delete my account
      </button>
    </form>
  </div>
</div>

<script>
  // Theme auto + toggle (uses your same localStorage key)
  (function () {
    const root = document.documentElement;
    const saved = localStorage.getItem('theme');
    if (saved) root.setAttribute('data-theme', saved);
  })();

  // Toast show
  (function () {
    const toastElList = [].slice.call(document.querySelectorAll('.toast'));
    const toastList = toastElList.map(t => new bootstrap.Toast(t, { delay: 3000 }));
    toastList.forEach(t => t.show());
  })();
</script>
</body>
</html>
