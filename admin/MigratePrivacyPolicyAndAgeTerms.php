<?php
require_once __DIR__ . '/../config/forms.php';
requireRole('admin');

// One-time fix, two parts:
// 1. Adds site_settings.privacy_policy (TEXT), seeded with a default policy if currently empty, so
//    it has an editable Privacy Policy under Configuration and something to show on the signup page.
// 2. Inserts an age-eligibility clause into the existing Terms and Conditions text (only if it
//    isn't already there), matching the new 15-30 age limit enforced on registration.
// Safe to run more than once. Delete this file once you've confirmed it applied.
$results = [];

$col = $conn->query("SHOW COLUMNS FROM site_settings LIKE 'privacy_policy'")->fetch_assoc();
if (!$col) {
    if ($conn->query("ALTER TABLE site_settings ADD COLUMN privacy_policy TEXT NULL AFTER terms_conditions")) {
        $results[] = "Added site_settings.privacy_policy column.";
    } else {
        $results[] = "Failed to add privacy_policy column: " . $conn->error;
    }
} else {
    $results[] = "privacy_policy column already exists.";
}

$defaultPrivacyPolicy = <<<'TXT'
The Sangguniang Kabataan ng Langkiwa (SK Langkiwa) Scholarship and Assistance Portal is committed to protecting the privacy of every applicant, scholar, and beneficiary who uses this system, in accordance with the Data Privacy Act of 2012 (RA 10173).

1. Information We Collect. We collect the personal information you provide during registration and application, including your name, age, gender, contact details, address, academic records, financial information, and any documents you upload (e.g., IDs, certificates, indigency documents).

2. How We Use Your Information. Your information is used solely to process and evaluate your application, administer approved scholarships and assistance programs, communicate with you regarding your application or account, and generate official reports for SK Langkiwa's internal use and compliance.

3. Data Sharing. Your information is not sold, rented, or shared with third parties for marketing purposes. It may only be disclosed to authorized SK Langkiwa officials, barangay officials, or government agencies when required by law or necessary to verify your application.

4. Data Storage and Security. Your data is stored securely and access is limited to authorized personnel who need it to perform their official duties. We take reasonable measures to protect your information from unauthorized access, alteration, or disclosure.

5. Data Retention. Your information is retained for as long as necessary to fulfill the purposes described in this policy, or as required by applicable law and barangay/SK record-keeping requirements.

6. Your Rights. Under the Data Privacy Act, you have the right to access, correct, or request the deletion of your personal information, subject to legal and administrative retention requirements. You may contact the SK Langkiwa office to exercise these rights.

7. Amendments. SK Langkiwa reserves the right to update this Privacy Policy at any time. Continued use of this portal after changes are posted constitutes acceptance of the revised policy.

By checking the box during registration, you acknowledge that you have read, understood, and agree to this Privacy Policy.
TXT;

$current = $conn->query("SELECT privacy_policy, terms_conditions FROM site_settings WHERE id = 1")->fetch_assoc();

if (empty($current['privacy_policy'])) {
    $stmt = $conn->prepare("UPDATE site_settings SET privacy_policy = ? WHERE id = 1");
    $stmt->bind_param('s', $defaultPrivacyPolicy);
    $stmt->execute();
    $stmt->close();
    $results[] = "Seeded a default Privacy Policy — edit it any time under Configuration.";
} else {
    $results[] = "privacy_policy already has content — left as-is.";
}

$terms = $current['terms_conditions'] ?? '';
if ($terms !== '' && stripos($terms, '15 and 30') === false && stripos($terms, '15-30') === false) {
    $ageClause = "2. Age Eligibility. This portal is intended exclusively for the Sangguniang Kabataan's youth constituents. You confirm that you are between 15 and 30 years old (inclusive), consistent with the official SK youth age range under RA 10742 (Sangguniang Kabataan Reform Act of 2015). Accounts found to belong to individuals outside this age range may be suspended or removed.";

    // Insert right after point 1 (if the text still follows the numbered-list format this app
    // seeds by default), then renumber every following "N." point by +1. Falls back to just
    // appending the clause at the end if the text has been edited into a different shape.
    if (preg_match('/^(.*?\n\n)(2\..*)$/s', $terms, $m)) {
        $rest = preg_replace_callback('/^(\d+)\./m', function ($mm) {
            return ((int)$mm[1] + 1) . '.';
        }, $m[2]);
        $newTerms = $m[1] . $ageClause . "\n\n" . $rest;
    } else {
        $newTerms = rtrim($terms) . "\n\n" . $ageClause;
    }

    $stmt = $conn->prepare("UPDATE site_settings SET terms_conditions = ? WHERE id = 1");
    $stmt->bind_param('s', $newTerms);
    $stmt->execute();
    $stmt->close();
    $results[] = "Inserted the age-eligibility (15-30) clause into Terms and Conditions.";
} elseif ($terms === '') {
    $results[] = "terms_conditions is empty — nothing to update (set one under Configuration first).";
} else {
    $results[] = "Terms and Conditions already mentions the 15-30 age range — left as-is.";
}
?>
<!DOCTYPE html>
<html>

<head>
    <title>Migrate Privacy Policy & Age Terms</title>
</head>

<body style="font-family: sans-serif; padding: 24px; max-width: 640px; margin: 0 auto;">
    <h2>Migrate Privacy Policy &amp; Terms Age Clause</h2>
    <ul>
        <?php foreach ($results as $r): ?>
            <li><?php echo htmlspecialchars($r); ?></li>
        <?php endforeach; ?>
    </ul>
    <p><a href="AdminConfiguration.php">Back to Configuration</a></p>
    <p style="color:#888; font-size:12px;">This page is safe to reload. Delete admin/MigratePrivacyPolicyAndAgeTerms.php once confirmed. You can edit either text afterward under Configuration &gt; Settings.</p>
</body>

</html>