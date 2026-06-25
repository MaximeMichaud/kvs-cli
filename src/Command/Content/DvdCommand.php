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

use function KVS\CLI\Utils\format_kvs_rating;

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
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results to show', Constants::DEFAULT_CONTENT_LIMIT)
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
        $action = $this->getStringArgument($input, 'action') ?? 'list';
        $id = $this->getStringArgument($input, 'id');

        return match ($action) {
            'list' => $this->listDvds($input),
            'show' => $this->showDvd($id),
            'stats' => $this->showStats(),
            default => $this->unknownAction($action),
        };
    }

    private function unknownAction(string $action): int
    {
        $this->io()->error(sprintf(
            'Unknown DVD action "%s". Available actions: list, show, stats.',
            $action
        ));

        return self::FAILURE;
    }

    private function listDvds(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $fromClause = "FROM {$this->table('dvds')} d";
        $whereClause = "WHERE 1=1";

        $params = [];

        // Status filter
        $status = $this->getStringOption($input, 'status');
        if ($status !== null) {
            $statusId = $this->parseStatusFilter($input, [
                'active' => StatusFormatter::DVD_ACTIVE,
                'disabled' => StatusFormatter::DVD_DISABLED,
                'inactive' => StatusFormatter::DVD_DISABLED,
            ]);
            if ($statusId !== null) {
                $whereClause .= " AND d.status_id = :status";
                $params['status'] = $statusId;
            }
        }

        // Search filter
        $search = $this->getStringOption($input, 'search');
        if ($search !== null) {
            $whereClause .= " AND d.title LIKE :search";
            $params['search'] = "%$search%";
        }

        if ($this->getStringOptionOrDefault($input, 'format', 'table') === 'count') {
            return $this->countDvds($db, $fromClause, $whereClause, $params);
        }

        $commentsSelect = '';
        if ($this->isDvdFieldRequested($input, 'comments_amount')) {
            $commentsSelect = ",
                        (SELECT COUNT(*) FROM {$this->table('comments')} c
                         WHERE c.object_type_id = 5 AND c.object_id = d.dvd_id) as comments_amount";
        }
        $includeGroupFields = $this->isDvdFieldRequested($input, 'dvd_group')
            || $this->isDvdFieldRequested($input, 'dvd_group_status_id');
        $groupSelect = $includeGroupFields ? ",
                        dg.title as dvd_group,
                        dg.status_id as dvd_group_status_id" : '';
        $groupJoin = $includeGroupFields
            ? "LEFT JOIN {$this->table('dvds_groups')} dg ON dg.dvd_group_id = d.dvd_group_id"
            : '';

        $dvdFields = [
            'dvd_id',
            'title',
            'status_id',
            'release_year',
            'dvd_viewed',
            'subscribers_count',
            'rating',
            'rating_amount',
        ];
        if ($includeGroupFields) {
            $dvdFields[] = 'dvd_group_id';
        }
        $fieldList = implode(', ', array_map(static fn (string $field): string => "d.$field", $dvdFields));
        $groupBy = implode(', ', array_map(static fn (string $field): string => "d.$field", $dvdFields));
        if ($includeGroupFields) {
            $groupBy .= ', dg.title, dg.status_id';
        }

        $query = "SELECT d.*$commentsSelect$groupSelect,
                        COUNT(v.dvd_id) as video_count,
                        COALESCE(SUM(v.duration), 0) as video_duration
                 FROM (
                     SELECT $fieldList
                     $fromClause
                     $whereClause
                     ORDER BY d.dvd_id DESC LIMIT :limit
                 ) d
                 $groupJoin
                 LEFT JOIN {$this->table('videos')} v ON v.dvd_id = d.dvd_id
                 GROUP BY $groupBy
                 ORDER BY d.dvd_id DESC";

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

            /** @var list<array<string, mixed>> $dvds */
            $dvds = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Transform DVDs for display (field aliases)
            $transformedDvds = array_map(function (array $dvd): array {
                $statusIdVal = $dvd['status_id'] ?? 0;
                $statusId = is_numeric($statusIdVal) ? (int) $statusIdVal : 0;

                $durationVal = $dvd['video_duration'] ?? 0;
                $duration = is_numeric($durationVal) ? (int) $durationVal : 0;

                return [
                    'dvd_id' => $dvd['dvd_id'] ?? 0,
                    'id' => $dvd['dvd_id'] ?? 0,
                    'title' => $dvd['title'] ?? '',
                    'status_id' => $statusId,
                    'status' => StatusFormatter::dvd($statusId, false),
                    'total_videos' => $dvd['video_count'] ?? 0,
                    'videos_amount' => $dvd['video_count'] ?? 0,
                    'videos' => $dvd['video_count'] ?? 0,
                    'total_videos_duration' => $duration,
                    'total_duration' => $this->formatDvdDuration($duration),
                    'duration' => $this->formatDvdDuration($duration),
                    'release_year' => $this->formatDvdReleaseYear($dvd['release_year'] ?? null),
                    'dvd_viewed' => $dvd['dvd_viewed'] ?? 0,
                    'views' => $dvd['dvd_viewed'] ?? 0,
                    'dvd_group' => $dvd['dvd_group'] ?? '',
                    'dvd_group_status_id' => $dvd['dvd_group_status_id'] ?? '',
                    'comments_amount' => $dvd['comments_amount'] ?? 0,
                    'subscribers_count' => $dvd['subscribers_count'] ?? 0,
                    'subscribers_amount' => $dvd['subscribers_count'] ?? 0,
                    'subscribers' => $dvd['subscribers_count'] ?? 0,
                    'rating' => format_kvs_rating($dvd['rating'] ?? 0, $dvd['rating_amount'] ?? 0),
                ];
            }, $dvds);

            // Default fields
            $defaultFields = ['dvd_id', 'title', 'status', 'total_videos'];

            // Format and display using Formatter
            $formatter = new Formatter($input->getOptions(), $defaultFields);
            $formatter->display($transformedDvds, $this->io());

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch DVDs: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function countDvds(\PDO $db, string $fromClause, string $whereClause, array $params): int
    {
        try {
            $stmt = $db->prepare("SELECT COUNT(*) $fromClause $whereClause");
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();

            $total = $stmt->fetchColumn();
            $this->io()->writeln((string) (is_numeric($total) ? (int) $total : 0));

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to count DVDs: ' . $e->getMessage());
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
                SELECT d.dvd_id,
                       d.title,
                       d.status_id,
                       d.dvd_viewed,
                       d.release_year,
                       d.rating_amount,
                       d.rating,
                       d.subscribers_count,
                       d.description,
                       COUNT(v.dvd_id) as video_count,
                       COALESCE(SUM(v.duration), 0) as video_duration
                FROM {$this->table('dvds')} d
                LEFT JOIN {$this->table('videos')} v ON v.dvd_id = d.dvd_id
                WHERE d.dvd_id = :id
                GROUP BY d.dvd_id,
                         d.title,
                         d.status_id,
                         d.dvd_viewed,
                         d.release_year,
                         d.rating_amount,
                         d.rating,
                         d.subscribers_count,
                         d.description
            ");
            $stmt->execute(['id' => $id]);
            $dvd = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($dvd)) {
                $this->io()->error("DVD not found: $id");
                return self::FAILURE;
            }

            // Display DVD details
            $titleValue = $dvd['title'] ?? '';
            $dvdTitle = is_scalar($titleValue) ? (string) $titleValue : '';
            $this->io()->title("DVD: $dvdTitle");

            $totalVideosVal = $dvd['video_count'] ?? 0;
            $dvdViewedVal = $dvd['dvd_viewed'] ?? 0;
            $dvdIdVal = $dvd['dvd_id'] ?? 0;
            $statusIdVal = $dvd['status_id'] ?? 0;
            $totalVideos = is_numeric($totalVideosVal) ? (int) $totalVideosVal : 0;
            $dvdViewed = is_numeric($dvdViewedVal) ? (int) $dvdViewedVal : 0;
            $dvdIdStr = is_scalar($dvdIdVal) ? (string) $dvdIdVal : '0';
            $statusId = is_numeric($statusIdVal) ? (int) $statusIdVal : 0;

            $info = [
                ['DVD ID', $dvdIdStr],
                ['Title', $dvdTitle],
                ['Status', StatusFormatter::dvd($statusId)],
                ['Videos', number_format($totalVideos)],
                ['Views', number_format($dvdViewed)],
            ];

            // Duration
            $durationVal = $dvd['video_duration'] ?? 0;
            $duration = is_numeric($durationVal) ? (int) $durationVal : 0;
            if ($duration > 0) {
                $info[] = ['Total Duration', $this->formatDvdDuration($duration)];
            }

            // Release year
            $releaseYear = $dvd['release_year'] ?? null;
            if ($this->hasDvdReleaseYear($releaseYear)) {
                $info[] = ['Release Year', is_scalar($releaseYear) ? (string) $releaseYear : ''];
            }

            // Rating
            $ratingAmountVal = $dvd['rating_amount'] ?? 0;
            $ratingVal = $dvd['rating'] ?? 0;
            $ratingAmount = is_numeric($ratingAmountVal) ? (int) $ratingAmountVal : 0;
            if ($ratingAmount > 0) {
                $info[] = ['Rating', format_kvs_rating($ratingVal, $ratingAmount)];
            }

            // Subscribers
            $subscribersCountVal = $dvd['subscribers_count'] ?? null;
            $subscribersCount = is_numeric($subscribersCountVal) ? (int) $subscribersCountVal : 0;
            if ($subscribersCount > 0) {
                $info[] = ['Subscribers', number_format($subscribersCount)];
            }

            $description = $dvd['description'] ?? null;
            if ($description !== null && $description !== '') {
                $info[] = ['Description', is_scalar($description) ? (string) $description : ''];
            }

            $this->renderTable(['Field', 'Value'], $info);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch DVD: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function isDvdFieldRequested(InputInterface $input, string $field): bool
    {
        $singleField = $this->getStringOption($input, 'field');
        if ($singleField === $field) {
            return true;
        }

        $fields = $this->getStringOption($input, 'fields');
        if ($fields === null) {
            return false;
        }

        foreach (array_map('trim', explode(',', $fields)) as $requestedField) {
            if ($requestedField === $field) {
                return true;
            }
        }

        return false;
    }

    private function formatDvdReleaseYear(mixed $releaseYear): int|string
    {
        if (is_numeric($releaseYear)) {
            $year = (int) $releaseYear;
            return $year === 0 ? '-' : $year;
        }

        if ($releaseYear === null || $releaseYear === '') {
            return '-';
        }

        return is_scalar($releaseYear) ? (string) $releaseYear : '-';
    }

    private function hasDvdReleaseYear(mixed $releaseYear): bool
    {
        if (is_numeric($releaseYear)) {
            return (int) $releaseYear > 0;
        }

        return $releaseYear !== null && $releaseYear !== '';
    }

    private function formatDvdDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $remainingSeconds = $seconds - ($hours * 3600);
        $minutes = intdiv($remainingSeconds, 60);
        $remainingSeconds = $seconds - ($hours * 3600) - ($minutes * 60);

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $remainingSeconds);
        }

        return sprintf('%d:%02d', $minutes, $remainingSeconds);
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
