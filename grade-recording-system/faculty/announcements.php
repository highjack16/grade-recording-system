<?php
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'faculty') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Handle announcement submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'post_announcement') {
    $section_id = intval($_POST['section_id']);
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);

    if (empty($title) || empty($content)) {
        $message = 'Please fill in all fields.';
        $message_type = 'error';
    } else {
        // Verify section belongs to this faculty
        $verify_sql = "SELECT id FROM sections WHERE id = ? AND faculty_id = ?";
        $verify_stmt = $conn->prepare($verify_sql);
        $verify_stmt->bind_param('ii', $section_id, $user_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();

        if ($verify_result->num_rows === 0) {
            $message = 'Invalid section selected.';
            $message_type = 'error';
        } else {
            $insert_sql = "INSERT INTO announcements (section_id, faculty_id, title, content) VALUES (?, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param('iiss', $section_id, $user_id, $title, $content);

            if ($insert_stmt->execute()) {
                $message = 'Announcement posted successfully!';
                $message_type = 'success';
            } else {
                $message = 'Error posting announcement. Please try again.';
                $message_type = 'error';
            }
        }
    }
}

// Handle announcement deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_announcement') {
    $announcement_id = intval($_POST['announcement_id']);

    // Verify announcement belongs to this faculty
    $verify_sql = "SELECT id FROM announcements WHERE id = ? AND faculty_id = ?";
    $verify_stmt = $conn->prepare($verify_sql);
    $verify_stmt->bind_param('ii', $announcement_id, $user_id);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();

    if ($verify_result->num_rows > 0) {
        $delete_sql = "DELETE FROM announcements WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param('i', $announcement_id);

        if ($delete_stmt->execute()) {
            $message = 'Announcement deleted successfully!';
            $message_type = 'success';
        } else {
            $message = 'Error deleting announcement.';
            $message_type = 'error';
        }
    }
}

// Get faculty's sections
$sections_sql = "
    SELECT s.id, s.section_name, c.course_code, c.course_name
    FROM sections s
    JOIN courses c ON s.course_id = c.id
    WHERE s.faculty_id = ? AND s.status = 'active'
    ORDER BY c.course_code, s.section_name
";
$sections_stmt = $conn->prepare($sections_sql);
$sections_stmt->bind_param('i', $user_id);
$sections_stmt->execute();
$sections_result = $sections_stmt->get_result();
$sections = $sections_result->fetch_all(MYSQLI_ASSOC);

// Get faculty's announcements
$announcements_sql = "
    SELECT 
        a.id,
        a.title,
        a.content,
        a.created_at,
        s.section_name,
        c.course_code
    FROM announcements a
    JOIN sections s ON a.section_id = s.id
    JOIN courses c ON s.course_id = c.id
    WHERE a.faculty_id = ?
    ORDER BY a.created_at DESC
";
$announcements_stmt = $conn->prepare($announcements_sql);
$announcements_stmt->bind_param('i', $user_id);
$announcements_stmt->execute();
$announcements_result = $announcements_stmt->get_result();
$announcements = $announcements_result->fetch_all(MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements - Grade Recording System</title>
    <link rel="stylesheet" href="../css/style.css">
</head>

<div class="pattern">

</div>


<body>
    <div class="container-fluid">
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>GRS</h2>
                <p>Faculty Portal</p>
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
                <a href="submit-grades.php" class="nav-item">
                    <span class="icon">✏️</span> Submit Grades
                </a>
                <a href="view-grades.php" class="nav-item">
                    <span class="icon">📊</span> View Grades
                </a>
                <a href="announcement.php" class="nav-item active">
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
                    <h1>Announcements</h1>
                    <p>Post and manage announcements for your sections</p>
                </div>
                <button type="button" class="btn btn-primary" onclick="openAnnouncementsModal()">View All Announcements</button>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h2>Post New Announcement</h2>
                </div>
                <div class="card-body">
                    <form method="POST" class="announcement-form">
                        <input type="hidden" name="action" value="post_announcement">
                        
                        <div class="form-group">
                            <label for="section_id">Select Section *</label>
                            <select id="section_id" name="section_id" required>
                                <option value="">-- Choose a Section --</option>
                                <?php foreach ($sections as $section): ?>
                                    <option value="<?php echo $section['id']; ?>">
                                        <?php echo htmlspecialchars($section['course_code'] . ' - ' . $section['section_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="title">Announcement Title *</label>
                            <input type="text" id="title" name="title" placeholder="Enter announcement title" required>
                        </div>

                        <div class="form-group">
                            <label for="content">Announcement Content *</label>
                            <textarea id="content" name="content" rows="6" placeholder="Enter announcement content" required></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary btn-block">Post Announcement</button>
                    </form>
                </div>
            </div>

        </div>
    </div>

    <div id="announcementsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Your Announcements</h2>
                <span class="modal-close" onclick="closeAnnouncementsModal()">&times;</span>
            </div>
            <div class="modal-body">
                <?php if (empty($announcements)): ?>
                    <div class="empty-state">
                        <p>No announcements posted yet.</p>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Course - Section</th>
                                <th>Posted Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($announcements as $announcement): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($announcement['title']); ?></strong>
                                        <br>
                                        <small style="color: #999;"><?php echo htmlspecialchars(substr($announcement['content'], 0, 100)) . (strlen($announcement['content']) > 100 ? '...' : ''); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($announcement['course_code'] . ' - ' . $announcement['section_name']); ?></td>
                                    <td><?php echo date('M d, Y h:i A', strtotime($announcement['created_at'])); ?></td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_announcement">
                                            <input type="hidden" name="announcement_id" value="<?php echo $announcement['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this announcement?');">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function openAnnouncementsModal() {
            document.getElementById('announcementsModal').style.display = 'flex';
        }

        function closeAnnouncementsModal() {
            document.getElementById('announcementsModal').style.display = 'none';
        }

        window.onclick = function(event) {
            const modal = document.getElementById('announcementsModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        }
    </script>
</body>
</html>
