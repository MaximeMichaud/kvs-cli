<?php

namespace KVS\CLI\Command\Content;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Command\Traits\RelationUsageTrait;
use KVS\CLI\Constants;
use KVS\CLI\Output\Formatter;
use KVS\CLI\Output\StatusFormatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

use function KVS\CLI\Utils\format_kvs_rating;

#[AsCommand(
    name: 'content:model',
    description: 'Manage KVS models (performers)',
    aliases: ['model', 'models', 'performer', 'performers']
)]
class ModelCommand extends BaseCommand
{
    use RelationUsageTrait;

    /** @var list<string> */
    private const SHOW_UNSUPPORTED_OPTIONS = [
        'status',
        'search',
        'group',
        'model-group',
        'tag',
        'category',
        'usage',
        'field-filter',
        'limit',
    ];

    /** @var list<string> */
    private const MODEL_STRING_FIELD_FILTER_COLUMNS = [
        'description',
        'alias',
        'screenshot1',
        'screenshot2',
        'country',
        'city',
        'state',
        'height',
        'weight',
        'measurements',
        'gallery_url',
        'custom1',
        'custom2',
        'custom3',
        'custom4',
        'custom5',
        'custom6',
        'custom7',
        'custom8',
        'custom9',
        'custom10',
        'custom_file1',
        'custom_file2',
        'custom_file3',
        'custom_file4',
        'custom_file5',
    ];

    /** @var list<string> */
    private const MODEL_ZERO_FIELD_FILTER_COLUMNS = [
        'hair_id',
        'eye_color_id',
        'age',
        'model_viewed',
    ];

    /** @var list<string> */
    private const MODEL_SPECIAL_FIELD_FILTERS = [
        'group',
        'rating',
        'tags',
        'categories',
    ];

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'Action to perform (list|show|stats)', 'list')
            ->addArgument('id', InputArgument::OPTIONAL, 'Model ID')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status (active|disabled|inactive)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results to show', Constants::DEFAULT_CONTENT_LIMIT)
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search in model names, directories, descriptions, aliases, and gallery URLs')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'Filter by model group ID or title')
            ->addOption('model-group', null, InputOption::VALUE_REQUIRED, 'Filter by model group ID or title')
            ->addOption('tag', null, InputOption::VALUE_REQUIRED, 'Filter by tag ID or name')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Filter by category ID or title')
            ->addOption('usage', null, InputOption::VALUE_REQUIRED, 'KVS admin usage filter (e.g. used/videos)')
            ->addOption('field-filter', null, InputOption::VALUE_REQUIRED, 'KVS admin field filter (e.g. filled/description)')
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
  <fg=green>kvs model list --status=inactive</>
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
        $action = $this->getStringArgument($input, 'action') ?? 'list';
        $id = $this->getStringArgument($input, 'id');

        return match ($action) {
            'list' => $this->listModels($input),
            'show' => $this->showModel($id, $input),
            'stats' => $this->showStats($input),
            default => $this->failUnknownAction('model', $action, ['list', 'show', 'stats']),
        };
    }

    private function listModels(InputInterface $input): int
    {
        if ($this->rejectUnsupportedArgument($input, 'list', 'id', 'a model ID argument', 'show', 'a specific model')) {
            return self::FAILURE;
        }

        if ($this->hasModelListOptionConflicts($input)) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $fromClause = "FROM {$this->table('models')} m";
        $whereClause = "WHERE 1=1";

        $params = [];

        // Status filter
        $status = $this->getStringOption($input, 'status');
        if ($status !== null) {
            $statusId = $this->parseStatusFilterOrFail($input, [
                'active' => StatusFormatter::MODEL_ACTIVE,
                'disabled' => StatusFormatter::MODEL_DISABLED,
                'inactive' => StatusFormatter::MODEL_DISABLED,
            ]);
            if ($statusId === false) {
                return self::FAILURE;
            }
            if ($statusId !== null) {
                $whereClause .= " AND m.status_id = :status";
                $params['status'] = $statusId;
            }
        }

        // Search filter
        $search = $this->getStringOption($input, 'search');
        if ($search !== null) {
            $whereClause .= ' AND ' . $this->buildAdminSearchCondition(
                'm.model_id',
                [
                    'm.title',
                    'm.dir',
                    'm.description',
                    'm.alias',
                    'm.gallery_url',
                ],
                $search,
                $params
            );
        }

        if (!$this->applyModelAdminFilters($db, $input, $whereClause, $params)) {
            return self::FAILURE;
        }

        if ($this->getStringOptionOrDefault($input, 'format', 'table') === 'count') {
            if ($this->rejectFieldSelectionForCountFormat($input)) {
                return self::FAILURE;
            }
            if ($this->getPositiveIntOptionOrDefault($input, 'limit', Constants::DEFAULT_CONTENT_LIMIT) === null) {
                return self::FAILURE;
            }
            return $this->countModels($db, $fromClause, $whereClause, $params);
        }

        $extraSelectSql = $this->buildModelExtraSelectSql($input);
        $modelGroupSelect = $this->isModelFieldRequested($input, 'model_group')
            ? ",
                 mg.title as model_group"
            : '';
        $modelGroupJoin = $this->isModelFieldRequested($input, 'model_group')
            ? "LEFT JOIN {$this->table('models_groups')} mg ON mg.model_group_id = m.model_group_id"
            : '';

        // Match KVS admin model listing counters, which are derived from relation tables.
        $query = "SELECT m.*,
                 (SELECT COUNT(*) FROM {$this->table('models')}_videos WHERE model_id = m.model_id) as video_count,
                 (SELECT COUNT(*) FROM {$this->table('models')}_albums WHERE model_id = m.model_id) as album_count,
                 c.title as country_name$extraSelectSql$modelGroupSelect
                 $fromClause
                 LEFT JOIN {$this->table('list_countries')} c ON m.country = c.country_code AND c.language_code = 'en'
                 $modelGroupJoin
                 $whereClause
                 ORDER BY m.model_id DESC LIMIT :limit";

        try {
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $limit = $this->getPositiveIntOptionOrDefault($input, 'limit', Constants::DEFAULT_CONTENT_LIMIT);
            if ($limit === null) {
                return self::FAILURE;
            }
            $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $knownFields = array_merge(
                $this->getStatementColumnNames($stmt),
                [
                    'id',
                    'thumb',
                    'status',
                    'videos',
                    'videos_amount',
                    'albums',
                    'albums_amount',
                    'posts_amount',
                    'other_amount',
                    'all_amount',
                    'comments_amount',
                    'subscribers_amount',
                    'views',
                    'country',
                    'model_group',
                    'city',
                    'state',
                    'birth_date',
                    'death_date',
                    'age',
                    'measurements',
                    'height',
                    'weight',
                    'rank',
                    'rating',
                    'tags',
                    'categories',
                ]
            );

            /** @var list<array<string, mixed>> $models */
            $models = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Transform models for display (field aliases)
            $transformedModels = array_map(fn (array $model): array => $this->transformModelForList($model), $models);

            // Default fields
            $defaultFields = ['model_id', 'title', 'status', 'video_count'];

            // Format and display using Formatter
            $formatter = new Formatter($input->getOptions(), $defaultFields, $knownFields);
            $formatter->display($transformedModels, $this->io());

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch models: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function hasModelListOptionConflicts(InputInterface $input): bool
    {
        if ($this->getStringOption($input, 'group') !== null && $this->getStringOption($input, 'model-group') !== null) {
            $this->io()->error('Options --group and --model-group cannot be used together');
            return true;
        }

        return false;
    }

    /**
     * @param array<string, int|string> $params
     */
    private function applyModelAdminFilters(
        \PDO $db,
        InputInterface $input,
        string &$whereClause,
        array &$params
    ): bool {
        if (!$this->applyModelAdminRelationFilters($db, $input, $whereClause, $params)) {
            return false;
        }

        $usage = $this->getStringOption($input, 'usage');
        if ($usage !== null) {
            $condition = $this->getModelUsageFilterCondition($usage);
            if ($condition === null) {
                $this->io()->error('Invalid model usage filter. Use: ' . implode(', ', $this->getAdminUsageFilterValues()));
                return false;
            }
            $whereClause .= " AND {$condition}";
        }

        $fieldFilter = $this->getStringOption($input, 'field-filter');
        if ($fieldFilter !== null) {
            $condition = $this->getModelFieldFilterCondition($fieldFilter);
            if ($condition === null) {
                $this->io()->error('Invalid model field filter. Use: ' . implode(', ', $this->getModelFieldFilterValues()));
                return false;
            }
            $whereClause .= " AND {$condition}";
        }

        return true;
    }

    /**
     * @param array<string, int|string> $params
     */
    private function applyModelAdminRelationFilters(
        \PDO $db,
        InputInterface $input,
        string &$whereClause,
        array &$params
    ): bool {
        $group = $this->resolveModelGroupIdOption($db, $input);
        if ($group === false) {
            return false;
        }
        if ($group !== null) {
            $whereClause .= ' AND m.model_group_id = :model_group';
            $params['model_group'] = $group;
        }

        $tag = $this->resolveTagIdOption($db, $input);
        if ($tag === false) {
            return false;
        }
        if ($tag !== null) {
            $whereClause .= " AND EXISTS (SELECT 1 FROM {$this->table('tags_models')} tm_filter "
                . 'WHERE tm_filter.model_id = m.model_id AND tm_filter.tag_id = :tag)';
            $params['tag'] = $tag;
        }

        $category = $this->resolveCategoryIdOption($db, $input);
        if ($category === false) {
            return false;
        }
        if ($category !== null) {
            $whereClause .= " AND EXISTS (SELECT 1 FROM {$this->table('categories_models')} cm_filter "
                . 'WHERE cm_filter.model_id = m.model_id AND cm_filter.category_id = :category)';
            $params['category'] = $category;
        }

        return true;
    }

    private function resolveModelGroupIdOption(\PDO $db, InputInterface $input): int|false|null
    {
        $group = $this->getStringOption($input, 'group') ?? $this->getStringOption($input, 'model-group');
        if ($group === null) {
            return null;
        }

        $group = trim($group);
        if ($group === '') {
            $this->io()->error('Invalid value for --group (use: integer >= 0 or title)');
            return false;
        }

        if (preg_match('/^\d+$/', $group) === 1) {
            return (int) $group;
        }

        if (preg_match('/^-?\d+(?:\.\d+)?$/', $group) === 1) {
            $this->io()->error('Invalid value for --group (use: integer >= 0 or title)');
            return false;
        }

        $stmt = $db->prepare("SELECT model_group_id FROM {$this->table('models_groups')} WHERE title = :title LIMIT 1");
        $stmt->execute(['title' => $group]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return -1;
        }

        return is_numeric($id) ? (int) $id : false;
    }

    private function getModelUsageFilterCondition(string $usage): ?string
    {
        $videosExpression = $this->getModelRelationCountExpression('models_videos', 'mv_usage');
        $albumsExpression = $this->getModelRelationCountExpression('models_albums', 'ma_usage');
        $postsExpression = $this->getModelRelationCountExpression('models_posts', 'mp_usage');
        $otherExpression = '(COALESCE(m.total_dvds, 0) + COALESCE(m.total_dvd_groups, 0))';
        $allExpression = "({$videosExpression} + {$albumsExpression} + {$postsExpression} + {$otherExpression})";

        return $this->getAdminUsageFilterCondition(
            $usage,
            $videosExpression,
            $albumsExpression,
            $postsExpression,
            $otherExpression,
            $allExpression
        );
    }

    private function getModelRelationCountExpression(string $table, string $alias): string
    {
        return "(SELECT COUNT(*) FROM {$this->table($table)} {$alias} WHERE {$alias}.model_id = m.model_id)";
    }

    /** @return list<string> */
    private function getModelFieldFilterValues(): array
    {
        $values = [];
        foreach (['empty', 'filled'] as $prefix) {
            foreach (self::MODEL_STRING_FIELD_FILTER_COLUMNS as $column) {
                $values[] = "{$prefix}/{$column}";
            }
            foreach (self::MODEL_ZERO_FIELD_FILTER_COLUMNS as $column) {
                $values[] = "{$prefix}/{$column}";
            }
            foreach (self::MODEL_SPECIAL_FIELD_FILTERS as $field) {
                $values[] = "{$prefix}/{$field}";
            }
        }

        return $values;
    }

    private function getModelFieldFilterCondition(string $fieldFilter): ?string
    {
        $parts = explode('/', $fieldFilter, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$state, $field] = $parts;
        if (!in_array($state, ['empty', 'filled'], true)) {
            return null;
        }

        return $state === 'empty'
            ? $this->getEmptyModelFieldFilterCondition($field)
            : $this->getFilledModelFieldFilterCondition($field);
    }

    private function getEmptyModelFieldFilterCondition(string $field): ?string
    {
        if (in_array($field, self::MODEL_STRING_FIELD_FILTER_COLUMNS, true)) {
            return "m.{$field} = ''";
        }

        if (in_array($field, self::MODEL_ZERO_FIELD_FILTER_COLUMNS, true)) {
            return "m.{$field} = 0";
        }

        return match ($field) {
            'group' => 'm.model_group_id = 0',
            'rating' => '(m.rating = 0 AND m.rating_amount = 1)',
            'tags' => $this->getModelRelationExistsCondition('tags_models', 'tag_id', false),
            'categories' => $this->getModelRelationExistsCondition('categories_models', 'category_id', false),
            default => null,
        };
    }

    private function getFilledModelFieldFilterCondition(string $field): ?string
    {
        if (in_array($field, self::MODEL_STRING_FIELD_FILTER_COLUMNS, true)) {
            return "m.{$field} != ''";
        }

        if (in_array($field, self::MODEL_ZERO_FIELD_FILTER_COLUMNS, true)) {
            return "m.{$field} != 0";
        }

        return match ($field) {
            'group' => 'm.model_group_id != 0',
            'rating' => '(m.rating > 0 OR m.rating_amount > 1)',
            'tags' => $this->getModelRelationExistsCondition('tags_models', 'tag_id', true),
            'categories' => $this->getModelRelationExistsCondition('categories_models', 'category_id', true),
            default => null,
        };
    }

    private function getModelRelationExistsCondition(string $relationTable, string $idColumn, bool $exists): string
    {
        $table = $this->table($relationTable);
        $operator = $exists ? 'EXISTS' : 'NOT EXISTS';
        return "{$operator} (SELECT {$idColumn} FROM {$table} rel_filter WHERE rel_filter.model_id = m.model_id)";
    }

    /**
     * @param array<string, mixed> $model
     * @return array<string, mixed>
     */
    private function transformModelForList(array $model): array
    {
        $statusIdVal = $model['status_id'] ?? 0;
        $statusId = is_numeric($statusIdVal) ? (int) $statusIdVal : 0;

        return [
            ...$model,
            'model_id' => $model['model_id'] ?? 0,
            'id' => $model['model_id'] ?? 0,
            'title' => $model['title'] ?? '',
            'thumb' => $model['screenshot1'] ?? $model['screenshot2'] ?? '',
            'status_id' => $statusId,
            'status' => StatusFormatter::model($statusId, false),
            'video_count' => $model['video_count'] ?? 0,
            'videos' => $model['video_count'] ?? 0,
            'videos_amount' => $model['video_count'] ?? 0,
            'album_count' => $model['album_count'] ?? 0,
            'albums' => $model['album_count'] ?? 0,
            'albums_amount' => $model['album_count'] ?? 0,
            'posts_amount' => $model['posts_amount'] ?? 0,
            'other_amount' => $model['other_amount'] ?? 0,
            'all_amount' => $model['all_amount'] ?? 0,
            'comments_amount' => $model['comments_amount'] ?? 0,
            'subscribers_amount' => $model['subscribers_count'] ?? 0,
            'model_viewed' => $model['model_viewed'] ?? 0,
            'views' => $model['model_viewed'] ?? 0,
            'country_name' => $model['country_name'] ?? '',
            'country' => $model['country_name'] ?? '',
            'model_group' => $model['model_group'] ?? '',
            'city' => $model['city'] ?? '',
            'state' => $model['state'] ?? '',
            'birth_date' => $model['birth_date'] ?? '',
            'death_date' => $model['death_date'] ?? '',
            'age' => $model['age'] ?? '',
            'measurements' => $model['measurements'] ?? '',
            'height' => $model['height'] ?? '',
            'weight' => $model['weight'] ?? '',
            'rank' => $this->formatModelRank($model['rank'] ?? null),
            'rating' => format_kvs_rating($model['rating'] ?? 0, $model['rating_amount'] ?? 0),
            'tags' => $model['tags'] ?? '',
            'categories' => $model['categories'] ?? '',
        ];
    }

    private function formatModelRank(mixed $rank): string
    {
        if ($rank === null || !is_scalar($rank)) {
            return '#';
        }

        return '#' . (string) $rank;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function countModels(\PDO $db, string $fromClause, string $whereClause, array $params): int
    {
        try {
            $stmt = $db->prepare("SELECT COUNT(*) $fromClause $whereClause");
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();

            $total = $stmt->fetchColumn();
            $this->io()->writeln((string) (is_numeric($total) ? (int) $total : 0));

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to count models: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function showModel(?string $id, InputInterface $input): int
    {
        $modelId = $this->getRequiredPositiveId($id, 'Model');
        if ($modelId === null) {
            return self::FAILURE;
        }

        if ($this->rejectUnsupportedOptionsForAction($input, 'show', self::SHOW_UNSUPPORTED_OPTIONS)) {
            return self::FAILURE;
        }
        if ($this->rejectCountFormatForSingularAction($input, 'show')) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $extraSelectSql = $this->buildModelExtraSelectSql($input);
            $modelGroupSelect = $this->isModelFieldRequested($input, 'model_group')
                ? ",
                       mg.title as model_group"
                : '';
            $modelGroupJoin = $this->isModelFieldRequested($input, 'model_group')
                ? "LEFT JOIN {$this->table('models_groups')} mg ON mg.model_group_id = m.model_group_id"
                : '';

            $stmt = $db->prepare("
                SELECT m.*,
                       (SELECT COUNT(*) FROM {$this->table('models')}_videos WHERE model_id = m.model_id) as video_count,
                       (SELECT COUNT(*) FROM {$this->table('models')}_albums WHERE model_id = m.model_id) as album_count,
                       c.title as country_name$extraSelectSql$modelGroupSelect
                FROM {$this->table('models')} m
                LEFT JOIN {$this->table('list_countries')} c ON m.country = c.country_code AND c.language_code = 'en'
                $modelGroupJoin
                WHERE m.model_id = :id
            ");
            $stmt->execute(['id' => $modelId]);
            $modelRow = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($modelRow)) {
                $this->io()->error("Model not found: $modelId");
                return self::FAILURE;
            }
            $model = $this->normalizeModelRow($modelRow);

            // Display model details
            $titleValue = $model['title'] ?? '';
            $modelTitle = is_scalar($titleValue) ? (string) $titleValue : '';

            $videoCountVal = $model['video_count'] ?? 0;
            $albumCountVal = $model['album_count'] ?? 0;
            $modelViewedVal = $model['model_viewed'] ?? 0;
            $modelIdVal = $model['model_id'] ?? 0;
            $statusIdVal = $model['status_id'] ?? 0;

            $videoCount = is_numeric($videoCountVal) ? (int) $videoCountVal : 0;
            $albumCount = is_numeric($albumCountVal) ? (int) $albumCountVal : 0;
            $modelViewed = is_numeric($modelViewedVal) ? (int) $modelViewedVal : 0;
            $modelIdStr = is_scalar($modelIdVal) ? (string) $modelIdVal : '0';
            $statusId = is_numeric($statusIdVal) ? (int) $statusIdVal : 0;

            $info = [
                ['Model ID', $modelIdStr],
                ['Name', $modelTitle],
                ['Status', StatusFormatter::model($statusId)],
                ['Videos', number_format($videoCount)],
                ['Albums', number_format($albumCount)],
                ['Views', number_format($modelViewed)],
            ];

            // Rating
            $ratingAmountVal = $model['rating_amount'] ?? 0;
            $ratingVal = $model['rating'] ?? 0;
            $ratingAmount = is_numeric($ratingAmountVal) ? (int) $ratingAmountVal : 0;
            if ($ratingAmount > 0) {
                $info[] = ['Rating', format_kvs_rating($ratingVal, $ratingAmount)];
            }

            // Rank
            $rank = $model['rank'] ?? null;
            if ($rank !== null && is_numeric($rank) && (int) $rank !== 0) {
                $info[] = ['Rank', '#' . number_format((int) $rank)];
            }

            // Optional fields
            $this->addOptionalField($info, 'Country', $model['country_name'] ?? null);
            /** @var array<string, mixed> $model */
            $this->addBirthDateField($info, $model);
            $this->addOptionalField($info, 'Measurements', $model['measurements'] ?? null);
            $this->addOptionalField($info, 'Height', $model['height'] ?? null);
            $this->addOptionalField($info, 'Weight', $model['weight'] ?? null);
            $this->addOptionalField($info, 'Description', $model['description'] ?? null);

            if ($this->shouldUseFormattedRows($input)) {
                return $this->displayDetailRows(
                    $input,
                    $info,
                    $this->getRequestedModelDetailFields($input, $model, $statusId)
                );
            }

            $this->io()->title("Model: $modelTitle");
            $this->renderTable(['Field', 'Value'], $info);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch model: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @param array<string, mixed> $model
     * @return array<string, mixed>
     */
    private function getRequestedModelDetailFields(InputInterface $input, array $model, int $statusId): array
    {
        $fields = $this->transformModelForList($model);
        foreach (
            [
                'model_id',
                'id',
                'status',
                'videos',
                'albums',
                'views',
                'rating',
                'rank',
                'birth_date',
                'description',
            ] as $field
        ) {
            unset($fields[$field]);
        }

        $fields['status_id'] = $statusId;

        return $this->getRequestedDetailFields($input, $fields);
    }

    /**
     * @param array<mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeModelRow(array $row): array
    {
        $result = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function buildModelExtraSelectSql(InputInterface $input): string
    {
        $extraSelects = [];
        if ($this->isModelFieldRequested($input, 'posts_amount') || $this->isModelFieldRequested($input, 'all_amount')) {
            $extraSelects[] = "(
                SELECT COUNT(*)
                FROM {$this->table('models_posts')} mp
                WHERE mp.model_id = m.model_id
            ) as posts_amount";
        }
        if ($this->isModelFieldRequested($input, 'other_amount') || $this->isModelFieldRequested($input, 'all_amount')) {
            $extraSelects[] = "(m.total_dvds + m.total_dvd_groups) as other_amount";
        }
        if ($this->isModelFieldRequested($input, 'all_amount')) {
            $extraSelects[] = "(
                (SELECT COUNT(*) FROM {$this->table('models')}_videos WHERE model_id = m.model_id) +
                (SELECT COUNT(*) FROM {$this->table('models')}_albums WHERE model_id = m.model_id) +
                (SELECT COUNT(*) FROM {$this->table('models_posts')} mp_all WHERE mp_all.model_id = m.model_id) +
                m.total_dvds + m.total_dvd_groups
            ) as all_amount";
        }
        if ($this->isModelFieldRequested($input, 'comments_amount')) {
            $extraSelects[] = "(
                SELECT COUNT(*)
                FROM {$this->table('comments')} cm
                WHERE cm.object_type_id = 4 AND cm.object_id = m.model_id
            ) as comments_amount";
        }
        if ($this->isModelFieldRequested($input, 'tags')) {
            $extraSelects[] = "(
                SELECT GROUP_CONCAT(t.tag ORDER BY tm.id ASC)
                FROM {$this->table('tags')} t
                INNER JOIN {$this->table('tags_models')} tm ON tm.tag_id = t.tag_id
                WHERE tm.model_id = m.model_id
            ) as tags";
        }
        if ($this->isModelFieldRequested($input, 'categories')) {
            $extraSelects[] = "(
                SELECT GROUP_CONCAT(c.title ORDER BY cm_rel.id ASC)
                FROM {$this->table('categories')} c
                INNER JOIN {$this->table('categories_models')} cm_rel ON cm_rel.category_id = c.category_id
                WHERE cm_rel.model_id = m.model_id
            ) as categories";
        }

        return $extraSelects === [] ? '' : ",\n                 " . implode(",\n                 ", $extraSelects);
    }

    private function isModelFieldRequested(InputInterface $input, string $field): bool
    {
        $singleField = $this->getStringOption($input, 'field');
        if ($singleField === $field) {
            return true;
        }

        $fields = $this->getStringOption($input, 'fields');
        if ($fields === null) {
            return false;
        }

        foreach (array_map('trim', explode(',', $fields)) as $requestedField) {
            if ($requestedField === $field) {
                return true;
            }
        }

        return false;
    }

    private function showStats(InputInterface $input): int
    {
        if ($this->rejectUnsupportedArgument($input, 'stats', 'id', 'a model ID argument', 'show', 'a specific model')) {
            return self::FAILURE;
        }

        if ($this->rejectUnsupportedOptionsForAction($input, 'stats', self::SHOW_UNSUPPORTED_OPTIONS)) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stats = [];
            /** @var list<array<string, mixed>> $metricRows */
            $metricRows = [];

            // Total models
            $stmt = $db->query("SELECT COUNT(*) FROM {$this->table('models')}");
            if ($stmt !== false) {
                $value = (int) $stmt->fetchColumn();
                $stats[] = ['Total Models', number_format($value)];
                $metricRows[] = $this->metricRow('overall', 'Total Models', $value, number_format($value));
            }

            // Active models
            $stmt = $db->query("SELECT COUNT(*) FROM {$this->table('models')} WHERE status_id = " . StatusFormatter::MODEL_ACTIVE);
            if ($stmt !== false) {
                $value = (int) $stmt->fetchColumn();
                $stats[] = ['Active', number_format($value)];
                $metricRows[] = $this->metricRow('overall', 'Active', $value, number_format($value));
            }

            // Inactive models
            $stmt = $db->query("SELECT COUNT(*) FROM {$this->table('models')} WHERE status_id = " . StatusFormatter::MODEL_DISABLED);
            if ($stmt !== false) {
                $value = (int) $stmt->fetchColumn();
                $stats[] = ['Inactive', number_format($value)];
                $metricRows[] = $this->metricRow('overall', 'Inactive', $value, number_format($value));
            }

            // Models with videos
            $stmt = $db->query("SELECT COUNT(DISTINCT model_id) FROM {$this->table('models')}_videos");
            if ($stmt !== false) {
                $value = (int) $stmt->fetchColumn();
                $stats[] = ['Models with Videos', number_format($value)];
                $metricRows[] = $this->metricRow('overall', 'Models with Videos', $value, number_format($value));
            }

            // Total video-model relations
            $stmt = $db->query("SELECT COUNT(*) FROM {$this->table('models')}_videos");
            if ($stmt !== false) {
                $value = (int) $stmt->fetchColumn();
                $stats[] = ['Total Video Relations', number_format($value)];
                $metricRows[] = $this->metricRow('overall', 'Total Video Relations', $value, number_format($value));
            }

            if ($this->shouldUseFormattedRows($input)) {
                $this->displayMetricRows($input, $metricRows);
                return self::SUCCESS;
            }

            $this->io()->title('Model Statistics');
            $this->renderTable(['Metric', 'Value'], $stats);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch statistics: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @param list<array{0: string, 1: string}> $info
     */
    private function addOptionalField(array &$info, string $label, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $info[] = [$label, is_scalar($value) ? (string) $value : ''];
    }

    /**
     * @param list<array{0: string, 1: string}> $info
     * @param array<string, mixed> $model
     */
    private function addBirthDateField(array &$info, array $model): void
    {
        $birthDate = $model['birth_date'] ?? null;
        if ($birthDate === null || $birthDate === '') {
            return;
        }
        $age = $model['age'] ?? null;
        $ageStr = is_scalar($age) ? (string) $age : '';
        $ageDisplay = $ageStr !== '' ? " (age $ageStr)" : '';
        $info[] = ['Birth Date', (is_scalar($birthDate) ? (string) $birthDate : '') . $ageDisplay];
    }
}
