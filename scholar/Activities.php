<?php
require_once __DIR__ . '/../config/scholars.php';
requireRole('scholar');

$me = currentUser();
$scholar = getScholarByUserId($me['user_id']);

$rows = [];
if ($scholar) {
    $stmt = $conn->prepare("SELECT att.attendance_id, att.status, att.qr_token, att.scanned_at,
            a.title, a.activity_date, a.activity_time, a.venue, a.note
        FROM attendance att JOIN activities a ON a.activity_id = att.activity_id
        WHERE att.scholar_id = ? AND a.archived_at IS NULL
        ORDER BY a.activity_date DESC");
    $stmt->bind_param('i', $scholar['scholar_id']);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$total = count($rows);
$present = count(array_filter($rows, fn($r) => $r['status'] === 'present'));
$pending = count(array_filter($rows, fn($r) => $r['status'] === 'pending'));
$absent = count(array_filter($rows, fn($r) => $r['status'] === 'absent'));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Activities</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f0f0f0;
        }

        .activities {
            background: #fff;
            border: 1px solid #45b84d;
            border-radius: 10px;
            padding: 20px;
            height: 100%;
        }

        .activity-title {
            font-size: 15px;
            font-weight: 700;
            color: #1a1a1a;
            margin-bottom: 8px;
        }

        .detail {
            font-size: 13px;
            color: #000000;
            margin-bottom: 4px;
        }

        .badge-pending {
            background-color: #fff3cd;
            color: #856404;
            font-size: 12px;
            border-radius: 20px;
            padding: 4px 12px;
            border: 1px solid #ffe69c;
        }

        .badge-submitted {
            background-color: #d1e7dd;
            color: #0a3622;
            font-size: 12px;
            font-weight: 600;
            border-radius: 20px;
            padding: 4px 12px;
            border: 1px solid #a3cfbb;
        }

        .badge-absent {
            background-color: #f8d7da;
            color: #842029;
            font-size: 12px;
            font-weight: 600;
            border-radius: 20px;
            padding: 4px 12px;
            border: 1px solid #f1aeb5;
        }

        .qr-section {
            background-color: #f8f9fa;
            border: 1px dashed #45b84d;
            border-radius: 8px;
            padding: 14px;
            margin-top: 14px;
            text-align: center;
        }

        .section-label {
            font-size: 13px;
            color: #000;
            margin-bottom: 6px;
            display: block;
        }

        .btn-generate-qr {
            background-color: #45b84d;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 8px;
            font-size: 13px;
            font-weight: 600;
            width: 100%;
        }

        .btn-generate-qr:hover {
            background-color: #2e7d32;
            color: #fff;
        }

        .present-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 13px;
            color: #0a3622;
            background-color: #d1e7dd;
            border-radius: 6px;
            padding: 8px 12px;
            margin-top: 14px;
        }

        .missed-notice {
            font-size: 13px;
            color: #842029;
            background-color: #f8d7da;
            border-radius: 6px;
            padding: 8px 12px;
            margin-top: 14px;
        }

        .summary-card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 16px 20px;
            text-align: center;
        }

        .summary-card .num {
            font-size: 36px;
            font-weight: 700;
            color: #1a1a1a;
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

        hr.my-divider {
            border-top: 1px solid #eee;
            margin: 12px 0;
        }
    </style>
</head>

<body>
    <?php include(__DIR__ . '/../includes/scholarnav.php') ?>

    <div class="container-fluid px-4" style="margin-top: 80px;">

        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <div>
                <div style="font-size: 22px; font-weight: 700; margin-bottom: 4px;">
                    <i class="bi bi-calendar-event-fill text-success"></i> My Activities
                </div>
                <p class="text-muted mb-0" style="font-size: 13px;">View your assigned SK activities and generate your QR code for attendance scanning.</p>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="summary-card">
                    <div class="num"><?php echo $total; ?></div>
                    <div class="lbl">Total Assigned</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="summary-card">
                    <div class="num text-success"><?php echo $present; ?></div>
                    <div class="lbl">Present</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="summary-card">
                    <div class="num" style="color: #856404;"><?php echo $pending; ?></div>
                    <div class="lbl">Pending</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="summary-card">
                    <div class="num text-danger"><?php echo $absent; ?></div>
                    <div class="lbl">Missed</div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <?php if (empty($rows)): ?>
                <div class="col-12">
                    <p class="text-muted text-center py-5">No activities have been assigned to you yet.</p>
                </div>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <div class="col-lg-4 col-md-6">
                    <div class="activities">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div class="activity-title">🟢 <?php echo e($r['title']); ?></div>
                            <?php if ($r['status'] === 'present'): ?>
                                <span class="badge-submitted">✅ Present</span>
                            <?php elseif ($r['status'] === 'absent'): ?>
                                <span class="badge-absent">❌ Absent</span>
                            <?php else: ?>
                                <span class="badge-pending"><strong>⏳ Pending</strong></span>
                            <?php endif; ?>
                        </div>
                        <div class="detail"><i class="bi bi-calendar2 text-success"></i> &nbsp;<strong>When:</strong> <?php echo date('F j, Y', strtotime($r['activity_date'])); ?><?php echo $r['activity_time'] ? ', ' . date('g:i A', strtotime($r['activity_time'])) : ''; ?></div>
                        <div class="detail"><i class="bi bi-geo-alt-fill text-success"></i> &nbsp;<strong>Where:</strong> <?php echo e($r['venue']); ?></div>
                        <?php if ($r['note']): ?><div class="detail"><i class="bi bi-info-circle text-success"></i> &nbsp;<strong>Note:</strong> <?php echo e($r['note']); ?></div><?php endif; ?>

                        <hr class="my-divider">

                        <?php if ($r['status'] === 'present'): ?>
                            <div class="present-info">
                                <div><i class="bi bi-check-circle-fill"></i> QR Code scanned</div>
                                <?php if ($r['scanned_at']): ?>
                                    <div><i class="bi bi-calendar-check"></i> Date: <strong><?php echo date('F j, Y', strtotime($r['scanned_at'])); ?></strong></div>
                                    <div><i class="bi bi-clock-fill"></i> Time: <strong><?php echo date('g:i A', strtotime($r['scanned_at'])); ?></strong></div>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($r['status'] === 'absent'): ?>
                            <div class="missed-notice">
                                <i class="bi bi-exclamation-triangle-fill"></i> &nbsp;No QR scan was recorded for this activity. Marked absent by the SK officer.
                            </div>
                        <?php else: ?>
                            <div class="qr-section">
                                <label class="section-label"><i class="bi bi-qr-code"></i> Attendance QR Code</label>
                                <p class="text-muted mb-2" style="font-size: 12px;">Generate your personal QR code and present it to the SK officer for scanning on the day of the activity.</p>
                                <button type="button" class="btn-generate-qr" data-bs-toggle="modal" data-bs-target="#qrModal<?php echo $r['attendance_id']; ?>" onclick="renderQr(<?php echo $r['attendance_id']; ?>, '<?php echo e($r['qr_token']); ?>')">
                                    <i class="bi bi-qr-code-scan"></i> Generate QR Code
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($r['status'] === 'pending'): ?>
                    <div class="modal fade" id="qrModal<?php echo $r['attendance_id']; ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title"><i class="bi bi-qr-code"></i> Attendance QR Code — <?php echo e($r['title']); ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body text-center">
                                    <div id="qrcode<?php echo $r['attendance_id']; ?>" class="d-flex justify-content-center my-3"></div>
                                    <p class="text-muted" style="font-size:12px;">Show this to the SK officer on the day of the activity.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo APP_BASE; ?>/assets/js/qrcode.min.js"></script>
    <script>
        const renderedQr = {};

        function renderQr(attendanceId, token) {
            if (renderedQr[attendanceId]) return;
            const el = document.getElementById('qrcode' + attendanceId);
            if (el) {
                new QRCode(el, {
                    text: token,
                    width: 200,
                    height: 200
                });
                renderedQr[attendanceId] = true;
            }
        }
    </script>
</body>

</html>
