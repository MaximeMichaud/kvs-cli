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

    /**
     * Option category mappings based on KVS admin/options.php pages
     * @var array<string, list<string>>
     */
    private const CATEGORIES = [
        'website' => [
            'ALBUM_', 'ALBUMS_', 'VIDEO_', 'VIDEOS_', 'SCREENSHOTS_',
            'CATEGORY_', 'TAG_', 'TAGS_', 'MODEL_', 'MODELS_',
            'DVD_', 'CS_', 'POST_', 'ROTATOR_', 'DEFAULT_',
        ],
        'memberzone' => [
            'USER_', 'TOKENS_', 'PREMIUM_', 'PRIVATE_', 'PUBLIC_',
            'AWARDS_', 'FEEDBACK_', 'LIMIT_',
        ],
        'antispam' => [
            'ANTISPAM_',
        ],
        'stats' => [
            'STATS_', 'ACTIVITY_',
        ],
        'system' => [
            'ENABLE_', 'SYSTEM_', 'API_', 'DIRECTORIES_',
            'ANTI_HOTLINK_', 'REFERER_', 'USE_',
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
  <fg=green>kvs options get ENABLE_ANTI_HOTLINK</>
  <fg=green>kvs options set ENABLE_ANTI_HOTLINK 1</>
  <fg=green>kvs options list --format=json</>

<fg=yellow>NOTE:</>
  Options are stored in the ktvs_options table as key-value pairs.
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

        $action = $this->getStringArgument($input, 'action');

        return match ($action) {
            'list' => $this->listOptions($input),
            'get' => $this->getOption($input),
            'set' => $this->setOption($input),
            default => $this->listOptions($input),
        };
    }

    private function listOptions(InputInterface $input): int
    {
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
        if ($category !== null && isset(self::CATEGORIES[$category])) {
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
            $query .= " AND variable LIKE :search";
            $params['search'] = '%' . strtoupper($search) . '%';
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

            if ($options === []) {
                $this->io()->warning('No options found matching criteria');
                return self::SUCCESS;
            }

            // Transform for display
            $options = array_map(function (array $option): array {
                $value = $option['value'];
                // Truncate long values for table display
                if (strlen($value) > 50) {
                    $option['display_value'] = substr($value, 0, 47) . '...';
                } else {
                    $option['display_value'] = $value;
                }
                // Detect category
                $option['category'] = $this->detectCategory($option['variable']);
                return $option;
            }, $options);

            $defaultFields = ['variable', 'display_value', 'category'];

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

            $this->io()->title("Option: {$option['variable']}");

            $info = [
                ['Name', $option['variable']],
                ['Value', $option['value']],
                ['Category', $this->detectCategory($option['variable'])],
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

            if (!$this->io()->confirm('Apply this change?', false)) {
                $this->io()->info('Operation cancelled');
                return self::SUCCESS;
            }

            // Update the option
            $stmt = $db->prepare(
                "UPDATE {$this->table('options')} SET value = :value WHERE variable = :name"
            );
            $stmt->execute(['value' => $value, 'name' => $name]);

            $this->io()->success("Option '$name' updated successfully");
            $this->io()->text("Changed from: $oldValue");
            $this->io()->text("Changed to: $value");

            // Warn about cache
            $this->io()->note('You may need to clear cache for changes to take effect: kvs cache clear');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to update option: ' . $e->getMessage());
            return self::FAILURE;
        }
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
