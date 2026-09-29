<?php
$me = currentUser();
$homeUrl = dashboardUrlForRole($me['role'] ?? 'applicant');
$roleLabel = ucfirst($me['role'] ?? 'applicant');
?>
<!-- Navbar -->
<nav class="navbar navbar-expand-lg shadow-sm fixed-top" style="background: linear-gradient(90deg, #45b84d, #aadaad); padding: 8px 0;">

    <div class="container-fluid px-4">

        <!-- Logo / Brand -->
        <a class="navbar-brand d-flex align-items-center gap-2 m-0" href="<?php echo $homeUrl; ?>" style="font-size: 16px; font-weight: 700; color: #fff;">
            <img src="<?php echo siteLogoUrl(); ?>"
                width="36"
                height="36"
                alt="Logo"
                class="rounded-circle border border-white border-2">
            <span class="d-none d-sm-inline"><?php echo e(siteName()); ?></span>
            <span class="d-sm-none">SK Langkiwa</span>
        </a>

        <!-- Toggle button for mobile -->
        <button class="navbar-toggler bg-light border-0"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#navbarMenu">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Navbar Links -->
        <div class="collapse navbar-collapse" id="navbarMenu">

            <!-- Center Links -->
            <ul class="navbar-nav mx-auto gap-1">
                <li class="nav-item">
                    <a class="nav-link d-flex align-items-center gap-1 fw-semibold px-3 py-1 rounded" href="<?php echo $homeUrl; ?>"
                        style="font-size: 14px; color: #fff;"
                        onmouseover="this.style.background='rgba(255,255,255,0.25)';"
                        onmouseout="this.style.background='';">
                        <i class="bi bi-grid-fill"></i> Home
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo APP_BASE; ?>/applicant/ApplicationForm.php" class="nav-link d-flex align-items-center gap-1 fw-semibold px-3 py-1 rounded"
                        style="font-size: 14px; color: #fff;"
                        onmouseover="this.style.background='rgba(255,255,255,0.25)';"
                        onmouseout="this.style.background='';">
                        <i class="bi bi-pencil-square"></i> Application Form
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo APP_BASE; ?>/applicant/ApplicationStatus.php" class="nav-link d-flex align-items-center gap-1 fw-semibold px-3 py-1 rounded"
                        style="font-size: 14px; color: #fff;"
                        onmouseover="this.style.background='rgba(255,255,255,0.25)';"
                        onmouseout="this.style.background='';">
                        <i class="bi bi-shield-check"></i> Application Status
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo APP_BASE; ?>/applicant/MyAssistance.php" class="nav-link d-flex align-items-center gap-1 fw-semibold px-3 py-1 rounded"
                        style="font-size: 14px; color: #fff;"
                        onmouseover="this.style.background='rgba(255,255,255,0.25)';"
                        onmouseout="this.style.background='';">
                        <i class="bi bi-hand-holding-heart-fill"></i> My Assistance
                    </a>
                </li>
            </ul>

            <!-- Right Side: Role Badge + User Dropdown -->
            <ul class="navbar-nav align-items-center gap-2">

                <!-- Role Badge -->
                <li class="nav-item">
                    <span class="badge rounded-pill px-3 py-2" style="background-color: #fff; color: #3a7d44; font-size: 13px; font-weight: 600;"><?php echo e($roleLabel); ?></span>
                </li>

                <!-- Profile Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center gap-2 border rounded-pill px-3 py-1"
                        href="#"
                        role="button"
                        data-bs-toggle="dropdown"
                        style="font-size: 14px; font-weight: 600; color: #fff; border-color: rgba(255,255,255,0.6) !important;">

                        <span class="rounded-circle d-flex align-items-center justify-content-center"
                            style="width:28px; height:28px; background: rgba(255,255,255,0.3);">
                            <i class="bi bi-person-fill text-white" style="font-size: 14px;"></i>
                        </span>
                        <?php echo e($me['first_name'] ?? ''); ?>
                    </a>

                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3 mt-1" style="min-width: 170px;">
                        <li><a class="dropdown-item d-flex align-items-center gap-2 py-2" href="<?php echo APP_BASE; ?>/UserProfile.php"><i class="bi bi-person-circle"></i> My Profile</a></li>
                        <li><a class="dropdown-item d-flex align-items-center gap-2 py-2" href="<?php echo APP_BASE; ?>/applicant/ApplicationStatus.php"><i class="bi bi-file-earmark-text"></i> My Application</a></li>
                        <li>
                            <hr class="dropdown-divider my-1">
                        </li>
                        <li><a class="dropdown-item d-flex align-items-center gap-2 py-2 text-danger" href="#" data-bs-toggle="modal" data-bs-target="#logoutConfirmModal"><i class="bi bi-box-arrow-right"></i> Log Out</a></li>
                    </ul>
                </li>

            </ul>
        </div>

    </div>

</nav>

<!-- LOG OUT CONFIRMATION MODAL -->
<div class="modal fade" id="logoutConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 380px;">
        <div class="modal-content border-0 shadow">
            <div class="modal-header" style="background: linear-gradient(90deg, #e53935, #ef9a9a);">
                <h6 class="modal-title fw-bold text-white"><i class="bi bi-box-arrow-right me-2"></i>Log Out</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter: brightness(0) invert(1);"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <i class="bi bi-question-circle-fill text-warning" style="font-size: 40px;"></i>
                <p class="mt-3 mb-0" style="font-size: 14px;">Are you sure you want to log out?</p>
            </div>
            <div class="modal-footer border-0 justify-content-center">
                <button type="button" class="btn btn-sm btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                <a href="<?php echo APP_BASE; ?>/logout.php" class="btn btn-sm btn-danger px-4"><i class="bi bi-box-arrow-right me-1"></i> Yes, Log Out</a>
            </div>
        </div>
    </div>
</div>