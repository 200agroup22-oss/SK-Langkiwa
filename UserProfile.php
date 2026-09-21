<?php
require_once __DIR__ . '/config/functions.php';
requireRole(['applicant', 'scholar', 'admin', 'committee_admin']);

$me = currentUser();

$stmt = $conn->prepare("SELECT user_id, last_name, first_name, email, password_hash, profile_photo, role FROM users WHERE user_id = ?");
$stmt->bind_param('i', $me['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmNewPassword = $_POST['confirm_new_password'] ?? '';

    $errors = [];
    if ($firstName === '' || $lastName === '') {
        $errors[] = 'First name and last name are required.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (empty($errors) && $email !== $user['email']) {
        $check = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $check->bind_param('si', $email, $me['user_id']);
        $check->execute();
        if ($check->get_result()->fetch_assoc()) {
            $errors[] = 'That email is already in use by another account.';
        }
        $check->close();
    }

    $wantsPasswordChange = ($currentPassword !== '' || $newPassword !== '' || $confirmNewPassword !== '');
    $newPasswordHash = null;
    if (empty($errors) && $wantsPasswordChange) {
        if (!password_verify($currentPassword, $user['password_hash'])) {
            $errors[] = 'Current password is incorrect.';
        } elseif (strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($newPassword !== $confirmNewPassword) {
            $errors[] = 'New password and confirmation do not match.';
        } else {
            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        }
    }

    $photoPath = $user['profile_photo'];
    if (empty($errors) && !empty($_FILES['profile_photo']['name'])) {
        try {
            $uploaded = handleUpload($_FILES['profile_photo'], 'logos');
            if ($uploaded) {
                $photoPath = $uploaded['path'];
            }
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        }
    }

    if (empty($errors)) {
        if ($newPasswordHash) {
            $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, profile_photo = ?, password_hash = ? WHERE user_id = ?");
            $stmt->bind_param('sssssi', $firstName, $lastName, $email, $photoPath, $newPasswordHash, $me['user_id']);
        } else {
            $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, profile_photo = ? WHERE user_id = ?");
            $stmt->bind_param('ssssi', $firstName, $lastName, $email, $photoPath, $me['user_id']);
        }
        $stmt->execute();
        $stmt->close();

        $_SESSION['first_name'] = $firstName;
        $_SESSION['last_name'] = $lastName;
        $_SESSION['email'] = $email;

        logAudit('Updated Profile');
        setFlash('success', 'Profile updated successfully.');
    } else {
        setFlash('error', implode(' ', $errors));
    }

    header("Location: " . APP_BASE . "/UserProfile.php");
    exit();
}

$profileSuccess = getFlash('success');
$profileError = getFlash('error');
$photoUrl = $user['profile_photo'] ? APP_BASE . '/' . $user['profile_photo'] : APP_BASE . '/photos/default.jpg';
$roleLabel = ['admin' => 'Super Admin', 'committee_admin' => 'Committee Admin'][$user['role']] ?? ucfirst($user['role']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <?php if (in_array($user['role'], ['admin', 'committee_admin'], true)): ?>
        <link href="<?php echo APP_BASE; ?>/assets/css/admin.css" rel="stylesheet">
    <?php endif; ?>
    <style>
        body {
            background-color: #f0f0f0;
        }


        .profile-wrapper {
            max-width: 1020px;
            margin: 100px auto 40px;
        }

        /* ── Banner ── */
        .profile-banner {
            background: linear-gradient(90deg, #45b84d, #aadaad);
            border-radius: 14px 14px 0 0;
            padding: 30px 20px 24px;
            text-align: center;
            position: relative;
        }

        .avatar-ring {
            width: 180px;
            height: 180px;
            border-radius: 50%;
            border: 4px solid #fff;
            object-fit: cover;
            margin-bottom: 12px;
        }

        .profile-banner h5 {
            color: #fff;
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 2px;
        }

        .profile-banner .username {
            color: rgba(255, 255, 255, 0.85);
            font-size: 13px;
            margin-bottom: 10px;
        }

        .scholar-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            font-weight: 600;
            padding: 4px 14px;
            border-radius: 20px;
            background-color: rgba(255, 255, 255, 0.25);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.5);
        }

        /* ── Form Card ── */
        .form-card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 0 0 14px 14px;
            padding: 32px 36px;
        }

        .section-label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1px;
            color: #45b84d;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 16px;
        }

        .section-label::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e8e8e8;
        }

        .form-label {
            font-size: 12px;
            font-weight: 600;
            color: #555;
            margin-bottom: 4px;
        }

        .input-group-text {
            background-color: #f8f9fa;
            color: #45b84d;
            border-color: #ccc;
            border-right: none;
            font-size: 13px;
        }

        .form-control {
            font-size: 13px;
            border-color: #ccc;
            border-left: none;
        }

        .form-control:focus {
            border-color: #45b84d;
            box-shadow: 0 0 0 2px rgba(69, 184, 77, 0.15);
        }

        .input-group:focus-within .input-group-text {
            border-color: #45b84d;
        }

        /* ── Photo Upload ── */
        .file-upload-label {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            border: 1px dashed #a5d6a7;
            border-radius: 8px;
            padding: 9px;
            font-size: 12px;
            color: #777;
            cursor: pointer;
            background: #f9fdf9;
            transition: all 0.2s;
        }

        .file-upload-label:hover {
            border-color: #45b84d;
            color: #45b84d;
        }

        /* ── Buttons ── */
        .btn-save {
            background-color: #45b84d;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 9px 26px;
            font-weight: 600;
            font-size: 13px;
            transition: background 0.2s;
        }

        .btn-save:hover {
            background-color: #3aa342;
            color: #fff;
        }

        .btn-cancel {
            background-color: #f0f0f0;
            color: #555;
            border: 1px solid #ccc;
            border-radius: 8px;
            padding: 9px 26px;
            font-weight: 600;
            font-size: 13px;
        }

        .btn-cancel:hover {
            background-color: #e0e0e0;
        }

        /* The admin layout already offsets content for its fixed topbar via .main-content
           (margin-top: 52px) — the 100px top margin below is tuned for the plain applicant/
           scholar top navbar instead, so it would double up on top of that for admin. */
        .main-content .profile-wrapper {
            margin-top: 0;
        }
    </style>
</head>

<body>
    <?php
    if (in_array($user['role'], ['admin', 'committee_admin'], true)) {
        include __DIR__ . '/includes/adminsidebar.php';
        echo '<div class="main-content">';
    } elseif ($user['role'] === 'scholar') {
        include __DIR__ . '/includes/scholarnav.php';
        echo '<div class="container">';
    } else {
        include __DIR__ . '/includes/applicantnav.php';
        echo '<div class="container">';
    }
    ?>
    <div class="profile-wrapper">

        <!-- Banner -->
        <div class="profile-banner">
            <img src="<?php echo e($photoUrl); ?>" class="avatar-ring" alt="Profile Picture">
            <h5><?php echo e($user['first_name'] . ' ' . $user['last_name']); ?></h5>
            <div class="username">User ID: <?php echo str_pad($user['user_id'], 3, '0', STR_PAD_LEFT); ?></div>
            <span class="scholar-badge">
                <i class="bi bi-patch-check-fill"></i> <?php echo e($roleLabel); ?>
            </span>
        </div>

        <!-- Form Card -->
        <div class="form-card">

            <?php if ($profileSuccess): ?>
                <div class="alert alert-success py-2"><?php echo e($profileSuccess); ?></div>
            <?php endif; ?>
            <?php if ($profileError): ?>
                <div class="alert alert-danger py-2"><?php echo e($profileError); ?></div>
            <?php endif; ?>

            <form action="" method="post" enctype="multipart/form-data">

                <!-- Photo Upload -->
                <div class="section-label">
                    <i class="bi bi-camera-fill"></i> Profile Photo
                </div>
                <div class="row mb-4">
                    <div class="col-md-8">
                        <label class="file-upload-label w-100">
                            <i class="bi bi-image"></i> <span id="photoFileName">Choose new photo</span>
                            <input type="file" name="profile_photo" id="profilePhotoInput" class="d-none" accept="image/*"
                                onchange="document.getElementById('photoFileName').textContent = this.files[0] ? this.files[0].name : 'Choose new photo';">
                        </label>
                    </div>
                    <div class="col-md-4">
                        <button type="button" class="btn-save w-100" style="padding: 9px;" onclick="document.getElementById('profilePhotoInput').click();">
                            <i class="bi bi-camera-fill me-1"></i> Change Photo
                        </button>
                    </div>
                </div>

                <!-- Personal Info -->
                <div class="section-label">
                    <i class="bi bi-person-fill"></i> Personal Information
                </div>
                <div class="row mb-3">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">First Name</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                            <input type="text" name="first_name" class="form-control" value="<?php echo e($user['first_name']); ?>" required>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Last Name</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                            <input type="text" name="last_name" class="form-control" value="<?php echo e($user['last_name']); ?>" required>
                        </div>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Email Address</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-envelope-fill"></i></span>
                            <input type="email" name="email" class="form-control" value="<?php echo e($user['email']); ?>" required>
                        </div>
                    </div>
                </div>

                <!-- Change Password -->
                <div class="section-label">
                    <i class="bi bi-shield-lock-fill"></i> Change Password
                </div>
                <div class="mb-3">
                    <label class="form-label">Current Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                        <input type="password" name="current_password" class="form-control" placeholder="Enter current password">
                        <span class="input-group-text" style="cursor:pointer;" onclick="togglePasswordVisibility(this)"><i class="bi bi-eye-fill"></i></span>
                    </div>
                </div>
                <div class="row mb-4">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">New Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                            <input type="password" name="new_password" class="form-control" placeholder="Enter new password" minlength="8">
                            <span class="input-group-text" style="cursor:pointer;" onclick="togglePasswordVisibility(this)"><i class="bi bi-eye-fill"></i></span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Confirm New Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                            <input type="password" name="confirm_new_password" class="form-control" placeholder="Re-enter new password" minlength="8">
                            <span class="input-group-text" style="cursor:pointer;" onclick="togglePasswordVisibility(this)"><i class="bi bi-eye-fill"></i></span>
                        </div>
                    </div>
                </div>

                <!-- Buttons -->
                <div class="d-flex gap-2">
                    <button type="submit" name="update_user" class="btn-save">
                        <i class="bi bi-floppy-fill me-1"></i> Save Changes
                    </button>
                    <a href="<?php echo dashboardUrlForRole($user['role']); ?>" class="btn-cancel text-decoration-none text-center">
                        Cancel
                    </a>
                </div>

            </form>
        </div>

    </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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