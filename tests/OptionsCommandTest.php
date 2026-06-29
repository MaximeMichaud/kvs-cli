<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Settings\OptionsCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class OptionsCommandTest extends TestCase
{
    private string $kvsPath;
    private PDO $db;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->kvsPath = TestHelper::createTestKvsInstallation();
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec('CREATE TABLE ' . TestHelper::table('options') . ' (variable TEXT PRIMARY KEY, value TEXT)');
        $this->db->exec(
            "INSERT INTO " . TestHelper::table('options') .
            " (variable, value) VALUES ('ENABLE_DVD_FIELD_1', '1'), ('SCREENSHOTS_SIZE', '320x180')"
        );

        $config = TestHelper::createTestConfiguration($this->kvsPath);
        $command = new class ($config, $this->db) extends OptionsCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
        $this->tester = new CommandTester($command);
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->kvsPath);
    }

    public function testGetOptionSupportsJsonFormat(): void
    {
        $this->tester->execute([
            'action' => 'get',
            'name' => 'ENABLE_DVD_FIELD_1',
            '--format' => 'json',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();
        $rows = json_decode($display, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertSame('ENABLE_DVD_FIELD_1', $rows[0]['variable'] ?? null);
        $this->assertSame('1', $rows[0]['value'] ?? null);
        $this->assertSame('System', $rows[0]['category'] ?? null);
        $this->assertSame('Enabled', $rows[0]['status'] ?? null);
        $this->assertStringNotContainsString('Option:', $display);
    }

    public function testGetOptionKeepsTableOutputByDefault(): void
    {
        $this->tester->execute([
            'action' => 'get',
            'name' => 'SCREENSHOTS_SIZE',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('Option: SCREENSHOTS_SIZE', $display);
        $this->assertStringContainsString('Dimension (WxH)', $display);
    }

    public function testGetOptionHonorsFieldsSelectionInTableFormat(): void
    {
        $this->tester->execute([
            'action' => 'get',
            'name' => 'ENABLE_DVD_FIELD_1',
            '--fields' => 'variable',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('Variable', $display);
        $this->assertStringContainsString('ENABLE_DVD_FIELD_1', $display);
        $this->assertStringNotContainsString('Option: ENABLE_DVD_FIELD_1', $display);
        $this->assertStringNotContainsString('Category', $display);
    }

    public function testListEmptyResultHonorsFieldsSelectionInTableFormat(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => '__missing_option__',
            '--fields' => 'variable',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('No results found.', $display);
        $this->assertStringNotContainsString('No options found matching criteria', $display);
    }

    public function testListRejectsSetOnlyYesOption(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--yes' => true,
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();

        $this->assertSame(1, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('list action does not support --yes', $display);
    }

    public function testListRejectsIgnoredNameArgument(): void
    {
        $this->tester->execute([
            'action' => 'list',
            'name' => 'ENABLE_DVD_FIELD_1',
            '--format' => 'count',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();

        $this->assertSame(1, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('list action does not support option name or value arguments', $display);
        $this->assertNotSame("2\n", $display);
    }

    public function testGetRejectsSetOnlyYesOption(): void
    {
        $this->tester->execute([
            'action' => 'get',
            'name' => 'ENABLE_DVD_FIELD_1',
            '--yes' => true,
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();

        $this->assertSame(1, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('get action does not support --yes', $display);
        $this->assertStringNotContainsString('ENABLE_DVD_FIELD_1', $display);
    }

    public function testGetRejectsIgnoredValueArgument(): void
    {
        $this->tester->execute([
            'action' => 'get',
            'name' => 'ENABLE_DVD_FIELD_1',
            'value' => 'ignored-value',
            '--format' => 'json',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();

        $this->assertSame(1, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('get action does not support a value argument', $display);
        $this->assertStringNotContainsString('ENABLE_DVD_FIELD_1', $display);
    }

    public function testSetRejectsListOnlyFiltersBeforeUpdatingOption(): void
    {
        $this->tester->execute([
            'action' => 'set',
            'name' => 'ENABLE_DVD_FIELD_1',
            'value' => '0',
            '--prefix' => 'ENABLE',
            '--yes' => true,
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();
        $value = $this->db->query(
            'SELECT value FROM ' . TestHelper::table('options') . " WHERE variable = 'ENABLE_DVD_FIELD_1'"
        )->fetchColumn();

        $this->assertSame(1, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('set action does not support --prefix', $display);
        $this->assertSame('1', $value);
    }

    public function testSetRejectsOutputOptionsBeforeLookingUpOption(): void
    {
        $cases = [
            ['--format', 'json', 'format'],
            ['--fields', 'variable', 'fields'],
            ['--no-truncate', true, 'no-truncate'],
        ];

        foreach ($cases as [$option, $value, $optionName]) {
            $this->tester->execute([
                'action' => 'set',
                'name' => 'KVS_CLI_DOES_NOT_EXIST',
                'value' => '1',
                $option => $value,
                '--force' => true,
            ]);

            $display = $this->tester->getDisplay();

            $this->assertSame(1, $this->tester->getStatusCode(), $optionName . ': ' . $display);
            $this->assertStringContainsString("set action does not support --$optionName", $display);
            $this->assertStringNotContainsString('Option not found', $display);
        }
    }

    public function testListRejectsConflictingEnabledDisabledFilters(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--enabled' => true,
            '--disabled' => true,
            '--format' => 'count',
            '--force' => true,
        ]);

        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('cannot be used together', $this->tester->getDisplay());
    }
}
