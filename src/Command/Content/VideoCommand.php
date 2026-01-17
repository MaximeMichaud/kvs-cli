<?php

namespace KVS\CLI\Command\Content;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Constants;
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
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results to show', Constants::DEFAULT_CONTENT_LIMIT)
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
  is_hd           HD flag (Yes/No)
  is_private      Private flag (Yes/No)
  favourites      Favourites count

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
        $action = $this->getStringArgument($input, 'action');

        return match ($action) {
            'list' => $this->listVideos($input),
            'show' => $this->showVideo($this->getStringArgument($input, 'id')),
            'delete' => $this->deleteVideo($this->getStringArgument($input, 'id')),
            'update' => $this->updateVideo($this->getStringArgument($input, 'id'), $input),
            'stats' => $this->showStats(),
            default => $this->showHelp(),
        };
    }

    private function listVideos(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $query = "SELECT v.*, u.username,
                 v.video_viewed as views
                 FROM {$this->table('videos')} v
                 LEFT JOIN {$this->table('users')} u ON v.user_id = u.user_id
                 WHERE 1=1";

        $params = [];

        $status = $this->getStringOption($input, 'status');
        if ($status !== null) {
            $statusMap = [
                'active' => StatusFormatter::VIDEO_ACTIVE,
                'disabled' => StatusFormatter::VIDEO_DISABLED,
                'error' => StatusFormatter::VIDEO_ERROR,
            ];
            if (isset($statusMap[$status])) {
                $query .= " AND v.status_id = :status";
                $params['status'] = $statusMap[$status];
            }
        }

        $search = $this->getStringOption($input, 'search');
        if ($search !== null) {
            $query .= " AND v.title LIKE :search";
            $params['search'] = "%$search%";
        }

        $category = $this->getIntOption($input, 'category');
        if ($category !== null) {
            $query .= " AND EXISTS (SELECT 1 FROM {$this->table('categories_videos')} cv "
                . "WHERE cv.video_id = v.video_id AND cv.category_id = :category)";
            $params['category'] = $category;
        }

        $user = $this->getIntOption($input, 'user');
        if ($user !== null) {
            $query .= " AND v.user_id = :user";
            $params['user'] = $user;
        }

        $query .= " ORDER BY v.post_date DESC LIMIT :limit";

        try {
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $limit = $this->getIntOptionOrDefault($input, 'limit', Constants::DEFAULT_CONTENT_LIMIT);
            $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            /** @var list<array<string, mixed>> $videos */
            $videos = $stmt->fetchAll();

            // Transform data for display (field aliases and calculated values)
            $videos = array_map(function (array $video): array {
                // Add field aliases
                $video['id'] = $video['video_id'];
                $statusId = isset($video['status_id']) && is_numeric($video['status_id']) ? (int) $video['status_id'] : 0;
                $video['status'] = StatusFormatter::video($statusId, false);

                // Calculate rating (rating / rating_amount gives 0-5 scale)
                $ratingAmount = isset($video['rating_amount']) && is_numeric($video['rating_amount']) ? (int) $video['rating_amount'] : 0;
                $rating = isset($video['rating']) && is_numeric($video['rating']) ? (float) $video['rating'] : 0.0;
                $video['rating'] = $ratingAmount > 0
                    ? round($rating / $ratingAmount, 1)
                    : 0;

                return $video;
            }, $videos);

            // Format and display output using centralized Formatter
            $formatter = new Formatter(
                $input->getOptions(),
                ['video_id', 'title', 'status_id', 'views', 'username', 'post_date']
            );
            $formatter->display($videos, $this->io());

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch videos: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function showVideo(?string $id): int
    {
        if ($id === null || $id === '') {
            $this->io()->error('Video ID is required');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("SELECT * FROM {$this->table('videos')} WHERE video_id = :id");
            $stmt->execute(['id' => $id]);
            /** @var array{title: string, status_id: int, is_hd: int, is_private: int, duration: int, file_size: int, file_dimensions: string, post_date: string, rating: int, rating_amount: int, video_viewed: int, favourites_count: int, description: string}|false $video */
            $video = $stmt->fetch();

            if ($video === false) {
                $this->io()->error("Video not found: $id");
                return self::FAILURE;
            }

            $this->io()->section("Video #$id");

            $postTimestamp = strtotime($video['post_date']);
            $ratingAmount = $video['rating_amount'];
            $rating = $video['rating'];

            $info = [
                ['Title', $video['title']],
                ['Status', StatusFormatter::video($video['status_id'])],
                ['HD', $video['is_hd'] > 0 ? '<fg=green>Yes</>' : '<fg=gray>No</>'],
                ['Private', $video['is_private'] > 0 ? '<fg=yellow>Yes</>' : '<fg=gray>No</>'],
                ['Duration', $this->formatDuration($video['duration'])],
                ['File Size', format_bytes($video['file_size'])],
                ['Resolution', $video['file_dimensions']],
                ['Posted', $postTimestamp !== false ? date('Y-m-d H:i:s', $postTimestamp) : 'Unknown'],
                [
                    'Rating',
                    $ratingAmount > 0
                        ? sprintf(
                            '%.1f/%d (%d votes)',
                            $rating / $ratingAmount,
                            Constants::RATING_SCALE,
                            $ratingAmount
                        )
                        : 'No ratings yet'
                ],
                ['Views', number_format($video['video_viewed'])],
                ['Favourites', number_format($video['favourites_count'])],
            ];

            $this->renderTable(['Property', 'Value'], $info);

            if ($video['description'] !== '') {
                $this->io()->section('Description');
                $this->io()->text($video['description']);
            }

            $stmt = $db->prepare("
                SELECT c.title FROM {$this->table('categories')} c
                JOIN {$this->table('categories_videos')} cv ON c.category_id = cv.category_id
                WHERE cv.video_id = :id
            ");
            $stmt->execute(['id' => $id]);
            $categories = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            if ($categories !== []) {
                $this->io()->section('Categories');
                $this->io()->listing($categories);
            }

            $stmt = $db->prepare("
                SELECT t.tag FROM {$this->table('tags')} t
                JOIN {$this->table('tags_videos')} tv ON t.tag_id = tv.tag_id
                WHERE tv.video_id = :id
            ");
            $stmt->execute(['id' => $id]);
            $tags = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            if ($tags !== []) {
                $this->io()->section('Tags');
                $this->io()->text(implode(', ', $tags));
            }
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch video: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function deleteVideo(?string $id): int
    {
        if ($id === null || $id === '') {
            $this->io()->error('Video ID is required');
            return self::FAILURE;
        }

        $this->io()->warning("This will permanently delete video #$id");

        if ($this->io()->confirm('Do you want to continue?', false) !== true) {
            return self::SUCCESS;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $db->beginTransaction();

            // Core tables that must exist
            $coreTables = [
                $this->table('videos'),
                $this->table('categories_videos'),
                $this->table('tags_videos'),
                $this->table('models_videos'),
                $this->table('comments'),
            ];

            // Optional tables that may not exist in all installations
            $optionalTables = [
                $this->table('stats_videos_users_views'),
            ];

            // Delete from core tables
            $commentsTable = $this->table('comments');
            foreach ($coreTables as $table) {
                $column = $table === $commentsTable ? 'object_id' : 'video_id';
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
            $this->io()->success("Video #$id deleted successfully");
        } catch (\Exception $e) {
            $db->rollBack();
            $this->io()->error('Failed to delete video: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function updateVideo(?string $id, InputInterface $input): int
    {
        if ($id === null || $id === '') {
            $this->io()->error('Video ID is required');
            return self::FAILURE;
        }

        $this->io()->info("Update functionality would be implemented here for video #$id");
        $this->io()->note('This would allow updating title, status, categories, etc.');

        return self::SUCCESS;
    }

    private function showStats(): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            /** @var list<list<string>> $stats */
            $stats = [];

            $queries = [
                'Total Videos' => "SELECT COUNT(*) FROM {$this->table('videos')}",
                'Active Videos' => "SELECT COUNT(*) FROM {$this->table('videos')} WHERE status_id = " . StatusFormatter::VIDEO_ACTIVE,
                'Total Views' => "SELECT SUM(video_viewed) FROM {$this->table('videos')}",
                'Total Duration' => "SELECT SUM(duration) FROM {$this->table('videos')}",
                'Average Rating' => "SELECT AVG(rating/rating_amount) FROM {$this->table('videos')} WHERE rating_amount > 0",
                'Total Size' => "SELECT SUM(file_size) FROM {$this->table('videos')}",
            ];

            foreach ($queries as $label => $query) {
                $result = $db->query($query);
                $rawValue = $result !== false ? $result->fetchColumn() : null;

                $displayValue = '0';
                if ($label === 'Total Duration') {
                    $intVal = is_numeric($rawValue) ? (int) $rawValue : 0;
                    $displayValue = $this->formatDuration($intVal);
                } elseif ($label === 'Average Rating') {
                    $displayValue = is_numeric($rawValue) ? sprintf('%.1f/%d', (float) $rawValue, Constants::RATING_SCALE) : 'N/A';
                } elseif ($label === 'Total Size') {
                    $intVal = is_numeric($rawValue) ? (int) $rawValue : 0;
                    $displayValue = format_bytes($intVal);
                } elseif (is_numeric($rawValue)) {
                    $displayValue = number_format((int) $rawValue);
                }

                $stats[] = [$label, $displayValue];
            }

            $this->renderTable(['Metric', 'Value'], $stats);

            $stmt = $db->query("
                SELECT v.title, v.video_viewed as views
                FROM {$this->table('videos')} v
                WHERE v.status_id = " . StatusFormatter::VIDEO_ACTIVE . "
                ORDER BY v.video_viewed DESC
                LIMIT " . Constants::TOP_QUERY_LIMIT . "
            ");
            $topVideos = $stmt !== false ? $stmt->fetchAll() : [];

            if ($topVideos !== []) {
                $this->io()->section('Top 10 Most Viewed Videos');
                $rows = [];
                foreach ($topVideos as $i => $video) {
                    if (!is_array($video)) {
                        continue;
                    }
                    $titleVal = $video['title'] ?? '';
                    $title = is_string($titleVal) ? $titleVal : (is_scalar($titleVal) ? (string) $titleVal : '');
                    $viewsVal = $video['views'] ?? 0;
                    $views = is_numeric($viewsVal) ? (float) $viewsVal : 0.0;
                    $rows[] = [
                        $i + 1,
                        substr($title, 0, Constants::DEFAULT_TRUNCATE_LENGTH),
                        number_format($views),
                    ];
                }
                $this->renderTable(['#', 'Title', 'Views'], $rows);
            }
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch statistics: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function showHelp(): int
    {
        $this->io()->info('Available actions:');
        $this->io()->listing([
            'list : List videos with filters',
            'show <id> : Show details for a specific video',
            'delete <id> : Delete a video',
            'update <id> : Update video information',
            'stats : Show video statistics',
        ]);

        $this->io()->section('Examples');
        $this->io()->text([
            'kvs content:video list --status=active --limit=10',
            'kvs content:video show 123',
            'kvs content:video list --search="example" --category=5',
            'kvs content:video stats',
        ]);

        return self::SUCCESS;
    }

    private function formatDuration(?int $seconds): string
    {
        if ($seconds === null || $seconds === 0) {
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
