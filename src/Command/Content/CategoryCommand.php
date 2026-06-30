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
    name: 'content:category',
    description: 'Manage KVS categories',
    aliases: ['category', 'categories', 'cat']
)]
class CategoryCommand extends BaseCommand
{
    use RelationUsageTrait;
    use ToggleStatusTrait;

    /** @var list<string> */
    private const SHOW_UNSUPPORTED_OPTIONS = [
        'title',
        'description',
        'group',
        'parent',
        'status',
        'search',
        'unused',
        'usage',
        'field-filter',
        'dry-run',
        'limit',
    ];

    /** @var list<string> */
    private const TREE_UNSUPPORTED_OPTIONS = [
        'title',
        'description',
        'group',
        'parent',
        'status',
        'search',
        'unused',
        'usage',
        'field-filter',
        'dry-run',
        'limit',
    ];

    /** @var array<string, string> */
    private const CATEGORY_RELATION_TABLES = [
        'videos' => 'video_id',
        'content_sources' => 'content_source_id',
        'albums' => 'album_id',
        'posts' => 'post_id',
        'playlists' => 'playlist_id',
        'dvds' => 'dvd_id',
        'dvds_groups' => 'dvd_group_id',
        'models' => 'model_id',
    ];

    /** @var list<string> */
    private const CATEGORY_FIELD_FILTER_COLUMNS = [
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
    ];

    protected function configure(): void
    {
        $this
            ->setHelp(<<<'HELP'
Manage KVS categories with full CRUD operations.

<info>ACTIONS:</info>
  list              List all categories (default)
  tree              Show category hierarchy tree
  show <id>         Show category details
  create <title>    Create new category
  delete <id>       Delete category
  update <id>       Update category properties
  enable <id>       Enable category
  disable <id>      Disable category
  merge <id> <target>           Merge source category into target
  assign-group <group> <ids>    Bulk-assign categories to a group

<info>EXAMPLES:</info>
  <comment>kvs category list</comment>
  <comment>kvs category list --search=Canada</comment>
  <comment>kvs category list --group=0 --unused</comment>
  <comment>kvs category tree</comment>
  <comment>kvs category create "New Category" --group=5</comment>
  <comment>kvs category update 3 --title="Renamed" --status=inactive</comment>
  <comment>kvs category merge 12 15</comment>
  <comment>kvs category assign-group 5 12,15,18</comment>
  <comment>kvs category assign-group 5 12 15 18 --dry-run</comment>
  <comment>kvs category delete 3</comment>
  <comment>kvs category enable 2</comment>
  <comment>kvs category disable 2</comment>
HELP
            )
            ->addArgument(
                'action',
                InputArgument::OPTIONAL,
                'Action: list, tree, show, create, delete, update, enable, disable, merge, assign-group',
                'list'
            )
            ->addArgument('id', InputArgument::OPTIONAL, 'Category ID, title, source ID, or group ID')
            ->addArgument('values', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Target category ID or category IDs')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Category title')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Category description')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'Category group ID or title')
            ->addOption('parent', null, InputOption::VALUE_REQUIRED, 'Deprecated alias for --group')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Status (active|inactive)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results to show', Constants::DEFAULT_LIMIT)
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search in category titles, directories, descriptions, and synonyms')
            ->addOption('unused', null, InputOption::VALUE_NONE, 'Show only unused categories')
            ->addOption('usage', null, InputOption::VALUE_REQUIRED, 'KVS admin usage filter (e.g. used/videos)')
            ->addOption('field-filter', null, InputOption::VALUE_REQUIRED, 'KVS admin field filter (e.g. filled/description)')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to display')
            ->addOption('field', null, InputOption::VALUE_REQUIRED, 'Display single field from each item')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count, ids', 'table')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview assign-group changes without writing')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation of long text fields');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $this->getStringArgument($input, 'action') ?? 'list';
        $id = $this->getStringArgument($input, 'id');
        $values = $this->getStringArrayArgument($input, 'values');

        return match ($action) {
            'list' => $this->listCategories($input),
            'tree' => $this->showTree($input),
            'show' => $this->showCategory($id, $input),
            'create' => $this->createCategory($input),
            'delete' => $this->deleteCategory($id, $input),
            'update' => $this->updateCategory($id, $input),
            'enable' => $this->toggleStatus($id, 1),
            'disable' => $this->toggleStatus($id, 0),
            'merge' => $this->mergeCategory($id, $values[0] ?? null, $input),
            'assign-group' => $this->assignCategoriesToGroup($id, $values, $input),
            default => $this->failUnknownAction(
                'category',
                $action,
                ['list', 'tree', 'show', 'create', 'delete', 'update', 'enable', 'disable', 'merge', 'assign-group']
            ),
        };
    }

    private function listCategories(InputInterface $input): int
    {
        if ($this->rejectUnsupportedArgument($input, 'list', 'id', 'a category ID or title argument', 'show', 'a specific category')) {
            return self::FAILURE;
        }

        if ($this->getStringOption($input, 'group') !== null && $this->getStringOption($input, 'parent') !== null) {
            $this->io()->error('Options --group and --parent cannot be used together');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $conditions = [];
            $params = [];

            $statusId = $this->parseStatusFilterOrFail($input, [
                'active' => StatusFormatter::CATEGORY_ACTIVE,
                'inactive' => StatusFormatter::CATEGORY_INACTIVE,
                'disabled' => StatusFormatter::CATEGORY_INACTIVE,
            ]);
            if ($statusId === false) {
                return self::FAILURE;
            }
            if ($statusId !== null) {
                $conditions[] = 'c.status_id = :status';
                $params['status'] = $statusId;
            }

            $search = $this->getStringOption($input, 'search');
            if ($search !== null) {
                $conditions[] = $this->buildAdminSearchCondition(
                    'c.category_id',
                    [
                        'c.title',
                        'c.dir',
                        'c.description',
                        'c.synonyms',
                    ],
                    $search,
                    $params
                );
            }

            $groupId = $this->resolveCategoryGroupFilter($db, $input);
            if ($groupId === false) {
                return self::FAILURE;
            }
            if ($groupId !== null) {
                $conditions[] = 'c.category_group_id = :group';
                $params['group'] = $groupId;
            }

            $fieldFilter = $this->getStringOption($input, 'field-filter');
            if ($fieldFilter !== null) {
                $condition = $this->getCategoryFieldFilterCondition($fieldFilter);
                if ($condition === null) {
                    $this->io()->error(
                        'Invalid category field filter. Use: ' . implode(', ', $this->getCategoryFieldFilterValues())
                    );
                    return self::FAILURE;
                }
                $conditions[] = $condition;
            }

            $usageJoins = $this->getCategoryUsageJoins();
            $unusedOnly = $this->getBoolOption($input, 'unused');
            $usage = $this->getStringOption($input, 'usage');

            if ($unusedOnly && $usage !== null) {
                $this->io()->error('Options --unused and --usage cannot be used together');
                return self::FAILURE;
            }

            if ($usage !== null) {
                $usageCondition = $this->getCategoryUsageFilterCondition($usage);
                if ($usageCondition === null) {
                    $this->io()->error(
                        'Invalid category usage filter. Use: ' . implode(', ', $this->getAdminUsageFilterValues())
                    );
                    return self::FAILURE;
                }
                $conditions[] = $usageCondition;
            }

            if ($unusedOnly) {
                $conditions[] = $this->getCategoryTotalUsageCondition() . ' = 0';
            }

            $whereClause = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
            if ($this->getStringOptionOrDefault($input, 'format', 'table') === 'count') {
                if ($this->rejectFieldSelectionForCountFormat($input)) {
                    return self::FAILURE;
                }
                if ($this->getPositiveIntOptionOrDefault($input, 'limit', Constants::DEFAULT_LIMIT) === null) {
                    return self::FAILURE;
                }
                return $this->countCategories($db, $whereClause, $params, ($unusedOnly || $usage !== null) ? $usageJoins : '');
            }

            $limit = $this->getPositiveIntOptionOrDefault($input, 'limit', Constants::DEFAULT_LIMIT);
            if ($limit === null) {
                return self::FAILURE;
            }
            $usageSelectors = $this->getCategoryUsageSelectors();
            $includeGroupField = $this->isCategoryFieldRequested($input, 'category_group');
            $groupSelect = $includeGroupField ? ', cg.title as category_group' : '';
            $groupJoin = $includeGroupField
                ? "LEFT JOIN {$this->table('categories_groups')} cg ON cg.category_group_id = c.category_group_id"
                : '';

            $sql = "
                SELECT c.*$groupSelect,
                       {$usageSelectors}
                FROM {$this->table('categories')} c
                {$groupJoin}
                {$usageJoins}
                $whereClause
                ORDER BY c.category_id DESC
                LIMIT :limit
            ";

            $stmt = $db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $knownFields = array_merge(
                $this->getStatementColumnNames($stmt),
                [
                    'id',
                    'video_count',
                    'album_count',
                    'total_usage',
                    'status',
                    'thumb',
                    'videos_amount',
                    'albums_amount',
                    'posts_amount',
                    'other_amount',
                    'all_amount',
                    'videos',
                    'albums',
                    'posts',
                    'playlists',
                    'content_sources',
                    'models',
                    'dvds',
                    'dvds_groups',
                    'category_group',
                ]
            );

            /** @var list<array<string, mixed>> $categories */
            $categories = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $categories = array_map(function (array $category): array {
                $counts = $this->extractCategoryUsageCounts($category);
                $statusIdVal = $category['status_id'] ?? 0;
                $statusId = is_numeric($statusIdVal) ? (int) $statusIdVal : 0;
                $otherAmount = $this->getCategoryStoredOtherAmount($category);
                $allAmount = $counts['videos'] + $counts['albums'] + $counts['posts'] + $otherAmount;
                $category['id'] = $category['category_id'] ?? 0;
                $category['video_count'] = $counts['videos'];
                $category['album_count'] = $counts['albums'];
                $category['total_usage'] = $allAmount;
                $category['status'] = StatusFormatter::category($statusId, false);
                $category['thumb'] = $category['screenshot1'] ?? $category['screenshot2'] ?? '';
                $category['videos_amount'] = $counts['videos'];
                $category['albums_amount'] = $counts['albums'];
                $category['posts_amount'] = $counts['posts'];
                $category['other_amount'] = $otherAmount;
                $category['all_amount'] = $allAmount;

                return [
                    ...$category,
                    ...$counts,
                ];
            }, $categories);

            // Format and display output using centralized Formatter
            $formatter = new Formatter(
                $input->getOptions(),
                ['category_id', 'title', 'video_count', 'album_count', 'total_usage', 'status'],
                $knownFields
            );
            $formatter->display($categories, $this->io());

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch categories: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function countCategories(\PDO $db, string $whereClause, array $params, string $usageJoins): int
    {
        try {
            $stmt = $db->prepare("
                SELECT COUNT(*)
                FROM {$this->table('categories')} c
                {$usageJoins}
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
            $this->io()->error('Failed to count categories: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function resolveCategoryGroupFilter(\PDO $db, InputInterface $input): int|false|null
    {
        $group = $this->getStringOption($input, 'group');
        if ($group === null) {
            $group = $this->getStringOption($input, 'parent');
        }
        if ($group === null) {
            return null;
        }

        $group = trim($group);
        if ($group === '') {
            $this->io()->error('Invalid value for --group (use: integer >= 0 or title)');
            return false;
        }

        if (preg_match('/^\d+$/', $group) === 1) {
            return (int) $group;
        }

        if (preg_match('/^-?\d+(?:\.\d+)?$/', $group) === 1) {
            $this->io()->error('Invalid value for --group (use: integer >= 0 or title)');
            return false;
        }

        return $this->findReferenceIdByText(
            $db,
            'categories_groups',
            'category_group_id',
            'title',
            $group
        ) ?? -1;
    }

    private function showTree(InputInterface $input): int
    {
        if ($this->rejectUnsupportedArgument($input, 'tree', 'id', 'a category ID or title argument', 'show', 'a specific category')) {
            return self::FAILURE;
        }

        if ($this->rejectUnsupportedOptionsForAction($input, 'tree', self::TREE_UNSUPPORTED_OPTIONS)) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->query("
                SELECT c.*,
                       (SELECT COUNT(*) FROM {$this->table('categories')}_videos WHERE category_id = c.category_id) as video_count
                FROM {$this->table('categories')} c
                ORDER BY category_group_id, title
            ");
            if ($stmt === false) {
                $this->io()->error('Failed to execute query');
                return self::FAILURE;
            }
            /** @var list<array<string, mixed>> $categories */
            $categories = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if ($this->shouldUseFormattedRows($input)) {
                $categories = array_map(function (array $category): array {
                    $statusId = is_numeric($category['status_id'] ?? null) ? (int) $category['status_id'] : 0;
                    $category['id'] = $category['category_id'] ?? 0;
                    $category['status_id'] = $statusId;
                    $category['status'] = StatusFormatter::category($statusId, false);
                    return $category;
                }, $categories);

                return $this->displayFormattedRows(
                    $input,
                    $categories,
                    ['category_id', 'title', 'category_group_id', 'video_count', 'status']
                );
            }

            $this->io()->section('Category Tree');

            foreach ($categories as $cat) {
                $groupIdVal = $cat['category_group_id'] ?? 0;
                $prefix = (is_numeric($groupIdVal) && (int) $groupIdVal > 0) ? '  └─ ' : '';
                $videoCountVal = $cat['video_count'] ?? 0;
                $videoCount = is_numeric($videoCountVal) ? (int) $videoCountVal : 0;
                $count = " ({$videoCount} videos)";
                $statusIdVal = $cat['status_id'] ?? 0;
                $statusId = is_numeric($statusIdVal) ? (int) $statusIdVal : 0;
                $status = $statusId === StatusFormatter::CATEGORY_ACTIVE ? '' : ' <fg=yellow>[Inactive]</>';
                $title = isset($cat['title']) && is_string($cat['title']) ? $cat['title'] : '';
                $this->io()->text($prefix . $title . $count . $status);
            }
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch categories: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function showCategory(?string $id, InputInterface $input): int
    {
        if ($id === null || $id === '') {
            $this->io()->error('Category ID or title is required');
            return self::FAILURE;
        }

        if ($this->rejectUnsupportedOptionsForAction($input, 'show', self::SHOW_UNSUPPORTED_OPTIONS)) {
            return self::FAILURE;
        }
        if ($this->rejectCountFormatForSingularAction($input, 'show')) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $whereClause = ctype_digit($id) ? 'c.category_id = :identifier_id' : 'c.title = :identifier_title';
            $stmt = $db->prepare("
                SELECT c.*, cg.title as category_group
                FROM {$this->table('categories')} c
                LEFT JOIN {$this->table('categories_groups')} cg ON c.category_group_id = cg.category_group_id
                WHERE {$whereClause}
            ");
            $stmt->execute(ctype_digit($id) ? ['identifier_id' => (int) $id] : ['identifier_title' => $id]);
            /** @var array<string, mixed>|false $category */
            $category = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($category === false) {
                $this->io()->error("Category not found: $id");
                return self::FAILURE;
            }

            $titleValue = $category['title'] ?? '';
            $categoryTitle = is_string($titleValue) ? $titleValue : (is_scalar($titleValue) ? (string) $titleValue : '');

            $categoryId = $category['category_id'] ?? 0;
            $categoryIdString = is_scalar($categoryId) ? (string) $categoryId : '0';
            $usage = $this->getCategoryUsageCounts($db, $categoryIdString);
            $videoCount = $usage['videos'] ?? 0;
            $albumCount = $usage['albums'] ?? 0;
            $postCount = $usage['posts'] ?? 0;
            $otherCount = $this->getCategoryStoredOtherAmount($category);
            $totalUsage = $videoCount + $albumCount + $postCount + $otherCount;

            $groupId = $category['category_group_id'] ?? 0;
            $groupIdInt = is_numeric($groupId) ? (int) $groupId : 0;
            $groupIdStr = $groupIdInt > 0 ? (string) $groupIdInt : 'None (Root)';
            $groupTitle = $category['category_group'] ?? null;
            $groupDisplay = $groupIdInt > 0
                ? ((is_scalar($groupTitle) && (string) $groupTitle !== '') ? (string) $groupTitle . " (#$groupIdInt)" : "#$groupIdInt")
                : 'None (Root)';
            $addedDate = $category['added_date'] ?? null;
            $addedDateStr = is_string($addedDate) ? $addedDate : 'N/A';

            $statusId = isset($category['status_id']) && is_numeric($category['status_id']) ? (int) $category['status_id'] : 0;

            $info = [
                ['ID', is_scalar($categoryId) ? (string) $categoryId : '0'],
                ['Title', $categoryTitle],
                ['Group', $groupDisplay],
                ['Group ID', $groupIdStr],
                ['Status', StatusFormatter::category($statusId)],
                ['Videos', (string) $videoCount],
                ['Albums', (string) $albumCount],
                ['Posts', (string) $postCount],
                ['Other', (string) $otherCount],
                ['Total Usage', (string) $totalUsage],
                ['Added', $addedDateStr],
            ];

            $description = $category['description'] ?? null;
            if ($this->shouldUseFormattedRows($input)) {
                $extra = $this->getRequestedCategoryDetailFields(
                    $input,
                    $category,
                    is_scalar($categoryId) ? (string) $categoryId : '0',
                    $statusId,
                    [
                        'videos' => $videoCount,
                        'albums' => $albumCount,
                        'posts' => $postCount,
                    ],
                    $otherCount,
                    $totalUsage
                );
                if ($description !== null && $description !== '' && is_scalar($description)) {
                    $extra['description'] = (string) $description;
                }
                return $this->displayDetailRows($input, $info, $extra);
            }

            $this->io()->section("Category: $categoryTitle");
            $this->renderTable(['Property', 'Value'], $info);

            if ($description !== null && $description !== '' && is_scalar($description)) {
                $this->io()->section('Description');
                $this->io()->text((string) $description);
            }
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch category: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $category
     * @param array{videos: int, albums: int, posts: int} $counts
     * @return array<string, mixed>
     */
    private function getRequestedCategoryDetailFields(
        InputInterface $input,
        array $category,
        string $categoryId,
        int $statusId,
        array $counts,
        int $otherAmount,
        int $totalUsage
    ): array {
        $fields = [
            'category_id' => $categoryId,
            'dir' => $this->getCategoryStringField($category, 'dir'),
            'description' => $this->getCategoryStringField($category, 'description'),
            'status_id' => $statusId,
            'category_group' => $this->getCategoryStringField($category, 'category_group'),
            'category_group_id' => $this->getCategoryScalarField($category, 'category_group_id'),
            'videos_amount' => $counts['videos'],
            'albums_amount' => $counts['albums'],
            'posts_amount' => $counts['posts'],
            'other_amount' => $otherAmount,
            'all_amount' => $totalUsage,
            'added_date' => $this->getCategoryStringField($category, 'added_date'),
            'sort_id' => $this->getCategoryScalarField($category, 'sort_id'),
        ];

        foreach (
            [
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
            ] as $field
        ) {
            $fields[$field] = $this->getCategoryScalarField($category, $field);
        }

        return $this->getRequestedDetailFields($input, $fields);
    }

    /**
     * @param array<string, mixed> $category
     */
    private function getCategoryStringField(array $category, string $field): string
    {
        $value = $category[$field] ?? '';
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $category
     */
    private function getCategoryScalarField(array $category, string $field): int|float|string|null
    {
        if (!array_key_exists($field, $category) || $category[$field] === null) {
            return null;
        }

        if (!is_scalar($category[$field])) {
            return null;
        }

        if (is_numeric($category[$field])) {
            return str_contains((string) $category[$field], '.') ? (float) $category[$field] : (int) $category[$field];
        }

        return (string) $category[$field];
    }

    private function createCategory(InputInterface $input): int
    {
        $titleOption = $this->getStringOption($input, 'title');
        $idArg = $this->getStringArgument($input, 'id');
        $title = $titleOption ?? $idArg;

        if ($title === null) {
            $this->io()->error('Category title is required');
            $this->io()->text('Usage: kvs content:category create "Category Name"');
            $this->io()->text('   or: kvs content:category create --title="Category Name" --description="..." --group=5');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            // Check if category already exists
            $stmt = $db->prepare("SELECT category_id FROM {$this->table('categories')} WHERE title = :title");
            $stmt->execute(['title' => $title]);

            if ($stmt->fetch() !== false) {
                $this->io()->error("Category already exists: $title");
                return self::FAILURE;
            }

            // Prepare data
            $description = $this->getStringOption($input, 'description') ?? '';
            $groupId = $this->getCategoryGroupInput($input);
            $statusId = StatusFormatter::CATEGORY_ACTIVE;

            // KVS category_group_id points to categories_groups, not another category.
            if ($groupId !== null && $groupId !== '') {
                $stmt = $db->prepare("SELECT category_group_id FROM {$this->table('categories_groups')} WHERE category_group_id = :id");
                $stmt->execute(['id' => $groupId]);
                if ($stmt->fetch() === false) {
                    $this->io()->error("Category group not found: $groupId");
                    return self::FAILURE;
                }
            }

            $dir = $this->getUniqueCategoryDir($db, $this->slugifyCategoryDir($title));
            $now = date('Y-m-d H:i:s');

            // Relax sql_mode for INSERT (KVS tables have many NOT NULL without DEFAULT)
            $restoreSqlMode = $this->relaxSqlMode($db);
            try {
                $table = $this->table('categories');
                $stmt = $db->prepare("
                    INSERT INTO {$table}
                        (title, dir, description, synonyms, category_group_id,
                         status_id, added_date, last_content_date)
                    VALUES
                        (:title, :dir, :description, '',
                         :category_group_id, :status_id, :added_date, :last_content_date)
                ");

                $stmt->execute([
                    'title' => $title,
                    'dir' => $dir,
                    'description' => $description,
                    'category_group_id' => $groupId !== null && $groupId !== '' ? (int) $groupId : 0,
                    'status_id' => $statusId,
                    'added_date' => $now,
                    'last_content_date' => $now,
                ]);

                $categoryId = $db->lastInsertId();
            } finally {
                $this->restoreSqlMode($db, $restoreSqlMode);
            }

            $this->io()->success("Category created successfully!");
            $this->renderTable(
                ['Property', 'Value'],
                [
                    ['ID', (string) $categoryId],
                    ['Title', $title],
                    ['Group ID', $groupId ?? 'None'],
                    ['Description', $description !== '' ? $description : 'None'],
                    ['Status', 'Active'],
                ]
            );
        } catch (\Exception $e) {
            $this->io()->error('Failed to create category: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function deleteCategory(?string $id, InputInterface $input): int
    {
        if ($id === null || $id === '') {
            $this->io()->error('Category ID is required');
            $this->io()->text('Usage: kvs content:category delete <category_id>');
            return self::FAILURE;
        }
        if (!ctype_digit($id)) {
            $this->io()->error('Category ID must be numeric');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            // Get category details
            $stmt = $db->prepare("SELECT * FROM {$this->table('categories')} WHERE category_id = :id");
            $stmt->execute(['id' => $id]);
            $category = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($category)) {
                $this->io()->error("Category not found: $id");
                return self::FAILURE;
            }

            $usage = $this->getCategoryUsageCounts($db, $id);
            $totalUsage = array_sum($usage);

            if ($totalUsage > 0) {
                $this->io()->warning("This category is used by $totalUsage items:");
                $this->io()->listing($this->formatUsageCounts($usage));

                if ($this->io()->confirm('Delete anyway? This will remove all associations.', false) !== true) {
                    if (!$input->isInteractive()) {
                        $this->io()->error('Category deletion cancelled because confirmation was not provided.');
                        return self::FAILURE;
                    }

                    $this->io()->info('Operation cancelled');
                    return self::SUCCESS;
                }
            }

            $this->deleteCategoryFiles($id);
            $this->writeAdminAuditLog($db, 180, (int) $id, Constants::OBJECT_TYPE_CATEGORY);

            $stmt = $db->prepare("DELETE FROM {$this->table('categories')} WHERE category_id = :id");
            $stmt->execute(['id' => $id]);
            $this->deleteCategoryRelations($db, $id);
            $this->resetCategoryReferences($db, $id);

            $titleValue = $category['title'] ?? '';
            $deletedTitle = is_string($titleValue) ? $titleValue : (is_scalar($titleValue) ? (string) $titleValue : '');
            $this->io()->success("Category '$deletedTitle' deleted successfully!");
        } catch (\Exception $e) {
            $this->io()->error('Failed to delete category: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, int>
     */
    private function getCategoryUsageCounts(\PDO $db, string $categoryId): array
    {
        return $this->getRelationUsageCounts(
            $db,
            'categories',
            'category_id',
            $categoryId,
            self::CATEGORY_RELATION_TABLES
        );
    }

    private function getCategoryUsageSelectors(): string
    {
        $selectors = [];
        foreach (array_keys(self::CATEGORY_RELATION_TABLES) as $suffix) {
            $selectors[] = sprintf(
                'COALESCE(%s.usage_count, 0) as %s',
                $this->getCategoryUsageJoinAlias($suffix),
                $this->getRelationUsageAlias($suffix)
            );
        }

        return implode(",\n                       ", $selectors);
    }

    private function getCategoryUsageJoins(): string
    {
        $joins = [];
        foreach (array_keys(self::CATEGORY_RELATION_TABLES) as $suffix) {
            $table = $this->table('categories') . '_' . $suffix;
            $alias = $this->getCategoryUsageJoinAlias($suffix);
            $joins[] = sprintf(
                'LEFT JOIN (SELECT category_id, COUNT(*) as usage_count FROM %s GROUP BY category_id) %s ' .
                'ON %s.category_id = c.category_id',
                $table,
                $alias,
                $alias
            );
        }

        return implode("\n                ", $joins);
    }

    private function getCategoryTotalUsageCondition(): string
    {
        return implode(' + ', [
            $this->getCategoryUsageJoinExpression('videos'),
            $this->getCategoryUsageJoinExpression('albums'),
            $this->getCategoryUsageJoinExpression('posts'),
            $this->getCategoryStoredOtherAmountExpression(),
        ]);
    }

    private function getCategoryUsageFilterCondition(string $usage): ?string
    {
        return $this->getAdminUsageFilterCondition(
            $usage,
            $this->getCategoryUsageJoinExpression('videos'),
            $this->getCategoryUsageJoinExpression('albums'),
            $this->getCategoryUsageJoinExpression('posts'),
            $this->getCategoryStoredOtherAmountExpression(),
            $this->getCategoryTotalUsageCondition()
        );
    }

    private function getCategoryUsageJoinExpression(string $suffix): string
    {
        return sprintf('COALESCE(%s.usage_count, 0)', $this->getCategoryUsageJoinAlias($suffix));
    }

    private function getCategoryStoredOtherAmountExpression(): string
    {
        return '(COALESCE(c.total_content_sources, 0) + COALESCE(c.total_playlists, 0) + '
            . 'COALESCE(c.total_models, 0) + COALESCE(c.total_dvds, 0) + COALESCE(c.total_dvd_groups, 0))';
    }

    private function getCategoryUsageJoinAlias(string $suffix): string
    {
        return 'category_' . $suffix . '_usage';
    }

    /**
     * @return array<string, string>
     */
    private function getCategoryUsageLabels(): array
    {
        return $this->getRelationUsageLabels(self::CATEGORY_RELATION_TABLES);
    }

    /**
     * @param array<string, mixed> $category
     * @return array<string, int>
     */
    private function extractCategoryUsageCounts(array $category): array
    {
        return $this->extractRelationUsageCounts($category, self::CATEGORY_RELATION_TABLES);
    }

    /** @return list<string> */
    private function getCategoryFieldFilterValues(): array
    {
        $values = [];
        foreach (['empty', 'filled'] as $prefix) {
            foreach (self::CATEGORY_FIELD_FILTER_COLUMNS as $column) {
                $values[] = "{$prefix}/{$column}";
            }
            $values[] = "{$prefix}/group";
        }

        return $values;
    }

    private function getCategoryFieldFilterCondition(string $fieldFilter): ?string
    {
        $parts = explode('/', $fieldFilter, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$state, $field] = $parts;
        if (!in_array($state, ['empty', 'filled'], true)) {
            return null;
        }

        if ($field === 'group') {
            return $state === 'empty' ? 'c.category_group_id = 0' : 'c.category_group_id != 0';
        }

        if (!in_array($field, self::CATEGORY_FIELD_FILTER_COLUMNS, true)) {
            return null;
        }

        return $state === 'empty' ? "c.{$field} = ''" : "c.{$field} != ''";
    }

    /**
     * @param array<string, int> $usage
     * @return list<string>
     */
    private function formatUsageCounts(array $usage): array
    {
        $lines = [];
        $labels = $this->getCategoryUsageLabels();
        foreach ($usage as $suffix => $count) {
            $label = $labels[$suffix] ?? $suffix;
            $lines[] = "$label: $count";
        }

        return $lines;
    }

    private function deleteCategoryRelations(\PDO $db, string $categoryId): void
    {
        foreach (array_keys(self::CATEGORY_RELATION_TABLES) as $suffix) {
            $table = $this->table('categories') . '_' . $suffix;
            $db->prepare("DELETE FROM {$table} WHERE category_id = :id")->execute(['id' => $categoryId]);
        }
    }

    private function mergeCategory(?string $sourceId, ?string $targetId, InputInterface $input): int
    {
        if ($sourceId === null || $sourceId === '' || $targetId === null || $targetId === '') {
            $this->io()->error('Both source and target category IDs are required');
            $this->io()->text('Usage: kvs content:category merge <source_category_id> <target_category_id>');
            return self::FAILURE;
        }
        if (!ctype_digit($sourceId) || !ctype_digit($targetId)) {
            $this->io()->error('Source and target category IDs must be numeric');
            return self::FAILURE;
        }
        if ($sourceId === $targetId) {
            $this->io()->error('Source and target categories must be different');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("
                SELECT category_id, title, category_group_id
                FROM {$this->table('categories')}
                WHERE category_id IN (:source, :target)
            ");
            $stmt->execute(['source' => $sourceId, 'target' => $targetId]);
            /** @var list<array<string, mixed>> $categories */
            $categories = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (count($categories) !== 2) {
                $this->io()->error('One or both categories not found');
                return self::FAILURE;
            }

            $sourceCategory = null;
            $targetCategory = null;
            foreach ($categories as $category) {
                $categoryId = $category['category_id'] ?? null;
                $categoryIdString = is_scalar($categoryId) ? (string) $categoryId : '';
                if ($categoryIdString === $sourceId) {
                    $sourceCategory = $category;
                }
                if ($categoryIdString === $targetId) {
                    $targetCategory = $category;
                }
            }

            if ($sourceCategory === null || $targetCategory === null) {
                $this->io()->error('One or both categories not found');
                return self::FAILURE;
            }

            $sourceTitle = $this->stringValue($sourceCategory['title'] ?? '');
            $targetTitle = $this->stringValue($targetCategory['title'] ?? '');

            $this->io()->section('Merge Operation');
            $this->io()->text("Source: $sourceTitle (ID: $sourceId)");
            $this->io()->text("Target: $targetTitle (ID: $targetId)");
            $this->io()->newLine();
            $this->io()->warning('All associations will be moved to the target category, then source category will be deleted.');

            if ($this->io()->confirm('Continue with merge?', false) !== true) {
                if (!$input->isInteractive()) {
                    $this->io()->error('Category merge cancelled because confirmation was not provided.');
                    return self::FAILURE;
                }

                $this->io()->info('Operation cancelled');
                return self::SUCCESS;
            }

            $db->beginTransaction();

            $this->mergeCategoryRelations($db, $sourceId, $targetId);
            $this->moveCategoryReferences($db, $sourceId, $targetId);
            // TODO: Replace 180 if KVS exposes a merge-specific audit action.
            $this->writeAdminAuditLog(
                $db,
                180,
                (int) $sourceId,
                Constants::OBJECT_TYPE_CATEGORY,
                "Merged into category {$targetId}"
            );
            $db->prepare("DELETE FROM {$this->table('categories')} WHERE category_id = :id")->execute(['id' => $sourceId]);

            $db->commit();

            $this->io()->success('Categories merged successfully!');
            $this->io()->text("'$sourceTitle' has been merged into '$targetTitle'");
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->io()->error('Failed to merge categories: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function mergeCategoryRelations(\PDO $db, string $sourceId, string $targetId): void
    {
        foreach (self::CATEGORY_RELATION_TABLES as $suffix => $objectColumn) {
            $table = $this->table('categories') . '_' . $suffix;

            try {
                $stmt = $db->prepare("
                    SELECT src.{$objectColumn}
                    FROM {$table} src
                    INNER JOIN {$table} dst ON dst.{$objectColumn} = src.{$objectColumn}
                    WHERE src.category_id = :source AND dst.category_id = :target
                ");
                $stmt->execute(['source' => $sourceId, 'target' => $targetId]);
                $duplicates = $stmt->fetchAll(\PDO::FETCH_COLUMN);

                $deleteStmt = $db->prepare("
                    DELETE FROM {$table}
                    WHERE category_id = :source AND {$objectColumn} = :object_id
                ");
                foreach ($duplicates as $duplicate) {
                    if (is_scalar($duplicate)) {
                        $deleteStmt->execute(['source' => $sourceId, 'object_id' => $duplicate]);
                    }
                }

                $updateStmt = $db->prepare("UPDATE {$table} SET category_id = :target WHERE category_id = :source");
                $updateStmt->execute(['target' => $targetId, 'source' => $sourceId]);
            } catch (\PDOException $e) {
                throw new \RuntimeException("Failed to merge category relation table {$table}: " . $e->getMessage(), 0, $e);
            }
        }
    }

    private function moveCategoryReferences(\PDO $db, string $sourceId, string $targetId): void
    {
        $usersTable = $this->table('users');
        $stmt = $db->prepare("
            UPDATE {$usersTable}
            SET favourite_category_id = :target
            WHERE favourite_category_id = :source
        ");
        $stmt->execute(['target' => $targetId, 'source' => $sourceId]);

        $table = $this->multiTable('stats_referers_list');
        $stmt = $db->prepare("UPDATE {$table} SET category_id = :target WHERE category_id = :source");
        $stmt->execute(['target' => $targetId, 'source' => $sourceId]);
    }

    /**
     * @param list<string> $categoryIdInputs
     */
    private function assignCategoriesToGroup(?string $groupId, array $categoryIdInputs, InputInterface $input): int
    {
        if ($groupId === null || $groupId === '') {
            $this->io()->error('Category group ID is required');
            $this->io()->text('Usage: kvs content:category assign-group <group_id> <category_ids...>');
            return self::FAILURE;
        }
        if (!ctype_digit($groupId)) {
            $this->io()->error('Category group ID must be numeric');
            return self::FAILURE;
        }

        try {
            $categoryIds = $this->parseCategoryIds($categoryIdInputs);
        } catch (\InvalidArgumentException $e) {
            $this->io()->error($e->getMessage());
            return self::FAILURE;
        }

        if ($categoryIds === []) {
            $this->io()->error('At least one category ID is required');
            $this->io()->text('Usage: kvs content:category assign-group <group_id> <category_ids...>');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            if ($groupId !== '0') {
                $stmt = $db->prepare("SELECT category_group_id FROM {$this->table('categories_groups')} WHERE category_group_id = :id");
                $stmt->execute(['id' => $groupId]);
                if ($stmt->fetch() === false) {
                    $this->io()->error("Category group not found: $groupId");
                    return self::FAILURE;
                }
            }

            $categories = $this->fetchCategoriesByIds($db, $categoryIds);
            $foundIds = array_map(
                static fn(array $category): string => is_scalar($category['category_id'] ?? null) ? (string) $category['category_id'] : '',
                $categories
            );
            $missingIds = array_values(array_diff($categoryIds, $foundIds));
            if ($missingIds !== []) {
                $this->io()->error('Category not found: ' . implode(', ', $missingIds));
                return self::FAILURE;
            }

            $rows = [];
            foreach ($categories as $category) {
                $categoryId = $this->stringValue($category['category_id'] ?? '');
                $title = $this->stringValue($category['title'] ?? '');
                $oldGroupId = $this->stringValue($category['category_group_id'] ?? 0);
                $rows[] = [$categoryId, $title, $oldGroupId, $groupId];
            }

            $this->renderTable(['ID', 'Title', 'Old group', 'New group'], $rows);

            if ($this->getBoolOption($input, 'dry-run')) {
                $this->io()->info('Dry run only, no changes written.');
                return self::SUCCESS;
            }

            $db->beginTransaction();
            $params = ['group_id' => (int) $groupId];
            $placeholders = $this->buildIdPlaceholders($categoryIds, $params);
            $stmt = $db->prepare("
                UPDATE {$this->table('categories')}
                SET category_group_id = :group_id
                WHERE category_id IN ({$placeholders})
            ");
            $stmt->execute($params);
            $db->commit();

            $this->io()->success('Categories assigned to group successfully!');
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->io()->error('Failed to assign categories to group: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param list<string> $inputs
     * @return list<string>
     */
    private function parseCategoryIds(array $inputs): array
    {
        $ids = [];
        foreach ($inputs as $input) {
            foreach (explode(',', $input) as $value) {
                $id = trim($value);
                if ($id === '') {
                    continue;
                }
                if (!ctype_digit($id)) {
                    throw new \InvalidArgumentException("Category ID must be numeric: $id");
                }
                if (!in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * @param list<string> $ids
     * @return list<array<string, mixed>>
     */
    private function fetchCategoriesByIds(\PDO $db, array $ids): array
    {
        $params = [];
        $placeholders = $this->buildIdPlaceholders($ids, $params);
        $stmt = $db->prepare("
            SELECT category_id, title, category_group_id
            FROM {$this->table('categories')}
            WHERE category_id IN ({$placeholders})
        ");
        $stmt->execute($params);

        /** @var list<array<string, mixed>> $categories */
        $categories = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $positions = array_flip($ids);
        usort($categories, static function (array $left, array $right) use ($positions): int {
            $leftId = is_scalar($left['category_id'] ?? null) ? (string) $left['category_id'] : '';
            $rightId = is_scalar($right['category_id'] ?? null) ? (string) $right['category_id'] : '';
            $leftPosition = $positions[$leftId] ?? PHP_INT_MAX;
            $rightPosition = $positions[$rightId] ?? PHP_INT_MAX;
            return $leftPosition <=> $rightPosition;
        });

        return $categories;
    }

    /**
     * @param list<string> $ids
     * @param array<string, int> $params
     */
    private function buildIdPlaceholders(array $ids, array &$params): string
    {
        $placeholders = [];
        foreach ($ids as $index => $id) {
            $name = 'id_' . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = (int) $id;
        }

        return implode(', ', $placeholders);
    }

    /**
     * @return list<string>
     */
    private function getStringArrayArgument(InputInterface $input, string $name): array
    {
        $value = $input->getArgument($name);
        if (!is_array($value)) {
            return [];
        }

        $values = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $values[] = $item;
            } elseif (is_scalar($item)) {
                $values[] = (string) $item;
            }
        }

        return $values;
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function resetCategoryReferences(\PDO $db, string $categoryId): void
    {
        $usersTable = $this->table('users');
        $stmt = $db->prepare("
            UPDATE {$usersTable}
            SET favourite_category_id = 0
            WHERE favourite_category_id = :id
        ");
        $stmt->execute(['id' => $categoryId]);

        $table = $this->multiTable('stats_referers_list');
        $stmt = $db->prepare("UPDATE {$table} SET category_id = 0 WHERE category_id = :id");
        $stmt->execute(['id' => $categoryId]);
    }

    private function deleteCategoryFiles(string $categoryId): void
    {
        $contentPath = $this->config->getCategoriesPath();
        $path = rtrim($contentPath, '/') . '/' . $categoryId;
        if (!is_dir($path)) {
            return;
        }

        $this->removeDirectory($path);
    }

    private function removeDirectory(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }

    private function slugifyCategoryDir(string $title): string
    {
        $dir = preg_replace('/[^a-z0-9]+/', '-', strtolower($title));
        $dir = trim((string) $dir, '-');

        return $dir !== '' ? $dir : 'category';
    }

    private function getUniqueCategoryDir(\PDO $db, string $baseDir): string
    {
        for ($i = 1; $i < 999999; $i++) {
            $dir = $i === 1 ? $baseDir : $baseDir . $i;
            $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table('categories')} WHERE dir = :dir");
            $stmt->execute(['dir' => $dir]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $dir;
            }
        }

        throw new \RuntimeException('Unable to generate unique category directory');
    }

    private function relaxSqlMode(\PDO $db): bool
    {
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return false;
        }

        $db->exec("SET @kvs_cli_old_sql_mode = @@sql_mode, sql_mode = ''");
        return true;
    }

    private function restoreSqlMode(\PDO $db, bool $restore): void
    {
        if (!$restore) {
            return;
        }

        try {
            $db->exec('SET sql_mode = @kvs_cli_old_sql_mode');
        } catch (\Exception) {
        }
    }

    private function isCategoryFieldRequested(InputInterface $input, string $field): bool
    {
        $singleField = $this->getStringOption($input, 'field');
        if ($singleField === $field) {
            return true;
        }

        $fields = $this->getStringOption($input, 'fields');
        if ($fields === null) {
            return false;
        }

        foreach (array_map('trim', explode(',', $fields)) as $requestedField) {
            if ($requestedField === $field) {
                return true;
            }
        }

        return false;
    }

    private function updateCategory(?string $id, InputInterface $input): int
    {
        $categoryId = $this->getRequiredPositiveId($id, 'Category');
        if ($categoryId === null) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            // Get current category
            $stmt = $db->prepare("SELECT * FROM {$this->table('categories')} WHERE category_id = :id");
            $stmt->execute(['id' => $categoryId]);
            $category = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($category)) {
                $this->io()->error("Category not found: $categoryId");
                return self::FAILURE;
            }

            $updates = [];
            $params = ['id' => $categoryId];

            // Title
            $title = $this->getStringOption($input, 'title');
            if ($title !== null) {
                $stmt = $db->prepare("
                    SELECT category_id
                    FROM {$this->table('categories')}
                    WHERE title = :title AND category_id != :id
                ");
                $stmt->execute(['title' => $title, 'id' => $id]);
                if ($stmt->fetch() !== false) {
                    $this->io()->error("Category already exists: $title");
                    return self::FAILURE;
                }

                $updates[] = 'title = :title';
                $params['title'] = $title;
            }

            // Description
            $description = $this->getStringOption($input, 'description');
            if ($description !== null) {
                $updates[] = 'description = :description';
                $params['description'] = $description;
            }

            // Category group
            $groupId = $this->getCategoryGroupInput($input);
            if ($groupId !== null) {
                if ($groupId !== '' && preg_match('/^\d+$/', $groupId) !== 1) {
                    $this->io()->error('Invalid Category group ID (use: integer >= 0)');
                    return self::FAILURE;
                }
                if ($groupId !== '') {
                    $stmt = $db->prepare("SELECT category_group_id FROM {$this->table('categories_groups')} WHERE category_group_id = :id");
                    $stmt->execute(['id' => (int) $groupId]);
                    if ($stmt->fetch() === false) {
                        $this->io()->error("Category group not found: $groupId");
                        return self::FAILURE;
                    }
                }
                $updates[] = 'category_group_id = :category_group_id';
                $params['category_group_id'] = $groupId !== '' ? (int) $groupId : 0;
            }

            if ($this->getStringOption($input, 'parent') !== null && $this->getStringOption($input, 'group') === null) {
                $this->io()->warning('--parent is deprecated for categories; KVS uses category groups. Use --group instead.');
            }

            // Status
            $status = $this->getStringOption($input, 'status');
            if ($status !== null) {
                $statusId = $this->parseStatusFilter($input, [
                    'active' => StatusFormatter::CATEGORY_ACTIVE,
                    'inactive' => StatusFormatter::CATEGORY_INACTIVE,
                    'disabled' => StatusFormatter::CATEGORY_INACTIVE,
                ]);
                if ($statusId === null) {
                    throw new \InvalidArgumentException('Status is required');
                }
                $updates[] = 'status_id = :status_id';
                $params['status_id'] = $statusId;
            }

            if ($updates === []) {
                $this->io()->warning('No changes specified. Use --title, --description, --group, or --status options.');
                return self::FAILURE;
            }

            // Update category
            $sql = "UPDATE {$this->table('categories')} SET " . implode(', ', $updates) . " WHERE category_id = :id";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            $this->io()->success("Category updated successfully!");

            // Show updated category
            return $this->showCategory((string) $categoryId, $input);
        } catch (\Exception $e) {
            $this->io()->error('Failed to update category: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function getCategoryGroupInput(InputInterface $input): ?string
    {
        $groupId = $this->getStringOption($input, 'group');
        if ($groupId !== null) {
            return $groupId;
        }

        return $this->getStringOption($input, 'parent');
    }

    /**
     * @param array<string, mixed> $category
     */
    private function getCategoryStoredOtherAmount(array $category): int
    {
        $total = 0;
        foreach (['total_content_sources', 'total_playlists', 'total_models', 'total_dvds', 'total_dvd_groups'] as $field) {
            $value = $category[$field] ?? 0;
            $total += is_numeric($value) ? (int) $value : 0;
        }

        return $total;
    }

    /**
     * Toggle category status (enable/disable)
     *
     * Uses ToggleStatusTrait for generic implementation.
     *
     * @param string|null $id Category ID
     * @param int $status Target status (0 = disable, 1 = enable)
     * @return int Command::SUCCESS or Command::FAILURE
     */
    private function toggleStatus(?string $id, int $status): int
    {
        return $this->toggleEntityStatus(
            entityName: 'Category',
            tableName: $this->table('categories'),
            idColumn: 'category_id',
            nameColumn: 'title',
            id: $id,
            status: $status,
            commandName: 'content:category'
        );
    }
}
