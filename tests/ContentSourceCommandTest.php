<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\ContentSourceCommand;
use KVS\CLI\Command\Content\ContentSourceGroupCommand;
use KVS\CLI\Config\Configuration;
use KVS\CLI\Constants;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ContentSourceCommand::class)]
#[CoversClass(ContentSourceGroupCommand::class)]
class ContentSourceCommandTest extends TestCase
{
    private string $kvsPath;
    private string $sourceFilesPath;
    private Configuration $config;
    private PDO $db;
    private ContentSourceCommand $sourceCommand;
    private ContentSourceGroupCommand $groupCommand;
    private CommandTester $sourceTester;
    private CommandTester $groupTester;

    protected function setUp(): void
    {
        $this->kvsPath = TestHelper::createTempDir('kvs-content-source-');
        $this->sourceFilesPath = $this->kvsPath . '/contents/content_sources';

        TestHelper::createMockKvsInstallation($this->kvsPath, [
            'project_path' => $this->kvsPath,
            'tables_prefix' => TestHelper::getTablePrefix(),
            'tables_prefix_multi' => TestHelper::getTablePrefix(),
            'php_path' => PHP_BINARY,
            'content_path_content_sources' => $this->sourceFilesPath,
            'content_url_content_sources' => 'https://cdn.example.test/content_sources',
            'image_allowed_ext' => 'jpg,jpeg,png,gif,webp',
        ]);
        mkdir($this->sourceFilesPath, 0777, true);

        $this->db = $this->createDatabase();
        $this->config = TestHelper::createTestConfiguration($this->kvsPath);
        $this->sourceCommand = $this->createSourceCommand($this->db);
        $this->groupCommand = $this->createGroupCommand($this->db);
        $this->sourceTester = new CommandTester($this->sourceCommand);
        $this->groupTester = new CommandTester($this->groupCommand);
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->kvsPath);
    }

    public function testCommandMetadata(): void
    {
        $sourceCommand = new ContentSourceCommand($this->config);
        $this->assertSame('content:content-source', $sourceCommand->getName());
        $this->assertContains('source', $sourceCommand->getAliases());
        $this->assertContains('sites', $sourceCommand->getAliases());

        $groupCommand = new ContentSourceGroupCommand($this->config);
        $this->assertSame('content:content-source-group', $groupCommand->getName());
        $this->assertContains('source-group', $groupCommand->getAliases());
        $this->assertContains('csgroup', $groupCommand->getAliases());
    }

    public function testContentSourceCommandHasAdminParityOptions(): void
    {
        $definition = $this->sourceCommand->getDefinition();

        foreach (
            [
                'title',
                'description',
                'url',
                'synonyms',
                'status',
                'group',
                'content-source-group',
                'dir',
                'sort',
                'rating',
                'rating-amount',
                'screenshot1',
                'screenshot2',
                'custom-file10',
                'tags',
                'categories',
                'tag',
                'category',
                'used',
                'unused',
                'usage',
                'field-filter',
                'sort-by',
                'sort-dir',
                'format',
            ] as $option
        ) {
            $this->assertTrue($definition->hasOption($option), "missing option: $option");
        }
    }

    public function testListSupportsAdminFiltersAndComputedFields(): void
    {
        $exitCode = $this->sourceTester->execute([
            'action' => 'list',
            '--format' => 'json',
            '--fields' => 'content_source_id,title,content_source_group,videos_amount,albums_amount,all_amount,status,rating,thumb',
            '--sort-by' => 'content_source_id',
            '--sort-dir' => 'asc',
            '--limit' => '10',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $rows = $this->decodeJsonRows($this->sourceTester->getDisplay());
        $this->assertCount(2, $rows);
        $this->assertSame('Alpha Source', $rows[0]['title']);
        $this->assertSame('Studios', $rows[0]['content_source_group']);
        $this->assertSame(1, (int) $rows[0]['videos_amount']);
        $this->assertSame(1, (int) $rows[0]['albums_amount']);
        $this->assertSame(2, (int) $rows[0]['all_amount']);
        $this->assertSame('Active', $rows[0]['status']);
        $this->assertSame(4.0, (float) $rows[0]['rating']);
        $this->assertSame('https://cdn.example.test/content_sources/1/s1_alpha.png', $rows[0]['thumb']);

        $this->sourceTester->execute([
            'action' => 'list',
            '--usage' => 'notused/all',
            '--format' => 'ids',
            '--limit' => '10',
        ]);
        $this->assertSame('2', trim($this->sourceTester->getDisplay()));

        $this->sourceTester->execute([
            'action' => 'list',
            '--field-filter' => 'filled/tags',
            '--format' => 'ids',
            '--limit' => '10',
        ]);
        $this->assertSame('1', trim($this->sourceTester->getDisplay()));

        $this->sourceTester->execute([
            'action' => 'list',
            '--group' => 'Studios',
            '--format' => 'ids',
            '--limit' => '10',
        ]);
        $this->assertSame('1', trim($this->sourceTester->getDisplay()));
    }

    public function testContentSourceGroupCrudDetachesSourcesOnDelete(): void
    {
        $exitCode = $this->groupTester->execute([
            'action' => 'create',
            '--title' => 'Partner Sites',
            '--external-id' => 'partners',
            '--custom5' => 'priority',
            '--status' => 'inactive',
            '--sort' => '7',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $group = $this->fetchGroupByTitle('Partner Sites');
        $this->assertNotNull($group);
        $this->assertSame(0, (int) $group['status_id']);
        $this->assertSame('partners', $group['external_id']);
        $this->assertSame('priority', $group['custom5']);
        $this->assertAuditLogExists(100, (int) $group['content_source_group_id'], Constants::OBJECT_TYPE_CONTENT_SOURCE_GROUP, '');

        $this->groupTester->execute([
            'action' => 'list',
            '--usage' => 'used/content_sources',
            '--format' => 'ids',
            '--limit' => '10',
        ]);
        $this->assertSame('1', trim($this->groupTester->getDisplay()));

        $exitCode = $this->groupTester->execute([
            'action' => 'update',
            'id' => '2',
            '--title' => 'Empty Renamed',
            '--external-id' => '',
            '--custom1' => 'archived',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $updated = $this->fetchGroupByTitle('Empty Renamed');
        $this->assertNotNull($updated);
        $this->assertSame('', $updated['external_id']);
        $this->assertSame('archived', $updated['custom1']);

        $this->groupTester->setInputs(['yes']);
        $exitCode = $this->groupTester->execute(['action' => 'delete', 'id' => '1']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertNull($this->fetchGroupByTitle('Studios'));
        $this->assertSame(0, (int) $this->db->query(
            'SELECT content_source_group_id FROM ' . TestHelper::table('content_sources') . ' WHERE content_source_id = 1'
        )->fetchColumn());
    }

    public function testCreateSourceUploadsFilesAndCreatesMissingRelations(): void
    {
        $image = $this->createTinyPng('source image.png');
        $customFile = $this->kvsPath . '/source-notes.txt';
        file_put_contents($customFile, 'source notes');

        $exitCode = $this->sourceTester->execute([
            'action' => 'create',
            'id' => 'Gamma Source',
            '--url' => 'https://gamma.example.test/path',
            '--group' => 'Fresh Group',
            '--screenshot1' => $image,
            '--custom-file1' => $customFile,
            '--tags' => 'Existing Tag, Fresh Tag',
            '--categories' => 'Existing Category, Fresh Category',
            '--rating' => '7.5',
            '--rating-amount' => '4',
            '--custom1' => 'spotlight',
            '--status' => 'active',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode, $this->sourceTester->getDisplay());
        $source = $this->fetchSourceByTitle('Gamma Source');
        $this->assertNotNull($source);
        $sourceId = (int) $source['content_source_id'];
        $this->assertSame('gamma-source', $source['dir']);
        $this->assertSame(30, (int) $source['rating']);
        $this->assertSame(4, (int) $source['rating_amount']);
        $this->assertSame('s1_source-image.png', $source['screenshot1']);
        $this->assertSame('s2_source-image.png', $source['screenshot2']);
        $this->assertSame('c1_source-notes.txt', $source['custom_file1']);
        $this->assertFileExists($this->sourceFilesPath . "/{$sourceId}/s1_source-image.png");
        $this->assertFileExists($this->sourceFilesPath . "/{$sourceId}/s2_source-image.png");
        $this->assertFileExists($this->sourceFilesPath . "/{$sourceId}/c1_source-notes.txt");

        $group = $this->fetchGroupByTitle('Fresh Group');
        $this->assertNotNull($group);
        $this->assertSame((int) $group['content_source_group_id'], (int) $source['content_source_group_id']);
        $this->assertSame(2, $this->countRelations('tags_content_sources', $sourceId));
        $this->assertSame(2, $this->countRelations('categories_content_sources', $sourceId));
        $this->assertSame(2, $this->getTagTotal('Existing Tag'));
        $this->assertSame(2, $this->getCategoryTotal('Existing Category'));

        $exitCode = $this->sourceTester->execute([
            'action' => 'show',
            'id' => (string) $sourceId,
            '--format' => 'json',
            '--fields' => 'content_source_id,title,content_source_group,tags,categories,screenshot1_url,screenshot2_url,custom_file1_url',
        ]);
        $this->assertSame(Command::SUCCESS, $exitCode);
        $rows = $this->decodeJsonRows($this->sourceTester->getDisplay());
        $this->assertSame('Gamma Source', $rows[0]['title']);
        $this->assertSame('Fresh Group', $rows[0]['content_source_group']);
        $this->assertStringContainsString('Fresh Tag', $rows[0]['tags']);
        $this->assertStringContainsString('Fresh Category', $rows[0]['categories']);
        $this->assertSame(
            "https://cdn.example.test/content_sources/{$sourceId}/s1_source-image.png",
            $rows[0]['screenshot1_url']
        );
        $this->assertSame(
            "https://cdn.example.test/content_sources/{$sourceId}/c1_source-notes.txt",
            $rows[0]['custom_file1_url']
        );
    }

    public function testUpdateSourceReplacesFilesAndPreservesAverageRatingWhenAmountChanges(): void
    {
        $newImage = $this->createTinyPng('alpha replacement.png');

        $exitCode = $this->sourceTester->execute([
            'action' => 'update',
            'id' => '1',
            '--screenshot1' => $newImage,
            '--screenshot2' => '',
            '--custom-file1' => '',
            '--rating-amount' => '20',
            '--custom1' => '',
            '--tags' => 'Replacement Tag',
            '--categories' => '',
            '--status' => 'disabled',
            '--group' => '0',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode, $this->sourceTester->getDisplay());
        $source = $this->fetchSourceByTitle('Alpha Source');
        $this->assertNotNull($source);
        $this->assertSame(0, (int) $source['status_id']);
        $this->assertSame(0, (int) $source['content_source_group_id']);
        $this->assertSame(80, (int) $source['rating']);
        $this->assertSame(20, (int) $source['rating_amount']);
        $this->assertSame('', $source['custom1']);
        $this->assertSame('s1_alpha-replacement.png', $source['screenshot1']);
        $this->assertSame('', $source['screenshot2']);
        $this->assertSame('', $source['custom_file1']);
        $this->assertFileExists($this->sourceFilesPath . '/1/s1_alpha-replacement.png');
        $this->assertFileDoesNotExist($this->sourceFilesPath . '/1/s1_alpha.png');
        $this->assertFileDoesNotExist($this->sourceFilesPath . '/1/c1_old.txt');
        $this->assertSame(1, $this->countRelations('tags_content_sources', 1));
        $this->assertSame(0, $this->countRelations('categories_content_sources', 1));
        $auditDetails = $this->fetchAuditDetails(150, 1, Constants::OBJECT_TYPE_CONTENT_SOURCE);
        $this->assertNotNull($auditDetails);
        foreach (['status_id', 'content_source_group_id', 'rating', 'screenshot1', 'custom_file1', 'tags'] as $field) {
            $this->assertStringContainsString($field, $auditDetails);
        }
    }

    public function testDeleteSourceDetachesContentAndRemovesRelations(): void
    {
        $this->sourceTester->setInputs(['yes']);
        $exitCode = $this->sourceTester->execute(['action' => 'delete', 'id' => '1']);

        $this->assertSame(Command::SUCCESS, $exitCode, $this->sourceTester->getDisplay());
        $this->assertNull($this->fetchSourceByTitle('Alpha Source'));
        $this->assertSame(0, (int) $this->db->query(
            'SELECT content_source_id FROM ' . TestHelper::table('videos') . ' WHERE video_id = 1'
        )->fetchColumn());
        $this->assertSame(0, (int) $this->db->query(
            'SELECT content_source_id FROM ' . TestHelper::table('albums') . ' WHERE album_id = 1'
        )->fetchColumn());
        $this->assertSame(0, $this->countTableRows('comments'));
        $this->assertSame(0, $this->countTableRows('users_subscriptions'));
        $this->assertSame(0, $this->countTableRows('users_events'));
        $this->assertSame(0, $this->countRelations('tags_content_sources', 1));
        $this->assertSame(0, $this->countRelations('categories_content_sources', 1));
        $this->assertSame(0, $this->getTagTotal('Existing Tag'));
        $this->assertSame(0, $this->getCategoryTotal('Existing Category'));
        $this->assertSame(0, (int) $this->db->query(
            'SELECT comments_cs_count FROM ' . TestHelper::table('users') . ' WHERE user_id = 5'
        )->fetchColumn());
        $this->assertDirectoryDoesNotExist($this->sourceFilesPath . '/1');
        $this->assertAuditLogExists(180, 1, Constants::OBJECT_TYPE_CONTENT_SOURCE, '');
    }

    public function testValidationRejectsInvalidSourceInput(): void
    {
        $exitCode = $this->sourceTester->execute(['action' => 'create', 'id' => 'Alpha Source']);
        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Content source already exists', $this->sourceTester->getDisplay());

        $exitCode = $this->sourceTester->execute([
            'action' => 'create',
            'id' => 'Invalid URL Source',
            '--url' => 'not a url',
        ]);
        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertNull($this->fetchSourceByTitle('Invalid URL Source'));

        $exitCode = $this->sourceTester->execute([
            'action' => 'update',
            'id' => '1abc',
            '--title' => 'Should Not Update',
        ]);
        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Invalid Content source ID', $this->sourceTester->getDisplay());
        $this->assertNull($this->fetchSourceByTitle('Should Not Update'));

        $exitCode = $this->sourceTester->execute([
            'action' => 'update',
            'id' => '1',
            '--rating' => '11',
        ]);
        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertSame(40, (int) $this->db->query(
            'SELECT rating FROM ' . TestHelper::table('content_sources') . ' WHERE content_source_id = 1'
        )->fetchColumn());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchSourceByTitle(string $title): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM ' . TestHelper::table('content_sources') . ' WHERE title = :title'
        );
        $stmt->execute(['title' => $title]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchGroupByTitle(string $title): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM ' . TestHelper::table('content_sources_groups') . ' WHERE title = :title'
        );
        $stmt->execute(['title' => $title]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function countRelations(string $table, int $sourceId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM ' . TestHelper::table($table) . ' WHERE content_source_id = :id'
        );
        $stmt->execute(['id' => $sourceId]);

        return (int) $stmt->fetchColumn();
    }

    private function countTableRows(string $table): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM ' . TestHelper::table($table))->fetchColumn();
    }

    private function getTagTotal(string $tag): int
    {
        $stmt = $this->db->prepare(
            'SELECT total_content_sources FROM ' . TestHelper::table('tags') . ' WHERE tag = :tag'
        );
        $stmt->execute(['tag' => $tag]);

        return (int) $stmt->fetchColumn();
    }

    private function getCategoryTotal(string $title): int
    {
        $stmt = $this->db->prepare(
            'SELECT total_content_sources FROM ' . TestHelper::table('categories') . ' WHERE title = :title'
        );
        $stmt->execute(['title' => $title]);

        return (int) $stmt->fetchColumn();
    }

    private function assertAuditLogExists(int $actionId, int $objectId, int $objectTypeId, string $details): void
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM ' . TestHelper::table('admin_audit_log') .
            ' WHERE username = :username AND action_id = :action_id AND object_id = :object_id' .
            ' AND object_type_id = :object_type_id AND action_details = :details'
        );
        $stmt->execute([
            'username' => 'kvs-cli',
            'action_id' => $actionId,
            'object_id' => $objectId,
            'object_type_id' => $objectTypeId,
            'details' => $details,
        ]);

        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    private function fetchAuditDetails(int $actionId, int $objectId, int $objectTypeId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT action_details FROM ' . TestHelper::table('admin_audit_log') .
            ' WHERE username = :username AND action_id = :action_id AND object_id = :object_id' .
            ' AND object_type_id = :object_type_id'
        );
        $stmt->execute([
            'username' => 'kvs-cli',
            'action_id' => $actionId,
            'object_id' => $objectId,
            'object_type_id' => $objectTypeId,
        ]);
        $details = $stmt->fetchColumn();

        return is_scalar($details) ? (string) $details : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeJsonRows(string $output): array
    {
        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($rows);

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    private function createTinyPng(string $filename): string
    {
        $path = $this->kvsPath . '/' . $filename;
        file_put_contents(
            $path,
            base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
                true
            ) ?: ''
        );

        return $path;
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        $this->createSchema($db);
        $this->seedData($db);

        return $db;
    }

    private function createSchema(PDO $db): void
    {
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('content_sources_groups') . ' (' .
            'content_source_group_id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, dir TEXT, ' .
            'description TEXT DEFAULT "", status_id INTEGER DEFAULT 1, external_id TEXT DEFAULT "", ' .
            'custom1 TEXT DEFAULT "", custom2 TEXT DEFAULT "", custom3 TEXT DEFAULT "", ' .
            'custom4 TEXT DEFAULT "", custom5 TEXT DEFAULT "", sort_id INTEGER DEFAULT 0, added_date TEXT)'
        );

        $sourceColumns = [
            'content_source_id INTEGER PRIMARY KEY AUTOINCREMENT',
            'content_source_group_id INTEGER DEFAULT 0',
            'title TEXT',
            'dir TEXT',
            'description TEXT DEFAULT ""',
            'synonyms TEXT DEFAULT ""',
            'status_id INTEGER DEFAULT 1',
            'screenshot1 TEXT DEFAULT ""',
            'screenshot2 TEXT DEFAULT ""',
            'url TEXT DEFAULT ""',
            'rating INTEGER DEFAULT 0',
            'rating_amount INTEGER DEFAULT 1',
            'sort_id INTEGER DEFAULT 0',
            'added_date TEXT',
            'last_content_date TEXT DEFAULT ""',
            'cs_viewed INTEGER DEFAULT 0',
            'comments_count INTEGER DEFAULT 0',
            'subscribers_count INTEGER DEFAULT 0',
            'rank INTEGER DEFAULT 0',
            'last_rank INTEGER DEFAULT 0',
        ];
        for ($i = 1; $i <= 10; $i++) {
            $sourceColumns[] = "custom{$i} TEXT DEFAULT \"\"";
        }
        for ($i = 1; $i <= 10; $i++) {
            $sourceColumns[] = "custom_file{$i} TEXT DEFAULT \"\"";
        }
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('content_sources') . ' (' .
            implode(', ', $sourceColumns) .
            ')'
        );

        $db->exec(
            'CREATE TABLE ' . TestHelper::table('tags') . ' (' .
            'tag_id INTEGER PRIMARY KEY AUTOINCREMENT, tag TEXT, tag_dir TEXT, status_id INTEGER, ' .
            'added_date TEXT, total_content_sources INTEGER DEFAULT 0)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('categories') . ' (' .
            'category_id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, dir TEXT, status_id INTEGER, ' .
            'category_group_id INTEGER DEFAULT 0, added_date TEXT, total_content_sources INTEGER DEFAULT 0)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('tags_content_sources') . ' (' .
            'id INTEGER PRIMARY KEY AUTOINCREMENT, tag_id INTEGER, content_source_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('categories_content_sources') . ' (' .
            'id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER, content_source_id INTEGER)'
        );
        $db->exec('CREATE TABLE ' . TestHelper::table('videos') . ' (video_id INTEGER PRIMARY KEY, content_source_id INTEGER)');
        $db->exec('CREATE TABLE ' . TestHelper::table('albums') . ' (album_id INTEGER PRIMARY KEY, content_source_id INTEGER)');
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('comments') . ' (' .
            'comment_id INTEGER PRIMARY KEY, object_type_id INTEGER, object_id INTEGER, user_id INTEGER, is_approved INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('users_subscriptions') . ' (' .
            'user_id INTEGER, subscribed_object_id INTEGER, subscribed_object_type_id INTEGER)'
        );
        $db->exec('CREATE TABLE ' . TestHelper::table('users_events') . ' (event_id INTEGER PRIMARY KEY, content_source_id INTEGER)');
        $db->exec('CREATE TABLE ' . TestHelper::table('videos_feeds_import') . ' (videos_content_source_id INTEGER)');
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('users') . ' (' .
            'user_id INTEGER PRIMARY KEY, comments_cs_count INTEGER, comments_total_count INTEGER)'
        );
        $db->exec('CREATE TABLE ' . TestHelper::table('options') . ' (variable TEXT PRIMARY KEY, value TEXT)');
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('admin_audit_log') . ' (' .
            'user_id INTEGER, username TEXT, action_id INTEGER, object_id INTEGER, ' .
            'object_type_id INTEGER, action_details TEXT DEFAULT "", added_date TEXT)'
        );
    }

    private function seedData(PDO $db): void
    {
        $db->exec(
            'INSERT INTO ' . TestHelper::table('content_sources_groups') .
            ' (content_source_group_id, title, dir, description, status_id, external_id, ' .
            'custom1, custom2, custom3, custom4, custom5, sort_id, added_date) VALUES ' .
            "(1, 'Studios', 'studios', 'Known studios', 1, 'studios-ext', 'featured', '', '', '', '', 2, '2026-01-01 10:00:00'), " .
            "(2, 'Empty Group', 'empty-group', '', 0, 'empty-ext', '', '', '', '', '', 4, '2026-01-02 10:00:00')"
        );

        $customColumns = [];
        $customValuesAlpha = [];
        $customValuesBeta = [];
        for ($i = 1; $i <= 10; $i++) {
            $customColumns[] = "custom{$i}";
            $customValuesAlpha[] = $i === 1 ? "'curated'" : "''";
            $customValuesBeta[] = "''";
        }
        for ($i = 1; $i <= 10; $i++) {
            $customColumns[] = "custom_file{$i}";
            $customValuesAlpha[] = $i === 1 ? "'c1_old.txt'" : "''";
            $customValuesBeta[] = "''";
        }

        $baseColumns = [
            'content_source_id',
            'content_source_group_id',
            'title',
            'dir',
            'description',
            'synonyms',
            'status_id',
            'screenshot1',
            'screenshot2',
            'url',
            'rating',
            'rating_amount',
            'sort_id',
            'added_date',
            'last_content_date',
            'cs_viewed',
            'comments_count',
            'subscribers_count',
            'rank',
            'last_rank',
        ];
        $alphaValues = [
            '1',
            '1',
            "'Alpha Source'",
            "'alpha-source'",
            "'Alpha desc'",
            "'Alpha Alt'",
            '1',
            "'s1_alpha.png'",
            "''",
            "'https://alpha.example.test/'",
            '40',
            '10',
            '3',
            "'2026-01-03 10:00:00'",
            "''",
            '15',
            '1',
            '1',
            '0',
            '0',
        ];
        $betaValues = [
            '2',
            '0',
            "'Beta Source'",
            "'beta-source'",
            "''",
            "''",
            '0',
            "''",
            "''",
            "''",
            '0',
            '1',
            '1',
            "'2026-01-04 10:00:00'",
            "''",
            '0',
            '0',
            '0',
            '0',
            '0',
        ];

        $db->exec(
            'INSERT INTO ' . TestHelper::table('content_sources') .
            ' (' . implode(', ', array_merge($baseColumns, $customColumns)) . ') VALUES ' .
            '(' . implode(', ', array_merge($alphaValues, $customValuesAlpha)) . '), ' .
            '(' . implode(', ', array_merge($betaValues, $customValuesBeta)) . ')'
        );

        $db->exec(
            'INSERT INTO ' . TestHelper::table('tags') .
            " (tag_id, tag, tag_dir, status_id, added_date, total_content_sources) VALUES " .
            "(1, 'Existing Tag', 'existing-tag', 1, '2026-01-01 10:00:00', 1)"
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('categories') .
            " (category_id, title, dir, status_id, added_date, total_content_sources) VALUES " .
            "(1, 'Existing Category', 'existing-category', 1, '2026-01-01 10:00:00', 1)"
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('tags_content_sources') .
            ' (tag_id, content_source_id) VALUES (1, 1)'
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('categories_content_sources') .
            ' (category_id, content_source_id) VALUES (1, 1)'
        );
        $db->exec('INSERT INTO ' . TestHelper::table('videos') . ' (video_id, content_source_id) VALUES (1, 1)');
        $db->exec('INSERT INTO ' . TestHelper::table('albums') . ' (album_id, content_source_id) VALUES (1, 1)');
        $db->exec(
            'INSERT INTO ' . TestHelper::table('comments') .
            ' (comment_id, object_type_id, object_id, user_id, is_approved) VALUES (1, 3, 1, 5, 1)'
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('users_subscriptions') .
            ' (user_id, subscribed_object_id, subscribed_object_type_id) VALUES (5, 1, 3)'
        );
        $db->exec('INSERT INTO ' . TestHelper::table('users_events') . ' (event_id, content_source_id) VALUES (1, 1)');
        $db->exec('INSERT INTO ' . TestHelper::table('videos_feeds_import') . ' (videos_content_source_id) VALUES (1)');
        $db->exec(
            'INSERT INTO ' . TestHelper::table('users') .
            ' (user_id, comments_cs_count, comments_total_count) VALUES (5, 1, 1)'
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('options') .
            " (variable, value) VALUES ('CS_SCREENSHOT_OPTION', '1')"
        );

        mkdir($this->sourceFilesPath . '/1', 0777, true);
        file_put_contents($this->sourceFilesPath . '/1/s1_alpha.png', 'old screenshot');
        file_put_contents($this->sourceFilesPath . '/1/c1_old.txt', 'old custom file');
    }

    private function createSourceCommand(PDO $db): ContentSourceCommand
    {
        return new class ($this->config, $db) extends ContentSourceCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:content-source');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
    }

    private function createGroupCommand(PDO $db): ContentSourceGroupCommand
    {
        return new class ($this->config, $db) extends ContentSourceGroupCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:content-source-group');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
    }
}
