<?php

namespace KVS\CLI\Command;

use KVS\CLI\Command\Traits\EvalSecurityTrait;
use KVS\CLI\Service\TempFileManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Psy\Configuration;
use Psy\Shell;

#[AsCommand(
    name: 'shell',
    description: 'Interactive PHP shell with KVS context loaded',
    aliases: ['console', 'repl']
)]
class ShellCommand extends BaseCommand
{
    use EvalSecurityTrait;

    protected function configure(): void
    {
        $this
            ->addOption('includes', 'i', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Additional files to include')
            ->addOption('bootstrap', 'b', InputOption::VALUE_REQUIRED, 'Bootstrap file to load')
            ->setHelp(<<<'HELP'
The <info>shell</info> command starts an interactive PHP shell (REPL) with the KVS context pre-loaded.

<comment>Usage:</comment>
  kvs shell                    Start interactive shell
  kvs shell --includes=file.php  Include additional files

<comment>Available in shell:</comment>
  $config    - KVS CMS config array
  $kvsConfig - KVS CLI configuration object
  $db        - Database connection
  sql()      - KVS native SQL helper
  DB::       - Database helper class
  Video::    - Video model
  User::     - User model
  Album::    - Album model
  Category:: - Category model

<comment>Examples in shell:</comment>
  >>> $video = Video::find(1)
  >>> $videos = Video::all(5)
  >>> $config->get('project_url')
  >>> User::count()
  >>> help                     # Show PsySH help
  >>> exit                     # Exit the shell
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!class_exists('Psy\Shell')) {
            $this->io()->error('PsySH is not installed. Run: composer require psy/psysh');
            return self::FAILURE;
        }

        $this->io()->title('KVS Interactive Shell');
        $this->io()->info('Loading KVS context...');

        // Get mysqli connection for compatibility with KVS native snippets and helpers.
        $db = $this->getMysqliConnection();
        if ($db === null) {
            $this->io()->warning('Database connection not available');
        }

        // Prepare shell configuration
        $shellConfig = new Configuration([
            'prompt' => 'kvs>>> ',
            'updateCheck' => 'never',
            'startupMessage' => $this->getStartupMessage(),
        ]);

        // Create bootstrap file content with secure permissions and automatic cleanup
        $bootstrap = $this->createBootstrap($input);
        $tempBootstrap = TempFileManager::createWithContent($bootstrap, 'kvs_shell_', '.php');

        // Include bootstrap
        require $tempBootstrap;
        $this->defineKvsDatabaseConstantsForUserCode();
        $GLOBALS['config'] = $this->getKvsRuntimeConfig();

        // Initialize database connections if available
        if ($db !== null) {
            $GLOBALS['kvs_db'] = $db;
            if (class_exists('\\Model')) {
                \Model::setDb($db);
            }
            if (class_exists('\\DB')) {
                \DB::setConnection($db);
            }
        }

        // Set shell variables
        $shell = new Shell($shellConfig);
        $shell->setScopeVariables($this->getShellVariables());

        // Add custom includes
        $includes = $this->getArrayOption($input, 'includes');
        foreach ($includes as $include) {
            if (file_exists($include)) {
                require_once $include;
            }
        }

        // Run the shell
        $shell->run();

        // Note: Temp file cleanup is automatic via TempFileManager shutdown handler

        return self::SUCCESS;
    }

    private function createBootstrap(InputInterface $input): string
    {
        $kvsPath = $this->config->getKvsPath();
        $bootstrap = $this->getStringOption($input, 'bootstrap');
        $setupPath = $kvsPath . '/admin/include/setup.php';
        $evalBootstrap = $this->getEvalBootstrapCode($this->config->getTablePrefix());
        $exportedKvsPath = var_export($kvsPath, true);
        $exportedSetupPath = var_export($setupPath, true);

        $code = <<<PHP
<?php
// KVS Shell Bootstrap
\$kvsPath = {$exportedKvsPath};

// Load KVS configuration if available
if (file_exists({$exportedSetupPath})) {
    \$config = [];
    @include {$exportedSetupPath};
}

{$evalBootstrap}

// Helper functions
if (!function_exists('dd')) {
    function dd(...\$vars) {
        foreach (\$vars as \$var) {
            var_dump(\$var);
        }
        exit;
    }
}

if (!function_exists('dump')) {
    function dump(...\$vars) {
        foreach (\$vars as \$var) {
            var_dump(\$var);
        }
    }
}
PHP;

        // Add custom bootstrap if provided
        if (is_string($bootstrap) && file_exists($bootstrap)) {
            $bootstrapContent = file_get_contents($bootstrap);
            if ($bootstrapContent !== false) {
                $code .= "\n// Custom bootstrap\n" . $bootstrapContent;
            }
        }

        return $code;
    }

    /**
     * @return array<string, mixed>
     */
    private function getShellVariables(): array
    {
        $vars = [
            'config' => $this->getKvsRuntimeConfig(),
            'kvsConfig' => $this->config,
            'kvsPath' => $this->config->getKvsPath(),
        ];

        $db = $this->getMysqliConnection(true);
        if ($db !== null) {
            $vars['db'] = $db;
        }

        return $vars;
    }

    private function getStartupMessage(): string
    {
        return <<<MSG
========================================
         KVS Interactive Shell
========================================
PHP \PHP_VERSION on \PHP_OS

Variables:
  \$config    - KVS CMS config array
  \$kvsConfig - KVS CLI configuration object
  \$db        - Database connection

Classes:
  Video, User, Album, Category, Tag, DVD
  sql(), sql_pr(), mr2array(), mr2number()
  DB::query() - Run SQL queries

Type 'help' for PsySH help
Type 'exit' or Ctrl+D to quit
========================================

MSG;
    }
}
