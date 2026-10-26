<?php
// New self-registered accounts are held as 'pending' until the Super Admin has checked the ID and
// proof of residency they uploaded (Account Approvals page). Needs config/functions.php loaded first.
require_once __DIR__ . '/mail.php';

// Adds the 'pending'/'declined' account statuses and the columns that hold the uploaded documents
// and the decision. Safe to call repeatedly; does nothing once everything exists.
function ensureRegistrationApprovalSchema()
{
    global $conn;
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $col = $conn->query("SHOW COLUMNS FROM users LIKE 'status'")->fetch_assoc();
    if ($col && (stripos($col['Type'], "'pending'") === false || stripos($col['Type'], "'declined'") === false)) {
        $conn->query("ALTER TABLE users MODIFY COLUMN status ENUM('active','inactive','archived','pending','declined') NOT NULL DEFAULT 'active'");
    }

    $columns = [
        'id_document_path' => 'VARCHAR(255) NULL',
        'residency_document_path' => 'VARCHAR(255) NULL',
        'decline_reason' => 'VARCHAR(255) NULL',
        'decided_at' => 'DATETIME NULL',
        'decided_by' => 'INT NULL',
    ];
    foreach ($columns as $name => $def) {
        $exists = $conn->query("SHOW COLUMNS FROM users LIKE '$name'");
        if ($exists && $exists->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN $name $def");
        }
    }
}

function pendingRegistrationCount()
{
    global $conn;
    try {
        $res = $conn->query("SELECT COUNT(*) FROM users WHERE status = 'pending'");
        return $res ? (int)$res->fetch_row()[0] : 0;
    } catch (Throwable $ex) {
        return 0; // enum value not created yet
    }
}

// Emails the registrant the Super Admin's decision. Never throws — a mail problem must not block the decision.
function sendRegistrationDecisionEmail($toEmail, $toName, $status, $reason = null)
{
    $isApproved = $status === 'approved';
    $statusText = $isApproved ? 'Approved' : 'Declined';

    if (BREVO_API_KEY === '') {
        $line = date('Y-m-d H:i:s') . " | registration_$status | $toEmail" . ($reason ? " | Reason: $reason" : '') . "\n";
        file_put_contents(__DIR__ . '/otp_debug.log', $line, FILE_APPEND);
        return true;
    }

    $color = $isApproved ? '#2e7d32' : '#c62828';
    $bg = $isApproved ? '#e8f5e9' : '#fce4ec';
    $body = $isApproved
        ? '<p>Your account has been verified. You can now log in with your email and password.</p>'
        : '<p>' . (!empty($reason) ? 'Reason: ' . htmlspecialchars($reason) . '. ' : '') . 'You may register again with clearer or correct documents.</p>';

    $payload = [
        'sender' => ['name' => BREVO_SENDER_NAME, 'email' => BREVO_SENDER_EMAIL],
        'to' => [['email' => $toEmail, 'name' => $toName !== '' ? $toName : $toEmail]],
        'subject' => BREVO_SENDER_NAME . ' - Your Registration Was ' . $statusText,
        'htmlContent' => '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;">'
            . '<h2 style="color:#409D42;">' . htmlspecialchars(BREVO_SENDER_NAME) . '</h2>'
            . '<p>Hi ' . htmlspecialchars($toName) . ',</p>'
            . '<p>Your registration has been reviewed.</p>'
            . '<div style="font-size:18px;font-weight:bold;background:' . $bg . ';color:' . $color . ';padding:14px 20px;border-radius:8px;text-align:center;margin:16px 0;">' . $statusText . '</div>'
            . $body
            . '<p style="color:#888;font-size:12px;">This is an automated message — please do not reply.</p></div>',
        'textContent' => "Your registration was $statusText." . (!$isApproved && !empty($reason) ? " Reason: $reason" : ''),
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['accept: application/json', 'content-type: application/json', 'api-key: ' . BREVO_API_KEY],
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode < 200 || $httpCode >= 300) {
        error_log('Registration decision email failed (Brevo): ' . ($curlError ?: "HTTP $httpCode - $response"));
        return false;
    }
    return true;
}
