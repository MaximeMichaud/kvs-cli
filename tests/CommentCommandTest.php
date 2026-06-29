<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\CommentCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CommentCommand::class)]
class CommentCommandTest extends TestCase
{
    private string $kvsPath;
    private Configuration $config;
    private CommentCommand $command;
    private CommandTester $tester;
    private PDO $db;

    protected function setUp(): void
    {
        $this->kvsPath = TestHelper::createTestKvsInstallation();
        $this->db = $this->createDatabase();

        $this->config = TestHelper::createTestConfiguration($this->kvsPath);
        $this->command = $this->createCommand($this->db);
        $this->tester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->kvsPath);
    }

    public function testHelpDocumentation(): void
    {
        $help = $this->command->getHelp();

        $this->assertStringContainsString('Output format: table, csv, json, yaml, count, ids', $help);
        $this->assertStringContainsString('content is the parent object ID', $help);
        $this->assertStringContainsString('Use comment for the comment text', $help);
    }

    public function testListCommentsDefault(): void
    {
        $this->tester->execute(['action' => 'list']);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Great test video', $output);
        $this->assertStringContainsString('Needs review test phrase', $output);
        $this->assertStringContainsString('Album feedback', $output);
    }

    public function testListCommentsWithLimit(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 2,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Great test video', $output);
        $this->assertStringContainsString('Needs review test phrase', $output);
        $this->assertStringNotContainsString('Album feedback', $output);
    }

    public function testListCommentsApproved(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--approved' => true,
            '--format' => 'json',
            '--fields' => 'comment_id,comment',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame([30, 10], array_map(static fn (array $row): int => (int) $row['comment_id'], $rows));
        $this->assertSame(['Great test video', 'Album feedback'], array_column($rows, 'comment'));
    }

    public function testListCommentsPending(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--pending' => true,
            '--format' => 'json',
            '--fields' => 'comment_id,comment',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(20, (int) $rows[0]['comment_id']);
        $this->assertSame('Needs review test phrase', $rows[0]['comment']);
    }

    public function testListCommentsNotApprovedLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--not-approved' => true,
            '--format' => 'json',
            '--fields' => 'comment_id,comment',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([20], array_map(static fn (array $row): int => (int) $row['comment_id'], $rows));
        $this->assertSame('Needs review test phrase', $rows[0]['comment']);
    }

    public function testListCommentsOldest(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--oldest' => true,
            '--format' => 'json',
            '--fields' => 'comment_id',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame([10, 20, 30], array_map(static fn (array $row): int => (int) $row['comment_id'], $rows));
    }

    public function testListCommentsUsesKvsAdminDefaultOrdering(): void
    {
        $this->db->exec(
            'UPDATE ' . TestHelper::table('comments') .
            " SET added_date = '2099-01-01 00:00:00' WHERE comment_id = 10"
        );

        $this->tester->execute([
            'action' => 'list',
            '--format' => 'json',
            '--fields' => 'comment_id',
            '--limit' => 3,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame([30, 20, 10], array_map(static fn (array $row): int => (int) $row['comment_id'], $rows));
    }

    public function testListCommentsFilterByVideo(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--video' => 100,
            '--format' => 'json',
            '--fields' => 'comment_id,content_title',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame([30, 20], array_map(static fn (array $row): int => (int) $row['comment_id'], $rows));
        $this->assertSame(['Intro Video', 'Intro Video'], array_column($rows, 'content_title'));
    }

    public function testListCommentsFiltersByKvsAdminObjectTypeAndId(): void
    {
        $cases = [
            'object type alias' => [['--object-type' => 'video'], [30, 20]],
            'object type id' => [['--object-type' => '1'], [30, 20]],
            'object id only' => [['--object-id' => '100'], [30, 20]],
            'object type and id' => [['--object-type' => 'album', '--object-id' => '200'], [10]],
            'object type no match' => [['--object-type' => 'album', '--object-id' => '100'], []],
        ];

        foreach ($cases as $label => [$options, $expectedIds]) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                '--format' => 'json',
                '--fields' => 'comment_id',
                ...$options,
            ]);

            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $tester->getStatusCode(), "{$label}: {$tester->getDisplay()}");
            $this->assertSame(
                $expectedIds,
                array_map(static fn (array $row): int => (int) $row['comment_id'], $rows),
                $label
            );
        }
    }

    public function testListCommentsFiltersByKvsAdminPlaylistObject(): void
    {
        $this->insertComment($this->db, [
            'comment_id' => 40,
            'object_id' => 700,
            'object_type_id' => 13,
            'object_sub_id' => 0,
            'user_id' => 1,
            'anonymous_username' => '',
            'is_approved' => 1,
            'is_review_needed' => 0,
            'comment' => 'Playlist feedback',
            'country_code' => 'CA',
            'ip' => 2130706433,
            'rating' => 4,
            'added_date' => date('Y-m-d H:i:s'),
        ]);

        foreach ([['--playlist' => '700'], ['--object-type' => 'playlist']] as $options) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                '--format' => 'json',
                '--fields' => 'comment_id,object',
                ...$options,
            ]);

            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertSame([40], array_map(static fn (array $row): int => (int) $row['comment_id'], $rows));
            $this->assertSame('Playlist Title', $rows[0]['object']);
        }
    }

    public function testListCommentsFiltersByIpLikeKvsAdmin(): void
    {
        $cases = [
            '127.0.0.1' => [30],
            '0.0.0.0' => [20, 10],
        ];

        foreach ($cases as $ip => $expectedIds) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                '--ip' => $ip,
                '--format' => 'json',
                '--fields' => 'comment_id',
            ]);

            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertSame($expectedIds, array_map(static fn (array $row): int => (int) $row['comment_id'], $rows));
        }
    }

    public function testListCommentsFilterByUser(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--user' => 1,
            '--format' => 'json',
            '--fields' => 'comment_id,user',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(30, (int) $rows[0]['comment_id']);
        $this->assertSame('alice', $rows[0]['user']);
    }

    public function testListCommentsFilterByUsernameLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--user' => 'alice',
            '--format' => 'json',
            '--fields' => 'comment_id,user',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertCount(1, $rows);
        $this->assertSame(30, (int) $rows[0]['comment_id']);
        $this->assertSame('alice', $rows[0]['user']);
    }

    public function testListCommentsFilterByAnonymousUsernameLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--user' => 'GuestUser',
            '--format' => 'json',
            '--fields' => 'comment_id,user',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertCount(1, $rows);
        $this->assertSame(10, (int) $rows[0]['comment_id']);
        $this->assertSame('GuestUser', $rows[0]['user']);
    }

    public function testListCommentsSearchesRegisteredUsernameLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'bob',
            '--format' => 'json',
            '--fields' => 'comment_id,user,comment',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([20], array_map(static fn (array $row): int => (int) $row['comment_id'], $rows));
        $this->assertSame('bob', $rows[0]['user']);
        $this->assertSame('Needs review test phrase', $rows[0]['comment']);
    }

    public function testListCommentsCountSearchesAnonymousUsernameLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'GuestUser',
            '--format' => 'count',
        ]);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame('1', trim($this->tester->getDisplay()));
    }

    public function testListCommentsAllowsZeroUserSentinel(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--user' => 0,
            '--format' => 'json',
            '--fields' => 'comment_id,user',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertCount(1, $rows);
        $this->assertSame(10, (int) $rows[0]['comment_id']);
        $this->assertSame('GuestUser', $rows[0]['user']);
    }

    public function testListAndPendingRejectInvalidNumericFilters(): void
    {
        foreach (['list', 'pending'] as $action) {
            foreach (['video', 'album', 'content-source', 'model', 'dvd', 'post', 'playlist', 'object-id'] as $option) {
                foreach (['abc', '1.5', '-1'] as $value) {
                    $tester = new CommandTester($this->command);
                    $tester->execute([
                        'action' => $action,
                        '--format' => 'count',
                        '--' . $option => $value,
                    ]);

                    $display = $tester->getDisplay();
                    $this->assertSame(1, $tester->getStatusCode(), "$action --$option=$value: $display");
                    $this->assertStringContainsString("Invalid value for --$option", $display, "$action --$option=$value");
                }
            }

            foreach (['1.5', '-1'] as $value) {
                $tester = new CommandTester($this->command);
                $tester->execute([
                    'action' => $action,
                    '--format' => 'count',
                    '--user' => $value,
                ]);

                $display = $tester->getDisplay();
                $this->assertSame(1, $tester->getStatusCode(), "$action --user=$value: $display");
                $this->assertStringContainsString('Invalid value for --user', $display, "$action --user=$value");
            }

            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => $action,
                '--format' => 'count',
                '--object-type' => 'unknown',
            ]);
            $this->assertSame(1, $tester->getStatusCode(), "$action --object-type=unknown");
            $this->assertStringContainsString('Invalid value for --object-type', $tester->getDisplay());

            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => $action,
                '--format' => 'count',
                '--ip' => '999.999.999.999',
            ]);
            $this->assertSame(1, $tester->getStatusCode(), "$action --ip=999.999.999.999");
            $this->assertStringContainsString('Invalid value for --ip', $tester->getDisplay());
        }
    }

    #[DataProvider('provideConflictingApprovalFilters')]
    public function testListCommentsRejectsConflictingApprovalFilters(array $options): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => 'count',
            ...$options,
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('cannot be used together', $this->tester->getDisplay());
    }

    /**
     * @return iterable<string, array{options: array<string, true>}>
     */
    public static function provideConflictingApprovalFilters(): iterable
    {
        yield 'approved and pending' => ['options' => ['--approved' => true, '--pending' => true]];
        yield 'approved and not approved' => ['options' => ['--approved' => true, '--not-approved' => true]];
        yield 'pending and not approved' => ['options' => ['--pending' => true, '--not-approved' => true]];
    }

    public function testListCommentsExposesKvsAdminFields(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--fields' => 'comment_id,comment_full,object,user_status_id,ip,country,rating,is_approved',
            '--format' => 'json',
            '--limit' => 1,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(30, (int) $rows[0]['comment_id']);
        $this->assertSame('Great test video', $rows[0]['comment_full']);
        $this->assertSame('Intro Video', $rows[0]['object']);
        $this->assertSame(2, (int) $rows[0]['user_status_id']);
        $this->assertSame('127.0.0.1', $rows[0]['ip']);
        $this->assertSame('Canada', $rows[0]['country']);
        $this->assertSame(5, (int) $rows[0]['rating']);
        $this->assertSame(1, (int) $rows[0]['is_approved']);
    }

    public function testListCommentsExposesKvsObjectCompanionFields(): void
    {
        $this->insertComment($this->db, [
            'comment_id' => 40,
            'object_id' => 600,
            'object_type_id' => 12,
            'object_sub_id' => 0,
            'user_id' => 1,
            'anonymous_username' => '',
            'is_approved' => 1,
            'is_review_needed' => 0,
            'comment' => 'Post companion field test',
            'country_code' => 'CA',
            'ip' => 2130706433,
            'rating' => 4,
            'added_date' => date('Y-m-d H:i:s'),
        ]);

        $this->tester->execute([
            'action' => 'list',
            '--search' => 'Post companion field test',
            '--fields' => 'comment_id,object,object_dir,post_type_id',
            '--format' => 'json',
        ]);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(1, $rows);
        $this->assertSame(40, (int) $rows[0]['comment_id']);
        $this->assertSame('Post Title', $rows[0]['object']);
        $this->assertSame('post-title', $rows[0]['object_dir']);
        $this->assertSame(2, (int) $rows[0]['post_type_id']);
    }

    public function testListCommentsUsesAnonymousUsernameForAnonymousUser(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'Album feedback',
            '--format' => 'json',
            '--fields' => 'comment_id,user',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(10, (int) $rows[0]['comment_id']);
        $this->assertSame('GuestUser', $rows[0]['user']);
    }

    public function testPendingCommentsExposeKvsAdminUserStatusField(): void
    {
        $this->tester->execute([
            'action' => 'pending',
            '--fields' => 'comment_id,user,user_status_id,ip',
            '--format' => 'json',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame(20, (int) $rows[0]['comment_id']);
        $this->assertSame('bob', $rows[0]['user']);
        $this->assertSame(0, (int) $rows[0]['user_status_id']);
        $this->assertSame('0.0.0.0', $rows[0]['ip']);
    }

    public function testStatsUsesAnonymousUsernameForTopCommenters(): void
    {
        $this->tester->execute(['action' => 'stats']);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('GuestUser', $output);
        $this->assertStringNotContainsString('Unknown', $output);
    }

    public function testListCommentsSearch(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'review',
            '--format' => 'json',
            '--fields' => 'comment_id,comment',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(20, (int) $rows[0]['comment_id']);
        $this->assertSame('Needs review test phrase', $rows[0]['comment']);
    }

    #[DataProvider('provideOutputFormats')]
    public function testListCommentsFormats(string $format): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => $format,
            '--limit' => 3,
        ]);

        $output = trim($this->tester->getDisplay());

        $this->assertEquals(0, $this->tester->getStatusCode());

        if ($format === 'table') {
            $this->assertStringContainsString('Comment id', $output);
            $this->assertStringContainsString('Great test video', $output);
            return;
        }

        if ($format === 'json') {
            $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            $this->assertCount(3, $rows);
            $this->assertSame(30, (int) $rows[0]['comment_id']);
            return;
        }

        if ($format === 'count') {
            $this->assertSame('3', $output);
            return;
        }

        $this->assertSame('30 20 10', $output);
    }

    public static function provideOutputFormats(): array
    {
        return [
            'table format' => ['table'],
            'json format' => ['json'],
            'count format' => ['count'],
            'ids format' => ['ids'],
        ];
    }

    public function testShowComment(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '30',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Comment #30', $output);
        $this->assertStringContainsString('alice', $output);
        $this->assertStringContainsString('alice@example.test', $output);
        $this->assertStringContainsString('Approved', $output);
        $this->assertStringContainsString('Video', $output);
        $this->assertStringContainsString('Intro Video', $output);
        $this->assertStringContainsString('Great test video', $output);
    }

    public function testShowCommentSupportsJsonFormat(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '30',
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();
        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('30', $rows[0]['id']);
        $this->assertSame('alice', $rows[0]['user']);
        $this->assertSame('Approved', $rows[0]['status']);
        $this->assertSame('Great test video', $rows[0]['comment']);
        $this->assertStringNotContainsString('Comment #30', $output);
    }

    public function testShowCommentTreatsReviewNeededAsPendingEvenWhenApproved(): void
    {
        $this->insertComment($this->db, [
            'comment_id' => 40,
            'object_id' => 100,
            'object_type_id' => 1,
            'object_sub_id' => 0,
            'user_id' => 1,
            'anonymous_username' => '',
            'is_approved' => 1,
            'is_review_needed' => 1,
            'comment' => 'Approved but still awaiting review',
            'country_code' => 'CA',
            'ip' => 0,
            'rating' => 0,
            'added_date' => date('Y-m-d H:i:s'),
        ]);

        $this->tester->execute([
            'action' => 'show',
            'id' => '40',
            '--format' => 'json',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame('Pending', $rows[0]['status']);
    }

    public function testShowCommentRejectsNonIntegerIdBeforeQuery(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '30abc',
            '--format' => 'json',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Invalid Comment ID', $this->tester->getDisplay());
    }

    public function testShowCommentUsesAnonymousUsernameForAnonymousUser(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '10',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('GuestUser', $output);
        $this->assertStringNotContainsString('Guest                             ', $output);
    }

    public function testShowCommentNotFound(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '999999999',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Comment not found: 999999999', $output);
    }

    public function testShowCommentMissingId(): void
    {
        $this->tester->execute([
            'action' => 'show',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Comment ID is required', $output);
        $this->assertStringContainsString('Usage: kvs content:comment show <comment_id>', $output);
    }

    public function testStats(): void
    {
        $this->tester->execute(['action' => 'stats']);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Comment Statistics', $output);
        $this->assertStringContainsString('Overall Statistics', $output);
        $this->assertMatchesRegularExpression('/Total Comments\W+3/', $output);
        $this->assertMatchesRegularExpression('/Unique Commenters\W+3/', $output);
        $this->assertMatchesRegularExpression('/Video Comments\W+2/', $output);
        $this->assertMatchesRegularExpression('/Album Comments\W+1/', $output);
        $this->assertMatchesRegularExpression('/Comments \(Last 7 Days\)\W+3/', $output);
        $this->assertStringContainsString('Top 10 Commenters', $output);
        $this->assertStringContainsString('alice', $output);
        $this->assertStringContainsString('bob', $output);
        $this->assertStringContainsString('GuestUser', $output);
    }

    public function testStatsSupportsJsonFormat(): void
    {
        $this->tester->execute([
            'action' => 'stats',
            '--format' => 'json',
            '--fields' => 'section,metric,value,label',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $rowsByMetric = array_column($rows, null, 'metric');

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame('overall', $rowsByMetric['Total Comments']['section'] ?? null);
        $this->assertSame(3, (int) ($rowsByMetric['Total Comments']['value'] ?? 0));
        $this->assertStringNotContainsString('Comment Statistics', $this->tester->getDisplay());
    }

    public function testPendingActionListsReviewNeededComments(): void
    {
        $this->tester->execute([
            'action' => 'pending',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Pending Comments (1 awaiting moderation)', $output);
        $this->assertStringContainsString('Needs review test phrase', $output);
        $this->assertStringContainsString('Use kvs comment approve ID', $output);
        $this->assertStringNotContainsString('Great test video', $output);
    }

    public function testPendingActionJsonFormatIsNotDecoratedAndExposesContentAlias(): void
    {
        $this->tester->execute([
            'action' => 'pending',
            '--format' => 'json',
            '--fields' => 'comment_id,content,content_title,type,is_approved',
        ]);

        $output = $this->tester->getDisplay();
        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringNotContainsString('Pending Comments', $output);
        $this->assertStringNotContainsString('Use kvs comment approve ID', $output);
        $this->assertCount(1, $rows);
        $this->assertSame(20, (int) $rows[0]['comment_id']);
        $this->assertSame(100, (int) $rows[0]['content']);
        $this->assertSame('Intro Video', $rows[0]['content_title']);
        $this->assertSame('Video', $rows[0]['type']);
        $this->assertSame(0, (int) $rows[0]['is_approved']);
    }

    public function testPendingActionCountFormatIgnoresPaginationAndIsNotDecorated(): void
    {
        $this->insertComment($this->db, [
            'comment_id' => 40,
            'object_id' => 200,
            'object_type_id' => 2,
            'object_sub_id' => 0,
            'user_id' => 1,
            'anonymous_username' => '',
            'is_approved' => 0,
            'is_review_needed' => 1,
            'comment' => 'Second pending comment',
            'country_code' => 'CA',
            'ip' => 0,
            'rating' => 0,
            'added_date' => date('Y-m-d H:i:s'),
        ]);

        $this->tester->execute([
            'action' => 'pending',
            '--format' => 'count',
            '--limit' => 1,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('2', trim($this->tester->getDisplay()));
    }

    public function testDefaultActionIsList(): void
    {
        $this->tester->execute([]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Great test video', $this->tester->getDisplay());
    }

    public function testInvalidActionReturnsFailure(): void
    {
        $this->tester->execute(['action' => 'invalid']);

        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Unknown comment action "invalid"', $output);
        $this->assertStringContainsString('Available actions: list, pending', $output);
        $this->assertStringContainsString('show, approve, reject, delete, stats', $output);
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        $this->createSchema($db);
        $this->seedDatabase($db);

        return $db;
    }

    private function createSchema(PDO $db): void
    {
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('comments') . ' (' .
            'comment_id INTEGER, object_id INTEGER, object_type_id INTEGER, object_sub_id INTEGER, ' .
            'user_id INTEGER, anonymous_username TEXT, is_approved INTEGER, is_review_needed INTEGER, ' .
            'comment TEXT, ip INTEGER, country_code TEXT, rating INTEGER, added_date TEXT)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('users') . ' (' .
            'user_id INTEGER, username TEXT, email TEXT, status_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('list_countries') . ' (' .
            'country_code TEXT, title TEXT, language_code TEXT)'
        );

        $this->createObjectTable($db, 'videos', 'video_id');
        $this->createObjectTable($db, 'albums', 'album_id');
        $this->createObjectTable($db, 'content_sources', 'content_source_id');
        $this->createObjectTable($db, 'models', 'model_id');
        $this->createObjectTable($db, 'dvds', 'dvd_id');
        $this->createObjectTable($db, 'posts', 'post_id', true);
        $this->createObjectTable($db, 'playlists', 'playlist_id');
    }

    private function createObjectTable(PDO $db, string $table, string $idColumn, bool $includePostType = false): void
    {
        $postTypeColumn = $includePostType ? ', post_type_id INTEGER' : '';
        $db->exec(
            'CREATE TABLE ' . TestHelper::table($table) . ' (' .
            $idColumn . ' INTEGER, title TEXT, dir TEXT, comments_count INTEGER' . $postTypeColumn . ')'
        );
    }

    private function seedDatabase(PDO $db): void
    {
        $db->exec(
            'INSERT INTO ' . TestHelper::table('users') .
            " (user_id, username, email, status_id) VALUES " .
            "(1, 'alice', 'alice@example.test', 2), (2, 'bob', 'bob@example.test', 0)"
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('list_countries') .
            " (country_code, title, language_code) VALUES " .
            "('CA', 'Canada', 'en'), ('US', 'United States', 'en')"
        );

        $db->exec(
            'INSERT INTO ' . TestHelper::table('videos') .
            " (video_id, title, dir, comments_count) VALUES (100, 'Intro Video', 'intro-video', 2)"
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('albums') .
            " (album_id, title, dir, comments_count) VALUES (200, 'Sample Album', 'sample-album', 1)"
        );

        $objectFixtures = [
            ['content_sources', 'content_source_id', 300, 'Source Title', 'source-title', null],
            ['models', 'model_id', 400, 'Model Title', 'model-title', null],
            ['dvds', 'dvd_id', 500, 'DVD Title', 'dvd-title', null],
            ['posts', 'post_id', 600, 'Post Title', 'post-title', 2],
            ['playlists', 'playlist_id', 700, 'Playlist Title', 'playlist-title', null],
        ];

        foreach ($objectFixtures as [$table, $idColumn, $id, $title, $dir, $postTypeId]) {
            $postTypeColumn = $postTypeId === null ? '' : ', post_type_id';
            $postTypeValue = $postTypeId === null ? '' : ', :post_type_id';
            $stmt = $db->prepare(
                'INSERT INTO ' . TestHelper::table($table) .
                " ({$idColumn}, title, dir, comments_count{$postTypeColumn}) " .
                "VALUES (:id, :title, :dir, 0{$postTypeValue})"
            );
            $params = ['id' => $id, 'title' => $title, 'dir' => $dir];
            if ($postTypeId !== null) {
                $params['post_type_id'] = $postTypeId;
            }
            $stmt->execute($params);
        }

        $this->insertComment($db, [
            'comment_id' => 30,
            'object_id' => 100,
            'object_type_id' => 1,
            'object_sub_id' => 0,
            'user_id' => 1,
            'anonymous_username' => '',
            'is_approved' => 1,
            'is_review_needed' => 0,
            'comment' => 'Great test video',
            'country_code' => 'CA',
            'ip' => 2130706433,
            'rating' => 5,
            'added_date' => date('Y-m-d H:i:s', time() - 3600),
        ]);
        $this->insertComment($db, [
            'comment_id' => 20,
            'object_id' => 100,
            'object_type_id' => 1,
            'object_sub_id' => 0,
            'user_id' => 2,
            'anonymous_username' => '',
            'is_approved' => 0,
            'is_review_needed' => 1,
            'comment' => 'Needs review test phrase',
            'country_code' => 'US',
            'ip' => 0,
            'rating' => 0,
            'added_date' => date('Y-m-d H:i:s', time() - 7200),
        ]);
        $this->insertComment($db, [
            'comment_id' => 10,
            'object_id' => 200,
            'object_type_id' => 2,
            'object_sub_id' => 0,
            'user_id' => 0,
            'anonymous_username' => 'GuestUser',
            'is_approved' => 1,
            'is_review_needed' => 0,
            'comment' => 'Album feedback',
            'country_code' => '',
            'ip' => 0,
            'rating' => 3,
            'added_date' => date('Y-m-d H:i:s', time() - 259200),
        ]);
    }

    /**
     * @param array<string, int|string> $comment
     */
    private function insertComment(PDO $db, array $comment): void
    {
        $stmt = $db->prepare(
            'INSERT INTO ' . TestHelper::table('comments') .
            ' (comment_id, object_id, object_type_id, object_sub_id, user_id, anonymous_username, ' .
            'is_approved, is_review_needed, comment, ip, country_code, rating, added_date) VALUES ' .
            '(:comment_id, :object_id, :object_type_id, :object_sub_id, :user_id, :anonymous_username, ' .
            ':is_approved, :is_review_needed, :comment, :ip, :country_code, :rating, :added_date)'
        );
        $stmt->execute($comment);
    }

    private function createCommand(PDO $db): CommentCommand
    {
        return new class ($this->config, $db) extends CommentCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:comment');
                $this->setDescription('Manage KVS comments');
                $this->setAliases(['comment', 'comments']);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
    }
}
