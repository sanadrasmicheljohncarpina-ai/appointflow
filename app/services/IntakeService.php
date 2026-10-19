<?php
declare(strict_types=1);

final class IntakeService
{
    public function __construct(private PDO $pdo) {}

    public function listActiveQuestions(int $workspaceId, ?int $serviceId, string $stage): array
    {
        $sql =
            'SELECT id, service_id, stage, question, field_type, required, options_json, sort_order
             FROM intake_questions
             WHERE workspace_id = ?
               AND active = 1
               AND stage = ?
               AND (service_id IS NULL OR service_id = ?)
             ORDER BY CASE WHEN service_id IS NULL THEN 0 ELSE 1 END, sort_order ASC, id ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$workspaceId, $stage, $serviceId]);
        return $stmt->fetchAll();
    }

    public function validateAndSaveAnswers(int $engagementId, array $questions, array $postedAnswers): void
    {
        if (!$questions) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare('DELETE FROM intake_answers WHERE engagement_id = ? AND question_id = ?');
            $insert = $this->pdo->prepare(
                'INSERT INTO intake_answers (engagement_id, question_id, answer_text)
                 VALUES (?, ?, ?)'
            );

            foreach ($questions as $question) {
                $questionId = (int)$question['id'];
                $raw = $postedAnswers[$questionId] ?? null;
                $answer = is_array($raw)
                    ? implode(', ', array_map(static fn(mixed $value): string => trim((string)$value), $raw))
                    : trim((string)$raw);

                if ((int)$question['required'] === 1 && $answer === '') {
                    throw new InvalidArgumentException('Please answer: ' . (string)$question['question']);
                }

                if ($answer === '') {
                    continue;
                }

                $delete->execute([$engagementId, $questionId]);
                $insert->execute([$engagementId, $questionId, $answer]);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getAnswers(int $engagementId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ia.question_id, q.question, q.field_type, ia.answer_text
             FROM intake_answers ia
             INNER JOIN intake_questions q ON q.id = ia.question_id
             WHERE ia.engagement_id = ?
             ORDER BY q.sort_order ASC, q.id ASC'
        );
        $stmt->execute([$engagementId]);
        return $stmt->fetchAll();
    }
}
