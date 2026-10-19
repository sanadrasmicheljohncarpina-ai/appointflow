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
