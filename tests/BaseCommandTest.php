<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class BaseCommandTest extends TestCase
{
    private $tempDir;
    private Configuration $config;

    protected function setUp(): void
    {
        // Create mock KVS installation
        $this->tempDir = sys_get_temp_dir() . '/kvs-test-' . uniqid();
        mkdir($this->tempDir . '/admin/include', 0755, true);
        file_put_contents($this->tempDir . '/admin/include/setup_db.php', '<?php');
        file_put_contents($this->tempDir . '/admin/include/setup.php', '<?php');

        $this->config = new Configuration(['path' => $this->tempDir]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    public function testBaseCommandInitialization(): void
    {
        // Create a concrete implementation of BaseCommand for testing
        $command = new class ($this->config) extends BaseCommand {
            protected function configure(): void
            {
                $this->setName('test:command');
            }

            protected function execute($input, $output): int
            {
                return self::SUCCESS;
            }
        };

        $this->assertInstanceOf(BaseCommand::class, $command);
        $this->assertEquals('test:command', $command->getName());
    }

    public function testBaseCommandHasConfiguration(): void
    {
        $command = new class ($this->config) extends BaseCommand {
            protected function configure(): void
            {
                $this->setName('test:command');
            }

            protected function execute($input, $output): int
            {
                // Test that config is accessible
                $this->io->text('KVS Path: ' . $this->config->getKvsPath());
                return self::SUCCESS;
            }
        };

        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $command->run($input, $output);

        $display = $output->fetch();
        $this->assertStringContainsString('KVS Path: ' . $this->tempDir, $display);
    }

    public function testBaseCommandInitializeMethod(): void
    {
        $initializeCalled = false;

        $command = new class ($this->config, $initializeCalled) extends BaseCommand {
            private $flag;

            public function __construct($config, &$flag)
            {
                parent::__construct($config);
                $this->flag = &$flag;
            }

            protected function configure(): void
            {
                $this->setName('test:command');
            }

            protected function initialize($input, $output): void
            {
                parent::initialize($input, $output);
                $this->flag = true;
            }

            protected function execute($input, $output): int
            {
                return self::SUCCESS;
            }
        };

        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $command->run($input, $output);

        $this->assertTrue($initializeCalled, 'Initialize method should be called');
    }

    public function testBaseCommandIoStyleInitialization(): void
    {
        $command = new class ($this->config) extends BaseCommand {
            protected function configure(): void
            {
                $this->setName('test:command');
            }

            protected function execute($input, $output): int
            {
                $this->io->success('Test success message');
                $this->io->error('Test error message');
                $this->io->warning('Test warning message');
                $this->io->info('Test info message');
                return self::SUCCESS;
            }
        };

        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $command->run($input, $output);

        $display = $output->fetch();
        $this->assertStringContainsString('Test success message', $display);
        $this->assertStringContainsString('Test error message', $display);
        $this->assertStringContainsString('Test warning message', $display);
        $this->assertStringContainsString('Test info message', $display);
    }

    public function testBaseCommandReturnCodes(): void
    {
        // Test SUCCESS
        $successCommand = new class ($this->config) extends BaseCommand {
            protected function configure(): void
            {
                $this->setName('test:success');
            }

            protected function execute($input, $output): int
            {
                return self::SUCCESS;
            }
        };

        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $result = $successCommand->run($input, $output);
        $this->assertEquals(0, $result);

        // Test FAILURE
        $failureCommand = new class ($this->config) extends BaseCommand {
            protected function configure(): void
            {
                $this->setName('test:failure');
            }

            protected function execute($input, $output): int
            {
                return self::FAILURE;
            }
        };

        $result = $failureCommand->run($input, $output);
        $this->assertEquals(1, $result);

        // Test INVALID
        $invalidCommand = new class ($this->config) extends BaseCommand {
            protected function configure(): void
            {
                $this->setName('test:invalid');
            }

            protected function execute($input, $output): int
            {
                return self::INVALID;
            }
        };

        $result = $invalidCommand->run($input, $output);
        $this->assertEquals(2, $result);
    }

    public function testBaseCommandWithArguments(): void
    {
        $command = new class ($this->config) extends BaseCommand {
            protected function configure(): void
            {
                $this->setName('test:args')
                     ->addArgument('name', \Symfony\Component\Console\Input\InputArgument::REQUIRED, 'Name argument')
                     ->addArgument('optional', \Symfony\Component\Console\Input\InputArgument::OPTIONAL, 'Optional argument', 'default');
            }

            protected function execute($input, $output): int
            {
                $this->io->text('Name: ' . $input->getArgument('name'));
                $this->io->text('Optional: ' . $input->getArgument('optional'));
                return self::SUCCESS;
            }
        };

        $input = new ArrayInput(['name' => 'TestName']);
        $output = new BufferedOutput();

        $command->run($input, $output);

        $display = $output->fetch();
        $this->assertStringContainsString('Name: TestName', $display);
        $this->assertStringContainsString('Optional: default', $display);
    }

    public function testBaseCommandWithOptions(): void
    {
        $command = new class ($this->config) extends BaseCommand {
            protected function configure(): void
            {
                $this->setName('test:options')
                     ->addOption('flag', 'f', \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Flag option')
                     ->addOption('value', 'v', \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Value option');
            }

            protected function execute($input, $output): int
            {
                $this->io->text('Flag: ' . ($input->getOption('flag') ? 'true' : 'false'));
                $this->io->text('Value: ' . ($input->getOption('value') ?: 'not set'));
                return self::SUCCESS;
            }
        };

        $input = new ArrayInput(['--flag' => true, '--value' => 'test']);
        $output = new BufferedOutput();

        $command->run($input, $output);

        $display = $output->fetch();
        $this->assertStringContainsString('Flag: true', $display);
        $this->assertStringContainsString('Value: test', $display);
    }
}
