<?php
require_once __DIR__ . '/auth.php';

// Escape for HTML output.
function e($value)
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// Returns the URL of the current site logo — the admin-uploaded one (Configuration > System
// Logo) if set, otherwise the bundled default. Used everywhere a logo is shown so uploading a
// new one updates it site-wide.
function siteLogoUrl()
{
    global $conn;
    $row = $conn->query("SELECT logo_path FROM site_settings WHERE id = 1")->fetch_assoc();
    return !empty($row['logo_path']) ? APP_BASE . '/' . $row['logo_path'] : APP_BASE . '/admin/photos/logo.jpg';
}

// Same resolution as siteLogoUrl(), but as a filesystem path instead of a URL — for contexts
// like FPDF's Image() that need to read the file directly rather than fetch it over HTTP.
function siteLogoPath()
{
    global $conn;
    $row = $conn->query("SELECT logo_path FROM site_settings WHERE id = 1")->fetch_assoc();
    $relative = !empty($row['logo_path']) ? $row['logo_path'] : 'admin/photos/logo.jpg';
    return __DIR__ . '/../' . $relative;
}

// The admin-configured "Site / Barangay Name" (Content Management > Site Settings), used
// everywhere the site's name is displayed so renaming it in one place updates it everywhere.
function siteName()
{
    global $conn;
    $row = $conn->query("SELECT site_name FROM site_settings WHERE id = 1")->fetch_assoc();
    return !empty($row['site_name']) ? $row['site_name'] : 'Sangguniang Kabataan ng Langkiwa';
}

function generateQrToken()
{
    return bin2hex(random_bytes(16));
}

// Shared password strength rule for signup, forgot-password reset, and change-password — at least
// 8 characters with one uppercase letter, one number, and one special character. Returns an error
// message string, or null if the password meets the requirement.
function validatePasswordStrength($password)
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (strlen($password) > 72) {
        return 'Password must be at most 72 characters.';
    }
    if (preg_match('/\s/', $password)) {
        return 'Password must not contain spaces.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must contain at least one lowercase letter.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must contain at least one uppercase letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'Password must contain at least one number.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Password must contain at least one special character.';
    }
    return null;
}

// Name rule: letters (incl. accented), spaces, hyphens, apostrophes, and periods only — no digits
// or other special characters — up to 50 characters. Returns an error message, or null if valid.
// An empty value is valid unless $required.
function validatePersonName($value, $label, $required = true)
{
    if ($value === '') {
        return $required ? "$label is required." : null;
    }
    if (mb_strlen($value) > 50) {
        return "$label must be at most 50 characters.";
    }
    if (!preg_match("/^\p{L}[\p{L} .'\-]*$/u", $value)) {
        return "$label may only contain letters, spaces, hyphens, apostrophes, and periods.";
    }
    return null;
}

// Email rule: valid format, up to 100 characters. Returns an error message, or null if valid.
function validateEmailAddress($email)
{
    if (strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Please enter a valid email address.';
    }
    return null;
}

// Validates a single $_FILES entry (extension, size, actual content type) without touching the
// filesystem, so callers can surface a bad file as a normal form error before doing anything
// that would need to be undone. Returns an error message string, or null if the file is valid.
function validateUploadedFile(array $file)
{
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return 'File must be 4MB or smaller.';
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return 'File upload failed (error code ' . $file['error'] . ').';
    }

    $allowedExts = ['jpg', 'jpeg', 'png', 'pdf'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts, true)) {
        return 'Only JPG, PNG, and PDF files are allowed.';
    }
    if ($file['size'] > 4 * 1024 * 1024) {
        return 'File must be 4MB or smaller.';
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
function handleUpload(array $file, $subdir = 'documents')
{
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
// Optional $email: a real address typed by the officer, so the walk-in gets the same approval and release emails
// as everyone else. It is used only when it is valid and not already taken by another account (otherwise the
// placeholder is kept). Optional $contactNumber is stored on the user for the release slip.
function ensureUserContactColumn()
{
    global $conn;
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $exists = $conn->query("SHOW COLUMNS FROM users LIKE 'contact_number'");
    if ($exists && $exists->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN contact_number VARCHAR(30) NULL");
    }
}

function getOrCreateWalkInUser($lastName, $firstName, $middleName = '', $email = '', $contactNumber = '')
{
    global $conn;

    $placeholderEmail = 'walkin.' . strtolower(preg_replace('/[^a-z0-9]/i', '', $firstName . $lastName)) . '.' . bin2hex(random_bytes(3)) . '@walkin.local';
    $useEmail = $placeholderEmail;
    $email = trim((string)$email);
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $taken = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        if (!$taken) {
            $useEmail = $email;
        }
    }
    $randomPasswordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO users (last_name, first_name, middle_name, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?, 'applicant', 'active')");
    $stmt->bind_param('sssss', $lastName, $firstName, $middleName, $useEmail, $randomPasswordHash);
    $stmt->execute();
    $userId = $stmt->insert_id;
    $stmt->close();

    $contactNumber = trim((string)$contactNumber);
    if ($contactNumber !== '') {
        ensureUserContactColumn();
        $contactNumber = substr(preg_replace('/[^0-9+\-\s()]/', '', $contactNumber), 0, 30);
        $stmt = $conn->prepare("UPDATE users SET contact_number = ? WHERE user_id = ?");
        $stmt->bind_param('si', $contactNumber, $userId);
        $stmt->execute();
        $stmt->close();
    }

    return $userId;
}

function logActivityLogin($userId, $fullName, $email, $role)
{
    global $conn;
    $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, full_name, email, role, logged_in_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param('isss', $userId, $fullName, $email, $role);
    $stmt->execute();
    $_SESSION['activity_log_id'] = $stmt->insert_id;
    $stmt->close();
}

function logActivityLogout()
{
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

function generateOtpCode()
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

// A new code can only be requested once every OTP_RESEND_SECONDS per email, wherever it is asked for
// (sign up, forgot password, Resend). Kept in the database so closing the tab or clearing cookies
// doesn't skip the wait.
const OTP_RESEND_SECONDS = 60;

function otpCooldownRemaining($email)
{
    global $conn;
    $key = hash('sha256', strtolower(trim($email)));
    $conn->query("CREATE TABLE IF NOT EXISTS otp_cooldowns (
        email_key CHAR(64) PRIMARY KEY,
        last_sent INT NOT NULL DEFAULT 0
    )");
    $stmt = $conn->prepare("SELECT last_sent FROM otp_cooldowns WHERE email_key = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? max(0, (int)$row['last_sent'] + OTP_RESEND_SECONDS - time()) : 0;
}

function otpClearCooldown($email)
{
    global $conn;
    $key = hash('sha256', strtolower(trim($email)));
    $stmt = $conn->prepare("DELETE FROM otp_cooldowns WHERE email_key = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->close();
}

function otpMarkSent($email)
{
    global $conn;
    $key = hash('sha256', strtolower(trim($email)));
    $now = time();
    $stmt = $conn->prepare("INSERT INTO otp_cooldowns (email_key, last_sent) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_sent = VALUES(last_sent)");
    $stmt->bind_param('si', $key, $now);
    $stmt->execute();
    $stmt->close();
}

// Starts (or restarts) a pending OTP challenge in the session. $data carries any extra
// payload the flow needs once verified (e.g. the not-yet-created registration fields).
// Returns the generated code so the caller can email it.
function startOtp($purpose, $email, array $data = [])
{
    otpMarkSent($email);
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

// Personal in-app notification (shown in that one user's own Announcement widget only), separate
// from admin-authored broadcast announcements — used for automatic status-change alerts like an
// application decision, so the applicant/scholar sees it without having to open their email.
function notifyUserInApp($userId, $title, $message)
{
    global $conn;
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
    $stmt->bind_param('iss', $userId, $title, $message);
    $stmt->execute();
    $stmt->close();
}

function logAudit($action, $details = null)
{
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

// Windowed page-number list for a pagination bar (e.g. [1,2,3,4,5,'...',57]) — shows every page
// when there are few, otherwise a block around the current page plus the first and last.
function paginationPageList($current, $total, $window = 2)
{
    if ($total <= 7) {
        return range(1, $total);
    }
    $pages = [1];
    $start = max(2, $current - $window);
    $end = min($total - 1, $current + $window);
    if ($current <= $window + 2) {
        $start = 2;
        $end = min($total - 1, 2 * $window + 1);
    }
    if ($current >= $total - $window - 1) {
        $end = $total - 1;
        $start = max(2, $total - (2 * $window + 1));
    }
    if ($start > 2) {
        $pages[] = '...';
    }
    for ($i = $start; $i <= $end; $i++) {
        $pages[] = $i;
    }
    if ($end < $total - 1) {
        $pages[] = '...';
    }
    $pages[] = $total;
    return $pages;
}

// Renders a "Showing X to Y of Z entries" line + a Bootstrap pagination bar for any of the admin
// list tables (Applicants, Cash/In-Kind Assistance, etc). Page links reuse the current request's
// own GET params (only swapping `page`), so whatever filter/sort/search is active survives moving
// between pages without the caller having to pass them in explicitly.
function renderPagination($current, $totalPages, $totalRows, $perPage)
{
    $from = $totalRows === 0 ? 0 : ($current - 1) * $perPage + 1;
    $to = min($current * $perPage, $totalRows);
    $urlFor = function ($page) {
        $params = $_GET;
        $params['page'] = $page;
        return '?' . http_build_query($params);
    };
?>
    <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
        <span style="font-size:12px; color:#888;">Showing <?php echo $from; ?> to <?php echo $to; ?> of <?php echo $totalRows; ?> entries</span>
        <?php if ($totalPages > 1): ?>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?php echo $current <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="<?php echo $current > 1 ? e($urlFor($current - 1)) : '#'; ?>">Previous</a>
                    </li>
                    <?php foreach (paginationPageList($current, $totalPages) as $p): ?>
                        <?php if ($p === '...'): ?>
                            <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                        <?php else: ?>
                            <li class="page-item <?php echo $p === $current ? 'active' : ''; ?>">
                                <a class="page-link" href="<?php echo e($urlFor($p)); ?>"><?php echo $p; ?></a>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <li class="page-item <?php echo $current >= $totalPages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="<?php echo $current < $totalPages ? e($urlFor($current + 1)) : '#'; ?>">Next</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
<?php
}
