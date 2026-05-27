<?php

namespace KVS\CLI\Command\Traits;

use Symfony\Component\Process\Process;

trait RsyncProgressTrait
{
    protected function writeRsyncProgress(string $type, string $buffer): void
    {
        if ($type !== Process::OUT || !str_contains($buffer, '%')) {
            return;
        }

        if (!$this->io()->isDecorated()) {
            return;
        }

        $line = trim(str_replace("\r", '', $buffer));
        if ($line === '') {
            return;
        }

        $this->io()->write("\r" . $line);
    }
}
