<?php

namespace KVS\CLI\Command\System;

use KVS\CLI\Command\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'system:antispam',
    description: 'Manage KVS anti-spam settings',
    aliases: ['antispam']
)]
class AntispamCommand extends BaseCommand
{
    /** @var array<string, string> */
    private const SECTIONS = [
        'videos' => 'ANTISPAM_VIDEOS',
        'albums' => 'ANTISPAM_ALBUMS',
        'posts' => 'ANTISPAM_POSTS',
        'playlists' => 'ANTISPAM_PLAYLISTS',
        'dvds' => 'ANTISPAM_DVDS',
        'comments' => 'ANTISPAM_COMMENTS',
        'messages' => 'ANTISPAM_MESSAGES',
        'feedbacks' => 'ANTISPAM_FEEDBACKS',
    ];

    /** @var array<string, string> */
    private const ACTIONS = [
        'captcha' => 'FORCE_CAPTCHA',
        'disable' => 'FORCE_DISABLED',
        'delete' => 'AUTODELETE',
        'error' => 'ERROR',
    ];

    protected function configure(): void
    {
        $this
            ->addArgument(
                'action',
                InputArgument::OPTIONAL,
                'Action: show|set|add|remove|blacklist',
                'show'
            )
            // Blacklist options
            ->addOption('words', null, InputOption::VALUE_REQUIRED, 'Blacklisted words (comma-separated)')
            ->addOption('words-ignore-feedbacks', null, InputOption::VALUE_REQUIRED, 'Ignore feedbacks for word check (0|1)')
            ->addOption('domains', null, InputOption::VALUE_REQUIRED, 'Blocked domains (comma-separated)')
            ->addOption('ips', null, InputOption::VALUE_REQUIRED, 'Blocked IPs (comma-separated)')
            ->addOption('blacklist-action', null, InputOption::VALUE_REQUIRED, 'Blacklist action (delete|deactivate)')
            // Clear blacklist options
            ->addOption('clear-words', null, InputOption::VALUE_NONE, 'Clear all blacklisted words')
            ->addOption('clear-domains', null, InputOption::VALUE_NONE, 'Clear all blocked domains')
            ->addOption('clear-ips', null, InputOption::VALUE_NONE, 'Clear all blocked IPs')
            // Duplicates
            ->addOption('duplicates-comments', null, InputOption::VALUE_REQUIRED, 'Delete comment duplicates (0|1)')
            ->addOption('duplicates-messages', null, InputOption::VALUE_REQUIRED, 'Delete message duplicates (0|1)')
            // Videos rules
            ->addOption('videos-captcha', null, InputOption::VALUE_REQUIRED, 'Force captcha for videos (count/seconds)')
            ->addOption('videos-disable', null, InputOption::VALUE_REQUIRED, 'Disable videos after (count/seconds)')
            ->addOption('videos-delete', null, InputOption::VALUE_REQUIRED, 'Delete videos after (count/seconds)')
            ->addOption('videos-error', null, InputOption::VALUE_REQUIRED, 'Show error for videos (count/seconds)')
            ->addOption('videos-history', null, InputOption::VALUE_REQUIRED, 'Analyze history for videos (all|user)')
            // Albums rules
            ->addOption('albums-captcha', null, InputOption::VALUE_REQUIRED, 'Force captcha for albums (count/seconds)')
            ->addOption('albums-disable', null, InputOption::VALUE_REQUIRED, 'Disable albums after (count/seconds)')
            ->addOption('albums-delete', null, InputOption::VALUE_REQUIRED, 'Delete albums after (count/seconds)')
            ->addOption('albums-error', null, InputOption::VALUE_REQUIRED, 'Show error for albums (count/seconds)')
            ->addOption('albums-history', null, InputOption::VALUE_REQUIRED, 'Analyze history for albums (all|user)')
            // Posts rules
            ->addOption('posts-captcha', null, InputOption::VALUE_REQUIRED, 'Force captcha for posts (count/seconds)')
            ->addOption('posts-disable', null, InputOption::VALUE_REQUIRED, 'Disable posts after (count/seconds)')
            ->addOption('posts-delete', null, InputOption::VALUE_REQUIRED, 'Delete posts after (count/seconds)')
            ->addOption('posts-error', null, InputOption::VALUE_REQUIRED, 'Show error for posts (count/seconds)')
            ->addOption('posts-history', null, InputOption::VALUE_REQUIRED, 'Analyze history for posts (all|user)')
            // Playlists rules
            ->addOption('playlists-captcha', null, InputOption::VALUE_REQUIRED, 'Force captcha for playlists (count/seconds)')
            ->addOption('playlists-disable', null, InputOption::VALUE_REQUIRED, 'Disable playlists after (count/seconds)')
            ->addOption('playlists-delete', null, InputOption::VALUE_REQUIRED, 'Delete playlists after (count/seconds)')
            ->addOption('playlists-error', null, InputOption::VALUE_REQUIRED, 'Show error for playlists (count/seconds)')
            ->addOption('playlists-history', null, InputOption::VALUE_REQUIRED, 'Analyze history for playlists (all|user)')
            // DVDs/Channels rules
            ->addOption('dvds-captcha', null, InputOption::VALUE_REQUIRED, 'Force captcha for channels (count/seconds)')
            ->addOption('dvds-disable', null, InputOption::VALUE_REQUIRED, 'Disable channels after (count/seconds)')
            ->addOption('dvds-delete', null, InputOption::VALUE_REQUIRED, 'Delete channels after (count/seconds)')
            ->addOption('dvds-error', null, InputOption::VALUE_REQUIRED, 'Show error for channels (count/seconds)')
            ->addOption('dvds-history', null, InputOption::VALUE_REQUIRED, 'Analyze history for channels (all|user)')
            // Comments rules
            ->addOption('comments-captcha', null, InputOption::VALUE_REQUIRED, 'Force captcha for comments (count/seconds)')
            ->addOption('comments-disable', null, InputOption::VALUE_REQUIRED, 'Disable comments after (count/seconds)')
            ->addOption('comments-delete', null, InputOption::VALUE_REQUIRED, 'Delete comments after (count/seconds)')
            ->addOption('comments-error', null, InputOption::VALUE_REQUIRED, 'Show error for comments (count/seconds)')
            ->addOption('comments-history', null, InputOption::VALUE_REQUIRED, 'Analyze history for comments (all|user)')
            // Messages rules (no captcha/disable)
            ->addOption('messages-delete', null, InputOption::VALUE_REQUIRED, 'Delete messages after (count/seconds)')
            ->addOption('messages-error', null, InputOption::VALUE_REQUIRED, 'Show error for messages (count/seconds)')
            ->addOption('messages-history', null, InputOption::VALUE_REQUIRED, 'Analyze history for messages (all|user)')
            // Feedbacks rules (no captcha/disable)
            ->addOption('feedbacks-delete', null, InputOption::VALUE_REQUIRED, 'Delete feedbacks after (count/seconds)')
            ->addOption('feedbacks-error', null, InputOption::VALUE_REQUIRED, 'Show error for feedbacks (count/seconds)')
            ->addOption('feedbacks-history', null, InputOption::VALUE_REQUIRED, 'Analyze history for feedbacks (all|user)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, json', 'table')
            ->setHelp(<<<'HELP'
Manage KVS anti-spam settings.

<fg=yellow>ACTIONS:</>
  show          Display current anti-spam settings (default)
  set           Replace blacklist (words, domains, IPs)
  add           Add to existing blacklist
  remove        Remove from blacklist
  blacklist     Show blacklist details

<fg=yellow>BLACKLIST OPTIONS:</>
  --words               Blacklisted words (comma-separated)
  --words-ignore-feedbacks  Ignore feedbacks (0|1)
  --domains             Blocked email domains
  --ips                 Blocked IP addresses
  --blacklist-action    Action: delete|deactivate
  --clear-words         Clear all blacklisted words
  --clear-domains       Clear all blocked domains
  --clear-ips           Clear all blocked IPs

<fg=yellow>RULE FORMAT:</>
  Rules use count/seconds format, e.g., "5/60" = 5 items within 60 seconds

<fg=yellow>SECTIONS:</>
  videos, albums, posts, playlists, dvds, comments, messages, feedbacks

<fg=yellow>RULE TYPES:</>
  captcha   Force captcha after threshold
  disable   Deactivate content after threshold
  delete    Auto-delete content after threshold
  error     Show error after threshold

<fg=yellow>EXAMPLES:</>
  <fg=green>kvs antispam show</>
  <fg=green>kvs antispam blacklist</>

  <fg=cyan># Replace entire blacklist:</>
  <fg=green>kvs antispam set --words="spam,scam,viagra"</>
  <fg=green>kvs antispam set --domains="spam.com,temp.mail"</>

  <fg=cyan># Add to existing blacklist:</>
  <fg=green>kvs antispam add --words="newspam"</>
  <fg=green>kvs antispam add --domains="bad.com" --ips="1.2.3.4"</>

  <fg=cyan># Remove from blacklist:</>
  <fg=green>kvs antispam remove --words="spam"</>
  <fg=green>kvs antispam remove --domains="spam.com"</>

  <fg=cyan># Clear blacklist:</>
  <fg=green>kvs antispam set --clear-words</>
  <fg=green>kvs antispam set --clear-domains --clear-ips</>

  <fg=cyan># Configure rules:</>
  <fg=green>kvs antispam set --comments-captcha=5/60</>
  <fg=green>kvs antispam set --blacklist-action=delete</>
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $this->getStringArgument($input, 'action');

        return match ($action) {
            'show' => $this->showSettings($input),
            'set' => $this->setSettings($input),
            'add' => $this->addToBlacklist($input),
            'remove' => $this->removeFromBlacklist($input),
            'blacklist' => $this->showBlacklist($input),
            default => $this->showSettings($input),
        };
    }

    private function showSettings(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            // Get all antispam options
            $stmt = $db->prepare("
                SELECT variable, value FROM {$this->table('options')}
                WHERE variable LIKE 'ANTISPAM_%'
            ");
            $stmt->execute();
            /** @var array<string, string> $rows */
            $rows = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

            // Get blocked domains count
            $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table('users_blocked_domains')}");
            $stmt->execute();
            $blockedDomainsCount = (int) $stmt->fetchColumn();

            // Get blocked IPs count
            $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table('users_blocked_ips')}");
            $stmt->execute();
            $blockedIpsCount = (int) $stmt->fetchColumn();

            $format = $this->getStringOption($input, 'format');

            if ($format === 'json') {
                $data = [
                    'blacklist' => [
                        'words' => $rows['ANTISPAM_BLACKLIST_WORDS'] ?? '',
                        'words_ignore_feedbacks' => ($rows['ANTISPAM_BLACKLIST_WORDS_IGNORE_FEEDBACKS'] ?? '0') === '1',
                        'domains_count' => $blockedDomainsCount,
                        'ips_count' => $blockedIpsCount,
                        'action' => ($rows['ANTISPAM_BLACKLIST_ACTION'] ?? '0') === '0' ? 'delete' : 'deactivate',
                    ],
                    'duplicates' => [
                        'comments' => ($rows['ANTISPAM_COMMENTS_DUPLICATES'] ?? '0') === '1',
                        'messages' => ($rows['ANTISPAM_MESSAGES_DUPLICATES'] ?? '0') === '1',
                    ],
                    'sections' => [],
                ];

                foreach (self::SECTIONS as $name => $prefix) {
                    $section = [
                        'analyze_history' => ($rows[$prefix . '_ANALYZE_HISTORY'] ?? '0') === '0' ? 'all' : 'user',
                    ];
                    foreach (self::ACTIONS as $actionName => $actionKey) {
                        $ruleValue = $rows[$prefix . '_' . $actionKey] ?? '0/0';
                        $section[$actionName] = $ruleValue;
                    }
                    $data['sections'][$name] = $section;
                }

                $this->io()->writeln((string) json_encode($data, JSON_PRETTY_PRINT));
                return self::SUCCESS;
            }

            // Table format
            $this->io()->section('Anti-spam Settings');

            // Blacklisting overview
            $this->io()->text('<fg=cyan>Blacklisting</>');

            $words = $rows['ANTISPAM_BLACKLIST_WORDS'] ?? '';
            $wordCount = trim($words) !== '' ? count(array_filter(explode(',', $words), static fn(string $w): bool => trim($w) !== '')) : 0;
            $ignoreFeeds = ($rows['ANTISPAM_BLACKLIST_WORDS_IGNORE_FEEDBACKS'] ?? '0') === '1';
            $action = ($rows['ANTISPAM_BLACKLIST_ACTION'] ?? '0') === '0' ? 'Delete' : 'Deactivate';

            $blacklistInfo = [
                ['Blacklisted words', $wordCount . ' word(s)'],
                ['Ignore feedbacks for words', $ignoreFeeds ? 'Yes' : 'No'],
                ['Blocked domains', $blockedDomainsCount . ' domain(s)'],
                ['Blocked IPs', $blockedIpsCount . ' IP(s)'],
                ['Blacklist action', $action],
            ];

            $this->renderTable(['Setting', 'Value'], $blacklistInfo);

            // Duplicates
            $this->io()->newLine();
            $this->io()->text('<fg=cyan>Duplicates</>');

            $dupComments = ($rows['ANTISPAM_COMMENTS_DUPLICATES'] ?? '0') === '1';
            $dupMessages = ($rows['ANTISPAM_MESSAGES_DUPLICATES'] ?? '0') === '1';

            $dupInfo = [
                ['Delete duplicate comments', $dupComments ? 'Yes' : 'No'],
                ['Delete duplicate messages', $dupMessages ? 'Yes' : 'No'],
            ];

            $this->renderTable(['Setting', 'Value'], $dupInfo);

            // Section rules
            $this->io()->newLine();
            $this->io()->text('<fg=cyan>Section Rules</>');

            $tableData = [];
            foreach (self::SECTIONS as $name => $prefix) {
                $history = ($rows[$prefix . '_ANALYZE_HISTORY'] ?? '0') === '0' ? 'All' : 'User';
                $captcha = $this->formatRule($rows[$prefix . '_FORCE_CAPTCHA'] ?? '0/0');
                $disable = $this->formatRule($rows[$prefix . '_FORCE_DISABLED'] ?? '0/0');
                $delete = $this->formatRule($rows[$prefix . '_AUTODELETE'] ?? '0/0');
                $error = $this->formatRule($rows[$prefix . '_ERROR'] ?? '0/0');

                $tableData[] = [ucfirst($name), $history, $captcha, $disable, $delete, $error];
            }

            $this->renderTable(
                ['Section', 'History', 'Captcha', 'Disable', 'Delete', 'Error'],
                $tableData
            );

            $this->io()->newLine();
            $this->io()->text('<fg=gray>Use "kvs antispam blacklist" for blacklist details</>');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch antispam settings: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function showBlacklist(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            // Get blacklisted words
            $stmt = $db->prepare("
                SELECT value FROM {$this->table('options')}
                WHERE variable = 'ANTISPAM_BLACKLIST_WORDS'
            ");
            $stmt->execute();
            $wordsRaw = $stmt->fetchColumn();
            $words = is_string($wordsRaw) && $wordsRaw !== ''
                ? array_values(array_filter(array_map('trim', explode(',', $wordsRaw)), static fn(string $w): bool => $w !== ''))
                : [];

            // Get blocked domains
            $stmt = $db->prepare("SELECT domain FROM {$this->table('users_blocked_domains')} ORDER BY sort_id");
            $stmt->execute();
            /** @var array<string> $domains */
            $domains = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            // Get blocked IPs
            $stmt = $db->prepare("SELECT ip FROM {$this->table('users_blocked_ips')} ORDER BY sort_id");
            $stmt->execute();
            /** @var array<string> $ips */
            $ips = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            $format = $this->getStringOption($input, 'format');

            if ($format === 'json') {
                $data = [
                    'words' => $words,
                    'domains' => $domains,
                    'ips' => $ips,
                ];
                $this->io()->writeln((string) json_encode($data, JSON_PRETTY_PRINT));
                return self::SUCCESS;
            }

            $this->io()->section('Blacklist Details');

            // Words
            $this->io()->text('<fg=cyan>Blacklisted Words</> (' . count($words) . ')');
            if (count($words) > 0) {
                $this->io()->text('  ' . implode(', ', $words));
            } else {
                $this->io()->text('  <fg=gray>None</>');
            }

            $this->io()->newLine();

            // Domains
            $this->io()->text('<fg=cyan>Blocked Domains</> (' . count($domains) . ')');
            if (count($domains) > 0) {
                foreach ($domains as $domain) {
                    $this->io()->text('  - ' . $domain);
                }
            } else {
                $this->io()->text('  <fg=gray>None</>');
            }

            $this->io()->newLine();

            // IPs
            $this->io()->text('<fg=cyan>Blocked IPs</> (' . count($ips) . ')');
            if (count($ips) > 0) {
                foreach ($ips as $ip) {
                    $this->io()->text('  - ' . $ip);
                }
            } else {
                $this->io()->text('  <fg=gray>None</>');
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch blacklist: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function setSettings(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $changes = [];

            // Clear blacklist options
            if ($this->getBoolOption($input, 'clear-words')) {
                $this->updateOption($db, 'ANTISPAM_BLACKLIST_WORDS', '');
                $changes[] = 'Cleared all blacklisted words';
            }

            if ($this->getBoolOption($input, 'clear-domains')) {
                $this->updateBlockedDomains($db, []);
                $changes[] = 'Cleared all blocked domains';
            }

            if ($this->getBoolOption($input, 'clear-ips')) {
                $this->updateBlockedIps($db, []);
                $changes[] = 'Cleared all blocked IPs';
            }

            // Blacklist words
            $words = $this->getStringOption($input, 'words');
            if ($words !== null) {
                $this->updateOption($db, 'ANTISPAM_BLACKLIST_WORDS', $words);
                $wordCount = count(array_filter(explode(',', $words), static fn(string $w): bool => trim($w) !== ''));
                $changes[] = "Blacklisted words: $wordCount word(s)";
            }

            // Words ignore feedbacks
            $wordsIgnore = $this->getStringOption($input, 'words-ignore-feedbacks');
            if ($wordsIgnore !== null) {
                if (!in_array($wordsIgnore, ['0', '1'], true)) {
                    $this->io()->error('Invalid value for --words-ignore-feedbacks (use: 0 or 1)');
                    return self::FAILURE;
                }
                $this->updateOption($db, 'ANTISPAM_BLACKLIST_WORDS_IGNORE_FEEDBACKS', $wordsIgnore);
                $changes[] = 'Ignore feedbacks for word check: ' . ($wordsIgnore === '1' ? 'Yes' : 'No');
            }

            // Blacklist action
            $blAction = $this->getStringOption($input, 'blacklist-action');
            if ($blAction !== null) {
                if (!in_array($blAction, ['delete', 'deactivate'], true)) {
                    $this->io()->error('Invalid value for --blacklist-action (use: delete or deactivate)');
                    return self::FAILURE;
                }
                $this->updateOption($db, 'ANTISPAM_BLACKLIST_ACTION', $blAction === 'delete' ? '0' : '1');
                $changes[] = 'Blacklist action: ' . ucfirst($blAction);
            }

            // Blocked domains
            $domains = $this->getStringOption($input, 'domains');
            if ($domains !== null) {
                $domainList = array_values(array_filter(array_map('trim', explode(',', $domains)), static fn(string $d): bool => $d !== ''));
                $this->updateBlockedDomains($db, $domainList);
                $changes[] = 'Blocked domains: ' . count($domainList) . ' domain(s)';
            }

            // Blocked IPs
            $ips = $this->getStringOption($input, 'ips');
            if ($ips !== null) {
                $ipList = array_values(array_filter(array_map('trim', explode(',', $ips)), static fn(string $ip): bool => $ip !== ''));
                $this->updateBlockedIps($db, $ipList);
                $changes[] = 'Blocked IPs: ' . count($ipList) . ' IP(s)';
            }

            // Duplicates
            $dupComments = $this->getStringOption($input, 'duplicates-comments');
            if ($dupComments !== null) {
                if (!in_array($dupComments, ['0', '1'], true)) {
                    $this->io()->error('Invalid value for --duplicates-comments (use: 0 or 1)');
                    return self::FAILURE;
                }
                $this->updateOption($db, 'ANTISPAM_COMMENTS_DUPLICATES', $dupComments);
                $changes[] = 'Delete duplicate comments: ' . ($dupComments === '1' ? 'Yes' : 'No');
            }

            $dupMessages = $this->getStringOption($input, 'duplicates-messages');
            if ($dupMessages !== null) {
                if (!in_array($dupMessages, ['0', '1'], true)) {
                    $this->io()->error('Invalid value for --duplicates-messages (use: 0 or 1)');
                    return self::FAILURE;
                }
                $this->updateOption($db, 'ANTISPAM_MESSAGES_DUPLICATES', $dupMessages);
                $changes[] = 'Delete duplicate messages: ' . ($dupMessages === '1' ? 'Yes' : 'No');
            }

            // Section rules - all sections
            foreach (self::SECTIONS as $section => $prefix) {
                // History
                $history = $this->getStringOption($input, "$section-history");
                if ($history !== null) {
                    if (!in_array($history, ['all', 'user'], true)) {
                        $this->io()->error("Invalid value for --$section-history (use: all or user)");
                        return self::FAILURE;
                    }
                    $this->updateOption($db, $prefix . '_ANALYZE_HISTORY', $history === 'all' ? '0' : '1');
                    $changes[] = ucfirst($section) . " analyze history: $history";
                }

                // Rules - messages and feedbacks only have delete/error
                $availableActions = self::ACTIONS;
                if (in_array($section, ['messages', 'feedbacks'], true)) {
                    $availableActions = ['delete' => 'AUTODELETE', 'error' => 'ERROR'];
                }

                foreach ($availableActions as $actionName => $actionKey) {
                    $rule = $this->getStringOption($input, "$section-$actionName");
                    if ($rule !== null) {
                        if (!$this->validateRule($rule)) {
                            $this->io()->error("Invalid rule format for --$section-$actionName (use: count/seconds, e.g., 5/60)");
                            return self::FAILURE;
                        }
                        $this->updateOption($db, $prefix . '_' . $actionKey, $rule);
                        $changes[] = ucfirst($section) . " $actionName: $rule";
                    }
                }
            }

            if ($changes === []) {
                $this->io()->warning('No settings to update.');
                $this->io()->text('Use options like --words, --domains, --comments-captcha, etc.');
                return self::SUCCESS;
            }

            $this->io()->success('Anti-spam settings updated:');
            foreach ($changes as $change) {
                $this->io()->text("  - $change");
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to update settings: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function updateOption(\PDO $db, string $variable, string $value): void
    {
        // Use upsert pattern in case the option doesn't exist yet
        $stmt = $db->prepare("
            INSERT INTO {$this->table('options')} (variable, value)
            VALUES (:variable, :value)
            ON DUPLICATE KEY UPDATE value = VALUES(value)
        ");
        $stmt->execute(['variable' => $variable, 'value' => $value]);
    }

    /**
     * @param array<string> $domains
     */
    private function updateBlockedDomains(\PDO $db, array $domains): void
    {
        $db->exec("DELETE FROM {$this->table('users_blocked_domains')}");

        $stmt = $db->prepare("
            INSERT INTO {$this->table('users_blocked_domains')} (domain, sort_id)
            VALUES (:domain, :sort_id)
        ");

        foreach ($domains as $i => $domain) {
            $stmt->execute(['domain' => $domain, 'sort_id' => $i]);
        }
    }

    /**
     * @param array<string> $ips
     */
    private function updateBlockedIps(\PDO $db, array $ips): void
    {
        $db->exec("DELETE FROM {$this->table('users_blocked_ips')}");

        $stmt = $db->prepare("
            INSERT INTO {$this->table('users_blocked_ips')} (ip, sort_id)
            VALUES (:ip, :sort_id)
        ");

        foreach ($ips as $i => $ip) {
            $stmt->execute(['ip' => $ip, 'sort_id' => $i]);
        }
    }

    private function validateRule(string $rule): bool
    {
        if ($rule === '0/0' || $rule === '') {
            return true;
        }

        $match = preg_match('/^\d+\/\d+$/', $rule);
        if ($match !== 1) {
            return false;
        }

        $parts = explode('/', $rule);
        return (int) $parts[0] >= 0 && (int) $parts[1] >= 0;
    }

    private function formatRule(string $rule): string
    {
        if ($rule === '0/0' || $rule === '' || $rule === '/') {
            return '<fg=gray>-</>';
        }

        $parts = explode('/', $rule);
        if (count($parts) !== 2) {
            return $rule;
        }

        $count = (int) $parts[0];
        $seconds = (int) $parts[1];

        if ($count === 0 && $seconds === 0) {
            return '<fg=gray>-</>';
        }

        return "$count / {$seconds}s";
    }

    private function addToBlacklist(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $changes = [];

            // Add words
            $words = $this->getStringOption($input, 'words');
            if ($words !== null) {
                $newWords = array_values(array_filter(array_map('trim', explode(',', $words)), static fn(string $w): bool => $w !== ''));
                if (count($newWords) > 0) {
                    $existingWords = $this->getExistingWords($db);
                    $merged = array_unique(array_merge($existingWords, $newWords));
                    $this->updateOption($db, 'ANTISPAM_BLACKLIST_WORDS', implode(',', $merged));
                    $changes[] = 'Added ' . count($newWords) . ' word(s): ' . implode(', ', $newWords);
                }
            }

            // Add domains
            $domains = $this->getStringOption($input, 'domains');
            if ($domains !== null) {
                $newDomains = array_values(array_filter(array_map('trim', explode(',', $domains)), static fn(string $d): bool => $d !== ''));
                if (count($newDomains) > 0) {
                    $existingDomains = $this->getExistingDomains($db);
                    $merged = array_unique(array_merge($existingDomains, $newDomains));
                    $this->updateBlockedDomains($db, $merged);
                    $changes[] = 'Added ' . count($newDomains) . ' domain(s): ' . implode(', ', $newDomains);
                }
            }

            // Add IPs
            $ips = $this->getStringOption($input, 'ips');
            if ($ips !== null) {
                $newIps = array_values(array_filter(array_map('trim', explode(',', $ips)), static fn(string $ip): bool => $ip !== ''));
                if (count($newIps) > 0) {
                    $existingIps = $this->getExistingIps($db);
                    $merged = array_unique(array_merge($existingIps, $newIps));
                    $this->updateBlockedIps($db, $merged);
                    $changes[] = 'Added ' . count($newIps) . ' IP(s): ' . implode(', ', $newIps);
                }
            }

            if ($changes === []) {
                $this->io()->warning('Nothing to add. Use --words, --domains, or --ips.');
                return self::SUCCESS;
            }

            $this->io()->success('Added to blacklist:');
            foreach ($changes as $change) {
                $this->io()->text("  - $change");
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to add to blacklist: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function removeFromBlacklist(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $changes = [];

            // Remove words
            $words = $this->getStringOption($input, 'words');
            if ($words !== null) {
                $toRemove = array_values(array_filter(array_map('trim', explode(',', $words)), static fn(string $w): bool => $w !== ''));
                if (count($toRemove) > 0) {
                    $existing = $this->getExistingWords($db);
                    $remaining = array_values(array_diff($existing, $toRemove));
                    $removed = array_intersect($existing, $toRemove);
                    $this->updateOption($db, 'ANTISPAM_BLACKLIST_WORDS', implode(',', $remaining));
                    if (count($removed) > 0) {
                        $changes[] = 'Removed ' . count($removed) . ' word(s): ' . implode(', ', $removed);
                    }
                }
            }

            // Remove domains
            $domains = $this->getStringOption($input, 'domains');
            if ($domains !== null) {
                $toRemove = array_values(array_filter(array_map('trim', explode(',', $domains)), static fn(string $d): bool => $d !== ''));
                if (count($toRemove) > 0) {
                    $existing = $this->getExistingDomains($db);
                    $remaining = array_values(array_diff($existing, $toRemove));
                    $removed = array_intersect($existing, $toRemove);
                    $this->updateBlockedDomains($db, $remaining);
                    if (count($removed) > 0) {
                        $changes[] = 'Removed ' . count($removed) . ' domain(s): ' . implode(', ', $removed);
                    }
                }
            }

            // Remove IPs
            $ips = $this->getStringOption($input, 'ips');
            if ($ips !== null) {
                $toRemove = array_values(array_filter(array_map('trim', explode(',', $ips)), static fn(string $ip): bool => $ip !== ''));
                if (count($toRemove) > 0) {
                    $existing = $this->getExistingIps($db);
                    $remaining = array_values(array_diff($existing, $toRemove));
                    $removed = array_intersect($existing, $toRemove);
                    $this->updateBlockedIps($db, $remaining);
                    if (count($removed) > 0) {
                        $changes[] = 'Removed ' . count($removed) . ' IP(s): ' . implode(', ', $removed);
                    }
                }
            }

            if ($changes === []) {
                $this->io()->warning('Nothing removed. Items may not exist in blacklist.');
                return self::SUCCESS;
            }

            $this->io()->success('Removed from blacklist:');
            foreach ($changes as $change) {
                $this->io()->text("  - $change");
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to remove from blacklist: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @return array<string>
     */
    private function getExistingWords(\PDO $db): array
    {
        $stmt = $db->prepare("
            SELECT value FROM {$this->table('options')}
            WHERE variable = 'ANTISPAM_BLACKLIST_WORDS'
        ");
        $stmt->execute();
        $value = $stmt->fetchColumn();

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $w): bool => $w !== ''));
    }

    /**
     * @return array<string>
     */
    private function getExistingDomains(\PDO $db): array
    {
        $stmt = $db->prepare("SELECT domain FROM {$this->table('users_blocked_domains')} ORDER BY sort_id");
        $stmt->execute();
        /** @var array<string> $domains */
        $domains = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        return $domains;
    }

    /**
     * @return array<string>
     */
    private function getExistingIps(\PDO $db): array
    {
        $stmt = $db->prepare("SELECT ip FROM {$this->table('users_blocked_ips')} ORDER BY sort_id");
        $stmt->execute();
        /** @var array<string> $ips */
        $ips = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        return $ips;
    }
}
