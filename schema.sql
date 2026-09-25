-- Sunday School Platform Database Schema
-- Complete clean database structure for fresh installation
-- Generated with all tables, primary keys, indexes, and constraints

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";






CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `church_id` int(11) NOT NULL,
  `uncle_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `announcements` (
  `id` int(11) NOT NULL,
  `church_id` int(11) NOT NULL,
  `type` enum('message','button') NOT NULL,
  `text` text NOT NULL,
  `link` text DEFAULT NULL,
  `class` varchar(50) DEFAULT 'جميع',
  `student_names` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `class_id` int(11) DEFAULT NULL,
  `church_id` int(11) NOT NULL,
  `uncle_id` int(11) DEFAULT NULL,
  `attendance_date` date NOT NULL,
  `status` enum('present','absent') NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `church_id` int(11) NOT NULL,
  `uncle_id` int(11) DEFAULT NULL,
  `uncle_name` varchar(100) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `entity` varchar(30) NOT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `entity_name` varchar(200) DEFAULT NULL,
  `old_data` longtext DEFAULT NULL,
  `new_data` longtext DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Full audit trail of every important change in the system';





CREATE TABLE `churches` (
  `id` int(11) NOT NULL,
  `church_name` varchar(100) NOT NULL,
  `church_code` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `admin_email` varchar(255) DEFAULT NULL,
  `admin_emails` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `church_type` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `church_classes` (
  `id` int(11) NOT NULL,
  `church_id` int(11) NOT NULL,
  `code` varchar(30) NOT NULL,
  `arabic_name` varchar(100) NOT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `order` int(11) NOT NULL DEFAULT 0 COMMENT 'Grade sequence (lowest = youngest); used for promote-to-next-grade',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `color` varchar(20) DEFAULT '#4f46e5',
  `icon` varchar(30) DEFAULT '' COMMENT 'FontAwesome class or number string'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `church_reg_keys` (
  `id` int(11) NOT NULL,
  `reg_key` varchar(64) NOT NULL,
  `label` varchar(255) DEFAULT '',
  `created_by` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `used_at` datetime DEFAULT NULL,
  `used_by_church` int(11) DEFAULT NULL,
  `is_revoked` tinyint(1) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `church_id` int(11) DEFAULT NULL COMMENT 'FK → churches.id — set after successful registration'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;





CREATE TABLE `church_settings` (
  `id` int(11) NOT NULL,
  `church_id` int(11) NOT NULL,
  `attendance_day` tinyint(4) NOT NULL DEFAULT 5 COMMENT '1=Mon 2=Tue 3=Wed 4=Thu 5=Fri 6=Sat 7=Sun',
  `uncle_class_navigation` varchar(10) NOT NULL DEFAULT 'all' COMMENT 'all | own',
  `combined_class_groups` text DEFAULT NULL COMMENT 'JSON array of {label, classes[]} groups',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `custom_field` text DEFAULT NULL COMMENT 'JSON array [{name,icon,key}] — multiple custom fields per church',
  `view_mode` varchar(10) NOT NULL DEFAULT 'classes' COMMENT 'classes | all | both',
  `auto_grade_month` tinyint(3) UNSIGNED DEFAULT NULL COMMENT '1-12; month when annual grade-up runs',
  `auto_grade_day` tinyint(3) UNSIGNED DEFAULT NULL COMMENT '1-31; day when annual grade-up runs',
  `last_auto_grade_year` smallint(5) UNSIGNED DEFAULT NULL COMMENT 'Last calendar year auto grade-up completed'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `classes` (
  `id` int(11) NOT NULL,
  `code` varchar(20) NOT NULL,
  `arabic_name` varchar(50) NOT NULL,
  `display_order` int(11) NOT NULL,
  `color` varchar(20) DEFAULT '#4f46e5'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `coupon_logs` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `uncle_id` int(11) DEFAULT NULL,
  `old_count` int(11) NOT NULL DEFAULT 0,
  `new_count` int(11) NOT NULL DEFAULT 0,
  `change_amount` int(11) NOT NULL DEFAULT 0,
  `change_type` enum('manual','attendance','commitment','system','task') DEFAULT 'manual',
  `reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `coupon_withdrawals` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `uncle_id` int(11) NOT NULL,
  `amount` int(11) NOT NULL,
  `att_amount` int(11) DEFAULT 0,
  `com_amount` int(11) DEFAULT 0,
  `tsk_amount` int(11) DEFAULT 0,
  `note` text DEFAULT NULL,
  `is_refunded` tinyint(1) DEFAULT 0,
  `refunded_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `custom_field_templates` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `custom_fields` text NOT NULL,
  `custom_field_icons` text NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `developer_messages` (
  `id` int(11) NOT NULL,
  `to_church_id` int(11) NOT NULL DEFAULT 0,
  `subject` varchar(300) NOT NULL,
  `body` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `exam_starts` (
  `id` int(11) NOT NULL,
  `task_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `church_id` int(11) NOT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;





CREATE TABLE `guests` (
  `id` int(11) NOT NULL,
  `church_id` int(11) NOT NULL COMMENT 'Which church added this guest',
  `name` varchar(255) NOT NULL COMMENT 'Full name of the guest child',
  `phone` varchar(20) DEFAULT NULL COMMENT 'Phone number (guardian/child)',
  `guardian_name` varchar(255) DEFAULT NULL COMMENT 'Name of parent/guardian',
  `class` varchar(100) DEFAULT NULL COMMENT 'Class/grade if known',
  `gender` enum('male','female') DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL COMMENT 'Uncle ID who added the guest',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `church_id` int(11) NOT NULL,
  `type` enum('registration','task_submission','developer_message','system','announcement') NOT NULL,
  `title` varchar(300) NOT NULL,
  `body` text DEFAULT NULL,
  `entity_type` varchar(50) DEFAULT NULL COMMENT 'student | task | uncle | etc.',
  `entity_id` int(11) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `pending_registrations` (
  `id` int(11) NOT NULL,
  `church_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `gender` varchar(10) DEFAULT 'male',
  `username` varchar(50) DEFAULT NULL COMMENT 'Student username for login',
  `password_hash` varchar(255) DEFAULT NULL COMMENT 'Hashed password for student login',
  `profile_photo` varchar(255) DEFAULT NULL COMMENT 'Path to profile photo',
  `class` varchar(50) NOT NULL,
  `birthday` date DEFAULT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` mediumtext DEFAULT NULL,
  `extra_data` longtext DEFAULT NULL COMMENT 'JSON key-value of church custom registration fields',
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `rejection_note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `approved_at` timestamp NULL DEFAULT NULL,
  `image_url` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `rooms_templates` (
  `id` int(11) NOT NULL,
  `church_id` int(11) DEFAULT NULL COMMENT 'NULL for developer default templates',
  `name` varchar(255) NOT NULL,
  `config` text NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `students` (
  `id` int(11) NOT NULL,
  `church_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `gender` varchar(10) NOT NULL DEFAULT 'male',
  `class_id` int(11) NOT NULL,
  `class` varchar(50) DEFAULT NULL,
  `enrollment_status` varchar(20) NOT NULL DEFAULT 'active' COMMENT 'active | graduate',
  `address` text DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `emergency_phone` varchar(20) DEFAULT NULL,
  `medical_notes` text DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `coupons` int(11) DEFAULT 0,
  `attendance_coupons` int(11) DEFAULT 0,
  `commitment_coupons` int(11) DEFAULT 0,
  `image_url` text DEFAULT NULL,
  `password_hash` varchar(64) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `custom_info` text DEFAULT NULL COMMENT 'JSON key-value: {"value":"..."} — one field per church definition',
  `email` varchar(255) DEFAULT NULL COMMENT 'Email from registration form',
  `task_coupons` int(11) NOT NULL DEFAULT 0 COMMENT 'Coupons earned from tasks',
  `trip_points` text DEFAULT NULL,
  `graduate_from_class_id` int(11) DEFAULT NULL,
  `graduate_from_class` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `student_sibling_groups` (
  `id` varchar(64) NOT NULL,
  `church_id` int(11) NOT NULL,
  `label` varchar(255) DEFAULT '',
  `status` enum('approved','pending','rejected') NOT NULL DEFAULT 'approved',
  `linked_by_id` int(11) DEFAULT NULL,
  `linked_by_name` varchar(255) DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `student_sibling_group_members` (
  `student_id` int(11) NOT NULL,
  `group_id` varchar(64) NOT NULL,
  `church_id` int(11) NOT NULL,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `student_transfer_requests` (
  `id` int(11) NOT NULL,
  `from_church_id` int(11) NOT NULL,
  `to_church_id` int(11) NOT NULL,
  `from_student_id` int(11) DEFAULT NULL,
  `student_name` varchar(100) NOT NULL DEFAULT '',
  `student_snapshot` longtext NOT NULL COMMENT 'JSON full student row at send time',
  `status` enum('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
  `target_class_id` int(11) DEFAULT NULL COMMENT 'Chosen by receiving church on accept',
  `accepted_student_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `tasks` (
  `id` int(11) NOT NULL,
  `church_id` int(11) NOT NULL,
  `uncle_id` int(11) NOT NULL,
  `class_id` int(11) DEFAULT NULL,
  `class_ids` varchar(255) DEFAULT NULL,
  `title` varchar(300) NOT NULL,
  `description` text DEFAULT NULL,
  `start_date` datetime NOT NULL,
  `end_date` datetime NOT NULL,
  `no_deadline` tinyint(1) NOT NULL DEFAULT 0,
  `time_limit` int(11) DEFAULT NULL COMMENT 'Minutes; NULL = no timer',
  `total_degree` int(11) NOT NULL DEFAULT 0,
  `max_coupons` int(11) NOT NULL DEFAULT 0,
  `coupon_matrix` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '[{pct, min, val}]',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `status` varchar(50) DEFAULT NULL,
  `assign_to` varchar(50) DEFAULT NULL,
  `specific_ids` text DEFAULT NULL,
  `shuffle` tinyint(1) NOT NULL DEFAULT 0,
  `show_result` tinyint(1) NOT NULL DEFAULT 1,
  `show_answers` tinyint(1) NOT NULL DEFAULT 0,
  `allow_review` tinyint(1) NOT NULL DEFAULT 0,
  `timer_behavior` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `task_questions` (
  `id` int(11) NOT NULL,
  `question_type` enum('mcq','open','tf') NOT NULL DEFAULT 'mcq',
  `task_id` int(11) NOT NULL,
  `question_text` text NOT NULL,
  `options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT '["option A", "option B", ...]',
  `correct_index` int(11) DEFAULT NULL,
  `degree` int(11) NOT NULL DEFAULT 0,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `image_url` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `task_submissions` (
  `id` int(11) NOT NULL,
  `task_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `church_id` int(11) NOT NULL,
  `answers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '{questionIndex: chosenOptionIndex}',
  `score` int(11) NOT NULL DEFAULT 0,
  `total_degree` int(11) NOT NULL DEFAULT 0,
  `correct_count` int(11) NOT NULL DEFAULT 0,
  `wrong_count` int(11) NOT NULL DEFAULT 0,
  `percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `coupons_earned` int(11) NOT NULL DEFAULT 0,
  `coupons_awarded` int(11) NOT NULL DEFAULT 0,
  `time_taken_secs` int(11) DEFAULT NULL,
  `time_taken_sec` int(11) DEFAULT NULL,
  `open_scores` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `is_graded` tinyint(1) NOT NULL DEFAULT 0,
  `graded_by_uncle_id` int(11) DEFAULT NULL,
  `graded_at` datetime DEFAULT NULL,
  `correction_notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `trips` (
  `id` int(11) NOT NULL,
  `church_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `type` enum('one_day','multiple_days') NOT NULL DEFAULT 'one_day',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(5,2) DEFAULT 0.00,
  `discount_type` enum('percentage','fixed') DEFAULT 'percentage',
  `max_participants` int(11) DEFAULT NULL,
  `status` enum('planned','active','completed','cancelled') DEFAULT 'planned',
  `image_url` varchar(500) DEFAULT NULL,
  `show_registered_kids` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `points_config` text DEFAULT NULL,
  `collaborating_churches` text DEFAULT '[]' COMMENT 'JSON array of church_ids that have joined this trip as collaborators',
  `has_points_game` tinyint(1) DEFAULT 0,
  `custom_fields` text DEFAULT NULL,
  `custom_field_icons` text DEFAULT NULL,
  `has_rooms` tinyint(1) DEFAULT 0,
  `rooms_config` text DEFAULT NULL,
  `collaboration_limits` text DEFAULT NULL,
  `collab_limit_mode` varchar(50) DEFAULT 'open',
  `collab_max_participants` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `trip_collaboration_requests` (
  `id` int(11) NOT NULL,
  `trip_id` int(11) NOT NULL,
  `from_church_id` int(11) NOT NULL,
  `to_church_id` int(11) NOT NULL,
  `status` enum('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
  `registration_limit` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE `trip_expenses` (
  `id` int(11) NOT NULL,
  `trip_id` int(11) NOT NULL,
  `church_id` int(11) NOT NULL,
  `name` varchar(200) DEFAULT NULL,
  `type` enum('bus','food','entry','other') NOT NULL DEFAULT 'other',
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `funding` enum('kids','funded','partial') NOT NULL DEFAULT 'kids',
  `per_kid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `trip_payments` (
  `id` int(11) NOT NULL,
  `registration_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `donation` decimal(10,2) DEFAULT 0.00,
  `payment_date` timestamp NULL DEFAULT current_timestamp(),
  `payment_method` enum('cash','card','bank_transfer','other') DEFAULT 'cash',
  `received_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `trip_registrations` (
  `id` int(11) NOT NULL,
  `trip_id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL COMMENT 'NULL for guest registrations',
  `registered_by` int(11) DEFAULT NULL,
  `registration_date` timestamp NULL DEFAULT current_timestamp(),
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `deposit` decimal(10,2) DEFAULT 0.00,
  `donation` decimal(10,2) DEFAULT 0.00,
  `payment_status` enum('pending','partial','paid') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `payment_history` text DEFAULT '[]' COMMENT 'JSON array of payment events for this registration, including deposits and payments',
  `cancelled` tinyint(1) DEFAULT 0,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `custom_data` text DEFAULT NULL,
  `registration_type` enum('student','other_church_student','guest') DEFAULT 'student' COMMENT 'Type of registration: student (from this church), other_church_student (from collaborating church), or guest (not in system)',
  `church_id` int(11) DEFAULT NULL COMMENT 'Church ID if this is an other_church_student (for tracking which collaborating church they belong to)',
  `guest_name` varchar(255) DEFAULT NULL COMMENT 'Full name of guest registration',
  `guest_phone` varchar(20) DEFAULT NULL COMMENT 'Phone number for guest guardian/contact',
  `guest_guardian_name` varchar(255) DEFAULT NULL COMMENT 'Name of guardian for guest registration',
  `guest_class` varchar(100) DEFAULT NULL COMMENT 'Class/group name for guest registration',
  `guest_notes` text DEFAULT NULL COMMENT 'Additional notes for guest registration',
  `guest_gender` enum('male','female') DEFAULT NULL COMMENT 'Gender of guest child',
  `guest_id` int(11) DEFAULT NULL COMMENT 'FK to guests table (for guest registrations)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `trip_waitlist` (
  `id` int(11) NOT NULL,
  `trip_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `church_id` int(11) NOT NULL,
  `added_by` int(11) DEFAULT NULL,
  `position` int(11) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `deposit` decimal(10,2) DEFAULT 0.00,
  `donation` decimal(10,2) DEFAULT 0.00,
  `custom_data` text DEFAULT NULL,
  `added_at` timestamp NULL DEFAULT current_timestamp(),
  `payment_history` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `uncles` (
  `id` int(11) NOT NULL,
  `church_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `gender` varchar(10) DEFAULT 'male',
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('uncle','admin','developer') NOT NULL DEFAULT 'uncle',
  `image_url` varchar(500) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL,
  `deleted` tinyint(1) DEFAULT 0,
  `assigned_classes` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL COMMENT 'Used by createChurchWithAdmin — mirrors password_hash',
  `class` varchar(100) DEFAULT NULL COMMENT 'Shorthand used by createChurchWithAdmin — mirrors assigned_classes'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `uncle_attendance` (
  `id` int(11) NOT NULL,
  `uncle_id` int(11) NOT NULL,
  `church_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `status` enum('present','absent') NOT NULL DEFAULT 'present',
  `recorded_by` int(11) DEFAULT NULL COMMENT 'church_id of admin who recorded',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `uncle_class_assignments` (
  `id` int(11) NOT NULL,
  `uncle_id` int(11) NOT NULL,
  `church_id` int(11) NOT NULL,
  `class_name` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;




ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_activity_church` (`church_id`),
  ADD KEY `idx_activity_uncle` (`uncle_id`),
  ADD KEY `idx_activity_created` (`created_at`);

ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_church_active` (`church_id`,`is_active`),
  ADD KEY `idx_church_id` (`church_id`);

ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`student_id`,`attendance_date`),
  ADD KEY `idx_date` (`attendance_date`),
  ADD KEY `idx_church_date` (`church_id`,`attendance_date`),
  ADD KEY `idx_student_id` (`student_id`),
  ADD KEY `user_id` (`uncle_id`),
  ADD KEY `idx_attendance_church_date` (`church_id`,`attendance_date`),
  ADD KEY `idx_attendance_class` (`class_id`),
  ADD KEY `idx_attendance_date_class` (`attendance_date`,`class_id`),
  ADD KEY `idx_attendance_status` (`status`),
  ADD KEY `idx_att_student_date_status` (`student_id`,`attendance_date`,`status`);

ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_church` (`church_id`),
  ADD KEY `idx_uncle` (`uncle_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_entity` (`entity`,`entity_id`),
  ADD KEY `idx_created_at` (`created_at`);

ALTER TABLE `churches`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `church_classes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_church_class_code` (`church_id`,`code`),
  ADD KEY `idx_church_id` (`church_id`),
  ADD KEY `idx_church_classes_name` (`church_id`,`arabic_name`);

ALTER TABLE `church_reg_keys`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reg_key` (`reg_key`),
  ADD UNIQUE KEY `idx_church_reg_key_unique` (`reg_key`),
  ADD KEY `crk_ibfk_created_by` (`created_by`),
  ADD KEY `idx_church_id` (`church_id`);

ALTER TABLE `church_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `church_id` (`church_id`),
  ADD KEY `idx_cs_church_id` (`church_id`);

ALTER TABLE `classes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

ALTER TABLE `coupon_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `uncle_id` (`uncle_id`);

ALTER TABLE `coupon_withdrawals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `created_at` (`created_at`);

ALTER TABLE `custom_field_templates`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `developer_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_deleted_read` (`is_deleted`,`is_read`);

ALTER TABLE `exam_starts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_exam_start` (`task_id`,`student_id`),
  ADD KEY `es_ibfk_student` (`student_id`);

ALTER TABLE `guests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_guests_church` (`church_id`),
  ADD KEY `idx_guests_name` (`name`);

ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_church_read` (`church_id`,`is_read`),
  ADD KEY `idx_church_created` (`church_id`,`created_at`);

ALTER TABLE `pending_registrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_username_unique` (`username`),
  ADD KEY `idx_church_status` (`church_id`,`status`),
  ADD KEY `idx_class` (`class`),
  ADD KEY `idx_username` (`username`);

ALTER TABLE `rooms_templates`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_church_class` (`church_id`),
  ADD KEY `idx_name` (`name`),
  ADD KEY `idx_church_id` (`church_id`),
  ADD KEY `idx_students_church_class` (`church_id`,`class_id`),
  ADD KEY `idx_students_phone_search` (`phone`),
  ADD KEY `idx_students_coupons` (`coupons`),
  ADD KEY `idx_students_created` (`created_at`),
  ADD KEY `idx_students_class_id` (`class_id`),
  ADD KEY `idx_students_church_name` (`church_id`,`name`);

ALTER TABLE `student_sibling_groups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `church_id` (`church_id`);

ALTER TABLE `student_sibling_group_members`
  ADD PRIMARY KEY (`student_id`),
  ADD KEY `group_id` (`group_id`),
  ADD KEY `church_id` (`church_id`);

ALTER TABLE `student_transfer_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_str_to_pending` (`to_church_id`,`status`),
  ADD KEY `idx_str_from` (`from_church_id`,`status`);

ALTER TABLE `tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tasks_church` (`church_id`),
  ADD KEY `idx_tasks_uncle` (`uncle_id`);

ALTER TABLE `task_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tq_task` (`task_id`);

ALTER TABLE `task_submissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ts_task` (`task_id`),
  ADD KEY `idx_ts_student` (`student_id`),
  ADD KEY `idx_ts_church` (`church_id`);

ALTER TABLE `trips`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_trips_church` (`church_id`),
  ADD KEY `idx_trips_dates` (`start_date`,`end_date`),
  ADD KEY `idx_trips_status` (`status`),
  ADD KEY `trips_ibfk_2` (`created_by`),
  ADD KEY `idx_trips_collaborating_churches` (`collaborating_churches`(100));

ALTER TABLE `trip_collaboration_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `trip_id` (`trip_id`),
  ADD KEY `from_church_id` (`from_church_id`),
  ADD KEY `to_church_id` (`to_church_id`);

ALTER TABLE `trip_expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_trip_id` (`trip_id`),
  ADD KEY `idx_church_id` (`church_id`);

ALTER TABLE `trip_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_trip_payments_registration` (`registration_id`),
  ADD KEY `idx_trip_payments_received_by` (`received_by`);

ALTER TABLE `trip_registrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_trip_student` (`trip_id`,`student_id`),
  ADD KEY `idx_trip_registrations_trip` (`trip_id`),
  ADD KEY `idx_trip_registrations_student` (`student_id`),
  ADD KEY `idx_trip_registrations_payment` (`payment_status`),
  ADD KEY `trip_registrations_ibfk_3` (`registered_by`),
  ADD KEY `trip_registrations_ibfk_4` (`cancelled_by`),
  ADD KEY `idx_trip_registrations_type` (`registration_type`),
  ADD KEY `idx_trip_registrations_church_id` (`church_id`),
  ADD KEY `idx_trip_registrations_trip_type` (`trip_id`,`registration_type`),
  ADD KEY `idx_trip_reg_guest` (`guest_id`);

ALTER TABLE `trip_waitlist`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_trip_student` (`trip_id`,`student_id`),
  ADD KEY `idx_trip_pos` (`trip_id`,`position`),
  ADD KEY `idx_church` (`church_id`);

ALTER TABLE `uncles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `idx_church_user` (`church_id`,`username`),
  ADD KEY `idx_role` (`role`);

ALTER TABLE `uncle_attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uncle_date_church` (`uncle_id`,`attendance_date`,`church_id`),
  ADD KEY `church_date` (`church_id`,`attendance_date`),
  ADD KEY `uncle_id` (`uncle_id`);

ALTER TABLE `uncle_class_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_uncle_class` (`uncle_id`,`church_id`,`class_name`),
  ADD KEY `idx_church_class` (`church_id`,`class_name`),
  ADD KEY `idx_uncle` (`uncle_id`);


ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=271;

ALTER TABLE `announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6419;

ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3517;

ALTER TABLE `churches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

ALTER TABLE `church_classes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=140;

ALTER TABLE `church_reg_keys`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

ALTER TABLE `church_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

ALTER TABLE `classes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

ALTER TABLE `coupon_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=68;

ALTER TABLE `coupon_withdrawals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

ALTER TABLE `custom_field_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

ALTER TABLE `developer_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `exam_starts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

ALTER TABLE `guests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=91;

ALTER TABLE `pending_registrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=90;

ALTER TABLE `rooms_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1173;

ALTER TABLE `student_transfer_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `tasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

ALTER TABLE `task_questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=92;

ALTER TABLE `task_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

ALTER TABLE `trips`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

ALTER TABLE `trip_collaboration_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

ALTER TABLE `trip_expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

ALTER TABLE `trip_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=110;

ALTER TABLE `trip_registrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=125;

ALTER TABLE `trip_waitlist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

ALTER TABLE `uncles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=108;

ALTER TABLE `uncle_attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=62;

ALTER TABLE `uncle_class_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=105;


ALTER TABLE `announcements`
  ADD CONSTRAINT `announcements_ibfk_1` FOREIGN KEY (`church_id`) REFERENCES `churches` (`id`) ON DELETE CASCADE;

ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`church_id`) REFERENCES `churches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendance_ibfk_3` FOREIGN KEY (`uncle_id`) REFERENCES `uncles` (`id`) ON DELETE SET NULL;

ALTER TABLE `church_classes`
  ADD CONSTRAINT `church_classes_ibfk_1` FOREIGN KEY (`church_id`) REFERENCES `churches` (`id`) ON DELETE CASCADE;

ALTER TABLE `church_reg_keys`
  ADD CONSTRAINT `crk_ibfk_church_id` FOREIGN KEY (`church_id`) REFERENCES `churches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `crk_ibfk_created_by` FOREIGN KEY (`created_by`) REFERENCES `uncles` (`id`) ON DELETE SET NULL;

ALTER TABLE `church_settings`
  ADD CONSTRAINT `church_settings_ibfk_1` FOREIGN KEY (`church_id`) REFERENCES `churches` (`id`) ON DELETE CASCADE;

ALTER TABLE `coupon_logs`
  ADD CONSTRAINT `coupon_logs_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `coupon_logs_ibfk_2` FOREIGN KEY (`uncle_id`) REFERENCES `uncles` (`id`) ON DELETE SET NULL;

ALTER TABLE `exam_starts`
  ADD CONSTRAINT `es_ibfk_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

ALTER TABLE `students`
  ADD CONSTRAINT `students_ibfk_1` FOREIGN KEY (`church_id`) REFERENCES `churches` (`id`) ON DELETE CASCADE;

ALTER TABLE `trips`
  ADD CONSTRAINT `trips_ibfk_1` FOREIGN KEY (`church_id`) REFERENCES `churches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `trips_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `uncles` (`id`) ON DELETE SET NULL;

ALTER TABLE `trip_payments`
  ADD CONSTRAINT `trip_payments_ibfk_1` FOREIGN KEY (`registration_id`) REFERENCES `trip_registrations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `trip_payments_ibfk_2` FOREIGN KEY (`received_by`) REFERENCES `uncles` (`id`) ON DELETE SET NULL;

ALTER TABLE `trip_registrations`
  ADD CONSTRAINT `trip_registrations_ibfk_1` FOREIGN KEY (`trip_id`) REFERENCES `trips` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `trip_registrations_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `trip_registrations_ibfk_3` FOREIGN KEY (`registered_by`) REFERENCES `uncles` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `trip_registrations_ibfk_4` FOREIGN KEY (`cancelled_by`) REFERENCES `uncles` (`id`) ON DELETE SET NULL;

ALTER TABLE `uncles`
  ADD CONSTRAINT `uncles_ibfk_1` FOREIGN KEY (`church_id`) REFERENCES `churches` (`id`) ON DELETE CASCADE;

ALTER TABLE `uncle_attendance`
  ADD CONSTRAINT `fk_ua_church` FOREIGN KEY (`church_id`) REFERENCES `churches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ua_uncle` FOREIGN KEY (`uncle_id`) REFERENCES `uncles` (`id`) ON DELETE CASCADE;


-- --------------------------------------------------------
-- Table structure for table `phone_verifications`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `phone_verifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `church_id` int(11) DEFAULT NULL,
  `phone` varchar(50) NOT NULL,
  `request_token` varchar(64) DEFAULT NULL,
  `otp_code` varchar(10) NOT NULL,
  `owner_type` varchar(20) DEFAULT NULL,
  `owner_id` int(11) DEFAULT NULL,
  `owner_name` varchar(100) DEFAULT NULL,
  `is_sent` tinyint(1) DEFAULT 0,
  `is_verified` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_phone` (`phone`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `auth_refresh_tokens`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `auth_refresh_tokens` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `family_id` VARCHAR(64) NOT NULL,
  `user_type` VARCHAR(20) NOT NULL,
  `user_id` INT NOT NULL,
  `token_hash` VARCHAR(64) NOT NULL UNIQUE,
  `parent_token_id` BIGINT NULL DEFAULT NULL,
  `status` ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
  `revoked_at` DATETIME NULL DEFAULT NULL,
  `revocation_reason` VARCHAR(50) NULL DEFAULT NULL,
  `grace_until` DATETIME NULL DEFAULT NULL,
  `ip_address` VARCHAR(45) NULL DEFAULT NULL,
  `user_agent` VARCHAR(500) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  `absolute_expires_at` DATETIME NOT NULL,
  INDEX `idx_family_id` (`family_id`),
  INDEX `idx_user_type_id` (`user_type`, `user_id`),
  INDEX `idx_status_expires` (`status`, `expires_at`),
  INDEX `idx_absolute_expires` (`absolute_expires_at`),
  INDEX `idx_grace_until` (`grace_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


SET FOREIGN_KEY_CHECKS = 1;
