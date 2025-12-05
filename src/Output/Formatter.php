<?php

namespace KVS\CLI\Output;

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
    private array $args;

    /**
     * Constructor
     *
     * @param array $options Input options from command (typically $input->getOptions())
     * @param array $defaultFields Default fields to display if --fields not specified
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
        if ($this->args['fields']) {
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
     * @param array $items Array of items to display
     * @param OutputInterface $output Symfony Console output interface
     * @return void
     */
    public function display(array $items, OutputInterface $output): void
    {
        // Empty check
        if (empty($items)) {
            $output->writeln('<comment>No results found.</comment>');
            return;
        }

        // Single field mode (--field=email)
        if ($this->args['field']) {
            $this->displaySingleField($items, $output);
            return;
        }

        // Multi-field mode with different formats
        $this->displayMultiple($items, $output);
    }

    /**
     * Display single field from each item (one value per line)
     *
     * @param array $items
     * @param OutputInterface $output
     * @return void
     */
    private function displaySingleField(array $items, OutputInterface $output): void
    {
        $field = $this->args['field'];

        foreach ($items as $item) {
            if (isset($item[$field])) {
                $output->writeln($item[$field]);
            }
        }
    }

    /**
     * Display multiple fields in requested format
     *
     * @param array $items
     * @param OutputInterface $output
     * @return void
     */
    private function displayMultiple(array $items, OutputInterface $output): void
    {
        $fields = $this->args['fields'];
        $format = $this->args['format'];

        switch ($format) {
            case 'count':
                $output->writeln((string)count($items));
                break;

            case 'ids':
                $this->displayIds($items, $output);
                break;

            case 'table':
                $this->displayTable($items, $fields, $output);
                break;

            case 'json':
                $this->displayJson($items, $fields, $output);
                break;

            case 'csv':
                $this->displayCsv($items, $fields, $output);
                break;

            case 'yaml':
                $this->displayYaml($items, $fields, $output);
                break;

            default:
                throw new \InvalidArgumentException("Invalid format: {$format}");
        }
    }

    /**
     * Display space-separated IDs
     */
    private function displayIds(array $items, OutputInterface $output): void
    {
        // Try common ID field names
        $idFields = ['id', 'user_id', 'video_id', 'album_id', 'category_id', 'tag_id', 'comment_id'];

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
     */
    private function displayTable(array $items, array $fields, OutputInterface $output): void
    {
        $table = new Table($output);
        $table->setStyle('box');

        // Headers (capitalize and replace underscores)
        $headers = array_map(function ($field) {
            return '<options=bold>' . ucfirst(str_replace('_', ' ', $field)) . '</>';
        }, $fields);
        $table->setHeaders($headers);

        // Rows
        foreach ($items as $item) {
            $row = [];
            foreach ($fields as $field) {
                $value = $item[$field] ?? '';

                // Truncate long text unless --no-truncate
                if (!$this->args['no-truncate'] && is_string($value) && strlen($value) > 50) {
                    $value = substr($value, 0, 47) . '...';
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
        if (!$this->args['no-truncate'] && $this->hasLongFields($items, $fields)) {
            $output->writeln('<comment>💡 Tip: Use --no-truncate to see full text</comment>');
        }
    }

    /**
     * Display as JSON
     */
    private function displayJson(array $items, array $fields, OutputInterface $output): void
    {
        // Filter to only requested fields
        $filtered = array_map(function ($item) use ($fields) {
            $result = [];
            foreach ($fields as $field) {
                if (isset($item[$field])) {
                    $result[$field] = $item[$field];
                }
            }
            return $result;
        }, $items);

        $output->writeln(json_encode($filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Display as CSV
     */
    private function displayCsv(array $items, array $fields, OutputInterface $output): void
    {
        $handle = fopen('php://output', 'w');

        // Header row
        fputcsv($handle, $fields);

        // Data rows
        foreach ($items as $item) {
            $row = [];
            foreach ($fields as $field) {
                $row[] = $item[$field] ?? '';
            }
            fputcsv($handle, $row);
        }

        fclose($handle);
    }

    /**
     * Display as YAML
     */
    private function displayYaml(array $items, array $fields, OutputInterface $output): void
    {
        foreach ($items as $item) {
            $output->writeln('-');
            foreach ($fields as $field) {
                if (isset($item[$field])) {
                    $value = $item[$field];
                    // Escape special YAML characters
                    if (is_string($value) && (strpos($value, ':') !== false || strpos($value, '#') !== false)) {
                        $value = '"' . str_replace('"', '\\"', $value) . '"';
                    }
                    $output->writeln("  $field: $value");
                }
            }
        }
    }

    /**
     * Check if any fields have text longer than 50 chars
     */
    private function hasLongFields(array $items, array $fields): bool
    {
        foreach ($items as $item) {
            foreach ($fields as $field) {
                if (isset($item[$field]) && is_string($item[$field]) && strlen($item[$field]) > 50) {
                    return true;
                }
            }
        }
        return false;
    }
}
