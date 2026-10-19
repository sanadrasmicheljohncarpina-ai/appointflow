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
