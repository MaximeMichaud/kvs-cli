<?php

namespace KVS\CLI\Command\Content;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Output\Formatter;
use KVS\CLI\Output\StatusFormatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(
    name: 'content:dvd',
    description: 'Manage KVS DVDs (channels/series)',
    aliases: ['dvd', 'dvds', 'channel', 'channels']
)]
class DvdCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'Action to perform (list|show|stats)', 'list')
            ->addArgument('id', InputArgument::OPTIONAL, 'DVD ID')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status (active|disabled)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results to show', 20)
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search in DVD titles')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to display')
            ->addOption('field', null, InputOption::VALUE_REQUIRED, 'Display single field value')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count, ids', 'table')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation of long text fields')
            ->setHelp(<<<'HELP'
Manage KVS DVDs (channels/series/collections).

<fg=yellow>AVAILABLE FIELDS:</>
  id, dvd_id      DVD ID
  title           DVD/channel name
  status          Status (Active/Disabled)
  videos          Total videos
  duration        Total duration
  release_year    Release year
  views           View count
  rating          Average rating
  subscribers     Subscriber count

<fg=yellow>EXAMPLES:</>
  <fg=green>kvs dvd list</>
  <fg=green>kvs dvd list --status=active</>
  <fg=green>kvs dvd list --search="Series"</>
  <fg=green>kvs dvd list --fields=id,title,videos,views,release_year</>
  <fg=green>kvs dvd list --format=json</>
  <fg=green>kvs dvd list --format=count</>
  <fg=green>kvs dvd show 123</>
  <fg=green>kvs dvd stats</>
HELP
            );
    }

    protected function execute(InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
    {
        $action = $input->getArgument('action');

        return match ($action) {
            'list' => $this->listDvds($input),
            'show' => $this->showDvd($input->getArgument('id')),
            'stats' => $this->showStats(),
            default => $this->listDvds($input),
        };
    }

    private function listDvds(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        // Build query - counts will be added when we know the table structure
        $query = "SELECT d.*
                 FROM {$this->table('dvds')} d
                 WHERE 1=1";

        $params = [];

        // Status filter
        $status = $input->getOption('status');
        if ($status !== null) {
            $statusMap = ['active' => 1, 'disabled' => 0];
            if (isset($statusMap[$status])) {
                $query .= " AND d.status_id = :status";
                $params['status'] = $statusMap[$status];
            }
        }

        // Search filter
        $search = $input->getOption('search');
        if ($search !== null) {
            $query .= " AND d.title LIKE :search";
            $params['search'] = "%$search%";
        }

        $query .= " ORDER BY d.dvd_id DESC LIMIT :limit";

        try {
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue('limit', (int)$input->getOption('limit'), \PDO::PARAM_INT);
            $stmt->execute();

            $dvds = $stmt->fetchAll();

            // Transform DVDs for display (field aliases)
            $transformedDvds = array_map(function ($dvd) {
                // Calculate rating
                $ratingAmount = (int)($dvd['rating_amount'] ?? 0);
                $calculatedRating = $ratingAmount > 0
                    ? round($dvd['rating'] / $ratingAmount, 1)
                    : 0;

                return [
                    'dvd_id' => $dvd['dvd_id'],
                    'id' => $dvd['dvd_id'],
                    'title' => $dvd['title'],
                    'status_id' => $dvd['status_id'],
                    'status' => StatusFormatter::dvd((int)$dvd['status_id'], false),
                    'total_videos' => $dvd['total_videos'] ?? 0,
                    'videos' => $dvd['total_videos'] ?? 0,
                    'total_videos_duration' => $dvd['total_videos_duration'] ?? 0,
                    'duration' => $dvd['total_videos_duration'] ?? 0,
                    'release_year' => $dvd['release_year'] ?? '',
                    'dvd_viewed' => $dvd['dvd_viewed'] ?? 0,
                    'views' => $dvd['dvd_viewed'] ?? 0,
                    'subscribers_count' => $dvd['subscribers_count'] ?? 0,
                    'subscribers' => $dvd['subscribers_count'] ?? 0,
                    'rating' => $calculatedRating,
                ];
            }, $dvds);

            // Default fields
            $defaultFields = ['dvd_id', 'title', 'status_id', 'total_videos'];

            // Format and display using Formatter
            $formatter = new Formatter($input->getOptions(), $defaultFields);
            $formatter->display($transformedDvds, $this->io());

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch DVDs: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function showDvd(?string $id): int
    {
        if ($id === null || $id === '') {
            $this->io()->error('DVD ID is required');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("
                SELECT d.*
                FROM {$this->table('dvds')} d
                WHERE d.dvd_id = :id
            ");
            $stmt->execute(['id' => $id]);
            $dvd = $stmt->fetch();

            if ($dvd === false) {
                $this->io()->error("DVD not found: $id");
                return self::FAILURE;
            }

            // Display DVD details
            $this->io()->title("DVD: {$dvd['title']}");

            $info = [
                ['DVD ID', $dvd['dvd_id']],
                ['Title', $dvd['title']],
                ['Status', StatusFormatter::dvd((int)$dvd['status_id'])],
                ['Videos', number_format($dvd['total_videos'] ?? 0)],
                ['Views', number_format($dvd['dvd_viewed'] ?? 0)],
            ];

            // Duration
            $duration = (int)($dvd['total_videos_duration'] ?? 0);
            if ($duration > 0) {
                $hours = floor($duration / 3600);
                $minutes = floor(($duration % 3600) / 60);
                $info[] = ['Total Duration', sprintf('%dh %dm', $hours, $minutes)];
            }

            // Release year
            if (isset($dvd['release_year']) && $dvd['release_year'] !== '') {
                $info[] = ['Release Year', $dvd['release_year']];
            }

            // Rating
            $ratingAmount = (int)($dvd['rating_amount'] ?? 0);
            if ($ratingAmount > 0) {
                $info[] = ['Rating', sprintf('%.1f/5 (%d votes)', $dvd['rating'] / $ratingAmount, $ratingAmount)];
            }

            // Subscribers
            if (isset($dvd['subscribers_count']) && (int) $dvd['subscribers_count'] > 0) {
                $info[] = ['Subscribers', number_format((int) $dvd['subscribers_count'])];
            }

            if (isset($dvd['description']) && $dvd['description'] !== '') {
                $info[] = ['Description', $dvd['description']];
            }

            $this->renderTable(['Field', 'Value'], $info);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch DVD: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function showStats(): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stats = [];

            // Total DVDs
            $stmt = $db->query("SELECT COUNT(*) FROM {$this->table('dvds')}");
            if ($stmt !== false) {
                $stats[] = ['Total DVDs', number_format((int) $stmt->fetchColumn())];
            }

            // Active DVDs
            $stmt = $db->query("SELECT COUNT(*) FROM {$this->table('dvds')} WHERE status_id = " . StatusFormatter::DVD_ACTIVE);
            if ($stmt !== false) {
                $stats[] = ['Active', number_format((int) $stmt->fetchColumn())];
            }

            // Disabled DVDs
            $stmt = $db->query("SELECT COUNT(*) FROM {$this->table('dvds')} WHERE status_id = " . StatusFormatter::DVD_DISABLED);
            if ($stmt !== false) {
                $stats[] = ['Disabled', number_format((int) $stmt->fetchColumn())];
            }

            $this->io()->title('DVD Statistics');
            $this->renderTable(['Metric', 'Value'], $stats);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch statistics: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
