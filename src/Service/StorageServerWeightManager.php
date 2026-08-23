<?php

namespace KVS\CLI\Service;

class StorageServerWeightManager
{
    public const MAX_WEIGHT = 99999;
    public const MAX_TOTAL_WEIGHT = 99999;

    public function __construct(
        private \PDO $db,
        private string $serverTable,
        private string $groupTable,
        private string $databaseIdentity,
        private StorageClusterDataPublisher $clusterPublisher,
        private int $lockTimeout = 10
    ) {
        $this->assertTableIdentifier($this->serverTable);
        $this->assertTableIdentifier($this->groupTable);
    }

    /**
     * @return array{
     *     ok: true,
     *     action: string,
     *     group_id: int,
     *     revision: string,
     *     weights: list<array<string, mixed>>
     * }
     */
    public function read(int $groupId): array
    {
        $rows = $this->fetchGroupRows($groupId);

        return [
            'ok' => true,
            'action' => 'weights',
            'group_id' => $groupId,
            'revision' => $this->revision($rows),
            'weights' => $this->publicWeightRows($rows),
        ];
    }

    /**
     * @param array<int, int> $weights
     * @return array<string, mixed>
     */
    public function apply(
        int $groupId,
        array $weights,
        ?string $expectedRevision,
        bool $ignoreRevision,
        bool $dryRun
    ): array {
        return $this->withOperationLock(
            fn(): array => $this->applyLocked(
                $groupId,
                $weights,
                $expectedRevision,
                $ignoreRevision,
                $dryRun
            )
        );
    }

    /**
     * @param array<int, int> $weights
     * @return array<string, mixed>
     */
    private function applyLocked(
        int $groupId,
        array $weights,
        ?string $expectedRevision,
        bool $ignoreRevision,
        bool $dryRun
    ): array {
        $beforeRows = $this->fetchGroupRows($groupId);
        $this->validateWeightVector($groupId, $weights, $beforeRows);
        $revisionBefore = $this->revision($beforeRows);
        if (!$ignoreRevision && $expectedRevision !== null && !hash_equals($revisionBefore, $expectedRevision)) {
            throw new StorageServerWeightException(
                'revision_conflict',
                'The storage server group changed after its weights were read.'
            );
        }

        $projectedRows = $this->replaceGroupWeights($beforeRows, $weights);
        $revisionAfter = $this->revision($projectedRows);
        $changes = $this->buildChanges($beforeRows, $weights);
        $changed = $changes !== [];

        try {
            $this->clusterPublisher->assertWritable();
            $snapshot = $this->clusterPublisher->snapshot();
            $clusterFields = $this->clusterPublisher->fieldsFromSerializedRows($snapshot['bytes']);
        } catch (\Throwable $e) {
            throw new StorageServerWeightException('cluster_data_unavailable', $e->getMessage(), false, $e);
        }

        if ($dryRun) {
            try {
                $clusterRows = $this->clusterPublisher->fetchRows(
                    $this->db,
                    $this->serverTable,
                    $clusterFields
                );
                $projectedClusterRows = $this->replaceClusterWeights($clusterRows, $groupId, $weights);
                $this->clusterPublisher->validateStaging($projectedClusterRows, $snapshot['permissions']);
            } catch (\Throwable $e) {
                throw new StorageServerWeightException('dry_run_validation_failed', $e->getMessage(), false, $e);
            }

            return $this->mutationResult(
                $groupId,
                $revisionBefore,
                $revisionAfter,
                true,
                $changed,
                false,
                $changes
            );
        }

        $rowsImmediatelyBeforeWrite = $this->fetchGroupRows($groupId);
        if (!hash_equals($revisionBefore, $this->revision($rowsImmediatelyBeforeWrite))) {
            throw new StorageServerWeightException(
                'revision_conflict',
                'The storage server group changed immediately before its weights were written.'
            );
        }

        $databaseWriteAttempted = false;
        $expectedClusterBytes = null;
        $clusterDataUpdated = false;

        try {
            if ($changed) {
                $databaseWriteAttempted = true;
                $this->updateWeights($groupId, $weights);
                $writtenRows = $this->fetchGroupRows($groupId);
                $this->assertWeightsMatch($writtenRows, $weights);
                $revisionAfter = $this->revision($writtenRows);
            }

            $clusterRows = $this->clusterPublisher->fetchRows(
                $this->db,
                $this->serverTable,
                $clusterFields
            );
            $expectedClusterBytes = $this->clusterPublisher->serializeRows($clusterRows);
            if (!hash_equals(hash('sha256', $snapshot['bytes']), hash('sha256', $expectedClusterBytes))) {
                $this->clusterPublisher->publish($clusterRows, $snapshot['permissions']);
                $clusterDataUpdated = true;
            }

            $finalRows = $this->fetchGroupRows($groupId);
            $this->assertWeightsMatch($finalRows, $weights);
            $finalClusterRows = $this->clusterPublisher->fetchRows(
                $this->db,
                $this->serverTable,
                $clusterFields
            );
            $this->assertClusterRowsMatch($finalClusterRows);

            return $this->mutationResult(
                $groupId,
                $revisionBefore,
                $this->revision($finalRows),
                false,
                $changed,
                $clusterDataUpdated,
                $changes
            );
        } catch (\Throwable $e) {
            $recovered = $this->recover(
                $groupId,
                $beforeRows,
                $weights,
                $snapshot,
                $databaseWriteAttempted,
                $expectedClusterBytes
            );

            throw new StorageServerWeightException(
                'set_weights_failed',
                'Failed to apply storage server weights: ' . $e->getMessage(),
                !$recovered,
                $e
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $beforeRows
     * @param array<int, int> $weights
     * @param array{bytes: string, permissions: int} $snapshot
     */
    private function recover(
        int $groupId,
        array $beforeRows,
        array $weights,
        array $snapshot,
        bool $databaseWriteAttempted,
        ?string $expectedClusterBytes
    ): bool {
        try {
            if ($databaseWriteAttempted) {
                $currentRows = $this->fetchGroupRows($groupId);
                $previousById = $this->rowsById($beforeRows);
                $restoreWeights = [];

                foreach ($currentRows as $row) {
                    $serverId = $this->intField($row, 'server_id');
                    $previous = $previousById[$serverId] ?? null;
                    if ($previous === null || !isset($weights[$serverId])) {
                        return false;
                    }

                    $currentWeight = $this->canonicalWeight($row['lb_weight'] ?? null);
                    $previousWeight = $this->canonicalWeight($previous['lb_weight'] ?? null);
                    $writtenWeight = (string) $weights[$serverId];
                    if ($currentWeight === $writtenWeight && $currentWeight !== $previousWeight) {
                        $restoreWeights[$serverId] = $previousWeight;
                    } elseif ($currentWeight !== $previousWeight) {
                        return false;
                    }
                }

                if ($restoreWeights !== []) {
                    $this->updateWeights($groupId, $restoreWeights);
                }
                $this->assertRowsHavePreviousWeights($this->fetchGroupRows($groupId), $beforeRows);
            }

            $currentBytes = $this->clusterPublisher->readBytes();
            if ($currentBytes !== $snapshot['bytes']) {
                if ($expectedClusterBytes === null || $currentBytes !== $expectedClusterBytes) {
                    return false;
                }
                $this->clusterPublisher->restore($snapshot);
            }

            return $this->clusterPublisher->readBytes() === $snapshot['bytes'];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<int, int> $weights
     */
    private function validateWeightVector(int $groupId, array $weights, array $rows): void
    {
        $sum = 0;
        foreach ($weights as $serverId => $weight) {
            if ($serverId < 1 || $weight < 1 || $weight > self::MAX_WEIGHT) {
                throw new StorageServerWeightException(
                    'invalid_weight',
                    "Weight for server {$serverId} must be an integer from 1 to " . self::MAX_WEIGHT . '.'
                );
            }
            $sum += $weight;
        }
        if ($sum > self::MAX_TOTAL_WEIGHT) {
            throw new StorageServerWeightException(
                'excessive_weight_sum',
                'The total weight for a storage server group cannot exceed ' . self::MAX_TOTAL_WEIGHT . '.'
            );
        }

        $groupIds = [];
        foreach ($rows as $row) {
            $groupIds[$this->intField($row, 'server_id')] = true;
        }

        foreach (array_keys($weights) as $serverId) {
            if (isset($groupIds[$serverId])) {
                continue;
            }
            $actualGroup = $this->findServerGroup($serverId);
            if ($actualGroup === null) {
                throw new StorageServerWeightException(
                    'server_not_found',
                    "Storage server not found: {$serverId}."
                );
            }
            throw new StorageServerWeightException(
                'server_out_of_group',
                "Storage server {$serverId} does not belong to group {$groupId}."
            );
        }

        $missing = [];
        foreach (array_keys($groupIds) as $serverId) {
            if (!array_key_exists($serverId, $weights)) {
                $missing[] = $serverId;
            }
        }
        if ($missing !== []) {
            throw new StorageServerWeightException(
                'incomplete_weight_vector',
                "Weights must be provided for every server in group {$groupId}."
            );
        }

        foreach ($rows as $row) {
            if (
                $this->intField($row, 'status_id') === 1
                && $this->intField($row, 'streaming_type_id') !== 5
                && $this->countryList($row['lb_countries'] ?? null) === []
            ) {
                return;
            }
        }

        throw new StorageServerWeightException(
            'no_unrestricted_active_server',
            'At least one active non-backup server without country restrictions must remain available.'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchGroupRows(int $groupId): array
    {
        $group = $this->db->prepare("SELECT group_id FROM {$this->groupTable} WHERE group_id = :group_id");
        $group->execute(['group_id' => $groupId]);
        if ($group->fetchColumn() === false) {
            throw new StorageServerWeightException(
                'group_not_found',
                "Storage server group not found: {$groupId}."
            );
        }

        $stmt = $this->db->prepare(
            "SELECT server_id, group_id, status_id, streaming_type_id, lb_weight, lb_countries
             FROM {$this->serverTable}
             WHERE group_id = :group_id
             ORDER BY server_id ASC"
        );
        $stmt->execute(['group_id' => $groupId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === []) {
            throw new StorageServerWeightException(
                'empty_server_group',
                "Storage server group {$groupId} does not contain any servers."
            );
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalizedRow = [];
            foreach ($row as $key => $value) {
                $normalizedRow[(string) $key] = $value;
            }
            $normalized[] = $normalizedRow;
        }

        return $normalized;
    }

    private function findServerGroup(int $serverId): ?int
    {
        $stmt = $this->db->prepare("SELECT group_id FROM {$this->serverTable} WHERE server_id = :server_id");
        $stmt->execute(['server_id' => $serverId]);
        $groupId = $stmt->fetchColumn();

        return $groupId === false ? null : (int) $groupId;
    }

    /**
     * @param array<int, int|string> $weights
     */
    private function updateWeights(int $groupId, array $weights): void
    {
        if ($weights === []) {
            return;
        }

        $cases = [];
        $ids = [];
        $params = ['group_id' => $groupId];
        $index = 0;
        foreach ($weights as $serverId => $weight) {
            $caseId = 'case_id_' . $index;
            $whereId = 'where_id_' . $index;
            $weightName = 'weight_' . $index;
            $cases[] = "WHEN :{$caseId} THEN :{$weightName}";
            $ids[] = ":{$whereId}";
            $params[$caseId] = $serverId;
            $params[$whereId] = $serverId;
            $params[$weightName] = $weight;
            $index++;
        }

        $sql = "UPDATE {$this->serverTable}
                SET lb_weight = CASE server_id " . implode(' ', $cases) . " ELSE lb_weight END
                WHERE group_id = :group_id AND server_id IN (" . implode(', ', $ids) . ')';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<int, int> $weights
     */
    private function assertWeightsMatch(array $rows, array $weights): void
    {
        if (count($rows) !== count($weights)) {
            throw new \RuntimeException('Storage server group changed during weight verification');
        }
        foreach ($rows as $row) {
            $serverId = $this->intField($row, 'server_id');
            if (!isset($weights[$serverId])) {
                throw new \RuntimeException('Storage server group changed during weight verification');
            }
            if ($this->canonicalWeight($row['lb_weight'] ?? null) !== (string) $weights[$serverId]) {
                throw new \RuntimeException("Weight verification failed for storage server {$serverId}");
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $actualRows
     * @param list<array<string, mixed>> $previousRows
     */
    private function assertRowsHavePreviousWeights(array $actualRows, array $previousRows): void
    {
        $previousById = $this->rowsById($previousRows);
        if (count($actualRows) !== count($previousById)) {
            throw new \RuntimeException('Storage server group changed during recovery verification');
        }
        foreach ($actualRows as $row) {
            $serverId = $this->intField($row, 'server_id');
            $previous = $previousById[$serverId] ?? null;
            if (
                $previous === null
                || $this->canonicalWeight($row['lb_weight'] ?? null)
                    !== $this->canonicalWeight($previous['lb_weight'] ?? null)
            ) {
                throw new \RuntimeException('Storage server weight recovery verification failed');
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $expectedRows
     */
    private function assertClusterRowsMatch(array $expectedRows): void
    {
        if ($this->clusterPublisher->readRows() !== $expectedRows) {
            throw new \RuntimeException('Published storage cluster data verification failed');
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function revision(array $rows): string
    {
        $canonical = [];
        foreach ($rows as $row) {
            $canonical[] = [
                'server_id' => $this->intField($row, 'server_id'),
                'group_id' => $this->intField($row, 'group_id'),
                'status_id' => $this->intField($row, 'status_id'),
                'streaming_type_id' => $this->intField($row, 'streaming_type_id'),
                'lb_weight' => $this->canonicalWeight($row['lb_weight'] ?? null),
                'lb_countries' => $this->countryList($row['lb_countries'] ?? null),
            ];
        }
        usort(
            $canonical,
            static fn(array $left, array $right): int => $left['server_id'] <=> $right['server_id']
        );

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function canonicalWeight(mixed $value): string
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            throw new \RuntimeException('Storage server contains a non-numeric load-balancing weight');
        }
        $raw = trim((string) $value);
        if (preg_match('/^-?\d+(?:\.\d+)?$/', $raw) !== 1) {
            throw new \RuntimeException('Storage server contains a non-numeric load-balancing weight');
        }

        $negative = str_starts_with($raw, '-');
        if ($negative) {
            $raw = substr($raw, 1);
        }
        [$integer, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');
        $normalized = $fraction === '' ? $integer : $integer . '.' . $fraction;

        return $negative && $normalized !== '0' ? '-' . $normalized : $normalized;
    }

    /**
     * @return list<string>
     */
    private function countryList(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $countries = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $value)),
            static fn(string $country): bool => $country !== ''
        )));
        sort($countries, SORT_STRING);

        return $countries;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function publicWeightRows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $statusId = $this->intField($row, 'status_id');
            $streamingTypeId = $this->intField($row, 'streaming_type_id');
            $result[] = [
                'server_id' => $this->intField($row, 'server_id'),
                'weight' => $this->publicWeight($row['lb_weight'] ?? null),
                'status_id' => $statusId,
                'streaming_type_id' => $streamingTypeId,
                'lb_countries' => $this->countryList($row['lb_countries'] ?? null),
                'eligible' => $statusId === 1 && $streamingTypeId !== 5,
            ];
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<int, int> $weights
     * @return list<array<string, mixed>>
     */
    private function replaceGroupWeights(array $rows, array $weights): array
    {
        foreach ($rows as &$row) {
            $serverId = $this->intField($row, 'server_id');
            if (isset($weights[$serverId])) {
                $row['lb_weight'] = (string) $weights[$serverId];
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<int, int> $weights
     * @return list<array<string, mixed>>
     */
    private function replaceClusterWeights(array $rows, int $groupId, array $weights): array
    {
        foreach ($rows as &$row) {
            $serverId = $this->intField($row, 'server_id');
            if ($this->intField($row, 'group_id') === $groupId && isset($weights[$serverId])) {
                $row['lb_weight'] = (string) $weights[$serverId];
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<int, int> $weights
     * @return list<array<string, mixed>>
     */
    private function buildChanges(array $rows, array $weights): array
    {
        $changes = [];
        foreach ($rows as $row) {
            $serverId = $this->intField($row, 'server_id');
            $oldWeight = $this->canonicalWeight($row['lb_weight'] ?? null);
            if (!isset($weights[$serverId]) || $oldWeight === (string) $weights[$serverId]) {
                continue;
            }
            $statusId = $this->intField($row, 'status_id');
            $streamingTypeId = $this->intField($row, 'streaming_type_id');
            $changes[] = [
                'server_id' => $serverId,
                'old_weight' => $this->publicWeight($row['lb_weight'] ?? null),
                'new_weight' => $weights[$serverId],
                'status_id' => $statusId,
                'streaming_type_id' => $streamingTypeId,
                'lb_countries' => $this->countryList($row['lb_countries'] ?? null),
                'eligible' => $statusId === 1 && $streamingTypeId !== 5,
            ];
        }

        return $changes;
    }

    private function publicWeight(mixed $value): int|float
    {
        $canonical = $this->canonicalWeight($value);

        return str_contains($canonical, '.') ? (float) $canonical : (int) $canonical;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function rowsById(array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $byId[$this->intField($row, 'server_id')] = $row;
        }

        return $byId;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function intField(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_numeric($value)) {
            throw new \RuntimeException("Storage server contains an invalid {$field}");
        }

        return (int) $value;
    }

    /**
     * @param list<array<string, mixed>> $changes
     * @return array<string, mixed>
     */
    private function mutationResult(
        int $groupId,
        string $revisionBefore,
        string $revisionAfter,
        bool $dryRun,
        bool $changed,
        bool $clusterDataUpdated,
        array $changes
    ): array {
        return [
            'ok' => true,
            'action' => 'set-weights',
            'group_id' => $groupId,
            'revision_before' => $revisionBefore,
            'revision_after' => $revisionAfter,
            'dry_run' => $dryRun,
            'changed' => $changed,
            'cluster_data_updated' => $clusterDataUpdated,
            'changes' => $changes,
        ];
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withOperationLock(callable $callback): mixed
    {
        if ($this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return $callback();
        }

        $lockName = 'kvs_cli_weights_' . substr(hash(
            'sha256',
            $this->databaseIdentity . '|' . $this->serverTable
        ), 0, 40);
        $stmt = $this->db->prepare('SELECT GET_LOCK(:lock_name, :lock_timeout)');
        $stmt->bindValue('lock_name', $lockName, \PDO::PARAM_STR);
        $stmt->bindValue('lock_timeout', $this->lockTimeout, \PDO::PARAM_INT);
        $stmt->execute();
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new StorageServerWeightException(
                'lock_unavailable',
                "Could not acquire the storage server weight lock within {$this->lockTimeout} seconds."
            );
        }

        $result = null;
        $error = null;
        try {
            $result = $callback();
        } catch (\Throwable $e) {
            $error = $e;
        }

        try {
            $release = $this->db->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute(['lock_name' => $lockName]);
            if ((int) $release->fetchColumn() !== 1 && $error === null) {
                throw new StorageServerWeightException(
                    'lock_release_failed',
                    'Storage server weights were processed, but the advisory lock could not be released.'
                );
            }
        } catch (\Throwable $releaseError) {
            if ($error === null) {
                $error = $releaseError;
            }
        }

        if ($error !== null) {
            throw $error;
        }

        return $result;
    }

    private function assertTableIdentifier(string $table): void
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new \InvalidArgumentException('Invalid storage server table identifier');
        }
    }
}
