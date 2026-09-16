<?php
require_once __DIR__ . '/../../config/scholars.php';
requireRole('admin');

$committeeId = getCommitteeIdByCode('education');
$fields = getFormFields($committeeId, 'scholarship');
$term = getCurrentTerm();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Set by the requirement-field handlers below so the redirect reopens the Manage
    // Requirement Types modal instead of dropping the admin back on the plain scholar list.
    $redirectHash = '';

    if (isset($_POST['edit_scholar'])) {
        $scholarId = (int)$_POST['scholar_id'];
        $school = trim($_POST['school'] ?? '');
        $course = trim($_POST['course'] ?? '');
        $yearLevel = (int)($_POST['year_level'] ?? 0);
        $stmt = $conn->prepare("UPDATE scholars SET school = ?, course = ?, year_level = ? WHERE scholar_id = ?");
        $stmt->bind_param('ssii', $school, $course, $yearLevel, $scholarId);
        $stmt->execute();
        $stmt->close();
        logAudit('Updated Scholar', 'Scholar #' . $scholarId);
        setFlash('success', 'Scholar updated.');
    }

    if (isset($_POST['set_eligibility'])) {
        $scholarId = (int)$_POST['scholar_id'];
        $eligibility = $_POST['eligibility'] === 'eligible' ? 'eligible' : 'not_eligible';
        ensureAllowanceRecord($scholarId);
        $stmt = $conn->prepare("UPDATE allowance_distributions SET eligibility = ?, eligibility_is_manual = 1 WHERE scholar_id = ? AND academic_year = ? AND semester = ?");
        $stmt->bind_param('siss', $eligibility, $scholarId, $term['current_academic_year'], $term['current_semester']);
        $stmt->execute();
        $stmt->close();
        logAudit($eligibility === 'eligible' ? 'Marked Scholar Eligible' : 'Marked Scholar Not Eligible', 'Scholar #' . $scholarId);
        setFlash('success', 'Scholar eligibility updated.');
    }

    if (isset($_POST['end_semester'])) {
        $next = getNextTerm($term['current_academic_year'], $term['current_semester']);
        $deadlineInput = trim($_POST['requirements_deadline'] ?? '');
        $deadline = ($deadlineInput !== '' && DateTime::createFromFormat('Y-m-d', $deadlineInput)) ? $deadlineInput : null;

        // Every currently active scholar "finishes" the ending term right here — moved to
        // 'pending' and flagged with the academic year/semester they just completed. They keep
        // scholar-portal access to submit renewal documents for the new term; nobody is
        // auto-approved or auto-declined. The admin reviews each one and decides Approve
        // (continue) or Decline (remove) from the Applicants page's Renewals tab.
        $stmt = $conn->prepare("UPDATE scholars SET status = 'pending', finished_academic_year = ?, finished_semester = ? WHERE status = 'active'");
        $stmt->bind_param('ss', $term['current_academic_year'], $term['current_semester']);
        $stmt->execute();
        $stmt->close();

        // The ending term's activities are done too — archive them the same way an admin would
        // archive one by hand. They're already tagged with this exact academic_year/semester from
        // when they were created, so this just closes them out in bulk: they drop off the
        // scholar's dashboard (which filters archived_at IS NULL) and move into the admin's
        // Activities archive view instead of lingering as if the term were still open.
        $stmt = $conn->prepare("UPDATE activities SET archived_at = NOW() WHERE committee_id = ? AND academic_year = ? AND semester = ? AND archived_at IS NULL");
        $stmt->bind_param('iss', $committeeId, $term['current_academic_year'], $term['current_semester']);
        $stmt->execute();
        $archivedActivityCount = $stmt->affected_rows;
        $stmt->close();

        $stmt = $conn->prepare("UPDATE site_settings SET current_academic_year = ?, current_semester = ?, requirements_deadline = ?, requirements_open = 1 WHERE id = 1");
        $stmt->bind_param('sss', $next['academic_year'], $next['semester'], $deadline);
        $stmt->execute();
        $stmt->close();

        $deadlineNote = $deadline ? (' Requirements deadline: ' . date('M j, Y', strtotime($deadline)) . '.') : '';
        logAudit('Ended Semester', $term['current_academic_year'] . ' ' . $term['current_semester'] . ' -> ' . $next['academic_year'] . ' ' . $next['semester'] . ' (' . $archivedActivityCount . ' activities archived)' . ($deadline ? ' (deadline ' . $deadline . ')' : ''));
        setFlash('success', 'Semester ended. Now in ' . $next['academic_year'] . ', ' . $next['semester'] . '. All scholars are now pending renewal — review them on the Applicants page\'s Renewals tab. ' . $archivedActivityCount . ' activities from the ended semester were archived.' . $deadlineNote);
    }

    if (isset($_POST['archive_scholar'])) {
        $scholarId = (int)$_POST['scholar_id'];
        $stmt = $conn->prepare("UPDATE scholars SET status = 'archived', finished_academic_year = ?, finished_semester = ? WHERE scholar_id = ?");
        $stmt->bind_param('ssi', $term['current_academic_year'], $term['current_semester'], $scholarId);
        $stmt->execute();
        $stmt->close();

        // An archived scholar is no longer an active beneficiary, so their account drops
        // back to a plain applicant (they keep applicant-side access, just lose the scholar
        // dashboard/nav until re-approved).
        $stmt = $conn->prepare("UPDATE users u JOIN scholars s ON s.user_id = u.user_id SET u.role = 'applicant' WHERE s.scholar_id = ?");
        $stmt->bind_param('i', $scholarId);
        $stmt->execute();
        $stmt->close();

        logAudit('Archived Scholar', 'Scholar #' . $scholarId);
        setFlash('success', 'Scholar archived.');
    }

    if (isset($_POST['restore_scholar'])) {
        $scholarId = (int)$_POST['scholar_id'];
        $stmt = $conn->prepare("UPDATE scholars SET status = 'active' WHERE scholar_id = ?");
        $stmt->bind_param('i', $scholarId);
        $stmt->execute();
        $stmt->close();

        // Reinstating an archived scholar restores their scholar role/access.
        $stmt = $conn->prepare("UPDATE users u JOIN scholars s ON s.user_id = u.user_id SET u.role = 'scholar' WHERE s.scholar_id = ?");
        $stmt->bind_param('i', $scholarId);
        $stmt->execute();
        $stmt->close();

        logAudit('Restored Scholar', 'Scholar #' . $scholarId);
        setFlash('success', 'Scholar restored.');
    }

    // -- Requirement document types (the file fields scholars submit each term for renewal) --
    // Scoped tightly to committee_id + program_track='scholarship' + input_type='file' in every
    // query below so this can only ever touch the requirement-file fields shown in that modal —
    // never the rest of the iSKolar application form (Last Name, School, etc.), which stays
    // exclusively managed inline in Configuration's Forms tab.
    if (isset($_POST['save_requirement_field'])) {
        $redirectHash = '#manageRequirementTypesModal';
        $label = trim($_POST['label'] ?? '');
        $required = isset($_POST['required']) ? 1 : 0;
        $fieldId = (int)($_POST['field_id'] ?? 0);

        if ($label === '') {
            setFlash('error', 'Requirement label is required.');
        } elseif ($fieldId > 0) {
            $stmt = $conn->prepare("UPDATE form_fields SET label = ?, is_required = ? WHERE field_id = ? AND committee_id = ? AND program_track = 'scholarship' AND input_type = 'file'");
            $stmt->bind_param('siii', $label, $required, $fieldId, $committeeId);
            $stmt->execute();
            $stmt->close();
            logAudit('Updated Requirement Field', $label);
            setFlash('success', 'Requirement updated.');
        } else {
            $base = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $label), '_')) ?: 'requirement';
            $key = $base;
            $i = 2;
            while (true) {
                $stmt = $conn->prepare("SELECT field_id FROM form_fields WHERE committee_id = ? AND program_track = 'scholarship' AND field_key = ?");
                $stmt->bind_param('is', $committeeId, $key);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$exists) break;
                $key = $base . '_' . $i;
                $i++;
            }
            $stmt = $conn->prepare("SELECT COALESCE(MAX(sort_order),0) AS m FROM form_fields WHERE committee_id = ? AND program_track = 'scholarship'");
            $stmt->bind_param('i', $committeeId);
            $stmt->execute();
            $sortOrder = (int)$stmt->get_result()->fetch_assoc()['m'] + 1;
            $stmt->close();

            $stmt = $conn->prepare("INSERT INTO form_fields (committee_id, program_track, label, field_key, input_type, icon, width, is_required, sort_order) VALUES (?, 'scholarship', ?, ?, 'file', 'bi-file-earmark-text', 'half', ?, ?)");
            $stmt->bind_param('issii', $committeeId, $label, $key, $required, $sortOrder);
            $stmt->execute();
            $stmt->close();
            logAudit('Added Requirement Field', $label);
            setFlash('success', 'Requirement added.');
        }
    }

    if (isset($_POST['archive_requirement_field'])) {
        $redirectHash = '#manageRequirementTypesModal';
        $fieldId = (int)$_POST['field_id'];
        $stmt = $conn->prepare("UPDATE form_fields SET archived_at = NOW() WHERE field_id = ? AND committee_id = ? AND program_track = 'scholarship' AND input_type = 'file'");
        $stmt->bind_param('ii', $fieldId, $committeeId);
        $stmt->execute();
        $stmt->close();
        logAudit('Removed Requirement Field', 'Field #' . $fieldId);
        setFlash('success', 'Requirement removed.');
    }

    if (isset($_POST['move_requirement_field'])) {
        $redirectHash = '#manageRequirementTypesModal';
        $fieldId = (int)$_POST['field_id'];
        $direction = $_POST['direction'] === 'up' ? 'up' : 'down';
        $reqFields = array_values(array_filter(getFormFields($committeeId, 'scholarship'), fn($f) => $f['input_type'] === 'file'));
        $index = null;
        foreach ($reqFields as $i => $f) {
            if ((int)$f['field_id'] === $fieldId) {
                $index = $i;
                break;
            }
        }
        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
        if ($index !== null && isset($reqFields[$swapWith])) {
            $a = $reqFields[$index];
            $b = $reqFields[$swapWith];
            $stmt = $conn->prepare("UPDATE form_fields SET sort_order = ? WHERE field_id = ?");
            $stmt->bind_param('ii', $b['sort_order'], $a['field_id']);
            $stmt->execute();
            $stmt->bind_param('ii', $a['sort_order'], $b['field_id']);
            $stmt->execute();
            $stmt->close();
        }
    }

    header("Location: EducationScholarList.php" . $redirectHash);
    exit();
}

$pageSuccess = getFlash('success');
$pageError = getFlash('error');

$scholars = $conn->query("SELECT s.*, u.first_name, u.last_name, u.user_id, u.email
    FROM scholars s JOIN users u ON u.user_id = s.user_id
    WHERE s.status = 'active' ORDER BY s.scholar_id ASC")->fetch_all(MYSQLI_ASSOC);

foreach ($scholars as &$sch) {
    $sch['allowance'] = ensureAllowanceRecord($sch['scholar_id']);
    $sch['answers'] = getApplicationAnswers($sch['application_id']);
    $sch['files'] = getApplicationFiles($sch['application_id']);
}
unset($sch);

// Purely permanent removals now — scholars.status='pending' (awaiting a renewal decision) is
// reviewed separately on the Applicants page's Renewals tab, not here.
$archivedScholars = $conn->query("SELECT s.*, u.first_name, u.last_name FROM scholars s JOIN users u ON u.user_id = s.user_id WHERE s.status = 'archived' ORDER BY u.last_name")->fetch_all(MYSQLI_ASSOC);

$nextTerm = getNextTerm($term['current_academic_year'], $term['current_semester']);
$deadlinePassed = !empty($term['requirements_deadline']) && strtotime($term['requirements_deadline']) < strtotime('today');
$windowOpen = isRequirementsWindowOpen($term);

// Requirement document types (the file fields scholars must submit each term) — managed here via
// "Manage Requirement Types"; the actual per-scholar submission review lives on the Applicants
// page's Renewals tab now.
$requirementDocFields = array_values(array_filter($fields, fn($f) => $f['input_type'] === 'file'));

$yearLevelLabel = fn($n) => $n ? $n . (['', 'st', 'nd', 'rd'][$n] ?? 'th') . ' Year' : '—';
$eligibilityBadge = fn($e) => $e === 'eligible' ? 'badge-eligible' : ($e === 'not_eligible' ? 'badge-not-eligible' : 'badge-pending-elig');
$eligibilityLabel = fn($e) => $e === 'eligible' ? 'Eligible' : ($e === 'not_eligible' ? 'Not Eligible' : 'Pending');

$activeLink = 'EducationScholarList';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scholars</title>
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
            background-color: #f8d7da;
            color: #842029;
            font-size: 12px;
            font-weight: 600;
            border-radius: 20px;
            padding: 4px 12px;
            border: 1px solid #f1aeb5;
        }

        .badge-pending-elig {
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

    <?php include __DIR__ . '/../../includes/adminsidebar.php'; ?>

    <div class="main-content">
        <h4 class="fw-bold mb-1">Scholars</h4>
        <p class="text-muted mb-2" style="font-size: 13px;">
            iSKolar ng Langkiwa active scholars for A.Y. <?php echo e($term['current_academic_year']); ?>, <?php echo e($term['current_semester']); ?>.
            <span class="badge <?php echo $windowOpen ? 'bg-success' : 'bg-secondary'; ?> ms-1">
                <i class="bi bi-<?php echo $windowOpen ? 'unlock-fill' : 'lock-fill'; ?> me-1"></i>Requirements submission <?php echo $windowOpen ? 'open' : 'closed'; ?>
            </span>
            <?php if (!empty($term['requirements_deadline'])): ?>
                <span class="badge <?php echo $deadlinePassed ? 'bg-danger' : 'bg-secondary'; ?> ms-1">
                    <i class="bi bi-clock-history me-1"></i>Requirements deadline: <?php echo date('M j, Y', strtotime($term['requirements_deadline'])); ?><?php echo $deadlinePassed ? ' (passed)' : ''; ?>
                </span>
            <?php endif; ?>
        </p>

        <?php if ($pageSuccess): ?><div class="alert alert-success py-2"><?php echo e($pageSuccess); ?></div><?php endif; ?>
        <?php if ($pageError): ?><div class="alert alert-danger py-2"><?php echo e($pageError); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#archivesModal">
                    <i class="bi bi-archive me-1"></i> Archives
                </button>
                <a class="btn btn-sm btn-outline-dark" href="<?php echo APP_BASE; ?>/admin/Education/EducationApplicants.php?tab=renewals">
                    <i class="bi bi-file-earmark-check me-1"></i> Renewals
                </a>
                <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#manageRequirementTypesModal">
                    <i class="bi bi-pencil-square me-1"></i> Manage Requirement Types
                </button>
                <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#endSemesterModal">
                    <i class="bi bi-calendar-check me-1"></i> End Semester
                </button>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <select class="form-select form-select-sm" id="scholarFilterSelect" style="width:auto;">
                    <option value="all">Filter: All Scholars</option>
                    <option value="elig:eligible">Status: Eligible</option>
                    <option value="elig:not_eligible">Status: Not Eligible</option>
                    <option value="elig:pending">Status: Pending</option>
                    <option value="year:1">Year Level: 1st Year</option>
                    <option value="year:2">Year Level: 2nd Year</option>
                    <option value="year:3">Year Level: 3rd Year</option>
                    <option value="year:4">Year Level: 4th Year</option>
                </select>
                <select class="form-select form-select-sm" id="scholarSortSelect" style="width:auto;">
                    <option value="id_asc">Sort By: ID (Ascending)</option>
                    <option value="id_desc">Sort By: ID (Descending)</option>
                    <option value="name_asc">Sort By: Name (A-Z)</option>
                    <option value="name_desc">Sort By: Name (Z-A)</option>
                    <option value="year_asc">Sort By: Year Level (Low-High)</option>
                    <option value="year_desc">Sort By: Year Level (High-Low)</option>
                </select>
                <div class="search-box position-relative">
                    <i class="bi bi-search position-absolute" style="left:10px; top:50%; transform:translateY(-50%); color:#999; font-size:12px;"></i>
                    <input type="text" class="form-control form-control-sm" id="scholarSearchInput" placeholder="Search scholar..." style="padding-left:28px;">
                </div>
            </div>
        </div>

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
                            <th>Activities</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="scholarsTableBody">
                        <?php if (empty($scholars)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">No scholars yet.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($scholars as $sch): ?>
                            <tr class="scholar-row" data-scholar-id="<?php echo (int)$sch['scholar_id']; ?>" data-name="<?php echo e(strtolower($sch['first_name'] . ' ' . $sch['last_name'])); ?>" data-year-level="<?php echo (int)$sch['year_level']; ?>" data-eligibility="<?php echo e($sch['allowance']['eligibility']); ?>">
                                <td><?php echo str_pad($sch['scholar_id'], 3, '0', STR_PAD_LEFT); ?></td>
                                <td><?php echo e($sch['first_name'] . ' ' . $sch['last_name']); ?></td>
                                <td><?php echo e($sch['school']); ?></td>
                                <td><?php echo e($sch['course']); ?></td>
                                <td><?php echo $yearLevelLabel($sch['year_level']); ?></td>
                                <td><span class="fw-semibold"><?php echo $sch['allowance']['activities_completed']; ?> / <?php echo $sch['allowance']['activities_required']; ?></span></td>
                                <td><span class="<?php echo $eligibilityBadge($sch['allowance']['eligibility']); ?>"><?php echo $eligibilityLabel($sch['allowance']['eligibility']); ?></span></td>
                                <td>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle py-1 px-2" style="font-size:12px;" data-bs-toggle="dropdown">Actions</button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3" style="font-size:13px; min-width:160px;">
                                            <li><a class="dropdown-item d-flex align-items-center gap-2 py-2" href="#" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $sch['scholar_id']; ?>"><i class="bi bi-eye text-primary"></i> View</a></li>
                                            <li><a class="dropdown-item d-flex align-items-center gap-2 py-2" href="#" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $sch['scholar_id']; ?>"><i class="bi bi-pencil text-warning"></i> Edit</a></li>
                                            <li>
                                                <hr class="dropdown-divider my-1">
                                            </li>
                                            <li>
                                                <form method="post" class="px-3 py-1">
                                                    <input type="hidden" name="scholar_id" value="<?php echo $sch['scholar_id']; ?>">
                                                    <input type="hidden" name="eligibility" value="eligible">
                                                    <button type="submit" name="set_eligibility" class="dropdown-item d-flex align-items-center gap-2 py-1 px-0 border-0 bg-transparent"><i class="bi bi-check-lg text-success"></i> Eligible</button>
                                                </form>
                                            </li>
                                            <li>
                                                <form method="post" class="px-3 py-1">
                                                    <input type="hidden" name="scholar_id" value="<?php echo $sch['scholar_id']; ?>">
                                                    <input type="hidden" name="eligibility" value="not_eligible">
                                                    <button type="submit" name="set_eligibility" class="dropdown-item d-flex align-items-center gap-2 py-1 px-0 border-0 bg-transparent"><i class="bi bi-x-lg text-danger"></i> Not Eligible</button>
                                                </form>
                                            </li>
                                            <li>
                                                <hr class="dropdown-divider my-1">
                                            </li>
                                            <li>
                                                <form method="post" class="px-3 py-1">
                                                    <input type="hidden" name="scholar_id" value="<?php echo $sch['scholar_id']; ?>">
                                                    <button type="submit" name="archive_scholar" class="dropdown-item d-flex align-items-center gap-2 py-1 px-0 border-0 bg-transparent text-danger"><i class="bi bi-archive"></i> Archive</button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php foreach ($scholars as $sch): ?>
        <!-- VIEW MODAL -->
        <div class="modal fade" id="viewModal<?php echo $sch['scholar_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold"><i class="bi bi-mortarboard-fill me-2"></i>Scholar Details</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="row g-3 mb-2">
                            <div class="col-3">
                                <div class="info-label">Scholar ID</div>
                                <div class="info-value"><?php echo str_pad($sch['scholar_id'], 3, '0', STR_PAD_LEFT); ?></div>
                            </div>
                            <div class="col-3">
                                <div class="info-label">Status</div>
                                <div class="info-value"><span class="<?php echo $eligibilityBadge($sch['allowance']['eligibility']); ?>"><?php echo $eligibilityLabel($sch['allowance']['eligibility']); ?></span></div>
                            </div>
                            <div class="col-3">
                                <div class="info-label">Activities</div>
                                <div class="info-value fw-bold text-success"><?php echo $sch['allowance']['activities_completed']; ?> / <?php echo $sch['allowance']['activities_required']; ?></div>
                            </div>
                            <div class="col-3">
                                <div class="info-label">Allowance</div>
                                <div class="info-value fw-bold text-success">₱<?php echo number_format($sch['allowance']['amount'], 2); ?></div>
                            </div>
                            <div class="col-12">
                                <div class="info-label">Full Name</div>
                                <div class="info-value"><?php echo e($sch['first_name'] . ' ' . $sch['last_name']); ?></div>
                            </div>
                            <div class="col-12">
                                <div class="info-label">School</div>
                                <div class="info-value"><?php echo e($sch['school']); ?></div>
                            </div>
                            <div class="col-6">
                                <div class="info-label">Course</div>
                                <div class="info-value"><?php echo e($sch['course']); ?></div>
                            </div>
                            <div class="col-6">
                                <div class="info-label">Year Level</div>
                                <div class="info-value"><?php echo $yearLevelLabel($sch['year_level']); ?></div>
                            </div>
                            <?php if (!empty($sch['finished_academic_year'])): ?>
                                <div class="col-12">
                                    <div class="info-label">Last Completed Term</div>
                                    <div class="info-value"><?php echo e($sch['finished_academic_year'] . ', ' . $sch['finished_semester']); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <hr>
                        <div class="section-divider"><i class="bi bi-paperclip me-1"></i> Application Documents</div>
                        <div class="row g-2">
                            <?php foreach ($fields as $field):
                                if ($field['input_type'] !== 'file') continue;
                                $file = $sch['files'][$field['field_id']] ?? null;
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
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- EDIT MODAL -->
        <div class="modal fade" id="editModal<?php echo $sch['scholar_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <form method="post">
                        <input type="hidden" name="scholar_id" value="<?php echo $sch['scholar_id']; ?>">
                        <div class="modal-header">
                            <h6 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Scholar</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4">
                            <div class="mb-3">
                                <label class="form-label" style="font-size:13px;font-weight:600;">School</label>
                                <input type="text" class="form-control form-control-sm" name="school" value="<?php echo e($sch['school']); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" style="font-size:13px;font-weight:600;">Course</label>
                                <input type="text" class="form-control form-control-sm" name="course" value="<?php echo e($sch['course']); ?>">
                            </div>
                            <div class="mb-0">
                                <label class="form-label" style="font-size:13px;font-weight:600;">Year Level</label>
                                <select class="form-select form-select-sm" name="year_level">
                                    <?php for ($y = 1; $y <= 4; $y++): ?>
                                        <option value="<?php echo $y; ?>" <?php echo (int)$sch['year_level'] === $y ? 'selected' : ''; ?>><?php echo $yearLevelLabel($y); ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer border-0">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="edit_scholar" class="btn btn-sm btn-success"><i class="bi bi-save me-1"></i> Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- ARCHIVES MODAL -->
    <div class="modal fade" id="archivesModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold"><i class="bi bi-archive-fill me-2"></i> Archived Scholars</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-muted mb-3" style="font-size:12px;">Permanently declined or manually removed scholars. Renewal decisions for scholars pending a new term are made from the <a href="<?php echo APP_BASE; ?>/admin/Education/EducationApplicants.php?tab=renewals">Applicants page's Renewals tab</a> instead.</p>
                    <div class="table-card">
                        <div class="table-responsive-wrap">
                            <table class="table mb-0" style="font-size:13px;">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Full Name</th>
                                        <th>School</th>
                                        <th>Finished</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($archivedScholars)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">No archived scholars.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($archivedScholars as $arch): ?>
                                        <tr>
                                            <td><?php echo str_pad($arch['scholar_id'], 3, '0', STR_PAD_LEFT); ?></td>
                                            <td><?php echo e($arch['first_name'] . ' ' . $arch['last_name']); ?></td>
                                            <td><?php echo e($arch['school']); ?></td>
                                            <td>
                                                <?php if (!empty($arch['finished_academic_year'])): ?>
                                                    <span class="badge bg-light text-dark border" style="font-size:11px;font-weight:600;"><?php echo e($arch['finished_academic_year'] . ', ' . $arch['finished_semester']); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <form method="post">
                                                    <input type="hidden" name="scholar_id" value="<?php echo $arch['scholar_id']; ?>">
                                                    <button type="submit" name="restore_scholar" class="btn btn-sm btn-outline-success py-0 px-2" style="font-size:11px;"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
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

    <!-- MANAGE REQUIREMENT TYPES MODAL -->
    <div class="modal fade" id="manageRequirementTypesModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i> Manage Requirement Types</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <p class="text-muted mb-0" style="font-size:12px;">These are the requirement document types scholars must submit each term.</p>
                        <button type="button" class="btn btn-sm btn-outline-success" onclick="openAddRequirementField()" data-bs-toggle="modal" data-bs-target="#requirementFieldModal">
                            <i class="bi bi-plus-lg me-1"></i> Add Requirement
                        </button>
                    </div>

                    <?php if (empty($requirementDocFields)): ?>
                        <p class="text-muted text-center mb-0">No requirement document fields are configured for this track yet — use "Add Requirement" above.</p>
                    <?php else: ?>
                        <div class="table-card">
                            <div class="table-responsive-wrap">
                                <table class="table mb-0" style="font-size:12px;">
                                    <thead>
                                        <tr>
                                            <th>Requirement</th>
                                            <th>Required</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($requirementDocFields as $i => $rf): ?>
                                            <tr>
                                                <td><?php echo e($rf['label']); ?></td>
                                                <td><?php echo $rf['is_required'] ? 'Required' : 'Optional'; ?></td>
                                                <td class="text-end">
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="field_id" value="<?php echo $rf['field_id']; ?>">
                                                        <input type="hidden" name="direction" value="up">
                                                        <button type="submit" name="move_requirement_field" class="btn btn-sm btn-light py-0 px-1" <?php echo $i === 0 ? 'disabled' : ''; ?>><i class="bi bi-arrow-up"></i></button>
                                                    </form>
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="field_id" value="<?php echo $rf['field_id']; ?>">
                                                        <input type="hidden" name="direction" value="down">
                                                        <button type="submit" name="move_requirement_field" class="btn btn-sm btn-light py-0 px-1" <?php echo $i === count($requirementDocFields) - 1 ? 'disabled' : ''; ?>><i class="bi bi-arrow-down"></i></button>
                                                    </form>
                                                    <button type="button" class="btn btn-sm btn-light py-0 px-1" title="Edit" onclick='openEditRequirementField(<?php echo json_encode($rf); ?>)' data-bs-toggle="modal" data-bs-target="#requirementFieldModal"><i class="bi bi-pencil"></i></button>
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Remove this requirement? Already-submitted files stay on file, but it will no longer show on this form.');">
                                                        <input type="hidden" name="field_id" value="<?php echo $rf['field_id']; ?>">
                                                        <button type="submit" name="archive_requirement_field" class="btn btn-sm btn-light py-0 px-1 text-danger" title="Remove"><i class="bi bi-trash"></i></button>
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
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ADD/EDIT REQUIREMENT FIELD MODAL -->
    <div class="modal fade" id="requirementFieldModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <form method="post">
                    <input type="hidden" name="field_id" id="editingReqFieldId" value="">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold" id="reqFieldModalLabel"><i class="bi bi-plus-circle me-2"></i>Add Requirement</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label" style="font-size:13px;font-weight:600;">Requirement Label</label>
                            <input type="text" class="form-control form-control-sm" name="label" id="reqFieldLabelInput" required placeholder="e.g. Certificate of Registration">
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="required" id="reqFieldRequiredCheck" checked>
                            <label class="form-check-label" for="reqFieldRequiredCheck" style="font-size:13px;">Required</label>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="save_requirement_field" class="btn btn-sm btn-success"><i class="bi bi-save me-1"></i> Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- END SEMESTER MODAL -->
    <div class="modal fade" id="endSemesterModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold"><i class="bi bi-calendar-check me-2"></i> End Semester</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" onsubmit="return confirm('End the current semester and move to <?php echo e(addslashes($nextTerm['academic_year'] . ' ' . $nextTerm['semester'])); ?>?');">
                    <div class="modal-body p-4">
                        <div class="d-flex align-items-center justify-content-center gap-3 mb-3" style="font-size:14px;">
                            <span class="fw-semibold text-muted"><?php echo e($term['current_academic_year']); ?><br><?php echo e($term['current_semester']); ?></span>
                            <i class="bi bi-arrow-right fs-4 text-muted"></i>
                            <span class="fw-bold text-success"><?php echo e($nextTerm['academic_year']); ?><br><?php echo e($nextTerm['semester']); ?></span>
                        </div>
                        <ul class="mb-3" style="font-size:13px;">
                            <li>All <strong><?php echo count($scholars); ?></strong> currently active scholar(s) will be marked <strong>Pending</strong> and flagged as having finished <?php echo e($term['current_academic_year'] . ', ' . $term['current_semester']); ?> — the Scholars list will start empty for the new term.</li>
                            <li>They keep scholar-portal access to submit renewal documents. Review each one and decide Approve or Decline from the Applicants page's <strong>Renewals</strong> tab.</li>
                        </ul>
                        <div class="mb-2">
                            <label class="form-label fw-semibold" style="font-size:13px;">Requirements Deadline for <?php echo e($nextTerm['academic_year'] . ', ' . $nextTerm['semester']); ?></label>
                            <input type="date" class="form-control form-control-sm" name="requirements_deadline" value="<?php echo e(date('Y-m-d', strtotime('+30 days'))); ?>" min="<?php echo e(date('Y-m-d')); ?>">
                            <div class="form-text" style="font-size:11px;">Scholars must submit their updated requirements by this date. Leave blank for no deadline — it can be set or changed later in Site Settings.</div>
                        </div>
                        <p class="text-muted mb-0" style="font-size:12px;">This advances the site's current academic year/semester and opens the requirements submission window for scholars (closed while a semester is ongoing). Existing attendance, allowance, and requirement records stay tied to the term that just ended.</p>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="end_semester" class="btn btn-sm btn-danger"><i class="bi bi-calendar-check me-1"></i> End Semester &amp; Continue</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Row "Actions" dropdowns sit inside a horizontally-scrolling table wrapper. Per the CSS
        // overflow spec, setting overflow-x:auto forces the paired overflow-y to compute as auto
        // too (even though it's declared "visible"), so the browser clips any menu that opens past
        // the wrapper's bottom edge — cutting off items like "Eligible" on rows near the table's
        // end. Positioning the menu with Popper's "fixed" strategy renders it relative to the
        // viewport instead, sidestepping that clipping entirely.
        document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function(toggle) {
            new bootstrap.Dropdown(toggle, {
                popperConfig: function(defaultConfig) {
                    return Object.assign({}, defaultConfig, {
                        strategy: 'fixed'
                    });
                }
            });
        });

        // Client-side search / filter / sort (Scholars table)
        const scholarSearchInput = document.getElementById('scholarSearchInput');
        const scholarFilterSelect = document.getElementById('scholarFilterSelect');
        const scholarSortSelect = document.getElementById('scholarSortSelect');
        const scholarsTableBody = document.getElementById('scholarsTableBody');

        function applyScholarFilters() {
            if (!scholarsTableBody) return;
            const q = scholarSearchInput ? scholarSearchInput.value.trim().toLowerCase() : '';
            const filterVal = scholarFilterSelect ? scholarFilterSelect.value : 'all';
            const sortVal = scholarSortSelect ? scholarSortSelect.value : 'id_asc';
            const [filterKey, filterArg] = filterVal.includes(':') ? filterVal.split(':') : [null, null];

            const rows = Array.from(scholarsTableBody.querySelectorAll('.scholar-row'));
            if (!rows.length) return;

            rows.forEach(function(row) {
                const matchesSearch = !q || (row.dataset.name || '').includes(q);
                const matchesFilter = !filterKey ||
                    (filterKey === 'elig' && (row.dataset.eligibility || '') === filterArg) ||
                    (filterKey === 'year' && (row.dataset.yearLevel || '') === filterArg);
                row.style.display = (matchesSearch && matchesFilter) ? '' : 'none';
            });

            rows.sort(function(a, b) {
                switch (sortVal) {
                    case 'id_desc':
                        return (+b.dataset.scholarId) - (+a.dataset.scholarId);
                    case 'name_asc':
                        return (a.dataset.name || '').localeCompare(b.dataset.name || '');
                    case 'name_desc':
                        return (b.dataset.name || '').localeCompare(a.dataset.name || '');
                    case 'year_asc':
                        return (+a.dataset.yearLevel) - (+b.dataset.yearLevel);
                    case 'year_desc':
                        return (+b.dataset.yearLevel) - (+a.dataset.yearLevel);
                    case 'id_asc':
                    default:
                        return (+a.dataset.scholarId) - (+b.dataset.scholarId);
                }
            });
            rows.forEach(row => scholarsTableBody.appendChild(row));
        }

        if (scholarSearchInput) scholarSearchInput.addEventListener('input', applyScholarFilters);
        if (scholarFilterSelect) scholarFilterSelect.addEventListener('change', applyScholarFilters);
        if (scholarSortSelect) scholarSortSelect.addEventListener('change', applyScholarFilters);
        applyScholarFilters();

        // Requirement field add/edit modal
        function openAddRequirementField() {
            document.getElementById('editingReqFieldId').value = '';
            document.getElementById('reqFieldLabelInput').value = '';
            document.getElementById('reqFieldRequiredCheck').checked = true;
            document.getElementById('reqFieldModalLabel').innerHTML = '<i class="bi bi-plus-circle me-2"></i>Add Requirement';
        }

        function openEditRequirementField(f) {
            document.getElementById('editingReqFieldId').value = f.field_id;
            document.getElementById('reqFieldLabelInput').value = f.label;
            document.getElementById('reqFieldRequiredCheck').checked = !!Number(f.is_required);
            document.getElementById('reqFieldModalLabel').innerHTML = '<i class="bi bi-pencil me-2"></i>Edit Requirement';
        }

        // Requirement-field actions redirect back here with #manageRequirementTypesModal so the
        // admin lands back inside the modal they were working in, instead of the plain scholar list.
        if (window.location.hash === '#manageRequirementTypesModal') {
            const manageModalEl = document.getElementById('manageRequirementTypesModal');
            if (manageModalEl) new bootstrap.Modal(manageModalEl).show();
        }
    </script>
</body>

</html>