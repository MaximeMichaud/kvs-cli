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
use function KVS\CLI\Utils\calculate_kvs_rating;
use function KVS\CLI\Utils\format_kvs_rating;

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
            ->addOption('field', null, InputOption::VALUE_REQUIRED, 'Display single field value')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count, ids', 'table')
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
  resolution      Resolution type (SD/HD/FHD/4K+)
  is_private      Access level (Public/Private/Premium)
  favourites      Favourites count

<fg=yellow>EXAMPLES:</>
  <fg=green>kvs video list</>
  <fg=green>kvs video list --no-truncate</>
  <fg=green>kvs video list --fields=id,title,views,user</>
  <fg=green>kvs video list --field=title</>
  <fg=green>kvs video list --format=csv</>
  <fg=green>kvs video list --format=ids</>
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

        if ($this->getBoolOption($input, 'stats')) {
            return $this->showStats();
        }

        if ($action === null || $action === '') {
            return $this->showHelp();
        }

        return match ($action) {
            'list' => $this->listVideos($input),
            'show' => $this->showVideo($this->getStringArgument($input, 'id')),
            'delete' => $this->deleteVideo($this->getStringArgument($input, 'id'), $input),
            'update' => $this->updateVideo($this->getStringArgument($input, 'id'), $input),
            'stats' => $this->showStats(),
            default => $this->failUnknownAction('video', $action, ['list', 'show', 'delete', 'update', 'stats']),
        };
    }

    private function listVideos(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $fromSql = "FROM {$this->table('videos')} v
                 LEFT JOIN {$this->table('users')} u ON v.user_id = u.user_id
                 WHERE 1=1";

        $params = [];

        $status = $this->getStringOption($input, 'status');
        if ($status !== null) {
            $statusId = $this->parseStatusFilter($input, [
                'active' => StatusFormatter::VIDEO_ACTIVE,
                'disabled' => StatusFormatter::VIDEO_DISABLED,
                'error' => StatusFormatter::VIDEO_ERROR,
            ], [0, 1, 2, 3, 4, 5]);
            if ($statusId !== null) {
                $fromSql .= " AND v.status_id = :status";
                $params['status'] = $statusId;
            }
        }

        $search = $this->getStringOption($input, 'search');
        if ($search !== null) {
            $fromSql .= " AND v.title LIKE :search";
            $params['search'] = "%$search%";
        }

        $category = $this->getIntOption($input, 'category');
        if ($category !== null) {
            $fromSql .= " AND EXISTS (SELECT 1 FROM {$this->table('categories_videos')} cv "
                . "WHERE cv.video_id = v.video_id AND cv.category_id = :category)";
            $params['category'] = $category;
        }

        $user = $this->getIntOption($input, 'user');
        if ($user !== null) {
            $fromSql .= " AND v.user_id = :user";
            $params['user'] = $user;
        }

        if ($this->getStringOptionOrDefault($input, 'format', 'table') === 'count') {
            return $this->countVideos($db, $fromSql, $params);
        }

        $selectFields = [
            'v.*',
            'u.username',
            'v.video_viewed as views',
        ];
        if ($this->isFieldRequested($input, 'comments_count')) {
            $commentsTable = $this->table('comments');
            $selectFields[] = "(
                SELECT COUNT(*) FROM $commentsTable c
                WHERE c.object_type_id = 1 AND c.object_id = v.video_id
            ) as comments_count";
        }

        $query = 'SELECT ' . implode(",\n                 ", $selectFields) . "
                 $fromSql
                 ORDER BY v.post_date DESC LIMIT :limit";

        try {
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $limit = $this->getPositiveIntOptionOrDefault($input, 'limit', Constants::DEFAULT_CONTENT_LIMIT);
            if ($limit === null) {
                return self::FAILURE;
            }
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
                $privacyId = isset($video['is_private']) && is_numeric($video['is_private']) ? (int) $video['is_private'] : 0;
                $privacy = StatusFormatter::contentPrivacy($privacyId, false);
                $video['is_private'] = $privacy;
                $video['access'] = $privacy;
                $resolutionType = isset($video['resolution_type']) && is_numeric($video['resolution_type'])
                    ? (int) $video['resolution_type']
                    : 0;
                $video['resolution'] = $this->formatResolutionType($resolutionType, false);

                if (array_key_exists('duration', $video)) {
                    $durationVal = $video['duration'];
                    $video['duration'] = $this->formatDuration(is_numeric($durationVal) ? (int) $durationVal : null);
                }

                if (array_key_exists('file_size', $video)) {
                    $fileSizeVal = $video['file_size'];
                    $fileSize = is_numeric($fileSizeVal) ? (int) $fileSizeVal : 0;
                    $video['filesize'] = format_bytes($fileSize);
                }
                if (array_key_exists('r_ctr', $video) && is_numeric($video['r_ctr'])) {
                    $video['r_ctr'] = round((float) $video['r_ctr'] * 100, 4);
                }

                $video['rating'] = format_kvs_rating($video['rating'] ?? 0, $video['rating_amount'] ?? 0);

                return $video;
            }, $videos);

            // Format and display output using centralized Formatter
            $formatter = new Formatter(
                $input->getOptions(),
                ['video_id', 'title', 'status', 'views', 'username', 'post_date']
            );
            $formatter->display($videos, $this->io());

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch videos: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function isFieldRequested(InputInterface $input, string $field): bool
    {
        $fieldOption = $this->getStringOption($input, 'field');
        if ($fieldOption === $field) {
            return true;
        }

        $fieldsOption = $this->getStringOption($input, 'fields');
        if ($fieldsOption === null || $fieldsOption === '') {
            return false;
        }

        $fields = array_map('trim', explode(',', $fieldsOption));
        return in_array($field, $fields, true);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function countVideos(\PDO $db, string $fromSql, array $params): int
    {
        try {
            $stmt = $db->prepare("SELECT COUNT(*) $fromSql");
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();

            $this->io()->writeln((string) (int) $stmt->fetchColumn());
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to count videos: ' . $e->getMessage());
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
            /** @var array{title: string, status_id: int, resolution_type: int, is_private: int, duration: int, file_size: int, file_dimensions: string, post_date: string, rating: int, rating_amount: int, video_viewed: int, favourites_count: int, description: string}|false $video */
            $video = $stmt->fetch();

            if ($video === false) {
                $this->io()->error("Video not found: $id");
                return self::FAILURE;
            }

            $this->io()->section("Video #$id");

            $postTimestamp = strtotime($video['post_date']);
            $info = [
                ['Title', $video['title']],
                ['Status', StatusFormatter::video($video['status_id'])],
                ['Resolution', $this->formatResolutionType($video['resolution_type'])],
                ['Access', StatusFormatter::contentPrivacy($video['is_private'])],
                ['Duration', $this->formatDuration($video['duration'])],
                ['File Size', format_bytes($video['file_size'])],
                ['Dimensions', $video['file_dimensions']],
                ['Posted', $postTimestamp !== false ? date('Y-m-d H:i:s', $postTimestamp) : 'Unknown'],
                [
                    'Rating',
                    format_kvs_rating($video['rating'], $video['rating_amount'])
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
                $this->io()->text(implode(', ', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $tags)));
            }
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch video: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function deleteVideo(?string $id, InputInterface $input): int
    {
        if ($id === null || $id === '') {
            $this->io()->error('Video ID is required');
            return self::FAILURE;
        }
        if (!ctype_digit($id)) {
            $this->io()->error('Video ID must be numeric');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("SELECT video_id, status_id FROM {$this->table('videos')} WHERE video_id = :id");
            $stmt->execute(['id' => $id]);
            /** @var array{video_id: int|string, status_id: int|string}|false $video */
            $video = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($video === false || !is_numeric($video['video_id'])) {
                $this->io()->error("Video not found: #$id");
                return self::FAILURE;
            }

            $videoId = (int) $video['video_id'];
            $statusId = is_numeric($video['status_id']) ? (int) $video['status_id'] : -1;
            $deletableStatuses = [
                StatusFormatter::VIDEO_DISABLED,
                StatusFormatter::VIDEO_ACTIVE,
                StatusFormatter::VIDEO_ERROR,
            ];
            if (!in_array($statusId, $deletableStatuses, true)) {
                $this->io()->error(sprintf(
                    'Video cannot be deleted in its current status: #%d (%s)',
                    $videoId,
                    StatusFormatter::video($statusId, false)
                ));
                return self::FAILURE;
            }

            $this->io()->warning("This will delete video #$id using KVS native cleanup");
            $this->io()->warning('Files, references and counters will be queued for KVS background deletion.');

            if ($this->io()->confirm('Do you want to continue?', false) !== true) {
                if (!$input->isInteractive()) {
                    $this->io()->error('Video deletion cancelled because confirmation was not provided.');
                    return self::FAILURE;
                }

                $this->io()->warning('Video deletion cancelled');
                return self::SUCCESS;
            }

            $this->deleteVideoWithKvs($videoId);
            $this->io()->success("Video #$id queued for KVS deletion");
        } catch (\Exception $e) {
            $this->io()->error('Failed to delete video: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function deleteVideoWithKvs(int $videoId): void
    {
        $this->runWithKvsAdminContext(function () use ($videoId): void {
            if (!function_exists('delete_video')) {
                throw new \RuntimeException('KVS delete_video function is not available');
            }

            if (delete_video($videoId) !== true) {
                throw new \RuntimeException("KVS refused to delete video #$videoId");
            }
        }, ['functions_servers.php', 'functions_admin.php']);
    }

    private function updateVideo(?string $id, InputInterface $input): int
    {
        if ($id === null || $id === '') {
            $this->io()->error('Video ID is required');
            return self::FAILURE;
        }

        $this->io()->error('Update functionality is not yet implemented');
        $this->io()->note('Use the KVS admin panel to update videos for now.');

        return self::FAILURE;
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
                    $displayValue = is_numeric($rawValue)
                        ? sprintf('%.1f/%d', calculate_kvs_rating($rawValue, 1), Constants::RATING_SCALE)
                        : 'N/A';
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

    private function formatResolutionType(int $resolutionType, bool $withColor = true): string
    {
        $label = match (true) {
            $resolutionType === 1 => 'HD',
            $resolutionType === 2 => 'FHD',
            $resolutionType > 2 => "{$resolutionType}K",
            default => 'SD',
        };

        if (!$withColor) {
            return $label;
        }

        $color = match (true) {
            $resolutionType === 1 => 'green',
            $resolutionType === 2 => 'cyan',
            $resolutionType > 2 => 'magenta',
            default => 'gray',
        };

        return sprintf('<fg=%s>%s</>', $color, $label);
    }
}
