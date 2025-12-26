<?php

namespace KVS\CLI\Command\Content;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Output\Formatter;
use KVS\CLI\Output\StatusFormatter;
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
  albums          Number of albums
  rating          Average rating
  views           Total profile views
  country         Country name
  birth_date      Birth date
  age             Age (years)
  measurements    Body measurements
  height, weight  Physical attributes
  rank            Model rank

<fg=yellow>EXAMPLES:</>
  <fg=green>kvs model list</>
  <fg=green>kvs model list --status=active</>
  <fg=green>kvs model list --search="Jane"</>
  <fg=green>kvs model list --fields=id,title,videos,country,rank</>
  <fg=green>kvs model list --format=json</>
  <fg=green>kvs model list --format=count</>
  <fg=green>kvs model show 123</>
  <fg=green>kvs model stats</>
HELP
            );
    }

    protected function execute(InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
    {
        $action = $this->getStringArgumentOrDefault($input, 'action', 'list');
        $id = $this->getStringArgument($input, 'id');

        return match ($action) {
            'list' => $this->listModels($input),
            'show' => $this->showModel($id),
            'stats' => $this->showStats(),
            default => $this->listModels($input),
        };
    }

    private function listModels(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        // Build query with video count and country
        $query = "SELECT m.*,
                 (SELECT COUNT(*) FROM {$this->table('models')}_videos WHERE model_id = m.model_id) as video_count,
                 (SELECT COUNT(*) FROM {$this->table('models')}_albums WHERE model_id = m.model_id) as album_count,
                 c.title as country_name
                 FROM {$this->table('models')} m
                 LEFT JOIN {$this->table('countries')} c ON m.country_id = c.country_id
                 WHERE 1=1";

        $params = [];

        // Status filter
        $status = $this->getStringOption($input, 'status');
        if ($status !== null) {
            $statusMap = ['active' => 1, 'disabled' => 0];
            if (isset($statusMap[$status])) {
                $query .= " AND m.status_id = :status";
                $params['status'] = $statusMap[$status];
            }
        }

        // Search filter
        $search = $this->getStringOption($input, 'search');
        if ($search !== null) {
            $query .= " AND m.title LIKE :search";
            $params['search'] = "%$search%";
        }

        $query .= " ORDER BY m.model_id DESC LIMIT :limit";

        try {
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue('limit', $this->getIntOptionOrDefault($input, 'limit', 20), \PDO::PARAM_INT);
            $stmt->execute();

            $models = $stmt->fetchAll();

            // Transform models for display (field aliases)
            $transformedModels = array_values(array_map(function (array $model): array {
                // Calculate rating
                $ratingAmount = (int)($model['rating_amount'] ?? 0);
                $calculatedRating = $ratingAmount > 0
                    ? round((float) $model['rating'] / $ratingAmount, 1)
                    : 0;

                return [
                    'model_id' => $model['model_id'],
                    'id' => $model['model_id'],
                    'title' => $model['title'],
                    'status_id' => $model['status_id'],
                    'status' => StatusFormatter::model((int)$model['status_id'], false),
                    'video_count' => $model['video_count'],
                    'videos' => $model['video_count'],
                    'album_count' => $model['album_count'],
                    'albums' => $model['album_count'],
                    'model_viewed' => $model['model_viewed'] ?? 0,
                    'views' => $model['model_viewed'] ?? 0,
                    'country_name' => $model['country_name'] ?? '',
                    'country' => $model['country_name'] ?? '',
                    'birth_date' => $model['birth_date'] ?? '',
                    'age' => $model['age'] ?? '',
                    'measurements' => $model['measurements'] ?? '',
                    'height' => $model['height'] ?? '',
                    'weight' => $model['weight'] ?? '',
                    'rank' => $model['rank'] ?? '',
                    'rating' => $calculatedRating,
                ];
            }, $models));

            // Default fields
            $defaultFields = ['model_id', 'title', 'status_id', 'video_count'];

            // Format and display using Formatter
            $formatter = new Formatter($input->getOptions(), $defaultFields);
            $formatter->display($transformedModels, $this->io());

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch models: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function showModel(?string $id): int
    {
        if ($id === null || $id === '') {
            $this->io()->error('Model ID is required');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("
                SELECT m.*,
                       (SELECT COUNT(*) FROM {$this->table('models')}_videos WHERE model_id = m.model_id) as video_count,
                       (SELECT COUNT(*) FROM {$this->table('models')}_albums WHERE model_id = m.model_id) as album_count,
                       c.title as country_name
                FROM {$this->table('models')} m
                LEFT JOIN {$this->table('countries')} c ON m.country_id = c.country_id
                WHERE m.model_id = :id
            ");
            $stmt->execute(['id' => $id]);
            $model = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($model)) {
                $this->io()->error("Model not found: $id");
                return self::FAILURE;
            }

            // Display model details
            $titleValue = $model['title'] ?? '';
            $modelTitle = is_string($titleValue) ? $titleValue : (is_scalar($titleValue) ? (string) $titleValue : '');
            $this->io()->title("Model: $modelTitle");

            $videoCount = is_numeric($model['video_count']) ? (int) $model['video_count'] : 0;
            $albumCount = is_numeric($model['album_count']) ? (int) $model['album_count'] : 0;
            $modelViewed = is_numeric($model['model_viewed'] ?? 0) ? (int) $model['model_viewed'] : 0;

            $info = [
                ['Model ID', (string) $model['model_id']],
                ['Name', $modelTitle],
                ['Status', StatusFormatter::model((int)$model['status_id'])],
                ['Videos', number_format($videoCount)],
                ['Albums', number_format($albumCount)],
                ['Views', number_format($modelViewed)],
            ];

            // Rating
            $ratingAmount = is_numeric($model['rating_amount'] ?? 0) ? (int) $model['rating_amount'] : 0;
            if ($ratingAmount > 0) {
                $rating = is_numeric($model['rating']) ? (float) $model['rating'] : 0;
                $info[] = ['Rating', sprintf('%.1f/5 (%d votes)', $rating / $ratingAmount, $ratingAmount)];
            }

            // Rank
            $rank = $model['rank'] ?? null;
            if ($rank && is_numeric($rank) && (int) $rank !== 0) {
                $info[] = ['Rank', '#' . number_format((int) $rank)];
            }

            // Country
            $countryName = $model['country_name'] ?? null;
            if (is_string($countryName) && $countryName !== '') {
                $info[] = ['Country', $countryName];
            }

            // Personal info
            $birthDate = $model['birth_date'] ?? null;
            if ($birthDate !== null && $birthDate !== '') {
                $age = $model['age'] ?? null;
                $ageStr = ($age !== null && $age !== '') ? " (age $age)" : '';
                $info[] = ['Birth Date', (string) $birthDate . $ageStr];
            }

            $measurements = $model['measurements'] ?? null;
            if ($measurements !== null && $measurements !== '') {
                $info[] = ['Measurements', (string) $measurements];
            }

            $height = $model['height'] ?? null;
            if ($height !== null && $height !== '') {
                $info[] = ['Height', (string) $height];
            }

            $weight = $model['weight'] ?? null;
            if ($weight !== null && $weight !== '') {
                $info[] = ['Weight', (string) $weight];
            }

            $description = $model['description'] ?? null;
            if ($description !== null && $description !== '') {
                $info[] = ['Description', (string) $description];
            }

            $this->renderTable(['Field', 'Value'], $info);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch model: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function showStats(): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stats = [];

            // Total models
            $stmt = $db->query("SELECT COUNT(*) FROM {$this->table('models')}");
            if ($stmt !== false) {
                $stats[] = ['Total Models', number_format((int) $stmt->fetchColumn())];
            }

            // Active models
            $stmt = $db->query("SELECT COUNT(*) FROM {$this->table('models')} WHERE status_id = " . StatusFormatter::MODEL_ACTIVE);
            if ($stmt !== false) {
                $stats[] = ['Active', number_format((int) $stmt->fetchColumn())];
            }

            // Disabled models
            $stmt = $db->query("SELECT COUNT(*) FROM {$this->table('models')} WHERE status_id = " . StatusFormatter::MODEL_DISABLED);
            if ($stmt !== false) {
                $stats[] = ['Disabled', number_format((int) $stmt->fetchColumn())];
            }

            // Models with videos
            $stmt = $db->query("SELECT COUNT(DISTINCT model_id) FROM {$this->table('models')}_videos");
            if ($stmt !== false) {
                $stats[] = ['Models with Videos', number_format((int) $stmt->fetchColumn())];
            }

            // Total video-model relations
            $stmt = $db->query("SELECT COUNT(*) FROM {$this->table('models')}_videos");
            if ($stmt !== false) {
                $stats[] = ['Total Video Relations', number_format((int) $stmt->fetchColumn())];
            }

            $this->io()->title('Model Statistics');
            $this->renderTable(['Metric', 'Value'], $stats);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch statistics: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
