<?php
require_once '../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'faculty') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';
$selected_section_id = isset($_GET['section_id']) ? (int)$_GET['section_id'] : null;

// Get current school year
$current_school_year = date('Y') . '-' . (date('Y') + 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_enroll') {
    $section_id = (int)$_POST['section_id'];

    // Verify section belongs to this faculty
    $verify_sql = "SELECT s.id, s.term_id FROM sections s WHERE s.id = ? AND s.faculty_id = ?";
    $verify_stmt = $conn->prepare($verify_sql);
    $verify_stmt->bind_param('ii', $section_id, $user_id);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();

    if ($verify_result->num_rows > 0) {
        $section_data = $verify_result->fetch_assoc();
        $term_id = $section_data['term_id'];
        
        $enrolled_count = 0;
        $skipped_count = 0;
        $conflicts = 0;

        foreach ($_POST as $key => $value) {
            if (strpos($key, 'student_') === 0 && $value === 'on') {
                $student_id = (int)str_replace('student_', '', $key);

                // Check if already enrolled in this section
                $check_sql = "SELECT id FROM enrollments WHERE student_id = ? AND section_id = ? AND status = 'enrolled'";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param('ii', $student_id, $section_id);
                $check_stmt->execute();
                
                if ($check_stmt->get_result()->num_rows === 0) {
                    // Check for schedule conflicts with other courses in same term
                    $conflict_sql = "SELECT COUNT(*) as conflict_count FROM enrollments e
                                    JOIN sections s ON e.section_id = s.id
                                    WHERE e.student_id = ? AND s.term_id = ? AND e.status = 'enrolled'
                                    AND s.id != ?";
                    $conflict_stmt = $conn->prepare($conflict_sql);
                    $conflict_stmt->bind_param('iii', $student_id, $term_id, $section_id);
                    $conflict_stmt->execute();
                    $conflict_result = $conflict_stmt->get_result();
                    $conflict_row = $conflict_result->fetch_assoc();
                    
                    // For now, allow enrollment (schedule conflict checking can be enhanced)
                    $insert_sql = "INSERT INTO enrollments (student_id, section_id, status) VALUES (?, ?, 'enrolled')";
                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->bind_param('ii', $student_id, $section_id);

                    if ($insert_stmt->execute()) {
                        $enrolled_count++;
                    } else {
                        $error .= "Failed to enroll student ID $student_id: " . $insert_stmt->error . "<br>";
                    }
                } else {
                    $skipped_count++;
                }
            }
        }

        if ($enrolled_count > 0) {
            $message = "✅ Successfully enrolled <strong>$enrolled_count</strong> student(s)!";
            if ($skipped_count > 0) {
                $message .= " <br>($skipped_count already enrolled)";
            }
        } else {
            $error = ($skipped_count > 0 ? "$skipped_count students were already enrolled." : "No new students were enrolled.");
        }
    } else {
        $error = "❌ Unauthorized access to this section.";
    }
}

// Get current sections for this faculty
$sections_sql = "SELECT s.id, s.section_name, c.course_code, c.course_name, t.term_name, t.id as term_id,
                        COUNT(e.id) as enrolled_count, s.max_students
                 FROM sections s
                 JOIN courses c ON s.course_id = c.id
                 JOIN terms t ON s.term_id = t.id
                 LEFT JOIN enrollments e ON s.id = e.section_id AND e.status = 'enrolled'
                 WHERE s.faculty_id = ?
                 GROUP BY s.id
                 ORDER BY t.start_date DESC, c.course_code";
$sections_stmt = $conn->prepare($sections_sql);
$sections_stmt->bind_param('i', $user_id);
$sections_stmt->execute();
$sections_result = $sections_stmt->get_result();
$sections = $sections_result->fetch_all(MYSQLI_ASSOC);

// Get available and enrolled students for selected section
$available_students = [];
$enrolled_students = [];
$section_term_id = null;

if ($selected_section_id) {
    // Get section term
    $term_sql = "SELECT term_id FROM sections WHERE id = ? AND faculty_id = ?";
    $term_stmt = $conn->prepare($term_sql);
    $term_stmt->bind_param('ii', $selected_section_id, $user_id);
    $term_stmt->execute();
    $term_result = $term_stmt->get_result();
    if ($term_result->num_rows > 0) {
        $term_data = $term_result->fetch_assoc();
        $section_term_id = $term_data['term_id'];
    }
    
    // Enrolled students
    $enrolled_sql = "SELECT u.id, u.first_name, u.last_name, u.email, e.enrollment_date
                     FROM enrollments e
                     JOIN users u ON e.student_id = u.id
                     WHERE e.section_id = ? AND e.status = 'enrolled'
                     ORDER BY u.first_name, u.last_name";
    $enrolled_stmt = $conn->prepare($enrolled_sql);
    $enrolled_stmt->bind_param('i', $selected_section_id);
    $enrolled_stmt->execute();
    $enrolled_result = $enrolled_stmt->get_result();
    $enrolled_students = $enrolled_result->fetch_all(MYSQLI_ASSOC);

    // Available students (active + not enrolled in this section)
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
    $available_stmt->bind_param('i', $selected_section_id);
    $available_stmt->execute();
    $available_result = $available_stmt->get_result();
    $available_students = $available_result->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enroll Students - Grade Recording System</title>
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
                <a href="enroll-students.php" class="nav-item active">
                    <span class="icon">👥</span> Enroll Students
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
                <div>
                    <h1>Enroll Students</h1>
                    <p>Bulk enroll students into your course sections</p>
                </div>
            </div>
            
            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo $message; ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <!-- Section Selection -->
            <div class="card">
                <div class="card-header">
                    <h2>Select Your Section</h2>
                </div>
                <div class="card-body">
                    <form method="GET" style="display: flex; gap: 15px; align-items: flex-end;">
                        <div style="display: flex; flex-direction: column; flex: 1;">
                            <label for="section_id" style="font-weight: 500; margin-bottom: 5px;">Choose Section:</label>
                            <select id="section_id" name="section_id" onchange="this.form.submit()" style="padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                                <option value="">-- Select a section --</option>
                                <?php foreach ($sections as $section): ?>
                                    <option value="<?php echo $section['id']; ?>" <?php echo $selected_section_id == $section['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($section['course_code'] . ' - ' . $section['section_name'] . ' (' . $section['term_name'] . ') [' . $section['enrolled_count'] . '/' . $section['max_students'] . ']'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                </div>
            </div>
            
            <?php if ($selected_section_id && count($available_students) > 0): ?>
                <form method="POST" id="enrollment-form">
                    <input type="hidden" name="action" value="bulk_enroll">
                    <input type="hidden" name="section_id" value="<?php echo $selected_section_id; ?>">
                    
                    <div style="background: rgba(68, 136, 255, 0.1); border-left: 4px solid #0088ff; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                        <strong>Available Students:</strong> <?php echo count($available_students); ?> | 
                        <strong>Currently Enrolled:</strong> <?php echo count($enrolled_students); ?> | 
                        <strong>Selected:</strong> <span id="selected-count" style="background: #ff4444; color: white; padding: 2px 8px; border-radius: 12px; font-weight: 600;">0</span>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <h2>Available Students for Enrollment</h2>
                        </div>
                        <div class="card-body">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th style="width: 50px;">
                                            <input type="checkbox" id="select-all" onchange="toggleSelectAll(this)">
                                        </th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($available_students as $student): ?>
                                        <tr>
                                            <td style="text-align: center;">
                                                <input type="checkbox" name="student_<?php echo $student['id']; ?>" class="student-checkbox" onchange="updateSelectedCount()">
                                            </td>
                                            <td><strong><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($student['email']); ?></td>
                                            <td><span class="badge" style="background: #d1ecf1; color: #0c5460; padding: 4px 8px; border-radius: 3px;">Available</span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                        <button type="button" class="btn btn-secondary" onclick="clearSelection()" style="padding: 10px 20px;">Clear Selection</button>
                        <button type="submit" class="btn btn-primary" style="padding: 10px 20px;">Enroll Selected Students</button>
                    </div>
                </form>
            <?php elseif ($selected_section_id && count($available_students) === 0): ?>
                <div class="card">
                    <div class="card-body">
                        <div class="empty-state">
                            <p>All available students are already enrolled in this section.</p>
                        </div>
                    </div>
                </div>
            <?php elseif ($selected_section_id): ?>
                <div class="card">
                    <div class="card-body">
                        <div class="empty-state">
                            <p>No students available for enrollment.</p>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="card-body">
                        <div class="empty-state">
                            <p>Select a section above to view available students for enrollment.</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Enrolled Students Section -->
            <?php if ($selected_section_id && count($enrolled_students) > 0): ?>
                <div class="card" style="margin-top: 30px;">
                    <div class="card-header">
                        <h2>Currently Enrolled Students (<?php echo count($enrolled_students); ?>)</h2>
                    </div>
                    <div class="card-body">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Enrollment Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($enrolled_students as $student): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($student['email']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($student['enrollment_date'])); ?></td>
                                        <td><span class="badge" style="background: #d4edda; color: #155724; padding: 4px 8px; border-radius: 3px;">Enrolled</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function toggleSelectAll(checkbox) {
            const studentCheckboxes = document.querySelectorAll('.student-checkbox');
            studentCheckboxes.forEach(cb => {
                cb.checked = checkbox.checked;
            });
            updateSelectedCount();
        }

        function updateSelectedCount() {
            const selectedCount = document.querySelectorAll('.student-checkbox:checked').length;
            document.getElementById('selected-count').textContent = selectedCount;

            const allCheckboxes = document.querySelectorAll('.student-checkbox');
            document.getElementById('select-all').checked = selectedCount === allCheckboxes.length && allCheckboxes.length > 0;
        }

        function clearSelection() {
            document.querySelectorAll('.student-checkbox').forEach(cb => {
                cb.checked = false;
            });
            document.getElementById('select-all').checked = false;
            updateSelectedCount();
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            updateSelectedCount();
        });
    </script>
</body>
</html>