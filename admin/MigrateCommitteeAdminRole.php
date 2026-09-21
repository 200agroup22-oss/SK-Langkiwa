<?php
require_once __DIR__ . '/../config/forms.php';
requireRole('admin');

// One-time schema fix for the new Committee Admin role:
// 1. Widens users.role to add 'committee_admin' alongside the existing applicant/scholar/admin.
// 2. Creates admin_committee_assignments (user_id, committee_id) — a committee_admin can be
//    assigned to one or more committees; unused for every other role.
// Safe to run more than once. Delete this file once you've confirmed it applied.
$results = [];

$col = $conn->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch_assoc();
if ($col && stripos($col['Type'], "'committee_admin'") !== false) {
    $results[] = "users.role already allows 'committee_admin'.";
} else {
    if ($conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('applicant','scholar','admin','committee_admin') NOT NULL DEFAULT 'applicant'")) {
        $results[] = "Success — users.role now allows 'committee_admin'.";
    } else {
        $results[] = "Failed to widen users.role: " . $conn->error;
    }
}

$tableExists = $conn->query("SHOW TABLES LIKE 'admin_committee_assignments'")->fetch_assoc();
if ($tableExists) {
    $results[] = "admin_committee_assignments table already exists.";
} else {
    $sql = "CREATE TABLE admin_committee_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        committee_id INT NOT NULL,
        UNIQUE KEY uniq_user_committee (user_id, committee_id),
        FOREIGN KEY (user_id) REFERENCES users(user_id),
        FOREIGN KEY (committee_id) REFERENCES committees(committee_id)
    ) ENGINE=InnoDB";
    if ($conn->query($sql)) {
        $results[] = "Created admin_committee_assignments table.";
    } else {
        $results[] = "Failed to create admin_committee_assignments: " . $conn->error;
    }
}
?>
<!DOCTYPE html>
<html>

<head>
    <title>Migrate Committee Admin Role</title>
</head>

<body style="font-family: sans-serif; padding: 24px; max-width: 640px; margin: 0 auto;">
    <h2>Migrate Committee Admin Role</h2>
    <ul>
        <?php foreach ($results as $r): ?>
            <li><?php echo htmlspecialchars($r); ?></li>
        <?php endforeach; ?>
    </ul>
    <p><a href="AdminUserManagement.php">Back to User Management</a></p>
    <p style="color:#888; font-size:12px;">This page is safe to reload. Delete admin/MigrateCommitteeAdminRole.php once confirmed.</p>
</body>

</html>