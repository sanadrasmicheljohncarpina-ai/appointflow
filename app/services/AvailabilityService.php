<?php
declare(strict_types=1);

final class AvailabilityService
{
    public function __construct(private PDO $pdo) {}

    public function listRules(int $workspaceId, ?int $userId = null): array
    {
        $sql =
            'SELECT id, user_id, weekday, start_time, end_time, active
             FROM availability_rules
             WHERE workspace_id = ?';
        $params = [$workspaceId];

        if ($userId === null) {
            $sql .= ' AND user_id IS NULL';
        } else {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }

        $sql .= ' ORDER BY weekday, start_time, id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function listAllRules(int $workspaceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ar.id, ar.user_id, ar.weekday, ar.start_time, ar.end_time, ar.active,
                    u.first_name, u.last_name, u.status AS user_status
             FROM availability_rules ar
             LEFT JOIN users u
               ON u.id = ar.user_id
              AND u.workspace_id = ar.workspace_id
             WHERE ar.workspace_id = ?
             ORDER BY CASE WHEN ar.user_id IS NULL THEN 0 ELSE 1 END,
                      COALESCE(u.last_name, \'\'), COALESCE(u.first_name, \'\'),
                      ar.weekday, ar.start_time, ar.id'
        );
        $stmt->execute([$workspaceId]);
        return $stmt->fetchAll();
    }

    public function addRule(
        int $workspaceId,
        ?int $userId,
        int $weekday,
        string $startTime,
        string $endTime
    ): int {
        $this->assertUserBelongsToWorkspace($workspaceId, $userId);
        $this->assertRuleTimes($weekday, $startTime, $endTime);

        if ($this->ruleExistsForOverlap($workspaceId, $userId, $weekday, $startTime, $endTime)) {
            throw new InvalidArgumentException('That availability window overlaps an existing rule.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO availability_rules
                (workspace_id, user_id, weekday, start_time, end_time, active)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([$workspaceId, $userId, $weekday, $startTime, $endTime]);

        return (int)$this->pdo->lastInsertId();
    }

    public function setRuleStatus(int $workspaceId, int $ruleId, bool $active): void
    {
        $this->getRule($workspaceId, $ruleId);

        $stmt = $this->pdo->prepare(
            'UPDATE availability_rules
             SET active = ?
             WHERE id = ? AND workspace_id = ?'
        );
        $stmt->execute([$active ? 1 : 0, $ruleId, $workspaceId]);
    }

    public function deleteRule(int $workspaceId, int $ruleId): void
    {
        $this->getRule($workspaceId, $ruleId);

        $stmt = $this->pdo->prepare(
            'DELETE FROM availability_rules
             WHERE id = ? AND workspace_id = ?'
        );
        $stmt->execute([$ruleId, $workspaceId]);
    }

    public function listBlockedTimes(int $workspaceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT bt.id, bt.user_id, bt.starts_at, bt.ends_at, bt.reason,
                    u.first_name, u.last_name, u.status AS user_status
             FROM blocked_times bt
             LEFT JOIN users u
               ON u.id = bt.user_id
              AND u.workspace_id = bt.workspace_id
             WHERE bt.workspace_id = ?
             ORDER BY bt.starts_at ASC, bt.id ASC'
        );
        $stmt->execute([$workspaceId]);
        return $stmt->fetchAll();
    }

    public function addBlockedTime(
        int $workspaceId,
        ?int $userId,
        string $startsAt,
        string $endsAt,
        ?string $reason
    ): int {
        $this->assertUserBelongsToWorkspace($workspaceId, $userId);

        if ($this->compareDateTimes($startsAt, $endsAt) >= 0) {
            throw new InvalidArgumentException('Blocked time must end after it starts.');
        }

        if ($this->blockedTimeOverlaps($workspaceId, $userId, $startsAt, $endsAt)) {
            throw new InvalidArgumentException('That blocked period overlaps an existing blocked period for the same scope.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO blocked_times
                (workspace_id, user_id, starts_at, ends_at, reason)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$workspaceId, $userId, $startsAt, $endsAt, $reason]);

        return (int)$this->pdo->lastInsertId();
    }

    public function deleteBlockedTime(int $workspaceId, int $blockedTimeId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM blocked_times
             WHERE id = ? AND workspace_id = ?'
        );
        $stmt->execute([$blockedTimeId, $workspaceId]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Blocked period not found.');
        }
    }

    private function getRule(int $workspaceId, int $ruleId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, weekday, start_time, end_time, active
             FROM availability_rules
             WHERE id = ? AND workspace_id = ?
             LIMIT 1'
        );
        $stmt->execute([$ruleId, $workspaceId]);
        $rule = $stmt->fetch();
        if (!$rule) {
            throw new RuntimeException('Availability rule not found.');
        }
        return $rule;
    }

    private function assertUserBelongsToWorkspace(int $workspaceId, ?int $userId): void
    {
        if ($userId === null) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id
             FROM users
             WHERE id = ? AND workspace_id = ? AND role = \'consultant\'
             LIMIT 1'
        );
        $stmt->execute([$userId, $workspaceId]);
        if ($stmt->fetchColumn() === false) {
            throw new InvalidArgumentException('Selected consultant does not belong to this workspace.');
        }
    }

    private function assertRuleTimes(int $weekday, string $startTime, string $endTime): void
    {
        if ($weekday < 0 || $weekday > 6) {
            throw new InvalidArgumentException('Please select a valid weekday.');
        }

        $start = DateTimeImmutable::createFromFormat('!H:i', $startTime);
        $end = DateTimeImmutable::createFromFormat('!H:i', $endTime);
        if (!$start || !$end || $start->format('H:i') !== $startTime || $end->format('H:i') !== $endTime) {
            throw new InvalidArgumentException('Please enter valid availability times.');
        }

        if ($start >= $end) {
            throw new InvalidArgumentException('Availability must end after it starts.');
        }
    }

    private function ruleExistsForOverlap(
        int $workspaceId,
        ?int $userId,
        int $weekday,
        string $startTime,
        string $endTime
    ): bool {
        $sql =
            'SELECT COUNT(*)
             FROM availability_rules
             WHERE workspace_id = ?
               AND weekday = ?
               AND active = 1
               AND start_time < ?
               AND end_time > ?';
        $params = [$workspaceId, $weekday, $endTime, $startTime];

        if ($userId === null) {
            $sql .= ' AND user_id IS NULL';
        } else {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function blockedTimeOverlaps(
        int $workspaceId,
        ?int $userId,
        string $startsAt,
        string $endsAt
    ): bool {
        $sql =
            'SELECT COUNT(*)
             FROM blocked_times
             WHERE workspace_id = ?
               AND starts_at < ?
               AND ends_at > ?';
        $params = [$workspaceId, $endsAt, $startsAt];

        if ($userId === null) {
            $sql .= ' AND user_id IS NULL';
        } else {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function compareDateTimes(string $left, string $right): int
    {
        $leftDate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $left);
        $rightDate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $right);

        if (!$leftDate || !$rightDate) {
            throw new InvalidArgumentException('Please enter valid blocked dates and times.');
        }

        return $leftDate <=> $rightDate;
    }
}
