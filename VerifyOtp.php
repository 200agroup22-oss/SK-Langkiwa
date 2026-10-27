<?php
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/config/mail.php';

if (isLoggedIn()) {
    header("Location: " . dashboardUrlForRole($_SESSION['role']));
    exit();
}

$pending = $_SESSION['otp_pending'] ?? null;
if (!$pending) {
    header("Location: signup.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['resend'])) {
        if (($wait = otpCooldownRemaining($pending['email'])) > 0) {
            $error = 'Please wait ' . $wait . ' second' . ($wait === 1 ? '' : 's') . ' before requesting a new code.';
        } else {
            $pending['code'] = generateOtpCode();
            $pending['expires_at'] = time() + 600;
            $pending['attempts'] = 0;
            $_SESSION['otp_pending'] = $pending;
            otpMarkSent($pending['email']);

            $name = $pending['purpose'] === 'register' ? ($pending['data']['first_name'] ?? '') : '';
            if (sendOtpEmail($pending['email'], $name, $pending['code'], $pending['purpose'])) {
                setFlash('success', 'A new code has been sent to ' . $pending['email'] . '.');
                header("Location: VerifyOtp.php");
                exit();
            }
            otpClearCooldown($pending['email']);
            $error = 'We could not send the code right now. Please try again in a moment.';
        }
    }

    if (isset($_POST['verify'])) {
        $enteredCode = trim($_POST['otp_code'] ?? '');

        if (time() > $pending['expires_at']) {
            $error = 'This code has expired. Please request a new one.';
        } elseif ($pending['attempts'] >= 5) {
            $error = 'Too many incorrect attempts. Please request a new code.';
        } elseif ($enteredCode === '' || $enteredCode !== $pending['code']) {
            $pending['attempts']++;
            $_SESSION['otp_pending'] = $pending;
            $error = 'Incorrect code. Please try again.';
        } else {
            if ($pending['purpose'] === 'register') {
                $d = $pending['data'];
                $email = $pending['email'];

                $stmt = $conn->prepare("INSERT INTO users (last_name, first_name, middle_name, age, gender, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'applicant', 'active')");
                $stmt->bind_param('sssisss', $d['last_name'], $d['first_name'], $d['middle_name'], $d['age'], $d['gender'], $email, $d['password_hash']);
                $stmt->execute();
                $userId = $stmt->insert_id;
                $stmt->close();

                unset($_SESSION['otp_pending']);

                $fullName = trim($d['first_name'] . ' ' . $d['last_name']);
                $auditStmt = $conn->prepare("INSERT INTO audit_logs (user_id, full_name, email, action) VALUES (?, ?, ?, 'Registered')");
                $auditStmt->bind_param('iss', $userId, $fullName, $email);
                $auditStmt->execute();
                $auditStmt->close();

                setFlash('success', 'Account created successfully! Please log in.');
                header("Location: " . APP_BASE . "/login.php");
                exit();
            } else {
                $_SESSION['reset_email'] = $pending['email'];
                unset($_SESSION['otp_pending']);
                header("Location: ResetPassword.php");
                exit();
            }
        }
    }
}

$isReset = $pending['purpose'] === 'reset';
$pageTitle = $isReset ? 'Reset Your Password' : 'Verify Your Email';
$pageDesc = $isReset
    ? 'Enter the 6-digit code we sent to your email to continue resetting your password.'
    : 'Enter the 6-digit code we sent to your email to finish creating your account.';
$otpSuccess = getFlash('success');
$resendWait = otpCooldownRemaining($pending['email']);
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($pageTitle); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <?php include __DIR__ . '/includes/auth_style.php'; ?>
</head>

<body class="auth-body">
    <div class="container-fluid d-flex justify-content-center align-items-center vh-100">

        <div class="card auth-card" style="width: 450px; max-width: 94vw;">
            <div class="card-header text-center">
                <img src="<?php echo siteLogoUrl(); ?>" alt="logo">
                <?php echo e(siteName()); ?>
            </div>
            <div class="card-body">
                <h5 class="card-title text-center">
                    <i class="bi bi-shield-lock-fill"></i> <?php echo e($pageTitle); ?>
                </h5>
                <p class="text-center"><?php echo e($pageDesc); ?></p>
                <p class="text-center text-muted" style="font-size:13px;">Code sent to <strong><?php echo e($pending['email']); ?></strong></p>

                <?php if ($otpSuccess): ?>
                    <div class="alert alert-success py-2"><?php echo e($otpSuccess); ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger py-2"><?php echo e($error); ?></div>
                <?php endif; ?>

                <form action="" method="post">
                    <div class="mb-3">
                        <input type="text" name="otp_code" class="form-control otp-input" maxlength="6" inputmode="numeric" pattern="\d{6}" placeholder="------" autofocus required>
                    </div>
                    <div class="d-grid mb-2">
                        <button type="submit" name="verify" class="btn btn-auth">
                            Verify Code
                        </button>
                    </div>
                </form>

                <form action="" method="post" class="text-center">
                    <button type="submit" name="resend" id="resendBtn" class="btn btn-link auth-link" style="font-size: 13px;" <?php echo $resendWait > 0 ? ' disabled' : ''; ?>>
                        Didn't get a code? Resend<span id="resendTimer"><?php echo $resendWait > 0 ? ' in ' . $resendWait . 's' : ''; ?></span>
                    </button>
                </form>
            </div>
            <div class="card-footer">
                <?php echo e(siteName()); ?>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <script>
        // Resend is disabled while the cooldown runs, then switches back on by itself.
        (function() {
            let left = <?php echo (int)$resendWait; ?>;
            const btn = document.getElementById('resendBtn');
            const label = document.getElementById('resendTimer');
            if (left <= 0) return;
            const timer = setInterval(function() {
                left--;
                if (left <= 0) {
                    clearInterval(timer);
                    label.textContent = '';
                    btn.disabled = false;
                    return;
                }
                label.textContent = ' in ' + left + 's';
            }, 1000);
        })();
    </script>
</body>

</html>