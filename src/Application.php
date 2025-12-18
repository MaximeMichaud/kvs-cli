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
use KVS\CLI\Command\System\CacheCommand;
use KVS\CLI\Command\System\CronCommand;
use KVS\CLI\Command\System\BackupCommand;
use KVS\CLI\Command\System\StatusCommand;
use KVS\CLI\Command\System\MaintenanceCommand;
use KVS\CLI\Command\Content\VideoCommand;
use KVS\CLI\Command\Content\UserCommand;
use KVS\CLI\Command\Content\AlbumCommand;
use KVS\CLI\Command\Content\CategoryCommand;
use KVS\CLI\Command\Content\TagCommand;
use KVS\CLI\Command\Content\CommentCommand;
use KVS\CLI\Command\Content\ModelCommand;
use KVS\CLI\Command\Content\DvdCommand;
use KVS\CLI\Command\Video\FormatsCommand;
use KVS\CLI\Command\Video\ScreenshotsCommand;
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
use KVS\CLI\Config\Configuration;
use KVS\CLI\Bootstrap\BootstrapState;
use KVS\CLI\Bootstrap\LoadConfiguration;
use KVS\CLI\Bootstrap\ValidateKvsInstallation;
use KVS\CLI\Bootstrap\RegisterCommands;

// Global version constant for SelfUpdateCommand
define('KVS_CLI_VERSION', '1.0.0-beta');

class Application extends BaseApplication
{
    public const VERSION = KVS_CLI_VERSION;
    public const NAME = 'KVS CLI';
    public const EXAMPLE_PATH = '/path/to/kvs';

    private ?Configuration $config = null;

    /**
     * Bootstrap steps to execute in order
     */
    private array $bootstrapSteps = [
        LoadConfiguration::class,
        ValidateKvsInstallation::class,
        RegisterCommands::class,
    ];

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);

        // Load utility functions
        require_once __DIR__ . '/utils.php';
    }

    /**
     * Override to remove completion command that doesn't work in PHAR
     * and add SelfUpdateCommand (always available, even without KVS)
     */
    protected function getDefaultCommands(): array
    {
        return [
            new \Symfony\Component\Console\Command\HelpCommand(),
            new \Symfony\Component\Console\Command\ListCommand(),
            new SelfUpdateCommand(),
        ];
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
    public function run(InputInterface $input = null, OutputInterface $output = null): int
    {
        $input = $input ?? new ArgvInput();
        $output = $output ?? new ConsoleOutput();

        // Run bootstrap process
        $state = $this->bootstrap($input, $output);

        // Store config if loaded
        $this->config = $state->getValue('config');

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

        // Check for version flag in argv
        $argv = $_SERVER['argv'] ?? [];
        $isVersionRequest = in_array('--version', $argv) || in_array('-V', $argv);

        // Commands that work without KVS
        $standaloneCommands = [
            'help', 'list', '--help',
            'self-update', 'selfupdate', 'self:update',
        ];

        return in_array($command, $standaloneCommands) || $isVersionRequest;
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
        if (in_array('KVS installation not found', $state->getErrors())) {
            $searchedPath = $state->getValue('searched_path');

            if ($searchedPath) {
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
            if (!$this->config || !$this->config->isKvsInstalled()) {
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
        if (!$this->config || !$this->config->isKvsInstalled()) {
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
            new CacheCommand($config),
            new CronCommand($config),
            new BackupCommand($config),
            new StatusCommand($config),
            new MaintenanceCommand($config),

            new VideoCommand($config),
            new UserCommand($config),
            new AlbumCommand($config),
            new CategoryCommand($config),
            new TagCommand($config),
            new CommentCommand($config),
            new ModelCommand($config),
            new DvdCommand($config),

            new FormatsCommand($config),
            new ScreenshotsCommand($config),

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
