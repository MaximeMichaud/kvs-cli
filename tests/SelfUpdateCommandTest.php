<?php

declare(strict_types=1);

namespace KVS\CLI\Tests;

use KVS\CLI\Command\SelfUpdateCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

class SelfUpdateCommandTest extends TestCase
{
    private CommandTester $commandTester;
    private SelfUpdateCommand $command;

    protected function setUp(): void
    {
        $this->command = new SelfUpdateCommand();

        $application = new Application();
        $application->add($this->command);

        $this->commandTester = new CommandTester($this->command);
    }

    public function testCommandIsRegistered(): void
    {
        $this->assertEquals('self-update', $this->command->getName());
    }

    public function testCommandHasAliases(): void
    {
        $aliases = $this->command->getAliases();

        $this->assertContains('selfupdate', $aliases);
        $this->assertContains('self:update', $aliases);
    }

    public function testCommandHasDescription(): void
    {
        $this->assertNotEmpty($this->command->getDescription());
        $this->assertStringContainsString('update', strtolower($this->command->getDescription()));
    }

    public function testCommandHasStableOption(): void
    {
        $definition = $this->command->getDefinition();

        $this->assertTrue($definition->hasOption('stable'));
        $this->assertFalse($definition->getOption('stable')->isValueRequired());
    }

    public function testCommandHasPreviewOption(): void
    {
        $definition = $this->command->getDefinition();

        $this->assertTrue($definition->hasOption('preview'));
        $this->assertFalse($definition->getOption('preview')->isValueRequired());
    }

    public function testCommandHasCheckOption(): void
    {
        $definition = $this->command->getDefinition();

        $this->assertTrue($definition->hasOption('check'));
        $this->assertFalse($definition->getOption('check')->isValueRequired());
    }

    public function testCommandHasYesOption(): void
    {
        $definition = $this->command->getDefinition();

        $this->assertTrue($definition->hasOption('yes'));
        $this->assertEquals('y', $definition->getOption('yes')->getShortcut());
    }

    public function testNonPharExecutionShowsError(): void
    {
        // When not running as PHAR, should show error
        $this->commandTester->execute([]);

        $output = $this->commandTester->getDisplay();

        $this->assertStringContainsString('PHAR', $output);
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testDockerLauncherCannotUpdateTemporaryContainerCopy(): void
    {
        $previous = getenv('KVS_CLI_DOCKER_LAUNCHER');
        putenv('KVS_CLI_DOCKER_LAUNCHER=1');
        try {
            $this->commandTester->execute(['--yes' => true]);

            $this->assertSame(1, $this->commandTester->getStatusCode());
            $this->assertStringContainsString('on the host', $this->commandTester->getDisplay());
        } finally {
            putenv($previous === false ? 'KVS_CLI_DOCKER_LAUNCHER' : 'KVS_CLI_DOCKER_LAUNCHER=' . $previous);
        }
    }

    public function testCheckOptionWithNonPhar(): void
    {
        $this->commandTester->execute(['--check' => true]);

        $output = $this->commandTester->getDisplay();

        // Should still fail because not running as PHAR
        $this->assertStringContainsString('PHAR', $output);
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testCheckOptionDoesNotRequireWritablePhar(): void
    {
        $pharPath = tempnam(sys_get_temp_dir(), 'kvs-readonly-');
        $this->assertIsString($pharPath);
        file_put_contents($pharPath, 'phar');
        chmod($pharPath, 0555);

        $previousArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = [$pharPath];

        $command = new class extends SelfUpdateCommand {
            protected function isRunningAsPhar(): bool
            {
                return true;
            }

            protected function getCurrentVersion(): string
            {
                return '1.0.0';
            }

            /**
             * @return array<array{tag_name: string, prerelease: bool, assets: array<array{name: string, browser_download_url: string}>}>
             */
            protected function getGitHubReleases(SymfonyStyle $io, bool $includePrerelease): ?array
            {
                return [[
                    'tag_name' => 'v1.1.0',
                    'prerelease' => false,
                    'assets' => [],
                ]];
            }
        };

        try {
            $tester = new CommandTester($command);
            $tester->execute(['--check' => true]);

            $output = $tester->getDisplay();
            $this->assertSame(0, $tester->getStatusCode());
            $this->assertStringContainsString('New version available', $output);
            $this->assertStringNotContainsString('not writable', $output);
        } finally {
            if ($previousArgv === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $previousArgv;
            }

            chmod($pharPath, 0644);
            unlink($pharPath);
        }
    }

    public function testDevCheckDoesNotRequireWritablePhar(): void
    {
        $pharPath = tempnam(sys_get_temp_dir(), 'kvs-dev-readonly-');
        $this->assertIsString($pharPath);
        file_put_contents($pharPath, 'phar');
        chmod($pharPath, 0555);

        $previousArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = [$pharPath];

        $command = new class extends SelfUpdateCommand {
            protected function isRunningAsPhar(): bool
            {
                return true;
            }

            protected function getCurrentVersion(): string
            {
                return '1.0.0';
            }

            protected function getLatestWorkflowRunId(SymfonyStyle $io): ?string
            {
                return '123456789';
            }
        };

        try {
            $tester = new CommandTester($command);
            $tester->execute(['--dev' => true, '--check' => true]);

            $output = $tester->getDisplay();
            $this->assertSame(0, $tester->getStatusCode(), $output);
            $this->assertStringContainsString('Latest successful dev build is available', $output);
            $this->assertStringContainsString('123456789', $output);
            $this->assertStringContainsString('kvs self-update --dev', $output);
            $this->assertStringNotContainsString('not writable', $output);
            $this->assertStringNotContainsString('Downloading', $output);
            $this->assertStringNotContainsString('Installing', $output);
        } finally {
            if ($previousArgv === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $previousArgv;
            }

            chmod($pharPath, 0644);
            unlink($pharPath);
        }
    }

    public function testHelpOutput(): void
    {
        $help = $this->command->getHelp();

        $this->assertStringContainsString('--stable', $help);
        $this->assertStringContainsString('--preview', $help);
        $this->assertStringContainsString('--check', $help);
        $this->assertStringContainsString('Examples', $help);
    }

    /**
     * @dataProvider provideInvalidArchiveKinds
     */
    public function testInvalidArchiveIsRejectedBeforeExecutingItsStub(string $kind): void
    {
        $directory = TestHelper::createTempDir('kvs-update-verification-');
        try {
            $asset = $directory . '/download.phar';
            $marker = $directory . '/executed';
            $current = $directory . '/current.phar';
            file_put_contents($current, 'Original updater fixture');
            chmod($current, 0750);
            $before = hash_file('sha256', $current);
            if ($kind === 'plain PHP') {
                $stub = '<?php file_put_contents(' . var_export($marker, true)
                    . ", 'executed'); echo 'Synthetic maintenance response'.PHP_EOL;";
            } else {
                $valid = $directory . '/valid.phar';
                $this->createArchiveFixture($valid, $marker, 0);
                $archive = file_get_contents($valid);
                $halt = '__HALT_COMPILER();';
                $position = strpos($archive, $halt);
                $this->assertNotFalse($position);
                $stub = substr($archive, 0, $position + strlen($halt)) . "\n";
            }
            file_put_contents($asset, $stub);

            $tester = $this->runUpdateWithLocalAsset($current, $asset);

            $this->assertFileDoesNotExist($marker, 'An invalid archive must not execute its PHP stub.');
            $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertSame($before, hash_file('sha256', $current));
            $this->assertSame(0750, fileperms($current) & 0777);
            $this->assertStringContainsString('PHAR', $tester->getDisplay());
        } finally {
            TestHelper::removeDir($directory);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideInvalidArchiveKinds(): array
    {
        return [
            'plain PHP' => ['plain PHP'],
            'truncated archive' => ['truncated archive'],
        ];
    }

    /**
     * @dataProvider provideArchiveExitCodes
     */
    public function testGenuineArchiveStillNeedsSuccessfulVersionCommand(int $exitCode): void
    {
        $directory = TestHelper::createTempDir('kvs-update-verification-');
        try {
            $asset = $directory . '/download.phar';
            $marker = $directory . '/executed';
            $current = $directory . '/current.phar';
            file_put_contents($current, 'Original updater fixture');
            chmod($current, 0750);
            $before = hash_file('sha256', $current);
            $this->createArchiveFixture($asset, $marker, $exitCode);
            $archive = new \Phar($asset);
            $this->assertSame('9.9.9', $archive['VERSION']->getContent());
            unset($archive);

            $tester = $this->runUpdateWithLocalAsset($current, $asset);

            $this->assertFileExists($marker, 'A readable PHAR must still execute the --version check.');
            $this->assertSame('executed', file_get_contents($marker));
            $this->assertSame($exitCode === 0 ? 0 : 1, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertSame(
                $exitCode === 0 ? hash_file('sha256', $asset) : $before,
                hash_file('sha256', $current)
            );
            $this->assertSame(0750, fileperms($current) & 0777);
        } finally {
            TestHelper::removeDir($directory);
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public static function provideArchiveExitCodes(): array
    {
        return ['successful version' => [0], 'failed version' => [7]];
    }

    private function createArchiveFixture(string $path, string $marker, int $exitCode): void
    {
        $code = <<<'PHP'
$archive = new Phar($argv[1]);
$archive->addFromString('VERSION', '9.9.9');
$archive->setStub('<?php file_put_contents(' . var_export($argv[2], true)
    . ", 'executed'); echo 'Synthetic archive version'.PHP_EOL; exit(" . (int) $argv[3] . '); __HALT_COMPILER();');
PHP;
        $process = new Process([PHP_BINARY, '-d', 'phar.readonly=0', '-r', $code, $path, $marker, (string) $exitCode]);
        $process->mustRun();
        $this->assertFileExists($path);
    }

    private function runUpdateWithLocalAsset(string $current, string $asset): CommandTester
    {
        $command = new class ($asset) extends SelfUpdateCommand {
            public function __construct(private string $asset)
            {
                parent::__construct();
            }

            protected function isRunningAsPhar(): bool
            {
                return true;
            }

            protected function getCurrentVersion(): string
            {
                return '0.0.1';
            }

            /**
             * @return list<array{
             *     tag_name: string,
             *     prerelease: bool,
             *     assets: list<array{name: string, browser_download_url: string}>
             * }>
             */
            protected function getGitHubReleases(SymfonyStyle $io, bool $includePrerelease): ?array
            {
                return [[
                    'tag_name' => 'v9.9.9',
                    'prerelease' => false,
                    'assets' => [['name' => 'kvs.phar', 'browser_download_url' => 'file://' . $this->asset]],
                ]];
            }
        };
        $previousArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = [$current];
        try {
            $tester = new CommandTester($command);
            ob_start();
            try {
                $tester->execute(['--yes' => true]);
            } finally {
                ob_end_clean();
            }
            return $tester;
        } finally {
            if ($previousArgv === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $previousArgv;
            }
        }
    }
}
