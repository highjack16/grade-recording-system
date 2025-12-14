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
$filter_course = '';
$filter_section = '';
$search_student = '';

// Get filter parameters
if (isset($_GET['course'])) {
    $filter_course = $_GET['course'];
}
if (isset($_GET['section'])) {
    $filter_section = $_GET['section'];
}
if (isset($_GET['search'])) {
    $search_student = $_GET['search'];
}

$sql = "SELECT 
            g.id, 
            u.id as student_id,
            CONCAT(u.first_name, ' ', u.last_name) AS student_name, 
            c.id as course_id,
            c.course_code, 
            c.course_name,
            s.id as section_id,
            s.section_name, 
            g.final_grade,
            g.letter_grade,
            g.detailed_scores
        FROM grades g
        JOIN users u ON g.student_id = u.id
        JOIN sections s ON g.section_id = s.id
        JOIN courses c ON s.course_id = c.id
        WHERE 1=1";

$params = [];
$types = '';

if (!empty($filter_course)) {
    $sql .= " AND c.id = ?";
    $params[] = intval($filter_course);
    $types .= 'i';
}

if (!empty($filter_section)) {
    $sql .= " AND s.id = ?";
    $params[] = intval($filter_section);
    $types .= 'i';
}

if (!empty($search_student)) {
    $sql .= " AND (CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR u.id LIKE ?)";
    $search_term = '%' . $search_student . '%';
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'ss';
}

$sql .= " ORDER BY c.course_code, s.section_name, u.first_name";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

$grades = $result->fetch_all(MYSQLI_ASSOC);

// Parse detailed scores for each grade
foreach ($grades as &$grade) {
    $details = json_decode($grade['detailed_scores'] ?? '{}', true);
    $grade['quiz_score'] = $details['quiz_score'] ?? null;
    $grade['midterm_score'] = $details['midterm_score'] ?? null;
    $grade['final_score'] = $details['final_score'] ?? null;
    $grade['weighted_percentage'] = $details['weighted_percentage'] ?? null;
}

// Get courses for filter dropdown
$courses_sql = "SELECT DISTINCT c.id, c.course_code, c.course_name FROM courses c 
                JOIN sections s ON c.id = s.course_id 
                JOIN grades g ON s.id = g.section_id 
                ORDER BY c.course_code";
$courses_result = $conn->query($courses_sql);
$courses = $courses_result->fetch_all(MYSQLI_ASSOC);


$sections_sql = "SELECT DISTINCT s.id, s.section_name, c.course_code FROM sections s 
                 JOIN courses c ON s.course_id = c.id 
                 JOIN grades g ON s.id = g.section_id";
if (!empty($filter_course)) {
    $sections_sql .= " WHERE c.id = " . intval($filter_course);
}
$sections_sql .= " ORDER BY c.course_code, s.section_name";
$sections_result = $conn->query($sections_sql);
$sections = $sections_result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Grades - Grade Recording System</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .grade-details-btn {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            margin-right: 5px;
        }
        .grade-details-btn:hover {
            background: #2563eb;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.7);
            justify-content: center;
            align-items: center;
        }
        
        .modal-content {
            background-color: #1e1e1e;
            border: 1px solid #333;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            color: #e0e0e0;
            overflow: hidden;
        }
        
        .modal-header {
            padding: 20px 25px;
            border-bottom: 1px solid #333;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h2 {
            margin: 0;
            font-size: 1.3rem;
        }
        
        .modal-close {
            background: none;
            border: none;
            color: #aaa;
            font-size: 28px;
            cursor: pointer;
            padding: 0;
            line-height: 1;
        }
        
        .modal-close:hover {
            color: #e0e0e0;
        }
        
        .modal-body {
            padding: 25px;
            max-height: calc(90vh - 80px);
            overflow-y: auto;
        }

        .modal-body::-webkit-scrollbar {
            display: none;
        }

        .modal-body {
            -ms-overflow-style: none;  
            scrollbar-width: none;  
        }
        
        .grade-detail-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #333;
        }
        
        .grade-detail-row:last-child {
            border-bottom: none;
            padding-top: 15px;
            margin-top: 10px;
            border-top: 2px solid #4ade80;
            font-weight: bold;
        }
        
        .grade-detail-label {
            color: #aaa;
            font-size: 14px;
        }
        
        .grade-detail-value {
            color: #e0e0e0;
            font-weight: 600;
            font-size: 14px;
        }
        
        .final-grade-large {
            font-size: 24px;
            color: #4ade80;
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
                <a href="manage-sections.php" class="nav-item">
                    <span class="icon">📋</span> Manage Sections
                </a>
                <a href="manage-terms.php" class="nav-item">
                    <span class="icon">📅</span> Manage Terms
                </a>
                <a href="manage-grades.php" class="nav-item active">
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
            $page_title = "Manage Grades";
            $page_subtitle = "View and manage all student grades";
            include '../includes/notification-header.php'; 
            ?>
            
            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-body">
                    <form method="GET" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: center;">
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <label for="course" style="font-weight: 500; white-space: nowrap;">Course:</label>
                            <select id="course" name="course" onchange="this.form.submit()" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px;">
                                <option value="">All Courses</option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?php echo $course['id']; ?>" <?php echo $filter_course == $course['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <label for="section" style="font-weight: 500; white-space: nowrap;">Section:</label>
                            <select id="section" name="section" onchange="this.form.submit()" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px;">
                                <option value="">All Sections</option>
                                <?php foreach ($sections as $section): ?>
                                    <option value="<?php echo $section['id']; ?>" <?php echo $filter_section == $section['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($section['course_code'] . ' - ' . $section['section_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div style="display: flex; gap: 10px; align-items: center; flex-grow: 1;">
                            <label for="search" style="font-weight: 500; white-space: nowrap;">Student:</label>
                            <input type="text" id="search" name="search" placeholder="Search student..." value="<?php echo htmlspecialchars($search_student); ?>" style="flex-grow: 1; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px;">
                        </div>
                        
                        <div style="display: flex; gap: 10px;">
                            <button type="submit" class="btn btn-primary" style="padding: 8px 16px; font-size: 14px;">🔍 Search</button>
                            <a href="manage-grades.php" class="btn btn-secondary" style="padding: 8px 16px; font-size: 14px; text-decoration: none;">Clear</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2>All Submitted Grades</h2>
                </div>
                <div class="card-body">
                    <?php if (empty($grades)): ?>
                        <div class="empty-state">
                            <p>No grades found</p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Student Name</th>
                                        <th>Course</th>
                                        <th>Section</th>
                                        <th style="text-align: center;">Quiz</th>
                                        <th style="text-align: center;">Midterm</th>
                                        <th style="text-align: center;">Final</th>
                                        <th style="text-align: center;">Overall %</th>
                                        <th style="text-align: center;">Final Grade</th>
                                        <th style="text-align: center;">Letter</th>
                                        <th style="text-align: center;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($grades as $grade): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($grade['student_name']); ?></td>
                                            <td><?php echo htmlspecialchars($grade['course_code']); ?></td>
                                            <td><?php echo htmlspecialchars($grade['section_name']); ?></td>
                                            <td style="text-align: center;">
                                                <?php echo $grade['quiz_score'] !== null ? number_format($grade['quiz_score'], 1) : '<span style="color: #999;">—</span>'; ?>
                                            </td>
                                            <td style="text-align: center;">
                                                <?php echo $grade['midterm_score'] !== null ? number_format($grade['midterm_score'], 1) : '<span style="color: #999;">—</span>'; ?>
                                            </td>
                                            <td style="text-align: center;">
                                                <?php echo $grade['final_score'] !== null ? number_format($grade['final_score'], 1) : '<span style="color: #999;">—</span>'; ?>
                                            </td>
                                            <td style="text-align: center;">
                                                <?php echo $grade['weighted_percentage'] !== null ? number_format($grade['weighted_percentage'], 2) . '%' : '<span style="color: #999;">—</span>'; ?>
                                            </td>
                                            <td style="text-align: center; font-weight: bold; color: #4ade80;">
                                                <?php echo $grade['final_grade'] !== null ? number_format($grade['final_grade'], 2) : '<span style="color: #999;">—</span>'; ?>
                                            </td>
                                            <td style="text-align: center;">
                                                <?php if ($grade['letter_grade']): ?>
                                                    <span class="badge badge-<?php echo strtolower($grade['letter_grade']); ?>">
                                                        <?php echo htmlspecialchars($grade['letter_grade']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color: #999;">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align: center;">
                                                <button type="button" class="grade-details-btn" onclick="viewGradeDetails(<?php echo htmlspecialchars(json_encode($grade)); ?>)">
                                                    View Details
                                                </button>
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
    
    <!-- Grade Details Modal -->
    <div id="gradeDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Grade Breakdown</h2>
                <button class="modal-close" onclick="closeDetailsModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div style="background: #2a2a2a; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <p style="margin: 5px 0;"><strong>Student:</strong> <span id="detailStudentName"></span></p>
                    <p style="margin: 5px 0;"><strong>Course:</strong> <span id="detailCourseName"></span></p>
                    <p style="margin: 5px 0;"><strong>Section:</strong> <span id="detailSectionName"></span></p>
                </div>
                
                <div id="gradeBreakdown"></div>
            </div>
        </div>
    </div>
    
    <script>
        function viewGradeDetails(gradeData) {
            document.getElementById('detailStudentName').textContent = gradeData.student_name;
            document.getElementById('detailCourseName').textContent = gradeData.course_code + ' - ' + gradeData.course_name;
            document.getElementById('detailSectionName').textContent = gradeData.section_name;
            
            const details = JSON.parse(gradeData.detailed_scores || '{}');
            
            let html = '';
            
            if (details.attendance !== null && details.attendance !== undefined) {
                html += `<div class="grade-detail-row">
                    <span class="grade-detail-label">Attendance</span>
                    <span class="grade-detail-value">${details.attendance}</span>
                </div>`;
            }
            
            if (details.recitation !== null && details.recitation !== undefined) {
                html += `<div class="grade-detail-row">
                    <span class="grade-detail-label">Recitation</span>
                    <span class="grade-detail-value">${details.recitation}</span>
                </div>`;
            }
            
            if (details.quiz_score !== null && details.quiz_score !== undefined) {
                html += `<div class="grade-detail-row">
                    <span class="grade-detail-label">Quiz</span>
                    <span class="grade-detail-value">${details.quiz_score} / ${details.quiz_total || 'N/A'}</span>
                </div>`;
            }
            
            if (details.project_score !== null && details.project_score !== undefined) {
                html += `<div class="grade-detail-row">
                    <span class="grade-detail-label">Project</span>
                    <span class="grade-detail-value">${details.project_score} / ${details.project_total || 'N/A'}</span>
                </div>`;
            }
            
            if (details.midterm_score !== null && details.midterm_score !== undefined) {
                html += `<div class="grade-detail-row">
                    <span class="grade-detail-label">Midterm Exam</span>
                    <span class="grade-detail-value">${details.midterm_score} / ${details.midterm_total || 'N/A'}</span>
                </div>`;
            }
            
            if (details.final_score !== null && details.final_score !== undefined) {
                html += `<div class="grade-detail-row">
                    <span class="grade-detail-label">Final Exam</span>
                    <span class="grade-detail-value">${details.final_score} / ${details.final_total || 'N/A'}</span>
                </div>`;
            }
            
            html += `<div class="grade-detail-row">
                <span class="grade-detail-label">Weighted Percentage</span>
                <span class="grade-detail-value">${details.weighted_percentage || 0}%</span>
            </div>`;
            
            html += `<div class="grade-detail-row">
                <span class="grade-detail-label">Final Grade</span>
                <span class="grade-detail-value final-grade-large">${gradeData.final_grade ? Number(gradeData.final_grade).toFixed(2) : 'N/A'} (${gradeData.letter_grade || 'N/A'})</span>
            </div>`;
            
            document.getElementById('gradeBreakdown').innerHTML = html;
            document.getElementById('gradeDetailsModal').style.display = 'flex';
        }
        
        function closeDetailsModal() {
            document.getElementById('gradeDetailsModal').style.display = 'none';
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('gradeDetailsModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        }
        
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeDetailsModal();
            }
        });
    </script>
</body>
</html>