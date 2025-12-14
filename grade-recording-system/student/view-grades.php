<?php
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Updated query to get detailed_scores
$sql = "SELECT g.id, c.course_code, c.course_name, s.section_name, 
        CONCAT(u.first_name, ' ', u.last_name) as faculty_name,
        g.final_grade, g.letter_grade, g.detailed_scores, g.created_at
        FROM grades g
        JOIN sections s ON g.section_id = s.id
        JOIN courses c ON s.course_id = c.id
        JOIN users u ON s.faculty_id = u.id
        WHERE g.student_id = ?
        ORDER BY g.created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
$grades = $result->fetch_all(MYSQLI_ASSOC);

// Parse detailed scores for each grade
foreach ($grades as &$grade) {
    $details = json_decode($grade['detailed_scores'] ?? '{}', true);
    $grade['quiz_score'] = $details['quiz_score'] ?? null;
    $grade['quiz_total'] = $details['quiz_total'] ?? null;
    $grade['midterm_score'] = $details['midterm_score'] ?? null;
    $grade['midterm_total'] = $details['midterm_total'] ?? null;
    $grade['final_score'] = $details['final_score'] ?? null;
    $grade['final_total'] = $details['final_total'] ?? null;
    $grade['weighted_percentage'] = $details['weighted_percentage'] ?? null;
    $grade['attendance'] = $details['attendance'] ?? null;
    $grade['recitation'] = $details['recitation'] ?? null;
    $grade['project_score'] = $details['project_score'] ?? null;
    $grade['project_total'] = $details['project_total'] ?? null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Grades - Grade Recording System</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        :root {
            --dark-bg-1: #121212;
            --dark-bg-2: #1e1e1e;
            --dark-border: #333;
            --text-light: #e0e0e0;
            --text-muted: #aaa;
            --accent-green: #4ade80;
            --accent-blue: #3b82f6;
            --surface: #1e1e1e;
        }

        body {
            background-color: var(--dark-bg-1);
            color: var(--text-light);
        }

        .page-header {
            padding-bottom: 20px;
            margin-bottom: 25px;
        }

        .page-header h1 {
            color: var(--text-light);
            margin: 0 0 5px 0;
            font-size: 1.8rem;
        }

        .page-header p {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin: 0;
        }

        .grades-card {
            background-color: var(--dark-bg-2);
            border: 1px solid var(--dark-border);
            border-radius: 12px;
            overflow: hidden;
        }

        .card-header {
            padding: 20px 25px;
            border-bottom: 1px solid var(--dark-border);
        }

        .card-header h2 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-light);
        }

        .table-wrapper {
            overflow-x: auto;
        }

        .grades-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .grades-table th {
            padding: 14px 16px;
            text-align: left;
            font-weight: 700;
            color: var(--text-light);
            background-color: #2a2a2a;
            border-bottom: 2px solid var(--dark-border);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .grades-table td {
            padding: 16px;
            border-bottom: 1px solid var(--dark-border);
            color: var(--text-muted);
        }

        .grades-table tbody tr:hover {
            background-color: #2a2a2a;
        }

        .course-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .course-code {
            font-weight: 600;
            color: var(--text-light);
            font-size: 13px;
        }

        .course-name {
            font-size: 12px;
            color: var(--text-muted);
        }

        .score-display {
            text-align: center;
            font-weight: 600;
            color: var(--text-light);
        }

        .score-fraction {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }

        .score-separator {
            color: var(--text-muted);
        }

        .na-text {
            color: #666;
            font-style: italic;
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
            color: #fb923c;
            border: 1px solid #fb923c;
        }

        .grade-badge.pass {
            background-color: rgba(234, 179, 8, 0.2);
            color: #eab308;
            border: 1px solid #eab308;
        }

        .grade-badge.conditional {
            background-color: rgba(248, 113, 113, 0.2);
            color: #f87171;
            border: 1px solid #f87171;
        }

        .grade-badge.failed {
            background-color: rgba(239, 68, 68, 0.3);
            color: #ef4444;
            border: 1px solid #ef4444;
        }

        .letter-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 12px;
            text-align: center;
            min-width: 40px;
        }

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

        .details-btn {
            background-color: var(--accent-blue);
            color: white;
            border: none;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .details-btn:hover {
            background-color: #2563eb;
            transform: translateY(-1px);
        }

        /* Modal styles */
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
            background-color: var(--dark-bg-2);
            border: 1px solid var(--dark-border);
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            color: var(--text-light);
            overflow: hidden;
        }

        .modal-header {
            padding: 20px 25px;
            border-bottom: 1px solid var(--dark-border);
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
            color: var(--text-light);
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
            border-bottom: 1px solid var(--dark-border);
        }

        .grade-detail-row:last-child {
            border-bottom: none;
            padding-top: 15px;
            margin-top: 10px;
            border-top: 2px solid var(--accent-green);
            font-weight: bold;
        }

        .grade-detail-label {
            color: #aaa;
            font-size: 14px;
        }

        .grade-detail-value {
            color: var(--text-light);
            font-weight: 600;
            font-size: 14px;
        }

        .final-grade-large {
            font-size: 24px;
            color: var(--accent-green);
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
                <a href="view-grades.php" class="nav-item active">
                    <span class="icon">📊</span> My Grades
                </a>
                <a href="announcement.php" class="nav-item">
                    <span class="icon">📢</span> Announcements
                </a>
                <a href="transcript.php" class="nav-item">
                    <span class="icon">📄</span> Transcript
                </a>
            </nav>
            
            <div class="sidebar-footer">
                <a href="../logout.php" class="btn btn-logout">Logout</a>
            </div>
        </div>
        
        <div class="main-content">
            <div class="page-header">
                <h1>My Grades</h1>
                <p>View your course grades and performance</p>
            </div>
            
            <div class="grades-card">
                <div class="card-header">
                    <h2>Course Grades</h2>
                </div>
                <div class="table-wrapper">
                    <?php if (empty($grades)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">📊</div>
                            <h3>No Grades Yet</h3>
                            <p>Your grades will appear here once your instructors submit them.</p>
                        </div>
                    <?php else: ?>
                        <table class="grades-table">
                            <thead>
                                <tr>
                                    <th>Course</th>
                                    <th>Section</th>
                                    <th>Faculty</th>
                                    <th style="text-align: center;">Quiz</th>
                                    <th style="text-align: center;">Midterm</th>
                                    <th style="text-align: center;">Final Exam</th>
                                    <th style="text-align: center;">Overall</th>
                                    <th style="text-align: center;">Final Grade</th>
                                    <th style="text-align: center;">Letter</th>
                                    <th style="text-align: center;">Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($grades as $grade): 
                                    $grade_class = 'pass';
                                    if ($grade['final_grade'] !== null) {
                                        if ($grade['final_grade'] <= 1.50) $grade_class = 'excellent';
                                        elseif ($grade['final_grade'] <= 2.00) $grade_class = 'verygood';
                                        elseif ($grade['final_grade'] <= 2.50) $grade_class = 'good';
                                        elseif ($grade['final_grade'] <= 3.00) $grade_class = 'pass';
                                        elseif ($grade['final_grade'] <= 4.00) $grade_class = 'conditional';
                                        else $grade_class = 'failed';
                                    }
                                ?>
                                    <tr>
                                        <td>
                                            <div class="course-info">
                                                <span class="course-code"><?php echo htmlspecialchars($grade['course_code']); ?></span>
                                                <span class="course-name"><?php echo htmlspecialchars($grade['course_name']); ?></span>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($grade['section_name']); ?></td>
                                        <td><?php echo htmlspecialchars($grade['faculty_name']); ?></td>
                                        <td class="score-display">
                                            <?php if ($grade['quiz_score'] !== null && $grade['quiz_total'] !== null): ?>
                                                <div class="score-fraction">
                                                    <span><?php echo number_format($grade['quiz_score'], 1); ?></span>
                                                    <span class="score-separator">/</span>
                                                    <span><?php echo $grade['quiz_total']; ?></span>
                                                </div>
                                            <?php else: ?>
                                                <span class="na-text">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="score-display">
                                            <?php if ($grade['midterm_score'] !== null && $grade['midterm_total'] !== null): ?>
                                                <div class="score-fraction">
                                                    <span><?php echo number_format($grade['midterm_score'], 1); ?></span>
                                                    <span class="score-separator">/</span>
                                                    <span><?php echo $grade['midterm_total']; ?></span>
                                                </div>
                                            <?php else: ?>
                                                <span class="na-text">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="score-display">
                                            <?php if ($grade['final_score'] !== null && $grade['final_total'] !== null): ?>
                                                <div class="score-fraction">
                                                    <span><?php echo number_format($grade['final_score'], 1); ?></span>
                                                    <span class="score-separator">/</span>
                                                    <span><?php echo $grade['final_total']; ?></span>
                                                </div>
                                            <?php else: ?>
                                                <span class="na-text">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="score-display">
                                            <?php if ($grade['weighted_percentage'] !== null): ?>
                                                <strong style="color: var(--accent-blue);"><?php echo number_format($grade['weighted_percentage'], 2); ?>%</strong>
                                            <?php else: ?>
                                                <span class="na-text">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if ($grade['final_grade'] !== null): ?>
                                                <div class="grade-badge <?php echo $grade_class; ?>">
                                                    <span class="grade-value"><?php echo number_format($grade['final_grade'], 2); ?></span>
                                                    <?php if ($grade['weighted_percentage']): ?>
                                                        <span class="grade-percent"><?php echo number_format($grade['weighted_percentage'], 1); ?>%</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="na-text">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if ($grade['letter_grade']): ?>
                                                <span class="letter-badge grade-badge <?php echo $grade_class; ?>">
                                                    <?php echo htmlspecialchars($grade['letter_grade']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="na-text">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <button type="button" class="details-btn" onclick='viewGradeDetails(<?php echo json_encode($grade); ?>)'>
                                                View Details
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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
                    <p style="margin: 5px 0;"><strong>Course:</strong> <span id="detailCourseName"></span></p>
                    <p style="margin: 5px 0;"><strong>Section:</strong> <span id="detailSectionName"></span></p>
                    <p style="margin: 5px 0;"><strong>Faculty:</strong> <span id="detailFacultyName"></span></p>
                </div>
                
                <div id="gradeBreakdown"></div>
            </div>
        </div>
    </div>

    <script>
        function viewGradeDetails(gradeData) {
            document.getElementById('detailCourseName').textContent = gradeData.course_code + ' - ' + gradeData.course_name;
            document.getElementById('detailSectionName').textContent = gradeData.section_name;
            document.getElementById('detailFacultyName').textContent = gradeData.faculty_name;
            
            let html = '';
            
            if (gradeData.attendance !== null && gradeData.attendance !== undefined) {
                html += `<div class="grade-detail-row">
                    <span class="grade-detail-label">Attendance</span>
                    <span class="grade-detail-value">${gradeData.attendance}</span>
                </div>`;
            }
            
            if (gradeData.recitation !== null && gradeData.recitation !== undefined) {
                html += `<div class="grade-detail-row">
                    <span class="grade-detail-label">Recitation</span>
                    <span class="grade-detail-value">${gradeData.recitation}</span>
                </div>`;
            }
            
            if (gradeData.quiz_score !== null && gradeData.quiz_total !== null) {
                html += `<div class="grade-detail-row">
                    <span class="grade-detail-label">Quiz</span>
                    <span class="grade-detail-value">${gradeData.quiz_score} / ${gradeData.quiz_total}</span>
                </div>`;
            }
            
            if (gradeData.project_score !== null && gradeData.project_total !== null) {
                html += `<div class="grade-detail-row">
                    <span class="grade-detail-label">Project</span>
                    <span class="grade-detail-value">${gradeData.project_score} / ${gradeData.project_total}</span>
                </div>`;
            }
            
            if (gradeData.midterm_score !== null && gradeData.midterm_total !== null) {
                html += `<div class="grade-detail-row">
                    <span class="grade-detail-label">Midterm Exam</span>
                    <span class="grade-detail-value">${gradeData.midterm_score} / ${gradeData.midterm_total}</span>
                </div>`;
            }
            
            if (gradeData.final_score !== null && gradeData.final_total !== null) {
                html += `<div class="grade-detail-row">
                    <span class="grade-detail-label">Final Exam</span>
                    <span class="grade-detail-value">${gradeData.final_score} / ${gradeData.final_total}</span>
                </div>`;
            }
            
            html += `<div class="grade-detail-row">
                <span class="grade-detail-label">Weighted Percentage</span>
                <span class="grade-detail-value">${gradeData.weighted_percentage ? gradeData.weighted_percentage + '%' : 'N/A'}</span>
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