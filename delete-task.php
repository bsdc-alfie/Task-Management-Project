<?php 
include 'db_connect_tasks.php';
if (isset($_GET['id'])) {
    $task_id = $_GET['id'];
    $query = "DELETE FROM list WHERE id = $task_id";
    mysqli_query($conn, $query);
    header("Location: index.php");
    exit();
}
?>