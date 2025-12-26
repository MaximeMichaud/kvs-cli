<?php

declare(strict_types=1);

namespace KVS\CLI\Benchmark;

use PDO;

/**
 * Database benchmark using real KVS queries
 *
 * Tests actual queries that KVS executes in production.
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
        // Test 1: Video listing query (most common)
        $this->benchVideoListing($result);

        // Test 2: Video count with filters
        $this->benchVideoCount($result);

        // Test 3: Category listing with video counts
        $this->benchCategoryListing($result);

        // Test 4: Search query
        $this->benchSearch($result);

        // Test 5: User lookup
        $this->benchUserLookup($result);

        // Test 6: Insert performance (temp table)
        $this->benchInsert($result);
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
