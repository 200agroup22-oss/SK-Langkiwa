<?php
require_once __DIR__ . '/../../config/forms.php';
requireRole(['admin', 'committee_admin']);

$committeeId = getCommitteeIdByCode('education');
requireCommitteeAccess($committeeId);
$track = 'scholarship';
$me = currentUser();

// ---- POST handlers (redirect-after-POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['add_applicant'])) {
        $errors = validateDynamicSubmission($committeeId, $_POST, $_FILES, [], $track);
        if (empty($errors)) {
            $lastName = trim($_POST['last_name'] ?? '');
            $firstName = trim($_POST['first_name'] ?? '');
            $middleName = trim($_POST['middle_name'] ?? '');
            $userId = getOrCreateWalkInUser($lastName, $firstName, $middleName);

            $settings = $conn->query("SELECT current_academic_year, current_semester FROM site_settings WHERE id = 1")->fetch_assoc();
            $stmt = $conn->prepare("INSERT INTO applications (user_id, committee_id, program_track, status, academic_year, semester) VALUES (?, ?, ?, 'pending', ?, ?)");
            $stmt->bind_param('iisss', $userId, $committeeId, $track, $settings['current_academic_year'], $settings['current_semester']);
            $stmt->execute();
            $applicationId = $stmt->insert_id;
            $stmt->close();

            saveDynamicSubmission($applicationId, $committeeId, $_POST, $_FILES, $track);
            logAudit('Added Applicant', 'Education scholarship applicant #' . $applicationId);
            setFlash('success', 'Applicant added.');
        } else {
            setFlash('error', implode(' ', $errors));
        }
    }

    if (isset($_POST['edit_application'])) {
        $applicationId = (int)$_POST['application_id'];
        $existingFiles = getApplicationFiles($applicationId);
        $errors = validateDynamicSubmission($committeeId, $_POST, $_FILES, $existingFiles, $track);
        if (empty($errors)) {
            saveDynamicSubmission($applicationId, $committeeId, $_POST, $_FILES, $track);
            logAudit('Updated Applicant', 'Education scholarship applicant #' . $applicationId);
            setFlash('success', 'Applicant updated.');
        } else {
            setFlash('error', implode(' ', $errors));
        }
    }

    if (isset($_POST['approve_application'])) {
        $applicationId = (int)$_POST['application_id'];
        if (isProgramFull($committeeId, $track)) {
            setFlash('error', 'Cannot approve — iSKolar ng Langkiwa has reached its slot limit. Increase it in Configuration > Application/Program Settings, or decline/archive another approved applicant first.');
        } else {
            $stmt = $conn->prepare("UPDATE applications SET status = 'approved', decided_at = NOW(), decided_by = ? WHERE application_id = ?");
            $stmt->bind_param('ii', $me['user_id'], $applicationId);
            $stmt->execute();
            $stmt->close();

            // Promote the applicant into an active scholar record. Year Level is stored as a
            // text option ("1st Year", "2nd Year" ...) on the application, but scholars.year_level
            // is a plain number, so keep just the leading digit.
            $ownFields = getFormFields($committeeId, $track);
            $appForAnswers = ['answers' => getApplicationAnswers($applicationId)];
            $school = answerByKey($appForAnswers, $ownFields, 'school_university');
            $course = answerByKey($appForAnswers, $ownFields, 'course');
            $yearLevelText = answerByKey($appForAnswers, $ownFields, 'year_level');
            $yearLevel = preg_match('/(\d+)/', $yearLevelText, $ylm) ? (int)$ylm[1] : null;

            $userRow = $conn->prepare("SELECT user_id FROM applications WHERE application_id = ?");
            $userRow->bind_param('i', $applicationId);
            $userRow->execute();
            $userId = ($r = $userRow->get_result()->fetch_assoc()) ? (int)$r['user_id'] : null;
            $userRow->close();

            if ($userId) {
                $already = $conn->prepare("SELECT scholar_id FROM scholars WHERE user_id = ?");
                $already->bind_param('i', $userId);
                $already->execute();
                $exists = $already->get_result()->fetch_assoc();
                $already->close();

                if (!$exists) {
                    $insert = $conn->prepare("INSERT INTO scholars (user_id, application_id, school, course, year_level, status) VALUES (?, ?, ?, ?, ?, 'active')");
                    $insert->bind_param('iissi', $userId, $applicationId, $school, $course, $yearLevel);
                    $insert->execute();
                    $insert->close();

                    $roleStmt = $conn->prepare("UPDATE users SET role = 'scholar' WHERE user_id = ?");
                    $roleStmt->bind_param('i', $userId);
                    $roleStmt->execute();
                    $roleStmt->close();
                }
            }

            logAudit('Approved Application', 'Education scholarship applicant #' . $applicationId);
            notifyApplicationDecision($applicationId, 'approved');
            setFlash('success', 'Applicant approved and added to the Scholars list.');
        }
    }

    if (isset($_POST['decline_application'])) {
        $applicationId = (int)$_POST['application_id'];
        $reason = trim($_POST['reason'] ?? '');
        $stmt = $conn->prepare("UPDATE applications SET status = 'declined', decline_reason = ?, decided_at = NOW(), decided_by = ? WHERE application_id = ?");
        $stmt->bind_param('sii', $reason, $me['user_id'], $applicationId);
        $stmt->execute();
        $stmt->close();
        logAudit('Declined Application', 'Education scholarship applicant #' . $applicationId);
        notifyApplicationDecision($applicationId, 'declined', $reason);
        setFlash('success', 'Applicant declined.');
    }

    if (isset($_POST['archive_application'])) {
        $applicationId = (int)$_POST['application_id'];
        $stmt = $conn->prepare("UPDATE applications SET archived_at = NOW() WHERE application_id = ?");
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $stmt->close();
        logAudit('Archived Applicant', 'Education scholarship applicant #' . $applicationId);
        setFlash('success', 'Applicant archived.');
    }

    if (isset($_POST['restore_application'])) {
        $applicationId = (int)$_POST['application_id'];
        $stmt = $conn->prepare("UPDATE applications SET archived_at = NULL WHERE application_id = ?");
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $stmt->close();
        logAudit('Restored Applicant', 'Education scholarship applicant #' . $applicationId);
        setFlash('success', 'Applicant restored.');
    }

    // -- Renewals: scholars whose term ended (scholars.status = 'pending') via "End Semester" --
    if (isset($_POST['renewal_approve'])) {
        $scholarId = (int)$_POST['scholar_id'];
        $stmt = $conn->prepare("UPDATE scholars SET status = 'active' WHERE scholar_id = ? AND status = 'pending'");
        $stmt->bind_param('i', $scholarId);
        $stmt->execute();
        $stmt->close();
        logAudit('Approved Scholar Renewal', 'Scholar #' . $scholarId);
        setFlash('success', 'Renewal approved — scholar is active again.');
    }

    if (isset($_POST['renewal_decline'])) {
        $scholarId = (int)$_POST['scholar_id'];
        $stmt = $conn->prepare("UPDATE scholars SET status = 'archived' WHERE scholar_id = ? AND status = 'pending'");
        $stmt->bind_param('i', $scholarId);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare("UPDATE users u JOIN scholars s ON s.user_id = u.user_id SET u.role = 'applicant' WHERE s.scholar_id = ?");
        $stmt->bind_param('i', $scholarId);
        $stmt->execute();
        $stmt->close();
        logAudit('Declined Scholar Renewal', 'Scholar #' . $scholarId);
        setFlash('success', 'Renewal declined — scholar moved to the archive.');
    }

    $returnTab = (($_POST['return_tab'] ?? '') === 'renewals') ? '?tab=renewals' : '';
    header("Location: EducationApplicants.php" . $returnTab);
    exit();
}

$pageError = getFlash('error');
$pageSuccess = getFlash('success');
$activeTab = (($_GET['tab'] ?? '') === 'renewals') ? 'renewals' : 'applications';

$applications = listApplications($committeeId, $track);
$archivedApplications = listArchivedApplications($committeeId, $track);
$pendingRenewals = $conn->query("SELECT s.*, u.first_name, u.last_name, u.email
    FROM scholars s JOIN users u ON u.user_id = s.user_id
    WHERE s.status = 'pending' ORDER BY s.scholar_id ASC")->fetch_all(MYSQLI_ASSOC);

// ---- filter / sort / search (GET, applied in PHP over the small result set) ----
$statusFilter = $_GET['status'] ?? '';
$sortBy = $_GET['sort'] ?? '';
$search = trim($_GET['q'] ?? '');

$ownFields = getFormFields($committeeId, $track);
foreach ($applications as &$app) {
    $app['full_name'] = trim(answerByKey($app, $ownFields, 'last_name') . ' ' . answerByKey($app, $ownFields, 'first_name'));
    $app['address'] = answerByKey($app, $ownFields, 'complete_address');
    $app['school'] = answerByKey($app, $ownFields, 'school_university');
    $app['course'] = answerByKey($app, $ownFields, 'course');
    $app['year_level'] = answerByKey($app, $ownFields, 'year_level');
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

$activeLink = 'EducationApplicants';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>iSKolar ng Langkiwa Applicants</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo APP_BASE; ?>/assets/css/admin.css" rel="stylesheet">
</head>

<body>

    <?php include __DIR__ . '/../../includes/adminsidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <h4 class="fw-bold mb-1">iSKolar ng Langkiwa Applicants</h4>
        <p class="text-muted mb-2" style="font-size: 13px;">View new scholarship applications and end-of-term renewal decisions.</p>

        <?php if ($pageSuccess): ?><div class="alert alert-success py-2"><?php echo e($pageSuccess); ?></div><?php endif; ?>
        <?php if ($pageError): ?><div class="alert alert-danger py-2"><?php echo e($pageError); ?></div><?php endif; ?>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-3" id="applicantsTabNav">
            <li class="nav-item">
                <a class="nav-link<?php echo $activeTab === 'applications' ? ' active' : ''; ?>" href="EducationApplicants.php">
                    <i class="bi bi-pencil-square me-1"></i> Applications
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link<?php echo $activeTab === 'renewals' ? ' active' : ''; ?>" href="EducationApplicants.php?tab=renewals">
                    <i class="bi bi-arrow-repeat me-1"></i> Renewals
                    <?php if (!empty($pendingRenewals)): ?><span class="badge text-bg-warning ms-1"><?php echo count($pendingRenewals); ?></span><?php endif; ?>
                </a>
            </li>
        </ul>

        <?php if ($activeTab === 'applications'): ?>

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
                                <th>School</th>
                                <th>Course</th>
                                <th>Year Level</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($applications)): ?>
                                <tr>
                                    <td colspan="8">
                                        <div class="empty-state">
                                            <i class="bi bi-inbox"></i>
                                            <p>No iSKolar ng Langkiwa applications found.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($applications as $app): ?>
                                <tr>
                                    <td><?php echo str_pad($app['application_id'], 3, '0', STR_PAD_LEFT); ?></td>
                                    <td><?php echo e($app['full_name']); ?></td>
                                    <td><?php echo e($app['address']); ?></td>
                                    <td><?php echo e($app['school']); ?></td>
                                    <td><?php echo e($app['course']); ?></td>
                                    <td><?php echo e($app['year_level']); ?></td>
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

        <?php else: ?>

            <!-- Renewals tab -->
            <p class="text-muted mb-2" style="font-size: 13px;">
                These scholars finished their term (via "End Semester" on the Scholars page) and are awaiting a renewal decision. They keep scholar-portal access to submit their updated requirements while pending.
            </p>
            <div class="table-card">
                <div class="table-responsive-wrap">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>Scholar ID</th>
                                <th>Full Name</th>
                                <th>School</th>
                                <th>Course</th>
                                <th>Year Level</th>
                                <th>Finished Term</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pendingRenewals)): ?>
                                <tr>
                                    <td colspan="7">
                                        <div class="empty-state">
                                            <i class="bi bi-inbox"></i>
                                            <p>No renewals awaiting a decision.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($pendingRenewals as $sch): ?>
                                <tr>
                                    <td><?php echo str_pad($sch['scholar_id'], 3, '0', STR_PAD_LEFT); ?></td>
                                    <td><?php echo e(trim($sch['first_name'] . ' ' . $sch['last_name'])); ?></td>
                                    <td><?php echo e($sch['school'] ?? '—'); ?></td>
                                    <td><?php echo e($sch['course'] ?? '—'); ?></td>
                                    <td><?php echo e($sch['year_level'] ?? '—'); ?></td>
                                    <td><?php echo e(trim(($sch['finished_academic_year'] ?? '') . ' ' . ($sch['finished_semester'] ?? ''))); ?></td>
                                    <td class="d-flex gap-1">
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="scholar_id" value="<?php echo $sch['scholar_id']; ?>">
                                            <input type="hidden" name="return_tab" value="renewals">
                                            <button type="submit" name="renewal_approve" class="btn btn-sm btn-success" onclick="return confirm('Approve this renewal and make the scholar active again?');"><i class="bi bi-check-lg me-1"></i>Approve</button>
                                        </form>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="scholar_id" value="<?php echo $sch['scholar_id']; ?>">
                                            <input type="hidden" name="return_tab" value="renewals">
                                            <button type="submit" name="renewal_decline" class="btn btn-sm btn-danger" onclick="return confirm('Decline this renewal? The scholar will be moved to the archive.');"><i class="bi bi-x-lg me-1"></i>Decline</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php endif; ?>
    </div>

    <!-- ADD -->
    <div class="modal fade" id="addApplicantModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <form method="post" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold"><i class="bi bi-person-plus-fill me-2"></i> iSKolar ng Langkiwa Application Form</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <?php renderDynamicFormFields($committeeId, [], [], $track); ?>
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
        $appFields = getFormFields($committeeId, $track);
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
                            <?php renderDynamicFormFields($committeeId, $app['answers'], $app['files'], $track); ?>
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
                            <p class="text-muted" style="font-size:12px; margin-bottom:0;">This applicant will be approved and added to the Scholars list.</p>
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
                                        $archFields = getFormFields($committeeId, $track);
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