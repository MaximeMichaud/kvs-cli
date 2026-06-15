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
    name: 'content:category-group',
    description: 'Manage KVS category groups',
    aliases: ['category-group', 'category-groups', 'cat-group', 'cgroup']
)]
class CategoryGroupCommand extends BaseCommand
{
    use ToggleStatusTrait;

    protected function configure(): void
    {
        $this
            ->setHelp(<<<'HELP'
Manage KVS category groups with full CRUD operations.

Category groups organize categories (see <comment>kvs category assign-group</comment>).
This command manages the groups themselves (table categories_groups).

<info>ACTIONS:</info>
  list              List all category groups (default)
  show <id>         Show category group details
  create <title>    Create new category group
  delete <id>       Delete category group (detaches its categories)
  update <id>       Update category group properties
  enable <id>       Enable category group
  disable <id>      Disable category group

<info>EXAMPLES:</info>
  <comment>kvs category-group list</comment>
  <comment>kvs category-group list --search=Genres --status=active</comment>
  <comment>kvs category-group create "Genres" --description="Movie genres"</comment>
  <comment>kvs category-group create --title="Studios" --external-id=studios --sort=5</comment>
  <comment>kvs category-group show 3</comment>
  <comment>kvs category-group update 3 --title="Movie Genres" --status=inactive</comment>
  <comment>kvs category-group enable 3</comment>
  <comment>kvs category-group disable 3</comment>
  <comment>kvs category-group delete 3</comment>
HELP
            )
            ->addArgument(
                'action',
                InputArgument::OPTIONAL,
                'Action: list, show, create, delete, update, enable, disable',
                'list'
            )
            ->addArgument('id', InputArgument::OPTIONAL, 'Category group ID, or title when creating')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Category group title')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Category group description')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Status (active|inactive)')
            ->addOption('external-id', null, InputOption::VALUE_REQUIRED, 'External ID (must be unique)')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Directory slug (auto-generated from title if omitted)')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'Manual sort order (sort_id, integer >= 0)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results to show', Constants::DEFAULT_LIMIT)
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search in category group titles')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to display')
            ->addOption('field', null, InputOption::VALUE_REQUIRED, 'Display single field from each item')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count, ids', 'table')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation of long text fields');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $this->getStringArgument($input, 'action') ?? 'list';
        $id = $this->getStringArgument($input, 'id');

        return match ($action) {
            'list' => $this->listGroups($input),
            'show' => $this->showGroup($id),
            'create' => $this->createGroup($input),
            'delete' => $this->deleteGroup($id, $input),
            'update' => $this->updateGroup($id, $input),
            'enable' => $this->toggleStatus($id, 1),
            'disable' => $this->toggleStatus($id, 0),
            default => $this->failUnknownAction(
                'category-group',
                $action,
                ['list', 'show', 'create', 'delete', 'update', 'enable', 'disable']
            ),
        };
    }

    private function listGroups(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $conditions = [];
            $params = [];

            $statusId = $this->parseStatusFilter($input, [
                'active' => StatusFormatter::CATEGORY_GROUP_ACTIVE,
                'inactive' => StatusFormatter::CATEGORY_GROUP_DISABLED,
                'disabled' => StatusFormatter::CATEGORY_GROUP_DISABLED,
            ]);
            if ($statusId !== null) {
                $conditions[] = 'g.status_id = :status';
                $params['status'] = $statusId;
            }

            $search = $this->getStringOption($input, 'search');
            if ($search !== null) {
                $conditions[] = 'g.title LIKE :search';
                $params['search'] = '%' . $search . '%';
            }

            $whereClause = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

            if ($this->getStringOptionOrDefault($input, 'format', 'table') === 'count') {
                return $this->countGroups($db, $whereClause, $params);
            }

            $limit = $this->getPositiveIntOptionOrDefault($input, 'limit', Constants::DEFAULT_LIMIT);
            if ($limit === null) {
                return self::FAILURE;
            }

            $categoriesTable = $this->table('categories');
            $sql = "
                SELECT g.*,
                       (SELECT COUNT(*) FROM {$categoriesTable} WHERE category_group_id = g.category_group_id) AS category_count
                FROM {$this->table('categories_groups')} g
                $whereClause
                ORDER BY g.sort_id, g.title
                LIMIT :limit
            ";

            $stmt = $db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            /** @var list<array<string, mixed>> $groups */
            $groups = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $groups = array_map(function (array $group): array {
                $statusIdVal = $group['status_id'] ?? 0;
                $statusId = is_numeric($statusIdVal) ? (int) $statusIdVal : 0;
                $group['id'] = $group['category_group_id'] ?? 0;
                $group['status'] = StatusFormatter::categoryGroup($statusId, false);

                return $group;
            }, $groups);

            $formatter = new Formatter(
                $input->getOptions(),
                ['category_group_id', 'title', 'dir', 'category_count', 'status']
            );
            $formatter->display($groups, $this->io());

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch category groups: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function countGroups(\PDO $db, string $whereClause, array $params): int
    {
        try {
            $stmt = $db->prepare("
                SELECT COUNT(*)
                FROM {$this->table('categories_groups')} g
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
            $this->io()->error('Failed to count category groups: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function showGroup(?string $id): int
    {
        $groupId = $this->requireNumericId($id, 'show');
        if ($groupId === null) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("SELECT * FROM {$this->table('categories_groups')} WHERE category_group_id = :id");
            $stmt->execute(['id' => $groupId]);
            /** @var array<string, mixed>|false $group */
            $group = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($group === false) {
                $this->io()->error("Category group not found: $groupId");
                return self::FAILURE;
            }

            $groupTitle = $this->stringValue($group['title'] ?? '');
            $this->io()->section("Category group: $groupTitle");

            $categoryCount = $this->countCategoriesInGroup($db, $groupId);

            $statusId = isset($group['status_id']) && is_numeric($group['status_id']) ? (int) $group['status_id'] : 0;
            $externalId = $this->stringValue($group['external_id'] ?? '');
            $addedDate = $group['added_date'] ?? null;

            $info = [
                ['ID', $this->stringValue($group['category_group_id'] ?? '0')],
                ['Title', $groupTitle],
                ['Dir', $this->stringValue($group['dir'] ?? '')],
                ['Status', StatusFormatter::categoryGroup($statusId)],
                ['External ID', $externalId !== '' ? $externalId : 'None'],
                ['Sort', $this->stringValue($group['sort_id'] ?? '0')],
                ['Categories', (string) $categoryCount],
                ['Added', is_string($addedDate) ? $addedDate : 'N/A'],
            ];

            $this->renderTable(['Property', 'Value'], $info);

            $description = $group['description'] ?? null;
            if ($description !== null && $description !== '' && is_scalar($description)) {
                $this->io()->section('Description');
                $this->io()->text((string) $description);
            }
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch category group: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function createGroup(InputInterface $input): int
    {
        $title = $this->getStringOption($input, 'title') ?? $this->getStringArgument($input, 'id');

        if ($title === null || $title === '') {
            $this->io()->error('Category group title is required');
            $this->io()->text('Usage: kvs content:category-group create "Group Name"');
            $this->io()->text('   or: kvs content:category-group create --title="Group Name" --description="..."');
            return self::FAILURE;
        }

        $statusId = $this->getStatusOption($input);
        if ($statusId === false) {
            return self::FAILURE;
        }
        $statusId ??= StatusFormatter::CATEGORY_GROUP_ACTIVE;

        $sortId = $this->parseSortId($input);
        if ($sortId === false) {
            return self::FAILURE;
        }

        $description = $this->getStringOption($input, 'description') ?? '';
        $externalId = $this->getStringOption($input, 'external-id') ?? '';

        $explicitDir = $this->getStringOption($input, 'dir');
        if ($explicitDir !== null && $explicitDir !== '') {
            $dir = $this->slugify($explicitDir);
            if ($dir === '') {
                $this->io()->error('The provided --dir does not contain any usable slug characters.');
                return self::FAILURE;
            }
        } else {
            $dir = $this->slugify($title);
            if ($dir === '') {
                // Non-ASCII / punctuation-only titles slugify to empty; keep a usable default.
                $dir = 'group';
            }
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("SELECT category_group_id FROM {$this->table('categories_groups')} WHERE title = :title");
            $stmt->execute(['title' => $title]);
            if ($stmt->fetch() !== false) {
                $this->io()->error("Category group already exists: $title");
                return self::FAILURE;
            }

            if ($externalId !== '') {
                $stmt = $db->prepare("SELECT category_group_id FROM {$this->table('categories_groups')} WHERE external_id = :external_id");
                $stmt->execute(['external_id' => $externalId]);
                if ($stmt->fetch() !== false) {
                    $this->io()->error("Category group with external ID already exists: $externalId");
                    return self::FAILURE;
                }
            }

            $table = $this->table('categories_groups');
            $dir = $this->resolveUniqueDir($db, $dir, null);

            // Relax sql_mode for INSERT (KVS tables have many NOT NULL without DEFAULT).
            $this->relaxSqlMode($db);
            try {
                $stmt = $db->prepare("
                    INSERT INTO {$table}
                        (title, dir, description, status_id, external_id, sort_id, added_date)
                    VALUES
                        (:title, :dir, :description, :status_id, :external_id, :sort_id, :added_date)
                ");
                $stmt->execute([
                    'title' => $title,
                    'dir' => $dir,
                    'description' => $description,
                    'status_id' => $statusId,
                    'external_id' => $externalId,
                    'sort_id' => $sortId,
                    'added_date' => date('Y-m-d H:i:s'),
                ]);

                $groupId = $db->lastInsertId();
            } finally {
                $this->restoreSqlMode($db);
            }

            $this->io()->success('Category group created successfully!');
            $this->renderTable(
                ['Property', 'Value'],
                [
                    ['ID', (string) $groupId],
                    ['Title', $title],
                    ['Dir', $dir],
                    ['Description', $description !== '' ? $description : 'None'],
                    ['External ID', $externalId !== '' ? $externalId : 'None'],
                    ['Sort', (string) $sortId],
                    ['Status', StatusFormatter::categoryGroup($statusId, false)],
                ]
            );
        } catch (\Exception $e) {
            $this->io()->error('Failed to create category group: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function deleteGroup(?string $id, InputInterface $input): int
    {
        $groupId = $this->requireNumericId($id, 'delete');
        if ($groupId === null) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $deletedTitle = '';

        try {
            $stmt = $db->prepare("SELECT * FROM {$this->table('categories_groups')} WHERE category_group_id = :id");
            $stmt->execute(['id' => $groupId]);
            $group = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($group)) {
                $this->io()->error("Category group not found: $groupId");
                return self::FAILURE;
            }
            $deletedTitle = $this->stringValue($group['title'] ?? '');

            $reviewedCategoryIds = $this->categoryIdsInGroup($db, $groupId);
            if ($reviewedCategoryIds !== []) {
                $reviewedCount = count($reviewedCategoryIds);
                $this->io()->warning("This group contains $reviewedCount categories.");
                $this->io()->text('They will be detached (category_group_id set to 0), not deleted.');

                if ($this->io()->confirm('Delete anyway?', false) !== true) {
                    if (!$input->isInteractive()) {
                        $this->io()->error('Category group deletion cancelled because confirmation was not provided.');
                        return self::FAILURE;
                    }

                    $this->io()->info('Operation cancelled');
                    return self::SUCCESS;
                }
            }

            $db->beginTransaction();

            // Serialize against a concurrent `category assign-group`: lock the group row
            // (MySQL) so an in-flight assignment either completes before we read, or blocks
            // until we commit the delete and then fails its own existence check. The lock
            // clause degrades to a plain SELECT on drivers without row locking (e.g. SQLite).
            $lockStmt = $db->prepare(
                "SELECT category_group_id FROM {$this->table('categories_groups')} WHERE category_group_id = :id"
                . $this->rowLockClause($db)
            );
            $lockStmt->execute(['id' => $groupId]);
            if ($lockStmt->fetch() === false) {
                $db->rollBack();
                $this->io()->error("Category group not found: $groupId");
                return self::FAILURE;
            }

            // Re-read the exact set of attached categories under the lock and abort if it
            // changed since the operator reviewed it - even a same-size swap (one leaves,
            // one joins). This guarantees we never detach a category that was never shown
            // and confirmed.
            if ($this->categoryIdsInGroup($db, $groupId) !== $reviewedCategoryIds) {
                $db->rollBack();
                $this->io()->warning(
                    'The set of categories in this group changed since you reviewed it. '
                    . 'Re-run the delete to review and confirm.'
                );
                return self::FAILURE;
            }

            $db->prepare("UPDATE {$this->table('categories')} SET category_group_id = 0 WHERE category_group_id = :id")
                ->execute(['id' => $groupId]);
            $db->prepare("DELETE FROM {$this->table('categories_groups')} WHERE category_group_id = :id")
                ->execute(['id' => $groupId]);
            $db->commit();
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->io()->error('Failed to delete category group: ' . $e->getMessage());
            return self::FAILURE;
        }

        // Best-effort side effects AFTER the commit. The row is already gone, so a failure
        // here must not be reported as an overall failure (that would break safe retries).
        try {
            // TODO: Replace 180 if KVS exposes a category-group delete audit action.
            $this->writeAdminAuditLog($db, 180, $groupId, Constants::OBJECT_TYPE_CATEGORY_GROUP);
            $this->deleteGroupFiles((string) $groupId);
        } catch (\Throwable $cleanupError) {
            $this->io()->warning('Category group deleted, but post-delete cleanup failed: ' . $cleanupError->getMessage());
        }

        $this->io()->success("Category group '$deletedTitle' deleted successfully!");

        return self::SUCCESS;
    }

    private function updateGroup(?string $id, InputInterface $input): int
    {
        $groupId = $this->requireNumericId($id, 'update');
        if ($groupId === null) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("SELECT * FROM {$this->table('categories_groups')} WHERE category_group_id = :id");
            $stmt->execute(['id' => $groupId]);
            $group = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($group)) {
                $this->io()->error("Category group not found: $groupId");
                return self::FAILURE;
            }

            $updates = [];
            $params = ['id' => $groupId];

            $title = $this->getStringOption($input, 'title');
            if ($title !== null) {
                if ($title === '') {
                    $this->io()->error('Category group title cannot be empty');
                    return self::FAILURE;
                }
                $stmt = $db->prepare(
                    "SELECT category_group_id FROM {$this->table('categories_groups')}
                     WHERE title = :title AND category_group_id <> :id"
                );
                $stmt->execute(['title' => $title, 'id' => $groupId]);
                if ($stmt->fetch() !== false) {
                    $this->io()->error("Category group already exists: $title");
                    return self::FAILURE;
                }
                $updates[] = 'title = :title';
                $params['title'] = $title;
            }

            $description = $this->getStringOption($input, 'description');
            if ($description !== null) {
                $updates[] = 'description = :description';
                $params['description'] = $description;
            }

            $dir = $this->getStringOption($input, 'dir');
            if ($dir !== null && $dir !== '') {
                $normalizedDir = $this->slugify($dir);
                if ($normalizedDir === '') {
                    $this->io()->error('The provided --dir does not contain any usable slug characters.');
                    return self::FAILURE;
                }
                $updates[] = 'dir = :dir';
                $params['dir'] = $this->resolveUniqueDir($db, $normalizedDir, $groupId);
            }

            // Use the raw option so an explicitly empty --external-id= clears the value;
            // getStringOption() collapses "" to null and would silently skip the change.
            $externalId = $input->getOption('external-id');
            if ($externalId !== null) {
                if ($externalId !== '') {
                    $stmt = $db->prepare(
                        "SELECT category_group_id FROM {$this->table('categories_groups')}
                         WHERE external_id = :external_id AND category_group_id <> :id"
                    );
                    $stmt->execute(['external_id' => $externalId, 'id' => $groupId]);
                    if ($stmt->fetch() !== false) {
                        $this->io()->error("Category group with external ID already exists: $externalId");
                        return self::FAILURE;
                    }
                }
                $updates[] = 'external_id = :external_id';
                $params['external_id'] = $externalId;
            }

            $status = $this->getStatusOption($input);
            if ($status === false) {
                return self::FAILURE;
            }
            if ($status !== null) {
                $updates[] = 'status_id = :status_id';
                $params['status_id'] = $status;
            }

            if ($this->getStringOption($input, 'sort') !== null) {
                $sortId = $this->parseSortId($input);
                if ($sortId === false) {
                    return self::FAILURE;
                }
                $updates[] = 'sort_id = :sort_id';
                $params['sort_id'] = $sortId;
            }

            if ($updates === []) {
                $this->io()->warning('No changes specified. Use --title, --description, --dir, --external-id, --status, or --sort options.');
                return self::FAILURE;
            }

            $sql = "UPDATE {$this->table('categories_groups')} SET " . implode(', ', $updates) . " WHERE category_group_id = :id";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            $this->io()->success('Category group updated successfully!');

            return $this->showGroup((string) $groupId);
        } catch (\Exception $e) {
            $this->io()->error('Failed to update category group: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Toggle category group status (enable/disable) via the shared trait.
     *
     * @param string|null $id Category group ID
     * @param int $status Target status (0 = disable, 1 = enable)
     */
    private function toggleStatus(?string $id, int $status): int
    {
        $action = $status !== 0 ? 'enable' : 'disable';
        $groupId = $this->requireNumericId($id, $action);
        if ($groupId === null) {
            return self::FAILURE;
        }

        return $this->toggleEntityStatus(
            entityName: 'Category group',
            tableName: $this->table('categories_groups'),
            idColumn: 'category_group_id',
            nameColumn: 'title',
            id: (string) $groupId,
            status: $status,
            commandName: 'content:category-group'
        );
    }

    /**
     * Validate a required, numeric category group ID for read/mutation actions.
     *
     * Returns null (after printing an error) when the ID is missing or non-numeric,
     * otherwise returns it as an int. Casting prevents MySQL from coercing a string
     * such as "1abc" or "1,2" into a real row ID on the mutation paths.
     */
    private function requireNumericId(?string $id, string $action): ?int
    {
        if ($id === null || $id === '') {
            $this->io()->error('Category group ID is required');
            $this->io()->text("Usage: kvs content:category-group {$action} <category_group_id>");
            return null;
        }
        if (!ctype_digit($id)) {
            $this->io()->error('Category group ID must be numeric');
            return null;
        }

        return (int) $id;
    }

    protected function countCategoriesInGroup(\PDO $db, int $groupId): int
    {
        $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table('categories')} WHERE category_group_id = :id");
        $stmt->execute(['id' => $groupId]);
        $count = $stmt->fetchColumn();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @return list<int> sorted IDs of the categories currently attached to the group
     */
    protected function categoryIdsInGroup(\PDO $db, int $groupId): array
    {
        $stmt = $db->prepare(
            "SELECT category_id FROM {$this->table('categories')} WHERE category_group_id = :id ORDER BY category_id"
        );
        $stmt->execute(['id' => $groupId]);

        $ids = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }

    /**
     * Parse the --status option into a status_id.
     *
     * Returns null when the option is absent, false (after printing an error) when the
     * value is not a recognised status, or the resolved status_id otherwise. Strict
     * validation prevents a typo such as "actve" from silently disabling a group.
     */
    private function getStatusOption(InputInterface $input): int|false|null
    {
        $status = $this->getStringOption($input, 'status');
        if ($status === null) {
            return null;
        }

        switch (strtolower(trim($status))) {
            case 'active':
                return StatusFormatter::CATEGORY_GROUP_ACTIVE;
            case 'inactive':
            case 'disabled':
                return StatusFormatter::CATEGORY_GROUP_DISABLED;
            default:
                $this->io()->error(sprintf(
                    'Invalid status "%s". Valid values: active, inactive, disabled.',
                    $status
                ));
                return false;
        }
    }

    /**
     * Parse the --sort option as a non-negative integer.
     * Returns 0 when absent, or false (after printing an error) when invalid.
     */
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

    private function slugify(string $title): string
    {
        $dir = preg_replace('/[^a-z0-9]+/', '-', strtolower($title));

        return trim((string) $dir, '-');
    }

    /**
     * Return a dir slug that is unique within categories_groups, appending a numeric
     * suffix when needed. $excludeId skips the row being updated.
     */
    private function resolveUniqueDir(\PDO $db, string $dir, ?int $excludeId): string
    {
        $candidate = $dir;
        $suffix = 2;
        while ($this->dirExists($db, $candidate, $excludeId)) {
            $candidate = $dir . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function dirExists(\PDO $db, string $dir, ?int $excludeId): bool
    {
        $sql = "SELECT category_group_id FROM {$this->table('categories_groups')} WHERE dir = :dir";
        $params = ['dir' => $dir];
        if ($excludeId !== null) {
            $sql .= ' AND category_group_id <> :id';
            $params['id'] = $excludeId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch() !== false;
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

    protected function deleteGroupFiles(string $groupId): void
    {
        $contentPath = $this->config->getCategoriesPath();
        if ($contentPath === '') {
            return;
        }

        $path = rtrim($contentPath, '/') . '/groups/' . $groupId;
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

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
