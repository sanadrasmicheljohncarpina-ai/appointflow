<?php
declare(strict_types=1);

final class ServiceCatalogService
{
    public function __construct(private PDO $pdo) {}

    public function listServices(int $workspaceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, description, duration_minutes,
                    buffer_before_minutes, buffer_after_minutes, active,
                    created_at, updated_at
             FROM services
             WHERE workspace_id = ?
             ORDER BY active DESC, name ASC, id ASC'
        );
        $stmt->execute([$workspaceId]);
        return $stmt->fetchAll();
    }

    public function getService(int $workspaceId, int $serviceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, description, duration_minutes,
                    buffer_before_minutes, buffer_after_minutes, active
             FROM services
             WHERE id = ?
               AND workspace_id = ?
             LIMIT 1'
        );
        $stmt->execute([$serviceId, $workspaceId]);
        $service = $stmt->fetch();

        if (!$service) {
            throw new RuntimeException('Service not found.');
        }

        return $service;
    }

    public function createService(
        int $workspaceId,
        string $name,
        ?string $description,
        int $durationMinutes,
        int $bufferBeforeMinutes,
        int $bufferAfterMinutes
    ): int {
        $this->assertNameAvailable($workspaceId, $name);
        $this->assertDurationValues($durationMinutes, $bufferBeforeMinutes, $bufferAfterMinutes);

        $stmt = $this->pdo->prepare(
            'INSERT INTO services
                (workspace_id, name, description, duration_minutes,
                 buffer_before_minutes, buffer_after_minutes, active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $workspaceId,
            $name,
            $description,
            $durationMinutes,
            $bufferBeforeMinutes,
            $bufferAfterMinutes,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function updateService(
        int $workspaceId,
        int $serviceId,
        string $name,
        ?string $description,
        int $durationMinutes,
        int $bufferBeforeMinutes,
        int $bufferAfterMinutes
    ): void {
        $service = $this->getService($workspaceId, $serviceId);
        $this->assertDurationValues($durationMinutes, $bufferBeforeMinutes, $bufferAfterMinutes);

        if (strcasecmp((string)$service['name'], $name) !== 0) {
            $this->assertNameAvailable($workspaceId, $name, $serviceId);
        }

        $stmt = $this->pdo->prepare(
            'UPDATE services
             SET name = ?,
                 description = ?,
                 duration_minutes = ?,
                 buffer_before_minutes = ?,
                 buffer_after_minutes = ?
             WHERE id = ? AND workspace_id = ?'
        );
        $stmt->execute([
            $name,
            $description,
            $durationMinutes,
            $bufferBeforeMinutes,
            $bufferAfterMinutes,
            $serviceId,
            $workspaceId,
        ]);
    }

    public function setStatus(int $workspaceId, int $serviceId, bool $active): void
    {
        $this->getService($workspaceId, $serviceId);

        $stmt = $this->pdo->prepare(
            'UPDATE services
             SET active = ?
             WHERE id = ? AND workspace_id = ?'
        );
        $stmt->execute([$active ? 1 : 0, $serviceId, $workspaceId]);
    }

    private function assertNameAvailable(
        int $workspaceId,
        string $name,
        ?int $ignoreServiceId = null
    ): void {
        $sql = 'SELECT id
                FROM services
                WHERE workspace_id = ?
                  AND LOWER(name) = LOWER(?)';
        $params = [$workspaceId, $name];

        if ($ignoreServiceId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $ignoreServiceId;
        }

        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->fetchColumn() !== false) {
            throw new InvalidArgumentException('A service with that name already exists in this workspace.');
        }
    }

    private function assertDurationValues(
        int $durationMinutes,
        int $bufferBeforeMinutes,
        int $bufferAfterMinutes
    ): void {
        if ($durationMinutes < 1) {
            throw new InvalidArgumentException('Duration must be at least 1 minute.');
        }

        if ($durationMinutes > 1440) {
            throw new InvalidArgumentException('Duration cannot exceed 24 hours.');
        }

        if ($bufferBeforeMinutes < 0 || $bufferAfterMinutes < 0) {
            throw new InvalidArgumentException('Buffers cannot be negative.');
        }

        if ($bufferBeforeMinutes > 1440 || $bufferAfterMinutes > 1440) {
            throw new InvalidArgumentException('Buffers cannot exceed 24 hours.');
        }
    }
}
