<?php
require_once __DIR__ . '/../config/forms.php';
requireRole('admin');

// One-time schema fix for the new Secretary/Treasurer roles: widens users.role to add them
// alongside the existing applicant/scholar/admin/committee_admin. Neither role needs any new
// table — unlike committee_admin they aren't scoped to specific committees (they see every
// committee's Applicants/Scholars/Assistance pages and full Reports, but never Content
// Management, User Management, or Configuration).
// Safe to run more than once. Delete this file once you've confirmed it applied.
$results = [];

$col = $conn->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch_assoc();
if ($col && stripos($col['Type'], "'secretary'") !== false && stripos($col['Type'], "'treasurer'") !== false) {
    $results[] = "users.role already allows 'secretary' and 'treasurer'.";
} else {
    if ($conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('applicant','scholar','admin','committee_admin','secretary','treasurer') NOT NULL DEFAULT 'applicant'")) {
        $results[] = "Success — users.role now allows 'secretary' and 'treasurer'.";
    } else {
        $results[] = "Failed to widen users.role: " . $conn->error;
    }
}
?>
<!DOCTYPE html>
<html>

<head>
    <title>Migrate Secretary/Treasurer Roles</title>
</head>

<body style="font-family: sans-serif; padding: 24px; max-width: 640px; margin: 0 auto;">
    <h2>Migrate Secretary/Treasurer Roles</h2>
    <ul>
        <?php foreach ($results as $r): ?>
            <li><?php echo htmlspecialchars($r); ?></li>
        <?php endforeach; ?>
    </ul>
    <p><a href="AdminUserManagement.php">Back to User Management</a></p>
    <p style="color:#888; font-size:12px;">This page is safe to reload. Delete admin/MigrateSecretaryTreasurerRoles.php once confirmed.</p>
</body>

</html>