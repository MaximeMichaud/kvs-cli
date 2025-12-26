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

use function KVS\CLI\Utils\truncate;

#[AsCommand(
    name: 'content:tag',
    description: 'Manage KVS tags',
    aliases: ['tag', 'tags']
)]
class TagCommand extends BaseCommand
{
    use ToggleStatusTrait;

    protected function configure(): void
    {
        $this
            ->setHelp(<<<'HELP'
Manage KVS tags with full CRUD operations.

<info>ACTIONS:</info>
  list              List all tags (default)
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
  <comment>kvs tag create "4K UHD"</comment>
  <comment>kvs tag update 5 --name="Ultra HD"</comment>
  <comment>kvs tag enable 3</comment>
  <comment>kvs tag disable 3</comment>
  <comment>kvs tag merge 10 15</comment>
  <comment>kvs tag delete 8</comment>
  <comment>kvs tag stats</comment>
HELP
            )
            ->addArgument('action', InputArgument::OPTIONAL, 'Action: list, create, delete, merge, update, enable, disable, stats', 'list')
            ->addArgument('identifier', InputArgument::OPTIONAL, 'Tag ID or name')
            ->addArgument('target', InputArgument::OPTIONAL, 'Target tag ID for merge operation')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Tag name (for update)')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter/set status (active|inactive)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results to show', Constants::DEFAULT_LIMIT)
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search in tag names')
            ->addOption('unused', null, InputOption::VALUE_NONE, 'Show only unused tags')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to display')
            ->addOption('field', null, InputOption::VALUE_REQUIRED, 'Display single field from each item')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count, ids', 'table')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation of long text fields');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');

        return match ($action) {
            'list' => $this->listTags($input),
            'create' => $this->createTag($input->getArgument('identifier')),
            'delete' => $this->deleteTag($input->getArgument('identifier')),
            'update' => $this->updateTag($input->getArgument('identifier'), $input),
            'enable' => $this->toggleStatus($input->getArgument('identifier'), 1),
            'disable' => $this->toggleStatus($input->getArgument('identifier'), 0),
            'merge' => $this->mergeTags(
                $input->getArgument('identifier'),
                $input->getArgument('target')
            ),
            'stats' => $this->showStats(),
            default => $this->listTags($input),
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
            $status = $input->getOption('status');
            if ($status !== null) {
                $statusId = ($status === 'active') ? 1 : 0;
                $conditions[] = 't.status_id = :status';
                $params['status'] = $statusId;
            }

            // Search filter
            $search = $input->getOption('search');
            if ($search !== null) {
                $conditions[] = 't.tag LIKE :search';
                $params['search'] = '%' . $search . '%';
            }

            // Build query
            $whereClause = implode(' AND ', $conditions);
            $limit = (int)$input->getOption('limit');

            $sql = "
                SELECT t.*,
                       (SELECT COUNT(*) FROM {$this->table('tags')}_videos WHERE tag_id = t.tag_id) as video_count,
                       (SELECT COUNT(*) FROM {$this->table('tags')}_albums WHERE tag_id = t.tag_id) as album_count
                FROM {$this->table('tags')} t
                WHERE $whereClause
            ";

            // Unused filter
            if ($input->getOption('unused') !== false) {
                $sql .= " HAVING video_count = 0 AND album_count = 0";
            }

            $sql .= " ORDER BY t.tag LIMIT :limit";
            $params['limit'] = $limit;

            $stmt = $db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $stmt->execute();

            $tags = $stmt->fetchAll();

            // Add total_usage field to each tag
            $transformedTags = array_map(function ($tag) {
                return [
                    'tag_id' => $tag['tag_id'],
                    'tag' => $tag['tag'],
                    'video_count' => $tag['video_count'],
                    'album_count' => $tag['album_count'],
                    'total_usage' => $tag['video_count'] + $tag['album_count'],
                    'status_id' => $tag['status_id'],
                ];
            }, $tags);

            // Format and display output using centralized Formatter
            $formatter = new Formatter(
                $input->getOptions(),
                ['tag_id', 'tag', 'video_count', 'album_count', 'total_usage', 'status_id']
            );
            $formatter->display($transformedTags, $this->io);
        } catch (\Exception $e) {
            $this->io->error('Failed to fetch tags: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function createTag(?string $tagName): int
    {
        if ($tagName === null || $tagName === '') {
            $this->io->error('Tag name is required');
            $this->io->text('Usage: kvs content:tag create "Tag Name"');
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
                $this->io->error("Tag already exists: $tagName");
                return self::FAILURE;
            }

            // Create tag
            $stmt = $db->prepare("
                INSERT INTO {$this->table('tags')} (tag, status_id)
                VALUES (:tag, 1)
            ");

            $stmt->execute(['tag' => $tagName]);

            $tagId = $db->lastInsertId();

            $this->io->success("Tag created successfully!");
            $this->renderTable(
                ['Property', 'Value'],
                [
                    ['ID', $tagId],
                    ['Name', $tagName],
                    ['Status', 'Active'],
                ]
            );
        } catch (\Exception $e) {
            $this->io->error('Failed to create tag: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function deleteTag(?string $identifier): int
    {
        if ($identifier === null || $identifier === '') {
            $this->io->error('Tag ID is required');
            $this->io->text('Usage: kvs content:tag delete <tag_id>');
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
            $tag = $stmt->fetch();

            if ($tag === false) {
                $this->io->error("Tag not found: $identifier");
                return self::FAILURE;
            }

            // Check usage
            $stmt = $db->prepare("
                SELECT
                    (SELECT COUNT(*) FROM {$this->table('tags')}_videos WHERE tag_id = :id) as video_count,
                    (SELECT COUNT(*) FROM {$this->table('tags')}_albums WHERE tag_id = :id) as album_count
            ");
            $stmt->execute(['id' => $identifier]);
            $usage = $stmt->fetch();

            $totalUsage = $usage['video_count'] + $usage['album_count'];

            if ($totalUsage > 0) {
                $this->io->warning("This tag is used by $totalUsage items:");
                $this->io->listing([
                    "Videos: {$usage['video_count']}",
                    "Albums: {$usage['album_count']}",
                ]);

                if ($this->io->confirm('Delete anyway? This will remove all associations.', false) !== true) {
                    $this->io->info('Operation cancelled');
                    return self::SUCCESS;
                }
            }

            // Delete associations first
            $db->prepare("DELETE FROM {$this->table('tags')}_videos WHERE tag_id = :id")->execute(['id' => $identifier]);
            $db->prepare("DELETE FROM {$this->table('tags')}_albums WHERE tag_id = :id")->execute(['id' => $identifier]);

            // Delete tag
            $stmt = $db->prepare("DELETE FROM {$this->table('tags')} WHERE tag_id = :id");
            $stmt->execute(['id' => $identifier]);

            $this->io->success("Tag '{$tag['tag']}' deleted successfully!");
        } catch (\Exception $e) {
            $this->io->error('Failed to delete tag: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function mergeTags(?string $sourceId, ?string $targetId): int
    {
        if ($sourceId === null || $sourceId === '' || $targetId === null || $targetId === '') {
            $this->io->error('Both source and target tag IDs are required');
            $this->io->text('Usage: kvs content:tag merge <source_tag_id> <target_tag_id>');
            return self::FAILURE;
        }

        if ($sourceId === $targetId) {
            $this->io->error('Source and target tags must be different');
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
                $this->io->error('One or both tags not found');
                return self::FAILURE;
            }

            $sourceTag = array_filter($tags, fn($t) => $t['tag_id'] === $sourceId)[0] ?? null;
            $targetTag = array_filter($tags, fn($t) => $t['tag_id'] === $targetId)[0] ?? null;

            $this->io->section('Merge Operation');
            $this->io->text("Source: {$sourceTag['tag']} (ID: $sourceId)");
            $this->io->text("Target: {$targetTag['tag']} (ID: $targetId)");
            $this->io->newLine();
            $this->io->warning('All associations will be moved to the target tag, then source tag will be deleted.');

            if ($this->io->confirm('Continue with merge?', false) !== true) {
                $this->io->info('Operation cancelled');
                return self::SUCCESS;
            }

            $db->beginTransaction();

            // Move video associations (avoid duplicates)
            $db->prepare("
                INSERT IGNORE INTO {$this->table('tags')}_videos (tag_id, video_id)
                SELECT :target, video_id FROM {$this->table('tags')}_videos WHERE tag_id = :source
            ")->execute(['target' => $targetId, 'source' => $sourceId]);

            // Move album associations (avoid duplicates)
            $db->prepare("
                INSERT IGNORE INTO {$this->table('tags')}_albums (tag_id, album_id)
                SELECT :target, album_id FROM {$this->table('tags')}_albums WHERE tag_id = :source
            ")->execute(['target' => $targetId, 'source' => $sourceId]);

            // Delete old associations
            $db->prepare("DELETE FROM {$this->table('tags')}_videos WHERE tag_id = :id")->execute(['id' => $sourceId]);
            $db->prepare("DELETE FROM {$this->table('tags')}_albums WHERE tag_id = :id")->execute(['id' => $sourceId]);

            // Delete source tag
            $db->prepare("DELETE FROM {$this->table('tags')} WHERE tag_id = :id")->execute(['id' => $sourceId]);

            $db->commit();

            $this->io->success("Tags merged successfully!");
            $this->io->text("'{$sourceTag['tag']}' has been merged into '{$targetTag['tag']}'");
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->io->error('Failed to merge tags: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function showStats(): int
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
            $overall = $stmt->fetch();

            // Usage stats
            $stmt = $db->query("
                SELECT
                    COUNT(DISTINCT tag_id) as used_tags
                FROM (
                    SELECT tag_id FROM {$this->table('tags')}_videos
                    UNION
                    SELECT tag_id FROM {$this->table('tags')}_albums
                ) as used
            ");
            if ($stmt === false) {
                throw new \RuntimeException('Failed to execute usage stats query');
            }
            $usageStats = $stmt->fetch();
            $unusedTags = $overall['total_tags'] - $usageStats['used_tags'];

            // Top tags
            $stmt = $db->query("
                SELECT t.tag,
                       (SELECT COUNT(*) FROM {$this->table('tags')}_videos WHERE tag_id = t.tag_id) as video_count,
                       (SELECT COUNT(*) FROM {$this->table('tags')}_albums WHERE tag_id = t.tag_id) as album_count
                FROM {$this->table('tags')} t
                ORDER BY (video_count + album_count) DESC
                LIMIT " . Constants::TOP_QUERY_LIMIT . "
            ");
            if ($stmt === false) {
                throw new \RuntimeException('Failed to execute top tags query');
            }
            $topTags = $stmt->fetchAll();

            $this->io->title('Tag Statistics');

            $this->io->section('Overall Statistics');
            $this->renderTable(
                ['Metric', 'Count'],
                [
                    ['Total Tags', $overall['total_tags']],
                    ['Active Tags', $overall['active_tags']],
                    ['Inactive Tags', $overall['inactive_tags']],
                    ['Used Tags', $usageStats['used_tags']],
                    ['Unused Tags', $unusedTags],
                ]
            );

            if ($topTags !== []) {
                $this->io->section('Top 10 Most Used Tags');
                $rows = [];
                foreach ($topTags as $tag) {
                    $total = $tag['video_count'] + $tag['album_count'];
                    $rows[] = [
                        $tag['tag'],
                        $tag['video_count'],
                        $tag['album_count'],
                        $total,
                    ];
                }
                $this->renderTable(['Tag', 'Videos', 'Albums', 'Total'], $rows);
            }
        } catch (\Exception $e) {
            $this->io->error('Failed to fetch stats: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function updateTag(?string $id, InputInterface $input): int
    {
        if ($id === null || $id === '') {
            $this->io->error('Tag ID is required');
            $this->io->text('Usage: kvs content:tag update <tag_id> --name="New Name" --status=inactive');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            // Get current tag
            $stmt = $db->prepare("SELECT * FROM {$this->table('tags')} WHERE tag_id = :id");
            $stmt->execute(['id' => $id]);
            $tag = $stmt->fetch();

            if ($tag === false) {
                $this->io->error("Tag not found: $id");
                return self::FAILURE;
            }

            $updates = [];
            $params = ['id' => $id];

            // Name
            $name = $input->getOption('name');
            if ($name !== null) {
                // Check if new name already exists
                $stmt = $db->prepare("SELECT tag_id FROM {$this->table('tags')} WHERE tag = :tag AND tag_id !== :id");
                $stmt->execute(['tag' => $name, 'id' => $id]);
                if ($stmt->fetch() !== false) {
                    $this->io->error("Tag name already exists: $name");
                    $this->io->text('Hint: Use merge command to combine duplicate tags');
                    return self::FAILURE;
                }
                $updates[] = 'tag = :tag';
                $params['tag'] = $name;
            }

            // Status
            $status = $input->getOption('status');
            if ($status !== null) {
                $statusId = ($status === 'active') ? 1 : 0;
                $updates[] = 'status_id = :status_id';
                $params['status_id'] = $statusId;
            }

            if ($updates === []) {
                $this->io->warning('No changes specified. Use --name or --status options.');
                return self::FAILURE;
            }

            // Update tag
            $sql = "UPDATE {$this->table('tags')} SET " . implode(', ', $updates) . " WHERE tag_id = :id";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            $this->io->success("Tag updated successfully!");
            $this->renderTable(
                ['Property', 'Value'],
                [
                    ['ID', $id],
                    ['New Name', $params['tag'] ?? $tag['tag']],
                    [
                        'Status',
                        isset($params['status_id'])
                            ? ($params['status_id'] !== 0 ? 'Active' : 'Inactive')
                            : ($tag['status_id'] !== 0 ? 'Active' : 'Inactive')
                    ],
                ]
            );
        } catch (\Exception $e) {
            $this->io->error('Failed to update tag: ' . $e->getMessage());
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
}
