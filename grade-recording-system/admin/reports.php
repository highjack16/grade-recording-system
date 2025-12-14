<?php
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$first_name = $_SESSION['first_name'];
$last_name = $_SESSION['last_name'];

// Get statistics
$sql = "SELECT COUNT(*) as total FROM users WHERE role = 'student'";
$total_students = $conn->query($sql)->fetch_assoc()['total'];

$sql = "SELECT COUNT(*) as total FROM courses";
$total_courses = $conn->query($sql)->fetch_assoc()['total'];

$sql = "SELECT COUNT(*) as total FROM enrollments WHERE status = 'enrolled'";
$total_enrollments = $conn->query($sql)->fetch_assoc()['total'];

$sql = "SELECT AVG(final_grade) as avg FROM grades WHERE final_grade IS NOT NULL";
$avg_grade = $conn->query($sql)->fetch_assoc()['avg'];

// Get grade distribution
$sql = "SELECT letter_grade, COUNT(*) as count FROM grades WHERE letter_grade IS NOT NULL GROUP BY letter_grade ORDER BY letter_grade";
$result = $conn->query($sql);
$grade_distribution = $result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Grade Recording System</title>
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
                <a href="manage-users.php" class="nav-item">
                    <span class="icon">👥</span> Manage Users
                </a>
                <a href="manage-courses.php" class="nav-item">
                    <span class="icon">📚</span> Manage Courses
                </a>
                <a href="manage-sections.php" class="nav-item">
                    <span class="icon">📋</span> Manage Sections
                </a>
                <a href="manage-terms.php" class="nav-item">
                    <span class="icon">📅</span> Manage Terms
                </a>
                <a href="manage-grades.php" class="nav-item">
                    <span class="icon">📈</span> Manage Grades
                </a>
                <a href="reports.php" class="nav-item active">
                    <span class="icon">📑</span> Reports
                </a>
                <a href="system-logs.php" class="nav-item">
                    <span class="icon">🔍</span> System Logs
                </a>
            </nav>
            
            <div class="sidebar-footer">
                <a href="../logout.php" class="btn btn-logout">Logout</a>
            </div>
        </div>
        
        <div class="main-content">
        <?php 
    $page_title = "System Reports";
    $page_subtitle = "View system statistics and analytics";
    include '../includes/notification-header.php'; 
    ?>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">👥</div>
                    <div class="stat-content">
                        <h3><?php echo $total_students; ?></h3>
                        <p>Total Students</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">📚</div>
                    <div class="stat-content">
                        <h3><?php echo $total_courses; ?></h3>
                        <p>Total Courses</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">📋</div>
                    <div class="stat-content">
                        <h3><?php echo $total_enrollments; ?></h3>
                        <p>Total Enrollments</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">📊</div>
                    <div class="stat-content">
                        <h3><?php echo $avg_grade ? number_format($avg_grade, 2) : 'N/A'; ?></h3>
                        <p>Average Grade</p>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2>Grade Distribution</h2>
                </div>
                <div class="card-body">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Letter Grade</th>
                                <th>Count</th>
                                <th>Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_grades = array_sum(array_column($grade_distribution, 'count'));
                            foreach ($grade_distribution as $dist): 
                                $percentage = $total_grades > 0 ? ($dist['count'] / $total_grades) * 100 : 0;
                            ?>
                                <tr>
                                    <td><?php echo $dist['letter_grade']; ?></td>
                                    <td><?php echo $dist['count']; ?></td>
                                    <td><?php echo number_format($percentage, 2); ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
