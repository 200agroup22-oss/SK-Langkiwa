<?php
require_once __DIR__ . '/config/functions.php';

if (isLoggedIn()) {
    header("Location: " . dashboardUrlForRole($_SESSION['role']));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        setFlash('error', 'Please enter your email and password.');
    } else {
        $stmt = $conn->prepare("SELECT user_id, first_name, last_name, email, password_hash, role, status FROM users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            setFlash('error', 'Invalid email or password.');
        } elseif ($user['status'] !== 'active') {
            setFlash('error', 'This account is inactive. Please contact the SK office.');
        } else {
            $_SESSION['user_id'] = (int)$user['user_id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['email'] = $user['email'];

            logActivityLogin($user['user_id'], $user['first_name'] . ' ' . $user['last_name'], $user['email'], $user['role']);
            logAudit('Logged In');

            header("Location: " . dashboardUrlForRole($user['role']));
            exit();
        }
    }
}

$loginError = getFlash('error');
$loginSuccess = getFlash('success');
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Poppins Font -->
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
                <h5 class="card-title" style="font-family: 'Poppins', sans-serif; color: #409D42;font-weight: 800;"><i class="bi bi-person-fill"></i> Log in</h5>
                <p>Enter your credentials to access your account.</p>

                <?php if ($loginSuccess): ?>
                    <div class="alert alert-success py-2"><i class="bi bi-check-circle-fill me-1"></i><?php echo e($loginSuccess); ?></div>
                <?php endif; ?>
                <?php if ($loginError): ?>
                    <div class="alert alert-danger py-2"><?php echo e($loginError); ?></div>
                <?php endif; ?>

                <form action="" method="post">
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-envelope-fill"></i></span>
                            <input type="email" name="email" class="form-control" placeholder="Enter your email" required>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                            <input type="password" name="password" class="form-control" placeholder="Enter your password" required>
                            <span class="input-group-text" style="cursor:pointer;" onclick="togglePasswordVisibility(this)"><i class="bi bi-eye-fill"></i></span>
                        </div>
                    </div>

                    <div class="text-end mb-3">
                        <a href="ForgotPassword.php" style="font-size: 13px; color: #409D42; text-decoration: none;">
                            Forgot Password?
                        </a>
                    </div>

                    <div class="d-grid mb-2">
                        <button type="submit" class="btn" style="background-color:#409D42; color:white;">
                            Log in
                        </button>
                    </div>

                    <div class="text-center">
                        <small>
                            Don't have an account?
                            <a href="signup.php" style="color:#409D42; font-weight:500; text-decoration:none;">
                                Register here
                            </a>
                        </small>
                    </div>

                </form>
            </div>
            <div class="card-footer text-center text-body-secondary" style="font-size: 12px;">
                <?php echo e(siteName()); ?>.
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
