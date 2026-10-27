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

    $col = $conn->query("SHOW COLUMNS FROM program_tabs LIKE 'min_activities'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE program_tabs ADD COLUMN min_activities INT NULL");
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

    $conn->query("CREATE TABLE IF NOT EXISTS program_eligibility_overrides (
        application_id INT PRIMARY KEY,
        eligibility ENUM('eligible','not_eligible') NOT NULL,
        set_by INT NULL,
        set_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
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

// Same idea for the Beneficiaries page (approved applicants and their activity progress).
function programBeneficiariesActiveName($committeeId)
{
    return str_replace('ProgramActivities', 'ProgramBeneficiaries', programActivitiesActiveName($committeeId));
}

function programBeneficiariesUrl(array $tab)
{
    $param = !empty($tab['program_id']) ? 'ptab' : 'tab';
    return APP_BASE . '/admin/ProgramBeneficiaries.php?' . $param . '=' . (int)$tab['tab_id'];
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

// Whether an approved application has met its program's activity requirement — the program-level
// equivalent of a scholar needing their activities done before their allowance. Returns null when
// the application's program doesn't have Activities switched on (nothing required), otherwise
// ['required' => n, 'completed' => m, 'ok' => bool]: n is the program's live (non-archived)
// activities that apply to the person, m how many of them they were scanned/marked present at.
// Activities posted for "all beneficiaries" before the person was approved are assigned to them here
// first, so a later approval isn't stuck owing an activity they never got a QR code for.
function programActivityCompliance($applicationId)
{
    global $conn;
    try {
        $stmt = $conn->prepare("SELECT user_id, committee_id, program_track, program_id FROM applications WHERE application_id = ?");
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $app = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$app) {
            return null;
        }

        if (!empty($app['program_id'])) {
            $stmt = $conn->prepare("SELECT * FROM program_tabs WHERE program_id = ? LIMIT 1");
            $stmt->bind_param('i', $app['program_id']);
        } else {
            $stmt = $conn->prepare("SELECT * FROM program_tabs WHERE committee_id = ? AND track_code = ? AND program_id IS NULL LIMIT 1");
            $stmt->bind_param('is', $app['committee_id'], $app['program_track']);
        }
        $stmt->execute();
        $tab = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$tab || empty($tab['has_activities'])) {
            return null;
        }

        ensureProgramActivitySchema();
        $tabId = (int)$tab['tab_id'];
        $userId = (int)$app['user_id'];

        $stmt = $conn->prepare("SELECT a.activity_id FROM program_activities a
            LEFT JOIN program_attendance att ON att.activity_id = a.activity_id AND att.user_id = ?
            WHERE a.tab_id = ? AND a.archived_at IS NULL AND a.audience = 'all' AND att.attendance_id IS NULL");
        $stmt->bind_param('ii', $userId, $tabId);
        $stmt->execute();
        $missing = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'activity_id');
        $stmt->close();
        if ($missing) {
            $ins = $conn->prepare("INSERT INTO program_attendance (activity_id, user_id, status, qr_token) VALUES (?, ?, 'pending', ?)");
            foreach ($missing as $activityId) {
                $token = generateQrToken();
                $ins->bind_param('iis', $activityId, $userId, $token);
                $ins->execute();
            }
            $ins->close();
        }

        $stmt = $conn->prepare("SELECT COUNT(*) AS required, COALESCE(SUM(att.status = 'present'), 0) AS completed
            FROM program_attendance att JOIN program_activities a ON a.activity_id = att.activity_id
            WHERE att.user_id = ? AND a.tab_id = ? AND a.archived_at IS NULL");
        $stmt->bind_param('ii', $userId, $tabId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $required = (int)$row['required'];
        $completed = (int)$row['completed'];

        // A program can ask for a set number of activities instead of all of them.
        if (!empty($app['program_id'])) {
            ensureProgramInKindColumns();
            $stmt = $conn->prepare("SELECT min_activities FROM programs WHERE program_id = ?");
            $stmt->bind_param('i', $app['program_id']);
            $stmt->execute();
            $minNeeded = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
            $stmt->close();
            if ($minNeeded > 0) {
                $required = $minNeeded;
            }
        } elseif ((int)($tab['min_activities'] ?? 0) > 0) {
            $required = (int)$tab['min_activities']; // built-in tab
        }
        $ok = $completed >= $required;

        // A Super Admin/officer can mark someone Eligible or Not Eligible by hand (Beneficiaries > Actions),
        // which then wins over attendance — same idea as a scholar's "Set Eligibility".
        $manual = null;
        $stmt = $conn->prepare("SELECT eligibility FROM program_eligibility_overrides WHERE application_id = ?");
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $override = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($override) {
            $manual = $override['eligibility'];
            $ok = $manual === 'eligible';
        }

        return ['required' => $required, 'completed' => $completed, 'ok' => $ok, 'manual' => $manual];
    } catch (Throwable $ex) {
        return null;
    }
}

// True unless the application's program requires activities that the person hasn't completed yet.
function programActivitiesSatisfied($applicationId)
{
    $c = programActivityCompliance($applicationId);
    return $c === null || $c['ok'];
}

// Assigns a beneficiary the activities already posted for "all beneficiaries" of each program they are
// approved in — every one still open (not archived) — so someone approved after an activity was created still sees it.
function syncProgramActivitiesForUser($userId)
{
    global $conn;
    try {
        $stmt = $conn->prepare("SELECT DISTINCT a.application_id FROM applications a
            JOIN program_tabs pt ON pt.committee_id = a.committee_id
                AND ((pt.program_id IS NOT NULL AND a.program_id = pt.program_id)
                  OR (pt.program_id IS NULL AND a.program_id IS NULL AND a.program_track = pt.track_code))
            WHERE a.user_id = ? AND a.status = 'approved' AND a.archived_at IS NULL AND pt.has_activities = 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $appIds = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'application_id');
        $stmt->close();
        foreach ($appIds as $appId) {
            programActivityCompliance((int)$appId); // assigns any missing open activities
        }
    } catch (Throwable $ex) {
        // tables not created yet — nothing to sync
    }
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

// Whether a program's tab (programId null = the committee's base Assistance Program tab) has Activities on.
function programTabHasActivities($committeeId, $programId, $track = 'assistance')
{
    global $conn;
    try {
        if ($programId !== null) {
            $stmt = $conn->prepare("SELECT * FROM program_tabs WHERE program_id = ? LIMIT 1");
            $stmt->bind_param('i', $programId);
        } else {
            $stmt = $conn->prepare("SELECT * FROM program_tabs WHERE committee_id = ? AND track_code = ? AND program_id IS NULL LIMIT 1");
            $stmt->bind_param('is', $committeeId, $track);
        }
        $stmt->execute();
        $tab = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return !empty($tab['has_activities']);
    } catch (Throwable $ex) {
        return false;
    }
}

// True when a program with Activities also has the default amount (cash) or items (in-kind) set, so
// eligible beneficiaries can be added automatically instead of by hand.
function autoAddBeneficiariesEnabled($committeeId, $programId, $type)
{
    // Any program (custom, or a built-in Assistance Program with its settings filled in) that has a default
    // amount / items adds its approved (and, with Activities, eligible) applicants by itself; without defaults
    // it stays manual.
    return $type === 'cash' ? getProgramAmount($programId, $committeeId) !== null : getProgramInKind($programId, $committeeId) !== null;
}

// For a program with Activities: every approved applicant who is now eligible (all activities done, or
// marked Eligible by hand) and has no beneficiary record of this type yet is added as a pending
// beneficiary, using the amount (cash) or items/quantity (in-kind) set on the program. Applicants whose
// chosen assistance type is the other one, and ones already added or archived, are left alone.
function autoAddEligibleBeneficiaries($committeeId, $programId, $type)
{
    global $conn;
    if (!autoAddBeneficiariesEnabled($committeeId, $programId, $type)) {
        return;
    }
    $stmt = $conn->prepare("SELECT a.application_id FROM applications a
        LEFT JOIN assistance_beneficiaries ab ON ab.application_id = a.application_id AND ab.type = ?
        WHERE a.committee_id = ? AND a.program_id <=> ? AND a.status = 'approved' AND a.archived_at IS NULL AND ab.beneficiary_id IS NULL
        ORDER BY a.application_id ASC");
    $stmt->bind_param('sii', $type, $committeeId, $programId);
    $stmt->execute();
    $candidates = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'application_id');
    $stmt->close();

    foreach ($candidates as $applicationId) {
        $applicationId = (int)$applicationId;
        $compliance = programActivityCompliance($applicationId);
        // null = the program has no Activities, so being approved is enough.
        if (($compliance !== null && !$compliance['ok']) || !applicationAssistanceTypeMatches($applicationId, $type)) {
            continue;
        }
        if ($type === 'cash') {
            $amount = getProgramAmount($programId, $committeeId);
            $ins = $conn->prepare("INSERT INTO assistance_beneficiaries (application_id, type, amount, status) VALUES (?, 'cash', ?, 'pending')");
            $ins->bind_param('id', $applicationId, $amount);
        } else {
            $defaults = getProgramInKind($programId, $committeeId);
            $ins = $conn->prepare("INSERT INTO assistance_beneficiaries (application_id, type, items, quantity, status) VALUES (?, 'in_kind', ?, ?, 'pending')");
            $ins->bind_param('isi', $applicationId, $defaults['items'], $defaults['quantity']);
        }
        $ins->execute();
        $ins->close();
        logAudit('Auto-added ' . ($type === 'cash' ? 'Cash' : 'In-Kind') . ' Beneficiary', 'Application #' . $applicationId . ' (eligible)');
        notifyBeneficiaryPendingRelease($applicationId, $type);
    }
}
