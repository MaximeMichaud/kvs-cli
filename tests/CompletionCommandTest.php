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
        $this->assertStringContainsString('local server_actions="list show enable disable activate deactivate stats group"', $bashOutput);
        $this->assertStringContainsString(
            'local conversion_actions="list show enable disable activate deactivate debug-on debug-off log config stats"',
            $bashOutput
        );

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
        $this->assertStringContainsString('__fish_seen_subcommand_from content:video video videos" -a "list show delete stats"', $fishOutput);
    }

    public function testCompletionScriptsIncludeCommandAliases(): void
    {
        $bash = new CommandTester(new CompletionCommand());
        $bash->execute(['shell' => 'bash']);
        $bashOutput = $bash->getDisplay();

        $zsh = new CommandTester(new CompletionCommand());
        $zsh->execute(['shell' => 'zsh']);
        $zshOutput = $zsh->getDisplay();

        $fish = new CommandTester(new CompletionCommand());
        $fish->execute(['shell' => 'fish']);
        $fishOutput = $fish->getDisplay();

        $aliases = [
            'selfupdate',
            'self:update',
            'info',
            'status',
            'cache',
            'cron',
            'backup',
            'check',
            'benchmark',
            'bench',
            'queue',
            'server',
            'servers',
            'conversion',
            'email',
            'antispam',
            'stats',
            'stats-settings',
            'maint',
            'videos',
            'albums',
            'gallery',
            'users',
            'member',
            'members',
            'users:purge',
            'user:cleanup',
            'categories',
            'cat',
            'tags',
            'comments',
            'models',
            'performer',
            'performers',
            'dvds',
            'channel',
            'channels',
            'playlists',
            'plugins',
            'plug',
            'conf',
            'cfg',
            'console',
            'repl',
            'eval-php',
            'database:export',
            'db:dump',
            'database:import',
            'db:restore',
            'debug',
            'log',
            'logs',
            'formats',
            'screenshots',
            'options',
            'option',
            'video-format',
            'vformat',
            'scan',
            'package',
            'import',
            'to-docker',
        ];

        foreach ($aliases as $alias) {
            $this->assertMatchesRegularExpression(
                '/(^|\\s)' . preg_quote($alias, '/') . '(\\s|")/s',
                $bashOutput,
                sprintf('Bash completion should include alias "%s".', $alias)
            );

            $zshAlias = str_replace(':', '\\:', $alias);
            $this->assertStringContainsString(
                "'" . $zshAlias . ':',
                $zshOutput,
                sprintf('Zsh completion should include alias "%s".', $alias)
            );

            $this->assertStringContainsString(
                '-a "' . $alias . '"',
                $fishOutput,
                sprintf('Fish completion should include alias "%s".', $alias)
            );
        }
    }

    public function testCompletionScriptsIncludeActionsForCommandAliases(): void
    {
        $zsh = new CommandTester(new CompletionCommand());
        $zsh->execute(['shell' => 'zsh']);
        $zshOutput = $zsh->getDisplay();

        $this->assertStringContainsString('content:album|album|albums|gallery)', $zshOutput);
        $this->assertStringContainsString('content:model|model|models|performer|performers)', $zshOutput);
        $this->assertStringContainsString('content:dvd|dvd|dvds|channel|channels)', $zshOutput);
        $this->assertStringContainsString('system:server|server|servers)', $zshOutput);
        $this->assertStringContainsString('settings:video-format|video-format|vformat)', $zshOutput);
        $this->assertStringContainsString('maintenance|maint)', $zshOutput);

        $fish = new CommandTester(new CompletionCommand());
        $fish->execute(['shell' => 'fish']);
        $fishOutput = $fish->getDisplay();

        $this->assertStringContainsString(
            '__fish_seen_subcommand_from content:album album albums gallery" -a "list show delete"',
            $fishOutput
        );
        $this->assertStringContainsString(
            '__fish_seen_subcommand_from content:model model models performer performers" -a "list show stats"',
            $fishOutput
        );
        $this->assertStringContainsString(
            '__fish_seen_subcommand_from content:dvd dvd dvds channel channels" -a "list show stats"',
            $fishOutput
        );
        $this->assertStringContainsString(
            '__fish_seen_subcommand_from system:server server servers" -a "list show enable disable activate deactivate stats group"',
            $fishOutput
        );
        $this->assertStringContainsString(
            '__fish_seen_subcommand_from system:conversion conversion" -a "list show enable disable activate deactivate"',
            $fishOutput
        );
        $this->assertStringContainsString(
            '__fish_seen_subcommand_from system:conversion conversion" -a "debug-on debug-off log config stats"',
            $fishOutput
        );
        $this->assertStringContainsString(
            '__fish_seen_subcommand_from settings:video-format video-format vformat" -a "list show groups"',
            $fishOutput
        );
        $this->assertStringContainsString(
            '__fish_seen_subcommand_from maintenance maint" -a "on off status"',
            $fishOutput
        );
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

    public function testFishCompletionRestoresFileCompletionForPathArguments(): void
    {
        $fish = new CommandTester(new CompletionCommand());
        $fish->execute(['shell' => 'fish']);
        $fishOutput = $fish->getDisplay();

        $this->assertStringContainsString('__fish_seen_subcommand_from db:import database:import db:restore" -F', $fishOutput);
        $this->assertStringContainsString('__fish_seen_subcommand_from migrate:import import" -F', $fishOutput);
        $this->assertStringContainsString('__fish_seen_subcommand_from migrate:package package" -F', $fishOutput);
        $this->assertStringContainsString('__fish_seen_subcommand_from eval-file" -F', $fishOutput);
        $this->assertStringContainsString(
            '__fish_seen_subcommand_from db:export database:export db:dump" -s o -l output',
            $fishOutput
        );
        $this->assertStringContainsString(' -r -F', $fishOutput);
    }

    public function testZshCompletionRestoresFileCompletionForPathArguments(): void
    {
        $zsh = new CommandTester(new CompletionCommand());
        $zsh->execute(['shell' => 'zsh']);
        $zshOutput = $zsh->getDisplay();

        $this->assertStringContainsString('eval-file|db:import|database:import|db:restore', $zshOutput);
        $this->assertStringContainsString('migrate:scan|scan|\\', $zshOutput);
        $this->assertStringContainsString('migrate:package|package|migrate:import|import', $zshOutput);
        $this->assertStringContainsString('--output=[Output file path]:file:_files', $zshOutput);
        $this->assertStringContainsString('--target=[Target directory]:directory:_files -/', $zshOutput);
        $this->assertStringContainsString('--includes=[Additional files to include]:file:_files', $zshOutput);
        $this->assertStringContainsString('--bootstrap=[Bootstrap file to load]:file:_files', $zshOutput);
    }

    public function testGeneratedFishCompletionOffersFilesForFileArguments(): void
    {
        exec('command -v fish', $fishBinary, $fishExitCode);
        if ($fishExitCode !== 0 || $fishBinary === []) {
            $this->markTestSkipped('fish shell is not installed.');
        }

        $tester = new CommandTester(new CompletionCommand());
        $tester->execute(['shell' => 'fish']);

        $script = $this->tempDir . '/kvs.fish';
        $filesDir = $this->tempDir . '/files';
        mkdir($filesDir, 0755, true);
        file_put_contents($filesDir . '/backup.sql', 'select 1;');
        file_put_contents($filesDir . '/backup.tar.zst', 'archive');
        file_put_contents($script, $tester->getDisplay());

        $fishCommand = sprintf(
            'source %s; complete -C %s; printf "__SPLIT__\n"; complete -C %s',
            escapeshellarg($script),
            escapeshellarg('kvs db:import ' . $filesDir . '/b'),
            escapeshellarg('kvs db:export --output ' . $filesDir . '/b')
        );

        exec('fish -c ' . escapeshellarg($fishCommand), $output, $exitCode);
        $display = implode("\n", $output);

        $this->assertSame(0, $exitCode, $display);
        $this->assertStringContainsString($filesDir . '/backup.sql', $display);
        $this->assertStringContainsString($filesDir . '/backup.tar.zst', $display);
    }

    public function testGeneratedBashCompletionOffersFilesForFileArguments(): void
    {
        exec('command -v bash', $bashBinary, $bashExitCode);
        if ($bashExitCode !== 0 || $bashBinary === []) {
            $this->markTestSkipped('bash shell is not installed.');
        }
        if (!is_file('/usr/share/bash-completion/bash_completion')) {
            $this->markTestSkipped('bash-completion is not installed.');
        }

        $tester = new CommandTester(new CompletionCommand());
        $tester->execute(['shell' => 'bash']);

        $script = $this->tempDir . '/kvs.bash';
        $filesDir = $this->tempDir . '/files';
        mkdir($filesDir, 0755, true);
        file_put_contents($filesDir . '/backup.sql', 'select 1;');
        file_put_contents($filesDir . '/backup.tar.zst', 'archive');
        file_put_contents($script, $tester->getDisplay());

        $bashCommand = sprintf(
            <<<'BASH'
source /usr/share/bash-completion/bash_completion
source %s
COMP_WORDS=(kvs db:import %s)
COMP_CWORD=2
COMP_LINE=%s
COMP_POINT=${#COMP_LINE}
COMP_TYPE=9
COMPREPLY=()
_kvs_complete
printf 'db_import:\n'
printf '%%s\n' "${COMPREPLY[@]}"
COMP_WORDS=(kvs db:export --output %s)
COMP_CWORD=3
COMP_LINE=%s
COMP_POINT=${#COMP_LINE}
COMP_TYPE=9
COMPREPLY=()
_kvs_complete
printf 'db_export_output:\n'
printf '%%s\n' "${COMPREPLY[@]}"
BASH,
            escapeshellarg($script),
            escapeshellarg($filesDir . '/b'),
            escapeshellarg('kvs db:import ' . $filesDir . '/b'),
            escapeshellarg($filesDir . '/b'),
            escapeshellarg('kvs db:export --output ' . $filesDir . '/b')
        );

        exec('bash -lc ' . escapeshellarg($bashCommand), $output, $exitCode);
        $display = implode("\n", $output);

        $this->assertSame(0, $exitCode, $display);
        $this->assertStringContainsString($filesDir . '/backup.sql', $display);
        $this->assertStringContainsString($filesDir . '/backup.tar.zst', $display);
    }

    public function testGeneratedZshCompletionOffersFilesForFileArguments(): void
    {
        exec('command -v zsh', $zshBinary, $zshExitCode);
        if ($zshExitCode !== 0 || $zshBinary === []) {
            $this->markTestSkipped('zsh shell is not installed.');
        }

        $tester = new CommandTester(new CompletionCommand());
        $tester->execute(['shell' => 'zsh']);

        $completionDir = $this->tempDir . '/zsh-completions';
        $filesDir = $this->tempDir . '/files';
        mkdir($completionDir, 0755, true);
        mkdir($filesDir, 0755, true);
        file_put_contents($filesDir . '/backup.sql', 'select 1;');
        file_put_contents($filesDir . '/backup.tar.zst', 'archive');
        file_put_contents($completionDir . '/_kvs', $tester->getDisplay());

        $probeScript = $this->tempDir . '/probe-zsh-completion.zsh';
        file_put_contents($probeScript, <<<'ZSH'
zmodload zsh/zpty
zpty -b child zsh -f -i
zpty -w child "fpath=(${(q)1} \$fpath)"$'\n'
zpty -w child $'autoload -Uz compinit\ncompinit -D\n'
zpty -w child $'bindkey "^I" complete-word\n'
zpty -w child $'zstyle ":completion:*" menu off\n'
zpty -w child $'zstyle ":completion:*" verbose no\n'
zpty -n -w child "$2"
zpty -n -w child $'\t\t'
sleep 2

local output chunk index
for index in {1..80}; do
  if zpty -r -t child chunk; then
    output+=$chunk
  else
    sleep 0.05
  fi
done

zpty -d child
print -r -- $output
ZSH
        );

        $importOutput = $this->runZshCompletionProbe(
            $probeScript,
            $completionDir,
            'kvs db:import ' . $filesDir . '/b'
        );
        $exportOutput = $this->runZshCompletionProbe(
            $probeScript,
            $completionDir,
            'kvs db:export --output ' . $filesDir . '/b'
        );

        $this->assertStringContainsString('backup.sql', $importOutput);
        $this->assertStringContainsString('backup.tar.zst', $importOutput);
        $this->assertStringContainsString('backup.sql', $exportOutput);
        $this->assertStringContainsString('backup.tar.zst', $exportOutput);
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

    public function testHelpUsesShellSpecificInstallHints(): void
    {
        $help = (new CompletionCommand())->getHelp();

        $this->assertStringContainsString('kvs completion --install', $help);
        $this->assertStringContainsString('source ~/.bash_completion', $help);
        $this->assertStringContainsString('source ~/.zshrc', $help);
        $this->assertStringContainsString('source ~/.config/fish/completions/kvs.fish', $help);
        $this->assertStringNotContainsString('source ~/.bashrc', $help);
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
        $this->assertStringContainsString(
            'Restart your terminal or run: source ~/.config/fish/completions/kvs.fish',
            $tester->getDisplay()
        );
        $this->assertStringNotContainsString('source ~/.bashrc', $tester->getDisplay());
    }

    public function testInstallShowsShellSpecificPostInstallHint(): void
    {
        putenv('HOME=' . $this->tempDir);

        $zsh = new CommandTester(new CompletionCommand());
        $zsh->execute([
            'shell' => 'zsh',
            '--install' => true,
        ]);

        $this->assertSame(0, $zsh->getStatusCode());
        $this->assertFileExists($this->tempDir . '/.zsh/completion/_kvs');
        $this->assertStringContainsString('Restart your terminal or run: source ~/.zshrc', $zsh->getDisplay());
        $this->assertStringNotContainsString('source ~/.bashrc', $zsh->getDisplay());

        $bash = new CommandTester(new CompletionCommand());
        $bash->execute([
            'shell' => 'bash',
            '--install' => true,
        ]);

        $this->assertSame(0, $bash->getStatusCode());
        $this->assertFileExists($this->tempDir . '/.bash_completion');
        $this->assertStringContainsString('Restart your terminal or run: source ~/.bash_completion', $bash->getDisplay());
        $this->assertStringNotContainsString('source ~/.bashrc', $bash->getDisplay());
    }

    private function runZshCompletionProbe(string $probeScript, string $completionDir, string $line): string
    {
        $command = sprintf(
            'zsh -f %s %s %s 2>&1',
            escapeshellarg($probeScript),
            escapeshellarg($completionDir),
            escapeshellarg($line)
        );

        $output = [];
        exec($command, $output, $exitCode);
        $display = implode("\n", $output);

        $this->assertSame(0, $exitCode, $display);

        return $display;
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
