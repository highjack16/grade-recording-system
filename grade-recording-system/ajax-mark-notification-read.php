<?php
// ajax-mark-notification-read.php
// Marks a single notification as read when clicked

session_start();
require_once 'config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo 'error';
    exit;
}

$user_id = $_SESSION['user_id'];

// Check if notification_id was sent
if (!isset($_POST['notification_id']) || empty($_POST['notification_id'])) {
    echo 'error';
    exit;
}

$notification_id = intval($_POST['notification_id']);

// Update the specific notification to mark it as read
$stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND recipient_id = ?");
$stmt->bind_param('ii', $notification_id, $user_id);

if ($stmt->execute()) {
    echo 'success';
} else {
    echo 'error';
}

$stmt->close();
$conn->close();
?>