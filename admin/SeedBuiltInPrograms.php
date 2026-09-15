<?php
require_once __DIR__ . '/../config/forms.php';
requireRole('admin');

// One-time data fix: recreates the placeholder catalog programs that back each built-in
// track (iSKolar ng Langkiwa / Assistance Program) for the 4 built-in committees. These
// existed in local dev but were never carried over to this database. Safe to run more than
// once — it skips any program that already exists by name within its committee. Delete this
// file once you've confirmed the programs appear in Content Management.
$seedPrograms = [
    [
        'code' => 'education',
        'name' => 'iSKolar ng Langkiwa Scholarship',
        'description' => 'Scholarship program for qualified students of Barangay Langkiwa. Edit this entry to set the real allowance amount, application window, and eligibility requirements.',
        'assistance_type' => 'cash',
        'app_start_date' => null,
        'app_end_date' => null,
    ],
    [
        'code' => 'education',
        'name' => 'Education Assistance Program',
        'description' => 'Cash or in-kind educational assistance for Education committee applicants. Edit this entry to set the real amount/items, application window, and eligibility requirements.',
        'assistance_type' => 'cash',
        'app_start_date' => '2026-09-12',
        'app_end_date' => '2026-10-10',
    ],
    [
        'code' => 'health',
        'name' => 'Health Assistance Program',
        'description' => 'Cash or in-kind medical/health assistance for Health committee applicants. Edit this entry to set the real amount/items, application window, and eligibility requirements.',
        'assistance_type' => 'cash',
        'app_start_date' => null,
        'app_end_date' => null,
    ],
    [
        'code' => 'sports',
        'name' => 'Sports Assistance Program',
        'description' => 'Cash or in-kind assistance for Sports committee applicants. Edit this entry to set the real amount/items, application window, and eligibility requirements.',
        'assistance_type' => 'cash',
        'app_start_date' => null,
        'app_end_date' => null,
    ],
    [
        'code' => 'active_citizenship',
        'name' => 'Active Citizenship Assistance Program',
        'description' => 'Cash or in-kind assistance for Active Citizenship committee applicants. Edit this entry to set the real amount/items, application window, and eligibility requirements.',
        'assistance_type' => 'cash',
        'app_start_date' => null,
        'app_end_date' => null,
    ],
];

$results = [];
foreach ($seedPrograms as $seed) {
    $committeeId = getCommitteeIdByCode($seed['code']);
    if (!$committeeId) {
        $results[] = "Skipped \"{$seed['name']}\" — committee \"{$seed['code']}\" not found.";
        continue;
    }

    $stmt = $conn->prepare("SELECT program_id FROM programs WHERE committee_id = ? AND name = ?");
    $stmt->bind_param('is', $committeeId, $seed['name']);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($existing) {
        $results[] = "Already exists: \"{$seed['name']}\" (#{$existing['program_id']})";
        continue;
    }

    $stmt = $conn->prepare("INSERT INTO programs (committee_id, name, description, assistance_type, app_start_date, app_end_date, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
    $stmt->bind_param('isssss', $committeeId, $seed['name'], $seed['description'], $seed['assistance_type'], $seed['app_start_date'], $seed['app_end_date']);
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();

    syncProgramTab($newId, $committeeId, $seed['name'], true);
    $results[] = "Created: \"{$seed['name']}\" (#{$newId})";
}
?>
<!DOCTYPE html>
<html>

<head>
    <title>Seed Built-in Programs</title>
</head>

<body style="font-family: sans-serif; padding: 24px; max-width: 640px; margin: 0 auto;">
    <h2>Seed Built-in Programs</h2>
    <ul>
        <?php foreach ($results as $r): ?>
            <li><?php echo htmlspecialchars($r); ?></li>
        <?php endforeach; ?>
    </ul>
    <p><a href="AdminProgramManagement.php">Back to Content Management</a></p>
    <p style="color:#888; font-size:12px;">This page is safe to reload — it skips programs that already exist. Delete admin/SeedBuiltInPrograms.php once you've confirmed the programs show up.</p>
</body>

</html>