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
  pending           List pending comments awaiting moderation
  show <id>         Show comment details
  approve <id>      Approve comment(s) - makes them visible on site
  reject <id>       Reject and delete comment(s)
  delete <id>       Delete comment (alias for reject)
  stats             Show comment statistics

<info>LIST OPTIONS:</info>
  --fields=<fields>     Comma-separated list of fields to display
  --format=<format>     Output format: table, csv, json, yaml, count
  --no-truncate         Disable truncation of long text fields

<info>MODERATION:</info>
  Multiple IDs can be provided comma-separated: approve 1,2,3
  Use --all with pending to approve/reject all pending comments

<info>NOTE:</info>
  Long text fields (comment, content_title) are truncated in table view.
  Use --no-truncate to show full content, or --format=json for exports.

<info>AVAILABLE FIELDS:</info>
  id, user, username, type, content, content_title, comment, date, added_date

<info>EXAMPLES:</info>
  <comment>kvs comment list</comment>
  <comment>kvs comment pending</comment>
  <comment>kvs comment approve 123</comment>
  <comment>kvs comment approve 1,2,3,4</comment>
  <comment>kvs comment approve --all</comment>
  <comment>kvs comment reject 456</comment>
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
            ->addArgument(
                'action',
                InputArgument::OPTIONAL,
                'Action: list|pending|show|approve|reject|delete|stats',
                'list'
            )
            ->addArgument('id', InputArgument::OPTIONAL, 'Comment ID(s) - comma-separated for batch')
            ->addOption('video', null, InputOption::VALUE_REQUIRED, 'Filter by video ID')
            ->addOption('album', null, InputOption::VALUE_REQUIRED, 'Filter by album ID')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Filter by user ID')
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of results to show',
                Constants::DEFAULT_COMMENT_LIMIT
            )
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search in comment text')
            ->addOption('oldest', null, InputOption::VALUE_NONE, 'Show oldest first (default: recent)')
            ->addOption('approved', null, InputOption::VALUE_NONE, 'Show only approved comments')
            ->addOption('pending', null, InputOption::VALUE_NONE, 'Show only pending comments')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated fields to display')
            ->addOption('field', null, InputOption::VALUE_REQUIRED, 'Display single field from each item')
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Output format: table, csv, json, yaml, count, ids',
                'table'
            )
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable text truncation')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Apply to all pending comments');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $this->getStringArgument($input, 'action');
        $id = $this->getStringArgument($input, 'id');

        return match ($action) {
            'list' => $this->listComments($input),
            'pending' => $this->listPendingComments($input),
            'show' => $this->showComment($id),
            'approve' => $this->approveComments($input, $id),
            'reject', 'delete' => $this->rejectComments($input, $id),
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

    private function showStats(): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            // Overall stats
            $videoType = Constants::OBJECT_TYPE_VIDEO;
            $albumType = Constants::OBJECT_TYPE_ALBUM;
            $stmt = $db->query("
                SELECT
                    COUNT(*) as total_comments,
                    COUNT(DISTINCT user_id) as unique_users,
                    SUM(CASE WHEN object_type_id = $videoType THEN 1 ELSE 0 END) as video_comments,
                    SUM(CASE WHEN object_type_id = $albumType THEN 1 ELSE 0 END) as album_comments,
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

    private function listPendingComments(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $conditions = ['c.is_review_needed = 1'];
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

            $whereClause = implode(' AND ', $conditions);
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

            if ($comments === []) {
                $this->io()->success('No pending comments awaiting moderation');
                return self::SUCCESS;
            }

            $this->io()->title('Pending Comments (' . count($comments) . ' awaiting moderation)');

            // Transform comments
            $transformedComments = array_map(function (array $comment): array {
                return [
                    'comment_id' => $comment['comment_id'] ?? 0,
                    'username' => $comment['username'] ?? $comment['anonymous_username'] ?? 'Guest',
                    'object_type' => $comment['object_type'] ?? '',
                    'object_title' => $comment['object_title'] ?? '',
                    'comment' => $comment['comment'] ?? '',
                    'added_date' => $comment['added_date'] ?? '',
                ];
            }, $comments);

            $formatter = new Formatter(
                $input->getOptions(),
                ['comment_id', 'username', 'object_type', 'object_title', 'comment', 'added_date']
            );
            $formatter->display($transformedComments, $this->io());

            $this->io()->newLine();
            $this->io()->text(
                '<info>Tip:</info> Use <comment>kvs comment approve ID</comment> ' .
                'or <comment>kvs comment reject ID</comment> to moderate'
            );

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch pending comments: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function approveComments(InputInterface $input, ?string $id): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $commentIds = $this->resolveCommentIds($db, $input, $id);
            if ($commentIds === []) {
                return self::FAILURE;
            }

            // Get comment details for confirmation
            $placeholders = implode(',', array_fill(0, count($commentIds), '?'));
            $stmt = $db->prepare("
                SELECT c.comment_id, c.object_id, c.object_type_id, c.user_id, c.comment,
                       u.username,
                       CASE c.object_type_id
                           WHEN 1 THEN 'Video'
                           WHEN 2 THEN 'Album'
                           ELSE 'Other'
                       END as object_type
                FROM {$this->table('comments')} c
                LEFT JOIN {$this->table('users')} u ON c.user_id = u.user_id
                WHERE c.comment_id IN ($placeholders) AND c.is_approved = 0
            ");
            $stmt->execute($commentIds);

            /** @var list<array<string, mixed>> $comments */
            $comments = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if ($comments === []) {
                $this->io()->warning('No pending comments found with the specified ID(s)');
                return self::SUCCESS;
            }

            $this->io()->title('Comments to Approve (' . count($comments) . ')');

            /** @var list<list<string>> $rows */
            $rows = [];
            foreach ($comments as $comment) {
                $commentText = is_string($comment['comment']) ? $comment['comment'] : '';
                $commentIdVal = $comment['comment_id'] ?? '';
                $usernameVal = $comment['username'] ?? 'Guest';
                $objectTypeVal = $comment['object_type'] ?? '';
                $rows[] = [
                    is_scalar($commentIdVal) ? (string) $commentIdVal : '',
                    is_scalar($usernameVal) ? (string) $usernameVal : 'Guest',
                    is_scalar($objectTypeVal) ? (string) $objectTypeVal : '',
                    truncate($commentText, Constants::COMMENT_TRUNCATE_LENGTH),
                ];
            }
            $this->renderTable(['ID', 'User', 'Type', 'Comment'], $rows);

            if ($this->io()->confirm('Approve ' . count($comments) . ' comment(s)?', false) !== true) {
                $this->io()->info('Operation cancelled');
                return self::SUCCESS;
            }

            // Use transaction to ensure atomic update
            $db->beginTransaction();

            // Approve the comments
            $stmt = $db->prepare("
                UPDATE {$this->table('comments')}
                SET is_approved = 1, is_review_needed = 0
                WHERE comment_id IN ($placeholders)
            ");
            $stmt->execute($commentIds);

            // Update comment counts on parent objects
            $this->updateCommentCounts($db, $comments);

            $db->commit();

            $this->io()->success(count($comments) . ' comment(s) approved successfully!');
            return self::SUCCESS;
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->io()->error('Failed to approve comments: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function rejectComments(InputInterface $input, ?string $id): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $commentIds = $this->resolveCommentIds($db, $input, $id);
            if ($commentIds === []) {
                return self::FAILURE;
            }

            // Get comment details for confirmation
            $placeholders = implode(',', array_fill(0, count($commentIds), '?'));
            $stmt = $db->prepare("
                SELECT c.comment_id, c.object_id, c.object_type_id, c.user_id, c.comment,
                       u.username,
                       CASE c.object_type_id
                           WHEN 1 THEN 'Video'
                           WHEN 2 THEN 'Album'
                           ELSE 'Other'
                       END as object_type
                FROM {$this->table('comments')} c
                LEFT JOIN {$this->table('users')} u ON c.user_id = u.user_id
                WHERE c.comment_id IN ($placeholders)
            ");
            $stmt->execute($commentIds);

            /** @var list<array<string, mixed>> $comments */
            $comments = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if ($comments === []) {
                $this->io()->warning('No comments found with the specified ID(s)');
                return self::SUCCESS;
            }

            $this->io()->title('Comments to Reject/Delete (' . count($comments) . ')');

            /** @var list<list<string>> $rows */
            $rows = [];
            foreach ($comments as $comment) {
                $commentText = is_string($comment['comment']) ? $comment['comment'] : '';
                $commentIdVal = $comment['comment_id'] ?? '';
                $usernameVal = $comment['username'] ?? 'Guest';
                $objectTypeVal = $comment['object_type'] ?? '';
                $rows[] = [
                    is_scalar($commentIdVal) ? (string) $commentIdVal : '',
                    is_scalar($usernameVal) ? (string) $usernameVal : 'Guest',
                    is_scalar($objectTypeVal) ? (string) $objectTypeVal : '',
                    truncate($commentText, Constants::COMMENT_TRUNCATE_LENGTH),
                ];
            }
            $this->renderTable(['ID', 'User', 'Type', 'Comment'], $rows);

            if ($this->io()->confirm('Reject and DELETE ' . count($comments) . ' comment(s)?', false) !== true) {
                $this->io()->info('Operation cancelled');
                return self::SUCCESS;
            }

            // Use transaction to ensure atomic delete
            $db->beginTransaction();

            // Delete user events associated with these comments
            try {
                $stmt = $db->prepare("DELETE FROM {$this->table('users')}_events WHERE comment_id IN ($placeholders)");
                $stmt->execute($commentIds);
            } catch (\PDOException) {
                // Table may not exist, ignore
            }

            // Delete the comments
            $stmt = $db->prepare("DELETE FROM {$this->table('comments')} WHERE comment_id IN ($placeholders)");
            $stmt->execute($commentIds);

            // Update comment counts on parent objects and users
            $this->updateCommentCounts($db, $comments);

            $db->commit();

            $this->io()->success(count($comments) . ' comment(s) rejected and deleted!');
            return self::SUCCESS;
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->io()->error('Failed to reject comments: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Resolve comment IDs from input (single ID, comma-separated, or --all flag).
     *
     * @return list<int>
     */
    private function resolveCommentIds(\PDO $db, InputInterface $input, ?string $id): array
    {
        // If --all flag is set, get all pending comment IDs
        if ($this->getBoolOption($input, 'all')) {
            $stmt = $db->query("SELECT comment_id FROM {$this->table('comments')} WHERE is_review_needed = 1");
            if ($stmt === false) {
                $this->io()->error('Failed to fetch pending comments');
                return [];
            }
            $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            if ($ids === []) {
                $this->io()->success('No pending comments to process');
                return [];
            }
            $result = [];
            foreach ($ids as $fetchedId) {
                if (is_numeric($fetchedId)) {
                    $result[] = (int) $fetchedId;
                }
            }
            return $result;
        }

        // Otherwise, parse the ID argument
        if ($id === null || $id === '') {
            $this->io()->error(
                'Comment ID required. Use comma-separated IDs for batch, or --all for pending.'
            );
            $this->io()->text('Usage: kvs comment approve ID or approve 1,2,3 or approve --all');
            return [];
        }

        // Parse comma-separated IDs
        $parts = explode(',', $id);
        $intIds = [];
        foreach ($parts as $singleId) {
            $trimmed = trim($singleId);
            if ($trimmed === '') {
                continue;
            }
            if (!is_numeric($trimmed)) {
                $this->io()->error("Invalid comment ID: $trimmed");
                return [];
            }
            $intIds[] = (int) $trimmed;
        }

        return $intIds;
    }

    /**
     * Update comment counts on parent objects and users.
     *
     * Updates counts on: videos, albums, content_sources, models, dvds, posts, playlists
     * Also updates user-specific comment counts.
     *
     * @param list<array<string, mixed>> $comments
     */
    private function updateCommentCounts(\PDO $db, array $comments): void
    {
        // Collect unique object IDs by type and user IDs
        $objectsByType = [];
        $userIds = [];

        foreach ($comments as $comment) {
            $objectTypeIdRaw = $comment['object_type_id'] ?? 0;
            $objectIdRaw = $comment['object_id'] ?? 0;
            $userIdRaw = $comment['user_id'] ?? 0;

            $objectTypeId = is_numeric($objectTypeIdRaw) ? (int) $objectTypeIdRaw : 0;
            $objectId = is_numeric($objectIdRaw) ? (int) $objectIdRaw : 0;
            $userId = is_numeric($userIdRaw) ? (int) $userIdRaw : 0;

            if ($objectId > 0) {
                $objectsByType[$objectTypeId][$objectId] = true;
            }
            if ($userId > 0) {
                $userIds[$userId] = true;
            }
        }

        // Object type to table mapping
        $tableMap = [
            Constants::OBJECT_TYPE_VIDEO => ['table' => 'videos', 'id_col' => 'video_id'],
            Constants::OBJECT_TYPE_ALBUM => ['table' => 'albums', 'id_col' => 'album_id'],
            Constants::OBJECT_TYPE_CONTENT_SOURCE => ['table' => 'content_sources', 'id_col' => 'content_source_id'],
            Constants::OBJECT_TYPE_MODEL => ['table' => 'models', 'id_col' => 'model_id'],
            Constants::OBJECT_TYPE_DVD => ['table' => 'dvds', 'id_col' => 'dvd_id'],
            Constants::OBJECT_TYPE_POST => ['table' => 'posts', 'id_col' => 'post_id'],
            Constants::OBJECT_TYPE_PLAYLIST => ['table' => 'playlists', 'id_col' => 'playlist_id'],
        ];

        // Update comment counts on parent objects
        foreach ($objectsByType as $typeId => $objectIds) {
            if (!isset($tableMap[$typeId])) {
                continue;
            }

            $config = $tableMap[$typeId];
            $tableName = $this->table($config['table']);
            $idColumn = $config['id_col'];
            $commentsTable = $this->table('comments');

            foreach (array_keys($objectIds) as $objectId) {
                try {
                    $db->prepare("
                        UPDATE {$tableName}
                        SET comments_count = (
                            SELECT COUNT(*) FROM {$commentsTable}
                            WHERE object_id = ? AND object_type_id = ? AND is_approved = 1
                        )
                        WHERE {$idColumn} = ?
                    ")->execute([$objectId, $typeId, $objectId]);
                } catch (\PDOException) {
                    // Column may not exist in some KVS versions, ignore
                }
            }
        }

        // Update user comment counts
        if ($userIds !== []) {
            $this->updateUserCommentCounts($db, array_keys($userIds));
        }
    }

    /**
     * Update user-specific comment counts.
     *
     * @param list<int> $userIds
     */
    private function updateUserCommentCounts(\PDO $db, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $commentsTable = $this->table('comments');
        $usersTable = $this->table('users');

        foreach ($userIds as $userId) {
            try {
                $db->prepare("
                    UPDATE {$usersTable} SET
                        comments_videos_count = (
                            SELECT COUNT(*) FROM {$commentsTable}
                            WHERE user_id = ? AND is_approved = 1 AND object_type_id = ?
                        ),
                        comments_albums_count = (
                            SELECT COUNT(*) FROM {$commentsTable}
                            WHERE user_id = ? AND is_approved = 1 AND object_type_id = ?
                        ),
                        comments_cs_count = (
                            SELECT COUNT(*) FROM {$commentsTable}
                            WHERE user_id = ? AND is_approved = 1 AND object_type_id = ?
                        ),
                        comments_models_count = (
                            SELECT COUNT(*) FROM {$commentsTable}
                            WHERE user_id = ? AND is_approved = 1 AND object_type_id = ?
                        ),
                        comments_dvds_count = (
                            SELECT COUNT(*) FROM {$commentsTable}
                            WHERE user_id = ? AND is_approved = 1 AND object_type_id = ?
                        ),
                        comments_posts_count = (
                            SELECT COUNT(*) FROM {$commentsTable}
                            WHERE user_id = ? AND is_approved = 1 AND object_type_id = ?
                        ),
                        comments_playlists_count = (
                            SELECT COUNT(*) FROM {$commentsTable}
                            WHERE user_id = ? AND is_approved = 1 AND object_type_id = ?
                        ),
                        comments_total_count = (
                            SELECT COUNT(*) FROM {$commentsTable}
                            WHERE user_id = ? AND is_approved = 1
                        )
                    WHERE user_id = ?
                ")->execute([
                    $userId, Constants::OBJECT_TYPE_VIDEO,
                    $userId, Constants::OBJECT_TYPE_ALBUM,
                    $userId, Constants::OBJECT_TYPE_CONTENT_SOURCE,
                    $userId, Constants::OBJECT_TYPE_MODEL,
                    $userId, Constants::OBJECT_TYPE_DVD,
                    $userId, Constants::OBJECT_TYPE_POST,
                    $userId, Constants::OBJECT_TYPE_PLAYLIST,
                    $userId,
                    $userId,
                ]);
            } catch (\PDOException) {
                // Columns may not exist in some KVS versions, ignore
            }
        }
    }
}
