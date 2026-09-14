<?php
// Form editing now lives inline in Configuration's Forms tab — redirect there instead of
// serving a standalone editor page for this track.
require_once __DIR__ . '/../../config/forms.php';
requireRole('admin');

$committeeId = getCommitteeIdByCode('education');
$tabs = getProgramTabs($committeeId, false);
$tabId = $tabs['scholarship']['tab_id'] ?? null;

header("Location: " . APP_BASE . "/admin/AdminConfiguration.php?tab=formsTab" . ($tabId ? '&ftab=' . (int)$tabId : ''));
exit();
