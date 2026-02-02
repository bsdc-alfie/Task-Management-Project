<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Task</title>
</head>
<body>
    <h2>Add New Task</h2>
    <form action="add-task.php" method="POST">
        <label for="task_name">Task Name:</label><br>
        <input type="text" id="task_name" name="task_name" required><br><br>
        
        <label for="description">Description:</label><br>
        <textarea id="description" name="description" required></textarea><br><br>
        
        <label for="due_date">Due Date:</label><br>
        <input type="date" id="due_date" name="due_date" required><br><br>
        
        <input type="submit" value="Add Task">
    </form>
    <?php
    include '../tasks/db_connect_tasks.php';
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $task_name = $_POST['task_name'];
        $task_description = $_POST['description'];
        $task_due_date = $_POST['due_date'];

        $query = "INSERT INTO list (task_name, task_description, end_date) VALUES ('$task_name', '$task_description', '$task_due_date')";
        mysqli_query($conn, $query);
        header("Location: index.php");
        exit();
    }
    ?>
</body>
</html>