<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer into dashboard.php when done</title>
</head>
<body>
    <h2>Task List</h2>
    <a href="add-task.php">Add New Task</a>
    <table>
        <tr>
            <th>Task Name</th>
            <th>Description</th>
            <th>Date Created</th>
            <th>Due Date</th>
            <th>Actions</th>
        </tr>
        <?php
        include 'db_connect_tasks.php';
        $query = "SELECT * FROM list";
        $result = mysqli_query($conn, $query);
        while($row = mysqli_fetch_assoc($result)) {
            echo "<tr>";
            echo "<td>" . $row['task_name'] . "</td>";
            echo "<td>" . $row['task_description'] . "</td>";
            echo "<td>" . $row['created_at'] . "</td>";
            echo "<td>" . $row['end_date'] . "</td>";
            echo "<td><a href='edit-task.php?id=" . $row['id'] . "'>Edit</a> | <a href='delete-task.php?id=" . $row['id'] . "'>Delete</a></td>";
            echo "</tr>";
        }
        ?>
    </table>
</body>
</html>