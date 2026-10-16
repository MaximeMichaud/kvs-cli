<?php

declare(strict_types=1);

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
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
        foreach (['bash', 'dirname', 'basename'] as $command) {
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
    exit((int) getenv('MOCK_CP_EXIT'));
}
if ($args[0] === 'exec') {
    if (in_array('test', $args, true)) {
        exit(0);
    }
    if (in_array('mktemp', $args, true)) {
        echo "/runtime/kvs-cli-test\n";
        exit(0);
    }
    if (in_array('rm', $args, true)) {
        exit(0);
    }
    if (in_array('php', $args, true)) {
        echo 'stdin=' . stream_get_contents(STDIN);
        exit((int) getenv('MOCK_EXIT'));
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
        $this->assertContains([
            'exec', '-i', '-e', 'KVS_CLI_DOCKER_LAUNCHER=1', '-e', 'KVS_PATH=/srv/site',
            '-w', '/srv/site', 'first-php', 'php', '/runtime/kvs-cli-test/kvs.phar',
            '--path=/srv/site', '--version', 'value with spaces; $(false)',
        ], $calls);
        $this->assertSame(['exec', 'first-php', 'rm', '-rf', '--', '/runtime/kvs-cli-test'], end($calls));
    }

    public function testMultipleSitesRequireExplicitSelection(): void
    {
        $process = $this->launch(['--version'], ['MOCK_CONTAINERS' => $this->twoSites()]);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Multiple KVS sites', $process->getErrorOutput());
        $this->assertSame([], $this->copyCalls());
    }

    public function testHostPathSelectsSiteAndBecomesContainerPath(): void
    {
        $process = $this->launch(['--path', $this->directory . '/other', 'video', 'list'], [
            'MOCK_CONTAINERS' => $this->twoSites(),
        ]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame([
            'cp', '-L', '--', $this->directory . '/kvs.phar',
            'second-php:/runtime/kvs-cli-test/kvs.phar',
        ], $this->copyCalls()[0]);
        $this->assertStringNotContainsString('--path=' . $this->directory, file_get_contents($this->directory . '/calls'));
    }

    public function testCurrentDirectorySelectsItsSite(): void
    {
        $process = $this->launch(['--version'], ['MOCK_CONTAINERS' => $this->twoSites()], '/other');

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringStartsWith('second-php:', $this->copyCalls()[0][4]);
    }

    public function testPathOptionOverridesEnvironment(): void
    {
        $process = $this->launch(['--path=' . $this->directory . '/other', '--version'], [
            'MOCK_CONTAINERS' => $this->twoSites(),
            'KVS_PATH' => $this->directory . '/site',
        ]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringStartsWith('second-php:', $this->copyCalls()[0][4]);
    }

    public function testComposeLabelSupportsCustomContainerNames(): void
    {
        $process = $this->launch(['--version'], ['MOCK_CONTAINERS' => "custom-runtime\tphp-fpm\n"]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringStartsWith('custom-runtime:', $this->copyCalls()[0][4]);
    }

    public function testLegacyContainerWithoutComposeLabelIsSupported(): void
    {
        $process = $this->launch(['--version'], ['MOCK_CONTAINERS' => "legacy-php\t\n"]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringStartsWith('legacy-php:', $this->copyCalls()[0][4]);
    }

    public function testExplicitContainerSupportsOtherNames(): void
    {
        $process = $this->launch(['--version'], ['KVS_DOCKER_CONTAINER' => 'chosen-runtime']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringStartsWith('chosen-runtime:', $this->copyCalls()[0][4]);
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
     * @param array<string, string> $environment
     */
    private function launch(array $args, array $environment = [], string $cwd = ''): Process
    {
        $process = new Process(array_merge([__DIR__ . '/../bin/kvs-docker'], $args), $this->directory . $cwd, array_merge([
            'PATH' => $this->directory . '/bin',
            'KVS_CLI_PHAR' => $this->directory . '/kvs.phar',
            'KVS_PATH' => '',
            'KVS_DOCKER_CONTAINER' => '',
            'MOCK_ROOT' => $this->directory,
            'MOCK_LOG' => $this->directory . '/calls',
            'MOCK_EXIT' => '0',
            'MOCK_CP_EXIT' => '0',
            'MOCK_PS_EXIT' => '0',
        ], $environment));
        $process->setInput('sentinel input');
        $process->run();
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
