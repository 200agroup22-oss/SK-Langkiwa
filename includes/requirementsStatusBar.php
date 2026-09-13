<?php
// Shared status strip shown on every applicant-facing form, site-wide, so applicants always
// know whether the iSKolar requirements submission window is currently open and when it's due
// — regardless of which program the form itself belongs to.
$__reqSettings = $conn->query("SELECT requirements_open, requirements_deadline FROM site_settings WHERE id = 1")->fetch_assoc();
$__reqOpen = !empty($__reqSettings['requirements_open'])
    && (empty($__reqSettings['requirements_deadline']) || strtotime($__reqSettings['requirements_deadline']) >= strtotime('today'));
$__reqDeadlinePassed = !empty($__reqSettings['requirements_deadline']) && strtotime($__reqSettings['requirements_deadline']) < strtotime('today');
?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <span class="badge <?php echo $__reqOpen ? 'bg-success' : 'bg-secondary'; ?>" style="font-size:12px; font-weight:600; padding:6px 12px;">
        <i class="bi bi-<?php echo $__reqOpen ? 'unlock-fill' : 'lock-fill'; ?> me-1"></i>Requirements submission <?php echo $__reqOpen ? 'open' : 'closed'; ?>
    </span>
    <?php if (!empty($__reqSettings['requirements_deadline'])): ?>
        <span class="badge <?php echo $__reqDeadlinePassed ? 'bg-danger' : 'bg-secondary'; ?>" style="font-size:12px; font-weight:600; padding:6px 12px;">
            <i class="bi bi-clock-history me-1"></i>Requirements deadline: <?php echo date('M j, Y', strtotime($__reqSettings['requirements_deadline'])); ?><?php echo $__reqDeadlinePassed ? ' (passed)' : ''; ?>
        </span>
    <?php endif; ?>
</div>
