<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Content\UserCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

/**
 * Tests for formatter features in UserCommand
 * - --fields option
 * - --field option
 * - --format option (table, csv, json, yaml, count, ids)
 */
class UserCommandWPCLITest extends TestCase
{
    private string $tempDir;
    private Configuration $config;
    private UserCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        // Create mock KVS installation
        $this->tempDir = sys_get_temp_dir() . '/kvs-test-' . uniqid();
        mkdir($this->tempDir . '/admin/include', 0755, true);

        TestHelper::createMockDbConfig($this->tempDir);
        file_put_contents($this->tempDir . '/admin/include/setup.php', '<?php');

        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new UserCommand($this->config);

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    public function testCommandHasFieldsOption(): void
    {
        $definition = $this->command->getDefinition();
        $this->assertTrue($definition->hasOption('fields'));

        $option = $definition->getOption('fields');
        $this->assertEquals('Comma-separated list of fields to display', $option->getDescription());
    }

    public function testCommandHasFieldOption(): void
    {
        $definition = $this->command->getDefinition();
        $this->assertTrue($definition->hasOption('field'));

        $option = $definition->getOption('field');
        $this->assertEquals('Display single field value', $option->getDescription());
    }

    public function testCommandHasFormatOption(): void
    {
        $definition = $this->command->getDefinition();
        $this->assertTrue($definition->hasOption('format'));

        $option = $definition->getOption('format');
        $this->assertStringContainsString('table', $option->getDescription());
        $this->assertStringContainsString('csv', $option->getDescription());
        $this->assertStringContainsString('json', $option->getDescription());
        $this->assertStringContainsString('yaml', $option->getDescription());
        $this->assertStringContainsString('count', $option->getDescription());
        $this->assertStringContainsString('ids', $option->getDescription());
    }

    public function testHelpContainsFormatterFeatures(): void
    {
        $help = $this->command->getHelp();

        // Check for field options documentation
        $this->assertStringContainsString('--fields', $help);
        $this->assertStringContainsString('--field', $help);
        $this->assertStringContainsString('--format', $help);

        // Check for available fields list
        $this->assertStringContainsString('AVAILABLE FIELDS', $help);
        $this->assertStringContainsString('username', $help);
        $this->assertStringContainsString('email', $help);
        $this->assertStringContainsString('tokens', $help);

        // Check for format examples
        $this->assertStringContainsString('table', $help);
        $this->assertStringContainsString('csv', $help);
        $this->assertStringContainsString('json', $help);
        $this->assertStringContainsString('yaml', $help);
    }

    public function testCommandHasCorrectAliases(): void
    {
        $aliases = $this->command->getAliases();

        $this->assertContains('user', $aliases);
        $this->assertContains('users', $aliases);
        $this->assertContains('member', $aliases);
        $this->assertContains('members', $aliases);
    }

    /**
     * Test that default fields are documented
     */
    public function testDefaultFieldsDocumented(): void
    {
        $reflection = new \ReflectionClass(UserCommand::class);
        $method = $reflection->getMethod('listUsers');
        $method->setAccessible(true);

        // Expected default fields
        $expectedDefaults = ['id', 'username', 'display_name', 'email', 'status', 'added_date'];

        // This is validated by the help text
        $help = $this->command->getHelp();
        foreach ($expectedDefaults as $field) {
            $this->assertStringContainsString($field, $help);
        }
    }

    /**
     * Test help contains practical examples
     */
    public function testHelpContainsPracticalExamples(): void
    {
        $help = $this->command->getHelp();

        // Check for example commands
        $this->assertStringContainsString('kvs user list', $help);
        $this->assertStringContainsString('--fields=', $help);
        $this->assertStringContainsString('--field=', $help);
        $this->assertStringContainsString('--format=csv', $help);
        $this->assertStringContainsString('--format=json', $help);
        $this->assertStringContainsString('--format=count', $help);
        $this->assertStringContainsString('--format=ids', $help);

        // Check for combined examples
        $this->assertStringContainsString('--status=premium', $help);
    }

    /**
     * Test command metadata is correct
     */
    public function testCommandMetadata(): void
    {
        $this->assertEquals('content:user', $this->command->getName());
        $this->assertStringContainsString('user', strtolower($this->command->getDescription()));
        $this->assertNotEmpty($this->command->getHelp());
    }
}
