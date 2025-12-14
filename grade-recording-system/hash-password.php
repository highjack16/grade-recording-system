<?php
// Simple password hasher

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    
    if ($password) {
        $hashed = password_hash($password, PASSWORD_BCRYPT);
        echo "<h2>Password: " . htmlspecialchars($password) . "</h2>";
        echo "<h2>Hashed: " . htmlspecialchars($hashed) . "</h2>";
        echo "<p style='color: green;'>Copy the hashed password above and paste it in phpMyAdmin</p>";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Password Hasher</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #1a1a1a; color: white; }
        input, button { padding: 10px; font-size: 16px; }
        button { background: #0066cc; color: white; border: none; cursor: pointer; }
    </style>
</head>
<body>
    <h1>Password Hasher</h1>
    <form method="POST">
        <input type="text" name="password" placeholder="Enter password" required>
        <button type="submit">Hash Password</button>
    </form>
</body>
</html>