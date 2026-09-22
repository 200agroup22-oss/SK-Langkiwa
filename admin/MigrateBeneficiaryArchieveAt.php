<?php
require_once __DIR__ . '/../config/forms.php';
requireRole('admin');

// One-time schema fix: assistance_beneficiaries had no archived_at column, so the "Delete" button
// on every Cash/In-Kind Assistance page permanently deleted the row instead of archiving it like
// every other list in this app. Adds the column so Archive/Restore works the normal way.
// Safe to run more than once. Delete this file once you've confirmed it applied.
$results = [];

$col = $conn->query("SHOW COLUMNS FROM assistance_beneficiaries LIKE 'archived_at'")->fetch_assoc();
if ($col) {
    $results[] = "archived_at column already exists.";
} else {
    if ($conn->query("ALTER TABLE assistance_beneficiaries ADD COLUMN archived_at DATETIME NULL")) {
        $results[] = "Success — added assistance_beneficiaries.archived_at.";
    } else {
        $results[] = "Failed to add archived_at column: " . $conn->error;
    }
}
?>
<!DOCTYPE html>
<html>

<head>
    <title>Migrate Beneficiary Archived At</title>
</head>

<body style="font-family: sans-serif; padding: 24px; max-width: 640px; margin: 0 auto;">
    <h2>Migrate assistance_beneficiaries.archived_at</h2>
    <ul>
        <?php foreach ($results as $r): ?>
            <li><?php echo htmlspecialchars($r); ?></li>
        <?php endforeach; ?>
    </ul>
    <p><a href="AdminProgramManagement.php">Back to Content Management</a></p>
    <p style="color:#888; font-size:12px;">This page is safe to reload. Delete admin/MigrateBeneficiaryArchivedAt.php once confirmed.</p>
</body>

</html>