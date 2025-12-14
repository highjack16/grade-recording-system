<?php
require_once '../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$first_name = $_SESSION['first_name'];
$last_name = $_SESSION['last_name'];

$travel_time = null;
$current_time = new DateTime();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['travel_time'])) {
    $travel_time = $_POST['travel_time'];
    // Validate the time format
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $travel_time);
    if ($dt && $dt->format('Y-m-d\TH:i') === $travel_time) {
        $current_time = $dt;
    }
}

// Fetch logs with time travel data
$sql = "
    SELECT 
        l.id, 
        CONCAT(u.first_name, ' ', u.last_name) AS user_name, 
        l.action, 
        l.ip_address, 
        l.created_at,
        l.old_data,
        l.new_data
    FROM system_logs l
    LEFT JOIN users u ON l.user_id = u.id
    WHERE l.created_at <= ?
    ORDER BY l.created_at DESC
    LIMIT 100
";

$stmt = $conn->prepare($sql);
$travel_time_str = $current_time->format('Y-m-d H:i:s');
$stmt->bind_param('s', $travel_time_str);
$stmt->execute();
$result = $stmt->get_result();
$logs = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Logs - Grade Recording System</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .modal {
            display: none;
            position: fixed;
            z-index: 100;
            padding-top: 100px;
            left: 0; top: 0;
            width: 100%; height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
        }
        .modal-content {
            background-color: #fff;
            margin: auto;
            padding: 20px;
            border-radius: 10px;
            width: 70%;
            max-height: 80%;
            overflow-y: auto;
        }
        pre {
            background: #f7f7f7;
            padding: 10px;
            border-radius: 6px;
            overflow-x: auto;
        }
        .close {
            float: right;
            font-size: 20px;
            cursor: pointer;
            color: #333;
        }
        .btn-view {
            background: #007bff;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
        }
        .btn-view:hover {
            background: #0056b3;
        }
        /* Add time travel control styling */
        .time-travel-control {
            background:rgb(31, 31, 31);
            border: 2px solidrgb(0, 0, 0);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .time-travel-control label {
            font-weight: bold;
            color: white;
        }
        .time-travel-control input {
            padding: 8px 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
        }
        .time-travel-control button {
            background:rgb(169, 0, 0);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }
        .time-travel-control button:hover {
            background:rgb(65, 0, 0);
        }
        .time-travel-control .reset-btn {
            background: #6c757d;
        }
        .time-travel-control .reset-btn:hover {
            background: #5a6268;
        }
        .time-travel-status {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
            color: #856404;
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
                <a href="../dashboard.php" class="nav-item"><span class="icon">📊</span> Dashboard</a>
                <a href="manage-users.php" class="nav-item"><span class="icon">👥</span> Manage Users</a>
                <a href="manage-courses.php" class="nav-item"><span class="icon">📚</span> Manage Courses</a>
                <a href="manage-sections.php" class="nav-item"><span class="icon">📋</span> Manage Sections</a>
                <a href="manage-terms.php" class="nav-item"><span class="icon">📅</span> Manage Terms</a>
                <a href="manage-grades.php" class="nav-item"><span class="icon">📈</span> Manage Grades</a>
                <a href="reports.php" class="nav-item"><span class="icon">📑</span> Reports</a>
                <a href="system-logs.php" class="nav-item active"><span class="icon">🔍</span> System Logs</a>
            </nav>
            
            <div class="sidebar-footer">
                <a href="../logout.php" class="btn btn-logout">Logout</a>
            </div>
        </div>
        
        <div class="main-content">
        <?php 
    $page_title = "System Logs";
    $page_subtitle = "View system activity and user actions (with time travel data)";
    include '../includes/notification-header.php'; 
    ?>

            <div class="time-travel-control">
                <label for="travel_time">🕒 Travel to Time:</label>
                <form method="POST" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <input 
                        type="datetime-local" 
                        id="travel_time" 
                        name="travel_time" 
                        value="<?php echo $current_time->format('Y-m-d\TH:i'); ?>"
                    >
                    <button type="submit">Set</button>
                    <button type="button" onclick="resetTime()" class="reset-btn">Reset to Now</button>
                </form>
            </div>

            <?php if ($travel_time): ?>
            <div class="time-travel-status">
                ⏰ <strong>Time Travel Active:</strong> Viewing logs from <strong><?php echo $current_time->format('M d, Y H:i:s'); ?></strong> and earlier
            </div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header">
                    <h2>Recent Activity</h2>
                </div>
                <div class="card-body">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Action</th>
                                <th>IP Address</th>
                                <th>Timestamp</th>
                                <th>Changes</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($logs as $log): ?>
<tr>
    <td><?php echo htmlspecialchars($log['user_name'] ?? 'System'); ?></td>
    <td><?php echo htmlspecialchars($log['action']); ?></td>
    <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
    <td><?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?></td>
    <td>
        <?php if (!empty($log['old_data']) || !empty($log['new_data'])): ?>
            <?php 
                $logJson = htmlspecialchars(json_encode($log, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); 
            ?>
            <button class="btn-view" onclick='viewTimeTravel(<?php echo $logJson; ?>)'>View</button>
        <?php else: ?>
            <span style="color: #777;">No Data</span>
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

    <div id="timeTravelModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h3>🕒 Time Travel View</h3>
            <p><b>Action:</b> <span id="actionText"></span></p>
            <h4>Before (Old Data)</h4>
            <pre id="oldData"></pre>
            <h4>After (New Data)</h4>
            <pre id="newData"></pre>
        </div>
    </div>

    <script>
    function viewTimeTravel(log) {
        let oldData = {};
        let newData = {};

        try {
            oldData = log.old_data ? JSON.parse(log.old_data) : {};
        } catch (e) {
            oldData = { error: "Invalid JSON" };
        }

        try {
            newData = log.new_data ? JSON.parse(log.new_data) : {};
        } catch (e) {
            newData = { error: "Invalid JSON" };
        }

        document.getElementById('actionText').innerText = log.action || 'No action';
        document.getElementById('oldData').textContent = JSON.stringify(oldData, null, 4);
        document.getElementById('newData').textContent = JSON.stringify(newData, null, 4);

        document.getElementById('timeTravelModal').style.display = 'block';
    }

    function closeModal() {
        document.getElementById('timeTravelModal').style.display = 'none';
    }

    function resetTime() {
        document.getElementById('travel_time').value = new Date().toISOString().slice(0, 16);
        document.querySelector('form').submit();
    }

    window.onclick = function(event) {
        const modal = document.getElementById('timeTravelModal');
        if (event.target === modal) {
            closeModal();
        }
    }
</script>

</body>
</html>
