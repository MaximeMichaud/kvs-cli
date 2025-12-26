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

#[AsCommand(
    name: 'content:user',
    description: 'Manage KVS users',
    aliases: ['user', 'users', 'member', 'members']
)]
class UserCommand extends BaseCommand
{
    // Available fields mapping
    private const FIELD_MAP = [
        'id' => 'user_id',
        'user_id' => 'user_id',
        'username' => 'username',
        'display_name' => 'display_name',
        'email' => 'email',
        'status' => 'status_id',
        'status_id' => 'status_id',
        'gender' => 'gender_id',
        'gender_id' => 'gender_id',
        'country' => 'country_code',
        'country_code' => 'country_code',
        'tokens' => 'tokens_available',
        'tokens_available' => 'tokens_available',
        'tokens_required' => 'tokens_required',
        'profile_viewed' => 'profile_viewed',
        'ip' => 'ip',
        'birth_date' => 'birth_date',
        'added_date' => 'added_date',
        'joined' => 'added_date',
        'last_login' => 'last_login_date',
        'last_login_date' => 'last_login_date',
        'videos' => 'video_count',
        'video_count' => 'video_count',
        'albums' => 'album_count',
        'album_count' => 'album_count',
        'is_trusted' => 'is_trusted',
        'trusted' => 'is_trusted',
        'is_removal_requested' => 'is_removal_requested',
        'removal_requested' => 'is_removal_requested',
        'removal_reason' => 'removal_reason',
        'reason' => 'removal_reason',
        'activity' => 'activity',
        'logins' => 'logins_count',
        'logins_count' => 'logins_count',
    ];

    protected function configure(): void
    {
        $this
            ->setHelp(<<<'HELP'
Manage KVS users.

<info>ACTIONS:</info>
  list              List users (default)
  show <id>         Show user details
  create            Create new user (interactive)
  delete <id>       Delete user
  stats             Show user statistics

<info>LIST OPTIONS:</info>
  --fields=<fields>     Comma-separated list of fields to display
  --field=<field>       Display a single field value for each user
  --format=<format>     Output format: table, csv, json, yaml, count, ids

<info>NOTE:</info>
  Long text fields (email, removal_reason) are truncated in table view.
  Use --no-truncate to show full content, or --format=json for exports.

<info>AVAILABLE FIELDS:</info>
  id, username, display_name, email, status
  tokens_available, tokens_required, profile_viewed
  country_code, gender, birth_date, ip
  added_date, last_login_date, logins_count, activity
  videos, albums, is_trusted, is_removal_requested, removal_reason

<info>EXAMPLES:</info>
  <comment>kvs user list</comment>
  <comment>kvs user list --fields=id,username,email,tokens</comment>
  <comment>kvs user list --field=username</comment>
  <comment>kvs user list --format=csv</comment>
  <comment>kvs user list --status=premium --format=json</comment>
  <comment>kvs user list --removal-requested</comment>
  <comment>kvs user list --removal-requested --no-truncate</comment>
  <comment>kvs user list --removal-requested --format=csv</comment>
  <comment>kvs user list --removal-requested --fields=id,username,email,removal_reason</comment>
  <comment>kvs user list --removal-requested --field=removal_reason</comment>
  <comment>kvs user list --trusted</comment>
  <comment>kvs user list --format=count</comment>
  <comment>kvs user list --format=ids</comment>
HELP
            )
            ->addArgument('action', InputArgument::OPTIONAL, 'Action to perform (list|show|create|delete|stats)', 'list')
            ->addArgument('id', InputArgument::OPTIONAL, 'User ID or username')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status (active|disabled|premium)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results to show', Constants::DEFAULT_CONTENT_LIMIT)
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search in usernames and emails')
            ->addOption('removal-requested', null, InputOption::VALUE_NONE, 'Filter users who requested account deletion')
            ->addOption('trusted', null, InputOption::VALUE_NONE, 'Filter trusted users only')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to display')
            ->addOption('field', null, InputOption::VALUE_REQUIRED, 'Display single field value')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation of long text fields in table view')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count, ids', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');

        return match ($action) {
            'list' => $this->listUsers($input),
            'show' => $this->showUser($input->getArgument('id')),
            'create' => $this->createUser($input),
            'delete' => $this->deleteUser($input->getArgument('id')),
            'stats' => $this->showStats(),
            default => $this->listUsers($input),
        };
    }

    private function listUsers(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        // Build query
        $query = "SELECT u.*,
                 (SELECT COUNT(*) FROM {$this->table('videos')} WHERE user_id = u.user_id) as video_count,
                 (SELECT COUNT(*) FROM {$this->table('albums')} WHERE user_id = u.user_id) as album_count
                 FROM {$this->table('users')} u
                 WHERE 1=1";

        $params = [];

        // Status filter
        $status = $input->getOption('status');
        if ($status !== null) {
            $statusMap = [
                'active' => 2,
                'disabled' => 0,
                'premium' => 3,
            ];
            if (isset($statusMap[$status])) {
                $query .= " AND u.status_id = :status";
                $params['status'] = $statusMap[$status];
            }
        }

        // Search filter
        $search = $input->getOption('search');
        if ($search !== null) {
            $query .= " AND (u.username LIKE :search OR u.email LIKE :search)";
            $params['search'] = "%$search%";
        }

        // Removal requested filter
        if ($input->getOption('removal-requested') !== null) {
            $query .= " AND u.is_removal_requested = 1";
        }

        // Trusted users filter
        if ($input->getOption('trusted') !== null) {
            $query .= " AND u.is_trusted = 1";
        }

        $query .= " ORDER BY u.added_date DESC LIMIT :limit";

        try {
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue('limit', (int)$input->getOption('limit'), \PDO::PARAM_INT);
            $stmt->execute();

            $users = $stmt->fetchAll();

            // Determine default fields based on filters
            // When filtering by removal-requested, include removal_reason
            if ($input->getOption('removal-requested') !== null) {
                $defaultFields = ['user_id', 'username', 'email', 'removal_reason', 'added_date'];
            } else {
                $defaultFields = ['user_id', 'username', 'display_name', 'email', 'status_id', 'added_date'];
            }

            // Format and display output using centralized Formatter
            $formatter = new Formatter($input->getOptions(), $defaultFields);
            /** @var list<array<string, mixed>> $users */
            $formatter->display($users, $this->io);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io->error('Failed to fetch users: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    private function getFieldValue(array $user, string $field, bool $formatted = true, bool $noTruncate = false): string
    {
        // Map field name to database column
        $dbField = self::FIELD_MAP[$field] ?? $field;

        $value = $user[$dbField] ?? '';

        // Special formatting for certain fields
        if ($field === 'status' || $field === 'status_id') {
            return StatusFormatter::user((int)$value, $formatted);
        }

        if ($formatted) {
            if ($field === 'email' && !$noTruncate) {
                return truncate($value, 25);
            }

            if (in_array($field, ['added_date', 'joined', 'last_login', 'last_login_date'], true)) {
                return ($value !== '' && $value !== null && $value !== 0) ? date('Y-m-d', strtotime($value)) : 'Never';
            }

            if ($field === 'gender' || $field === 'gender_id') {
                return match ((int)$value) {
                    1 => 'Male',
                    2 => 'Female',
                    default => 'Other'
                };
            }

            if (in_array($field, ['is_trusted', 'trusted'], true)) {
                return (int)$value === 1 ? '<fg=green>Yes</>' : '<fg=gray>No</>';
            }

            if (in_array($field, ['is_removal_requested', 'removal_requested'], true)) {
                return (int)$value === 1 ? '<fg=yellow>Yes</>' : '<fg=gray>No</>';
            }

            if (in_array($field, ['removal_reason', 'reason'], true)) {
                // Truncate long reasons in table (~60-80 chars for readability)
                // Unless --no-truncate is specified
                if ($value === '') {
                    return '<fg=gray>N/A</>';
                }
                if (!$noTruncate) {
                    return truncate($value, 80);
                }
                return $value;
            }

            if (in_array($field, ['logins', 'logins_count', 'activity', 'profile_viewed'], true)) {
                return number_format((int)$value);
            }
        } else {
            // Raw values for CSV/JSON
            if ($field === 'gender' || $field === 'gender_id') {
                return match ((int)$value) {
                    1 => 'Male',
                    2 => 'Female',
                    default => 'Other'
                };
            }

            if (in_array($field, ['is_trusted', 'trusted', 'is_removal_requested', 'removal_requested'], true)) {
                return (int)$value === 1 ? 'Yes' : 'No';
            }

            if (in_array($field, ['removal_reason', 'reason'], true)) {
                // Full reason for CSV/JSON export
                return $value !== '' ? $value : 'N/A';
            }
        }

        return (string)$value;
    }

    private function escapeYAML(mixed $value): string
    {
        if (is_numeric($value)) {
            return (string)$value;
        }
        $strValue = (string)$value;
        if (str_contains($strValue, ':') || str_contains($strValue, '#')) {
            return '"' . str_replace('"', '\\"', $strValue) . '"';
        }
        return $strValue;
    }

    private function showUser(?string $id): int
    {
        if ($id === null || $id === '') {
            $this->io->error('User ID or username is required');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $query = "SELECT * FROM {$this->table('users')} WHERE user_id = :id OR username = :id";
            $stmt = $db->prepare($query);
            $stmt->execute(['id' => $id]);
            $user = $stmt->fetch();

            if ($user === false) {
                $this->io->error("User not found: $id");
                return self::FAILURE;
            }

            $this->io->section("User: {$user['username']}");

            $info = [
                ['User ID', (string)$user['user_id']],
                ['Username', (string)$user['username']],
                ['Email', (string)$user['email']],
                ['Status', StatusFormatter::user((int)$user['status_id'])],
                ['Display Name', ($user['display_name'] !== '' && $user['display_name'] !== null) ? (string)$user['display_name'] : 'N/A'],
                ['Country', ($user['country_code'] !== '' && $user['country_code'] !== null) ? (string)$user['country_code'] : 'N/A'],
                ['Gender', (int)$user['gender_id'] === 1 ? 'Male' : ((int)$user['gender_id'] === 2 ? 'Female' : 'Other')],
                ['Birth Date', ($user['birth_date'] !== '' && $user['birth_date'] !== null) ? (string)$user['birth_date'] : 'N/A'],
                ['Joined', date('Y-m-d H:i:s', strtotime((string)$user['added_date']))],
                ['Last Login', ($user['last_login_date'] !== '' && $user['last_login_date'] !== null)
                    ? date('Y-m-d H:i:s', strtotime((string)$user['last_login_date']))
                    : 'Never'],
                ['IP', ($user['ip'] !== '' && $user['ip'] !== null) ? (string)$user['ip'] : 'N/A'],
            ];

            $this->renderTable(['Property', 'Value'], $info);

            $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table('videos')} WHERE user_id = :id");
            $stmt->execute(['id' => $user['user_id']]);
            $videoCount = $stmt->fetchColumn();

            $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table('albums')} WHERE user_id = :id");
            $stmt->execute(['id' => $user['user_id']]);
            $albumCount = $stmt->fetchColumn();

            $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table('comments')} WHERE user_id = :id");
            $stmt->execute(['id' => $user['user_id']]);
            $commentCount = $stmt->fetchColumn();

            $this->io->section('Content Statistics');
            $stats = [
                ['Videos Uploaded', (string)$videoCount],
                ['Albums Created', (string)$albumCount],
                ['Comments Posted', (string)$commentCount],
                ['Profile Views', number_format((int)($user['profile_viewed'] ?? 0))],
            ];
            $this->renderTable(['Metric', 'Count'], $stats);

            $this->io->section('Activity');
            $activityStats = [
                ['Logins Count', number_format((int)($user['logins_count'] ?? 0))],
                ['Activity Score', number_format((int)($user['activity'] ?? 0))],
            ];
            $this->renderTable(['Metric', 'Value'], $activityStats);

            $hasTokens = ($user['tokens_available'] !== 0 && $user['tokens_available'] !== null)
                || ($user['tokens_required'] !== 0 && $user['tokens_required'] !== null);
            if ($hasTokens) {
                $this->io->section('Token Information');
                $tokens = [
                    ['Available Tokens', (string)($user['tokens_available'] ?? 0)],
                    ['Required Tokens', (string)($user['tokens_required'] ?? 0)],
                ];
                $this->renderTable(['Type', 'Amount'], $tokens);
            }
        } catch (\Exception $e) {
            $this->io->error('Failed to fetch user: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function createUser(InputInterface $input): int
    {
        $this->io->section('Create New User');

        $username = $this->io->ask('Username');
        $email = $this->io->ask('Email');
        $password = $this->io->askHidden('Password');
        $displayName = $this->io->ask('Display Name (optional)');

        if ($username === null || $username === '' || $email === null || $email === '' || $password === null || $password === '') {
            $this->io->error('Username, email, and password are required');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table('users')} WHERE username = :username OR email = :email");
            $stmt->execute(['username' => $username, 'email' => $email]);

            if ($stmt->fetchColumn() > 0) {
                $this->io->error('Username or email already exists');
                return self::FAILURE;
            }

            $stmt = $db->prepare("
                INSERT INTO {$this->table('users')} (username, email, pass, display_name, status_id, added_date, ip)
                VALUES (:username, :email, :pass, :display_name, 2, NOW(), :ip)
            ");

            $stmt->execute([
                'username' => $username,
                'email' => $email,
                'pass' => md5($password),
                'display_name' => ($displayName !== '' && $displayName !== null) ? $displayName : $username,
                'ip' => '127.0.0.1',
            ]);

            $userId = $db->lastInsertId();
            $this->io->success("User created successfully with ID: $userId");
        } catch (\Exception $e) {
            $this->io->error('Failed to create user: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function deleteUser(?string $id): int
    {
        if ($id === null || $id === '') {
            $this->io->error('User ID or username is required');
            return self::FAILURE;
        }

        $this->io->warning("This will permanently delete user: $id");
        $this->io->warning("All associated content will also be deleted");

        if ($this->io->confirm('Do you want to continue?', false) !== true) {
            return self::SUCCESS;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("SELECT user_id FROM {$this->table('users')} WHERE user_id = :id OR username = :id");
            $stmt->execute(['id' => $id]);
            $userId = $stmt->fetchColumn();

            if ($userId === false || $userId === null || $userId === '' || $userId === 0) {
                $this->io->error("User not found: $id");
                return self::FAILURE;
            }

            $db->beginTransaction();

            $tables = [
                $this->table('users'),
                $this->table('videos'),
                $this->table('albums'),
                $this->table('comments'),
            ];

            foreach ($tables as $table) {
                $stmt = $db->prepare("DELETE FROM $table WHERE user_id = :id");
                $stmt->execute(['id' => $userId]);
            }

            $db->commit();
            $this->io->success("User and all associated content deleted successfully");
        } catch (\Exception $e) {
            $db->rollBack();
            $this->io->error('Failed to delete user: ' . $e->getMessage());
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
            $stats = [];

            $queries = [
                'Total Users' => "SELECT COUNT(*) FROM {$this->table('users')}",
                'Active Users' => "SELECT COUNT(*) FROM {$this->table('users')} WHERE status_id = " . StatusFormatter::USER_ACTIVE,
                'Premium Users' => "SELECT COUNT(*) FROM {$this->table('users')} WHERE status_id = " . StatusFormatter::USER_PREMIUM,
                'Disabled Users' => "SELECT COUNT(*) FROM {$this->table('users')} WHERE status_id = " . StatusFormatter::USER_DISABLED,
                'Users Today' => "SELECT COUNT(*) FROM {$this->table('users')} WHERE DATE(added_date) = CURDATE()",
                'Users This Month' => "SELECT COUNT(*) FROM {$this->table('users')} "
                    . "WHERE MONTH(added_date) = MONTH(NOW()) AND YEAR(added_date) = YEAR(NOW())",
            ];

            foreach ($queries as $label => $query) {
                $result = $db->query($query);
                if ($result === false) {
                    throw new \RuntimeException("Failed to execute query: $label");
                }
                $value = $result->fetchColumn();
                $stats[] = [$label, number_format((int)$value)];
            }

            $this->renderTable(['Metric', 'Count'], $stats);

            $stmt = $db->query("
                SELECT u.username, u.added_date,
                       (SELECT COUNT(*) FROM {$this->table('videos')} WHERE user_id = u.user_id) as videos,
                       (SELECT COUNT(*) FROM {$this->table('albums')} WHERE user_id = u.user_id) as albums
                FROM {$this->table('users')} u
                WHERE u.status_id = " . StatusFormatter::USER_ACTIVE . "
                ORDER BY u.added_date DESC
                LIMIT " . Constants::TOP_QUERY_LIMIT . "
            ");
            if ($stmt === false) {
                throw new \RuntimeException("Failed to fetch recent users");
            }
            $recentUsers = $stmt->fetchAll();

            if ($recentUsers !== []) {
                $this->io->section(Constants::TOP_QUERY_LIMIT . ' Most Recent Users');
                $rows = [];
                foreach ($recentUsers as $user) {
                    $rows[] = [
                        (string)$user['username'],
                        (string)$user['videos'],
                        (string)$user['albums'],
                        date('Y-m-d', strtotime((string)$user['added_date'])),
                    ];
                }
                $this->renderTable(['Username', 'Videos', 'Albums', 'Joined'], $rows);
            }
        } catch (\Exception $e) {
            $this->io->error('Failed to fetch statistics: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
