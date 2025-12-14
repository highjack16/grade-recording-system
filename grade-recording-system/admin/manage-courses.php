<?php
require_once dirname(__DIR__) . '/config/db.php';

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
$selected_term_id = isset($_GET['term_id']) ? intval($_GET['term_id']) : null;

$curriculum_structure = [
    '1st Semester' => [
        ['code' => 'CC103', 'name' => 'Data Structures and Algorithms', 'lec' => 2, 'lab' => 1, 'prereq' => 'CC102'],
        ['code' => 'CC104', 'name' => 'Information Management', 'lec' => 2, 'lab' => 1, 'prereq' => 'CC102, OOP 112'],
        ['code' => 'MAD121', 'name' => 'Mobile Application Development', 'lec' => 2, 'lab' => 1, 'prereq' => 'CC102, OOP 112'],
        ['code' => 'WD123', 'name' => 'Web Development 2', 'lec' => 2, 'lab' => 1, 'prereq' => 'WD 114'],
        ['code' => 'SIPP125', 'name' => 'Social Issues and Professional Practice', 'lec' => 3, 'lab' => 0, 'prereq' => 'CC102'],
        ['code' => 'CC127', 'name' => 'Networks and Communications', 'lec' => 2, 'lab' => 1, 'prereq' => 'CC102'],
        ['code' => 'PATHFIT3', 'name' => 'Sports', 'lec' => 2, 'lab' => 0, 'prereq' => 'PATHFIT 2'],
    ],
    '2nd Semester' => [
        ['code' => 'CC105', 'name' => 'Applications Development and Emerging Technologies', 'lec' => 2, 'lab' => 1, 'prereq' => 'CC104'],
        ['code' => 'ACTINT122', 'name' => 'ACT Internship (320 hours)', 'lec' => 0, 'lab' => 6, 'prereq' => 'CC102, OOP 112, EC 102, DS 112'],
        ['code' => 'AO124', 'name' => 'Architecture and Organization', 'lec' => 2, 'lab' => 1, 'prereq' => 'DS 111, CC 112'],
        ['code' => 'PHILISC1', 'name' => 'Philippine Constitution', 'lec' => 3, 'lab' => 0, 'prereq' => 'None'],
        ['code' => 'CW101', 'name' => 'The Contemporary World', 'lec' => 3, 'lab' => 0, 'prereq' => 'None'],
        ['code' => 'PATHFIT4', 'name' => 'Outdoor and Adventure Activities', 'lec' => 2, 'lab' => 0, 'prereq' => 'PATHFIT'],
    ],
    'Summer' => [
        ['code' => 'CS128', 'name' => 'Algorithms and Complexity', 'lec' => 3, 'lab' => 0, 'prereq' => 'DS 118, CC 103'],
        ['code' => 'CS129', 'name' => 'Programming Languages', 'lec' => 2, 'lab' => 1, 'prereq' => 'CC103'],
        ['code' => 'STAT120', 'name' => 'Statistics for Computer Science', 'lec' => 3, 'lab' => 0, 'prereq' => 'None'],
    ],
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add_custom_subject') {
            $course_code = trim($_POST['custom_course_code'] ?? '');
            $course_name = trim($_POST['custom_course_name'] ?? '');
            $lec_units = intval($_POST['custom_lec_units'] ?? 0);
            $lab_units = intval($_POST['custom_lab_units'] ?? 0);
            $prereq = trim($_POST['custom_prereq'] ?? '');
            $term_id = intval($_POST['custom_term_id'] ?? 0);
            
            if (empty($course_code) || empty($course_name) || $term_id === 0) {
                $error = 'Course code, name, and term are required';
            } else {
                $check_course_sql = "SELECT id FROM courses WHERE course_code = ?";
                $check_course_stmt = $conn->prepare($check_course_sql);
                $check_course_stmt->bind_param('s', $course_code);
                $check_course_stmt->execute();
                $check_course_result = $check_course_stmt->get_result();
                
                if ($check_course_result->num_rows === 0) {
                    $credits = $lec_units + $lab_units;
                    $description_data = json_encode([
                        'lec' => $lec_units, 
                        'lab' => $lab_units, 
                        'prereq' => $prereq, 
                        'term_id' => $term_id
                    ]);
                    $insert_course_sql = "INSERT INTO courses (course_code, course_name, description, credits) VALUES (?, ?, ?, ?)";
                    $insert_course_stmt = $conn->prepare($insert_course_sql);
                    $insert_course_stmt->bind_param('sssi', $course_code, $course_name, $description_data, $credits);
                    
                    if ($insert_course_stmt->execute()) {
                        $message = 'Custom subject added successfully. You can now add it to the curriculum from the dropdown below.';
                        $action = "Added custom subject {$course_code} to courses pool";
                        $ip = $_SERVER['REMOTE_ADDR'];
                        $log_sql = "INSERT INTO system_logs (user_id, action, ip_address) VALUES (?, ?, ?)";
                        $log_stmt = $conn->prepare($log_sql);
                        $log_stmt->bind_param('iss', $_SESSION['user_id'], $action, $ip);
                        $log_stmt->execute();
                        
                        header("Location: manage-courses.php?term_id=$term_id&success=2");
                        exit();
                    } else {
                        $error = 'Error adding custom subject: ' . $insert_course_stmt->error;
                    }
                } else {
                    $error = 'A course with this code already exists';
                }
            }
        } elseif ($_POST['action'] === 'add_to_curriculum') {
            $course_code = $_POST['course_code'] ?? '';
            $term_id = intval($_POST['term_id'] ?? 0);
            
            if (empty($course_code) || $term_id === 0) {
                $error = 'Course and term are required';
            } else {
                $course_data = null;
                foreach ($curriculum_structure as $term_name => $courses) {
                    foreach ($courses as $c) {
                        if ($c['code'] === $course_code) {
                            $course_data = $c;
                            break 2;
                        }
                    }
                }
                
                if (!$course_data) {
                    $custom_sql = "SELECT id, course_code, course_name, description, credits FROM courses WHERE course_code = ?";
                    $custom_stmt = $conn->prepare($custom_sql);
                    $custom_stmt->bind_param('s', $course_code);
                    $custom_stmt->execute();
                    $custom_result = $custom_stmt->get_result();
                    
                    if ($custom_result->num_rows > 0) {
                        $custom_course = $custom_result->fetch_assoc();
                        $description_data = json_decode($custom_course['description'], true);
                        $course_data = [
                            'code' => $custom_course['course_code'],
                            'name' => $custom_course['course_name'],
                            'lec' => $description_data['lec'] ?? 0,
                            'lab' => $description_data['lab'] ?? 0,
                            'prereq' => $description_data['prereq'] ?? ''
                        ];
                    }
                }
                
                if (!$course_data) {
                    $error = 'Course not found';
                } else {
                    // Check if course exists in courses table
                    $check_course_sql = "SELECT id FROM courses WHERE course_code = ?";
                    $check_course_stmt = $conn->prepare($check_course_sql);
                    $check_course_stmt->bind_param('s', $course_code);
                    $check_course_stmt->execute();
                    $check_course_result = $check_course_stmt->get_result();
                    
                    if ($check_course_result->num_rows === 0) {
                        // Create course if it doesn't exist
                        $credits = $course_data['lec'] + $course_data['lab'];
                        $insert_course_sql = "INSERT INTO courses (course_code, course_name, credits) VALUES (?, ?, ?)";
                        $insert_course_stmt = $conn->prepare($insert_course_sql);
                        $insert_course_stmt->bind_param('ssi', $course_code, $course_data['name'], $credits);
                        $insert_course_stmt->execute();
                        $course_id = $conn->insert_id;
                    } else {
                        $course = $check_course_result->fetch_assoc();
                        $course_id = $course['id'];
                    }
                    
                    // Check if already in curriculum
                    $check_curriculum_sql = "SELECT COUNT(*) as cnt FROM curriculum WHERE course_id = ? AND term_id = ? AND school_year = ?";
                    $check_curriculum_stmt = $conn->prepare($check_curriculum_sql);
                    $check_curriculum_stmt->bind_param('iis', $course_id, $term_id, $current_school_year);
                    $check_curriculum_stmt->execute();
                    $check_curriculum_result = $check_curriculum_stmt->get_result();
                    $check_row = $check_curriculum_result->fetch_assoc();
                    
                    if ($check_row['cnt'] > 0) {
                        $error = 'This course is already in the curriculum for this term';
                    } else {
                        // Add to curriculum
                        $prereq = ($course_data['prereq'] !== 'None' && !empty($course_data['prereq'])) ? $course_data['prereq'] : NULL;
                        $lec_units = intval($course_data['lec']);
                        $lab_units = intval($course_data['lab']);
                        $is_required = 1;
                        
                        $insert_curriculum_sql = "INSERT INTO curriculum (course_id, term_id, school_year, lecture_units, lab_units, prerequisite, is_required) 
                                                  VALUES (?, ?, ?, ?, ?, ?, ?)";
                        $insert_curriculum_stmt = $conn->prepare($insert_curriculum_sql);
                        $insert_curriculum_stmt->bind_param('issiisi', $course_id, $term_id, $current_school_year, $lec_units, $lab_units, $prereq, $is_required);
                        
                        if ($insert_curriculum_stmt->execute()) {
                            $message = 'Course added to curriculum successfully';
                            $action = "Added {$course_data['code']} to curriculum";
                            $ip = $_SERVER['REMOTE_ADDR'];
                            $log_sql = "INSERT INTO system_logs (user_id, action, ip_address) VALUES (?, ?, ?)";
                            $log_stmt = $conn->prepare($log_sql);
                            $log_stmt->bind_param('iss', $_SESSION['user_id'], $action, $ip);
                            $log_stmt->execute();
                            
                            header("Location: manage-courses.php?term_id=$term_id&success=1");
                            exit();
                        } else {
                            $error = 'Error adding to curriculum: ' . $insert_curriculum_stmt->error;
                        }
                    }
                }
            }
        } elseif ($_POST['action'] === 'remove_from_curriculum') {
            $curriculum_id = $_POST['curriculum_id'] ?? '';
            
            if (!empty($curriculum_id)) {
                $sql = "DELETE FROM curriculum WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('i', $curriculum_id);
                
                if ($stmt->execute()) {
                    $message = 'Course removed from curriculum successfully';
                    $action = "Removed course from curriculum ID: $curriculum_id";
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $log_sql = "INSERT INTO system_logs (user_id, action, ip_address) VALUES (?, ?, ?)";
                    $log_stmt = $conn->prepare($log_sql);
                    $log_stmt->bind_param('iss', $_SESSION['user_id'], $action, $ip);
                    $log_stmt->execute();
                    
                    header("Location: manage-courses.php?term_id={$_GET['term_id']}&removed=1");
                    exit();
                } else {
                    $error = 'Error removing from curriculum';
                }
                $stmt->close();
            }
        }
    }
}

if (isset($_GET['success'])) {
    if ($_GET['success'] == '2') {
        $message = 'Custom subject added successfully. You can now add it to the curriculum from the dropdown below.';
    } else {
        $message = 'Course added to curriculum successfully';
    }
}
if (isset($_GET['removed'])) {
    $message = 'Course removed from curriculum successfully';
}

// Get all terms
$terms_sql = "SELECT id, term_name FROM terms ORDER BY start_date ASC";
$terms_result = $conn->query($terms_sql);
$terms = $terms_result->fetch_all(MYSQLI_ASSOC);

// Get courses already in selected term
$courses_in_term = [];
$available_courses = [];

if ($selected_term_id) {
    // Get term name
    $term_name_sql = "SELECT term_name FROM terms WHERE id = ?";
    $term_name_stmt = $conn->prepare($term_name_sql);
    $term_name_stmt->bind_param('i', $selected_term_id);
    $term_name_stmt->execute();
    $term_name_result = $term_name_stmt->get_result();
    
    if ($term_name_result->num_rows > 0) {
        $term_name_row = $term_name_result->fetch_assoc();
        $selected_term_name = $term_name_row['term_name'];
        
        // Get courses already in curriculum
        $in_term_sql = "SELECT course_id FROM curriculum WHERE term_id = ? AND school_year = ?";
        $in_term_stmt = $conn->prepare($in_term_sql);
        $in_term_stmt->bind_param('is', $selected_term_id, $current_school_year);
        $in_term_stmt->execute();
        $in_term_result = $in_term_stmt->get_result();
        $course_codes_in_curriculum = [];
        while ($row = $in_term_result->fetch_assoc()) {
            $courses_in_term[] = $row['course_id'];
            // Get course code
            $course_code_sql = "SELECT course_code FROM courses WHERE id = ?";
            $course_code_stmt = $conn->prepare($course_code_sql);
            $course_code_stmt->bind_param('i', $row['course_id']);
            $course_code_stmt->execute();
            $course_code_result = $course_code_stmt->get_result();
            if ($code_row = $course_code_result->fetch_assoc()) {
                $course_codes_in_curriculum[] = $code_row['course_code'];
            }
        }

        foreach ($curriculum_structure as $term => $courses) {
            // Match term name
            $match = false;
            if (stripos($selected_term_name, '1st') !== false && stripos($term, '1st') !== false) {
                $match = true;
            } elseif (stripos($selected_term_name, '2nd') !== false && stripos($term, '2nd') !== false) {
                $match = true;
            } elseif (stripos($selected_term_name, 'Summer') !== false && stripos($term, 'Summer') !== false) {
                $match = true;
            }
            
            if ($match) {
                foreach ($courses as $course) {
                    if (!in_array($course['code'], $course_codes_in_curriculum)) {
                        $available_courses[] = $course;
                    }
                }
                break;
            }
        }
        
        $custom_courses_sql = "SELECT id, course_code, course_name, description, credits FROM courses WHERE description IS NOT NULL AND description != ''";
        $custom_courses_result = $conn->query($custom_courses_sql);
        
        if ($custom_courses_result) {
            while ($custom_course = $custom_courses_result->fetch_assoc()) {
                // Skip if already in curriculum
                if (in_array($custom_course['course_code'], $course_codes_in_curriculum)) {
                    continue;
                }

                $already_in_list = false;
                foreach ($available_courses as $ac) {
                    if ($ac['code'] === $custom_course['course_code']) {
                        $already_in_list = true;
                        break;
                    }
                }
                if ($already_in_list) {
                    continue;
                }

                $description_data = json_decode($custom_course['description'], true);
                if ($description_data && isset($description_data['term_id']) && $description_data['term_id'] == $selected_term_id) {
                    $available_courses[] = [
                        'code' => $custom_course['course_code'],
                        'name' => $custom_course['course_name'],
                        'lec' => $description_data['lec'] ?? 0,
                        'lab' => $description_data['lab'] ?? 0,
                        'prereq' => $description_data['prereq'] ?? ''
                    ];
                }
            }
        }
    }
}

$curriculum = [];
$total_lecture = 0;
$total_lab = 0;
$total_units = 0;

if ($selected_term_id) {
    $curriculum_sql = "SELECT c.id, co.course_code, co.course_name, 
                              c.lecture_units, c.lab_units, c.prerequisite
                       FROM curriculum c
                       JOIN courses co ON c.course_id = co.id
                       WHERE c.term_id = ? AND c.school_year = ?
                       ORDER BY co.course_code ASC";
    $curriculum_stmt = $conn->prepare($curriculum_sql);
    $curriculum_stmt->bind_param('is', $selected_term_id, $current_school_year);
    $curriculum_stmt->execute();
    $curriculum_result = $curriculum_stmt->get_result();
    $curriculum = $curriculum_result->fetch_all(MYSQLI_ASSOC);
    
    foreach ($curriculum as $course) {
        $total_lecture += $course['lecture_units'];
        $total_lab += $course['lab_units'];
        $total_units += $course['lecture_units'] + $course['lab_units'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Courses & Curriculum - Grade Recording System</title>
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
                <a href="manage-courses.php" class="nav-item active">
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
$page_title = "Manage Courses & Curriculum";
$page_subtitle = "WMSU CCS Curriculum - School Year: " . $current_school_year;
include '../includes/notification-header.php'; 
?>
            
            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h2>Add Custom Subject</h2>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="add_custom_subject">
                        
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                            <div class="form-group">
                                <label for="custom_course_code">Subject Code: <span style="color: red;">*</span></label>
                                <input type="text" id="custom_course_code" name="custom_course_code" required 
                                       placeholder="e.g., CS101" 
                                       style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                            </div>
                            
                            <div class="form-group">
                                <label for="custom_course_name">Subject Name: <span style="color: red;">*</span></label>
                                <input type="text" id="custom_course_name" name="custom_course_name" required 
                                       placeholder="e.g., Introduction to Computing" 
                                       style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                            </div>
                            
                            <div class="form-group">
                                <label for="custom_lec_units">Lecture Units:</label>
                                <input type="number" id="custom_lec_units" name="custom_lec_units" min="0" max="6" value="3" 
                                       style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                            </div>
                            
                            <div class="form-group">
                                <label for="custom_lab_units">Lab Units:</label>
                                <input type="number" id="custom_lab_units" name="custom_lab_units" min="0" max="6" value="0" 
                                       style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                            </div>
                            
                            <div class="form-group">
                                <label for="custom_prereq">Prerequisite:</label>
                                <input type="text" id="custom_prereq" name="custom_prereq" 
                                       placeholder="e.g., CC102 (leave blank if none)" 
                                       style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                            </div>
                            
                            <div class="form-group">
                                <label for="custom_term_id">Assign to Semester: <span style="color: red;">*</span></label>
                                <select id="custom_term_id" name="custom_term_id" required 
                                        style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                                    <option value="">-- Select Semester --</option>
                                    <?php foreach ($terms as $term): ?>
                                        <option value="<?php echo $term['id']; ?>" <?php echo $selected_term_id == $term['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($term['term_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div style="margin-top: 15px;">
                            <button type="submit" class="btn btn-primary" style="padding: 10px 20px;">+ Add Custom Subject</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2>Select Term</h2>
                </div>
                <div class="card-body">
                    <form method="GET">
                        <div style="display: flex; gap: 15px; align-items: flex-end;">
                            <div style="display: flex; flex-direction: column; flex: 1; max-width: 400px;">
                                <label for="term_id" style="font-weight: 500; margin-bottom: 8px;">Choose Term:</label>
                                <select id="term_id" name="term_id" onchange="this.form.submit()" style="padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                                    <option value="">-- Select a term --</option>
                                    <?php foreach ($terms as $term): ?>
                                        <option value="<?php echo $term['id']; ?>" <?php echo $selected_term_id == $term['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($term['term_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <?php if ($selected_term_id): ?>
                <div class="card">
                    <div class="card-header">
                        <h2>Add Course to This Term</h2>
                    </div>
                    <div class="card-body">
                        <?php if (empty($available_courses)): ?>
                            <div class="empty-state">
                                <p>✅ All courses for this term are already added!</p>
                            </div>
                        <?php else: ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="add_to_curriculum">
                                <input type="hidden" name="term_id" value="<?php echo $selected_term_id; ?>">
                                
                                <div class="form-group">
                                    <label for="course_code">Available Courses:</label>
                                    <select id="course_code" name="course_code" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                                        <option value="">-- Select a course --</option>
                                        <?php foreach ($available_courses as $course): ?>
                                            <option value="<?php echo $course['code']; ?>">
                                                <?php echo htmlspecialchars($course['code'] . ' - ' . $course['name'] . ' (' . ($course['lec'] + $course['lab']) . ' units)'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div style="margin-top: 15px;">
                                    <button type="submit" class="btn btn-primary" style="padding: 10px 20px;">+ Add Course</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h2>Curriculum for This Term (<?php echo count($curriculum); ?> courses)</h2>
                    </div>
                    <div class="card-body">
                        <?php if (empty($curriculum)): ?>
                            <div class="empty-state">
                                <p>No courses added to this term yet. Add courses above.</p>
                            </div>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>CODE</th>
                                            <th>TITLE</th>
                                            <th style="text-align: center;">LEC</th>
                                            <th style="text-align: center;">LAB</th>
                                            <th style="text-align: center;">TOTAL</th>
                                            <th>PREREQ</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($curriculum as $course): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($course['course_code']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($course['course_name']); ?></td>
                                                <td style="text-align: center;"><?php echo $course['lecture_units']; ?></td>
                                                <td style="text-align: center;"><?php echo $course['lab_units']; ?></td>
                                                <td style="text-align: center;"><strong><?php echo ($course['lecture_units'] + $course['lab_units']); ?></strong></td>
                                                <td><?php echo $course['prerequisite'] ? htmlspecialchars($course['prerequisite']) : '<span style="color: #999;">None</span>'; ?></td>
                                                <td>
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="action" value="remove_from_curriculum">
                                                        <input type="hidden" name="curriculum_id" value="<?php echo $course['id']; ?>">
                                                        <button type="submit" class="btn btn-danger btn-sm" style="padding: 6px 12px; font-size: 13px;" onclick="return confirm('Remove this course?')">Remove</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr style="background:rgb(67, 67, 67); font-weight: 600">
                                            <td colspan="2">TOTAL</td>
                                            <td style="text-align: center;"><?php echo $total_lecture; ?></td>
                                            <td style="text-align: center;"><?php echo $total_lab; ?></td>
                                            <td style="text-align: center;"><?php echo $total_units; ?></td>
                                            <td colspan="2"></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
