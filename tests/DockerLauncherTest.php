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
        mkdir($this->directory . '/runtime', 0700);
        file_put_contents($this->directory . '/kvs.phar', <<<'PHP'
<?php
if (getenv('MOCK_SKIP_INPUT') === '1') {
    echo "KVS CLI fixture version\n";
} else {
    echo 'stdin=' . stream_get_contents(STDIN);
}
exit((int) getenv('MOCK_EXIT'));
PHP);
        foreach (
            ['bash', 'dirname', 'basename', 'sh', 'cat', 'cut', 'sleep', 'mv',
            'mkdir', 'mktemp', 'rm', 'id', 'stat', 'ln', 'sha256sum'] as $command
        ) {
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
    echo getenv('MOCK_EXTRA_MOUNTS');
    exit(0);
}
if ($args[0] === 'cp') {
    if ((int) getenv('MOCK_CP_EXIT') !== 0) {
        fwrite(STDERR, "Copy failed\n");
    } elseif (stream_isatty(STDOUT) && !in_array('-q', $args, true)) {
        echo "Successfully copied fixture PHAR\n";
    }
    if ((int) getenv('MOCK_CP_EXIT') === 0) {
        $destination = explode(':', $args[count($args) - 1], 2)[1];
        $payload = file_get_contents($args[count($args) - 2]);
        $middle = intdiv(strlen($payload), 2);
        file_put_contents($destination, substr($payload, 0, $middle));
        usleep((int) getenv('MOCK_COPY_DELAY_US'));
        file_put_contents($destination, substr($payload, $middle), FILE_APPEND);
        if (getenv('MOCK_CORRUPT_COPY') === '1') {
            file_put_contents($destination, 'incomplete transfer');
        }
    }
    exit((int) getenv('MOCK_CP_EXIT'));
}
if ($args[0] === 'exec') {
    if (in_array('-c', $args, true) &&
        str_contains($args[array_search('-c', $args, true) + 1], '/admin/include/setup.php')) {
        if (in_array('/srv/site', $args, true)) {
            echo "/srv/site\n";
        }
        exit(0);
    }
    if (in_array('php', $args, true)) {
        $phpArgs = array_slice($args, array_search('php', $args, true) + 1);
        if (getenv('MOCK_CANCEL_PENDING') === '1') {
            $runtime = dirname($phpArgs[5]);
            rename($runtime . '/pending', $runtime . '/canceled');
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
    if (in_array('sh', $args, true) || in_array('rm', $args, true)) {
        $command = array_slice($args, 2);
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
            $this->runtimePhar(),
            '--path=/srv/site', '--version', 'value with spaces; $(false)',
        ], array_slice($executions[0], -4));
        $this->assertSame(['exec', 'first-php', 'rm', '-rf', '--', dirname($this->runtimePhar())], end($calls));
    }

    public function testMultipleSitesRequireExplicitSelection(): void
    {
        $process = $this->launch(['--version'], ['MOCK_CONTAINERS' => $this->twoSites()]);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Multiple KVS sites', $process->getErrorOutput());
        $this->assertSame([], $this->copyCalls());
    }

    public function testRepeatedInvocationsReuseTheContainerPhar(): void
    {
        $first = $this->launch(['--version']);
        $second = $this->launch(['--version']);

        $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
        $this->assertCount(1, $this->copyCalls(), 'An unchanged PHAR must only be copied once.');
        $this->assertSame([], glob($this->directory . '/runtime/kvs-cli.*'));
        $this->assertCount(1, glob($this->directory . '/runtime/kvs-cli-cache-*/*.phar'));
    }

    public function testChangedPharWithIdenticalSizeAndTimestampRefreshesTheCache(): void
    {
        $phar = $this->directory . '/kvs.phar';
        file_put_contents($phar, '<?php echo "first";');
        $timestamp = filemtime($phar);
        $first = $this->launch(['--version']);
        file_put_contents($phar, '<?php echo "other";');
        touch($phar, $timestamp);
        $second = $this->launch(['--version']);

        $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        $this->assertSame('first', $first->getOutput());
        $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
        $this->assertSame('other', $second->getOutput());
        $this->assertCount(2, $this->copyCalls());
    }

    public function testDamagedOrMissingCacheIsRepairedBeforeExecution(): void
    {
        $first = $this->launch(['--version']);
        $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        $cache = glob($this->directory . '/runtime/kvs-cli-cache-*/*.phar')[0];
        file_put_contents($cache, '<?php echo "damaged cache";');
        $repaired = $this->launch(['--version']);
        $this->assertSame(0, $repaired->getExitCode(), $repaired->getErrorOutput());
        $this->assertSame($first->getOutput(), $repaired->getOutput());
        $this->assertSame(hash_file('sha256', $this->directory . '/kvs.phar'), hash_file('sha256', $cache));

        unlink($cache);
        $restored = $this->launch(['--version']);
        $this->assertSame(0, $restored->getExitCode(), $restored->getErrorOutput());
        $this->assertSame($first->getOutput(), $restored->getOutput());
        $this->assertCount(3, $this->copyCalls());
    }

    public function testIncompleteTransferCannotPoisonTheCache(): void
    {
        $process = $this->launch(['--version'], ['MOCK_CORRUPT_COPY' => '1']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('PHAR changed during transfer', $process->getErrorOutput());
        $this->assertSame('', $process->getOutput());
        $this->assertSame([], glob($this->directory . '/runtime/kvs-cli-cache-*/*.phar'));
        $this->assertSame([], glob($this->directory . '/runtime/kvs-cli.*'));
        $retry = $this->launch(['--version']);
        $this->assertSame(0, $retry->getExitCode(), $retry->getErrorOutput());
    }

    public function testFailedUpdatePreservesThePreviousCachedPhar(): void
    {
        $original = file_get_contents($this->directory . '/kvs.phar');
        $first = $this->launch(['--version']);
        file_put_contents($this->directory . '/kvs.phar', '<?php echo "updated";');
        $failed = $this->launch(['--version'], ['MOCK_CP_EXIT' => '23']);
        file_put_contents($this->directory . '/kvs.phar', $original);
        $restored = $this->launch(['--version']);

        $this->assertSame(23, $failed->getExitCode());
        $this->assertSame(0, $restored->getExitCode(), $restored->getErrorOutput());
        $this->assertSame($first->getOutput(), $restored->getOutput());
        $this->assertCount(2, $this->copyCalls());
    }

    public function testConcurrentColdStartsOnlyExecuteCompleteCopies(): void
    {
        $first = $this->createProcess(['--version'], ['MOCK_COPY_DELAY_US' => '200000']);
        $second = $this->createProcess(['--version'], ['MOCK_COPY_DELAY_US' => '200000']);
        $first->start();
        $second->start();
        try {
            $first->wait();
            $second->wait();
            $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
            $this->assertSame('stdin=sentinel input', $first->getOutput());
            $this->assertSame($first->getOutput(), $second->getOutput());
            $this->assertSame([], glob($this->directory . '/runtime/kvs-cli.*'));
            $this->assertCount(1, glob($this->directory . '/runtime/kvs-cli-cache-*/*.phar'));
        } finally {
            $first->stop(0.5);
            $second->stop(0.5);
        }
    }

    public function testCacheDirectoryMustBePrivateAndCannotBeASymlink(): void
    {
        $first = $this->launch(['--version']);
        $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        $cacheDirectory = glob($this->directory . '/runtime/kvs-cli-cache-*')[0];
        chmod($cacheDirectory, 0777);
        $unsafe = $this->launch(['--version']);
        $this->assertSame(1, $unsafe->getExitCode());
        $this->assertStringContainsString('Unsafe PHAR cache directory', $unsafe->getErrorOutput());

        chmod($cacheDirectory, 0700);
        rename($cacheDirectory, $cacheDirectory . '-saved');
        symlink($cacheDirectory . '-saved', $cacheDirectory);
        $symlinked = $this->launch(['--version']);
        $this->assertSame(1, $symlinked->getExitCode());
        $this->assertStringContainsString('Unsafe PHAR cache directory', $symlinked->getErrorOutput());
        $this->assertSame([], glob($this->directory . '/runtime/kvs-cli.*'));
        $this->assertCount(1, $this->copyCalls());
        unlink($cacheDirectory);
    }

    public function testAllContainerMountsAreProbedInOneExec(): void
    {
        $process = $this->launch(['--version'], [
            'MOCK_EXTRA_MOUNTS' => "/fixture/logs\t/var/log\n/fixture/config\t/etc/php\n",
        ]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $probes = array_values(array_filter($this->dockerCalls(), static fn(array $call): bool =>
            str_contains(implode(' ', $call), '/admin/include/setup.php')));
        $this->assertCount(1, $probes);
        $this->assertSame(['/srv/site', '/var/log', '/etc/php'], array_slice($probes[0], -3));
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
        $this->assertSame([$this->runtimePhar(), '--path=/srv/site', 'help', 'list'], $output['argv']);
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
if (in_array('--version', $argv, true)) {
    echo 'cached ready';
    exit(0);
}
if (function_exists('cli_set_process_title')) {
    cli_set_process_title('fixture worker with a custom title');
}
file_put_contents(getenv('MOCK_ROOT') . '/started', 'started');
sleep(2);
file_put_contents(getenv('MOCK_ROOT') . '/finished', 'late write');
PHP);
        $warmup = $this->launch(['--version']);
        $this->assertSame(0, $warmup->getExitCode(), $warmup->getErrorOutput());
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
            $after = $this->launch(['--version']);
            $this->assertSame(0, $after->getExitCode(), $after->getErrorOutput());
            $this->assertSame('cached ready', $after->getOutput());
            $this->assertCount(1, $this->copyCalls());
        } finally {
            $process->stop(0.5);
        }
    }

    public function testCancellationBeforePhpStartupPreventsApplicationExecution(): void
    {
        file_put_contents($this->directory . '/kvs.phar', <<<'PHP'
<?php
file_put_contents(getenv('MOCK_ROOT') . '/finished', 'should not run');
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
            'second-php:' . $this->runtimePhar(),
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
        $this->assertSame(['exec', 'first-php', 'rm', '-rf', '--', dirname($this->runtimePhar())], end($calls));
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

    public function testOldPharSelfUpdateAliasesAreBlocked(): void
    {
        foreach (['self-update', 'selfupdate', 'self:update', 'self-up', 'sel'] as $command) {
            $process = $this->launch([$command, '--yes']);
            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('on the host', $process->getErrorOutput());
        }
        $this->assertFileDoesNotExist($this->directory . '/calls');
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
        $process = new Process(array_merge([__DIR__ . '/../bin/kvs-docker'], $args), $this->directory . $cwd, array_merge([
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
            'TMPDIR' => $this->directory . '/runtime',
            'MOCK_LOG' => $this->directory . '/calls',
            'MOCK_EXIT' => '0',
            'MOCK_CP_EXIT' => '0',
            'MOCK_PS_EXIT' => '0',
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

    private function runtimePhar(): string
    {
        return explode(':', $this->copyCalls()[0][5], 2)[1];
    }

    /** @return list<list<string>> */
    private function copyCalls(): array
    {
        return array_values(array_filter($this->dockerCalls(), static fn(array $call): bool => $call[0] === 'cp'));
    }
}
