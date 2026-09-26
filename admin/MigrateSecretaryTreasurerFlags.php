<?php
require_once __DIR__ . '/../config/forms.php';
requireRole('admin');

// Reworks Secretary/Treasurer from separate top-level roles into two flags on a Committee Admin
// instead: a Committee Admin can now also be checked as Secretary and/or Treasurer in the
// Assigned Committees picker, which gives them full (unscoped) committee access + full Reports
// on top of whatever specific committees they're assigned to — while still never getting Content
// Management, User Management, or Configuration. This only needs two new columns; no user data
// is migrated automatically since the earlier 'secretary'/'treasurer' role values were never used
// in production (nobody had committed those files yet).
// Safe to run more than once. Delete this file once you've confirmed it applied.
$results = [];

foreach (['is_secretary', 'is_treasurer'] as $col) {
    $exists = $conn->query("SHOW COLUMNS FROM users LIKE '$col'")->fetch_assoc();
    if ($exists) {
        $results[] = "users.$col already exists.";
    } else {
        if ($conn->query("ALTER TABLE users ADD COLUMN $col TINYINT(1) NOT NULL DEFAULT 0")) {
            $results[] = "Success — added users.$col.";
        } else {
            $results[] = "Failed to add users.$col: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html>

<head>
    <title>Migrate Secretary/Treasurer Flags</title>
</head>

<body style="font-family: sans-serif; padding: 24px; max-width: 640px; margin: 0 auto;">
    <h2>Migrate Secretary/Treasurer Flags</h2>
    <ul>
        <?php foreach ($results as $r): ?>
            <li><?php echo htmlspecialchars($r); ?></li>
        <?php endforeach; ?>
    </ul>
    <p><a href="AdminUserManagement.php">Back to User Management</a></p>
    <p style="color:#888; font-size:12px;">This page is safe to reload. Delete admin/MigrateSecretaryTreasurerFlags.php once confirmed.</p>
</body>

</html>