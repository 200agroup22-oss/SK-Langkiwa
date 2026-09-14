<?php
require_once __DIR__ . '/forms.php';

function getCurrentTerm()
{
    global $conn;
    $row = $conn->query("SELECT current_academic_year, current_semester, requirements_deadline, requirements_open, activities_required_per_term, default_allowance_amount FROM site_settings WHERE id = 1")->fetch_assoc();
    return $row;
}

// Whether scholars can currently submit updated requirements: the window has to have been
// opened (via End Semester, or by hand in Site Settings) and, if a deadline was set, it hasn't
// passed yet. A blank deadline leaves the window open indefinitely once opened.
function isRequirementsWindowOpen($term)
{
    if (empty($term['requirements_open'])) {
        return false;
    }
    if (!empty($term['requirements_deadline']) && strtotime($term['requirements_deadline']) < strtotime('today')) {
        return false;
    }
    return true;
}

// Walks the term forward one step. The regular cycle is just 1st Semester -> 2nd Semester ->
// 1st Semester of the next academic year (e.g. 2025-2026 2nd Semester -> 2026-2027 1st
// Semester). 3rd Semester / Summer are still selectable by hand in Site Settings for schools
// that run a summer term, but they're a side branch, not part of the auto-advance cycle — ending
// one of those also rolls straight into next year's 1st Semester.
function getNextTerm($academicYear, $semester)
{
    if ($semester === '1st Semester') {
        return ['academic_year' => $academicYear, 'semester' => '2nd Semester'];
    }

    $nextYear = $academicYear;
    if (preg_match('/^(\d{4})-(\d{4})$/', $academicYear, $m)) {
        $nextYear = ((int)$m[1] + 1) . '-' . ((int)$m[2] + 1);
    }
    return ['academic_year' => $nextYear, 'semester' => '1st Semester'];
}

function countPresentActivities($scholarId, $academicYear, $semester)
{
    global $conn;
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM attendance att
        JOIN activities a ON a.activity_id = att.activity_id
        WHERE att.scholar_id = ? AND att.status = 'present' AND a.academic_year = ? AND a.semester = ?");
    $stmt->bind_param('iss', $scholarId, $academicYear, $semester);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)$row['c'];
}

// How many activities a scholar needs to attend this term is just how many the admin has
// actually posted for it (Education > Activities) — not a fixed number — so this counts real,
// non-archived activity rows instead of reading a hardcoded setting.
function countTotalActivities($committeeId, $academicYear, $semester)
{
    global $conn;
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM activities WHERE committee_id = ? AND academic_year = ? AND semester = ? AND archived_at IS NULL");
    $stmt->bind_param('iss', $committeeId, $academicYear, $semester);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)$row['c'];
}

function countAssignedActivities($scholarId, $academicYear, $semester)
{
    global $conn;
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM attendance att
        JOIN activities a ON a.activity_id = att.activity_id
        WHERE att.scholar_id = ? AND a.academic_year = ? AND a.semester = ?");
    $stmt->bind_param('iss', $scholarId, $academicYear, $semester);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)$row['c'];
}

// Fetches (creating if needed) a scholar's allowance_distributions row for the current term,
// and recomputes activities_completed/eligibility from live attendance data.
function ensureAllowanceRecord($scholarId)
{
    global $conn;
    $term = getCurrentTerm();
    $ay = $term['current_academic_year'];
    $sem = $term['current_semester'];
    $educationCommitteeId = getCommitteeIdByCode('education');
    $required = countTotalActivities($educationCommitteeId, $ay, $sem);

    $stmt = $conn->prepare("SELECT * FROM allowance_distributions WHERE scholar_id = ? AND academic_year = ? AND semester = ?");
    $stmt->bind_param('iss', $scholarId, $ay, $sem);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$record) {
        $stmt = $conn->prepare("INSERT INTO allowance_distributions (scholar_id, academic_year, semester, activities_required, activities_completed, eligibility, amount, status) VALUES (?, ?, ?, ?, 0, 'pending', ?, 'pending')");
        $stmt->bind_param('issid', $scholarId, $ay, $sem, $required, $term['default_allowance_amount']);
        $stmt->execute();
        $stmt->close();
    }

    $completed = countPresentActivities($scholarId, $ay, $sem);

    // An admin's explicit eligible/not_eligible call (via "Set Eligibility") is a manual decision
    // and must stick — tracked via eligibility_is_manual, not by the eligibility value itself.
    // Checking the value alone was the earlier bug: once a scholar auto-computed to 'eligible'
    // (e.g. 1/1 before a 2nd activity existed), it froze there forever and never dropped back to
    // 'pending' even after more activities got posted and she fell behind (1/2) — letting her get
    // approved despite not actually meeting the current requirement. Only a real manual override
    // should resist the live completed-vs-required recompute; an auto-derived value never should.
    if ($record && !empty($record['eligibility_is_manual'])) {
        $eligibility = $record['eligibility'];
    } else {
        $eligibility = ($completed >= $required) ? 'eligible' : 'pending';
    }

    // activities_required is kept live (not frozen at creation time) so it always reflects
    // however many activities are currently posted for the term, growing or shrinking as the
    // admin adds/archives them. Don't downgrade a term admin already decided on (approved/
    // declined payout) back to 'pending' eligibility text.
    if ($record && $record['status'] !== 'pending') {
        $stmt = $conn->prepare("UPDATE allowance_distributions SET activities_completed = ?, activities_required = ? WHERE scholar_id = ? AND academic_year = ? AND semester = ?");
        $stmt->bind_param('iiiss', $completed, $required, $scholarId, $ay, $sem);
    } else {
        $stmt = $conn->prepare("UPDATE allowance_distributions SET activities_completed = ?, activities_required = ?, eligibility = ? WHERE scholar_id = ? AND academic_year = ? AND semester = ?");
        $stmt->bind_param('iisiss', $completed, $required, $eligibility, $scholarId, $ay, $sem);
    }
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("SELECT * FROM allowance_distributions WHERE scholar_id = ? AND academic_year = ? AND semester = ?");
    $stmt->bind_param('iss', $scholarId, $ay, $sem);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $record;
}

function getScholarByUserId($userId)
{
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM scholars WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
}
