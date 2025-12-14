<?php
session_start();

if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    
    // Log the logout
    require_once 'config/db.php';
    $action = 'User logged out';
    $ip = $_SERVER['REMOTE_ADDR'];
    $sql = "INSERT INTO system_logs (user_id, action, ip_address) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iss', $user_id, $action, $ip);
    $stmt->execute();
    $conn->close();
}

session_destroy();
header('Location: index.php');
exit();
?>
