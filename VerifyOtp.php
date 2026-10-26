<?php
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/config/mail.php';
require_once __DIR__ . '/config/registration_approval.php';

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
        $pending['code'] = generateOtpCode();
        $pending['expires_at'] = time() + 600;
        $pending['attempts'] = 0;
        $_SESSION['otp_pending'] = $pending;

        $name = $pending['purpose'] === 'register' ? ($pending['data']['first_name'] ?? '') : '';
        sendOtpEmail($pending['email'], $name, $pending['code'], $pending['purpose']);

        setFlash('success', 'A new code has been sent to ' . $pending['email'] . '.');
        header("Location: VerifyOtp.php");
        exit();
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

                // The account is created as 'pending' — it can't log in until the Super Admin has checked the
                // uploaded ID and proof of residency (Account Approvals). A previously declined registration
                // with the same email is reused (reset to pending with the new details and files).
                ensureRegistrationApprovalSchema();
                $idPath = $d['id_document_path'] ?? null;
                $residencyPath = $d['residency_document_path'] ?? null;

                $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND status = 'declined'");
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $declined = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($declined) {
                    $userId = (int)$declined['user_id'];
                    $stmt = $conn->prepare("UPDATE users SET last_name = ?, first_name = ?, middle_name = ?, age = ?, gender = ?, password_hash = ?, status = 'pending', id_document_path = ?, residency_document_path = ?, decline_reason = NULL, decided_at = NULL, decided_by = NULL WHERE user_id = ?");
                    $stmt->bind_param('sssissssi', $d['last_name'], $d['first_name'], $d['middle_name'], $d['age'], $d['gender'], $d['password_hash'], $idPath, $residencyPath, $userId);
                    $stmt->execute();
                    $stmt->close();
                } else {
                    $stmt = $conn->prepare("INSERT INTO users (last_name, first_name, middle_name, age, gender, email, password_hash, role, status, id_document_path, residency_document_path) VALUES (?, ?, ?, ?, ?, ?, ?, 'applicant', 'pending', ?, ?)");
                    $stmt->bind_param('sssisssss', $d['last_name'], $d['first_name'], $d['middle_name'], $d['age'], $d['gender'], $email, $d['password_hash'], $idPath, $residencyPath);
                    $stmt->execute();
                    $userId = $stmt->insert_id;
                    $stmt->close();
                }

                unset($_SESSION['otp_pending']);

                $fullName = trim($d['first_name'] . ' ' . $d['last_name']);
                $auditStmt = $conn->prepare("INSERT INTO audit_logs (user_id, full_name, email, action) VALUES (?, ?, ?, 'Registered')");
                $auditStmt->bind_param('iss', $userId, $fullName, $email);
                $auditStmt->execute();
                $auditStmt->close();

                setFlash('success', 'Email verified! Your registration is now waiting for approval by the Super Admin. You will be emailed once it is reviewed, and you can log in after it is approved.');
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
                    <button type="submit" name="resend" class="btn btn-link auth-link" style="font-size: 13px;">
                        Didn't get a code? Resend
                    </button>
                </form>
            </div>
            <div class="card-footer">
                <?php echo e(siteName()); ?>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
</body>

</html>