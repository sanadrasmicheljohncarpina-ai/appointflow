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
