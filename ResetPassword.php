<?php
require_once __DIR__ . '/config/functions.php';

if (isLoggedIn()) {
    header("Location: " . dashboardUrlForRole($_SESSION['role']));
    exit();
}

$resetEmail = $_SESSION['reset_email'] ?? null;
if (!$resetEmail) {
    header("Location: ForgotPassword.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
        $stmt->bind_param('ss', $hash, $resetEmail);
        $stmt->execute();
        $stmt->close();

        unset($_SESSION['reset_email']);
        logAudit('Reset Password', $resetEmail);
        setFlash('success', 'Password changed successfully. Please log in with your new password.');
        header("Location: login.php");
        exit();
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .card-header {
            font-family: 'Poppins', sans-serif;
            font-size: 17px;
            color: #409D42;
        }
    </style>
</head>

<body style="background-color: #b4ebc0;">
    <div class="container-fluid d-flex justify-content-center align-items-center vh-100">

        <div class="card" style="width: 450px;">
            <div class="card-header text-center">
                <img src="<?php echo siteLogoUrl(); ?>" alt="logo" height="70px" style="border-radius:100%;">
                <?php echo e(siteName()); ?>
            </div>
            <div class="card-body">
                <h5 class="card-title text-center" style="font-family: 'Poppins', sans-serif; color: #409D42; font-weight: 800;">
                    <i class="bi bi-lock-fill"></i> Reset Password
                </h5>
                <p class="text-center">Set a new password for <strong><?php echo e($resetEmail); ?></strong></p>

                <?php if ($error): ?>
                    <div class="alert alert-danger py-2"><?php echo e($error); ?></div>
                <?php endif; ?>

                <form action="" method="post">
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                            <input type="password" name="password" class="form-control" placeholder="Enter new password" minlength="8" required>
                            <span class="input-group-text" style="cursor:pointer;" onclick="togglePasswordVisibility(this)"><i class="bi bi-eye-fill"></i></span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Confirm Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                            <input type="password" name="confirm_password" class="form-control" placeholder="Re-enter new password" minlength="8" required>
                            <span class="input-group-text" style="cursor:pointer;" onclick="togglePasswordVisibility(this)"><i class="bi bi-eye-fill"></i></span>
                        </div>
                    </div>

                    <div class="d-grid mb-2">
                        <button type="submit" class="btn" style="background-color:#409D42; color:white;">
                            Reset Password
                        </button>
                    </div>
                </form>
            </div>
            <div class="card-footer text-center text-body-secondary" style="font-size: 12px;">
                <?php echo e(siteName()); ?>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
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