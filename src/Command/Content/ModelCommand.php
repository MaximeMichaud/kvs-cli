<?php

namespace KVS\CLI\Command\Content;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Output\Formatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(
    name: 'content:model',
    description: 'Manage KVS models (performers)',
    aliases: ['model', 'models', 'performer', 'performers']
)]
class ModelCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'Action to perform (list|show|stats)', 'list')
            ->addArgument('id', InputArgument::OPTIONAL, 'Model ID')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status (active|disabled)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results to show', 20)
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search in model names')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to display')
            ->addOption('field', null, InputOption::VALUE_REQUIRED, 'Display single field value')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count, ids', 'table')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation of long text fields')
            ->setHelp(<<<'HELP'
Manage KVS models (performers/actors).

<fg=yellow>AVAILABLE FIELDS:</>
  id, model_id    Model ID
  title           Model/performer name
  status          Status (Active/Disabled)
  videos          Number of videos
  rating          Average rating
  views           Total profile views

<fg=yellow>EXAMPLES:</>
  <fg=green>kvs model list</>
  <fg=green>kvs model list --status=active</>
  <fg=green>kvs model list --search="Jane"</>
  <fg=green>kvs model list --fields=id,title,videos,rating</>
  <fg=green>kvs model list --format=json</>
  <fg=green>kvs model list --format=count</>
  <fg=green>kvs model show 123</>
  <fg=green>kvs model stats</>
HELP
            );
    }

    protected function execute(InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
    {
        $action = $input->getArgument('action');

        return match ($action) {
            'list' => $this->listModels($input),
            'show' => $this->showModel($input->getArgument('id')),
            'stats' => $this->showStats(),
            default => $this->listModels($input),
        };
    }

    private function listModels(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if (!$db) {
            return self::FAILURE;
        }

        // Build query with video count
        $query = "SELECT m.*,
                 (SELECT COUNT(*) FROM ktvs_models_videos WHERE model_id = m.model_id) as video_count
                 FROM ktvs_models m
                 WHERE 1=1";

        $params = [];

        // Status filter
        if ($status = $input->getOption('status')) {
            $statusMap = ['active' => 1, 'disabled' => 0];
            if (isset($statusMap[$status])) {
                $query .= " AND m.status_id = :status";
                $params['status'] = $statusMap[$status];
            }
        }

        // Search filter
        if ($search = $input->getOption('search')) {
            $query .= " AND m.title LIKE :search";
            $params['search'] = "%$search%";
        }

        $query .= " ORDER BY m.model_id DESC LIMIT :limit";

        try {
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue('limit', (int)$input->getOption('limit'), \PDO::PARAM_INT);
            $stmt->execute();

            $models = $stmt->fetchAll();

            // Default fields
            $defaultFields = ['model_id', 'title', 'status_id', 'video_count'];

            // Format and display using Formatter
            $formatter = new Formatter($input->getOptions(), $defaultFields);
            $formatter->display($models, $this->io);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io->error('Failed to fetch models: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function showModel(?string $id): int
    {
        if (!$id) {
            $this->io->error('Model ID is required');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if (!$db) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("
                SELECT m.*,
                       (SELECT COUNT(*) FROM ktvs_models_videos WHERE model_id = m.model_id) as video_count
                FROM ktvs_models m
                WHERE m.model_id = :id
            ");
            $stmt->execute(['id' => $id]);
            $model = $stmt->fetch();

            if (!$model) {
                $this->io->error("Model not found: $id");
                return self::FAILURE;
            }

            // Display model details
            $this->io->title("Model: {$model['title']}");

            $info = [
                ['Model ID', $model['model_id']],
                ['Name', $model['title']],
                ['Status', $model['status_id'] == 1 ? 'Active' : 'Disabled'],
                ['Videos', $model['video_count']],
            ];

            if (!empty($model['description'])) {
                $info[] = ['Description', $model['description']];
            }

            $this->io->table(['Field', 'Value'], $info);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io->error('Failed to fetch model: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function showStats(): int
    {
        $db = $this->getDatabaseConnection();
        if (!$db) {
            return self::FAILURE;
        }

        try {
            $stats = [];

            // Total models
            $stmt = $db->query("SELECT COUNT(*) FROM ktvs_models");
            $stats[] = ['Total Models', number_format($stmt->fetchColumn())];

            // Active models
            $stmt = $db->query("SELECT COUNT(*) FROM ktvs_models WHERE status_id = 1");
            $stats[] = ['Active', number_format($stmt->fetchColumn())];

            // Disabled models
            $stmt = $db->query("SELECT COUNT(*) FROM ktvs_models WHERE status_id = 0");
            $stats[] = ['Disabled', number_format($stmt->fetchColumn())];

            // Models with videos
            $stmt = $db->query("SELECT COUNT(DISTINCT model_id) FROM ktvs_models_videos");
            $stats[] = ['Models with Videos', number_format($stmt->fetchColumn())];

            // Total video-model relations
            $stmt = $db->query("SELECT COUNT(*) FROM ktvs_models_videos");
            $stats[] = ['Total Video Relations', number_format($stmt->fetchColumn())];

            $this->io->title('Model Statistics');
            $this->io->table(['Metric', 'Value'], $stats);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io->error('Failed to fetch statistics: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
