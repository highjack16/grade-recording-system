<?php
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'faculty') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];

$sql = "
    SELECT 
        g.id, 
        CONCAT(u.first_name, ' ', u.last_name) AS student_name, 
        c.course_code, 
        s.section_name,
        COALESCE(g.final_grade, 0) AS final_grade,
        COALESCE(g.letter_grade, '') AS letter_grade,
        g.detailed_scores,
        COALESCE(g.updated_at, g.created_at) AS submitted_date
    FROM grades g
    JOIN users u ON g.student_id = u.id
    JOIN sections s ON g.section_id = s.id
    JOIN courses c ON s.course_id = c.id
    WHERE s.faculty_id = ?
      AND g.final_grade IS NOT NULL 
      AND g.final_grade > 0
    ORDER BY c.course_code, s.section_name, u.first_name
";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
$grades = $result->fetch_all(MYSQLI_ASSOC);

foreach ($grades as &$grade) {
    $detailed = json_decode($grade['detailed_scores'], true);
    if ($detailed) {
        $grade['quiz_score'] = isset($detailed['quiz_score']) ? $detailed['quiz_score'] : null;
        $grade['midterm_score'] = isset($detailed['midterm_score']) ? $detailed['midterm_score'] : null;
        $grade['final_score'] = isset($detailed['final_score']) ? $detailed['final_score'] : null;
    } else {
        $grade['quiz_score'] = null;
        $grade['midterm_score'] = null;
        $grade['final_score'] = null;
    }
}
unset($grade);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Grades - Grade Recording System</title>
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
                <a href="enroll-students.php" class="nav-item">
                    <span class="icon">👥</span> Enroll Students
                </a>
                <a href="submit-grades.php" class="nav-item">
                    <span class="icon">✏️</span> Submit Grades
                </a>
                <a href="view-grades.php" class="nav-item active">
                    <span class="icon">📊</span> View Grades
                </a>
                <a href="announcements.php" class="nav-item">
                    <span class="icon">📢</span> Announcements
                </a>
            </nav>
            
            <div class="sidebar-footer">
                <a href="../logout.php" class="btn btn-logout">Logout</a>
            </div>
        </div>
        
        <div class="main-content">
            <div class="header">
                <h1>View Grades</h1>
                <p>Review submitted student grades</p>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2>All Submitted Grades</h2>
                </div>
                <div class="card-body">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Course</th>
                                <th>Section</th>
                                <th>Quiz</th>
                                <th>Midterm</th>
                                <th>Final</th>
                                <th>Overall Grade</th>
                                <th>Letter</th>
                                <th>Submitted</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grades as $grade): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($grade['student_name']); ?></td>
                                    <td><?php echo htmlspecialchars($grade['course_code']); ?></td>
                                    <td><?php echo htmlspecialchars($grade['section_name']); ?></td>
                                    <td><?php echo ($grade['quiz_score'] !== null) ? number_format($grade['quiz_score'], 0) : 'N/A'; ?></td>
                                    <td><?php echo ($grade['midterm_score'] !== null) ? number_format($grade['midterm_score'], 0) : 'N/A'; ?></td>
                                    <td><?php echo ($grade['final_score'] !== null) ? number_format($grade['final_score'], 0) : 'N/A'; ?></td>
                                    <td><?php echo $grade['final_grade'] > 0 ? number_format($grade['final_grade'], 2) : 'N/A'; ?></td>
                                    <td><?php echo $grade['letter_grade'] ?: 'N/A'; ?></td>
                                    <td><?php echo $grade['submitted_date'] ? date('M d, Y', strtotime($grade['submitted_date'])) : 'N/A'; ?></td>
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
