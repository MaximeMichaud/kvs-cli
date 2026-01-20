<?php

namespace KVS\CLI\Command\System;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Compat\KvsCompatibility;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'system:stats-settings',
    description: 'Manage KVS statistics collection settings',
    aliases: ['stats-settings']
)]
class StatsSettingsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument(
                'action',
                InputArgument::OPTIONAL,
                'Action: show|set',
                'show'
            )
            // Traffic stats
            ->addOption('traffic', null, InputOption::VALUE_REQUIRED, 'Collect traffic stats (0|1)')
            ->addOption('traffic-countries', null, InputOption::VALUE_REQUIRED, 'Collect traffic countries (0|1)')
            ->addOption('traffic-devices', null, InputOption::VALUE_REQUIRED, 'Collect traffic devices (0|1)')
            ->addOption('traffic-embed-domains', null, InputOption::VALUE_REQUIRED, 'Collect embed domains (0|1)')
            ->addOption('traffic-keep', null, InputOption::VALUE_REQUIRED, 'Keep traffic stats (days, 0=forever)')
            // Player stats
            ->addOption('player', null, InputOption::VALUE_REQUIRED, 'Collect player stats (0|1)')
            ->addOption('player-countries', null, InputOption::VALUE_REQUIRED, 'Collect player countries (0|1)')
            ->addOption('player-devices', null, InputOption::VALUE_REQUIRED, 'Collect player devices (0|1)')
            ->addOption('player-embed-profiles', null, InputOption::VALUE_REQUIRED, 'Collect embed profiles (0|1)')
            ->addOption('player-keep', null, InputOption::VALUE_REQUIRED, 'Keep player stats (days, 0=forever)')
            ->addOption('player-reporting', null, InputOption::VALUE_REQUIRED, 'Player stats reporting (0|1)')
            // Videos stats
            ->addOption('videos', null, InputOption::VALUE_REQUIRED, 'Collect videos stats (0|1)')
            ->addOption('videos-unique', null, InputOption::VALUE_REQUIRED, 'Collect unique video views (0|1)')
            ->addOption('videos-embeds-unique', null, InputOption::VALUE_REQUIRED, 'Collect unique embed views (0|1)')
            ->addOption('videos-plays', null, InputOption::VALUE_REQUIRED, 'Collect video plays (0|1)')
            ->addOption('videos-files', null, InputOption::VALUE_REQUIRED, 'Collect video file stats (0|1)')
            ->addOption('videos-keep', null, InputOption::VALUE_REQUIRED, 'Keep videos stats (days, 0=forever)')
            ->addOption('videos-countries-mode', null, InputOption::VALUE_REQUIRED, 'Country filter mode (none|include|exclude)')
            ->addOption('videos-countries', null, InputOption::VALUE_REQUIRED, 'Country codes (comma-separated, e.g. US,CA,GB or "clear")')
            // Albums stats
            ->addOption('albums', null, InputOption::VALUE_REQUIRED, 'Collect albums stats (0|1)')
            ->addOption('albums-unique', null, InputOption::VALUE_REQUIRED, 'Collect unique album views (0|1)')
            ->addOption('albums-images', null, InputOption::VALUE_REQUIRED, 'Collect album image stats (0|1)')
            ->addOption('albums-keep', null, InputOption::VALUE_REQUIRED, 'Keep albums stats (days, 0=forever)')
            ->addOption('albums-countries-mode', null, InputOption::VALUE_REQUIRED, 'Country filter mode (none|include|exclude)')
            ->addOption('albums-countries', null, InputOption::VALUE_REQUIRED, 'Country codes (comma-separated, e.g. US,CA,GB or "clear")')
            // Memberzone stats
            ->addOption('memberzone', null, InputOption::VALUE_REQUIRED, 'Collect memberzone stats (0|1)')
            ->addOption('memberzone-video-files', null, InputOption::VALUE_REQUIRED, 'Collect memberzone video files (0|1)')
            ->addOption('memberzone-album-images', null, InputOption::VALUE_REQUIRED, 'Collect memberzone album images (0|1)')
            ->addOption('memberzone-keep', null, InputOption::VALUE_REQUIRED, 'Keep memberzone stats (days, 0=forever)')
            // Search stats
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Collect search stats (0|1)')
            ->addOption('search-keep', null, InputOption::VALUE_REQUIRED, 'Keep search stats (days, 0=forever)')
            ->addOption('search-inactive', null, InputOption::VALUE_REQUIRED, 'Mark inactive searches (0|1)')
            ->addOption('search-lowercase', null, InputOption::VALUE_REQUIRED, 'Convert searches to lowercase (0|1)')
            ->addOption('search-max-length', null, InputOption::VALUE_REQUIRED, 'Max search length (0=unlimited)')
            ->addOption('search-stop-symbols', null, InputOption::VALUE_REQUIRED, 'Stop symbols to remove from searches')
            ->addOption('search-countries-mode', null, InputOption::VALUE_REQUIRED, 'Country filter mode (none|include|exclude)')
            ->addOption('search-countries', null, InputOption::VALUE_REQUIRED, 'Country codes (comma-separated, e.g. US,CA,GB or "clear")')
            // Performance stats
            ->addOption('performance', null, InputOption::VALUE_REQUIRED, 'Collect performance stats (0|1)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, json', 'table')
            ->setHelp(<<<'HELP'
Manage KVS statistics collection settings.

<fg=yellow>ACTIONS:</>
  show          Display current stats settings (default)
  set           Update stats settings

<fg=yellow>TRAFFIC OPTIONS:</>
  --traffic               Enable traffic stats collection (0|1)
  --traffic-countries     Collect country data (0|1)
  --traffic-devices       Collect device data (0|1)
  --traffic-embed-domains Collect embed domain data (0|1)
  --traffic-keep          Retention period in days (0=forever)

<fg=yellow>PLAYER OPTIONS:</>
  --player                Enable player stats collection (0|1)
  --player-countries      Collect country data (0|1)
  --player-devices        Collect device data (0|1)
  --player-embed-profiles Collect embed profile data (0|1)
  --player-keep           Retention period in days (0=forever)
  --player-reporting      Enable player reporting (0|1)

<fg=yellow>VIDEOS OPTIONS:</>
  --videos                Enable videos stats collection (0|1)
  --videos-unique         Collect unique views (0|1)
  --videos-embeds-unique  Collect unique embed views (0|1)
  --videos-plays          Collect video plays (0|1)
  --videos-files          Collect video file stats (0|1)
  --videos-keep           Retention period in days (0=forever)
  --videos-countries-mode Country filter (none|include|exclude)
  --videos-countries      Country codes (comma-separated, e.g. US,CA,GB)

<fg=yellow>ALBUMS OPTIONS:</>
  --albums                Enable albums stats collection (0|1)
  --albums-unique         Collect unique views (0|1)
  --albums-images         Collect album image stats (0|1)
  --albums-keep           Retention period in days (0=forever)
  --albums-countries-mode Country filter (none|include|exclude)
  --albums-countries      Country codes (comma-separated, e.g. US,CA,GB)

<fg=yellow>MEMBERZONE OPTIONS:</>
  --memberzone            Enable memberzone stats (0|1)
  --memberzone-video-files  Collect video file downloads (0|1)
  --memberzone-album-images Collect album image views (0|1)
  --memberzone-keep       Retention period in days (0=forever)

<fg=yellow>SEARCH OPTIONS:</>
  --search                Enable search stats collection (0|1)
  --search-keep           Retention period in days (0=forever)
  --search-inactive       Mark inactive searches (0|1)
  --search-lowercase      Convert to lowercase (0|1)
  --search-max-length     Max length (0=unlimited)
  --search-stop-symbols   Symbols to remove
  --search-countries-mode Country filter (none|include|exclude)
  --search-countries      Country codes (comma-separated, e.g. US,CA,GB)

<fg=yellow>PERFORMANCE OPTIONS:</>
  --performance           Enable performance stats (0|1)

<fg=yellow>EXAMPLES:</>
  <fg=green>kvs stats-settings show</>
  <fg=green>kvs stats-settings show --format=json</>
  <fg=green>kvs stats-settings set --traffic=1 --traffic-countries=1</>
  <fg=green>kvs stats-settings set --videos=1 --videos-keep=30</>
  <fg=green>kvs stats-settings set --search=1 --search-lowercase=1</>
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $this->getStringArgument($input, 'action');

        return match ($action) {
            'show' => $this->showSettings($input),
            'set' => $this->setSettings($input),
            default => $this->showSettings($input),
        };
    }

    private function getStatsParamsPath(): string
    {
        return $this->config->getKvsPath() . '/admin/data/system/stats_params.dat';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadStatsParams(bool $runCompatChecks = true): array
    {
        $path = $this->getStatsParamsPath();

        if (!file_exists($path)) {
            return $this->getDefaultParams();
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return $this->getDefaultParams();
        }

        $params = @unserialize($content, ['allowed_classes' => false]);
        if (!is_array($params)) {
            return $this->getDefaultParams();
        }

        /** @var array<string, mixed> $merged */
        $merged = array_merge($this->getDefaultParams(), $params);

        // Run compatibility checks (skip for JSON output to ensure valid JSON)
        if ($runCompatChecks) {
            $this->runCompatibilityChecks($merged);
        }

        return $merged;
    }

    /**
     * Run KVS compatibility checks on loaded parameters.
     *
     * @param array<string, mixed> $params
     */
    private function runCompatibilityChecks(array &$params): void
    {
        $kvsVersion = $this->config->getKvsVersion();
        $compat = new KvsCompatibility($kvsVersion, $this->io);

        // Run all checks (version, unknown keys, backwards compat, deprecated)
        $compat->runAllChecks('stats_params', $params);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function getParamInt(array $params, string $key): int
    {
        $value = $params[$key] ?? 0;
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function getParamString(array $params, string $key): string
    {
        $value = $params[$key] ?? '';
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        return '';
    }

    /**
     * @param array<string, mixed> $params
     * @return list<string>
     */
    private function getParamArray(array $params, string $key): array
    {
        $value = $params[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $result[] = $item;
            }
        }
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function getDefaultParams(): array
    {
        return [
            'collect_traffic_stats' => 0,
            'collect_traffic_stats_countries' => 0,
            'collect_traffic_stats_devices' => 0,
            'collect_traffic_stats_embed_domains' => 0,
            'collect_player_stats' => 0,
            'collect_player_stats_countries' => 0,
            'collect_player_stats_devices' => 0,
            'collect_player_stats_embed_profiles' => 0,
            'collect_videos_stats' => 0,
            'collect_videos_stats_unique' => 0,
            'collect_videos_embeds_unique' => 0,
            'collect_videos_stats_video_plays' => 0,
            'collect_videos_stats_video_files' => 0,
            'collect_albums_stats' => 0,
            'collect_albums_stats_unique' => 0,
            'collect_albums_stats_album_images' => 0,
            'collect_memberzone_stats' => 0,
            'collect_memberzone_stats_video_files' => 0,
            'collect_memberzone_stats_album_images' => 0,
            'collect_search_stats' => 0,
            'collect_performance_stats' => 0,
            'keep_traffic_stats_period' => 0,
            'keep_player_stats_period' => 0,
            'keep_videos_stats_period' => 0,
            'keep_albums_stats_period' => 0,
            'keep_memberzone_stats_period' => 0,
            'keep_search_stats_period' => 0,
            'player_stats_reporting' => 0,
            'search_inactive' => 0,
            'search_to_lowercase' => 0,
            'search_max_length' => 0,
            'search_stop_symbols' => '',
            'videos_stats_limit_countries_option' => '',
            'videos_stats_limit_countries' => [],
            'albums_stats_limit_countries_option' => '',
            'albums_stats_limit_countries' => [],
            'search_stats_limit_countries_option' => '',
            'search_stats_limit_countries' => [],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function saveStatsParams(array $params): bool
    {
        $path = $this->getStatsParamsPath();
        $dir = dirname($path);

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                return false;
            }
        }

        $result = file_put_contents($path, serialize($params), LOCK_EX);
        return $result !== false;
    }

    private function showSettings(InputInterface $input): int
    {
        $format = $this->getStringOption($input, 'format');

        // Load params without compat warnings for JSON (to ensure valid JSON output)
        $params = $this->loadStatsParams($format !== 'json');

        if ($format === 'json') {
            $this->io()->writeln((string) json_encode($params, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->io()->section('Statistics Collection Settings');

        // Traffic stats
        $this->io()->text('<fg=cyan>Traffic Stats</>');
        $trafficData = [
            ['Collect traffic stats', $this->formatBool($this->getParamInt($params, 'collect_traffic_stats'))],
            ['Collect countries', $this->formatBool($this->getParamInt($params, 'collect_traffic_stats_countries'))],
            ['Collect devices', $this->formatBool($this->getParamInt($params, 'collect_traffic_stats_devices'))],
            ['Collect embed domains', $this->formatBool($this->getParamInt($params, 'collect_traffic_stats_embed_domains'))],
            ['Retention period', $this->formatRetention($this->getParamInt($params, 'keep_traffic_stats_period'))],
        ];
        $this->renderTable(['Setting', 'Value'], $trafficData);

        // Player stats
        $this->io()->newLine();
        $this->io()->text('<fg=cyan>Player Stats</>');
        $playerData = [
            ['Collect player stats', $this->formatBool($this->getParamInt($params, 'collect_player_stats'))],
            ['Collect countries', $this->formatBool($this->getParamInt($params, 'collect_player_stats_countries'))],
            ['Collect devices', $this->formatBool($this->getParamInt($params, 'collect_player_stats_devices'))],
            ['Collect embed profiles', $this->formatBool($this->getParamInt($params, 'collect_player_stats_embed_profiles'))],
            ['Retention period', $this->formatRetention($this->getParamInt($params, 'keep_player_stats_period'))],
            ['Player reporting', $this->formatBool($this->getParamInt($params, 'player_stats_reporting'))],
        ];
        $this->renderTable(['Setting', 'Value'], $playerData);

        // Videos stats
        $this->io()->newLine();
        $this->io()->text('<fg=cyan>Videos Stats</>');
        $videosCountriesMode = $this->getParamString($params, 'videos_stats_limit_countries_option');
        $videosCountries = $this->getParamArray($params, 'videos_stats_limit_countries');
        $videosData = [
            ['Collect videos stats', $this->formatBool($this->getParamInt($params, 'collect_videos_stats'))],
            ['Collect unique views', $this->formatBool($this->getParamInt($params, 'collect_videos_stats_unique'))],
            ['Collect unique embeds', $this->formatBool($this->getParamInt($params, 'collect_videos_embeds_unique'))],
            ['Collect video plays', $this->formatBool($this->getParamInt($params, 'collect_videos_stats_video_plays'))],
            ['Collect video files', $this->formatBool($this->getParamInt($params, 'collect_videos_stats_video_files'))],
            ['Retention period', $this->formatRetention($this->getParamInt($params, 'keep_videos_stats_period'))],
            ['Country filter', $this->formatCountryFilter($videosCountriesMode, $videosCountries)],
        ];
        $this->renderTable(['Setting', 'Value'], $videosData);

        // Albums stats
        $this->io()->newLine();
        $this->io()->text('<fg=cyan>Albums Stats</>');
        $albumsCountriesMode = $this->getParamString($params, 'albums_stats_limit_countries_option');
        $albumsCountries = $this->getParamArray($params, 'albums_stats_limit_countries');
        $albumsData = [
            ['Collect albums stats', $this->formatBool($this->getParamInt($params, 'collect_albums_stats'))],
            ['Collect unique views', $this->formatBool($this->getParamInt($params, 'collect_albums_stats_unique'))],
            ['Collect album images', $this->formatBool($this->getParamInt($params, 'collect_albums_stats_album_images'))],
            ['Retention period', $this->formatRetention($this->getParamInt($params, 'keep_albums_stats_period'))],
            ['Country filter', $this->formatCountryFilter($albumsCountriesMode, $albumsCountries)],
        ];
        $this->renderTable(['Setting', 'Value'], $albumsData);

        // Memberzone stats
        $this->io()->newLine();
        $this->io()->text('<fg=cyan>Memberzone Stats</>');
        $memberzoneData = [
            ['Collect memberzone stats', $this->formatBool($this->getParamInt($params, 'collect_memberzone_stats'))],
            ['Collect video files', $this->formatBool($this->getParamInt($params, 'collect_memberzone_stats_video_files'))],
            ['Collect album images', $this->formatBool($this->getParamInt($params, 'collect_memberzone_stats_album_images'))],
            ['Retention period', $this->formatRetention($this->getParamInt($params, 'keep_memberzone_stats_period'))],
        ];
        $this->renderTable(['Setting', 'Value'], $memberzoneData);

        // Search stats
        $this->io()->newLine();
        $this->io()->text('<fg=cyan>Search Stats</>');
        $searchMaxLen = $this->getParamInt($params, 'search_max_length');
        $searchStopSymbols = $this->getParamString($params, 'search_stop_symbols');
        $searchCountriesMode = $this->getParamString($params, 'search_stats_limit_countries_option');
        $searchCountries = $this->getParamArray($params, 'search_stats_limit_countries');
        $searchData = [
            ['Collect search stats', $this->formatBool($this->getParamInt($params, 'collect_search_stats'))],
            ['Retention period', $this->formatRetention($this->getParamInt($params, 'keep_search_stats_period'))],
            ['Mark inactive', $this->formatBool($this->getParamInt($params, 'search_inactive'))],
            ['Convert to lowercase', $this->formatBool($this->getParamInt($params, 'search_to_lowercase'))],
            ['Max length', $searchMaxLen === 0 ? 'Unlimited' : (string) $searchMaxLen],
            ['Stop symbols', $searchStopSymbols !== '' ? $searchStopSymbols : '<fg=gray>None</>'],
            ['Country filter', $this->formatCountryFilter($searchCountriesMode, $searchCountries)],
        ];
        $this->renderTable(['Setting', 'Value'], $searchData);

        // Performance stats
        $this->io()->newLine();
        $this->io()->text('<fg=cyan>Performance Stats</>');
        $perfData = [
            ['Collect performance stats', $this->formatBool($this->getParamInt($params, 'collect_performance_stats'))],
        ];
        $this->renderTable(['Setting', 'Value'], $perfData);

        return self::SUCCESS;
    }

    private function setSettings(InputInterface $input): int
    {
        $params = $this->loadStatsParams();
        $changes = [];

        // Process boolean options
        $result = $this->processBoolOptions($input, $params, $changes);
        if ($result !== null) {
            return $result;
        }

        // Process integer (retention) options
        $result = $this->processIntOptions($input, $params, $changes);
        if ($result !== null) {
            return $result;
        }

        // Process country filter options
        $result = $this->processCountryOptions($input, $params, $changes);
        if ($result !== null) {
            return $result;
        }

        // Process special string options
        $this->processStringOptions($input, $params, $changes);

        // Validate countries without mode
        $this->warnCountriesWithoutMode($input, $params);

        if ($changes === []) {
            $this->io()->warning('No settings to update.');
            $this->io()->text('Use options like --traffic=1, --videos=1, --search-keep=30, etc.');
            return self::SUCCESS;
        }

        if (!$this->saveStatsParams($params)) {
            $this->io()->error('Failed to save stats settings. Check file permissions.');
            return self::FAILURE;
        }

        $this->io()->success('Stats settings updated:');
        foreach ($changes as $change) {
            $this->io()->text("  - $change");
        }

        return self::SUCCESS;
    }

    /**
     * Process boolean options.
     *
     * @param array<string, mixed> $params
     * @param list<string> $changes
     */
    private function processBoolOptions(InputInterface $input, array &$params, array &$changes): ?int
    {
        $boolOptions = [
            'traffic' => ['collect_traffic_stats', 'Traffic stats'],
            'traffic-countries' => ['collect_traffic_stats_countries', 'Traffic countries'],
            'traffic-devices' => ['collect_traffic_stats_devices', 'Traffic devices'],
            'traffic-embed-domains' => ['collect_traffic_stats_embed_domains', 'Traffic embed domains'],
            'player' => ['collect_player_stats', 'Player stats'],
            'player-countries' => ['collect_player_stats_countries', 'Player countries'],
            'player-devices' => ['collect_player_stats_devices', 'Player devices'],
            'player-embed-profiles' => ['collect_player_stats_embed_profiles', 'Player embed profiles'],
            'player-reporting' => ['player_stats_reporting', 'Player reporting'],
            'videos' => ['collect_videos_stats', 'Videos stats'],
            'videos-unique' => ['collect_videos_stats_unique', 'Videos unique'],
            'videos-embeds-unique' => ['collect_videos_embeds_unique', 'Videos embeds unique'],
            'videos-plays' => ['collect_videos_stats_video_plays', 'Videos plays'],
            'videos-files' => ['collect_videos_stats_video_files', 'Videos files'],
            'albums' => ['collect_albums_stats', 'Albums stats'],
            'albums-unique' => ['collect_albums_stats_unique', 'Albums unique'],
            'albums-images' => ['collect_albums_stats_album_images', 'Albums images'],
            'memberzone' => ['collect_memberzone_stats', 'Memberzone stats'],
            'memberzone-video-files' => ['collect_memberzone_stats_video_files', 'Memberzone video files'],
            'memberzone-album-images' => ['collect_memberzone_stats_album_images', 'Memberzone album images'],
            'search' => ['collect_search_stats', 'Search stats'],
            'search-inactive' => ['search_inactive', 'Search inactive'],
            'search-lowercase' => ['search_to_lowercase', 'Search lowercase'],
            'performance' => ['collect_performance_stats', 'Performance stats'],
        ];

        foreach ($boolOptions as $option => [$paramKey, $label]) {
            $value = $this->getStringOption($input, $option);
            if ($value === null) {
                continue;
            }
            if (!$this->validateBool($value)) {
                $this->io()->error("Invalid value for --$option (use: 0 or 1)");
                return self::FAILURE;
            }
            $params[$paramKey] = (int) $value;
            $changes[] = "$label: " . $this->formatBool((int) $value);
        }

        return null;
    }

    /**
     * Process integer (retention) options.
     *
     * @param array<string, mixed> $params
     * @param list<string> $changes
     */
    private function processIntOptions(InputInterface $input, array &$params, array &$changes): ?int
    {
        $intOptions = [
            'traffic-keep' => ['keep_traffic_stats_period', 'Traffic retention', true],
            'player-keep' => ['keep_player_stats_period', 'Player retention', true],
            'videos-keep' => ['keep_videos_stats_period', 'Videos retention', true],
            'albums-keep' => ['keep_albums_stats_period', 'Albums retention', true],
            'memberzone-keep' => ['keep_memberzone_stats_period', 'Memberzone retention', true],
            'search-keep' => ['keep_search_stats_period', 'Search retention', true],
            'search-max-length' => ['search_max_length', 'Search max length', false],
        ];

        foreach ($intOptions as $option => [$paramKey, $label, $isRetention]) {
            $value = $this->getStringOption($input, $option);
            if ($value === null) {
                continue;
            }
            if (!$this->validateInt($value)) {
                $this->io()->error("Invalid value for --$option (use: integer >= 0)");
                return self::FAILURE;
            }
            $params[$paramKey] = (int) $value;
            if ($isRetention) {
                $changes[] = "$label: " . $this->formatRetention((int) $value);
            } else {
                $changes[] = "$label: " . ((int) $value === 0 ? 'Unlimited' : $value);
            }
        }

        return null;
    }

    /**
     * Process country filter options.
     *
     * @param array<string, mixed> $params
     * @param list<string> $changes
     */
    private function processCountryOptions(InputInterface $input, array &$params, array &$changes): ?int
    {
        $countryModeOptions = [
            'videos-countries-mode' => ['videos_stats_limit_countries_option', 'Videos country mode'],
            'albums-countries-mode' => ['albums_stats_limit_countries_option', 'Albums country mode'],
            'search-countries-mode' => ['search_stats_limit_countries_option', 'Search country mode'],
        ];

        foreach ($countryModeOptions as $option => [$paramKey, $label]) {
            $value = $this->getStringOption($input, $option);
            if ($value === null) {
                continue;
            }
            if (!$this->validateCountryMode($value)) {
                $this->io()->error("Invalid value for --$option (use: none, include, or exclude)");
                return self::FAILURE;
            }
            $params[$paramKey] = $value === 'none' ? '' : $value;
            $changes[] = "$label: " . ($value === 'none' ? 'None' : ucfirst($value));
        }

        $countryListOptions = [
            'videos-countries' => ['videos_stats_limit_countries', 'Videos countries'],
            'albums-countries' => ['albums_stats_limit_countries', 'Albums countries'],
            'search-countries' => ['search_stats_limit_countries', 'Search countries'],
        ];

        foreach ($countryListOptions as $option => [$paramKey, $label]) {
            $value = $this->getStringOption($input, $option);
            if ($value === null) {
                continue;
            }
            if (strtolower($value) === 'clear') {
                $params[$paramKey] = [];
                $changes[] = "$label: Cleared";
            } else {
                $params[$paramKey] = $this->parseCountryCodes($value);
                $changes[] = "$label: $value";
            }
        }

        return null;
    }

    /**
     * Process special string options.
     *
     * @param array<string, mixed> $params
     * @param list<string> $changes
     */
    private function processStringOptions(InputInterface $input, array &$params, array &$changes): void
    {
        $searchStopSymbols = $this->getStringOption($input, 'search-stop-symbols');
        if ($searchStopSymbols !== null) {
            if (strtolower($searchStopSymbols) === 'clear') {
                $params['search_stop_symbols'] = '';
                $changes[] = 'Search stop symbols: Cleared';
            } else {
                $params['search_stop_symbols'] = $searchStopSymbols;
                $changes[] = 'Search stop symbols: ' . $searchStopSymbols;
            }
        }
    }

    /**
     * Warn if countries specified without mode.
     *
     * @param array<string, mixed> $params
     */
    private function warnCountriesWithoutMode(InputInterface $input, array $params): void
    {
        $countryWarnings = [
            ['videos-countries', 'videos-countries-mode', 'videos_stats_limit_countries_option'],
            ['albums-countries', 'albums-countries-mode', 'albums_stats_limit_countries_option'],
            ['search-countries', 'search-countries-mode', 'search_stats_limit_countries_option'],
        ];

        foreach ($countryWarnings as [$countriesOpt, $modeOpt, $paramKey]) {
            $countries = $this->getStringOption($input, $countriesOpt);
            $mode = $this->getStringOption($input, $modeOpt);
            $paramValue = $params[$paramKey] ?? '';
            $paramIsEmpty = !is_string($paramValue) || $paramValue === '';

            if ($countries !== null && strtolower($countries) !== 'clear' && $mode === null && $paramIsEmpty) {
                $this->io()->warning("--$countriesOpt specified without --$modeOpt. Countries will be ignored by KVS.");
                $this->io()->text("Use: --$modeOpt=include or --$modeOpt=exclude");
            }
        }
    }

    private function validateBool(string $value): bool
    {
        return in_array($value, ['0', '1'], true);
    }

    private function validateInt(string $value): bool
    {
        // Only accept non-negative integers (not floats like 1.5 or scientific notation like 1e3)
        return ctype_digit($value) || $value === '0';
    }

    private function validateCountryMode(string $value): bool
    {
        return in_array($value, ['none', 'include', 'exclude'], true);
    }

    /**
     * @return list<string>
     */
    private function parseCountryCodes(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $codes = array_map('trim', explode(',', $value));
        $result = [];
        foreach ($codes as $code) {
            $code = strtoupper($code);
            if ($code !== '' && strlen($code) === 2) {
                $result[] = $code;
            }
        }
        return $result;
    }

    private function formatBool(int $value): string
    {
        return $value === 1 ? '<fg=green>Yes</>' : '<fg=yellow>No</>';
    }

    private function formatRetention(int $days): string
    {
        if ($days === 0) {
            return '<fg=cyan>Forever</>';
        }
        return "$days days";
    }

    /**
     * @param list<string> $countries
     */
    private function formatCountryFilter(string $mode, array $countries): string
    {
        if ($mode === '' || $countries === []) {
            return '<fg=gray>None</>';
        }

        $countriesList = implode(', ', $countries);
        if ($mode === 'include') {
            return "<fg=green>Include:</> $countriesList";
        }
        if ($mode === 'exclude') {
            return "<fg=yellow>Exclude:</> $countriesList";
        }

        return '<fg=gray>None</>';
    }
}
