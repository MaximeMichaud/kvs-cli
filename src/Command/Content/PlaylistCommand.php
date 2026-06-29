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

use function KVS\CLI\Utils\calculate_kvs_rating;
use function KVS\CLI\Utils\format_kvs_rating;

#[AsCommand(
    name: 'content:playlist',
    description: 'Manage KVS playlists',
    aliases: ['playlist', 'playlists']
)]
class PlaylistCommand extends BaseCommand
{
    /** @var list<string> */
    private const SHOW_UNSUPPORTED_OPTIONS = [
        'status',
        'user',
        'public',
        'private',
        'search',
        'category',
        'tag',
        'field-filter',
        'flag',
        'flag-votes',
        'review-needed',
        'not-review-needed',
        'locked',
        'unlocked',
        'title',
        'description',
        'dir',
        'video',
        'limit',
    ];

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'Action to perform (list|show|create|add|remove|delete)')
            ->addArgument('id', InputArgument::OPTIONAL, 'Playlist ID, or title for create')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status (active|disabled)')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Filter by user ID or username')
            ->addOption('public', null, InputOption::VALUE_NONE, 'Show only public playlists')
            ->addOption('private', null, InputOption::VALUE_NONE, 'Show only private playlists')
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search in titles, directories, and descriptions')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Filter by category ID or title')
            ->addOption('tag', null, InputOption::VALUE_REQUIRED, 'Filter by tag ID or name')
            ->addOption('field-filter', null, InputOption::VALUE_REQUIRED, 'KVS admin field filter (e.g. filled/videos)')
            ->addOption('flag', null, InputOption::VALUE_REQUIRED, 'Filter by flag ID')
            ->addOption('flag-votes', null, InputOption::VALUE_REQUIRED, 'Minimum flag votes for --flag', '1')
            ->addOption('review-needed', null, InputOption::VALUE_NONE, 'Show only playlists that need review')
            ->addOption('not-review-needed', null, InputOption::VALUE_NONE, 'Show only playlists that do not need review')
            ->addOption('locked', null, InputOption::VALUE_NONE, 'Show only locked playlists')
            ->addOption('unlocked', null, InputOption::VALUE_NONE, 'Show only unlocked playlists')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Playlist title for create action')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Playlist description for create action')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Playlist directory slug for create action')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results', Constants::DEFAULT_CONTENT_LIMIT)
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to display')
            ->addOption('field', null, InputOption::VALUE_REQUIRED, 'Display single field value')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count, ids', 'table')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation of long text fields')
            ->addOption('video', null, InputOption::VALUE_REQUIRED, 'Video ID (required for add/remove actions)')
            ->setHelp(<<<'HELP'
Manage KVS playlists.

<fg=yellow>AVAILABLE FIELDS:</>
  id, playlist_id   Playlist ID
  title             Playlist title
  status            Playlist status (Active/Disabled)
  type              Public/Private
  videos            Number of videos in playlist
  user, username    Owner username
  views             View count
  rating            Rating (out of 5)
  date, added_date  Created date

<fg=yellow>EXAMPLES:</>
  <fg=green>kvs playlist list</>
  <fg=green>kvs playlist list --public</>
  <fg=green>kvs playlist list --private --user=alice</>
  <fg=green>kvs playlist list --status=active --limit=50</>
  <fg=green>kvs playlist list --search="favorites"</>
	  <fg=green>kvs playlist list --field=title</>
	  <fg=green>kvs playlist list --format=json</>
	  <fg=green>kvs playlist list --format=ids</>
	  <fg=green>kvs playlist list --format=count</>
	  <fg=green>kvs playlist create "Favorites" --user=1 --private</>
	  <fg=green>kvs playlist show 1</>
	  <fg=green>kvs playlist add 1 --video=42</>
	  <fg=green>kvs playlist remove 1 --video=42</>
  <fg=green>kvs playlist delete 1</>

<fg=yellow>NOTE:</>
  Long text fields (title, description) are truncated in table view.
  Use --no-truncate to show full content, or --format=json for exports.
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $this->getStringArgument($input, 'action') ?? 'list';

        return match ($action) {
            'list' => $this->listPlaylists($input),
            'show' => $this->showPlaylist($this->getStringArgument($input, 'id'), $input),
            'create' => $this->createPlaylist($input),
            'add' => $this->addVideoToPlaylist(
                $this->getStringArgument($input, 'id'),
                $this->getStringOption($input, 'video')
            ),
            'remove' => $this->removeVideoFromPlaylist(
                $this->getStringArgument($input, 'id'),
                $this->getStringOption($input, 'video')
            ),
            'delete' => $this->deletePlaylist($this->getStringArgument($input, 'id'), $input),
            default => $this->failUnknownAction('playlist', $action, ['list', 'show', 'create', 'add', 'remove', 'delete']),
        };
    }

    private function listPlaylists(InputInterface $input): int
    {
        if (
            $this->hasConflictingBoolOptions($input, ['public', 'private'])
            || $this->hasConflictingBoolOptions($input, ['review-needed', 'not-review-needed'])
            || $this->hasConflictingBoolOptions($input, ['locked', 'unlocked'])
        ) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $fromClause = "FROM {$this->table('playlists')} p
                 LEFT JOIN {$this->table('users')} u ON p.user_id = u.user_id
                 WHERE 1=1";

        $params = [];

        if (!$this->applyPlaylistListFilters($db, $input, $fromClause, $params)) {
            return self::FAILURE;
        }

        if ($this->getStringOptionOrDefault($input, 'format', 'table') === 'count') {
            if ($this->getPositiveIntOptionOrDefault($input, 'limit', Constants::DEFAULT_CONTENT_LIMIT) === null) {
                return self::FAILURE;
            }
            return $this->countPlaylists($db, $fromClause, $params);
        }

        $commentsTable = $this->table('comments');
        $favVideosTable = $this->table('fav_videos');
        $userStatusSelect = $this->isPlaylistFieldRequested($input, 'user_status_id')
            ? ', u.status_id as user_status_id'
            : '';
        $relationSelect = $this->buildPlaylistRelationSelectSql($input);

        $query = "SELECT p.*, u.username$userStatusSelect$relationSelect,
                        (SELECT COUNT(*) FROM $favVideosTable f WHERE f.playlist_id = p.playlist_id) as video_count,
                        (
                            SELECT COUNT(*) FROM $commentsTable c
                            WHERE c.object_type_id = 13 AND c.object_id = p.playlist_id
                        ) as comments_amount
                 $fromClause
                 ORDER BY p.playlist_id DESC LIMIT :limit";

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

            /** @var list<array<string, mixed>> $playlists */
            $playlists = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Transform playlists for display
            $transformedPlaylists = array_map(function (array $playlist): array {
                $calculatedRating = calculate_kvs_rating($playlist['rating'] ?? 0, $playlist['rating_amount'] ?? 0);

                $statusIdVal = $playlist['status_id'] ?? 0;
                $statusId = is_numeric($statusIdVal) ? (int) $statusIdVal : 0;

                $isPrivateVal = $playlist['is_private'] ?? 0;
                $isPrivate = is_numeric($isPrivateVal) ? (int) $isPrivateVal : 0;

                return [
                    ...$playlist,
                    'playlist_id' => $playlist['playlist_id'] ?? 0,
                    'id' => $playlist['playlist_id'] ?? 0,  // Alias
                    'title' => $playlist['title'] ?? '',
                    'status_id' => $statusId,
                    'status' => StatusFormatter::playlist($statusId, false),  // Alias
                    'is_private' => $isPrivate,
                    'type' => $isPrivate !== 0 ? 'Private' : 'Public',  // Alias
                    'total_videos' => $playlist['video_count'] ?? 0,
                    'videos_amount' => $playlist['video_count'] ?? 0,
                    'videos' => $playlist['video_count'] ?? 0,  // Alias
                    'comments_amount' => $playlist['comments_amount'] ?? 0,
                    'username' => $playlist['username'] ?? '',
                    'user' => $playlist['username'] ?? '',  // Alias
                    'playlist_viewed' => $playlist['playlist_viewed'] ?? 0,
                    'views' => $playlist['playlist_viewed'] ?? 0,  // Alias
                    'rating' => $calculatedRating,
                    'added_date' => $playlist['added_date'] ?? '',
                    'date' => $playlist['added_date'] ?? '',  // Alias
                ];
            }, $playlists);

            // Format and display output using centralized Formatter
            $formatter = new Formatter(
                $input->getOptions(),
                ['playlist_id', 'title', 'status', 'type', 'total_videos', 'username', 'added_date']
            );
            $formatter->display($transformedPlaylists, $this->io());

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch playlists: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @param array<string, int|string> $params
     */
    private function applyPlaylistListFilters(
        \PDO $db,
        InputInterface $input,
        string &$fromClause,
        array &$params
    ): bool {
        $status = $this->getStringOption($input, 'status');
        if ($status !== null) {
            $statusId = $this->parseStatusFilterOrFail($input, [
                'active' => StatusFormatter::PLAYLIST_ACTIVE,
                'disabled' => StatusFormatter::PLAYLIST_DISABLED,
                'inactive' => StatusFormatter::PLAYLIST_DISABLED,
            ]);
            if ($statusId === false) {
                return false;
            }
            if ($statusId !== null) {
                $fromClause .= " AND p.status_id = :status";
                $params['status'] = $statusId;
            }
        }

        $user = $this->resolveUserIdOption($db, $input);
        if ($user === false) {
            return false;
        }
        if ($user !== null) {
            $fromClause .= " AND p.user_id = :user";
            $params['user'] = $user;
        }

        if ($input->getOption('public')) {
            $fromClause .= " AND p.is_private = 0";
        } elseif ($input->getOption('private')) {
            $fromClause .= " AND p.is_private = 1";
        }

        if ($this->getBoolOption($input, 'review-needed')) {
            $fromClause .= " AND p.is_review_needed = 1";
        } elseif ($this->getBoolOption($input, 'not-review-needed')) {
            $fromClause .= " AND p.is_review_needed = 0";
        }

        if ($this->getBoolOption($input, 'locked')) {
            $fromClause .= " AND p.is_locked = 1";
        } elseif ($this->getBoolOption($input, 'unlocked')) {
            $fromClause .= " AND p.is_locked = 0";
        }

        $search = $input->getOption('search');
        if (is_string($search) && $search !== '') {
            $fromClause .= ' AND ' . $this->buildAdminSearchCondition(
                'p.playlist_id',
                [
                    'p.title',
                    'p.dir',
                    'p.description',
                ],
                $search,
                $params
            );
        }

        $fieldFilter = $this->getStringOption($input, 'field-filter');
        if ($fieldFilter !== null) {
            $condition = $this->getPlaylistFieldFilterCondition($fieldFilter);
            if ($condition === null) {
                $this->io()->error(
                    'Invalid playlist field filter. Use: ' . implode(', ', $this->getPlaylistFieldFilterValues())
                );
                return false;
            }
            $fromClause .= " AND {$condition}";
        }

        if (!$this->applyPlaylistFlagFilter($input, $fromClause, $params)) {
            return false;
        }

        return $this->applyPlaylistRelationFilters($db, $input, $fromClause, $params);
    }

    /**
     * @param array<string, int|string> $params
     */
    private function applyPlaylistFlagFilter(InputInterface $input, string &$fromClause, array &$params): bool
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

        $flagsTable = $this->table('flags_playlists');
        $fromClause .= " AND (
            SELECT SUM(fp_filter.votes)
            FROM {$flagsTable} fp_filter
            WHERE fp_filter.playlist_id = p.playlist_id AND fp_filter.flag_id = :flag
        ) >= :flag_votes";
        $params['flag'] = $flag;
        $params['flag_votes'] = $flagVotes;

        return true;
    }

    /** @return list<string> */
    private function getPlaylistFieldFilterValues(): array
    {
        return [
            'empty/description',
            'empty/playlist_viewed',
            'empty/rating',
            'empty/tags',
            'empty/categories',
            'empty/videos',
            'filled/description',
            'filled/playlist_viewed',
            'filled/rating',
            'filled/tags',
            'filled/categories',
            'filled/videos',
        ];
    }

    private function getPlaylistFieldFilterCondition(string $fieldFilter): ?string
    {
        $tagsTable = $this->table('tags_playlists');
        $categoriesTable = $this->table('categories_playlists');
        $favVideosTable = $this->table('fav_videos');

        return match ($fieldFilter) {
            'empty/description' => "p.description = ''",
            'empty/playlist_viewed' => 'p.playlist_viewed = 0',
            'empty/rating' => '(p.rating = 0 AND p.rating_amount = 1)',
            'empty/tags' => "NOT EXISTS (SELECT 1 FROM {$tagsTable} tp_empty WHERE tp_empty.playlist_id = p.playlist_id)",
            'empty/categories' => "NOT EXISTS (SELECT 1 FROM {$categoriesTable} cp_empty WHERE cp_empty.playlist_id = p.playlist_id)",
            'empty/videos' => "NOT EXISTS (SELECT 1 FROM {$favVideosTable} fv_empty WHERE fv_empty.playlist_id = p.playlist_id)",
            'filled/description' => "p.description != ''",
            'filled/playlist_viewed' => 'p.playlist_viewed != 0',
            'filled/rating' => '(p.rating > 0 OR p.rating_amount > 1)',
            'filled/tags' => "EXISTS (SELECT 1 FROM {$tagsTable} tp_filled WHERE tp_filled.playlist_id = p.playlist_id)",
            'filled/categories' => "EXISTS (SELECT 1 FROM {$categoriesTable} cp_filled WHERE cp_filled.playlist_id = p.playlist_id)",
            'filled/videos' => "EXISTS (SELECT 1 FROM {$favVideosTable} fv_filled WHERE fv_filled.playlist_id = p.playlist_id)",
            default => null,
        };
    }

    /**
     * @param array<string, int|string> $params
     */
    private function applyPlaylistRelationFilters(
        \PDO $db,
        InputInterface $input,
        string &$fromClause,
        array &$params
    ): bool {
        $category = $this->resolveCategoryIdOption($db, $input);
        if ($category === false) {
            return false;
        }
        if ($category !== null) {
            $fromClause .= " AND EXISTS (SELECT 1 FROM {$this->table('categories_playlists')} cp "
                . "WHERE cp.playlist_id = p.playlist_id AND cp.category_id = :category)";
            $params['category'] = $category;
        }

        $tag = $this->resolveTagIdOption($db, $input);
        if ($tag === false) {
            return false;
        }
        if ($tag !== null) {
            $fromClause .= " AND EXISTS (SELECT 1 FROM {$this->table('tags_playlists')} tp "
                . "WHERE tp.playlist_id = p.playlist_id AND tp.tag_id = :tag)";
            $params['tag'] = $tag;
        }

        return true;
    }

    private function isPlaylistFieldRequested(InputInterface $input, string $field): bool
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

    private function buildPlaylistRelationSelectSql(InputInterface $input): string
    {
        $selects = [];

        if ($this->isPlaylistFieldRequested($input, 'tags')) {
            $selects[] = "(
                SELECT GROUP_CONCAT(t.tag ORDER BY tp.id ASC)
                FROM {$this->table('tags')} t
                INNER JOIN {$this->table('tags_playlists')} tp ON tp.tag_id = t.tag_id
                WHERE tp.playlist_id = p.playlist_id
            ) as tags";
        }
        if ($this->isPlaylistFieldRequested($input, 'categories')) {
            $selects[] = "(
                SELECT GROUP_CONCAT(c.title ORDER BY cp.id ASC)
                FROM {$this->table('categories')} c
                INNER JOIN {$this->table('categories_playlists')} cp ON cp.category_id = c.category_id
                WHERE cp.playlist_id = p.playlist_id
            ) as categories";
        }

        return $selects === [] ? '' : ",\n                        " . implode(",\n                        ", $selects);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function countPlaylists(\PDO $db, string $fromClause, array $params): int
    {
        try {
            $stmt = $db->prepare("SELECT COUNT(*) $fromClause");
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $stmt->execute();

            $total = $stmt->fetchColumn();
            $this->io()->writeln((string) (is_numeric($total) ? (int) $total : 0));

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to count playlists: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function showPlaylist(?string $id, InputInterface $input): int
    {
        $playlistId = $this->getRequiredPositiveId($id, 'Playlist');
        if ($playlistId === null) {
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
            // Fetch main playlist data with username
            $stmt = $db->prepare("
                SELECT p.*, u.username,
                       (SELECT COUNT(*) FROM {$this->table('fav_videos')} f WHERE f.playlist_id = p.playlist_id) as video_count
                FROM {$this->table('playlists')} p
                LEFT JOIN {$this->table('users')} u ON p.user_id = u.user_id
                WHERE p.playlist_id = :id
            ");
            $stmt->execute(['id' => $playlistId]);
            /** @var array<string, mixed>|false $playlist */
            $playlist = $stmt->fetch();

            if ($playlist === false) {
                $this->io()->error("Playlist not found: $playlistId");
                return self::FAILURE;
            }

            // Type calculation
            $isPrivate = isset($playlist['is_private']) && is_numeric($playlist['is_private'])
                ? (int) $playlist['is_private']
                : 0;
            $type = $isPrivate !== 0 ? 'Private' : 'Public';

            // Rating calculation
            $ratingAmount = isset($playlist['rating_amount']) && is_numeric($playlist['rating_amount'])
                ? (int) $playlist['rating_amount']
                : 0;
            $ratingDisplay = format_kvs_rating($playlist['rating'] ?? 0, $ratingAmount);

            // Status
            $statusId = isset($playlist['status_id']) && is_numeric($playlist['status_id'])
                ? (int) $playlist['status_id']
                : 0;

            $addedDate = $playlist['added_date'] ?? '';
            $addedTimestamp = is_string($addedDate) ? strtotime($addedDate) : false;
            $lastContentDate = $playlist['last_content_date'] ?? '';
            $lastContentTimestamp = is_string($lastContentDate) ? strtotime($lastContentDate) : false;

            $titleVal = $playlist['title'] ?? '';
            $title = is_string($titleVal) ? $titleVal : '';
            $usernameVal = $playlist['username'] ?? 'Unknown';
            $username = is_string($usernameVal) ? $usernameVal : 'Unknown';
            $totalVideosVal = $playlist['video_count'] ?? 0;
            $totalVideos = is_numeric($totalVideosVal) ? (int) $totalVideosVal : 0;
            $playlistViewedVal = $playlist['playlist_viewed'] ?? 0;
            $playlistViewed = is_numeric($playlistViewedVal) ? (int) $playlistViewedVal : 0;
            $commentsCountVal = $playlist['comments_count'] ?? 0;
            $commentsCount = is_numeric($commentsCountVal) ? (int) $commentsCountVal : 0;
            $subscribersCountVal = $playlist['subscribers_count'] ?? 0;
            $subscribersCount = is_numeric($subscribersCountVal) ? (int) $subscribersCountVal : 0;

            $info = [
                ['Title', $title],
                ['Status', StatusFormatter::playlist($statusId)],
                ['Type', $type],
                ['Owner', $username],
                ['Videos', number_format($totalVideos)],
                ['Views', number_format($playlistViewed)],
                ['Rating', $ratingDisplay],
                ['Comments', number_format($commentsCount)],
                ['Subscribers', number_format($subscribersCount)],
                ['Created', $addedTimestamp !== false ? date('Y-m-d H:i:s', $addedTimestamp) : 'Unknown'],
                ['Last Updated', $lastContentTimestamp !== false ? date('Y-m-d H:i:s', $lastContentTimestamp) : 'Never'],
            ];

            // Description section
            $description = isset($playlist['description']) && is_string($playlist['description']) ? $playlist['description'] : '';

            // Videos in playlist (top 10)
            $stmt = $db->prepare("
                SELECT v.video_id, v.title
                FROM {$this->table('videos')} v
                INNER JOIN {$this->table('fav_videos')} f ON v.video_id = f.video_id
                WHERE f.playlist_id = :id AND f.fav_type = 10
                ORDER BY f.playlist_sort_id ASC
                LIMIT 10
            ");
            $stmt->execute(['id' => $playlistId]);
            /** @var list<array{video_id: int, title: string}> $videos */
            $videos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Categories
            $stmt = $db->prepare("
                SELECT c.title
                FROM {$this->table('categories')} c
                INNER JOIN {$this->table('categories')}_playlists cp ON c.category_id = cp.category_id
                WHERE cp.playlist_id = :id
                ORDER BY cp.id ASC
            ");
            $stmt->execute(['id' => $playlistId]);
            /** @var list<array{title: string}> $categories */
            $categories = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Tags
            $stmt = $db->prepare("
                SELECT t.tag
                FROM {$this->table('tags')} t
                INNER JOIN {$this->table('tags')}_playlists tp ON t.tag_id = tp.tag_id
                WHERE tp.playlist_id = :id
                ORDER BY tp.id ASC
            ");
            $stmt->execute(['id' => $playlistId]);
            /** @var list<array{tag: string}> $tags */
            $tags = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $videoList = [];
            foreach ($videos as $video) {
                $videoList[] = [
                    'video_id' => $video['video_id'],
                    'title' => $video['title'],
                ];
            }
            $categoryTitles = array_map(fn($c) => $c['title'], $categories);
            $tagNames = array_map(fn($t) => $t['tag'], $tags);

            if (!$this->isTableFormat($input)) {
                return $this->displayDetailRows($input, $info, [
                    'playlist_id' => (string) $playlistId,
                    'description' => $description,
                    'videos_top' => $videoList,
                    'categories' => $categoryTitles,
                    'tags' => $tagNames,
                ]);
            }

            $this->io()->section("Playlist #$playlistId");
            $this->renderTable(['Property', 'Value'], $info);

            if ($description !== '') {
                $this->io()->newLine();
                $this->io()->section('Description');
                $this->io()->text($description);
            }

            if (count($videos) > 0) {
                $this->io()->newLine();
                $this->io()->section('Videos (Top 10)');
                $this->io()->listing(array_map(
                    static fn (array $video): string => sprintf('#%d: %s', $video['video_id'], $video['title']),
                    $videos
                ));
            }

            if (count($categories) > 0) {
                $this->io()->newLine();
                $this->io()->section('Categories');
                $this->io()->text(implode(', ', $categoryTitles));
            }

            if (count($tags) > 0) {
                $this->io()->newLine();
                $this->io()->section('Tags');
                $this->io()->text(implode(', ', $tagNames));
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch playlist: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Fetch a playlist by ID, returning [user_id, is_locked, title] or null if not found.
     *
     * @return array{user_id: int, is_locked: int, title: string}|null
     */
    private function fetchPlaylistOwnerAndLock(\PDO $db, int $playlistId): ?array
    {
        $stmt = $db->prepare(
            "SELECT user_id, is_locked, title FROM {$this->table('playlists')} WHERE playlist_id = :id"
        );
        $stmt->execute(['id' => $playlistId]);
        /** @var array{user_id: int|string, is_locked: int|string, title: string}|false $row */
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        return [
            'user_id' => (int) $row['user_id'],
            'is_locked' => (int) $row['is_locked'],
            'title' => $row['title'],
        ];
    }

    private function createPlaylist(InputInterface $input): int
    {
        $title = $this->getStringOption($input, 'title') ?? $this->getStringArgument($input, 'id');
        if ($title === null || trim($title) === '') {
            $this->io()->error('Playlist title is required');
            $this->io()->text('Usage: kvs content:playlist create "Playlist Title" --user=<user_id>');
            return self::FAILURE;
        }
        $title = trim($title);

        $userId = $this->parsePositivePlaylistIdOption($this->getStringOption($input, 'user'), 'User', 'User ID is required (use --user=<id>)');
        if ($userId === null) {
            return self::FAILURE;
        }

        if ($input->getOption('public') === true && $input->getOption('private') === true) {
            $this->io()->error('Use either --public or --private, not both');
            return self::FAILURE;
        }

        try {
            $statusId = $this->parseStatusFilter($input, [
                'active' => StatusFormatter::PLAYLIST_ACTIVE,
                'disabled' => StatusFormatter::PLAYLIST_DISABLED,
                'inactive' => StatusFormatter::PLAYLIST_DISABLED,
            ]) ?? StatusFormatter::PLAYLIST_ACTIVE;
        } catch (\InvalidArgumentException $e) {
            $this->io()->error($e->getMessage());
            return self::FAILURE;
        }

        $isPrivate = $input->getOption('private') === true ? 1 : 0;
        if ($isPrivate === 1) {
            $statusId = StatusFormatter::PLAYLIST_ACTIVE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            if (!$this->userExists($db, $userId)) {
                $this->io()->error("User not found: $userId");
                return self::FAILURE;
            }

            $requestedDir = $this->getStringOption($input, 'dir');
            $dir = $this->getUniquePlaylistDir($db, $requestedDir ?? $this->slugifyDirectory($title));
            $description = $this->getStringOption($input, 'description') ?? '';
            $now = date('Y-m-d H:i:s');

            $restoreSqlMode = $this->relaxSqlMode($db);
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("
                    INSERT INTO {$this->table('playlists')}
                        (user_id, title, dir, description, status_id, is_private, is_locked,
                         rating, rating_amount, added_date, last_content_date)
                    VALUES
                        (:user_id, :title, :dir, :description, :status_id, :is_private, 0,
                         0, 1, :added_date, :last_content_date)
                ");
                $stmt->execute([
                    'user_id' => $userId,
                    'title' => $title,
                    'dir' => $dir,
                    'description' => $description,
                    'status_id' => $statusId,
                    'is_private' => $isPrivate,
                    'added_date' => $now,
                    'last_content_date' => $now,
                ]);

                $playlistId = (string) $db->lastInsertId();
                $db->commit();
            } catch (\Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            } finally {
                $this->restoreSqlMode($db, $restoreSqlMode);
            }

            $this->io()->success("Playlist created successfully with ID: $playlistId");
            $this->renderTable(
                ['Property', 'Value'],
                [
                    ['ID', $playlistId],
                    ['Title', $title],
                    ['User ID', (string) $userId],
                    ['Directory', $dir],
                    ['Type', $isPrivate === 1 ? 'Private' : 'Public'],
                    ['Status', StatusFormatter::playlist($statusId, false)],
                ]
            );
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to create playlist: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function userExists(\PDO $db, int $userId): bool
    {
        $stmt = $db->prepare("SELECT 1 FROM {$this->table('users')} WHERE user_id = :id");
        $stmt->execute(['id' => $userId]);

        return $stmt->fetchColumn() !== false;
    }

    private function getUniquePlaylistDir(\PDO $db, string $baseDir): string
    {
        $baseDir = trim($baseDir);
        if ($baseDir === '') {
            $baseDir = 'playlist';
        }

        for ($i = 1; $i < 999999; $i++) {
            $dir = $i === 1 ? $baseDir : $baseDir . $i;
            $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table('playlists')} WHERE dir = :dir");
            $stmt->execute(['dir' => $dir]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $dir;
            }
        }

        throw new \RuntimeException('Unable to generate unique playlist directory');
    }

    private function slugifyDirectory(string $title): string
    {
        $dir = preg_replace('/[^a-z0-9]+/', '-', strtolower($title));

        return trim((string) $dir, '-');
    }

    private function relaxSqlMode(\PDO $db): bool
    {
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return false;
        }

        $db->exec("SET @kvs_cli_old_sql_mode = @@sql_mode, sql_mode = ''");
        return true;
    }

    private function restoreSqlMode(\PDO $db, bool $restore): void
    {
        if (!$restore) {
            return;
        }

        try {
            $db->exec('SET sql_mode = @kvs_cli_old_sql_mode');
        } catch (\Exception) {
        }
    }

    private function videoExists(\PDO $db, int $videoId): bool
    {
        $stmt = $db->prepare("SELECT 1 FROM {$this->table('videos')} WHERE video_id = :id");
        $stmt->execute(['id' => $videoId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Recompute counters after a fav_videos change for a playlist.
     * Mirrors fav_videos_changed() in kvs/admin/include/functions.php:652
     * but scoped to the changed video and owner.
     */
    private function recountAfterFavChange(\PDO $db, int $videoId, int $ownerUserId): void
    {
        // 1) playlists.total_videos for all playlists owned by this user
        $stmt = $db->prepare(
            "UPDATE {$this->table('playlists')}
             SET total_videos = (
                 SELECT COUNT(*) FROM {$this->table('fav_videos')} f
                 WHERE f.playlist_id = {$this->table('playlists')}.playlist_id
             )
             WHERE user_id = :user_id"
        );
        $stmt->execute(['user_id' => $ownerUserId]);

        // 2) videos.favourites_count for this video (counts every fav_type incl. 10)
        $stmt = $db->prepare(
            "UPDATE {$this->table('videos')}
             SET favourites_count = (
                 SELECT COUNT(*) FROM {$this->table('fav_videos')} f
                 WHERE f.video_id = {$this->table('videos')}.video_id
             )
             WHERE video_id = :id"
        );
        $stmt->execute(['id' => $videoId]);

        // 3) users.favourite_videos_count for the playlist owner
        $stmt = $db->prepare(
            "UPDATE {$this->table('users')}
             SET favourite_videos_count = (
                 SELECT COUNT(*) FROM {$this->table('fav_videos')} f
                 WHERE f.user_id = {$this->table('users')}.user_id
             )
             WHERE user_id = :id"
        );
        $stmt->execute(['id' => $ownerUserId]);
    }

    private function addVideoToPlaylist(?string $id, ?string $videoIdInput): int
    {
        $playlistId = $this->parsePositivePlaylistIdOption($id, 'Playlist', 'Playlist ID is required');
        if ($playlistId === null) {
            return self::FAILURE;
        }

        $videoId = $this->parsePositivePlaylistIdOption($videoIdInput, 'Video', 'Video ID is required (use --video=<id>)');
        if ($videoId === null) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $playlist = $this->fetchPlaylistOwnerAndLock($db, $playlistId);
            if ($playlist === null) {
                $this->io()->error("Playlist not found: $playlistId");
                return self::FAILURE;
            }
            if ($playlist['is_locked'] === 1) {
                $this->io()->error("Playlist #$playlistId is locked");
                return self::FAILURE;
            }

            if (!$this->videoExists($db, $videoId)) {
                $this->io()->error("Video not found: $videoId");
                return self::FAILURE;
            }

            $ownerUserId = $playlist['user_id'];

            $db->beginTransaction();

            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM {$this->table('fav_videos')}
                 WHERE user_id = :user_id AND video_id = :video_id
                   AND fav_type = :fav_type AND playlist_id = :playlist_id"
            );
            $stmt->execute([
                'user_id' => $ownerUserId,
                'video_id' => $videoId,
                'fav_type' => Constants::FAV_TYPE_PLAYLIST,
                'playlist_id' => $playlistId,
            ]);
            $alreadyExists = ((int) $stmt->fetchColumn()) > 0;

            if ($alreadyExists) {
                $this->recountAfterFavChange($db, $videoId, $ownerUserId);
                $db->commit();
                $this->io()->note("Video #$videoId is already in playlist #$playlistId");
                return self::SUCCESS;
            }

            $stmt = $db->prepare(
                "INSERT INTO {$this->table('fav_videos')}
                    (user_id, video_id, fav_type, playlist_id, playlist_sort_id, added_date)
                 VALUES (:user_id, :video_id, :fav_type, :playlist_id, :playlist_sort_id, :added_date)"
            );
            $stmt->execute([
                'user_id' => $ownerUserId,
                'video_id' => $videoId,
                'fav_type' => Constants::FAV_TYPE_PLAYLIST,
                'playlist_id' => $playlistId,
                'playlist_sort_id' => 0,
                'added_date' => date('Y-m-d H:i:s'),
            ]);

            $stmt = $db->prepare(
                "UPDATE {$this->table('playlists')}
                 SET last_content_date = :now
                 WHERE playlist_id = :id"
            );
            $stmt->execute(['now' => date('Y-m-d H:i:s'), 'id' => $playlistId]);

            $this->recountAfterFavChange($db, $videoId, $ownerUserId);

            $db->commit();
            $this->io()->success("Video #$videoId added to playlist #$playlistId");
            return self::SUCCESS;
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->io()->error('Failed to add video to playlist: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function removeVideoFromPlaylist(?string $id, ?string $videoIdInput): int
    {
        $playlistId = $this->parsePositivePlaylistIdOption($id, 'Playlist', 'Playlist ID is required');
        if ($playlistId === null) {
            return self::FAILURE;
        }

        $videoId = $this->parsePositivePlaylistIdOption($videoIdInput, 'Video', 'Video ID is required (use --video=<id>)');
        if ($videoId === null) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $playlist = $this->fetchPlaylistOwnerAndLock($db, $playlistId);
            if ($playlist === null) {
                $this->io()->error("Playlist not found: $playlistId");
                return self::FAILURE;
            }
            if ($playlist['is_locked'] === 1) {
                $this->io()->error("Playlist #$playlistId is locked");
                return self::FAILURE;
            }

            $ownerUserId = $playlist['user_id'];

            $relationParams = [
                'user_id' => $ownerUserId,
                'video_id' => $videoId,
                'fav_type' => Constants::FAV_TYPE_PLAYLIST,
                'playlist_id' => $playlistId,
            ];
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM {$this->table('fav_videos')}
                 WHERE user_id = :user_id AND video_id = :video_id
                   AND fav_type = :fav_type AND playlist_id = :playlist_id"
            );
            $stmt->execute($relationParams);
            $relationExists = ((int) $stmt->fetchColumn()) > 0;

            if (!$relationExists && !$this->videoExists($db, $videoId)) {
                $this->io()->error("Video not found: $videoId");
                return self::FAILURE;
            }

            $db->beginTransaction();

            $stmt = $db->prepare(
                "DELETE FROM {$this->table('fav_videos')}
                 WHERE user_id = :user_id AND video_id = :video_id
                   AND fav_type = :fav_type AND playlist_id = :playlist_id"
            );
            $stmt->execute($relationParams);
            $deleted = $stmt->rowCount();

            if ($deleted === 0) {
                $this->recountAfterFavChange($db, $videoId, $ownerUserId);
                $db->commit();
                $this->io()->note("Video #$videoId is not in playlist #$playlistId");
                return self::SUCCESS;
            }

            $this->recountAfterFavChange($db, $videoId, $ownerUserId);

            $db->commit();
            $this->io()->success("Video #$videoId removed from playlist #$playlistId");
            return self::SUCCESS;
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->io()->error('Failed to remove video from playlist: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function parsePositivePlaylistIdOption(?string $value, string $label, string $missingMessage): ?int
    {
        if ($value === null || $value === '') {
            $this->io()->error($missingMessage);
            return null;
        }

        if (preg_match('/^[1-9]\d*$/', $value) !== 1) {
            $this->io()->error(sprintf('Invalid %s ID (use: integer >= 1)', $label));
            return null;
        }

        return (int) $value;
    }

    private function deletePlaylist(?string $id, InputInterface $input): int
    {
        if ($id === null || $id === '') {
            $this->io()->error('Playlist ID is required');
            return self::FAILURE;
        }
        if (!ctype_digit($id)) {
            $this->io()->error('Playlist ID must be numeric');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        // Check if playlist exists first
        try {
            $stmt = $db->prepare("SELECT playlist_id, title, is_locked FROM {$this->table('playlists')} WHERE playlist_id = :id");
            $stmt->execute(['id' => $id]);
            /** @var array{playlist_id: int|string, title: string, is_locked: int|string}|false $playlist */
            $playlist = $stmt->fetch();

            if ($playlist === false) {
                $this->io()->error("Playlist not found: $id");
                return self::FAILURE;
            }

            $playlistId = (int) $playlist['playlist_id'];
            $isLocked = is_numeric($playlist['is_locked']) ? (int) $playlist['is_locked'] : 0;
            if ($isLocked === 1) {
                $this->io()->error("Playlist #$id is locked and cannot be deleted");
                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch playlist: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->io()->warning("This will delete playlist #$id using KVS native cleanup");
        $this->io()->text("Title: " . $playlist['title']);

        if ($this->io()->confirm('Do you want to continue?', false) !== true) {
            if (!$input->isInteractive()) {
                $this->io()->error('Playlist deletion cancelled because confirmation was not provided.');
                return self::FAILURE;
            }

            $this->io()->warning('Playlist deletion cancelled');
            return self::SUCCESS;
        }

        try {
            $this->deletePlaylistWithKvs($playlistId);
            $this->io()->success("Playlist #$id deleted with KVS cleanup");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to delete playlist: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    protected function deletePlaylistWithKvs(int $playlistId): void
    {
        $this->runWithKvsAdminContext(function () use ($playlistId): void {
            if (!function_exists('delete_playlists')) {
                throw new \RuntimeException('KVS delete_playlists function is not available');
            }

            delete_playlists([$playlistId], 'ap');
        });
    }
}
