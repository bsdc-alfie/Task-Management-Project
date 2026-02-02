<?php
// Logs into localhost
$servername = "localhost";
$username = "root";
$password = ""; // WHY IS THE PASSWORD NOTHING BY DEFAULT WHO DECIDED THAT???
$dbname = "list";

// Creates the connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check said connection
if ($conn->connect_error) {
    die("Connection failed (bowomp2): " . $conn->connect_error);
}
