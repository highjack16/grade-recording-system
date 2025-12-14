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

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add') {
            $term_name = $_POST['term_name'] ?? '';
            $start_date = $_POST['start_date'] ?? '';
            $end_date = $_POST['end_date'] ?? '';
            
            if (empty($term_name) || empty($start_date) || empty($end_date)) {
                $error = 'All fields are required';
            } else {
                $sql = "INSERT INTO terms (term_name, start_date, end_date) VALUES (?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('sss', $term_name, $start_date, $end_date);
                
                if ($stmt->execute()) {
                    $message = 'Term added successfully';
                    $action = "Added new term: $term_name";
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $log_sql = "INSERT INTO system_logs (user_id, action, ip_address) VALUES (?, ?, ?)";
                    $log_stmt = $conn->prepare($log_sql);
                    $log_stmt->bind_param('iss', $_SESSION['user_id'], $action, $ip);
                    $log_stmt->execute();
                } else {
                    $error = 'Error adding term: ' . $stmt->error;
                }
                $stmt->close();
            }
        } elseif ($_POST['action'] === 'delete') {
            $term_id = $_POST['term_id'] ?? '';
            if (!empty($term_id)) {
                $sql = "DELETE FROM terms WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('i', $term_id);
                
                if ($stmt->execute()) {
                    $message = 'Term deleted successfully';
                    $action = "Deleted term ID: $term_id";
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $log_sql = "INSERT INTO system_logs (user_id, action, ip_address) VALUES (?, ?, ?)";
                    $log_stmt = $conn->prepare($log_sql);
                    $log_stmt->bind_param('iss', $_SESSION['user_id'], $action, $ip);
                    $log_stmt->execute();
                } else {
                    $error = 'Error deleting term';
                }
                $stmt->close();
            }
        }
    }
}

$sql = "SELECT id, term_name, start_date, end_date, status FROM terms ORDER BY start_date DESC";
$result = $conn->query($sql);
$terms = $result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Terms - Grade Recording System</title>
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
                <a href="manage-courses.php" class="nav-item">
                    <span class="icon">📚</span> Manage Courses
                </a>
                <a href="manage-sections.php" class="nav-item">
                    <span class="icon">📋</span> Manage Sections
                </a>
                <a href="manage-terms.php" class="nav-item active">
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
    $page_title = "Manage Terms";
    $page_subtitle = "Create and manage academic terms";
    include '../includes/notification-header.php'; 
    ?>
    
    <div style="margin-bottom: 20px; text-align: right;">
        <button class="btn btn-primary" onclick="openModal('addTermModal')">+ Add Term</button>
    </div>
            
            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <div id="addTermModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2>Add New Term</h2>
                        <button class="modal-close" onclick="closeModal('addTermModal')">&times;</button>
                    </div>
                    <div class="modal-body">
                        <form method="POST" class="form">
                            <input type="hidden" name="action" value="add">
                            
                            <div class="form-group">
                                <label for="term_name">Term Name</label>
                                <input type="text" id="term_name" name="term_name" required placeholder="e.g., Fall 2024">
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="start_date">Start Date</label>
                                    <input type="date" id="start_date" name="start_date" required>
                                </div>
                                <div class="form-group">
                                    <label for="end_date">End Date</label>
                                    <input type="date" id="end_date" name="end_date" required>
                                </div>
                            </div>
                            
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" onclick="closeModal('addTermModal')">Cancel</button>
                                <button type="submit" class="btn btn-primary">Add Term</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2>All Terms</h2>
                </div>
                <div class="card-body">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Term Name</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($terms as $term): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($term['term_name']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($term['start_date'])); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($term['end_date'])); ?></td>
                                    <td><span class="badge badge-<?php echo $term['status']; ?>"><?php echo ucfirst($term['status']); ?></span></td>
                                    <td>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="term_id" value="<?php echo $term['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure?')">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
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
        
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>
