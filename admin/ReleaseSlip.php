<?php
// Printable release slip for one Cash or In-Kind payout: who gets what, where and when, with signature lines
// for the beneficiary and the releasing officer. Used for every beneficiary, and handed to walk-in applicants
// who have no account to be notified through. Opened from the "Slip" button on the Cash / In-Kind pages.
require_once __DIR__ . '/../config/forms.php';
requireRole(['admin', 'committee_admin']);

$beneficiaryId = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT ab.*, a.committee_id, a.program_id, a.application_id, a.program_track,
        u.first_name, u.last_name, u.middle_name, u.email, u.user_id, c.name AS committee_name
    FROM assistance_beneficiaries ab
    JOIN applications a ON a.application_id = ab.application_id
    JOIN users u ON u.user_id = a.user_id
    JOIN committees c ON c.committee_id = a.committee_id
    WHERE ab.beneficiary_id = ?");
$stmt->bind_param('i', $beneficiaryId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(404);
    echo 'Release slip not found.';
    exit();
}
requireCommitteeAccess((int)$row['committee_id']);

// contact number (optional column, only present once a walk-in was added with one)
$contact = '';
$col = $conn->query("SHOW COLUMNS FROM users LIKE 'contact_number'");
if ($col && $col->num_rows > 0) {
    $stmt = $conn->prepare("SELECT contact_number FROM users WHERE user_id = ?");
    $stmt->bind_param('i', $row['user_id']);
    $stmt->execute();
    $contact = (string)($stmt->get_result()->fetch_row()[0] ?? '');
    $stmt->close();
}

$program = getProgramById((int)$row['program_id'], (int)$row['committee_id']);
$programName = $program['name'] ?? ($row['committee_name'] . ' Assistance Program');
$isCash = $row['type'] === 'cash';
$what = $isCash
    ? 'PHP ' . number_format((float)$row['amount'], 2)
    : trim(($row['items'] ?? '') . (!empty($row['quantity']) ? ' x ' . (int)$row['quantity'] : ''));
$releaseDate = !empty($program['release_date']) ? date('F j, Y', strtotime($program['release_date'])) : 'To be announced';
$releaseTime = !empty($program['release_time']) ? date('g:i A', strtotime($program['release_time'])) : '';
$venue = !empty($program['release_venue']) ? $program['release_venue'] : 'To be announced';
$fullName = trim($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'] . ' ' : '') . $row['last_name']);
$isWalkIn = substr((string)$row['email'], -12) === '@walkin.local';
$status = $row['status'] === 'released' ? 'Released' : 'Pending release';
$dateReleased = !empty($row['date_released']) ? date('F j, Y', strtotime($row['date_released'])) : '';
$slipNo = 'RS-' . str_pad((string)$row['beneficiary_id'], 5, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Release Slip <?php echo e($slipNo); ?></title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            color: #222;
            margin: 0;
            background: #f0f0f0;
        }

        .toolbar {
            max-width: 760px;
            margin: 14px auto 0;
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            padding: 0 12px;
        }

        .toolbar button,
        .toolbar a {
            background: #45b84d;
            color: #fff;
            border: 0;
            border-radius: 6px;
            padding: 8px 16px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
        }

        .toolbar a {
            background: #6c757d;
        }

        .slip {
            max-width: 760px;
            margin: 12px auto 24px;
            background: #fff;
            padding: 28px 34px;
            border: 1px solid #ddd;
        }

        .head {
            display: flex;
            align-items: center;
            gap: 14px;
            border-bottom: 3px solid #45b84d;
            padding-bottom: 12px;
        }

        .head img {
            width: 62px;
            height: 62px;
            border-radius: 50%;
            object-fit: cover;
        }

        .head h1 {
            font-size: 18px;
            margin: 0;
            color: #2e7d32;
        }

        .head p {
            margin: 2px 0 0;
            font-size: 12px;
            color: #666;
        }

        h2 {
            text-align: center;
            letter-spacing: 2px;
            font-size: 20px;
            margin: 18px 0 4px;
        }

        .no {
            text-align: center;
            font-size: 12px;
            color: #666;
            margin-bottom: 16px;
        }

        table.info {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        table.info td {
            padding: 7px 10px;
            border: 1px solid #ccc;
            vertical-align: top;
        }

        table.info td.l {
            width: 32%;
            background: #e8f5e9;
            font-weight: bold;
            color: #333;
        }

        .remind {
            margin: 14px 0;
            padding: 9px 12px;
            background: #fff8e1;
            border: 1px solid #ffe082;
            font-size: 13px;
        }

        .sign {
            display: flex;
            gap: 40px;
            margin-top: 46px;
        }

        .sign>div {
            flex: 1;
            text-align: center;
            font-size: 12px;
        }

        .sign .line {
            border-top: 1px solid #333;
            margin-top: 38px;
            padding-top: 4px;
        }

        .box {
            display: inline-block;
            width: 13px;
            height: 13px;
            border: 1px solid #333;
            vertical-align: middle;
            margin-right: 6px;
        }

        .foot {
            margin-top: 22px;
            font-size: 11px;
            color: #777;
            text-align: center;
        }

        @media print {
            body {
                background: #fff;
            }

            .toolbar {
                display: none;
            }

            .slip {
                border: 0;
                margin: 0;
                max-width: none;
                padding: 10px 6px;
            }
        }
    </style>
</head>

<body>
    <div class="toolbar">
        <a href="javascript:window.close()">Close</a>
        <button onclick="window.print()">Print slip</button>
    </div>

    <div class="slip">
        <div class="head">
            <img src="<?php echo siteLogoUrl(); ?>" alt="Logo">
            <div>
                <h1><?php echo e(siteName()); ?></h1>
                <p><?php echo e($row['committee_name']); ?> Committee</p>
            </div>
        </div>

        <h2>RELEASE SLIP</h2>
        <div class="no">Slip No. <?php echo e($slipNo); ?> &middot; Printed <?php echo e(date('F j, Y g:i A')); ?></div>

        <table class="info">
            <tr>
                <td class="l">Beneficiary</td>
                <td><?php echo e($fullName); ?><?php echo $isWalkIn ? ' (walk-in)' : ''; ?></td>
            </tr>
            <tr>
                <td class="l">Applicant ID</td>
                <td><?php echo e(str_pad((string)$row['application_id'], 3, '0', STR_PAD_LEFT)); ?></td>
            </tr>
            <?php if ($contact !== ''): ?>
                <tr>
                    <td class="l">Contact number</td>
                    <td><?php echo e($contact); ?></td>
                </tr>
            <?php endif; ?>
            <tr>
                <td class="l">Program</td>
                <td><?php echo e($programName); ?></td>
            </tr>
            <tr>
                <td class="l">Type of assistance</td>
                <td><?php echo $isCash ? 'Cash Assistance' : 'In-Kind Assistance'; ?><?php echo (int)($row['round_no'] ?? 1) > 1 ? ' (Round ' . (int)$row['round_no'] . ')' : ''; ?></td>
            </tr>
            <tr>
                <td class="l"><?php echo $isCash ? 'Amount' : 'Items and quantity'; ?></td>
                <td><strong><?php echo e($what); ?></strong></td>
            </tr>
            <tr>
                <td class="l">Release date</td>
                <td><?php echo e($releaseDate . ($releaseTime !== '' ? ' at ' . $releaseTime : '')); ?></td>
            </tr>
            <tr>
                <td class="l">Venue</td>
                <td><?php echo e($venue); ?></td>
            </tr>
            <tr>
                <td class="l">Status</td>
                <td><?php echo e($status . ($dateReleased !== '' ? ' on ' . $dateReleased : '')); ?></td>
            </tr>
        </table>

        <div class="remind">Please bring <strong><?php echo e(releaseRequirementsText($program)); ?></strong> when you claim your assistance. The officer will check it before handing over the assistance.</div>

        <div><span class="box"></span>Valid ID checked &nbsp;&nbsp;&nbsp; ID presented: ______________________________</div>

        <div class="sign">
            <div>
                <div class="line">Signature of beneficiary over printed name</div>
                <div style="margin-top:10px;">Date received: ____________________</div>
            </div>
            <div>
                <div class="line">Releasing SK officer over printed name</div>
                <div style="margin-top:10px;">Date released: ____________________</div>
            </div>
        </div>

        <div class="foot">This slip is a record of the release. Keep it with the barangay release records.</div>
    </div>
</body>

</html>