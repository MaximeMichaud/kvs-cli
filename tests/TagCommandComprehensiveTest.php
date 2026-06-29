<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\TagCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(TagCommand::class)]
class TagCommandComprehensiveTest extends TestCase
{
    private string $kvsPath;
    private Configuration $config;
    private TagCommand $command;
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

    public function testCommandMetadata(): void
    {
        $this->assertEquals('content:tag', $this->command->getName());
        $this->assertStringContainsString('tag', strtolower($this->command->getDescription()));

        $aliases = $this->command->getAliases();
        $this->assertContains('tag', $aliases);
        $this->assertContains('tags', $aliases);
    }

    public function testCommandHasAllOptions(): void
    {
        $definition = $this->command->getDefinition();

        foreach (['search', 'status', 'unused', 'usage', 'field-filter', 'limit', 'name'] as $option) {
            $this->assertTrue($definition->hasOption($option));
        }

        $this->assertStringContainsString('show', $definition->getArgument('action')->getDescription());
    }

    public function testHelpDocumentation(): void
    {
        $help = $this->command->getHelp();

        foreach (['list', 'show <id>', 'create', 'delete', 'update', 'enable', 'disable', 'merge', 'stats'] as $text) {
            $this->assertStringContainsString($text, $help);
        }

        $this->assertStringContainsString('EXAMPLES', $help);
        $this->assertStringContainsString('kvs tag', $help);
    }

    public function testListWithoutFilters(): void
    {
        $exitCode = $this->tester->execute(['action' => 'list']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Tag id', $output);
        $this->assertStringContainsString('Tag', $output);
        $this->assertStringContainsString('4K', $output);
        $this->assertStringContainsString('unused', $output);
    }

    public function testListWithLimit(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => '2',
            '--format' => 'json',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(2, $rows);
    }

    public function testListCountIgnoresLimitAndKeepsFilters(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => 'count',
            '--limit' => '1',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('4', trim($this->tester->getDisplay()));

        $activeTester = new CommandTester($this->command);
        $activeTester->execute([
            'action' => 'list',
            '--status' => 'active',
            '--format' => 'count',
            '--limit' => '1',
        ]);

        $this->assertEquals(0, $activeTester->getStatusCode());
        $this->assertSame('3', trim($activeTester->getDisplay()));

        $unusedTester = new CommandTester($this->command);
        $unusedTester->execute([
            'action' => 'list',
            '--unused' => true,
            '--format' => 'count',
            '--limit' => '1',
        ]);

        $this->assertEquals(0, $unusedTester->getStatusCode());
        $this->assertSame('1', trim($unusedTester->getDisplay()));
    }

    public function testListWithSearch(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => '4K',
            '--format' => 'json',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(10, (int) $rows[0]['tag_id']);
        $this->assertSame('4K', $rows[0]['tag']);
    }

    public function testListWithSearchMatchesDirectoryAndSynonymsLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'ultra hd',
            '--fields' => 'tag_id,tag_dir,synonyms',
            '--format' => 'json',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(10, (int) $rows[0]['tag_id']);
        $this->assertSame('4k', $rows[0]['tag_dir']);
        $this->assertSame('uhd, ultra hd', $rows[0]['synonyms']);

        $this->db->exec(
            'INSERT INTO ' . TestHelper::table('tags') .
            " (tag_id, tag, tag_dir, synonyms, status_id) VALUES (50, 'Slug Target', 'directory-only', '', 1)"
        );

        $slugTester = new CommandTester($this->command);
        $slugTester->execute([
            'action' => 'list',
            '--search' => 'directory-only',
            '--format' => 'count',
        ]);

        $this->assertEquals(0, $slugTester->getStatusCode());
        $this->assertSame('1', trim($slugTester->getDisplay()));
    }

    public function testListFiltersByKvsAdminFieldFilter(): void
    {
        $cases = [
            'filled/synonyms' => [10],
            'empty/synonyms' => [40, 30, 20],
            'filled/custom1' => [10],
            'empty/custom1' => [40, 30, 20],
        ];

        foreach ($cases as $filter => $expectedIds) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                '--field-filter' => $filter,
                '--format' => 'json',
                '--fields' => 'tag_id',
                '--limit' => 10,
            ]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($expectedIds, array_map(static fn (array $row): int => (int) $row['tag_id'], $rows), $filter);
        }
    }

    public function testListFiltersByKvsAdminUsageBuckets(): void
    {
        $cases = [
            'used/videos' => [40, 10],
            'notused/videos' => [30, 20],
            'used/albums' => [10],
            'notused/albums' => [40, 30, 20],
            'used/posts' => [30],
            'notused/posts' => [40, 20, 10],
            'used/other' => [10],
            'notused/other' => [40, 30, 20],
            'used/all' => [40, 30, 10],
            'notused/all' => [20],
        ];

        foreach ($cases as $usage => $expectedIds) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                '--usage' => $usage,
                '--format' => 'json',
                '--fields' => 'tag_id',
                '--limit' => 10,
            ]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($expectedIds, array_map(static fn (array $row): int => (int) $row['tag_id'], $rows), $usage);
        }
    }

    public function testListExposesKvsAdminRenameField(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => '4K',
            '--format' => 'json',
            '--fields' => 'tag_id,tag,tag_rename',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame(10, (int) $rows[0]['tag_id']);
        $this->assertSame('4K', $rows[0]['tag']);
        $this->assertSame('4K', $rows[0]['tag_rename']);
    }

    public function testListWithStatusFilter(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--status' => 'inactive',
            '--format' => 'json',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(30, (int) $rows[0]['tag_id']);
        $this->assertSame('Inactive', $rows[0]['status']);
    }

    public function testListUnusedTags(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--unused' => true,
            '--format' => 'json',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(20, (int) $rows[0]['tag_id']);
        $this->assertSame('unused', $rows[0]['tag']);
        $this->assertSame(0, (int) $rows[0]['total_usage']);
    }

    public function testListCountsEveryTagRelationTable(): void
    {
        $this->db->exec('INSERT INTO ' . TestHelper::table('tags_posts') . ' (tag_id, post_id) VALUES (10, 300)');
        $this->db->exec('INSERT INTO ' . TestHelper::table('tags_playlists') . ' (tag_id, playlist_id) VALUES (10, 400)');
        $this->db->exec(
            'INSERT INTO ' . TestHelper::table('tags_content_sources') .
            ' (tag_id, content_source_id) VALUES (10, 500)'
        );
        $this->db->exec('INSERT INTO ' . TestHelper::table('tags_models') . ' (tag_id, model_id) VALUES (10, 600)');
        $this->db->exec('INSERT INTO ' . TestHelper::table('tags_dvds') . ' (tag_id, dvd_id) VALUES (10, 700)');
        $this->db->exec(
            'INSERT INTO ' . TestHelper::table('tags_dvds_groups') .
            ' (tag_id, dvd_group_id) VALUES (10, 800)'
        );

        $this->tester->execute([
            'action' => 'list',
            '--search' => '4K',
            '--fields' => 'tag_id,videos,albums,posts,playlists,content_sources,models,dvds,dvds_groups,total_usage',
            '--format' => 'json',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame(10, (int) $rows[0]['tag_id']);
        $this->assertSame(2, (int) $rows[0]['videos']);
        $this->assertSame(1, (int) $rows[0]['albums']);
        $this->assertSame(1, (int) $rows[0]['posts']);
        $this->assertSame(1, (int) $rows[0]['playlists']);
        $this->assertSame(1, (int) $rows[0]['content_sources']);
        $this->assertSame(1, (int) $rows[0]['models']);
        $this->assertSame(1, (int) $rows[0]['dvds']);
        $this->assertSame(1, (int) $rows[0]['dvds_groups']);
        $this->assertSame(14, (int) $rows[0]['total_usage']);
    }

    public function testListExposesKvsAdminCountFields(): void
    {
        $this->db->exec('INSERT INTO ' . TestHelper::table('tags_posts') . ' (tag_id, post_id) VALUES (10, 300)');

        $this->tester->execute([
            'action' => 'list',
            '--search' => '4K',
            '--fields' => 'tag_id,tag,videos_amount,albums_amount,posts_amount,other_amount,all_amount',
            '--format' => 'json',
            '--limit' => '1',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(10, (int) $rows[0]['tag_id']);
        $this->assertSame('4K', $rows[0]['tag']);
        $this->assertSame(2, (int) $rows[0]['videos_amount']);
        $this->assertSame(1, (int) $rows[0]['albums_amount']);
        $this->assertSame(1, (int) $rows[0]['posts_amount']);
        $this->assertSame(10, (int) $rows[0]['other_amount']);
        $this->assertSame(14, (int) $rows[0]['all_amount']);
    }

    public function testListExposesKvsAdminRawScalarAndAverageFields(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => '4K',
            '--fields' => 'tag_id,tag_dir,synonyms,avg_videos_rating,avg_videos_popularity,' .
                'avg_albums_rating,avg_albums_popularity,avg_posts_rating,avg_posts_popularity,added_date',
            '--format' => 'json',
            '--limit' => '1',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(10, (int) $rows[0]['tag_id']);
        $this->assertSame('4k', $rows[0]['tag_dir']);
        $this->assertSame('uhd, ultra hd', $rows[0]['synonyms']);
        $this->assertSame(4.5, (float) $rows[0]['avg_videos_rating']);
        $this->assertSame(1200, (int) $rows[0]['avg_videos_popularity']);
        $this->assertSame(4.25, (float) $rows[0]['avg_albums_rating']);
        $this->assertSame(900, (int) $rows[0]['avg_albums_popularity']);
        $this->assertSame(3.75, (float) $rows[0]['avg_posts_rating']);
        $this->assertSame(300, (int) $rows[0]['avg_posts_popularity']);
        $this->assertSame('2026-05-26 10:00:00', $rows[0]['added_date']);
    }

    public function testShowTagDetails(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'identifier' => '10',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Tag: 4K', $output);
        $this->assertStringContainsString('4k', $output);
        $this->assertMatchesRegularExpression('/Videos\W+2/', $output);
        $this->assertMatchesRegularExpression('/Albums\W+1/', $output);
        $this->assertMatchesRegularExpression('/Total Usage\W+13/', $output);
    }

    public function testShowTagSupportsJsonFormat(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'identifier' => '10',
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();
        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('10', $rows[0]['id']);
        $this->assertSame('4K', $rows[0]['name']);
        $this->assertSame('2', $rows[0]['videos']);
        $this->assertSame('13', $rows[0]['total_usage']);
        $this->assertStringNotContainsString('Tag: 4K', $output);
    }

    public function testShowTagSupportsExactName(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'identifier' => '4K',
            '--format' => 'json',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('10', $rows[0]['id']);
        $this->assertSame('4K', $rows[0]['name']);
    }

    public function testShowTagReportsMissingTextIdentifier(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'identifier' => '10abc',
            '--format' => 'json',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Tag not found: 10abc', $this->tester->getDisplay());
    }

    public function testUpdateTagRejectsNonIntegerIdBeforeQuery(): void
    {
        $this->tester->execute([
            'action' => 'update',
            'identifier' => '10abc',
            '--name' => 'Renamed',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Invalid Tag ID', $this->tester->getDisplay());
    }

    public function testCreateWithoutName(): void
    {
        $exitCode = $this->tester->execute(['action' => 'create']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Tag name is required', $output);
    }

    public function testCreateValidationExists(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'create',
            'identifier' => '4K',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Tag already exists: 4K', $output);
    }

    public function testUpdateUsesUniqueTagDirectoryLikeKvsAdmin(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'update',
            'identifier' => '20',
            '--name' => '4K!',
        ]);

        $this->assertEquals(0, $exitCode);
        $this->assertSame(
            '4k2',
            $this->db->query('SELECT tag_dir FROM ' . TestHelper::table('tags') . ' WHERE tag_id = 20')
                ->fetchColumn()
        );
    }

    public function testUpdateRejectsInvalidStatusWithoutChangingTag(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'update',
            'identifier' => '10',
            '--status' => 'bogus',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Invalid status "bogus"', $output);
        $this->assertSame(
            1,
            (int) $this->db->query('SELECT status_id FROM ' . TestHelper::table('tags') . ' WHERE tag_id = 10')
                ->fetchColumn()
        );
    }

    public function testUpdateWithoutChanges(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'update',
            'identifier' => '10',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('No changes specified', $output);
    }

    public function testUpdateRequiresId(): void
    {
        $exitCode = $this->tester->execute(['action' => 'update']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Tag ID is required', $output);
    }

    public function testEnableRequiresId(): void
    {
        $exitCode = $this->tester->execute(['action' => 'enable']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Tag ID is required', $output);
    }

    public function testDisableRequiresId(): void
    {
        $exitCode = $this->tester->execute(['action' => 'disable']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Tag ID is required', $output);
    }

    public function testEnableNonExistentTag(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'enable',
            'identifier' => '99999',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Tag not found: 99999', $output);
    }

    public function testDeleteRequiresId(): void
    {
        $exitCode = $this->tester->execute(['action' => 'delete']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Tag ID is required', $output);
    }

    public function testDeleteNonExistentTag(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'delete',
            'identifier' => '99999',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Tag not found: 99999', $output);
    }

    public function testMergeRequiresBothIds(): void
    {
        $exitCode = $this->tester->execute(['action' => 'merge']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Both source and target tag IDs are required', $output);
    }

    public function testMergeSameIdRejected(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'merge',
            'identifier' => '10',
            'target' => '10',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Source and target tags must be different', $output);
    }

    public function testMergeNonExistentSource(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'merge',
            'identifier' => '99999',
            'target' => '10',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('One or both tags not found', $output);
    }

    public function testMergeNonExistentTarget(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'merge',
            'identifier' => '10',
            'target' => '99999',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('One or both tags not found', $output);
    }

    public function testStatsCommand(): void
    {
        $exitCode = $this->tester->execute(['action' => 'stats']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Tag Statistics', $output);
        $this->assertMatchesRegularExpression('/Total Tags\W+4/', $output);
        $this->assertMatchesRegularExpression('/Active Tags\W+3/', $output);
        $this->assertMatchesRegularExpression('/Inactive Tags\W+1/', $output);
        $this->assertMatchesRegularExpression('/Used Tags\W+3/', $output);
        $this->assertMatchesRegularExpression('/Unused Tags\W+1/', $output);
    }

    public function testStatsShowsTop10(): void
    {
        $this->tester->execute(['action' => 'stats']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Top 10 Most Used Tags', $output);
        $this->assertStringContainsString('4K', $output);
        $this->assertStringContainsString('tagged', $output);
        $this->assertMatchesRegularExpression('/4K\W+2\W+1\W+10\W+13/', $output);
    }

    public function testInvalidAction(): void
    {
        $exitCode = $this->tester->execute(['action' => 'invalid_action']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Unknown tag action "invalid_action"', $output);
    }

    public function testNonNumericIdHandling(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'delete',
            'identifier' => 'not_a_number',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Tag ID must be numeric', $output);
    }

    public function testListOutputFormat(): void
    {
        $this->tester->execute(['action' => 'list', '--limit' => '5']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Tag id', $output);
        $this->assertStringContainsString('Tag', $output);
        $this->assertStringContainsString('Video count', $output);
        $this->assertStringContainsString('Album count', $output);
        $this->assertStringContainsString('Total usage', $output);
        $this->assertStringContainsString('Status', $output);
    }

    public function testStatsOutputFormat(): void
    {
        $this->tester->execute(['action' => 'stats']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Tag Statistics', $output);
        $this->assertStringContainsString('Overall Statistics', $output);
        $this->assertStringContainsString('Top 10 Most Used Tags', $output);
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
        $this->assertSame('overall', $rowsByMetric['Total Tags']['section'] ?? null);
        $this->assertSame(4, (int) ($rowsByMetric['Total Tags']['value'] ?? 0));
        $this->assertStringNotContainsString('Tag Statistics', $this->tester->getDisplay());
    }

    public function testCommandIntegrationWithHermeticDb(): void
    {
        $exitCode = $this->tester->execute(['action' => 'list', '--limit' => '1']);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Total: 1 result', $this->tester->getDisplay());
    }

    public function testAllActionsAreAccessible(): void
    {
        $actions = ['list', 'show', 'create', 'delete', 'update', 'enable', 'disable', 'merge', 'stats'];
        $help = $this->command->getHelp();

        foreach ($actions as $action) {
            $this->assertStringContainsString($action, strtolower($help));
        }
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        $db->exec(
            'CREATE TABLE ' . TestHelper::table('tags') . ' (' .
            'tag_id INTEGER, tag TEXT, tag_dir TEXT, synonyms TEXT, custom1 TEXT, custom2 TEXT, ' .
            'custom3 TEXT, custom4 TEXT, custom5 TEXT, status_id INTEGER, ' .
            'added_date TEXT, last_content_date TEXT, total_content_sources INTEGER, total_playlists INTEGER, ' .
            'total_models INTEGER, total_dvds INTEGER, total_dvd_groups INTEGER, ' .
            'avg_videos_rating REAL, avg_videos_popularity INTEGER, avg_albums_rating REAL, avg_albums_popularity INTEGER, ' .
            'avg_posts_rating REAL, avg_posts_popularity INTEGER)'
        );

        foreach ($this->relationTables() as $suffix => $objectColumn) {
            $db->exec(
                'CREATE TABLE ' . TestHelper::table('tags_' . $suffix) . ' (' .
                'tag_id INTEGER, ' . $objectColumn . ' INTEGER)'
            );
        }

        $db->exec(
            'INSERT INTO ' . TestHelper::table('tags') .
            ' (tag_id, tag, tag_dir, synonyms, custom1, custom2, custom3, custom4, custom5, status_id, ' .
            'added_date, last_content_date, ' .
            'total_content_sources, total_playlists, total_models, total_dvds, total_dvd_groups, ' .
            'avg_videos_rating, avg_videos_popularity, avg_albums_rating, avg_albums_popularity, ' .
            'avg_posts_rating, avg_posts_popularity) VALUES ' .
            "(10, '4K', '4k', 'uhd, ultra hd', 'featured', '', '', '', '', 1, " .
            "'2026-05-26 10:00:00', '2026-05-26 11:00:00', 1, 2, 3, 4, 0, 4.5, 1200, 4.25, 900, 3.75, 300), " .
            "(20, 'unused', 'unused', '', '', '', '', '', '', 1, " .
            "'2026-05-26 09:00:00', '2026-05-26 10:00:00', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0), " .
            "(30, 'archived', 'archived', '', '', '', '', '', '', 0, " .
            "'2026-05-26 08:00:00', '2026-05-26 09:00:00', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0), " .
            "(40, 'tagged', 'tagged', '', '', '', '', '', '', 1, " .
            "'2026-05-26 07:00:00', '2026-05-26 08:00:00', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0)"
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('tags_videos') .
            ' (tag_id, video_id) VALUES (10, 100), (10, 101), (40, 102)'
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('tags_albums') .
            ' (tag_id, album_id) VALUES (10, 200)'
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('tags_posts') .
            ' (tag_id, post_id) VALUES (30, 300)'
        );

        return $db;
    }

    /**
     * @return array<string, string>
     */
    private function relationTables(): array
    {
        return [
            'videos' => 'video_id',
            'albums' => 'album_id',
            'posts' => 'post_id',
            'playlists' => 'playlist_id',
            'content_sources' => 'content_source_id',
            'models' => 'model_id',
            'dvds' => 'dvd_id',
            'dvds_groups' => 'dvd_group_id',
        ];
    }

    private function createCommand(PDO $db): TagCommand
    {
        return new class ($this->config, $db) extends TagCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:tag');
                $this->setDescription('Manage KVS tags');
                $this->setAliases(['tag', 'tags']);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
    }
}
