<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\System\StatsSettingsCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Tester\CommandTester;

class StatsSettingsCommandTest extends TestCase
{
    private string $kvsPath;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->kvsPath = TestHelper::createTempDir('kvs-stats-settings-test-');
        mkdir($this->kvsPath . '/admin/include', 0755, true);
        mkdir($this->kvsPath . '/admin/data/system', 0755, true);

        TestHelper::createMockDbConfig($this->kvsPath);
        file_put_contents(
            $this->kvsPath . '/admin/include/setup.php',
            '<?php $config = ["project_version" => "7.0.0", "tables_prefix" => "ktvs_"];'
        );
        file_put_contents(
            $this->kvsPath . '/admin/include/list_countries.php',
            <<<'PHP'
<?php
$list_countries['code'][1] = 'us';
$list_countries['code'][2] = 'ca';
$list_countries['code'][3] = 'gb';
PHP
        );
        $this->writeStatsParams([
            'videos_stats_limit_countries_option' => '',
            'videos_stats_limit_countries' => [],
        ]);

        $this->tester = new CommandTester(
            new StatsSettingsCommand(new Configuration(['path' => $this->kvsPath]))
        );
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->kvsPath);
    }

    public function testCountryListRequiresModeWhenNoModeIsConfigured(): void
    {
        $this->tester->execute([
            'action' => 'set',
            '--videos-countries' => 'US,CA',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();
        $this->assertSame(1, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('--videos-countries requires --videos-countries-mode=include', $display);
        $this->assertStringContainsString('--videos-countries-mode=exclude', $display);

        $params = $this->readStatsParams();
        $this->assertSame('', $params['videos_stats_limit_countries_option'] ?? null);
        $this->assertSame([], $params['videos_stats_limit_countries'] ?? null);
    }

    public function testCountryListCanReuseExistingMode(): void
    {
        $this->writeStatsParams([
            'videos_stats_limit_countries_option' => 'include',
            'videos_stats_limit_countries' => [],
        ]);

        $this->tester->execute([
            'action' => 'set',
            '--videos-countries' => 'US,CA',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();
        $this->assertSame(0, $this->tester->getStatusCode(), $display);

        $params = $this->readStatsParams();
        $this->assertSame('include', $params['videos_stats_limit_countries_option'] ?? null);
        $this->assertSame(['us', 'ca'], $params['videos_stats_limit_countries'] ?? null);
    }

    public function testCountryListRejectsCodesMissingFromKvsCountryList(): void
    {
        $this->writeStatsParams([
            'videos_stats_limit_countries_option' => 'include',
            'videos_stats_limit_countries' => ['us'],
        ]);

        $this->tester->execute([
            'action' => 'set',
            '--videos-countries' => 'US,ZZ',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();
        $this->assertSame(1, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('Invalid country code(s) for --videos-countries: ZZ', $display);

        $params = $this->readStatsParams();
        $this->assertSame('include', $params['videos_stats_limit_countries_option'] ?? null);
        $this->assertSame(['us'], $params['videos_stats_limit_countries'] ?? null);
    }

    public function testSetDefaultValuePreservesSparseStatsParamsFile(): void
    {
        $this->writeStatsParams([
            'collect_traffic_stats' => 1,
            'videos_stats_limit_countries_option' => '',
            'videos_stats_limit_countries' => [],
        ]);
        $before = $this->readStatsParams();

        $this->tester->execute([
            'action' => 'set',
            '--performance' => '0',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();
        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertSame($before, $this->readStatsParams());
    }

    public function testSetNonDefaultValueAddsOnlyThatStatsParam(): void
    {
        $this->writeStatsParams([
            'collect_traffic_stats' => 1,
            'CUSTOM_SETTING' => ['preserve' => true],
            'videos_stats_limit_countries_option' => '',
            'videos_stats_limit_countries' => [],
        ]);

        $this->tester->execute([
            'action' => 'set',
            '--performance' => '1',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();
        $params = $this->readStatsParams();
        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertSame(1, $params['collect_performance_stats'] ?? null);
        $this->assertCount(5, $params);
        $this->assertSame(['preserve' => true], $params['CUSTOM_SETTING']);
        $this->assertArrayNotHasKey('collect_player_stats', $params);
    }

    /** @return array<string, array{string}> */
    public static function statsSettingsActions(): array
    {
        return ['show' => ['show'], 'set' => ['set']];
    }

    #[DataProvider('statsSettingsActions')]
    public function testRejectsUnreadableStatsSettingsWithoutChangingFile(string $action): void
    {
        $contents = serialize(['collect_traffic_stats' => 1, 'CUSTOM_SETTING' => 'preserve']);
        file_put_contents($this->statsParamsPath(), $contents);
        chmod($this->statsParamsPath(), 0200);
        if (is_readable($this->statsParamsPath())) {
            chmod($this->statsParamsPath(), 0644);
            $this->markTestSkipped('The current user can read a write-only settings file.');
        }
        $arguments = ['action' => $action, '--force' => true];
        $arguments += $action === 'set' ? ['--traffic' => '0'] : ['--format' => 'json'];

        try {
            $this->tester->execute($arguments);

            $this->assertSame(1, $this->tester->getStatusCode(), $this->tester->getDisplay());
            $this->assertStringContainsString('Failed to read stats settings', $this->tester->getDisplay());
            $this->assertStringNotContainsString('Stats settings updated', $this->tester->getDisplay());
        } finally {
            chmod($this->statsParamsPath(), 0644);
        }
        $this->assertSame($contents, file_get_contents($this->statsParamsPath()));
    }

    /** @return array<string, array{string, string}> */
    public static function malformedStatsSettings(): array
    {
        $cases = [];
        foreach (['invalid serialization', serialize('invalid'), ''] as $index => $contents) {
            foreach (['show', 'set'] as $action) {
                $cases[$action . '-' . $index] = [$action, $contents];
            }
        }
        return $cases;
    }

    #[DataProvider('malformedStatsSettings')]
    public function testRejectsMalformedStatsSettingsWithoutChangingFile(string $action, string $contents): void
    {
        file_put_contents($this->statsParamsPath(), $contents);
        $arguments = ['action' => $action, '--force' => true];
        $arguments += $action === 'set' ? ['--traffic' => '0'] : ['--format' => 'json'];

        $this->tester->execute($arguments);

        $this->assertSame(1, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertStringContainsString('Invalid stats settings', $this->tester->getDisplay());
        $this->assertStringNotContainsString('Stats settings updated', $this->tester->getDisplay());
        $this->assertSame($contents, file_get_contents($this->statsParamsPath()));
    }

    public function testRejectsReadOnlyStatsSettingsWithoutChangingFile(): void
    {
        $contents = serialize(['collect_traffic_stats' => 1, 'CUSTOM_SETTING' => 'preserve']);
        file_put_contents($this->statsParamsPath(), $contents);
        chmod($this->statsParamsPath(), 0444);
        if (is_writable($this->statsParamsPath())) {
            chmod($this->statsParamsPath(), 0644);
            $this->markTestSkipped('The current user can write a read-only settings file.');
        }

        try {
            $this->tester->execute(['action' => 'set', '--traffic' => '0', '--force' => true]);

            $this->assertSame(1, $this->tester->getStatusCode(), $this->tester->getDisplay());
            $this->assertStringContainsString('Failed to save stats settings', $this->tester->getDisplay());
            $this->assertSame($contents, file_get_contents($this->statsParamsPath()));
        } finally {
            chmod($this->statsParamsPath(), 0644);
        }
    }

    public function testMissingStatsSettingsUseDefaultsAndCanBeCreated(): void
    {
        unlink($this->statsParamsPath());
        $this->tester->execute(['action' => 'show', '--format' => 'json', '--force' => true]);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $params = json_decode($this->tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $params['collect_traffic_stats']);
        $this->assertFileDoesNotExist($this->statsParamsPath());

        $this->tester->execute(['action' => 'set', '--traffic' => '1', '--force' => true]);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame(['collect_traffic_stats' => 1], $this->readStatsParams());
    }

    public function testPerformanceDisabledRemovesKvsDebugArtifacts(): void
    {
        $performanceDir = $this->kvsPath . '/admin/data/analysis/performance';
        self::assertTrue(mkdir($performanceDir, 0777, true));
        file_put_contents($performanceDir . '/sample.dat', 'debug');

        $overloadLog = $this->kvsPath . '/admin/logs/overload.txt';
        self::assertTrue(is_dir(dirname($overloadLog)) || mkdir(dirname($overloadLog), 0777, true));
        file_put_contents($overloadLog, 'overload');

        $this->writeStatsParams([
            'collect_performance_stats' => 1,
            'videos_stats_limit_countries_option' => '',
            'videos_stats_limit_countries' => [],
        ]);

        $this->tester->execute([
            'action' => 'set',
            '--performance' => '0',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();
        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertDirectoryDoesNotExist($performanceDir);
        $this->assertFileDoesNotExist($overloadLog);
    }

    public function testPerformanceSettingMirrorsKvsAdminNotification(): void
    {
        $db = $this->createNotificationDatabase();
        $tester = $this->createTesterWithDatabase($db);

        $tester->execute([
            'action' => 'set',
            '--performance' => '1',
            '--force' => true,
        ]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode(), $display);
        $this->assertSame(
            1,
            (int) $db->query(
                "SELECT objects FROM " . TestHelper::table('admin_notifications') .
                " WHERE notification_id = 'settings.stats.performance_debug'"
            )->fetchColumn()
        );
        $this->assertSame(
            '[]',
            $db->query(
                "SELECT details FROM " . TestHelper::table('admin_notifications') .
                " WHERE notification_id = 'settings.stats.performance_debug'"
            )->fetchColumn()
        );

        $tester->execute([
            'action' => 'set',
            '--performance' => '0',
            '--force' => true,
        ]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode(), $display);
        $this->assertSame(
            0,
            (int) $db->query(
                "SELECT COUNT(*) FROM " . TestHelper::table('admin_notifications') .
                " WHERE notification_id = 'settings.stats.performance_debug'"
            )->fetchColumn()
        );
    }

    public function testSetWritesKvsStatsSettingsAuditLog(): void
    {
        $db = $this->createNotificationDatabase();
        $tester = $this->createTesterWithDatabase($db);
        $this->writeStatsParams([
            'collect_performance_stats' => 1,
            'videos_stats_limit_countries_option' => '',
            'videos_stats_limit_countries' => [],
        ]);

        $tester->execute([
            'action' => 'set',
            '--performance' => '0',
            '--force' => true,
        ]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode(), $display);

        $row = $db->query(
            'SELECT username, action_id, object_id, object_type_id, action_details FROM ' .
            TestHelper::table('admin_audit_log')
        )->fetch();

        $this->assertIsArray($row);
        $this->assertSame('kvs-cli', $row['username']);
        $this->assertSame(223, (int) $row['action_id']);
        $this->assertSame(0, (int) $row['object_id']);
        $this->assertSame(30, (int) $row['object_type_id']);
        $this->assertSame('collect_performance_stats', $row['action_details']);
    }

    public function testRejectsUnknownAction(): void
    {
        $this->tester->execute([
            'action' => 'unknown_action',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();
        $this->assertSame(1, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('Unknown stats-settings action "unknown_action"', $display);
    }

    public function testShowRejectsInvalidFormat(): void
    {
        $this->tester->execute([
            'action' => 'show',
            '--format' => 'xml',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();
        $this->assertSame(1, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('Invalid value for --format "xml"', $display);
        $this->assertStringNotContainsString('Statistics Collection Settings', $display);
    }

    public function testShowRejectsSetOptions(): void
    {
        $this->tester->execute([
            'action' => 'show',
            '--traffic' => '0',
            '--format' => 'json',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();
        $this->assertSame(1, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('The show action does not support --traffic', $display);
    }

    public function testShowDisplaysPlayerReportingAsKvsEnum(): void
    {
        $this->writeStatsParams([
            'player_stats_reporting' => 0,
            'videos_stats_limit_countries_option' => '',
            'videos_stats_limit_countries' => [],
        ]);

        $this->tester->execute([
            'action' => 'show',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();
        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('Player reporting', $display);
        $this->assertStringContainsString('KVS', $display);
        $this->assertStringNotContainsString('Player reporting       │ No', $display);
    }

    public function testSetAcceptsKvsPlayerReportingBothValue(): void
    {
        $this->tester->execute([
            'action' => 'set',
            '--player-reporting' => '2',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();
        $params = $this->readStatsParams();
        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertSame(2, $params['player_stats_reporting'] ?? null);
        $this->assertStringContainsString('Player reporting: KVS and Google Analytics', $display);
    }

    public function testSetRejectsInvalidPlayerReportingValue(): void
    {
        $this->tester->execute([
            'action' => 'set',
            '--player-reporting' => '3',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();
        $this->assertSame(1, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('Invalid value for --player-reporting (use: 0, 1, or 2)', $display);
    }

    public function testSetRejectsFormatBeforeNoopSuccess(): void
    {
        foreach (['json', 'xml'] as $format) {
            $tester = new CommandTester(
                new StatsSettingsCommand(new Configuration(['path' => $this->kvsPath]))
            );

            $tester->execute([
                'action' => 'set',
                '--format' => $format,
                '--force' => true,
            ]);

            $display = $tester->getDisplay();
            $this->assertSame(1, $tester->getStatusCode(), "format=$format: $display");
            $this->assertStringContainsString('The set action does not support --format', $display);
            $this->assertStringNotContainsString('No settings to update', $display);
        }
    }

    public function testExperimentalPromptRefusalFails(): void
    {
        $this->tester->setInputs(['no']);
        $this->tester->execute(['action' => 'show']);

        $display = $this->tester->getDisplay();
        $this->assertSame(1, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('Command aborted.', $display);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function writeStatsParams(array $params): void
    {
        file_put_contents($this->statsParamsPath(), serialize($params));
    }

    /**
     * @return array<string, mixed>
     */
    private function readStatsParams(): array
    {
        $params = unserialize((string) file_get_contents($this->statsParamsPath()), ['allowed_classes' => false]);
        $this->assertIsArray($params);

        /** @var array<string, mixed> $params */
        return $params;
    }

    private function statsParamsPath(): string
    {
        return $this->kvsPath . '/admin/data/system/stats_params.dat';
    }

    private function createNotificationDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('admin_notifications') .
            ' (notification_id TEXT PRIMARY KEY, objects INTEGER NOT NULL DEFAULT 0, details TEXT NOT NULL DEFAULT "")'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('admin_audit_log') .
            ' (
                record_id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                username TEXT NOT NULL,
                action_id INTEGER NOT NULL,
                object_id INTEGER NOT NULL,
                object_type_id INTEGER NOT NULL,
                action_details TEXT NOT NULL,
                added_date TEXT NOT NULL
            )'
        );

        return $db;
    }

    private function createTesterWithDatabase(PDO $db): CommandTester
    {
        $command = new class (new Configuration(['path' => $this->kvsPath]), $db) extends StatsSettingsCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };

        return new CommandTester($command);
    }

    #[DataProvider('directJsonPayloads')]
    public function testDirectJsonPreservesStatsStopSymbols(string $payload, bool $decorated): void
    {
        $this->writeStatsParams(['search_stop_symbols' => $payload, 'collect_search_stats' => 1]);
        $before = $this->readStatsParams();
        $this->tester->execute(['action' => 'show', '--format' => 'json', '--force' => true], ['decorated' => $decorated]);
        $text = $this->tester->getDisplay();
        $this->assertSame(0, $this->tester->getStatusCode(), $text);
        $this->assertJson($text);
        $data = json_decode($text, true);
        $this->assertSame($payload, $data['search_stop_symbols']);
        $this->assertSame(1, $data['collect_search_stats']);
        $this->assertSame($before, $this->readStatsParams());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function directJsonPayloads(): iterable
    {
        foreach (FormatterTest::exportContractPayloads() as $name => [$payload, $format, $decorated]) {
            if ($format === 'json') {
                yield $name => [$payload, $decorated];
            }
        }
    }
}
