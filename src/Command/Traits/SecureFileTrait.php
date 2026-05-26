<?php

namespace KVS\CLI\Command\Traits;

trait SecureFileTrait
{
    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withSecureFileUmask(callable $callback): mixed
    {
        $previousUmask = umask(0137);

        try {
            return $callback();
        } finally {
            umask($previousUmask);
        }
    }

    private function writeSecureFile(string $path, string $contents): bool
    {
        $result = $this->withSecureFileUmask(
            static fn (): int|false => file_put_contents($path, $contents)
        );

        if ($result === false) {
            return false;
        }

        return $this->restrictFilePermissions($path);
    }

    private function restrictFilePermissions(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        return chmod($path, 0640);
    }
}
