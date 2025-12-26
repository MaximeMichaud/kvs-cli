<?php

namespace KVS\CLI\Command;

use KVS\CLI\Config\Configuration;
use KVS\CLI\Constants;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class BaseCommand extends Command
{
    protected Configuration $config;
    protected ?SymfonyStyle $io = null;

    public function __construct(Configuration $config)
    {
        $this->config = $config;
        parent::__construct();
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->io = new SymfonyStyle($input, $output);

        if (!$this->config->isKvsInstalled()) {
            $this->io->warning('KVS installation not found or not properly configured.');
            $this->io->note('Make sure KVS is installed and the database is configured.');
        }
    }

    /**
     * @param list<string> $args
     */
    protected function executePhpScript(string $scriptPath, array $args = []): ?string
    {
        if (!file_exists($scriptPath)) {
            $this->io->error("Script not found: $scriptPath");
            return null;
        }

        $command = 'php ' . escapeshellarg($scriptPath);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }

        exec($command . ' 2>&1', $output, $returnCode);

        if ($returnCode !== 0) {
            $this->io->error("Script execution failed: " . implode("\n", $output));
            return null;
        }

        return implode("\n", $output);
    }

    protected function getDatabaseConnection(bool $quiet = false): ?\PDO
    {
        $dbConfig = $this->config->getDatabaseConfig();

        if ($dbConfig === []) {
            if (!$quiet) {
                $this->io->error('Database configuration not found');
            }
            return null;
        }

        try {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=' . Constants::DB_CHARSET,
                $dbConfig['host'],
                $dbConfig['database']
            );

            return new \PDO($dsn, $dbConfig['user'], $dbConfig['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        } catch (\PDOException $e) {
            if (!$quiet) {
                $this->io->error('Database connection failed: ' . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * Render a table with consistent box style
     *
     * @param list<string> $headers
     * @param list<list<string|int|null>> $rows
     */
    protected function renderTable(array $headers, array $rows): void
    {
        $table = new Table($this->io);
        $table->setStyle(Constants::TABLE_STYLE);
        $table->setHeaders($headers);
        $table->setRows($rows);
        $table->render();
    }

    /**
     * Get prefixed table name (reads tables_prefix from KVS config)
     */
    protected function table(string $name): string
    {
        return $this->config->getTablePrefix() . $name;
    }
}
