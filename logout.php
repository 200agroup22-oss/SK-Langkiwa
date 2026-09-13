<?php
require_once __DIR__ . '/config/functions.php';

if (isLoggedIn()) {
    logAudit('Logged Out');
    logActivityLogout();
}

$_SESSION = [];
session_destroy();

header("Location: " . APP_BASE . "/login.php");
exit();
