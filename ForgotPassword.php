<?php
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/config/mail.php';

if (isLoggedIn()) {
    header("Location: " . dashboardUrlForRole($_SESSION['role']));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    $stmt = $conn->prepare("SELECT user_id, first_name, status FROM users WHERE email = ?");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        setFlash('error', 'No account found with that email address.');
    } elseif ($user['status'] !== 'active') {
        setFlash('error', 'This account is inactive. Please contact the SK office.');
    } else {
        $code = startOtp('reset', $email);
        sendOtpEmail($email, $user['first_name'], $code, 'reset');
        header("Location: VerifyOtp.php");
        exit();
    }
}

$pageError = getFlash('error');
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot Password</title>
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
                    <i class="bi bi-key-fill"></i> Forgot Password
                </h5>
                <p class="text-center">Enter your account email and we'll send you a verification code to reset your password.</p>

                <?php if ($pageError): ?>
                    <div class="alert alert-danger py-2"><?php echo e($pageError); ?></div>
                <?php endif; ?>

                <form action="" method="post">
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-envelope-fill"></i></span>
                            <input type="email" name="email" class="form-control" placeholder="Enter your email" required>
                        </div>
                    </div>

                    <div class="d-grid mb-2">
                        <button type="submit" class="btn" style="background-color:#409D42; color:white;">
                            Send Verification Code
                        </button>
                    </div>

                    <div class="text-center">
                        <small>
                            <a href="login.php" style="color:#409D42; font-weight:500; text-decoration:none;">
                                <i class="bi bi-arrow-left"></i> Back to Log in
                            </a>
                        </small>
                    </div>
                </form>
            </div>
            <div class="card-footer text-center text-body-secondary" style="font-size: 12px;">
                <?php echo e(siteName()); ?>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
</body>

</html>
