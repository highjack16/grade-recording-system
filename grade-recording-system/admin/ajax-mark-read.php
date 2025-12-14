<?php
require_once '../config/db.php'; // This path assumes db.php is one level up

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    exit('Auth error');
}

$admin_id = $_SESSION['user_id'];

// Update all unread notifications for this admin
$sql = "UPDATE notifications SET is_read = 1 WHERE recipient_id = ? AND is_read = 0";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $admin_id);
$stmt->execute();

if ($stmt->affected_rows >= 0) {
    // We return success even if 0 rows were affected (i.e., nothing to mark as read)
    echo 'success';
} else {
    echo 'error';
}
$stmt->close();
?>