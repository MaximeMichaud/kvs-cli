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
  <comment>kvs category create "New Category" --parent=5</comment>
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
            ->addOption('parent', null, InputOption::VALUE_REQUIRED, 'Parent category ID')
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
            $limit = $this->getIntOptionOrDefault($input, 'limit', Constants::DEFAULT_LIMIT);

            $sql = "
                SELECT c.*,
                       (SELECT COUNT(*) FROM {$this->table('categories')}_videos WHERE category_id = c.category_id) as video_count,
                       (SELECT COUNT(*) FROM {$this->table('categories')}_albums WHERE category_id = c.category_id) as album_count
                FROM {$this->table('categories')} c
                ORDER BY c.title
                LIMIT :limit
            ";

            $stmt = $db->prepare($sql);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            /** @var list<array<string, mixed>> $categories */
            $categories = array_values($stmt->fetchAll(\PDO::FETCH_ASSOC));

            // Format and display output using centralized Formatter
            $formatter = new Formatter(
                $input->getOptions(),
                ['category_id', 'title', 'video_count', 'album_count', 'status_id']
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
            $this->io()->text('   or: kvs content:category create --title="Category Name" --description="..." --parent=5');
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
            $parentId = $this->getStringOption($input, 'parent');
            $statusId = StatusFormatter::CATEGORY_ACTIVE;

            // Validate parent category if provided
            if ($parentId !== null) {
                $stmt = $db->prepare("SELECT category_id FROM {$this->table('categories')} WHERE category_id = :id");
                $stmt->execute(['id' => $parentId]);
                if ($stmt->fetch() === false) {
                    $this->io()->error("Parent category not found: $parentId");
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
                'category_group_id' => $parentId !== null ? (int) $parentId : 0,
                'status_id' => $statusId,
            ]);

            $db->exec("SET sql_mode = @old_sql_mode");

            $categoryId = $db->lastInsertId();

            $this->io()->success("Category created successfully!");
            $this->renderTable(
                ['Property', 'Value'],
                [
                    ['ID', (string) $categoryId],
                    ['Title', $title],
                    ['Group ID', $parentId ?? 'None (Root)'],
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

            // Check for child categories
            $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table('categories')} WHERE category_group_id = :id");
            $stmt->execute(['id' => $id]);
            $childCount = $stmt->fetchColumn();

            if ($childCount > 0) {
                $this->io()->error("Cannot delete category with $childCount child categories.");
                $this->io()->text('Please delete or reassign child categories first.');
                return self::FAILURE;
            }

            // Check usage
            $stmt = $db->prepare("
                SELECT
                    (SELECT COUNT(*) FROM {$this->table('categories')}_videos WHERE category_id = :id) as video_count,
                    (SELECT COUNT(*) FROM {$this->table('categories')}_albums WHERE category_id = :id) as album_count
            ");
            $stmt->execute(['id' => $id]);
            $usage = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($usage)) {
                $this->io()->error('Failed to retrieve category usage information');
                return self::FAILURE;
            }

            $videoCount = is_numeric($usage['video_count']) ? (int) $usage['video_count'] : 0;
            $albumCount = is_numeric($usage['album_count']) ? (int) $usage['album_count'] : 0;
            $totalUsage = $videoCount + $albumCount;

            if ($totalUsage > 0) {
                $this->io()->warning("This category is used by $totalUsage items:");
                $this->io()->listing([
                    "Videos: $videoCount",
                    "Albums: $albumCount",
                ]);

                if ($this->io()->confirm('Delete anyway? This will remove all associations.', false) !== true) {
                    $this->io()->info('Operation cancelled');
                    return self::SUCCESS;
                }
            }

            // Delete associations first
            $db->prepare("DELETE FROM {$this->table('categories')}_videos WHERE category_id = :id")->execute(['id' => $id]);
            $db->prepare("DELETE FROM {$this->table('categories')}_albums WHERE category_id = :id")->execute(['id' => $id]);

            // Delete category
            $stmt = $db->prepare("DELETE FROM {$this->table('categories')} WHERE category_id = :id");
            $stmt->execute(['id' => $id]);

            $titleValue = $category['title'] ?? '';
            $deletedTitle = is_string($titleValue) ? $titleValue : (is_scalar($titleValue) ? (string) $titleValue : '');
            $this->io()->success("Category '$deletedTitle' deleted successfully!");
        } catch (\Exception $e) {
            $this->io()->error('Failed to delete category: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
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

            // Parent
            $parentId = $this->getStringOption($input, 'parent');
            if ($parentId !== null) {
                if ($parentId === $id) {
                    $this->io()->error('Category cannot be its own parent');
                    return self::FAILURE;
                }
                $updates[] = 'category_group_id = :category_group_id';
                $params['category_group_id'] = $parentId !== '' ? (int) $parentId : 0;
            }

            // Status
            $status = $this->getStringOption($input, 'status');
            if ($status !== null) {
                $statusId = ($status === 'active') ? StatusFormatter::CATEGORY_ACTIVE : StatusFormatter::CATEGORY_INACTIVE;
                $updates[] = 'status_id = :status_id';
                $params['status_id'] = $statusId;
            }

            if ($updates === []) {
                $this->io()->warning('No changes specified. Use --title, --description, --parent, or --status options.');
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
