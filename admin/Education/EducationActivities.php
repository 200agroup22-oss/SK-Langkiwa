<?php
require_once __DIR__ . '/../../config/scholars.php';
requireRole(['admin', 'committee_admin', 'secretary', 'treasurer']);

$committeeId = getCommitteeIdByCode('education');
requireCommitteeAccess($committeeId);
$term = getCurrentTerm();
$me = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['add_activity'])) {
        $title = trim($_POST['title'] ?? '');
        $date = $_POST['activity_date'] ?? '';
        $time = $_POST['activity_time'] ?: null;
        $venue = trim($_POST['venue'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $audience = ($_POST['audience'] ?? 'all') === 'selected' ? 'selected' : 'all';

        if ($title === '' || $date === '') {
            setFlash('error', 'Activity title and date are required.');
        } else {
            $stmt = $conn->prepare("INSERT INTO activities (committee_id, academic_year, semester, title, description, activity_date, activity_time, venue, audience, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('issssssssi', $committeeId, $term['current_academic_year'], $term['current_semester'], $title, $description, $date, $time, $venue, $audience, $me['user_id']);
            $stmt->execute();
            $activityId = $stmt->insert_id;
            $stmt->close();

            if ($audience === 'selected') {
                $scholarIds = array_map('intval', $_POST['scholar_ids'] ?? []);
            } else {
                $res = $conn->query("SELECT scholar_id FROM scholars WHERE status = 'active'");
                $scholarIds = array_column($res->fetch_all(MYSQLI_ASSOC), 'scholar_id');
            }

            foreach ($scholarIds as $scholarId) {
                $token = generateQrToken();
                $stmt = $conn->prepare("INSERT INTO attendance (activity_id, scholar_id, status, qr_token) VALUES (?, ?, 'pending', ?)");
                $stmt->bind_param('iis', $activityId, $scholarId, $token);
                $stmt->execute();
                $stmt->close();

                if ($audience === 'selected') {
                    $stmt = $conn->prepare("INSERT INTO activity_scholars (activity_id, scholar_id) VALUES (?, ?)");
                    $stmt->bind_param('ii', $activityId, $scholarId);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            logAudit('Added Activity', $title);
            setFlash('success', 'Activity added.');
        }
    }

    if (isset($_POST['edit_activity'])) {
        $activityId = (int)$_POST['activity_id'];
        $title = trim($_POST['title'] ?? '');
        $date = $_POST['activity_date'] ?? '';
        $time = $_POST['activity_time'] ?: null;
        $venue = trim($_POST['venue'] ?? '');
        $description = trim($_POST['description'] ?? '');

        $stmt = $conn->prepare("UPDATE activities SET title=?, activity_date=?, activity_time=?, venue=?, description=? WHERE activity_id=?");
        $stmt->bind_param('sssssi', $title, $date, $time, $venue, $description, $activityId);
        $stmt->execute();
        $stmt->close();
        logAudit('Updated Activity', $title);
        setFlash('success', 'Activity updated.');
    }

    if (isset($_POST['archive_activity'])) {
        $activityId = (int)$_POST['activity_id'];
        $stmt = $conn->prepare("UPDATE activities SET archived_at = NOW() WHERE activity_id = ?");
        $stmt->bind_param('i', $activityId);
        $stmt->execute();
        $stmt->close();
        logAudit('Archived Activity', 'Activity #' . $activityId);
        setFlash('success', 'Activity archived.');
    }

    if (isset($_POST['restore_activity'])) {
        $activityId = (int)$_POST['activity_id'];
        $stmt = $conn->prepare("UPDATE activities SET archived_at = NULL WHERE activity_id = ?");
        $stmt->bind_param('i', $activityId);
        $stmt->execute();
        $stmt->close();
        logAudit('Restored Activity', 'Activity #' . $activityId);
        setFlash('success', 'Activity restored.');
    }

    if (isset($_POST['save_attendance'])) {
        $activityId = (int)$_POST['activity_id'];
        $states = $_POST['attendance'] ?? [];
        foreach ($states as $scholarId => $status) {
            $scholarId = (int)$scholarId;
            $status = ($status === 'present') ? 'present' : 'absent';
            if ($status === 'present') {
                $stmt = $conn->prepare("UPDATE attendance SET status = 'present', scanned_at = COALESCE(scanned_at, NOW()) WHERE activity_id = ? AND scholar_id = ?");
            } else {
                $stmt = $conn->prepare("UPDATE attendance SET status = 'absent', scanned_at = NULL WHERE activity_id = ? AND scholar_id = ?");
            }
            $stmt->bind_param('ii', $activityId, $scholarId);
            $stmt->execute();
            $stmt->close();
        }
        logAudit('Saved Attendance', 'Activity #' . $activityId);
        setFlash('success', 'Attendance saved.');
    }

    header("Location: EducationActivities.php");
    exit();
}

$pageError = getFlash('error');
$pageSuccess = getFlash('success');

$stmt = $conn->prepare("SELECT a.*,
        (SELECT COUNT(*) FROM attendance WHERE activity_id = a.activity_id) AS total_assigned,
        (SELECT COUNT(*) FROM attendance WHERE activity_id = a.activity_id AND status = 'present') AS total_present
    FROM activities a
    WHERE a.committee_id = ? AND a.archived_at IS NULL
    ORDER BY a.activity_id ASC");
$stmt->bind_param('i', $committeeId);
$stmt->execute();
$activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("SELECT * FROM activities WHERE committee_id = ? AND archived_at IS NOT NULL ORDER BY archived_at DESC");
$stmt->bind_param('i', $committeeId);
$stmt->execute();
$archivedActivities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$scholars = $conn->query("SELECT s.scholar_id, u.first_name, u.last_name FROM scholars s JOIN users u ON u.user_id = s.user_id WHERE s.status = 'active' ORDER BY u.last_name")->fetch_all(MYSQLI_ASSOC);

function getAttendanceRows($conn, $activityId)
{
    $stmt = $conn->prepare("SELECT att.scholar_id, att.status, u.first_name, u.last_name
        FROM attendance att JOIN scholars s ON s.scholar_id = att.scholar_id JOIN users u ON u.user_id = s.user_id
        WHERE att.activity_id = ? ORDER BY u.last_name");
    $stmt->bind_param('i', $activityId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

$activeLink = 'EducationActivities';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activities - iSKolar ng Langkiwa</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo APP_BASE; ?>/assets/css/admin.css" rel="stylesheet">
    <style>
        .badge-present {
            background-color: #4caf50;
            color: #fff;
            font-size: 11px;
            padding: 3px 10px;
            border-radius: 20px;
            font-weight: 600;
        }

        .badge-absent {
            background-color: #f44336;
            color: #fff;
            font-size: 11px;
            padding: 3px 10px;
            border-radius: 20px;
            font-weight: 600;
        }

        .badge-pending-att {
            background-color: #ffc107;
            color: #212529;
            font-size: 11px;
            padding: 3px 10px;
            border-radius: 20px;
            font-weight: 600;
        }

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

    <?php include __DIR__ . '/../../includes/adminsidebar.php'; ?>

    <div class="main-content">
        <h4 class="fw-bold mb-1">Activities</h4>
        <p class="text-muted mb-4" style="font-size: 13px;">Add, view and monitor activity attendance for iSKolar ng Langkiwa scholars.</p>

        <?php if ($pageSuccess): ?><div class="alert alert-success py-2"><?php echo e($pageSuccess); ?></div><?php endif; ?>
        <?php if ($pageError): ?><div class="alert alert-danger py-2"><?php echo e($pageError); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addActivityModal">
                    <i class="bi bi-plus-lg me-1"></i> Add Activity
                </button>
                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#archivesModal">
                    <i class="bi bi-archive me-1"></i> Archives
                </button>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <select class="form-select form-select-sm" id="activityFilterSelect" style="width:auto;">
                    <option value="all">Filter: All Activities</option>
                    <option value="audience:all">Audience: All Scholars</option>
                    <option value="audience:selected">Audience: Selected Scholars</option>
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
                <table class="table mb-0">
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
                                <td><?php echo str_pad($act['activity_id'], 3, '0', STR_PAD_LEFT); ?></td>
                                <td><?php echo e($act['title']); ?></td>
                                <td><?php echo date('F j, Y', strtotime($act['activity_date'])); ?></td>
                                <td><?php echo e($act['venue']); ?></td>
                                <td><span class="text-success fw-semibold"><?php echo $act['total_present']; ?></span> / <span class="text-muted"><?php echo $act['total_assigned']; ?></span> <span class="text-muted" style="font-size:11px;">present</span></td>
                                <td class="d-flex gap-1">
                                    <button class="btn-attendance" data-bs-toggle="modal" data-bs-target="#attendanceModal<?php echo $act['activity_id']; ?>"><i class="bi bi-person-check"></i> Attendance</button>
                                    <button class="btn-edit" data-bs-toggle="modal" data-bs-target="#editActivityModal<?php echo $act['activity_id']; ?>"><i class="bi bi-pencil"></i> Edit</button>
                                    <button class="btn-archive" data-bs-toggle="modal" data-bs-target="#archiveActivityModal<?php echo $act['activity_id']; ?>"><i class="bi bi-archive"></i> Archive</button>
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
                            <input type="text" class="form-control form-control-sm" name="title" placeholder="e.g. Clean Up Drive" required>
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
                            <input type="text" class="form-control form-control-sm" name="venue" placeholder="e.g. Barangay Langkiwa Covered Court">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="font-size:13px; font-weight:600;">Description <span class="text-muted fw-normal">(optional)</span></label>
                            <textarea class="form-control form-control-sm" name="description" rows="3" placeholder="Brief description of the activity..."></textarea>
                        </div>
                        <div class="mb-0">
                            <label class="form-label" style="font-size:13px; font-weight:600;">Required Scholars</label>
                            <select class="form-select form-select-sm" name="audience" id="audienceSelect" onchange="document.getElementById('scholarPicker').style.display = this.value === 'selected' ? 'block' : 'none';">
                                <option value="all" selected>All Scholars</option>
                                <option value="selected">Selected Scholars Only</option>
                            </select>
                            <div class="mt-1" style="font-size:11px; color:#888;"><i class="bi bi-info-circle me-1"></i>This activity will be counted toward scholar eligibility.</div>
                            <div id="scholarPicker" style="display:none;" class="mt-2 border rounded p-2" style="max-height:150px; overflow-y:auto;">
                                <?php foreach ($scholars as $sch): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="scholar_ids[]" value="<?php echo $sch['scholar_id']; ?>" id="sch<?php echo $sch['scholar_id']; ?>">
                                        <label class="form-check-label" for="sch<?php echo $sch['scholar_id']; ?>" style="font-size:12px;"><?php echo e($sch['first_name'] . ' ' . $sch['last_name']); ?></label>
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

    <?php foreach ($activities as $act): $rows = getAttendanceRows($conn, $act['activity_id']); ?>
        <!-- ATTENDANCE MODAL -->
        <div class="modal fade" id="attendanceModal<?php echo $act['activity_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content border-0 shadow">
                    <form method="post">
                        <input type="hidden" name="activity_id" value="<?php echo $act['activity_id']; ?>">
                        <div class="modal-header">
                            <h6 class="modal-title fw-bold"><i class="bi bi-person-check-fill me-2"></i> Scholar Attendance — <?php echo e($act['title']); ?></h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                <div class="attendance-summary d-flex gap-4 flex-wrap mb-0" style="background-color:#e8f5e9;border-radius:8px;padding:12px 16px;">
                                    <div>
                                        <div style="font-size:11px; color:#666; font-weight:700; text-transform:uppercase;">Total Scholars</div>
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
                                    <table class="table mb-0" style="font-size:13px;">
                                        <thead>
                                            <tr>
                                                <th>Scholar</th>
                                                <th>Attendance</th>
                                            </tr>
                                        </thead>
                                        <tbody id="attendanceTableBody<?php echo $act['activity_id']; ?>">
                                            <?php foreach ($rows as $r): ?>
                                                <tr class="attendance-row">
                                                    <td><?php echo e($r['first_name'] . ' ' . $r['last_name']); ?></td>
                                                    <td>
                                                        <select class="form-select form-select-sm" name="attendance[<?php echo $r['scholar_id']; ?>]" style="width:auto; display:inline-block;">
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
                                <input type="text" class="form-control form-control-sm" name="title" value="<?php echo e($act['title']); ?>" required>
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
                                <input type="text" class="form-control form-control-sm" name="venue" value="<?php echo e($act['venue']); ?>">
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
                    <h6 class="modal-title fw-bold"><i class="bi bi-qr-code-scan me-2"></i>Scan Scholar QR Code</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="stopScanner()"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <video id="qrScanVideo" autoplay muted playsinline></video>
                    <canvas id="qrScanCanvas" style="display:none;"></canvas>
                    <p id="qrScanResult" class="mt-3 mb-0" style="font-size:13px;">Point the scholar's QR code at the camera.</p>
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
            document.getElementById('qrScanResult').textContent = 'Point the scholar\'s QR code at the camera.';
            const modal = new bootstrap.Modal(document.getElementById('qrScanModal'));
            modal.show();
            navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: 'environment'
                }
            }).then(stream => {
                scanStream = stream;
                const video = document.getElementById('qrScanVideo');
                video.srcObject = stream;
                scanTimer = setInterval(scanFrame, 400);
            }).catch(() => {
                document.getElementById('qrScanResult').textContent = 'Camera unavailable. Please allow camera access and try again.';
            });
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

        function submitToken(token) {
            if (!token) return;
            fetch('<?php echo APP_BASE; ?>/ScanAttendance.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'activity_id=' + encodeURIComponent(scanActivityId) + '&token=' + encodeURIComponent(token)
                }).then(r => r.json())
                .then(data => {
                    document.getElementById('qrScanResult').textContent = data.message;
                    if (data.success) {
                        stopScanner();
                        setTimeout(() => window.location.reload(), 900);
                    }
                }).catch(() => {
                    document.getElementById('qrScanResult').textContent = 'Network error — try again.';
                });
        }

        function stopScanner() {
            if (scanTimer) clearInterval(scanTimer);
            if (scanStream) scanStream.getTracks().forEach(t => t.stop());
            scanTimer = null;
            scanStream = null;
        }

        document.getElementById('qrScanModal').addEventListener('hidden.bs.modal', stopScanner);

        // Attendance modal: filter the scholar list by their current attendance selection
        // (Present/Absent/Pending), including any unsaved changes made in the dropdowns.
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
                    case 'id_asc':
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