<?php
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'faculty') {
    header('Location: ../index.php');
    exit();
}

function sendNotification($conn, $recipient_id, $message, $link = null, $type = 'general', $sender_id = null) {
    $stmt = $conn->prepare("INSERT INTO notifications (recipient_id, sender_id, type, message, link, is_read, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW())");
    $stmt->bind_param('iisss', $recipient_id, $sender_id, $type, $message, $link);
    $stmt->execute();
    $stmt->close();
}

function getAdminIds($conn) {
    $admins = [];
    $result = $conn->query("SELECT id FROM users WHERE role = 'admin'");
    while ($row = $result->fetch_assoc()) {
        $admins[] = $row['id'];
    }
    return $admins;
}

function percentageToGrade($percentage) {
    if ($percentage >= 97) return 1.00;
    if ($percentage >= 94) return 1.25;
    if ($percentage >= 91) return 1.50;
    if ($percentage >= 88) return 1.75;
    if ($percentage >= 85) return 2.00;
    if ($percentage >= 82) return 2.25;
    if ($percentage >= 79) return 2.50;
    if ($percentage >= 76) return 2.75;
    if ($percentage >= 75) return 3.00;
    if ($percentage >= 60) return 4.00; // Conditional
    return 5.00; // Failed
}

function getLetterGrade($grade) {
    if ($grade <= 1.00) return 'A+';
    if ($grade <= 1.25) return 'A';
    if ($grade <= 1.50) return 'A-';
    if ($grade <= 1.75) return 'B+';
    if ($grade <= 2.00) return 'B';
    if ($grade <= 2.25) return 'B-';
    if ($grade <= 2.50) return 'C+';
    if ($grade <= 2.75) return 'C';
    if ($grade <= 3.00) return 'C-';
    if ($grade <= 4.00) return 'INC';
    return 'F';
}

$user_id = $_SESSION['user_id'];
$faculty_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_grades') {
    $section_id = intval($_POST['section_id'] ?? 0);
    $grades_data = $_POST['grades'] ?? [];
    
    if ($section_id > 0 && !empty($grades_data)) {
        $course_info_sql = "SELECT c.course_name, c.course_code FROM sections s JOIN courses c ON s.course_id = c.id WHERE s.id = ?";
        $course_stmt = $conn->prepare($course_info_sql);
        $course_stmt->bind_param('i', $section_id);
        $course_stmt->execute();
        $course_result = $course_stmt->get_result()->fetch_assoc();
        $course_name = $course_result['course_name'] ?? 'Unknown Course';
        $course_code = $course_result['course_code'] ?? '';
        $course_stmt->close();
        
        // Get grading config
        $config_sql = "SELECT config_data FROM grading_config WHERE section_id = ?";
        $config_stmt = $conn->prepare($config_sql);
        $config_stmt->bind_param('i', $section_id);
        $config_stmt->execute();
        $config_result = $config_stmt->get_result();
        
        $default_grading_config = [
            'attendance' => ['weight' => 10, 'total' => 20],
            'recitation' => ['weight' => 10, 'total' => 100],
            'quiz' => ['weight' => 20, 'total' => 100],
            'project' => ['weight' => 10, 'total' => 100],
            'midterm' => ['weight' => 25, 'total' => 100],
            'final' => ['weight' => 25, 'total' => 100]
        ];
        $grading_config = $default_grading_config;
        
        if ($config_result->num_rows > 0) {
            $config_row = $config_result->fetch_assoc();
            $decoded_config = json_decode($config_row['config_data'], true);
            if ($decoded_config !== null && is_array($decoded_config)) {
                $grading_config = array_merge($default_grading_config, $decoded_config);
                foreach ($default_grading_config as $key => $defaults) {
                    if (!isset($grading_config[$key]) || !is_array($grading_config[$key])) {
                        $grading_config[$key] = $defaults;
                    } else {
                        $grading_config[$key] = array_merge($defaults, $grading_config[$key]);
                    }
                }
            }
        }
        
        $success_count = 0;
        $error_count = 0;
        $graded_students = []; // Track graded students for notifications
        
        foreach ($grades_data as $student_id => $grade_values) {
            $attendance_score = isset($grade_values['attendance']) && $grade_values['attendance'] !== '' ? floatval($grade_values['attendance']) : null;
            $recitation_score = isset($grade_values['recitation']) && $grade_values['recitation'] !== '' ? floatval($grade_values['recitation']) : null;
            $quiz_score = isset($grade_values['quiz_score']) && $grade_values['quiz_score'] !== '' ? floatval($grade_values['quiz_score']) : null;
            $quiz_total = isset($grade_values['quiz_total']) && $grade_values['quiz_total'] !== '' ? intval($grade_values['quiz_total']) : null;
            $project_score = isset($grade_values['project_score']) && $grade_values['project_score'] !== '' ? floatval($grade_values['project_score']) : null;
            $project_total = isset($grade_values['project_total']) && $grade_values['project_total'] !== '' ? intval($grade_values['project_total']) : null;
            $midterm_score = isset($grade_values['midterm_score']) && $grade_values['midterm_score'] !== '' ? floatval($grade_values['midterm_score']) : null;
            $midterm_total = isset($grade_values['midterm_total']) && $grade_values['midterm_total'] !== '' ? intval($grade_values['midterm_total']) : null;
            $final_score = isset($grade_values['final_score']) && $grade_values['final_score'] !== '' ? floatval($grade_values['final_score']) : null;
            $final_total = isset($grade_values['final_total']) && $grade_values['final_total'] !== '' ? intval($grade_values['final_total']) : null;
            
            // Calculate weighted total percentage
            $weighted_total = 0;
            
            if ($attendance_score !== null && $grading_config['attendance']['total'] > 0) {
                $pct = ($attendance_score / $grading_config['attendance']['total']) * 100;
                $weighted_total += ($pct * $grading_config['attendance']['weight']) / 100;
            }
            
            if ($recitation_score !== null && $grading_config['recitation']['total'] > 0) {
                $pct = ($recitation_score / $grading_config['recitation']['total']) * 100;
                $weighted_total += ($pct * $grading_config['recitation']['weight']) / 100;
            }
            
            if ($quiz_score !== null && $quiz_total !== null && $quiz_total > 0) {
                $pct = ($quiz_score / $quiz_total) * 100;
                $weighted_total += ($pct * $grading_config['quiz']['weight']) / 100;
            }
            
            if ($project_score !== null && $project_total !== null && $project_total > 0) {
                $pct = ($project_score / $project_total) * 100;
                $weighted_total += ($pct * $grading_config['project']['weight']) / 100;
            }
            
            if ($midterm_score !== null && $midterm_total !== null && $midterm_total > 0) {
                $pct = ($midterm_score / $midterm_total) * 100;
                $weighted_total += ($pct * $grading_config['midterm']['weight']) / 100;
            }
            
            if ($final_score !== null && $final_total !== null && $final_total > 0) {
                $pct = ($final_score / $final_total) * 100;
                $weighted_total += ($pct * $grading_config['final']['weight']) / 100;
            }
            
            $final_grade = percentageToGrade($weighted_total);
            $letter_grade = getLetterGrade($final_grade);
            
            // Store detailed scores as JSON
            $detailed_scores = json_encode([
                'attendance' => $attendance_score,
                'recitation' => $recitation_score,
                'quiz_score' => $quiz_score,
                'quiz_total' => $quiz_total,
                'project_score' => $project_score,
                'project_total' => $project_total,
                'midterm_score' => $midterm_score,
                'midterm_total' => $midterm_total,
                'final_score' => $final_score,
                'final_total' => $final_total,
                'weighted_percentage' => round($weighted_total, 2)
            ]);
            
            // Check if grade exists
            $check_sql = "SELECT id FROM grades WHERE student_id = ? AND section_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('ii', $student_id, $section_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $update_sql = "UPDATE grades SET final_grade = ?, letter_grade = ?, detailed_scores = ?, updated_at = NOW() WHERE student_id = ? AND section_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param('dssii', $final_grade, $letter_grade, $detailed_scores, $student_id, $section_id);
                if ($update_stmt->execute()) {
                    $success_count++;
                    $graded_students[] = [
                        'id' => $student_id,
                        'letter_grade' => $letter_grade,
                        'final_grade' => $final_grade
                    ];
                } else {
                    $error_count++;
                }
            } else {
                $insert_sql = "INSERT INTO grades (student_id, section_id, final_grade, letter_grade, detailed_scores, created_at) VALUES (?, ?, ?, ?, ?, NOW())";
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->bind_param('iidss', $student_id, $section_id, $final_grade, $letter_grade, $detailed_scores);
                if ($insert_stmt->execute()) {
                    $success_count++;
                    $graded_students[] = [
                        'id' => $student_id,
                        'letter_grade' => $letter_grade,
                        'final_grade' => $final_grade
                    ];
                } else {
                    $error_count++;
                }
            }
        }
        
        foreach ($graded_students as $student) {
            $student_message = "Your grade for {$course_code} - {$course_name} has been posted by Prof. {$faculty_name}. You received: {$student['letter_grade']}";
            sendNotification($conn, $student['id'], $student_message, 'student/view-grades.php', 'grade', $user_id);
        }
        
        if ($success_count > 0) {
            $admin_ids = getAdminIds($conn);
            $admin_message = "Prof. {$faculty_name} submitted grades for {$success_count} student(s) in {$course_code} - {$course_name}";
            foreach ($admin_ids as $admin_id) {
                sendNotification($conn, $admin_id, $admin_message, 'admin/manage-grades.php', 'grade', $user_id);
            }
        }
        
        if ($success_count > 0) {
            $message = "Successfully saved grades for $success_count student(s).";
        }
        if ($error_count > 0) {
            $error = "Failed to save grades for $error_count student(s).";
        }
    }
}

// Fetch faculty's sections
$sections_sql = "SELECT s.id, s.section_name, c.course_code, c.course_name, t.term_name
                 FROM sections s 
                 JOIN courses c ON s.course_id = c.id 
                 JOIN terms t ON s.term_id = t.id 
                 WHERE s.faculty_id = ? 
                 ORDER BY t.start_date DESC, c.course_code";
$sections_stmt = $conn->prepare($sections_sql);
$sections_stmt->bind_param('i', $user_id);
$sections_stmt->execute();
$sections = $sections_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$selected_section = isset($_GET['section_id']) ? intval($_GET['section_id']) : (count($sections) > 0 ? $sections[0]['id'] : 0);

// Fetch students for selected section
$students = [];
$grading_config = [
    'attendance' => ['weight' => 10, 'total' => 20],
    'recitation' => ['weight' => 10, 'total' => 100],
    'quiz' => ['weight' => 20, 'total' => 100],
    'project' => ['weight' => 10, 'total' => 100],
    'midterm' => ['weight' => 25, 'total' => 100],
    'final' => ['weight' => 25, 'total' => 100]
];

if ($selected_section > 0) {
    // Get grading config for this section
    $config_sql = "SELECT config_data FROM grading_config WHERE section_id = ?";
    $config_stmt = $conn->prepare($config_sql);
    $config_stmt->bind_param('i', $selected_section);
    $config_stmt->execute();
    $config_result = $config_stmt->get_result();
    
    if ($config_result->num_rows > 0) {
        $config_row = $config_result->fetch_assoc();
        $decoded_config = json_decode($config_row['config_data'], true);
        if ($decoded_config !== null && is_array($decoded_config)) {
            $grading_config = array_merge($grading_config, $decoded_config);
        }
    }
    
    // Get enrolled students with their grades
    $students_sql = "SELECT u.id as student_id, CONCAT(u.first_name, ' ', u.last_name) as student_name, 
                     u.email, g.final_grade, g.letter_grade, g.detailed_scores
                     FROM enrollments e
                     JOIN users u ON e.student_id = u.id
                     LEFT JOIN grades g ON g.student_id = u.id AND g.section_id = e.section_id
                     WHERE e.section_id = ? AND e.status = 'enrolled'
                     ORDER BY u.last_name, u.first_name";
    $students_stmt = $conn->prepare($students_sql);
    $students_stmt->bind_param('i', $selected_section);
    $students_stmt->execute();
    $students = $students_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Grades - Grade Recording System</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        /* Theme variables matching dashboard.php */
        :root {
            --dark-bg-1: #121212;
            --dark-bg-2: #1e1e1e;
            --dark-border: #333;
            --text-light: #e0e0e0;
            --text-muted: #aaa;
            --accent-green: #4ade80;
            --accent-red: #f87171;
            --accent-blue: #3b82f6;
            --accent-orange: #fb923c;
            --surface: #1e1e1e;
            --surface-light: #2a2a2a;
            --surface-lighter: #333;
        }

        body {
            background-color: var(--dark-bg-1);
            color: var(--text-light);
        }

        /* Page header matching dashboard */
        .page-header-main {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }

        .page-header-main h1 {
            color: var(--text-light);
            margin: 0;
            font-size: 1.8rem;
        }

        .page-header-main p {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin: 4px 0 0 0;
        }

        /* Alert messages */
        .alert {
            padding: 14px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-success {
            background-color: rgba(74, 222, 128, 0.15);
            border: 1px solid var(--accent-green);
            color: var(--accent-green);
        }

        .alert-error {
            background-color: rgba(248, 113, 113, 0.15);
            border: 1px solid var(--accent-red);
            color: var(--accent-red);
        }

        /* Section selector card */
        .selector-card {
            background-color: var(--dark-bg-2);
            border: 1px solid var(--dark-border);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
        }

        .selector-card h3 {
            margin: 0 0 20px 0;
            font-size: 1.1rem;
            color: var(--text-light);
        }

        .selector-inline {
            display: flex;
            gap: 20px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .selector-group {
            flex: 1;
            min-width: 300px;
        }

        .selector-group label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        .selector-group select {
            width: 100%;
            padding: 12px 16px;
            background-color: var(--surface-light);
            border: 1px solid var(--dark-border);
            border-radius: 8px;
            color: var(--text-light);
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .selector-group select:hover {
            border-color: #444;
        }

        .selector-group select:focus {
            outline: none;
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .btn-load {
            background-color: var(--accent-blue);
            color: white;
            border: none;
            padding: 12px 28px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .btn-load:hover {
            background-color: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        /* Configuration panel */
        .config-panel {
            background-color: var(--dark-bg-2);
            border: 1px solid var(--dark-border);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
        }

        .config-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--dark-border);
        }

        .config-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-light);
            margin: 0;
        }

        .config-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .config-item {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .config-item label {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .config-inputs {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .config-input {
            padding: 10px 12px;
            background-color: var(--surface-light);
            border: 1px solid var(--dark-border);
            border-radius: 6px;
            color: var(--text-light);
            font-size: 14px;
            width: 70px;
            text-align: center;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .config-input:focus {
            outline: none;
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .input-suffix {
            font-size: 13px;
            color: var(--text-muted);
            font-weight: 600;
        }

        .config-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 20px;
            border-top: 1px solid var(--dark-border);
        }

        .total-weight-display {
            font-size: 15px;
            font-weight: 700;
            padding: 12px 20px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .total-weight-valid {
            background-color: rgba(74, 222, 128, 0.15);
            color: var(--accent-green);
            border: 1px solid var(--accent-green);
        }

        .total-weight-invalid {
            background-color: rgba(248, 113, 113, 0.15);
            color: var(--accent-red);
            border: 1px solid var(--accent-red);
        }

        .btn-save-config {
            background-color: var(--accent-green);
            color: #121212;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-save-config:hover:not(:disabled) {
            background-color: #22c55e;
            transform: translateY(-1px);
        }

        .btn-save-config:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Grading table matching reference screenshot */
        .grades-panel {
            background-color: var(--dark-bg-2);
            border: 1px solid var(--dark-border);
            border-radius: 12px;
            overflow: hidden;
        }

        .grades-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 25px;
            border-bottom: 1px solid var(--dark-border);
        }

        .grades-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-light);
            margin: 0;
        }

        .student-count {
            background-color: var(--surface-light);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            color: var(--text-muted);
        }

        .table-wrapper {
            overflow-x: auto;
        }

        .grading-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .grading-table thead {
            position: sticky;
            top: 0;
            z-index: 10;
            background-color: var(--surface-light);
        }

        .grading-table th {
            padding: 14px 12px;
            text-align: left;
            font-weight: 700;
            color: var(--text-light);
            border-bottom: 2px solid var(--dark-border);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            background-color: var(--surface-light);
        }

        .grading-table th.col-student {
            min-width: 200px;
            position: sticky;
            left: 0;
            z-index: 11;
            border-right: 1px solid var(--dark-border);
        }

        .grading-table td {
            padding: 12px;
            border-bottom: 1px solid var(--dark-border);
            color: var(--text-muted);
            background-color: var(--surface);
            vertical-align: middle;
        }

        .grading-table td.col-student {
            position: sticky;
            left: 0;
            z-index: 5;
            background-color: var(--surface);
            border-right: 1px solid var(--dark-border);
        }

        .grading-table tbody tr:hover td {
            background-color: var(--surface-light);
        }

        .grading-table tbody tr:hover td.col-student {
            background-color: var(--surface-light);
        }

        /* Student info cell matching reference */
        .student-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .student-load-bar {
            width: 4px;
            height: 36px;
            border-radius: 2px;
            flex-shrink: 0;
        }

        .load-excellent { background-color: var(--accent-green); }
        .load-good { background-color: var(--accent-blue); }
        .load-average { background-color: var(--accent-orange); }
        .load-poor { background-color: var(--accent-red); }

        .student-details {
            flex: 1;
            min-width: 0;
        }

        .student-name {
            font-weight: 600;
            color: var(--text-light);
            font-size: 13px;
            margin-bottom: 2px;
        }

        .student-email {
            font-size: 11px;
            color: var(--text-muted);
        }

        /* Grade input fields */
        .grade-input {
            width: 60px;
            padding: 8px 10px;
            background-color: var(--surface-lighter);
            border: 1px solid var(--dark-border);
            border-radius: 6px;
            color: var(--text-light);
            font-size: 13px;
            font-weight: 600;
            text-align: center;
            transition: all 0.2s ease;
        }

        .grade-input:focus {
            outline: none;
            border-color: var(--accent-blue);
            background-color: var(--surface-light);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .grade-input:hover {
            border-color: #444;
        }

        .grade-input.has-value {
            border-color: var(--accent-blue);
            background-color: rgba(59, 130, 246, 0.1);
        }

        .grade-pair {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .grade-separator {
            color: var(--text-muted);
            font-weight: 600;
        }

        /* Calculated grade display */
        .calculated-cell {
            text-align: center;
        }

        .grade-badge {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            padding: 8px 14px;
            border-radius: 8px;
            min-width: 70px;
        }

        .grade-value {
            font-size: 16px;
            font-weight: 700;
        }

        .grade-percent {
            font-size: 11px;
            margin-top: 2px;
            opacity: 0.8;
        }

        .grade-badge.excellent {
            background-color: rgba(74, 222, 128, 0.2);
            color: var(--accent-green);
            border: 1px solid var(--accent-green);
        }

        .grade-badge.verygood {
            background-color: rgba(59, 130, 246, 0.2);
            color: var(--accent-blue);
            border: 1px solid var(--accent-blue);
        }

        .grade-badge.good {
            background-color: rgba(251, 146, 60, 0.2);
            color: var(--accent-orange);
            border: 1px solid var(--accent-orange);
        }

        .grade-badge.pass {
            background-color: rgba(234, 179, 8, 0.2);
            color: #eab308;
            border: 1px solid #eab308;
        }

        .grade-badge.conditional {
            background-color: rgba(248, 113, 113, 0.2);
            color: var(--accent-red);
            border: 1px solid var(--accent-red);
        }

        .grade-badge.failed {
            background-color: rgba(239, 68, 68, 0.3);
            color: #ef4444;
            border: 1px solid #ef4444;
        }

        .grade-badge.empty {
            background-color: var(--surface-light);
            color: var(--text-muted);
            border: 1px solid var(--dark-border);
        }

        /* Table footer with actions */
        .grades-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 25px;
            border-top: 1px solid var(--dark-border);
            background-color: var(--surface);
        }

        .btn-secondary {
            background-color: transparent;
            color: var(--text-muted);
            border: 1px solid var(--dark-border);
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-secondary:hover {
            border-color: var(--text-muted);
            color: var(--text-light);
        }

        .btn-submit {
            background-color: var(--accent-green);
            color: #121212;
            border: none;
            padding: 12px 32px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-submit:hover {
            background-color: #22c55e;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(74, 222, 128, 0.3);
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }

        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }

        .empty-state h3 {
            color: var(--text-light);
            margin-bottom: 8px;
        }

        /* Column header with weight info */
        .th-content {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .th-weight {
            font-size: 10px;
            color: var(--accent-blue);
            font-weight: 600;
        }

        .th-total {
            font-size: 10px;
            color: var(--text-muted);
            font-weight: 500;
        }
    </style>
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
                <a href="submit-grades.php" class="nav-item active">
                    <span class="icon">✏️</span> Submit Grades
                </a>
                <a href="view-grades.php" class="nav-item">
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
            <div class="page-header-main">
                <div class="header-left">
                    <h1>Submit Grades</h1>
                    <p>Enter and manage student grades for your sections</p>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <!-- Section Selector -->
            <div class="selector-card">
                <h3>Select Section</h3>
                <form method="GET" action="submit-grades.php" class="selector-inline">
                    <div class="selector-group">
                        <label>Course & Section</label>
                        <select name="section_id" required>
                            <option value="">-- Select a section --</option>
                            <?php foreach ($sections as $section): ?>
                                <option value="<?php echo $section['id']; ?>" <?php echo $selected_section == $section['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($section['course_code'] . ' - ' . $section['course_name'] . ' (' . $section['section_name'] . ') - ' . $section['term_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn-load">Load Students</button>
                </form>
            </div>

            <?php if ($selected_section > 0): ?>
                <!-- Grading Configuration -->
                <div class="config-panel">
                    <form method="POST" action="submit-grades.php?section_id=<?php echo $selected_section; ?>" id="configForm">
                        <input type="hidden" name="action" value="save_grading_config">
                        <input type="hidden" name="section_id" value="<?php echo $selected_section; ?>">
                        
                        <div class="config-header">
                            <h3>Grading Configuration</h3>
                            <span style="font-size: 12px; color: var(--text-muted);">Set the weight (%) and total items for each component</span>
                        </div>

                        <div class="config-grid">
                            <div class="config-item">
                                <label>Attendance</label>
                                <div class="config-inputs">
                                    <input type="number" name="attendance_weight" class="config-input weight-input" 
                                           value="<?php echo $grading_config['attendance']['weight'] ?? 10; ?>" 
                                           min="0" max="100" step="0.1" required>
                                    <span class="input-suffix">%</span>
                                    <input type="number" name="attendance_total" class="config-input" 
                                           value="<?php echo $grading_config['attendance']['total'] ?? 20; ?>" 
                                           min="1" step="1" placeholder="Total" required>
                                    <span class="input-suffix">days</span>
                                </div>
                            </div>

                            <div class="config-item">
                                <label>Recitation</label>
                                <div class="config-inputs">
                                    <input type="number" name="recitation_weight" class="config-input weight-input" 
                                           value="<?php echo $grading_config['recitation']['weight'] ?? 10; ?>" 
                                           min="0" max="100" step="0.1" required>
                                    <span class="input-suffix">%</span>
                                    <input type="number" name="recitation_total" class="config-input" 
                                           value="<?php echo $grading_config['recitation']['total'] ?? 100; ?>" 
                                           min="1" step="1" placeholder="Total" required>
                                    <span class="input-suffix">pts</span>
                                </div>
                            </div>

                            <div class="config-item">
                                <label>Quiz</label>
                                <div class="config-inputs">
                                    <input type="number" name="quiz_weight" class="config-input weight-input" 
                                           value="<?php echo $grading_config['quiz']['weight'] ?? 20; ?>" 
                                           min="0" max="100" step="0.1" required>
                                    <span class="input-suffix">%</span>
                                    <input type="number" name="quiz_total" class="config-input" 
                                           value="<?php echo $grading_config['quiz']['total'] ?? 100; ?>" 
                                           min="1" step="1" placeholder="Default" required>
                                    <span class="input-suffix">default</span>
                                </div>
                            </div>

                            <div class="config-item">
                                <label>Project</label>
                                <div class="config-inputs">
                                    <input type="number" name="project_weight" class="config-input weight-input" 
                                           value="<?php echo $grading_config['project']['weight'] ?? 10; ?>" 
                                           min="0" max="100" step="0.1" required>
                                    <span class="input-suffix">%</span>
                                    <input type="number" name="project_total" class="config-input" 
                                           value="<?php echo $grading_config['project']['total'] ?? 100; ?>" 
                                           min="1" step="1" placeholder="Default" required>
                                    <span class="input-suffix">default</span>
                                </div>
                            </div>

                            <div class="config-item">
                                <label>Midterm Exam</label>
                                <div class="config-inputs">
                                    <input type="number" name="midterm_weight" class="config-input weight-input" 
                                           value="<?php echo $grading_config['midterm']['weight'] ?? 25; ?>" 
                                           min="0" max="100" step="0.1" required>
                                    <span class="input-suffix">%</span>
                                    <input type="number" name="midterm_total" class="config-input" 
                                           value="<?php echo $grading_config['midterm']['total'] ?? 60; ?>" 
                                           min="1" step="1" placeholder="Items" required>
                                    <span class="input-suffix">items</span>
                                </div>
                            </div>

                            <div class="config-item">
                                <label>Final Exam</label>
                                <div class="config-inputs">
                                    <input type="number" name="final_weight" class="config-input weight-input" 
                                           value="<?php echo $grading_config['final']['weight'] ?? 25; ?>" 
                                           min="0" max="100" step="0.1" required>
                                    <span class="input-suffix">%</span>
                                    <input type="number" name="final_total" class="config-input" 
                                           value="<?php echo $grading_config['final']['total'] ?? 100; ?>" 
                                           min="1" step="1" placeholder="Items" required>
                                    <span class="input-suffix">items</span>
                                </div>
                            </div>
                        </div>

                        <div class="config-footer">
                            <div class="total-weight-display" id="totalWeightDisplay">Total: 0%</div>
                            <button type="submit" class="btn-save-config" id="saveConfigBtn">Save Configuration</button>
                        </div>
                    </form>
                </div>

                <!-- Student Grades Table -->
                <div class="grades-panel">
                    <form method="POST" action="submit-grades.php?section_id=<?php echo $selected_section; ?>" id="gradesForm">
                        <input type="hidden" name="action" value="submit_grades">
                        <input type="hidden" name="section_id" value="<?php echo $selected_section; ?>">

                        <div class="grades-header">
                            <h3>Student Grades</h3>
                            <span class="student-count"><?php echo count($students); ?> Students</span>
                        </div>

                        <?php if (empty($students)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">👥</div>
                                <h3>No Students Enrolled</h3>
                                <p>There are no students enrolled in this section yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-wrapper">
                                <table class="grading-table">
                                    <thead>
                                        <tr>
                                            <th class="col-student">Student</th>
                                            <th>
                                                <div class="th-content">
                                                    <span>Attendance</span>
                                                    <span class="th-weight"><?php echo $grading_config['attendance']['weight']; ?>%</span>
                                                    <span class="th-total">/ <?php echo $grading_config['attendance']['total']; ?></span>
                                                </div>
                                            </th>
                                            <th>
                                                <div class="th-content">
                                                    <span>Recitation</span>
                                                    <span class="th-weight"><?php echo $grading_config['recitation']['weight']; ?>%</span>
                                                    <span class="th-total">/ <?php echo $grading_config['recitation']['total']; ?></span>
                                                </div>
                                            </th>
                                            <th>
                                                <div class="th-content">
                                                    <span>Quiz</span>
                                                    <span class="th-weight"><?php echo $grading_config['quiz']['weight']; ?>%</span>
                                                    <span class="th-total">Score / Total</span>
                                                </div>
                                            </th>
                                            <th>
                                                <div class="th-content">
                                                    <span>Project</span>
                                                    <span class="th-weight"><?php echo $grading_config['project']['weight']; ?>%</span>
                                                    <span class="th-total">Score / Total</span>
                                                </div>
                                            </th>
                                            <th>
                                                <div class="th-content">
                                                    <span>Midterm</span>
                                                    <span class="th-weight"><?php echo $grading_config['midterm']['weight']; ?>%</span>
                                                    <span class="th-total">Score / Total</span>
                                                </div>
                                            </th>
                                            <th>
                                                <div class="th-content">
                                                    <span>Final</span>
                                                    <span class="th-weight"><?php echo $grading_config['final']['weight']; ?>%</span>
                                                    <span class="th-total">Score / Total</span>
                                                </div>
                                            </th>
                                            <th style="text-align: center;">
                                                <div class="th-content">
                                                    <span>Grade</span>
                                                    <span class="th-total">Auto-calculated</span>
                                                </div>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($students as $student): 
                                            $existing = json_decode($student['detailed_scores'] ?? '{}', true);
                                            $current_pct = $existing['weighted_percentage'] ?? 0;
                                            
                                            // Determine load color based on current grade
                                            if ($student['final_grade'] !== null) {
                                                if ($student['final_grade'] <= 1.50) $load_class = 'load-excellent';
                                                elseif ($student['final_grade'] <= 2.50) $load_class = 'load-good';
                                                elseif ($student['final_grade'] <= 3.00) $load_class = 'load-average';
                                                else $load_class = 'load-poor';
                                            } else {
                                                $load_class = 'load-average';
                                            }
                                        ?>
                                            <tr data-student-id="<?php echo $student['student_id']; ?>">
                                                <td class="col-student">
                                                    <div class="student-info">
                                                        <div class="student-load-bar <?php echo $load_class; ?>"></div>
                                                        <div class="student-details">
                                                            <div class="student-name"><?php echo htmlspecialchars($student['student_name']); ?></div>
                                                            <div class="student-email"><?php echo htmlspecialchars($student['email']); ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <input type="number" 
                                                           name="grades[<?php echo $student['student_id']; ?>][attendance]" 
                                                           class="grade-input <?php echo isset($existing['attendance']) ? 'has-value' : ''; ?>"
                                                           value="<?php echo $existing['attendance'] ?? ''; ?>"
                                                           min="0" 
                                                           max="<?php echo $grading_config['attendance']['total']; ?>" 
                                                           step="0.5"
                                                           placeholder="0">
                                                </td>
                                                <td>
                                                    <input type="number" 
                                                           name="grades[<?php echo $student['student_id']; ?>][recitation]" 
                                                           class="grade-input <?php echo isset($existing['recitation']) ? 'has-value' : ''; ?>"
                                                           value="<?php echo $existing['recitation'] ?? ''; ?>"
                                                           min="0" 
                                                           max="<?php echo $grading_config['recitation']['total']; ?>" 
                                                           step="0.1"
                                                           placeholder="0">
                                                </td>
                                                <td>
                                                    <div class="grade-pair">
                                                        <input type="number" 
                                                               name="grades[<?php echo $student['student_id']; ?>][quiz_score]" 
                                                               class="grade-input <?php echo isset($existing['quiz_score']) ? 'has-value' : ''; ?>"
                                                               value="<?php echo $existing['quiz_score'] ?? ''; ?>"
                                                               min="0" step="0.5" placeholder="0">
                                                        <span class="grade-separator">/</span>
                                                        <input type="number" 
                                                               name="grades[<?php echo $student['student_id']; ?>][quiz_total]" 
                                                               class="grade-input <?php echo isset($existing['quiz_total']) ? 'has-value' : ''; ?>"
                                                               value="<?php echo $existing['quiz_total'] ?? $grading_config['quiz']['total']; ?>"
                                                               min="1" step="1" placeholder="<?php echo $grading_config['quiz']['total']; ?>">
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="grade-pair">
                                                        <input type="number" 
                                                               name="grades[<?php echo $student['student_id']; ?>][project_score]" 
                                                               class="grade-input <?php echo isset($existing['project_score']) ? 'has-value' : ''; ?>"
                                                               value="<?php echo $existing['project_score'] ?? ''; ?>"
                                                               min="0" step="0.5" placeholder="0">
                                                        <span class="grade-separator">/</span>
                                                        <input type="number" 
                                                               name="grades[<?php echo $student['student_id']; ?>][project_total]" 
                                                               class="grade-input <?php echo isset($existing['project_total']) ? 'has-value' : ''; ?>"
                                                               value="<?php echo $existing['project_total'] ?? $grading_config['project']['total']; ?>"
                                                               min="1" step="1" placeholder="<?php echo $grading_config['project']['total']; ?>">
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="grade-pair">
                                                        <input type="number" 
                                                               name="grades[<?php echo $student['student_id']; ?>][midterm_score]" 
                                                               class="grade-input <?php echo isset($existing['midterm_score']) ? 'has-value' : ''; ?>"
                                                               value="<?php echo $existing['midterm_score'] ?? ''; ?>"
                                                               min="0" step="0.5" placeholder="0">
                                                        <span class="grade-separator">/</span>
                                                        <input type="number" 
                                                               name="grades[<?php echo $student['student_id']; ?>][midterm_total]" 
                                                               class="grade-input <?php echo isset($existing['midterm_total']) ? 'has-value' : ''; ?>"
                                                               value="<?php echo $existing['midterm_total'] ?? $grading_config['midterm']['total']; ?>"
                                                               min="1" step="1" placeholder="<?php echo $grading_config['midterm']['total']; ?>">
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="grade-pair">
                                                        <input type="number" 
                                                               name="grades[<?php echo $student['student_id']; ?>][final_score]" 
                                                               class="grade-input <?php echo isset($existing['final_score']) ? 'has-value' : ''; ?>"
                                                               value="<?php echo $existing['final_score'] ?? ''; ?>"
                                                               min="0" step="0.5" placeholder="0">
                                                        <span class="grade-separator">/</span>
                                                        <input type="number" 
                                                               name="grades[<?php echo $student['student_id']; ?>][final_total]" 
                                                               class="grade-input <?php echo isset($existing['final_total']) ? 'has-value' : ''; ?>"
                                                               value="<?php echo $existing['final_total'] ?? $grading_config['final']['total']; ?>"
                                                               min="1" step="1" placeholder="<?php echo $grading_config['final']['total']; ?>">
                                                    </div>
                                                </td>
                                                <td class="calculated-cell">
                                                    <?php
                                                    $grade_class = 'empty';
                                                    $grade_display = '--';
                                                    $pct_display = '';
                                                    
                                                    if ($student['final_grade'] !== null) {
                                                        $grade_display = number_format($student['final_grade'], 2);
                                                        $pct_display = number_format($current_pct, 1) . '%';
                                                        
                                                        if ($student['final_grade'] <= 1.50) $grade_class = 'excellent';
                                                        elseif ($student['final_grade'] <= 2.00) $grade_class = 'verygood';
                                                        elseif ($student['final_grade'] <= 2.50) $grade_class = 'good';
                                                        elseif ($student['final_grade'] <= 3.00) $grade_class = 'pass';
                                                        elseif ($student['final_grade'] <= 4.00) $grade_class = 'conditional';
                                                        else $grade_class = 'failed';
                                                    }
                                                    ?>
                                                    <div class="grade-badge <?php echo $grade_class; ?>" data-grade-display>
                                                        <span class="grade-value"><?php echo $grade_display; ?></span>
                                                        <?php if ($pct_display): ?>
                                                            <span class="grade-percent"><?php echo $pct_display; ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="grades-footer">
                                <button type="button" class="btn-secondary" onclick="resetForm()">Reset Changes</button>
                                <button type="submit" class="btn-submit">Save All Grades</button>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Configuration weight calculator
        const weightInputs = document.querySelectorAll('.weight-input');
        const totalWeightDisplay = document.getElementById('totalWeightDisplay');
        const saveConfigBtn = document.getElementById('saveConfigBtn');

        function updateTotalWeight() {
            if (!totalWeightDisplay) return;
            
            let total = 0;
            weightInputs.forEach(input => {
                total += parseFloat(input.value) || 0;
            });

            totalWeightDisplay.textContent = `Total: ${total.toFixed(1)}%`;
            
            if (Math.abs(total - 100) < 0.1) {
                totalWeightDisplay.className = 'total-weight-display total-weight-valid';
                if (saveConfigBtn) saveConfigBtn.disabled = false;
            } else {
                totalWeightDisplay.className = 'total-weight-display total-weight-invalid';
                if (saveConfigBtn) saveConfigBtn.disabled = true;
            }
        }

        weightInputs.forEach(input => {
            input.addEventListener('input', updateTotalWeight);
        });

        updateTotalWeight();

        // Grade calculation
        const gradeInputs = document.querySelectorAll('.grading-table input[type="number"]');
        
        gradeInputs.forEach(input => {
            input.addEventListener('input', function() {
                if (this.value) {
                    this.classList.add('has-value');
                } else {
                    this.classList.remove('has-value');
                }
                calculateRowGrade(this.closest('tr'));
            });
        });

        // Grading config from PHP
        const weights = {
            attendance: <?php echo json_encode($grading_config['attendance']['weight'] ?? 0); ?>,
            recitation: <?php echo json_encode($grading_config['recitation']['weight'] ?? 0); ?>,
            quiz: <?php echo json_encode($grading_config['quiz']['weight'] ?? 0); ?>,
            project: <?php echo json_encode($grading_config['project']['weight'] ?? 0); ?>,
            midterm: <?php echo json_encode($grading_config['midterm']['weight'] ?? 0); ?>,
            final: <?php echo json_encode($grading_config['final']['weight'] ?? 0); ?>
        };

        const totals = {
            attendance: <?php echo json_encode($grading_config['attendance']['total'] ?? 0); ?>,
            recitation: <?php echo json_encode($grading_config['recitation']['total'] ?? 0); ?>
        };

        function calculateRowGrade(row) {
            if (!row) return;
            
            const studentId = row.dataset.studentId;
            let weightedTotal = 0;
            let hasAnyGrade = false;

            // Attendance
            const attendance = parseFloat(row.querySelector(`input[name="grades[${studentId}][attendance]"]`)?.value);
            if (!isNaN(attendance) && totals.attendance > 0) {
                hasAnyGrade = true;
                const pct = (attendance / totals.attendance) * 100;
                weightedTotal += (pct * weights.attendance) / 100;
            }

            // Recitation
            const recitation = parseFloat(row.querySelector(`input[name="grades[${studentId}][recitation]"]`)?.value);
            if (!isNaN(recitation) && totals.recitation > 0) {
                hasAnyGrade = true;
                const pct = (recitation / totals.recitation) * 100;
                weightedTotal += (pct * weights.recitation) / 100;
            }

            // Quiz
            const quizScore = parseFloat(row.querySelector(`input[name="grades[${studentId}][quiz_score]"]`)?.value);
            const quizTotal = parseFloat(row.querySelector(`input[name="grades[${studentId}][quiz_total]"]`)?.value);
            if (!isNaN(quizScore) && !isNaN(quizTotal) && quizTotal > 0) {
                hasAnyGrade = true;
                const pct = (quizScore / quizTotal) * 100;
                weightedTotal += (pct * weights.quiz) / 100;
            }

            // Project
            const projectScore = parseFloat(row.querySelector(`input[name="grades[${studentId}][project_score]"]`)?.value);
            const projectTotal = parseFloat(row.querySelector(`input[name="grades[${studentId}][project_total]"]`)?.value);
            if (!isNaN(projectScore) && !isNaN(projectTotal) && projectTotal > 0) {
                hasAnyGrade = true;
                const pct = (projectScore / projectTotal) * 100;
                weightedTotal += (pct * weights.project) / 100;
            }

            // Midterm
            const midtermScore = parseFloat(row.querySelector(`input[name="grades[${studentId}][midterm_score]"]`)?.value);
            const midtermTotal = parseFloat(row.querySelector(`input[name="grades[${studentId}][midterm_total]"]`)?.value);
            if (!isNaN(midtermScore) && !isNaN(midtermTotal) && midtermTotal > 0) {
                hasAnyGrade = true;
                const pct = (midtermScore / midtermTotal) * 100;
                weightedTotal += (pct * weights.midterm) / 100;
            }

            // Final
            const finalScore = parseFloat(row.querySelector(`input[name="grades[${studentId}][final_score]"]`)?.value);
            const finalTotal = parseFloat(row.querySelector(`input[name="grades[${studentId}][final_total]"]`)?.value);
            if (!isNaN(finalScore) && !isNaN(finalTotal) && finalTotal > 0) {
                hasAnyGrade = true;
                const pct = (finalScore / finalTotal) * 100;
                weightedTotal += (pct * weights.final) / 100;
            }

            const gradeDisplay = row.querySelector('[data-grade-display]');
            
            if (hasAnyGrade && gradeDisplay) {
                const grade = percentageToGrade(weightedTotal);
                const gradeClass = getGradeClass(grade);
                
                gradeDisplay.innerHTML = `
                    <span class="grade-value">${grade.toFixed(2)}</span>
                    <span class="grade-percent">${weightedTotal.toFixed(1)}%</span>
                `;
                gradeDisplay.className = `grade-badge ${gradeClass}`;
                
                // Update the load bar color
                const loadBar = row.querySelector('.student-load-bar');
                if (loadBar) {
                    loadBar.className = 'student-load-bar';
                    if (grade <= 1.50) loadBar.classList.add('load-excellent');
                    else if (grade <= 2.50) loadBar.classList.add('load-good');
                    else if (grade <= 3.00) loadBar.classList.add('load-average');
                    else loadBar.classList.add('load-poor');
                }
            } else if (gradeDisplay) {
                gradeDisplay.innerHTML = '<span class="grade-value">--</span>';
                gradeDisplay.className = 'grade-badge empty';
            }
        }

        function percentageToGrade(percentage) {
            if (percentage >= 96) return 1.00;
            if (percentage >= 94) return 1.25;
            if (percentage >= 91) return 1.50;
            if (percentage >= 89) return 1.75;
            if (percentage >= 86) return 2.00;
            if (percentage >= 83) return 2.25;
            if (percentage >= 80) return 2.50;
            if (percentage >= 77) return 2.75;
            if (percentage >= 75) return 3.00;
            if (percentage >= 70) return 4.00;
            return 5.00;
        }

        function getGradeClass(grade) {
            if (grade <= 1.50) return 'excellent';
            if (grade <= 2.00) return 'verygood';
            if (grade <= 2.50) return 'good';
            if (grade <= 3.00) return 'pass';
            if (grade <= 4.00) return 'conditional';
            return 'failed';
        }

        function resetForm() {
            if (confirm('Are you sure you want to reset all grade inputs? This will clear unsaved changes.')) {
                document.getElementById('gradesForm').reset();
                document.querySelectorAll('.grade-input').forEach(input => {
                    input.classList.remove('has-value');
                });
                document.querySelectorAll('[data-grade-display]').forEach(display => {
                    display.innerHTML = '<span class="grade-value">--</span>';
                    display.className = 'grade-badge empty';
                });
                document.querySelectorAll('.student-load-bar').forEach(bar => {
                    bar.className = 'student-load-bar load-average';
                });
            }
        }
    </script>
</body>
</html>
