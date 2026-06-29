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
    /** @var list<string> */
    private const SHOW_UNSUPPORTED_OPTIONS = [
        'status',
        'search',
        'resolution',
        'load-type',
        'category',
        'category-group',
        'tag',
        'model',
        'content-source',
        'content-source-group',
        'dvd',
        'dvd-group',
        'model-group',
        'playlist',
        'user',
        'admin-user',
        'ip',
        'server-group',
        'format-video-group',
        'feed',
        'has-errors',
        'posted',
        'neuroscore',
        'digiregs-copyright',
        'show-id',
        'public',
        'private',
        'premium',
        'access-level',
        'review-needed',
        'not-review-needed',
        'locked',
        'unlocked',
        'field-filter',
        'flag',
        'flag-votes',
        'post-date-from',
        'post-date-to',
        'duration-from',
        'duration-to',
        'limit',
    ];

    /** @var list<string> */
    private const VIDEO_STRING_FIELD_FILTER_COLUMNS = [
        'title',
        'description',
        'gallery_url',
        'custom1',
        'custom2',
        'custom3',
    ];

    /** @var list<string> */
    private const VIDEO_ZERO_FIELD_FILTER_COLUMNS = [
        'af_custom1',
        'af_custom2',
        'af_custom3',
        'tokens_required',
        'video_viewed',
        'video_viewed_unique',
    ];

    /** @var list<string> */
    private const VIDEO_SPECIAL_FIELD_FILTERS = [
        'content_source',
        'dvd',
        'admin',
        'admin_flag',
        'comments',
        'favourites',
        'purchases',
        'rating',
        'tags',
        'categories',
        'models',
    ];

    /** @var array<string, array{0: bool, 1: int, 2: int|null}> */
    private const VIDEO_NEUROSCORE_FILTERS = [
        'score_missing' => [false, 1, null],
        'score_postponed' => [true, 1, 0],
        'score_processing' => [true, 1, 1],
        'score_finished' => [true, 1, 2],
        'title_missing' => [false, 3, null],
        'title_postponed' => [true, 3, 0],
        'title_processing' => [true, 3, 1],
        'title_finished' => [true, 3, 2],
        'categories_missing' => [false, 4, null],
        'categories_postponed' => [true, 4, 0],
        'categories_processing' => [true, 4, 1],
        'categories_finished' => [true, 4, 2],
        'models_missing' => [false, 5, null],
        'models_postponed' => [true, 5, 0],
        'models_processing' => [true, 5, 1],
        'models_finished' => [true, 5, 2],
    ];

    /** @var array<string, array{0: bool, 1: int, 2: int|null}> */
    private const VIDEO_DIGIREGS_COPYRIGHT_FILTERS = [
        'copyright_applied' => [true, 2, null],
        'copyright_not_applied' => [false, 2, null],
        'copyright_empty' => [true, 2, 0],
        'copyright_studio' => [true, 2, 1],
        'copyright_watermark' => [true, 2, 2],
    ];

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'Action to perform (list|show|delete|stats)')
            ->addArgument('id', InputArgument::OPTIONAL, 'Video ID')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status (active|disabled|error|processing|deleting|deleted)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results to show', Constants::DEFAULT_CONTENT_LIMIT)
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search in titles, directories, and descriptions')
            ->addOption('resolution', null, InputOption::VALUE_REQUIRED, 'Filter by KVS resolution type ID')
            ->addOption('load-type', null, InputOption::VALUE_REQUIRED, 'Filter by KVS load type ID (0|1|2|3|5)')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Filter by category ID or title')
            ->addOption('category-group', null, InputOption::VALUE_REQUIRED, 'Filter by category group ID or title')
            ->addOption('tag', null, InputOption::VALUE_REQUIRED, 'Filter by tag ID or name')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Filter by model ID or title')
            ->addOption('content-source', null, InputOption::VALUE_REQUIRED, 'Filter by content source ID or title')
            ->addOption('content-source-group', null, InputOption::VALUE_REQUIRED, 'Filter by content source group ID or title')
            ->addOption('dvd', null, InputOption::VALUE_REQUIRED, 'Filter by DVD ID or title')
            ->addOption('dvd-group', null, InputOption::VALUE_REQUIRED, 'Filter by DVD group ID or title')
            ->addOption('model-group', null, InputOption::VALUE_REQUIRED, 'Filter by model group ID or title')
            ->addOption('playlist', null, InputOption::VALUE_REQUIRED, 'Filter by playlist ID or title')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Filter by user ID or username')
            ->addOption('admin-user', null, InputOption::VALUE_REQUIRED, 'Filter by admin user ID or login')
            ->addOption('ip', null, InputOption::VALUE_REQUIRED, 'Filter by IP address')
            ->addOption('server-group', null, InputOption::VALUE_REQUIRED, 'Filter by storage server group ID or title')
            ->addOption('format-video-group', null, InputOption::VALUE_REQUIRED, 'Filter by video format group ID or title')
            ->addOption('feed', null, InputOption::VALUE_REQUIRED, 'Filter by import feed ID')
            ->addOption('has-errors', null, InputOption::VALUE_REQUIRED, 'Filter by KVS processing error bit (1|10|100|1000|10000)')
            ->addOption('posted', null, InputOption::VALUE_REQUIRED, 'Filter by public posting state (yes|no)')
            ->addOption('neuroscore', null, InputOption::VALUE_REQUIRED, 'Filter by KVS Neuroscore operation state')
            ->addOption('digiregs-copyright', null, InputOption::VALUE_REQUIRED, 'Filter by KVS DigiRegs copyright state')
            ->addOption('show-id', null, InputOption::VALUE_REQUIRED, 'Filter by KVS admin show ID')
            ->addOption('public', null, InputOption::VALUE_NONE, 'Show only public videos')
            ->addOption('private', null, InputOption::VALUE_NONE, 'Show only private videos')
            ->addOption('premium', null, InputOption::VALUE_NONE, 'Show only premium videos')
            ->addOption('access-level', null, InputOption::VALUE_REQUIRED, 'Filter by access level ID (0-3)')
            ->addOption('review-needed', null, InputOption::VALUE_NONE, 'Show only videos that need review')
            ->addOption('not-review-needed', null, InputOption::VALUE_NONE, 'Show only videos that do not need review')
            ->addOption('locked', null, InputOption::VALUE_NONE, 'Show only locked videos')
            ->addOption('unlocked', null, InputOption::VALUE_NONE, 'Show only unlocked videos')
            ->addOption('field-filter', null, InputOption::VALUE_REQUIRED, 'KVS admin field filter (e.g. filled/tags)')
            ->addOption('flag', null, InputOption::VALUE_REQUIRED, 'Filter by admin or user flag ID')
            ->addOption('flag-votes', null, InputOption::VALUE_REQUIRED, 'Minimum user flag votes for --flag', '1')
            ->addOption('post-date-from', null, InputOption::VALUE_REQUIRED, 'Filter by minimum post date (YYYY-MM-DD)')
            ->addOption('post-date-to', null, InputOption::VALUE_REQUIRED, 'Filter by maximum post date (YYYY-MM-DD)')
            ->addOption('duration-from', null, InputOption::VALUE_REQUIRED, 'Filter by minimum duration in seconds')
            ->addOption('duration-to', null, InputOption::VALUE_REQUIRED, 'Filter by maximum duration in seconds')
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
  is_private      Access type (Public/Private/Premium)
  type            Access type alias (Public/Private/Premium)
  access          Access level (From access type/All users/Only members/Only premium members)
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
        $action = $this->getStringArgument($input, 'action') ?? 'list';

        if ($this->getBoolOption($input, 'stats')) {
            if ($this->rejectUnsupportedOptions($input, 'stats', self::SHOW_UNSUPPORTED_OPTIONS)) {
                return self::FAILURE;
            }

            return $this->showStats($input);
        }

        return match ($action) {
            'list' => $this->listVideos($input),
            'show' => $this->showVideo($this->getStringArgument($input, 'id'), $input),
            'delete' => $this->deleteVideo($this->getStringArgument($input, 'id'), $input),
            'stats' => $this->showStats($input),
            default => $this->failUnknownAction('video', $action, ['list', 'show', 'delete', 'stats']),
        };
    }

    private function listVideos(InputInterface $input): int
    {
        if ($this->rejectUnsupportedArgument($input, 'list', 'id', 'a video ID argument', 'show', 'a specific video')) {
            return self::FAILURE;
        }

        if ($this->hasVideoListOptionConflicts($input)) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $fromSql = "FROM {$this->table('videos')} v
                 LEFT JOIN {$this->table('users')} u ON v.user_id = u.user_id";
        $whereSql = 'WHERE 1=1';

        $params = [];

        if (!$this->applyVideoListFilters($db, $input, $whereSql, $params)) {
            return self::FAILURE;
        }

        if ($this->getStringOptionOrDefault($input, 'format', 'table') === 'count') {
            if ($this->rejectFieldSelectionForCountFormat($input)) {
                return self::FAILURE;
            }
            if ($this->getPositiveIntOptionOrDefault($input, 'limit', Constants::DEFAULT_CONTENT_LIMIT) === null) {
                return self::FAILURE;
            }
            return $this->countVideos($db, "$fromSql $whereSql", $params);
        }

        $selectFields = [
            'v.*',
            'u.username',
            'v.video_viewed as views',
        ];
        if ($this->isFieldRequested($input, 'user_status_id')) {
            $selectFields[] = 'u.status_id as user_status_id';
        }
        [$relationSelects, $relationJoinSql] = $this->buildVideoRelationSql($input);
        $selectFields = array_merge($selectFields, $relationSelects);
        $query = 'SELECT ' . implode(",\n                 ", $selectFields) . "
                 $fromSql
                 $relationJoinSql
                 $whereSql
                 ORDER BY v.video_id DESC LIMIT :limit";

        try {
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $limit = $this->getPositiveIntOptionOrDefault($input, 'limit', Constants::DEFAULT_CONTENT_LIMIT);
            if ($limit === null) {
                return self::FAILURE;
            }
            $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $knownFields = array_merge(
                $this->getStatementColumnNames($stmt),
                [
                    'id',
                    'status',
                    'is_error',
                    'is_private',
                    'type',
                    'access_level_id',
                    'access',
                    'resolution',
                    'duration',
                    'filesize',
                    'file_size',
                    'ip',
                    'r_ctr',
                    'thumb',
                    'website_link',
                    'rating',
                ]
            );

            /** @var list<array<string, mixed>> $videos */
            $videos = $stmt->fetchAll();

            $thumbFormat = $this->isFieldRequested($input, 'thumb') ? $this->getVideoThumbFormat($db) : null;
            $thumbBaseUrl = $thumbFormat !== null ? $this->getVideoThumbBaseUrl() : null;

            // Transform data for display (field aliases and calculated values)
            $videos = array_map(function (array $video) use ($thumbFormat, $thumbBaseUrl): array {
                // Add field aliases
                $video['id'] = $video['video_id'];
                $statusId = isset($video['status_id']) && is_numeric($video['status_id']) ? (int) $video['status_id'] : 0;
                $video['status'] = StatusFormatter::video($statusId, false);
                $video['is_error'] = $statusId === 2 ? 1 : 0;
                $privacyId = isset($video['is_private']) && is_numeric($video['is_private']) ? (int) $video['is_private'] : 0;
                $privacy = StatusFormatter::contentPrivacy($privacyId, false);
                $accessLevelId = isset($video['access_level_id']) && is_numeric($video['access_level_id'])
                    ? (int) $video['access_level_id']
                    : 0;
                $video['is_private'] = $privacy;
                $video['type'] = $privacy;
                $video['access_level_id'] = $accessLevelId;
                $video['access'] = StatusFormatter::contentAccessLevel($accessLevelId, false);
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
                if (array_key_exists('ip', $video)) {
                    $video['ip'] = $this->formatKvsIp($video['ip']);
                }

                $video['thumb'] = $this->formatVideoThumb($video, $thumbFormat, $thumbBaseUrl);
                $video['website_link'] = $this->buildKvsWebsiteLink(
                    $video,
                    'video_id',
                    'WEBSITE_LINK_PATTERN'
                );
                $video['rating'] = format_kvs_rating($video['rating'] ?? 0, $video['rating_amount'] ?? 0);

                return $video;
            }, $videos);

            // Format and display output using centralized Formatter
            $formatter = new Formatter(
                $input->getOptions(),
                ['video_id', 'title', 'status', 'views', 'username', 'post_date'],
                $knownFields
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
     * @return array{0: list<string>, 1: string}
     */
    private function buildVideoRelationSql(InputInterface $input): array
    {
        $selects = [];
        $joins = [];

        if (
            $this->isFieldRequested($input, 'content_source')
            || $this->isFieldRequested($input, 'content_source_status_id')
        ) {
            $selects[] = 'cs.title as content_source';
            $selects[] = 'cs.status_id as content_source_status_id';
            $joins[] = "LEFT JOIN {$this->table('content_sources')} cs ON cs.content_source_id = v.content_source_id";
        }

        if (
            $this->isFieldRequested($input, 'admin_user')
            || $this->isFieldRequested($input, 'admin_user_is_superadmin')
        ) {
            $selects[] = 'au.login as admin_user';
            $selects[] = 'au.is_superadmin as admin_user_is_superadmin';
            $joins[] = "LEFT JOIN {$this->table('admin_users')} au ON au.user_id = v.admin_user_id";
        }

        if ($this->isFieldRequested($input, 'dvd') || $this->isFieldRequested($input, 'dvd_status_id')) {
            $selects[] = 'd.title as dvd';
            $selects[] = 'd.status_id as dvd_status_id';
            $joins[] = "LEFT JOIN {$this->table('dvds')} d ON d.dvd_id = v.dvd_id";
        }

        if ($this->isFieldRequested($input, 'admin_flag')) {
            $selects[] = 'f.title as admin_flag';
            $joins[] = "LEFT JOIN {$this->table('flags')} f ON f.flag_id = v.admin_flag_id";
        }

        if (
            $this->isFieldRequested($input, 'server_group')
            || $this->isFieldRequested($input, 'server_group_status_id')
        ) {
            $selects[] = 'sg.title as server_group';
            $selects[] = 'sg.status_id as server_group_status_id';
            $joins[] = "LEFT JOIN {$this->table('admin_servers_groups')} sg ON sg.group_id = v.server_group_id";
        }

        if ($this->isFieldRequested($input, 'format_video_group')) {
            $selects[] = 'fvg.title as format_video_group';
            $joins[] = "LEFT JOIN {$this->table('formats_videos_groups')} fvg "
                . 'ON fvg.format_video_group_id = v.format_video_group_id';
        }
        if ($this->isFieldRequested($input, 'tags')) {
            $selects[] = "(
                SELECT GROUP_CONCAT(t.tag ORDER BY tv.id ASC)
                FROM {$this->table('tags')} t
                INNER JOIN {$this->table('tags_videos')} tv ON tv.tag_id = t.tag_id
                WHERE tv.video_id = v.video_id
            ) as tags";
        }
        if ($this->isFieldRequested($input, 'categories')) {
            $selects[] = "(
                SELECT GROUP_CONCAT(c.title ORDER BY cv.id ASC)
                FROM {$this->table('categories')} c
                INNER JOIN {$this->table('categories_videos')} cv ON cv.category_id = c.category_id
                WHERE cv.video_id = v.video_id
            ) as categories";
        }
        if ($this->isFieldRequested($input, 'models')) {
            $selects[] = "(
                SELECT GROUP_CONCAT(m.title ORDER BY mv.id ASC)
                FROM {$this->table('models')} m
                INNER JOIN {$this->table('models_videos')} mv ON mv.model_id = m.model_id
                WHERE mv.video_id = v.video_id
            ) as models";
        }

        return [$selects, implode("\n                 ", $joins)];
    }

    /**
     * @param array<string, int|string> $params
     */
    private function applyVideoListFilters(
        \PDO $db,
        InputInterface $input,
        string &$whereSql,
        array &$params
    ): bool {
        $status = $this->getStringOption($input, 'status');
        if ($status !== null) {
            $statusId = $this->parseStatusFilterOrFail($input, [
                'active' => StatusFormatter::VIDEO_ACTIVE,
                'disabled' => StatusFormatter::VIDEO_DISABLED,
                'error' => StatusFormatter::VIDEO_ERROR,
                'processing' => StatusFormatter::VIDEO_PROCESSING,
                'in_process' => StatusFormatter::VIDEO_PROCESSING,
                'in-process' => StatusFormatter::VIDEO_PROCESSING,
                'deleting' => StatusFormatter::VIDEO_DELETING,
                'deleted' => StatusFormatter::VIDEO_DELETED,
            ], [0, 1, 2, 3, 4, 5]);
            if ($statusId === false) {
                return false;
            }
            if ($statusId !== null) {
                $whereSql .= " AND v.status_id = :status";
                $params['status'] = $statusId;
            }
        }

        $search = $this->getStringOption($input, 'search');
        if ($search !== null) {
            $searchCondition = $this->buildAdminSearchCondition(
                'v.video_id',
                [
                    'v.title',
                    'v.dir',
                    'v.description',
                    'v.file_url',
                    'v.embed',
                    'v.gallery_url',
                    'v.pseudo_url',
                    'v.delete_reason',
                    'v.custom1',
                    'v.custom2',
                    'v.custom3',
                ],
                $search,
                $params
            );
            $websiteLinkCondition = $this->buildKvsWebsiteLinkSearchCondition(
                'v.video_id',
                'v.dir',
                'WEBSITE_LINK_PATTERN',
                $search,
                $params,
                'search_website_link'
            );
            if ($websiteLinkCondition !== null) {
                $searchCondition = "({$searchCondition} OR {$websiteLinkCondition})";
            }
            $whereSql .= ' AND ' . $searchCondition;
        }

        if (!$this->applyVideoAdminScalarFilters($input, $whereSql, $params)) {
            return false;
        }

        if (!$this->applyVideoAdminColumnFilters($input, $whereSql, $params)) {
            return false;
        }

        if (!$this->applyVideoRelationFilters($db, $input, $whereSql, $params)) {
            return false;
        }

        $user = $this->resolveUserIdOption($db, $input);
        if ($user === false) {
            return false;
        }
        if ($user !== null) {
            $whereSql .= " AND v.user_id = :user";
            $params['user'] = $user;
        }

        if (!$this->applyVideoAdminReferenceFilters($db, $input, $whereSql, $params)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, int|string> $params
     */
    private function applyVideoAdminColumnFilters(InputInterface $input, string &$whereSql, array &$params): bool
    {
        $resolution = $this->getOptionalNonNegativeIntOption($input, 'resolution');
        if ($resolution === false) {
            return false;
        }
        if ($resolution !== null) {
            if ($resolution > 100) {
                $whereSql .= ' AND v.resolution_type >= :resolution';
                $params['resolution'] = $resolution - 100;
            } else {
                $whereSql .= ' AND v.resolution_type = :resolution';
                $params['resolution'] = $resolution;
            }
        }

        $loadType = $this->getOptionalNonNegativeIntOption($input, 'load-type');
        if ($loadType === false) {
            return false;
        }
        if ($loadType !== null) {
            if (!in_array($loadType, [0, 1, 2, 3, 5], true)) {
                $this->io()->error('Invalid value for --load-type (use: 0, 1, 2, 3 or 5)');
                return false;
            }
            $whereSql .= ' AND v.load_type_id = :load_type';
            $params['load_type'] = $loadType;
        }

        $feed = $this->getOptionalPositiveIntOption($input, 'feed');
        if ($feed === false) {
            return false;
        }
        if ($feed !== null) {
            $whereSql .= ' AND v.feed_id = :feed';
            $params['feed'] = $feed;
        }

        $hasErrors = $this->getOptionalPositiveIntOption($input, 'has-errors');
        if ($hasErrors === false) {
            return false;
        }
        if ($hasErrors !== null) {
            $errorMasks = [
                1 => 1,
                10 => 2,
                100 => 4,
                1000 => 8,
                10000 => 16,
            ];
            if (!isset($errorMasks[$hasErrors])) {
                $this->io()->error('Invalid value for --has-errors (use: 1, 10, 100, 1000 or 10000)');
                return false;
            }
            $whereSql .= ' AND (v.has_errors & :has_errors_mask) > 0';
            $params['has_errors_mask'] = $errorMasks[$hasErrors];
        }

        $posted = $this->getStringOption($input, 'posted');
        if ($posted !== null) {
            if (!in_array($posted, ['yes', 'no'], true)) {
                $this->io()->error('Invalid value for --posted (use: yes or no)');
                return false;
            }
            $postedCondition = 'v.status_id = 1 AND v.relative_post_date <= 0 AND v.post_date <= CURRENT_TIMESTAMP';
            if ($posted === 'yes') {
                $whereSql .= " AND {$postedCondition}";
            } else {
                $whereSql .= " AND NOT ({$postedCondition})";
            }
        }

        return true;
    }

    /**
     * @param array<string, int|string> $params
     */
    private function applyVideoAdminReferenceFilters(
        \PDO $db,
        InputInterface $input,
        string &$whereSql,
        array &$params
    ): bool {
        $adminUser = $this->resolveAdminUserIdOption($db, $input);
        if ($adminUser === false) {
            return false;
        }
        if ($adminUser !== null) {
            $whereSql .= ' AND v.admin_user_id = :admin_user';
            $params['admin_user'] = $adminUser;
        }

        $ip = $this->getStringOption($input, 'ip');
        if ($ip !== null) {
            $ipNumber = $this->parseKvsIpv4Option($ip);
            if ($ipNumber === false) {
                return false;
            }
            $whereSql .= ' AND v.ip = :ip';
            $params['ip'] = $ipNumber;
        }

        $serverGroup = $this->resolveServerGroupIdOption($db, $input);
        if ($serverGroup === false) {
            return false;
        }
        if ($serverGroup !== null) {
            if ($serverGroup === 0) {
                $this->io()->error('Invalid value for --server-group (use: integer >= 1 or title)');
                return false;
            }
            $whereSql .= ' AND v.server_group_id = :server_group';
            $params['server_group'] = $serverGroup;
        }

        $formatVideoGroup = $this->resolveReferenceIdOrTitleOption(
            $db,
            $input,
            'format-video-group',
            'formats_videos_groups',
            'format_video_group_id',
            'title'
        );
        if ($formatVideoGroup === false) {
            return false;
        }
        if ($formatVideoGroup !== null) {
            if ($formatVideoGroup === 0) {
                $this->io()->error('Invalid value for --format-video-group (use: integer >= 1 or title)');
                return false;
            }
            $whereSql .= ' AND v.format_video_group_id = :format_video_group';
            $params['format_video_group'] = $formatVideoGroup;
        }

        return true;
    }

    private function hasVideoListOptionConflicts(InputInterface $input): bool
    {
        return $this->hasConflictingBoolOptions($input, ['public', 'private', 'premium'])
            || $this->hasConflictingBoolOptions($input, ['review-needed', 'not-review-needed'])
            || $this->hasConflictingBoolOptions($input, ['locked', 'unlocked']);
    }

    /**
     * @param array<string, int|string> $params
     */
    private function applyVideoAdminScalarFilters(InputInterface $input, string &$whereSql, array &$params): bool
    {
        if ($this->getBoolOption($input, 'public')) {
            $whereSql .= ' AND v.is_private = 0';
        } elseif ($this->getBoolOption($input, 'private')) {
            $whereSql .= ' AND v.is_private = 1';
        } elseif ($this->getBoolOption($input, 'premium')) {
            $whereSql .= ' AND v.is_private = 2';
        }

        $accessLevel = $this->getOptionalNonNegativeIntOption($input, 'access-level');
        if ($accessLevel === false) {
            return false;
        }
        if ($accessLevel !== null) {
            if ($accessLevel > 3) {
                $this->io()->error('Invalid value for --access-level (use: 0, 1, 2 or 3)');
                return false;
            }
            $whereSql .= ' AND v.access_level_id = :access_level';
            $params['access_level'] = $accessLevel;
        }

        if ($this->getBoolOption($input, 'review-needed')) {
            $whereSql .= ' AND v.is_review_needed = 1';
        } elseif ($this->getBoolOption($input, 'not-review-needed')) {
            $whereSql .= ' AND v.is_review_needed = 0';
        }

        if ($this->getBoolOption($input, 'locked')) {
            $whereSql .= ' AND v.is_locked = 1';
        } elseif ($this->getBoolOption($input, 'unlocked')) {
            $whereSql .= ' AND v.is_locked = 0';
        }

        if (!$this->applyVideoDateDurationFilters($input, $whereSql, $params)) {
            return false;
        }

        if (!$this->applyVideoFlagFilter($input, $whereSql, $params)) {
            return false;
        }

        if (!$this->applyVideoAdvancedOperationFilters($input, $whereSql, $params)) {
            return false;
        }

        if (!$this->applyVideoShowIdFilter($input, $whereSql, $params)) {
            return false;
        }

        $fieldFilter = $this->getStringOption($input, 'field-filter');
        if ($fieldFilter !== null) {
            $condition = $this->getVideoFieldFilterCondition($fieldFilter);
            if ($condition === null) {
                $this->io()->error('Invalid video field filter. Use: ' . implode(', ', $this->getVideoFieldFilterValues()));
                return false;
            }
            $whereSql .= " AND {$condition}";
        }

        return true;
    }

    /**
     * @param array<string, int|string> $params
     */
    private function applyVideoShowIdFilter(InputInterface $input, string &$whereSql, array &$params): bool
    {
        $showId = $this->getStringOption($input, 'show-id');
        if ($showId === null) {
            return true;
        }

        $showId = trim($showId);
        if ($showId === '') {
            $this->io()->error('Invalid value for --show-id');
            return false;
        }

        $auditLog = $this->table('admin_audit_log');
        switch ($showId) {
            case '15':
                $whereSql .= " AND EXISTS (SELECT 1 FROM {$auditLog} aal_show " .
                    'WHERE aal_show.object_id = v.video_id AND aal_show.object_type_id = 1 AND aal_show.action_id = 100)';
                return true;
            case '16':
                $whereSql .= " AND EXISTS (SELECT 1 FROM {$auditLog} aal_show " .
                    'WHERE aal_show.object_id = v.video_id AND aal_show.object_type_id = 1 AND aal_show.action_id = 140)';
                return true;
            case '17':
                $whereSql .= " AND EXISTS (SELECT 1 FROM {$auditLog} aal_show " .
                    'WHERE aal_show.object_id = v.video_id AND aal_show.object_type_id = 1 AND aal_show.action_id = 140)' .
                    ' AND u.status_id = 6';
                return true;
            case '18':
                $whereSql .= ' AND v.feed_id > 0';
                return true;
            case '19':
                $whereSql .= " AND v.gallery_url != ''";
                return true;
            case '20':
                $whereSql .= " AND EXISTS (SELECT 1 FROM {$auditLog} aal_show " .
                    'WHERE aal_show.object_id = v.video_id AND aal_show.object_type_id = 1 AND aal_show.action_id = 110)';
                return true;
            case '25':
                $whereSql .= " AND EXISTS (SELECT 1 FROM {$auditLog} aal_show " .
                    'WHERE aal_show.object_id = v.video_id AND aal_show.object_type_id = 1 AND aal_show.action_id = 130)';
                return true;
            case '21':
                $whereSql .= ' AND v.screen_main = 1';
                return true;
            case '22':
                $whereSql .= ' AND v.screen_main != 1';
                return true;
            case '23':
                $whereSql .= ' AND v.rs_completed = 1';
                return true;
            case '24':
                $whereSql .= ' AND v.rs_completed != 1';
                return true;
            case 'is_vertical':
                $whereSql .= ' AND v.is_vertical = 1';
                return true;
            case 'is_horizontal':
                $whereSql .= ' AND v.is_vertical = 0';
                return true;
        }

        if (str_starts_with($showId, 'wf/') || str_starts_with($showId, 'wof/')) {
            $withoutFormat = str_starts_with($showId, 'wof/');
            $postfix = substr($showId, $withoutFormat ? 4 : 3);
            if ($postfix === '') {
                $this->io()->error('Invalid value for --show-id format filter');
                return false;
            }
            $whereSql .= $withoutFormat
                ? ' AND v.file_formats NOT LIKE :show_id_file_format AND v.load_type_id = 1'
                : ' AND v.file_formats LIKE :show_id_file_format AND v.load_type_id = 1';
            $params['show_id_file_format'] = "%||{$postfix}|%";
            return true;
        }

        if (str_starts_with($showId, 'wq/') || str_starts_with($showId, 'woq/')) {
            $withoutQuality = str_starts_with($showId, 'woq/');
            $quality = substr($showId, $withoutQuality ? 4 : 3);
            if (preg_match('/^\d+$/', $quality) !== 1) {
                $this->io()->error('Invalid value for --show-id quality filter');
                return false;
            }
            $whereSql .= $withoutQuality
                ? " AND v.file_dimensions != '' AND INSTR(v.file_dimensions, 'x') > 0 " .
                    "AND (SUBSTR(v.file_dimensions, INSTR(v.file_dimensions, 'x') + 1) + 0) < :show_id_quality"
                : " AND v.file_dimensions != '' AND INSTR(v.file_dimensions, 'x') > 0 " .
                    "AND (SUBSTR(v.file_dimensions, INSTR(v.file_dimensions, 'x') + 1) + 0) >= :show_id_quality";
            $params['show_id_quality'] = (int) $quality;
            return true;
        }

        $this->io()->error('Invalid value for --show-id');
        return false;
    }

    /**
     * @param array<string, int|string> $params
     */
    private function applyVideoAdvancedOperationFilters(InputInterface $input, string &$whereSql, array &$params): bool
    {
        $neuroscore = $this->getStringOption($input, 'neuroscore');
        if ($neuroscore !== null) {
            if (!isset(self::VIDEO_NEUROSCORE_FILTERS[$neuroscore])) {
                $this->io()->error(
                    'Invalid value for --neuroscore. Use: ' . implode(', ', array_keys(self::VIDEO_NEUROSCORE_FILTERS))
                );
                return false;
            }
            [$exists, $operationType, $operationStatus] = self::VIDEO_NEUROSCORE_FILTERS[$neuroscore];
            $this->appendVideoAdvancedOperationFilter(
                $whereSql,
                $params,
                'neuroscore',
                $exists,
                $operationType,
                $operationStatus
            );
        }

        $digiregsCopyright = $this->getStringOption($input, 'digiregs-copyright');
        if ($digiregsCopyright !== null) {
            if (!isset(self::VIDEO_DIGIREGS_COPYRIGHT_FILTERS[$digiregsCopyright])) {
                $this->io()->error(
                    'Invalid value for --digiregs-copyright. Use: ' .
                    implode(', ', array_keys(self::VIDEO_DIGIREGS_COPYRIGHT_FILTERS))
                );
                return false;
            }
            [$exists, $operationType, $operationStatus] = self::VIDEO_DIGIREGS_COPYRIGHT_FILTERS[$digiregsCopyright];
            $this->appendVideoAdvancedOperationFilter(
                $whereSql,
                $params,
                'digiregs_copyright',
                $exists,
                $operationType,
                $operationStatus
            );
        }

        return true;
    }

    /**
     * @param array<string, int|string> $params
     */
    private function appendVideoAdvancedOperationFilter(
        string &$whereSql,
        array &$params,
        string $parameterPrefix,
        bool $exists,
        int $operationType,
        ?int $operationStatus
    ): void {
        $alias = "vao_{$parameterPrefix}";
        $operationTypeParameter = "{$parameterPrefix}_operation_type";
        $condition = "SELECT 1 FROM {$this->table('videos_advanced_operations')} {$alias} " .
            "WHERE {$alias}.video_id = v.video_id AND {$alias}.operation_type_id = :{$operationTypeParameter}";
        $params[$operationTypeParameter] = $operationType;

        if ($operationStatus !== null) {
            $operationStatusParameter = "{$parameterPrefix}_operation_status";
            $condition .= " AND {$alias}.operation_status_id = :{$operationStatusParameter}";
            $params[$operationStatusParameter] = $operationStatus;
        }

        $whereSql .= $exists ? " AND EXISTS ({$condition})" : " AND NOT EXISTS ({$condition})";
    }

    /**
     * @param array<string, int|string> $params
     */
    private function applyVideoDateDurationFilters(InputInterface $input, string &$whereSql, array &$params): bool
    {
        $postDateFrom = $this->getDateOption($input, 'post-date-from');
        if ($postDateFrom === false) {
            return false;
        }
        if ($postDateFrom !== null) {
            $whereSql .= ' AND v.post_date >= :post_date_from';
            $params['post_date_from'] = $postDateFrom;
        }

        $postDateTo = $this->getDateOption($input, 'post-date-to');
        if ($postDateTo === false) {
            return false;
        }
        if ($postDateTo !== null) {
            $whereSql .= ' AND v.post_date <= :post_date_to';
            $params['post_date_to'] = $postDateTo . ' 23:59:59';
        }

        $durationFrom = $this->getOptionalPositiveIntOption($input, 'duration-from');
        if ($durationFrom === false) {
            return false;
        }
        if ($durationFrom !== null) {
            $whereSql .= ' AND v.duration >= :duration_from';
            $params['duration_from'] = $durationFrom;
        }

        $durationTo = $this->getOptionalPositiveIntOption($input, 'duration-to');
        if ($durationTo === false) {
            return false;
        }
        if ($durationTo !== null) {
            $whereSql .= ' AND v.duration <= :duration_to';
            $params['duration_to'] = $durationTo;
        }

        return true;
    }

    private function getDateOption(InputInterface $input, string $name): string|false|null
    {
        $value = $this->getStringOption($input, $name);
        if ($value === null) {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            $this->io()->error(sprintf('Invalid value for --%s (use: YYYY-MM-DD)', $name));
            return false;
        }

        return date('Y-m-d', $timestamp);
    }

    /**
     * @param array<string, int|string> $params
     */
    private function applyVideoFlagFilter(InputInterface $input, string &$whereSql, array &$params): bool
    {
        $flag = $this->getOptionalPositiveIntOption($input, 'flag');
        if ($flag === false) {
            return false;
        }

        $votesOption = $this->getStringOption($input, 'flag-votes');
        if ($flag === null) {
            if ($votesOption !== null && $this->isOptionExplicitlySet($input, 'flag-votes')) {
                $this->io()->error('Option --flag-votes requires --flag');
                return false;
            }
            return true;
        }

        $flagVotes = $this->getPositiveIntOptionOrDefault($input, 'flag-votes', 1);
        if ($flagVotes === null) {
            return false;
        }

        $flagsTable = $this->table('flags_videos');
        $whereSql .= " AND (
            v.admin_flag_id = :admin_flag
            OR (
                SELECT COALESCE(SUM(fv_filter.votes), 0)
                FROM {$flagsTable} fv_filter
                WHERE fv_filter.video_id = v.video_id AND fv_filter.flag_id = :user_flag
            ) >= :minimum_flag_votes
        )";
        $params['admin_flag'] = $flag;
        $params['user_flag'] = $flag;
        $params['minimum_flag_votes'] = $flagVotes;

        return true;
    }

    /**
     * @param array<string, int|string> $params
     */
    private function applyVideoRelationFilters(
        \PDO $db,
        InputInterface $input,
        string &$whereSql,
        array &$params
    ): bool {
        $category = $this->resolveCategoryIdOption($db, $input);
        if ($category === false) {
            return false;
        }
        if ($category !== null) {
            $whereSql .= " AND EXISTS (SELECT 1 FROM {$this->table('categories_videos')} cv "
                . "WHERE cv.video_id = v.video_id AND cv.category_id = :category)";
            $params['category'] = $category;
        }

        $categoryGroup = $this->resolveVideoReferenceIdOption(
            $db,
            $input,
            'category-group',
            'categories_groups',
            'category_group_id',
            'title'
        );
        if ($categoryGroup === false) {
            return false;
        }
        if ($categoryGroup !== null) {
            $whereSql .= " AND EXISTS (SELECT 1 FROM {$this->table('categories_videos')} cvg "
                . "WHERE cvg.video_id = v.video_id AND cvg.category_id IN ("
                . "SELECT cg.category_id FROM {$this->table('categories')} cg "
                . "WHERE cg.category_group_id = :category_group))";
            $params['category_group'] = $categoryGroup;
        }

        $tag = $this->resolveTagIdOption($db, $input);
        if ($tag === false) {
            return false;
        }
        if ($tag !== null) {
            $whereSql .= " AND EXISTS (SELECT 1 FROM {$this->table('tags_videos')} tv "
                . "WHERE tv.video_id = v.video_id AND tv.tag_id = :tag)";
            $params['tag'] = $tag;
        }

        $model = $this->resolveModelIdOption($db, $input);
        if ($model === false) {
            return false;
        }
        if ($model !== null) {
            $whereSql .= " AND EXISTS (SELECT 1 FROM {$this->table('models_videos')} mv "
                . "WHERE mv.video_id = v.video_id AND mv.model_id = :model)";
            $params['model'] = $model;
        }

        $modelGroup = $this->resolveVideoReferenceIdOption(
            $db,
            $input,
            'model-group',
            'models_groups',
            'model_group_id',
            'title'
        );
        if ($modelGroup === false) {
            return false;
        }
        if ($modelGroup !== null) {
            $whereSql .= " AND EXISTS (SELECT 1 FROM {$this->table('models_videos')} mvg "
                . 'WHERE mvg.video_id = v.video_id AND mvg.model_id IN ('
                . "SELECT mg.model_id FROM {$this->table('models')} mg "
                . 'WHERE mg.model_group_id = :model_group))';
            $params['model_group'] = $modelGroup;
        }

        $contentSource = $this->resolveContentSourceIdOption($db, $input);
        if ($contentSource === false) {
            return false;
        }
        if ($contentSource !== null) {
            $whereSql .= " AND v.content_source_id = :content_source";
            $params['content_source'] = $contentSource;
        }

        $contentSourceGroup = $this->resolveVideoReferenceIdOption(
            $db,
            $input,
            'content-source-group',
            'content_sources_groups',
            'content_source_group_id',
            'title'
        );
        if ($contentSourceGroup === false) {
            return false;
        }
        if ($contentSourceGroup !== null) {
            $whereSql .= ' AND v.content_source_id IN ('
                . "SELECT csg.content_source_id FROM {$this->table('content_sources')} csg "
                . 'WHERE csg.content_source_group_id = :content_source_group)';
            $params['content_source_group'] = $contentSourceGroup;
        }

        $dvd = $this->resolveDvdIdOption($db, $input);
        if ($dvd === false) {
            return false;
        }
        if ($dvd !== null) {
            $whereSql .= " AND v.dvd_id = :dvd";
            $params['dvd'] = $dvd;
        }

        $dvdGroup = $this->resolveVideoReferenceIdOption(
            $db,
            $input,
            'dvd-group',
            'dvds_groups',
            'dvd_group_id',
            'title'
        );
        if ($dvdGroup === false) {
            return false;
        }
        if ($dvdGroup !== null) {
            $whereSql .= ' AND v.dvd_id IN ('
                . "SELECT dg.dvd_id FROM {$this->table('dvds')} dg "
                . 'WHERE dg.dvd_group_id = :dvd_group)';
            $params['dvd_group'] = $dvdGroup;
        }

        $playlist = $this->resolvePlaylistIdOption($db, $input);
        if ($playlist === false) {
            return false;
        }
        if ($playlist !== null) {
            $whereSql .= " AND EXISTS (SELECT 1 FROM {$this->table('fav_videos')} fv "
                . "WHERE fv.video_id = v.video_id AND fv.playlist_id = :playlist)";
            $params['playlist'] = $playlist;
        }

        return true;
    }

    private function resolveVideoReferenceIdOption(
        \PDO $db,
        InputInterface $input,
        string $name,
        string $table,
        string $idColumn,
        string $titleColumn
    ): int|false|null {
        $value = $this->getStringOption($input, $name);
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            $this->io()->error(sprintf('Invalid value for --%s (use: integer >= 0 or title)', $name));
            return false;
        }

        if (preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (preg_match('/^-?\d+(?:\.\d+)?$/', $value) === 1) {
            $this->io()->error(sprintf('Invalid value for --%s (use: integer >= 0 or title)', $name));
            return false;
        }

        return $this->findReferenceIdByText($db, $table, $idColumn, $titleColumn, $value) ?? -1;
    }

    /** @return list<string> */
    private function getVideoFieldFilterValues(): array
    {
        $values = [];
        foreach (['empty', 'filled'] as $prefix) {
            foreach (self::VIDEO_STRING_FIELD_FILTER_COLUMNS as $column) {
                $values[] = "{$prefix}/{$column}";
            }
            foreach (self::VIDEO_ZERO_FIELD_FILTER_COLUMNS as $column) {
                $values[] = "{$prefix}/{$column}";
            }
            foreach (self::VIDEO_SPECIAL_FIELD_FILTERS as $field) {
                $values[] = "{$prefix}/{$field}";
            }
        }

        return $values;
    }

    private function getVideoFieldFilterCondition(string $fieldFilter): ?string
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
            ? $this->getEmptyVideoFieldFilterCondition($field)
            : $this->getFilledVideoFieldFilterCondition($field);
    }

    private function getEmptyVideoFieldFilterCondition(string $field): ?string
    {
        if (in_array($field, self::VIDEO_STRING_FIELD_FILTER_COLUMNS, true)) {
            return "v.{$field} = ''";
        }

        if (in_array($field, self::VIDEO_ZERO_FIELD_FILTER_COLUMNS, true)) {
            return "v.{$field} = 0";
        }

        return match ($field) {
            'content_source' => 'v.content_source_id = 0',
            'dvd' => 'v.dvd_id = 0',
            'admin' => 'v.admin_user_id = 0',
            'admin_flag' => 'v.admin_flag_id = 0',
            'comments' => $this->getVideoCommentsCountCondition(false),
            'favourites' => 'v.favourites_count = 0',
            'purchases' => 'v.purchases_count = 0',
            'rating' => '(v.rating = 0 AND v.rating_amount = 1)',
            'tags' => $this->getVideoRelationExistsCondition('tags_videos', 'tag_id', false),
            'categories' => $this->getVideoRelationExistsCondition('categories_videos', 'category_id', false),
            'models' => $this->getVideoRelationExistsCondition('models_videos', 'model_id', false),
            default => null,
        };
    }

    private function getFilledVideoFieldFilterCondition(string $field): ?string
    {
        if (in_array($field, self::VIDEO_STRING_FIELD_FILTER_COLUMNS, true)) {
            return "v.{$field} != ''";
        }

        if (in_array($field, self::VIDEO_ZERO_FIELD_FILTER_COLUMNS, true)) {
            return "v.{$field} != 0";
        }

        return match ($field) {
            'content_source' => 'v.content_source_id > 0',
            'dvd' => 'v.dvd_id > 0',
            'admin' => 'v.admin_user_id > 0',
            'admin_flag' => 'v.admin_flag_id > 0',
            'comments' => $this->getVideoCommentsCountCondition(true),
            'favourites' => 'v.favourites_count > 0',
            'purchases' => 'v.purchases_count > 0',
            'rating' => '(v.rating > 0 OR v.rating_amount > 1)',
            'tags' => $this->getVideoRelationExistsCondition('tags_videos', 'tag_id', true),
            'categories' => $this->getVideoRelationExistsCondition('categories_videos', 'category_id', true),
            'models' => $this->getVideoRelationExistsCondition('models_videos', 'model_id', true),
            default => null,
        };
    }

    private function getVideoCommentsCountCondition(bool $filled): string
    {
        $operator = $filled ? '>' : '=';
        return "(SELECT COUNT(*) FROM {$this->table('comments')} cf "
            . "WHERE cf.object_id = v.video_id AND cf.object_type_id = 1) {$operator} 0";
    }

    private function getVideoRelationExistsCondition(string $relationTable, string $idColumn, bool $exists): string
    {
        $operator = $exists ? 'EXISTS' : 'NOT EXISTS';
        return "{$operator} (SELECT {$idColumn} FROM {$this->table($relationTable)} rel_filter "
            . 'WHERE rel_filter.video_id = v.video_id)';
    }

    /**
     * @param array<string, mixed> $params
     */
    private function countVideos(\PDO $db, string $fromSql, array $params): int
    {
        try {
            $stmt = $db->prepare("SELECT COUNT(*) $fromSql");
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $stmt->execute();

            $this->io()->writeln((string) (int) $stmt->fetchColumn());
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to count videos: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function showVideo(?string $id, InputInterface $input): int
    {
        $videoId = $this->getRequiredPositiveId($id, 'Video');
        if ($videoId === null) {
            return self::FAILURE;
        }

        if ($this->rejectUnsupportedOptionsForAction($input, 'show', self::SHOW_UNSUPPORTED_OPTIONS)) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("SELECT * FROM {$this->table('videos')} WHERE video_id = :id");
            $stmt->execute(['id' => $videoId]);
            /** @var array{title: string, status_id: int, resolution_type: int, is_private: int, access_level_id?: int, duration: int, file_size: int, file_dimensions: string, post_date: string, rating: int, rating_amount: int, video_viewed: int, favourites_count: int, description: string}|false $video */
            $video = $stmt->fetch();

            if ($video === false) {
                $this->io()->error("Video not found: $videoId");
                return self::FAILURE;
            }

            $postTimestamp = strtotime($video['post_date']);
            $accessLevelId = $video['access_level_id'] ?? 0;
            $info = [
                ['Title', $video['title']],
                ['Status', StatusFormatter::video($video['status_id'])],
                ['Resolution', $this->formatResolutionType($video['resolution_type'])],
                ['Type', StatusFormatter::contentPrivacy($video['is_private'])],
                ['Access', StatusFormatter::contentAccessLevel($accessLevelId)],
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

            $stmt = $db->prepare("
                SELECT c.title FROM {$this->table('categories')} c
                JOIN {$this->table('categories_videos')} cv ON c.category_id = cv.category_id
                WHERE cv.video_id = :id
                ORDER BY cv.id ASC
            ");
            $stmt->execute(['id' => $videoId]);
            $categories = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            $stmt = $db->prepare("
                SELECT t.tag FROM {$this->table('tags')} t
                JOIN {$this->table('tags_videos')} tv ON t.tag_id = tv.tag_id
                WHERE tv.video_id = :id
                ORDER BY tv.id ASC
            ");
            $stmt->execute(['id' => $videoId]);
            $tags = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            $categoryValues = array_map(
                static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
                $categories
            );
            $tagValues = array_map(
                static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
                $tags
            );

            if ($this->shouldUseFormattedRows($input)) {
                return $this->displayDetailRows($input, $info, [
                    'video_id' => (string) $videoId,
                    'description' => $video['description'],
                    'categories' => $categoryValues,
                    'tags' => $tagValues,
                ]);
            }

            $this->io()->section("Video #$videoId");
            $this->renderTable(['Property', 'Value'], $info);

            if ($video['description'] !== '') {
                $this->io()->section('Description');
                $this->io()->text($video['description']);
            }

            if ($categories !== []) {
                $this->io()->section('Categories');
                $this->io()->listing($categoryValues);
            }

            if ($tags !== []) {
                $this->io()->section('Tags');
                $this->io()->text(implode(', ', $tagValues));
            }
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch video: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function getVideoThumbFormat(\PDO $db): ?string
    {
        try {
            $stmt = $db->query("
                SELECT size
                FROM {$this->table('formats_screenshots')}
                WHERE status_id = 1 AND group_id = 1
            ");
        } catch (\PDOException) {
            return null;
        }

        if ($stmt === false) {
            return null;
        }

        $targetWidth = $this->getVideoThumbTargetWidth();
        $bestFormat = null;
        $bestDistance = PHP_INT_MAX;

        while (($size = $stmt->fetchColumn()) !== false) {
            if (!is_string($size) || $size === '' || $size === 'source') {
                continue;
            }

            $width = $this->parseSizeWidth($size);
            if ($width === null) {
                continue;
            }

            $distance = abs($targetWidth - $width);
            if ($bestFormat === null || $distance < $bestDistance) {
                $bestFormat = $size;
                $bestDistance = $distance;
            }
        }

        return $bestFormat;
    }

    private function getVideoThumbTargetWidth(): int
    {
        $config = $this->getKvsRuntimeConfig();
        $configuredSize = $config['maximum_thumb_size'] ?? '150x150';
        if (!is_scalar($configuredSize)) {
            return 150;
        }

        return $this->parseSizeWidth((string) $configuredSize) ?? 150;
    }

    private function parseSizeWidth(string $size): ?int
    {
        $parts = explode('x', $size, 2);
        if ($parts[0] === '' || !ctype_digit($parts[0])) {
            return null;
        }

        $width = (int) $parts[0];
        return $width > 0 ? $width : null;
    }

    private function getVideoThumbBaseUrl(): ?string
    {
        $config = $this->getKvsRuntimeConfig();
        foreach (['content_url_videos_screenshots_admin_panel', 'content_url_videos_screenshots'] as $key) {
            $value = $config[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return rtrim($value, '/');
            }
        }

        $projectUrl = $config['project_url'] ?? null;
        if (is_string($projectUrl) && trim($projectUrl) !== '') {
            return rtrim($projectUrl, '/') . '/contents/videos_screenshots';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $video
     */
    private function formatVideoThumb(array $video, ?string $thumbFormat, ?string $thumbBaseUrl): string
    {
        $screenMain = $video['screen_main'] ?? null;
        $videoId = $video['video_id'] ?? null;
        $statusId = $video['status_id'] ?? null;

        if (
            $thumbFormat === null
            || $thumbBaseUrl === null
            || !is_numeric($screenMain)
            || !is_numeric($videoId)
            || !is_numeric($statusId)
            || !in_array((int) $statusId, [StatusFormatter::VIDEO_DISABLED, StatusFormatter::VIDEO_ACTIVE], true)
        ) {
            return '';
        }

        $videoIdInt = (int) $videoId;
        $dirPath = (int) (floor($videoIdInt / 1000) * 1000);

        return sprintf(
            '%s/%d/%d/%s/%d.jpg',
            $thumbBaseUrl,
            $dirPath,
            $videoIdInt,
            $thumbFormat,
            (int) $screenMain
        );
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

    private function showStats(InputInterface $input): int
    {
        if ($this->rejectUnsupportedArgument($input, 'stats', 'id', 'a video ID argument', 'show', 'a specific video')) {
            return self::FAILURE;
        }

        if ($this->rejectUnsupportedOptionsForAction($input, 'stats', self::SHOW_UNSUPPORTED_OPTIONS)) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            /** @var list<list<string>> $stats */
            $stats = [];
            /** @var list<array<string, mixed>> $metricRows */
            $metricRows = [];

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
                    $metricRows[] = $this->metricRow('overall', $label, $intVal, $displayValue);
                } elseif ($label === 'Average Rating') {
                    $ratingValue = is_numeric($rawValue) ? calculate_kvs_rating($rawValue, 1) : null;
                    $displayValue = $ratingValue !== null
                        ? sprintf('%.1f/%d', $ratingValue, Constants::RATING_SCALE)
                        : 'N/A';
                    $metricRows[] = $this->metricRow('overall', $label, $ratingValue, $displayValue);
                } elseif ($label === 'Total Size') {
                    $intVal = is_numeric($rawValue) ? (int) $rawValue : 0;
                    $displayValue = format_bytes($intVal);
                    $metricRows[] = $this->metricRow('overall', $label, $intVal, $displayValue);
                } elseif (is_numeric($rawValue)) {
                    $intVal = (int) $rawValue;
                    $displayValue = number_format($intVal);
                    $metricRows[] = $this->metricRow('overall', $label, $intVal, $displayValue);
                }

                $stats[] = [$label, $displayValue];
            }

            $stmt = $db->query("
                SELECT v.title, v.video_viewed as views
                FROM {$this->table('videos')} v
                WHERE v.status_id = " . StatusFormatter::VIDEO_ACTIVE . "
                ORDER BY v.video_viewed DESC
                LIMIT " . Constants::TOP_QUERY_LIMIT . "
            ");
            $topVideos = $stmt !== false ? $stmt->fetchAll() : [];

            /** @var list<list<int|string>> $topRows */
            $topRows = [];
            if ($topVideos !== []) {
                foreach ($topVideos as $i => $video) {
                    if (!is_array($video)) {
                        continue;
                    }
                    $titleVal = $video['title'] ?? '';
                    $title = is_string($titleVal) ? $titleVal : (is_scalar($titleVal) ? (string) $titleVal : '');
                    $viewsVal = $video['views'] ?? 0;
                    $views = is_numeric($viewsVal) ? (float) $viewsVal : 0.0;
                    $metricRows[] = $this->metricRow(
                        'top_videos',
                        (string) ($i + 1),
                        (int) $views,
                        number_format($views),
                        $title
                    );
                    $topRows[] = [
                        $i + 1,
                        substr($title, 0, Constants::DEFAULT_TRUNCATE_LENGTH),
                        number_format($views),
                    ];
                }
            }

            if ($this->shouldUseFormattedRows($input)) {
                $this->displayMetricRows($input, $metricRows);
                return self::SUCCESS;
            }

            $this->renderTable(['Metric', 'Value'], $stats);

            if ($topVideos !== []) {
                $this->io()->section('Top 10 Most Viewed Videos');
                $this->renderTable(['#', 'Title', 'Views'], $topRows);
            }
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch statistics: ' . $e->getMessage());
            return self::FAILURE;
        }

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
