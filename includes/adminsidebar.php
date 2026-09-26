<?php
// Shared admin topbar + sidebar. Caller sets $activeLink (e.g. 'EducationApplicants') before including this file.
require_once __DIR__ . '/../config/forms.php';
$activeLink = $activeLink ?? '';
$adminMe = currentUser();
$isSuperAdmin = hasFullCommitteeAccess($adminMe['role'] ?? '');
// Content Management / User Management / Configuration stay Super-Admin-only even though
// secretary/treasurer see every committee's menu just like a Super Admin does.
$isFullAdmin = ($adminMe['role'] ?? '') === 'admin';
$myCommitteeIds = $isSuperAdmin ? [] : getUserCommitteeIds($adminMe['user_id']);
function committeeAllowed($committeeId)
{
    global $isSuperAdmin, $myCommitteeIds;
    return $isSuperAdmin || in_array((int)$committeeId, $myCommitteeIds, true);
}

$eduCommitteeId = getCommitteeIdByCode('education');
$healthCommitteeId = getCommitteeIdByCode('health');
$sportsCommitteeId = getCommitteeIdByCode('sports');
$citizenshipCommitteeId = getCommitteeIdByCode('active_citizenship');

$eduTabs = getProgramTabs($eduCommitteeId, false);
$healthTabs = getProgramTabs($healthCommitteeId, false);
$sportsTabs = getProgramTabs($sportsCommitteeId, false);
$citizenshipTabs = getProgramTabs($citizenshipCommitteeId, false);

// Any committee added via Content Management beyond the 4 built-in ones above — each renders its
// own top-level nav section further down, built entirely from its program tabs (a generic
// committee never gets a hardcoded "assistance" track the way the 4 built-in ones do).
$builtInCommitteeIds = [$eduCommitteeId, $healthCommitteeId, $sportsCommitteeId, $citizenshipCommitteeId];
$placeholders = implode(',', array_fill(0, count($builtInCommitteeIds), '?'));
$extraCommitteesStmt = $conn->prepare("SELECT * FROM committees WHERE committee_id NOT IN ($placeholders) AND archived_at IS NULL ORDER BY committee_id ASC");
$extraCommitteesStmt->bind_param(str_repeat('i', count($builtInCommitteeIds)), ...$builtInCommitteeIds);
$extraCommitteesStmt->execute();
$extraCommittees = $extraCommitteesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$extraCommitteesStmt->close();
if (!$isSuperAdmin) {
    $extraCommittees = array_values(array_filter($extraCommittees, fn($ec) => committeeAllowed($ec['committee_id'])));
}

// Per-committee page set that a program added via Content Management > Add Program should link
// to for its auto-generated Applications / Assistance Requests / Form submenu. Applications
// aren't reviewed per-program (only per track), so every program under a committee shares that
// committee's existing review pages — same as the built-in "Assistance Program" tab already does.
// No 'form'/'form_active' entries here — form editing lives inline in Configuration's Forms tab
// now, not on a per-program sidebar page (see renderExtraProgramTabLinks() below, which skips the
// "Form" link entirely when a page set has none).
$programSubmenuPages = [
    'education' => [
        'applications' => 'Education/EducationAssistanceApplicants.php',
        'applications_active' => 'EducationAssistanceApplicants',
        'cash' => 'Education/EducationCashAssistance.php',
        'cash_active' => 'EducationCashAssistance',
        'inkind' => 'Education/EducationInKindAssistance.php',
        'inkind_active' => 'EducationInKindAssistance',
    ],
    'health' => [
        'applications' => 'Health/HealthApplicants.php',
        'applications_active' => 'HealthApplicants',
        'cash' => 'Health/HealthCashAssistance.php',
        'cash_active' => 'HealthCashAssistance',
        'inkind' => 'Health/HealthInKindAssistance.php',
        'inkind_active' => 'HealthInKindAssistance',
    ],
    'sports' => [
        'applications' => 'Sports/SportsApplicants.php',
        'applications_active' => 'SportsApplicants',
        'cash' => 'Sports/SportsCashAssistance.php',
        'cash_active' => 'SportsCashAssistance',
        'inkind' => 'Sports/SportsInkindAssistance.php',
        'inkind_active' => 'SportsInkindAssistance',
    ],
    'active_citizenship' => [
        'applications' => 'ActiveCitizenship/ActiveCitizenshipApplicants.php',
        'applications_active' => 'ActiveCitizenshipApplicants',
        'cash' => 'ActiveCitizenship/ActiveCitizenshipCashAssistance.php',
        'cash_active' => 'ActiveCitizenshipCashAssistance',
        'inkind' => 'ActiveCitizenship/ActiveCitizenshipInKindAssistance.php',
        'inkind_active' => 'ActiveCitizenshipInKindAssistance',
    ],
];

// Extra tabs an admin created via Content Management > Add Program — renders the same
// Applications / Assistance Requests / Form submenu shape as the built-in tracks, using $pages
// (one of the per-committee sets above) to know where each item points. Appends `ptab=<tab id>`
// (which submenu should stay expanded/highlighted) and `program_id=<program id>` (which program's
// data the destination page should actually filter to) to a page URL. Assistance Requests shows
// Cash, In-Kind, or both, matching whichever type(s) the program was created as in the catalog.
function withProgramParams($urlPath, $ptab, $programId)
{
    $sep = strpos($urlPath, '?') !== false ? '&' : '?';
    return $urlPath . $sep . 'ptab=' . $ptab . '&program_id=' . $programId;
}

function renderExtraProgramTabLinks($committeeId, $tabs, $pages)
{
    static $counter = 0;
    $currentPtab = isset($_GET['ptab']) ? (int)$_GET['ptab'] : null;
    foreach ($tabs as $t) {
        if (empty($t['program_id']) || empty($t['is_visible'])) {
            continue;
        }
        $counter++;
        $menuId = 'programTab' . $counter . 'Menu';
        $reqMenuId = 'programTab' . $counter . 'ReqMenu';
        $type = $t['program_assistance_type'] ?? 'cash';
        $showCash = in_array($type, ['cash', 'both'], true) && !empty($pages['cash']);
        $showInKind = in_array($type, ['in_kind', 'both'], true) && !empty($pages['inkind']);
        $hasReq = $showCash || $showInKind;
        $ptab = (int)$t['tab_id'];
        $programId = (int)$t['program_id'];
        $isThisPtab = $currentPtab === $ptab;

        $expanded = $isThisPtab && (
            (navActive($pages['applications_active'] ?? '') !== '')
            || (navActive($pages['cash_active'] ?? '') !== '')
            || (navActive($pages['inkind_active'] ?? '') !== '')
            || (navActive($pages['form_active'] ?? '') !== '')
        );
        $reqExpanded = $isThisPtab && ((navActive($pages['cash_active'] ?? '') !== '') || (navActive($pages['inkind_active'] ?? '') !== ''));

        echo '<a href="#' . $menuId . '" class="nav-link-item nav-sub nav-sub-parent nav-program-tab" data-bs-toggle="collapse" role="button" aria-expanded="' . ($expanded ? 'true' : 'false') . '" aria-controls="' . $menuId . '">';
        echo '<i class="bi ' . e($t['icon']) . '"></i> ' . e($t['label']) . '<i class="bi bi-chevron-down toggle-icon-sub"></i></a>';
        echo '<div class="collapse' . ($expanded ? ' show' : '') . '" id="' . $menuId . '"><div class="submenu-nested program-sub">';

        if (!empty($pages['applications'])) {
            echo '<a href="' . APP_BASE . '/admin/' . e(withProgramParams($pages['applications'], $ptab, $programId)) . '" class="nav-link-item nav-sub-sub' . ($isThisPtab ? navActive($pages['applications_active']) : '') . '"><i class="bi bi-pencil-square"></i> Applications</a>';
        }

        if ($hasReq) {
            echo '<a href="#' . $reqMenuId . '" class="nav-link-item nav-sub-sub nav-sub-sub-parent" data-bs-toggle="collapse" role="button" aria-expanded="' . ($reqExpanded ? 'true' : 'false') . '" aria-controls="' . $reqMenuId . '">';
            echo '<i class="bi bi-cash-coin"></i> Assistance Requests<i class="bi bi-chevron-down toggle-icon-sub-sub"></i></a>';
            echo '<div class="collapse' . ($reqExpanded ? ' show' : '') . '" id="' . $reqMenuId . '"><div class="submenu-nested-2">';
            if ($showCash) {
                echo '<a href="' . APP_BASE . '/admin/' . e(withProgramParams($pages['cash'], $ptab, $programId)) . '" class="nav-link-item nav-sub-sub-sub' . ($isThisPtab ? navActive($pages['cash_active']) : '') . '"><i class="bi bi-cash"></i> Cash Assistance</a>';
            }
            if ($showInKind) {
                echo '<a href="' . APP_BASE . '/admin/' . e(withProgramParams($pages['inkind'], $ptab, $programId)) . '" class="nav-link-item nav-sub-sub-sub' . ($isThisPtab ? navActive($pages['inkind_active']) : '') . '"><i class="bi bi-box-seam"></i> In-Kind Assistance</a>';
            }
            echo '</div></div>';
        }

        if (!empty($pages['form'])) {
            $formHref = $pages['form'] === '#' ? '#' : (APP_BASE . '/admin/' . e(withProgramParams($pages['form'], $ptab, $programId)));
            echo '<a href="' . $formHref . '" class="nav-link-item nav-sub-sub' . ($isThisPtab ? navActive($pages['form_active']) : '') . '"><i class="bi bi-file-earmark-text"></i> Form</a>';
        }

        echo '</div></div>';
    }
}

// Page set for a committee added via Content Management that isn't one of the 4 built-in ones
// (Education/Health/Sports/Active Citizenship). Those generic committees have no dedicated pages
// of their own — every program under them shares the single generic CommitteeApplicants.php /
// CommitteeCashAssistance.php / CommitteeInKindAssistance.php set instead, selected via
// ?committee=<id>. Form editing isn't part of this set — it's handled inline in Configuration's
// Forms tab for every committee, built-in or generic alike.
function genericCommitteePages($committeeId)
{
    return [
        'applications' => 'CommitteeApplicants.php?committee=' . $committeeId,
        'applications_active' => 'CommitteeApplicants_' . $committeeId,
        'cash' => 'CommitteeCashAssistance.php?committee=' . $committeeId,
        'cash_active' => 'CommitteeCashAssistance_' . $committeeId,
        'inkind' => 'CommitteeInKindAssistance.php?committee=' . $committeeId,
        'inkind_active' => 'CommitteeInKindAssistance_' . $committeeId,
    ];
}

function navActive($name)
{
    global $activeLink;
    return $activeLink === $name ? ' active' : '';
}

function groupExpanded($prefixes)
{
    global $activeLink;
    foreach ((array)$prefixes as $prefix) {
        if (strpos($activeLink, $prefix) === 0) {
            return true;
        }
    }
    return false;
}

function collapseAttrs($expanded)
{
    return $expanded ? ['show', 'true'] : ['', 'false'];
}
?>
<!-- Topbar -->
<div class="topbar">
    <div class="brand">
        <button class="menu-toggle" id="menuToggle"><i class="bi bi-list"></i></button>
        <img src="<?php echo siteLogoUrl(); ?>" alt="Logo">
        <span class="brand-text d-none d-sm-inline"><?php echo e(siteName()); ?></span>
        <span class="brand-text d-sm-none">SK Langkiwa</span>
    </div>
    <div class="d-flex align-items-center gap-3">
        <?php $roleBadgeLabels = ['admin' => 'Super Admin', 'committee_admin' => 'Committee Admin', 'secretary' => 'Secretary', 'treasurer' => 'Treasurer']; ?>
        <span class="role-badge d-none d-sm-inline-block"><?php echo e($roleBadgeLabels[$adminMe['role'] ?? ''] ?? ucfirst($adminMe['role'] ?? '')); ?></span>
        <div class="dropdown">
            <a href="#" class="dropdown-toggle d-flex align-items-center gap-2 text-white text-decoration-none border border-white border-opacity-50 rounded-pill px-3 py-1"
                data-bs-toggle="dropdown" style="font-size: 14px; font-weight: 600;">
                <i class="bi bi-person-circle fs-5"></i> <?php echo e($adminMe['first_name'] ?? 'Admin'); ?>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3 mt-1">
                <li><a class="dropdown-item d-flex align-items-center gap-2 py-2" href="<?php echo APP_BASE; ?>/UserProfile.php"><i class="bi bi-person-circle"></i> My Profile</a></li>
                <li>
                    <hr class="dropdown-divider my-1">
                </li>
                <li><a class="dropdown-item d-flex align-items-center gap-2 py-2 text-danger" href="<?php echo APP_BASE; ?>/logout.php"><i class="bi bi-box-arrow-right"></i> Log Out</a></li>
            </ul>
        </div>
    </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="logo-wrap">
        <img src="<?php echo siteLogoUrl(); ?>" alt="Logo">
        <p><?php echo e(siteName()); ?></p>
    </div>
    <nav>
        <a href="<?php echo APP_BASE; ?>/admin/AdminDashboard.php" class="nav-link-item<?php echo navActive('AdminDashboard'); ?>"><i class="bi bi-bar-chart-line-fill"></i> Dashboard</a>

        <?php
        // A built-in committee's base "Assistance Program"/"Assistance Requests" tab shares its
        // Applications/Cash/In-Kind/Form pages with every custom program tab added under that same
        // committee (see renderExtraProgramTabLinks() above) — so by $activeLink text alone, the
        // base tab can't tell whether the current page was reached via itself or via a sibling
        // program tab. A program-specific link always carries `ptab`; the base tab's own links
        // never do. So only let the base tab's submenu expand/highlight when there's no ptab —
        // otherwise it would light up together with whichever program tab was actually clicked.
        $isBaseProgramView = empty($_GET['ptab']);
        ?>

        <!-- EDUCATION COMMITTEE -->
        <?php if (committeeAllowed($eduCommitteeId)): ?>
            <?php
            $eduExpanded = groupExpanded('Education');
            $iskolarExpanded = groupExpanded(['EducationApplicants', 'EducationScholarList', 'EducationAllowanceDistribution', 'EducationActivities']);
            $eduAssistExpanded = $isBaseProgramView && groupExpanded(['EducationAssistanceApplicants', 'EducationCashAssistance', 'EducationInKindAssistance']);
            $eduAssistReqExpanded = $isBaseProgramView && groupExpanded(['EducationCashAssistance', 'EducationInKindAssistance']);
            ?>
            <a href="#educationMenu" class="nav-link-item nav-parent" data-bs-toggle="collapse" role="button" aria-expanded="<?php echo $eduExpanded ? 'true' : 'false'; ?>" aria-controls="educationMenu">
                <i class="bi bi-mortarboard-fill"></i> Education Committee
                <i class="bi bi-chevron-down toggle-icon"></i>
            </a>
            <div class="collapse<?php echo $eduExpanded ? ' show' : ''; ?>" id="educationMenu">
                <div class="submenu">
                    <?php if (!empty($eduTabs['scholarship']['is_visible'])): ?>
                        <a href="#iskolarMenu" class="nav-link-item nav-sub nav-sub-parent nav-program-tab" data-bs-toggle="collapse" role="button" aria-expanded="<?php echo $iskolarExpanded ? 'true' : 'false'; ?>" aria-controls="iskolarMenu">
                            <i class="bi <?php echo e($eduTabs['scholarship']['icon']); ?>"></i> <?php echo e($eduTabs['scholarship']['label']); ?>
                            <i class="bi bi-chevron-down toggle-icon-sub"></i>
                        </a>
                        <div class="collapse<?php echo $iskolarExpanded ? ' show' : ''; ?>" id="iskolarMenu">
                            <div class="submenu-nested program-sub">
                                <a href="<?php echo APP_BASE; ?>/admin/Education/EducationApplicants.php" class="nav-link-item nav-sub-sub<?php echo navActive('EducationApplicants'); ?>"><i class="bi bi-pencil-square"></i> Applications</a>
                                <a href="<?php echo APP_BASE; ?>/admin/Education/EducationScholarList.php" class="nav-link-item nav-sub-sub<?php echo navActive('EducationScholarList'); ?>"><i class="bi bi-person-check-fill"></i> Scholars</a>
                                <a href="<?php echo APP_BASE; ?>/admin/Education/EducationAllowanceDistribution.php" class="nav-link-item nav-sub-sub<?php echo navActive('EducationAllowanceDistribution'); ?>"><i class="bi bi-cash"></i> Allowance Distribution</a>
                                <a href="<?php echo APP_BASE; ?>/admin/Education/EducationActivities.php" class="nav-link-item nav-sub-sub<?php echo navActive('EducationActivities'); ?>"><i class="bi bi-calendar-event-fill"></i> Activities</a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($eduTabs['assistance']['is_visible'])): ?>
                        <a href="#assistanceProgramMenu" class="nav-link-item nav-sub nav-sub-parent nav-program-tab" data-bs-toggle="collapse" role="button" aria-expanded="<?php echo $eduAssistExpanded ? 'true' : 'false'; ?>" aria-controls="assistanceProgramMenu">
                            <i class="bi <?php echo e($eduTabs['assistance']['icon']); ?>"></i> <?php echo e($eduTabs['assistance']['label']); ?>
                            <i class="bi bi-chevron-down toggle-icon-sub"></i>
                        </a>
                        <div class="collapse<?php echo $eduAssistExpanded ? ' show' : ''; ?>" id="assistanceProgramMenu">
                            <div class="submenu-nested program-sub">
                                <a href="<?php echo APP_BASE; ?>/admin/Education/EducationAssistanceApplicants.php" class="nav-link-item nav-sub-sub<?php echo $isBaseProgramView ? navActive('EducationAssistanceApplicants') : ''; ?>"><i class="bi bi-pencil-square"></i> Applications</a>

                                <a href="#educationAssistanceRequestsMenu" class="nav-link-item nav-sub-sub nav-sub-sub-parent" data-bs-toggle="collapse" role="button" aria-expanded="<?php echo $eduAssistReqExpanded ? 'true' : 'false'; ?>" aria-controls="educationAssistanceRequestsMenu">
                                    <i class="bi bi-cash-coin"></i> Assistance Requests
                                    <i class="bi bi-chevron-down toggle-icon-sub-sub"></i>
                                </a>
                                <div class="collapse<?php echo $eduAssistReqExpanded ? ' show' : ''; ?>" id="educationAssistanceRequestsMenu">
                                    <div class="submenu-nested-2">
                                        <a href="<?php echo APP_BASE; ?>/admin/Education/EducationCashAssistance.php" class="nav-link-item nav-sub-sub-sub<?php echo $isBaseProgramView ? navActive('EducationCashAssistance') : ''; ?>"><i class="bi bi-cash"></i> Cash Assistance</a>
                                        <a href="<?php echo APP_BASE; ?>/admin/Education/EducationInKindAssistance.php" class="nav-link-item nav-sub-sub-sub<?php echo $isBaseProgramView ? navActive('EducationInKindAssistance') : ''; ?>"><i class="bi bi-box-seam"></i> In-Kind Assistance</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php renderExtraProgramTabLinks($eduCommitteeId, $eduTabs, $programSubmenuPages['education']); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- HEALTH COMMITTEE -->
        <?php
        $healthExpanded = groupExpanded('Health');
        $healthAssistExpanded = $isBaseProgramView && groupExpanded(['HealthApplicants', 'HealthCashAssistance', 'HealthInKindAssistance']);
        $healthReqExpanded = $isBaseProgramView && groupExpanded(['HealthCashAssistance', 'HealthInKindAssistance']);
        ?>
        <?php if (committeeAllowed($healthCommitteeId) && !empty($healthTabs['assistance']['is_visible'])): ?>
            <a href="#healthMenu" class="nav-link-item nav-parent" data-bs-toggle="collapse" role="button" aria-expanded="<?php echo $healthExpanded ? 'true' : 'false'; ?>" aria-controls="healthMenu">
                <i class="bi bi-heart-pulse-fill"></i> Health
                <i class="bi bi-chevron-down toggle-icon"></i>
            </a>
            <div class="collapse<?php echo $healthExpanded ? ' show' : ''; ?>" id="healthMenu">
                <div class="submenu">
                    <a href="#healthAssistanceProgramMenu" class="nav-link-item nav-sub nav-sub-parent nav-program-tab" data-bs-toggle="collapse" role="button" aria-expanded="<?php echo $healthAssistExpanded ? 'true' : 'false'; ?>" aria-controls="healthAssistanceProgramMenu">
                        <i class="bi <?php echo e($healthTabs['assistance']['icon']); ?>"></i> <?php echo e($healthTabs['assistance']['label']); ?>
                        <i class="bi bi-chevron-down toggle-icon-sub"></i>
                    </a>
                    <div class="collapse<?php echo $healthAssistExpanded ? ' show' : ''; ?>" id="healthAssistanceProgramMenu">
                        <div class="submenu-nested program-sub">
                            <a href="<?php echo APP_BASE; ?>/admin/Health/HealthApplicants.php" class="nav-link-item nav-sub-sub<?php echo $isBaseProgramView ? navActive('HealthApplicants') : ''; ?>"><i class="bi bi-pencil-square"></i> Applications</a>

                            <a href="#healthAssistanceMenu" class="nav-link-item nav-sub-sub nav-sub-sub-parent" data-bs-toggle="collapse" role="button" aria-expanded="<?php echo $healthReqExpanded ? 'true' : 'false'; ?>" aria-controls="healthAssistanceMenu">
                                <i class="bi bi-cash-coin"></i> Assistance Requests
                                <i class="bi bi-chevron-down toggle-icon-sub-sub"></i>
                            </a>
                            <div class="collapse<?php echo $healthReqExpanded ? ' show' : ''; ?>" id="healthAssistanceMenu">
                                <div class="submenu-nested-2">
                                    <a href="<?php echo APP_BASE; ?>/admin/Health/HealthCashAssistance.php" class="nav-link-item nav-sub-sub-sub<?php echo $isBaseProgramView ? navActive('HealthCashAssistance') : ''; ?>"><i class="bi bi-cash"></i> Cash Assistance</a>
                                    <a href="<?php echo APP_BASE; ?>/admin/Health/HealthInKindAssistance.php" class="nav-link-item nav-sub-sub-sub<?php echo $isBaseProgramView ? navActive('HealthInKindAssistance') : ''; ?>"><i class="bi bi-box-seam"></i> In-Kind Assistance</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php renderExtraProgramTabLinks($healthCommitteeId, $healthTabs, $programSubmenuPages['health']); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- SPORTS COMMITTEE -->
        <?php
        $sportsExpanded = groupExpanded('Sports');
        $sportsAssistExpanded = $isBaseProgramView && groupExpanded(['SportsApplicants', 'SportsCashAssistance', 'SportsInkindAssistance']);
        $sportsReqExpanded = $isBaseProgramView && groupExpanded(['SportsCashAssistance', 'SportsInkindAssistance']);
        ?>
        <?php if (committeeAllowed($sportsCommitteeId) && !empty($sportsTabs['assistance']['is_visible'])): ?>
            <a href="#sportsMenu" class="nav-link-item nav-parent" data-bs-toggle="collapse" role="button" aria-expanded="<?php echo $sportsExpanded ? 'true' : 'false'; ?>" aria-controls="sportsMenu">
                <i class="bi bi-trophy-fill"></i> Sports Committee
                <i class="bi bi-chevron-down toggle-icon"></i>
            </a>
            <div class="collapse<?php echo $sportsExpanded ? ' show' : ''; ?>" id="sportsMenu">
                <div class="submenu">
                    <a href="#sportsAssistanceProgramMenu" class="nav-link-item nav-sub nav-sub-parent nav-program-tab" data-bs-toggle="collapse" role="button" aria-expanded="<?php echo $sportsAssistExpanded ? 'true' : 'false'; ?>" aria-controls="sportsAssistanceProgramMenu">
                        <i class="bi <?php echo e($sportsTabs['assistance']['icon']); ?>"></i> <?php echo e($sportsTabs['assistance']['label']); ?>
                        <i class="bi bi-chevron-down toggle-icon-sub"></i>
                    </a>
                    <div class="collapse<?php echo $sportsAssistExpanded ? ' show' : ''; ?>" id="sportsAssistanceProgramMenu">
                        <div class="submenu-nested program-sub">
                            <a href="<?php echo APP_BASE; ?>/admin/Sports/SportsApplicants.php" class="nav-link-item nav-sub-sub<?php echo $isBaseProgramView ? navActive('SportsApplicants') : ''; ?>"><i class="bi bi-pencil-square"></i> Applications</a>

                            <a href="#sportsAssistanceMenu" class="nav-link-item nav-sub-sub nav-sub-sub-parent" data-bs-toggle="collapse" role="button" aria-expanded="<?php echo $sportsReqExpanded ? 'true' : 'false'; ?>" aria-controls="sportsAssistanceMenu">
                                <i class="bi bi-cash-coin"></i> Assistance Requests
                                <i class="bi bi-chevron-down toggle-icon-sub-sub"></i>
                            </a>
                            <div class="collapse<?php echo $sportsReqExpanded ? ' show' : ''; ?>" id="sportsAssistanceMenu">
                                <div class="submenu-nested-2">
                                    <a href="<?php echo APP_BASE; ?>/admin/Sports/SportsCashAssistance.php" class="nav-link-item nav-sub-sub-sub<?php echo $isBaseProgramView ? navActive('SportsCashAssistance') : ''; ?>"><i class="bi bi-cash"></i> Cash Assistance</a>
                                    <a href="<?php echo APP_BASE; ?>/admin/Sports/SportsInkindAssistance.php" class="nav-link-item nav-sub-sub-sub<?php echo $isBaseProgramView ? navActive('SportsInkindAssistance') : ''; ?>"><i class="bi bi-box-seam"></i> In-Kind Assistance</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php renderExtraProgramTabLinks($sportsCommitteeId, $sportsTabs, $programSubmenuPages['sports']); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ACTIVE CITIZENSHIP -->
        <?php
        $citizenshipExpanded = groupExpanded('ActiveCitizenship');
        $citizenshipAssistExpanded = $isBaseProgramView && groupExpanded(['ActiveCitizenshipApplicants', 'ActiveCitizenshipCashAssistance', 'ActiveCitizenshipInKindAssistance']);
        $citizenshipReqExpanded = $isBaseProgramView && groupExpanded(['ActiveCitizenshipCashAssistance', 'ActiveCitizenshipInKindAssistance']);
        ?>
        <?php if (committeeAllowed($citizenshipCommitteeId) && !empty($citizenshipTabs['assistance']['is_visible'])): ?>
            <a href="#citizenshipMenu" class="nav-link-item nav-parent" data-bs-toggle="collapse" role="button" aria-expanded="<?php echo $citizenshipExpanded ? 'true' : 'false'; ?>" aria-controls="citizenshipMenu">
                <i class="bi bi-flag-fill"></i> Active Citizenship
                <i class="bi bi-chevron-down toggle-icon"></i>
            </a>
            <div class="collapse<?php echo $citizenshipExpanded ? ' show' : ''; ?>" id="citizenshipMenu">
                <div class="submenu">
                    <a href="#citizenshipAssistanceProgramMenu" class="nav-link-item nav-sub nav-sub-parent nav-program-tab" data-bs-toggle="collapse" role="button" aria-expanded="<?php echo $citizenshipAssistExpanded ? 'true' : 'false'; ?>" aria-controls="citizenshipAssistanceProgramMenu">
                        <i class="bi <?php echo e($citizenshipTabs['assistance']['icon']); ?>"></i> <?php echo e($citizenshipTabs['assistance']['label']); ?>
                        <i class="bi bi-chevron-down toggle-icon-sub"></i>
                    </a>
                    <div class="collapse<?php echo $citizenshipAssistExpanded ? ' show' : ''; ?>" id="citizenshipAssistanceProgramMenu">
                        <div class="submenu-nested program-sub">
                            <a href="<?php echo APP_BASE; ?>/admin/ActiveCitizenship/ActiveCitizenshipApplicants.php" class="nav-link-item nav-sub-sub<?php echo $isBaseProgramView ? navActive('ActiveCitizenshipApplicants') : ''; ?>"><i class="bi bi-pencil-square"></i> Applications</a>

                            <a href="#citizenshipAssistanceRequestsMenu" class="nav-link-item nav-sub-sub nav-sub-sub-parent" data-bs-toggle="collapse" role="button" aria-expanded="<?php echo $citizenshipReqExpanded ? 'true' : 'false'; ?>" aria-controls="citizenshipAssistanceRequestsMenu">
                                <i class="bi bi-cash-coin"></i> Assistance Requests
                                <i class="bi bi-chevron-down toggle-icon-sub-sub"></i>
                            </a>
                            <div class="collapse<?php echo $citizenshipReqExpanded ? ' show' : ''; ?>" id="citizenshipAssistanceRequestsMenu">
                                <div class="submenu-nested-2">
                                    <a href="<?php echo APP_BASE; ?>/admin/ActiveCitizenship/ActiveCitizenshipCashAssistance.php" class="nav-link-item nav-sub-sub-sub<?php echo $isBaseProgramView ? navActive('ActiveCitizenshipCashAssistance') : ''; ?>"><i class="bi bi-cash"></i> Cash Assistance</a>
                                    <a href="<?php echo APP_BASE; ?>/admin/ActiveCitizenship/ActiveCitizenshipInKindAssistance.php" class="nav-link-item nav-sub-sub-sub<?php echo $isBaseProgramView ? navActive('ActiveCitizenshipInKindAssistance') : ''; ?>"><i class="bi bi-box-seam"></i> In-Kind Assistance</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php renderExtraProgramTabLinks($citizenshipCommitteeId, $citizenshipTabs, $programSubmenuPages['active_citizenship']); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- EXTRA COMMITTEES (added via Content Management, beyond the 4 built-in ones above) -->
        <?php foreach ($extraCommittees as $ec):
            $ecId = (int)$ec['committee_id'];
            $ecTabs = getProgramTabs($ecId, true);
            if (empty($ecTabs)) continue;
            $ecMenuId = 'extraCommittee' . $ecId . 'Menu';
            $ecExpanded = strpos($activeLink, 'Committee') === 0 && preg_match('/_' . $ecId . '$/', $activeLink) === 1;
        ?>
            <a href="#<?php echo $ecMenuId; ?>" class="nav-link-item nav-parent" data-bs-toggle="collapse" role="button" aria-expanded="<?php echo $ecExpanded ? 'true' : 'false'; ?>" aria-controls="<?php echo $ecMenuId; ?>">
                <i class="bi <?php echo e($ec['icon'] ?: 'bi-people-fill'); ?>"></i> <?php echo e($ec['name']); ?>
                <i class="bi bi-chevron-down toggle-icon"></i>
            </a>
            <div class="collapse<?php echo $ecExpanded ? ' show' : ''; ?>" id="<?php echo $ecMenuId; ?>">
                <div class="submenu">
                    <?php renderExtraProgramTabLinks($ecId, $ecTabs, genericCommitteePages($ecId)); ?>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- REPORTS (a committee_admin gets a committee-scoped view — see AdminReports.php) -->
        <a href="<?php echo APP_BASE; ?>/admin/AdminReports.php" class="nav-link-item<?php echo navActive('AdminReports'); ?>"><i class="bi bi-graph-up-arrow"></i> Reports</a>
    </nav>

    <!-- Bottom section: Program Management + User Management + Configuration -->
    <div class="config-section">
        <?php if ($isFullAdmin): ?>
            <a href="<?php echo APP_BASE; ?>/admin/AdminProgramManagement.php" class="nav-link-item<?php echo navActive('AdminProgramManagement'); ?>"><i class="bi bi-diagram-3-fill"></i> Content Management</a>
        <?php endif; ?>
        <?php if ($isFullAdmin): ?>
            <a href="<?php echo APP_BASE; ?>/admin/AdminUserManagement.php" class="nav-link-item<?php echo navActive('AdminUserManagement'); ?>"><i class="bi bi-person-fill"></i> User Management</a>
            <a href="<?php echo APP_BASE; ?>/admin/AdminConfiguration.php" class="nav-link-item<?php echo navActive('AdminConfiguration'); ?>"><i class="bi bi-gear-fill"></i> Configuration</a>
        <?php endif; ?>
    </div>
</div>

<script>
    (function() {
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        menuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');
        });
        overlay.addEventListener('click', () => {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        });
    })();
</script>