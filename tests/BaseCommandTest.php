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
        $this->tempDir = TestHelper::createTempDir('kvs-test-');
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

    public function testRuntimeConfigUsesActualKvsPathOverConfiguredProjectPath(): void
    {
        file_put_contents(
            $this->tempDir . '/admin/include/setup.php',
            '<?php $config = ["project_path" => "/var/www/html", "tables_prefix" => "ktvs_"];'
        );

        $config = new Configuration(['path' => $this->tempDir]);
        $command = new class ($config) extends BaseCommand {
            protected function configure(): void
            {
                $this->setName('test:runtime-config');
            }

            protected function execute($input, $output): int
            {
                return self::SUCCESS;
            }

            public function runtimeConfig(): array
            {
                return $this->getKvsRuntimeConfig();
            }
        };

        $runtimeConfig = $command->runtimeConfig();

        $this->assertSame($this->tempDir, $runtimeConfig['project_path']);
        $this->assertSame('ktvs_', $runtimeConfig['tables_prefix']);
    }

    public function testKvsAdminContextUsesActualKvsPathOverConfiguredProjectPath(): void
    {
        file_put_contents(
            $this->tempDir . '/admin/include/setup.php',
            '<?php $config = ["project_path" => "/var/www/html", "tables_prefix" => "ktvs_"];'
        );
        file_put_contents($this->tempDir . '/admin/include/functions_base.php', '<?php');
        file_put_contents($this->tempDir . '/admin/include/functions.php', '<?php');

        $config = new Configuration(['path' => $this->tempDir]);
        $command = new class ($config) extends BaseCommand {
            protected function configure(): void
            {
                $this->setName('test:kvs-admin-context');
            }

            protected function execute($input, $output): int
            {
                return self::SUCCESS;
            }

            public function runInContext(): string
            {
                return $this->runWithKvsAdminContext(static function (): string {
                    global $config;

                    return (string) ($config['project_path'] ?? '');
                });
            }
        };

        $this->assertSame($this->tempDir, $command->runInContext());
    }

    public function testDatabaseConnectionDoesNotAppendLocalhostFallbacks(): void
    {
        $command = new class ($this->config) extends BaseCommand {
            protected function configure(): void
            {
                $this->setName('test:database-hosts');
            }

            protected function execute($input, $output): int
            {
                return self::SUCCESS;
            }

            /**
             * @return list<string>
             */
            public function hostsFor(string $host): array
            {
                return $this->getDatabaseHostsToTry($host);
            }
        };

        $this->assertSame(['mariadb'], $command->hostsFor('mariadb'));
        $this->assertSame(['mariadb:3307'], $command->hostsFor('mariadb:3307'));
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

    public function testInvalidStatusFilterThrows(): void
    {
        $command = new class ($this->config) extends BaseCommand {
            protected function configure(): void
            {
                $this->setName('test:status')
                    ->addOption('status', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED);
            }

            public function parseForTest(\Symfony\Component\Console\Input\ArrayInput $input): ?int
            {
                return $this->parseStatusFilter($input, ['active' => 1, 'disabled' => 0], [0, 1]);
            }

            protected function execute($input, $output): int
            {
                return self::SUCCESS;
            }
        };

        $input = new ArrayInput(['--status' => 'bogus'], $command->getDefinition());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid status "bogus"');
        $command->parseForTest($input);
    }

    public static function exportContractDetailPayloads(): iterable
    {
        foreach (['Literal <fg=red>text</>', 'Literal <bg=blue>text</>', 'Literal <options=bold>text</>', 'Literal <info>text</info>'] as $payload) {
            foreach (['row', 'extra'] as $source) {
                yield "$source $payload" => [$source, $payload];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('exportContractDetailPayloads')]
    public function testExportContractDetailAssemblyPreservesRawData(string $source, string $payload): void
    {
        $command = new class ($this->config) extends BaseCommand {
            public array $captured = [];
            protected function configure(): void
            {
                $this->setName('test:detail-data');
            }
            protected function execute($input, $output): int
            {
                return self::SUCCESS;
            }
            public function assemble(array $rows, array $extra): int
            {
                return $this->displayDetailRows(new ArrayInput([]), $rows, $extra);
            }
            protected function displayFormattedRows(
                \Symfony\Component\Console\Input\InputInterface $input,
                array $rows,
                array $defaultFields,
                ?array $knownFields = null,
                bool $literalTableValues = false
            ): int {
                $this->captured = $rows;
                return self::SUCCESS;
            }
        };
        $command->assemble($source === 'row' ? [['Value', $payload]] : [], $source === 'extra' ? ['value' => $payload] : []);
        $this->assertSame($payload, $command->captured[0]['value']);
    }

    public function testExportContractDetailAssemblyKeepsScalarAndNestedTypes(): void
    {
        $command = new class ($this->config) extends BaseCommand {
            public array $captured = [];
            protected function configure(): void
            {
                $this->setName('test:detail-types');
            }
            protected function execute($input, $output): int
            {
                return self::SUCCESS;
            }
            public function assemble(array $rows, array $extra): int
            {
                return $this->displayDetailRows(new ArrayInput([]), $rows, $extra);
            }
            protected function displayFormattedRows(
                \Symfony\Component\Console\Input\InputInterface $input,
                array $rows,
                array $defaultFields,
                ?array $knownFields = null,
                bool $literalTableValues = false
            ): int {
                $this->captured = $rows;
                return self::SUCCESS;
            }
        };
        $nested = ['title' => 'Literal <fg=red>text</>', 'enabled' => false];
        $command->assemble([['Images', 2], ['Missing', null]], ['count' => 2, 'enabled' => false, 'nested' => $nested]);
        $this->assertSame([['images' => '2', 'missing' => '', 'count' => 2, 'enabled' => false, 'nested' => $nested]], $command->captured);
    }

    /**
     * @dataProvider installationAdminContextPathProvider
     */
    public function testInstallationAdminContextUsesStablePathsAndRestoresProcessState(string $kind, bool $callbackFails): void
    {
        $root = $this->tempDir;
        $previousDirectory = getcwd();
        $previousIncludePath = get_include_path();
        $previousSession = $_SESSION ?? null;
        $previousConfig = $GLOBALS['config'] ?? null;
        $warnings = [];
        set_error_handler(static function (int $severity) use (&$warnings): bool {
            $warnings[] = $severity;
            return true;
        });

        try {
            foreach (['site', 'physical/site'] as $site) {
                $directory = $root . '/' . $site . '/admin/include';
                mkdir($directory, 0755, true);
                file_put_contents($directory . '/setup_db.php', '<?php');
                file_put_contents($directory . '/functions_base.php', '<?php');
                file_put_contents($directory . '/functions.php', '<?php');
                file_put_contents($directory . '/fixture_extra.php', '<?php $config["fixture_extra"] = "loaded";');
                file_put_contents(
                    $directory . '/setup.php',
                    '<?php $config = ["project_path" => "Synthetic stale path", "project_name" => '
                    . var_export($site, true) . ', "project_version" => "7.0.0"];'
                );
            }
            mkdir($root . '/work');
            mkdir($root . '/physical/nested');
            $this->assertTrue(symlink($root . '/site', $root . '/alias'));
            $this->assertTrue(symlink($root . '/physical/nested', $root . '/jump'));
            $directory = match ($kind) {
                'dot' => $root . '/site',
                'parent' => $root . '/work',
                default => $root,
            };
            $path = match ($kind) {
                'absolute' => $root . '/site',
                'dot' => '.',
                'child' => 'site',
                'parent' => '../site',
                'relative alias' => 'alias',
                'absolute alias' => $root . '/alias',
                'alias parent' => 'jump/../site',
            };
            chdir($directory);
            $expectedPath = str_starts_with($path, '/') ? $path : $directory . '/' . $path;
            $expectedSite = $kind === 'alias parent' ? 'physical/site' : 'site';
            $configuration = new Configuration(['path' => $path, 'disable_db_env_overrides' => true]);
            $command = new class ($configuration) extends BaseCommand {
                protected function configure(): void
                {
                    $this->setName('test:relative-context');
                }

                public function runInContext(callable $callback): mixed
                {
                    return $this->runWithKvsAdminContext($callback, ['fixture_extra.php']);
                }
            };
            $_SESSION = ['synthetic_session' => true];
            $GLOBALS['config'] = ['synthetic_config' => true];
            $called = false;
            $callbackData = null;
            $failure = null;
            try {
                $command->runInContext(static function () use (&$called, &$callbackData, $callbackFails): void {
                    global $config;
                    $called = true;
                    $callbackData = [
                        'cwd' => getcwd(),
                        'project_path' => $config['project_path'] ?? null,
                        'project_name' => $config['project_name'] ?? null,
                        'extra_loaded' => $config['fixture_extra'] ?? null,
                        'project_file_exists' => is_file(($config['project_path'] ?? '') . '/admin/include/setup.php'),
                        'session_login' => $_SESSION['userdata']['login'] ?? null,
                    ];
                    if ($callbackFails) {
                        throw new \RuntimeException('Synthetic callback failure');
                    }
                });
            } catch (\Throwable $error) {
                $failure = $error;
            }

            $this->assertSame($directory, getcwd());
            $this->assertSame($previousIncludePath, get_include_path());
            $this->assertSame(['synthetic_session' => true], $_SESSION);
            $this->assertSame(['synthetic_config' => true], $GLOBALS['config']);
            $this->assertSame([], $warnings);
            $this->assertTrue($called, 'The synthetic callback must be reached after loading its includes.');
            $this->assertSame([
                'cwd' => $root . '/' . $expectedSite . '/admin',
                'project_path' => $expectedPath,
                'project_name' => $expectedSite,
                'extra_loaded' => 'loaded',
                'project_file_exists' => true,
                'session_login' => 'kvs-cli',
            ], $callbackData);
            if ($callbackFails) {
                $this->assertInstanceOf(\RuntimeException::class, $failure);
                $this->assertSame('Synthetic callback failure', $failure->getMessage());
            } else {
                $this->assertNull($failure);
            }
        } finally {
            restore_error_handler();
            if ($previousDirectory !== false) {
                chdir($previousDirectory);
            }
            set_include_path($previousIncludePath);
            if ($previousSession === null) {
                unset($_SESSION);
            } else {
                $_SESSION = $previousSession;
            }
            if ($previousConfig === null) {
                unset($GLOBALS['config']);
            } else {
                $GLOBALS['config'] = $previousConfig;
            }
        }
    }

    public static function installationAdminContextPathProvider(): iterable
    {
        foreach (['absolute', 'dot', 'child', 'parent', 'relative alias', 'absolute alias', 'alias parent'] as $kind) {
            foreach ([false, true] as $failure) {
                yield $kind . ($failure ? ' callback failure' : ' callback success') => [$kind, $failure];
            }
        }
    }
}
