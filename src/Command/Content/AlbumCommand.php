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
    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'Action to perform (list|show|delete)')
            ->addArgument('id', InputArgument::OPTIONAL, 'Album ID')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status (active|disabled)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results', Constants::DEFAULT_CONTENT_LIMIT)
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Filter by user ID')
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search in album titles')
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
  is_private      Access level (Public/Private/Premium)
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
        $action = $this->getStringArgument($input, 'action');

        if ($action === null || $action === '') {
            return $this->showHelp();
        }

        return match ($action) {
            'list' => $this->listAlbums($input),
            'show' => $this->showAlbum($this->getStringArgument($input, 'id')),
            'delete' => $this->deleteAlbum($this->getStringArgument($input, 'id'), $input),
            default => $this->failUnknownAction('album', $action, ['list', 'show', 'delete']),
        };
    }

    private function listAlbums(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $fromClause = "FROM {$this->table('albums')} a
                 LEFT JOIN {$this->table('users')} u ON a.user_id = u.user_id";
        $whereClause = 'WHERE 1=1';

        $params = [];

        $status = $this->getStringOption($input, 'status');
        if ($status !== null) {
            $statusId = $this->parseStatusFilter($input, [
                'active' => StatusFormatter::ALBUM_ACTIVE,
                'disabled' => StatusFormatter::ALBUM_DISABLED,
                'inactive' => StatusFormatter::ALBUM_DISABLED,
            ], [0, 1, 2, 3, 4, 5]);
            if ($statusId !== null) {
                $whereClause .= " AND a.status_id = :status";
                $params['status'] = $statusId;
            }
        }

        $user = $this->getIntOption($input, 'user');
        if ($user !== null) {
            $whereClause .= " AND a.user_id = :user";
            $params['user'] = $user;
        }

        $search = $this->getStringOption($input, 'search');
        if ($search !== null) {
            $whereClause .= " AND a.title LIKE :search";
            $params['search'] = '%' . $search . '%';
        }

        try {
            if ($this->getStringOption($input, 'format') === 'count') {
                $stmt = $db->prepare("SELECT COUNT(*) {$fromClause} {$whereClause}");
                foreach ($params as $key => $value) {
                    $stmt->bindValue($key, $value);
                }
                $stmt->execute();

                $total = $stmt->fetchColumn();
                $this->io()->writeln((string) (is_numeric($total) ? (int) $total : 0));

                return self::SUCCESS;
            }

            $query = "SELECT a.*, u.username,
                 a.photos_amount as image_count
                 {$fromClause}
                 {$whereClause}
                 ORDER BY a.post_date DESC LIMIT :limit";

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

                return [
                    'album_id' => $album['album_id'] ?? 0,
                    'id' => $album['album_id'] ?? 0,  // Alias
                    'title' => $album['title'] ?? '',
                    'image_count' => $album['image_count'] ?? 0,
                    'images' => $album['image_count'] ?? 0,  // Alias
                    'status_id' => $statusId,
                    'status' => StatusFormatter::album($statusId, false),  // Alias
                    'is_private' => $privacy,
                    'access' => $privacy,
                    'username' => $album['username'] ?? '',
                    'post_date' => $album['post_date'] ?? '',
                    'album_viewed' => $album['album_viewed'] ?? 0,
                    'views' => $album['album_viewed'] ?? 0,  // Alias
                    'rating' => $calculatedRating,
                ];
            }, $albums);

            // Format and display output using centralized Formatter
            $formatter = new Formatter(
                $input->getOptions(),
                ['album_id', 'title', 'image_count', 'status', 'is_private', 'username', 'post_date']
            );
            $formatter->display($transformedAlbums, $this->io());

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch albums: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function showAlbum(?string $id): int
    {
        if ($id === null || $id === '') {
            $this->io()->error('Album ID is required');
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
            $stmt->execute(['id' => $id]);
            /** @var array<string, mixed>|false $album */
            $album = $stmt->fetch();

            if ($album === false) {
                $this->io()->error("Album not found: $id");
                return self::FAILURE;
            }

            $this->io()->section("Album #$id");

            $title = isset($album['title']) && is_string($album['title']) ? $album['title'] : '';
            $statusIdVal = $album['status_id'] ?? 0;
            $statusId = is_numeric($statusIdVal) ? (int) $statusIdVal : 0;
            $postDate = isset($album['post_date']) && is_string($album['post_date']) ? $album['post_date'] : '';
            $postTimestamp = strtotime($postDate);
            $privacyIdVal = $album['is_private'] ?? 0;
            $privacyId = is_numeric($privacyIdVal) ? (int) $privacyIdVal : 0;
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
                ['Access', StatusFormatter::contentPrivacy($privacyId)],
                ['User', $username],
                ['Images', $imageCountValue],
                ['Posted', $postTimestamp !== false ? date('Y-m-d H:i:s', $postTimestamp) : 'Unknown'],
                ['Views', number_format($views)],
                [
                    'Rating',
                    format_kvs_rating($album['rating'] ?? 0, $album['rating_amount'] ?? 0)
                ],
            ];

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

    private function showHelp(): int
    {
        $this->io()->info('Available actions:');
        $this->io()->listing([
            'list : List albums',
            'show <id> : Show album details',
            'delete <id> : Delete an album',
        ]);

        return self::SUCCESS;
    }
}
