<?php
require_once __DIR__ . '/../../config/forms.php';
requireRole(['admin', 'committee_admin', 'secretary', 'treasurer']);

$committeeId = getCommitteeIdByCode('education');
requireCommitteeAccess($committeeId);
$track = 'scholarship';
$fields = getFormFields($committeeId, $track);
$me = currentUser();

function yearLevelToInt($text)
{
    if (preg_match('/(\d)/', $text, $m)) {
        return (int)$m[1];
    }
    return null;
}

// ---- POST handlers (redirect-after-POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Renewal actions redirect back to the Renewals tab instead of the default New Applicants one.
    $redirectTab = '';

    if (isset($_POST['approve_renewal'])) {
        $redirectTab = '?tab=renewals';
        $scholarId = (int)$_POST['scholar_id'];
        $stmt = $conn->prepare("UPDATE scholars SET status = 'active' WHERE scholar_id = ? AND status = 'pending'");
        $stmt->bind_param('i', $scholarId);
        $stmt->execute();
        $stmt->close();
        logAudit('Approved Scholar Renewal', 'Scholar #' . $scholarId);
        setFlash('success', 'Renewal approved — scholar is back on the active list.');
    }

    if (isset($_POST['decline_renewal'])) {
        $redirectTab = '?tab=renewals';
        $scholarId = (int)$_POST['scholar_id'];
        $stmt = $conn->prepare("UPDATE scholars SET status = 'archived' WHERE scholar_id = ? AND status = 'pending'");
        $stmt->bind_param('i', $scholarId);
        $stmt->execute();
        $stmt->close();

        // Declining a renewal is a permanent removal, same as the Scholars page's manual Archive
        // action — the account drops back to a plain applicant and loses scholar-portal access.
        $stmt = $conn->prepare("UPDATE users u JOIN scholars s ON s.user_id = u.user_id SET u.role = 'applicant' WHERE s.scholar_id = ?");
        $stmt->bind_param('i', $scholarId);
        $stmt->execute();
        $stmt->close();

        logAudit('Declined Scholar Renewal', 'Scholar #' . $scholarId);
        setFlash('success', 'Renewal declined — scholar archived.');
    }

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
            logAudit('Added Applicant', 'Education applicant #' . $applicationId);
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
            logAudit('Updated Applicant', 'Education applicant #' . $applicationId);
            setFlash('success', 'Applicant updated.');
        } else {
            setFlash('error', implode(' ', $errors));
        }
    }

    if (isset($_POST['approve_application'])) {
        $applicationId = (int)$_POST['application_id'];

        if (isProgramFull($committeeId, $track)) {
            setFlash('error', 'Cannot approve — this program has reached its slot limit. Increase it in Configuration > Application/Program Settings, or decline/archive another approved applicant first.');
            header("Location: EducationApplicants.php");
            exit();
        }

        $stmt = $conn->prepare("SELECT user_id, academic_year, semester FROM applications WHERE application_id = ?");
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $app = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($app) {
            $stmt = $conn->prepare("UPDATE applications SET status = 'approved', decided_at = NOW(), decided_by = ? WHERE application_id = ?");
            $stmt->bind_param('ii', $me['user_id'], $applicationId);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("SELECT scholar_id FROM scholars WHERE user_id = ?");
            $stmt->bind_param('i', $app['user_id']);
            $stmt->execute();
            $scholar = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$scholar) {
                $answers = getApplicationAnswers($applicationId);
                $school = answerByKey(['answers' => $answers], $fields, 'school_university');
                $course = answerByKey(['answers' => $answers], $fields, 'course');
                $yearLevelText = answerByKey(['answers' => $answers], $fields, 'year_level');
                $yearLevel = yearLevelToInt($yearLevelText);

                $stmt = $conn->prepare("INSERT INTO scholars (user_id, application_id, school, course, year_level, status) VALUES (?, ?, ?, ?, ?, 'active')");
                $stmt->bind_param('iissi', $app['user_id'], $applicationId, $school, $course, $yearLevel);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("UPDATE users SET role = 'scholar' WHERE user_id = ?");
                $stmt->bind_param('i', $app['user_id']);
                $stmt->execute();
                $stmt->close();
            }

            logAudit('Approved Application', 'Education applicant #' . $applicationId);
            notifyApplicationDecision($applicationId, 'approved');
            setFlash('success', 'Applicant approved and added to the Scholar list.');
        }
    }

    if (isset($_POST['decline_application'])) {
        $applicationId = (int)$_POST['application_id'];
        $reason = trim($_POST['reason'] ?? '');
        $stmt = $conn->prepare("UPDATE applications SET status = 'declined', decline_reason = ?, decided_at = NOW(), decided_by = ? WHERE application_id = ?");
        $stmt->bind_param('sii', $reason, $me['user_id'], $applicationId);
        $stmt->execute();
        $stmt->close();
        logAudit('Declined Application', 'Education applicant #' . $applicationId);
        notifyApplicationDecision($applicationId, 'declined', $reason);
        setFlash('success', 'Applicant declined.');
    }

    if (isset($_POST['archive_application'])) {
        $applicationId = (int)$_POST['application_id'];
        $stmt = $conn->prepare("UPDATE applications SET archived_at = NOW() WHERE application_id = ?");
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $stmt->close();
        logAudit('Archived Applicant', 'Education applicant #' . $applicationId);
        setFlash('success', 'Applicant archived.');
    }

    if (isset($_POST['restore_application'])) {
        $applicationId = (int)$_POST['application_id'];
        $stmt = $conn->prepare("UPDATE applications SET archived_at = NULL WHERE application_id = ?");
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $stmt->close();
        logAudit('Restored Applicant', 'Education applicant #' . $applicationId);
        setFlash('success', 'Applicant restored.');
    }

    header("Location: EducationApplicants.php" . $redirectTab);
    exit();
}

$pageError = getFlash('error');
$pageSuccess = getFlash('success');
$activeTab = ($_GET['tab'] ?? '') === 'renewals' ? 'renewals' : 'new';

$applications = listApplications($committeeId, $track);
$archivedApplications = listArchivedApplications($committeeId, $track);

$settings = $conn->query("SELECT current_academic_year, current_semester FROM site_settings WHERE id = 1")->fetch_assoc();

// ---- Renewals tab: scholars whose term ended and are awaiting an Approve/Decline decision ----
$pendingScholars = $conn->query("SELECT s.*, u.first_name, u.last_name, u.email FROM scholars s JOIN users u ON u.user_id = s.user_id WHERE s.status = 'pending' ORDER BY u.last_name ASC")->fetch_all(MYSQLI_ASSOC);
$renewalDocFields = array_values(array_filter($fields, fn($f) => $f['input_type'] === 'file'));
$renewalFiles = [];
if (!empty($pendingScholars)) {
    $rfStmt = $conn->prepare("SELECT scholar_id, field_id, file_path, original_name, uploaded_at FROM scholar_requirement_files WHERE academic_year = ? AND semester = ?");
    $rfStmt->bind_param('ss', $settings['current_academic_year'], $settings['current_semester']);
    $rfStmt->execute();
    foreach ($rfStmt->get_result() as $rf) {
        $renewalFiles[(int)$rf['scholar_id']][(int)$rf['field_id']] = $rf;
    }
    $rfStmt->close();
}

// ---- filter / sort / search (GET, applied in PHP over the small result set) ----
$statusFilter = $_GET['status'] ?? '';
$sortBy = $_GET['sort'] ?? '';
$search = trim($_GET['q'] ?? '');

foreach ($applications as &$app) {
    $app['full_name'] = trim(answerByKey($app, $fields, 'last_name') . ' ' . answerByKey($app, $fields, 'first_name'));
    $app['school'] = answerByKey($app, $fields, 'school_university');
    $app['course'] = answerByKey($app, $fields, 'course');
    $app['year_level_text'] = answerByKey($app, $fields, 'year_level');
    $app['year_level_num'] = yearLevelToInt($app['year_level_text']) ?? 0;
}
unset($app);

if ($statusFilter !== '') {
    $applications = array_values(array_filter($applications, fn($a) => $a['status'] === $statusFilter));
}
if ($search !== '') {
    $needle = mb_strtolower($search);
    $applications = array_values(array_filter($applications, fn($a) => str_contains(mb_strtolower($a['full_name']), $needle) || str_contains(mb_strtolower($a['school']), $needle)));
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
    case 'year_asc':
        usort($applications, fn($a, $b) => $a['year_level_num'] <=> $b['year_level_num']);
        break;
    case 'year_desc':
        usort($applications, fn($a, $b) => $b['year_level_num'] <=> $a['year_level_num']);
        break;
}

// ---- Pagination (10 per page, in-memory over this small filtered/sorted result set) ----
$perPage = 10;
$totalRows = count($applications);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
$pagedApplications = array_slice($applications, ($page - 1) * $perPage, $perPage);

$activeLink = 'EducationApplicants';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Applicants</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo APP_BASE; ?>/assets/css/admin.css" rel="stylesheet">
</head>

<body>

    <?php include __DIR__ . '/../../includes/adminsidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <h4 class="fw-bold mb-1">ISkolar ng Langkiwa Applicants</h4>
        <p class="text-muted mb-2" style="font-size: 13px;">View Applicants for iSKolar ng Langkiwa Program.</p>

        <?php if ($pageSuccess): ?><div class="alert alert-success py-2"><?php echo e($pageSuccess); ?></div><?php endif; ?>
        <?php if ($pageError): ?><div class="alert alert-danger py-2"><?php echo e($pageError); ?></div><?php endif; ?>

        <!-- Term Indicators -->
        <div class="d-flex align-items-center gap-2 mb-4 flex-wrap">
            <span class="term-badge ay"><i class="bi bi-calendar3"></i> A.Y. <?php echo e($settings['current_academic_year']); ?></span>
            <span class="term-badge sem"><i class="bi bi-book"></i> <?php echo e($settings['current_semester']); ?></span>
        </div>

        <!-- Applicants / Renewals Tabs -->
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?php echo $activeTab === 'new' ? 'active' : ''; ?>" href="?tab=new"><i class="bi bi-person-plus me-1"></i> New Applicants</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $activeTab === 'renewals' ? 'active' : ''; ?>" href="?tab=renewals">
                    <i class="bi bi-arrow-repeat me-1"></i> Renewals
                    <?php if (!empty($pendingScholars)): ?><span class="badge bg-warning text-dark ms-1"><?php echo count($pendingScholars); ?></span><?php endif; ?>
                </a>
            </li>
        </ul>

        <?php if ($activeTab === 'new'): ?>
            <form method="get">
                <input type="hidden" name="tab" value="new">
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
                        <option value="year_asc" <?php echo $sortBy === 'year_asc' ? 'selected' : ''; ?>>Year Level: 1st → 4th</option>
                        <option value="year_desc" <?php echo $sortBy === 'year_desc' ? 'selected' : ''; ?>>Year Level: 4th → 1st</option>
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
                                <th>School</th>
                                <th>Course</th>
                                <th>Year Level</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pagedApplications)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">No applicants found.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($pagedApplications as $app): ?>
                                <tr>
                                    <td><?php echo str_pad($app['application_id'], 3, '0', STR_PAD_LEFT); ?></td>
                                    <td><?php echo e($app['full_name']); ?></td>
                                    <td><?php echo e($app['school']); ?></td>
                                    <td><?php echo e($app['course']); ?></td>
                                    <td><?php echo e($app['year_level_text']); ?></td>
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

            <?php renderPagination($page, $totalPages, $totalRows, $perPage); ?>
        <?php else: ?>
            <!-- Renewals Tab: scholars whose term ended, awaiting an Approve/Decline decision -->
            <p class="text-muted mb-3" style="font-size:13px;">Scholars whose term ended and are pending a renewal decision. They keep scholar-portal access to submit requirement documents for this term — Approve puts them back on the active Scholars list, Decline archives them permanently.</p>
            <div class="table-card">
                <div class="table-responsive-wrap">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>Scholar ID</th>
                                <th>Full Name</th>
                                <th>School</th>
                                <th>Finished</th>
                                <?php foreach ($renewalDocFields as $rf): ?>
                                    <th><?php echo e($rf['label']); ?></th>
                                <?php endforeach; ?>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pendingScholars)): ?>
                                <tr>
                                    <td colspan="<?php echo 5 + count($renewalDocFields); ?>" class="text-center text-muted py-4">No scholars pending renewal.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($pendingScholars as $ps): ?>
                                <tr>
                                    <td><?php echo str_pad($ps['scholar_id'], 3, '0', STR_PAD_LEFT); ?></td>
                                    <td><?php echo e($ps['first_name'] . ' ' . $ps['last_name']); ?></td>
                                    <td><?php echo e($ps['school']); ?></td>
                                    <td>
                                        <?php if (!empty($ps['finished_academic_year'])): ?>
                                            <span class="badge bg-light text-dark border" style="font-size:11px;font-weight:600;"><?php echo e($ps['finished_academic_year'] . ', ' . $ps['finished_semester']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php foreach ($renewalDocFields as $rf):
                                        $submitted = $renewalFiles[$ps['scholar_id']][$rf['field_id']] ?? null;
                                    ?>
                                        <td>
                                            <?php if ($submitted): ?>
                                                <a class="doc-item" href="<?php echo APP_BASE . '/' . e($submitted['file_path']); ?>" target="_blank" rel="noopener">
                                                    <i class="bi bi-file-earmark-image-fill"></i><?php echo e($submitted['original_name']); ?><i class="bi bi-check-circle-fill doc-check"></i>
                                                </a>
                                            <?php else: ?>
                                                <div class="doc-item" style="color:#a94442;"><i class="bi bi-exclamation-circle-fill"></i>Not submitted</div>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="text-center">
                                        <div class="d-flex gap-1 justify-content-center">
                                            <form method="post" onsubmit="return confirm('Approve renewal for <?php echo e(addslashes($ps['first_name'] . ' ' . $ps['last_name'])); ?>? They will go back on the active Scholars list.');">
                                                <input type="hidden" name="scholar_id" value="<?php echo $ps['scholar_id']; ?>">
                                                <button type="submit" name="approve_renewal" class="btn btn-sm btn-outline-success py-0 px-2" style="font-size:11px;"><i class="bi bi-check-lg"></i> Approve</button>
                                            </form>
                                            <form method="post" onsubmit="return confirm('Decline renewal for <?php echo e(addslashes($ps['first_name'] . ' ' . $ps['last_name'])); ?>? They will be archived and lose scholar-portal access.');">
                                                <input type="hidden" name="scholar_id" value="<?php echo $ps['scholar_id']; ?>">
                                                <button type="submit" name="decline_renewal" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:11px;"><i class="bi bi-x-lg"></i> Decline</button>
                                            </form>
                                        </div>
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
                        <h6 class="modal-title fw-bold"><i class="bi bi-person-plus-fill me-2"></i> Scholar Application Form</h6>
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

    <?php foreach ($pagedApplications as $app): ?>
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
                            <?php foreach ($fields as $field):
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
                            <?php foreach ($fields as $field):
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
                            <p class="text-muted" style="font-size:12px; margin-bottom:0;">This applicant will be approved and added to the Scholar list for A.Y. <?php echo e($settings['current_academic_year']); ?>, <?php echo e($settings['current_semester']); ?>.</p>
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
                                        <th>School</th>
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
                                    ?>
                                        <tr>
                                            <td><?php echo str_pad($arch['application_id'], 3, '0', STR_PAD_LEFT); ?></td>
                                            <td><?php echo e(trim(answerByKey($archApp, $fields, 'last_name') . ' ' . answerByKey($archApp, $fields, 'first_name'))); ?></td>
                                            <td><?php echo e(answerByKey($archApp, $fields, 'school_university')); ?></td>
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