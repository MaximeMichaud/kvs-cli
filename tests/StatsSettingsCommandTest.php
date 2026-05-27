<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\System\StatsSettingsCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
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
        $this->assertCount(4, $params);
        $this->assertArrayNotHasKey('collect_player_stats', $params);
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
}
