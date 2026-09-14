<?php
require_once __DIR__ . '/../config/forms.php';
requireRole('admin');

$me = currentUser();

// ---- POST handlers (redirect-after-POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['save_logo'])) {
        try {
            $uploaded = handleUpload($_FILES['logo'] ?? [], 'logos');
            if ($uploaded) {
                $stmt = $conn->prepare("UPDATE site_settings SET logo_path = ? WHERE id = 1");
                $stmt->bind_param('s', $uploaded['path']);
                $stmt->execute();
                $stmt->close();
                logAudit('Updated Site Settings', 'System logo');
                setFlash('success', 'Logo updated.');
            } else {
                setFlash('error', 'Please choose a logo file to upload.');
            }
        } catch (RuntimeException $ex) {
            setFlash('error', $ex->getMessage());
        }
    }

    if (isset($_POST['save_about'])) {
        $aboutText = trim($_POST['about_text'] ?? '');
        $stmt = $conn->prepare("UPDATE site_settings SET about_text = ? WHERE id = 1");
        $stmt->bind_param('s', $aboutText);
        $stmt->execute();
        $stmt->close();
        logAudit('Updated Site Settings', 'About the Program text');
        setFlash('success', 'About the Program updated.');
    }

    if (isset($_POST['save_terms'])) {
        $terms = trim($_POST['terms_conditions'] ?? '');
        if ($terms === '') {
            setFlash('error', 'Terms and Conditions cannot be empty — applicants must have something to agree to.');
        } else {
            $stmt = $conn->prepare("UPDATE site_settings SET terms_conditions = ? WHERE id = 1");
            $stmt->bind_param('s', $terms);
            $stmt->execute();
            $stmt->close();
            logAudit('Updated Site Settings', 'Terms and Conditions');
            setFlash('success', 'Terms and Conditions updated.');
        }
    }

    if (isset($_POST['save_office_info'])) {
        $address = trim($_POST['sk_office_address'] ?? '');
        $contact = trim($_POST['contact_number'] ?? '');
        $hours = trim($_POST['office_hours'] ?? '');
        $stmt = $conn->prepare("UPDATE site_settings SET sk_office_address = ?, contact_number = ?, office_hours = ? WHERE id = 1");
        $stmt->bind_param('sss', $address, $contact, $hours);
        $stmt->execute();
        $stmt->close();
        logAudit('Updated Site Settings', 'SK Office info');
        setFlash('success', 'SK Office info updated.');
    }

    if (isset($_POST['update_program_slots'])) {
        $tabId = (int)$_POST['tab_id'];
        $slotsRaw = trim($_POST['max_slots'] ?? '');
        $maxSlots = ($slotsRaw === '') ? null : max(0, (int)$slotsRaw);
        $stmt = $conn->prepare("UPDATE program_tabs SET max_slots = ? WHERE tab_id = ?");
        $stmt->bind_param('ii', $maxSlots, $tabId);
        $stmt->execute();
        $stmt->close();
        logAudit('Updated Program Slots', 'Tab #' . $tabId . ' -> ' . ($maxSlots === null ? 'Unlimited' : $maxSlots));
        setFlash('success', 'Slot limit updated.');
    }

    if (isset($_POST['add_date'])) {
        $eventName = trim($_POST['event_name'] ?? '');
        $eventDate = $_POST['event_date'] ?? '';
        if ($eventName === '' || $eventDate === '') {
            setFlash('error', 'Event name and date are required.');
        } else {
            $stmt = $conn->prepare("INSERT INTO important_dates (event_name, event_date) VALUES (?, ?)");
            $stmt->bind_param('ss', $eventName, $eventDate);
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();
            logAudit('Added Important Date', $eventName . ' (#' . $newId . ')');
            setFlash('success', 'Event added.');
        }
    }

    if (isset($_POST['edit_date'])) {
        $dateId = (int)$_POST['date_id'];
        $eventName = trim($_POST['event_name'] ?? '');
        $eventDate = $_POST['event_date'] ?? '';
        if ($eventName === '' || $eventDate === '') {
            setFlash('error', 'Event name and date are required.');
        } else {
            $stmt = $conn->prepare("UPDATE important_dates SET event_name = ?, event_date = ? WHERE date_id = ?");
            $stmt->bind_param('ssi', $eventName, $eventDate, $dateId);
            $stmt->execute();
            $stmt->close();
            logAudit('Updated Important Date', $eventName . ' (#' . $dateId . ')');
            setFlash('success', 'Event updated.');
        }
    }

    if (isset($_POST['delete_date'])) {
        $dateId = (int)$_POST['date_id'];
        $stmt = $conn->prepare("DELETE FROM important_dates WHERE date_id = ?");
        $stmt->bind_param('i', $dateId);
        $stmt->execute();
        $stmt->close();
        logAudit('Deleted Important Date', 'Date #' . $dateId);
        setFlash('success', 'Event deleted.');
    }

    if (isset($_POST['add_announcement'])) {
        $title = trim($_POST['title'] ?? '');
        $message = trim($_POST['message'] ?? '');
        if ($title === '' || $message === '') {
            setFlash('error', 'Title and message are required.');
        } else {
            $stmt = $conn->prepare("INSERT INTO announcements (committee_id, title, message, sent_to, posted_by, posted_at) VALUES (NULL, ?, ?, 'all', ?, NOW())");
            $stmt->bind_param('ssi', $title, $message, $me['user_id']);
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();
            logAudit('Posted Announcement', $title . ' (#' . $newId . ')');
            setFlash('success', 'Announcement posted.');
        }
    }

    if (isset($_POST['edit_announcement'])) {
        $announcementId = (int)$_POST['announcement_id'];
        $title = trim($_POST['title'] ?? '');
        $message = trim($_POST['message'] ?? '');
        if ($title === '' || $message === '') {
            setFlash('error', 'Title and message are required.');
        } else {
            $stmt = $conn->prepare("UPDATE announcements SET title = ?, message = ? WHERE announcement_id = ?");
            $stmt->bind_param('ssi', $title, $message, $announcementId);
            $stmt->execute();
            $stmt->close();
            logAudit('Updated Announcement', $title . ' (#' . $announcementId . ')');
            setFlash('success', 'Announcement updated.');
        }
    }

    if (isset($_POST['delete_announcement'])) {
        $announcementId = (int)$_POST['announcement_id'];
        $stmt = $conn->prepare("UPDATE announcements SET archived_at = NOW() WHERE announcement_id = ?");
        $stmt->bind_param('i', $announcementId);
        $stmt->execute();
        $stmt->close();
        logAudit('Removed Announcement', 'Announcement #' . $announcementId);
        setFlash('success', 'Announcement removed.');
    }

    header("Location: AdminConfiguration.php");
    exit();
}

$pageError = getFlash('error');
$pageSuccess = getFlash('success');

$settings = $conn->query("SELECT * FROM site_settings WHERE id = 1")->fetch_assoc();
$importantDates = $conn->query("SELECT * FROM important_dates ORDER BY event_date ASC")->fetch_all(MYSQLI_ASSOC);
$announcements = $conn->query("SELECT * FROM announcements WHERE archived_at IS NULL ORDER BY posted_at DESC")->fetch_all(MYSQLI_ASSOC);

// ---- Application / Program Settings: slot limits per committee+track program ----
$programTabRows = $conn->query("SELECT t.*, c.name AS committee_name FROM program_tabs t JOIN committees c ON c.committee_id = t.committee_id ORDER BY c.committee_id ASC, t.sort_order ASC")->fetch_all(MYSQLI_ASSOC);
foreach ($programTabRows as &$row) {
    $row['approved_count'] = getApprovedCount((int)$row['committee_id'], $row['track_code']);
}
unset($row);
// Committee names for the "Filter by Committee" dropdown — a dropdown scales better here than a
// tab per committee, since committees are added freely via Content Management and could grow past
// what a tab bar can comfortably hold.
$programSettingsCommittees = array_values(array_unique(array_column($programTabRows, 'committee_name')));
sort($programSettingsCommittees);

// ---- Forms tab: committee -> program/track picker that jumps to that form's existing field
// editor page. Each built-in committee (Education/Health/Sports/Active Citizenship) keeps its own
// dedicated editor page; any other committee shares the generic CommitteeForms.php. This just
// finds the right URL for a given committee+track+tab so the picker can link to it directly
// instead of the admin hunting for it in the sidebar's nested program submenus.
function formEditorUrl($committeeCode, $committeeId, $trackCode, $tabId)
{
    $isBaseTab = in_array($trackCode, ['scholarship', 'assistance'], true);
    switch ($committeeCode) {
        case 'education':
            if ($trackCode === 'scholarship') {
                return APP_BASE . '/admin/Education/EducationForms.php';
            }
            $url = APP_BASE . '/admin/Education/EducationAssistanceForms.php';
            return $isBaseTab ? $url : $url . '?ptab=' . $tabId;
        case 'health':
            $url = APP_BASE . '/admin/Health/HealthForms.php';
            return $isBaseTab ? $url : $url . '?ptab=' . $tabId;
        case 'sports':
            $url = APP_BASE . '/admin/Sports/SportsForms.php';
            return $isBaseTab ? $url : $url . '?ptab=' . $tabId;
        case 'active_citizenship':
            $url = APP_BASE . '/admin/ActiveCitizenship/ActiveCitizenshipForms.php';
            return $isBaseTab ? $url : $url . '?ptab=' . $tabId;
        default:
            $url = APP_BASE . '/admin/CommitteeForms.php?committee=' . $committeeId;
            return $isBaseTab ? $url : $url . '&ptab=' . $tabId;
    }
}

$allCommittees = $conn->query("SELECT * FROM committees WHERE archived_at IS NULL ORDER BY committee_id ASC")->fetch_all(MYSQLI_ASSOC);
$formsByCommittee = [];
foreach ($allCommittees as $c) {
    $cid = (int)$c['committee_id'];
    $forms = [];
    foreach (getProgramTabs($cid, false) as $t) {
        $forms[] = [
            'label' => $t['label'],
            'url' => formEditorUrl($c['code'], $cid, $t['track_code'], (int)$t['tab_id']),
        ];
    }
    $formsByCommittee[$cid] = $forms;
}

// ---- Activity Logs: filter (role, date range) + search, most-recent first, capped at 200 rows (no full pagination — thesis-scale data) ----
$roleFilter = $_GET['role'] ?? '';
$dateFilter = $_GET['logdate'] ?? '';
$logSearch = trim($_GET['logq'] ?? '');

$activityLogs = $conn->query("SELECT * FROM activity_logs ORDER BY logged_in_at DESC LIMIT 200")->fetch_all(MYSQLI_ASSOC);
$distinctRoles = array_values(array_unique(array_map(fn($r) => $r['role'], $activityLogs)));

if ($roleFilter !== '') {
    $activityLogs = array_values(array_filter($activityLogs, fn($r) => $r['role'] === $roleFilter));
}
if ($dateFilter !== '') {
    $now = time();
    $activityLogs = array_values(array_filter($activityLogs, function ($r) use ($dateFilter, $now) {
        $t = strtotime($r['logged_in_at']);
        if ($dateFilter === 'today') return date('Y-m-d', $t) === date('Y-m-d', $now);
        if ($dateFilter === 'week') return $t >= strtotime('monday this week', $now);
        if ($dateFilter === 'month') return date('Y-m', $t) === date('Y-m', $now);
        return true;
    }));
}
if ($logSearch !== '') {
    $needle = mb_strtolower($logSearch);
    $activityLogs = array_values(array_filter($activityLogs, fn($r) => str_contains(mb_strtolower($r['full_name']), $needle) || str_contains(mb_strtolower($r['email']), $needle)));
}

// ---- Audit Logs: filter (action, date range) + search, capped at 200 rows ----
$actionFilter = $_GET['action'] ?? '';
$auditDateFilter = $_GET['auditdate'] ?? '';
$auditSearch = trim($_GET['auditq'] ?? '');

$distinctActions = $conn->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC")->fetch_all(MYSQLI_ASSOC);

$auditLogs = $conn->query("SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 200")->fetch_all(MYSQLI_ASSOC);

if ($actionFilter !== '') {
    $auditLogs = array_values(array_filter($auditLogs, fn($r) => $r['action'] === $actionFilter));
}
if ($auditDateFilter !== '') {
    $now = time();
    $auditLogs = array_values(array_filter($auditLogs, function ($r) use ($auditDateFilter, $now) {
        $t = strtotime($r['created_at']);
        if ($auditDateFilter === 'today') return date('Y-m-d', $t) === date('Y-m-d', $now);
        if ($auditDateFilter === 'week') return $t >= strtotime('monday this week', $now);
        if ($auditDateFilter === 'month') return date('Y-m', $t) === date('Y-m', $now);
        return true;
    }));
}
if ($auditSearch !== '') {
    $needle = mb_strtolower($auditSearch);
    $auditLogs = array_values(array_filter($auditLogs, fn($r) => str_contains(mb_strtolower($r['full_name']), $needle) || str_contains(mb_strtolower($r['email']), $needle) || str_contains(mb_strtolower($r['action']), $needle)));
}

$activeLink = 'AdminConfiguration';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo APP_BASE; ?>/assets/css/admin.css" rel="stylesheet">
    <style>
        .config-card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 24px;
            margin-bottom: 24px;
        }

        .config-card .card-section-title {
            font-size: 15px;
            font-weight: 700;
            color: #2e7d32;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
        }

        .config-card .card-section-sub {
            font-size: 13px;
            color: #888;
            margin-bottom: 20px;
        }

        .divider {
            border-top: 1px solid #eee;
            margin: 20px 0;
        }

        .setting-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            padding: 14px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .setting-item:last-child {
            border-bottom: none;
        }

        .setting-label {
            font-size: 13px;
            font-weight: 600;
            color: #333;
        }

        .setting-desc {
            font-size: 12px;
            color: #999;
            margin-top: 2px;
        }

        .setting-control {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .logo-preview {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            border: 2px solid #a5d6a7;
            background: #e8f5e9;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .logo-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .req-row {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f8f9fa;
            border: 1px solid #eee;
            border-radius: 6px;
            padding: 9px 14px;
            margin-bottom: 8px;
            font-size: 13px;
            flex-wrap: wrap;
        }

        .req-row i {
            color: #45b84d;
        }

        .req-row .req-actions {
            margin-left: auto;
            display: flex;
            gap: 6px;
        }

        .table-card {
            background: #fff;
            border-radius: 8px;
            border: 1px solid #eee;
            overflow-x: auto;
        }

        .log-table-card {
            max-height: 420px;
            overflow-y: auto;
        }

        .log-table-card thead th {
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .table {
            min-width: 600px;
        }

        .table thead th {
            background-color: #a5d6a7;
            color: #1b5e20;
            font-size: 12px;
            font-weight: 600;
            border: none;
            padding: 10px 14px;
        }

        .table tbody td {
            font-size: 12px;
            color: #333;
            padding: 10px 14px;
            vertical-align: middle;
            border-bottom: 1px solid #f5f5f5;
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        .btn-sm-icon {
            background: none;
            border: none;
            padding: 3px 6px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
        }

        .btn-edit-req {
            color: #f57f17;
            background-color: #fff8e1;
        }

        .btn-del-req {
            color: #c62828;
            background-color: #fce4ec;
        }

        .search-box {
            position: relative;
            width: 200px;
        }

        .search-box input {
            border: 1px solid #ccc;
            border-radius: 20px;
            padding: 5px 32px 5px 12px;
            font-size: 12px;
            width: 100%;
            outline: none;
        }

        .search-box input:focus {
            border-color: #45b84d;
        }

        .search-box i {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #888;
            font-size: 13px;
        }

        .filter-select {
            border: 1px solid #ccc;
            border-radius: 6px;
            padding: 5px 10px;
            font-size: 12px;
            color: #555;
            background: #fff;
            outline: none;
        }

        @media (max-width: 991px) {
            .config-card {
                padding: 18px;
            }

            .setting-item {
                flex-direction: column;
                align-items: flex-start;
            }

            .setting-control {
                width: 100%;
            }

            .search-box {
                width: 100%;
            }
        }

        @media (max-width: 576px) {
            .config-card {
                padding: 14px;
                border-radius: 8px;
            }

            .card-section-title {
                font-size: 14px;
            }

            .req-row .req-actions {
                margin-left: 0;
            }
        }

        .content-tabs {
            display: flex;
            gap: 6px;
            border-bottom: 2px solid #e0e0e0;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .content-tab-btn {
            background: none;
            border: none;
            font-size: 13.5px;
            font-weight: 600;
            color: #666;
            padding: 10px 16px;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
        }

        .content-tab-btn.active {
            color: #2e7d32;
            border-bottom-color: #45b84d;
        }

        .content-tab-btn:hover {
            color: #2e7d32;
        }

        .tab-pane-custom {
            display: none;
        }

        .tab-pane-custom.active {
            display: block;
        }
    </style>
</head>

<body>

    <?php include __DIR__ . '/../includes/adminsidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <h4 class="fw-bold mb-1">Configuration</h4>
        <p class="text-muted mb-2" style="font-size: 13px;">Manage system settings, activity logs, and audit logs.</p>

        <?php if ($pageSuccess): ?><div class="alert alert-success py-2"><?php echo e($pageSuccess); ?></div><?php endif; ?>
        <?php if ($pageError): ?><div class="alert alert-danger py-2"><?php echo e($pageError); ?></div><?php endif; ?>

        <!-- Tabs -->
        <div class="content-tabs">
            <button class="content-tab-btn active" data-tab="settingsTab"><i class="bi bi-gear-fill"></i> Settings</button>
            <button class="content-tab-btn" data-tab="programSettingsTab"><i class="bi bi-ui-checks-grid"></i> Application / Program Settings</button>
            <button class="content-tab-btn" data-tab="formsTab"><i class="bi bi-file-earmark-text"></i> Forms</button>
            <button class="content-tab-btn" data-tab="activityLogsTab"><i class="bi bi-activity"></i> Activity Logs</button>
            <button class="content-tab-btn" data-tab="auditLogsTab"><i class="bi bi-journal-text"></i> Audit Logs</button>
        </div>

        <!-- ==============================
             SECTION 1: SETTINGS
        ============================== -->
        <div class="tab-pane-custom active" id="settingsTab">
            <div class="config-card">
                <div class="card-section-title"><i class="bi bi-gear-fill"></i> Settings</div>
                <div class="card-section-sub">Configure system-wide settings that apply across all pages and modules.</div>

                <!-- Logo -->
                <form method="post" enctype="multipart/form-data">
                    <div class="setting-item">
                        <div>
                            <div class="setting-label"><i class="bi bi-image text-success me-1"></i> System Logo</div>
                            <div class="setting-desc">Upload a new logo that will appear across all pages of the system.</div>
                        </div>
                        <div class="setting-control gap-3">
                            <div class="logo-preview">
                                <img src="<?php echo $settings['logo_path'] ? APP_BASE . '/' . e($settings['logo_path']) : APP_BASE . '/admin/photos/logo.jpg'; ?>" alt="Current Logo">
                            </div>
                            <input type="file" name="logo" class="form-control form-control-sm" accept="image/*" style="max-width:220px;">
                            <button type="submit" name="save_logo" class="btn btn-sm btn-outline-success"><i class="bi bi-upload me-1"></i> Upload</button>
                        </div>
                    </div>
                </form>

                <div class="divider"></div>

                <!-- About the Program -->
                <form method="post">
                    <div class="mb-2">
                        <div class="setting-label"><i class="bi bi-info-circle-fill text-success me-1"></i> About the Program</div>
                        <div class="setting-desc mt-1 mb-2">This text appears in the "About the Program" card on the applicant dashboard.</div>
                        <textarea class="form-control form-control-sm" name="about_text" rows="3" style="font-size:13px;"><?php echo e($settings['about_text']); ?></textarea>
                        <div class="text-end mt-2">
                            <button type="submit" name="save_about" class="btn btn-sm btn-success px-3">Save</button>
                        </div>
                    </div>
                </form>

                <div class="divider"></div>

                <!-- Terms and Conditions -->
                <form method="post">
                    <div class="mb-2">
                        <div class="setting-label"><i class="bi bi-file-earmark-text-fill text-success me-1"></i> Terms and Conditions</div>
                        <div class="setting-desc mt-1 mb-2">Shown to applicants during registration. They must check a box agreeing to this text before an account can be created.</div>
                        <textarea class="form-control form-control-sm" name="terms_conditions" rows="8" style="font-size:12.5px; font-family: 'Courier New', monospace;" required><?php echo e($settings['terms_conditions']); ?></textarea>
                        <div class="text-end mt-2">
                            <button type="submit" name="save_terms" class="btn btn-sm btn-success px-3">Save</button>
                        </div>
                    </div>
                </form>

                <div class="divider"></div>

                <!-- SK Office -->
                <form method="post">
                    <div class="mb-2">
                        <div class="setting-label"><i class="bi bi-geo-alt-fill text-success me-1"></i> SK Office Info</div>
                        <div class="setting-desc mt-1 mb-2">Contact details shown in the "SK Office" card on the applicant dashboard.</div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label" style="font-size:12px; font-weight:600;">Address</label>
                                <input type="text" class="form-control form-control-sm" style="font-size:13px;" name="sk_office_address" value="<?php echo e($settings['sk_office_address']); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" style="font-size:12px; font-weight:600;">Contact Number</label>
                                <input type="text" class="form-control form-control-sm" style="font-size:13px;" name="contact_number" value="<?php echo e($settings['contact_number']); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" style="font-size:12px; font-weight:600;">Office Hours</label>
                                <input type="text" class="form-control form-control-sm" style="font-size:13px;" name="office_hours" value="<?php echo e($settings['office_hours']); ?>">
                            </div>
                        </div>
                        <div class="text-end mt-2">
                            <button type="submit" name="save_office_info" class="btn btn-sm btn-success px-3">Save</button>
                        </div>
                    </div>
                </form>

                <div class="divider"></div>

                <!-- Important Dates -->
                <div class="mb-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <div class="setting-label"><i class="bi bi-calendar2-event text-success me-1"></i> Important Dates</div>
                        <div class="setting-desc mt-1">Events shown in the "Important Dates" table on the applicant dashboard.</div>
                    </div>
                    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addDateModal">
                        <i class="bi bi-plus-lg me-1"></i> Add Event
                    </button>
                </div>

                <div class="mt-3">
                    <?php if (empty($importantDates)): ?>
                        <p class="text-muted text-center mb-0" style="font-size:13px;">No important dates yet.</p>
                    <?php endif; ?>
                    <?php foreach ($importantDates as $d): ?>
                        <div class="req-row">
                            <i class="bi bi-calendar-event"></i>
                            <?php echo e($d['event_name']); ?> — <span class="text-muted"><?php echo date('F j, Y', strtotime($d['event_date'])); ?></span>
                            <div class="req-actions">
                                <button class="btn-sm-icon btn-edit-req" data-bs-toggle="modal" data-bs-target="#editDateModal<?php echo $d['date_id']; ?>"><i class="bi bi-pencil"></i></button>
                                <button class="btn-sm-icon btn-del-req" data-bs-toggle="modal" data-bs-target="#deleteDateModal<?php echo $d['date_id']; ?>"><i class="bi bi-trash"></i></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="divider"></div>

                <!-- Announcements -->
                <div class="mb-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <div class="setting-label"><i class="bi bi-bell-fill text-success me-1"></i> Announcements</div>
                        <div class="setting-desc mt-1">Posts shown in the "Announcement" card on the applicant dashboard.</div>
                    </div>
                    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addAnnouncementModal">
                        <i class="bi bi-plus-lg me-1"></i> Add Announcement
                    </button>
                </div>

                <div class="mt-3">
                    <?php if (empty($announcements)): ?>
                        <p class="text-muted text-center mb-0" style="font-size:13px;">No announcements posted yet.</p>
                    <?php endif; ?>
                    <?php foreach ($announcements as $a): ?>
                        <div class="req-row" style="align-items:flex-start;">
                            <i class="bi bi-file-earmark-text-fill mt-1"></i>
                            <div>
                                <div class="fw-semibold" style="font-size:13px;"><?php echo e($a['title']); ?></div>
                                <div class="text-muted" style="font-size:12px;"><?php echo nl2br(e($a['message'])); ?></div>
                                <div class="text-muted" style="font-size:11px;">Posted: <?php echo date('F j, Y', strtotime($a['posted_at'])); ?></div>
                            </div>
                            <div class="req-actions">
                                <button class="btn-sm-icon btn-edit-req" data-bs-toggle="modal" data-bs-target="#editAnnouncementModal<?php echo $a['announcement_id']; ?>"><i class="bi bi-pencil"></i></button>
                                <button class="btn-sm-icon btn-del-req" data-bs-toggle="modal" data-bs-target="#deleteAnnouncementModal<?php echo $a['announcement_id']; ?>"><i class="bi bi-trash"></i></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div><!-- /settingsTab -->

        <!-- ==============================
             SECTION 1.5: APPLICATION / PROGRAM SETTINGS
        ============================== -->
        <div class="tab-pane-custom" id="programSettingsTab">
            <div class="config-card">
                <div class="card-section-title"><i class="bi bi-ui-checks-grid"></i> Application / Program Settings</div>
                <div class="card-section-sub">Set a maximum number of approved slots per program. Leave blank for unlimited. Once a program reaches its limit, admins can no longer approve new applicants for it until the limit is raised or a slot frees up.</div>

                <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                    <span style="font-size:12px; color:#666; font-weight:600;"><i class="bi bi-funnel me-1"></i>Filter by Committee:</span>
                    <select class="filter-select" id="programSettingsCommitteeFilter">
                        <option value="all">All Committees</option>
                        <?php foreach ($programSettingsCommittees as $cn): ?>
                            <option value="<?php echo e(strtolower($cn)); ?>"><?php echo e($cn); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="table-card">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>Committee</th>
                                <th>Program</th>
                                <th>Approved</th>
                                <th>Max Slots</th>
                                <th>Remaining</th>
                            </tr>
                        </thead>
                        <tbody id="programSettingsTableBody">
                            <?php if (empty($programTabRows)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-3">No programs found.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($programTabRows as $row): ?>
                                <?php
                                $remaining = $row['max_slots'] !== null ? max(0, (int)$row['max_slots'] - $row['approved_count']) : null;
                                ?>
                                <tr data-committee="<?php echo e(strtolower($row['committee_name'])); ?>">
                                    <td><?php echo e($row['committee_name']); ?></td>
                                    <td><i class="bi <?php echo e($row['icon']); ?> me-1 text-success"></i><?php echo e($row['label']); ?></td>
                                    <td><?php echo (int)$row['approved_count']; ?></td>
                                    <td>
                                        <form method="post" class="d-flex align-items-center gap-2">
                                            <input type="hidden" name="tab_id" value="<?php echo (int)$row['tab_id']; ?>">
                                            <input type="number" min="0" name="max_slots" class="form-control form-control-sm" style="width:90px;" placeholder="Unlimited" value="<?php echo $row['max_slots'] !== null ? (int)$row['max_slots'] : ''; ?>">
                                            <button type="submit" name="update_program_slots" class="btn btn-sm btn-success"><i class="bi bi-save"></i></button>
                                        </form>
                                    </td>
                                    <td>
                                        <?php if ($remaining === null): ?>
                                            <span class="badge text-bg-secondary">Unlimited</span>
                                        <?php elseif ($remaining === 0): ?>
                                            <span class="badge text-bg-danger">Full</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-success"><?php echo $remaining; ?> left</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div><!-- /programSettingsTab -->

        <!-- ==============================
             SECTION 1.7: FORMS (jump to a program's field editor)
        ============================== -->
        <div class="tab-pane-custom" id="formsTab">
            <div class="config-card">
                <div class="card-section-title"><i class="bi bi-file-earmark-text"></i> Forms</div>
                <div class="card-section-sub">Pick a committee and a program to jump straight to that program's application-form field editor — no need to dig through the sidebar's nested menus.</div>

                <div class="row g-3" style="max-width:640px;">
                    <div class="col-md-6">
                        <label class="form-label">Committee</label>
                        <select class="form-select" id="formsCommitteeSelect">
                            <option value="" disabled selected>Select a committee</option>
                            <?php foreach ($allCommittees as $c): ?>
                                <option value="<?php echo (int)$c['committee_id']; ?>"><?php echo e($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Program / Form</label>
                        <select class="form-select" id="formsProgramSelect" disabled>
                            <option value="">Select a committee first</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <a href="#" id="formsOpenBtn" class="btn-brand" style="pointer-events:none; opacity:0.5; display:inline-block;"><i class="bi bi-box-arrow-up-right me-1"></i> Open Form Editor</a>
                    </div>
                </div>
            </div>
        </div><!-- /formsTab -->

        <!-- ==============================
             SECTION 2: ACTIVITY LOGS
        ============================== -->
        <div class="tab-pane-custom" id="activityLogsTab">
            <div class="config-card">
                <div class="card-section-title"><i class="bi bi-activity"></i> Activity Logs</div>
                <div class="card-section-sub">Track user login and logout activity across the system. Showing the most recent 200 entries.</div>

                <form method="get">
                    <input type="hidden" name="section" value="activity">
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <div class="d-flex gap-2 flex-wrap align-items-center">
                            <span style="font-size:12px; color:#666; font-weight:600;"><i class="bi bi-funnel me-1"></i>Filter:</span>
                            <select class="filter-select" name="role" onchange="this.form.submit()">
                                <option value="">All Roles</option>
                                <?php foreach ($distinctRoles as $r): ?>
                                    <option value="<?php echo e($r); ?>" <?php echo $roleFilter === $r ? 'selected' : ''; ?>><?php echo ucfirst(e($r)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select class="filter-select" name="logdate" onchange="this.form.submit()">
                                <option value="">All Date</option>
                                <option value="today" <?php echo $dateFilter === 'today' ? 'selected' : ''; ?>>Today</option>
                                <option value="week" <?php echo $dateFilter === 'week' ? 'selected' : ''; ?>>This Week</option>
                                <option value="month" <?php echo $dateFilter === 'month' ? 'selected' : ''; ?>>This Month</option>
                            </select>
                        </div>
                        <div class="search-box">
                            <input type="text" name="logq" value="<?php echo e($logSearch); ?>" placeholder="Search...">
                            <i class="bi bi-search"></i>
                        </div>
                    </div>
                </form>

                <div class="table-card log-table-card">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>User ID</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Logged In</th>
                                <th>Logged Out</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($activityLogs)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-3">No activity logs found.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($activityLogs as $log): ?>
                                <tr>
                                    <td><?php echo $log['user_id'] ? str_pad($log['user_id'], 3, '0', STR_PAD_LEFT) : '—'; ?></td>
                                    <td><?php echo e($log['full_name']); ?></td>
                                    <td><?php echo e($log['email']); ?></td>
                                    <td><?php echo ucfirst(e($log['role'])); ?></td>
                                    <td><?php echo date('Y-m-d H:i:s', strtotime($log['logged_in_at'])); ?></td>
                                    <td><?php echo $log['logged_out_at'] ? date('Y-m-d H:i:s', strtotime($log['logged_out_at'])) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-3">
                    <span style="font-size:12px; color:#888;">Showing <?php echo count($activityLogs); ?> entries</span>
                </div>
            </div>
        </div><!-- /activityLogsTab -->

        <!-- audit logs -->
        <div class="tab-pane-custom" id="auditLogsTab">
            <div class="config-card">
                <div class="card-section-title"><i class="bi bi-journal-text"></i> Audit Logs</div>
                <div class="card-section-sub">Track all user actions and system events across the platform. Showing the most recent 200 entries.</div>

                <form method="get">
                    <input type="hidden" name="section" value="audit">
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <div class="d-flex gap-2 flex-wrap align-items-center">
                            <span style="font-size:12px; color:#666; font-weight:600;"><i class="bi bi-funnel me-1"></i>Filter:</span>
                            <select class="filter-select" name="action" onchange="this.form.submit()">
                                <option value="">All Actions</option>
                                <?php foreach ($distinctActions as $ac): ?>
                                    <option value="<?php echo e($ac['action']); ?>" <?php echo $actionFilter === $ac['action'] ? 'selected' : ''; ?>><?php echo e($ac['action']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select class="filter-select" name="auditdate" onchange="this.form.submit()">
                                <option value="">All Date</option>
                                <option value="today" <?php echo $auditDateFilter === 'today' ? 'selected' : ''; ?>>Today</option>
                                <option value="week" <?php echo $auditDateFilter === 'week' ? 'selected' : ''; ?>>This Week</option>
                                <option value="month" <?php echo $auditDateFilter === 'month' ? 'selected' : ''; ?>>This Month</option>
                            </select>
                        </div>
                        <div class="search-box">
                            <input type="text" name="auditq" value="<?php echo e($auditSearch); ?>" placeholder="Search...">
                            <i class="bi bi-search"></i>
                        </div>
                    </div>
                </form>

                <div class="table-card log-table-card">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>User ID</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Action</th>
                                <th>Date &amp; Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($auditLogs)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-3">No audit logs found.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($auditLogs as $log): ?>
                                <tr>
                                    <td><?php echo $log['user_id'] ? str_pad($log['user_id'], 3, '0', STR_PAD_LEFT) : '—'; ?></td>
                                    <td><?php echo e($log['full_name']); ?></td>
                                    <td><?php echo e($log['email']); ?></td>
                                    <td><?php echo e($log['action']); ?><?php echo $log['details'] ? ' — ' . e($log['details']) : ''; ?></td>
                                    <td><?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-3">
                    <span style="font-size:12px; color:#888;">Showing <?php echo count($auditLogs); ?> entries</span>
                </div>
            </div>
        </div><!-- /auditLogsTab -->

    </div>

    <!-- ADD EVENT (IMPORTANT DATES) MODAL -->
    <div class="modal fade" id="addDateModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
            <div class="modal-content border-0 shadow">
                <form method="post">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold"><i class="bi bi-calendar-plus me-2"></i>Add Event</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label" style="font-size:13px; font-weight:600;">Event Name</label>
                            <input type="text" class="form-control form-control-sm" name="event_name" placeholder="e.g. Barangay Youth Assembly" required>
                        </div>
                        <div>
                            <label class="form-label" style="font-size:13px; font-weight:600;">Date</label>
                            <input type="date" class="form-control form-control-sm" name="event_date" required>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_date" class="btn btn-sm btn-success"><i class="bi bi-plus-lg me-1"></i> Add</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php foreach ($importantDates as $d): ?>
        <!-- EDIT EVENT MODAL -->
        <div class="modal fade" id="editDateModal<?php echo $d['date_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
                <div class="modal-content border-0 shadow">
                    <form method="post">
                        <input type="hidden" name="date_id" value="<?php echo $d['date_id']; ?>">
                        <div class="modal-header">
                            <h6 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Event</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4">
                            <div class="mb-3">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Event Name</label>
                                <input type="text" class="form-control form-control-sm" name="event_name" value="<?php echo e($d['event_name']); ?>" required>
                            </div>
                            <div>
                                <label class="form-label" style="font-size:13px; font-weight:600;">Date</label>
                                <input type="date" class="form-control form-control-sm" name="event_date" value="<?php echo e($d['event_date']); ?>" required>
                            </div>
                        </div>
                        <div class="modal-footer border-0">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="edit_date" class="btn btn-sm btn-success"><i class="bi bi-save me-1"></i> Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- DELETE EVENT MODAL -->
        <div class="modal fade" id="deleteDateModal<?php echo $d['date_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered" style="max-width: 380px;">
                <div class="modal-content border-0 shadow">
                    <form method="post">
                        <input type="hidden" name="date_id" value="<?php echo $d['date_id']; ?>">
                        <div class="modal-header" style="background: linear-gradient(90deg, #e53935, #ef9a9a);">
                            <h6 class="modal-title fw-bold text-white"><i class="bi bi-trash-fill me-2"></i>Delete Event</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter: brightness(0) invert(1);"></button>
                        </div>
                        <div class="modal-body p-4 text-center">
                            <i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 36px;"></i>
                            <p class="mt-3 mb-1 fw-bold" style="font-size:14px;">Delete this event?</p>
                            <p class="text-muted mb-0" style="font-size:12px;">This event will be removed from the Important Dates list on the applicant dashboard.</p>
                        </div>
                        <div class="modal-footer border-0 justify-content-center">
                            <button type="button" class="btn btn-sm btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="delete_date" class="btn btn-sm btn-danger px-4"><i class="bi bi-trash me-1"></i> Yes, Delete</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- ADD ANNOUNCEMENT MODAL -->
    <div class="modal fade" id="addAnnouncementModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 460px;">
            <div class="modal-content border-0 shadow">
                <form method="post">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold"><i class="bi bi-bell-fill me-2"></i>Add Announcement</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label" style="font-size:13px; font-weight:600;">Title</label>
                            <input type="text" class="form-control form-control-sm" name="title" placeholder="e.g. Financial Assistance Application Now Open" required>
                        </div>
                        <div>
                            <label class="form-label" style="font-size:13px; font-weight:600;">Message</label>
                            <textarea class="form-control form-control-sm" name="message" rows="3" placeholder="Write the announcement details..." required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_announcement" class="btn btn-sm btn-success"><i class="bi bi-plus-lg me-1"></i> Post</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php foreach ($announcements as $a): ?>
        <!-- EDIT ANNOUNCEMENT MODAL -->
        <div class="modal fade" id="editAnnouncementModal<?php echo $a['announcement_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered" style="max-width: 460px;">
                <div class="modal-content border-0 shadow">
                    <form method="post">
                        <input type="hidden" name="announcement_id" value="<?php echo $a['announcement_id']; ?>">
                        <div class="modal-header">
                            <h6 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Announcement</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4">
                            <div class="mb-3">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Title</label>
                                <input type="text" class="form-control form-control-sm" name="title" value="<?php echo e($a['title']); ?>" required>
                            </div>
                            <div>
                                <label class="form-label" style="font-size:13px; font-weight:600;">Message</label>
                                <textarea class="form-control form-control-sm" name="message" rows="3" required><?php echo e($a['message']); ?></textarea>
                            </div>
                        </div>
                        <div class="modal-footer border-0">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="edit_announcement" class="btn btn-sm btn-success"><i class="bi bi-save me-1"></i> Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- DELETE ANNOUNCEMENT MODAL -->
        <div class="modal fade" id="deleteAnnouncementModal<?php echo $a['announcement_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered" style="max-width: 380px;">
                <div class="modal-content border-0 shadow">
                    <form method="post">
                        <input type="hidden" name="announcement_id" value="<?php echo $a['announcement_id']; ?>">
                        <div class="modal-header" style="background: linear-gradient(90deg, #e53935, #ef9a9a);">
                            <h6 class="modal-title fw-bold text-white"><i class="bi bi-trash-fill me-2"></i>Delete Announcement</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter: brightness(0) invert(1);"></button>
                        </div>
                        <div class="modal-body p-4 text-center">
                            <i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 36px;"></i>
                            <p class="mt-3 mb-1 fw-bold" style="font-size:14px;">Delete this announcement?</p>
                            <p class="text-muted mb-0" style="font-size:12px;">This post will be removed from the applicant dashboard.</p>
                        </div>
                        <div class="modal-footer border-0 justify-content-center">
                            <button type="button" class="btn btn-sm btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="delete_announcement" class="btn btn-sm btn-danger px-4"><i class="bi bi-trash me-1"></i> Yes, Delete</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Tab switching
        document.querySelectorAll('.content-tab-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.content-tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.tab-pane-custom').forEach(p => p.classList.remove('active'));
                btn.classList.add('active');
                document.getElementById(btn.dataset.tab).classList.add('active');
            });
        });

        // Application / Program Settings — filter rows by committee
        const programSettingsCommitteeFilter = document.getElementById('programSettingsCommitteeFilter');
        if (programSettingsCommitteeFilter) {
            programSettingsCommitteeFilter.addEventListener('change', function() {
                const val = this.value;
                document.querySelectorAll('#programSettingsTableBody tr[data-committee]').forEach(function(row) {
                    row.style.display = (val === 'all' || row.dataset.committee === val) ? '' : 'none';
                });
            });
        }

        // Forms tab — Committee -> Program picker that jumps to that program's field editor
        const formsByCommittee = <?php echo json_encode($formsByCommittee); ?>;
        const formsCommitteeSelect = document.getElementById('formsCommitteeSelect');
        const formsProgramSelect = document.getElementById('formsProgramSelect');
        const formsOpenBtn = document.getElementById('formsOpenBtn');

        function disableFormsOpenBtn() {
            formsOpenBtn.href = '#';
            formsOpenBtn.style.pointerEvents = 'none';
            formsOpenBtn.style.opacity = '0.5';
        }

        if (formsCommitteeSelect) {
            formsCommitteeSelect.addEventListener('change', function() {
                const forms = formsByCommittee[this.value] || [];
                formsProgramSelect.innerHTML = '<option value="" disabled selected>Select a form</option>';
                forms.forEach(function(f) {
                    const opt = document.createElement('option');
                    opt.value = f.url;
                    opt.textContent = f.label;
                    formsProgramSelect.appendChild(opt);
                });
                formsProgramSelect.disabled = forms.length === 0;
                disableFormsOpenBtn();
            });

            formsProgramSelect.addEventListener('change', function() {
                if (this.value) {
                    formsOpenBtn.href = this.value;
                    formsOpenBtn.style.pointerEvents = 'auto';
                    formsOpenBtn.style.opacity = '1';
                } else {
                    disableFormsOpenBtn();
                }
            });
        }
    </script>
</body>

</html>