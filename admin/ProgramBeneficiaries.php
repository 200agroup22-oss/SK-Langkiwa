<?php
// Beneficiaries of a program that has Activities switched on: every approved applicant, with how many
// of the program's activities they have completed. Only eligible ones (all activities done, or marked
// Eligible by hand) appear on the program's Cash / In-Kind Assistance pages — same idea as the
// Scholars page. See config/program_activities.php.
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
$me = currentUser();

$programId = !empty($tab['program_id']) ? (int)$tab['program_id'] : null;
$track = 'assistance';
$fields = getFormFields($committeeId, $track, $programId);
$selfUrl = 'ProgramBeneficiaries.php?' . ($programId !== null ? 'ptab=' : 'tab=') . $tabId;

if ($programId !== null) {
    $stmt = $conn->prepare("SELECT application_id FROM applications WHERE committee_id = ? AND program_id = ? AND status = 'approved' AND archived_at IS NULL ORDER BY application_id ASC");
    $stmt->bind_param('ii', $committeeId, $programId);
} else {
    $stmt = $conn->prepare("SELECT application_id FROM applications WHERE committee_id = ? AND program_track = ? AND program_id IS NULL AND status = 'approved' AND archived_at IS NULL ORDER BY application_id ASC");
    $stmt->bind_param('is', $committeeId, $track);
}
$stmt->execute();
$appIds = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'application_id'));
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $applicationId = (int)($_POST['application_id'] ?? 0);

    if (isViewOnlyOfficer()) {
        setFlash('error', 'Your role has view-only access on this page.');
    } elseif (!in_array($applicationId, $appIds, true)) {
        setFlash('error', 'That beneficiary does not belong to this program.');
    } elseif (isset($_POST['set_eligibility'])) {
        $eligibility = ($_POST['eligibility'] ?? '') === 'eligible' ? 'eligible' : 'not_eligible';
        $stmt = $conn->prepare("INSERT INTO program_eligibility_overrides (application_id, eligibility, set_by) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE eligibility = VALUES(eligibility), set_by = VALUES(set_by), set_at = NOW()");
        $stmt->bind_param('isi', $applicationId, $eligibility, $me['user_id']);
        $stmt->execute();
        $stmt->close();
        logAudit($eligibility === 'eligible' ? 'Marked Beneficiary Eligible' : 'Marked Beneficiary Not Eligible', $tab['label'] . ' - application #' . $applicationId);
        setFlash('success', 'Beneficiary marked ' . ($eligibility === 'eligible' ? 'eligible' : 'not eligible') . '.');
    } elseif (isset($_POST['clear_eligibility'])) {
        $stmt = $conn->prepare("DELETE FROM program_eligibility_overrides WHERE application_id = ?");
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $stmt->close();
        logAudit('Reset Beneficiary Eligibility', $tab['label'] . ' - application #' . $applicationId);
        setFlash('success', 'Eligibility now follows activity attendance.');
    }

    header('Location: ' . $selfUrl);
    exit();
}

$pageError = getFlash('error');
$pageSuccess = getFlash('success');

$rows = [];
foreach ($appIds as $appId) {
    $answers = getApplicationAnswers($appId);
    $app = ['answers' => $answers];
    $compliance = programActivityCompliance($appId) ?? ['required' => 0, 'completed' => 0, 'ok' => true, 'manual' => null];
    $rows[] = [
        'application_id' => $appId,
        'full_name' => trim(answerByKey($app, $fields, 'last_name') . ' ' . answerByKey($app, $fields, 'first_name')),
        'address' => answerByKey($app, $fields, 'complete_address'),
        'files' => getApplicationFiles($appId),
        'required' => $compliance['required'],
        'completed' => $compliance['completed'],
        'eligible' => $compliance['ok'],
        'manual' => $compliance['manual'] ?? null,
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
    <style>
        .badge-eligible {
            background-color: #d1e7dd;
            color: #0a3622;
            font-size: 12px;
            font-weight: 600;
            border-radius: 20px;
            padding: 4px 12px;
            border: 1px solid #a3cfbb;
        }

        .badge-not-eligible {
            background-color: #fff3cd;
            color: #856404;
            font-size: 12px;
            font-weight: 600;
            border-radius: 20px;
            padding: 4px 12px;
            border: 1px solid #ffe69c;
        }
    </style>
</head>

<body>

    <?php include __DIR__ . '/../includes/adminsidebar.php'; ?>

    <div class="main-content">
        <h4 class="fw-bold mb-1"><?php echo e($programName); ?> Beneficiaries</h4>
        <p class="text-muted mb-2" style="font-size: 13px;">
            Approved applicants of this program and their progress on its activities. Only eligible beneficiaries are listed under Assistance Requests.
        </p>

        <?php if ($pageSuccess): ?><div class="alert alert-success py-2"><?php echo e($pageSuccess); ?></div><?php endif; ?>
        <?php if ($pageError): ?><div class="alert alert-danger py-2"><?php echo e($pageError); ?></div><?php endif; ?>

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
                            <th>Activities</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pagedRows)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No beneficiaries yet. Applicants appear here once their application is approved.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($pagedRows as $r): ?>
                            <tr>
                                <td data-label="Applicant ID"><?php echo str_pad($r['application_id'], 3, '0', STR_PAD_LEFT); ?></td>
                                <td data-label="Full Name"><?php echo e($r['full_name']); ?></td>
                                <td data-label="Address"><?php echo e($r['address']); ?></td>
                                <td data-label="Activities"><span class="fw-semibold"><?php echo (int)$r['completed']; ?> / <?php echo (int)$r['required']; ?></span></td>
                                <td data-label="Status">
                                    <span class="<?php echo $r['eligible'] ? 'badge-eligible' : 'badge-not-eligible'; ?>"><?php echo $r['eligible'] ? 'Eligible' : 'Not yet eligible'; ?></span>
                                    <?php if ($r['manual']): ?><span class="text-muted" style="font-size:11px;" title="Set by hand, not by attendance">(manual)</span><?php endif; ?>
                                </td>
                                <td data-label="Action">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle py-1 px-2" style="font-size:12px;" data-bs-toggle="dropdown">Actions</button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3" style="font-size:13px; min-width:170px;">
                                            <li><a class="dropdown-item d-flex align-items-center gap-2 py-2" href="#" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $r['application_id']; ?>"><i class="bi bi-eye text-primary"></i> View</a></li>
                                            <?php if (!isViewOnlyOfficer()): ?>
                                                <li>
                                                    <hr class="dropdown-divider my-1">
                                                </li>
                                                <li>
                                                    <form method="post" class="px-3 py-1">
                                                        <input type="hidden" name="application_id" value="<?php echo $r['application_id']; ?>">
                                                        <input type="hidden" name="eligibility" value="eligible">
                                                        <button type="submit" name="set_eligibility" class="dropdown-item d-flex align-items-center gap-2 py-1 px-0 border-0 bg-transparent"><i class="bi bi-check-lg text-success"></i> Eligible</button>
                                                    </form>
                                                </li>
                                                <li>
                                                    <form method="post" class="px-3 py-1">
                                                        <input type="hidden" name="application_id" value="<?php echo $r['application_id']; ?>">
                                                        <input type="hidden" name="eligibility" value="not_eligible">
                                                        <button type="submit" name="set_eligibility" class="dropdown-item d-flex align-items-center gap-2 py-1 px-0 border-0 bg-transparent"><i class="bi bi-x-lg text-danger"></i> Not Eligible</button>
                                                    </form>
                                                </li>
                                                <?php if ($r['manual']): ?>
                                                    <li>
                                                        <form method="post" class="px-3 py-1">
                                                            <input type="hidden" name="application_id" value="<?php echo $r['application_id']; ?>">
                                                            <button type="submit" name="clear_eligibility" class="dropdown-item d-flex align-items-center gap-2 py-1 px-0 border-0 bg-transparent"><i class="bi bi-arrow-counterclockwise text-secondary"></i> Follow attendance</button>
                                                        </form>
                                                    </li>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php renderPagination($page, $totalPages, $totalRows, $perPage); ?>
    </div>

    <?php foreach ($pagedRows as $r): ?>
        <!-- VIEW MODAL -->
        <div class="modal fade" id="viewModal<?php echo $r['application_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold"><i class="bi bi-file-earmark-text-fill me-2"></i>Beneficiary Details</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="section-divider"><i class="bi bi-person-fill me-1"></i> Personal Information</div>
                        <div class="row g-3 mb-2">
                            <div class="col-md-4">
                                <div class="info-label">Applicant ID</div>
                                <div class="info-value">#<?php echo str_pad($r['application_id'], 3, '0', STR_PAD_LEFT); ?></div>
                            </div>
                            <div class="col-md-8">
                                <div class="info-label">Full Name</div>
                                <div class="info-value"><?php echo e($r['full_name']); ?></div>
                            </div>
                            <div class="col-12">
                                <div class="info-label">Address</div>
                                <div class="info-value"><?php echo e($r['address']); ?></div>
                            </div>
                        </div>
                        <div class="section-divider"><i class="bi bi-calendar-event-fill me-1"></i> Activities</div>
                        <div class="row g-3 mb-2">
                            <div class="col-md-4">
                                <div class="info-label">Completed</div>
                                <div class="info-value"><?php echo (int)$r['completed']; ?> / <?php echo (int)$r['required']; ?></div>
                            </div>
                            <div class="col-md-8">
                                <div class="info-label">Status</div>
                                <div class="info-value"><?php echo $r['eligible'] ? 'Eligible' : 'Not yet eligible'; ?><?php echo $r['manual'] ? ' (set by hand)' : ''; ?></div>
                            </div>
                        </div>
                        <div class="section-divider"><i class="bi bi-paperclip me-1"></i> Submitted Documents</div>
                        <div class="row g-2">
                            <?php foreach ($fields as $field):
                                if ($field['input_type'] !== 'file') continue;
                                $file = $r['files'][$field['field_id']] ?? null;
                            ?>
                                <div class="col-md-6">
                                    <div class="info-label mb-1"><?php echo e($field['label']); ?></div>
                                    <?php if ($file): ?>
                                        <a class="doc-item" href="<?php echo APP_BASE . '/' . e($file['path']); ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-image-fill"></i><?php echo e($file['original_name']); ?></a>
                                    <?php else: ?>
                                        <div class="doc-item"><i class="bi bi-file-earmark-image-fill"></i>Not submitted</div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Row "Actions" menus sit inside a scrolling table wrapper; fixed positioning stops the
        // wrapper from clipping them near the bottom (same fix as the Scholars page).
        document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function(toggle) {
            new bootstrap.Dropdown(toggle, {
                popperConfig: function(defaultConfig) {
                    return Object.assign({}, defaultConfig, {
                        strategy: 'fixed'
                    });
                }
            });
        });
    </script>
</body>

</html>