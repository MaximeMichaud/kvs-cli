<?php

namespace KVS\CLI\Command;

use KVS\CLI\Command\Traits\EvalSecurityTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'eval',
    description: 'Execute PHP code with KVS context loaded',
    aliases: ['eval-php']
)]
class EvalCommand extends BaseCommand
{
    use EvalSecurityTrait;

    protected function configure(): void
    {
        $this
            ->addArgument('code', InputArgument::REQUIRED, 'PHP code to execute')
            ->addOption('skip-kvs', null, InputOption::VALUE_NONE, 'Skip loading KVS context')
            ->setHelp(<<<'HELP'
The <info>eval</info> command executes PHP code with the KVS context pre-loaded.

<comment>Usage:</comment>
  kvs eval 'echo "Hello World";'
  kvs eval 'return Video::count();'
  kvs eval 'var_dump(Video::find(1));'

<comment>Examples:</comment>
  # Simple echo
  kvs eval 'echo "KVS Path: " . $kvsPath;'

  # Database query
  kvs eval 'print_r(DB::query("SHOW TABLES"));'

  # Using models (table prefix is auto-configured)
  kvs eval 'echo "Total videos: " . Video::count();'

  # Return value (will be var_dumped)
  kvs eval 'return ["videos" => Video::count(), "users" => User::count()];'

<comment>Available variables:</comment>
  $kvsConfig - KVS configuration
  $kvsPath   - KVS installation path
  $db        - Database connection

<comment>Available classes:</comment>
  Video, User, Album, Category, Tag, DVD, Model_
  DB::query(), DB::escape()
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Security: Block eval in production unless explicitly allowed
        if (!$this->isEvalAllowed()) {
            $this->io()->error('Eval command is disabled in production environment.');
            $this->io()->text('Set KVS_ALLOW_EVAL=true to override, or use KVS_ENV=dev');
            return self::FAILURE;
        }

        $code = $this->getStringArgument($input, 'code');
        if ($code === null) {
            $this->io()->error('Code argument is required');
            return self::FAILURE;
        }

        $skipKvs = $this->getBoolOption($input, 'skip-kvs');

        if (!$skipKvs) {
            // Prepare KVS context variables
            $kvsPath = $this->config->getKvsPath();
            $kvsConfig = $this->config;

            // Get database connection (PDO)
            $db = $this->getDatabaseConnection();
            if ($db === null) {
                $this->io()->warning('Database connection not available');
            }

            // Get config array for easier access
            $dbConfig = $this->config->getDatabaseConfig();
            $config = [
                'project_path' => $kvsPath,
                'project_version' => $this->config->get('project_version', 'unknown'),
                'db_host' => $dbConfig['host'] ?? null,
                'db_name' => $dbConfig['database'] ?? null,
            ];

            // Load bootstrap (this defines Model and DB classes with PDO)
            $bootstrap = $this->getEvalBootstrapCode($this->config->getTablePrefix());
            eval($bootstrap);

            // Initialize Model and DB helpers with PDO connection
            // Must use global namespace since classes are defined in eval()
            if ($db !== null) {
                // @phpstan-ignore-next-line - Model and DB classes are dynamically created in bootstrap code
                \Model::setDb($db);
                // @phpstan-ignore-next-line - Model and DB classes are dynamically created in bootstrap code
                \DB::setConnection($db);
            }

            // IMPORTANT: Extract variables into current scope so eval() can access them
            // This makes $kvsPath, $kvsConfig, $db, $config, $dbConfig available in user's eval()
            extract(compact('kvsPath', 'kvsConfig', 'db', 'config', 'dbConfig'));
        }

        // Execute the user code
        $result = null;
        ob_start();
        $errorOccurred = false;

        try {
            $result = eval($code);
        } catch (\ParseError $e) {
            $errorOccurred = true;
            $this->io()->error('Parse Error: ' . $e->getMessage());
            if ($this->io()->isVerbose()) {
                $this->io()->text($e->getTraceAsString());
            }
        } catch (\Exception $e) {
            $errorOccurred = true;
            $this->io()->error('Error: ' . $e->getMessage());
            if ($this->io()->isVerbose()) {
                $this->io()->text($e->getTraceAsString());
            }
        } catch (\Error $e) {
            $errorOccurred = true;
            $this->io()->error('Fatal Error: ' . $e->getMessage());
            if ($this->io()->isVerbose()) {
                $this->io()->text($e->getTraceAsString());
            }
        }

        $outputStr = ob_get_clean();

        // Display output
        if ($outputStr !== false && $outputStr !== '') {
            $this->io()->write($outputStr);
        }

        // Display return value if any
        if (!$errorOccurred && $result !== null) {
            if (is_bool($result)) {
                $this->io()->writeln($result ? 'true' : 'false');
            } elseif (is_scalar($result)) {
                $this->io()->writeln((string)$result);
            } else {
                var_dump($result);
            }
        }

        return $errorOccurred ? self::FAILURE : self::SUCCESS;
    }
}
