<?php

namespace KVS\CLI\Service;

class StorageClusterDataPublisher
{
    /** @var list<string> */
    public const FIELDS = [
        'server_id',
        'group_id',
        'content_type_id',
        'status_id',
        'streaming_type_id',
        'streaming_script',
        'streaming_key',
        'is_replace_domain_on_satellite',
        'urls',
        'is_remote',
        'control_script_url',
        'control_script_url_lock_ip',
        'time_offset',
        'lb_weight',
        'lb_countries',
        'error_streaming_id',
        'error_streaming_iteration',
        'warning_id',
    ];

    public function __construct(private string $clusterFile)
    {
    }

    public function assertWritable(): void
    {
        $clusterDir = dirname($this->clusterFile);
        if (!is_dir($clusterDir)) {
            throw new \RuntimeException("Storage cluster data directory does not exist: {$clusterDir}");
        }
        if (!is_file($this->clusterFile) || !is_writable($this->clusterFile)) {
            throw new \RuntimeException("Storage cluster data file is not writable: {$this->clusterFile}");
        }
        if (!is_writable($clusterDir)) {
            throw new \RuntimeException("Storage cluster data directory is not writable: {$clusterDir}");
        }
    }

    /**
     * @param list<string>|null $fields
     * @return list<array<string, mixed>>
     */
    public function fetchRows(\PDO $db, string $serverTable, ?array $fields = null): array
    {
        $fields ??= self::FIELDS;
        $this->validateFields($fields);
        $columns = implode(', ', array_map(static fn(string $field): string => "`{$field}`", $fields));
        $stmt = $db->query("SELECT {$columns} FROM {$serverTable} ORDER BY server_id ASC");
        if ($stmt === false) {
            throw new \RuntimeException('Failed to query storage servers for cluster data');
        }

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalizedRow = [];
            foreach ($fields as $field) {
                $normalizedRow[$field] = $row[$field] ?? null;
            }
            $normalized[] = $normalizedRow;
        }

        return $normalized;
    }

    /**
     * Determines the installed KVS field set from its current cluster data.
     *
     * KVS releases add fields to update_cluster_data() over time. Preserving
     * the serialized key order avoids silently removing fields on newer KVS
     * installations while the required fields and their order remain pinned.
     *
     * @return list<string>
     */
    public function fieldsFromSerializedRows(string $bytes): array
    {
        $rows = $this->decodeRows($bytes);
        if ($rows === []) {
            throw new \RuntimeException('Storage cluster data does not contain any servers');
        }

        $fields = array_keys($rows[0]);
        $this->validateFields($fields);
        foreach ($rows as $row) {
            if (array_keys($row) !== $fields) {
                throw new \RuntimeException('Storage cluster data rows do not use a consistent field set');
            }
        }

        $requiredFields = array_values(array_filter(
            $fields,
            static fn(string $field): bool => in_array($field, self::FIELDS, true)
        ));
        if ($requiredFields !== self::FIELDS) {
            throw new \RuntimeException('Storage cluster data does not use the required KVS field order');
        }

        return $fields;
    }

    /**
     * @return array{bytes: string, permissions: int}
     */
    public function snapshot(): array
    {
        $this->assertWritable();
        $bytes = $this->readFile($this->clusterFile);
        if ($bytes === false) {
            throw new \RuntimeException('Failed to read existing storage cluster data');
        }
        $permissions = fileperms($this->clusterFile);
        if ($permissions === false) {
            throw new \RuntimeException('Failed to read storage cluster data permissions');
        }

        return [
            'bytes' => $bytes,
            'permissions' => $permissions & 0777,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function serializeRows(array $rows): string
    {
        return serialize($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function validateStaging(array $rows, int $permissions): void
    {
        $temporaryFile = $this->stageBytes($this->serializeRows($rows), $permissions, $rows);
        $this->removeFile($temporaryFile);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function publish(array $rows, int $permissions): string
    {
        $bytes = $this->serializeRows($rows);
        $temporaryFile = $this->stageBytes($bytes, $permissions, $rows);

        try {
            if (!$this->renameFile($temporaryFile, $this->clusterFile)) {
                throw new \RuntimeException('Failed to publish storage cluster data atomically');
            }
        } finally {
            if (is_file($temporaryFile)) {
                $this->removeFile($temporaryFile);
            }
        }

        return $bytes;
    }

    /**
     * @param array{bytes: string, permissions: int} $snapshot
     */
    public function restore(array $snapshot): void
    {
        $temporaryFile = $this->stageBytes($snapshot['bytes'], $snapshot['permissions'], null);

        try {
            if (!$this->renameFile($temporaryFile, $this->clusterFile)) {
                throw new \RuntimeException('Failed to restore original storage cluster data');
            }
        } finally {
            if (is_file($temporaryFile)) {
                $this->removeFile($temporaryFile);
            }
        }
    }

    public function readBytes(): string
    {
        $bytes = $this->readFile($this->clusterFile);
        if ($bytes === false) {
            throw new \RuntimeException('Failed to read storage cluster data');
        }

        return $bytes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function readRows(): array
    {
        return $this->decodeRows($this->readBytes());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeRows(string $bytes): array
    {
        $rows = @unserialize($bytes, ['allowed_classes' => false]);
        if (!is_array($rows)) {
            throw new \RuntimeException('Storage cluster data cannot be unserialized');
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException('Storage cluster data contains an invalid row');
            }
            $normalizedRow = [];
            foreach ($row as $key => $value) {
                $normalizedRow[(string) $key] = $value;
            }
            $normalized[] = $normalizedRow;
        }

        return $normalized;
    }

    /**
     * @param list<string> $fields
     */
    private function validateFields(array $fields): void
    {
        if ($fields === [] || count(array_unique($fields)) !== count($fields)) {
            throw new \RuntimeException('Storage cluster data contains an invalid field set');
        }
        foreach ($fields as $field) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field) !== 1) {
                throw new \RuntimeException('Storage cluster data contains an invalid field name');
            }
        }
        foreach (self::FIELDS as $requiredField) {
            if (!in_array($requiredField, $fields, true)) {
                throw new \RuntimeException("Storage cluster data is missing required field {$requiredField}");
            }
        }
    }

    /**
     * @param list<array<string, mixed>>|null $expectedRows
     */
    private function stageBytes(string $bytes, int $permissions, ?array $expectedRows): string
    {
        $temporaryFile = $this->createTemporaryFile(dirname($this->clusterFile));
        if ($temporaryFile === false) {
            throw new \RuntimeException('Failed to create temporary storage cluster data file');
        }
        if (dirname($temporaryFile) !== dirname($this->clusterFile)) {
            $this->removeFile($temporaryFile);
            throw new \RuntimeException('Temporary storage cluster data file was not created in the target directory');
        }

        try {
            $written = $this->writeFile($temporaryFile, $bytes);
            if ($written !== strlen($bytes)) {
                throw new \RuntimeException('Failed to write temporary storage cluster data file');
            }
            if (!$this->changePermissions($temporaryFile, $permissions)) {
                throw new \RuntimeException('Failed to preserve storage cluster data permissions');
            }

            if ($expectedRows !== null) {
                $stagedBytes = $this->readFile($temporaryFile);
                if ($stagedBytes === false) {
                    throw new \RuntimeException('Failed to read temporary storage cluster data file');
                }
                $decoded = @unserialize($stagedBytes, ['allowed_classes' => false]);
                if (!is_array($decoded) || $decoded !== $expectedRows) {
                    throw new \RuntimeException('Temporary storage cluster data validation failed');
                }
            }

            return $temporaryFile;
        } catch (\Throwable $e) {
            if (is_file($temporaryFile)) {
                $this->removeFile($temporaryFile);
            }
            throw $e;
        }
    }

    protected function createTemporaryFile(string $directory): string|false
    {
        return tempnam($directory, '.cluster.dat.kvs-cli-');
    }

    protected function writeFile(string $path, string $bytes): int|false
    {
        return file_put_contents($path, $bytes, LOCK_EX);
    }

    protected function readFile(string $path): string|false
    {
        return file_get_contents($path);
    }

    protected function changePermissions(string $path, int $permissions): bool
    {
        return chmod($path, $permissions);
    }

    protected function renameFile(string $source, string $target): bool
    {
        return rename($source, $target);
    }

    protected function removeFile(string $path): void
    {
        @unlink($path);
    }
}
