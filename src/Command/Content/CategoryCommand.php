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
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'Category group ID')
            ->addOption('parent', null, InputOption::VALUE_REQUIRED, 'Deprecated alias for --group')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Status (active|inactive)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results to show', Constants::DEFAULT_LIMIT)
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search in category titles')
            ->addOption('unused', null, InputOption::VALUE_NONE, 'Show only unused categories')
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
            'tree' => $this->showTree(),
            'show' => $this->showCategory($id),
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
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $conditions = [];
            $params = [];

            $statusId = $this->parseStatusFilter($input, [
                'active' => StatusFormatter::CATEGORY_ACTIVE,
                'inactive' => StatusFormatter::CATEGORY_INACTIVE,
                'disabled' => StatusFormatter::CATEGORY_INACTIVE,
            ]);
            if ($statusId !== null) {
                $conditions[] = 'c.status_id = :status';
                $params['status'] = $statusId;
            }

            $search = $this->getStringOption($input, 'search');
            if ($search !== null) {
                $conditions[] = 'c.title LIKE :search';
                $params['search'] = '%' . $search . '%';
            }

            $groupId = $this->getStringOption($input, 'group');
            if ($groupId !== null) {
                if (!ctype_digit($groupId)) {
                    $this->io()->error('Category group ID must be numeric');
                    return self::FAILURE;
                }
                $conditions[] = 'c.category_group_id = :group';
                $params['group'] = (int) $groupId;
            }

            $usageJoins = $this->getCategoryUsageJoins();
            $unusedOnly = $this->getBoolOption($input, 'unused');
            if ($unusedOnly) {
                $conditions[] = $this->getCategoryTotalUsageCondition() . ' = 0';
            }

            $whereClause = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
            if ($this->getStringOptionOrDefault($input, 'format', 'table') === 'count') {
                return $this->countCategories($db, $whereClause, $params, $unusedOnly ? $usageJoins : '');
            }

            $limit = $this->getPositiveIntOptionOrDefault($input, 'limit', Constants::DEFAULT_LIMIT);
            if ($limit === null) {
                return self::FAILURE;
            }
            $usageSelectors = $this->getCategoryUsageSelectors();

            $sql = "
                SELECT c.*,
                       {$usageSelectors}
                FROM {$this->table('categories')} c
                {$usageJoins}
                $whereClause
                ORDER BY c.title
                LIMIT :limit
            ";

            $stmt = $db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            /** @var list<array<string, mixed>> $categories */
            $categories = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $categories = array_map(function (array $category): array {
                $counts = $this->extractCategoryUsageCounts($category);
                $statusIdVal = $category['status_id'] ?? 0;
                $statusId = is_numeric($statusIdVal) ? (int) $statusIdVal : 0;
                $category['id'] = $category['category_id'] ?? 0;
                $category['video_count'] = $counts['videos'];
                $category['album_count'] = $counts['albums'];
                $category['total_usage'] = array_sum($counts);
                $category['status'] = StatusFormatter::category($statusId, false);

                return [
                    ...$category,
                    ...$counts,
                ];
            }, $categories);

            // Format and display output using centralized Formatter
            $formatter = new Formatter(
                $input->getOptions(),
                ['category_id', 'title', 'video_count', 'album_count', 'total_usage', 'status']
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

    private function showTree(): int
    {
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

    private function showCategory(?string $id): int
    {
        if ($id === null || $id === '') {
            $this->io()->error('Category ID is required');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("SELECT * FROM {$this->table('categories')} WHERE category_id = :id");
            $stmt->execute(['id' => $id]);
            /** @var array<string, mixed>|false $category */
            $category = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($category === false) {
                $this->io()->error("Category not found: $id");
                return self::FAILURE;
            }

            $titleValue = $category['title'] ?? '';
            $categoryTitle = is_string($titleValue) ? $titleValue : (is_scalar($titleValue) ? (string) $titleValue : '');
            $this->io()->section("Category: $categoryTitle");

            $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table('categories')}_videos WHERE category_id = :id");
            $stmt->execute(['id' => $id]);
            $videoCountRaw = $stmt->fetchColumn();
            $videoCount = is_numeric($videoCountRaw) ? (int) $videoCountRaw : 0;

            $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table('categories')}_albums WHERE category_id = :id");
            $stmt->execute(['id' => $id]);
            $albumCountRaw = $stmt->fetchColumn();
            $albumCount = is_numeric($albumCountRaw) ? (int) $albumCountRaw : 0;

            $groupId = $category['category_group_id'] ?? 0;
            $groupIdInt = is_numeric($groupId) ? (int) $groupId : 0;
            $groupIdStr = $groupIdInt > 0 ? (string) $groupIdInt : 'None (Root)';
            $addedDate = $category['added_date'] ?? null;
            $addedDateStr = is_string($addedDate) ? $addedDate : 'N/A';

            $categoryId = $category['category_id'] ?? 0;
            $statusId = isset($category['status_id']) && is_numeric($category['status_id']) ? (int) $category['status_id'] : 0;

            $info = [
                ['ID', is_scalar($categoryId) ? (string) $categoryId : '0'],
                ['Title', $categoryTitle],
                ['Group ID', $groupIdStr],
                ['Status', StatusFormatter::category($statusId)],
                ['Videos', (string) $videoCount],
                ['Albums', (string) $albumCount],
                ['Added', $addedDateStr],
            ];

            $this->renderTable(['Property', 'Value'], $info);

            $description = $category['description'] ?? null;
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

            // Create category - dir is URL slug of title
            $dir = preg_replace('/[^a-z0-9]+/', '-', strtolower($title));
            $dir = trim((string) $dir, '-');

            // Relax sql_mode for INSERT (KVS tables have many NOT NULL without DEFAULT)
            $db->exec("SET @old_sql_mode = @@sql_mode, sql_mode = ''");

            $table = $this->table('categories');
            $stmt = $db->prepare("
                INSERT INTO {$table}
                    (title, dir, description, synonyms, category_group_id,
                     status_id, added_date, last_content_date)
                VALUES
                    (:title, :dir, :description, '',
                     :category_group_id, :status_id, NOW(), NOW())
            ");

            $stmt->execute([
                'title' => $title,
                'dir' => $dir,
                'description' => $description,
                'category_group_id' => $groupId !== null && $groupId !== '' ? (int) $groupId : 0,
                'status_id' => $statusId,
            ]);

            $categoryId = $db->lastInsertId();

            $db->exec("SET sql_mode = @old_sql_mode");

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
        $expressions = [];
        foreach (array_keys(self::CATEGORY_RELATION_TABLES) as $suffix) {
            $expressions[] = sprintf(
                'COALESCE(%s.usage_count, 0)',
                $this->getCategoryUsageJoinAlias($suffix)
            );
        }

        return implode(' + ', $expressions);
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

    private function updateCategory(?string $id, InputInterface $input): int
    {
        if ($id === null || $id === '') {
            $this->io()->error('Category ID is required');
            $this->io()->text('Usage: kvs content:category update <category_id> --title="New Title" --description="..." --status=inactive');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            // Get current category
            $stmt = $db->prepare("SELECT * FROM {$this->table('categories')} WHERE category_id = :id");
            $stmt->execute(['id' => $id]);
            $category = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($category)) {
                $this->io()->error("Category not found: $id");
                return self::FAILURE;
            }

            $updates = [];
            $params = ['id' => $id];

            // Title
            $title = $this->getStringOption($input, 'title');
            if ($title !== null) {
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
                if ($groupId !== '') {
                    $stmt = $db->prepare("SELECT category_group_id FROM {$this->table('categories_groups')} WHERE category_group_id = :id");
                    $stmt->execute(['id' => $groupId]);
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
                $statusId = ($status === 'active') ? StatusFormatter::CATEGORY_ACTIVE : StatusFormatter::CATEGORY_INACTIVE;
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
            return $this->showCategory($id);
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
