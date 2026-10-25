<?php
// Beneficiaries of a program that has Activities switched on: every approved applicant, with how many
// of the program's activities they have completed. Only those who completed them are "eligible" and
// appear on the program's Cash / In-Kind Assistance pages (see config/program_activities.php).
require_once __DIR__ . '/../config/forms.php';
requireRole(['admin', 'committee_admin']);

ensureProgramActivitySchema();

$tabId = (int)($_GET['ptab'] ?? $_GET['tab'] ?? 0);
$tab = $tabId ? getProgramTabById($tabId) : null;
if (!$tab || empty($tab['has_activities'])) {
    header('Location: ' . dashboardUrlForRole(currentUser()['role']));
    exit();
}
$committeeId = (int)$tab['committee_id'];
requireCommitteeAccess($committeeId);

$programId = !empty($tab['program_id']) ? (int)$tab['program_id'] : null;
$track = 'assistance';
$fields = getFormFields($committeeId, $track, $programId);

if ($programId !== null) {
    $stmt = $conn->prepare("SELECT application_id FROM applications WHERE committee_id = ? AND program_id = ? AND status = 'approved' AND archived_at IS NULL ORDER BY application_id ASC");
    $stmt->bind_param('ii', $committeeId, $programId);
} else {
    $stmt = $conn->prepare("SELECT application_id FROM applications WHERE committee_id = ? AND program_track = ? AND program_id IS NULL AND status = 'approved' AND archived_at IS NULL ORDER BY application_id ASC");
    $stmt->bind_param('is', $committeeId, $track);
}
$stmt->execute();
$appIds = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'application_id');
$stmt->close();

$rows = [];
foreach ($appIds as $appId) {
    $answers = getApplicationAnswers($appId);
    $app = ['answers' => $answers];
    $compliance = programActivityCompliance((int)$appId) ?? ['required' => 0, 'completed' => 0, 'ok' => true];
    $rows[] = [
        'application_id' => (int)$appId,
        'full_name' => trim(answerByKey($app, $fields, 'last_name') . ' ' . answerByKey($app, $fields, 'first_name')),
        'address' => answerByKey($app, $fields, 'complete_address'),
        'required' => $compliance['required'],
        'completed' => $compliance['completed'],
        'eligible' => $compliance['ok'],
    ];
}

$eligibleCount = count(array_filter($rows, fn($r) => $r['eligible']));
$statusFilter = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');
$displayRows = $rows;
if ($statusFilter === 'eligible') {
    $displayRows = array_values(array_filter($displayRows, fn($r) => $r['eligible']));
} elseif ($statusFilter === 'not_eligible') {
    $displayRows = array_values(array_filter($displayRows, fn($r) => !$r['eligible']));
}
if ($search !== '') {
    $needle = mb_strtolower($search);
    $displayRows = array_values(array_filter($displayRows, fn($r) => str_contains(mb_strtolower($r['full_name']), $needle) || str_contains(mb_strtolower($r['address']), $needle)));
}

$perPage = 10;
$totalRows = count($displayRows);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
$pagedRows = array_slice($displayRows, ($page - 1) * $perPage, $perPage);

$activeLink = programBeneficiariesActiveName($committeeId);
$programName = $tab['label'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($programName); ?> Beneficiaries</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo APP_BASE; ?>/assets/css/admin.css" rel="stylesheet">
</head>

<body>

    <?php include __DIR__ . '/../includes/adminsidebar.php'; ?>

    <div class="main-content">
        <h4 class="fw-bold mb-1"><?php echo e($programName); ?> Beneficiaries</h4>
        <p class="text-muted mb-2" style="font-size: 13px;">
            Approved applicants of this program and their progress on its activities. Only beneficiaries who completed all activities are eligible and listed under Assistance Requests.
        </p>

        <form method="get">
            <input type="hidden" name="<?php echo $programId !== null ? 'ptab' : 'tab'; ?>" value="<?php echo (int)$tabId; ?>">
            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <span class="badge text-bg-secondary"><?php echo count($rows); ?> beneficiaries</span>
                    <span class="badge text-bg-success"><?php echo $eligibleCount; ?> eligible</span>
                    <span class="badge text-bg-warning"><?php echo count($rows) - $eligibleCount; ?> not yet eligible</span>
                </div>
                <div class="search-box">
                    <input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Search...">
                    <i class="bi bi-search"></i>
                </div>
            </div>
            <div class="filter-bar d-flex gap-2 align-items-center mb-3 flex-wrap">
                <span style="font-size:12px; color:#666; font-weight:600;"><i class="bi bi-funnel me-1"></i>Filter:</span>
                <select class="filter-select" name="status" onchange="this.form.submit()">
                    <option value="">All Beneficiaries</option>
                    <option value="eligible" <?php echo $statusFilter === 'eligible' ? 'selected' : ''; ?>>Eligible</option>
                    <option value="not_eligible" <?php echo $statusFilter === 'not_eligible' ? 'selected' : ''; ?>>Not yet eligible</option>
                </select>
            </div>
        </form>

        <div class="table-card">
            <div class="table-responsive-wrap">
                <table class="table table-stack mb-0">
                    <thead>
                        <tr>
                            <th>Applicant ID</th>
                            <th>Full Name</th>
                            <th>Address</th>
                            <th>Activities Completed</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pagedRows)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">No beneficiaries yet. Applicants appear here once their application is approved.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($pagedRows as $r): ?>
                            <tr>
                                <td data-label="Applicant ID"><?php echo str_pad($r['application_id'], 3, '0', STR_PAD_LEFT); ?></td>
                                <td data-label="Full Name"><?php echo e($r['full_name']); ?></td>
                                <td data-label="Address"><?php echo e($r['address']); ?></td>
                                <td data-label="Activities Completed">
                                    <span class="fw-semibold <?php echo $r['eligible'] ? 'text-success' : ''; ?>"><?php echo (int)$r['completed']; ?></span> / <?php echo (int)$r['required']; ?>
                                </td>
                                <td data-label="Status">
                                    <?php if ($r['eligible']): ?>
                                        <span class="badge text-bg-success">Eligible</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-warning">Not yet eligible</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php renderPagination($page, $totalPages, $totalRows, $perPage); ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>