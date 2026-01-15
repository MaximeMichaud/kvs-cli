<?php

namespace KVS\CLI\Output;

use KVS\CLI\Constants;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Formatter - Centralized output formatting for list commands
 *
 * Eliminates code duplication across all list commands
 * (video, user, album, category, tag, etc.)
 *
 * Usage:
 *   $formatter = new Formatter($input->getOptions(), ['id', 'title', 'status']);
 *   $formatter->display($items, $output);
 */
class Formatter
{
    /**
     * Field alias mapping: user-friendly names → actual database column names
     * When user requests 'id', we also check 'video_id', 'album_id', etc.
     */
    private const FIELD_ALIASES = [
        'id' => ['video_id', 'album_id', 'user_id', 'category_id', 'tag_id', 'model_id', 'dvd_id', 'comment_id'],
        'status' => ['status_id'],
        'views' => ['video_viewed', 'album_viewed', 'profile_viewed', 'model_viewed', 'dvd_viewed'],
        'images' => ['image_count', 'photos_amount'],
        'videos' => ['total_videos', 'video_count'],
        'albums' => ['total_albums', 'album_count'],
        'user' => ['username'],
        'date' => ['post_date', 'added_date'],
    ];

    /** @var array<string, mixed> */
    private array $args;

    /**
     * Constructor
     *
     * @param array<string, mixed> $options Input options from command (typically $input->getOptions())
     * @param list<string> $defaultFields Default fields to display if --fields not specified
     */
    public function __construct(array $options, array $defaultFields)
    {
        $this->args = [
            'format' => $options['format'] ?? 'table',
            'fields' => $options['fields'] ?? null,
            'field'  => $options['field'] ?? null,
            'no-truncate' => $options['no-truncate'] ?? false,
        ];

        // Parse fields
        if ($this->args['fields'] !== null && $this->args['fields'] !== '') {
            if (is_string($this->args['fields'])) {
                $this->args['fields'] = array_map('trim', explode(',', $this->args['fields']));
            }
        } else {
            $this->args['fields'] = $defaultFields;
        }
    }

    /**
     * Display items in the requested format
     *
     * @param list<array<string, mixed>> $items Array of items to display
     * @param OutputInterface $output Symfony Console output interface
     * @return void
     */
    public function display(array $items, OutputInterface $output): void
    {
        // Empty check
        if ($items === []) {
            $output->writeln('<comment>No results found.</comment>');
            return;
        }

        // Single field mode (--field=email)
        if ($this->args['field'] !== null && $this->args['field'] !== '' && $this->args['field'] !== false) {
            $this->displaySingleField($items, $output);
            return;
        }

        // Multi-field mode with different formats
        $this->displayMultiple($items, $output);
    }

    /**
     * Display single field from each item (one value per line)
     *
     * @param list<array<string, mixed>> $items
     * @param OutputInterface $output
     * @return void
     */
    private function displaySingleField(array $items, OutputInterface $output): void
    {
        $field = $this->args['field'];

        // Type check: field must be a string
        if (!is_string($field)) {
            return;
        }

        foreach ($items as $item) {
            $value = $this->getFieldValue($item, $field);
            if ($value !== '') {
                // Cast to string - database values are typically strings/ints/null
                $stringValue = is_scalar($value) || $value === null ? (string)$value : '';
                if ($stringValue !== '') {
                    $output->writeln($stringValue);
                }
            }
        }
    }

    /**
     * Display multiple fields in requested format
     *
     * @param list<array<string, mixed>> $items
     * @param OutputInterface $output
     * @return void
     */
    private function displayMultiple(array $items, OutputInterface $output): void
    {
        $fields = $this->args['fields'];
        $format = $this->args['format'];

        // Type check: fields must be a list of strings
        if (!is_array($fields)) {
            throw new \InvalidArgumentException('Fields must be an array');
        }

        // Ensure all fields are strings
        /** @var list<string> $validFields */
        $validFields = [];
        foreach ($fields as $field) {
            if (is_string($field)) {
                $validFields[] = $field;
            }
        }

        // Type check: format must be a string
        if (!is_string($format)) {
            throw new \InvalidArgumentException('Format must be a string');
        }

        switch ($format) {
            case 'count':
                $output->writeln((string)count($items));
                break;

            case 'ids':
                $this->displayIds($items, $output);
                break;

            case 'table':
                $this->displayTable($items, $validFields, $output);
                break;

            case 'json':
                $this->displayJson($items, $validFields, $output);
                break;

            case 'csv':
                $this->displayCsv($items, $validFields, $output);
                break;

            case 'yaml':
                $this->displayYaml($items, $validFields, $output);
                break;

            default:
                throw new \InvalidArgumentException("Invalid format: {$format}");
        }
    }

    /**
     * Display space-separated IDs
     *
     * @param list<array<string, mixed>> $items
     */
    private function displayIds(array $items, OutputInterface $output): void
    {
        // Try common ID field names
        $idFields = Constants::ID_FIELD_NAMES;

        $ids = [];
        foreach ($items as $item) {
            foreach ($idFields as $idField) {
                if (isset($item[$idField])) {
                    $ids[] = $item[$idField];
                    break;
                }
            }
        }

        $output->writeln(implode(' ', $ids));
    }

    /**
     * Display as formatted table with borders
     *
     * @param list<array<string, mixed>> $items
     * @param list<string> $fields
     */
    private function displayTable(array $items, array $fields, OutputInterface $output): void
    {
        $table = new Table($output);
        $table->setStyle(Constants::TABLE_STYLE);

        // Headers (capitalize and replace underscores)
        $headers = array_map(function ($field) {
            return '<options=bold>' . ucfirst(str_replace('_', ' ', $field)) . '</>';
        }, $fields);
        $table->setHeaders($headers);

        // Rows
        foreach ($items as $item) {
            $row = [];
            foreach ($fields as $field) {
                $value = $this->getFieldValue($item, $field);

                // Truncate long text unless --no-truncate
                if (
                    $this->args['no-truncate'] !== true &&
                    $this->args['no-truncate'] !== 'true' &&
                    $this->args['no-truncate'] !== 1 &&
                    is_string($value) &&
                    strlen($value) > Constants::DEFAULT_TRUNCATE_LENGTH
                ) {
                    $value = substr($value, 0, Constants::DEFAULT_TRUNCATE_LENGTH - 3) . '...';
                }

                $row[] = $value;
            }
            $table->addRow($row);
        }

        $table->render();

        // Footer with total count
        $output->writeln('');
        $output->writeln(sprintf('<info>Total: %d results</info>', count($items)));

        // Tip about --no-truncate if text was truncated
        if (
            $this->args['no-truncate'] !== true &&
            $this->args['no-truncate'] !== 'true' &&
            $this->args['no-truncate'] !== 1 &&
            $this->hasLongFields($items, $fields)
        ) {
            $output->writeln('<comment>💡 Tip: Use --no-truncate to see full text</comment>');
        }
    }

    /**
     * Display as JSON
     *
     * @param list<array<string, mixed>> $items
     * @param list<string> $fields
     */
    private function displayJson(array $items, array $fields, OutputInterface $output): void
    {
        // Filter to only requested fields, using alias resolution
        $filtered = array_map(function ($item) use ($fields) {
            $result = [];
            foreach ($fields as $field) {
                $value = $this->getFieldValue($item, $field);
                if ($value !== '') {
                    $result[$field] = $value;
                }
            }
            return $result;
        }, $items);

        $json = json_encode($filtered, Constants::JSON_FLAGS);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode JSON: ' . json_last_error_msg());
        }
        $output->writeln($json);
    }

    /**
     * Display as CSV
     *
     * @param list<array<string, mixed>> $items
     * @param list<string> $fields
     */
    private function displayCsv(array $items, array $fields, OutputInterface $output): void
    {
        $handle = fopen('php://output', 'w');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open output stream for CSV');
        }

        // Header row
        fputcsv($handle, $fields, ',', '"', '\\');

        // Data rows
        foreach ($items as $item) {
            /** @var array<int, string|int|float|bool|null> $row */
            $row = [];
            foreach ($fields as $field) {
                $value = $this->getFieldValue($item, $field);
                // Cast to scalar types that fputcsv accepts (database values are typically strings/ints/null)
                if (is_scalar($value) || $value === null) {
                    $row[] = $value;
                } else {
                    $row[] = ''; // fallback for non-scalar values
                }
            }
            fputcsv($handle, $row, ',', '"', '\\');
        }

        fclose($handle);
    }

    /**
     * Display as YAML
     *
     * @param list<array<string, mixed>> $items
     * @param list<string> $fields
     */
    private function displayYaml(array $items, array $fields, OutputInterface $output): void
    {
        foreach ($items as $item) {
            $output->writeln('-');
            foreach ($fields as $field) {
                $value = $this->getFieldValue($item, $field);
                if ($value !== '') {
                    // Convert to string for YAML output (database values are typically strings/ints/null)
                    $stringValue = is_scalar($value) || $value === null ? (string)$value : '';
                    if ($stringValue !== '') {
                        // Escape special YAML characters
                        if (str_contains($stringValue, ':') || str_contains($stringValue, '#')) {
                            $stringValue = '"' . str_replace('"', '\\"', $stringValue) . '"';
                        }
                        $output->writeln("  $field: $stringValue");
                    }
                }
            }
        }
    }

    /**
     * Get field value from item, resolving aliases if needed
     *
     * @param array<string, mixed> $item Data item
     * @param string $field Requested field name
     * @return mixed Field value or empty string if not found
     */
    private function getFieldValue(array $item, string $field): mixed
    {
        // Direct match first
        if (isset($item[$field])) {
            return $item[$field];
        }

        // Check aliases
        if (isset(self::FIELD_ALIASES[$field])) {
            foreach (self::FIELD_ALIASES[$field] as $aliasField) {
                if (isset($item[$aliasField])) {
                    return $item[$aliasField];
                }
            }
        }

        return '';
    }

    /**
     * Check if any fields have text longer than 50 chars
     *
     * @param list<array<string, mixed>> $items
     * @param list<string> $fields
     */
    private function hasLongFields(array $items, array $fields): bool
    {
        foreach ($items as $item) {
            foreach ($fields as $field) {
                $value = $this->getFieldValue($item, $field);
                if (is_string($value) && strlen($value) > Constants::DEFAULT_TRUNCATE_LENGTH) {
                    return true;
                }
            }
        }
        return false;
    }
}
