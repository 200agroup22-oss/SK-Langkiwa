<?php
require_once __DIR__ . '/../config/scholars.php';
requireRole('scholar');

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
    if (isset($_POST['restore_notification'])) {
        $notificationId = (int)$_POST['notification_id'];
        $stmt = $conn->prepare("UPDATE notifications SET archived_at = NULL WHERE notification_id = ? AND user_id = ?");
        $stmt->bind_param('ii', $notificationId, $me['user_id']);
        $stmt->execute();
        $stmt->close();
    }
    if (isset($_POST['restore_announcement'])) {
        $announcementId = (int)$_POST['announcement_id'];
        $stmt = $conn->prepare("DELETE FROM announcement_archives WHERE user_id = ? AND announcement_id = ?");
        $stmt->bind_param('ii', $me['user_id'], $announcementId);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: Scholar.php");
    exit();
}

$scholar = getScholarByUserId($me['user_id']);
$term = getCurrentTerm();

$activitiesDone = 0;
$upcoming = null;
if ($scholar) {
    syncScholarActivities($scholar['scholar_id']);
    // This semester only — it starts again from 0 when the admin ends the semester.
    $activitiesDone = countPresentActivities($scholar['scholar_id'], $term['current_academic_year'], $term['current_semester']);

    $stmt = $conn->prepare("SELECT a.title, a.activity_date, a.activity_time, a.venue, a.note
        FROM attendance att JOIN activities a ON a.activity_id = att.activity_id
        WHERE att.scholar_id = ? AND a.activity_date >= CURDATE() AND a.archived_at IS NULL
        ORDER BY a.activity_date ASC LIMIT 1");
    $stmt->bind_param('i', $scholar['scholar_id']);
    $stmt->execute();
    $upcoming = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$stmt = $conn->prepare("SELECT a.announcement_id, a.title, a.message, a.event_date, a.event_time, a.event_where, a.notes, a.posted_at FROM announcements a
    WHERE a.archived_at IS NULL AND a.sent_to IN ('all','scholars')
    AND NOT EXISTS (SELECT 1 FROM announcement_archives aa WHERE aa.announcement_id = a.announcement_id AND aa.user_id = ?)
    ORDER BY a.posted_at DESC LIMIT 20");
$stmt->bind_param('i', $me['user_id']);
$stmt->execute();
$broadcastAnnouncements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("SELECT notification_id, title, message, created_at FROM notifications WHERE user_id = ? AND archived_at IS NULL ORDER BY created_at DESC LIMIT 20");
$stmt->bind_param('i', $me['user_id']);
$stmt->execute();
$personalNotifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$announcements = [];
foreach ($broadcastAnnouncements as $a) {
    $announcements[] = ['type' => 'announcement', 'id' => (int)$a['announcement_id'], 'title' => $a['title'], 'message' => $a['message'], 'event_date' => $a['event_date'], 'event_time' => $a['event_time'], 'event_where' => $a['event_where'], 'notes' => $a['notes'], 'posted_at' => $a['posted_at']];
}
foreach ($personalNotifications as $n) {
    $announcements[] = ['type' => 'notification', 'id' => (int)$n['notification_id'], 'title' => $n['title'], 'message' => $n['message'], 'event_date' => null, 'event_time' => null, 'event_where' => null, 'notes' => null, 'posted_at' => $n['created_at']];
}
usort($announcements, fn($a, $b) => strtotime($b['posted_at']) <=> strtotime($a['posted_at']));
$announcements = array_slice($announcements, 0, 20);

// ---- Archived items (for the Archives modal — everything this scholar has dismissed) ----
$stmt = $conn->prepare("SELECT a.announcement_id, a.title, a.message, aa.archived_at FROM announcement_archives aa
    JOIN announcements a ON a.announcement_id = aa.announcement_id
    WHERE aa.user_id = ? ORDER BY aa.archived_at DESC");
$stmt->bind_param('i', $me['user_id']);
$stmt->execute();
$archivedBroadcasts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("SELECT notification_id, title, message, archived_at FROM notifications WHERE user_id = ? AND archived_at IS NOT NULL ORDER BY archived_at DESC");
$stmt->bind_param('i', $me['user_id']);
$stmt->execute();
$archivedNotifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$archivedAnnouncements = [];
foreach ($archivedBroadcasts as $a) {
    $archivedAnnouncements[] = ['type' => 'announcement', 'id' => (int)$a['announcement_id'], 'title' => $a['title'], 'message' => $a['message'], 'archived_at' => $a['archived_at']];
}
foreach ($archivedNotifications as $n) {
    $archivedAnnouncements[] = ['type' => 'notification', 'id' => (int)$n['notification_id'], 'title' => $n['title'], 'message' => $n['message'], 'archived_at' => $n['archived_at']];
}
usort($archivedAnnouncements, fn($a, $b) => strtotime($b['archived_at']) <=> strtotime($a['archived_at']));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scholar Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Bootstrap Icons has no waving-hand glyph; this one icon comes from Google's Material Symbols (only waving_hand is downloaded) -->
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0&icon_names=waving_hand&display=block" rel="stylesheet">
    <style>
        .wave-icon {
            font-family: 'Material Symbols Rounded';
            font-weight: 400;
            font-style: normal;
            font-size: 1em;
            line-height: 1;
            display: inline-block;
            width: 1em;
            overflow: hidden;
            white-space: nowrap;
            vertical-align: -0.12em;
            margin-left: 4px;
            color: #f5b301;
            font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
    </style>
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

        /* Announcement widget pagination (paginateList() below) — same green theme as the rest of the app */
        #announcementPagination .pagination {
            margin-bottom: 0;
            flex-wrap: wrap;
            justify-content: center;
            row-gap: 4px;
        }

        #announcementPagination {
            max-width: 100%;
            overflow-x: hidden;
        }

        #announcementPagination .page-link {
            color: #2e7d32;
            font-size: 12px;
            padding: 2px 9px;
        }

        #announcementPagination .page-item.active .page-link {
            background-color: #45b84d;
            border-color: #45b84d;
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
            <h4 class="fw-bold mb-1">Welcome back, <?php echo e($me['first_name']); ?>! <span class="wave-icon" aria-hidden="true">waving_hand</span></h4>
            <p class="text-muted mb-3" style="font-size: 12px; font-weight: 600; letter-spacing: 0.5px;">ACADEMIC YEAR <?php echo e($term['current_academic_year']); ?> · <?php echo mb_strtoupper(e($term['current_semester'])); ?></p>
            <a href="Activities.php" style="background-color: #45b84d; color: #fff; border-radius: 20px; padding: 7px 20px; font-size: 13px; font-weight: 600; text-decoration: none;">
                <i class="bi bi-mortarboard-fill me-1"></i> View my Activities &nbsp;→
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
                            <div class="activity-title"><i class="bi bi-circle-fill text-danger me-1" style="font-size:10px;"></i><u><?php echo e($upcoming['title']); ?></u></div>
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
                    <div class="section-title d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-bell-fill text-danger"></i>&nbsp;&nbsp;ANNOUNCEMENT
                            <span class="badge bg-danger rounded-circle ms-1" style="font-size: 11px;"><?php echo count($announcements); ?></span></span>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" data-bs-toggle="modal" data-bs-target="#archivedAnnouncementsModal"><i class="bi bi-archive me-1"></i>Archives</button>
                    </div>
                    <hr class="my-2">
                    <?php if (empty($announcements)): ?>
                        <p class="text-muted mb-0" style="font-size: 13px;">No announcements yet.</p>
                    <?php endif; ?>
                    <div id="announcementList">
                        <?php foreach ($announcements as $a): ?>
                            <div class="announcement-item d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <p class="fw-semibold mb-1" style="font-size: 13px;"><?php echo e($a['title']); ?></p>
                                    <p class="mb-1" style="font-size: 12px; color: #444;"><?php echo nl2br(e($a['message'])); ?></p>
                                    <?php if (!empty($a['event_date'])): ?><div style="font-size: 12px; color: #333; margin-bottom: 2px;"><strong>When:</strong> <?php echo date('F j, Y', strtotime($a['event_date'])); ?><?php echo !empty($a['event_time']) ? ' — ' . date('g:i A', strtotime($a['event_time'])) : ''; ?></div><?php endif; ?>
                                    <?php if (!empty($a['event_where'])): ?><div style="font-size: 12px; color: #333; margin-bottom: 2px;"><strong>Where:</strong> <?php echo e($a['event_where']); ?></div><?php endif; ?>
                                    <?php if (!empty($a['notes'])): ?><div style="font-size: 12px; color: #333; margin-bottom: 2px;"><strong>Note:</strong> <?php echo e($a['notes']); ?></div><?php endif; ?>
                                    <p class="text-muted mb-0 mt-1" style="font-size: 11px;">Posted: <?php echo date('F j, Y', strtotime($a['posted_at'])); ?></p>
                                </div>
                                <button type="button" class="btn btn-sm p-0 text-muted" style="font-size:14px; line-height:1;" title="Archive" onclick="confirmArchiveAnnouncement('<?php echo $a['type']; ?>', <?php echo $a['id']; ?>)">
                                    <i class="bi bi-archive"></i>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <nav id="announcementPagination" class="mt-2"></nav>
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

    <!-- ARCHIVED ANNOUNCEMENTS MODAL -->
    <div class="modal fade" id="archivedAnnouncementsModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold"><i class="bi bi-archive-fill me-2"></i>Archived Announcements</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <?php if (empty($archivedAnnouncements)): ?>
                        <p class="text-muted mb-0" style="font-size: 13px;">No archived announcements.</p>
                    <?php endif; ?>
                    <?php foreach ($archivedAnnouncements as $a): ?>
                        <div class="announcement-item d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <p class="fw-semibold mb-1" style="font-size: 13px;"><?php echo e($a['title']); ?></p>
                                <p class="mb-0" style="font-size: 12px; color: #444;"><?php echo e($a['message']); ?></p>
                                <p class="text-muted mb-0 mt-1" style="font-size: 11px;">Archived: <?php echo date('F j, Y', strtotime($a['archived_at'])); ?></p>
                            </div>
                            <form method="post">
                                <input type="hidden" name="<?php echo $a['type'] === 'notification' ? 'notification_id' : 'announcement_id'; ?>" value="<?php echo $a['id']; ?>">
                                <button type="submit" name="<?php echo $a['type'] === 'notification' ? 'restore_notification' : 'restore_announcement'; ?>" class="btn btn-sm btn-outline-success py-0 px-2" style="font-size:11px;"><i class="bi bi-arrow-counterclockwise me-1"></i>Restore</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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

        // Client-side pagination for a small, already-fetched list (the Announcement widget) — no
        // page reload needed just to flip between a couple of items.
        function paginateList(listId, navId, perPage) {
            const list = document.getElementById(listId);
            const nav = document.getElementById(navId);
            if (!list || !nav) return;
            const items = Array.from(list.children);
            // Bootstrap's .d-flex utility is !important, so a plain style.display = 'none' can't
            // hide it — remove the class instead when hiding, and only restore it on items that
            // actually had it to begin with.
            items.forEach(function(item) {
                item.dataset.wasFlex = item.classList.contains('d-flex') ? '1' : '';
            });
            const totalPages = Math.max(1, Math.ceil(items.length / perPage));
            let currentPage = 1;

            function render() {
                items.forEach(function(item, i) {
                    const page = Math.floor(i / perPage) + 1;
                    const show = page === currentPage;
                    item.classList.toggle('d-none', !show);
                    if (item.dataset.wasFlex) {
                        item.classList.toggle('d-flex', show);
                    }
                });
                if (totalPages <= 1) {
                    nav.innerHTML = '';
                    return;
                }
                let html = '<ul class="pagination pagination-sm mb-0">';
                html += '<li class="page-item ' + (currentPage <= 1 ? 'disabled' : '') + '"><a class="page-link" href="#" data-page="' + (currentPage - 1) + '">Previous</a></li>';
                for (let p = 1; p <= totalPages; p++) {
                    html += '<li class="page-item ' + (p === currentPage ? 'active' : '') + '"><a class="page-link" href="#" data-page="' + p + '">' + p + '</a></li>';
                }
                html += '<li class="page-item ' + (currentPage >= totalPages ? 'disabled' : '') + '"><a class="page-link" href="#" data-page="' + (currentPage + 1) + '">Next</a></li>';
                html += '</ul>';
                nav.innerHTML = html;
                nav.querySelectorAll('.page-link').forEach(function(link) {
                    link.addEventListener('click', function(e) {
                        e.preventDefault();
                        const page = parseInt(this.dataset.page, 10);
                        if (page >= 1 && page <= totalPages) {
                            currentPage = page;
                            render();
                        }
                    });
                });
            }
            render();
        }
        paginateList('announcementList', 'announcementPagination', 2);
    </script>
</body>

</html>