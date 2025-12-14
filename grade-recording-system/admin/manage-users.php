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
$search = '';

if (isset($_GET['search'])) {
    $search = $_GET['search'];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add') {
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';
            $first_name = $_POST['first_name'] ?? '';
            $last_name = $_POST['last_name'] ?? '';
            $role = $_POST['role'] ?? '';
            
            if (empty($email) || empty($password) || empty($first_name) || empty($last_name) || empty($role)) {
                $error = 'All fields are required';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters long';
            } else {
                // Check for duplicate email
                $check_sql = "SELECT id FROM users WHERE email = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param('s', $email);
                $check_stmt->execute();
                
                if ($check_stmt->get_result()->num_rows > 0) {
                    $error = 'Email already exists in the system';
                } else {
                    $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                    $sql = "INSERT INTO users (email, password, first_name, last_name, role) VALUES (?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param('sssss', $email, $hashed_password, $first_name, $last_name, $role);
                    
                    if ($stmt->execute()) {
                        $message = 'User added successfully';
                        // Log action
                        $action = "Added new user: $email (Role: $role)";
                        $ip = $_SERVER['REMOTE_ADDR'];
                        $log_sql = "INSERT INTO system_logs (user_id, action, ip_address) VALUES (?, ?, ?)";
                        $log_stmt = $conn->prepare($log_sql);
                        $log_stmt->bind_param('iss', $_SESSION['user_id'], $action, $ip);
                        $log_stmt->execute();
                    } else {
                        $error = 'Error adding user: ' . $stmt->error;
                    }
                    $stmt->close();
                }
                $check_stmt->close();
            }
        } elseif ($_POST['action'] === 'edit') {
            $user_id = $_POST['user_id'] ?? '';
            $first_name = $_POST['first_name'] ?? '';
            $last_name = $_POST['last_name'] ?? '';
            $role = $_POST['role'] ?? '';
            $password = $_POST['password'] ?? '';
            
            if (empty($user_id) || empty($first_name) || empty($last_name) || empty($role)) {
                $error = 'All fields are required';
            } elseif ($password && strlen($password) < 8) {
                $error = 'Password must be at least 8 characters long';
            } else {
                if (!empty($password)) {
                    $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                    $sql = "UPDATE users SET first_name = ?, last_name = ?, role = ?, password = ? WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param('ssssi', $first_name, $last_name, $role, $hashed_password, $user_id);
                } else {
                    $sql = "UPDATE users SET first_name = ?, last_name = ?, role = ? WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param('sssi', $first_name, $last_name, $role, $user_id);
                }
                
                if ($stmt->execute()) {
                    $message = 'User updated successfully';
                    // Log action
                    $action = "Updated user ID: $user_id (Name: $first_name $last_name, Role: $role)";
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $log_sql = "INSERT INTO system_logs (user_id, action, ip_address) VALUES (?, ?, ?)";
                    $log_stmt = $conn->prepare($log_sql);
                    $log_stmt->bind_param('iss', $_SESSION['user_id'], $action, $ip);
                    $log_stmt->execute();
                } else {
                    $error = 'Error updating user: ' . $stmt->error;
                }
                $stmt->close();
            }
        } elseif ($_POST['action'] === 'delete') {
            $user_id = $_POST['user_id'] ?? '';
            if (!empty($user_id) && $user_id != $_SESSION['user_id']) {
                $sql = "DELETE FROM users WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('i', $user_id);
                
                if ($stmt->execute()) {
                    $message = 'User deleted successfully';
                    // Log action
                    $action = "Deleted user ID: $user_id";
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $log_sql = "INSERT INTO system_logs (user_id, action, ip_address) VALUES (?, ?, ?)";
                    $log_stmt = $conn->prepare($log_sql);
                    $log_stmt->bind_param('iss', $_SESSION['user_id'], $action, $ip);
                    $log_stmt->execute();
                } else {
                    $error = 'Error deleting user';
                }
                $stmt->close();
            } elseif ($user_id == $_SESSION['user_id']) {
                $error = 'Cannot delete your own account';
            }
        }
    }
}

// Get all users with search
$sql = "SELECT id, email, first_name, last_name, role, status, created_at FROM users WHERE 1=1";

if (!empty($search)) {
    $search_term = '%' . $search . '%';
    $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
}

$sql .= " ORDER BY created_at DESC";

if (!empty($search)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sss', $search_term, $search_term, $search_term);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

$users = $result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - Grade Recording System</title>
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
                <a href="manage-users.php" class="nav-item active">
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
    $page_title = "Manage Users";
    $page_subtitle = "Add, edit, and delete user accounts";
    include '../includes/notification-header.php'; 
    ?>
    
    <div style="margin-bottom: 20px; text-align: right;">
        <button class="btn btn-primary btn-add-user" onclick="openAddUserModal()">+ Add User</button>
    </div>
            
            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <!-- Search Bar -->
            <div class="card">
                <div class="card-body">
                    <form method="GET" style="display: flex; gap: 10px;">
                        <input type="text" name="search" placeholder="Search by name or email..." value="<?php echo htmlspecialchars($search); ?>" style="flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                        <button type="submit" class="btn btn-primary">🔍 Search</button>
                        <a href="manage-users.php" class="btn btn-secondary" style="text-decoration: none;">Clear</a>
                    </form>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2>All Users</h2>
                </div>
                <div class="card-body">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><span class="badge badge-<?php echo $user['role']; ?>"><?php echo ucfirst($user['role']); ?></span></td>
                                    <td><span class="badge badge-<?php echo $user['status']; ?>"><?php echo ucfirst($user['status']); ?></span></td>
                                    <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                    <td style="display: flex; gap: 5px;">
                                        <button type="button" class="btn btn-primary btn-sm" onclick="openEditUserModal(<?php echo htmlspecialchars(json_encode($user)); ?>)" style="padding: 6px 12px; font-size: 13px;">Edit</button>
                                        <?php if ($user['id'] !== $_SESSION['user_id']): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" style="padding: 6px 12px; font-size: 13px;" onclick="return confirm('Are you sure you want to delete this user?')">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add User Modal -->
    <div id="addUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add New User</h2>
                <button class="modal-close" onclick="closeAddUserModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" class="form">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name">First Name</label>
                            <input type="text" id="first_name" name="first_name" required>
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name</label>
                            <input type="text" id="last_name" name="last_name" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" required>
                        </div>
                        <div class="form-group">
                            <label for="role">Role</label>
                            <select id="role" name="role" required>
                                <option value="">Select Role</option>
                                <option value="admin">Admin</option>
                                <option value="faculty">Faculty</option>
                                <option value="student">Student</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password (Min. 8 characters)</label>
                        <input type="password" id="password" name="password" required minlength="8">
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeAddUserModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit User Modal -->
    <div id="editUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit User</h2>
                <button class="modal-close" onclick="closeEditUserModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" class="form">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="user_id" id="editUserId">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="editFirstName">First Name</label>
                            <input type="text" id="editFirstName" name="first_name" required>
                        </div>
                        <div class="form-group">
                            <label for="editLastName">Last Name</label>
                            <input type="text" id="editLastName" name="last_name" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="editEmail">Email (Read-only)</label>
                        <input type="email" id="editEmail" readonly style="background-color: #f5f5f5; cursor: not-allowed;">
                    </div>
                    
                    <div class="form-group">
                        <label for="editRole">Role</label>
                        <select id="editRole" name="role" required>
                            <option value="">Select Role</option>
                            <option value="admin">Admin</option>
                            <option value="faculty">Faculty</option>
                            <option value="student">Student</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="editPassword">Password (Leave blank to keep current password)</label>
                        <input type="password" id="editPassword" name="password" minlength="8" placeholder="New password (optional)">
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeEditUserModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function openAddUserModal() {
            document.getElementById('addUserModal').style.display = 'flex';
        }
        
        function closeAddUserModal() {
            document.getElementById('addUserModal').style.display = 'none';
        }
        
        function openEditUserModal(userData) {
            document.getElementById('editUserId').value = userData.id;
            document.getElementById('editFirstName').value = userData.first_name;
            document.getElementById('editLastName').value = userData.last_name;
            document.getElementById('editEmail').value = userData.email;
            document.getElementById('editRole').value = userData.role;
            document.getElementById('editUserModal').style.display = 'flex';
        }
        
        function closeEditUserModal() {
            document.getElementById('editUserModal').style.display = 'none';
        }
        
        window.onclick = function(event) {
            const addModal = document.getElementById('addUserModal');
            const editModal = document.getElementById('editUserModal');
            if (event.target === addModal) {
                addModal.style.display = 'none';
            }
            if (event.target === editModal) {
                editModal.style.display = 'none';
            }
        }
    </script>
</body>
</html>