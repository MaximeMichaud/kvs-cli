<?php

namespace KVS\CLI\Command\Migrate;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

use function KVS\CLI\Utils\format_bytes;

#[AsCommand(
    name: 'migrate:import',
    description: 'Import a KVS migration package into Docker',
    aliases: ['import']
)]
class ImportCommand extends Command
{
    private const KVS_INSTALL_REPO = 'https://github.com/MaximeMichaud/KVS-install.git';
    private const KVS_INSTALL_DIR = '/opt/kvs';

    private ?SymfonyStyle $io = null;

    private function io(): SymfonyStyle
    {
        assert($this->io !== null);
        return $this->io;
    }

    private function getStringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);
        return is_string($value) ? $value : null;
    }

    private function getStringArgument(InputInterface $input, string $name): ?string
    {
        $value = $input->getArgument($name);
        return is_string($value) ? $value : null;
    }

    /**
     * @param \Symfony\Component\Console\Helper\QuestionHelper $helper
     */
    private function askSslChoice($helper, InputInterface $input, OutputInterface $output): string
    {
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
        return is_string($result) ? $result : '1';
    }

    protected function configure(): void
    {
        $this
            ->addArgument('package', InputArgument::REQUIRED, 'Path to migration package (.tar.zst)')
            ->addOption('domain', 'd', InputOption::VALUE_REQUIRED, 'Target domain name')
            ->addOption('email', 'e', InputOption::VALUE_REQUIRED, 'Admin email for SSL certificates')
            ->addOption('target', 't', InputOption::VALUE_REQUIRED, 'KVS-Install directory', self::KVS_INSTALL_DIR)
            ->addOption('ssl', null, InputOption::VALUE_REQUIRED, 'SSL: 1=letsencrypt, 2=zerossl, 3=selfsigned')
            ->addOption('db', null, InputOption::VALUE_REQUIRED, 'MariaDB: 1=11.8, 2=11.4, 3=10.11, 4=10.6', '1')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompts')
            ->setHelp(<<<'EOT'
Import a KVS migration package created by migrate:package into a Docker environment.

This command:
  1. Extracts the migration package
  2. Sets up KVS-Install (Docker environment)
  3. Imports the database
  4. Copies content files

<info>Examples:</info>
  kvs migrate:import backup.tar.zst --domain=example.com --email=admin@example.com
  kvs migrate:import backup.tar.zst -d example.com -e admin@example.com --ssl=1
  kvs migrate:import backup.tar.zst -d example.com -e admin@example.com -y

<info>Typical workflow:</info>
  # On source server
  kvs migrate:package -o backup.tar.zst

  # Transfer to new server
  scp backup.tar.zst user@newserver:/tmp/

  # On destination server
  kvs migrate:import /tmp/backup.tar.zst --domain=example.com

<info>SSL options:</info>
  --ssl=1  Let's Encrypt (requires valid DNS + ports 80/443)
  --ssl=2  ZeroSSL (alternative CA)
  --ssl=3  Self-signed (for testing only)

<info>Requirements:</info>
  • Docker and Docker Compose
  • Git
  • zstd (for extraction)
  • Root/sudo access
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        $packagePath = $this->getStringArgument($input, 'package');
        if ($packagePath === null) {
            $this->io()->error('Package path is required');
            return Command::FAILURE;
        }

        $domain = $this->getStringOption($input, 'domain');
        $email = $this->getStringOption($input, 'email');
        $targetDir = $this->getStringOption($input, 'target') ?? self::KVS_INSTALL_DIR;
        $sslChoice = $this->getStringOption($input, 'ssl');
        $dbChoice = $this->getStringOption($input, 'db') ?? '1';
        $skipConfirm = $input->getOption('yes') === true;

        $this->io()->title('KVS Migration Import');

        // Step 1: Validate package
        if (!file_exists($packagePath)) {
            $this->io()->error('Package not found: ' . $packagePath);
            return Command::FAILURE;
        }

        if (!str_ends_with($packagePath, '.tar.zst')) {
            $this->io()->error('Package must be a .tar.zst file');
            return Command::FAILURE;
        }

        // Step 2: Check requirements
        if (!$this->checkRequirements()) {
            return Command::FAILURE;
        }

        // Step 3: Extract and read metadata
        $this->io()->section('Reading package');
        $extractDir = $this->extractPackage($packagePath);
        if ($extractDir === null) {
            return Command::FAILURE;
        }

        $metadata = $this->readMetadata($extractDir);
        if ($metadata === null) {
            $this->cleanup($extractDir);
            return Command::FAILURE;
        }

        // Step 4: Interactive prompts for missing options
        $helper = $this->getHelper('question');

        if ($domain === null) {
            $question = new Question('Domain name (e.g., example.com): ');
            $question->setValidator(function (?string $value): string {
                $pattern = '/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?';
                $pattern .= '(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*$/';
                if ($value === null || $value === '' || preg_match($pattern, $value) !== 1) {
                    throw new \RuntimeException('Invalid domain name');
                }
                return $value;
            });
            $result = $helper->ask($input, $output, $question);
            if (!is_string($result)) {
                $this->cleanup($extractDir);
                $this->io()->error('Domain is required');
                return Command::FAILURE;
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
                $this->cleanup($extractDir);
                $this->io()->error('Email is required');
                return Command::FAILURE;
            }
            $email = $result;
        }

        if ($sslChoice === null) {
            $sslChoice = $this->askSslChoice($helper, $input, $output);
        }

        // Step 5: Show import plan
        $this->io()->section('Import Plan');

        $sslName = match ($sslChoice) {
            '1' => "Let's Encrypt",
            '2' => 'ZeroSSL',
            default => 'Self-signed',
        };
        $dbName = match ($dbChoice) {
            '1' => 'MariaDB 11.8',
            '2' => 'MariaDB 11.4 LTS',
            '3' => 'MariaDB 10.11 LTS',
            '4' => 'MariaDB 10.6 LTS',
            default => 'MariaDB 11.8',
        };

        $dbData = is_array($metadata['database'] ?? null) ? $metadata['database'] : [];
        $contentData = is_array($metadata['content'] ?? null) ? $metadata['content'] : [];

        $dbSize = isset($dbData['size']) && is_int($dbData['size']) ? $dbData['size'] : 0;
        $contentSize = isset($contentData['size']) && is_int($contentData['size']) ? $contentData['size'] : 0;
        $contentFiles = isset($contentData['files']) && is_int($contentData['files']) ? $contentData['files'] : 0;

        $contentIncluded = isset($contentData['included']) && $contentData['included'] === true;
        $contentInfo = $contentIncluded
            ? format_bytes($contentSize) . " ({$contentFiles} files)"
            : 'Not included';

        $kvsVersion = isset($metadata['kvs_version']) && is_string($metadata['kvs_version'])
            ? $metadata['kvs_version']
            : 'Unknown';

        $this->io()->table(['Parameter', 'Value'], [
            ['Package', basename($packagePath)],
            ['KVS Version', $kvsVersion],
            ['Database Size', format_bytes($dbSize)],
            ['Content', $contentInfo],
            ['---', '---'],
            ['Target Domain', $domain],
            ['KVS-Install Dir', $targetDir],
            ['SSL', $sslName],
            ['Database', $dbName],
        ]);

        // Step 6: Confirmation
        if (!$skipConfirm) {
            $this->io()->warning([
                'This will:',
                '• Clone/update KVS-Install in ' . $targetDir,
                '• Start Docker containers',
                '• Import database and content from package',
            ]);

            $question = new ConfirmationQuestion('Proceed with import? [y/N] ', false);
            $confirmed = $helper->ask($input, $output, $question);
            if ($confirmed !== true) {
                $this->cleanup($extractDir);
                $this->io()->warning('Import cancelled');
                return Command::SUCCESS;
            }
        }

        // Step 7: Execute import
        $this->io()->section('Executing Import');

        // 7.1: Setup KVS-Install
        $this->io()->text('<info>Step 1/4:</info> Setting up KVS-Install...');
        if (!$this->setupKvsInstall($targetDir)) {
            $this->cleanup($extractDir);
            return Command::FAILURE;
        }

        // 7.2: Run KVS-Install setup
        $this->io()->text('<info>Step 2/4:</info> Running KVS-Install setup...');
        if (!$this->runKvsInstallSetup($targetDir, $domain, $email, $sslChoice, $dbChoice)) {
            $this->cleanup($extractDir);
            return Command::FAILURE;
        }

        // 7.3: Import database
        $this->io()->text('<info>Step 3/4:</info> Importing database...');
        if (!$this->importDatabase($extractDir, $targetDir, $domain)) {
            $this->cleanup($extractDir);
            return Command::FAILURE;
        }

        // 7.4: Copy content
        if ($contentIncluded) {
            $this->io()->text('<info>Step 4/4:</info> Copying content files...');
            if (!$this->importContent($extractDir, $domain)) {
                $this->cleanup($extractDir);
                return Command::FAILURE;
            }
        } else {
            $this->io()->text('<info>Step 4/4:</info> Skipping content (not included in package)');
        }

        // Cleanup
        $this->cleanup($extractDir);

        // Success
        $this->io()->newLine();
        $this->io()->success([
            'Import completed!',
            '',
            "Site: https://{$domain}",
            "Admin: https://{$domain}/admin/",
        ]);

        return Command::SUCCESS;
    }

    private function checkRequirements(): bool
    {
        $missing = [];

        $tools = ['docker', 'git', 'zstd', 'tar'];
        foreach ($tools as $tool) {
            $result = shell_exec("which {$tool} 2>/dev/null");
            if (!is_string($result) || trim($result) === '') {
                $missing[] = $tool;
            }
        }

        if ($missing !== []) {
            $this->io()->error('Missing required tools: ' . implode(', ', $missing));
            $this->io()->text('Install with: <info>apt install docker.io git zstd</info>');
            return false;
        }

        return true;
    }

    private function extractPackage(string $packagePath): ?string
    {
        $extractDir = sys_get_temp_dir() . '/kvs-import-' . uniqid();
        mkdir($extractDir, 0755, true);

        $this->io()->text('Extracting package...');

        // Extract .tar.zst
        $command = sprintf(
            'zstd -d -c %s | tar -xf - -C %s',
            escapeshellarg($packagePath),
            escapeshellarg($extractDir)
        );

        $process = Process::fromShellCommandline($command);
        $process->setTimeout(3600);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->io()->error('Failed to extract package: ' . $process->getErrorOutput());
            $this->cleanup($extractDir);
            return null;
        }

        // Verify structure
        if (!file_exists($extractDir . '/metadata.json')) {
            $this->io()->error('Invalid package: metadata.json not found');
            $this->cleanup($extractDir);
            return null;
        }

        if (!file_exists($extractDir . '/database.sql.zst')) {
            $this->io()->error('Invalid package: database.sql.zst not found');
            $this->cleanup($extractDir);
            return null;
        }

        $this->io()->text('Package extracted');
        return $extractDir;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readMetadata(string $extractDir): ?array
    {
        $metadataFile = $extractDir . '/metadata.json';
        $content = file_get_contents($metadataFile);
        if ($content === false) {
            $this->io()->error('Failed to read metadata.json');
            return null;
        }

        $metadata = json_decode($content, true);
        if (!is_array($metadata)) {
            $this->io()->error('Invalid metadata.json');
            return null;
        }

        $createdAt = isset($metadata['created_at']) && is_string($metadata['created_at'])
            ? $metadata['created_at']
            : 'Unknown';
        $kvsVer = isset($metadata['kvs_version']) && is_string($metadata['kvs_version'])
            ? $metadata['kvs_version']
            : 'Unknown';
        $sourcePath = isset($metadata['source_path']) && is_string($metadata['source_path'])
            ? $metadata['source_path']
            : 'Unknown';

        $this->io()->text([
            'Package info:',
            '  Created: ' . $createdAt,
            '  KVS Version: ' . $kvsVer,
            '  Source: ' . $sourcePath,
        ]);

        /** @var array<string, mixed> $metadata */
        return $metadata;
    }

    private function setupKvsInstall(string $targetDir): bool
    {
        if (is_dir($targetDir . '/.git')) {
            $this->io()->text('Updating existing KVS-Install...');
            $process = new Process(['git', 'pull'], $targetDir);
            $process->setTimeout(120);
            $process->run();
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

        if (!file_exists($targetDir . '/docker/setup.sh')) {
            $this->io()->error('KVS-Install setup.sh not found');
            return false;
        }

        return true;
    }

    private function runKvsInstallSetup(
        string $targetDir,
        string $domain,
        string $email,
        string $sslChoice,
        string $dbChoice
    ): bool {
        $dockerDir = $targetDir . '/docker';

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
            'PATH' => (getenv('PATH') !== false ? getenv('PATH') : '/usr/local/bin:/usr/bin:/bin'),
        ];

        $process = new Process(['bash', 'setup.sh'], $dockerDir, $env);
        $process->setTimeout(1800);
        $process->setTty(Process::isTtySupported());

        $process->run(function (string $type, string $buffer): void {
            $this->io()->write($buffer);
        });

        if (!$process->isSuccessful()) {
            $this->io()->error('KVS-Install setup failed');
            return false;
        }

        return true;
    }

    private function importDatabase(string $extractDir, string $targetDir, string $domain): bool
    {
        $dbFile = $extractDir . '/database.sql.zst';
        $containerPrefix = 'kvs-' . str_replace('.', '-', $domain);
        $mariadbContainer = $containerPrefix . '-mariadb';

        // Wait for database
        $this->io()->text('Waiting for database...');
        $ready = false;
        for ($i = 0; $i < 30; $i++) {
            $process = new Process([
                'docker', 'exec', $mariadbContainer,
                'mariadb', '-u', 'root', '-e', 'SELECT 1'
            ]);
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

        // Decompress and import
        $this->io()->text('Importing database...');
        $command = sprintf(
            'zstd -d -c %s | docker exec -i %s mariadb -u root %s',
            escapeshellarg($dbFile),
            escapeshellarg($mariadbContainer),
            escapeshellarg($database)
        );

        $process = Process::fromShellCommandline($command);
        $process->setTimeout(3600);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->io()->error('Database import failed: ' . $process->getErrorOutput());
            return false;
        }

        $this->io()->text('Database imported');
        return true;
    }

    private function importContent(string $extractDir, string $domain): bool
    {
        $contentDir = $extractDir . '/content';
        if (!is_dir($contentDir)) {
            $this->io()->warning('No content directory in package');
            return true;
        }

        $targetPath = "/var/www/{$domain}/contents";

        if (!is_dir($targetPath)) {
            mkdir($targetPath, 0755, true);
        }

        $this->io()->text('Copying content files...');

        $rsyncCheck = shell_exec('which rsync 2>/dev/null');
        if (is_string($rsyncCheck) && trim($rsyncCheck) !== '') {
            $process = new Process([
                'rsync', '-a', '--info=progress2',
                $contentDir . '/',
                $targetPath . '/'
            ]);
        } else {
            $process = Process::fromShellCommandline(
                'cp -r ' . escapeshellarg($contentDir) . '/* ' . escapeshellarg($targetPath) . '/'
            );
        }

        $process->setTimeout(3600);
        $process->run(function (string $type, string $buffer): void {
            if (str_contains($buffer, '%')) {
                $this->io()->write("\r" . trim($buffer));
            }
        });
        $this->io()->newLine();

        // Fix permissions
        $process = new Process(['chown', '-R', 'www-data:www-data', $targetPath]);
        $process->run();

        $this->io()->text('Content imported');
        return true;
    }

    private function cleanup(string $dir): void
    {
        if (is_dir($dir)) {
            $process = Process::fromShellCommandline('rm -rf ' . escapeshellarg($dir));
            $process->run();
        }
    }
}
