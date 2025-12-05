<?php

namespace KVS\CLI\Command\Content;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Output\Formatter;
use KVS\CLI\Output\StatusFormatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function KVS\CLI\Utils\truncate;
use function KVS\CLI\Utils\pick_fields;

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
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results', 20)
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Filter by user ID')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to display')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count', 'table')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation of long text fields')
            ->setHelp(<<<'HELP'
Manage KVS photo albums.

<fg=yellow>AVAILABLE FIELDS:</>
  id, album_id    Album ID
  title           Album title
  images          Number of images
  status          Album status (Active/Disabled)
  user, username  Username
  date, post_date Posted date
  views           View count
  rating          Rating (out of 5)

<fg=yellow>EXAMPLES:</>
  <fg=green>kvs album list</>
  <fg=green>kvs album list --no-truncate</>
  <fg=green>kvs album list --fields=id,title,images,user</>
  <fg=green>kvs album list --format=csv</>
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
        $action = $input->getArgument('action') ?? 'list';

        return match ($action) {
            'list' => $this->listAlbums($input),
            'show' => $this->showAlbum($input->getArgument('id')),
            'delete' => $this->deleteAlbum($input->getArgument('id')),
            default => $this->showHelp(),
        };
    }

    private function listAlbums(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if (!$db) {
            return self::FAILURE;
        }

        $query = "SELECT a.*, u.username,
                 (SELECT COUNT(*) FROM ktvs_albums_images WHERE album_id = a.album_id) as image_count
                 FROM ktvs_albums a
                 LEFT JOIN ktvs_users u ON a.user_id = u.user_id
                 WHERE 1=1";

        $params = [];

        if ($status = $input->getOption('status')) {
            $query .= " AND a.status_id = :status";
            $params['status'] = $status;
        }

        if ($user = $input->getOption('user')) {
            $query .= " AND a.user_id = :user";
            $params['user'] = $user;
        }

        $query .= " ORDER BY a.post_date DESC LIMIT :limit";

        try {
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue('limit', (int)$input->getOption('limit'), \PDO::PARAM_INT);
            $stmt->execute();

            $albums = $stmt->fetchAll();

            // Transform albums to use friendly field names
            $transformedAlbums = array_map(function ($album) {
                return [
                    'album_id' => $album['album_id'],
                    'title' => $album['title'],
                    'image_count' => $album['image_count'],
                    'status_id' => $album['status_id'],
                    'username' => $album['username'],
                    'post_date' => $album['post_date'],
                    'album_viewed' => $album['album_viewed'],
                    'rating' => $album['rating'],
                ];
            }, $albums);

            // Format and display output using centralized Formatter
            $formatter = new Formatter(
                $input->getOptions(),
                ['album_id', 'title', 'image_count', 'status_id', 'username', 'post_date']
            );
            $formatter->display($transformedAlbums, $this->io);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io->error('Failed to fetch albums: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function showAlbum(?string $id): int
    {
        if (!$id) {
            $this->io->error('Album ID is required');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if (!$db) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("SELECT * FROM ktvs_albums WHERE album_id = :id");
            $stmt->execute(['id' => $id]);
            $album = $stmt->fetch();

            if (!$album) {
                $this->io->error("Album not found: $id");
                return self::FAILURE;
            }

            $this->io->section("Album #$id");

            $stmt = $db->prepare("SELECT COUNT(*) FROM ktvs_albums_images WHERE album_id = :id");
            $stmt->execute(['id' => $id]);
            $imageCount = $stmt->fetchColumn();

            $info = [
                ['Title', $album['title']],
                ['Status', StatusFormatter::album($album['status_id'])],
                ['Images', $imageCount],
                ['Posted', date('Y-m-d H:i:s', strtotime($album['post_date']))],
                ['Views', number_format($album['album_viewed'])],
                ['Rating', sprintf('%.1f/5', $album['rating'] / 20)],
            ];

            $this->io->table(['Property', 'Value'], $info);
        } catch (\Exception $e) {
            $this->io->error('Failed to fetch album: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function deleteAlbum(?string $id): int
    {
        if (!$id) {
            $this->io->error('Album ID is required');
            return self::FAILURE;
        }

        $this->io->warning("This will permanently delete album #$id");

        if (!$this->io->confirm('Do you want to continue?', false)) {
            return self::SUCCESS;
        }

        $db = $this->getDatabaseConnection();
        if (!$db) {
            return self::FAILURE;
        }

        try {
            $db->beginTransaction();

            $tables = [
                'ktvs_albums',
                'ktvs_albums_images',
                'ktvs_categories_albums',
                'ktvs_tags_albums',
            ];

            foreach ($tables as $table) {
                $stmt = $db->prepare("DELETE FROM $table WHERE album_id = :id");
                $stmt->execute(['id' => $id]);
            }

            $db->commit();
            $this->io->success("Album #$id deleted successfully");
        } catch (\Exception $e) {
            $db->rollBack();
            $this->io->error('Failed to delete album: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function showHelp(): int
    {
        $this->io->info('Available actions:');
        $this->io->listing([
            'list : List albums',
            'show <id> : Show album details',
            'delete <id> : Delete an album',
        ]);

        return self::SUCCESS;
    }
}
