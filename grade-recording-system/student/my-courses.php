<?php
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];

$sql = "SELECT s.id, c.course_code, c.course_name, s.section_name, CONCAT(u.first_name, ' ', u.last_name) as faculty_name, t.term_name
        FROM enrollments e
        JOIN sections s ON e.section_id = s.id
        JOIN courses c ON s.course_id = c.id
        JOIN users u ON s.faculty_id = u.id
        JOIN terms t ON s.term_id = t.id
        WHERE e.student_id = ? AND e.status = 'enrolled'
        ORDER BY t.start_date DESC, c.course_code";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
$courses = $result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Courses - Grade Recording System</title>
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
                <a href="my-courses.php" class="nav-item active">
                    <span class="icon">📚</span> My Courses
                </a>
                <a href="view-grades.php" class="nav-item">
                    <span class="icon">📊</span> My Grades
                </a>
                <a href="announcement.php" class="nav-item">
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
                <h1>My Courses</h1>
                <p>View your enrolled courses</p>
            </div>
            
            <div class="courses-grid">
                <?php foreach ($courses as $course): ?>
                    <div class="course-card">
                        <div class="course-header">
                            <h3><?php echo htmlspecialchars($course['course_code']); ?></h3>
                            <p class="course-term"><?php echo htmlspecialchars($course['term_name']); ?></p>
                        </div>
                        <div class="course-body">
                            <p class="course-name"><?php echo htmlspecialchars($course['course_name']); ?></p>
                            <p class="course-section">Section: <?php echo htmlspecialchars($course['section_name']); ?></p>
                            <p class="course-faculty">Faculty: <?php echo htmlspecialchars($course['faculty_name']); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</body>
</html>
