<?php
include '../Task-Management-Project/database/db_connect.php';

$message ="";
$toastClass ="";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $password = $_POST['password'];

    // $stmt->execute(); is in the area where it's prepared and executed, who'da thought
    $stmt = $conn -> prepare("SELECT password FROM userdata WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->bind_result($db_password);
        $stmt->fetch();

        if (password_verify($password, $db_password)) {
            $message = "Login Successful!";
            $toastClass = "bg-success"; // mmmmm green - drugged up alfie
            //This starts a session and sends you to the dashboard
            session_start();
            $_SESSION['email'] = $email;
            header("Location: dashboard.php");
            exit();
        } else{
            $message = "Incorrect password";
            $toastClass = "bg-danger"; //big dummy get your password right
        }
    } else{
        $message = "Email not found";
        $toastClass = "bg-warning"; //yello 
    }

    $stmt->close();
    $conn->close();
}
?>