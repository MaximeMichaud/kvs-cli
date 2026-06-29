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
use KVS\CLI\Command\ConfigCommand;
use KVS\CLI\Command\System\ConversionCommand;
use KVS\CLI\Command\System\QueueCommand;
use KVS\CLI\Command\System\ServerCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class GlobalActionArgumentValidationTest extends TestCase
{
    private string $kvsPath;
    private Configuration $config;

    protected function setUp(): void
    {
        $this->kvsPath = TestHelper::createTestKvsInstallation();
        $this->config = TestHelper::createTestConfiguration($this->kvsPath);
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->kvsPath);
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('unsupportedGlobalActionArgumentProvider')]
    public function testGlobalActionsRejectIgnoredArguments(
        string $commandName,
        array $input,
        string $expectedMessage
    ): void {
        $tester = new CommandTester($this->createCommand($commandName));

        $exitCode = $tester->execute($input);
        $output = $tester->getDisplay();

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString($expectedMessage, $output);
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    public static function unsupportedGlobalActionArgumentProvider(): iterable
    {
        yield 'video list id' => [
            'video',
            ['action' => 'list', 'id' => '2', '--format' => 'count'],
            'The list action does not support a video ID argument',
        ];
        yield 'video stats id' => [
            'video',
            ['action' => 'stats', 'id' => '2'],
            'The stats action does not support a video ID argument',
        ];
        yield 'user list id' => [
            'user',
            ['action' => 'list', 'id' => '1', '--format' => 'count'],
            'The list action does not support a user ID or username argument',
        ];
        yield 'user stats id' => [
            'user',
            ['action' => 'stats', 'id' => '1'],
            'The stats action does not support a user ID or username argument',
        ];
        yield 'album list id' => [
            'album',
            ['action' => 'list', 'id' => '2', '--format' => 'count'],
            'The list action does not support an album ID argument',
        ];
        yield 'dvd list id' => [
            'dvd',
            ['action' => 'list', 'id' => '1', '--format' => 'count'],
            'The list action does not support a DVD ID argument',
        ];
        yield 'dvd stats id' => [
            'dvd',
            ['action' => 'stats', 'id' => '1'],
            'The stats action does not support a DVD ID argument',
        ];
        yield 'model list id' => [
            'model',
            ['action' => 'list', 'id' => '1', '--format' => 'count'],
            'The list action does not support a model ID argument',
        ];
        yield 'model stats id' => [
            'model',
            ['action' => 'stats', 'id' => '1'],
            'The stats action does not support a model ID argument',
        ];
        yield 'tag list identifier' => [
            'tag',
            ['action' => 'list', 'identifier' => '1', '--format' => 'count'],
            'The list action does not support a tag ID or name argument',
        ];
        yield 'tag stats identifier' => [
            'tag',
            ['action' => 'stats', 'identifier' => '1'],
            'The stats action does not support a tag ID or name argument',
        ];
        yield 'category list id' => [
            'category',
            ['action' => 'list', 'id' => '1', '--format' => 'count'],
            'The list action does not support a category ID or title argument',
        ];
        yield 'category tree id' => [
            'category',
            ['action' => 'tree', 'id' => '1', '--format' => 'count'],
            'The tree action does not support a category ID or title argument',
        ];
        yield 'playlist list id' => [
            'playlist',
            ['action' => 'list', 'id' => '1', '--format' => 'count'],
            'The list action does not support a playlist ID argument',
        ];
        yield 'comment list id' => [
            'comment',
            ['action' => 'list', 'id' => '1', '--format' => 'count'],
            'The list action does not support a comment ID argument',
        ];
        yield 'comment pending id' => [
            'comment',
            ['action' => 'pending', 'id' => '1', '--format' => 'count'],
            'The pending action does not support a comment ID argument',
        ];
        yield 'comment stats id' => [
            'comment',
            ['action' => 'stats', 'id' => '1'],
            'The stats action does not support a comment ID argument',
        ];
        yield 'queue list id' => [
            'queue',
            ['action' => 'list', 'id' => '1', '--format' => 'count'],
            'The list action does not support a task ID argument',
        ];
        yield 'queue history id' => [
            'queue',
            ['action' => 'history', 'id' => '1', '--format' => 'count'],
            'The history action does not support a task ID argument',
        ];
        yield 'queue stats id' => [
            'queue',
            ['action' => 'stats', 'id' => '1'],
            'The stats action does not support a task ID argument',
        ];
        yield 'server list id' => [
            'server',
            ['--force' => true, 'action' => 'list', 'id' => '1', '--format' => 'count'],
            'The list action does not support a server ID argument',
        ];
        yield 'server stats id' => [
            'server',
            ['--force' => true, 'action' => 'stats', 'id' => '1'],
            'The stats action does not support a server ID argument',
        ];
        yield 'conversion list id' => [
            'conversion',
            ['--force' => true, 'action' => 'list', 'id' => '1', '--format' => 'count'],
            'The list action does not support a conversion server ID argument',
        ];
        yield 'conversion stats id' => [
            'conversion',
            ['--force' => true, 'action' => 'stats', 'id' => '1'],
            'The stats action does not support a conversion server ID argument',
        ];
        yield 'config list key' => [
            'config',
            ['action' => 'list', 'key' => 'db.host', '--json' => true],
            'The list action does not support a configuration key argument',
        ];
        yield 'config list key and value' => [
            'config',
            ['action' => 'list', 'key' => 'db.host', 'value' => 'unexpected-value', '--json' => true],
            'The list action does not support a configuration key argument',
        ];
        yield 'config get value' => [
            'config',
            ['action' => 'get', 'key' => 'db.host', 'value' => 'unexpected-value'],
            'The get action does not support a value argument',
        ];
    }

    private function createCommand(string $commandName): Command
    {
        $command = match ($commandName) {
            'album' => new AlbumCommand($this->config),
            'category' => new CategoryCommand($this->config),
            'comment' => new CommentCommand($this->config),
            'config' => new ConfigCommand($this->config),
            'conversion' => new ConversionCommand($this->config),
            'dvd' => new DvdCommand($this->config),
            'model' => new ModelCommand($this->config),
            'playlist' => new PlaylistCommand($this->config),
            'queue' => new QueueCommand($this->config),
            'server' => new ServerCommand($this->config),
            'tag' => new TagCommand($this->config),
            'user' => new UserCommand($this->config),
            'video' => new VideoCommand($this->config),
            default => throw new \InvalidArgumentException("Unknown command: $commandName"),
        };

        $command->setName("test:$commandName");

        return $command;
    }
}
