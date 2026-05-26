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
            'delete' => $this->deleteAlbum($this->getStringArgument($input, 'id')),
            default => $this->failUnknownAction('album', $action, ['list', 'show', 'delete']),
        };
    }

    private function listAlbums(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $query = "SELECT a.*, u.username,
                 (SELECT COUNT(*) FROM {$this->table('albums')}_images WHERE album_id = a.album_id) as image_count
                 FROM {$this->table('albums')} a
                 LEFT JOIN {$this->table('users')} u ON a.user_id = u.user_id
                 WHERE 1=1";

        $params = [];

        $status = $this->getStringOption($input, 'status');
        if ($status !== null) {
            $statusId = $this->parseStatusFilter($input, [
                'active' => StatusFormatter::ALBUM_ACTIVE,
                'disabled' => StatusFormatter::ALBUM_DISABLED,
                'inactive' => StatusFormatter::ALBUM_DISABLED,
            ], [0, 1, 2, 3, 4, 5]);
            if ($statusId !== null) {
                $query .= " AND a.status_id = :status";
                $params['status'] = $statusId;
            }
        }

        $user = $this->getIntOption($input, 'user');
        if ($user !== null) {
            $query .= " AND a.user_id = :user";
            $params['user'] = $user;
        }

        $search = $this->getStringOption($input, 'search');
        if ($search !== null) {
            $query .= " AND a.title LIKE :search";
            $params['search'] = '%' . $search . '%';
        }

        $query .= " ORDER BY a.post_date DESC LIMIT :limit";

        try {
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $limit = $this->getIntOptionOrDefault($input, 'limit', Constants::DEFAULT_CONTENT_LIMIT);
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
            $stmt = $db->prepare("SELECT * FROM {$this->table('albums')} WHERE album_id = :id");
            $stmt->execute(['id' => $id]);
            /** @var array{title: string, status_id: int, post_date: string, album_viewed: int, rating: int, rating_amount: int}|false $album */
            $album = $stmt->fetch();

            if ($album === false) {
                $this->io()->error("Album not found: $id");
                return self::FAILURE;
            }

            $this->io()->section("Album #$id");

            $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table('albums')}_images WHERE album_id = :id");
            $stmt->execute(['id' => $id]);
            $imageCount = $stmt->fetchColumn();
            $imageCountValue = is_numeric($imageCount) ? (int) $imageCount : 0;

            $postTimestamp = strtotime($album['post_date']);

            $info = [
                ['Title', $album['title']],
                ['Status', StatusFormatter::album($album['status_id'])],
                ['Images', $imageCountValue],
                ['Posted', $postTimestamp !== false ? date('Y-m-d H:i:s', $postTimestamp) : 'Unknown'],
                ['Views', number_format($album['album_viewed'])],
                [
                    'Rating',
                    format_kvs_rating($album['rating'], $album['rating_amount'])
                ],
            ];

            $this->renderTable(['Property', 'Value'], $info);
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch album: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function deleteAlbum(?string $id): int
    {
        if ($id === null || $id === '') {
            $this->io()->error('Album ID is required');
            return self::FAILURE;
        }
        if (!ctype_digit($id)) {
            $this->io()->error('Album ID must be numeric');
            return self::FAILURE;
        }

        $this->io()->warning("This will delete album #$id using KVS native cleanup");
        $this->io()->warning('Files, references and counters will be queued for KVS background deletion.');

        if ($this->io()->confirm('Do you want to continue?', false) !== true) {
            return self::SUCCESS;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("SELECT album_id FROM {$this->table('albums')} WHERE album_id = :id");
            $stmt->execute(['id' => $id]);
            $albumId = $stmt->fetchColumn();
            if (!is_numeric($albumId)) {
                $this->io()->error("Album not found: #$id");
                return self::FAILURE;
            }

            $this->deleteAlbumWithKvs((int) $albumId);
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
