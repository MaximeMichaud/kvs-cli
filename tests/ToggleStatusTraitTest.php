<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Traits\ToggleStatusTrait;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Unit tests for ToggleStatusTrait
 *
 * Tests all scenarios:
 * - Missing ID parameter
 * - Database connection failure
 * - Entity not found
 * - Entity already at target status
 * - Successful status toggle
 * - Database exception during update
 */
class ToggleStatusTraitTest extends TestCase
{
    private TestCommandWithToggleTrait $command;
    private SymfonyStyle $io;
    private BufferedOutput $output;

    protected function setUp(): void
    {
        // Create test environment
        $this->output = new BufferedOutput();
        $input = new ArrayInput([]);
        $this->io = new SymfonyStyle($input, $this->output);

        // Create mock KVS installation
        $tempDir = sys_get_temp_dir() . '/kvs-test-toggle-' . uniqid();
        mkdir($tempDir . '/admin/include', 0755, true);

        TestHelper::createMockDbConfig($tempDir);
        file_put_contents($tempDir . '/admin/include/setup.php', '<?php');

        $config = new Configuration(['path' => $tempDir]);

        // Create test command instance
        $this->command = new TestCommandWithToggleTrait($config);
        $this->command->setIo($this->io);
    }

    protected function tearDown(): void
    {
        // Cleanup temp directory
        $tempDir = sys_get_temp_dir();
        exec('rm -rf ' . escapeshellarg($tempDir . '/kvs-test-toggle-*'));
    }

    /**
     * Test: Missing ID parameter returns FAILURE
     */
    public function testMissingIdReturnsFailure(): void
    {
        $result = $this->command->testToggleStatus(
            'Tag',
            'ktvs_tags',
            'tag_id',
            'tag',
            null, // Missing ID
            1,
            'content:tag'
        );

        $this->assertEquals(Command::FAILURE, $result);

        $output = $this->output->fetch();
        $this->assertStringContainsString('Tag ID is required', $output);
        $this->assertStringContainsString('Usage: kvs content:tag enable', $output);
    }

    /**
     * Test: Disable action shows correct usage message
     */
    public function testMissingIdShowsDisableUsage(): void
    {
        $result = $this->command->testToggleStatus(
            'Category',
            'ktvs_categories',
            'category_id',
            'title',
            null,
            0, // Disable
            'content:category'
        );

        $this->assertEquals(Command::FAILURE, $result);

        $output = $this->output->fetch();
        $this->assertStringContainsString('Category ID is required', $output);
        $this->assertStringContainsString('Usage: kvs content:category disable', $output);
    }

    /**
     * Test: Database connection failure returns FAILURE
     */
    public function testDatabaseConnectionFailureReturnsFailure(): void
    {
        // Force database connection to fail by using invalid credentials
        $tempDir = sys_get_temp_dir() . '/kvs-test-toggle-invalid-' . uniqid();
        mkdir($tempDir . '/admin/include', 0755, true);

        file_put_contents(
            $tempDir . '/admin/include/setup_db.php',
            "<?php\n" .
            "define('DB_HOST', 'invalid_host');\n" .
            "define('DB_LOGIN', 'invalid');\n" .
            "define('DB_PASS', 'invalid');\n" .
            "define('DB_DEVICE', 'invalid');"
        );
        file_put_contents($tempDir . '/admin/include/setup.php', '<?php');

        $config = new Configuration(['path' => $tempDir]);
        $command = new TestCommandWithToggleTrait($config);
        $command->setIo($this->io);

        $result = $command->testToggleStatus(
            'Tag',
            'ktvs_tags',
            'tag_id',
            'tag',
            '123',
            1,
            'content:tag'
        );

        $this->assertEquals(Command::FAILURE, $result);

        // Cleanup
        exec('rm -rf ' . escapeshellarg($tempDir));
    }

    /**
     * Test: Entity not found returns FAILURE
     */
    public function testEntityNotFoundReturnsFailure(): void
    {
        // Try to toggle a non-existent tag
        $result = $this->command->testToggleStatus(
            entityName: 'Tag',
            tableName: TestHelper::table('tags'),
            idColumn: 'tag_id',
            nameColumn: 'tag',
            id: '999999',
            status: 1,
            commandName: 'content:tag'
        );

        $this->assertEquals(Command::FAILURE, $result);
        $this->assertStringContainsString('not found', $this->output->fetch());
    }

    /**
     * Test: Entity already at target status returns SUCCESS with info message
     */
    public function testEntityAlreadyAtTargetStatus(): void
    {
        // Tag ID 1 (HD) is already active (status_id=1)
        $result = $this->command->testToggleStatus(
            entityName: 'Tag',
            tableName: TestHelper::table('tags'),
            idColumn: 'tag_id',
            nameColumn: 'tag',
            id: '1',
            status: 1,
            commandName: 'content:tag'
        );

        $this->assertEquals(Command::SUCCESS, $result);
        $this->assertStringContainsString('already', $this->output->fetch());
    }

    /**
     * Test: Successful status toggle returns SUCCESS
     */
    public function testSuccessfulStatusToggle(): void
    {
        // Disable tag ID 1 (HD) which is currently active
        $result = $this->command->testToggleStatus(
            entityName: 'Tag',
            tableName: TestHelper::table('tags'),
            idColumn: 'tag_id',
            nameColumn: 'tag',
            id: '1',
            status: 0,
            commandName: 'content:tag'
        );

        $this->assertEquals(Command::SUCCESS, $result);
        $output = $this->output->fetch();
        $this->assertStringContainsString('disabled', $output);

        // Re-enable to restore original state
        $this->command->testToggleStatus(
            entityName: 'Tag',
            tableName: TestHelper::table('tags'),
            idColumn: 'tag_id',
            nameColumn: 'tag',
            id: '1',
            status: 1,
            commandName: 'content:tag'
        );
    }

    /**
     * Test: Invalid table name returns FAILURE
     */
    public function testInvalidTableReturnsFailure(): void
    {
        $result = $this->command->testToggleStatus(
            entityName: 'Tag',
            tableName: 'nonexistent_table',
            idColumn: 'tag_id',
            nameColumn: 'tag',
            id: '1',
            status: 1,
            commandName: 'content:tag'
        );

        $this->assertEquals(Command::FAILURE, $result);
    }

    /**
     * Test: Method signature and parameter types
     */
    public function testMethodSignature(): void
    {
        $reflection = new \ReflectionClass(ToggleStatusTrait::class);
        $method = $reflection->getMethod('toggleEntityStatus');

        $this->assertTrue($method->isProtected());

        $params = $method->getParameters();
        $this->assertCount(7, $params);

        // Check parameter types
        $this->assertEquals('entityName', $params[0]->getName());
        $this->assertEquals('string', $params[0]->getType()->getName());

        $this->assertEquals('tableName', $params[1]->getName());
        $this->assertEquals('string', $params[1]->getType()->getName());

        $this->assertEquals('idColumn', $params[2]->getName());
        $this->assertEquals('string', $params[2]->getType()->getName());

        $this->assertEquals('nameColumn', $params[3]->getName());
        $this->assertEquals('string', $params[3]->getType()->getName());

        $this->assertEquals('id', $params[4]->getName());
        $this->assertTrue($params[4]->getType()->allowsNull());

        $this->assertEquals('status', $params[5]->getName());
        $this->assertEquals('int', $params[5]->getType()->getName());

        $this->assertEquals('commandName', $params[6]->getName());
        $this->assertEquals('string', $params[6]->getType()->getName());

        // Check return type
        $this->assertEquals('int', $method->getReturnType()->getName());
    }

    /**
     * Test: Named parameters work correctly
     */
    public function testNamedParameters(): void
    {
        // Test that named parameters work (PHP 8+ feature)
        $result = $this->command->testToggleStatus(
            entityName: 'Tag',
            tableName: 'ktvs_tags',
            idColumn: 'tag_id',
            nameColumn: 'tag',
            id: null,
            status: 1,
            commandName: 'content:tag'
        );

        $this->assertEquals(Command::FAILURE, $result);
    }

    /**
     * Test: Enable action (status = 1)
     */
    public function testEnableAction(): void
    {
        $result = $this->command->testToggleStatus(
            'Tag',
            'ktvs_tags',
            'tag_id',
            'tag',
            null,
            1, // Enable
            'content:tag'
        );

        $output = $this->output->fetch();
        $this->assertStringContainsString('enable', $output);
    }

    /**
     * Test: Disable action (status = 0)
     */
    public function testDisableAction(): void
    {
        $result = $this->command->testToggleStatus(
            'Tag',
            'ktvs_tags',
            'tag_id',
            'tag',
            null,
            0, // Disable
            'content:tag'
        );

        $output = $this->output->fetch();
        $this->assertStringContainsString('disable', $output);
    }

    /**
     * Test: Different entity names work correctly
     */
    public function testDifferentEntityNames(): void
    {
        // Test Tag
        $this->command->testToggleStatus('Tag', 'ktvs_tags', 'tag_id', 'tag', null, 1, 'content:tag');
        $output1 = $this->output->fetch();
        $this->assertStringContainsString('Tag ID is required', $output1);

        // Test Category
        $this->command->testToggleStatus('Category', 'ktvs_categories', 'category_id', 'title', null, 1, 'content:category');
        $output2 = $this->output->fetch();
        $this->assertStringContainsString('Category ID is required', $output2);

        // Test custom entity
        $this->command->testToggleStatus('CustomEntity', 'ktvs_custom', 'custom_id', 'name', null, 1, 'content:custom');
        $output3 = $this->output->fetch();
        $this->assertStringContainsString('CustomEntity ID is required', $output3);
    }
}

/**
 * Test command class that uses ToggleStatusTrait for testing
 */
class TestCommandWithToggleTrait extends \KVS\CLI\Command\BaseCommand
{
    use ToggleStatusTrait;

    private SymfonyStyle $testIo;

    public function __construct(Configuration $config)
    {
        parent::__construct($config);
    }

    public function setIo(SymfonyStyle $io): void
    {
        $this->testIo = $io;
        $this->io = $io;
    }

    /**
     * Expose toggleEntityStatus for testing
     */
    public function testToggleStatus(
        string $entityName,
        string $tableName,
        string $idColumn,
        string $nameColumn,
        ?string $id,
        int $status,
        string $commandName
    ): int {
        return $this->toggleEntityStatus(
            $entityName,
            $tableName,
            $idColumn,
            $nameColumn,
            $id,
            $status,
            $commandName
        );
    }

    protected function configure(): void
    {
        $this->setName('test:toggle');
    }

    protected function execute($input, $output): int
    {
        return self::SUCCESS;
    }
}
