<?php

declare(strict_types=1);

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class DockerLauncherTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kvs-launcher-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/bin', 0700, true);
        mkdir($this->directory . '/site', 0700);
        mkdir($this->directory . '/other', 0700);
        file_put_contents($this->directory . '/kvs.phar', 'synthetic PHAR');
        copy(__DIR__ . '/../bin/kvs-docker', $this->directory . '/kvs');
        chmod($this->directory . '/kvs', 0755);
        foreach (['bash', 'dirname', 'basename', 'sh', 'cat', 'cut', 'sleep', 'mv', 'mktemp', 'cmp', 'chmod', 'rm', 'cp'] as $command) {
            $which = new Process(['which', $command]);
            $which->mustRun();
            symlink(trim($which->getOutput()), $this->directory . '/bin/' . $command);
        }

        $docker = <<<'PHP'
<?php
$args = array_slice($argv, 1);
file_put_contents(getenv('MOCK_LOG'), json_encode($args) . "\n", FILE_APPEND);
if ($args[0] === 'ps') {
    echo getenv('MOCK_CONTAINERS') ?: "first-php\tphp-fpm\n";
    exit((int) getenv('MOCK_PS_EXIT'));
}
if ($args[0] === 'inspect') {
    if ($args[2] === '{{.State.Running}}') {
        echo "true\n";
        exit(0);
    }
    $container = $args[3];
    $site = $container === 'second-php' ? '/other' : '/site';
    echo getenv('MOCK_ROOT') . $site . "\t/srv/site\n";
    exit(0);
}
if ($args[0] === 'cp') {
    [$source, $destination] = array_slice($args, -2);
    if (str_contains($source, ':')) {
        if ((int) getenv('MOCK_RETURN_CP_EXIT') !== 0) {
            exit((int) getenv('MOCK_RETURN_CP_EXIT'));
        }
        exit(copy(getenv('MOCK_ROOT') . '/runtime/' . basename($source), $destination) ? 0 : 1);
    }
    if ((int) getenv('MOCK_CP_EXIT') !== 0) {
        fwrite(STDERR, "Copy failed\n");
    } elseif (stream_isatty(STDOUT) && !in_array('-q', $args, true)) {
        echo "Successfully copied fixture PHAR\n";
    }
    if ((int) getenv('MOCK_CP_EXIT') === 0) {
        copy(getenv('MOCK_ROOT') . '/kvs.phar', getenv('MOCK_ROOT') . '/runtime/kvs.phar');
    }
    exit((int) getenv('MOCK_CP_EXIT'));
}
if ($args[0] === 'exec') {
    if (in_array('test', $args, true)) {
        exit(0);
    }
    if (in_array('mktemp', $args, true) ||
        (in_array('-c', $args, true) && str_contains($args[array_search('-c', $args, true) + 1], 'mktemp -d -t kvs-cli.'))) {
        if (!is_dir(getenv('MOCK_ROOT') . '/runtime')) {
            mkdir(getenv('MOCK_ROOT') . '/runtime');
        }
        touch(getenv('MOCK_ROOT') . '/runtime/pending');
        echo "/runtime/kvs-cli-test\n";
        exit(0);
    }
    if (in_array('rm', $args, true)) {
        exit(0);
    }
    if (in_array('php', $args, true)) {
        if (in_array('-r', $args, true) && str_contains($args[array_search('-r', $args, true) + 1], 'new Phar($argv[1])')) {
            $launcher = getenv('MOCK_UPDATE_LAUNCHER');
            if ($launcher === false || $launcher === '') {
                exit(3);
            }
            file_put_contents(getenv('MOCK_ROOT') . '/runtime/kvs-docker', $launcher);
            exit(0);
        }
        if (getenv('MOCK_UPDATED_PHAR') !== false && (int) getenv('MOCK_EXIT') === 0) {
            file_put_contents(getenv('MOCK_ROOT') . '/runtime/kvs.phar', getenv('MOCK_UPDATED_PHAR'));
        }
        if (getenv('MOCK_RUN_PHP') === '1') {
            if (getenv('MOCK_CANCEL_PENDING') === '1') {
                rename(getenv('MOCK_ROOT') . '/runtime/pending', getenv('MOCK_ROOT') . '/runtime/canceled');
            }
            $environment = getenv();
            foreach (['KVS_DB_HOST', 'KVS_DB_USER', 'KVS_DB_PASS', 'KVS_DB_NAME', 'KVS_DB_PORT',
                'KVS_ENV', 'KVS_ALLOW_EVAL', 'KVS_BENCHMARK_API_URL'] as $variable) {
                unset($environment[$variable]);
            }
            $environment['KVS_DB_NAME'] = 'container_fixture';
            $environment['KVS_DB_PASS'] = 'container_fixture';
            foreach ($args as $index => $argument) {
                if ($argument !== '-e') {
                    continue;
                }
                $setting = $args[$index + 1];
                if (str_contains($setting, '=')) {
                    [$name, $value] = explode('=', $setting, 2);
                } else {
                    $name = $setting;
                    $value = getenv($name);
                }
                if ($value !== false) {
                    $environment[$name] = $value;
                }
            }
            $phpArgs = array_slice($args, array_search('php', $args, true) + 1);
            $phpArgs = array_map(static fn(string $arg): string =>
                $arg === '/runtime/kvs-cli-test/kvs.phar' ? getenv('MOCK_ROOT') . '/runtime/kvs.phar' : $arg,
                $phpArgs
            );
            $environmentPairs = [];
            foreach ($environment as $name => $value) {
                $environmentPairs[] = $name . '=' . $value;
            }
            $command = array_merge([PHP_BINARY], $phpArgs);
            if (getenv('MOCK_DETACHED_PHP') === '1') {
                array_unshift($command, 'setsid');
            }
            $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, null, $environmentPairs);
            exit(proc_close($process));
        }
        if (getenv('MOCK_SKIP_INPUT') === '1') {
            echo "KVS CLI fixture version\n";
            exit(0);
        }
        echo 'stdin=' . stream_get_contents(STDIN);
        exit((int) getenv('MOCK_EXIT'));
    }
    if (in_array('sh', $args, true)) {
        $command = array_slice($args, array_search('sh', $args, true));
        $command = array_map(static fn(string $arg): string =>
            $arg === '/runtime/kvs-cli-test' ? getenv('MOCK_ROOT') . '/runtime' : $arg,
            $command
        );
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);
        exit(proc_close($process));
    }
}
fwrite(STDERR, 'Unexpected Docker invocation');
exit(99);
PHP;
        file_put_contents($this->directory . '/bin/docker', '#!' . PHP_BINARY . "\n" . $docker);
        chmod($this->directory . '/bin/docker', 0700);
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->directory);
    }

    public function testLauncherWorksWithoutHostPhpAndPreservesArgumentsAndStdin(): void
    {
        $php = new Process(['/usr/bin/env', 'php', '--version'], $this->directory, [
            'PATH' => $this->directory . '/bin',
        ]);
        $php->run();
        $this->assertSame(127, $php->getExitCode());

        $process = $this->launch(['--version', 'value with spaces; $(false)'], ['MOCK_EXIT' => '17']);

        $this->assertSame(17, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('stdin=sentinel input', $process->getOutput());
        $calls = $this->dockerCalls();
        $executions = array_values(array_filter($calls, static fn(array $call): bool => in_array('php', $call, true)));
        $this->assertSame([
            'exec', '-i', '-e', 'KVS_CLI_DOCKER_LAUNCHER=1', '-e', 'KVS_PATH=/srv/site',
            '-w', '/srv/site', 'first-php', 'php',
        ], array_slice($executions[0], 0, 10));
        $this->assertSame([
            '/runtime/kvs-cli-test/kvs.phar',
            '--path=/srv/site', '--version', 'value with spaces; $(false)',
        ], array_slice($executions[0], -4));
        $this->assertSame(['exec', 'first-php', 'rm', '-rf', '--', '/runtime/kvs-cli-test'], end($calls));
    }

    public function testMultipleSitesRequireExplicitSelection(): void
    {
        $process = $this->launch(['--version'], ['MOCK_CONTAINERS' => $this->twoSites()]);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Multiple KVS sites', $process->getErrorOutput());
        $this->assertSame([], $this->copyCalls());
    }

    public function testInteractiveVersionDoesNotPrintDockerCopyProgress(): void
    {
        if (!Process::isPtySupported()) {
            $this->markTestSkipped('PTY support is required to reproduce interactive Docker progress.');
        }
        $process = $this->launch(['--version'], ['MOCK_SKIP_INPUT' => '1'], '', true);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('KVS CLI fixture version', $process->getOutput());
        $this->assertStringNotContainsString('Successfully copied', $process->getOutput());
    }

    public function testPhpHelpUsesPublicCommandNameAndPreservesArgvAndStdin(): void
    {
        file_put_contents($this->directory . '/kvs.phar', <<<'PHP'
<?php
echo json_encode([
    'self' => $_SERVER['PHP_SELF'],
    'argv' => $_SERVER['argv'],
    'argc' => $_SERVER['argc'],
    'global_argc' => $argc,
    'input' => stream_get_contents(STDIN),
], JSON_THROW_ON_ERROR);
PHP);
        $process = $this->launch(['help', 'list'], ['MOCK_RUN_PHP' => '1']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $output = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('kvs', $output['self']);
        $this->assertSame([$this->directory . '/runtime/kvs.phar', '--path=/srv/site', 'help', 'list'], $output['argv']);
        $this->assertSame(4, $output['argc']);
        $this->assertSame(4, $output['global_argc']);
        $this->assertSame('sentinel input', $output['input']);
    }

    public function testDocumentedEnvironmentReachesContainerWithoutValuesInArguments(): void
    {
        $variables = [
            'KVS_DB_HOST' => '127.0.0.9',
            'KVS_DB_USER' => 'requested_user',
            'KVS_DB_NAME' => 'requested_database',
            'KVS_DB_PASS' => bin2hex(random_bytes(12)) . " ' \" $ ;\n",
            'KVS_DB_PORT' => '13306',
            'KVS_ENV' => 'test',
            'KVS_ALLOW_EVAL' => 'true',
            'KVS_BENCHMARK_API_URL' => 'http://fixture.invalid/api',
        ];
        file_put_contents($this->directory . '/kvs.phar', '<?php echo json_encode(getenv());');
        $process = $this->launch(['--version'], array_merge($variables, ['MOCK_RUN_PHP' => '1']));

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $environment = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        foreach ($variables as $name => $value) {
            $this->assertSame($value, $environment[$name]);
        }
        $this->assertStringNotContainsString($variables['KVS_DB_PASS'], file_get_contents($this->directory . '/calls'));
    }

    public function testUnsetEnvironmentKeepsContainerValuesAndEmptyValuesOverrideThem(): void
    {
        file_put_contents($this->directory . '/kvs.phar', '<?php echo json_encode(getenv());');
        $process = $this->launch(['--version'], ['MOCK_RUN_PHP' => '1', 'KVS_DB_PASS' => '']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $environment = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('container_fixture', $environment['KVS_DB_NAME']);
        $this->assertSame('', $environment['KVS_DB_PASS']);
    }

    /** @return array<string, array{int, int}> */
    public static function interruptionSignals(): array
    {
        return ['TERM' => [15, 143], 'INT' => [2, 130], 'HUP' => [1, 129]];
    }

    #[DataProvider('interruptionSignals')]
    public function testInterruptStopsDetachedPhpBeforeItCanWriteAgain(int $signal, int $exitCode): void
    {
        $setsid = new Process(['which', 'setsid']);
        $setsid->run();
        if (!$setsid->isSuccessful()) {
            $this->markTestSkipped('setsid is required to simulate a container process.');
        }
        symlink(trim($setsid->getOutput()), $this->directory . '/bin/setsid');
        file_put_contents($this->directory . '/kvs.phar', <<<'PHP'
<?php
if (function_exists('cli_set_process_title')) {
    cli_set_process_title('fixture worker with a custom title');
}
file_put_contents(dirname(__DIR__) . '/started', 'started');
sleep(2);
file_put_contents(dirname(__DIR__) . '/finished', 'late write');
PHP);
        $process = $this->createProcess(['eval'], ['MOCK_RUN_PHP' => '1', 'MOCK_DETACHED_PHP' => '1']);
        $process->start();
        try {
            $deadline = microtime(true) + 5;
            while (!is_file($this->directory . '/started') && microtime(true) < $deadline && $process->isRunning()) {
                usleep(10000);
            }
            $this->assertFileExists($this->directory . '/started', $process->getErrorOutput());
            $process->signal($signal);
            $process->wait();
            $this->assertSame($exitCode, $process->getExitCode(), $process->getErrorOutput());
            usleep(2200000);
            $this->assertFileDoesNotExist($this->directory . '/finished');
        } finally {
            $process->stop(0.5);
        }
    }

    public function testCancellationBeforePhpStartupPreventsApplicationExecution(): void
    {
        file_put_contents($this->directory . '/kvs.phar', <<<'PHP'
<?php
file_put_contents(dirname(__DIR__) . '/finished', 'should not run');
PHP);
        $process = $this->launch(['eval'], ['MOCK_RUN_PHP' => '1', 'MOCK_CANCEL_PENDING' => '1']);

        $this->assertSame(1, $process->getExitCode(), $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->directory . '/finished');
    }

    public function testHostPathSelectsSiteAndBecomesContainerPath(): void
    {
        $process = $this->launch(['--path', $this->directory . '/other', 'video', 'list'], [
            'MOCK_CONTAINERS' => $this->twoSites(),
        ]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame([
            'cp', '-q', '-L', '--', $this->directory . '/kvs.phar',
            'second-php:/runtime/kvs-cli-test/kvs.phar',
        ], $this->copyCalls()[0]);
        $this->assertStringNotContainsString('--path=' . $this->directory, file_get_contents($this->directory . '/calls'));
    }

    public function testCurrentDirectorySelectsItsSite(): void
    {
        $process = $this->launch(['--version'], ['MOCK_CONTAINERS' => $this->twoSites()], '/other');

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringStartsWith('second-php:', $this->copyCalls()[0][5]);
    }

    public function testPathOptionOverridesEnvironment(): void
    {
        $process = $this->launch(['--path=' . $this->directory . '/other', '--version'], [
            'MOCK_CONTAINERS' => $this->twoSites(),
            'KVS_PATH' => $this->directory . '/site',
        ]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringStartsWith('second-php:', $this->copyCalls()[0][5]);
    }

    public function testComposeLabelSupportsCustomContainerNames(): void
    {
        $process = $this->launch(['--version'], ['MOCK_CONTAINERS' => "custom-runtime\tphp-fpm\n"]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringStartsWith('custom-runtime:', $this->copyCalls()[0][5]);
    }

    public function testLegacyContainerWithoutComposeLabelIsSupported(): void
    {
        $process = $this->launch(['--version'], ['MOCK_CONTAINERS' => "legacy-php\t\n"]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringStartsWith('legacy-php:', $this->copyCalls()[0][5]);
    }

    public function testExplicitContainerSupportsOtherNames(): void
    {
        $process = $this->launch(['--version'], ['KVS_DOCKER_CONTAINER' => 'chosen-runtime']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringStartsWith('chosen-runtime:', $this->copyCalls()[0][5]);
    }

    public function testUnknownPathDoesNotFallBackToAnotherSite(): void
    {
        $process = $this->launch(['--path=/missing/site', '--version']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('No matching', $process->getErrorOutput());
        $this->assertSame([], $this->copyCalls());
    }

    public function testCopyFailureCleansUpWithoutRunningPhp(): void
    {
        $process = $this->launch(['--version'], ['MOCK_CP_EXIT' => '23']);

        $this->assertSame(23, $process->getExitCode());
        $this->assertStringContainsString('Copy failed', $process->getErrorOutput());
        $calls = $this->dockerCalls();
        $this->assertSame(['exec', 'first-php', 'rm', '-rf', '--', '/runtime/kvs-cli-test'], end($calls));
        foreach ($calls as $call) {
            $this->assertNotContains('php', $call);
        }
    }

    public function testDaemonFailureIsReported(): void
    {
        $process = $this->launch(['--version'], ['MOCK_PS_EXIT' => '1']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Cannot list Docker containers', $process->getErrorOutput());
    }

    public function testSelfUpdateAliasesPersistTheContainerUpdateOnHost(): void
    {
        $launcher = file_get_contents($this->directory . '/kvs') . "\n# Updated fixture\n";
        foreach (['self-update', 'selfupdate', 'self:update', 'self-up', 'sel'] as $command) {
            file_put_contents($this->directory . '/kvs.phar', 'synthetic PHAR');
            $process = $this->launch([$command, '--dev', '--yes'], [
                'MOCK_UPDATED_PHAR' => 'updated PHAR',
                'MOCK_UPDATE_LAUNCHER' => $launcher,
            ]);
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertSame('updated PHAR', file_get_contents($this->directory . '/kvs.phar'));
            $this->assertSame($launcher, file_get_contents($this->directory . '/kvs'));
            $this->assertStringContainsString('KVS_CLI_DOCKER_LAUNCHER=0', file_get_contents($this->directory . '/calls'));
            $this->assertSame([], glob($this->directory . '/.kvs-update.*'));
        }
    }

    public function testFailedContainerUpdateLeavesHostFilesUnchanged(): void
    {
        $launcher = file_get_contents($this->directory . '/kvs');
        $process = $this->launch(['self-update', '--dev', '--yes'], ['MOCK_EXIT' => '17']);

        $this->assertSame(17, $process->getExitCode());
        $this->assertSame('synthetic PHAR', file_get_contents($this->directory . '/kvs.phar'));
        $this->assertSame($launcher, file_get_contents($this->directory . '/kvs'));
    }

    public function testCheckModeDoesNotInstallHostFiles(): void
    {
        $process = $this->launch(['self-update', '--dev', '--check'], ['MOCK_RETURN_CP_EXIT' => '23']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('synthetic PHAR', file_get_contents($this->directory . '/kvs.phar'));
        $this->assertSame([], glob($this->directory . '/.kvs-update.*'));
    }

    public function testCopyFailureLeavesHostFilesUnchangedAndCleansStaging(): void
    {
        $launcher = file_get_contents($this->directory . '/kvs');
        $process = $this->launch(['self-update', '--dev', '--yes'], [
            'MOCK_UPDATED_PHAR' => 'updated PHAR',
            'MOCK_RETURN_CP_EXIT' => '23',
        ]);

        $this->assertSame(23, $process->getExitCode());
        $this->assertSame('synthetic PHAR', file_get_contents($this->directory . '/kvs.phar'));
        $this->assertSame($launcher, file_get_contents($this->directory . '/kvs'));
        $this->assertSame([], glob($this->directory . '/.kvs-update.*'));
    }

    public function testOlderPharWithoutBundledLauncherCanStillUpdate(): void
    {
        $launcher = file_get_contents($this->directory . '/kvs');
        $process = $this->launch(['self-update', '--yes'], ['MOCK_UPDATED_PHAR' => 'updated PHAR']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('updated PHAR', file_get_contents($this->directory . '/kvs.phar'));
        $this->assertSame($launcher, file_get_contents($this->directory . '/kvs'));
    }

    public function testBrokenBundledLauncherLeavesHostFilesUnchanged(): void
    {
        $launcher = file_get_contents($this->directory . '/kvs');
        $process = $this->launch(['self-update', '--yes'], [
            'MOCK_UPDATED_PHAR' => 'updated PHAR',
            'MOCK_UPDATE_LAUNCHER' => "#!/usr/bin/env bash\nif\n",
        ]);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertSame('synthetic PHAR', file_get_contents($this->directory . '/kvs.phar'));
        $this->assertSame($launcher, file_get_contents($this->directory . '/kvs'));
        $this->assertSame([], glob($this->directory . '/.kvs-update.*'));
    }

    public function testCancelledUpdateDoesNotReplaceHostFiles(): void
    {
        $launcher = file_get_contents($this->directory . '/kvs');
        $process = $this->launch(['self-update', '--dev']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('synthetic PHAR', file_get_contents($this->directory . '/kvs.phar'));
        $this->assertSame($launcher, file_get_contents($this->directory . '/kvs'));
        $this->assertSame([], glob($this->directory . '/.kvs-update.*'));
    }

    public function testFailedLauncherReplacementRestoresPreviousPhar(): void
    {
        unlink($this->directory . '/bin/mv');
        $which = new Process(['which', 'mv']);
        $which->mustRun();
        $realMove = trim($which->getOutput());
        file_put_contents($this->directory . '/bin/mv', '#!' . PHP_BINARY . "\n" . <<<'PHP'
<?php
if (str_ends_with($argv[3], '/next-launcher')) {
    exit(31);
}
$arguments = array_map('escapeshellarg', array_slice($argv, 1));
passthru(escapeshellarg(getenv('MOCK_REAL_MV')) . ' ' . implode(' ', $arguments), $status);
exit($status);
PHP);
        chmod($this->directory . '/bin/mv', 0700);
        $launcher = file_get_contents($this->directory . '/kvs');
        $process = $this->launch(['self-update', '--dev', '--yes'], [
            'MOCK_UPDATED_PHAR' => 'updated PHAR',
            'MOCK_UPDATE_LAUNCHER' => $launcher,
            'MOCK_REAL_MV' => $realMove,
        ]);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('previous PHAR was restored', $process->getErrorOutput());
        $this->assertSame('synthetic PHAR', file_get_contents($this->directory . '/kvs.phar'));
        $this->assertSame($launcher, file_get_contents($this->directory . '/kvs'));
        $this->assertSame([], glob($this->directory . '/.kvs-update.*'));
    }

    public function testCommandArgumentsAreNotMistakenForSelfUpdate(): void
    {
        $process = $this->launch(['eval', 'self-update']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testRootPathDoesNotSelectAnUnrelatedSite(): void
    {
        $process = $this->launch(['--path=/', '--version']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('No matching', $process->getErrorOutput());
        $this->assertSame([], $this->copyCalls());
    }

    /**
     * @param list<string> $args
     * @param array<string, string|false> $environment
     */
    private function launch(array $args, array $environment = [], string $cwd = '', bool $pty = false): Process
    {
        $process = $this->createProcess($args, $environment, $cwd, $pty);
        $process->run();
        return $process;
    }

    /**
     * @param list<string> $args
     * @param array<string, string|false> $environment
     */
    private function createProcess(array $args, array $environment = [], string $cwd = '', bool $pty = false): Process
    {
        $process = new Process(array_merge([$this->directory . '/kvs'], $args), $this->directory . $cwd, array_merge([
            'PATH' => $this->directory . '/bin',
            'KVS_CLI_PHAR' => $this->directory . '/kvs.phar',
            'KVS_PATH' => '',
            'KVS_DOCKER_CONTAINER' => '',
            'KVS_DB_HOST' => false,
            'KVS_DB_USER' => false,
            'KVS_DB_PASS' => false,
            'KVS_DB_NAME' => false,
            'KVS_DB_PORT' => false,
            'KVS_ENV' => false,
            'KVS_ALLOW_EVAL' => false,
            'KVS_BENCHMARK_API_URL' => false,
            'MOCK_ROOT' => $this->directory,
            'MOCK_LOG' => $this->directory . '/calls',
            'MOCK_EXIT' => '0',
            'MOCK_CP_EXIT' => '0',
            'MOCK_PS_EXIT' => '0',
            'MOCK_RETURN_CP_EXIT' => '0',
        ], $environment));
        $process->setPty($pty);
        $process->setInput($pty ? null : 'sentinel input');
        return $process;
    }

    private function twoSites(): string
    {
        return "first-php\tphp-fpm\nsecond-php\tphp-fpm\n";
    }

    /** @return list<list<string>> */
    private function dockerCalls(): array
    {
        $lines = file($this->directory . '/calls', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return array_map(static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);
    }

    /** @return list<list<string>> */
    private function copyCalls(): array
    {
        return array_values(array_filter($this->dockerCalls(), static fn(array $call): bool => $call[0] === 'cp'));
    }
}
