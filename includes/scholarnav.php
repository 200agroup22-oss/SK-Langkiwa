<?php
$me = currentUser();
$currentPage = basename($_SERVER['SCRIPT_NAME']);

$navLinks = [
    ['file' => 'Scholar.php',              'href' => '/scholar/Scholar.php',                  'icon' => 'bi-grid-fill',            'label' => 'Home'],
    ['file' => 'EducationAssistanceForm.php', 'href' => '/applicant/EducationAssistanceForm.php', 'icon' => 'bi-pencil-square',    'label' => 'Apply for Assistance'],
    ['file' => 'Activities.php',           'href' => '/scholar/Activities.php',               'icon' => 'bi-calendar-event-fill',  'label' => 'Activities'],
    ['file' => 'Allowance.php',            'href' => '/scholar/Allowance.php',                'icon' => 'bi-cash-coin',            'label' => 'Allowance'],
    ['file' => 'UpdateRequirements.php',   'href' => '/scholar/UpdateRequirements.php',       'icon' => 'bi-clipboard-check-fill', 'label' => 'Update Requirements'],
];
?>
<style>
    .scholar-nav {
        background: linear-gradient(90deg, #45b84d, #aadaad);
        padding: 8px 0;
    }

    .scholar-nav .navbar-brand {
        font-size: 16px;
        font-weight: 700;
        color: #fff;
        min-width: 0;
    }

    .scholar-nav .navbar-toggler {
        background: #fff;
        border: 0;
        padding: 4px 8px;
    }

    .scholar-nav .navbar-toggler:focus {
        box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.5);
    }

    .scholar-nav .scholar-link {
        font-size: 14px;
        font-weight: 600;
        color: #fff;
        padding: 6px 12px;
        border-radius: 6px;
        white-space: nowrap;
        transition: background 0.15s;
    }

    .scholar-nav .scholar-link:hover,
    .scholar-nav .scholar-link:focus,
    .scholar-nav .scholar-link.active {
        color: #fff;
        background: rgba(255, 255, 255, 0.25);
    }

    .scholar-nav .scholar-profile {
        font-size: 14px;
        font-weight: 600;
        color: #fff;
        border: 1px solid rgba(255, 255, 255, 0.6);
        border-radius: 50rem;
        padding: 4px 12px;
    }

    .scholar-nav .scholar-role {
        background: #fff;
        color: #3a7d44;
        font-size: 13px;
        font-weight: 600;
    }

    /* Tablet landscape (iPad): keep everything on one row, no hamburger */
    @media (min-width: 992px) and (max-width: 1199.98px) {
        .scholar-nav .container-fluid {
            padding-left: 12px !important;
            padding-right: 12px !important;
        }

        .scholar-nav .navbar-brand span {
            font-size: 14px;
        }

        .scholar-nav .scholar-link {
            font-size: 13px;
            padding: 6px 8px;
        }

        .scholar-nav .scholar-link i {
            display: none;
        }

        .scholar-nav .scholar-role {
            display: none;
        }

        .scholar-nav .scholar-profile {
            font-size: 13px;
            padding: 3px 10px;
        }
    }

    /* Collapsed (mobile) layout */
    @media (max-width: 991.98px) {
        .scholar-nav .navbar-collapse {
            background: rgba(46, 125, 50, 0.95);
            border-radius: 10px;
            margin-top: 8px;
            padding: 10px;
            max-height: calc(100vh - 80px);
            overflow-y: auto;
        }

        .scholar-nav .scholar-link {
            padding: 10px 14px;
        }

        .scholar-nav .scholar-right {
            border-top: 1px solid rgba(255, 255, 255, 0.3);
            margin-top: 8px;
            padding-top: 10px;
            flex-direction: row;
            justify-content: space-between;
            flex-wrap: wrap;
        }

        .scholar-nav .scholar-profile-item {
            flex: 1;
            min-width: 0;
        }

        .scholar-nav .scholar-profile {
            justify-content: center;
        }

        /* keep the dropdown inside the panel instead of floating off-screen */
        .scholar-nav .dropdown-menu {
            position: static !important;
            transform: none !important;
            margin-top: 6px !important;
            width: 100%;
        }
    }
</style>

<!-- Scholar Navbar -->
<nav class="navbar navbar-expand-lg scholar-nav shadow-sm fixed-top">
    <div class="container-fluid px-3 px-md-4">

        <!-- Logo / Brand -->
        <a class="navbar-brand d-flex align-items-center gap-2 m-0" href="<?php echo APP_BASE; ?>/scholar/Scholar.php">
            <img src="<?php echo siteLogoUrl(); ?>" width="36" height="36" alt="Logo" class="rounded-circle border border-white border-2 flex-shrink-0">
            <span class="d-none d-sm-inline text-truncate">iSKolar ng Langkiwa</span>
            <span class="d-sm-none">iSKolar</span>
        </a>

        <!-- Toggle button -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMenu"
            aria-controls="navbarMenu" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Navbar Links -->
        <div class="collapse navbar-collapse" id="navbarMenu">

            <!-- Center Links -->
            <ul class="navbar-nav mx-lg-auto gap-1 mt-2 mt-lg-0">
                <?php foreach ($navLinks as $link): ?>
                    <li class="nav-item">
                        <a class="nav-link scholar-link d-flex align-items-center gap-2 <?php echo $currentPage === $link['file'] ? 'active' : ''; ?>"
                            href="<?php echo APP_BASE . $link['href']; ?>">
                            <i class="bi <?php echo $link['icon']; ?>"></i> <?php echo $link['label']; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <!-- Right Side: Role Badge + User Dropdown -->
            <ul class="navbar-nav scholar-right align-items-lg-center gap-2">

                <li class="nav-item">
                    <span class="badge rounded-pill px-3 py-2 scholar-role">Scholar</span>
                </li>

                <li class="nav-item dropdown scholar-profile-item">
                    <a class="nav-link dropdown-toggle scholar-profile d-flex align-items-center gap-2"
                        href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                            style="width:28px; height:28px; background: rgba(255,255,255,0.3);">
                            <i class="bi bi-person-fill text-white" style="font-size: 14px;"></i>
                        </span>
                        <span class="text-truncate"><?php echo e($me['first_name'] ?? ''); ?></span>
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