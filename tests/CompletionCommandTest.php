<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\CompletionCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CompletionCommand::class)]
class CompletionCommandTest extends TestCase
{
    private ?string $previousHome = null;
    private ?string $previousShell = null;
    private string $tempDir;

    protected function setUp(): void
    {
        $home = getenv('HOME');
        $shell = getenv('SHELL');
        $this->previousHome = is_string($home) ? $home : null;
        $this->previousShell = is_string($shell) ? $shell : null;
        $this->tempDir = TestHelper::createTempDir('kvs-completion-test-');
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('HOME', $this->previousHome);
        $this->restoreEnv('SHELL', $this->previousShell);
        TestHelper::removeDir($this->tempDir);
    }

    public function testGeneratesBashCompletionScript(): void
    {
        $tester = new CommandTester(new CompletionCommand());
        $tester->execute(['shell' => 'bash']);

        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('# KVS CLI bash completion', $output);
        $this->assertStringContainsString('_kvs_complete()', $output);
        $this->assertStringContainsString('complete -F _kvs_complete kvs', $output);
        $this->assertStringContainsString('system:status', $output);
    }

    public function testGeneratesZshCompletionScript(): void
    {
        $tester = new CommandTester(new CompletionCommand());
        $tester->execute(['shell' => 'zsh']);

        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('#compdef kvs', $output);
        $this->assertStringContainsString('_kvs()', $output);
        $this->assertStringContainsString('_kvs "$@"', $output);
        $this->assertStringContainsString('system\\:status:Show system status', $output);
    }

    public function testGeneratesFishCompletionScript(): void
    {
        $tester = new CommandTester(new CompletionCommand());
        $tester->execute(['shell' => 'fish']);

        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('# KVS CLI fish completion', $output);
        $this->assertStringContainsString('complete -c kvs -f', $output);
        $this->assertStringContainsString('__fish_seen_subcommand_from completion', $output);
        $this->assertStringContainsString('bash zsh fish', $output);
    }

    public function testCompletionScriptsIncludeCurrentCommands(): void
    {
        $bash = new CommandTester(new CompletionCommand());
        $bash->execute(['shell' => 'bash']);
        $bashOutput = $bash->getDisplay();

        $this->assertStringContainsString('cli:info', $bashOutput);
        $this->assertStringContainsString('system:queue', $bashOutput);
        $this->assertStringContainsString('settings:options', $bashOutput);
        $this->assertStringContainsString('content:playlist', $bashOutput);
        $this->assertStringContainsString('user:purge', $bashOutput);
        $this->assertStringContainsString('migrate:scan', $bashOutput);
        $this->assertStringContainsString('local video_actions="list show delete stats"', $bashOutput);
        $this->assertStringContainsString('local queue_actions="list show stats history help-action"', $bashOutput);

        $zsh = new CommandTester(new CompletionCommand());
        $zsh->execute(['shell' => 'zsh']);
        $zshOutput = $zsh->getDisplay();

        $this->assertStringContainsString('system\\:queue:Manage background task queue', $zshOutput);
        $this->assertStringContainsString('settings\\:options:Manage KVS system options', $zshOutput);
        $this->assertStringContainsString('content\\:playlist:Manage playlists', $zshOutput);

        $fish = new CommandTester(new CompletionCommand());
        $fish->execute(['shell' => 'fish']);
        $fishOutput = $fish->getDisplay();

        $this->assertStringContainsString('-a "system:queue"', $fishOutput);
        $this->assertStringContainsString('-a "settings:options"', $fishOutput);
        $this->assertStringContainsString('-a "video:screenshots"', $fishOutput);
        $this->assertStringContainsString('__fish_seen_subcommand_from content:video video" -a "list show delete stats"', $fishOutput);
    }

    public function testCompletionScriptsDoNotSuggestInvalidLegacyActions(): void
    {
        $bash = new CommandTester(new CompletionCommand());
        $bash->execute(['shell' => 'bash']);
        $bashOutput = $bash->getDisplay();

        $this->assertStringNotContainsString('local cache_actions="clear status"', $bashOutput);
        $this->assertStringNotContainsString('local debug_actions="on off status"', $bashOutput);

        $fish = new CommandTester(new CompletionCommand());
        $fish->execute(['shell' => 'fish']);
        $fishOutput = $fish->getDisplay();

        $this->assertStringNotContainsString('__fish_seen_subcommand_from system:cache" -a "clear status"', $fishOutput);
        $this->assertStringNotContainsString('__fish_seen_subcommand_from maintenance dev:debug" -a "on off status"', $fishOutput);
    }

    public function testRejectsUnsupportedShell(): void
    {
        $tester = new CommandTester(new CompletionCommand());
        $tester->execute(['shell' => 'powershell']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString(
            'Shell "powershell" not supported. Use bash, zsh, or fish.',
            $tester->getDisplay()
        );
    }

    public function testDefaultShellUsesShellEnvironment(): void
    {
        putenv('SHELL=/usr/bin/fish');

        $tester = new CommandTester(new CompletionCommand());
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('# KVS CLI fish completion', $tester->getDisplay());
    }

    public function testInstallWritesFishCompletionToTemporaryHome(): void
    {
        putenv('HOME=' . $this->tempDir);

        $tester = new CommandTester(new CompletionCommand());
        $tester->execute([
            'shell' => 'fish',
            '--install' => true,
        ]);

        $installPath = $this->tempDir . '/.config/fish/completions/kvs.fish';

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileExists($installPath);
        $this->assertStringContainsString('# KVS CLI fish completion', (string) file_get_contents($installPath));
        $this->assertStringContainsString('Installed to:', $tester->getDisplay());
        $this->assertStringContainsString('kvs.fish', $tester->getDisplay());
    }

    private function restoreEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            return;
        }

        putenv($name . '=' . $value);
    }
}
