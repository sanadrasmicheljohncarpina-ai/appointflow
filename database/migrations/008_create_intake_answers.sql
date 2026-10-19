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
