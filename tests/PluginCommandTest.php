<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\PluginCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

/**
 * Comprehensive tests for PluginCommand covering all functionality:
 * - Structure & metadata validation
 * - list (with filters: --status, --type, --fields, --field, --format)
 * - show
 * - path
 * - status
 * - Output formats (table, csv, json, yaml, count)
 * - Integration with real KVS
 *
 * Total: 30+ tests with 100+ assertions
 */
class PluginCommandTest extends TestCase
{
    private Configuration $config;
    private PluginCommand $command;
    private CommandTester $tester;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = TestHelper::createTempDir('kvs-plugin-test-');
        TestHelper::createMockKvsInstallation($this->tempDir, ['project_version' => '6.3.2']);
        $this->createPluginFixture(
            'backup',
            'Backup',
            'Kernel Team',
            '1.0.0',
            '6.0.0',
            'manual,cron',
            'Backup plugin',
            'Create and restore backups.'
        );
        $this->createPluginFixture(
            'analytics',
            'Analytics',
            'Kernel Team',
            '1.1.0',
            '6.0.0',
            'manual',
            'Analytics plugin',
            'Track internal statistics.',
            true
        );
        $this->createPluginFixture(
            'digiregs',
            'DigiRegs',
            'Kernel Team',
            '1.5',
            '6.0.0',
            'api,process_object',
            'DigiRegs',
            'Validate content records.'
        );
        $this->createGuardedPluginFixture();
        $this->createPluginFixture(
            'awe_black_label',
            'AWE Red Label',
            'Kernel Team',
            'del',
            '6.0.0',
            'manual',
            'AWE Red Label',
            'Deleted internal plugin.'
        );

        $this->config = new Configuration([
            'path' => $this->tempDir,
            'disable_db_env_overrides' => true,
        ]);
        $this->command = new PluginCommand($this->config);

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        if (isset($this->tempDir)) {
            TestHelper::removeDir($this->tempDir);
        }
    }

    // ========================================
    // STRUCTURE AND METADATA TESTS (5 tests)
    // ========================================

    public function testCommandMetadata(): void
    {
        $this->assertEquals('plugin', $this->command->getName());
        $this->assertStringContainsString('plugin', strtolower($this->command->getDescription()));

        $aliases = $this->command->getAliases();
        $this->assertContains('plugins', $aliases);
        $this->assertContains('plug', $aliases);
        $this->assertCount(2, $aliases, 'Should have exactly 2 aliases');
    }

    public function testCommandHasAllOptions(): void
    {
        $definition = $this->command->getDefinition();

        // Filter options
        $this->assertTrue($definition->hasOption('status'), 'Should have --status option');
        $this->assertTrue($definition->hasOption('type'), 'Should have --type option');

        // Formatter options
        $this->assertTrue($definition->hasOption('fields'), 'Should have --fields option');
        $this->assertTrue($definition->hasOption('field'), 'Should have --field option');
        $this->assertTrue($definition->hasOption('format'), 'Should have --format option');
    }

    public function testHelpDocumentationCompleteness(): void
    {
        $help = $this->command->getHelp();

        // Verify all actions are documented
        $this->assertStringContainsString('list', $help);
        $this->assertStringContainsString('show', $help);
        $this->assertStringContainsString('path', $help);
        $this->assertStringContainsString('status', $help);

        // Verify options are documented
        $this->assertStringContainsString('--status', $help);
        $this->assertStringContainsString('--type', $help);
        $this->assertStringContainsString('--fields', $help);
        $this->assertStringContainsString('--field', $help);
        $this->assertStringContainsString('--format', $help);

        // Verify status values
        $this->assertStringContainsString('active', $help);
        $this->assertStringContainsString('inactive', $help);

        // Verify type values
        $this->assertStringContainsString('manual', $help);
        $this->assertStringContainsString('cron', $help);
        $this->assertStringContainsString('api', $help);
        $this->assertStringContainsString('process_object', $help);

        // Verify examples exist
        $this->assertStringContainsString('EXAMPLES', $help);
        $this->assertStringContainsString('kvs plugin', $help);

        // Verify read-only note
        $this->assertStringContainsString('read-only', $help);
        $this->assertStringContainsString('admin panel', $help);
    }

    public function testFieldAvailabilityValidation(): void
    {
        $help = $this->command->getHelp();

        // Verify available fields section exists
        $this->assertStringContainsString('AVAILABLE FIELDS', $help);

        // Verify all documented fields
        $expectedFields = ['id', 'name', 'author', 'version', 'kvs_version',
                          'status', 'enabled', 'types', 'title', 'description'];

        foreach ($expectedFields as $field) {
            $this->assertStringContainsString(
                $field,
                $help,
                "Field '$field' should be documented in help"
            );
        }
    }

    public function testPluginValidationMethodsExist(): void
    {
        $reflection = new \ReflectionClass(PluginCommand::class);

        // Test validation methods exist
        $this->assertTrue($reflection->hasMethod('checkPhpSyntax'));
        $this->assertTrue($reflection->hasMethod('checkVersionCompatibility'));
        $this->assertTrue($reflection->hasMethod('checkPluginEnabled'));

        // Verify they are private
        $this->assertTrue($reflection->getMethod('checkPhpSyntax')->isPrivate());
        $this->assertTrue($reflection->getMethod('checkVersionCompatibility')->isPrivate());
        $this->assertTrue($reflection->getMethod('checkPluginEnabled')->isPrivate());
    }

    // ========================================
    // LIST COMMAND TESTS (8 tests)
    // ========================================

    public function testListWithoutFilters(): void
    {
        $exitCode = $this->tester->execute(['action' => 'list']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode, 'List should succeed');

        // Default table format should show headers
        $this->assertStringContainsString('Id', $output);
        $this->assertStringContainsString('Name', $output);
        $this->assertStringContainsString('Version', $output);
        $this->assertStringContainsString('Status', $output);
        $this->assertStringContainsString('Types', $output);
    }

    public function testListWithStatusActive(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'list',
            '--status' => 'active'
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Name', $output);
    }

    public function testListWithStatusInactive(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'list',
            '--status' => 'inactive'
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Name', $output);
    }

    public function testListWithTypeManual(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'list',
            '--type' => 'manual'
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode);

        // Should show plugins that have 'manual' in their types
        // Example: backup plugin has types "manual,cron"
        $this->assertStringContainsString('Name', $output);
    }

    public function testListWithTypeCron(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'list',
            '--type' => 'cron'
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Name', $output);
    }

    public function testListWithTypeApi(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'list',
            '--type' => 'api',
            '--format' => 'json',
            '--fields' => 'id,types',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $ids = array_column($rows, 'id');

        $this->assertEquals(0, $exitCode);
        $this->assertContains('digiregs', $ids);
    }

    public function testListWithTypeProcessObject(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'list',
            '--type' => 'process_object',
            '--format' => 'json',
            '--fields' => 'id,types',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $ids = array_column($rows, 'id');

        $this->assertEquals(0, $exitCode);
        $this->assertContains('digiregs', $ids);
    }

    public function testListWithFields(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'list',
            '--fields' => 'id,name,version'
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode);

        // Should only show specified fields
        $this->assertStringContainsString('Id', $output);
        $this->assertStringContainsString('Name', $output);
        $this->assertStringContainsString('Version', $output);
    }

    public function testListRejectsUnknownFieldsWithoutRawFormatterException(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'list',
            '--fields' => 'definitely_bad',
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('Unknown field(s): definitely_bad', $output);
        $this->assertStringNotContainsString('In Formatter.php line', $output);
    }

    public function testListWithField(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'list',
            '--field' => 'id'
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode);

        // Should output only field values, no headers
        // Should NOT contain table headers
        $this->assertStringNotContainsString('Id', $output);
        $this->assertStringNotContainsString('Name', $output);

        // Output should be simple list of IDs (plugin names)
        $lines = array_filter(explode("\n", trim($output)));
        foreach ($lines as $line) {
            $this->assertNotEmpty(trim($line), 'Each line should contain plugin ID');
        }
    }

    public function testListWithFormatCount(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'list',
            '--format' => 'count'
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode);

        // Should output only a number
        $count = trim($output);
        $this->assertIsNumeric($count, 'Count format should output only a number');
        $this->assertGreaterThan(0, (int)$count, 'Should have at least 1 plugin');
    }

    public function testListDoesNotExecutePluginPhpFiles(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'list',
            '--format' => 'json',
            '--fields' => 'id,status',
        ]);

        $output = $this->tester->getDisplay();
        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $ids = array_column($rows, 'id');

        $this->assertEquals(0, $exitCode);
        $this->assertContains('guarded_plugin', $ids);
        $this->assertStringNotContainsString('Access denied', $output);
    }

    public function testListSkipsPluginsHiddenByKvsAdmin(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'list',
            '--format' => 'json',
            '--fields' => 'id',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $ids = array_column($rows, 'id');

        $this->assertEquals(0, $exitCode);
        $this->assertNotContains('awe_black_label', $ids);
    }

    public function testListRejectsIgnoredPluginIdArgument(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'list',
            'id' => 'backup',
            '--format' => 'json',
            '--fields' => 'id,status',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode, $output);
        $this->assertStringContainsString('list action does not support a plugin ID', $output);
        $this->assertStringNotContainsString('"id": "backup"', $output);
    }

    // ========================================
    // SHOW COMMAND TESTS (3 tests)
    // ========================================

    public function testShowExistingPlugin(): void
    {
        // Use 'backup' plugin which should exist in KVS
        $exitCode = $this->tester->execute([
            'action' => 'show',
            'id' => 'backup'
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode);

        // Should display plugin details
        $this->assertStringContainsString('Plugin:', $output);
        $this->assertStringContainsString('Backup', $output);
        $this->assertStringContainsString('ID', $output);
        $this->assertStringContainsString('Name', $output);
        $this->assertStringContainsString('Author', $output);
        $this->assertStringContainsString('Version', $output);
        $this->assertStringContainsString('Required KVS', $output);
        $this->assertStringContainsString('Status', $output);
        $this->assertStringContainsString('Types', $output);
        $this->assertStringContainsString('Kernel Team', $output);

        // Should show paths section
        $this->assertStringContainsString('Paths', $output);
        $this->assertStringContainsString('Plugin directory:', $output);
        $this->assertStringContainsString('Main file:', $output);
        $this->assertStringContainsString('Template:', $output);
        $this->assertStringContainsString('Metadata:', $output);
    }

    public function testShowExistingPluginSupportsJsonFormat(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'show',
            'id' => 'backup',
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();
        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $exitCode);
        $this->assertSame('backup', $rows[0]['id'] ?? null);
        $this->assertSame('Backup', $rows[0]['name'] ?? null);
        $this->assertSame('Kernel Team', $rows[0]['author'] ?? null);
        $this->assertStringContainsString('/admin/plugins/backup', $rows[0]['path'] ?? '');
        $this->assertStringNotContainsString('Plugin:', $output);
    }

    public function testShowHonorsFieldSelectionInTableFormat(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'show',
            'id' => 'backup',
            '--field' => 'name',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(0, $exitCode, $output);
        $this->assertSame("Backup\n", $output);
        $this->assertStringNotContainsString('Plugin: Backup', $output);
    }

    public function testShowUsesDataFileForDynamicEnabledStatus(): void
    {
        $this->createDataBackedPluginFixture('dynamic_status', true);

        $exitCode = $this->tester->execute([
            'action' => 'show',
            'id' => 'dynamic_status',
            '--format' => 'json',
            '--fields' => 'id,status',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $exitCode, $this->tester->getDisplay());
        $this->assertSame('dynamic_status', $rows[0]['id'] ?? null);
        $this->assertSame('Active', $rows[0]['status'] ?? null);
    }

    public function testShowRejectsListFilters(): void
    {
        foreach (['status' => 'active', 'type' => 'api'] as $option => $value) {
            $exitCode = $this->tester->execute([
                'action' => 'show',
                'id' => 'backup',
                '--' . $option => $value,
            ]);
            $output = $this->tester->getDisplay();

            $this->assertEquals(1, $exitCode, $output);
            $this->assertStringContainsString("show action does not support --$option", $output);
            $this->assertStringNotContainsString('"id": "backup"', $output);
        }
    }

    public function testShowWithoutId(): void
    {
        $exitCode = $this->tester->execute(['action' => 'show']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode, 'Should fail without plugin ID');
        $this->assertStringContainsString('required', strtolower($output));
        $this->assertStringContainsString('Usage:', $output);
    }

    public function testShowNonExistentPlugin(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'show',
            'id' => 'nonexistent_plugin_999'
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode, 'Should fail for non-existent plugin');
        $this->assertStringContainsString('not found', strtolower($output));
        $this->assertStringContainsString('nonexistent_plugin_999', $output);
    }

    // ========================================
    // PATH COMMAND TESTS (3 tests)
    // ========================================

    public function testPathForExistingPlugin(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'path',
            'id' => 'backup'
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode);

        // Should output only the path
        $path = trim($output);
        $this->assertStringContainsString('/admin/plugins/backup', $path);
        $this->assertDirectoryExists($path, 'Plugin directory should exist');
    }

    public function testPathForExistingPluginSupportsJsonFormat(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'path',
            'id' => 'backup',
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();
        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $exitCode);
        $this->assertSame('backup', $rows[0]['id'] ?? null);
        $this->assertStringContainsString('/admin/plugins/backup', $rows[0]['path'] ?? '');
        $this->assertStringNotContainsString('Plugin directory:', $output);
    }

    public function testPathHonorsFieldSelectionInTableFormat(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'path',
            'id' => 'backup',
            '--fields' => 'id',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('Id', $output);
        $this->assertStringContainsString('backup', $output);
        $this->assertStringNotContainsString('/admin/plugins/backup', $output);
    }

    public function testPathRejectsListFilters(): void
    {
        foreach (['status' => 'active', 'type' => 'api'] as $option => $value) {
            $exitCode = $this->tester->execute([
                'action' => 'path',
                'id' => 'backup',
                '--' . $option => $value,
            ]);
            $output = $this->tester->getDisplay();

            $this->assertEquals(1, $exitCode, $output);
            $this->assertStringContainsString("path action does not support --$option", $output);
            $this->assertStringNotContainsString('/admin/plugins/backup', $output);
        }
    }

    public function testPathWithoutId(): void
    {
        $exitCode = $this->tester->execute(['action' => 'path']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode, 'Should fail without plugin ID');
        $this->assertStringContainsString('required', strtolower($output));
    }

    public function testPathForNonExistentPlugin(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'path',
            'id' => 'nonexistent_plugin_999'
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode, 'Should fail for non-existent plugin');
        $this->assertStringContainsString('not found', strtolower($output));
    }

    // ========================================
    // STATUS COMMAND TESTS (2 tests)
    // ========================================

    public function testStatusShowsStatistics(): void
    {
        $exitCode = $this->tester->execute(['action' => 'status']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode);

        // Should show statistics section
        $this->assertStringContainsString('Plugin Statistics', $output);
        $this->assertStringContainsString('Total Plugins', $output);
        $this->assertStringContainsString('Active', $output);
        $this->assertStringContainsString('Inactive', $output);
        // Note: Missing Files, Syntax Errors, Incompatible only shown if issues exist

        // Should have numeric values in table
        $this->assertMatchesRegularExpression('/\d+/', $output, 'Should contain numeric statistics');
    }

    public function testStatusShowsTypeBreakdown(): void
    {
        $exitCode = $this->tester->execute(['action' => 'status']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode);

        // Should show type breakdown
        $this->assertStringContainsString('By Type', $output);
        $this->assertStringContainsString('Type', $output);
        $this->assertStringContainsString('Count', $output);
    }

    public function testStatusSupportsJsonFormat(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'status',
            '--format' => 'json',
            '--fields' => 'section,metric,value,label',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $rowsByMetric = array_column($rows, null, 'metric');

        $this->assertEquals(0, $exitCode, $this->tester->getDisplay());
        $this->assertSame('overall', $rowsByMetric['Total Plugins']['section'] ?? null);
        $this->assertSame(4, (int) ($rowsByMetric['Total Plugins']['value'] ?? 0));
        $this->assertStringNotContainsString('Plugin Statistics', $this->tester->getDisplay());
    }

    public function testStatusHonorsFieldsSelectionInTableFormat(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'status',
            '--fields' => 'metric',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('Metric', $output);
        $this->assertStringContainsString('Total Plugins', $output);
        $this->assertStringNotContainsString('Plugin Statistics', $output);
        $this->assertStringNotContainsString('Count', $output);
    }

    public function testStatusRejectsListFilters(): void
    {
        foreach (['status' => 'active', 'type' => 'api'] as $option => $value) {
            $exitCode = $this->tester->execute([
                'action' => 'status',
                '--' . $option => $value,
            ]);
            $output = $this->tester->getDisplay();

            $this->assertEquals(1, $exitCode, $output);
            $this->assertStringContainsString("status action does not support --$option", $output);
            $this->assertStringNotContainsString('Total Plugins', $output);
        }
    }

    public function testStatusRejectsIgnoredPluginIdArgument(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'status',
            'id' => 'backup',
            '--format' => 'json',
            '--fields' => 'section,metric,value',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode, $output);
        $this->assertStringContainsString('status action does not support a plugin ID', $output);
        $this->assertStringNotContainsString('Total Plugins', $output);
    }

    public function testTypeFilterIsValidatedBeforeStatusFilterCanEmptyResults(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'list',
            '--status' => 'inactive',
            '--type' => 'cron',
            '--format' => 'count',
        ]);

        $this->assertEquals(0, $exitCode, $this->tester->getDisplay());
        $this->assertSame("0\n", $this->tester->getDisplay());
    }

    // ========================================
    // OUTPUT FORMAT TESTS (6 tests)
    // ========================================

    public function testTableFormat(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'list',
            '--format' => 'table'
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode);

        // Table format should have headers and borders.
        $this->assertStringContainsString('Id', $output);
        $this->assertStringContainsString('Name', $output);
        $this->assertStringContainsString('┌', $output, 'Should contain table formatting');
    }

    public function testCSVFormat(): void
    {
        // Capture output with error suppression to avoid risky test warning
        ob_start();
        $exitCode = $this->tester->execute([
            'action' => 'list',
            '--format' => 'csv'
        ]);
        $csvOutput = ob_get_clean();
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode);

        // CSV format should have comma-separated values
        // Combine both outputs in case CSV goes to stdout
        $fullOutput = $csvOutput . $output;
        $lines = array_filter(explode("\n", trim($fullOutput)));
        $this->assertGreaterThan(0, count($lines), 'Should have CSV lines');

        // First line should be headers
        $headers = str_getcsv($lines[0], ',', '"', '\\');
        $this->assertContains('id', $headers);
        $this->assertContains('name', $headers);
        $this->assertContains('version', $headers);
        $this->assertContains('status', $headers);
        $this->assertContains('types', $headers);
    }

    public function testJSONFormat(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'list',
            '--format' => 'json'
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode);

        // Should be valid JSON
        $json = json_decode($output, true);
        $this->assertIsArray($json, 'JSON format should output valid JSON array');
        $this->assertGreaterThan(0, count($json), 'Should have plugins in JSON output');

        // Each item should have the required fields (name may be empty for some plugins)
        if (count($json) > 0) {
            $firstPlugin = $json[0];
            $this->assertArrayHasKey('id', $firstPlugin);
            $this->assertArrayHasKey('version', $firstPlugin);
            $this->assertArrayHasKey('status', $firstPlugin);
            // 'name' and 'types' may be omitted if empty
        }
    }

    public function testYAMLFormat(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'list',
            '--format' => 'yaml'
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode);

        // YAML format should have key: value pairs with proper indentation
        $this->assertStringContainsString('id:', $output);
        $this->assertStringContainsString('name:', $output);
        $this->assertStringContainsString('version:', $output);

        // YAML should start with dash for list items
        $this->assertStringContainsString('-', $output);

        // Should have proper indentation (2 spaces)
        $this->assertStringContainsString('  id:', $output);
    }

    public function testCountFormat(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'list',
            '--format' => 'count'
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode);

        // Count format should output only a single number
        $count = trim($output);
        $this->assertIsNumeric($count, 'Count should be numeric');
        $this->assertGreaterThan(0, (int)$count, 'Should have at least one plugin');
        $this->assertStringNotContainsString('Plugin', $output, 'Count should not have text headers');
    }

    public function testSingleFieldOutput(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'list',
            '--field' => 'name'
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode);

        // Should output only values, no headers or formatting
        $this->assertStringNotContainsString('Name', $output);
        $this->assertStringNotContainsString('ID', $output);

        // Each line should be a plugin name
        $lines = array_filter(explode("\n", trim($output)));
        $this->assertGreaterThan(0, count($lines), 'Should have plugin names');
        foreach ($lines as $line) {
            $this->assertNotEmpty(trim($line), 'Each line should contain a value');
        }
    }

    // ========================================
    // INTEGRATION TESTS (3 tests)
    // ========================================

    public function testCanReadFixturePlugins(): void
    {
        $pluginsDir = $this->config->getAdminPath() . '/plugins';
        $this->assertDirectoryExists($pluginsDir, 'KVS plugins directory should exist');

        $exitCode = $this->tester->execute(['action' => 'list']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode, 'Should successfully read fixture plugins');
        $this->assertStringContainsString('Name', $output);

        // Should find known fixture plugins
        $exitCode = $this->tester->execute([
            'action' => 'show',
            'id' => 'backup'
        ]);
        $this->assertEquals(0, $exitCode, 'Should find backup plugin fixture');
    }

    public function testHandlesEmptyPluginDirectoryGracefully(): void
    {
        $tempDir = TestHelper::createTempDir('kvs-test-');
        TestHelper::createMockKvsInstallation($tempDir);

        try {
            $tempConfig = new Configuration([
                'path' => $tempDir,
                'disable_db_env_overrides' => true,
            ]);
            $tempCommand = new PluginCommand($tempConfig);

            $app = new Application();
            $app->add($tempCommand);

            $tempTester = new CommandTester($tempCommand);
            $exitCode = $tempTester->execute(['action' => 'list']);
            $output = $tempTester->getDisplay();

            $this->assertEquals(0, $exitCode, 'Should handle empty plugins directory gracefully');
            $this->assertStringContainsString('No plugins found', $output);
        } finally {
            TestHelper::removeDir($tempDir);
        }
    }

    public function testAllActionsAccessible(): void
    {
        $actions = ['list', 'show', 'path', 'status'];

        foreach ($actions as $action) {
            // Verify help mentions the action
            $help = $this->command->getHelp();
            $this->assertStringContainsString(
                $action,
                strtolower($help),
                "Action '$action' should be documented in help"
            );
        }

        // Verify default action is list
        $exitCode = $this->tester->execute([]);
        $this->assertEquals(0, $exitCode, 'Should default to list action');
    }

    // ========================================
    // COMBINED FILTER TESTS (2 tests)
    // ========================================

    public function testCombinedStatusAndTypeFilters(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'list',
            '--status' => 'active',
            '--type' => 'manual'
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode);
        // Should apply both filters
        $this->assertStringContainsString('Name', $output);
    }

    public function testFieldsWithFormatJSON(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'list',
            '--fields' => 'id,name,version',
            '--format' => 'json'
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode);

        // Should output JSON with only specified fields
        $json = json_decode($output, true);
        $this->assertIsArray($json);

        if (count($json) > 0) {
            $firstPlugin = $json[0];
            $this->assertArrayHasKey('id', $firstPlugin);
            $this->assertArrayHasKey('version', $firstPlugin);
            // 'name' may be omitted if empty for some plugins

            // Should have at most 3 fields (some may be empty and omitted)
            $this->assertLessThanOrEqual(
                3,
                count(array_keys($firstPlugin)),
                'Should have at most 3 fields as specified'
            );
        }
    }

    // ========================================
    // PRIVATE METHOD TESTS (2 tests)
    // ========================================

    public function testParsePluginMethodExists(): void
    {
        $reflection = new \ReflectionClass(PluginCommand::class);
        $this->assertTrue($reflection->hasMethod('parsePlugin'));

        $method = $reflection->getMethod('parsePlugin');
        $this->assertTrue($method->isPrivate());

        // Test method parameters
        $params = $method->getParameters();
        $this->assertCount(2, $params);
        $this->assertEquals('id', $params[0]->getName());
        $this->assertEquals('path', $params[1]->getName());
    }

    public function testPluginParsingMethodsExist(): void
    {
        $reflection = new \ReflectionClass(PluginCommand::class);

        // Core plugin methods
        $this->assertTrue($reflection->hasMethod('getAllPlugins'));
        $this->assertTrue($reflection->hasMethod('getPluginById'));
        $this->assertTrue($reflection->hasMethod('parsePlugin'));
        $this->assertTrue($reflection->hasMethod('checkPluginEnabled'));

        // Verify they are private
        $this->assertTrue($reflection->getMethod('getAllPlugins')->isPrivate());
        $this->assertTrue($reflection->getMethod('getPluginById')->isPrivate());
        $this->assertTrue($reflection->getMethod('parsePlugin')->isPrivate());
        $this->assertTrue($reflection->getMethod('checkPluginEnabled')->isPrivate());
    }

    // ========================================
    // ERROR HANDLING TESTS (2 tests)
    // ========================================

    public function testRejectsUnknownAction(): void
    {
        $exitCode = $this->tester->execute(['action' => 'invalid_action']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Unknown plugin action "invalid_action"', $output);
    }

    public function testInvalidFormatReturnsCleanCliError(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'list',
            '--format' => 'invalid_format'
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Invalid value for --format "invalid_format"', $output);
        $this->assertStringNotContainsString('Formatter.php line', $output);
    }

    private function createPluginFixture(
        string $id,
        string $name,
        string $author,
        string $version,
        string $kvsVersion,
        string $types,
        string $title,
        string $description,
        bool $disabled = false
    ): void {
        $pluginDir = $this->tempDir . '/admin/plugins/' . $id;
        mkdir($pluginDir . '/langs', 0755, true);

        file_put_contents(
            $pluginDir . '/' . $id . '.dat',
            <<<XML
<plugin>
    <plugin_name>{$name}</plugin_name>
    <author>{$author}</author>
    <version>{$version}</version>
    <kvs_version>{$kvsVersion}</kvs_version>
    <plugin_types>{$types}</plugin_types>
</plugin>
XML
        );

        $enabledFunctionValue = $disabled ? 'false' : 'true';
        $enabledFunction = "\nfunction {$id}IsEnabled(): bool\n{\n    return {$enabledFunctionValue};\n}\n";

        file_put_contents(
            $pluginDir . '/' . $id . '.php',
            "<?php\nfunction {$id}Show(): void\n{\n}\n" . $enabledFunction
        );
        file_put_contents($pluginDir . '/' . $id . '.tpl', '');
        file_put_contents(
            $pluginDir . '/langs/english.php',
            <<<PHP
<?php
\$lang['plugins']['{$id}']['title'] = '{$title}';
\$lang['plugins']['{$id}']['description'] = '{$description}';

PHP
        );
    }

    private function createGuardedPluginFixture(): void
    {
        $pluginDir = $this->tempDir . '/admin/plugins/guarded_plugin';
        mkdir($pluginDir . '/langs', 0755, true);

        file_put_contents(
            $pluginDir . '/guarded_plugin.dat',
            <<<XML
<plugin>
    <plugin_name>Guarded Plugin</plugin_name>
    <author>Test</author>
    <version>1.0.0</version>
    <kvs_version>6.0.0</kvs_version>
    <plugin_types>manual</plugin_types>
</plugin>
XML
        );

        file_put_contents(
            $pluginDir . '/guarded_plugin.php',
            "<?php\nif (!defined('KVS_ADMIN')) {\n    exit('Access denied');\n}\nfunction guarded_pluginShow(): void\n{\n}\n"
        );
        file_put_contents($pluginDir . '/guarded_plugin.tpl', '');
        file_put_contents(
            $pluginDir . '/langs/english.php',
            <<<PHP
<?php
\$lang['plugins']['guarded_plugin']['title'] = 'Guarded plugin';
\$lang['plugins']['guarded_plugin']['description'] = 'Should not be executed by CLI list.';

PHP
        );
    }

    private function createDataBackedPluginFixture(string $id, bool $enabled): void
    {
        $pluginDir = $this->tempDir . '/admin/plugins/' . $id;
        $dataDir = $this->tempDir . '/admin/data/plugins/' . $id;
        mkdir($pluginDir . '/langs', 0755, true);
        mkdir($dataDir, 0755, true);

        file_put_contents(
            $pluginDir . '/' . $id . '.dat',
            <<<XML
<plugin>
    <plugin_name>Dynamic Status</plugin_name>
    <author>Test</author>
    <version>1.0.0</version>
    <kvs_version>6.0.0</kvs_version>
    <plugin_types>manual,cron</plugin_types>
</plugin>
XML
        );

        file_put_contents(
            $pluginDir . '/' . $id . '.php',
            <<<PHP
<?php
function {$id}Show(): void
{
}

function {$id}LoadConfig(): array
{
    return [];
}

function {$id}IsEnabled(): bool
{
    \$data = {$id}LoadConfig();
    return intval(\$data['is_enabled']) == 1;
}

PHP
        );
        file_put_contents($pluginDir . '/' . $id . '.tpl', '');
        file_put_contents($dataDir . '/data.dat', serialize(['is_enabled' => $enabled ? 1 : 0]));
        file_put_contents(
            $pluginDir . '/langs/english.php',
            <<<PHP
<?php
\$lang['plugins']['{$id}']['title'] = 'Dynamic Status';
\$lang['plugins']['{$id}']['description'] = 'Uses data.dat to determine enabled status.';

PHP
        );
    }
}
