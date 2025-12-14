<?php
require_once 'config/db.php';

// Helper function to calculate percentage change
function calculatePercentageChange($current, $previous) {
    if ($previous == 0) {
        return $current > 0 ? 100 : 0; // Avoid division by zero
    }
    return (($current - $previous) / $previous) * 100;
}

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// --- START: NEW HELPER FUNCTION ---
function time_ago($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $weeks = floor($diff->d / 7);
    $days = $diff->d - ($weeks * 7);

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    
    // Manually set the values
    $diff_arr = [
        'y' => $diff->y,
        'm' => $diff->m,
        'w' => $weeks,
        'd' => $days,
        'h' => $diff->h,
        'i' => $diff->i,
        's' => $diff->s,
    ];

    foreach ($string as $k => &$v) {
        if ($diff_arr[$k]) {
            $v = $diff_arr[$k] . ' ' . $v . ($diff_arr[$k] > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}
// --- END: NEW HELPER FUNCTION ---

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$first_name = $_SESSION['first_name'];
$last_name = $_SESSION['last_name'];

// Initialize all variables to prevent errors
$stats = [];
$js_chart_labels = '[]';
$js_chart_data_students = '[]';
$recent_activity = [];
$unread_count = 0;
$notifications_list = [];

if ($role === 'admin') {
    // --- Advanced KPI Calculations ---
    $thisMonthStart = date('Y-m-01 00:00:00');
    $thisMonthEnd = date('Y-m-t 23:59:59');
    $lastMonthStart = date('Y-m-01 00:00:00', strtotime('-1 month'));
    $lastMonthEnd = date('Y-m-t 23:59:59', strtotime('-1 month'));

    $newStudentsThisMonth = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student' AND created_at BETWEEN '$thisMonthStart' AND '$thisMonthEnd'")->fetch_assoc()['count'];
    $newStudentsLastMonth = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student' AND created_at BETWEEN '$lastMonthStart' AND '$lastMonthEnd'")->fetch_assoc()['count'];
    $stats['new_students'] = $newStudentsThisMonth;
    $stats['new_students_perc'] = calculatePercentageChange($newStudentsThisMonth, $newStudentsLastMonth);

    $newGradesThisMonth = $conn->query("SELECT COUNT(*) as count FROM grades WHERE created_at BETWEEN '$thisMonthStart' AND '$thisMonthEnd'")->fetch_assoc()['count'];
    $newGradesLastMonth = $conn->query("SELECT COUNT(*) as count FROM grades WHERE created_at BETWEEN '$lastMonthStart' AND '$lastMonthEnd'")->fetch_assoc()['count'];
    $stats['new_grades'] = $newGradesThisMonth;
    $stats['new_grades_perc'] = calculatePercentageChange($newGradesThisMonth, $newGradesLastMonth);

    $stats['faculty'] = $conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'faculty'")->fetch_assoc()['total'];
    $stats['courses'] = $conn->query("SELECT COUNT(*) as total FROM courses")->fetch_assoc()['total'];
    
    // --- CHART DATA: New Students per Month (Last 12 Months) ---
    $chart_labels = [];
    $chart_data_students = [];
    for ($i = 11; $i >= 0; $i--) {
        $month = date('Y-m', strtotime("-$i months"));
        $month_start = date('Y-m-01 00:00:00', strtotime($month));
        $month_end = date('Y-m-t 23:59:59', strtotime($month));
        $chart_labels[] = date('M', strtotime($month_start));
        $count = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student' AND created_at BETWEEN '$month_start' AND '$month_end'")->fetch_assoc()['count'];
        $chart_data_students[] = $count;
    }
    $js_chart_labels = json_encode($chart_labels);
    $js_chart_data_students = json_encode($chart_data_students);
    
    // --- RECENT ACTIVITY TABLE DATA ---
    $recent_students = $conn->query("SELECT first_name, last_name, email, created_at FROM users WHERE role = 'student' ORDER BY created_at DESC LIMIT 5");
    $recent_grades = $conn->query("SELECT g.id, g.letter_grade, g.created_at, c.course_name 
                                   FROM grades g 
                                   JOIN sections s ON g.section_id = s.id
                                   JOIN courses c ON s.course_id = c.id
                                   ORDER BY g.created_at DESC LIMIT 5");
    while ($row = $recent_students->fetch_assoc()) {
        $row['type'] = 'student';
        $recent_activity[] = $row;
    }
    while ($row = $recent_grades->fetch_assoc()) {
        $row['type'] = 'grade';
        $recent_activity[] = $row;
    }
    usort($recent_activity, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    $recent_activity = array_slice($recent_activity, 0, 5);

    // --- NOTIFICATION DATA ---
    $admin_id = $user_id; 
    $stmt_count = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE recipient_id = ? AND is_read = 0");
    $stmt_count->bind_param('i', $admin_id);
    $stmt_count->execute();
    $unread_count = $stmt_count->get_result()->fetch_assoc()['count'];
    $stmt_count->close();
    
    $stmt_list = $conn->prepare("SELECT * FROM notifications WHERE recipient_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt_list->bind_param('i', $admin_id);
    $stmt_list->execute();
    $notifications_list = $stmt_list->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_list->close();

} elseif ($role === 'faculty') {
    // Faculty logic
    $sql = "SELECT COUNT(*) as total FROM sections WHERE faculty_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stats['courses'] = $stmt->get_result()->fetch_assoc()['total'];
    
    $sql = "SELECT COUNT(DISTINCT e.student_id) as total FROM enrollments e 
            JOIN sections s ON e.section_id = s.id WHERE s.faculty_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stats['students'] = $stmt->get_result()->fetch_assoc()['total'];

} elseif ($role === 'student') {
    // Student logic
    $sql = "SELECT COUNT(*) as total FROM enrollments WHERE student_id = ? AND status = 'enrolled'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stats['courses'] = $stmt->get_result()->fetch_assoc()['total'];
    
    $sql = "SELECT AVG(final_grade) as avg FROM grades WHERE student_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $avg_grade = $stmt->get_result()->fetch_assoc()['avg'];
    $stats['avg_grade'] = $avg_grade ? number_format($avg_grade, 2) : 'N/A';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Grade Recording System</title>
    <link rel="stylesheet" href="css/style.css">

    <style>
        /* Main Layout */
        :root {
            --dark-bg-1: #121212;
            --dark-bg-2: #1e1e1e; /* Card background */
            --dark-border: #333;
            --text-light: #e0e0e0;
            --text-muted: #aaa;
            --accent-green: #4ade80;
            --accent-red: #f87171;
        }
        body {
            background-color: var(--dark-bg-1);
            color: var(--text-light);
        }
        
        /* THIS IS THE RENAMED, CORRECTED HEADER CLASS */
        .page-header-main {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 20px;
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
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px; /* Space between bell and user avatar */
        }

        /* New KPI Card Styles */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
        }
        .kpi-card {
            background-color: var(--dark-bg-2);
            border: 1px solid var(--dark-border);
            border-radius: 12px;
            padding: 20px;
            position: relative;
        }
        .kpi-label {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 8px;
        }
        .kpi-number {
            font-size: 2.2rem;
            font-weight: 700;
            color: #fff;
            line-height: 1.1;
            margin-bottom: 15px;
        }
        .kpi-change {
            font-size: 0.9rem;
            font-weight: 600;
        }
        .kpi-positive { color: var(--accent-green); }
        .kpi-negative { color: var(--accent-red); }
        .kpi-neutral { color: var(--text-muted); }

        /* Analytics Chart Container */
        .chart-container {
            background-color: var(--dark-bg-2);
            border: 1px solid var(--dark-border);
            border-radius: 12px;
            padding: 25px;
            margin-top: 25px;
            height: 400px; /* Fixed height for chart */
        }
        .chart-container h2 {
            color: #fff;
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 1.25rem;
        }

        /* Recent Activity Table */
        .table-container {
            background-color: var(--dark-bg-2);
            border: 1px solid var(--dark-border);
            border-radius: 12px;
            padding: 25px;
            margin-top: 25px;
        }
        .table-container h2 {
            color: #fff;
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 1.25rem;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        .table th, .table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--dark-border);
            color: var(--text-light);
        }
        .table th {
            color: var(--text-muted);
            font-size: 0.85rem;
            text-transform: uppercase;
        }
        .table tbody tr:last-child td {
            border-bottom: none;
        }
        .table-status {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .status-student {
            background-color: rgba(59, 130, 246, 0.2);
            color: #60a5fa;
        }
        .status-grade {
            background-color: rgba(74, 222, 128, 0.2);
            color: var(--accent-green);
        }

        /* --- NEW Notification System Styles (to match image) --- */
        .notification-bell {
            position: relative;
            cursor: pointer;
        }
        .bell-icon {
            color: var(--text-muted);
            width: 24px; /* Set fixed size for SVG */
            height: 24px;
            transition: color 0.2s;
        }
        .bell-icon:hover {
            color: var(--text-light);
        }
        .notification-badge {
            position: absolute;
            top: -2px;
            right: -4px;
            background-color: var(--accent-red);
            width: 10px;
            height: 10px;
            border-radius: 50%;
            border: 2px solid var(--dark-bg-1);
        }
        /* The Dropdown Panel */
        .notification-dropdown {
            display: none; /* Hidden by default */
            position: absolute;
            top: 55px; /* Drop below the header */
            right: 0;
            width: 400px; /* Wider */
            background-color: var(--dark-bg-2);
            border-radius: 12px;
            border: 1px solid var(--dark-border);
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            z-index: 1000;
        }
        .notification-dropdown.show {
            display: block; /* JS will toggle this class */
        }
        /* Dropdown Header */
        .notification-dropdown-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            border-bottom: 1px solid var(--dark-border);
        }
        .notification-dropdown-header h3 {
            margin: 0;
            color: var(--text-light);
            font-size: 1.1rem;
        }
        .mark-as-read-btn {
            font-size: 0.85rem;
            color: var(--text-muted);
            cursor: pointer;
            text-decoration: none;
            transition: color 0.2s;
        }
        .mark-as-read-btn:hover {
            color: var(--text-light);
        }
        /* Notification List */
        .notification-list {
            list-style: none;
            padding: 0;
            margin: 0;
            max-height: 400px;
            overflow-y: auto;
        }
        .notification-item {
            display: flex;
            padding: 16px 20px;
            gap: 15px;
            border-bottom: 1px solid var(--dark-border);
            transition: background-color 0.2s;
            text-decoration: none;
        }
        .notification-item:last-child {
            border-bottom: none;
        }
        .notification-item:hover {
            background-color: #2a2a2a;
        }
        .notification-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #333;
            color: var(--text-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0; 
        }
        .notification-content {
            flex-grow: 1;
        }
        .notification-message {
            font-size: 0.95rem;
            color: var(--text-light);
            line-height: 1.4;
        }
        .notification-timestamp {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: 4px;
        }
        .notification-unread-dot {
            width: 8px;
            height: 8px;
            background-color: var(--accent-red);
            border-radius: 50%;
            flex-shrink: 0;
            margin-top: 6px; 
        }
        .notification-empty-state {
            padding: 40px 20px;
            text-align: center;
            color: var(--text-muted);
        }
        .header-user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: #333;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
        }
        .sidebar-footer .user-info {
            display: none; /* Hide old avatar from sidebar footer */
        }
        
        /* Styles for Faculty/Student simple stat cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
        }
        .stat-card {
            background: var(--dark-bg-2);
            border: 1px solid var(--dark-border);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .stat-icon {
            font-size: 2rem;
        }
        .stat-content h3 {
            margin: 0 0 5px 0;
            font-size: 1.8rem;
            color: var(--text-light);
        }
        .stat-content p {
            margin: 0;
            color: var(--text-muted);
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="container-fluid">
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>GRS</h2>
                <p>Grade Recording</p>
            </div>
            
            <nav class="sidebar-nav">
                <?php if ($role === 'admin'): ?>
                    <a href="dashboard.php" class="nav-item active">
                        <span class="icon">📊</span> Dashboard
                    </a>
                    <a href="admin/manage-users.php" class="nav-item">
                        <span class="icon">👥</span> Manage Users
                    </a>
                    <a href="admin/manage-courses.php" class="nav-item">
                        <span class="icon">📚</span> Manage Courses
                    </a>
                    <a href="admin/manage-sections.php" class="nav-item">
                        <span class="icon">📋</span> Manage Sections
                    </a>
                    <a href="admin/manage-terms.php" class="nav-item">
                        <span class="icon">📅</span> Manage Terms
                    </a>
                    <a href="admin/manage-grades.php" class="nav-item">
                        <span class="icon">📈</span> Manage Grades
                    </a>
                    <a href="admin/reports.php" class="nav-item">
                        <span class="icon">📑</span> Reports
                    </a>
                    <a href="admin/system-logs.php" class="nav-item">
                        <span class="icon">🔍</span> System Logs
                    </a>
                <?php elseif ($role === 'faculty'): ?>
                    <a href="dashboard.php" class="nav-item active">
                        <span class="icon">📊</span> Dashboard
                    </a>
                    <a href="faculty/my-courses.php" class="nav-item">
                        <span class="icon">📚</span> My Courses
                    </a>
                    <a href="faculty/enroll-students.php" class="nav-item">
                        <span class="icon">👥</span> Enroll Students
                    </a>
                    <a href="faculty/submit-grades.php" class="nav-item">
                        <span class="icon">✏️</span> Submit Grades
                    </a>
                    <a href="faculty/view-grades.php" class="nav-item">
                        <span class="icon">📊</span> View Grades
                    </a>
                    <a href="faculty/announcements.php" class="nav-item">
                        <span class="icon">📢</span> Announcements
                    </a>
                <?php elseif ($role === 'student'): ?>
                    <a href="dashboard.php" class="nav-item active">
                        <span class="icon">📊</span> Dashboard
                    </a>
                    <a href="student/my-courses.php" class="nav-item">
                        <span class="icon">📚</span> My Courses
                    </a>
                    <a href="student/view-grades.php" class="nav-item">
                        <span class="icon">📊</span> My Grades
                    </a>
                    <a href="student/announcement.php" class="nav-item">
                    <span class="icon">📢</span> Announcements
                </a>
                    <a href="student/transcript.php" class="nav-item">
                        <span class="icon">📄</span> Transcript
                    </a>
                <?php endif; ?>
            </nav>
            
            <div class="sidebar-footer">
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($first_name, 0, 1) . substr($last_name, 0, 1)); ?></div>
                    <div class="user-details">
                        <p class="user-name"><?php echo htmlspecialchars($first_name . ' ' . $last_name); ?></p>
                        <p class="user-role"><?php echo ucfirst($role); ?></p>
                    </div>
                </div>
                <a href="logout.php" class="btn btn-logout">Logout</a>
            </div>
        </div>
        
        <div class="main-content">
            <div class="page-header-main">
    
                <div class="header-left">
                    <h1>Welcome, <?php echo htmlspecialchars($first_name); ?>!</h1>
                    <p>Dashboard Overview</p>
                </div>

                <div class="header-right">

                    <?php if ($role === 'admin'): ?>
                        <div class="notification-bell">
                            <span class="bell-icon"> <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/>
                                </svg>
                            </span>
                            
                            <?php if ($unread_count > 0): ?>
                                <span class="notification-badge" id="notificationBadge"></span>
                            <?php endif; ?>
                            
                            <div class="notification-dropdown" id="notificationDropdown">
                                <div class="notification-dropdown-header">
                                    <h3>Notifications</h3>
                                    <a href="#" class="mark-as-read-btn" onclick="markAllAsRead(event)">
                                        ✔ Mark all as read
                                    </a>
                                </div>
                                <ul class="notification-list" id="notificationList">
                                    <?php if (empty($notifications_list)): ?>
                                        <li class="notification-empty-state">No new notifications.</li>
                                    <?php else: ?>
                                        <?php foreach ($notifications_list as $notif): 
                                            // Determine icon based on message
                                            $icon = '🔔'; // Default
                                            if (strpos($notif['message'], 'grade') !== false) $icon = '✏️';
                                            if (strpos($notif['message'], 'enrolled') !== false) $icon = '👥';
                                        ?>
                                            <a href="<?php echo htmlspecialchars($notif['link']); ?>" class="notification-item <?php echo $notif['is_read'] == 0 ? 'unread' : ''; ?>">
                                                <div class="notification-avatar">
                                                    <span><?php echo $icon; ?></span>
                                                </div>
                                                <div class="notification-content">
                                                    <div class="notification-message">
                                                        <?php echo htmlspecialchars($notif['message']); ?>
                                                    </div>
                                                    <div class="notification-timestamp">
                                                        <?php echo time_ago($notif['created_at']); ?>
                                                    </div>
                                                </div>
                                                <?php if ($notif['is_read'] == 0): ?>
                                                    <div class="notification-unread-dot"></div>
                                                <?php endif; ?>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                        <?php endif; ?>

                    <div class="header-user-avatar">
                        <?php echo strtoupper(substr($first_name, 0, 1) . substr($last_name, 0, 1)); ?>
                    </div>
                </div>
            </div>
            
            <?php if ($role === 'admin'): ?>
                <div class="kpi-grid">
                    <div class="kpi-card">
                        <div class="kpi-label">New Students (This Month)</div>
                        <div class="kpi-number"><?php echo $stats['new_students']; ?></div>
                        <?php
                        $perc_class = $stats['new_students_perc'] >= 0 ? 'kpi-positive' : 'kpi-negative';
                        $perc_sign = $stats['new_students_perc'] >= 0 ? '+' : '';
                        echo sprintf('<div class="kpi-change %s">%s%.0f%% vs last month</div>', $perc_class, $perc_sign, $stats['new_students_perc']);
                        ?>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-label">Grades Added (This Month)</div>
                        <div class="kpi-number"><?php echo $stats['new_grades']; ?></div>
                        <?php
                        $perc_class = $stats['new_grades_perc'] >= 0 ? 'kpi-positive' : 'kpi-negative';
                        $perc_sign = $stats['new_grades_perc'] >= 0 ? '+' : '';
                        echo sprintf('<div class="kpi-change %s">%s%.0f%% vs last month</div>', $perc_class, $perc_sign, $stats['new_grades_perc']);
                        ?>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-label">Total Faculty</div>
                        <div class="kpi-number"><?php echo $stats['faculty']; ?></div>
                        <div class="kpi-change kpi-neutral">All-time total</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-label">Total Courses</div>
                        <div class="kpi-number"><?php echo $stats['courses']; ?></div>
                        <div class="kpi-change kpi-neutral">All-time total</div>
                    </div>
                </div>
                
                <div class="chart-container">
                    <h2>New Student Registrations (Last 12 Months)</h2>
                    <canvas id="analyticsChart"></canvas>
                </div>

                <div class="table-container">
                    <h2>Recent Activity</h2>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Detail</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_activity as $activity): ?>
                                <tr>
                                    <?php if ($activity['type'] === 'student'): ?>
                                        <td><span class="table-status status-student">New Student</span></td>
                                        <td><?php echo htmlspecialchars($activity['first_name'] . ' ' . $activity['last_name']); ?> (<?php echo htmlspecialchars($activity['email']); ?>)</td>
                                    <?php else: // type is 'grade' ?>
                                        <td><span class="table-status status-grade">New Grade</span></td>
                                        <td>Grade '<?php echo htmlspecialchars($activity['letter_grade']); ?>' submitted for <?php echo htmlspecialchars($activity['course_name']); ?></td>
                                    <?php endif; ?>
                                    <td><?php echo date('M d, Y', strtotime($activity['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($recent_activity)): ?>
                                <tr><td colspan="3" style="text-align: center; color: var(--text-muted);">No recent activity found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
            <?php elseif ($role === 'faculty'): ?>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">📚</div>
                        <div class="stat-content">
                            <h3><?php echo $stats['courses']; ?></h3>
                            <p>My Courses</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">👥</div>
                        <div class="stat-content">
                            <h3><?php echo $stats['students']; ?></h3>
                            <p>Total Students</p>
                        </div>
                    </div>
                </div>
            <?php elseif ($role === 'student'): ?>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">📚</div>
                        <div class="stat-content">
                            <h3><?php echo $stats['courses']; ?></h3>
                            <p>Enrolled Courses</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">📊</div>
                        <div class="stat-content">
                            <h3><?php echo $stats['avg_grade']; ?></h3>
                            <p>Average Grade</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
    // --- Chart.js Code ---
    document.addEventListener("DOMContentLoaded", () => {
        
        // This only runs if the chart element exists on the page (i.e., for admin)
        const canvasElement = document.getElementById('analyticsChart');
        
        // Check if the canvas element exists
        if (canvasElement) { 
            
            // *** THIS IS THE FIX ***
            // We must get the 2D CONTEXT from the element.
            const ctx = canvasElement.getContext('2d'); 

            // Get data from PHP
            const labels = <?php echo $js_chart_labels; ?>;
            const data = <?php echo $js_chart_data_students; ?>;

            // Create the gradient (This will now work)
            let gradient = ctx.createLinearGradient(0, 0, 0, 400); 
            gradient.addColorStop(0, 'rgba(74, 222, 128, 0.5)'); // accent-green
            gradient.addColorStop(1, 'rgba(74, 222, 128, 0.0)');

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'New Students',
                        data: data,
                        backgroundColor: gradient,
                        borderColor: '#4ade80',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { color: '#aaa', stepSize: 1 },
                            grid: { color: '#333' }
                        },
                        x: {
                            ticks: { color: '#aaa' },
                            grid: { display: false }
                        }
                    }
                }
            });
        } // End of if(canvasElement)

        // --- Notification Bell JS (Admin Only) ---
        <?php if ($role === 'admin'): ?>
        
        const bellIcon = document.querySelector('.notification-bell');
        const dropdown = document.getElementById('notificationDropdown');
        
        if (bellIcon) {
            bellIcon.addEventListener('click', function(event) {
                event.stopPropagation(); // Prevents window.click from firing
                dropdown.classList.toggle('show');
                
                // This version marks as read when you OPEN it
                const badge = document.getElementById('notificationBadge');
                if (dropdown.classList.contains('show') && badge) {
                     markAllAsRead(event, true); // pass 'true' to skip event prevention
                }
            });
        }
    
        // Close dropdown if clicking anywhere else on the screen
        window.addEventListener('click', function(e) {   
            if (dropdown && dropdown.classList.contains('show') && !bellIcon.contains(e.target)) {
                dropdown.classList.remove('show');
            }
        });

        <?php endif; ?>
    }); // End of DOMContentLoaded

    <?php if ($role === 'admin'): ?>
    // This function must be global so onclick can find it
    function markAllAsRead(event, isToggle = false) {
        if (!isToggle) {
            event.preventDefault(); // Stop link from navigating
            event.stopPropagation(); // Stop dropdown from closing
        }

        const badge = document.getElementById('notificationBadge');
        const list = document.getElementById('notificationList');

        // 1. Tell server to mark all as read
        fetch('admin/ajax-mark-all-read.php') // Make sure this path is correct
            .then(response => response.text())
            .then(data => {
                if (data === 'success') {
                    if (badge) {
                        badge.style.display = 'none';
                    }
                    list.querySelectorAll('.notification-unread-dot').forEach(dot => {
                        dot.style.display = 'none';
                    });
                    
                    // Find the button and update its text
                    const readBtn = document.querySelector('.mark-as-read-btn');
                    if (readBtn) {
                        readBtn.innerText = '✔ All read';
                    }
                }
            })
            .catch(error => console.error('Error marking notifications as read:', error));
    }
    <?php endif; ?>
    // --- End Notification Bell JS ---
    </script>
    
</body>
</html>