<?php
require_once __DIR__ . '/config/functions.php';

if (isLoggedIn()) {
    header("Location: " . dashboardUrlForRole($_SESSION['role']));
    exit();
}

// ---- Login attempt limit ----
// Like a phone: after 5 wrong passwords in a row the login is locked for 30 seconds, and every further
// round of 5 doubles the wait (30s, 60s, 2 min ... up to 15 min). Tracked per email + IP address, so one
// person's mistakes don't lock someone else out. A correct login (or 15 quiet minutes) clears the count.
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_BASE_LOCK_SECONDS = 30;
const LOGIN_MAX_LOCK_SECONDS = 900;
const LOGIN_FORGET_AFTER_SECONDS = 900;

function loginAttemptKey($email)
{
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    $ip = $forwarded !== '' ? trim(explode(',', $forwarded)[0]) : ($_SERVER['REMOTE_ADDR'] ?? '');
    return hash('sha256', strtolower(trim($email)) . '|' . $ip);
}

function loginAttemptRow($key)
{
    global $conn;
    $conn->query("CREATE TABLE IF NOT EXISTS login_attempts (
        attempt_key CHAR(64) PRIMARY KEY,
        fails INT NOT NULL DEFAULT 0,
        locked_until INT NOT NULL DEFAULT 0,
        last_attempt INT NOT NULL DEFAULT 0
    )");
    $stmt = $conn->prepare("SELECT fails, locked_until, last_attempt FROM login_attempts WHERE attempt_key = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row && $row['locked_until'] <= time() && time() - (int)$row['last_attempt'] > LOGIN_FORGET_AFTER_SECONDS) {
        return null; // old mistakes are forgotten
    }
    return $row ?: null;
}

function loginLockRemaining($key)
{
    $row = loginAttemptRow($key);
    return $row ? max(0, (int)$row['locked_until'] - time()) : 0;
}

// Records a wrong password. Returns ['left' => attempts before the next lock, 'lock' => seconds locked now].
function loginRegisterFailure($key)
{
    global $conn;
    $row = loginAttemptRow($key);
    $fails = ($row ? (int)$row['fails'] : 0) + 1;
    $lock = 0;
    if ($fails % LOGIN_MAX_ATTEMPTS === 0) {
        $round = intdiv($fails, LOGIN_MAX_ATTEMPTS);
        $lock = min(LOGIN_BASE_LOCK_SECONDS * (2 ** ($round - 1)), LOGIN_MAX_LOCK_SECONDS);
    }
    $lockedUntil = $lock > 0 ? time() + $lock : 0;
    $now = time();
    $stmt = $conn->prepare("INSERT INTO login_attempts (attempt_key, fails, locked_until, last_attempt) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE fails = VALUES(fails), locked_until = VALUES(locked_until), last_attempt = VALUES(last_attempt)");
    $stmt->bind_param('siii', $key, $fails, $lockedUntil, $now);
    $stmt->execute();
    $stmt->close();
    return ['left' => LOGIN_MAX_ATTEMPTS - ($fails % LOGIN_MAX_ATTEMPTS), 'lock' => $lock];
}

function loginClearFailures($key)
{
    global $conn;
    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE attempt_key = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->close();
}

$lockSeconds = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $attemptKey = loginAttemptKey($email);

    if ($email === '' || $password === '') {
        setFlash('error', 'Please enter your email and password.');
    } elseif (($lockSeconds = loginLockRemaining($attemptKey)) > 0) {
        setFlash('error', 'Too many failed attempts. Please wait before trying again.');
    } else {
        // SELECT * (not an explicit column list) so login still works even for one request right
        // after this file deploys but before MigrateSecretaryTreasurerFlags.php has been run —
        // an explicit is_secretary/is_treasurer column reference would hard-fail the query until
        // those columns exist, breaking login for everyone in that gap.
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $failure = loginRegisterFailure($attemptKey);
            if ($failure['lock'] > 0) {
                $lockSeconds = $failure['lock'];
                setFlash('error', 'Too many failed attempts. Please wait before trying again.');
            } elseif ($failure['left'] <= 3) {
                setFlash('error', 'Invalid email or password. ' . $failure['left'] . ' attempt' . ($failure['left'] === 1 ? '' : 's') . ' left before a temporary lock.');
            } else {
                setFlash('error', 'Invalid email or password.');
            }
        } elseif ($user['status'] !== 'active') {
            setFlash('error', 'This account is inactive. Please contact the SK office.');
        } else {
            loginClearFailures($attemptKey);
            $_SESSION['user_id'] = (int)$user['user_id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['is_secretary'] = (int)($user['is_secretary'] ?? 0);
            $_SESSION['is_treasurer'] = (int)($user['is_treasurer'] ?? 0);

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
                <h5 class="card-title"><i class="bi bi-person-fill"></i> Log in</h5>
                <p>Enter your credentials to access your account.</p>

                <?php if ($loginSuccess): ?>
                    <div class="alert alert-success py-2"><i class="bi bi-check-circle-fill me-1"></i><?php echo e($loginSuccess); ?></div>
                <?php endif; ?>
                <?php if ($loginError): ?>
                    <div class="alert alert-danger py-2"><?php echo e($loginError); ?><?php if ($lockSeconds > 0): ?> <strong><i class="bi bi-hourglass-split"></i> <span id="lockCountdown"><?php echo (int)$lockSeconds; ?></span>s</strong><?php endif; ?></div>
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
                        <a href="ForgotPassword.php" class="auth-link" style="font-size: 13px;">
                            Forgot Password?
                        </a>
                    </div>

                    <div class="d-grid mb-2">
                        <button type="submit" class="btn btn-auth" id="loginBtn" <?php echo $lockSeconds > 0 ? ' disabled' : ''; ?>>
                            Log in
                        </button>
                    </div>

                    <div class="text-center">
                        <small>
                            Don't have an account?
                            <a href="signup.php" class="auth-link">
                                Register here
                            </a>
                        </small>
                    </div>

                </form>
            </div>
            <div class="card-footer">
                <?php echo e(siteName()); ?>.
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <script>
        // Locked out: count down on screen and re-enable the button when the wait is over.
        (function() {
            const counter = document.getElementById('lockCountdown');
            const btn = document.getElementById('loginBtn');
            if (!counter) return;
            let left = parseInt(counter.textContent, 10);
            const timer = setInterval(function() {
                left--;
                if (left <= 0) {
                    clearInterval(timer);
                    btn.disabled = false;
                    counter.closest('.alert').innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>You can try again now.';
                    return;
                }
                counter.textContent = left;
            }, 1000);
        })();

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