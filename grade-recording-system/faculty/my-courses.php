<?php
require_once '../config/db.php';

// Check if user is logged in and is faculty
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

if ($_SESSION['role'] !== 'faculty') {
    header('Location: ../dashboard.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle faculty assigning themselves to a section
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'assign_section') {
            $section_id = $_POST['section_id'] ?? '';
            
            if (!empty($section_id)) {
                // Check if section is available (not assigned or assigned to placeholder)
                $check_sql = "SELECT faculty_id FROM sections WHERE id = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param('i', $section_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $section = $check_result->fetch_assoc();
                
                if ($section && ($section['faculty_id'] == 1 || $section['faculty_id'] == null)) {
                    // Assign faculty to section
                    $update_sql = "UPDATE sections SET faculty_id = ? WHERE id = ?";
                    $update_stmt = $conn->prepare($update_sql);
                    $update_stmt->bind_param('ii', $user_id, $section_id);
                    
                    if ($update_stmt->execute()) {
                        $message = 'Section assigned successfully';
                    } else {
                        $error = 'Error assigning section';
                    }
                } else {
                    $error = 'This section is already assigned to another faculty';
                }
            }
        } elseif ($_POST['action'] === 'unassign_section') {
            $section_id = $_POST['section_id'] ?? '';
            
            if (!empty($section_id)) {
                // Unassign faculty from section (set back to placeholder)
                $placeholder_faculty_id = 1;
                $update_sql = "UPDATE sections SET faculty_id = ? WHERE id = ? AND faculty_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param('iii', $placeholder_faculty_id, $section_id, $user_id);
                
                if ($update_stmt->execute()) {
                    $message = 'Section unassigned successfully';
                } else {
                    $error = 'Error unassigning section';
                }
            }
        }
    }
}

// Get faculty's assigned courses
$my_courses_sql = "SELECT s.id, c.course_code, c.course_name, s.section_name, t.term_name, 
                          (SELECT COUNT(*) FROM enrollments WHERE section_id = s.id) as student_count
                   FROM sections s
                   JOIN courses c ON s.course_id = c.id
                   JOIN terms t ON s.term_id = t.id
                   WHERE s.faculty_id = ?
                   ORDER BY t.start_date DESC, c.course_code ASC";
$my_stmt = $conn->prepare($my_courses_sql);
if (!$my_stmt) {
    die("Query preparation failed: " . $conn->error);
}
$my_stmt->bind_param('i', $user_id);
$my_stmt->execute();
$my_courses = $my_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get available sections (not assigned or assigned to placeholder)
$available_sql = "SELECT s.id, c.course_code, c.course_name, s.section_name, t.term_name, s.max_students,
                         (SELECT COUNT(*) FROM enrollments WHERE section_id = s.id) as enrolled_count
                  FROM sections s
                  JOIN courses c ON s.course_id = c.id
                  JOIN terms t ON s.term_id = t.id
                  WHERE (s.faculty_id = 1 OR s.faculty_id IS NULL) AND s.status = 'active'
                  ORDER BY t.start_date DESC, c.course_code ASC";
$available_result = $conn->query($available_sql);
if (!$available_result) {
    die("Query failed: " . $conn->error);
}
$available_sections = $available_result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Courses - Grade Recording System</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            border-bottom: 2px solid #333;
        }
        .tab {
            padding: 12px 24px;
            background: none;
            border: none;
            color: #999;
            cursor: pointer;
            font-size: 15px;
            font-weight: 500;
            transition: all 0.3s;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
        }
        .tab:hover {
            color: #fff;
        }
        .tab.active {
            color: #fff;
            border-bottom-color: #007bff;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .courses-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .course-card {
            background: #2a2a2a;
            border-radius: 8px;
            padding: 20px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .course-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.3);
        }
        .course-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        .course-header h3 {
            color: #fff;
            font-size: 18px;
            margin: 0;
        }
        .course-term {
            background: #333;
            color: #aaa;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
        }
        .course-body {
            margin-bottom: 15px;
        }
        .course-name {
            color: #ccc;
            font-size: 14px;
            margin-bottom: 8px;
        }
        .course-section, .course-students {
            color: #999;
            font-size: 13px;
            margin-bottom: 5px;
        }
        .course-footer {
            padding-top: 15px;
            border-top: 1px solid #333;
            display: flex;
            gap: 10px;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }
        .empty-state h3 {
            color: #999;
            margin-bottom: 10px;
        }
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
            border-radius: 8px;
            width: 90%;
            max-width: 900px;
            max-height: 90vh;
            overflow: hidden;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-close:hover {
            color: #fff;
        }
        .modal-body::-webkit-scrollbar {
            display: none;
        }
        .modal-body {
            scrollbar-width: none;
            -ms-overflow-style: none;
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
                <a href="my-courses.php" class="nav-item active">
                    <span class="icon">📚</span> My Courses
                </a>
                <a href="enroll-students.php" class="nav-item">
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
                    <h1>My Courses</h1>
                    <p>Manage your assigned courses and choose new sections</p>
                </div>
            </div>
            
            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <div class="tabs">
                <button class="tab active" onclick="switchTab('my-courses')">My Courses (<?php echo count($my_courses); ?>)</button>
                <button class="tab" onclick="switchTab('available')">Available Sections (<?php echo count($available_sections); ?>)</button>
            </div>
            
            <!-- My Courses Tab -->
            <div id="my-courses" class="tab-content active">
                <?php if (empty($my_courses)): ?>
                    <div class="empty-state">
                        <h3>📚 No courses assigned yet</h3>
                        <p>Browse available sections to get started</p>
                    </div>
                <?php else: ?>
                    <div class="courses-grid">
                        <?php foreach ($my_courses as $course): ?>
                            <div class="course-card">
                                <div class="course-header">
                                    <h3><?php echo htmlspecialchars($course['course_code']); ?></h3>
                                    <span class="course-term"><?php echo htmlspecialchars($course['term_name']); ?></span>
                                </div>
                                <div class="course-body">
                                    <p class="course-name"><?php echo htmlspecialchars($course['course_name']); ?></p>
                                    <p class="course-section">Section: <?php echo htmlspecialchars($course['section_name']); ?></p>
                                    <p class="course-students">Students: <?php echo $course['student_count']; ?></p>
                                </div>
                                <div class="course-footer">
                                    <button class="btn btn-sm btn-primary" onclick="viewSectionStudents(<?php echo $course['id']; ?>, '<?php echo htmlspecialchars($course['course_code'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($course['section_name'], ENT_QUOTES); ?>')">View Section</button>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="unassign_section">
                                        <input type="hidden" name="section_id" value="<?php echo $course['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to drop this section?')">Drop</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Available Sections Tab -->
            <div id="available" class="tab-content">
                <?php if (empty($available_sections)): ?>
                    <div class="empty-state">
                        <h3>✅ No available sections</h3>
                        <p>All sections are currently assigned</p>
                    </div>
                <?php else: ?>
                    <div class="courses-grid">
                        <?php foreach ($available_sections as $section): ?>
                            <div class="course-card">
                                <div class="course-header">
                                    <h3><?php echo htmlspecialchars($section['course_code']); ?></h3>
                                    <span class="course-term"><?php echo htmlspecialchars($section['term_name']); ?></span>
                                </div>
                                <div class="course-body">
                                    <p class="course-name"><?php echo htmlspecialchars($section['course_name']); ?></p>
                                    <p class="course-section">Section: <?php echo htmlspecialchars($section['section_name']); ?></p>
                                    <p class="course-students">
                                        Enrolled: <?php echo $section['enrolled_count']; ?> / <?php echo $section['max_students']; ?>
                                    </p>
                                </div>
                                <div class="course-footer">
                                    <form method="POST" style="width: 100%;">
                                        <input type="hidden" name="action" value="assign_section">
                                        <input type="hidden" name="section_id" value="<?php echo $section['id']; ?>">
                                        <button type="submit" class="btn btn-primary" style="width: 100%;">Assign to Me</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- View Section Modal -->
    <div id="viewSectionModal" class="modal">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header" style="background: #2c2c2c; padding: 20px 30px; border-bottom: 1px solid #1a1a1a;">
                <h2 id="modalTitle" style="margin: 0; color: #fff; font-size: 20px;"></h2>
                <button class="modal-close" onclick="closeModal()" style="background: none; border: none; color: #999; font-size: 28px; cursor: pointer; padding: 0;">&times;</button>
            </div>
            <div class="modal-body" style="padding: 30px; background: #1a1a1a; max-height: calc(90vh - 140px); overflow-y: auto;">
                <div id="modalContent"></div>
            </div>
        </div>
    </div>
    
    <script>
        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName).classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }

        async function viewSectionStudents(sectionId, courseCode, sectionName) {
            document.getElementById('modalTitle').textContent = courseCode + ' - ' + sectionName;
            document.getElementById('modalContent').innerHTML = '<p style="text-align: center; color: #999;">Loading...</p>';
            document.getElementById('viewSectionModal').style.display = 'flex';

            try {
                const response = await fetch(`get_section_students.php?section_id=${sectionId}`);
                const data = await response.json();

                if (data.students.length === 0) {
                    // No students enrolled
                    document.getElementById('modalContent').innerHTML = `
                        <div style="background: #2a2a2a; padding: 40px; border-radius: 8px; text-align: center; border: 2px dashed #666;">
                            <h3 style="color: #ffc107; font-size: 20px; margin-bottom: 10px;">⚠️ No Students Enrolled Yet</h3>
                            <p style="color: #999; margin-bottom: 20px;">This section doesn't have any enrolled students yet.</p>
                            <a href="enroll-students.php?section_id=${sectionId}" class="btn btn-primary">Enroll Students</a>
                        </div>
                    `;
                } else {
                    // Show students table
                    let tableHTML = `
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Student Name</th>
                                    <th>Email</th>
                                    <th>Enrolled Date</th>
                                    <th>Grade</th>
                                </tr>
                            </thead>
                            <tbody>
                    `;

                    data.students.forEach((student, index) => {
                        let gradeDisplay = student.final_grade 
                            ? `<span class="badge badge-success">${parseFloat(student.final_grade).toFixed(2)} (${student.letter_grade})</span>`
                            : `<span class="badge badge-secondary">Not Graded</span>`;

                        tableHTML += `
                            <tr>
                                <td>${index + 1}</td>
                                <td>${student.first_name} ${student.last_name}</td>
                                <td>${student.email}</td>
                                <td>${new Date(student.enrollment_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })}</td>
                                <td>${gradeDisplay}</td>
                            </tr>
                        `;
                    });

                    tableHTML += `
                            </tbody>
                        </table>
                    `;

                    document.getElementById('modalContent').innerHTML = tableHTML;
                }
            } catch (error) {
                document.getElementById('modalContent').innerHTML = `
                    <div style="background: #2a2a2a; padding: 40px; border-radius: 8px; text-align: center;">
                        <h3 style="color: #dc3545; font-size: 20px; margin-bottom: 10px;">❌ Error Loading Students</h3>
                        <p style="color: #999;">Please try again later.</p>
                    </div>
                `;
            }
        }

        function closeModal() {
            document.getElementById('viewSectionModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('viewSectionModal');
            if (event.target === modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>