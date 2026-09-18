<?php
require_once __DIR__ . '/../config/forms.php';
requireRole('admin');

$me = currentUser();

function slugifyCode($name)
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    return trim($slug, '_');
}

// ---- POST handlers (redirect-after-POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // -- Committees --
    if (isset($_POST['add_committee'])) {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $icon = trim($_POST['icon'] ?? 'bi-people-fill');
        if ($name === '') {
            setFlash('error', 'Committee name is required.');
        } else {
            $code = slugifyCode($name);
            $stmt = $conn->prepare("INSERT INTO committees (code, name, description, icon) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('ssss', $code, $name, $description, $icon);
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();

            // Every assistance application form needs a way to say whether the request is for
            // cash or in-kind assistance — seed it here so a new committee never launches without
            // it (and never gets it set up as some other custom set of options by mistake).
            $atLabel = 'Type of Assistance';
            $atKey = 'assistance_type';
            $atOptions = json_encode(['Cash Assistance', 'In-kind Assistance']);
            $stmt = $conn->prepare("INSERT INTO form_fields (committee_id, program_track, label, field_key, input_type, icon, width, is_required, options, sort_order) VALUES (?, 'assistance', ?, ?, 'radio', 'bi-cash-coin', 'full', 1, ?, 1)");
            $stmt->bind_param('isss', $newId, $atLabel, $atKey, $atOptions);
            $stmt->execute();
            $stmt->close();

            logAudit('Added Committee', $name . ' (#' . $newId . ')');
            setFlash('success', 'Committee added.');
        }
    }

    if (isset($_POST['edit_committee'])) {
        $committeeId = (int)$_POST['committee_id'];
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $icon = trim($_POST['icon'] ?? 'bi-people-fill');
        if ($name === '') {
            setFlash('error', 'Committee name is required.');
        } else {
            $stmt = $conn->prepare("UPDATE committees SET name = ?, description = ?, icon = ? WHERE committee_id = ?");
            $stmt->bind_param('sssi', $name, $description, $icon, $committeeId);
            $stmt->execute();
            $stmt->close();
            logAudit('Updated Committee', $name . ' (#' . $committeeId . ')');
            setFlash('success', 'Committee updated.');
        }
    }

    if (isset($_POST['archive_committee'])) {
        $committeeId = (int)$_POST['committee_id'];
        $stmt = $conn->prepare("UPDATE committees SET archived_at = NOW() WHERE committee_id = ?");
        $stmt->bind_param('i', $committeeId);
        $stmt->execute();
        $stmt->close();
        logAudit('Archived Committee', 'Committee #' . $committeeId);
        setFlash('success', 'Committee archived.');
    }

    if (isset($_POST['restore_committee'])) {
        $committeeId = (int)$_POST['committee_id'];
        $stmt = $conn->prepare("UPDATE committees SET archived_at = NULL WHERE committee_id = ?");
        $stmt->bind_param('i', $committeeId);
        $stmt->execute();
        $stmt->close();
        logAudit('Restored Committee', 'Committee #' . $committeeId);
        setFlash('success', 'Committee restored.');
    }

    // -- Programs --
    if (isset($_POST['add_program']) || isset($_POST['edit_program'])) {
        $isEdit = isset($_POST['edit_program']);
        $committeeId = (int)$_POST['committee_id'];
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $selectedTypes = (array)($_POST['assistance_type'] ?? []);
        $hasCash = in_array('cash', $selectedTypes, true);
        $hasInKind = in_array('in_kind', $selectedTypes, true);
        $assistanceType = ($hasCash && $hasInKind) ? 'both' : ($hasInKind ? 'in_kind' : 'cash');
        $amount = $_POST['amount'] !== '' ? (float)$_POST['amount'] : null;
        $releaseSchedule = trim($_POST['release_schedule'] ?? '');
        $appStart = $_POST['app_start_date'] !== '' ? $_POST['app_start_date'] : null;
        $appEnd = $_POST['app_end_date'] !== '' ? $_POST['app_end_date'] : null;
        $eligibility = trim($_POST['eligibility_requirements'] ?? '');
        $status = isset($_POST['status_active']) ? 'active' : 'inactive';

        if ($name === '' || $committeeId <= 0) {
            setFlash('error', 'Program name and committee are required.');
        } elseif ($isEdit) {
            $programId = (int)$_POST['program_id'];
            $stmt = $conn->prepare("UPDATE programs SET committee_id = ?, name = ?, description = ?, assistance_type = ?, amount = ?, release_schedule = ?, app_start_date = ?, app_end_date = ?, eligibility_requirements = ?, status = ? WHERE program_id = ?");
            $stmt->bind_param('isssdsssssi', $committeeId, $name, $description, $assistanceType, $amount, $releaseSchedule, $appStart, $appEnd, $eligibility, $status, $programId);
            $stmt->execute();
            $stmt->close();
            syncProgramTab($programId, $committeeId, $name, $status === 'active');
            logAudit('Updated Program', $name . ' (#' . $programId . ')');
            setFlash('success', 'Program updated.');
        } else {
            $stmt = $conn->prepare("INSERT INTO programs (committee_id, name, description, assistance_type, amount, release_schedule, app_start_date, app_end_date, eligibility_requirements, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('isssdsssss', $committeeId, $name, $description, $assistanceType, $amount, $releaseSchedule, $appStart, $appEnd, $eligibility, $status);
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();
            syncProgramTab($newId, $committeeId, $name, $status === 'active');
            logAudit('Added Program', $name . ' (#' . $newId . ')');
            setFlash('success', 'Program added.');
        }
    }

    if (isset($_POST['toggle_program_status'])) {
        $programId = (int)$_POST['program_id'];
        $newStatus = $_POST['new_status'] === 'active' ? 'active' : 'inactive';
        $stmt = $conn->prepare("UPDATE programs SET status = ? WHERE program_id = ?");
        $stmt->bind_param('si', $newStatus, $programId);
        $stmt->execute();
        $stmt->close();
        setProgramTabVisible($programId, $newStatus === 'active');
        logAudit('Changed Program Status', 'Program #' . $programId . ' -> ' . $newStatus);
        setFlash('success', 'Program status updated.');
    }

    if (isset($_POST['archive_program'])) {
        $programId = (int)$_POST['program_id'];
        $stmt = $conn->prepare("UPDATE programs SET archived_at = NOW() WHERE program_id = ?");
        $stmt->bind_param('i', $programId);
        $stmt->execute();
        $stmt->close();
        setProgramTabVisible($programId, false);
        logAudit('Archived Program', 'Program #' . $programId);
        setFlash('success', 'Program archived.');
    }

    if (isset($_POST['restore_program'])) {
        $programId = (int)$_POST['program_id'];
        $stmt = $conn->prepare("UPDATE programs SET archived_at = NULL WHERE program_id = ?");
        $stmt->bind_param('i', $programId);
        $stmt->execute();
        $stmt->close();
        $restoredStatus = $conn->query("SELECT status FROM programs WHERE program_id = " . (int)$programId)->fetch_assoc()['status'] ?? 'inactive';
        setProgramTabVisible($programId, $restoredStatus === 'active');
        logAudit('Restored Program', 'Program #' . $programId);
        setFlash('success', 'Program restored.');
    }

    // -- Announcements (the only place in the admin that can post one — Configuration's old
    // "Announcements" section was a second, more limited copy of this same feature and has been
    // removed in favor of this single one) --
    if (isset($_POST['save_announcement'])) {
        $announcementId = (int)($_POST['announcement_id'] ?? 0);
        $committeeId = $_POST['committee_id'] !== '' ? (int)$_POST['committee_id'] : null;
        $title = trim($_POST['title'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $eventDate = !empty($_POST['event_date']) ? $_POST['event_date'] : null;
        $eventTime = !empty($_POST['event_time']) ? $_POST['event_time'] : null;
        $eventWhere = trim($_POST['event_where'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $sentTo = in_array($_POST['sent_to'] ?? 'all', ['all', 'scholars', 'applicants', 'specific'], true) ? $_POST['sent_to'] : 'all';
        $specificTarget = trim($_POST['specific_target'] ?? '');

        if ($title === '' || $message === '') {
            setFlash('error', 'Title and message are required.');
        } elseif ($announcementId > 0) {
            $stmt = $conn->prepare("UPDATE announcements SET committee_id = ?, title = ?, message = ?, event_date = ?, event_time = ?, event_where = ?, notes = ?, sent_to = ?, specific_target = ? WHERE announcement_id = ?");
            $stmt->bind_param('i' . str_repeat('s', 8) . 'i', $committeeId, $title, $message, $eventDate, $eventTime, $eventWhere, $notes, $sentTo, $specificTarget, $announcementId);
            $stmt->execute();
            $stmt->close();
            logAudit('Updated Announcement', $title . ' (#' . $announcementId . ')');
            setFlash('success', 'Announcement updated.');
        } else {
            $stmt = $conn->prepare("INSERT INTO announcements (committee_id, title, message, event_date, event_time, event_where, notes, sent_to, specific_target, posted_by, posted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param('issssssssi', $committeeId, $title, $message, $eventDate, $eventTime, $eventWhere, $notes, $sentTo, $specificTarget, $me['user_id']);
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();
            logAudit('Posted Announcement', $title . ' (#' . $newId . ')');
            setFlash('success', 'Announcement posted.');
        }
    }

    if (isset($_POST['archive_announcement'])) {
        $announcementId = (int)$_POST['announcement_id'];
        $stmt = $conn->prepare("UPDATE announcements SET archived_at = NOW() WHERE announcement_id = ?");
        $stmt->bind_param('i', $announcementId);
        $stmt->execute();
        $stmt->close();
        logAudit('Archived Announcement', 'Announcement #' . $announcementId);
        setFlash('success', 'Announcement archived.');
    }

    if (isset($_POST['restore_announcement'])) {
        $announcementId = (int)$_POST['announcement_id'];
        $stmt = $conn->prepare("UPDATE announcements SET archived_at = NULL WHERE announcement_id = ?");
        $stmt->bind_param('i', $announcementId);
        $stmt->execute();
        $stmt->close();
        logAudit('Restored Announcement', 'Announcement #' . $announcementId);
        setFlash('success', 'Announcement restored.');
    }

    // -- Site Settings (this tab owns: site_name, tagline, welcome_message, contact_number, email, sk_office_address, facebook_url, allow_public_applications, show_announcements, current_academic_year, current_semester, requirements_deadline, requirements_open) --
    if (isset($_POST['save_site_settings'])) {
        $siteName = trim($_POST['site_name'] ?? '');
        $tagline = trim($_POST['tagline'] ?? '');
        $welcomeMessage = trim($_POST['welcome_message'] ?? '');
        $contactNumber = trim($_POST['contact_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $officeAddress = trim($_POST['sk_office_address'] ?? '');
        $facebookUrl = trim($_POST['facebook_url'] ?? '');
        $allowPublicApplications = isset($_POST['allow_public_applications']) ? 1 : 0;
        $showAnnouncements = isset($_POST['show_announcements']) ? 1 : 0;
        $academicYear = trim($_POST['current_academic_year'] ?? '');
        $semester = $_POST['current_semester'] ?? '';
        $requirementsDeadlineInput = trim($_POST['requirements_deadline'] ?? '');
        $requirementsDeadline = $requirementsDeadlineInput !== '' ? $requirementsDeadlineInput : null;
        $requirementsOpen = isset($_POST['requirements_open']) ? 1 : 0;

        if (!preg_match('/^\d{4}-\d{4}$/', $academicYear)) {
            setFlash('error', 'Academic Year must be in the form YYYY-YYYY, e.g. 2026-2027.');
        } elseif (!in_array($semester, ['1st Semester', '2nd Semester', '3rd Semester', 'Summer'], true)) {
            setFlash('error', 'Invalid semester.');
        } elseif ($requirementsDeadline !== null && !DateTime::createFromFormat('Y-m-d', $requirementsDeadline)) {
            setFlash('error', 'Invalid requirements deadline date.');
        } else {
            $stmt = $conn->prepare("UPDATE site_settings SET site_name = ?, tagline = ?, welcome_message = ?, contact_number = ?, email = ?, sk_office_address = ?, facebook_url = ?, allow_public_applications = ?, show_announcements = ?, current_academic_year = ?, current_semester = ?, requirements_deadline = ?, requirements_open = ? WHERE id = 1");
            $stmt->bind_param('sssssssiisssi', $siteName, $tagline, $welcomeMessage, $contactNumber, $email, $officeAddress, $facebookUrl, $allowPublicApplications, $showAnnouncements, $academicYear, $semester, $requirementsDeadline, $requirementsOpen);
            $stmt->execute();
            $stmt->close();
            logAudit('Updated Site Settings', 'General / contact / preferences / term: ' . $academicYear . ' ' . $semester);
            setFlash('success', 'Site settings saved.');
        }
    }

    // -- Program tabs (sidebar labels/visibility, e.g. Education's "iSKolar" vs "Assistance Program") --
    if (isset($_POST['save_tabs'])) {
        $tabs = $_POST['tabs'] ?? [];
        foreach ($tabs as $tabId => $data) {
            $tabId = (int)$tabId;
            $label = trim($data['label'] ?? '');
            $icon = trim($data['icon'] ?? 'bi-hand-holding-heart-fill');
            $visible = isset($data['visible']) ? 1 : 0;
            if ($label === '') {
                continue;
            }
            $stmt = $conn->prepare("UPDATE program_tabs SET label = ?, icon = ?, is_visible = ? WHERE tab_id = ?");
            $stmt->bind_param('ssii', $label, $icon, $visible, $tabId);
            $stmt->execute();
            $stmt->close();
        }
        logAudit('Updated Program Tabs', 'Sidebar tab labels/visibility changed');
        setFlash('success', 'Tabs updated.');
    }

    header("Location: AdminProgramManagement.php");
    exit();
}

$pageError = getFlash('error');
$pageSuccess = getFlash('success');

// ---- Data for display ----
$committees = $conn->query("SELECT * FROM committees WHERE archived_at IS NULL ORDER BY committee_id ASC")->fetch_all(MYSQLI_ASSOC);
$archivedCommittees = $conn->query("SELECT * FROM committees WHERE archived_at IS NOT NULL ORDER BY archived_at DESC")->fetch_all(MYSQLI_ASSOC);

$tabsByCommittee = [];
foreach ($conn->query("SELECT * FROM program_tabs ORDER BY committee_id ASC, sort_order ASC") as $t) {
    $tabsByCommittee[(int)$t['committee_id']][] = $t;
}

$programsResult = $conn->query("SELECT * FROM programs WHERE archived_at IS NULL ORDER BY committee_id ASC, program_id ASC");
$allPrograms = $programsResult->fetch_all(MYSQLI_ASSOC);

// beneficiary count per program = approved applications tied to that program
$beneficiaryCounts = [];
$bcResult = $conn->query("SELECT program_id, COUNT(*) AS cnt FROM applications WHERE status = 'approved' AND program_id IS NOT NULL GROUP BY program_id");
foreach ($bcResult as $row) {
    $beneficiaryCounts[(int)$row['program_id']] = (int)$row['cnt'];
}

$programsByCommittee = [];
foreach ($allPrograms as $p) {
    $programsByCommittee[(int)$p['committee_id']][] = $p;
}

$archivedPrograms = $conn->query("SELECT p.*, c.name AS committee_name FROM programs p LEFT JOIN committees c ON c.committee_id = p.committee_id WHERE p.archived_at IS NOT NULL ORDER BY p.archived_at DESC")->fetch_all(MYSQLI_ASSOC);

$announcements = $conn->query("SELECT a.*, c.name AS committee_name, c.icon AS committee_icon FROM announcements a LEFT JOIN committees c ON c.committee_id = a.committee_id WHERE a.archived_at IS NULL ORDER BY a.announcement_id ASC")->fetch_all(MYSQLI_ASSOC);
$announcementsByGroup = []; // key: committee_id or 'general'
foreach ($announcements as $a) {
    $key = $a['committee_id'] !== null ? (int)$a['committee_id'] : 'general';
    $announcementsByGroup[$key][] = $a;
}

$archivedAnnouncements = $conn->query("SELECT a.*, c.name AS committee_name FROM announcements a LEFT JOIN committees c ON c.committee_id = a.committee_id WHERE a.archived_at IS NOT NULL ORDER BY a.archived_at DESC")->fetch_all(MYSQLI_ASSOC);

$settings = $conn->query("SELECT * FROM site_settings WHERE id = 1")->fetch_assoc();

// Built-in tracks (program_tabs rows with no program_id) count as programs too, matching the
// per-committee "X programs" badge below — otherwise these stats undercount whenever a committee
// has no catalog programs of its own yet.
$statBuiltInTotal = 0;
$statBuiltInActive = 0;
foreach ($committees as $c) {
    foreach (($tabsByCommittee[(int)$c['committee_id']] ?? []) as $t) {
        if (empty($t['program_id'])) {
            $statBuiltInTotal++;
            if (!empty($t['is_visible'])) {
                $statBuiltInActive++;
            }
        }
    }
}

$statCommittees = count($committees);
$statTotalPrograms = count($allPrograms) + $statBuiltInTotal;
$statActivePrograms = count(array_filter($allPrograms, fn($p) => $p['status'] === 'active')) + $statBuiltInActive;
$statAnnouncements = count($announcements);

$sendToLabel = ['all' => 'All Users', 'scholars' => 'Scholars Only', 'applicants' => 'Applicants Only', 'specific' => 'Specific User'];

function assistLabel($type)
{
    if ($type === 'both') return 'Cash Assistance + In-Kind Assistance';
    return $type === 'in_kind' ? 'In-Kind Assistance' : 'Cash Assistance';
}

function assistBadgeClass($type)
{
    return $type === 'in_kind' ? 'assist-kind' : 'assist-cash';
}

// One badge per type a program actually offers — a 'both' program gets two badges
// (Cash + In-Kind) side by side instead of a single combined label.
function assistBadges($type)
{
    if ($type === 'both') {
        return [['cash', 'Cash Assistance'], ['in_kind', 'In-Kind Assistance']];
    }
    return $type === 'in_kind' ? [['in_kind', 'In-Kind Assistance']] : [['cash', 'Cash Assistance']];
}

$committeeIconChoices = ['bi-people-fill', 'bi-mortarboard-fill', 'bi-heart-pulse-fill', 'bi-trophy-fill', 'bi-flag-fill', 'bi-tree-fill', 'bi-palette-fill', 'bi-shield-fill-check', 'bi-music-note-beamed', 'bi-globe2', 'bi-star-fill', 'bi-bank', 'bi-briefcase-fill', 'bi-currency-exchange'];

$activeLink = 'AdminProgramManagement';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Content Management</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo APP_BASE; ?>/assets/css/admin.css" rel="stylesheet">

    <style>
        .page-title {
            font-size: 22px;
            font-weight: 700;
            color: #1a1a1a;
            margin-bottom: 2px;
        }

        .page-subtitle {
            font-size: 13px;
            color: #6b6b6b;
        }

        .stat-card {
            border: 2px solid #45b84d;
            border-radius: 10px;
            padding: 14px 18px;
            background: #fff;
        }

        .stat-card .label {
            font-size: 11px;
            font-weight: 700;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card .value {
            font-size: 26px;
            font-weight: 700;
            color: #1a1a1a;
            line-height: 1.2;
        }

        .btn-brand {
            background-color: #45b84d;
            color: #fff;
            font-weight: 600;
            font-size: 13.5px;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
        }

        .btn-brand:hover {
            background-color: #3ca644;
            color: #fff;
        }

        .btn-outline-brand {
            background: #fff;
            color: #2e7d32;
            border: 1.5px solid #45b84d;
            font-weight: 600;
            font-size: 13.5px;
            padding: 8px 16px;
            border-radius: 6px;
        }

        .btn-outline-brand:hover {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            padding-left: 34px;
            border-radius: 20px;
            font-size: 13px;
        }

        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
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

        .committee-block {
            background: #fff;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            margin-bottom: 18px;
            overflow: hidden;
        }

        .committee-block-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 14px 20px;
            background: #f7fbf7;
            border-bottom: 1px solid #eee;
            flex-wrap: wrap;
        }

        .committee-block-header .title {
            font-weight: 700;
            font-size: 15px;
            color: #1a1a1a;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .committee-icon-badge {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            background: #45b84d;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        .committee-count-badge {
            font-size: 11.5px;
            font-weight: 700;
            background: #e8f5e9;
            color: #2e7d32;
            border-radius: 20px;
            padding: 2px 10px;
        }

        .committee-block-body {
            padding: 14px 20px;
        }

        .program-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            padding: 12px 14px;
            border: 1px solid #ededed;
            border-radius: 8px;
            background: #fcfcfc;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }

        .program-item:last-child {
            margin-bottom: 0;
        }

        .program-item .p-name {
            font-weight: 700;
            font-size: 13.5px;
            color: #1a1a1a;
        }

        .program-item .p-meta {
            font-size: 12px;
            color: #777;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 3px;
        }

        .program-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .assist-tag {
            font-size: 11.5px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 20px;
            white-space: nowrap;
        }

        .assist-cash {
            background: #e3f2fd;
            color: #1565c0;
            border: 1px solid #90caf9;
        }

        .assist-kind {
            background: #fff3e0;
            color: #e65100;
            border: 1px solid #ffcc80;
        }

        .status-pill {
            font-size: 11.5px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-block;
            white-space: nowrap;
        }

        .status-active {
            background-color: #45b84d;
            color: #fff;
        }

        .status-inactive {
            background-color: #e0e0e0;
            color: #616161;
        }

        .action-btn {
            font-size: 11.5px;
            font-weight: 600;
            padding: 5px 9px;
            border-radius: 5px;
            border: none;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        .btn-view {
            background: #e3f2fd;
            color: #1565c0;
        }

        .btn-edit {
            background: #fff3e0;
            color: #e65100;
        }

        .btn-toggle {
            background: #fdecea;
            color: #c62828;
        }

        .btn-archive-program {
            background: #fdecea;
            color: #c62828;
        }

        .btn-activate {
            background: #45b84d;
            color: #fff;
        }

        .empty-committee {
            border: 1.5px dashed #c8e6c9;
            border-radius: 8px;
            padding: 18px;
            text-align: center;
            color: #8a8a8a;
            font-size: 12.5px;
            background: #fafcfa;
        }

        .builtin-tab-item {
            border-color: #d8cdf2;
            background: #faf8ff;
        }

        /* Announcement view-modal preview (matches EducationAnnouncement.php's own copy of this) */
        .ann-preview {
            background: #e8f5e9;
            border-left: 4px solid #45b84d;
            border-radius: 6px;
            padding: 14px 16px;
        }

        .ann-title {
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 6px;
        }

        .ann-body {
            font-size: 13px;
            color: #444;
            margin-bottom: 8px;
        }

        .ann-detail {
            font-size: 12px;
            color: #333;
            margin-bottom: 2px;
        }

        .ann-meta {
            font-size: 11px;
            color: #888;
            margin-top: 6px;
        }

        .builtin-tag {
            font-size: 11.5px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 20px;
            white-space: nowrap;
            background: #ede7f6;
            color: #5e35b1;
            border: 1px solid #b39ddb;
        }

        .announcement-card {
            border: 1px solid #ededed;
            border-radius: 8px;
            background: #fcfcfc;
            padding: 12px 14px;
            margin-bottom: 10px;
        }

        .announcement-card:last-child {
            margin-bottom: 0;
        }

        .announcement-card .a-title {
            font-weight: 700;
            font-size: 13.5px;
            color: #1a1a1a;
        }

        .announcement-card .a-msg {
            font-size: 12.5px;
            color: #555;
            margin-top: 4px;
        }

        .announcement-card .a-meta {
            font-size: 11.5px;
            color: #999;
            margin-top: 6px;
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .filter-bar select,
        .filter-bar input {
            font-size: 13px;
            border-radius: 6px;
        }

        .form-label {
            font-size: 13px;
            font-weight: 600;
            color: #333;
        }

        .form-control,
        .form-select {
            font-size: 13.5px;
        }

        .icon-choice {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            border: 1.5px solid #ddd;
            background: #fff;
            color: #45b84d;
            font-size: 17px;
            cursor: pointer;
        }

        .icon-choice.selected {
            border-color: #45b84d;
            background: #e8f5e9;
        }

        .settings-card {
            background: #fff;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            padding: 22px;
            max-width: 720px;
            margin: 0 auto;
        }

        .settings-section-title {
            font-size: 13.5px;
            font-weight: 700;
            color: #2e7d32;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin: 22px 0 12px;
        }

        .settings-section-title:first-child {
            margin-top: 0;
        }

        @media (max-width: 576px) {
            .page-title {
                font-size: 19px;
            }

            .stat-card .value {
                font-size: 22px;
            }

            .program-item {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>

<body>

    <?php include __DIR__ . '/../includes/adminsidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">

        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
            <div>
                <div class="page-title">Content Management</div>
                <div class="page-subtitle">Manage committees, programs, and announcements shown across the site.</div>
            </div>
        </div>

        <?php if ($pageSuccess): ?><div class="alert alert-success py-2"><?php echo e($pageSuccess); ?></div><?php endif; ?>
        <?php if ($pageError): ?><div class="alert alert-danger py-2"><?php echo e($pageError); ?></div><?php endif; ?>

        <!-- Stat Cards -->
        <div class="row g-3 mb-3">
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="label">Committees</div>
                    <div class="value"><?php echo $statCommittees; ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="label">Total Programs</div>
                    <div class="value"><?php echo $statTotalPrograms; ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="label">Active Programs</div>
                    <div class="value"><?php echo $statActivePrograms; ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="label">Announcements</div>
                    <div class="value"><?php echo $statAnnouncements; ?></div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="content-tabs">
            <button class="content-tab-btn active" data-tab="programsTab"><i class="bi bi-diagram-3-fill"></i> Committees &amp; Programs</button>
            <button class="content-tab-btn" data-tab="announcementsTab"><i class="bi bi-bell-fill"></i> Announcements</button>
            <button class="content-tab-btn" data-tab="settingsTab"><i class="bi bi-gear-fill"></i> Site Settings</button>
        </div>

        <!-- TAB: Committees & Programs -->
        <div class="tab-pane-custom active" id="programsTab">

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn-brand" data-bs-toggle="modal" data-bs-target="#addCommitteeModal"><i class="bi bi-plus-lg me-1"></i> Add Committee</button>
                    <button class="btn-outline-brand" data-bs-toggle="modal" data-bs-target="#addProgramModal"><i class="bi bi-plus-lg me-1"></i> Add Program</button>
                    <button class="btn-outline-brand" data-bs-toggle="modal" data-bs-target="#archivesProgramModal"><i class="bi bi-archive me-1"></i> Program Archives</button>
                    <button class="btn-outline-brand" data-bs-toggle="modal" data-bs-target="#archivesCommitteeModal"><i class="bi bi-archive me-1"></i> Committee Archives</button>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <select class="form-select form-select-sm" id="programFilterSelect" style="width:auto;">
                        <option value="all">Filter: All Programs</option>
                        <option value="status:active">Status: Active</option>
                        <option value="status:inactive">Status: Inactive</option>
                        <option value="assist:Cash Assistance">Type: Cash Assistance</option>
                        <option value="assist:In-Kind Assistance">Type: In-Kind Assistance</option>
                    </select>
                    <select class="form-select form-select-sm" id="programSortSelect" style="width:auto;">
                        <option value="id_asc">Sort By: ID (Ascending)</option>
                        <option value="id_desc">Sort By: ID (Descending)</option>
                        <option value="name_asc">Sort By: Name (A-Z)</option>
                        <option value="name_desc">Sort By: Name (Z-A)</option>
                        <option value="date_desc">Sort By: Date Added (Newest)</option>
                        <option value="date_asc">Sort By: Date Added (Oldest)</option>
                    </select>
                    <div class="search-box">
                        <i class="bi bi-search"></i>
                        <input type="text" class="form-control" id="programSearchInput" placeholder="Search program...">
                    </div>
                </div>
            </div>

            <!-- Committee Sections -->
            <div id="committeeSectionsContainer">
                <?php foreach ($committees as $c): $cid = (int)$c['committee_id'];
                    $cprograms = $programsByCommittee[$cid] ?? [];
                    $builtInTabs = array_filter($tabsByCommittee[$cid] ?? [], function ($t) {
                        return empty($t['program_id']);
                    });
                    $totalProgramCount = count($cprograms) + count($builtInTabs);
                ?>
                    <div class="committee-block" data-committee-name="<?php echo e(strtolower($c['name'])); ?>">
                        <div class="committee-block-header">
                            <div class="title">
                                <span class="committee-icon-badge"><i class="bi <?php echo e($c['icon'] ?: 'bi-people-fill'); ?>"></i></span>
                                <?php echo e($c['name']); ?>
                                <span class="committee-count-badge"><?php echo $totalProgramCount; ?> program<?php echo $totalProgramCount === 1 ? '' : 's'; ?></span>
                                <button type="button" class="action-btn btn-edit" data-bs-toggle="modal" data-bs-target="#editCommitteeModal<?php echo $cid; ?>"><i class="bi bi-pencil"></i> Edit</button>
                                <?php if (!empty($tabsByCommittee[$cid])): ?>
                                    <button type="button" class="action-btn btn-edit" data-bs-toggle="modal" data-bs-target="#manageTabsModal<?php echo $cid; ?>"><i class="bi bi-layout-sidebar-inset"></i> Manage Tabs</button>
                                <?php endif; ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Archive this committee? Its programs will stay as-is, but the committee will be hidden from this list.');">
                                    <input type="hidden" name="committee_id" value="<?php echo $cid; ?>">
                                    <button type="submit" name="archive_committee" class="action-btn btn-archive-program"><i class="bi bi-archive"></i> Archive</button>
                                </form>
                            </div>
                            <button type="button" class="btn-outline-brand add-program-for-committee" data-committee="<?php echo $cid; ?>"><i class="bi bi-plus-lg me-1"></i> Add Program</button>
                        </div>
                        <div class="committee-block-body">
                            <?php foreach ($builtInTabs as $t): $tid = (int)$t['tab_id'];
                                $trackBenCount = getApprovedCount($cid, $t['track_code']); ?>
                                <div class="program-item builtin-tab-item">
                                    <div>
                                        <div class="p-name"><i class="bi <?php echo e($t['icon']); ?> me-1"></i><?php echo e($t['label']); ?></div>
                                        <div class="p-meta">
                                            <span class="builtin-tag">Built-in Track</span>
                                            <span class="status-pill <?php echo $t['is_visible'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $t['is_visible'] ? 'Shown' : 'Hidden'; ?></span>
                                            <span><i class="bi bi-people me-1"></i><?php echo $trackBenCount; ?> beneficiar<?php echo $trackBenCount === 1 ? 'y' : 'ies'; ?></span>
                                            <?php if (!empty($t['max_slots'])): ?>
                                                <span title="Slot limit"><i class="bi bi-clipboard-check me-1"></i><?php echo $trackBenCount; ?> / <?php echo (int)$t['max_slots']; ?> slots</span>
                                            <?php else: ?>
                                                <span class="text-muted" style="font-size:12px;"><i class="bi bi-infinity me-1"></i>Unlimited slots</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="program-actions">
                                        <button type="button" class="action-btn btn-edit" data-bs-toggle="modal" data-bs-target="#manageTabsModal<?php echo $cid; ?>"><i class="bi bi-pencil"></i> Manage</button>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="tabs[<?php echo $tid; ?>][label]" value="<?php echo e($t['label']); ?>">
                                            <input type="hidden" name="tabs[<?php echo $tid; ?>][icon]" value="<?php echo e($t['icon']); ?>">
                                            <?php if (!$t['is_visible']): ?><input type="hidden" name="tabs[<?php echo $tid; ?>][visible]" value="1"><?php endif; ?>
                                            <button type="submit" name="save_tabs" class="action-btn <?php echo $t['is_visible'] ? 'btn-toggle' : 'btn-activate'; ?>">
                                                <i class="bi <?php echo $t['is_visible'] ? 'bi-slash-circle' : 'bi-check-circle'; ?>"></i>
                                                <?php echo $t['is_visible'] ? 'Hide' : 'Show'; ?>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($cprograms)): ?>
                                <div class="empty-committee">No financial assistance programs yet for <?php echo e($c['name']); ?>.</div>
                            <?php endif; ?>
                            <?php foreach ($cprograms as $p): $pid = (int)$p['program_id'];
                                $benCount = $beneficiaryCounts[$pid] ?? 0;
                                $closedReason = programClosedReason($p);
                                $closedState = programClosedState($p);
                                $hasWindow = !empty($p['app_start_date']) || !empty($p['app_end_date']);
                            ?>
                                <div class="program-item" data-program-id="<?php echo $pid; ?>" data-program-name="<?php echo e(strtolower($p['name'])); ?>" data-status="<?php echo e(ucfirst($p['status'])); ?>" data-assist="<?php echo e(implode(',', array_column(assistBadges($p['assistance_type']), 1))); ?>" data-created="<?php echo $p['created_at'] ? strtotime($p['created_at']) : 0; ?>">
                                    <div>
                                        <div class="p-name">#<?php echo $pid; ?> — <?php echo e($p['name']); ?></div>
                                        <div class="p-meta">
                                            <?php foreach (assistBadges($p['assistance_type']) as [$badgeType, $badgeLabel]): ?>
                                                <span class="assist-tag <?php echo assistBadgeClass($badgeType); ?>"><?php echo e($badgeLabel); ?></span>
                                            <?php endforeach; ?>
                                            <span><i class="bi bi-people me-1"></i><?php echo $benCount; ?> beneficiaries</span>
                                            <?php if ($hasWindow): ?>
                                                <span title="Application period"><i class="bi bi-calendar-range me-1"></i><?php
                                                                                                                            echo $p['app_start_date'] ? date('M j, Y', strtotime($p['app_start_date'])) : 'Anytime';
                                                                                                                            echo ' – ';
                                                                                                                            echo $p['app_end_date'] ? date('M j, Y', strtotime($p['app_end_date'])) : 'No end date';
                                                                                                                            ?></span>
                                            <?php else: ?>
                                                <span><i class="bi bi-calendar3 me-1"></i><?php echo $p['created_at'] ? date('M j, Y', strtotime($p['created_at'])) : '—'; ?></span>
                                            <?php endif; ?>
                                            <span class="status-pill <?php echo $p['status'] === 'active' ? 'status-active' : 'status-inactive'; ?>"><?php echo ucfirst($p['status']); ?></span>
                                            <?php if ($closedReason && $p['status'] === 'active'): ?>
                                                <span class="status-pill status-inactive" title="<?php echo e($closedReason); ?>"><i class="bi bi-lock-fill me-1"></i><?php echo $closedState === 'not_open' ? 'Not Yet Open' : 'Closed'; ?></span>
                                            <?php elseif ($hasWindow && $p['status'] === 'active'): ?>
                                                <span class="status-pill status-active"><i class="bi bi-unlock-fill me-1"></i>Open</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="program-actions">
                                        <button type="button" class="action-btn btn-view" data-bs-toggle="modal" data-bs-target="#viewProgramModal<?php echo $pid; ?>"><i class="bi bi-eye"></i> View</button>
                                        <button type="button" class="action-btn btn-edit" data-bs-toggle="modal" data-bs-target="#editProgramModal<?php echo $pid; ?>"><i class="bi bi-pencil"></i> Edit</button>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="program_id" value="<?php echo $pid; ?>">
                                            <input type="hidden" name="new_status" value="<?php echo $p['status'] === 'active' ? 'inactive' : 'active'; ?>">
                                            <button type="submit" name="toggle_program_status" class="action-btn <?php echo $p['status'] === 'active' ? 'btn-toggle' : 'btn-activate'; ?>">
                                                <i class="bi <?php echo $p['status'] === 'active' ? 'bi-slash-circle' : 'bi-check-circle'; ?>"></i>
                                                <?php echo $p['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                                            </button>
                                        </form>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Archive this program?');">
                                            <input type="hidden" name="program_id" value="<?php echo $pid; ?>">
                                            <button type="submit" name="archive_program" class="action-btn btn-archive-program"><i class="bi bi-archive"></i> Archive</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if (!empty($tabsByCommittee[$cid])): ?>
                        <!-- MANAGE TABS MODAL -->
                        <div class="modal fade" id="manageTabsModal<?php echo $cid; ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered modal-lg">
                                <div class="modal-content border-0 shadow">
                                    <form method="post">
                                        <div class="modal-header brand-header">
                                            <h5 class="modal-title text-white fw-bold"><i class="bi bi-layout-sidebar-inset me-2"></i> Manage Tabs — <?php echo e($c['name']); ?></h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body p-4">
                                            <p class="text-muted" style="font-size:13px;">These are the sidebar sections shown under <?php echo e($c['name']); ?> on both the admin and applicant sides. Rename a tab or hide it from the sidebar without deleting any data — the underlying pages and applications stay intact either way.</p>
                                            <?php foreach ($tabsByCommittee[$cid] as $t): $tid = (int)$t['tab_id']; ?>
                                                <div class="row g-2 align-items-center mb-3 pb-3" style="border-bottom:1px solid #eee;">
                                                    <div class="col-md-5">
                                                        <label class="form-label" style="font-size:12px; font-weight:600;">Label</label>
                                                        <input type="text" class="form-control form-control-sm" name="tabs[<?php echo $tid; ?>][label]" value="<?php echo e($t['label']); ?>" required>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label" style="font-size:12px; font-weight:600;">Icon <span class="text-muted fw-normal">(Bootstrap Icons class)</span></label>
                                                        <input type="text" class="form-control form-control-sm" name="tabs[<?php echo $tid; ?>][icon]" value="<?php echo e($t['icon']); ?>">
                                                    </div>
                                                    <div class="col-md-3">
                                                        <label class="form-label d-block" style="font-size:12px; font-weight:600;">Visible</label>
                                                        <div class="form-check form-switch">
                                                            <input class="form-check-input" type="checkbox" name="tabs[<?php echo $tid; ?>][visible]" id="tabVisible<?php echo $tid; ?>" <?php echo $t['is_visible'] ? 'checked' : ''; ?>>
                                                            <label class="form-check-label" for="tabVisible<?php echo $tid; ?>" style="font-size:12px;"><?php echo $t['is_visible'] ? 'Shown' : 'Hidden'; ?></label>
                                                        </div>
                                                    </div>
                                                    <div class="col-12">
                                                        <span class="text-muted" style="font-size:11px;"><i class="bi bi-info-circle me-1"></i>Track: <code><?php echo e($t['track_code']); ?></code> — <i class="bi <?php echo e($t['icon']); ?>"></i> preview</span>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn-outline-brand" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="save_tabs" class="btn-brand"><i class="bi bi-save me-1"></i> Save Tabs</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- TAB: Announcements -->
        <div class="tab-pane-custom" id="announcementsTab">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn-brand" onclick="openAddAnnouncement()" data-bs-toggle="modal" data-bs-target="#addAnnouncementModal"><i class="bi bi-plus-lg me-1"></i> Add Announcement</button>
                    <button class="btn-outline-brand" data-bs-toggle="modal" data-bs-target="#archivesAnnouncementModal"><i class="bi bi-archive me-1"></i> Announcement Archives</button>
                </div>
                <div class="search-box">
                    <i class="bi bi-search"></i>
                    <input type="text" class="form-control" id="announcementSearchInput" placeholder="Search announcement...">
                </div>
            </div>

            <div id="announcementsContainer">
                <?php
                // Same per-committee layout as Committees & Programs — every committee gets its own
                // section (even with zero announcements), plus a trailing General section for
                // announcements not tied to any one committee.
                $announcementSections = $committees;
                $announcementSections[] = ['committee_id' => null, 'name' => 'General', 'icon' => 'bi-globe2'];
                foreach ($announcementSections as $c):
                    $cid = $c['committee_id'] !== null ? (int)$c['committee_id'] : null;
                    $groupKey = $cid ?? 'general';
                    $group = $announcementsByGroup[$groupKey] ?? [];
                ?>
                    <div class="committee-block">
                        <div class="committee-block-header">
                            <div class="title">
                                <span class="committee-icon-badge"><i class="bi <?php echo e($c['icon'] ?: 'bi-people-fill'); ?>"></i></span>
                                <?php echo e($c['name']); ?>
                                <span class="committee-count-badge"><?php echo count($group); ?></span>
                            </div>
                            <button type="button" class="btn-outline-brand add-announcement-for-committee" data-committee="<?php echo e($cid ?? ''); ?>"><i class="bi bi-plus-lg me-1"></i> Add Announcement</button>
                        </div>
                        <div class="committee-block-body">
                            <div class="table-card">
                                <div class="table-responsive-wrap">
                                    <table class="table mb-0">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Title</th>
                                                <th>Sent To</th>
                                                <th>Date Posted</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($group)): ?>
                                                <tr>
                                                    <td colspan="5" class="text-center text-muted py-3">No announcements yet for <?php echo e($c['name']); ?>.</td>
                                                </tr>
                                            <?php endif; ?>
                                            <?php foreach ($group as $a): ?>
                                                <tr class="announcement-row" data-announcement-id="<?php echo (int)$a['announcement_id']; ?>" data-title="<?php echo e(strtolower($a['title'])); ?>" data-date="<?php echo strtotime($a['posted_at']); ?>" data-sent-to="<?php echo e($a['sent_to']); ?>">
                                                    <td><?php echo str_pad($a['announcement_id'], 3, '0', STR_PAD_LEFT); ?></td>
                                                    <td><?php echo e($a['title']); ?></td>
                                                    <td><?php echo e($sendToLabel[$a['sent_to']] ?? $a['sent_to']); ?></td>
                                                    <td><?php echo date('F j, Y', strtotime($a['posted_at'])); ?></td>
                                                    <td class="d-flex gap-1">
                                                        <button type="button" class="btn-view" data-bs-toggle="modal" data-bs-target="#viewAnnouncementModal<?php echo $a['announcement_id']; ?>"><i class="bi bi-eye"></i> View</button>
                                                        <button type="button" class="btn-edit" onclick='openEditAnnouncement(<?php echo json_encode($a); ?>)' data-bs-toggle="modal" data-bs-target="#addAnnouncementModal"><i class="bi bi-pencil"></i> Edit</button>
                                                        <form method="post" class="d-inline" onsubmit="return confirm('Archive this announcement? It will no longer be shown to applicants/scholars.');">
                                                            <input type="hidden" name="announcement_id" value="<?php echo $a['announcement_id']; ?>">
                                                            <button type="submit" name="archive_announcement" class="action-btn btn-archive-program"><i class="bi bi-archive"></i> Archive</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- TAB: Site Settings -->
        <div class="tab-pane-custom" id="settingsTab">
            <div class="settings-card">
                <form method="post" id="siteSettingsForm" data-orig-year="<?php echo e($settings['current_academic_year']); ?>" data-orig-semester="<?php echo e($settings['current_semester']); ?>">
                    <div class="settings-section-title">General</div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Site / Barangay Name</label>
                            <input type="text" class="form-control" name="site_name" value="<?php echo e($settings['site_name']); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Tagline</label>
                            <input type="text" class="form-control" name="tagline" value="<?php echo e($settings['tagline']); ?>" placeholder="e.g., Serbisyo Para sa Kabataan">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Welcome / About Message</label>
                            <textarea class="form-control" name="welcome_message" rows="3" placeholder="Short message shown on the public homepage"><?php echo e($settings['welcome_message']); ?></textarea>
                        </div>
                    </div>

                    <div class="settings-section-title">Academic Term</div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Current Academic Year</label>
                            <input type="text" class="form-control" name="current_academic_year" id="settingAcademicYear" value="<?php echo e($settings['current_academic_year']); ?>" placeholder="e.g., 2026-2027" pattern="\d{4}-\d{4}" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Current Semester</label>
                            <select class="form-select" name="current_semester" id="settingSemester" required>
                                <option value="1st Semester" <?php echo $settings['current_semester'] === '1st Semester' ? 'selected' : ''; ?>>1st Semester</option>
                                <option value="2nd Semester" <?php echo $settings['current_semester'] === '2nd Semester' ? 'selected' : ''; ?>>2nd Semester</option>
                                <option value="3rd Semester" <?php echo $settings['current_semester'] === '3rd Semester' ? 'selected' : ''; ?>>3rd Semester</option>
                                <option value="Summer" <?php echo $settings['current_semester'] === 'Summer' ? 'selected' : ''; ?>>Summer</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Requirements Deadline</label>
                            <input type="date" class="form-control" name="requirements_deadline" value="<?php echo e($settings['requirements_deadline']); ?>">
                            <div class="form-text" style="font-size:11.5px;">Cutoff for scholars to submit updated requirements (Barangay Indigency, Grades, etc.) for the current term. Leave blank for no deadline.</div>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" name="requirements_open" id="settingRequirementsOpen" <?php echo $settings['requirements_open'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="settingRequirementsOpen" style="font-size:13.5px;">Scholars can submit updated requirements right now</label>
                            </div>
                            <div class="form-text" style="font-size:11.5px;">Normally turned on automatically by "End Semester" (Scholars page) and off again once the deadline passes. Use this switch to open or close the window by hand.</div>
                        </div>
                        <div class="col-12">
                            <div class="alert alert-warning py-2 mb-0" style="font-size:12.5px;">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i> This is what "iSKolar ng Langkiwa" applications, activities, and allowance distribution treat as the active term. Changing it starts a fresh activity/allowance tracking period for every scholar going forward — past terms' records stay intact and remain visible in Reports.
                            </div>
                        </div>
                    </div>

                    <div class="settings-section-title">Contact Information</div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Contact Number</label>
                            <input type="text" class="form-control" name="contact_number" value="<?php echo e($settings['contact_number']); ?>" placeholder="e.g., 0917 123 4567">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email Address</label>
                            <input type="email" class="form-control" name="email" value="<?php echo e($settings['email']); ?>" placeholder="e.g., sk.langkiwa@email.com">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Office Address</label>
                            <input type="text" class="form-control" name="sk_office_address" value="<?php echo e($settings['sk_office_address']); ?>" placeholder="e.g., Barangay Hall, Langkiwa">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Facebook Page URL</label>
                            <input type="text" class="form-control" name="facebook_url" value="<?php echo e($settings['facebook_url']); ?>" placeholder="https://facebook.com/...">
                        </div>
                    </div>

                    <div class="settings-section-title">Preferences</div>
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" role="switch" name="allow_public_applications" id="settingPublicApplications" <?php echo $settings['allow_public_applications'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="settingPublicApplications" style="font-size:13.5px;">Allow youth to submit applications online</label>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="show_announcements" id="settingShowAnnouncements" <?php echo $settings['show_announcements'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="settingShowAnnouncements" style="font-size:13.5px;">Show announcements on the public homepage</label>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end mt-4 gap-2">
                        <button type="reset" class="btn-outline-brand">Reset</button>
                        <button type="submit" name="save_site_settings" class="btn-brand"><i class="bi bi-check-lg me-1"></i> Save Settings</button>
                    </div>
                </form>
            </div>
        </div>

    </div>

    <!-- Add Committee Modal -->
    <div class="modal fade" id="addCommitteeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:12px; border:none;">
                <form method="post">
                    <div class="modal-header" style="background:linear-gradient(90deg,#45b84d,#aadaad); border-radius:12px 12px 0 0;">
                        <h5 class="modal-title text-white fw-bold"><i class="bi bi-people-fill me-2"></i> Add Committee</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label">Committee Name</label>
                            <input type="text" class="form-control" name="name" placeholder="e.g., Environment Committee" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Short Description</label>
                            <textarea class="form-control" name="description" rows="2" placeholder="What this committee is responsible for"></textarea>
                        </div>
                        <div class="mb-1">
                            <label class="form-label">Icon</label>
                            <input type="hidden" name="icon" value="bi-people-fill" class="icon-hidden-input">
                            <div class="d-flex flex-wrap gap-2 icon-picker">
                                <?php foreach ($committeeIconChoices as $i => $ic): ?>
                                    <span class="icon-choice<?php echo $i === 0 ? ' selected' : ''; ?>" data-icon="<?php echo e($ic); ?>"><i class="bi <?php echo e($ic); ?>"></i></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-outline-brand" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_committee" class="btn-brand">Save Committee</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php foreach ($committees as $c): $cid = (int)$c['committee_id']; ?>
        <!-- Edit Committee Modal -->
        <div class="modal fade" id="editCommitteeModal<?php echo $cid; ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="border-radius:12px; border:none;">
                    <form method="post">
                        <input type="hidden" name="committee_id" value="<?php echo $cid; ?>">
                        <div class="modal-header" style="background:linear-gradient(90deg,#45b84d,#aadaad); border-radius:12px 12px 0 0;">
                            <h5 class="modal-title text-white fw-bold"><i class="bi bi-pencil-square me-2"></i> Edit Committee</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4">
                            <div class="mb-3">
                                <label class="form-label">Committee Name</label>
                                <input type="text" class="form-control" name="name" value="<?php echo e($c['name']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Short Description</label>
                                <textarea class="form-control" name="description" rows="2"><?php echo e($c['description']); ?></textarea>
                            </div>
                            <div class="mb-1">
                                <label class="form-label">Icon</label>
                                <input type="hidden" name="icon" value="<?php echo e($c['icon']); ?>" class="icon-hidden-input">
                                <div class="d-flex flex-wrap gap-2 icon-picker">
                                    <?php foreach ($committeeIconChoices as $ic): ?>
                                        <span class="icon-choice<?php echo $ic === $c['icon'] ? ' selected' : ''; ?>" data-icon="<?php echo e($ic); ?>"><i class="bi <?php echo e($ic); ?>"></i></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn-outline-brand" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="edit_committee" class="btn-brand">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Add Program Modal -->
    <div class="modal fade" id="addProgramModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content" style="border-radius:12px; border:none;">
                <form method="post">
                    <div class="modal-header" style="background:linear-gradient(90deg,#45b84d,#aadaad); border-radius:12px 12px 0 0;">
                        <h5 class="modal-title text-white fw-bold"><i class="bi bi-plus-circle me-2"></i> Add Financial Assistance Program</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Program Name</label>
                                <input type="text" class="form-control" name="name" placeholder="e.g., Medical Assistance Program" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Committee</label>
                                <select class="form-select" name="committee_id" id="newProgramCommittee" required>
                                    <option value="" selected disabled>Select committee</option>
                                    <?php foreach ($committees as $c): ?>
                                        <option value="<?php echo $c['committee_id']; ?>"><?php echo e($c['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Program Description</label>
                                <textarea class="form-control" name="description" rows="3" placeholder="Briefly describe the purpose and coverage of this program"></textarea>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label d-block">Assistance Type <span class="text-muted fw-normal">(select one or both)</span></label>
                                <div class="form-check">
                                    <input class="form-check-input assistance-type-check" type="checkbox" name="assistance_type[]" value="cash" id="newAtCash" checked>
                                    <label class="form-check-label" for="newAtCash">Cash Assistance</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input assistance-type-check" type="checkbox" name="assistance_type[]" value="in_kind" id="newAtKind">
                                    <label class="form-check-label" for="newAtKind">In-Kind Assistance</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Amount / Value</label>
                                <div class="input-group">
                                    <span class="input-group-text">&#8369;</span>
                                    <input type="number" step="0.01" class="form-control" name="amount" placeholder="0.00">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Release Schedule</label>
                                <select class="form-select" name="release_schedule">
                                    <option value="" selected disabled>Select schedule</option>
                                    <option>One-time</option>
                                    <option>Per Semester</option>
                                    <option>Monthly</option>
                                    <option>Quarterly</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Application Period Start</label>
                                <input type="date" class="form-control" name="app_start_date">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Application Period End</label>
                                <input type="date" class="form-control" name="app_end_date">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Eligibility Requirements <span class="text-muted fw-normal">(one per line)</span></label>
                                <textarea class="form-control" name="eligibility_requirements" rows="3" placeholder="e.g.&#10;Barangay Indigency&#10;Valid ID"></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label d-block">Status</label>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" name="status_active" id="programStatusSwitch" checked>
                                    <label class="form-check-label" for="programStatusSwitch" style="font-size:13.5px;">Active (open for applications)</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-outline-brand" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_program" class="btn-brand">Save Program</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php foreach ($allPrograms as $p): $pid = (int)$p['program_id'];
        $benCount = $beneficiaryCounts[$pid] ?? 0;
        $reqLines = $p['eligibility_requirements'] ? explode("\n", $p['eligibility_requirements']) : [];
    ?>
        <!-- View Program Modal -->
        <div class="modal fade" id="viewProgramModal<?php echo $pid; ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold"><i class="bi bi-eye me-2"></i><?php echo e($p['name']); ?></h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="info-label">Assistance Type</div>
                                <div class="info-value"><?php echo assistLabel($p['assistance_type']); ?></div>
                            </div>
                            <div class="col-6">
                                <div class="info-label">Status</div>
                                <div class="info-value"><?php echo ucfirst($p['status']); ?></div>
                            </div>
                            <div class="col-6">
                                <div class="info-label">Amount</div>
                                <div class="info-value"><?php echo $p['amount'] !== null ? '₱' . number_format($p['amount'], 2) : '—'; ?></div>
                            </div>
                            <div class="col-6">
                                <div class="info-label">Release Schedule</div>
                                <div class="info-value"><?php echo e($p['release_schedule'] ?: '—'); ?></div>
                            </div>
                            <div class="col-6">
                                <div class="info-label">Application Start</div>
                                <div class="info-value"><?php echo e($p['app_start_date'] ?: '—'); ?></div>
                            </div>
                            <div class="col-6">
                                <div class="info-label">Application End</div>
                                <div class="info-value"><?php echo e($p['app_end_date'] ?: '—'); ?></div>
                            </div>
                            <div class="col-12">
                                <div class="info-label">Description</div>
                                <div class="info-value"><?php echo nl2br(e($p['description'] ?: '—')); ?></div>
                            </div>
                            <div class="col-12">
                                <div class="info-label">Eligibility Requirements</div>
                                <div class="info-value">
                                    <?php if (empty($reqLines)): ?>—<?php else: ?>
                                    <ul class="mb-0 ps-3">
                                        <?php foreach ($reqLines as $rl): if (trim($rl) === '') continue; ?><li><?php echo e($rl); ?></li><?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="info-label">Beneficiaries (approved applicants)</div>
                                <div class="info-value"><?php echo $benCount; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Program Modal -->
        <div class="modal fade" id="editProgramModal<?php echo $pid; ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content" style="border-radius:12px; border:none;">
                    <form method="post">
                        <input type="hidden" name="program_id" value="<?php echo $pid; ?>">
                        <div class="modal-header" style="background:linear-gradient(90deg,#45b84d,#aadaad); border-radius:12px 12px 0 0;">
                            <h5 class="modal-title text-white fw-bold"><i class="bi bi-pencil-square me-2"></i> Edit Program</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Program Name</label>
                                    <input type="text" class="form-control" name="name" value="<?php echo e($p['name']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Committee</label>
                                    <select class="form-select" name="committee_id" required>
                                        <?php foreach ($committees as $c): ?>
                                            <option value="<?php echo $c['committee_id']; ?>" <?php echo (int)$c['committee_id'] === (int)$p['committee_id'] ? 'selected' : ''; ?>><?php echo e($c['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Program Description</label>
                                    <textarea class="form-control" name="description" rows="3"><?php echo e($p['description']); ?></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label d-block">Assistance Type <span class="text-muted fw-normal">(select one or both)</span></label>
                                    <div class="form-check">
                                        <input class="form-check-input assistance-type-check" type="checkbox" name="assistance_type[]" value="cash" id="editAtCash<?php echo $pid; ?>" <?php echo in_array($p['assistance_type'], ['cash', 'both'], true) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="editAtCash<?php echo $pid; ?>">Cash Assistance</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input assistance-type-check" type="checkbox" name="assistance_type[]" value="in_kind" id="editAtKind<?php echo $pid; ?>" <?php echo in_array($p['assistance_type'], ['in_kind', 'both'], true) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="editAtKind<?php echo $pid; ?>">In-Kind Assistance</label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Amount / Value</label>
                                    <div class="input-group">
                                        <span class="input-group-text">&#8369;</span>
                                        <input type="number" step="0.01" class="form-control" name="amount" value="<?php echo e($p['amount']); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Release Schedule</label>
                                    <select class="form-select" name="release_schedule">
                                        <option value="">Select schedule</option>
                                        <?php foreach (['One-time', 'Per Semester', 'Monthly', 'Quarterly'] as $sch): ?>
                                            <option <?php echo $p['release_schedule'] === $sch ? 'selected' : ''; ?>><?php echo $sch; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Application Period Start</label>
                                    <input type="date" class="form-control" name="app_start_date" value="<?php echo e($p['app_start_date']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Application Period End</label>
                                    <input type="date" class="form-control" name="app_end_date" value="<?php echo e($p['app_end_date']); ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Eligibility Requirements <span class="text-muted fw-normal">(one per line)</span></label>
                                    <textarea class="form-control" name="eligibility_requirements" rows="3"><?php echo e($p['eligibility_requirements']); ?></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label d-block">Status</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" name="status_active" id="programStatusSwitch<?php echo $pid; ?>" <?php echo $p['status'] === 'active' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="programStatusSwitch<?php echo $pid; ?>" style="font-size:13.5px;">Active (open for applications)</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn-outline-brand" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="edit_program" class="btn-brand">Save Program</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Add/Edit Announcement Modal (shared — openAddAnnouncement()/openEditAnnouncement() below swap it between the two) -->
    <div class="modal fade" id="addAnnouncementModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow">
                <form method="post">
                    <input type="hidden" name="announcement_id" id="announcementIdInput" value="">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold" id="announcementModalTitle"><i class="bi bi-plus-circle-fill me-2"></i>Add Announcement</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label mb-2" style="font-size:13px; font-weight:700; color:#2e7d32;"><i class="bi bi-send-fill"></i> Send To</label>
                                <div class="border rounded-2 overflow-hidden">
                                    <label class="d-flex align-items-center gap-3 px-3 py-2 border-bottom" style="cursor:pointer; margin:0;">
                                        <input type="radio" name="sent_to" value="all" id="sendToAll" style="accent-color:#2e7d32; flex-shrink:0;">
                                        <div>
                                            <div style="font-size:13px; font-weight:600; color:#1b5e20;">All Users</div>
                                        </div>
                                    </label>
                                    <label class="d-flex align-items-center gap-3 px-3 py-2 border-bottom" style="cursor:pointer; margin:0;">
                                        <input type="radio" name="sent_to" value="scholars" id="sendToScholars" style="accent-color:#2e7d32; flex-shrink:0;">
                                        <div>
                                            <div style="font-size:13px; font-weight:600; color:#1b5e20;">Scholars Only</div>
                                        </div>
                                    </label>
                                    <label class="d-flex align-items-center gap-3 px-3 py-2 border-bottom" style="cursor:pointer; margin:0;">
                                        <input type="radio" name="sent_to" value="applicants" id="sendToApplicants" style="accent-color:#2e7d32; flex-shrink:0;">
                                        <div>
                                            <div style="font-size:13px; font-weight:600; color:#1b5e20;">Applicants Only</div>
                                        </div>
                                    </label>
                                    <label class="d-flex align-items-center gap-3 px-3 py-2" style="cursor:pointer; margin:0;">
                                        <input type="radio" name="sent_to" value="specific" id="sendToSpecific" style="accent-color:#2e7d32; flex-shrink:0;">
                                        <div>
                                            <div style="font-size:13px; font-weight:600; color:#1b5e20;">Specific User</div>
                                        </div>
                                    </label>
                                </div>
                                <div class="mt-2">
                                    <label class="form-label" style="font-size:13px; font-weight:600;">User ID or Email <span class="text-muted fw-normal">(if Specific User)</span></label>
                                    <input type="text" class="form-control form-control-sm" name="specific_target" id="announcementSpecificTargetInput" placeholder="e.g. juan@email.com">
                                </div>
                            </div>
                            <div class="col-12">
                                <hr class="my-1">
                            </div>
                            <div class="col-12">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Title</label>
                                <input type="text" class="form-control form-control-sm" name="title" id="announcementTitleInput" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Committee</label>
                                <select class="form-select form-select-sm" name="committee_id" id="announcementCommitteeSelect">
                                    <option value="">General (All Committees)</option>
                                    <?php foreach ($committees as $c): ?>
                                        <option value="<?php echo $c['committee_id']; ?>"><?php echo e($c['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Date <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="date" class="form-control form-control-sm" name="event_date" id="announcementEventDateInput">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Time <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="time" class="form-control form-control-sm" name="event_time" id="announcementEventTimeInput">
                            </div>
                            <div class="col-12">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Where <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="text" class="form-control form-control-sm" name="event_where" id="announcementEventWhereInput">
                            </div>
                            <div class="col-12">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Notes / Reminder <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="text" class="form-control form-control-sm" name="notes" id="announcementNotesInput">
                            </div>
                            <div class="col-12">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Description</label>
                                <textarea class="form-control form-control-sm" name="message" id="announcementMessageInput" rows="4" required></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="save_announcement" class="btn btn-sm btn-success"><i class="bi bi-send-fill me-1"></i> Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Announcement Modals -->
    <?php foreach ($announcements as $a): ?>
        <div class="modal fade" id="viewAnnouncementModal<?php echo $a['announcement_id']; ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold"><i class="bi bi-bell-fill me-2"></i>Announcement Details</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <div class="info-label">Announcement ID</div>
                                <div class="info-value"><?php echo str_pad($a['announcement_id'], 3, '0', STR_PAD_LEFT); ?></div>
                            </div>
                            <div class="col-6">
                                <div class="info-label">Committee</div>
                                <div class="info-value"><?php echo e($a['committee_name'] ?? 'General'); ?></div>
                            </div>
                            <div class="col-6">
                                <div class="info-label">Date Posted</div>
                                <div class="info-value"><?php echo date('F j, Y', strtotime($a['posted_at'])); ?></div>
                            </div>
                            <div class="col-6">
                                <div class="info-label">Sent To</div>
                                <div class="info-value"><?php echo e($sendToLabel[$a['sent_to']] ?? $a['sent_to']); ?><?php echo $a['sent_to'] === 'specific' ? ' (' . e($a['specific_target']) . ')' : ''; ?></div>
                            </div>
                        </div>
                        <div class="section-divider">Preview (as seen by applicants/scholars)</div>
                        <div class="ann-preview">
                            <div class="ann-title"><?php echo e($a['title']); ?></div>
                            <div class="ann-body"><?php echo nl2br(e($a['message'])); ?></div>
                            <?php if ($a['event_date']): ?><div class="ann-detail"><strong>When:</strong> <?php echo date('F j, Y', strtotime($a['event_date'])); ?><?php echo $a['event_time'] ? ' — ' . date('g:i A', strtotime($a['event_time'])) : ''; ?></div><?php endif; ?>
                            <?php if ($a['event_where']): ?><div class="ann-detail"><strong>Where:</strong> <?php echo e($a['event_where']); ?></div><?php endif; ?>
                            <?php if ($a['notes']): ?><div class="ann-detail"><strong>Note:</strong> <?php echo e($a['notes']); ?></div><?php endif; ?>
                            <div class="ann-meta">Posted: <?php echo date('F j, Y', strtotime($a['posted_at'])); ?></div>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Announcement Archives Modal -->
    <div class="modal fade" id="archivesAnnouncementModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content" style="border-radius:12px; border:none;">
                <div class="modal-header" style="background:linear-gradient(90deg,#45b84d,#aadaad); border-radius:12px 12px 0 0;">
                    <h5 class="modal-title text-white fw-bold"><i class="bi bi-archive-fill me-2"></i> Archived Announcements</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="table-card">
                        <table class="table mb-0" style="font-size:13px;">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Committee</th>
                                    <th>Archived On</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($archivedAnnouncements)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">No archived announcements.</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($archivedAnnouncements as $a): ?>
                                    <tr>
                                        <td><?php echo str_pad($a['announcement_id'], 3, '0', STR_PAD_LEFT); ?></td>
                                        <td><?php echo e($a['title']); ?></td>
                                        <td><?php echo e($a['committee_name'] ?? 'General'); ?></td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($a['archived_at'])); ?></td>
                                        <td class="text-center">
                                            <form method="post">
                                                <input type="hidden" name="announcement_id" value="<?php echo $a['announcement_id']; ?>">
                                                <button type="submit" name="restore_announcement" class="btn btn-sm btn-outline-success py-0 px-2" style="font-size:11px;"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-brand" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Archives Modal -->
    <div class="modal fade" id="archivesProgramModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content" style="border-radius:12px; border:none;">
                <div class="modal-header" style="background:linear-gradient(90deg,#45b84d,#aadaad); border-radius:12px 12px 0 0;">
                    <h5 class="modal-title text-white fw-bold"><i class="bi bi-archive-fill me-2"></i> Archived Programs</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div id="archivedProgramsContainer">
                        <?php if (empty($archivedPrograms)): ?>
                            <p class="text-muted text-center mb-0">No archived programs.</p>
                        <?php endif; ?>
                        <?php foreach ($archivedPrograms as $ap): ?>
                            <div class="program-item">
                                <div>
                                    <div class="p-name"><?php echo e($ap['name']); ?></div>
                                    <div class="p-meta">
                                        <span><?php echo e($ap['committee_name']); ?></span>
                                        <span><i class="bi bi-calendar3 me-1"></i>Archived <?php echo date('M j, Y', strtotime($ap['archived_at'])); ?></span>
                                    </div>
                                </div>
                                <div class="program-actions">
                                    <form method="post">
                                        <input type="hidden" name="program_id" value="<?php echo $ap['program_id']; ?>">
                                        <button type="submit" name="restore_program" class="action-btn btn-view"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-brand" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Archived Committees Modal -->
    <div class="modal fade" id="archivesCommitteeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content" style="border-radius:12px; border:none;">
                <div class="modal-header" style="background:linear-gradient(90deg,#45b84d,#aadaad); border-radius:12px 12px 0 0;">
                    <h5 class="modal-title text-white fw-bold"><i class="bi bi-archive-fill me-2"></i> Archived Committees</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div id="archivedCommitteesContainer">
                        <?php if (empty($archivedCommittees)): ?>
                            <p class="text-muted text-center mb-0">No archived committees.</p>
                        <?php endif; ?>
                        <?php foreach ($archivedCommittees as $ac): ?>
                            <div class="program-item">
                                <div>
                                    <div class="p-name"><i class="bi <?php echo e($ac['icon'] ?: 'bi-people-fill'); ?> me-1"></i> <?php echo e($ac['name']); ?></div>
                                    <div class="p-meta">
                                        <span><i class="bi bi-calendar3 me-1"></i>Archived <?php echo date('M j, Y', strtotime($ac['archived_at'])); ?></span>
                                    </div>
                                </div>
                                <div class="program-actions">
                                    <form method="post">
                                        <input type="hidden" name="committee_id" value="<?php echo $ac['committee_id']; ?>">
                                        <button type="submit" name="restore_committee" class="action-btn btn-view"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-brand" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
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

        // Icon picker (Add/Edit Committee modals)
        document.querySelectorAll('.icon-picker').forEach(function(picker) {
            const hiddenInput = picker.parentElement.querySelector('.icon-hidden-input');
            picker.querySelectorAll('.icon-choice').forEach(function(choice) {
                choice.addEventListener('click', function() {
                    picker.querySelectorAll('.icon-choice').forEach(c => c.classList.remove('selected'));
                    choice.classList.add('selected');
                    if (hiddenInput) hiddenInput.value = choice.dataset.icon;
                });
            });
        });

        // "Add Program" button inside a committee block preselects that committee
        document.querySelectorAll('.add-program-for-committee').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const sel = document.getElementById('newProgramCommittee');
                if (sel) sel.value = btn.dataset.committee;
                const modalEl = document.getElementById('addProgramModal');
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            });
        });

        // Client-side search / filter / sort (Programs tab)
        const programSearchInput = document.getElementById('programSearchInput');
        const programFilterSelect = document.getElementById('programFilterSelect');
        const programSortSelect = document.getElementById('programSortSelect');

        function applyProgramFilters() {
            const q = programSearchInput ? programSearchInput.value.trim().toLowerCase() : '';
            const filterVal = programFilterSelect ? programFilterSelect.value : 'all';
            const sortVal = programSortSelect ? programSortSelect.value : 'id_asc';
            const [filterKey, filterArg] = filterVal.includes(':') ? filterVal.split(':') : [null, null];

            document.querySelectorAll('#committeeSectionsContainer .committee-block-body').forEach(function(body) {
                const items = Array.from(body.querySelectorAll('.program-item:not(.builtin-tab-item)'));
                if (!items.length) return;

                items.forEach(function(item) {
                    const matchesSearch = !q || (item.dataset.programName || '').includes(q);
                    const matchesFilter = !filterKey ||
                        (filterKey === 'status' && (item.dataset.status || '').toLowerCase() === filterArg) ||
                        (filterKey === 'assist' && (item.dataset.assist || '').split(',').includes(filterArg));
                    item.style.display = (matchesSearch && matchesFilter) ? '' : 'none';
                });

                items.sort(function(a, b) {
                    switch (sortVal) {
                        case 'id_desc':
                            return (+b.dataset.programId) - (+a.dataset.programId);
                        case 'name_asc':
                            return (a.dataset.programName || '').localeCompare(b.dataset.programName || '');
                        case 'name_desc':
                            return (b.dataset.programName || '').localeCompare(a.dataset.programName || '');
                        case 'date_desc':
                            return (+b.dataset.created) - (+a.dataset.created);
                        case 'date_asc':
                            return (+a.dataset.created) - (+b.dataset.created);
                        case 'id_asc':
                        default:
                            return (+a.dataset.programId) - (+b.dataset.programId);
                    }
                });
                items.forEach(item => body.appendChild(item));
            });
        }

        if (programSearchInput) programSearchInput.addEventListener('input', applyProgramFilters);
        if (programFilterSelect) programFilterSelect.addEventListener('change', applyProgramFilters);
        if (programSortSelect) programSortSelect.addEventListener('change', applyProgramFilters);
        applyProgramFilters();

        // Assistance Type is now two checkboxes (Cash / In-Kind) instead of one required select —
        // require at least one checked per form so a program is never saved with neither.
        document.querySelectorAll('.assistance-type-check').forEach(function(box) {
            box.addEventListener('change', function() {
                const form = box.closest('form');
                const boxesInForm = form.querySelectorAll('.assistance-type-check');
                const anyChecked = Array.from(boxesInForm).some(b => b.checked);
                boxesInForm.forEach(b => b.setCustomValidity(anyChecked ? '' : 'Select at least one assistance type.'));
            });
        });

        // Add/Edit Announcement modal — one shared modal, reset to a blank "Add" state or
        // populated into an "Edit" state depending on which button opened it.
        function openAddAnnouncement(committeeId) {
            document.getElementById('announcementIdInput').value = '';
            document.getElementById('announcementTitleInput').value = '';
            document.getElementById('announcementCommitteeSelect').value = committeeId || '';
            document.getElementById('announcementMessageInput').value = '';
            document.getElementById('announcementEventDateInput').value = '';
            document.getElementById('announcementEventTimeInput').value = '';
            document.getElementById('announcementEventWhereInput').value = '';
            document.getElementById('announcementSpecificTargetInput').value = '';
            document.getElementById('announcementNotesInput').value = '';
            document.getElementById('sendToAll').checked = true;
            document.getElementById('announcementModalTitle').innerHTML = '<i class="bi bi-plus-circle-fill me-2"></i>Add Announcement';
        }

        function openEditAnnouncement(a) {
            document.getElementById('announcementIdInput').value = a.announcement_id;
            document.getElementById('announcementTitleInput').value = a.title;
            document.getElementById('announcementCommitteeSelect').value = a.committee_id || '';
            document.getElementById('announcementMessageInput').value = a.message || '';
            document.getElementById('announcementEventDateInput').value = a.event_date || '';
            document.getElementById('announcementEventTimeInput').value = a.event_time || '';
            document.getElementById('announcementEventWhereInput').value = a.event_where || '';
            document.getElementById('announcementSpecificTargetInput').value = a.specific_target || '';
            document.getElementById('announcementNotesInput').value = a.notes || '';
            const sentTo = a.sent_to || 'all';
            const radio = document.getElementById('sendTo' + sentTo.charAt(0).toUpperCase() + sentTo.slice(1));
            if (radio) radio.checked = true;
            document.getElementById('announcementModalTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>Edit Announcement';
        }

        // "Add Announcement" button inside a committee block preselects that committee
        document.querySelectorAll('.add-announcement-for-committee').forEach(function(btn) {
            btn.addEventListener('click', function() {
                openAddAnnouncement(btn.dataset.committee);
                const modalEl = document.getElementById('addAnnouncementModal');
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            });
        });

        // Client-side search (Announcements tab) — filters every committee section's table rows
        // by title, same pattern as the Programs tab above.
        const announcementSearchInput = document.getElementById('announcementSearchInput');
        if (announcementSearchInput) {
            announcementSearchInput.addEventListener('input', function() {
                const q = this.value.trim().toLowerCase();
                document.querySelectorAll('#announcementsContainer .announcement-row').forEach(function(row) {
                    const match = !q || (row.dataset.title || '').includes(q);
                    row.style.display = match ? '' : 'none';
                });
            });
        }

        // Only prompt when the Academic Term itself actually changed — not on every unrelated
        // settings save (site name, contact info, etc.).
        const siteSettingsForm = document.getElementById('siteSettingsForm');
        if (siteSettingsForm) {
            siteSettingsForm.addEventListener('submit', function(e) {
                const yearChanged = document.getElementById('settingAcademicYear').value.trim() !== this.dataset.origYear;
                const semesterChanged = document.getElementById('settingSemester').value !== this.dataset.origSemester;
                if ((yearChanged || semesterChanged) && !confirm('Change the active Academic Term to "' + document.getElementById('settingAcademicYear').value.trim() + ', ' + document.getElementById('settingSemester').value + '"?\n\nThis starts a fresh activity/allowance tracking period for every scholar going forward. Past terms stay intact.')) {
                    e.preventDefault();
                }
            });
        }
    </script>

</body>

</html>