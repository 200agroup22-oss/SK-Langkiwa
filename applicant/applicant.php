<?php
require_once __DIR__ . '/../config/functions.php';
requireRole('applicant');

$me = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['archive_notification'])) {
        $notificationId = (int)$_POST['notification_id'];
        $stmt = $conn->prepare("UPDATE notifications SET archived_at = NOW() WHERE notification_id = ? AND user_id = ?");
        $stmt->bind_param('ii', $notificationId, $me['user_id']);
        $stmt->execute();
        $stmt->close();
    }
    if (isset($_POST['archive_announcement'])) {
        $announcementId = (int)$_POST['announcement_id'];
        $stmt = $conn->prepare("INSERT IGNORE INTO announcement_archives (user_id, announcement_id) VALUES (?, ?)");
        $stmt->bind_param('ii', $me['user_id'], $announcementId);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: applicant.php");
    exit();
}

$settings = $conn->query("SELECT * FROM site_settings WHERE id = 1")->fetch_assoc();

$dates = $conn->query("SELECT event_name, event_date FROM important_dates ORDER BY event_date ASC LIMIT 5")->fetch_all(MYSQLI_ASSOC);

// Announcement widget combines two sources — admin-authored broadcasts (dismissible per-viewer via
// announcement_archives, since the same announcement is shown to everyone it was sent to) and this
// applicant's own personal notifications (e.g. application decisions, archivable outright since
// those are theirs alone) — merged and sorted so the most recent of either kind shows first.
$stmt = $conn->prepare("SELECT a.announcement_id, a.title, a.message, a.posted_at FROM announcements a
    WHERE a.archived_at IS NULL AND a.sent_to IN ('all','applicants')
    AND NOT EXISTS (SELECT 1 FROM announcement_archives aa WHERE aa.announcement_id = a.announcement_id AND aa.user_id = ?)
    ORDER BY a.posted_at DESC LIMIT 5");
$stmt->bind_param('i', $me['user_id']);
$stmt->execute();
$broadcastAnnouncements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("SELECT notification_id, title, message, created_at FROM notifications WHERE user_id = ? AND archived_at IS NULL ORDER BY created_at DESC LIMIT 5");
$stmt->bind_param('i', $me['user_id']);
$stmt->execute();
$personalNotifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$announcements = [];
foreach ($broadcastAnnouncements as $a) {
    $announcements[] = ['type' => 'announcement', 'id' => (int)$a['announcement_id'], 'title' => $a['title'], 'message' => $a['message'], 'posted_at' => $a['posted_at']];
}
foreach ($personalNotifications as $n) {
    $announcements[] = ['type' => 'notification', 'id' => (int)$n['notification_id'], 'title' => $n['title'], 'message' => $n['message'], 'posted_at' => $n['created_at']];
}
usort($announcements, fn($a, $b) => strtotime($b['posted_at']) <=> strtotime($a['posted_at']));
$announcements = array_slice($announcements, 0, 5);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>iSKolar ng Langkiwa</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f0f0f0;
        }

        .welcome-banner {
            background: linear-gradient(135deg, #c8efc8, #e8f8e8);
            border: 1px solid #b2ddb2;
            border-radius: 12px;
            padding: 28px 32px;
        }

        .btn-appform {
            background-color: #45b84d;
            color: #fff;
            border-radius: 20px;
            padding: 7px 20px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
        }

        .section-card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px 20px;
            height: 100%;
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

        .req-item {
            font-size: 18px;
            color: #333;
            padding: 5px 0;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }

        .qa-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 0;
            border-bottom: 1px solid #eee;
            font-size: 13px;
            font-weight: 600;
            color: #333;
            text-decoration: none;
        }

        .qa-item:last-child {
            border-bottom: none;
        }

        .qa-icon {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            background-color: #e8f5e9;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .qa-icon i {
            font-size: 17px;
            color: #45b84d;
        }

        .announcement-item {
            background-color: #e8f5e9;
            border-left: 4px solid #45b84d;
            border-radius: 6px;
            padding: 12px 14px;
            margin-bottom: 12px;
        }

        .announcement-list-scroll {
            max-height: 320px;
            overflow-y: auto;
            padding-right: 6px;
        }

        hr.my-divider {
            border-top: 1px solid #eee;
            margin: 10px 0 12px;
        }
    </style>
</head>

<body>
    <?php include(__DIR__ . '/../includes/applicantnav.php') ?>

    <div class="container-fluid px-4" style="margin-top: 80px;">

        <!-- Welcome Banner -->
        <div class="welcome-banner mb-4">
            <h4 class="fw-bold mb-1">Hello, <?php echo e($me['first_name']); ?>! 👋</h4>
            <p class="text-muted mb-3" style="font-size: 12px; font-weight: 600; letter-spacing: 0.5px;">FINANCIAL ASSISTANCE PROGRAM · <?php echo e($settings['current_academic_year'] ?? ''); ?></p>
            <a href="ApplicationForm.php" class="btn-appform">
                📋 Apply for Financial Assistance &nbsp;→
            </a>
        </div>

        <!-- Main Row -->
        <div class="row g-3">

            <!-- Requirements -->
            <!-- Left: 3 cards stacked -->
            <div class="col-lg-3 col-md-6">

                <!-- About the Program -->
                <div class="section-card mb-3">
                    <div class="section-title"><i class="bi bi-info-circle-fill text-success"></i> About the Program</div>
                    <p style="font-size: 15px; color: #555; margin-bottom: 0; line-height: 1.6;">
                        <?php echo nl2br(e($settings['about_text'] ?? '')); ?>
                    </p>
                </div>

                <!-- SK Office Info -->
                <div class="section-card">
                    <div class="section-title"><i class="bi bi-geo-alt-fill text-success"></i> SK Office</div>
                    <div class="req-item"><i class="bi bi-house-fill text-success"></i> <?php echo e($settings['sk_office_address'] ?? ''); ?></div>
                    <div class="req-item"><i class="bi bi-telephone-fill text-success"></i> <?php echo e($settings['contact_number'] ?? ''); ?></div>
                    <div class="req-item"><i class="bi bi-clock-fill text-success"></i> <?php echo e($settings['office_hours'] ?? ''); ?></div>
                </div>

            </div>

            <!-- Important Dates + Quick Action -->
            <div class="col-lg-6 col-md-12">

                <div class="section-card mb-3">
                    <div class="section-title"><i class="bi bi-calendar2-event"></i> Important Dates</div>
                    <hr class="my-divider">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0" style="font-size: 13px;">
                            <thead class="table-success">
                                <tr>
                                    <th>Event</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($dates)): ?>
                                    <tr>
                                        <td colspan="2" class="text-center text-muted">No upcoming dates posted.</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($dates as $d): ?>
                                    <tr>
                                        <td><?php echo e($d['event_name']); ?></td>
                                        <td><?php echo date('F j, Y', strtotime($d['event_date'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!empty($settings['requirements_deadline'])): ?>
                                    <tr>
                                        <td>iSKolar Updated Requirements Deadline</td>
                                        <td><?php echo date('F j, Y', strtotime($settings['requirements_deadline'])); ?></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="section-card">
                    <div class="section-title"><i class="bi bi-lightning-fill text-warning"></i> Quick Action</div>
                    <hr class="my-divider">
                    <a href="ApplicationForm.php" class="qa-item">
                        <div class="qa-icon"><i class="bi bi-file-earmark-text-fill"></i></div>
                        <span>Apply for Financial Assistance</span>
                    </a>
                    <a href="../UserProfile.php" class="qa-item">
                        <div class="qa-icon"><i class="bi bi-person-fill"></i></div>
                        <span>Edit Profile</span>
                    </a>
                    <a href="ApplicationStatus.php" class="qa-item">
                        <div class="qa-icon"><i class="bi bi-shield-check"></i></div>
                        <span>Application Status</span>
                    </a>
                </div>

            </div>

            <!-- Announcements -->
            <div class="col-lg-3 col-md-6">
                <div class="section-card">
                    <div class="section-title">
                        <i class="bi bi-bell-fill text-danger"></i> Announcement
                        <span class="badge bg-danger rounded-circle ms-1" style="font-size: 11px;"><?php echo count($announcements); ?></span>
                    </div>
                    <hr class="my-divider">
                    <?php if (empty($announcements)): ?>
                        <p class="text-muted mb-0" style="font-size: 13px;">No announcements yet.</p>
                    <?php endif; ?>
                    <div class="<?php echo count($announcements) >= 4 ? 'announcement-list-scroll' : ''; ?>">
                        <?php foreach ($announcements as $a): ?>
                            <div class="announcement-item d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <p class="fw-semibold mb-1" style="font-size: 13px;"><?php echo e($a['title']); ?></p>
                                    <p class="mb-0" style="font-size: 12px; color: #444;"><?php echo e($a['message']); ?></p>
                                    <p class="text-muted mb-0 mt-1" style="font-size: 11px;">Posted: <?php echo date('F j, Y', strtotime($a['posted_at'])); ?></p>
                                </div>
                                <button type="button" class="btn btn-sm p-0 text-muted" style="font-size:14px; line-height:1;" title="Archive" onclick="confirmArchiveAnnouncement('<?php echo $a['type']; ?>', <?php echo $a['id']; ?>)">
                                    <i class="bi bi-archive"></i>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- ARCHIVE ANNOUNCEMENT CONFIRMATION MODAL -->
    <div class="modal fade" id="archiveAnnouncementModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
            <div class="modal-content border-0 shadow">
                <div class="modal-header" style="background: linear-gradient(90deg, #e53935, #ef9a9a);">
                    <h6 class="modal-title fw-bold text-white"><i class="bi bi-archive-fill me-2"></i>Archive Announcement</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter: brightness(0) invert(1);"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 40px;"></i>
                    <p class="mt-3 mb-0" style="font-size: 14px;">Are you sure you want to archive this announcement? You won't see it here anymore.</p>
                </div>
                <div class="modal-footer border-0 justify-content-center">
                    <button type="button" class="btn btn-sm btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="confirmArchiveAnnouncementBtn" class="btn btn-sm btn-danger px-4"><i class="bi bi-archive me-1"></i> Yes, Archive</button>
                </div>
            </div>
        </div>
    </div>

    <form id="archiveAnnouncementForm" method="post" class="d-none">
        <input type="hidden" name="notification_id" id="archiveNotificationId" value="">
        <input type="hidden" name="announcement_id" id="archiveAnnouncementId" value="">
        <input type="hidden" name="archive_notification" id="archiveNotificationFlag" value="1" disabled>
        <input type="hidden" name="archive_announcement" id="archiveAnnouncementFlag" value="1" disabled>
    </form>

    <script>
        function confirmArchiveAnnouncement(type, id) {
            document.getElementById('archiveNotificationFlag').disabled = type !== 'notification';
            document.getElementById('archiveAnnouncementFlag').disabled = type !== 'announcement';
            document.getElementById('archiveNotificationId').value = type === 'notification' ? id : '';
            document.getElementById('archiveAnnouncementId').value = type === 'announcement' ? id : '';
            new bootstrap.Modal(document.getElementById('archiveAnnouncementModal')).show();
        }
        document.getElementById('confirmArchiveAnnouncementBtn').addEventListener('click', function() {
            document.getElementById('archiveAnnouncementForm').submit();
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>