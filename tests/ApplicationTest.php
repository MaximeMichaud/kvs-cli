<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ApplicationTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application();
    }

    public function testApplicationHasName(): void
    {
        $this->assertEquals('KVS CLI', $this->app->getName());
    }

    public function testApplicationHasVersion(): void
    {
        $expectedVersion = trim(file_get_contents(__DIR__ . '/../VERSION'));
        $this->assertEquals($expectedVersion, $this->app->getVersion());
    }

    public function testComposerBinEntrypointExistsAndIsExecutable(): void
    {
        $binPath = __DIR__ . '/../bin/kvs';

        $this->assertFileExists($binPath);
        $this->assertTrue(is_executable($binPath));
    }

    public function testComposerBinEntrypointUsesComposerAutoloadProxy(): void
    {
        $tempDir = TestHelper::createTempDir('kvs-composer-bin-');
        mkdir($tempDir . '/vendor/kvs/cli/bin', 0755, true);
        copy(__DIR__ . '/../bin/kvs', $tempDir . '/vendor/kvs/cli/bin/kvs');

        $proxy = $tempDir . '/vendor/bin/kvs';
        mkdir(dirname($proxy), 0755, true);
        file_put_contents(
            $proxy,
            '<?php' . PHP_EOL
            . '$_composer_autoload_path = ' . var_export(__DIR__ . '/../vendor/autoload.php', true) . ';' . PHP_EOL
            . '$_SERVER["argv"] = [__FILE__, "--version"];' . PHP_EOL
            . '$_SERVER["argc"] = 2;' . PHP_EOL
            . 'include ' . var_export($tempDir . '/vendor/kvs/cli/bin/kvs', true) . ';' . PHP_EOL
        );

        try {
            $output = [];
            $returnCode = 0;
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($proxy) . ' 2>&1', $output, $returnCode);

            $this->assertSame(0, $returnCode, implode("\n", $output));
            $this->assertStringContainsString('KVS CLI version', implode("\n", $output));
        } finally {
            if (is_dir($tempDir)) {
                exec('rm -rf ' . escapeshellarg($tempDir));
            }
        }
    }

    public function testApplicationHasDefaultCommands(): void
    {
        $commands = $this->app->all();

        // Should have at least help and list commands
        $this->assertArrayHasKey('help', $commands);
        $this->assertArrayHasKey('list', $commands);
    }

    public function testApplicationHasPathOption(): void
    {
        $definition = $this->app->getDefinition();

        $this->assertTrue($definition->hasOption('path'));
        $option = $definition->getOption('path');
        $this->assertEquals('Path to KVS installation directory', $option->getDescription());
    }

    public function testApplicationHelp(): void
    {
        $help = $this->app->getHelp();

        // When no KVS detected, should show warning
        $this->assertStringContainsString('No KVS installation detected', $help);
    }

    public function testApplicationRun(): void
    {
        $this->app->setAutoExit(false);

        $input = new ArrayInput(['command' => 'list']);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        $exitCode = $this->app->run($input, $output);

        $display = $output->fetch();
        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Available commands:', $display);
    }

    public function testHelpFlagWorksWithoutKvs(): void
    {
        $this->app->setAutoExit(false);

        $input = new ArrayInput(['--help' => true]);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        $exitCode = $this->app->run($input, $output);

        $display = $output->fetch();
        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Usage:', $display);
    }

    public function testVersionFlagWorksWithoutKvs(): void
    {
        $this->app->setAutoExit(false);

        $input = new ArrayInput(['--version' => true]);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        $exitCode = $this->app->run($input, $output);

        $display = $output->fetch();
        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('KVS CLI', $display);
    }

    public function testNoArgsShowsListWithoutKvs(): void
    {
        $this->app->setAutoExit(false);

        $input = new ArrayInput([]);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        $exitCode = $this->app->run($input, $output);

        $display = $output->fetch();
        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Available commands:', $display);
    }

    public function testEvalSkipKvsWorksWithoutKvs(): void
    {
        $this->app->setAutoExit(false);

        $input = new ArrayInput([
            'command' => 'eval',
            'code' => 'echo "ok";',
            '--skip-kvs' => true,
        ]);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        $oldCwd = getcwd();
        $oldKvsEnv = getenv('KVS_ENV');
        $oldAllowEval = getenv('KVS_ALLOW_EVAL');
        $exitCode = 1;
        try {
            putenv('KVS_ENV=dev');
            putenv('KVS_ALLOW_EVAL');
            chdir(sys_get_temp_dir());
            $exitCode = $this->app->run($input, $output);
        } finally {
            if ($oldCwd !== false) {
                chdir($oldCwd);
            }
            if ($oldKvsEnv === false) {
                putenv('KVS_ENV');
            } else {
                putenv('KVS_ENV=' . $oldKvsEnv);
            }
            if ($oldAllowEval === false) {
                putenv('KVS_ALLOW_EVAL');
            } else {
                putenv('KVS_ALLOW_EVAL=' . $oldAllowEval);
            }
        }

        $display = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertSame('ok', trim($display));
    }

    public function testMigrateScanJsonInvalidPathWorksWithoutKvs(): void
    {
        $this->app->setAutoExit(false);

        $missingPath = sys_get_temp_dir() . '/kvs-scan-missing-' . bin2hex(random_bytes(4));
        $input = new ArrayInput([
            'command' => 'migrate:scan',
            'path' => $missingPath,
            '--json' => true,
            '--force' => true,
        ]);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        $oldCwd = getcwd();
        $oldKvsPath = getenv('KVS_PATH');
        try {
            putenv('KVS_PATH');
            chdir(sys_get_temp_dir());
            $exitCode = $this->app->run($input, $output);
        } finally {
            if ($oldCwd !== false) {
                chdir($oldCwd);
            }
            if ($oldKvsPath === false) {
                putenv('KVS_PATH');
            } else {
                putenv('KVS_PATH=' . $oldKvsPath);
            }
        }

        $display = $output->fetch();
        $data = json_decode($display, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($data);
        $this->assertTrue($data['error']);
        $this->assertStringContainsString('does not contain a valid KVS installation', $data['message']);
        $this->assertStringNotContainsString('[ERROR]', $display);
    }
}
