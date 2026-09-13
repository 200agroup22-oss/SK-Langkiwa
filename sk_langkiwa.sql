-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 25, 2026 at 03:47 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `sk_langkiwa`
--

-- --------------------------------------------------------

--
-- Table structure for table `activities`
--

CREATE TABLE `activities` (
  `activity_id` int(11) NOT NULL,
  `committee_id` int(11) DEFAULT NULL,
  `academic_year` varchar(20) DEFAULT NULL,
  `semester` varchar(20) DEFAULT NULL,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `activity_date` date NOT NULL,
  `activity_time` time DEFAULT NULL,
  `venue` varchar(150) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `audience` enum('all','selected') NOT NULL DEFAULT 'all',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `archived_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activities`
--

INSERT INTO `activities` (`activity_id`, `committee_id`, `academic_year`, `semester`, `title`, `description`, `activity_date`, `activity_time`, `venue`, `note`, `audience`, `created_by`, `created_at`, `archived_at`) VALUES
(1, 1, '2025-2026', '2nd Semester', 'SK/KK Assembly', '', '2026-09-05', NULL, 'Barangay Langkiwa Covered Court', NULL, 'all', 1, '2026-08-24 23:59:46', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `full_name` varchar(200) NOT NULL,
  `email` varchar(150) NOT NULL,
  `role` varchar(60) NOT NULL,
  `logged_in_at` datetime NOT NULL DEFAULT current_timestamp(),
  `logged_out_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`log_id`, `user_id`, `full_name`, `email`, `role`, `logged_in_at`, `logged_out_at`) VALUES
(1, 2, 'Jane Dela Cruz', 'jane.delacruz@example.com', 'applicant', '2026-08-24 23:43:24', '2026-08-24 23:58:52'),
(2, 1, 'Admin User', 'admin@langkiwa.gov.ph', 'admin', '2026-08-24 23:46:27', NULL),
(3, 2, 'Jane Dela Cruz', 'jane.delacruz@example.com', 'scholar', '2026-08-24 23:58:52', NULL),
(4, 2, 'Jane Dela Cruz', 'jane.delacruz@example.com', 'scholar', '2026-08-25 00:00:43', NULL),
(5, 4, 'Bob Santos', 'bob.santos@example.com', 'applicant', '2026-08-25 09:28:05', NULL),
(6, 1, 'Admin User', 'admin@langkiwa.gov.ph', 'admin', '2026-08-25 09:28:35', '2026-08-25 09:37:03'),
(7, 5, 'Carla Reyes', 'carla.reyes@example.com', 'applicant', '2026-08-25 09:29:54', NULL),
(8, 1, 'Admin User', 'admin@langkiwa.gov.ph', 'admin', '2026-08-25 09:34:22', '2026-08-25 09:36:16'),
(9, 6, 'Yhianzy Solis', 'yhianzy@gmail.com', 'applicant', '2026-08-25 09:36:49', '2026-08-25 09:39:33'),
(10, 6, 'Yhianzy Solis', 'yhianzy@gmail.com', 'applicant', '2026-08-25 09:39:46', '2026-08-25 09:40:00'),
(11, 1, 'Admin User', 'admin@langkiwa.gov.ph', 'admin', '2026-08-25 09:40:12', '2026-08-25 09:41:03'),
(12, 6, 'Yhianzy Solis', 'yhianzy@gmail.com', 'applicant', '2026-08-25 09:41:13', '2026-08-25 09:42:11'),
(13, 1, 'Admin User', 'admin@langkiwa.gov.ph', 'admin', '2026-08-25 09:42:18', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `activity_scholars`
--

CREATE TABLE `activity_scholars` (
  `activity_id` int(11) NOT NULL,
  `scholar_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `allowance_distributions`
--

CREATE TABLE `allowance_distributions` (
  `distribution_id` int(11) NOT NULL,
  `scholar_id` int(11) NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `semester` varchar(20) NOT NULL,
  `activities_required` int(11) NOT NULL DEFAULT 3,
  `activities_completed` int(11) NOT NULL DEFAULT 0,
  `eligibility` enum('eligible','pending','not_eligible') NOT NULL DEFAULT 'pending',
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','approved','declined') NOT NULL DEFAULT 'pending',
  `decided_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `allowance_distributions`
--

INSERT INTO `allowance_distributions` (`distribution_id`, `scholar_id`, `academic_year`, `semester`, `activities_required`, `activities_completed`, `eligibility`, `amount`, `status`, `decided_at`) VALUES
(1, 1, '2025-2026', '2nd Semester', 3, 1, 'pending', 2000.00, 'pending', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `announcement_id` int(11) NOT NULL,
  `committee_id` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `event_date` date DEFAULT NULL,
  `event_time` time DEFAULT NULL,
  `event_where` varchar(150) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `sent_to` enum('all','scholars','applicants','specific') NOT NULL DEFAULT 'all',
  `specific_target` varchar(150) DEFAULT NULL,
  `posted_by` int(11) DEFAULT NULL,
  `posted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `archived_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `announcements`
--

INSERT INTO `announcements` (`announcement_id`, `committee_id`, `title`, `message`, `event_date`, `event_time`, `event_where`, `notes`, `sent_to`, `specific_target`, `posted_by`, `posted_at`, `archived_at`) VALUES
(1, 1, 'Scholarship Application Open', 'Applications are now open for the 2nd semester.', NULL, NULL, '', '', 'all', NULL, 1, '2026-08-25 09:26:02', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `applications`
--

CREATE TABLE `applications` (
  `application_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `committee_id` int(11) NOT NULL,
  `program_track` varchar(30) NOT NULL DEFAULT 'assistance',
  `program_id` int(11) DEFAULT NULL,
  `status` enum('pending','approved','declined') NOT NULL DEFAULT 'pending',
  `decline_reason` varchar(255) DEFAULT NULL,
  `academic_year` varchar(20) DEFAULT NULL,
  `semester` varchar(20) DEFAULT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `decided_at` datetime DEFAULT NULL,
  `decided_by` int(11) DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `applications`
--

INSERT INTO `applications` (`application_id`, `user_id`, `committee_id`, `program_track`, `program_id`, `status`, `decline_reason`, `academic_year`, `semester`, `submitted_at`, `decided_at`, `decided_by`, `archived_at`) VALUES
(1, 2, 1, 'scholarship', NULL, 'approved', NULL, '2025-2026', '2nd Semester', '2026-08-24 23:43:24', '2026-08-24 23:47:31', 1, NULL),
(3, 4, 2, 'assistance', NULL, 'approved', NULL, '2025-2026', '2nd Semester', '2026-08-25 09:28:05', '2026-08-25 09:28:18', 1, NULL),
(4, 5, 1, 'scholarship', NULL, 'approved', NULL, '2025-2026', '2nd Semester', '2026-08-25 09:29:54', '2026-08-25 09:35:50', 1, NULL),
(5, 6, 3, 'assistance', NULL, 'declined', '', '2025-2026', '2nd Semester', '2026-08-25 09:38:57', '2026-08-25 09:40:56', 1, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `application_answers`
--

CREATE TABLE `application_answers` (
  `answer_id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `field_id` int(11) NOT NULL,
  `value` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `application_answers`
--

INSERT INTO `application_answers` (`answer_id`, `application_id`, `field_id`, `value`) VALUES
(1, 1, 1, 'Dela Cruz'),
(2, 1, 2, 'Jane'),
(3, 1, 3, 'Dalisay'),
(4, 1, 4, 'Blk 3 Lot 5, Barangay Langkiwa'),
(5, 1, 5, '3rd Year'),
(6, 1, 6, 'Cavite State University'),
(7, 1, 7, 'BS Information Technology'),
(11, 3, 21, 'Santos'),
(12, 3, 22, 'Bob'),
(13, 3, 23, ''),
(14, 3, 24, 'Blk 1 Lot 1, Barangay Langkiwa'),
(15, 3, 25, 'Cash Assistance'),
(16, 3, 26, 'Requesting medical assistance for hospital bills.'),
(24, 4, 1, 'Reyes'),
(25, 4, 2, 'Carla'),
(26, 4, 3, ''),
(27, 4, 4, 'Blk 2, Barangay Langkiwa'),
(28, 4, 5, '1st Year'),
(29, 4, 6, 'Trimex Colleges Binan'),
(30, 4, 7, 'BS Psychology'),
(31, 5, 31, 'Solis'),
(32, 5, 32, 'Yhianzy'),
(33, 5, 33, 'Papio'),
(34, 5, 34, '123123123 Blk 2, Barangay Mabuhay Carmona Cavite'),
(35, 5, 35, 'Cash Assistance'),
(36, 5, 36, 'pahingi pera pls');

-- --------------------------------------------------------

--
-- Table structure for table `application_files`
--

CREATE TABLE `application_files` (
  `file_id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `field_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `application_files`
--

INSERT INTO `application_files` (`file_id`, `application_id`, `field_id`, `file_path`, `original_name`, `uploaded_at`) VALUES
(1, 1, 8, 'uploads/documents/b2ba88925c60b101_1787586204.pdf', 'indigency.pdf', '2026-08-24 23:43:24'),
(2, 1, 9, 'uploads/documents/e5868a68939bf8e6_1787586204.pdf', 'grades.pdf', '2026-08-24 23:43:24'),
(3, 1, 10, 'uploads/documents/19c5b96f87f4764d_1787586204.pdf', 'regform.pdf', '2026-08-24 23:43:24'),
(4, 1, 11, 'uploads/documents/4b509b17ed02ad7c_1787586204.pdf', 'validid.pdf', '2026-08-24 23:43:24'),
(5, 3, 27, 'uploads/documents/316a5a12ecfbc7c0_1787621285.pdf', 'indigency.pdf', '2026-08-25 09:28:05'),
(6, 3, 28, 'uploads/documents/bce978341222fc1f_1787621285.pdf', 'medcert.pdf', '2026-08-25 09:28:05'),
(7, 3, 29, 'uploads/documents/c3a67640faf2e29f_1787621285.pdf', 'hospbill.pdf', '2026-08-25 09:28:05'),
(8, 3, 30, 'uploads/documents/28917ecf7fbe5cc8_1787621285.pdf', 'validid.pdf', '2026-08-25 09:28:05'),
(9, 4, 8, 'uploads/documents/124202690843bc78_1787621394.pdf', 'indigency.pdf', '2026-08-25 09:29:54'),
(10, 4, 9, 'uploads/documents/54bbee2dafc92f05_1787621394.pdf', 'grades.pdf', '2026-08-25 09:29:54'),
(11, 4, 10, 'uploads/documents/12ea06354c7b2351_1787621394.pdf', 'regform.pdf', '2026-08-25 09:29:54'),
(12, 4, 11, 'uploads/documents/37a51950a4e52c53_1787621394.pdf', 'validid.pdf', '2026-08-25 09:29:54'),
(13, 5, 37, 'uploads/documents/f97cad474bc58e1c_1787621937.pdf', 'Activty-1 (1).pdf', '2026-08-25 09:38:57'),
(14, 5, 38, 'uploads/documents/6e9df66240fe9743_1787621937.pdf', 'Activty-1 (1).pdf', '2026-08-25 09:38:57'),
(15, 5, 39, 'uploads/documents/cf4bfb23d47dcaf3_1787621937.pdf', 'Activty-1 (1).pdf', '2026-08-25 09:38:57'),
(16, 5, 40, 'uploads/documents/e2155aeb51c5080b_1787621937.pdf', 'Activty-1 (1).pdf', '2026-08-25 09:38:57');

-- --------------------------------------------------------

--
-- Table structure for table `assistance_beneficiaries`
--

CREATE TABLE `assistance_beneficiaries` (
  `beneficiary_id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `type` enum('cash','in_kind') NOT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `items` varchar(255) DEFAULT NULL,
  `quantity` int(11) DEFAULT NULL,
  `status` enum('pending','released','distributed') NOT NULL DEFAULT 'pending',
  `date_released` date DEFAULT NULL,
  `date_distributed` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assistance_beneficiaries`
--

INSERT INTO `assistance_beneficiaries` (`beneficiary_id`, `application_id`, `type`, `amount`, `items`, `quantity`, `status`, `date_released`, `date_distributed`) VALUES
(2, 3, 'cash', 1500.00, NULL, NULL, 'pending', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `attendance_id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `scholar_id` int(11) NOT NULL,
  `status` enum('pending','present','absent') NOT NULL DEFAULT 'pending',
  `qr_token` varchar(64) NOT NULL,
  `scanned_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`attendance_id`, `activity_id`, `scholar_id`, `status`, `qr_token`, `scanned_at`) VALUES
(1, 1, 1, 'present', '180d5fa8c9dc73313e7bca6ca551e59d', '2026-08-25 00:00:02');

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `full_name` varchar(200) NOT NULL,
  `email` varchar(150) NOT NULL,
  `action` varchar(100) NOT NULL,
  `details` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`log_id`, `user_id`, `full_name`, `email`, `action`, `details`, `created_at`) VALUES
(1, 2, 'Jane Dela Cruz', 'jane.delacruz@example.com', 'Registered', NULL, '2026-08-24 23:43:24'),
(2, 2, 'Jane Dela Cruz', 'jane.delacruz@example.com', 'Submitted Application', 'Education application #1', '2026-08-24 23:43:24'),
(3, 1, 'Admin User', 'admin@langkiwa.gov.ph', 'Logged In', NULL, '2026-08-24 23:46:27'),
(4, 1, 'Admin User', 'admin@langkiwa.gov.ph', 'Approved Application', 'Education applicant #1', '2026-08-24 23:47:31'),
(5, 2, 'Jane Dela Cruz', 'jane.delacruz@example.com', 'Logged Out', NULL, '2026-08-24 23:58:52'),
(6, 2, 'Jane Dela Cruz', 'jane.delacruz@example.com', 'Logged In', NULL, '2026-08-24 23:58:52'),
(7, 1, 'Admin User', 'admin@langkiwa.gov.ph', 'Added Activity', 'SK/KK Assembly', '2026-08-24 23:59:46'),
(8, 1, 'Admin User', 'admin@langkiwa.gov.ph', 'Scanned Attendance', 'Jane Dela Cruz - activity #1', '2026-08-25 00:00:02'),
(9, 2, 'Jane Dela Cruz', 'jane.delacruz@example.com', 'Logged In', NULL, '2026-08-25 00:00:43'),
(10, 1, 'Admin User', 'admin@langkiwa.gov.ph', 'Added Form Field', 'Test Field', '2026-08-25 09:23:31'),
(11, 1, 'Admin User', 'admin@langkiwa.gov.ph', 'Removed Form Field', 'Field #50', '2026-08-25 09:23:37'),
(12, 1, 'Admin User', 'admin@langkiwa.gov.ph', 'Posted Announcement', 'Scholarship Application Open', '2026-08-25 09:26:02'),
(13, 1, 'Admin User', 'admin@langkiwa.gov.ph', 'Generated Report', 'Consolidated / All Committees / 2025-01-01 to 2026-12-31', '2026-08-25 09:26:26'),
(14, 4, 'Bob Santos', 'bob.santos@example.com', 'Registered', NULL, '2026-08-25 09:28:05'),
(15, 4, 'Bob Santos', 'bob.santos@example.com', 'Submitted Application', 'Health application #3', '2026-08-25 09:28:05'),
(16, 1, 'Admin User', 'admin@langkiwa.gov.ph', 'Approved Application', 'Health applicant #3', '2026-08-25 09:28:18'),
(17, 1, 'Admin User', 'admin@langkiwa.gov.ph', 'Logged In', NULL, '2026-08-25 09:28:35'),
(18, 1, 'Admin User', 'admin@langkiwa.gov.ph', 'Added Cash Beneficiary', 'Health application #3', '2026-08-25 09:28:59'),
(19, 5, 'Carla Reyes', 'carla.reyes@example.com', 'Registered', NULL, '2026-08-25 09:29:54'),
(20, 5, 'Carla Reyes', 'carla.reyes@example.com', 'Submitted Application', 'Education application #4', '2026-08-25 09:29:54'),
(21, 1, 'Admin User', 'admin@langkiwa.gov.ph', 'Logged In', NULL, '2026-08-25 09:34:22'),
(22, 1, 'Admin User', 'admin@langkiwa.gov.ph', 'Updated Applicant', 'Education applicant #4', '2026-08-25 09:35:43'),
(23, 1, 'Admin User', 'admin@langkiwa.gov.ph', 'Approved Application', 'Education applicant #4', '2026-08-25 09:35:50'),
(24, 1, 'Admin User', 'admin@langkiwa.gov.ph', 'Logged Out', NULL, '2026-08-25 09:36:16'),
(25, 6, 'Yhianzy Solis', 'yhianzy@gmail.com', 'Registered', NULL, '2026-08-25 09:36:49'),
(26, 1, 'Admin User', 'admin@langkiwa.gov.ph', 'Logged Out', NULL, '2026-08-25 09:37:03'),
(27, 6, 'Yhianzy Solis', 'yhianzy@gmail.com', 'Submitted Application', 'Sports application #5', '2026-08-25 09:38:57'),
(28, 6, 'Yhianzy Solis', 'yhianzy@gmail.com', 'Logged Out', NULL, '2026-08-25 09:39:33'),
(29, 6, 'Yhianzy Solis', 'yhianzy@gmail.com', 'Logged In', NULL, '2026-08-25 09:39:46'),
(30, 6, 'Yhianzy Solis', 'yhianzy@gmail.com', 'Logged Out', NULL, '2026-08-25 09:40:00'),
(31, 1, 'Admin User', 'admin@langkiwa.gov.ph', 'Logged In', NULL, '2026-08-25 09:40:12'),
(32, 1, 'Admin User', 'admin@langkiwa.gov.ph', 'Declined Application', 'Sports applicant #5', '2026-08-25 09:40:56'),
(33, 1, 'Admin User', 'admin@langkiwa.gov.ph', 'Logged Out', NULL, '2026-08-25 09:41:03'),
(34, 6, 'Yhianzy Solis', 'yhianzy@gmail.com', 'Logged In', NULL, '2026-08-25 09:41:13'),
(35, 6, 'Yhianzy Solis', 'yhianzy@gmail.com', 'Logged Out', NULL, '2026-08-25 09:42:11'),
(36, 1, 'Admin User', 'admin@langkiwa.gov.ph', 'Logged In', NULL, '2026-08-25 09:42:18');

-- --------------------------------------------------------

--
-- Table structure for table `committees`
--

CREATE TABLE `committees` (
  `committee_id` int(11) NOT NULL,
  `code` varchar(40) NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `icon` varchar(60) DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `committees`
--

INSERT INTO `committees` (`committee_id`, `code`, `name`, `description`, `icon`, `archived_at`) VALUES
(1, 'education', 'Education', 'iSKolar ng Langkiwa and education assistance programs', 'bi-mortarboard-fill', NULL),
(2, 'health', 'Health', 'Medical and health assistance programs', 'bi-heart-pulse-fill', NULL),
(3, 'sports', 'Sports', 'Sports development and assistance programs', 'bi-trophy-fill', NULL),
(4, 'active_citizenship', 'Active Citizenship', 'Civic engagement and active citizenship programs', 'bi-people-fill', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `form_fields`
--

CREATE TABLE `form_fields` (
  `field_id` int(11) NOT NULL,
  `committee_id` int(11) NOT NULL,
  `program_track` varchar(30) NOT NULL DEFAULT 'assistance',
  `label` varchar(150) NOT NULL,
  `field_key` varchar(100) NOT NULL,
  `input_type` enum('text','number','date','textarea','dropdown','file','radio') NOT NULL,
  `icon` varchar(60) DEFAULT NULL,
  `width` enum('third','half','two_third','full') NOT NULL DEFAULT 'full',
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `options` text DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `archived_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `form_fields`
--

INSERT INTO `form_fields` (`field_id`, `committee_id`, `program_track`, `label`, `field_key`, `input_type`, `icon`, `width`, `is_required`, `options`, `sort_order`, `archived_at`) VALUES
(1, 1, 'scholarship', 'Last Name', 'last_name', 'text', 'bi-person-fill', 'third', 1, NULL, 1, NULL),
(2, 1, 'scholarship', 'First Name', 'first_name', 'text', 'bi-person-fill', 'third', 1, NULL, 2, NULL),
(3, 1, 'scholarship', 'Middle Name', 'middle_name', 'text', 'bi-person-fill', 'third', 0, NULL, 3, NULL),
(4, 1, 'scholarship', 'Complete Address', 'complete_address', 'text', 'bi-geo-alt-fill', 'full', 1, NULL, 4, NULL),
(5, 1, 'scholarship', 'Year Level', 'year_level', 'dropdown', 'bi-bar-chart-fill', 'half', 1, '[\"1st Year\",\"2nd Year\",\"3rd Year\",\"4th Year\"]', 5, NULL),
(6, 1, 'scholarship', 'School/University', 'school_university', 'text', 'bi-building', 'half', 1, NULL, 6, NULL),
(7, 1, 'scholarship', 'Course', 'course', 'text', 'bi-journal-bookmark-fill', 'half', 1, NULL, 7, NULL),
(8, 1, 'scholarship', 'Barangay Indigency', 'barangay_indigency', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 8, NULL),
(9, 1, 'scholarship', 'Copy of Grades (Last Semester)', 'grades_last_semester', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 9, NULL),
(10, 1, 'scholarship', 'School Registration Form (Current Semester)', 'school_registration_form', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 10, NULL),
(11, 1, 'scholarship', 'Valid ID', 'valid_id', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 11, NULL),
(12, 1, 'assistance', 'Last Name', 'last_name', 'text', 'bi-person-fill', 'third', 1, NULL, 1, NULL),
(13, 1, 'assistance', 'First Name', 'first_name', 'text', 'bi-person-fill', 'third', 1, NULL, 2, NULL),
(14, 1, 'assistance', 'Middle Name', 'middle_name', 'text', 'bi-person-fill', 'third', 0, NULL, 3, NULL),
(15, 1, 'assistance', 'Complete Address', 'complete_address', 'text', 'bi-geo-alt-fill', 'full', 1, NULL, 4, NULL),
(16, 1, 'assistance', 'Type of Assistance', 'assistance_type', 'dropdown', 'bi-cash-coin', 'full', 1, '[\"Tuition Fee Assistance\",\"School Supplies Assistance\",\"Transportation Assistance\"]', 5, NULL),
(17, 1, 'assistance', 'Barangay Indigency', 'barangay_indigency', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 6, NULL),
(18, 1, 'assistance', 'Certificate of Enrollment', 'certificate_enrollment', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 7, NULL),
(19, 1, 'assistance', 'Report Card / Grades', 'report_card', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 8, NULL),
(20, 1, 'assistance', 'Valid ID', 'valid_id', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 9, NULL),
(21, 2, 'assistance', 'Last Name', 'last_name', 'text', 'bi-person-fill', 'third', 1, NULL, 1, NULL),
(22, 2, 'assistance', 'First Name', 'first_name', 'text', 'bi-person-fill', 'third', 1, NULL, 2, NULL),
(23, 2, 'assistance', 'Middle Name', 'middle_name', 'text', 'bi-person-fill', 'third', 0, NULL, 3, NULL),
(24, 2, 'assistance', 'Complete Address', 'complete_address', 'text', 'bi-geo-alt-fill', 'full', 1, NULL, 4, NULL),
(25, 2, 'assistance', 'Type of Assistance', 'assistance_type', 'radio', 'bi-cash-coin', 'full', 1, '[\"Cash Assistance\",\"In-kind Assistance\"]', 5, NULL),
(26, 2, 'assistance', 'Letter Request', 'letter_request', 'textarea', 'bi-envelope-fill', 'full', 1, NULL, 6, NULL),
(27, 2, 'assistance', 'Barangay Indigency', 'barangay_indigency', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 7, NULL),
(28, 2, 'assistance', 'Medical Certificate', 'medical_certificate', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 8, NULL),
(29, 2, 'assistance', 'Hospital Bill / Prescription', 'hospital_bill', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 9, NULL),
(30, 2, 'assistance', 'Valid ID', 'valid_id', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 10, NULL),
(31, 3, 'assistance', 'Last Name', 'last_name', 'text', 'bi-person-fill', 'third', 1, NULL, 1, NULL),
(32, 3, 'assistance', 'First Name', 'first_name', 'text', 'bi-person-fill', 'third', 1, NULL, 2, NULL),
(33, 3, 'assistance', 'Middle Name', 'middle_name', 'text', 'bi-person-fill', 'third', 0, NULL, 3, NULL),
(34, 3, 'assistance', 'Complete Address', 'complete_address', 'text', 'bi-geo-alt-fill', 'full', 1, NULL, 4, NULL),
(35, 3, 'assistance', 'Type of Assistance', 'assistance_type', 'radio', 'bi-cash-coin', 'full', 1, '[\"Cash Assistance\",\"In-kind Assistance\"]', 5, NULL),
(36, 3, 'assistance', 'Letter Request', 'letter_request', 'textarea', 'bi-envelope-fill', 'full', 1, NULL, 6, NULL),
(37, 3, 'assistance', 'Barangay Indigency', 'barangay_indigency', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 7, NULL),
(38, 3, 'assistance', 'Certificate of Participation', 'certificate_participation', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 8, NULL),
(39, 3, 'assistance', 'Proof of Event Registration', 'proof_event_registration', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 9, NULL),
(40, 3, 'assistance', 'Valid ID', 'valid_id', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 10, NULL),
(41, 4, 'assistance', 'Last Name', 'last_name', 'text', 'bi-person-fill', 'third', 1, NULL, 1, NULL),
(42, 4, 'assistance', 'First Name', 'first_name', 'text', 'bi-person-fill', 'third', 1, NULL, 2, NULL),
(43, 4, 'assistance', 'Middle Name', 'middle_name', 'text', 'bi-person-fill', 'third', 0, NULL, 3, NULL),
(44, 4, 'assistance', 'Complete Address', 'complete_address', 'text', 'bi-geo-alt-fill', 'full', 1, NULL, 4, NULL),
(45, 4, 'assistance', 'Type of Assistance', 'assistance_type', 'radio', 'bi-cash-coin', 'full', 1, '[\"Cash Assistance\",\"In-kind Assistance\"]', 5, NULL),
(46, 4, 'assistance', 'Barangay Indigency', 'barangay_indigency', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 6, NULL),
(47, 4, 'assistance', 'Certificate of Participation', 'certificate_participation', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 7, NULL),
(48, 4, 'assistance', 'Proof of Involvement', 'proof_involvement', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 8, NULL),
(49, 4, 'assistance', 'Valid ID', 'valid_id', 'file', 'bi-file-earmark-text-fill', 'half', 1, NULL, 9, NULL),
(50, 1, 'scholarship', 'Test Field', 'test_field', 'text', 'bi-fonts', '', 0, NULL, 12, '2026-08-25 09:23:37');

-- --------------------------------------------------------

--
-- Table structure for table `important_dates`
--

CREATE TABLE `important_dates` (
  `date_id` int(11) NOT NULL,
  `event_name` varchar(150) NOT NULL,
  `event_date` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `programs`
--

CREATE TABLE `programs` (
  `program_id` int(11) NOT NULL,
  `committee_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `assistance_type` enum('cash','in_kind') NOT NULL DEFAULT 'cash',
  `amount` decimal(10,2) DEFAULT NULL,
  `release_schedule` varchar(60) DEFAULT NULL,
  `app_start_date` date DEFAULT NULL,
  `app_end_date` date DEFAULT NULL,
  `eligibility_requirements` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `archived_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `scholars`
--

CREATE TABLE `scholars` (
  `scholar_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `school` varchar(150) DEFAULT NULL,
  `course` varchar(150) DEFAULT NULL,
  `year_level` tinyint(4) DEFAULT NULL,
  `status` enum('active','archived') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `scholars`
--

INSERT INTO `scholars` (`scholar_id`, `user_id`, `application_id`, `school`, `course`, `year_level`, `status`, `created_at`) VALUES
(1, 2, 1, 'Cavite State University', 'BS Information Technology', 3, 'active', '2026-08-24 23:47:31'),
(2, 5, 4, 'Trimex Colleges Binan', 'BS Psychology', 1, 'active', '2026-08-25 09:35:50');

-- --------------------------------------------------------

--
-- Table structure for table `scholar_requirement_files`
--

CREATE TABLE `scholar_requirement_files` (
  `requirement_file_id` int(11) NOT NULL,
  `scholar_id` int(11) NOT NULL,
  `field_id` int(11) NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `semester` varchar(20) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `site_settings`
--

CREATE TABLE `site_settings` (
  `id` tinyint(4) NOT NULL DEFAULT 1,
  `logo_path` varchar(255) DEFAULT NULL,
  `about_text` text DEFAULT NULL,
  `sk_office_address` varchar(255) DEFAULT NULL,
  `contact_number` varchar(40) DEFAULT NULL,
  `office_hours` varchar(100) DEFAULT NULL,
  `site_name` varchar(150) DEFAULT NULL,
  `tagline` varchar(200) DEFAULT NULL,
  `welcome_message` text DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `facebook_url` varchar(255) DEFAULT NULL,
  `allow_public_applications` tinyint(1) NOT NULL DEFAULT 1,
  `show_announcements` tinyint(1) NOT NULL DEFAULT 1,
  `current_academic_year` varchar(20) NOT NULL DEFAULT '2025-2026',
  `current_semester` varchar(20) NOT NULL DEFAULT '2nd Semester',
  `default_allowance_amount` decimal(10,2) NOT NULL DEFAULT 2000.00,
  `activities_required_per_term` int(11) NOT NULL DEFAULT 3
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `site_settings`
--

INSERT INTO `site_settings` (`id`, `logo_path`, `about_text`, `sk_office_address`, `contact_number`, `office_hours`, `site_name`, `tagline`, `welcome_message`, `email`, `facebook_url`, `allow_public_applications`, `show_announcements`, `current_academic_year`, `current_semester`, `default_allowance_amount`, `activities_required_per_term`) VALUES
(1, NULL, 'The SK Langkiwa Financial Assistance Program supports the youth of the barangay through scholarships and assistance programs.', 'Barangay Langkiwa Hall', '(046) 000-0000', 'Mon-Fri, 8:00 AM - 5:00 PM', 'Sangguniang Kabataan ng Langkiwa', 'Serving the youth of Barangay Langkiwa', NULL, 'sklangkiwa@example.gov.ph', NULL, 1, 1, '2025-2026', '2nd Semester', 2000.00, 3);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `age` int(11) DEFAULT NULL,
  `gender` enum('Male','Female') DEFAULT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('applicant','scholar','admin') NOT NULL DEFAULT 'applicant',
  `position_title` varchar(100) DEFAULT NULL,
  `profile_photo` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive','archived') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `archived_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `last_name`, `first_name`, `middle_name`, `age`, `gender`, `email`, `password_hash`, `role`, `position_title`, `profile_photo`, `status`, `created_at`, `archived_at`) VALUES
(1, 'User', 'Admin', NULL, 30, 'Male', 'admin@langkiwa.gov.ph', '$2y$10$Bw9oS915KhWHClJFIJ01M.iVIb3V.o4WM8MOWvPCV4CTiZkD6JrPC', 'admin', 'Admin/SK Chairperson', NULL, 'active', '2026-08-24 23:43:06', NULL),
(2, 'Dela Cruz', 'Jane', 'Dalisay', 20, 'Female', 'jane.delacruz@example.com', '$2y$10$ubKosujLA9e5uhWcYsLFbeYpCFNMx6BvGKCMzoky0nFYaiDm6smmm', 'scholar', NULL, NULL, 'active', '2026-08-24 23:43:24', NULL),
(4, 'Santos', 'Bob', '', 22, 'Male', 'bob.santos@example.com', '$2y$10$NUDXI3mAiz4SnBuk1Ry0kOpx1KdR1nLJE9U0asD9.GpjzmsRnWZze', 'applicant', NULL, NULL, 'active', '2026-08-25 09:28:05', NULL),
(5, 'Reyes', 'Carla', '', 21, 'Female', 'carla.reyes@example.com', '$2y$10$4QnURfp93DWbyqFpdmjE4OMz2iaBWDsPm3we9m9tGXPKZKxA2Eb0O', 'scholar', NULL, NULL, 'active', '2026-08-25 09:29:54', NULL),
(6, 'Solis', 'Yhianzy', '', 21, 'Male', 'yhianzy@gmail.com', '$2y$10$6DZzGSVZxQFVt7oM.qNBm.oQ.lViUE2NNLHNknRAEogb4SlMtbcuK', 'applicant', NULL, NULL, 'active', '2026-08-25 09:36:49', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activities`
--
ALTER TABLE `activities`
  ADD PRIMARY KEY (`activity_id`),
  ADD KEY `committee_id` (`committee_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `activity_scholars`
--
ALTER TABLE `activity_scholars`
  ADD PRIMARY KEY (`activity_id`,`scholar_id`),
  ADD KEY `scholar_id` (`scholar_id`);

--
-- Indexes for table `allowance_distributions`
--
ALTER TABLE `allowance_distributions`
  ADD PRIMARY KEY (`distribution_id`),
  ADD UNIQUE KEY `uniq_scholar_term` (`scholar_id`,`academic_year`,`semester`);

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`announcement_id`),
  ADD KEY `committee_id` (`committee_id`),
  ADD KEY `posted_by` (`posted_by`);

--
-- Indexes for table `applications`
--
ALTER TABLE `applications`
  ADD PRIMARY KEY (`application_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `committee_id` (`committee_id`),
  ADD KEY `program_id` (`program_id`),
  ADD KEY `decided_by` (`decided_by`);

--
-- Indexes for table `application_answers`
--
ALTER TABLE `application_answers`
  ADD PRIMARY KEY (`answer_id`),
  ADD KEY `application_id` (`application_id`),
  ADD KEY `field_id` (`field_id`);

--
-- Indexes for table `application_files`
--
ALTER TABLE `application_files`
  ADD PRIMARY KEY (`file_id`),
  ADD KEY `application_id` (`application_id`),
  ADD KEY `field_id` (`field_id`);

--
-- Indexes for table `assistance_beneficiaries`
--
ALTER TABLE `assistance_beneficiaries`
  ADD PRIMARY KEY (`beneficiary_id`),
  ADD UNIQUE KEY `application_id` (`application_id`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`attendance_id`),
  ADD UNIQUE KEY `qr_token` (`qr_token`),
  ADD UNIQUE KEY `uniq_activity_scholar` (`activity_id`,`scholar_id`),
  ADD KEY `scholar_id` (`scholar_id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `committees`
--
ALTER TABLE `committees`
  ADD PRIMARY KEY (`committee_id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `form_fields`
--
ALTER TABLE `form_fields`
  ADD PRIMARY KEY (`field_id`),
  ADD KEY `committee_id` (`committee_id`);

--
-- Indexes for table `important_dates`
--
ALTER TABLE `important_dates`
  ADD PRIMARY KEY (`date_id`);

--
-- Indexes for table `programs`
--
ALTER TABLE `programs`
  ADD PRIMARY KEY (`program_id`),
  ADD KEY `committee_id` (`committee_id`);

--
-- Indexes for table `scholars`
--
ALTER TABLE `scholars`
  ADD PRIMARY KEY (`scholar_id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD KEY `application_id` (`application_id`);

--
-- Indexes for table `scholar_requirement_files`
--
ALTER TABLE `scholar_requirement_files`
  ADD PRIMARY KEY (`requirement_file_id`),
  ADD KEY `scholar_id` (`scholar_id`),
  ADD KEY `field_id` (`field_id`);

--
-- Indexes for table `site_settings`
--
ALTER TABLE `site_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activities`
--
ALTER TABLE `activities`
  MODIFY `activity_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `allowance_distributions`
--
ALTER TABLE `allowance_distributions`
  MODIFY `distribution_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `announcement_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `applications`
--
ALTER TABLE `applications`
  MODIFY `application_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `application_answers`
--
ALTER TABLE `application_answers`
  MODIFY `answer_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `application_files`
--
ALTER TABLE `application_files`
  MODIFY `file_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `assistance_beneficiaries`
--
ALTER TABLE `assistance_beneficiaries`
  MODIFY `beneficiary_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `attendance_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `committees`
--
ALTER TABLE `committees`
  MODIFY `committee_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `form_fields`
--
ALTER TABLE `form_fields`
  MODIFY `field_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=51;

--
-- AUTO_INCREMENT for table `important_dates`
--
ALTER TABLE `important_dates`
  MODIFY `date_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `programs`
--
ALTER TABLE `programs`
  MODIFY `program_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `scholars`
--
ALTER TABLE `scholars`
  MODIFY `scholar_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `scholar_requirement_files`
--
ALTER TABLE `scholar_requirement_files`
  MODIFY `requirement_file_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activities`
--
ALTER TABLE `activities`
  ADD CONSTRAINT `activities_ibfk_1` FOREIGN KEY (`committee_id`) REFERENCES `committees` (`committee_id`),
  ADD CONSTRAINT `activities_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `activity_scholars`
--
ALTER TABLE `activity_scholars`
  ADD CONSTRAINT `activity_scholars_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`activity_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `activity_scholars_ibfk_2` FOREIGN KEY (`scholar_id`) REFERENCES `scholars` (`scholar_id`);

--
-- Constraints for table `allowance_distributions`
--
ALTER TABLE `allowance_distributions`
  ADD CONSTRAINT `allowance_distributions_ibfk_1` FOREIGN KEY (`scholar_id`) REFERENCES `scholars` (`scholar_id`);

--
-- Constraints for table `announcements`
--
ALTER TABLE `announcements`
  ADD CONSTRAINT `announcements_ibfk_1` FOREIGN KEY (`committee_id`) REFERENCES `committees` (`committee_id`),
  ADD CONSTRAINT `announcements_ibfk_2` FOREIGN KEY (`posted_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `applications`
--
ALTER TABLE `applications`
  ADD CONSTRAINT `applications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `applications_ibfk_2` FOREIGN KEY (`committee_id`) REFERENCES `committees` (`committee_id`),
  ADD CONSTRAINT `applications_ibfk_3` FOREIGN KEY (`program_id`) REFERENCES `programs` (`program_id`),
  ADD CONSTRAINT `applications_ibfk_4` FOREIGN KEY (`decided_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `application_answers`
--
ALTER TABLE `application_answers`
  ADD CONSTRAINT `application_answers_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `applications` (`application_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `application_answers_ibfk_2` FOREIGN KEY (`field_id`) REFERENCES `form_fields` (`field_id`);

--
-- Constraints for table `application_files`
--
ALTER TABLE `application_files`
  ADD CONSTRAINT `application_files_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `applications` (`application_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `application_files_ibfk_2` FOREIGN KEY (`field_id`) REFERENCES `form_fields` (`field_id`);

--
-- Constraints for table `assistance_beneficiaries`
--
ALTER TABLE `assistance_beneficiaries`
  ADD CONSTRAINT `assistance_beneficiaries_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `applications` (`application_id`);

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`activity_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`scholar_id`) REFERENCES `scholars` (`scholar_id`);

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `form_fields`
--
ALTER TABLE `form_fields`
  ADD CONSTRAINT `form_fields_ibfk_1` FOREIGN KEY (`committee_id`) REFERENCES `committees` (`committee_id`);

--
-- Constraints for table `programs`
--
ALTER TABLE `programs`
  ADD CONSTRAINT `programs_ibfk_1` FOREIGN KEY (`committee_id`) REFERENCES `committees` (`committee_id`);

--
-- Constraints for table `scholars`
--
ALTER TABLE `scholars`
  ADD CONSTRAINT `scholars_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `scholars_ibfk_2` FOREIGN KEY (`application_id`) REFERENCES `applications` (`application_id`);

--
-- Constraints for table `scholar_requirement_files`
--
ALTER TABLE `scholar_requirement_files`
  ADD CONSTRAINT `scholar_requirement_files_ibfk_1` FOREIGN KEY (`scholar_id`) REFERENCES `scholars` (`scholar_id`),
  ADD CONSTRAINT `scholar_requirement_files_ibfk_2` FOREIGN KEY (`field_id`) REFERENCES `form_fields` (`field_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
