<?php
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get announcements for sections the student is enrolled in
$announcements_sql = "
    SELECT 
        a.id,
        a.title,
        a.content,
        a.created_at,
        CONCAT(u.first_name, ' ', u.last_name) AS faculty_name,
        s.section_name,
        c.course_code,
        c.course_name
    FROM announcements a
    JOIN sections s ON a.section_id = s.id
    JOIN courses c ON s.course_id = c.id
    JOIN users u ON a.faculty_id = u.id
    JOIN enrollments e ON s.id = e.section_id
    WHERE e.student_id = ? AND e.status = 'enrolled'
    ORDER BY a.created_at DESC
";
$announcements_stmt = $conn->prepare($announcements_sql);
$announcements_stmt->bind_param('i', $user_id);
$announcements_stmt->execute();
$announcements_result = $announcements_stmt->get_result();
$announcements = $announcements_result->fetch_all(MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements - Grade Recording System</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="container-fluid">
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>GRS</h2>
                <p>Grade Recording</p>
            </div>
            
            <nav class="sidebar-nav">
                <a href="../dashboard.php" class="nav-item">
                    <span class="icon">📊</span> Dashboard
                </a>
                <a href="my-courses.php" class="nav-item">
                    <span class="icon">📚</span> My Courses
                </a>
                <a href="view-grades.php" class="nav-item">
                    <span class="icon">📊</span> My Grades
                </a>
                <a href="announcement.php" class="nav-item active">
                    <span class="icon">📢</span> Announcements
                </a>
                <a href="transcript.php" class="nav-item">
                    <span class="icon">📄</span> Transcript
                </a>
            </nav>
            
            <div class="sidebar-footer">
                <a href="../logout.php" class="btn btn-logout">Logout</a>
            </div>
        </div>
        
        <div class="main-content">
            <div class="header">
                <h1>Announcements</h1>
                <p>View announcements from your courses</p>
            </div>
            
            <div class="announcements-container">
                <?php if (empty($announcements)): ?>
                    <div class="empty-state">
                        <p>No announcements yet</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($announcements as $announcement): ?>
                        <div class="announcement-card">
                            <div class="announcement-header">
                                <div class="announcement-meta">
                                    <h3><?php echo htmlspecialchars($announcement['title']); ?></h3>
                                    <p class="announcement-course"><?php echo htmlspecialchars($announcement['course_code']); ?> - <?php echo htmlspecialchars($announcement['section_name']); ?></p>
                                </div>
                                <span class="announcement-time"><?php echo date('M d, Y g:i A', strtotime($announcement['created_at'])); ?></span>
                            </div>
                            <div class="announcement-body">
                                <p class="announcement-faculty">From: <strong><?php echo htmlspecialchars($announcement['faculty_name']); ?></strong></p>
                                <p class="announcement-content"><?php echo nl2br(htmlspecialchars($announcement['content'])); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
