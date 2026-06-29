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

use function KVS\CLI\Utils\calculate_kvs_rating;
use function KVS\CLI\Utils\format_kvs_rating;

#[AsCommand(
    name: 'content:album',
    description: 'Manage KVS photo albums',
    aliases: ['album', 'albums', 'gallery']
)]
class AlbumCommand extends BaseCommand
{
    /** @var list<string> */
    private const SHOW_UNSUPPORTED_OPTIONS = [
        'status',
        'user',
        'category',
        'tag',
        'model',
        'content-source',
        'content-source-group',
        'category-group',
        'model-group',
        'public',
        'private',
        'premium',
        'access-level',
        'admin-user',
        'ip',
        'server-group',
        'review-needed',
        'not-review-needed',
        'locked',
        'unlocked',
        'has-errors',
        'posted',
        'show-id',
        'field-filter',
        'flag',
        'flag-votes',
        'post-date-from',
        'post-date-to',
        'search',
        'limit',
    ];

    /** @var list<string> */
    private const ALBUM_STRING_FIELD_FILTER_COLUMNS = [
        'title',
        'description',
        'gallery_url',
        'custom1',
        'custom2',
        'custom3',
    ];

    /** @var list<string> */
    private const ALBUM_NUMERIC_FIELD_FILTER_COLUMNS = [
        'af_custom1',
        'af_custom2',
        'af_custom3',
    ];

    /** @var list<string> */
    private const ALBUM_SPECIAL_FIELD_FILTERS = [
        'content_source',
        'admin',
        'admin_flag',
        'tokens_required',
        'album_viewed',
        'album_viewed_unique',
        'comments',
        'favourites',
        'purchases',
        'rating',
        'tags',
        'categories',
        'models',
    ];

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'Action to perform (list|show|delete)')
            ->addArgument('id', InputArgument::OPTIONAL, 'Album ID')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status (active|disabled|error|processing|deleting|deleted)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results', Constants::DEFAULT_CONTENT_LIMIT)
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Filter by user ID or username')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Filter by category ID or title')
            ->addOption('tag', null, InputOption::VALUE_REQUIRED, 'Filter by tag ID or name')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Filter by model ID or title')
            ->addOption('content-source', null, InputOption::VALUE_REQUIRED, 'Filter by content source ID or title')
            ->addOption('content-source-group', null, InputOption::VALUE_REQUIRED, 'Filter by content source group ID or title')
            ->addOption('category-group', null, InputOption::VALUE_REQUIRED, 'Filter by category group ID or title')
            ->addOption('model-group', null, InputOption::VALUE_REQUIRED, 'Filter by model group ID or title')
            ->addOption('public', null, InputOption::VALUE_NONE, 'Show only public albums')
            ->addOption('private', null, InputOption::VALUE_NONE, 'Show only private albums')
            ->addOption('premium', null, InputOption::VALUE_NONE, 'Show only premium albums')
            ->addOption('access-level', null, InputOption::VALUE_REQUIRED, 'Filter by access level (0-3)')
            ->addOption('admin-user', null, InputOption::VALUE_REQUIRED, 'Filter by admin user ID or login')
            ->addOption('ip', null, InputOption::VALUE_REQUIRED, 'Filter by IP address')
            ->addOption('server-group', null, InputOption::VALUE_REQUIRED, 'Filter by storage server group ID or title')
            ->addOption('review-needed', null, InputOption::VALUE_NONE, 'Show only albums that need review')
            ->addOption('not-review-needed', null, InputOption::VALUE_NONE, 'Show only albums that do not need review')
            ->addOption('locked', null, InputOption::VALUE_NONE, 'Show only locked albums')
            ->addOption('unlocked', null, InputOption::VALUE_NONE, 'Show only unlocked albums')
            ->addOption('has-errors', null, InputOption::VALUE_REQUIRED, 'Filter by KVS processing error bit (1|10)')
            ->addOption('posted', null, InputOption::VALUE_REQUIRED, 'Filter by public posting state (yes|no)')
            ->addOption('show-id', null, InputOption::VALUE_REQUIRED, 'Filter by KVS admin show ID')
            ->addOption('field-filter', null, InputOption::VALUE_REQUIRED, 'KVS admin field filter (e.g. filled/tags)')
            ->addOption('flag', null, InputOption::VALUE_REQUIRED, 'Filter by admin or user flag ID')
            ->addOption('flag-votes', null, InputOption::VALUE_REQUIRED, 'Minimum user flag votes for --flag', '1')
            ->addOption('post-date-from', null, InputOption::VALUE_REQUIRED, 'Filter by minimum post date (YYYY-MM-DD)')
            ->addOption('post-date-to', null, InputOption::VALUE_REQUIRED, 'Filter by maximum post date (YYYY-MM-DD)')
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search in album titles, directories, and descriptions')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to display')
            ->addOption('field', null, InputOption::VALUE_REQUIRED, 'Display single field value')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count, ids', 'table')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation of long text fields')
            ->setHelp(<<<'HELP'
Manage KVS photo albums.

<fg=yellow>AVAILABLE FIELDS:</>
  id, album_id    Album ID
  title           Album title
  images          Number of images
  status          Album status (Active/Disabled)
  is_private      Access type (Public/Private/Premium)
  type            Access type alias (Public/Private/Premium)
  access          Access level (From access type/All users/Only members/Only premium members)
  user, username  Username
  date, post_date Posted date
  views           View count
  rating          Rating (out of 5)

<fg=yellow>EXAMPLES:</>
  <fg=green>kvs album list</>
  <fg=green>kvs album list --no-truncate</>
  <fg=green>kvs album list --fields=id,title,images,user</>
  <fg=green>kvs album list --field=title</>
  <fg=green>kvs album list --search="Outdoor"</>
  <fg=green>kvs album list --format=csv</>
  <fg=green>kvs album list --format=ids</>
  <fg=green>kvs album list --status=1 --format=json</>
  <fg=green>kvs album list --format=count</>

<fg=yellow>NOTE:</>
  Long text fields (title) are truncated in table view.
  Use --no-truncate to show full content, or --format=json for exports.
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $this->getStringArgument($input, 'action') ?? 'list';

        return match ($action) {
            'list' => $this->listAlbums($input),
            'show' => $this->showAlbum($this->getStringArgument($input, 'id'), $input),
            'delete' => $this->deleteAlbum($this->getStringArgument($input, 'id'), $input),
            default => $this->failUnknownAction('album', $action, ['list', 'show', 'delete']),
        };
    }

    private function listAlbums(InputInterface $input): int
    {
        if ($this->rejectUnsupportedArgument($input, 'list', 'id', 'an album ID argument', 'show', 'a specific album')) {
            return self::FAILURE;
        }

        if (
            $this->hasConflictingBoolOptions($input, ['public', 'private', 'premium'])
            || $this->hasConflictingBoolOptions($input, ['review-needed', 'not-review-needed'])
            || $this->hasConflictingBoolOptions($input, ['locked', 'unlocked'])
        ) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $fromClause = "FROM {$this->table('albums')} a
                 LEFT JOIN {$this->table('users')} u ON a.user_id = u.user_id";
        $whereClause = 'WHERE 1=1';

        $params = [];

        if (!$this->applyAlbumListFilters($db, $input, $whereClause, $params)) {
            return self::FAILURE;
        }

        try {
            if ($this->getStringOption($input, 'format') === 'count') {
                if ($this->rejectFieldSelectionForCountFormat($input)) {
                    return self::FAILURE;
                }
                if ($this->getPositiveIntOptionOrDefault($input, 'limit', Constants::DEFAULT_CONTENT_LIMIT) === null) {
                    return self::FAILURE;
                }
                $stmt = $db->prepare("SELECT COUNT(*) {$fromClause} {$whereClause}");
                foreach ($params as $key => $value) {
                    $stmt->bindValue($key, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
                }
                $stmt->execute();

                $total = $stmt->fetchColumn();
                $this->io()->writeln((string) (is_numeric($total) ? (int) $total : 0));

                return self::SUCCESS;
            }

            $commentsTable = $this->table('comments');
            [$relationSelectSql, $relationJoinSql] = $this->buildAlbumRelationSql($input);
            $userStatusSelect = $this->isAlbumFieldRequested($input, 'user_status_id')
                ? ', u.status_id as user_status_id'
                : '';

            $query = "SELECT a.*, u.username$userStatusSelect$relationSelectSql,
                 a.photos_amount as image_count,
                 (
                     SELECT COUNT(*) FROM $commentsTable c
                     WHERE c.object_type_id = 2 AND c.object_id = a.album_id
                 ) as comments_count
                 {$fromClause}
                 {$relationJoinSql}
                 {$whereClause}
                 ORDER BY a.album_id DESC LIMIT :limit";

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
                    'images',
                    'status',
                    'is_error',
                    'is_private',
                    'type',
                    'access_level_id',
                    'access',
                    'views',
                    'website_link',
                    'ip',
                    'thumb',
                    'rating',
                ]
            );

            /** @var list<array<string, mixed>> $albums */
            $albums = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Transform albums for display (field aliases and calculated values)
            $transformedAlbums = array_map(function (array $album): array {
                $calculatedRating = calculate_kvs_rating($album['rating'] ?? 0, $album['rating_amount'] ?? 0);

                $statusIdVal = $album['status_id'] ?? 0;
                $statusId = is_numeric($statusIdVal) ? (int) $statusIdVal : 0;
                $privacyIdVal = $album['is_private'] ?? 0;
                $privacyId = is_numeric($privacyIdVal) ? (int) $privacyIdVal : 0;
                $privacy = StatusFormatter::contentPrivacy($privacyId, false);
                $accessLevelIdVal = $album['access_level_id'] ?? 0;
                $accessLevelId = is_numeric($accessLevelIdVal) ? (int) $accessLevelIdVal : 0;
                $albumIp = $album['ip'] ?? null;

                return array_merge(
                    $album,
                    [
                        'album_id' => $album['album_id'] ?? 0,
                        'id' => $album['album_id'] ?? 0,  // Alias
                        'title' => $album['title'] ?? '',
                        'image_count' => $album['image_count'] ?? 0,
                        'photos_amount' => $album['photos_amount'] ?? 0,
                        'images' => $album['image_count'] ?? 0,  // Alias
                        'status_id' => $statusId,
                        'status' => StatusFormatter::album($statusId, false),  // Alias
                        'is_error' => $statusId === 2 ? 1 : 0,
                        'is_private' => $privacy,
                        'type' => $privacy,
                        'access_level_id' => $accessLevelId,
                        'access' => StatusFormatter::contentAccessLevel($accessLevelId, false),
                        'username' => $album['username'] ?? '',
                        'post_date' => $album['post_date'] ?? '',
                        'album_viewed' => $album['album_viewed'] ?? 0,
                        'views' => $album['album_viewed'] ?? 0,  // Alias
                        'comments_count' => $album['comments_count'] ?? 0,
                        'favourites_count' => $album['favourites_count'] ?? 0,
                        'purchases_count' => $album['purchases_count'] ?? 0,
                        'website_link' => $this->buildKvsWebsiteLink(
                            $album,
                            'album_id',
                            'WEBSITE_LINK_PATTERN_ALBUM'
                        ),
                        'ip' => array_key_exists('ip', $album) ? $this->formatKvsIp($albumIp) : '',
                        'thumb' => '',
                        'rating' => $calculatedRating,
                    ],
                    $this->transformAlbumRelationFields($album)
                );
            }, $albums);

            // Format and display output using centralized Formatter
            $formatter = new Formatter(
                $input->getOptions(),
                ['album_id', 'title', 'image_count', 'status', 'is_private', 'username', 'post_date'],
                $knownFields
            );
            $formatter->display($transformedAlbums, $this->io());

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch albums: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @param array<string, int|string> $params
     */
    private function applyAlbumListFilters(
        \PDO $db,
        InputInterface $input,
        string &$whereClause,
        array &$params
    ): bool {
        $status = $this->getStringOption($input, 'status');
        if ($status !== null) {
            $statusId = $this->parseStatusFilterOrFail($input, [
                'active' => StatusFormatter::ALBUM_ACTIVE,
                'disabled' => StatusFormatter::ALBUM_DISABLED,
                'inactive' => StatusFormatter::ALBUM_DISABLED,
                'error' => StatusFormatter::ALBUM_ERROR,
                'processing' => StatusFormatter::ALBUM_PROCESSING,
                'in_process' => StatusFormatter::ALBUM_PROCESSING,
                'in-process' => StatusFormatter::ALBUM_PROCESSING,
                'deleting' => StatusFormatter::ALBUM_DELETING,
                'deleted' => StatusFormatter::ALBUM_DELETED,
            ], [0, 1, 2, 3, 4, 5]);
            if ($statusId === false) {
                return false;
            }
            if ($statusId !== null) {
                $whereClause .= " AND a.status_id = :status";
                $params['status'] = $statusId;
            }
        }

        $user = $this->resolveUserIdOption($db, $input);
        if ($user === false) {
            return false;
        }
        if ($user !== null) {
            $whereClause .= " AND a.user_id = :user";
            $params['user'] = $user;
        }

        $adminUser = $this->resolveAdminUserIdOption($db, $input);
        if ($adminUser === false) {
            return false;
        }
        if ($adminUser !== null) {
            $whereClause .= ' AND a.admin_user_id = :admin_user';
            $params['admin_user'] = $adminUser;
        }

        $ip = $this->getStringOption($input, 'ip');
        if ($ip !== null) {
            $ipNumber = $this->parseKvsIpv4Option($ip);
            if ($ipNumber === false) {
                return false;
            }
            $whereClause .= ' AND a.ip = :ip';
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
            $whereClause .= ' AND a.server_group_id = :server_group';
            $params['server_group'] = $serverGroup;
        }

        if ($this->getBoolOption($input, 'public')) {
            $whereClause .= ' AND a.is_private = 0';
        } elseif ($this->getBoolOption($input, 'private')) {
            $whereClause .= ' AND a.is_private = 1';
        } elseif ($this->getBoolOption($input, 'premium')) {
            $whereClause .= ' AND a.is_private = 2';
        }

        $accessLevel = $this->parseAlbumAccessLevelOption($input);
        if ($accessLevel === false) {
            return false;
        }
        if ($accessLevel !== null) {
            $whereClause .= ' AND a.access_level_id = :access_level';
            $params['access_level'] = $accessLevel;
        }

        if ($this->getBoolOption($input, 'review-needed')) {
            $whereClause .= ' AND a.is_review_needed = 1';
        } elseif ($this->getBoolOption($input, 'not-review-needed')) {
            $whereClause .= ' AND a.is_review_needed = 0';
        }

        if ($this->getBoolOption($input, 'locked')) {
            $whereClause .= ' AND a.is_locked = 1';
        } elseif ($this->getBoolOption($input, 'unlocked')) {
            $whereClause .= ' AND a.is_locked = 0';
        }

        $hasErrors = $this->getOptionalPositiveIntOption($input, 'has-errors');
        if ($hasErrors === false) {
            return false;
        }
        if ($hasErrors !== null) {
            $errorMasks = [
                1 => 1,
                10 => 2,
            ];
            if (!isset($errorMasks[$hasErrors])) {
                $this->io()->error('Invalid value for --has-errors (use: 1 or 10)');
                return false;
            }
            $whereClause .= ' AND (a.has_errors & :has_errors_mask) > 0';
            $params['has_errors_mask'] = $errorMasks[$hasErrors];
        }

        $posted = $this->getStringOption($input, 'posted');
        if ($posted !== null) {
            if (!in_array($posted, ['yes', 'no'], true)) {
                $this->io()->error('Invalid value for --posted (use: yes or no)');
                return false;
            }
            $postedCondition = 'a.status_id = 1 AND a.relative_post_date <= 0 AND a.post_date <= CURRENT_TIMESTAMP';
            if ($posted === 'yes') {
                $whereClause .= " AND {$postedCondition}";
            } else {
                $whereClause .= " AND NOT ({$postedCondition})";
            }
        }

        if (!$this->applyAlbumShowIdFilter($input, $whereClause)) {
            return false;
        }

        $postDateFrom = $this->getDateOption($input, 'post-date-from');
        if ($postDateFrom === false) {
            return false;
        }
        if ($postDateFrom !== null) {
            $whereClause .= ' AND a.post_date >= :post_date_from';
            $params['post_date_from'] = $postDateFrom;
        }

        $postDateTo = $this->getDateOption($input, 'post-date-to');
        if ($postDateTo === false) {
            return false;
        }
        if ($postDateTo !== null) {
            $whereClause .= ' AND a.post_date <= :post_date_to';
            $params['post_date_to'] = $postDateTo . ' 23:59:59';
        }

        if (!$this->applyAlbumRelationFilters($db, $input, $whereClause, $params)) {
            return false;
        }

        if (!$this->applyAlbumFlagFilter($input, $whereClause, $params)) {
            return false;
        }

        $search = $this->getStringOption($input, 'search');
        if ($search !== null) {
            $searchCondition = $this->buildAdminSearchCondition(
                'a.album_id',
                [
                    'a.title',
                    'a.dir',
                    'a.description',
                    'a.gallery_url',
                    'a.delete_reason',
                    'a.custom1',
                    'a.custom2',
                    'a.custom3',
                ],
                $search,
                $params
            );
            $websiteLinkCondition = $this->buildKvsWebsiteLinkSearchCondition(
                'a.album_id',
                'a.dir',
                'WEBSITE_LINK_PATTERN_ALBUM',
                $search,
                $params,
                'search_website_link'
            );
            if ($websiteLinkCondition !== null) {
                $searchCondition = "({$searchCondition} OR {$websiteLinkCondition})";
            }
            $whereClause .= ' AND ' . $searchCondition;
        }

        $fieldFilter = $this->getStringOption($input, 'field-filter');
        if ($fieldFilter !== null) {
            $condition = $this->getAlbumFieldFilterCondition($fieldFilter);
            if ($condition === null) {
                $this->io()->error('Invalid album field filter. Use: ' . implode(', ', $this->getAlbumFieldFilterValues()));
                return false;
            }
            $whereClause .= " AND {$condition}";
        }

        return true;
    }

    private function applyAlbumShowIdFilter(InputInterface $input, string &$whereClause): bool
    {
        $showId = $this->getStringOption($input, 'show-id');
        if ($showId === null) {
            return true;
        }

        $showId = trim($showId);
        $auditLog = $this->table('admin_audit_log');
        switch ($showId) {
            case '13':
                $whereClause .= " AND EXISTS (SELECT 1 FROM {$auditLog} aal_show " .
                    'WHERE aal_show.object_id = a.album_id AND aal_show.object_type_id = 2 AND aal_show.action_id = 100)';
                return true;
            case '14':
                $whereClause .= " AND EXISTS (SELECT 1 FROM {$auditLog} aal_show " .
                    'WHERE aal_show.object_id = a.album_id AND aal_show.object_type_id = 2 AND aal_show.action_id = 140)';
                return true;
            case '15':
                $whereClause .= " AND EXISTS (SELECT 1 FROM {$auditLog} aal_show " .
                    'WHERE aal_show.object_id = a.album_id AND aal_show.object_type_id = 2 AND aal_show.action_id = 140)' .
                    ' AND u.status_id = 6';
                return true;
            case '16':
                $whereClause .= " AND a.gallery_url != ''";
                return true;
            case '17':
                $whereClause .= " AND EXISTS (SELECT 1 FROM {$auditLog} aal_show " .
                    'WHERE aal_show.object_id = a.album_id AND aal_show.object_type_id = 2 AND aal_show.action_id = 110)';
                return true;
        }

        $this->io()->error('Invalid value for --show-id');
        return false;
    }

    /**
     * @param array<string, int|string> $params
     */
    private function applyAlbumFlagFilter(InputInterface $input, string &$whereClause, array &$params): bool
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

        $flagsTable = $this->table('flags_albums');
        $whereClause .= " AND (
            a.admin_flag_id = :admin_flag
            OR (
                SELECT COALESCE(SUM(fa_filter.votes), 0)
                FROM {$flagsTable} fa_filter
                WHERE fa_filter.album_id = a.album_id AND fa_filter.flag_id = :user_flag
            ) >= :minimum_flag_votes
        )";
        $params['admin_flag'] = $flag;
        $params['user_flag'] = $flag;
        $params['minimum_flag_votes'] = $flagVotes;

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

    private function parseAlbumAccessLevelOption(InputInterface $input): int|false|null
    {
        $accessLevel = $this->getStringOption($input, 'access-level');
        if ($accessLevel === null) {
            return null;
        }

        $values = [
            '0' => 0,
            'from-access-type' => 0,
            'inherit' => 0,
            '1' => 1,
            'all' => 1,
            'all-users' => 1,
            '2' => 2,
            'members' => 2,
            'only-members' => 2,
            '3' => 3,
            'premium' => 3,
            'only-premium' => 3,
            'only-premium-members' => 3,
        ];

        $normalized = strtolower(str_replace('_', '-', $accessLevel));
        if (!array_key_exists($normalized, $values)) {
            $this->io()->error(
                'Invalid album access level. Use: 0, 1, 2, 3, inherit, all, members, or premium'
            );
            return false;
        }

        return $values[$normalized];
    }

    /** @return list<string> */
    private function getAlbumFieldFilterValues(): array
    {
        $values = [];
        foreach (['empty', 'filled'] as $prefix) {
            foreach (self::ALBUM_STRING_FIELD_FILTER_COLUMNS as $column) {
                $values[] = "{$prefix}/{$column}";
            }
            foreach (self::ALBUM_NUMERIC_FIELD_FILTER_COLUMNS as $column) {
                $values[] = "{$prefix}/{$column}";
            }
            foreach (self::ALBUM_SPECIAL_FIELD_FILTERS as $field) {
                $values[] = "{$prefix}/{$field}";
            }
        }

        return $values;
    }

    private function getAlbumFieldFilterCondition(string $fieldFilter): ?string
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
            ? $this->getEmptyAlbumFieldFilterCondition($field)
            : $this->getFilledAlbumFieldFilterCondition($field);
    }

    private function getEmptyAlbumFieldFilterCondition(string $field): ?string
    {
        if (in_array($field, self::ALBUM_STRING_FIELD_FILTER_COLUMNS, true)) {
            return "a.{$field} = ''";
        }
        if (in_array($field, self::ALBUM_NUMERIC_FIELD_FILTER_COLUMNS, true)) {
            return "a.{$field} = 0";
        }

        return match ($field) {
            'content_source' => 'a.content_source_id = 0',
            'admin' => 'a.admin_user_id = 0',
            'admin_flag' => 'a.admin_flag_id = 0',
            'tokens_required' => 'a.tokens_required = 0',
            'album_viewed' => 'a.album_viewed = 0',
            'album_viewed_unique' => 'a.album_viewed_unique = 0',
            'comments' => $this->getAlbumCommentsCountExpression() . ' = 0',
            'favourites' => 'a.favourites_count = 0',
            'purchases' => 'a.purchases_count = 0',
            'rating' => '(a.rating = 0 AND a.rating_amount = 1)',
            'tags' => $this->getAlbumRelationExistsCondition('tags_albums', 'tag_id', false),
            'categories' => $this->getAlbumRelationExistsCondition('categories_albums', 'category_id', false),
            'models' => $this->getAlbumRelationExistsCondition('models_albums', 'model_id', false),
            default => null,
        };
    }

    private function getFilledAlbumFieldFilterCondition(string $field): ?string
    {
        if (in_array($field, self::ALBUM_STRING_FIELD_FILTER_COLUMNS, true)) {
            return "a.{$field} != ''";
        }
        if (in_array($field, self::ALBUM_NUMERIC_FIELD_FILTER_COLUMNS, true)) {
            return "a.{$field} != 0";
        }

        return match ($field) {
            'content_source' => 'a.content_source_id > 0',
            'admin' => 'a.admin_user_id > 0',
            'admin_flag' => 'a.admin_flag_id > 0',
            'tokens_required' => 'a.tokens_required > 0',
            'album_viewed' => 'a.album_viewed > 0',
            'album_viewed_unique' => 'a.album_viewed_unique > 0',
            'comments' => $this->getAlbumCommentsCountExpression() . ' > 0',
            'favourites' => 'a.favourites_count > 0',
            'purchases' => 'a.purchases_count > 0',
            'rating' => '(a.rating > 0 OR a.rating_amount > 1)',
            'tags' => $this->getAlbumRelationExistsCondition('tags_albums', 'tag_id', true),
            'categories' => $this->getAlbumRelationExistsCondition('categories_albums', 'category_id', true),
            'models' => $this->getAlbumRelationExistsCondition('models_albums', 'model_id', true),
            default => null,
        };
    }

    private function getAlbumCommentsCountExpression(): string
    {
        $commentsTable = $this->table('comments');
        return "(SELECT COUNT(*) FROM {$commentsTable} c_filter "
            . 'WHERE c_filter.object_id = a.album_id AND c_filter.object_type_id = 2)';
    }

    private function getAlbumRelationExistsCondition(string $relationTable, string $idColumn, bool $exists): string
    {
        $table = $this->table($relationTable);
        $operator = $exists ? 'EXISTS' : 'NOT EXISTS';
        return "{$operator} (SELECT {$idColumn} FROM {$table} rel_filter WHERE rel_filter.album_id = a.album_id)";
    }

    /**
     * @param array<string, int|string> $params
     */
    private function applyAlbumRelationFilters(
        \PDO $db,
        InputInterface $input,
        string &$whereClause,
        array &$params
    ): bool {
        $category = $this->resolveCategoryIdOption($db, $input);
        if ($category === false) {
            return false;
        }
        if ($category !== null) {
            $whereClause .= " AND EXISTS (SELECT 1 FROM {$this->table('categories_albums')} ca "
                . "WHERE ca.album_id = a.album_id AND ca.category_id = :category)";
            $params['category'] = $category;
        }

        $categoryGroup = $this->resolveReferenceIdOrTitleOption(
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
            $whereClause .= " AND EXISTS (SELECT 1 FROM {$this->table('categories_albums')} cag "
                . 'WHERE cag.album_id = a.album_id AND cag.category_id IN ('
                . "SELECT cg.category_id FROM {$this->table('categories')} cg "
                . 'WHERE cg.category_group_id = :category_group))';
            $params['category_group'] = $categoryGroup;
        }

        $tag = $this->resolveTagIdOption($db, $input);
        if ($tag === false) {
            return false;
        }
        if ($tag !== null) {
            $whereClause .= " AND EXISTS (SELECT 1 FROM {$this->table('tags_albums')} ta "
                . "WHERE ta.album_id = a.album_id AND ta.tag_id = :tag)";
            $params['tag'] = $tag;
        }

        $model = $this->resolveModelIdOption($db, $input);
        if ($model === false) {
            return false;
        }
        if ($model !== null) {
            $whereClause .= " AND EXISTS (SELECT 1 FROM {$this->table('models_albums')} ma "
                . "WHERE ma.album_id = a.album_id AND ma.model_id = :model)";
            $params['model'] = $model;
        }

        $modelGroup = $this->resolveReferenceIdOrTitleOption(
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
            $whereClause .= " AND EXISTS (SELECT 1 FROM {$this->table('models_albums')} mag "
                . 'WHERE mag.album_id = a.album_id AND mag.model_id IN ('
                . "SELECT mg.model_id FROM {$this->table('models')} mg "
                . 'WHERE mg.model_group_id = :model_group))';
            $params['model_group'] = $modelGroup;
        }

        $contentSource = $this->resolveContentSourceIdOption($db, $input);
        if ($contentSource === false) {
            return false;
        }
        if ($contentSource !== null) {
            $whereClause .= " AND a.content_source_id = :content_source";
            $params['content_source'] = $contentSource;
        }

        $contentSourceGroup = $this->resolveReferenceIdOrTitleOption(
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
            $whereClause .= ' AND a.content_source_id IN ('
                . "SELECT csg.content_source_id FROM {$this->table('content_sources')} csg "
                . 'WHERE csg.content_source_group_id = :content_source_group)';
            $params['content_source_group'] = $contentSourceGroup;
        }

        return true;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function buildAlbumRelationSql(InputInterface $input): array
    {
        $selects = [];
        $joins = [];

        if (
            $this->isAlbumFieldRequested($input, 'content_source')
            || $this->isAlbumFieldRequested($input, 'content_source_status_id')
        ) {
            $selects[] = 'cs.title as content_source';
            $selects[] = 'cs.status_id as content_source_status_id';
            $joins[] = "LEFT JOIN {$this->table('content_sources')} cs ON cs.content_source_id = a.content_source_id";
        }

        if (
            $this->isAlbumFieldRequested($input, 'admin_user')
            || $this->isAlbumFieldRequested($input, 'admin_user_is_superadmin')
        ) {
            $selects[] = 'au.login as admin_user';
            $selects[] = 'au.is_superadmin as admin_user_is_superadmin';
            $joins[] = "LEFT JOIN {$this->table('admin_users')} au ON au.user_id = a.admin_user_id";
        }

        if ($this->isAlbumFieldRequested($input, 'admin_flag')) {
            $selects[] = 'f.title as admin_flag';
            $joins[] = "LEFT JOIN {$this->table('flags')} f ON f.flag_id = a.admin_flag_id";
        }

        if (
            $this->isAlbumFieldRequested($input, 'server_group')
            || $this->isAlbumFieldRequested($input, 'server_group_status_id')
        ) {
            $selects[] = 'sg.title as server_group';
            $selects[] = 'sg.status_id as server_group_status_id';
            $joins[] = "LEFT JOIN {$this->table('admin_servers_groups')} sg ON sg.group_id = a.server_group_id";
        }
        if ($this->isAlbumFieldRequested($input, 'tags')) {
            $selects[] = "(
                SELECT GROUP_CONCAT(t.tag ORDER BY ta.id ASC)
                FROM {$this->table('tags')} t
                INNER JOIN {$this->table('tags_albums')} ta ON ta.tag_id = t.tag_id
                WHERE ta.album_id = a.album_id
            ) as tags";
        }
        if ($this->isAlbumFieldRequested($input, 'categories')) {
            $selects[] = "(
                SELECT GROUP_CONCAT(c.title ORDER BY ca.id ASC)
                FROM {$this->table('categories')} c
                INNER JOIN {$this->table('categories_albums')} ca ON ca.category_id = c.category_id
                WHERE ca.album_id = a.album_id
            ) as categories";
        }
        if ($this->isAlbumFieldRequested($input, 'models')) {
            $selects[] = "(
                SELECT GROUP_CONCAT(m.title ORDER BY ma.id ASC)
                FROM {$this->table('models')} m
                INNER JOIN {$this->table('models_albums')} ma ON ma.model_id = m.model_id
                WHERE ma.album_id = a.album_id
            ) as models";
        }

        return [
            $selects === [] ? '' : ",\n                 " . implode(",\n                 ", $selects),
            implode("\n                 ", $joins),
        ];
    }

    private function isAlbumFieldRequested(InputInterface $input, string $field): bool
    {
        $fieldOption = $this->getStringOption($input, 'field');
        if ($fieldOption === $field) {
            return true;
        }

        $fieldsOption = $this->getStringOption($input, 'fields');
        if ($fieldsOption === null || $fieldsOption === '') {
            return false;
        }

        return in_array($field, array_map('trim', explode(',', $fieldsOption)), true);
    }

    /**
     * @param array<string, mixed> $album
     * @return array<string, mixed>
     */
    private function transformAlbumRelationFields(array $album): array
    {
        return [
            'content_source' => $album['content_source'] ?? '',
            'content_source_status_id' => $album['content_source_status_id'] ?? '',
            'admin_flag' => $album['admin_flag'] ?? '',
            'server_group' => $album['server_group'] ?? '',
            'server_group_status_id' => $album['server_group_status_id'] ?? '',
        ];
    }

    private function showAlbum(?string $id, InputInterface $input): int
    {
        $albumId = $this->getRequiredPositiveId($id, 'Album');
        if ($albumId === null) {
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
            $stmt = $db->prepare("
                SELECT a.*, u.username
                FROM {$this->table('albums')} a
                LEFT JOIN {$this->table('users')} u ON a.user_id = u.user_id
                WHERE a.album_id = :id
            ");
            $stmt->execute(['id' => $albumId]);
            /** @var array<string, mixed>|false $album */
            $album = $stmt->fetch();

            if ($album === false) {
                $this->io()->error("Album not found: $albumId");
                return self::FAILURE;
            }

            $title = isset($album['title']) && is_string($album['title']) ? $album['title'] : '';
            $statusIdVal = $album['status_id'] ?? 0;
            $statusId = is_numeric($statusIdVal) ? (int) $statusIdVal : 0;
            $postDate = isset($album['post_date']) && is_string($album['post_date']) ? $album['post_date'] : '';
            $postTimestamp = strtotime($postDate);
            $privacyIdVal = $album['is_private'] ?? 0;
            $privacyId = is_numeric($privacyIdVal) ? (int) $privacyIdVal : 0;
            $accessLevelIdVal = $album['access_level_id'] ?? 0;
            $accessLevelId = is_numeric($accessLevelIdVal) ? (int) $accessLevelIdVal : 0;
            $viewedVal = $album['album_viewed'] ?? 0;
            $views = is_numeric($viewedVal) ? (int) $viewedVal : 0;
            $photosAmountVal = $album['photos_amount'] ?? 0;
            $imageCountValue = is_numeric($photosAmountVal) ? (int) $photosAmountVal : 0;
            $username = isset($album['username']) && is_string($album['username']) && $album['username'] !== ''
                ? $album['username']
                : 'N/A';

            $info = [
                ['Title', $title],
                ['Status', StatusFormatter::album($statusId)],
                ['Type', StatusFormatter::contentPrivacy($privacyId)],
                ['Access', StatusFormatter::contentAccessLevel($accessLevelId)],
                ['User', $username],
                ['Images', $imageCountValue],
                ['Posted', $postTimestamp !== false ? date('Y-m-d H:i:s', $postTimestamp) : 'Unknown'],
                ['Views', number_format($views)],
                [
                    'Rating',
                    format_kvs_rating($album['rating'] ?? 0, $album['rating_amount'] ?? 0)
                ],
            ];

            if ($this->shouldUseFormattedRows($input)) {
                return $this->displayDetailRows($input, $info, ['album_id' => (string) $albumId]);
            }

            $this->io()->section("Album #$albumId");
            $this->renderTable(['Property', 'Value'], $info);
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch album: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function deleteAlbum(?string $id, InputInterface $input): int
    {
        if ($id === null || $id === '') {
            $this->io()->error('Album ID is required');
            return self::FAILURE;
        }
        if (!ctype_digit($id)) {
            $this->io()->error('Album ID must be numeric');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("SELECT album_id, status_id FROM {$this->table('albums')} WHERE album_id = :id");
            $stmt->execute(['id' => $id]);
            /** @var array{album_id: int|string, status_id: int|string}|false $album */
            $album = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($album === false || !is_numeric($album['album_id'])) {
                $this->io()->error("Album not found: #$id");
                return self::FAILURE;
            }

            $albumId = (int) $album['album_id'];
            $statusId = is_numeric($album['status_id']) ? (int) $album['status_id'] : -1;
            $deletableStatuses = [
                StatusFormatter::ALBUM_DISABLED,
                StatusFormatter::ALBUM_ACTIVE,
                StatusFormatter::ALBUM_ERROR,
            ];
            if (!in_array($statusId, $deletableStatuses, true)) {
                $this->io()->error(sprintf(
                    'Album cannot be deleted in its current status: #%d (%s)',
                    $albumId,
                    StatusFormatter::album($statusId, false)
                ));
                return self::FAILURE;
            }

            $this->io()->warning("This will delete album #$id using KVS native cleanup");
            $this->io()->warning('Files, references and counters will be queued for KVS background deletion.');

            if ($this->io()->confirm('Do you want to continue?', false) !== true) {
                if (!$input->isInteractive()) {
                    $this->io()->error('Album deletion cancelled because confirmation was not provided.');
                    return self::FAILURE;
                }

                $this->io()->warning('Album deletion cancelled');
                return self::SUCCESS;
            }

            $this->deleteAlbumWithKvs($albumId);
            $this->io()->success("Album #$id queued for KVS deletion");
        } catch (\Exception $e) {
            $this->io()->error('Failed to delete album: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function deleteAlbumWithKvs(int $albumId): void
    {
        $this->runWithKvsAdminContext(function () use ($albumId): void {
            if (!function_exists('delete_album')) {
                throw new \RuntimeException('KVS delete_album function is not available');
            }

            if (delete_album($albumId) !== true) {
                throw new \RuntimeException("KVS refused to delete album #$albumId");
            }
        }, ['functions_servers.php', 'functions_admin.php']);
    }
}
