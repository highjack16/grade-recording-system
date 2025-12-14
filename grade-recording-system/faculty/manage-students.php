<?php
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'faculty') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$section_id = isset($_GET['section_id']) ? (int)$_GET['section_id'] : null;
$message = '';
$error = '';

// Handle adding student to section
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_student' && $section_id) {
        $student_id = (int)$_POST['student_id'];
        
        // Verify the section belongs to this faculty
        $verify_sql = "SELECT id FROM sections WHERE id = ? AND faculty_id = ?";
        $verify_stmt = $conn->prepare($verify_sql);
        $verify_stmt->bind_param('ii', $section_id, $user_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        
        if ($verify_result->num_rows > 0) {
            // Check if student is already enrolled
            $check_sql = "SELECT id FROM enrollments WHERE student_id = ? AND section_id = ? AND status = 'enrolled'";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('ii', $student_id, $section_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $error = "Student is already enrolled in this section.";
            } else {
                // Add student to section
                $insert_sql = "INSERT INTO enrollments (student_id, section_id, status) VALUES (?, ?, 'enrolled')";
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->bind_param('ii', $student_id, $section_id);
                
                if ($insert_stmt->execute()) {
                    $message = "Student added successfully!";
                } else {
                    $error = "Error adding student: " . $conn->error;
                }
            }
        } else {
            $error = "Unauthorized access to this section.";
        }
    }
    
    // Handle removing student from section
    if ($_POST['action'] === 'remove_student' && $section_id) {
        $enrollment_id = (int)$_POST['enrollment_id'];
        
        // Verify the section belongs to this faculty
        $verify_sql = "SELECT s.id FROM sections s 
                      JOIN enrollments e ON s.id = e.section_id 
                      WHERE e.id = ? AND s.faculty_id = ?";
        $verify_stmt = $conn->prepare($verify_sql);
        $verify_stmt->bind_param('ii', $enrollment_id, $user_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        
        if ($verify_result->num_rows > 0) {
            $delete_sql = "UPDATE enrollments SET status = 'dropped' WHERE id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param('i', $enrollment_id);
            
            if ($delete_stmt->execute()) {
                $message = "Student removed successfully!";
            } else {
                $error = "Error removing student: " . $conn->error;
            }
        } else {
            $error = "Unauthorized access.";
        }
    }
}

// Get faculty's sections
$sections_sql = "SELECT s.id, c.course_code, c.course_name, s.section_name, t.term_name
                FROM sections s
                JOIN courses c ON s.course_id = c.id
                JOIN terms t ON s.term_id = t.id
                WHERE s.faculty_id = ?
                ORDER BY t.start_date DESC, c.course_code";
$sections_stmt = $conn->prepare($sections_sql);
$sections_stmt->bind_param('i', $user_id);
$sections_stmt->execute();
$sections_result = $sections_stmt->get_result();
$sections = $sections_result->fetch_all(MYSQLI_ASSOC);

// Get enrolled students for selected section
$enrolled_students = [];
$available_students = [];

if ($section_id) {
    // Verify section belongs to faculty
    $verify_sql = "SELECT id FROM sections WHERE id = ? AND faculty_id = ?";
    $verify_stmt = $conn->prepare($verify_sql);
    $verify_stmt->bind_param('ii', $section_id, $user_id);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    
    if ($verify_result->num_rows > 0) {
        // Get enrolled students
        $enrolled_sql = "SELECT e.id as enrollment_id, u.id, u.first_name, u.last_name, u.email, e.enrollment_date
                        FROM enrollments e
                        JOIN users u ON e.student_id = u.id
                        WHERE e.section_id = ? AND e.status = 'enrolled'
                        ORDER BY u.first_name, u.last_name";
        $enrolled_stmt = $conn->prepare($enrolled_sql);
        $enrolled_stmt->bind_param('i', $section_id);
        $enrolled_stmt->execute();
        $enrolled_result = $enrolled_stmt->get_result();
        $enrolled_students = $enrolled_result->fetch_all(MYSQLI_ASSOC);
        
        // Get available students (not enrolled in this section)
        $available_sql = "SELECT u.id, u.first_name, u.last_name, u.email
                         FROM users u
                         WHERE u.role = 'student' 
                         AND u.status = 'active'
                         AND u.id NOT IN (
                            SELECT student_id FROM enrollments 
                            WHERE section_id = ? AND status = 'enrolled'
                         )
                         ORDER BY u.first_name, u.last_name";
        $available_stmt = $conn->prepare($available_sql);
        $available_stmt->bind_param('i', $section_id);
        $available_stmt->execute();
        $available_result = $available_stmt->get_result();
        $available_students = $available_result->fetch_all(MYSQLI_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students - Grade Recording System</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .manage-students-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-top: 2rem;
        }
        
        .section-selector {
            grid-column: 1 / -1;
            background: #2a2a3e;
            padding: 1.5rem;
            border-radius: 8px;
            border: 1px solid #3a3a4e;
        }
        
        .section-selector label {
            display: block;
            margin-bottom: 0.5rem;
            color: #e0e0e0;
            font-weight: 500;
        }
        
        .section-selector select {
            width: 100%;
            padding: 0.75rem;
            background: #1a1a2e;
            border: 1px solid #3a3a4e;
            color: #e0e0e0;
            border-radius: 4px;
            font-size: 1rem;
        }
        
        .students-panel {
            background: #2a2a3e;
            padding: 1.5rem;
            border-radius: 8px;
            border: 1px solid #3a3a4e;
        }
        
        .students-panel h3 {
            margin-top: 0;
            color: #e0e0e0;
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }
        
        .students-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .student-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            background: #1a1a2e;
            margin-bottom: 0.5rem;
            border-radius: 4px;
            border: 1px solid #3a3a4e;
        }
        
        .student-info {
            flex: 1;
        }
        
        .student-name {
            color: #e0e0e0;
            font-weight: 500;
            margin: 0;
        }
        
        .student-email {
            color: #a0a0b0;
            font-size: 0.9rem;
            margin: 0.25rem 0 0 0;
        }
        
        .student-date {
            color: #808090;
            font-size: 0.85rem;
            margin-top: 0.25rem;
        }
        
        .btn-action {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        
        .btn-add {
            background: #4a90e2;
            color: white;
        }
        
        .btn-add:hover {
            background: #357abd;
        }
        
        .btn-remove {
            background: #e74c3c;
            color: white;
        }
        
        .btn-remove:hover {
            background: #c0392b;
        }
        
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #808090;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
            grid-column: 1 / -1;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .add-student-form {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .add-student-form select {
            flex: 1;
            padding: 0.75rem;
            background: #1a1a2e;
            border: 1px solid #3a3a4e;
            color: #e0e0e0;
            border-radius: 4px;
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
                <a href="manage-students.php" class="nav-item active">
                    <span class="icon">👥</span> Manage Students
                </a>
                <a href="submit-grades.php" class="nav-item">
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
            <div class="header">
                <h1>Manage Students</h1>
                <p>View and add students to your sections</p>
            </div>
            
            <div class="manage-students-container">
                <?php if ($message): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <div class="section-selector">
                    <label for="section-select">Select Section:</label>
                    <select id="section-select" onchange="window.location.href='manage-students.php?section_id=' + this.value">
                        <option value="">-- Choose a section --</option>
                        <?php foreach ($sections as $section): ?>
                            <option value="<?php echo $section['id']; ?>" <?php echo ($section_id == $section['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($section['course_code'] . ' - ' . $section['section_name'] . ' (' . $section['term_name'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if ($section_id): ?>
                    <div class="students-panel">
                        <h3>Enrolled Students (<?php echo count($enrolled_students); ?>)</h3>
                        <?php if (count($enrolled_students) > 0): ?>
                            <div class="students-list">
                                <?php foreach ($enrolled_students as $student): ?>
                                    <div class="student-item">
                                        <div class="student-info">
                                            <p class="student-name"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></p>
                                            <p class="student-email"><?php echo htmlspecialchars($student['email']); ?></p>
                                            <p class="student-date">Enrolled: <?php echo date('M d, Y', strtotime($student['enrollment_date'])); ?></p>
                                        </div>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Remove this student?');">
                                            <input type="hidden" name="action" value="remove_student">
                                            <input type="hidden" name="enrollment_id" value="<?php echo $student['enrollment_id']; ?>">
                                            <button type="submit" class="btn-action btn-remove">Remove</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">No students enrolled yet</div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="students-panel">
                        <h3>Add Students (<?php echo count($available_students); ?> available)</h3>
                        <?php if (count($available_students) > 0): ?>
                            <form method="POST" class="add-student-form">
                                <input type="hidden" name="action" value="add_student">
                                <select name="student_id" required>
                                    <option value="">-- Select a student --</option>
                                    <?php foreach ($available_students as $student): ?>
                                        <option value="<?php echo $student['id']; ?>">
                                            <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name'] . ' (' . $student['email'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn-action btn-add">Add Student</button>
                            </form>
                        <?php else: ?>
                            <div class="empty-state">All available students are already enrolled</div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state" style="grid-column: 1 / -1;">
                        Select a section to manage students
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
