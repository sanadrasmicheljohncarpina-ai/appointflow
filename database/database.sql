-- AppointFlow MVP v0.1 schema
CREATE DATABASE IF NOT EXISTS appointflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE appointflow;


-- 001_create_workspaces.sql

CREATE TABLE IF NOT EXISTS workspaces (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(50) NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Manila',
    booking_slug VARCHAR(120) NOT NULL UNIQUE,
    status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 002_create_users.sql

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id BIGINT UNSIGNED NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('owner', 'consultant') NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    UNIQUE KEY uq_users_workspace_email (workspace_id, email),
    KEY idx_users_workspace_role (workspace_id, role),
    KEY idx_users_workspace_status (workspace_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 003_create_clients.sql

CREATE TABLE IF NOT EXISTS clients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id BIGINT UNSIGNED NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(50) NULL,
    company_name VARCHAR(150) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_clients_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    KEY idx_clients_workspace_email (workspace_id, email),
    KEY idx_clients_name (workspace_id, last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 004_create_services.sql

CREATE TABLE IF NOT EXISTS services (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    duration_minutes INT UNSIGNED NOT NULL,
    buffer_before_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    buffer_after_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_services_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    KEY idx_services_workspace_active (workspace_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 005_create_engagements.sql

CREATE TABLE IF NOT EXISTS engagements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NULL,
    assigned_user_id BIGINT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    source VARCHAR(100) NULL,
    problem_summary TEXT NOT NULL,
    qualification_notes TEXT NULL,
    status ENUM('new','qualified','scheduled','completed','follow_up','converted','closed_lost') NOT NULL DEFAULT 'new',
    priority ENUM('normal','high') NOT NULL DEFAULT 'normal',
    next_action TEXT NULL,
    next_action_due_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_engagements_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_engagements_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_engagements_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_engagements_assigned_user FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    KEY idx_engagements_workspace_status (workspace_id, status),
    KEY idx_engagements_assigned_status (workspace_id, assigned_user_id, status),
    KEY idx_engagements_client (client_id),
    KEY idx_engagements_service (service_id),
    KEY idx_engagements_next_action (next_action_due_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 006_create_appointments.sql

CREATE TABLE IF NOT EXISTS appointments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    engagement_id BIGINT UNSIGNED NOT NULL,
    assigned_user_id BIGINT UNSIGNED NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    status ENUM('scheduled','cancelled','completed','no_show') NOT NULL DEFAULT 'scheduled',
    client_token_hash VARCHAR(255) NOT NULL,
    client_token_expires_at DATETIME NULL,
    confirmation_sent_at DATETIME NULL,
    reminder_sent_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    cancellation_reason TEXT NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_appointments_engagement FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_appointments_assigned_user FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    KEY idx_appointments_user_start (assigned_user_id, starts_at),
    KEY idx_appointments_engagement (engagement_id, status),
    KEY idx_appointments_schedule (starts_at, ends_at, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 007_create_intake_questions.sql

CREATE TABLE IF NOT EXISTS intake_questions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id BIGINT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NULL,
    stage ENUM('inquiry','pre_meeting') NOT NULL,
    question TEXT NOT NULL,
    field_type ENUM('text','textarea','select','checkbox') NOT NULL DEFAULT 'text',
    required BOOLEAN NOT NULL DEFAULT FALSE,
    options_json JSON NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_intake_questions_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_intake_questions_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL ON UPDATE CASCADE,
    KEY idx_intake_questions_service (service_id, stage, active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 008_create_intake_answers.sql

CREATE TABLE IF NOT EXISTS intake_answers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    engagement_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    answer_text TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_intake_answers_engagement FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_intake_answers_question FOREIGN KEY (question_id) REFERENCES intake_questions(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    UNIQUE KEY uq_intake_answer (engagement_id, question_id),
    KEY idx_intake_answers_question (question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 009_create_preparation_tasks.sql

CREATE TABLE IF NOT EXISTS preparation_tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    engagement_id BIGINT UNSIGNED NOT NULL,
    assigned_user_id BIGINT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    status ENUM('pending','completed') NOT NULL DEFAULT 'pending',
    due_at DATETIME NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_preparation_engagement FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_preparation_user FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    KEY idx_preparation_engagement (engagement_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 010_create_meeting_notes.sql

CREATE TABLE IF NOT EXISTS meeting_notes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    appointment_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    summary TEXT NOT NULL,
    problem TEXT NOT NULL,
    requirements TEXT NOT NULL,
    decisions TEXT NULL,
    concerns TEXT NULL,
    next_step TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_meeting_notes_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_meeting_notes_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    UNIQUE KEY uq_meeting_note_appointment (appointment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 011_create_follow_ups.sql

CREATE TABLE IF NOT EXISTS follow_ups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    engagement_id BIGINT UNSIGNED NOT NULL,
    assigned_user_id BIGINT UNSIGNED NOT NULL,
    type ENUM('email','call','proposal','document','meeting','other') NOT NULL DEFAULT 'other',
    title VARCHAR(200) NOT NULL,
    notes TEXT NULL,
    due_at DATETIME NOT NULL,
    status ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_followups_engagement FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_followups_user FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    KEY idx_followups_user_status (assigned_user_id, status, due_at),
    KEY idx_followups_engagement (engagement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 012_create_availability_rules.sql

CREATE TABLE IF NOT EXISTS availability_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    weekday TINYINT UNSIGNED NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    CONSTRAINT chk_availability_weekday CHECK (weekday BETWEEN 0 AND 6),
    CONSTRAINT fk_availability_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_availability_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    KEY idx_availability_lookup (workspace_id, user_id, weekday, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 013_create_blocked_times.sql

CREATE TABLE IF NOT EXISTS blocked_times (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_blocked_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_blocked_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    KEY idx_blocked_lookup (workspace_id, user_id, starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 014_create_engagement_events.sql

CREATE TABLE IF NOT EXISTS engagement_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    engagement_id BIGINT UNSIGNED NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(80) NOT NULL,
    description TEXT NOT NULL,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_events_engagement FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_events_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    KEY idx_events_engagement (engagement_id, created_at),
    KEY idx_events_type (event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 015_create_notification_log.sql

CREATE TABLE IF NOT EXISTS notification_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id BIGINT UNSIGNED NOT NULL,
    engagement_id BIGINT UNSIGNED NULL,
    appointment_id BIGINT UNSIGNED NULL,
    recipient_email VARCHAR(255) NOT NULL,
    notification_type ENUM('confirmation','reminder','cancellation','reschedule') NOT NULL,
    status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    sent_at DATETIME NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_notification_engagement FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_notification_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL ON UPDATE CASCADE,
    KEY idx_notifications_status (status, created_at),
    KEY idx_notifications_appointment (appointment_id, notification_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- 016_create_client_link_requests.sql

CREATE TABLE IF NOT EXISTS client_link_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id BIGINT UNSIGNED NOT NULL,
    engagement_id BIGINT UNSIGNED NOT NULL,
    appointment_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    status ENUM('pending','sent','dismissed') NOT NULL DEFAULT 'pending',
    request_count INT UNSIGNED NOT NULL DEFAULT 1,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    resolved_by_user_id BIGINT UNSIGNED NULL,
    CONSTRAINT fk_link_requests_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_link_requests_engagement FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_link_requests_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_link_requests_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_link_requests_resolver FOREIGN KEY (resolved_by_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    KEY idx_link_requests_engagement (engagement_id, status),
    KEY idx_link_requests_workspace (workspace_id, status, last_requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
