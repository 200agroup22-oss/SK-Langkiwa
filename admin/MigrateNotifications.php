<?php
require_once __DIR__ . '/../config/forms.php';
requireRole('admin');

// One-time schema addition: personal in-app notifications (e.g. "your application was approved")
// plus a per-user way to archive/dismiss a broadcast announcement without hiding it for everyone
// else who was sent it. Safe to run more than once. Delete this file once you've confirmed it applied.
$results = [];

$table = $conn->query("SHOW TABLES LIKE 'notifications'")->fetch_assoc();
if ($table) {
    $results[] = "notifications table already exists.";
} else {
    $sql = "CREATE TABLE notifications (
        notification_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(200) NOT NULL,
        message TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        archived_at DATETIME NULL,
        FOREIGN KEY (user_id) REFERENCES users(user_id)
    ) ENGINE=InnoDB";
    if ($conn->query($sql)) {
        $results[] = "Success — created notifications table.";
    } else {
        $results[] = "Failed to create notifications table: " . $conn->error;
    }
}

$table2 = $conn->query("SHOW TABLES LIKE 'announcement_archives'")->fetch_assoc();
if ($table2) {
    $results[] = "announcement_archives table already exists.";
} else {
    $sql2 = "CREATE TABLE announcement_archives (
        user_id INT NOT NULL,
        announcement_id INT NOT NULL,
        archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, announcement_id),
        FOREIGN KEY (user_id) REFERENCES users(user_id),
        FOREIGN KEY (announcement_id) REFERENCES announcements(announcement_id)
    ) ENGINE=InnoDB";
    if ($conn->query($sql2)) {
        $results[] = "Success — created announcement_archives table.";
    } else {
        $results[] = "Failed to create announcement_archives table: " . $conn->error;
    }
}
?>
<!DOCTYPE html>
<html>

<head>
    <title>Migrate Notifications</title>
</head>

<body style="font-family: sans-serif; padding: 24px; max-width: 640px; margin: 0 auto;">
    <h2>Migrate Notifications / Announcement Archives</h2>
    <ul>
        <?php foreach ($results as $r): ?>
            <li><?php echo htmlspecialchars($r); ?></li>
        <?php endforeach; ?>
    </ul>
    <p><a href="AdminDashboard.php">Back to Dashboard</a></p>
    <p style="color:#888; font-size:12px;">This page is safe to reload. Delete admin/MigrateNotifications.php once confirmed.</p>
</body>

</html>