<?php
// pages/microsoft_auth.php
// Receives the Microsoft fake-OAuth callback, sets the session, redirects home.
session_start();

$email = trim($_POST['ms_email'] ?? '');

if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['email'] = $email;
}

header('Location: homepage.php');
exit();
