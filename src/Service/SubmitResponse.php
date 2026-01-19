<?php

declare(strict_types=1);

namespace KVS\CLI\Service;

/**
 * Response from benchmark API submission.
 */
final class SubmitResponse
{
    private function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ?string $url = null
    ) {
    }

    /**
     * Create a successful response.
     */
    public static function success(string $message, ?string $url = null): self
    {
        return new self(true, $message, $url);
    }

    /**
     * Create an error response.
     */
    public static function error(string $message): self
    {
        return new self(false, $message, null);
    }
}
