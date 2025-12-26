<?php

declare(strict_types=1);

namespace KVS\CLI\Benchmark;

use PDO;

/**
 * Database benchmark using real KVS queries
 *
 * Tests actual queries that KVS executes in production including:
 * - Common page queries (video listing, categories)
 * - Heavy cron queries (stats aggregation, category summaries)
 * - Write operations (stats updates, view counters)
 *
 * All writes use temporary tables or rollback - zero pollution.
 */
class DatabaseBench
{
    private PDO $db;
    private string $tablePrefix;
    private int $iterations;

    public function __construct(PDO $db, string $tablePrefix, int $iterations = 10)
    {
        $this->db = $db;
        $this->tablePrefix = $tablePrefix;
        $this->iterations = $iterations;
    }

    /**
     * Run all database benchmarks
     */
    public function run(BenchmarkResult $result): void
    {
        // Basic queries (page loads)
        $this->benchVideoListing($result);
        $this->benchVideoCount($result);
        $this->benchCategoryListing($result);
        $this->benchSearch($result);
        $this->benchUserLookup($result);

        // Heavy queries (cron-style)
        $this->benchCategorySummary($result);
        $this->benchStatsAggregation($result);
        $this->benchComplexJoin($result);

        // Write operations
        $this->benchInsert($result);
        $this->benchUpdate($result);
    }

    private function benchVideoListing(BenchmarkResult $result): void
    {
        $videosTable = $this->tablePrefix . 'videos';
        $catsTable = $this->tablePrefix . 'categories';
        $videoCatsTable = $this->tablePrefix . 'videos_categories';

        // Check if tables exist
        if (!$this->tableExists($videosTable)) {
            return;
        }

        // Real KVS video listing query with category join
        $query = "
            SELECT v.video_id, v.title, v.added_date, v.video_viewed, v.rating,
                   v.duration, v.status_id
            FROM {$videosTable} v
            WHERE v.status_id = 1
            ORDER BY v.added_date DESC
            LIMIT 20
        ";

        $timings = [];
        for ($i = 0; $i < $this->iterations; $i++) {
            $start = microtime(true);
            $stmt = $this->db->query($query);
            if ($stmt !== false) {
                $stmt->fetchAll();
            }
            $timings[] = (microtime(true) - $start) * 1000;
        }

        $this->recordDbResult($result, 'video_listing', 'Video Listing (20 items)', $timings);
    }

    private function benchVideoCount(BenchmarkResult $result): void
    {
        $videosTable = $this->tablePrefix . 'videos';

        if (!$this->tableExists($videosTable)) {
            return;
        }

        $query = "SELECT COUNT(*) FROM {$videosTable} WHERE status_id = 1";

        $timings = [];
        for ($i = 0; $i < $this->iterations; $i++) {
            $start = microtime(true);
            $stmt = $this->db->query($query);
            if ($stmt !== false) {
                $stmt->fetchColumn();
            }
            $timings[] = (microtime(true) - $start) * 1000;
        }

        $this->recordDbResult($result, 'video_count', 'Video Count Query', $timings);
    }

    private function benchCategoryListing(BenchmarkResult $result): void
    {
        $catsTable = $this->tablePrefix . 'categories';
        $videoCatsTable = $this->tablePrefix . 'videos_categories';

        if (!$this->tableExists($catsTable)) {
            return;
        }

        // Category list with video counts (common KVS query)
        $query = "
            SELECT c.category_id, c.title, c.dir,
                   (SELECT COUNT(*) FROM {$videoCatsTable} vc WHERE vc.category_id = c.category_id) as video_count
            FROM {$catsTable} c
            WHERE c.status_id = 1
            ORDER BY c.title ASC
        ";

        $timings = [];
        for ($i = 0; $i < $this->iterations; $i++) {
            $start = microtime(true);
            try {
                $stmt = $this->db->query($query);
                if ($stmt !== false) {
                    $stmt->fetchAll();
                }
            } catch (\PDOException $e) {
                // videos_categories might not exist
                break;
            }
            $timings[] = (microtime(true) - $start) * 1000;
        }

        if ($timings !== []) {
            $this->recordDbResult($result, 'category_listing', 'Category Listing + Counts', $timings);
        }
    }

    private function benchSearch(BenchmarkResult $result): void
    {
        $videosTable = $this->tablePrefix . 'videos';

        if (!$this->tableExists($videosTable)) {
            return;
        }

        // LIKE search (basic search)
        $query = "
            SELECT video_id, title, added_date
            FROM {$videosTable}
            WHERE status_id = 1
              AND title LIKE '%test%'
            ORDER BY added_date DESC
            LIMIT 20
        ";

        $timings = [];
        for ($i = 0; $i < $this->iterations; $i++) {
            $start = microtime(true);
            $stmt = $this->db->query($query);
            if ($stmt !== false) {
                $stmt->fetchAll();
            }
            $timings[] = (microtime(true) - $start) * 1000;
        }

        $this->recordDbResult($result, 'search', 'LIKE Search Query', $timings);
    }

    private function benchUserLookup(BenchmarkResult $result): void
    {
        $usersTable = $this->tablePrefix . 'users';

        if (!$this->tableExists($usersTable)) {
            return;
        }

        // Prepared statement for user lookup (common auth pattern)
        $query = "SELECT user_id, username, status_id FROM {$usersTable} WHERE user_id = ?";
        $stmt = $this->db->prepare($query);

        $timings = [];
        for ($i = 0; $i < $this->iterations; $i++) {
            $start = microtime(true);
            $stmt->execute([($i % 10) + 1]);
            $stmt->fetch();
            $timings[] = (microtime(true) - $start) * 1000;
        }

        $this->recordDbResult($result, 'user_lookup', 'User Lookup (prepared)', $timings);
    }

    private function benchInsert(BenchmarkResult $result): void
    {
        // Use temporary table - automatically cleaned up
        $tempTable = $this->tablePrefix . 'bench_temp_' . uniqid();

        try {
            $this->db->exec("
                CREATE TEMPORARY TABLE {$tempTable} (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    data VARCHAR(255),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");

            $stmt = $this->db->prepare("INSERT INTO {$tempTable} (data) VALUES (?)");

            $timings = [];
            for ($i = 0; $i < $this->iterations * 10; $i++) {
                $start = microtime(true);
                $stmt->execute(['benchmark_data_' . $i]);
                $timings[] = (microtime(true) - $start) * 1000;
            }

            $this->recordDbResult($result, 'insert', 'INSERT (temp table)', $timings);

            $this->db->exec("DROP TEMPORARY TABLE IF EXISTS {$tempTable}");
        } catch (\PDOException $e) {
            // Temp tables might not be supported
        }
    }

    /**
     * Benchmark UPDATE operations (simulating view counter updates)
     */
    private function benchUpdate(BenchmarkResult $result): void
    {
        $tempTable = $this->tablePrefix . 'bench_update_' . uniqid();

        try {
            // Create temp table with counter column
            $this->db->exec("
                CREATE TEMPORARY TABLE {$tempTable} (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    counter INT DEFAULT 0,
                    data VARCHAR(255)
                )
            ");

            // Insert some rows to update
            for ($i = 0; $i < 100; $i++) {
                $this->db->exec("INSERT INTO {$tempTable} (data) VALUES ('row_{$i}')");
            }

            $stmt = $this->db->prepare("UPDATE {$tempTable} SET counter = counter + 1 WHERE id = ?");

            $timings = [];
            for ($i = 0; $i < $this->iterations * 10; $i++) {
                $start = microtime(true);
                $stmt->execute([($i % 100) + 1]);
                $timings[] = (microtime(true) - $start) * 1000;
            }

            $this->recordDbResult($result, 'update', 'UPDATE Counter', $timings);

            $this->db->exec("DROP TEMPORARY TABLE IF EXISTS {$tempTable}");
        } catch (\PDOException $e) {
            // Ignore
        }
    }

    /**
     * Heavy query: Category summary with video counts and stats
     * Like KVS cron_optimize.php does for category recalculation
     */
    private function benchCategorySummary(BenchmarkResult $result): void
    {
        $catsTable = $this->tablePrefix . 'categories';
        $videosTable = $this->tablePrefix . 'videos';
        $videoCatsTable = $this->tablePrefix . 'videos_categories';

        if (!$this->tableExists($catsTable) || !$this->tableExists($videoCatsTable)) {
            return;
        }

        // Real KVS-style category summary query (from cron_optimize.php pattern)
        $query = "
            SELECT
                c.category_id,
                c.title,
                COUNT(DISTINCT vc.video_id) as total_videos,
                COALESCE(SUM(v.video_viewed), 0) as total_views,
                COALESCE(AVG(v.rating), 0) as avg_rating
            FROM {$catsTable} c
            LEFT JOIN {$videoCatsTable} vc ON c.category_id = vc.category_id
            LEFT JOIN {$videosTable} v ON vc.video_id = v.video_id AND v.status_id = 1
            WHERE c.status_id = 1
            GROUP BY c.category_id, c.title
            ORDER BY total_videos DESC
        ";

        $timings = [];
        for ($i = 0; $i < $this->iterations; $i++) {
            $start = microtime(true);
            try {
                $stmt = $this->db->query($query);
                if ($stmt !== false) {
                    $stmt->fetchAll();
                }
            } catch (\PDOException $e) {
                break;
            }
            $timings[] = (microtime(true) - $start) * 1000;
        }

        if ($timings !== []) {
            $this->recordDbResult($result, 'category_summary', 'Category Summary (JOIN)', $timings);
        }
    }

    /**
     * Heavy query: Stats aggregation
     * Like KVS cron_stats.php does for daily statistics
     */
    private function benchStatsAggregation(BenchmarkResult $result): void
    {
        $videosTable = $this->tablePrefix . 'videos';

        if (!$this->tableExists($videosTable)) {
            return;
        }

        // Aggregate stats query (simulating daily stats calculation)
        $query = "
            SELECT
                DATE(added_date) as day,
                COUNT(*) as videos_count,
                SUM(video_viewed) as total_views,
                AVG(duration) as avg_duration,
                AVG(rating) as avg_rating,
                MAX(video_viewed) as max_views
            FROM {$videosTable}
            WHERE status_id = 1
            GROUP BY DATE(added_date)
            ORDER BY day DESC
            LIMIT 30
        ";

        $timings = [];
        for ($i = 0; $i < $this->iterations; $i++) {
            $start = microtime(true);
            try {
                $stmt = $this->db->query($query);
                if ($stmt !== false) {
                    $stmt->fetchAll();
                }
            } catch (\PDOException $e) {
                break;
            }
            $timings[] = (microtime(true) - $start) * 1000;
        }

        if ($timings !== []) {
            $this->recordDbResult($result, 'stats_aggregation', 'Stats Aggregation (30 days)', $timings);
        }
    }

    /**
     * Heavy query: Complex multi-table JOIN
     * Simulating video detail page with all relations
     */
    private function benchComplexJoin(BenchmarkResult $result): void
    {
        $videosTable = $this->tablePrefix . 'videos';
        $catsTable = $this->tablePrefix . 'categories';
        $videoCatsTable = $this->tablePrefix . 'videos_categories';
        $tagsTable = $this->tablePrefix . 'tags';
        $videoTagsTable = $this->tablePrefix . 'videos_tags';

        if (!$this->tableExists($videosTable)) {
            return;
        }

        // Complex query with multiple JOINs (video detail page)
        $query = "
            SELECT
                v.video_id, v.title, v.description, v.duration, v.video_viewed, v.rating,
                GROUP_CONCAT(DISTINCT c.title SEPARATOR ', ') as categories,
                GROUP_CONCAT(DISTINCT t.title SEPARATOR ', ') as tags
            FROM {$videosTable} v
            LEFT JOIN {$videoCatsTable} vc ON v.video_id = vc.video_id
            LEFT JOIN {$catsTable} c ON vc.category_id = c.category_id
            LEFT JOIN {$videoTagsTable} vt ON v.video_id = vt.video_id
            LEFT JOIN {$tagsTable} t ON vt.tag_id = t.tag_id
            WHERE v.status_id = 1
            GROUP BY v.video_id, v.title, v.description, v.duration, v.video_viewed, v.rating
            ORDER BY v.added_date DESC
            LIMIT 10
        ";

        $timings = [];
        for ($i = 0; $i < $this->iterations; $i++) {
            $start = microtime(true);
            try {
                $stmt = $this->db->query($query);
                if ($stmt !== false) {
                    $stmt->fetchAll();
                }
            } catch (\PDOException $e) {
                // Some tables might not exist
                break;
            }
            $timings[] = (microtime(true) - $start) * 1000;
        }

        if ($timings !== []) {
            $this->recordDbResult($result, 'complex_join', 'Complex JOIN (5 tables)', $timings);
        }
    }

    /**
     * Record database result with statistics
     *
     * @param array<int, float> $timings Array of times in milliseconds
     */
    private function recordDbResult(BenchmarkResult $result, string $key, string $name, array $timings): void
    {
        if ($timings === []) {
            return;
        }

        $count = count($timings);
        $avgMs = array_sum($timings) / $count;
        $queriesSec = $avgMs > 0 ? 1000 / $avgMs : 0;

        $result->recordDb($key, $name, $avgMs, $queriesSec, $count);
    }

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->db->query("SELECT 1 FROM {$table} LIMIT 1");
            return $stmt !== false;
        } catch (\PDOException $e) {
            return false;
        }
    }
}
