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

    public function testFieldMapConstantExists(): void
    {
        $reflection = new \ReflectionClass(UserCommand::class);
        $this->assertTrue($reflection->hasConstant('FIELD_MAP'));

        $fieldMap = $reflection->getConstant('FIELD_MAP');
        $this->assertIsArray($fieldMap);

        // Test key field mappings
        $this->assertArrayHasKey('id', $fieldMap);
        $this->assertArrayHasKey('username', $fieldMap);
        $this->assertArrayHasKey('email', $fieldMap);
        $this->assertArrayHasKey('tokens', $fieldMap);
        $this->assertArrayHasKey('videos', $fieldMap);
        $this->assertArrayHasKey('albums', $fieldMap);

        // Test field mapping values
        $this->assertEquals('user_id', $fieldMap['id']);
        $this->assertEquals('tokens_available', $fieldMap['tokens']);
        $this->assertEquals('video_count', $fieldMap['videos']);
        $this->assertEquals('album_count', $fieldMap['albums']);
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

    public function testGetFieldValueMethodExists(): void
    {
        $reflection = new \ReflectionClass(UserCommand::class);
        $this->assertTrue($reflection->hasMethod('getFieldValue'));

        $method = $reflection->getMethod('getFieldValue');
        $this->assertTrue($method->isPrivate());

        // Test method parameters
        $params = $method->getParameters();
        $this->assertCount(3, $params);
        $this->assertEquals('user', $params[0]->getName());
        $this->assertEquals('field', $params[1]->getName());
        $this->assertEquals('formatted', $params[2]->getName());

        // Test default value for formatted parameter
        $this->assertTrue($params[2]->isDefaultValueAvailable());
        $this->assertTrue($params[2]->getDefaultValue());
    }

    public function testOutputMethodsExist(): void
    {
        $reflection = new \ReflectionClass(UserCommand::class);

        // Test all format output methods exist
        $this->assertTrue($reflection->hasMethod('outputTable'));
        $this->assertTrue($reflection->hasMethod('outputCSV'));
        $this->assertTrue($reflection->hasMethod('outputJSON'));
        $this->assertTrue($reflection->hasMethod('outputYAML'));
        $this->assertTrue($reflection->hasMethod('outputSingleField'));

        // Verify they are private
        $this->assertTrue($reflection->getMethod('outputTable')->isPrivate());
        $this->assertTrue($reflection->getMethod('outputCSV')->isPrivate());
        $this->assertTrue($reflection->getMethod('outputJSON')->isPrivate());
        $this->assertTrue($reflection->getMethod('outputYAML')->isPrivate());
        $this->assertTrue($reflection->getMethod('outputSingleField')->isPrivate());
    }

    public function testStatusConversionMethodsExist(): void
    {
        $reflection = new \ReflectionClass(UserCommand::class);

        $this->assertTrue($reflection->hasMethod('getUserStatusLabel'));
        $this->assertTrue($reflection->hasMethod('getUserStatusText'));

        // Gender conversion is done inline in getFieldValue(), not a separate method
        // This is tested by the getFieldValue method existence
        $this->assertTrue($reflection->hasMethod('getFieldValue'));
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
     * Test that FIELD_MAP covers all necessary mappings
     */
    public function testFieldMapCompleteness(): void
    {
        $reflection = new \ReflectionClass(UserCommand::class);
        $fieldMap = $reflection->getConstant('FIELD_MAP');

        // Essential fields that must be mapped
        $essentialFields = [
            'id' => 'user_id',
            'username' => 'username',
            'display_name' => 'display_name',
            'email' => 'email',
            'status' => 'status_id',
            'tokens' => 'tokens_available',
            'country' => 'country_code',
            'gender' => 'gender_id',
            'videos' => 'video_count',
            'albums' => 'album_count',
            'added_date' => 'added_date',
            'last_login' => 'last_login_date',
        ];

        foreach ($essentialFields as $userField => $dbField) {
            $this->assertArrayHasKey($userField, $fieldMap, "Field '$userField' missing from FIELD_MAP");
            $this->assertEquals($dbField, $fieldMap[$userField], "Field '$userField' maps to wrong DB column");
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
