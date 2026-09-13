<?php
require_once __DIR__ . '/../../config/forms.php';
requireRole('admin');

$committeeId = getCommitteeIdByCode('sports');
$track = 'assistance';

// Every custom program added under Sports's Assistance track has its own independent form — so
// reflect which program tab the admin actually clicked (via ?ptab=) in the heading instead of
// always showing the base tab's label.
$baseTabLabel = getProgramTab($committeeId, 'assistance', 'Sports Assistance Program')['label'];
$viewingTab = isset($_GET['ptab']) ? getProgramTabById((int)$_GET['ptab']) : null;
$formHeading = $viewingTab['label'] ?? $baseTabLabel;
// null on the base tab (manages the base form); a program's own id when viewing one of its tabs
// (manages that program's own, separate field set).
$programId = $viewingTab['program_id'] ?? null;

function slugifyFieldKey($label, $committeeId, $track, $conn, $programId = null) {
    $base = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $label), '_'));
    if ($base === '') {
        $base = 'field';
    }
    $key = $base;
    $i = 2;
    while (true) {
        $sql = "SELECT field_id FROM form_fields WHERE committee_id = ? AND program_track = ? AND field_key = ? AND " . ($programId !== null ? "program_id = ?" : "program_id IS NULL");
        $stmt = $conn->prepare($sql);
        if ($programId !== null) {
            $stmt->bind_param('issi', $committeeId, $track, $key, $programId);
        } else {
            $stmt->bind_param('iss', $committeeId, $track, $key);
        }
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$exists) {
            return $key;
        }
        $key = $base . '_' . $i;
        $i++;
    }
}

$typeMap = ['Text' => 'text', 'Number' => 'number', 'Date' => 'date', 'Textarea' => 'textarea', 'Dropdown' => 'dropdown', 'Radio Buttons' => 'radio', 'File Upload' => 'file'];
$typeMapReverse = array_flip($typeMap);
$widthMap = ['1/3' => 'third', 'Half' => 'half', '2/3' => 'two_third', 'Full' => 'full'];
$widthMapReverse = array_flip($widthMap);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['save_field'])) {
        $label = trim($_POST['label'] ?? '');
        $inputType = $typeMap[$_POST['input_type'] ?? 'Text'] ?? 'text';
        $icon = $_POST['icon'] ?? 'bi-fonts';
        $width = $widthMap[$_POST['width'] ?? 'Full'] ?? 'full';
        $required = isset($_POST['required']) ? 1 : 0;
        $optionsText = trim($_POST['options'] ?? '');
        $options = null;
        if (($inputType === 'dropdown' || $inputType === 'radio') && $optionsText !== '') {
            $options = json_encode(array_values(array_filter(array_map('trim', explode(',', $optionsText)))));
        }
        $fieldId = (int)($_POST['field_id'] ?? 0);

        if ($label === '') {
            setFlash('error', 'Field label is required.');
        } elseif ($fieldId > 0) {
            $stmt = $conn->prepare("UPDATE form_fields SET label=?, input_type=?, icon=?, width=?, is_required=?, options=? WHERE field_id=? AND committee_id=? AND program_track=?");
            $stmt->bind_param('ssssisiis', $label, $inputType, $icon, $width, $required, $options, $fieldId, $committeeId, $track);
            $stmt->execute();
            $stmt->close();
            logAudit('Updated Form Field', $label);
            setFlash('success', 'Field updated.');
        } else {
            $fieldKey = slugifyFieldKey($label, $committeeId, $track, $conn, $programId);
            $stmt = $conn->prepare("SELECT COALESCE(MAX(sort_order),0) AS m FROM form_fields WHERE committee_id = ? AND program_track = ?");
            $stmt->bind_param('is', $committeeId, $track);
            $stmt->execute();
            $maxOrder = $stmt->get_result()->fetch_assoc()['m'];
            $stmt->close();
            $sortOrder = $maxOrder + 1;

            $stmt = $conn->prepare("INSERT INTO form_fields (committee_id, program_track, program_id, label, field_key, input_type, icon, width, is_required, options, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('isisssssisi', $committeeId, $track, $programId, $label, $fieldKey, $inputType, $icon, $width, $required, $options, $sortOrder);
            $stmt->execute();
            $stmt->close();
            logAudit('Added Form Field', $label . ' (' . $formHeading . ')');
            setFlash('success', 'Field added.');
        }
    }

    if (isset($_POST['archive_field'])) {
        $fieldId = (int)$_POST['field_id'];
        $stmt = $conn->prepare("UPDATE form_fields SET archived_at = NOW() WHERE field_id = ? AND committee_id = ? AND program_track = ?");
        $stmt->bind_param('iis', $fieldId, $committeeId, $track);
        $stmt->execute();
        $stmt->close();
        logAudit('Removed Form Field', 'Field #' . $fieldId);
        setFlash('success', 'Field removed.');
    }

    if (isset($_POST['move_field'])) {
        $fieldId = (int)$_POST['field_id'];
        $direction = $_POST['direction'] === 'up' ? 'up' : 'down';
        $scopeFields = getFormFields($committeeId, $track, $programId);
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

    header("Location: SportsForms.php" . ($viewingTab ? '?ptab=' . $viewingTab['tab_id'] : ''));
    exit();
}

$pageSuccess = getFlash('success');
$pageError = getFlash('error');
$fields = getFormFields($committeeId, $track, $programId);

$iconChoices = ['bi-fonts', 'bi-person', 'bi-geo-alt', 'bi-building', 'bi-mortarboard', 'bi-calendar3', 'bi-123', 'bi-menu-button-wide', 'bi-chat-left-text', 'bi-upload', 'bi-telephone', 'bi-envelope', 'bi-cash-coin', 'bi-box-seam', 'bi-person-vcard', 'bi-file-earmark-text'];

$activeLink = 'SportsForms';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Form - Sports</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo APP_BASE; ?>/assets/css/admin.css" rel="stylesheet">
    <style>
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

    <?php include __DIR__ . '/../../includes/adminsidebar.php'; ?>

    <div class="main-content">
        <h4 class="fw-bold mb-1">Application Form — <?php echo e($formHeading); ?></h4>
        <p class="text-muted mb-2" style="font-size: 13px;">
            Manage the fields applicants fill out on <?php echo e($formHeading); ?>'s application form. Changes take effect immediately.
            <?php if ($viewingTab): ?>
                This form is independent from <strong><?php echo e($baseTabLabel); ?></strong> and every other Sports program — it started as a copy of the base form, and changes here only affect <strong><?php echo e($formHeading); ?></strong>.
            <?php endif; ?>
        </p>

        <?php if ($pageSuccess): ?><div class="alert alert-success py-2"><?php echo e($pageSuccess); ?></div><?php endif; ?>
        <?php if ($pageError): ?><div class="alert alert-danger py-2"><?php echo e($pageError); ?></div><?php endif; ?>

        <div class="d-flex gap-2 align-items-center mb-3 flex-wrap">
            <button class="btn btn-sm btn-success" onclick="openAddField()" data-bs-toggle="modal" data-bs-target="#fieldModal"><i class="bi bi-plus-lg me-1"></i> Add Field</button>
            <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#previewFormModal"><i class="bi bi-eye me-1"></i> Preview Form</button>
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
                                <td><span class="type-badge"><?php echo e($typeMapReverse[$f['input_type']] ?? $f['input_type']); ?></span></td>
                                <td><?php echo e($widthMapReverse[$f['width']] ?? 'Full'); ?></td>
                                <td><?php echo $f['is_required'] ? '<span class="required-badge">Required</span>' : '<span class="optional-badge">Optional</span>'; ?></td>
                                <td class="text-end">
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="field_id" value="<?php echo $f['field_id']; ?>">
                                        <input type="hidden" name="direction" value="up">
                                        <button type="submit" name="move_field" class="btn btn-sm btn-light py-0 px-1" <?php echo $i === 0 ? 'disabled' : ''; ?>><i class="bi bi-arrow-up"></i></button>
                                    </form>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="field_id" value="<?php echo $f['field_id']; ?>">
                                        <input type="hidden" name="direction" value="down">
                                        <button type="submit" name="move_field" class="btn btn-sm btn-light py-0 px-1" <?php echo $i === count($fields) - 1 ? 'disabled' : ''; ?>><i class="bi bi-arrow-down"></i></button>
                                    </form>
                                    <button class="btn btn-sm btn-light py-0 px-1" title="Edit" onclick='openEditField(<?php echo json_encode($f); ?>)' data-bs-toggle="modal" data-bs-target="#fieldModal"><i class="bi bi-pencil"></i></button>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Remove this field? Existing submitted answers stay, but it will no longer show on the form.');">
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
    <div class="modal fade" id="fieldModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <form method="post">
                    <input type="hidden" name="field_id" id="editingFieldId" value="">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold" id="fieldModalLabel"><i class="bi bi-plus-circle me-2"></i>Add Field</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label" style="font-size:13px;font-weight:600;">Field Label</label>
                            <input type="text" class="form-control form-control-sm" name="label" id="fieldLabelInput" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label" style="font-size:13px;font-weight:600;">Input Type</label>
                                <select class="form-select form-select-sm" name="input_type" id="fieldTypeSelect" onchange="toggleOptionsField()">
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
                                <select class="form-select form-select-sm" name="width" id="fieldWidthSelect">
                                    <option>1/3</option>
                                    <option>Half</option>
                                    <option>2/3</option>
                                    <option selected>Full</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3" id="optionsFieldWrap" style="display:none;">
                            <label class="form-label" style="font-size:13px;font-weight:600;">Options <span class="text-muted fw-normal">(comma-separated)</span></label>
                            <input type="text" class="form-control form-control-sm" name="options" id="fieldOptionsInput" placeholder="e.g. Cash Assistance, In-kind Assistance">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="font-size:13px;font-weight:600;">Icon</label>
                            <div class="icon-radio-grid">
                                <?php foreach ($iconChoices as $ic): ?>
                                    <input type="radio" name="icon" id="icon-<?php echo e($ic); ?>" value="<?php echo e($ic); ?>" <?php echo $ic === 'bi-fonts' ? 'checked' : ''; ?>>
                                    <label for="icon-<?php echo e($ic); ?>"><i class="bi <?php echo e($ic); ?>"></i></label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="required" id="fieldRequiredCheck">
                            <label class="form-check-label" for="fieldRequiredCheck" style="font-size:13px;">Required field</label>
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
    <div class="modal fade" id="previewFormModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Applicant Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="preview-form-card p-2">
                        <h5 class="text-center fw-bold mb-4" style="letter-spacing:0.5px;">SPORTS APPLICATION FORM</h5>
                        <?php renderDynamicFormFields($committeeId, [], [], $track, $programId); ?>
                        <button type="button" class="btn btn-success w-100 mt-2" style="letter-spacing:1px; font-weight:700;" disabled>SUBMIT</button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleOptionsField() {
            const type = document.getElementById('fieldTypeSelect').value;
            document.getElementById('optionsFieldWrap').style.display = (type === 'Dropdown' || type === 'Radio Buttons') ? 'block' : 'none';
        }

        function openAddField() {
            document.getElementById('editingFieldId').value = '';
            document.getElementById('fieldLabelInput').value = '';
            document.getElementById('fieldTypeSelect').value = 'Text';
            document.getElementById('fieldWidthSelect').value = 'Full';
            document.getElementById('fieldOptionsInput').value = '';
            document.getElementById('fieldRequiredCheck').checked = false;
            document.getElementById('icon-bi-fonts').checked = true;
            document.getElementById('fieldModalLabel').innerHTML = '<i class="bi bi-plus-circle me-2"></i>Add Field';
            toggleOptionsField();
        }

        const TYPE_LABELS = {
            text: 'Text',
            number: 'Number',
            date: 'Date',
            textarea: 'Textarea',
            dropdown: 'Dropdown',
            radio: 'Radio Buttons',
            file: 'File Upload'
        };
        const WIDTH_LABELS = {
            third: '1/3',
            half: 'Half',
            two_third: '2/3',
            full: 'Full'
        };

        function openEditField(f) {
            document.getElementById('editingFieldId').value = f.field_id;
            document.getElementById('fieldLabelInput').value = f.label;
            document.getElementById('fieldTypeSelect').value = TYPE_LABELS[f.input_type] || 'Text';
            document.getElementById('fieldWidthSelect').value = WIDTH_LABELS[f.width] || 'Full';
            document.getElementById('fieldRequiredCheck').checked = !!Number(f.is_required);
            const opts = f.options ? JSON.parse(f.options).join(', ') : '';
            document.getElementById('fieldOptionsInput').value = opts;
            const iconEl = document.getElementById('icon-' + f.icon);
            if (iconEl) iconEl.checked = true;
            document.getElementById('fieldModalLabel').innerHTML = '<i class="bi bi-pencil me-2"></i>Edit Field';
            toggleOptionsField();
        }
    </script>
</body>

</html>
