<?php
require_once __DIR__ . '/../config/forms.php';
requireRole('admin');

$me = currentUser();

// ---- Forms tab: field type/width choices, shared by the save_field handler below and the
// inline field editor rendered further down ----
$fieldTypeMap = ['Text' => 'text', 'Number' => 'number', 'Date' => 'date', 'Textarea' => 'textarea', 'Dropdown' => 'dropdown', 'Radio Buttons' => 'radio', 'File Upload' => 'file'];
$fieldTypeMapReverse = array_flip($fieldTypeMap);
$fieldWidthMap = ['1/3' => 'third', 'Half' => 'half', '2/3' => 'two_third', 'Full' => 'full'];
$fieldWidthMapReverse = array_flip($fieldWidthMap);

// A POST redirects back into the Forms tab (instead of always landing on Settings) with the
// program that was being edited still expanded — set by the save_field/archive_field/move_field
// handlers below.
$redirectTab = null;
$redirectFtab = null;

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

    if (isset($_POST['save_site_name'])) {
        $siteName = trim($_POST['site_name'] ?? '');
        if ($siteName === '') {
            setFlash('error', 'System name cannot be blank.');
        } else {
            $stmt = $conn->prepare("UPDATE site_settings SET site_name = ? WHERE id = 1");
            $stmt->bind_param('s', $siteName);
            $stmt->execute();
            $stmt->close();
            logAudit('Updated Site Settings', 'System name: ' . $siteName);
            setFlash('success', 'System name updated.');
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

    if (isset($_POST['save_privacy_policy'])) {
        $privacyPolicy = trim($_POST['privacy_policy'] ?? '');
        if ($privacyPolicy === '') {
            setFlash('error', 'Privacy Policy cannot be empty — applicants must have something to agree to.');
        } else {
            $stmt = $conn->prepare("UPDATE site_settings SET privacy_policy = ? WHERE id = 1");
            $stmt->bind_param('s', $privacyPolicy);
            $stmt->execute();
            $stmt->close();
            logAudit('Updated Site Settings', 'Privacy Policy');
            setFlash('success', 'Privacy Policy updated.');
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

    // Announcement posting/editing/archiving now lives only in Content Management
    // (AdminProgramManagement.php) — this used to be a second, more limited copy of the same
    // feature (always General/"all", no committee, no event details).

    // ---- Forms tab: add/edit/reorder/remove a program's application-form fields, inline ----
    if (isset($_POST['save_field'])) {
        $formCommitteeId = (int)($_POST['form_committee_id'] ?? 0);
        $formTrack = ($_POST['form_track'] ?? '') === 'scholarship' ? 'scholarship' : 'assistance';
        $formProgramIdRaw = $_POST['form_program_id'] ?? '';
        $formProgramId = $formProgramIdRaw === '' ? null : (int)$formProgramIdRaw;
        $redirectTab = 'formsTab';
        $redirectFtab = (int)($_POST['form_tab_id'] ?? 0);

        $label = trim($_POST['label'] ?? '');
        $inputType = $fieldTypeMap[$_POST['input_type'] ?? 'Text'] ?? 'text';
        $icon = $_POST['icon'] ?? 'bi-fonts';
        $width = $fieldWidthMap[$_POST['width'] ?? 'Full'] ?? 'full';
        $required = isset($_POST['required']) ? 1 : 0;
        $optionsText = trim($_POST['options'] ?? '');
        $options = null;
        if (($inputType === 'dropdown' || $inputType === 'radio') && $optionsText !== '') {
            $options = json_encode(array_values(array_filter(array_map('trim', explode(',', $optionsText)))));
        }
        $fieldId = (int)($_POST['field_id'] ?? 0);

        if ($formCommitteeId <= 0) {
            setFlash('error', 'Invalid form.');
        } elseif ($label === '') {
            setFlash('error', 'Field label is required.');
        } elseif ($fieldId > 0) {
            $stmt = $conn->prepare("UPDATE form_fields SET label=?, input_type=?, icon=?, width=?, is_required=?, options=? WHERE field_id=? AND committee_id=? AND program_track=?");
            $stmt->bind_param('ssssisiis', $label, $inputType, $icon, $width, $required, $options, $fieldId, $formCommitteeId, $formTrack);
            $stmt->execute();
            $stmt->close();
            logAudit('Updated Form Field', $label);
            setFlash('success', 'Field updated.');
        } else {
            $fieldKey = slugifyFieldKey($label, $formCommitteeId, $formTrack, $conn, $formProgramId);
            $stmt = $conn->prepare("SELECT COALESCE(MAX(sort_order),0) AS m FROM form_fields WHERE committee_id = ? AND program_track = ?");
            $stmt->bind_param('is', $formCommitteeId, $formTrack);
            $stmt->execute();
            $maxOrder = $stmt->get_result()->fetch_assoc()['m'];
            $stmt->close();
            $sortOrder = $maxOrder + 1;

            $stmt = $conn->prepare("INSERT INTO form_fields (committee_id, program_track, program_id, label, field_key, input_type, icon, width, is_required, options, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('isisssssisi', $formCommitteeId, $formTrack, $formProgramId, $label, $fieldKey, $inputType, $icon, $width, $required, $options, $sortOrder);
            $stmt->execute();
            $stmt->close();
            logAudit('Added Form Field', $label);
            setFlash('success', 'Field added.');
        }
    }

    if (isset($_POST['archive_field'])) {
        $formCommitteeId = (int)($_POST['form_committee_id'] ?? 0);
        $formTrack = ($_POST['form_track'] ?? '') === 'scholarship' ? 'scholarship' : 'assistance';
        $redirectTab = 'formsTab';
        $redirectFtab = (int)($_POST['form_tab_id'] ?? 0);

        $fieldId = (int)$_POST['field_id'];
        $stmt = $conn->prepare("UPDATE form_fields SET archived_at = NOW() WHERE field_id = ? AND committee_id = ? AND program_track = ?");
        $stmt->bind_param('iis', $fieldId, $formCommitteeId, $formTrack);
        $stmt->execute();
        $stmt->close();
        logAudit('Removed Form Field', 'Field #' . $fieldId);
        setFlash('success', 'Field removed.');
    }

    if (isset($_POST['move_field'])) {
        $formCommitteeId = (int)($_POST['form_committee_id'] ?? 0);
        $formTrack = ($_POST['form_track'] ?? '') === 'scholarship' ? 'scholarship' : 'assistance';
        $formProgramIdRaw = $_POST['form_program_id'] ?? '';
        $formProgramId = $formProgramIdRaw === '' ? null : (int)$formProgramIdRaw;
        $redirectTab = 'formsTab';
        $redirectFtab = (int)($_POST['form_tab_id'] ?? 0);

        $fieldId = (int)$_POST['field_id'];
        $direction = $_POST['direction'] === 'up' ? 'up' : 'down';
        $scopeFields = getFormFields($formCommitteeId, $formTrack, $formProgramId);
        $index = null;
        foreach ($scopeFields as $i => $f) {
            if ((int)$f['field_id'] === $fieldId) {
                $index = $i;
                break;
            }
        }
        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
        if ($index !== null && isset($scopeFields[$swapWith])) {
            $a = $scopeFields[$index];
            $b = $scopeFields[$swapWith];
            $stmt = $conn->prepare("UPDATE form_fields SET sort_order = ? WHERE field_id = ?");
            $stmt->bind_param('ii', $b['sort_order'], $a['field_id']);
            $stmt->execute();
            $stmt->bind_param('ii', $a['sort_order'], $b['field_id']);
            $stmt->execute();
            $stmt->close();
        }
    }

    if ($redirectTab) {
        header("Location: AdminConfiguration.php?tab=" . $redirectTab . ($redirectFtab ? '&ftab=' . $redirectFtab : ''));
    } else {
        header("Location: AdminConfiguration.php");
    }
    exit();
}

$pageError = getFlash('error');
$pageSuccess = getFlash('success');

$settings = $conn->query("SELECT * FROM site_settings WHERE id = 1")->fetch_assoc();
$importantDates = $conn->query("SELECT * FROM important_dates ORDER BY event_date ASC")->fetch_all(MYSQLI_ASSOC);

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

// ---- Forms tab: committee -> program picker whose selected program's field editor renders
// inline below (every program's editor is server-rendered up front, then hidden/shown by
// formsCommitteeSelect/formsProgramSelect in JS — no page reload, no separate editor page). See
// $iconChoices for the icon picker shared by every field editor instance.
$allCommittees = $conn->query("SELECT * FROM committees WHERE archived_at IS NULL ORDER BY committee_id ASC")->fetch_all(MYSQLI_ASSOC);
$formPanels = [];
$formCommitteeOptions = [];
foreach ($allCommittees as $c) {
    $cid = (int)$c['committee_id'];
    foreach (getProgramTabs($cid, false) as $t) {
        $isBaseTab = in_array($t['track_code'], ['scholarship', 'assistance'], true);
        $formTrack = $isBaseTab ? $t['track_code'] : 'assistance';
        $formProgramId = $isBaseTab ? null : (int)$t['program_id'];
        $formPanels[] = [
            'tab_id' => (int)$t['tab_id'],
            'committee_id' => $cid,
            'committee_name' => $c['name'],
            'label' => $t['label'],
            'track' => $formTrack,
            'program_id' => $formProgramId,
            'fields' => getFormFields($cid, $formTrack, $formProgramId),
        ];
        $formCommitteeOptions[$cid] = $c['name'];
    }
}
$formPanelsByCommittee = [];
foreach ($formPanels as $panel) {
    $formPanelsByCommittee[$panel['committee_id']][] = ['tab_id' => $panel['tab_id'], 'label' => $panel['label']];
}
$iconChoices = ['bi-fonts', 'bi-person', 'bi-geo-alt', 'bi-building', 'bi-mortarboard', 'bi-calendar3', 'bi-123', 'bi-menu-button-wide', 'bi-chat-left-text', 'bi-upload', 'bi-telephone', 'bi-envelope', 'bi-cash-coin', 'bi-box-seam', 'bi-person-vcard', 'bi-file-earmark-text'];

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

        .committee-tag {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 20px;
            background: #eef4ff;
            color: #3b5bdb;
            border: 1px solid #d0dcf7;
            white-space: nowrap;
        }

        .icon-preview-swatch {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 6px;
            background: #e8f5e9;
            color: #2e7d32;
        }

        .type-badge {
            background: #e3f2fd;
            color: #1565c0;
            font-size: 11px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 20px;
        }

        .required-badge {
            background: #fce4ec;
            color: #c62828;
            font-size: 11px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 20px;
        }

        .optional-badge {
            background: #f1f1f1;
            color: #666;
            font-size: 11px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 20px;
        }

        .icon-radio-grid {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            gap: 6px;
        }

        .icon-radio-grid label {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 36px;
            border: 1px solid #ddd;
            border-radius: 6px;
            cursor: pointer;
        }

        .icon-radio-grid input:checked+label {
            border-color: #45b84d;
            background: #e8f5e9;
            color: #2e7d32;
        }

        .icon-radio-grid input {
            display: none;
        }

        .preview-form-card {
            background: #fff;
            border-radius: 10px;
        }
    </style>
</head>

<body>

    <?php include __DIR__ . '/../includes/adminsidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <h4 class="fw-bold mb-1">Configuration</h4>
        <p class="text-muted mb-2" style="font-size: 13px;">Manage system settings, program settings, and application forms.</p>

        <?php if ($pageSuccess): ?><div class="alert alert-success py-2"><?php echo e($pageSuccess); ?></div><?php endif; ?>
        <?php if ($pageError): ?><div class="alert alert-danger py-2"><?php echo e($pageError); ?></div><?php endif; ?>

        <!-- Tabs -->
        <div class="content-tabs">
            <button class="content-tab-btn active" data-tab="settingsTab"><i class="bi bi-gear-fill"></i> Settings</button>
            <button class="content-tab-btn" data-tab="programSettingsTab"><i class="bi bi-ui-checks-grid"></i> Application / Program Settings</button>
            <button class="content-tab-btn" data-tab="formsTab"><i class="bi bi-file-earmark-text"></i> Forms</button>
        </div>

        <!-- ==============================
             SECTION 1: SETTINGS
        ============================== -->
        <div class="tab-pane-custom active" id="settingsTab">
            <div class="config-card">
                <div class="card-section-title"><i class="bi bi-gear-fill"></i> Settings</div>
                <div class="card-section-sub">Configure system-wide settings that apply across all pages and modules.</div>

                <!-- System Name -->
                <form method="post">
                    <div class="setting-item">
                        <div>
                            <div class="setting-label"><i class="bi bi-card-heading text-success me-1"></i> System Name</div>
                            <div class="setting-desc">The site/barangay name shown in the header, sidebar, and everywhere else the system identifies itself.</div>
                        </div>
                        <div class="setting-control gap-3">
                            <input type="text" name="site_name" class="form-control form-control-sm" style="max-width:280px;" value="<?php echo e($settings['site_name']); ?>" required>
                            <button type="submit" name="save_site_name" class="btn btn-sm btn-outline-success"><i class="bi bi-save me-1"></i> Save</button>
                        </div>
                    </div>
                </form>

                <div class="divider"></div>

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

                <!-- Privacy Policy -->
                <form method="post">
                    <div class="mb-2">
                        <div class="setting-label"><i class="bi bi-shield-lock-fill text-success me-1"></i> Privacy Policy</div>
                        <div class="setting-desc mt-1 mb-2">Shown to applicants during registration alongside the Terms and Conditions.</div>
                        <textarea class="form-control form-control-sm" name="privacy_policy" rows="8" style="font-size:12.5px; font-family: 'Courier New', monospace;" required><?php echo e($settings['privacy_policy']); ?></textarea>
                        <div class="text-end mt-2">
                            <button type="submit" name="save_privacy_policy" class="btn btn-sm btn-success px-3">Save</button>
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
             SECTION 1.7: FORMS (select a committee, then a program, to manage its fields inline)
        ============================== -->
        <div class="tab-pane-custom" id="formsTab">
            <div class="config-card">
                <div class="card-section-title"><i class="bi bi-file-earmark-text"></i> Forms</div>
                <div class="card-section-sub">Pick a committee, then a program, to add, edit, reorder, or remove its application-form fields right here — changes take effect immediately.</div>

                <?php if (empty($formPanels)): ?>
                    <p class="text-muted py-3 mb-0">No programs yet.</p>
                <?php else: ?>
                    <div class="row g-3 mb-4" style="max-width:640px;">
                        <div class="col-md-6">
                            <label class="form-label">Committee</label>
                            <select class="form-select" id="formsCommitteeSelect">
                                <option value="" disabled selected>Select a committee</option>
                                <?php foreach ($formCommitteeOptions as $cid => $cname): ?>
                                    <option value="<?php echo $cid; ?>"><?php echo e($cname); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Program</label>
                            <select class="form-select" id="formsProgramSelect" disabled>
                                <option value="" selected>Select a committee first</option>
                            </select>
                        </div>
                    </div>
                <?php endif; ?>

                <p class="text-muted py-3 mb-0" id="formsEmptyState">Select a committee and a program above to manage its form.</p>

                <div id="formsPanelsWrap">
                    <?php foreach ($formPanels as $panel):
                        $tabId = $panel['tab_id'];
                        $fields = $panel['fields'];
                        $programIdAttr = $panel['program_id'] !== null ? (int)$panel['program_id'] : '';
                    ?>
                        <div class="forms-panel" data-form-tab="<?php echo $tabId; ?>" data-committee-id="<?php echo $panel['committee_id']; ?>" style="display:none;">
                            <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                                <span class="committee-tag"><?php echo e($panel['committee_name']); ?></span>
                                <h6 class="mb-0 fw-bold"><?php echo e($panel['label']); ?></h6>
                                <span class="text-muted" style="font-size:12px; font-weight:500;">(<?php echo count($fields); ?> field<?php echo count($fields) === 1 ? '' : 's'; ?>)</span>
                            </div>

                            <div class="d-flex gap-2 align-items-center mb-3 flex-wrap">
                                <button type="button" class="btn btn-sm btn-success" onclick="openAddField(<?php echo $tabId; ?>)" data-bs-toggle="modal" data-bs-target="#fieldModal<?php echo $tabId; ?>"><i class="bi bi-plus-lg me-1"></i> Add Field</button>
                                <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#previewModal<?php echo $tabId; ?>"><i class="bi bi-eye me-1"></i> Preview Form</button>
                            </div>

                            <div class="table-card">
                                <div class="table-responsive-wrap">
                                    <table class="table mb-0">
                                        <thead>
                                            <tr>
                                                <th>Icon</th>
                                                <th>Field Label</th>
                                                <th>Input Type</th>
                                                <th>Width</th>
                                                <th>Required</th>
                                                <th class="text-end">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($fields)): ?>
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted py-4">No fields yet.</td>
                                                </tr>
                                            <?php endif; ?>
                                            <?php foreach ($fields as $i => $f): ?>
                                                <tr>
                                                    <td><span class="icon-preview-swatch"><i class="bi <?php echo e($f['icon']); ?>"></i></span></td>
                                                    <td><?php echo e($f['label']); ?></td>
                                                    <td><span class="type-badge"><?php echo e($fieldTypeMapReverse[$f['input_type']] ?? $f['input_type']); ?></span></td>
                                                    <td><?php echo e($fieldWidthMapReverse[$f['width']] ?? 'Full'); ?></td>
                                                    <td><?php echo $f['is_required'] ? '<span class="required-badge">Required</span>' : '<span class="optional-badge">Optional</span>'; ?></td>
                                                    <td class="text-end">
                                                        <form method="post" class="d-inline">
                                                            <input type="hidden" name="form_committee_id" value="<?php echo $panel['committee_id']; ?>">
                                                            <input type="hidden" name="form_track" value="<?php echo e($panel['track']); ?>">
                                                            <input type="hidden" name="form_program_id" value="<?php echo $programIdAttr; ?>">
                                                            <input type="hidden" name="form_tab_id" value="<?php echo $tabId; ?>">
                                                            <input type="hidden" name="field_id" value="<?php echo $f['field_id']; ?>">
                                                            <input type="hidden" name="direction" value="up">
                                                            <button type="submit" name="move_field" class="btn btn-sm btn-light py-0 px-1" <?php echo $i === 0 ? 'disabled' : ''; ?>><i class="bi bi-arrow-up"></i></button>
                                                        </form>
                                                        <form method="post" class="d-inline">
                                                            <input type="hidden" name="form_committee_id" value="<?php echo $panel['committee_id']; ?>">
                                                            <input type="hidden" name="form_track" value="<?php echo e($panel['track']); ?>">
                                                            <input type="hidden" name="form_program_id" value="<?php echo $programIdAttr; ?>">
                                                            <input type="hidden" name="form_tab_id" value="<?php echo $tabId; ?>">
                                                            <input type="hidden" name="field_id" value="<?php echo $f['field_id']; ?>">
                                                            <input type="hidden" name="direction" value="down">
                                                            <button type="submit" name="move_field" class="btn btn-sm btn-light py-0 px-1" <?php echo $i === count($fields) - 1 ? 'disabled' : ''; ?>><i class="bi bi-arrow-down"></i></button>
                                                        </form>
                                                        <button type="button" class="btn btn-sm btn-light py-0 px-1" title="Edit" onclick='openEditField(<?php echo $tabId; ?>, <?php echo json_encode($f); ?>)' data-bs-toggle="modal" data-bs-target="#fieldModal<?php echo $tabId; ?>"><i class="bi bi-pencil"></i></button>
                                                        <form method="post" class="d-inline" onsubmit="return confirm('Remove this field? Existing submitted answers stay, but it will no longer show on the form.');">
                                                            <input type="hidden" name="form_committee_id" value="<?php echo $panel['committee_id']; ?>">
                                                            <input type="hidden" name="form_track" value="<?php echo e($panel['track']); ?>">
                                                            <input type="hidden" name="form_tab_id" value="<?php echo $tabId; ?>">
                                                            <input type="hidden" name="field_id" value="<?php echo $f['field_id']; ?>">
                                                            <button type="submit" name="archive_field" class="btn btn-sm btn-light py-0 px-1 text-danger" title="Remove"><i class="bi bi-trash"></i></button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- ADD/EDIT FIELD MODAL -->
                        <div class="modal fade" id="fieldModal<?php echo $tabId; ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content border-0 shadow">
                                    <form method="post">
                                        <input type="hidden" name="form_committee_id" value="<?php echo $panel['committee_id']; ?>">
                                        <input type="hidden" name="form_track" value="<?php echo e($panel['track']); ?>">
                                        <input type="hidden" name="form_program_id" value="<?php echo $programIdAttr; ?>">
                                        <input type="hidden" name="form_tab_id" value="<?php echo $tabId; ?>">
                                        <input type="hidden" name="field_id" id="editingFieldId<?php echo $tabId; ?>" value="">
                                        <div class="modal-header">
                                            <h6 class="modal-title fw-bold" id="fieldModalLabel<?php echo $tabId; ?>"><i class="bi bi-plus-circle me-2"></i>Add Field</h6>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body p-4">
                                            <div class="mb-3">
                                                <label class="form-label" style="font-size:13px;font-weight:600;">Field Label</label>
                                                <input type="text" class="form-control form-control-sm" name="label" id="fieldLabelInput<?php echo $tabId; ?>" required>
                                            </div>
                                            <div class="row g-2 mb-3">
                                                <div class="col-md-6">
                                                    <label class="form-label" style="font-size:13px;font-weight:600;">Input Type</label>
                                                    <select class="form-select form-select-sm" name="input_type" id="fieldTypeSelect<?php echo $tabId; ?>" onchange="toggleOptionsField(<?php echo $tabId; ?>)">
                                                        <option>Text</option>
                                                        <option>Number</option>
                                                        <option>Date</option>
                                                        <option>Textarea</option>
                                                        <option>Dropdown</option>
                                                        <option>Radio Buttons</option>
                                                        <option>File Upload</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label" style="font-size:13px;font-weight:600;">Column Width</label>
                                                    <select class="form-select form-select-sm" name="width" id="fieldWidthSelect<?php echo $tabId; ?>">
                                                        <option>1/3</option>
                                                        <option>Half</option>
                                                        <option>2/3</option>
                                                        <option selected>Full</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="mb-3" id="optionsFieldWrap<?php echo $tabId; ?>" style="display:none;">
                                                <label class="form-label" style="font-size:13px;font-weight:600;">Options <span class="text-muted fw-normal">(comma-separated)</span></label>
                                                <input type="text" class="form-control form-control-sm" name="options" id="fieldOptionsInput<?php echo $tabId; ?>" placeholder="e.g. Cash Assistance, In-kind Assistance">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label" style="font-size:13px;font-weight:600;">Icon</label>
                                                <div class="icon-radio-grid">
                                                    <?php foreach ($iconChoices as $ic): ?>
                                                        <input type="radio" name="icon" id="icon<?php echo $tabId; ?>-<?php echo e($ic); ?>" value="<?php echo e($ic); ?>" <?php echo $ic === 'bi-fonts' ? 'checked' : ''; ?>>
                                                        <label for="icon<?php echo $tabId; ?>-<?php echo e($ic); ?>"><i class="bi <?php echo e($ic); ?>"></i></label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="required" id="fieldRequiredCheck<?php echo $tabId; ?>">
                                                <label class="form-check-label" for="fieldRequiredCheck<?php echo $tabId; ?>" style="font-size:13px;">Required field</label>
                                            </div>
                                        </div>
                                        <div class="modal-footer border-0">
                                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="save_field" class="btn btn-sm btn-success"><i class="bi bi-save me-1"></i> Save Field</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- PREVIEW MODAL -->
                        <div class="modal fade" id="previewModal<?php echo $tabId; ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Applicant Preview</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body p-4">
                                        <div class="preview-form-card p-2">
                                            <h5 class="text-center fw-bold mb-4" style="letter-spacing:0.5px;"><?php echo e(mb_strtoupper($panel['committee_name'] . ' - ' . $panel['label'])); ?> APPLICATION FORM</h5>
                                            <?php renderDynamicFormFields($panel['committee_id'], [], [], $panel['track'], $panel['program_id']); ?>
                                            <button type="button" class="btn btn-success w-100 mt-2" style="letter-spacing:1px; font-weight:700;" disabled>SUBMIT</button>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div><!-- /formsTab -->


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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Tab switching
        function activateTab(tabName) {
            const btn = document.querySelector('.content-tab-btn[data-tab="' + tabName + '"]');
            const pane = document.getElementById(tabName);
            if (!btn || !pane) return;
            document.querySelectorAll('.content-tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-pane-custom').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            pane.classList.add('active');
        }

        document.querySelectorAll('.content-tab-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                activateTab(btn.dataset.tab);
            });
        });

        // Forms tab — committee -> program picker shows exactly one program's field editor at a
        // time (every editor is already rendered in the page; this just toggles which one is visible).
        const formPanelsByCommittee = <?php echo json_encode($formPanelsByCommittee); ?>;
        const formsCommitteeSelect = document.getElementById('formsCommitteeSelect');
        const formsProgramSelect = document.getElementById('formsProgramSelect');
        const formsEmptyState = document.getElementById('formsEmptyState');

        function showFormsPanel(tabId) {
            document.querySelectorAll('.forms-panel').forEach(p => p.style.display = 'none');
            const panel = tabId ? document.querySelector('.forms-panel[data-form-tab="' + tabId + '"]') : null;
            if (panel) {
                panel.style.display = 'block';
                formsEmptyState.style.display = 'none';
            } else {
                formsEmptyState.style.display = 'block';
            }
        }

        function populateFormsProgramSelect(committeeId, selectTabId) {
            const programs = formPanelsByCommittee[committeeId] || [];
            formsProgramSelect.innerHTML = '<option value="" disabled selected>Select a program</option>';
            programs.forEach(function(p) {
                const opt = document.createElement('option');
                opt.value = p.tab_id;
                opt.textContent = p.label;
                if (String(p.tab_id) === String(selectTabId)) opt.selected = true;
                formsProgramSelect.appendChild(opt);
            });
            formsProgramSelect.disabled = programs.length === 0;
        }

        if (formsCommitteeSelect) {
            formsCommitteeSelect.addEventListener('change', function() {
                populateFormsProgramSelect(this.value, null);
                showFormsPanel(null);
            });

            formsProgramSelect.addEventListener('change', function() {
                showFormsPanel(this.value);
            });
        }

        // A redirect-after-POST from the Forms tab (Add/Edit/Reorder/Remove Field) carries
        // ?tab=formsTab&ftab=<program tab id> so the admin lands back where they were instead of
        // the default Settings tab, with the same committee/program still selected.
        (function() {
            const params = new URLSearchParams(location.search);
            const tab = params.get('tab');
            if (tab) activateTab(tab);

            const ftab = params.get('ftab');
            if (ftab && formsCommitteeSelect) {
                const panelEl = document.querySelector('.forms-panel[data-form-tab="' + ftab + '"]');
                if (panelEl) {
                    const committeeId = panelEl.dataset.committeeId;
                    formsCommitteeSelect.value = committeeId;
                    populateFormsProgramSelect(committeeId, ftab);
                    showFormsPanel(ftab);
                    panelEl.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                }
            }
        })();

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

        // Forms tab — inline field editor, one Add/Edit modal per program (ids suffixed by tab id)
        function toggleOptionsField(tabId) {
            const type = document.getElementById('fieldTypeSelect' + tabId).value;
            document.getElementById('optionsFieldWrap' + tabId).style.display = (type === 'Dropdown' || type === 'Radio Buttons') ? 'block' : 'none';
        }

        function openAddField(tabId) {
            document.getElementById('editingFieldId' + tabId).value = '';
            document.getElementById('fieldLabelInput' + tabId).value = '';
            document.getElementById('fieldTypeSelect' + tabId).value = 'Text';
            document.getElementById('fieldWidthSelect' + tabId).value = 'Full';
            document.getElementById('fieldOptionsInput' + tabId).value = '';
            document.getElementById('fieldRequiredCheck' + tabId).checked = false;
            document.getElementById('icon' + tabId + '-bi-fonts').checked = true;
            document.getElementById('fieldModalLabel' + tabId).innerHTML = '<i class="bi bi-plus-circle me-2"></i>Add Field';
            toggleOptionsField(tabId);
        }

        const FORM_FIELD_TYPE_LABELS = {
            text: 'Text',
            number: 'Number',
            date: 'Date',
            textarea: 'Textarea',
            dropdown: 'Dropdown',
            radio: 'Radio Buttons',
            file: 'File Upload'
        };
        const FORM_FIELD_WIDTH_LABELS = {
            third: '1/3',
            half: 'Half',
            two_third: '2/3',
            full: 'Full'
        };

        function openEditField(tabId, f) {
            document.getElementById('editingFieldId' + tabId).value = f.field_id;
            document.getElementById('fieldLabelInput' + tabId).value = f.label;
            document.getElementById('fieldTypeSelect' + tabId).value = FORM_FIELD_TYPE_LABELS[f.input_type] || 'Text';
            document.getElementById('fieldWidthSelect' + tabId).value = FORM_FIELD_WIDTH_LABELS[f.width] || 'Full';
            document.getElementById('fieldRequiredCheck' + tabId).checked = !!Number(f.is_required);
            const opts = f.options ? JSON.parse(f.options).join(', ') : '';
            document.getElementById('fieldOptionsInput' + tabId).value = opts;
            const iconEl = document.getElementById('icon' + tabId + '-' + f.icon);
            if (iconEl) iconEl.checked = true;
            document.getElementById('fieldModalLabel' + tabId).innerHTML = '<i class="bi bi-pencil me-2"></i>Edit Field';
            toggleOptionsField(tabId);
        }
    </script>
</body>

</html>