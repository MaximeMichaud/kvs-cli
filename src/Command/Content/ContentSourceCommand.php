<?php

namespace KVS\CLI\Command\Content;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Command\Traits\ToggleStatusTrait;
use KVS\CLI\Constants;
use KVS\CLI\Output\StatusFormatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'content:content-source',
    description: 'Manage KVS content sources',
    aliases: ['content-source', 'content-sources', 'source', 'sources', 'site', 'sites']
)]
class ContentSourceCommand extends BaseCommand
{
    use ToggleStatusTrait;

    /** @var list<string> */
    private const OUTPUT_FORMATS = ['table', 'csv', 'json', 'yaml', 'count', 'ids'];

    /** @var list<string> */
    private const DEFAULT_LIST_FIELDS = [
        'content_source_id',
        'title',
        'content_source_group',
        'videos_amount',
        'albums_amount',
        'status',
        'sort_id',
    ];

    /** @var list<string> */
    private const CUSTOM_FIELDS = [
        'custom1',
        'custom2',
        'custom3',
        'custom4',
        'custom5',
        'custom6',
        'custom7',
        'custom8',
        'custom9',
        'custom10',
    ];

    /** @var array<string, string> */
    private const FILE_OPTIONS = [
        'screenshot1' => 's1_',
        'screenshot2' => 's2_',
        'custom-file1' => 'c1_',
        'custom-file2' => 'c2_',
        'custom-file3' => 'c3_',
        'custom-file4' => 'c4_',
        'custom-file5' => 'c5_',
        'custom-file6' => 'c6_',
        'custom-file7' => 'c7_',
        'custom-file8' => 'c8_',
        'custom-file9' => 'c9_',
        'custom-file10' => 'c10_',
    ];

    /** @var list<string> */
    private const FIELD_FILTER_COLUMNS = [
        'url',
        'description',
        'synonyms',
        'screenshot1',
        'screenshot2',
        'custom1',
        'custom2',
        'custom3',
        'custom4',
        'custom5',
        'custom6',
        'custom7',
        'custom8',
        'custom9',
        'custom10',
        'custom_file1',
        'custom_file2',
        'custom_file3',
        'custom_file4',
        'custom_file5',
        'custom_file6',
        'custom_file7',
        'custom_file8',
        'custom_file9',
        'custom_file10',
    ];

    /** @var array<string, string> */
    private const SORT_FIELDS = [
        'content_source_id' => 's.content_source_id',
        'title' => 's.title',
        'dir' => 's.dir',
        'url' => 's.url',
        'description' => 's.description',
        'status_id' => 's.status_id',
        'content_source_group' => 'g.title',
        'rating' => 'rating',
        'cs_viewed' => 's.cs_viewed',
        'videos_amount' => 'videos_amount',
        'albums_amount' => 'albums_amount',
        'all_amount' => 'all_amount',
        'comments_amount' => 'comments_amount',
        'subscribers_amount' => 's.subscribers_count',
        'added_date' => 's.added_date',
        'rank' => 's.rank',
        'sort_id' => 's.sort_id',
    ];

    /** @var list<string> */
    private const SHOW_UNSUPPORTED_OPTIONS = [
        'title',
        'description',
        'url',
        'synonyms',
        'status',
        'group',
        'content-source-group',
        'dir',
        'sort',
        'rating',
        'rating-amount',
        'tags',
        'categories',
        'screenshot1',
        'screenshot2',
        'custom1',
        'custom2',
        'custom3',
        'custom4',
        'custom5',
        'custom6',
        'custom7',
        'custom8',
        'custom9',
        'custom10',
        'custom-file1',
        'custom-file2',
        'custom-file3',
        'custom-file4',
        'custom-file5',
        'custom-file6',
        'custom-file7',
        'custom-file8',
        'custom-file9',
        'custom-file10',
        'limit',
        'search',
        'used',
        'unused',
        'usage',
        'field-filter',
        'sort-by',
        'sort-dir',
    ];

    protected function configure(): void
    {
        $this
            ->setHelp(<<<'HELP'
Manage KVS content sources with CRUD operations.

KVS calls content sources "Sites" in the admin UI. This command manages
the content_sources table and stores uploaded source files in the KVS
content source file directory.

<info>ACTIONS:</info>
  list              List content sources (default)
  show <id|dir>     Show one content source
  create <title>    Create a content source
  update <id>       Update a content source
  delete <id>       Delete a content source and detach related content
  enable <id>       Enable a content source
  disable <id>      Disable a content source

<info>EXAMPLES:</info>
  <comment>kvs content-source list --status=active --usage=used/all</comment>
  <comment>kvs content-source list --group="Studios" --field-filter=filled/screenshot1</comment>
  <comment>kvs source create "Sample Source" --url=https://source.example/ --group="Studios"</comment>
  <comment>kvs source update 12 --screenshot1=/tmp/source.jpg --custom1=featured</comment>
  <comment>kvs source update 12 --screenshot1= --custom-file1=</comment>
  <comment>kvs source delete 12</comment>
HELP
            )
            ->addArgument(
                'action',
                InputArgument::OPTIONAL,
                'Action: list, show, create, update, delete, enable, disable',
                'list'
            )
            ->addArgument('id', InputArgument::OPTIONAL, 'Content source ID, directory, or title when creating')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Content source title')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Content source description')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'External source URL')
            ->addOption('synonyms', null, InputOption::VALUE_REQUIRED, 'Comma-separated synonyms')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Status (active|inactive|disabled|1|0)')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'Content source group ID or title')
            ->addOption('content-source-group', null, InputOption::VALUE_REQUIRED, 'Alias for --group')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Directory slug (auto-generated from title if omitted)')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'Manual sort order (sort_id, integer >= 0)')
            ->addOption('rating', null, InputOption::VALUE_REQUIRED, 'Average rating, from 0 to 10')
            ->addOption('rating-amount', null, InputOption::VALUE_REQUIRED, 'Rating vote count, integer >= 1')
            ->addOption('screenshot1', null, InputOption::VALUE_REQUIRED, 'Upload/replace screenshot 1 image; use empty value to clear on update')
            ->addOption('screenshot2', null, InputOption::VALUE_REQUIRED, 'Upload/replace screenshot 2 image; use empty value to clear on update')
            ->addOption('tags', null, InputOption::VALUE_REQUIRED, 'Comma-separated tag IDs or names')
            ->addOption('categories', null, InputOption::VALUE_REQUIRED, 'Comma-separated category IDs or titles')
            ->addOption('tag', null, InputOption::VALUE_REQUIRED, 'Filter by tag ID or name')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Filter by category ID or title')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results to show', Constants::DEFAULT_LIMIT)
            ->addOption(
                'search',
                null,
                InputOption::VALUE_REQUIRED,
                'Search in IDs, titles, dirs, descriptions, synonyms, URLs, custom fields, and filenames'
            )
            ->addOption('used', null, InputOption::VALUE_NONE, 'Show only content sources used by videos or albums')
            ->addOption('unused', null, InputOption::VALUE_NONE, 'Show only content sources not used by videos or albums')
            ->addOption(
                'usage',
                null,
                InputOption::VALUE_REQUIRED,
                'KVS admin usage filter (used/videos|used/albums|used/all|notused/videos|notused/albums|notused/all)'
            )
            ->addOption('field-filter', null, InputOption::VALUE_REQUIRED, 'KVS admin field filter (e.g. filled/description)')
            ->addOption('sort-by', null, InputOption::VALUE_REQUIRED, 'Sort field (default: content_source_id)')
            ->addOption('sort-dir', null, InputOption::VALUE_REQUIRED, 'Sort direction: asc or desc (default: desc)')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to display')
            ->addOption('field', null, InputOption::VALUE_REQUIRED, 'Display single field from each item')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count, ids', 'table')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation of long text fields');

        foreach (self::CUSTOM_FIELDS as $field) {
            $number = substr($field, 6);
            $this->addOption($field, null, InputOption::VALUE_REQUIRED, "Custom field $number");
        }
        for ($i = 1; $i <= 10; $i++) {
            $this->addOption(
                "custom-file{$i}",
                null,
                InputOption::VALUE_REQUIRED,
                "Upload/replace custom file $i; use empty value to clear on update"
            );
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->validateOutputFormat($input, self::OUTPUT_FORMATS) === null) {
            return self::FAILURE;
        }

        $action = $this->getStringArgument($input, 'action') ?? 'list';
        $id = $this->getStringArgument($input, 'id');

        return match ($action) {
            'list' => $this->listSources($input),
            'show' => $this->showSource($id, $input),
            'create', 'add' => $this->createSource($input),
            'update' => $this->updateSource($id, $input),
            'delete' => $this->deleteSource($id, $input),
            'enable' => $this->toggleStatus($id, 1),
            'disable' => $this->toggleStatus($id, 0),
            default => $this->failUnknownAction(
                'content-source',
                $action,
                ['list', 'show', 'create', 'update', 'delete', 'enable', 'disable']
            ),
        };
    }

    private function listSources(InputInterface $input): int
    {
        if (
            $this->rejectUnsupportedArgument(
                $input,
                'list',
                'id',
                'a content source ID, directory, or title argument',
                'show',
                'a specific content source'
            )
        ) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $conditions = [];
            /** @var array<string, int|string> $params */
            $params = [];

            $statusId = $this->parseStatusFilterOrFail($input, [
                'active' => StatusFormatter::CONTENT_SOURCE_ACTIVE,
                'inactive' => StatusFormatter::CONTENT_SOURCE_DISABLED,
                'disabled' => StatusFormatter::CONTENT_SOURCE_DISABLED,
            ], [0, 1]);
            if ($statusId === false) {
                return self::FAILURE;
            }
            if ($statusId !== null) {
                $conditions[] = 's.status_id = :status';
                $params['status'] = $statusId;
            }

            $groupId = $this->resolveGroupOption($db, $input, false);
            if ($groupId === false) {
                return self::FAILURE;
            }
            if ($groupId !== null) {
                $conditions[] = 's.content_source_group_id = :group_id';
                $params['group_id'] = $groupId;
            }

            if (
                !$this->addRelationFilter(
                    $db,
                    $input,
                    'tag',
                    'tags_content_sources',
                    'tag_id',
                    'tags',
                    'tag',
                    'tag_id',
                    $conditions,
                    $params
                )
            ) {
                return self::FAILURE;
            }
            if (
                !$this->addRelationFilter(
                    $db,
                    $input,
                    'category',
                    'categories_content_sources',
                    'category_id',
                    'categories',
                    'title',
                    'category_id',
                    $conditions,
                    $params
                )
            ) {
                return self::FAILURE;
            }

            $search = $this->getStringOption($input, 'search');
            if ($search !== null) {
                $conditions[] = $this->buildAdminSearchCondition(
                    's.content_source_id',
                    array_merge(
                        [
                            's.title',
                            's.dir',
                            's.description',
                            's.synonyms',
                            's.url',
                            's.screenshot1',
                            's.screenshot2',
                        ],
                        array_map(static fn (string $field): string => "s.$field", self::CUSTOM_FIELDS),
                        array_map(static fn (int $i): string => "s.custom_file{$i}", range(1, 10))
                    ),
                    $search,
                    $params
                );
            }

            $fieldFilter = $this->getStringOption($input, 'field-filter');
            if ($fieldFilter !== null) {
                $condition = $this->getFieldFilterCondition($fieldFilter);
                if ($condition === null) {
                    $this->io()->error('Invalid content source field filter. Use: ' . implode(', ', $this->getFieldFilterValues()));
                    return self::FAILURE;
                }
                $conditions[] = $condition;
            }

            if ($this->hasConflictingBoolOptions($input, ['used', 'unused'])) {
                return self::FAILURE;
            }

            $usage = $this->getStringOption($input, 'usage');
            if ($usage !== null && ($this->getBoolOption($input, 'used') || $this->getBoolOption($input, 'unused'))) {
                $this->io()->error('Options --usage, --used, and --unused cannot be combined');
                return self::FAILURE;
            }

            if ($this->getBoolOption($input, 'used')) {
                $conditions[] = $this->getUsageCondition('used/all');
            } elseif ($this->getBoolOption($input, 'unused')) {
                $conditions[] = $this->getUsageCondition('notused/all');
            } elseif ($usage !== null) {
                $condition = $this->getUsageCondition($usage);
                if ($condition === null) {
                    $this->io()->error('Invalid content source usage filter. Use: ' . implode(', ', $this->getUsageValues()));
                    return self::FAILURE;
                }
                $conditions[] = $condition;
            }

            $whereClause = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

            if ($this->getStringOptionOrDefault($input, 'format', 'table') === 'count') {
                if ($this->rejectFieldSelectionForCountFormat($input)) {
                    return self::FAILURE;
                }
                if ($this->getPositiveIntOptionOrDefault($input, 'limit', Constants::DEFAULT_LIMIT) === null) {
                    return self::FAILURE;
                }
                return $this->countSources($db, $whereClause, $params);
            }

            $limit = $this->getPositiveIntOptionOrDefault($input, 'limit', Constants::DEFAULT_LIMIT);
            if ($limit === null) {
                return self::FAILURE;
            }

            $sortBy = $this->getSortBy($input);
            if ($sortBy === null) {
                return self::FAILURE;
            }
            $sortDirection = $this->getSortDirection($input);
            if ($sortDirection === null) {
                return self::FAILURE;
            }

            $sql = $this->getSourceSelectSql($whereClause, "ORDER BY {$sortBy} {$sortDirection}, s.content_source_id DESC LIMIT :limit");
            $stmt = $db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            /** @var list<array<string, mixed>> $sources */
            $sources = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $sources = array_map(fn (array $source): array => $this->enrichSourceRow($source), $sources);

            return $this->displayFormattedRows(
                $input,
                $sources,
                self::DEFAULT_LIST_FIELDS,
                $this->getKnownFields($sources)
            );
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch content sources: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function countSources(\PDO $db, string $whereClause, array $params): int
    {
        try {
            $stmt = $db->prepare("
                SELECT COUNT(*)
                FROM {$this->table('content_sources')} s
                LEFT JOIN {$this->table('content_sources_groups')} g ON g.content_source_group_id = s.content_source_group_id
                $whereClause
            ");
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $stmt->execute();

            $total = $stmt->fetchColumn();
            $this->io()->writeln((string) (is_numeric($total) ? (int) $total : 0));

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to count content sources: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function showSource(?string $identifier, InputInterface $input): int
    {
        if ($this->rejectUnsupportedOptionsForAction($input, 'show', self::SHOW_UNSUPPORTED_OPTIONS)) {
            return self::FAILURE;
        }
        if ($this->rejectCountFormatForSingularAction($input, 'show')) {
            return self::FAILURE;
        }
        if ($identifier === null || $identifier === '') {
            $this->io()->error('Content source ID, directory, or title is required');
            $this->io()->text('Usage: kvs content-source show <content_source_id|dir|title>');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $source = $this->fetchSourceByIdentifier($db, $identifier);
            if ($source === null) {
                $this->io()->error("Content source not found: $identifier");
                return self::FAILURE;
            }

            $source = $this->enrichSourceRow($source);
            $sourceId = is_numeric($source['content_source_id'] ?? null) ? (int) $source['content_source_id'] : 0;
            $tags = [];
            $categories = [];
            if ($sourceId > 0) {
                $tags = $this->fetchRelationNames(
                    $db,
                    $sourceId,
                    'tags_content_sources',
                    'tag_id',
                    'tags',
                    'tag'
                );
                $categories = $this->fetchRelationNames(
                    $db,
                    $sourceId,
                    'categories_content_sources',
                    'category_id',
                    'categories',
                    'title'
                );
            }
            $source['tags'] = implode(', ', $tags);
            $source['categories'] = implode(', ', $categories);

            $title = $this->stringValue($source['title'] ?? '');
            $statusId = is_numeric($source['status_id'] ?? null) ? (int) $source['status_id'] : 0;
            $info = [
                ['ID', (string) $sourceId],
                ['Title', $title],
                ['Dir', $this->stringValue($source['dir'] ?? '')],
                ['URL', $this->stringValue($source['url'] ?? '') !== ''
                    ? $this->stringValue($source['url'] ?? '')
                    : 'None'],
                ['Group', $this->formatSourceGroup($source)],
                ['Status', StatusFormatter::contentSource($statusId)],
                ['Rating', $this->stringValue($source['rating'] ?? '0')],
                ['Rating amount', $this->stringValue($source['rating_amount'] ?? '0')],
                ['Videos', $this->stringValue($source['videos_amount'] ?? '0')],
                ['Albums', $this->stringValue($source['albums_amount'] ?? '0')],
                ['Comments', $this->stringValue($source['comments_amount'] ?? '0')],
                ['Subscribers', $this->stringValue($source['subscribers_amount'] ?? '0')],
                ['Sort', $this->stringValue($source['sort_id'] ?? '0')],
                ['Added', $this->stringValue($source['added_date'] ?? 'N/A') !== ''
                    ? $this->stringValue($source['added_date'] ?? 'N/A')
                    : 'N/A'],
            ];

            foreach (['synonyms', 'screenshot1', 'screenshot2', 'tags', 'categories'] as $field) {
                $value = $this->stringValue($source[$field] ?? '');
                if ($value !== '') {
                    $info[] = [ucfirst($field), $value];
                }
            }
            foreach (self::CUSTOM_FIELDS as $field) {
                $value = $this->stringValue($source[$field] ?? '');
                if ($value !== '') {
                    $info[] = [ucfirst(str_replace('custom', 'Custom ', $field)), $value];
                }
            }
            for ($i = 1; $i <= 10; $i++) {
                $field = "custom_file{$i}";
                $value = $this->stringValue($source[$field] ?? '');
                if ($value !== '') {
                    $info[] = ["Custom file $i", $value];
                }
            }

            if ($this->shouldUseFormattedRows($input)) {
                return $this->displayDetailRows($input, $info, $this->getRequestedDetailFieldsForSource($input, $source));
            }

            $this->io()->section("Content source: $title");
            $this->renderTable(['Property', 'Value'], $info);

            $description = $source['description'] ?? null;
            if ($description !== null && $description !== '' && is_scalar($description)) {
                $this->io()->section('Description');
                $this->io()->text((string) $description);
            }
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch content source: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function createSource(InputInterface $input): int
    {
        $title = $this->getStringOption($input, 'title') ?? $this->getStringArgument($input, 'id');
        if ($title === null || $title === '') {
            $this->io()->error('Content source title is required');
            $this->io()->text('Usage: kvs content-source create "Source Name"');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            if ($this->sourceTitleExists($db, $title, null)) {
                $this->io()->error("Content source already exists: $title");
                return self::FAILURE;
            }

            $synonyms = $this->getRawStringOptionOrDefault($input, 'synonyms', '');
            if (!$this->validateSynonyms($db, $synonyms)) {
                return self::FAILURE;
            }

            $url = $this->getRawStringOptionOrDefault($input, 'url', '');
            if (!$this->validateUrl($url)) {
                return self::FAILURE;
            }

            $statusId = $this->getStatusOption($input);
            if ($statusId === false) {
                return self::FAILURE;
            }
            $statusId ??= StatusFormatter::CONTENT_SOURCE_ACTIVE;

            $sortId = $this->parseSortId($input);
            if ($sortId === false) {
                return self::FAILURE;
            }

            $ratingData = $this->getRatingData($input);
            if ($ratingData === false) {
                return self::FAILURE;
            }

            $groupId = $this->resolveGroupOption($db, $input, true);
            if ($groupId === false) {
                return self::FAILURE;
            }

            $dir = $this->resolveInputDir($this->getRawStringOption($input, 'dir'), $title, 'source');
            if ($dir === null) {
                return self::FAILURE;
            }
            $dir = $this->resolveUniqueSourceDir($db, $dir, null);

            $uploads = $this->collectCreateUploads($db, $input);
            if ($uploads === false) {
                return self::FAILURE;
            }

            $sourceId = 0;
            $createdFiles = [];
            $description = $this->getRawStringOptionOrDefault($input, 'description', '');
            $customValues = $this->collectCustomValues($input, []);

            $db->beginTransaction();
            $this->relaxSqlMode($db);
            try {
                $fields = [
                    'content_source_group_id' => $groupId ?? 0,
                    'title' => $title,
                    'dir' => $dir,
                    'description' => $description,
                    'synonyms' => $synonyms,
                    'status_id' => $statusId,
                    'screenshot1' => $uploads['screenshot1']['filename'] ?? '',
                    'screenshot2' => $uploads['screenshot2']['filename'] ?? '',
                    'url' => $url,
                    'rating' => $ratingData['rating'],
                    'rating_amount' => $ratingData['rating_amount'],
                    'sort_id' => $sortId,
                    'added_date' => date('Y-m-d H:i:s'),
                ];
                foreach (self::CUSTOM_FIELDS as $field) {
                    $fields[$field] = $customValues[$field];
                }
                for ($i = 1; $i <= 10; $i++) {
                    $option = "custom-file{$i}";
                    $fields["custom_file{$i}"] = $uploads[$option]['filename'] ?? '';
                }

                $columns = array_keys($fields);
                $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
                $stmt = $db->prepare(
                    "INSERT INTO {$this->table('content_sources')}
                        (" . implode(', ', $columns) . ')
                     VALUES
                        (' . implode(', ', $placeholders) . ')'
                );
                $stmt->execute($fields);

                $sourceId = (int) $db->lastInsertId();
                $createdFiles = $this->installUploads($sourceId, array_values($uploads));
                $this->syncTags($db, $sourceId, $this->getRawStringOption($input, 'tags'));
                $this->syncCategories($db, $sourceId, $this->getRawStringOption($input, 'categories'));
                $this->writeAdminAuditLog($db, 100, $sourceId, Constants::OBJECT_TYPE_CONTENT_SOURCE);
                $this->restoreSqlMode($db);
                $db->commit();
            } catch (\Throwable $mutationError) {
                $this->restoreSqlMode($db);
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $this->removeFiles($createdFiles);
                if ($sourceId > 0) {
                    $this->removeEmptySourceDirectory($sourceId);
                }
                throw $mutationError;
            }

            $this->io()->success('Content source created successfully!');
            return $this->showSource((string) $sourceId, $input);
        } catch (\Exception $e) {
            $this->io()->error('Failed to create content source: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function updateSource(?string $id, InputInterface $input): int
    {
        $sourceId = $this->requireNumericId($id, 'update');
        if ($sourceId === null) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $source = $this->fetchSourceById($db, $sourceId);
            if ($source === null) {
                $this->io()->error("Content source not found: $sourceId");
                return self::FAILURE;
            }

            $updates = [];
            $params = ['id' => $sourceId];
            $changedFields = [];
            if (!$this->collectScalarUpdates($db, $input, $source, $sourceId, $updates, $params, $changedFields)) {
                return self::FAILURE;
            }

            $fileChanges = $this->collectUpdateUploads($db, $input, $source, $sourceId, $updates, $params, $changedFields);
            if ($fileChanges === false) {
                return self::FAILURE;
            }

            $tagsSpecified = $this->getRawStringOption($input, 'tags') !== null;
            $categoriesSpecified = $this->getRawStringOption($input, 'categories') !== null;
            if ($updates === [] && !$tagsSpecified && !$categoriesSpecified) {
                $this->io()->warning(
                    'No changes specified. Use --title, --description, --url, --synonyms, --group, --dir, --status, '
                    . '--sort, --rating, --tags, --categories, screenshots, custom fields, or custom files.'
                );
                return self::FAILURE;
            }

            $newFiles = [];
            $db->beginTransaction();
            $this->relaxSqlMode($db);
            try {
                $newFiles = $this->installUploads($sourceId, array_values($fileChanges['uploads']));
                if ($updates !== []) {
                    $stmt = $db->prepare("
                        UPDATE {$this->table('content_sources')}
                        SET " . implode(', ', $updates) . '
                        WHERE content_source_id = :id
                    ');
                    $stmt->execute($params);
                }
                if ($tagsSpecified) {
                    $this->syncTags($db, $sourceId, $this->getRawStringOption($input, 'tags'));
                    $changedFields[] = 'tags';
                }
                if ($categoriesSpecified) {
                    $this->syncCategories($db, $sourceId, $this->getRawStringOption($input, 'categories'));
                    $changedFields[] = 'categories';
                }
                $this->writeAdminAuditLog(
                    $db,
                    150,
                    $sourceId,
                    Constants::OBJECT_TYPE_CONTENT_SOURCE,
                    implode(', ', array_values(array_unique($changedFields)))
                );
                $this->restoreSqlMode($db);
                $db->commit();
            } catch (\Throwable $mutationError) {
                $this->restoreSqlMode($db);
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $this->removeFiles($newFiles);
                throw $mutationError;
            }

            try {
                $this->removeFiles(array_values(array_diff(array_unique($fileChanges['old_files']), $newFiles)));
            } catch (\Throwable $cleanupError) {
                $this->io()->warning('Content source updated, but old file cleanup failed: ' . $cleanupError->getMessage());
            }
            $this->removeSourceCache($sourceId);

            $this->io()->success('Content source updated successfully!');
            return $this->showSource((string) $sourceId, $input);
        } catch (\Exception $e) {
            $this->io()->error('Failed to update content source: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function deleteSource(?string $id, InputInterface $input): int
    {
        $sourceId = $this->requireNumericId($id, 'delete');
        if ($sourceId === null) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $source = $this->fetchSourceById($db, $sourceId);
            if ($source === null) {
                $this->io()->error("Content source not found: $sourceId");
                return self::FAILURE;
            }

            $usage = $this->getSourceUsageCounts($db, $sourceId);
            $totalUsage = array_sum($usage);
            if ($totalUsage > 0) {
                $this->io()->warning("This content source is used by $totalUsage items:");
                $this->io()->listing($this->formatUsageCounts($usage));

                if ($this->io()->confirm('Delete anyway? This will detach content and remove source relations.', false) !== true) {
                    if (!$input->isInteractive()) {
                        $this->io()->error('Content source deletion cancelled because confirmation was not provided.');
                        return self::FAILURE;
                    }

                    $this->io()->info('Operation cancelled');
                    return self::SUCCESS;
                }
            }

            $tagIds = $this->getRelatedIds($db, 'tags_content_sources', 'tag_id', $sourceId);
            $categoryIds = $this->getRelatedIds($db, 'categories_content_sources', 'category_id', $sourceId);
            $commentUserIds = $this->getCommentUserIds($db, $sourceId);

            $db->beginTransaction();
            $this->writeAdminAuditLog($db, 180, $sourceId, Constants::OBJECT_TYPE_CONTENT_SOURCE);
            $db->prepare("DELETE FROM {$this->table('content_sources')} WHERE content_source_id = :id")->execute(['id' => $sourceId]);
            $db->prepare("DELETE FROM {$this->table('categories_content_sources')} WHERE content_source_id = :id")->execute(['id' => $sourceId]);
            $db->prepare("DELETE FROM {$this->table('tags_content_sources')} WHERE content_source_id = :id")->execute(['id' => $sourceId]);
            $db->prepare("DELETE FROM {$this->table('users_events')} WHERE content_source_id = :id")->execute(['id' => $sourceId]);
            $db->prepare("DELETE FROM {$this->table('comments')} WHERE object_id = :id AND object_type_id = :type")
                ->execute(['id' => $sourceId, 'type' => Constants::OBJECT_TYPE_CONTENT_SOURCE]);
            $db->prepare("
                DELETE FROM {$this->table('users_subscriptions')}
                WHERE subscribed_object_id = :id AND subscribed_object_type_id = :type
            ")
                ->execute(['id' => $sourceId, 'type' => Constants::OBJECT_TYPE_CONTENT_SOURCE]);
            $db->prepare("UPDATE {$this->table('videos')} SET content_source_id = 0 WHERE content_source_id = :id")
                ->execute(['id' => $sourceId]);
            $db->prepare("UPDATE {$this->table('albums')} SET content_source_id = 0 WHERE content_source_id = :id")
                ->execute(['id' => $sourceId]);
            $this->safeExecute(
                $db,
                "UPDATE {$this->table('videos_feeds_import')}
                 SET videos_content_source_id = 0
                 WHERE videos_content_source_id = :id",
                ['id' => $sourceId]
            );
            $this->recountRelatedTags($db, $tagIds);
            $this->recountRelatedCategories($db, $categoryIds);
            $this->recountCommentUsers($db, $commentUserIds);
            $db->commit();

            try {
                $this->deleteSourceFiles($sourceId);
                $this->removeSourceCache($sourceId);
            } catch (\Throwable $cleanupError) {
                $this->io()->warning('Content source deleted, but post-delete cleanup failed: ' . $cleanupError->getMessage());
            }

            $deletedTitle = $this->stringValue($source['title'] ?? '');
            $this->io()->success("Content source '$deletedTitle' deleted successfully!");
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->io()->error('Failed to delete content source: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function toggleStatus(?string $id, int $status): int
    {
        $action = $status !== 0 ? 'enable' : 'disable';
        $sourceId = $this->requireNumericId($id, $action);
        if ($sourceId === null) {
            return self::FAILURE;
        }

        return $this->toggleEntityStatus(
            entityName: 'Content source',
            tableName: $this->table('content_sources'),
            idColumn: 'content_source_id',
            nameColumn: 'title',
            id: (string) $sourceId,
            status: $status,
            commandName: 'content:content-source'
        );
    }

    private function getSourceSelectSql(string $whereClause, string $tail): string
    {
        $videosTable = $this->table('videos');
        $albumsTable = $this->table('albums');
        $commentsTable = $this->table('comments');
        $videoCount = "(SELECT COUNT(*) FROM {$videosTable} v_count WHERE v_count.content_source_id = s.content_source_id)";
        $albumCount = "(SELECT COUNT(*) FROM {$albumsTable} a_count WHERE a_count.content_source_id = s.content_source_id)";
        $commentCount = "(SELECT COUNT(*) FROM {$commentsTable} c_count WHERE c_count.object_type_id = "
            . Constants::OBJECT_TYPE_CONTENT_SOURCE . ' AND c_count.object_id = s.content_source_id)';

        return "
            SELECT s.*,
                   CASE WHEN s.rating_amount > 0 THEN s.rating / s.rating_amount ELSE 0 END AS rating,
                   g.title AS content_source_group,
                   {$videoCount} AS videos_amount,
                   {$albumCount} AS albums_amount,
                   ({$videoCount} + {$albumCount}) AS all_amount,
                   {$commentCount} AS comments_amount,
                   s.subscribers_count AS subscribers_amount
            FROM {$this->table('content_sources')} s
            LEFT JOIN {$this->table('content_sources_groups')} g ON g.content_source_group_id = s.content_source_group_id
            $whereClause
            $tail
        ";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchSourceById(\PDO $db, int $sourceId): ?array
    {
        $stmt = $db->prepare($this->getSourceSelectSql('WHERE s.content_source_id = :id', ''));
        $stmt->execute(['id' => $sourceId]);
        return $this->fetchAssoc($stmt);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchSourceByIdentifier(\PDO $db, string $identifier): ?array
    {
        if (preg_match('/^[1-9]\d*$/', $identifier) === 1) {
            return $this->fetchSourceById($db, (int) $identifier);
        }

        $stmt = $db->prepare(
            $this->getSourceSelectSql('WHERE s.dir = :identifier OR s.title = :identifier', 'LIMIT 1')
        );
        $stmt->execute(['identifier' => $identifier]);
        return $this->fetchAssoc($stmt);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchAssoc(\PDOStatement $stmt): ?array
    {
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $result = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $source
     * @param list<string> $updates
     * @param array<string, mixed> $params
     * @param list<string> $changedFields
     */
    private function collectScalarUpdates(
        \PDO $db,
        InputInterface $input,
        array $source,
        int $sourceId,
        array &$updates,
        array &$params,
        array &$changedFields
    ): bool {
        $title = $this->getRawStringOption($input, 'title');
        if ($title !== null) {
            if ($title === '') {
                $this->io()->error('Content source title cannot be empty');
                return false;
            }
            if ($title !== $this->stringValue($source['title'] ?? '') && $this->sourceTitleExists($db, $title, $sourceId)) {
                $this->io()->error("Content source already exists: $title");
                return false;
            }
            $this->queueScalarUpdate($updates, $params, $changedFields, $source, 'title', $title);
        }

        foreach (['description', 'url', 'synonyms'] as $field) {
            $value = $this->getRawStringOption($input, $field);
            if ($value === null) {
                continue;
            }
            if ($field === 'url' && !$this->validateUrl($value)) {
                return false;
            }
            if ($field === 'synonyms' && !$this->validateSynonyms($db, $value)) {
                return false;
            }
            $this->queueScalarUpdate($updates, $params, $changedFields, $source, $field, $value);
        }

        $dirInput = $this->getRawStringOption($input, 'dir');
        if ($dirInput !== null) {
            $baseTitle = $title ?? $this->stringValue($source['title'] ?? '');
            $dir = $this->resolveInputDir($dirInput, $baseTitle, 'source');
            if ($dir === null) {
                return false;
            }
            $this->queueScalarUpdate(
                $updates,
                $params,
                $changedFields,
                $source,
                'dir',
                $this->resolveUniqueSourceDir($db, $dir, $sourceId)
            );
        }

        $status = $this->getStatusOption($input);
        if ($status === false) {
            return false;
        }
        if ($status !== null) {
            $this->queueScalarUpdate($updates, $params, $changedFields, $source, 'status_id', $status);
        }

        if ($this->groupOptionProvided($input)) {
            $groupId = $this->resolveGroupOption($db, $input, true);
            if ($groupId === false) {
                return false;
            }
            $this->queueScalarUpdate($updates, $params, $changedFields, $source, 'content_source_group_id', $groupId ?? 0);
        }

        if ($this->getRawStringOption($input, 'sort') !== null) {
            $sortId = $this->parseSortId($input);
            if ($sortId === false) {
                return false;
            }
            $this->queueScalarUpdate($updates, $params, $changedFields, $source, 'sort_id', $sortId);
        }

        if ($this->getRawStringOption($input, 'rating') !== null || $this->getRawStringOption($input, 'rating-amount') !== null) {
            $ratingData = $this->getRatingData($input, $source);
            if ($ratingData === false) {
                return false;
            }
            $this->queueScalarUpdate($updates, $params, $changedFields, $source, 'rating', $ratingData['rating']);
            $this->queueScalarUpdate($updates, $params, $changedFields, $source, 'rating_amount', $ratingData['rating_amount']);
        }

        foreach (self::CUSTOM_FIELDS as $field) {
            $value = $this->getRawStringOption($input, $field);
            if ($value !== null) {
                $this->queueScalarUpdate($updates, $params, $changedFields, $source, $field, $value);
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $source
     * @param list<string> $updates
     * @param array<string, mixed> $params
     * @param list<string> $changedFields
     * @return array{uploads: array<string, array{source: string, filename: string}>, old_files: list<string>}|false
     */
    private function collectUpdateUploads(
        \PDO $db,
        InputInterface $input,
        array $source,
        int $sourceId,
        array &$updates,
        array &$params,
        array &$changedFields
    ): array|false {
        $uploads = [];
        $oldFiles = [];

        foreach (self::FILE_OPTIONS as $option => $prefix) {
            $raw = $this->getRawStringOption($input, $option);
            if ($raw === null) {
                continue;
            }

            $field = str_replace('-', '_', $option);
            if ($raw === '') {
                $oldFilename = $this->stringValue($source[$field] ?? '');
                if ($oldFilename !== '') {
                    $oldFiles[] = $this->getSourceFilePath($sourceId, $oldFilename);
                }
                $updates[] = "{$field} = :{$field}";
                $params[$field] = '';
                $changedFields[] = $field;
                continue;
            }

            $upload = $this->prepareUpload($input, $option, $prefix);
            if ($upload === false) {
                return false;
            }
            if ($upload !== null) {
                $oldFilename = $this->stringValue($source[$field] ?? '');
                if ($oldFilename !== '') {
                    $oldFiles[] = $this->getSourceFilePath($sourceId, $oldFilename);
                }
                $updates[] = "{$field} = :{$field}";
                $params[$field] = $upload['filename'];
                $changedFields[] = $field;
                $uploads[$option] = $upload;
            }
        }

        if (
            isset($uploads['screenshot1'])
            && !isset($uploads['screenshot2'])
            && $this->getRawStringOption($input, 'screenshot2') === null
            && $this->shouldAutoCreateSecondScreenshot($db)
        ) {
            $upload = $this->duplicateUpload($uploads['screenshot1'], 's2_');
            $oldFilename = $this->stringValue($source['screenshot2'] ?? '');
            if ($oldFilename !== '') {
                $oldFiles[] = $this->getSourceFilePath($sourceId, $oldFilename);
            }
            $updates[] = 'screenshot2 = :screenshot2';
            $params['screenshot2'] = $upload['filename'];
            $changedFields[] = 'screenshot2';
            $uploads['screenshot2'] = $upload;
        }

        return ['uploads' => $uploads, 'old_files' => $oldFiles];
    }

    /**
     * @return array<string, array{source: string, filename: string}>|false
     */
    private function collectCreateUploads(\PDO $db, InputInterface $input): array|false
    {
        $uploads = [];
        foreach (self::FILE_OPTIONS as $option => $prefix) {
            $upload = $this->prepareUpload($input, $option, $prefix);
            if ($upload === false) {
                return false;
            }
            if ($upload !== null) {
                $uploads[$option] = $upload;
            }
        }

        if (
            isset($uploads['screenshot1'])
            && !isset($uploads['screenshot2'])
            && $this->shouldAutoCreateSecondScreenshot($db)
        ) {
            $uploads['screenshot2'] = $this->duplicateUpload($uploads['screenshot1'], 's2_');
        }

        return $uploads;
    }

    private function requireNumericId(?string $id, string $action): ?int
    {
        if ($id === null || $id === '') {
            $this->io()->error('Content source ID is required');
            $this->io()->text("Usage: kvs content-source {$action} <content_source_id>");
            return null;
        }
        if (preg_match('/^[1-9]\d*$/', $id) !== 1) {
            $this->io()->error('Invalid Content source ID (use: integer >= 1)');
            return null;
        }

        return (int) $id;
    }

    /**
     * @param list<array<string, mixed>> $sources
     * @return list<string>
     */
    private function getKnownFields(array $sources): array
    {
        $fields = [
            'id',
            'content_source_id',
            'title',
            'dir',
            'description',
            'synonyms',
            'url',
            'content_source_group_id',
            'content_source_group',
            'status_id',
            'status',
            'rating',
            'rating_amount',
            'cs_viewed',
            'screenshot1',
            'screenshot1_url',
            'screenshot2',
            'screenshot2_url',
            'thumb',
            'videos_amount',
            'albums_amount',
            'all_amount',
            'comments_amount',
            'subscribers_amount',
            'added_date',
            'last_content_date',
            'rank',
            'last_rank',
            'sort_id',
        ];
        $fields = array_merge($fields, self::CUSTOM_FIELDS);
        for ($i = 1; $i <= 10; $i++) {
            $fields[] = "custom_file{$i}";
            $fields[] = "custom_file{$i}_url";
        }

        foreach ($sources as $source) {
            foreach (array_keys($source) as $field) {
                $fields[] = $field;
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function enrichSourceRow(array $source): array
    {
        $sourceId = is_numeric($source['content_source_id'] ?? null) ? (int) $source['content_source_id'] : 0;
        $statusId = is_numeric($source['status_id'] ?? null) ? (int) $source['status_id'] : 0;
        $source['id'] = $sourceId;
        $source['content_source_id'] = $sourceId;
        $source['status_id'] = $statusId;
        $source['status'] = StatusFormatter::contentSource($statusId, false);
        foreach (['videos_amount', 'albums_amount', 'all_amount', 'comments_amount', 'subscribers_amount'] as $field) {
            $source[$field] = is_numeric($source[$field] ?? null) ? (int) $source[$field] : 0;
        }

        $baseUrl = rtrim($this->stringValue($this->config->get('content_url_content_sources', '')), '/');
        foreach (['screenshot1', 'screenshot2'] as $field) {
            $filename = $this->stringValue($source[$field] ?? '');
            $source["{$field}_url"] = ($filename !== '' && $baseUrl !== '' && $sourceId > 0)
                ? "{$baseUrl}/{$sourceId}/{$filename}"
                : '';
        }
        for ($i = 1; $i <= 10; $i++) {
            $field = "custom_file{$i}";
            $filename = $this->stringValue($source[$field] ?? '');
            $source["{$field}_url"] = ($filename !== '' && $baseUrl !== '' && $sourceId > 0)
                ? "{$baseUrl}/{$sourceId}/{$filename}"
                : '';
        }
        $source['thumb'] = $source['screenshot1_url'] !== '' ? $source['screenshot1_url'] : $source['screenshot2_url'];

        return $source;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function getRequestedDetailFieldsForSource(InputInterface $input, array $source): array
    {
        $fields = [];
        foreach ($this->getKnownFields([$source]) as $field) {
            $fields[$field] = $source[$field] ?? '';
        }

        return $this->getRequestedDetailFields($input, $fields);
    }

    private function getFieldFilterCondition(string $fieldFilter): ?string
    {
        if (preg_match('~^(empty|filled)/([a-z0-9_]+)$~', $fieldFilter, $matches) !== 1) {
            return null;
        }

        $mode = $matches[1];
        $field = $matches[2];
        if ($field === 'group') {
            return $mode === 'empty' ? 's.content_source_group_id = 0' : 's.content_source_group_id != 0';
        }
        if ($field === 'cs_viewed') {
            return $mode === 'empty' ? 's.cs_viewed = 0' : 's.cs_viewed != 0';
        }
        if ($field === 'rating') {
            return $mode === 'empty'
                ? '(s.rating = 0 AND s.rating_amount = 1)'
                : '(s.rating > 0 OR s.rating_amount > 1)';
        }
        if ($field === 'tags') {
            $table = $this->table('tags_content_sources');
            return $mode === 'empty'
                ? "NOT EXISTS (SELECT 1 FROM {$table} t_filter WHERE t_filter.content_source_id = s.content_source_id)"
                : "EXISTS (SELECT 1 FROM {$table} t_filter WHERE t_filter.content_source_id = s.content_source_id)";
        }
        if ($field === 'categories') {
            $table = $this->table('categories_content_sources');
            return $mode === 'empty'
                ? "NOT EXISTS (SELECT 1 FROM {$table} c_filter WHERE c_filter.content_source_id = s.content_source_id)"
                : "EXISTS (SELECT 1 FROM {$table} c_filter WHERE c_filter.content_source_id = s.content_source_id)";
        }
        if (!in_array($field, self::FIELD_FILTER_COLUMNS, true)) {
            return null;
        }

        return $mode === 'empty' ? "s.{$field} = ''" : "s.{$field} != ''";
    }

    /**
     * @return list<string>
     */
    private function getFieldFilterValues(): array
    {
        $values = [];
        foreach (array_merge(self::FIELD_FILTER_COLUMNS, ['group', 'cs_viewed', 'rating', 'tags', 'categories']) as $field) {
            $values[] = "empty/{$field}";
            $values[] = "filled/{$field}";
        }

        return $values;
    }

    private function getUsageCondition(string $usage): ?string
    {
        $videosTable = $this->table('videos');
        $albumsTable = $this->table('albums');
        $videoExists = "EXISTS (SELECT 1 FROM {$videosTable} v_usage WHERE v_usage.content_source_id = s.content_source_id)";
        $albumExists = "EXISTS (SELECT 1 FROM {$albumsTable} a_usage WHERE a_usage.content_source_id = s.content_source_id)";

        return match ($usage) {
            'used/videos' => $videoExists,
            'used/albums' => $albumExists,
            'used/all' => "({$videoExists} OR {$albumExists})",
            'notused/videos' => "NOT {$videoExists}",
            'notused/albums' => "NOT {$albumExists}",
            'notused/all' => "(NOT {$videoExists} AND NOT {$albumExists})",
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function getUsageValues(): array
    {
        return ['used/videos', 'used/albums', 'used/all', 'notused/videos', 'notused/albums', 'notused/all'];
    }

    private function getSortBy(InputInterface $input): ?string
    {
        $sortBy = $this->getStringOption($input, 'sort-by') ?? 'content_source_id';
        if (!isset(self::SORT_FIELDS[$sortBy])) {
            $this->io()->error('Invalid content source sort field. Use: ' . implode(', ', array_keys(self::SORT_FIELDS)));
            return null;
        }

        return self::SORT_FIELDS[$sortBy];
    }

    private function getSortDirection(InputInterface $input): ?string
    {
        $direction = strtolower($this->getStringOption($input, 'sort-dir') ?? 'desc');
        if (!in_array($direction, ['asc', 'desc'], true)) {
            $this->io()->error('Invalid content source sort direction. Use: asc or desc');
            return null;
        }

        return strtoupper($direction);
    }

    private function getStatusOption(InputInterface $input): int|false|null
    {
        $status = $this->getStringOption($input, 'status');
        if ($status === null) {
            return null;
        }

        return match (strtolower(trim($status))) {
            'active', '1' => StatusFormatter::CONTENT_SOURCE_ACTIVE,
            'inactive', 'disabled', '0' => StatusFormatter::CONTENT_SOURCE_DISABLED,
            default => $this->failInvalidStatus($status),
        };
    }

    private function parseSortId(InputInterface $input): int|false
    {
        $sort = $this->getStringOption($input, 'sort');
        if ($sort === null || $sort === '') {
            return 0;
        }
        if (preg_match('/^\d+$/', $sort) !== 1) {
            $this->io()->error('Invalid value for --sort (use: integer >= 0)');
            return false;
        }

        return (int) $sort;
    }

    /**
     * @param array<string, mixed>|null $current
     * @return array{rating: int, rating_amount: int}|false
     */
    private function getRatingData(InputInterface $input, ?array $current = null): array|false
    {
        $ratingAmountRaw = $this->getRawStringOption($input, 'rating-amount');
        $ratingRaw = $this->getRawStringOption($input, 'rating');

        $ratingAmount = $ratingAmountRaw !== null
            ? $this->parsePositiveInt($ratingAmountRaw, '--rating-amount')
            : (is_numeric($current['rating_amount'] ?? null) ? (int) $current['rating_amount'] : 1);
        if ($ratingAmount === false) {
            return false;
        }
        if ($ratingAmount < 1) {
            $this->io()->error('Invalid value for --rating-amount (use: integer >= 1)');
            return false;
        }

        $average = $ratingRaw !== null
            ? $this->parseAverageRating($ratingRaw)
            : (is_numeric($current['rating'] ?? null) ? (float) $current['rating'] : 0.0);
        if ($average === false) {
            return false;
        }

        return [
            'rating' => (int) round($average * $ratingAmount),
            'rating_amount' => $ratingAmount,
        ];
    }

    private function parsePositiveInt(string $value, string $option): int|false
    {
        if (preg_match('/^\d+$/', $value) !== 1) {
            $this->io()->error("Invalid value for {$option} (use: integer >= 1)");
            return false;
        }

        return (int) $value;
    }

    private function parseAverageRating(string $value): float|false
    {
        if (preg_match('/^(?:\d+|\d+\.\d+|\.\d+)$/', $value) !== 1) {
            $this->io()->error('Invalid value for --rating (use: number from 0 to 10)');
            return false;
        }

        $rating = (float) $value;
        if ($rating < 0.0 || $rating > 10.0) {
            $this->io()->error('Invalid value for --rating (use: number from 0 to 10)');
            return false;
        }

        return $rating;
    }

    private function failInvalidStatus(string $status): false
    {
        $this->io()->error(sprintf(
            'Invalid status "%s". Valid values: active, inactive, disabled, 1, 0.',
            $status
        ));

        return false;
    }

    private function groupOptionProvided(InputInterface $input): bool
    {
        return $this->getRawStringOption($input, 'group') !== null
            || $this->getRawStringOption($input, 'content-source-group') !== null;
    }

    private function resolveGroupOption(\PDO $db, InputInterface $input, bool $createIfMissing): int|false|null
    {
        $group = $this->getRawStringOption($input, 'group');
        $alias = $this->getRawStringOption($input, 'content-source-group');
        if ($group !== null && $alias !== null) {
            $this->io()->error('Options --group and --content-source-group cannot be used together');
            return false;
        }
        $value = $group ?? $alias;
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        if (preg_match('/^\d+$/', $value) === 1) {
            $groupId = (int) $value;
            if ($groupId === 0 || $this->contentSourceGroupExists($db, $groupId)) {
                return $groupId;
            }
            $this->io()->error("Content source group not found: $value");
            return false;
        }

        $stmt = $db->prepare("SELECT content_source_group_id FROM {$this->table('content_sources_groups')} WHERE title = :title LIMIT 1");
        $stmt->execute(['title' => $value]);
        $found = $stmt->fetchColumn();
        if (is_numeric($found)) {
            return (int) $found;
        }
        if (!$createIfMissing) {
            $this->io()->error("Content source group not found: $value");
            return false;
        }

        $dir = $this->resolveUniqueGroupDir($db, $this->resolveInputDir(null, $value, 'group'), null);
        $this->relaxSqlMode($db);
        try {
            $stmt = $db->prepare("
                INSERT INTO {$this->table('content_sources_groups')}
                    (title, dir, status_id, added_date)
                VALUES
                    (:title, :dir, :status_id, :added_date)
            ");
            $stmt->execute([
                'title' => $value,
                'dir' => $dir,
                'status_id' => StatusFormatter::CONTENT_SOURCE_GROUP_ACTIVE,
                'added_date' => date('Y-m-d H:i:s'),
            ]);
            $groupId = (int) $db->lastInsertId();
            $this->writeAdminAuditLog($db, 100, $groupId, Constants::OBJECT_TYPE_CONTENT_SOURCE_GROUP);
        } finally {
            $this->restoreSqlMode($db);
        }

        return $groupId;
    }

    private function contentSourceGroupExists(\PDO $db, int $groupId): bool
    {
        $stmt = $db->prepare("SELECT content_source_group_id FROM {$this->table('content_sources_groups')} WHERE content_source_group_id = :id");
        $stmt->execute(['id' => $groupId]);

        return $stmt->fetch() !== false;
    }

    /**
     * @param list<string> $conditions
     * @param array<string, int|string> $params
     */
    private function addRelationFilter(
        \PDO $db,
        InputInterface $input,
        string $option,
        string $relationTable,
        string $relationIdColumn,
        string $objectTable,
        string $objectTitleColumn,
        string $objectIdColumn,
        array &$conditions,
        array &$params
    ): bool {
        $raw = $this->getRawStringOption($input, $option);
        if ($raw === null) {
            return true;
        }
        $id = $this->resolveNamedId($db, $raw, $objectTable, $objectIdColumn, $objectTitleColumn, $option);
        if ($id === false) {
            return false;
        }

        $param = $option . '_id';
        $table = $this->table($relationTable);
        $conditions[] = "EXISTS (
            SELECT 1 FROM {$table} rel_{$option}
            WHERE rel_{$option}.content_source_id = s.content_source_id
              AND rel_{$option}.{$relationIdColumn} = :{$param}
        )";
        $params[$param] = $id;

        return true;
    }

    private function resolveNamedId(
        \PDO $db,
        string $raw,
        string $table,
        string $idColumn,
        string $titleColumn,
        string $label
    ): int|false {
        $value = trim($raw);
        if ($value === '') {
            $this->io()->error(sprintf('Invalid value for --%s (use: integer >= 1 or title)', $label));
            return false;
        }
        if (preg_match('/^[1-9]\d*$/', $value) === 1) {
            return (int) $value;
        }

        $stmt = $db->prepare("SELECT {$idColumn} FROM {$this->table($table)} WHERE {$titleColumn} = :title LIMIT 1");
        $stmt->execute(['title' => $value]);
        $id = $stmt->fetchColumn();
        if (is_numeric($id)) {
            return (int) $id;
        }

        $this->io()->error(sprintf('%s not found: %s', ucfirst($label), $value));
        return false;
    }

    /**
     * @return list<string>
     */
    private function fetchRelationNames(
        \PDO $db,
        int $sourceId,
        string $relationTable,
        string $relationIdColumn,
        string $objectTable,
        string $objectTitleColumn
    ): array {
        $stmt = $db->prepare("
            SELECT o.{$objectTitleColumn}
            FROM {$this->table($relationTable)} r
            INNER JOIN {$this->table($objectTable)} o ON o.{$relationIdColumn} = r.{$relationIdColumn}
            WHERE r.content_source_id = :id
            ORDER BY o.{$objectTitleColumn}
        ");
        $stmt->execute(['id' => $sourceId]);

        $values = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $value) {
            if (is_scalar($value)) {
                $values[] = (string) $value;
            }
        }

        return $values;
    }

    private function syncTags(\PDO $db, int $sourceId, ?string $rawTags): void
    {
        if ($rawTags === null) {
            return;
        }

        $oldIds = $this->getRelatedIds($db, 'tags_content_sources', 'tag_id', $sourceId);
        $db->prepare("DELETE FROM {$this->table('tags_content_sources')} WHERE content_source_id = :id")->execute(['id' => $sourceId]);
        $newIds = [];
        foreach ($this->splitList($rawTags) as $tag) {
            $tagId = $this->findOrCreateTag($db, $tag);
            if ($tagId > 0 && !in_array($tagId, $newIds, true)) {
                $db->prepare("INSERT INTO {$this->table('tags_content_sources')} (tag_id, content_source_id) VALUES (:tag_id, :source_id)")
                    ->execute(['tag_id' => $tagId, 'source_id' => $sourceId]);
                $newIds[] = $tagId;
            }
        }
        $this->recountRelatedTags($db, array_values(array_unique(array_merge($oldIds, $newIds))));
    }

    private function syncCategories(\PDO $db, int $sourceId, ?string $rawCategories): void
    {
        if ($rawCategories === null) {
            return;
        }

        $oldIds = $this->getRelatedIds($db, 'categories_content_sources', 'category_id', $sourceId);
        $db->prepare("
            DELETE FROM {$this->table('categories_content_sources')}
            WHERE content_source_id = :id
        ")->execute(['id' => $sourceId]);
        $newIds = [];
        foreach ($this->splitList($rawCategories) as $category) {
            $categoryId = $this->findOrCreateCategory($db, $category);
            if ($categoryId > 0 && !in_array($categoryId, $newIds, true)) {
                $db->prepare("
                    INSERT INTO {$this->table('categories_content_sources')}
                        (category_id, content_source_id)
                    VALUES
                        (:category_id, :source_id)
                ")
                    ->execute(['category_id' => $categoryId, 'source_id' => $sourceId]);
                $newIds[] = $categoryId;
            }
        }
        $this->recountRelatedCategories($db, array_values(array_unique(array_merge($oldIds, $newIds))));
    }

    /**
     * @return list<string>
     */
    private function splitList(string $value): array
    {
        $items = [];
        foreach (explode(',', $value) as $item) {
            $item = trim($item);
            if ($item !== '' && !in_array($item, $items, true)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function findOrCreateTag(\PDO $db, string $tag): int
    {
        if (preg_match('/^[1-9]\d*$/', $tag) === 1) {
            return (int) $tag;
        }
        $stmt = $db->prepare("SELECT tag_id FROM {$this->table('tags')} WHERE tag = :tag LIMIT 1");
        $stmt->execute(['tag' => $tag]);
        $id = $stmt->fetchColumn();
        if (is_numeric($id)) {
            return (int) $id;
        }

        $dir = $this->resolveUniqueTagDir($db, $this->resolveInputDir(null, $tag, 'tag'));
        $stmt = $db->prepare("INSERT INTO {$this->table('tags')} (tag, tag_dir, status_id, added_date) VALUES (:tag, :dir, 1, :added_date)");
        $stmt->execute(['tag' => $tag, 'dir' => $dir, 'added_date' => date('Y-m-d H:i:s')]);
        $id = (int) $db->lastInsertId();
        $this->writeAdminAuditLog($db, 100, $id, Constants::OBJECT_TYPE_TAG);

        return $id;
    }

    private function findOrCreateCategory(\PDO $db, string $category): int
    {
        if (preg_match('/^[1-9]\d*$/', $category) === 1) {
            return (int) $category;
        }
        $stmt = $db->prepare("SELECT category_id FROM {$this->table('categories')} WHERE title = :title LIMIT 1");
        $stmt->execute(['title' => $category]);
        $id = $stmt->fetchColumn();
        if (is_numeric($id)) {
            return (int) $id;
        }

        $dir = $this->resolveUniqueCategoryDir($db, $this->resolveInputDir(null, $category, 'category'));
        $stmt = $db->prepare("INSERT INTO {$this->table('categories')} (title, dir, status_id, added_date) VALUES (:title, :dir, 1, :added_date)");
        $stmt->execute(['title' => $category, 'dir' => $dir, 'added_date' => date('Y-m-d H:i:s')]);
        $id = (int) $db->lastInsertId();
        $this->writeAdminAuditLog($db, 100, $id, Constants::OBJECT_TYPE_CATEGORY);

        return $id;
    }

    /**
     * @return list<int>
     */
    private function getRelatedIds(\PDO $db, string $relationTable, string $idColumn, int $sourceId): array
    {
        $stmt = $db->prepare("SELECT DISTINCT {$idColumn} FROM {$this->table($relationTable)} WHERE content_source_id = :id");
        $stmt->execute(['id' => $sourceId]);

        $ids = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, int>
     */
    private function getSourceUsageCounts(\PDO $db, int $sourceId): array
    {
        return [
            'videos' => $this->countByColumn($db, 'videos', 'content_source_id', $sourceId),
            'albums' => $this->countByColumn($db, 'albums', 'content_source_id', $sourceId),
            'comments' => $this->countComments($db, $sourceId),
            'subscribers' => $this->countSubscriptions($db, $sourceId),
        ];
    }

    private function countByColumn(\PDO $db, string $table, string $column, int $id): int
    {
        $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table($table)} WHERE {$column} = :id");
        $stmt->execute(['id' => $id]);
        $count = $stmt->fetchColumn();

        return is_numeric($count) ? (int) $count : 0;
    }

    private function countComments(\PDO $db, int $sourceId): int
    {
        $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table('comments')} WHERE object_id = :id AND object_type_id = :type");
        $stmt->execute(['id' => $sourceId, 'type' => Constants::OBJECT_TYPE_CONTENT_SOURCE]);
        $count = $stmt->fetchColumn();

        return is_numeric($count) ? (int) $count : 0;
    }

    private function countSubscriptions(\PDO $db, int $sourceId): int
    {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM {$this->table('users_subscriptions')}
            WHERE subscribed_object_id = :id AND subscribed_object_type_id = :type
        ");
        $stmt->execute(['id' => $sourceId, 'type' => Constants::OBJECT_TYPE_CONTENT_SOURCE]);
        $count = $stmt->fetchColumn();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @param array<string, int> $usage
     * @return list<string>
     */
    private function formatUsageCounts(array $usage): array
    {
        $items = [];
        foreach ($usage as $label => $count) {
            if ($count > 0) {
                $items[] = ucfirst($label) . ': ' . $count;
            }
        }

        return $items;
    }

    private function validateSynonyms(\PDO $db, string $synonyms): bool
    {
        foreach ($this->splitList($synonyms) as $synonym) {
            $stmt = $db->prepare("SELECT content_source_id FROM {$this->table('content_sources')} WHERE title = :title LIMIT 1");
            $stmt->execute(['title' => $synonym]);
            if ($stmt->fetch() !== false) {
                $this->io()->error("Content source synonym duplicates an existing title: $synonym");
                return false;
            }
        }

        return true;
    }

    private function validateUrl(string $url): bool
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) !== false) {
            return true;
        }

        $this->io()->error('Invalid value for --url (use a valid URL or empty value)');
        return false;
    }

    private function sourceTitleExists(\PDO $db, string $title, ?int $excludeId): bool
    {
        $sql = "SELECT content_source_id FROM {$this->table('content_sources')} WHERE title = :title";
        $params = ['title' => $title];
        if ($excludeId !== null) {
            $sql .= ' AND content_source_id <> :id';
            $params['id'] = $excludeId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch() !== false;
    }

    /**
     * @param list<string> $updates
     * @param array<string, mixed> $params
     * @param list<string> $changedFields
     * @param array<string, mixed> $current
     */
    private function queueScalarUpdate(
        array &$updates,
        array &$params,
        array &$changedFields,
        array $current,
        string $field,
        string|int $value
    ): void {
        $currentValue = $this->stringValue($current[$field] ?? '');
        $newValue = (string) $value;
        if ($currentValue === $newValue) {
            return;
        }

        $updates[] = "{$field} = :{$field}";
        $params[$field] = $value;
        $changedFields[] = $field;
    }

    private function resolveInputDir(?string $dirInput, string $title, string $fallback): ?string
    {
        $source = $dirInput === null || $dirInput === '' ? $title : $dirInput;
        $dir = $this->slugify($source);
        if ($dir === '') {
            if ($dirInput !== null && $dirInput !== '') {
                $this->io()->error('The provided --dir does not contain any usable slug characters.');
                return null;
            }

            return $fallback;
        }

        return $dir;
    }

    private function slugify(string $value): string
    {
        $dir = preg_replace('/[^a-z0-9]+/', '-', strtolower($value));

        return trim((string) $dir, '-');
    }

    private function resolveUniqueSourceDir(\PDO $db, string $dir, ?int $excludeId): string
    {
        return $this->resolveUniqueDir($db, $dir, 'content_sources', 'content_source_id', $excludeId);
    }

    private function resolveUniqueGroupDir(\PDO $db, ?string $dir, ?int $excludeId): string
    {
        return $this->resolveUniqueDir($db, $dir ?? 'group', 'content_sources_groups', 'content_source_group_id', $excludeId);
    }

    private function resolveUniqueTagDir(\PDO $db, ?string $dir): string
    {
        return $this->resolveUniqueDir($db, $dir ?? 'tag', 'tags', 'tag_id', null, 'tag_dir');
    }

    private function resolveUniqueCategoryDir(\PDO $db, ?string $dir): string
    {
        return $this->resolveUniqueDir($db, $dir ?? 'category', 'categories', 'category_id', null);
    }

    private function resolveUniqueDir(
        \PDO $db,
        string $dir,
        string $table,
        string $idColumn,
        ?int $excludeId,
        string $dirColumn = 'dir'
    ): string {
        $candidate = $dir;
        $suffix = 2;
        while ($this->dirExists($db, $candidate, $table, $idColumn, $excludeId, $dirColumn)) {
            $candidate = $dir . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function dirExists(
        \PDO $db,
        string $dir,
        string $table,
        string $idColumn,
        ?int $excludeId,
        string $dirColumn
    ): bool {
        $sql = "SELECT {$idColumn} FROM {$this->table($table)} WHERE {$dirColumn} = :dir";
        $params = ['dir' => $dir];
        if ($excludeId !== null) {
            $sql .= " AND {$idColumn} <> :id";
            $params['id'] = $excludeId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch() !== false;
    }

    /**
     * @param array<string, mixed> $current
     * @return array<string, string>
     */
    private function collectCustomValues(InputInterface $input, array $current): array
    {
        $values = [];
        foreach (self::CUSTOM_FIELDS as $field) {
            $values[$field] = $this->getRawStringOption($input, $field) ?? $this->stringValue($current[$field] ?? '');
        }

        return $values;
    }

    /**
     * @return array{source: string, filename: string}|false|null
     */
    private function prepareUpload(InputInterface $input, string $option, string $prefix): array|false|null
    {
        $path = $this->getRawStringOption($input, $option);
        if ($path === null || $path === '') {
            return null;
        }

        if (!is_file($path) || !is_readable($path)) {
            $this->io()->error(sprintf('The --%s file does not exist or is not readable: %s', $option, $path));
            return false;
        }

        $isScreenshot = str_starts_with($option, 'screenshot');
        if ($isScreenshot) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($extension, $this->getAllowedImageExtensions(), true)) {
                $this->io()->error(sprintf(
                    'The --%s file extension is not allowed. Allowed extensions: %s',
                    $option,
                    implode(', ', $this->getAllowedImageExtensions())
                ));
                return false;
            }
            if (@getimagesize($path) === false) {
                $this->io()->error(sprintf('The --%s file is not a valid image: %s', $option, $path));
                return false;
            }
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $basename = pathinfo($path, PATHINFO_FILENAME);
        $safeName = $this->slugifyFileName($basename);
        if ($safeName === '') {
            $safeName = $option;
        }
        $filename = $extension !== '' ? "{$prefix}{$safeName}.{$extension}" : "{$prefix}{$safeName}";
        $realPath = realpath($path);

        return [
            'source' => $realPath !== false ? $realPath : $path,
            'filename' => $filename,
        ];
    }

    /**
     * @param array{source: string, filename: string} $upload
     * @return array{source: string, filename: string}
     */
    private function duplicateUpload(array $upload, string $prefix): array
    {
        $filename = preg_replace('/^s[12]_/', '', $upload['filename']) ?? $upload['filename'];

        return ['source' => $upload['source'], 'filename' => $prefix . $filename];
    }

    /**
     * @param list<array{source: string, filename: string}> $uploads
     * @return list<string>
     */
    private function installUploads(int $sourceId, array $uploads): array
    {
        $created = [];
        foreach ($uploads as $upload) {
            $target = $this->getSourceFilePath($sourceId, $upload['filename']);
            $this->ensureDirectory(dirname($target));
            if (!@copy($upload['source'], $target)) {
                throw new \RuntimeException(sprintf('Could not copy file to %s', $target));
            }
            @chmod($target, 0666);
            $created[] = $target;
        }

        return $created;
    }

    private function getSourceFilePath(int $sourceId, string $filename): string
    {
        $contentPath = $this->stringValue($this->config->get('content_path_content_sources', ''));
        if ($contentPath === '') {
            throw new \RuntimeException('KVS content source file path is not configured');
        }

        return rtrim($contentPath, '/') . '/' . $sourceId . '/' . $filename;
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!@mkdir($path, 0777, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Could not create directory %s', $path));
        }
        @chmod($path, 0777);
    }

    /**
     * @param list<string> $paths
     */
    private function removeFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if ($path === '' || !is_file($path)) {
                continue;
            }
            if (!@unlink($path) && is_file($path)) {
                throw new \RuntimeException(sprintf('Could not remove %s', $path));
            }
        }
    }

    private function removeEmptySourceDirectory(int $sourceId): void
    {
        $contentPath = $this->stringValue($this->config->get('content_path_content_sources', ''));
        if ($contentPath === '') {
            return;
        }
        $path = rtrim($contentPath, '/') . '/' . $sourceId;
        if (is_dir($path)) {
            @rmdir($path);
        }
    }

    private function deleteSourceFiles(int $sourceId): void
    {
        $contentPath = $this->stringValue($this->config->get('content_path_content_sources', ''));
        if ($contentPath === '') {
            return;
        }

        $path = rtrim($contentPath, '/') . '/' . $sourceId;
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $removed = $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            if (!$removed) {
                throw new \RuntimeException(sprintf('Could not remove %s', $item->getPathname()));
            }
        }
        if (!@rmdir($path) && is_dir($path)) {
            throw new \RuntimeException(sprintf('Could not remove directory %s', $path));
        }
    }

    /**
     * @return list<string>
     */
    private function getAllowedImageExtensions(): array
    {
        $configured = $this->config->get('image_allowed_ext', 'jpg,jpeg,png,gif,webp');
        $extensions = is_string($configured) ? explode(',', $configured) : ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        $normalized = array_map(static fn (string $extension): string => strtolower(trim($extension)), $extensions);

        return array_values(array_unique(array_filter($normalized, static fn (string $extension): bool => $extension !== '')));
    }

    private function shouldAutoCreateSecondScreenshot(\PDO $db): bool
    {
        return $this->getKvsOption($db, 'CS_SCREENSHOT_OPTION') === '1';
    }

    private function getKvsOption(\PDO $db, string $name): ?string
    {
        try {
            $stmt = $db->prepare("SELECT value FROM {$this->table('options')} WHERE variable = :variable LIMIT 1");
            $stmt->execute(['variable' => $name]);
            $value = $stmt->fetchColumn();
        } catch (\Exception) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function removeSourceCache(int $sourceId): void
    {
        $projectPath = $this->stringValue($this->config->get('project_path', $this->config->getKvsPath()));
        if ($projectPath === '') {
            return;
        }
        $hash = md5((string) $sourceId);
        $cacheFile = rtrim($projectPath, '/') . "/admin/data/engine/content_sources_info/{$hash[0]}{$hash[1]}/{$hash}.dat";
        if (is_file($cacheFile)) {
            @unlink($cacheFile);
        }
    }

    /**
     * @return list<int>
     */
    private function getCommentUserIds(\PDO $db, int $sourceId): array
    {
        $stmt = $db->prepare("
            SELECT DISTINCT user_id
            FROM {$this->table('comments')}
            WHERE object_id = :id AND object_type_id = :type
        ");
        $stmt->execute(['id' => $sourceId, 'type' => Constants::OBJECT_TYPE_CONTENT_SOURCE]);

        $ids = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }

    /**
     * @param list<int> $tagIds
     */
    private function recountRelatedTags(\PDO $db, array $tagIds): void
    {
        foreach (array_values(array_unique($tagIds)) as $tagId) {
            $this->safeExecute(
                $db,
                "UPDATE {$this->table('tags')}
                 SET total_content_sources = (
                    SELECT COUNT(*) FROM {$this->table('tags_content_sources')} WHERE tag_id = :id
                 )
                 WHERE tag_id = :id",
                ['id' => $tagId]
            );
        }
    }

    /**
     * @param list<int> $categoryIds
     */
    private function recountRelatedCategories(\PDO $db, array $categoryIds): void
    {
        foreach (array_values(array_unique($categoryIds)) as $categoryId) {
            $this->safeExecute(
                $db,
                "UPDATE {$this->table('categories')}
                 SET total_content_sources = (
                    SELECT COUNT(*) FROM {$this->table('categories_content_sources')} WHERE category_id = :id
                 )
                 WHERE category_id = :id",
                ['id' => $categoryId]
            );
        }
    }

    /**
     * @param list<int> $userIds
     */
    private function recountCommentUsers(\PDO $db, array $userIds): void
    {
        foreach (array_values(array_unique($userIds)) as $userId) {
            $this->safeExecute(
                $db,
                "UPDATE {$this->table('users')}
                 SET comments_cs_count = (
                    SELECT COUNT(*) FROM {$this->table('comments')}
                    WHERE user_id = :id AND is_approved = 1 AND object_type_id = " . Constants::OBJECT_TYPE_CONTENT_SOURCE . "
                 ),
                 comments_total_count = (
                    SELECT COUNT(*) FROM {$this->table('comments')}
                    WHERE user_id = :id AND is_approved = 1
                 )
                 WHERE user_id = :id",
                ['id' => $userId]
            );
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function safeExecute(\PDO $db, string $sql, array $params): void
    {
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
        } catch (\PDOException) {
            // Optional KVS tables and counters may be absent in hermetic tests.
        }
    }

    /**
     * @param array<string, mixed> $source
     */
    private function formatSourceGroup(array $source): string
    {
        $groupId = is_numeric($source['content_source_group_id'] ?? null) ? (int) $source['content_source_group_id'] : 0;
        if ($groupId <= 0) {
            return 'None';
        }

        $title = $this->stringValue($source['content_source_group'] ?? '');
        return $title !== '' ? "{$title} (#{$groupId})" : "#{$groupId}";
    }

    private function getRawStringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);
        if ($value === null || $value === false) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function getRawStringOptionOrDefault(InputInterface $input, string $name, string $default): string
    {
        return $this->getRawStringOption($input, $name) ?? $default;
    }

    private function slugifyFileName(string $name): string
    {
        $slug = preg_replace('/[^a-z0-9._-]+/', '-', strtolower($name));

        return trim((string) $slug, '-_.');
    }

    private function relaxSqlMode(\PDO $db): void
    {
        try {
            $db->exec("SET @old_sql_mode = @@sql_mode, sql_mode = ''");
        } catch (\PDOException) {
            // Non-MySQL drivers (e.g. SQLite in tests) do not support sql_mode.
        }
    }

    private function restoreSqlMode(\PDO $db): void
    {
        try {
            $db->exec("SET sql_mode = @old_sql_mode");
        } catch (\PDOException) {
            // Non-MySQL drivers (e.g. SQLite in tests) do not support sql_mode.
        }
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
