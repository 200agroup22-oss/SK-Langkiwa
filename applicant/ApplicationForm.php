<?php
require_once __DIR__ . '/../config/forms.php';
requireRole(['applicant', 'scholar']);

$me = currentUser();
$identity = ownIdentityFields($me['user_id']);
$committeeId = getCommitteeIdByCode('education');

$existing = null;
$stmt = $conn->prepare("SELECT application_id, status FROM applications WHERE user_id = ? AND committee_id = ? AND program_track = 'scholarship' AND status != 'declined' AND archived_at IS NULL LIMIT 1");
$stmt->bind_param('ii', $me['user_id'], $committeeId);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

$formError = null;
if (!$existing && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($identity as $key => $val) {
        $_POST[$key] = $val;
    }
    $errors = validateDynamicSubmission($committeeId, $_POST, $_FILES, [], 'scholarship');
    if (empty($errors)) {
        $settings = $conn->query("SELECT current_academic_year, current_semester FROM site_settings WHERE id = 1")->fetch_assoc();

        $stmt = $conn->prepare("INSERT INTO applications (user_id, committee_id, program_track, status, academic_year, semester) VALUES (?, ?, 'scholarship', 'pending', ?, ?)");
        $stmt->bind_param('iiss', $me['user_id'], $committeeId, $settings['current_academic_year'], $settings['current_semester']);
        $stmt->execute();
        $applicationId = $stmt->insert_id;
        $stmt->close();

        saveDynamicSubmission($applicationId, $committeeId, $_POST, $_FILES, 'scholarship');
        logAudit('Submitted Application', 'Education application #' . $applicationId);

        setFlash('success', 'Your Education application was submitted successfully.');
        header("Location: ApplicationStatus.php");
        exit();
    }
    $formError = implode(' ', $errors);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(siteName()); ?> | Scholar Application Form</title>

    <!-- Bootstrap 5.3.3 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons 1.11.3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <style>
        :root {
            --sk-green: #45b84d;
            --sk-green-light: #a5d6a7;
            --sk-green-dark: #388e3c;
        }

        body {
            background-color: #f4f6f5;
            font-family: 'Segoe UI', sans-serif;
        }

        /* Sidebar */
        .sidebar {
            background-color: #ffffff;
            min-height: calc(100vh - 56px);
            border-right: 1px solid #e0e0e0;
            padding-top: 1rem;
        }

        .sidebar .section-label {
            font-size: 0.75rem;
            font-weight: 700;
            color: #9e9e9e;
            padding: 0 1rem;
            margin-bottom: 0.5rem;
        }

        .sidebar .nav-link {
            color: #424242;
            padding: 0.6rem 1rem;
            font-size: 0.9rem;
            border-left: 4px solid transparent;
        }

        .sidebar .nav-link i {
            margin-right: 8px;
            width: 18px;
            text-align: center;
        }

        .sidebar .nav-link.active {
            background-color: var(--sk-green-light);
            border-left: 4px solid var(--sk-green-dark);
            font-weight: 600;
            color: #1b5e20;
        }

        .sidebar .nav-link:hover:not(.active) {
            background-color: #f1f8f2;
        }

        /* Form card */
        .form-card {
            background-color: #ffffff;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            padding: 2rem 2.5rem;
            margin-top: 2rem;
        }

        .form-card h4 {
            font-weight: 800;
            letter-spacing: 0.5px;
        }

        .btn-sk-submit {
            background-color: var(--sk-green);
            border-color: var(--sk-green);
            color: #fff;
            font-weight: 700;
            letter-spacing: 1px;
            padding: 0.6rem;
        }

        .btn-sk-submit:hover {
            background-color: var(--sk-green-dark);
            border-color: var(--sk-green-dark);
            color: #fff;
        }
    </style>
</head>

<body>

    <?php include(__DIR__ . '/../includes/applicantnav.php') ?>

    <?php require_once __DIR__ . '/../includes/applicantformsidebar.php'; ?>

    <div class="container-fluid" style="margin-top: 70px;">
        <!-- Mobile: compact committee/program switcher, reachable without scrolling past the form -->
        <div class="d-md-none mb-3">
            <a class="btn btn-outline-success w-100 d-flex justify-content-between align-items-center" href="#mobileCommitteeMenu" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="mobileCommitteeMenu">
                <span><i class="bi bi-list-ul me-2"></i>Switch Committee / Program</span>
                <i class="bi bi-chevron-down"></i>
            </a>
            <div class="collapse mt-2" id="mobileCommitteeMenu">
                <div class="sidebar sidebar-mobile border rounded p-2">
                    <?php renderApplicantCommitteeSidebar('m-'); ?>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Sidebar (desktop only) -->
            <div class="d-none d-md-block col-md-3 col-lg-2 sidebar">
                <?php renderApplicantCommitteeSidebar(); ?>
            </div>

            <!-- Main Content -->
            <div class="col-12 col-md-9 col-lg-10">
                <div class="form-card mx-auto" style="max-width: 850px;">
                    <h4 class="text-center mb-4">SCHOLAR APPLICATION FORM</h4>

                    <?php if ($existing): ?>
                        <div class="alert alert-info py-2 mb-0">
                            You already have an Education application on file (status: <strong><?php echo e(ucfirst($existing['status'])); ?></strong>).
                            <a href="ApplicationStatus.php">Check your Application Status page</a>, or use the sidebar to apply to another committee.
                        </div>
                    <?php else: ?>
                        <?php if ($formError): ?>
                            <div class="alert alert-danger py-2"><?php echo e($formError); ?></div>
                        <?php endif; ?>

                        <form method="post" enctype="multipart/form-data">
                            <?php renderDynamicFormFields($committeeId, [], [], 'scholarship', null, $identity); ?>
                            <button type="submit" class="btn btn-sk-submit w-100">SUBMIT</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>