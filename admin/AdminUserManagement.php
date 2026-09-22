<?php
require_once __DIR__ . '/../config/forms.php';
requireRole('admin');

$me = currentUser();

// Replaces a committee_admin's committee assignments wholesale with the given list. Called after
// every add/edit so a role change away from committee_admin (or an empty selection) also clears
// out stale assignments instead of leaving orphaned rows behind.
function syncCommitteeAssignments($userId, $role, array $committeeIds)
{
    global $conn;
    $stmt = $conn->prepare("DELETE FROM admin_committee_assignments WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();

    if ($role !== 'committee_admin' || empty($committeeIds)) {
        return;
    }
    $stmt = $conn->prepare("INSERT IGNORE INTO admin_committee_assignments (user_id, committee_id) VALUES (?, ?)");
    foreach ($committeeIds as $cid) {
        $cid = (int)$cid;
        if ($cid <= 0) continue;
        $stmt->bind_param('ii', $userId, $cid);
        $stmt->execute();
    }
    $stmt->close();
}

// ---- POST handlers (redirect-after-POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['add_user'])) {
        $lastName = trim($_POST['last_name'] ?? '');
        $firstName = trim($_POST['first_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'applicant';
        $positionTitle = trim($_POST['position_title'] ?? '');
        $password = $_POST['password'] ?? '';
        $committeeIds = (array)($_POST['committee_ids'] ?? []);

        $allowedRoles = ['admin', 'committee_admin', 'scholar', 'applicant'];
        if (!in_array($role, $allowedRoles, true)) {
            $role = 'applicant';
        }

        if ($lastName === '' || $firstName === '' || $email === '' || $password === '') {
            setFlash('error', 'Last name, first name, email, and password are required.');
        } elseif ($role === 'committee_admin' && empty($committeeIds)) {
            setFlash('error', 'Select at least one committee for a Committee Admin.');
        } else {
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($exists) {
                setFlash('error', 'A user with that email already exists.');
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (last_name, first_name, email, password_hash, role, position_title, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
                $stmt->bind_param('ssssss', $lastName, $firstName, $email, $hash, $role, $positionTitle);
                $stmt->execute();
                $newId = $stmt->insert_id;
                $stmt->close();
                syncCommitteeAssignments($newId, $role, $committeeIds);
                logAudit('Added User', 'User #' . $newId . ' (' . $email . ')');
                setFlash('success', 'User added.');
            }
        }
    }

    if (isset($_POST['edit_user'])) {
        $targetId = (int)$_POST['user_id'];
        $lastName = trim($_POST['last_name'] ?? '');
        $firstName = trim($_POST['first_name'] ?? '');
        $middleName = trim($_POST['middle_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'applicant';
        $positionTitle = trim($_POST['position_title'] ?? '');
        $status = $_POST['status'] ?? 'active';
        $password = $_POST['password'] ?? '';
        $committeeIds = (array)($_POST['committee_ids'] ?? []);

        $ageRaw = trim($_POST['age'] ?? '');
        $age = ($ageRaw !== '' && ctype_digit($ageRaw)) ? (int)$ageRaw : null;
        $gender = $_POST['gender'] ?? '';
        if (!in_array($gender, ['Male', 'Female'], true)) {
            $gender = null;
        }

        $allowedRoles = ['admin', 'committee_admin', 'scholar', 'applicant'];
        if (!in_array($role, $allowedRoles, true)) {
            $role = 'applicant';
        }
        // Archiving is only done through the dedicated archive_user action (which also stamps
        // archived_at) — edit_user may only toggle between active/inactive.
        $allowedStatus = ['active', 'inactive'];
        if (!in_array($status, $allowedStatus, true)) {
            $status = 'active';
        }

        $isSelf = $targetId === (int)$me['user_id'];
        if ($isSelf && $role !== 'admin') {
            setFlash('error', 'You cannot change your own role away from admin.');
        } elseif ($lastName === '' || $firstName === '' || $email === '') {
            setFlash('error', 'Last name, first name, and email are required.');
        } elseif ($role === 'committee_admin' && empty($committeeIds)) {
            setFlash('error', 'Select at least one committee for a Committee Admin.');
        } else {
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
            $stmt->bind_param('si', $email, $targetId);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($exists) {
                setFlash('error', 'Another user already uses that email.');
            } else {
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET last_name = ?, first_name = ?, middle_name = ?, age = ?, gender = ?, email = ?, role = ?, position_title = ?, status = ?, password_hash = ? WHERE user_id = ?");
                    $stmt->bind_param('sssissssssi', $lastName, $firstName, $middleName, $age, $gender, $email, $role, $positionTitle, $status, $hash, $targetId);
                } else {
                    $stmt = $conn->prepare("UPDATE users SET last_name = ?, first_name = ?, middle_name = ?, age = ?, gender = ?, email = ?, role = ?, position_title = ?, status = ? WHERE user_id = ?");
                    $stmt->bind_param('sssisssssi', $lastName, $firstName, $middleName, $age, $gender, $email, $role, $positionTitle, $status, $targetId);
                }
                $stmt->execute();
                $stmt->close();
                syncCommitteeAssignments($targetId, $role, $committeeIds);
                logAudit('Updated User', 'User #' . $targetId . ' (' . $email . ')');
                setFlash('success', 'User updated.');
            }
        }
    }

    if (isset($_POST['archive_user'])) {
        $targetId = (int)$_POST['user_id'];
        if ($targetId === (int)$me['user_id']) {
            setFlash('error', 'You cannot archive your own account while logged in.');
        } else {
            $stmt = $conn->prepare("UPDATE users SET status = 'archived', archived_at = NOW() WHERE user_id = ?");
            $stmt->bind_param('i', $targetId);
            $stmt->execute();
            $stmt->close();
            logAudit('Archived User', 'User #' . $targetId);
            setFlash('success', 'User archived.');
        }
    }

    if (isset($_POST['restore_user'])) {
        $targetId = (int)$_POST['user_id'];
        $stmt = $conn->prepare("UPDATE users SET status = 'active', archived_at = NULL WHERE user_id = ?");
        $stmt->bind_param('i', $targetId);
        $stmt->execute();
        $stmt->close();
        logAudit('Restored User', 'User #' . $targetId);
        setFlash('success', 'User restored.');
    }

    header("Location: AdminUserManagement.php");
    exit();
}

$pageError = getFlash('error');
$pageSuccess = getFlash('success');

// ---- fetch + PHP-side filter/sort/search (thesis-scale dataset) ----
$roleFilter = $_GET['role'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$sortBy = $_GET['sort'] ?? '';
$search = trim($_GET['q'] ?? '');

$result = $conn->query("SELECT * FROM users WHERE status != 'archived' ORDER BY user_id ASC");
$users = $result->fetch_all(MYSQLI_ASSOC);
foreach ($users as &$u) {
    $u['full_name'] = trim($u['first_name'] . ' ' . $u['last_name']);
}
unset($u);

if ($roleFilter !== '') {
    $users = array_values(array_filter($users, fn($u) => $u['role'] === $roleFilter));
}
if ($statusFilter !== '') {
    $users = array_values(array_filter($users, fn($u) => $u['status'] === $statusFilter));
}
if ($search !== '') {
    $needle = mb_strtolower($search);
    $users = array_values(array_filter($users, fn($u) => str_contains(mb_strtolower($u['full_name']), $needle) || str_contains(mb_strtolower($u['email']), $needle)));
}
switch ($sortBy) {
    case 'name_asc':
        usort($users, fn($a, $b) => strcasecmp($a['full_name'], $b['full_name']));
        break;
    case 'name_desc':
        usort($users, fn($a, $b) => strcasecmp($b['full_name'], $a['full_name']));
        break;
    case 'role':
        usort($users, fn($a, $b) => strcasecmp($a['role'], $b['role']));
        break;
    case 'newest':
        usort($users, fn($a, $b) => $b['user_id'] <=> $a['user_id']);
        break;
    case 'oldest':
        usort($users, fn($a, $b) => $a['user_id'] <=> $b['user_id']);
        break;
}

$archivedResult = $conn->query("SELECT * FROM users WHERE status = 'archived' ORDER BY archived_at DESC");
$archivedUsers = $archivedResult->fetch_all(MYSQLI_ASSOC);

$allCommittees = $conn->query("SELECT * FROM committees WHERE archived_at IS NULL ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

// Every committee_admin's assigned committee names, keyed by user_id — one query instead of one
// per row in the user table below.
$committeeNamesByUser = [];
$assignResult = $conn->query("SELECT ca.user_id, c.name FROM admin_committee_assignments ca JOIN committees c ON c.committee_id = ca.committee_id ORDER BY c.name ASC");
foreach ($assignResult as $row) {
    $committeeNamesByUser[(int)$row['user_id']][] = $row['name'];
}
$committeeIdsByUser = [];
$assignIdsResult = $conn->query("SELECT user_id, committee_id FROM admin_committee_assignments");
foreach ($assignIdsResult as $row) {
    $committeeIdsByUser[(int)$row['user_id']][] = (int)$row['committee_id'];
}

$roleDisplayNames = ['admin' => 'Super Admin', 'committee_admin' => 'Committee Admin', 'scholar' => 'Scholar', 'applicant' => 'Applicant'];

function roleLabel($role, $positionTitle)
{
    if ($positionTitle !== '' && $positionTitle !== null) {
        return $positionTitle;
    }
    global $roleDisplayNames;
    return $roleDisplayNames[$role] ?? ucfirst($role);
}

$activeLink = 'AdminUserManagement';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo APP_BASE; ?>/assets/css/admin.css" rel="stylesheet">
    <style>
        .badge-role {
            background-color: #f0f0f0;
            color: #444;
            font-size: 11px;
            padding: 3px 10px;
            border-radius: 20px;
            font-weight: 600;
            border: 1px solid #ddd;
        }

        .badge-active {
            background-color: #4caf50;
            color: #fff;
            font-size: 11px;
            padding: 3px 10px;
            border-radius: 20px;
            font-weight: 600;
        }

        .badge-inactive {
            background-color: #9e9e9e;
            color: #fff;
            font-size: 11px;
            padding: 3px 10px;
            border-radius: 20px;
            font-weight: 600;
        }
    </style>
</head>

<body>

    <?php include __DIR__ . '/../includes/adminsidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <h4 class="fw-bold mb-1">User Management</h4>
        <p class="text-muted mb-2" style="font-size: 13px;">Manage system users and their roles.</p>

        <?php if ($pageSuccess): ?><div class="alert alert-success py-2"><?php echo e($pageSuccess); ?></div><?php endif; ?>
        <?php if ($pageError): ?><div class="alert alert-danger py-2"><?php echo e($pageError); ?></div><?php endif; ?>

        <form method="get">
            <!-- Table Header -->
            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addUserModal">
                        <i class="bi bi-plus-lg me-1"></i> Add User
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#archivesUserModal">
                        <i class="bi bi-archive me-1"></i> Archives
                    </button>
                </div>
                <div class="search-box">
                    <input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Search...">
                    <i class="bi bi-search"></i>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="d-flex gap-2 align-items-center mb-3 flex-wrap">
                <span style="font-size:12px; color:#666; font-weight:600;"><i class="bi bi-funnel me-1"></i>Filter:</span>
                <select class="filter-select" name="role" onchange="this.form.submit()">
                    <option value="">All Roles</option>
                    <option value="admin" <?php echo $roleFilter === 'admin' ? 'selected' : ''; ?>>Super Admin</option>
                    <option value="committee_admin" <?php echo $roleFilter === 'committee_admin' ? 'selected' : ''; ?>>Committee Admin</option>
                    <option value="scholar" <?php echo $roleFilter === 'scholar' ? 'selected' : ''; ?>>Scholar</option>
                    <option value="applicant" <?php echo $roleFilter === 'applicant' ? 'selected' : ''; ?>>Applicant</option>
                </select>
                <select class="filter-select" name="status" onchange="this.form.submit()">
                    <option value="">All Status</option>
                    <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
                <span style="font-size:12px; color:#666; font-weight:600; margin-left:6px;"><i class="bi bi-sort-down me-1"></i>Sort by:</span>
                <select class="filter-select" name="sort" onchange="this.form.submit()">
                    <option value="">Default Order</option>
                    <option value="name_asc" <?php echo $sortBy === 'name_asc' ? 'selected' : ''; ?>>Name: A &rarr; Z</option>
                    <option value="name_desc" <?php echo $sortBy === 'name_desc' ? 'selected' : ''; ?>>Name: Z &rarr; A</option>
                    <option value="role" <?php echo $sortBy === 'role' ? 'selected' : ''; ?>>Role</option>
                    <option value="newest" <?php echo $sortBy === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                    <option value="oldest" <?php echo $sortBy === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                </select>
            </div>
        </form>

        <!-- Table -->
        <div class="table-card">
            <div class="table-responsive-wrap">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No users found.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td><?php echo str_pad($u['user_id'], 3, '0', STR_PAD_LEFT); ?></td>
                                <td><?php echo e($u['full_name']); ?></td>
                                <td><?php echo e($u['email']); ?></td>
                                <td>
                                    <span class="badge-role"><?php echo e(roleLabel($u['role'], $u['position_title'])); ?></span>
                                    <?php if ($u['role'] === 'committee_admin'): ?>
                                        <div class="text-muted mt-1" style="font-size:11px;"><?php echo e(implode(', ', $committeeNamesByUser[(int)$u['user_id']] ?? []) ?: 'No committees assigned yet'); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="<?php echo $u['status'] === 'active' ? 'badge-active' : 'badge-inactive'; ?>"><?php echo ucfirst(e($u['status'])); ?></span></td>
                                <td class="d-flex gap-1">
                                    <button class="btn-view" data-bs-toggle="modal" data-bs-target="#viewUserModal<?php echo $u['user_id']; ?>"><i class="bi bi-eye"></i> View</button>
                                    <button class="btn-edit" data-bs-toggle="modal" data-bs-target="#editUserModal<?php echo $u['user_id']; ?>"><i class="bi bi-pencil"></i> Edit</button>
                                    <button class="btn-archive" data-bs-toggle="modal" data-bs-target="#archiveUserModal<?php echo $u['user_id']; ?>"><i class="bi bi-archive"></i> Archive</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-3">
            <span style="font-size:12px; color:#888;">Showing <?php echo count($users); ?> of <?php echo count($users); ?> entries</span>
        </div>
    </div>

    <?php foreach ($users as $u): ?>
        <!-- VIEW USER MODAL -->
        <div class="modal fade" id="viewUserModal<?php echo $u['user_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold"><i class="bi bi-person-circle me-2"></i>User Details</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="section-divider"><i class="bi bi-person-fill me-1"></i> Account Information</div>
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="info-label">User ID</div>
                                <div class="info-value"><?php echo str_pad($u['user_id'], 3, '0', STR_PAD_LEFT); ?></div>
                            </div>
                            <div class="col-6">
                                <div class="info-label">Status</div>
                                <div class="info-value"><span class="<?php echo $u['status'] === 'active' ? 'badge-active' : 'badge-inactive'; ?>"><?php echo ucfirst(e($u['status'])); ?></span></div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-label">First Name</div>
                                <div class="info-value"><?php echo e($u['first_name']); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-label">Last Name</div>
                                <div class="info-value"><?php echo e($u['last_name']); ?></div>
                            </div>
                            <?php if (!empty($u['middle_name'])): ?>
                                <div class="col-12">
                                    <div class="info-label">Middle Name</div>
                                    <div class="info-value"><?php echo e($u['middle_name']); ?></div>
                                </div>
                            <?php endif; ?>
                            <div class="col-12">
                                <div class="info-label">Email</div>
                                <div class="info-value"><?php echo e($u['email']); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-label">Age</div>
                                <div class="info-value"><?php echo $u['age'] !== null ? e($u['age']) : '—'; ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-label">Gender</div>
                                <div class="info-value"><?php echo !empty($u['gender']) ? e($u['gender']) : '—'; ?></div>
                            </div>
                            <div class="col-6">
                                <div class="info-label">Role</div>
                                <div class="info-value"><span class="badge-role"><?php echo e(roleLabel($u['role'], $u['position_title'])); ?></span></div>
                            </div>
                            <div class="col-6">
                                <div class="info-label">Date Registered</div>
                                <div class="info-value"><?php echo date('F j, Y', strtotime($u['created_at'])); ?></div>
                            </div>
                            <?php if ($u['role'] === 'committee_admin'): ?>
                                <div class="col-12">
                                    <div class="info-label">Assigned Committees</div>
                                    <div class="info-value"><?php echo e(implode(', ', $committeeNamesByUser[(int)$u['user_id']] ?? []) ?: 'None yet'); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- EDIT USER MODAL -->
        <div class="modal fade" id="editUserModal<?php echo $u['user_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <form method="post">
                        <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                        <div class="modal-header">
                            <h6 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit User</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" style="font-size:13px; font-weight:600;">First Name</label>
                                    <input type="text" class="form-control form-control-sm" name="first_name" value="<?php echo e($u['first_name']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" style="font-size:13px; font-weight:600;">Last Name</label>
                                    <input type="text" class="form-control form-control-sm" name="last_name" value="<?php echo e($u['last_name']); ?>" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" style="font-size:13px; font-weight:600;">Middle Name</label>
                                    <input type="text" class="form-control form-control-sm" name="middle_name" value="<?php echo e($u['middle_name']); ?>" placeholder="Optional">
                                </div>
                                <div class="col-12">
                                    <label class="form-label" style="font-size:13px; font-weight:600;">Email</label>
                                    <input type="email" class="form-control form-control-sm" name="email" value="<?php echo e($u['email']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" style="font-size:13px; font-weight:600;">Age</label>
                                    <input type="number" class="form-control form-control-sm" name="age" value="<?php echo e($u['age']); ?>" min="1" max="120" placeholder="Optional">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" style="font-size:13px; font-weight:600;">Gender</label>
                                    <select class="form-select form-select-sm" name="gender">
                                        <option value="" <?php echo empty($u['gender']) ? 'selected' : ''; ?>>Not set</option>
                                        <option value="Male" <?php echo $u['gender'] === 'Male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo $u['gender'] === 'Female' ? 'selected' : ''; ?>>Female</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" style="font-size:13px; font-weight:600;">Role</label>
                                    <select class="form-select form-select-sm role-select" name="role" onchange="toggleCommitteePicker(this)">
                                        <option value="admin" <?php echo $u['role'] === 'admin' ? 'selected' : ''; ?>>Super Admin</option>
                                        <option value="committee_admin" <?php echo $u['role'] === 'committee_admin' ? 'selected' : ''; ?>>Committee Admin</option>
                                        <option value="scholar" <?php echo $u['role'] === 'scholar' ? 'selected' : ''; ?>>Scholar</option>
                                        <option value="applicant" <?php echo $u['role'] === 'applicant' ? 'selected' : ''; ?>>Applicant</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" style="font-size:13px; font-weight:600;">Status</label>
                                    <select class="form-select form-select-sm" name="status">
                                        <option value="active" <?php echo $u['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo $u['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                                <div class="col-12 committee-picker" style="<?php echo $u['role'] === 'committee_admin' ? '' : 'display:none;'; ?>">
                                    <label class="form-label" style="font-size:13px; font-weight:600;">Assigned Committees</label>
                                    <div class="border rounded-2 p-2" style="max-height:150px; overflow-y:auto;">
                                        <?php foreach ($allCommittees as $c): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="committee_ids[]" value="<?php echo $c['committee_id']; ?>" id="editCommittee<?php echo $u['user_id']; ?>_<?php echo $c['committee_id']; ?>" <?php echo in_array((int)$c['committee_id'], $committeeIdsByUser[(int)$u['user_id']] ?? [], true) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" style="font-size:13px;" for="editCommittee<?php echo $u['user_id']; ?>_<?php echo $c['committee_id']; ?>"><?php echo e($c['name']); ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" style="font-size:13px; font-weight:600;">Position / Sub-role Title <span class="text-muted fw-normal">(e.g. SK Treasurer, Committee Chair on Education)</span></label>
                                    <input type="text" class="form-control form-control-sm" name="position_title" value="<?php echo e($u['position_title']); ?>" placeholder="Optional display title">
                                </div>
                                <div class="col-12">
                                    <label class="form-label" style="font-size:13px; font-weight:600;">New Password <span class="text-muted fw-normal">(leave blank to keep current)</span></label>
                                    <div class="input-group input-group-sm">
                                        <input type="password" class="form-control" name="password" placeholder="Enter new password">
                                        <span class="input-group-text" style="cursor:pointer;" onclick="togglePasswordVisibility(this)"><i class="bi bi-eye-fill"></i></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer border-0">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="edit_user" class="btn btn-sm btn-success"><i class="bi bi-save me-1"></i> Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ARCHIVE CONFIRMATION MODAL -->
        <div class="modal fade" id="archiveUserModal<?php echo $u['user_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
                <div class="modal-content border-0 shadow">
                    <form method="post">
                        <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                        <div class="modal-header" style="background: linear-gradient(90deg, #e53935, #ef9a9a);">
                            <h6 class="modal-title fw-bold text-white"><i class="bi bi-archive-fill me-2"></i>Archive User</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter: brightness(0) invert(1);"></button>
                        </div>
                        <div class="modal-body p-4 text-center">
                            <?php if ((int)$u['user_id'] === (int)$me['user_id']): ?>
                                <i class="bi bi-exclamation-triangle-fill text-danger" style="font-size: 40px;"></i>
                                <p class="mt-3 mb-1" style="font-size: 15px; font-weight: 600;">You cannot archive your own account.</p>
                                <p class="text-muted mb-0" style="font-size: 13px;">You are currently logged in as this user. Ask another admin to archive this account if needed.</p>
                            <?php else: ?>
                                <i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 40px;"></i>
                                <p class="mt-3 mb-1" style="font-size: 15px; font-weight: 600;">Are you sure you want to archive this user?</p>
                                <p class="text-muted mb-0" style="font-size: 13px;">This will deactivate <strong><?php echo e($u['full_name']); ?></strong> and remove their access to the system. You can restore them later.</p>
                            <?php endif; ?>
                        </div>
                        <div class="modal-footer border-0 justify-content-center">
                            <button type="button" class="btn btn-sm btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                            <?php if ((int)$u['user_id'] !== (int)$me['user_id']): ?>
                                <button type="submit" name="archive_user" class="btn btn-sm btn-danger px-4"><i class="bi bi-archive me-1"></i> Yes, Archive</button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- ADD USER MODAL -->
    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <form method="post">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold"><i class="bi bi-person-plus-fill me-2"></i>Add User</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" style="font-size:13px; font-weight:600;">First Name</label>
                                <input type="text" class="form-control form-control-sm" name="first_name" placeholder="First name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Last Name</label>
                                <input type="text" class="form-control form-control-sm" name="last_name" placeholder="Last name" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Email</label>
                                <input type="email" class="form-control form-control-sm" name="email" placeholder="Enter email address" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Role</label>
                                <select class="form-select form-select-sm role-select" name="role" required onchange="toggleCommitteePicker(this)">
                                    <option value="" disabled selected>Select role</option>
                                    <option value="admin">Super Admin</option>
                                    <option value="committee_admin">Committee Admin</option>
                                    <option value="scholar">Scholar</option>
                                    <option value="applicant">Applicant</option>
                                </select>
                            </div>
                            <div class="col-12 committee-picker" style="display:none;">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Assigned Committees</label>
                                <div class="border rounded-2 p-2" style="max-height:150px; overflow-y:auto;">
                                    <?php foreach ($allCommittees as $c): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="committee_ids[]" value="<?php echo $c['committee_id']; ?>" id="addCommittee<?php echo $c['committee_id']; ?>">
                                            <label class="form-check-label" style="font-size:13px;" for="addCommittee<?php echo $c['committee_id']; ?>"><?php echo e($c['name']); ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Position / Sub-role Title <span class="text-muted fw-normal">(e.g. SK Treasurer)</span></label>
                                <input type="text" class="form-control form-control-sm" name="position_title" placeholder="Optional display title">
                            </div>
                            <div class="col-12">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Password</label>
                                <div class="input-group input-group-sm">
                                    <input type="password" class="form-control" name="password" placeholder="Set password" required>
                                    <span class="input-group-text" style="cursor:pointer;" onclick="togglePasswordVisibility(this)"><i class="bi bi-eye-fill"></i></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_user" class="btn btn-sm btn-success"><i class="bi bi-plus-lg me-1"></i> Add User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ARCHIVES USER MODAL -->
    <div class="modal fade" id="archivesUserModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold"><i class="bi bi-archive-fill me-2"></i> Archived Users</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="table-card">
                        <div class="table-responsive-wrap">
                            <table class="table mb-0" style="font-size:13px;">
                                <thead>
                                    <tr>
                                        <th>User ID</th>
                                        <th>Full Name</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Archived On</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($archivedUsers)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">No archived users.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($archivedUsers as $arch): ?>
                                        <tr>
                                            <td><?php echo str_pad($arch['user_id'], 3, '0', STR_PAD_LEFT); ?></td>
                                            <td><?php echo e(trim($arch['first_name'] . ' ' . $arch['last_name'])); ?></td>
                                            <td><?php echo e($arch['email']); ?></td>
                                            <td><span class="badge-role"><?php echo e(roleLabel($arch['role'], $arch['position_title'])); ?></span></td>
                                            <td><?php echo $arch['archived_at'] ? date('Y-m-d H:i:s', strtotime($arch['archived_at'])) : '—'; ?></td>
                                            <td class="text-center">
                                                <form method="post">
                                                    <input type="hidden" name="user_id" value="<?php echo $arch['user_id']; ?>">
                                                    <button type="submit" name="restore_user" class="btn btn-sm btn-outline-success py-0 px-2" style="font-size:11px;">
                                                        <i class="bi bi-arrow-counterclockwise"></i> Restore
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleCommitteePicker(select) {
            const picker = select.closest('form').querySelector('.committee-picker');
            if (picker) picker.style.display = select.value === 'committee_admin' ? '' : 'none';
        }

        function togglePasswordVisibility(btn) {
            const input = btn.closest('.input-group').querySelector('input[type="password"], input[type="text"].pw-toggled');
            const icon = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                input.classList.add('pw-toggled');
                icon.classList.replace('bi-eye-fill', 'bi-eye-slash-fill');
            } else {
                input.type = 'password';
                input.classList.remove('pw-toggled');
                icon.classList.replace('bi-eye-slash-fill', 'bi-eye-fill');
            }
        }
    </script>
</body>

</html>