<?php $me = currentUser(); ?>
<!-- Scholar Navbar -->
<nav class="navbar navbar-expand-lg shadow-sm fixed-top" style="background: linear-gradient(90deg, #45b84d, #aadaad); padding: 8px 0;">
    <div class="container-fluid px-4">

        <!-- Logo / Brand -->
        <a class="navbar-brand d-flex align-items-center gap-2 m-0" href="<?php echo APP_BASE; ?>/scholar/Scholar.php" style="font-size: 16px; font-weight: 700; color: #fff;">
            <img src="<?php echo siteLogoUrl(); ?>" width="36" height="36" alt="Logo" class="rounded-circle border border-white border-2">
            iSKolar ng Langkiwa
        </a>

        <!-- Toggle button for mobile -->
        <button class="navbar-toggler bg-light border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMenu">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Navbar Links -->
        <div class="collapse navbar-collapse" id="navbarMenu">

            <!-- Center Links -->
            <ul class="navbar-nav mx-auto gap-1">
                <li class="nav-item">
                    <a class="nav-link d-flex align-items-center gap-1 fw-semibold px-3 py-1 rounded" href="<?php echo APP_BASE; ?>/scholar/Scholar.php"
                        style="font-size: 14px; color: #fff;"
                        onmouseover="this.style.background='rgba(255,255,255,0.25)';"
                        onmouseout="this.style.background='';">
                        <i class="bi bi-grid-fill"></i> Home
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link d-flex align-items-center gap-1 fw-semibold px-3 py-1 rounded" href="<?php echo APP_BASE; ?>/applicant/EducationAssistanceForm.php"
                        style="font-size: 14px; color: #fff;"
                        onmouseover="this.style.background='rgba(255,255,255,0.25)';"
                        onmouseout="this.style.background='';">
                        <i class="bi bi-pencil-square"></i> Apply for Assistance
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link d-flex align-items-center gap-1 fw-semibold px-3 py-1 rounded" href="<?php echo APP_BASE; ?>/scholar/Activities.php"
                        style="font-size: 14px; color: #fff;"
                        onmouseover="this.style.background='rgba(255,255,255,0.25)';"
                        onmouseout="this.style.background='';">
                        <i class="bi bi-calendar-event-fill"></i> Activities
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link d-flex align-items-center gap-1 fw-semibold px-3 py-1 rounded" href="<?php echo APP_BASE; ?>/scholar/Allowance.php"
                        style="font-size: 14px; color: #fff;"
                        onmouseover="this.style.background='rgba(255,255,255,0.25)';"
                        onmouseout="this.style.background='';">
                        <i class="bi bi-cash-coin"></i> Allowance
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link d-flex align-items-center gap-1 fw-semibold px-3 py-1 rounded" href="<?php echo APP_BASE; ?>/scholar/UpdateRequirements.php"
                        style="font-size: 14px; color: #fff;"
                        onmouseover="this.style.background='rgba(255,255,255,0.25)';"
                        onmouseout="this.style.background='';">
                        <i class="bi bi-clipboard-check-fill"></i> Update Requirements
                    </a>
                </li>
            </ul>

            <!-- Right Side: Role Badge + User Dropdown -->
            <ul class="navbar-nav align-items-center gap-2">

                <!-- Role Badge -->
                <li class="nav-item">
                    <span class="badge rounded-pill px-3 py-2" style="background-color: #fff; color: #3a7d44; font-size: 13px; font-weight: 600;">Scholar</span>
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
                        <li>
                            <hr class="dropdown-divider my-1">
                        </li>
                        <li><a class="dropdown-item d-flex align-items-center gap-2 py-2 text-danger" href="<?php echo APP_BASE; ?>/logout.php"><i class="bi bi-box-arrow-right"></i> Log Out</a></li>
                    </ul>
                </li>

            </ul>
        </div>
    </div>
</nav>