<?php

namespace KVS\CLI\Command\Migrate;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Command\Traits\ExperimentalCommandTrait;
use KVS\CLI\Config\Configuration;
use KVS\CLI\Constants;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Process\Process;

use function KVS\CLI\Utils\format_bytes;

#[AsCommand(
    name: 'migrate:to-docker',
    description: '[EXPERIMENTAL] Migrate a standalone KVS installation to Docker via KVS-Install',
    aliases: ['to-docker']
)]
class ToDockerCommand extends BaseCommand
{
    use ExperimentalCommandTrait;

    private const KVS_INSTALL_REPO = 'https://github.com/MaximeMichaud/KVS-install.git';
    private const KVS_INSTALL_DIR = '/opt/kvs';

    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::OPTIONAL, 'Source KVS installation path')
            ->addOption('domain', 'd', InputOption::VALUE_REQUIRED, 'Target domain name')
            ->addOption('email', 'e', InputOption::VALUE_REQUIRED, 'Admin email for SSL certificates')
            ->addOption('target', 't', InputOption::VALUE_REQUIRED, 'KVS-Install directory', self::KVS_INSTALL_DIR)
            ->addOption('ssl', null, InputOption::VALUE_REQUIRED, 'SSL: 1=letsencrypt, 2=zerossl, 3=selfsigned')
            ->addOption('db', null, InputOption::VALUE_REQUIRED, 'MariaDB: 1=11.8, 2=11.4, 3=10.11, 4=10.6', '1')
            ->addOption('no-content', null, InputOption::VALUE_NONE, 'Skip content migration (DB only)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompts')
            ->setHelp(<<<'EOT'
Migrate a standalone KVS installation to Docker using KVS-Install.

This command delegates to KVS-Install's setup.sh which handles:
  • Port conflict detection (80/443)
  • DNS verification
  • SSL certificate generation (Let's Encrypt, ZeroSSL, or self-signed)
  • Docker container orchestration
  • Database and content migration

<info>Examples:</info>
  kvs migrate:to-docker /var/www/site -d example.com -e admin@example.com
  kvs migrate:to-docker --domain=example.com --ssl=1      # Let's Encrypt
  kvs migrate:to-docker /var/www/site --dry-run           # Preview only

<info>SSL options:</info>
  --ssl=1  Let's Encrypt (requires valid DNS + ports 80/443)
  --ssl=2  ZeroSSL (alternative CA)
  --ssl=3  Self-signed (for testing only)

<info>Requirements:</info>
  • Docker and Docker Compose
  • Git
  • Root/sudo access
EOT
            );
        $this->configureExperimentalOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $abort = $this->confirmExperimental($input, $output);
        if ($abort !== null) {
            return $abort;
        }

        $sourcePath = $this->getStringArgument($input, 'source');
        $domain = $this->getStringOption($input, 'domain');
        $email = $this->getStringOption($input, 'email');
        $targetDir = $this->getStringOption($input, 'target') ?? self::KVS_INSTALL_DIR;
        $sslChoice = $this->getStringOption($input, 'ssl');
        $dbChoice = $this->getStringOption($input, 'db') ?? '1';
        $noContent = $this->getBoolOption($input, 'no-content');
        $dryRun = $this->getBoolOption($input, 'dry-run');
        $skipConfirm = $this->getBoolOption($input, 'yes');

        // Validate SSL and DB choices
        if ($sslChoice !== null && !in_array($sslChoice, ['1', '2', '3'], true)) {
            $this->io()->error('Invalid --ssl value. Must be 1, 2, or 3');
            return self::FAILURE;
        }
        if (!in_array($dbChoice, ['1', '2', '3', '4'], true)) {
            $this->io()->error('Invalid --db value. Must be 1, 2, 3, or 4');
            return self::FAILURE;
        }

        $this->io()->title('KVS Migration to Docker');

        // Step 1: Load and validate source
        $sourceConfig = $this->loadSourceConfig($sourcePath);
        if ($sourceConfig === null) {
            return self::FAILURE;
        }

        // Step 2: Check basic requirements
        if (!$this->checkRequirements()) {
            return self::FAILURE;
        }

        // Step 3: Interactive prompts for missing options
        $helper = $this->getHelper('question');

        if ($domain === null) {
            $question = new Question('Domain name (e.g., example.com): ');
            $question->setValidator(function (?string $value): string {
                $pattern = '/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*$/';
                if ($value === null || $value === '' || preg_match($pattern, $value) !== 1) {
                    throw new \RuntimeException('Invalid domain name');
                }
                return $value;
            });
            $result = $helper->ask($input, $output, $question);
            if (!is_string($result)) {
                $this->io()->error('Domain is required');
                return self::FAILURE;
            }
            $domain = $result;
        }

        if ($email === null) {
            $question = new Question('Admin email (for SSL certificates): ');
            $question->setValidator(function (?string $value): string {
                if ($value === null || $value === '' || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    throw new \RuntimeException('Invalid email address');
                }
                return $value;
            });
            $result = $helper->ask($input, $output, $question);
            if (!is_string($result)) {
                $this->io()->error('Email is required');
                return self::FAILURE;
            }
            $email = $result;
        }

        if ($sslChoice === null) {
            if (!$input->isInteractive()) {
                // Non-interactive mode: use Let's Encrypt by default
                $sslChoice = '1';
                $this->io()->note("Using default SSL: Let's Encrypt (use --ssl to override)");
            } else {
                $this->io()->text([
                    'SSL Certificate options:',
                    "  [1] Let's Encrypt (recommended for production)",
                    '  [2] ZeroSSL (alternative CA)',
                    '  [3] Self-signed (for testing only)',
                ]);
                $question = new Question('SSL choice [1]: ', '1');
                $question->setValidator(function (?string $value): string {
                    if ($value === null || !in_array($value, ['1', '2', '3'], true)) {
                        throw new \RuntimeException('Please enter 1, 2, or 3');
                    }
                    return $value;
                });
                $result = $helper->ask($input, $output, $question);
                $sslChoice = is_string($result) ? $result : '1';
            }
        }

        // Step 4: Display migration plan
        $this->io()->section('Migration Plan');

        $scanResult = $this->scanSource($sourceConfig);
        $sslName = match ($sslChoice) {
            '1' => "Let's Encrypt",
            '2' => 'ZeroSSL',
            default => 'Self-signed',
        };
        $dbName = match ($dbChoice) {
            '1' => 'MariaDB 11.8 (latest)',
            '2' => 'MariaDB 11.4 LTS',
            '3' => 'MariaDB 10.11 LTS',
            '4' => 'MariaDB 10.6 LTS',
        };

        $this->io()->table(['Parameter', 'Value'], [
            ['Source Path', $sourceConfig->getKvsPath()],
            ['KVS Version', $scanResult['version'] ?? 'Unknown'],
            ['Database', $scanResult['database']],
            ['Content Size', $scanResult['content_size']],
            ['---', '---'],
            ['Target Domain', $domain],
            ['KVS-Install Dir', $targetDir],
            ['SSL', $sslName],
            ['Database', $dbName],
            ['Include Content', $noContent ? 'No' : 'Yes'],
        ]);

        if ($dryRun) {
            $this->io()->note('Dry run mode - no changes will be made');
            $this->showDryRunSteps($sourceConfig, $targetDir, $domain, $sslChoice, $dbChoice);
            return self::SUCCESS;
        }

        // Step 5: Confirmation
        if (!$skipConfirm) {
            $this->io()->warning([
                'This will:',
                '• Clone/update KVS-Install in ' . $targetDir,
                '• Start Docker containers (may stop existing ones)',
                '• Import your database and content',
            ]);

            $question = new ConfirmationQuestion('Proceed with migration? [y/N] ', false);
            $confirmed = $helper->ask($input, $output, $question);
            if ($confirmed !== true) {
                $this->io()->warning('Migration cancelled');
                return self::SUCCESS;
            }
        }

        // Step 6: Execute migration
        $this->io()->section('Executing Migration');

        // 6.1: Clone/update KVS-Install
        $this->io()->text('<info>Step 1/4:</info> Setting up KVS-Install...');
        if (!$this->setupKvsInstall($targetDir)) {
            return self::FAILURE;
        }

        // 6.2: Export database from source
        $this->io()->text('<info>Step 2/4:</info> Exporting source database...');
        $dbDumpFile = $this->exportSourceDatabase($sourceConfig);
        if ($dbDumpFile === null) {
            return self::FAILURE;
        }

        // 6.3: Run KVS-Install setup.sh
        $this->io()->text('<info>Step 3/4:</info> Running KVS-Install setup...');
        $this->io()->note('KVS-Install will handle: ports, DNS, SSL, Docker containers');
        $this->io()->newLine();

        if (!$this->runKvsInstallSetup($targetDir, $domain, $email, $sslChoice, $dbChoice)) {
            // Cleanup dump file
            if (file_exists($dbDumpFile)) {
                unlink($dbDumpFile);
            }
            return self::FAILURE;
        }

        // 6.4: Import database and content
        $this->io()->text('<info>Step 4/4:</info> Importing data...');
        if (!$this->importData($targetDir, $domain, $dbDumpFile, $sourceConfig, $noContent)) {
            return self::FAILURE;
        }

        // Cleanup
        if (file_exists($dbDumpFile)) {
            unlink($dbDumpFile);
        }

        // Step 7: Success
        $this->io()->newLine();
        $this->io()->success([
            'Migration completed!',
            '',
            "Site: https://{$domain}",
            "Admin: https://{$domain}/admin/",
        ]);

        $this->io()->note([
            'Next steps:',
            '• Verify site functionality',
            '• Update DNS if not already pointing here',
            '• Check SSL certificate status',
        ]);

        return self::SUCCESS;
    }

    private function loadSourceConfig(?string $path): ?Configuration
    {
        try {
            if ($path !== null) {
                return new Configuration(['path' => $path]);
            }
            return $this->config;
        } catch (\Exception $e) {
            $this->io()->error('Source installation not found: ' . $e->getMessage());
            return null;
        }
    }

    private function checkRequirements(): bool
    {
        $missing = [];

        // Docker
        $result = shell_exec('which docker 2>/dev/null');
        if (!is_string($result) || trim($result) === '') {
            $missing[] = 'docker';
        }

        // Git
        $result = shell_exec('which git 2>/dev/null');
        if (!is_string($result) || trim($result) === '') {
            $missing[] = 'git';
        }

        // Dump command
        $hasDump = false;
        foreach (['mariadb-dump', 'mysqldump'] as $cmd) {
            $result = shell_exec("which {$cmd} 2>/dev/null");
            if (is_string($result) && trim($result) !== '') {
                $hasDump = true;
                break;
            }
        }
        if (!$hasDump) {
            $missing[] = 'mariadb-dump or mysqldump';
        }

        if ($missing !== []) {
            $this->io()->error('Missing: ' . implode(', ', $missing));
            $this->io()->text('Install: <info>apt install docker.io git mariadb-client</info>');
            return false;
        }

        return true;
    }

    /**
     * @return array{version: string|null, database: string, content_size: string}
     */
    private function scanSource(Configuration $config): array
    {
        $dbConfig = $config->getDatabaseConfig();
        $contentPath = $config->getContentPath();

        $contentSize = '0 B';
        if (is_dir($contentPath)) {
            $sizeResult = shell_exec("du -sb " . escapeshellarg($contentPath) . " 2>/dev/null | cut -f1");
            if (is_string($sizeResult)) {
                $contentSize = format_bytes((int) trim($sizeResult));
            }
        }

        return [
            'version' => $config->getKvsVersion() !== '' ? $config->getKvsVersion() : null,
            'database' => ($dbConfig['database'] ?? 'unknown') . '@' . ($dbConfig['host'] ?? 'unknown'),
            'content_size' => $contentSize,
        ];
    }

    private function showDryRunSteps(
        Configuration $config,
        string $targetDir,
        string $domain,
        string $sslChoice,
        string $dbChoice
    ): void {
        $this->io()->section('Dry Run - Commands that would be executed:');

        $dbConfig = $config->getDatabaseConfig();

        $this->io()->text('<comment># 1. Clone KVS-Install</comment>');
        $this->io()->text("git clone " . self::KVS_INSTALL_REPO . " {$targetDir}");
        $this->io()->newLine();

        $this->io()->text('<comment># 2. Export source database</comment>');
        $this->io()->text("mariadb-dump {$dbConfig['database']} > /tmp/kvs-migration.sql");
        $this->io()->newLine();

        $this->io()->text('<comment># 3. Run KVS-Install setup (headless)</comment>');
        $this->io()->text("cd {$targetDir}/docker && \\");
        $this->io()->text("  HEADLESS=y \\");
        $this->io()->text("  DOMAIN={$domain} \\");
        $this->io()->text("  EMAIL=admin@{$domain} \\");
        $this->io()->text("  SSL_CHOICE={$sslChoice} \\");
        $this->io()->text("  DB_CHOICE={$dbChoice} \\");
        $this->io()->text("  ./setup.sh");
        $this->io()->newLine();

        $this->io()->text('<comment># 4. Import database</comment>');
        $this->io()->text("docker exec -i kvs-{$domain}-mariadb mariadb kvs < /tmp/kvs-migration.sql");
        $this->io()->newLine();

        if (is_dir($config->getContentPath())) {
            $this->io()->text('<comment># 5. Copy content</comment>');
            $this->io()->text("rsync -av {$config->getContentPath()}/ /var/www/{$domain}/contents/");
        }
    }

    private function setupKvsInstall(string $targetDir): bool
    {
        if (is_dir($targetDir . '/.git')) {
            $this->io()->text('Updating existing KVS-Install...');
            $process = new Process(['git', 'pull'], $targetDir);
            $process->setTimeout(120);
            $process->run();

            if (!$process->isSuccessful()) {
                $this->io()->warning('Failed to update KVS-Install, using existing version: ' . $process->getErrorOutput());
            }
        } else {
            $this->io()->text('Cloning KVS-Install...');

            $parentDir = dirname($targetDir);
            if (!is_dir($parentDir)) {
                mkdir($parentDir, 0755, true);
            }

            $process = new Process(['git', 'clone', self::KVS_INSTALL_REPO, $targetDir]);
            $process->setTimeout(300);
            $process->run();

            if (!$process->isSuccessful()) {
                $this->io()->error('Failed to clone KVS-Install: ' . $process->getErrorOutput());
                return false;
            }
        }

        // Verify setup.sh exists
        if (!file_exists($targetDir . '/docker/setup.sh')) {
            $this->io()->error('KVS-Install setup.sh not found');
            return false;
        }

        return true;
    }

    private function exportSourceDatabase(Configuration $config): ?string
    {
        $dbConfig = $config->getDatabaseConfig();
        if ($dbConfig === []) {
            $this->io()->error('Source database configuration not found');
            return null;
        }

        $dumpCmd = null;
        foreach (['mariadb-dump', 'mysqldump'] as $cmd) {
            $result = shell_exec("which {$cmd} 2>/dev/null");
            if (is_string($result) && trim($result) !== '') {
                $dumpCmd = $cmd;
                break;
            }
        }

        if ($dumpCmd === null) {
            $this->io()->error('No dump command found');
            return null;
        }

        $host = $dbConfig['host'];
        $port = Constants::DEFAULT_MYSQL_PORT;
        if (str_contains($host, ':')) {
            [$host, $portStr] = explode(':', $host, 2);
            $port = (int) $portStr;
        }

        $outputFile = '/tmp/kvs-migration-' . uniqid() . '.sql';

        $command = sprintf(
            '%s --host=%s --port=%d --user=%s --password=%s %s > %s 2>/dev/null',
            $dumpCmd,
            escapeshellarg($host),
            $port,
            escapeshellarg($dbConfig['user']),
            escapeshellarg($dbConfig['password']),
            escapeshellarg($dbConfig['database']),
            escapeshellarg($outputFile)
        );

        $process = Process::fromShellCommandline($command);
        $process->setTimeout(3600);
        $process->run();

        if (!file_exists($outputFile) || filesize($outputFile) === 0) {
            $this->io()->error('Database export failed');
            return null;
        }

        $size = filesize($outputFile);
        $this->io()->text('Exported: ' . ($size !== false ? format_bytes($size) : '0 B'));

        return $outputFile;
    }

    private function runKvsInstallSetup(
        string $targetDir,
        string $domain,
        string $email,
        string $sslChoice,
        string $dbChoice
    ): bool {
        $dockerDir = $targetDir . '/docker';

        // Build environment variables for headless mode
        $env = [
            'HEADLESS' => 'y',
            'DOMAIN' => $domain,
            'EMAIL' => $email,
            'SSL_CHOICE' => $sslChoice,
            'DB_CHOICE' => $dbChoice,
            'IONCUBE_CHOICE' => '1',
            'CACHE_CHOICE' => '1',
            'MODE_CHOICE' => '1',
            'STOP_EXISTING' => 'Y',
            'SKIP_PRESS_ENTER' => '1',
            'PATH' => getenv('PATH'),
        ];

        $process = new Process(['bash', 'setup.sh'], $dockerDir, $env);
        $process->setTimeout(1800); // 30 minutes
        $process->setTty(Process::isTtySupported());

        $process->run(function (string $type, string $buffer): void {
            // Stream output directly
            $this->io()->write($buffer);
        });

        if (!$process->isSuccessful()) {
            $this->io()->error('KVS-Install setup failed');
            $this->io()->text('Check logs in: ' . $targetDir . '/logs/');
            return false;
        }

        return true;
    }

    private function importData(
        string $targetDir,
        string $domain,
        string $dbDumpFile,
        Configuration $sourceConfig,
        bool $noContent
    ): bool {
        // Determine container prefix (kvs-{domain} format)
        $containerPrefix = 'kvs-' . str_replace('.', '-', $domain);
        $mariadbContainer = $containerPrefix . '-mariadb';

        // Wait for database
        $this->io()->text('Waiting for database...');
        $ready = false;
        for ($i = 0; $i < 30; $i++) {
            $process = new Process(['docker', 'exec', $mariadbContainer, 'mariadb', '-u', 'root', '-e', 'SELECT 1']);
            $process->run();
            if ($process->isSuccessful()) {
                $ready = true;
                break;
            }
            sleep(2);
            $this->io()->write('.');
        }
        $this->io()->newLine();

        if (!$ready) {
            $this->io()->error('Database container not ready');
            return false;
        }

        // Get database name from .env
        $envFile = $targetDir . '/docker/.env';
        $database = str_replace(['.', '-'], '_', $domain);
        if (file_exists($envFile)) {
            $content = file_get_contents($envFile);
            if ($content !== false && preg_match('/^MARIADB_DATABASE=(.+)$/m', $content, $matches) === 1) {
                $database = trim($matches[1]);
            }
        }

        // Import database
        $this->io()->text('Importing database...');
        $command = sprintf(
            'docker exec -i %s mariadb -u root %s < %s',
            escapeshellarg($mariadbContainer),
            escapeshellarg($database),
            escapeshellarg($dbDumpFile)
        );

        $process = Process::fromShellCommandline($command);
        $process->setTimeout(3600);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->io()->error('Database import failed: ' . $process->getErrorOutput());
            return false;
        }
        $this->io()->text('Database imported');

        // Copy content
        if (!$noContent) {
            $sourcePath = $sourceConfig->getContentPath();
            $targetPath = "/var/www/{$domain}/contents";

            if (is_dir($sourcePath)) {
                $this->io()->text('Copying content files...');

                // Create target directory
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }

                $rsyncCheck = shell_exec('which rsync 2>/dev/null');
                if (is_string($rsyncCheck) && trim($rsyncCheck) !== '') {
                    $process = new Process([
                        'rsync', '-a', '--info=progress2',
                        $sourcePath . '/',
                        $targetPath . '/'
                    ]);
                    $process->setTimeout(3600);
                    $process->run(function (string $type, string $buffer): void {
                        if (str_contains($buffer, '%')) {
                            $this->io()->write("\r" . trim($buffer));
                        }
                    });
                    $this->io()->newLine();
                } else {
                    // Fallback to cp with /. to include dot files
                    $process = new Process(['cp', '-r', $sourcePath . '/.', $targetPath . '/']);
                    $process->setTimeout(3600);
                    $process->run();
                }

                if (!$process->isSuccessful()) {
                    $this->io()->error('Content copy failed: ' . $process->getErrorOutput());
                    return false;
                }

                // Fix permissions
                $process = new Process(['chown', '-R', 'www-data:www-data', $targetPath]);
                $process->setTimeout(300);
                $process->run();

                if (!$process->isSuccessful()) {
                    $this->io()->warning('Failed to set permissions: ' . $process->getErrorOutput());
                    // Don't fail on permission errors, just warn
                }

                $this->io()->text('Content copied');
            } else {
                $this->io()->warning('No content to copy (directory not found)');
            }
        }

        return true;
    }
}
