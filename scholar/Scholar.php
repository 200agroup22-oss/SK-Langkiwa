<?php
require_once __DIR__ . '/../config/scholars.php';
requireRole('scholar');

$me = currentUser();
$scholar = getScholarByUserId($me['user_id']);
$term = getCurrentTerm();

$activitiesDone = 0;
$upcoming = null;
if ($scholar) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM attendance WHERE scholar_id = ? AND status = 'present'");
    $stmt->bind_param('i', $scholar['scholar_id']);
    $stmt->execute();
    $activitiesDone = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $stmt = $conn->prepare("SELECT a.title, a.activity_date, a.activity_time, a.venue, a.note
        FROM attendance att JOIN activities a ON a.activity_id = att.activity_id
        WHERE att.scholar_id = ? AND a.activity_date >= CURDATE() AND a.archived_at IS NULL
        ORDER BY a.activity_date ASC LIMIT 1");
    $stmt->bind_param('i', $scholar['scholar_id']);
    $stmt->execute();
    $upcoming = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$announcements = $conn->query("SELECT title, message, posted_at FROM announcements
    WHERE archived_at IS NULL AND sent_to IN ('all','scholars')
    ORDER BY posted_at DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scholar Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f0f0f0;
        }

        .welcome {
            background: linear-gradient(135deg, #c8efc8, #e8f8e8);
            border: 1px solid #b2ddb2;
            border-radius: 12px;
            padding: 28px 32px;
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
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }

        .announcement-item {
            background-color: #e8f5e9;
            border-left: 4px solid #45b84d;
            border-radius: 6px;
            padding: 12px 14px;
            margin-bottom: 12px;
        }

        .activity-card {
            background-color: #fdf6ec;
            border: 1px solid #f5c97a;
            border-radius: 8px;
            padding: 14px 16px;
        }

        .activity-card .activity-title {
            font-size: 14px;
            font-weight: 700;
            color: #333;
            margin-bottom: 8px;
        }

        .activity-card p {
            font-size: 13px;
            color: #444;
            margin-bottom: 4px;
        }

        .activity-card p:last-child {
            margin-bottom: 0;
        }
    </style>
</head>

<body>
    <?php include(__DIR__ . '/../includes/scholarnav.php') ?>

    <div class="container-fluid px-4" style="margin-top: 80px;">

        <!-- Welcome Banner -->
        <div class="welcome mb-4">
            <h4 class="fw-bold mb-1">Welcome back, <?php echo e($me['first_name']); ?>! 👋</h4>
            <p class="text-muted mb-3" style="font-size: 12px; font-weight: 600; letter-spacing: 0.5px;">ACADEMIC YEAR <?php echo e($term['current_academic_year']); ?> · <?php echo mb_strtoupper(e($term['current_semester'])); ?></p>
            <a href="Activities.php" style="background-color: #45b84d; color: #fff; border-radius: 20px; padding: 7px 20px; font-size: 13px; font-weight: 600; text-decoration: none;">
                🎓 View my Activities &nbsp;→
            </a>
        </div>

        <!-- Main Row -->
        <div class="row g-3">

            <!-- Left: Activity Done -->
            <div class="col-lg-3 col-md-6">
                <div class="section-card">
                    <div class="section-title"><i class="bi bi-pencil-square text-success"></i>&nbsp;&nbsp;ACTIVITY DONE:</div>
                    <h1 class="fw-bold" style="font-size: 52px; color: #1a1a1a; margin: 8px 0;"><?php echo $activitiesDone; ?></h1>
                    <p class="text-muted mb-0" style="font-size: 11px; font-weight: 600; letter-spacing: 0.5px;">ACTIVITIES ARE COMPLETED THIS SEMESTER</p>
                </div>
            </div>

            <!-- Middle: Upcoming Activities -->
            <div class="col-lg-6 col-md-12">
                <div class="section-card">
                    <div class="section-title"><i class="bi bi-calendar2-event"></i>&nbsp;&nbsp;UPCOMING ACTIVITIES</div>
                    <hr class="my-2">
                    <?php if ($upcoming): ?>
                        <div class="activity-card">
                            <div class="activity-title">🔴 <u><?php echo e($upcoming['title']); ?></u></div>
                            <p><strong>WHEN:</strong> <?php echo date('F j, Y', strtotime($upcoming['activity_date'])); ?><?php echo $upcoming['activity_time'] ? ', ' . date('g:i A', strtotime($upcoming['activity_time'])) : ''; ?></p>
                            <p><strong>WHERE:</strong> <?php echo e($upcoming['venue']); ?></p>
                            <?php if ($upcoming['note']): ?><p><strong>Note:</strong> <?php echo e($upcoming['note']); ?></p><?php endif; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0" style="font-size: 13px;">No upcoming activities assigned yet.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right: Announcements -->
            <div class="col-lg-3 col-md-6">
                <div class="section-card">
                    <div class="section-title">
                        <i class="bi bi-bell-fill text-danger"></i>&nbsp;&nbsp;ANNOUNCEMENT
                        <span class="badge bg-danger rounded-circle ms-1" style="font-size: 11px;"><?php echo count($announcements); ?></span>
                    </div>
                    <hr class="my-2">
                    <?php if (empty($announcements)): ?>
                        <p class="text-muted mb-0" style="font-size: 13px;">No announcements yet.</p>
                    <?php endif; ?>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
