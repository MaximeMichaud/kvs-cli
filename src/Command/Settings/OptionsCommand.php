<?php

namespace KVS\CLI\Command\Settings;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Command\Traits\ExperimentalCommandTrait;
use KVS\CLI\Output\Formatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'settings:options',
    description: '[EXPERIMENTAL] Manage KVS system options',
    aliases: ['options', 'option']
)]
class OptionsCommand extends BaseCommand
{
    use ExperimentalCommandTrait;

    private const OUTPUT_FORMATS = ['table', 'csv', 'json', 'yaml', 'count'];
    private const LIST_ONLY_OPTIONS = ['prefix', 'category', 'search', 'with-value', 'enabled', 'disabled'];

    /**
     * Serialized KVS settings files that duplicate selected ktvs_options values.
     *
     * @var list<string>
     */
    private const MIRRORED_SETTINGS_FILES = [
        'mixed_options.dat',
        'website_ui_params.dat',
        'blocked_words.dat',
        'memberzone_params.dat',
        'hotlink_info.dat',
        'rotator.dat',
        'api.dat',
    ];

    /**
     * Option category mappings based on KVS admin/options.php pages
     * @var array<string, list<string>>
     */
    private const CATEGORIES = [
        'website' => [
            'ALBUM_', 'ALBUMS_', 'VIDEO_', 'VIDEOS_', 'SCREENSHOTS_',
            'CATEGORY_', 'TAG_', 'TAGS_', 'MODEL_', 'MODELS_',
            'DVD_', 'CS_', 'POST_', 'ROTATOR_', 'DEFAULT_',
            'USE_POST_DATE_RANDOMIZATION', 'PLAYER_',
        ],
        'memberzone' => [
            'USER_', 'TOKENS_', 'PREMIUM_', 'PRIVATE_', 'PUBLIC_',
            'AWARDS_', 'FEEDBACK_', 'LIMIT_', 'AFFILIATE_',
            'AUTO_DELETE_', 'GENERATED_USERS_', 'STATUS_AFTER_PREMIUM',
        ],
        'antispam' => [
            'ANTISPAM_',
        ],
        'stats' => [
            'STATS_', 'ACTIVITY_',
        ],
        'system' => [
            'ENABLE_', 'SYSTEM_', 'API_', 'DIRECTORIES_',
            'ANTI_HOTLINK_', 'REFERER_', 'CRON_', 'FAILED_TASKS_',
            'INITIAL_VERSION', 'UPDATE_VERSION', 'KEEP_VIDEO_SOURCE_FILES',
            'MAIN_SERVER_MIN_FREE_SPACE_MB', 'SERVER_GROUP_MIN_FREE_SPACE_MB',
        ],
    ];

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'Action: list|get|set', 'list')
            ->addArgument('name', InputArgument::OPTIONAL, 'Option name (for get/set)')
            ->addArgument('value', InputArgument::OPTIONAL, 'New value (for set)')
            ->addOption('prefix', 'p', InputOption::VALUE_REQUIRED, 'Filter by prefix (e.g., ENABLE, ANTISPAM)')
            ->addOption('category', 'c', InputOption::VALUE_REQUIRED, 'Filter by category (website|memberzone|antispam|stats|system)')
            ->addOption('search', 's', InputOption::VALUE_REQUIRED, 'Search in option names')
            ->addOption('with-value', null, InputOption::VALUE_REQUIRED, 'Filter by value (exact match)')
            ->addOption('enabled', null, InputOption::VALUE_NONE, 'Show only enabled options (value=1)')
            ->addOption('disabled', null, InputOption::VALUE_NONE, 'Show only disabled options (value=0)')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Fields to display')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count', 'table')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation of long values in table view')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Apply set changes without confirmation')
            ->setHelp(<<<'HELP'
Manage KVS system options (ktvs_options table).

<fg=yellow>ACTIONS:</>
  list              List all options (with filters)
  get <name>        Get a specific option value
  set <name> <val>  Set an option value

<fg=yellow>CATEGORIES:</>
  website           Album, video, screenshot, category, tag, model, DVD settings
  memberzone        User, tokens, premium, awards settings
  antispam          Antispam configuration
  stats             Statistics settings
  system            System-wide flags and settings (ENABLE_*, API_*, etc.)

<fg=yellow>EXAMPLES:</>
  <fg=green>kvs options list</>
  <fg=green>kvs options list --prefix=ENABLE</>
  <fg=green>kvs options list --prefix=ENABLE --enabled</>
  <fg=green>kvs options list --category=system</>
  <fg=green>kvs options list --search=AVATAR</>
  <fg=green>kvs options list --search=ACTIVITY --no-truncate</>
  <fg=green>kvs options get ENABLE_ANTI_HOTLINK</>
  <fg=green>kvs options set ENABLE_ANTI_HOTLINK 1 --yes</>
  <fg=green>kvs options list --format=json</>

<fg=yellow>NOTE:</>
  Options are stored in the ktvs_options table as key-value pairs.
  Some KVS runtime files mirror selected options and are synchronized on set.
  Long list values are truncated in table view unless --no-truncate is used.
  Use with caution - changing options can affect site behavior.
HELP
            );
        $this->configureExperimentalOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $abort = $this->confirmExperimental($input, $output);
        if ($abort !== null) {
            return $abort;
        }

        $action = $this->getStringArgument($input, 'action') ?? 'list';

        return match ($action) {
            'list' => $this->listOptions($input),
            'get' => $this->getOption($input),
            'set' => $this->setOption($input),
            default => $this->failUnknownAction('options', $action, ['list', 'get', 'set']),
        };
    }

    private function listOptions(InputInterface $input): int
    {
        if ($this->hasConflictingBoolOptions($input, ['enabled', 'disabled'])) {
            return self::FAILURE;
        }
        if ($this->validateOutputFormat($input, self::OUTPUT_FORMATS) === null) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $query = "SELECT variable, value FROM {$this->table('options')} WHERE 1=1";
        $params = [];

        // Filter by prefix
        $prefix = $this->getStringOption($input, 'prefix');
        if ($prefix !== null) {
            $prefix = strtoupper($prefix);
            if (!str_ends_with($prefix, '_')) {
                $prefix .= '_';
            }
            $query .= " AND variable LIKE :prefix";
            $params['prefix'] = $prefix . '%';
        }

        // Filter by category
        $category = $this->getStringOption($input, 'category');
        if ($category !== null) {
            $category = strtolower($category);
            if (!isset(self::CATEGORIES[$category])) {
                $this->io()->error(
                    'Invalid value for --category (use: ' . implode(', ', array_keys(self::CATEGORIES)) . ')'
                );
                return self::FAILURE;
            }
            $prefixes = self::CATEGORIES[$category];
            $conditions = [];
            foreach ($prefixes as $i => $catPrefix) {
                $paramName = "cat_prefix_$i";
                $conditions[] = "variable LIKE :$paramName";
                $params[$paramName] = $catPrefix . '%';
            }
            $query .= " AND (" . implode(' OR ', $conditions) . ")";
        }

        // Filter by search term
        $search = $this->getStringOption($input, 'search');
        if ($search !== null) {
            $query .= " AND variable LIKE :search" . $this->likeEscapeSql();
            $params['search'] = $this->containsLikePattern(strtoupper($search));
        }

        // Filter by exact value
        $withValue = $this->getStringOption($input, 'with-value');
        if ($withValue !== null) {
            $query .= " AND value = :with_value";
            $params['with_value'] = $withValue;
        }

        // Filter enabled/disabled
        if ($this->getBoolOption($input, 'enabled')) {
            $query .= " AND value = '1'";
        } elseif ($this->getBoolOption($input, 'disabled')) {
            $query .= " AND value = '0'";
        }

        $query .= " ORDER BY variable ASC";

        try {
            $stmt = $db->prepare($query);
            $stmt->execute($params);

            /** @var list<array{variable: string, value: string}> $options */
            $options = $stmt->fetchAll();
            $format = $this->getStringOptionOrDefault($input, 'format', 'table');
            $defaultFields = $format === 'table'
                ? ['variable', 'display_value', 'category']
                : ['variable', 'value', 'category'];

            if ($options === []) {
                if ($this->isTableFormat($input)) {
                    $this->io()->warning('No options found matching criteria');
                } else {
                    $formatter = new Formatter($input->getOptions(), $defaultFields);
                    $formatter->display([], $this->io());
                }
                return self::SUCCESS;
            }

            $noTruncate = $this->getBoolOption($input, 'no-truncate');

            // Transform for display
            $options = array_map(function (array $option) use ($noTruncate): array {
                $value = $option['value'];
                // Truncate long values for table display
                if (!$noTruncate && strlen($value) > 50) {
                    $option['display_value'] = substr($value, 0, 47) . '...';
                } else {
                    $option['display_value'] = $value;
                }
                // Detect category
                $option['category'] = $this->detectCategory($option['variable']);
                return $option;
            }, $options);

            $formatter = new Formatter($input->getOptions(), $defaultFields);
            $formatter->display($options, $this->io());

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch options: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function getOption(InputInterface $input): int
    {
        if ($this->rejectListOnlyOptions($input, 'get')) {
            return self::FAILURE;
        }
        if ($this->validateOutputFormat($input, self::OUTPUT_FORMATS) === null) {
            return self::FAILURE;
        }

        $name = $this->getStringArgument($input, 'name');
        if ($name === null || $name === '') {
            $this->io()->error('Option name is required');
            $this->io()->text('Usage: kvs options get <name>');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $name = strtoupper($name);

        try {
            $stmt = $db->prepare(
                "SELECT variable, value FROM {$this->table('options')} WHERE variable = :name"
            );
            $stmt->execute(['name' => $name]);
            /** @var array{variable: string, value: string}|false $option */
            $option = $stmt->fetch();

            if ($option === false) {
                $this->io()->error("Option not found: $name");

                // Suggest similar options
                $suggestions = $this->findSimilarOptions($db, $name);
                if ($suggestions !== []) {
                    $this->io()->text('Did you mean one of these?');
                    $this->io()->listing($suggestions);
                }
                return self::FAILURE;
            }

            $format = $this->getStringOptionOrDefault($input, 'format', 'table');
            $category = $this->detectCategory($option['variable']);
            $optionRow = [
                'variable' => $option['variable'],
                'value' => $option['value'],
                'category' => $category,
            ];

            if ($option['value'] === '0' || $option['value'] === '1') {
                $optionRow['status'] = $option['value'] === '1' ? 'Enabled' : 'Disabled';
            }
            if (preg_match('/^\d+x\d+$/', $option['value']) === 1) {
                $optionRow['type'] = 'Dimension (WxH)';
            }

            if ($format !== 'table') {
                $formatter = new Formatter(
                    $input->getOptions(),
                    ['variable', 'value', 'category', 'status', 'type']
                );
                $formatter->display([$optionRow], $this->io());
                return self::SUCCESS;
            }

            $this->io()->title("Option: {$option['variable']}");

            $info = [
                ['Name', $option['variable']],
                ['Value', $option['value']],
                ['Category', $category],
            ];

            // Add interpretation for boolean-like values
            if ($option['value'] === '0' || $option['value'] === '1') {
                $status = $option['value'] === '1' ? '<fg=green>Enabled</>' : '<fg=yellow>Disabled</>';
                $info[] = ['Status', $status];
            }

            // Add interpretation for size values
            if (preg_match('/^\d+x\d+$/', $option['value']) === 1) {
                $info[] = ['Type', 'Dimension (WxH)'];
            }

            $this->renderTable(['Property', 'Value'], $info);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch option: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function rejectListOnlyOptions(InputInterface $input, string $action): bool
    {
        foreach (self::LIST_ONLY_OPTIONS as $option) {
            $value = $input->getOption($option);
            if ($value === null || $value === false) {
                continue;
            }

            $this->io()->error(sprintf(
                'The %s action does not support --%s. Use list --%s to filter options.',
                $action,
                $option,
                $option
            ));
            return true;
        }

        return false;
    }

    private function setOption(InputInterface $input): int
    {
        $name = $this->getStringArgument($input, 'name');
        $value = $this->getStringArgument($input, 'value');

        if ($name === null || $name === '') {
            $this->io()->error('Option name is required');
            $this->io()->text('Usage: kvs options set <name> <value>');
            return self::FAILURE;
        }

        if ($value === null) {
            $this->io()->error('Option value is required');
            $this->io()->text('Usage: kvs options set <name> <value>');
            $this->io()->text('Use empty string "" to clear a value');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $name = strtoupper($name);

        try {
            // Check if option exists
            $stmt = $db->prepare(
                "SELECT variable, value FROM {$this->table('options')} WHERE variable = :name"
            );
            $stmt->execute(['name' => $name]);
            /** @var array{variable: string, value: string}|false $existing */
            $existing = $stmt->fetch();

            if ($existing === false) {
                $this->io()->error("Option not found: $name");
                $this->io()->note('Cannot create new options via CLI. Use KVS admin panel.');
                return self::FAILURE;
            }

            $oldValue = $existing['value'];

            // Confirm change
            $this->io()->text("Option: <info>$name</info>");
            $this->io()->text("Current value: <comment>$oldValue</comment>");
            $this->io()->text("New value: <comment>$value</comment>");
            $this->io()->newLine();

            $yes = $this->getBoolOption($input, 'yes');
            if (!$yes && !$input->isInteractive()) {
                $this->io()->error('Confirmation is required in non-interactive mode. Use --yes to apply this change.');
                return self::FAILURE;
            }

            if (!$yes && !$this->io()->confirm('Apply this change?', false)) {
                $this->io()->info('Operation cancelled');
                return self::SUCCESS;
            }

            $mirroredSettingsFiles = $this->loadMirroredSettingsFiles($name);

            // Update the option
            $stmt = $db->prepare(
                "UPDATE {$this->table('options')} SET value = :value WHERE variable = :name"
            );
            $stmt->execute(['value' => $value, 'name' => $name]);
            $syncedFiles = $this->writeMirroredSettingsFiles($mirroredSettingsFiles, $name, $value);

            $this->io()->success("Option '$name' updated successfully");
            $this->io()->text("Changed from: $oldValue");
            $this->io()->text("Changed to: $value");
            if ($syncedFiles !== []) {
                $this->io()->text('Synchronized KVS settings file(s): ' . implode(', ', $syncedFiles));
            }

            // Warn about cache
            $this->io()->note('You may need to clear cache for changes to take effect: kvs cache clear');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to update option: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @return list<array{file: string, path: string, data: array<string, mixed>}>
     */
    private function loadMirroredSettingsFiles(string $name): array
    {
        $systemDir = $this->config->getAdminPath() . '/data/system';
        $matches = [];

        foreach (self::MIRRORED_SETTINGS_FILES as $file) {
            $path = $systemDir . '/' . $file;
            if (!is_file($path)) {
                continue;
            }

            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new \RuntimeException("Cannot read mirrored KVS settings file: $path");
            }

            $data = @unserialize($contents, ['allowed_classes' => false]);
            if (!is_array($data) || !array_key_exists($name, $data)) {
                continue;
            }

            if (!is_writable($path)) {
                throw new \RuntimeException("Cannot update mirrored KVS settings file: $path");
            }

            if (is_array($data[$name])) {
                throw new \RuntimeException(
                    "Cannot safely update array-valued mirrored KVS setting '$name' in $path"
                );
            }

            /** @var array<string, mixed> $data */
            $matches[] = [
                'file' => $file,
                'path' => $path,
                'data' => $data,
            ];
        }

        return $matches;
    }

    /**
     * @param list<array{file: string, path: string, data: array<string, mixed>}> $settingsFiles
     * @return list<string>
     */
    private function writeMirroredSettingsFiles(array $settingsFiles, string $name, string $value): array
    {
        $syncedFiles = [];

        foreach ($settingsFiles as $settingsFile) {
            $data = $settingsFile['data'];
            $data[$name] = $this->castMirroredOptionValue($value, $data[$name]);

            if (file_put_contents($settingsFile['path'], serialize($data), LOCK_EX) === false) {
                throw new \RuntimeException(
                    "Cannot update mirrored KVS settings file: {$settingsFile['path']}"
                );
            }

            $syncedFiles[] = $settingsFile['file'];
        }

        return $syncedFiles;
    }

    private function castMirroredOptionValue(string $value, mixed $currentValue): mixed
    {
        if (is_int($currentValue)) {
            return (int) $value;
        }

        if (is_float($currentValue)) {
            return (float) $value;
        }

        if (is_bool($currentValue)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? ($value !== '' && $value !== '0');
        }

        return $value;
    }

    /**
     * Detect category based on option name prefix
     */
    private function detectCategory(string $variable): string
    {
        foreach (self::CATEGORIES as $category => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($variable, $prefix)) {
                    return ucfirst($category);
                }
            }
        }
        return 'Other';
    }

    /**
     * Find similar option names for suggestions
     * @return list<string>
     */
    private function findSimilarOptions(\PDO $db, string $name): array
    {
        // Extract prefix from the name
        $parts = explode('_', $name);
        if (count($parts) < 2) {
            return [];
        }

        $prefix = $parts[0] . '_';

        try {
            $stmt = $db->prepare(
                "SELECT variable FROM {$this->table('options')}
                 WHERE variable LIKE :prefix
                 ORDER BY variable
                 LIMIT 5"
            );
            $stmt->execute(['prefix' => $prefix . '%']);
            /** @var list<string> $results */
            $results = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            return $results;
        } catch (\Exception $e) {
            return [];
        }
    }
}
