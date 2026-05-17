<?php

namespace KVS\CLI\Command\Content;

use KVS\CLI\Command\BaseCommand;
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
    use ToggleStatusTrait;

    /** @var list<string> */
    private const CATEGORY_RELATION_TABLES = [
        'videos',
        'content_sources',
        'albums',
        'posts',
        'playlists',
        'dvds',
        'dvds_groups',
        'models',
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

<info>EXAMPLES:</info>
  <comment>kvs category list</comment>
  <comment>kvs category tree</comment>
  <comment>kvs category create "New Category" --group=5</comment>
  <comment>kvs category update 3 --title="Renamed" --status=inactive</comment>
  <comment>kvs category delete 3</comment>
  <comment>kvs category enable 2</comment>
  <comment>kvs category disable 2</comment>
HELP
            )
            ->addArgument('action', InputArgument::OPTIONAL, 'Action: list, tree, show, create, delete, update, enable, disable', 'list')
            ->addArgument('id', InputArgument::OPTIONAL, 'Category ID or title')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Category title')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Category description')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'Category group ID')
            ->addOption('parent', null, InputOption::VALUE_REQUIRED, 'Deprecated alias for --group')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Status (active|inactive)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results to show', Constants::DEFAULT_LIMIT)
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to display')
            ->addOption('field', null, InputOption::VALUE_REQUIRED, 'Display single field from each item')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count, ids', 'table')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation of long text fields');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $this->getStringArgument($input, 'action');
        $id = $this->getStringArgument($input, 'id');

        return match ($action) {
            'list' => $this->listCategories($input),
            'tree' => $this->showTree(),
            'show' => $this->showCategory($id),
            'create' => $this->createCategory($input),
            'delete' => $this->deleteCategory($id),
            'update' => $this->updateCategory($id, $input),
            'enable' => $this->toggleStatus($id, 1),
            'disable' => $this->toggleStatus($id, 0),
            default => $this->listCategories($input),
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

            $whereClause = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
            $limit = $this->getIntOptionOrDefault($input, 'limit', Constants::DEFAULT_LIMIT);

            $sql = "
                SELECT c.*,
                       (SELECT COUNT(*) FROM {$this->table('categories')}_videos WHERE category_id = c.category_id) as video_count,
                       (SELECT COUNT(*) FROM {$this->table('categories')}_albums WHERE category_id = c.category_id) as album_count
                FROM {$this->table('categories')} c
                $whereClause
                ORDER BY c.title
                LIMIT :limit
            ";

            $stmt = $db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value, \PDO::PARAM_INT);
            }
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            /** @var list<array<string, mixed>> $categories */
            $categories = array_values($stmt->fetchAll(\PDO::FETCH_ASSOC));
            $categories = array_map(function (array $category): array {
                $statusIdVal = $category['status_id'] ?? 0;
                $statusId = is_numeric($statusIdVal) ? (int) $statusIdVal : 0;
                $category['id'] = $category['category_id'] ?? 0;
                $category['status'] = StatusFormatter::category($statusId, false);

                return $category;
            }, $categories);

            // Format and display output using centralized Formatter
            $formatter = new Formatter(
                $input->getOptions(),
                ['category_id', 'title', 'video_count', 'album_count', 'status']
            );
            $formatter->display($categories, $this->io());

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch categories: ' . $e->getMessage());
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

    private function deleteCategory(?string $id): int
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
        $usage = [];
        foreach (self::CATEGORY_RELATION_TABLES as $suffix) {
            $table = $this->table('categories') . '_' . $suffix;
            $stmt = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE category_id = :id");
            $stmt->execute(['id' => $categoryId]);
            $count = $stmt->fetchColumn();
            $usage[$suffix] = is_numeric($count) ? (int) $count : 0;
        }

        return $usage;
    }

    /**
     * @param array<string, int> $usage
     * @return list<string>
     */
    private function formatUsageCounts(array $usage): array
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

        $lines = [];
        foreach ($usage as $suffix => $count) {
            $label = $labels[$suffix] ?? $suffix;
            $lines[] = "$label: $count";
        }

        return $lines;
    }

    private function deleteCategoryRelations(\PDO $db, string $categoryId): void
    {
        foreach (self::CATEGORY_RELATION_TABLES as $suffix) {
            $table = $this->table('categories') . '_' . $suffix;
            $db->prepare("DELETE FROM {$table} WHERE category_id = :id")->execute(['id' => $categoryId]);
        }
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
        $contentPath = $this->config->get('content_path_categories');
        if (!is_string($contentPath) || $contentPath === '') {
            $contentPath = $this->config->getContentPath() . '/categories';
        }

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
