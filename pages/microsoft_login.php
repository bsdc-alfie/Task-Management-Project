<?php
// pages/microsoft_login.php
// Simulates a Microsoft OAuth redirect page.
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign in – Microsoft</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
      background: #f2f2f2;
      min-height: 100vh;
      display: grid;
      place-items: center;
      padding: 16px;
    }

    .ms-card {
      background: #fff;
      padding: 44px 44px 36px;
      width: 100%;
      max-width: 440px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.15);
    }

    .ms-logo {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 24px;
    }
    .ms-squares {
      width: 20px;
      height: 20px;
      display: grid;
      grid-template-columns: repeat(2,1fr);
      grid-template-rows: repeat(2,1fr);
      gap: 2px;
      flex-shrink: 0;
    }
    .ms-squares span:nth-child(1) { background: #f25022; }
    .ms-squares span:nth-child(2) { background: #7fba00; }
    .ms-squares span:nth-child(3) { background: #00a4ef; }
    .ms-squares span:nth-child(4) { background: #ffb900; }
    .ms-logo-text { font-size: 20px; font-weight: 600; color: #1b1b1b; }

    .ms-title { font-size: 24px; font-weight: 600; color: #1b1b1b; margin-bottom: 8px; }
    .ms-sub   { font-size: 14px; color: #444; margin-bottom: 28px; line-height: 1.5; }

    .ms-email-display {
      font-size: 14px;
      color: #1b1b1b;
      font-weight: 600;
      margin-bottom: 20px;
      padding: 0 0 12px;
      border-bottom: 1px solid #e0e0e0;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .ms-email-display svg { flex-shrink: 0; }

    /* Phases */
    .phase { display: none; }
    #phase-redirect { display: flex; flex-direction: column; align-items: center; gap: 18px; padding: 20px 0 12px; }

    .spinner {
      width: 36px; height: 36px;
      border: 3px solid #e0e0e0;
      border-top-color: #0067b8;
      border-radius: 50%;
      animation: spin 0.8s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .redirect-text { font-size: 14px; color: #444; text-align: center; }

    .ms-input {
      width: 100%;
      border: 1px solid #666;
      padding: 8px 12px;
      font-size: 15px;
      font-family: inherit;
      outline: none;
      border-radius: 0;
    }
    .ms-input:focus { border-color: #0067b8; box-shadow: 0 0 0 1px #0067b8; }
    .ms-input-label { font-size: 13px; color: #444; margin-bottom: 4px; display: block; }

    .ms-btn-next {
      background: #0067b8;
      color: #fff;
      border: none;
      padding: 10px 24px;
      font-size: 15px;
      font-family: inherit;
      font-weight: 600;
      cursor: pointer;
      margin-top: 20px;
      float: right;
    }
    .ms-btn-next:hover { background: #005ea2; }
    .ms-btn-next:disabled { background: #a0c4e8; cursor: not-allowed; }

    .ms-footer {
      margin-top: 32px;
      padding-top: 16px;
      border-top: 1px solid #e0e0e0;
      font-size: 12px;
      color: #666;
      display: flex;
      gap: 16px;
      flex-wrap: wrap;
    }
    .ms-footer a { color: #0067b8; text-decoration: none; }
    .ms-footer a:hover { text-decoration: underline; }

    /* Signing-in overlay */
    #phase-signing {
      text-align: center;
      padding: 20px 0;
    }
  </style>
</head>
<body>

<div class="ms-card">

  <div class="ms-logo">
    <div class="ms-squares"><span></span><span></span><span></span><span></span></div>
    <span class="ms-logo-text">Microsoft</span>
  </div>

  <!-- Phase 1: redirecting spinner -->
  <div id="phase-redirect">
    <div class="spinner"></div>
    <div class="redirect-text">Redirecting to Microsoft sign-in&hellip;</div>
  </div>

  <!-- Phase 2: email -->
  <div id="phase-email" class="phase">
    <div class="ms-title">Sign in</div>
    <div class="ms-sub">Use your Microsoft account</div>

    <label class="ms-input-label" for="ms-email">Email, phone, or Skype</label>
    <input class="ms-input" type="email" id="ms-email" autocomplete="email" autofocus>

    <div style="margin-top:12px; font-size:13px; color:#444;">
      No account? <a href="register.php" style="color:#0067b8; font-weight:600; text-decoration:none;">Create one!</a>
    </div>

    <div style="overflow:hidden;">
      <button class="ms-btn-next" id="emailNextBtn" type="button">Next</button>
    </div>
  </div>

  <!-- Phase 3: password -->
  <div id="phase-password" class="phase">
    <div class="ms-title">Enter password</div>

    <div class="ms-email-display">
      <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
        <circle cx="8" cy="5" r="3" stroke="#444" stroke-width="1.5"/>
        <path d="M2 13c0-2.21 2.686-4 6-4s6 1.79 6 4" stroke="#444" stroke-width="1.5" stroke-linecap="round"/>
      </svg>
      <span id="emailDisplay"></span>
    </div>

    <label class="ms-input-label" for="ms-password">Password</label>
    <input class="ms-input" type="password" id="ms-password" autocomplete="current-password">

    <div style="margin-top:10px; font-size:13px;">
      <a href="reset_password.php" style="color:#0067b8; font-weight:600; text-decoration:none;">Forgot password?</a>
    </div>

    <div style="overflow:hidden;">
      <button class="ms-btn-next" id="signInBtn" type="button">Sign in</button>
    </div>
  </div>

  <!-- Phase 4: signing in spinner -->
  <div id="phase-signing" class="phase">
    <div class="spinner" style="margin: 0 auto 16px;"></div>
    <div class="redirect-text">Signing you in&hellip;</div>
  </div>

  <!-- Hidden form that POSTs to microsoft_auth.php -->
  <form id="ms-auth-form" method="POST" action="microsoft_auth.php" style="display:none;">
    <input type="hidden" name="ms_email" id="form-email">
  </form>

  <div class="ms-footer">
    <a href="#">Terms of use</a>
    <a href="#">Privacy &amp; cookies</a>
  </div>
</div>

<script>
  function show(id) {
    ['phase-redirect','phase-email','phase-password','phase-signing'].forEach(function(p) {
      document.getElementById(p).style.display = p === id ? (p === 'phase-redirect' ? 'flex' : 'block') : 'none';
    });
  }

  // Phase 1 → Phase 2 after 1.6 s
  setTimeout(function () {
    show('phase-email');
    document.getElementById('ms-email').focus();
  }, 1600);

  // Phase 2 → Phase 3 (Next or Enter)
  function goToPassword() {
    var email = document.getElementById('ms-email').value.trim();
    if (!email) { document.getElementById('ms-email').focus(); return; }
    document.getElementById('emailDisplay').textContent = email;
    show('phase-password');
    document.getElementById('ms-password').focus();
  }
  document.getElementById('emailNextBtn').addEventListener('click', goToPassword);
  document.getElementById('ms-email').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); goToPassword(); }
  });

  // Phase 3 → Phase 4 → submit (Sign in or Enter)
  function doSignIn() {
    var pw = document.getElementById('ms-password').value;
    if (!pw) { document.getElementById('ms-password').focus(); return; }
    show('phase-signing');
    var email = document.getElementById('emailDisplay').textContent;
    document.getElementById('form-email').value = email;
    setTimeout(function () {
      document.getElementById('ms-auth-form').submit();
    }, 1200);
  }
  document.getElementById('signInBtn').addEventListener('click', doSignIn);
  document.getElementById('ms-password').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); doSignIn(); }
  });
</script>

</body>
</html>
