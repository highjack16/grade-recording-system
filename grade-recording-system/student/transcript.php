<?php
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get student info
$sql = "SELECT first_name, last_name, email FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

// Get all grades
$sql = "SELECT g.id, c.course_code, c.course_name, c.credits, s.section_name, t.term_name,
        g.final_grade, g.letter_grade
        FROM grades g
        JOIN sections s ON g.section_id = s.id
        JOIN courses c ON s.course_id = c.id
        JOIN terms t ON s.term_id = t.id
        WHERE g.student_id = ?
        ORDER BY t.start_date DESC, c.course_code";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
$grades = $result->fetch_all(MYSQLI_ASSOC);

// Calculate GPA using the numeric final_grade (1.00 - 5.00 scale)
// Note: In Philippines system, lower is better (1.00 is highest, 5.00 is failing)
$total_credits = 0;
$total_grade_points = 0;

foreach ($grades as $grade) {
    // Only count completed courses (not INC or failed courses)
    if ($grade['final_grade'] !== null && $grade['final_grade'] <= 3.00 && $grade['credits'] > 0) {
        $total_credits += $grade['credits'];
        // For GPA calculation, we sum up (grade * credits)
        $total_grade_points += $grade['final_grade'] * $grade['credits'];
    }
}

// Calculate weighted GPA
$gpa = $total_credits > 0 ? $total_grade_points / $total_credits : 0;

// Helper function to get grade badge class
function getGradeBadgeClass($letter_grade) {
    $upper = strtoupper($letter_grade);
    if (in_array($upper, ['A+', 'A', 'A-', 'VE', 'E'])) return 'grade-a';
    if (in_array($upper, ['B+', 'B', 'B-', 'VS'])) return 'grade-b';
    if (in_array($upper, ['C+', 'C', 'C-', 'S'])) return 'grade-c';
    if (in_array($upper, ['D', 'INC'])) return 'grade-d';
    return 'grade-f';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transcript - Grade Recording System</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        @media print {
            .sidebar, 
            .no-print,
            .print-buttons {
                display: none !important;
            }

            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 20px !important;
                max-width: 100% !important;
            }
            
            .container-fluid {
                padding: 0 !important;
                margin: 0 !important;
            }

            body {
                background: #fff !important;
                color: #000 !important;
            }
            
            .card {
                box-shadow: none !important;
                border: 1px solid #000 !important;
                background: #fff !important;
                page-break-inside: avoid;
                margin-bottom: 20px !important;
            }

            .card-header {
                background: #f5f5f5 !important;
                color: #000 !important;
                border-bottom: 2px solid #000 !important;
            }

            .card-body {
                background: #fff !important;
                color: #000 !important;
            }

            .table {
                border-collapse: collapse !important;
                width: 100% !important;
            }

            .table th, 
            .table td {
                border: 1px solid #000 !important;
                padding: 8px !important;
                color: #000 !important;
            }

            .table thead {
                background: #e0e0e0 !important;
            }

            .table thead th {
                font-weight: bold !important;
                color: #000 !important;
            }

            .gpa-badge,
            .grade-badge {
                border: 1px solid #000 !important;
                padding: 4px 8px !important;
                border-radius: 4px !important;
                background: #fff !important;
                color: #000 !important;
                font-weight: bold !important;
            }

            .student-info {
                color: #000 !important;
            }

            .student-info p {
                color: #000 !important;
            }

            .student-info strong {
                color: #000 !important;
            }

            .print-header {
                text-align: center;
                margin-bottom: 30px;
                border-bottom: 3px solid #000;
                padding-bottom: 20px;
            }

            .print-header h1 {
                color: #000 !important;
                margin: 10px 0;
                font-size: 24px;
            }

            .print-header p {
                color: #000 !important;
                margin: 5px 0;
            }

            .print-footer {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                text-align: center;
                font-size: 10px;
                color: #666;
                padding: 10px;
                border-top: 1px solid #ccc;
            }

            * {
                color: #000 !important;
            }

            h1, h2, h3, h4, h5, h6 {
                color: #000 !important;
            }
        }

        .print-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .btn-print,
        .btn-download {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-print {
            background: #6366f1;
            color: white;
        }

        .btn-print:hover {
            background: #4f46e5;
        }

        .btn-download {
            background: #10b981;
            color: white;
        }

        .btn-download:hover {
            background: #059669;
        }

        .gpa-badge {
            background: #10b981;
            color: white;
            padding: 4px 12px;
            border-radius: 6px;
            font-weight: bold;
        }

        .grade-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: bold;
            display: inline-block;
        }

        .grade-a { background: #10b981; color: white; }
        .grade-b { background: #3b82f6; color: white; }
        .grade-c { background: #f59e0b; color: white; }
        .grade-d { background: #ef4444; color: white; }
        .grade-f { background: #dc2626; color: white; }

        .student-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .student-info p {
            margin: 0;
            padding: 10px;
            background: rgba(99, 102, 241, 0.1);
            border-radius: 6px;
        }

        .print-header {
            display: none;
        }

        @media print {
            .print-header {
                display: block !important;
            }
        }

        .numeric-grade {
            color: #6366f1;
            font-weight: 600;
            font-size: 12px;
            display: block;
            margin-top: 2px;
        }

        .grade-cell {
            text-align: center;
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
                <a href="view-grades.php" class="nav-item">
                    <span class="icon">📊</span> My Grades
                </a>
                <a href="announcement.php" class="nav-item">
                    <span class="icon">📢</span> Announcements
                </a>
                <a href="transcript.php" class="nav-item active">
                    <span class="icon">📄</span> Transcript
                </a>
            </nav>
            
            <div class="sidebar-footer">
                <a href="../logout.php" class="btn btn-logout">Logout</a>
            </div>
        </div>
        
        <div class="main-content">
            <div class="print-header">
                <h1>GRADE RECORDING SYSTEM</h1>
                <p>Official Academic Transcript</p>
                <p>Printed on: <?php echo date('F d, Y'); ?></p>
            </div>

            <div class="header no-print" style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
                <div>
                    <h1>Academic Transcript</h1>
                    <p>Your complete academic record</p>
                </div>
            </div>

            <div class="print-buttons no-print">
                <button onclick="window.print()" class="btn-print">
                    <span>🖨️</span>
                    <span>Print Transcript</span>
                </button>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2>Student Information</h2>
                </div>
                <div class="card-body">
                    <div class="student-info">
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($student['email']); ?></p>
                        <p><strong>Total Credits:</strong> <?php echo number_format($total_credits, 1); ?></p>
                        <p><strong>Cumulative GPA:</strong> <span class="gpa-badge"><?php echo number_format($gpa, 2); ?></span></p>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2>Course History</h2>
                </div>
                <div class="card-body">
                    <?php if (empty($grades)): ?>
                        <p style="text-align: center; padding: 20px; color: #666;">No grades available yet.</p>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Course Code</th>
                                    <th>Course Name</th>
                                    <th>Term</th>
                                    <th style="text-align: center;">Grade</th>
                                    <th style="text-align: center;">Credits</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($grades as $grade): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($grade['course_code']); ?></td>
                                        <td><?php echo htmlspecialchars($grade['course_name']); ?></td>
                                        <td><?php echo htmlspecialchars($grade['term_name']); ?></td>
                                        <td class="grade-cell">
                                            <span class="grade-badge <?php echo getGradeBadgeClass($grade['letter_grade']); ?>">
                                                <?php echo htmlspecialchars($grade['letter_grade']); ?>
                                            </span>
                                            <?php if ($grade['final_grade'] !== null): ?>
                                                <span class="numeric-grade">(<?php echo number_format($grade['final_grade'], 2); ?>)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;"><?php echo number_format($grade['credits'], 1); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="font-weight: bold; background-color: rgba(99, 102, 241, 0.1);">
                                    <td colspan="4" style="text-align: right;">Total Credits:</td>
                                    <td style="text-align: center;"><?php echo number_format($total_credits, 1); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        function downloadPDF() {
            alert('Please use your browser\'s print dialog and select "Save as PDF" as the printer option.');
            window.print();
        }
    </script>
</body>
</html>