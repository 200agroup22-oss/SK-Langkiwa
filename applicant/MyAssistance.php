<?php
require_once __DIR__ . '/../config/forms.php';
requireRole(['applicant', 'scholar']);

$me = currentUser();

$stmt = $conn->prepare("SELECT a.application_id, a.committee_id, a.status, a.decline_reason, a.submitted_at, a.decided_at,
        c.name AS committee_name,
        b.beneficiary_id, b.type AS ben_type, b.amount, b.items, b.quantity, b.status AS ben_status, b.date_released, b.date_distributed
    FROM applications a
    JOIN committees c ON c.committee_id = a.committee_id
    LEFT JOIN assistance_beneficiaries b ON b.application_id = a.application_id
    WHERE a.user_id = ? AND a.program_track = 'assistance' AND a.archived_at IS NULL
    ORDER BY a.submitted_at DESC");
$stmt->bind_param('i', $me['user_id']);
$stmt->execute();
$applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$approvedCount = count(array_filter($applications, fn($a) => $a['status'] === 'approved'));
$awaitingCount = count(array_filter($applications, fn($a) => $a['status'] === 'approved' && !$a['beneficiary_id']));
$releasedCount = count(array_filter($applications, fn($a) => in_array($a['ben_status'], ['released', 'distributed'], true)));

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

function benStatusBadge($status)
{
    switch ($status) {
        case 'released':
            return ['bg-success', 'Released'];
        case 'distributed':
            return ['bg-success', 'Distributed'];
        default:
            return ['bg-warning text-dark', 'Pending Release'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Assistance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f0f0f0;
        }

        .summary-card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 16px 20px;
            text-align: center;
        }

        .summary-card .num {
            font-size: 28px;
            font-weight: 700;
            line-height: 1;
            color: #1a1a1a;
        }

        .summary-card .lbl {
            font-size: 11px;
            font-weight: 600;
            color: #777;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-top: 4px;
        }

        .assistance-card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px 22px;
            margin-bottom: 14px;
        }

        .assistance-card .committee-name {
            font-size: 15px;
            font-weight: 700;
            color: #1a1a1a;
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

        .detail-row {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            padding: 4px 0;
            border-bottom: 1px dashed #eee;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .processing-note {
            background-color: #e3f2fd;
            border-left: 4px solid #1565c0;
            border-radius: 6px;
            padding: 10px 14px;
            font-size: 13px;
            color: #0d47a1;
            margin-top: 10px;
        }
    </style>
</head>

<body>

    <?php include(__DIR__ . '/../includes/applicantnav.php') ?>

    <div class="container-fluid px-4" style="margin-top: 80px;">

        <div class="mb-3">
            <div style="font-size: 22px; font-weight: 700; margin-bottom: 4px;">
                <i class="bi bi-hand-holding-heart-fill text-success"></i> My Assistance
            </div>
            <p class="text-muted mb-0" style="font-size: 13px;">Track your financial and in-kind assistance applications across all SK committees.</p>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-md-4">
                <div class="summary-card">
                    <div class="num text-success"><?php echo $approvedCount; ?></div>
                    <div class="lbl">Approved Programs</div>
                </div>
            </div>
            <div class="col-6 col-md-4">
                <div class="summary-card">
                    <div class="num" style="color:#856404;"><?php echo $awaitingCount; ?></div>
                    <div class="lbl">Awaiting Processing</div>
                </div>
            </div>
            <div class="col-6 col-md-4">
                <div class="summary-card">
                    <div class="num text-success"><?php echo $releasedCount; ?></div>
                    <div class="lbl">Released / Distributed</div>
                </div>
            </div>
        </div>

        <?php if (empty($applications)): ?>
            <div class="assistance-card text-center text-muted py-4">
                You haven't applied for any assistance program yet. Visit <a href="ApplicationForm.php">Application Form</a> to get started.
            </div>
        <?php endif; ?>

        <?php foreach ($applications as $app): ?>
            <div class="assistance-card">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                    <div>
                        <div class="committee-name"><?php echo e($app['committee_name']); ?> Assistance</div>
                        <div class="text-muted" style="font-size:12px;">Submitted <?php echo date('F j, Y', strtotime($app['submitted_at'])); ?></div>
                    </div>
                    <span class="<?php echo statusBadgeClass($app['status']); ?>"><?php echo statusLabel($app['status']); ?></span>
                </div>

                <?php if ($app['status'] === 'declined' && $app['decline_reason']): ?>
                    <div class="alert alert-danger py-2 mb-0"><strong>Reason:</strong> <?php echo e($app['decline_reason']); ?></div>
                <?php elseif ($app['status'] === 'approved'): ?>
                    <?php if ($app['beneficiary_id']): ?>
                        <?php [$bClass, $bLabel] = benStatusBadge($app['ben_status']); ?>
                        <div class="detail-row">
                            <span class="text-muted">Type of Assistance</span>
                            <span class="fw-semibold"><?php echo $app['ben_type'] === 'cash' ? 'Cash Assistance' : 'In-Kind Assistance'; ?></span>
                        </div>
                        <?php if ($app['ben_type'] === 'cash'): ?>
                            <div class="detail-row">
                                <span class="text-muted">Amount</span>
                                <span class="fw-semibold text-success">₱<?php echo number_format($app['amount'], 2); ?></span>
                            </div>
                        <?php else: ?>
                            <div class="detail-row">
                                <span class="text-muted">Item(s)</span>
                                <span class="fw-semibold"><?php echo e($app['items']); ?><?php echo $app['quantity'] ? ' (x' . $app['quantity'] . ')' : ''; ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="detail-row">
                            <span class="text-muted">Status</span>
                            <span class="badge <?php echo $bClass; ?>"><?php echo $bLabel; ?></span>
                        </div>
                        <?php if ($app['date_released']): ?>
                            <div class="detail-row">
                                <span class="text-muted">Date Released</span>
                                <span class="fw-semibold"><?php echo date('F j, Y', strtotime($app['date_released'])); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($app['date_distributed']): ?>
                            <div class="detail-row">
                                <span class="text-muted">Date Distributed</span>
                                <span class="fw-semibold"><?php echo date('F j, Y', strtotime($app['date_distributed'])); ?></span>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="processing-note">
                            <i class="bi bi-hourglass-split me-1"></i> Your application has been approved. The SK office is preparing your assistance details (amount/items and release date) — check back soon.
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="text-muted" style="font-size:13px;">Your application is still under review by the SK office.</div>
                <?php endif; ?>

                <div class="mt-2">
                    <a href="ApplicationStatus.php" style="font-size:12px; color:#1565c0; text-decoration:none;"><i class="bi bi-file-earmark-text me-1"></i>View full application details</a>
                </div>
            </div>
        <?php endforeach; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>