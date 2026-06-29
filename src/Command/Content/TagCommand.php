<?php

namespace KVS\CLI\Command\Content;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Command\Traits\RelationUsageTrait;
use KVS\CLI\Command\Traits\ToggleStatusTrait;
use KVS\CLI\Constants;
use KVS\CLI\Output\Formatter;
use KVS\CLI\Output\StatusFormatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'content:tag',
    description: 'Manage KVS tags',
    aliases: ['tag', 'tags']
)]
class TagCommand extends BaseCommand
{
    use RelationUsageTrait;
    use ToggleStatusTrait;

    /** @var list<string> */
    private const SHOW_UNSUPPORTED_OPTIONS = [
        'name',
        'status',
        'search',
        'unused',
    ];

    /** @var array<string, string> */
    private const TAG_RELATION_TABLES = [
        'videos' => 'video_id',
        'albums' => 'album_id',
        'posts' => 'post_id',
        'playlists' => 'playlist_id',
        'content_sources' => 'content_source_id',
        'models' => 'model_id',
        'dvds' => 'dvd_id',
        'dvds_groups' => 'dvd_group_id',
    ];

    protected function configure(): void
    {
        $this
            ->setHelp(<<<'HELP'
Manage KVS tags with full CRUD operations.

<info>ACTIONS:</info>
  list              List all tags (default)
  show <id>         Show tag details
  create <name>     Create new tag
  delete <id>       Delete tag
  update <id>       Update tag name or status
  enable <id>       Enable tag
  disable <id>      Disable tag
  merge <id> <target>  Merge source tag into target tag
  stats             Show tag statistics

<info>EXAMPLES:</info>
  <comment>kvs tag list --search=HD</comment>
  <comment>kvs tag list --unused</comment>
  <comment>kvs tag show 5</comment>
  <comment>kvs tag create "4K UHD"</comment>
  <comment>kvs tag update 5 --name="Ultra HD"</comment>
  <comment>kvs tag enable 3</comment>
  <comment>kvs tag disable 3</comment>
  <comment>kvs tag merge 10 15</comment>
  <comment>kvs tag delete 8</comment>
  <comment>kvs tag stats</comment>
HELP
            )
            ->addArgument('action', InputArgument::OPTIONAL, 'Action: list, show, create, delete, merge, update, enable, disable, stats', 'list')
            ->addArgument('identifier', InputArgument::OPTIONAL, 'Tag ID or name')
            ->addArgument('target', InputArgument::OPTIONAL, 'Target tag ID for merge operation')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Tag name (for update)')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter/set status (active|inactive)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results to show', Constants::DEFAULT_LIMIT)
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search in tag names, directories, and synonyms')
            ->addOption('unused', null, InputOption::VALUE_NONE, 'Show only unused tags')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to display')
            ->addOption('field', null, InputOption::VALUE_REQUIRED, 'Display single field from each item')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count, ids', 'table')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation of long text fields');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $this->getStringArgument($input, 'action') ?? 'list';
        $identifier = $this->getStringArgument($input, 'identifier');
        $target = $this->getStringArgument($input, 'target');

        return match ($action) {
            'list' => $this->listTags($input),
            'show' => $this->showTag($identifier, $input),
            'create' => $this->createTag($identifier),
            'delete' => $this->deleteTag($identifier, $input),
            'update' => $this->updateTag($identifier, $input),
            'enable' => $this->toggleStatus($identifier, 1),
            'disable' => $this->toggleStatus($identifier, 0),
            'merge' => $this->mergeTags($identifier, $target, $input),
            'stats' => $this->showStats($input),
            default => $this->failUnknownAction(
                'tag',
                $action,
                ['list', 'show', 'create', 'delete', 'update', 'enable', 'disable', 'merge', 'stats']
            ),
        };
    }

    private function listTags(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $conditions = ['1=1'];
            $params = [];

            // Status filter
            $status = $this->getStringOption($input, 'status');
            if ($status !== null) {
                $statusId = $this->parseStatusFilterOrFail($input, [
                    'active' => StatusFormatter::TAG_ACTIVE,
                    'inactive' => StatusFormatter::TAG_INACTIVE,
                    'disabled' => StatusFormatter::TAG_INACTIVE,
                ]);
                if ($statusId === false) {
                    return self::FAILURE;
                }
                if ($statusId !== null) {
                    $conditions[] = 't.status_id = :status';
                    $params['status'] = $statusId;
                }
            }

            // Search filter
            $search = $this->getStringOption($input, 'search');
            if ($search !== null) {
                $searchEscape = $this->likeEscapeSql();
                $conditions[] = '(t.tag LIKE :search' . $searchEscape
                    . ' OR t.tag_dir LIKE :search' . $searchEscape
                    . ' OR t.synonyms LIKE :search' . $searchEscape . ')';
                $params['search'] = $this->containsLikePattern($search);
            }

            $usageSelectors = $this->getTagUsageAggregateSelectors();
            $usageJoins = $this->getTagUsageAggregateJoins();
            $totalUsageCondition = $this->getTagTotalUsageJoinCondition();
            $unusedOnly = $this->getBoolOption($input, 'unused');

            if ($unusedOnly) {
                $conditions[] = "{$totalUsageCondition} = 0";
            }

            $whereClause = implode(' AND ', $conditions);

            if ($this->getStringOption($input, 'format') === 'count') {
                if ($this->getPositiveIntOptionOrDefault($input, 'limit', Constants::DEFAULT_LIMIT) === null) {
                    return self::FAILURE;
                }
                $countJoins = $unusedOnly ? $usageJoins : '';
                $stmt = $db->prepare("
                    SELECT COUNT(*)
                    FROM {$this->table('tags')} t
                    {$countJoins}
                    WHERE $whereClause
                ");
                foreach ($params as $key => $value) {
                    $stmt->bindValue(':' . $key, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
                }
                $stmt->execute();

                $total = $stmt->fetchColumn();
                $this->io()->writeln((string) (is_numeric($total) ? (int) $total : 0));

                return self::SUCCESS;
            }

            $limit = $this->getPositiveIntOptionOrDefault($input, 'limit', Constants::DEFAULT_LIMIT);
            if ($limit === null) {
                return self::FAILURE;
            }

            $sql = "
                SELECT t.*,
                       {$usageSelectors}
                FROM {$this->table('tags')} t
                {$usageJoins}
                WHERE $whereClause
            ";

            $sql .= " ORDER BY t.tag_id DESC LIMIT :limit";
            $params['limit'] = $limit;

            $stmt = $db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $stmt->execute();

            /** @var list<array<string, mixed>> $tags */
            $tags = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            /** @var list<array<string, mixed>> $transformedTags */
            $transformedTags = [];
            foreach ($tags as $tag) {
                $counts = $this->extractTagUsageCounts($tag);
                $statusIdVal = $tag['status_id'] ?? 0;
                $statusId = is_numeric($statusIdVal) ? (int) $statusIdVal : 0;
                $otherAmount = $this->getTagStoredOtherAmount($tag);
                $allAmount = $counts['videos'] + $counts['albums'] + $counts['posts'] + $otherAmount;
                $transformedTags[] = [
                    ...$tag,
                    'tag_id' => $tag['tag_id'] ?? 0,
                    'id' => $tag['tag_id'] ?? 0,
                    'tag' => $tag['tag'] ?? '',
                    'tag_rename' => $tag['tag'] ?? '',
                    'video_count' => $counts['videos'],
                    'album_count' => $counts['albums'],
                    'total_usage' => $allAmount,
                    'videos_amount' => $counts['videos'],
                    'albums_amount' => $counts['albums'],
                    'posts_amount' => $counts['posts'],
                    'other_amount' => $otherAmount,
                    'all_amount' => $allAmount,
                    'status_id' => $statusId,
                    'status' => StatusFormatter::tag($statusId, false),
                    ...$counts,
                ];
            }

            // Format and display output using centralized Formatter
            $formatter = new Formatter(
                $input->getOptions(),
                ['tag_id', 'tag', 'video_count', 'album_count', 'total_usage', 'status']
            );
            $formatter->display($transformedTags, $this->io());
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch tags: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function showTag(?string $id, InputInterface $input): int
    {
        if ($id === null || $id === '') {
            $this->io()->error('Tag ID or name is required');
            return self::FAILURE;
        }

        if ($this->rejectUnsupportedOptionsForAction($input, 'show', self::SHOW_UNSUPPORTED_OPTIONS)) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $whereClause = ctype_digit($id) ? 't.tag_id = :identifier_id' : 't.tag = :identifier_name';
            $stmt = $db->prepare("
                SELECT t.*,
                       {$this->getTagUsageSelectors()}
                FROM {$this->table('tags')} t
                WHERE {$whereClause}
            ");
            $stmt->execute(ctype_digit($id) ? ['identifier_id' => (int) $id] : ['identifier_name' => $id]);
            /** @var array<string, mixed>|false $tag */
            $tag = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($tag === false) {
                $this->io()->error("Tag not found: $id");
                return self::FAILURE;
            }

            $tagName = is_string($tag['tag'] ?? null) ? $tag['tag'] : '';

            $counts = $this->extractTagUsageCounts($tag);
            $statusIdRaw = $tag['status_id'] ?? 0;
            $statusId = is_numeric($statusIdRaw) ? (int) $statusIdRaw : 0;
            $tagIdRaw = $tag['tag_id'] ?? 0;
            $tagId = is_scalar($tagIdRaw) ? (string) $tagIdRaw : '0';
            $tagDir = is_string($tag['tag_dir'] ?? null) ? $tag['tag_dir'] : '';
            $addedDate = is_string($tag['added_date'] ?? null) ? $tag['added_date'] : 'N/A';

            $info = [
                ['ID', $tagId],
                ['Name', $tagName],
                ['Slug', $tagDir],
                ['Status', StatusFormatter::tag($statusId)],
            ];

            foreach ($this->getTagUsageLabels() as $suffix => $label) {
                $info[] = [$label, (string) ($counts[$suffix] ?? 0)];
            }

            $otherAmount = $this->getTagStoredOtherAmount($tag);
            $totalUsage = $counts['videos'] + $counts['albums'] + $counts['posts'] + $otherAmount;
            $info[] = ['Total Usage', (string) $totalUsage];
            $info[] = ['Added', $addedDate];

            if (!$this->isTableFormat($input)) {
                return $this->displayDetailRows($input, $info);
            }

            $this->io()->section("Tag: $tagName");
            $this->renderTable(['Property', 'Value'], $info);
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch tag: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function createTag(?string $tagName): int
    {
        if ($tagName === null || $tagName === '') {
            $this->io()->error('Tag name is required');
            $this->io()->text('Usage: kvs content:tag create "Tag Name"');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            // Check if tag already exists
            $stmt = $db->prepare("SELECT tag_id FROM {$this->table('tags')} WHERE tag = :tag");
            $stmt->execute(['tag' => $tagName]);

            if ($stmt->fetch() !== false) {
                $this->io()->error("Tag already exists: $tagName");
                return self::FAILURE;
            }

            $tagDir = $this->getUniqueTagDir($db, $this->slugifyTagDir($tagName));

            // Relax sql_mode for INSERT (KVS tables have many NOT NULL without DEFAULT)
            $db->exec("SET @old_sql_mode = @@sql_mode, sql_mode = ''");

            $stmt = $db->prepare("
                INSERT INTO {$this->table('tags')} (tag, tag_dir, synonyms, status_id, added_date, last_content_date)
                VALUES (:tag, :tag_dir, '', 1, NOW(), NOW())
            ");

            $stmt->execute(['tag' => $tagName, 'tag_dir' => $tagDir]);

            $tagId = $db->lastInsertId();

            $db->exec("SET sql_mode = @old_sql_mode");

            $this->io()->success("Tag created successfully!");
            $this->renderTable(
                ['Property', 'Value'],
                [
                    ['ID', (string) $tagId],
                    ['Name', $tagName],
                    ['Status', 'Active'],
                ]
            );
        } catch (\Exception $e) {
            $this->io()->error('Failed to create tag: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function deleteTag(?string $identifier, InputInterface $input): int
    {
        if ($identifier === null || $identifier === '') {
            $this->io()->error('Tag ID is required');
            $this->io()->text('Usage: kvs content:tag delete <tag_id>');
            return self::FAILURE;
        }
        if (!ctype_digit($identifier)) {
            $this->io()->error('Tag ID must be numeric');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            // Get tag details
            $stmt = $db->prepare("SELECT * FROM {$this->table('tags')} WHERE tag_id = :id");
            $stmt->execute(['id' => $identifier]);
            $tag = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($tag)) {
                $this->io()->error("Tag not found: $identifier");
                return self::FAILURE;
            }

            $usage = $this->getTagUsageCounts($db, $identifier);
            $totalUsage = array_sum($usage);

            if ($totalUsage > 0) {
                $this->io()->warning("This tag is used by $totalUsage items:");
                $this->io()->listing($this->formatUsageCounts($usage));

                if ($this->io()->confirm('Delete anyway? This will remove all associations.', false) !== true) {
                    if (!$input->isInteractive()) {
                        $this->io()->error('Tag deletion cancelled because confirmation was not provided.');
                        return self::FAILURE;
                    }

                    $this->io()->info('Operation cancelled');
                    return self::SUCCESS;
                }
            }

            $this->deleteTagRelations($db, $identifier);

            // Delete tag
            $stmt = $db->prepare("DELETE FROM {$this->table('tags')} WHERE tag_id = :id");
            $stmt->execute(['id' => $identifier]);

            $tagValue = $tag['tag'] ?? '';
            $deletedTagName = is_string($tagValue) ? $tagValue : (is_scalar($tagValue) ? (string) $tagValue : '');
            $this->io()->success("Tag '$deletedTagName' deleted successfully!");
        } catch (\Exception $e) {
            $this->io()->error('Failed to delete tag: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function getTagUsageSelectors(): string
    {
        return $this->getRelationUsageSelectors('tags', 't', 'tag_id', self::TAG_RELATION_TABLES);
    }

    private function getTagAdminTotalUsageExpression(): string
    {
        $tagsTable = $this->table('tags');
        return sprintf(
            '((SELECT COUNT(*) FROM %1$s_videos WHERE tag_id = t.tag_id) +
              (SELECT COUNT(*) FROM %1$s_albums WHERE tag_id = t.tag_id) +
              (SELECT COUNT(*) FROM %1$s_posts WHERE tag_id = t.tag_id) +
              COALESCE(t.total_content_sources, 0) +
              COALESCE(t.total_playlists, 0) +
              COALESCE(t.total_models, 0) +
              COALESCE(t.total_dvds, 0) +
              COALESCE(t.total_dvd_groups, 0))',
            $tagsTable
        );
    }

    private function getTagUsageAggregateSelectors(): string
    {
        $selectors = [];
        foreach (array_keys(self::TAG_RELATION_TABLES) as $suffix) {
            $selectors[] = sprintf(
                'COALESCE(%s.usage_count, 0) as %s',
                $this->getTagUsageAggregateAlias($suffix),
                $this->getRelationUsageAlias($suffix)
            );
        }

        return implode(",\n                       ", $selectors);
    }

    private function getTagUsageAggregateJoins(): string
    {
        $joins = [];
        foreach (array_keys(self::TAG_RELATION_TABLES) as $suffix) {
            $alias = $this->getTagUsageAggregateAlias($suffix);
            $joins[] = sprintf(
                'LEFT JOIN (SELECT tag_id, COUNT(*) as usage_count FROM %s_%s GROUP BY tag_id) %s ON %s.tag_id = t.tag_id',
                $this->table('tags'),
                $suffix,
                $alias,
                $alias
            );
        }

        return implode("\n                ", $joins);
    }

    private function getTagTotalUsageJoinCondition(): string
    {
        return implode(' + ', array_map(
            fn(string $suffix): string => sprintf(
                'COALESCE(%s.usage_count, 0)',
                $this->getTagUsageAggregateAlias($suffix)
            ),
            array_keys(self::TAG_RELATION_TABLES)
        ));
    }

    private function getTagUsageAggregateAlias(string $suffix): string
    {
        return 'tag_usage_' . $suffix;
    }

    /**
     * @return array<string, string>
     */
    private function getTagUsageLabels(): array
    {
        return $this->getRelationUsageLabels(self::TAG_RELATION_TABLES);
    }

    /**
     * @param array<string, mixed> $tag
     * @return array<string, int>
     */
    private function extractTagUsageCounts(array $tag): array
    {
        return $this->extractRelationUsageCounts($tag, self::TAG_RELATION_TABLES);
    }

    /**
     * @param array<string, mixed> $tag
     */
    private function getTagStoredOtherAmount(array $tag): int
    {
        $total = 0;
        foreach (['total_content_sources', 'total_playlists', 'total_models', 'total_dvds', 'total_dvd_groups'] as $field) {
            $value = $tag[$field] ?? 0;
            $total += is_numeric($value) ? (int) $value : 0;
        }

        return $total;
    }

    private function mergeTags(?string $sourceId, ?string $targetId, InputInterface $input): int
    {
        if ($sourceId === null || $sourceId === '' || $targetId === null || $targetId === '') {
            $this->io()->error('Both source and target tag IDs are required');
            $this->io()->text('Usage: kvs content:tag merge <source_tag_id> <target_tag_id>');
            return self::FAILURE;
        }
        if (!ctype_digit($sourceId) || !ctype_digit($targetId)) {
            $this->io()->error('Source and target tag IDs must be numeric');
            return self::FAILURE;
        }

        if ($sourceId === $targetId) {
            $this->io()->error('Source and target tags must be different');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            // Verify both tags exist
            $stmt = $db->prepare("SELECT tag_id, tag FROM {$this->table('tags')} WHERE tag_id IN (:source, :target)");
            $stmt->execute(['source' => $sourceId, 'target' => $targetId]);
            $tags = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (count($tags) !== 2) {
                $this->io()->error('One or both tags not found');
                return self::FAILURE;
            }

            /** @var array<string, mixed>|null $sourceTag */
            $sourceTag = null;
            /** @var array<string, mixed>|null $targetTag */
            $targetTag = null;
            foreach ($tags as $tag) {
                if (!is_array($tag)) {
                    continue;
                }
                $tagIdVal = $tag['tag_id'] ?? null;
                $tagIdStr = is_scalar($tagIdVal) ? (string) $tagIdVal : '';
                if ($tagIdStr === $sourceId) {
                    $sourceTag = $tag;
                }
                if ($tagIdStr === $targetId) {
                    $targetTag = $tag;
                }
            }

            if ($sourceTag === null || $targetTag === null) {
                $this->io()->error('One or both tags not found');
                return self::FAILURE;
            }

            $sourceTagVal = $sourceTag['tag'] ?? '';
            $targetTagVal = $targetTag['tag'] ?? '';
            $sourceTagName = is_scalar($sourceTagVal) ? (string) $sourceTagVal : '';
            $targetTagName = is_scalar($targetTagVal) ? (string) $targetTagVal : '';

            $this->io()->section('Merge Operation');
            $this->io()->text("Source: $sourceTagName (ID: $sourceId)");
            $this->io()->text("Target: $targetTagName (ID: $targetId)");
            $this->io()->newLine();
            $this->io()->warning('All associations will be moved to the target tag, then source tag will be deleted.');

            if ($this->io()->confirm('Continue with merge?', false) !== true) {
                if (!$input->isInteractive()) {
                    $this->io()->error('Tag merge cancelled because confirmation was not provided.');
                    return self::FAILURE;
                }

                $this->io()->info('Operation cancelled');
                return self::SUCCESS;
            }

            $db->beginTransaction();

            $this->mergeTagRelations($db, $sourceId, $targetId);
            $db->prepare("DELETE FROM {$this->table('tags')} WHERE tag_id = :id")->execute(['id' => $sourceId]);

            $db->commit();

            $this->io()->success("Tags merged successfully!");
            $this->io()->text("'$sourceTagName' has been merged into '$targetTagName'");
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->io()->error('Failed to merge tags: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, int>
     */
    private function getTagUsageCounts(\PDO $db, string $tagId): array
    {
        return $this->getRelationUsageCounts($db, 'tags', 'tag_id', $tagId, self::TAG_RELATION_TABLES);
    }

    /**
     * @param array<string, int> $usage
     * @return list<string>
     */
    private function formatUsageCounts(array $usage): array
    {
        $lines = [];
        $labels = $this->getTagUsageLabels();
        foreach ($usage as $suffix => $count) {
            $label = $labels[$suffix] ?? $suffix;
            $lines[] = "$label: $count";
        }

        return $lines;
    }

    private function deleteTagRelations(\PDO $db, string $tagId): void
    {
        foreach (self::TAG_RELATION_TABLES as $suffix => $objectColumn) {
            $table = $this->table('tags') . '_' . $suffix;
            $db->prepare("DELETE FROM {$table} WHERE tag_id = :id")->execute(['id' => $tagId]);
        }
    }

    private function mergeTagRelations(\PDO $db, string $sourceId, string $targetId): void
    {
        foreach (self::TAG_RELATION_TABLES as $suffix => $objectColumn) {
            $table = $this->table('tags') . '_' . $suffix;
            $stmt = $db->prepare("
                SELECT src.{$objectColumn}
                FROM {$table} src
                INNER JOIN {$table} dst ON dst.{$objectColumn} = src.{$objectColumn}
                WHERE src.tag_id = :source AND dst.tag_id = :target
            ");
            $stmt->execute(['source' => $sourceId, 'target' => $targetId]);
            $duplicates = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            $deleteStmt = $db->prepare("
                DELETE FROM {$table}
                WHERE tag_id = :source AND {$objectColumn} = :object_id
            ");
            foreach ($duplicates as $duplicate) {
                if (is_scalar($duplicate)) {
                    $deleteStmt->execute(['source' => $sourceId, 'object_id' => $duplicate]);
                }
            }

            $updateStmt = $db->prepare("UPDATE {$table} SET tag_id = :target WHERE tag_id = :source");
            $updateStmt->execute(['target' => $targetId, 'source' => $sourceId]);
        }
    }

    private function showStats(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            // Overall stats
            $stmt = $db->query("
                SELECT
                    COUNT(*) as total_tags,
                    SUM(CASE WHEN status_id = " . StatusFormatter::TAG_ACTIVE . " THEN 1 ELSE 0 END) as active_tags,
                    SUM(CASE WHEN status_id = " . StatusFormatter::TAG_INACTIVE . " THEN 1 ELSE 0 END) as inactive_tags
                FROM {$this->table('tags')}
            ");
            if ($stmt === false) {
                throw new \RuntimeException('Failed to execute overall stats query');
            }
            $overall = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($overall)) {
                throw new \RuntimeException('Failed to fetch overall stats');
            }

            // Usage stats
            $totalUsageExpression = $this->getTagAdminTotalUsageExpression();
            $stmt = $db->query("
                SELECT SUM(CASE WHEN {$totalUsageExpression} > 0 THEN 1 ELSE 0 END) as used_tags
                FROM {$this->table('tags')} t
            ");
            if ($stmt === false) {
                throw new \RuntimeException('Failed to execute usage stats query');
            }
            $usageStats = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($usageStats)) {
                throw new \RuntimeException('Failed to fetch usage stats');
            }

            $totalTags = is_numeric($overall['total_tags']) ? (int) $overall['total_tags'] : 0;
            $usedTags = is_numeric($usageStats['used_tags']) ? (int) $usageStats['used_tags'] : 0;
            $unusedTags = $totalTags - $usedTags;

            // Top tags
            $usageSelectors = $this->getTagUsageSelectors();

            $stmt = $db->query("
                SELECT t.*,
                       {$usageSelectors}
                FROM {$this->table('tags')} t
                ORDER BY {$totalUsageExpression} DESC
                LIMIT " . Constants::TOP_QUERY_LIMIT . "
            ");
            if ($stmt === false) {
                throw new \RuntimeException('Failed to execute top tags query');
            }
            $topTags = $stmt->fetchAll();

            $activeTags = is_numeric($overall['active_tags']) ? (int) $overall['active_tags'] : 0;
            $inactiveTags = is_numeric($overall['inactive_tags']) ? (int) $overall['inactive_tags'] : 0;

            /** @var list<array<string, mixed>> $metricRows */
            $metricRows = [
                $this->metricRow('overall', 'Total Tags', $totalTags),
                $this->metricRow('overall', 'Active Tags', $activeTags),
                $this->metricRow('overall', 'Inactive Tags', $inactiveTags),
                $this->metricRow('overall', 'Used Tags', $usedTags),
                $this->metricRow('overall', 'Unused Tags', $unusedTags),
            ];

            /** @var list<list<int|string>> $topRows */
            $topRows = [];
            if ($topTags !== []) {
                foreach ($topTags as $i => $tag) {
                    if (!is_array($tag)) {
                        continue;
                    }
                    /** @var array<string, mixed> $tag */
                    $tagName = $tag['tag'] ?? '';
                    $counts = $this->extractTagUsageCounts($tag);
                    $videoCount = $counts['videos'];
                    $albumCount = $counts['albums'];
                    $otherCount = $counts['posts'] + $this->getTagStoredOtherAmount($tag);
                    $total = $videoCount + $albumCount + $otherCount;
                    $metricRows[] = $this->metricRow(
                        'top_tags',
                        (string) ($i + 1),
                        $total,
                        (string) $total,
                        is_scalar($tagName) ? (string) $tagName : ''
                    );
                    $topRows[] = [
                        is_scalar($tagName) ? (string) $tagName : '',
                        $videoCount,
                        $albumCount,
                        $otherCount,
                        $total,
                    ];
                }
            }

            if (!$this->isTableFormat($input)) {
                $this->displayMetricRows($input, $metricRows);
                return self::SUCCESS;
            }

            $this->io()->title('Tag Statistics');
            $this->io()->section('Overall Statistics');
            $this->renderTable(
                ['Metric', 'Count'],
                [
                    ['Total Tags', (string) $totalTags],
                    ['Active Tags', (string) $activeTags],
                    ['Inactive Tags', (string) $inactiveTags],
                    ['Used Tags', (string) $usedTags],
                    ['Unused Tags', (string) $unusedTags],
                ]
            );

            if ($topRows !== []) {
                $this->io()->section('Top 10 Most Used Tags');
                $this->renderTable(['Tag', 'Videos', 'Albums', 'Other', 'Total'], $topRows);
            }
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch stats: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function updateTag(?string $id, InputInterface $input): int
    {
        $tagId = $this->getRequiredPositiveId($id, 'Tag');
        if ($tagId === null) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            // Get current tag
            $stmt = $db->prepare("SELECT * FROM {$this->table('tags')} WHERE tag_id = :id");
            $stmt->execute(['id' => $tagId]);
            $tag = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($tag)) {
                $this->io()->error("Tag not found: $tagId");
                return self::FAILURE;
            }

            $updates = [];
            $params = ['id' => $tagId];

            // Name
            $name = $this->getStringOption($input, 'name');
            if ($name !== null) {
                $stmt = $db->prepare("SELECT tag_id, tag FROM {$this->table('tags')} WHERE tag = :tag AND tag_id != :id");
                $stmt->execute(['tag' => $name, 'id' => $id]);
                $existingTag = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (is_array($existingTag)) {
                    $targetIdValue = $existingTag['tag_id'] ?? null;
                    $targetId = is_scalar($targetIdValue) ? (string) $targetIdValue : '';
                    if ($targetId === '') {
                        $this->io()->error("Tag name already exists: $name");
                        return self::FAILURE;
                    }

                    $db->beginTransaction();
                    $this->mergeTagRelations($db, (string) $tagId, $targetId);
                    $deleteStmt = $db->prepare("DELETE FROM {$this->table('tags')} WHERE tag_id = :id");
                    $deleteStmt->execute(['id' => $tagId]);
                    $db->commit();

                    $tagValue = $tag['tag'] ?? '';
                    $sourceName = is_scalar($tagValue) ? (string) $tagValue : '';
                    $targetNameValue = $existingTag['tag'] ?? $name;
                    $targetName = is_scalar($targetNameValue) ? (string) $targetNameValue : $name;

                    $this->io()->success('Tags merged successfully!');
                    $this->io()->text("'$sourceName' has been merged into '$targetName'");

                    return self::SUCCESS;
                }
                $updates[] = 'tag = :tag';
                $params['tag'] = $name;
                // Also update tag_dir (URL slug)
                $tagDir = $this->getUniqueTagDir($db, $this->slugifyTagDir($name), (string) $tagId);
                $updates[] = 'tag_dir = :tag_dir';
                $params['tag_dir'] = $tagDir;
            }

            // Status
            $status = $this->getStringOption($input, 'status');
            if ($status !== null) {
                $statusId = $this->parseStatusFilter($input, [
                    'active' => StatusFormatter::TAG_ACTIVE,
                    'inactive' => StatusFormatter::TAG_INACTIVE,
                    'disabled' => StatusFormatter::TAG_INACTIVE,
                ]);
                if ($statusId === null) {
                    throw new \InvalidArgumentException('Status is required');
                }
                $updates[] = 'status_id = :status_id';
                $params['status_id'] = $statusId;
            }

            if ($updates === []) {
                $this->io()->warning('No changes specified. Use --name or --status options.');
                return self::FAILURE;
            }

            // Update tag
            $sql = "UPDATE {$this->table('tags')} SET " . implode(', ', $updates) . " WHERE tag_id = :id";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            $tagValue = $tag['tag'] ?? '';
            $tagStr = is_string($tagValue) ? $tagValue : (is_scalar($tagValue) ? (string) $tagValue : '');
            $newName = $params['tag'] ?? $tagStr;
            $currentStatusId = $params['status_id'] ?? (is_numeric($tag['status_id']) ? (int) $tag['status_id'] : 0);
            $statusLabel = $currentStatusId !== StatusFormatter::TAG_INACTIVE ? 'Active' : 'Inactive';

            $this->io()->success("Tag updated successfully!");
            $this->renderTable(
                ['Property', 'Value'],
                [
                    ['ID', (string) $tagId],
                    ['New Name', $newName],
                    ['Status', $statusLabel],
                ]
            );
        } catch (\Exception $e) {
            $this->io()->error('Failed to update tag: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Toggle tag status (enable/disable)
     *
     * Uses ToggleStatusTrait for generic implementation.
     *
     * @param string|null $id Tag ID
     * @param int $status Target status (0 = disable, 1 = enable)
     * @return int Command::SUCCESS or Command::FAILURE
     */
    private function toggleStatus(?string $id, int $status): int
    {
        return $this->toggleEntityStatus(
            entityName: 'Tag',
            tableName: $this->table('tags'),
            idColumn: 'tag_id',
            nameColumn: 'tag',
            id: $id,
            status: $status,
            commandName: 'content:tag'
        );
    }

    private function slugifyTagDir(string $tagName): string
    {
        $tagDir = preg_replace('/[^a-z0-9]+/', '-', strtolower($tagName));

        return trim((string) $tagDir, '-');
    }

    private function getUniqueTagDir(\PDO $db, string $baseDir, ?string $excludeId = null): string
    {
        for ($i = 1; $i < 999999; $i++) {
            $tagDir = $i === 1 ? $baseDir : $baseDir . $i;
            $sql = "SELECT COUNT(*) FROM {$this->table('tags')} WHERE tag_dir = :tag_dir";
            $params = ['tag_dir' => $tagDir];

            if ($excludeId !== null) {
                $sql .= ' AND tag_id != :id';
                $params['id'] = $excludeId;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            if ((int) $stmt->fetchColumn() === 0) {
                return $tagDir;
            }
        }

        throw new \RuntimeException('Unable to generate unique tag directory');
    }
}
