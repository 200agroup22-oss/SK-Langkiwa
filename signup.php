<?php
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/config/mail.php';

if (isLoggedIn()) {
    header("Location: " . dashboardUrlForRole($_SESSION['role']));
    exit();
}

$old = ['last_name' => '', 'first_name' => '', 'middle_name' => '', 'age' => '', 'gender' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lastName = trim($_POST['last_name'] ?? '');
    $firstName = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $age = trim($_POST['age'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $old = ['last_name' => $lastName, 'first_name' => $firstName, 'middle_name' => $middleName, 'age' => $age, 'gender' => $gender, 'email' => $email];

    $errors = [];
    if ($lastName === '' || $firstName === '') {
        $errors[] = 'Last name and first name are required.';
    }
    if (!in_array($gender, ['Male', 'Female'], true)) {
        $errors[] = 'Please select a gender.';
    }
    if ($age === '' || !ctype_digit($age) || (int)$age < 1) {
        $errors[] = 'Please enter a valid age.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }
    if (!isset($_POST['agree_terms'])) {
        $errors[] = 'You must agree to the Terms and Conditions to register.';
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            $errors[] = 'An account with that email already exists.';
        }
        $stmt->close();
    }

    if (empty($errors)) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $code = startOtp('register', $email, [
            'last_name' => $lastName,
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'age' => (int)$age,
            'gender' => $gender,
            'password_hash' => $passwordHash,
        ]);
        sendOtpEmail($email, $firstName, $code, 'register');

        header("Location: VerifyOtp.php");
        exit();
    } else {
        setFlash('error', implode(' ', $errors));
    }
}

$signupError = getFlash('error');
$settings = $conn->query("SELECT terms_conditions FROM site_settings WHERE id = 1")->fetch_assoc();
$termsText = $settings['terms_conditions'] ?? '';
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .card-header {
            font-family: 'Poppins', sans-serif;
            font-size: 20px;
            color: #409D42;
        }
    </style>
</head>

<body style="background-color: #b4ebc0;">
    <div class="container-fluid d-flex justify-content-center align-items-center min-vh-100 py-4">

        <div class="card" style="width: 600px;">
            <div class="card-header">
                <img src="<?php echo siteLogoUrl(); ?>" alt="logo" height="60px" style="border-radius:100%;">
                <?php echo e(siteName()); ?>
            </div>
            <div class="card-body">
                <h5 class="card-title" style="font-family: 'Poppins', sans-serif; color: #409D42; font-weight: 800;">
                    <i class="bi bi-person-plus-fill"></i> Create an Account
                </h5>
                <p>Fill in the details below to register your account.</p>

                <?php if ($signupError): ?>
                    <div class="alert alert-danger py-2"><?php echo e($signupError); ?></div>
                <?php endif; ?>

                <form action="" method="post">

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Last Name</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                                <input type="text" name="last_name" class="form-control" placeholder="Last name" value="<?php echo e($old['last_name']); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">First Name</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                                <input type="text" name="first_name" class="form-control" placeholder="First name" value="<?php echo e($old['first_name']); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Middle Name</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                                <input type="text" name="middle_name" class="form-control" placeholder="Middle name" value="<?php echo e($old['middle_name']); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Age</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-calendar-fill"></i></span>
                                <input type="number" name="age" class="form-control" placeholder="Enter your age" min="1" value="<?php echo e($old['age']); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Gender</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-gender-ambiguous"></i></span>
                                <select class="form-select" name="gender" required>
                                    <option value="" disabled <?php echo $old['gender'] === '' ? 'selected' : ''; ?>>Select gender</option>
                                    <option value="Male" <?php echo $old['gender'] === 'Male' ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo $old['gender'] === 'Female' ? 'selected' : ''; ?>>Female</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-envelope-fill"></i></span>
                            <input type="email" name="email" class="form-control" placeholder="Enter your email" value="<?php echo e($old['email']); ?>" required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                <input type="password" name="password" class="form-control" placeholder="Enter your password" minlength="8" required>
                                <span class="input-group-text" style="cursor:pointer;" onclick="togglePasswordVisibility(this)"><i class="bi bi-eye-fill"></i></span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Confirm Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                <input type="password" name="confirm_password" class="form-control" placeholder="Re-enter password" minlength="8" required>
                                <span class="input-group-text" style="cursor:pointer;" onclick="togglePasswordVisibility(this)"><i class="bi bi-eye-fill"></i></span>
                            </div>
                        </div>
                    </div>

                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="agreeTerms" name="agree_terms" required>
                        <label class="form-check-label" for="agreeTerms" style="font-size: 13px;">
                            I have read and agree to the
                            <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal" style="color:#409D42; font-weight:600; text-decoration:none;">
                                Terms and Conditions
                            </a>
                        </label>
                    </div>

                    <div class="d-grid mb-2">
                        <button type="submit" class="btn" style="background-color:#409D42; color:white;">
                            Register
                        </button>
                    </div>

                    <div class="text-center">
                        <small>
                            Already have an account?
                            <a href="login.php" style="color:#409D42; font-weight:500; text-decoration:none;">
                                Log in here
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

    <!-- TERMS AND CONDITIONS MODAL -->
    <div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable modal-dialog-centered" style="max-width: 620px;">
            <div class="modal-content border-0 shadow">
                <div class="modal-header" style="background: linear-gradient(90deg, #409D42, #86c98a); color: #fff;">
                    <h6 class="modal-title fw-bold" id="termsModalLabel"><i class="bi bi-file-earmark-text-fill me-2"></i>Terms and Conditions</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="filter: brightness(0) invert(1);"></button>
                </div>
                <div class="modal-body p-4" style="font-size: 13px; white-space: pre-wrap; line-height: 1.6;"><?php echo e($termsText); ?></div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-sm btn-success px-4" data-bs-dismiss="modal">Close</button>
                </div>
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
