<?php
// Gmail SMTP configuration for outbound OTP emails (registration + forgot password).
//
// To enable real email delivery:
//   1. Turn on 2-Step Verification on the Gmail account that will send these emails:
//      https://myaccount.google.com/security
//   2. Create an App Password: https://myaccount.google.com/apppasswords
//      (choose "Mail" as the app) — this gives you a 16-character code, NOT your normal
//      Gmail password.
//   3. Fill in MAIL_USERNAME (the Gmail address) and MAIL_PASSWORD (the app password) below.
//
// Until MAIL_USERNAME is filled in, OTP codes are written to config/otp_debug.log instead of
// being emailed, so registration and password reset still work end-to-end for local testing/demo.
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_USERNAME', '200agroup22@gmail.com');
define('MAIL_PASSWORD', 'ltvklidcsgghccbp');
define('MAIL_FROM_NAME', siteName());

require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// Sends a 6-digit OTP email for 'register' or 'reset'. Returns true on success (including the
// debug-log fallback below), false only on a real send failure once SMTP is configured.

function sendOtpEmail($toEmail, $toName, $otpCode, $purpose) {
>>>>>>> b2d6751608c3c49570e33986527d9880861bef13
    if (MAIL_USERNAME === '') {
        $line = date('Y-m-d H:i:s') . " | $purpose | $toEmail | OTP: $otpCode\n";
        file_put_contents(__DIR__ . '/otp_debug.log', $line, FILE_APPEND);
        return true;
    }

    $subject = $purpose === 'reset' ? MAIL_FROM_NAME . ' - Password Reset Code' : MAIL_FROM_NAME . ' - Verify Your Email';
    $actionText = $purpose === 'reset' ? 'reset your password' : 'verify your email and finish creating your account';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = MAIL_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = MAIL_USERNAME;
        $mail->Password = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = MAIL_PORT;
        $mail->Timeout = 10;
        $mail->SMTPKeepAlive = false;

        $mail->setFrom(MAIL_USERNAME, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;">'
            . '<h2 style="color:#409D42;">' . htmlspecialchars(MAIL_FROM_NAME) . '</h2>'
            . '<p>Hi ' . htmlspecialchars($toName) . ',</p>'
            . '<p>Use the code below to ' . $actionText . '. This code expires in 10 minutes.</p>'
            . '<div style="font-size:28px;font-weight:bold;letter-spacing:6px;background:#e8f5e9;color:#2e7d32;padding:14px 20px;border-radius:8px;text-align:center;margin:16px 0;">' . $otpCode . '</div>'
            . '<p style="color:#888;font-size:12px;">If you did not request this, you can safely ignore this email.</p>'
            . '</div>';
        $mail->AltBody = "Your " . MAIL_FROM_NAME . " verification code is: $otpCode (expires in 10 minutes)";

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('OTP email failed: ' . $mail->ErrorInfo);
        return false;
    }
}
