<?php
require_once __DIR__ . '/../../config/forms.php';
requireRole('admin');

$committeeId = getCommitteeIdByCode('health');
$track = 'assistance';
$type = 'in_kind';
$me = currentUser();

// Scope the whole page to one specific program tab when navigated here via a program-specific
// sidebar link, instead of pooling every program under the committee together — lets admins tell
// beneficiaries for different programs apart instead of them all landing in one shared list.
$programId = isset($_GET['program_id']) && $_GET['program_id'] !== '' ? (int)$_GET['program_id'] : null;
$committeePrograms = $conn->prepare("SELECT program_id, name FROM programs WHERE committee_id = ? AND archived_at IS NULL ORDER BY name ASC");
$committeePrograms->bind_param('i', $committeeId);
$committeePrograms->execute();
$committeePrograms = $committeePrograms->get_result()->fetch_all(MYSQLI_ASSOC);
$activeProgram = null;
foreach ($committeePrograms as $cp) {
    if ((int)$cp['program_id'] === $programId) {
        $activeProgram = $cp;
        break;
    }
}

// ---- POST handlers (redirect-after-POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['add_beneficiary'])) {
        $applicationId = (int)($_POST['application_id'] ?? 0);
        $items = trim($_POST['items'] ?? '');
        $quantity = (int)($_POST['quantity'] ?? 0);

        $stmt = $conn->prepare("SELECT a.application_id FROM applications a
            LEFT JOIN assistance_beneficiaries ab ON ab.application_id = a.application_id AND ab.type = ?
            WHERE a.application_id = ? AND a.committee_id = ? AND a.program_track = ? AND a.status = 'approved' AND a.archived_at IS NULL AND ab.beneficiary_id IS NULL");
        $stmt->bind_param('siis', $type, $applicationId, $committeeId, $track);
        $stmt->execute();
        $valid = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($valid && $items !== '' && $quantity > 0) {
            $stmt = $conn->prepare("INSERT INTO assistance_beneficiaries (application_id, type, items, quantity, status) VALUES (?, ?, ?, ?, 'pending')");
            $stmt->bind_param('issi', $applicationId, $type, $items, $quantity);
            $stmt->execute();
            $stmt->close();
            logAudit('Added In-Kind Beneficiary', 'Health application #' . $applicationId);
            setFlash('success', 'Beneficiary added.');
        } else {
            setFlash('error', 'Could not add beneficiary. Choose a valid applicant, item(s), and quantity.');
        }
    }

    if (isset($_POST['edit_beneficiary'])) {
        $beneficiaryId = (int)($_POST['beneficiary_id'] ?? 0);
        $items = trim($_POST['items'] ?? '');
        $quantity = (int)($_POST['quantity'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['pending', 'distributed'], true) ? $_POST['status'] : 'pending';
        $dateDistributed = $status === 'distributed' ? ($_POST['date_distributed'] ?: date('Y-m-d')) : null;

        $stmt = $conn->prepare("UPDATE assistance_beneficiaries SET items = ?, quantity = ?, status = ?, date_distributed = ? WHERE beneficiary_id = ? AND type = 'in_kind'");
        $stmt->bind_param('sissi', $items, $quantity, $status, $dateDistributed, $beneficiaryId);
        $stmt->execute();
        $stmt->close();
        logAudit('Updated In-Kind Beneficiary', 'Beneficiary #' . $beneficiaryId);
        setFlash('success', 'Beneficiary updated.');
    }

    if (isset($_POST['distribute_item'])) {
        $beneficiaryId = (int)($_POST['beneficiary_id'] ?? 0);
        $dateDistributed = $_POST['date_distributed'] ?: date('Y-m-d');

        $stmt = $conn->prepare("UPDATE assistance_beneficiaries SET status = 'distributed', date_distributed = ? WHERE beneficiary_id = ? AND type = 'in_kind'");
        $stmt->bind_param('si', $dateDistributed, $beneficiaryId);
        $stmt->execute();
        $stmt->close();
        logAudit('Distributed Item(s)', 'Beneficiary #' . $beneficiaryId);
        setFlash('success', 'Item(s) marked as distributed.');
    }

    if (isset($_POST['delete_beneficiary'])) {
        $beneficiaryId = (int)($_POST['beneficiary_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM assistance_beneficiaries WHERE beneficiary_id = ? AND type = 'in_kind'");
        $stmt->bind_param('i', $beneficiaryId);
        $stmt->execute();
        $stmt->close();
        logAudit('Deleted In-Kind Beneficiary', 'Beneficiary #' . $beneficiaryId);
        setFlash('success', 'Beneficiary record removed.');
    }

    header("Location: HealthInKindAssistance.php" . ($programId !== null ? '?program_id=' . $programId : ''));
    exit();
}

$pageError = getFlash('error');
$pageSuccess = getFlash('success');

// ---- Load beneficiaries (approved applications LEFT JOINed to this page's beneficiary type) ----
$sql = "SELECT a.application_id, a.user_id, a.program_id, ab.beneficiary_id, ab.items, ab.quantity, ab.status AS b_status, ab.date_distributed
        FROM applications a
        LEFT JOIN assistance_beneficiaries ab ON ab.application_id = a.application_id AND ab.type = 'in_kind'
        WHERE a.committee_id = ? AND a.program_track = ? AND a.status = 'approved' AND a.archived_at IS NULL"
    . ($programId !== null ? " AND a.program_id = ?" : "") . "
        ORDER BY a.application_id ASC";
$stmt = $conn->prepare($sql);
if ($programId !== null) {
    $stmt->bind_param('isi', $committeeId, $track, $programId);
} else {
    $stmt->bind_param('is', $committeeId, $track);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($rows as &$row) {
    $answers = getApplicationAnswers($row['application_id']);
    $app = ['answers' => $answers];
    // Each row's own program has its own independent field set — fetch it per row (not once for
    // the whole pooled list) so a beneficiary's name/address/documents resolve against the fields
    // that were actually on their program's form when they applied.
    $row['fields'] = getFormFields($committeeId, $track, $row['program_id']);
    $row['full_name'] = trim(answerByKey($app, $row['fields'], 'last_name') . ' ' . answerByKey($app, $row['fields'], 'first_name'));
    $row['address'] = answerByKey($app, $row['fields'], 'complete_address');
    $row['files'] = getApplicationFiles($row['application_id']);
    $row['answers'] = $answers;
}
unset($row);

// Applications eligible to become a NEW beneficiary (approved, no beneficiary row of any type yet)
$eligibleStmt = $conn->prepare("SELECT a.application_id FROM applications a
    LEFT JOIN assistance_beneficiaries ab ON ab.application_id = a.application_id AND ab.type = ?
    WHERE a.committee_id = ? AND a.program_track = ? AND a.status = 'approved' AND a.archived_at IS NULL AND ab.beneficiary_id IS NULL
    ORDER BY a.application_id ASC");
$eligibleStmt->bind_param('sis', $type, $committeeId, $track);
$eligibleStmt->execute();
$eligibleIds = array_column($eligibleStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'application_id');
$eligibleStmt->close();

$eligibleApplications = [];
foreach ($rows as $row) {
    if (in_array($row['application_id'], $eligibleIds, true)) {
        $eligibleApplications[] = $row;
    }
}

// ---- filter / sort / search (GET, applied in PHP over the small result set) ----
$statusFilter = $_GET['status'] ?? '';
$sortBy = $_GET['sort'] ?? '';
$search = trim($_GET['q'] ?? '');

$displayRows = $rows;
foreach ($displayRows as &$row) {
    $row['display_status'] = $row['beneficiary_id'] ? $row['b_status'] : 'not_added';
}
unset($row);

if ($statusFilter !== '') {
    $displayRows = array_values(array_filter($displayRows, fn($r) => $r['display_status'] === $statusFilter));
}
if ($search !== '') {
    $needle = mb_strtolower($search);
    $displayRows = array_values(array_filter($displayRows, fn($r) => str_contains(mb_strtolower($r['full_name']), $needle) || str_contains(mb_strtolower($r['address']), $needle)));
}
switch ($sortBy) {
    case 'name_asc':
        usort($displayRows, fn($a, $b) => strcasecmp($a['full_name'], $b['full_name']));
        break;
    case 'name_desc':
        usort($displayRows, fn($a, $b) => strcasecmp($b['full_name'], $a['full_name']));
        break;
    case 'id_desc':
        usort($displayRows, fn($a, $b) => $b['application_id'] <=> $a['application_id']);
        break;
    case 'id_asc':
    default:
        usort($displayRows, fn($a, $b) => $a['application_id'] <=> $b['application_id']);
        break;
}

function statusBadgeClass($status)
{
    switch ($status) {
        case 'distributed':
            return 'success';
        case 'pending':
            return 'warning';
        default:
            return 'secondary';
    }
}
function statusLabel($status)
{
    switch ($status) {
        case 'distributed':
            return 'Distributed';
        case 'pending':
            return 'Pending';
        default:
            return 'Not Added';
    }
}

$activeLink = 'HealthInKindAssistance';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($activeProgram ? $activeProgram['name'] : 'Health'); ?> In-Kind Assistance Beneficiaries</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo APP_BASE; ?>/assets/css/admin.css" rel="stylesheet">
</head>

<body>

    <?php include __DIR__ . '/../../includes/adminsidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <h4 class="fw-bold mb-1"><?php echo e($activeProgram ? $activeProgram['name'] : 'Health'); ?> In-Kind Assistance Beneficiaries</h4>
        <p class="text-muted mb-2" style="font-size: 13px;">
            <?php if ($activeProgram): ?>
                Viewing beneficiaries for the <?php echo e($activeProgram['name']); ?> program only. <a href="HealthInKindAssistance.php">View all Health programs</a>.
            <?php else: ?>
                Monitor beneficiaries approved for Health in-kind assistance.
            <?php endif; ?>
        </p>

        <?php if ($pageSuccess): ?><div class="alert alert-success py-2"><?php echo e($pageSuccess); ?></div><?php endif; ?>
        <?php if ($pageError): ?><div class="alert alert-danger py-2"><?php echo e($pageError); ?></div><?php endif; ?>

        <form method="get">
            <!-- Table Header -->
            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addBeneficiaryModal">
                        <i class="bi bi-plus-lg me-1"></i> Add Beneficiary
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#archivesBeneficiaryModal">
                        <i class="bi bi-list-ul me-1"></i> All Beneficiaries
                    </button>
                </div>
                <div class="search-box">
                    <input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Search...">
                    <i class="bi bi-search"></i>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="filter-bar d-flex gap-2 align-items-center mb-3 flex-wrap">
                <span style="font-size:12px; color:#666; font-weight:600;"><i class="bi bi-funnel me-1"></i>Filter:</span>
                <?php if (!empty($committeePrograms)): ?>
                    <select class="filter-select" name="program_id" onchange="this.form.submit()">
                        <option value="">All Programs</option>
                        <?php foreach ($committeePrograms as $cp): ?>
                            <option value="<?php echo $cp['program_id']; ?>" <?php echo $programId === (int)$cp['program_id'] ? 'selected' : ''; ?>><?php echo e($cp['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <select class="filter-select" name="status" onchange="this.form.submit()">
                    <option value="">All Status</option>
                    <option value="not_added" <?php echo $statusFilter === 'not_added' ? 'selected' : ''; ?>>Not Added</option>
                    <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="distributed" <?php echo $statusFilter === 'distributed' ? 'selected' : ''; ?>>Distributed</option>
                </select>
                <span style="font-size:12px; color:#666; font-weight:600; margin-left:6px;"><i class="bi bi-sort-down me-1"></i>Sort by:</span>
                <select class="filter-select" name="sort" onchange="this.form.submit()">
                    <option value="id_asc" <?php echo $sortBy === '' || $sortBy === 'id_asc' ? 'selected' : ''; ?>>Applicant ID: Oldest &rarr; Newest</option>
                    <option value="id_desc" <?php echo $sortBy === 'id_desc' ? 'selected' : ''; ?>>Applicant ID: Newest &rarr; Oldest</option>
                    <option value="name_asc" <?php echo $sortBy === 'name_asc' ? 'selected' : ''; ?>>Name: A &rarr; Z</option>
                    <option value="name_desc" <?php echo $sortBy === 'name_desc' ? 'selected' : ''; ?>>Name: Z &rarr; A</option>
                </select>
            </div>
        </form>

        <!-- Table -->
        <div class="table-card">
            <div class="table-responsive-wrap">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>Applicant ID</th>
                            <th>Full Name</th>
                            <th>Address</th>
                            <?php if (!empty($committeePrograms)): ?><th>Program</th><?php endif; ?>
                            <th>Item(s)</th>
                            <th>Quantity</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($displayRows)): ?>
                            <tr>
                                <td colspan="<?php echo !empty($committeePrograms) ? 8 : 7; ?>" class="text-center text-muted py-4">No <?php echo e($activeProgram ? $activeProgram['name'] : 'health'); ?> in-kind assistance beneficiaries found.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($displayRows as $row): ?>
                            <tr>
                                <td><?php echo str_pad($row['application_id'], 3, '0', STR_PAD_LEFT); ?></td>
                                <td><?php echo e($row['full_name']); ?></td>
                                <td><?php echo e($row['address']); ?></td>
                                <?php if (!empty($committeePrograms)): ?><td><?php echo e(programLabel($row['program_id'])); ?></td><?php endif; ?>
                                <td><?php echo $row['beneficiary_id'] ? e($row['items']) : '—'; ?></td>
                                <td><?php echo $row['beneficiary_id'] ? e($row['quantity']) : '—'; ?></td>
                                <td><span class="badge text-bg-<?php echo statusBadgeClass($row['display_status']); ?>"><?php echo statusLabel($row['display_status']); ?></span></td>
                                <td class="d-flex gap-1">
                                    <?php if ($row['beneficiary_id']): ?>
                                        <button class="btn-view" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $row['beneficiary_id']; ?>"><i class="bi bi-eye"></i> View</button>
                                        <button class="btn-edit" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $row['beneficiary_id']; ?>"><i class="bi bi-pencil"></i> Edit</button>
                                        <button class="btn-archive" data-bs-toggle="modal" data-bs-target="#deleteModal<?php echo $row['beneficiary_id']; ?>"><i class="bi bi-trash"></i> Delete</button>
                                    <?php else: ?>
                                        <button type="button" class="btn-view" onclick="document.getElementById('addAppSelect').value='<?php echo $row['application_id']; ?>';" data-bs-toggle="modal" data-bs-target="#addBeneficiaryModal"><i class="bi bi-plus-lg"></i> Add</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
            <span style="font-size:12px; color:#888;">Showing <?php echo count($displayRows); ?> of <?php echo count($displayRows); ?> entries</span>
        </div>
    </div>

    <!-- ADD -->
    <div class="modal fade" id="addBeneficiaryModal" tabindex="-1" aria-labelledby="addBeneficiaryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <form method="post">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold" id="addBeneficiaryModalLabel">
                            <i class="bi bi-person-plus-fill me-2"></i> Add In-Kind Assistance Beneficiary
                        </h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-4">
                        <?php if (empty($eligibleApplications)): ?>
                            <div class="alert alert-warning py-2 mb-0">There are no approved Health applicants awaiting in-kind assistance setup right now.</div>
                        <?php else: ?>
                            <div class="section-divider"><i class="bi bi-person-fill me-1"></i> Applicant</div>
                            <div class="mb-3">
                                <label class="info-label">Select Approved Applicant <span class="text-danger">*</span></label>
                                <select class="form-select" name="application_id" id="addAppSelect" required>
                                    <option value="" disabled selected>Choose an applicant...</option>
                                    <?php foreach ($eligibleApplications as $ea): ?>
                                        <option value="<?php echo $ea['application_id']; ?>">#<?php echo str_pad($ea['application_id'], 3, '0', STR_PAD_LEFT); ?> — <?php echo e($ea['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="section-divider"><i class="bi bi-box-seam me-1"></i> Assistance Information</div>
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="info-label">Item(s) <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="items" placeholder="e.g. School Supplies Kit" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="info-label">Quantity <span class="text-danger">*</span></label>
                                    <input type="number" min="1" class="form-control" name="quantity" required>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-sm btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                        <?php if (!empty($eligibleApplications)): ?>
                            <button type="submit" name="add_beneficiary" class="btn btn-sm btn-success px-4">
                                <i class="bi bi-save me-1"></i> Save Beneficiary
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php foreach ($displayRows as $row):
        if (!$row['beneficiary_id']) continue;
    ?>
        <!-- VIEW MODAL -->
        <div class="modal fade" id="viewModal<?php echo $row['beneficiary_id']; ?>" tabindex="-1">
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
                                <div class="info-value">#<?php echo str_pad($row['application_id'], 3, '0', STR_PAD_LEFT); ?></div>
                            </div>
                            <div class="col-md-8">
                                <div class="info-label">Full Name</div>
                                <div class="info-value"><?php echo e($row['full_name']); ?></div>
                            </div>
                            <div class="col-12">
                                <div class="info-label">Address</div>
                                <div class="info-value"><?php echo e($row['address']); ?></div>
                            </div>
                        </div>
                        <div class="section-divider"><i class="bi bi-box-seam me-1"></i> Assistance Information</div>
                        <div class="row g-3 mb-2">
                            <div class="col-md-4">
                                <div class="info-label">Item(s)</div>
                                <div class="info-value"><?php echo e($row['items']); ?></div>
                            </div>
                            <div class="col-md-2">
                                <div class="info-label">Quantity</div>
                                <div class="info-value"><?php echo e($row['quantity']); ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-label">Status</div>
                                <div class="info-value"><?php echo statusLabel($row['b_status']); ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-label">Date Distributed</div>
                                <div class="info-value"><?php echo $row['date_distributed'] ? e($row['date_distributed']) : '—'; ?></div>
                            </div>
                        </div>
                        <div class="section-divider"><i class="bi bi-paperclip me-1"></i> Submitted Documents</div>
                        <div class="row g-2">
                            <?php foreach ($row['fields'] as $field):
                                if ($field['input_type'] !== 'file') continue;
                                $file = $row['files'][$field['field_id']] ?? null;
                            ?>
                                <div class="col-md-6">
                                    <div class="info-label mb-1"><?php echo e($field['label']); ?></div>
                                    <?php if ($file): ?>
                                        <a class="doc-item" href="<?php echo APP_BASE . '/' . e($file['path']); ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-image-fill"></i><?php echo e($file['original_name']); ?><i class="bi bi-check-circle-fill doc-check"></i></a>
                                    <?php else: ?>
                                        <div class="doc-item"><i class="bi bi-file-earmark-image-fill"></i>Not submitted</div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($row['b_status'] === 'pending'): ?>
                            <div class="decision-bar">
                                <div class="decision-title"><i class="bi bi-box-seam me-1"></i> Item Distribution</div>
                                <div class="decision-sub">Confirm this beneficiary has received their in-kind assistance.</div>
                                <div class="d-flex gap-2 flex-wrap">
                                    <button class="btn-approve" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#distributeModal<?php echo $row['beneficiary_id']; ?>"><i class="bi bi-box-seam"></i> Mark as Distributed</button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- EDIT MODAL -->
        <div class="modal fade" id="editModal<?php echo $row['beneficiary_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content border-0 shadow">
                    <form method="post">
                        <input type="hidden" name="beneficiary_id" value="<?php echo $row['beneficiary_id']; ?>">
                        <div class="modal-header">
                            <h6 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Beneficiary</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4">
                            <div class="section-divider"><i class="bi bi-person-fill me-1"></i> Applicant</div>
                            <p class="info-value">#<?php echo str_pad($row['application_id'], 3, '0', STR_PAD_LEFT); ?> — <?php echo e($row['full_name']); ?></p>
                            <div class="section-divider"><i class="bi bi-box-seam me-1"></i> Assistance Information</div>
                            <div class="row g-3 mb-2">
                                <div class="col-md-4">
                                    <label class="form-label" style="font-size:11px;font-weight:700;color:#888;text-transform:uppercase;">Item(s)</label>
                                    <input type="text" class="form-control form-control-sm" name="items" value="<?php echo e($row['items']); ?>" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label" style="font-size:11px;font-weight:700;color:#888;text-transform:uppercase;">Quantity</label>
                                    <input type="number" min="1" class="form-control form-control-sm" name="quantity" value="<?php echo e($row['quantity']); ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" style="font-size:11px;font-weight:700;color:#888;text-transform:uppercase;">Status</label>
                                    <select class="form-select form-select-sm" name="status">
                                        <option value="pending" <?php echo $row['b_status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="distributed" <?php echo $row['b_status'] === 'distributed' ? 'selected' : ''; ?>>Distributed</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" style="font-size:11px;font-weight:700;color:#888;text-transform:uppercase;">Date Distributed</label>
                                    <input type="date" class="form-control form-control-sm" name="date_distributed" value="<?php echo e($row['date_distributed']); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer border-0">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="edit_beneficiary" class="btn btn-sm btn-success"><i class="bi bi-floppy me-1"></i>Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- DELETE MODAL -->
        <div class="modal fade" id="deleteModal<?php echo $row['beneficiary_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-sm">
                <div class="modal-content border-0 shadow">
                    <form method="post">
                        <input type="hidden" name="beneficiary_id" value="<?php echo $row['beneficiary_id']; ?>">
                        <div class="modal-header">
                            <h6 class="modal-title fw-bold"><i class="bi bi-archive me-2"></i>Delete Beneficiary Record</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4 text-center">
                            <div class="archive-icon-wrap"><i class="bi bi-trash-fill"></i></div>
                            <p class="fw-bold mb-1" style="font-size:14px;">Are you sure?</p>
                            <p class="text-muted" style="font-size:12px; margin-bottom:0;">This removes the in-kind assistance record for this applicant. The application itself is not affected, and a new beneficiary record can be added again later.</p>
                        </div>
                        <div class="modal-footer border-0 justify-content-center gap-2">
                            <button type="button" class="btn btn-sm btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="delete_beneficiary" class="btn btn-sm btn-danger px-4"><i class="bi bi-trash me-1"></i>Yes, Delete</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- DISTRIBUTE MODAL -->
        <div class="modal fade" id="distributeModal<?php echo $row['beneficiary_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-sm">
                <div class="modal-content border-0 shadow">
                    <form method="post">
                        <input type="hidden" name="beneficiary_id" value="<?php echo $row['beneficiary_id']; ?>">
                        <div class="modal-header" style="background: linear-gradient(90deg, #45b84d, #aadaad);">
                            <h6 class="modal-title fw-bold text-white"><i class="bi bi-box-seam me-2"></i>Distribute Item(s)</h6>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4 text-center">
                            <div style="width:64px;height:64px;border-radius:50%;background:#e8f5e9;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;">
                                <i class="bi bi-box-seam" style="font-size:28px;color:#2e7d32;"></i>
                            </div>
                            <p class="fw-bold mb-1" style="font-size:14px;">Mark this beneficiary as distributed?</p>
                            <p class="text-muted" style="font-size:12px; margin-bottom:14px;">This confirms the in-kind assistance item(s) have been given to the beneficiary.</p>
                            <div class="text-start">
                                <label style="font-size:12px;font-weight:600;color:#555;">Date Distributed</label>
                                <input type="date" class="form-control form-control-sm mt-1" name="date_distributed" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        <div class="modal-footer border-0 justify-content-center gap-2">
                            <button type="button" class="btn btn-sm btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="distribute_item" class="btn btn-sm btn-success px-4"><i class="bi bi-check-lg me-1"></i>Confirm Distribution</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- ALL BENEFICIARIES MODAL -->
    <div class="modal fade" id="archivesBeneficiaryModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold"><i class="bi bi-list-ul me-2"></i> All Approved Applicants (Health Assistance)</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="table-card">
                        <div class="table-responsive-wrap">
                            <table class="table mb-0" style="font-size:13px;">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Full Name</th>
                                        <th>Address</th>
                                        <th>Item(s)</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($rows)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">No approved Health applicants yet.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($rows as $row): ?>
                                        <tr>
                                            <td><?php echo str_pad($row['application_id'], 3, '0', STR_PAD_LEFT); ?></td>
                                            <td><?php echo e($row['full_name']); ?></td>
                                            <td><?php echo e($row['address']); ?></td>
                                            <td><?php echo $row['beneficiary_id'] ? e($row['items']) : '—'; ?></td>
                                            <td><span class="badge text-bg-<?php echo statusBadgeClass($row['beneficiary_id'] ? $row['b_status'] : 'not_added'); ?>"><?php echo statusLabel($row['beneficiary_id'] ? $row['b_status'] : 'not_added'); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>