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
    /** @var list<string> */
    private const SHOW_UNSUPPORTED_OPTIONS = [
        'status',
        'search',
        'country',
        'gender',
        'ip',
        'activity',
        'field-filter',
        'banned-status',
        'removal-requested',
        'trusted',
        'untrusted',
        'yes',
        'limit',
    ];

    private const SENSITIVE_LIST_FIELDS = [
        'pass',
        'pass_bill',
        'temp_pass',
        'remember_me_key',
        'remember_me_valid_for',
        'last_session_id_hash',
        'login_protection_restore_code',
    ];

    /** @var list<string> */
    private const ACTIVITY_FILTERS = [
        'new_today',
        'new_yesterday',
        'new_week',
        'new_month',
        'new_year',
        'have/logins',
        'have/logins_today',
        'have/logins_yesterday',
        'have/logins_week',
        'have/logins_month',
        'have/logins_year',
        'have/videos',
        'have/albums',
        'have/dvds',
        'have/playlists',
        'have/comments',
        'have/friends',
        'no/logins',
        'no/logins_today',
        'no/logins_yesterday',
        'no/logins_week',
        'no/logins_month',
        'no/logins_year',
        'no/videos',
        'no/albums',
        'no/dvds',
        'no/playlists',
        'no/comments',
        'no/friends',
    ];

    /** @var list<string> */
    private const USER_STRING_FIELD_FILTER_COLUMNS = [
        'description',
        'avatar',
        'cover',
        'city',
        'website',
        'education',
        'occupation',
        'about_me',
        'interests',
        'favourite_movies',
        'favourite_music',
        'favourite_books',
        'custom1',
        'custom2',
        'custom3',
        'custom4',
        'custom5',
        'custom6',
        'custom7',
        'custom8',
        'custom9',
        'custom10',
    ];

    /** @var list<string> */
    private const USER_ZERO_FIELD_FILTER_COLUMNS = [
        'favourite_category_id',
        'country_id',
        'gender_id',
        'relationship_status_id',
        'orientation_id',
        'profile_viewed',
        'tokens_available',
        'tokens_required',
    ];

    /** @var array<string, int> */
    private const USER_GENDER_ALIASES = [
        'male' => 1,
        'female' => 2,
        'couple' => 3,
        'transsexual' => 4,
        'trans' => 4,
    ];

    /** @var array<string, int> */
    private const USER_BANNED_STATUS_ALIASES = [
        'temporary' => 1,
        'temp' => 1,
        'permanent' => 2,
        'perm' => 2,
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
  <comment>kvs user list --untrusted</comment>
  <comment>kvs user list --ip=127.0.0.1</comment>
  <comment>kvs user list --activity=have/logins</comment>
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
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search in user admin text fields')
            ->addOption('country', null, InputOption::VALUE_REQUIRED, 'Filter by country ID or title')
            ->addOption('gender', null, InputOption::VALUE_REQUIRED, 'Filter by gender (male|female|couple|transsexual|1-4)')
            ->addOption('ip', null, InputOption::VALUE_REQUIRED, 'Filter by IP address')
            ->addOption('activity', null, InputOption::VALUE_REQUIRED, 'Filter by KVS admin activity bucket')
            ->addOption('field-filter', null, InputOption::VALUE_REQUIRED, 'KVS admin field filter (e.g. filled/avatar)')
            ->addOption('banned-status', null, InputOption::VALUE_REQUIRED, 'Filter by KVS login protection status (temporary|permanent|1|2)')
            ->addOption('removal-requested', null, InputOption::VALUE_NONE, 'Filter users who requested account deletion')
            ->addOption('trusted', null, InputOption::VALUE_NONE, 'Filter trusted users only')
            ->addOption('untrusted', null, InputOption::VALUE_NONE, 'Filter untrusted users only')
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
        if ($this->rejectUnsupportedArgument($input, 'list', 'id', 'a user ID or username argument', 'show', 'a specific user')) {
            return self::FAILURE;
        }

        if ($this->hasConflictingBoolOptions($input, ['trusted', 'untrusted'])) {
            return self::FAILURE;
        }

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
            $statusId = $this->parseStatusFilterOrFail($input, [
                'active' => StatusFormatter::USER_ACTIVE,
                'disabled' => StatusFormatter::USER_DISABLED,
                'premium' => StatusFormatter::USER_PREMIUM,
                'not-confirmed' => StatusFormatter::USER_NOT_CONFIRMED,
                'unconfirmed' => StatusFormatter::USER_NOT_CONFIRMED,
                'anonymous' => StatusFormatter::USER_ANONYMOUS,
                'generated' => StatusFormatter::USER_GENERATED,
                'webmaster' => StatusFormatter::USER_WEBMASTER,
            ], [0, 1, 2, 3, 4, 5, 6]);
            if ($statusId === false) {
                return self::FAILURE;
            }
            if ($statusId !== null) {
                $whereClause .= " AND u.status_id = :status";
                $params['status'] = $statusId;
            }
        }

        // Search filter
        $search = $this->getStringOption($input, 'search');
        if ($search !== null) {
            $whereClause .= ' AND ' . $this->buildAdminSearchCondition(
                'u.user_id',
                [
                    'u.username',
                    'u.display_name',
                    'u.email',
                    'u.city',
                    'u.website',
                    'u.education',
                    'u.occupation',
                    'u.about_me',
                    'u.interests',
                    'u.favourite_movies',
                    'u.favourite_music',
                    'u.favourite_books',
                    'u.custom1',
                    'u.custom2',
                    'u.custom3',
                    'u.custom4',
                    'u.custom5',
                    'u.custom6',
                    'u.custom7',
                    'u.custom8',
                    'u.custom9',
                    'u.custom10',
                ],
                $search,
                $params
            );
        }

        $ip = $this->getStringOption($input, 'ip');
        if ($ip !== null) {
            $ipNumber = $this->parseIpv4Option($ip);
            if ($ipNumber === null) {
                return self::FAILURE;
            }
            $whereClause .= ' AND (u.ip = :ip OR u.ip = :ip_raw)';
            $params['ip'] = $ipNumber;
            $params['ip_raw'] = $ip;
        }

        // Removal requested filter
        if ($this->getBoolOption($input, 'removal-requested')) {
            $whereClause .= " AND u.is_removal_requested = 1";
        }

        // Trusted users filter
        if ($this->getBoolOption($input, 'trusted')) {
            $whereClause .= " AND u.is_trusted = 1";
        }
        if ($this->getBoolOption($input, 'untrusted')) {
            $whereClause .= " AND u.is_trusted = 0";
        }

        if (!$this->applyUserAdminFilters($db, $input, $whereClause, $params)) {
            return self::FAILURE;
        }

        if (!$this->applyUserActivityFilter($input, $whereClause, $params)) {
            return self::FAILURE;
        }

        try {
            if ($this->getStringOption($input, 'format') === 'count') {
                if ($this->rejectFieldSelectionForCountFormat($input)) {
                    return self::FAILURE;
                }
                if ($this->getPositiveIntOptionOrDefault($input, 'limit', Constants::DEFAULT_CONTENT_LIMIT) === null) {
                    return self::FAILURE;
                }
                $stmt = $db->prepare("SELECT COUNT(*) {$fromClause} {$whereClause}");
                foreach ($params as $key => $value) {
                    $stmt->bindValue($key, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
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
                $stmt->bindValue($key, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $limit = $this->getPositiveIntOptionOrDefault($input, 'limit', Constants::DEFAULT_CONTENT_LIMIT);
            if ($limit === null) {
                return self::FAILURE;
            }
            $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $knownFields = array_merge(
                $this->getStatementColumnNames($stmt),
                [
                    'id',
                    'status',
                    'gender',
                    'ip',
                    'thumb',
                    'days_left_message',
                ]
            );
            $knownFields = array_values(array_diff($knownFields, self::SENSITIVE_LIST_FIELDS));

            /** @var list<array<string, mixed>> $users */
            $users = $stmt->fetchAll();
            $users = array_map(function (array $user) use ($db): array {
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

                return $this->filterSensitiveUserFields($this->hydrateUserListAppendFields($db, $user));
            }, $users);

            // Determine default fields based on filters
            // When filtering by removal-requested, include removal_reason
            if ($this->getBoolOption($input, 'removal-requested')) {
                $defaultFields = ['user_id', 'username', 'email', 'removal_reason', 'added_date'];
            } else {
                $defaultFields = ['user_id', 'username', 'display_name', 'email', 'status', 'added_date'];
            }

            // Format and display output using centralized Formatter
            $formatter = new Formatter($input->getOptions(), $defaultFields, $knownFields);
            $formatter->display($users, $this->io());

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch users: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function filterSensitiveUserFields(array $user): array
    {
        foreach (self::SENSITIVE_LIST_FIELDS as $field) {
            unset($user[$field]);
        }

        return $user;
    }

    private function parseIpv4Option(string $ip): ?string
    {
        if (preg_match('/^\d+$/', $ip) === 1) {
            if (!$this->isKvsIpv4IntegerInRange($ip)) {
                $this->io()->error('Invalid value for --ip (use: IPv4 address)');
                return null;
            }

            return (string) (int) $ip;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $this->io()->error('Invalid value for --ip (use: IPv4 address)');
            return null;
        }

        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            $this->io()->error('Invalid value for --ip (use: IPv4 address)');
            return null;
        }

        return sprintf('%u', $ipLong);
    }

    /**
     * @param array<string, int|string> $params
     */
    private function applyUserAdminFilters(
        \PDO $db,
        InputInterface $input,
        string &$whereClause,
        array &$params
    ): bool {
        $countryId = $this->resolveUserCountryFilter($db, $input);
        if ($countryId === false) {
            return false;
        }
        if ($countryId !== null) {
            $whereClause .= ' AND u.country_id = :country';
            $params['country'] = $countryId;
        }

        $genderId = $this->parseUserGenderFilter($input);
        if ($genderId === false) {
            return false;
        }
        if ($genderId !== null) {
            $whereClause .= ' AND u.gender_id = :gender';
            $params['gender'] = $genderId;
        }

        $bannedStatus = $this->parseUserBannedStatusFilter($input);
        if ($bannedStatus === false) {
            return false;
        }
        if ($bannedStatus === 1) {
            $whereClause .= ' AND (u.login_protection_is_banned = 1 AND u.login_protection_restore_code <> 0)';
        } elseif ($bannedStatus === 2) {
            $whereClause .= ' AND (u.login_protection_is_banned = 1 AND u.login_protection_restore_code = 0)';
        }

        $fieldFilter = $this->getStringOption($input, 'field-filter');
        if ($fieldFilter !== null) {
            $condition = $this->getUserFieldFilterCondition($fieldFilter);
            if ($condition === null) {
                $this->io()->error('Invalid user field filter. Use: ' . implode(', ', $this->getUserFieldFilterValues()));
                return false;
            }
            $whereClause .= " AND {$condition}";
        }

        return true;
    }

    private function resolveUserCountryFilter(\PDO $db, InputInterface $input): int|false|null
    {
        $country = $this->getStringOption($input, 'country');
        if ($country === null) {
            return null;
        }

        $country = trim($country);
        if ($country === '') {
            $this->io()->error('Invalid value for --country (use: integer >= 0 or country title)');
            return false;
        }

        if (preg_match('/^\d+$/', $country) === 1) {
            return (int) $country;
        }

        if (preg_match('/^-?\d+(?:\.\d+)?$/', $country) === 1) {
            $this->io()->error('Invalid value for --country (use: integer >= 0 or country title)');
            return false;
        }

        $stmt = $db->prepare(
            "SELECT country_id FROM {$this->table('list_countries')} "
            . "WHERE title = :title ORDER BY language_code = 'en' DESC LIMIT 1"
        );
        $stmt->execute(['title' => $country]);
        $id = $stmt->fetchColumn();

        return is_numeric($id) ? (int) $id : -1;
    }

    private function parseUserGenderFilter(InputInterface $input): int|false|null
    {
        $gender = $this->getStringOption($input, 'gender');
        if ($gender === null) {
            return null;
        }

        $gender = strtolower(trim($gender));
        if (isset(self::USER_GENDER_ALIASES[$gender])) {
            return self::USER_GENDER_ALIASES[$gender];
        }

        if (preg_match('/^\d+$/', $gender) === 1) {
            $genderId = (int) $gender;
            if ($genderId >= 1 && $genderId <= 4) {
                return $genderId;
            }
        }

        $this->io()->error('Invalid value for --gender (use: male, female, couple, transsexual, 1, 2, 3 or 4)');
        return false;
    }

    private function parseUserBannedStatusFilter(InputInterface $input): int|false|null
    {
        $status = $this->getStringOption($input, 'banned-status');
        if ($status === null) {
            return null;
        }

        $status = strtolower(trim($status));
        if (isset(self::USER_BANNED_STATUS_ALIASES[$status])) {
            return self::USER_BANNED_STATUS_ALIASES[$status];
        }

        if ($status === '1' || $status === '2') {
            return (int) $status;
        }

        $this->io()->error('Invalid value for --banned-status (use: temporary, permanent, 1 or 2)');
        return false;
    }

    /** @return list<string> */
    private function getUserFieldFilterValues(): array
    {
        $values = [];
        foreach (['empty', 'filled'] as $prefix) {
            foreach (self::USER_STRING_FIELD_FILTER_COLUMNS as $column) {
                $values[] = "{$prefix}/{$column}";
            }
            foreach (self::USER_ZERO_FIELD_FILTER_COLUMNS as $column) {
                $values[] = "{$prefix}/{$column}";
            }
            $values[] = "{$prefix}/birth_date";
        }

        return $values;
    }

    private function getUserFieldFilterCondition(string $fieldFilter): ?string
    {
        $parts = explode('/', $fieldFilter, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$state, $field] = $parts;
        if (!in_array($state, ['empty', 'filled'], true)) {
            return null;
        }

        return $state === 'empty'
            ? $this->getEmptyUserFieldFilterCondition($field)
            : $this->getFilledUserFieldFilterCondition($field);
    }

    private function getEmptyUserFieldFilterCondition(string $field): ?string
    {
        if (in_array($field, self::USER_STRING_FIELD_FILTER_COLUMNS, true)) {
            return "u.{$field} = ''";
        }

        if (in_array($field, self::USER_ZERO_FIELD_FILTER_COLUMNS, true)) {
            return "u.{$field} = 0";
        }

        if ($field === 'birth_date') {
            return "u.birth_date = '0000-00-00'";
        }

        return null;
    }

    private function getFilledUserFieldFilterCondition(string $field): ?string
    {
        if (in_array($field, self::USER_STRING_FIELD_FILTER_COLUMNS, true)) {
            return "u.{$field} <> ''";
        }

        if (in_array($field, self::USER_ZERO_FIELD_FILTER_COLUMNS, true)) {
            return "u.{$field} <> 0";
        }

        if ($field === 'birth_date') {
            return "u.birth_date <> '0000-00-00'";
        }

        return null;
    }

    /**
     * @param array<string, int|string> $params
     */
    private function applyUserActivityFilter(InputInterface $input, string &$whereClause, array &$params): bool
    {
        $activity = $this->getStringOption($input, 'activity');
        if ($activity === null) {
            return true;
        }

        if (!in_array($activity, self::ACTIVITY_FILTERS, true)) {
            $this->io()->error(sprintf(
                'Invalid value for --activity. Valid values: %s',
                implode(', ', self::ACTIVITY_FILTERS)
            ));
            return false;
        }

        $today = date('Y-m-d 00:00:00');
        $yesterday = date('Y-m-d 00:00:00', time() - 86400);
        $lastWeek = date('Y-m-d H:i:s', time() - 7 * 86400);
        $lastMonth = date('Y-m-d H:i:s', time() - 30 * 86400);
        $lastYear = date('Y-m-d H:i:s', time() - 365 * 86400);

        switch ($activity) {
            case 'new_today':
                $whereClause .= ' AND u.added_date > :activity_date';
                $params['activity_date'] = $today;
                break;
            case 'new_yesterday':
                $whereClause .= ' AND u.added_date > :activity_date';
                $params['activity_date'] = $yesterday;
                break;
            case 'new_week':
                $whereClause .= ' AND u.added_date > :activity_date';
                $params['activity_date'] = $lastWeek;
                break;
            case 'new_month':
                $whereClause .= ' AND u.added_date > :activity_date';
                $params['activity_date'] = $lastMonth;
                break;
            case 'new_year':
                $whereClause .= ' AND u.added_date > :activity_date';
                $params['activity_date'] = $lastYear;
                break;
            case 'have/logins':
                $whereClause .= ' AND u.logins_count > 0';
                break;
            case 'have/logins_today':
                $whereClause .= ' AND u.last_login_date > :activity_date';
                $params['activity_date'] = $today;
                break;
            case 'have/logins_yesterday':
                $whereClause .= ' AND u.last_login_date > :activity_date';
                $params['activity_date'] = $yesterday;
                break;
            case 'have/logins_week':
                $whereClause .= ' AND u.last_login_date > :activity_date';
                $params['activity_date'] = $lastWeek;
                break;
            case 'have/logins_month':
                $whereClause .= ' AND u.last_login_date > :activity_date';
                $params['activity_date'] = $lastMonth;
                break;
            case 'have/logins_year':
                $whereClause .= ' AND u.last_login_date > :activity_date';
                $params['activity_date'] = $lastYear;
                break;
            case 'no/logins':
                $whereClause .= ' AND u.logins_count = 0';
                break;
            case 'no/logins_today':
                $whereClause .= ' AND u.last_login_date <= :activity_date';
                $params['activity_date'] = $today;
                break;
            case 'no/logins_yesterday':
                $whereClause .= ' AND u.last_login_date <= :activity_date';
                $params['activity_date'] = $yesterday;
                break;
            case 'no/logins_week':
                $whereClause .= ' AND u.last_login_date <= :activity_date';
                $params['activity_date'] = $lastWeek;
                break;
            case 'no/logins_month':
                $whereClause .= ' AND u.last_login_date <= :activity_date';
                $params['activity_date'] = $lastMonth;
                break;
            case 'no/logins_year':
                $whereClause .= ' AND u.last_login_date <= :activity_date';
                $params['activity_date'] = $lastYear;
                break;
            default:
                $this->applyUserRelationActivityFilter($activity, $whereClause);
                break;
        }

        return true;
    }

    private function applyUserRelationActivityFilter(string $activity, string &$whereClause): void
    {
        $relationMap = [
            'videos' => ['videos', 'video_id'],
            'albums' => ['albums', 'album_id'],
            'dvds' => ['dvds', 'dvd_id'],
            'playlists' => ['playlists', 'playlist_id'],
            'comments' => ['comments', 'comment_id'],
        ];

        if ($activity === 'have/friends') {
            $whereClause .= ' AND u.friends_count > 0';
            return;
        }
        if ($activity === 'no/friends') {
            $whereClause .= ' AND u.friends_count = 0';
            return;
        }

        [$mode, $relation] = explode('/', $activity, 2);
        [$table] = $relationMap[$relation];
        $exists = sprintf(
            'EXISTS (SELECT 1 FROM %s related WHERE related.user_id = u.user_id)',
            $this->table($table)
        );

        $whereClause .= $mode === 'have' ? " AND $exists" : " AND NOT $exists";
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
     * Hydrate append-only fields used by KVS admin users grid.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function hydrateUserListAppendFields(\PDO $db, array $user): array
    {
        $user['days_left_message'] = '';
        if ($this->getInt($user['status_id'] ?? null) !== StatusFormatter::USER_PREMIUM) {
            return $user;
        }

        $userId = $this->getInt($user['user_id'] ?? null);
        if ($userId <= 0) {
            return $user;
        }

        try {
            $stmt = $db->prepare("
                SELECT status_id, access_end_date, duration_rebill, is_unlimited_access
                FROM {$this->table('bill_transactions')}
                WHERE status_id IN (1, 4) AND user_id = :user_id
                ORDER BY transaction_id DESC
                LIMIT 1
            ");
            $stmt->execute(['user_id' => $userId]);
            $transaction = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return $user;
        }

        if (!is_array($transaction)) {
            return $user;
        }

        if ($this->getInt($transaction['is_unlimited_access'] ?? null) === 1) {
            $message = "\u{221E}";
        } elseif ($this->getInt($transaction['status_id'] ?? null) === 4) {
            $message = $this->getInt($transaction['duration_rebill'] ?? null) . ' days';
        } else {
            $accessEndDate = $this->getStr($transaction['access_end_date'] ?? null);
            $accessEndTimestamp = strtotime($accessEndDate);
            $daysLeft = $accessEndTimestamp !== false
                ? (int) round(($accessEndTimestamp - time()) / 86400)
                : 0;
            $message = $daysLeft . ' days';
        }

        if ($this->getInt($user['is_trial'] ?? null) === 1) {
            $message .= ', trial';
        }

        $user['days_left_message'] = $message;
        return $user;
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

        if ($this->rejectUnsupportedOptionsForAction($input, 'show', self::SHOW_UNSUPPORTED_OPTIONS)) {
            return self::FAILURE;
        }
        if ($this->rejectCountFormatForSingularAction($input, 'show')) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $user = $this->fetchUserByIdOrUsername($db, $id);

            if ($user === false) {
                $this->io()->error("User not found: $id");
                return self::FAILURE;
            }

            $userId = $this->getInt($user['user_id'] ?? null);
            $username = $this->getStr($user['username'] ?? null);

            $displayName = $this->getStr($user['display_name'] ?? null);
            $countryCode = $this->getStr($user['country_id'] ?? null);
            $birthDate = $this->getStr($user['birth_date'] ?? null);
            $ip = array_key_exists('ip', $user) ? $this->formatKvsIp($user['ip']) : '';

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

            if ($this->shouldUseFormattedRows($input)) {
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

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $user = $this->fetchUserByIdOrUsername($db, $id);

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

            $username = $user['username'] ?? $id;
            $username = is_scalar($username) ? (string) $username : $id;

            $this->io()->warning("This will delete user using KVS native cleanup: {$username} ({$userId})");
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

            $this->deleteUsersWithKvs([$userId]);

            $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table('users')} WHERE user_id = :id");
            $stmt->execute(['id' => $userId]);
            if ((int) $stmt->fetchColumn() > 0) {
                $this->io()->error("KVS did not delete user: {$userId}");
                return self::FAILURE;
            }

            $this->io()->success("User deleted with KVS cleanup: {$username} ({$userId})");
        } catch (\Exception $e) {
            $this->io()->error('Failed to delete user: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function fetchUserByIdOrUsername(\PDO $db, string $idOrUsername): array|false
    {
        if (preg_match('/^[1-9]\d*$/', $idOrUsername) === 1) {
            $stmt = $db->prepare("SELECT * FROM {$this->table('users')} WHERE user_id = :id");
            $stmt->execute(['id' => (int) $idOrUsername]);
            return $this->fetchAssocRow($stmt);
        }

        $stmt = $db->prepare("SELECT * FROM {$this->table('users')} WHERE username = :username");
        $stmt->execute(['username' => $idOrUsername]);
        return $this->fetchAssocRow($stmt);
    }

    /**
     * @return array<string, mixed>|false
     */
    private function fetchAssocRow(\PDOStatement $stmt): array|false
    {
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return false;
        }

        $assoc = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $assoc[$key] = $value;
            }
        }

        return $assoc;
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
        if ($this->rejectUnsupportedArgument($input, 'stats', 'id', 'a user ID or username argument', 'show', 'a specific user')) {
            return self::FAILURE;
        }

        if ($this->rejectUnsupportedOptionsForAction($input, 'stats', self::SHOW_UNSUPPORTED_OPTIONS)) {
            return self::FAILURE;
        }

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
                'Inactive Users' => "SELECT COUNT(*) FROM {$this->table('users')} WHERE status_id = " . StatusFormatter::USER_DISABLED,
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

            if ($this->shouldUseFormattedRows($input)) {
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
