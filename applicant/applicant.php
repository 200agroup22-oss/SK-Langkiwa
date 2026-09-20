<?php
require_once __DIR__ . '/../config/functions.php';
requireRole('applicant');

$me = currentUser();

$settings = $conn->query("SELECT * FROM site_settings WHERE id = 1")->fetch_assoc();

$dates = $conn->query("SELECT event_name, event_date FROM important_dates ORDER BY event_date ASC LIMIT 5")->fetch_all(MYSQLI_ASSOC);

$announcements = $conn->query("SELECT title, message, posted_at FROM announcements
    WHERE archived_at IS NULL AND sent_to IN ('all','applicants')
    ORDER BY posted_at DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);
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
                            <div class="announcement-item">
                                <p class="fw-semibold mb-1" style="font-size: 13px;"><?php echo e($a['title']); ?></p>
                                <p class="mb-0" style="font-size: 12px; color: #444;"><?php echo e($a['message']); ?></p>
                                <p class="text-muted mb-0 mt-1" style="font-size: 11px;">Posted: <?php echo date('F j, Y', strtotime($a['posted_at'])); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>