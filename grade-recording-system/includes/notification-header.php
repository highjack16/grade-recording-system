<?php
// includes/notification-header.php
// This file displays the notification bell and header section

// Fetch unread notification count
$stmt_count = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE recipient_id = ? AND is_read = 0");
$stmt_count->bind_param('i', $user_id);
$stmt_count->execute();
$unread_count = $stmt_count->get_result()->fetch_assoc()['count'];
$stmt_count->close();

// Fetch recent notifications
$stmt_list = $conn->prepare("SELECT * FROM notifications WHERE recipient_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt_list->bind_param('i', $user_id);
$stmt_list->execute();
$notifications_list = $stmt_list->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_list->close();

// Time ago function
if (!function_exists('time_ago')) {
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
}
?>

<!-- START OF HTML -->
<div class="page-header-main">
    <div class="header-left">
        <h1><?php echo isset($page_title) ? $page_title : 'Welcome, ' . htmlspecialchars($first_name) . '!'; ?></h1>
        <p><?php echo isset($page_subtitle) ? $page_subtitle : 'Dashboard Overview'; ?></p>
    </div>

    <div class="header-right">
        <!-- Notification Bell -->
        <div class="notification-bell">
            <span class="bell-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/>
                </svg>
            </span>
            
            <?php if ($unread_count > 0): ?>
                <span class="notification-badge" id="notificationBadge"></span>
            <?php endif; ?>
            
            <div class="notification-dropdown" id="notificationDropdown">
                <div class="notification-dropdown-header">
                    <h3>Notifications</h3>
                    <button class="notification-close-btn" onclick="closeNotificationDropdown(event)">&times;</button>
                </div>
                <ul class="notification-list" id="notificationList">
                    <?php if (empty($notifications_list)): ?>
                        <li class="notification-empty-state">No new notifications.</li>
                    <?php else: ?>
                        <?php foreach ($notifications_list as $notif): 
                            // Set icon based on message content only
                            $icon = '🔔';
                            $message_lower = strtolower($notif['message']);
                            
                            if (strpos($message_lower, 'grade') !== false) {
                                $icon = '✏️';
                            } elseif (strpos($message_lower, 'enrolled') !== false) {
                                $icon = '👥';
                            } elseif (strpos($message_lower, 'subject') !== false || strpos($message_lower, 'course') !== false) {
                                $icon = '📚';
                            }
                            
                            // Get the link, default to # if not set
                            $notif_link = $notif['link'] ?? '#';
                        ?>
                            <a href="<?php echo htmlspecialchars($notif_link); ?>" 
                               class="notification-item <?php echo $notif['is_read'] == 0 ? 'unread' : ''; ?>"
                               onclick="handleNotificationClick(event, <?php echo $notif['id']; ?>, '<?php echo htmlspecialchars($notif_link, ENT_QUOTES); ?>')">
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
                <div class="notification-footer">
                    <a href="#" class="mark-as-read-btn" onclick="markAllAsRead(event)">Mark all as read</a>
                    <a href="<?php echo '../' . $role; ?>/notifications.php" class="btn-view-all">View all notifications</a>
                </div>
            </div>
        </div>

        <div class="header-user-avatar">
            <?php echo strtoupper(substr($first_name, 0, 1) . substr($last_name, 0, 1)); ?>
        </div>
    </div>
</div>

<style>
    .page-header-main {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding-bottom: 20px;
    }
    .page-header-main h1 {
        color: var(--text-light, #e0e0e0);
        margin: 0;
        font-size: 1.8rem;
    }
    .page-header-main p {
        font-size: 0.9rem;
        color: var(--text-muted, #aaa);
        margin: 4px 0 0 0;
    }
    .header-right {
        display: flex;
        align-items: center;
        gap: 20px;
    }
    
    .notification-bell {
        position: relative;
        cursor: pointer;
    }
    .bell-icon {
        color: var(--text-muted, #aaa);
        width: 24px;
        height: 24px;
        transition: color 0.2s;
        display: block;
    }
    .bell-icon:hover {
        color: var(--text-light, #e0e0e0);
    }
    .notification-badge {
        position: absolute;
        top: -2px;
        right: -4px;
        background-color: var(--accent-red, #f87171);
        width: 10px;
        height: 10px;
        border-radius: 50%;
        border: 2px solid var(--dark-bg-1, #121212);
    }
    .notification-dropdown {
        display: none;
        position: absolute;
        top: 55px;
        right: 0;
        width: 400px;
        background-color: var(--dark-bg-2, #1e1e1e);
        border-radius: 12px;
        border: 1px solid var(--dark-border, #333);
        box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        z-index: 1000;
    }
    .notification-dropdown.show {
        display: block;
    }
    .notification-dropdown-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 20px;
        border-bottom: 1px solid var(--dark-border, #333);
    }
    .notification-dropdown-header h3 {
        margin: 0;
        color: var(--text-light, #e0e0e0);
        font-size: 1.1rem;
    }
    .notification-close-btn {
        background: none;
        border: none;
        color: var(--text-muted, #aaa);
        font-size: 1.5rem;
        cursor: pointer;
        padding: 0;
        line-height: 1;
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .notification-close-btn:hover {
        color: var(--text-light, #e0e0e0);
    }
    .mark-as-read-btn {
        font-size: 0.85rem;
        color: var(--text-muted, #aaa);
        cursor: pointer;
        text-decoration: none;
        transition: color 0.2s;
    }
    .mark-as-read-btn:hover {
        color: var(--text-light, #e0e0e0);
    }
    .notification-list {
        list-style: none;
        padding: 0;
        margin: 0;
        max-height: 350px;
        overflow-y: auto;
    }
    .notification-item {
        display: flex;
        padding: 16px 20px;
        gap: 15px;
        border-bottom: 1px solid var(--dark-border, #333);
        transition: background-color 0.2s;
        text-decoration: none;
        color: inherit;
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
        color: var(--text-light, #e0e0e0);
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
        color: var(--text-light, #e0e0e0);
        line-height: 1.4;
    }
    .notification-timestamp {
        font-size: 0.85rem;
        color: var(--text-muted, #aaa);
        margin-top: 4px;
    }
    .notification-unread-dot {
        width: 8px;
        height: 8px;
        background-color: var(--accent-red, #f87171);
        border-radius: 50%;
        flex-shrink: 0;
        margin-top: 6px;
    }
    .notification-empty-state {
        padding: 40px 20px;
        text-align: center;
        color: var(--text-muted, #aaa);
    }
    .notification-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 20px;
        border-top: 1px solid var(--dark-border, #333);
    }
    .notification-footer a {
        text-decoration: none;
    }
    .btn-view-all {
        background-color: var(--accent-purple, #a78bfa);
        color: white;
        padding: 8px 16px;
        border-radius: 6px;
        font-size: 0.85rem;
        text-decoration: none;
        transition: opacity 0.2s;
    }
    .btn-view-all:hover {
        opacity: 0.9;
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
</style>

<script>
// This script runs when the DOM is loaded
document.addEventListener("DOMContentLoaded", () => {
    const bellIcon = document.querySelector('.notification-bell');
    const dropdown = document.getElementById('notificationDropdown');
    
    if (bellIcon && dropdown) {
        // Toggle dropdown when bell is clicked
        bellIcon.addEventListener('click', function(event) {
            event.stopPropagation();
            dropdown.classList.toggle('show');
        });
    }

    // Close dropdown when clicking outside
    window.addEventListener('click', function(e) {   
        if (dropdown && dropdown.classList.contains('show') && !bellIcon.contains(e.target)) {
            dropdown.classList.remove('show');
        }
    });
});

// Close button function
function closeNotificationDropdown(event) {
    event.preventDefault();
    event.stopPropagation();
    const dropdown = document.getElementById('notificationDropdown');
    if (dropdown) {
        dropdown.classList.remove('show');
    }
}

// Mark all as read function
function markAllAsRead(event, skipPrevent = false) {
    if (!skipPrevent) {
        event.preventDefault();
        event.stopPropagation();
    }

    const badge = document.getElementById('notificationBadge');
    const list = document.getElementById('notificationList');

    // Determine correct path based on current location
    let ajaxPath = 'ajax-mark-all-read.php';
    const currentPath = window.location.pathname;
    
    if (currentPath.includes('/admin/')) {
        ajaxPath = '../ajax-mark-all-read.php';
    } else if (currentPath.includes('/student/')) {
        ajaxPath = '../ajax-mark-all-read.php';
    } else if (currentPath.includes('/faculty/')) {
        ajaxPath = '../ajax-mark-all-read.php';
    }

    // Send request to mark all as read
    fetch(ajaxPath)
        .then(response => response.text())
        .then(data => {
            if (data.trim() === 'success') {
                // Hide the badge
                if (badge) {
                    badge.style.display = 'none';
                }
                
                // Remove all unread dots
                list.querySelectorAll('.notification-unread-dot').forEach(dot => {
                    dot.style.display = 'none';
                });
                
                // Update button text
                const readBtn = document.querySelector('.mark-as-read-btn');
                if (readBtn) {
                    readBtn.innerText = 'All read';
                }
            }
        })
        .catch(error => console.error('Error marking notifications as read:', error));
}

// Handle notification click - mark as read and navigate
function handleNotificationClick(event, notificationId, link) {
    // Don't prevent default if link is just '#'
    if (link === '#' || !link) {
        event.preventDefault();
        return;
    }
    
    // Mark this specific notification as read
    const currentPath = window.location.pathname;
    let ajaxPath = 'ajax-mark-notification-read.php';
    
    if (currentPath.includes('/admin/')) {
        ajaxPath = '../ajax-mark-notification-read.php';
    } else if (currentPath.includes('/student/')) {
        ajaxPath = '../ajax-mark-notification-read.php';
    } else if (currentPath.includes('/faculty/')) {
        ajaxPath = '../ajax-mark-notification-read.php';
    }
    
    // Send async request to mark as read (don't wait for response)
    fetch(ajaxPath, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'notification_id=' + notificationId
    }).catch(error => console.error('Error marking notification as read:', error));
    
    // Let the link navigate normally
    // The page will reload and notification will appear as read
}
</script>