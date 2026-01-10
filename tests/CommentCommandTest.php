<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Content\CommentCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

#[CoversClass(CommentCommand::class)]
class CommentCommandTest extends TestCase
{
    private Configuration $config;
    private CommentCommand $command;
    private CommandTester $tester;
    private ?\PDO $db = null;

    protected function setUp(): void
    {
        $kvsPath = getenv('KVS_TEST_PATH') ?: __DIR__ . '/../../kvs';

        if (!is_dir($kvsPath)) {
            $this->markTestSkipped('KVS installation not found at ' . $kvsPath);
        }

        $this->config = new Configuration(['path' => $kvsPath]);
        $this->command = new CommentCommand($this->config);

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);

        try {
            $this->db = TestHelper::getPDO();
        } catch (\PDOException $e) {
            $this->markTestSkipped('Cannot connect to test database: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        $this->db = null;
    }

    public function testListCommentsDefault(): void
    {
        $this->tester->execute(['action' => 'list']);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testListCommentsWithLimit(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 5
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testListCommentsApproved(): void
    {
        // Check if is_approved column exists
        $table = $this->config->getTablePrefix() . 'comments';
        $stmt = $this->db->query("SHOW COLUMNS FROM {$table} LIKE 'is_approved'");
        if ($stmt->rowCount() === 0) {
            $this->markTestSkipped('is_approved column does not exist in this KVS version');
        }

        $this->tester->execute([
            'action' => 'list',
            '--approved' => true
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testListCommentsPending(): void
    {
        // Check if is_approved column exists
        $table = $this->config->getTablePrefix() . 'comments';
        $stmt = $this->db->query("SHOW COLUMNS FROM {$table} LIKE 'is_approved'");
        if ($stmt->rowCount() === 0) {
            $this->markTestSkipped('is_approved column does not exist in this KVS version');
        }

        $this->tester->execute([
            'action' => 'list',
            '--pending' => true
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testListCommentsOldest(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--oldest' => true
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testListCommentsFilterByVideo(): void
    {
        // Get a video ID that has comments
        $table = $this->config->getTablePrefix() . 'comments';
        $stmt = $this->db->query("SELECT object_id FROM {$table} WHERE object_type_id = 1 LIMIT 1");
        $videoId = $stmt->fetchColumn();

        if ($videoId === false) {
            $this->markTestSkipped('No video comments in database');
        }

        $this->tester->execute([
            'action' => 'list',
            '--video' => $videoId
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testListCommentsFilterByUser(): void
    {
        // Get a user ID that has comments
        $table = $this->config->getTablePrefix() . 'comments';
        $stmt = $this->db->query("SELECT user_id FROM {$table} WHERE user_id IS NOT NULL LIMIT 1");
        $userId = $stmt->fetchColumn();

        if ($userId === false) {
            $this->markTestSkipped('No user comments in database');
        }

        $this->tester->execute([
            'action' => 'list',
            '--user' => $userId
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testListCommentsSearch(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'test'
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    #[DataProvider('provideOutputFormats')]
    public function testListCommentsFormats(string $format, string $contains): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => $format,
            '--limit' => 3
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString($contains, $output);
    }

    public static function provideOutputFormats(): array
    {
        return [
            'table format' => ['table', 'Comment id'],
            'json format' => ['json', 'comment_id'],
        ];
    }

    public function testShowComment(): void
    {
        // Get a comment ID
        $table = $this->config->getTablePrefix() . 'comments';
        $stmt = $this->db->query("SELECT comment_id FROM {$table} LIMIT 1");
        $commentId = $stmt->fetchColumn();

        if ($commentId === false) {
            $this->markTestSkipped('No comments in database');
        }

        $this->tester->execute([
            'action' => 'show',
            'id' => $commentId
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Comment #' . $commentId, $output);
    }

    public function testShowCommentNotFound(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '999999999'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('not found', $output);
    }

    public function testShowCommentMissingId(): void
    {
        $this->tester->execute([
            'action' => 'show'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('required', $output);
    }

    public function testStats(): void
    {
        $this->tester->execute(['action' => 'stats']);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Comment Statistics', $output);
        $this->assertStringContainsString('Total Comments', $output);
    }

    public function testDefaultActionIsList(): void
    {
        $this->tester->execute([]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testInvalidActionFallsBackToList(): void
    {
        $this->tester->execute(['action' => 'invalid']);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }
}
