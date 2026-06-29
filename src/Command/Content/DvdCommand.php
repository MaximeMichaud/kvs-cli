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
    /** @var list<string> */
    private const DVD_STRING_FIELD_FILTER_COLUMNS = [
        'description',
        'synonyms',
        'cover1_front',
        'cover1_back',
        'cover2_front',
        'cover2_back',
        'custom1',
        'custom2',
        'custom3',
        'custom4',
        'custom5',
        'custom6',
        'custom7',
        'custom8',
        'custom9',
        'custom10',
        'custom_file1',
        'custom_file2',
        'custom_file3',
        'custom_file4',
        'custom_file5',
        'tokens_required',
    ];

    /** @var list<string> */
    private const DVD_SPECIAL_FIELD_FILTERS = [
        'group',
        'user',
        'dvd_viewed',
        'rating',
        'tags',
        'categories',
        'models',
    ];

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'Action to perform (list|show|stats)', 'list')
            ->addArgument('id', InputArgument::OPTIONAL, 'DVD ID')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status (active|disabled)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results to show', Constants::DEFAULT_CONTENT_LIMIT)
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search in DVD titles, directories, descriptions, and synonyms')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Filter by user ID or username')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'Filter by DVD group ID or title')
            ->addOption('dvd-group', null, InputOption::VALUE_REQUIRED, 'Filter by DVD group ID or title')
            ->addOption('tag', null, InputOption::VALUE_REQUIRED, 'Filter by tag ID or name')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Filter by category ID or title')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Filter by model ID or title')
            ->addOption('usage', null, InputOption::VALUE_REQUIRED, 'KVS admin usage filter (used/videos|notused/videos)')
            ->addOption('review-needed', null, InputOption::VALUE_NONE, 'Show only DVDs that need review')
            ->addOption('not-review-needed', null, InputOption::VALUE_NONE, 'Show only DVDs that do not need review')
            ->addOption('field-filter', null, InputOption::VALUE_REQUIRED, 'KVS admin field filter (e.g. filled/tags)')
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
            'show' => $this->showDvd($id, $input),
            'stats' => $this->showStats($input),
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
        if ($this->hasDvdListOptionConflicts($input)) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $fromClause = "FROM {$this->table('dvds')} d";
        $whereClause = "WHERE 1=1";

        $params = [];

        if (!$this->applyDvdListFilters($db, $input, $whereClause, $params)) {
            return self::FAILURE;
        }

        if ($this->getStringOptionOrDefault($input, 'format', 'table') === 'count') {
            if ($this->getPositiveIntOptionOrDefault($input, 'limit', Constants::DEFAULT_CONTENT_LIMIT) === null) {
                return self::FAILURE;
            }
            return $this->countDvds($db, $fromClause, $whereClause, $params);
        }

        $commentsSelect = '';
        if ($this->isDvdFieldRequested($input, 'comments_amount')) {
            $commentsSelect = ",
                        (SELECT COUNT(*) FROM {$this->table('comments')} c
                         WHERE c.object_type_id = 5 AND c.object_id = d.dvd_id) as comments_amount";
        }
        $relationSelect = $this->buildDvdRelationSelectSql($input);
        $includeGroupFields = $this->isDvdFieldRequested($input, 'dvd_group')
            || $this->isDvdFieldRequested($input, 'dvd_group_status_id');
        $groupSelect = $includeGroupFields ? ",
                        dg.title as dvd_group,
                        dg.status_id as dvd_group_status_id" : '';
        $groupJoin = $includeGroupFields
            ? "LEFT JOIN {$this->table('dvds_groups')} dg ON dg.dvd_group_id = d.dvd_group_id"
            : '';

        $dvdFields = $this->getDvdListFields($input, $includeGroupFields);
        $fieldList = implode(', ', array_map(static fn (string $field): string => "d.$field", $dvdFields));
        $groupBy = implode(', ', array_map(static fn (string $field): string => "d.$field", $dvdFields));
        if ($includeGroupFields) {
            $groupBy .= ', dg.title, dg.status_id';
        }

        $query = "SELECT d.*$commentsSelect$groupSelect$relationSelect,
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
                    ...$dvd,
                    'dvd_id' => $dvd['dvd_id'] ?? 0,
                    'id' => $dvd['dvd_id'] ?? 0,
                    'title' => $dvd['title'] ?? '',
                    'thumb' => $dvd['cover1_front'] ?? $dvd['cover2_front'] ?? '',
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
                    'user' => $dvd['user'] ?? '',
                    'comments_amount' => $dvd['comments_amount'] ?? 0,
                    'subscribers_count' => $dvd['subscribers_count'] ?? 0,
                    'subscribers_amount' => $dvd['subscribers_count'] ?? 0,
                    'subscribers' => $dvd['subscribers_count'] ?? 0,
                    'rating' => format_kvs_rating($dvd['rating'] ?? 0, $dvd['rating_amount'] ?? 0),
                    'tags' => $dvd['tags'] ?? '',
                    'categories' => $dvd['categories'] ?? '',
                    'models' => $dvd['models'] ?? '',
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

    private function hasDvdListOptionConflicts(InputInterface $input): bool
    {
        if ($this->hasConflictingBoolOptions($input, ['review-needed', 'not-review-needed'])) {
            return true;
        }

        if ($this->getStringOption($input, 'group') !== null && $this->getStringOption($input, 'dvd-group') !== null) {
            $this->io()->error('Options --group and --dvd-group cannot be used together');
            return true;
        }

        return false;
    }

    /**
     * @param array<string, int|string> $params
     */
    private function applyDvdListFilters(
        \PDO $db,
        InputInterface $input,
        string &$whereClause,
        array &$params
    ): bool {
        $status = $this->getStringOption($input, 'status');
        if ($status !== null) {
            $statusId = $this->parseStatusFilterOrFail($input, [
                'active' => StatusFormatter::DVD_ACTIVE,
                'disabled' => StatusFormatter::DVD_DISABLED,
                'inactive' => StatusFormatter::DVD_DISABLED,
            ]);
            if ($statusId === false) {
                return false;
            }
            if ($statusId !== null) {
                $whereClause .= " AND d.status_id = :status";
                $params['status'] = $statusId;
            }
        }

        $search = $this->getStringOption($input, 'search');
        if ($search !== null) {
            $searchEscape = $this->likeEscapeSql();
            $whereClause .= " AND (d.title LIKE :search" . $searchEscape
                . " OR d.dir LIKE :search" . $searchEscape
                . " OR d.description LIKE :search" . $searchEscape
                . " OR d.synonyms LIKE :search" . $searchEscape . ")";
            $params['search'] = $this->containsLikePattern($search);
        }

        $user = $this->resolveUserIdOption($db, $input);
        if ($user === false) {
            return false;
        }
        if ($user !== null) {
            $whereClause .= ' AND d.user_id = :user';
            $params['user'] = $user;
        }

        if (!$this->applyDvdAdminRelationFilters($db, $input, $whereClause, $params)) {
            return false;
        }

        $usage = $this->getStringOption($input, 'usage');
        if ($usage !== null) {
            $condition = $this->getDvdUsageFilterCondition($usage);
            if ($condition === null) {
                $this->io()->error('Invalid DVD usage filter. Use: used/videos or notused/videos');
                return false;
            }
            $whereClause .= " AND {$condition}";
        }

        if ($this->getBoolOption($input, 'review-needed')) {
            $whereClause .= ' AND d.is_review_needed = 1';
        } elseif ($this->getBoolOption($input, 'not-review-needed')) {
            $whereClause .= ' AND d.is_review_needed = 0';
        }

        $fieldFilter = $this->getStringOption($input, 'field-filter');
        if ($fieldFilter !== null) {
            $condition = $this->getDvdFieldFilterCondition($fieldFilter);
            if ($condition === null) {
                $this->io()->error('Invalid DVD field filter. Use: ' . implode(', ', $this->getDvdFieldFilterValues()));
                return false;
            }
            $whereClause .= " AND {$condition}";
        }

        return true;
    }

    /**
     * @param array<string, int|string> $params
     */
    private function applyDvdAdminRelationFilters(
        \PDO $db,
        InputInterface $input,
        string &$whereClause,
        array &$params
    ): bool {
        $group = $this->resolveDvdGroupIdOption($db, $input);
        if ($group === false) {
            return false;
        }
        if ($group !== null) {
            $whereClause .= ' AND d.dvd_group_id = :dvd_group';
            $params['dvd_group'] = $group;
        }

        $tag = $this->resolveTagIdOption($db, $input);
        if ($tag === false) {
            return false;
        }
        if ($tag !== null) {
            $whereClause .= " AND EXISTS (SELECT 1 FROM {$this->table('tags_dvds')} td "
                . 'WHERE td.dvd_id = d.dvd_id AND td.tag_id = :tag)';
            $params['tag'] = $tag;
        }

        $category = $this->resolveCategoryIdOption($db, $input);
        if ($category === false) {
            return false;
        }
        if ($category !== null) {
            $whereClause .= " AND EXISTS (SELECT 1 FROM {$this->table('categories_dvds')} cd "
                . 'WHERE cd.dvd_id = d.dvd_id AND cd.category_id = :category)';
            $params['category'] = $category;
        }

        $model = $this->resolveModelIdOption($db, $input);
        if ($model === false) {
            return false;
        }
        if ($model !== null) {
            $whereClause .= " AND EXISTS (SELECT 1 FROM {$this->table('models_dvds')} md "
                . 'WHERE md.dvd_id = d.dvd_id AND md.model_id = :model)';
            $params['model'] = $model;
        }

        return true;
    }

    private function resolveDvdGroupIdOption(\PDO $db, InputInterface $input): int|false|null
    {
        $group = $this->getStringOption($input, 'group') ?? $this->getStringOption($input, 'dvd-group');
        if ($group === null) {
            return null;
        }
        if (preg_match('/^[1-9]\d*$/', $group) === 1) {
            return (int) $group;
        }

        $stmt = $db->prepare("SELECT dvd_group_id FROM {$this->table('dvds_groups')} WHERE title = :title LIMIT 1");
        $stmt->execute(['title' => $group]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return -1;
        }

        return is_numeric($id) ? (int) $id : false;
    }

    private function getDvdUsageFilterCondition(string $usage): ?string
    {
        $videosTable = $this->table('videos');

        return match ($usage) {
            'used/videos' => "EXISTS (SELECT 1 FROM {$videosTable} v_filter WHERE v_filter.dvd_id = d.dvd_id)",
            'notused/videos' => "NOT EXISTS (SELECT 1 FROM {$videosTable} v_filter WHERE v_filter.dvd_id = d.dvd_id)",
            default => null,
        };
    }

    /** @return list<string> */
    private function getDvdFieldFilterValues(): array
    {
        $values = [];
        foreach (['empty', 'filled'] as $prefix) {
            foreach (self::DVD_STRING_FIELD_FILTER_COLUMNS as $column) {
                $values[] = "{$prefix}/{$column}";
            }
            foreach (self::DVD_SPECIAL_FIELD_FILTERS as $field) {
                $values[] = "{$prefix}/{$field}";
            }
        }

        return $values;
    }

    private function getDvdFieldFilterCondition(string $fieldFilter): ?string
    {
        $parts = explode('/', $fieldFilter, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$state, $field] = $parts;
        if (!in_array($state, ['empty', 'filled'], true)) {
            return null;
        }

        return $state === 'empty'
            ? $this->getEmptyDvdFieldFilterCondition($field)
            : $this->getFilledDvdFieldFilterCondition($field);
    }

    private function getEmptyDvdFieldFilterCondition(string $field): ?string
    {
        if (in_array($field, self::DVD_STRING_FIELD_FILTER_COLUMNS, true)) {
            return "d.{$field} = ''";
        }

        return match ($field) {
            'group' => 'd.dvd_group_id = 0',
            'user' => 'd.user_id = 0',
            'dvd_viewed' => 'd.dvd_viewed = 0',
            'rating' => '(d.rating = 0 AND d.rating_amount = 1)',
            'tags' => $this->getDvdRelationExistsCondition('tags_dvds', 'tag_id', false),
            'categories' => $this->getDvdRelationExistsCondition('categories_dvds', 'category_id', false),
            'models' => $this->getDvdRelationExistsCondition('models_dvds', 'model_id', false),
            default => null,
        };
    }

    private function getFilledDvdFieldFilterCondition(string $field): ?string
    {
        if (in_array($field, self::DVD_STRING_FIELD_FILTER_COLUMNS, true)) {
            return "d.{$field} != ''";
        }

        return match ($field) {
            'group' => 'd.dvd_group_id != 0',
            'user' => 'd.user_id != 0',
            'dvd_viewed' => 'd.dvd_viewed != 0',
            'rating' => '(d.rating > 0 OR d.rating_amount > 1)',
            'tags' => $this->getDvdRelationExistsCondition('tags_dvds', 'tag_id', true),
            'categories' => $this->getDvdRelationExistsCondition('categories_dvds', 'category_id', true),
            'models' => $this->getDvdRelationExistsCondition('models_dvds', 'model_id', true),
            default => null,
        };
    }

    private function getDvdRelationExistsCondition(string $relationTable, string $idColumn, bool $exists): string
    {
        $table = $this->table($relationTable);
        $operator = $exists ? 'EXISTS' : 'NOT EXISTS';
        return "{$operator} (SELECT {$idColumn} FROM {$table} rel_filter WHERE rel_filter.dvd_id = d.dvd_id)";
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

    private function showDvd(?string $id, InputInterface $input): int
    {
        $dvdId = $this->getRequiredPositiveId($id, 'DVD');
        if ($dvdId === null) {
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
            $stmt->execute(['id' => $dvdId]);
            $dvd = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($dvd)) {
                $this->io()->error("DVD not found: $dvdId");
                return self::FAILURE;
            }

            // Display DVD details
            $titleValue = $dvd['title'] ?? '';
            $dvdTitle = is_scalar($titleValue) ? (string) $titleValue : '';

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

            if (!$this->isTableFormat($input)) {
                return $this->displayDetailRows($input, $info);
            }

            $this->io()->title("DVD: $dvdTitle");
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

    /**
     * @return list<string>
     */
    private function getDvdListFields(InputInterface $input, bool $includeGroupFields): array
    {
        $fields = [
            'dvd_id',
            'title',
            'status_id',
            'release_year',
            'dvd_viewed',
            'subscribers_count',
            'rating',
            'rating_amount',
        ];

        $optionalFields = [
            'dir',
            'description',
            'synonyms',
            'cover1_front',
            'cover1_back',
            'cover2_front',
            'cover2_back',
            'is_video_upload_allowed',
            'tokens_required',
            'avg_videos_rating',
            'avg_videos_popularity',
            'added_date',
            'sort_id',
        ];
        foreach ($optionalFields as $field) {
            if ($this->isDvdFieldRequested($input, $field)) {
                $fields[] = $field;
            }
        }
        for ($i = 1; $i <= 10; $i++) {
            $field = "custom{$i}";
            if ($this->isDvdFieldRequested($input, $field)) {
                $fields[] = $field;
            }
        }
        for ($i = 1; $i <= 5; $i++) {
            $field = "custom_file{$i}";
            if ($this->isDvdFieldRequested($input, $field)) {
                $fields[] = $field;
            }
        }

        if ($this->isDvdFieldRequested($input, 'thumb')) {
            $fields[] = 'cover1_front';
        }
        if ($this->isDvdFieldRequested($input, 'user') || $this->isDvdFieldRequested($input, 'user_status_id')) {
            $fields[] = 'user_id';
        }
        if ($includeGroupFields) {
            $fields[] = 'dvd_group_id';
        }

        return array_values(array_unique($fields));
    }

    private function buildDvdRelationSelectSql(InputInterface $input): string
    {
        $selects = [];

        if ($this->isDvdFieldRequested($input, 'user')) {
            $selects[] = "(
                SELECT u.username
                FROM {$this->table('users')} u
                WHERE u.user_id = d.user_id
            ) as user";
        }
        if ($this->isDvdFieldRequested($input, 'user_status_id')) {
            $selects[] = "(
                SELECT u.status_id
                FROM {$this->table('users')} u
                WHERE u.user_id = d.user_id
            ) as user_status_id";
        }
        if ($this->isDvdFieldRequested($input, 'tags')) {
            $selects[] = "(
                SELECT GROUP_CONCAT(t.tag ORDER BY td.id ASC)
                FROM {$this->table('tags')} t
                INNER JOIN {$this->table('tags_dvds')} td ON td.tag_id = t.tag_id
                WHERE td.dvd_id = d.dvd_id
            ) as tags";
        }
        if ($this->isDvdFieldRequested($input, 'categories')) {
            $selects[] = "(
                SELECT GROUP_CONCAT(c.title ORDER BY cd.id ASC)
                FROM {$this->table('categories')} c
                INNER JOIN {$this->table('categories_dvds')} cd ON cd.category_id = c.category_id
                WHERE cd.dvd_id = d.dvd_id
            ) as categories";
        }
        if ($this->isDvdFieldRequested($input, 'models')) {
            $selects[] = "(
                SELECT GROUP_CONCAT(m.title ORDER BY md.id ASC)
                FROM {$this->table('models')} m
                INNER JOIN {$this->table('models_dvds')} md ON md.model_id = m.model_id
                WHERE md.dvd_id = d.dvd_id
            ) as models";
        }

        return $selects === [] ? '' : ",\n                        " . implode(",\n                        ", $selects);
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

    private function showStats(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stats = [];
            /** @var list<array<string, mixed>> $metricRows */
            $metricRows = [];

            // Total DVDs
            $stmt = $db->query("SELECT COUNT(*) FROM {$this->table('dvds')}");
            if ($stmt !== false) {
                $value = (int) $stmt->fetchColumn();
                $stats[] = ['Total DVDs', number_format($value)];
                $metricRows[] = $this->metricRow('overall', 'Total DVDs', $value, number_format($value));
            }

            // Active DVDs
            $stmt = $db->query("SELECT COUNT(*) FROM {$this->table('dvds')} WHERE status_id = " . StatusFormatter::DVD_ACTIVE);
            if ($stmt !== false) {
                $value = (int) $stmt->fetchColumn();
                $stats[] = ['Active', number_format($value)];
                $metricRows[] = $this->metricRow('overall', 'Active', $value, number_format($value));
            }

            // Inactive DVDs
            $stmt = $db->query("SELECT COUNT(*) FROM {$this->table('dvds')} WHERE status_id = " . StatusFormatter::DVD_DISABLED);
            if ($stmt !== false) {
                $value = (int) $stmt->fetchColumn();
                $stats[] = ['Inactive', number_format($value)];
                $metricRows[] = $this->metricRow('overall', 'Inactive', $value, number_format($value));
            }

            if (!$this->isTableFormat($input)) {
                $this->displayMetricRows($input, $metricRows);
                return self::SUCCESS;
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
