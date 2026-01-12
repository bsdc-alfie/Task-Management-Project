<?php
// start new
session_start();

// reset
$_SESSION = array();

// kill session
session_destroy();

// go back to login
header("Location: login.php");
exit();
?>