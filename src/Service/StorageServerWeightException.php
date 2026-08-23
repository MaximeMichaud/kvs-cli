<?php

namespace KVS\CLI\Service;

class StorageServerWeightException extends \RuntimeException
{
    public function __construct(
        private string $errorCode,
        string $message,
        private bool $recoveryRequired = false,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function isRecoveryRequired(): bool
    {
        return $this->recoveryRequired;
    }
}
