<?php

namespace KVS\CLI\Command\Content;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Output\Formatter;
use KVS\CLI\Output\StatusFormatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function KVS\CLI\Utils\truncate;
use function KVS\CLI\Utils\format_bytes;

#[AsCommand(
    name: 'content:video',
    description: 'Manage KVS videos',
    aliases: ['video', 'videos']
)]
class VideoCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'Action to perform (list|show|delete|update)')
            ->addArgument('id', InputArgument::OPTIONAL, 'Video ID')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status (active|disabled|error)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results to show', 20)
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search in titles')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Filter by category ID')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Filter by user ID')
            ->addOption('stats', null, InputOption::VALUE_NONE, 'Show video statistics')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to display')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count', 'table')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation of long text fields')
            ->setHelp(<<<'HELP'
Manage KVS videos.

<fg=yellow>AVAILABLE FIELDS:</>
  id, video_id    Video ID
  title           Video title
  status          Video status (Active/Disabled/Error)
  views           View count
  user, username  Username
  date, post_date Posted date
  duration        Video duration
  rating          Rating (out of 5)
  filesize        File size

<fg=yellow>EXAMPLES:</>
  <fg=green>kvs video list</>
  <fg=green>kvs video list --no-truncate</>
  <fg=green>kvs video list --fields=id,title,views,user</>
  <fg=green>kvs video list --format=csv</>
  <fg=green>kvs video list --status=active --format=json</>
  <fg=green>kvs video list --format=count</>

<fg=yellow>NOTE:</>
  Long text fields (title) are truncated in table view.
  Use --no-truncate to show full content, or --format=json for exports.
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action') ?? 'list';

        return match ($action) {
            'list' => $this->listVideos($input),
            'show' => $this->showVideo($input->getArgument('id')),
            'delete' => $this->deleteVideo($input->getArgument('id')),
            'update' => $this->updateVideo($input->getArgument('id'), $input),
            'stats' => $this->showStats(),
            default => $this->showHelp(),
        };
    }

    private function listVideos(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if (!$db) {
            return self::FAILURE;
        }

        $query = "SELECT v.*, u.username,
                 v.video_viewed as views
                 FROM ktvs_videos v
                 LEFT JOIN ktvs_users u ON v.user_id = u.user_id
                 WHERE 1=1";

        $params = [];

        if ($status = $input->getOption('status')) {
            $statusMap = ['active' => 1, 'disabled' => 0, 'error' => 2];
            if (isset($statusMap[$status])) {
                $query .= " AND v.status_id = :status";
                $params['status'] = $statusMap[$status];
            }
        }

        if ($search = $input->getOption('search')) {
            $query .= " AND v.title LIKE :search";
            $params['search'] = "%$search%";
        }

        if ($category = $input->getOption('category')) {
            $query .= " AND EXISTS (SELECT 1 FROM ktvs_categories_videos cv WHERE cv.video_id = v.video_id AND cv.category_id = :category)";
            $params['category'] = $category;
        }

        if ($user = $input->getOption('user')) {
            $query .= " AND v.user_id = :user";
            $params['user'] = $user;
        }

        $query .= " ORDER BY v.post_date DESC LIMIT :limit";

        try {
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue('limit', (int)$input->getOption('limit'), \PDO::PARAM_INT);
            $stmt->execute();

            $videos = $stmt->fetchAll();

            // Format and display output using centralized Formatter
            $formatter = new Formatter(
                $input->getOptions(),
                ['video_id', 'title', 'status_id', 'views', 'username', 'post_date']
            );
            $formatter->display($videos, $this->io);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io->error('Failed to fetch videos: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function showVideo(?string $id): int
    {
        if (!$id) {
            $this->io->error('Video ID is required');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if (!$db) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("SELECT * FROM ktvs_videos WHERE video_id = :id");
            $stmt->execute(['id' => $id]);
            $video = $stmt->fetch();

            if (!$video) {
                $this->io->error("Video not found: $id");
                return self::FAILURE;
            }

            $this->io->section("Video #$id");

            $info = [
                ['Title', $video['title']],
                ['Status', StatusFormatter::video($video['status_id'])],
                ['Duration', $this->formatDuration($video['duration'])],
                ['File Size', format_bytes($video['file_size'])],
                ['Resolution', $video['file_dimensions']],
                ['Posted', date('Y-m-d H:i:s', strtotime($video['post_date']))],
                ['Rating', sprintf('%.1f/5 (%d votes)', $video['rating'] / 20, $video['rating_amount'])],
                ['Views', number_format($video['video_viewed'])],
            ];

            $this->io->table(['Property', 'Value'], $info);

            if ($video['description']) {
                $this->io->section('Description');
                $this->io->text($video['description']);
            }

            $stmt = $db->prepare("
                SELECT c.title FROM ktvs_categories c
                JOIN ktvs_categories_videos cv ON c.category_id = cv.category_id
                WHERE cv.video_id = :id
            ");
            $stmt->execute(['id' => $id]);
            $categories = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            if (!empty($categories)) {
                $this->io->section('Categories');
                $this->io->listing($categories);
            }

            $stmt = $db->prepare("
                SELECT t.tag FROM ktvs_tags t
                JOIN ktvs_tags_videos tv ON t.tag_id = tv.tag_id
                WHERE tv.video_id = :id
            ");
            $stmt->execute(['id' => $id]);
            $tags = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            if (!empty($tags)) {
                $this->io->section('Tags');
                $this->io->text(implode(', ', $tags));
            }
        } catch (\Exception $e) {
            $this->io->error('Failed to fetch video: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function deleteVideo(?string $id): int
    {
        if (!$id) {
            $this->io->error('Video ID is required');
            return self::FAILURE;
        }

        $this->io->warning("This will permanently delete video #$id");

        if (!$this->io->confirm('Do you want to continue?', false)) {
            return self::SUCCESS;
        }

        $db = $this->getDatabaseConnection();
        if (!$db) {
            return self::FAILURE;
        }

        try {
            $db->beginTransaction();

            // Core tables that must exist
            $coreTables = [
                'ktvs_videos',
                'ktvs_categories_videos',
                'ktvs_tags_videos',
                'ktvs_models_videos',
                'ktvs_comments',
            ];

            // Optional tables that may not exist in all installations
            $optionalTables = [
                'ktvs_stats_videos_users_views',
            ];

            // Delete from core tables
            foreach ($coreTables as $table) {
                $column = $table === 'ktvs_comments' ? 'object_id' : 'video_id';
                $stmt = $db->prepare("DELETE FROM $table WHERE $column = :id");
                $stmt->execute(['id' => $id]);
            }

            // Delete from optional tables (ignore if table doesn't exist)
            foreach ($optionalTables as $table) {
                try {
                    $stmt = $db->prepare("DELETE FROM $table WHERE video_id = :id");
                    $stmt->execute(['id' => $id]);
                } catch (\PDOException $e) {
                    // Ignore table not found errors
                    if ($e->getCode() !== '42S02') {
                        throw $e;
                    }
                }
            }

            $db->commit();
            $this->io->success("Video #$id deleted successfully");
        } catch (\Exception $e) {
            $db->rollBack();
            $this->io->error('Failed to delete video: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function updateVideo(?string $id, InputInterface $input): int
    {
        if (!$id) {
            $this->io->error('Video ID is required');
            return self::FAILURE;
        }

        $this->io->info("Update functionality would be implemented here for video #$id");
        $this->io->note('This would allow updating title, status, categories, etc.');

        return self::SUCCESS;
    }

    private function showStats(): int
    {
        $db = $this->getDatabaseConnection();
        if (!$db) {
            return self::FAILURE;
        }

        try {
            $stats = [];

            $queries = [
                'Total Videos' => "SELECT COUNT(*) FROM ktvs_videos",
                'Active Videos' => "SELECT COUNT(*) FROM ktvs_videos WHERE status_id = 1",
                'Total Views' => "SELECT SUM(video_viewed) FROM ktvs_videos",
                'Total Duration' => "SELECT SUM(duration) FROM ktvs_videos",
                'Average Rating' => "SELECT AVG(rating) FROM ktvs_videos WHERE rating_amount > 0",
                'Total Size' => "SELECT SUM(file_size) FROM ktvs_videos",
            ];

            foreach ($queries as $label => $query) {
                $value = $db->query($query)->fetchColumn();

                if ($label === 'Total Duration') {
                    $value = $this->formatDuration($value);
                } elseif ($label === 'Average Rating') {
                    $value = sprintf('%.1f/5', $value / 20);
                } elseif ($label === 'Total Size') {
                    $value = format_bytes($value);
                } elseif (is_numeric($value)) {
                    $value = number_format($value);
                }

                $stats[] = [$label, $value];
            }

            $this->io->table(['Metric', 'Value'], $stats);

            $stmt = $db->query("
                SELECT v.title, v.video_viewed as views
                FROM ktvs_videos v
                WHERE v.status_id = 1
                ORDER BY v.video_viewed DESC
                LIMIT 10
            ");
            $topVideos = $stmt->fetchAll();

            if (!empty($topVideos)) {
                $this->io->section('Top 10 Most Viewed Videos');
                $rows = [];
                foreach ($topVideos as $i => $video) {
                    $rows[] = [
                        $i + 1,
                        substr($video['title'], 0, 50),
                        number_format($video['views']),
                    ];
                }
                $this->io->table(['#', 'Title', 'Views'], $rows);
            }
        } catch (\Exception $e) {
            $this->io->error('Failed to fetch statistics: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function showHelp(): int
    {
        $this->io->info('Available actions:');
        $this->io->listing([
            'list : List videos with filters',
            'show <id> : Show details for a specific video',
            'delete <id> : Delete a video',
            'update <id> : Update video information',
            'stats : Show video statistics',
        ]);

        $this->io->section('Examples');
        $this->io->text([
            'kvs content:video list --status=active --limit=10',
            'kvs content:video show 123',
            'kvs content:video list --search="example" --category=5',
            'kvs content:video stats',
        ]);

        return self::SUCCESS;
    }

    private function outputTable(array $videos, array $fields, bool $noTruncate = false): int
    {
        $headers = array_map(fn($f) => ucfirst(str_replace('_', ' ', $f)), $fields);
        $rows = [];

        foreach ($videos as $video) {
            $row = [];
            foreach ($fields as $field) {
                $row[] = $this->getFieldValue($video, $field, true, $noTruncate);
            }
            $rows[] = $row;
        }

        $this->io->table($headers, $rows);
        return self::SUCCESS;
    }

    private function outputCSV(array $videos, array $fields, bool $noTruncate = false): int
    {
        $output = fopen('php://output', 'w');
        fputcsv($output, $fields);

        foreach ($videos as $video) {
            $row = [];
            foreach ($fields as $field) {
                $row[] = $this->getFieldValue($video, $field, false, true);
            }
            fputcsv($output, $row);
        }

        fclose($output);
        return self::SUCCESS;
    }

    private function outputJSON(array $videos, array $fields): int
    {
        $result = [];

        foreach ($videos as $video) {
            $item = [];
            foreach ($fields as $field) {
                $item[$field] = $this->getFieldValue($video, $field, false, true);
            }
            $result[] = $item;
        }

        $this->io->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }

    private function outputYAML(array $videos, array $fields): int
    {
        foreach ($videos as $i => $video) {
            $this->io->writeln("- ");
            foreach ($fields as $field) {
                $value = $this->getFieldValue($video, $field, false, true);
                $this->io->writeln("  {$field}: " . json_encode($value, JSON_UNESCAPED_UNICODE));
            }
        }

        return self::SUCCESS;
    }

    private function getFieldValue(array $video, string $field, bool $formatted = true, bool $noTruncate = false): string
    {
        $fieldMap = [
            'id' => 'video_id',
            'video_id' => 'video_id',
            'title' => 'title',
            'status' => 'status_id',
            'status_id' => 'status_id',
            'views' => 'views',
            'user' => 'username',
            'username' => 'username',
            'date' => 'post_date',
            'post_date' => 'post_date',
            'duration' => 'duration',
            'rating' => 'rating',
            'filesize' => 'file_size',
            'file_size' => 'file_size',
        ];

        $dbField = $fieldMap[$field] ?? $field;
        $value = $video[$dbField] ?? '';

        // Handle empty values
        if ($value === '' || $value === null) {
            return $formatted ? '<fg=gray>N/A</>' : 'N/A';
        }

        // Format status
        if ($field === 'status' || $field === 'status_id') {
            return StatusFormatter::video((int)$value, $formatted);
        }

        // Format dates
        if (in_array($field, ['date', 'post_date']) && $value) {
            return $formatted ? date('Y-m-d', strtotime($value)) : $value;
        }

        // Format numbers
        if ($field === 'views' && is_numeric($value)) {
            return $formatted ? number_format((int)$value) : (string)$value;
        }

        // Format rating
        if ($field === 'rating' && is_numeric($value)) {
            return sprintf('%.1f', $value / 20);
        }

        // Format duration
        if ($field === 'duration' && is_numeric($value)) {
            return $this->formatDuration((int)$value);
        }

        // Format filesize
        if (in_array($field, ['filesize', 'file_size']) && is_numeric($value)) {
            return format_bytes((int)$value);
        }

        // Truncate title (50 chars unless --no-truncate)
        if ($field === 'title' && !$noTruncate) {
            return truncate($value, 50);
        }

        return $value;
    }


    private function formatDuration(?int $seconds): string
    {
        if (!$seconds) {
            return '0:00';
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%d:%02d', $minutes, $seconds);
    }
}
