<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\AlbumCommand;
use KVS\CLI\Command\Content\CategoryCommand;
use KVS\CLI\Command\Content\CommentCommand;
use KVS\CLI\Command\Content\DvdCommand;
use KVS\CLI\Command\Content\ModelCommand;
use KVS\CLI\Command\Content\PlaylistCommand;
use KVS\CLI\Command\Content\TagCommand;
use KVS\CLI\Command\Content\UserCommand;
use KVS\CLI\Command\Content\VideoCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ContentShowActionValidationRegressionTest extends TestCase
{
    private string $tempDir = '';

    private ?Configuration $config = null;

    protected function setUp(): void
    {
        $this->tempDir = TestHelper::createTempDir('kvs-content-show-validation-');
        TestHelper::createMockKvsInstallation($this->tempDir);
        $this->config = TestHelper::createTestConfiguration($this->tempDir);
    }

    protected function tearDown(): void
    {
        if ($this->tempDir !== '' && is_dir($this->tempDir)) {
            TestHelper::removeDir($this->tempDir);
        }
    }

    public function testShowActionsRejectUnsupportedOptionsBeforeDatabaseAccess(): void
    {
        /** @var list<array{string, \Closure(): Command, array<string, mixed>, string}> $cases */
        $cases = [
            ['video search', fn (): Command => $this->createVideoCommand(), $this->showInput('id', '73', 'search'), '--search'],
            ['album search', fn (): Command => $this->createAlbumCommand(), $this->showInput('id', '6', 'search'), '--search'],
            ['dvd search', fn (): Command => $this->createDvdCommand(), $this->showInput('id', '4', 'search'), '--search'],
            ['model search', fn (): Command => $this->createModelCommand(), $this->showInput('id', '7', 'search'), '--search'],
            ['category search', fn (): Command => $this->createCategoryCommand(), $this->showInput('id', '424', 'search'), '--search'],
            ['tag search', fn (): Command => $this->createTagCommand(), $this->showInput('identifier', '72', 'search'), '--search'],
            ['playlist search', fn (): Command => $this->createPlaylistCommand(), $this->showInput('id', '6', 'search'), '--search'],
            ['user search', fn (): Command => $this->createUserCommand(), $this->showInput('id', '320', 'search'), '--search'],
            ['comment search', fn (): Command => $this->createCommentCommand(), $this->showInput('id', '300', 'search'), '--search'],
            ['video status', fn (): Command => $this->createVideoCommand(), $this->showInput('id', '73', 'status', 'disabled'), '--status'],
            ['album status', fn (): Command => $this->createAlbumCommand(), $this->showInput('id', '6', 'status', 'disabled'), '--status'],
            ['dvd status', fn (): Command => $this->createDvdCommand(), $this->showInput('id', '4', 'status', 'disabled'), '--status'],
            ['model status', fn (): Command => $this->createModelCommand(), $this->showInput('id', '7', 'status', 'disabled'), '--status'],
            ['category status', fn (): Command => $this->createCategoryCommand(), $this->showInput('id', '424', 'status', 'inactive'), '--status'],
            ['tag status', fn (): Command => $this->createTagCommand(), $this->showInput('identifier', '72', 'status', 'inactive'), '--status'],
            ['playlist status', fn (): Command => $this->createPlaylistCommand(), $this->showInput('id', '6', 'status', 'disabled'), '--status'],
            ['user status', fn (): Command => $this->createUserCommand(), $this->showInput('id', '320', 'status', 'disabled'), '--status'],
            ['comment approved', fn (): Command => $this->createCommentCommand(), $this->showInput('id', '300', 'approved', true), '--approved'],
            ['playlist title', fn (): Command => $this->createPlaylistCommand(), $this->showInput('id', '6', 'title', '__ignored__'), '--title'],
            [
                'playlist description',
                fn (): Command => $this->createPlaylistCommand(),
                $this->showInput('id', '6', 'description', '__ignored__'),
                '--description',
            ],
            ['playlist dir', fn (): Command => $this->createPlaylistCommand(), $this->showInput('id', '6', 'dir', '__ignored__'), '--dir'],
            ['playlist video', fn (): Command => $this->createPlaylistCommand(), $this->showInput('id', '6', 'video', '999999'), '--video'],
            ['category unused', fn (): Command => $this->createCategoryCommand(), $this->showInput('id', '424', 'unused', true), '--unused'],
            ['tag unused', fn (): Command => $this->createTagCommand(), $this->showInput('identifier', '72', 'unused', true), '--unused'],
            [
                'user removal requested',
                fn (): Command => $this->createUserCommand(),
                $this->showInput('id', '320', 'removal-requested', true),
                '--removal-requested',
            ],
            ['user trusted', fn (): Command => $this->createUserCommand(), $this->showInput('id', '320', 'trusted', true), '--trusted'],
            ['comment video', fn (): Command => $this->createCommentCommand(), $this->showInput('id', '300', 'video', '999999'), '--video'],
            ['comment pending', fn (): Command => $this->createCommentCommand(), $this->showInput('id', '300', 'pending', true), '--pending'],
            ['comment oldest', fn (): Command => $this->createCommentCommand(), $this->showInput('id', '300', 'oldest', true), '--oldest'],
        ];

        foreach ($cases as [$label, $createCommand, $input, $option]) {
            $tester = new CommandTester($createCommand());
            $tester->execute($input);

            $display = $tester->getDisplay();
            self::assertSame(1, $tester->getStatusCode(), "$label: $display");
            self::assertStringContainsString("The show action does not support $option", $display, $label);
            self::assertStringNotContainsString('Database access should not be reached', $display, $label);
        }
    }

    private function createVideoCommand(): VideoCommand
    {
        return new class ($this->getConfig()) extends VideoCommand {
            public function __construct(Configuration $config)
            {
                parent::__construct($config);
                $this->setName('content:video');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                throw new \LogicException('Database access should not be reached');
            }
        };
    }

    private function createAlbumCommand(): AlbumCommand
    {
        return new class ($this->getConfig()) extends AlbumCommand {
            public function __construct(Configuration $config)
            {
                parent::__construct($config);
                $this->setName('content:album');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                throw new \LogicException('Database access should not be reached');
            }
        };
    }

    private function createDvdCommand(): DvdCommand
    {
        return new class ($this->getConfig()) extends DvdCommand {
            public function __construct(Configuration $config)
            {
                parent::__construct($config);
                $this->setName('content:dvd');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                throw new \LogicException('Database access should not be reached');
            }
        };
    }

    private function createModelCommand(): ModelCommand
    {
        return new class ($this->getConfig()) extends ModelCommand {
            public function __construct(Configuration $config)
            {
                parent::__construct($config);
                $this->setName('content:model');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                throw new \LogicException('Database access should not be reached');
            }
        };
    }

    private function createCategoryCommand(): CategoryCommand
    {
        return new class ($this->getConfig()) extends CategoryCommand {
            public function __construct(Configuration $config)
            {
                parent::__construct($config);
                $this->setName('content:category');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                throw new \LogicException('Database access should not be reached');
            }
        };
    }

    private function createTagCommand(): TagCommand
    {
        return new class ($this->getConfig()) extends TagCommand {
            public function __construct(Configuration $config)
            {
                parent::__construct($config);
                $this->setName('content:tag');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                throw new \LogicException('Database access should not be reached');
            }
        };
    }

    private function createPlaylistCommand(): PlaylistCommand
    {
        return new class ($this->getConfig()) extends PlaylistCommand {
            public function __construct(Configuration $config)
            {
                parent::__construct($config);
                $this->setName('content:playlist');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                throw new \LogicException('Database access should not be reached');
            }
        };
    }

    private function createUserCommand(): UserCommand
    {
        return new class ($this->getConfig()) extends UserCommand {
            public function __construct(Configuration $config)
            {
                parent::__construct($config);
                $this->setName('content:user');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                throw new \LogicException('Database access should not be reached');
            }
        };
    }

    private function createCommentCommand(): CommentCommand
    {
        return new class ($this->getConfig()) extends CommentCommand {
            public function __construct(Configuration $config)
            {
                parent::__construct($config);
                $this->setName('content:comment');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                throw new \LogicException('Database access should not be reached');
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function showInput(string $idArgument, string $id, string $option, mixed $value = '__missing__'): array
    {
        return [
            'action' => 'show',
            $idArgument => $id,
            '--' . $option => $value,
        ];
    }

    private function getConfig(): Configuration
    {
        if ($this->config === null) {
            throw new \LogicException('Test configuration is not initialized');
        }

        return $this->config;
    }
}
