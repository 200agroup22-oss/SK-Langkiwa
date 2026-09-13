<?php
require_once __DIR__ . '/../config/scholars.php';
requireRole('scholar');

$me = currentUser();
$scholar = getScholarByUserId($me['user_id']);
$term = getCurrentTerm();
$allowance = $scholar ? ensureAllowanceRecord($scholar['scholar_id']) : null;

$rows = [];
if ($scholar) {
    $stmt = $conn->prepare("SELECT a.title, a.activity_date, att.status
        FROM attendance att JOIN activities a ON a.activity_id = att.activity_id
        WHERE att.scholar_id = ? AND a.academic_year = ? AND a.semester = ? AND a.archived_at IS NULL
        ORDER BY a.activity_date ASC");
    $stmt->bind_param('iss', $scholar['scholar_id'], $term['current_academic_year'], $term['current_semester']);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$eligibilityLabel = ['eligible' => 'Eligible', 'pending' => 'Pending', 'not_eligible' => 'Not Eligible'];
$statusLabel = ['pending' => 'Awaiting Approval', 'approved' => 'Released', 'declined' => 'Declined'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Allowance</title>
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
        }

        .summary-card .lbl {
            font-size: 11px;
            font-weight: 600;
            color: #777;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-top: 4px;
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
            margin-bottom: 12px;
        }
    </style>
</head>

<body>
    <?php include(__DIR__ . '/../includes/scholarnav.php') ?>

    <div class="container-fluid px-4" style="margin-top: 80px;">

        <div class="mb-3">
            <div style="font-size: 22px; font-weight: 700; margin-bottom: 4px;">
                <i class="bi bi-cash-coin text-success"></i> My Allowance
            </div>
            <p class="text-muted mb-0" style="font-size: 13px;">Monitor your scholarship allowance status for the current semester.</p>
        </div>

        <?php if (!$allowance): ?>
            <div class="alert alert-warning">No allowance record found yet for this term.</div>
        <?php else: ?>

            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="summary-card">
                        <div class="num text-success">₱<?php echo number_format($allowance['amount'], 0); ?></div>
                        <div class="lbl">Allowance This Semester</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="summary-card">
                        <div class="num" style="color: #45b84d;"><?php echo $allowance['activities_completed']; ?> / <?php echo $allowance['activities_required']; ?></div>
                        <div class="lbl">Activities Completed</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="summary-card">
                        <div class="num" style="color: #45b84d;"><?php echo e($statusLabel[$allowance['status']]); ?></div>
                        <div class="lbl">Payout Status</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="summary-card">
                        <div class="num <?php echo $allowance['eligibility'] === 'eligible' ? 'text-success' : 'text-warning'; ?>"><?php echo e($eligibilityLabel[$allowance['eligibility']]); ?></div>
                        <div class="lbl">Allowance Status</div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-lg-8">
                    <div class="section-card">
                        <div class="section-title"><i class="bi bi-table"></i> Activity Compliance</div>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle mb-0" style="font-size: 13px;">
                                <thead class="table-success">
                                    <tr>
                                        <th>#</th>
                                        <th>Activity</th>
                                        <th>Date</th>
                                        <th>Attendance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($rows)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted">No activities assigned this term yet.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($rows as $i => $r): ?>
                                        <tr>
                                            <td><?php echo $i + 1; ?></td>
                                            <td><?php echo e($r['title']); ?></td>
                                            <td><?php echo date('F j, Y', strtotime($r['activity_date'])); ?></td>
                                            <td>
                                                <?php if ($r['status'] === 'present'): ?>
                                                    <span class="text-success fw-semibold">Present</span>
                                                <?php elseif ($r['status'] === 'absent'): ?>
                                                    <span class="text-danger fw-semibold">Absent</span>
                                                <?php else: ?>
                                                    <span class="text-muted fw-semibold">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="section-card mb-3">
                        <div class="section-title"><i class="bi bi-cash-stack"></i> Allowance Details</div>
                        <div class="d-flex justify-content-between mb-2" style="font-size: 13px;">
                            <span class="text-muted">Scholarship Year</span>
                            <span class="fw-semibold"><?php echo e($term['current_academic_year']); ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2" style="font-size: 13px;">
                            <span class="text-muted">Semester</span>
                            <span class="fw-semibold"><?php echo e($term['current_semester']); ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2" style="font-size: 13px;">
                            <span class="text-muted">Total Allowance</span>
                            <span class="fw-semibold text-success">₱<?php echo number_format($allowance['amount'], 0); ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2" style="font-size: 13px;">
                            <span class="text-muted">Release Schedule</span>
                            <span class="fw-semibold">End of Semester</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2" style="font-size: 13px;">
                            <span class="text-muted">Scholar Status</span>
                            <span class="fw-semibold text-success"><?php echo ucfirst($scholar['status']); ?></span>
                        </div>
                        <div class="d-flex justify-content-between" style="font-size: 13px;">
                            <span class="text-muted">Eligibility</span>
                            <span class="fw-semibold <?php echo $allowance['eligibility'] === 'eligible' ? 'text-success' : 'text-warning'; ?>"><?php echo e($eligibilityLabel[$allowance['eligibility']]); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
