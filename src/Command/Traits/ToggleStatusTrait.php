<?php

namespace KVS\CLI\Command\Traits;

/**
 * Trait for toggling entity status (enable/disable)
 *
 * Eliminates code duplication between TagCommand and CategoryCommand
 * which both have identical toggleStatus() logic.
 *
 * @package KVS\CLI\Command\Traits
 */
trait ToggleStatusTrait
{
    /**
     * Toggle entity status (enable/disable)
     *
     * Generic implementation that works for any entity with status_id column.
     *
     * @param string $entityName    Human-readable entity name (e.g. "Tag", "Category")
     * @param string $tableName     Database table name (e.g. "tags")
     * @param string $idColumn      Primary key column name (e.g. "tag_id")
     * @param string $nameColumn    Display name column (e.g. "tag", "title")
     * @param string|null $id       Entity ID to toggle
     * @param int $status           Target status (0 = disable, 1 = enable)
     * @param string $commandName   Command name for usage message (e.g. "content:tag")
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    protected function toggleEntityStatus(
        string $entityName,
        string $tableName,
        string $idColumn,
        string $nameColumn,
        ?string $id,
        int $status,
        string $commandName
    ): int {
        // Validate ID parameter
        if ($id === null || $id === "") {
            $action = $status !== 0 ? 'enable' : 'disable';
            $this->io()->error("{$entityName} ID is required");
            $this->io()->text("Usage: kvs {$commandName} {$action} <{$idColumn}>");
            return self::FAILURE;
        }

        // Get database connection
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            // Check if entity exists
            $sql = "SELECT {$nameColumn}, status_id FROM {$tableName} WHERE {$idColumn} = :id";
            $stmt = $db->prepare($sql);
            $stmt->execute(['id' => $id]);
            $entity = $stmt->fetch();

            if ($entity === false) {
                $this->io()->error("{$entityName} not found: {$id}");
                return self::FAILURE;
            }

            // Check if already at target status
            if ($entity['status_id'] === $status) {
                $currentStatus = $status !== 0 ? 'active' : 'inactive';
                $this->io()->info("{$entityName} is already {$currentStatus}");
                return self::SUCCESS;
            }

            // Update status
            $sql = "UPDATE {$tableName} SET status_id = :status WHERE {$idColumn} = :id";
            $stmt = $db->prepare($sql);
            $stmt->execute(['status' => $status, 'id' => $id]);

            // Success message
            $newStatus = $status !== 0 ? 'enabled' : 'disabled';
            $entityDisplayName = $entity[$nameColumn];
            $this->io()->success("{$entityName} '{$entityDisplayName}' {$newStatus} successfully!");
        } catch (\Exception $e) {
            $this->io()->error("Failed to update {$entityName} status: " . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
