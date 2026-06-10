<?php

namespace KVS\CLI\Command\Content;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Constants;
use KVS\CLI\Output\Formatter;
use KVS\CLI\Output\StatusFormatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'content:source',
    description: 'Manage KVS content sources',
    aliases: ['source', 'sources', 'site', 'sites', 'content-site', 'content-sites']
)]
class ContentSourceCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('content:source')
            ->setDescription('Manage KVS content sources')
            ->setAliases(['source', 'sources', 'site', 'sites', 'content-site', 'content-sites'])
            ->setHelp(<<<'HELP'
Manage KVS content sources, called "Sites" in the KVS admin and public filters.

<info>ACTIONS:</info>
  list              List content sources (default)
  show <id|dir>     Show one content source
  create <title>    Create a content source

<info>EXAMPLES:</info>
  <comment>kvs source list --search=Sample Source</comment>
  <comment>kvs source show sample-source</comment>
  <comment>kvs source create "Sample Source" --dir=sample-source --url=https://sample-source.example/</comment>
  <comment>kvs source create "Flingster" --status=inactive --sort=20</comment>
HELP
            )
            ->addArgument('action', InputArgument::OPTIONAL, 'Action: list, show, create', 'list')
            ->addArgument('identifier', InputArgument::OPTIONAL, 'Source ID, title, or directory')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Directory / URL slug. Defaults to a slug from the title')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'External source URL')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Source description')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Status (active|inactive|disabled)')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'Sort order')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results to show', Constants::DEFAULT_LIMIT)
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search in source titles, directories, and URLs')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to display')
            ->addOption('field', null, InputOption::VALUE_REQUIRED, 'Display single field from each item')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count, ids', 'table')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation of long text fields');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $this->getStringArgument($input, 'action');
        $identifier = $this->getStringArgument($input, 'identifier');

        return match ($action) {
            'list' => $this->listSources($input),
            'show' => $this->showSource($identifier),
            'create', 'add' => $this->createSource($input, $identifier),
            default => $this->listSources($input),
        };
    }

    private function listSources(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $conditions = ['1=1'];
            $params = [];

            $status = $this->getStringOption($input, 'status');
            if ($status !== null) {
                $statusId = $this->parseStatus($status);
                if ($statusId === null) {
                    $this->io()->error('Invalid status (use: active, inactive, disabled)');
                    return self::FAILURE;
                }
                $conditions[] = 'status_id = :status';
                $params['status'] = $statusId;
            }

            $search = $this->getStringOption($input, 'search');
            if ($search !== null) {
                $conditions[] = '(title LIKE :search OR dir LIKE :search OR url LIKE :search)';
                $params['search'] = '%' . $search . '%';
            }

            $limit = $this->getIntOptionOrDefault($input, 'limit', Constants::DEFAULT_LIMIT);
            $sql = 'SELECT content_source_id, title, dir, url, status_id, total_videos, total_albums, sort_id
                FROM ' . $this->table('content_sources') . '
                WHERE ' . implode(' AND ', $conditions) . '
                ORDER BY sort_id ASC, title ASC
                LIMIT :limit';

            $stmt = $db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            /** @var list<array<string, mixed>> $sources */
            $sources = array_values($stmt->fetchAll(\PDO::FETCH_ASSOC));

            $formatter = new Formatter(
                $input->getOptions(),
                ['content_source_id', 'title', 'dir', 'total_videos', 'total_albums', 'status_id']
            );
            $formatter->display($sources, $this->io());
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch content sources: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function showSource(?string $identifier): int
    {
        if ($identifier === null || $identifier === '') {
            $this->io()->error('Content source ID, title, or directory is required');
            $this->io()->text('Usage: kvs content:source show <id|dir>');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $source = $this->findSource($db, $identifier);
            if ($source === null) {
                $this->io()->error("Content source not found: $identifier");
                return self::FAILURE;
            }

            $title = $this->stringField($source, 'title');
            $statusId = $this->intField($source, 'status_id');
            $this->io()->section("Content source: $title");
            $this->renderTable(['Property', 'Value'], [
                ['ID', (string) $this->intField($source, 'content_source_id')],
                ['Title', $title],
                ['Directory', $this->stringField($source, 'dir')],
                ['URL', $this->stringField($source, 'url')],
                ['Status', StatusFormatter::category($statusId)],
                ['Videos', (string) $this->intField($source, 'total_videos')],
                ['Albums', (string) $this->intField($source, 'total_albums')],
                ['Added', $this->stringField($source, 'added_date', 'N/A')],
            ]);
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch content source: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function createSource(InputInterface $input, ?string $title): int
    {
        if ($title === null || $title === '') {
            $this->io()->error('Content source title is required');
            $this->io()->text('Usage: kvs content:source create "Source Title" [--dir=source-title]');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $dir = $this->getStringOption($input, 'dir') ?? $this->slugify($title);
        if ($dir === '') {
            $this->io()->error('Content source directory cannot be empty');
            return self::FAILURE;
        }

        $statusOption = $this->getStringOption($input, 'status') ?? 'active';
        $statusId = $this->parseStatus($statusOption);
        if ($statusId === null) {
            $this->io()->error('Invalid status (use: active, inactive, disabled)');
            return self::FAILURE;
        }

        try {
            $this->relaxSqlMode($db);
            if ($this->findSource($db, $dir) !== null) {
                $this->io()->error("Content source already exists for directory or title: $dir");
                return self::FAILURE;
            }

            $now = date('Y-m-d H:i:s');
            $fields = [
                'title' => $title,
                'dir' => $dir,
                'description' => $this->getStringOption($input, 'description') ?? '',
                'url' => $this->getStringOption($input, 'url') ?? '',
                'status_id' => $statusId,
                'rating' => 80,
                'rating_amount' => 1,
                'sort_id' => $this->getIntOption($input, 'sort') ?? $this->nextSortId($db),
                'added_date' => $now,
                'last_content_date' => $now,
            ];

            $columns = array_keys($fields);
            $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);
            $stmt = $db->prepare(
                'INSERT INTO ' . $this->table('content_sources') .
                    ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')'
            );
            $stmt->execute($fields);

            $id = (int) $db->lastInsertId();
            $this->io()->success("Content source created: $title (#$id)");
            $this->showSource((string) $id);
        } catch (\Exception $e) {
            $this->io()->error('Failed to create content source: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function parseStatus(string $status): ?int
    {
        return match ($status) {
            'active', '1' => 1,
            'inactive', 'disabled', '0' => 0,
            default => null,
        };
    }

    private function nextSortId(\PDO $db): int
    {
        $stmt = $db->query('SELECT COALESCE(MAX(sort_id), 0) + 1 FROM ' . $this->table('content_sources'));
        if ($stmt === false) {
            return 1;
        }

        $value = $stmt->fetchColumn();
        return is_numeric($value) ? (int) $value : 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findSource(\PDO $db, string $identifier): ?array
    {
        if (ctype_digit($identifier)) {
            $stmt = $db->prepare(
                'SELECT * FROM ' . $this->table('content_sources') . ' WHERE content_source_id = :id'
            );
            $stmt->execute(['id' => (int) $identifier]);
        } else {
            $stmt = $db->prepare(
                'SELECT * FROM ' . $this->table('content_sources') .
                    ' WHERE dir = :identifier OR title = :identifier LIMIT 1'
            );
            $stmt->execute(['identifier' => $identifier]);
        }

        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function relaxSqlMode(\PDO $db): void
    {
        try {
            $db->exec("SET SESSION sql_mode = ''");
        } catch (\Exception) {
        }
    }

    private function slugify(string $value): string
    {
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value));
        return trim($slug, '-');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function stringField(array $row, string $field, string $default = ''): string
    {
        $value = $row[$field] ?? null;
        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function intField(array $row, string $field): int
    {
        $value = $row[$field] ?? 0;
        return is_numeric($value) ? (int) $value : 0;
    }
}
