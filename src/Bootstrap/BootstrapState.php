<?php

namespace KVS\CLI\Bootstrap;

/**
 * Bootstrap state container
 *
 * Holds values and errors that are passed between bootstrap steps.
 * This allows each step to be isolated and testable.
 */
class BootstrapState
{
    /** @var array<string, mixed> */
    private array $values = [];

    /** @var list<string> */
    private array $errors = [];

    /**
     * Set a value in the state
     */
    public function setValue(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    /**
     * Get a value from the state
     */
    public function getValue(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    /**
     * Check if a value exists
     */
    public function hasValue(string $key): bool
    {
        return isset($this->values[$key]);
    }

    /**
     * Add an error to the state
     */
    public function addError(string $error): void
    {
        $this->errors[] = $error;
    }

    /**
     * Check if there are any errors
     */
    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Get all errors
     *
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
