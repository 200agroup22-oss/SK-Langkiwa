<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/program_activities.php';

function getCommitteeIdByCode($code)
{
    global $conn;
    $stmt = $conn->prepare("SELECT committee_id FROM committees WHERE code = ?");
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['committee_id'] : null;
}

// Returns a committee's sidebar tabs (e.g. Education's "iSKolar ng Langkiwa" vs "Assistance
// Program"), keyed by track_code. Pass $onlyVisible=false to include hidden tabs (for admin management UI).
function getProgramTabs($committeeId, $onlyVisible = true)
{
    global $conn;
    // LEFT JOINed so each program-backed tab carries its catalog program's assistance_type — lets
    // the sidebar nest a program's link under the matching Cash/In-Kind Assistance item instead of
    // giving every program its own sibling tab. NULL for the two built-in tracks (no program_id).
    $sql = "SELECT pt.*, p.assistance_type AS program_assistance_type FROM program_tabs pt LEFT JOIN programs p ON p.program_id = pt.program_id WHERE pt.committee_id = ?" . ($onlyVisible ? " AND pt.is_visible = 1" : "") . " ORDER BY pt.sort_order ASC, pt.tab_id ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $committeeId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $byTrack = [];
    foreach ($rows as $row) {
        // "bi-hand-holding-heart-fill" does not exist in Bootstrap Icons (it renders blank) - older rows saved it.
        if (($row['icon'] ?? '') === 'bi-hand-holding-heart-fill') {
            $row['icon'] = 'bi-heart-fill';
        }
        $byTrack[$row['track_code']] = $row;
    }
    return $byTrack;
}

// Returns a single tab's DB row (label/icon/is_visible/max_slots), or a sensible fallback if none is configured yet.
function getProgramTab($committeeId, $trackCode, $fallbackLabel = 'Assistance Program', $fallbackIcon = 'bi-heart-fill')
{
    $tabs = getProgramTabs($committeeId, false);
    return $tabs[$trackCode] ?? ['label' => $fallbackLabel, 'icon' => $fallbackIcon, 'is_visible' => 1, 'max_slots' => null];
}

// program_tabs.form_title is the heading shown at the top of an application form (e.g. "HEALTH
// APPLICATION FORM"), separate from the program name used in the sidebar. The column is created on
// demand by ensureFormTitleColumn() the first time a Super Admin sets a title, so readers just
// treat a missing column the same as "no custom title".
function formTitleColumnExists()
{
    global $conn;
    static $exists = null;
    if ($exists === null) {
        $exists = (bool)$conn->query("SHOW COLUMNS FROM program_tabs LIKE 'form_title'")->num_rows;
    }
    return $exists;
}

function ensureFormTitleColumn()
{
    global $conn;
    if (!formTitleColumnExists()) {
        $conn->query("ALTER TABLE program_tabs ADD COLUMN form_title VARCHAR(150) NULL");
    }
}

// The heading for an application form: the custom title set in Configuration > Forms, or $default.
// Matches the same tab the admin edits: a catalog program by program_id, otherwise the committee's
// built-in tab for the track.
function formTitleFor($committeeId, $track, $programId, $default)
{
    global $conn;
    if (!formTitleColumnExists()) {
        return $default;
    }
    if ($programId !== null) {
        $stmt = $conn->prepare("SELECT form_title FROM program_tabs WHERE program_id = ? LIMIT 1");
        $stmt->bind_param('i', $programId);
    } else {
        $stmt = $conn->prepare("SELECT form_title FROM program_tabs WHERE committee_id = ? AND track_code = ? AND program_id IS NULL ORDER BY tab_id ASC LIMIT 1");
        $stmt->bind_param('is', $committeeId, $track);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $title = trim($row['form_title'] ?? '');
    return $title !== '' ? $title : $default;
}

// Looks up a single program_tabs row by its tab_id — used by the Form-builder pages to know
// which sidebar tab (e.g. a custom program like "test1") the admin actually clicked, since those
// pages otherwise have no way to tell one program tab's "Form" link apart from another's.
function getProgramTabById($tabId)
{
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM program_tabs WHERE tab_id = ?");
    $stmt->bind_param('i', $tabId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// The two built-in tracks (Education's "scholarship" and every committee's "assistance") each
// have their own dedicated application-form page. Any other tab was auto-created from a catalog
// entry in `programs` (see AdminProgramManagement.php's "Add Program") and has no page of its
// own — it reuses its committee's assistance-track form instead, tagging submissions with
// ?program_id= so the application records which specific program it was for.
function programTabApplicantUrl($committeeId, array $tab)
{
    $educationId = getCommitteeIdByCode('education');
    $healthId = getCommitteeIdByCode('health');
    $sportsId = getCommitteeIdByCode('sports');

    if ($committeeId === $educationId && $tab['track_code'] === 'scholarship') {
        return APP_BASE . '/applicant/ApplicationForm.php';
    }
    if ($committeeId === $educationId && $tab['track_code'] === 'assistance') {
        return APP_BASE . '/applicant/EducationAssistanceForm.php';
    }
    if ($committeeId === $healthId && $tab['track_code'] === 'assistance') {
        return APP_BASE . '/applicant/HealthApplicationForm.php';
    }
    if ($committeeId === $sportsId && $tab['track_code'] === 'assistance') {
        return APP_BASE . '/applicant/SportsApplicationForm.php';
    }

    if (!empty($tab['program_id'])) {
        $base = null;
        if ($committeeId === $educationId) {
            $base = APP_BASE . '/applicant/EducationAssistanceForm.php';
        } elseif ($committeeId === $healthId) {
            $base = APP_BASE . '/applicant/HealthApplicationForm.php';
        } elseif ($committeeId === $sportsId) {
            $base = APP_BASE . '/applicant/SportsApplicationForm.php';
        }
        if ($base) {
            return $base . '?program_id=' . (int)$tab['program_id'];
        }
    }

    // Every other committee — the built-in Active Citizenship committee, or any committee added
    // via Content Management > Add Committee — shares one generic applicant-facing form.
    $url = APP_BASE . '/applicant/CommitteeApplicationForm.php?committee=' . $committeeId;
    if (!empty($tab['program_id'])) {
        $url .= '&program_id=' . (int)$tab['program_id'];
    }
    return $url;
}

// Admin-side equivalent of programTabApplicantUrl() — points at the committee's existing
// Applicants review page, since applications aren't reviewed per-program, only per track.
function programTabAdminUrl($committeeId, array $tab)
{
    $educationId = getCommitteeIdByCode('education');
    $healthId = getCommitteeIdByCode('health');
    $sportsId = getCommitteeIdByCode('sports');
    $citizenshipId = getCommitteeIdByCode('active_citizenship');

    if ($committeeId === $educationId && $tab['track_code'] === 'scholarship') {
        return APP_BASE . '/admin/Education/EducationApplicants.php';
    }
    if ($committeeId === $educationId && $tab['track_code'] === 'assistance') {
        return APP_BASE . '/admin/Education/EducationAssistanceApplicants.php';
    }
    if ($committeeId === $healthId && $tab['track_code'] === 'assistance') {
        return APP_BASE . '/admin/Health/HealthApplicants.php';
    }
    if ($committeeId === $sportsId && $tab['track_code'] === 'assistance') {
        return APP_BASE . '/admin/Sports/SportsApplicants.php';
    }
    if ($committeeId === $citizenshipId && $tab['track_code'] === 'assistance') {
        return APP_BASE . '/admin/ActiveCitizenship/ActiveCitizenshipApplicants.php';
    }

    if (!empty($tab['program_id'])) {
        if ($committeeId === $educationId) {
            return APP_BASE . '/admin/Education/EducationAssistanceApplicants.php';
        }
        if ($committeeId === $healthId) {
            return APP_BASE . '/admin/Health/HealthApplicants.php';
        }
        if ($committeeId === $sportsId) {
            return APP_BASE . '/admin/Sports/SportsApplicants.php';
        }
        if ($committeeId === $citizenshipId) {
            return APP_BASE . '/admin/ActiveCitizenship/ActiveCitizenshipApplicants.php';
        }
    }
    return null;
}

// Creates (or refreshes) the program_tabs row that mirrors a catalog Program — this is what
// actually makes a newly-added Program show up as a sidebar tab. $sync=true updates an existing
// tab in place (label/visibility/committee) instead of creating a new one; used when a program
// is edited, toggled active/inactive, archived, or restored.
function syncProgramTab($programId, $committeeId, $name, $isActive)
{
    global $conn;
    $isVisible = $isActive ? 1 : 0;

    $stmt = $conn->prepare("SELECT tab_id FROM program_tabs WHERE program_id = ?");
    $stmt->bind_param('i', $programId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $stmt = $conn->prepare("UPDATE program_tabs SET committee_id = ?, label = ?, is_visible = ? WHERE program_id = ?");
        $stmt->bind_param('isii', $committeeId, $name, $isVisible, $programId);
        $stmt->execute();
        $stmt->close();
        return;
    }

    $stmt = $conn->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM program_tabs WHERE committee_id = ?");
    $stmt->bind_param('i', $committeeId);
    $stmt->execute();
    $sortOrder = (int)$stmt->get_result()->fetch_assoc()['next_order'];
    $stmt->close();

    $trackCode = 'program_' . $programId;
    $stmt = $conn->prepare("INSERT INTO program_tabs (committee_id, program_id, track_code, label, is_visible, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('iissii', $committeeId, $programId, $trackCode, $name, $isVisible, $sortOrder);
    $stmt->execute();
    $stmt->close();

    // Give the new program its own starting field set (a copy of the committee's base fields)
    // instead of it inheriting the base tab's fields live — each program's form is independent
    // from here on, so editing one program's fields (or the base tab's) never affects another.
    cloneBaseFormFieldsIntoProgram($committeeId, 'assistance', $programId);
}

// Copies the committee+track's current base fields (program_id IS NULL) into new rows scoped to
// one program, so that program starts with a working form instead of an empty one. No-ops if the
// program already has any fields of its own (avoids re-seeding/duplicating on a later re-sync).
function cloneBaseFormFieldsIntoProgram($committeeId, $programTrack, $programId)
{
    global $conn;

    $stmt = $conn->prepare("SELECT COUNT(*) c FROM form_fields WHERE committee_id = ? AND program_track = ? AND program_id = ?");
    $stmt->bind_param('isi', $committeeId, $programTrack, $programId);
    $stmt->execute();
    $already = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    if ($already > 0) {
        return;
    }

    // Only the basic identity fields are cloned in automatically — a new program starts with a
    // short form and the admin adds whatever extra fields (documents, Type of Assistance, etc.)
    // that specific program actually needs, instead of every program inheriting the full base set.
    $starterFieldKeys = ['last_name', 'first_name', 'middle_name', 'complete_address'];
    $stmt = $conn->prepare("SELECT * FROM form_fields WHERE committee_id = ? AND program_track = ? AND program_id IS NULL AND archived_at IS NULL AND field_key IN ('" . implode("','", $starterFieldKeys) . "') ORDER BY sort_order ASC, field_id ASC");
    $stmt->bind_param('is', $committeeId, $programTrack);
    $stmt->execute();
    $baseFields = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($baseFields)) {
        return;
    }

    $insert = $conn->prepare("INSERT INTO form_fields (committee_id, program_track, program_id, label, field_key, input_type, icon, width, is_required, options, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($baseFields as $f) {
        $insert->bind_param('isisssssisi', $committeeId, $programTrack, $programId, $f['label'], $f['field_key'], $f['input_type'], $f['icon'], $f['width'], $f['is_required'], $f['options'], $f['sort_order']);
        $insert->execute();
    }
    $insert->close();
}

// Hides/unhides the tab for a program without touching its label — used by toggle/archive/restore.
function setProgramTabVisible($programId, $isVisible)
{
    global $conn;
    $vis = $isVisible ? 1 : 0;
    $stmt = $conn->prepare("UPDATE program_tabs SET is_visible = ? WHERE program_id = ?");
    $stmt->bind_param('ii', $vis, $programId);
    $stmt->execute();
    $stmt->close();
}

// Emails the applicant once their application has been approved or declined (called right after
// the status UPDATE in each committee's Applicants page). Silently does nothing if the applicant
// has no email on file or the email fails to send — approval/decline itself must never be blocked
// by a mail delivery problem.
function notifyApplicationDecision($applicationId, $status, $reason = null)
{
    global $conn;
    $stmt = $conn->prepare("SELECT u.user_id, u.email, u.first_name, u.last_name, p.name AS program_name, pt.label AS track_label, c.name AS committee_name
        FROM applications a
        JOIN users u ON u.user_id = a.user_id
        LEFT JOIN programs p ON p.program_id = a.program_id
        LEFT JOIN program_tabs pt ON pt.committee_id = a.committee_id AND pt.track_code = a.program_track
        LEFT JOIN committees c ON c.committee_id = a.committee_id
        WHERE a.application_id = ?");
    $stmt->bind_param('i', $applicationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return;
    }

    $programLabel = $row['program_name'] ?: ($row['track_label'] ?: $row['committee_name']);
    $toName = trim($row['first_name'] . ' ' . $row['last_name']);

    if (!empty($row['email'])) {
        sendApplicationDecisionEmail($row['email'], $toName, $programLabel, $status, $reason);
    }

    $isApproved = $status === 'approved';
    $title = 'Application ' . ($isApproved ? 'Approved' : 'Declined');
    $message = 'Your application for ' . $programLabel . ' has been ' . ($isApproved ? 'approved. Congratulations!' : 'declined.' . (!empty($reason) ? ' Reason: ' . $reason : ''));
    notifyUserInApp((int)$row['user_id'], $title, $message);
}

// Committee IDs a 'committee_admin' user is assigned to. Meaningless for other roles — callers
// should check the role first (or just call requireCommitteeAccess(), which does).
function getUserCommitteeIds($userId)
{
    global $conn;
    $stmt = $conn->prepare("SELECT committee_id FROM admin_committee_assignments WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $ids = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'committee_id'));
    $stmt->close();
    return $ids;
}

// True for 'admin' (also gets Content Management / User Management / Configuration) and for any
// committee_admin additionally checked as Secretary and/or Treasurer in User Management — both
// flags mean "sees every committee's data unscoped + full Reports", same as admin's committee
// visibility, just without the management pages. Takes the full user array (needs the two flags,
// not just the role string).
function hasFullCommitteeAccess($user)
{
    return $user['role'] === 'admin' || !empty($user['is_secretary']) || !empty($user['is_treasurer']);
}

// A committee_admin flagged Secretary and/or Treasurer: sees every committee but is read-only on
// the Applicants pages (View only — no approve/decline/edit/archive/add) and can't add, edit or
// archive Activities (scanning/marking attendance is still allowed). Enforced server-side too.
function isViewOnlyOfficer($user = null)
{
    $user = $user ?? currentUser();
    return $user && ($user['role'] ?? '') === 'committee_admin'
        && (!empty($user['is_secretary']) || !empty($user['is_treasurer']));
}

// 'committee_admin' is the only role that gets an "Assigned Committees" picker in User Management
// and is scoped to just those committees elsewhere (Applicants/Scholars/Assistance/Reports) unless
// also flagged Secretary/Treasurer (see hasFullCommitteeAccess()) — as opposed to 'admin' (sees
// everything) or 'scholar'/'applicant' (not admin-side roles at all).
function isCommitteeScopedRole($role)
{
    return $role === 'committee_admin';
}

// Call after requireRole(['admin', 'committee_admin']) on any committee-specific admin page, once
// that page's $committeeId is known. Admin (and a Secretary/Treasurer-flagged committee_admin)
// always passes; an unflagged committee_admin is sent back to their dashboard if this committee
// isn't one they're assigned to.
function requireCommitteeAccess($committeeId)
{
    $me = currentUser();
    if (hasFullCommitteeAccess($me)) {
        return;
    }
    if (!in_array((int)$committeeId, getUserCommitteeIds($me['user_id']), true)) {
        header("Location: " . dashboardUrlForRole($me['role']));
        exit();
    }
}

// Counts approved, non-archived applications for a committee+track — used to enforce program_tabs.max_slots.
function getApprovedCount($committeeId, $trackCode, $programId = null)
{
    global $conn;
    // A program you created (programId given) counts only its own approved applicants; a built-in tab
    // (no program) counts only applicants who applied to it directly, not those of the programs under it.
    if ($programId !== null) {
        $stmt = $conn->prepare("SELECT COUNT(*) c FROM applications WHERE committee_id = ? AND program_id = ? AND status = 'approved' AND archived_at IS NULL");
        $stmt->bind_param('ii', $committeeId, $programId);
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) c FROM applications WHERE committee_id = ? AND program_track = ? AND program_id IS NULL AND status = 'approved' AND archived_at IS NULL");
        $stmt->bind_param('is', $committeeId, $trackCode);
    }
    $stmt->execute();
    $c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $c;
}

// Returns true if a program's approved count has reached its configured max_slots (never true when unlimited).
function isProgramFull($committeeId, $trackCode, $programId = null)
{
    $tab = $programId !== null ? getProgramTabByProgramId($programId) : getProgramTab($committeeId, $trackCode);
    if (empty($tab['max_slots'])) {
        return false;
    }
    return getApprovedCount($committeeId, $trackCode, $programId) >= (int)$tab['max_slots'];
}

// Looks up a `programs` catalog row by id, including the fields needed to decide whether it's
// currently accepting applications (status, app_start_date, app_end_date, archived_at).
function getProgramById($programId)
{
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM programs WHERE program_id = ?");
    $stmt->bind_param('i', $programId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// Given a `programs` row (or null, meaning no specific program was selected — the generic/base
// track, which has no per-program window to enforce), returns null when applications are open
// right now, or a human-readable reason when they're not. Used on both the admin program list
// (to show an at-a-glance status) and every applicant-facing form (to actually block submission
// outside the configured window instead of silently accepting it).
function programClosedReason($program)
{
    if (!$program) {
        return null;
    }
    if ($program['status'] !== 'active' || !empty($program['archived_at'])) {
        return 'This program is not currently accepting applications.';
    }
    $today = date('Y-m-d');
    if (!empty($program['app_start_date']) && $today < $program['app_start_date']) {
        return 'Applications open on ' . date('F j, Y', strtotime($program['app_start_date'])) . '.';
    }
    if (!empty($program['app_end_date']) && $today > $program['app_end_date']) {
        return 'Applications closed on ' . date('F j, Y', strtotime($program['app_end_date'])) . '.';
    }
    return null;
}

// Short machine-readable counterpart to programClosedReason() — same rules, but returns a state
// keyword ('inactive' | 'not_open' | 'closed') instead of a sentence, for admin UI that wants to
// pick its own badge label/color per case rather than showing one generic "Closed" for every reason.
// Returns null when the program is open (or no program was given).
function programClosedState($program)
{
    if (!$program) {
        return null;
    }
    if ($program['status'] !== 'active' || !empty($program['archived_at'])) {
        return 'inactive';
    }
    $today = date('Y-m-d');
    if (!empty($program['app_start_date']) && $today < $program['app_start_date']) {
        return 'not_open';
    }
    if (!empty($program['app_end_date']) && $today > $program['app_end_date']) {
        return 'closed';
    }
    return null;
}

// The amount set on a program in Content Management (programs.amount), or null if none/zero. Cash
// Assistance pages use it as the default amount for a new beneficiary so it isn't typed twice.
function getProgramAmount($programId)
{
    if (empty($programId)) {
        return null;
    }
    $program = getProgramById((int)$programId);
    $amount = $program['amount'] ?? null;
    return ($amount !== null && (float)$amount > 0) ? (float)$amount : null;
}

// In-kind defaults set on a program (programs.inkind_items / inkind_quantity), used to prefill a new In-Kind
// beneficiary so the items and quantity are not typed twice. Null when the program has none.
function ensureProgramInKindColumns()
{
    global $conn;
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    foreach (['inkind_items' => 'VARCHAR(255) NULL', 'inkind_quantity' => 'INT NULL'] as $col => $def) {
        $exists = $conn->query("SHOW COLUMNS FROM programs LIKE '$col'");
        if ($exists && $exists->num_rows === 0) {
            $conn->query("ALTER TABLE programs ADD COLUMN $col $def");
        }
    }
}

function getProgramInKind($programId)
{
    if (empty($programId)) {
        return null;
    }
    $program = getProgramById((int)$programId);
    $items = trim((string)($program['inkind_items'] ?? ''));
    if ($items === '') {
        return null;
    }
    $qty = (int)($program['inkind_quantity'] ?? 0);
    return ['items' => $items, 'quantity' => $qty > 0 ? $qty : 1];
}

function getProgramInKindForApplication($applicationId)
{
    global $conn;
    $stmt = $conn->prepare("SELECT program_id FROM applications WHERE application_id = ?");
    $stmt->bind_param('i', $applicationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? getProgramInKind($row['program_id']) : null;
}

function getProgramAmountForApplication($applicationId)
{
    global $conn;
    $stmt = $conn->prepare("SELECT program_id FROM applications WHERE application_id = ?");
    $stmt->bind_param('i', $applicationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? getProgramAmount($row['program_id']) : null;
}

// "Type of Assistance" (Cash vs In-Kind) only means something for a program that offers both. A
// single-type program has nothing to choose, so its form, Applicants list and Assistance Requests
// don't need it. The base "Assistance Program" tab (no program selected) keeps it.
function programShowsAssistanceType($programId)
{
    static $cache = [];
    if ($programId === null) {
        return true;
    }
    if (!isset($cache[$programId])) {
        $program = getProgramById((int)$programId);
        $cache[$programId] = !$program || ($program['assistance_type'] ?? '') === 'both';
    }
    return $cache[$programId];
}

// Gives a program that offers both Cash and In-Kind the "Type of Assistance" radio field on its
// application form (so applicants can say which one they want). Does nothing if it already has one.
function ensureProgramAssistanceTypeField($committeeId, $programId)
{
    global $conn;
    $stmt = $conn->prepare("SELECT field_id FROM form_fields WHERE committee_id = ? AND program_track = 'assistance' AND program_id = ? AND archived_at IS NULL AND (field_key = 'assistance_type' OR LOWER(TRIM(label)) = 'type of assistance') LIMIT 1");
    $stmt->bind_param('ii', $committeeId, $programId);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    if ($exists) {
        return;
    }

    $stmt = $conn->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM form_fields WHERE committee_id = ? AND program_track = 'assistance' AND program_id = ?");
    $stmt->bind_param('ii', $committeeId, $programId);
    $stmt->execute();
    $sortOrder = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();

    $label = 'Type of Assistance';
    $key = 'assistance_type';
    $options = json_encode(['Cash Assistance', 'In-kind Assistance']);
    $stmt = $conn->prepare("INSERT INTO form_fields (committee_id, program_track, program_id, label, field_key, input_type, icon, width, is_required, options, sort_order) VALUES (?, 'assistance', ?, ?, ?, 'radio', 'bi-cash-coin', 'full', 1, ?, ?)");
    $stmt->bind_param('iisssi', $committeeId, $programId, $label, $key, $options, $sortOrder);
    $stmt->execute();
    $stmt->close();
}

// Turns a field label into a unique, DB-safe field_key within its committee+track(+program) scope
// (e.g. "Full Name" -> "full_name", "full_name_2" if that key is already taken). Shared by every
// place that lets an admin add a custom form field.
function slugifyFieldKey($label, $committeeId, $track, $conn, $programId = null)
{
    $base = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $label), '_'));
    if ($base === '') {
        $base = 'field';
    }
    // "Type of Assistance" is a built-in field the Cash/In-Kind pages look up by key, so a re-added
    // copy must use that key too (only a live copy counts as already taken).
    $liveOnly = '';
    if ($base === 'type_of_assistance') {
        $base = 'assistance_type';
        $liveOnly = ' AND archived_at IS NULL';
    }
    $key = $base;
    $i = 2;
    while (true) {
        $sql = "SELECT field_id FROM form_fields WHERE committee_id = ? AND program_track = ? AND field_key = ?" . $liveOnly . " AND " . ($programId !== null ? "program_id = ?" : "program_id IS NULL");
        $stmt = $conn->prepare($sql);
        if ($programId !== null) {
            $stmt->bind_param('issi', $committeeId, $track, $key, $programId);
        } else {
            $stmt->bind_param('iss', $committeeId, $track, $key);
        }
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$exists) {
            return $key;
        }
        $key = $base . '_' . $i;
        $i++;
    }
}

// Returns the active (non-archived) field definitions for a committee + program track, in display order.
// program_track distinguishes multiple application forms within one committee (e.g. Education's
// 'scholarship' iSKolar form vs its 'assistance' financial-aid form). Defaults to 'assistance',
// the only track that exists for Health/Sports/Active Citizenship.
// Every program's form is independent — pass $programId to get exactly that program's own fields;
// leave it null to get the base "Assistance Program" tab's own fields. Nothing is shared between
// them, so one program can drop a requirement another program still needs (or add extras of its
// own) without touching anyone else's form. A brand-new program starts with a copy of the base
// fields (see cloneBaseFormFieldsIntoProgram()) rather than an empty form, but from that point on
// each is edited on its own.
function getFormFields($committeeId, $programTrack = 'assistance', $programId = null, $includeAll = false)
{
    global $conn;
    $sql = "SELECT * FROM form_fields WHERE committee_id = ? AND program_track = ? AND archived_at IS NULL AND "
        . ($programId !== null ? "program_id = ?" : "program_id IS NULL")
        . " ORDER BY sort_order ASC, field_id ASC";
    $stmt = $conn->prepare($sql);
    if ($programId !== null) {
        $stmt->bind_param('isi', $committeeId, $programTrack, $programId);
    } else {
        $stmt->bind_param('is', $committeeId, $programTrack);
    }
    $stmt->execute();
    $fields = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // A program that offers only Cash or only In-Kind has nothing to choose, so its "Type of Assistance"
    // field is left out of the form, validation and lists (Configuration > Forms passes $includeAll to
    // still show everything for editing).
    if (!$includeAll && $programId !== null && !programShowsAssistanceType($programId)) {
        $fields = array_values(array_filter($fields, fn($f) => !fieldMatchesKey($f, 'assistance_type')));
    }
    return $fields;
}

// Looks up which program (if any) an application was filed under, so its answers can be rendered
// with that program's own fields layered on top of the shared ones — an application's own row is
// the source of truth for this, not whatever program filter the current page happens to be showing.
function getApplicationProgramId($applicationId)
{
    global $conn;
    $stmt = $conn->prepare("SELECT program_id FROM applications WHERE application_id = ?");
    $stmt->bind_param('i', $applicationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ($row && $row['program_id'] !== null) ? (int)$row['program_id'] : null;
}

// Returns the account holder's own name, keyed by the field_key convention used for the built-in
// "Last Name" / "First Name" / "Middle Name" dynamic fields — used to lock those fields to the
// logged-in applicant's real identity on self-service forms, so one account can't submit (or
// edit) an application under a different person's name. Always read fresh from `users`, not the
// session, since session data can be stale and this is the value being trusted server-side.
function ownIdentityFields($userId)
{
    global $conn;
    $stmt = $conn->prepare("SELECT first_name, last_name, middle_name FROM users WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return [];
    }
    return [
        'last_name' => $row['last_name'],
        'first_name' => $row['first_name'],
        'middle_name' => $row['middle_name'] ?? '',
    ];
}

function widthToColClass($width)
{
    switch ($width) {
        case 'third':
            return 'col-md-4';
        case 'half':
            return 'col-md-6';
        case 'two_third':
            return 'col-md-8';
        default:
            return 'col-md-12';
    }
}

// Loads existing answers/files for an application, keyed by field_id, for pre-filling an edit form.
function getApplicationAnswers($applicationId)
{
    global $conn;
    $answers = [];
    $stmt = $conn->prepare("SELECT field_id, value FROM application_answers WHERE application_id = ?");
    $stmt->bind_param('i', $applicationId);
    $stmt->execute();
    foreach ($stmt->get_result() as $row) {
        $answers[(int)$row['field_id']] = $row['value'];
    }
    $stmt->close();
    return $answers;
}

function getApplicationFiles($applicationId)
{
    global $conn;
    $files = [];
    $stmt = $conn->prepare("SELECT field_id, file_path, original_name FROM application_files WHERE application_id = ?");
    $stmt->bind_param('i', $applicationId);
    $stmt->execute();
    foreach ($stmt->get_result() as $row) {
        $files[(int)$row['field_id']] = ['path' => $row['file_path'], 'original_name' => $row['original_name']];
    }
    $stmt->close();
    return $files;
}

// Renders the dynamic form fields (input elements only - caller supplies the <form> wrapper and submit button).
// $programId layers in that program's own fields on top of the committee's shared ones — pass the
// program the applicant/application actually belongs to (see getApplicationProgramId() for an
// existing application) so the right fields show up.
// $lockedIdentity (see ownIdentityFields()) pre-fills and locks any field whose field_key it
// contains (last_name/first_name/middle_name) to that value — pass it on applicant self-service
// forms so a user can't type a different person's name into their own application. Leave it null
// (the default) for admin-facing forms, where typing/editing the applicant's name is the point.
function renderDynamicFormFields($committeeId, $existingAnswers = [], $existingFiles = [], $programTrack = 'assistance', $programId = null, $lockedIdentity = null)
{
    static $scriptPrinted = false;
    static $callCount = 0;
    // A page can render this more than once (e.g. an "Add Applicant" modal plus one "Edit"
    // modal per existing application), so radio option ids need a per-call prefix — otherwise
    // every call produces the same id and a <label for="..."> in one modal ends up toggling
    // the identically-id'd radio in a different (often hidden) one instead of its own.
    $callCount++;
    $idPrefix = 'df' . $callCount . '_';
    $fields = getFormFields($committeeId, $programTrack, $programId);
    echo '<div class="row">';
    foreach ($fields as $field) {
        $colClass = widthToColClass($field['width']);
        $name = e($field['field_key']);
        $label = e($field['label']);
        $icon = e($field['icon'] ?: 'bi-pencil-fill');
        $required = $field['is_required'] ? 'required' : '';
        $isLocked = $lockedIdentity !== null && array_key_exists($field['field_key'], $lockedIdentity);
        $value = $isLocked ? e($lockedIdentity[$field['field_key']]) : e($existingAnswers[$field['field_id']] ?? '');

        echo '<div class="' . $colClass . ' mb-3">';
        echo '<label class="form-label"><i class="bi ' . $icon . ' me-1 text-success"></i>' . $label . ($field['is_required'] ? ' <span class="text-danger">*</span>' : '') . ($isLocked ? ' <i class="bi bi-lock-fill text-muted ms-1" title="Locked to your account"></i>' : '') . '</label>';

        switch ($field['input_type']) {
            case 'textarea':
                echo '<textarea class="form-control js-sentence-case" name="' . $name . '" rows="5" ' . $required . '>' . $value . '</textarea>';
                break;

            case 'dropdown':
                $options = json_decode($field['options'] ?? '[]', true) ?: [];
                echo '<select class="form-select" name="' . $name . '" ' . $required . '>';
                echo '<option value="" disabled' . ($value === '' ? ' selected' : '') . '>Select ' . $label . '</option>';
                foreach ($options as $opt) {
                    if (!assistanceOptionAllowed($field, $opt)) {
                        continue;
                    }
                    $sel = ($value === $opt) ? 'selected' : '';
                    echo '<option value="' . e($opt) . '" ' . $sel . '>' . e($opt) . '</option>';
                }
                echo '</select>';
                break;

            case 'radio':
                $options = json_decode($field['options'] ?? '[]', true) ?: [];
                foreach ($options as $i => $opt) {
                    if (!assistanceOptionAllowed($field, $opt)) {
                        continue;
                    }
                    $id = $idPrefix . $name . '_' . $i;
                    $checked = ($value === $opt) ? 'checked' : '';
                    echo '<div class="form-check">';
                    echo '<input class="form-check-input" type="radio" name="' . $name . '" id="' . e($id) . '" value="' . e($opt) . '" ' . $checked . ' ' . $required . '>';
                    echo '<label class="form-check-label" for="' . e($id) . '">' . e($opt) . '</label>';
                    echo '</div>';
                }
                break;

            case 'file':
                if (!empty($existingFiles[$field['field_id']])) {
                    echo '<div class="mb-1 small text-success"><i class="bi bi-check-circle-fill"></i> On file: <a href="' . APP_BASE . '/' . e($existingFiles[$field['field_id']]['path']) . '" target="_blank" rel="noopener" class="text-success">' . e($existingFiles[$field['field_id']]['original_name']) . '</a></div>';
                }
                $fileRequired = (!empty($existingFiles[$field['field_id']])) ? '' : $required;
                echo '<input type="file" class="form-control" name="' . $name . '" accept=".jpg,.jpeg,.png,.pdf" ' . $fileRequired . ' onchange="if(this.files[0]&&this.files[0].size>4194304){this.value=\'\';this.setCustomValidity(\'File must be 4MB or smaller.\');this.reportValidity();}else{this.setCustomValidity(\'\');}">';
                echo '<div class="form-text" style="font-size:11.5px;">JPG, PNG or PDF, up to 4MB.</div>';
                break;

            case 'number':
                echo '<input type="number" class="form-control" name="' . $name . '" value="' . $value . '" ' . $required . '>';
                break;

            case 'date':
                echo '<input type="date" class="form-control" name="' . $name . '" value="' . $value . '" ' . $required . '>';
                break;

            default:
                $lockedAttr = $isLocked ? 'readonly' : '';
                $inputClass = $isLocked ? 'form-control' : 'form-control js-sentence-case';
                // Name fields take letters, spaces, hyphens, apostrophes and periods only — no digits or symbols.
                $nameAttrs = '';
                if (!$isLocked && in_array($field['field_key'], ['last_name', 'first_name', 'middle_name'], true)) {
                    $nameAttrs = ' pattern="\p{L}[\p{L} .\'\-]*" maxlength="50" title="Letters, spaces, hyphens, apostrophes, and periods only."';
                }
                echo '<input type="text" class="' . $inputClass . '" name="' . $name . '" value="' . $value . '" ' . $required . ' ' . $lockedAttr . $nameAttrs . '>';
        }

        echo '</div>';
    }
    echo '</div>';

    if (!$scriptPrinted) {
        $scriptPrinted = true;
        echo <<<'HTML'
<script>
document.addEventListener('input', function (e) {
    var el = e.target;
    if (!el.classList || !el.classList.contains('js-sentence-case')) return;
    var start = el.selectionStart, end = el.selectionEnd;
    var val = el.value;
    var newVal = val.replace(/(^\s*[a-z])|([.!?]\s+[a-z])/g, function (m) {
        return m.toUpperCase();
    });
    if (newVal !== val) {
        el.value = newVal;
        if (start !== null) el.setSelectionRange(start, end);
    }
});
</script>
HTML;
    }
}

// Validates $_POST/$_FILES against the committee's required fields.
// $existingFiles (field_id => file info) lets edits pass validation when a required file was already uploaded previously.
function validateDynamicSubmission($committeeId, array $post, array $files, array $existingFiles = [], $programTrack = 'assistance', $programId = null)
{
    $errors = [];
    foreach (getFormFields($committeeId, $programTrack, $programId) as $field) {
        $key = $field['field_key'];

        if ($field['input_type'] === 'file') {
            $hasNewFile = isset($files[$key]) && $files[$key]['error'] !== UPLOAD_ERR_NO_FILE;
            if ($hasNewFile) {
                $fileError = validateUploadedFile($files[$key]);
                if ($fileError) {
                    $errors[] = $field['label'] . ': ' . $fileError;
                }
            } elseif ($field['is_required'] && empty($existingFiles[$field['field_id']])) {
                $errors[] = $field['label'] . ' is required.';
            }
        } elseif ($field['is_required']) {
            $postedValue = $post[$key] ?? '';
            if (is_array($postedValue)) {
                $postedValue = implode(', ', $postedValue);
            }
            if (trim($postedValue) === '') {
                $errors[] = $field['label'] . ' is required.';
            }
        }

        // Name fields: letters, spaces, hyphens, apostrophes and periods only.
        if (in_array($key, ['last_name', 'first_name', 'middle_name'], true)) {
            $nameValue = trim($post[$key] ?? '');
            if ($nameValue !== '' && ($nameError = validatePersonName($nameValue, $field['label'], false))) {
                $errors[] = $nameError;
            }
        }
    }
    return $errors;
}

// Loads active (non-archived) applications for a committee+track with their dynamic answers/files
// attached. Pass $programId to scope this to one specific program tab (e.g. when the admin
// navigated here via a program-specific sidebar link) instead of pooling every program under the
// committee together; leave it null to see everything, same as before this parameter existed.
function listApplications($committeeId, $programTrack, $programId = null)
{
    global $conn;
    $sql = "SELECT a.*, u.email AS user_email FROM applications a
        JOIN users u ON u.user_id = a.user_id
        WHERE a.committee_id = ? AND a.program_track = ? AND a.archived_at IS NULL"
        . ($programId !== null ? " AND a.program_id = ?" : " AND a.program_id IS NULL")
        . " ORDER BY a.application_id ASC";
    $stmt = $conn->prepare($sql);
    if ($programId !== null) {
        $stmt->bind_param('isi', $committeeId, $programTrack, $programId);
    } else {
        $stmt->bind_param('is', $committeeId, $programTrack);
    }
    $stmt->execute();
    $apps = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($apps as &$app) {
        $app['answers'] = getApplicationAnswers($app['application_id']);
        $app['files'] = getApplicationFiles($app['application_id']);
    }
    unset($app);
    return $apps;
}

// Same as listApplications() but for the archive view.
function listArchivedApplications($committeeId, $programTrack, $programId = null)
{
    global $conn;
    $sql = "SELECT a.*, u.email AS user_email FROM applications a
        JOIN users u ON u.user_id = a.user_id
        WHERE a.committee_id = ? AND a.program_track = ? AND a.archived_at IS NOT NULL"
        . ($programId !== null ? " AND a.program_id = ?" : " AND a.program_id IS NULL")
        . " ORDER BY a.archived_at DESC";
    $stmt = $conn->prepare($sql);
    if ($programId !== null) {
        $stmt->bind_param('isi', $committeeId, $programTrack, $programId);
    } else {
        $stmt->bind_param('is', $committeeId, $programTrack);
    }
    $stmt->execute();
    $apps = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $apps;
}

// Label to show for which program an application belongs to, e.g. in a "Program" table column —
// looks up the program name from its catalog entry, falling back to "General" for applications
// with no program_id (submitted via the committee's base assistance track).
function programLabel($programId)
{
    global $conn;
    if (empty($programId)) {
        return 'General';
    }
    $stmt = $conn->prepare("SELECT name FROM programs WHERE program_id = ?");
    $stmt->bind_param('i', $programId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? $row['name'] : 'General';
}

// Convenience accessor: look up an application's answer value by its field_key (e.g. 'school_university')
// rather than the numeric field_id, since callers usually know the key, not the id.
function answerByKey($app, $fields, $key, $default = '')
{
    foreach ($fields as $f) {
        if (fieldMatchesKey($f, $key)) {
            $value = $app['answers'][$f['field_id']] ?? '';
            if ($value !== '' && $value !== null) {
                return $value;
            }
        }
    }
    // Fall back to an answer stored under a removed (archived) copy of the same field — e.g. the
    // form's "Type of Assistance" was removed and re-added after the applicant applied, so their
    // answer still points at the old field_id and would otherwise show as blank in the lists.
    if (!empty($app['application_id'])) {
        global $conn;
        $labelMatch = $key === 'assistance_type' ? " OR LOWER(TRIM(f.label)) = 'type of assistance'" : '';
        $stmt = $conn->prepare("SELECT aa.value FROM application_answers aa JOIN form_fields f ON f.field_id = aa.field_id
            WHERE aa.application_id = ? AND (f.field_key = ?" . $labelMatch . ") AND aa.value IS NOT NULL AND aa.value <> '' ORDER BY aa.answer_id DESC LIMIT 1");
        $stmt->bind_param('is', $app['application_id'], $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return $row['value'];
        }
    }
    return $default;
}

// Whether a form field is the one the system knows by $key. The "Type of Assistance" field is
// recognised by its label too, because a copy re-added through Configuration > Forms gets an
// auto-generated key (type_of_assistance) instead of the built-in assistance_type.
function fieldMatchesKey($field, $key)
{
    if ($field['field_key'] === $key) {
        return true;
    }
    return $key === 'assistance_type' && strtolower(trim($field['label'] ?? '')) === 'type of assistance';
}

// Whether an applicant's answer to the "Type of Assistance" form field (e.g. "Cash Assistance" /
// "In-kind Assistance") matches a given Cash/In-Kind Assistance admin page ($pageType: 'cash' or
// 'in_kind') — used so an applicant only ever shows up as a beneficiary candidate on the page
// matching what they actually requested, not on both. An application with no recognizable answer
// (submitted before this field existed, or the field was removed from the form) still matches
// either page, so older applications don't silently become impossible to process.
// Looks up an application's own "Type of Assistance" answer and checks it against $pageType
// ('cash' or 'in_kind') — the server-side counterpart to the display-side filtering in each
// Cash/In-Kind Assistance page, so a forged "add beneficiary" request can't add someone under the
// type they didn't actually request.
function applicationAssistanceTypeMatches($applicationId, $pageType)
{
    global $conn;
    $stmt = $conn->prepare("SELECT committee_id, program_track, program_id FROM applications WHERE application_id = ?");
    $stmt->bind_param('i', $applicationId);
    $stmt->execute();
    $app = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$app) {
        return false;
    }
    $fields = getFormFields($app['committee_id'], $app['program_track'], $app['program_id']);
    $answers = getApplicationAnswers($applicationId);
    $answer = answerByKey(['answers' => $answers], $fields, 'assistance_type');
    return assistanceTypeAnswerMatches($answer, $pageType);
}

function assistanceTypeAnswerMatches($answer, $pageType)
{
    $answer = trim((string)$answer);
    if ($answer === '') {
        return true;
    }
    $hasInKind = stripos($answer, 'in-kind') !== false || stripos($answer, 'in kind') !== false || stripos($answer, 'inkind') !== false;
    $hasCash = stripos($answer, 'cash') !== false;
    if ($hasInKind && $hasCash) {
        return true; // asked for both
    }
    if ($hasInKind) {
        return $pageType === 'in_kind';
    }
    if ($hasCash) {
        return $pageType === 'cash';
    }
    return true;
}

// Persists $_POST/$_FILES for a committee's dynamic fields against an application.
// Non-file answers are fully replaced; files are only replaced for fields with a newly uploaded file.
function saveDynamicSubmission($applicationId, $committeeId, array $post, array $files, $programTrack = 'assistance', $programId = null)
{
    global $conn;

    $del = $conn->prepare("DELETE FROM application_answers WHERE application_id = ?");
    $del->bind_param('i', $applicationId);
    $del->execute();
    $del->close();

    foreach (getFormFields($committeeId, $programTrack, $programId) as $field) {
        $key = $field['field_key'];
        $fieldId = $field['field_id'];

        if ($field['input_type'] === 'file') {
            if (isset($files[$key]) && $files[$key]['error'] !== UPLOAD_ERR_NO_FILE) {
                $uploaded = handleUpload($files[$key]);
                if ($uploaded) {
                    $delFile = $conn->prepare("DELETE FROM application_files WHERE application_id = ? AND field_id = ?");
                    $delFile->bind_param('ii', $applicationId, $fieldId);
                    $delFile->execute();
                    $delFile->close();

                    $insFile = $conn->prepare("INSERT INTO application_files (application_id, field_id, file_path, original_name) VALUES (?, ?, ?, ?)");
                    $insFile->bind_param('iiss', $applicationId, $fieldId, $uploaded['path'], $uploaded['original_name']);
                    $insFile->execute();
                    $insFile->close();
                }
            }
            continue;
        }

        $value = $post[$key] ?? '';
        if (is_array($value)) {
            $value = implode(', ', array_map('trim', $value));
        }
        $insAns = $conn->prepare("INSERT INTO application_answers (application_id, field_id, value) VALUES (?, ?, ?)");
        $insAns->bind_param('iis', $applicationId, $fieldId, $value);
        $insAns->execute();
        $insAns->close();
    }
}

// ---- Release schedule notices (Pending Release) ----
// A program carries one release schedule (date / time / venue, set in Content Management). Beneficiaries
// whose assistance is Pending Release are emailed it when they are added and again if it changes.
function ensureProgramReleaseColumns()
{
    global $conn;
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    foreach (['release_date' => 'DATE NULL', 'release_time' => 'TIME NULL', 'release_venue' => 'VARCHAR(150) NULL'] as $col => $def) {
        $exists = $conn->query("SHOW COLUMNS FROM programs LIKE '$col'");
        if ($exists && $exists->num_rows === 0) {
            $conn->query("ALTER TABLE programs ADD COLUMN $col $def");
        }
    }
}

// Emails (and posts an in-app notice to) the person behind an application about their pending release.
// $beneficiary is the assistance_beneficiaries row (type, amount, items, quantity).
function notifyBeneficiaryRelease($applicationId, array $beneficiary, $updated = false)
{
    global $conn;
    try {
        $stmt = $conn->prepare("SELECT u.user_id, u.email, u.first_name, u.last_name, a.program_id, a.committee_id, c.name AS committee_name
            FROM applications a JOIN users u ON u.user_id = a.user_id JOIN committees c ON c.committee_id = a.committee_id
            WHERE a.application_id = ?");
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || empty($row['email']) || substr($row['email'], -12) === '@walkin.local') {
            return; // walk-in applicants have no real email
        }

        $program = !empty($row['program_id']) ? getProgramById((int)$row['program_id']) : null;
        $type = $beneficiary['type'] ?? 'cash';
        $what = $type === 'cash'
            ? 'PHP ' . number_format((float)($beneficiary['amount'] ?? 0), 2)
            : trim(($beneficiary['items'] ?? '') . (!empty($beneficiary['quantity']) ? ' x' . (int)$beneficiary['quantity'] : ''));
        $details = [
            'program' => $program['name'] ?? ($row['committee_name'] . ' Assistance Program'),
            'type' => $type,
            'what' => $what,
            'date' => $program['release_date'] ?? null,
            'time' => $program['release_time'] ?? null,
            'venue' => $program['release_venue'] ?? null,
        ];
        sendReleaseScheduleEmail($row['email'], trim($row['first_name'] . ' ' . $row['last_name']), $details, $updated);

        $when = !empty($details['date']) ? date('F j, Y', strtotime($details['date'])) . (!empty($details['time']) ? ' at ' . date('g:i A', strtotime($details['time'])) : '') : 'to be announced';
        notifyUserInApp(
            (int)$row['user_id'],
            $updated ? 'Release schedule updated' : 'Assistance pending release',
            $details['program'] . ' (' . $what . ') - release: ' . $when . (!empty($details['venue']) ? ', ' . $details['venue'] : '') . '.'
        );
    } catch (Throwable $ex) {
        error_log('Release notice failed: ' . $ex->getMessage());
    }
}

// Convenience for the page handlers right after a beneficiary was added.
function notifyBeneficiaryPendingRelease($applicationId, $type)
{
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM assistance_beneficiaries WHERE application_id = ? AND type = ? AND status = 'pending' AND archived_at IS NULL ORDER BY beneficiary_id DESC LIMIT 1");
    $stmt->bind_param('is', $applicationId, $type);
    $stmt->execute();
    $ben = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($ben) {
        notifyBeneficiaryRelease((int)$applicationId, $ben, false);
    }
}

// The release schedule of a program changed: tell everyone still waiting for their release.
function notifyProgramReleaseScheduleChanged($programId)
{
    global $conn;
    $stmt = $conn->prepare("SELECT ab.*, a.application_id AS app_id FROM assistance_beneficiaries ab
        JOIN applications a ON a.application_id = ab.application_id
        WHERE a.program_id = ? AND ab.status = 'pending' AND ab.archived_at IS NULL");
    $stmt->bind_param('i', $programId);
    $stmt->execute();
    $bens = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($bens as $ben) {
        notifyBeneficiaryRelease((int)$ben['app_id'], $ben, true);
    }
}

// Turns a new program's "Eligibility Requirements" list (one per line) into required file-upload fields on
// its application form, so the documents applicants must submit exist from day one. Called once when a
// program is created; later edits to the list never touch the form (that is edited in Configuration > Forms).
function createRequirementFieldsForProgram($committeeId, $programId, $requirementsText)
{
    global $conn;
    $labels = [];
    foreach (preg_split('/\r\n|\r|\n/', (string)$requirementsText) as $line) {
        $label = trim(preg_replace('/\s+/', ' ', $line));
        if ($label !== '' && mb_strlen($label) <= 100 && !in_array(mb_strtolower($label), array_map('mb_strtolower', $labels), true)) {
            $labels[] = $label;
        }
    }
    if (empty($labels)) {
        return;
    }

    $stmt = $conn->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM form_fields WHERE committee_id = ? AND program_track = 'assistance' AND program_id = ?");
    $stmt->bind_param('ii', $committeeId, $programId);
    $stmt->execute();
    $sort = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();

    $insert = $conn->prepare("INSERT INTO form_fields (committee_id, program_track, program_id, label, field_key, input_type, icon, width, is_required, options, sort_order) VALUES (?, 'assistance', ?, ?, ?, 'file', 'bi-upload', 'half', 1, NULL, ?)");
    foreach ($labels as $label) {
        $sort++;
        $key = slugifyFieldKey($label, $committeeId, 'assistance', $conn, $programId);
        $insert->bind_param('iissi', $committeeId, $programId, $label, $key, $sort);
        $insert->execute();
    }
    $insert->close();
}

// ---- Applying for both Cash and In-Kind ----
// Which assistance types this person has already applied for in a program (non-declined, non-archived
// applications). An application with no recognisable Type of Assistance answer counts as covering both.
function remainingAssistanceTypes($userId, $committeeId, $programId)
{
    global $conn;
    $fields = getFormFields($committeeId, 'assistance', $programId, true);
    $stmt = $conn->prepare("SELECT application_id FROM applications WHERE user_id = ? AND committee_id = ? AND program_id <=> ? AND status != 'declined' AND archived_at IS NULL");
    $stmt->bind_param('iii', $userId, $committeeId, $programId);
    $stmt->execute();
    $appIds = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'application_id');
    $stmt->close();

    $taken = ['cash' => false, 'in_kind' => false];
    foreach ($appIds as $appId) {
        $answer = answerByKey(['answers' => getApplicationAnswers((int)$appId), 'application_id' => (int)$appId], $fields, 'assistance_type');
        $hasInKind = stripos($answer, 'kind') !== false;
        $hasCash = stripos($answer, 'cash') !== false;
        if (!$hasInKind && !$hasCash) {
            $taken['cash'] = $taken['in_kind'] = true;
        } else {
            $taken['cash'] = $taken['cash'] || $hasCash;
            $taken['in_kind'] = $taken['in_kind'] || $hasInKind;
        }
    }
    return array_values(array_keys(array_filter($taken, fn($t) => !$t)));
}

// Server-side check that the chosen types are only ones still open to this applicant.
function validateAssistanceTypeChoice(array $post, $allowedTypes)
{
    if (!is_array($allowedTypes)) {
        return [];
    }
    $errors = [];
    $chosen = $post['assistance_type'] ?? [];
    foreach ((array)$chosen as $opt) {
        $type = (stripos($opt, 'kind') !== false) ? 'in_kind' : 'cash';
        if (!in_array($type, $allowedTypes, true)) {
            $errors[] = 'You already applied for ' . ($type === 'cash' ? 'Cash' : 'In-Kind') . ' Assistance in this program.';
            break;
        }
    }
    return $errors;
}

// Query string that keeps an admin program page on its program and its own sidebar menu (program_id for the
// data, ptab for which program's submenu stays open). Empty for the committee's general Assistance Program.
function programPtab($programId)
{
    if ($programId === null) {
        return 0;
    }
    $tab = getProgramTabByProgramId((int)$programId);
    return $tab ? (int)$tab['tab_id'] : 0;
}

function programPageQuery($programId, $prefix = '?')
{
    if ($programId === null) {
        return '';
    }
    $q = 'program_id=' . (int)$programId;
    $ptab = programPtab($programId);
    if ($ptab > 0) {
        $q .= '&ptab=' . $ptab;
    }
    return $prefix . $q;
}



// While someone applies for the second assistance type of a program (they already applied for the other),
// only the type still open to them is offered in the Type of Assistance question.
function assistanceOptionAllowed(array $field, $opt)
{
    global $allowedAssistanceTypes;
    if (!is_array($allowedAssistanceTypes) || !fieldMatchesKey($field, 'assistance_type')) {
        return true;
    }
    $type = (stripos($opt, 'kind') !== false) ? 'in_kind' : 'cash';
    return in_array($type, $allowedAssistanceTypes, true);
}

// Whether a program (or, with no program, the committee's general Assistance Program) lets people ask for
// both Cash and In-Kind assistance: a program set to "both", or a general form whose Type of Assistance
// question lists a Cash and an In-Kind choice.
function programOffersBothTypes($committeeId, $programId)
{
    if ($programId !== null) {
        $program = getProgramById((int)$programId);
        return $program && ($program['assistance_type'] ?? '') === 'both';
    }
    foreach (getFormFields($committeeId, 'assistance', null, true) as $field) {
        if (!fieldMatchesKey($field, 'assistance_type')) {
            continue;
        }
        $options = json_decode($field['options'] ?? '[]', true) ?: [];
        $hasCash = false;
        $hasKind = false;
        foreach ($options as $opt) {
            $hasCash = $hasCash || stripos($opt, 'cash') !== false;
            $hasKind = $hasKind || stripos($opt, 'kind') !== false;
        }
        return $hasCash && $hasKind;
    }
    return false;
}

// Built-in tabs (iSKolar / Assistance Program) can be archived like programs: program_tabs.archived_at.
function ensureProgramTabArchiveColumn()
{
    global $conn;
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $exists = $conn->query("SHOW COLUMNS FROM program_tabs LIKE 'archived_at'");
    if ($exists && $exists->num_rows === 0) {
        $conn->query("ALTER TABLE program_tabs ADD COLUMN archived_at DATETIME NULL");
    }
}

// ---- Release rounds (Monthly / Quarterly / Per Semester programs) ----
// A one-time program pays each beneficiary once. A recurring program pays in rounds: round 1 is the first
// payout, and an admin starts each next round with one button, which gives every beneficiary who is still
// eligible a new pending payout (and emails them the release schedule). Each payout is its own
// assistance_beneficiaries row, tagged with its round number.
function ensureBeneficiaryRoundColumn()
{
    global $conn;
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $exists = $conn->query("SHOW COLUMNS FROM assistance_beneficiaries LIKE 'round_no'");
    if ($exists && $exists->num_rows === 0) {
        $conn->query("ALTER TABLE assistance_beneficiaries ADD COLUMN round_no INT NOT NULL DEFAULT 1");
    }

    // A person now has one payout row per (application, type, round). Older databases still carry a UNIQUE key on
    // application_id alone (or on application_id + type), which blocks a second round. The new key is added first
    // because application_id is a foreign key and needs an index covering it before the old ones can be dropped.
    try {
        $hasNew = $conn->query("SHOW INDEX FROM assistance_beneficiaries WHERE Key_name = 'uniq_application_type_round'")->num_rows > 0;
        if (!$hasNew) {
            $conn->query("ALTER TABLE assistance_beneficiaries ADD UNIQUE KEY uniq_application_type_round (application_id, type, round_no)");
        }
        foreach (['application_id', 'uniq_application_type'] as $oldKey) {
            if ($conn->query("SHOW INDEX FROM assistance_beneficiaries WHERE Key_name = '$oldKey'")->num_rows > 0) {
                $conn->query("ALTER TABLE assistance_beneficiaries DROP INDEX `$oldKey`");
            }
        }
    } catch (Throwable $ex) {
        error_log('Beneficiary index update failed: ' . $ex->getMessage());
    }
}

function programIsRecurring($programId)
{
    if ($programId === null) {
        return false;
    }
    $program = getProgramById((int)$programId);
    return $program && in_array($program['release_schedule'] ?? '', ['Monthly', 'Quarterly', 'Per Semester'], true);
}

// The highest round number that already exists for a program and assistance type (1 when nothing yet).
function currentReleaseRound($committeeId, $programId, $type)
{
    global $conn;
    ensureBeneficiaryRoundColumn();
    $stmt = $conn->prepare("SELECT COALESCE(MAX(ab.round_no), 1) FROM assistance_beneficiaries ab
        JOIN applications a ON a.application_id = ab.application_id
        WHERE a.committee_id = ? AND a.program_id = ? AND ab.type = ?");
    $stmt->bind_param('iis', $committeeId, $programId, $type);
    $stmt->execute();
    $round = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return max(1, $round);
}

// Starts the next round. Returns ['ok' => bool, 'message' => string].
function startNextReleaseRound($committeeId, $programId, $type)
{
    global $conn;
    ensureBeneficiaryRoundColumn();
    if ($programId === null || !programIsRecurring($programId)) {
        return ['ok' => false, 'message' => 'This program is not a recurring (Monthly / Quarterly / Per Semester) program.'];
    }

    $stmt = $conn->prepare("SELECT ab.*, a.application_id AS app_id FROM assistance_beneficiaries ab
        JOIN applications a ON a.application_id = ab.application_id
        WHERE a.committee_id = ? AND a.program_id = ? AND ab.type = ? AND a.status = 'approved' AND a.archived_at IS NULL
        ORDER BY a.application_id ASC, ab.round_no DESC, ab.beneficiary_id DESC");
    $stmt->bind_param('iis', $committeeId, $programId, $type);
    $stmt->execute();
    $all = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $latest = [];
    foreach ($all as $row) {
        if (!isset($latest[$row['app_id']])) {
            $latest[$row['app_id']] = $row; // newest payout per application
        }
    }
    if (empty($latest)) {
        return ['ok' => false, 'message' => 'There are no beneficiaries yet, so there is no round to continue.'];
    }

    $current = max(array_map(fn($r) => (int)$r['round_no'], $latest));
    foreach ($latest as $row) {
        if ((int)$row['round_no'] === $current && $row['status'] === 'pending' && empty($row['archived_at'])) {
            return ['ok' => false, 'message' => 'Round ' . $current . ' still has payouts waiting for release. Release them (or archive the ones that will not be paid) before starting round ' . ($current + 1) . '.'];
        }
    }

    $next = $current + 1;
    $created = 0;
    $skipped = 0;
    foreach ($latest as $appId => $row) {
        if (!empty($row['archived_at'])) {
            $skipped++;
            continue;
        }
        $compliance = programActivityCompliance((int)$appId);
        if ($compliance !== null && !$compliance['ok']) {
            $skipped++; // no longer eligible for this program's activities
            continue;
        }

        if ($type === 'cash') {
            $amount = getProgramAmount($programId) ?? (float)$row['amount'];
            $ins = $conn->prepare("INSERT INTO assistance_beneficiaries (application_id, type, amount, status, round_no) VALUES (?, 'cash', ?, 'pending', ?)");
            $ins->bind_param('idi', $appId, $amount, $next);
        } else {
            $defaults = getProgramInKind($programId);
            $items = $defaults['items'] ?? $row['items'];
            $qty = $defaults['quantity'] ?? $row['quantity'];
            $ins = $conn->prepare("INSERT INTO assistance_beneficiaries (application_id, type, items, quantity, status, round_no) VALUES (?, 'in_kind', ?, ?, 'pending', ?)");
            $ins->bind_param('isii', $appId, $items, $qty, $next);
        }
        $ins->execute();
        $newId = $ins->insert_id;
        $ins->close();
        $created++;

        $fetch = $conn->prepare("SELECT * FROM assistance_beneficiaries WHERE beneficiary_id = ?");
        $fetch->bind_param('i', $newId);
        $fetch->execute();
        $newRow = $fetch->get_result()->fetch_assoc();
        $fetch->close();
        if ($newRow) {
            notifyBeneficiaryRelease((int)$appId, $newRow, false);
        }
    }

    logAudit('Started Release Round', 'Program #' . $programId . ' (' . ($type === 'cash' ? 'Cash' : 'In-Kind') . ') round ' . $next . ': ' . $created . ' added, ' . $skipped . ' skipped');
    if ($created === 0) {
        return ['ok' => false, 'message' => 'Nobody is eligible for round ' . $next . ' right now (' . $skipped . ' skipped).'];
    }
    return ['ok' => true, 'message' => 'Round ' . $next . ' started: ' . $created . ' beneficiar' . ($created === 1 ? 'y' : 'ies') . ' added with a pending payout and emailed' . ($skipped ? ' (' . $skipped . ' skipped - archived or no longer eligible)' : '') . '.'];
}
