<?php

namespace KVS\CLI\Service;

/**
 * Retains replaced uploads on disk until their database mutation commits.
 */
final class UploadedFileBatch
{
    /** @var array<string, array{backup: string|null, hash: string|null}> */
    private array $files = [];

    public function copy(string $source, string $target): void
    {
        if (array_key_exists($target, $this->files)) {
            throw new \RuntimeException("Upload destination was already written: $target");
        }
        if (is_link($target) || (file_exists($target) && !is_file($target))) {
            throw new \RuntimeException("Upload destination is not a regular file: $target");
        }

        $backup = null;
        $hash = null;
        if (is_file($target)) {
            $hash = @hash_file('sha256', $target);
            if ($hash === false) {
                throw new \RuntimeException("Could not read existing upload: $target");
            }
            $backup = @tempnam(dirname($target), '.kvs-cli-upload-');
            if ($backup === false) {
                throw new \RuntimeException("Could not reserve upload backup for $target");
            }
            if (realpath(dirname($backup)) !== realpath(dirname($target)) || !@rename($target, $backup)) {
                $cleanupFailed = !@unlink($backup);
                throw new \RuntimeException(
                    "Could not preserve existing upload: $target"
                    . ($cleanupFailed ? "; could not remove temporary backup: $backup" : '')
                );
            }
        }

        // Register before copying: copy() can fail after creating a partial file.
        $this->files[$target] = ['backup' => $backup, 'hash' => $hash];
        if (!@copy($source, $target)) {
            throw new \RuntimeException("Could not copy file to $target");
        }
    }

    /**
     * @return list<string> Restoration failures, with retained backup locations.
     */
    public function rollback(): array
    {
        $failures = [];
        foreach (array_reverse($this->files, true) as $target => $file) {
            $backup = $file['backup'];
            if ($backup !== null) {
                if (!@rename($backup, $target)) {
                    $failures[] = "Could not restore $target; original upload retained at $backup";
                    continue;
                }
                if (@hash_file('sha256', $target) !== $file['hash']) {
                    $failures[] = "Restored upload could not be verified: $target";
                    continue;
                }
            } elseif ((file_exists($target) || is_link($target)) && !@unlink($target)) {
                $failures[] = "Could not remove uncommitted upload: $target";
                continue;
            }
            unset($this->files[$target]);
        }

        return $failures;
    }

    public function commit(): void
    {
        $failures = [];
        foreach ($this->files as $target => $file) {
            $backup = $file['backup'];
            if ($backup !== null && !@unlink($backup)) {
                $failures[] = "Could not remove upload backup: $backup";
                continue;
            }
            unset($this->files[$target]);
        }
        if ($failures !== []) {
            throw new \RuntimeException(implode('; ', $failures));
        }
    }
}
