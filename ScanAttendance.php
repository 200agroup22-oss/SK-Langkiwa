<?php
require_once __DIR__ . '/config/functions.php';
header('Content-Type: application/json');

if (!isLoggedIn() || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit();
}

$activityId = (int)($_POST['activity_id'] ?? 0);
$token = trim($_POST['token'] ?? '');

if ($activityId <= 0 || $token === '') {
    echo json_encode(['success' => false, 'message' => 'Missing activity or token.']);
    exit();
}

$stmt = $conn->prepare("SELECT att.attendance_id, att.status, u.first_name, u.last_name
    FROM attendance att JOIN scholars s ON s.scholar_id = att.scholar_id JOIN users u ON u.user_id = s.user_id
    WHERE att.activity_id = ? AND att.qr_token = ?");
$stmt->bind_param('is', $activityId, $token);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'QR code does not match this activity.']);
    exit();
}

if ($row['status'] === 'present') {
    echo json_encode(['success' => true, 'message' => e($row['first_name'] . ' ' . $row['last_name']) . ' was already marked present.']);
    exit();
}

$stmt = $conn->prepare("UPDATE attendance SET status = 'present', scanned_at = NOW() WHERE attendance_id = ?");
$stmt->bind_param('i', $row['attendance_id']);
$stmt->execute();
$stmt->close();

logAudit('Scanned Attendance', $row['first_name'] . ' ' . $row['last_name'] . ' - activity #' . $activityId);

echo json_encode(['success' => true, 'message' => e($row['first_name'] . ' ' . $row['last_name']) . ' marked present.']);
