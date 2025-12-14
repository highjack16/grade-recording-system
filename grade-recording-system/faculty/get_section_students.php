<?php
require_once '../config/db.php';

header('Content-Type: application/json');

// Check if user is logged in and is faculty
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'faculty') {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$section_id = isset($_GET['section_id']) ? intval($_GET['section_id']) : 0;

if ($section_id === 0) {
    echo json_encode(['error' => 'Invalid section ID']);
    exit();
}

// Verify section belongs to this faculty
$verify_sql = "SELECT id FROM sections WHERE id = ? AND faculty_id = ?";
$verify_stmt = $conn->prepare($verify_sql);
$verify_stmt->bind_param('ii', $section_id, $user_id);
$verify_stmt->execute();
$verify_result = $verify_stmt->get_result();

if ($verify_result->num_rows === 0) {
    echo json_encode(['error' => 'Section not found or unauthorized']);
    exit();
}

// Get enrolled students
$students_sql = "SELECT u.id, u.first_name, u.last_name, u.email, e.enrollment_date,
                        g.final_grade, g.letter_grade
                 FROM enrollments e
                 JOIN users u ON e.student_id = u.id
                 LEFT JOIN grades g ON g.student_id = u.id AND g.section_id = e.section_id
                 WHERE e.section_id = ? AND e.status = 'enrolled'
                 ORDER BY u.last_name ASC, u.first_name ASC";
$students_stmt = $conn->prepare($students_sql);
$students_stmt->bind_param('i', $section_id);
$students_stmt->execute();
$students_result = $students_stmt->get_result();
$students = $students_result->fetch_all(MYSQLI_ASSOC);

echo json_encode(['students' => $students]);
?>