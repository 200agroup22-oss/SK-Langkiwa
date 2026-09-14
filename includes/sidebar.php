<?php
require_once __DIR__ . '/../config/scholars.php';
requireRole('scholar');

$me = currentUser();
$scholar = getScholarByUserId($me['user_id']);
$term = getCurrentTerm();
$committeeId = getCommitteeIdByCode('education');
$docFields = array_values(array_filter(getFormFields($committeeId, 'scholarship'), fn($f) => $f['input_type'] === 'file'));
$windowOpen = isRequirementsWindowOpen($term);

$formError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $scholar) {
    if (!$windowOpen) {
        setFlash('error', 'Requirements submission is currently closed. It opens again once the current semester ends.');
        header("Location: UpdateRequirements.php");
        exit();
    }

    $yearLevel = (int)($_POST['year_level'] ?? 0);
    if ($yearLevel >= 1 && $yearLevel <= 5) {
        $stmt = $conn->prepare("UPDATE scholars SET year_level = ? WHERE scholar_id = ?");
        $stmt->bind_param('ii', $yearLevel, $scholar['scholar_id']);
        $stmt->execute();
        $stmt->close();
        $scholar['year_level'] = $yearLevel;
    }

    $errors = [];
    foreach ($docFields as $field) {
        $key = $field['field_key'];
        if (isset($_FILES[$key]) && $_FILES[$key]['error'] !== UPLOAD_ERR_NO_FILE) {
            try {
                $uploaded = handleUpload($_FILES[$key]);
                if ($uploaded) {
                    $del = $conn->prepare("DELETE FROM scholar_requirement_files WHERE scholar_id = ? AND field_id = ? AND academic_year = ? AND semester = ?");
                    $del->bind_param('iiss', $scholar['scholar_id'], $field['field_id'], $term['current_academic_year'], $term['current_semester']);
                    $del->execute();
                    $del->close();

                    $ins = $conn->prepare("INSERT INTO scholar_requirement_files (scholar_id, field_id, academic_year, semester, file_path, original_name) VALUES (?, ?, ?, ?, ?, ?)");
                    $ins->bind_param('iissss', $scholar['scholar_id'], $field['field_id'], $term['current_academic_year'], $term['current_semester'], $uploaded['path'], $uploaded['original_name']);
                    $ins->execute();
                    $ins->close();
                }
            } catch (RuntimeException $ex) {
                $errors[] = $ex->getMessage();
            }
        }
    }

    if (empty($errors)) {
        logAudit('Submitted Requirements', 'Scholar #' . $scholar['scholar_id']);
        setFlash('success', 'Requirements submitted.');
    } else {
        setFlash('error', implode(' ', $errors));
    }
    header("Location: UpdateRequirements.php");
    exit();
}

$currentFiles = [];
if ($scholar) {
    $stmt = $conn->prepare("SELECT field_id, original_name FROM scholar_requirement_files WHERE scholar_id = ? AND academic_year = ? AND semester = ?");
    $stmt->bind_param('iss', $scholar['scholar_id'], $term['current_academic_year'], $term['current_semester']);
    $stmt->execute();
    foreach ($stmt->get_result() as $row) {
        $currentFiles[(int)$row['field_id']] = $row['original_name'];
    }
    $stmt->close();
}

$deadlinePassed = !empty($term['requirements_deadline']) && strtotime($term['requirements_deadline']) < strtotime('today');

$reqSuccess = getFlash('success');
$reqError = getFlash('error');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Requirements</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f0f0f0;
        }

        .form-wrapper {
            background: #fff;
            border: 2px solid #b2ddb2;
            border-radius: 16px;
            padding: 36px 50px;
            max-width: 780px;
            margin: 0 auto;
        }

        .form-title {
            font-size: 20px;
            font-weight: 800;
            text-align: center;
            color: #1a1a1a;
            letter-spacing: 1px;
            margin-bottom: 4px;
        }

        .form-divider {
            border: none;
            border-top: 2px solid #45b84d;
            width: 60px;
            margin: 8px auto 28px;
        }

        .form-label {
            font-size: 13px;
            font-weight: 500;
            color: #333;
        }

        .file-box {
            border: 1px solid #bbb;
            border-radius: 6px;
            padding: 10px 14px;
            font-size: 13px;
            color: #555;
            display: flex;
            align-items: center;
            background: #fff;
            cursor: pointer;
        }

        .file-box-label {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .file-box.has-file {
            border-color: #45b84d;
            color: #2e7d32;
        }

        .file-box.has-file .file-box-icon {
            color: #2e7d32;
        }

        .file-box i {
            font-size: 15px;
            color: #555;
        }

        .btn-submit {
            background-color: #b2ddb2;
            color: #1a1a1a;
            font-weight: 700;
            font-size: 15px;
            letter-spacing: 1px;
            border: none;
            border-radius: 8px;
            padding: 12px;
            width: 100%;
            margin-top: 10px;
        }

        .btn-submit:hover {
            background-color: #45b84d;
            color: #fff;
        }

        .doc-status {
            background-color: #e8f5e9;
            border: 1px solid #a5d6a7;
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 12px;
            color: #2e7d32;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 6px;
        }

        .term-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            padding: 6px 13px;
            border-radius: 20px;
        }

        .term-badge.ay {
            background-color: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #a5d6a7;
        }

        .term-badge.sem {
            background-color: #e3f2fd;
            color: #1565c0;
            border: 1px solid #90caf9;
        }

        .term-badge.deadline {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffe69c;
        }

        .term-badge.deadline.overdue {
            background-color: #f8d7da;
            color: #842029;
            border: 1px solid #f1aeb5;
        }
    </style>
</head>

<body>
    <?php include(__DIR__ . '/../includes/scholarnav.php') ?>

    <div class="container-fluid px-4 min-vh-100 d-flex align-items-center justify-content-center py-4">
        <div class="form-wrapper">
            <div class="form-title">REQUIREMENTS SUBMISSION</div>
            <hr class="form-divider">

            <?php if ($reqSuccess): ?><div class="alert alert-success py-2"><?php echo e($reqSuccess); ?></div><?php endif; ?>
            <?php if ($reqError): ?><div class="alert alert-danger py-2"><?php echo e($reqError); ?></div><?php endif; ?>

            <form method="post" enctype="multipart/form-data">
                <div class="d-flex align-items-center justify-content-center gap-2 mb-4 flex-wrap">
                    <span class="term-badge ay"><i class="bi bi-calendar3"></i> A.Y. <?php echo e($term['current_academic_year']); ?></span>
                    <span class="term-badge sem"><i class="bi bi-book"></i> <?php echo e($term['current_semester']); ?></span>
                    <?php if (!empty($term['requirements_deadline'])): ?>
                        <span class="term-badge deadline<?php echo $deadlinePassed ? ' overdue' : ''; ?>">
                            <i class="bi bi-clock-history"></i> Deadline: <?php echo date('M j, Y', strtotime($term['requirements_deadline'])); ?><?php echo $deadlinePassed ? ' (passed)' : ''; ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if (!$windowOpen): ?>
                    <div class="alert alert-secondary d-flex align-items-center gap-2 mb-4" style="font-size:13px;">
                        <i class="bi bi-lock-fill"></i>
                        <div>Requirements submission is currently closed. It opens again once the current semester ends and the new term's renewal window is announced.</div>
                    </div>
                <?php endif; ?>

                <!-- Scholar Info -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Scholar Name</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                            <input type="text" class="form-control" value="<?php echo e($me['first_name'] . ' ' . $me['last_name']); ?>" style="background:#f8f9fa; font-size:13px;" readonly>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Scholar ID</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="bi bi-person-badge-fill"></i></span>
                            <input type="text" class="form-control" value="<?php echo $scholar ? str_pad($scholar['scholar_id'], 3, '0', STR_PAD_LEFT) : ''; ?>" style="background:#f8f9fa; font-size:13px;" readonly>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Year Level</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="bi bi-mortarboard-fill"></i></span>
                            <select class="form-select" name="year_level" style="font-size:13px;" <?php echo $windowOpen ? '' : 'disabled'; ?>>
                                <option value="" disabled <?php echo empty($scholar['year_level']) ? 'selected' : ''; ?>>Select Year Level</option>
                                <?php for ($y = 1; $y <= 4; $y++): ?>
                                    <option value="<?php echo $y; ?>" <?php echo ($scholar && (int)$scholar['year_level'] === $y) ? 'selected' : ''; ?>><?php echo $y; ?><?php echo ['', 'st', 'nd', 'rd', 'th'][$y] ?? 'th'; ?> Year</option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <hr class="mb-4">

                <!-- File Uploads -->
                <div class="row mb-4 align-items-end">
                    <?php foreach ($docFields as $field): ?>
                        <div class="col-md-3">
                            <label class="form-label"><?php echo e($field['label']); ?><br>&nbsp;</label>
                            <?php if ($windowOpen): ?>
                                <label class="file-box w-100">
                                    <i class="bi bi-upload file-box-icon"></i> &nbsp;&nbsp;<span class="file-box-label">Attach File</span>
                                    <input type="file" class="d-none" name="<?php echo e($field['field_key']); ?>" accept=".jpg,.jpeg,.png,.pdf">
                                </label>
                            <?php endif; ?>
                            <?php if (!empty($currentFiles[$field['field_id']])): ?>
                                <div class="doc-status mt-2">
                                    <i class="bi bi-check-circle-fill"></i> <?php echo e($currentFiles[$field['field_id']]); ?>
                                </div>
                            <?php else: ?>
                                <div class="doc-status mt-2" style="background:#fff3cd;border-color:#ffe69c;color:#856404;">
                                    <i class="bi bi-exclamation-circle-fill"></i> Not yet submitted this term
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Submit -->
                <?php if ($windowOpen): ?>
                    <button type="submit" class="btn-submit">
                        <i class="bi bi-upload me-2"></i> SUBMIT REQUIREMENTS
                    </button>
                <?php endif; ?>

            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('.file-box input[type="file"]').forEach(function(input) {
            input.addEventListener('change', function() {
                const box = input.closest('.file-box');
                const label = box.querySelector('.file-box-label');
                const hasFile = input.files && input.files.length > 0;
                label.textContent = hasFile ? input.files[0].name : 'Attach File';
                box.classList.toggle('has-file', hasFile);
            });
        });
    </script>
</body>

</html>