<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Task</title>
</head>
<body>
    <h2>Edit Task</h2>
    <?php
    include 'db_connect_tasks.php';

    if (isset($_GET['id'])) {
        $task_id = $_GET['id'];
        $query = "SELECT * FROM list WHERE id = $task_id";
        $result = mysqli_query($conn, $query);
        $task = mysqli_fetch_assoc($result);
        }
    ?>
    <form action="edit-task.php?id=<?php echo $task_id; ?>" method="POST">
        <label for="task_name">Task Name:</label><br>
        <input type="text" id="task_name" name="task_name" value="<?php echo $task['task_name']; ?>" required>
        
        <label for="description">Description:</label><br>
        <textarea id="description" name="description" required><?php echo $task['task_description']; ?></textarea>
        
        <label for="due_date">Due Date:</label><br>
        <input type="date" id="due_date" name="due_date" value="<?php echo $task['end_date']; ?>" required>
        
        <input type="submit" value="Update Task">
    </form>

    <?php
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $task_name = $_POST['task_name'];
            $task_description = $_POST['description'];
            $task_due_date = $_POST['due_date'];

            $updateQuery = "UPDATE list SET task_name='$task_name', task_description='$task_description', end_date='$task_due_date' WHERE id=$task_id";
            mysqli_query($conn, $updateQuery);
            header("Location: index.php");
            exit();
        }
    ?>
</body>
</html>