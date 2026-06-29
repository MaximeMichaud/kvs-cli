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
  delete <id>       Delete user using KVS native cleanup
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
  <comment>kvs user list --fields=id,username,email,tokens_available,tokens_required</comment>
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
            ->addOption(
                'status',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter by status (active|disabled|premium|not-confirmed|unconfirmed|anonymous|generated|webmaster|0-6)'
            )
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results to show', Constants::DEFAULT_CONTENT_LIMIT)
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search in usernames and emails')
            ->addOption('removal-requested', null, InputOption::VALUE_NONE, 'Filter users who requested account deletion')
            ->addOption('trusted', null, InputOption::VALUE_NONE, 'Filter trusted users only')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to display')
            ->addOption('field', null, InputOption::VALUE_REQUIRED, 'Display single field value')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation of long text fields in table view')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count, ids', 'table')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompt (for delete)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $this->getStringArgument($input, 'action') ?? 'list';

        return match ($action) {
            'list' => $this->listUsers($input),
            'show' => $this->showUser($this->getStringArgument($input, 'id'), $input),
            'create' => $this->createUser($input),
            'delete' => $this->deleteUser($this->getStringArgument($input, 'id'), $input),
            'stats' => $this->showStats($input),
            default => $this->failUnknownAction('user', $action, ['list', 'show', 'create', 'delete', 'stats']),
        };
    }

    private function listUsers(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $fromClause = "FROM {$this->table('users')} u";
        $whereClause = 'WHERE 1=1';

        $params = [];

        // Status filter
        $status = $this->getStringOption($input, 'status');
        if ($status !== null) {
            $statusId = $this->parseStatusFilter($input, [
                'active' => StatusFormatter::USER_ACTIVE,
                'disabled' => StatusFormatter::USER_DISABLED,
                'premium' => StatusFormatter::USER_PREMIUM,
                'not-confirmed' => StatusFormatter::USER_NOT_CONFIRMED,
                'unconfirmed' => StatusFormatter::USER_NOT_CONFIRMED,
                'anonymous' => StatusFormatter::USER_ANONYMOUS,
                'generated' => StatusFormatter::USER_GENERATED,
                'webmaster' => StatusFormatter::USER_WEBMASTER,
            ], [0, 1, 2, 3, 4, 5, 6]);
            if ($statusId !== null) {
                $whereClause .= " AND u.status_id = :status";
                $params['status'] = $statusId;
            }
        }

        // Search filter
        $search = $this->getStringOption($input, 'search');
        if ($search !== null) {
            $whereClause .= " AND (u.username LIKE :search OR u.email LIKE :search)";
            $params['search'] = "%$search%";
        }

        // Removal requested filter
        if ($this->getBoolOption($input, 'removal-requested')) {
            $whereClause .= " AND u.is_removal_requested = 1";
        }

        // Trusted users filter
        if ($this->getBoolOption($input, 'trusted')) {
            $whereClause .= " AND u.is_trusted = 1";
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

            $counterSelects = $this->getRequestedUserCounterSelects($input);
            $query = "SELECT u.*" . ($counterSelects !== [] ? ', ' . implode(', ', $counterSelects) : '') . "
                 {$fromClause}
                 {$whereClause}
                 ORDER BY u.user_id DESC LIMIT :limit";

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

            /** @var list<array<string, mixed>> $users */
            $users = $stmt->fetchAll();
            $users = array_map(function (array $user): array {
                $statusId = $this->getInt($user['status_id'] ?? null);
                $user['id'] = $user['user_id'] ?? 0;
                $user['status'] = StatusFormatter::user($statusId, false);
                $user['gender'] = $this->formatGender($this->getInt($user['gender_id'] ?? null));
                if (array_key_exists('ip', $user)) {
                    $user['ip'] = $this->formatKvsIp($user['ip']);
                }
                $avatar = $user['avatar'] ?? '';
                $avatar = is_scalar($avatar) ? (string) $avatar : '';
                $user['thumb'] = $avatar;

                return $user;
            }, $users);

            // Determine default fields based on filters
            // When filtering by removal-requested, include removal_reason
            if ($this->getBoolOption($input, 'removal-requested')) {
                $defaultFields = ['user_id', 'username', 'email', 'removal_reason', 'added_date'];
            } else {
                $defaultFields = ['user_id', 'username', 'display_name', 'email', 'status', 'added_date'];
            }

            // Format and display output using centralized Formatter
            $formatter = new Formatter($input->getOptions(), $defaultFields);
            $formatter->display($users, $this->io());

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch users: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @return list<string>
     */
    private function getRequestedUserCounterSelects(InputInterface $input): array
    {
        $requestedFields = [];
        $fields = $this->getStringOption($input, 'fields');
        if ($fields !== null) {
            $requestedFields = array_merge($requestedFields, array_map('trim', explode(',', $fields)));
        }

        $field = $this->getStringOption($input, 'field');
        if ($field !== null) {
            $requestedFields[] = trim($field);
        }

        $selects = [];
        if (in_array('videos', $requestedFields, true) || in_array('video_count', $requestedFields, true)) {
            $selects[] = sprintf(
                '(SELECT COUNT(*) FROM %s v WHERE v.user_id = u.user_id) as video_count',
                $this->table('videos')
            );
        }
        if (in_array('videos_count', $requestedFields, true)) {
            $selects[] = sprintf(
                '(SELECT COUNT(*) FROM %s v WHERE v.user_id = u.user_id) as videos_count',
                $this->table('videos')
            );
        }
        if (in_array('albums', $requestedFields, true) || in_array('album_count', $requestedFields, true)) {
            $selects[] = sprintf(
                '(SELECT COUNT(*) FROM %s a WHERE a.user_id = u.user_id) as album_count',
                $this->table('albums')
            );
        }
        if (in_array('albums_count', $requestedFields, true)) {
            $selects[] = sprintf(
                '(SELECT COUNT(*) FROM %s a WHERE a.user_id = u.user_id) as albums_count',
                $this->table('albums')
            );
        }
        if (in_array('posts_count', $requestedFields, true)) {
            $selects[] = sprintf(
                '(SELECT COUNT(*) FROM %s p WHERE p.user_id = u.user_id) as posts_count',
                $this->table('posts')
            );
        }
        if (in_array('dvds_count', $requestedFields, true)) {
            $selects[] = sprintf(
                '(SELECT COUNT(*) FROM %s d WHERE d.user_id = u.user_id) as dvds_count',
                $this->table('dvds')
            );
        }
        if (in_array('playlists_count', $requestedFields, true)) {
            $selects[] = sprintf(
                '(SELECT COUNT(*) FROM %s p WHERE p.user_id = u.user_id) as playlists_count',
                $this->table('playlists')
            );
        }
        if (in_array('public_playlists_count', $requestedFields, true)) {
            $selects[] = sprintf(
                '(SELECT COUNT(*) FROM %s p WHERE p.user_id = u.user_id AND p.is_private = 0) as public_playlists_count',
                $this->table('playlists')
            );
        }
        if (in_array('comments_count', $requestedFields, true)) {
            $selects[] = sprintf(
                '(SELECT COUNT(*) FROM %s c WHERE c.user_id = u.user_id) as comments_count',
                $this->table('comments')
            );
        }
        if (in_array('favourite_category', $requestedFields, true)) {
            $selects[] = sprintf(
                '(SELECT title FROM %s c WHERE c.category_id = u.favourite_category_id) as favourite_category',
                $this->table('categories')
            );
        }

        return $selects;
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
        if ($date === '' || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
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
            0 => 'N/A',
            1 => 'Male',
            2 => 'Female',
            3 => 'Couple',
            4 => 'Transsexual',
            default => 'Unknown',
        };
    }

    private function showUser(?string $id, InputInterface $input): int
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
                ['Birth Date', $this->formatDate($birthDate, 'Y-m-d', 'N/A')],
                ['Joined', $this->formatDate($this->getStr($user['added_date'] ?? null), 'Y-m-d H:i:s', 'Unknown')],
                ['Last Login', $this->formatDate($this->getStr($user['last_login_date'] ?? null))],
                ['IP', $ip !== '' ? $ip : 'N/A'],
            ];

            if (!$this->isTableFormat($input)) {
                $contentStats = $this->getUserContentStats(
                    $db,
                    $userId,
                    $this->getInt($user['profile_viewed'] ?? null)
                );

                return $this->displayDetailRows($input, $info, [
                    ...$contentStats,
                    'logins_count' => $this->getInt($user['logins_count'] ?? null),
                    'activity_score' => $this->getInt($user['activity'] ?? null),
                    'tokens_available' => $this->getInt($user['tokens_available'] ?? null),
                    'tokens_required' => $this->getInt($user['tokens_required'] ?? null),
                ]);
            }

            $this->io()->section("User: {$username}");
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

    /**
     * @return array{videos_uploaded: int, albums_created: int, comments_posted: int, profile_views: int}
     */
    private function getUserContentStats(\PDO $db, int $userId, int $profileViewed): array
    {
        $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table('videos')} WHERE user_id = :id");
        $stmt->execute(['id' => $userId]);
        $videoCount = (int) $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table('albums')} WHERE user_id = :id");
        $stmt->execute(['id' => $userId]);
        $albumCount = (int) $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table('comments')} WHERE user_id = :id");
        $stmt->execute(['id' => $userId]);
        $commentCount = (int) $stmt->fetchColumn();

        return [
            'videos_uploaded' => $videoCount,
            'albums_created' => $albumCount,
            'comments_posted' => $commentCount,
            'profile_views' => $profileViewed,
        ];
    }

    private function displayUserContentStats(\PDO $db, int $userId, int $profileViewed): void
    {
        $contentStats = $this->getUserContentStats($db, $userId, $profileViewed);

        $this->io()->section('Content Statistics');
        $stats = [
            ['Videos Uploaded', (string) $contentStats['videos_uploaded']],
            ['Albums Created', (string) $contentStats['albums_created']],
            ['Comments Posted', (string) $contentStats['comments_posted']],
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
                'pass' => $this->generateKvsPasswordHash(is_string($password) ? $password : ''),
                'display_name' => (is_string($displayName) && $displayName !== '')
                    ? $displayName : $username,
            ]);

            $userId = $db->lastInsertId();

            $db->exec("SET sql_mode = @old_sql_mode");
            $this->io()->success("User created successfully with ID: $userId");
        } catch (\Exception $e) {
            $this->io()->error('Failed to create user: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function generateKvsPasswordHash(string $password): string
    {
        return \crypt($password, '$2a$07$aa5f7b4693ccdbdd792f6a998e9ed446$');
    }

    private function deleteUser(?string $id, InputInterface $input): int
    {
        if ($id === null || $id === '') {
            $this->io()->error('User ID or username is required');
            return self::FAILURE;
        }

        $this->io()->warning("This will delete user using KVS native cleanup: $id");
        $this->io()->warning('Associated videos and albums will be queued for KVS background deletion.');

        if (
            !$this->getBoolOption($input, 'yes')
            && $this->io()->confirm('Do you want to continue?', false) !== true
        ) {
            if (!$input->isInteractive()) {
                $this->io()->error('User deletion cancelled because confirmation was not provided.');
                return self::FAILURE;
            }

            $this->io()->warning('User deletion cancelled');
            return self::SUCCESS;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("SELECT user_id, username, status_id FROM {$this->table('users')} WHERE user_id = :id OR username = :id");
            $stmt->execute(['id' => $id]);
            /** @var array<string, mixed>|false $user */
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($user === false) {
                $this->io()->error("User not found: $id");
                return self::FAILURE;
            }

            $userIdValue = $user['user_id'] ?? null;
            if (!is_numeric($userIdValue)) {
                $this->io()->error("User not found: $id");
                return self::FAILURE;
            }

            $userId = (int) $userIdValue;
            if ($this->getInt($user['status_id'] ?? null) === StatusFormatter::USER_ANONYMOUS) {
                $this->io()->error("Anonymous system user cannot be deleted: {$userId}");
                return self::FAILURE;
            }

            $this->deleteUsersWithKvs([$userId]);

            $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table('users')} WHERE user_id = :id");
            $stmt->execute(['id' => $userId]);
            if ((int) $stmt->fetchColumn() > 0) {
                $this->io()->error("KVS did not delete user: {$userId}");
                return self::FAILURE;
            }

            $username = $user['username'] ?? $id;
            $username = is_scalar($username) ? (string) $username : $id;
            $this->io()->success("User deleted with KVS cleanup: {$username} ({$userId})");
        } catch (\Exception $e) {
            $this->io()->error('Failed to delete user: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Delete users through KVS native cleanup so files, references, counters and background tasks stay consistent.
     *
     * @param list<int> $userIds
     */
    protected function deleteUsersWithKvs(array $userIds): void
    {
        $this->runWithKvsAdminContext(function () use ($userIds): void {
            $deleteUsers = $this->getKvsDeleteUsersFunctionName();
            if (!function_exists($deleteUsers)) {
                throw new \RuntimeException('KVS delete_users function is not available');
            }

            $deleteUsers($userIds, true, 'ap');
        });
    }

    private function getKvsDeleteUsersFunctionName(): string
    {
        return 'delete_users';
    }

    private function showStats(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stats = [];
            /** @var list<array<string, mixed>> $metricRows */
            $metricRows = [];
            $todayStart = date('Y-m-d 00:00:00');
            $tomorrowStart = date('Y-m-d 00:00:00', strtotime('+1 day'));
            $monthStart = date('Y-m-01 00:00:00');
            $nextMonthStart = date('Y-m-01 00:00:00', strtotime('first day of next month'));

            $queries = [
                'Total Users' => "SELECT COUNT(*) FROM {$this->table('users')}",
                'Active Users' => "SELECT COUNT(*) FROM {$this->table('users')} WHERE status_id = " . StatusFormatter::USER_ACTIVE,
                'Premium Users' => "SELECT COUNT(*) FROM {$this->table('users')} WHERE status_id = " . StatusFormatter::USER_PREMIUM,
                'Disabled Users' => "SELECT COUNT(*) FROM {$this->table('users')} WHERE status_id = " . StatusFormatter::USER_DISABLED,
                'Users Today' => "SELECT COUNT(*) FROM {$this->table('users')} "
                    . "WHERE added_date >= '{$todayStart}' AND added_date < '{$tomorrowStart}'",
                'Users This Month' => "SELECT COUNT(*) FROM {$this->table('users')} "
                    . "WHERE added_date >= '{$monthStart}' AND added_date < '{$nextMonthStart}'",
            ];

            foreach ($queries as $label => $query) {
                $result = $db->query($query);
                if ($result === false) {
                    throw new \RuntimeException("Failed to execute query: $label");
                }
                $value = $result->fetchColumn();
                $intValue = is_numeric($value) ? (int) $value : 0;
                $stats[] = [$label, number_format($intValue)];
                $metricRows[] = $this->metricRow('overall', $label, $intValue, number_format($intValue));
            }

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

            /** @var list<list<string>> $recentRows */
            $recentRows = [];
            if ($recentUsers !== []) {
                foreach ($recentUsers as $i => $user) {
                    $username = is_string($user['username'] ?? null) ? $user['username'] : '';
                    $videos = is_numeric($user['videos'] ?? null) ? (string) $user['videos'] : '0';
                    $albums = is_numeric($user['albums'] ?? null) ? (string) $user['albums'] : '0';
                    $addedDate = is_string($user['added_date'] ?? null) ? $user['added_date'] : '';
                    $timestamp = $addedDate !== '' ? strtotime($addedDate) : false;
                    $dateStr = $timestamp !== false ? date('Y-m-d', $timestamp) : 'Unknown';
                    $metricRows[] = $this->metricRow(
                        'recent_users',
                        (string) ($i + 1),
                        $dateStr,
                        $dateStr,
                        $username
                    );
                    $recentRows[] = [$username, $videos, $albums, $dateStr];
                }
            }

            if (!$this->isTableFormat($input)) {
                $this->displayMetricRows($input, $metricRows);
                return self::SUCCESS;
            }

            $this->renderTable(['Metric', 'Count'], $stats);

            if ($recentRows !== []) {
                $this->io()->section(Constants::TOP_QUERY_LIMIT . ' Most Recent Users');
                $this->renderTable(['Username', 'Videos', 'Albums', 'Joined'], $recentRows);
            }
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch statistics: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
