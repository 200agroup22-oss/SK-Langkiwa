<?php
// Brevo API configuration for outbound OTP emails (registration + forgot password).
//
// Sender must be verified in Brevo under Senders, Domains & Dedicated IPs.
//
// Until BREVO_API_KEY is filled in, OTP codes are written to config/otp_debug.log instead of
// being emailed, so registration and password reset still work end-to-end for local testing/demo.
// Actual key/sender values live in mail.local.php (gitignored, not committed).
// Ask a teammate for a copy, or create your own Brevo sender + API key for local testing.
if (file_exists(__DIR__ . '/mail.local.php')) {
    require_once __DIR__ . '/mail.local.php';
} elseif (getenv('BREVO_API_KEY')) {
    define('BREVO_API_KEY', getenv('BREVO_API_KEY'));
} else {
    define('BREVO_API_KEY', '');
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
