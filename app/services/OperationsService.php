<?php
declare(strict_types=1);

/**
 * Read-only aggregation for the Operations Center (dashboard, calendar, follow-up center).
 *
 * Rules shared by every method:
 *  - Everything is filtered by workspace id.
 *  - $assignedUserId is null for owners (they see the whole workspace). For consultants it is their
 *    own user id and limits results to engagements assigned to them, which is exactly what
 *    requireEngagementAccess() allows, so every row links to a page the viewer may open.
 *  - Dates are stored as workspace-local wall-clock time, so "now", "today" and "tomorrow" are
 *    computed in PHP in the workspace timezone and bound as strings. The database's own clock function is never used, because it
 *    follows the database server's timezone, which can differ from the workspace's.
 *  - Nothing here writes to the database.
 */
final class OperationsService
{
    public const FOLLOW_UP_FILTERS = ['all', 'today', 'upcoming', 'overdue', 'completed'];

    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo) {}

    /** @return array<string,int> */
    public function getDashboardSummary(int $workspaceId, ?int $assignedUserId, DateTimeZone $tz): array
    {
        $now = $this->now($tz);
        [$todayStart, $tomorrowStart] = $this->dayBounds($now);
        [$scopeSql, $scopeParams] = $this->scope($assignedUserId);
        $base = [$workspaceId, ...$scopeParams];

        $count = function (string $sql, array $params) : int {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        };

        $engagementCount = static fn (string $status): string =>
            "SELECT COUNT(*) FROM engagements e WHERE e.workspace_id = ? AND e.status = '{$status}'{$scopeSql}";
        $appointmentCount =
            "SELECT COUNT(*) FROM appointments a
             INNER JOIN engagements e ON e.id = a.engagement_id
             WHERE e.workspace_id = ? AND a.status = 'scheduled'{$scopeSql}";
        $followUpCount =
            "SELECT COUNT(*) FROM follow_ups f
             INNER JOIN engagements e ON e.id = f.engagement_id
             WHERE e.workspace_id = ? AND f.status = 'pending'{$scopeSql}";

        return [
            'new_inquiries' => $count($engagementCount('new'), $base),
            'qualified' => $count($engagementCount('qualified'), $base),
            'today_consultations' => $count(
                $appointmentCount . ' AND a.starts_at >= ? AND a.starts_at < ?',
                [...$base, $todayStart, $tomorrowStart]
            ),
            'upcoming_consultations' => $count(
                $appointmentCount . ' AND a.starts_at >= ?',
                [...$base, $now->format(self::DT)]
            ),
            'open_preparation_tasks' => $count(
                "SELECT COUNT(*) FROM preparation_tasks p
                 INNER JOIN engagements e ON e.id = p.engagement_id
                 WHERE e.workspace_id = ? AND p.status = 'pending'{$scopeSql}
                   AND EXISTS (SELECT 1 FROM appointments a
                               WHERE a.engagement_id = e.id AND a.status = 'scheduled' AND a.starts_at >= ?)",
                [...$base, $now->format(self::DT)]
            ),
            'pending_followups' => $count($followUpCount, $base),
            'due_today_followups' => $count(
                $followUpCount . ' AND f.due_at >= ? AND f.due_at < ?',
                [...$base, $todayStart, $tomorrowStart]
            ),
            'overdue_followups' => $count(
                $followUpCount . ' AND f.due_at < ?',
                [...$base, $now->format(self::DT)]
            ),
        ];
    }

    /** Scheduled consultations that start today (including ones already under way). */
    public function getTodayAppointments(int $workspaceId, ?int $assignedUserId, DateTimeZone $tz): array
    {
        [$todayStart, $tomorrowStart] = $this->dayBounds($this->now($tz));
        return $this->appointments($workspaceId, $assignedUserId, $todayStart, $tomorrowStart, ['scheduled'], null);
    }

    /** Scheduled consultations from tomorrow onwards, soonest first. */
    public function getUpcomingAppointments(int $workspaceId, ?int $assignedUserId, DateTimeZone $tz, int $limit = 6): array
    {
        [, $tomorrowStart] = $this->dayBounds($this->now($tz));
        return $this->appointments($workspaceId, $assignedUserId, $tomorrowStart, null, ['scheduled'], $limit);
    }

    /**
     * Appointments in [$fromLocal, $toLocal) for the calendar, oldest first.
     * Cancelled appointments are left out unless $includeCancelled is true.
     */
    public function getAppointmentsBetween(
        int $workspaceId,
        ?int $assignedUserId,
        string $fromLocal,
        string $toLocal,
        bool $includeCancelled = false
    ): array {
        $statuses = ['scheduled', 'completed', 'no_show'];
        if ($includeCancelled) {
            $statuses[] = 'cancelled';
        }
        return $this->appointments($workspaceId, $assignedUserId, $fromLocal, $toLocal, $statuses, null);
    }

    /**
     * Upcoming consultations whose preparation checklist is not finished (or was never opened).
     * "Not started" means no checklist exists yet; it is created the first time the engagement page
     * is viewed.
     */
    public function getPendingPreparation(int $workspaceId, ?int $assignedUserId, DateTimeZone $tz, int $limit = 6): array
    {
        [$scopeSql, $scopeParams] = $this->scope($assignedUserId);
        $stmt = $this->pdo->prepare(
            "SELECT e.id AS engagement_id, e.title, c.first_name, c.last_name,
                    a.id AS appointment_id, a.starts_at,
                    COALESCE(p.total, 0) AS tasks_total, COALESCE(p.done, 0) AS tasks_done
             FROM appointments a
             INNER JOIN engagements e ON e.id = a.engagement_id
             INNER JOIN clients c ON c.id = e.client_id
             LEFT JOIN (
                 SELECT engagement_id, COUNT(*) AS total, SUM(status = 'completed') AS done
                 FROM preparation_tasks GROUP BY engagement_id
             ) p ON p.engagement_id = e.id
             WHERE e.workspace_id = ? AND a.status = 'scheduled' AND a.starts_at >= ?{$scopeSql}
               AND (p.engagement_id IS NULL OR p.done < p.total)
             ORDER BY a.starts_at ASC, a.id ASC
             LIMIT " . max(1, $limit)
        );
        $stmt->execute([$workspaceId, $this->now($tz)->format(self::DT), ...$scopeParams]);
        return $stmt->fetchAll();
    }

    /** Engagements still waiting to be reviewed, oldest first. */
    public function getNewInquiries(int $workspaceId, ?int $assignedUserId, int $limit = 6): array
    {
        [$scopeSql, $scopeParams] = $this->scope($assignedUserId);
        $stmt = $this->pdo->prepare(
            "SELECT e.id AS engagement_id, e.title, e.created_at, c.first_name, c.last_name
             FROM engagements e
             INNER JOIN clients c ON c.id = e.client_id
             WHERE e.workspace_id = ? AND e.status = 'new'{$scopeSql}
             ORDER BY e.created_at ASC, e.id ASC
             LIMIT " . max(1, $limit)
        );
        $stmt->execute([$workspaceId, ...$scopeParams]);
        return $stmt->fetchAll();
    }

    public function getOverdueFollowUps(int $workspaceId, ?int $assignedUserId, DateTimeZone $tz, int $limit = 6): array
    {
        return $this->getFollowUps($workspaceId, $assignedUserId, $tz, 'overdue', null, $limit);
    }

    /**
     * Follow-ups for the Follow-up Center.
     *
     * Filters: all (every status), today (pending, due today), upcoming (pending, due after today),
     * overdue (pending, due time already passed), completed. Pending items are listed first, soonest due first.
     * $filterUserId narrows to one assignee (owners only; consultants are already scoped).
     */
    public function getFollowUps(
        int $workspaceId,
        ?int $assignedUserId,
        DateTimeZone $tz,
        string $filter = 'all',
        ?int $filterUserId = null,
        ?int $limit = null
    ): array {
        if (!in_array($filter, self::FOLLOW_UP_FILTERS, true)) {
            $filter = 'all';
        }
        $now = $this->now($tz);
        [$todayStart, $tomorrowStart] = $this->dayBounds($now);
        [$scopeSql, $scopeParams] = $this->scope($assignedUserId);

        $sql =
            "SELECT f.id, f.engagement_id, f.type, f.title, f.due_at, f.status, f.completed_at,
                    e.title AS engagement_title, c.first_name, c.last_name,
                    u.first_name AS assigned_first, u.last_name AS assigned_last
             FROM follow_ups f
             INNER JOIN engagements e ON e.id = f.engagement_id
             INNER JOIN clients c ON c.id = e.client_id
             LEFT JOIN users u ON u.id = f.assigned_user_id
             WHERE e.workspace_id = ?{$scopeSql}";
        $params = [$workspaceId, ...$scopeParams];

        if ($filterUserId !== null) {
            $sql .= ' AND f.assigned_user_id = ?';
            $params[] = $filterUserId;
        }
        switch ($filter) {
            case 'today':
                $sql .= " AND f.status = 'pending' AND f.due_at >= ? AND f.due_at < ?";
                array_push($params, $todayStart, $tomorrowStart);
                break;
            case 'upcoming':
                $sql .= " AND f.status = 'pending' AND f.due_at >= ?";
                $params[] = $tomorrowStart;
                break;
            case 'overdue':
                $sql .= " AND f.status = 'pending' AND f.due_at < ?";
                $params[] = $now->format(self::DT);
                break;
            case 'completed':
                $sql .= " AND f.status = 'completed'";
                break;
        }
        $sql .= $filter === 'completed'
            ? ' ORDER BY f.completed_at DESC, f.id DESC'
            : " ORDER BY FIELD(f.status, 'pending', 'completed', 'cancelled'), f.due_at ASC, f.id ASC";
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, $limit);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $nowString = $now->format(self::DT);
        foreach ($rows as &$row) {
            $row['is_overdue'] = $row['status'] === 'pending' && (string)$row['due_at'] < $nowString;
        }
        unset($row);
        return $rows;
    }

    /** @return array{all:int,today:int,upcoming:int,overdue:int,completed:int} */
    public function getFollowUpCounts(int $workspaceId, ?int $assignedUserId, DateTimeZone $tz, ?int $filterUserId = null): array
    {
        $now = $this->now($tz);
        [$todayStart, $tomorrowStart] = $this->dayBounds($now);
        [$scopeSql, $scopeParams] = $this->scope($assignedUserId);
        $userSql = $filterUserId !== null ? ' AND f.assigned_user_id = ?' : '';
        $userParams = $filterUserId !== null ? [$filterUserId] : [];

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS total_all,
                    COALESCE(SUM(f.status = 'pending' AND f.due_at >= ? AND f.due_at < ?), 0) AS total_today,
                    COALESCE(SUM(f.status = 'pending' AND f.due_at >= ?), 0) AS total_upcoming,
                    COALESCE(SUM(f.status = 'pending' AND f.due_at < ?), 0) AS total_overdue,
                    COALESCE(SUM(f.status = 'completed'), 0) AS total_completed
             FROM follow_ups f
             INNER JOIN engagements e ON e.id = f.engagement_id
             WHERE e.workspace_id = ?{$scopeSql}{$userSql}"
        );
        $stmt->execute([
            $todayStart, $tomorrowStart, $tomorrowStart, $now->format(self::DT),
            $workspaceId, ...$scopeParams, ...$userParams,
        ]);
        $row = $stmt->fetch();
        return [
            'all' => (int)$row['total_all'],
            'today' => (int)$row['total_today'],
            'upcoming' => (int)$row['total_upcoming'],
            'overdue' => (int)$row['total_overdue'],
            'completed' => (int)$row['total_completed'],
        ];
    }

    public function getRecentEngagements(int $workspaceId, ?int $assignedUserId, int $limit = 8): array
    {
        [$scopeSql, $scopeParams] = $this->scope($assignedUserId);
        $stmt = $this->pdo->prepare(
            "SELECT e.id, e.title, e.status, e.next_action, e.updated_at,
                    c.first_name, c.last_name,
                    a.starts_at,
                    u.first_name AS assigned_first, u.last_name AS assigned_last
             FROM engagements e
             INNER JOIN clients c ON c.id = e.client_id
             LEFT JOIN appointments a ON a.id = (
                 SELECT a2.id FROM appointments a2
                 WHERE a2.engagement_id = e.id AND a2.status = 'scheduled'
                 ORDER BY a2.starts_at ASC, a2.id ASC LIMIT 1
             )
             LEFT JOIN users u ON u.id = e.assigned_user_id
             WHERE e.workspace_id = ?{$scopeSql}
             ORDER BY e.updated_at DESC, e.id DESC
             LIMIT " . max(1, $limit)
        );
        $stmt->execute([$workspaceId, ...$scopeParams]);
        return $stmt->fetchAll();
    }

    /**
     * Calendar window helpers: the Monday-based week containing $date, or the single day.
     *
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable} [first day 00:00, day after the last day 00:00]
     */
    public static function viewRange(string $view, DateTimeImmutable $date): array
    {
        $day = $date->setTime(0, 0, 0);
        if ($view === 'day') {
            return [$day, $day->modify('+1 day')];
        }
        $offset = (int)$day->format('N') - 1; // Monday = 0
        $start = $day->modify('-' . $offset . ' days');
        return [$start, $start->modify('+7 days')];
    }

    // ------------------------------------------------------------------------------------------

    private function appointments(
        int $workspaceId,
        ?int $assignedUserId,
        string $fromLocal,
        ?string $toLocal,
        array $statuses,
        ?int $limit
    ): array {
        [$scopeSql, $scopeParams] = $this->scope($assignedUserId);
        $statusMarks = implode(',', array_fill(0, count($statuses), '?'));
        $sql =
            "SELECT a.id AS appointment_id, a.engagement_id, a.status, a.starts_at, a.ends_at,
                    e.title AS engagement_title, c.first_name AS client_first, c.last_name AS client_last,
                    s.name AS service_name,
                    u.first_name AS consultant_first, u.last_name AS consultant_last
             FROM appointments a
             INNER JOIN engagements e ON e.id = a.engagement_id
             INNER JOIN clients c ON c.id = e.client_id
             LEFT JOIN services s ON s.id = e.service_id
             LEFT JOIN users u ON u.id = a.assigned_user_id
             WHERE e.workspace_id = ? AND a.status IN ({$statusMarks}) AND a.starts_at >= ?{$scopeSql}";
        $params = [$workspaceId, ...$statuses, $fromLocal, ...$scopeParams];
        if ($toLocal !== null) {
            $sql .= ' AND a.starts_at < ?';
            $params[] = $toLocal;
        }
        $sql .= ' ORDER BY a.starts_at ASC, a.id ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, $limit);
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array{0:string,1:list<int>} SQL fragment (leading " AND ...") and its params. */
    private function scope(?int $assignedUserId): array
    {
        return $assignedUserId === null ? ['', []] : [' AND e.assigned_user_id = ?', [$assignedUserId]];
    }

    private function now(DateTimeZone $tz): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $tz);
    }

    /** @return array{0:string,1:string} Start of today and start of tomorrow, workspace-local. */
    private function dayBounds(DateTimeImmutable $now): array
    {
        $start = $now->setTime(0, 0, 0);
        return [$start->format(self::DT), $start->modify('+1 day')->format(self::DT)];
    }
}
