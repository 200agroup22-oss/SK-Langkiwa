<?php
// Super Admin review of new self-registered accounts: check the uploaded ID and proof of residency,
// then approve (the account can log in) or decline with a reason (they may register again).
require_once __DIR__ . '/../config/forms.php';
require_once __DIR__ . '/../config/registration_approval.php';
requireRole('admin');

ensureRegistrationApprovalSchema();
$me = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int)($_POST['user_id'] ?? 0);

    $stmt = $conn->prepare("SELECT user_id, first_name, last_name, email FROM users WHERE user_id = ? AND status = 'pending'");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$target) {
        setFlash('error', 'That registration is no longer pending.');
    } elseif (isset($_POST['approve_registration'])) {
        $stmt = $conn->prepare("UPDATE users SET status = 'active', decided_at = NOW(), decided_by = ?, decline_reason = NULL WHERE user_id = ?");
        $stmt->bind_param('ii', $me['user_id'], $userId);
        $stmt->execute();
        $stmt->close();
        $name = trim($target['first_name'] . ' ' . $target['last_name']);
        sendRegistrationDecisionEmail($target['email'], $name, 'approved');
        logAudit('Approved Registration', $name . ' (' . $target['email'] . ')');
        setFlash('success', $name . '\'s account was approved. They can now log in.');
    } elseif (isset($_POST['decline_registration'])) {
        $reason = trim($_POST['decline_reason'] ?? '');
        if ($reason === '') {
            setFlash('error', 'Please give a reason for declining.');
        } else {
            $reason = mb_substr($reason, 0, 255);
            $stmt = $conn->prepare("UPDATE users SET status = 'declined', decline_reason = ?, decided_at = NOW(), decided_by = ? WHERE user_id = ?");
            $stmt->bind_param('sii', $reason, $me['user_id'], $userId);
            $stmt->execute();
            $stmt->close();
            $name = trim($target['first_name'] . ' ' . $target['last_name']);
            sendRegistrationDecisionEmail($target['email'], $name, 'declined', $reason);
            logAudit('Declined Registration', $name . ' (' . $target['email'] . ') — ' . $reason);
            setFlash('success', $name . '\'s registration was declined.');
        }
    }

    header('Location: AccountApprovals.php' . (($_POST['tab'] ?? '') === 'declined' ? '?tab=declined' : ''));
    exit();
}

$pageError = getFlash('error');
$pageSuccess = getFlash('success');

$pending = $conn->query("SELECT * FROM users WHERE status = 'pending' ORDER BY created_at ASC")->fetch_all(MYSQLI_ASSOC);
$declined = $conn->query("SELECT u.*, CONCAT(d.first_name, ' ', d.last_name) AS decided_by_name
    FROM users u LEFT JOIN users d ON d.user_id = u.decided_by
    WHERE u.status = 'declined' ORDER BY u.decided_at DESC LIMIT 50")->fetch_all(MYSQLI_ASSOC);

$activeTab = ($_GET['tab'] ?? '') === 'declined' ? 'declined' : 'pending';
$activeLink = 'AccountApprovals';

function docLink($path, $label)
{
    if (empty($path)) {
        return '<span class="text-muted" style="font-size:12px;">' . e($label) . ': none</span>';
    }
    $url = APP_BASE . '/' . ltrim($path, '/');
    return '<a href="' . e($url) . '" target="_blank" rel="noopener" class="btn-view" style="text-decoration:none;display:inline-block;"><i class="bi bi-file-earmark-text"></i> ' . e($label) . '</a>';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Approvals</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo APP_BASE; ?>/assets/css/admin.css" rel="stylesheet">
</head>

<body>

    <?php include __DIR__ . '/../includes/adminsidebar.php'; ?>

    <div class="main-content">
        <h4 class="fw-bold mb-1">Account Approvals</h4>
        <p class="text-muted mb-3" style="font-size: 13px;">New registrations wait here until you have checked their ID and proof of residency. They can't log in until approved.</p>

        <?php if ($pageSuccess): ?><div class="alert alert-success py-2"><?php echo e($pageSuccess); ?></div><?php endif; ?>
        <?php if ($pageError): ?><div class="alert alert-danger py-2"><?php echo e($pageError); ?></div><?php endif; ?>

        <ul class="nav nav-pills mb-3 gap-1">
            <li class="nav-item"><a class="btn btn-sm <?php echo $activeTab === 'pending' ? 'btn-success' : 'btn-outline-success'; ?>" href="AccountApprovals.php">Pending <span class="badge text-bg-light ms-1"><?php echo count($pending); ?></span></a></li>
            <li class="nav-item"><a class="btn btn-sm <?php echo $activeTab === 'declined' ? 'btn-success' : 'btn-outline-success'; ?>" href="AccountApprovals.php?tab=declined">Declined <span class="badge text-bg-light ms-1"><?php echo count($declined); ?></span></a></li>
        </ul>

        <div class="table-card">
            <div class="table-responsive-wrap">
                <?php if ($activeTab === 'pending'): ?>
                    <table class="table table-stack mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Age / Gender</th>
                                <th>Submitted</th>
                                <th>Documents</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pending)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">No registrations are waiting for approval.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($pending as $u): ?>
                                <tr>
                                    <td data-label="Name"><?php echo e(trim($u['last_name'] . ', ' . $u['first_name'] . ' ' . ($u['middle_name'] ?? ''))); ?></td>
                                    <td data-label="Email"><?php echo e($u['email']); ?></td>
                                    <td data-label="Age / Gender"><?php echo e(($u['age'] ?? '—') . ' / ' . ($u['gender'] ?? '—')); ?></td>
                                    <td data-label="Submitted"><?php echo date('M j, Y g:i A', strtotime($u['created_at'])); ?></td>
                                    <td data-label="Documents" class="d-flex gap-1 flex-wrap">
                                        <?php echo docLink($u['id_document_path'], 'Valid ID'); ?>
                                        <?php echo docLink($u['residency_document_path'], 'Residency'); ?>
                                    </td>
                                    <td data-label="Action" class="d-flex gap-1 flex-wrap">
                                        <form method="post" data-confirm="Approve <?php echo e($u['first_name'] . ' ' . $u['last_name']); ?>'s registration? They will be able to log in." data-confirm-title="Approve Registration" data-confirm-ok="Yes, Approve" data-confirm-variant="success" class="m-0">
                                            <input type="hidden" name="user_id" value="<?php echo (int)$u['user_id']; ?>">
                                            <button type="submit" name="approve_registration" class="btn btn-sm btn-success py-0 px-2" style="font-size:12px;"><i class="bi bi-check-lg"></i> Approve</button>
                                        </form>
                                        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:12px;" data-bs-toggle="modal" data-bs-target="#declineModal<?php echo (int)$u['user_id']; ?>"><i class="bi bi-x-lg"></i> Decline</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <table class="table table-stack mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Reason</th>
                                <th>Declined</th>
                                <th>Documents</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($declined)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No declined registrations.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($declined as $u): ?>
                                <tr>
                                    <td data-label="Name"><?php echo e(trim($u['last_name'] . ', ' . $u['first_name'])); ?></td>
                                    <td data-label="Email"><?php echo e($u['email']); ?></td>
                                    <td data-label="Reason"><?php echo e($u['decline_reason']); ?></td>
                                    <td data-label="Declined"><?php echo $u['decided_at'] ? date('M j, Y', strtotime($u['decided_at'])) : '—'; ?><?php echo !empty($u['decided_by_name']) ? ' by ' . e($u['decided_by_name']) : ''; ?></td>
                                    <td data-label="Documents" class="d-flex gap-1 flex-wrap">
                                        <?php echo docLink($u['id_document_path'], 'Valid ID'); ?>
                                        <?php echo docLink($u['residency_document_path'], 'Residency'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php foreach ($pending as $u): ?>
        <div class="modal fade" id="declineModal<?php echo (int)$u['user_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <form method="post">
                        <input type="hidden" name="user_id" value="<?php echo (int)$u['user_id']; ?>">
                        <div class="modal-header">
                            <h6 class="modal-title fw-bold"><i class="bi bi-x-circle me-2"></i>Decline Registration</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4">
                            <p style="font-size:13px;">Decline <strong><?php echo e($u['first_name'] . ' ' . $u['last_name']); ?></strong>'s registration? They'll be emailed the reason and can register again.</p>
                            <label class="form-label" style="font-size:13px; font-weight:600;">Reason <span class="text-danger">*</span></label>
                            <textarea class="form-control form-control-sm" name="decline_reason" rows="3" maxlength="255" placeholder="e.g. The ID photo is unreadable" required></textarea>
                        </div>
                        <div class="modal-footer border-0">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="decline_registration" class="btn btn-sm btn-danger"><i class="bi bi-x-lg me-1"></i>Decline</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>