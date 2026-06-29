<?php

namespace KVS\CLI\Command\Traits;

/**
 * Shared helpers for commands that count KVS relation tables.
 */
trait RelationUsageTrait
{
    /** @return list<string> */
    protected function getAdminUsageFilterValues(): array
    {
        return [
            'used/videos',
            'used/albums',
            'used/posts',
            'used/other',
            'used/all',
            'notused/videos',
            'notused/albums',
            'notused/posts',
            'notused/other',
            'notused/all',
        ];
    }

    protected function getAdminUsageFilterCondition(
        string $usage,
        string $videosExpression,
        string $albumsExpression,
        string $postsExpression,
        string $otherExpression,
        string $allExpression
    ): ?string {
        $filters = [
            'used/videos' => "{$videosExpression} > 0",
            'used/albums' => "{$albumsExpression} > 0",
            'used/posts' => "{$postsExpression} > 0",
            'used/other' => "{$otherExpression} > 0",
            'used/all' => "{$allExpression} > 0",
            'notused/videos' => "{$videosExpression} = 0",
            'notused/albums' => "{$albumsExpression} = 0",
            'notused/posts' => "{$postsExpression} = 0",
            'notused/other' => "{$otherExpression} = 0",
            'notused/all' => "{$allExpression} = 0",
        ];

        return $filters[$usage] ?? null;
    }

    /**
     * @param array<string, string> $relationTables
     */
    protected function getRelationUsageSelectors(
        string $relationBaseTable,
        string $entityAlias,
        string $idColumn,
        array $relationTables
    ): string {
        $selectors = [];
        foreach (array_keys($relationTables) as $suffix) {
            $alias = $this->getRelationUsageAlias($suffix);
            $selectors[] = sprintf(
                '(SELECT COUNT(*) FROM %s_%s WHERE %s = %s.%s) as %s',
                $this->table($relationBaseTable),
                $suffix,
                $idColumn,
                $entityAlias,
                $idColumn,
                $alias
            );
        }

        return implode(",\n                       ", $selectors);
    }

    /**
     * @param array<string, string> $relationTables
     */
    protected function getRelationTotalUsageAliasExpression(array $relationTables): string
    {
        return implode(' + ', array_map(
            fn(string $suffix): string => $this->getRelationUsageAlias($suffix),
            array_keys($relationTables)
        ));
    }

    /**
     * @param array<string, string> $relationTables
     */
    protected function getRelationTotalUsageSubqueryExpression(
        string $relationBaseTable,
        string $entityAlias,
        string $idColumn,
        array $relationTables
    ): string {
        $subqueries = [];
        foreach (array_keys($relationTables) as $suffix) {
            $subqueries[] = sprintf(
                '(SELECT COUNT(*) FROM %s_%s WHERE %s = %s.%s)',
                $this->table($relationBaseTable),
                $suffix,
                $idColumn,
                $entityAlias,
                $idColumn
            );
        }

        return implode(' + ', $subqueries);
    }

    protected function getRelationUsageAlias(string $suffix): string
    {
        return $suffix . '_count';
    }

    /**
     * @param array<string, string> $relationTables
     * @return array<string, string>
     */
    protected function getRelationUsageLabels(array $relationTables): array
    {
        $labels = [
            'videos' => 'Videos',
            'content_sources' => 'Content sources',
            'albums' => 'Albums',
            'posts' => 'Posts',
            'playlists' => 'Playlists',
            'dvds' => 'DVDs',
            'dvds_groups' => 'DVD groups',
            'models' => 'Models',
        ];

        $orderedLabels = [];
        foreach (array_keys($relationTables) as $suffix) {
            $orderedLabels[$suffix] = $labels[$suffix] ?? $suffix;
        }

        return $orderedLabels;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $relationTables
     * @return array<string, int>
     */
    protected function extractRelationUsageCounts(array $row, array $relationTables): array
    {
        $counts = [];
        foreach (array_keys($relationTables) as $suffix) {
            $value = $row[$this->getRelationUsageAlias($suffix)] ?? 0;
            $counts[$suffix] = is_numeric($value) ? (int) $value : 0;
        }

        return $counts;
    }

    /**
     * @param array<string, string> $relationTables
     * @return array<string, int>
     */
    protected function getRelationUsageCounts(
        \PDO $db,
        string $relationBaseTable,
        string $idColumn,
        string $id,
        array $relationTables
    ): array {
        $usage = [];
        foreach (array_keys($relationTables) as $suffix) {
            $table = $this->table($relationBaseTable) . '_' . $suffix;
            $stmt = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE {$idColumn} = :id");
            $stmt->execute(['id' => $id]);
            $count = $stmt->fetchColumn();
            $usage[$suffix] = is_numeric($count) ? (int) $count : 0;
        }

        return $usage;
    }
}
