<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Command\Content\AlbumCommand;
use KVS\CLI\Command\Content\CategoryCommand;
use KVS\CLI\Command\Content\DvdCommand;
use KVS\CLI\Command\Content\ModelCommand;
use KVS\CLI\Command\Content\PlaylistCommand;
use KVS\CLI\Command\Content\TagCommand;
use KVS\CLI\Command\Content\UserCommand;
use KVS\CLI\Command\Content\VideoCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ContentCountLimitValidationTest extends TestCase
{
    private string $kvsPath;
    private Configuration $config;
    private PDO $db;

    protected function setUp(): void
    {
        $this->kvsPath = TestHelper::createTestKvsInstallation();
        $this->config = TestHelper::createTestConfiguration($this->kvsPath);
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->kvsPath);
    }

    public function testCountFormatRejectsNegativeLimitBeforeSql(): void
    {
        foreach ($this->createCommands() as $name => $command) {
            $tester = new CommandTester($command);
            $tester->execute([
                'action' => 'list',
                '--format' => 'count',
                '--limit' => '-1',
            ]);

            $this->assertSame(1, $tester->getStatusCode(), $name . ': ' . $tester->getDisplay());
            $this->assertStringContainsString('Invalid value for --limit', $tester->getDisplay(), $name);
            $this->assertStringNotContainsString('no such table', strtolower($tester->getDisplay()), $name);
        }
    }

    public function testCountFormatRejectsInvalidPositiveIntegerFiltersBeforeSql(): void
    {
        $cases = [
            'content:video --user' => ['content:video', 'user'],
            'content:video --category' => ['content:video', 'category'],
            'content:album --user' => ['content:album', 'user'],
            'content:playlist --user' => ['content:playlist', 'user'],
        ];

        foreach ($cases as $label => [$commandName, $option]) {
            foreach (['abc', '1.5', '-1'] as $value) {
                $commands = $this->createCommands();
                $tester = new CommandTester($commands[$commandName]);
                $tester->execute([
                    'action' => 'list',
                    '--format' => 'count',
                    '--' . $option => $value,
                ]);

                $display = $tester->getDisplay();
                $this->assertSame(1, $tester->getStatusCode(), "$label=$value: $display");
                $this->assertStringContainsString("Invalid value for --$option", $display, "$label=$value");
                $this->assertStringNotContainsString('no such table', strtolower($display), "$label=$value");
            }
        }
    }

    /**
     * @return array<string, BaseCommand>
     */
    private function createCommands(): array
    {
        return [
            'content:video' => new class ($this->config, $this->db) extends VideoCommand {
                public function __construct(Configuration $config, private PDO $testDb)
                {
                    parent::__construct($config);
                    $this->setName('content:video');
                }

                protected function getDatabaseConnection(bool $quiet = false): ?PDO
                {
                    return $this->testDb;
                }
            },
            'content:album' => new class ($this->config, $this->db) extends AlbumCommand {
                public function __construct(Configuration $config, private PDO $testDb)
                {
                    parent::__construct($config);
                    $this->setName('content:album');
                }

                protected function getDatabaseConnection(bool $quiet = false): ?PDO
                {
                    return $this->testDb;
                }
            },
            'content:user' => new class ($this->config, $this->db) extends UserCommand {
                public function __construct(Configuration $config, private PDO $testDb)
                {
                    parent::__construct($config);
                    $this->setName('content:user');
                }

                protected function getDatabaseConnection(bool $quiet = false): ?PDO
                {
                    return $this->testDb;
                }
            },
            'content:category' => new class ($this->config, $this->db) extends CategoryCommand {
                public function __construct(Configuration $config, private PDO $testDb)
                {
                    parent::__construct($config);
                    $this->setName('content:category');
                }

                protected function getDatabaseConnection(bool $quiet = false): ?PDO
                {
                    return $this->testDb;
                }
            },
            'content:tag' => new class ($this->config, $this->db) extends TagCommand {
                public function __construct(Configuration $config, private PDO $testDb)
                {
                    parent::__construct($config);
                    $this->setName('content:tag');
                }

                protected function getDatabaseConnection(bool $quiet = false): ?PDO
                {
                    return $this->testDb;
                }
            },
            'content:model' => new class ($this->config, $this->db) extends ModelCommand {
                public function __construct(Configuration $config, private PDO $testDb)
                {
                    parent::__construct($config);
                    $this->setName('content:model');
                }

                protected function getDatabaseConnection(bool $quiet = false): ?PDO
                {
                    return $this->testDb;
                }
            },
            'content:dvd' => new class ($this->config, $this->db) extends DvdCommand {
                public function __construct(Configuration $config, private PDO $testDb)
                {
                    parent::__construct($config);
                    $this->setName('content:dvd');
                }

                protected function getDatabaseConnection(bool $quiet = false): ?PDO
                {
                    return $this->testDb;
                }
            },
            'content:playlist' => new class ($this->config, $this->db) extends PlaylistCommand {
                public function __construct(Configuration $config, private PDO $testDb)
                {
                    parent::__construct($config);
                    $this->setName('content:playlist');
                }

                protected function getDatabaseConnection(bool $quiet = false): ?PDO
                {
                    return $this->testDb;
                }
            },
        ];
    }
}
