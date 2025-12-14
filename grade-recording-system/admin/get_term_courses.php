<?php
require_once dirname(__DIR__) . '/config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode([]);
    exit();
}

$term_id = isset($_GET['term_id']) ? intval($_GET['term_id']) : 0;
$school_year = isset($_GET['school_year']) ? $_GET['school_year'] : '';

if ($term_id === 0 || empty($school_year)) {
    echo json_encode([]);
    exit();
}

// Get courses that are in the curriculum for this term and school year
$sql = "SELECT DISTINCT c.id, c.course_code, c.course_name 
        FROM courses c
        INNER JOIN curriculum cur ON c.id = cur.course_id
        WHERE cur.term_id = ? AND cur.school_year = ?
        ORDER BY c.course_code ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param('is', $term_id, $school_year);
$stmt->execute();
$result = $stmt->get_result();
$courses = $result->fetch_all(MYSQLI_ASSOC);

echo json_encode($courses);
?>