<?php
// QR scan endpoint for program activities (see admin/ProgramActivities.php). Same access rule as the
// Activities page itself: Super Admin, a Secretary/Treasurer-flagged officer, or a Committee Admin
// assigned to the program's committee.
require_once __DIR__ . '/config/forms.php';
header('Content-Type: application/json');

$tabId = (int)($_POST['tab_id'] ?? 0);
$activityId = (int)($_POST['activity_id'] ?? 0);
$token = trim($_POST['token'] ?? '');

$tab = $tabId ? getProgramTabById($tabId) : null;

$canScan = false;
if ($tab && isLoggedIn()) {
    $scanUser = currentUser();
    if (hasFullCommitteeAccess($scanUser)) {
        $canScan = true;
    } elseif ($scanUser['role'] === 'committee_admin') {
        $canScan = in_array((int)$tab['committee_id'], getUserCommitteeIds($scanUser['user_id']), true);
    }
}

if (!$canScan) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit();
}

if ($activityId <= 0 || $token === '') {
    echo json_encode(['success' => false, 'message' => 'Missing activity or token.']);
    exit();
}

$stmt = $conn->prepare("SELECT att.attendance_id, att.status, u.first_name, u.last_name
    FROM program_attendance att
    JOIN program_activities act ON act.activity_id = att.activity_id
    JOIN users u ON u.user_id = att.user_id
    WHERE att.activity_id = ? AND act.tab_id = ? AND att.qr_token = ?");
$stmt->bind_param('iis', $activityId, $tabId, $token);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'QR code does not match this activity.']);
    exit();
}

$name = e($row['first_name'] . ' ' . $row['last_name']);

if ($row['status'] === 'present') {
    echo json_encode(['success' => true, 'message' => $name . ' was already marked present.']);
    exit();
}

$stmt = $conn->prepare("UPDATE program_attendance SET status = 'present', scanned_at = NOW() WHERE attendance_id = ?");
$stmt->bind_param('i', $row['attendance_id']);
$stmt->execute();
$stmt->close();

logAudit('Scanned Program Attendance', $row['first_name'] . ' ' . $row['last_name'] . ' - ' . $tab['label'] . ' activity #' . $activityId);

echo json_encode(['success' => true, 'message' => $name . ' marked present.']);
