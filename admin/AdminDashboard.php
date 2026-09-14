<?php
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/forms.php';
requireRole('admin');

$me = currentUser();

// ---- Stat cards ----
// Total Beneficiaries: all-time approved applications across every committee (not just Education scholars).
$totalBeneficiaries = (int)($conn->query("SELECT COUNT(*) c FROM applications WHERE status = 'approved' AND archived_at IS NULL")->fetch_assoc()['c'] ?? 0);

// Total Applicants: distinct users who have ever submitted an application (not archived).
$totalApplicants = (int)($conn->query("SELECT COUNT(DISTINCT user_id) c FROM applications WHERE archived_at IS NULL")->fetch_assoc()['c'] ?? 0);

// Activity Attendees: total attendance rows marked present across all activities.
$activityAttendees = (int)($conn->query("SELECT COUNT(*) c FROM attendance WHERE status = 'present'")->fetch_assoc()['c'] ?? 0);

// ---- Application Status Breakdown ----
$statusCounts = ['pending' => 0, 'approved' => 0, 'declined' => 0];
$statusResult = $conn->query("SELECT status, COUNT(*) c FROM applications WHERE archived_at IS NULL GROUP BY status");
foreach ($statusResult as $row) {
    $statusCounts[$row['status']] = (int)$row['c'];
}

// "Eligible for Payout" = scholars currently marked eligible in the allowance_distributions table.
$eligibleForPayout = (int)($conn->query("SELECT COUNT(*) c FROM allowance_distributions WHERE eligibility = 'eligible'")->fetch_assoc()['c'] ?? 0);

// ---- Submitted Applications per day-of-week (aggregated across all non-archived applications) ----
$weeklyCounts = array_fill(0, 7, 0); // index 0=Mon .. 6=Sun, matching chart labels
$dowResult = $conn->query("SELECT DAYOFWEEK(submitted_at) dow, COUNT(*) c FROM applications WHERE archived_at IS NULL GROUP BY dow");
// MySQL DAYOFWEEK(): 1=Sunday, 2=Monday, ... 7=Saturday
$dowToIndex = [2 => 0, 3 => 1, 4 => 2, 5 => 3, 6 => 4, 7 => 5, 1 => 6];
foreach ($dowResult as $row) {
    $idx = $dowToIndex[(int)$row['dow']] ?? null;
    if ($idx !== null) {
        $weeklyCounts[$idx] = (int)$row['c'];
    }
}

// ---- Program Application Windows: flags programs that are closed or closing within 7 days,
// so admins notice an expiring/expired deadline without having to open every program individually.
$programWindowRows = $conn->query(
    "SELECT p.*, c.name AS committee_name FROM programs p
     JOIN committees c ON c.committee_id = p.committee_id
     WHERE p.archived_at IS NULL AND (p.app_start_date IS NOT NULL OR p.app_end_date IS NOT NULL)
     ORDER BY p.app_end_date ASC"
)->fetch_all(MYSQLI_ASSOC);
$closedPrograms = [];
$closingSoonPrograms = [];
$today = new DateTime('today');
foreach ($programWindowRows as $pr) {
    $reason = programClosedReason($pr);
    if ($reason) {
        $pr['reason'] = $reason;
        $pr['state'] = programClosedState($pr);
        $closedPrograms[] = $pr;
    } elseif (!empty($pr['app_end_date'])) {
        $daysLeft = (int)$today->diff(new DateTime($pr['app_end_date']))->format('%r%a');
        if ($daysLeft >= 0 && $daysLeft <= 7) {
            $pr['days_left'] = $daysLeft;
            $closingSoonPrograms[] = $pr;
        }
    }
}

$activeLink = 'AdminDashboard';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo APP_BASE; ?>/assets/css/admin.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Stat Cards */
        .stat-card {
            border: 2px solid #45b84d;
            border-radius: 10px;
            padding: 16px 20px;
            background: #fff;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .stat-icon {
            width: 46px;
            height: 46px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .stat-card .label {
            font-size: 11px;
            font-weight: 700;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }

        .stat-card .value {
            font-size: 30px;
            font-weight: 700;
            color: #1a1a1a;
            line-height: 1;
        }

        /* Status Cards */
        .status-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .status-card {
            flex: 1;
            min-width: 120px;
            border-radius: 10px;
            padding: 14px 16px;
            text-align: center;
        }

        .status-card .s-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .status-card .s-value {
            font-size: 28px;
            font-weight: 700;
            line-height: 1;
        }

        .status-pending {
            background: #fff8e1;
            border: 1.5px solid #ffe082;
        }

        .status-pending .s-label {
            color: #b77f00;
        }

        .status-pending .s-value {
            color: #f59e0b;
        }

        .status-approved {
            background: #e8f5e9;
            border: 1.5px solid #a5d6a7;
        }

        .status-approved .s-label {
            color: #2e7d32;
        }

        .status-approved .s-value {
            color: #45b84d;
        }

        .status-rejected {
            background: #fdecea;
            border: 1.5px solid #ef9a9a;
        }

        .status-rejected .s-label {
            color: #b71c1c;
        }

        .status-rejected .s-value {
            color: #e53935;
        }

        .status-eligible {
            background: #e3f2fd;
            border: 1.5px solid #90caf9;
        }

        .status-eligible .s-label {
            color: #1565c0;
        }

        .status-eligible .s-value {
            color: #1e88e5;
        }

        /* Chart Cards */
        .chart-card {
            background: #fff;
            border: 1px solid #c8e6c9;
            border-radius: 10px;
            padding: 18px;
        }

        .chart-title {
            font-size: 12px;
            font-weight: 700;
            color: #333;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }

        .section-title {
            font-size: 12px;
            font-weight: 700;
            color: #45b84d;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 10px;
        }

        @media (max-width: 576px) {
            .stat-card {
                padding: 14px 16px;
            }

            .stat-card .value {
                font-size: 24px;
            }

            .status-card {
                min-width: 100%;
            }

            .chart-card {
                height: auto !important;
            }

            .chart-card>div {
                height: 220px !important;
            }
        }
    </style>
</head>

<body>

    <?php include __DIR__ . '/../includes/adminsidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <h4 class="fw-bold mb-1">Dashboard Overview</h4>
        <p class="text-muted mb-4" style="font-size: 13px;">Monitor system performance at a glance.</p>

        <!-- Stat Cards -->
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon" style="background:#e8f5e9;">
                        <i class="bi bi-mortarboard-fill" style="color:#45b84d;"></i>
                    </div>
                    <div>
                        <div class="label">Total Beneficiaries</div>
                        <div class="value"><?php echo $totalBeneficiaries; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon" style="background:#e3f2fd;">
                        <i class="bi bi-people-fill" style="color:#1e88e5;"></i>
                    </div>
                    <div>
                        <div class="label">Total Applicants</div>
                        <div class="value"><?php echo $totalApplicants; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon" style="background:#fff3e0;">
                        <i class="bi bi-calendar-check-fill" style="color:#f59e0b;"></i>
                    </div>
                    <div>
                        <div class="label">Activity Attendees</div>
                        <div class="value"><?php echo $activityAttendees; ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Application Status Breakdown -->
        <div class="mb-3">
            <div class="section-title"><i class="bi bi-bar-chart-fill me-1"></i> Application Status Breakdown</div>
            <div class="status-row">
                <div class="status-card status-pending">
                    <div class="s-label">Pending</div>
                    <div class="s-value"><?php echo $statusCounts['pending']; ?></div>
                </div>
                <div class="status-card status-approved">
                    <div class="s-label">Approved</div>
                    <div class="s-value"><?php echo $statusCounts['approved']; ?></div>
                </div>
                <div class="status-card status-rejected">
                    <div class="s-label">Rejected</div>
                    <div class="s-value"><?php echo $statusCounts['declined']; ?></div>
                </div>
                <div class="status-card status-eligible">
                    <div class="s-label">Eligible for Payout</div>
                    <div class="s-value"><?php echo $eligibleForPayout; ?></div>
                </div>
            </div>
        </div>

        <!-- Program Application Windows -->
        <?php if (!empty($closedPrograms) || !empty($closingSoonPrograms)): ?>
            <div class="mb-3">
                <div class="section-title"><i class="bi bi-calendar-x-fill me-1"></i> Program Application Windows</div>
                <div class="table-card">
                    <div class="table-responsive-wrap">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>Program</th>
                                    <th>Committee</th>
                                    <th>Application Period</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($closingSoonPrograms as $pr): ?>
                                    <tr>
                                        <td><?php echo e($pr['name']); ?></td>
                                        <td><?php echo e($pr['committee_name']); ?></td>
                                        <td><?php echo $pr['app_end_date'] ? date('M j, Y', strtotime($pr['app_end_date'])) : '—'; ?></td>
                                        <td><span class="badge text-bg-warning">Closes in <?php echo $pr['days_left']; ?> day<?php echo $pr['days_left'] === 1 ? '' : 's'; ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php
                                $stateBadges = ['inactive' => ['secondary', 'Inactive'], 'not_open' => ['info', 'Not Yet Open'], 'closed' => ['danger', 'Closed']];
                                foreach ($closedPrograms as $pr):
                                    [$badgeColor, $badgeLabel] = $stateBadges[$pr['state']] ?? ['secondary', 'Closed'];
                                ?>
                                    <tr>
                                        <td><?php echo e($pr['name']); ?></td>
                                        <td><?php echo e($pr['committee_name']); ?></td>
                                        <td><?php echo $pr['app_start_date'] ? date('M j, Y', strtotime($pr['app_start_date'])) : '—'; ?> – <?php echo $pr['app_end_date'] ? date('M j, Y', strtotime($pr['app_end_date'])) : '—'; ?></td>
                                        <td><span class="badge text-bg-<?php echo $badgeColor; ?>" title="<?php echo e($pr['reason']); ?>"><?php echo $badgeLabel; ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <a href="<?php echo APP_BASE; ?>/admin/AdminProgramManagement.php" style="font-size:12px;">Manage program dates in Content Management &rarr;</a>
            </div>
        <?php endif; ?>

        <!-- Charts Row -->
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div class="chart-card" style="height:280px;">
                    <div class="chart-title"><i class="bi bi-bar-chart-line-fill me-1" style="color:#45b84d;"></i> Submitted Applications per Day of Week</div>
                    <div style="position:relative; height:210px;">
                        <canvas id="appChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="chart-card" style="height:280px;">
                    <div class="chart-title"><i class="bi bi-pie-chart-fill me-1" style="color:#45b84d;"></i> Application Status Distribution</div>
                    <div style="position:relative; height:210px;">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- end .main-content -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const weeklyData = <?php echo json_encode(array_values($weeklyCounts)); ?>;
        const weeklyMax = Math.max(10, ...weeklyData) + 5;

        new Chart(document.getElementById('appChart'), {
            type: 'line',
            data: {
                labels: ['Mon', 'Tue', 'Wed', 'Thur', 'Fri', 'Sat', 'Sun'],
                datasets: [{
                    data: weeklyData,
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
                        min: 0,
                        max: weeklyMax,
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

        new Chart(document.getElementById('statusChart'), {
            type: 'doughnut',
            data: {
                labels: ['Pending', 'Approved', 'Rejected', 'Eligible for Payout'],
                datasets: [{
                    data: [
                        <?php echo (int)$statusCounts['pending']; ?>,
                        <?php echo (int)$statusCounts['approved']; ?>,
                        <?php echo (int)$statusCounts['declined']; ?>,
                        <?php echo (int)$eligibleForPayout; ?>
                    ],
                    backgroundColor: ['#f59e0b', '#45b84d', '#e53935', '#1e88e5'],
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
                            padding: 14
                        }
                    }
                },
                cutout: '60%'
            }
        });
    </script>

</body>

</html>