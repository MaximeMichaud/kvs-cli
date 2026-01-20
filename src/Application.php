<?php

namespace KVS\CLI;

use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Command\Command;
use KVS\CLI\Command\System\BenchmarkCommand;
use KVS\CLI\Command\System\CacheCommand;
use KVS\CLI\Command\System\CheckCommand;
use KVS\CLI\Command\System\CronCommand;
use KVS\CLI\Command\System\BackupCommand;
use KVS\CLI\Command\System\QueueCommand;
use KVS\CLI\Command\System\StatusCommand;
use KVS\CLI\Command\System\StatsCommand;
use KVS\CLI\Command\System\MaintenanceCommand;
use KVS\CLI\Command\System\ServerCommand;
use KVS\CLI\Command\System\ConversionCommand;
use KVS\CLI\Command\System\EmailCommand;
use KVS\CLI\Command\System\AntispamCommand;
use KVS\CLI\Command\System\StatsSettingsCommand;
use KVS\CLI\Command\Content\VideoCommand;
use KVS\CLI\Command\Content\UserCommand;
use KVS\CLI\Command\Content\AlbumCommand;
use KVS\CLI\Command\Content\CategoryCommand;
use KVS\CLI\Command\Content\TagCommand;
use KVS\CLI\Command\Content\CommentCommand;
use KVS\CLI\Command\Content\ModelCommand;
use KVS\CLI\Command\Content\DvdCommand;
use KVS\CLI\Command\Content\PlaylistCommand;
use KVS\CLI\Command\Content\UserPurgeCommand;
use KVS\CLI\Command\Video\FormatsCommand;
use KVS\CLI\Command\Video\ScreenshotsCommand;
use KVS\CLI\Command\Settings\VideoFormatCommand;
use KVS\CLI\Command\Database\ExportCommand;
use KVS\CLI\Command\Database\ImportCommand;
use KVS\CLI\Command\Dev\DebugCommand;
use KVS\CLI\Command\Dev\LogCommand;
use KVS\CLI\Command\ConfigCommand;
use KVS\CLI\Command\ShellCommand;
use KVS\CLI\Command\EvalCommand;
use KVS\CLI\Command\EvalFileCommand;
use KVS\CLI\Command\PluginCommand;
use KVS\CLI\Command\SelfUpdateCommand;
use KVS\CLI\Command\CompletionCommand;
use KVS\CLI\Command\CliInfoCommand;
use KVS\CLI\Config\Configuration;
use KVS\CLI\Bootstrap\BootstrapState;
use KVS\CLI\Bootstrap\BootstrapStep;
use KVS\CLI\Bootstrap\LoadConfiguration;
use KVS\CLI\Bootstrap\ValidateKvsInstallation;
use KVS\CLI\Bootstrap\RegisterCommands;

// Define root path (works for both PHAR and source)
if (!defined('KVS_CLI_ROOT')) {
    define('KVS_CLI_ROOT', dirname(__DIR__));
}

// Read version from VERSION file (like WP-CLI)
// @phpstan-ignore function.alreadyNarrowedType
if (!is_string(KVS_CLI_ROOT)) {
    throw new \RuntimeException('KVS_CLI_ROOT constant is not a string');
}
$versionContent = file_get_contents(KVS_CLI_ROOT . '/VERSION');
if ($versionContent === false) {
    throw new \RuntimeException('Unable to read VERSION file');
}
define('KVS_CLI_VERSION', trim($versionContent));

class Application extends BaseApplication
{
    public const VERSION = KVS_CLI_VERSION;
    public const NAME = 'KVS CLI';
    public const EXAMPLE_PATH = '/path/to/kvs';

    private ?Configuration $config = null;

    /**
     * Bootstrap steps to execute in order
     * @var array<class-string<BootstrapStep>>
     */
    private array $bootstrapSteps = [
        LoadConfiguration::class,
        ValidateKvsInstallation::class,
        RegisterCommands::class,
    ];

    public function __construct()
    {
        /** @var string $version */
        $version = self::VERSION;
        parent::__construct(self::NAME, $version);

        // Load utility functions
        require_once __DIR__ . '/utils.php';
    }

    /**
     * Override default commands to add SelfUpdateCommand (always available, even without KVS)
     */
    protected function getDefaultCommands(): array
    {
        $commands = [
            new \Symfony\Component\Console\Command\HelpCommand(),
            new \Symfony\Component\Console\Command\ListCommand(),
            new SelfUpdateCommand(),
        ];

        $commands[] = new CompletionCommand();
        $commands[] = new CliInfoCommand();

        return $commands;
    }

    /**
     * Add --path option globally
     */
    protected function getDefaultInputDefinition(): InputDefinition
    {
        $definition = parent::getDefaultInputDefinition();
        $definition->addOption(new InputOption(
            'path',
            null,
            InputOption::VALUE_REQUIRED,
            'Path to KVS installation directory'
        ));

        return $definition;
    }

    /**
     * Override run to use modular bootstrap
     */
    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        $input = $input ?? new ArgvInput();
        $output = $output ?? new ConsoleOutput();

        // Run bootstrap process
        $state = $this->bootstrap($input, $output);

        // Store config if loaded
        $config = $state->getValue('config');
        if ($config instanceof Configuration) {
            $this->config = $config;
        }

        // Handle bootstrap errors for non-help commands
        if ($state->hasErrors() && !$this->isHelpCommand($input)) {
            $this->displayBootstrapErrors($state, $input, $output);
            return 1;
        }

        return parent::run($input, $output);
    }

    /**
     * Execute bootstrap steps
     */
    private function bootstrap(InputInterface $input, OutputInterface $output): BootstrapState
    {
        $state = new BootstrapState();
        $state->setValue('input', $input);
        $state->setValue('output', $output);
        $state->setValue('application', $this);

        foreach ($this->bootstrapSteps as $stepClass) {
            $step = new $stepClass();
            $state = $step->process($state);
        }

        return $state;
    }

    /**
     * Check if the command should work without KVS installation
     */
    private function isHelpCommand(InputInterface $input): bool
    {
        $command = $input->getFirstArgument();
        $argv = $_SERVER['argv'] ?? [];
        $argv = is_array($argv) ? $argv : [];

        // Check for --version/-V flag
        $isVersionRequest = in_array('--version', $argv, true) || in_array('-V', $argv, true);

        // Check for --help/-h flag (these are OPTIONS, not arguments)
        $isHelpRequest = in_array('--help', $argv, true) || in_array('-h', $argv, true);

        // Also check via input for ArrayInput in tests
        try {
            if ($input->hasParameterOption(['--version', '-V'])) {
                $isVersionRequest = true;
            }
            if ($input->hasParameterOption(['--help', '-h'])) {
                $isHelpRequest = true;
            }
        } catch (\Exception $e) {
            // Ignore if input doesn't support this
        }

        // No command given = show list (like wp-cli)
        if ($command === null && !$isHelpRequest && !$isVersionRequest) {
            return true;
        }

        // Commands that work without KVS
        $standaloneCommands = [
            'help', 'list',
            'self-update', 'selfupdate', 'self:update',
            'completion',
            'cli:info', 'info',
        ];

        return in_array($command, $standaloneCommands, true) || $isVersionRequest || $isHelpRequest;
    }

    /**
     * Display bootstrap errors with helpful suggestions
     */
    private function displayBootstrapErrors(BootstrapState $state, InputInterface $input, OutputInterface $output): void
    {
        $io = new SymfonyStyle($input, $output);

        foreach ($state->getErrors() as $error) {
            $io->error($error);
        }

        // Show contextual help for KVS not found error
        if (in_array('KVS installation not found', $state->getErrors(), true)) {
            $searchedPath = $state->getValue('searched_path');

            if (is_string($searchedPath)) {
                $io->text("The path '{$searchedPath}' does not contain a valid KVS installation.");
            } else {
                $io->text('Solutions:');
                $io->text('  • Run from a KVS directory:');
                $io->text('    cd ' . self::EXAMPLE_PATH . ' && kvs maintenance status');
                $io->newLine();
                $io->text('  • Use --path parameter:');
                $io->text('    kvs --path=' . self::EXAMPLE_PATH . ' maintenance status');
                $io->newLine();
                $io->text('  • Set KVS_PATH environment variable:');
                $io->text('    export KVS_PATH=' . self::EXAMPLE_PATH);
            }
            $io->newLine();
            $io->note('A valid KVS installation must contain admin/include/setup_db.php');
        }
    }

    /**
     * Override find to provide better error messages when KVS commands not found
     */
    public function find(string $name): Command
    {
        try {
            return parent::find($name);
        } catch (CommandNotFoundException $e) {
            // If command not found and no KVS detected, show helpful message
            if ($this->config === null || !$this->config->isKvsInstalled()) {
                // If autoExit is disabled (testing), just re-throw
                if (!$this->isAutoExitEnabled()) {
                    throw $e;
                }

                $io = new SymfonyStyle(new ArgvInput(), new ConsoleOutput());
                $io->error('KVS installation not found');
                $io->text('Cannot run KVS commands outside of a KVS installation directory.');
                $io->newLine();
                $io->text('Solutions:');
                $io->text('  • cd ' . self::EXAMPLE_PATH . ' && kvs ' . $name);
                $io->text('  • kvs --path=' . self::EXAMPLE_PATH . ' ' . $name);
                $io->newLine();
                exit(1);
            }

            throw $e;
        }
    }

    /**
     * Override getHelp to show KVS hint when no installation detected
     */
    public function getHelp(): string
    {
        $help = parent::getHelp();

        // Add hint when no KVS detected
        if ($this->config === null || !$this->config->isKvsInstalled()) {
            $help .= "\n\n<warning>⚠️  No KVS installation detected in current directory</warning>";
            $help .= "\n<info>   Use --path=" . self::EXAMPLE_PATH . " or cd to KVS directory for full functionality</info>";
        }

        return $help;
    }

    /**
     * Register all KVS commands
     * Public so it can be called by RegisterCommands bootstrap step
     */
    public function registerKvsCommands(Configuration $config): void
    {
        $this->addCommands([
            new BenchmarkCommand($config),
            new CacheCommand($config),
            new CheckCommand($config),
            new CronCommand($config),
            new BackupCommand($config),
            new QueueCommand($config),
            new StatusCommand($config),
            new StatsCommand($config),
            new MaintenanceCommand($config),
            new ServerCommand($config),
            new ConversionCommand($config),
            new EmailCommand($config),
            new AntispamCommand($config),
            new StatsSettingsCommand($config),

            new VideoCommand($config),
            new UserCommand($config),
            new UserPurgeCommand($config),
            new AlbumCommand($config),
            new CategoryCommand($config),
            new TagCommand($config),
            new CommentCommand($config),
            new ModelCommand($config),
            new DvdCommand($config),
            new PlaylistCommand($config),

            new FormatsCommand($config),
            new ScreenshotsCommand($config),

            new VideoFormatCommand($config),

            new ExportCommand($config),
            new ImportCommand($config),

            new DebugCommand($config),
            new LogCommand($config),

            new ConfigCommand($config),
            new ShellCommand($config),
            new EvalCommand($config),
            new EvalFileCommand($config),
            new PluginCommand($config),
        ]);
    }

    public function getLongVersion(): string
    {
        return sprintf(
            '<info>%s</info> version <comment>%s</comment>',
            $this->getName(),
            $this->getVersion()
        );
    }
}
