<?php

namespace KVS\CLI\Command\Content;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Constants;
use KVS\CLI\Output\Formatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function KVS\CLI\Utils\truncate;

#[AsCommand(
    name: 'content:comment',
    description: 'Manage KVS comments',
    aliases: ['comment', 'comments']
)]
class CommentCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setHelp(<<<'HELP'
Manage KVS comments.

<info>ACTIONS:</info>
  list              List comments (default)
  show <id>         Show comment details
  delete <id>       Delete comment
  stats             Show comment statistics

<info>LIST OPTIONS:</info>
  --fields=<fields>     Comma-separated list of fields to display
  --format=<format>     Output format: table, csv, json, yaml, count
  --no-truncate         Disable truncation of long text fields

<info>NOTE:</info>
  Long text fields (comment, content_title) are truncated in table view.
  Use --no-truncate to show full content, or --format=json for exports.

<info>AVAILABLE FIELDS:</info>
  id, user, username, type, content, content_title, comment, date, added_date

<info>EXAMPLES:</info>
  <comment>kvs comment list</comment>
  <comment>kvs comment list --oldest</comment>
  <comment>kvs comment list --no-truncate</comment>
  <comment>kvs comment list --fields=id,username,comment,date</comment>
  <comment>kvs comment list --format=json</comment>
  <comment>kvs comment list --video=123</comment>
  <comment>kvs comment list --search="spam" --format=csv</comment>
HELP
            )
            ->addArgument('action', InputArgument::OPTIONAL, 'Action to perform (list|show|delete|stats)', 'list')
            ->addArgument('id', InputArgument::OPTIONAL, 'Comment ID')
            ->addOption('video', null, InputOption::VALUE_REQUIRED, 'Filter by video ID')
            ->addOption('album', null, InputOption::VALUE_REQUIRED, 'Filter by album ID')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Filter by user ID')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results to show', Constants::DEFAULT_COMMENT_LIMIT)
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search in comment text')
            ->addOption('oldest', null, InputOption::VALUE_NONE, 'Show oldest comments first (default: most recent first)')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to display')
            ->addOption('field', null, InputOption::VALUE_REQUIRED, 'Display single field from each item')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count, ids', 'table')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation of long text fields');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');

        return match ($action) {
            'list' => $this->listComments($input),
            'show' => $this->showComment($input->getArgument('id')),
            'delete' => $this->deleteComment($input->getArgument('id')),
            'stats' => $this->showStats(),
            default => $this->listComments($input),
        };
    }

    private function listComments(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if (!$db) {
            return self::FAILURE;
        }

        try {
            $conditions = ['1=1'];
            $params = [];

            // Video filter
            if ($videoId = $input->getOption('video')) {
                $conditions[] = 'c.object_id = :video_id AND c.object_type_id = ' . Constants::OBJECT_TYPE_VIDEO;
                $params['video_id'] = $videoId;
            }

            // Album filter
            if ($albumId = $input->getOption('album')) {
                $conditions[] = 'c.object_id = :album_id AND c.object_type_id = ' . Constants::OBJECT_TYPE_ALBUM;
                $params['album_id'] = $albumId;
            }

            // User filter
            if ($userId = $input->getOption('user')) {
                $conditions[] = 'c.user_id = :user_id';
                $params['user_id'] = $userId;
            }

            // Search filter
            if ($search = $input->getOption('search')) {
                $conditions[] = 'c.comment LIKE :search';
                $params['search'] = '%' . $search . '%';
            }

            $whereClause = implode(' AND ', $conditions);
            // Default: most recent first (DESC), unless --oldest is specified
            $orderBy = $input->getOption('oldest') ? 'c.added_date ASC' : 'c.added_date DESC';
            $limit = (int)$input->getOption('limit');

            $sql = "
                SELECT c.*,
                       u.username,
                       CASE c.object_type_id
                           WHEN 1 THEN (SELECT title FROM {$this->table('videos')} WHERE video_id = c.object_id)
                           WHEN 2 THEN (SELECT title FROM {$this->table('albums')} WHERE album_id = c.object_id)
                           ELSE 'Unknown'
                       END as object_title,
                       CASE c.object_type_id
                           WHEN 1 THEN 'Video'
                           WHEN 2 THEN 'Album'
                           ELSE 'Unknown'
                       END as object_type
                FROM {$this->table('comments')} c
                LEFT JOIN {$this->table('users')} u ON c.user_id = u.user_id
                WHERE $whereClause
                ORDER BY $orderBy
                LIMIT :limit
            ";

            $stmt = $db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            $comments = $stmt->fetchAll();

            // Transform comments to use standardized field names
            $transformedComments = array_map(function ($comment) {
                return [
                    'comment_id' => $comment['comment_id'],
                    'username' => $comment['username'],
                    'object_type' => $comment['object_type'],
                    'object_title' => $comment['object_title'],
                    'comment' => $comment['comment'],
                    'added_date' => $comment['added_date'],
                ];
            }, $comments);

            // Format and display output using centralized Formatter
            $formatter = new Formatter(
                $input->getOptions(),
                ['comment_id', 'username', 'object_type', 'object_title', 'comment', 'added_date']
            );
            $formatter->display($transformedComments, $this->io);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io->error('Failed to fetch comments: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function showComment(?string $id): int
    {
        if (!$id) {
            $this->io->error('Comment ID is required');
            $this->io->text('Usage: kvs content:comment show <comment_id>');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if (!$db) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("
                SELECT c.*,
                       u.username,
                       u.email,
                       CASE c.object_type_id
                           WHEN 1 THEN (SELECT title FROM {$this->table('videos')} WHERE video_id = c.object_id)
                           WHEN 2 THEN (SELECT title FROM {$this->table('albums')} WHERE album_id = c.object_id)
                           ELSE 'Unknown'
                       END as object_title,
                       CASE c.object_type_id
                           WHEN 1 THEN 'Video'
                           WHEN 2 THEN 'Album'
                           ELSE 'Unknown'
                       END as object_type
                FROM {$this->table('comments')} c
                LEFT JOIN {$this->table('users')} u ON c.user_id = u.user_id
                WHERE c.comment_id = :id
            ");
            $stmt->execute(['id' => $id]);
            $comment = $stmt->fetch();

            if (!$comment) {
                $this->io->error("Comment not found: $id");
                return self::FAILURE;
            }

            $this->io->title("Comment #$id");

            $info = [
                ['ID', $comment['comment_id']],
                ['User', $comment['username'] ?? 'Guest'],
                ['User Email', $comment['email'] ?? 'N/A'],
                ['Content Type', $comment['object_type']],
                ['Content ID', $comment['object_id']],
                ['Content Title', $comment['object_title'] ?? 'N/A'],
                ['Posted', $comment['added_date']],
            ];

            $this->renderTable(['Property', 'Value'], $info);

            $this->io->section('Comment Text');
            $this->io->text($comment['comment']);
        } catch (\Exception $e) {
            $this->io->error('Failed to fetch comment: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function deleteComment(?string $id): int
    {
        if (!$id) {
            $this->io->error('Comment ID is required');
            $this->io->text('Usage: kvs content:comment delete <comment_id>');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if (!$db) {
            return self::FAILURE;
        }

        try {
            // Get comment details first
            $stmt = $db->prepare("
                SELECT c.*,
                       u.username,
                       CASE c.object_type_id
                           WHEN 1 THEN 'Video'
                           WHEN 2 THEN 'Album'
                           ELSE 'Unknown'
                       END as object_type
                FROM {$this->table('comments')} c
                LEFT JOIN {$this->table('users')} u ON c.user_id = u.user_id
                WHERE c.comment_id = :id
            ");
            $stmt->execute(['id' => $id]);
            $comment = $stmt->fetch();

            if (!$comment) {
                $this->io->error("Comment not found: $id");
                return self::FAILURE;
            }

            $this->io->section("Comment to Delete");
            $this->renderTable(
                ['Property', 'Value'],
                [
                    ['ID', $comment['comment_id']],
                    ['User', $comment['username'] ?? 'Guest'],
                    ['Type', $comment['object_type']],
                    ['Comment', truncate($comment['comment'], 100)],
                ]
            );

            if (!$this->io->confirm('Delete this comment?', false)) {
                $this->io->info('Operation cancelled');
                return self::SUCCESS;
            }

            // Delete the comment
            $stmt = $db->prepare("DELETE FROM {$this->table('comments')} WHERE comment_id = :id");
            $stmt->execute(['id' => $id]);

            $this->io->success('Comment deleted successfully!');
        } catch (\Exception $e) {
            $this->io->error('Failed to delete comment: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function showStats(): int
    {
        $db = $this->getDatabaseConnection();
        if (!$db) {
            return self::FAILURE;
        }

        try {
            // Overall stats
            $stmt = $db->query("
                SELECT
                    COUNT(*) as total_comments,
                    COUNT(DISTINCT user_id) as unique_users,
                    SUM(CASE WHEN object_type_id = 1 THEN 1 ELSE 0 END) as video_comments,
                    SUM(CASE WHEN object_type_id = 2 THEN 1 ELSE 0 END) as album_comments,
                    MIN(added_date) as first_comment,
                    MAX(added_date) as last_comment
                FROM {$this->table('comments')}
            ");
            $overall = $stmt->fetch();

            // Top commenters
            $stmt = $db->query("
                SELECT u.username,
                       COUNT(*) as comment_count
                FROM {$this->table('comments')} c
                LEFT JOIN {$this->table('users')} u ON c.user_id = u.user_id
                WHERE c.user_id IS NOT NULL
                GROUP BY c.user_id
                ORDER BY comment_count DESC
                LIMIT " . Constants::TOP_QUERY_LIMIT . "
            ");
            $topCommenters = $stmt->fetchAll();

            // Recent activity (last 7 days)
            $stmt = $db->query("
                SELECT COUNT(*) as recent_comments
                FROM {$this->table('comments')}
                WHERE added_date >= DATE_SUB(NOW(), INTERVAL " . Constants::RECENT_DAYS . " DAY)
            ");
            $recentStats = $stmt->fetch();

            $this->io->title('Comment Statistics');

            $this->io->section('Overall Statistics');
            $this->renderTable(
                ['Metric', 'Value'],
                [
                    ['Total Comments', number_format($overall['total_comments'])],
                    ['Unique Commenters', number_format($overall['unique_users'])],
                    ['Video Comments', number_format($overall['video_comments'])],
                    ['Album Comments', number_format($overall['album_comments'])],
                    ['Comments (Last 7 Days)', number_format($recentStats['recent_comments'])],
                    ['First Comment', $overall['first_comment'] ?? 'N/A'],
                    ['Latest Comment', $overall['last_comment'] ?? 'N/A'],
                ]
            );

            if (!empty($topCommenters)) {
                $this->io->section('Top 10 Commenters');
                $rows = [];
                foreach ($topCommenters as $commenter) {
                    $rows[] = [
                        $commenter['username'] ?? 'Unknown',
                        number_format($commenter['comment_count']),
                    ];
                }
                $this->renderTable(['User', 'Comments'], $rows);
            }
        } catch (\Exception $e) {
            $this->io->error('Failed to fetch stats: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
