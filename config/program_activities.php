<?php
// Activities for any program (the equivalent of iSKolar's Scholars > Activities): an admin posts
// activities for a program, each approved beneficiary of that program gets a personal QR code, and
// attendance is recorded by scanning it. A program has this switched on via program_tabs.has_activities
// (Content Management > Add/Edit Program, or Configuration > Application / Program Settings for the
// built-in "Assistance Program" tabs). Tables/columns are created on first use.

function ensureProgramActivitySchema()
{
    global $conn;
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $col = $conn->query("SHOW COLUMNS FROM program_tabs LIKE 'has_activities'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE program_tabs ADD COLUMN has_activities TINYINT(1) NOT NULL DEFAULT 0");
    }

    $conn->query("CREATE TABLE IF NOT EXISTS program_activities (
        activity_id INT AUTO_INCREMENT PRIMARY KEY,
        tab_id INT NOT NULL,
        title VARCHAR(150) NOT NULL,
        description TEXT NULL,
        activity_date DATE NOT NULL,
        activity_time TIME NULL,
        venue VARCHAR(150) NULL,
        audience ENUM('all','selected') NOT NULL DEFAULT 'all',
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        archived_at DATETIME NULL,
        KEY idx_tab (tab_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS program_attendance (
        attendance_id INT AUTO_INCREMENT PRIMARY KEY,
        activity_id INT NOT NULL,
        user_id INT NOT NULL,
        status ENUM('pending','present','absent') NOT NULL DEFAULT 'pending',
        qr_token VARCHAR(64) NOT NULL,
        scanned_at DATETIME NULL,
        UNIQUE KEY uq_activity_user (activity_id, user_id),
        KEY idx_token (qr_token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function setProgramTabActivities($tabId, $enabled)
{
    global $conn;
    ensureProgramActivitySchema();
    $flag = $enabled ? 1 : 0;
    $stmt = $conn->prepare("UPDATE program_tabs SET has_activities = ? WHERE tab_id = ?");
    $stmt->bind_param('ii', $flag, $tabId);
    $stmt->execute();
    $stmt->close();
}

// The program_tabs row for a program_id (catalog program), or null.
function getProgramTabByProgramId($programId)
{
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM program_tabs WHERE program_id = ? LIMIT 1");
    $stmt->bind_param('i', $programId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// Name used as $activeLink for the Activities page of a committee — prefixed per committee so the
// sidebar expands the right committee group, and so each committee's own link is the highlighted one.
function programActivitiesActiveName($committeeId)
{
    $map = [
        'education' => 'Education',
        'health' => 'Health',
        'sports' => 'Sports',
        'active_citizenship' => 'ActiveCitizenship',
    ];
    foreach ($map as $code => $prefix) {
        if ((int)getCommitteeIdByCode($code) === (int)$committeeId) {
            return $prefix . 'ProgramActivities';
        }
    }
    return 'CommitteeProgramActivities_' . (int)$committeeId;
}

// URL of a program tab's admin Activities page. Custom programs carry ptab (so the sidebar keeps
// that program's submenu open); a committee's base Assistance tab uses ?tab= so it doesn't.
function programActivitiesUrl(array $tab)
{
    $param = !empty($tab['program_id']) ? 'ptab' : 'tab';
    return APP_BASE . '/admin/ProgramActivities.php?' . $param . '=' . (int)$tab['tab_id'];
}

// Approved, non-archived beneficiaries of a program tab: [user_id, first_name, last_name].
function programTabParticipants(array $tab)
{
    global $conn;
    if (!empty($tab['program_id'])) {
        $stmt = $conn->prepare("SELECT DISTINCT u.user_id, u.first_name, u.last_name
            FROM applications a JOIN users u ON u.user_id = a.user_id
            WHERE a.committee_id = ? AND a.program_id = ? AND a.status = 'approved' AND a.archived_at IS NULL
            ORDER BY u.last_name, u.first_name");
        $stmt->bind_param('ii', $tab['committee_id'], $tab['program_id']);
    } else {
        $stmt = $conn->prepare("SELECT DISTINCT u.user_id, u.first_name, u.last_name
            FROM applications a JOIN users u ON u.user_id = a.user_id
            WHERE a.committee_id = ? AND a.program_track = ? AND a.program_id IS NULL AND a.status = 'approved' AND a.archived_at IS NULL
            ORDER BY u.last_name, u.first_name");
        $stmt->bind_param('is', $tab['committee_id'], $tab['track_code']);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// True if the user is an approved beneficiary of any visible program that has Activities switched on
// — decides whether the applicant/scholar portal shows a "My Program Activities" link.
function userHasProgramActivities($userId)
{
    global $conn;
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    $cache[$userId] = false;
    try {
        $col = $conn->query("SHOW COLUMNS FROM program_tabs LIKE 'has_activities'");
        if (!$col || $col->num_rows === 0) {
            return false;
        }
        $stmt = $conn->prepare("SELECT 1 FROM program_tabs pt
            JOIN applications a ON a.committee_id = pt.committee_id AND a.status = 'approved' AND a.archived_at IS NULL
                AND ((pt.program_id IS NOT NULL AND a.program_id = pt.program_id)
                  OR (pt.program_id IS NULL AND a.program_id IS NULL AND a.program_track = pt.track_code))
            WHERE a.user_id = ? AND pt.has_activities = 1 AND pt.is_visible = 1 LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $cache[$userId] = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
    } catch (Throwable $ex) {
        $cache[$userId] = false;
    }
    return $cache[$userId];
}
