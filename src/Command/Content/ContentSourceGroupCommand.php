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
    name: 'content:content-source-group',
    description: 'Manage KVS content source groups',
    aliases: ['content-source-group', 'content-source-groups', 'source-group', 'source-groups', 'csgroup']
)]
class ContentSourceGroupCommand extends BaseCommand
{
    use ToggleStatusTrait;

    /** @var list<string> */
    private const OUTPUT_FORMATS = ['table', 'csv', 'json', 'yaml', 'count', 'ids'];

    /** @var list<string> */
    private const DEFAULT_LIST_FIELDS = [
        'content_source_group_id',
        'title',
        'content_sources_amount',
        'status',
        'sort_id',
    ];

    /** @var list<string> */
    private const CUSTOM_FIELDS = ['custom1', 'custom2', 'custom3', 'custom4', 'custom5'];

    /** @var list<string> */
    private const FIELD_FILTER_COLUMNS = [
        'description',
        'custom1',
        'custom2',
        'custom3',
        'custom4',
        'custom5',
    ];

    /** @var array<string, string> */
    private const SORT_FIELDS = [
        'content_source_group_id' => 'g.content_source_group_id',
        'title' => 'g.title',
        'dir' => 'g.dir',
        'description' => 'g.description',
        'external_id' => 'g.external_id',
        'status_id' => 'g.status_id',
        'custom1' => 'g.custom1',
        'custom2' => 'g.custom2',
        'custom3' => 'g.custom3',
        'custom4' => 'g.custom4',
        'custom5' => 'g.custom5',
        'content_sources_amount' => 'content_sources_amount',
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
        'custom4',
        'custom5',
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
Manage KVS content source groups with full CRUD operations.

Content source groups organize content sources (Sites in the KVS admin).
This command manages the groups themselves (table content_sources_groups).

<info>ACTIONS:</info>
  list              List all content source groups (default)
  show <id>         Show content source group details
  create <title>    Create new content source group
  delete <id>       Delete content source group (detaches its content sources)
  update <id>       Update content source group properties
  enable <id>       Enable content source group
  disable <id>      Disable content source group

<info>EXAMPLES:</info>
  <comment>kvs content-source-group list</comment>
  <comment>kvs content-source-group list --search=Studios --status=active</comment>
  <comment>kvs content-source-group list --usage=used/content_sources --field-filter=filled/description</comment>
  <comment>kvs content-source-group list --sort-by=content_sources_amount --sort-dir=desc</comment>
  <comment>kvs content-source-group create "Studios" --description="Source studios"</comment>
  <comment>kvs content-source-group create --title="Partners" --external-id=partners --custom1=featured --sort=5</comment>
  <comment>kvs content-source-group update 3 --title="Partner Sites" --status=inactive</comment>
  <comment>kvs content-source-group enable 3</comment>
  <comment>kvs content-source-group disable 3</comment>
  <comment>kvs content-source-group delete 3</comment>
HELP
            )
            ->addArgument(
                'action',
                InputArgument::OPTIONAL,
                'Action: list, show, create, delete, update, enable, disable',
                'list'
            )
            ->addArgument('id', InputArgument::OPTIONAL, 'Content source group ID, or title when creating')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Content source group title')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Content source group description')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Status (active|inactive|disabled|1|0)')
            ->addOption('external-id', null, InputOption::VALUE_REQUIRED, 'External ID (must be unique)')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Directory slug (auto-generated from title if omitted)')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'Manual sort order (sort_id, integer >= 0)')
            ->addOption('custom1', null, InputOption::VALUE_REQUIRED, 'Custom field 1')
            ->addOption('custom2', null, InputOption::VALUE_REQUIRED, 'Custom field 2')
            ->addOption('custom3', null, InputOption::VALUE_REQUIRED, 'Custom field 3')
            ->addOption('custom4', null, InputOption::VALUE_REQUIRED, 'Custom field 4')
            ->addOption('custom5', null, InputOption::VALUE_REQUIRED, 'Custom field 5')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results to show', Constants::DEFAULT_LIMIT)
            ->addOption(
                'search',
                null,
                InputOption::VALUE_REQUIRED,
                'Search in IDs, titles, dirs, descriptions, external IDs, and custom fields'
            )
            ->addOption('used', null, InputOption::VALUE_NONE, 'Show only groups assigned to content sources')
            ->addOption('unused', null, InputOption::VALUE_NONE, 'Show only groups not assigned to content sources')
            ->addOption(
                'usage',
                null,
                InputOption::VALUE_REQUIRED,
                'KVS admin usage filter (used/content_sources|notused/content_sources)'
            )
            ->addOption('field-filter', null, InputOption::VALUE_REQUIRED, 'KVS admin field filter (e.g. filled/description)')
            ->addOption('sort-by', null, InputOption::VALUE_REQUIRED, 'Sort field (default: content_source_group_id)')
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
                'content-source-group',
                $action,
                ['list', 'show', 'create', 'delete', 'update', 'enable', 'disable']
            ),
        };
    }

    private function listGroups(InputInterface $input): int
    {
        if (
            $this->rejectUnsupportedArgument(
                $input,
                'list',
                'id',
                'a content source group ID or title argument',
                'show',
                'a specific content source group'
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
                'active' => StatusFormatter::CONTENT_SOURCE_GROUP_ACTIVE,
                'inactive' => StatusFormatter::CONTENT_SOURCE_GROUP_DISABLED,
                'disabled' => StatusFormatter::CONTENT_SOURCE_GROUP_DISABLED,
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
                    'g.content_source_group_id',
                    [
                        'g.title',
                        'g.dir',
                        'g.description',
                        'g.external_id',
                        'g.custom1',
                        'g.custom2',
                        'g.custom3',
                        'g.custom4',
                        'g.custom5',
                    ],
                    $search,
                    $params
                );
            }

            $fieldFilter = $this->getStringOption($input, 'field-filter');
            if ($fieldFilter !== null) {
                $condition = $this->getFieldFilterCondition($fieldFilter);
                if ($condition === null) {
                    $this->io()->error('Invalid content source group field filter. Use: ' . implode(', ', $this->getFieldFilterValues()));
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
                $conditions[] = $this->getUsageCondition('used/content_sources');
            } elseif ($this->getBoolOption($input, 'unused')) {
                $conditions[] = $this->getUsageCondition('notused/content_sources');
            } elseif ($usage !== null) {
                $condition = $this->getUsageCondition($usage);
                if ($condition === null) {
                    $this->io()->error('Invalid content source group usage filter. Use: used/content_sources or notused/content_sources');
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

            $sortBy = $this->getSortBy($input);
            if ($sortBy === null) {
                return self::FAILURE;
            }
            $sortDirection = $this->getSortDirection($input);
            if ($sortDirection === null) {
                return self::FAILURE;
            }

            $sourcesTable = $this->table('content_sources');
            $sql = "
                SELECT g.*,
                       (SELECT COUNT(*) FROM {$sourcesTable} WHERE content_source_group_id = g.content_source_group_id) AS content_sources_amount
                FROM {$this->table('content_sources_groups')} g
                $whereClause
                ORDER BY {$sortBy} {$sortDirection}, g.content_source_group_id DESC
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
                $this->getKnownFields($groups)
            );
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch content source groups: ' . $e->getMessage());
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
                FROM {$this->table('content_sources_groups')} g
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
            $this->io()->error('Failed to count content source groups: ' . $e->getMessage());
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
            $group = $this->fetchGroupWithCounts($db, $groupId);
            if ($group === null) {
                $this->io()->error("Content source group not found: $groupId");
                return self::FAILURE;
            }

            $group = $this->enrichGroupRow($group);
            $title = $this->stringValue($group['title'] ?? '');
            $statusId = is_numeric($group['status_id'] ?? null) ? (int) $group['status_id'] : 0;
            $contentSourcesAmount = is_numeric($group['content_sources_amount'] ?? null)
                ? (int) $group['content_sources_amount']
                : 0;

            $info = [
                ['ID', $this->stringValue($group['content_source_group_id'] ?? '0')],
                ['Title', $title],
                ['Dir', $this->stringValue($group['dir'] ?? '')],
                ['Status', StatusFormatter::contentSourceGroup($statusId)],
                ['External ID', $this->stringValue($group['external_id'] ?? '') !== ''
                    ? $this->stringValue($group['external_id'] ?? '')
                    : 'None'],
                ['Sort', $this->stringValue($group['sort_id'] ?? '0')],
                ['Content sources', (string) $contentSourcesAmount],
                ['Added', $this->stringValue($group['added_date'] ?? 'N/A') !== ''
                    ? $this->stringValue($group['added_date'] ?? 'N/A')
                    : 'N/A'],
            ];

            foreach (self::CUSTOM_FIELDS as $field) {
                $value = $this->stringValue($group[$field] ?? '');
                if ($value !== '') {
                    $info[] = [ucfirst(str_replace('custom', 'Custom ', $field)), $value];
                }
            }

            if ($this->shouldUseFormattedRows($input)) {
                return $this->displayDetailRows($input, $info, $this->getRequestedDetailFieldsForGroup($input, $group));
            }

            $this->io()->section("Content source group: $title");
            $this->renderTable(['Property', 'Value'], $info);

            $description = $group['description'] ?? null;
            if ($description !== null && $description !== '' && is_scalar($description)) {
                $this->io()->section('Description');
                $this->io()->text((string) $description);
            }
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch content source group: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function createGroup(InputInterface $input): int
    {
        $title = $this->getStringOption($input, 'title') ?? $this->getStringArgument($input, 'id');
        if ($title === null || $title === '') {
            $this->io()->error('Content source group title is required');
            $this->io()->text('Usage: kvs content-source-group create "Group Name"');
            return self::FAILURE;
        }

        $statusId = $this->getStatusOption($input);
        if ($statusId === false) {
            return self::FAILURE;
        }
        $statusId ??= StatusFormatter::CONTENT_SOURCE_GROUP_ACTIVE;

        $sortId = $this->parseSortId($input);
        if ($sortId === false) {
            return self::FAILURE;
        }

        $dir = $this->resolveInputDir($this->getRawStringOption($input, 'dir'), $title, 'group');
        if ($dir === null) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            if ($this->groupTitleExists($db, $title, null)) {
                $this->io()->error("Content source group already exists: $title");
                return self::FAILURE;
            }

            $externalId = $this->getRawStringOptionOrDefault($input, 'external-id', '');
            if ($externalId !== '' && $this->groupExternalIdExists($db, $externalId, null)) {
                $this->io()->error("Content source group with external ID already exists: $externalId");
                return self::FAILURE;
            }

            $dir = $this->resolveUniqueDir($db, $dir, null);
            $description = $this->getRawStringOptionOrDefault($input, 'description', '');
            $customValues = $this->collectCustomValues($input, []);

            $db->beginTransaction();
            $this->relaxSqlMode($db);
            try {
                $stmt = $db->prepare("
                    INSERT INTO {$this->table('content_sources_groups')}
                        (
                            title, dir, description, status_id, external_id,
                            custom1, custom2, custom3, custom4, custom5,
                            sort_id, added_date
                        )
                    VALUES
                        (
                            :title, :dir, :description, :status_id, :external_id,
                            :custom1, :custom2, :custom3, :custom4, :custom5,
                            :sort_id, :added_date
                        )
                ");
                $stmt->execute([
                    'title' => $title,
                    'dir' => $dir,
                    'description' => $description,
                    'status_id' => $statusId,
                    'external_id' => $externalId,
                    'custom1' => $customValues['custom1'],
                    'custom2' => $customValues['custom2'],
                    'custom3' => $customValues['custom3'],
                    'custom4' => $customValues['custom4'],
                    'custom5' => $customValues['custom5'],
                    'sort_id' => $sortId,
                    'added_date' => date('Y-m-d H:i:s'),
                ]);

                $groupId = (int) $db->lastInsertId();
                $this->writeAdminAuditLog($db, 100, $groupId, Constants::OBJECT_TYPE_CONTENT_SOURCE_GROUP);
                $this->restoreSqlMode($db);
                $db->commit();
            } catch (\Throwable $mutationError) {
                $this->restoreSqlMode($db);
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $mutationError;
            }

            $this->io()->success('Content source group created successfully!');
            $this->renderTable(['Property', 'Value'], [
                ['ID', (string) $groupId],
                ['Title', $title],
                ['Dir', $dir],
                ['Description', $description !== '' ? $description : 'None'],
                ['External ID', $externalId !== '' ? $externalId : 'None'],
                ['Sort', (string) $sortId],
                ['Status', StatusFormatter::contentSourceGroup($statusId, false)],
            ]);
        } catch (\Exception $e) {
            $this->io()->error('Failed to create content source group: ' . $e->getMessage());
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

        try {
            $group = $this->fetchGroupById($db, $groupId);
            if ($group === null) {
                $this->io()->error("Content source group not found: $groupId");
                return self::FAILURE;
            }

            $sourceIds = $this->contentSourceIdsInGroup($db, $groupId);
            if ($sourceIds !== []) {
                $sourceCount = count($sourceIds);
                $this->io()->warning("This group contains $sourceCount content sources.");
                $this->io()->text('They will be detached (content_source_group_id set to 0), not deleted.');

                if ($this->io()->confirm('Delete anyway?', false) !== true) {
                    if (!$input->isInteractive()) {
                        $this->io()->error('Content source group deletion cancelled because confirmation was not provided.');
                        return self::FAILURE;
                    }

                    $this->io()->info('Operation cancelled');
                    return self::SUCCESS;
                }
            }

            $db->beginTransaction();

            $lockStmt = $db->prepare(
                "SELECT content_source_group_id FROM {$this->table('content_sources_groups')} WHERE content_source_group_id = :id"
                . $this->rowLockClause($db)
            );
            $lockStmt->execute(['id' => $groupId]);
            if ($lockStmt->fetch() === false) {
                $db->rollBack();
                $this->io()->error("Content source group not found: $groupId");
                return self::FAILURE;
            }

            if ($this->contentSourceIdsInGroup($db, $groupId) !== $sourceIds) {
                $db->rollBack();
                $this->io()->warning(
                    'The set of content sources in this group changed since you reviewed it. '
                    . 'Re-run the delete to review and confirm.'
                );
                return self::FAILURE;
            }

            $this->writeAdminAuditLog($db, 180, $groupId, Constants::OBJECT_TYPE_CONTENT_SOURCE_GROUP);
            $db->prepare("UPDATE {$this->table('content_sources')} SET content_source_group_id = 0 WHERE content_source_group_id = :id")
                ->execute(['id' => $groupId]);
            $db->prepare("DELETE FROM {$this->table('content_sources_groups')} WHERE content_source_group_id = :id")
                ->execute(['id' => $groupId]);
            $db->commit();

            $deletedTitle = $this->stringValue($group['title'] ?? '');
            $this->io()->success("Content source group '$deletedTitle' deleted successfully!");
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->io()->error('Failed to delete content source group: ' . $e->getMessage());
            return self::FAILURE;
        }

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
                $this->io()->error("Content source group not found: $groupId");
                return self::FAILURE;
            }

            $updates = [];
            $params = ['id' => $groupId];
            $changedFields = [];

            if (!$this->collectScalarUpdates($db, $input, $group, $groupId, $updates, $params, $changedFields)) {
                return self::FAILURE;
            }

            if ($updates === []) {
                $this->io()->warning(
                    'No changes specified. Use --title, --description, --dir, --external-id, --status, '
                    . '--sort, --custom1, --custom2, --custom3, --custom4, or --custom5 options.'
                );
                return self::FAILURE;
            }

            $stmt = $db->prepare("
                UPDATE {$this->table('content_sources_groups')}
                SET " . implode(', ', $updates) . '
                WHERE content_source_group_id = :id
            ');
            $stmt->execute($params);
            $this->writeAdminAuditLog(
                $db,
                150,
                $groupId,
                Constants::OBJECT_TYPE_CONTENT_SOURCE_GROUP,
                implode(', ', array_values(array_unique($changedFields)))
            );

            $this->io()->success('Content source group updated successfully!');

            return $this->showGroup((string) $groupId, $input);
        } catch (\Exception $e) {
            $this->io()->error('Failed to update content source group: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchGroupById(\PDO $db, int $groupId): ?array
    {
        $stmt = $db->prepare("SELECT * FROM {$this->table('content_sources_groups')} WHERE content_source_group_id = :id");
        $stmt->execute(['id' => $groupId]);
        return $this->fetchAssoc($stmt);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchGroupWithCounts(\PDO $db, int $groupId): ?array
    {
        $sourcesTable = $this->table('content_sources');
        $stmt = $db->prepare("
            SELECT g.*,
                   (SELECT COUNT(*) FROM {$sourcesTable} WHERE content_source_group_id = g.content_source_group_id) AS content_sources_amount
            FROM {$this->table('content_sources_groups')} g
            WHERE g.content_source_group_id = :id
        ");
        $stmt->execute(['id' => $groupId]);
        return $this->fetchAssoc($stmt);
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
        if ($title !== null) {
            if ($title === '') {
                $this->io()->error('Content source group title cannot be empty');
                return false;
            }
            if ($title !== $this->stringValue($group['title'] ?? '') && $this->groupTitleExists($db, $title, $groupId)) {
                $this->io()->error("Content source group already exists: $title");
                return false;
            }
            $this->queueScalarUpdate($updates, $params, $changedFields, $group, 'title', $title);
        }

        $description = $this->getRawStringOption($input, 'description');
        if ($description !== null) {
            $this->queueScalarUpdate($updates, $params, $changedFields, $group, 'description', $description);
        }

        $dirInput = $this->getRawStringOption($input, 'dir');
        if ($dirInput !== null) {
            $baseTitle = $title ?? $this->stringValue($group['title'] ?? '');
            $normalizedDir = $this->resolveInputDir($dirInput, $baseTitle, 'group');
            if ($normalizedDir === null) {
                return false;
            }
            $this->queueScalarUpdate(
                $updates,
                $params,
                $changedFields,
                $group,
                'dir',
                $this->resolveUniqueDir($db, $normalizedDir, $groupId)
            );
        }

        $externalId = $this->getRawStringOption($input, 'external-id');
        if ($externalId !== null) {
            if (
                $externalId !== ''
                && $externalId !== $this->stringValue($group['external_id'] ?? '')
                && $this->groupExternalIdExists($db, $externalId, $groupId)
            ) {
                $this->io()->error("Content source group with external ID already exists: $externalId");
                return false;
            }
            $this->queueScalarUpdate($updates, $params, $changedFields, $group, 'external_id', $externalId);
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

        foreach (self::CUSTOM_FIELDS as $field) {
            $value = $this->getRawStringOption($input, $field);
            if ($value !== null) {
                $this->queueScalarUpdate($updates, $params, $changedFields, $group, $field, $value);
            }
        }

        return true;
    }

    private function toggleStatus(?string $id, int $status): int
    {
        $action = $status !== 0 ? 'enable' : 'disable';
        $groupId = $this->requireNumericId($id, $action);
        if ($groupId === null) {
            return self::FAILURE;
        }

        return $this->toggleEntityStatus(
            entityName: 'Content source group',
            tableName: $this->table('content_sources_groups'),
            idColumn: 'content_source_group_id',
            nameColumn: 'title',
            id: (string) $groupId,
            status: $status,
            commandName: 'content:content-source-group'
        );
    }

    private function requireNumericId(?string $id, string $action): ?int
    {
        if ($id === null || $id === '') {
            $this->io()->error('Content source group ID is required');
            $this->io()->text("Usage: kvs content-source-group {$action} <content_source_group_id>");
            return null;
        }
        if (preg_match('/^[1-9]\d*$/', $id) !== 1) {
            $this->io()->error('Invalid Content source group ID (use: integer >= 1)');
            return null;
        }

        return (int) $id;
    }

    /**
     * @param list<array<string, mixed>> $groups
     * @return list<string>
     */
    private function getKnownFields(array $groups): array
    {
        $fields = [
            'id',
            'content_source_group_id',
            'title',
            'dir',
            'description',
            'external_id',
            'status_id',
            'status',
            'custom1',
            'custom2',
            'custom3',
            'custom4',
            'custom5',
            'content_sources_amount',
            'content_source_count',
            'added_date',
            'sort_id',
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
        $groupId = is_numeric($group['content_source_group_id'] ?? null) ? (int) $group['content_source_group_id'] : 0;
        $statusId = is_numeric($group['status_id'] ?? null) ? (int) $group['status_id'] : 0;
        $amountValue = $group['content_sources_amount'] ?? ($group['content_source_count'] ?? null);
        $amount = is_numeric($amountValue)
            ? (int) $amountValue
            : 0;

        $group['id'] = $groupId;
        $group['content_source_group_id'] = $groupId;
        $group['status_id'] = $statusId;
        $group['status'] = StatusFormatter::contentSourceGroup($statusId, false);
        $group['content_sources_amount'] = $amount;
        $group['content_source_count'] = $amount;

        return $group;
    }

    /**
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private function getRequestedDetailFieldsForGroup(InputInterface $input, array $group): array
    {
        $fields = [];
        foreach ($this->getKnownFields([$group]) as $field) {
            $fields[$field] = $group[$field] ?? '';
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
        if (!in_array($field, self::FIELD_FILTER_COLUMNS, true)) {
            return null;
        }

        return $mode === 'empty' ? "g.{$field} = ''" : "g.{$field} != ''";
    }

    /**
     * @return list<string>
     */
    private function getFieldFilterValues(): array
    {
        $values = [];
        foreach (self::FIELD_FILTER_COLUMNS as $field) {
            $values[] = "empty/{$field}";
            $values[] = "filled/{$field}";
        }

        return $values;
    }

    private function getUsageCondition(string $usage): ?string
    {
        $sourcesTable = $this->table('content_sources');

        return match ($usage) {
            'used/content_sources' => "EXISTS (
                SELECT 1 FROM {$sourcesTable} cs_usage
                WHERE cs_usage.content_source_group_id = g.content_source_group_id
            )",
            'notused/content_sources' => "NOT EXISTS (
                SELECT 1 FROM {$sourcesTable} cs_usage
                WHERE cs_usage.content_source_group_id = g.content_source_group_id
            )",
            default => null,
        };
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

    private function getSortBy(InputInterface $input): ?string
    {
        $sortBy = $this->getStringOption($input, 'sort-by') ?? 'content_source_group_id';
        if (!isset(self::SORT_FIELDS[$sortBy])) {
            $this->io()->error('Invalid content source group sort field. Use: ' . implode(', ', array_keys(self::SORT_FIELDS)));
            return null;
        }

        return self::SORT_FIELDS[$sortBy];
    }

    private function getSortDirection(InputInterface $input): ?string
    {
        $direction = strtolower($this->getStringOption($input, 'sort-dir') ?? 'desc');
        if (!in_array($direction, ['asc', 'desc'], true)) {
            $this->io()->error('Invalid content source group sort direction. Use: asc or desc');
            return null;
        }

        return strtoupper($direction);
    }

    /**
     * @return list<int>
     */
    private function contentSourceIdsInGroup(\PDO $db, int $groupId): array
    {
        $stmt = $db->prepare(
            "SELECT content_source_id FROM {$this->table('content_sources')} WHERE content_source_group_id = :id ORDER BY content_source_id"
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

    private function getStatusOption(InputInterface $input): int|false|null
    {
        $status = $this->getStringOption($input, 'status');
        if ($status === null) {
            return null;
        }

        return match (strtolower(trim($status))) {
            'active', '1' => StatusFormatter::CONTENT_SOURCE_GROUP_ACTIVE,
            'inactive', 'disabled', '0' => StatusFormatter::CONTENT_SOURCE_GROUP_DISABLED,
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

    private function failInvalidStatus(string $status): false
    {
        $this->io()->error(sprintf(
            'Invalid status "%s". Valid values: active, inactive, disabled, 1, 0.',
            $status
        ));

        return false;
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

    private function slugify(string $value): string
    {
        $dir = preg_replace('/[^a-z0-9]+/', '-', strtolower($value));

        return trim((string) $dir, '-');
    }

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
        $sql = "SELECT content_source_group_id FROM {$this->table('content_sources_groups')} WHERE dir = :dir";
        $params = ['dir' => $dir];
        if ($excludeId !== null) {
            $sql .= ' AND content_source_group_id <> :id';
            $params['id'] = $excludeId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch() !== false;
    }

    private function groupTitleExists(\PDO $db, string $title, ?int $excludeId): bool
    {
        $sql = "SELECT content_source_group_id FROM {$this->table('content_sources_groups')} WHERE title = :title";
        $params = ['title' => $title];
        if ($excludeId !== null) {
            $sql .= ' AND content_source_group_id <> :id';
            $params['id'] = $excludeId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch() !== false;
    }

    private function groupExternalIdExists(\PDO $db, string $externalId, ?int $excludeId): bool
    {
        $sql = "SELECT content_source_group_id FROM {$this->table('content_sources_groups')} WHERE external_id = :external_id";
        $params = ['external_id' => $externalId];
        if ($excludeId !== null) {
            $sql .= ' AND content_source_group_id <> :id';
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

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
