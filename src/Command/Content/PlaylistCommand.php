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
    name: 'content:playlist',
    description: 'Manage KVS playlists',
    aliases: ['playlist', 'playlists']
)]
class PlaylistCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'Action to perform (list|show|delete)')
            ->addArgument('id', InputArgument::OPTIONAL, 'Playlist ID')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status (active|disabled)')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Filter by user ID')
            ->addOption('public', null, InputOption::VALUE_NONE, 'Show only public playlists')
            ->addOption('private', null, InputOption::VALUE_NONE, 'Show only private playlists')
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search in titles and descriptions')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results', Constants::DEFAULT_CONTENT_LIMIT)
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to display')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count', 'table')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation of long text fields')
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
  <fg=green>kvs playlist list --private --user=5</>
  <fg=green>kvs playlist list --status=active --limit=50</>
  <fg=green>kvs playlist list --search="favorites"</>
  <fg=green>kvs playlist list --format=json</>
  <fg=green>kvs playlist list --format=count</>
  <fg=green>kvs playlist show 1</>
  <fg=green>kvs playlist delete 1</>

<fg=yellow>NOTE:</>
  Long text fields (title, description) are truncated in table view.
  Use --no-truncate to show full content, or --format=json for exports.
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $this->getStringArgument($input, 'action');

        return match ($action) {
            'list' => $this->listPlaylists($input),
            'show' => $this->showPlaylist($this->getStringArgument($input, 'id')),
            'delete' => $this->deletePlaylist($this->getStringArgument($input, 'id')),
            default => $this->showHelp(),
        };
    }

    private function listPlaylists(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $query = "SELECT p.*, u.username
                 FROM {$this->table('playlists')} p
                 LEFT JOIN {$this->table('users')} u ON p.user_id = u.user_id
                 WHERE 1=1";

        $params = [];

        // Status filter
        $status = $this->getIntOption($input, 'status');
        if ($status !== null) {
            $query .= " AND p.status_id = :status";
            $params['status'] = $status;
        }

        // User filter
        $user = $this->getIntOption($input, 'user');
        if ($user !== null) {
            $query .= " AND p.user_id = :user";
            $params['user'] = $user;
        }

        // Public/Private filter
        if ($input->getOption('public')) {
            $query .= " AND p.is_private = 0";
        } elseif ($input->getOption('private')) {
            $query .= " AND p.is_private = 1";
        }

        // Search filter
        $search = $input->getOption('search');
        if (is_string($search) && $search !== '') {
            $query .= " AND (p.title LIKE :search OR p.description LIKE :search)";
            $params['search'] = '%' . $search . '%';
        }

        $query .= " ORDER BY p.added_date DESC LIMIT :limit";

        try {
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $limit = $this->getIntOptionOrDefault($input, 'limit', Constants::DEFAULT_CONTENT_LIMIT);
            $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            /** @var list<array<string, mixed>> $playlists */
            $playlists = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Transform playlists for display
            $transformedPlaylists = array_map(function (array $playlist): array {
                // Calculate rating (rating / rating_amount gives 0-5 scale)
                $ratingAmountVal = $playlist['rating_amount'] ?? 0;
                $ratingAmount = is_numeric($ratingAmountVal) ? (int) $ratingAmountVal : 0;
                $ratingVal = $playlist['rating'] ?? 0;
                $rating = is_numeric($ratingVal) ? (float) $ratingVal : 0.0;
                $calculatedRating = $ratingAmount > 0
                    ? round($rating / $ratingAmount, 1)
                    : 0;

                $statusIdVal = $playlist['status_id'] ?? 0;
                $statusId = is_numeric($statusIdVal) ? (int) $statusIdVal : 0;

                $isPrivateVal = $playlist['is_private'] ?? 0;
                $isPrivate = is_numeric($isPrivateVal) ? (int) $isPrivateVal : 0;

                return [
                    'playlist_id' => $playlist['playlist_id'] ?? 0,
                    'id' => $playlist['playlist_id'] ?? 0,  // Alias
                    'title' => $playlist['title'] ?? '',
                    'status_id' => $statusId,
                    'status' => StatusFormatter::playlist($statusId, false),  // Alias
                    'is_private' => $isPrivate,
                    'type' => $isPrivate !== 0 ? 'Private' : 'Public',  // Alias
                    'total_videos' => $playlist['total_videos'] ?? 0,
                    'videos' => $playlist['total_videos'] ?? 0,  // Alias
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
                ['playlist_id', 'title', 'status_id', 'is_private', 'total_videos', 'username', 'added_date']
            );
            $formatter->display($transformedPlaylists, $this->io());

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch playlists: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function showPlaylist(?string $id): int
    {
        if ($id === null || $id === '') {
            $this->io()->error('Playlist ID is required');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            // Fetch main playlist data with username
            $stmt = $db->prepare("
                SELECT p.*, u.username
                FROM {$this->table('playlists')} p
                LEFT JOIN {$this->table('users')} u ON p.user_id = u.user_id
                WHERE p.playlist_id = :id
            ");
            $stmt->execute(['id' => $id]);
            /** @var array<string, mixed>|false $playlist */
            $playlist = $stmt->fetch();

            if ($playlist === false) {
                $this->io()->error("Playlist not found: $id");
                return self::FAILURE;
            }

            $this->io()->section("Playlist #$id");

            // Type calculation
            $isPrivate = isset($playlist['is_private']) && is_numeric($playlist['is_private'])
                ? (int) $playlist['is_private']
                : 0;
            $type = $isPrivate !== 0 ? 'Private' : 'Public';

            // Rating calculation
            $ratingAmount = isset($playlist['rating_amount']) && is_numeric($playlist['rating_amount'])
                ? (int) $playlist['rating_amount']
                : 0;
            $rating = isset($playlist['rating']) && is_numeric($playlist['rating'])
                ? (float) $playlist['rating']
                : 0.0;
            $ratingDisplay = $ratingAmount > 0
                ? sprintf('%.1f/%d (%d votes)', $rating / $ratingAmount, Constants::RATING_SCALE, $ratingAmount)
                : 'No ratings yet';

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
            $totalVideosVal = $playlist['total_videos'] ?? 0;
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

            $this->renderTable(['Property', 'Value'], $info);

            // Description section
            $description = isset($playlist['description']) && is_string($playlist['description']) ? $playlist['description'] : '';
            if ($description !== '') {
                $this->io()->newLine();
                $this->io()->section('Description');
                $this->io()->text($description);
            }

            // Videos in playlist (top 10)
            $stmt = $db->prepare("
                SELECT v.video_id, v.title
                FROM {$this->table('videos')} v
                INNER JOIN {$this->table('fav_videos')} f ON v.video_id = f.video_id
                WHERE f.playlist_id = :id AND f.fav_type = 10
                ORDER BY f.playlist_sort_id ASC
                LIMIT 10
            ");
            $stmt->execute(['id' => $id]);
            /** @var list<array{video_id: int, title: string}> $videos */
            $videos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (count($videos) > 0) {
                $this->io()->newLine();
                $this->io()->section('Videos (Top 10)');
                $videoList = [];
                foreach ($videos as $video) {
                    $videoList[] = sprintf('#%d: %s', $video['video_id'], $video['title']);
                }
                $this->io()->listing($videoList);
            }

            // Categories
            $stmt = $db->prepare("
                SELECT c.title
                FROM {$this->table('categories')} c
                INNER JOIN {$this->table('categories')}_playlists cp ON c.category_id = cp.category_id
                WHERE cp.playlist_id = :id
            ");
            $stmt->execute(['id' => $id]);
            /** @var list<array{title: string}> $categories */
            $categories = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (count($categories) > 0) {
                $this->io()->newLine();
                $this->io()->section('Categories');
                $categoryTitles = array_map(fn($c) => $c['title'], $categories);
                $this->io()->text(implode(', ', $categoryTitles));
            }

            // Tags
            $stmt = $db->prepare("
                SELECT t.tag
                FROM {$this->table('tags')} t
                INNER JOIN {$this->table('tags')}_playlists tp ON t.tag_id = tp.tag_id
                WHERE tp.playlist_id = :id
            ");
            $stmt->execute(['id' => $id]);
            /** @var list<array{tag: string}> $tags */
            $tags = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (count($tags) > 0) {
                $this->io()->newLine();
                $this->io()->section('Tags');
                $tagNames = array_map(fn($t) => $t['tag'], $tags);
                $this->io()->text(implode(', ', $tagNames));
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch playlist: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function deletePlaylist(?string $id): int
    {
        if ($id === null || $id === '') {
            $this->io()->error('Playlist ID is required');
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
            /** @var array{playlist_id: int, title: string, is_locked: int}|false $playlist */
            $playlist = $stmt->fetch();

            if ($playlist === false) {
                $this->io()->error("Playlist not found: $id");
                return self::FAILURE;
            }

            // Check if locked
            if ($playlist['is_locked'] === 1) {
                $this->io()->error("Playlist #$id is locked and cannot be deleted");
                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch playlist: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->io()->warning("This will permanently delete playlist #$id and all associated data");
        $this->io()->text("Title: " . $playlist['title']);

        if ($this->io()->confirm('Do you want to continue?', false) !== true) {
            return self::SUCCESS;
        }

        try {
            $db->beginTransaction();

            // Delete from related tables (order matters for foreign key constraints)
            // Core tables with playlist_id column
            $coreTables = [
                ['table' => $this->table('fav_videos'), 'column' => 'playlist_id'],
                ['table' => $this->table('categories') . '_playlists', 'column' => 'playlist_id'],
                ['table' => $this->table('tags') . '_playlists', 'column' => 'playlist_id'],
            ];

            foreach ($coreTables as $tableInfo) {
                $stmt = $db->prepare("DELETE FROM {$tableInfo['table']} WHERE {$tableInfo['column']} = :id");
                $stmt->execute(['id' => $id]);
            }

            // Comments (object_type_id = 13 for playlists)
            $stmt = $db->prepare("DELETE FROM {$this->table('comments')} WHERE object_id = :id AND object_type_id = 13");
            $stmt->execute(['id' => $id]);

            // Subscriptions (subscribed_object_type_id = 13 for playlists)
            $subscriptionsTable = $this->table('users') . '_subscriptions';
            $stmt = $db->prepare(
                "DELETE FROM {$subscriptionsTable} WHERE subscribed_object_id = :id AND subscribed_object_type_id = 13"
            );
            $stmt->execute(['id' => $id]);

            // Optional tables - gracefully handle if they don't exist
            $optionalTables = [
                ['table' => $this->table('flags') . '_playlists', 'column' => 'playlist_id'],
                ['table' => $this->table('flags') . '_history', 'column' => 'playlist_id'],
                ['table' => $this->table('flags') . '_messages', 'column' => 'playlist_id'],
                ['table' => $this->table('users') . '_events', 'column' => 'playlist_id'],
                ['table' => $this->table('rating') . '_history', 'column' => 'playlist_id'],
            ];

            foreach ($optionalTables as $tableInfo) {
                try {
                    $stmt = $db->prepare("DELETE FROM {$tableInfo['table']} WHERE {$tableInfo['column']} = :id");
                    $stmt->execute(['id' => $id]);
                } catch (\PDOException) {
                    // Table may not exist, ignore
                }
            }

            // Delete main playlist record (LAST)
            $stmt = $db->prepare("DELETE FROM {$this->table('playlists')} WHERE playlist_id = :id");
            $stmt->execute(['id' => $id]);

            $db->commit();
            $this->io()->success("Playlist #$id deleted successfully");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $db->rollBack();
            $this->io()->error('Failed to delete playlist: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function showHelp(): int
    {
        $this->io()->info('Available actions:');
        $this->io()->listing([
            'list : List playlists',
            'show <id> : Show playlist details',
            'delete <id> : Delete a playlist',
        ]);

        return self::SUCCESS;
    }
}
