<?php

declare(strict_types=1);

namespace KVS\CLI\Tests;

use KVS\CLI\Benchmark\BenchmarkResult;
use KVS\CLI\Benchmark\DatabaseBench;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseBench::class)]
class DatabaseBenchTest extends TestCase
{
    public function testDatabaseBenchUsesKvsCategoryVideoJoinTable(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->createKvsSchema($db);

        $result = new BenchmarkResult();
        $bench = new DatabaseBench($db, 'ktvs_', 1);

        $bench->run($result);

        $dbResults = $result->getDbResults();
        $this->assertArrayHasKey('category_listing', $dbResults);
        $this->assertArrayHasKey('category_summary', $dbResults);
    }

    private function createKvsSchema(\PDO $db): void
    {
        $db->exec('CREATE TABLE ktvs_videos (
            video_id INTEGER PRIMARY KEY,
            title TEXT,
            description TEXT,
            added_date TEXT,
            video_viewed INTEGER,
            rating INTEGER,
            duration INTEGER,
            status_id INTEGER
        )');
        $db->exec('CREATE TABLE ktvs_categories (
            category_id INTEGER PRIMARY KEY,
            title TEXT,
            dir TEXT,
            status_id INTEGER
        )');
        $db->exec('CREATE TABLE ktvs_categories_videos (
            category_id INTEGER,
            video_id INTEGER
        )');
        $db->exec('CREATE TABLE ktvs_users (
            user_id INTEGER PRIMARY KEY,
            username TEXT,
            status_id INTEGER
        )');

        $db->exec("INSERT INTO ktvs_videos
            (video_id, title, description, added_date, video_viewed, rating, duration, status_id)
            VALUES (1, 'Test video', 'Description', '2026-05-14 00:00:00', 10, 5, 60, 1)");
        $db->exec("INSERT INTO ktvs_categories (category_id, title, dir, status_id)
            VALUES (1, 'Category', 'category', 1)");
        $db->exec('INSERT INTO ktvs_categories_videos (category_id, video_id) VALUES (1, 1)');
        $db->exec("INSERT INTO ktvs_users (user_id, username, status_id) VALUES (1, 'user', 1)");
    }
}
