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
