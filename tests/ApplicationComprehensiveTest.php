<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ApplicationComprehensiveTest extends TestCase
{
    private Application $app;
    private string $tempKvsDir;

    protected function setUp(): void
    {
        $this->app = new Application();
        $this->app->setAutoExit(false);

        // Create temp KVS installation for testing
        $this->tempKvsDir = sys_get_temp_dir() . '/kvs-test-' . uniqid();
        $this->createMockKvsInstallation($this->tempKvsDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempKvsDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempKvsDir));
        }
    }

    private function createMockKvsInstallation(string $dir): void
    {
        // Use TestHelper to create mock KVS installation
        TestHelper::createMockKvsInstallation($dir, ['project_version' => '6.3.2']);
    }

    // Test 1: Application metadata
    public function testApplicationMetadata(): void
    {
        $expectedVersion = trim(file_get_contents(__DIR__ . '/../VERSION'));
        $this->assertEquals('KVS CLI', $this->app->getName());
        $this->assertEquals($expectedVersion, $this->app->getVersion());
        $this->assertStringContainsString('KVS CLI', $this->app->getLongVersion());
    }

    // Test 2: Default commands exist
    public function testDefaultCommandsExist(): void
    {
        $commands = $this->app->all();

        $this->assertArrayHasKey('help', $commands);
        $this->assertArrayHasKey('list', $commands);
    }

    // Test 3: Path option is defined
    public function testPathOptionIsDefined(): void
    {
        $definition = $this->app->getDefinition();

        $this->assertTrue($definition->hasOption('path'));
        $this->assertTrue($definition->hasOption('help'));
        $this->assertTrue($definition->hasOption('version'));
        $this->assertTrue($definition->hasOption('verbose'));

        $pathOption = $definition->getOption('path');
        $this->assertEquals('Path to KVS installation directory', $pathOption->getDescription());
        $this->assertTrue($pathOption->isValueRequired());
        $this->assertTrue($pathOption->acceptValue());
    }

    // Test 4: Help includes warning when no KVS
    public function testHelpIncludesWarningWhenNoKvs(): void
    {
        $help = $this->app->getHelp();

        $this->assertStringContainsString('No KVS installation detected', $help);
        $this->assertStringContainsString('Use --path=/path/to/kvs', $help);
    }

    // Test 5: Run without KVS shows only basic commands
    public function testRunWithoutKvsShowsOnlyBasicCommands(): void
    {
        $input = new ArrayInput(['command' => 'list']);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        // Change to temp dir without KVS
        $oldCwd = getcwd();
        chdir(sys_get_temp_dir());

        $exitCode = $this->app->run($input, $output);

        chdir($oldCwd);

        $this->assertEquals(0, $exitCode);

        $display = $output->fetch();
        $this->assertStringContainsString('Available commands:', $display);
        $this->assertStringContainsString('help', $display);
        $this->assertStringContainsString('list', $display);
        $this->assertStringNotContainsString('maintenance', $display);
    }

    // Test 6: Run with --path parameter loads KVS commands
    public function testRunWithPathParameterLoadsKvsCommands(): void
    {
        $input = new ArrayInput([
            'command' => 'list',
            '--path' => $this->tempKvsDir
        ]);
        $input->bind($this->app->getDefinition());
        $input->setInteractive(false);
        $output = new BufferedOutput();

        $exitCode = $this->app->run($input, $output);

        $this->assertEquals(0, $exitCode);

        $display = $output->fetch();
        $this->assertStringContainsString('maintenance', $display);
        $this->assertStringContainsString('config', $display);
        $this->assertStringContainsString('system:status', $display);
    }

    // Test 7: Run from KVS directory auto-detects
    public function testRunFromKvsDirectoryAutoDetects(): void
    {
        $oldCwd = getcwd();
        chdir($this->tempKvsDir);

        $input = new ArrayInput(['command' => 'list']);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        $exitCode = $this->app->run($input, $output);

        chdir($oldCwd);

        $this->assertEquals(0, $exitCode);

        $display = $output->fetch();
        $this->assertStringContainsString('maintenance', $display);
    }

    // Test 8: Invalid command shows error
    public function testInvalidCommandShowsError(): void
    {
        $input = new ArrayInput(['command' => 'nonexistent']);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        $exitCode = $this->app->run($input, $output);

        $this->assertEquals(1, $exitCode);

        $display = $output->fetch();
        $this->assertStringContainsString('KVS installation not found', $display);
    }

    // Test 9: Invalid --path shows error
    public function testInvalidPathShowsError(): void
    {
        $input = new ArrayInput([
            'command' => 'list',
            '--path' => '/nonexistent/path'
        ]);
        $input->bind($this->app->getDefinition());
        $input->setInteractive(false);
        $output = new BufferedOutput();

        $exitCode = $this->app->run($input, $output);

        $this->assertEquals(0, $exitCode); // list still works

        $display = $output->fetch();
        $this->assertStringNotContainsString('maintenance', $display); // No KVS commands
    }

    // Test 10: Version command works
    public function testVersionCommandWorks(): void
    {
        $expectedVersion = trim(file_get_contents(__DIR__ . '/../VERSION'));
        $input = new ArrayInput(['--version' => true]);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        $this->app->run($input, $output);

        $this->assertStringContainsString('KVS CLI version ' . $expectedVersion, $output->fetch());
    }

    // Test 11: Help command works
    public function testHelpCommandWorks(): void
    {
        $input = new ArrayInput(['command' => 'help']);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        $this->app->run($input, $output);

        $display = $output->fetch();
        $this->assertStringContainsString('Usage:', $display);
        $this->assertStringContainsString('Options:', $display);
    }

    // Test 12: KVS_PATH environment variable works
    public function testKvsPathEnvironmentVariable(): void
    {
        putenv('KVS_PATH=' . $this->tempKvsDir);

        $app = new Application();
        $app->setAutoExit(false);
        $input = new ArrayInput(['command' => 'list']);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        $exitCode = $app->run($input, $output);

        putenv('KVS_PATH='); // Clean up

        $this->assertEquals(0, $exitCode);

        $display = $output->fetch();
        $this->assertStringContainsString('maintenance', $display);
    }

    // Test 13: Test find() method error handling
    public function testFindMethodErrorHandling(): void
    {
        $this->expectException(\Symfony\Component\Console\Exception\CommandNotFoundException::class);

        // This should throw exception when no KVS and invalid command
        $this->app->find('nonexistent-command');
    }

    // Test 14: Test all registered command names
    public function testAllRegisteredCommandNames(): void
    {
        // Setup with valid KVS path
        $input = new ArrayInput([
            'command' => 'list',
            '--path' => $this->tempKvsDir
        ]);
        $input->bind($this->app->getDefinition());
        $input->setInteractive(false);
        $output = new BufferedOutput();

        $this->app->run($input, $output);

        $commands = $this->app->all();

        // Check system commands
        $this->assertArrayHasKey('system:status', $commands);
        $this->assertArrayHasKey('system:cache', $commands);
        $this->assertArrayHasKey('system:cron', $commands);
        $this->assertArrayHasKey('system:backup', $commands);
        $this->assertArrayHasKey('system:check', $commands);

        // Check content commands
        $this->assertArrayHasKey('content:video', $commands);
        $this->assertArrayHasKey('content:user', $commands);
        $this->assertArrayHasKey('content:album', $commands);
        $this->assertArrayHasKey('content:category', $commands);

        // Check database commands
        $this->assertArrayHasKey('db:export', $commands);
        $this->assertArrayHasKey('db:import', $commands);

        // Check dev commands
        $this->assertArrayHasKey('dev:debug', $commands);
        $this->assertArrayHasKey('dev:log', $commands);

        // Check other commands
        $this->assertArrayHasKey('config', $commands);
        $this->assertArrayHasKey('maintenance', $commands);
        $this->assertArrayHasKey('shell', $commands);
    }

    // Test 15: Test command aliases work
    public function testCommandAliasesWork(): void
    {
        $input = new ArrayInput([
            'command' => 'list',
            '--path' => $this->tempKvsDir
        ]);
        $input->bind($this->app->getDefinition());
        $input->setInteractive(false);
        $output = new BufferedOutput();

        $this->app->run($input, $output);

        // Test that we can find commands by aliases
        $this->assertInstanceOf(\Symfony\Component\Console\Command\Command::class, $this->app->find('maint'));
        $this->assertInstanceOf(\Symfony\Component\Console\Command\Command::class, $this->app->find('status'));
        $this->assertInstanceOf(\Symfony\Component\Console\Command\Command::class, $this->app->find('video'));
    }
}
