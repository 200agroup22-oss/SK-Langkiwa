<?php
require_once __DIR__ . '/../../config/scholars.php';
requireRole('admin');

$term = getCurrentTerm();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_payout']) || isset($_POST['decline_payout'])) {
        $scholarId = (int)$_POST['scholar_id'];
        $newStatus = isset($_POST['approve_payout']) ? 'approved' : 'declined';
        ensureAllowanceRecord($scholarId);
        $stmt = $conn->prepare("UPDATE allowance_distributions SET status = ?, decided_at = NOW() WHERE scholar_id = ? AND academic_year = ? AND semester = ?");
        $stmt->bind_param('siss', $newStatus, $scholarId, $term['current_academic_year'], $term['current_semester']);
        $stmt->execute();
        $stmt->close();
        logAudit($newStatus === 'approved' ? 'Approved Allowance Payout' : 'Declined Allowance Payout', 'Scholar #' . $scholarId);
        setFlash('success', 'Allowance payout ' . $newStatus . '.');
    }
    header("Location: EducationAllowanceDistribution.php");
    exit();
}

$pageSuccess = getFlash('success');

$scholars = $conn->query("SELECT s.*, u.first_name, u.last_name
    FROM scholars s JOIN users u ON u.user_id = s.user_id
    WHERE s.status = 'active' ORDER BY s.scholar_id ASC")->fetch_all(MYSQLI_ASSOC);

foreach ($scholars as &$sch) {
    $sch['allowance'] = ensureAllowanceRecord($sch['scholar_id']);
}
unset($sch);

// Only scholars who have met the activity requirement (or were manually marked eligible on the
// Scholars page) belong in the payout queue — everyone else stays hidden until they qualify.
$scholars = array_values(array_filter($scholars, fn($sch) => $sch['allowance']['eligibility'] === 'eligible'));

$yearLevelLabel = fn($n) => $n ? $n . (['', 'st', 'nd', 'rd'][$n] ?? 'th') . ' Year' : '—';
$eligibilityBadge = fn($e) => $e === 'eligible' ? 'badge-eligible' : ($e === 'not_eligible' ? 'badge-not-eligible' : 'badge-pending-elig');
$eligibilityLabel = fn($e) => $e === 'eligible' ? 'Eligible' : ($e === 'not_eligible' ? 'Not Eligible' : 'Pending');

$activeLink = 'EducationAllowanceDistribution';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Allowance Distribution</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo APP_BASE; ?>/assets/css/admin.css" rel="stylesheet">
    <style>
        .badge-eligible {
            background-color: #d1e7dd;
            color: #0a3622;
            font-size: 12px;
            font-weight: 600;
            border-radius: 20px;
            padding: 4px 12px;
            border: 1px solid #a3cfbb;
        }

        .badge-not-eligible {
            background-color: #f8d7da;
            color: #842029;
            font-size: 12px;
            font-weight: 600;
            border-radius: 20px;
            padding: 4px 12px;
            border: 1px solid #f1aeb5;
        }

        .badge-pending-elig {
            background-color: #fff3cd;
            color: #856404;
            font-size: 12px;
            font-weight: 600;
            border-radius: 20px;
            padding: 4px 12px;
            border: 1px solid #ffe69c;
        }
    </style>
</head>

<body>

    <?php include __DIR__ . '/../../includes/adminsidebar.php'; ?>

    <div class="main-content">
        <h4 class="fw-bold mb-1">Allowance Distribution</h4>
        <p class="text-muted mb-2" style="font-size: 13px;">Approve allowance payouts for A.Y. <?php echo e($term['current_academic_year']); ?>, <?php echo e($term['current_semester']); ?>. Only scholars who have met the activity requirement are listed.</p>

        <?php if ($pageSuccess): ?><div class="alert alert-success py-2"><?php echo e($pageSuccess); ?></div><?php endif; ?>

        <div class="d-flex justify-content-end align-items-center mb-3 flex-wrap gap-2">
            <select class="form-select form-select-sm" id="allowanceFilterSelect" style="width:auto;">
                <option value="all">Filter: All Scholars</option>
                <option value="payout:pending">Payout: Pending</option>
                <option value="payout:approved">Payout: Released</option>
                <option value="payout:declined">Payout: Declined</option>
            </select>
            <select class="form-select form-select-sm" id="allowanceSortSelect" style="width:auto;">
                <option value="id_asc">Sort By: ID (Ascending)</option>
                <option value="id_desc">Sort By: ID (Descending)</option>
                <option value="name_asc">Sort By: Name (A-Z)</option>
                <option value="name_desc">Sort By: Name (Z-A)</option>
                <option value="year_asc">Sort By: Year Level (Low-High)</option>
                <option value="year_desc">Sort By: Year Level (High-Low)</option>
            </select>
            <div class="search-box position-relative">
                <i class="bi bi-search position-absolute" style="left:10px; top:50%; transform:translateY(-50%); color:#999; font-size:12px;"></i>
                <input type="text" class="form-control form-control-sm" id="allowanceSearchInput" placeholder="Search scholar..." style="padding-left:28px;">
            </div>
        </div>

        <div class="table-card">
            <div class="table-responsive-wrap">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>Scholar ID</th>
                            <th>Full Name</th>
                            <th>School</th>
                            <th>Course</th>
                            <th>Year Level</th>
                            <th>Activities</th>
                            <th>Eligibility</th>
                            <th>Payout Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="allowanceTableBody">
                        <?php if (empty($scholars)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">No eligible scholars yet.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($scholars as $sch): ?>
                            <tr class="allowance-row" data-scholar-id="<?php echo (int)$sch['scholar_id']; ?>" data-name="<?php echo e(strtolower($sch['first_name'] . ' ' . $sch['last_name'])); ?>" data-year-level="<?php echo (int)$sch['year_level']; ?>" data-eligibility="<?php echo e($sch['allowance']['eligibility']); ?>" data-payout="<?php echo e($sch['allowance']['status']); ?>">
                                <td><?php echo str_pad($sch['scholar_id'], 3, '0', STR_PAD_LEFT); ?></td>
                                <td><?php echo e($sch['first_name'] . ' ' . $sch['last_name']); ?></td>
                                <td><?php echo e($sch['school']); ?></td>
                                <td><?php echo e($sch['course']); ?></td>
                                <td><?php echo $yearLevelLabel($sch['year_level']); ?></td>
                                <td><?php echo $sch['allowance']['activities_completed']; ?> / <?php echo $sch['allowance']['activities_required']; ?></td>
                                <td><span class="<?php echo $eligibilityBadge($sch['allowance']['eligibility']); ?>"><?php echo $eligibilityLabel($sch['allowance']['eligibility']); ?></span></td>
                                <td><span class="badge text-bg-<?php echo $sch['allowance']['status'] === 'approved' ? 'success' : ($sch['allowance']['status'] === 'declined' ? 'danger' : 'secondary'); ?>"><?php echo ucfirst($sch['allowance']['status']); ?></span></td>
                                <td class="d-flex gap-1">
                                    <button class="btn-view" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $sch['scholar_id']; ?>"><i class="bi bi-eye"></i> View</button>
                                    <?php if ($sch['allowance']['status'] === 'pending'): ?>
                                        <button type="button" class="btn-approve" style="padding:4px 10px;font-size:12px;" data-bs-toggle="modal" data-bs-target="#approvePayoutModal<?php echo $sch['scholar_id']; ?>" <?php echo $sch['allowance']['eligibility'] !== 'eligible' ? 'disabled title="Not eligible yet"' : ''; ?>><i class="bi bi-check-circle-fill"></i> Released</button>
                                        <button type="button" class="btn-decline" style="padding:4px 10px;font-size:12px;" data-bs-toggle="modal" data-bs-target="#declinePayoutModal<?php echo $sch['scholar_id']; ?>"><i class="bi bi-x-circle"></i> Decline</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php foreach ($scholars as $sch): ?>
        <div class="modal fade" id="viewModal<?php echo $sch['scholar_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold"><i class="bi bi-cash-coin me-2"></i>Allowance Details</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="info-label">Scholar ID</div>
                                <div class="info-value"><?php echo str_pad($sch['scholar_id'], 3, '0', STR_PAD_LEFT); ?></div>
                            </div>
                            <div class="col-6">
                                <div class="info-label">Eligibility</div>
                                <div class="info-value"><span class="<?php echo $eligibilityBadge($sch['allowance']['eligibility']); ?>"><?php echo $eligibilityLabel($sch['allowance']['eligibility']); ?></span></div>
                            </div>
                            <div class="col-12">
                                <div class="info-label">Full Name</div>
                                <div class="info-value"><?php echo e($sch['first_name'] . ' ' . $sch['last_name']); ?></div>
                            </div>
                            <div class="col-12">
                                <div class="info-label">School</div>
                                <div class="info-value"><?php echo e($sch['school']); ?></div>
                            </div>
                            <div class="col-6">
                                <div class="info-label">Course</div>
                                <div class="info-value"><?php echo e($sch['course']); ?></div>
                            </div>
                            <div class="col-6">
                                <div class="info-label">Year Level</div>
                                <div class="info-value"><?php echo $yearLevelLabel($sch['year_level']); ?></div>
                            </div>
                            <div class="col-6">
                                <div class="info-label">Activities Completed</div>
                                <div class="info-value"><?php echo $sch['allowance']['activities_completed']; ?> / <?php echo $sch['allowance']['activities_required']; ?></div>
                            </div>
                            <div class="col-6">
                                <div class="info-label">Allowance Amount</div>
                                <div class="info-value fw-bold text-success">₱<?php echo number_format($sch['allowance']['amount'], 2); ?></div>
                            </div>
                            <div class="col-6">
                                <div class="info-label">Academic Year</div>
                                <div class="info-value"><?php echo e($term['current_academic_year']); ?></div>
                            </div>
                            <div class="col-6">
                                <div class="info-label">Semester</div>
                                <div class="info-value"><?php echo e($term['current_semester']); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- APPROVE PAYOUT CONFIRMATION MODAL -->
        <div class="modal fade" id="approvePayoutModal<?php echo $sch['scholar_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-sm">
                <div class="modal-content border-0 shadow">
                    <form method="post">
                        <input type="hidden" name="scholar_id" value="<?php echo $sch['scholar_id']; ?>">
                        <div class="modal-header">
                            <h6 class="modal-title fw-bold"><i class="bi bi-cash-coin me-2"></i>Release Allowance</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4 text-center">
                            <div style="width:64px;height:64px;border-radius:50%;background:#e8f5e9;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;">
                                <i class="bi bi-check-circle-fill" style="font-size:28px;color:#2e7d32;"></i>
                            </div>
                            <p class="fw-bold mb-1" style="font-size:14px;">Are you sure you want to approve this applicant?</p>
                            <p class="text-muted" style="font-size:12px; margin-bottom:0;">This will mark <strong><?php echo e($sch['first_name'] . ' ' . $sch['last_name']); ?></strong>'s allowance payout as released for A.Y. <?php echo e($term['current_academic_year']); ?>, <?php echo e($term['current_semester']); ?>.</p>
                        </div>
                        <div class="modal-footer border-0 justify-content-center gap-2">
                            <button type="button" class="btn btn-sm btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="approve_payout" class="btn btn-sm btn-success px-4"><i class="bi bi-check-lg me-1"></i>Yes, Release</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- DECLINE PAYOUT CONFIRMATION MODAL -->
        <div class="modal fade" id="declinePayoutModal<?php echo $sch['scholar_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-sm">
                <div class="modal-content border-0 shadow">
                    <form method="post">
                        <input type="hidden" name="scholar_id" value="<?php echo $sch['scholar_id']; ?>">
                        <div class="modal-header">
                            <h6 class="modal-title fw-bold"><i class="bi bi-x-circle-fill me-2"></i>Decline Payout</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4 text-center">
                            <div class="archive-icon-wrap"><i class="bi bi-x-circle-fill"></i></div>
                            <p class="fw-bold mb-1" style="font-size:14px;">Are you sure you want to decline this applicant?</p>
                            <p class="text-muted" style="font-size:12px; margin-bottom:0;">This will mark <strong><?php echo e($sch['first_name'] . ' ' . $sch['last_name']); ?></strong>'s allowance payout as declined for A.Y. <?php echo e($term['current_academic_year']); ?>, <?php echo e($term['current_semester']); ?>.</p>
                        </div>
                        <div class="modal-footer border-0 justify-content-center gap-2">
                            <button type="button" class="btn btn-sm btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="decline_payout" class="btn btn-sm btn-danger px-4"><i class="bi bi-x-lg me-1"></i>Yes, Decline</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Client-side search / filter / sort (Allowance Distribution table)
        const allowanceSearchInput = document.getElementById('allowanceSearchInput');
        const allowanceFilterSelect = document.getElementById('allowanceFilterSelect');
        const allowanceSortSelect = document.getElementById('allowanceSortSelect');
        const allowanceTableBody = document.getElementById('allowanceTableBody');

        function applyAllowanceFilters() {
            if (!allowanceTableBody) return;
            const q = allowanceSearchInput ? allowanceSearchInput.value.trim().toLowerCase() : '';
            const filterVal = allowanceFilterSelect ? allowanceFilterSelect.value : 'all';
            const sortVal = allowanceSortSelect ? allowanceSortSelect.value : 'id_asc';
            const [filterKey, filterArg] = filterVal.includes(':') ? filterVal.split(':') : [null, null];

            const rows = Array.from(allowanceTableBody.querySelectorAll('.allowance-row'));
            if (!rows.length) return;

            rows.forEach(function(row) {
                const matchesSearch = !q || (row.dataset.name || '').includes(q);
                const matchesFilter = !filterKey ||
                    (filterKey === 'elig' && (row.dataset.eligibility || '') === filterArg) ||
                    (filterKey === 'payout' && (row.dataset.payout || '') === filterArg);
                row.style.display = (matchesSearch && matchesFilter) ? '' : 'none';
            });

            rows.sort(function(a, b) {
                switch (sortVal) {
                    case 'id_desc':
                        return (+b.dataset.scholarId) - (+a.dataset.scholarId);
                    case 'name_asc':
                        return (a.dataset.name || '').localeCompare(b.dataset.name || '');
                    case 'name_desc':
                        return (b.dataset.name || '').localeCompare(a.dataset.name || '');
                    case 'year_asc':
                        return (+a.dataset.yearLevel) - (+b.dataset.yearLevel);
                    case 'year_desc':
                        return (+b.dataset.yearLevel) - (+a.dataset.yearLevel);
                    case 'id_asc':
                    default:
                        return (+a.dataset.scholarId) - (+b.dataset.scholarId);
                }
            });
            rows.forEach(row => allowanceTableBody.appendChild(row));
        }

        if (allowanceSearchInput) allowanceSearchInput.addEventListener('input', applyAllowanceFilters);
        if (allowanceFilterSelect) allowanceFilterSelect.addEventListener('change', applyAllowanceFilters);
        if (allowanceSortSelect) allowanceSortSelect.addEventListener('change', applyAllowanceFilters);
        applyAllowanceFilters();
    </script>
</body>

</html>