<?php
// Logs into localhost
$servername = "localhost";
$username = "root";
$password = "password";
$dbname = "users";

// Creates the connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check said connection
if ($conn->connect_error) {
    die("Connection failed (bowomp): " . $conn->connect_error);
}
?>