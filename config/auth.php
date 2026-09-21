<?php
require_once __DIR__ . '/db.php';

// Change APP_BASE to '' if the app is deployed at the web root instead of /200A.
if (!defined('APP_BASE')) {
    define('APP_BASE', '');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn()
{
    return isset($_SESSION['user_id']);
}

function currentUser()
{
    if (!isLoggedIn()) {
        return null;
    }
    return [
        'user_id' => $_SESSION['user_id'],
        'role' => $_SESSION['role'],
        'first_name' => $_SESSION['first_name'],
        'last_name' => $_SESSION['last_name'],
        'email' => $_SESSION['email'],
    ];
}

function dashboardUrlForRole($role)
{
    switch ($role) {
        case 'admin':
        case 'committee_admin':
            return APP_BASE . '/admin/AdminDashboard.php';
        case 'scholar':
            return APP_BASE . '/scholar/Scholar.php';
        default:
            return APP_BASE . '/applicant/applicant.php';
    }
}

// Re-reads role/status from the DB into the session. Needed because actions like approving an
// Education application flip users.role to 'scholar' out from under an already-logged-in session
// — without this, the nav/dashboard would keep showing the old role until the user logs out and
// back in. Also catches a mid-session deactivation (status != 'active') and force-logs the user out.
function syncSession()
{
    global $conn;
    if (!isLoggedIn()) {
        return;
    }
    $stmt = $conn->prepare("SELECT role, status, first_name, last_name, email FROM users WHERE user_id = ?");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || $row['status'] !== 'active') {
        session_unset();
        session_destroy();
        header("Location: " . APP_BASE . "/login.php");
        exit();
    }

    $_SESSION['role'] = $row['role'];
    $_SESSION['first_name'] = $row['first_name'];
    $_SESSION['last_name'] = $row['last_name'];
    $_SESSION['email'] = $row['email'];
}

// $roles: a role string or array of allowed roles. Redirects to login (if guest)
// or to the caller's own dashboard (if logged in but wrong role).
function requireRole($roles)
{
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    if (!isLoggedIn()) {
        header("Location: " . APP_BASE . "/login.php");
        exit();
    }
    syncSession();
    if (!in_array($_SESSION['role'], $roles, true)) {
        header("Location: " . dashboardUrlForRole($_SESSION['role']));
        exit();
    }
}

function setFlash($type, $message)
{
    $_SESSION['flash_' . $type] = $message;
}

function getFlash($type)
{
    if (!empty($_SESSION['flash_' . $type])) {
        $message = $_SESSION['flash_' . $type];
        unset($_SESSION['flash_' . $type]);
        return $message;
    }
    return null;
}
