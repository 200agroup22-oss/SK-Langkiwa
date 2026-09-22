<?php
require_once __DIR__ . '/../config/forms.php';
requireRole(['applicant', 'scholar']);

$me = currentUser();
$identity = ownIdentityFields($me['user_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_application'])) {
    $applicationId = (int)($_POST['application_id'] ?? 0);

    $stmt = $conn->prepare("SELECT application_id, committee_id, program_track, program_id, status FROM applications WHERE application_id = ? AND user_id = ? AND archived_at IS NULL");
    $stmt->bind_param('ii', $applicationId, $me['user_id']);
    $stmt->execute();
    $app = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$app) {
        setFlash('error', 'Application not found.');
    } else {
        foreach ($identity as $key => $val) {
            $_POST[$key] = $val;
        }
        $existingFiles = getApplicationFiles($applicationId);
        $errors = validateDynamicSubmission($app['committee_id'], $_POST, $_FILES, $existingFiles, $app['program_track'], $app['program_id']);
        if (empty($errors)) {
            saveDynamicSubmission($applicationId, $app['committee_id'], $_POST, $_FILES, $app['program_track'], $app['program_id']);
            logAudit('Updated Application', 'Application #' . $applicationId);
            setFlash('success', 'Your application was updated.');
        } else {
            setFlash('error', implode(' ', $errors));
        }
    }

    header("Location: ApplicationStatus.php");
    exit();
}

$statusError = getFlash('error');
$statusSuccess = getFlash('success');

$stmt = $conn->prepare("SELECT a.application_id, a.committee_id, a.program_track, a.program_id, a.status, a.decline_reason, a.submitted_at, c.name AS committee_name, p.name AS program_name, pt.label AS track_label
    FROM applications a
    JOIN committees c ON c.committee_id = a.committee_id
    LEFT JOIN programs p ON p.program_id = a.program_id
    LEFT JOIN program_tabs pt ON pt.committee_id = a.committee_id AND pt.track_code = a.program_track
    WHERE a.user_id = ? AND a.archived_at IS NULL
    ORDER BY a.submitted_at DESC");
$stmt->bind_param('i', $me['user_id']);
$stmt->execute();
$applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($applications as &$app) {
    $app['answers'] = getApplicationAnswers($app['application_id']);
    $app['files'] = getApplicationFiles($app['application_id']);
    $app['fields'] = getFormFields($app['committee_id'], $app['program_track'], $app['program_id']);
}
unset($app);

function statusBadgeClass($status)
{
    return $status === 'approved' ? 'badge-approved' : ($status === 'declined' ? 'badge-declined' : 'badge-pending');
}

function statusLabel($status)
{
    if ($status === 'approved') return 'Approved';
    if ($status === 'declined') return 'Declined';
    return '⏳ Pending';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Status</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        /* A <form> wrapping modal-header/body/footer breaks the scrollable-modal flex
           layout (the form isn't a flex item, so modal-body never gets a bounded
           height to scroll within and content silently clips instead). Removing the
           form from the box tree lets its children rejoin modal-content's flex layout. */
        .modal-dialog-scrollable .modal-content>form {
            display: contents;
        }

        body {
            background-color: #f0f0f0;
        }

        .section-card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 20px 24px;
        }

        .section-title {
            font-size: 14px;
            font-weight: 700;
            color: #2e7d32;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 10px;
        }

        .badge-pending {
            background-color: #fff3cd;
            color: #856404;
            font-size: 12px;
            font-weight: 600;
            border-radius: 20px;
            padding: 4px 14px;
            border: 1px solid #ffe69c;
        }

        .badge-approved {
            background-color: #d1e7dd;
            color: #0a3622;
            font-size: 12px;
            font-weight: 600;
            border-radius: 20px;
            padding: 4px 14px;
            border: 1px solid #a3cfbb;
        }

        .badge-declined {
            background-color: #f8d7da;
            color: #842029;
            font-size: 12px;
            font-weight: 600;
            border-radius: 20px;
            padding: 4px 14px;
            border: 1px solid #f1aeb5;
        }

        .btn-view {
            background-color: #e3f2fd;
            color: #1565c0;
            border: none;
            font-size: 12px;
            padding: 5px 12px;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-edit {
            background-color: #fff8e1;
            color: #f57f17;
            border: none;
            font-size: 12px;
            padding: 5px 12px;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
        }

        .modal-header {
            background: linear-gradient(90deg, #45b84d, #aadaad);
            color: #fff;
        }

        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }

        .info-label {
            font-size: 11px;
            font-weight: 700;
            color: #888;
            text-transform: uppercase;
            margin-bottom: 2px;
        }

        .info-value {
            font-size: 14px;
            font-weight: 500;
            color: #222;
            margin-bottom: 12px;
        }

        .doc-item {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f8f9fa;
            border: 1px solid #eee;
            border-radius: 6px;
            padding: 9px 14px;
            margin-bottom: 8px;
            font-size: 13px;
            color: #333;
        }

        .doc-item i {
            color: #45b84d;
            font-size: 16px;
        }

        .doc-check {
            margin-left: auto;
            color: #45b84d;
            font-size: 16px;
        }

        .section-divider {
            font-size: 12px;
            font-weight: 700;
            color: #2e7d32;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            border-bottom: 1px solid #e0e0e0;
            padding-bottom: 6px;
            margin: 16px 0 12px;
        }
    </style>
</head>

<body>

    <?php include(__DIR__ . '/../includes/applicantnav.php') ?>

    <div class="container-fluid px-4" style="margin-top: 80px;">

        <div class="mb-3">
            <div style="font-size: 22px; font-weight: 700; margin-bottom: 4px;">
                <i class="bi bi-shield-check text-success"></i> My Application
            </div>
            <p class="text-muted mb-0" style="font-size: 13px;">View and manage your scholarship / assistance applications.</p>
        </div>

        <?php if ($statusSuccess): ?>
            <div class="alert alert-success py-2"><?php echo e($statusSuccess); ?></div>
        <?php endif; ?>
        <?php if ($statusError): ?>
            <div class="alert alert-danger py-2"><?php echo e($statusError); ?></div>
        <?php endif; ?>

        <!-- Application Card -->
        <div class="section-card">
            <div class="table-responsive">
                <table class="table mb-0" style="font-size: 13px;">
                    <thead>
                        <tr style="background-color: #a5d6a7;">
                            <th style="background-color: #a5d6a7; color: #1b5e20; padding: 10px 14px; font-weight: 600; border: none;">Committee</th>
                            <th style="background-color: #a5d6a7; color: #1b5e20; padding: 10px 14px; font-weight: 600; border: none;">Program</th>
                            <th style="background-color: #a5d6a7; color: #1b5e20; padding: 10px 14px; font-weight: 600; border: none;">Date Submitted</th>
                            <th style="background-color: #a5d6a7; color: #1b5e20; padding: 10px 14px; font-weight: 600; border: none;">Status</th>
                            <th style="background-color: #a5d6a7; color: #1b5e20; padding: 10px 14px; font-weight: 600; border: none;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($applications)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">You haven't submitted any applications yet.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($applications as $app): ?>
                            <tr>
                                <td style="padding: 12px 14px; vertical-align: middle;"><?php echo e($app['committee_name']); ?></td>
                                <td style="padding: 12px 14px; vertical-align: middle;"><?php echo e($app['program_name'] ?: ($app['track_label'] ?: '—')); ?></td>
                                <td style="padding: 12px 14px; vertical-align: middle;"><?php echo date('F j, Y', strtotime($app['submitted_at'])); ?></td>
                                <td style="padding: 12px 14px; vertical-align: middle;">
                                    <span class="<?php echo statusBadgeClass($app['status']); ?>"><?php echo statusLabel($app['status']); ?></span>
                                </td>
                                <td style="padding: 12px 14px; vertical-align: middle;">
                                    <div class="d-flex gap-1">
                                        <button class="btn-view" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $app['application_id']; ?>">
                                            <i class="bi bi-eye"></i> View
                                        </button>
                                        <button class="btn-edit" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $app['application_id']; ?>">
                                            <i class="bi bi-pencil"></i> Edit
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <?php foreach ($applications as $app): ?>
        <!-- VIEW MODAL -->
        <div class="modal fade" id="viewModal<?php echo $app['application_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold"><i class="bi bi-file-earmark-text-fill me-2"></i><?php echo e($app['committee_name']); ?> Application</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">

                        <!-- Status Banner -->
                        <div class="d-flex align-items-center justify-content-between mb-3 p-3" style="background:#fff3cd; border-radius:8px; border:1px solid #ffe69c;">
                            <div style="font-size:13px; color:#664d03; font-weight:600;">
                                <i class="bi bi-hourglass-split me-1"></i> Application Status
                            </div>
                            <span class="<?php echo statusBadgeClass($app['status']); ?>"><?php echo statusLabel($app['status']); ?></span>
                        </div>

                        <?php if ($app['status'] === 'declined' && $app['decline_reason']): ?>
                            <div class="alert alert-danger py-2"><strong>Reason:</strong> <?php echo e($app['decline_reason']); ?></div>
                        <?php endif; ?>

                        <div class="section-divider"><i class="bi bi-list-check me-1"></i> Submitted Information</div>
                        <div class="row g-3 mb-2">
                            <?php foreach ($app['fields'] as $field):
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
                            <?php foreach ($app['fields'] as $field):
                                if ($field['input_type'] !== 'file') continue;
                                $file = $app['files'][$field['field_id']] ?? null;
                            ?>
                                <div class="col-md-6">
                                    <div class="info-label mb-1"><?php echo e($field['label']); ?></div>
                                    <div class="doc-item">
                                        <i class="bi bi-file-earmark-image-fill"></i>
                                        <?php echo $file ? e($file['original_name']) : 'Not submitted'; ?>
                                        <?php if ($file): ?><i class="bi bi-check-circle-fill doc-check"></i><?php endif; ?>
                                    </div>
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
        <div class="modal fade" id="editModal<?php echo $app['application_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content border-0 shadow">
                    <form method="post" enctype="multipart/form-data">
                        <div class="modal-header">
                            <h6 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit <?php echo e($app['committee_name']); ?> Application</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4">

                            <?php if ($app['status'] !== 'pending'): ?>
                                <div class="p-3 mb-3" style="background:#fff3cd; border-left:4px solid #ffc107; border-radius:6px; font-size:13px; color:#664d03;">
                                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                    This application has already been <?php echo e(strtolower(statusLabel($app['status']))); ?>. Saving changes here updates your submitted information but does not change that decision — contact your committee if you need it reviewed again.
                                </div>
                            <?php endif; ?>

                            <input type="hidden" name="application_id" value="<?php echo $app['application_id']; ?>">
                            <?php renderDynamicFormFields($app['committee_id'], $app['answers'], $app['files'], $app['program_track'], $app['program_id'], $identity); ?>

                        </div>
                        <div class="modal-footer border-0">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="edit_application" class="btn btn-sm btn-success"><i class="bi bi-save me-1"></i> Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>