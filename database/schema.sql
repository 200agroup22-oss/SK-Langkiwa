-- SK Langkiwa system database schema
-- Import into MySQL/XAMPP via phpMyAdmin or: mysql -u root sk_langkiwa < schema.sql

CREATE DATABASE IF NOT EXISTS sk_langkiwa CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE sk_langkiwa;

-- ---------------------------------------------------------------
-- Users & auth
-- ---------------------------------------------------------------
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    last_name VARCHAR(100) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) NULL,
    age INT NULL,
    gender ENUM('Male','Female') NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('applicant','scholar','admin','committee_admin','secretary','treasurer') NOT NULL DEFAULT 'applicant',
    position_title VARCHAR(100) NULL,
    profile_photo VARCHAR(255) NULL,
    status ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at DATETIME NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Committees, programs, dynamic form fields
-- ---------------------------------------------------------------
CREATE TABLE committees (
    committee_id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(255) NULL,
    icon VARCHAR(60) NULL,
    archived_at DATETIME NULL
) ENGINE=InnoDB;

-- Which committee(s) a 'committee_admin' user is scoped to manage. Unused for other roles.
CREATE TABLE admin_committee_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    committee_id INT NOT NULL,
    UNIQUE KEY uniq_user_committee (user_id, committee_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    FOREIGN KEY (committee_id) REFERENCES committees(committee_id)
) ENGINE=InnoDB;

CREATE TABLE programs (
    program_id INT AUTO_INCREMENT PRIMARY KEY,
    committee_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    assistance_type ENUM('cash','in_kind','both') NOT NULL DEFAULT 'cash',
    amount DECIMAL(10,2) NULL,
    release_schedule VARCHAR(60) NULL,
    app_start_date DATE NULL,
    app_end_date DATE NULL,
    eligibility_requirements TEXT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at DATETIME NULL,
    FOREIGN KEY (committee_id) REFERENCES committees(committee_id)
) ENGINE=InnoDB;

-- Sidebar "program tabs" per committee (e.g. Education's "iSKolar ng Langkiwa" vs "Assistance
-- Program"). One row per (committee, program_track) pair actually implemented in code; admin can
-- rename the label/icon or hide a tab from the sidebar without touching the underlying pages.
CREATE TABLE program_tabs (
    tab_id INT AUTO_INCREMENT PRIMARY KEY,
    committee_id INT NOT NULL,
    program_id INT NULL DEFAULT NULL COMMENT 'Links this tab to a catalog entry in programs — NULL for the two built-in tracks (scholarship/assistance)',
    track_code VARCHAR(30) NOT NULL,
    label VARCHAR(100) NOT NULL,
    icon VARCHAR(60) NOT NULL DEFAULT 'bi-hand-holding-heart-fill',
    is_visible TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    max_slots INT NULL DEFAULT NULL COMMENT 'NULL = unlimited slots for this program',
    UNIQUE KEY uniq_committee_track (committee_id, track_code),
    FOREIGN KEY (committee_id) REFERENCES committees(committee_id)
) ENGINE=InnoDB;

CREATE TABLE form_fields (
    field_id INT AUTO_INCREMENT PRIMARY KEY,
    committee_id INT NOT NULL,
    program_track VARCHAR(30) NOT NULL DEFAULT 'assistance',
    program_id INT NULL DEFAULT NULL COMMENT 'NULL = shared field shown on every program under this committee+track; set = shown only on that one program''s form, in addition to the shared fields',
    label VARCHAR(150) NOT NULL,
    field_key VARCHAR(100) NOT NULL,
    input_type ENUM('text','number','date','textarea','dropdown','file','radio') NOT NULL,
    icon VARCHAR(60) NULL,
    width ENUM('third','half','two_third','full') NOT NULL DEFAULT 'full',
    is_required TINYINT(1) NOT NULL DEFAULT 1,
    options TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    archived_at DATETIME NULL,
    FOREIGN KEY (committee_id) REFERENCES committees(committee_id),
    FOREIGN KEY (program_id) REFERENCES programs(program_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Applications (generic, dynamic, shared by every committee)
-- ---------------------------------------------------------------
CREATE TABLE applications (
    application_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    committee_id INT NOT NULL,
    program_track VARCHAR(30) NOT NULL DEFAULT 'assistance',
    program_id INT NULL,
    status ENUM('pending','approved','declined') NOT NULL DEFAULT 'pending',
    decline_reason VARCHAR(255) NULL,
    academic_year VARCHAR(20) NULL,
    semester VARCHAR(20) NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at DATETIME NULL,
    decided_by INT NULL,
    archived_at DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    FOREIGN KEY (committee_id) REFERENCES committees(committee_id),
    FOREIGN KEY (program_id) REFERENCES programs(program_id),
    FOREIGN KEY (decided_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE application_answers (
    answer_id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    field_id INT NOT NULL,
    value TEXT NULL,
    FOREIGN KEY (application_id) REFERENCES applications(application_id) ON DELETE CASCADE,
    FOREIGN KEY (field_id) REFERENCES form_fields(field_id)
) ENGINE=InnoDB;

CREATE TABLE application_files (
    file_id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    field_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES applications(application_id) ON DELETE CASCADE,
    FOREIGN KEY (field_id) REFERENCES form_fields(field_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Scholars & allowance
-- ---------------------------------------------------------------
CREATE TABLE scholars (
    scholar_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    application_id INT NOT NULL,
    school VARCHAR(150) NULL,
    course VARCHAR(150) NULL,
    year_level TINYINT NULL,
    status ENUM('active','pending','archived') NOT NULL DEFAULT 'active' COMMENT "active = current term; pending = term ended, awaiting the admin's renewal decision on the Applicants page's Renewals tab (still has scholar-portal access); archived = permanently declined/removed (role reverted to applicant)",
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_academic_year VARCHAR(20) NULL COMMENT 'Academic year the scholar was last archived out of (the term they finished) — set on manual Archive or End Semester',
    finished_semester VARCHAR(20) NULL COMMENT 'Semester counterpart to finished_academic_year',
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    FOREIGN KEY (application_id) REFERENCES applications(application_id)
) ENGINE=InnoDB;

CREATE TABLE allowance_distributions (
    distribution_id INT AUTO_INCREMENT PRIMARY KEY,
    scholar_id INT NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    semester VARCHAR(20) NOT NULL,
    activities_required INT NOT NULL DEFAULT 3,
    activities_completed INT NOT NULL DEFAULT 0,
    eligibility ENUM('eligible','pending','not_eligible') NOT NULL DEFAULT 'pending',
    eligibility_is_manual TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Set when an admin explicitly overrides eligibility via Set Eligibility — keeps it from being auto-recomputed from attendance until cleared',
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    status ENUM('pending','approved','declined') NOT NULL DEFAULT 'pending',
    requirement_decision ENUM('pending','update','declined') NOT NULL DEFAULT 'pending' COMMENT 'Admin call on the Updated Requirements modal for the next term: update (scholar continues, must submit new docs) or declined (scholar is archived)',
    decided_at DATETIME NULL,
    UNIQUE KEY uniq_scholar_term (scholar_id, academic_year, semester),
    FOREIGN KEY (scholar_id) REFERENCES scholars(scholar_id)
) ENGINE=InnoDB;

CREATE TABLE scholar_requirement_files (
    requirement_file_id INT AUTO_INCREMENT PRIMARY KEY,
    scholar_id INT NOT NULL,
    field_id INT NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    semester VARCHAR(20) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (scholar_id) REFERENCES scholars(scholar_id),
    FOREIGN KEY (field_id) REFERENCES form_fields(field_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Activities & QR attendance
-- ---------------------------------------------------------------
CREATE TABLE activities (
    activity_id INT AUTO_INCREMENT PRIMARY KEY,
    committee_id INT NULL,
    academic_year VARCHAR(20) NULL,
    semester VARCHAR(20) NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NULL,
    activity_date DATE NOT NULL,
    activity_time TIME NULL,
    venue VARCHAR(150) NULL,
    note VARCHAR(255) NULL,
    audience ENUM('all','selected') NOT NULL DEFAULT 'all',
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at DATETIME NULL,
    FOREIGN KEY (committee_id) REFERENCES committees(committee_id),
    FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE activity_scholars (
    activity_id INT NOT NULL,
    scholar_id INT NOT NULL,
    PRIMARY KEY (activity_id, scholar_id),
    FOREIGN KEY (activity_id) REFERENCES activities(activity_id) ON DELETE CASCADE,
    FOREIGN KEY (scholar_id) REFERENCES scholars(scholar_id)
) ENGINE=InnoDB;

CREATE TABLE attendance (
    attendance_id INT AUTO_INCREMENT PRIMARY KEY,
    activity_id INT NOT NULL,
    scholar_id INT NOT NULL,
    status ENUM('pending','present','absent') NOT NULL DEFAULT 'pending',
    qr_token VARCHAR(64) NOT NULL UNIQUE,
    scanned_at DATETIME NULL,
    UNIQUE KEY uniq_activity_scholar (activity_id, scholar_id),
    FOREIGN KEY (activity_id) REFERENCES activities(activity_id) ON DELETE CASCADE,
    FOREIGN KEY (scholar_id) REFERENCES scholars(scholar_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Cash / in-kind assistance beneficiaries (all committees)
-- ---------------------------------------------------------------
CREATE TABLE assistance_beneficiaries (
    beneficiary_id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL UNIQUE,
    type ENUM('cash','in_kind') NOT NULL,
    amount DECIMAL(10,2) NULL,
    items VARCHAR(255) NULL,
    quantity INT NULL,
    status ENUM('pending','released','distributed') NOT NULL DEFAULT 'pending',
    date_released DATE NULL,
    date_distributed DATE NULL,
    archived_at DATETIME NULL,
    FOREIGN KEY (application_id) REFERENCES applications(application_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Announcements & site config
-- ---------------------------------------------------------------
CREATE TABLE announcements (
    announcement_id INT AUTO_INCREMENT PRIMARY KEY,
    committee_id INT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    event_date DATE NULL,
    event_time TIME NULL,
    event_where VARCHAR(150) NULL,
    notes VARCHAR(255) NULL,
    sent_to ENUM('all','scholars','applicants','specific') NOT NULL DEFAULT 'all',
    specific_target VARCHAR(150) NULL,
    posted_by INT NULL,
    posted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at DATETIME NULL,
    FOREIGN KEY (committee_id) REFERENCES committees(committee_id),
    FOREIGN KEY (posted_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

-- Per-viewer dismissal of a broadcast announcement (the announcement itself stays visible to
-- everyone else it was sent to — this only hides it for the one user who archived it).
CREATE TABLE announcement_archives (
    user_id INT NOT NULL,
    announcement_id INT NOT NULL,
    archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, announcement_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    FOREIGN KEY (announcement_id) REFERENCES announcements(announcement_id)
) ENGINE=InnoDB;

-- Personal in-app notifications (e.g. "your application was approved") shown alongside broadcast
-- announcements in the applicant/scholar dashboard's Announcement widget, but owned by one user.
CREATE TABLE notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE important_dates (
    date_id INT AUTO_INCREMENT PRIMARY KEY,
    event_name VARCHAR(150) NOT NULL,
    event_date DATE NOT NULL
) ENGINE=InnoDB;

CREATE TABLE site_settings (
    id TINYINT PRIMARY KEY DEFAULT 1,
    logo_path VARCHAR(255) NULL,
    about_text TEXT NULL,
    sk_office_address VARCHAR(255) NULL,
    contact_number VARCHAR(40) NULL,
    office_hours VARCHAR(100) NULL,
    site_name VARCHAR(150) NULL,
    tagline VARCHAR(200) NULL,
    welcome_message TEXT NULL,
    email VARCHAR(150) NULL,
    facebook_url VARCHAR(255) NULL,
    terms_conditions TEXT NULL,
    privacy_policy TEXT NULL,
    allow_public_applications TINYINT(1) NOT NULL DEFAULT 1,
    show_announcements TINYINT(1) NOT NULL DEFAULT 1,
    current_academic_year VARCHAR(20) NOT NULL DEFAULT '2025-2026',
    current_semester VARCHAR(20) NOT NULL DEFAULT '2nd Semester',
    requirements_deadline DATE NULL COMMENT 'Cutoff for scholars to submit updated requirements for the current term; set via End Semester or edited manually in Site Settings',
    requirements_open TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether scholars can currently submit updated requirements. Turned on by End Semester (a fresh renewal window for the new term); auto-treated as closed again once requirements_deadline passes',
    default_allowance_amount DECIMAL(10,2) NOT NULL DEFAULT 2000.00,
    activities_required_per_term INT NOT NULL DEFAULT 3
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Logging
-- ---------------------------------------------------------------
CREATE TABLE activity_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    full_name VARCHAR(200) NOT NULL,
    email VARCHAR(150) NOT NULL,
    role VARCHAR(60) NOT NULL,
    logged_in_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    logged_out_at DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    full_name VARCHAR(200) NOT NULL,
    email VARCHAR(150) NOT NULL,
    action VARCHAR(100) NOT NULL,
    details VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Seed data
-- ---------------------------------------------------------------
INSERT INTO committees (code, name, description, icon) VALUES
('education', 'Education', 'iSKolar ng Langkiwa and education assistance programs', 'bi-mortarboard-fill'),
('health', 'Health', 'Medical and health assistance programs', 'bi-heart-pulse-fill'),
('sports', 'Sports', 'Sports development and assistance programs', 'bi-trophy-fill'),
('active_citizenship', 'Active Citizenship', 'Civic engagement and active citizenship programs', 'bi-people-fill');

INSERT INTO program_tabs (committee_id, track_code, label, icon, sort_order)
SELECT committee_id, 'scholarship', 'iSKolar ng Langkiwa', 'bi-mortarboard-fill', 1 FROM committees WHERE code='education'
UNION ALL
SELECT committee_id, 'assistance', 'Assistance Program', 'bi-hand-holding-heart-fill', 2 FROM committees WHERE code='education'
UNION ALL
SELECT committee_id, 'assistance', 'Assistance Program', 'bi-hand-holding-heart-fill', 1 FROM committees WHERE code='health'
UNION ALL
SELECT committee_id, 'assistance', 'Assistance Program', 'bi-hand-holding-heart-fill', 1 FROM committees WHERE code='sports'
UNION ALL
SELECT committee_id, 'assistance', 'Assistance Program', 'bi-hand-holding-heart-fill', 1 FROM committees WHERE code='active_citizenship';

INSERT INTO site_settings (id, site_name, tagline, about_text, sk_office_address, contact_number, office_hours, email, terms_conditions)
VALUES (1, 'Sangguniang Kabataan ng Langkiwa', 'Serving the youth of Barangay Langkiwa',
'The SK Langkiwa Financial Assistance Program supports the youth of the barangay through scholarships and assistance programs.',
'Barangay Langkiwa Hall', '(046) 000-0000', 'Mon-Fri, 8:00 AM - 5:00 PM', 'sklangkiwa@example.gov.ph',
'By creating an account with the Sangguniang Kabataan ng Langkiwa (SK Langkiwa) Scholarship and Assistance Portal, you agree to the following:

1. Eligibility. You confirm that you are a bona fide resident (or qualified beneficiary) of Barangay Langkiwa, and that all information you provide — personal details, academic records, financial status, and supporting documents — is true, complete, and accurate to the best of your knowledge.

2. Data Privacy. Information you submit, including personal data, application details, and uploaded documents, will be collected, stored, and used solely for evaluating your application, administering approved scholarships and assistance, and generating official SK Langkiwa reports, in accordance with the Data Privacy Act of 2012 (RA 10173).

3. Document Authenticity. Any falsified, forged, or altered document submitted as part of an application shall result in immediate disqualification and may be reported to the appropriate barangay or school authorities.

4. One Application per Program. Applicants may only maintain one active application per assistance program per academic term. Duplicate applications for the same program will be declined.

5. Approval is Not Guaranteed. Submission of an application does not guarantee approval. Applications are subject to review, verification, and available program slots, and are approved at the discretion of the concerned SK Langkiwa committee.

6. Scholar Obligations. Once approved as a scholar or beneficiary, you agree to comply with attendance requirements for SK-organized activities, submit required documents each semester, and maintain good standing, as failure to do so may affect the continued release of assistance.

7. Account Responsibility. You are responsible for keeping your account credentials confidential and for all activity that occurs under your account.

8. Amendments. SK Langkiwa reserves the right to update these Terms and Conditions at any time. Continued use of this portal after changes are posted constitutes acceptance of the revised terms.

By checking the box during registration, you acknowledge that you have read, understood, and agree to these Terms and Conditions.');

-- Default admin account: email admin@langkiwa.gov.ph / password Admin@123
INSERT INTO users (last_name, first_name, middle_name, age, gender, email, password_hash, role, position_title, status)
VALUES ('User', 'Admin', NULL, 30, 'Male', 'admin@langkiwa.gov.ph',
'$2y$10$Bw9oS915KhWHClJFIJ01M.iVIb3V.o4WM8MOWvPCV4CTiZkD6JrPC',
'admin', 'Admin/SK Chairperson', 'active');

-- Dynamic form fields: Education iSKolar scholarship application form
INSERT INTO form_fields (committee_id, program_track, label, field_key, input_type, icon, width, is_required, options, sort_order) VALUES
(1, 'scholarship', 'Last Name', 'last_name', 'text', 'bi-person-fill', 'third', 1, NULL, 1),
(1, 'scholarship', 'First Name', 'first_name', 'text', 'bi-person-fill', 'third', 1, NULL, 2),
(1, 'scholarship', 'Middle Name', 'middle_name', 'text', 'bi-person-fill', 'third', 0, NULL, 3),
(1, 'scholarship', 'Complete Address', 'complete_address', 'text', 'bi-geo-alt-fill', 'full', 1, NULL, 4),
(1, 'scholarship', 'Year Level', 'year_level', 'dropdown', 'bi-bar-chart-fill', 'half', 1, '["1st Year","2nd Year","3rd Year","4th Year"]', 5),
(1, 'scholarship', 'School/University', 'school_university', 'text', 'bi-building', 'half', 1, NULL, 6),
(1, 'scholarship', 'Course', 'course', 'text', 'bi-journal-bookmark-fill', 'half', 1, NULL, 7),
(1, 'scholarship', 'Barangay Indigency', 'barangay_indigency', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 8),
(1, 'scholarship', 'Copy of Grades (Last Semester)', 'grades_last_semester', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 9),
(1, 'scholarship', 'School Registration Form (Current Semester)', 'school_registration_form', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 10),
(1, 'scholarship', 'Valid ID', 'valid_id', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 11);

-- Dynamic form fields: Education Assistance Program (admin-entered, distinct from iSKolar)
INSERT INTO form_fields (committee_id, program_track, label, field_key, input_type, icon, width, is_required, options, sort_order) VALUES
(1, 'assistance', 'Last Name', 'last_name', 'text', 'bi-person-fill', 'third', 1, NULL, 1),
(1, 'assistance', 'First Name', 'first_name', 'text', 'bi-person-fill', 'third', 1, NULL, 2),
(1, 'assistance', 'Middle Name', 'middle_name', 'text', 'bi-person-fill', 'third', 0, NULL, 3),
(1, 'assistance', 'Complete Address', 'complete_address', 'text', 'bi-geo-alt-fill', 'full', 1, NULL, 4),
(1, 'assistance', 'Type of Assistance', 'assistance_type', 'dropdown', 'bi-cash-coin', 'full', 1, '["Tuition Fee Assistance","School Supplies Assistance","Transportation Assistance"]', 5),
(1, 'assistance', 'Barangay Indigency', 'barangay_indigency', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 6),
(1, 'assistance', 'Certificate of Enrollment', 'certificate_enrollment', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 7),
(1, 'assistance', 'Report Card / Grades', 'report_card', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 8),
(1, 'assistance', 'Valid ID', 'valid_id', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 9);

-- Dynamic form fields: Health assistance application form
INSERT INTO form_fields (committee_id, program_track, label, field_key, input_type, icon, width, is_required, options, sort_order) VALUES
(2, 'assistance', 'Last Name', 'last_name', 'text', 'bi-person-fill', 'third', 1, NULL, 1),
(2, 'assistance', 'First Name', 'first_name', 'text', 'bi-person-fill', 'third', 1, NULL, 2),
(2, 'assistance', 'Middle Name', 'middle_name', 'text', 'bi-person-fill', 'third', 0, NULL, 3),
(2, 'assistance', 'Complete Address', 'complete_address', 'text', 'bi-geo-alt-fill', 'full', 1, NULL, 4),
(2, 'assistance', 'Type of Assistance', 'assistance_type', 'radio', 'bi-cash-coin', 'full', 1, '["Cash Assistance","In-kind Assistance"]', 5),
(2, 'assistance', 'Letter Request', 'letter_request', 'textarea', 'bi-envelope-fill', 'full', 1, NULL, 6),
(2, 'assistance', 'Barangay Indigency', 'barangay_indigency', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 7),
(2, 'assistance', 'Medical Certificate', 'medical_certificate', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 8),
(2, 'assistance', 'Hospital Bill / Prescription', 'hospital_bill', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 9),
(2, 'assistance', 'Valid ID', 'valid_id', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 10);

-- Dynamic form fields: Sports assistance application form
INSERT INTO form_fields (committee_id, program_track, label, field_key, input_type, icon, width, is_required, options, sort_order) VALUES
(3, 'assistance', 'Last Name', 'last_name', 'text', 'bi-person-fill', 'third', 1, NULL, 1),
(3, 'assistance', 'First Name', 'first_name', 'text', 'bi-person-fill', 'third', 1, NULL, 2),
(3, 'assistance', 'Middle Name', 'middle_name', 'text', 'bi-person-fill', 'third', 0, NULL, 3),
(3, 'assistance', 'Complete Address', 'complete_address', 'text', 'bi-geo-alt-fill', 'full', 1, NULL, 4),
(3, 'assistance', 'Type of Assistance', 'assistance_type', 'radio', 'bi-cash-coin', 'full', 1, '["Cash Assistance","In-kind Assistance"]', 5),
(3, 'assistance', 'Letter Request', 'letter_request', 'textarea', 'bi-envelope-fill', 'full', 1, NULL, 6),
(3, 'assistance', 'Barangay Indigency', 'barangay_indigency', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 7),
(3, 'assistance', 'Certificate of Participation', 'certificate_participation', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 8),
(3, 'assistance', 'Proof of Event Registration', 'proof_event_registration', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 9),
(3, 'assistance', 'Valid ID', 'valid_id', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 10);

-- Dynamic form fields: Active Citizenship assistance (admin-entered, no public form yet)
INSERT INTO form_fields (committee_id, program_track, label, field_key, input_type, icon, width, is_required, options, sort_order) VALUES
(4, 'assistance', 'Last Name', 'last_name', 'text', 'bi-person-fill', 'third', 1, NULL, 1),
(4, 'assistance', 'First Name', 'first_name', 'text', 'bi-person-fill', 'third', 1, NULL, 2),
(4, 'assistance', 'Middle Name', 'middle_name', 'text', 'bi-person-fill', 'third', 0, NULL, 3),
(4, 'assistance', 'Complete Address', 'complete_address', 'text', 'bi-geo-alt-fill', 'full', 1, NULL, 4),
(4, 'assistance', 'Type of Assistance', 'assistance_type', 'radio', 'bi-cash-coin', 'full', 1, '["Cash Assistance","In-kind Assistance"]', 5),
(4, 'assistance', 'Barangay Indigency', 'barangay_indigency', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 6),
(4, 'assistance', 'Certificate of Participation', 'certificate_participation', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 7),
(4, 'assistance', 'Proof of Involvement', 'proof_involvement', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 8),
(4, 'assistance', 'Valid ID', 'valid_id', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 9);
