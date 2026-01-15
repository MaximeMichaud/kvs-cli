<?php

declare(strict_types=1);

namespace KVS\CLI\Command\Content;

use KVS\CLI\Command\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'user:purge',
    description: 'Purge users based on criteria (uses KVS delete_users function)',
    aliases: ['users:purge', 'user:cleanup']
)]
class UserPurgeCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setHelp(<<<'HELP'
Purge users based on specified criteria using KVS's native delete_users() function.

<info>This command is DRY-RUN by default.</info> Use --confirm to actually delete users.

<info>FILTERS:</info>
  --removal-requested    Users who requested account deletion
  --no-content           Users with 0 videos and 0 comments
  --inactive-days=N      Users who haven't logged in for N days
  --min-age=N            Accounts older than N days

<info>EXECUTION:</info>
  --confirm              Actually delete (default is dry-run)
  --yes                  Skip confirmation prompt

<info>EXAMPLES:</info>
  <comment># Dry-run: show users who requested deletion with no content</comment>
  kvs user:purge --removal-requested --no-content

  <comment># Add inactive and age filters</comment>
  kvs user:purge --removal-requested --no-content --inactive-days=30 --min-age=90

  <comment># Actually delete (with confirmation)</comment>
  kvs user:purge --removal-requested --no-content --inactive-days=30 --confirm

  <comment># Delete without confirmation prompt</comment>
  kvs user:purge --removal-requested --no-content --confirm --yes

<info>NOTE:</info>
  This command loads the KVS admin context and uses the native delete_users()
  function, which properly cleans up all related data (avatars, subscriptions,
  messages, comments, etc.)
HELP
            )
            ->addOption('removal-requested', null, InputOption::VALUE_NONE, 'Filter users who requested account deletion')
            ->addOption('no-content', null, InputOption::VALUE_NONE, 'Filter users with 0 videos and 0 comments')
            ->addOption('inactive-days', null, InputOption::VALUE_REQUIRED, 'Filter users inactive for N days')
            ->addOption('min-age', null, InputOption::VALUE_REQUIRED, 'Filter accounts older than N days')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of users to process', 1000)
            ->addOption('confirm', null, InputOption::VALUE_NONE, 'Actually delete users (default is dry-run)')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $removalRequested = $this->getBoolOption($input, 'removal-requested');
        $noContent = $this->getBoolOption($input, 'no-content');
        $inactiveDays = $this->getIntOption($input, 'inactive-days');
        $minAge = $this->getIntOption($input, 'min-age');
        $confirm = $this->getBoolOption($input, 'confirm');
        $yes = $this->getBoolOption($input, 'yes');
        $limit = $this->getIntOptionOrDefault($input, 'limit', 1000);

        // Require at least one filter
        if (!$removalRequested && !$noContent && $inactiveDays === null && $minAge === null) {
            $this->io()->error('At least one filter is required: --removal-requested, --no-content, --inactive-days, or --min-age');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        // Build query
        $conditions = [];
        $params = [];

        if ($removalRequested) {
            $conditions[] = 'is_removal_requested = 1';
        }

        if ($noContent) {
            $conditions[] = 'total_videos_count = 0';
            $conditions[] = 'comments_total_count = 0';
        }

        if ($inactiveDays !== null) {
            $conditions[] = 'last_login_date < DATE_SUB(NOW(), INTERVAL :inactive_days DAY)';
            $params['inactive_days'] = $inactiveDays;
        }

        if ($minAge !== null) {
            $conditions[] = 'added_date < DATE_SUB(NOW(), INTERVAL :min_age DAY)';
            $params['min_age'] = $minAge;
        }

        $whereClause = implode(' AND ', $conditions);
        $query = "SELECT user_id, username, email, last_login_date, added_date, removal_reason
                  FROM {$this->table('users')}
                  WHERE $whereClause
                  ORDER BY added_date ASC
                  LIMIT :limit";

        try {
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, \PDO::PARAM_INT);
            }
            $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $users = $stmt->fetchAll();
        } catch (\Exception $e) {
            $this->io()->error('Failed to query users: ' . $e->getMessage());
            return self::FAILURE;
        }

        $count = count($users);

        if ($count === 0) {
            $this->io()->success('No users match the specified criteria.');
            return self::SUCCESS;
        }

        // Display users
        $this->io()->title($confirm ? 'Users to Delete' : 'Users Matching Criteria (Dry-Run)');

        /** @var list<list<string>> $rows */
        $rows = [];
        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }
            $lastLogin = $user['last_login_date'] ?? null;
            $lastLoginStr = 'Never';
            if (is_string($lastLogin) && $lastLogin !== '') {
                $timestamp = strtotime($lastLogin);
                if ($timestamp !== false) {
                    $lastLoginStr = date('Y-m-d', $timestamp);
                }
            }

            $userIdVal = $user['user_id'] ?? '';
            $usernameVal = $user['username'] ?? '';
            $emailVal = $user['email'] ?? '';
            $addedDateVal = $user['added_date'] ?? '';
            $addedDateStr = 'Unknown';
            if (is_string($addedDateVal) && $addedDateVal !== '') {
                $timestamp = strtotime($addedDateVal);
                if ($timestamp !== false) {
                    $addedDateStr = date('Y-m-d', $timestamp);
                }
            }

            $rows[] = [
                is_scalar($userIdVal) ? (string) $userIdVal : '',
                is_scalar($usernameVal) ? (string) $usernameVal : '',
                is_scalar($emailVal) ? (string) $emailVal : '',
                $lastLoginStr,
                $addedDateStr,
            ];
        }

        $this->renderTable(['ID', 'Username', 'Email', 'Last Login', 'Created'], $rows);

        $this->io()->newLine();
        $this->io()->text(sprintf('<info>Total:</info> %d users', $count));

        if ($confirm === false) {
            $this->io()->newLine();
            $this->io()->note('This is a DRY-RUN. Use --confirm to actually delete users.');
            return self::SUCCESS;
        }

        // Confirmation
        if ($yes === false) {
            $this->io()->newLine();
            $this->io()->warning(sprintf('You are about to DELETE %d users permanently!', $count));

            if ($this->io()->confirm('Are you sure you want to continue?', false) !== true) {
                $this->io()->text('Operation cancelled.');
                return self::SUCCESS;
            }
        }

        // Load KVS admin context and delete
        $this->io()->text('Loading KVS admin context...');

        $kvsPath = $this->config->getKvsPath();
        $adminPath = $kvsPath . '/admin';

        if (!is_dir($adminPath)) {
            $this->io()->error(sprintf('KVS admin directory not found: %s', $adminPath));
            return self::FAILURE;
        }

        // Change to admin directory for relative includes
        $originalDir = getcwd();
        if ($originalDir === false) {
            $this->io()->error('Failed to get current working directory');
            return self::FAILURE;
        }
        chdir($adminPath);

        try {
            // Load KVS admin context
            require_once $adminPath . '/include/setup.php';
            require_once $adminPath . '/include/setup_db.php';
            require_once $adminPath . '/include/functions_base.php';
            require_once $adminPath . '/include/functions.php';

            // Simulate admin session for delete_users
            /** @var array<string, mixed> $sessionUserdata */
            $sessionUserdata = [
                'user_id' => 1,
                'login' => 'kvs-cli',
                'is_superadmin' => 1,
            ];
            $_SESSION['userdata'] = $sessionUserdata;

            $userIds = array_column($users, 'user_id');

            $this->io()->text(sprintf('Deleting %d users...', $count));

            // Use KVS delete_users function with 'ap' context
            // @phpstan-ignore-next-line (function is loaded dynamically from KVS)
            delete_users($userIds, true, 'ap');

            chdir($originalDir);

            $this->io()->success(sprintf('Successfully deleted %d users.', $count));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            chdir($originalDir);
            $this->io()->error('Failed to delete users: ' . $e->getMessage());
            if ($this->io()->isVerbose()) {
                $this->io()->text($e->getTraceAsString());
            }
            return self::FAILURE;
        }
    }
}
