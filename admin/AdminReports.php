<?php
require_once __DIR__ . '/../config/functions.php';
requireRole('admin');

$me = currentUser();

$committees = $conn->query("SELECT * FROM committees ORDER BY committee_id ASC")->fetch_all(MYSQLI_ASSOC);

// Built-in tracks (iSKolar ng Langkiwa / Assistance Program) plus active, non-archived catalog
// programs, grouped by committee, for the Generate Report modal's Program filter (populated
// client-side once a specific committee is chosen). A built-in track's option value is
// "track:<track_code>" instead of a numeric program_id, since it has no row of its own in the
// programs table — GenerateReport.php tells the two apart by that prefix.
$programsByCommitteeCode = [];
foreach ($committees as $c) {
    $programsByCommitteeCode[$c['code']] = [];
}
$builtInTracksResult = $conn->query("SELECT pt.track_code, pt.label, c.code AS committee_code FROM program_tabs pt JOIN committees c ON c.committee_id = pt.committee_id WHERE pt.program_id IS NULL AND pt.is_visible = 1 ORDER BY pt.sort_order ASC");
foreach ($builtInTracksResult as $t) {
    $programsByCommitteeCode[$t['committee_code']][] = ['id' => 'track:' . $t['track_code'], 'name' => $t['label'] . ' (Built-in Track)'];
}
$allProgramsResult = $conn->query("SELECT p.program_id, p.name, c.code AS committee_code FROM programs p JOIN committees c ON c.committee_id = p.committee_id WHERE p.archived_at IS NULL AND p.status = 'active' ORDER BY p.name ASC");
foreach ($allProgramsResult as $p) {
    $programsByCommitteeCode[$p['committee_code']][] = ['id' => (string)(int)$p['program_id'], 'name' => $p['name']];
}

// ---- Period + committee filters (GET, drive the whole dashboard view) ----
$periodMode = ($_GET['period'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';
$committeeCode = $_GET['committee'] ?? 'all';

$committeeId = 0; // 0 = "all committees" sentinel used in the (? = 0 OR x = ?) WHERE pattern below
if ($committeeCode !== 'all') {
    $stmt = $conn->prepare("SELECT committee_id FROM committees WHERE code = ?");
    $stmt->bind_param('s', $committeeCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $committeeId = $row ? (int)$row['committee_id'] : -1; // -1 = no such committee, matches nothing
}

$defaultPeriodValue = $periodMode === 'monthly' ? date('Y-m') : date('Y');
$periodValue = $_GET['period_value'] ?? $defaultPeriodValue;
if ($periodMode === 'monthly' && !preg_match('/^\d{4}-\d{2}$/', $periodValue)) {
    $periodValue = $defaultPeriodValue;
}
if ($periodMode === 'yearly' && !preg_match('/^\d{4}$/', $periodValue)) {
    $periodValue = $defaultPeriodValue;
}

if ($periodMode === 'monthly') {
    $periodStart = date('Y-m-01', strtotime($periodValue . '-01'));
    $periodEnd = date('Y-m-t', strtotime($periodValue . '-01'));
} else {
    $periodStart = $periodValue . '-01-01';
    $periodEnd = $periodValue . '-12-31';
}

// ---- Dynamic period select options ----
$monthlyOptions = [];
for ($i = 0; $i < 12; $i++) {
    $m = date('Y-m', strtotime("-$i months"));
    $monthlyOptions[$m] = date('F Y', strtotime($m . '-01'));
}

$yearRange = $conn->query("SELECT MIN(YEAR(submitted_at)) miny, MAX(YEAR(submitted_at)) maxy FROM applications")->fetch_assoc();
$minYear = $yearRange['miny'] ? (int)$yearRange['miny'] : (int)date('Y');
$maxYear = $yearRange['maxy'] ? (int)$yearRange['maxy'] : (int)date('Y');
$maxYear = max($maxYear, (int)date('Y'));
$yearlyOptions = [];
for ($y = $maxYear; $y >= $minYear; $y--) {
    $yearlyOptions[(string)$y] = (string)$y;
}

// ---- Stat cards ----
$stmt = $conn->prepare("SELECT COUNT(*) c FROM applications a WHERE a.status = 'approved' AND a.archived_at IS NULL AND a.submitted_at BETWEEN ? AND ? AND (? = 0 OR a.committee_id = ?)");
$stmt->bind_param('ssii', $periodStart, $periodEnd, $committeeId, $committeeId);
$stmt->execute();
$totalBeneficiaries = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) c FROM applications a WHERE a.submitted_at BETWEEN ? AND ? AND (? = 0 OR a.committee_id = ?)");
$stmt->bind_param('ssii', $periodStart, $periodEnd, $committeeId, $committeeId);
$stmt->execute();
$applicationsSubmitted = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COALESCE(SUM(b.quantity), 0) q FROM assistance_beneficiaries b JOIN applications a ON a.application_id = b.application_id WHERE b.type = 'in_kind' AND a.submitted_at BETWEEN ? AND ? AND (? = 0 OR a.committee_id = ?)");
$stmt->bind_param('ssii', $periodStart, $periodEnd, $committeeId, $committeeId);
$stmt->execute();
$inKindItemsDistributed = (int)($stmt->get_result()->fetch_assoc()['q'] ?? 0);
$stmt->close();

// ---- Chart: Beneficiaries per Committee (approved applications, respects committee + period filter) ----
$stmt = $conn->prepare("SELECT c.name, COUNT(a.application_id) cnt FROM committees c
    LEFT JOIN applications a ON a.committee_id = c.committee_id AND a.status = 'approved' AND a.archived_at IS NULL AND a.submitted_at BETWEEN ? AND ?
    WHERE (? = 0 OR c.committee_id = ?)
    GROUP BY c.committee_id, c.name ORDER BY c.committee_id ASC");
$stmt->bind_param('ssii', $periodStart, $periodEnd, $committeeId, $committeeId);
$stmt->execute();
$beneficiariesPerCommittee = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ---- Chart: Application Status Breakdown ----
$statusCounts = ['pending' => 0, 'approved' => 0, 'declined' => 0];
$stmt = $conn->prepare("SELECT status, COUNT(*) c FROM applications a WHERE a.archived_at IS NULL AND a.submitted_at BETWEEN ? AND ? AND (? = 0 OR a.committee_id = ?) GROUP BY status");
$stmt->bind_param('ssii', $periodStart, $periodEnd, $committeeId, $committeeId);
$stmt->execute();
foreach ($stmt->get_result() as $row) {
    $statusCounts[$row['status']] = (int)$row['c'];
}
$stmt->close();

// ---- Chart: Funds Released per Month (last 6 months ending at selected month) or per Year (last 6 years ending at selected year) ----
$fundsLabels = [];
$fundsData = [];
if ($periodMode === 'monthly') {
    for ($i = 5; $i >= 0; $i--) {
        $bucket = date('Y-m', strtotime($periodValue . "-01 -$i months"));
        $bucketStart = $bucket . '-01';
        $bucketEnd = date('Y-m-t', strtotime($bucketStart));
        $stmt = $conn->prepare("SELECT COALESCE(SUM(b.amount), 0) s FROM assistance_beneficiaries b JOIN applications a ON a.application_id = b.application_id WHERE b.type = 'cash' AND a.submitted_at BETWEEN ? AND ? AND (? = 0 OR a.committee_id = ?)");
        $stmt->bind_param('ssii', $bucketStart, $bucketEnd, $committeeId, $committeeId);
        $stmt->execute();
        $sum = (float)($stmt->get_result()->fetch_assoc()['s'] ?? 0);
        $stmt->close();
        $fundsLabels[] = date('M', strtotime($bucketStart));
        $fundsData[] = $sum;
    }
    $fundsChartTitle = 'Funds Released per Month';
} else {
    $endYear = (int)$periodValue;
    for ($i = 5; $i >= 0; $i--) {
        $y = $endYear - $i;
        $bucketStart = $y . '-01-01';
        $bucketEnd = $y . '-12-31';
        $stmt = $conn->prepare("SELECT COALESCE(SUM(b.amount), 0) s FROM assistance_beneficiaries b JOIN applications a ON a.application_id = b.application_id WHERE b.type = 'cash' AND a.submitted_at BETWEEN ? AND ? AND (? = 0 OR a.committee_id = ?)");
        $stmt->bind_param('ssii', $bucketStart, $bucketEnd, $committeeId, $committeeId);
        $stmt->execute();
        $sum = (float)($stmt->get_result()->fetch_assoc()['s'] ?? 0);
        $stmt->close();
        $fundsLabels[] = (string)$y;
        $fundsData[] = $sum;
    }
    $fundsChartTitle = 'Funds Released per Year';
}

// ---- Chart: In-Kind Assistance Distributed (top items by quantity) ----
$stmt = $conn->prepare("SELECT b.items, COALESCE(SUM(b.quantity), 0) qty FROM assistance_beneficiaries b JOIN applications a ON a.application_id = b.application_id WHERE b.type = 'in_kind' AND b.items IS NOT NULL AND b.items != '' AND a.submitted_at BETWEEN ? AND ? AND (? = 0 OR a.committee_id = ?) GROUP BY b.items ORDER BY qty DESC LIMIT 8");
$stmt->bind_param('ssii', $periodStart, $periodEnd, $committeeId, $committeeId);
$stmt->execute();
$inKindBreakdown = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ---- Consolidated Committee Report table (always all real committees, period-filtered only) ----
$consolidatedRows = [];
foreach ($committees as $c) {
    $cid = (int)$c['committee_id'];

    $stmt = $conn->prepare("SELECT COUNT(*) c FROM programs WHERE committee_id = ? AND archived_at IS NULL");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $totalPrograms = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) c FROM applications WHERE committee_id = ? AND status = 'approved' AND archived_at IS NULL AND submitted_at BETWEEN ? AND ?");
    $stmt->bind_param('iss', $cid, $periodStart, $periodEnd);
    $stmt->execute();
    $benCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    $stmt = $conn->prepare("SELECT COALESCE(SUM(b.amount), 0) s FROM assistance_beneficiaries b JOIN applications a ON a.application_id = b.application_id WHERE a.committee_id = ? AND b.type = 'cash' AND a.submitted_at BETWEEN ? AND ?");
    $stmt->bind_param('iss', $cid, $periodStart, $periodEnd);
    $stmt->execute();
    $fundsReleased = (float)($stmt->get_result()->fetch_assoc()['s'] ?? 0);
    $stmt->close();

    $stmt = $conn->prepare("SELECT b.items, SUM(b.quantity) qty FROM assistance_beneficiaries b JOIN applications a ON a.application_id = b.application_id WHERE a.committee_id = ? AND b.type = 'in_kind' AND a.submitted_at BETWEEN ? AND ? GROUP BY b.items ORDER BY qty DESC LIMIT 3");
    $stmt->bind_param('iss', $cid, $periodStart, $periodEnd);
    $stmt->execute();
    $itemRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $inKindSummary = implode(', ', array_map(fn($r) => $r['items'] . ' (' . (int)$r['qty'] . ')', $itemRows));

    $stmt = $conn->prepare("SELECT status, COUNT(*) c FROM applications WHERE committee_id = ? AND archived_at IS NULL AND submitted_at BETWEEN ? AND ? GROUP BY status");
    $stmt->bind_param('iss', $cid, $periodStart, $periodEnd);
    $stmt->execute();
    $decided = ['approved' => 0, 'declined' => 0];
    foreach ($stmt->get_result() as $row) {
        if (isset($decided[$row['status']])) $decided[$row['status']] = (int)$row['c'];
    }
    $stmt->close();
    $decidedTotal = $decided['approved'] + $decided['declined'];
    $approvalRate = $decidedTotal > 0 ? round(($decided['approved'] / $decidedTotal) * 100) : null;

    $consolidatedRows[] = [
        'name' => $c['name'],
        'programs' => $totalPrograms,
        'beneficiaries' => $benCount,
        'funds' => $fundsReleased,
        'inkind' => $inKindSummary !== '' ? $inKindSummary : '—',
        'approval' => $approvalRate,
    ];
}

// ---- Activity Logs tab: filter (role, date range) + search, paginated 10 rows at a time ----
$logPerPage = 10;
$roleFilter = $_GET['role'] ?? '';
$dateFilter = $_GET['logdate'] ?? '';
$logSearch = trim($_GET['logq'] ?? '');
$logPage = max(1, (int)($_GET['logpage'] ?? 1));

$distinctRoles = array_column($conn->query("SELECT DISTINCT role FROM activity_logs ORDER BY role ASC")->fetch_all(MYSQLI_ASSOC), 'role');

$logWhere = [];
if ($roleFilter !== '') {
    $logWhere[] = "role = '" . $conn->real_escape_string($roleFilter) . "'";
}
if ($dateFilter === 'today') {
    $logWhere[] = "DATE(logged_in_at) = CURDATE()";
} elseif ($dateFilter === 'week') {
    $logWhere[] = "YEARWEEK(logged_in_at, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($dateFilter === 'month') {
    $logWhere[] = "DATE_FORMAT(logged_in_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
}
if ($logSearch !== '') {
    $needle = $conn->real_escape_string($logSearch);
    $logWhere[] = "(full_name LIKE '%$needle%' OR email LIKE '%$needle%')";
}
$logWhereSql = $logWhere ? ('WHERE ' . implode(' AND ', $logWhere)) : '';

$totalActivityLogs = (int)$conn->query("SELECT COUNT(*) c FROM activity_logs $logWhereSql")->fetch_assoc()['c'];
$totalActivityPages = max(1, (int)ceil($totalActivityLogs / $logPerPage));
$logPage = min($logPage, $totalActivityPages);
$logOffset = ($logPage - 1) * $logPerPage;

$activityLogs = $conn->query("SELECT * FROM activity_logs $logWhereSql ORDER BY logged_in_at DESC LIMIT $logPerPage OFFSET $logOffset")->fetch_all(MYSQLI_ASSOC);

// ---- Audit Logs tab: filter (action, date range) + search, paginated 10 rows at a time ----
$auditPerPage = 10;
$actionFilter = $_GET['action'] ?? '';
$auditDateFilter = $_GET['auditdate'] ?? '';
$auditSearch = trim($_GET['auditq'] ?? '');
$auditPage = max(1, (int)($_GET['auditpage'] ?? 1));

$distinctActions = array_column($conn->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC")->fetch_all(MYSQLI_ASSOC), 'action');

$auditWhere = [];
if ($actionFilter !== '') {
    $auditWhere[] = "action = '" . $conn->real_escape_string($actionFilter) . "'";
}
if ($auditDateFilter === 'today') {
    $auditWhere[] = "DATE(created_at) = CURDATE()";
} elseif ($auditDateFilter === 'week') {
    $auditWhere[] = "YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($auditDateFilter === 'month') {
    $auditWhere[] = "DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
}
if ($auditSearch !== '') {
    $needle = $conn->real_escape_string($auditSearch);
    $auditWhere[] = "(full_name LIKE '%$needle%' OR email LIKE '%$needle%' OR action LIKE '%$needle%')";
}
$auditWhereSql = $auditWhere ? ('WHERE ' . implode(' AND ', $auditWhere)) : '';

$totalAuditLogs = (int)$conn->query("SELECT COUNT(*) c FROM audit_logs $auditWhereSql")->fetch_assoc()['c'];
$totalAuditPages = max(1, (int)ceil($totalAuditLogs / $auditPerPage));
$auditPage = min($auditPage, $totalAuditPages);
$auditOffset = ($auditPage - 1) * $auditPerPage;

$auditLogs = $conn->query("SELECT * FROM audit_logs $auditWhereSql ORDER BY created_at DESC LIMIT $auditPerPage OFFSET $auditOffset")->fetch_all(MYSQLI_ASSOC);

$activityPaginationParams = ['tab' => 'activityLogsTab', 'role' => $roleFilter, 'logdate' => $dateFilter, 'logq' => $logSearch];
$auditPaginationParams = ['tab' => 'auditLogsTab', 'action' => $actionFilter, 'auditdate' => $auditDateFilter, 'auditq' => $auditSearch];

// Windowed page-number list for pagination controls (e.g. [1,2,3,4,5,'...',57]) — shows every
// page when there are few, otherwise a block around the current page plus the first and last.
function paginationPageList($current, $total, $window = 2)
{
    if ($total <= 7) {
        return range(1, $total);
    }
    $pages = [1];
    $start = max(2, $current - $window);
    $end = min($total - 1, $current + $window);
    if ($current <= $window + 2) {
        $start = 2;
        $end = min($total - 1, 2 * $window + 1);
    }
    if ($current >= $total - $window - 1) {
        $end = $total - 1;
        $start = max(2, $total - (2 * $window + 1));
    }
    if ($start > 2) {
        $pages[] = '...';
    }
    for ($i = $start; $i <= $end; $i++) {
        $pages[] = $i;
    }
    if ($end < $total - 1) {
        $pages[] = '...';
    }
    $pages[] = $total;
    return $pages;
}

// Renders a "Showing X to Y of Z entries" line + Bootstrap pagination bar for a log table.
function renderLogPagination($current, $total, $totalRows, $perPage, $pageParam, array $extraParams)
{
    $from = $totalRows === 0 ? 0 : ($current - 1) * $perPage + 1;
    $to = min($current * $perPage, $totalRows);
    $urlFor = function ($page) use ($pageParam, $extraParams) {
        return '?' . http_build_query(array_merge($extraParams, [$pageParam => $page]));
    };
?>
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
        <span style="font-size:12px; color:#888;">Showing <?php echo $from; ?> to <?php echo $to; ?> of <?php echo $totalRows; ?> entries</span>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?php echo $current <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo $current > 1 ? e($urlFor($current - 1)) : '#'; ?>">Previous</a>
                </li>
                <?php foreach (paginationPageList($current, $total) as $p): ?>
                    <?php if ($p === '...'): ?>
                        <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                    <?php else: ?>
                        <li class="page-item <?php echo $p === $current ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo e($urlFor($p)); ?>"><?php echo $p; ?></a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
                <li class="page-item <?php echo $current >= $total ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo $current < $total ? e($urlFor($current + 1)) : '#'; ?>">Next</a>
                </li>
            </ul>
        </nav>
    </div>
<?php
}

$activeLink = 'AdminReports';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo APP_BASE; ?>/assets/css/admin.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .page-title {
            font-size: 22px;
            font-weight: 700;
            color: #1a1a1a;
            margin-bottom: 2px;
        }

        .page-subtitle {
            font-size: 13px;
            color: #6b6b6b;
        }

        .content-tabs {
            display: flex;
            gap: 6px;
            border-bottom: 2px solid #e0e0e0;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .content-tab-btn {
            background: none;
            border: none;
            font-size: 13.5px;
            font-weight: 600;
            color: #666;
            padding: 10px 16px;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
        }

        .content-tab-btn.active {
            color: #2e7d32;
            border-bottom-color: #45b84d;
        }

        .content-tab-btn:hover {
            color: #2e7d32;
        }

        .tab-pane-custom {
            display: none;
        }

        .tab-pane-custom.active {
            display: block;
        }

        .pagination .page-link {
            color: #2e7d32;
            font-size: 12.5px;
        }

        .pagination .page-item.active .page-link {
            background-color: #45b84d;
            border-color: #45b84d;
        }

        .pagination .page-link:focus {
            box-shadow: 0 0 0 2px rgba(69, 184, 77, 0.15);
        }

        .period-toggle {
            display: inline-flex;
            background: #fff;
            border: 1.5px solid #45b84d;
            border-radius: 8px;
            overflow: hidden;
        }

        .period-toggle button {
            border: none;
            background: #fff;
            color: #2e7d32;
            font-size: 13px;
            font-weight: 600;
            padding: 7px 18px;
            cursor: pointer;
        }

        .period-toggle button.active {
            background: #45b84d;
            color: #fff;
        }

        .filter-select {
            border: 1px solid #ccc;
            border-radius: 6px;
            padding: 6px 12px;
            font-size: 12.5px;
            color: #444;
            background: #fff;
        }

        .filter-select:focus {
            outline: none;
            border-color: #45b84d;
            box-shadow: 0 0 0 2px rgba(69, 184, 77, 0.15);
        }

        .btn-brand {
            background-color: #45b84d;
            color: #fff;
            font-weight: 600;
            font-size: 13.5px;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
        }

        .btn-brand:hover {
            background-color: #3ca644;
            color: #fff;
        }

        .btn-outline-brand {
            background: #fff;
            color: #2e7d32;
            border: 1.5px solid #45b84d;
            font-weight: 600;
            font-size: 13.5px;
            padding: 8px 16px;
            border-radius: 6px;
        }

        .btn-outline-brand:hover {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .stat-card {
            border: 2px solid #45b84d;
            border-radius: 10px;
            padding: 14px 18px;
            background: #fff;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .stat-card .label {
            font-size: 11px;
            font-weight: 700;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card .value {
            font-size: 24px;
            font-weight: 700;
            color: #1a1a1a;
            line-height: 1.2;
        }

        .chart-card {
            background: #fff;
            border: 1px solid #c8e6c9;
            border-radius: 10px;
            padding: 18px;
            height: 100%;
        }

        .chart-title {
            font-size: 12px;
            font-weight: 700;
            color: #333;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }

        .section-label {
            font-size: 12px;
            font-weight: 700;
            color: #45b84d;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin: 22px 0 10px;
        }

        .table-card {
            background: #fff;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid #e0e0e0;
        }

        .table-responsive-wrap {
            width: 100%;
            overflow-x: auto;
        }

        table.report-table {
            width: 100%;
            margin-bottom: 0;
            min-width: 900px;
        }

        table.report-table thead th {
            background-color: #a9d8ab;
            color: #1a3d1c;
            font-size: 12.5px;
            font-weight: 700;
            padding: 12px 16px;
            border: none;
            white-space: nowrap;
        }

        table.report-table tbody td {
            padding: 11px 16px;
            font-size: 13px;
            color: #2b2b2b;
            vertical-align: middle;
            border-bottom: 1px solid #eee;
        }

        table.report-table tbody tr:last-child td {
            border-bottom: none;
        }

        .committee-tag {
            font-size: 12px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 20px;
            background: #eef4ff;
            color: #3b5bdb;
            border: 1px solid #d0dcf7;
            white-space: nowrap;
        }

        .modal-header.brand-header {
            background: linear-gradient(90deg, #45b84d, #aadaad);
            color: #fff;
            border-bottom: none;
        }

        .modal-header.brand-header .btn-close {
            filter: brightness(0) invert(1);
        }

        .modal .form-label {
            font-size: 13px;
            font-weight: 600;
            color: #333;
            margin-bottom: 4px;
        }

        .modal .form-select,
        .modal .form-control {
            font-size: 13.5px;
            border: 1px solid #ccc;
            border-radius: 6px;
        }

        .modal .form-select:focus,
        .modal .form-control:focus {
            border-color: #45b84d;
            box-shadow: 0 0 0 2px rgba(69, 184, 77, 0.15);
        }

        @media (max-width: 576px) {
            .page-title {
                font-size: 19px;
            }

            .stat-card .value {
                font-size: 20px;
            }
        }
    </style>
</head>

<body>

    <?php include __DIR__ . '/../includes/adminsidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">

        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
            <div>
                <div class="page-title">Reports</div>
                <div class="page-subtitle">Consolidated analytics and summaries across all SK committees.</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="content-tabs">
            <button class="content-tab-btn active" data-tab="overviewTab"><i class="bi bi-bar-chart-fill"></i> Overview</button>
            <button class="content-tab-btn" data-tab="activityLogsTab"><i class="bi bi-activity"></i> Activity Logs</button>
            <button class="content-tab-btn" data-tab="auditLogsTab"><i class="bi bi-journal-text"></i> Audit Logs</button>
        </div>

        <div class="tab-pane-custom active" id="overviewTab">

            <!-- Period toggle + filters -->
            <form method="get" id="filterForm">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <input type="hidden" name="period" id="periodModeInput" value="<?php echo e($periodMode); ?>">
                        <div class="period-toggle">
                            <button type="button" class="<?php echo $periodMode === 'monthly' ? 'active' : ''; ?>" id="btnMonthly">Monthly</button>
                            <button type="button" class="<?php echo $periodMode === 'yearly' ? 'active' : ''; ?>" id="btnYearly">Yearly</button>
                        </div>
                        <select class="filter-select" name="period_value" id="periodSelect" onchange="this.form.submit()">
                            <?php $options = $periodMode === 'monthly' ? $monthlyOptions : $yearlyOptions; ?>
                            <?php foreach ($options as $val => $label): ?>
                                <option value="<?php echo e($val); ?>" <?php echo $val === $periodValue ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="filter-select" name="committee" onchange="this.form.submit()">
                            <option value="all" <?php echo $committeeCode === 'all' ? 'selected' : ''; ?>>All Committees</option>
                            <?php foreach ($committees as $c): ?>
                                <option value="<?php echo e($c['code']); ?>" <?php echo $committeeCode === $c['code'] ? 'selected' : ''; ?>><?php echo e($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn-brand" data-bs-toggle="modal" data-bs-target="#generateReportModal">
                            <i class="bi bi-download me-1"></i> Generate Report
                        </button>
                    </div>
                </div>
            </form>

            <!-- Stat Cards -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#e8f5e9;"><i class="bi bi-people-fill" style="color:#45b84d;"></i></div>
                        <div>
                            <div class="label">Total Beneficiaries</div>
                            <div class="value"><?php echo $totalBeneficiaries; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fff3e0;"><i class="bi bi-file-earmark-text-fill" style="color:#f59e0b;"></i></div>
                        <div>
                            <div class="label">Applications Submitted</div>
                            <div class="value"><?php echo $applicationsSubmitted; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#ede7f6;"><i class="bi bi-box-seam-fill" style="color:#7e57c2;"></i></div>
                        <div>
                            <div class="label">In-Kind Items Distributed</div>
                            <div class="value"><?php echo $inKindItemsDistributed; ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Row 1 -->
            <div class="section-label"><i class="bi bi-bar-chart-fill me-1"></i> Committee Overview</div>
            <div class="row g-3 mb-3">
                <div class="col-lg-6">
                    <div class="chart-card" style="height:300px;">
                        <div class="chart-title"><i class="bi bi-people-fill me-1" style="color:#45b84d;"></i> Beneficiaries per Committee</div>
                        <div style="position:relative; height:230px;">
                            <canvas id="beneficiariesChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="chart-card" style="height:300px;">
                        <div class="chart-title"><i class="bi bi-pie-chart-fill me-1" style="color:#45b84d;"></i> Application Status Breakdown</div>
                        <div style="position:relative; height:230px;">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Row 2 -->
            <div class="section-label"><i class="bi bi-graph-up me-1"></i> Trends</div>
            <div class="row g-3 mb-3">
                <div class="col-lg-7">
                    <div class="chart-card" style="height:300px;">
                        <div class="chart-title" id="fundsChartTitle"><i class="bi bi-cash-coin me-1" style="color:#45b84d;"></i> <?php echo e($fundsChartTitle); ?></div>
                        <div style="position:relative; height:230px;">
                            <canvas id="fundsChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="chart-card" style="height:300px;">
                        <div class="chart-title"><i class="bi bi-box-seam-fill me-1" style="color:#7e57c2;"></i> In-Kind Assistance Distributed</div>
                        <div style="position:relative; height:230px;">
                            <canvas id="inKindChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Consolidated Table -->
            <div class="section-label"><i class="bi bi-table me-1"></i> Consolidated Committee Report <span class="text-muted" style="font-weight:500; text-transform:none; letter-spacing:normal;">(period-filtered, all committees)</span></div>
            <div class="table-card">
                <div class="table-responsive-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Committee</th>
                                <th>Total Programs</th>
                                <th>Total Beneficiaries</th>
                                <th>Funds Released</th>
                                <th>In-Kind Assistance</th>
                                <th>Approval Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($consolidatedRows)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-3">No committee data yet.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($consolidatedRows as $row): ?>
                                <tr>
                                    <td><span class="committee-tag"><?php echo e($row['name']); ?></span></td>
                                    <td><?php echo $row['programs']; ?></td>
                                    <td><?php echo $row['beneficiaries']; ?></td>
                                    <td>&#8369;<?php echo number_format($row['funds'], 0); ?></td>
                                    <td><?php echo e($row['inkind']); ?></td>
                                    <td><?php echo $row['approval'] !== null ? $row['approval'] . '%' : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div><!-- /overviewTab -->

        <!-- ==============================
             ACTIVITY LOGS TAB
        ============================== -->
        <div class="tab-pane-custom" id="activityLogsTab">
            <div class="section-label"><i class="bi bi-activity me-1"></i> Activity Logs <span class="text-muted" style="font-weight:500; text-transform:none; letter-spacing:normal;">(user login/logout history, all-time)</span></div>

            <form method="get">
                <input type="hidden" name="tab" value="activityLogsTab">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <span style="font-size:12px; color:#666; font-weight:600;"><i class="bi bi-funnel me-1"></i>Filter:</span>
                        <select class="filter-select" name="role" onchange="this.form.submit()">
                            <option value="">All Roles</option>
                            <?php foreach ($distinctRoles as $r): ?>
                                <option value="<?php echo e($r); ?>" <?php echo $roleFilter === $r ? 'selected' : ''; ?>><?php echo ucfirst(e($r)); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="filter-select" name="logdate" onchange="this.form.submit()">
                            <option value="">All Date</option>
                            <option value="today" <?php echo $dateFilter === 'today' ? 'selected' : ''; ?>>Today</option>
                            <option value="week" <?php echo $dateFilter === 'week' ? 'selected' : ''; ?>>This Week</option>
                            <option value="month" <?php echo $dateFilter === 'month' ? 'selected' : ''; ?>>This Month</option>
                        </select>
                    </div>
                    <div class="search-box">
                        <input type="text" name="logq" value="<?php echo e($logSearch); ?>" placeholder="Search...">
                        <i class="bi bi-search"></i>
                    </div>
                </div>
            </form>

            <div class="table-card">
                <div class="table-responsive-wrap">
                    <table class="report-table" style="min-width:700px;">
                        <thead>
                            <tr>
                                <th>User ID</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Logged In</th>
                                <th>Logged Out</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($activityLogs)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-3">No activity logs found.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($activityLogs as $log): ?>
                                <tr>
                                    <td><?php echo $log['user_id'] ? str_pad($log['user_id'], 3, '0', STR_PAD_LEFT) : '—'; ?></td>
                                    <td><?php echo e($log['full_name']); ?></td>
                                    <td><?php echo e($log['email']); ?></td>
                                    <td><?php echo ucfirst(e($log['role'])); ?></td>
                                    <td><?php echo date('Y-m-d H:i:s', strtotime($log['logged_in_at'])); ?></td>
                                    <td><?php echo $log['logged_out_at'] ? date('Y-m-d H:i:s', strtotime($log['logged_out_at'])) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php renderLogPagination($logPage, $totalActivityPages, $totalActivityLogs, $logPerPage, 'logpage', $activityPaginationParams); ?>
        </div><!-- /activityLogsTab -->

        <!-- ==============================
             AUDIT LOGS TAB
        ============================== -->
        <div class="tab-pane-custom" id="auditLogsTab">
            <div class="section-label"><i class="bi bi-journal-text me-1"></i> Audit Logs <span class="text-muted" style="font-weight:500; text-transform:none; letter-spacing:normal;">(all user actions and system events, all-time)</span></div>

            <form method="get">
                <input type="hidden" name="tab" value="auditLogsTab">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <span style="font-size:12px; color:#666; font-weight:600;"><i class="bi bi-funnel me-1"></i>Filter:</span>
                        <select class="filter-select" name="action" onchange="this.form.submit()">
                            <option value="">All Actions</option>
                            <?php foreach ($distinctActions as $ac): ?>
                                <option value="<?php echo e($ac); ?>" <?php echo $actionFilter === $ac ? 'selected' : ''; ?>><?php echo e($ac); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="filter-select" name="auditdate" onchange="this.form.submit()">
                            <option value="">All Date</option>
                            <option value="today" <?php echo $auditDateFilter === 'today' ? 'selected' : ''; ?>>Today</option>
                            <option value="week" <?php echo $auditDateFilter === 'week' ? 'selected' : ''; ?>>This Week</option>
                            <option value="month" <?php echo $auditDateFilter === 'month' ? 'selected' : ''; ?>>This Month</option>
                        </select>
                    </div>
                    <div class="search-box">
                        <input type="text" name="auditq" value="<?php echo e($auditSearch); ?>" placeholder="Search...">
                        <i class="bi bi-search"></i>
                    </div>
                </div>
            </form>

            <div class="table-card">
                <div class="table-responsive-wrap">
                    <table class="report-table" style="min-width:700px;">
                        <thead>
                            <tr>
                                <th>User ID</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Action</th>
                                <th>Date &amp; Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($auditLogs)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-3">No audit logs found.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($auditLogs as $log): ?>
                                <tr>
                                    <td><?php echo $log['user_id'] ? str_pad($log['user_id'], 3, '0', STR_PAD_LEFT) : '—'; ?></td>
                                    <td><?php echo e($log['full_name']); ?></td>
                                    <td><?php echo e($log['email']); ?></td>
                                    <td><?php echo e($log['action']); ?><?php echo $log['details'] ? ' — ' . e($log['details']) : ''; ?></td>
                                    <td><?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php renderLogPagination($auditPage, $totalAuditPages, $totalAuditLogs, $auditPerPage, 'auditpage', $auditPaginationParams); ?>
        </div><!-- /auditLogsTab -->

    </div><!-- end .main-content -->

    <!-- Generate Report Modal -->
    <div class="modal fade" id="generateReportModal" tabindex="-1" aria-labelledby="generateReportModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header brand-header">
                    <h5 class="modal-title" id="generateReportModalLabel"><i class="bi bi-download me-2"></i>Generate Report</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="generateReportForm">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="reportType" class="form-label">Report Type</label>
                            <select class="form-select" id="reportType" required>
                                <option value="" selected disabled>Select report type</option>
                                <option value="consolidated">Consolidated Summary Report</option>
                                <option value="applicants">Applicant Summaries Report</option>
                                <option value="beneficiaries">Beneficiaries Report</option>
                                <option value="financial">Financial / Funds Released Report</option>
                                <option value="in-kind">In-Kind Assistance Report</option>
                                <option value="disbursement">Disbursement Report</option>
                                <option value="application-status">Application Status Report</option>
                                <option value="scholars">Scholars Report (incl. Activity Participation)</option>
                                <option value="activity-log">Activity Log Report</option>
                                <option value="audit-log">Audit Trail Report</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="reportCommittee" class="form-label">Committee</label>
                            <select class="form-select" id="reportCommittee" required>
                                <option value="all" selected>All Committees</option>
                                <?php foreach ($committees as $c): ?>
                                    <option value="<?php echo e($c['code']); ?>"><?php echo e($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="reportProgram" class="form-label">Program</label>
                            <select class="form-select" id="reportProgram" disabled>
                                <option value="all" selected>All Programs</option>
                            </select>
                            <div class="form-text" style="font-size:11.5px;" id="reportProgramHint">Pick a committee to filter by a specific program, or leave it on "All Programs".</div>
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label for="reportDateFrom" class="form-label">Date From</label>
                                <input type="date" class="form-control" id="reportDateFrom" required>
                            </div>
                            <div class="col-6">
                                <label for="reportDateTo" class="form-label">Date To</label>
                                <input type="date" class="form-control" id="reportDateTo" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-outline-brand" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-brand"><i class="bi bi-file-earmark-arrow-down me-1"></i> Generate</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Tab switching (Overview / Activity Logs / Audit Logs)
        function activateReportsTab(tabName) {
            const btn = document.querySelector('.content-tab-btn[data-tab="' + tabName + '"]');
            const pane = document.getElementById(tabName);
            if (!btn || !pane) return;
            document.querySelectorAll('.content-tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-pane-custom').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            pane.classList.add('active');
        }

        document.querySelectorAll('.content-tab-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                activateReportsTab(btn.dataset.tab);
            });
        });

        // A filter/pagination link on the Activity Logs or Audit Logs tab reloads the page with
        // ?tab=... so the admin lands back on the same tab instead of the default Overview.
        (function() {
            const tab = new URLSearchParams(location.search).get('tab');
            if (tab) activateReportsTab(tab);
        })();

        // Monthly / Yearly toggle — reloads the page via the hidden "period" field so all data is server-computed
        const btnMonthly = document.getElementById('btnMonthly');
        const btnYearly = document.getElementById('btnYearly');
        const periodModeInput = document.getElementById('periodModeInput');
        const filterForm = document.getElementById('filterForm');

        btnMonthly.addEventListener('click', () => {
            if (periodModeInput.value !== 'monthly') {
                periodModeInput.value = 'monthly';
                filterForm.submit();
            }
        });
        btnYearly.addEventListener('click', () => {
            if (periodModeInput.value !== 'yearly') {
                periodModeInput.value = 'yearly';
                filterForm.submit();
            }
        });

        // Beneficiaries per Committee
        new Chart(document.getElementById('beneficiariesChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($beneficiariesPerCommittee, 'name')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_map(fn($r) => (int)$r['cnt'], $beneficiariesPerCommittee)); ?>,
                    backgroundColor: '#45b84d',
                    borderRadius: 6,
                    maxBarThickness: 40
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0,
                            font: {
                                size: 11
                            }
                        },
                        grid: {
                            color: '#eee'
                        }
                    },
                    x: {
                        ticks: {
                            font: {
                                size: 10
                            }
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });

        // Application Status Breakdown
        new Chart(document.getElementById('statusChart'), {
            type: 'doughnut',
            data: {
                labels: ['Pending', 'Approved', 'Rejected'],
                datasets: [{
                    data: [
                        <?php echo (int)$statusCounts['pending']; ?>,
                        <?php echo (int)$statusCounts['approved']; ?>,
                        <?php echo (int)$statusCounts['declined']; ?>
                    ],
                    backgroundColor: ['#f59e0b', '#45b84d', '#e53935'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: {
                                size: 11
                            },
                            padding: 12
                        }
                    }
                },
                cutout: '60%'
            }
        });

        // Funds Released (Monthly or Yearly, per selected mode)
        new Chart(document.getElementById('fundsChart'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode($fundsLabels); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_map('floatval', $fundsData)); ?>,
                    borderColor: '#2e7d32',
                    backgroundColor: 'rgba(69,184,77,0.08)',
                    borderWidth: 2,
                    pointRadius: 4,
                    pointBackgroundColor: '#2e7d32',
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            font: {
                                size: 11
                            }
                        },
                        grid: {
                            color: '#eee'
                        }
                    },
                    x: {
                        ticks: {
                            font: {
                                size: 11
                            }
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });

        // In-Kind Assistance Distributed
        new Chart(document.getElementById('inKindChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($inKindBreakdown, 'items')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_map(fn($r) => (int)$r['qty'], $inKindBreakdown)); ?>,
                    backgroundColor: '#7e57c2',
                    borderRadius: 6,
                    maxBarThickness: 34
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0,
                            font: {
                                size: 11
                            }
                        },
                        grid: {
                            color: '#eee'
                        }
                    },
                    y: {
                        ticks: {
                            font: {
                                size: 11
                            }
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });

        // Built-in tracks + catalog programs per committee code, for the Program filter below.
        const programsByCommittee = <?php echo json_encode($programsByCommitteeCode); ?>;

        const reportTypeSelect = document.getElementById('reportType');
        const reportCommitteeSelect = document.getElementById('reportCommittee');
        const reportProgramSelect = document.getElementById('reportProgram');
        const reportProgramHint = document.getElementById('reportProgramHint');
        const logReportTypes = ['activity-log', 'audit-log'];

        reportCommitteeSelect.addEventListener('change', function() {
            const code = this.value;
            const programs = code !== 'all' ? (programsByCommittee[code] || []) : [];

            reportProgramSelect.innerHTML = '<option value="all" selected>All Programs</option>';
            programs.forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = p.name;
                reportProgramSelect.appendChild(opt);
            });
            reportProgramSelect.disabled = code === 'all';
        });

        // Activity/Audit logs aren't scoped to a committee or program, so lock those filters out when picked.
        reportTypeSelect.addEventListener('change', function() {
            const isLogReport = logReportTypes.includes(this.value);
            reportCommitteeSelect.value = 'all';
            reportCommitteeSelect.disabled = isLogReport;
            reportProgramSelect.innerHTML = '<option value="all" selected>All Programs</option>';
            reportProgramSelect.disabled = true;
            reportProgramHint.textContent = isLogReport ?
                'Not applicable to this report — activity and audit logs are system-wide.' :
                'Pick a committee to filter by a specific program, or leave it on "All Programs".';
        });

        // Generate Report -> streams a PDF from GenerateReport.php
        document.getElementById('generateReportForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const reportType = document.getElementById('reportType').value;
            const committee = reportCommitteeSelect.disabled ? 'all' : document.getElementById('reportCommittee').value;
            const program = reportProgramSelect.disabled ? 'all' : reportProgramSelect.value;
            const dateFrom = document.getElementById('reportDateFrom').value;
            const dateTo = document.getElementById('reportDateTo').value;

            window.location.href = `GenerateReport.php?type=${encodeURIComponent(reportType)}&committee=${encodeURIComponent(committee)}&program=${encodeURIComponent(program)}&from=${encodeURIComponent(dateFrom)}&to=${encodeURIComponent(dateTo)}`;

            const modalEl = document.getElementById('generateReportModal');
            bootstrap.Modal.getInstance(modalEl).hide();
        });
    </script>

</body>

</html>