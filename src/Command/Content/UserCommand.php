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
  country_id, gender, birth_date, ip
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
        $action = $this->getStringArgument($input, 'action');

        return match ($action) {
            'list' => $this->listUsers($input),
            'show' => $this->showUser($this->getStringArgument($input, 'id')),
            'create' => $this->createUser($input),
            'delete' => $this->deleteUser($this->getStringArgument($input, 'id')),
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
        $status = $this->getStringOption($input, 'status');
        if ($status !== null) {
            $statusMap = [
                'active' => StatusFormatter::USER_ACTIVE,
                'disabled' => StatusFormatter::USER_DISABLED,
                'premium' => StatusFormatter::USER_PREMIUM,
            ];
            if (isset($statusMap[$status])) {
                $query .= " AND u.status_id = :status";
                $params['status'] = $statusMap[$status];
            }
        }

        // Search filter
        $search = $this->getStringOption($input, 'search');
        if ($search !== null) {
            $query .= " AND (u.username LIKE :search OR u.email LIKE :search)";
            $params['search'] = "%$search%";
        }

        // Removal requested filter
        if ($this->getBoolOption($input, 'removal-requested')) {
            $query .= " AND u.is_removal_requested = 1";
        }

        // Trusted users filter
        if ($this->getBoolOption($input, 'trusted')) {
            $query .= " AND u.is_trusted = 1";
        }

        $query .= " ORDER BY u.added_date DESC LIMIT :limit";

        try {
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $limit = $this->getIntOptionOrDefault($input, 'limit', Constants::DEFAULT_CONTENT_LIMIT);
            $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            $users = $stmt->fetchAll();

            // Determine default fields based on filters
            // When filtering by removal-requested, include removal_reason
            if ($this->getBoolOption($input, 'removal-requested')) {
                $defaultFields = ['user_id', 'username', 'email', 'removal_reason', 'added_date'];
            } else {
                $defaultFields = ['user_id', 'username', 'display_name', 'email', 'status_id', 'added_date'];
            }

            // Format and display output using centralized Formatter
            $formatter = new Formatter($input->getOptions(), $defaultFields);
            /** @var list<array<string, mixed>> $users */
            $formatter->display($users, $this->io());

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch users: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Get string value from mixed array value
     */
    private function getStr(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * Get int value from mixed array value
     */
    private function getInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Format date from string or return fallback
     */
    private function formatDate(string $date, string $format = 'Y-m-d H:i:s', string $fallback = 'Never'): string
    {
        if ($date === '') {
            return $fallback;
        }
        $timestamp = strtotime($date);
        return $timestamp !== false ? date($format, $timestamp) : $fallback;
    }

    /**
     * Format gender from ID
     */
    private function formatGender(int $genderId): string
    {
        return match ($genderId) {
            1 => 'Male',
            2 => 'Female',
            default => 'Other',
        };
    }

    private function showUser(?string $id): int
    {
        if ($id === null || $id === '') {
            $this->io()->error('User ID or username is required');
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
            /** @var array<string, mixed>|false $user */
            $user = $stmt->fetch();

            if ($user === false) {
                $this->io()->error("User not found: $id");
                return self::FAILURE;
            }

            $userId = $this->getInt($user['user_id'] ?? null);
            $username = $this->getStr($user['username'] ?? null);

            $this->io()->section("User: {$username}");

            $displayName = $this->getStr($user['display_name'] ?? null);
            $countryCode = $this->getStr($user['country_id'] ?? null);
            $birthDate = $this->getStr($user['birth_date'] ?? null);
            $ip = $this->getStr($user['ip'] ?? null);

            $info = [
                ['User ID', (string) $userId],
                ['Username', $username],
                ['Email', $this->getStr($user['email'] ?? null)],
                ['Status', StatusFormatter::user($this->getInt($user['status_id'] ?? null))],
                ['Display Name', $displayName !== '' ? $displayName : 'N/A'],
                ['Country', $countryCode !== '' ? $countryCode : 'N/A'],
                ['Gender', $this->formatGender($this->getInt($user['gender_id'] ?? null))],
                ['Birth Date', $birthDate !== '' ? $birthDate : 'N/A'],
                ['Joined', $this->formatDate($this->getStr($user['added_date'] ?? null), 'Y-m-d H:i:s', 'Unknown')],
                ['Last Login', $this->formatDate($this->getStr($user['last_login_date'] ?? null))],
                ['IP', $ip !== '' ? $ip : 'N/A'],
            ];

            $this->renderTable(['Property', 'Value'], $info);

            $this->displayUserContentStats($db, $userId, $this->getInt($user['profile_viewed'] ?? null));
            $this->displayUserActivity($user);
            $this->displayUserTokens($user);
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch user: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function displayUserContentStats(\PDO $db, int $userId, int $profileViewed): void
    {
        $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table('videos')} WHERE user_id = :id");
        $stmt->execute(['id' => $userId]);
        $videoCount = $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table('albums')} WHERE user_id = :id");
        $stmt->execute(['id' => $userId]);
        $albumCount = $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table('comments')} WHERE user_id = :id");
        $stmt->execute(['id' => $userId]);
        $commentCount = $stmt->fetchColumn();

        $this->io()->section('Content Statistics');
        $stats = [
            ['Videos Uploaded', (string) $videoCount],
            ['Albums Created', (string) $albumCount],
            ['Comments Posted', (string) $commentCount],
            ['Profile Views', number_format($profileViewed)],
        ];
        $this->renderTable(['Metric', 'Count'], $stats);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function displayUserActivity(array $user): void
    {
        $this->io()->section('Activity');
        $activityStats = [
            ['Logins Count', number_format($this->getInt($user['logins_count'] ?? null))],
            ['Activity Score', number_format($this->getInt($user['activity'] ?? null))],
        ];
        $this->renderTable(['Metric', 'Value'], $activityStats);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function displayUserTokens(array $user): void
    {
        $tokensAvailable = $this->getInt($user['tokens_available'] ?? null);
        $tokensRequired = $this->getInt($user['tokens_required'] ?? null);

        if ($tokensAvailable !== 0 || $tokensRequired !== 0) {
            $this->io()->section('Token Information');
            $tokens = [
                ['Available Tokens', (string) $tokensAvailable],
                ['Required Tokens', (string) $tokensRequired],
            ];
            $this->renderTable(['Type', 'Amount'], $tokens);
        }
    }

    private function createUser(InputInterface $input): int
    {
        $this->io()->section('Create New User');

        $username = $this->io()->ask('Username');
        $email = $this->io()->ask('Email');
        $password = $this->io()->askHidden('Password');
        $displayName = $this->io()->ask('Display Name (optional)');

        if ($username === null || $username === '' || $email === null || $email === '' || $password === null || $password === '') {
            $this->io()->error('Username, email, and password are required');
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
                $this->io()->error('Username or email already exists');
                return self::FAILURE;
            }

            // Relax sql_mode (KVS tables have many NOT NULL without DEFAULT)
            $db->exec("SET @old_sql_mode = @@sql_mode, sql_mode = ''");

            $stmt = $db->prepare("
                INSERT INTO {$this->table('users')}
                    (username, email, pass, display_name,
                     status_id, added_date, last_login_date, ip)
                VALUES
                    (:username, :email, :pass, :display_name,
                     " . StatusFormatter::USER_ACTIVE . ",
                     NOW(), NOW(), INET_ATON('127.0.0.1'))
            ");

            $stmt->execute([
                'username' => $username,
                'email' => $email,
                'pass' => md5(is_string($password) ? $password : ''),
                'display_name' => (is_string($displayName) && $displayName !== '')
                    ? $displayName : $username,
            ]);

            $db->exec("SET sql_mode = @old_sql_mode");

            $userId = $db->lastInsertId();
            $this->io()->success("User created successfully with ID: $userId");
        } catch (\Exception $e) {
            $this->io()->error('Failed to create user: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function deleteUser(?string $id): int
    {
        if ($id === null || $id === '') {
            $this->io()->error('User ID or username is required');
            return self::FAILURE;
        }

        $this->io()->warning("This will permanently delete user: $id");
        $this->io()->warning("All associated content will also be deleted");

        if ($this->io()->confirm('Do you want to continue?', false) !== true) {
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
                $this->io()->error("User not found: $id");
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
            $this->io()->success("User and all associated content deleted successfully");
        } catch (\Exception $e) {
            $db->rollBack();
            $this->io()->error('Failed to delete user: ' . $e->getMessage());
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
                $intValue = is_numeric($value) ? (int) $value : 0;
                $stats[] = [$label, number_format($intValue)];
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
            /** @var list<array<string, mixed>> $recentUsers */
            $recentUsers = $stmt->fetchAll();

            if ($recentUsers !== []) {
                $this->io()->section(Constants::TOP_QUERY_LIMIT . ' Most Recent Users');
                $rows = [];
                foreach ($recentUsers as $user) {
                    $username = is_string($user['username'] ?? null) ? $user['username'] : '';
                    $videos = is_numeric($user['videos'] ?? null) ? (string) $user['videos'] : '0';
                    $albums = is_numeric($user['albums'] ?? null) ? (string) $user['albums'] : '0';
                    $addedDate = is_string($user['added_date'] ?? null) ? $user['added_date'] : '';
                    $timestamp = $addedDate !== '' ? strtotime($addedDate) : false;
                    $dateStr = $timestamp !== false ? date('Y-m-d', $timestamp) : 'Unknown';
                    $rows[] = [$username, $videos, $albums, $dateStr];
                }
                $this->renderTable(['Username', 'Videos', 'Albums', 'Joined'], $rows);
            }
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch statistics: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
