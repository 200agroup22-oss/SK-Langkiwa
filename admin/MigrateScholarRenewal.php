<?php
require_once __DIR__ . '/../config/forms.php';
requireRole('admin');

// End Semester marks every active scholar 'pending' (awaiting renewal) and records the term they
// just finished. Older databases only allow scholars.status = 'active'/'archived' and have no
// finished_academic_year / finished_semester columns, which makes End Semester fail with
// "Data truncated for column 'status'". This adds what's missing.
// Safe to run more than once. Delete this file once you've confirmed it applied.
$results = [];

$col = $conn->query("SHOW COLUMNS FROM scholars LIKE 'status'")->fetch_assoc();
if ($col && stripos($col['Type'], "'pending'") !== false) {
    $results[] = "scholars.status already allows 'pending'.";
} else {
    if ($conn->query("ALTER TABLE scholars MODIFY COLUMN status ENUM('active','pending','archived') NOT NULL DEFAULT 'active'")) {
        $results[] = "Success — scholars.status now allows 'pending'.";
    } else {
        $results[] = "Failed to update scholars.status: " . $conn->error;
    }
}

foreach (['finished_academic_year' => 'VARCHAR(20) NULL', 'finished_semester' => 'VARCHAR(20) NULL'] as $name => $def) {
    $exists = $conn->query("SHOW COLUMNS FROM scholars LIKE '$name'")->fetch_assoc();
    if ($exists) {
        $results[] = "scholars.$name already exists.";
    } elseif ($conn->query("ALTER TABLE scholars ADD COLUMN $name $def")) {
        $results[] = "Success — added scholars.$name.";
    } else {
        $results[] = "Failed to add scholars.$name: " . $conn->error;
    }
}
?>
<!DOCTYPE html>
<html>

<head>
    <title>Migrate Scholar Renewal</title>
</head>

<body style="font-family: sans-serif; padding: 24px; max-width: 640px; margin: 0 auto;">
    <h2>Migrate Scholar Renewal</h2>
    <ul>
        <?php foreach ($results as $r): ?>
            <li><?php echo htmlspecialchars($r); ?></li>
        <?php endforeach; ?>
    </ul>
    <p><a href="Education/EducationScholarList.php">Back to Scholars</a></p>
    <p style="color:#888; font-size:12px;">This page is safe to reload. Delete admin/MigrateScholarRenewal.php once confirmed.</p>
</body>

</html>