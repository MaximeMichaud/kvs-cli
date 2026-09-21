<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\System\QueueCommand;
use KVS\CLI\Config\Configuration;
use PDO;

class QueueActionTestCommand extends QueueCommand
{
    public float $clock = 0.0;
    public ?\Closure $onSleep = null;

    public function __construct(Configuration $config, private PDO $db)
    {
        parent::__construct($config);
        $this->setName('system:queue');
    }

    protected function getDatabaseConnection(bool $quiet = false): ?PDO
    {
        return $this->db;
    }

    protected function waitClock(): float
    {
        return $this->clock;
    }

    protected function waitSleep(float $seconds): void
    {
        $this->clock += $seconds;
        if ($this->onSleep !== null) {
            ($this->onSleep)();
        }
    }
}
