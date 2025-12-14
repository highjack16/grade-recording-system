<?php
require_once dirname(__DIR__) . '/config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode([]);
    exit();
}

// Check if this is for getting schedules or courses
if (isset($_GET['section_id'])) {
    // Get schedules for a section
    $section_id = intval($_GET['section_id']);
    
    if ($section_id === 0) {
        echo json_encode([]);
        exit();
    }
    
    $sql = "SELECT id, day_of_week, start_time, end_time, room_location 
            FROM course_schedule 
            WHERE section_id = ? 
            ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), 
                     start_time ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $section_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $schedules = $result->fetch_all(MYSQLI_ASSOC);
    
    // Format times to 12-hour format
    foreach ($schedules as &$schedule) {
        $schedule['start_time'] = date('g:i A', strtotime($schedule['start_time']));
        $schedule['end_time'] = date('g:i A', strtotime($schedule['end_time']));
    }
    
    echo json_encode($schedules);
}
?>