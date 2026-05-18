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
    private string $tempDir;
    private \PDO $db;

    protected function setUp(): void
    {
        // Create test environment
        $this->output = new BufferedOutput();
        $input = new ArrayInput([]);
        $this->io = new SymfonyStyle($input, $this->output);

        // Create mock KVS installation
        $this->tempDir = TestHelper::createTempDir('kvs-test-toggle-');
        mkdir($this->tempDir . '/admin/include', 0755, true);

        TestHelper::createMockKvsInstallation($this->tempDir);

        $config = new Configuration([
            'path' => $this->tempDir,
            'disable_db_env_overrides' => true,
        ]);
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->db->exec('CREATE TABLE ktvs_tags (tag_id INTEGER PRIMARY KEY, tag TEXT, status_id INTEGER)');
        $this->db->exec("INSERT INTO ktvs_tags VALUES (1, 'HD', 1), (2, 'Inactive', 0)");

        // Create test command instance
        $this->command = new TestCommandWithToggleTrait($config, $this->db);
        $this->command->setIo($this->io);
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->tempDir);
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
        $config = new Configuration([
            'path' => $this->tempDir,
            'disable_db_env_overrides' => true,
        ]);
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
        $status = $this->db->query('SELECT status_id FROM ktvs_tags WHERE tag_id = 1')->fetchColumn();
        $this->assertSame(0, (int) $status);
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

    public function __construct(Configuration $config, private ?\PDO $testDb = null)
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

    protected function getDatabaseConnection(bool $quiet = false): ?\PDO
    {
        return $this->testDb;
    }

    protected function execute($input, $output): int
    {
        return self::SUCCESS;
    }
}
