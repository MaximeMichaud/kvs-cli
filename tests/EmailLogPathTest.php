<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\System\EmailCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class EmailLogPathTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = TestHelper::createTempDir('kvs-email-log-');
        mkdir($this->tempDir . '/admin/include', 0755, true);
        mkdir($this->tempDir . '/admin/data/logs', 0755, true);
        file_put_contents($this->tempDir . '/admin/include/setup_db.php', '<?php');
        file_put_contents($this->tempDir . '/admin/include/setup.php', '<?php $config = [];');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    public function testEmailLogReadsAdminDataLogs(): void
    {
        file_put_contents($this->tempDir . '/admin/data/logs/email_test.txt', "sent from data logs\n");

        $tester = new CommandTester(new EmailCommand(new Configuration(['path' => $this->tempDir])));
        $tester->execute(['--force' => true, 'action' => 'log']);

        $output = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('sent from data logs', $output);
    }
}
