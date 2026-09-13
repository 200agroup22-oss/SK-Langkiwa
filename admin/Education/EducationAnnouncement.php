<?php
require_once __DIR__ . '/../../config/forms.php';
requireRole('admin');

$committeeId = getCommitteeIdByCode('education');
$me = currentUser();

$sendToLabel = ['all' => 'All Users', 'scholars' => 'Scholars Only', 'applicants' => 'Applicants Only', 'specific' => 'Specific User'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['save_announcement'])) {
        $announcementId = (int)($_POST['announcement_id'] ?? 0);
        $sendTo = in_array($_POST['send_to'] ?? '', ['all', 'scholars', 'applicants', 'specific'], true) ? $_POST['send_to'] : 'all';
        $specificTarget = $sendTo === 'specific' ? trim($_POST['specific_target'] ?? '') : null;
        $title = trim($_POST['title'] ?? '');
        $eventDate = !empty($_POST['event_date']) ? $_POST['event_date'] : null;
        $eventTime = !empty($_POST['event_time']) ? $_POST['event_time'] : null;
        $eventWhere = trim($_POST['event_where'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $message = trim($_POST['description'] ?? '');

        if ($title === '' || $message === '') {
            setFlash('error', 'Title and description are required.');
        } elseif ($announcementId > 0) {
            $stmt = $conn->prepare("UPDATE announcements SET title=?, message=?, event_date=?, event_time=?, event_where=?, notes=?, sent_to=?, specific_target=? WHERE announcement_id=? AND committee_id=?");
            $stmt->bind_param('ssssssssii', $title, $message, $eventDate, $eventTime, $eventWhere, $notes, $sendTo, $specificTarget, $announcementId, $committeeId);
            $stmt->execute();
            $stmt->close();
            logAudit('Updated Announcement', $title);
            setFlash('success', 'Announcement updated.');
        } else {
            $stmt = $conn->prepare("INSERT INTO announcements (committee_id, title, message, event_date, event_time, event_where, notes, sent_to, specific_target, posted_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('issssssssi', $committeeId, $title, $message, $eventDate, $eventTime, $eventWhere, $notes, $sendTo, $specificTarget, $me['user_id']);
            $stmt->execute();
            $stmt->close();
            logAudit('Posted Announcement', $title);
            setFlash('success', 'Announcement posted.');
        }
    }

    if (isset($_POST['archive_announcement'])) {
        $announcementId = (int)$_POST['announcement_id'];
        $stmt = $conn->prepare("UPDATE announcements SET archived_at = NOW() WHERE announcement_id = ? AND committee_id = ?");
        $stmt->bind_param('ii', $announcementId, $committeeId);
        $stmt->execute();
        $stmt->close();
        logAudit('Archived Announcement', 'Announcement #' . $announcementId);
        setFlash('success', 'Announcement archived.');
    }

    if (isset($_POST['restore_announcement'])) {
        $announcementId = (int)$_POST['announcement_id'];
        $stmt = $conn->prepare("UPDATE announcements SET archived_at = NULL WHERE announcement_id = ? AND committee_id = ?");
        $stmt->bind_param('ii', $announcementId, $committeeId);
        $stmt->execute();
        $stmt->close();
        logAudit('Restored Announcement', 'Announcement #' . $announcementId);
        setFlash('success', 'Announcement restored.');
    }

    header("Location: EducationAnnouncement.php");
    exit();
}

$pageSuccess = getFlash('success');
$pageError = getFlash('error');

$stmt = $conn->prepare("SELECT a.*, u.first_name AS poster_first, u.last_name AS poster_last
    FROM announcements a LEFT JOIN users u ON u.user_id = a.posted_by
    WHERE a.committee_id = ? AND a.archived_at IS NULL ORDER BY a.announcement_id ASC");
$stmt->bind_param('i', $committeeId);
$stmt->execute();
$announcements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("SELECT * FROM announcements WHERE committee_id = ? AND archived_at IS NOT NULL ORDER BY archived_at DESC");
$stmt->bind_param('i', $committeeId);
$stmt->execute();
$archived = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$activeLink = 'EducationAnnouncement';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcement - Education</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo APP_BASE; ?>/assets/css/admin.css" rel="stylesheet">
    <style>
        .ann-preview {
            background: #e8f5e9;
            border-left: 4px solid #45b84d;
            border-radius: 6px;
            padding: 14px 16px;
        }

        .ann-title {
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 6px;
        }

        .ann-body {
            font-size: 13px;
            color: #444;
            margin-bottom: 8px;
        }

        .ann-detail {
            font-size: 12px;
            color: #333;
            margin-bottom: 2px;
        }

        .ann-meta {
            font-size: 11px;
            color: #888;
            margin-top: 6px;
        }
    </style>
</head>

<body>

    <?php include __DIR__ . '/../../includes/adminsidebar.php'; ?>

    <div class="main-content">
        <h4 class="fw-bold mb-1">Announcement</h4>
        <p class="text-muted mb-2" style="font-size: 13px;">Post announcements for iSKolar ng Langkiwa applicants and scholars.</p>

        <?php if ($pageSuccess): ?><div class="alert alert-success py-2"><?php echo e($pageSuccess); ?></div><?php endif; ?>
        <?php if ($pageError): ?><div class="alert alert-danger py-2"><?php echo e($pageError); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-success" onclick="openAdd()" data-bs-toggle="modal" data-bs-target="#formAnnouncementModal"><i class="bi bi-plus-lg me-1"></i> Add Announcement</button>
                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#archivesAnnouncementModal"><i class="bi bi-archive me-1"></i> Archives</button>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <select class="form-select form-select-sm" id="announcementFilterSelect" style="width:auto;">
                    <option value="all">Filter: All Announcements</option>
                    <option value="sent:all">Sent To: All Users</option>
                    <option value="sent:scholars">Sent To: Scholars Only</option>
                    <option value="sent:applicants">Sent To: Applicants Only</option>
                    <option value="sent:specific">Sent To: Specific User</option>
                </select>
                <select class="form-select form-select-sm" id="announcementSortSelect" style="width:auto;">
                    <option value="id_asc">Sort By: ID (Ascending)</option>
                    <option value="id_desc">Sort By: ID (Descending)</option>
                    <option value="title_asc">Sort By: Title (A-Z)</option>
                    <option value="title_desc">Sort By: Title (Z-A)</option>
                    <option value="date_desc">Sort By: Date Posted (Newest)</option>
                    <option value="date_asc">Sort By: Date Posted (Oldest)</option>
                </select>
                <div class="search-box position-relative">
                    <i class="bi bi-search position-absolute" style="left:10px; top:50%; transform:translateY(-50%); color:#999; font-size:12px;"></i>
                    <input type="text" class="form-control form-control-sm" id="announcementSearchInput" placeholder="Search announcement..." style="padding-left:28px;">
                </div>
            </div>
        </div>

        <div class="table-card">
            <div class="table-responsive-wrap">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Sent To</th>
                            <th>Date Posted</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="announcementsTableBody">
                        <?php if (empty($announcements)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">No announcements yet.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($announcements as $a): ?>
                            <tr class="announcement-row" data-announcement-id="<?php echo (int)$a['announcement_id']; ?>" data-title="<?php echo e(strtolower($a['title'])); ?>" data-date="<?php echo strtotime($a['posted_at']); ?>" data-sent-to="<?php echo e($a['sent_to']); ?>">
                                <td><?php echo str_pad($a['announcement_id'], 3, '0', STR_PAD_LEFT); ?></td>
                                <td><?php echo e($a['title']); ?></td>
                                <td><?php echo e($sendToLabel[$a['sent_to']] ?? $a['sent_to']); ?></td>
                                <td><?php echo date('F j, Y', strtotime($a['posted_at'])); ?></td>
                                <td class="d-flex gap-1">
                                    <button class="btn-view" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $a['announcement_id']; ?>"><i class="bi bi-eye"></i> View</button>
                                    <button class="btn-edit" onclick='openEdit(<?php echo json_encode($a); ?>)' data-bs-toggle="modal" data-bs-target="#formAnnouncementModal"><i class="bi bi-pencil"></i> Edit</button>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Archive this announcement? Scholars/applicants will no longer see it.');">
                                        <input type="hidden" name="announcement_id" value="<?php echo $a['announcement_id']; ?>">
                                        <button type="submit" name="archive_announcement" class="btn-archive"><i class="bi bi-archive"></i> Archive</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php foreach ($announcements as $a): ?>
        <div class="modal fade" id="viewModal<?php echo $a['announcement_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold"><i class="bi bi-bell-fill me-2"></i>Announcement Details</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <div class="info-label">Announcement ID</div>
                                <div class="info-value"><?php echo str_pad($a['announcement_id'], 3, '0', STR_PAD_LEFT); ?></div>
                            </div>
                            <div class="col-6">
                                <div class="info-label">Date Posted</div>
                                <div class="info-value"><?php echo date('F j, Y', strtotime($a['posted_at'])); ?></div>
                            </div>
                            <div class="col-6">
                                <div class="info-label">Posted By</div>
                                <div class="info-value"><?php echo e(trim(($a['poster_first'] ?? '') . ' ' . ($a['poster_last'] ?? '')) ?: '—'); ?></div>
                            </div>
                            <div class="col-6">
                                <div class="info-label">Sent To</div>
                                <div class="info-value"><?php echo e($sendToLabel[$a['sent_to']] ?? $a['sent_to']); ?><?php echo $a['sent_to'] === 'specific' ? ' (' . e($a['specific_target']) . ')' : ''; ?></div>
                            </div>
                        </div>
                        <div class="section-divider">Preview (as seen by scholars)</div>
                        <div class="ann-preview">
                            <div class="ann-title"><?php echo e($a['title']); ?></div>
                            <div class="ann-body"><?php echo e($a['message']); ?></div>
                            <?php if ($a['event_date']): ?><div class="ann-detail"><strong>When:</strong> <?php echo date('F j, Y', strtotime($a['event_date'])); ?><?php echo $a['event_time'] ? ' — ' . date('g:i A', strtotime($a['event_time'])) : ''; ?></div><?php endif; ?>
                            <?php if ($a['event_where']): ?><div class="ann-detail"><strong>Where:</strong> <?php echo e($a['event_where']); ?></div><?php endif; ?>
                            <?php if ($a['notes']): ?><div class="ann-detail"><strong>Note:</strong> <?php echo e($a['notes']); ?></div><?php endif; ?>
                            <div class="ann-meta">Posted: <?php echo date('F j, Y', strtotime($a['posted_at'])); ?></div>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- ADD/EDIT MODAL -->
    <div class="modal fade" id="formAnnouncementModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow">
                <form method="post">
                    <input type="hidden" name="announcement_id" id="announcementIdInput" value="">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold" id="formAnnouncementLabel"><i class="bi bi-plus-circle-fill me-2"></i>Add Announcement</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label mb-2" style="font-size:13px; font-weight:700; color:#2e7d32;"><i class="bi bi-send-fill"></i> Send To</label>
                                <div class="border rounded-2 overflow-hidden">
                                    <label class="d-flex align-items-center gap-3 px-3 py-2 border-bottom" style="cursor:pointer; margin:0;">
                                        <input type="radio" name="send_to" value="all" id="sendToAll" style="accent-color:#2e7d32; flex-shrink:0;">
                                        <div><div style="font-size:13px; font-weight:600; color:#1b5e20;">All Users</div></div>
                                    </label>
                                    <label class="d-flex align-items-center gap-3 px-3 py-2 border-bottom" style="cursor:pointer; margin:0;">
                                        <input type="radio" name="send_to" value="scholars" id="sendToScholars" style="accent-color:#2e7d32; flex-shrink:0;">
                                        <div><div style="font-size:13px; font-weight:600; color:#1b5e20;">Scholars Only</div></div>
                                    </label>
                                    <label class="d-flex align-items-center gap-3 px-3 py-2 border-bottom" style="cursor:pointer; margin:0;">
                                        <input type="radio" name="send_to" value="applicants" id="sendToApplicants" style="accent-color:#2e7d32; flex-shrink:0;">
                                        <div><div style="font-size:13px; font-weight:600; color:#1b5e20;">Applicants Only</div></div>
                                    </label>
                                    <label class="d-flex align-items-center gap-3 px-3 py-2" style="cursor:pointer; margin:0;">
                                        <input type="radio" name="send_to" value="specific" id="sendToSpecific" style="accent-color:#2e7d32; flex-shrink:0;">
                                        <div><div style="font-size:13px; font-weight:600; color:#1b5e20;">Specific User</div></div>
                                    </label>
                                </div>
                                <div class="mt-2">
                                    <label class="form-label" style="font-size:13px; font-weight:600;">User ID or Email <span class="text-muted fw-normal">(if Specific User)</span></label>
                                    <input type="text" class="form-control form-control-sm" name="specific_target" id="specificTargetInput" placeholder="e.g. juan@email.com">
                                </div>
                            </div>
                            <div class="col-12">
                                <hr class="my-1">
                            </div>
                            <div class="col-12">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Title</label>
                                <input type="text" class="form-control form-control-sm" name="title" id="titleInput" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Date <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="date" class="form-control form-control-sm" name="event_date" id="eventDateInput">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Time <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="time" class="form-control form-control-sm" name="event_time" id="eventTimeInput">
                            </div>
                            <div class="col-12">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Where <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="text" class="form-control form-control-sm" name="event_where" id="eventWhereInput">
                            </div>
                            <div class="col-12">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Notes / Reminder <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="text" class="form-control form-control-sm" name="notes" id="notesInput">
                            </div>
                            <div class="col-12">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Description</label>
                                <textarea class="form-control form-control-sm" name="description" id="descriptionInput" rows="4" required></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="save_announcement" class="btn btn-sm btn-success"><i class="bi bi-send-fill me-1"></i> Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ARCHIVES MODAL -->
    <div class="modal fade" id="archivesAnnouncementModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold"><i class="bi bi-archive-fill me-2"></i> Archived Announcements</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="table-card">
                        <table class="table mb-0" style="font-size:13px;">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Archived On</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($archived)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted">No archived announcements.</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($archived as $a): ?>
                                    <tr>
                                        <td><?php echo str_pad($a['announcement_id'], 3, '0', STR_PAD_LEFT); ?></td>
                                        <td><?php echo e($a['title']); ?></td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($a['archived_at'])); ?></td>
                                        <td class="text-center">
                                            <form method="post">
                                                <input type="hidden" name="announcement_id" value="<?php echo $a['announcement_id']; ?>">
                                                <button type="submit" name="restore_announcement" class="btn btn-sm btn-outline-success py-0 px-2" style="font-size:11px;"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function openAdd() {
            document.getElementById('announcementIdInput').value = '';
            document.getElementById('titleInput').value = '';
            document.getElementById('eventDateInput').value = '';
            document.getElementById('eventTimeInput').value = '';
            document.getElementById('eventWhereInput').value = '';
            document.getElementById('notesInput').value = '';
            document.getElementById('descriptionInput').value = '';
            document.getElementById('specificTargetInput').value = '';
            document.getElementById('sendToAll').checked = true;
            document.getElementById('formAnnouncementLabel').innerHTML = '<i class="bi bi-plus-circle-fill me-2"></i>Add Announcement';
        }

        function openEdit(a) {
            document.getElementById('announcementIdInput').value = a.announcement_id;
            document.getElementById('titleInput').value = a.title;
            document.getElementById('eventDateInput').value = a.event_date || '';
            document.getElementById('eventTimeInput').value = a.event_time || '';
            document.getElementById('eventWhereInput').value = a.event_where || '';
            document.getElementById('notesInput').value = a.notes || '';
            document.getElementById('descriptionInput').value = a.message || '';
            document.getElementById('specificTargetInput').value = a.specific_target || '';
            const radio = document.getElementById('sendTo' + a.sent_to.charAt(0).toUpperCase() + a.sent_to.slice(1));
            if (radio) radio.checked = true;
            document.getElementById('formAnnouncementLabel').innerHTML = '<i class="bi bi-pencil-square me-2"></i>Edit Announcement';
        }

        // Client-side search / filter / sort (Announcements table)
        const announcementSearchInput = document.getElementById('announcementSearchInput');
        const announcementFilterSelect = document.getElementById('announcementFilterSelect');
        const announcementSortSelect = document.getElementById('announcementSortSelect');
        const announcementsTableBody = document.getElementById('announcementsTableBody');

        function applyAnnouncementFilters() {
            if (!announcementsTableBody) return;
            const q = announcementSearchInput ? announcementSearchInput.value.trim().toLowerCase() : '';
            const filterVal = announcementFilterSelect ? announcementFilterSelect.value : 'all';
            const sortVal = announcementSortSelect ? announcementSortSelect.value : 'id_asc';
            const [filterKey, filterArg] = filterVal.includes(':') ? filterVal.split(':') : [null, null];

            const rows = Array.from(announcementsTableBody.querySelectorAll('.announcement-row'));
            if (!rows.length) return;

            rows.forEach(function(row) {
                const matchesSearch = !q || (row.dataset.title || '').includes(q);
                const matchesFilter = !filterKey || (filterKey === 'sent' && (row.dataset.sentTo || '') === filterArg);
                row.style.display = (matchesSearch && matchesFilter) ? '' : 'none';
            });

            rows.sort(function(a, b) {
                switch (sortVal) {
                    case 'id_desc':
                        return (+b.dataset.announcementId) - (+a.dataset.announcementId);
                    case 'title_asc':
                        return (a.dataset.title || '').localeCompare(b.dataset.title || '');
                    case 'title_desc':
                        return (b.dataset.title || '').localeCompare(a.dataset.title || '');
                    case 'date_desc':
                        return (+b.dataset.date) - (+a.dataset.date);
                    case 'date_asc':
                        return (+a.dataset.date) - (+b.dataset.date);
                    case 'id_asc':
                    default:
                        return (+a.dataset.announcementId) - (+b.dataset.announcementId);
                }
            });
            rows.forEach(row => announcementsTableBody.appendChild(row));
        }

        if (announcementSearchInput) announcementSearchInput.addEventListener('input', applyAnnouncementFilters);
        if (announcementFilterSelect) announcementFilterSelect.addEventListener('change', applyAnnouncementFilters);
        if (announcementSortSelect) announcementSortSelect.addEventListener('change', applyAnnouncementFilters);
        applyAnnouncementFilters();
    </script>
</body>

</html>
