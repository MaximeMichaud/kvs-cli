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
  <comment>kvs comment list --approved</comment>
  <comment>kvs comment list --pending</comment>
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
            ->addOption('approved', null, InputOption::VALUE_NONE, 'Show only approved comments')
            ->addOption('pending', null, InputOption::VALUE_NONE, 'Show only pending (unapproved) comments')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to display')
            ->addOption('field', null, InputOption::VALUE_REQUIRED, 'Display single field from each item')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count, ids', 'table')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation of long text fields');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $this->getStringArgument($input, 'action');
        $id = $this->getStringArgument($input, 'id');

        return match ($action) {
            'list' => $this->listComments($input),
            'show' => $this->showComment($id),
            'delete' => $this->deleteComment($id),
            'stats' => $this->showStats(),
            default => $this->listComments($input),
        };
    }

    private function listComments(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $conditions = ['1=1'];
            $params = [];

            // Video filter
            $videoId = $this->getStringOption($input, 'video');
            if ($videoId !== null) {
                $conditions[] = 'c.object_id = :video_id AND c.object_type_id = ' . Constants::OBJECT_TYPE_VIDEO;
                $params['video_id'] = $videoId;
            }

            // Album filter
            $albumId = $this->getStringOption($input, 'album');
            if ($albumId !== null) {
                $conditions[] = 'c.object_id = :album_id AND c.object_type_id = ' . Constants::OBJECT_TYPE_ALBUM;
                $params['album_id'] = $albumId;
            }

            // User filter
            $userId = $this->getStringOption($input, 'user');
            if ($userId !== null) {
                $conditions[] = 'c.user_id = :user_id';
                $params['user_id'] = $userId;
            }

            // Search filter
            $search = $this->getStringOption($input, 'search');
            if ($search !== null) {
                $conditions[] = 'c.comment LIKE :search';
                $params['search'] = '%' . $search . '%';
            }

            // Approval status filters
            if ($this->getBoolOption($input, 'approved')) {
                $conditions[] = 'c.is_approved = 1';
            } elseif ($this->getBoolOption($input, 'pending')) {
                $conditions[] = 'c.is_approved = 0';
            }

            $whereClause = implode(' AND ', $conditions);
            // Default: most recent first (DESC), unless --oldest is specified
            $orderBy = $this->getBoolOption($input, 'oldest') ? 'c.added_date ASC' : 'c.added_date DESC';
            $limit = $this->getIntOptionOrDefault($input, 'limit', Constants::DEFAULT_COMMENT_LIMIT);

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

            /** @var list<array<string, mixed>> $comments */
            $comments = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Transform comments to use standardized field names
            $transformedComments = array_map(function (array $comment): array {
                return [
                    'comment_id' => $comment['comment_id'] ?? 0,
                    'username' => $comment['username'] ?? '',
                    'object_type' => $comment['object_type'] ?? '',
                    'object_title' => $comment['object_title'] ?? '',
                    'comment' => $comment['comment'] ?? '',
                    'added_date' => $comment['added_date'] ?? '',
                ];
            }, $comments);

            // Format and display output using centralized Formatter
            $formatter = new Formatter(
                $input->getOptions(),
                ['comment_id', 'username', 'object_type', 'object_title', 'comment', 'added_date']
            );
            $formatter->display($transformedComments, $this->io());

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch comments: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function showComment(?string $id): int
    {
        if ($id === null || $id === '') {
            $this->io()->error('Comment ID is required');
            $this->io()->text('Usage: kvs content:comment show <comment_id>');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
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
            $comment = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($comment)) {
                $this->io()->error("Comment not found: $id");
                return self::FAILURE;
            }

            $this->io()->title("Comment #$id");

            $approvalStatus = (bool)($comment['is_approved'] ?? 0)
                ? '<fg=green>Approved</>'
                : '<fg=yellow>Pending</>';

            // Safe type extraction
            $commentIdVal = $comment['comment_id'] ?? 0;
            $usernameVal = $comment['username'] ?? 'Guest';
            $emailVal = $comment['email'] ?? 'N/A';
            $objectTypeVal = $comment['object_type'] ?? '';
            $objectIdVal = $comment['object_id'] ?? 0;
            $objectTitleVal = $comment['object_title'] ?? 'N/A';
            $addedDateVal = $comment['added_date'] ?? '';
            $commentTextVal = $comment['comment'] ?? '';

            $info = [
                ['ID', is_scalar($commentIdVal) ? (string) $commentIdVal : '0'],
                ['User', is_scalar($usernameVal) ? (string) $usernameVal : 'Guest'],
                ['User Email', is_scalar($emailVal) ? (string) $emailVal : 'N/A'],
                ['Status', $approvalStatus],
                ['Content Type', is_scalar($objectTypeVal) ? (string) $objectTypeVal : ''],
                ['Content ID', is_scalar($objectIdVal) ? (string) $objectIdVal : '0'],
                ['Content Title', is_scalar($objectTitleVal) ? (string) $objectTitleVal : 'N/A'],
                ['Posted', is_scalar($addedDateVal) ? (string) $addedDateVal : ''],
            ];

            $this->renderTable(['Property', 'Value'], $info);

            $this->io()->section('Comment Text');
            $this->io()->text(is_scalar($commentTextVal) ? (string) $commentTextVal : '');
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch comment: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function deleteComment(?string $id): int
    {
        if ($id === null || $id === '') {
            $this->io()->error('Comment ID is required');
            $this->io()->text('Usage: kvs content:comment delete <comment_id>');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
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
            $comment = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($comment)) {
                $this->io()->error("Comment not found: $id");
                return self::FAILURE;
            }

            $usernameRaw = $comment['username'] ?? null;
            $username = 'Guest';
            if ($usernameRaw !== null) {
                $username = is_string($usernameRaw) ? $usernameRaw : (is_scalar($usernameRaw) ? (string) $usernameRaw : 'Guest');
            }

            $commentTextRaw = $comment['comment'] ?? null;
            $commentText = '';
            if ($commentTextRaw !== null) {
                $commentText = is_string($commentTextRaw) ? $commentTextRaw : (is_scalar($commentTextRaw) ? (string) $commentTextRaw : '');
            }

            $commentIdVal = $comment['comment_id'] ?? 0;
            $objectTypeVal = $comment['object_type'] ?? '';

            $this->io()->section("Comment to Delete");
            $this->renderTable(
                ['Property', 'Value'],
                [
                    ['ID', is_scalar($commentIdVal) ? (string) $commentIdVal : '0'],
                    ['User', $username],
                    ['Type', is_scalar($objectTypeVal) ? (string) $objectTypeVal : ''],
                    ['Comment', truncate($commentText, Constants::COMMENT_TRUNCATE_LENGTH)],
                ]
            );

            if ($this->io()->confirm('Delete this comment?', false) !== true) {
                $this->io()->info('Operation cancelled');
                return self::SUCCESS;
            }

            // Delete the comment
            $stmt = $db->prepare("DELETE FROM {$this->table('comments')} WHERE comment_id = :id");
            $stmt->execute(['id' => $id]);

            $this->io()->success('Comment deleted successfully!');
        } catch (\Exception $e) {
            $this->io()->error('Failed to delete comment: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function showStats(): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            // Overall stats
            $stmt = $db->query("
                SELECT
                    COUNT(*) as total_comments,
                    COUNT(DISTINCT user_id) as unique_users,
                    SUM(CASE WHEN object_type_id = " . Constants::OBJECT_TYPE_VIDEO . " THEN 1 ELSE 0 END) as video_comments,
                    SUM(CASE WHEN object_type_id = " . Constants::OBJECT_TYPE_ALBUM . " THEN 1 ELSE 0 END) as album_comments,
                    MIN(added_date) as first_comment,
                    MAX(added_date) as last_comment
                FROM {$this->table('comments')}
            ");
            if ($stmt === false) {
                $this->io()->error('Failed to fetch overall statistics');
                return self::FAILURE;
            }
            $overall = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($overall)) {
                $this->io()->error('Failed to fetch overall statistics');
                return self::FAILURE;
            }

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
            if ($stmt === false) {
                $this->io()->error('Failed to fetch top commenters');
                return self::FAILURE;
            }
            $topCommenters = $stmt->fetchAll();

            // Recent activity (last 7 days)
            $stmt = $db->query("
                SELECT COUNT(*) as recent_comments
                FROM {$this->table('comments')}
                WHERE added_date >= DATE_SUB(NOW(), INTERVAL " . Constants::RECENT_DAYS . " DAY)
            ");
            if ($stmt === false) {
                $this->io()->error('Failed to fetch recent activity statistics');
                return self::FAILURE;
            }
            $recentStats = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($recentStats)) {
                $this->io()->error('Failed to fetch recent activity statistics');
                return self::FAILURE;
            }

            $this->io()->title('Comment Statistics');

            $this->io()->section('Overall Statistics');
            $totalComments = is_numeric($overall['total_comments']) ? (int) $overall['total_comments'] : 0;
            $uniqueUsers = is_numeric($overall['unique_users']) ? (int) $overall['unique_users'] : 0;
            $videoComments = is_numeric($overall['video_comments']) ? (int) $overall['video_comments'] : 0;
            $albumComments = is_numeric($overall['album_comments']) ? (int) $overall['album_comments'] : 0;
            $recentComments = is_numeric($recentStats['recent_comments']) ? (int) $recentStats['recent_comments'] : 0;
            $firstComment = $overall['first_comment'] ?? null;
            $firstCommentStr = is_scalar($firstComment) ? (string) $firstComment : 'N/A';
            $lastComment = $overall['last_comment'] ?? null;
            $lastCommentStr = is_scalar($lastComment) ? (string) $lastComment : 'N/A';

            $this->renderTable(
                ['Metric', 'Value'],
                [
                    ['Total Comments', number_format($totalComments)],
                    ['Unique Commenters', number_format($uniqueUsers)],
                    ['Video Comments', number_format($videoComments)],
                    ['Album Comments', number_format($albumComments)],
                    ['Comments (Last 7 Days)', number_format($recentComments)],
                    ['First Comment', $firstCommentStr !== '' ? $firstCommentStr : 'N/A'],
                    ['Latest Comment', $lastCommentStr !== '' ? $lastCommentStr : 'N/A'],
                ]
            );

            if ($topCommenters !== []) {
                $this->io()->section('Top 10 Commenters');
                /** @var list<list<string>> $rows */
                $rows = [];
                foreach ($topCommenters as $commenter) {
                    if (!is_array($commenter)) {
                        continue;
                    }
                    $commenterUsername = $commenter['username'] ?? 'Unknown';
                    $commenterCount = $commenter['comment_count'] ?? 0;
                    $rows[] = [
                        is_scalar($commenterUsername) ? (string) $commenterUsername : 'Unknown',
                        is_numeric($commenterCount) ? number_format((float) $commenterCount) : '0',
                    ];
                }
                $this->renderTable(['User', 'Comments'], $rows);
            }
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch stats: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
