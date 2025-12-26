<?php

namespace KVS\CLI\Command\Traits;

use Symfony\Component\Console\Input\InputInterface;

/**
 * Type-safe helpers for Symfony Console Input
 *
 * Provides typed wrappers for getArgument() and getOption() methods
 * to satisfy PHPStan level 9 strict type requirements.
 */
trait InputHelperTrait
{
    /**
     * Get argument as string (null if not provided)
     */
    protected function getStringArgument(InputInterface $input, string $name): ?string
    {
        $value = $input->getArgument($name);
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        if (is_array($value)) {
            $first = $value[0] ?? null;
            return is_string($first) ? $first : null;
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        return null;
    }

    /**
     * Get argument as string with default
     */
    protected function getStringArgumentOrDefault(InputInterface $input, string $name, string $default): string
    {
        return $this->getStringArgument($input, $name) ?? $default;
    }

    /**
     * Get argument as integer (null if not provided or not numeric)
     */
    protected function getIntArgument(InputInterface $input, string $name): ?int
    {
        $value = $this->getStringArgument($input, $name);
        if ($value === null || !is_numeric($value)) {
            return null;
        }
        return (int) $value;
    }

    /**
     * Get argument as array of strings
     *
     * @return list<string>
     */
    protected function getArrayArgument(InputInterface $input, string $name): array
    {
        $value = $input->getArgument($name);
        if ($value === null) {
            return [];
        }
        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }
        if (is_string($value)) {
            return [$value];
        }
        if (is_scalar($value)) {
            return [(string) $value];
        }
        return [];
    }

    /**
     * Get option as string (null if not provided)
     */
    protected function getStringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        if (is_array($value)) {
            $first = $value[0] ?? null;
            return is_string($first) ? $first : null;
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        return null;
    }

    /**
     * Get option as string with default
     */
    protected function getStringOptionOrDefault(InputInterface $input, string $name, string $default): string
    {
        return $this->getStringOption($input, $name) ?? $default;
    }

    /**
     * Get option as integer (null if not provided or not numeric)
     */
    protected function getIntOption(InputInterface $input, string $name): ?int
    {
        $value = $this->getStringOption($input, $name);
        if ($value === null || !is_numeric($value)) {
            return null;
        }
        return (int) $value;
    }

    /**
     * Get option as integer with default
     */
    protected function getIntOptionOrDefault(InputInterface $input, string $name, int $default): int
    {
        return $this->getIntOption($input, $name) ?? $default;
    }

    /**
     * Get option as boolean (for VALUE_NONE flags)
     */
    protected function getBoolOption(InputInterface $input, string $name): bool
    {
        return $input->getOption($name) === true;
    }

    /**
     * Get option as array of strings
     *
     * @return list<string>
     */
    protected function getArrayOption(InputInterface $input, string $name): array
    {
        $value = $input->getOption($name);
        if ($value === null || $value === false) {
            return [];
        }
        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }
        if (is_string($value)) {
            return [$value];
        }
        if (is_scalar($value)) {
            return [(string) $value];
        }
        return [];
    }
}
