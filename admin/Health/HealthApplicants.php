<?php
require_once __DIR__ . '/../../config/forms.php';
requireRole('admin');

$committeeId = getCommitteeIdByCode('health');
$track = 'assistance';
$me = currentUser();

// Scope the whole page to one specific program tab when navigated here via a program-specific
// sidebar link, instead of pooling every program under the committee together — lets admins tell
// applicants for different programs apart instead of them all landing in one shared list.
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

    if (isset($_POST['add_applicant'])) {
        $errors = validateDynamicSubmission($committeeId, $_POST, $_FILES, [], $track, $programId);
        if (empty($errors)) {
            $lastName = trim($_POST['last_name'] ?? '');
            $firstName = trim($_POST['first_name'] ?? '');
            $middleName = trim($_POST['middle_name'] ?? '');
            $userId = getOrCreateWalkInUser($lastName, $firstName, $middleName);

            $settings = $conn->query("SELECT current_academic_year, current_semester FROM site_settings WHERE id = 1")->fetch_assoc();
            $stmt = $conn->prepare("INSERT INTO applications (user_id, committee_id, program_track, program_id, status, academic_year, semester) VALUES (?, ?, ?, ?, 'pending', ?, ?)");
            $stmt->bind_param('iisiss', $userId, $committeeId, $track, $programId, $settings['current_academic_year'], $settings['current_semester']);
            $stmt->execute();
            $applicationId = $stmt->insert_id;
            $stmt->close();

            saveDynamicSubmission($applicationId, $committeeId, $_POST, $_FILES, $track, $programId);
            logAudit('Added Applicant', 'Health applicant #' . $applicationId);
            setFlash('success', 'Applicant added.');
        } else {
            setFlash('error', implode(' ', $errors));
        }
    }

    if (isset($_POST['edit_application'])) {
        $applicationId = (int)$_POST['application_id'];
        // The application's own program (not the page's current filter) decides which extra
        // fields apply — an admin filtering "All Programs" could be editing any applicant's row.
        $appProgramId = getApplicationProgramId($applicationId);
        $existingFiles = getApplicationFiles($applicationId);
        $errors = validateDynamicSubmission($committeeId, $_POST, $_FILES, $existingFiles, $track, $appProgramId);
        if (empty($errors)) {
            saveDynamicSubmission($applicationId, $committeeId, $_POST, $_FILES, $track, $appProgramId);
            logAudit('Updated Applicant', 'Health applicant #' . $applicationId);
            setFlash('success', 'Applicant updated.');
        } else {
            setFlash('error', implode(' ', $errors));
        }
    }

    if (isset($_POST['approve_application'])) {
        $applicationId = (int)$_POST['application_id'];
        if (isProgramFull($committeeId, $track)) {
            setFlash('error', 'Cannot approve — this program has reached its slot limit. Increase it in Configuration > Application/Program Settings, or decline/archive another approved applicant first.');
        } else {
            $stmt = $conn->prepare("UPDATE applications SET status = 'approved', decided_at = NOW(), decided_by = ? WHERE application_id = ?");
            $stmt->bind_param('ii', $me['user_id'], $applicationId);
            $stmt->execute();
            $stmt->close();
            logAudit('Approved Application', 'Health applicant #' . $applicationId);
            setFlash('success', 'Applicant approved.');
        }
    }

    if (isset($_POST['decline_application'])) {
        $applicationId = (int)$_POST['application_id'];
        $reason = trim($_POST['reason'] ?? '');
        $stmt = $conn->prepare("UPDATE applications SET status = 'declined', decline_reason = ?, decided_at = NOW(), decided_by = ? WHERE application_id = ?");
        $stmt->bind_param('sii', $reason, $me['user_id'], $applicationId);
        $stmt->execute();
        $stmt->close();
        logAudit('Declined Application', 'Health applicant #' . $applicationId);
        setFlash('success', 'Applicant declined.');
    }

    if (isset($_POST['archive_application'])) {
        $applicationId = (int)$_POST['application_id'];
        $stmt = $conn->prepare("UPDATE applications SET archived_at = NOW() WHERE application_id = ?");
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $stmt->close();
        logAudit('Archived Applicant', 'Health applicant #' . $applicationId);
        setFlash('success', 'Applicant archived.');
    }

    if (isset($_POST['restore_application'])) {
        $applicationId = (int)$_POST['application_id'];
        $stmt = $conn->prepare("UPDATE applications SET archived_at = NULL WHERE application_id = ?");
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $stmt->close();
        logAudit('Restored Applicant', 'Health applicant #' . $applicationId);
        setFlash('success', 'Applicant restored.');
    }

    header("Location: HealthApplicants.php" . ($programId !== null ? '?program_id=' . $programId : ''));
    exit();
}

$pageError = getFlash('error');
$pageSuccess = getFlash('success');

$applications = listApplications($committeeId, $track, $programId);
$archivedApplications = listArchivedApplications($committeeId, $track, $programId);

// ---- filter / sort / search (GET, applied in PHP over the small result set) ----
$statusFilter = $_GET['status'] ?? '';
$sortBy = $_GET['sort'] ?? '';
$search = trim($_GET['q'] ?? '');

foreach ($applications as &$app) {
    // Each application's own program has its own independent field set.
    $appOwnFields = getFormFields($committeeId, $track, $app['program_id']);
    $app['full_name'] = trim(answerByKey($app, $appOwnFields, 'last_name') . ' ' . answerByKey($app, $appOwnFields, 'first_name'));
    $app['address'] = answerByKey($app, $appOwnFields, 'complete_address');
    $app['assistance_type'] = answerByKey($app, $appOwnFields, 'assistance_type');
}
unset($app);

if ($statusFilter !== '') {
    $applications = array_values(array_filter($applications, fn($a) => $a['status'] === $statusFilter));
}
if ($search !== '') {
    $needle = mb_strtolower($search);
    $applications = array_values(array_filter($applications, fn($a) => str_contains(mb_strtolower($a['full_name']), $needle) || str_contains(mb_strtolower($a['address']), $needle)));
}
switch ($sortBy) {
    case 'name_asc':
        usort($applications, fn($a, $b) => strcasecmp($a['full_name'], $b['full_name']));
        break;
    case 'name_desc':
        usort($applications, fn($a, $b) => strcasecmp($b['full_name'], $a['full_name']));
        break;
    case 'id_oldest':
        usort($applications, fn($a, $b) => $a['application_id'] <=> $b['application_id']);
        break;
    case 'id_newest':
        usort($applications, fn($a, $b) => $b['application_id'] <=> $a['application_id']);
        break;
}

$activeLink = 'HealthApplicants';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($activeProgram ? $activeProgram['name'] : 'Health'); ?> Applicants</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo APP_BASE; ?>/assets/css/admin.css" rel="stylesheet">
</head>

<body>

    <?php include __DIR__ . '/../../includes/adminsidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <h4 class="fw-bold mb-1"><?php echo e($activeProgram ? $activeProgram['name'] : 'Health'); ?> Applicants</h4>
        <p class="text-muted mb-2" style="font-size: 13px;">
            <?php if ($activeProgram): ?>
                Viewing applications for the <?php echo e($activeProgram['name']); ?> program only. <a href="HealthApplicants.php">View all Health programs</a>.
            <?php else: ?>
                View applications for Health financial assistance.
            <?php endif; ?>
        </p>

        <?php if ($pageSuccess): ?><div class="alert alert-success py-2"><?php echo e($pageSuccess); ?></div><?php endif; ?>
        <?php if ($pageError): ?><div class="alert alert-danger py-2"><?php echo e($pageError); ?></div><?php endif; ?>

        <form method="get">
            <!-- Table Header -->
            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addApplicantModal">
                        <i class="bi bi-plus-lg me-1"></i> Add Applicant
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#archivesApplicantModal">
                        <i class="bi bi-archive me-1"></i> Archives
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
                    <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="declined" <?php echo $statusFilter === 'declined' ? 'selected' : ''; ?>>Declined</option>
                </select>
                <span style="font-size:12px; color:#666; font-weight:600; margin-left:6px;"><i class="bi bi-sort-down me-1"></i>Sort by:</span>
                <select class="filter-select" name="sort" onchange="this.form.submit()">
                    <option value="">Default Order</option>
                    <option value="name_asc" <?php echo $sortBy === 'name_asc' ? 'selected' : ''; ?>>Name: A → Z</option>
                    <option value="name_desc" <?php echo $sortBy === 'name_desc' ? 'selected' : ''; ?>>Name: Z → A</option>
                    <option value="id_oldest" <?php echo $sortBy === 'id_oldest' ? 'selected' : ''; ?>>Applicant ID: Oldest First</option>
                    <option value="id_newest" <?php echo $sortBy === 'id_newest' ? 'selected' : ''; ?>>Applicant ID: Newest First</option>
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
                            <th>Type of Assistance</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($applications)): ?>
                            <tr>
                                <td colspan="<?php echo !empty($committeePrograms) ? 7 : 6; ?>">
                                    <div class="empty-state">
                                        <i class="bi bi-inbox"></i>
                                        <p>No <?php echo e($activeProgram ? $activeProgram['name'] : 'health assistance'); ?> applications found.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($applications as $app): ?>
                            <tr>
                                <td><?php echo str_pad($app['application_id'], 3, '0', STR_PAD_LEFT); ?></td>
                                <td><?php echo e($app['full_name']); ?></td>
                                <td><?php echo e($app['address']); ?></td>
                                <?php if (!empty($committeePrograms)): ?><td><?php echo e(programLabel($app['program_id'])); ?></td><?php endif; ?>
                                <td><?php echo e($app['assistance_type']); ?></td>
                                <td><span class="badge text-bg-<?php echo $app['status'] === 'approved' ? 'success' : ($app['status'] === 'declined' ? 'danger' : 'warning'); ?>"><?php echo ucfirst($app['status']); ?></span></td>
                                <td class="d-flex gap-1">
                                    <button class="btn-view" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $app['application_id']; ?>"><i class="bi bi-eye"></i> View</button>
                                    <button class="btn-edit" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $app['application_id']; ?>"><i class="bi bi-pencil"></i> Edit</button>
                                    <button class="btn-archive" data-bs-toggle="modal" data-bs-target="#archiveModal<?php echo $app['application_id']; ?>"><i class="bi bi-archive"></i> Archive</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
            <span style="font-size:12px; color:#888;">Showing <?php echo count($applications); ?> of <?php echo count($applications); ?> entries</span>
        </div>
    </div>

    <!-- ADD -->
    <div class="modal fade" id="addApplicantModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <form method="post" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold"><i class="bi bi-person-plus-fill me-2"></i> Health Assistance Application Form</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <?php renderDynamicFormFields($committeeId, [], [], $track, $programId); ?>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-sm btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_applicant" class="btn btn-sm btn-success px-4"><i class="bi bi-send me-1"></i> Submit Application</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php foreach ($applications as $app):
        // Render this applicant's own program's fields (shared + whatever that program added),
        // not the page's current filter — "All Programs" pools applicants from every program together.
        $appFields = getFormFields($committeeId, $track, $app['program_id']);
    ?>
        <!-- VIEW MODAL -->
        <div class="modal fade" id="viewModal<?php echo $app['application_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold"><i class="bi bi-file-earmark-text-fill me-2"></i>Applicant Details</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="row g-3 mb-2">
                            <?php foreach ($appFields as $field):
                                if ($field['input_type'] === 'file') continue;
                                $val = $app['answers'][$field['field_id']] ?? '';
                            ?>
                                <div class="col-md-6">
                                    <div class="info-label"><?php echo e($field['label']); ?></div>
                                    <div class="info-value"><?php echo e($val !== '' ? $val : '—'); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="section-divider"><i class="bi bi-paperclip me-1"></i> Submitted Documents</div>
                        <div class="row g-2">
                            <?php foreach ($appFields as $field):
                                if ($field['input_type'] !== 'file') continue;
                                $file = $app['files'][$field['field_id']] ?? null;
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

                        <?php if ($app['status'] === 'pending'): ?>
                            <div class="decision-bar">
                                <div class="decision-title"><i class="bi bi-clipboard2-check me-1"></i> Application Decision</div>
                                <div class="decision-sub">Review the applicant's information and submitted documents before making a decision.</div>
                                <div class="d-flex gap-2 flex-wrap">
                                    <button class="btn-approve" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#approveModal<?php echo $app['application_id']; ?>"><i class="bi bi-check-circle-fill"></i> Approve</button>
                                    <button class="btn-decline" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#declineModal<?php echo $app['application_id']; ?>"><i class="bi bi-x-circle"></i> Decline</button>
                                </div>
                            </div>
                        <?php elseif ($app['status'] === 'declined' && $app['decline_reason']): ?>
                            <div class="alert alert-danger py-2 mt-3"><strong>Reason:</strong> <?php echo e($app['decline_reason']); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- EDIT MODAL -->
        <div class="modal fade" id="editModal<?php echo $app['application_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content border-0 shadow">
                    <form method="post" enctype="multipart/form-data">
                        <div class="modal-header">
                            <h6 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Applicant</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4">
                            <input type="hidden" name="application_id" value="<?php echo $app['application_id']; ?>">
                            <?php renderDynamicFormFields($committeeId, $app['answers'], $app['files'], $track, $app['program_id']); ?>
                        </div>
                        <div class="modal-footer border-0">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="edit_application" class="btn btn-sm btn-success"><i class="bi bi-floppy me-1"></i>Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ARCHIVE MODAL -->
        <div class="modal fade" id="archiveModal<?php echo $app['application_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-sm">
                <div class="modal-content border-0 shadow">
                    <form method="post">
                        <input type="hidden" name="application_id" value="<?php echo $app['application_id']; ?>">
                        <div class="modal-header">
                            <h6 class="modal-title fw-bold"><i class="bi bi-archive me-2"></i>Archive Applicant</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4 text-center">
                            <div class="archive-icon-wrap"><i class="bi bi-archive-fill"></i></div>
                            <p class="fw-bold mb-1" style="font-size:14px;">Are you sure?</p>
                            <p class="text-muted" style="font-size:12px; margin-bottom:0;">You are about to archive this applicant. They will be removed from the active list but can be restored later from the archive.</p>
                        </div>
                        <div class="modal-footer border-0 justify-content-center gap-2">
                            <button type="button" class="btn btn-sm btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="archive_application" class="btn btn-sm btn-danger px-4"><i class="bi bi-archive me-1"></i>Yes, Archive</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- APPROVE MODAL -->
        <div class="modal fade" id="approveModal<?php echo $app['application_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-sm">
                <div class="modal-content border-0 shadow">
                    <form method="post">
                        <input type="hidden" name="application_id" value="<?php echo $app['application_id']; ?>">
                        <div class="modal-header" style="background: linear-gradient(90deg, #45b84d, #aadaad);">
                            <h6 class="modal-title fw-bold text-white"><i class="bi bi-check-circle-fill me-2"></i>Approve Applicant</h6>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4 text-center">
                            <div style="width:64px;height:64px;border-radius:50%;background:#e8f5e9;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;">
                                <i class="bi bi-check-circle-fill" style="font-size:28px;color:#2e7d32;"></i>
                            </div>
                            <p class="fw-bold mb-1" style="font-size:14px;">Approve this applicant?</p>
                            <p class="text-muted" style="font-size:12px; margin-bottom:0;">This applicant will be approved for Health assistance.</p>
                        </div>
                        <div class="modal-footer border-0 justify-content-center gap-2">
                            <button type="button" class="btn btn-sm btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="approve_application" class="btn btn-sm btn-success px-4"><i class="bi bi-check-lg me-1"></i>Yes, Approve</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- DECLINE MODAL -->
        <div class="modal fade" id="declineModal<?php echo $app['application_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-sm">
                <div class="modal-content border-0 shadow">
                    <form method="post">
                        <input type="hidden" name="application_id" value="<?php echo $app['application_id']; ?>">
                        <div class="modal-header" style="background: linear-gradient(90deg, #e53935, #ef9a9a);">
                            <h6 class="modal-title fw-bold text-white"><i class="bi bi-x-circle-fill me-2"></i>Decline Applicant</h6>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4 text-center">
                            <div style="width:64px;height:64px;border-radius:50%;background:#fce4ec;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;">
                                <i class="bi bi-x-circle-fill" style="font-size:28px;color:#c62828;"></i>
                            </div>
                            <p class="fw-bold mb-1" style="font-size:14px;">Decline this applicant?</p>
                            <p class="text-muted" style="font-size:12px; margin-bottom:0;">This applicant will be declined and notified. This action can be reviewed later from the archive.</p>
                            <div class="mt-3 text-start">
                                <label style="font-size:12px;font-weight:600;color:#555;">Reason for Declining <span class="text-muted fw-normal">(optional)</span></label>
                                <textarea class="form-control form-control-sm mt-1" name="reason" rows="3" placeholder="e.g. Incomplete documents, not a resident of Barangay Langkiwa..."></textarea>
                            </div>
                        </div>
                        <div class="modal-footer border-0 justify-content-center gap-2">
                            <button type="button" class="btn btn-sm btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="decline_application" class="btn btn-sm btn-danger px-4"><i class="bi bi-x-lg me-1"></i>Yes, Decline</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- ARCHIVES MODAL -->
    <div class="modal fade" id="archivesApplicantModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold"><i class="bi bi-archive-fill me-2"></i> Archived Applicants</h6>
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
                                        <th>Archived On</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($archivedApplications)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">No archived applicants.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($archivedApplications as $arch):
                                        $archAnswers = getApplicationAnswers($arch['application_id']);
                                        $archApp = ['answers' => $archAnswers];
                                        $archFields = getFormFields($committeeId, $track, $arch['program_id']);
                                    ?>
                                        <tr>
                                            <td><?php echo str_pad($arch['application_id'], 3, '0', STR_PAD_LEFT); ?></td>
                                            <td><?php echo e(trim(answerByKey($archApp, $archFields, 'last_name') . ' ' . answerByKey($archApp, $archFields, 'first_name'))); ?></td>
                                            <td><?php echo e(answerByKey($archApp, $archFields, 'complete_address')); ?></td>
                                            <td><?php echo date('Y-m-d H:i', strtotime($arch['archived_at'])); ?></td>
                                            <td class="text-center">
                                                <form method="post">
                                                    <input type="hidden" name="application_id" value="<?php echo $arch['application_id']; ?>">
                                                    <button type="submit" name="restore_application" class="btn btn-sm btn-outline-success py-0 px-2" style="font-size:11px;"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
                                                </form>
                                            </td>
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
