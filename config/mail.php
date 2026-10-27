<?php
// Brevo API configuration for outbound OTP emails (registration + forgot password).
//
// Sender must be verified in Brevo under Senders, Domains & Dedicated IPs.
//
// Until BREVO_API_KEY is filled in, OTP codes are written to config/otp_debug.log instead of
// being emailed, so registration and password reset still work end-to-end for local testing/demo.
// The API key is never stored in this file (GitHub blocks pushes that contain it). It comes from the
// BREVO_API_KEY environment variable (Railway > Variables) or, on local XAMPP, from config/mail.local.php,
// which is git-ignored.
$brevoLocalFile = __DIR__ . '/mail.local.php';
if (is_file($brevoLocalFile)) {
    require_once $brevoLocalFile;
}
if (!defined('BREVO_API_KEY')) {
    define('BREVO_API_KEY', getenv('BREVO_API_KEY') ?: '');
}
define('BREVO_SENDER_EMAIL', '200agroup22@gmail.com');
define('BREVO_SENDER_NAME', siteName());

// Sends a 6-digit OTP email for 'register' or 'reset'. Returns true on success (including the
// debug-log fallback below), false only on a real send failure once the API key is configured.
function sendOtpEmail($toEmail, $toName, $otpCode, $purpose)
{
    if (BREVO_API_KEY === '') {
        $line = date('Y-m-d H:i:s') . " | $purpose | $toEmail | OTP: $otpCode\n";
        file_put_contents(__DIR__ . '/otp_debug.log', $line, FILE_APPEND);
        return true;
    }

    $subject = $purpose === 'reset' ? BREVO_SENDER_NAME . ' - Password Reset Code' : BREVO_SENDER_NAME . ' - Verify Your Email';
    $actionText = $purpose === 'reset' ? 'reset your password' : 'verify your email and finish creating your account';

    $htmlContent = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;">'
        . '<h2 style="color:#409D42;">' . htmlspecialchars(BREVO_SENDER_NAME) . '</h2>'
        . '<p>Hi ' . htmlspecialchars($toName) . ',</p>'
        . '<p>Use the code below to ' . $actionText . '. This code expires in 10 minutes.</p>'
        . '<div style="font-size:28px;font-weight:bold;letter-spacing:6px;background:#e8f5e9;color:#2e7d32;padding:14px 20px;border-radius:8px;text-align:center;margin:16px 0;">' . $otpCode . '</div>'
        . '<p style="color:#888;font-size:12px;">If you did not request this, you can safely ignore this email.</p>'
        . '</div>';

    $payload = [
        'sender' => ['name' => BREVO_SENDER_NAME, 'email' => BREVO_SENDER_EMAIL],
        'to' => [['email' => $toEmail, 'name' => $toName !== '' ? $toName : $toEmail]],
        'subject' => $subject,
        'htmlContent' => $htmlContent,
        'textContent' => "Your " . BREVO_SENDER_NAME . " verification code is: $otpCode (expires in 10 minutes)",
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . BREVO_API_KEY,
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode < 200 || $httpCode >= 300) {
        error_log('OTP email failed (Brevo): ' . ($curlError ?: "HTTP $httpCode - $response"));
        return false;
    }

    return true;
}

// Emails an applicant once their application has been approved or declined. $programLabel is
// whatever name the applicant would recognize (a specific program's name, or the track/committee
// name for a generic application). $reason is only shown when the application was declined.
function sendApplicationDecisionEmail($toEmail, $toName, $programLabel, $status, $reason = null)
{
    $isApproved = $status === 'approved';
    $statusText = $isApproved ? 'Approved' : 'Declined';

    if (BREVO_API_KEY === '') {
        $line = date('Y-m-d H:i:s') . " | application_$status | $toEmail | $programLabel" . ($reason ? " | Reason: $reason" : '') . "\n";
        file_put_contents(__DIR__ . '/otp_debug.log', $line, FILE_APPEND);
        return true;
    }

    $subject = BREVO_SENDER_NAME . ' - Your ' . $programLabel . ' Application Was ' . $statusText;
    $color = $isApproved ? '#2e7d32' : '#c62828';
    $bg = $isApproved ? '#e8f5e9' : '#fce4ec';

    $htmlContent = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;">'
        . '<h2 style="color:#409D42;">' . htmlspecialchars(BREVO_SENDER_NAME) . '</h2>'
        . '<p>Hi ' . htmlspecialchars($toName) . ',</p>'
        . '<p>Your application for <strong>' . htmlspecialchars($programLabel) . '</strong> has been reviewed.</p>'
        . '<div style="font-size:18px;font-weight:bold;background:' . $bg . ';color:' . $color . ';padding:14px 20px;border-radius:8px;text-align:center;margin:16px 0;">' . $statusText . '</div>'
        . ($isApproved
            ? '<p>Congratulations! Please log in to your account to see the next steps.</p>'
            : '<p>' . (!empty($reason) ? 'Reason: ' . htmlspecialchars($reason) : 'Log in to your account and check your Application Status page for more details.') . '</p>')
        . '<p style="color:#888;font-size:12px;">This is an automated message — please do not reply.</p>'
        . '</div>';

    $payload = [
        'sender' => ['name' => BREVO_SENDER_NAME, 'email' => BREVO_SENDER_EMAIL],
        'to' => [['email' => $toEmail, 'name' => $toName !== '' ? $toName : $toEmail]],
        'subject' => $subject,
        'htmlContent' => $htmlContent,
        'textContent' => "Your $programLabel application was $statusText." . (!$isApproved && !empty($reason) ? " Reason: $reason" : ''),
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . BREVO_API_KEY,
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode < 200 || $httpCode >= 300) {
        error_log('Application decision email failed (Brevo): ' . ($curlError ?: "HTTP $httpCode - $response"));
        return false;
    }

    return true;
}

// A plain notice email: a heading line, an optional table of label => value rows, and a closing line.
// Used for scholar notices (allowance release, semester ended, renewal result). Never throws - a mail
// problem must not block the action that triggered it.
function sendNoticeEmail($toEmail, $toName, $subject, $intro, array $rows = [], $closing = '')
{
    $fullSubject = BREVO_SENDER_NAME . ' - ' . $subject;

    if (BREVO_API_KEY === '') {
        $line = date('Y-m-d H:i:s') . " | notice | $toEmail | $subject\n";
        file_put_contents(__DIR__ . '/otp_debug.log', $line, FILE_APPEND);
        return true;
    }

    $table = '';
    $text = $intro;
    if ($rows) {
        $table = '<table style="width:100%;border-collapse:collapse;background:#e8f5e9;border-radius:8px;margin:14px 0;">';
        foreach ($rows as $label => $value) {
            $table .= '<tr><td style="padding:6px 10px;color:#666;width:38%;">' . htmlspecialchars($label) . '</td><td style="padding:6px 10px;font-weight:bold;">' . htmlspecialchars($value) . '</td></tr>';
            $text .= " $label: $value.";
        }
        $table .= '</table>';
    }
    $htmlContent = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:auto;">'
        . '<h2 style="color:#409D42;">' . htmlspecialchars(BREVO_SENDER_NAME) . '</h2>'
        . '<p>Hi ' . htmlspecialchars($toName) . ',</p>'
        . '<p>' . $intro . '</p>'
        . $table
        . ($closing !== '' ? '<p>' . $closing . '</p>' : '')
        . '<p style="color:#888;font-size:12px;">This is an automated message - please do not reply.</p></div>';

    $payload = [
        'sender' => ['name' => BREVO_SENDER_NAME, 'email' => BREVO_SENDER_EMAIL],
        'to' => [['email' => $toEmail, 'name' => $toName !== '' ? $toName : $toEmail]],
        'subject' => $fullSubject,
        'htmlContent' => $htmlContent,
        'textContent' => strip_tags($text),
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
        error_log('Notice email failed (Brevo): ' . ($curlError ?: "HTTP $httpCode - $response"));
        return false;
    }
    return true;
}

// Emails a beneficiary whose assistance is Pending Release: what they will receive and when/where to
// claim it. $details is ['program', 'type' ('cash'|'in_kind'), 'what' (amount or items text),
// 'date', 'time', 'venue']; date/time/venue may be empty ("to be announced"). $updated=true marks the
// message as a change to an earlier schedule. Never throws - a mail problem must not block the action.
function sendReleaseScheduleEmail($toEmail, $toName, array $details, $updated = false)
{
    $when = '';
    if (!empty($details['date'])) {
        $when = date('F j, Y', strtotime($details['date']));
        if (!empty($details['time'])) {
            $when .= ' at ' . date('g:i A', strtotime($details['time']));
        }
    }
    $whenText = $when !== '' ? $when : 'To be announced by the SK office';
    $venueText = !empty($details['venue']) ? $details['venue'] : 'To be announced by the SK office';
    $typeText = ($details['type'] ?? 'cash') === 'cash' ? 'Cash Assistance' : 'In-Kind Assistance';
    $subject = BREVO_SENDER_NAME . ' - ' . ($updated ? 'Updated Release Schedule' : 'Your Assistance Is Ready for Release') . ': ' . $details['program'];

    if (BREVO_API_KEY === '') {
        $line = date('Y-m-d H:i:s') . " | release_schedule | $toEmail | {$details['program']} | $whenText | $venueText\n";
        file_put_contents(__DIR__ . '/otp_debug.log', $line, FILE_APPEND);
        return true;
    }

    $row = function ($label, $value) {
        return '<tr><td style="padding:6px 10px;color:#666;width:38%;">' . $label . '</td><td style="padding:6px 10px;font-weight:bold;">' . htmlspecialchars($value) . '</td></tr>';
    };
    $htmlContent = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:auto;">'
        . '<h2 style="color:#409D42;">' . htmlspecialchars(BREVO_SENDER_NAME) . '</h2>'
        . '<p>Hi ' . htmlspecialchars($toName) . ',</p>'
        . '<p>' . ($updated ? 'The release schedule for your assistance has been <strong>updated</strong>.' : 'Your application was approved and your assistance is now <strong>pending release</strong>.') . '</p>'
        . '<table style="width:100%;border-collapse:collapse;background:#e8f5e9;border-radius:8px;margin:14px 0;">'
        . $row('Program', $details['program'])
        . $row('Type', $typeText)
        . $row(($details['type'] ?? 'cash') === 'cash' ? 'Amount' : 'Items', $details['what'])
        . $row('Release date', $whenText)
        . $row('Venue', $venueText)
        . '</table>'
        . '<p>Please bring <strong>' . htmlspecialchars($details['bring'] ?? 'a valid ID') . '</strong> when you claim your assistance. You can also check your status under <strong>My Assistance</strong> in your account.</p>'
        . '<p style="color:#888;font-size:12px;">This is an automated message - please do not reply.</p></div>';

    $payload = [
        'sender' => ['name' => BREVO_SENDER_NAME, 'email' => BREVO_SENDER_EMAIL],
        'to' => [['email' => $toEmail, 'name' => $toName !== '' ? $toName : $toEmail]],
        'subject' => $subject,
        'htmlContent' => $htmlContent,
        'textContent' => "{$details['program']} - $typeText ({$details['what']}). Release: $whenText. Venue: $venueText.",
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
        error_log('Release schedule email failed (Brevo): ' . ($curlError ?: "HTTP $httpCode - $response"));
        return false;
    }
    return true;
}
