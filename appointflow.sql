-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 19, 2026 at 03:56 PM
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
-- Database: `appointflow`
--

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `engagement_id` bigint(20) UNSIGNED NOT NULL,
  `assigned_user_id` bigint(20) UNSIGNED NOT NULL,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `status` enum('scheduled','cancelled','completed','no_show') NOT NULL DEFAULT 'scheduled',
  `client_token_hash` varchar(255) NOT NULL,
  `client_token_expires_at` datetime DEFAULT NULL,
  `confirmation_sent_at` datetime DEFAULT NULL,
  `reminder_sent_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`id`, `engagement_id`, `assigned_user_id`, `starts_at`, `ends_at`, `status`, `client_token_hash`, `client_token_expires_at`, `confirmation_sent_at`, `reminder_sent_at`, `cancelled_at`, `cancellation_reason`, `completed_at`, `created_at`, `updated_at`) VALUES
(1, 1, 2, '2026-10-10 13:00:00', '2026-10-10 13:30:00', 'scheduled', '06188ccb6b3b899cee8611a9d5dbb5c3cc83a1688f36f171157cf80f64ae2230', NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-02 10:27:59', '2026-10-02 13:54:26'),
(2, 2, 3, '2026-10-11 09:00:00', '2026-10-11 09:30:00', 'scheduled', '5dcd73fc0f37fb88b98a32678339ee9721bd27772d6ce48224bf4611822e0b7e', NULL, '2026-10-02 13:37:42', NULL, NULL, NULL, NULL, '2026-10-02 13:37:42', '2026-10-02 13:51:35');

-- --------------------------------------------------------

--
-- Table structure for table `availability_rules`
--

CREATE TABLE `availability_rules` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `workspace_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `weekday` tinyint(3) UNSIGNED NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1
) ;

--
-- Dumping data for table `availability_rules`
--

INSERT INTO `availability_rules` (`id`, `workspace_id`, `user_id`, `weekday`, `start_time`, `end_time`, `active`) VALUES
(1, 1, NULL, 1, '09:00:00', '17:00:00', 1),
(2, 1, NULL, 2, '09:00:00', '17:00:00', 1),
(3, 1, NULL, 0, '09:00:00', '17:00:00', 1),
(4, 1, NULL, 4, '09:00:00', '17:00:00', 1),
(5, 1, NULL, 3, '09:00:00', '17:00:00', 1),
(6, 1, NULL, 5, '09:00:00', '17:00:00', 1),
(7, 1, NULL, 6, '09:00:00', '17:00:00', 1);

-- --------------------------------------------------------

--
-- Table structure for table `blocked_times`
--

CREATE TABLE `blocked_times` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `workspace_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `blocked_times`
--

INSERT INTO `blocked_times` (`id`, `workspace_id`, `user_id`, `starts_at`, `ends_at`, `reason`, `created_at`) VALUES
(2, 1, NULL, '2026-10-02 09:01:00', '2026-10-02 17:02:00', NULL, '2026-10-01 22:32:20');

-- --------------------------------------------------------

--
-- Table structure for table `clients`
--

CREATE TABLE `clients` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `workspace_id` bigint(20) UNSIGNED NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `company_name` varchar(150) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `clients`
--

INSERT INTO `clients` (`id`, `workspace_id`, `first_name`, `last_name`, `email`, `phone`, `company_name`, `notes`, `created_at`, `updated_at`) VALUES
(1, 1, 'Sarah', 'Johnson', 'sarah@example.com', '09171234567', 'Example Business', NULL, '2026-10-02 10:05:32', '2026-10-02 10:05:32'),
(2, 1, 'Maya', 'Santos', 'maya.santos@brightleafcafe.example', '0917 555 0142', 'Brightleaf Café', NULL, '2026-10-02 11:19:42', '2026-10-02 11:19:42');

-- --------------------------------------------------------

--
-- Table structure for table `client_link_requests`
--

CREATE TABLE `client_link_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `workspace_id` bigint(20) UNSIGNED NOT NULL,
  `engagement_id` bigint(20) UNSIGNED NOT NULL,
  `appointment_id` bigint(20) UNSIGNED NOT NULL,
  `client_id` bigint(20) UNSIGNED NOT NULL,
  `email` varchar(255) NOT NULL,
  `status` enum('pending','sent','dismissed') NOT NULL DEFAULT 'pending',
  `request_count` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by_user_id` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `engagements`
--

CREATE TABLE `engagements` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `workspace_id` bigint(20) UNSIGNED NOT NULL,
  `client_id` bigint(20) UNSIGNED NOT NULL,
  `service_id` bigint(20) UNSIGNED DEFAULT NULL,
  `assigned_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `source` varchar(100) DEFAULT NULL,
  `problem_summary` text NOT NULL,
  `qualification_notes` text DEFAULT NULL,
  `status` enum('new','qualified','scheduled','completed','follow_up','converted','closed_lost') NOT NULL DEFAULT 'new',
  `priority` enum('normal','high') NOT NULL DEFAULT 'normal',
  `next_action` text DEFAULT NULL,
  `next_action_due_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `engagements`
--

INSERT INTO `engagements` (`id`, `workspace_id`, `client_id`, `service_id`, `assigned_user_id`, `title`, `source`, `problem_summary`, `qualification_notes`, `status`, `priority`, `next_action`, `next_action_due_at`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, 2, 'Discovery Consultation inquiry', 'website', 'We currently manage customer appointments through Facebook Messenger and Excel. We want a more organized system and need help improving the process.\n\nAdditional information:\nThree employees handle bookings, and we sometimes have double bookings and customers asking to move their appointments.', NULL, 'scheduled', 'normal', NULL, NULL, '2026-10-02 10:05:32', '2026-10-02 10:27:59'),
(2, 1, 2, 1, 3, 'Discovery Consultation inquiry', 'website', 'We run a small two-location café and our website is outdated. Customers can\'t order ahead or see our current menu, and we get almost no traffic from Google. We\'d like help figuring out whether to rebuild the site or improve what we have, and how to set up online ordering without hurting our in-store workflow.\n\nAdditional information:\nWe\'re hoping to launch something before the holiday season, so our timeline is about 8 to 10 weeks. Budget is flexible, roughly ₱150,000 to ₱250,000, depending on what you recommend. Mornings (before 11 AM) work best for a call, and we can invite our operations manager to join.', NULL, 'scheduled', 'normal', NULL, NULL, '2026-10-02 11:19:42', '2026-10-02 13:37:42');

-- --------------------------------------------------------

--
-- Table structure for table `engagement_events`
--

CREATE TABLE `engagement_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `engagement_id` bigint(20) UNSIGNED NOT NULL,
  `actor_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `event_type` varchar(80) NOT NULL,
  `description` text NOT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `engagement_events`
--

INSERT INTO `engagement_events` (`id`, `engagement_id`, `actor_user_id`, `event_type`, `description`, `metadata_json`, `created_at`) VALUES
(1, 1, NULL, 'engagement.created', 'Engagement created.', NULL, '2026-10-02 10:05:32'),
(2, 1, NULL, 'inquiry.submitted', 'Public inquiry submitted.', NULL, '2026-10-02 10:05:32'),
(3, 1, 1, 'engagement.qualified', 'Engagement qualified.', '{\"assigned_user_id\":null}', '2026-10-02 10:11:37'),
(4, 1, 1, 'engagement.assigned', 'Consultant assignment cleared.', '{\"assigned_user_id\":null}', '2026-10-02 10:11:40'),
(5, 1, 1, 'engagement.assigned', 'Consultant assigned.', '{\"assigned_user_id\":2}', '2026-10-02 10:14:00'),
(6, 1, 1, 'appointment.booked', 'Consultation booked for Oct 10, 2026 1:00 PM.', '{\"appointment_id\":1,\"starts_at\":\"2026-10-10 13:00:00\",\"ends_at\":\"2026-10-10 13:30:00\",\"assigned_user_id\":2}', '2026-10-02 10:27:59'),
(7, 2, NULL, 'engagement.created', 'Engagement created.', NULL, '2026-10-02 11:19:42'),
(8, 2, NULL, 'inquiry.submitted', 'Public inquiry submitted.', NULL, '2026-10-02 11:19:42'),
(9, 2, 1, 'engagement.qualified', 'Engagement qualified. Consultant assigned.', '{\"assigned_user_id\":3}', '2026-10-02 13:37:04'),
(10, 2, 1, 'appointment.booked', 'Consultation booked for Oct 11, 2026 9:00 AM.', '{\"appointment_id\":2,\"starts_at\":\"2026-10-11 09:00:00\",\"ends_at\":\"2026-10-11 09:30:00\",\"assigned_user_id\":3}', '2026-10-02 13:37:42'),
(11, 2, 1, 'appointment.client_link_issued', 'Client management link generated.', '{\"appointment_id\":2,\"client_email\":\"maya.santos@brightleafcafe.example\"}', '2026-10-02 13:37:48'),
(12, 2, 1, 'appointment.client_link_issued', 'Client management link generated.', '{\"appointment_id\":2,\"client_email\":\"maya.santos@brightleafcafe.example\"}', '2026-10-02 13:51:35'),
(13, 1, 1, 'appointment.client_link_issued', 'Client management link generated.', '{\"appointment_id\":1,\"client_email\":\"sarah@example.com\"}', '2026-10-02 13:51:51'),
(14, 1, 1, 'appointment.client_link_issued', 'Client management link generated.', '{\"appointment_id\":1,\"client_email\":\"sarah@example.com\"}', '2026-10-02 13:54:26');

-- --------------------------------------------------------

--
-- Table structure for table `follow_ups`
--

CREATE TABLE `follow_ups` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `engagement_id` bigint(20) UNSIGNED NOT NULL,
  `assigned_user_id` bigint(20) UNSIGNED NOT NULL,
  `type` enum('email','call','proposal','document','meeting','other') NOT NULL DEFAULT 'other',
  `title` varchar(200) NOT NULL,
  `notes` text DEFAULT NULL,
  `due_at` datetime NOT NULL,
  `status` enum('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `intake_answers`
--

CREATE TABLE `intake_answers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `engagement_id` bigint(20) UNSIGNED NOT NULL,
  `question_id` bigint(20) UNSIGNED NOT NULL,
  `answer_text` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `intake_questions`
--

CREATE TABLE `intake_questions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `workspace_id` bigint(20) UNSIGNED NOT NULL,
  `service_id` bigint(20) UNSIGNED DEFAULT NULL,
  `stage` enum('inquiry','pre_meeting') NOT NULL,
  `question` text NOT NULL,
  `field_type` enum('text','textarea','select','checkbox') NOT NULL DEFAULT 'text',
  `required` tinyint(1) NOT NULL DEFAULT 0,
  `options_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`options_json`)),
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `meeting_notes`
--

CREATE TABLE `meeting_notes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `appointment_id` bigint(20) UNSIGNED NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `summary` text NOT NULL,
  `problem` text NOT NULL,
  `requirements` text NOT NULL,
  `decisions` text DEFAULT NULL,
  `concerns` text DEFAULT NULL,
  `next_step` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_log`
--

CREATE TABLE `notification_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `workspace_id` bigint(20) UNSIGNED NOT NULL,
  `engagement_id` bigint(20) UNSIGNED DEFAULT NULL,
  `appointment_id` bigint(20) UNSIGNED DEFAULT NULL,
  `recipient_email` varchar(255) NOT NULL,
  `notification_type` enum('confirmation','reminder','cancellation','reschedule') NOT NULL,
  `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `sent_at` datetime DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notification_log`
--

INSERT INTO `notification_log` (`id`, `workspace_id`, `engagement_id`, `appointment_id`, `recipient_email`, `notification_type`, `status`, `sent_at`, `error_message`, `created_at`) VALUES
(1, 1, 1, 1, 'sarah@example.com', 'confirmation', 'pending', NULL, NULL, '2026-10-02 10:27:59'),
(2, 1, 2, 2, 'maya.santos@brightleafcafe.example', 'confirmation', 'sent', '2026-10-02 13:37:42', NULL, '2026-10-02 13:37:42');

-- --------------------------------------------------------

--
-- Table structure for table `preparation_tasks`
--

CREATE TABLE `preparation_tasks` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `engagement_id` bigint(20) UNSIGNED NOT NULL,
  `assigned_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('pending','completed') NOT NULL DEFAULT 'pending',
  `due_at` datetime DEFAULT NULL,
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `workspace_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `duration_minutes` int(10) UNSIGNED NOT NULL,
  `buffer_before_minutes` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `buffer_after_minutes` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`id`, `workspace_id`, `name`, `description`, `duration_minutes`, `buffer_before_minutes`, `buffer_after_minutes`, `active`, `created_at`, `updated_at`) VALUES
(1, 1, 'Discovery Consultation', 'An initial consultation to understand your business needs and determine how we can help.', 30, 0, 0, 1, '2026-10-02 10:00:38', '2026-10-02 10:00:38');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `workspace_id` bigint(20) UNSIGNED NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('owner','consultant') NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `workspace_id`, `first_name`, `last_name`, `email`, `password_hash`, `role`, `status`, `last_login_at`, `created_at`, `updated_at`) VALUES
(1, 1, 'Maya', 'Owner', 'owner@example.com', '$2y$12$5SLeFbvT4Z44wcZXHw5bM.sYCO/fKMKR5twTN7Gj//yjxYKx2mqNe', 'owner', 'active', '2026-10-02 13:36:00', '2026-10-01 21:21:35', '2026-10-02 13:36:00'),
(2, 1, 'Daniel', 'Oliver', 'daniel@gamil.com', '$2y$10$yO7FOyC05lfOdyOUtFIhNujO5SgZCq1qmV6n0exlfY8QzY9vkDIWu', 'consultant', 'active', '2026-10-02 10:47:18', '2026-10-02 10:13:51', '2026-10-02 10:47:18'),
(3, 1, 'Millard', 'Lan', 'millard@gmail.com', '$2y$10$6KdHTGoHUJT5iKg5Q/hFHOxKukqnrSb5jwi42NtGJZ8IXxOF2dpE6', 'consultant', 'active', '2026-10-02 10:47:02', '2026-10-02 10:46:30', '2026-10-02 10:47:02');

-- --------------------------------------------------------

--
-- Table structure for table `workspaces`
--

CREATE TABLE `workspaces` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `timezone` varchar(64) NOT NULL DEFAULT 'Asia/Manila',
  `booking_slug` varchar(120) NOT NULL,
  `status` enum('active','suspended') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `workspaces`
--

INSERT INTO `workspaces` (`id`, `name`, `email`, `phone`, `timezone`, `booking_slug`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Northstar Digital Consulting', 'owner@example.com', '0989314975', 'Asia/Manila', 'northstar-digital-consulting', 'active', '2026-10-01 21:21:35', '2026-10-01 21:21:35');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_appointments_user_start` (`assigned_user_id`,`starts_at`),
  ADD KEY `idx_appointments_engagement` (`engagement_id`,`status`),
  ADD KEY `idx_appointments_schedule` (`starts_at`,`ends_at`,`status`);

--
-- Indexes for table `availability_rules`
--
ALTER TABLE `availability_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_availability_user` (`user_id`),
  ADD KEY `idx_availability_lookup` (`workspace_id`,`user_id`,`weekday`,`active`);

--
-- Indexes for table `blocked_times`
--
ALTER TABLE `blocked_times`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_blocked_user` (`user_id`),
  ADD KEY `idx_blocked_lookup` (`workspace_id`,`user_id`,`starts_at`,`ends_at`);

--
-- Indexes for table `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_clients_workspace_email` (`workspace_id`,`email`),
  ADD KEY `idx_clients_name` (`workspace_id`,`last_name`,`first_name`);

--
-- Indexes for table `client_link_requests`
--
ALTER TABLE `client_link_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_link_requests_appointment` (`appointment_id`),
  ADD KEY `fk_link_requests_client` (`client_id`),
  ADD KEY `fk_link_requests_resolver` (`resolved_by_user_id`),
  ADD KEY `idx_link_requests_engagement` (`engagement_id`,`status`),
  ADD KEY `idx_link_requests_workspace` (`workspace_id`,`status`,`last_requested_at`);

--
-- Indexes for table `engagements`
--
ALTER TABLE `engagements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_engagements_assigned_user` (`assigned_user_id`),
  ADD KEY `idx_engagements_workspace_status` (`workspace_id`,`status`),
  ADD KEY `idx_engagements_assigned_status` (`workspace_id`,`assigned_user_id`,`status`),
  ADD KEY `idx_engagements_client` (`client_id`),
  ADD KEY `idx_engagements_service` (`service_id`),
  ADD KEY `idx_engagements_next_action` (`next_action_due_at`);

--
-- Indexes for table `engagement_events`
--
ALTER TABLE `engagement_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_events_actor` (`actor_user_id`),
  ADD KEY `idx_events_engagement` (`engagement_id`,`created_at`),
  ADD KEY `idx_events_type` (`event_type`,`created_at`);

--
-- Indexes for table `follow_ups`
--
ALTER TABLE `follow_ups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_followups_user_status` (`assigned_user_id`,`status`,`due_at`),
  ADD KEY `idx_followups_engagement` (`engagement_id`);

--
-- Indexes for table `intake_answers`
--
ALTER TABLE `intake_answers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_intake_answer` (`engagement_id`,`question_id`),
  ADD KEY `idx_intake_answers_question` (`question_id`);

--
-- Indexes for table `intake_questions`
--
ALTER TABLE `intake_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_intake_questions_workspace` (`workspace_id`),
  ADD KEY `idx_intake_questions_service` (`service_id`,`stage`,`active`,`sort_order`);

--
-- Indexes for table `meeting_notes`
--
ALTER TABLE `meeting_notes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_meeting_note_appointment` (`appointment_id`),
  ADD KEY `fk_meeting_notes_user` (`created_by`);

--
-- Indexes for table `notification_log`
--
ALTER TABLE `notification_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_notification_workspace` (`workspace_id`),
  ADD KEY `fk_notification_engagement` (`engagement_id`),
  ADD KEY `idx_notifications_status` (`status`,`created_at`),
  ADD KEY `idx_notifications_appointment` (`appointment_id`,`notification_type`);

--
-- Indexes for table `preparation_tasks`
--
ALTER TABLE `preparation_tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_preparation_user` (`assigned_user_id`),
  ADD KEY `idx_preparation_engagement` (`engagement_id`,`status`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_services_workspace_active` (`workspace_id`,`active`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_workspace_email` (`workspace_id`,`email`),
  ADD KEY `idx_users_workspace_role` (`workspace_id`,`role`),
  ADD KEY `idx_users_workspace_status` (`workspace_id`,`status`);

--
-- Indexes for table `workspaces`
--
ALTER TABLE `workspaces`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `booking_slug` (`booking_slug`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT for table `availability_rules`
--
ALTER TABLE `availability_rules`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `blocked_times`
--
ALTER TABLE `blocked_times`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `clients`
--
ALTER TABLE `clients`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=52;

--
-- AUTO_INCREMENT for table `client_link_requests`
--
ALTER TABLE `client_link_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `engagements`
--
ALTER TABLE `engagements`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=52;

--
-- AUTO_INCREMENT for table `engagement_events`
--
ALTER TABLE `engagement_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `follow_ups`
--
ALTER TABLE `follow_ups`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `intake_answers`
--
ALTER TABLE `intake_answers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `intake_questions`
--
ALTER TABLE `intake_questions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `meeting_notes`
--
ALTER TABLE `meeting_notes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification_log`
--
ALTER TABLE `notification_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `preparation_tasks`
--
ALTER TABLE `preparation_tasks`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `workspaces`
--
ALTER TABLE `workspaces`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `fk_appointments_assigned_user` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_appointments_engagement` FOREIGN KEY (`engagement_id`) REFERENCES `engagements` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `availability_rules`
--
ALTER TABLE `availability_rules`
  ADD CONSTRAINT `fk_availability_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_availability_workspace` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `blocked_times`
--
ALTER TABLE `blocked_times`
  ADD CONSTRAINT `fk_blocked_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_blocked_workspace` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `clients`
--
ALTER TABLE `clients`
  ADD CONSTRAINT `fk_clients_workspace` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `client_link_requests`
--
ALTER TABLE `client_link_requests`
  ADD CONSTRAINT `fk_link_requests_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_link_requests_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_link_requests_engagement` FOREIGN KEY (`engagement_id`) REFERENCES `engagements` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_link_requests_resolver` FOREIGN KEY (`resolved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_link_requests_workspace` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `engagements`
--
ALTER TABLE `engagements`
  ADD CONSTRAINT `fk_engagements_assigned_user` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_engagements_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_engagements_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_engagements_workspace` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `engagement_events`
--
ALTER TABLE `engagement_events`
  ADD CONSTRAINT `fk_events_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_events_engagement` FOREIGN KEY (`engagement_id`) REFERENCES `engagements` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `follow_ups`
--
ALTER TABLE `follow_ups`
  ADD CONSTRAINT `fk_followups_engagement` FOREIGN KEY (`engagement_id`) REFERENCES `engagements` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_followups_user` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `intake_answers`
--
ALTER TABLE `intake_answers`
  ADD CONSTRAINT `fk_intake_answers_engagement` FOREIGN KEY (`engagement_id`) REFERENCES `engagements` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_intake_answers_question` FOREIGN KEY (`question_id`) REFERENCES `intake_questions` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `intake_questions`
--
ALTER TABLE `intake_questions`
  ADD CONSTRAINT `fk_intake_questions_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_intake_questions_workspace` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `meeting_notes`
--
ALTER TABLE `meeting_notes`
  ADD CONSTRAINT `fk_meeting_notes_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_meeting_notes_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `notification_log`
--
ALTER TABLE `notification_log`
  ADD CONSTRAINT `fk_notification_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_notification_engagement` FOREIGN KEY (`engagement_id`) REFERENCES `engagements` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_notification_workspace` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `preparation_tasks`
--
ALTER TABLE `preparation_tasks`
  ADD CONSTRAINT `fk_preparation_engagement` FOREIGN KEY (`engagement_id`) REFERENCES `engagements` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_preparation_user` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `services`
--
ALTER TABLE `services`
  ADD CONSTRAINT `fk_services_workspace` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_workspace` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
