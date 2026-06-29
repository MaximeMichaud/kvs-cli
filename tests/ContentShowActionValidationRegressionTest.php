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
            ['video limit', fn (): Command => $this->createVideoCommand(), $this->showInput('id', '73', 'limit', '1'), '--limit'],
            ['album limit', fn (): Command => $this->createAlbumCommand(), $this->showInput('id', '6', 'limit', '1'), '--limit'],
            ['dvd limit', fn (): Command => $this->createDvdCommand(), $this->showInput('id', '4', 'limit', '1'), '--limit'],
            ['model limit', fn (): Command => $this->createModelCommand(), $this->showInput('id', '7', 'limit', '1'), '--limit'],
            ['category limit', fn (): Command => $this->createCategoryCommand(), $this->showInput('id', '424', 'limit', '1'), '--limit'],
            ['tag limit', fn (): Command => $this->createTagCommand(), $this->showInput('identifier', '72', 'limit', '1'), '--limit'],
            ['playlist limit', fn (): Command => $this->createPlaylistCommand(), $this->showInput('id', '6', 'limit', '1'), '--limit'],
            ['user limit', fn (): Command => $this->createUserCommand(), $this->showInput('id', '320', 'limit', '1'), '--limit'],
            ['comment limit', fn (): Command => $this->createCommentCommand(), $this->showInput('id', '300', 'limit', '1'), '--limit'],
            ['video status', fn (): Command => $this->createVideoCommand(), $this->showInput('id', '73', 'status', 'disabled'), '--status'],
            [
                'video admin user',
                fn (): Command => $this->createVideoCommand(),
                $this->showInput('id', '73', 'admin-user', '1'),
                '--admin-user',
            ],
            ['video ip', fn (): Command => $this->createVideoCommand(), $this->showInput('id', '73', 'ip', '127.0.0.1'), '--ip'],
            [
                'video resolution',
                fn (): Command => $this->createVideoCommand(),
                $this->showInput('id', '73', 'resolution', '2'),
                '--resolution',
            ],
            [
                'video load type',
                fn (): Command => $this->createVideoCommand(),
                $this->showInput('id', '73', 'load-type', '1'),
                '--load-type',
            ],
            [
                'video server group',
                fn (): Command => $this->createVideoCommand(),
                $this->showInput('id', '73', 'server-group', '1'),
                '--server-group',
            ],
            [
                'video format video group',
                fn (): Command => $this->createVideoCommand(),
                $this->showInput('id', '73', 'format-video-group', '1'),
                '--format-video-group',
            ],
            ['video feed', fn (): Command => $this->createVideoCommand(), $this->showInput('id', '73', 'feed', '1'), '--feed'],
            [
                'video has errors',
                fn (): Command => $this->createVideoCommand(),
                $this->showInput('id', '73', 'has-errors', '1'),
                '--has-errors',
            ],
            [
                'video posted',
                fn (): Command => $this->createVideoCommand(),
                $this->showInput('id', '73', 'posted', 'yes'),
                '--posted',
            ],
            [
                'video neuroscore',
                fn (): Command => $this->createVideoCommand(),
                $this->showInput('id', '73', 'neuroscore', 'score_missing'),
                '--neuroscore',
            ],
            [
                'video digiregs copyright',
                fn (): Command => $this->createVideoCommand(),
                $this->showInput('id', '73', 'digiregs-copyright', 'copyright_applied'),
                '--digiregs-copyright',
            ],
            [
                'video show id',
                fn (): Command => $this->createVideoCommand(),
                $this->showInput('id', '73', 'show-id', '21'),
                '--show-id',
            ],
            ['album status', fn (): Command => $this->createAlbumCommand(), $this->showInput('id', '6', 'status', 'disabled'), '--status'],
            [
                'album category group',
                fn (): Command => $this->createAlbumCommand(),
                $this->showInput('id', '6', 'category-group', 'Group'),
                '--category-group',
            ],
            [
                'album content source group',
                fn (): Command => $this->createAlbumCommand(),
                $this->showInput('id', '6', 'content-source-group', 'Group'),
                '--content-source-group',
            ],
            [
                'album model group',
                fn (): Command => $this->createAlbumCommand(),
                $this->showInput('id', '6', 'model-group', 'Group'),
                '--model-group',
            ],
            [
                'album admin user',
                fn (): Command => $this->createAlbumCommand(),
                $this->showInput('id', '6', 'admin-user', '1'),
                '--admin-user',
            ],
            ['album ip', fn (): Command => $this->createAlbumCommand(), $this->showInput('id', '6', 'ip', '127.0.0.1'), '--ip'],
            [
                'album server group',
                fn (): Command => $this->createAlbumCommand(),
                $this->showInput('id', '6', 'server-group', '1'),
                '--server-group',
            ],
            ['album flag', fn (): Command => $this->createAlbumCommand(), $this->showInput('id', '6', 'flag', '1'), '--flag'],
            [
                'album has errors',
                fn (): Command => $this->createAlbumCommand(),
                $this->showInput('id', '6', 'has-errors', '1'),
                '--has-errors',
            ],
            [
                'album posted',
                fn (): Command => $this->createAlbumCommand(),
                $this->showInput('id', '6', 'posted', 'yes'),
                '--posted',
            ],
            [
                'album show id',
                fn (): Command => $this->createAlbumCommand(),
                $this->showInput('id', '6', 'show-id', '13'),
                '--show-id',
            ],
            [
                'album flag votes',
                fn (): Command => $this->createAlbumCommand(),
                $this->showInput('id', '6', 'flag-votes', '2'),
                '--flag-votes',
            ],
            [
                'album post date from',
                fn (): Command => $this->createAlbumCommand(),
                $this->showInput('id', '6', 'post-date-from', '2026-05-25'),
                '--post-date-from',
            ],
            [
                'album post date to',
                fn (): Command => $this->createAlbumCommand(),
                $this->showInput('id', '6', 'post-date-to', '2026-05-25'),
                '--post-date-to',
            ],
            ['dvd status', fn (): Command => $this->createDvdCommand(), $this->showInput('id', '4', 'status', 'disabled'), '--status'],
            ['dvd flag', fn (): Command => $this->createDvdCommand(), $this->showInput('id', '4', 'flag', '1'), '--flag'],
            [
                'dvd flag votes',
                fn (): Command => $this->createDvdCommand(),
                $this->showInput('id', '4', 'flag-votes', '2'),
                '--flag-votes',
            ],
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

    public function testStatsActionsRejectUnsupportedOptionsBeforeDatabaseAccess(): void
    {
        /** @var list<array{string, \Closure(): Command, array<string, mixed>, string}> $cases */
        $cases = [
            ['video stats search', fn (): Command => $this->createVideoCommand(), $this->statsInput('search'), '--search'],
            [
                'video stats flag search',
                fn (): Command => $this->createVideoCommand(),
                $this->statsFlagInput('search'),
                '--search',
            ],
            ['video stats status', fn (): Command => $this->createVideoCommand(), $this->statsInput('status', 'disabled'), '--status'],
            ['video stats limit', fn (): Command => $this->createVideoCommand(), $this->statsInput('limit', '1'), '--limit'],
            ['dvd stats search', fn (): Command => $this->createDvdCommand(), $this->statsInput('search'), '--search'],
            ['dvd stats status', fn (): Command => $this->createDvdCommand(), $this->statsInput('status', 'disabled'), '--status'],
            ['dvd stats limit', fn (): Command => $this->createDvdCommand(), $this->statsInput('limit', '1'), '--limit'],
            ['dvd stats user', fn (): Command => $this->createDvdCommand(), $this->statsInput('user', '1'), '--user'],
            ['model stats search', fn (): Command => $this->createModelCommand(), $this->statsInput('search'), '--search'],
            ['model stats status', fn (): Command => $this->createModelCommand(), $this->statsInput('status', 'disabled'), '--status'],
            ['model stats limit', fn (): Command => $this->createModelCommand(), $this->statsInput('limit', '1'), '--limit'],
            ['model stats usage', fn (): Command => $this->createModelCommand(), $this->statsInput('usage', 'used/videos'), '--usage'],
            ['tag stats search', fn (): Command => $this->createTagCommand(), $this->statsInput('search'), '--search'],
            ['tag stats status', fn (): Command => $this->createTagCommand(), $this->statsInput('status', 'inactive'), '--status'],
            ['tag stats limit', fn (): Command => $this->createTagCommand(), $this->statsInput('limit', '1'), '--limit'],
            ['tag stats unused', fn (): Command => $this->createTagCommand(), $this->statsInput('unused', true), '--unused'],
            ['user stats search', fn (): Command => $this->createUserCommand(), $this->statsInput('search'), '--search'],
            ['user stats status', fn (): Command => $this->createUserCommand(), $this->statsInput('status', 'disabled'), '--status'],
            ['user stats limit', fn (): Command => $this->createUserCommand(), $this->statsInput('limit', '1'), '--limit'],
            [
                'user stats removal requested',
                fn (): Command => $this->createUserCommand(),
                $this->statsInput('removal-requested', true),
                '--removal-requested',
            ],
            ['comment stats search', fn (): Command => $this->createCommentCommand(), $this->statsInput('search'), '--search'],
            ['comment stats pending', fn (): Command => $this->createCommentCommand(), $this->statsInput('pending', true), '--pending'],
            ['comment stats limit', fn (): Command => $this->createCommentCommand(), $this->statsInput('limit', '1'), '--limit'],
            ['comment stats video', fn (): Command => $this->createCommentCommand(), $this->statsInput('video', '999999'), '--video'],
        ];

        foreach ($cases as [$label, $createCommand, $input, $option]) {
            $tester = new CommandTester($createCommand());
            $tester->execute($input);

            $display = $tester->getDisplay();
            self::assertSame(1, $tester->getStatusCode(), "$label: $display");
            self::assertStringContainsString("The stats action does not support $option", $display, $label);
            self::assertStringNotContainsString('Database access should not be reached', $display, $label);
        }
    }

    public function testCategoryTreeRejectsUnsupportedOptionsBeforeDatabaseAccess(): void
    {
        /** @var list<array{string, array<string, mixed>, string}> $cases */
        $cases = [
            ['search', $this->treeInput('search'), '--search'],
            ['status', $this->treeInput('status', 'inactive'), '--status'],
            ['group', $this->treeInput('group'), '--group'],
            ['parent', $this->treeInput('parent'), '--parent'],
            ['unused', $this->treeInput('unused', true), '--unused'],
            ['usage', $this->treeInput('usage', 'used/videos'), '--usage'],
            ['field filter', $this->treeInput('field-filter', 'filled/synonyms'), '--field-filter'],
            ['limit', $this->treeInput('limit', '1'), '--limit'],
            ['title', $this->treeInput('title'), '--title'],
            ['description', $this->treeInput('description'), '--description'],
            ['dry run', $this->treeInput('dry-run', true), '--dry-run'],
        ];

        foreach ($cases as [$label, $input, $option]) {
            $tester = new CommandTester($this->createCategoryCommand());
            $tester->execute($input);

            $display = $tester->getDisplay();
            self::assertSame(1, $tester->getStatusCode(), "$label: $display");
            self::assertStringContainsString("The tree action does not support $option", $display, $label);
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

    /**
     * @return array<string, mixed>
     */
    private function statsInput(string $option, mixed $value = '__missing__'): array
    {
        return [
            'action' => 'stats',
            '--' . $option => $value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function statsFlagInput(string $option, mixed $value = '__missing__'): array
    {
        return [
            '--stats' => true,
            '--' . $option => $value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function treeInput(string $option, mixed $value = '__missing__'): array
    {
        return [
            'action' => 'tree',
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
