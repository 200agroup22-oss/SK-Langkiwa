<?php
// Shared "SK Committees" sidebar for the applicant-facing application form pages.
// Committees and tabs are both read live from the database, so an admin adding a committee via
// Content Management > Add Committee (or adding/renaming/hiding a program via Manage Tabs) shows
// up here automatically — no $activeLink bookkeeping needed, the active state is matched from the
// current URL (path + query, since program-specific tabs share their committee's base form page
// and are only told apart by a ?program_id= query param).
//
// This file only DEFINES things (functions + a one-time <style> block) — call
// renderApplicantCommitteeSidebar() wherever the list should actually appear. It's rendered twice
// per page (a desktop-only left column, and a mobile-only collapsible menu near the top), so each
// call takes an $idPrefix to keep their collapse element IDs from colliding.
require_once __DIR__ . '/../config/forms.php';
?>
<style>
    .sidebar .nav-parent-toggle {
        display: flex;
        align-items: center;
    }

    .sidebar .nav-sub-link {
        font-size: 0.82rem;
        padding: 0.45rem 1rem 0.45rem 1.6rem;
    }

    /* Same look as the desktop .sidebar column, minus the full-height/border-right layout rules
       that only make sense for a permanent side column — used when this is rendered a second time
       inside the mobile "Switch Committee / Program" collapse instead. */
    .sidebar-mobile {
        min-height: auto !important;
        border-right: none !important;
        padding-top: 0 !important;
    }
</style>
<?php
if (!function_exists('navLinkIsActive')) {
    // Two links only count as "the same page" when both their path AND their program_id query
    // param (if any) match — otherwise every program-specific tab under a committee (which all
    // point at the same base form page, differing only by ?program_id=) would light up together.
    function navLinkIsActive($url, $currentPath, $currentQuery)
    {
        if (rtrim((string)parse_url($url, PHP_URL_PATH), '/') !== $currentPath) {
            return false;
        }
        parse_str((string)parse_url($url, PHP_URL_QUERY), $urlQuery);
        $urlProgramId = $urlQuery['program_id'] ?? null;
        $currentProgramId = $currentQuery['program_id'] ?? null;
        return (string)$urlProgramId === (string)$currentProgramId;
    }
}

if (!function_exists('renderApplicantCommitteeSidebar')) {
    function renderApplicantCommitteeSidebar($idPrefix = '')
    {
        global $conn;

        $currentPath = rtrim((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        parse_str((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY), $currentQuery);

        $committees = $conn->query("SELECT * FROM committees WHERE archived_at IS NULL ORDER BY committee_id ASC")->fetch_all(MYSQLI_ASSOC);

        $committeeLinks = [];
        foreach ($committees as $c) {
            $cid = (int)$c['committee_id'];
            $links = [];
            foreach (getProgramTabs($cid, true) as $tab) {
                $url = programTabApplicantUrl($cid, $tab);
                if ($url) {
                    $links[] = ['label' => $tab['label'], 'icon' => $tab['icon'], 'url' => $url];
                }
            }
            if (!empty($links)) {
                $committeeLinks[] = [
                    'def' => ['label' => $c['name'], 'icon' => $c['icon'] ?: 'bi-people-fill', 'menuId' => $idPrefix . 'committee' . $cid . 'FormsMenu'],
                    'links' => $links,
                ];
            }
        }
?>
        <div class="section-label">SK COMMITTEES</div>
        <ul class="nav flex-column">
            <?php foreach ($committeeLinks as $group): $cd = $group['def'];
                $links = $group['links']; ?>
                <?php if (count($links) === 1): ?>
                    <li class="nav-item">
                        <a class="nav-link<?php echo navLinkIsActive($links[0]['url'], $currentPath, $currentQuery) ? ' active' : ''; ?>" href="<?php echo e($links[0]['url']); ?>"><i class="bi <?php echo e($cd['icon']); ?>"></i><?php echo e($cd['label']); ?></a>
                    </li>
                <?php else: ?>
                    <?php $expanded = false;
                    foreach ($links as $l) {
                        if (navLinkIsActive($l['url'], $currentPath, $currentQuery)) {
                            $expanded = true;
                        }
                    } ?>
                    <li class="nav-item">
                        <a class="nav-link nav-parent-toggle<?php echo $expanded ? ' active' : ''; ?>" href="#<?php echo e($cd['menuId']); ?>" data-bs-toggle="collapse" role="button" aria-expanded="<?php echo $expanded ? 'true' : 'false'; ?>">
                            <i class="bi <?php echo e($cd['icon']); ?>"></i><?php echo e($cd['label']); ?>
                            <i class="bi bi-chevron-down ms-auto" style="font-size:11px;"></i>
                        </a>
                        <div class="collapse<?php echo $expanded ? ' show' : ''; ?>" id="<?php echo e($cd['menuId']); ?>">
                            <ul class="nav flex-column">
                                <?php foreach ($links as $l): ?>
                                    <li class="nav-item">
                                        <a class="nav-link nav-sub-link<?php echo navLinkIsActive($l['url'], $currentPath, $currentQuery) ? ' active' : ''; ?>" href="<?php echo e($l['url']); ?>"><i class="bi <?php echo e($l['icon']); ?>"></i><?php echo e($l['label']); ?></a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
<?php
    }
}
