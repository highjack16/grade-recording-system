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

$message = '';
$error = '';
$current_school_year = date('Y') . '-' . (date('Y') + 1);

function checkScheduleConflict($conn, $section_id, $day, $start_time, $end_time, $exclude_schedule_id = null) {
    // Check if there's an overlapping schedule for the SAME SECTION on the same day
    $sql = "SELECT cs.*, s.section_name, c.course_code 
            FROM course_schedule cs
            JOIN sections s ON cs.section_id = s.id
            JOIN courses c ON s.course_id = c.id
            WHERE cs.section_id = ?
            AND cs.day_of_week = ? 
            AND (
                (? >= cs.start_time AND ? < cs.end_time) OR
                (? > cs.start_time AND ? <= cs.end_time) OR
                (? <= cs.start_time AND ? >= cs.end_time)
            )";
    
    if ($exclude_schedule_id) {
        $sql .= " AND cs.id != ?";
    }
    
    $stmt = $conn->prepare($sql);
    
    if ($exclude_schedule_id) {
        $stmt->bind_param('isssssssi', $section_id, $day, $start_time, $start_time, $end_time, $end_time, $start_time, $end_time, $exclude_schedule_id);
    } else {
        $stmt->bind_param('isssssss', $section_id, $day, $start_time, $start_time, $end_time, $end_time, $start_time, $end_time);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $conflict = $result->fetch_assoc();
        return $conflict;
    }
    
    return false;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add') {
            $course_id = $_POST['course_id'] ?? '';
            $section_name = $_POST['section_name'] ?? '';
            $term_id = $_POST['term_id'] ?? '';
            $max_students = $_POST['max_students'] ?? 50;
            
            // Schedule data
            $days = $_POST['days'] ?? [];
            $start_time = $_POST['start_time'] ?? '';
            $end_time = $_POST['end_time'] ?? '';
            $room_location = $_POST['room_location'] ?? '';
            
            if (empty($course_id) || empty($section_name) || empty($term_id)) {
                $error = 'Course, section name, and term are required';
            } else {
                // Check for duplicate section (same course + section + term)
                $check_sql = "SELECT COUNT(*) as cnt FROM sections 
                             WHERE course_id = ? AND section_name = ? AND term_id = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param('isi', $course_id, $section_name, $term_id);
                $check_stmt->execute();
                $result = $check_stmt->get_result();
                $row = $result->fetch_assoc();
                
                if ($row['cnt'] > 0) {
                    $error = 'This section already exists for this course and term';
                } else {
                    // Insert section (no conflict check needed for new section - it has no schedules yet)
                    $placeholder_faculty_id = 1;
                    
                    $sql = "INSERT INTO sections (course_id, section_name, faculty_id, term_id, max_students) 
                            VALUES (?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param('isiii', $course_id, $section_name, $placeholder_faculty_id, $term_id, $max_students);
                    
                    if ($stmt->execute()) {
                        $section_id = $conn->insert_id;
                        
                        // Add schedule if provided
                        if (!empty($days) && !empty($start_time) && !empty($end_time)) {
                            $schedule_sql = "INSERT INTO course_schedule (section_id, day_of_week, start_time, end_time, room_location) 
                                           VALUES (?, ?, ?, ?, ?)";
                            $schedule_stmt = $conn->prepare($schedule_sql);
                            
                            foreach ($days as $day) {
                                $schedule_stmt->bind_param('issss', $section_id, $day, $start_time, $end_time, $room_location);
                                $schedule_stmt->execute();
                            }
                        }
                        
                        $action = "Added new section: $section_name";
                        $ip = $_SERVER['REMOTE_ADDR'];
                        $log_sql = "INSERT INTO system_logs (user_id, action, ip_address) VALUES (?, ?, ?)";
                        $log_stmt = $conn->prepare($log_sql);
                        $log_stmt->bind_param('iss', $_SESSION['user_id'], $action, $ip);
                        $log_stmt->execute();
                        
                        header("Location: manage-sections.php?success=1");
                        exit();
                    } else {
                        $error = 'Error adding section: ' . $stmt->error;
                    }
                    $stmt->close();
                }
            }
        } elseif ($_POST['action'] === 'delete') {
            $section_id = $_POST['section_id'] ?? '';
            if (!empty($section_id)) {
                $sql = "DELETE FROM sections WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('i', $section_id);
                
                if ($stmt->execute()) {
                    $action = "Deleted section ID: $section_id";
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $log_sql = "INSERT INTO system_logs (user_id, action, ip_address) VALUES (?, ?, ?)";
                    $log_stmt = $conn->prepare($log_sql);
                    $log_stmt->bind_param('iss', $_SESSION['user_id'], $action, $ip);
                    $log_stmt->execute();
                    
                    header("Location: manage-sections.php?deleted=1");
                    exit();
                } else {
                    $error = 'Error deleting section';
                }
                $stmt->close();
            }
        } elseif ($_POST['action'] === 'add_schedule') {
            $section_id = $_POST['section_id'] ?? '';
            $days = $_POST['days'] ?? [];
            $start_time = $_POST['start_time'] ?? '';
            $end_time = $_POST['end_time'] ?? '';
            $room_location = $_POST['room_location'] ?? '';
            
            if (!empty($section_id) && !empty($days) && !empty($start_time) && !empty($end_time)) {
                // Check for time overlap within the same section
                $schedule_conflict = false;
                foreach ($days as $day) {
                    $conflict = checkScheduleConflict($conn, $section_id, $day, $start_time, $end_time);
                    if ($conflict) {
                        $error = "Schedule conflict! This section already has a schedule on {$day} from {$conflict['start_time']} to {$conflict['end_time']}. Times cannot overlap.";
                        $schedule_conflict = true;
                        break;
                    }
                }
                
                if (!$schedule_conflict) {
                    $schedule_sql = "INSERT INTO course_schedule (section_id, day_of_week, start_time, end_time, room_location) 
                                   VALUES (?, ?, ?, ?, ?)";
                    $schedule_stmt = $conn->prepare($schedule_sql);
                    
                    foreach ($days as $day) {
                        $schedule_stmt->bind_param('issss', $section_id, $day, $start_time, $end_time, $room_location);
                        $schedule_stmt->execute();
                    }
                    
                    header("Location: manage-sections.php?schedule_added=1");
                    exit();
                }
            } else {
                $error = 'Please select at least one day, start time, and end time';
            }
        } elseif ($_POST['action'] === 'delete_schedule') {
            $schedule_id = $_POST['schedule_id'] ?? '';
            if (!empty($schedule_id)) {
                $sql = "DELETE FROM course_schedule WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('i', $schedule_id);
                $stmt->execute();
                
                header("Location: manage-sections.php?schedule_deleted=1");
                exit();
            }
        }
    }
}

// Show messages
if (isset($_GET['success'])) $message = 'Section added successfully';
if (isset($_GET['deleted'])) $message = 'Section deleted successfully';
if (isset($_GET['schedule_added'])) $message = 'Schedule added successfully';
if (isset($_GET['schedule_deleted'])) $message = 'Schedule deleted successfully';

// Get all terms
$terms_sql = "SELECT id, term_name FROM terms WHERE status = 'active' ORDER BY start_date ASC";
$terms_result = $conn->query($terms_sql);
$terms = $terms_result->fetch_all(MYSQLI_ASSOC);

// Get all sections with details
$sections_sql = "SELECT s.id, c.course_code, c.course_name, s.section_name, 
                        CASE 
                            WHEN s.faculty_id = 1 THEN 'Not Assigned'
                            ELSE CONCAT(u.first_name, ' ', u.last_name)
                        END as faculty_name,
                        t.term_name, s.max_students, s.status,
                        (SELECT COUNT(*) FROM enrollments WHERE section_id = s.id) as enrolled_count
                 FROM sections s
                 JOIN courses c ON s.course_id = c.id
                 LEFT JOIN users u ON s.faculty_id = u.id AND s.faculty_id != 1
                 JOIN terms t ON s.term_id = t.id
                 ORDER BY t.start_date DESC, c.course_code ASC, s.section_name ASC";
$sections_result = $conn->query($sections_sql);
$sections = $sections_result->fetch_all(MYSQLI_ASSOC);

// Predefined section names
$section_names = ['Section A', 'Section B', 'Section C'];
$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Sections - Grade Recording System</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.8);
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background: #2c2c2c;
            padding: 0;
            border-radius: 8px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow: hidden;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 30px;
            background: #2c2c2c;
            border-bottom: 1px solid #1a1a1a;
        }
        .modal-header h2 {
            margin: 0;
            color: #fff;
            font-size: 20px;
            font-weight: 600;
        }
        .modal-close {
            background: none;
            border: none;
            color: #999;
            font-size: 28px;
            cursor: pointer;
            padding: 0;
            line-height: 1;
            transition: color 0.2s;
        }
        .modal-close:hover {
            color: #fff;
        }
        .modal-body {
            padding: 30px;
            max-height: calc(90vh - 140px);
            overflow-y: auto;
            background: #1a1a1a;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #fff;
            font-size: 14px;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #333;
            border-radius: 4px;
            background: #2c2c2c;
            color: #fff;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #444;
        }
        .form-group input::placeholder {
            color: #666;
        }
        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-top: 8px;
        }
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin: 0;
            cursor: pointer;
        }
        .checkbox-item label {
            margin: 0;
            font-weight: normal;
            cursor: pointer;
            color: #ccc;
            font-size: 14px;
        }
        .modal-footer {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #2c2c2c;
        }
        .schedule-item {
            background: #2c2c2c;
            padding: 15px;
            margin: 10px 0;
            border-radius: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 3px solid #666;
        }
        .schedule-info {
            flex: 1;
        }
        .schedule-info strong {
            color: #fff;
            font-size: 14px;
            font-weight: 600;
        }
        .schedule-info small {
            color: #999;
            display: block;
            margin-top: 4px;
            font-size: 13px;
        }
        .badge {
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-success {
            background: #28a745;
            color: white;
        }
        .badge-warning {
            background: #ffc107;
            color: #000;
        }
        .badge-secondary {
            background: #6c757d;
            color: white;
        }
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #666;
        }
        .empty-state p {
            font-size: 16px;
            margin: 0;
        }
        
        /* Hide scrollbar but keep functionality */
        .modal-body {
            scrollbar-width: none; /* Firefox */
            -ms-overflow-style: none; /* IE and Edge */
        }
        .modal-body::-webkit-scrollbar {
            display: none; /* Chrome, Safari, Opera */
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
                <a href="manage-users.php" class="nav-item">
                    <span class="icon">👥</span> Manage Users
                </a>
                <a href="manage-courses.php" class="nav-item">
                    <span class="icon">📚</span> Manage Courses
                </a>
                <a href="manage-sections.php" class="nav-item active">
                    <span class="icon">📋</span> Manage Sections
                </a>
                <a href="manage-terms.php" class="nav-item">
                    <span class="icon">📅</span> Manage Terms
                </a>
                <a href="manage-grades.php" class="nav-item">
                    <span class="icon">📈</span> Manage Grades
                </a>
                <a href="reports.php" class="nav-item">
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
    $page_title = "Manage Sections";
    $page_subtitle = "Create sections with schedules for each term";
    include '../includes/notification-header.php'; 
    ?>
    
    <div style="margin-bottom: 20px; text-align: right;">
        <button class="btn btn-primary" onclick="openModal('addSectionModal')">+ Add Section</button>
    </div>
            
            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header">
                    <h2>All Sections (<?php echo count($sections); ?>)</h2>
                </div>
                <div class="card-body">
                    <?php if (empty($sections)): ?>
                        <div class="empty-state">
                            <p>📋 No sections found. Create your first section above.</p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>COURSE</th>
                                        <th>SECTION</th>
                                        <th>FACULTY</th>
                                        <th>TERM</th>
                                        <th>ENROLLED</th>
                                        <th>STATUS</th>
                                        <th>ACTIONS</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sections as $section): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($section['course_code']); ?></strong><br>
                                                <small style="color: #888;"><?php echo htmlspecialchars($section['course_name']); ?></small>
                                            </td>
                                            <td><strong><?php echo htmlspecialchars($section['section_name']); ?></strong></td>
                                            <td>
                                                <?php if ($section['faculty_name'] === 'Not Assigned'): ?>
                                                    <span class="badge badge-secondary">Not Assigned</span>
                                                <?php else: ?>
                                                    <?php echo htmlspecialchars($section['faculty_name']); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($section['term_name']); ?></td>
                                            <td>
                                                <?php echo $section['enrolled_count']; ?> / <?php echo $section['max_students']; ?>
                                                <?php if ($section['enrolled_count'] >= $section['max_students']): ?>
                                                    <span class="badge badge-warning">FULL</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?php echo $section['status'] === 'active' ? 'success' : 'warning'; ?>">
                                                    <?php echo strtoupper($section['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-primary" onclick="openScheduleModal(<?php echo $section['id']; ?>, '<?php echo htmlspecialchars($section['course_code'] . ' - ' . $section['section_name'], ENT_QUOTES); ?>')">
                                                    📅 Schedule
                                                </button>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="section_id" value="<?php echo $section['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this section? This will also delete all schedules and enrollments.')">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Section Modal -->
    <div id="addSectionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add New Section</h2>
                <button class="modal-close" onclick="closeModal('addSectionModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="form-group">
                        <label for="term_id">Term *</label>
                        <select id="term_id" name="term_id" required onchange="loadAvailableCourses()">
                            <option value="">Select Term First</option>
                            <?php foreach ($terms as $term): ?>
                                <option value="<?php echo $term['id']; ?>">
                                    <?php echo htmlspecialchars($term['term_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="course_id">Course *</label>
                            <select id="course_id" name="course_id" required disabled>
                                <option value="">Select term first</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="section_name">Section Name *</label>
                            <select id="section_name" name="section_name" required>
                                <option value="">Select Section</option>
                                <?php foreach ($section_names as $name): ?>
                                    <option value="<?php echo $name; ?>"><?php echo $name; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="max_students">Max Students</label>
                        <input type="number" id="max_students" name="max_students" value="50" min="1" max="100">
                    </div>
                    
                    <div class="form-group">
                        <label>Schedule (Optional)</label>
                        <div class="checkbox-group">
                            <?php foreach ($days_of_week as $day): ?>
                                <div class="checkbox-item">
                                    <input type="checkbox" id="day_<?php echo $day; ?>" name="days[]" value="<?php echo $day; ?>">
                                    <label for="day_<?php echo $day; ?>"><?php echo $day; ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="start_time">Start Time</label>
                            <input type="time" id="start_time" name="start_time">
                        </div>
                        
                        <div class="form-group">
                            <label for="end_time">End Time</label>
                            <input type="time" id="end_time" name="end_time">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="room_location">Room</label>
                        <input type="text" id="room_location" name="room_location" placeholder="e.g., Room 301">
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('addSectionModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Section</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Schedule Modal -->
    <div id="scheduleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="scheduleModalTitle">Manage Schedule</h2>
                <button class="modal-close" onclick="closeModal('scheduleModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div id="existingSchedules" style="margin-bottom: 25px;">
                    <h3 style="color: #fff; margin-bottom: 12px; font-size: 16px; font-weight: 600;">Current Schedule</h3>
                    <div id="scheduleList"></div>
                </div>
                
                <h3 style="color: #fff; margin-bottom: 12px; font-size: 16px; font-weight: 600;">Add New Schedule</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="add_schedule">
                    <input type="hidden" id="schedule_section_id" name="section_id">
                    
                    <div class="form-group">
                        <label>Days *</label>
                        <div class="checkbox-group">
                            <?php foreach ($days_of_week as $day): ?>
                                <div class="checkbox-item">
                                    <input type="checkbox" id="schedule_day_<?php echo $day; ?>" name="days[]" value="<?php echo $day; ?>">
                                    <label for="schedule_day_<?php echo $day; ?>"><?php echo $day; ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="schedule_start_time">Start Time *</label>
                            <input type="time" id="schedule_start_time" name="start_time" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="schedule_end_time">End Time *</label>
                            <input type="time" id="schedule_end_time" name="end_time" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="schedule_room_location">Room</label>
                        <input type="text" id="schedule_room_location" name="room_location" placeholder="e.g., Room 301">
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('scheduleModal')">Close</button>
                        <button type="submit" class="btn btn-primary">Add Schedule</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        async function loadAvailableCourses() {
            const termId = document.getElementById('term_id').value;
            const courseSelect = document.getElementById('course_id');
            
            if (!termId) {
                courseSelect.disabled = true;
                courseSelect.innerHTML = '<option value="">Select term first</option>';
                return;
            }
            
            try {
                const response = await fetch(`get_term_courses.php?term_id=${termId}&school_year=<?php echo $current_school_year; ?>`);
                const courses = await response.json();
                
                courseSelect.innerHTML = '<option value="">Select Course</option>';
                courses.forEach(course => {
                    const option = document.createElement('option');
                    option.value = course.id;
                    option.textContent = `${course.course_code} - ${course.course_name}`;
                    courseSelect.appendChild(option);
                });
                
                courseSelect.disabled = false;
            } catch (error) {
                console.error('Error loading courses:', error);
                courseSelect.innerHTML = '<option value="">Error loading courses</option>';
            }
        }
        
        async function openScheduleModal(sectionId, sectionName) {
            document.getElementById('scheduleModalTitle').textContent = 'Schedule for ' + sectionName;
            document.getElementById('schedule_section_id').value = sectionId;
            
            try {
                const response = await fetch('get_schedules.php?section_id=' + sectionId);
                const schedules = await response.json();
                
                const scheduleList = document.getElementById('scheduleList');
                if (schedules.length === 0) {
                    scheduleList.innerHTML = '<p style="color: #888; text-align: center; padding: 20px;">📅 No schedules yet</p>';
                } else {
                    scheduleList.innerHTML = schedules.map(schedule => `
                        <div class="schedule-item">
                            <div class="schedule-info">
                                <strong>${schedule.day_of_week}</strong>
                                <small>${schedule.start_time} - ${schedule.end_time}${schedule.room_location ? ' | ' + schedule.room_location : ''}</small>
                            </div>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="delete_schedule">
                                <input type="hidden" name="schedule_id" value="${schedule.id}">
                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this schedule?')">Delete</button>
                            </form>
                        </div>
                    `).join('');
                }
            } catch (error) {
                console.error('Error loading schedules:', error);
            }
            
            openModal('scheduleModal');
        }
        
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>
