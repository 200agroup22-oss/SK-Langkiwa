<?php
require_once __DIR__ . '/../config/forms.php';
requireRole('admin');

// One-time schema fix: widens programs.assistance_type from enum('cash','in_kind') to
// enum('cash','in_kind','both') so a program can be flagged as offering both types at once.
// Safe to run more than once. Delete this file once you've confirmed it applied.
$results = [];

$col = $conn->query("SHOW COLUMNS FROM programs LIKE 'assistance_type'")->fetch_assoc();
if ($col && stripos($col['Type'], "'both'") !== false) {
    $results[] = "Already applied — assistance_type is: " . $col['Type'];
} else {
    if ($conn->query("ALTER TABLE programs MODIFY COLUMN assistance_type ENUM('cash','in_kind','both') NOT NULL DEFAULT 'cash'")) {
        $results[] = "Success — assistance_type now allows 'both'.";
    } else {
        $results[] = "Failed: " . $conn->error;
    }
}
?>
<!DOCTYPE html>
<html>

<head>
    <title>Migrate Assistance Type</title>
</head>

<body style="font-family: sans-serif; padding: 24px; max-width: 640px; margin: 0 auto;">
    <h2>Migrate Assistance Type Column</h2>
    <ul>
        <?php foreach ($results as $r): ?>
            <li><?php echo htmlspecialchars($r); ?></li>
        <?php endforeach; ?>
    </ul>
    <p><a href="AdminProgramManagement.php">Back to Content Management</a></p>
    <p style="color:#888; font-size:12px;">This page is safe to reload. Delete admin/MigrateAssistanceTypeBoth.php once confirmed.</p>
</body>

</html>