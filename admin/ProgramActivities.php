<?php
// Activities + QR attendance for any program that has Activities switched on (see
// config/program_activities.php). Mirrors the iSKolar Scholars > Activities page, but participants
// are the program's approved beneficiaries instead of scholars.
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
$programName = $tab['label'];
$selfUrl = 'ProgramActivities.php?' . (!empty($tab['program_id']) ? 'ptab=' : 'tab=') . $tabId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isViewOnlyOfficer() && !isset($_POST['save_attendance'])) {
        setFlash('error', 'Your role can only scan and record attendance on this page.');
        header('Location: ' . $selfUrl);
        exit();
    }

    if (isset($_POST['add_activity'])) {
        $title = trim($_POST['title'] ?? '');
        $date = $_POST['activity_date'] ?? '';
        $time = ($_POST['activity_time'] ?? '') ?: null;
        $venue = trim($_POST['venue'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $audience = ($_POST['audience'] ?? 'all') === 'selected' ? 'selected' : 'all';

        if ($title === '' || $date === '') {
            setFlash('error', 'Activity title and date are required.');
        } else {
            $stmt = $conn->prepare("INSERT INTO program_activities (tab_id, title, description, activity_date, activity_time, venue, audience, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('issssssi', $tabId, $title, $description, $date, $time, $venue, $audience, $me['user_id']);
            $stmt->execute();
            $activityId = $stmt->insert_id;
            $stmt->close();

            $participantIds = array_map('intval', array_column(programTabParticipants($tab), 'user_id'));
            if ($audience === 'selected') {
                $participantIds = array_values(array_intersect($participantIds, array_map('intval', $_POST['user_ids'] ?? [])));
            }

            $stmt = $conn->prepare("INSERT INTO program_attendance (activity_id, user_id, status, qr_token) VALUES (?, ?, 'pending', ?)");
            foreach ($participantIds as $uid) {
                $token = generateQrToken();
                $stmt->bind_param('iis', $activityId, $uid, $token);
                $stmt->execute();
            }
            $stmt->close();

            logAudit('Added Program Activity', $programName . ': ' . $title);
            setFlash('success', 'Activity added.');
        }
    }

    if (isset($_POST['edit_activity'])) {
        $activityId = (int)$_POST['activity_id'];
        $title = trim($_POST['title'] ?? '');
        $date = $_POST['activity_date'] ?? '';
        $time = ($_POST['activity_time'] ?? '') ?: null;
        $venue = trim($_POST['venue'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($title === '' || $date === '') {
            setFlash('error', 'Activity title and date are required.');
        } else {
            $stmt = $conn->prepare("UPDATE program_activities SET title = ?, activity_date = ?, activity_time = ?, venue = ?, description = ? WHERE activity_id = ? AND tab_id = ?");
            $stmt->bind_param('sssssii', $title, $date, $time, $venue, $description, $activityId, $tabId);
            $stmt->execute();
            $stmt->close();
            logAudit('Updated Program Activity', $programName . ': ' . $title);
            setFlash('success', 'Activity updated.');
        }
    }

    if (isset($_POST['archive_activity']) || isset($_POST['restore_activity'])) {
        $activityId = (int)$_POST['activity_id'];
        $archive = isset($_POST['archive_activity']);
        $sql = $archive
            ? "UPDATE program_activities SET archived_at = NOW() WHERE activity_id = ? AND tab_id = ?"
            : "UPDATE program_activities SET archived_at = NULL WHERE activity_id = ? AND tab_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $activityId, $tabId);
        $stmt->execute();
        $stmt->close();
        logAudit($archive ? 'Archived Program Activity' : 'Restored Program Activity', $programName . ' - activity #' . $activityId);
        setFlash('success', $archive ? 'Activity archived.' : 'Activity restored.');
    }

    if (isset($_POST['save_attendance'])) {
        $activityId = (int)$_POST['activity_id'];
        $owns = $conn->prepare("SELECT 1 FROM program_activities WHERE activity_id = ? AND tab_id = ?");
        $owns->bind_param('ii', $activityId, $tabId);
        $owns->execute();
        $valid = (bool)$owns->get_result()->fetch_row();
        $owns->close();

        if ($valid) {
            foreach (($_POST['attendance'] ?? []) as $userId => $status) {
                $userId = (int)$userId;
                $status = in_array($status, ['present', 'absent', 'pending'], true) ? $status : 'pending';
                if ($status === 'present') {
                    $stmt = $conn->prepare("UPDATE program_attendance SET status = 'present', scanned_at = COALESCE(scanned_at, NOW()) WHERE activity_id = ? AND user_id = ?");
                    $stmt->bind_param('ii', $activityId, $userId);
                } else {
                    $stmt = $conn->prepare("UPDATE program_attendance SET status = ?, scanned_at = NULL WHERE activity_id = ? AND user_id = ?");
                    $stmt->bind_param('sii', $status, $activityId, $userId);
                }
                $stmt->execute();
                $stmt->close();
            }
            logAudit('Saved Program Attendance', $programName . ' - activity #' . $activityId);
            setFlash('success', 'Attendance saved.');
        }
    }

    header('Location: ' . $selfUrl);
    exit();
}

$pageError = getFlash('error');
$pageSuccess = getFlash('success');

$stmt = $conn->prepare("SELECT a.*,
        (SELECT COUNT(*) FROM program_attendance WHERE activity_id = a.activity_id) AS total_assigned,
        (SELECT COUNT(*) FROM program_attendance WHERE activity_id = a.activity_id AND status = 'present') AS total_present
    FROM program_activities a
    WHERE a.tab_id = ? AND a.archived_at IS NULL
    ORDER BY a.activity_id ASC");
$stmt->bind_param('i', $tabId);
$stmt->execute();
$activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("SELECT * FROM program_activities WHERE tab_id = ? AND archived_at IS NOT NULL ORDER BY archived_at DESC");
$stmt->bind_param('i', $tabId);
$stmt->execute();
$archivedActivities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$participants = programTabParticipants($tab);

function getProgramAttendanceRows($conn, $activityId)
{
    $stmt = $conn->prepare("SELECT att.user_id, att.status, u.first_name, u.last_name
        FROM program_attendance att JOIN users u ON u.user_id = att.user_id
        WHERE att.activity_id = ? ORDER BY u.last_name, u.first_name");
    $stmt->bind_param('i', $activityId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

$activeLink = programActivitiesActiveName($committeeId);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activities - <?php echo e($programName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo APP_BASE; ?>/assets/css/admin.css" rel="stylesheet">
    <style>
        .btn-qr-scan {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background-color: #2e7d32;
            color: #fff;
            border: none;
            font-size: 13px;
            font-weight: 600;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
        }

        #qrScanVideo {
            width: 100%;
            max-width: 360px;
            border-radius: 8px;
            border: 2px solid #45b84d;
        }

        .btn-attendance {
            background-color: #e8f5e9;
            color: #2e7d32;
            border: none;
            font-size: 12px;
            padding: 4px 10px;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
        }
    </style>
</head>

<body>

    <?php include __DIR__ . '/../includes/adminsidebar.php'; ?>

    <div class="main-content">
        <h4 class="fw-bold mb-1">Activities</h4>
        <p class="text-muted mb-4" style="font-size: 13px;">Add, view and monitor activity attendance for <?php echo e($programName); ?> beneficiaries.</p>

        <?php if ($pageSuccess): ?><div class="alert alert-success py-2"><?php echo e($pageSuccess); ?></div><?php endif; ?>
        <?php if ($pageError): ?><div class="alert alert-danger py-2"><?php echo e($pageError); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <?php if (!isViewOnlyOfficer()): ?>
                    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addActivityModal">
                        <i class="bi bi-plus-lg me-1"></i> Add Activity
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#archivesModal">
                        <i class="bi bi-archive me-1"></i> Archives
                    </button>
                <?php endif; ?>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <select class="form-select form-select-sm" id="activityFilterSelect" style="width:auto;">
                    <option value="all">Filter: All Activities</option>
                    <option value="audience:all">Audience: All Beneficiaries</option>
                    <option value="audience:selected">Audience: Selected Beneficiaries</option>
                </select>
                <select class="form-select form-select-sm" id="activitySortSelect" style="width:auto;">
                    <option value="id_asc">Sort By: ID (Ascending)</option>
                    <option value="id_desc">Sort By: ID (Descending)</option>
                    <option value="title_asc">Sort By: Title (A-Z)</option>
                    <option value="title_desc">Sort By: Title (Z-A)</option>
                    <option value="date_desc">Sort By: Date (Newest)</option>
                    <option value="date_asc">Sort By: Date (Oldest)</option>
                </select>
                <div class="search-box position-relative">
                    <i class="bi bi-search position-absolute" style="left:10px; top:50%; transform:translateY(-50%); color:#999; font-size:12px;"></i>
                    <input type="text" class="form-control form-control-sm" id="activitySearchInput" placeholder="Search activity..." style="padding-left:28px;">
                </div>
            </div>
        </div>

        <div class="table-card">
            <div class="table-responsive-wrap">
                <table class="table table-stack mb-0">
                    <thead>
                        <tr>
                            <th>Activity ID</th>
                            <th>Title</th>
                            <th>Date</th>
                            <th>Venue</th>
                            <th>Attendance</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="activitiesTableBody">
                        <?php if (empty($activities)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No activities yet.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($activities as $act): ?>
                            <tr class="activity-row" data-activity-id="<?php echo (int)$act['activity_id']; ?>" data-title="<?php echo e(strtolower($act['title'])); ?>" data-date="<?php echo strtotime($act['activity_date']); ?>" data-audience="<?php echo e($act['audience']); ?>">
                                <td data-label="Activity ID"><?php echo str_pad($act['activity_id'], 3, '0', STR_PAD_LEFT); ?></td>
                                <td data-label="Title"><?php echo e($act['title']); ?></td>
                                <td data-label="Date"><?php echo date('F j, Y', strtotime($act['activity_date'])); ?></td>
                                <td data-label="Venue"><?php echo e($act['venue']); ?></td>
                                <td data-label="Attendance"><span class="text-success fw-semibold"><?php echo (int)$act['total_present']; ?></span> / <span class="text-muted"><?php echo (int)$act['total_assigned']; ?></span> <span class="text-muted" style="font-size:11px;">present</span></td>
                                <td data-label="Action" class="d-flex gap-1 flex-wrap">
                                    <button class="btn-attendance" data-bs-toggle="modal" data-bs-target="#attendanceModal<?php echo $act['activity_id']; ?>"><i class="bi bi-person-check"></i> Attendance</button>
                                    <?php if (!isViewOnlyOfficer()): ?>
                                        <button class="btn-edit" data-bs-toggle="modal" data-bs-target="#editActivityModal<?php echo $act['activity_id']; ?>"><i class="bi bi-pencil"></i> Edit</button>
                                        <button class="btn-archive" data-bs-toggle="modal" data-bs-target="#archiveActivityModal<?php echo $act['activity_id']; ?>"><i class="bi bi-archive"></i> Archive</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ADD ACTIVITY MODAL -->
    <div class="modal fade" id="addActivityModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <form method="post">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold"><i class="bi bi-plus-circle-fill me-2"></i>Add Activity</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label" style="font-size:13px; font-weight:600;">Activity Title</label>
                            <input type="text" class="form-control form-control-sm" name="title" maxlength="150" placeholder="e.g. Orientation" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Date</label>
                                <input type="date" class="form-control form-control-sm" name="activity_date" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Time</label>
                                <input type="time" class="form-control form-control-sm" name="activity_time">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="font-size:13px; font-weight:600;">Venue</label>
                            <input type="text" class="form-control form-control-sm" name="venue" maxlength="150" placeholder="e.g. Barangay Langkiwa Covered Court">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="font-size:13px; font-weight:600;">Description <span class="text-muted fw-normal">(optional)</span></label>
                            <textarea class="form-control form-control-sm" name="description" rows="3" placeholder="Brief description of the activity..."></textarea>
                        </div>
                        <div class="mb-0">
                            <label class="form-label" style="font-size:13px; font-weight:600;">Required Beneficiaries</label>
                            <select class="form-select form-select-sm" name="audience" onchange="document.getElementById('participantPicker').style.display = this.value === 'selected' ? 'block' : 'none';">
                                <option value="all" selected>All Beneficiaries (<?php echo count($participants); ?>)</option>
                                <option value="selected">Selected Beneficiaries Only</option>
                            </select>
                            <div class="mt-1" style="font-size:11px; color:#888;"><i class="bi bi-info-circle me-1"></i>Only approved beneficiaries of this program can be assigned.</div>
                            <div id="participantPicker" style="display:none; max-height:150px; overflow-y:auto;" class="mt-2 border rounded p-2">
                                <?php if (empty($participants)): ?>
                                    <div class="text-muted" style="font-size:12px;">No approved beneficiaries yet.</div>
                                <?php endif; ?>
                                <?php foreach ($participants as $p): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="user_ids[]" value="<?php echo (int)$p['user_id']; ?>" id="pu<?php echo (int)$p['user_id']; ?>">
                                        <label class="form-check-label" for="pu<?php echo (int)$p['user_id']; ?>" style="font-size:12px;"><?php echo e($p['first_name'] . ' ' . $p['last_name']); ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_activity" class="btn btn-sm btn-success"><i class="bi bi-plus-lg me-1"></i> Add Activity</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php foreach ($activities as $act): $rows = getProgramAttendanceRows($conn, $act['activity_id']); ?>
        <!-- ATTENDANCE MODAL -->
        <div class="modal fade" id="attendanceModal<?php echo $act['activity_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content border-0 shadow">
                    <form method="post">
                        <input type="hidden" name="activity_id" value="<?php echo $act['activity_id']; ?>">
                        <div class="modal-header">
                            <h6 class="modal-title fw-bold"><i class="bi bi-person-check-fill me-2"></i> Beneficiary Attendance — <?php echo e($act['title']); ?></h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                <div class="d-flex gap-4 flex-wrap mb-0" style="background-color:#e8f5e9;border-radius:8px;padding:12px 16px;">
                                    <div>
                                        <div style="font-size:11px; color:#666; font-weight:700; text-transform:uppercase;">Total Beneficiaries</div>
                                        <div style="font-size:13px; font-weight:600;"><?php echo count($rows); ?></div>
                                    </div>
                                    <div>
                                        <div style="font-size:11px; color:#666; font-weight:700; text-transform:uppercase;">Present</div>
                                        <div style="font-size:13px; font-weight:600; color:#2e7d32;"><?php echo count(array_filter($rows, fn($r) => $r['status'] === 'present')); ?></div>
                                    </div>
                                </div>
                                <button type="button" class="btn-qr-scan" onclick="openScanner(<?php echo $act['activity_id']; ?>)">
                                    <i class="bi bi-qr-code-scan"></i> Scan QR
                                </button>
                            </div>
                            <div class="d-flex justify-content-end mb-2">
                                <select class="form-select form-select-sm" style="width:auto;" onchange="filterAttendanceRows(<?php echo $act['activity_id']; ?>, this.value)">
                                    <option value="all">Filter: All</option>
                                    <option value="present">Present</option>
                                    <option value="absent">Absent</option>
                                    <option value="pending">Pending</option>
                                </select>
                            </div>
                            <div class="table-card">
                                <div class="table-responsive-wrap">
                                    <table class="table mb-0" style="font-size:13px; min-width:0;">
                                        <thead>
                                            <tr>
                                                <th>Beneficiary</th>
                                                <th>Attendance</th>
                                            </tr>
                                        </thead>
                                        <tbody id="attendanceTableBody<?php echo $act['activity_id']; ?>">
                                            <?php if (empty($rows)): ?>
                                                <tr>
                                                    <td colspan="2" class="text-center text-muted">No beneficiaries assigned.</td>
                                                </tr>
                                            <?php endif; ?>
                                            <?php foreach ($rows as $r): ?>
                                                <tr class="attendance-row">
                                                    <td><?php echo e($r['first_name'] . ' ' . $r['last_name']); ?></td>
                                                    <td>
                                                        <select class="form-select form-select-sm" name="attendance[<?php echo (int)$r['user_id']; ?>]" style="width:auto; display:inline-block;">
                                                            <option value="pending" <?php echo $r['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                            <option value="present" <?php echo $r['status'] === 'present' ? 'selected' : ''; ?>>Present</option>
                                                            <option value="absent" <?php echo $r['status'] === 'absent' ? 'selected' : ''; ?>>Absent</option>
                                                        </select>
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
                            <button type="submit" name="save_attendance" class="btn btn-sm btn-success"><i class="bi bi-save me-1"></i> Save Attendance</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php if (!isViewOnlyOfficer()): ?>
            <!-- EDIT ACTIVITY MODAL -->
            <div class="modal fade" id="editActivityModal<?php echo $act['activity_id']; ?>" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content border-0 shadow">
                        <form method="post">
                            <input type="hidden" name="activity_id" value="<?php echo $act['activity_id']; ?>">
                            <div class="modal-header">
                                <h6 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Activity</h6>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body p-4">
                                <div class="mb-3">
                                    <label class="form-label" style="font-size:13px; font-weight:600;">Activity Title</label>
                                    <input type="text" class="form-control form-control-sm" name="title" maxlength="150" value="<?php echo e($act['title']); ?>" required>
                                </div>
                                <div class="row g-2 mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label" style="font-size:13px; font-weight:600;">Date</label>
                                        <input type="date" class="form-control form-control-sm" name="activity_date" value="<?php echo e($act['activity_date']); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" style="font-size:13px; font-weight:600;">Time</label>
                                        <input type="time" class="form-control form-control-sm" name="activity_time" value="<?php echo e($act['activity_time']); ?>">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" style="font-size:13px; font-weight:600;">Venue</label>
                                    <input type="text" class="form-control form-control-sm" name="venue" maxlength="150" value="<?php echo e($act['venue']); ?>">
                                </div>
                                <div class="mb-0">
                                    <label class="form-label" style="font-size:13px; font-weight:600;">Description</label>
                                    <textarea class="form-control form-control-sm" name="description" rows="3"><?php echo e($act['description']); ?></textarea>
                                </div>
                            </div>
                            <div class="modal-footer border-0">
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" name="edit_activity" class="btn btn-sm btn-success"><i class="bi bi-save me-1"></i> Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- ARCHIVE ACTIVITY MODAL -->
            <div class="modal fade" id="archiveActivityModal<?php echo $act['activity_id']; ?>" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered modal-sm">
                    <div class="modal-content border-0 shadow">
                        <form method="post">
                            <input type="hidden" name="activity_id" value="<?php echo $act['activity_id']; ?>">
                            <div class="modal-header">
                                <h6 class="modal-title fw-bold"><i class="bi bi-archive me-2"></i>Archive Activity</h6>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body p-4 text-center">
                                <div class="archive-icon-wrap"><i class="bi bi-archive-fill"></i></div>
                                <p class="fw-bold mb-1" style="font-size:14px;">Are you sure?</p>
                                <p class="text-muted" style="font-size:12px; margin-bottom:0;">You are about to archive this activity. It will be removed from the list but can be restored later from the archive.</p>
                            </div>
                            <div class="modal-footer border-0 justify-content-center gap-2">
                                <button type="button" class="btn btn-sm btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" name="archive_activity" class="btn btn-sm btn-danger px-4"><i class="bi bi-archive me-1"></i>Yes, Archive</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <!-- ARCHIVES MODAL -->
    <div class="modal fade" id="archivesModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold"><i class="bi bi-archive-fill me-2"></i> Archived Activities</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="table-card">
                        <div class="table-responsive-wrap">
                            <table class="table mb-0" style="font-size:13px;">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Date</th>
                                        <th>Venue</th>
                                        <th>Archived On</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($archivedActivities)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">No archived activities.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($archivedActivities as $arch): ?>
                                        <tr>
                                            <td><?php echo e($arch['title']); ?></td>
                                            <td><?php echo date('F j, Y', strtotime($arch['activity_date'])); ?></td>
                                            <td><?php echo e($arch['venue']); ?></td>
                                            <td><?php echo date('Y-m-d H:i', strtotime($arch['archived_at'])); ?></td>
                                            <td class="text-center">
                                                <form method="post">
                                                    <input type="hidden" name="activity_id" value="<?php echo $arch['activity_id']; ?>">
                                                    <button type="submit" name="restore_activity" class="btn btn-sm btn-outline-success py-0 px-2" style="font-size:11px;"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
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

    <!-- QR SCANNER MODAL -->
    <div class="modal fade" id="qrScanModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold"><i class="bi bi-qr-code-scan me-2"></i>Scan Beneficiary QR Code</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="stopScanner()"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <video id="qrScanVideo" autoplay muted playsinline></video>
                    <canvas id="qrScanCanvas" style="display:none;"></canvas>
                    <p id="qrScanResult" class="mt-3 mb-0" style="font-size:13px;">Point the beneficiary's QR code at the camera.</p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo APP_BASE; ?>/assets/js/jsQR.min.js"></script>
    <script>
        let scanActivityId = null;
        let scanStream = null;
        let scanTimer = null;

        function openScanner(activityId) {
            scanActivityId = activityId;
            document.getElementById('qrScanResult').textContent = 'Point the beneficiary\'s QR code at the camera.';
            // The attendance modal and the scanner modal share a z-index, so close the former first.
            const attEl = document.getElementById('attendanceModal' + activityId);
            const showScanner = function() {
                new bootstrap.Modal(document.getElementById('qrScanModal')).show();
                navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: 'environment'
                    }
                }).then(stream => {
                    scanStream = stream;
                    document.getElementById('qrScanVideo').srcObject = stream;
                    scanTimer = setInterval(scanFrame, 400);
                }).catch(() => {
                    document.getElementById('qrScanResult').textContent = 'Camera unavailable. Please allow camera access and try again.';
                });
            };
            if (attEl && attEl.classList.contains('show')) {
                attEl.addEventListener('hidden.bs.modal', showScanner, {
                    once: true
                });
                bootstrap.Modal.getOrCreateInstance(attEl).hide();
            } else {
                showScanner();
            }
        }

        function scanFrame() {
            const video = document.getElementById('qrScanVideo');
            if (!video.videoWidth) return;
            const canvas = document.getElementById('qrScanCanvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const code = jsQR(imageData.data, imageData.width, imageData.height);
            if (code && code.data) {
                submitToken(code.data);
            }
        }

        let submitting = false;

        function submitToken(token) {
            if (!token || submitting) return;
            submitting = true;
            fetch('<?php echo APP_BASE; ?>/ScanProgramAttendance.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'tab_id=<?php echo (int)$tabId; ?>&activity_id=' + encodeURIComponent(scanActivityId) + '&token=' + encodeURIComponent(token)
                }).then(r => r.json())
                .then(data => {
                    document.getElementById('qrScanResult').textContent = data.message;
                    if (data.success) {
                        stopScanner();
                        // A fresh URL (cache-busting param) forces a real re-fetch, and reopening the same
                        // activity's modal afterward shows the updated attendance.
                        setTimeout(() => {
                            window.location.href = window.location.pathname + '?<?php echo !empty($tab['program_id']) ? 'ptab' : 'tab'; ?>=<?php echo (int)$tabId; ?>&openAttendance=' + encodeURIComponent(scanActivityId) + '&_t=' + Date.now();
                        }, 900);
                    } else {
                        setTimeout(() => {
                            submitting = false;
                        }, 1500);
                    }
                }).catch(() => {
                    document.getElementById('qrScanResult').textContent = 'Network error — try again.';
                    submitting = false;
                });
        }

        function stopScanner() {
            if (scanTimer) clearInterval(scanTimer);
            if (scanStream) scanStream.getTracks().forEach(t => t.stop());
            scanTimer = null;
            scanStream = null;
        }

        document.getElementById('qrScanModal').addEventListener('hidden.bs.modal', stopScanner);

        (function() {
            const openId = new URLSearchParams(window.location.search).get('openAttendance');
            if (!openId) return;
            const modalEl = document.getElementById('attendanceModal' + openId);
            if (modalEl) {
                new bootstrap.Modal(modalEl).show();
            }
        })();

        function filterAttendanceRows(activityId, filterVal) {
            const tbody = document.getElementById('attendanceTableBody' + activityId);
            if (!tbody) return;
            tbody.querySelectorAll('.attendance-row').forEach(function(row) {
                const select = row.querySelector('select[name^="attendance"]');
                const status = select ? select.value : 'pending';
                row.style.display = (filterVal === 'all' || status === filterVal) ? '' : 'none';
            });
        }

        // Client-side search / filter / sort (Activities table)
        const activitySearchInput = document.getElementById('activitySearchInput');
        const activityFilterSelect = document.getElementById('activityFilterSelect');
        const activitySortSelect = document.getElementById('activitySortSelect');
        const activitiesTableBody = document.getElementById('activitiesTableBody');

        function applyActivityFilters() {
            if (!activitiesTableBody) return;
            const q = activitySearchInput ? activitySearchInput.value.trim().toLowerCase() : '';
            const filterVal = activityFilterSelect ? activityFilterSelect.value : 'all';
            const sortVal = activitySortSelect ? activitySortSelect.value : 'id_asc';
            const [filterKey, filterArg] = filterVal.includes(':') ? filterVal.split(':') : [null, null];

            const rows = Array.from(activitiesTableBody.querySelectorAll('.activity-row'));
            if (!rows.length) return;

            rows.forEach(function(row) {
                const matchesSearch = !q || (row.dataset.title || '').includes(q);
                const matchesFilter = !filterKey || (filterKey === 'audience' && (row.dataset.audience || '') === filterArg);
                row.style.display = (matchesSearch && matchesFilter) ? '' : 'none';
            });

            rows.sort(function(a, b) {
                switch (sortVal) {
                    case 'id_desc':
                        return (+b.dataset.activityId) - (+a.dataset.activityId);
                    case 'title_asc':
                        return (a.dataset.title || '').localeCompare(b.dataset.title || '');
                    case 'title_desc':
                        return (b.dataset.title || '').localeCompare(a.dataset.title || '');
                    case 'date_desc':
                        return (+b.dataset.date) - (+a.dataset.date);
                    case 'date_asc':
                        return (+a.dataset.date) - (+b.dataset.date);
                    default:
                        return (+a.dataset.activityId) - (+b.dataset.activityId);
                }
            });
            rows.forEach(row => activitiesTableBody.appendChild(row));
        }

        if (activitySearchInput) activitySearchInput.addEventListener('input', applyActivityFilters);
        if (activityFilterSelect) activityFilterSelect.addEventListener('change', applyActivityFilters);
        if (activitySortSelect) activitySortSelect.addEventListener('change', applyActivityFilters);
        applyActivityFilters();
    </script>
</body>

</html>