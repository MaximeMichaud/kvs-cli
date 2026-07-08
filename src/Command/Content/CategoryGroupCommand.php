<?php

namespace KVS\CLI\Command\Content;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Command\Traits\ToggleStatusTrait;
use KVS\CLI\Constants;
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

    /** @var list<string> */
    private const OUTPUT_FORMATS = ['table', 'csv', 'json', 'yaml', 'count', 'ids'];

    /** @var list<string> */
    private const DEFAULT_LIST_FIELDS = ['category_group_id', 'title', 'categories_amount', 'status', 'sort_id'];

    /** @var list<string> */
    private const FIELD_FILTER_COLUMNS = [
        'description',
        'screenshot1',
        'screenshot2',
        'custom1',
        'custom2',
        'custom3',
    ];

    /** @var array<string, string> */
    private const SORT_FIELDS = [
        'category_group_id' => 'g.category_group_id',
        'title' => 'g.title',
        'dir' => 'g.dir',
        'description' => 'g.description',
        'external_id' => 'g.external_id',
        'status_id' => 'g.status_id',
        'screenshot1' => 'g.screenshot1',
        'screenshot2' => 'g.screenshot2',
        'custom1' => 'g.custom1',
        'custom2' => 'g.custom2',
        'custom3' => 'g.custom3',
        'categories_amount' => 'categories_amount',
        'added_date' => 'g.added_date',
        'sort_id' => 'g.sort_id',
    ];

    /** @var list<string> */
    private const SHOW_UNSUPPORTED_OPTIONS = [
        'title',
        'description',
        'status',
        'external-id',
        'dir',
        'sort',
        'custom1',
        'custom2',
        'custom3',
        'screenshot1',
        'screenshot2',
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
  <comment>kvs category-group list --usage=used/categories --field-filter=filled/description</comment>
  <comment>kvs category-group list --sort-by=categories_amount --sort-dir=desc</comment>
  <comment>kvs category-group create "Genres" --description="Movie genres"</comment>
  <comment>kvs category-group create --title="Studios" --external-id=studios --custom1=featured --sort=5</comment>
  <comment>kvs category-group show 3</comment>
  <comment>kvs category-group update 3 --title="Movie Genres" --status=inactive</comment>
  <comment>kvs category-group update 3 --screenshot1=/tmp/avatar.jpg</comment>
  <comment>kvs category-group update 3 --screenshot1=</comment>
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
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Status (active|inactive|disabled|1|0)')
            ->addOption('external-id', null, InputOption::VALUE_REQUIRED, 'External ID (must be unique)')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Directory slug (auto-generated from title if omitted)')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'Manual sort order (sort_id, integer >= 0)')
            ->addOption('custom1', null, InputOption::VALUE_REQUIRED, 'Custom field 1')
            ->addOption('custom2', null, InputOption::VALUE_REQUIRED, 'Custom field 2')
            ->addOption('custom3', null, InputOption::VALUE_REQUIRED, 'Custom field 3')
            ->addOption('screenshot1', null, InputOption::VALUE_REQUIRED, 'Upload/replace screenshot 1 image; use empty value to clear on update')
            ->addOption('screenshot2', null, InputOption::VALUE_REQUIRED, 'Upload/replace screenshot 2 image; use empty value to clear on update')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results to show', Constants::DEFAULT_LIMIT)
            ->addOption(
                'search',
                null,
                InputOption::VALUE_REQUIRED,
                'Search in IDs, titles, dirs, descriptions, external IDs, custom fields, and screenshot filenames'
            )
            ->addOption('used', null, InputOption::VALUE_NONE, 'Show only category groups assigned to categories')
            ->addOption('unused', null, InputOption::VALUE_NONE, 'Show only category groups not assigned to categories')
            ->addOption('usage', null, InputOption::VALUE_REQUIRED, 'KVS admin usage filter (used/categories|notused/categories)')
            ->addOption('field-filter', null, InputOption::VALUE_REQUIRED, 'KVS admin field filter (e.g. filled/description)')
            ->addOption('sort-by', null, InputOption::VALUE_REQUIRED, 'Sort field (default: category_group_id)')
            ->addOption('sort-dir', null, InputOption::VALUE_REQUIRED, 'Sort direction: asc or desc (default: desc)')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to display')
            ->addOption('field', null, InputOption::VALUE_REQUIRED, 'Display single field from each item')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count, ids', 'table')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation of long text fields');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->validateOutputFormat($input, self::OUTPUT_FORMATS) === null) {
            return self::FAILURE;
        }

        $action = $this->getStringArgument($input, 'action') ?? 'list';
        $id = $this->getStringArgument($input, 'id');

        return match ($action) {
            'list' => $this->listGroups($input),
            'show' => $this->showGroup($id, $input),
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
        if ($this->rejectUnsupportedArgument($input, 'list', 'id', 'a category group ID or title argument', 'show', 'a specific category group')) {
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
                'active' => StatusFormatter::CATEGORY_GROUP_ACTIVE,
                'inactive' => StatusFormatter::CATEGORY_GROUP_DISABLED,
                'disabled' => StatusFormatter::CATEGORY_GROUP_DISABLED,
            ], [0, 1]);
            if ($statusId === false) {
                return self::FAILURE;
            }
            if ($statusId !== null) {
                $conditions[] = 'g.status_id = :status';
                $params['status'] = $statusId;
            }

            $search = $this->getStringOption($input, 'search');
            if ($search !== null) {
                $conditions[] = $this->buildAdminSearchCondition(
                    'g.category_group_id',
                    [
                        'g.title',
                        'g.dir',
                        'g.description',
                        'g.external_id',
                        'g.custom1',
                        'g.custom2',
                        'g.custom3',
                        'g.screenshot1',
                        'g.screenshot2',
                    ],
                    $search,
                    $params
                );
            }

            $fieldFilter = $this->getStringOption($input, 'field-filter');
            if ($fieldFilter !== null) {
                $condition = $this->getCategoryGroupFieldFilterCondition($fieldFilter);
                if ($condition === null) {
                    $this->io()->error(
                        'Invalid category group field filter. Use: ' . implode(', ', $this->getCategoryGroupFieldFilterValues())
                    );
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
                $conditions[] = $this->getCategoryGroupUsageCondition('used/categories');
            } elseif ($this->getBoolOption($input, 'unused')) {
                $conditions[] = $this->getCategoryGroupUsageCondition('notused/categories');
            } elseif ($usage !== null) {
                $condition = $this->getCategoryGroupUsageCondition($usage);
                if ($condition === null) {
                    $this->io()->error('Invalid category group usage filter. Use: used/categories or notused/categories');
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
                return $this->countGroups($db, $whereClause, $params);
            }

            $limit = $this->getPositiveIntOptionOrDefault($input, 'limit', Constants::DEFAULT_LIMIT);
            if ($limit === null) {
                return self::FAILURE;
            }

            $sortBy = $this->getCategoryGroupSortBy($input);
            if ($sortBy === null) {
                return self::FAILURE;
            }
            $sortDirection = $this->getCategoryGroupSortDirection($input);
            if ($sortDirection === null) {
                return self::FAILURE;
            }

            $categoriesTable = $this->table('categories');
            $sql = "
                SELECT g.*,
                       (SELECT COUNT(*) FROM {$categoriesTable} WHERE category_group_id = g.category_group_id) AS categories_amount
                FROM {$this->table('categories_groups')} g
                $whereClause
                ORDER BY {$sortBy} {$sortDirection}, g.category_group_id DESC
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
            $groups = array_map(fn (array $group): array => $this->enrichGroupRow($group), $groups);

            return $this->displayFormattedRows(
                $input,
                $groups,
                self::DEFAULT_LIST_FIELDS,
                $this->getCategoryGroupKnownFields($groups)
            );
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

    private function showGroup(?string $id, InputInterface $input): int
    {
        if ($this->rejectUnsupportedOptionsForAction($input, 'show', self::SHOW_UNSUPPORTED_OPTIONS)) {
            return self::FAILURE;
        }
        if ($this->rejectCountFormatForSingularAction($input, 'show')) {
            return self::FAILURE;
        }

        $groupId = $this->requireNumericId($id, 'show');
        if ($groupId === null) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $categoriesTable = $this->table('categories');
            $stmt = $db->prepare("
                SELECT g.*,
                       (SELECT COUNT(*) FROM {$categoriesTable} WHERE category_group_id = g.category_group_id) AS categories_amount
                FROM {$this->table('categories_groups')} g
                WHERE g.category_group_id = :id
            ");
            $stmt->execute(['id' => $groupId]);
            /** @var array<string, mixed>|false $group */
            $group = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($group === false) {
                $this->io()->error("Category group not found: $groupId");
                return self::FAILURE;
            }

            $group = $this->enrichGroupRow($group);
            $groupTitle = $this->stringValue($group['title'] ?? '');

            $statusId = isset($group['status_id']) && is_numeric($group['status_id']) ? (int) $group['status_id'] : 0;
            $externalId = $this->stringValue($group['external_id'] ?? '');
            $addedDate = $group['added_date'] ?? null;
            $categoriesAmount = $group['categories_amount'] ?? 0;

            $info = [
                ['ID', $this->stringValue($group['category_group_id'] ?? '0')],
                ['Title', $groupTitle],
                ['Dir', $this->stringValue($group['dir'] ?? '')],
                ['Status', StatusFormatter::categoryGroup($statusId)],
                ['External ID', $externalId !== '' ? $externalId : 'None'],
                ['Sort', $this->stringValue($group['sort_id'] ?? '0')],
                ['Categories', is_scalar($categoriesAmount) ? (string) $categoriesAmount : '0'],
                ['Added', is_string($addedDate) ? $addedDate : 'N/A'],
            ];

            $optionalFields = [
                'screenshot1' => 'Screenshot 1',
                'screenshot2' => 'Screenshot 2',
                'custom1' => 'Custom 1',
                'custom2' => 'Custom 2',
                'custom3' => 'Custom 3',
            ];
            foreach ($optionalFields as $field => $label) {
                $value = $this->stringValue($group[$field] ?? '');
                if ($value !== '') {
                    $info[] = [$label, $value];
                }
            }

            if ($this->shouldUseFormattedRows($input)) {
                return $this->displayDetailRows(
                    $input,
                    $info,
                    $this->getRequestedCategoryGroupDetailFields($input, $group)
                );
            }

            $this->io()->section("Category group: $groupTitle");
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

        $description = $this->getRawStringOptionOrDefault($input, 'description', '');
        $externalId = $this->getRawStringOptionOrDefault($input, 'external-id', '');
        $custom1 = $this->getRawStringOptionOrDefault($input, 'custom1', '');
        $custom2 = $this->getRawStringOptionOrDefault($input, 'custom2', '');
        $custom3 = $this->getRawStringOptionOrDefault($input, 'custom3', '');

        $dir = $this->resolveInputDir($this->getRawStringOption($input, 'dir'), $title);
        if ($dir === null) {
            return self::FAILURE;
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

            $screenshot1 = $this->prepareScreenshotUpload($input, 'screenshot1', 's1_');
            if ($screenshot1 === false) {
                return self::FAILURE;
            }
            $screenshot2 = $this->prepareScreenshotUpload($input, 'screenshot2', 's2_');
            if ($screenshot2 === false) {
                return self::FAILURE;
            }
            if ($screenshot1 !== null && $screenshot2 === null && $this->shouldAutoCreateSecondScreenshot($db)) {
                $screenshot2 = $this->duplicateScreenshotUpload($screenshot1, 's2_');
            }

            $table = $this->table('categories_groups');
            $dir = $this->resolveUniqueDir($db, $dir, null);
            $groupId = 0;
            $createdFiles = [];

            $db->beginTransaction();
            $this->relaxSqlMode($db);
            try {
                $stmt = $db->prepare("
                    INSERT INTO {$table}
                        (
                            title, dir, description, status_id, external_id,
                            screenshot1, screenshot2, custom1, custom2, custom3,
                            sort_id, added_date
                        )
                    VALUES
                        (
                            :title, :dir, :description, :status_id, :external_id,
                            :screenshot1, :screenshot2, :custom1, :custom2, :custom3,
                            :sort_id, :added_date
                        )
                ");
                $stmt->execute([
                    'title' => $title,
                    'dir' => $dir,
                    'description' => $description,
                    'status_id' => $statusId,
                    'external_id' => $externalId,
                    'screenshot1' => $screenshot1['filename'] ?? '',
                    'screenshot2' => $screenshot2['filename'] ?? '',
                    'custom1' => $custom1,
                    'custom2' => $custom2,
                    'custom3' => $custom3,
                    'sort_id' => $sortId,
                    'added_date' => date('Y-m-d H:i:s'),
                ]);

                $groupId = (int) $db->lastInsertId();
                $createdFiles = $this->installScreenshotUploads($groupId, [$screenshot1, $screenshot2]);
                $this->writeAdminAuditLog($db, 100, $groupId, Constants::OBJECT_TYPE_CATEGORY_GROUP);
                $this->restoreSqlMode($db);
                $db->commit();
            } catch (\Throwable $mutationError) {
                $this->restoreSqlMode($db);
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $this->removeFiles($createdFiles);
                if ($groupId > 0) {
                    $this->removeEmptyGroupDirectory($groupId);
                }
                throw $mutationError;
            }

            $this->io()->success('Category group created successfully!');
            $rows = [
                ['ID', (string) $groupId],
                ['Title', $title],
                ['Dir', $dir],
                ['Description', $description !== '' ? $description : 'None'],
                ['External ID', $externalId !== '' ? $externalId : 'None'],
                ['Sort', (string) $sortId],
                ['Status', StatusFormatter::categoryGroup($statusId, false)],
            ];
            foreach (['Custom 1' => $custom1, 'Custom 2' => $custom2, 'Custom 3' => $custom3] as $label => $value) {
                if ($value !== '') {
                    $rows[] = [$label, $value];
                }
            }
            foreach (['Screenshot 1' => $screenshot1['filename'] ?? '', 'Screenshot 2' => $screenshot2['filename'] ?? ''] as $label => $value) {
                if ($value !== '') {
                    $rows[] = [$label, $value];
                }
            }
            $this->renderTable(['Property', 'Value'], $rows);
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

            $this->writeAdminAuditLog($db, 180, $groupId, Constants::OBJECT_TYPE_CATEGORY_GROUP);
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
            $group = $this->fetchGroupById($db, $groupId);
            if ($group === null) {
                $this->io()->error("Category group not found: $groupId");
                return self::FAILURE;
            }

            $updates = [];
            $params = ['id' => $groupId];
            $changedFields = [];
            $newFiles = [];
            $oldFilesToRemove = [];

            if (!$this->collectScalarUpdates($db, $input, $group, $groupId, $updates, $params, $changedFields)) {
                return self::FAILURE;
            }

            $screenshotUpdate = $this->collectScreenshotUpdates(
                $db,
                $input,
                $group,
                $groupId,
                $updates,
                $params,
                $changedFields
            );
            if ($screenshotUpdate === false) {
                return self::FAILURE;
            }
            $screenshotUploads = $screenshotUpdate['uploads'];
            $oldFilesToRemove = $screenshotUpdate['old_files'];

            if ($updates === []) {
                $this->io()->warning(
                    'No changes specified. Use --title, --description, --dir, --external-id, --status, '
                    . '--sort, --custom1, --custom2, --custom3, --screenshot1, or --screenshot2 options.'
                );
                return self::FAILURE;
            }

            $db->beginTransaction();
            try {
                $newFiles = $this->installScreenshotUploads($groupId, array_values($screenshotUploads));
                $sql = "UPDATE {$this->table('categories_groups')} SET " . implode(', ', $updates) . " WHERE category_group_id = :id";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $this->writeAdminAuditLog(
                    $db,
                    150,
                    $groupId,
                    Constants::OBJECT_TYPE_CATEGORY_GROUP,
                    implode(', ', array_values(array_unique($changedFields)))
                );
                $db->commit();
            } catch (\Throwable $mutationError) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $this->removeFiles($newFiles);
                throw $mutationError;
            }

            try {
                $this->removeFiles(array_values(array_diff(array_unique($oldFilesToRemove), $newFiles)));
            } catch (\Throwable $cleanupError) {
                $this->io()->warning('Category group updated, but old screenshot cleanup failed: ' . $cleanupError->getMessage());
            }

            $this->io()->success('Category group updated successfully!');

            return $this->showGroup((string) $groupId, $input);
        } catch (\Exception $e) {
            $this->io()->error('Failed to update category group: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchGroupById(\PDO $db, int $groupId): ?array
    {
        $stmt = $db->prepare("SELECT * FROM {$this->table('categories_groups')} WHERE category_group_id = :id");
        $stmt->execute(['id' => $groupId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $group = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $group[$key] = $value;
            }
        }

        return $group;
    }

    /**
     * @param array<string, mixed> $group
     * @param list<string> $updates
     * @param array<string, mixed> $params
     * @param list<string> $changedFields
     */
    private function collectScalarUpdates(
        \PDO $db,
        InputInterface $input,
        array $group,
        int $groupId,
        array &$updates,
        array &$params,
        array &$changedFields
    ): bool {
        $title = $this->getRawStringOption($input, 'title');
        if ($title !== null && !$this->queueTitleUpdate($db, $group, $groupId, $title, $updates, $params, $changedFields)) {
            return false;
        }

        $description = $this->getRawStringOption($input, 'description');
        if ($description !== null) {
            $this->queueScalarUpdate($updates, $params, $changedFields, $group, 'description', $description);
        }

        $dirInput = $this->getRawStringOption($input, 'dir');
        if ($dirInput !== null) {
            $baseTitle = $title ?? $this->stringValue($group['title'] ?? '');
            $normalizedDir = $this->resolveInputDir($dirInput, $baseTitle);
            if ($normalizedDir === null) {
                return false;
            }
            $newDir = $this->resolveUniqueDir($db, $normalizedDir, $groupId);
            $this->queueScalarUpdate($updates, $params, $changedFields, $group, 'dir', $newDir);
        }

        $externalId = $this->getRawStringOption($input, 'external-id');
        if ($externalId !== null && !$this->queueExternalIdUpdate($db, $group, $groupId, $externalId, $updates, $params, $changedFields)) {
            return false;
        }

        $status = $this->getStatusOption($input);
        if ($status === false) {
            return false;
        }
        if ($status !== null) {
            $this->queueScalarUpdate($updates, $params, $changedFields, $group, 'status_id', $status);
        }

        if ($this->getRawStringOption($input, 'sort') !== null) {
            $sortId = $this->parseSortId($input);
            if ($sortId === false) {
                return false;
            }
            $this->queueScalarUpdate($updates, $params, $changedFields, $group, 'sort_id', $sortId);
        }

        foreach (['custom1', 'custom2', 'custom3'] as $customField) {
            $customValue = $this->getRawStringOption($input, $customField);
            if ($customValue !== null) {
                $this->queueScalarUpdate($updates, $params, $changedFields, $group, $customField, $customValue);
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $group
     * @param list<string> $updates
     * @param array<string, mixed> $params
     * @param list<string> $changedFields
     */
    private function queueTitleUpdate(
        \PDO $db,
        array $group,
        int $groupId,
        string $title,
        array &$updates,
        array &$params,
        array &$changedFields
    ): bool {
        if ($title === '') {
            $this->io()->error('Category group title cannot be empty');
            return false;
        }
        if ($title === $this->stringValue($group['title'] ?? '')) {
            return true;
        }

        $stmt = $db->prepare(
            "SELECT category_group_id FROM {$this->table('categories_groups')}
             WHERE title = :title AND category_group_id <> :id"
        );
        $stmt->execute(['title' => $title, 'id' => $groupId]);
        if ($stmt->fetch() !== false) {
            $this->io()->error("Category group already exists: $title");
            return false;
        }
        $this->queueScalarUpdate($updates, $params, $changedFields, $group, 'title', $title);

        return true;
    }

    /**
     * @param array<string, mixed> $group
     * @param list<string> $updates
     * @param array<string, mixed> $params
     * @param list<string> $changedFields
     */
    private function queueExternalIdUpdate(
        \PDO $db,
        array $group,
        int $groupId,
        string $externalId,
        array &$updates,
        array &$params,
        array &$changedFields
    ): bool {
        if ($externalId !== '' && $externalId !== $this->stringValue($group['external_id'] ?? '')) {
            $stmt = $db->prepare(
                "SELECT category_group_id FROM {$this->table('categories_groups')}
                 WHERE external_id = :external_id AND category_group_id <> :id"
            );
            $stmt->execute(['external_id' => $externalId, 'id' => $groupId]);
            if ($stmt->fetch() !== false) {
                $this->io()->error("Category group with external ID already exists: $externalId");
                return false;
            }
        }

        $this->queueScalarUpdate($updates, $params, $changedFields, $group, 'external_id', $externalId);

        return true;
    }

    /**
     * @param array<string, mixed> $group
     * @param list<string> $updates
     * @param array<string, mixed> $params
     * @param list<string> $changedFields
     * @return array{uploads: array<string, array{source: string, filename: string}>, old_files: list<string>}|false
     */
    private function collectScreenshotUpdates(
        \PDO $db,
        InputInterface $input,
        array $group,
        int $groupId,
        array &$updates,
        array &$params,
        array &$changedFields
    ): array|false {
        $screenshotUploads = [];
        $oldFilesToRemove = [];

        foreach (['screenshot1' => 's1_', 'screenshot2' => 's2_'] as $field => $prefix) {
            $rawScreenshot = $this->getRawStringOption($input, $field);
            if ($rawScreenshot === null) {
                continue;
            }

            if ($rawScreenshot === '') {
                $this->queueScreenshotClear($group, $groupId, $field, $updates, $params, $changedFields, $oldFilesToRemove);
                continue;
            }

            $upload = $this->prepareScreenshotUpload($input, $field, $prefix);
            if ($upload === false) {
                return false;
            }
            if ($upload !== null) {
                $this->queueScreenshotUpload(
                    $group,
                    $groupId,
                    $field,
                    $upload,
                    $updates,
                    $params,
                    $changedFields,
                    $oldFilesToRemove
                );
                $screenshotUploads[$field] = $upload;
            }
        }

        if (
            isset($screenshotUploads['screenshot1'])
            && !isset($screenshotUploads['screenshot2'])
            && $this->getRawStringOption($input, 'screenshot2') === null
            && $this->shouldAutoCreateSecondScreenshot($db)
        ) {
            $upload = $this->duplicateScreenshotUpload($screenshotUploads['screenshot1'], 's2_');
            $this->queueScreenshotUpload(
                $group,
                $groupId,
                'screenshot2',
                $upload,
                $updates,
                $params,
                $changedFields,
                $oldFilesToRemove
            );
            $screenshotUploads['screenshot2'] = $upload;
        }

        return [
            'uploads' => $screenshotUploads,
            'old_files' => $oldFilesToRemove,
        ];
    }

    /**
     * @param array<string, mixed> $group
     * @param list<string> $updates
     * @param array<string, mixed> $params
     * @param list<string> $changedFields
     * @param list<string> $oldFilesToRemove
     */
    private function queueScreenshotClear(
        array $group,
        int $groupId,
        string $field,
        array &$updates,
        array &$params,
        array &$changedFields,
        array &$oldFilesToRemove
    ): void {
        $oldFilename = $this->stringValue($group[$field] ?? '');
        if ($oldFilename !== '') {
            $oldFilesToRemove[] = $this->getGroupFilePath($groupId, $oldFilename);
        }
        $updates[] = "{$field} = :{$field}";
        $params[$field] = '';
        $changedFields[] = $field;
    }

    /**
     * @param array<string, mixed> $group
     * @param array{source: string, filename: string} $upload
     * @param list<string> $updates
     * @param array<string, mixed> $params
     * @param list<string> $changedFields
     * @param list<string> $oldFilesToRemove
     */
    private function queueScreenshotUpload(
        array $group,
        int $groupId,
        string $field,
        array $upload,
        array &$updates,
        array &$params,
        array &$changedFields,
        array &$oldFilesToRemove
    ): void {
        $oldFilename = $this->stringValue($group[$field] ?? '');
        if ($oldFilename !== '') {
            $oldFilesToRemove[] = $this->getGroupFilePath($groupId, $oldFilename);
        }
        $updates[] = "{$field} = :{$field}";
        $params[$field] = $upload['filename'];
        $changedFields[] = $field;
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
        if (preg_match('/^[1-9]\d*$/', $id) !== 1) {
            $this->io()->error('Invalid Category group ID (use: integer >= 1)');
            return null;
        }

        return (int) $id;
    }

    /**
     * @param list<array<string, mixed>> $groups
     * @return list<string>
     */
    private function getCategoryGroupKnownFields(array $groups): array
    {
        $fields = [
            'id',
            'category_group_id',
            'title',
            'dir',
            'description',
            'external_id',
            'status_id',
            'status',
            'screenshot1',
            'screenshot1_url',
            'screenshot2',
            'screenshot2_url',
            'thumb',
            'custom1',
            'custom2',
            'custom3',
            'categories_amount',
            'category_count',
            'added_date',
            'sort_id',
            'is_avatar_available',
        ];

        foreach ($groups as $group) {
            foreach (array_keys($group) as $field) {
                $fields[] = $field;
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private function enrichGroupRow(array $group): array
    {
        $groupIdValue = $group['category_group_id'] ?? 0;
        $groupId = is_numeric($groupIdValue) ? (int) $groupIdValue : 0;
        $statusIdValue = $group['status_id'] ?? 0;
        $statusId = is_numeric($statusIdValue) ? (int) $statusIdValue : 0;
        $categoriesAmountValue = $group['categories_amount'] ?? ($group['category_count'] ?? 0);
        $categoriesAmount = is_numeric($categoriesAmountValue) ? (int) $categoriesAmountValue : 0;

        $group['id'] = $groupId;
        $group['category_group_id'] = $groupId;
        $group['status_id'] = $statusId;
        $group['status'] = StatusFormatter::categoryGroup($statusId, false);
        $group['categories_amount'] = $categoriesAmount;
        $group['category_count'] = $categoriesAmount;
        $group['is_avatar_available'] = $this->stringValue($group['screenshot1'] ?? '') !== '' ? 1 : 0;

        $baseUrl = rtrim($this->stringValue($this->config->get('content_url_categories', '')), '/');
        foreach (['screenshot1', 'screenshot2'] as $field) {
            $filename = $this->stringValue($group[$field] ?? '');
            if ($filename !== '' && $baseUrl !== '' && $groupId > 0) {
                $group["{$field}_url"] = "{$baseUrl}/groups/{$groupId}/{$filename}";
            } else {
                $group["{$field}_url"] = '';
            }
        }
        $group['thumb'] = $group['screenshot1_url'] !== '' ? $group['screenshot1_url'] : $group['screenshot2_url'];

        return $group;
    }

    /**
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private function getRequestedCategoryGroupDetailFields(InputInterface $input, array $group): array
    {
        $fields = [];
        foreach ($this->getCategoryGroupKnownFields([$group]) as $field) {
            $fields[$field] = $group[$field] ?? '';
        }

        return $this->getRequestedDetailFields($input, $fields);
    }

    private function getCategoryGroupFieldFilterCondition(string $fieldFilter): ?string
    {
        if (preg_match('~^(empty|filled)/([a-z0-9_]+)$~', $fieldFilter, $matches) !== 1) {
            return null;
        }

        $mode = $matches[1];
        $field = $matches[2];
        if (!in_array($field, self::FIELD_FILTER_COLUMNS, true)) {
            return null;
        }

        return $mode === 'empty' ? "g.{$field} = ''" : "g.{$field} != ''";
    }

    /**
     * @return list<string>
     */
    private function getCategoryGroupFieldFilterValues(): array
    {
        $values = [];
        foreach (self::FIELD_FILTER_COLUMNS as $field) {
            $values[] = "empty/{$field}";
            $values[] = "filled/{$field}";
        }

        return $values;
    }

    private function getCategoryGroupUsageCondition(string $usage): ?string
    {
        $categoriesTable = $this->table('categories');

        return match ($usage) {
            'used/categories' => "EXISTS (SELECT 1 FROM {$categoriesTable} c_usage WHERE c_usage.category_group_id = g.category_group_id)",
            'notused/categories' => "NOT EXISTS (SELECT 1 FROM {$categoriesTable} c_usage WHERE c_usage.category_group_id = g.category_group_id)",
            default => null,
        };
    }

    private function getCategoryGroupSortBy(InputInterface $input): ?string
    {
        $sortBy = $this->getStringOption($input, 'sort-by') ?? 'category_group_id';
        if (!isset(self::SORT_FIELDS[$sortBy])) {
            $this->io()->error('Invalid category group sort field. Use: ' . implode(', ', array_keys(self::SORT_FIELDS)));
            return null;
        }

        return self::SORT_FIELDS[$sortBy];
    }

    private function getCategoryGroupSortDirection(InputInterface $input): ?string
    {
        $direction = strtolower($this->getStringOption($input, 'sort-dir') ?? 'desc');
        if (!in_array($direction, ['asc', 'desc'], true)) {
            $this->io()->error('Invalid category group sort direction. Use: asc or desc');
            return null;
        }

        return strtoupper($direction);
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
            case '1':
                return StatusFormatter::CATEGORY_GROUP_ACTIVE;
            case 'inactive':
            case 'disabled':
            case '0':
                return StatusFormatter::CATEGORY_GROUP_DISABLED;
            default:
                $this->io()->error(sprintf(
                    'Invalid status "%s". Valid values: active, inactive, disabled, 1, 0.',
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

    private function resolveInputDir(?string $dirInput, string $title): ?string
    {
        $source = $dirInput === null || $dirInput === '' ? $title : $dirInput;
        $dir = $this->slugify($source);
        if ($dir === '') {
            if ($dirInput !== null && $dirInput !== '') {
                $this->io()->error('The provided --dir does not contain any usable slug characters.');
                return null;
            }

            return 'group';
        }

        return $dir;
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
            $candidate = $dir . $suffix;
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

    /**
     * @return array{source: string, filename: string}|false|null
     */
    private function prepareScreenshotUpload(InputInterface $input, string $option, string $prefix): array|false|null
    {
        $path = $this->getRawStringOption($input, $option);
        if ($path === null || $path === '') {
            return null;
        }

        if (!is_file($path) || !is_readable($path)) {
            $this->io()->error(sprintf('The --%s file does not exist or is not readable: %s', $option, $path));
            return false;
        }

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

        $basename = pathinfo($path, PATHINFO_FILENAME);
        $safeName = $this->slugifyFileName($basename);
        if ($safeName === '') {
            $safeName = $option;
        }

        $realPath = realpath($path);

        return [
            'source' => $realPath !== false ? $realPath : $path,
            'filename' => "{$prefix}{$safeName}.{$extension}",
        ];
    }

    /**
     * @param array{source: string, filename: string} $upload
     * @return array{source: string, filename: string}
     */
    private function duplicateScreenshotUpload(array $upload, string $prefix): array
    {
        $filename = preg_replace('/^s[12]_/', '', $upload['filename']) ?? $upload['filename'];

        return [
            'source' => $upload['source'],
            'filename' => $prefix . $filename,
        ];
    }

    /**
     * @param list<array{source: string, filename: string}|null> $uploads
     * @return list<string>
     */
    private function installScreenshotUploads(int $groupId, array $uploads): array
    {
        $created = [];
        foreach ($uploads as $upload) {
            if ($upload === null) {
                continue;
            }

            $target = $this->getGroupFilePath($groupId, $upload['filename']);
            $this->ensureDirectory(dirname($target));
            if (!@copy($upload['source'], $target)) {
                throw new \RuntimeException(sprintf('Could not copy screenshot to %s', $target));
            }
            @chmod($target, 0666);
            $created[] = $target;
        }

        return $created;
    }

    private function getGroupFilePath(int $groupId, string $filename): string
    {
        $contentPath = $this->config->getCategoriesPath();
        if ($contentPath === '') {
            throw new \RuntimeException('KVS categories content path is not configured');
        }

        return rtrim($contentPath, '/') . '/groups/' . $groupId . '/' . $filename;
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

    private function removeEmptyGroupDirectory(int $groupId): void
    {
        $contentPath = $this->config->getCategoriesPath();
        if ($contentPath === '') {
            return;
        }

        $path = rtrim($contentPath, '/') . '/groups/' . $groupId;
        if (is_dir($path)) {
            @rmdir($path);
        }
    }

    /**
     * @return list<string>
     */
    private function getAllowedImageExtensions(): array
    {
        $configured = $this->config->get('image_allowed_ext', 'jpg,jpeg,png,gif,webp');
        $extensions = is_string($configured) ? explode(',', $configured) : ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        $normalized = array_map(
            static fn (string $extension): string => strtolower(trim($extension)),
            $extensions
        );

        return array_values(array_unique(array_filter(
            $normalized,
            static fn (string $extension): bool => $extension !== ''
        )));
    }

    private function slugifyFileName(string $name): string
    {
        $slug = preg_replace('/[^a-z0-9._-]+/', '-', strtolower($name));

        return trim((string) $slug, '-_.');
    }

    private function shouldAutoCreateSecondScreenshot(\PDO $db): bool
    {
        return $this->getKvsOption($db, 'CATEGORY_AVATAR_OPTION') === '1';
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
