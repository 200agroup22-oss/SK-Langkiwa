<?php
// Streams a PDF export for the "Generate Report" modal on AdminReports.php.
// GET params: type, committee, from, to — all validated/whitelisted below since they arrive
// directly from the query string. This endpoint outputs a file, not a redirect (the one
// documented exception to the redirect-after-POST convention used elsewhere in admin/).
require_once __DIR__ . '/../config/scholars.php';
require_once __DIR__ . '/../config/fpdf/fpdf.php';
requireRole(['admin', 'committee_admin']);
$me = currentUser();
$isSuperAdmin = $me['role'] === 'admin';
$myCommitteeIds = $isSuperAdmin ? [] : getUserCommitteeIds($me['user_id']);

// ---- Validate report type ----
$allowedTypes = ['consolidated', 'applicants', 'beneficiaries', 'financial', 'in-kind', 'disbursement', 'application-status', 'scholars', 'activity-log', 'audit-log'];
$type = $_GET['type'] ?? '';
if (!in_array($type, $allowedTypes, true)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Invalid report type.';
    exit();
}
// Activity/audit logs are system-wide, not scoped to any committee — a committee_admin never gets these.
if (!$isSuperAdmin && in_array($type, ['activity-log', 'audit-log'], true)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'Not available to Committee Admins.';
    exit();
}

// ---- Validate committee (must be 'all' or a real committee code) ----
$committeeCode = $_GET['committee'] ?? 'all';
$committeeId = 0; // sentinel for "all"
$committeeLabel = 'All Committees';
if ($committeeCode !== 'all') {
    $stmt = $conn->prepare("SELECT committee_id, name FROM committees WHERE code = ?");
    $stmt->bind_param('s', $committeeCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo 'Invalid committee.';
        exit();
    }
    $committeeId = (int)$row['committee_id'];
    $committeeLabel = $row['name'];
}

if (!$isSuperAdmin && (!$committeeId || !in_array($committeeId, $myCommitteeIds, true))) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'You can only generate reports for your assigned committee(s).';
    exit();
}

// ---- Validate program (must be 'all', "track:<code>" for a built-in track, or a real, active
// catalog program id — either way, scoped to the selected committee) ----
$programParam = $_GET['program'] ?? 'all';
$programId = 0; // sentinel for "no catalog-program filter" (used whether scope is 'all' or a track)
$trackCode = ''; // sentinel for "no built-in-track filter" (used whether scope is 'all' or a catalog program)
$programLabel = null;
if ($programParam !== 'all' && strpos($programParam, 'track:') === 0) {
    $trackCode = substr($programParam, 6);
    $stmt = $conn->prepare("SELECT label FROM program_tabs WHERE track_code = ? AND program_id IS NULL AND (? = 0 OR committee_id = ?)");
    $stmt->bind_param('sii', $trackCode, $committeeId, $committeeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo 'Invalid program.';
        exit();
    }
    $programLabel = $row['label'] . ' (Built-in Track)';
} elseif ($programParam !== 'all') {
    $stmt = $conn->prepare("SELECT program_id, name FROM programs WHERE program_id = ? AND status = 'active' AND archived_at IS NULL AND (? = 0 OR committee_id = ?)");
    $stmt->bind_param('iii', $programParam, $committeeId, $committeeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo 'Invalid program.';
        exit();
    }
    $programId = (int)$row['program_id'];
    $programLabel = $row['name'];
}

// ---- Validate from/to dates (YYYY-MM-DD) ----
function isValidDate($value)
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    [$y, $m, $d] = explode('-', $value);
    return checkdate((int)$m, (int)$d, (int)$y);
}

$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
if (!isValidDate($from) || !isValidDate($to)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Invalid date range. Use YYYY-MM-DD for both "from" and "to".';
    exit();
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
// Make the "to" bound inclusive of the whole day for datetime columns.
$toInclusive = $to . ' 23:59:59';
$fromInclusive = $from . ' 00:00:00';

$reportLabelOverrides = ['activity-log' => 'Activity Log', 'audit-log' => 'Audit Trail'];
$reportLabel = $reportLabelOverrides[$type] ?? ucfirst(str_replace('-', ' ', $type));
$scopeLabel = $committeeLabel . ($programLabel !== null ? ' — ' . $programLabel : '');
logAudit('Generated Report', $reportLabel . ' / ' . $scopeLabel . ' / ' . $from . ' to ' . $to);

// ---- PDF setup ----
class ReportPDF extends FPDF
{
    public $reportTitle = '';
    public $committeeLabel = '';
    public $programLabel = '';
    public $dateRange = '';
    public $logoPath = '';

    function Header()
    {
        $this->SetFillColor(69, 184, 77);
        $this->Rect(0, 0, $this->GetPageWidth(), 26, 'F');

        $textX = 10;
        if ($this->logoPath && file_exists($this->logoPath)) {
            $ext = strtolower(pathinfo($this->logoPath, PATHINFO_EXTENSION));
            $imageType = in_array($ext, ['jpg', 'jpeg'], true) ? 'JPG' : strtoupper($ext);
            $this->Image($this->logoPath, 8, 4, 18, 18, $imageType);
            $textX = 30;
        }

        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Helvetica', 'B', 15);
        $this->SetXY($textX, 5);
        $this->Cell(0, 8, pdfEnc(siteName() . ' - ' . $this->reportTitle), 0, 1);
        $this->SetFont('Helvetica', '', 9);
        $this->SetXY($textX, 13);
        $this->Cell(0, 5, pdfEnc('Committee: ' . $this->committeeLabel . '   |   Program: ' . $this->programLabel), 0, 1);
        $this->SetXY($textX, 18.5);
        $this->Cell(0, 5, pdfEnc('Period: ' . $this->dateRange), 0, 1);
        $this->SetTextColor(0, 0, 0);
        $this->SetY(32);
    }

    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 10, pdfEnc('Generated ' . date('Y-m-d H:i:s') . '   |   Page ' . $this->PageNo() . '/{nb}'), 0, 0, 'C');
    }
}

// FPDF's core fonts only support Windows-1252, not UTF-8 — without this, em dashes, curly
// quotes, and accented names (e.g. "Peña") render as mojibake ("â€”" etc.) in the PDF.
function pdfEnc($text)
{
    $text = (string)$text;
    $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
    return $converted !== false ? $converted : $text;
}

function fitText($pdf, $text, $width)
{
    $text = pdfEnc($text);
    if ($pdf->GetStringWidth($text) <= $width - 2) {
        return $text;
    }
    while (strlen($text) > 0 && $pdf->GetStringWidth($text . '...') > $width - 2) {
        $text = substr($text, 0, -1);
    }
    return $text . '...';
}

// Prints a section heading, adding a fresh page first if there isn't room left for it plus a row or two.
function sectionTitle($pdf, $text)
{
    if ($pdf->GetY() > $pdf->GetPageHeight() - 40) {
        $pdf->AddPage();
    } else {
        $pdf->Ln(4);
    }
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->SetTextColor(46, 125, 50);
    $pdf->Cell(0, 8, pdfEnc($text), 0, 1);
    $pdf->SetTextColor(30, 30, 30);
}

// Renders a bordered table with a repeating header row across page breaks.
function renderTable($pdf, $headers, $widths, $rows)
{
    $printHeader = function () use ($pdf, $headers, $widths) {
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->SetFillColor(169, 216, 171);
        $pdf->SetTextColor(20, 60, 20);
        foreach ($headers as $i => $h) {
            $pdf->Cell($widths[$i], 8, fitText($pdf, $h, $widths[$i]), 1, 0, 'L', true);
        }
        $pdf->Ln();
        $pdf->SetFont('Helvetica', '', 8.5);
        $pdf->SetTextColor(30, 30, 30);
    };

    $printHeader();

    if (empty($rows)) {
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Cell(array_sum($widths), 8, pdfEnc('No data for the selected filters.'), 1, 1, 'C');
        return;
    }

    $fill = false;
    foreach ($rows as $row) {
        if ($pdf->GetY() > $pdf->GetPageHeight() - 25) {
            $pdf->AddPage();
            $printHeader();
            $fill = false;
        }
        $pdf->SetFillColor($fill ? 245 : 255, $fill ? 250 : 255, $fill ? 245 : 255);
        foreach ($row as $i => $val) {
            $pdf->Cell($widths[$i], 7, fitText($pdf, $val, $widths[$i]), 1, 0, 'L', true);
        }
        $pdf->Ln();
        $fill = !$fill;
    }
}

// Prints a bold, right-aligned total line beneath a table (e.g. "Total Beneficiaries: 42").
function renderTotalLine($pdf, $text)
{
    if ($pdf->GetY() > $pdf->GetPageHeight() - 25) {
        $pdf->AddPage();
    }
    $pdf->Ln(2);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetTextColor(30, 30, 30);
    $pdf->Cell(0, 7, pdfEnc($text), 0, 1, 'R');
}

// Prints a formal "printed by" signature block (blank space for a pen signature, a line,
// then the printed name/position/date beneath it) at the end of the report.
function renderSignatureBlock($pdf, $name, $position)
{
    if ($pdf->GetY() > $pdf->GetPageHeight() - 45) {
        $pdf->AddPage();
    }
    $pdf->Ln(16);
    $blockWidth = 80;
    $x = $pdf->GetPageWidth() - 10 - $blockWidth;
    $y = $pdf->GetY();
    $pdf->Line($x, $y, $x + $blockWidth, $y);
    $pdf->SetXY($x, $y + 1);
    $pdf->SetFont('Helvetica', 'B', 10.5);
    $pdf->SetTextColor(30, 30, 30);
    $pdf->Cell($blockWidth, 6, pdfEnc(strtoupper($name)), 0, 2, 'C');
    $pdf->SetX($x);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell($blockWidth, 5, pdfEnc($position !== '' ? $position : 'System Administrator'), 0, 2, 'C');
    $pdf->SetX($x);
    $pdf->SetFont('Helvetica', 'I', 8);
    $pdf->Cell($blockWidth, 5, pdfEnc('Printed by / Generated on ' . date('F j, Y')), 0, 1, 'C');
}

$pdf = new ReportPDF('L', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->reportTitle = $reportLabel . ' Report';
$pdf->committeeLabel = $committeeLabel;
$pdf->programLabel = $programLabel !== null ? $programLabel : 'All Programs';
$pdf->dateRange = $from . ' to ' . $to;
$pdf->logoPath = siteLogoPath();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

switch ($type) {

    case 'consolidated': {
            $headers = ['Committee', 'Total Programs', 'Total Beneficiaries', 'Funds Released', 'In-Kind Items (Qty)', 'Approval Rate'];
            $widths = [70, 40, 45, 45, 45, 32];

            $committeesStmt = $conn->prepare("SELECT * FROM committees WHERE (? = 0 OR committee_id = ?) ORDER BY committee_id ASC");
            $committeesStmt->bind_param('ii', $committeeId, $committeeId);
            $committeesStmt->execute();
            $committeeRows = $committeesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $committeesStmt->close();

            $rows = [];
            $grandBenCount = 0;
            foreach ($committeeRows as $c) {
                $cid = (int)$c['committee_id'];

                $stmt = $conn->prepare("SELECT COUNT(*) c FROM programs WHERE committee_id = ? AND archived_at IS NULL");
                $stmt->bind_param('i', $cid);
                $stmt->execute();
                $totalPrograms = ($programId > 0 || $trackCode !== '') ? 1 : (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
                $stmt->close();

                $stmt = $conn->prepare("SELECT COUNT(*) c FROM applications WHERE committee_id = ? AND status = 'approved' AND archived_at IS NULL AND submitted_at BETWEEN ? AND ? AND (? = 0 OR program_id = ?) AND (? = '' OR (program_track = ? AND program_id IS NULL))");
                $stmt->bind_param('issiiss', $cid, $fromInclusive, $toInclusive, $programId, $programId, $trackCode, $trackCode);
                $stmt->execute();
                $benCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
                $stmt->close();

                $stmt = $conn->prepare("SELECT COALESCE(SUM(b.amount), 0) s FROM assistance_beneficiaries b JOIN applications a ON a.application_id = b.application_id WHERE a.committee_id = ? AND b.type = 'cash' AND b.status != 'pending' AND a.submitted_at BETWEEN ? AND ? AND (? = 0 OR a.program_id = ?) AND (? = '' OR (a.program_track = ? AND a.program_id IS NULL))");
                $stmt->bind_param('issiiss', $cid, $fromInclusive, $toInclusive, $programId, $programId, $trackCode, $trackCode);
                $stmt->execute();
                $funds = (float)($stmt->get_result()->fetch_assoc()['s'] ?? 0);
                $stmt->close();

                $stmt = $conn->prepare("SELECT COALESCE(SUM(b.quantity), 0) q FROM assistance_beneficiaries b JOIN applications a ON a.application_id = b.application_id WHERE a.committee_id = ? AND b.type = 'in_kind' AND b.status != 'pending' AND a.submitted_at BETWEEN ? AND ? AND (? = 0 OR a.program_id = ?) AND (? = '' OR (a.program_track = ? AND a.program_id IS NULL))");
                $stmt->bind_param('issiiss', $cid, $fromInclusive, $toInclusive, $programId, $programId, $trackCode, $trackCode);
                $stmt->execute();
                $inKindQty = (int)($stmt->get_result()->fetch_assoc()['q'] ?? 0);
                $stmt->close();

                $stmt = $conn->prepare("SELECT status, COUNT(*) c FROM applications WHERE committee_id = ? AND archived_at IS NULL AND submitted_at BETWEEN ? AND ? AND (? = 0 OR program_id = ?) AND (? = '' OR (program_track = ? AND program_id IS NULL)) GROUP BY status");
                $stmt->bind_param('issiiss', $cid, $fromInclusive, $toInclusive, $programId, $programId, $trackCode, $trackCode);
                $stmt->execute();
                $decided = ['approved' => 0, 'declined' => 0];
                foreach ($stmt->get_result() as $row) {
                    if (isset($decided[$row['status']])) $decided[$row['status']] = (int)$row['c'];
                }
                $stmt->close();
                $decidedTotal = $decided['approved'] + $decided['declined'];
                $approvalRate = $decidedTotal > 0 ? round(($decided['approved'] / $decidedTotal) * 100) . '%' : 'N/A';

                $rows[] = [$c['name'], $totalPrograms, $benCount, (string)number_format($funds, 2), $inKindQty, $approvalRate];
                $grandBenCount += $benCount;
            }

            renderTable($pdf, $headers, $widths, $rows);
            renderTotalLine($pdf, 'Total Beneficiaries (All Committees): ' . $grandBenCount);

            // ---- Appendix: full list of approved applicants/beneficiaries behind the summary above ----
            sectionTitle($pdf, 'Approved Applicants / Beneficiaries');
            $detailHeaders = ['Application ID', 'Full Name', 'Committee', 'Assistance', 'Amount / Items', 'Submitted At', 'Decided At'];
            $detailWidths = [25, 50, 45, 28, 50, 40, 39];

            $stmt = $conn->prepare("SELECT a.application_id, u.first_name, u.last_name, c.name AS committee_name, a.submitted_at, a.decided_at,
                b.type AS b_type, b.amount, b.items, b.quantity
            FROM applications a
            JOIN users u ON u.user_id = a.user_id
            JOIN committees c ON c.committee_id = a.committee_id
            LEFT JOIN assistance_beneficiaries b ON b.application_id = a.application_id
            WHERE a.status = 'approved' AND a.archived_at IS NULL AND a.submitted_at BETWEEN ? AND ? AND (? = 0 OR a.committee_id = ?) AND (? = 0 OR a.program_id = ?) AND (? = '' OR (a.program_track = ? AND a.program_id IS NULL))
            ORDER BY a.committee_id ASC, a.submitted_at ASC");
            $stmt->bind_param('ssiiiiss', $fromInclusive, $toInclusive, $committeeId, $committeeId, $programId, $programId, $trackCode, $trackCode);
            $stmt->execute();
            $detailRows = [];
            foreach ($stmt->get_result() as $row) {
                if ($row['b_type'] === 'cash') {
                    $assistance = 'Cash';
                    $amountItems = number_format((float)$row['amount'], 2);
                } elseif ($row['b_type'] === 'in_kind') {
                    $assistance = 'In-Kind';
                    $amountItems = trim(($row['items'] ?: '') . ' (' . (int)$row['quantity'] . ')');
                } else {
                    $assistance = 'Pending';
                    $amountItems = '—';
                }
                $detailRows[] = [
                    $row['application_id'],
                    trim($row['first_name'] . ' ' . $row['last_name']),
                    $row['committee_name'],
                    $assistance,
                    $amountItems,
                    $row['submitted_at'],
                    $row['decided_at'] ?: '—',
                ];
            }
            $stmt->close();

            renderTable($pdf, $detailHeaders, $detailWidths, $detailRows);
            break;
        }

    case 'applicants': {
            $headers = ['Application ID', 'Full Name', 'Email', 'Committee', 'Program', 'Status', 'Submitted At', 'Decided At'];
            $widths = [28, 42, 48, 33, 39, 25, 32, 30];

            // A blank program_id doesn't mean "no program" — it means the applicant applied through a
            // built-in track (iSKolar ng Langkiwa / Assistance Program) rather than a specific catalog
            // program, so program_track is what actually says which one. Fall back to that track's
            // label whenever there's no catalog program name to show.
            $stmt = $conn->prepare("SELECT a.application_id, u.first_name, u.last_name, u.email, c.name AS committee_name, p.name AS program_name, pt.label AS track_label, a.status, a.submitted_at, a.decided_at
            FROM applications a
            JOIN users u ON u.user_id = a.user_id
            JOIN committees c ON c.committee_id = a.committee_id
            LEFT JOIN programs p ON p.program_id = a.program_id
            LEFT JOIN program_tabs pt ON pt.committee_id = a.committee_id AND pt.track_code = a.program_track
            WHERE a.archived_at IS NULL AND a.submitted_at BETWEEN ? AND ? AND (? = 0 OR a.committee_id = ?) AND (? = 0 OR a.program_id = ?) AND (? = '' OR (a.program_track = ? AND a.program_id IS NULL))
            ORDER BY a.submitted_at ASC");
            $stmt->bind_param('ssiiiiss', $fromInclusive, $toInclusive, $committeeId, $committeeId, $programId, $programId, $trackCode, $trackCode);
            $stmt->execute();
            $rows = [];
            foreach ($stmt->get_result() as $row) {
                $rows[] = [
                    $row['application_id'],
                    trim($row['first_name'] . ' ' . $row['last_name']),
                    $row['email'],
                    $row['committee_name'],
                    $row['program_name'] ?: ($row['track_label'] ?: '—'),
                    ucfirst($row['status']),
                    $row['submitted_at'],
                    $row['decided_at'] ?: '—',
                ];
            }
            $stmt->close();

            renderTable($pdf, $headers, $widths, $rows);
            renderTotalLine($pdf, 'Total Applicants: ' . count($rows));
            break;
        }

    case 'beneficiaries': {
            $headers = ['Application ID', 'Full Name', 'Email', 'Committee', 'Status', 'Submitted At', 'Decided At'];
            $widths = [25, 50, 60, 45, 30, 35, 32];

            $stmt = $conn->prepare("SELECT a.application_id, u.first_name, u.last_name, u.email, c.name AS committee_name, a.status, a.submitted_at, a.decided_at
            FROM applications a
            JOIN users u ON u.user_id = a.user_id
            JOIN committees c ON c.committee_id = a.committee_id
            WHERE a.status = 'approved' AND a.archived_at IS NULL AND a.submitted_at BETWEEN ? AND ? AND (? = 0 OR a.committee_id = ?) AND (? = 0 OR a.program_id = ?) AND (? = '' OR (a.program_track = ? AND a.program_id IS NULL))
            ORDER BY a.submitted_at ASC");
            $stmt->bind_param('ssiiiiss', $fromInclusive, $toInclusive, $committeeId, $committeeId, $programId, $programId, $trackCode, $trackCode);
            $stmt->execute();
            $rows = [];
            foreach ($stmt->get_result() as $row) {
                $rows[] = [
                    $row['application_id'],
                    trim($row['first_name'] . ' ' . $row['last_name']),
                    $row['email'],
                    $row['committee_name'],
                    ucfirst($row['status']),
                    $row['submitted_at'],
                    $row['decided_at'] ?: '—',
                ];
            }
            $stmt->close();

            renderTable($pdf, $headers, $widths, $rows);
            renderTotalLine($pdf, 'Total Beneficiaries: ' . count($rows));
            break;
        }

    case 'financial': {
            $headers = ['Beneficiary ID', 'Full Name', 'Committee', 'Amount', 'Status', 'Date Released', 'Date Distributed'];
            $widths = [28, 55, 45, 35, 30, 42, 42];

            $stmt = $conn->prepare("SELECT b.beneficiary_id, u.first_name, u.last_name, c.name AS committee_name, b.amount, b.status, b.date_released, b.date_distributed
            FROM assistance_beneficiaries b
            JOIN applications a ON a.application_id = b.application_id
            JOIN users u ON u.user_id = a.user_id
            JOIN committees c ON c.committee_id = a.committee_id
            WHERE b.type = 'cash' AND a.submitted_at BETWEEN ? AND ? AND (? = 0 OR a.committee_id = ?) AND (? = 0 OR a.program_id = ?) AND (? = '' OR (a.program_track = ? AND a.program_id IS NULL))
            ORDER BY b.beneficiary_id ASC");
            $stmt->bind_param('ssiiiiss', $fromInclusive, $toInclusive, $committeeId, $committeeId, $programId, $programId, $trackCode, $trackCode);
            $stmt->execute();
            $rows = [];
            foreach ($stmt->get_result() as $row) {
                $rows[] = [
                    $row['beneficiary_id'],
                    trim($row['first_name'] . ' ' . $row['last_name']),
                    $row['committee_name'],
                    number_format((float)$row['amount'], 2),
                    ucfirst($row['status']),
                    $row['date_released'] ?: '—',
                    $row['date_distributed'] ?: '—',
                ];
            }
            $stmt->close();

            renderTable($pdf, $headers, $widths, $rows);
            renderTotalLine($pdf, 'Total Beneficiaries: ' . count($rows));
            break;
        }

    case 'in-kind': {
            $headers = ['Beneficiary ID', 'Full Name', 'Committee', 'Items', 'Quantity', 'Status', 'Date Distributed'];
            $widths = [25, 50, 40, 62, 25, 30, 45];

            $stmt = $conn->prepare("SELECT b.beneficiary_id, u.first_name, u.last_name, c.name AS committee_name, b.items, b.quantity, b.status, b.date_distributed
            FROM assistance_beneficiaries b
            JOIN applications a ON a.application_id = b.application_id
            JOIN users u ON u.user_id = a.user_id
            JOIN committees c ON c.committee_id = a.committee_id
            WHERE b.type = 'in_kind' AND a.submitted_at BETWEEN ? AND ? AND (? = 0 OR a.committee_id = ?) AND (? = 0 OR a.program_id = ?) AND (? = '' OR (a.program_track = ? AND a.program_id IS NULL))
            ORDER BY b.beneficiary_id ASC");
            $stmt->bind_param('ssiiiiss', $fromInclusive, $toInclusive, $committeeId, $committeeId, $programId, $programId, $trackCode, $trackCode);
            $stmt->execute();
            $rows = [];
            foreach ($stmt->get_result() as $row) {
                $rows[] = [
                    $row['beneficiary_id'],
                    trim($row['first_name'] . ' ' . $row['last_name']),
                    $row['committee_name'],
                    $row['items'],
                    $row['quantity'],
                    ucfirst($row['status']),
                    $row['date_distributed'] ?: '—',
                ];
            }
            $stmt->close();

            renderTable($pdf, $headers, $widths, $rows);
            renderTotalLine($pdf, 'Total Beneficiaries: ' . count($rows));
            break;
        }

    case 'disbursement': {
            // Beneficiaries whose assistance has actually been released — for a "Both" (cash + in-kind)
            // program, that means BOTH portions must be released, not just one; for a single-type
            // program (or a base track with no catalog program), whichever one portion they have just
            // needs to be released. One row per application, aggregating its up-to-2 beneficiary records.
            $headers = ['Applicant ID', 'Full Name', 'Committee', 'Type', 'Cash Amount', 'Cash Released', 'In-Kind Items (Qty)', 'In-Kind Distributed'];
            $widths = [22, 48, 40, 30, 32, 32, 45, 28];

            $stmt = $conn->prepare("SELECT a.application_id, u.first_name, u.last_name, c.name AS committee_name, p.assistance_type AS program_assistance_type,
                MAX(CASE WHEN b.type = 'cash' THEN b.amount END) AS cash_amount,
                MAX(CASE WHEN b.type = 'cash' THEN b.status END) AS cash_status,
                MAX(CASE WHEN b.type = 'cash' THEN b.date_released END) AS cash_date,
                MAX(CASE WHEN b.type = 'in_kind' THEN b.items END) AS inkind_items,
                MAX(CASE WHEN b.type = 'in_kind' THEN b.quantity END) AS inkind_qty,
                MAX(CASE WHEN b.type = 'in_kind' THEN b.status END) AS inkind_status,
                MAX(CASE WHEN b.type = 'in_kind' THEN b.date_distributed END) AS inkind_date
            FROM applications a
            JOIN assistance_beneficiaries b ON b.application_id = a.application_id
            JOIN users u ON u.user_id = a.user_id
            JOIN committees c ON c.committee_id = a.committee_id
            LEFT JOIN programs p ON p.program_id = a.program_id
            WHERE a.status = 'approved' AND a.archived_at IS NULL AND a.submitted_at BETWEEN ? AND ? AND (? = 0 OR a.committee_id = ?) AND (? = 0 OR a.program_id = ?) AND (? = '' OR (a.program_track = ? AND a.program_id IS NULL))
            GROUP BY a.application_id
            ORDER BY a.application_id ASC");
            $stmt->bind_param('ssiiiiss', $fromInclusive, $toInclusive, $committeeId, $committeeId, $programId, $programId, $trackCode, $trackCode);
            $stmt->execute();
            $releasedStatuses = ['released', 'distributed'];
            $rows = [];
            foreach ($stmt->get_result() as $row) {
                $cashPresent = $row['cash_status'] !== null;
                $inKindPresent = $row['inkind_status'] !== null;
                $cashReleased = in_array($row['cash_status'], $releasedStatuses, true);
                $inKindReleased = in_array($row['inkind_status'], $releasedStatuses, true);

                if ($row['program_assistance_type'] === 'both') {
                    if (!($cashPresent && $cashReleased && $inKindPresent && $inKindReleased)) {
                        continue;
                    }
                } else {
                    if (!$cashPresent && !$inKindPresent) continue;
                    if ($cashPresent && !$cashReleased) continue;
                    if ($inKindPresent && !$inKindReleased) continue;
                }

                $typeLabel = ($cashPresent && $inKindPresent) ? 'Cash + In-Kind' : ($cashPresent ? 'Cash' : 'In-Kind');

                $rows[] = [
                    $row['application_id'],
                    trim($row['first_name'] . ' ' . $row['last_name']),
                    $row['committee_name'],
                    $typeLabel,
                    $cashPresent ? number_format((float)$row['cash_amount'], 2) : '—',
                    $cashPresent ? ($row['cash_date'] ?: '—') : '—',
                    $inKindPresent ? ($row['inkind_items'] . ' (' . $row['inkind_qty'] . ')') : '—',
                    $inKindPresent ? ($row['inkind_date'] ?: '—') : '—',
                ];
            }
            $stmt->close();

            renderTable($pdf, $headers, $widths, $rows);
            renderTotalLine($pdf, 'Total Beneficiaries: ' . count($rows));
            break;
        }

    case 'application-status': {
            $headers = ['Committee', 'Pending', 'Approved', 'Declined', 'Total'];
            $widths = [95, 45, 45, 45, 47];

            $committeesStmt = $conn->prepare("SELECT * FROM committees WHERE (? = 0 OR committee_id = ?) ORDER BY committee_id ASC");
            $committeesStmt->bind_param('ii', $committeeId, $committeeId);
            $committeesStmt->execute();
            $committeeRows = $committeesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $committeesStmt->close();

            $rows = [];
            $grandApproved = 0;
            foreach ($committeeRows as $c) {
                $cid = (int)$c['committee_id'];
                $stmt = $conn->prepare("SELECT status, COUNT(*) c FROM applications WHERE committee_id = ? AND archived_at IS NULL AND submitted_at BETWEEN ? AND ? AND (? = 0 OR program_id = ?) AND (? = '' OR (program_track = ? AND program_id IS NULL)) GROUP BY status");
                $stmt->bind_param('issiiss', $cid, $fromInclusive, $toInclusive, $programId, $programId, $trackCode, $trackCode);
                $stmt->execute();
                $counts = ['pending' => 0, 'approved' => 0, 'declined' => 0];
                foreach ($stmt->get_result() as $row) {
                    if (isset($counts[$row['status']])) $counts[$row['status']] = (int)$row['c'];
                }
                $stmt->close();
                $total = $counts['pending'] + $counts['approved'] + $counts['declined'];
                $rows[] = [$c['name'], $counts['pending'], $counts['approved'], $counts['declined'], $total];
                $grandApproved += $counts['approved'];
            }

            renderTable($pdf, $headers, $widths, $rows);
            renderTotalLine($pdf, 'Total Beneficiaries (Approved): ' . $grandApproved);

            // ---- Appendix: full applicant list (every status) behind the counts above ----
            sectionTitle($pdf, 'Applicant List (All Statuses)');
            $detailHeaders = ['Application ID', 'Full Name', 'Committee', 'Status', 'Submitted At', 'Decided At'];
            $detailWidths = [28, 60, 50, 33, 53, 53];

            $stmt = $conn->prepare("SELECT a.application_id, u.first_name, u.last_name, c.name AS committee_name, a.status, a.submitted_at, a.decided_at
            FROM applications a
            JOIN users u ON u.user_id = a.user_id
            JOIN committees c ON c.committee_id = a.committee_id
            WHERE a.archived_at IS NULL AND a.submitted_at BETWEEN ? AND ? AND (? = 0 OR a.committee_id = ?) AND (? = 0 OR a.program_id = ?) AND (? = '' OR (a.program_track = ? AND a.program_id IS NULL))
            ORDER BY a.committee_id ASC, a.submitted_at ASC");
            $stmt->bind_param('ssiiiiss', $fromInclusive, $toInclusive, $committeeId, $committeeId, $programId, $programId, $trackCode, $trackCode);
            $stmt->execute();
            $detailRows = [];
            foreach ($stmt->get_result() as $row) {
                $detailRows[] = [
                    $row['application_id'],
                    trim($row['first_name'] . ' ' . $row['last_name']),
                    $row['committee_name'],
                    ucfirst($row['status']),
                    $row['submitted_at'],
                    $row['decided_at'] ?: '—',
                ];
            }
            $stmt->close();

            renderTable($pdf, $detailHeaders, $detailWidths, $detailRows);
            break;
        }

    case 'scholars': {
            $headers = ['Scholar ID', 'Full Name', 'School', 'Course', 'Year Level', 'Activities (Present/Held)', 'Eligibility', 'Allowance Amount'];
            $widths = [22, 48, 45, 40, 20, 45, 32, 25];

            // Activity counts are scoped to the report's date range (an activity's actual
            // activity_date), not just the current academic term, so this reflects real
            // participation history over whatever period was picked.
            $stmt = $conn->prepare("SELECT s.scholar_id, u.first_name, u.last_name, s.school, s.course, s.year_level,
                (SELECT COUNT(*) FROM attendance att JOIN activities act ON act.activity_id = att.activity_id
                    WHERE att.scholar_id = s.scholar_id AND act.activity_date BETWEEN ? AND ? AND act.archived_at IS NULL) AS total_activities,
                (SELECT COUNT(*) FROM attendance att JOIN activities act ON act.activity_id = att.activity_id
                    WHERE att.scholar_id = s.scholar_id AND att.status = 'present' AND act.activity_date BETWEEN ? AND ? AND act.archived_at IS NULL) AS present_count
            FROM scholars s
            JOIN users u ON u.user_id = s.user_id
            WHERE s.status = 'active'
            ORDER BY s.scholar_id ASC");
            $stmt->bind_param('ssss', $from, $to, $from, $to);
            $stmt->execute();
            $scholarRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $yearLevelLabel = fn($n) => $n ? $n . (['', 'st', 'nd', 'rd'][$n] ?? 'th') . ' Year' : '—';

            $rows = [];
            $totalPresent = 0;
            $totalHeld = 0;
            foreach ($scholarRows as $row) {
                $scholarId = (int)$row['scholar_id'];
                $allowance = ensureAllowanceRecord($scholarId);
                $rows[] = [
                    str_pad($scholarId, 3, '0', STR_PAD_LEFT),
                    trim($row['first_name'] . ' ' . $row['last_name']),
                    $row['school'] ?: '—',
                    $row['course'] ?: '—',
                    $yearLevelLabel((int)$row['year_level']),
                    $row['present_count'] . ' / ' . $row['total_activities'],
                    ucfirst(str_replace('_', ' ', $allowance['eligibility'])),
                    number_format((float)$allowance['amount'], 2),
                ];
                $totalPresent += (int)$row['present_count'];
                $totalHeld += (int)$row['total_activities'];
            }

            renderTable($pdf, $headers, $widths, $rows);
            renderTotalLine($pdf, 'Total Scholars: ' . count($rows) . '   |   Activity Participation: ' . $totalPresent . ' / ' . $totalHeld);
            break;
        }

        // Activity/audit logs are system-wide (not tied to a committee or program), so the
        // committee/program filters are locked to "all" on the modal and ignored here.
    case 'activity-log': {
            $headers = ['Log ID', 'Full Name', 'Email', 'Role', 'Logged In At', 'Logged Out At'];
            $widths = [20, 55, 65, 35, 55, 47];

            $stmt = $conn->prepare("SELECT log_id, full_name, email, role, logged_in_at, logged_out_at
            FROM activity_logs WHERE logged_in_at BETWEEN ? AND ? ORDER BY logged_in_at ASC");
            $stmt->bind_param('ss', $fromInclusive, $toInclusive);
            $stmt->execute();
            $rows = [];
            foreach ($stmt->get_result() as $row) {
                $rows[] = [
                    $row['log_id'],
                    $row['full_name'],
                    $row['email'],
                    ucfirst($row['role']),
                    $row['logged_in_at'],
                    $row['logged_out_at'] ?: '—',
                ];
            }
            $stmt->close();

            renderTable($pdf, $headers, $widths, $rows);
            renderTotalLine($pdf, 'Total Logins: ' . count($rows));
            break;
        }

    case 'audit-log': {
            $headers = ['Log ID', 'Full Name', 'Email', 'Action', 'Details', 'Date/Time'];
            $widths = [20, 50, 60, 45, 60, 42];

            $stmt = $conn->prepare("SELECT log_id, full_name, email, action, details, created_at
            FROM audit_logs WHERE created_at BETWEEN ? AND ? ORDER BY created_at ASC");
            $stmt->bind_param('ss', $fromInclusive, $toInclusive);
            $stmt->execute();
            $rows = [];
            foreach ($stmt->get_result() as $row) {
                $rows[] = [
                    $row['log_id'],
                    $row['full_name'],
                    $row['email'],
                    $row['action'],
                    $row['details'] ?: '—',
                    $row['created_at'],
                ];
            }
            $stmt->close();

            renderTable($pdf, $headers, $widths, $rows);
            renderTotalLine($pdf, 'Total Audit Events: ' . count($rows));
            break;
        }
}

// ---- Formal "printed by" signature block, using the generating admin's name/position ----
$printedByName = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
$stmt = $conn->prepare("SELECT position_title FROM users WHERE user_id = ?");
$stmt->bind_param('i', $me['user_id']);
$stmt->execute();
$positionTitle = (string)($stmt->get_result()->fetch_assoc()['position_title'] ?? '');
$stmt->close();
renderSignatureBlock($pdf, $printedByName !== '' ? $printedByName : 'Administrator', $positionTitle);

$filename = 'sk-langkiwa-report-' . $type . '-' . date('Ymd-His') . '.pdf';
$pdf->Output('D', $filename);
exit();
