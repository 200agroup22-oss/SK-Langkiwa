<?php
require_once __DIR__ . '/auth.php';

// Escape for HTML output.
function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// Returns the URL of the current site logo — the admin-uploaded one (Configuration > System
// Logo) if set, otherwise the bundled default. Used everywhere a logo is shown so uploading a
// new one updates it site-wide.
function siteLogoUrl() {
    global $conn;
    $row = $conn->query("SELECT logo_path FROM site_settings WHERE id = 1")->fetch_assoc();
    return !empty($row['logo_path']) ? APP_BASE . '/' . $row['logo_path'] : APP_BASE . '/admin/photos/logo.jpg';
}

// The admin-configured "Site / Barangay Name" (Content Management > Site Settings), used
// everywhere the site's name is displayed so renaming it in one place updates it everywhere.
function siteName() {
    global $conn;
    $row = $conn->query("SELECT site_name FROM site_settings WHERE id = 1")->fetch_assoc();
    return !empty($row['site_name']) ? $row['site_name'] : 'Sangguniang Kabataan ng Langkiwa';
}

function generateQrToken() {
    return bin2hex(random_bytes(16));
}

// Validates a single $_FILES entry (extension, size, actual content type) without touching the
// filesystem, so callers can surface a bad file as a normal form error before doing anything
// that would need to be undone. Returns an error message string, or null if the file is valid.
function validateUploadedFile(array $file) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return 'File upload failed (error code ' . $file['error'] . ').';
    }

    $allowedExts = ['jpg', 'jpeg', 'png', 'pdf'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts, true)) {
        return 'Only JPG, PNG, and PDF files are allowed.';
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return 'File must be smaller than 5MB.';
    }

    // Validate actual content, not just the claimed extension, so a renamed executable can't slip through.
    $allowedMime = ['image/jpeg', 'image/png', 'application/pdf'];
    $actualMime = mime_content_type($file['tmp_name']);
    if (!in_array($actualMime, $allowedMime, true)) {
        return 'The file content does not match an allowed type (JPG, PNG, PDF).';
    }

    return null;
}

/**
 * Moves an uploaded file into uploads/documents and returns
 * ['path' => relative-path-from-app-root, 'original_name' => ...] or null if no file was sent.
 * Throws RuntimeException on upload/validation failure.
 */
function handleUpload(array $file, $subdir = 'documents') {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $fileError = validateUploadedFile($file);
    if ($fileError) {
        throw new RuntimeException($fileError);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $destDir = __DIR__ . '/../uploads/' . $subdir;
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    $storedName = bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
    $destPath = $destDir . '/' . $storedName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new RuntimeException('Could not save the uploaded file.');
    }

    return [
        'path' => 'uploads/' . $subdir . '/' . $storedName,
        'original_name' => $file['name'],
    ];
}

// Creates a minimal 'applicant' user account for someone an admin adds by hand (e.g. a walk-in
// applicant with no online account), so applications.user_id always has a real user to point at.
// The account has no usable password (walk-ins don't log in) and a generated placeholder email.
function getOrCreateWalkInUser($lastName, $firstName, $middleName = '') {
    global $conn;

    $placeholderEmail = 'walkin.' . strtolower(preg_replace('/[^a-z0-9]/i', '', $firstName . $lastName)) . '.' . bin2hex(random_bytes(3)) . '@walkin.local';
    $randomPasswordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO users (last_name, first_name, middle_name, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?, 'applicant', 'active')");
    $stmt->bind_param('sssss', $lastName, $firstName, $middleName, $placeholderEmail, $randomPasswordHash);
    $stmt->execute();
    $userId = $stmt->insert_id;
    $stmt->close();

    return $userId;
}

function logActivityLogin($userId, $fullName, $email, $role) {
    global $conn;
    $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, full_name, email, role, logged_in_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param('isss', $userId, $fullName, $email, $role);
    $stmt->execute();
    $_SESSION['activity_log_id'] = $stmt->insert_id;
    $stmt->close();
}

function logActivityLogout() {
    global $conn;
    if (empty($_SESSION['activity_log_id'])) {
        return;
    }
    $stmt = $conn->prepare("UPDATE activity_logs SET logged_out_at = NOW() WHERE log_id = ?");
    $stmt->bind_param('i', $_SESSION['activity_log_id']);
    $stmt->execute();
    $stmt->close();
}

// ---- OTP verification (registration + forgot password) ----

function generateOtpCode() {
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

// Starts (or restarts) a pending OTP challenge in the session. $data carries any extra
// payload the flow needs once verified (e.g. the not-yet-created registration fields).
// Returns the generated code so the caller can email it.
function startOtp($purpose, $email, array $data = []) {
    $_SESSION['otp_pending'] = [
        'purpose' => $purpose, // 'register' or 'reset'
        'email' => $email,
        'code' => generateOtpCode(),
        'expires_at' => time() + 600, // 10 minutes
        'attempts' => 0,
        'data' => $data,
    ];
    return $_SESSION['otp_pending']['code'];
}

function logAudit($action, $details = null) {
    global $conn;
    $user = currentUser();
    $userId = $user ? $user['user_id'] : null;
    $fullName = $user ? trim($user['first_name'] . ' ' . $user['last_name']) : 'Guest';
    $email = $user ? $user['email'] : '';
    $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, full_name, email, action, details) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('issss', $userId, $fullName, $email, $action, $details);
    $stmt->execute();
    $stmt->close();
}
