<?php

namespace KVS\CLI\Command\Content;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Command\Traits\ToggleStatusTrait;
use KVS\CLI\Output\Formatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function KVS\CLI\Utils\truncate;

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
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results to show', 50)
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to display')
            ->addOption('field', null, InputOption::VALUE_REQUIRED, 'Display single field from each item')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count, ids', 'table')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation of long text fields');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');

        return match ($action) {
            'list' => $this->listCategories($input),
            'tree' => $this->showTree(),
            'show' => $this->showCategory($input->getArgument('id')),
            'create' => $this->createCategory($input),
            'delete' => $this->deleteCategory($input->getArgument('id')),
            'update' => $this->updateCategory($input->getArgument('id'), $input),
            'enable' => $this->toggleStatus($input->getArgument('id'), 1),
            'disable' => $this->toggleStatus($input->getArgument('id'), 0),
            default => $this->listCategories($input),
        };
    }

    private function listCategories(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if (!$db) {
            return self::FAILURE;
        }

        try {
            $limit = (int)$input->getOption('limit');

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

            $categories = $stmt->fetchAll();

            // Format and display output using centralized Formatter
            $formatter = new Formatter(
                $input->getOptions(),
                ['category_id', 'title', 'video_count', 'album_count', 'status_id']
            );
            $formatter->display($categories, $this->io);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io->error('Failed to fetch categories: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function showTree(): int
    {
        $db = $this->getDatabaseConnection();
        if (!$db) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->query("
                SELECT c.*,
                       (SELECT COUNT(*) FROM {$this->table('categories')}_videos WHERE category_id = c.category_id) as video_count
                FROM {$this->table('categories')} c
                ORDER BY parent_id, title
            ");
            $categories = $stmt->fetchAll();

            $this->io->section('Category Tree');

            foreach ($categories as $cat) {
                $prefix = $cat['parent_id'] ? '  └─ ' : '';
                $count = " ({$cat['video_count']} videos)";
                $status = $cat['status_id'] == 1 ? '' : ' <fg=yellow>[Inactive]</>';
                $this->io->text($prefix . $cat['title'] . $count . $status);
            }
        } catch (\Exception $e) {
            $this->io->error('Failed to fetch categories: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function showCategory(?string $id): int
    {
        if (!$id) {
            $this->io->error('Category ID is required');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if (!$db) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("SELECT * FROM {$this->table('categories')} WHERE category_id = :id");
            $stmt->execute(['id' => $id]);
            $category = $stmt->fetch();

            if (!$category) {
                $this->io->error("Category not found: $id");
                return self::FAILURE;
            }

            $this->io->section("Category: {$category['title']}");

            $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table('categories')}_videos WHERE category_id = :id");
            $stmt->execute(['id' => $id]);
            $videoCount = $stmt->fetchColumn();

            $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table('categories')}_albums WHERE category_id = :id");
            $stmt->execute(['id' => $id]);
            $albumCount = $stmt->fetchColumn();

            $info = [
                ['ID', $category['category_id']],
                ['Title', $category['title']],
                ['Parent ID', $category['parent_id'] ?? 'None (Root)'],
                ['Status', $category['status_id'] == 1 ? 'Active' : 'Inactive'],
                ['Videos', $videoCount],
                ['Albums', $albumCount],
                ['Added', $category['added_date'] ?? 'N/A'],
            ];

            $this->renderTable(['Property', 'Value'], $info);

            if ($category['description']) {
                $this->io->section('Description');
                $this->io->text($category['description']);
            }
        } catch (\Exception $e) {
            $this->io->error('Failed to fetch category: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function createCategory(InputInterface $input): int
    {
        $title = $input->getOption('title') ?: $input->getArgument('id');

        if (!$title) {
            $this->io->error('Category title is required');
            $this->io->text('Usage: kvs content:category create "Category Name"');
            $this->io->text('   or: kvs content:category create --title="Category Name" --description="..." --parent=5');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if (!$db) {
            return self::FAILURE;
        }

        try {
            // Check if category already exists
            $stmt = $db->prepare("SELECT category_id FROM {$this->table('categories')} WHERE title = :title");
            $stmt->execute(['title' => $title]);

            if ($stmt->fetch()) {
                $this->io->error("Category already exists: $title");
                return self::FAILURE;
            }

            // Prepare data
            $description = $input->getOption('description') ?? '';
            $parentId = $input->getOption('parent');
            $statusId = 1; // Active by default

            // Validate parent category if provided
            if ($parentId) {
                $stmt = $db->prepare("SELECT category_id FROM {$this->table('categories')} WHERE category_id = :id");
                $stmt->execute(['id' => $parentId]);
                if (!$stmt->fetch()) {
                    $this->io->error("Parent category not found: $parentId");
                    return self::FAILURE;
                }
            }

            // Create category
            $stmt = $db->prepare("
                INSERT INTO {$this->table('categories')} (title, description, parent_id, status_id, video_count, added_date)
                VALUES (:title, :description, :parent_id, :status_id, 0, NOW())
            ");

            $stmt->execute([
                'title' => $title,
                'description' => $description,
                'parent_id' => $parentId,
                'status_id' => $statusId,
            ]);

            $categoryId = $db->lastInsertId();

            $this->io->success("Category created successfully!");
            $this->renderTable(
                ['Property', 'Value'],
                [
                    ['ID', $categoryId],
                    ['Title', $title],
                    ['Parent ID', $parentId ?? 'None (Root)'],
                    ['Description', $description ?: 'None'],
                    ['Status', 'Active'],
                ]
            );
        } catch (\Exception $e) {
            $this->io->error('Failed to create category: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function deleteCategory(?string $id): int
    {
        if (!$id) {
            $this->io->error('Category ID is required');
            $this->io->text('Usage: kvs content:category delete <category_id>');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if (!$db) {
            return self::FAILURE;
        }

        try {
            // Get category details
            $stmt = $db->prepare("SELECT * FROM {$this->table('categories')} WHERE category_id = :id");
            $stmt->execute(['id' => $id]);
            $category = $stmt->fetch();

            if (!$category) {
                $this->io->error("Category not found: $id");
                return self::FAILURE;
            }

            // Check for child categories
            $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table('categories')} WHERE parent_id = :id");
            $stmt->execute(['id' => $id]);
            $childCount = $stmt->fetchColumn();

            if ($childCount > 0) {
                $this->io->error("Cannot delete category with $childCount child categories.");
                $this->io->text('Please delete or reassign child categories first.');
                return self::FAILURE;
            }

            // Check usage
            $stmt = $db->prepare("
                SELECT
                    (SELECT COUNT(*) FROM {$this->table('categories')}_videos WHERE category_id = :id) as video_count,
                    (SELECT COUNT(*) FROM {$this->table('categories')}_albums WHERE category_id = :id) as album_count
            ");
            $stmt->execute(['id' => $id]);
            $usage = $stmt->fetch();

            $totalUsage = $usage['video_count'] + $usage['album_count'];

            if ($totalUsage > 0) {
                $this->io->warning("This category is used by $totalUsage items:");
                $this->io->listing([
                    "Videos: {$usage['video_count']}",
                    "Albums: {$usage['album_count']}",
                ]);

                if (!$this->io->confirm('Delete anyway? This will remove all associations.', false)) {
                    $this->io->info('Operation cancelled');
                    return self::SUCCESS;
                }
            }

            // Delete associations first
            $db->prepare("DELETE FROM {$this->table('categories')}_videos WHERE category_id = :id")->execute(['id' => $id]);
            $db->prepare("DELETE FROM {$this->table('categories')}_albums WHERE category_id = :id")->execute(['id' => $id]);

            // Delete category
            $stmt = $db->prepare("DELETE FROM {$this->table('categories')} WHERE category_id = :id");
            $stmt->execute(['id' => $id]);

            $this->io->success("Category '{$category['title']}' deleted successfully!");
        } catch (\Exception $e) {
            $this->io->error('Failed to delete category: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function updateCategory(?string $id, InputInterface $input): int
    {
        if (!$id) {
            $this->io->error('Category ID is required');
            $this->io->text('Usage: kvs content:category update <category_id> --title="New Title" --description="..." --status=inactive');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if (!$db) {
            return self::FAILURE;
        }

        try {
            // Get current category
            $stmt = $db->prepare("SELECT * FROM {$this->table('categories')} WHERE category_id = :id");
            $stmt->execute(['id' => $id]);
            $category = $stmt->fetch();

            if (!$category) {
                $this->io->error("Category not found: $id");
                return self::FAILURE;
            }

            $updates = [];
            $params = ['id' => $id];

            // Title
            if ($title = $input->getOption('title')) {
                $updates[] = 'title = :title';
                $params['title'] = $title;
            }

            // Description
            if ($input->hasOption('description') && $input->getOption('description') !== null) {
                $updates[] = 'description = :description';
                $params['description'] = $input->getOption('description');
            }

            // Parent
            if ($input->hasOption('parent') && $input->getOption('parent') !== null) {
                $parentId = $input->getOption('parent');
                if ($parentId == $id) {
                    $this->io->error('Category cannot be its own parent');
                    return self::FAILURE;
                }
                $updates[] = 'parent_id = :parent_id';
                $params['parent_id'] = $parentId ?: null;
            }

            // Status
            if ($status = $input->getOption('status')) {
                $statusId = ($status === 'active') ? 1 : 0;
                $updates[] = 'status_id = :status_id';
                $params['status_id'] = $statusId;
            }

            if (empty($updates)) {
                $this->io->warning('No changes specified. Use --title, --description, --parent, or --status options.');
                return self::FAILURE;
            }

            // Update category
            $sql = "UPDATE {$this->table('categories')} SET " . implode(', ', $updates) . " WHERE category_id = :id";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            $this->io->success("Category updated successfully!");

            // Show updated category
            return $this->showCategory($id);
        } catch (\Exception $e) {
            $this->io->error('Failed to update category: ' . $e->getMessage());
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
