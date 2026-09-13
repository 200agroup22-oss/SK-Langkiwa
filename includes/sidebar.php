<style>
    /* Sidebar */
    .sidebar {
        position: fixed;
        top: 52px;
        left: 0;
        width: 250px;
        bottom: 0;
        background: #fff;
        border-right: 1px solid #ddd;
        padding: 20px 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        z-index: 998;
    }

    .sidebar .logo-wrap {
        text-align: center;
        padding: 0 12px 16px;
        border-bottom: 1px solid #eee;
        width: 100%;
    }

    .sidebar .logo-wrap img {
        width: 80px;
        height: 80px;
        border-radius: 50%;
    }

    .sidebar .logo-wrap p {
        font-size: 13px;
        font-weight: 700;
        color: #2e7d32;
        margin: 8px 0 0;
        line-height: 1.3;
    }

    .sidebar nav {
        width: 100%;
        margin-top: 10px;
    }

    .nav-link-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 9px 18px;
        font-size: 14px;
        font-weight: 500;
        color: #333;
        text-decoration: none;
        border-radius: 0;
    }

    .nav-link-item:hover,
    .nav-link-item.active {
        background-color: #e8f5e9;
        color: #2e7d32;
    }

    .nav-link-item i {
        font-size: 16px;
        color: #45b84d;
    }

    .sidebar .config-section {
        margin-top: auto;
        width: 100%;
        border-top: 1px solid #eee;
        padding-top: 8px;
    }
</style>

<!-- Sidebar -->
<div class="sidebar">
    <div class="logo-wrap">
        <img src="../photos/logo.jpg" alt="Logo">
        <p>iSKolar ng Langkiwa</p>
    </div>
    <nav>
        <a href="AdminDashboard.php" class="nav-link-item active"><i class="bi bi-bar-chart-line-fill"></i> Dashboard</a>
        <a href="Applicants.php" class="nav-link-item"><i class="bi bi-pencil-square"></i> Applications</a>
        <a href="ScholarList.php" class="nav-link-item"><i class="bi bi-mortarboard-fill"></i> Scholars</a>
        <a href="../admin/AdminAllowanceDistribution.php" class="nav-link-item"><i class="bi bi-cash"></i> Allowance Distribution</a>
        <a href="AdminActivities.php" class="nav-link-item"><i class="bi bi-calendar-event-fill"></i> Activities</a>
        <a href="../admin/AdminUserManagement.php" class="nav-link-item"><i class="bi bi-person-fill"></i> User Management</a>
        <a href="../admin/AdminAnnouncement.php" class="nav-link-item"><i class="bi bi-bell-fill"></i> Announcement</a>
    </nav>
    <div class="config-section">
        <a href="../admin/AdminConfiguration.php" class="nav-link-item"><i class="bi bi-gear-fill"></i> Configuration</a>
    </div>
</div>