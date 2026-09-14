<?php
// Form editing now lives inline in Configuration's Forms tab — redirect there instead of
// serving a standalone editor page for this committee/program.
require_once __DIR__ . '/../config/forms.php';
requireRole('admin');

$committeeId = (int)($_GET['committee'] ?? 0);
$tabId = isset($_GET['ptab']) ? (int)$_GET['ptab'] : null;
if (!$tabId && $committeeId > 0) {
    $tabs = getProgramTabs($committeeId, false);
    $tabId = $tabs['assistance']['tab_id'] ?? null;
}

header("Location: " . APP_BASE . "/admin/AdminConfiguration.php?tab=formsTab" . ($tabId ? '&ftab=' . (int)$tabId : ''));
exit();
