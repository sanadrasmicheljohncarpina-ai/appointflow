<?php
declare(strict_types=1);

final class TeamService
{
    public function __construct(private PDO $pdo) {}

    public function listMembers(int $workspaceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, first_name, last_name, email, role, status, last_login_at
             FROM users
             WHERE workspace_id = ?
             ORDER BY FIELD(role, \'owner\', \'consultant\'), last_name, first_name, email'
        );
        $stmt->execute([$workspaceId]);
        return $stmt->fetchAll();
    }

    public function getConsultant(int $workspaceId, int $consultantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, first_name, last_name, email, role, status, last_login_at
             FROM users
             WHERE id = ?
               AND workspace_id = ?
               AND role = \'consultant\'
             LIMIT 1'
        );
        $stmt->execute([$consultantId, $workspaceId]);
        $consultant = $stmt->fetch();

        if (!$consultant) {
            throw new RuntimeException('Consultant not found.');
        }

        return $consultant;
    }

    public function createConsultant(
        int $workspaceId,
        string $firstName,
        string $lastName,
        string $email,
        string $password
    ): int {
        $this->assertEmailAvailable($workspaceId, $email);

        $stmt = $this->pdo->prepare(
            'INSERT INTO users
                (workspace_id, first_name, last_name, email, password_hash, role, status)
             VALUES (?, ?, ?, ?, ?, \'consultant\', \'active\')'
        );
        $stmt->execute([
            $workspaceId,
            $firstName,
            $lastName,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function updateConsultant(
        int $workspaceId,
        int $consultantId,
        string $firstName,
        string $lastName,
        string $email,
        ?string $password = null
    ): void {
        $consultant = $this->getConsultant($workspaceId, $consultantId);

        if (strcasecmp((string)$consultant['email'], $email) !== 0) {
            $this->assertEmailAvailable($workspaceId, $email, $consultantId);
        }

        if ($password !== null && $password !== '') {
            $stmt = $this->pdo->prepare(
                'UPDATE users
                 SET first_name = ?, last_name = ?, email = ?, password_hash = ?
                 WHERE id = ? AND workspace_id = ? AND role = \'consultant\''
            );
            $stmt->execute([
                $firstName,
                $lastName,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $consultantId,
                $workspaceId,
            ]);
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE users
             SET first_name = ?, last_name = ?, email = ?
             WHERE id = ? AND workspace_id = ? AND role = \'consultant\''
        );
        $stmt->execute([
            $firstName,
            $lastName,
            $email,
            $consultantId,
            $workspaceId,
        ]);
    }

    public function setStatus(int $workspaceId, int $consultantId, string $status): void
    {
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new InvalidArgumentException('Invalid consultant status.');
        }

        $this->getConsultant($workspaceId, $consultantId);

        $stmt = $this->pdo->prepare(
            'UPDATE users
             SET status = ?
             WHERE id = ? AND workspace_id = ? AND role = \'consultant\''
        );
        $stmt->execute([$status, $consultantId, $workspaceId]);
    }

    private function assertEmailAvailable(
        int $workspaceId,
        string $email,
        ?int $ignoreUserId = null
    ): void {
        $sql = 'SELECT id
                FROM users
                WHERE workspace_id = ?
                  AND email = ?';
        $params = [$workspaceId, $email];

        if ($ignoreUserId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $ignoreUserId;
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->fetchColumn() !== false) {
            throw new InvalidArgumentException(
                'That email is already in use in this workspace.'
            );
        }
    }
}
